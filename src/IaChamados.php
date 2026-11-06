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
 *  3. Se a base não resolver: procura no User Function pela API pública do
 *     site (sem custo de busca) e o modelo barato responde com base nos
 *     artigos encontrados.
 *  4. Só se nada disso resolver: busca do Google no TDN da TOTVS (o site
 *     bloqueia acesso direto). É a única etapa cobrada por pesquisa, por
 *     isso tem limite diário próprio (ver configuracoes.php).
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
    // Google sobrecarregado (503/429) ou rede: volta pra fila e o cron tenta
    // de novo mais tarde, até esse limite de tentativas.
    private const MAX_TENTATIVAS = 3;

    // Botão "Sugerir resposta": tempo total máximo da análise e de cada
    // chamada ao Google. Passou do prazo, pula direto pro fim (resposta com
    // base na conversa) em vez de deixar a tela esperando pra sempre.
    private const PRAZO_MANUAL_SEGUNDOS = 100;
    // Cada chamada ao Google tem no máximo isso (cada etapa faz no máximo
    // uma chamada, com até 2 novas tentativas se o Google estiver instável).
    private const TIMEOUT_CHAMADA_SEGUNDOS = 25;
    // Etapa marcada "em execução" há mais tempo que isso = o processo
    // morreu no meio (a hospedagem mata requisição longa) -- é pulada.
    public const SEGUNDOS_ETAPA_MORTA = 100;

    // Passo a passo da análise em andamento (gravado em chamado_ia_analises.progresso).
    private static ?PDO $pdoProgresso = null;
    private static int $chamadoProgresso = 0;
    private static array $passos = [];
    private static float $inicio = 0;
    private static float $prazo = 0;
    private static ?string $ultimoErro = null;
    // Resposta curta = barata e com cara de conversa (não de manual).
    private const MAX_TOKENS_RESPOSTA = 900;
    // Modelo "flash" cheio pensa antes de responder (tokens de raciocínio
    // contam na saída), então precisa de mais folga pra não cortar a resposta.
    private const MAX_TOKENS_RESPOSTA_BUSCA = 4000;
    // "flash" cheio primeiro na busca: nos testes o "flash-lite" respondia
    // sem nem chamar a ferramenta de busca do Google.
    public const MODELOS_BUSCA = ['gemini-flash-latest', 'gemini-flash-lite-latest'];

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
     * Roda a análise inteira num processo só (abertura de chamado, cron,
     * WhatsApp, testes): executa as etapas uma atrás da outra. $manual =
     * pedido explícito da equipe -- refaz do zero e NUNCA responde o cliente
     * sozinho. Retorna a linha final de chamado_ia_analises (ou null se não
     * rodou porque outro processo já está cuidando dela).
     */
    public static function processar(PDO $pdo, int $chamadoId, bool $manual = false): ?array
    {
        @set_time_limit(300);
        ignore_user_abort(true);

        if ($manual) {
            self::iniciarManual($pdo, $chamadoId);
        } elseif (!self::assumirAutomatica($pdo, $chamadoId)) {
            return null;
        }

        for ($i = 0; $i < 12; $i++) {
            $r = self::executarEtapa($pdo, $chamadoId, $manual);
            if ($r['terminou']) {
                break;
            }
            if ($r['ocupada']) {
                return null; // outro processo está no meio de uma etapa
            }
        }

        return self::linha($pdo, $chamadoId) ?: null;
    }

    /** Começa (ou recomeça) a análise manual do zero. */
    public static function iniciarManual(PDO $pdo, int $chamadoId): void
    {
        $estado = json_encode(['etapa' => 'inicio', 'inicio' => microtime(true), 'manual' => true]);
        $pdo->prepare("INSERT INTO chamado_ia_analises (chamado_id, status, progresso, estado) VALUES (?, 'processando', '[]', ?)
            ON DUPLICATE KEY UPDATE status = 'processando', erro = NULL, resposta = NULL, fonte = NULL, links = NULL,
                chamados_referencia = NULL, progresso = '[]', estado = VALUES(estado), tokens_entrada = 0, tokens_saida = 0, usou_busca = 0")
            ->execute([$chamadoId, $estado]);
    }

    /**
     * Análise automática: pega pra si uma pendente (nova) ou uma que ficou
     * parada no meio (processo morreu) -- trava atômica, pra dois processos
     * nunca rodarem a mesma ao mesmo tempo.
     */
    private static function assumirAutomatica(PDO $pdo, int $chamadoId): bool
    {
        $estado = json_encode(['etapa' => 'inicio', 'inicio' => microtime(true), 'manual' => false]);
        $nova = $pdo->prepare("UPDATE chamado_ia_analises SET status = 'processando', progresso = '[]', estado = ?, tentativas = tentativas + 1
            WHERE chamado_id = ? AND status = 'pendente' AND tentativas < " . self::MAX_TENTATIVAS);
        $nova->execute([$estado, $chamadoId]);
        if ($nova->rowCount() > 0) {
            return true;
        }

        $parada = $pdo->prepare("UPDATE chamado_ia_analises SET atualizado_em = NOW()
            WHERE chamado_id = ? AND status = 'processando'
              AND atualizado_em < DATE_SUB(NOW(), INTERVAL " . self::SEGUNDOS_ETAPA_MORTA . " SECOND)");
        $parada->execute([$chamadoId]);

        return $parada->rowCount() > 0;
    }

    private static function linha(PDO $pdo, int $chamadoId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM chamado_ia_analises WHERE chamado_id = ?');
        $stmt->execute([$chamadoId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Executa UMA etapa da análise e grava o estado no banco. Cada etapa é
     * curta (no máximo uma chamada ao Google), então cabe numa requisição
     * normal da hospedagem. Se o processo morrer no meio de uma etapa, na
     * próxima vez ela é pulada e a análise segue pra próxima.
     * Retorna ['terminou' => bool, 'ocupada' => bool].
     */
    public static function executarEtapa(PDO $pdo, int $chamadoId, bool $manual, bool $anteriorFalhou = false): array
    {
        $linha = self::linha($pdo, $chamadoId);
        if (!$linha || $linha['status'] !== 'processando') {
            return ['terminou' => true, 'ocupada' => false];
        }

        $estado = json_decode($linha['estado'] ?? '', true) ?: ['etapa' => 'inicio', 'inicio' => microtime(true), 'manual' => $manual];
        self::$pdoProgresso = $pdo;
        self::$chamadoProgresso = $chamadoId;
        self::$passos = json_decode($linha['progresso'] ?? '[]', true) ?: [];
        self::$inicio = (float) $estado['inicio'];
        self::$prazo = self::$inicio + ($manual ? self::PRAZO_MANUAL_SEGUNDOS : 240);
        AiSugestoes::$timeoutPorChamada = self::TIMEOUT_CHAMADA_SEGUNDOS;
        AiSugestoes::$trocarModeloNoTimeout = true;
        AiSugestoes::$aoTentarDeNovo = fn (string $msg) => self::passo($msg, 'aviso');

        $etapa = $estado['etapa'];

        // Etapa marcada "em execução" por outra requisição: ou ainda está
        // rodando (espera), ou o processo morreu no meio (pula ela).
        if (!empty($estado['executando'])) {
            // $anteriorFalhou: a tela viu a requisição anterior cair (erro de
            // servidor), então não precisa esperar o tempo todo pra pular.
            $limite = $anteriorFalhou ? 5 : self::SEGUNDOS_ETAPA_MORTA;
            if (microtime(true) - (float) $estado['executando'] < $limite) {
                return ['terminou' => false, 'ocupada' => true];
            }
            self::resultado('não terminou no servidor (processo interrompido), pulando', 'erro');
            $estado['executando'] = null;
            $estado['etapa'] = self::ETAPA_SE_FALHAR[$etapa] ?? 'fim_erro';
            self::salvarEstado($estado);
            if (str_starts_with($estado['etapa'], 'fim_')) {
                self::concluir($pdo, $chamadoId, $manual, $estado['etapa'] === 'fim_erro'
                    ? ['status' => 'erro', 'erro' => 'A etapa "' . $etapa . '" foi interrompida no servidor.']
                    : ['status' => 'sem_resposta', 'erro' => 'Não encontrou nada confiável (uma etapa foi interrompida no servidor).']);

                return ['terminou' => true, 'ocupada' => false];
            }

            return ['terminou' => false, 'ocupada' => false];
        }

        $estado['executando'] = microtime(true);
        self::salvarEstado($estado);

        // Erro fatal do PHP no meio da etapa (ou limite de tempo da
        // hospedagem): deixa registrado no passo a passo, pra dar pra ver o motivo.
        register_shutdown_function(function () use ($chamadoId) {
            $erro = error_get_last();
            if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && self::$chamadoProgresso === $chamadoId) {
                self::resultado('erro no servidor: ' . mb_substr($erro['message'], 0, 180), 'erro');
            }
        });

        try {
            $r = self::rodarEtapa($pdo, $chamadoId, $manual, $estado);
        } catch (\Throwable $e) {
            error_log('IA chamados: erro na etapa ' . $etapa . ' do chamado #' . $chamadoId . ': ' . $e->getMessage());
            self::resultado('erro: ' . mb_substr($e->getMessage(), 0, 150), 'erro');
            $proxima = self::ETAPA_SE_FALHAR[$etapa] ?? 'fim_erro';
            $r = $proxima === 'fim_erro'
                ? ['fim' => ['status' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 500)]]
                : ['proxima' => $proxima];
        }

        $estado = $r['estado'] ?? $estado;
        $estado['executando'] = null;

        if (isset($r['fim'])) {
            $estado['etapa'] = 'fim';
            self::salvarEstado($estado);
            self::concluir($pdo, $chamadoId, $manual, $r['fim']);

            return ['terminou' => true, 'ocupada' => false];
        }

        $estado['etapa'] = $r['proxima'];
        self::salvarEstado($estado);

        return ['terminou' => false, 'ocupada' => false];
    }

    // Pra onde ir quando uma etapa falha ou morre no meio.
    private const ETAPA_SE_FALHAR = [
        'inicio' => 'fim_erro',
        'base' => 'termos',
        'termos' => 'tdn',
        'uf_busca' => 'tdn',
        'uf_ler' => 'tdn',
        'tdn' => 'conversa',
        'conversa' => 'fim_erro',
    ];

    private static function salvarEstado(array $estado): void
    {
        try {
            self::$pdoProgresso->prepare('UPDATE chamado_ia_analises SET estado = ?, progresso = ? WHERE chamado_id = ?')
                ->execute([json_encode($estado, JSON_UNESCAPED_UNICODE), json_encode(self::$passos, JSON_UNESCAPED_UNICODE), self::$chamadoProgresso]);
        } catch (\Throwable $e) {
            error_log('IA chamados: falha ao salvar estado: ' . $e->getMessage());
        }
    }

    /** Gravação final (resposta/erro) -- e, na automática, nova tentativa se o Google estava instável. */
    private static function concluir(PDO $pdo, int $chamadoId, bool $manual, array $resultado): void
    {
        $fim = [
            'sugerida' => ['Sugestão pronta (deixar como: ' . self::rotuloStatus($resultado['status_sugerido'] ?? '') . ')', 'ok'],
            'enviada' => ['Resposta enviada ao cliente, chamado ' . self::rotuloStatus($resultado['status_sugerido'] ?? ''), 'ok'],
            'sem_resposta' => ['Nada confiável encontrado: ' . ($resultado['erro'] ?? ''), 'aviso'],
            'ignorada' => ['Análise não feita: ' . ($resultado['erro'] ?? ''), 'aviso'],
            'erro' => ['Erro: ' . self::erroCurto($resultado['erro'] ?? ''), 'erro'],
        ][$resultado['status']] ?? null;
        if ($fim) {
            self::passo($fim[0], $fim[1]);
        }

        $campos = array_merge([
            'fonte' => null, 'resposta' => null, 'chamados_referencia' => null, 'links' => null, 'erro' => null, 'resposta_id' => null,
            'status_sugerido' => null,
        ], $resultado);

        $linha = self::linha($pdo, $chamadoId);
        $falhaPassageira = $campos['status'] === 'erro' && preg_match('/HTTP (429|500|503)|falha de rede|interrompida/i', (string) $campos['erro']);
        if ($falhaPassageira && !$manual && (int) ($linha['tentativas'] ?? 0) < self::MAX_TENTATIVAS) {
            $campos['status'] = 'pendente';
            $campos['erro'] = 'Google instável, vai tentar de novo: ' . $campos['erro'];
        }

        $pdo->prepare('UPDATE chamado_ia_analises SET status = ?, fonte = ?, resposta = ?, chamados_referencia = ?, links = ?, erro = ?,
                resposta_id = ?, status_sugerido = ?, progresso = ? WHERE chamado_id = ?')
            ->execute([
                $campos['status'], $campos['fonte'], $campos['resposta'], $campos['chamados_referencia'], $campos['links'],
                $campos['erro'] !== null ? mb_substr($campos['erro'], 0, 500) : null, $campos['resposta_id'], $campos['status_sugerido'],
                json_encode(self::$passos, JSON_UNESCAPED_UNICODE), $chamadoId,
            ]);
    }

    /**
     * Registra um passo da análise e já grava no banco, pra tela acompanhar
     * ao vivo. Cada passo novo fecha o anterior com o tempo que levou.
     * $tipo: andamento | ok | aviso | erro.
     */
    private static function passo(string $texto, string $tipo = 'andamento'): void
    {
        $agora = round(microtime(true) - self::$inicio, 1);
        // Aviso (nova tentativa no Google) entra no meio, sem fechar o passo atual.
        if ($tipo !== 'aviso') {
            self::fecharPassoAtual('', 'ok', $agora);
        }
        self::$passos[] = ['t' => $agora, 'texto' => mb_substr($texto, 0, 300), 'tipo' => $tipo];
        self::passoGravar();
    }

    /** Fecha o passo em andamento com o resultado (ex.: "2 parecidos: #13, #40"). */
    private static function resultado(string $complemento, string $tipo = 'ok'): void
    {
        self::fecharPassoAtual($complemento, $tipo, round(microtime(true) - self::$inicio, 1));
        self::passoGravar();
    }

    private static function fecharPassoAtual(string $complemento, string $tipo, float $agora): void
    {
        for ($i = count(self::$passos) - 1; $i >= 0; $i--) {
            if (self::$passos[$i]['tipo'] === 'andamento') {
                self::$passos[$i]['tipo'] = $tipo;
                if ($complemento !== '') {
                    self::$passos[$i]['texto'] .= ' → ' . mb_substr($complemento, 0, 200);
                }
                self::$passos[$i]['duracao'] = round($agora - self::$passos[$i]['t'], 1);

                return;
            }
        }
    }

    private static function passoGravar(): void
    {
        if (!self::$pdoProgresso || !self::$chamadoProgresso) {
            return;
        }
        try {
            self::$pdoProgresso->prepare('UPDATE chamado_ia_analises SET progresso = ? WHERE chamado_id = ?')
                ->execute([json_encode(self::$passos, JSON_UNESCAPED_UNICODE), self::$chamadoProgresso]);
        } catch (\Throwable $e) {
            // Passo a passo é só informativo -- nunca derruba a análise.
        }
    }

    private static function tempoEsgotado(): bool
    {
        return microtime(true) > self::$prazo;
    }

    /** Chama o Gemini e já soma os tokens gastos na análise. */
    private static function gerarIa(string $prompt, array $ferramentas = [], ?string $chave = null, array $arquivos = [], array $config = [], ?array $modelos = null): array
    {
        $r = AiSugestoes::gerar($prompt, $ferramentas, $chave, $arquivos, $config, $modelos);
        if ($r['ok'] && self::$pdoProgresso) {
            self::$pdoProgresso->prepare('UPDATE chamado_ia_analises SET tokens_entrada = tokens_entrada + ?, tokens_saida = tokens_saida + ? WHERE chamado_id = ?')
                ->execute([$r['uso']['entrada'], $r['uso']['saida'], self::$chamadoProgresso]);
        }

        return $r;
    }

    /** Análises que ficaram pra trás: pendentes ou paradas no meio (usado pelo cron). */
    public static function processarPendentes(PDO $pdo, int $limite = 5): int
    {
        $ids = $pdo->query("SELECT chamado_id FROM chamado_ia_analises
            WHERE (status = 'pendente' AND criado_em < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                    AND (tentativas = 0 OR atualizado_em < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
               OR (status = 'processando' AND atualizado_em < DATE_SUB(NOW(), INTERVAL " . self::SEGUNDOS_ETAPA_MORTA . " SECOND)
                    AND (estado IS NULL OR estado NOT LIKE '%\"manual\":true%'))
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

    // --- Etapas --------------------------------------------------------------

    /** Dados do chamado que toda etapa usa (recarregados a cada requisição). */
    private static function contexto(PDO $pdo, int $chamadoId): ?array
    {
        $stmt = $pdo->prepare('SELECT ch.*, c.nome AS cliente_nome FROM chamados ch LEFT JOIN clientes c ON c.id = ch.cliente_id WHERE ch.id = ?');
        $stmt->execute([$chamadoId]);
        $chamado = $stmt->fetch();
        if (!$chamado) {
            return null;
        }
        $chamado['conversa'] = self::conversa($pdo, $chamadoId);

        return $chamado;
    }

    /**
     * Uma etapa. Devolve ['proxima' => etapa] ou ['fim' => resultado], e
     * opcionalmente 'estado' atualizado (termos de busca, artigos...).
     */
    private static function rodarEtapa(PDO $pdo, int $chamadoId, bool $manual, array $estado): array
    {
        $chamado = self::contexto($pdo, $chamadoId);
        if (!$chamado) {
            return ['fim' => ['status' => 'erro', 'erro' => 'Chamado não encontrado.']];
        }
        $chamado['__manual'] = $manual;
        $primeiroNome = self::primeiroNomeContato($pdo, $chamado);
        $palavras = self::palavrasChave($chamado['titulo'] . ' ' . $chamado['descricao']);
        $imagens = $estado['imagens'] ?? [];

        switch ($estado['etapa']) {
            case 'inicio':
                if (!AiSugestoes::configurada()) {
                    return ['fim' => ['status' => 'erro', 'erro' => 'Chave do Gemini não configurada (Configurações > IA Gemini).']];
                }
                self::passo('Lendo o chamado e a conversa');
                self::resultado(count($chamado['conversa']) . ' mensagem(ns) além da abertura');
                if (count($palavras) < 2 && !($manual && $chamado['conversa'])) {
                    return ['fim' => ['status' => 'ignorada', 'erro' => 'Descrição curta demais pra analisar.']];
                }
                if (!$manual && self::analisesHoje($pdo, false) >= self::limiteDia($pdo)) {
                    return ['fim' => ['status' => 'ignorada', 'erro' => 'Limite diário de análises atingido.']];
                }
                // Prints/fotos anexados (geralmente é onde está a mensagem de erro).
                self::passo('Enviando os prints anexados pra IA ler');
                $estado['imagens'] = self::imagensDoChamado($pdo, $chamadoId);
                self::resultado($estado['imagens'] ? count($estado['imagens']) . ' imagem(ns): ' . implode(', ', array_column($estado['imagens'], 'nome')) : 'nenhuma imagem no chamado');

                return ['proxima' => 'base', 'estado' => $estado];

            case 'base':
                // Nossa base: busca por palavra-chave no MySQL, sem custo.
                self::passo('Procurando chamados parecidos na nossa base (palavras: ' . implode(', ', array_slice($palavras, 0, 6)) . ')');
                $similares = self::chamadosSimilares($pdo, $chamado, $palavras);
                self::resultado($similares ? count($similares) . ' parecido(s): #' . implode(', #', array_column($similares, 'id')) : 'nenhum parecido');
                if (!$similares) {
                    return ['proxima' => 'termos'];
                }

                self::passo('IA conferindo se a solução dos chamados parecidos serve pra este');
                $r = self::gerarIa(self::promptBase($chamado, $similares, $primeiroNome), [], null, $imagens,
                    ['maxOutputTokens' => self::MAX_TOKENS_RESPOSTA, 'temperature' => 0.4, 'responseMimeType' => 'application/json']);
                if (!$r['ok']) {
                    self::resultado(self::erroCurto($r['erro']), 'erro');

                    return (!$manual || self::erroDeChave($r['erro']))
                        ? ['fim' => ['status' => 'erro', 'erro' => $r['erro']]]
                        : ['proxima' => 'termos'];
                }
                $json = json_decode(self::extrairJson($r['texto']), true);
                if (empty($json['resolve']) || trim($json['resposta'] ?? '') === '') {
                    self::resultado('não é o mesmo problema');

                    return ['proxima' => 'termos'];
                }
                self::resultado('serve');
                $referencias = array_values(array_intersect(
                    array_map('intval', (array) ($json['referencias'] ?? [])),
                    array_map(fn ($s) => (int) $s['id'], $similares)
                ));

                return ['fim' => self::finalizar($pdo, $chamado, $manual, [
                    'fonte' => 'base',
                    'status_chamado' => self::statusEscolhido($json['status'] ?? ''),
                    'resposta' => self::garantirNome(self::limparTexto($json['resposta']), $primeiroNome),
                    'chamados_referencia' => implode(',', $referencias ?: array_map(fn ($s) => (int) $s['id'], $similares)),
                ])];

            case 'termos':
                if (self::tempoEsgotado()) {
                    return ['proxima' => 'tdn'];
                }
                self::passo('IA criando termos de busca');
                self::$ultimoErro = null;
                $tokE = 0;
                $tokS = 0;
                $estado['consultas'] = self::consultasDeBusca($chamado, $tokE, $tokS);
                if ($tokE && self::$pdoProgresso) {
                    $pdo->prepare('UPDATE chamado_ia_analises SET tokens_entrada = tokens_entrada + ?, tokens_saida = tokens_saida + ? WHERE chamado_id = ?')
                        ->execute([$tokE, $tokS, $chamadoId]);
                }
                if (!$estado['consultas']) {
                    self::resultado('não conseguiu gerar termos' . (self::$ultimoErro ? ' (' . self::erroCurto(self::$ultimoErro) . ')' : ''), 'aviso');

                    return self::erroDeChave(self::$ultimoErro)
                        ? ['fim' => ['status' => 'erro', 'erro' => self::$ultimoErro]]
                        : ['proxima' => 'tdn', 'estado' => $estado];
                }
                self::resultado('"' . implode('", "', $estado['consultas']) . '"');

                return ['proxima' => 'uf_busca', 'estado' => $estado];

            case 'uf_busca':
                // User Function: API pública do site, sem custo de busca.
                self::passo('Buscando artigos no User Function');
                $estado['artigos'] = self::artigosUserFunction($estado['consultas'] ?? []);
                self::resultado($estado['artigos'] ? count($estado['artigos']) . ' artigo(s): ' . implode(' | ', array_column($estado['artigos'], 'titulo')) : 'nenhum artigo encontrado');

                return ['proxima' => $estado['artigos'] ? 'uf_ler' : 'tdn', 'estado' => $estado];

            case 'uf_ler':
                $artigos = $estado['artigos'] ?? [];
                self::passo('IA lendo os artigos do User Function');
                $r = self::gerarIa(self::promptArtigos($chamado, $artigos, $primeiroNome), [], null, $imagens,
                    ['maxOutputTokens' => self::MAX_TOKENS_RESPOSTA, 'temperature' => 0.3, 'responseMimeType' => 'application/json']);
                if (!$r['ok']) {
                    self::resultado(self::erroCurto($r['erro']), 'erro');

                    return self::erroDeChave($r['erro']) ? ['fim' => ['status' => 'erro', 'erro' => $r['erro']]] : ['proxima' => 'tdn'];
                }
                $json = json_decode(self::extrairJson($r['texto']), true);
                if (empty($json['resolve']) || trim($json['resposta'] ?? '') === '') {
                    self::resultado('os artigos não resolvem este caso');

                    return ['proxima' => 'tdn'];
                }
                self::resultado('encontrou a solução');
                $usados = array_values(array_filter(array_map(fn ($i) => $artigos[(int) $i - 1] ?? null, (array) ($json['artigos'] ?? []))));

                return ['fim' => self::finalizar($pdo, $chamado, $manual, [
                    'fonte' => 'internet',
                    'status_chamado' => self::statusEscolhido($json['status'] ?? ''),
                    'resposta' => self::garantirNome(self::limparTexto($json['resposta']), $primeiroNome),
                    'links' => json_encode(array_map(fn ($a) => ['titulo' => $a['titulo'], 'url' => $a['url']], $usados ?: $artigos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ])];

            case 'tdn':
                // TDN via busca do Google -- a única parte cobrada por
                // pesquisa, por isso tem limite diário próprio.
                $semTempo = self::tempoEsgotado();
                $semCota = self::analisesHoje($pdo, true) >= self::limiteBuscaDia($pdo);
                if ($semTempo || $semCota) {
                    self::passo($semTempo ? 'Pulando a busca no TDN: tempo máximo da análise atingido' : 'Pulando a busca no TDN: limite diário de buscas atingido', 'aviso');

                    return ['proxima' => 'conversa'];
                }
                // Conta a busca ANTES de chamar (se o processo morrer no
                // meio, ainda assim entra no limite diário de custo).
                $pdo->prepare('UPDATE chamado_ia_analises SET usou_busca = 1 WHERE chamado_id = ?')->execute([$chamadoId]);

                self::passo('Pesquisando no TDN da TOTVS (busca do Google)');
                $r = self::gerarIa(self::promptBusca($chamado, $primeiroNome, $estado['consultas'] ?? []), [['google_search' => (object) []]],
                    AiSugestoes::chaveParaBuscaOuAnalise(), [], ['maxOutputTokens' => self::MAX_TOKENS_RESPOSTA_BUSCA, 'temperature' => 0.3], self::MODELOS_BUSCA);
                if (!$r['ok']) {
                    self::resultado(self::erroCurto($r['erro']), 'erro');
                    if (!self::erroDeChave($r['erro'])) {
                        return ['proxima' => 'conversa'];
                    }

                    return ['fim' => ['status' => 'erro', 'erro' => $r['erro']]];
                }
                $resposta = trim($r['texto']);
                $statusTdn = 'aguardando_cliente';
                if (preg_match('/\n?\s*STATUS:\s*([a-z_]+)\s*$/iu', $resposta, $mStatus)) {
                    $statusTdn = self::statusEscolhido($mStatus[1]);
                    $resposta = trim(substr($resposta, 0, -strlen($mStatus[0])));
                }
                $naoEncontrou = $resposta === '' || stripos($resposta, 'NAO_ENCONTRADO') !== false;
                // Só vale se pelo menos uma das páginas usadas for da TOTVS ou do
                // User Function -- resposta montada com blog aleatório não serve.
                $fontesOficiais = array_values(array_filter($r['fontes'], fn ($f) => preg_match('/totvs|userfunction/i', $f['titulo'] . ' ' . $f['url'])));
                if ($naoEncontrou || !$fontesOficiais) {
                    self::resultado($r['fontes'] ? 'páginas consultadas não resolvem' : 'nada encontrado na TOTVS', 'aviso');

                    return ['proxima' => 'conversa'];
                }
                self::resultado(count($fontesOficiais) . ' página(s) da TOTVS');

                return ['fim' => self::finalizar($pdo, $chamado, $manual, [
                    'fonte' => 'internet',
                    'status_chamado' => $statusTdn,
                    'resposta' => self::garantirNome(self::limparTexto($resposta), $primeiroNome),
                    'links' => json_encode($fontesOficiais, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ])];

            case 'conversa':
                return ['fim' => self::respostaPelaConversa($pdo, $chamado, $primeiroNome, $imagens)];
        }

        return ['fim' => ['status' => 'erro', 'erro' => 'Etapa desconhecida: ' . $estado['etapa']]];
    }

    /** Chave errada/sem permissão: não adianta tentar as outras etapas. */
    private static function erroDeChave(?string $erro): bool
    {
        return (bool) preg_match('/API key|API_KEY|HTTP (401|403)|PERMISSION_DENIED/i', (string) $erro);
    }

    /** "Gemini retornou HTTP 503: This model is..." -> "Google sobrecarregado (503)". */
    private static function erroCurto(?string $erro): string
    {
        if (self::erroDeChave($erro)) {
            return 'chave do Gemini recusada pelo Google (confira em Configurações > IA Gemini)';
        }
        if (preg_match('/HTTP (\d+)/', (string) $erro, $m)) {
            return $m[1] === '503' ? 'Google sobrecarregado (503)' : ($m[1] === '429' ? 'limite de uso do Google (429)' : 'Google respondeu HTTP ' . $m[1]);
        }

        return mb_substr((string) $erro, 0, 120);
    }

    /**
     * Último recurso (botão ou abertura do chamado): nada na base nem na
     * internet, então escreve a próxima resposta só com o que já está no
     * chamado (normalmente pedindo o que falta, ou dando retorno do que a
     * equipe já fez) -- sem inventar solução técnica.
     */
    private static function respostaPelaConversa(PDO $pdo, array $chamado, string $primeiroNome, array $imagens): array
    {
        self::passo('Sem solução documentada: IA escrevendo a resposta com base na conversa');
        $r = self::gerarIa(
            "Você é consultor de TOTVS Protheus na PrimeERP e vai escrever a próxima resposta da equipe neste chamado.\n\n"
                . self::blocoChamado($chamado)
                . "\nTAREFA: escreva a próxima resposta da equipe pro cliente, coerente com a conversa. Não temos uma solução pronta "
                . "documentada pra esse caso: então NÃO invente solução técnica. Dê retorno do que já foi feito (se a conversa mostrar), "
                . "faça as perguntas objetivas que faltam pra resolver, ou diga que a equipe vai analisar/fazer e retornar "
                . "(ex.: pedido de desenvolvimento ou ajuste: confirme o que foi pedido e diga que vamos fazer e avisar).\n\n"
                . self::regrasDeEscrita($primeiroNome) . "\n\n"
                . self::REGRAS_STATUS . "\n\n"
                . 'Responda SOMENTE com JSON: {"resposta": "texto pro cliente", "status": "aguardando_equipe" ou "aguardando_cliente" ou "fechado"}',
            [],
            null,
            $imagens,
            ['maxOutputTokens' => self::MAX_TOKENS_RESPOSTA, 'temperature' => 0.4, 'responseMimeType' => 'application/json']
        );
        if (!$r['ok']) {
            self::resultado(self::erroCurto($r['erro']), 'erro');

            return ['status' => 'erro', 'erro' => $r['erro']];
        }

        $json = json_decode(self::extrairJson($r['texto']), true);
        $texto = trim((string) ($json['resposta'] ?? ''));
        if ($texto === '') {
            self::resultado('a IA não devolveu texto', 'erro');

            return ['status' => 'erro', 'erro' => 'A IA não devolveu a resposta no formato esperado.'];
        }

        // Sem solução documentada: no modo automático vai pro cliente também
        // (normalmente "vamos verificar e retornar" ou pedindo o que falta),
        // mas nunca fecha o chamado -- só aguardando nós/cliente.
        $statusConversa = self::statusEscolhido($json['status'] ?? '', 'aguardando_equipe');
        if ($statusConversa === 'fechado') {
            $statusConversa = 'aguardando_cliente';
        }

        return self::finalizar($pdo, $chamado, (bool) ($chamado['__manual'] ?? false), [
            'fonte' => 'conversa',
            'status_chamado' => $statusConversa,
            'resposta' => self::garantirNome(self::limparTexto($texto), $primeiroNome),
        ]);
    }

    /** Mensagens do chamado depois da abertura, em ordem. */
    private static function conversa(PDO $pdo, int $chamadoId): array
    {
        $stmt = $pdo->prepare("SELECT autor_tipo, autor_nome, mensagem, criado_em FROM chamado_respostas
            WHERE chamado_id = ? ORDER BY criado_em ASC, id ASC");
        $stmt->execute([$chamadoId]);

        // As 10 últimas bastam (chamado longo não pode estourar o custo).
        return array_slice($stmt->fetchAll(), -10);
    }

    /** Até 2 imagens anexadas no chamado, já enviadas pro Gemini. */
    private static function imagensDoChamado(PDO $pdo, int $chamadoId): array
    {
        $stmt = $pdo->prepare('SELECT id, arquivo_nome_original, arquivo_caminho, arquivo_tamanho FROM chamado_anexos
            WHERE chamado_id = ? AND arquivo_tamanho BETWEEN 1 AND 4194304 ORDER BY id DESC');
        $stmt->execute([$chamadoId]);

        $tipos = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $imagens = [];
        foreach ($stmt->fetchAll() as $anexo) {
            $mime = $tipos[strtolower(pathinfo($anexo['arquivo_nome_original'], PATHINFO_EXTENSION))] ?? null;
            if (!$mime) {
                continue;
            }
            $caminho = UPLOADS_DIR . '/' . $anexo['arquivo_caminho'];
            $bytes = is_file($caminho) ? file_get_contents($caminho) : null;
            if (!$bytes) {
                $dados = $pdo->prepare('SELECT dados FROM chamado_anexos WHERE id = ?');
                $dados->execute([$anexo['id']]);
                $bytes = $dados->fetchColumn() ?: null;
            }
            if ($bytes && ($enviado = AiSugestoes::enviarArquivoUmaVez($mime, $bytes, $anexo['arquivo_nome_original']))) {
                $imagens[] = $enviado;
            }
            if (count($imagens) >= 2) {
                break;
            }
        }

        return $imagens;
    }

    /**
     * 2-3 consultas curtas (ex.: "difal protheus") geradas pelo modelo barato
     * -- palavra-chave crua do chamado (F2_DIFAL, nº de nota) busca mal em site.
     */
    private static function consultasDeBusca(array $chamado, int &$tokensEntrada, int &$tokensSaida): array
    {
        $r = AiSugestoes::gerar(
            "Chamado de suporte TOTVS Protheus:\nTítulo: {$chamado['titulo']}\nDescrição: " . mb_substr($chamado['descricao'], 0, 1500)
            . "\n\nGere de 2 a 3 consultas de busca CURTAS (2 a 4 palavras cada, em português, sem nome de cliente nem número de documento) "
            . 'pra achar artigos sobre esse problema em sites de Protheus, da mais específica pra mais genérica. '
            . 'Responda só JSON: {"consultas": ["...", "..."]}',
            [],
            null,
            [],
            ['maxOutputTokens' => 150, 'temperature' => 0.2, 'responseMimeType' => 'application/json']
        );
        if (!$r['ok']) {
            self::$ultimoErro = $r['erro'];

            return [];
        }
        $tokensEntrada += $r['uso']['entrada'];
        $tokensSaida += $r['uso']['saida'];
        $json = json_decode(self::extrairJson($r['texto']), true);

        return array_slice(array_values(array_filter(array_map('trim', (array) ($json['consultas'] ?? [])), 'strlen')), 0, 3);
    }

    /** Até 3 artigos do User Function (API pública do WordPress do site). */
    private static function artigosUserFunction(array $consultas): array
    {
        $ids = [];
        foreach ($consultas as $consulta) {
            $resultados = json_decode((string) self::httpGet('https://userfunction.com.br/wp-json/wp/v2/search?per_page=3&subtype=post&search=' . rawurlencode($consulta)), true);
            foreach (is_array($resultados) ? $resultados : [] as $item) {
                if (!empty($item['id'])) {
                    $ids[(int) $item['id']] = true;
                }
            }
            if (count($ids) >= 3) {
                break;
            }
        }

        $artigos = [];
        foreach (array_slice(array_keys($ids), 0, 3) as $id) {
            $post = json_decode((string) self::httpGet("https://userfunction.com.br/wp-json/wp/v2/posts/{$id}?_fields=title,content,link"), true);
            if (empty($post['content']['rendered'])) {
                continue;
            }
            $texto = html_entity_decode(strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#si', '', $post['content']['rendered'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $artigos[] = [
                'titulo' => html_entity_decode(strip_tags($post['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => $post['link'],
                'texto' => mb_substr(trim(preg_replace('/\s+/u', ' ', $texto)), 0, 3500),
            ];
        }

        return $artigos;
    }

    private static function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'PrimeHub/1.0 (+https://www.primeerp.com.br)',
        ]);
        $corpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($corpo !== false && $codigo === 200) ? $corpo : null;
    }

    /**
     * Com resposta pronta: modo automático (e não manual) já posta no
     * chamado e avisa o cliente; senão fica só como sugestão pra equipe.
     */
    private static function finalizar(PDO $pdo, array $chamado, bool $manual, array $dados): array
    {
        $dados['status'] = 'sugerida';
        $statusChamado = self::statusEscolhido($dados['status_chamado'] ?? '');
        $dados['status_sugerido'] = $statusChamado;
        unset($dados['status_chamado']);

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

        // Status escolhido pela IA (mesmas opções do "Deixar como" da tela).
        if ($statusChamado === 'fechado') {
            $pdo->prepare("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ?")->execute([$chamado['id']]);
        } else {
            $pdo->prepare('UPDATE chamados SET status = ?, fechado_em = NULL WHERE id = ?')->execute([$statusChamado, $chamado['id']]);
        }

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
                // Igual à resposta manual: só pergunta no WhatsApp o que o
                // cliente quer fazer se o chamado NÃO foi encerrado agora.
                if (!empty($pessoa['telefone']) && $statusChamado !== 'fechado') {
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

    /**
     * Como a IA decide em que status o chamado fica depois da resposta --
     * mesmos 3 do "Deixar como" da tela de resposta.
     */
    private const REGRAS_STATUS = <<<TXT
ESCOLHA DO STATUS do chamado depois desta resposta (campo "status"):
- "aguardando_equipe": o próximo passo é NOSSO -- desenvolvimento, customização, ajuste de fonte/dicionário, liberar algo na base, análise na base do cliente, ou qualquer coisa que a equipe ainda precisa fazer. A resposta deve dizer de forma natural que vamos verificar/fazer e retornar.
- "aguardando_cliente": o próximo passo é do CLIENTE -- seguir os passos que você passou, testar/validar, mandar print/informação que falta, confirmar se resolveu.
- "fechado": SÓ quando a resposta resolve de fato e por completo (ex.: dúvida simples totalmente respondida) e não depende de nenhuma ação, teste ou confirmação do cliente. Nesse caso termine dizendo, de forma natural, que estamos encerrando o chamado e que se precisar é só responder por aqui. Na dúvida, NÃO feche: use "aguardando_cliente".
TXT;

    private const STATUS_CHAMADO_VALIDOS = ['aguardando_equipe', 'aguardando_cliente', 'fechado'];

    public static function rotuloStatus(?string $status): string
    {
        return ['aguardando_equipe' => 'aguardando nós', 'aguardando_cliente' => 'aguardando cliente', 'fechado' => 'fechado'][$status] ?? 'aguardando cliente';
    }

    /** Status que a IA escolheu (ou o mais seguro, se veio algo estranho). */
    private static function statusEscolhido($valor, string $padrao = 'aguardando_cliente'): string
    {
        $valor = strtolower(trim((string) $valor));

        return in_array($valor, self::STATUS_CHAMADO_VALIDOS, true) ? $valor : $padrao;
    }

    private static function regrasDeEscrita(string $primeiroNome): string
    {
        $saudacao = $primeiroNome !== ''
            ? "OBRIGATÓRIO: a primeira frase cumprimenta {$primeiroNome} pelo nome, de um jeito simples (ex.: \"Oi, {$primeiroNome}, tudo bem?\" ou \"Olá, {$primeiroNome}!\")."
            : 'Comece com um cumprimento simples (ex.: "Olá, tudo bem?").';

        return <<<TXT
COMO ESCREVER A RESPOSTA (muito importante):
- Você escreve como um consultor Protheus da PrimeERP falando com o cliente: português do Brasil, cordial, direto e natural, como uma pessoa de verdade escreveria no chamado.
- {$saudacao}
- Nunca diga que é IA, robô, assistente virtual ou modelo. Não fale em "base de dados", "chamados anteriores", "outros clientes", "pesquisei" ou "encontrei na internet".
- Nunca cite nome de outro cliente nem dados de outro chamado. Não copie respostas antigas palavra por palavra: escreva de novo, para ESTE chamado (mencione o que o cliente relatou, ex.: número da nota, rotina, mensagem de erro).
- Texto puro: sem markdown, sem asteriscos, sem #, sem tabelas, sem emojis. Se precisar de passo a passo, use linhas "1)", "2)", "3)".
- Seja curto: no máximo uns 170 palavras. Nada de introdução longa nem despedida formal; não assine (o nome já aparece no chamado).
- Use os nomes certos de rotina, parâmetro (MV_...), tabela e campo, mas NUNCA invente um. Se faltar informação pra ter certeza, peça de forma objetiva (ex.: print do erro, rotina, filial, exemplo de documento).
- Não prometa prazo. Se a solução exigir ajuste em fonte/customização, diga que a equipe vai verificar e retornar.
- Você fala EM NOME da equipe: mensagens marcadas "Equipe" na conversa foram escritas por nós. Fale em primeira pessoa ("eu", "nós", "já validamos"), nunca da equipe ou de um colega em terceira pessoa (nada de "o Kleber fez").
TXT;
    }

    private static function blocoChamado(array $chamado): string
    {
        $texto = "CHAMADO\n"
            . 'Cliente: ' . ($chamado['cliente_nome'] ?: '-') . "\n"
            . 'Título: ' . $chamado['titulo'] . "\n"
            . 'Categoria: ' . ($chamado['categoria'] ?: '-') . "\n"
            . "Descrição (abertura):\n" . mb_substr($chamado['descricao'], 0, 3000) . "\n";

        if (!empty($chamado['conversa'])) {
            $texto .= "\nCONVERSA ATÉ AGORA (a resposta que você vai escrever é a PRÓXIMA mensagem da equipe, então leve tudo isso em conta "
                . "e não repita o que já foi dito):\n";
            foreach ($chamado['conversa'] as $m) {
                $quem = $m['autor_tipo'] === 'equipe' ? 'Equipe (' . $m['autor_nome'] . ')' : 'Cliente (' . $m['autor_nome'] . ')';
                $texto .= '- ' . date('d/m/Y H:i', strtotime($m['criado_em'])) . " {$quem}: " . mb_substr($m['mensagem'], 0, 800) . "\n";
            }
        }

        return $texto . "(Imagens anexadas ao chamado, se houver, vêm junto -- leia o que aparece nelas.)\n";
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
            . self::REGRAS_STATUS . "\n\n"
            . 'Responda SOMENTE com JSON neste formato: {"resolve": true ou false, "resposta": "texto pro cliente (vazio se resolve=false)", "status": "aguardando_equipe" ou "aguardando_cliente" ou "fechado", "referencias": [ids dos chamados de referência usados]}';
    }

    private static function promptArtigos(array $chamado, array $artigos, string $primeiroNome): string
    {
        $blocos = '';
        foreach ($artigos as $i => $a) {
            $blocos .= "\n--- Artigo " . ($i + 1) . ": {$a['titulo']} ---\n{$a['texto']}\n";
        }

        return "Você é consultor de TOTVS Protheus na PrimeERP. Chegou o chamado abaixo. Separei artigos do site User Function "
            . "(referência técnica de Protheus) que podem ajudar.\n\n"
            . self::blocoChamado($chamado)
            . "\nARTIGOS:" . $blocos . "\n\n"
            . "TAREFA: decida se algum artigo realmente orienta a solução DESTE problema (tema parecido não basta). "
            . "Se sim, escreva a resposta pro cliente baseada SÓ no que os artigos dizem.\n\n"
            . self::regrasDeEscrita($primeiroNome) . "\n\n"
            . self::REGRAS_STATUS . "\n\n"
            . 'Responda SOMENTE com JSON: {"resolve": true ou false, "resposta": "texto pro cliente (vazio se resolve=false)", "status": "aguardando_equipe" ou "aguardando_cliente" ou "fechado", "artigos": [números dos artigos usados]}';
    }

    private static function promptBusca(array $chamado, string $primeiroNome, array $consultas = []): string
    {
        $sugestoes = $consultas ? ' Sugestões de termos: ' . implode('; ', $consultas) . '.' : '';

        return "Você é consultor de TOTVS Protheus na PrimeERP. Chegou o chamado abaixo e precisamos de uma orientação técnica confiável.\n\n"
            . self::blocoChamado($chamado)
            . "\nPrimeiro PESQUISE com a busca do Google na documentação oficial da TOTVS: consultas com site:tdn.totvs.com "
            . "(e, se precisar, site:centraldeatendimento.totvs.com).{$sugestoes} Faça de 2 a 4 pesquisas curtas antes de concluir. "
            . "Use só o que encontrar nessas páginas da TOTVS; não use outros sites e nunca invente rotina, parâmetro ou campo.\n\n"
            . self::regrasDeEscrita($primeiroNome) . "\n\n"
            . self::REGRAS_STATUS . "\n\n"
            . "SAÍDA: escreva a resposta pro cliente e, numa ÚLTIMA linha separada, exatamente STATUS: seguido de aguardando_equipe, "
            . "aguardando_cliente ou fechado. Se depois de pesquisar não achar nada que realmente se aplique, escreva apenas a palavra NAO_ENCONTRADO.";
    }

    // --- Utilitários -------------------------------------------------------------

    private static function primeiroNomeContato(PDO $pdo, array $chamado): string
    {
        // Quem escreveu por último do lado do cliente (é com essa pessoa que
        // estamos falando agora), senão quem abriu o chamado.
        foreach (array_reverse($chamado['conversa'] ?? []) as $mensagem) {
            if ($mensagem['autor_tipo'] === 'cliente' && trim((string) $mensagem['autor_nome']) !== '') {
                return self::formatarPrimeiroNome($mensagem['autor_nome']);
            }
        }
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
            return self::formatarPrimeiroNome($nome);
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

    private static function formatarPrimeiroNome(string $nome): string
    {
        $primeiro = explode(' ', trim($nome))[0];

        return mb_convert_case(mb_strtolower($primeiro, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /** Se o modelo esqueceu o nome no cumprimento ("Oi, tudo bem?"), põe. */
    private static function garantirNome(string $texto, string $primeiroNome): string
    {
        if ($primeiroNome === '' || mb_stripos(mb_substr($texto, 0, 80), $primeiroNome) !== false) {
            return $texto;
        }
        // Já cumprimenta alguém pelo nome ("Oi, Maico, ...")? Não mexe.
        if (preg_match('/^(Oi|Olá|Ola|Bom dia|Boa tarde|Boa noite)[,!]?\s+\p{Lu}\p{Ll}+/u', $texto)) {
            return $texto;
        }

        return preg_replace_callback('/^(Oi|Olá|Ola|Bom dia|Boa tarde|Boa noite)\b[,!.]?\s*(\S?)/u', function ($m) use ($primeiroNome) {
            // "Oi, tudo bem?" -> "Oi, Mariana, tudo bem?"; "Olá! Essa..." -> "Olá, Mariana! Essa..."
            $continuaMinusculo = $m[2] !== '' && mb_strtolower($m[2]) === $m[2] && mb_strtoupper($m[2]) !== $m[2];

            return $m[1] . ', ' . $primeiroNome . ($continuaMinusculo ? ', ' : '! ') . $m[2];
        }, $texto, 1) ?? $texto;
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
