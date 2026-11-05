<?php

namespace AdminPrime;

/**
 * Sugestões de melhoria por setor (aba Setores) geradas com IA -- fala
 * direto com a API do Google Gemini (plano gratuito, pedido do Kleber pra
 * não gerar custo nenhum), mesmo espírito do WhatsAppApi: enquanto
 * GEMINI_API_KEY não estiver configurada, só avisa em vez de quebrar a tela.
 *
 * DUAS CHAVES DE PROPÓSITO -- não é redundância, é isolamento de custo:
 * GEMINI_API_KEY (análise de chamados/KPIs já cadastrados) nunca chama
 * ferramenta de busca, então continua sempre dentro do plano gratuito.
 * GEMINI_API_KEY_BUSCA (pesquisarPerfilEmpresa(), busca na internet) usa um
 * projeto Google Cloud SEPARADO, porque ativar faturamento pra liberar a
 * cota da ferramenta google_search remove o plano gratuito do projeto
 * INTEIRO (toda chamada passa a ser cobrada) -- se fosse a mesma chave, a
 * análise interna que já funciona de graça passaria a custar também.
 */
class AiSugestoes
{
    // ALIAS ("-latest"), não versão fixa com número -- o Google vai
    // aposentando modelo antigo com o tempo (foi o que aconteceu com o
    // "gemini-2.5-flash" fixo que usávamos antes: passou a devolver HTTP 404
    // "no longer available to new users" pra chave/projeto mais novo, mesmo
    // ele ainda existindo de fato pra chave antiga -- a aposentadoria é por
    // PROJETO, não global, o que faz um número fixo virar bomba-relógio).
    // "-latest" sempre aponta pro modelo estável mais atual daquela
    // categoria, sem precisar trocar código de novo quando o Google lançar a
    // próxima geração.
    //
    // "flash-lite" como PRINCIPAL (não o "flash" cheio) -- testado na prática
    // com o PDF real que travou em produção: "flash" cheio usa "thinking"
    // (raciocínio estendido) por padrão nesses modelos mais novos e levou
    // 12-38s por chamada (às vezes 503 de tanta demanda, sendo um modelo mais
    // "na moda"), o que é tempo suficiente pra bater no limite de timeout do
    // proxy da hospedagem e devolver resposta cortada pro navegador antes do
    // PHP terminar. "flash-lite" respondeu em 4-5s consistentemente, LENDO
    // o PDF de verdade (citou item/página específicos do documento, não
    // genérico) -- mais rápido E mais confiável pra essa tarefa. "flash"
    // cheio fica como reserva, pra quando o lite falhar de verdade.
    private const MODELO = 'gemini-flash-lite-latest';
    private const MODELO_RESERVA = 'gemini-flash-latest';
    // Pouco tempo de espera/tentativa de propósito -- isso tudo roda dentro
    // de uma requisição HTTP síncrona (cliente.php aguardando), e hospedagem
    // compartilhada normalmente mata o PHP em 30-60s. Errar pra menos aqui
    // (retry mais curto, mas ainda cobre o caso de 503 passageiro) é melhor
    // que travar a página no meio e devolver resposta cortada (ver
    // set_time_limit() em cliente.php, que dá uma folga adicional, mas não
    // resolve timeout de proxy/servidor, só do PHP em si).
    private const TENTATIVAS_POR_MODELO = 2;
    private const ESPERA_ENTRE_TENTATIVAS_SEGUNDOS = 2;
    private const TIMEOUT_POR_CHAMADA_SEGUNDOS = 55;

    // Upload de cada anexo pela Files API do Gemini (ver enviarArquivo())
    // em vez de embutir os bytes em base64 direto no corpo da requisição
    // (inline_data) -- o corpo de uma requisição inline tem um teto de
    // ~20MB no total, contando TODOS os anexos juntos, o que limitava a uns
    // 8 documentos na prática. Arquivo enviado pela Files API não conta
    // pra esse teto (o Gemini aceita até 3.000 arquivos por requisição,
    // 50MB por PDF) -- o limite aqui embaixo é só uma margem de segurança
    // nossa pra não fazer dezenas de uploads sequenciais numa requisição
    // síncrona de página (cada upload leva 1-3s), não um limite do Gemini.
    public const MAX_ARQUIVOS_POR_CHAMADA = 15;
    private const TIMEOUT_UPLOAD_ARQUIVO_SEGUNDOS = 12;
    // Orçamento de tempo total pra fase de upload (soma de todos os
    // arquivos) -- se a rede estiver ruim e os uploads começarem a
    // demorar, para de tentar mandar mais arquivo e segue com os que já
    // subiram, em vez de estourar o tempo de execução do PHP.
    private const TEMPO_MAX_UPLOADS_SEGUNDOS = 60;

    /**
     * As duas chaves preferem o valor salvo pela tela configuracoes.php
     * (tabela `configuracoes`) e só caem pra constante de config/config.php
     * se nada tiver sido salvo ainda pela tela -- mesmo esquema do
     * WhatsAppApi/Mailer.
     */
    private static function chaveAnalise(): string
    {
        return Configuracoes::get(Database::connection(), 'gemini_api_key', defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '') ?? '';
    }

    private static function chaveBusca(): string
    {
        return Configuracoes::get(Database::connection(), 'gemini_api_key_busca', defined('GEMINI_API_KEY_BUSCA') ? GEMINI_API_KEY_BUSCA : '') ?? '';
    }

    public static function configurada(): bool
    {
        return self::chaveAnalise() !== '';
    }

    public static function configuradaBusca(): bool
    {
        return self::chaveBusca() !== '';
    }

    /**
     * Chave usada pela busca na internet dos chamados (IaChamados): a de
     * busca se tiver uma separada, senão a própria chave de análise (caso
     * de um projeto só, já com faturamento ativado).
     */
    public static function chaveParaBuscaOuAnalise(): string
    {
        return self::chaveBusca() !== '' ? self::chaveBusca() : self::chaveAnalise();
    }

    /**
     * $prompt já vem pronto (contexto do setor montado por quem chamou --
     * cliente.php sabe os dados, essa classe só sabe falar com a API).
     * $ferramentas é a lista de "tools" do Gemini (ex.: [['google_search' =>
     * (object) []]] pra deixar o modelo pesquisar na internet antes de
     * responder -- ver pesquisarPerfilEmpresa() abaixo); vazio por padrão
     * (só gera texto a partir do próprio prompt, sem buscar nada). $chave é
     * qual GEMINI_API_KEY* usar -- sempre a de análise interna por padrão;
     * pesquisarPerfilEmpresa() é o único caller que passa a de busca.
     * $arquivos deixa o Gemini LER o conteúdo real de anexos (PDF/imagem/
     * texto), não só metadado -- cada item é ['mime' => string, 'dados' =>
     * bytes crus do arquivo, 'nome' => nome original (opcional, só pra
     * mensagem de erro)]. Cada um é enviado primeiro pra Files API do
     * Gemini (ver enviarArquivo()) e referenciado por URI no prompt --
     * ver cliente.php, aba Implantação, que monta essa lista a partir da
     * tabela documentos. Retorna sempre um array ['ok' => bool, 'texto' =>
     * ?string, 'erro' => ?string, 'arquivos_com_falha' => string[]] --
     * arquivos_com_falha lista os que não deu pra enviar (rede, formato
     * recusado pelo Gemini etc.), pra quem chamou avisar sem abortar a
     * geração inteira por causa de UM anexo com problema.
     */
    public static function gerar(string $prompt, array $ferramentas = [], ?string $chave = null, array $arquivos = [], array $configGeracao = []): array
    {
        $chave ??= self::chaveAnalise();
        if ($chave === '') {
            return ['ok' => false, 'texto' => null, 'erro' => 'Chave do Gemini não configurada.', 'arquivos_com_falha' => []];
        }

        [$partesArquivo, $arquivosComFalha] = self::enviarArquivos(array_slice($arquivos, 0, self::MAX_ARQUIVOS_POR_CHAMADA), $chave);

        $partes = $partesArquivo;
        $partes[] = ['text' => $prompt];

        $corpo = ['contents' => [['parts' => $partes]]];
        if ($ferramentas) {
            $corpo['tools'] = $ferramentas;
        }
        // generationConfig opcional (ex.: maxOutputTokens/temperature/
        // responseMimeType) -- usado por IaChamados pra limitar o tamanho
        // (e o custo) da resposta. Vazio = padrão do Google, como sempre foi.
        if ($configGeracao) {
            $corpo['generationConfig'] = $configGeracao;
        }

        // Tenta o modelo estável algumas vezes (503 "alta demanda" costuma
        // ser passageiro, ver comentário na const) e, se nem assim
        // conseguir, cai pro modelo de reserva antes de desistir de vez --
        // sem isso, um pico de demanda no Google derrubava a geração toda.
        // IMPORTANTE: cair pro modelo de reserva precisa acontecer pra
        // QUALQUER falha do modelo principal, não só pra erro "passageiro"
        // (tentar_de_novo) -- um erro definitivo (ex.: HTTP 404 porque esse
        // modelo específico saiu do ar pra essa chave, como já aconteceu)
        // é justamente o caso em que trocar de MODELO ajuda, ao contrário de
        // insistir no mesmo. Só não vale repetir o MESMO modelo de novo
        // nesse caso (por isso o "break", não um retry) -- só o Google sabe
        // se um 429/503 vale a pena tentar de novo com o mesmo modelo.
        foreach ([self::MODELO, self::MODELO_RESERVA] as $indiceModelo => $modelo) {
            $ehUltimoModelo = $indiceModelo === 1;
            $tentativas = $ehUltimoModelo ? 1 : self::TENTATIVAS_POR_MODELO;

            for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
                $resultado = self::chamarModelo($modelo, $chave, $corpo);

                if ($resultado['ok']) {
                    return [
                        'ok' => true,
                        'texto' => $resultado['texto'],
                        'erro' => null,
                        'arquivos_com_falha' => $arquivosComFalha,
                        'uso' => $resultado['uso'],
                        'fontes' => $resultado['fontes'],
                    ];
                }

                if (!$resultado['tentar_de_novo']) {
                    break; // não vale repetir ESSE modelo -- tenta o próximo modelo da lista (se ainda tiver)
                }

                $ultimaTentativaDesseModelo = $tentativa === $tentativas;
                if (!$ultimaTentativaDesseModelo) {
                    sleep(self::ESPERA_ENTRE_TENTATIVAS_SEGUNDOS);
                }
            }
        }

        return ['ok' => false, 'texto' => null, 'erro' => $resultado['erro'], 'arquivos_com_falha' => $arquivosComFalha];
    }

    /**
     * Envia cada anexo pra Files API do Gemini e monta a lista de "parts"
     * (file_data, por URI) pra entrar no prompt -- ver comentário de
     * MAX_ARQUIVOS_POR_CHAMADA sobre por que isso substitui o antigo
     * inline_data em base64. Respeita um orçamento de tempo total
     * (TEMPO_MAX_UPLOADS_SEGUNDOS): se a rede estiver lenta, para de tentar
     * enviar mais arquivo e segue só com os que já subiram, em vez de
     * arriscar estourar o tempo de execução do PHP.
     * Retorna [partes_de_arquivo, nomes_com_falha].
     */
    private static function enviarArquivos(array $arquivos, string $chave): array
    {
        $partesArquivo = [];
        $arquivosComFalha = [];
        $inicio = microtime(true);

        foreach ($arquivos as $arquivo) {
            $nomeArquivo = $arquivo['nome'] ?? 'anexo';

            if ((microtime(true) - $inicio) > self::TEMPO_MAX_UPLOADS_SEGUNDOS) {
                $arquivosComFalha[] = $nomeArquivo . ' (tempo de upload esgotado)';
                continue;
            }

            $upload = self::enviarArquivo($arquivo['mime'], $arquivo['dados'], $chave);
            if ($upload['ok']) {
                $partesArquivo[] = ['file_data' => ['mime_type' => $arquivo['mime'], 'file_uri' => $upload['uri']]];
            } else {
                $arquivosComFalha[] = $nomeArquivo . ' (' . $upload['erro'] . ')';
            }
        }

        return [$partesArquivo, $arquivosComFalha];
    }

    /**
     * Upload resumível de um arquivo pra Files API do Gemini (protocolo
     * oficial do Google: https://ai.google.dev/api/files) -- duas
     * chamadas: a primeira "reserva" o upload e devolve a URL real de
     * envio no cabeçalho X-Goog-Upload-URL, a segunda manda os bytes de
     * verdade pra essa URL. Retorna o file_uri devolvido pelo Gemini (usado
     * depois como file_data na chamada de geração), ou erro.
     */
    private static function enviarArquivo(string $mime, string $bytes, string $chave): array
    {
        $tamanho = strlen($bytes);
        $urlInicio = 'https://generativelanguage.googleapis.com/upload/v1beta/files?key=' . $chave;

        $ch = curl_init($urlInicio);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-Goog-Upload-Protocol: resumable',
                'X-Goog-Upload-Command: start',
                'X-Goog-Upload-Header-Content-Length: ' . $tamanho,
                'X-Goog-Upload-Header-Content-Type: ' . $mime,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['file' => ['display_name' => 'anexo']]),
            CURLOPT_TIMEOUT => self::TIMEOUT_UPLOAD_ARQUIVO_SEGUNDOS,
        ]);
        $respostaInicio = curl_exec($ch);
        $codigoInicio = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tamanhoCabecalho = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $erroInicio = curl_error($ch);
        curl_close($ch);

        if ($respostaInicio === false) {
            return ['ok' => false, 'uri' => null, 'erro' => 'falha de rede ao iniciar upload: ' . $erroInicio];
        }
        if ($codigoInicio >= 300) {
            return ['ok' => false, 'uri' => null, 'erro' => 'Gemini recusou iniciar o upload (HTTP ' . $codigoInicio . ')'];
        }

        $cabecalhosInicio = substr($respostaInicio, 0, $tamanhoCabecalho);
        if (!preg_match('/^x-goog-upload-url:\s*(\S+)/mi', $cabecalhosInicio, $m)) {
            return ['ok' => false, 'uri' => null, 'erro' => 'Gemini não retornou a URL de upload'];
        }
        $urlUpload = trim($m[1]);

        $ch2 = curl_init($urlUpload);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Length: ' . $tamanho,
                'X-Goog-Upload-Offset: 0',
                'X-Goog-Upload-Command: upload, finalize',
            ],
            CURLOPT_POSTFIELDS => $bytes,
            CURLOPT_TIMEOUT => self::TIMEOUT_UPLOAD_ARQUIVO_SEGUNDOS,
        ]);
        $respostaUpload = curl_exec($ch2);
        $codigoUpload = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $erroUpload = curl_error($ch2);
        curl_close($ch2);

        if ($respostaUpload === false) {
            return ['ok' => false, 'uri' => null, 'erro' => 'falha de rede ao enviar arquivo: ' . $erroUpload];
        }
        if ($codigoUpload >= 300) {
            return ['ok' => false, 'uri' => null, 'erro' => 'Gemini recusou o arquivo (HTTP ' . $codigoUpload . ')'];
        }

        $dadosUpload = json_decode($respostaUpload, true);
        $uri = $dadosUpload['file']['uri'] ?? null;
        if (!$uri) {
            return ['ok' => false, 'uri' => null, 'erro' => 'Gemini não retornou o identificador do arquivo enviado'];
        }

        return ['ok' => true, 'uri' => $uri, 'erro' => null];
    }

    /**
     * Uma chamada de verdade à API do Gemini. 'tentar_de_novo' só fica true
     * pra erro claramente PASSAGEIRO (503 alta demanda, 429 cota
     * momentânea) -- erro de chave errada/conteúdo bloqueado etc. não vale
     * a pena repetir, já volta direto pra tela.
     */
    private static function chamarModelo(string $modelo, string $chave, array $corpo): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent?key=' . $chave;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($corpo),
            CURLOPT_TIMEOUT => self::TIMEOUT_POR_CHAMADA_SEGUNDOS,
        ]);

        $resposta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false) {
            error_log('IA: falha de rede chamando o Gemini (' . $modelo . '): ' . $erroCurl);
            return ['ok' => false, 'texto' => null, 'erro' => 'Falha de rede ao chamar o Gemini: ' . $erroCurl, 'tentar_de_novo' => true];
        }

        $dados = json_decode($resposta, true);

        if ($codigoHttp >= 300) {
            $mensagemGoogle = $dados['error']['message'] ?? $resposta;
            error_log('IA: falha na chamada à API do Gemini (' . $modelo . '): HTTP ' . $codigoHttp . ' -- ' . $mensagemGoogle);
            return [
                'ok' => false,
                'texto' => null,
                'erro' => "Gemini retornou HTTP {$codigoHttp}: {$mensagemGoogle}",
                'tentar_de_novo' => in_array($codigoHttp, [429, 500, 503], true),
            ];
        }

        // Concatena o texto de TODAS as partes (não só a primeira) -- com
        // ferramenta de busca ligada o Gemini às vezes devolve mais de uma
        // parte (ex.: uma de raciocínio + uma de resposta final).
        $partesResposta = $dados['candidates'][0]['content']['parts'] ?? [];
        $texto = implode('', array_filter(array_column($partesResposta, 'text')));

        if ($texto === '') {
            // Resposta 200 mas sem texto -- normalmente bloqueio de safety
            // (finishReason=SAFETY) ou resposta vazia. Mostra o que o
            // Google mandou pra dar pra entender o motivo. Não vale repetir
            // (não é erro passageiro, o modelo decidiu não responder).
            $motivo = $dados['candidates'][0]['finishReason'] ?? 'desconhecido';
            return ['ok' => false, 'texto' => null, 'erro' => "Gemini respondeu sem texto (motivo: {$motivo}).", 'tentar_de_novo' => false];
        }

        // Tokens gastos (pra controle de custo em IaChamados) e as páginas
        // usadas quando a busca do Google estava ligada (groundingMetadata).
        $uso = [
            'entrada' => (int) ($dados['usageMetadata']['promptTokenCount'] ?? 0),
            'saida' => (int) ($dados['usageMetadata']['candidatesTokenCount'] ?? 0)
                + (int) ($dados['usageMetadata']['thoughtsTokenCount'] ?? 0),
        ];
        $fontes = [];
        foreach ($dados['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [] as $trecho) {
            if (!empty($trecho['web']['uri'])) {
                $fontes[] = ['titulo' => $trecho['web']['title'] ?? $trecho['web']['uri'], 'url' => $trecho['web']['uri']];
            }
        }

        return ['ok' => true, 'texto' => $texto, 'erro' => null, 'tentar_de_novo' => false, 'uso' => $uso, 'fontes' => $fontes];
    }

    /**
     * Perfil PÚBLICO da empresa cliente (ramo, porte, situação cadastral,
     * cidade/UF) via busca do Google dentro do próprio Gemini -- diferente
     * de gerar(), que só analisa dados JÁ CADASTRADOS (chamados/KPIs); essa
     * função deixa o modelo procurar na internet antes de responder. Pedido
     * do Kleber depois de perguntar se a IA "não pode pesquisar sobre o
     * cliente já que tem o CNPJ dela".
     */
    public static function pesquisarPerfilEmpresa(string $nomeEmpresa, ?string $cnpj): array
    {
        if (!self::configuradaBusca()) {
            return ['ok' => false, 'texto' => null, 'erro' => 'Busca por IA não configurada -- preencha a chave em Configurações > IA Gemini (Google) (precisa de uma chave/projeto separado com faturamento ativado pra liberar a ferramenta de busca do Google).'];
        }

        $prompt = 'Pesquise informações públicas sobre a empresa brasileira "' . $nomeEmpresa . '"'
            . ($cnpj ? " (CNPJ {$cnpj})" : ' (não tenho o CNPJ, use o nome e o bom senso pra achar a empresa certa -- se houver mais de uma empresa com nome parecido, diga isso explicitamente em vez de chutar uma)')
            . '. Responda em português, em tópicos curtos: ramo de atividade, porte, situação cadastral (ativa/inapta/baixada), cidade/UF da sede, e ano de fundação se encontrar. '
            . 'Se não achar informação confiável, diga isso claramente em vez de inventar.';

        return self::gerar($prompt, [['google_search' => (object) []]], self::chaveBusca());
    }
}
