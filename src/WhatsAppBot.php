<?php

namespace AdminPrime;

use PDO;

/**
 * Bot de MENU GUIADO pra abrir chamado direto pelo WhatsApp -- pedido do
 * Kleber: o cliente chama ele no Zap pessoal, ele manda a pessoa falar com
 * esse número (o "atendente"), o bot recolhe título/categoria/prioridade/
 * descrição/anexos com perguntas numeradas (sem IA de conversa livre por
 * enquanto -- fica pra uma v2) e cria o chamado igualzinho a um aberto pelo
 * portal, já com os prints anexados e o cliente certo identificado pelo
 * telefone.
 *
 * Cada telefone tem UMA conversa em andamento por vez (whatsapp_conversas).
 * Cada resposta do cliente avança pra próxima etapa; qualquer coisa fora do
 * esperado repete a pergunta atual em vez de travar o fluxo.
 */
class WhatsAppBot
{
    private const PRIORIDADES = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];

    public static function processarTexto(PDO $pdo, string $telefone, string $nomePerfil, string $texto): void
    {
        $texto = trim($texto);
        $conversa = self::obterOuCriarConversa($pdo, $telefone, $nomePerfil);

        if (self::ehComandoCancelar($texto)) {
            self::excluirConversa($pdo, $conversa['id']);
            WhatsAppApi::enviarTexto($telefone, '🚫 Cancelado! Quando quiser abrir um chamado, é só mandar mensagem de novo. 🙂');
            return;
        }

        match ($conversa['etapa']) {
            'identificando' => self::etapaIdentificar($pdo, $conversa),
            'aguardando_identificacao_manual' => self::etapaIdentificacaoManual($pdo, $conversa, $texto),
            'escolher_setor' => self::etapaEscolherSetor($pdo, $conversa, $texto),
            'titulo' => self::etapaTitulo($pdo, $conversa, $texto),
            'categoria' => self::etapaCategoria($pdo, $conversa, $texto),
            'prioridade' => self::etapaPrioridade($pdo, $conversa, $texto),
            'descricao' => self::etapaDescricao($pdo, $conversa, $texto),
            'anexos' => self::etapaAnexos($pdo, $conversa, $texto),
            'confirmacao' => self::etapaConfirmacao($pdo, $conversa, $texto),
            default => self::etapaIdentificar($pdo, $conversa),
        };
    }

    public static function processarMidia(PDO $pdo, string $telefone, string $nomePerfil, string $mediaId, string $mimeType, ?string $nomeArquivo): void
    {
        $conversa = self::obterOuCriarConversa($pdo, $telefone, $nomePerfil);

        if ($conversa['etapa'] !== 'anexos') {
            // Mídia chegando fora da hora certa (ex.: antes de identificar o
            // cliente) -- avisa e não guarda, pra não ficar um anexo "solto"
            // sem saber de qual chamado é.
            WhatsAppApi::enviarTexto($telefone, '📌 Recebi o arquivo, mas ainda preciso de mais algumas informações antes. Vou te perguntar de novo o que falta.');
            self::reenviarPerguntaAtual($conversa);
            return;
        }

        $midia = WhatsAppApi::baixarMidia($mediaId);
        if (!$midia) {
            WhatsAppApi::enviarTexto($telefone, '😕 Não consegui baixar esse arquivo agora, pode tentar mandar de novo?');
            return;
        }

        $extensaoPermitida = ANEXO_CHAMADO_TIPOS_PERMITIDOS[$midia['mime']] ?? null;
        if (!$extensaoPermitida) {
            WhatsAppApi::enviarBotoes($telefone, '🚫 Esse tipo de arquivo eu não consigo anexar (só imagem ou PDF). Pode mandar outro, ou tocar no botão pra continuar sem anexo.', [
                ['id' => 'nao', 'titulo' => '✅ Continuar'],
            ]);
            return;
        }

        $pdo->prepare('INSERT INTO whatsapp_midias_temp (conversa_id, arquivo_nome_original, mime_type, dados) VALUES (?, ?, ?, ?)')
            ->execute([$conversa['id'], $nomeArquivo ?: ('anexo.' . $extensaoPermitida), $midia['mime'], $midia['bytes']]);

        WhatsAppApi::enviarBotoes($telefone, '✅ Recebido! Pode mandar mais algum print/arquivo, ou tocar no botão quando terminar.', [
            ['id' => 'nao', 'titulo' => '✅ Continuar'],
        ]);
    }

    // --- Etapas ------------------------------------------------------------

    private static function etapaIdentificar(PDO $pdo, array $conversa): void
    {
        $identificacao = self::identificarPorTelefone($pdo, $conversa['telefone']);

        if (!$identificacao) {
            self::atualizarConversa($pdo, $conversa['id'], ['etapa' => 'aguardando_identificacao_manual']);
            WhatsAppApi::enviarTexto($conversa['telefone'], '👋 Oi! Aqui é o atendimento da PrimeERP. Não encontrei seu número no nosso cadastro -- pode me dizer o nome da sua empresa?');
            return;
        }

        if ($identificacao['bloqueado']) {
            // Pessoa específica com acesso ao bot bloqueado (aba Usuários,
            // ver cliente.php) -- não trava o cadastro inteiro, só impede
            // ESSA pessoa de abrir chamado por aqui. Time interno é avisado
            // pra saber que alguém tentou mesmo bloqueado.
            $quem = $identificacao['nome_pessoa'] ?? $conversa['nome_perfil'] ?: $conversa['telefone'];
            Notificacoes::whatsapp(null, "🚫 WhatsApp: {$quem} ({$conversa['telefone']}), de {$identificacao['nome_cliente']}, tentou abrir chamado mas está com o acesso bloqueado.");
            WhatsAppApi::enviarTexto($conversa['telefone'], '🚫 Seu acesso a abrir chamados por aqui está temporariamente bloqueado. Fale com sua empresa ou com a PrimeERP por outro canal.');
            self::excluirConversa($pdo, $conversa['id']);
            return;
        }

        self::avancarAposIdentificar(
            $pdo,
            $conversa,
            $identificacao['cliente_id'],
            $identificacao['nome_cliente'],
            $identificacao['nome_pessoa'],
            $identificacao['setor_responsavel_id'],
            $identificacao['setor_id'],
            $identificacao['nome_setor']
        );
    }

    /**
     * Depois de saber QUEM é o cliente (batendo telefone, ou pelo nome
     * digitado na identificação manual) -- decide se precisa perguntar o
     * setor ou já pode ir direto pro título. Ponto único pra essa decisão,
     * chamado tanto pelo caminho automático (telefone) quanto manual (nome
     * da empresa digitado). $nomePessoa só vem preenchido no caminho
     * automático (achou o CADASTRO da pessoa, não só da empresa) -- usado
     * pra chamar pelo nome em vez de só "encontrei seu cadastro".
     */
    private static function avancarAposIdentificar(PDO $pdo, array $conversa, int $clienteId, string $nomeCliente, ?string $nomePessoa, ?int $setorResponsavelId, ?int $setorId, ?string $nomeSetor): void
    {
        $dados = ['cliente_id' => $clienteId, 'setor_responsavel_id' => $setorResponsavelId, 'nome_pessoa' => $nomePessoa];
        $saudacao = $nomePessoa ? ('Oi, ' . self::primeiroNome($nomePessoa) . '! 👋 ') : '👋 ';

        if ($setorResponsavelId) {
            // Bateu com o telefone de um responsável de setor específico --
            // igual ao login de setor no portal, já nasce vinculado a esse
            // setor, sem precisar perguntar.
            $dados['setor_id'] = $setorId;
            $dados['etapa'] = 'titulo';
            self::atualizarConversa($pdo, $conversa['id'], $dados);
            WhatsAppApi::enviarTexto($conversa['telefone'], "{$saudacao}Encontrei seu cadastro: *{$nomeCliente}* ({$nomeSetor}). ✅ Vamos abrir seu chamado.\n\n📝 Resuma em poucas palavras o que está acontecendo (isso vai virar o título do chamado):");
            return;
        }

        // Bateu direto com o telefone do MASTER (admin do cliente, vê e pode
        // abrir chamado em QUALQUER setor, não só no dele) -- continua
        // perguntando qual setor é o assunto, mas destaca o setor padrão
        // dele (clientes.setor_id, ver aba Usuários) na lista, pra ele só
        // confirmar quando for esse o caso, sem perder a opção de escolher
        // outro.
        $setoresDisponiveis = self::setoresDisponiveis($pdo, $clienteId);
        if ($setoresDisponiveis) {
            $dados['etapa'] = 'escolher_setor';
            self::atualizarConversa($pdo, $conversa['id'], $dados);
            $stmtSetorPadraoMaster = $pdo->prepare('SELECT setor_id FROM clientes WHERE id = ?');
            $stmtSetorPadraoMaster->execute([$clienteId]);
            $setorPadraoMasterId = (int) $stmtSetorPadraoMaster->fetchColumn();
            $linhas = [];
            foreach (array_values($setoresDisponiveis) as $i => $s) {
                $destaque = (int) $s['id'] === $setorPadraoMasterId ? '⭐ ' : '';
                $linhas[] = ['id' => (string) ($i + 1), 'titulo' => $destaque . $s['nome']];
            }
            WhatsAppApi::enviarLista($conversa['telefone'], "{$saudacao}Encontrei seu cadastro: *{$nomeCliente}*. 🗂️ Qual setor é o assunto?", 'Escolher setor', $linhas);
            return;
        }

        $dados['etapa'] = 'titulo';
        self::atualizarConversa($pdo, $conversa['id'], $dados);
        WhatsAppApi::enviarTexto($conversa['telefone'], "{$saudacao}Encontrei seu cadastro: *{$nomeCliente}*. ✅ Vamos abrir seu chamado.\n\n📝 Resuma em poucas palavras o que está acontecendo (isso vai virar o título do chamado):");
    }

    private static function etapaIdentificacaoManual(PDO $pdo, array $conversa, string $texto): void
    {
        $stmt = $pdo->prepare("SELECT id, nome FROM clientes WHERE status = 'ativo' AND nome LIKE ?");
        $stmt->execute(['%' . $texto . '%']);
        $encontrados = $stmt->fetchAll();

        if (count($encontrados) === 1) {
            self::avancarAposIdentificar($pdo, $conversa, (int) $encontrados[0]['id'], $encontrados[0]['nome'], null, null, null, null);
            return;
        }

        // Não achou (ou achou mais de um, ambíguo) -- essa é uma limitação
        // conhecida da v1: não existe ainda uma fila de triagem pra chamado
        // sem cliente identificado (só o e-mail/WhatsApp avisando a equipe
        // pra tratar manualmente por fora do sistema).
        Notificacoes::whatsapp(null, "⚠️ WhatsApp: {$conversa['nome_perfil']} ({$conversa['telefone']}) tentou abrir chamado mas eu não consegui identificar a empresa (\"$texto\"). Precisa de triagem manual.");
        WhatsAppApi::enviarTexto($conversa['telefone'], '😕 Não consegui confirmar seu cadastro por aqui, mas já avisei nossa equipe -- alguém vai te chamar em breve. 🙂');
        self::excluirConversa($pdo, $conversa['id']);
    }

    private static function etapaEscolherSetor(PDO $pdo, array $conversa, string $texto): void
    {
        $setores = self::setoresDisponiveis($pdo, $conversa['cliente_id']);
        $escolha = self::interpretarEscolhaNumerada($texto, $setores);

        if (!$escolha) {
            $linhas = [];
            foreach (array_values($setores) as $i => $s) {
                $linhas[] = ['id' => (string) ($i + 1), 'titulo' => $s['nome']];
            }
            WhatsAppApi::enviarLista($conversa['telefone'], '🤔 Não entendi. Qual setor é o assunto?', 'Escolher setor', $linhas);
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['setor_id' => $escolha['id'], 'etapa' => 'titulo']);
        WhatsAppApi::enviarTexto($conversa['telefone'], "✅ Certo, {$escolha['nome']}.\n\n📝 Resuma em poucas palavras o que está acontecendo (isso vai virar o título do chamado):");
    }

    private static function etapaTitulo(PDO $pdo, array $conversa, string $texto): void
    {
        if ($texto === '' || self::pareceSaudacaoOuMuitoCurto($texto)) {
            // Pega gente que ainda está só "cumprimentando" o atendimento (ou
            // que respondeu de qualquer jeito sem reparar na pergunta) --
            // sem essa checagem, "oi" virava o título do chamado de verdade
            // (aconteceu no primeiro teste real, ver histórico).
            WhatsAppApi::enviarTexto($conversa['telefone'], '🤔 Isso parece só uma saudação. Me conta rapidinho qual é o problema -- isso vai virar o título do chamado:');
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['titulo' => $texto, 'etapa' => 'categoria']);
        WhatsAppApi::enviarLista($conversa['telefone'], '🏷️ Qual a categoria do problema?', 'Escolher', self::linhasCategoria());
    }

    private static function etapaCategoria(PDO $pdo, array $conversa, string $texto): void
    {
        $indice = (int) $texto - 1;
        if ($texto === '' || !ctype_digit(trim($texto)) || !isset(CATEGORIAS_CHAMADO[$indice])) {
            WhatsAppApi::enviarLista($conversa['telefone'], '🤔 Não entendi. Qual a categoria do problema?', 'Escolher', self::linhasCategoria());
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['categoria' => CATEGORIAS_CHAMADO[$indice], 'etapa' => 'prioridade']);
        WhatsAppApi::enviarLista($conversa['telefone'], '📶 E a prioridade?', 'Escolher', self::linhasPrioridade());
    }

    private static function etapaPrioridade(PDO $pdo, array $conversa, string $texto): void
    {
        $chaves = array_keys(self::PRIORIDADES);
        $indice = (int) $texto - 1;
        if ($texto === '' || !ctype_digit(trim($texto)) || !isset($chaves[$indice])) {
            WhatsAppApi::enviarLista($conversa['telefone'], '🤔 Não entendi. E a prioridade?', 'Escolher', self::linhasPrioridade());
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['prioridade' => $chaves[$indice], 'etapa' => 'descricao']);
        WhatsAppApi::enviarTexto($conversa['telefone'], '📄 Agora descreve o problema com o máximo de detalhe que puder.');
    }

    private static function etapaDescricao(PDO $pdo, array $conversa, string $texto): void
    {
        if ($texto === '' || self::pareceSaudacaoOuMuitoCurto($texto)) {
            WhatsAppApi::enviarTexto($conversa['telefone'], '🤔 Preciso de uma descrição um pouco melhor do problema, pode escrever com suas palavras mesmo.');
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['descricao' => $texto, 'etapa' => 'anexos']);
        WhatsAppApi::enviarBotoes($conversa['telefone'], '📎 Quer mandar algum print ou arquivo? Pode enviar agora (foto ou PDF), ou tocar no botão pra continuar sem anexo.', [
            ['id' => 'nao', 'titulo' => '🚫 Sem anexo'],
        ]);
    }

    private static function etapaAnexos(PDO $pdo, array $conversa, string $texto): void
    {
        if (!self::ehResposta($texto, ['nao', 'não', 'pronto', 'pular', 'continuar'])) {
            WhatsAppApi::enviarBotoes($conversa['telefone'], '📎 Pode mandar o print/arquivo, ou tocar no botão pra continuar sem anexo.', [
                ['id' => 'nao', 'titulo' => '✅ Continuar'],
            ]);
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['etapa' => 'confirmacao']);
        $conversa = self::obterOuCriarConversa($pdo, $conversa['telefone'], $conversa['nome_perfil']);
        $qtdAnexos = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_midias_temp WHERE conversa_id = ' . (int) $conversa['id'])->fetchColumn();

        $resumo = "🔎 Confere se está tudo certo:\n\n"
            . "📝 *Título:* {$conversa['titulo']}\n"
            . "🏷️ *Categoria:* {$conversa['categoria']}\n"
            . '📶 *Prioridade:* ' . self::PRIORIDADES[$conversa['prioridade']] . "\n"
            . "📄 *Descrição:* {$conversa['descricao']}\n"
            . ($qtdAnexos > 0 ? "📎 *Anexos:* $qtdAnexos arquivo(s)\n" : '')
            . "\nPosso abrir o chamado?";
        WhatsAppApi::enviarBotoes($conversa['telefone'], $resumo, [
            ['id' => 'sim', 'titulo' => '✅ Sim, abrir'],
            ['id' => 'nao', 'titulo' => '✏️ Corrigir'],
        ]);
    }

    private static function etapaConfirmacao(PDO $pdo, array $conversa, string $texto): void
    {
        if (self::ehResposta($texto, ['sim', 's', 'confirmar', 'confirmo', 'pode'])) {
            self::criarChamado($pdo, $conversa);
            return;
        }
        if (self::ehResposta($texto, ['nao', 'não', 'n'])) {
            self::atualizarConversa($pdo, $conversa['id'], ['etapa' => 'titulo', 'titulo' => null, 'categoria' => null, 'prioridade' => null, 'descricao' => null]);
            $pdo->prepare('DELETE FROM whatsapp_midias_temp WHERE conversa_id = ?')->execute([$conversa['id']]);
            WhatsAppApi::enviarTexto($conversa['telefone'], '🔄 Sem problema, vamos refazer. Resuma em poucas palavras o que está acontecendo:');
            return;
        }
        WhatsAppApi::enviarTexto($conversa['telefone'], 'Toca em um dos botões acima 👆, ou responda "sim" pra abrir o chamado, ou "não" pra corrigir.');
    }

    // --- Criação do chamado --------------------------------------------------

    private static function criarChamado(PDO $pdo, array $conversa): void
    {
        $tituloFinal = mb_strtoupper($conversa['titulo'], 'UTF-8');

        $stmt = $pdo->prepare('
            INSERT INTO chamados
                (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, aberto_por_tipo, aberto_por_setor_responsavel_id, origem_canal, setor_id)
            VALUES (?, ?, ?, ?, ?, "cliente", ?, ?, "whatsapp", ?)
        ');
        $stmt->execute([
            $conversa['cliente_id'],
            $tituloFinal,
            $conversa['descricao'],
            $conversa['categoria'],
            $conversa['prioridade'],
            $conversa['setor_responsavel_id'] ? 'setor' : 'master',
            $conversa['setor_responsavel_id'],
            $conversa['setor_id'],
        ]);
        $chamadoId = (int) $pdo->lastInsertId();

        $midias = $pdo->prepare('SELECT * FROM whatsapp_midias_temp WHERE conversa_id = ?');
        $midias->execute([$conversa['id']]);
        foreach ($midias->fetchAll() as $m) {
            salvar_bytes_como_anexo_chamado($pdo, $chamadoId, null, 'cliente', $m['arquivo_nome_original'] ?: 'anexo', $m['mime_type'] ?: 'application/octet-stream', $m['dados']);
        }

        $stmtCliente = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $stmtCliente->execute([$conversa['cliente_id']]);
        $cliente = $stmtCliente->fetch();

        self::excluirConversa($pdo, $conversa['id']);

        $saudacao = $conversa['nome_pessoa'] ? ('Prontinho, ' . self::primeiroNome($conversa['nome_pessoa']) . '!') : 'Prontinho!';
        WhatsAppApi::enviarTexto($conversa['telefone'], "✅ {$saudacao} Chamado #$chamadoId aberto com sucesso. 🙌 Nossa equipe já foi avisada e vai te responder por aqui.");

        // Mesmo aviso (e-mail pra equipe + WhatsApp pro Kleber, via
        // Notificacoes::whatsapp()) de qualquer outro chamado novo -- não
        // precisa de nada especial só porque veio do WhatsApp.
        Notificacoes::novoChamado(['id' => $chamadoId, 'titulo' => $tituloFinal, 'prioridade' => $conversa['prioridade'], 'descricao' => $conversa['descricao']], $cliente);
    }

    // --- Identificação por telefone -----------------------------------------

    private static function identificarPorTelefone(PDO $pdo, string $telefone): ?array
    {
        // telefones_correspondem() é PHP puro (não dá pra comparar direto no
        // SQL por causa das variações de DDI/9º dígito) -- por isso busca
        // todo mundo com telefone cadastrado e compara linha a linha aqui.

        // Primeiro tenta bater com o telefone de um responsável de setor
        // específico (mais preciso: já sabe exatamente quem é a pessoa).
        $stmtResp = $pdo->query("SELECT sr.id AS setor_responsavel_id, sr.nome, sr.telefone, sr.setor_id, sr.bloqueado_whatsapp, s.nome AS nome_setor, s.cliente_id, c.nome AS nome_cliente
            FROM setor_responsaveis sr
            JOIN setores s ON s.id = sr.setor_id
            JOIN clientes c ON c.id = s.cliente_id
            WHERE s.ativo = 1 AND c.status = 'ativo' AND sr.telefone IS NOT NULL");
        foreach ($stmtResp->fetchAll() as $linha) {
            if (telefones_correspondem($telefone, $linha['telefone'])) {
                return [
                    'cliente_id' => (int) $linha['cliente_id'],
                    'setor_id' => (int) $linha['setor_id'],
                    'setor_responsavel_id' => (int) $linha['setor_responsavel_id'],
                    'nome_cliente' => $linha['nome_cliente'],
                    'nome_setor' => $linha['nome_setor'],
                    'nome_pessoa' => $linha['nome'],
                    'bloqueado' => (bool) $linha['bloqueado_whatsapp'],
                ];
            }
        }

        // Senão, tenta o telefone de contato do MASTER.
        $stmtCliente = $pdo->query("SELECT id, nome, contato_nome, contato_telefone, bloqueado_whatsapp FROM clientes WHERE status = 'ativo' AND contato_telefone IS NOT NULL");
        foreach ($stmtCliente->fetchAll() as $linha) {
            if (telefones_correspondem($telefone, $linha['contato_telefone'])) {
                return [
                    'cliente_id' => (int) $linha['id'],
                    'setor_id' => null,
                    'setor_responsavel_id' => null,
                    'nome_cliente' => $linha['nome'],
                    'nome_setor' => null,
                    'nome_pessoa' => $linha['contato_nome'] ?: null,
                    'bloqueado' => (bool) $linha['bloqueado_whatsapp'],
                ];
            }
        }

        return null;
    }

    /** Setores com pelo menos 1 responsável relacionado ou que sejam o setor do master. */
    private static function setoresDisponiveis(PDO $pdo, int $clienteId): array
    {
        $stmt = $pdo->prepare("
            SELECT s.id, s.nome
            FROM setores s
            INNER JOIN clientes c ON c.id = s.cliente_id
            WHERE s.cliente_id = ? AND s.ativo = 1
              AND (c.setor_id = s.id OR EXISTS(SELECT 1 FROM setor_responsaveis sr WHERE sr.setor_id = s.id))
            ORDER BY s.nome ASC
        ");
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    // --- Auxiliares de conversa ----------------------------------------------

    private static function obterOuCriarConversa(PDO $pdo, string $telefone, string $nomePerfil): array
    {
        $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversas WHERE telefone = ?');
        $stmt->execute([$telefone]);
        $conversa = $stmt->fetch();

        if ($conversa) {
            return $conversa;
        }

        $pdo->prepare('INSERT INTO whatsapp_conversas (telefone, nome_perfil) VALUES (?, ?)')->execute([$telefone, $nomePerfil]);
        $stmt->execute([$telefone]);
        return $stmt->fetch();
    }

    private static function atualizarConversa(PDO $pdo, int $conversaId, array $campos): void
    {
        $colunas = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($campos)));
        $pdo->prepare("UPDATE whatsapp_conversas SET $colunas WHERE id = ?")->execute([...array_values($campos), $conversaId]);
    }

    private static function excluirConversa(PDO $pdo, int $conversaId): void
    {
        $pdo->prepare('DELETE FROM whatsapp_conversas WHERE id = ?')->execute([$conversaId]); // cascade limpa whatsapp_midias_temp
    }

    private static function reenviarPerguntaAtual(array $conversa): void
    {
        // Repete só um aviso genérico -- as perguntas de cada etapa já são
        // reenviadas naturalmente na próxima mensagem de texto que chegar.
        WhatsAppApi::enviarTexto($conversa['telefone'], 'Pode responder minha última pergunta quando puder. 🙂');
    }

    private static function ehComandoCancelar(string $texto): bool
    {
        return in_array(mb_strtolower($texto), ['cancelar', 'cancela', 'reiniciar'], true);
    }

    /**
     * Filtra saudação solta ("oi", "bom dia"...) ou resposta curta demais pra
     * virar título/descrição de chamado -- sem isso, uma saudação digitada
     * na hora errada (ex.: repetindo o "oi" inicial sem reparar que já
     * virou outra pergunta) vira literalmente o título do chamado.
     */
    private static function pareceSaudacaoOuMuitoCurto(string $texto): bool
    {
        $normalizado = mb_strtolower(trim($texto), 'UTF-8');
        if (mb_strlen($normalizado, 'UTF-8') < 4) {
            return true;
        }

        $saudacoes = [
            'oi', 'ola', 'olá', 'opa', 'eae', 'e ai', 'e aí', 'oii', 'oie',
            'bom dia', 'boa tarde', 'boa noite', 'tudo bem', 'tudo bom',
            'blz', 'beleza', 'hello', 'hi', 'alo', 'alô',
        ];
        return in_array($normalizado, $saudacoes, true);
    }

    private static function ehResposta(string $texto, array $opcoes): bool
    {
        return in_array(mb_strtolower(trim($texto)), $opcoes, true);
    }

    /**
     * Emoji por categoria, mesma ordem/índice de CATEGORIAS_CHAMADO
     * (config.php) -- lista tem que ficar em no máximo 10 itens (limite de
     * linhas de uma lista interativa do WhatsApp).
     */
    private const EMOJIS_CATEGORIA = ['📋', '🛒', '📦', '🧾', '💰', '👥', '⏰', '🧮', '📑', '💻'];

    private static function linhasCategoria(): array
    {
        $linhas = [];
        foreach (array_values(CATEGORIAS_CHAMADO) as $i => $nome) {
            $emoji = self::EMOJIS_CATEGORIA[$i] ?? '▫️';
            $linhas[] = ['id' => (string) ($i + 1), 'titulo' => "$emoji $nome"];
        }
        return $linhas;
    }

    private static function linhasPrioridade(): array
    {
        return [
            ['id' => '1', 'titulo' => '🟢 Baixa'],
            ['id' => '2', 'titulo' => '🟡 Média'],
            ['id' => '3', 'titulo' => '🟠 Alta'],
            ['id' => '4', 'titulo' => '🔴 Urgente'],
        ];
    }

    private static function primeiroNome(string $nomeCompleto): string
    {
        $partes = explode(' ', trim($nomeCompleto));
        return $partes[0] !== '' ? $partes[0] : $nomeCompleto;
    }

    private static function interpretarEscolhaNumerada(string $texto, array $itens): ?array
    {
        $indice = (int) trim($texto) - 1;
        return $itens[$indice] ?? null;
    }
}
