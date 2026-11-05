<?php

namespace AdminPrime;

use PDO;

/**
 * Análise automática de chamado novo com IA (Gemini), pensada pra gastar
 * o mínimo possível:
 *
 *  1. Procura chamados PARECIDOS já respondidos pela equipe na nossa própria
 *     base (busca por palavra-chave no MySQL, sem custo de IA nenhum).
 *  2. Se achou algum, UMA chamada ao modelo mais barato (flash-lite), sem
 *     busca na internet, perguntando se aquelas soluções resolvem o chamado
 *     novo e já escrevendo a resposta no nosso jeito de falar.
 *  3. Só se a base não resolver: UMA chamada com a busca do Google ligada,
 *     restrita ao TDN da TOTVS e ao User Function. Essa é a etapa que custa
 *     mais, por isso tem limite diário próprio (ver configuracoes.php).
 *
 * O resultado fica em chamado_ia_analises. Modo "sugestao" (padrão): só
 * aparece pra equipe dentro do chamado, com botão pra usar como resposta.
 * Modo "automatico": já responde o cliente sozinho. "desligado": não faz nada.
 */
class IaChamados
{
    public const MODOS = ['desligado', 'sugestao', 'automatico'];

    private const MAX_CHAMADOS_REFERENCIA = 5;
    private const MAX_RESPOSTAS_POR_REFERENCIA = 3;
    private const MAX_PALAVRAS_CHAVE = 8;
    // Resposta curta = barata e com cara de conversa (não de manual).
    private const MAX_TOKENS_RESPOSTA = 900;

    private const STOPWORDS = [
        'para', 'pelo', 'pela', 'pelos', 'pelas', 'como', 'quando', 'onde', 'porque', 'porém', 'porem', 'mais', 'menos',
        'muito', 'muita', 'esta', 'está', 'estao', 'estão', 'esse', 'essa', 'isso', 'este', 'isto', 'aquele', 'aquela',
        'nosso', 'nossa', 'seus', 'suas', 'dele', 'dela', 'temos', 'tenho', 'fazer', 'feito', 'sendo', 'sido', 'será',
        'sera', 'seria', 'pode', 'podem', 'poderia', 'consigo', 'consegue', 'conseguimos', 'favor', 'obrigado', 'obrigada',
        'bom', 'boa', 'dia', 'tarde', 'noite', 'olá', 'ola', 'prezados', 'prezado', 'pessoal', 'equipe', 'chamado',
        'problema', 'erro', 'sistema', 'protheus', 'totvs', 'quando', 'depois', 'antes', 'ainda', 'também', 'tambem',
        'apenas', 'somente', 'sempre', 'nunca', 'algum', 'alguma', 'alguns', 'algumas', 'todo', 'toda', 'todos', 'todas',
        'outro', 'outra', 'outros', 'outras', 'mesmo', 'mesma', 'entre', 'sobre', 'desde', 'até', 'ate', 'aqui', 'agora',
        'segue', 'anexo', 'print', 'tela', 'verificar', 'verifiquem', 'ajuda', 'preciso', 'precisamos', 'gostaria',
        'tudo', 'nada', 'então', 'entao', 'qual', 'quais', 'quem', 'cada', 'fica', 'ficou', 'estava', 'estamos',
        'vocês', 'voces', 'você', 'voce', 'mas', 'com', 'sem', 'que', 'não', 'nao', 'sim', 'uma', 'umas', 'uns', 'dos', 'das',
        'na', 'no', 'nas', 'nos', 'da', 'do', 'de', 'em', 'um', 'ao', 'aos', 'os', 'as', 'se', 'ou', 'por', 'me', 'te', 'eu',
        'ele', 'ela', 'lhe', 'meu', 'minha', 'sua', 'seu', 'já', 'ja', 'só', 'so', 'vai', 'foi', 'ser', 'ter', 'tem', 'há',
        'nota', 'notas', 'dados', 'campo', 'campos', 'rotina', 'usuario', 'usuário', 'cliente', 'clientes', 'quando',
    ];

    // --- Configuração --------------------------------------------------------

    public static function modo(PDO $pdo): string
    {
        $modo = Configuracoes::get($pdo, 'ia_chamados_modo', 'sugestao');

        return in_array($modo, self::MODOS, true) ? $modo : 'sugestao';
    }

    private static function nomeAutor(PDO $pdo): string
    {
        return Configuracoes::get($pdo, 'ia_chamados_nome_autor', 'Equipe PrimeERP');
    }

    private static function limiteDia(PDO $pdo): int
    {
        return max(0, (int) Configuracoes::get($pdo, 'ia_chamados_limite_dia', '50'));
    }

    private static function limiteBuscaDia(PDO $pdo): int
    {
        return max(0, (int) Configuracoes::get($pdo, 'ia_chamados_limite_busca_dia', '15'));
    }

    // --- Entrada -------------------------------------------------------------

    /**
     * Chamado novo aberto pelo CLIENTE (portal/WhatsApp). Registra a análise
     * como pendente e, se $processarDepois, agenda o processamento pra depois
     * que a página já respondeu pro navegador (ver notificar_depois()) -- o
     * cliente não fica esperando a IA. O que sobrar pendente (ex.: requisição
     * interrompida) o cron_ia_chamados.php pega depois.
     */
    public static function agendar(PDO $pdo, int $chamadoId, bool $processarDepois = true): void
    {
        try {
            if (self::modo($pdo) === 'desligado' || !AiSugestoes::configurada()) {
                return;
            }
            $pdo->prepare("INSERT IGNORE INTO chamado_ia_analises (chamado_id, status) VALUES (?, 'pendente')")->execute([$chamadoId]);
            if ($processarDepois) {
                notificar_depois(fn () => self::processar($pdo, $chamadoId));
            }
        } catch (\Throwable $e) {
            error_log('IA chamados: falha ao agendar chamado #' . $chamadoId . ': ' . $e->getMessage());
        }
    }

    /**
     * Roda a análise de um chamado. $manual = pedido explícito da equipe pelo
     * botão "Analisar com IA" -- refaz mesmo se já tiver análise, e NUNCA
     * responde o cliente sozinho (só gera a sugestão), qualquer que seja o modo.
     * Retorna a linha final de chamado_ia_analises (ou null se nem chegou a rodar).
     */
    public static function processar(PDO $pdo, int $chamadoId, bool $manual = false): ?array
    {
        @set_time_limit(180);
        ignore_user_abort(true);

        try {
            if ($manual) {
                $pdo->prepare("INSERT INTO chamado_ia_analises (chamado_id, status) VALUES (?, 'processando')
                    ON DUPLICATE KEY UPDATE status = 'processando', erro = NULL")->execute([$chamadoId]);
            } else {
                // Trava atômica: só um processo pega cada chamado pendente
                // (evita gastar 2x se o cron e a requisição rodarem juntos).
                $trava = $pdo->prepare("UPDATE chamado_ia_analises SET status = 'processando' WHERE chamado_id = ? AND status = 'pendente'");
                $trava->execute([$chamadoId]);
                if ($trava->rowCount() === 0) {
                    return null;
                }
            }

            $resultado = self::analisar($pdo, $chamadoId, $manual);
        } catch (\Throwable $e) {
            error_log('IA chamados: erro no chamado #' . $chamadoId . ': ' . $e->getMessage());
            $resultado = ['status' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 500)];
        }

        $campos = array_merge([
            'fonte' => null, 'resposta' => null, 'chamados_referencia' => null, 'links' => null, 'erro' => null,
            'tokens_entrada' => 0, 'tokens_saida' => 0, 'usou_busca' => 0, 'resposta_id' => null,
        ], $resultado);

        $pdo->prepare('UPDATE chamado_ia_analises SET status = ?, fonte = ?, resposta = ?, chamados_referencia = ?, links = ?, erro = ?,
                tokens_entrada = ?, tokens_saida = ?, usou_busca = ?, resposta_id = ? WHERE chamado_id = ?')
            ->execute([
                $campos['status'], $campos['fonte'], $campos['resposta'], $campos['chamados_referencia'], $campos['links'], $campos['erro'],
                $campos['tokens_entrada'], $campos['tokens_saida'], $campos['usou_busca'], $campos['resposta_id'], $chamadoId,
            ]);

        $stmt = $pdo->prepare('SELECT * FROM chamado_ia_analises WHERE chamado_id = ?');
        $stmt->execute([$chamadoId]);

        return $stmt->fetch() ?: null;
    }

    /** Pendentes que ficaram pra trás (usado pelo cron). */
    public static function processarPendentes(PDO $pdo, int $limite = 5): int
    {
        // "processando" há muito tempo = processo morreu no meio; volta pra fila.
        $pdo->exec("UPDATE chamado_ia_analises SET status = 'pendente' WHERE status = 'processando' AND atualizado_em < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");

        $ids = $pdo->query("SELECT chamado_id FROM chamado_ia_analises
            WHERE status = 'pendente' AND criado_em < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ORDER BY criado_em ASC LIMIT " . (int) $limite)->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            self::processar($pdo, (int) $id);
        }

        return count($ids);
    }

    /** Análises de vários chamados de uma vez (tela do cliente.php). */
    public static function analisesPorChamado(PDO $pdo, array $chamadoIds): array
    {
        if (!$chamadoIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($chamadoIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM chamado_ia_analises WHERE chamado_id IN ($placeholders)");
        $stmt->execute(array_values($chamadoIds));

        $porChamado = [];
        foreach ($stmt->fetchAll() as $linha) {
            $porChamado[(int) $linha['chamado_id']] = $linha;
        }

        return $porChamado;
    }

    // --- Análise -------------------------------------------------------------

    private static function analisar(PDO $pdo, int $chamadoId, bool $manual): array
    {
        if (!AiSugestoes::configurada()) {
            return ['status' => 'erro', 'erro' => 'Chave do Gemini não configurada (Configurações > IA Gemini).'];
        }

        $stmt = $pdo->prepare('SELECT ch.*, c.nome AS cliente_nome FROM chamados ch LEFT JOIN clientes c ON c.id = ch.cliente_id WHERE ch.id = ?');
        $stmt->execute([$chamadoId]);
        $chamado = $stmt->fetch();
        if (!$chamado) {
            return ['status' => 'erro', 'erro' => 'Chamado não encontrado.'];
        }

        $palavras = self::palavrasChave($chamado['titulo'] . ' ' . $chamado['descricao']);
        if (count($palavras) < 2) {
            return ['status' => 'ignorada', 'erro' => 'Descrição curta demais pra analisar.'];
        }

        if (!$manual && self::analisesHoje($pdo, false) >= self::limiteDia($pdo)) {
            return ['status' => 'ignorada', 'erro' => 'Limite diário de análises atingido.'];
        }

        $primeiroNome = self::primeiroNomeContato($pdo, $chamado);
        $tokensEntrada = 0;
        $tokensSaida = 0;

        // Etapa 1: nossa base (barata).
        $similares = self::chamadosSimilares($pdo, $chamado, $palavras);
        if ($similares) {
            $r = AiSugestoes::gerar(
                self::promptBase($chamado, $similares, $primeiroNome),
                [],
                null,
                [],
                ['maxOutputTokens' => self::MAX_TOKENS_RESPOSTA, 'temperature' => 0.4, 'responseMimeType' => 'application/json']
            );
            if (!$r['ok']) {
                return ['status' => 'erro', 'erro' => mb_substr($r['erro'], 0, 500)];
            }
            $tokensEntrada += $r['uso']['entrada'];
            $tokensSaida += $r['uso']['saida'];

            $json = json_decode(self::extrairJson($r['texto']), true);
            if (!empty($json['resolve']) && trim($json['resposta'] ?? '') !== '') {
                $referencias = array_values(array_intersect(
                    array_map('intval', (array) ($json['referencias'] ?? [])),
                    array_map(fn ($s) => (int) $s['id'], $similares)
                ));

                return self::finalizar($pdo, $chamado, $manual, [
                    'fonte' => 'base',
                    'resposta' => self::limparTexto($json['resposta']),
                    'chamados_referencia' => implode(',', $referencias ?: array_map(fn ($s) => (int) $s['id'], $similares)),
                    'tokens_entrada' => $tokensEntrada,
                    'tokens_saida' => $tokensSaida,
                ]);
            }
        }

        // Etapa 2: TDN / User Function (busca do Google = a parte que custa).
        if (self::analisesHoje($pdo, true) >= self::limiteBuscaDia($pdo)) {
            return [
                'status' => 'sem_resposta',
                'erro' => 'Nada parecido na base e o limite diário de buscas na internet já foi atingido.',
                'tokens_entrada' => $tokensEntrada,
                'tokens_saida' => $tokensSaida,
            ];
        }

        $r = AiSugestoes::gerar(
            self::promptBusca($chamado, $primeiroNome),
            [['google_search' => (object) []]],
            AiSugestoes::chaveParaBuscaOuAnalise(),
            [],
            ['maxOutputTokens' => self::MAX_TOKENS_RESPOSTA, 'temperature' => 0.3]
        );
        if (!$r['ok']) {
            return ['status' => 'erro', 'erro' => mb_substr($r['erro'], 0, 500), 'usou_busca' => 1,
                'tokens_entrada' => $tokensEntrada, 'tokens_saida' => $tokensSaida];
        }
        $tokensEntrada += $r['uso']['entrada'];
        $tokensSaida += $r['uso']['saida'];

        $texto = trim($r['texto']);
        $encontrou = (bool) preg_match('/^\s*ENCONTROU:\s*SIM/i', $texto);
        $resposta = trim(preg_replace('/^\s*ENCONTROU:\s*(SIM|N[AÃ]O)\s*/iu', '', $texto));

        // Só vale se pelo menos uma das páginas usadas for da TOTVS ou do
        // User Function -- resposta montada com blog aleatório não serve.
        $fontesOficiais = array_values(array_filter($r['fontes'], fn ($f) => preg_match('/totvs|userfunction/i', $f['titulo'] . ' ' . $f['url'])));

        if (!$encontrou || $resposta === '' || !$fontesOficiais) {
            return [
                'status' => 'sem_resposta',
                'usou_busca' => 1,
                'links' => $r['fontes'] ? json_encode($r['fontes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'erro' => 'Não encontrou nada confiável na base nem no TDN/User Function.',
                'tokens_entrada' => $tokensEntrada,
                'tokens_saida' => $tokensSaida,
            ];
        }

        return self::finalizar($pdo, $chamado, $manual, [
            'fonte' => 'internet',
            'resposta' => self::limparTexto($resposta),
            'links' => json_encode($fontesOficiais, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'usou_busca' => 1,
            'tokens_entrada' => $tokensEntrada,
            'tokens_saida' => $tokensSaida,
        ]);
    }

    /**
     * Com resposta pronta: modo automático (e não manual) já posta no
     * chamado e avisa o cliente; senão fica só como sugestão pra equipe.
     */
    private static function finalizar(PDO $pdo, array $chamado, bool $manual, array $dados): array
    {
        $dados['status'] = 'sugerida';

        if ($manual || self::modo($pdo) !== 'automatico' || !$chamado['cliente_id']) {
            return $dados;
        }

        // Equipe já respondeu enquanto a IA pensava? Não atropela.
        $jaRespondido = $pdo->prepare("SELECT 1 FROM chamado_respostas WHERE chamado_id = ? AND autor_tipo = 'equipe' LIMIT 1");
        $jaRespondido->execute([$chamado['id']]);
        if ($jaRespondido->fetchColumn()) {
            return $dados;
        }

        $nomeAutor = self::nomeAutor($pdo);
        $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_usuario_id, mensagem) VALUES (?, "equipe", ?, NULL, ?)')
            ->execute([$chamado['id'], $nomeAutor, $dados['resposta']]);
        $dados['resposta_id'] = (int) $pdo->lastInsertId();
        $dados['status'] = 'enviada';

        recalcular_status_chamado($pdo, (int) $chamado['id']);

        try {
            $stmtCliente = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
            $stmtCliente->execute([$chamado['cliente_id']]);
            $cliente = $stmtCliente->fetch();
            $stmtChamado = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
            $stmtChamado->execute([$chamado['id']]);
            $chamadoAtualizado = $stmtChamado->fetch();

            if ($cliente && $chamadoAtualizado) {
                $pessoa = resolver_pessoa_do_chamado($pdo, $chamadoAtualizado, $cliente);
                Notificacoes::novaRespostaEquipe($chamadoAtualizado, $pessoa, $nomeAutor, $dados['resposta'], []);
                if (!empty($pessoa['telefone'])) {
                    WhatsAppBot::prepararConversaPosResposta($pdo, $chamadoAtualizado, $pessoa['telefone']);
                }
            }
        } catch (\Throwable $e) {
            error_log('IA chamados: resposta postada no chamado #' . $chamado['id'] . ', mas falhou ao avisar o cliente: ' . $e->getMessage());
        }

        return $dados;
    }

    private static function analisesHoje(PDO $pdo, bool $soComBusca): int
    {
        $sql = "SELECT COUNT(*) FROM chamado_ia_analises
            WHERE atualizado_em >= CURDATE() AND status IN ('sugerida','enviada','sem_resposta','erro')"
            . ($soComBusca ? ' AND usou_busca = 1' : '');

        return (int) $pdo->query($sql)->fetchColumn();
    }

    // --- Busca na nossa base ----------------------------------------------------

    /** Palavras mais "informativas" do texto (códigos de rotina/tabela primeiro). */
    private static function palavrasChave(string $texto): array
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', $texto, $m);
        $pontuadas = [];
        foreach ($m[0] as $palavraOriginal) {
            // Sem acento: "transmissão" e "transmissao" viram a mesma palavra
            // (o LIKE do MySQL já ignora acento, então não perde nada).
            $palavra = self::semAcento(mb_strtolower($palavraOriginal, 'UTF-8'));
            $temDigito = (bool) preg_match('/[\d_]/', $palavra);
            $ehSigla = (bool) preg_match('/^[A-Z]{2,4}$/', $palavraOriginal); // SX3, SF2, NFE, CTE...
            if (mb_strlen($palavra) < ($temDigito || $ehSigla ? 2 : 4)) {
                continue;
            }
            // Número solto (nº de nota, valor) é ruído; com 3 dígitos costuma
            // ser código de rejeição da SEFAZ (539, 204...), aí vale.
            if (in_array($palavra, self::stopwordsSemAcento(), true) || (ctype_digit($palavra) && strlen($palavra) !== 3)) {
                continue;
            }
            $peso = mb_strlen($palavra) + ($temDigito ? 10 : 0) + ($ehSigla ? 8 : 0);
            $pontuadas[$palavra] = max($pontuadas[$palavra] ?? 0, $peso);
        }
        arsort($pontuadas);

        return array_slice(array_keys($pontuadas), 0, self::MAX_PALAVRAS_CHAVE);
    }

    private static function chamadosSimilares(PDO $pdo, array $chamado, array $palavras): array
    {
        $pontos = [];
        $filtro = [];
        $paramsPontos = [];
        $paramsFiltro = [];
        foreach ($palavras as $palavra) {
            $like = '%' . addcslashes($palavra, '%_\\') . '%';
            $pontos[] = '(CASE WHEN ch.titulo LIKE ? THEN 3 ELSE 0 END + CASE WHEN ch.descricao LIKE ? THEN 1 ELSE 0 END)';
            array_push($paramsPontos, $like, $like);
            $filtro[] = 'ch.titulo LIKE ? OR ch.descricao LIKE ?';
            array_push($paramsFiltro, $like, $like);
        }

        // Só chamado em que a EQUIPE respondeu de verdade (resposta postada
        // pela própria IA não conta -- senão ela aprende com ela mesma).
        $sql = 'SELECT ch.id, ch.titulo, ch.descricao, ch.categoria, ch.status, (' . implode(' + ', $pontos) . ') AS pontos
            FROM chamados ch
            WHERE ch.id <> ?
              AND (' . implode(' OR ', $filtro) . ')
              AND EXISTS (
                  SELECT 1 FROM chamado_respostas r
                  WHERE r.chamado_id = ch.id AND r.autor_tipo = \'equipe\'
                    AND r.id NOT IN (SELECT resposta_id FROM chamado_ia_analises WHERE resposta_id IS NOT NULL)
              )
            HAVING pontos >= 4
            ORDER BY pontos DESC, (ch.status = \'fechado\') DESC, ch.criado_em DESC
            LIMIT ' . self::MAX_CHAMADOS_REFERENCIA;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($paramsPontos, [(int) $chamado['id']], $paramsFiltro));
        $similares = $stmt->fetchAll();

        $stmtRespostas = $pdo->prepare("SELECT autor_tipo, mensagem FROM chamado_respostas
            WHERE chamado_id = ? AND mensagem <> '(anexo enviado)'
              AND id NOT IN (SELECT resposta_id FROM chamado_ia_analises WHERE resposta_id IS NOT NULL)
            ORDER BY criado_em ASC, id ASC LIMIT 8");
        foreach ($similares as &$s) {
            $stmtRespostas->execute([$s['id']]);
            $conversa = $stmtRespostas->fetchAll();
            $daEquipe = array_values(array_filter($conversa, fn ($r) => $r['autor_tipo'] === 'equipe'));
            // As últimas respostas da equipe costumam ter a solução final.
            $s['respostas_equipe'] = array_slice($daEquipe, -self::MAX_RESPOSTAS_POR_REFERENCIA);
        }
        unset($s);

        return $similares;
    }

    // --- Prompts -------------------------------------------------------------

    private static function regrasDeEscrita(string $primeiroNome): string
    {
        $saudacao = $primeiroNome !== ''
            ? "Comece cumprimentando {$primeiroNome} pelo primeiro nome, de um jeito simples e variado (ex.: \"Oi, {$primeiroNome}, tudo bem?\")."
            : 'Comece com um cumprimento simples (ex.: "Olá, tudo bem?").';

        return <<<TXT
COMO ESCREVER A RESPOSTA (muito importante):
- Você escreve como um consultor Protheus da PrimeERP falando com o cliente: português do Brasil, cordial, direto e natural, como uma pessoa de verdade escreveria no chamado.
- {$saudacao}
- Nunca diga que é IA, robô, assistente virtual ou modelo. Não fale em "base de dados", "chamados anteriores", "outros clientes", "pesquisei" ou "encontrei na internet".
- Nunca cite nome de outro cliente nem dados de outro chamado.
- Texto puro: sem markdown, sem asteriscos, sem #, sem tabelas, sem emojis. Se precisar de passo a passo, use linhas "1)", "2)", "3)".
- Seja curto: no máximo uns 170 palavras. Nada de introdução longa nem despedida formal; não assine (o nome já aparece no chamado).
- Use os nomes certos de rotina, parâmetro (MV_...), tabela e campo, mas NUNCA invente um. Se faltar informação pra ter certeza, peça de forma objetiva (ex.: print do erro, rotina, filial, exemplo de documento).
- Não prometa prazo. Se a solução exigir ajuste em fonte/customização, diga que a equipe vai verificar e retornar.
TXT;
    }

    private static function blocoChamado(array $chamado): string
    {
        return "CHAMADO NOVO\n"
            . 'Cliente: ' . ($chamado['cliente_nome'] ?: '-') . "\n"
            . 'Título: ' . $chamado['titulo'] . "\n"
            . 'Categoria: ' . ($chamado['categoria'] ?: '-') . "\n"
            . "Descrição:\n" . mb_substr($chamado['descricao'], 0, 3000) . "\n";
    }

    private static function promptBase(array $chamado, array $similares, string $primeiroNome): string
    {
        $referencias = '';
        foreach ($similares as $s) {
            $referencias .= "\n--- Chamado de referência #{$s['id']} ---\n"
                . 'Título: ' . $s['titulo'] . "\n"
                . 'Problema: ' . mb_substr($s['descricao'], 0, 700) . "\n";
            foreach ($s['respostas_equipe'] as $i => $r) {
                $referencias .= 'Resposta da nossa equipe ' . ($i + 1) . ': ' . mb_substr($r['mensagem'], 0, 900) . "\n";
            }
        }

        return "Você é consultor de TOTVS Protheus na PrimeERP. Chegou um chamado novo de cliente. "
            . "Abaixo estão chamados parecidos que a nossa equipe já atendeu, com as respostas que demos. "
            . "Use-os como referência técnica E como exemplo do nosso jeito de escrever.\n\n"
            . self::blocoChamado($chamado)
            . "\nCHAMADOS PARECIDOS JÁ ATENDIDOS:" . $referencias . "\n\n"
            . "TAREFA: decida se as soluções acima resolvem (ou orientam bem) o chamado novo. Só considere que resolve se for "
            . "realmente o MESMO tipo de problema -- palavra parecida não basta.\n\n"
            . self::regrasDeEscrita($primeiroNome) . "\n\n"
            . 'Responda SOMENTE com JSON neste formato: {"resolve": true ou false, "resposta": "texto pro cliente (vazio se resolve=false)", "referencias": [ids dos chamados de referência usados]}';
    }

    private static function promptBusca(array $chamado, string $primeiroNome): string
    {
        return "Você é consultor de TOTVS Protheus na PrimeERP. Chegou o chamado abaixo e precisamos de uma orientação técnica confiável.\n\n"
            . self::blocoChamado($chamado)
            . "\nPESQUISE usando a busca do Google SOMENTE nestes sites oficiais/de referência:\n"
            . "- TDN da TOTVS: use consultas com site:tdn.totvs.com (e, se precisar, site:centraldeatendimento.totvs.com)\n"
            . "- User Function: use consultas com site:userfunction.com.br\n"
            . "Ignore qualquer outro site. Se nesses sites não houver nada que realmente se aplique a esse problema, não invente.\n\n"
            . self::regrasDeEscrita($primeiroNome) . "\n\n"
            . "FORMATO DA SAÍDA: a primeira linha deve ser exatamente \"ENCONTROU: SIM\" ou \"ENCONTROU: NAO\". "
            . "Se SIM, a partir da segunda linha escreva só a resposta pro cliente. Se NAO, não escreva mais nada.";
    }

    // --- Utilitários -------------------------------------------------------------

    private static function primeiroNomeContato(PDO $pdo, array $chamado): string
    {
        try {
            if (!$chamado['cliente_id']) {
                return '';
            }
            $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
            $stmt->execute([$chamado['cliente_id']]);
            $cliente = $stmt->fetch();
            if (!$cliente) {
                return '';
            }
            $nome = trim((string) (resolver_pessoa_do_chamado($pdo, $chamado, $cliente)['nome'] ?? ''));
            // Nome da EMPRESA (fallback do helper) não serve pra cumprimentar.
            if ($nome === '' || mb_strtolower($nome) === mb_strtolower((string) $cliente['nome'])) {
                return '';
            }
            $primeiro = explode(' ', $nome)[0];

            return mb_convert_case(mb_strtolower($primeiro, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function extrairJson(string $texto): string
    {
        $texto = trim($texto);
        $texto = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $texto);
        $inicio = strpos($texto, '{');
        $fim = strrpos($texto, '}');

        return ($inicio !== false && $fim !== false) ? substr($texto, $inicio, $fim - $inicio + 1) : $texto;
    }

    private static function semAcento(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'î' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }

    private static function stopwordsSemAcento(): array
    {
        static $lista = null;

        return $lista ??= array_values(array_unique(array_map([self::class, 'semAcento'], self::STOPWORDS)));
    }

    /** Tira qualquer resto de markdown que o modelo deixar escapar. */
    private static function limparTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/\*\*(.+?)\*\*/s', '$1', $texto);
        $texto = preg_replace('/(?<!\w)__(.+?)__(?!\w)/s', '$1', $texto);
        $texto = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $texto);
        $texto = preg_replace('/^\s*[\*\-•]\s+/m', '- ', $texto);
        $texto = preg_replace('/`([^`]+)`/', '$1', $texto);
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);

        return trim($texto);
    }
}
