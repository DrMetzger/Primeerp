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
    private const MODELO = 'gemini-3-flash-preview';
    // Preview tem MENOS capacidade reservada que modelo estável -- em hora
    // de pico devolve 503 "alta demanda" com muito mais frequência. Se isso
    // acontecer mesmo depois de tentar de novo, cai pra esse aqui (GA,
    // capacidade normal) em vez de simplesmente falhar na tela.
    private const MODELO_RESERVA = 'gemini-2.5-flash';
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

    public static function configurada(): bool
    {
        return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
    }

    public static function configuradaBusca(): bool
    {
        return defined('GEMINI_API_KEY_BUSCA') && GEMINI_API_KEY_BUSCA !== '';
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
     * bytes crus do arquivo], enviado como "inline_data" (base64) junto do
     * prompt (ver cliente.php, aba Implantação, que monta essa lista a
     * partir da tabela documentos). Retorna sempre um array ['ok' => bool,
     * 'texto' => ?string, 'erro' => ?string] -- diferente da versão
     * anterior (que só devolvia null em qualquer falha), pra dar pra
     * mostrar NA TELA o motivo real quando o Google recusar a chamada
     * (conta sem cota liberada, chave errada, modelo indisponível, etc.),
     * sem precisar ir atrás de log de servidor pra descobrir por quê.
     */
    public static function gerar(string $prompt, array $ferramentas = [], ?string $chave = null, array $arquivos = []): array
    {
        $chave ??= defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        if ($chave === '') {
            return ['ok' => false, 'texto' => null, 'erro' => 'Chave do Gemini não configurada.'];
        }

        $partes = [];
        foreach ($arquivos as $arquivo) {
            $partes[] = ['inline_data' => ['mime_type' => $arquivo['mime'], 'data' => base64_encode($arquivo['dados'])]];
        }
        $partes[] = ['text' => $prompt];

        $corpo = ['contents' => [['parts' => $partes]]];
        if ($ferramentas) {
            $corpo['tools'] = $ferramentas;
        }

        // Tenta o modelo preview algumas vezes (503 "alta demanda" costuma
        // ser passageiro, ver comentário na const) e, se nem assim
        // conseguir, cai pro modelo de reserva antes de desistir de vez --
        // sem isso, um pico de demanda no Google derrubava a geração toda.
        foreach ([self::MODELO, self::MODELO_RESERVA] as $indiceModelo => $modelo) {
            $ehUltimoModelo = $indiceModelo === 1;
            $tentativas = $ehUltimoModelo ? 1 : self::TENTATIVAS_POR_MODELO;

            for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
                $resultado = self::chamarModelo($modelo, $chave, $corpo);

                if ($resultado['ok'] || !$resultado['tentar_de_novo']) {
                    return ['ok' => $resultado['ok'], 'texto' => $resultado['texto'], 'erro' => $resultado['erro']];
                }

                $ultimaTentativaDesseModelo = $tentativa === $tentativas;
                if (!$ultimaTentativaDesseModelo) {
                    sleep(self::ESPERA_ENTRE_TENTATIVAS_SEGUNDOS);
                }
            }
        }

        return ['ok' => false, 'texto' => null, 'erro' => $resultado['erro']];
    }

    /**
     * Uma chamada de verdade à Graph API do Gemini. 'tentar_de_novo' só
     * fica true pra erro claramente PASSAGEIRO (503 alta demanda, 429 cota
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

        return ['ok' => true, 'texto' => $texto, 'erro' => null, 'tentar_de_novo' => false];
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
            return ['ok' => false, 'texto' => null, 'erro' => 'Busca por IA não configurada (ADMINPRIME_GEMINI_API_KEY_BUSCA) -- precisa de uma chave/projeto separado com faturamento ativado pra liberar a ferramenta de busca do Google.'];
        }

        $prompt = 'Pesquise informações públicas sobre a empresa brasileira "' . $nomeEmpresa . '"'
            . ($cnpj ? " (CNPJ {$cnpj})" : ' (não tenho o CNPJ, use o nome e o bom senso pra achar a empresa certa -- se houver mais de uma empresa com nome parecido, diga isso explicitamente em vez de chutar uma)')
            . '. Responda em português, em tópicos curtos: ramo de atividade, porte, situação cadastral (ativa/inapta/baixada), cidade/UF da sede, e ano de fundação se encontrar. '
            . 'Se não achar informação confiável, diga isso claramente em vez de inventar.';

        return self::gerar($prompt, [['google_search' => (object) []]], GEMINI_API_KEY_BUSCA);
    }
}
