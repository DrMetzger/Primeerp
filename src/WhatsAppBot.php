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

    // Anti-spam: depois de tantas tentativas de identificação sem sucesso
    // numa mesma conversa, o número fica sem resposta do bot por um tempo
    // (ver etapaIdentificacaoManual()) -- evita alguém sem cadastro nenhum
    // ficar mandando lixo indefinidamente e o bot sempre respondendo.
    private const MAX_TENTATIVAS_IDENTIFICACAO = 3;
    private const MINUTOS_BLOQUEIO_SPAM = 30;

    // Não repete o aviso de "fora do horário" pra mesma pessoa insistindo
    // várias vezes seguidas dentro da mesma janela fora do expediente.
    private const HORAS_ENTRE_AVISOS_HORARIO = 3;

    // Conversa "esquecida" (a pessoa começou a abrir um chamado -- às vezes
    // já tinha digitado até o título -- e nunca respondeu de novo, nem
    // cancelou) fica pra sempre com etapa/título/categoria/setor da
    // tentativa antiga, já que nada aqui apaga a conversa sozinho enquanto
    // ela não chega no fim (chamado criado) ou é cancelada. Bug real: dias
    // depois, a mesma pessoa manda mensagem de novo pra um assunto TOTALMENTE
    // diferente, e esses campos antigos "vazam" pro chamado novo (setor
    // errado, ou até o título de outro chamado). Passado esse tempo sem
    // nenhuma resposta, trata a conversa como abandonada e começa do zero,
    // igual se a pessoa nunca tivesse mandado nada antes.
    private const HORAS_ABANDONO_CONVERSA = 3;

    public static function processarTexto(PDO $pdo, string $telefone, string $nomePerfil, string $texto): void
    {
        $texto = trim($texto);
        $conversa = self::obterOuCriarConversa($pdo, $telefone, $nomePerfil);

        if (self::estaTemporariamenteBloqueado($conversa)) {
            return;
        }

        if (self::foraDoHorarioComercial() && !self::clienteTemLiberacaoHorario($pdo, $conversa)) {
            self::avisarForaDoHorario($pdo, $conversa);
            return;
        }

        if (self::ehComandoCancelar($texto)) {
            self::excluirConversa($pdo, $conversa['id']);
            WhatsAppApi::enviarTexto($telefone, '🚫 Cancelado! Quando quiser abrir um chamado, é só mandar mensagem de novo. 🙂');
            return;
        }

        match ($conversa['etapa']) {
            'identificando' => self::etapaIdentificar($pdo, $conversa),
            'aguardando_identificacao_manual' => self::etapaIdentificacaoManual($pdo, $conversa, $texto),
            'escolher_chamado' => self::etapaEscolherChamado($pdo, $conversa, $texto),
            'mensagem_continuar' => self::etapaMensagemContinuar($pdo, $conversa, $texto),
            'aguardando_acao_resposta' => self::etapaAcaoPosResposta($pdo, $conversa, $texto),
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

        if (self::estaTemporariamenteBloqueado($conversa)) {
            return;
        }

        if (self::foraDoHorarioComercial() && !self::clienteTemLiberacaoHorario($pdo, $conversa)) {
            self::avisarForaDoHorario($pdo, $conversa);
            return;
        }

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
            WhatsAppApi::enviarBotoes($telefone, '🚫 Esse tipo de arquivo eu não consigo anexar (imagem, PDF, Word/Excel/PowerPoint, texto ou ZIP). Pode mandar outro, ou tocar no botão pra continuar sem anexo.', [
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
        // Limpa título/categoria/prioridade/descrição aqui, no ÚNICO ponto de
        // entrada depois de identificar quem é -- ver HORAS_ABANDONO_CONVERSA:
        // sem isso, se essa MESMA linha de conversa sobreviver de uma
        // tentativa anterior (dentro da janela de abandono), esses campos de
        // um assunto ANTIGO ficam largados até alguém preencher de novo, e
        // podem "vazar" pro chamado novo se a pessoa pular alguma etapa.
        $dados = [
            'cliente_id' => $clienteId,
            'setor_responsavel_id' => $setorResponsavelId,
            'nome_pessoa' => $nomePessoa,
            'titulo' => null,
            'categoria' => null,
            'prioridade' => null,
            'descricao' => null,
        ];
        $saudacao = $nomePessoa ? ('Oi, ' . self::primeiroNome($nomePessoa) . '! 👋 ') : '👋 ';

        $chamadosAbertos = self::chamadosAbertosParaIdentidade($pdo, $clienteId, $setorResponsavelId);
        if ($chamadosAbertos) {
            $dados['setor_id'] = $setorId;
            $dados['chamado_continuar_id'] = null;
            $dados['etapa'] = 'escolher_chamado';
            self::atualizarConversa($pdo, $conversa['id'], $dados);
            $qtd = count($chamadosAbertos);
            $resumoAbertos = $qtd === 1 ? 'Vi que você já tem 1 chamado em aberto.' : "Vi que você já tem {$qtd} chamados em aberto.";
            WhatsAppApi::enviarLista($conversa['telefone'], "{$saudacao}Encontrei seu cadastro: *{$nomeCliente}*. {$resumoAbertos} É sobre algum deles ou é um assunto novo?", 'Escolher', self::linhasChamadosAbertos($chamadosAbertos));
            return;
        }

        if ($setorResponsavelId) {
            // Bateu com o telefone de um responsável de setor específico --
            // se ela só tem UM setor, já nasce vinculado a ele, igual antes,
            // sem precisar perguntar. Se estiver em MAIS de um (pedido do
            // Kleber: uma pessoa pode estar em vários agora), pergunta qual
            // é o assunto -- só entre os setores DELA, não os da empresa toda.
            $idsSetoresPessoa = setores_do_responsavel($pdo, $setorResponsavelId);
            if (count($idsSetoresPessoa) <= 1) {
                $dados['setor_id'] = $setorId;
                $dados['etapa'] = 'titulo';
                self::atualizarConversa($pdo, $conversa['id'], $dados);
                WhatsAppApi::enviarTexto($conversa['telefone'], "{$saudacao}Encontrei seu cadastro: *{$nomeCliente}* ({$nomeSetor}). ✅ Vamos abrir seu chamado.\n\n📝 Resuma em poucas palavras o que está acontecendo (isso vai virar o título do chamado):");
                return;
            }

            $setoresPessoa = self::setoresParaEscolha($pdo, $clienteId, $setorResponsavelId);
            $dados['etapa'] = 'escolher_setor';
            self::atualizarConversa($pdo, $conversa['id'], $dados);
            $linhas = [];
            foreach (array_values($setoresPessoa) as $i => $s) {
                $linhas[] = ['id' => (string) ($i + 1), 'titulo' => $s['nome']];
            }
            WhatsAppApi::enviarLista($conversa['telefone'], "{$saudacao}Encontrei seu cadastro: *{$nomeCliente}*. Vi que você está em mais de um setor. 🗂️ Qual setor é o assunto?", 'Escolher setor', $linhas);
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

        // Não achou (ou achou mais de um, ambíguo) -- em vez de já avisar a
        // equipe e desistir na primeira tentativa, deixa a pessoa tentar de
        // novo até MAX_TENTATIVAS_IDENTIFICACAO vezes. Só depois disso (ou
        // se ela nunca souber o nome certo da empresa) é que trava
        // temporariamente e avisa o time -- evita spam de aviso pra time por
        // gente que só errou a digitação.
        $tentativas = (int) $conversa['tentativas_identificacao'] + 1;

        if ($tentativas >= self::MAX_TENTATIVAS_IDENTIFICACAO) {
            $bloqueadoAte = date('Y-m-d H:i:s', time() + self::MINUTOS_BLOQUEIO_SPAM * 60);
            self::atualizarConversa($pdo, $conversa['id'], ['tentativas_identificacao' => 0, 'bloqueado_ate' => $bloqueadoAte]);
            Notificacoes::whatsapp(null, "🚫 WhatsApp: {$conversa['nome_perfil']} ({$conversa['telefone']}) excedeu tentativas de identificação (última tentativa: \"$texto\") e ficou bloqueado temporariamente.");
            WhatsAppApi::enviarTexto($conversa['telefone'], '🚫 Muitas tentativas sem conseguir confirmar seu cadastro. Aguarde um pouco e tente de novo, ou fale com a PrimeERP por outro canal.');
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['tentativas_identificacao' => $tentativas]);
        WhatsAppApi::enviarTexto($conversa['telefone'], '😕 Não encontrei nenhuma empresa com esse nome. Pode digitar de novo, com o nome completo da empresa?');
    }

    private static function etapaEscolherChamado(PDO $pdo, array $conversa, string $texto): void
    {
        if (self::ehResposta($texto, ['novo', 'assunto novo', 'outro', 'novo chamado'])) {
            self::iniciarNovoAssunto($pdo, $conversa);
            return;
        }

        $clienteId = (int) $conversa['cliente_id'];
        $setorResponsavelId = $conversa['setor_responsavel_id'] ? (int) $conversa['setor_responsavel_id'] : null;
        $chamadosAbertos = self::chamadosAbertosParaIdentidade($pdo, $clienteId, $setorResponsavelId);

        $chamadoEscolhidoId = str_starts_with($texto, 'chamado_') ? (int) substr($texto, strlen('chamado_')) : null;
        $chamadoEscolhido = null;
        foreach ($chamadosAbertos as $c) {
            if ((int) $c['id'] === $chamadoEscolhidoId) {
                $chamadoEscolhido = $c;
                break;
            }
        }

        if (!$chamadoEscolhido) {
            if (!$chamadosAbertos) {
                // Chamado(s) que existiam foram fechados nesse meio tempo --
                // não tem mais nada pra "continuar", segue direto pro fluxo normal.
                self::iniciarNovoAssunto($pdo, $conversa);
                return;
            }
            WhatsAppApi::enviarLista($conversa['telefone'], '🤔 Não entendi. Toca numa das opções: é sobre algum chamado já aberto, ou é um assunto novo?', 'Escolher', self::linhasChamadosAbertos($chamadosAbertos));
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], ['chamado_continuar_id' => $chamadoEscolhido['id'], 'etapa' => 'mensagem_continuar']);
        WhatsAppApi::enviarTexto($conversa['telefone'], "✍️ Certo, chamado #{$chamadoEscolhido['id']}. Manda sua mensagem que eu registro por lá.");
    }

    private static function etapaMensagemContinuar(PDO $pdo, array $conversa, string $texto): void
    {
        if ($texto === '' || self::pareceSaudacaoOuMuitoCurto($texto)) {
            WhatsAppApi::enviarTexto($conversa['telefone'], '🤔 Pode escrever a mensagem que quer registrar no chamado?');
            return;
        }

        $chamadoId = (int) $conversa['chamado_continuar_id'];
        $stmtChamado = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
        $stmtChamado->execute([$chamadoId]);
        $chamado = $stmtChamado->fetch();

        if (!$chamado) {
            self::excluirConversa($pdo, $conversa['id']);
            WhatsAppApi::enviarTexto($conversa['telefone'], '😕 Não encontrei mais esse chamado. Manda "oi" de novo pra começar um atendimento novo.');
            return;
        }

        $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_cliente_tipo, autor_setor_responsavel_id, mensagem) VALUES (?, "cliente", ?, ?, ?, ?)')
            ->execute([
                $chamadoId,
                $conversa['nome_pessoa'] ?: $conversa['nome_perfil'],
                $conversa['setor_responsavel_id'] ? 'setor' : 'master',
                $conversa['setor_responsavel_id'],
                $texto,
            ]);
        recalcular_status_chamado($pdo, $chamadoId);

        $stmtCliente = $pdo->prepare('SELECT nome FROM clientes WHERE id = ?');
        $stmtCliente->execute([$chamado['cliente_id']]);
        $nomeCliente = (string) $stmtCliente->fetchColumn();
        Notificacoes::mensagemWhatsappChamado($chamado, $nomeCliente, $texto);

        self::excluirConversa($pdo, $conversa['id']);
        WhatsAppApi::enviarTexto($conversa['telefone'], "✅ Registrado no chamado #{$chamadoId}. Nossa equipe vai ver sua mensagem e responder por aqui.");
    }

    private const BOTOES_ACAO_POS_RESPOSTA = [
        ['id' => 'encerrar_chamado', 'titulo' => '✅ Encerrar'],
        ['id' => 'responder_chamado', 'titulo' => '💬 Responder'],
        ['id' => 'nenhuma_acao', 'titulo' => '🕓 Nenhuma agora'],
    ];

    /**
     * Chamado pelo cliente.php depois que a EQUIPE responde um chamado --
     * pedido do Kleber pra, além de avisar a resposta (ver
     * Notificacoes::novaRespostaEquipe(), que já mandou o texto/anexo),
     * perguntar no mesmo fio o que a pessoa quer fazer agora. Reaproveita a
     * conversa/etapa do bot -- se ela responder "responder", cai direto no
     * fluxo mensagem_continuar já existente (reaproveita chamado_continuar_id).
     */
    public static function prepararConversaPosResposta(PDO $pdo, array $chamado, string $telefone): void
    {
        if ($telefone === '') {
            return;
        }

        $conversa = self::obterOuCriarConversa($pdo, $telefone, '');
        self::atualizarConversa($pdo, $conversa['id'], [
            'etapa' => 'aguardando_acao_resposta',
            'chamado_continuar_id' => $chamado['id'],
        ]);
        WhatsAppApi::enviarBotoes($telefone, '👉 O que você deseja fazer agora com esse chamado?', self::BOTOES_ACAO_POS_RESPOSTA);
    }

    private static function etapaAcaoPosResposta(PDO $pdo, array $conversa, string $texto): void
    {
        $chamadoId = (int) $conversa['chamado_continuar_id'];

        if ($texto === 'encerrar_chamado') {
            $pdo->prepare("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ?")->execute([$chamadoId]);
            self::excluirConversa($pdo, $conversa['id']);
            WhatsAppApi::enviarTexto($conversa['telefone'], "✅ Chamado #{$chamadoId} encerrado. Se precisar de mais alguma coisa, é só mandar mensagem por aqui. 🙂");
            return;
        }

        if ($texto === 'responder_chamado') {
            self::atualizarConversa($pdo, $conversa['id'], ['etapa' => 'mensagem_continuar']);
            WhatsAppApi::enviarTexto($conversa['telefone'], "✍️ Pode mandar sua mensagem que eu registro no chamado #{$chamadoId}.");
            return;
        }

        if ($texto === 'nenhuma_acao') {
            self::excluirConversa($pdo, $conversa['id']);
            WhatsAppApi::enviarTexto($conversa['telefone'], '👍 Tudo bem, fico por aqui. Quando quiser encerrar ou responder esse chamado, é só me chamar de novo.');
            return;
        }

        // Não reconheceu (digitou em vez de tocar no botão, por exemplo) --
        // repete a pergunta em vez de travar a conversa.
        WhatsAppApi::enviarBotoes($conversa['telefone'], '🤔 Não entendi. Toca numa das opções:', self::BOTOES_ACAO_POS_RESPOSTA);
    }

    /**
     * Título/categoria/prioridade/descrição de qualquer assunto anterior
     * (o que ela tinha respondido antes de pedir pra ver os chamados
     * abertos, ou de uma tentativa anterior) -- mesclar isso em toda vez que
     * um NOVO assunto começa de verdade evita esses campos "vazarem" pro
     * chamado novo (ver HORAS_ABANDONO_CONVERSA e avancarAposIdentificar()).
     */
    private static function camposNovoAssunto(array $extra): array
    {
        return $extra + ['titulo' => null, 'categoria' => null, 'prioridade' => null, 'descricao' => null];
    }

    /** Retoma o fluxo normal de abertura de chamado, sem repetir "encontrei seu cadastro" (já dito antes). */
    private static function iniciarNovoAssunto(PDO $pdo, array $conversa): void
    {
        $setorResponsavelId = $conversa['setor_responsavel_id'] ? (int) $conversa['setor_responsavel_id'] : null;
        $clienteId = (int) $conversa['cliente_id'];

        if ($setorResponsavelId) {
            // Só 1 setor -- continua reaproveitando o setor_id que já estava
            // na conversa (sem precisar perguntar de novo). Com mais de um,
            // pergunta de novo (pode ser um assunto de outro setor dela).
            if (count(setores_do_responsavel($pdo, $setorResponsavelId)) <= 1) {
                self::atualizarConversa($pdo, $conversa['id'], self::camposNovoAssunto(['etapa' => 'titulo', 'chamado_continuar_id' => null]));
                WhatsAppApi::enviarTexto($conversa['telefone'], '📝 Beleza, novo assunto. Resuma em poucas palavras o que está acontecendo (isso vai virar o título do chamado):');
                return;
            }

            $setoresPessoa = self::setoresParaEscolha($pdo, $clienteId, $setorResponsavelId);
            self::atualizarConversa($pdo, $conversa['id'], self::camposNovoAssunto(['etapa' => 'escolher_setor', 'chamado_continuar_id' => null]));
            $linhas = [];
            foreach (array_values($setoresPessoa) as $i => $s) {
                $linhas[] = ['id' => (string) ($i + 1), 'titulo' => $s['nome']];
            }
            WhatsAppApi::enviarLista($conversa['telefone'], '🗂️ Beleza, novo assunto. Qual setor é o assunto?', 'Escolher setor', $linhas);
            return;
        }

        $setoresDisponiveis = self::setoresDisponiveis($pdo, $clienteId);
        if ($setoresDisponiveis) {
            self::atualizarConversa($pdo, $conversa['id'], self::camposNovoAssunto(['etapa' => 'escolher_setor', 'chamado_continuar_id' => null]));
            $linhas = [];
            foreach (array_values($setoresDisponiveis) as $i => $s) {
                $linhas[] = ['id' => (string) ($i + 1), 'titulo' => $s['nome']];
            }
            WhatsAppApi::enviarLista($conversa['telefone'], '🗂️ Beleza, novo assunto. Qual setor é o assunto?', 'Escolher setor', $linhas);
            return;
        }

        self::atualizarConversa($pdo, $conversa['id'], self::camposNovoAssunto(['etapa' => 'titulo', 'chamado_continuar_id' => null]));
        WhatsAppApi::enviarTexto($conversa['telefone'], '📝 Beleza, novo assunto. Resuma em poucas palavras o que está acontecendo (isso vai virar o título do chamado):');
    }

    private static function etapaEscolherSetor(PDO $pdo, array $conversa, string $texto): void
    {
        // setor_responsavel_id (se tiver) já ficou salvo na conversa desde a
        // identificação -- usa a mesma lista RESTRITA (só os setores dela)
        // que foi mostrada, pra não aceitar um número que corresponda a um
        // setor de fora da lista que ela realmente viu.
        $setorResponsavelId = $conversa['setor_responsavel_id'] ? (int) $conversa['setor_responsavel_id'] : null;
        $setores = self::setoresParaEscolha($pdo, (int) $conversa['cliente_id'], $setorResponsavelId);
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

        // Preview curto da descrição -- mensagem interativa (botão) tem
        // limite de 1024 caracteres na Cloud API da Meta (ver
        // WhatsAppApi::limitarCorpoInterativo(), que também protege isso
        // como rede de segurança); reproduzir uma descrição bem longa
        // (cliente colando um texto de especificação técnica, por exemplo)
        // aqui inteira já quase estourava esse limite sozinha. A descrição
        // COMPLETA continua indo pro chamado -- isso aqui é só o resumo.
        $descricaoPreview = mb_strlen($conversa['descricao']) > 300
            ? mb_substr($conversa['descricao'], 0, 300) . '(...)'
            : $conversa['descricao'];

        $resumo = "🔎 Confere se está tudo certo:\n\n"
            . "📝 *Título:* {$conversa['titulo']}\n"
            . "🏷️ *Categoria:* {$conversa['categoria']}\n"
            . '📶 *Prioridade:* ' . self::PRIORIDADES[$conversa['prioridade']] . "\n"
            . "📄 *Descrição:* {$descricaoPreview}\n"
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

        // Meta pode reentregar o mesmo webhook se a resposta não for rápida
        // o bastante -- mesma trava contra duplicado usada no portal/admin
        // (ver bug de chamados idênticos criados de uma vez).
        if (chamado_e_duplicado_recente($pdo, $conversa['cliente_id'], $tituloFinal, $conversa['descricao'])) {
            self::excluirConversa($pdo, $conversa['id']);
            $saudacao = $conversa['nome_pessoa'] ? ('Prontinho, ' . self::primeiroNome($conversa['nome_pessoa']) . '!') : 'Prontinho!';
            WhatsAppApi::enviarTexto($conversa['telefone'], "✅ {$saudacao} Seu chamado já tinha sido aberto. 🙌 Nossa equipe já foi avisada e vai te responder por aqui.");
            return;
        }

        $stmt = $pdo->prepare('
            INSERT INTO chamados
                (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, aberto_por_tipo, aberto_por_setor_responsavel_id, origem_canal, setor_id, telefone_whatsapp)
            VALUES (?, ?, ?, ?, ?, "cliente", ?, ?, "whatsapp", ?, ?)
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
            $conversa['telefone'],
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

        // Mesmo aviso (e-mail pra equipe + WhatsApp pro Kleber, e agora
        // também pra quem é responsável pelo setor, via
        // Notificacoes::novoChamado()) de qualquer outro chamado novo -- não
        // precisa de nada especial só porque veio do WhatsApp.
        Notificacoes::novoChamado($pdo, [
            'id' => $chamadoId,
            'titulo' => $tituloFinal,
            'prioridade' => $conversa['prioridade'],
            'descricao' => $conversa['descricao'],
            'setor_id' => $conversa['setor_id'],
            'aberto_por_tipo' => $conversa['setor_responsavel_id'] ? 'setor' : 'master',
            'aberto_por_setor_responsavel_id' => $conversa['setor_responsavel_id'],
        ], $cliente);
    }

    // --- Identificação por telefone -----------------------------------------

    private static function identificarPorTelefone(PDO $pdo, string $telefone): ?array
    {
        // telefones_correspondem() é PHP puro (não dá pra comparar direto no
        // SQL por causa das variações de DDI/9º dígito) -- por isso busca
        // todo mundo com telefone cadastrado e compara linha a linha aqui.

        // Primeiro tenta bater com o telefone de um responsável de setor
        // específico (mais preciso: já sabe exatamente quem é a pessoa).
        // Não filtra mais pelo setor_id ÚNICO (primário) -- uma pessoa pode
        // estar em vários setores agora (ver setor_responsavel_setores), então
        // o match pelo telefone só depende do CLIENTE estar ativo e ela ter
        // PELO MENOS UM setor ativo (não necessariamente o principal). O
        // setor_id retornado aqui continua sendo só o principal, usado como
        // atalho quando ela só tem um setor mesmo (ver avancarAposIdentificar()).
        $stmtResp = $pdo->query("SELECT sr.id AS setor_responsavel_id, sr.nome, sr.telefone, sr.setor_id, sr.bloqueado_whatsapp, s.nome AS nome_setor, sr.cliente_id, c.nome AS nome_cliente
            FROM setor_responsaveis sr
            JOIN clientes c ON c.id = sr.cliente_id
            LEFT JOIN setores s ON s.id = sr.setor_id
            WHERE c.status = 'ativo' AND sr.telefone IS NOT NULL
              AND EXISTS (
                  SELECT 1 FROM setor_responsavel_setores srs
                  INNER JOIN setores s2 ON s2.id = srs.setor_id
                  WHERE srs.setor_responsavel_id = sr.id AND s2.ativo = 1
              )");
        foreach ($stmtResp->fetchAll() as $linha) {
            if (telefones_correspondem($telefone, $linha['telefone'])) {
                return [
                    'cliente_id' => (int) $linha['cliente_id'],
                    'setor_id' => $linha['setor_id'] !== null ? (int) $linha['setor_id'] : null,
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

    // Lista tem no máximo 10 linhas (limite da Meta); reserva 1 pra "novo
    // chamado", então no máximo 9 chamados abertos aparecem na listinha.
    private const MAX_CHAMADOS_ABERTOS_NA_LISTA = 9;

    /** Chamados ainda abertos (não fechados) dessa mesma identidade -- master do cliente ou responsável de setor específico. */
    private static function chamadosAbertosParaIdentidade(PDO $pdo, int $clienteId, ?int $setorResponsavelId): array
    {
        if ($setorResponsavelId) {
            $stmt = $pdo->prepare("SELECT id, titulo FROM chamados WHERE aberto_por_setor_responsavel_id = ? AND status != 'fechado' ORDER BY criado_em DESC LIMIT " . self::MAX_CHAMADOS_ABERTOS_NA_LISTA);
            $stmt->execute([$setorResponsavelId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, titulo FROM chamados WHERE cliente_id = ? AND aberto_por_tipo = 'master' AND status != 'fechado' ORDER BY criado_em DESC LIMIT " . self::MAX_CHAMADOS_ABERTOS_NA_LISTA);
            $stmt->execute([$clienteId]);
        }
        return $stmt->fetchAll();
    }

    private static function linhasChamadosAbertos(array $chamadosAbertos): array
    {
        $linhas = [];
        foreach ($chamadosAbertos as $chamado) {
            $linhas[] = [
                'id' => 'chamado_' . $chamado['id'],
                'titulo' => '#' . $chamado['id'],
                'descricao' => $chamado['titulo'],
            ];
        }
        $linhas[] = ['id' => 'novo', 'titulo' => '🆕 Novo chamado', 'descricao' => 'Assunto diferente dos de cima'];
        return $linhas;
    }

    /** Setores com pelo menos 1 responsável relacionado ou que o master participe. */
    private static function setoresDisponiveis(PDO $pdo, int $clienteId): array
    {
        $stmt = $pdo->prepare("
            SELECT s.id, s.nome
            FROM setores s
            WHERE s.cliente_id = ? AND s.ativo = 1
              AND (
                  EXISTS(SELECT 1 FROM cliente_setores cs WHERE cs.cliente_id = s.cliente_id AND cs.setor_id = s.id)
                  OR EXISTS(SELECT 1 FROM setor_responsavel_setores srs WHERE srs.setor_id = s.id)
              )
            ORDER BY s.nome ASC
        ");
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    /**
     * Setores que uma pessoa pode escolher no WhatsApp -- todos os
     * disponíveis da empresa pro MASTER (ele pode abrir chamado em qualquer
     * setor), mas só os PRÓPRIOS setores pra quem é um responsável de setor
     * específico -- pedido do Kleber: uma pessoa pode estar em mais de um
     * setor agora, mas continua só podendo escolher entre os DELA, não os
     * de toda a empresa.
     */
    private static function setoresParaEscolha(PDO $pdo, int $clienteId, ?int $setorResponsavelId): array
    {
        if (!$setorResponsavelId) {
            return self::setoresDisponiveis($pdo, $clienteId);
        }
        $idsSetoresPessoa = setores_do_responsavel($pdo, $setorResponsavelId);
        if (!$idsSetoresPessoa) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($idsSetoresPessoa), '?'));
        $stmt = $pdo->prepare("SELECT id, nome FROM setores WHERE cliente_id = ? AND ativo = 1 AND id IN ($placeholders) ORDER BY nome ASC");
        $stmt->execute([$clienteId, ...$idsSetoresPessoa]);
        return $stmt->fetchAll();
    }

    // --- Auxiliares de conversa ----------------------------------------------

    private static function obterOuCriarConversa(PDO $pdo, string $telefone, string $nomePerfil): array
    {
        // "abandonada" comparado inteiro dentro do MySQL (atualizado_em contra
        // NOW() do PRÓPRIO servidor) -- de propósito, pra não depender do
        // fuso horário do PHP bater com o do servidor de banco. Comparar
        // strtotime($atualizado_em) (interpretado no fuso do PHP) contra
        // time() (Unix, sem fuso) dá resultado errado sempre que os dois
        // servidores não estiverem no mesmo fuso -- bug real encontrado
        // testando: MySQL local em "SYSTEM" (UTC) e PHP em America/Sao_Paulo,
        // uma diferença de 3h que fazia uma conversa de 4h atrás parecer ter
        // só 1h, nunca expirando.
        $stmt = $pdo->prepare('SELECT *, (atualizado_em < NOW() - INTERVAL ? HOUR) AS abandonada FROM whatsapp_conversas WHERE telefone = ?');
        $stmt->execute([self::HORAS_ABANDONO_CONVERSA, $telefone]);
        $conversa = $stmt->fetch();

        // Ver HORAS_ABANDONO_CONVERSA -- sem isso, título/categoria/setor de
        // uma tentativa de dias/semanas atrás que nunca terminou (chamado
        // criado ou cancelado) fica pra sempre na linha e pode "vazar" pra
        // um assunto novo completamente diferente.
        if ($conversa && (bool) $conversa['abandonada']) {
            self::excluirConversa($pdo, $conversa['id']);
            $conversa = null;
        }

        if ($conversa) {
            unset($conversa['abandonada']);
            return $conversa;
        }

        $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversas WHERE telefone = ?');

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
     * Bloqueio temporário por anti-spam (ver MAX_TENTATIVAS_IDENTIFICACAO em
     * etapaIdentificacaoManual()) -- fica em silêncio total enquanto durar,
     * sem nem responder "você está bloqueado", porque isso também seria
     * abusável por quem só quer fazer o bot gastar cota de envio de mensagem.
     */
    private static function estaTemporariamenteBloqueado(array $conversa): bool
    {
        return $conversa['bloqueado_ate'] && strtotime($conversa['bloqueado_ate']) > time();
    }

    /** Fora de seg-sex das WHATSAPP_HORARIO_COMERCIAL_INICIO às _FIM (horário local, ver date_default_timezone_set em config.php). */
    private static function foraDoHorarioComercial(?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable('now');
        if ((int) $agora->format('N') >= 6) {
            return true; // sábado (6) ou domingo (7)
        }
        $hora = (int) $agora->format('G');
        return $hora < WHATSAPP_HORARIO_COMERCIAL_INICIO || $hora >= WHATSAPP_HORARIO_COMERCIAL_FIM;
    }

    /**
     * Liberação especial (aba Usuários, clientes.whatsapp_liberacao_horario)
     * pra funcionar fora do horário comercial. Se a conversa ainda não tem
     * cliente_id (mensagem antes de terminar a identificação), tenta achar
     * pelo telefone só pra essa checagem -- não avança a identificação
     * em si, isso continua acontecendo normalmente depois.
     */
    private static function clienteTemLiberacaoHorario(PDO $pdo, array $conversa): bool
    {
        $clienteId = $conversa['cliente_id'] ? (int) $conversa['cliente_id'] : null;
        if (!$clienteId) {
            $identificacao = self::identificarPorTelefone($pdo, $conversa['telefone']);
            $clienteId = $identificacao['cliente_id'] ?? null;
        }
        if (!$clienteId) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT whatsapp_liberacao_horario FROM clientes WHERE id = ?');
        $stmt->execute([$clienteId]);
        return (bool) $stmt->fetchColumn();
    }

    private static function avisarForaDoHorario(PDO $pdo, array $conversa): void
    {
        $ultimoAviso = $conversa['aviso_horario_enviado_em'] ?? null;
        if ($ultimoAviso && strtotime($ultimoAviso) > time() - self::HORAS_ENTRE_AVISOS_HORARIO * 3600) {
            return; // já avisou recentemente, fica quieto pra não repetir toda hora
        }

        self::atualizarConversa($pdo, $conversa['id'], ['aviso_horario_enviado_em' => date('Y-m-d H:i:s')]);
        WhatsAppApi::enviarTexto($conversa['telefone'], '🕗 Nosso atendimento por aqui funciona de segunda a sexta, das ' . WHATSAPP_HORARIO_COMERCIAL_INICIO . 'h às ' . WHATSAPP_HORARIO_COMERCIAL_FIM . 'h. Recebemos sua mensagem e alguém vai te responder no próximo horário comercial. 🙏');
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
