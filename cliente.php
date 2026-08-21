<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\AiSugestoes;
use AdminPrime\Auth;
use AdminPrime\Crypto;
use AdminPrime\Database;
use AdminPrime\Notificacoes;
use AdminPrime\PdfExport;
use AdminPrime\TempAccess;
use AdminPrime\WhatsAppBot;

Auth::requireLogin();

$pdo = Database::connection();

$clienteId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    die('Cliente não encontrado.');
}

// --- Ações (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'atualizar_cliente') {
        $stmt = $pdo->prepare('UPDATE clientes SET nome=?, cnpj=?, status=?, responsavel_interno_id=?, observacoes=?, portal_permite_gerenciar_equipe=? WHERE id=?');
        $stmt->execute([
            trim($_POST['nome']),
            apenas_digitos($_POST['cnpj'] ?? ''),
            $_POST['status'],
            $_POST['responsavel_interno_id'] ?: null,
            trim($_POST['observacoes']) ?: null,
            isset($_POST['portal_permite_gerenciar_equipe']) ? 1 : 0,
            $clienteId,
        ]);

        $erroLogo = processar_upload_logo_cliente($pdo, $clienteId);
        if ($erroLogo) {
            flash_set('erro', $erroLogo);
        }

        flash_set('sucesso', 'Dados do cliente atualizados.');
    }

    // Extraído de "atualizar_cliente" -- dados do CONTATO (pessoa) numa aba
    // própria, separada dos dados da EMPRESA, pedido do Kleber pra "juntar
    // tudo que é do usuário/contato em um lugar só". Sem isso os dois grupos
    // de campo ficavam misturados no mesmo form/UPDATE.
    if ($acao === 'atualizar_contato') {
        $stmt = $pdo->prepare('UPDATE clientes SET contato_nome=?, contato_email=?, contato_telefone=?, contato_documento=? WHERE id=?');
        $stmt->execute([
            trim($_POST['contato_nome']) ?: null,
            trim($_POST['contato_email']) ?: null,
            apenas_digitos($_POST['contato_telefone'] ?? ''),
            normalizar_documento($_POST['contato_documento'] ?? ''),
            $clienteId,
        ]);

        flash_set('sucesso', 'Dados do contato atualizados.');
    }

    if ($acao === 'criar_kpi') {
        $direcaoMelhorKpi = in_array($_POST['direcao_melhor'] ?? '', ['maior', 'menor'], true) ? $_POST['direcao_melhor'] : 'maior';
        $stmt = $pdo->prepare('INSERT INTO kpis (cliente_id, setor_id, nome_kpi, valor, meta, direcao_melhor, unidade, periodo, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            $_POST['setor_id'] ?: null,
            trim($_POST['nome_kpi']),
            str_replace(',', '.', $_POST['valor']),
            $_POST['meta'] !== '' ? str_replace(',', '.', $_POST['meta']) : null,
            $direcaoMelhorKpi,
            trim($_POST['unidade']) ?: null,
            $_POST['periodo'] . '-01',
            trim($_POST['observacoes']) ?: null,
        ]);
        flash_set('sucesso', 'KPI registrado.');
    }

    if ($acao === 'editar_kpi') {
        $direcaoMelhorKpi = in_array($_POST['direcao_melhor'] ?? '', ['maior', 'menor'], true) ? $_POST['direcao_melhor'] : 'maior';
        $stmt = $pdo->prepare('UPDATE kpis SET setor_id = ?, nome_kpi = ?, valor = ?, meta = ?, direcao_melhor = ?, unidade = ?, periodo = ?, observacoes = ? WHERE id = ? AND cliente_id = ?');
        $stmt->execute([
            $_POST['setor_id'] ?: null,
            trim($_POST['nome_kpi']),
            str_replace(',', '.', $_POST['valor']),
            $_POST['meta'] !== '' ? str_replace(',', '.', $_POST['meta']) : null,
            $direcaoMelhorKpi,
            trim($_POST['unidade']) ?: null,
            $_POST['periodo'] . '-01',
            trim($_POST['observacoes']) ?: null,
            (int) $_POST['kpi_id'],
            $clienteId,
        ]);
        flash_set('sucesso', 'KPI atualizado.');
    }

    if ($acao === 'excluir_kpi') {
        $pdo->prepare('DELETE FROM kpis WHERE id = ? AND cliente_id = ?')
            ->execute([(int) $_POST['kpi_id'], $clienteId]);
        flash_set('sucesso', 'KPI removido.');
    }

    // Calcula 4 KPIs padrão (abertos, resolvidos, urgentes, tempo médio)
    // direto dos CHAMADOS do setor no mês escolhido -- pra quem não sabe
    // que número digitar todo mês (pedido do Kleber). Faz upsert: se já
    // existe um KPI com esse nome nesse mês, atualiza em vez de duplicar,
    // e preserva a meta cadastrada manualmente (busca a meta mais recente
    // já definida pra aquele KPI/setor, senão o valor calculado sobrescreve
    // um número mas nunca inventa uma meta que ninguém definiu).
    if ($acao === 'calcular_kpis_automaticos') {
        // setor_id vazio = KPI GERAL do cliente. Antes isso significava
        // "só chamados sem setor vinculado" -- pra cliente que usa setores
        // (a grande maioria) isso dava sempre 0, parecendo que o cálculo
        // "não fazia nada" (reclamação real do Kleber). Agora GERAL passa a
        // ser o CONSOLIDADO: todos os chamados do cliente, com ou sem
        // setor. Quem quiser o número só de um setor usa "Registrar
        // métrica" de dentro daquele setor (aba Setores), que continua
        // filtrando por setor_id normalmente.
        $setorIdCalculo = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
        $condicaoSetorChamados = $setorIdCalculo !== null ? 'setor_id = ?' : '1 = 1';
        $paramsSetorChamados = $setorIdCalculo !== null ? [$setorIdCalculo] : [];
        // Mas o KPI em si (na tabela kpis) continua marcado como "geral"
        // (setor_id NULL) pra não se misturar com as métricas específicas
        // de cada setor na tela.
        $condicaoSetorKpis = $setorIdCalculo !== null ? 'setor_id = ?' : 'setor_id IS NULL';
        $paramsSetorKpis = $setorIdCalculo !== null ? [$setorIdCalculo] : [];

        $periodoCalculo = $_POST['periodo'] . '-01';
        $inicioPeriodo = $periodoCalculo . ' 00:00:00';
        $fimPeriodo = date('Y-m-t 23:59:59', strtotime($periodoCalculo));

        $stmtCalculo = $pdo->prepare("
            SELECT
                COUNT(*) AS total_abertos,
                SUM(CASE WHEN prioridade IN ('alta', 'urgente') THEN 1 ELSE 0 END) AS total_urgentes,
                (SELECT COUNT(*) FROM chamados WHERE {$condicaoSetorChamados} AND cliente_id = ? AND fechado_em BETWEEN ? AND ?) AS total_resolvidos,
                (SELECT AVG(TIMESTAMPDIFF(MINUTE, criado_em, fechado_em)) FROM chamados WHERE {$condicaoSetorChamados} AND cliente_id = ? AND fechado_em BETWEEN ? AND ?) AS tempo_medio_min
            FROM chamados
            WHERE {$condicaoSetorChamados} AND cliente_id = ? AND criado_em BETWEEN ? AND ?
        ");
        $stmtCalculo->execute([
            ...$paramsSetorChamados, $clienteId, $inicioPeriodo, $fimPeriodo,
            ...$paramsSetorChamados, $clienteId, $inicioPeriodo, $fimPeriodo,
            ...$paramsSetorChamados, $clienteId, $inicioPeriodo, $fimPeriodo,
        ]);
        $resultadoCalculo = $stmtCalculo->fetch();

        // Direção de cada métrica automática -- "quanto MAIOR melhor" pra
        // volume/resolução, "quanto MENOR melhor" pra urgência/tempo de
        // espera. Sem isso o card não sabe se pintar de verde ou vermelho.
        $kpisAutomaticos = [
            ['Chamados abertos', (int) $resultadoCalculo['total_abertos'], 'chamados', 'maior'],
            ['Chamados urgentes/alta prioridade', (int) $resultadoCalculo['total_urgentes'], 'chamados', 'menor'],
            ['Chamados resolvidos', (int) $resultadoCalculo['total_resolvidos'], 'chamados', 'maior'],
            ['Tempo médio de atendimento', $resultadoCalculo['tempo_medio_min'] !== null ? round((float) $resultadoCalculo['tempo_medio_min'], 2) : 0, 'min', 'menor'],
        ];

        foreach ($kpisAutomaticos as [$nomeKpiAuto, $valorKpiAuto, $unidadeKpiAuto, $direcaoKpiAuto]) {
            $stmtMetaAnterior = $pdo->prepare("SELECT meta FROM kpis WHERE {$condicaoSetorKpis} AND nome_kpi = ? AND meta IS NOT NULL ORDER BY periodo DESC LIMIT 1");
            $stmtMetaAnterior->execute([...$paramsSetorKpis, $nomeKpiAuto]);
            $metaAnteriorKpi = $stmtMetaAnterior->fetchColumn();
            $metaAnteriorKpi = $metaAnteriorKpi !== false ? $metaAnteriorKpi : null;

            // "Inteligência" sem depender de IA nenhuma: se ninguém nunca
            // definiu uma meta pra esse KPI, sugere a média dos últimos até
            // 3 meses anteriores como ponto de partida (em vez de deixar
            // sempre em branco pra sempre). Só é usada UMA vez -- a partir
            // daí ela conta como "meta definida" e o COALESCE acima passa a
            // preservá-la normalmente, então isso nunca sobrescreve um
            // ajuste manual do usuário depois.
            $observacaoAuto = 'Calculado automaticamente a partir dos chamados.';
            if ($metaAnteriorKpi === null) {
                $stmtHistorico = $pdo->prepare("SELECT valor FROM kpis WHERE {$condicaoSetorKpis} AND nome_kpi = ? AND periodo < ? ORDER BY periodo DESC LIMIT 3");
                $stmtHistorico->execute([...$paramsSetorKpis, $nomeKpiAuto, $periodoCalculo]);
                $valoresHistoricos = $stmtHistorico->fetchAll(PDO::FETCH_COLUMN);
                if ($valoresHistoricos) {
                    $metaAnteriorKpi = round(array_sum($valoresHistoricos) / count($valoresHistoricos), 2);
                    $observacaoAuto .= ' Meta sugerida com base na média dos últimos meses -- ajuste como preferir.';
                }
            }

            $stmtKpiExistente = $pdo->prepare("SELECT id FROM kpis WHERE {$condicaoSetorKpis} AND nome_kpi = ? AND periodo = ?");
            $stmtKpiExistente->execute([...$paramsSetorKpis, $nomeKpiAuto, $periodoCalculo]);
            $idKpiExistente = $stmtKpiExistente->fetchColumn();

            if ($idKpiExistente) {
                $pdo->prepare('UPDATE kpis SET valor = ?, unidade = ?, meta = COALESCE(meta, ?), direcao_melhor = ?, observacoes = ? WHERE id = ?')
                    ->execute([$valorKpiAuto, $unidadeKpiAuto, $metaAnteriorKpi, $direcaoKpiAuto, $observacaoAuto, $idKpiExistente]);
            } else {
                $pdo->prepare('INSERT INTO kpis (cliente_id, setor_id, nome_kpi, valor, meta, direcao_melhor, unidade, periodo, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$clienteId, $setorIdCalculo, $nomeKpiAuto, $valorKpiAuto, $metaAnteriorKpi, $direcaoKpiAuto, $unidadeKpiAuto, $periodoCalculo, $observacaoAuto]);
            }
        }

        flash_set('sucesso', '4 KPIs calculados automaticamente a partir dos chamados.');
    }

    if ($acao === 'criar_treinamento') {
        $setorIdTreinamentoNovo = $_POST['setor_id'] ?: null;
        $tiposTreinamentoValidos = ['treinamento', 'reuniao', 'conversa', 'auxilio'];
        $tipoTreinamentoNovo = in_array($_POST['tipo'] ?? '', $tiposTreinamentoValidos, true) ? $_POST['tipo'] : 'treinamento';
        $tituloTreinamentoNovo = trim($_POST['titulo']);

        // Chamado vinculado (opcional) tem que ser mesmo desse cliente --
        // não dá pra confiar no id que vier do POST sem confirmar isso.
        $chamadoIdTreinamentoNovo = null;
        if (!empty($_POST['chamado_id'])) {
            $confereChamadoTreinamento = $pdo->prepare('SELECT id FROM chamados WHERE id = ? AND cliente_id = ?');
            $confereChamadoTreinamento->execute([(int) $_POST['chamado_id'], $clienteId]);
            $chamadoIdTreinamentoNovo = $confereChamadoTreinamento->fetchColumn() ?: null;
        }

        $stmt = $pdo->prepare('INSERT INTO treinamentos (cliente_id, setor_id, titulo, tipo, descricao, status, data_inicio, hora_inicio, data_fim, hora_fim, link_reuniao, responsavel_id, chamado_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            $setorIdTreinamentoNovo,
            $tituloTreinamentoNovo,
            $tipoTreinamentoNovo,
            trim($_POST['descricao']) ?: null,
            $_POST['status'] ?: 'planejado',
            $_POST['data_inicio'] ?: null,
            $_POST['hora_inicio'] ?: null,
            $_POST['data_fim'] ?: null,
            $_POST['hora_fim'] ?: null,
            trim($_POST['link_reuniao'] ?? '') ?: null,
            $_POST['responsavel_id'] ?: null,
            $chamadoIdTreinamentoNovo,
        ]);
        $treinamentoIdNovo = (int) $pdo->lastInsertId();
        salvar_destinatarios_treinamento($pdo, $treinamentoIdNovo, $_POST['destinatarios'] ?? [], $clienteId);

        // Avisa a equipe (Kleber/Petronilho) por e-mail, e as pessoas do
        // cliente marcadas em "destinatarios" por e-mail + WhatsApp.
        $nomeSetorTreinamentoNovo = null;
        if ($setorIdTreinamentoNovo) {
            $stmtNomeSetor = $pdo->prepare('SELECT nome FROM setores WHERE id = ? AND cliente_id = ?');
            $stmtNomeSetor->execute([$setorIdTreinamentoNovo, $clienteId]);
            $nomeSetorTreinamentoNovo = $stmtNomeSetor->fetchColumn() ?: null;
        }
        notificar_depois(fn () => Notificacoes::novoTreinamento($pdo, [
            'id' => $treinamentoIdNovo,
            'cliente_id' => $clienteId,
            'titulo' => $tituloTreinamentoNovo,
            'tipo' => $tipoTreinamentoNovo,
            'data_inicio' => $_POST['data_inicio'] ?: null,
            'hora_inicio' => $_POST['hora_inicio'] ?: null,
            'link_reuniao' => trim($_POST['link_reuniao'] ?? '') ?: null,
        ], $cliente['nome'], $nomeSetorTreinamentoNovo));

        $rotulosTipoTreinamento = ['treinamento' => 'Treinamento', 'reuniao' => 'Reunião', 'conversa' => 'Conversa', 'auxilio' => 'Auxílio'];
        flash_set('sucesso', $rotulosTipoTreinamento[$tipoTreinamentoNovo] . ' registrado(a).');
    }

    if ($acao === 'atualizar_treinamento_status') {
        $stmt = $pdo->prepare('UPDATE treinamentos SET status=? WHERE id=? AND cliente_id=?');
        $stmt->execute([$_POST['status'], (int) $_POST['treinamento_id'], $clienteId]);
        flash_set('sucesso', 'Status do treinamento atualizado.');
    }

    // Editar um treinamento/reunião já existente (clicando nele no
    // calendário) -- reaproveita os mesmos campos da criação. Se a
    // data/hora mudar, zera lembrete_enviado_em pra o lembrete de 5min
    // antes ser recalculado em cima do novo horário.
    if ($acao === 'editar_treinamento') {
        $treinamentoIdEditar = (int) $_POST['treinamento_id'];
        $tiposTreinamentoValidos = ['treinamento', 'reuniao', 'conversa', 'auxilio'];

        $chamadoIdTreinamentoEditar = null;
        if (!empty($_POST['chamado_id'])) {
            $confereChamadoTreinamentoEditar = $pdo->prepare('SELECT id FROM chamados WHERE id = ? AND cliente_id = ?');
            $confereChamadoTreinamentoEditar->execute([(int) $_POST['chamado_id'], $clienteId]);
            $chamadoIdTreinamentoEditar = $confereChamadoTreinamentoEditar->fetchColumn() ?: null;
        }

        $stmt = $pdo->prepare('
            UPDATE treinamentos SET
                setor_id = ?, titulo = ?, tipo = ?, descricao = ?, status = ?,
                data_inicio = ?, hora_inicio = ?, data_fim = ?, hora_fim = ?,
                link_reuniao = ?, responsavel_id = ?, chamado_id = ?, lembrete_enviado_em = NULL
            WHERE id = ? AND cliente_id = ?
        ');
        $stmt->execute([
            $_POST['setor_id'] ?: null,
            trim($_POST['titulo']),
            in_array($_POST['tipo'] ?? '', $tiposTreinamentoValidos, true) ? $_POST['tipo'] : 'treinamento',
            trim($_POST['descricao']) ?: null,
            $_POST['status'] ?: 'planejado',
            $_POST['data_inicio'] ?: null,
            $_POST['hora_inicio'] ?: null,
            $_POST['data_fim'] ?: null,
            $_POST['hora_fim'] ?: null,
            trim($_POST['link_reuniao'] ?? '') ?: null,
            $_POST['responsavel_id'] ?: null,
            $chamadoIdTreinamentoEditar,
            $treinamentoIdEditar,
            $clienteId,
        ]);
        salvar_destinatarios_treinamento($pdo, $treinamentoIdEditar, $_POST['destinatarios'] ?? [], $clienteId);
        flash_set('sucesso', 'Treinamento/reunião atualizado.');
    }

    if ($acao === 'excluir_treinamento') {
        $pdo->prepare('DELETE FROM treinamentos WHERE id = ? AND cliente_id = ?')
            ->execute([(int) $_POST['treinamento_id'], $clienteId]);
        flash_set('sucesso', 'Treinamento/reunião removido.');
    }

    // --- Propostas de desenvolvimento (backlog de melhoria por setor) ----

    if ($acao === 'criar_proposta') {
        $tituloProposta = trim($_POST['titulo'] ?? '');
        if ($tituloProposta === '') {
            flash_set('erro', 'Informe o título da proposta.');
        } else {
            // Anexo é opcional (ex.: PDF de uma proposta de integração com
            // API) -- reaproveita a tabela documentos (mesmo backup/
            // restauração/download que documento já tem), a proposta só
            // guarda a referência.
            $documentoIdProposta = null;
            $erroUploadProposta = null;
            if (!empty($_FILES['arquivo']['name'])) {
                $documentoIdProposta = salvar_documento_upload(
                    $pdo,
                    $clienteId,
                    $_POST['setor_id'] ?: null,
                    $tituloProposta,
                    null,
                    'Proposta de desenvolvimento',
                    $_FILES['arquivo'],
                    Auth::userId(),
                    $erroUploadProposta
                );
            }

            $pdo->prepare('INSERT INTO propostas_desenvolvimento (cliente_id, setor_id, titulo, descricao, documento_id, criado_por) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$clienteId, $_POST['setor_id'] ?: null, $tituloProposta, trim($_POST['descricao'] ?? '') ?: null, $documentoIdProposta, Auth::userId()]);

            flash_set(
                $erroUploadProposta ? 'erro' : 'sucesso',
                $erroUploadProposta ? ('Proposta registrada, mas o anexo falhou: ' . $erroUploadProposta) : 'Proposta registrada.'
            );
        }
    }

    if ($acao === 'atualizar_status_proposta') {
        $pdo->prepare('UPDATE propostas_desenvolvimento SET status=? WHERE id=? AND cliente_id=?')
            ->execute([$_POST['status'], (int) $_POST['proposta_id'], $clienteId]);
        flash_set('sucesso', 'Status da proposta atualizado.');
    }

    if ($acao === 'excluir_proposta') {
        $pdo->prepare('DELETE FROM propostas_desenvolvimento WHERE id=? AND cliente_id=?')
            ->execute([(int) $_POST['proposta_id'], $clienteId]);
        flash_set('sucesso', 'Proposta removida.');
    }

    // --- Vídeo aulas por setor (portal do cliente assiste/baixa) ---------
    // Reaproveita a tabela "documentos" (categoria 'Vídeo aula'), mesma
    // ideia de Proposta de desenvolvimento -- sem tabela nova, ganha de
    // graça o backup/restauração de arquivo que documento já tem.

    if ($acao === 'upload_video_aula' && isset($_FILES['arquivo'])) {
        $nomeRotinaVideo = trim($_POST['nome_rotina'] ?? '');
        $setorIdVideo = (int) ($_POST['setor_id'] ?? 0);

        if ($nomeRotinaVideo === '') {
            flash_set('erro', 'Informe o nome da rotina da vídeo aula.');
        } elseif ($_FILES['arquivo']['error'] === UPLOAD_ERR_OK && !isset(VIDEO_AULA_TIPOS_PERMITIDOS[mime_content_type($_FILES['arquivo']['tmp_name'])])) {
            flash_set('erro', 'Envie um arquivo de vídeo no formato MP4.');
        } else {
            // O upload em si (bytes chegando) já tem max_input_time bem alto
            // (.htaccess) -- mas o max_execution_time de 45s continua baixo
            // de propósito pro resto do site (ver comentário lá). Só que
            // move_uploaded_file() de um vídeo de vários GB pode ele mesmo
            // passar de 45s se a pasta temporária do PHP estiver num
            // filesystem diferente de uploads/ (copia byte a byte em vez de
            // um rename() instantâneo) -- nesse caso o PHP mata o processo
            // SEM mandar nenhuma resposta, e a barra de "Registrando..." no
            // navegador fica travada pra sempre (bug real reportado pelo
            // Kleber). Só essa ação (não o site todo) ganha um tempo bem
            // maior, e ignore_user_abort garante que, se a pessoa desistir e
            // sair da página no meio do caminho, o vídeo termina de salvar
            // em segundo plano em vez de ficar corrompido/pela metade.
            set_time_limit(600);
            ignore_user_abort(true);

            $erroUploadVideo = null;
            $idVideoNovo = salvar_documento_upload(
                $pdo,
                $clienteId,
                $setorIdVideo ?: null,
                $nomeRotinaVideo,
                null,
                'Vídeo aula',
                $_FILES['arquivo'],
                Auth::userId(),
                $erroUploadVideo,
                VIDEO_AULA_MAX_SIZE,
                false
            );
            flash_set($idVideoNovo ? 'sucesso' : 'erro', $idVideoNovo ? 'Vídeo aula cadastrada.' : $erroUploadVideo);
        }
    }

    if ($acao === 'excluir_video_aula') {
        $stmtVideoExcluir = $pdo->prepare("SELECT arquivo_caminho FROM documentos WHERE id = ? AND cliente_id = ? AND categoria = 'Vídeo aula'");
        $stmtVideoExcluir->execute([(int) $_POST['documento_id'], $clienteId]);
        $caminhoVideoExcluir = $stmtVideoExcluir->fetchColumn();

        $pdo->prepare("DELETE FROM documentos WHERE id = ? AND cliente_id = ? AND categoria = 'Vídeo aula'")
            ->execute([(int) $_POST['documento_id'], $clienteId]);

        if ($caminhoVideoExcluir) {
            @unlink(UPLOADS_DIR . '/' . $caminhoVideoExcluir);
        }
        flash_set('sucesso', 'Vídeo aula removida.');
    }

    // --- Acessos (cofre de credenciais/VPN) e Fontes ADVPL ----------------
    // Dado extremamente sensível -- checa a permissão especial de novo
    // AQUI (server-side), não só escondendo a aba na tela: alguém sem
    // permissão não pode criar/editar/excluir isso nem mandando o POST
    // direto, mesmo sem ver o botão.
    $acoesDadosSensiveis = [
        'criar_credencial', 'atualizar_credencial', 'excluir_credencial',
        'upload_vpn_arquivo', 'excluir_vpn_arquivo',
        'upload_fonte_advpl', 'atualizar_fonte_advpl', 'excluir_fonte_advpl',
        'salvar_tcloud_ambiente',
        'criar_tcloud_vscode', 'atualizar_tcloud_vscode', 'excluir_tcloud_vscode',
    ];
    if (in_array($acao, $acoesDadosSensiveis, true) && !Auth::podeAcessarDadosSensiveis()) {
        http_response_code(403);
        die('Acesso restrito -- essa área guarda dados sensíveis do cliente (credenciais, código-fonte).');
    }

    if ($acao === 'criar_credencial') {
        $tipoCredencial = array_key_exists($_POST['tipo'] ?? '', CREDENCIAL_TIPOS) ? $_POST['tipo'] : 'outro';
        $tituloCredencial = trim($_POST['titulo'] ?? '');
        if ($tituloCredencial === '') {
            flash_set('erro', 'Informe um título/nome pra essa credencial.');
        } else {
            $pdo->prepare('INSERT INTO cliente_credenciais (cliente_id, tipo, titulo, host, porta, usuario, senha_cifrada, observacoes, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $clienteId,
                    $tipoCredencial,
                    $tituloCredencial,
                    trim($_POST['host'] ?? '') ?: null,
                    trim($_POST['porta'] ?? '') ?: null,
                    trim($_POST['usuario'] ?? '') ?: null,
                    Crypto::cifrar($_POST['senha'] ?? null),
                    trim($_POST['observacoes'] ?? '') ?: null,
                    Auth::userId(),
                ]);
            flash_set('sucesso', 'Credencial cadastrada.');
        }
    }

    if ($acao === 'atualizar_credencial') {
        $credencialIdAlvo = (int) $_POST['credencial_id'];
        $tipoCredencial = array_key_exists($_POST['tipo'] ?? '', CREDENCIAL_TIPOS) ? $_POST['tipo'] : 'outro';
        $tituloCredencial = trim($_POST['titulo'] ?? '');
        if ($tituloCredencial === '') {
            flash_set('erro', 'Informe um título/nome pra essa credencial.');
        } else {
            // Senha só é trocada se o campo vier preenchido -- em branco
            // mantém a senha atual (evita ter que redigitar só pra
            // corrigir o título/host de uma credencial já cadastrada).
            $novaSenha = trim($_POST['senha'] ?? '');
            if ($novaSenha !== '') {
                $pdo->prepare('UPDATE cliente_credenciais SET tipo=?, titulo=?, host=?, porta=?, usuario=?, senha_cifrada=?, observacoes=? WHERE id=? AND cliente_id=?')
                    ->execute([
                        $tipoCredencial, $tituloCredencial,
                        trim($_POST['host'] ?? '') ?: null, trim($_POST['porta'] ?? '') ?: null, trim($_POST['usuario'] ?? '') ?: null,
                        Crypto::cifrar($novaSenha), trim($_POST['observacoes'] ?? '') ?: null,
                        $credencialIdAlvo, $clienteId,
                    ]);
            } else {
                $pdo->prepare('UPDATE cliente_credenciais SET tipo=?, titulo=?, host=?, porta=?, usuario=?, observacoes=? WHERE id=? AND cliente_id=?')
                    ->execute([
                        $tipoCredencial, $tituloCredencial,
                        trim($_POST['host'] ?? '') ?: null, trim($_POST['porta'] ?? '') ?: null, trim($_POST['usuario'] ?? '') ?: null,
                        trim($_POST['observacoes'] ?? '') ?: null,
                        $credencialIdAlvo, $clienteId,
                    ]);
            }
            flash_set('sucesso', 'Credencial atualizada.');
        }
    }

    if ($acao === 'excluir_credencial') {
        $pdo->prepare('DELETE FROM cliente_credenciais WHERE id = ? AND cliente_id = ?')
            ->execute([(int) $_POST['credencial_id'], $clienteId]);
        flash_set('sucesso', 'Credencial removida.');
    }

    if ($acao === 'upload_vpn_arquivo' && isset($_FILES['arquivo'])) {
        $tituloVpn = trim($_POST['titulo'] ?? '');
        if ($tituloVpn === '') {
            flash_set('erro', 'Informe um título pra esse arquivo de VPN.');
        } elseif ($_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            flash_set('erro', 'Falha no upload do arquivo.');
        } elseif ($_FILES['arquivo']['size'] > UPLOADS_MAX_SIZE) {
            flash_set('erro', 'Arquivo maior que o limite permitido (' . (int) (UPLOADS_MAX_SIZE / 1024 / 1024) . 'MB).');
        } else {
            $dirClienteVpn = UPLOADS_DIR . '/clientes/' . $clienteId;
            if (!is_dir($dirClienteVpn)) {
                mkdir($dirClienteVpn, 0755, true);
            }
            $extensaoVpn = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
            $nomeArmazenadoVpn = bin2hex(random_bytes(16)) . ($extensaoVpn ? '.' . $extensaoVpn : '');
            $destinoVpn = $dirClienteVpn . '/' . $nomeArmazenadoVpn;

            if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destinoVpn)) {
                flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
            } else {
                $pdo->prepare('INSERT INTO cliente_vpn_arquivos (cliente_id, titulo, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $clienteId,
                        $tituloVpn,
                        $_FILES['arquivo']['name'],
                        'clientes/' . $clienteId . '/' . $nomeArmazenadoVpn,
                        $_FILES['arquivo']['size'],
                        file_get_contents($destinoVpn),
                        Auth::userId(),
                    ]);
                flash_set('sucesso', 'Arquivo de VPN cadastrado.');
            }
        }
    }

    if ($acao === 'excluir_vpn_arquivo') {
        $stmtVpnExcluir = $pdo->prepare('SELECT arquivo_caminho FROM cliente_vpn_arquivos WHERE id = ? AND cliente_id = ?');
        $stmtVpnExcluir->execute([(int) $_POST['arquivo_id'], $clienteId]);
        $caminhoVpnExcluir = $stmtVpnExcluir->fetchColumn();

        $pdo->prepare('DELETE FROM cliente_vpn_arquivos WHERE id = ? AND cliente_id = ?')
            ->execute([(int) $_POST['arquivo_id'], $clienteId]);

        if ($caminhoVpnExcluir) {
            @unlink(UPLOADS_DIR . '/' . $caminhoVpnExcluir);
        }
        flash_set('sucesso', 'Arquivo de VPN removido.');
    }

    if ($acao === 'upload_fonte_advpl' && isset($_FILES['arquivo'])) {
        $tituloFonte = trim($_POST['titulo'] ?? '');
        $setorIdFonte = (int) ($_POST['setor_id'] ?? 0);
        // Confirma que esse setor é mesmo desse cliente -- sem isso,
        // alguém poderia mandar o id de um setor de OUTRO cliente e a
        // fonte iria parar lá, fora do lugar (e visível pra quem não devia).
        $stmtSetorFonte = $pdo->prepare('SELECT 1 FROM setores WHERE id = ? AND cliente_id = ?');
        $stmtSetorFonte->execute([$setorIdFonte, $clienteId]);
        $setorPertenceAoCliente = (bool) $stmtSetorFonte->fetchColumn();
        if ($tituloFonte === '' || !$setorIdFonte) {
            flash_set('erro', 'Informe o nome da rotina e o setor.');
        } elseif (!$setorPertenceAoCliente) {
            flash_set('erro', 'Setor inválido.');
        } elseif ($_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            flash_set('erro', 'Falha no upload do arquivo.');
        } elseif ($_FILES['arquivo']['size'] > UPLOADS_MAX_SIZE) {
            flash_set('erro', 'Arquivo maior que o limite permitido (' . (int) (UPLOADS_MAX_SIZE / 1024 / 1024) . 'MB).');
        } else {
            $dirClienteFonte = UPLOADS_DIR . '/clientes/' . $clienteId;
            if (!is_dir($dirClienteFonte)) {
                mkdir($dirClienteFonte, 0755, true);
            }
            $extensaoFonte = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
            $nomeArmazenadoFonte = bin2hex(random_bytes(16)) . ($extensaoFonte ? '.' . $extensaoFonte : '');
            $destinoFonte = $dirClienteFonte . '/' . $nomeArmazenadoFonte;

            if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destinoFonte)) {
                flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
            } else {
                $pdo->prepare('INSERT INTO setor_fontes_advpl (setor_id, titulo, ponto_entrada, descricao, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $setorIdFonte,
                        $tituloFonte,
                        trim($_POST['ponto_entrada'] ?? '') ?: null,
                        trim($_POST['descricao'] ?? '') ?: null,
                        $_FILES['arquivo']['name'],
                        'clientes/' . $clienteId . '/' . $nomeArmazenadoFonte,
                        $_FILES['arquivo']['size'],
                        file_get_contents($destinoFonte),
                        Auth::userId(),
                    ]);
                flash_set('sucesso', 'Fonte ADVPL cadastrada.');
            }
        }
    }

    if ($acao === 'atualizar_fonte_advpl') {
        $fonteIdAlvo = (int) $_POST['fonte_id'];
        $tituloFonte = trim($_POST['titulo'] ?? '');

        // Mesmo cuidado do excluir_fonte_advpl: setor_fontes_advpl não tem
        // cliente_id direto, então o JOIN com setores confirma que essa
        // fonte é mesmo desse cliente antes de editar.
        $stmtFonteEditar = $pdo->prepare('
            SELECT f.arquivo_caminho FROM setor_fontes_advpl f
            INNER JOIN setores s ON s.id = f.setor_id
            WHERE f.id = ? AND s.cliente_id = ?
        ');
        $stmtFonteEditar->execute([$fonteIdAlvo, $clienteId]);
        $fonteAtual = $stmtFonteEditar->fetch();

        if (!$fonteAtual) {
            flash_set('erro', 'Fonte não encontrada.');
        } elseif ($tituloFonte === '') {
            flash_set('erro', 'Informe o nome da rotina.');
        } else {
            $pontoEntradaFonte = trim($_POST['ponto_entrada'] ?? '') ?: null;
            $descricaoFonte = trim($_POST['descricao'] ?? '') ?: null;

            // Arquivo novo é OPCIONAL na edição -- em branco mantém o
            // arquivo já cadastrado (mesma ideia da senha em branco no
            // editar_credencial), pra poder corrigir só o nome/ponto de
            // entrada sem precisar reenviar o fonte inteiro de novo.
            $temArquivoNovo = isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK && $_FILES['arquivo']['size'] > 0;

            if (!$temArquivoNovo) {
                $pdo->prepare('UPDATE setor_fontes_advpl SET titulo=?, ponto_entrada=?, descricao=? WHERE id=?')
                    ->execute([$tituloFonte, $pontoEntradaFonte, $descricaoFonte, $fonteIdAlvo]);
                flash_set('sucesso', 'Fonte ADVPL atualizada.');
            } elseif ($_FILES['arquivo']['size'] > UPLOADS_MAX_SIZE) {
                flash_set('erro', 'Arquivo maior que o limite permitido (' . (int) (UPLOADS_MAX_SIZE / 1024 / 1024) . 'MB).');
            } else {
                $dirClienteFonte = UPLOADS_DIR . '/clientes/' . $clienteId;
                if (!is_dir($dirClienteFonte)) {
                    mkdir($dirClienteFonte, 0755, true);
                }
                $extensaoFonte = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                $nomeArmazenadoFonte = bin2hex(random_bytes(16)) . ($extensaoFonte ? '.' . $extensaoFonte : '');
                $destinoFonte = $dirClienteFonte . '/' . $nomeArmazenadoFonte;

                if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destinoFonte)) {
                    flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
                } else {
                    $pdo->prepare('UPDATE setor_fontes_advpl SET titulo=?, ponto_entrada=?, descricao=?, arquivo_nome_original=?, arquivo_caminho=?, arquivo_tamanho=?, dados=? WHERE id=?')
                        ->execute([
                            $tituloFonte,
                            $pontoEntradaFonte,
                            $descricaoFonte,
                            $_FILES['arquivo']['name'],
                            'clientes/' . $clienteId . '/' . $nomeArmazenadoFonte,
                            $_FILES['arquivo']['size'],
                            file_get_contents($destinoFonte),
                            $fonteIdAlvo,
                        ]);
                    // Só apaga o arquivo antigo depois do UPDATE ter dado
                    // certo -- senão, numa falha no meio do caminho, o
                    // registro ficaria apontando pra um arquivo já apagado.
                    if ($fonteAtual['arquivo_caminho']) {
                        @unlink(UPLOADS_DIR . '/' . $fonteAtual['arquivo_caminho']);
                    }
                    flash_set('sucesso', 'Fonte ADVPL atualizada (arquivo substituído).');
                }
            }
        }
    }

    if ($acao === 'excluir_fonte_advpl') {
        // setor_fontes_advpl não tem cliente_id direto (só setor_id) -- o
        // JOIN com setores confirma que essa fonte é mesmo desse cliente
        // antes de excluir, senão alguém com a permissão especial num
        // cliente conseguiria apagar fonte de OUTRO cliente só sabendo o id.
        $stmtFonteExcluir = $pdo->prepare('
            SELECT f.arquivo_caminho FROM setor_fontes_advpl f
            INNER JOIN setores s ON s.id = f.setor_id
            WHERE f.id = ? AND s.cliente_id = ?
        ');
        $stmtFonteExcluir->execute([(int) $_POST['fonte_id'], $clienteId]);
        $fonteEncontrada = $stmtFonteExcluir->fetch();

        if ($fonteEncontrada) {
            $pdo->prepare('DELETE FROM setor_fontes_advpl WHERE id = ?')->execute([(int) $_POST['fonte_id']]);
            if ($fonteEncontrada['arquivo_caminho']) {
                @unlink(UPLOADS_DIR . '/' . $fonteEncontrada['arquivo_caminho']);
            }
            flash_set('sucesso', 'Fonte ADVPL removida.');
        } else {
            flash_set('erro', 'Fonte não encontrada.');
        }
    }

    // --- T-Cloud: links de ambiente (produção/teste) ---------------------
    // Um upsert só (INSERT ... ON DUPLICATE KEY UPDATE) na chave única
    // (cliente_id, tipo) -- o modal sempre manda os 4 links de uma vez
    // (mesmo os que já estavam preenchidos), então não precisa de UPDATE
    // parcial como a senha da credencial.
    if ($acao === 'salvar_tcloud_ambiente') {
        $tipoAmbienteTcloud = $_POST['tipo'] ?? '';
        if (!array_key_exists($tipoAmbienteTcloud, TCLOUD_AMBIENTES)) {
            flash_set('erro', 'Ambiente inválido.');
        } else {
            $pdo->prepare('
                INSERT INTO cliente_tcloud_ambientes (cliente_id, tipo, link_protheus, link_rest, link_schedule, link_exclusivo, atualizado_por)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    link_protheus = VALUES(link_protheus), link_rest = VALUES(link_rest),
                    link_schedule = VALUES(link_schedule), link_exclusivo = VALUES(link_exclusivo),
                    atualizado_por = VALUES(atualizado_por)
            ')->execute([
                $clienteId,
                $tipoAmbienteTcloud,
                trim($_POST['link_protheus'] ?? '') ?: null,
                trim($_POST['link_rest'] ?? '') ?: null,
                trim($_POST['link_schedule'] ?? '') ?: null,
                trim($_POST['link_exclusivo'] ?? '') ?: null,
                Auth::userId(),
            ]);
            flash_set('sucesso', 'Links de ' . TCLOUD_AMBIENTES[$tipoAmbienteTcloud] . ' salvos.');
        }
    }

    // --- T-Cloud: configurações de acesso via VSCode ----------------------
    if ($acao === 'criar_tcloud_vscode') {
        $tituloVscode = trim($_POST['titulo'] ?? '');
        if ($tituloVscode === '') {
            flash_set('erro', 'Informe um título pra essa configuração do VSCode.');
        } else {
            $pdo->prepare('INSERT INTO cliente_tcloud_vscode (cliente_id, titulo, link, porta, ambiente, criado_por) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $clienteId,
                    $tituloVscode,
                    trim($_POST['link'] ?? '') ?: null,
                    trim($_POST['porta'] ?? '') ?: null,
                    trim($_POST['ambiente'] ?? '') ?: null,
                    Auth::userId(),
                ]);
            flash_set('sucesso', 'Configuração do VSCode cadastrada.');
        }
    }

    if ($acao === 'atualizar_tcloud_vscode') {
        $vscodeIdAlvo = (int) $_POST['vscode_id'];
        $tituloVscode = trim($_POST['titulo'] ?? '');
        if ($tituloVscode === '') {
            flash_set('erro', 'Informe um título pra essa configuração do VSCode.');
        } else {
            $pdo->prepare('UPDATE cliente_tcloud_vscode SET titulo=?, link=?, porta=?, ambiente=? WHERE id=? AND cliente_id=?')
                ->execute([
                    $tituloVscode,
                    trim($_POST['link'] ?? '') ?: null,
                    trim($_POST['porta'] ?? '') ?: null,
                    trim($_POST['ambiente'] ?? '') ?: null,
                    $vscodeIdAlvo,
                    $clienteId,
                ]);
            flash_set('sucesso', 'Configuração do VSCode atualizada.');
        }
    }

    if ($acao === 'excluir_tcloud_vscode') {
        $pdo->prepare('DELETE FROM cliente_tcloud_vscode WHERE id = ? AND cliente_id = ?')
            ->execute([(int) $_POST['vscode_id'], $clienteId]);
        flash_set('sucesso', 'Configuração do VSCode removida.');
    }

    // --- Sugestões de melhoria por setor, geradas com IA -----------------
    // Ver src/AiSugestoes.php -- monta um prompt com o nome do setor, os
    // chamados mais recentes (título/categoria) e os KPIs registrados, pede
    // pra IA analisar se o processo parece saudável e sugerir melhorias.
    // Sem chave configurada, avisa em vez de travar (mesmo padrão do
    // WhatsApp).
    if ($acao === 'gerar_sugestao_ia') {
        $ehAjaxSugestaoIa = ($_POST['ajax'] ?? '') === '1';
        $setorIdSugestao = (int) $_POST['setor_id'];
        $stmtSetorSugestao = $pdo->prepare('SELECT nome FROM setores WHERE id = ? AND cliente_id = ?');
        $stmtSetorSugestao->execute([$setorIdSugestao, $clienteId]);
        $nomeSetorSugestao = $stmtSetorSugestao->fetchColumn();

        $respostaSugestaoIa = ['sucesso' => false, 'mensagem' => 'Setor não encontrado.', 'sugestao' => null, 'gerado_em' => null];

        if ($nomeSetorSugestao) {
            // Escopo bem definido do que a IA analisa desse setor: os
            // últimos 25 chamados (título/categoria/status/prioridade) e os
            // últimos 15 KPIs registrados -- nada além disso (não lê
            // documentos, propostas ou conversas).
            $stmtChamadosSugestao = $pdo->prepare('SELECT titulo, categoria, status, prioridade FROM chamados WHERE setor_id = ? ORDER BY criado_em DESC LIMIT 25');
            $stmtChamadosSugestao->execute([$setorIdSugestao]);
            $chamadosSugestao = $stmtChamadosSugestao->fetchAll();

            $stmtKpisSugestao = $pdo->prepare('SELECT nome_kpi, valor, meta, unidade, periodo FROM kpis WHERE setor_id = ? ORDER BY periodo DESC LIMIT 15');
            $stmtKpisSugestao->execute([$setorIdSugestao]);
            $kpisSugestao = $stmtKpisSugestao->fetchAll();

            $prompt = "Você é um consultor de processos de uma consultoria de ERP (TOTVS Protheus). "
                . "Analise o setor \"{$nomeSetorSugestao}\" do cliente \"{$cliente['nome']}\" com base nos dados abaixo "
                . "e responda em português, de forma direta e prática, em até 6 tópicos: "
                . "(1) um diagnóstico rápido de como está o processo desse setor, "
                . "(2) problemas ou padrões preocupantes que você identificou (se houver), "
                . "(3) sugestões concretas de melhoria.\n\n"
                . "Chamados recentes desse setor (título / categoria / status / prioridade):\n";
            foreach ($chamadosSugestao as $c) {
                $prompt .= "- {$c['titulo']} | {$c['categoria']} | {$c['status']} | {$c['prioridade']}\n";
            }
            if (!$chamadosSugestao) {
                $prompt .= "(nenhum chamado registrado ainda)\n";
            }
            $prompt .= "\nKPIs registrados desse setor (nome / valor / meta / unidade / período):\n";
            foreach ($kpisSugestao as $k) {
                $prompt .= "- {$k['nome_kpi']} | {$k['valor']} | " . ($k['meta'] ?? '-') . " | {$k['unidade']} | {$k['periodo']}\n";
            }
            if (!$kpisSugestao) {
                $prompt .= "(nenhum KPI registrado ainda)\n";
            }

            $resultadoIa = AiSugestoes::gerar($prompt);
            if (!$resultadoIa['ok']) {
                $respostaSugestaoIa['mensagem'] = 'Não foi possível gerar a sugestão: ' . $resultadoIa['erro'];
            } else {
                $pdo->prepare('INSERT INTO setor_sugestoes_ia (setor_id, sugestao, criado_por) VALUES (?, ?, ?)')
                    ->execute([$setorIdSugestao, $resultadoIa['texto'], Auth::userId()]);
                $respostaSugestaoIa = [
                    'sucesso' => true,
                    'mensagem' => 'Sugestão gerada.',
                    'sugestao' => $resultadoIa['texto'],
                    'gerado_em' => formatar_data(date('Y-m-d H:i:s')),
                ];
            }
        }

        if ($ehAjaxSugestaoIa) {
            header('Content-Type: application/json');
            echo json_encode($respostaSugestaoIa, JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_set($respostaSugestaoIa['sucesso'] ? 'sucesso' : 'erro', $respostaSugestaoIa['mensagem']);
    }

    // --- Aba "Análise de Risco" -------------------------------------------
    // Mesma chave Gemini de análise de sempre (AiSugestoes::gerar(), só lê
    // dados JÁ CADASTRADOS -- nunca busca na internet, fica 100% no plano
    // gratuito). Monta um raio-x do histórico de chamados (do cliente
    // inteiro ou só de um setor, ver $setorIdAnaliseRisco) e pede pra IA um
    // de dois tipos de documento BEM diferentes -- ver $tipoAnaliseRisco:
    // 'risco' é uso interno da Prime (nível de risco de perda do cliente,
    // sinais preocupantes, sugestões de melhoria NO NOSSO atendimento);
    // 'melhorias' é um relatório pra ENTREGAR ao próprio cliente (tom
    // profissional, sem mencionar risco de cancelamento nem atendente
    // específico -- pedido explícito do Kleber pra nunca vazar linguagem
    // interna num documento que o cliente vai ler).
    if ($acao === 'gerar_analise_risco') {
        $ehAjaxAnaliseRisco = ($_POST['ajax'] ?? '') === '1';
        $tipoAnaliseRisco = ($_POST['tipo'] ?? '') === 'melhorias' ? 'melhorias' : 'risco';
        $setorIdAnaliseRisco = (int) ($_POST['setor_id'] ?? 0) ?: null;
        $respostaAnaliseRisco = ['sucesso' => false, 'mensagem' => 'Não foi possível gerar a análise.', 'analise' => null, 'gerado_em' => null];

        $nomeSetorAnaliseRisco = null;
        if ($setorIdAnaliseRisco) {
            $stmtSetorAnaliseRisco = $pdo->prepare('SELECT nome FROM setores WHERE id = ? AND cliente_id = ?');
            $stmtSetorAnaliseRisco->execute([$setorIdAnaliseRisco, $clienteId]);
            $nomeSetorAnaliseRisco = $stmtSetorAnaliseRisco->fetchColumn() ?: null;
            if (!$nomeSetorAnaliseRisco) {
                $setorIdAnaliseRisco = null;
            }
        }
        $escopoAnaliseRisco = $nomeSetorAnaliseRisco ? "setor \"{$nomeSetorAnaliseRisco}\" do cliente \"{$cliente['nome']}\"" : "cliente \"{$cliente['nome']}\" (todos os setores)";
        $filtroSetorSql = $setorIdAnaliseRisco ? ' AND setor_id = ?' : '';
        $parametrosFiltroSetor = $setorIdAnaliseRisco ? [$clienteId, $setorIdAnaliseRisco] : [$clienteId];

        $stmtAgregadoRisco = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'aberto') AS abertos,
                SUM(status = 'aguardando_equipe') AS aguardando_equipe,
                SUM(status = 'aguardando_cliente') AS aguardando_cliente,
                SUM(status = 'fechado') AS fechados,
                SUM(prioridade = 'urgente') AS urgentes,
                SUM(prioridade = 'alta') AS altas,
                SUM(criado_em >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS ultimos_90_dias,
                AVG(CASE WHEN fechado_em IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, criado_em, fechado_em) END) AS tempo_medio_min
            FROM chamados WHERE cliente_id = ?{$filtroSetorSql}
        ");
        $stmtAgregadoRisco->execute($parametrosFiltroSetor);
        $agregadoRisco = $stmtAgregadoRisco->fetch();

        $stmtTendenciaRisco = $pdo->prepare("
            SELECT DATE_FORMAT(criado_em, '%Y-%m') AS mes, COUNT(*) AS qtd
            FROM chamados WHERE cliente_id = ?{$filtroSetorSql} AND criado_em >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY mes ORDER BY mes ASC
        ");
        $stmtTendenciaRisco->execute($parametrosFiltroSetor);
        $tendenciaRisco = $stmtTendenciaRisco->fetchAll();

        $stmtAbertosAntigosRisco = $pdo->prepare("
            SELECT titulo, status, prioridade, criado_em FROM chamados
            WHERE cliente_id = ?{$filtroSetorSql} AND status != 'fechado'
            ORDER BY criado_em ASC LIMIT 5
        ");
        $stmtAbertosAntigosRisco->execute($parametrosFiltroSetor);
        $abertosAntigosRisco = $stmtAbertosAntigosRisco->fetchAll();

        $stmtRecentesRisco = $pdo->prepare("SELECT titulo, categoria, status, prioridade, criado_em, fechado_em FROM chamados WHERE cliente_id = ?{$filtroSetorSql} ORDER BY criado_em DESC LIMIT 25");
        $stmtRecentesRisco->execute($parametrosFiltroSetor);
        $recentesRisco = $stmtRecentesRisco->fetchAll();

        $stmtTreinamentosRisco = $pdo->prepare("SELECT COUNT(*) FROM treinamentos WHERE cliente_id = ? AND data_inicio >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)");
        $stmtTreinamentosRisco->execute([$clienteId]);
        $treinamentosRecentesRisco = (int) $stmtTreinamentosRisco->fetchColumn();

        if ($tipoAnaliseRisco === 'melhorias') {
            $prompt = "Você é um consultor de processos redigindo um RELATÓRIO OFICIAL que será entregue diretamente ao {$escopoAnaliseRisco}. "
                . "ATENÇÃO: esse texto vai ser lido pelo cliente -- use um tom profissional, construtivo e respeitoso. "
                . "NUNCA mencione risco de cancelamento/perda do cliente, NUNCA cite nome de atendente específico da Prime, "
                . "NUNCA faça comentário interno, negativo ou fora de contexto sobre nossa equipe ou processo interno. "
                . "Baseado nos temas mais recorrentes dos chamados abaixo, responda em português, em Markdown, com estas seções:\n"
                . "## Resumo\n2-3 frases sobre como está o uso do sistema/processo nessa área.\n"
                . "## Oportunidades de melhoria identificadas\nPadrões ou temas recorrentes que sugerem espaço pra melhorar (ex.: retrabalho, dúvidas repetidas, processo manual que poderia ser automatizado).\n"
                . "## Recomendações\nAções práticas que O CLIENTE pode tomar (treinamento da equipe dele, revisão de processo interno, boas práticas de uso do ERP).\n\n"
                . "Temas dos chamados mais recentes (título / categoria):\n";
            foreach ($recentesRisco as $r) {
                $prompt .= "- {$r['titulo']} | {$r['categoria']}\n";
            }
            if (!$recentesRisco) {
                $prompt .= "(nenhum chamado registrado ainda)\n";
            }
        } else {
            $prompt = "Você é um consultor de Customer Success de uma consultoria de ERP (TOTVS Protheus). "
                . "Com base nos dados abaixo sobre o {$escopoAnaliseRisco}, avalie o RISCO desse cliente cancelar ou "
                . "deixar de renovar o contrato, e responda em português, em Markdown, com estas seções:\n"
                . "## Nível de risco\nUma palavra (Baixo, Médio, Alto ou Crítico) seguida de 1-2 frases justificando.\n"
                . "## Sinais de risco identificados\nLista dos padrões preocupantes que você viu nos dados (se não houver nenhum, diga isso).\n"
                . "## Sugestões de melhoria\nAções concretas de melhoria no NOSSO atendimento e/ou processo pra reduzir esse risco.\n\n"
                . "Resumo geral de chamados:\n"
                . "- Total de chamados: {$agregadoRisco['total']}\n"
                . "- Abertos: {$agregadoRisco['abertos']} | Aguardando nossa equipe: {$agregadoRisco['aguardando_equipe']} | Aguardando o cliente: {$agregadoRisco['aguardando_cliente']} | Fechados: {$agregadoRisco['fechados']}\n"
                . "- Prioridade urgente: {$agregadoRisco['urgentes']} | Prioridade alta: {$agregadoRisco['altas']}\n"
                . "- Chamados nos últimos 90 dias: {$agregadoRisco['ultimos_90_dias']}\n"
                . "- Tempo médio de atendimento (abertura até fechamento): " . ($agregadoRisco['tempo_medio_min'] !== null ? formatar_duracao_minutos((float) $agregadoRisco['tempo_medio_min']) : 'sem chamados fechados ainda') . "\n"
                . "- Treinamentos/reuniões nos últimos 6 meses: {$treinamentosRecentesRisco}\n\n"
                . "Tendência mensal de chamados abertos (últimos 6 meses, mês/quantidade):\n";
            foreach ($tendenciaRisco as $t) {
                $prompt .= "- {$t['mes']}: {$t['qtd']}\n";
            }
            if (!$tendenciaRisco) {
                $prompt .= "(nenhum chamado nos últimos 6 meses)\n";
            }
            $prompt .= "\nChamados ainda NÃO fechados, do mais antigo pro mais novo (título / status / prioridade / aberto em):\n";
            foreach ($abertosAntigosRisco as $a) {
                $prompt .= "- {$a['titulo']} | {$a['status']} | {$a['prioridade']} | " . formatar_data($a['criado_em']) . "\n";
            }
            if (!$abertosAntigosRisco) {
                $prompt .= "(nenhum chamado aberto no momento)\n";
            }
            $prompt .= "\nÚltimos chamados (título / categoria / status / prioridade / aberto em / fechado em):\n";
            foreach ($recentesRisco as $r) {
                $prompt .= "- {$r['titulo']} | {$r['categoria']} | {$r['status']} | {$r['prioridade']} | " . formatar_data($r['criado_em']) . ' | ' . ($r['fechado_em'] ? formatar_data($r['fechado_em']) : 'ainda não fechado') . "\n";
            }
            if (!$recentesRisco) {
                $prompt .= "(nenhum chamado registrado ainda)\n";
            }
        }

        $resultadoIaRisco = AiSugestoes::gerar($prompt);
        if (!$resultadoIaRisco['ok']) {
            $respostaAnaliseRisco['mensagem'] = 'Não foi possível gerar a análise: ' . $resultadoIaRisco['erro'];
        } else {
            $pdo->prepare('INSERT INTO cliente_analise_risco (cliente_id, tipo, setor_id, analise, criado_por) VALUES (?, ?, ?, ?, ?)')
                ->execute([$clienteId, $tipoAnaliseRisco, $setorIdAnaliseRisco, $resultadoIaRisco['texto'], Auth::userId()]);
            $respostaAnaliseRisco = [
                'sucesso' => true,
                'mensagem' => 'Análise gerada.',
                'id' => (int) $pdo->lastInsertId(),
                'tipo' => $tipoAnaliseRisco,
                'setor_nome' => $nomeSetorAnaliseRisco,
                'analise' => $resultadoIaRisco['texto'],
                'gerado_em' => formatar_data(date('Y-m-d H:i:s')),
            ];
        }

        if ($ehAjaxAnaliseRisco) {
            header('Content-Type: application/json');
            echo json_encode($respostaAnaliseRisco, JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_set($respostaAnaliseRisco['sucesso'] ? 'sucesso' : 'erro', $respostaAnaliseRisco['mensagem']);
    }

    // Cada análise agora é um CARD independente (ver aba Análise de Risco)
    // -- editar ATUALIZA o próprio registro (id), não cria mais uma versão
    // nova no histórico.
    if ($acao === 'atualizar_analise_risco') {
        $idAtualizarAnaliseRisco = (int) ($_POST['id'] ?? 0);
        $textoAtualizarAnaliseRisco = trim($_POST['texto'] ?? '');
        if ($textoAtualizarAnaliseRisco === '') {
            flash_set('erro', 'Não tem texto pra salvar.');
        } else {
            $pdo->prepare('UPDATE cliente_analise_risco SET analise = ?, atualizado_em = NOW() WHERE id = ? AND cliente_id = ?')
                ->execute([$textoAtualizarAnaliseRisco, $idAtualizarAnaliseRisco, $clienteId]);
            flash_set('sucesso', 'Alterações salvas.');
        }
    }

    // Exclui um card de análise específico -- sem confirmação server-side
    // (o JS já pede confirm() antes de mandar o POST).
    if ($acao === 'excluir_analise_risco') {
        $idExcluirAnaliseRisco = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM cliente_analise_risco WHERE id = ? AND cliente_id = ?')->execute([$idExcluirAnaliseRisco, $clienteId]);
        flash_set('sucesso', 'Análise excluída.');
    }

    // Exporta uma análise (de risco OU melhorias, cliente inteiro ou setor)
    // em PDF, com o mesmo timbrado usado em qualquer outro export do
    // sistema (ver src/PdfExport.php) -- exporta o texto que veio no POST
    // direto do card/modal, pra incluir qualquer edição manual não salva
    // ainda.
    if ($acao === 'exportar_pdf_analise_risco') {
        $textoAnaliseRiscoPdf = (string) ($_POST['texto'] ?? '');
        $labelAnaliseRiscoPdf = trim((string) ($_POST['label'] ?? 'Análise de Risco'));

        if ($textoAnaliseRiscoPdf === '') {
            http_response_code(400);
            die('Nada pra exportar.');
        }

        $pdfAnaliseRisco = new PdfExport($labelAnaliseRiscoPdf . ' - ' . $cliente['nome']);
        $pdfAnaliseRisco->subtitulo('Gerado em ' . formatar_data(date('Y-m-d H:i:s')));
        $pdfAnaliseRisco->escreverMarkdown($textoAnaliseRiscoPdf);

        $nomeArquivoAnaliseRiscoPdf = preg_replace('/[^a-z0-9]+/i', '-', $labelAnaliseRiscoPdf . '-' . $cliente['nome']) . '-' . $clienteId . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivoAnaliseRiscoPdf . '"');
        echo $pdfAnaliseRisco->bytes();
        exit;
    }

    // --- Perfil público da empresa via IA + busca no Google --------------
    // Diferente da sugestão por setor acima (que só lê dados JÁ
    // CADASTRADOS): aqui a IA pesquisa na internet pelo nome/CNPJ da
    // empresa. Pedido do Kleber depois de perguntar se a IA "não pode
    // pesquisar sobre o cliente já que tem o CNPJ dela".
    if ($acao === 'pesquisar_perfil_empresa') {
        $ehAjaxPerfilEmpresa = ($_POST['ajax'] ?? '') === '1';
        $resultadoPerfilEmpresa = AiSugestoes::pesquisarPerfilEmpresa($cliente['nome'], $cliente['cnpj']);

        if ($resultadoPerfilEmpresa['ok']) {
            $pdo->prepare('UPDATE clientes SET perfil_ia = ?, perfil_ia_gerado_em = NOW() WHERE id = ?')
                ->execute([$resultadoPerfilEmpresa['texto'], $clienteId]);
            $respostaPerfilEmpresa = [
                'sucesso' => true,
                'mensagem' => 'Perfil pesquisado.',
                'perfil' => $resultadoPerfilEmpresa['texto'],
                'gerado_em' => formatar_data(date('Y-m-d H:i:s')),
            ];
        } else {
            $respostaPerfilEmpresa = [
                'sucesso' => false,
                'mensagem' => 'Não foi possível pesquisar o perfil: ' . $resultadoPerfilEmpresa['erro'],
                'perfil' => null,
                'gerado_em' => null,
            ];
        }

        if ($ehAjaxPerfilEmpresa) {
            header('Content-Type: application/json');
            echo json_encode($respostaPerfilEmpresa, JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_set($respostaPerfilEmpresa['sucesso'] ? 'sucesso' : 'erro', $respostaPerfilEmpresa['mensagem']);
    }

    if ($acao === 'upload_documento' && isset($_FILES['arquivo'])) {
        $erroUploadDocumento = null;
        $idDocumentoNovo = salvar_documento_upload(
            $pdo,
            $clienteId,
            $_POST['setor_id'] ?: null,
            trim($_POST['titulo']),
            trim($_POST['descricao']) ?: null,
            trim($_POST['categoria']) ?: null,
            $_FILES['arquivo'],
            Auth::userId(),
            $erroUploadDocumento
        );
        flash_set($idDocumentoNovo ? 'sucesso' : 'erro', $idDocumentoNovo ? 'Documento enviado.' : $erroUploadDocumento);
    }

    // --- Implantação (aba Implantação) ------------------------------------
    // Checklist de etapas do onboarding desse cliente -- nível CLIENTE
    // (processo único da empresa toda), não por setor.

    if ($acao === 'criar_etapa_implantacao') {
        $tituloEtapa = trim($_POST['titulo'] ?? '');
        if ($tituloEtapa === '') {
            flash_set('erro', 'Informe o título da etapa.');
        } else {
            $stmtOrdemMax = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) FROM implantacao_etapas WHERE cliente_id = ?');
            $stmtOrdemMax->execute([$clienteId]);
            $ordemNova = (int) $stmtOrdemMax->fetchColumn() + 1;
            $pdo->prepare('INSERT INTO implantacao_etapas (cliente_id, titulo, descricao, data_prevista, ordem) VALUES (?, ?, ?, ?, ?)')
                ->execute([$clienteId, $tituloEtapa, trim($_POST['descricao'] ?? '') ?: null, $_POST['data_prevista'] ?: null, $ordemNova]);
            flash_set('sucesso', 'Etapa adicionada.');
        }
    }

    if ($acao === 'atualizar_etapa_implantacao') {
        $etapaId = (int) $_POST['etapa_id'];
        $stmtEtapaAtual = $pdo->prepare('SELECT status, data_concluida FROM implantacao_etapas WHERE id = ? AND cliente_id = ?');
        $stmtEtapaAtual->execute([$etapaId, $clienteId]);
        $etapaAtual = $stmtEtapaAtual->fetch();
        $tituloEtapa = trim($_POST['titulo'] ?? '');

        if (!$etapaAtual) {
            flash_set('erro', 'Etapa não encontrada.');
        } elseif ($tituloEtapa === '') {
            flash_set('erro', 'Informe o título da etapa.');
        } else {
            $novoStatusEtapa = in_array($_POST['status'] ?? '', ['pendente', 'em_andamento', 'concluida'], true) ? $_POST['status'] : 'pendente';
            // Só "renova" a data de conclusão se ela ainda não tinha uma --
            // senão editar só o título/descrição depois de já concluída
            // ficaria empurrando a data pra hoje toda vez.
            $dataConcluidaEtapa = $novoStatusEtapa === 'concluida' ? ($etapaAtual['data_concluida'] ?: date('Y-m-d')) : null;
            $pdo->prepare('UPDATE implantacao_etapas SET titulo=?, descricao=?, status=?, data_prevista=?, data_concluida=? WHERE id=?')
                ->execute([$tituloEtapa, trim($_POST['descricao'] ?? '') ?: null, $novoStatusEtapa, $_POST['data_prevista'] ?: null, $dataConcluidaEtapa, $etapaId]);
            flash_set('sucesso', 'Etapa atualizada.');
        }
    }

    // Não é destrutiva pros anexos -- eles só perdem o vínculo com a etapa
    // (mesma filosofia de excluir_setor).
    if ($acao === 'excluir_etapa_implantacao') {
        $etapaId = (int) $_POST['etapa_id'];
        $confereEtapa = $pdo->prepare('SELECT 1 FROM implantacao_etapas WHERE id = ? AND cliente_id = ?');
        $confereEtapa->execute([$etapaId, $clienteId]);
        if ($confereEtapa->fetchColumn()) {
            $pdo->prepare('UPDATE documentos SET implantacao_etapa_id = NULL WHERE implantacao_etapa_id = ?')->execute([$etapaId]);
            $pdo->prepare('DELETE FROM implantacao_etapas WHERE id = ?')->execute([$etapaId]);
            flash_set('sucesso', 'Etapa excluída. Os anexos dela continuam salvos, só sem vínculo.');
        }
    }

    if ($acao === 'reordenar_etapa_implantacao') {
        $etapaId = (int) $_POST['etapa_id'];
        $sentido = $_POST['sentido'] === 'up' ? 'up' : 'down';
        $stmtOrdemTodas = $pdo->prepare('SELECT id, ordem FROM implantacao_etapas WHERE cliente_id = ? ORDER BY ordem ASC, id ASC');
        $stmtOrdemTodas->execute([$clienteId]);
        $todasEtapas = $stmtOrdemTodas->fetchAll();
        $indiceEtapa = null;
        foreach ($todasEtapas as $i => $et) {
            if ((int) $et['id'] === $etapaId) {
                $indiceEtapa = $i;
                break;
            }
        }
        if ($indiceEtapa !== null) {
            $indiceAlvo = $sentido === 'up' ? $indiceEtapa - 1 : $indiceEtapa + 1;
            if (isset($todasEtapas[$indiceAlvo])) {
                $pdo->prepare('UPDATE implantacao_etapas SET ordem = ? WHERE id = ?')->execute([$todasEtapas[$indiceAlvo]['ordem'], $etapaId]);
                $pdo->prepare('UPDATE implantacao_etapas SET ordem = ? WHERE id = ?')->execute([$todasEtapas[$indiceEtapa]['ordem'], $todasEtapas[$indiceAlvo]['id']]);
            }
        }
    }

    if ($acao === 'upload_documento_implantacao' && !empty($_FILES['arquivos']['name'][0])) {
        $etapaIdAnexo = $_POST['etapa_id'] !== '' ? (int) $_POST['etapa_id'] : null;
        $errosUploadImplantacao = salvar_documentos_implantacao($pdo, $clienteId, $etapaIdAnexo, $_FILES['arquivos'], Auth::userId());
        flash_set($errosUploadImplantacao ? 'erro' : 'sucesso', $errosUploadImplantacao ? implode(' ', $errosUploadImplantacao) : 'Anexo(s) enviado(s).');
    }

    // IA: várias sugestões diferentes pro processo de implantação (não só
    // uma) -- cada tipo gera e guarda separado, pra dar pra ter todas
    // geradas ao mesmo tempo sem uma sobrescrever a outra.
    if ($acao === 'gerar_sugestao_implantacao') {
        // Um documento POR CHAMADA (não mais todos juntos numa única
        // chamada síncrona ao Gemini) -- pedido do Kleber: com vários
        // documentos grandes de uma vez, o tempo de resposta somado batia no
        // timeout do proxy da hospedagem antes do PHP terminar. O JS (ver
        // gerarSugestoesImplantacaoSequencial()) chama essa ação uma vez pra
        // cada documento selecionado, em sequência, e vai atualizando a
        // tela conforme cada resposta chega. 90s é bem mais que suficiente
        // pra UM documento só (testado na prática: 4-8s de verdade).
        set_time_limit(90);
        $ehAjaxImplantacaoIa = ($_POST['ajax'] ?? '') === '1';
        $tipoSugestaoImplantacao = $_POST['tipo'] ?? '';
        $forcarSemEtapasImplantacaoIa = ($_POST['forcar_sem_etapas'] ?? '') === '1';
        // 0 = seção "geral" (sem documento específico, só o contexto
        // cadastrado) -- usado quando não há nenhum documento anexado ainda.
        $documentoIdImplantacaoIa = (int) ($_POST['documento_id'] ?? 0);
        $ordemImplantacaoIa = (int) ($_POST['ordem'] ?? 0);

        $tiposSugestaoImplantacao = [
            'treinamento' => 'um roteiro de treinamento pros usuários da empresa aprenderem a usar o sistema, dividido por perfil/setor quando fizer sentido',
            'teste' => 'um roteiro de testes (homologação) pra validar se o sistema está configurado certo antes de ir pro ar, cobrindo os fluxos mais críticos',
            'boas_praticas' => 'uma lista de boas práticas de implantação de ERP aplicadas a esse cliente específico (não genérica)',
            'migracao_dados' => 'um checklist de dados cadastrais que normalmente precisam ser migrados e validados nessa fase (clientes, produtos/serviços, fornecedores, saldos, tabelas de preço, etc.)',
            'comunicacao' => 'um plano de comunicação com os usuários finais da empresa sobre a mudança de sistema (gestão de mudança), incluindo o que comunicar e quando',
            'riscos' => 'uma análise de riscos e pontos de atenção específicos dessa implantação, com base no que já foi cadastrado',
            'go_live' => 'um checklist de critérios objetivos pra considerar que essa implantação está pronta pra "ir ao ar" (go-live)',
        ];

        if (!isset($tiposSugestaoImplantacao[$tipoSugestaoImplantacao])) {
            $respostaImplantacaoIa = ['sucesso' => false, 'mensagem' => 'Tipo de sugestão inválido.', 'sugestao' => null, 'gerado_em' => null];
        } else {
            $stmtEtapasImplantacaoIa = $pdo->prepare('SELECT titulo, status FROM implantacao_etapas WHERE cliente_id = ? ORDER BY ordem ASC');
            $stmtEtapasImplantacaoIa->execute([$clienteId]);
            $etapasImplantacaoIa = $stmtEtapasImplantacaoIa->fetchAll();

            // Sem etapa cadastrada: avisa (não bloqueia) -- o admin decide se
            // quer seguir sem elas ou cadastrar as etapas primeiro.
            if (!$etapasImplantacaoIa && !$forcarSemEtapasImplantacaoIa) {
                $respostaImplantacaoIa = [
                    'sucesso' => false,
                    'precisa_confirmar_sem_etapas' => true,
                    'mensagem' => 'Nenhuma etapa de implantação foi cadastrada ainda pra esse cliente. Cadastre as etapas primeiro pra sugestões mais precisas, ou confirme pra gerar assim mesmo.',
                    'sugestao' => null,
                    'gerado_em' => null,
                ];
            } else {
                $stmtSetoresImplantacaoIa = $pdo->prepare('SELECT nome FROM setores WHERE cliente_id = ? AND ativo = 1');
                $stmtSetoresImplantacaoIa->execute([$clienteId]);
                $nomesSetoresImplantacaoIa = $stmtSetoresImplantacaoIa->fetchAll(PDO::FETCH_COLUMN);

                $stmtQtdUsuariosImplantacaoIa = $pdo->prepare('SELECT COUNT(*) FROM setor_responsaveis WHERE cliente_id = ?');
                $stmtQtdUsuariosImplantacaoIa->execute([$clienteId]);
                $qtdUsuariosImplantacaoIa = (int) $stmtQtdUsuariosImplantacaoIa->fetchColumn();

                // O UM documento dessa chamada (ownership-safe -- só pega se
                // for da categoria Implantação E desse mesmo cliente, mesmo
                // que o POST venha com id de outro documento/cliente).
                $docImplantacaoIa = null;
                if ($documentoIdImplantacaoIa > 0) {
                    $stmtDocImplantacaoIa = $pdo->prepare("SELECT titulo, arquivo_nome_original, arquivo_tamanho, dados FROM documentos WHERE cliente_id = ? AND categoria = 'Implantação' AND id = ?");
                    $stmtDocImplantacaoIa->execute([$clienteId, $documentoIdImplantacaoIa]);
                    $docImplantacaoIa = $stmtDocImplantacaoIa->fetch();
                }

                $nomeSecaoImplantacaoIa = $docImplantacaoIa
                    ? $docImplantacaoIa['arquivo_nome_original']
                    : 'Visão geral (setores, etapas e usuários cadastrados)';

                $mimesAceitosImplantacaoIa = [
                    'pdf' => 'application/pdf',
                    'txt' => 'text/plain',
                    'csv' => 'text/csv',
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                ];
                $arquivosImplantacaoIa = [];
                $textoDocxImplantacaoIa = '';
                $documentoIgnoradoImplantacaoIa = null;

                if ($docImplantacaoIa) {
                    $extensaoDocIa = strtolower(pathinfo($docImplantacaoIa['arquivo_nome_original'], PATHINFO_EXTENSION));

                    // .docx não é aceito pelo Gemini (só PDF/imagem/texto/
                    // áudio/vídeo) -- muito comum vir diagrama de processo
                    // em Word, então extrai o texto aqui e manda como texto
                    // puro junto do prompt em vez de simplesmente ignorar.
                    if ($extensaoDocIa === 'docx') {
                        $textoExtraidoDocx = extrair_texto_docx($docImplantacaoIa['dados']);
                        if ($textoExtraidoDocx) {
                            $textoDocxImplantacaoIa = mb_substr($textoExtraidoDocx, 0, 20000);
                        } else {
                            $documentoIgnoradoImplantacaoIa = $docImplantacaoIa['arquivo_nome_original'] . ' (não deu pra extrair o texto do Word)';
                        }
                    } else {
                        // Anexo vai pela Files API do Gemini (ver
                        // AiSugestoes::gerar()) -- uma chamada, um documento,
                        // sem limite de tamanho somado (só o de cada
                        // arquivo, já validado no upload em si).
                        $mimeDocIa = $mimesAceitosImplantacaoIa[$extensaoDocIa] ?? null;
                        if ($mimeDocIa) {
                            $arquivosImplantacaoIa[] = ['mime' => $mimeDocIa, 'dados' => $docImplantacaoIa['dados'], 'nome' => $docImplantacaoIa['arquivo_nome_original']];
                        } else {
                            $documentoIgnoradoImplantacaoIa = $docImplantacaoIa['arquivo_nome_original'];
                        }
                    }
                }

                $promptImplantacao = "Você é um consultor de implantação de ERP (TOTVS Protheus) da consultoria PrimeERP, escrevendo um trecho de um documento OFICIAL que vai ser entregue ao cliente. "
                    . "Gere {$tiposSugestaoImplantacao[$tipoSugestaoImplantacao]} pro cliente \"{$cliente['nome']}\".\n\n"
                    . "FORMATO: responda em português, em MARKDOWN bem estruturado -- use \"##\" pra títulos de seção/bloco, \"**texto**\" pra negrito nos termos e rotinas importantes, listas numeradas (\"1.\", \"2.\"...) ou com \"-\" quando fizer sentido, e parágrafos curtos e claros entre os blocos. Tom CORPORATIVO, sério e técnico, mas fácil de digerir -- isso vai direto num PDF entregue ao cliente, sem edição manual depois.\n\n"
                    . "REGRA MAIS IMPORTANTE: tudo que você mencionar sobre o TOTVS Protheus (nome de módulo, rotina, tela, campo, parâmetro, sigla) precisa ser algo que REALMENTE existe no Protheus -- nunca invente nome de módulo, função ou rotina que não existe, mesmo que pareça plausível. Se não tiver certeza absoluta se algo existe ou de como se chama no Protheus, NÃO invente: diga isso explicitamente (\"verificar com a equipe/documentação do Protheus qual é a rotina exata pra isso\") em vez de citar um nome específico chutado. Siga as boas práticas reais de implantação do Protheus, não de ERP genérico.\n\n"
                    . "Contexto desse cliente:\n"
                    . '- Setores cadastrados: ' . ($nomesSetoresImplantacaoIa ? implode(', ', $nomesSetoresImplantacaoIa) : 'nenhum setor cadastrado ainda') . "\n"
                    . "- Quantidade de pessoas/usuários cadastrados: {$qtdUsuariosImplantacaoIa}\n"
                    . "- Etapas de implantação já registradas nesse checklist:\n";
                if ($etapasImplantacaoIa) {
                    foreach ($etapasImplantacaoIa as $etIa) {
                        $promptImplantacao .= "  - {$etIa['titulo']} ({$etIa['status']})\n";
                    }
                } else {
                    $promptImplantacao .= "  (nenhuma etapa registrada ainda -- avise no início da resposta que seria melhor cadastrar as etapas antes, mas gere a sugestão de forma genérica sobre implantação de ERP mesmo assim)\n";
                }

                // ESTA chamada é focada em UM documento só (ou nenhum) --
                // pedido do Kleber pra processar documento por documento,
                // cada um virando uma SEÇÃO própria e autocontida do roteiro
                // final (que é montado juntando todas as seções depois, ver
                // mais abaixo). Sem isso, o Gemini tenderia a recapitular ou
                // se referir a "outros documentos" que ele nunca viu nessa
                // chamada específica.
                if ($arquivosImplantacaoIa) {
                    $promptImplantacao .= "\nEsta geração é focada EXCLUSIVAMENTE no documento \"{$nomeSecaoImplantacaoIa}\" (anexado nessa mesma mensagem) -- gere uma SEÇÃO específica e autocontida sobre o que ele traz de relevante pra essa sugestão, começando com um título curto (\"## \") que identifique o assunto principal do documento. Não recapitule nem se refira a \"outros documentos\" -- cada documento é processado numa chamada separada e as seções são juntadas depois automaticamente.\n";
                } elseif ($textoDocxImplantacaoIa !== '') {
                    $promptImplantacao .= "\nEsta geração é focada EXCLUSIVAMENTE no conteúdo extraído do documento Word \"{$nomeSecaoImplantacaoIa}\" abaixo -- gere uma SEÇÃO específica e autocontida sobre o que ele traz de relevante, começando com um título curto (\"## \") que identifique o assunto. Não recapitule nem se refira a \"outros documentos\".\nConteúdo extraído desse documento:\n{$textoDocxImplantacaoIa}\n";
                } else {
                    $promptImplantacao .= "\nNenhum documento específico foi anexado nesta geração -- escreva a seção inicial/geral (introdução + o que puder concluir só do contexto cadastrado acima).\n";
                }

                // Comentário/ajuste que o responsável da PrimeERP escreveu
                // no modal antes de gerar (ver modalDocsSugestaoImplantacao)
                // -- opcional, só entra no prompt se a pessoa escreveu algo.
                $comentarioImplantacaoIa = trim((string) ($_POST['comentario_ia'] ?? ''));
                if ($comentarioImplantacaoIa !== '') {
                    $promptImplantacao .= "\nComentário/ajuste do responsável da PrimeERP pra essa geração (siga isso com atenção, é mais importante que o resto do contexto): \"{$comentarioImplantacaoIa}\"\n";
                }

                $promptImplantacao .= "\nSe faltar alguma informação importante pra dar uma resposta específica (em vez de genérica), ou se você tiver alguma dúvida real sobre esse documento/cliente, liste isso primeiro, num bloco curto no topo da SEÇÃO com o título \"❓ Pontos a confirmar antes de aplicar isso:\" -- só inclua esse bloco se for realmente necessário, não invente dúvida à toa.";

                $resultadoImplantacaoIa = AiSugestoes::gerar($promptImplantacao, [], null, $arquivosImplantacaoIa);
                if (!$resultadoImplantacaoIa['ok']) {
                    $respostaImplantacaoIa = ['sucesso' => false, 'mensagem' => "Não foi possível gerar a seção \"{$nomeSecaoImplantacaoIa}\": " . $resultadoImplantacaoIa['erro'], 'sugestao' => null, 'gerado_em' => null];
                } else {
                    // Grava/substitui a seção DESSE documento (ver UNIQUE em
                    // Migrations::run()) e remonta o texto completo juntando
                    // TODAS as seções desse (cliente, tipo) na ordem em que
                    // foram processadas.
                    $pdo->prepare('
                        INSERT INTO implantacao_sugestoes_ia_secoes (cliente_id, tipo, documento_id, documento_nome, conteudo, ordem)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE documento_nome = VALUES(documento_nome), conteudo = VALUES(conteudo), ordem = VALUES(ordem)
                    ')->execute([$clienteId, $tipoSugestaoImplantacao, $documentoIdImplantacaoIa, $nomeSecaoImplantacaoIa, trim($resultadoImplantacaoIa['texto']), $ordemImplantacaoIa]);

                    $stmtSecoesImplantacaoIa = $pdo->prepare('SELECT conteudo FROM implantacao_sugestoes_ia_secoes WHERE cliente_id = ? AND tipo = ? ORDER BY ordem ASC, id ASC');
                    $stmtSecoesImplantacaoIa->execute([$clienteId, $tipoSugestaoImplantacao]);
                    $textoCompletoImplantacaoIa = implode("\n\n---\n\n", $stmtSecoesImplantacaoIa->fetchAll(PDO::FETCH_COLUMN));

                    $pdo->prepare('INSERT INTO implantacao_sugestoes_ia (cliente_id, tipo, sugestao, criado_por) VALUES (?, ?, ?, ?)')
                        ->execute([$clienteId, $tipoSugestaoImplantacao, $textoCompletoImplantacaoIa, Auth::userId()]);

                    $ignoradosImplantacaoIa = array_filter([$documentoIgnoradoImplantacaoIa]);
                    $ignoradosImplantacaoIa = array_merge($ignoradosImplantacaoIa, $resultadoImplantacaoIa['arquivos_com_falha'] ?? []);
                    $avisoIgnoradosImplantacaoIa = $ignoradosImplantacaoIa ? ' (não deu pra ler: ' . implode(', ', $ignoradosImplantacaoIa) . ')' : '';

                    $respostaImplantacaoIa = [
                        'sucesso' => true,
                        'mensagem' => "Seção \"{$nomeSecaoImplantacaoIa}\" processada.{$avisoIgnoradosImplantacaoIa}",
                        'sugestao' => $textoCompletoImplantacaoIa,
                        'gerado_em' => formatar_data(date('Y-m-d H:i:s')),
                    ];
                }
            }
        }

        if ($ehAjaxImplantacaoIa) {
            header('Content-Type: application/json');
            echo json_encode($respostaImplantacaoIa, JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set($respostaImplantacaoIa['sucesso'] ? 'sucesso' : 'erro', $respostaImplantacaoIa['mensagem']);
    }

    // Exporta o texto (já possivelmente editado pelo admin na textarea) de
    // uma sugestão de IA da Implantação em PDF timbrado -- mesmo timbrado
    // usado em qualquer outro export de PDF do sistema (ver PdfExport.php).
    if ($acao === 'exportar_pdf_sugestao_implantacao') {
        $labelSugestaoPdf = trim((string) ($_POST['label'] ?? 'Sugestão de implantação'));
        $textoSugestaoPdf = (string) ($_POST['texto'] ?? '');

        if ($textoSugestaoPdf === '') {
            http_response_code(400);
            die('Nada pra exportar.');
        }

        $pdfSugestao = new PdfExport($labelSugestaoPdf . ' - ' . $cliente['nome']);
        $pdfSugestao->subtitulo('Gerado em ' . formatar_data(date('Y-m-d H:i:s')));
        $pdfSugestao->escreverMarkdown($textoSugestaoPdf);

        $nomeArquivoPdf = 'implantacao-' . preg_replace('/[^a-z0-9]+/i', '-', $labelSugestaoPdf) . '-' . $clienteId . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivoPdf . '"');
        echo $pdfSugestao->bytes();
        exit;
    }

    // Apaga TODO o conteúdo já gerado (todas as seções por documento + todo
    // o histórico) pra esse tipo de sugestão, pra poder recomeçar do zero --
    // pedido do Kleber pra quando o roteiro acumulado ficar bagunçado.
    if ($acao === 'limpar_sugestao_implantacao') {
        $tipoLimparImplantacaoIa = $_POST['tipo'] ?? '';
        $tiposValidosImplantacaoIa = ['treinamento', 'teste', 'boas_praticas', 'migracao_dados', 'comunicacao', 'riscos', 'go_live'];

        if (!in_array($tipoLimparImplantacaoIa, $tiposValidosImplantacaoIa, true)) {
            flash_set('erro', 'Tipo de sugestão inválido.');
        } else {
            $pdo->prepare('DELETE FROM implantacao_sugestoes_ia_secoes WHERE cliente_id = ? AND tipo = ?')
                ->execute([$clienteId, $tipoLimparImplantacaoIa]);
            $pdo->prepare('DELETE FROM implantacao_sugestoes_ia WHERE cliente_id = ? AND tipo = ?')
                ->execute([$clienteId, $tipoLimparImplantacaoIa]);
            flash_set('sucesso', 'Sugestão limpa. Pode gerar de novo do zero.');
        }
    }

    // Salva o texto da sugestão como está na textarea (inclui qualquer edição
    // manual feita depois de gerada) -- sem isso, editar o texto e não fazer
    // mais nada faz a edição se perder no próximo reload (a textarea nunca
    // tinha sido persistida, só vivia no navegador). Vira uma nova entrada no
    // histórico (mesma tabela/uso de "gerar_sugestao_implantacao"), então
    // aparece como a versão mais recente. OBS: isso não altera as seções por
    // documento (implantacao_sugestoes_ia_secoes) -- gerar de novo a partir
    // de algum documento ainda reconstrói o texto a partir delas, então uma
    // edição manual livre pode ser sobrescrita por uma geração futura.
    if ($acao === 'salvar_edicao_sugestao_implantacao') {
        $tipoSalvarEdicaoImplantacaoIa = $_POST['tipo'] ?? '';
        $textoSalvarEdicaoImplantacaoIa = trim($_POST['texto'] ?? '');
        $tiposValidosImplantacaoIa = ['treinamento', 'teste', 'boas_praticas', 'migracao_dados', 'comunicacao', 'riscos', 'go_live'];

        if (!in_array($tipoSalvarEdicaoImplantacaoIa, $tiposValidosImplantacaoIa, true)) {
            flash_set('erro', 'Tipo de sugestão inválido.');
        } elseif ($textoSalvarEdicaoImplantacaoIa === '') {
            flash_set('erro', 'Não tem texto pra salvar.');
        } else {
            $pdo->prepare('INSERT INTO implantacao_sugestoes_ia (cliente_id, tipo, sugestao, criado_por) VALUES (?, ?, ?, ?)')
                ->execute([$clienteId, $tipoSalvarEdicaoImplantacaoIa, $textoSalvarEdicaoImplantacaoIa, Auth::userId()]);
            flash_set('sucesso', 'Alterações salvas.');
        }
    }

    if ($acao === 'enviar_convite_portal') {
        if (!$cliente['contato_email']) {
            flash_set('erro', 'Cadastre um e-mail de contato para o cliente antes de enviar o convite.');
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO cliente_senha_tokens (cliente_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$clienteId, $token]);
            notificar_depois(fn () => Notificacoes::conviteAcessoPortal($cliente, $token));
            flash_set('sucesso', 'Convite enviado para ' . $cliente['contato_email'] . '.');
        }
    }

    // Mesma ideia de "Resetar senha" do responsável de setor: zera a senha
    // na hora (invalida a atual) e já manda um convite novo -- antes só
    // existia pra contato master em usuarios.php, agora fica aqui também,
    // junto do resto do cadastro desse cliente.
    if ($acao === 'resetar_senha_master') {
        if (!$cliente['contato_email']) {
            flash_set('erro', 'Cadastre um e-mail de contato para o cliente antes de resetar a senha.');
        } else {
            $pdo->prepare('UPDATE clientes SET senha_hash = NULL WHERE id = ?')->execute([$clienteId]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO cliente_senha_tokens (cliente_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$clienteId, $token]);
            notificar_depois(fn () => Notificacoes::conviteAcessoPortal($cliente, $token));
            flash_set('sucesso', 'Senha resetada e novo link enviado para ' . $cliente['contato_email'] . '.');
        }
    }

    if ($acao === 'desativar_portal_master') {
        $pdo->prepare('UPDATE clientes SET portal_ativo = 0, senha_hash = NULL WHERE id = ?')->execute([$clienteId]);
        flash_set('sucesso', 'Acesso ao portal desativado.');
    }

    // Avatar do MASTER -- mesmas funções já usadas pelo próprio cliente em
    // portal/perfil.php, chamadas agora pelo lado da equipe (o Kleber pode
    // trocar a foto do contato sem precisar pedir pro cliente entrar no
    // portal só pra isso). Responde via AJAX (igual "pesquisar_perfil_empresa"
    // acima) quando pedido -- clicar num avatar pronto era um <form> que
    // recarregava a página inteira, o que apagava qualquer campo já
    // preenchido (e ainda não salvo) nos outros formulários da aba, incluindo
    // o de "Dados do contato" logo abaixo. Sem recarregar, nada se perde.
    if ($acao === 'atualizar_avatar_master' || $acao === 'escolher_avatar_predefinido_master') {
        $ehAjaxAvatarMaster = ($_POST['ajax'] ?? '') === '1';

        if ($acao === 'atualizar_avatar_master') {
            $erroAvatarMaster = processar_upload_foto_cliente($pdo, $clienteId);
        } else {
            $indiceAvatarMaster = (int) ($_POST['avatar'] ?? 0);
            $erroAvatarMaster = definir_avatar_predefinido_cliente($pdo, $clienteId, $indiceAvatarMaster) ? null : 'Avatar inválido.';
        }

        if ($ehAjaxAvatarMaster) {
            $stmtClienteAtualizado = $pdo->prepare('SELECT contato_nome, contato_email, nome, foto_perfil FROM clientes WHERE id = ?');
            $stmtClienteAtualizado->execute([$clienteId]);
            $clienteAtualizado = $stmtClienteAtualizado->fetch();

            header('Content-Type: application/json');
            echo json_encode([
                'sucesso' => !$erroAvatarMaster,
                'mensagem' => $erroAvatarMaster ?? 'Avatar atualizado.',
                'avatar_html' => $erroAvatarMaster ? null : avatar_html(
                    $clienteAtualizado['contato_nome'] ?: $clienteAtualizado['nome'],
                    resolver_avatar_por_email($pdo, $clienteAtualizado['contato_email'], $clienteAtualizado['foto_perfil']),
                    'cliente'
                ),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_set($erroAvatarMaster ? 'erro' : 'sucesso', $erroAvatarMaster ?? 'Avatar atualizado.');
    }

    if ($acao === 'criar_chamado_equipe') {
        $categoriaNovoChamado = trim($_POST['categoria'] ?? '');
        if (trim($_POST['titulo'] ?? '') === '' || trim($_POST['descricao'] ?? '') === '' || $categoriaNovoChamado === '') {
            flash_set('erro', 'Preencha título, categoria e descrição do chamado.');
        } else {
            // Título padronizado em MAIÚSCULAS (mesma regra do chamado aberto
            // pelo cliente no portal) -- pedido do Kleber pra não ter títulos
            // com capitalização diferente misturados na listagem.
            $tituloNovoChamadoEquipe = mb_strtoupper(trim($_POST['titulo']), 'UTF-8');
            $descricaoNovoChamadoEquipe = trim($_POST['descricao']);

            // Mesma trava contra duplo-clique/duplo-toque usada no portal do
            // cliente (ver bug de 3 chamados idênticos criados de uma vez).
            if (chamado_e_duplicado_recente($pdo, $clienteId, $tituloNovoChamadoEquipe, $descricaoNovoChamadoEquipe)) {
                flash_set('sucesso', 'Chamado registrado.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, responsavel_id, setor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $clienteId,
                    $tituloNovoChamadoEquipe,
                    $descricaoNovoChamadoEquipe,
                    $categoriaNovoChamado,
                    $_POST['prioridade'] ?? 'media',
                    'equipe',
                    $_POST['responsavel_id'] ?: null,
                    $_POST['setor_id'] ?: null,
                ]);
                // resposta_id = null -- anexo entra vinculado à mensagem de
                // abertura (ver comentário "resposta_id NULL = anexo da
                // mensagem de abertura" mais abaixo, onde os anexos são
                // agrupados pra exibição).
                $errosAnexoNovoChamado = processar_anexos_chamado($pdo, (int) $pdo->lastInsertId(), null, 'equipe');
                flash_set($errosAnexoNovoChamado ? 'erro' : 'sucesso', $errosAnexoNovoChamado ? 'Chamado registrado, mas houve problema com anexo(s): ' . implode(' ', $errosAnexoNovoChamado) : 'Chamado registrado.');
            }
        }
    }

    // --- Setores (departamentos independentes do cliente) ---------------
    // Ver src/Migrations.php (tabelas setores/setor_responsaveis) e
    // ClienteAuth (login separado do master pra cada setor).

    if ($acao === 'criar_setor') {
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '' || !nome_setor_valido($nomeSetor)) {
            flash_set('erro', 'Informe um nome válido pro setor (só pontuação, como "-", não é um nome).');
        } else {
            $pdo->prepare('INSERT INTO setores (cliente_id, nome, icone) VALUES (?, ?, ?)')
                ->execute([$clienteId, $nomeSetor, $_POST['icone'] ?: null]);
            flash_set('sucesso', 'Setor "' . $nomeSetor . '" criado.');
        }
    }

    if ($acao === 'atualizar_setor') {
        $setorId = (int) $_POST['setor_id'];
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '' || !nome_setor_valido($nomeSetor)) {
            flash_set('erro', 'Informe um nome válido pro setor (só pontuação, como "-", não é um nome).');
        } else {
            $pdo->prepare('UPDATE setores SET nome = ?, icone = ?, ativo = ? WHERE id = ? AND cliente_id = ?')
                ->execute([$nomeSetor, $_POST['icone'] ?: null, isset($_POST['ativo']) ? 1 : 0, $setorId, $clienteId]);
            flash_set('sucesso', 'Setor atualizado.');
        }
    }

    // Exclusão é permanente, mas não é destrutiva pros chamados nem pras
    // PESSOAS: chamados ficam sem setor (setor_id vira NULL) e os
    // responsáveis que estavam nesse setor voltam pra "sem setor" (continuam
    // cadastrados na aba Usuários, só perdem o vínculo) -- antes a exclusão
    // apagava o cadastro da pessoa inteiro via CASCADE, o que deixou de fazer
    // sentido agora que o cadastro dela é independente do setor.
    if ($acao === 'excluir_setor') {
        $setorId = (int) $_POST['setor_id'];
        $confereSetor = $pdo->prepare('SELECT 1 FROM setores WHERE id = ? AND cliente_id = ?');
        $confereSetor->execute([$setorId, $clienteId]);
        if ($confereSetor->fetchColumn()) {
            $pdo->prepare('UPDATE chamados SET setor_id = NULL WHERE setor_id = ?')->execute([$setorId]);

            // Precisa saber QUEM tinha esse setor antes de apagar -- o
            // próprio DELETE FROM setores abaixo já limpa em cascata as
            // linhas correspondentes em setor_responsavel_setores/
            // cliente_setores (ver FKs em Migrations.php), mas isso não
            // recalcula por conta própria o "setor principal" de cada um
            // (ver sincronizar_setor_principal_*() em helpers.php).
            $stmtAfetados = $pdo->prepare('SELECT setor_responsavel_id FROM setor_responsavel_setores WHERE setor_id = ?');
            $stmtAfetados->execute([$setorId]);
            $responsaveisAfetados = $stmtAfetados->fetchAll(PDO::FETCH_COLUMN);
            $masterAfetado = in_array($setorId, setores_do_cliente_master($pdo, $clienteId), true);

            $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);

            foreach ($responsaveisAfetados as $respIdAfetado) {
                sincronizar_setor_principal_responsavel($pdo, (int) $respIdAfetado);
            }
            if ($masterAfetado) {
                sincronizar_setor_principal_master($pdo, $clienteId);
            }

            flash_set('sucesso', 'Setor excluído. Os chamados e responsáveis dele continuam salvos, só sem setor vinculado.');
        }
    }

    // O master já vê/gerencia todos os setores independente disso -- esse
    // vínculo é só "de quais setores ele é considerado responsável" (conta
    // como responsável nos cards da aba Setores, entra na notificação de
    // chamado novo daquele setor). Pedido do Kleber: a Ananda (master da
    // SGT Forjados) precisa ficar em mais de um setor ao mesmo tempo, não
    // só um -- substitui o antigo setor único por um conjunto qualquer
    // (0, 1 ou vários), via cliente_setores.
    if ($acao === 'definir_setores_master') {
        substituir_setores_do_cliente_master($pdo, $clienteId, $_POST['setor_ids'] ?? []);
        flash_set('sucesso', 'Setores do master atualizados.');
    }

    // --- Usuários do cliente (aba Usuários) ------------------------------
    // Cadastro da PESSOA, independente de setor -- o vínculo com um setor
    // específico é feito depois, relacionando (ver 'relacionar_responsavel_setor').

    if ($acao === 'criar_usuario_cliente') {
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');

        if ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } else {
            $telefoneResp = apenas_digitos($_POST['telefone'] ?? '');
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $indiceAvatarNovo = (int) ($_POST['avatar'] ?? 0);
            $avatarNovo = ($indiceAvatarNovo >= 1 && $indiceAvatarNovo <= QUANTIDADE_AVATARES_PREDEFINIDOS)
                ? 'assets/img/avatares/avatar-' . $indiceAvatarNovo . '.svg'
                : null;

            $ehMasterResp = isset($_POST['eh_master']) ? 1 : 0;

            try {
                $pdo->prepare('INSERT INTO setor_responsaveis (cliente_id, nome, email, telefone, documento, foto_perfil, eh_master) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$clienteId, $nomeResp, $emailResp, $telefoneResp, $documentoResp, $avatarNovo, $ehMasterResp]);
                $responsavelId = (int) $pdo->lastInsertId();
                substituir_setores_do_responsavel($pdo, $clienteId, $responsavelId, $_POST['setor_ids'] ?? []);

                // Mesma pessoa que já é o contato master desse cliente,
                // cadastrada também como usuário (mesmo e-mail) -- sincroniza
                // o avatar com o do master direto no banco, em vez de
                // confiar só no fallback de exibição (resolver_avatar_por_email).
                if ($cliente['contato_email'] && $cliente['foto_perfil'] && strcasecmp(trim($cliente['contato_email']), $emailResp) === 0) {
                    $pdo->prepare('UPDATE setor_responsaveis SET foto_perfil = ?, foto_perfil_dados = ? WHERE id = ?')
                        ->execute([$cliente['foto_perfil'], $cliente['foto_perfil_dados'], $responsavelId]);
                }

                flash_set('sucesso', 'Usuário cadastrado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível cadastrar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    if ($acao === 'editar_usuario_cliente') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } elseif ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } else {
            $telefoneResp = apenas_digitos($_POST['telefone'] ?? '');
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $ehMasterResp = isset($_POST['eh_master']) ? 1 : 0;
            try {
                $pdo->prepare('UPDATE setor_responsaveis SET nome=?, email=?, telefone=?, documento=?, eh_master=? WHERE id=?')
                    ->execute([$nomeResp, $emailResp, $telefoneResp, $documentoResp, $ehMasterResp, $responsavelId]);
                substituir_setores_do_responsavel($pdo, $clienteId, $responsavelId, $_POST['setor_ids'] ?? []);
                flash_set('sucesso', 'Usuário atualizado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível salvar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    if ($acao === 'excluir_usuario_cliente') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $pdo->prepare('DELETE FROM setor_responsaveis WHERE id = ? AND cliente_id = ?')->execute([$responsavelId, $clienteId]);
        flash_set('sucesso', 'Usuário removido.');
    }

    // Bloqueia/libera uma PESSOA específica de abrir chamado pelo bot do
    // WhatsApp -- não mexe no acesso dela ao portal (portal_ativo), são
    // coisas independentes. responsavel_id vazio = é o contato master
    // (clientes.bloqueado_whatsapp), senão é um setor_responsaveis.
    if ($acao === 'alternar_bloqueio_whatsapp') {
        $responsavelId = $_POST['responsavel_id'] !== '' ? (int) $_POST['responsavel_id'] : null;
        if ($responsavelId) {
            $pdo->prepare('UPDATE setor_responsaveis SET bloqueado_whatsapp = NOT bloqueado_whatsapp WHERE id = ? AND cliente_id = ?')
                ->execute([$responsavelId, $clienteId]);
        } else {
            $pdo->prepare('UPDATE clientes SET bloqueado_whatsapp = NOT bloqueado_whatsapp WHERE id = ?')
                ->execute([$clienteId]);
        }
        flash_set('sucesso', 'Acesso ao WhatsApp atualizado.');
    }

    // Liberação especial pra funcionar fora do horário comercial (ver
    // WHATSAPP_HORARIO_COMERCIAL_* em config.php) -- é do CLIENTE inteiro,
    // não de uma pessoa específica.
    if ($acao === 'alternar_liberacao_horario') {
        $pdo->prepare('UPDATE clientes SET whatsapp_liberacao_horario = NOT whatsapp_liberacao_horario WHERE id = ?')
            ->execute([$clienteId]);
        flash_set('sucesso', 'Liberação de horário do WhatsApp atualizada.');
    }

    // Liberação especial pro PORTAL (site) funcionar fora do horário
    // comercial (ver PORTAL_CLIENTE_HORARIO_* em config.php e
    // ClienteAuth::bloquearForaDoHorario()) -- é do CLIENTE inteiro, vale
    // pro master e qualquer setor dele igual, mesma lógica da liberação de
    // WhatsApp acima só que pra tela diferente. Antes esse toggle ficava
    // duplicado em "Visão Geral" (aqui) e no modal de clientes.php -- pedido
    // do Kleber pra deixar só num lugar, junto com a de WhatsApp.
    if ($acao === 'alternar_acesso_portal_fora_horario') {
        $pdo->prepare('UPDATE clientes SET acesso_fora_horario = NOT acesso_fora_horario WHERE id = ?')
            ->execute([$clienteId]);
        flash_set('sucesso', 'Liberação de horário do Portal atualizada.');
    }

    // Vincula um usuário JÁ cadastrado a MAIS um setor -- aditivo, não tira
    // os outros setores que ele já tinha (uma pessoa pode estar em vários
    // ao mesmo tempo agora) -- é isso que "Gerenciar setores" usa pra
    // buscar/relacionar em vez de cadastrar uma pessoa nova. Pra desvincular
    // de um setor específico, ver 'desvincular_responsavel_setor'.
    if ($acao === 'relacionar_responsavel_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $novoSetorId = (int) ($_POST['setor_id'] ?? 0);
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);

        if (!$confereResp->fetchColumn() || !$novoSetorId) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            $setoresAtuaisResp = setores_do_responsavel($pdo, $responsavelId);
            substituir_setores_do_responsavel($pdo, $clienteId, $responsavelId, [...$setoresAtuaisResp, $novoSetorId]);
            flash_set('sucesso', 'Usuário relacionado ao setor.');
        }
    }

    // Remove o vínculo de um usuário com UM setor específico, sem tirar os
    // outros setores que ele tenha -- botão "Desvincular" de cada card na
    // aba Setores.
    if ($acao === 'desvincular_responsavel_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $setorId = (int) $_POST['setor_id'];
        $pdo->prepare('DELETE FROM setor_responsavel_setores WHERE setor_responsavel_id = ? AND setor_id = ?')->execute([$responsavelId, $setorId]);
        sincronizar_setor_principal_responsavel($pdo, $responsavelId);
        flash_set('sucesso', 'Usuário desvinculado do setor.');
    }

    // Multi-select da aba Usuários (linha da pessoa OU do master) -- substitui
    // de uma vez TODOS os setores dessa pessoa pelo conjunto marcado.
    if ($acao === 'definir_setores_responsavel') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            substituir_setores_do_responsavel($pdo, $clienteId, $responsavelId, $_POST['setor_ids'] ?? []);
            flash_set('sucesso', 'Setores atualizados.');
        }
    }

    if ($acao === 'enviar_convite_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $stmt = $pdo->prepare('SELECT sr.*, s.nome AS setor_nome FROM setor_responsaveis sr LEFT JOIN setores s ON s.id = sr.setor_id WHERE sr.id = ? AND sr.cliente_id = ?');
        $stmt->execute([$responsavelId, $clienteId]);
        $responsavel = $stmt->fetch();

        if (!$responsavel) {
            flash_set('erro', 'Usuário não encontrado.');
        } elseif (!$responsavel['setor_id']) {
            flash_set('erro', 'Relacione esse usuário a um setor antes de convidar pro portal -- é o setor que define o que ele vai ver.');
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO setor_senha_tokens (setor_responsavel_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$responsavelId, $token]);
            notificar_depois(fn () => Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token));
            flash_set('sucesso', 'Convite enviado para ' . $responsavel['email'] . '.');
        }
    }

    // Mesma ideia de "Resetar senha" dos clientes (usuarios.php): zera a
    // senha na hora (invalida a atual) e já manda um convite novo, em vez
    // de só reenviar o link mantendo a senha antiga válida.
    if ($acao === 'resetar_senha_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $stmt = $pdo->prepare('SELECT sr.*, s.nome AS setor_nome FROM setor_responsaveis sr LEFT JOIN setores s ON s.id = sr.setor_id WHERE sr.id = ? AND sr.cliente_id = ?');
        $stmt->execute([$responsavelId, $clienteId]);
        $responsavel = $stmt->fetch();

        if (!$responsavel || !$responsavel['setor_id']) {
            flash_set('erro', 'Usuário não encontrado ou sem setor relacionado.');
        } else {
            $pdo->prepare('UPDATE setor_responsaveis SET senha_hash = NULL WHERE id = ?')->execute([$responsavelId]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO setor_senha_tokens (setor_responsavel_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$responsavelId, $token]);
            notificar_depois(fn () => Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token));
            flash_set('sucesso', 'Senha resetada e novo link enviado para ' . $responsavel['email'] . '.');
        }
    }

    if ($acao === 'desativar_portal_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $pdo->prepare('UPDATE setor_responsaveis SET portal_ativo = 0, senha_hash = NULL WHERE id = ? AND cliente_id = ?')
            ->execute([$responsavelId, $clienteId]);
        flash_set('sucesso', 'Acesso ao portal desativado.');
    }

    if ($acao === 'atualizar_avatar_setor_responsavel') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $erroFoto = processar_upload_foto_setor_responsavel($pdo, $responsavelId);
        flash_set($erroFoto ? 'erro' : 'sucesso', $erroFoto ?? 'Foto atualizada.');
    }

    if ($acao === 'escolher_avatar_predefinido_setor_responsavel') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $indice = (int) ($_POST['avatar'] ?? 0);
        if (definir_avatar_predefinido_setor_responsavel($pdo, $responsavelId, $indice)) {
            flash_set('sucesso', 'Avatar atualizado.');
        }
    }

    if ($acao === 'responder_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        $mensagem = trim($_POST['mensagem'] ?? '');
        $temAnexo = !empty($_FILES['anexos']['name'][0] ?? '');
        $notificarCliente = ($_POST['notificar_cliente'] ?? '') === '1';
        // Sem essa escolha explícita cai no comportamento de sempre
        // (recalcular_status_chamado() -- vira "aguardando_cliente" depois
        // de resposta da equipe). Pedido do Kleber: às vezes já quer
        // encerrar na mesma hora (chamado antigo, resolvido por fora) ou
        // deixar como pendência NOSSA mesmo tendo respondido algo, sem
        // precisar de um segundo clique separado em "Encerrar".
        $statusAposResposta = $_POST['status_apos_resposta'] ?? 'aguardando_cliente';
        if (!in_array($statusAposResposta, ['aguardando_cliente', 'aguardando_equipe', 'fechado'], true)) {
            $statusAposResposta = 'aguardando_cliente';
        }

        $temMensagemOuAnexo = $mensagem !== '' || $temAnexo;
        $nomeResponsavel = Auth::userName();
        $errosAnexo = [];
        $anexosResposta = [];

        if ($temMensagemOuAnexo) {
            $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_usuario_id, mensagem) VALUES (?, "equipe", ?, ?, ?)')
                ->execute([$chamadoId, $nomeResponsavel, Auth::userId(), $mensagem !== '' ? $mensagem : '(anexo enviado)']);
            $respostaId = (int) $pdo->lastInsertId();

            $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, $respostaId, 'equipe');

            $stmtAnexosResposta = $pdo->prepare('SELECT id, arquivo_nome_original FROM chamado_anexos WHERE resposta_id = ?');
            $stmtAnexosResposta->execute([$respostaId]);
            $anexosResposta = $stmtAnexosResposta->fetchAll();
        }

        // O status escolhido no "Deixar como" sempre é aplicado, MESMO sem
        // mensagem/anexo nenhum -- pedido explícito do Kleber: às vezes só
        // quer encerrar um chamado antigo sem escrever nada, e isso não
        // pode depender de ter digitado algo (bug real: antes ficava tudo
        // dentro do if($temMensagemOuAnexo), então "Fechado" sem texto não
        // fechava nada, só voltava pra tela sem avisar que não fez nada).
        if ($statusAposResposta === 'fechado') {
            $pdo->prepare("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ?")->execute([$chamadoId]);
        } else {
            $pdo->prepare('UPDATE chamados SET status = ?, fechado_em = NULL WHERE id = ?')->execute([$statusAposResposta, $chamadoId]);
        }

        $chamadoAtualizado = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
        $chamadoAtualizado->execute([$chamadoId]);
        $chamadoAtualizado = $chamadoAtualizado->fetch();

        // Só faz sentido avisar o cliente se teve mensagem/anexo de verdade
        // -- não há nada pra "notificar" de uma troca de status silenciosa.
        if ($temMensagemOuAnexo && $notificarCliente) {
            // Avisa a PESSOA certa (quem abriu -- pode ser um responsável
            // de setor específico, não sempre o contato master) com o
            // texto da resposta e os anexos. Só pergunta no zap o que
            // ela quer fazer (encerrar/responder/nada) se o chamado NÃO
            // foi encerrado agora mesmo -- não faz sentido perguntar
            // "quer encerrar?" de algo que já está encerrado.
            $pessoaChamado = resolver_pessoa_do_chamado($pdo, $chamadoAtualizado, $cliente);
            notificar_depois(fn () => Notificacoes::novaRespostaEquipe($chamadoAtualizado, $pessoaChamado, $nomeResponsavel, $mensagem, $anexosResposta));
            if ($pessoaChamado['telefone'] && $statusAposResposta !== 'fechado') {
                notificar_depois(fn () => WhatsAppBot::prepararConversaPosResposta($pdo, $chamadoAtualizado, $pessoaChamado['telefone']));
            }
        }

        $rotuloStatus = ['aguardando_cliente' => 'aguardando cliente', 'aguardando_equipe' => 'aguardando nós', 'fechado' => 'fechado'][$statusAposResposta];
        $mensagemSucesso = ($temMensagemOuAnexo ? 'Resposta registrada, chamado ' : 'Chamado ') . $rotuloStatus . '.'
            . ($temMensagemOuAnexo && !$notificarCliente ? ' Cliente NÃO foi notificado.' : '');
        flash_set('sucesso', $errosAnexo ? $mensagemSucesso . ' Mas: ' . implode(' ', $errosAnexo) : $mensagemSucesso);
    }

    if ($acao === 'fechar_chamado') {
        $pdo->prepare("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ? AND cliente_id = ?")
            ->execute([(int) $_POST['chamado_id'], $clienteId]);
        flash_set('sucesso', 'Chamado encerrado.');
    }

    if ($acao === 'reabrir_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        // Confirma que o chamado é desse cliente antes de reabrir.
        $confere = $pdo->prepare('SELECT 1 FROM chamados WHERE id = ? AND cliente_id = ?');
        $confere->execute([$chamadoId, $clienteId]);
        if ($confere->fetchColumn()) {
            recalcular_status_chamado($pdo, $chamadoId);
            flash_set('sucesso', 'Chamado reaberto.');
        }
    }

    // Trocar o responsável de um chamado já existente -- antes só dava pra
    // escolher o responsável na hora de CRIAR o chamado, não tinha como
    // reatribuir depois pra outro colega da equipe.
    if ($acao === 'atribuir_responsavel_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        $novoResponsavelId = $_POST['responsavel_id'] !== '' ? (int) $_POST['responsavel_id'] : null;

        $confere = $pdo->prepare('SELECT id, titulo, responsavel_id FROM chamados WHERE id = ? AND cliente_id = ?');
        $confere->execute([$chamadoId, $clienteId]);
        $chamadoAlvo = $confere->fetch();

        if ($chamadoAlvo) {
            $pdo->prepare('UPDATE chamados SET responsavel_id = ? WHERE id = ?')->execute([$novoResponsavelId, $chamadoId]);

            // Só avisa por e-mail se realmente MUDOU pra alguém (não dispara
            // de novo se salvar o mesmo responsável, nem quando desatribui).
            if ($novoResponsavelId && $novoResponsavelId !== (int) $chamadoAlvo['responsavel_id']) {
                $stmtNovoResp = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
                $stmtNovoResp->execute([$novoResponsavelId]);
                $novoResponsavel = $stmtNovoResp->fetch();
                if ($novoResponsavel) {
                    notificar_depois(fn () => Notificacoes::chamadoAtribuido(['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo'], 'cliente_id' => $clienteId], $novoResponsavel));
                }
            }

            flash_set('sucesso', 'Responsável do chamado atualizado.');
        }
    }

    // Trocar o setor de um chamado depois de aberto -- exige que o setor de
    // destino já tenha um responsável cadastrado (é ele que recebe o e-mail
    // avisando que o chamado caiu pro setor dele; sem responsável não tem
    // pra quem avisar, então a troca é bloqueada). Tirar o setor ("Sem
    // setor") não passa por essa trava, já que não precisa avisar ninguém.
    if ($acao === 'atribuir_setor_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        $novoSetorId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;

        $confere = $pdo->prepare('SELECT id, titulo, setor_id FROM chamados WHERE id = ? AND cliente_id = ?');
        $confere->execute([$chamadoId, $clienteId]);
        $chamadoAlvo = $confere->fetch();

        if (!$chamadoAlvo) {
            flash_set('erro', 'Chamado não encontrado.');
        } elseif ($novoSetorId === null) {
            $pdo->prepare('UPDATE chamados SET setor_id = NULL WHERE id = ?')->execute([$chamadoId]);
            flash_set('sucesso', 'Chamado sem setor vinculado.');
        } else {
            $stmtSetorAlvo = $pdo->prepare('SELECT id, nome FROM setores WHERE id = ? AND cliente_id = ?');
            $stmtSetorAlvo->execute([$novoSetorId, $clienteId]);
            $setorAlvo = $stmtSetorAlvo->fetch();

            $stmtMembrosAlvo = $pdo->prepare('
                SELECT sr.* FROM setor_responsaveis sr
                INNER JOIN setor_responsavel_setores srs ON srs.setor_responsavel_id = sr.id
                WHERE srs.setor_id = ?
            ');
            $stmtMembrosAlvo->execute([$novoSetorId]);
            $membrosSetorAlvo = $stmtMembrosAlvo->fetchAll();

            $masterNesseSetor = in_array($novoSetorId, setores_do_cliente_master($pdo, $clienteId), true);

            if (!$setorAlvo || (!$membrosSetorAlvo && !$masterNesseSetor)) {
                flash_set('erro', 'Esse setor ainda não tem nenhum responsável cadastrado -- relacione um usuário em "Gerenciar setores" antes de atribuir chamados a ele.');
            } else {
                $pdo->prepare('UPDATE chamados SET setor_id = ? WHERE id = ?')->execute([$novoSetorId, $chamadoId]);

                // Só avisa por e-mail se realmente MUDOU de setor (não
                // dispara de novo se salvar o mesmo setor) -- avisa TODOS os
                // responsáveis desse setor, não só um (pode ter vários).
                if ($novoSetorId !== (int) $chamadoAlvo['setor_id']) {
                    foreach ($membrosSetorAlvo as $membro) {
                        notificar_depois(fn () => Notificacoes::chamadoAtribuidoSetor(['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo']], $membro, $setorAlvo['nome']));
                    }
                    if ($masterNesseSetor) {
                        notificar_depois(fn () => Notificacoes::chamadoAtribuidoSetor(
                            ['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo']],
                            ['nome' => $cliente['contato_nome'] ?: $cliente['nome'], 'email' => $cliente['contato_email'], 'telefone' => $cliente['contato_telefone']],
                            $setorAlvo['nome']
                        ));
                    }
                }

                flash_set('sucesso', 'Chamado vinculado ao setor ' . $setorAlvo['nome'] . '.');
            }
        }
    }

    // Volta rolado até o MESMO card de onde a ação partiu (ex.: responder um
    // chamado específico numa lista com 20+ chamados) -- sem isso a página
    // sempre recarregava no topo da aba, obrigando rolar tudo de novo pra
    // continuar a conversa. $_POST['scroll_para'] é o id="..." do elemento
    // (ver forms dos chamados) -- validado aqui (só letras/números/hífen)
    // porque vai direto pra URL do redirect. Query string SEMPRE antes do
    // #fragmento (senão vira parte do hash e o JS não acha via
    // URLSearchParams(location.search)).
    $urlRetorno = 'cliente.php?id=' . $clienteId;
    $scrollPara = $_POST['scroll_para'] ?? '';
    if ($scrollPara !== '' && preg_match('/^[a-zA-Z0-9-]+$/', $scrollPara)) {
        $urlRetorno .= '&scroll_para=' . urlencode($scrollPara);
    }
    $urlRetorno .= '#' . ($_POST['aba_retorno'] ?? 'visao-geral');
    header('Location: ' . url($urlRetorno));
    finalizar_resposta_e_notificar();
    exit;
}

// --- Dados para exibição ---
// Ordem pensada pra quem chega direto do Dashboard pra triagem: primeiro o
// que é urgente/alta prioridade E pendência nossa (aberto/aguardando_equipe),
// depois o resto do que é pendência nossa (mais antigo primeiro = mais
// atrasado primeiro), depois o que já respondemos (aguardando_cliente), e só
// por último o que já foi fechado.
$chamados = $pdo->prepare('
    SELECT c.*, u.nome AS responsavel_nome, u.foto_perfil AS responsavel_foto
    FROM chamados c
    LEFT JOIN usuarios u ON u.id = c.responsavel_id
    WHERE c.cliente_id = ?
    ORDER BY
        CASE
            WHEN c.status IN ("aberto", "aguardando_equipe") AND c.prioridade IN ("urgente", "alta") THEN 1
            WHEN c.status IN ("aberto", "aguardando_equipe") THEN 2
            WHEN c.status = "aguardando_cliente" THEN 3
            ELSE 4
        END ASC,
        CASE WHEN c.status = "fechado" THEN -UNIX_TIMESTAMP(c.criado_em) ELSE UNIX_TIMESTAMP(c.criado_em) END ASC
');
$chamados->execute([$clienteId]);
$chamados = $chamados->fetchAll();

// Monta a conversa completa de cada chamado (mensagem de abertura + todas as
// respostas + anexos de cada uma), já num formato único pro template só
// percorrer e desenhar as bolhas -- antes a mensagem de abertura ficava de
// fora do chat, sem bolha/cor/nome do contato, o que ficava estranho.
$linhasChatPorChamado = [];
if ($chamados) {
    $ids = array_column($chamados, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmtResp = $pdo->prepare("SELECT * FROM chamado_respostas WHERE chamado_id IN ($placeholders) ORDER BY criado_em ASC");
    $stmtResp->execute($ids);
    $respostasPorChamado = [];
    foreach ($stmtResp->fetchAll() as $r) {
        $respostasPorChamado[$r['chamado_id']][] = $r;
    }

    $stmtAnexos = $pdo->prepare("SELECT * FROM chamado_anexos WHERE chamado_id IN ($placeholders) ORDER BY criado_em ASC");
    $stmtAnexos->execute($ids);
    $anexosPorChamado = [];
    foreach ($stmtAnexos->fetchAll() as $a) {
        // resposta_id NULL = anexo da mensagem de abertura (chave 'abertura').
        $chave = $a['resposta_id'] ?? 'abertura';
        $anexosPorChamado[$a['chamado_id']][$chave][] = $a;
    }

    // Reunião/conversa/treinamento/auxílio vinculado a cada chamado (ver
    // treinamentos.chamado_id) -- pra mostrar na tela do chamado que
    // aconteceu um Meet por causa dele, sem precisar ir até a aba Setores
    // procurar.
    $stmtTreinamentosChamado = $pdo->prepare("SELECT chamado_id, titulo, tipo, data_inicio, hora_inicio, link_reuniao FROM treinamentos WHERE chamado_id IN ($placeholders) ORDER BY data_inicio DESC, hora_inicio DESC");
    $stmtTreinamentosChamado->execute($ids);
    $treinamentosPorChamadoId = [];
    foreach ($stmtTreinamentosChamado->fetchAll() as $tc) {
        $treinamentosPorChamadoId[$tc['chamado_id']][] = $tc;
    }

    // Foto de cada membro da equipe que respondeu -- antes o chat só sabia o
    // NOME de quem respondeu (autor_nome, texto solto), sem saber a foto de
    // qual usuário era, então toda resposta da equipe caía na inicial "K"
    // genérica. Respostas antigas/importadas do HESK não têm
    // autor_usuario_id (NULL) e continuam caindo na inicial, sem problema.
    $idsAutoresEquipe = array_unique(array_filter(array_column($respostasPorChamado ? array_merge(...array_values($respostasPorChamado)) : [], 'autor_usuario_id')));
    $fotoPorUsuarioId = [];
    if ($idsAutoresEquipe) {
        $placeholdersUsuarios = implode(',', array_fill(0, count($idsAutoresEquipe), '?'));
        $stmtFotos = $pdo->prepare("SELECT id, foto_perfil FROM usuarios WHERE id IN ($placeholdersUsuarios)");
        $stmtFotos->execute(array_values($idsAutoresEquipe));
        foreach ($stmtFotos->fetchAll() as $u) {
            $fotoPorUsuarioId[$u['id']] = $u['foto_perfil'];
        }
    }

    // Qual responsável de setor EXATAMENTE abriu/respondeu -- não é mais "o
    // responsável atual do setor" (deixou de bater desde que um setor pode
    // ter vários responsáveis ao mesmo tempo). Foto do master também passa
    // pelo resolver (mesma pessoa pode estar cadastrada com o mesmo e-mail
    // em mais de um lugar, ver helpers.php).
    $idsResponsaveisMencionados = array_unique(array_filter(array_merge(
        array_column($chamados, 'aberto_por_setor_responsavel_id'),
        $respostasPorChamado ? array_column(array_merge(...array_values($respostasPorChamado)), 'autor_setor_responsavel_id') : []
    )));
    $dadosPorResponsavelId = [];
    if ($idsResponsaveisMencionados) {
        $placeholdersResp = implode(',', array_fill(0, count($idsResponsaveisMencionados), '?'));
        $stmtDadosResp = $pdo->prepare("SELECT id, nome, email, foto_perfil FROM setor_responsaveis WHERE id IN ($placeholdersResp)");
        $stmtDadosResp->execute(array_values($idsResponsaveisMencionados));
        foreach ($stmtDadosResp->fetchAll() as $sr) {
            $dadosPorResponsavelId[$sr['id']] = ['nome' => $sr['nome'], 'foto' => resolver_avatar_por_email($pdo, $sr['email'], $sr['foto_perfil'])];
        }
    }
    $fotoMaster = resolver_avatar_por_email($pdo, $cliente['contato_email'] ?? null, $cliente['foto_perfil'] ?? null);

    foreach ($chamados as $c) {
        $linhas = [];

        $dadosAbertura = $c['aberto_por_tipo'] === 'setor' ? ($dadosPorResponsavelId[$c['aberto_por_setor_responsavel_id']] ?? null) : null;

        if ($c['aberto_por'] === 'equipe') {
            $autorTipo = 'equipe';
            $nomeAutor = $c['responsavel_nome'] ?: 'Equipe PrimeERP';
            $foto = $c['responsavel_foto'] ?? null;
        } elseif ($c['aberto_por'] === 'publico') {
            $autorTipo = 'cliente';
            $nomeAutor = $c['contato_nome_publico'] ?: ($c['empresa_informada'] ?: 'Cliente');
            $foto = null;
        } else {
            $autorTipo = 'cliente';
            $nomeAutor = $dadosAbertura['nome'] ?? ($cliente['contato_nome'] ?: $cliente['nome']);
            $foto = $dadosAbertura['foto'] ?? $fotoMaster;
        }

        $linhas[] = [
            'autor_tipo' => $autorTipo,
            'autor_nome' => $nomeAutor,
            'foto' => $foto,
            'mensagem' => $c['descricao'],
            'criado_em' => $c['criado_em'],
            'anexos' => $anexosPorChamado[$c['id']]['abertura'] ?? [],
        ];

        foreach ($respostasPorChamado[$c['id']] ?? [] as $r) {
            $dadosResposta = $r['autor_cliente_tipo'] === 'setor' ? ($dadosPorResponsavelId[$r['autor_setor_responsavel_id']] ?? null) : null;
            $linhas[] = [
                'autor_tipo' => $r['autor_tipo'],
                'autor_nome' => $r['autor_nome'],
                // Resposta da equipe sem autor_usuario_id vinculado (antiga/importada
                // do HESK, cujo autor_nome não bate com nenhum usuário atual) cai pra
                // foto de quem é o responsável atual do chamado, em vez de ficar sem
                // foto nenhuma -- melhor um retrato plausível do que nunca mostrar
                // nada, já que na prática quase toda resposta é de quem está/ficou
                // responsável pelo chamado.
                'foto' => $r['autor_tipo'] === 'cliente'
                    ? ($r['autor_cliente_tipo'] === 'setor' ? ($dadosResposta['foto'] ?? null) : $fotoMaster)
                    : ($fotoPorUsuarioId[$r['autor_usuario_id']] ?? $c['responsavel_foto'] ?? null),
                'mensagem' => $r['mensagem'],
                'criado_em' => $r['criado_em'],
                'anexos' => $anexosPorChamado[$c['id']][$r['id']] ?? [],
            ];
        }

        $linhasChatPorChamado[$c['id']] = $linhas;
    }
}

// KPI (métrica) agora pode ser de um setor específico (aba Setores) ou
// geral do cliente (setor_id NULL) -- ver Migrations. Agrupado por setor_id
// pra cada setor mostrar só as métricas dele; os gerais aparecem na Visão
// geral.
$kpis = $pdo->prepare('SELECT * FROM kpis WHERE cliente_id = ? ORDER BY periodo DESC, nome_kpi ASC');
$kpis->execute([$clienteId]);
$kpis = $kpis->fetchAll();
$kpisPorSetorId = [];
$kpisGerais = [];
foreach ($kpis as $k) {
    if ($k['setor_id']) {
        $kpisPorSetorId[$k['setor_id']][] = $k;
    } else {
        $kpisGerais[] = $k;
    }
}

// Documento também pode ser de um setor específico ou geral do cliente.
// Dois documentos gerais são "slots fixos" (contrato social e contrato de
// prestação de serviços da PrimeERP, mostrados na Visão geral) -- os demais
// documentos gerais (histórico de antes dessa separação existir, ou algo
// que realmente não é de um setor específico) aparecem numa lista simples,
// também na Visão geral.
$documentos = $pdo->prepare('SELECT d.*, u.nome AS criado_por_nome FROM documentos d LEFT JOIN usuarios u ON u.id = d.criado_por WHERE d.cliente_id = ? ORDER BY d.criado_em DESC');
$documentos->execute([$clienteId]);
$documentos = $documentos->fetchAll();
$documentosPorSetorId = [];
$videoAulasPorSetorId = [];
$documentosGerais = [];
$contratoSocial = null;
$contratoPrestacaoServicos = null;
foreach ($documentos as $doc) {
    if ($doc['setor_id'] && $doc['categoria'] === 'Vídeo aula') {
        $videoAulasPorSetorId[$doc['setor_id']][] = $doc;
    } elseif ($doc['setor_id']) {
        $documentosPorSetorId[$doc['setor_id']][] = $doc;
    } elseif ($doc['categoria'] === 'Contrato Social') {
        $contratoSocial ??= $doc; // primeiro = mais recente (ORDER BY criado_em DESC)
    } elseif ($doc['categoria'] === 'Contrato de Prestação de Serviços') {
        $contratoPrestacaoServicos ??= $doc;
    } elseif ($doc['categoria'] === 'Implantação') {
        // Aparecem só na aba Implantação (ver mais abaixo), não aqui.
    } else {
        $documentosGerais[] = $doc;
    }
}

// Anexos da aba Implantação, agrupados por etapa (NULL = anexo geral, sem
// etapa específica) -- reaproveita a mesma lista de $documentos já buscada
// acima, sem fazer outra query.
$anexosImplantacaoPorEtapaId = [];
$anexosImplantacaoGerais = [];
foreach ($documentos as $doc) {
    if ($doc['categoria'] !== 'Implantação') {
        continue;
    }
    if ($doc['implantacao_etapa_id']) {
        $anexosImplantacaoPorEtapaId[$doc['implantacao_etapa_id']][] = $doc;
    } else {
        $anexosImplantacaoGerais[] = $doc;
    }
}

// Todos os anexos da Implantação (gerais + de qualquer etapa), pra listar
// no modal de seleção antes de gerar sugestão com IA (ver
// modalDocsSugestaoImplantacao) -- o usuário escolhe quais desses a IA vai
// ler naquela geração específica, em vez de mandar sempre todos.
$todosDocumentosImplantacaoIa = array_values(array_filter($documentos, fn ($d) => $d['categoria'] === 'Implantação'));

$etapasImplantacao = $pdo->prepare('SELECT * FROM implantacao_etapas WHERE cliente_id = ? ORDER BY ordem ASC, id ASC');
$etapasImplantacao->execute([$clienteId]);
$etapasImplantacao = $etapasImplantacao->fetchAll();

// TODAS as análises já geradas (risco e melhorias, cliente inteiro ou por
// setor) -- cada uma vira um card na aba Análise de Risco, separadas por
// tipo (ver $analisesRiscoPorTipo).
$stmtAnalisesRisco = $pdo->prepare("
    SELECT r.*, s.nome AS setor_nome
    FROM cliente_analise_risco r
    LEFT JOIN setores s ON s.id = r.setor_id
    WHERE r.cliente_id = ?
    ORDER BY r.criado_em DESC
");
$stmtAnalisesRisco->execute([$clienteId]);
$analisesRiscoPorTipo = ['risco' => [], 'melhorias' => []];
foreach ($stmtAnalisesRisco->fetchAll() as $a) {
    $analisesRiscoPorTipo[$a['tipo'] === 'melhorias' ? 'melhorias' : 'risco'][] = $a;
}

// Só a sugestão mais recente DE CADA TIPO (ORDER BY criado_em DESC + ??=
// pega a primeira ocorrência de cada tipo, que é a mais nova).
$sugestoesImplantacaoIa = $pdo->prepare('SELECT * FROM implantacao_sugestoes_ia WHERE cliente_id = ? ORDER BY criado_em DESC');
$sugestoesImplantacaoIa->execute([$clienteId]);
$ultimaSugestaoImplantacaoPorTipo = [];
foreach ($sugestoesImplantacaoIa->fetchAll() as $sugImplantacao) {
    $ultimaSugestaoImplantacaoPorTipo[$sugImplantacao['tipo']] ??= $sugImplantacao;
}

// Propostas de melhoria/desenvolvimento por setor (aba Setores) -- backlog
// de evolução do processo, separado do chamado normal (que é
// atendimento/suporte pontual).
$propostas = $pdo->prepare('
    SELECT p.*, u.nome AS criado_por_nome, d.arquivo_nome_original
    FROM propostas_desenvolvimento p
    LEFT JOIN usuarios u ON u.id = p.criado_por
    LEFT JOIN documentos d ON d.id = p.documento_id
    WHERE p.cliente_id = ?
    ORDER BY p.criado_em DESC
');
$propostas->execute([$clienteId]);
$propostas = $propostas->fetchAll();
$propostasPorSetorId = [];
foreach ($propostas as $p) {
    if ($p['setor_id']) {
        $propostasPorSetorId[$p['setor_id']][] = $p;
    }
}

// --- Acessos (cofre de credenciais/VPN) e Fontes ADVPL ------------------
// Dado extremamente sensível (senha de servidor/RDP/AnyDesk/VPN, código-
// fonte proprietário do cliente) -- só carrega essas listas se a pessoa
// logada realmente tiver a permissão especial (ver Auth::
// podeAcessarDadosSensiveis()), pra nem essas linhas existirem em memória
// numa requisição de quem não pode ver. A SENHA em si nunca é decifrada
// aqui -- só o resto dos dados da credencial (título, host, usuário); a
// senha só é decifrada sob demanda via ver_credencial.php, quando a pessoa
// clica em "Mostrar".
$podeAcessarDadosSensiveis = Auth::podeAcessarDadosSensiveis();
$credenciaisCliente = [];
$vpnArquivosCliente = [];
$fontesAdvplPorSetorId = [];
// Ambientes T-Cloud (produção/teste) desse cliente -- default com os 4
// links vazios pra não precisar checar isset() no HTML do card/modal
// quando o cliente ainda não tiver cadastrado nada.
$tcloudAmbientesCliente = [];
foreach (array_keys(TCLOUD_AMBIENTES) as $tipoAmbienteTcloud) {
    $tcloudAmbientesCliente[$tipoAmbienteTcloud] = array_fill_keys(array_keys(TCLOUD_LINKS), null);
}
$tcloudVscodeCliente = [];
if ($podeAcessarDadosSensiveis) {
    $stmtCredenciais = $pdo->prepare('SELECT * FROM cliente_credenciais WHERE cliente_id = ? ORDER BY tipo ASC, titulo ASC');
    $stmtCredenciais->execute([$clienteId]);
    $credenciaisCliente = $stmtCredenciais->fetchAll();

    $stmtVpnArquivos = $pdo->prepare('
        SELECT v.*, u.nome AS criado_por_nome
        FROM cliente_vpn_arquivos v
        LEFT JOIN usuarios u ON u.id = v.criado_por
        WHERE v.cliente_id = ?
        ORDER BY v.criado_em DESC
    ');
    $stmtVpnArquivos->execute([$clienteId]);
    $vpnArquivosCliente = $stmtVpnArquivos->fetchAll();

    $stmtFontesAdvpl = $pdo->prepare('
        SELECT f.*, u.nome AS criado_por_nome
        FROM setor_fontes_advpl f
        INNER JOIN setores s ON s.id = f.setor_id
        LEFT JOIN usuarios u ON u.id = f.criado_por
        WHERE s.cliente_id = ?
        ORDER BY f.criado_em DESC
    ');
    $stmtFontesAdvpl->execute([$clienteId]);
    foreach ($stmtFontesAdvpl->fetchAll() as $fonte) {
        $fontesAdvplPorSetorId[$fonte['setor_id']][] = $fonte;
    }

    $stmtTcloudAmbientes = $pdo->prepare('SELECT * FROM cliente_tcloud_ambientes WHERE cliente_id = ?');
    $stmtTcloudAmbientes->execute([$clienteId]);
    foreach ($stmtTcloudAmbientes->fetchAll() as $ambienteTcloud) {
        if (array_key_exists($ambienteTcloud['tipo'], $tcloudAmbientesCliente)) {
            $tcloudAmbientesCliente[$ambienteTcloud['tipo']] = $ambienteTcloud;
        }
    }

    $stmtTcloudVscode = $pdo->prepare('SELECT * FROM cliente_tcloud_vscode WHERE cliente_id = ? ORDER BY titulo ASC');
    $stmtTcloudVscode->execute([$clienteId]);
    $tcloudVscodeCliente = $stmtTcloudVscode->fetchAll();
}

$treinamentos = $pdo->prepare('
    SELECT t.*, u.nome AS responsavel_nome, s.nome AS setor_nome
    FROM treinamentos t
    LEFT JOIN usuarios u ON u.id = t.responsavel_id
    LEFT JOIN setores s ON s.id = t.setor_id
    WHERE t.cliente_id = ?
    ORDER BY t.data_inicio DESC, t.hora_inicio ASC
');
$treinamentos->execute([$clienteId]);
$treinamentos = $treinamentos->fetchAll();

// Quem está marcado pra receber aviso (e-mail+WhatsApp) de cada treinamento
// -- carrega tudo de uma vez e agrupa por treinamento_id, pra não fazer uma
// query por linha do calendário.
$destinatariosPorTreinamentoId = [];
if ($treinamentos) {
    $stmtDestinatarios = $pdo->prepare('
        SELECT treinamento_id, tipo, setor_responsavel_id
        FROM treinamento_destinatarios
        WHERE treinamento_id IN (' . implode(',', array_fill(0, count($treinamentos), '?')) . ')
    ');
    $stmtDestinatarios->execute(array_column($treinamentos, 'id'));
    foreach ($stmtDestinatarios->fetchAll() as $d) {
        $destinatariosPorTreinamentoId[$d['treinamento_id']][] = $d['tipo'] === 'master' ? 'master' : ('responsavel_' . $d['setor_responsavel_id']);
    }
}

$treinamentosPorSetorId = [];
$treinamentosGerais = [];
foreach ($treinamentos as $t) {
    if ($t['setor_id']) {
        $treinamentosPorSetorId[$t['setor_id']][] = $t;
    } else {
        $treinamentosGerais[] = $t;
    }
}

// Formato leve pro calendário visual (JS lê isso de um data-attribute) --
// só os campos que a tela do calendário realmente usa.
$mapearEventosCalendario = fn (array $lista) => array_map(fn ($t) => [
    'id' => $t['id'],
    'titulo' => $t['titulo'],
    'tipo' => $t['tipo'],
    'descricao' => $t['descricao'],
    'data_inicio' => $t['data_inicio'],
    'hora_inicio' => $t['hora_inicio'],
    'data_fim' => $t['data_fim'],
    'hora_fim' => $t['hora_fim'],
    'link_reuniao' => $t['link_reuniao'],
    'status' => $t['status'],
    'setor_id' => $t['setor_id'],
    'responsavel_id' => $t['responsavel_id'],
    'chamado_id' => $t['chamado_id'],
    'destinatarios' => $destinatariosPorTreinamentoId[$t['id']] ?? [],
], $lista);

$usuarios = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();

// Todas as pessoas cadastradas pra ESSE cliente (aba Usuários) -- não
// depende mais de já estar num setor (setor_id pode ser NULL, quando ainda
// não foi relacionado a nenhum). O master (contato_* de clientes) não entra
// aqui porque não é uma linha de setor_responsaveis, é tratado à parte.
$usuariosCliente = $pdo->prepare('SELECT * FROM setor_responsaveis WHERE cliente_id = ? ORDER BY nome ASC');
$usuariosCliente->execute([$clienteId]);
$usuariosCliente = $usuariosCliente->fetchAll();

// TODOS os setores de cada pessoa (não só o "principal" em setor_id) --
// junta numa única query pra não fazer 1 SELECT por pessoa na tabela.
$setorIdsPorResponsavelId = [];
if ($usuariosCliente) {
    $idsResponsaveisCliente = array_column($usuariosCliente, 'id');
    $placeholdersResponsaveis = implode(',', array_fill(0, count($idsResponsaveisCliente), '?'));
    $stmtSetoresPorResponsavel = $pdo->prepare("SELECT setor_responsavel_id, setor_id FROM setor_responsavel_setores WHERE setor_responsavel_id IN ($placeholdersResponsaveis) ORDER BY setor_id ASC");
    $stmtSetoresPorResponsavel->execute($idsResponsaveisCliente);
    foreach ($stmtSetoresPorResponsavel->fetchAll() as $vinculo) {
        $setorIdsPorResponsavelId[$vinculo['setor_responsavel_id']][] = (int) $vinculo['setor_id'];
    }
    foreach ($usuariosCliente as &$ucComSetores) {
        $ucComSetores['setor_ids'] = $setorIdsPorResponsavelId[$ucComSetores['id']] ?? [];
    }
    unset($ucComSetores);
}

// Setores (departamentos) cadastrados pra esse cliente, com o responsável
// (se já tiver um) e insights próprios -- ver Migrations (setores,
// setor_responsaveis) e ClienteAuth (login separado pra cada setor). Cliente
// sem nenhum setor cadastrado (a maioria, hoje) não muda nada na aba
// Chamados -- os cards só aparecem pra quem tiver pelo menos 1 setor.
$setores = $pdo->prepare('
    SELECT
        s.*,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id) AS chamados_total,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status IN ("aberto","aguardando_equipe")) AS chamados_pendentes,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status IN ("aberto","aguardando_equipe") AND ch.prioridade IN ("alta","urgente")) AS chamados_urgentes,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status = "fechado") AS chamados_fechados,
        (SELECT AVG(TIMESTAMPDIFF(MINUTE, ch.criado_em, ch.fechado_em)) FROM chamados ch WHERE ch.setor_id = s.id AND ch.fechado_em IS NOT NULL) AS tempo_medio_min
    FROM setores s
    WHERE s.cliente_id = ?
    ORDER BY s.nome ASC
');
$setores->execute([$clienteId]);
$setores = $setores->fetchAll();
$nomesSetoresPorId = array_column($setores, 'nome', 'id');

// TODOS os setores do contato master (o master pode estar em vários agora,
// não só um) -- editado na aba Usuários, ver ação 'definir_setores_master'.
$idsSetoresMaster = setores_do_cliente_master($pdo, $clienteId);

// Última sugestão de IA gerada pra cada setor (histórico completo fica só
// no banco -- a tela sempre mostra a mais recente). ORDER BY criado_em DESC
// + "primeira ocorrência por setor_id vence" evita precisar de uma
// subquery por setor.
$ultimaSugestaoIaPorSetorId = [];
if ($setores) {
    $idsSetoresSugestao = array_column($setores, 'id');
    $placeholdersSugestao = implode(',', array_fill(0, count($idsSetoresSugestao), '?'));
    $stmtSugestoes = $pdo->prepare("SELECT * FROM setor_sugestoes_ia WHERE setor_id IN ($placeholdersSugestao) ORDER BY criado_em DESC");
    $stmtSugestoes->execute($idsSetoresSugestao);
    foreach ($stmtSugestoes->fetchAll() as $sug) {
        $ultimaSugestaoIaPorSetorId[$sug['setor_id']] ??= $sug;
    }
}

// Responsáveis de cada setor, agrupados por setor_id -- um setor pode ter
// VÁRIOS (pedido do Kleber, ex.: Financeiro com mais de uma pessoa). Quando
// o setor É o setor do master, o contato master também entra na lista (id
// null pra diferenciar de uma linha real de setor_responsaveis -- ele não
// tem login separado, é o mesmo login master de sempre).
$responsaveisPorSetorId = [];
if ($setores) {
    $idsSetores = array_column($setores, 'id');
    $placeholdersSetores = implode(',', array_fill(0, count($idsSetores), '?'));
    // JOIN com setor_responsavel_setores (não mais setor_id direto) --
    // uma pessoa pode estar em vários setores agora, então aparece nas
    // listas de TODOS eles, não só no que é o "principal" dela.
    $stmtResponsaveis = $pdo->prepare("
        SELECT sr.*, srs.setor_id AS setor_id_membro
        FROM setor_responsaveis sr
        INNER JOIN setor_responsavel_setores srs ON srs.setor_responsavel_id = sr.id
        WHERE srs.setor_id IN ($placeholdersSetores)
        ORDER BY sr.nome ASC
    ");
    $stmtResponsaveis->execute($idsSetores);
    foreach ($stmtResponsaveis->fetchAll() as $sr) {
        $responsaveisPorSetorId[$sr['setor_id_membro']][] = $sr;
    }
    foreach ($setores as $s) {
        if (in_array((int) $s['id'], $idsSetoresMaster, true)) {
            $responsaveisPorSetorId[$s['id']] ??= [];
            array_unshift($responsaveisPorSetorId[$s['id']], [
                'id' => null,
                'nome' => $cliente['contato_nome'] ?: $cliente['nome'],
                'email' => $cliente['contato_email'],
                'foto_perfil' => resolver_avatar_por_email($pdo, $cliente['contato_email'], $cliente['foto_perfil']),
                'portal_ativo' => 1,
                'eh_master' => true,
            ]);
        }
    }
}

// Quantos chamados desse cliente não têm setor vinculado (ou porque foram
// abertos antes dessa coluna existir, ou porque o cliente não usa setor pra
// tudo) -- mostrado como um card extra "Sem setor" quando existir pelo
// menos 1 setor cadastrado, senão esses chamados ficariam invisíveis nos
// cards.
$stmtSemSetor = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE cliente_id = ? AND setor_id IS NULL');
$stmtSemSetor->execute([$clienteId]);
$chamadosSemSetor = (int) $stmtSemSetor->fetchColumn();

$tituloPagina = $cliente['nome'];
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
        <?php if (arquivo_publico_existe($cliente['logo'])): ?>
            <img src="<?= e(asset_url($cliente['logo'])) ?>" alt="Logo <?= e($cliente['nome']) ?>" class="cliente-topo-logo">
        <?php endif; ?>
        <div>
            <h4 class="mb-0"><?= e($cliente['nome']) ?><?= $cliente['cnpj'] ? ' <small class="text-muted fw-normal fs-6">' . e(formatar_cnpj($cliente['cnpj'])) . '</small>' : '' ?></h4>
            <a href="<?= url('clientes.php') ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> Voltar para clientes</a>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="clienteTabs">
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#visao-geral"><i class="bi bi-building"></i> Visão geral</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#implantacao"><i class="bi bi-rocket-takeoff"></i> Implantação <span class="badge bg-secondary"><?= count($etapasImplantacao) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#chamados"><i class="bi bi-headset"></i> Chamados <span class="badge bg-secondary"><?= count($chamados) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#kpis"><i class="bi bi-graph-up"></i> KPIs</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#analise-risco"><i class="bi bi-stars"></i> Análise de Risco</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setores"><i class="bi bi-diagram-3"></i> Setores <span class="badge bg-secondary"><?= count($setores) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#usuarios"><i class="bi bi-people"></i> Usuários <span class="badge bg-secondary"><?= count($usuariosCliente) + 1 ?></span></a></li>
    <?php if ($podeAcessarDadosSensiveis): ?>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#acessos"><i class="bi bi-shield-lock"></i> Acessos</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tcloud"><i class="bi bi-cloud"></i> T-Cloud</a></li>
    <?php endif; ?>
</ul>

<div class="tab-content">

    <!-- VISÃO GERAL -->
    <div class="tab-pane" id="visao-geral">
        <div class="card card-stat">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <strong><i class="bi bi-globe"></i> Perfil público da empresa (IA)</strong>
                    <form method="post" class="form-pesquisar-perfil-empresa">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="pesquisar_perfil_empresa">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <button type="submit" class="btn btn-sm btn-dark" <?= AiSugestoes::configuradaBusca() ? '' : 'disabled' ?>><i class="bi bi-stars"></i> <?= $cliente['perfil_ia'] ? 'Pesquisar de novo' : 'Pesquisar com IA' ?></button>
                    </form>
                </div>
                <p class="text-muted small">A IA pesquisa no Google pelo nome (e CNPJ, se preenchido) da empresa e resume ramo de atividade, porte, situação cadastral e cidade/UF -- é informação pública, não substitui o que o próprio cliente informou no cadastro. Usa uma chave/projeto separado da análise interna de chamados/KPIs, de propósito (essa busca depende de faturamento ativado no Google Cloud; a análise interna continua sempre gratuita).</p>
                <?php if (!AiSugestoes::configuradaBusca()): ?>
                    <p class="text-muted small">Busca por IA ainda não configurada -- preencha a chave em Configurações &gt; IA Gemini (Google) (precisa de uma chave/projeto novo, com faturamento ativado no Google Cloud pra liberar a ferramenta de busca).</p>
                <?php endif; ?>
                <div class="resultado-perfil-empresa" id="resultadoPerfilEmpresa">
                    <?php if ($cliente['perfil_ia']): ?>
                        <div class="card card-stat"><div class="card-body">
                            <p class="text-muted small mb-2">Pesquisado em <?= e(formatar_data($cliente['perfil_ia_gerado_em'])) ?></p>
                            <div style="white-space: pre-wrap;"><?= e($cliente['perfil_ia']) ?></div>
                        </div></div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Ainda não pesquisado.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_cliente">
                    <input type="hidden" name="aba_retorno" value="visao-geral">
                    <div class="row g-3">
                        <div class="col-12 d-flex align-items-center gap-3">
                            <?php if (arquivo_publico_existe($cliente['logo'])): ?>
                                <img src="<?= e(asset_url($cliente['logo'])) ?>" alt="Logo" class="logo-preview-alterar">
                            <?php else: ?>
                                <div class="logo-preview-alterar logo-preview-vazio"><i class="bi bi-building"></i></div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <label class="form-label mb-1">Logo da empresa</label>
                                <input type="file" name="logo" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                                <small class="text-muted">PNG, JPG, WEBP ou SVG, até 5MB. A imagem é redimensionada automaticamente pro tamanho ideal do card.</small>
                                <button type="submit" class="btn btn-sm btn-outline-dark mt-2"><i class="bi bi-upload"></i> Enviar logo</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" value="<?= e($cliente['nome']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['ativo' => 'Ativo', 'pausado' => 'Pausado', 'encerrado' => 'Encerrado'] as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= $cliente['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cliente desde</label>
                            <input type="text" class="form-control" value="<?= formatar_data($cliente['criado_em']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CNPJ</label>
                            <input type="text" name="cnpj" class="form-control input-cnpj" value="<?= e(formatar_cnpj($cliente['cnpj'])) ?>" placeholder="00.000.000/0000-00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Responsável interno (equipe)</label>
                            <select name="responsavel_interno_id" class="form-select">
                                <option value="">-</option>
                                <?php foreach ($usuarios as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= (int) $cliente['responsavel_interno_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Quem da Prime acompanha essa conta -- útil sobretudo enquanto ainda não há um contato do cliente cadastrado.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="4"><?= e($cliente['observacoes']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" name="portal_permite_gerenciar_equipe" id="portalPermiteGerenciarEquipe" class="form-check-input" value="1" <?= $cliente['portal_permite_gerenciar_equipe'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="portalPermiteGerenciarEquipe">
                                    Deixar o master cadastrar setor/usuário sozinho no portal (aba "Setores e Usuários") -- só CRIAR e ALTERAR, exclusão continua só aqui. Desligado por padrão.
                                </label>
                            </div>
                            <div class="form-text mt-1">Liberação de acesso fora do horário comercial (portal e WhatsApp) agora fica só na aba <a href="#" id="linkIrAbaUsuariosHorario">Usuários</a>.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3">Salvar alterações</button>
                </form>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-body">
                <strong class="d-block mb-3">Contratos</strong>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Contrato social da empresa</label>
                        <?php if ($contratoSocial): ?>
                            <p class="mb-2"><a href="<?= url('download.php?id=' . $contratoSocial['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i> <?= e($contratoSocial['arquivo_nome_original']) ?></a></p>
                        <?php else: ?>
                            <p class="text-muted small mb-2">Nenhum arquivo enviado ainda.</p>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="upload_documento">
                            <input type="hidden" name="aba_retorno" value="visao-geral">
                            <input type="hidden" name="titulo" value="Contrato Social">
                            <input type="hidden" name="categoria" value="Contrato Social">
                            <input type="file" name="arquivo" class="form-control form-control-sm" required>
                            <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> <?= $contratoSocial ? 'Trocar' : 'Enviar' ?></button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contrato de prestação de serviços (PrimeERP)</label>
                        <?php if ($contratoPrestacaoServicos): ?>
                            <p class="mb-2"><a href="<?= url('download.php?id=' . $contratoPrestacaoServicos['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i> <?= e($contratoPrestacaoServicos['arquivo_nome_original']) ?></a></p>
                        <?php else: ?>
                            <p class="text-muted small mb-2">Nenhum arquivo enviado ainda.</p>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="upload_documento">
                            <input type="hidden" name="aba_retorno" value="visao-geral">
                            <input type="hidden" name="titulo" value="Contrato de Prestação de Serviços">
                            <input type="hidden" name="categoria" value="Contrato de Prestação de Serviços">
                            <input type="file" name="arquivo" class="form-control form-control-sm" required>
                            <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> <?= $contratoPrestacaoServicos ? 'Trocar' : 'Enviar' ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-body">
                <strong class="d-block mb-3"><i class="bi bi-file-earmark"></i> Outros documentos gerais</strong>
                <p class="text-muted small">Documentos que não são de um setor específico (para documento de setor, ver a aba Setores).</p>
                <?php if ($documentosGerais): ?>
                    <ul class="list-unstyled mb-3">
                        <?php foreach ($documentosGerais as $doc): ?>
                            <li class="d-flex align-items-center gap-2 mb-1">
                                <a href="<?= url('download.php?id=' . $doc['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i> <?= e($doc['titulo']) ?></a>
                                <span class="text-muted small"><?= $doc['categoria'] ? e($doc['categoria']) . ' · ' : '' ?><?= formatar_data($doc['criado_em']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="upload_documento">
                    <input type="hidden" name="aba_retorno" value="visao-geral">
                    <div class="col-md-3"><input type="text" name="titulo" class="form-control form-control-sm" placeholder="Título (opcional)"></div>
                    <div class="col-md-3"><input type="text" name="categoria" class="form-control form-control-sm" placeholder="Categoria (opcional)"></div>
                    <div class="col-md-4"><input type="file" name="arquivo" class="form-control form-control-sm" required></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-upload"></i> Enviar</button></div>
                </form>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-body">
                <strong class="d-block mb-3">Dados do contato</strong>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div id="avatarContatoMaster">
                        <?= avatar_html($cliente['contato_nome'] ?: $cliente['nome'], resolver_avatar_por_email($pdo, $cliente['contato_email'], $cliente['foto_perfil']), 'cliente') ?>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="form-avatar-master d-flex gap-2 align-items-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="atualizar_avatar_master">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <input type="file" name="foto" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
                        <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Enviar foto</button>
                    </form>
                </div>
                <p class="text-muted small mb-1">Ou escolha um avatar pronto:</p>
                <div class="galeria-avatares mb-3">
                    <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                        <form method="post" class="d-inline form-avatar-master">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="escolher_avatar_predefinido_master">
                            <input type="hidden" name="aba_retorno" value="visao-geral">
                            <input type="hidden" name="avatar" value="<?= $i ?>">
                            <button type="submit" class="avatar-opcao" title="Usar este avatar">
                                <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                            </button>
                        </form>
                    <?php endfor; ?>
                </div>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_contato">
                    <input type="hidden" name="aba_retorno" value="visao-geral">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Contato - nome</label>
                            <input type="text" name="contato_nome" class="form-control" value="<?= e($cliente['contato_nome']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Contato - e-mail</label>
                            <input type="email" name="contato_email" class="form-control" value="<?= e($cliente['contato_email']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Contato - telefone</label>
                            <input type="text" name="contato_telefone" class="form-control input-telefone" value="<?= e(formatar_telefone($cliente['contato_telefone'])) ?>" placeholder="(00) 00000-0000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">CPF ou RG do contato *</label>
                            <input type="text" name="contato_documento" class="form-control" required value="<?= e($cliente['contato_documento']) ?>" placeholder="Só números (RG pode terminar em X)">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3">Salvar dados do contato</button>
                </form>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>Portal do cliente</strong>
                    <div class="text-muted small">
                        <?= $cliente['portal_ativo'] ? '<span class="badge bg-success">Acesso ativo</span>' : '<span class="badge bg-secondary">Sem acesso ainda</span>' ?>
                        — o cliente usa este e-mail e uma senha própria para acompanhar chamados.
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="enviar_convite_portal">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <button type="submit" class="btn btn-outline-dark btn-sm" <?= $cliente['contato_email'] ? '' : 'disabled' ?>>
                            <i class="bi bi-send"></i> <?= $cliente['portal_ativo'] ? 'Reenviar convite' : 'Enviar convite de acesso' ?>
                        </button>
                    </form>
                    <?php if ($cliente['portal_ativo']): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Isso invalida a senha atual na hora. Confirma o reset?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="resetar_senha_master">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <button type="submit" class="btn btn-outline-dark btn-sm"><i class="bi bi-key"></i> Resetar senha</button>
                    </form>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="desativar_portal_master">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Desativar acesso</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <strong><i class="bi bi-calendar-event"></i> Agenda geral</strong>
                    <button type="button" class="btn btn-sm btn-dark botao-novo-treinamento-setor" data-setor-id="" data-aba-retorno="visao-geral"><i class="bi bi-plus-lg"></i> Novo</button>
                </div>
                <p class="text-muted small">Treinamentos/reuniões que não são de um setor específico (para agenda de setor, ver a aba Setores).</p>
                <div class="calendario-mensal" data-eventos='<?= e(json_encode($mapearEventosCalendario($treinamentosGerais), JSON_UNESCAPED_UNICODE)) ?>' data-setor-id="" data-aba-retorno="visao-geral"></div>
            </div>
        </div>
    </div>

    <!-- IMPLANTAÇÃO -->
    <div class="tab-pane" id="implantacao">
        <?php
        $totalEtapasImplantacao = count($etapasImplantacao);
        $concluidasImplantacao = count(array_filter($etapasImplantacao, fn ($e) => $e['status'] === 'concluida'));
        ?>
        <p class="text-muted small mb-3"><i class="bi bi-rocket-takeoff"></i> Checklist do processo de implantação desse cliente -- contrato, cadastros, treinamento, testes, go-live. Cada etapa pode ter anexo (contrato assinado, material de treinamento, evidência de teste...) e a IA pode sugerir roteiros pra ajudar.</p>

        <?php if ($totalEtapasImplantacao > 0): ?>
        <div class="card card-stat mb-3">
            <div class="card-body py-2">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="fw-semibold"><?= $concluidasImplantacao ?> de <?= $totalEtapasImplantacao ?> etapas concluídas</span>
                    <span class="text-muted small"><?= round($totalEtapasImplantacao ? $concluidasImplantacao / $totalEtapasImplantacao * 100 : 0) ?>%</span>
                </div>
                <div class="progress mt-1" style="height:6px;">
                    <div class="progress-bar bg-success" style="width: <?= $totalEtapasImplantacao ? round($concluidasImplantacao / $totalEtapasImplantacao * 100) : 0 ?>%"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card card-stat mb-3">
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="criar_etapa_implantacao">
                    <input type="hidden" name="aba_retorno" value="implantacao">
                    <div class="col-md-4"><input type="text" name="titulo" class="form-control" placeholder="Nova etapa (ex.: Assinatura de contrato)" required></div>
                    <div class="col-md-4"><input type="text" name="descricao" class="form-control" placeholder="Descrição (opcional)"></div>
                    <div class="col-md-2"><input type="date" name="data_prevista" class="form-control" title="Data prevista (opcional)"></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-dark w-100"><i class="bi bi-plus-lg"></i> Adicionar</button></div>
                </form>
            </div>
        </div>

        <?php if (!$etapasImplantacao): ?>
            <p class="text-muted text-center py-4"><i class="bi bi-rocket-takeoff" style="font-size:2rem;"></i><br>Nenhuma etapa de implantação cadastrada ainda.</p>
        <?php endif; ?>

        <?php foreach ($etapasImplantacao as $indiceEtapaImplantacao => $etapa):
            $idEtapaAtual = (int) $etapa['id'];
            $anexosEtapaAtual = $anexosImplantacaoPorEtapaId[$idEtapaAtual] ?? [];
            $statusBadgeEtapa = ['pendente' => 'secondary', 'em_andamento' => 'warning', 'concluida' => 'success'][$etapa['status']];
        ?>
        <div class="card card-stat mb-2">
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_etapa_implantacao">
                    <input type="hidden" name="aba_retorno" value="implantacao">
                    <input type="hidden" name="etapa_id" value="<?= $idEtapaAtual ?>">
                    <div class="col-md-4"><input type="text" name="titulo" class="form-control" value="<?= e($etapa['titulo']) ?>" required></div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="pendente" <?= $etapa['status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <option value="em_andamento" <?= $etapa['status'] === 'em_andamento' ? 'selected' : '' ?>>Em andamento</option>
                            <option value="concluida" <?= $etapa['status'] === 'concluida' ? 'selected' : '' ?>>Concluída</option>
                        </select>
                    </div>
                    <div class="col-md-2"><input type="date" name="data_prevista" class="form-control form-control-sm" value="<?= e($etapa['data_prevista']) ?>" title="Data prevista"></div>
                    <div class="col-md-3 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-check2"></i> Salvar</button>
                    </div>
                    <div class="col-12"><textarea name="descricao" class="form-control form-control-sm" rows="1" placeholder="Descrição (opcional)"><?= e($etapa['descricao']) ?></textarea></div>
                </form>

                <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                    <span class="badge bg-<?= $statusBadgeEtapa ?>"><?= ['pendente' => 'Pendente', 'em_andamento' => 'Em andamento', 'concluida' => 'Concluída'][$etapa['status']] ?></span>
                    <?php if ($etapa['data_prevista']): ?><span class="text-muted small">Previsto: <?= formatar_data($etapa['data_prevista']) ?></span><?php endif; ?>
                    <?php if ($etapa['data_concluida']): ?><span class="text-muted small">Concluído: <?= formatar_data($etapa['data_concluida']) ?></span><?php endif; ?>

                    <div class="ms-auto d-flex gap-1">
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="reordenar_etapa_implantacao">
                            <input type="hidden" name="aba_retorno" value="implantacao">
                            <input type="hidden" name="etapa_id" value="<?= $idEtapaAtual ?>">
                            <input type="hidden" name="sentido" value="up">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $indiceEtapaImplantacao === 0 ? 'disabled' : '' ?> title="Mover pra cima"><i class="bi bi-arrow-up"></i></button>
                        </form>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="reordenar_etapa_implantacao">
                            <input type="hidden" name="aba_retorno" value="implantacao">
                            <input type="hidden" name="etapa_id" value="<?= $idEtapaAtual ?>">
                            <input type="hidden" name="sentido" value="down">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $indiceEtapaImplantacao === count($etapasImplantacao) - 1 ? 'disabled' : '' ?> title="Mover pra baixo"><i class="bi bi-arrow-down"></i></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir a etapa \'<?= e($etapa['titulo']) ?>\'? Os anexos dela continuam salvos, só sem vínculo.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir_etapa_implantacao">
                            <input type="hidden" name="aba_retorno" value="implantacao">
                            <input type="hidden" name="etapa_id" value="<?= $idEtapaAtual ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir etapa"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>

                <div class="border-top pt-2">
                    <?php foreach ($anexosEtapaAtual as $anexoEtapa): ?>
                        <a href="<?= url('download.php?id=' . $anexoEtapa['id']) ?>" class="btn btn-sm btn-outline-dark me-1 mb-1"><i class="bi bi-paperclip"></i> <?= e($anexoEtapa['arquivo_nome_original']) ?></a>
                    <?php endforeach; ?>
                    <form method="post" enctype="multipart/form-data" class="d-inline-flex gap-1 align-items-center mt-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="upload_documento_implantacao">
                        <input type="hidden" name="aba_retorno" value="implantacao">
                        <input type="hidden" name="etapa_id" value="<?= $idEtapaAtual ?>">
                        <input type="file" name="arquivos[]" class="form-control form-control-sm" multiple required style="max-width:260px;">
                        <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Anexar</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="card card-stat mt-3">
            <div class="card-header bg-white fw-bold"><i class="bi bi-paperclip"></i> Anexos gerais (sem etapa específica)</div>
            <div class="card-body">
                <?php foreach ($anexosImplantacaoGerais as $anexoGeral): ?>
                    <a href="<?= url('download.php?id=' . $anexoGeral['id']) ?>" class="btn btn-sm btn-outline-dark me-1 mb-1"><i class="bi bi-paperclip"></i> <?= e($anexoGeral['arquivo_nome_original']) ?></a>
                <?php endforeach; ?>
                <?php if (!$anexosImplantacaoGerais): ?><p class="text-muted small mb-2">Nenhum anexo geral ainda.</p><?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="d-flex gap-1 align-items-center mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="upload_documento_implantacao">
                    <input type="hidden" name="aba_retorno" value="implantacao">
                    <input type="hidden" name="etapa_id" value="">
                    <input type="file" name="arquivos[]" class="form-control form-control-sm" multiple required style="max-width:300px;">
                    <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Anexar</button>
                </form>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-header bg-white fw-bold"><i class="bi bi-stars"></i> Sugestões de IA pra essa implantação</div>
            <div class="card-body">
                <p class="text-muted small">
                    <i class="bi bi-info-circle"></i> A IA usa os setores, a quantidade de usuários, as etapas e o CONTEÚDO dos anexos dessa implantação pra gerar cada sugestão. O texto gerado é editável -- ajuste como quiser antes de exportar em PDF.
                    <?php if (!AiSugestoes::configurada()): ?><span class="d-block mt-1">IA ainda não configurada (ver Configurações &gt; IA Gemini).</span><?php endif; ?>
                </p>
                <?php foreach ([
                    'treinamento' => ['📋', 'Roteiro de treinamento'],
                    'teste' => ['🧪', 'Roteiro de testes (homologação)'],
                    'boas_praticas' => ['✅', 'Boas práticas de implantação'],
                    'migracao_dados' => ['📦', 'Checklist de dados a migrar'],
                    'comunicacao' => ['📣', 'Plano de comunicação com usuários'],
                    'riscos' => ['⚠️', 'Riscos e pontos de atenção'],
                    'go_live' => ['🚀', 'Checklist de "pronto pra ir ao ar"'],
                ] as $tipoSugestao => [$emojiSugestao, $labelSugestao]):
                    $sugestaoExistente = $ultimaSugestaoImplantacaoPorTipo[$tipoSugestao] ?? null;
                ?>
                <div class="card mb-2">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <span class="fw-semibold"><?= $emojiSugestao ?> <?= e($labelSugestao) ?></span>
                            <form method="post" class="d-inline form-gerar-sugestao-implantacao" data-tipo="<?= $tipoSugestao ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="gerar_sugestao_implantacao">
                                <input type="hidden" name="aba_retorno" value="implantacao">
                                <input type="hidden" name="tipo" value="<?= $tipoSugestao ?>">
                                <button type="submit" class="btn btn-sm btn-dark" <?= AiSugestoes::configurada() ? '' : 'disabled' ?>><i class="bi bi-stars"></i> <?= $sugestaoExistente ? 'Gerar de novo' : 'Gerar' ?></button>
                            </form>
                        </div>
                        <div class="resultado-sugestao-implantacao mt-2" id="resultadoSugestaoImplantacao<?= $tipoSugestao ?>">
                        <?php if ($sugestaoExistente): ?>
                            <p class="text-muted small mb-1">Gerado em <?= formatar_data($sugestaoExistente['criado_em']) ?></p>
                            <textarea class="form-control form-control-sm textarea-sugestao-implantacao mb-2" rows="8"><?= e($sugestaoExistente['sugestao']) ?></textarea>
                            <button type="button" class="btn btn-sm btn-outline-dark botao-exportar-pdf-implantacao" data-tipo="<?= $tipoSugestao ?>" data-label="<?= e($labelSugestao) ?>"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</button>
                            <button type="button" class="btn btn-sm btn-outline-primary botao-salvar-edicao-implantacao" data-tipo="<?= $tipoSugestao ?>"><i class="bi bi-save"></i> Salvar alterações</button>
                            <form method="post" class="d-inline form-limpar-sugestao-implantacao" data-label="<?= e($labelSugestao) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="limpar_sugestao_implantacao">
                                <input type="hidden" name="aba_retorno" value="implantacao">
                                <input type="hidden" name="tipo" value="<?= $tipoSugestao ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Limpar</button>
                            </form>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Antes de gerar qualquer sugestão, mostra quais documentos a IA
             vai ler (o usuário pode destacar/desmarcar) e deixa escrever um
             comentário/ajuste -- os 7 botões "Gerar" acima todos abrem esse
             mesmo modal, só muda qual tipo de sugestão fica pendente. -->
        <div class="modal fade" id="modalDocsSugestaoImplantacao" tabindex="-1">
          <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-stars"></i> Gerar sugestão com IA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <p class="text-muted small">Escolha quais documentos a IA deve ler pra essa sugestão, e deixe um comentário/ajuste se quiser (opcional).</p>
                <?php if ($todosDocumentosImplantacaoIa): ?>
                <div class="mb-3">
                    <?php foreach ($todosDocumentosImplantacaoIa as $docModalIa): ?>
                    <div class="form-check">
                        <input class="form-check-input checkbox-doc-implantacao-ia" type="checkbox" value="<?= (int) $docModalIa['id'] ?>" id="docImplantacaoIa<?= (int) $docModalIa['id'] ?>" checked>
                        <label class="form-check-label" for="docImplantacaoIa<?= (int) $docModalIa['id'] ?>">
                            <i class="bi bi-file-earmark"></i> <?= e($docModalIa['arquivo_nome_original']) ?>
                            <?php if ($docModalIa['titulo'] && $docModalIa['titulo'] !== $docModalIa['arquivo_nome_original']): ?><span class="text-muted small">(<?= e($docModalIa['titulo']) ?>)</span><?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <p class="text-muted small">Nenhum documento anexado ainda nessa implantação -- a sugestão vai ser gerada só com os dados já cadastrados (setores, etapas, usuários).</p>
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Comentário / ajuste pra IA (opcional)</label>
                    <textarea class="form-control form-control-sm" id="comentarioDocsImplantacaoIa" rows="3" placeholder="Ex.: focar mais no módulo Financeiro, ou considerar que o cliente já usa outro ERP hoje..."></textarea>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-dark" id="botaoConfirmarGerarImplantacaoIa"><i class="bi bi-stars"></i> Gerar sugestão</button>
              </div>
            </div>
          </div>
        </div>
    </div>

    <!-- CHAMADOS -->
    <div class="tab-pane" id="chamados">
        <p class="text-muted small mb-2"><i class="bi bi-headset"></i> Atendimento e suporte -- dúvidas, problemas e pedidos pontuais do cliente, com resposta direta em formato de conversa.</p>

        <?php if ($setores): ?>
        <div class="painel-setores mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <p class="text-muted small mb-0"><i class="bi bi-diagram-3"></i> Esse cliente tem setores independentes -- clique num card pra ver só os chamados daquele setor.</p>
                <button type="button" class="btn btn-sm btn-outline-dark botao-ir-para-setores"><i class="bi bi-gear"></i> Gerenciar setores</button>
            </div>
            <div class="grid-setores">
                <div class="card-setor card-setor-todos active" data-setor-filtro="todos" role="button" tabindex="0">
                    <div class="card-setor-topo"><i class="bi bi-collection"></i> <span class="fw-semibold">Todos os setores</span></div>
                </div>
                <?php foreach ($setores as $s): ?>
                    <div class="card-setor <?= (int) $s['chamados_urgentes'] > 0 ? 'setor-atencao' : '' ?>" data-setor-filtro="<?= $s['id'] ?>" role="button" tabindex="0" style="<?= (int) $s['chamados_urgentes'] > 0 ? '' : 'border-left: 4px solid ' . cor_setor($s['id']) . ';' ?>">
                        <div class="card-setor-topo">
                            <span class="fw-semibold"><?= icone_setor_html($s) ?> <?= e($s['nome']) ?></span>
                            <?php if (!$s['ativo']): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                        </div>
                        <div class="card-setor-responsavel">
                            <?php $membrosSetor = $responsaveisPorSetorId[$s['id']] ?? []; ?>
                            <?php if ($membrosSetor): ?>
                                <div class="card-setor-responsavel-avatares">
                                    <?php foreach (array_slice($membrosSetor, 0, 3) as $m): ?>
                                        <?= avatar_html($m['nome'], resolver_avatar_por_email($pdo, $m['email'], $m['foto_perfil']), 'cliente') ?>
                                    <?php endforeach; ?>
                                </div>
                                <span><?= e($membrosSetor[0]['nome']) ?><?= count($membrosSetor) > 1 ? ' e mais ' . (count($membrosSetor) - 1) : '' ?></span>
                            <?php else: ?>
                                <span class="text-muted small"><i class="bi bi-person-x"></i> Sem responsável cadastrado</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-setor-insights">
                            <span class="badge modulo-pill <?= (int) $s['chamados_pendentes'] > 0 ? 'badge-aguardando-equipe' : 'modulo-ok' ?>">
                                <?= (int) $s['chamados_pendentes'] ?> pendente<?= (int) $s['chamados_pendentes'] !== 1 ? 's' : '' ?>
                            </span>
                            <?php if ((int) $s['chamados_urgentes'] > 0): ?>
                                <span class="badge prio-urgente modulo-pill"><?= (int) $s['chamados_urgentes'] ?> urgente<?= (int) $s['chamados_urgentes'] !== 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                            <span class="badge modulo-pill modulo-neutro"><?= (int) $s['chamados_total'] ?> no total</span>
                            <span class="badge modulo-pill modulo-neutro" title="Tempo médio de atendimento"><i class="bi bi-clock-history"></i> <?= e(formatar_duracao_minutos($s['tempo_medio_min'] !== null ? (float) $s['tempo_medio_min'] : null)) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($chamadosSemSetor > 0): ?>
                    <div class="card-setor" data-setor-filtro="sem-setor" role="button" tabindex="0">
                        <div class="card-setor-topo"><span class="fw-semibold text-muted">Sem setor</span></div>
                        <div class="card-setor-insights"><span class="badge modulo-pill modulo-neutro"><?= $chamadosSemSetor ?> chamado<?= $chamadosSemSetor !== 1 ? 's' : '' ?></span></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="toolbar">
            <div class="toolbar-acoes">
                <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoChamado"><i class="bi bi-plus-lg"></i> Registrar chamado</button>
                <?php if (!$setores): ?>
                    <button type="button" class="btn btn-outline-dark botao-ir-para-setores"><i class="bi bi-diagram-3"></i> Configurar setores</button>
                <?php endif; ?>
            </div>
            <div class="toolbar-filtros">
                <div class="btn-group flex-wrap" role="group" id="filtroStatusChamados">
                    <button type="button" class="btn btn-sm filtro-pill filtro-pendentes active" data-filtro="pendentes"><i class="bi bi-exclamation-diamond"></i> Pendentes</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-todos" data-filtro="todos"><i class="bi bi-collection"></i> Todos</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-aberto" data-filtro="aberto"><i class="bi bi-envelope-open"></i> Abertos</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-aguardando_equipe" data-filtro="aguardando_equipe"><i class="bi bi-exclamation-circle-fill"></i> Aguardando nós</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-aguardando_cliente" data-filtro="aguardando_cliente"><i class="bi bi-hourglass-split"></i> Aguardando cliente</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-fechado" data-filtro="fechado"><i class="bi bi-x-circle"></i> Fechados</button>
                </div>
                <button type="button" class="btn btn-sm btn-exportar" data-bs-toggle="modal" data-bs-target="#modalExportarChamados">
                    <i class="bi bi-file-earmark-excel"></i> Exportar
                </button>
            </div>
        </div>

        <div id="listaChamados">
        <?php foreach ($chamados as $c): ?>
        <div class="card chamado-card mb-3" id="chamado-<?= (int) $c['id'] ?>" data-status="<?= e($c['status']) ?>" data-prioridade="<?= e($c['prioridade']) ?>" data-setor="<?= $c['setor_id'] ? (int) $c['setor_id'] : 'sem-setor' ?>">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <strong class="d-block mb-1"><span class="text-muted fw-normal">#<?= (int) $c['id'] ?></span> <?= e($c['titulo']) ?></strong>
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <?php if ($setores): ?>
                            <span class="d-flex align-items-center gap-1">
                                <span class="badge-rotulo">Setor</span>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="atribuir_setor_chamado">
                                    <input type="hidden" name="aba_retorno" value="chamados">
                                    <input type="hidden" name="scroll_para" value="chamado-<?= $c['id'] ?>">
                                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                                    <select name="setor_id" class="form-select form-select-sm select-responsavel-chamado" onchange="this.form.submit()" title="Trocar o setor avisa o responsável dele por e-mail -- setor sem responsável cadastrado fica bloqueado aqui">
                                        <option value="" <?= !$c['setor_id'] ? 'selected' : '' ?>>Sem setor</option>
                                        <?php foreach ($setores as $s): $temResponsavel = !empty($responsaveisPorSetorId[$s['id']]); ?>
                                            <option value="<?= $s['id'] ?>" <?= (int) $c['setor_id'] === (int) $s['id'] ? 'selected' : '' ?> <?= !$temResponsavel ? 'disabled' : '' ?>><?= e($s['nome']) ?><?= !$temResponsavel ? ' (sem responsável)' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </span>
                            <?php endif; ?>
                            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Status</span> <?= badge_status_chamado($c['status']) ?></span>
                            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Prioridade</span> <?= badge_prioridade($c['prioridade']) ?></span>
                            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Origem</span> <?= $c['aberto_por'] === 'cliente'
                                ? '<span class="badge badge-origem-cliente"><i class="bi bi-person-fill"></i> Cliente</span>'
                                : '<span class="badge badge-origem-equipe"><i class="bi bi-headset"></i> Equipe</span>' ?></span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="badge-rotulo">Responsável</span>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="atribuir_responsavel_chamado">
                                    <input type="hidden" name="aba_retorno" value="chamados">
                                    <input type="hidden" name="scroll_para" value="chamado-<?= $c['id'] ?>">
                                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                                    <select name="responsavel_id" class="form-select form-select-sm select-responsavel-chamado" onchange="this.form.submit()" title="Trocar o responsável avisa a pessoa por e-mail">
                                        <option value="">Sem responsável</option>
                                        <?php foreach ($usuarios as $u): ?>
                                            <option value="<?= $u['id'] ?>" <?= (int) $c['responsavel_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </span>
                        </div>
                    </div>
                    <!-- O status "no meio" (aguardando nós / aguardando cliente) é sempre
                         automático, calculado por quem respondeu por último -- a única
                         ação manual que sobra é encerrar ou reabrir o chamado. -->
                    <div class="chamado-acoes">
                        <a href="<?= url('imprimir_chamado.php?id=' . $c['id']) ?>" target="_blank" class="btn btn-sm btn-imprimir"><i class="bi bi-printer"></i> Imprimir</a>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="<?= $c['status'] === 'fechado' ? 'reabrir_chamado' : 'fechar_chamado' ?>">
                            <input type="hidden" name="aba_retorno" value="chamados">
                            <input type="hidden" name="scroll_para" value="chamado-<?= $c['id'] ?>">
                            <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                            <?php if ($c['status'] === 'fechado'): ?>
                                <button type="submit" class="btn btn-reabrir"><i class="bi bi-arrow-counterclockwise"></i> Reabrir</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-encerrar"><i class="bi bi-check2-circle"></i> Encerrar</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-clock"></i> Aberto em <?= formatar_data($c['criado_em']) ?><?= $c['categoria'] ? ' · ' . e($c['categoria']) : '' ?></p>

                <?= render_resumo_anexos_chamado($anexosPorChamado[$c['id']] ?? []) ?>
                <?= render_reunioes_vinculadas_chamado($treinamentosPorChamadoId[$c['id']] ?? []) ?>

                <!-- Toda a conversa (mensagem de abertura + respostas) já vem normalizada
                     em $linhasChatPorChamado -- cliente sempre à esquerda e em verde,
                     equipe (nós) sempre à direita e em azul/roxo, com nome do contato,
                     avatar e anexos em todas as linhas, inclusive a de abertura. -->
                <div class="chat-thread">
                <?php foreach ($linhasChatPorChamado[$c['id']] ?? [] as $linha): ?>
                    <?php $ladoDireita = $linha['autor_tipo'] === 'equipe'; ?>
                    <div class="chat-linha <?= $ladoDireita ? 'chat-direita' : 'chat-esquerda' ?>">
                        <?= avatar_html($linha['autor_nome'], $linha['foto'], $linha['autor_tipo']) ?>
                        <div class="chat-bolha bolha-<?= e($linha['autor_tipo']) ?>">
                            <span class="chat-autor"><?= e($linha['autor_nome']) ?></span>
                            <span style="white-space: pre-wrap;"><?= e($linha['mensagem']) ?></span>
                            <?= render_anexos_html($linha['anexos']) ?>
                            <span class="chat-hora"><?= date('d/m/Y H:i', strtotime($linha['criado_em'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <form method="post" enctype="multipart/form-data" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="responder_chamado">
                    <input type="hidden" name="aba_retorno" value="chamados">
                    <input type="hidden" name="scroll_para" value="chamado-<?= $c['id'] ?>">
                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                    <textarea name="mensagem" class="form-control form-control-sm mb-2" rows="2" placeholder="Responder ao cliente..."></textarea>
                    <div class="d-flex flex-wrap align-items-center gap-3 mb-2 resposta-opcoes-envio">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="notificar_cliente" id="notificarCliente<?= $c['id'] ?>" value="1" checked>
                            <label class="form-check-label small" for="notificarCliente<?= $c['id'] ?>" title="Desmarque pra registrar a resposta sem avisar o cliente por e-mail/WhatsApp (ex.: encerrando chamado antigo)">Notificar cliente</label>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="small text-muted mb-0" for="statusAposResposta<?= $c['id'] ?>">Deixar como:</label>
                            <select name="status_apos_resposta" id="statusAposResposta<?= $c['id'] ?>" class="form-select form-select-sm w-auto">
                                <option value="aguardando_cliente" selected>Aguardando cliente</option>
                                <option value="aguardando_equipe">Aguardando nós</option>
                                <option value="fechado">Fechado (encerrar)</option>
                            </select>
                        </div>
                    </div>
                    <div class="anexos-stager" data-max-arquivos="<?= ANEXO_CHAMADO_MAX_QUANTIDADE ?>" data-max-tamanho-bytes="<?= ANEXO_CHAMADO_MAX_SIZE ?>">
                        <input type="file" name="anexos[]" class="anexos-stager-input" multiple accept="<?= e(ANEXO_CHAMADO_ACCEPT_HTML) ?>">
                        <div class="anexos-stager-controles">
                            <button type="button" class="btn btn-outline-dark btn-sm anexos-stager-escolher"><i class="bi bi-paperclip"></i> Escolher anexos</button>
                            <button type="button" class="btn btn-dark btn-sm anexos-stager-enviar" disabled><i class="bi bi-plus-lg"></i> Enviar anexos</button>
                            <span class="anexos-stager-pendente text-muted"></span>
                        </div>
                        <div class="anexos-stager-erro alert alert-danger d-none"></div>
                        <ul class="anexos-stager-lista list-unstyled"></ul>
                    </div>
                    <p class="anexos-upload-preview">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada (imagem, PDF, Word/Excel/PowerPoint, texto ou ZIP).</p>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-send"></i> Enviar resposta</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php if (empty($chamados)): ?>
            <p class="text-muted text-center py-4">Nenhum chamado registrado ainda.</p>
        <?php endif; ?>
        <p class="text-muted text-center py-3 d-none" id="avisoFiltroVazio">Nenhum chamado com esse status.</p>
    </div>

    <!-- KPIS -->
    <div class="tab-pane" id="kpis">
        <p class="text-muted small mb-1"><i class="bi bi-graph-up"></i> KPI (métrica) é qualquer número que ajuda a acompanhar a saúde do atendimento -- ex.: quantos chamados abriram, quantos foram resolvidos, tempo médio de resposta. A <strong>meta</strong> é o número que você quer bater; sem meta cadastrada o card fica cinza (neutro), não vermelho -- ausência de meta não é meta não batida.</p>
        <p class="text-muted small mb-2">Essa aba aqui é o <strong>consolidado do cliente inteiro</strong> (todos os chamados, de qualquer setor) -- <?= $setores ? 'se quiser o número só de UM setor específico, use "Registrar métrica" dentro da aba Setores.' : 'esse cliente ainda não tem setor cadastrado, então é a única visão que existe por enquanto.' ?> Importante: KPI é só acompanhamento manual/automático deste cliente -- a seção <a href="<?= url('relatorios.php') ?>">Relatórios</a> (chamados geral, estatísticas etc.) é independente e não lê esses valores.</p>
        <div class="toolbar d-flex flex-wrap gap-2 align-items-end justify-content-between">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <button type="button" class="btn btn-dark btn-sm botao-novo-kpi-geral"><i class="bi bi-plus-lg"></i> Registrar KPI</button>
                <form method="post" class="d-flex gap-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="calcular_kpis_automaticos">
                    <input type="hidden" name="aba_retorno" value="kpis">
                    <input type="hidden" name="setor_id" value="">
                    <div>
                        <label class="form-label small mb-1">Calcular a partir de TODOS os chamados do cliente</label>
                        <input type="month" name="periodo" class="form-control form-control-sm" value="<?= date('Y-m') ?>" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-magic"></i> Calcular automaticamente</button>
                </form>
            </div>
            <div class="kpi-personalizar d-flex align-items-center gap-2">
                <label class="form-label small mb-0" for="kpiColunasSelect"><i class="bi bi-grid-3x3-gap"></i> Cards por linha</label>
                <select id="kpiColunasSelect" class="form-select form-select-sm">
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="6">6</option>
                </select>
            </div>
        </div>
        <?= kpi_grades_html($kpisGerais, 'kpis', 'Nenhum KPI geral registrado ainda -- use "Registrar KPI" ou "Calcular automaticamente" acima.') ?>
    </div>

    <!-- ANÁLISE DE RISCO -->
    <div class="tab-pane" id="analise-risco">
        <p class="text-muted small mb-1"><i class="bi bi-stars"></i> A IA analisa o histórico de chamados (do cliente inteiro ou só de um setor) e gera dois tipos BEM diferentes de documento: <strong>Risco</strong> (uso interno da Prime -- nível de risco de perder o cliente e sugestões pro nosso atendimento) e <strong>Melhorias</strong> (relatório com tom profissional, pensado pra ser entregue direto ao cliente).</p>
        <p class="text-muted small mb-3">Mesma IA (Gemini, plano gratuito) já usada nas sugestões de Setores e Implantação -- só lê dados já cadastrados no sistema, nunca busca na internet.</p>

        <form method="post" class="row g-2 align-items-end mb-4 form-gerar-analise-risco">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="gerar_analise_risco">
            <div class="col-md-4">
                <label class="form-label small mb-1">Tipo de análise</label>
                <select name="tipo" class="form-select form-select-sm">
                    <option value="risco">Risco (uso interno)</option>
                    <option value="melhorias">Melhorias (relatório pro cliente)</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Escopo</label>
                <select name="setor_id" class="form-select form-select-sm">
                    <option value="">Cliente inteiro (todos os setores)</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-sm btn-dark w-100" <?= AiSugestoes::configurada() ? '' : 'disabled' ?>><i class="bi bi-stars"></i> Gerar análise</button>
            </div>
            <?php if (!AiSugestoes::configurada()): ?><div class="col-12"><span class="text-muted small">IA ainda não configurada (ver Configurações &gt; IA Gemini).</span></div><?php endif; ?>
        </form>

        <?php foreach (['risco' => ['Análises de Risco', 'uso interno da Prime'], 'melhorias' => ['Análises de Melhorias', 'relatório pro cliente']] as $tipoSecaoAnalise => [$tituloSecaoAnalise, $legendaSecaoAnalise]): ?>
        <h6 class="text-muted text-uppercase small fw-bold mb-1"><?= $tituloSecaoAnalise ?></h6>
        <p class="text-muted small mb-2"><?= $legendaSecaoAnalise ?></p>
        <?php if (!$analisesRiscoPorTipo[$tipoSecaoAnalise]): ?>
            <div class="analise-grade mb-4" id="gridAnalise<?= e($tipoSecaoAnalise) ?>">
                <p class="analise-grade-vazio text-muted text-center py-3 mb-0">Nenhuma análise de <?= mb_strtolower($tituloSecaoAnalise) ?> gerada ainda.</p>
            </div>
        <?php else: ?>
            <div class="analise-grade mb-4" id="gridAnalise<?= e($tipoSecaoAnalise) ?>">
            <?php foreach ($analisesRiscoPorTipo[$tipoSecaoAnalise] as $a): ?>
                <?= analise_card_html($a, $tituloSecaoAnalise) ?>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Visualizar/editar uma análise (Risco ou Melhorias) -- um modal só,
         reaproveitado pelos dois modos (view = readonly, edit = editável),
         ver JS do botao-visualizar-analise/botao-editar-analise. -->
    <div class="modal fade" id="modalAnalise" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="modalAnaliseTitulo"></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <textarea class="form-control" id="modalAnaliseTexto" rows="18"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-sm btn-outline-dark" id="botaoModalAnaliseExportarPdf"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</button>
            <button type="button" class="btn btn-sm btn-dark d-none" id="botaoModalAnaliseSalvar"><i class="bi bi-save"></i> Salvar alterações</button>
          </div>
        </div>
      </div>
    </div>

    <!-- SETORES -->
    <div class="tab-pane" id="setores">
        <p class="text-muted small">Cada setor tem seu próprio espaço: configurações, calendário de treinamentos/reuniões, documentos, propostas de melhoria, métricas e sugestões de IA sobre o processo dele.</p>

        <form method="post" class="row g-2 align-items-end mb-2">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="criar_setor">
            <input type="hidden" name="aba_retorno" value="setores">
            <input type="hidden" name="icone" id="novoSetorIcone" value="bi-diagram-3">
            <div class="col"><input type="text" name="nome" id="novoSetorNome" class="form-control" placeholder="Nome do novo setor" required></div>
            <div class="col-auto"><button type="submit" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Criar setor</button></div>
        </form>
        <div class="seletor-icone-setor mb-2" data-campo-destino="novoSetorIcone">
            <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                <button type="button" class="icone-opcao <?= $ic === 'bi-diagram-3' ? 'active' : '' ?>" data-icone="<?= $ic ?>" title="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></button>
            <?php endforeach; ?>
        </div>

        <?php
        $nomesSetoresExistentesLower = array_map('mb_strtolower', array_column($setores, 'nome'));
        $sugestoesSetorDisponiveis = array_filter(SETORES_SUGERIDOS, fn ($nomeSug) => !in_array(mb_strtolower($nomeSug), $nomesSetoresExistentesLower, true), ARRAY_FILTER_USE_KEY);
        ?>
        <?php if ($sugestoesSetorDisponiveis): ?>
        <div class="mb-4">
            <p class="text-muted small mb-1">Sugestões rápidas (clique pra preencher nome + ícone):</p>
            <?php foreach ($sugestoesSetorDisponiveis as $nomeSug => $iconeSug): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-1 botao-sugestao-setor" data-nome="<?= e($nomeSug) ?>" data-icone="<?= e($iconeSug) ?>"><i class="bi <?= e($iconeSug) ?>"></i> <?= e($nomeSug) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$setores): ?>
            <p class="text-muted text-center py-4 mb-0"><i class="bi bi-diagram-3" style="font-size:2rem;"></i><br>Nenhum setor cadastrado ainda.</p>
        <?php endif; ?>

        <?php foreach ($setores as $indiceSetor => $s):
            $idSetorAtual = (int) $s['id'];
            $membrosReais = array_filter($responsaveisPorSetorId[$idSetorAtual] ?? [], fn ($m) => empty($m['eh_master']));
            $treinamentosSetor = $treinamentosPorSetorId[$idSetorAtual] ?? [];
            $documentosSetor = $documentosPorSetorId[$idSetorAtual] ?? [];
            $videoAulasSetor = $videoAulasPorSetorId[$idSetorAtual] ?? [];
            $fontesAdvplSetor = $fontesAdvplPorSetorId[$idSetorAtual] ?? [];
            $propostasSetor = $propostasPorSetorId[$idSetorAtual] ?? [];
            $kpisSetor = $kpisPorSetorId[$idSetorAtual] ?? [];
            $sugestaoIaSetor = $ultimaSugestaoIaPorSetorId[$idSetorAtual] ?? null;
            $candidatosRelacionar = array_filter($usuariosCliente, fn ($u) => !in_array($idSetorAtual, $u['setor_ids'], true));
        ?>
        <details class="setor-detalhe" style="border-left-color: <?= cor_setor($idSetorAtual) ?>" <?= $indiceSetor === 0 ? 'open' : '' ?>>
            <summary>
                <?= icone_setor_html($s) ?>
                <span class="fw-semibold"><?= e($s['nome']) ?></span>
                <?php if (!$s['ativo']): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                <span class="text-muted small">
                    — <?= count($membrosReais) ?> <?= count($membrosReais) === 1 ? 'responsável' : 'responsáveis' ?><?= in_array($idSetorAtual, $idsSetoresMaster, true) ? ' + master' : '' ?>
                </span>
            </summary>
            <div class="setor-detalhe-corpo">
                <ul class="nav nav-tabs nav-tabs-sm" id="setorSubTabs<?= $idSetorAtual ?>">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#setorConfig<?= $idSetorAtual ?>"><i class="bi bi-gear"></i> Configurações</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setorCalendario<?= $idSetorAtual ?>"><i class="bi bi-calendar-event"></i> Calendário <span class="badge bg-secondary"><?= count($treinamentosSetor) ?></span></a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setorDocs<?= $idSetorAtual ?>"><i class="bi bi-file-earmark"></i> Documentos <span class="badge bg-secondary"><?= count($documentosSetor) ?></span></a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setorPropostas<?= $idSetorAtual ?>"><i class="bi bi-lightbulb"></i> Propostas <span class="badge bg-secondary"><?= count($propostasSetor) ?></span></a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setorMetricas<?= $idSetorAtual ?>"><i class="bi bi-graph-up"></i> Métricas <span class="badge bg-secondary"><?= count($kpisSetor) ?></span></a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setorIa<?= $idSetorAtual ?>"><i class="bi bi-stars"></i> IA</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setorVideoAulas<?= $idSetorAtual ?>"><i class="bi bi-play-circle"></i> Vídeo aulas <span class="badge bg-secondary"><?= count($videoAulasSetor) ?></span></a></li>
                    <?php if ($podeAcessarDadosSensiveis): ?>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setorFontesAdvpl<?= $idSetorAtual ?>"><i class="bi bi-file-earmark-code"></i> Fontes ADVPL <span class="badge bg-secondary"><?= count($fontesAdvplSetor) ?></span></a></li>
                    <?php endif; ?>
                </ul>
                <div class="tab-content pt-3">

                    <!-- Configurações -->
                    <div class="tab-pane show active" id="setorConfig<?= $idSetorAtual ?>">
                        <form method="post" class="row g-2 align-items-end mb-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="atualizar_setor">
                            <input type="hidden" name="aba_retorno" value="setores">
                            <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                            <input type="hidden" name="icone" id="setorIcone<?= $idSetorAtual ?>" value="<?= e($s['icone'] ?: 'bi-diagram-3') ?>">
                            <div class="col-md-6"><label class="form-label">Nome do setor</label><input type="text" name="nome" class="form-control" value="<?= e($s['nome']) ?>" required></div>
                            <div class="col-md-3 form-check ms-2 mb-2">
                                <input type="checkbox" name="ativo" id="setorAtivo<?= $idSetorAtual ?>" class="form-check-input" value="1" <?= $s['ativo'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="setorAtivo<?= $idSetorAtual ?>">Ativo</label>
                            </div>
                            <div class="col-md-3"><button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-check2"></i> Salvar</button></div>
                        </form>
                        <div class="seletor-icone-setor mb-3" data-campo-destino="setorIcone<?= $idSetorAtual ?>">
                            <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                                <button type="button" class="icone-opcao <?= $ic === ($s['icone'] ?: 'bi-diagram-3') ? 'active' : '' ?>" data-icone="<?= $ic ?>" title="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></button>
                            <?php endforeach; ?>
                        </div>

                        <hr>
                        <p class="text-muted small mb-2"><i class="bi bi-people"></i> Responsáveis (cada um com login próprio no portal, só desse setor)</p>
                        <?php if (!$membrosReais): ?>
                            <p class="text-muted small">Nenhum responsável relacionado ainda<?= in_array($idSetorAtual, $idsSetoresMaster, true) ? ' (o master já cobre esse setor, mas fica à vontade pra relacionar mais alguém)' : '' ?>.</p>
                        <?php endif; ?>
                        <?php foreach ($membrosReais as $m): ?>
                        <div class="responsavel-setor-item d-flex align-items-center gap-3 mb-2">
                            <?= avatar_html($m['nome'], resolver_avatar_por_email($pdo, $m['email'], $m['foto_perfil']), 'cliente') ?>
                            <div class="flex-grow-1">
                                <div class="fw-semibold"><?= e($m['nome']) ?></div>
                                <div class="text-muted small"><?= e($m['email']) ?></div>
                            </div>
                            <span class="badge bg-<?= $m['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $m['portal_ativo'] ? 'Portal ativo' : 'Sem acesso' ?></span>
                            <div class="d-flex flex-wrap gap-1">
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="enviar_convite_setor">
                                    <input type="hidden" name="aba_retorno" value="setores">
                                    <input type="hidden" name="responsavel_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-send"></i> <?= $m['portal_ativo'] ? 'Reenviar convite' : 'Convite' ?></button>
                                </form>
                                <?php if ($m['portal_ativo']): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Isso invalida a senha atual de <?= e($m['nome']) ?> na hora. Confirma o reset?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="resetar_senha_setor">
                                    <input type="hidden" name="aba_retorno" value="setores">
                                    <input type="hidden" name="responsavel_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-key"></i> Resetar senha</button>
                                </form>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="desativar_portal_setor">
                                    <input type="hidden" name="aba_retorno" value="setores">
                                    <input type="hidden" name="responsavel_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Desativar acesso</button>
                                </form>
                                <?php endif; ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Desvincular <?= e($m['nome']) ?> desse setor? A pessoa continua cadastrada na aba Usuários (e em outros setores que ela tenha).');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="desvincular_responsavel_setor">
                                    <input type="hidden" name="aba_retorno" value="setores">
                                    <input type="hidden" name="responsavel_id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Desvincular</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($candidatosRelacionar): ?>
                        <form method="post" class="row g-2 align-items-end mt-2 mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="relacionar_responsavel_setor">
                            <input type="hidden" name="aba_retorno" value="setores">
                            <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                            <div class="col">
                                <label class="form-label small mb-1"><i class="bi bi-search"></i> Buscar usuário já cadastrado no cliente e relacionar a esse setor:</label>
                                <select name="responsavel_id" class="form-select form-select-sm" required>
                                    <option value="" disabled selected>Selecione...</option>
                                    <?php foreach ($candidatosRelacionar as $cand): ?>
                                        <?php $nomesSetoresDoCandidato = array_map(fn ($sid) => $nomesSetoresPorId[$sid] ?? '?', $cand['setor_ids']); ?>
                                        <option value="<?= $cand['id'] ?>"><?= e($cand['nome']) ?> (<?= e($cand['email']) ?>)<?= $nomesSetoresDoCandidato ? ' — hoje em ' . e(implode(', ', $nomesSetoresDoCandidato)) : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto"><button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-link-45deg"></i> Relacionar</button></div>
                        </form>
                        <?php else: ?>
                            <p class="text-muted small">Nenhum outro usuário do cliente disponível pra relacionar -- cadastre um na aba <strong>Usuários</strong>.</p>
                        <?php endif; ?>

                        <hr>
                        <form method="post" onsubmit="return confirm('Excluir o setor &quot;<?= e($s['nome']) ?>&quot;? Os chamados e responsáveis dele continuam salvos, só ficam sem setor vinculado.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir_setor">
                            <input type="hidden" name="aba_retorno" value="setores">
                            <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir setor</button>
                        </form>
                    </div>

                    <!-- Calendário -->
                    <div class="tab-pane" id="setorCalendario<?= $idSetorAtual ?>">
                        <div class="d-flex justify-content-end mb-2">
                            <button type="button" class="btn btn-sm btn-dark botao-novo-treinamento-setor" data-setor-id="<?= $idSetorAtual ?>" data-aba-retorno="setores"><i class="bi bi-plus-lg"></i> Novo treinamento/reunião</button>
                        </div>
                        <div class="calendario-mensal" data-eventos='<?= e(json_encode($mapearEventosCalendario($treinamentosSetor), JSON_UNESCAPED_UNICODE)) ?>' data-setor-id="<?= $idSetorAtual ?>" data-aba-retorno="setores"></div>
                        <?php if ($treinamentosSetor): ?>
                        <div class="table-responsive mt-3">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Título</th><th>Tipo</th><th>Status</th><th>Quando</th><th>Responsável</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($treinamentosSetor as $t): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($t['titulo']) ?><?php if ($t['chamado_id']): ?> <span class="badge bg-light text-dark border" title="Vinculado ao chamado #<?= (int) $t['chamado_id'] ?>"><i class="bi bi-link-45deg"></i> #<?= (int) $t['chamado_id'] ?></span><?php endif; ?></td>
                                    <td><?= badge_tipo_treinamento($t['tipo']) ?></td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="acao" value="atualizar_treinamento_status">
                                            <input type="hidden" name="aba_retorno" value="setores">
                                            <input type="hidden" name="treinamento_id" value="<?= $t['id'] ?>">
                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <?php foreach (['planejado' => 'Planejado', 'em_andamento' => 'Em andamento', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'] as $val => $label): ?>
                                                    <option value="<?= $val ?>" <?= $t['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="small">
                                        <?= formatar_data($t['data_inicio']) ?><?= $t['hora_inicio'] ? ' ' . substr($t['hora_inicio'], 0, 5) : '' ?>
                                        <?php if ($t['data_fim'] && $t['data_fim'] !== $t['data_inicio']): ?><br>até <?= formatar_data($t['data_fim']) ?><?php endif; ?>
                                    </td>
                                    <td><?= e($t['responsavel_nome'] ?: '-') ?></td>
                                    <td class="text-end">
                                        <?php if ($t['link_reuniao']): ?><a href="<?= e($t['link_reuniao']) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-camera-video"></i> Entrar</a><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Documentos -->
                    <div class="tab-pane" id="setorDocs<?= $idSetorAtual ?>">
                        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="upload_documento">
                            <input type="hidden" name="aba_retorno" value="setores">
                            <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                            <div class="col-md-3"><input type="text" name="titulo" class="form-control form-control-sm" placeholder="Título (opcional)"></div>
                            <div class="col-md-3"><input type="text" name="categoria" class="form-control form-control-sm" placeholder="Categoria (opcional)"></div>
                            <div class="col-md-4"><input type="file" name="arquivo" class="form-control form-control-sm" required></div>
                            <div class="col-md-2"><button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-upload"></i> Enviar</button></div>
                        </form>
                        <?php if ($documentosSetor): ?>
                        <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Título</th><th>Categoria</th><th>Arquivo</th><th>Enviado por</th><th>Data</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($documentosSetor as $doc): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($doc['titulo']) ?></td>
                                    <td><?= e($doc['categoria'] ?: '-') ?></td>
                                    <td><?= e($doc['arquivo_nome_original']) ?> <small class="text-muted">(<?= round($doc['arquivo_tamanho']/1024) ?> KB)</small></td>
                                    <td><?= e($doc['criado_por_nome'] ?: '-') ?></td>
                                    <td><?= formatar_data($doc['criado_em']) ?></td>
                                    <td class="text-end"><a href="<?= url('download.php?id=' . $doc['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">Nenhum documento desse setor ainda.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Propostas de desenvolvimento -->
                    <div class="tab-pane" id="setorPropostas<?= $idSetorAtual ?>">
                        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="criar_proposta">
                            <input type="hidden" name="aba_retorno" value="setores">
                            <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                            <div class="col-md-3"><input type="text" name="titulo" class="form-control form-control-sm" placeholder="Título da proposta" required></div>
                            <div class="col-md-4"><input type="text" name="descricao" class="form-control form-control-sm" placeholder="Descrição (opcional)"></div>
                            <div class="col-md-3"><input type="file" name="arquivo" class="form-control form-control-sm" title="Anexo opcional (ex.: PDF de uma proposta de integração)"></div>
                            <div class="col-md-2"><button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-plus-lg"></i> Registrar</button></div>
                        </form>
                        <?php if ($propostasSetor): ?>
                        <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Título</th><th>Descrição</th><th>Anexo</th><th>Status</th><th>Criado por</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($propostasSetor as $p): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($p['titulo']) ?></td>
                                    <td class="text-muted small"><?= e($p['descricao']) ?></td>
                                    <td>
                                        <?php if ($p['documento_id']): ?>
                                            <a href="<?= url('download.php?id=' . $p['documento_id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-paperclip"></i> <?= e($p['arquivo_nome_original']) ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="acao" value="atualizar_status_proposta">
                                            <input type="hidden" name="aba_retorno" value="setores">
                                            <input type="hidden" name="proposta_id" value="<?= $p['id'] ?>">
                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <?php foreach (['proposta' => 'Proposta', 'aprovada' => 'Aprovada', 'em_andamento' => 'Em andamento', 'concluida' => 'Concluída', 'recusada' => 'Recusada'] as $val => $label): ?>
                                                    <option value="<?= $val ?>" <?= $p['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?= e($p['criado_por_nome'] ?: '-') ?></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir a proposta &quot;<?= e($p['titulo']) ?>&quot;?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="acao" value="excluir_proposta">
                                            <input type="hidden" name="aba_retorno" value="setores">
                                            <input type="hidden" name="proposta_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">Nenhuma proposta de melhoria registrada ainda pra esse setor.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Métricas -->
                    <div class="tab-pane" id="setorMetricas<?= $idSetorAtual ?>">
                        <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                            <button type="button" class="btn btn-sm btn-dark botao-novo-kpi-setor" data-setor-id="<?= $idSetorAtual ?>"><i class="bi bi-plus-lg"></i> Registrar métrica</button>
                            <form method="post" class="d-flex gap-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="calcular_kpis_automaticos">
                                <input type="hidden" name="aba_retorno" value="setores">
                                <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                                <div>
                                    <label class="form-label small mb-1">Calcular a partir dos chamados</label>
                                    <input type="month" name="periodo" class="form-control form-control-sm" value="<?= date('Y-m') ?>" required>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-magic"></i> Calcular automaticamente</button>
                            </form>
                        </div>
                        <p class="text-muted small">O cálculo automático usa os chamados desse setor no mês escolhido: quantos abriram, quantos foram resolvidos, quantos eram urgente/alta prioridade e o tempo médio de atendimento. Se já existir um KPI com o mesmo nome nesse mês, ele é atualizado (não duplica); uma meta cadastrada manualmente antes é preservada.</p>
                        <?= kpi_grades_html($kpisSetor, 'setores', 'Nenhuma métrica registrada ainda pra esse setor -- registre pra ajudar a IA a avaliar o processo.') ?>
                    </div>

                    <!-- IA -->
                    <div class="tab-pane" id="setorIa<?= $idSetorAtual ?>">
                        <p class="text-muted small">
                            <i class="bi bi-info-circle"></i> A IA analisa só os <strong>últimos 25 chamados</strong>
                            (título, categoria, status, prioridade) e os <strong>últimos 15 KPIs</strong> registrados
                            desse setor, e devolve um diagnóstico rápido do processo + sugestões de melhoria.
                            Não lê documentos, propostas nem conversas.
                        </p>
                        <form method="post" class="mb-3 form-gerar-sugestao-ia" data-setor-id="<?= $idSetorAtual ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="gerar_sugestao_ia">
                            <input type="hidden" name="aba_retorno" value="setores">
                            <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                            <button type="submit" class="btn btn-sm btn-dark" <?= AiSugestoes::configurada() ? '' : 'disabled' ?>><i class="bi bi-stars"></i> Gerar sugestões com IA</button>
                            <?php if (!AiSugestoes::configurada()): ?>
                                <span class="text-muted small ms-2">IA ainda não configurada (ver Configurações &gt; IA Gemini).</span>
                            <?php endif; ?>
                        </form>
                        <div class="resultado-sugestao-ia" id="resultadoSugestaoIa<?= $idSetorAtual ?>">
                        <?php if ($sugestaoIaSetor): ?>
                            <div class="card card-stat">
                                <div class="card-body">
                                    <p class="text-muted small mb-2">Gerado em <?= formatar_data($sugestaoIaSetor['criado_em']) ?></p>
                                    <div style="white-space: pre-wrap;"><?= e($sugestaoIaSetor['sugestao']) ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">Nenhuma sugestão gerada ainda pra esse setor.</p>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- Vídeo aulas -->
                    <div class="tab-pane" id="setorVideoAulas<?= $idSetorAtual ?>">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <p class="text-muted small mb-0"><i class="bi bi-info-circle"></i> Grave e suba aqui as vídeo aulas desse setor -- o cliente assiste e baixa direto no portal.</p>
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="collapse" data-bs-target="#formNovaVideoAula<?= $idSetorAtual ?>"><i class="bi bi-plus-lg"></i> Cadastrar nova</button>
                        </div>

                        <div class="collapse mb-3" id="formNovaVideoAula<?= $idSetorAtual ?>">
                            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end card card-stat p-3 form-video-aula-chunk">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="upload_video_aula">
                                <input type="hidden" name="aba_retorno" value="setores">
                                <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                                <div class="col-md-5"><label class="form-label small mb-1">Nome da rotina</label><input type="text" name="nome_rotina" class="form-control form-control-sm" placeholder="Ex.: Como lançar uma Ordem de Produção" required></div>
                                <div class="col-md-5"><label class="form-label small mb-1">Arquivo MP4</label><input type="file" name="arquivo" class="form-control form-control-sm" accept="<?= e(VIDEO_AULA_ACCEPT_HTML) ?>" required>
                                    <span class="text-muted small">Sem limite de tamanho -- vídeo grande só demora mais pra subir.</span>
                                </div>
                                <div class="col-md-2"><button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-upload"></i> Enviar</button></div>
                            </form>
                        </div>

                        <?php if ($videoAulasSetor): ?>
                        <div class="mb-3">
                            <input type="text" class="form-control form-control-sm filtro-video-aula" data-alvo="videoAulaGrid<?= $idSetorAtual ?>" placeholder="🔎 Filtrar por nome da rotina...">
                        </div>
                        <div class="row g-3" id="videoAulaGrid<?= $idSetorAtual ?>">
                            <?php foreach ($videoAulasSetor as $v): ?>
                            <div class="col-md-4" data-nome-filtro="<?= e(mb_strtolower($v['titulo'], 'UTF-8')) ?>">
                                <div class="card card-stat video-aula-card h-100">
                                    <div class="card-body pb-1">
                                        <div class="fw-semibold"><?= e($v['titulo']) ?></div>
                                    </div>
                                    <div class="video-aula-thumb" role="button" data-bs-toggle="modal" data-bs-target="#modalAssistirVideoAula" data-video-id="<?= $v['id'] ?>" data-video-nome="<?= e($v['titulo']) ?>">
                                        <video preload="metadata" muted playsinline src="<?= url('video_aula.php?id=' . $v['id']) ?>"></video>
                                        <div class="video-aula-play-overlay"><i class="bi bi-play-circle-fill"></i></div>
                                    </div>
                                    <div class="card-body pt-2">
                                        <p class="text-muted small mb-2"><?= round($v['arquivo_tamanho'] / 1024 / 1024, 1) ?> MB &middot; <?= formatar_data($v['criado_em']) ?><br><?= e($v['criado_por_nome'] ?: '-') ?></p>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modalAssistirVideoAula" data-video-id="<?= $v['id'] ?>" data-video-nome="<?= e($v['titulo']) ?>"><i class="bi bi-play-fill"></i> Assistir</button>
                                            <a href="<?= url('video_aula.php?id=' . $v['id'] . '&baixar=1') ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i> Baixar</a>
                                            <form method="post" class="ms-auto" onsubmit="return confirm('Excluir a vídeo aula &quot;<?= e($v['titulo']) ?>&quot;?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="acao" value="excluir_video_aula">
                                                <input type="hidden" name="aba_retorno" value="setores">
                                                <input type="hidden" name="documento_id" value="<?= $v['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">Nenhuma vídeo aula cadastrada ainda pra esse setor.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($podeAcessarDadosSensiveis): ?>
                    <!-- Fontes ADVPL -->
                    <div class="tab-pane" id="setorFontesAdvpl<?= $idSetorAtual ?>">
                        <div class="alert alert-warning small py-2 mb-3"><i class="bi bi-exclamation-triangle-fill"></i> Código-fonte proprietário do cliente -- área restrita, todo download fica registrado.</div>

                        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end card card-stat p-3 mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="upload_fonte_advpl">
                            <input type="hidden" name="aba_retorno" value="setores">
                            <input type="hidden" name="setor_id" value="<?= $idSetorAtual ?>">
                            <div class="col-md-4"><label class="form-label small mb-1">Nome da rotina</label><input type="text" name="titulo" class="form-control form-control-sm" placeholder="Ex.: Bloqueio de faturamento" required></div>
                            <div class="col-md-4"><label class="form-label small mb-1">Ponto de entrada</label><input type="text" name="ponto_entrada" class="form-control form-control-sm" placeholder="Ex.: U_MEUFONTE01"></div>
                            <div class="col-md-4"><label class="form-label small mb-1">Arquivo fonte</label><input type="file" name="arquivo" class="form-control form-control-sm" accept=".prw,.ptm,.prx,.tlpp,.ch,.zip" required></div>
                            <div class="col-12"><label class="form-label small mb-1">Descrição</label><textarea name="descricao" class="form-control form-control-sm" rows="1" placeholder="O que esse fonte faz, opcional"></textarea></div>
                            <div class="col-12"><button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-upload"></i> Enviar</button></div>
                        </form>

                        <?php if ($fontesAdvplSetor): ?>
                        <div class="mb-3 d-flex gap-2 align-items-center">
                            <input type="text" class="form-control form-control-sm filtro-fonte-advpl" data-alvo="fontesAdvplTbody<?= $idSetorAtual ?>" placeholder="🔎 Filtrar por rotina, ponto de entrada ou arquivo...">
                            <a href="<?= url('download_fontes_advpl_zip.php?setor_id=' . $idSetorAtual) ?>" class="btn btn-sm btn-outline-dark text-nowrap"><i class="bi bi-file-earmark-zip"></i> Baixar tudo (ZIP)</a>
                        </div>
                        <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Rotina</th><th>Ponto de entrada</th><th>Arquivo</th><th>Enviado por</th><th>Data</th><th></th></tr></thead>
                            <tbody id="fontesAdvplTbody<?= $idSetorAtual ?>">
                            <?php foreach ($fontesAdvplSetor as $f): ?>
                                <tr data-nome-filtro="<?= e(mb_strtolower($f['titulo'] . ' ' . $f['ponto_entrada'] . ' ' . $f['arquivo_nome_original'], 'UTF-8')) ?>">
                                    <td class="fw-semibold"><?= e($f['titulo']) ?><?= $f['descricao'] ? '<div class="text-muted small">' . e($f['descricao']) . '</div>' : '' ?></td>
                                    <td><?= $f['ponto_entrada'] ? '<code>' . e($f['ponto_entrada']) . '</code>' : '-' ?></td>
                                    <td><?= e($f['arquivo_nome_original']) ?> <small class="text-muted">(<?= round($f['arquivo_tamanho'] / 1024) ?> KB)</small></td>
                                    <td><?= e($f['criado_por_nome'] ?: '-') ?></td>
                                    <td><?= formatar_data($f['criado_em']) ?></td>
                                    <td class="text-end text-nowrap">
                                        <a href="<?= url('download_fonte_advpl.php?id=' . $f['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-dark botao-editar-fonte-advpl"
                                            data-id="<?= $f['id'] ?>" data-titulo="<?= e($f['titulo']) ?>"
                                            data-ponto-entrada="<?= e($f['ponto_entrada']) ?>" data-descricao="<?= e($f['descricao']) ?>"
                                            data-arquivo="<?= e($f['arquivo_nome_original']) ?>"
                                        ><i class="bi bi-pencil"></i></button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir a fonte &quot;<?= e($f['titulo']) ?>&quot;?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="acao" value="excluir_fonte_advpl">
                                            <input type="hidden" name="aba_retorno" value="setores">
                                            <input type="hidden" name="fonte_id" value="<?= $f['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">Nenhuma fonte ADVPL cadastrada ainda pra esse setor.</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </details>
        <?php endforeach; ?>
    </div>

    <!-- USUÁRIOS -->
    <div class="tab-pane" id="usuarios">
        <p class="text-muted small mb-2"><i class="bi bi-people"></i> Todo mundo que representa <?= e($cliente['nome']) ?> no sistema -- o master vê tudo, cada usuário relacionado a um setor só vê/abre chamado do próprio setor.</p>
        <div class="toolbar">
            <div class="toolbar-acoes">
                <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoUsuarioCliente"><i class="bi bi-plus-lg"></i> Novo usuário</button>
            </div>
            <a class="btn btn-sm btn-exportar" href="<?= url('exportar_usuarios_cliente.php?id=' . $clienteId) ?>" target="_blank"><i class="bi bi-file-earmark-excel"></i> Exportar</a>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th></th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Setor</th><th>Portal</th><th>WhatsApp</th><th>Horário WhatsApp</th><th>Horário Portal</th><th></th></tr></thead>
                    <tbody>
                        <?php
                        // Monta o texto do confirm() inteiro aqui em PHP -- antes esses
                        // dois botões concatenavam as constantes de horário fora de
                        // qualquer tag de PHP, então isso saía como texto puro na tela
                        // (o nome da constante e os pontos de concatenação, ao invés do
                        // valor de verdade). Bug real, não só estético.
                        $textoConfirmHorarioWhatsapp = ($cliente['whatsapp_liberacao_horario'] ? 'Remover' : 'Dar') . ' liberação especial pro WHATSAPP de ' . $cliente['nome']
                            . ' funcionar fora do horário comercial (seg-sex, ' . WHATSAPP_HORARIO_COMERCIAL_INICIO . 'h-' . WHATSAPP_HORARIO_COMERCIAL_FIM . 'h)? Vale pro bot do WhatsApp, não afeta o portal.';
                        $textoConfirmHorarioPortal = ($cliente['acesso_fora_horario'] ? 'Remover' : 'Dar') . ' liberação especial pro PORTAL de ' . $cliente['nome']
                            . ' funcionar fora do horário comercial (' . sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_INICIO) . '-' . sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_FIM) . ')? Vale pro portal (site), não afeta o WhatsApp.';
                        ?>
                        <tr>
                            <td><?= avatar_html($cliente['contato_nome'] ?: $cliente['nome'], resolver_avatar_por_email($pdo, $cliente['contato_email'], $cliente['foto_perfil']), 'cliente') ?></td>
                            <td class="fw-semibold"><?= e($cliente['contato_nome'] ?: $cliente['nome']) ?> <span class="badge bg-dark">Master</span></td>
                            <td><?= $cliente['contato_email'] ? e($cliente['contato_email']) : '<span class="text-muted">— sem e-mail —</span>' ?></td>
                            <td class="telefone-linha"><?= e(formatar_telefone($cliente['contato_telefone']) ?: '-') ?></td>
                            <td onclick="event.stopPropagation()">
                                <button type="button" class="btn btn-sm btn-outline-dark botao-editar-setores-usuario w-100 text-start" data-responsavel-id="" data-nome="<?= e($cliente['contato_nome'] ?: $cliente['nome']) ?>" data-setores-atuais='<?= e(json_encode($idsSetoresMaster)) ?>' <?= $setores ? '' : 'disabled title="Cadastre um setor primeiro"' ?>>
                                    <?= $idsSetoresMaster ? e(implode(', ', array_map(fn ($sid) => $nomesSetoresPorId[$sid] ?? '?', $idsSetoresMaster))) : 'Nenhum setor' ?>
                                </button>
                            </td>
                            <td><span class="badge bg-<?= $cliente['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $cliente['portal_ativo'] ? 'Ativo' : 'Sem acesso' ?></span></td>
                            <td>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= $cliente['bloqueado_whatsapp'] ? 'Liberar' : 'Bloquear' ?> o acesso do master a abrir chamado pelo WhatsApp?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="alternar_bloqueio_whatsapp">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <input type="hidden" name="responsavel_id" value="">
                                    <button type="submit" class="btn btn-sm <?= $cliente['bloqueado_whatsapp'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" title="<?= $cliente['bloqueado_whatsapp'] ? 'Bloqueado -- clique pra liberar' : 'Liberado -- clique pra bloquear' ?>">
                                        <i class="bi <?= $cliente['bloqueado_whatsapp'] ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i> <?= $cliente['bloqueado_whatsapp'] ? 'Bloqueado' : 'Liberado' ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= e($textoConfirmHorarioWhatsapp) ?>');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="alternar_liberacao_horario">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <button type="submit" class="btn btn-sm <?= $cliente['whatsapp_liberacao_horario'] ? 'btn-outline-warning' : 'btn-outline-secondary' ?>" title="WhatsApp -- vale pra qualquer pessoa desse cliente, no master ou em qualquer setor. Não afeta o portal.">
                                        <i class="bi <?= $cliente['whatsapp_liberacao_horario'] ? 'bi-clock-fill' : 'bi-clock' ?>"></i> <?= $cliente['whatsapp_liberacao_horario'] ? '24h liberado' : 'Só horário comercial' ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= e($textoConfirmHorarioPortal) ?>');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="alternar_acesso_portal_fora_horario">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <button type="submit" class="btn btn-sm <?= $cliente['acesso_fora_horario'] ? 'btn-outline-warning' : 'btn-outline-secondary' ?>" title="Portal -- vale pra qualquer pessoa desse cliente, no master ou em qualquer setor. Não afeta o WhatsApp.">
                                        <i class="bi <?= $cliente['acesso_fora_horario'] ? 'bi-clock-fill' : 'bi-clock' ?>"></i> <?= $cliente['acesso_fora_horario'] ? '24h liberado' : 'Só horário comercial' ?>
                                    </button>
                                </form>
                            </td>
                            <td></td>
                        </tr>
                        <?php foreach ($usuariosCliente as $uc): ?>
                        <tr class="linha-usuario-cliente" role="button" tabindex="0" data-usuario='<?= e(json_encode($uc, JSON_UNESCAPED_UNICODE)) ?>'>
                            <td><?= avatar_html($uc['nome'], resolver_avatar_por_email($pdo, $uc['email'], $uc['foto_perfil']), 'cliente') ?></td>
                            <td class="fw-semibold"><?= e($uc['nome']) ?> <?= $uc['eh_master'] ? '<span class="badge bg-primary">Master</span>' : '' ?></td>
                            <td><?= e($uc['email']) ?></td>
                            <td class="telefone-linha"><?= e(formatar_telefone($uc['telefone']) ?: '-') ?></td>
                            <td onclick="event.stopPropagation()">
                                <button type="button" class="btn btn-sm btn-outline-dark botao-editar-setores-usuario w-100 text-start" data-responsavel-id="<?= $uc['id'] ?>" data-nome="<?= e($uc['nome']) ?>" data-setores-atuais='<?= e(json_encode($uc['setor_ids'])) ?>' <?= $setores ? '' : 'disabled title="Cadastre um setor primeiro"' ?>>
                                    <?= $uc['setor_ids'] ? e(implode(', ', array_map(fn ($sid) => $nomesSetoresPorId[$sid] ?? '?', $uc['setor_ids']))) : 'Sem setor' ?>
                                </button>
                            </td>
                            <td><span class="badge bg-<?= $uc['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $uc['portal_ativo'] ? 'Ativo' : 'Sem acesso' ?></span></td>
                            <td onclick="event.stopPropagation()">
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= $uc['bloqueado_whatsapp'] ? 'Liberar' : 'Bloquear' ?> o acesso de <?= e($uc['nome']) ?> a abrir chamado pelo WhatsApp?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="alternar_bloqueio_whatsapp">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $uc['bloqueado_whatsapp'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" title="<?= $uc['bloqueado_whatsapp'] ? 'Bloqueado -- clique pra liberar' : 'Liberado -- clique pra bloquear' ?>">
                                        <i class="bi <?= $uc['bloqueado_whatsapp'] ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i> <?= $uc['bloqueado_whatsapp'] ? 'Bloqueado' : 'Liberado' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-muted small" title="Liberação de horário do WhatsApp vale pro cliente inteiro, é configurada só na linha do master">—</td>
                            <td class="text-muted small" title="Liberação de horário do Portal vale pro cliente inteiro, é configurada só na linha do master">—</td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="enviar_convite_setor">
                                        <input type="hidden" name="aba_retorno" value="usuarios">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-dark" title="<?= $uc['setor_id'] ? '' : 'Relacione a um setor primeiro' ?>" <?= $uc['setor_id'] ? '' : 'disabled' ?>><i class="bi bi-send"></i> <?= $uc['portal_ativo'] ? 'Reenviar convite' : 'Convite' ?></button>
                                    </form>
                                    <?php if ($uc['portal_ativo']): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Isso invalida a senha atual de <?= e($uc['nome']) ?> na hora. Confirma o reset?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="resetar_senha_setor">
                                        <input type="hidden" name="aba_retorno" value="usuarios">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-key"></i> Resetar senha</button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="desativar_portal_setor">
                                        <input type="hidden" name="aba_retorno" value="usuarios">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Desativar acesso</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remover <?= e($uc['nome']) ?>? Isso também apaga o acesso dele ao portal, se tiver.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="excluir_usuario_cliente">
                                        <input type="hidden" name="aba_retorno" value="usuarios">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($usuariosCliente)): ?>
                            <tr><td colspan="9" class="text-muted text-center py-3">Nenhum outro usuário cadastrado ainda -- só o master.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p class="text-muted small px-3 py-2 mb-0">Clique numa linha pra editar dados, avatar, convite ou senha.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($podeAcessarDadosSensiveis): ?>
    <!-- ACESSOS (cofre de credenciais + VPN) -->
    <div class="tab-pane" id="acessos">
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>Área restrita.</strong> Credenciais e arquivos aqui são extremamente sensíveis (senha de servidor, acesso remoto, VPN) -- só admin/diretor e usuários com liberação especial enxergam essa aba. Todo acesso (ver senha, baixar arquivo) fica registrado.
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0"><i class="bi bi-key"></i> Credenciais</h6>
            <button type="button" class="btn btn-sm btn-dark" id="botaoNovaCredencial"><i class="bi bi-plus-lg"></i> Nova credencial</button>
        </div>

        <?php if ($credenciaisCliente): ?>
        <div class="table-responsive mb-4">
        <table class="table table-hover align-middle mb-0" id="tabelaCredenciais" data-csrf-ver-senha="<?= e(csrf_token()) ?>">
            <thead><tr><th>Tipo</th><th>Título</th><th>Host</th><th>Porta</th><th>Usuário</th><th>Senha</th><th>Observações</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($credenciaisCliente as $cr): ?>
                <tr>
                    <td><span class="badge bg-secondary"><?= e(CREDENCIAL_TIPOS[$cr['tipo']] ?? ucfirst($cr['tipo'])) ?></span></td>
                    <td class="fw-semibold"><?= e($cr['titulo']) ?></td>
                    <td><?= e($cr['host'] ?: '-') ?></td>
                    <td><?= e($cr['porta'] ?: '-') ?></td>
                    <td><?= e($cr['usuario'] ?: '-') ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2 credencial-senha-linha" data-credencial-id="<?= $cr['id'] ?>">
                            <span class="credencial-senha-valor font-monospace">••••••••</span>
                            <button type="button" class="btn btn-sm btn-outline-dark botao-mostrar-senha" title="Mostrar senha"><i class="bi bi-eye"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-dark botao-copiar-senha" title="Copiar senha"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </td>
                    <td class="text-muted small"><?= e($cr['observacoes'] ?: '-') ?></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-dark botao-editar-credencial"
                            data-id="<?= $cr['id'] ?>" data-tipo="<?= e($cr['tipo']) ?>" data-titulo="<?= e($cr['titulo']) ?>"
                            data-host="<?= e($cr['host']) ?>" data-porta="<?= e($cr['porta']) ?>" data-usuario="<?= e($cr['usuario']) ?>"
                            data-observacoes="<?= e($cr['observacoes']) ?>"
                        ><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir a credencial &quot;<?= e($cr['titulo']) ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir_credencial">
                            <input type="hidden" name="aba_retorno" value="acessos">
                            <input type="hidden" name="credencial_id" value="<?= $cr['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="text-muted text-center py-3">Nenhuma credencial cadastrada ainda.</p>
        <?php endif; ?>

        <hr class="my-4">

        <div class="mb-3">
            <h6 class="mb-0"><i class="bi bi-hdd-network"></i> Arquivos de VPN</h6>
            <p class="text-muted small mb-0">Repositório pra guardar e baixar arquivo de configuração de VPN (.ovpn etc.) desse cliente.</p>
        </div>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="upload_vpn_arquivo">
            <input type="hidden" name="aba_retorno" value="acessos">
            <div class="col-md-4"><input type="text" name="titulo" class="form-control form-control-sm" placeholder="Título (ex.: VPN Matriz)" required></div>
            <div class="col-md-6"><input type="file" name="arquivo" class="form-control form-control-sm" required></div>
            <div class="col-md-2"><button type="submit" class="btn btn-sm btn-dark w-100"><i class="bi bi-upload"></i> Enviar</button></div>
        </form>
        <?php if ($vpnArquivosCliente): ?>
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Título</th><th>Arquivo</th><th>Enviado por</th><th>Data</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($vpnArquivosCliente as $v): ?>
                <tr>
                    <td class="fw-semibold"><?= e($v['titulo']) ?></td>
                    <td><?= e($v['arquivo_nome_original']) ?> <small class="text-muted">(<?= round($v['arquivo_tamanho'] / 1024) ?> KB)</small></td>
                    <td><?= e($v['criado_por_nome'] ?: '-') ?></td>
                    <td><?= formatar_data($v['criado_em']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= url('download_vpn.php?id=' . $v['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir o arquivo &quot;<?= e($v['titulo']) ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir_vpn_arquivo">
                            <input type="hidden" name="aba_retorno" value="acessos">
                            <input type="hidden" name="arquivo_id" value="<?= $v['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="text-muted text-center py-3">Nenhum arquivo de VPN cadastrado ainda.</p>
        <?php endif; ?>
    </div>

    <!-- T-CLOUD (ambientes + configs VSCode) -->
    <div class="tab-pane" id="tcloud">
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>Área restrita.</strong> Links de ambiente e de acesso remoto do T-Cloud -- mesma trava da aba Acessos.
            </div>
        </div>

        <div class="mb-3">
            <h6 class="mb-0"><i class="bi bi-cloud"></i> Ambientes</h6>
            <p class="text-muted small mb-0">Clique num ambiente pra ver/editar os links (Protheus, REST, Schedule, modo exclusivo).</p>
        </div>
        <div class="grid-setores mb-4">
            <?php foreach (TCLOUD_AMBIENTES as $tipoAmbienteTcloud => $labelAmbienteTcloud):
                $dadosAmbienteTcloud = $tcloudAmbientesCliente[$tipoAmbienteTcloud];
                $qtdLinksAmbienteTcloud = count(array_filter(array_intersect_key($dadosAmbienteTcloud, TCLOUD_LINKS)));
            ?>
            <div class="card-setor" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#modalTcloudAmbiente"
                data-tipo="<?= e($tipoAmbienteTcloud) ?>" data-titulo="<?= e($labelAmbienteTcloud) ?>"
                <?php foreach (array_keys(TCLOUD_LINKS) as $campoLinkTcloud): ?>
                data-<?= str_replace('_', '-', $campoLinkTcloud) ?>="<?= e($dadosAmbienteTcloud[$campoLinkTcloud] ?? '') ?>"
                <?php endforeach; ?>
            >
                <div class="card-setor-topo">
                    <span class="fw-semibold"><i class="bi <?= e(TCLOUD_AMBIENTE_ICONES[$tipoAmbienteTcloud] ?? 'bi-cloud') ?>"></i> <?= e($labelAmbienteTcloud) ?></span>
                </div>
                <div class="card-setor-insights">
                    <span class="badge modulo-pill <?= $qtdLinksAmbienteTcloud > 0 ? 'modulo-ok' : 'modulo-neutro' ?>"><?= $qtdLinksAmbienteTcloud ?>/<?= count(TCLOUD_LINKS) ?> links configurados</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <hr class="my-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h6 class="mb-0"><i class="bi bi-code-slash"></i> Configurações do VSCode</h6>
                <p class="text-muted small mb-0">Link, porta e ambiente pra conectar direto pelo VSCode.</p>
            </div>
            <button type="button" class="btn btn-sm btn-dark" id="botaoNovoTcloudVscode"><i class="bi bi-plus-lg"></i> Nova configuração</button>
        </div>

        <?php if ($tcloudVscodeCliente): ?>
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Título</th><th>Link</th><th>Porta</th><th>Ambiente</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tcloudVscodeCliente as $vs): ?>
                <tr>
                    <td class="fw-semibold"><?= e($vs['titulo']) ?></td>
                    <td>
                        <?php if ($vs['link']): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-truncate d-inline-block" style="max-width:220px;" title="<?= e($vs['link']) ?>"><?= e($vs['link']) ?></span>
                            <button type="button" class="btn btn-sm btn-outline-dark botao-copiar-link" data-valor="<?= e($vs['link']) ?>" title="Copiar link"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= e($vs['porta'] ?: '-') ?></td>
                    <td><?= e($vs['ambiente'] ?: '-') ?></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-dark botao-editar-tcloud-vscode"
                            data-id="<?= $vs['id'] ?>" data-titulo="<?= e($vs['titulo']) ?>" data-link="<?= e($vs['link']) ?>"
                            data-porta="<?= e($vs['porta']) ?>" data-ambiente="<?= e($vs['ambiente']) ?>"
                        ><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Excluir a configuração &quot;<?= e($vs['titulo']) ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir_tcloud_vscode">
                            <input type="hidden" name="aba_retorno" value="tcloud">
                            <input type="hidden" name="vscode_id" value="<?= $vs['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="text-muted text-center py-3">Nenhuma configuração de VSCode cadastrada ainda.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Modal Novo Chamado (registrado pela equipe) -->
<div class="modal fade" id="modalNovoChamado" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_chamado_equipe">
        <input type="hidden" name="aba_retorno" value="chamados">
        <div class="modal-header"><h5 class="modal-title">Registrar chamado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" style="text-transform:uppercase" required></div>
            <div class="mb-3"><label class="form-label">Descrição *</label><textarea name="descricao" class="form-control" rows="3" required></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Categoria *</label>
                    <select name="categoria" class="form-select" required>
                        <option value="" disabled selected>Selecione</option>
                        <?php foreach (CATEGORIAS_CHAMADO as $cat): ?>
                            <option><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Prioridade *</label>
                    <select name="prioridade" class="form-select" required>
                        <?= opcoes_prioridade_html('media') ?>
                    </select>
                </div>
                <div class="<?= $setores ? 'col-6' : 'col-12' ?>"><label class="form-label">Responsável</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($setores): ?>
                <div class="col-6"><label class="form-label">Setor</label>
                    <select name="setor_id" class="form-select">
                        <option value="">Sem setor</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="anexos-stager mt-3" data-max-arquivos="<?= ANEXO_CHAMADO_MAX_QUANTIDADE ?>" data-max-tamanho-bytes="<?= ANEXO_CHAMADO_MAX_SIZE ?>">
                <input type="file" name="anexos[]" class="anexos-stager-input" multiple accept="<?= e(ANEXO_CHAMADO_ACCEPT_HTML) ?>">
                <div class="anexos-stager-controles">
                    <button type="button" class="btn btn-outline-dark btn-sm anexos-stager-escolher"><i class="bi bi-paperclip"></i> Escolher anexos</button>
                    <button type="button" class="btn btn-dark btn-sm anexos-stager-enviar" disabled><i class="bi bi-plus-lg"></i> Enviar anexos</button>
                    <span class="anexos-stager-pendente text-muted"></span>
                </div>
                <div class="anexos-stager-erro alert alert-danger d-none"></div>
                <ul class="anexos-stager-lista list-unstyled"></ul>
            </div>
            <p class="anexos-upload-preview">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada (imagem, PDF, Word/Excel/PowerPoint, texto ou ZIP). Também pode colar (Ctrl+V) um print direto aqui.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Registrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Exportar Chamados -->
<div class="modal fade" id="modalExportarChamados" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="get" action="<?= url('exportar_chamados.php') ?>" target="_blank">
        <input type="hidden" name="id" value="<?= $clienteId ?>">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-excel"></i> Exportar chamados</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="exportarStatus" class="form-select">
                    <option value="todos">Todos</option>
                    <option value="aberto">Aberto</option>
                    <option value="aguardando_equipe">Aguardando nós</option>
                    <option value="aguardando_cliente">Aguardando cliente</option>
                    <option value="fechado">Fechado</option>
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">De *</label><input type="date" name="data_de" class="form-control" required></div>
                <div class="col-6"><label class="form-label">Até *</label><input type="date" name="data_ate" class="form-control" required></div>
            </div>
            <p class="text-muted small mt-2 mb-0">O arquivo abre direto no Excel.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-exportar"><i class="bi bi-download"></i> Exportar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Novo KPI -->
<div class="modal fade" id="modalNovoKpi" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_kpi">
        <input type="hidden" name="aba_retorno" id="novoKpiAbaRetorno" value="kpis">
        <div class="modal-header"><h5 class="modal-title">Registrar KPI</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome do KPI *</label><input type="text" name="nome_kpi" class="form-control" required placeholder="Ex: SLA de atendimento, chamados resolvidos..."></div>
            <div class="row g-2">
                <div class="col-4"><label class="form-label">Valor *</label><input type="text" name="valor" class="form-control" required placeholder="Ex: 95,5"></div>
                <div class="col-4"><label class="form-label">Meta</label><input type="text" name="meta" class="form-control" placeholder="Ex: 98"></div>
                <div class="col-4"><label class="form-label">Unidade</label><input type="text" name="unidade" class="form-control" placeholder="%, dias, un."></div>
            </div>
            <div class="mb-2 mt-2"><label class="form-label">Quando esse KPI é bom?</label>
                <select name="direcao_melhor" class="form-select">
                    <option value="maior">Quanto MAIOR o valor, melhor (ex.: chamados resolvidos)</option>
                    <option value="menor">Quanto MENOR o valor, melhor (ex.: chamados urgentes, tempo médio)</option>
                </select>
            </div>
            <div class="form-text mb-2">A meta é o número que você quer bater nesse KPI. Deixe em branco se ainda não tiver uma meta definida -- o card fica neutro (cinza) até você definir uma.</div>
            <div class="mb-3"><label class="form-label">Setor</label>
                <select name="setor_id" id="novoKpiSetor" class="form-select">
                    <option value="">Geral (todo o cliente)</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Observações</label><input type="text" name="observacoes" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Registrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Editar KPI -->
<div class="modal fade" id="modalEditarKpi" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="formEditarKpi">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="editar_kpi">
        <input type="hidden" name="aba_retorno" id="editarKpiAbaRetorno" value="kpis">
        <input type="hidden" name="kpi_id" id="editarKpiId">
        <div class="modal-header"><h5 class="modal-title">Editar KPI</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome do KPI *</label><input type="text" name="nome_kpi" id="editarKpiNome" class="form-control" required></div>
            <div class="row g-2">
                <div class="col-4"><label class="form-label">Valor *</label><input type="text" name="valor" id="editarKpiValor" class="form-control" required></div>
                <div class="col-4"><label class="form-label">Meta</label><input type="text" name="meta" id="editarKpiMeta" class="form-control"></div>
                <div class="col-4"><label class="form-label">Unidade</label><input type="text" name="unidade" id="editarKpiUnidade" class="form-control"></div>
            </div>
            <div class="mb-2 mt-2"><label class="form-label">Quando esse KPI é bom?</label>
                <select name="direcao_melhor" id="editarKpiDirecao" class="form-select">
                    <option value="maior">Quanto MAIOR o valor, melhor (ex.: chamados resolvidos)</option>
                    <option value="menor">Quanto MENOR o valor, melhor (ex.: chamados urgentes, tempo médio)</option>
                </select>
            </div>
            <div class="form-text mb-2">A meta é o número que você quer bater nesse KPI. Deixe em branco se ainda não tiver uma meta definida -- o card fica neutro (cinza) até você definir uma.</div>
            <div class="mb-3 mt-2"><label class="form-label">Período (mês/ano) *</label><input type="month" name="periodo" id="editarKpiPeriodo" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Setor</label>
                <select name="setor_id" id="editarKpiSetor" class="form-select">
                    <option value="">Geral (todo o cliente)</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Observações</label><input type="text" name="observacoes" id="editarKpiObservacoes" class="form-control"></div>
        </div>
        <div class="modal-footer justify-content-between">
            <button type="button" class="btn btn-outline-danger botao-excluir-kpi"><i class="bi bi-trash"></i> Excluir</button>
            <div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark">Salvar</button>
            </div>
        </div>
      </form>
      <form method="post" id="formExcluirKpi" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="excluir_kpi">
        <input type="hidden" name="aba_retorno" id="excluirKpiAbaRetorno" value="kpis">
        <input type="hidden" name="kpi_id" id="excluirKpiId">
      </form>
    </div>
  </div>
</div>

<!-- Modal Novo Treinamento -->
<div class="modal fade" id="modalNovoTreinamento" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_treinamento">
        <input type="hidden" name="aba_retorno" id="novoTreinamentoAbaRetorno" value="setores">
        <div class="modal-header"><h5 class="modal-title">Novo treinamento/reunião</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
            <div class="row g-2 mb-1">
                <div class="col-6"><label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value="treinamento">Treinamento</option>
                        <option value="reuniao">Reunião</option>
                        <option value="conversa">Conversa</option>
                        <option value="auxilio">Auxílio</option>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Setor</label>
                    <select name="setor_id" id="novoTreinamentoSetor" class="form-select">
                        <option value="">Geral (todo o cliente)</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Data início</label><input type="date" name="data_inicio" id="novoTreinamentoDataInicio" class="form-control"></div>
                <div class="col-6"><label class="form-label">Hora início</label><input type="time" name="hora_inicio" class="form-control"></div>
                <div class="col-6"><label class="form-label">Data fim</label><input type="date" name="data_fim" class="form-control"></div>
                <div class="col-6"><label class="form-label">Hora fim</label><input type="time" name="hora_fim" class="form-control"></div>
                <div class="col-12"><label class="form-label">Link da videochamada</label><input type="url" name="link_reuniao" class="form-control" placeholder="Meet, Teams, Zoom..."></div>
                <div class="col-12"><label class="form-label">Chamado vinculado (opcional)</label>
                    <select name="chamado_id" id="novoTreinamentoChamado" class="form-select">
                        <option value="">Nenhum</option>
                        <?php foreach ($chamados as $c): ?>
                            <option value="<?= $c['id'] ?>">#<?= (int) $c['id'] ?> -- <?= e($c['titulo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Pra registrar que essa conversa/reunião aconteceu por causa de um chamado -- ela aparece na tela dele depois.</div>
                </div>
                <div class="col-12"><label class="form-label">Responsável (equipe)</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Avisar por e-mail e WhatsApp (5min antes)</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="destinatarios[]" value="master" id="novoTreinamentoDestMaster">
                        <label class="form-check-label" for="novoTreinamentoDestMaster">📌 <?= e($cliente['contato_nome'] ?: $cliente['nome']) ?> <span class="text-muted small">(contato master)</span></label>
                    </div>
                    <?php foreach ($usuariosCliente as $uc): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="destinatarios[]" value="responsavel_<?= $uc['id'] ?>" id="novoTreinamentoDest<?= $uc['id'] ?>">
                        <label class="form-check-label" for="novoTreinamentoDest<?= $uc['id'] ?>"><?= e($uc['nome']) ?><?= $uc['telefone'] ? '' : ' <span class="text-muted small">(sem telefone -- só e-mail)</span>' ?></label>
                    </div>
                    <?php endforeach; ?>
                    <div class="form-text">Cada pessoa marcada recebe e-mail e, se tiver telefone cadastrado, também WhatsApp.</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Criar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Editar Treinamento/Reunião -- aberto clicando num evento existente
     do calendário (dados já vêm do JS, sem precisar buscar no servidor) -->
<div class="modal fade" id="modalEditarTreinamento" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="formEditarTreinamento">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="editar_treinamento">
        <input type="hidden" name="aba_retorno" id="editarTreinamentoAbaRetorno" value="setores">
        <input type="hidden" name="treinamento_id" id="editarTreinamentoId">
        <div class="modal-header"><h5 class="modal-title">Editar treinamento/reunião</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" id="editarTreinamentoTitulo" class="form-control" required></div>
            <div class="row g-2 mb-1">
                <div class="col-6"><label class="form-label">Tipo</label>
                    <select name="tipo" id="editarTreinamentoTipo" class="form-select">
                        <option value="treinamento">Treinamento</option>
                        <option value="reuniao">Reunião</option>
                        <option value="conversa">Conversa</option>
                        <option value="auxilio">Auxílio</option>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Setor</label>
                    <select name="setor_id" id="editarTreinamentoSetor" class="form-select">
                        <option value="">Geral (todo o cliente)</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" id="editarTreinamentoDescricao" class="form-control" rows="2"></textarea></div>
            <div class="row g-2 mb-1">
                <div class="col-6"><label class="form-label">Data início</label><input type="date" name="data_inicio" id="editarTreinamentoDataInicio" class="form-control"></div>
                <div class="col-6"><label class="form-label">Hora início</label><input type="time" name="hora_inicio" id="editarTreinamentoHoraInicio" class="form-control"></div>
                <div class="col-6"><label class="form-label">Data fim</label><input type="date" name="data_fim" id="editarTreinamentoDataFim" class="form-control"></div>
                <div class="col-6"><label class="form-label">Hora fim</label><input type="time" name="hora_fim" id="editarTreinamentoHoraFim" class="form-control"></div>
            </div>
            <div class="mb-3"><label class="form-label">Link da videochamada</label><input type="url" name="link_reuniao" id="editarTreinamentoLink" class="form-control" placeholder="Meet, Teams, Zoom..."></div>
            <div class="mb-3"><label class="form-label">Chamado vinculado (opcional)</label>
                <select name="chamado_id" id="editarTreinamentoChamado" class="form-select">
                    <option value="">Nenhum</option>
                    <?php foreach ($chamados as $c): ?>
                        <option value="<?= $c['id'] ?>">#<?= (int) $c['id'] ?> -- <?= e($c['titulo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Pra registrar que essa conversa/reunião aconteceu por causa de um chamado -- ela aparece na tela dele depois.</div>
            </div>
            <div class="mb-3"><label class="form-label">Status</label>
                <select name="status" id="editarTreinamentoStatus" class="form-select">
                    <?php foreach (['planejado' => 'Planejado', 'em_andamento' => 'Em andamento', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'] as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Responsável (equipe)</label>
                <select name="responsavel_id" id="editarTreinamentoResponsavel" class="form-select">
                    <option value="">-</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Avisar por e-mail e WhatsApp (5min antes)</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="destinatarios[]" value="master" id="editarTreinamentoDestMaster">
                    <label class="form-check-label" for="editarTreinamentoDestMaster">📌 <?= e($cliente['contato_nome'] ?: $cliente['nome']) ?> <span class="text-muted small">(contato master)</span></label>
                </div>
                <?php foreach ($usuariosCliente as $uc): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="destinatarios[]" value="responsavel_<?= $uc['id'] ?>" id="editarTreinamentoDest<?= $uc['id'] ?>">
                    <label class="form-check-label" for="editarTreinamentoDest<?= $uc['id'] ?>"><?= e($uc['nome']) ?><?= $uc['telefone'] ? '' : ' <span class="text-muted small">(sem telefone -- só e-mail)</span>' ?></label>
                </div>
                <?php endforeach; ?>
                <div class="form-text">Cada pessoa marcada recebe e-mail e, se tiver telefone cadastrado, também WhatsApp.</div>
            </div>
        </div>
        <div class="modal-footer justify-content-between">
            <button type="button" class="btn btn-outline-danger botao-excluir-treinamento"><i class="bi bi-trash"></i> Excluir</button>
            <div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark">Salvar</button>
            </div>
        </div>
      </form>
      <form method="post" id="formExcluirTreinamento" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="excluir_treinamento">
        <input type="hidden" name="aba_retorno" value="setores">
        <input type="hidden" name="treinamento_id" id="excluirTreinamentoId">
      </form>
    </div>
  </div>
</div>

<!-- Modal Novo usuário do cliente -->
<div class="modal fade" id="modalNovoUsuarioCliente" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_usuario_cliente">
        <input type="hidden" name="aba_retorno" value="usuarios">
        <div class="modal-header"><h5 class="modal-title">Novo usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="avatar" id="novoUsuarioClienteAvatar" value="1">
            <p class="text-muted small mb-1">Escolha um avatar (pode trocar depois):</p>
            <div class="galeria-avatares mb-3" id="galeriaAvatarNovoUsuarioCliente">
                <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                    <button type="button" class="avatar-opcao <?= $i === 1 ? 'active' : '' ?>" data-avatar="<?= $i ?>" title="Usar este avatar">
                        <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                    </button>
                <?php endfor; ?>
            </div>
            <div class="mb-3"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">E-mail *</label><input type="email" name="email" class="form-control" required></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control input-telefone" placeholder="(00) 00000-0000"></div>
                <div class="col-6"><label class="form-label">CPF</label><input type="text" name="documento" class="form-control" placeholder="Só números"></div>
                <div class="col-12"><label class="form-label">Setor(es)</label>
                    <?php if ($setores): ?>
                        <?php foreach ($setores as $s): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="setor_ids[]" value="<?= $s['id'] ?>" id="novoUsuarioClienteSetor<?= $s['id'] ?>">
                                <label class="form-check-label" for="novoUsuarioClienteSetor<?= $s['id'] ?>"><?= e($s['nome']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Sem setor cadastrado ainda -- pode deixar em branco por enquanto.</p>
                    <?php endif; ?>
                </div>
                <div class="col-12 form-check">
                    <input type="checkbox" name="eh_master" id="novoUsuarioClienteEhMaster" class="form-check-input" value="1">
                    <label class="form-check-label" for="novoUsuarioClienteEhMaster">Este usuário é MASTER? (vê e gerencia todos os setores, igual ao contato principal)</label>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Cadastrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Usuário do cliente: editar dados, avatar, convite/senha do portal -->
<div class="modal fade" id="modalUsuarioCliente" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalUsuarioClienteTitulo">Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-center mb-3">
            <img id="usuarioClienteAvatarPreview" class="avatar-circle avatar-foto cliente" style="width:80px;height:80px;font-size:2rem;" alt="Avatar">
        </div>

        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-2">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="atualizar_avatar_setor_responsavel">
            <input type="hidden" name="responsavel_id" id="avatarUsuarioClienteId">
            <input type="hidden" name="aba_retorno" value="usuarios">
            <div class="col"><input type="file" name="foto" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required></div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Enviar foto</button></div>
        </form>

        <p class="text-muted small mb-1">Ou escolha um avatar pronto:</p>
        <div class="galeria-avatares mb-4">
            <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="escolher_avatar_predefinido_setor_responsavel">
                    <input type="hidden" name="responsavel_id" class="avatar-usuario-cliente-id-campo">
                    <input type="hidden" name="aba_retorno" value="usuarios">
                    <input type="hidden" name="avatar" value="<?= $i ?>">
                    <button type="submit" class="avatar-opcao" title="Usar este avatar">
                        <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                    </button>
                </form>
            <?php endfor; ?>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="editar_usuario_cliente">
            <input type="hidden" name="aba_retorno" value="usuarios">
            <input type="hidden" name="responsavel_id" id="editarUsuarioClienteId">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nome *</label><input type="text" name="nome" id="editarUsuarioClienteNome" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">E-mail *</label><input type="email" name="email" id="editarUsuarioClienteEmail" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Telefone</label><input type="text" name="telefone" id="editarUsuarioClienteTelefone" class="form-control input-telefone" placeholder="(00) 00000-0000"></div>
                <div class="col-md-6"><label class="form-label">CPF</label><input type="text" name="documento" id="editarUsuarioClienteDocumento" class="form-control" placeholder="Só números"></div>
                <div class="col-md-6"><label class="form-label">Setor(es)</label>
                    <?php if ($setores): ?>
                        <?php foreach ($setores as $s): ?>
                            <div class="form-check">
                                <input class="form-check-input checkbox-editar-usuario-cliente-setor" type="checkbox" name="setor_ids[]" value="<?= $s['id'] ?>" id="editarUsuarioClienteSetor<?= $s['id'] ?>">
                                <label class="form-check-label" for="editarUsuarioClienteSetor<?= $s['id'] ?>"><?= e($s['nome']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Sem setor cadastrado ainda.</p>
                    <?php endif; ?>
                </div>
                <div class="col-12 form-check">
                    <input type="checkbox" name="eh_master" id="editarUsuarioClienteEhMaster" class="form-check-input" value="1">
                    <label class="form-check-label" for="editarUsuarioClienteEhMaster">Este usuário é MASTER? (vê e gerencia todos os setores, igual ao contato principal)</label>
                </div>
            </div>
            <button type="submit" class="btn btn-dark mt-3"><i class="bi bi-check2"></i> Salvar dados</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Setor(es) de uma pessoa (aba Usuários -- linha do master ou de um
     usuário) -- pedido do Kleber: uma pessoa pode estar em mais de um setor
     ao mesmo tempo agora (ex.: Eliete/Ananda na SGT Forjados). Modal único,
     reaproveitado tanto pra master (responsavel_id vazio, grava em
     cliente_setores) quanto pra usuário (grava em setor_responsavel_setores). -->
<div class="modal fade" id="modalSetoresUsuario" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Setores de <span id="modalSetoresUsuarioNome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($setores): ?>
            <?php foreach ($setores as $s): ?>
            <div class="form-check">
                <input class="form-check-input checkbox-setor-modal-usuario" type="checkbox" value="<?= $s['id'] ?>" id="modalSetorUsuario<?= $s['id'] ?>">
                <label class="form-check-label" for="modalSetorUsuario<?= $s['id'] ?>"><?= e($s['nome']) ?></label>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted small mb-0">Cadastre um setor primeiro (aba Setores).</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-dark" id="botaoConfirmarSetoresUsuario">Salvar</button>
      </div>
    </div>
  </div>
</div>

<?php if ($podeAcessarDadosSensiveis): ?>
<!-- Nova/editar credencial (aba Acessos) -- UM modal só, reaproveitado pros
     dois casos (o botão "Nova credencial" reseta os campos antes de abrir,
     "editar" preenche a partir dos data-* do botão da linha). -->
<div class="modal fade" id="modalCredencial" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" id="credencialAcao" value="criar_credencial">
        <input type="hidden" name="aba_retorno" value="acessos">
        <input type="hidden" name="credencial_id" id="credencialId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="credencialModalTitulo">Nova credencial</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Tipo</label>
              <select name="tipo" id="credencialTipo" class="form-select">
                <?php foreach (CREDENCIAL_TIPOS as $valorTipoCredencial => $labelTipoCredencial): ?>
                <option value="<?= e($valorTipoCredencial) ?>"><?= e($labelTipoCredencial) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Título *</label>
              <input type="text" name="titulo" id="credencialTitulo" class="form-control" placeholder="Ex.: Servidor Protheus - Produção" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Host/IP</label>
              <input type="text" name="host" id="credencialHost" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Porta</label>
              <input type="text" name="porta" id="credencialPorta" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Usuário</label>
              <input type="text" name="usuario" id="credencialUsuario" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Senha</label>
              <div class="campo-senha">
                <input type="password" name="senha" id="credencialSenha" class="form-control" autocomplete="new-password">
                <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
              </div>
              <p class="text-muted small mb-0" id="credencialSenhaDica" style="display:none;">Deixe em branco pra manter a senha atual.</p>
            </div>
            <div class="col-12">
              <label class="form-label">Observações</label>
              <textarea name="observacoes" id="credencialObservacoes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Editar Fonte ADVPL (aba Setores > Fontes ADVPL) -- arquivo é opcional
     aqui: em branco mantém o já cadastrado, preenchido substitui. -->
<div class="modal fade" id="modalEditarFonteAdvpl" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="atualizar_fonte_advpl">
        <input type="hidden" name="aba_retorno" value="setores">
        <input type="hidden" name="fonte_id" id="editarFonteAdvplId" value="">
        <div class="modal-header">
          <h5 class="modal-title">Editar fonte ADVPL</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nome da rotina *</label>
              <input type="text" name="titulo" id="editarFonteAdvplTitulo" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Ponto de entrada</label>
              <input type="text" name="ponto_entrada" id="editarFonteAdvplPontoEntrada" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Descrição</label>
              <textarea name="descricao" id="editarFonteAdvplDescricao" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Arquivo fonte</label>
              <input type="file" name="arquivo" class="form-control" accept=".prw,.ptm,.prx,.tlpp,.ch,.zip">
              <p class="text-muted small mb-0">Deixe em branco pra manter o arquivo atual (<span id="editarFonteAdvplArquivoAtual"></span>).</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Ambiente T-Cloud (Produção/Teste) -- UM modal só, aberto via
     data-bs-toggle direto no card (Bootstrap dispara show.bs.modal com
     event.relatedTarget = o card clicado, ver script mais abaixo), sem
     precisar de um botão "editar" separado -- o próprio card É o
     gatilho de visualizar/editar. -->
<div class="modal fade" id="modalTcloudAmbiente" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar_tcloud_ambiente">
        <input type="hidden" name="aba_retorno" value="tcloud">
        <input type="hidden" name="tipo" id="tcloudAmbienteTipo" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="tcloudAmbienteModalTitulo">Ambiente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Cole o link e use o botão pra copiar sem precisar selecionar o texto.</p>
          <?php foreach (TCLOUD_LINKS as $campoLinkTcloud => $labelLinkTcloud): ?>
          <div class="mb-3">
            <label class="form-label"><?= e($labelLinkTcloud) ?></label>
            <div class="input-group">
              <input type="text" name="<?= e($campoLinkTcloud) ?>" id="tcloudAmbiente_<?= e($campoLinkTcloud) ?>" class="form-control" placeholder="https://...">
              <button type="button" class="btn btn-outline-dark botao-copiar-link" data-alvo="tcloudAmbiente_<?= e($campoLinkTcloud) ?>" title="Copiar"><i class="bi bi-clipboard"></i></button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Nova/editar configuração VSCode (aba T-Cloud) -->
<div class="modal fade" id="modalTcloudVscode" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" id="tcloudVscodeAcao" value="criar_tcloud_vscode">
        <input type="hidden" name="aba_retorno" value="tcloud">
        <input type="hidden" name="vscode_id" id="tcloudVscodeId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="tcloudVscodeModalTitulo">Nova configuração do VSCode</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Título *</label>
              <input type="text" name="titulo" id="tcloudVscodeTitulo" class="form-control" placeholder="Ex.: Servidor de testes" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Link</label>
              <div class="input-group">
                <input type="text" name="link" id="tcloudVscodeLink" class="form-control" placeholder="ssh://... ou vscode-remote://...">
                <button type="button" class="btn btn-outline-dark botao-copiar-link" data-alvo="tcloudVscodeLink" title="Copiar"><i class="bi bi-clipboard"></i></button>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Porta</label>
              <input type="text" name="porta" id="tcloudVscodePorta" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Ambiente</label>
              <input type="text" name="ambiente" id="tcloudVscodeAmbiente" class="form-control" placeholder="Ex.: Produção, Teste">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Player de vídeo aula -- UM modal só, compartilhado por todas as vídeo
     aulas de todos os setores (o <video> troca de src via JS ao abrir, ver
     script mais abaixo), em vez de um modal por vídeo cadastrado. -->
<div class="modal fade" id="modalAssistirVideoAula" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content bg-dark">
      <div class="modal-header border-0">
        <h5 class="modal-title text-white" id="tituloVideoAssistir"></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-0">
        <video id="videoAssistirPlayer" controls style="width:100%; max-height:70vh; border-radius:8px;"></video>
      </div>
    </div>
  </div>
</div>

<script src="<?= asset_url('assets/js/calendario_visual.js') ?>"></script>
<script>
// Ativa a aba certa com base no hash da URL (ex: #chamados) ou abre "Visão geral" por padrão.
document.addEventListener('DOMContentLoaded', function () {
    // "Personalizar" cards por linha dos KPIs -- preferência é do
    // NAVEGADOR (localStorage), não por cliente, então escolher uma vez
    // vale pra todo mundo que essa pessoa for ver depois (aba KPIs geral E
    // qualquer sub-aba Métricas de setor, todas usam a mesma var CSS
    // --kpi-cols, aplicada aqui na raiz do documento).
    var seletorColunasKpi = document.getElementById('kpiColunasSelect');
    var colunasKpiSalvas = localStorage.getItem('kpiColunasPorLinha') || '4';
    document.documentElement.style.setProperty('--kpi-cols', colunasKpiSalvas);
    if (seletorColunasKpi) {
        seletorColunasKpi.value = colunasKpiSalvas;
        seletorColunasKpi.addEventListener('change', function () {
            document.documentElement.style.setProperty('--kpi-cols', seletorColunasKpi.value);
            localStorage.setItem('kpiColunasPorLinha', seletorColunasKpi.value);
        });
    }

    // Atalho "Usuários" no aviso da aba Visão Geral (liberação de horário
    // saiu daqui, ver acao=alternar_acesso_portal_fora_horario) -- clica de
    // verdade na aba real, em vez de só mudar a #url (senão a barra de abas
    // não atualiza qual está marcada como ativa).
    var linkIrAbaUsuarios = document.getElementById('linkIrAbaUsuariosHorario');
    if (linkIrAbaUsuarios) {
        linkIrAbaUsuarios.addEventListener('click', function (e) {
            e.preventDefault();
            var abaUsuarios = document.querySelector('.nav-tabs [data-bs-toggle="tab"][href="#usuarios"]');
            if (abaUsuarios) abaUsuarios.click();
        });
    }

    // Mesma trava contra duplo-clique/duplo-toque do formulário do portal
    // do cliente -- ver bug de chamados duplicados criados de uma vez.
    var formNovoChamadoEquipe = document.querySelector('#modalNovoChamado form');
    if (formNovoChamadoEquipe) {
        formNovoChamadoEquipe.addEventListener('submit', function () {
            var botaoEnviar = formNovoChamadoEquipe.querySelector('button[type="submit"]');
            if (botaoEnviar) {
                botaoEnviar.disabled = true;
                botaoEnviar.textContent = 'Enviando...';
            }
        });
    }

    // Player de vídeo aula -- só troca o src do <video> ao abrir o modal
    // (não carrega nada até o usuário clicar em "Assistir") e limpa/pausa ao
    // fechar, senão o navegador continuaria baixando/tocando em segundo
    // plano mesmo com o modal escondido.
    var modalVideoAula = document.getElementById('modalAssistirVideoAula');
    if (modalVideoAula) {
        var playerVideoAula = document.getElementById('videoAssistirPlayer');
        modalVideoAula.addEventListener('show.bs.modal', function (ev) {
            var gatilho = ev.relatedTarget;
            if (!gatilho) return;
            document.getElementById('tituloVideoAssistir').textContent = gatilho.dataset.videoNome || '';
            playerVideoAula.src = '<?= url('video_aula.php') ?>?id=' + encodeURIComponent(gatilho.dataset.videoId);
        });
        modalVideoAula.addEventListener('hidden.bs.modal', function () {
            playerVideoAula.pause();
            playerVideoAula.removeAttribute('src');
            playerVideoAula.load();
        });
    }

    // Filtro por nome da rotina na grade de vídeo aulas -- cada setor tem a
    // própria grade/campo (podem acumular muita vídeo aula com o tempo).
    document.querySelectorAll('.filtro-video-aula').forEach(function (campo) {
        var grade = document.getElementById(campo.dataset.alvo);
        if (!grade) return;
        campo.addEventListener('input', function () {
            var termo = campo.value.trim().toLowerCase();
            grade.querySelectorAll('[data-nome-filtro]').forEach(function (card) {
                card.style.display = card.dataset.nomeFiltro.indexOf(termo) === -1 ? 'none' : '';
            });
        });
    });

    // Mesma ideia do filtro de vídeo aula acima, só que pra tabela de
    // Fontes ADVPL -- filtra por rotina, ponto de entrada OU nome do
    // arquivo (tudo já concatenado em data-nome-filtro na linha).
    document.querySelectorAll('.filtro-fonte-advpl').forEach(function (campo) {
        var corpo = document.getElementById(campo.dataset.alvo);
        if (!corpo) return;
        campo.addEventListener('input', function () {
            var termo = campo.value.trim().toLowerCase();
            corpo.querySelectorAll('[data-nome-filtro]').forEach(function (linha) {
                linha.style.display = linha.dataset.nomeFiltro.indexOf(termo) === -1 ? 'none' : '';
            });
        });
    });

    // --- Vídeo aula: upload em PEDAÇOS ----------------------------------
    // Manda o arquivo em pedaços de ~10MB, um por vez, em vez de uma única
    // requisição gigante -- resolve de verdade o bug de "Registrando..."
    // travado por dezenas de minutos (o arquivo inteiro numa requisição só
    // dependia de camadas fora do nosso controle -- proxy da hospedagem,
    // disco -- que podiam segurar isso por muito tempo sem responder nada).
    // Cada pedaço é confirmado de verdade pelo servidor (ver
    // upload_video_aula_chunk.php), então a barra reflete progresso real, e
    // só mostra "salvo com sucesso" quando o ÚLTIMO pedaço realmente
    // terminar de ser processado no servidor.
    // 20MB por pedaço -- like qualquer outro upload desse site, depende do
    // upload_max_filesize/post_max_size subidos pra 5GB no .htaccess (ver
    // lá o comentário; mesmo override que já sustenta anexo de chamado e
    // documento comum). Sem esse override (php.ini padrão, sem nenhuma
    // config), o PHP teto é upload_max_filesize=2M -- rejeitaria QUALQUER
    // upload desse site, não só esse, então não faz sentido este pedaço
    // específico tentar ser mais conservador que o resto da aplicação.
    var TAMANHO_PEDACO_VIDEO_AULA = 20 * 1024 * 1024;

    function criarOverlayVideoAula() {
        var el = document.createElement('div');
        el.className = 'overlay-upload-progresso';
        el.innerHTML = '<div class="overlay-upload-progresso-caixa">'
            + '<div class="overlay-upload-progresso-titulo">Enviando vídeo aula...</div>'
            + '<div class="progress"><div class="progress-bar" role="progressbar">0%</div></div>'
            + '<div class="overlay-upload-progresso-detalhe"></div>'
            + '</div>';
        document.body.appendChild(el);
        return el;
    }

    function gerarUploadIdVideoAula() {
        var base = Date.now().toString(16) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
        return (base + '00000000000000000000000000000000').slice(0, 32);
    }

    // Manda 1 pedaço, com até 3 tentativas (rede instável no meio de um
    // upload longo -- de VÁRIOS pedaços -- não deveria jogar tudo fora,
    // só aquele pedaço específico precisa repetir).
    function enviarPedacoVideoAula(url, montarDados, tentativa) {
        tentativa = tentativa || 1;
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.timeout = 60000;
            xhr.addEventListener('load', function () {
                var json = null;
                try { json = JSON.parse(xhr.responseText); } catch (e) { /* segue com json=null */ }
                if (xhr.status >= 200 && xhr.status < 300 && json && json.sucesso) {
                    resolve(json);
                    return;
                }
                tentarDeNovoOuDesistir(json && json.mensagem ? json.mensagem : ('Falha no envio (HTTP ' + xhr.status + ').'));
            });
            xhr.addEventListener('error', function () { tentarDeNovoOuDesistir('Falha de rede.'); });
            xhr.addEventListener('timeout', function () { tentarDeNovoOuDesistir('O pedaço demorou demais pra ser confirmado.'); });

            function tentarDeNovoOuDesistir(mensagemErro) {
                if (tentativa < 3) {
                    setTimeout(function () {
                        enviarPedacoVideoAula(url, montarDados, tentativa + 1).then(resolve, reject);
                    }, 1000 * tentativa);
                    return;
                }
                reject(new Error(mensagemErro));
            }

            xhr.send(montarDados());
        });
    }

    document.querySelectorAll('.form-video-aula-chunk').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var inputArquivo = form.querySelector('input[type="file"]');
            var arquivo = inputArquivo.files[0];
            if (!arquivo) return;

            var campoNomeRotina = form.querySelector('input[name="nome_rotina"]');
            var nomeRotina = campoNomeRotina.value.trim();
            if (!nomeRotina) {
                alert('Informe o nome da rotina da vídeo aula.');
                return;
            }

            var campoSetorId = form.querySelector('input[name="setor_id"]');
            var csrfToken = form.querySelector('input[name="csrf_token"]').value;
            var campoAbaRetorno = form.querySelector('input[name="aba_retorno"]');
            var botaoEnviar = form.querySelector('button[type="submit"]');

            var overlay = criarOverlayVideoAula();
            var titulo = overlay.querySelector('.overlay-upload-progresso-titulo');
            var barra = overlay.querySelector('.progress-bar');
            var detalhe = overlay.querySelector('.overlay-upload-progresso-detalhe');
            overlay.classList.add('show');
            botaoEnviar.disabled = true;

            var avisarAntesDeSair = function (ev) { ev.preventDefault(); ev.returnValue = ''; };
            window.addEventListener('beforeunload', avisarAntesDeSair);

            var uploadId = gerarUploadIdVideoAula();
            var totalPartes = Math.max(1, Math.ceil(arquivo.size / TAMANHO_PEDACO_VIDEO_AULA));
            var indiceAtual = 0;

            function enviarProximoPedaco() {
                var inicio = indiceAtual * TAMANHO_PEDACO_VIDEO_AULA;
                var fim = Math.min(inicio + TAMANHO_PEDACO_VIDEO_AULA, arquivo.size);
                var pedaco = arquivo.slice(inicio, fim);

                titulo.textContent = 'Enviando vídeo aula...';
                detalhe.textContent = 'Pedaço ' + (indiceAtual + 1) + ' de ' + totalPartes;
                var pctAntes = Math.round((indiceAtual / totalPartes) * 100);
                barra.style.width = pctAntes + '%';
                barra.textContent = pctAntes + '%';

                enviarPedacoVideoAula('<?= url('upload_video_aula_chunk.php') ?>', function () {
                    var dados = new FormData();
                    dados.set('csrf_token', csrfToken);
                    dados.set('cliente_id', '<?= (int) $clienteId ?>');
                    if (campoSetorId) dados.set('setor_id', campoSetorId.value);
                    dados.set('nome_rotina', nomeRotina);
                    dados.set('nome_arquivo', arquivo.name);
                    dados.set('upload_id', uploadId);
                    dados.set('indice', String(indiceAtual));
                    dados.set('total_partes', String(totalPartes));
                    dados.set('pedaco', pedaco, 'pedaco.part');
                    return dados;
                }).then(function (json) {
                    indiceAtual++;
                    var pct = Math.round((indiceAtual / totalPartes) * 100);
                    barra.style.width = pct + '%';
                    barra.textContent = pct + '%';

                    if (json.finalizado) {
                        window.removeEventListener('beforeunload', avisarAntesDeSair);
                        titulo.textContent = 'Vídeo aula salva com sucesso!';
                        detalhe.textContent = '';
                        setTimeout(function () {
                            var abaRetorno = campoAbaRetorno ? campoAbaRetorno.value : '';
                            var urlFinal = window.location.href.split('#')[0];
                            window.location.href = abaRetorno ? (urlFinal + '#' + abaRetorno) : urlFinal;
                        }, 1200);
                        return;
                    }
                    enviarProximoPedaco();
                }).catch(function (erro) {
                    window.removeEventListener('beforeunload', avisarAntesDeSair);
                    overlay.classList.remove('show');
                    overlay.remove();
                    botaoEnviar.disabled = false;
                    alert('Falha ao enviar o vídeo (' + erro.message + '). Tente novamente.');
                });
            }

            enviarProximoPedaco();
        });
    });

    // --- Aba Acessos (cofre de credenciais) -----------------------------
    var modalCredencial = document.getElementById('modalCredencial');
    if (modalCredencial) {
        modalCredencial.querySelectorAll('.toggle-senha').forEach(function (botao) {
            botao.addEventListener('click', function () {
                var campo = botao.previousElementSibling;
                var mostrando = campo.type === 'text';
                campo.type = mostrando ? 'password' : 'text';
                botao.querySelector('i').className = mostrando ? 'bi bi-eye' : 'bi bi-eye-slash';
            });
        });

        var botaoNovaCredencial = document.getElementById('botaoNovaCredencial');
        if (botaoNovaCredencial) {
            botaoNovaCredencial.addEventListener('click', function () {
                document.getElementById('credencialModalTitulo').textContent = 'Nova credencial';
                document.getElementById('credencialAcao').value = 'criar_credencial';
                document.getElementById('credencialId').value = '';
                document.getElementById('credencialTipo').value = 'servidor';
                document.getElementById('credencialTitulo').value = '';
                document.getElementById('credencialHost').value = '';
                document.getElementById('credencialPorta').value = '';
                document.getElementById('credencialUsuario').value = '';
                document.getElementById('credencialSenha').value = '';
                document.getElementById('credencialObservacoes').value = '';
                document.getElementById('credencialSenhaDica').style.display = 'none';
                PrimeUI.openModal(modalCredencial);
            });
        }

        document.querySelectorAll('.botao-editar-credencial').forEach(function (botao) {
            botao.addEventListener('click', function () {
                document.getElementById('credencialModalTitulo').textContent = 'Editar credencial';
                document.getElementById('credencialAcao').value = 'atualizar_credencial';
                document.getElementById('credencialId').value = botao.dataset.id;
                document.getElementById('credencialTipo').value = botao.dataset.tipo;
                document.getElementById('credencialTitulo').value = botao.dataset.titulo;
                document.getElementById('credencialHost').value = botao.dataset.host || '';
                document.getElementById('credencialPorta').value = botao.dataset.porta || '';
                document.getElementById('credencialUsuario').value = botao.dataset.usuario || '';
                document.getElementById('credencialSenha').value = '';
                document.getElementById('credencialObservacoes').value = botao.dataset.observacoes || '';
                document.getElementById('credencialSenhaDica').style.display = '';
                PrimeUI.openModal(modalCredencial);
            });
        });
    }

    // "Mostrar senha" e "Copiar senha" na lista de credenciais -- busca a
    // senha decifrada só na hora que alguém realmente precisa dela (nunca
    // fica em texto puro no HTML da página), e só busca uma vez (fica em
    // cache no próprio elemento pra alternar mostrar/ocultar ou copiar de
    // novo sem bater no servidor nem gerar log repetido).
    var tabelaCredenciais = document.getElementById('tabelaCredenciais');
    if (tabelaCredenciais) {
        var csrfVerSenha = tabelaCredenciais.dataset.csrfVerSenha;

        var obterSenhaReal = function (linha, valorSpan) {
            if (valorSpan.dataset.senhaReal !== undefined) {
                return Promise.resolve(valorSpan.dataset.senhaReal);
            }
            var dados = new URLSearchParams();
            dados.set('csrf_token', csrfVerSenha);
            dados.set('id', linha.dataset.credencialId);
            return fetch('<?= url('ver_credencial.php') ?>', { method: 'POST', body: dados })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json.sucesso) {
                        throw new Error(json.mensagem || 'Não foi possível buscar a senha.');
                    }
                    valorSpan.dataset.senhaReal = json.senha || '(sem senha cadastrada)';
                    return valorSpan.dataset.senhaReal;
                });
        };

        tabelaCredenciais.querySelectorAll('.botao-mostrar-senha').forEach(function (botao) {
            botao.addEventListener('click', function () {
                var linha = botao.closest('.credencial-senha-linha');
                var valorSpan = linha.querySelector('.credencial-senha-valor');

                if (valorSpan.dataset.senhaReal !== undefined) {
                    var mostrando = valorSpan.textContent !== '••••••••';
                    valorSpan.textContent = mostrando ? '••••••••' : valorSpan.dataset.senhaReal;
                    botao.querySelector('i').className = mostrando ? 'bi bi-eye' : 'bi bi-eye-slash';
                    return;
                }

                obterSenhaReal(linha, valorSpan).then(function (senha) {
                    valorSpan.textContent = senha;
                    botao.querySelector('i').className = 'bi bi-eye-slash';
                }).catch(function (e) { alert(e.message || 'Falha ao buscar a senha. Tente de novo.'); });
            });
        });

        // Copiar direto pra área de transferência sem precisar mostrar a
        // senha na tela antes -- pedido do Kleber, útil em call com tela
        // compartilhada.
        tabelaCredenciais.querySelectorAll('.botao-copiar-senha').forEach(function (botao) {
            botao.addEventListener('click', function () {
                var linha = botao.closest('.credencial-senha-linha');
                var valorSpan = linha.querySelector('.credencial-senha-valor');
                var icone = botao.querySelector('i');

                obterSenhaReal(linha, valorSpan)
                    .then(function (senha) { return navigator.clipboard.writeText(senha); })
                    .then(function () {
                        icone.className = 'bi bi-check-lg text-success';
                        setTimeout(function () { icone.className = 'bi bi-clipboard'; }, 1500);
                    })
                    .catch(function (e) { alert(e.message || 'Falha ao copiar a senha. Tente de novo.'); });
            });
        });
    }

    // --- Fontes ADVPL: editar (rotina/ponto de entrada/descrição/arquivo) --
    var modalEditarFonteAdvpl = document.getElementById('modalEditarFonteAdvpl');
    if (modalEditarFonteAdvpl) {
        document.querySelectorAll('.botao-editar-fonte-advpl').forEach(function (botao) {
            botao.addEventListener('click', function () {
                document.getElementById('editarFonteAdvplId').value = botao.dataset.id;
                document.getElementById('editarFonteAdvplTitulo').value = botao.dataset.titulo;
                document.getElementById('editarFonteAdvplPontoEntrada').value = botao.dataset.pontoEntrada || '';
                document.getElementById('editarFonteAdvplDescricao').value = botao.dataset.descricao || '';
                document.getElementById('editarFonteAdvplArquivoAtual').textContent = botao.dataset.arquivo;
                PrimeUI.openModal(modalEditarFonteAdvpl);
            });
        });
    }

    // --- Aba T-Cloud (ambientes + configs VSCode) -------------------------
    // Botão de copiar reaproveitado nos 2 modais dessa aba e na tabela de
    // configs VSCode -- lê o valor do input indicado em data-alvo (modais)
    // ou direto de data-valor (célula da tabela), sem precisar de um
    // handler diferente pra cada lugar.
    document.querySelectorAll('.botao-copiar-link').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var valor = botao.dataset.valor;
            if (valor === undefined) {
                var campoAlvo = document.getElementById(botao.dataset.alvo);
                valor = campoAlvo ? campoAlvo.value : '';
            }
            if (!valor) return;

            var marcarCopiado = function () {
                var iconeOriginal = botao.innerHTML;
                botao.innerHTML = '<i class="bi bi-check2"></i>';
                setTimeout(function () { botao.innerHTML = iconeOriginal; }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(valor).then(marcarCopiado).catch(function () { alert('Não foi possível copiar. Copie manualmente.'); });
            } else {
                var campoTemporario = document.createElement('textarea');
                campoTemporario.value = valor;
                campoTemporario.style.position = 'fixed';
                campoTemporario.style.opacity = '0';
                document.body.appendChild(campoTemporario);
                campoTemporario.select();
                try { document.execCommand('copy'); marcarCopiado(); } catch (e) { alert('Não foi possível copiar. Copie manualmente.'); }
                document.body.removeChild(campoTemporario);
            }
        });
    });

    // Modal de ambiente (Produção/Teste) -- disparado pelo próprio card via
    // data-bs-toggle/data-bs-target, sem JS de clique próprio; só escuta
    // show.bs.modal pra preencher os campos a partir dos data-* do card
    // clicado (event.relatedTarget), mesmo padrão do player de vídeo aula.
    var modalTcloudAmbiente = document.getElementById('modalTcloudAmbiente');
    if (modalTcloudAmbiente) {
        modalTcloudAmbiente.addEventListener('show.bs.modal', function (ev) {
            var card = ev.relatedTarget;
            if (!card) return;
            document.getElementById('tcloudAmbienteModalTitulo').textContent = card.dataset.titulo || 'Ambiente';
            document.getElementById('tcloudAmbienteTipo').value = card.dataset.tipo || '';
            <?php foreach (array_keys(TCLOUD_LINKS) as $campoLinkTcloudJs): ?>
            document.getElementById('tcloudAmbiente_<?= e($campoLinkTcloudJs) ?>').value = card.dataset.<?= lcfirst(str_replace('_', '', ucwords($campoLinkTcloudJs, '_'))) ?> || '';
            <?php endforeach; ?>
        });
    }

    // Modal de configuração do VSCode -- mesmo padrão do modal de
    // credencial (um botão "novo" que reseta, um botão de editar por linha
    // que preenche a partir dos data-* daquela linha).
    var modalTcloudVscode = document.getElementById('modalTcloudVscode');
    if (modalTcloudVscode) {
        var botaoNovoTcloudVscode = document.getElementById('botaoNovoTcloudVscode');
        if (botaoNovoTcloudVscode) {
            botaoNovoTcloudVscode.addEventListener('click', function () {
                document.getElementById('tcloudVscodeModalTitulo').textContent = 'Nova configuração do VSCode';
                document.getElementById('tcloudVscodeAcao').value = 'criar_tcloud_vscode';
                document.getElementById('tcloudVscodeId').value = '';
                document.getElementById('tcloudVscodeTitulo').value = '';
                document.getElementById('tcloudVscodeLink').value = '';
                document.getElementById('tcloudVscodePorta').value = '';
                document.getElementById('tcloudVscodeAmbiente').value = '';
                PrimeUI.openModal(modalTcloudVscode);
            });
        }

        document.querySelectorAll('.botao-editar-tcloud-vscode').forEach(function (botao) {
            botao.addEventListener('click', function () {
                document.getElementById('tcloudVscodeModalTitulo').textContent = 'Editar configuração do VSCode';
                document.getElementById('tcloudVscodeAcao').value = 'atualizar_tcloud_vscode';
                document.getElementById('tcloudVscodeId').value = botao.dataset.id;
                document.getElementById('tcloudVscodeTitulo').value = botao.dataset.titulo;
                document.getElementById('tcloudVscodeLink').value = botao.dataset.link || '';
                document.getElementById('tcloudVscodePorta').value = botao.dataset.porta || '';
                document.getElementById('tcloudVscodeAmbiente').value = botao.dataset.ambiente || '';
                PrimeUI.openModal(modalTcloudVscode);
            });
        });
    }

    var hash = window.location.hash || '#visao-geral';
    var tabLink = document.querySelector('.nav-tabs a[href="' + hash + '"]');
    var tabPane = document.querySelector(hash);
    if (tabLink && tabPane) {
        PrimeUI.showTab(tabLink);
    } else {
        var first = document.querySelector('.nav-tabs a');
        if (first) PrimeUI.showTab(first);
    }
    document.querySelectorAll('.nav-tabs a').forEach(function (link) {
        link.addEventListener('shown.bs.tab', function (e) {
            history.replaceState(null, '', e.target.getAttribute('href'));
        });
    });

    // Filtro de chamados por status + por setor (client-side, sem
    // recarregar a página) -- os dois combinam (ex.: "Pendentes" do setor
    // "Financeiro" ao mesmo tempo).
    var grupoFiltro = document.getElementById('filtroStatusChamados');
    var filtroAtual = 'pendentes';
    var filtroSetorAtual = 'todos';
    function aplicarFiltroChamados() {
        var algumVisivel = false;
        document.querySelectorAll('#listaChamados .chamado-card').forEach(function (card) {
            var status = card.getAttribute('data-status');
            // "Pendentes" = pendência NOSSA (aberto ou aguardando_equipe) --
            // mesma definição usada no Dashboard e nos cards de Clientes, pra
            // não mostrar número diferente em cada tela pro mesmo cliente.
            // "aguardando_cliente" (já respondemos) tem o próprio filtro à
            // parte, não conta como pendência aqui.
            var mostraStatus = filtroAtual === 'todos' || (filtroAtual === 'pendentes' ? (status === 'aberto' || status === 'aguardando_equipe') : status === filtroAtual);
            var mostraSetor = filtroSetorAtual === 'todos' || card.getAttribute('data-setor') === filtroSetorAtual;
            var mostra = mostraStatus && mostraSetor;
            card.style.display = mostra ? '' : 'none';
            if (mostra) algumVisivel = true;
        });
        var aviso = document.getElementById('avisoFiltroVazio');
        if (aviso) aviso.classList.toggle('d-none', algumVisivel || document.querySelectorAll('#listaChamados .chamado-card').length === 0);
    }
    if (grupoFiltro) {
        aplicarFiltroChamados();
        grupoFiltro.querySelectorAll('.filtro-pill').forEach(function (botao) {
            botao.addEventListener('click', function () {
                grupoFiltro.querySelectorAll('.filtro-pill').forEach(function (b) { b.classList.remove('active'); });
                botao.classList.add('active');
                filtroAtual = botao.getAttribute('data-filtro');
                aplicarFiltroChamados();
            });
        });
        // Ao abrir o modal de exportar, já vem com o status do filtro ativo
        // selecionado (mas o usuário pode trocar antes de exportar).
        var modalExportar = document.getElementById('modalExportarChamados');
        if (modalExportar) {
            modalExportar.addEventListener('show.bs.modal', function () {
                var select = document.getElementById('exportarStatus');
                if (select) select.value = filtroAtual;
            });
        }
    }

    // Volta pro MESMO chamado de onde a ação partiu (responder, encerrar,
    // trocar setor/responsável) em vez de sempre abrir a aba Chamados lá do
    // topo -- pedido do Kleber: com 15-20 chamados abertos, ter que rolar
    // tudo de novo depois de cada resposta era inaceitável. Ver
    // scroll_para em cada form de chamado + o redirect no topo do arquivo.
    (function () {
        var parametros = new URLSearchParams(window.location.search);
        var scrollPara = parametros.get('scroll_para');
        if (!scrollPara) return;

        parametros.delete('scroll_para');
        var novaQuery = parametros.toString();
        history.replaceState(null, '', window.location.pathname + (novaQuery ? '?' + novaQuery : '') + window.location.hash);

        var alvo = document.getElementById(scrollPara);
        if (!alvo) return;

        // A resposta pode ter tirado o chamado do filtro "Pendentes"
        // (padrão da tela) -- ex.: virou "Aguardando cliente" -- então
        // força "Todos" antes de rolar, senão o card fica escondido.
        var botaoTodos = document.querySelector('.filtro-todos');
        if (botaoTodos) botaoTodos.click();

        setTimeout(function () {
            alvo.scrollIntoView({ block: 'center' });
            alvo.classList.add('chamado-destacado');
            setTimeout(function () { alvo.classList.remove('chamado-destacado'); }, 2000);
        }, 50);
    })();

    document.querySelectorAll('.card-setor').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.card-setor').forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            filtroSetorAtual = card.getAttribute('data-setor-filtro');
            aplicarFiltroChamados();
            document.getElementById('listaChamados').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        card.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') card.click();
        });
    });

    // Sugestões rápidas de nome+ícone de setor (modal Gerenciar setores) --
    // só preenche o campo, quem decide se cria é o próprio botão "Criar setor".
    document.querySelectorAll('.botao-sugestao-setor').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = document.getElementById('novoSetorNome');
            if (campo) {
                campo.value = botao.getAttribute('data-nome');
                campo.focus();
            }
            selecionarIcone(document.querySelector('.seletor-icone-setor[data-campo-destino="novoSetorIcone"]'), botao.getAttribute('data-icone'));
        });
    });

    // Seletor de ícone de setor -- clicar num ícone marca ele como escolhido
    // (visualmente) e preenche o input escondido que vai no form de
    // criar/atualizar setor (mesma ideia da galeria de avatares).
    function selecionarIcone(grupo, icone) {
        if (!grupo) return;
        var campo = document.getElementById(grupo.getAttribute('data-campo-destino'));
        if (campo) campo.value = icone;
        grupo.querySelectorAll('.icone-opcao').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-icone') === icone);
        });
    }
    document.querySelectorAll('.seletor-icone-setor').forEach(function (grupo) {
        grupo.querySelectorAll('.icone-opcao').forEach(function (botaoIcone) {
            botaoIcone.addEventListener('click', function () {
                selecionarIcone(grupo, botaoIcone.getAttribute('data-icone'));
            });
        });
    });

    // Seletor de avatar no cadastro de NOVO usuário -- escolhe um avatar
    // pronto já na criação (antes só dava pra trocar depois de editar).
    var galeriaAvatarNovo = document.getElementById('galeriaAvatarNovoUsuarioCliente');
    if (galeriaAvatarNovo) {
        galeriaAvatarNovo.querySelectorAll('.avatar-opcao').forEach(function (botao) {
            botao.addEventListener('click', function () {
                document.getElementById('novoUsuarioClienteAvatar').value = botao.getAttribute('data-avatar');
                galeriaAvatarNovo.querySelectorAll('.avatar-opcao').forEach(function (b) { b.classList.remove('active'); });
                botao.classList.add('active');
            });
        });
    }

    // Linha da aba Usuários -- clique abre o modal de edição já preenchido
    // (mesmo padrão do modal de usuário da equipe em usuarios.php).
    var baseUrlAvatarCliente = <?= json_encode(url('')) ?>;
    function abrirModalUsuarioCliente(dados) {
        document.getElementById('modalUsuarioClienteTitulo').textContent = dados.nome;
        document.getElementById('usuarioClienteAvatarPreview').src = baseUrlAvatarCliente + (dados.foto_perfil || 'assets/img/avatares/avatar-1.svg');
        document.getElementById('avatarUsuarioClienteId').value = dados.id;
        document.getElementById('editarUsuarioClienteId').value = dados.id;
        document.getElementById('editarUsuarioClienteNome').value = dados.nome;
        document.getElementById('editarUsuarioClienteEmail').value = dados.email;
        document.getElementById('editarUsuarioClienteTelefone').value = dados.telefone ? PrimeUI.formatarTelefone(dados.telefone) : '';
        document.getElementById('editarUsuarioClienteDocumento').value = dados.documento || '';
        var setorIdsAtuais = (dados.setor_ids || []).map(String);
        document.querySelectorAll('.checkbox-editar-usuario-cliente-setor').forEach(function (chk) {
            chk.checked = setorIdsAtuais.indexOf(chk.value) !== -1;
        });
        document.getElementById('editarUsuarioClienteEhMaster').checked = !!parseInt(dados.eh_master || 0, 10);
        document.querySelectorAll('.avatar-usuario-cliente-id-campo').forEach(function (campo) { campo.value = dados.id; });
        PrimeUI.openModal(document.getElementById('modalUsuarioCliente'));
    }
    document.querySelectorAll('.linha-usuario-cliente').forEach(function (linha) {
        linha.addEventListener('click', function (e) {
            if (e.target.closest('form, button, a, select')) return;
            abrirModalUsuarioCliente(JSON.parse(linha.getAttribute('data-usuario')));
        });
        linha.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') linha.click();
        });
    });

    // Botão "Setor(es)" de cada linha da aba Usuários (master ou usuário) --
    // abre o modal único de marcar/desmarcar setores. responsavelIdSetoresPendente
    // vazio = é a linha do master (grava em cliente_setores via
    // 'definir_setores_master'); com valor = é um usuário (grava em
    // setor_responsavel_setores via 'definir_setores_responsavel').
    var responsavelIdSetoresPendente = '';
    document.querySelectorAll('.botao-editar-setores-usuario').forEach(function (botao) {
        botao.addEventListener('click', function () {
            responsavelIdSetoresPendente = botao.getAttribute('data-responsavel-id') || '';
            var setorIdsAtuaisUsuario = (JSON.parse(botao.getAttribute('data-setores-atuais') || '[]')).map(String);
            document.querySelectorAll('.checkbox-setor-modal-usuario').forEach(function (chk) {
                chk.checked = setorIdsAtuaisUsuario.indexOf(chk.value) !== -1;
            });
            document.getElementById('modalSetoresUsuarioNome').textContent = botao.getAttribute('data-nome') || '';
            PrimeUI.openModal(document.getElementById('modalSetoresUsuario'));
        });
    });

    var botaoConfirmarSetoresUsuario = document.getElementById('botaoConfirmarSetoresUsuario');
    if (botaoConfirmarSetoresUsuario) {
        botaoConfirmarSetoresUsuario.addEventListener('click', function () {
            var setorIdsMarcados = [];
            document.querySelectorAll('.checkbox-setor-modal-usuario:checked').forEach(function (chk) { setorIdsMarcados.push(chk.value); });

            var formTemp = document.createElement('form');
            formTemp.method = 'post';
            formTemp.action = window.location.href;
            var camposOcultos = '<?= csrf_field() ?>'
                + '<input type="hidden" name="aba_retorno" value="usuarios">';
            if (responsavelIdSetoresPendente) {
                camposOcultos += '<input type="hidden" name="acao" value="definir_setores_responsavel">'
                    + '<input type="hidden" name="responsavel_id" value="' + responsavelIdSetoresPendente + '">';
            } else {
                camposOcultos += '<input type="hidden" name="acao" value="definir_setores_master">';
            }
            setorIdsMarcados.forEach(function (sid) { camposOcultos += '<input type="hidden" name="setor_ids[]" value="' + sid + '">'; });
            formTemp.innerHTML = camposOcultos;
            document.body.appendChild(formTemp);
            formTemp.submit();
        });
    }

    // "Gerenciar setores" / "Configurar setores" (aba Chamados) -- antes
    // abria um modal, agora só troca pra aba Setores.
    document.querySelectorAll('.botao-ir-para-setores').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var link = document.querySelector('.nav-tabs a[href="#setores"]');
            if (link) {
                PrimeUI.showTab(link);
                history.replaceState(null, '', '#setores');
                link.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });

    // "Novo treinamento/reunião" de dentro de um setor específico (aba
    // Setores > Calendário) -- pré-seleciona o setor no modal compartilhado
    // antes de abrir, em vez de duplicar o modal pra cada setor.
    document.querySelectorAll('.botao-novo-treinamento-setor').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = document.getElementById('novoTreinamentoSetor');
            var campoRetorno = document.getElementById('novoTreinamentoAbaRetorno');
            if (campo) campo.value = botao.getAttribute('data-setor-id');
            if (campoRetorno) campoRetorno.value = botao.getAttribute('data-aba-retorno') || 'setores';
            PrimeUI.openModal(document.getElementById('modalNovoTreinamento'));
        });
    });

    // "Registrar KPI" da aba KPIs geral -- precisa ZERAR setor/retorno do
    // modal (mesmo <select>/hidden são reaproveitados pelo botão de dentro
    // de um setor, ver abaixo), senão fica com o setor/aba do último uso
    // "presos" e o KPI "desaparece" (vai pro setor errado, sem avisar).
    var botaoNovoKpiGeral = document.querySelector('.botao-novo-kpi-geral');
    if (botaoNovoKpiGeral) {
        botaoNovoKpiGeral.addEventListener('click', function () {
            var campoSetor = document.getElementById('novoKpiSetor');
            var campoRetorno = document.getElementById('novoKpiAbaRetorno');
            var formNovoKpi = document.getElementById('modalNovoKpi').querySelector('form');
            if (campoSetor) campoSetor.value = '';
            if (campoRetorno) campoRetorno.value = 'kpis';
            if (formNovoKpi) formNovoKpi.reset();
            PrimeUI.openModal(document.getElementById('modalNovoKpi'));
        });
    }

    // "Registrar métrica" de dentro de um setor específico (aba Setores >
    // Métricas) -- mesma ideia, pré-seleciona o setor e ajusta o retorno
    // pra voltar pra aba Setores (não pra aba KPIs geral).
    document.querySelectorAll('.botao-novo-kpi-setor').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campoSetor = document.getElementById('novoKpiSetor');
            var campoRetorno = document.getElementById('novoKpiAbaRetorno');
            var formNovoKpi = document.getElementById('modalNovoKpi').querySelector('form');
            if (formNovoKpi) formNovoKpi.reset();
            if (campoSetor) campoSetor.value = botao.getAttribute('data-setor-id');
            if (campoRetorno) campoRetorno.value = 'setores';
            PrimeUI.openModal(document.getElementById('modalNovoKpi'));
        });
    });

    // Overlay de carregamento -- mostrado em TODO submit de formulário
    // (feedback visual imediato, pedido do Kleber depois de reclamar que os
    // cliques "pareciam não fazer nada" enquanto a página recarregava).
    // Criado uma vez só, reaproveitado por qualquer chamada.
    var overlayCarregandoEl = document.createElement('div');
    overlayCarregandoEl.className = 'overlay-carregando';
    overlayCarregandoEl.innerHTML = '<div class="overlay-carregando-caixa">'
        + '<i class="bi overlay-carregando-icone"></i>'
        + '<div class="overlay-carregando-texto"></div>'
        + '</div>';
    document.body.appendChild(overlayCarregandoEl);

    function mostrarCarregando(mensagem, comEstrelas) {
        overlayCarregandoEl.querySelector('.overlay-carregando-icone').className = 'bi overlay-carregando-icone ' + (comEstrelas ? 'bi-stars' : 'bi-arrow-repeat');
        overlayCarregandoEl.querySelector('.overlay-carregando-texto').textContent = mensagem;
        overlayCarregandoEl.classList.add('mostrando');
    }

    function escondercarregando() {
        overlayCarregandoEl.classList.remove('mostrando');
    }

    // Todo submit de formulário na página mostra o overlay -- na maioria
    // dos casos a própria troca de página (recarregamento normal) já
    // "esconde" o overlay junto, então não precisa de código pra escondê-lo
    // de novo aqui. Formulários com tratamento próprio (ex.: gerar sugestão
    // de IA, abaixo) chamam stopPropagation() pra não disparar esse aviso
    // genérico em cima do aviso específico deles.
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        mostrarCarregando('Salvando...', false);
    });

    // Gerar sugestão de IA -- via AJAX (fetch), sem recarregar a página
    // inteira (era o que mais incomodava: o usuário perdia o lugar onde
    // estava -- qual setor, qual sub-aba -- a cada geração). Atualiza só o
    // resultado, na mesma sub-aba.
    document.querySelectorAll('.form-gerar-sugestao-ia').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            mostrarCarregando('Gerando sugestão com IA...', true);

            var dados = new FormData(form);
            dados.set('ajax', '1');

            fetch(window.location.href, { method: 'POST', body: dados })
                .then(function (resp) { return resp.json(); })
                .then(function (json) {
                    var setorId = form.getAttribute('data-setor-id');
                    var alvo = document.getElementById('resultadoSugestaoIa' + setorId);
                    if (!alvo) return;
                    if (json.sucesso) {
                        alvo.innerHTML = '<div class="card card-stat"><div class="card-body">'
                            + '<p class="text-muted small mb-2">Gerado em ' + escaparHtmlCalendario(json.gerado_em) + '</p>'
                            + '<div style="white-space: pre-wrap;">' + escaparHtmlCalendario(json.sugestao) + '</div>'
                            + '</div></div>';
                    } else {
                        alvo.innerHTML = '<div class="alert alert-danger">' + escaparHtmlCalendario(json.mensagem) + '</div>';
                    }
                })
                .catch(function (erro) {
                    var setorId = form.getAttribute('data-setor-id');
                    var alvo = document.getElementById('resultadoSugestaoIa' + setorId);
                    if (alvo) alvo.innerHTML = '<div class="alert alert-danger">Falha de conexão ao gerar a sugestão: ' + escaparHtmlCalendario(String(erro)) + '</div>';
                })
                .finally(function () {
                    escondercarregando();
                });
        });
    });

    // Aba "Análise de Risco" -- cada geração vira um CARD novo na grade
    // certa (risco ou melhorias), sem apagar os anteriores. Visualizar/
    // Editar abrem o mesmo modal (#modalAnalise); Exportar PDF funciona
    // tanto direto do card quanto de dentro do modal; Excluir é um form
    // comum (confirm() abaixo).
    document.querySelectorAll('.form-gerar-analise-risco').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            mostrarCarregando('Gerando análise...', true);

            var dados = new FormData(form);
            dados.set('ajax', '1');
            var tipoSelecionado = form.querySelector('[name="tipo"]').value;
            var setorSelecionado = form.querySelector('[name="setor_id"]');
            var setorNomeSelecionado = setorSelecionado.selectedIndex > 0 ? setorSelecionado.options[setorSelecionado.selectedIndex].text : null;

            fetch(window.location.href, { method: 'POST', body: dados })
                .then(function (resp) { return resp.json(); })
                .then(function (json) {
                    if (!json.sucesso) {
                        alert(json.mensagem || 'Não foi possível gerar a análise.');
                        return;
                    }
                    adicionarCardAnalise(tipoSelecionado, json.id, setorNomeSelecionado, json.gerado_em, json.analise);
                })
                .catch(function (erro) {
                    alert('Falha de conexão ao gerar a análise: ' + String(erro));
                })
                .finally(function () {
                    escondercarregando();
                });
        });
    });

    function tituloSecaoAnalise(tipo) {
        return tipo === 'melhorias' ? 'Análises de Melhorias' : 'Análises de Risco';
    }

    // Espelha markdown_para_texto_simples() do helpers.php -- card novo
    // criado aqui via AJAX (sem recarregar a página) precisa ficar
    // idêntico ao que o PHP renderiza depois de um F5.
    function textoSimplesAnalise(texto) {
        var limpo = texto
            .replace(/^#{1,6}\s*/gm, '')
            .replace(/\*\*(.+?)\*\*/gs, '$1')
            .replace(/__(.+?)__/gs, '$1')
            .replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/gs, '$1')
            .replace(/^[-*]\s+/gm, '• ')
            .replace(/\s+/g, ' ')
            .trim();
        return limpo.length > 240 ? limpo.slice(0, 239) + '…' : limpo;
    }

    // Espelha analise_risco_cor() do helpers.php.
    function corNivelAnalise(tipo, texto) {
        if (tipo !== 'melhorias') {
            var m = texto.match(/\*\*\s*(alto|m[eé]dio|baixo)\s*\*\*/i);
            if (m) {
                var nivel = m[1].toLowerCase();
                if (nivel === 'alto') return { cor: '#b91c1c', icone: 'bi-exclamation-triangle-fill', label: 'Risco alto' };
                if (nivel === 'baixo') return { cor: '#15803d', icone: 'bi-check-circle-fill', label: 'Risco baixo' };
                return { cor: '#b45309', icone: 'bi-exclamation-circle-fill', label: 'Risco médio' };
            }
            return { cor: '#52525b', icone: 'bi-dash-circle', label: null };
        }
        return { cor: '#4f46e5', icone: 'bi-stars', label: null };
    }

    function adicionarCardAnalise(tipo, id, setorNome, geradoEm, texto) {
        var grade = document.getElementById('gridAnalise' + tipo);
        if (!grade) return;

        var placeholder = grade.querySelector('.analise-grade-vazio');
        if (placeholder) placeholder.remove();

        var preview = textoSimplesAnalise(texto);
        var nivel = corNivelAnalise(tipo, texto);
        var label = tituloSecaoAnalise(tipo) + (setorNome ? ' - ' + setorNome : '');

        var card = document.createElement('div');
        card.className = 'analise-card';
        card.style.setProperty('--analise-cor', nivel.cor);
        card.dataset.id = id;
        card.dataset.tipo = tipo;
        card.dataset.label = label;
        card.innerHTML = '<div class="analise-card-topo">'
            + '<span class="analise-card-icone"><i class="bi ' + nivel.icone + '"></i></span>'
            + '<span class="analise-card-escopo text-truncate">' + escaparHtmlCalendario(setorNome || 'Cliente inteiro') + '</span>'
            + '<span class="analise-card-data">' + escaparHtmlCalendario(geradoEm) + '</span>'
            + '</div>'
            + (nivel.label ? '<div class="analise-card-nivel">' + nivel.label + '</div>' : '')
            + '<p class="analise-card-preview">' + escaparHtmlCalendario(preview) + '</p>'
            + '<textarea class="d-none analise-texto-completo">' + escaparHtmlCalendario(texto) + '</textarea>'
            + '<div class="analise-card-rodape">'
            + '<button type="button" class="btn btn-sm btn-outline-dark botao-visualizar-analise" title="Visualizar"><i class="bi bi-eye"></i></button>'
            + '<button type="button" class="btn btn-sm btn-outline-dark botao-editar-analise" title="Editar"><i class="bi bi-pencil"></i></button>'
            + '<button type="button" class="btn btn-sm btn-outline-dark botao-exportar-pdf-analise" title="Exportar PDF"><i class="bi bi-file-earmark-pdf"></i></button>'
            + '<form method="post" class="d-inline form-excluir-analise">'
            + '<?= csrf_field() ?>'
            + '<input type="hidden" name="acao" value="excluir_analise_risco">'
            + '<input type="hidden" name="aba_retorno" value="analise-risco">'
            + '<input type="hidden" name="id" value="' + id + '">'
            + '<button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>'
            + '</form></div>';
        grade.insertBefore(card, grade.firstChild);
    }

    var modalAnalise = document.getElementById('modalAnalise');
    var modalAnaliseTexto = document.getElementById('modalAnaliseTexto');
    var botaoModalAnaliseSalvar = document.getElementById('botaoModalAnaliseSalvar');

    function abrirModalAnalise(card, modoEdicao) {
        var textoCompleto = card.querySelector('.analise-texto-completo').value;
        modalAnalise.dataset.id = card.dataset.id;
        modalAnalise.dataset.label = card.dataset.label;
        document.getElementById('modalAnaliseTitulo').textContent = card.dataset.label + (modoEdicao ? ' -- editando' : '');
        modalAnaliseTexto.value = textoCompleto;
        modalAnaliseTexto.readOnly = !modoEdicao;
        botaoModalAnaliseSalvar.classList.toggle('d-none', !modoEdicao);
        PrimeUI.openModal(modalAnalise);
    }

    document.addEventListener('click', function (e) {
        var botaoVer = e.target.closest('.botao-visualizar-analise');
        if (botaoVer) {
            abrirModalAnalise(botaoVer.closest('.analise-card'), false);
            return;
        }
        var botaoEditar = e.target.closest('.botao-editar-analise');
        if (botaoEditar) {
            abrirModalAnalise(botaoEditar.closest('.analise-card'), true);
        }
    });

    if (botaoModalAnaliseSalvar) {
        botaoModalAnaliseSalvar.addEventListener('click', function () {
            var formTemp = document.createElement('form');
            formTemp.method = 'post';
            formTemp.action = window.location.href;
            formTemp.innerHTML = '<?= csrf_field() ?>'
                + '<input type="hidden" name="acao" value="atualizar_analise_risco">'
                + '<input type="hidden" name="aba_retorno" value="analise-risco">'
                + '<input type="hidden" name="id" value="' + modalAnalise.dataset.id + '">'
                + '<input type="hidden" name="texto">';
            formTemp.querySelector('[name="texto"]').value = modalAnaliseTexto.value;
            document.body.appendChild(formTemp);
            formTemp.submit();
        });
    }

    // Exportar PDF -- direto do card (usa o texto completo salvo, ignora
    // qualquer edição não salva) ou de dentro do modal (usa o texto que
    // está na textarea NA HORA, incluindo edição ainda não salva).
    document.addEventListener('click', function (e) {
        var botaoCard = e.target.closest('.botao-exportar-pdf-analise');
        var label, texto;
        if (botaoCard) {
            var card = botaoCard.closest('.analise-card');
            label = card.dataset.label;
            texto = card.querySelector('.analise-texto-completo').value;
        } else if (e.target.closest('#botaoModalAnaliseExportarPdf')) {
            label = modalAnalise.dataset.label;
            texto = modalAnaliseTexto.value;
        } else {
            return;
        }

        var formTemp = document.createElement('form');
        formTemp.method = 'post';
        formTemp.action = window.location.href;
        formTemp.target = '_blank';
        formTemp.innerHTML = '<?= csrf_field() ?>'
            + '<input type="hidden" name="acao" value="exportar_pdf_analise_risco">'
            + '<input type="hidden" name="label">'
            + '<input type="hidden" name="texto">';
        formTemp.querySelector('[name="label"]').value = label;
        formTemp.querySelector('[name="texto"]').value = texto;
        document.body.appendChild(formTemp);
        formTemp.submit();
        formTemp.remove();
    });

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.form-excluir-analise');
        if (!form) return;
        if (!confirm('Excluir essa análise? Não tem como desfazer.')) {
            e.preventDefault();
        }
    });

    function renderizarResultadoSugestaoImplantacao(tipo, label, json) {
        var alvo = document.getElementById('resultadoSugestaoImplantacao' + tipo);
        if (!alvo) return;
        // Mesmos botões (Exportar PDF, Salvar alterações, Limpar) do
        // template PHP inicial -- precisa ficar em sincronia com aquele
        // bloco (ver <?= $tipoSugestao ?> na aba Implantação), senão eles
        // somem depois da primeira geração via AJAX (bug real: só o Exportar
        // PDF tinha sido replicado aqui, Limpar e Salvar sumiam da tela
        // assim que a sugestão terminava de gerar).
        alvo.innerHTML = '<p class="text-muted small mb-1">Gerado em ' + escaparHtmlCalendario(json.gerado_em) + '</p>'
            + '<textarea class="form-control form-control-sm textarea-sugestao-implantacao mb-2" rows="8">' + escaparHtmlCalendario(json.sugestao) + '</textarea>'
            + '<button type="button" class="btn btn-sm btn-outline-dark botao-exportar-pdf-implantacao" data-tipo="' + tipo + '" data-label="' + escaparHtmlCalendario(label) + '"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</button>'
            + '<button type="button" class="btn btn-sm btn-outline-primary botao-salvar-edicao-implantacao" data-tipo="' + tipo + '"><i class="bi bi-save"></i> Salvar alterações</button>'
            + '<form method="post" class="d-inline form-limpar-sugestao-implantacao" data-label="' + escaparHtmlCalendario(label) + '">'
                + '<?= csrf_field() ?>'
                + '<input type="hidden" name="acao" value="limpar_sugestao_implantacao">'
                + '<input type="hidden" name="aba_retorno" value="implantacao">'
                + '<input type="hidden" name="tipo" value="' + tipo + '">'
                + '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Limpar</button>'
            + '</form>';
    }

    // Manda UM documento por chamada ao Gemini (não mais todos juntos numa
    // única requisição) -- pedido do Kleber: assim que ele manda o próximo
    // documento, a IA "digere" e vai ACRESCENTANDO ao roteiro que já existe,
    // sem risco de timeout de proxy mesmo com vários documentos grandes.
    // Resolve com {sucesso, mensagemFinal} -- nunca rejeita (erro de UM
    // documento não aborta os outros da fila, só é reportado no final).
    function gerarUmaSecaoSugestaoImplantacao(form, documentoId, ordem, comentario, forcarSemEtapas) {
        var dados = new FormData(form);
        dados.set('ajax', '1');
        dados.set('documento_id', documentoId);
        dados.set('ordem', ordem);
        dados.set('comentario_ia', comentario);
        if (forcarSemEtapas) dados.set('forcar_sem_etapas', '1');

        return fetch(window.location.href, { method: 'POST', body: dados })
            .then(function (resp) {
                return resp.text().then(function (textoCru) {
                    try {
                        return JSON.parse(textoCru);
                    } catch (erroParse) {
                        // Resposta vazia/incompleta -- normalmente o servidor
                        // matou a requisição no meio. Bem mais raro agora
                        // que cada chamada processa só 1 documento, mas
                        // ainda dá pra acontecer num documento muito grande.
                        throw new Error('O servidor não terminou de responder pra esse documento (provavelmente demorou demais).');
                    }
                });
            });
    }

    // Fila sequencial: um documento por vez, esperando a resposta de um
    // antes de mandar o próximo -- é isso que evita o timeout de proxy (que
    // acontecia mandando todos juntos numa chamada só). Atualiza a tela a
    // cada resposta (não só no final), pra dar pra acompanhar o roteiro
    // sendo "alimentado" documento por documento.
    function gerarSugestoesImplantacaoSequencial(form, filaDocumentos, comentario, forcarSemEtapas) {
        var tipo = form.getAttribute('data-tipo');
        var label = form.closest('.card-body').querySelector('.fw-semibold').textContent.trim();
        var alvo = document.getElementById('resultadoSugestaoImplantacao' + tipo);
        var total = filaDocumentos.length;
        var falhas = [];
        var ultimoJson = null;

        function processarIndice(indice) {
            if (indice >= total) {
                escondercarregando();
                if (ultimoJson) {
                    renderizarResultadoSugestaoImplantacao(tipo, label, ultimoJson);
                    form.querySelector('button').innerHTML = '<i class="bi bi-stars"></i> Gerar de novo';
                }
                if (falhas.length && alvo) {
                    var avisoFalhas = document.createElement('div');
                    avisoFalhas.className = 'alert alert-danger mt-2';
                    avisoFalhas.textContent = 'Alguns documentos não puderam ser processados: ' + falhas.join('; ');
                    alvo.appendChild(avisoFalhas);
                }
                return;
            }

            var doc = filaDocumentos[indice];
            mostrarCarregando('Processando documento ' + (indice + 1) + ' de ' + total + ': ' + doc.nome + '...', true);

            gerarUmaSecaoSugestaoImplantacao(form, doc.id, indice, comentario, forcarSemEtapas)
                .then(function (json) {
                    if (json.sucesso) {
                        ultimoJson = json;
                        // Mostra o roteiro crescendo a cada documento, não só
                        // no final -- pedido explícito do Kleber.
                        renderizarResultadoSugestaoImplantacao(tipo, label, json);
                    } else if (json.precisa_confirmar_sem_etapas) {
                        escondercarregando();
                        if (confirm(json.mensagem + '\n\nGerar assim mesmo?')) {
                            gerarSugestoesImplantacaoSequencial(form, filaDocumentos, comentario, true);
                        }
                        return Promise.reject({ jaTratado: true });
                    } else {
                        falhas.push(doc.nome + ' (' + json.mensagem + ')');
                    }
                    processarIndice(indice + 1);
                })
                .catch(function (erro) {
                    if (erro && erro.jaTratado) return; // confirmação sem etapas já tratada acima
                    var mensagemErro = erro && erro.message ? erro.message : String(erro);
                    falhas.push(doc.nome + ' (' + mensagemErro + ')');
                    processarIndice(indice + 1);
                });
        }

        processarIndice(0);
    }

    // Os 7 botões "Gerar" (um por tipo de sugestão) todos abrem o MESMO
    // modal de seleção de documentos/comentário antes de mandar pra IA --
    // só guarda em formImplantacaoIaPendente qual form (tipo) foi clicado,
    // pra saber em qual continuar depois que a pessoa confirmar no modal.
    var formImplantacaoIaPendente = null;
    document.querySelectorAll('.form-gerar-sugestao-implantacao').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            formImplantacaoIaPendente = form;
            var campoComentario = document.getElementById('comentarioDocsImplantacaoIa');
            if (campoComentario) campoComentario.value = '';
            PrimeUI.openModal(document.getElementById('modalDocsSugestaoImplantacao'));
        });
    });

    var botaoConfirmarDocsImplantacaoIa = document.getElementById('botaoConfirmarGerarImplantacaoIa');
    if (botaoConfirmarDocsImplantacaoIa) {
        botaoConfirmarDocsImplantacaoIa.addEventListener('click', function () {
            if (!formImplantacaoIaPendente) return;

            var filaDocumentos = [];
            document.querySelectorAll('.checkbox-doc-implantacao-ia:checked').forEach(function (chk) {
                filaDocumentos.push({ id: chk.value, nome: chk.closest('.form-check').querySelector('label').textContent.trim() });
            });
            // Nenhum documento marcado (ou nenhum cadastrado ainda) -- ainda
            // assim processa UMA "seção geral", só com o contexto cadastrado
            // (setores/etapas/usuários), documento_id=0 no backend.
            if (!filaDocumentos.length) {
                filaDocumentos.push({ id: '0', nome: 'contexto geral' });
            }

            var comentario = document.getElementById('comentarioDocsImplantacaoIa').value;

            PrimeUI.closeModal(document.getElementById('modalDocsSugestaoImplantacao'));
            gerarSugestoesImplantacaoSequencial(formImplantacaoIaPendente, filaDocumentos, comentario, false);
        });
    }

    // Delegado no document porque o botão "Exportar PDF" também é criado
    // dinamicamente depois de gerar uma sugestão nova (innerHTML acima).
    document.addEventListener('click', function (e) {
        var botao = e.target.closest('.botao-exportar-pdf-implantacao');
        if (!botao) return;
        var tipo = botao.getAttribute('data-tipo');
        var label = botao.getAttribute('data-label');
        var textarea = document.querySelector('#resultadoSugestaoImplantacao' + tipo + ' .textarea-sugestao-implantacao');
        if (!textarea) return;

        var formTemp = document.createElement('form');
        formTemp.method = 'post';
        formTemp.action = window.location.href;
        formTemp.target = '_blank';
        formTemp.innerHTML = '<?= csrf_field() ?>'
            + '<input type="hidden" name="acao" value="exportar_pdf_sugestao_implantacao">'
            + '<input type="hidden" name="label">'
            + '<input type="hidden" name="texto">';
        formTemp.querySelector('[name="label"]').value = label;
        formTemp.querySelector('[name="texto"]').value = textarea.value;
        document.body.appendChild(formTemp);
        formTemp.submit();
        formTemp.remove();
    });

    // "Salvar alterações" -- persiste o texto da textarea (com qualquer
    // edição manual) como a versão mais atual, senão editar o texto e só
    // sair da página faz a edição se perder (a textarea nunca era salva por
    // conta própria). Mesmo truque de form temporário do Exportar PDF, só
    // que sem target="_blank" -- é pra voltar pra mesma tela com o flash de
    // sucesso, não abrir uma aba nova.
    document.addEventListener('click', function (e) {
        var botao = e.target.closest('.botao-salvar-edicao-implantacao');
        if (!botao) return;
        var tipo = botao.getAttribute('data-tipo');
        var textarea = document.querySelector('#resultadoSugestaoImplantacao' + tipo + ' .textarea-sugestao-implantacao');
        if (!textarea) return;

        var formTemp = document.createElement('form');
        formTemp.method = 'post';
        formTemp.action = window.location.href;
        formTemp.innerHTML = '<?= csrf_field() ?>'
            + '<input type="hidden" name="acao" value="salvar_edicao_sugestao_implantacao">'
            + '<input type="hidden" name="aba_retorno" value="implantacao">'
            + '<input type="hidden" name="tipo" value="' + tipo + '">'
            + '<input type="hidden" name="texto">';
        formTemp.querySelector('[name="texto"]').value = textarea.value;
        document.body.appendChild(formTemp);
        formTemp.submit();
    });

    // Confirmação do "Limpar" -- delegado no document porque o form também é
    // criado dinamicamente depois de gerar uma sugestão (ver
    // renderizarResultadoSugestaoImplantacao() acima).
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.form-limpar-sugestao-implantacao');
        if (!form) return;
        var label = form.getAttribute('data-label') || 'essa sugestão';
        if (!confirm('Apagar tudo que já foi gerado pra "' + label + '"? Não tem como desfazer -- vai precisar gerar de novo do zero.')) {
            e.preventDefault();
        }
    });

    document.querySelectorAll('.form-pesquisar-perfil-empresa').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            mostrarCarregando('Pesquisando perfil da empresa...', true);

            var dados = new FormData(form);
            dados.set('ajax', '1');

            fetch(window.location.href, { method: 'POST', body: dados })
                .then(function (resp) { return resp.json(); })
                .then(function (json) {
                    var alvo = document.getElementById('resultadoPerfilEmpresa');
                    if (!alvo) return;
                    if (json.sucesso) {
                        alvo.innerHTML = '<div class="card card-stat"><div class="card-body">'
                            + '<p class="text-muted small mb-2">Pesquisado em ' + escaparHtmlCalendario(json.gerado_em) + '</p>'
                            + '<div style="white-space: pre-wrap;">' + escaparHtmlCalendario(json.perfil) + '</div>'
                            + '</div></div>';
                        var botao = form.querySelector('button[type="submit"]');
                        if (botao) botao.innerHTML = '<i class="bi bi-stars"></i> Pesquisar de novo';
                    } else {
                        alvo.innerHTML = '<div class="alert alert-danger">' + escaparHtmlCalendario(json.mensagem) + '</div>';
                    }
                })
                .catch(function (erro) {
                    var alvo = document.getElementById('resultadoPerfilEmpresa');
                    if (alvo) alvo.innerHTML = '<div class="alert alert-danger">Falha de conexão ao pesquisar o perfil: ' + escaparHtmlCalendario(String(erro)) + '</div>';
                })
                .finally(function () {
                    escondercarregando();
                });
        });
    });

    // Avatar do contato (foto enviada OU avatar pronto) -- também via AJAX,
    // mesmo motivo do "pesquisar_perfil_empresa" acima: cada avatar pronto é
    // um <form> próprio que ANTES recarregava a página inteira ao clicar, o
    // que apagava qualquer campo já digitado (e ainda não salvo) em QUALQUER
    // outro formulário da aba -- bug real reportado pelo Kleber ("preenchi
    // os dados do contato, fui trocar a foto, ela recarregou e apagou tudo").
    document.querySelectorAll('.form-avatar-master').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var dados = new FormData(form);
            dados.set('ajax', '1');
            var botao = form.querySelector('button[type="submit"]');
            var textoBotaoOriginal = botao ? botao.innerHTML : '';
            if (botao) botao.disabled = true;

            fetch(window.location.href, { method: 'POST', body: dados })
                .then(function (resp) { return resp.json(); })
                .then(function (json) {
                    if (json.sucesso && json.avatar_html) {
                        var alvo = document.getElementById('avatarContatoMaster');
                        if (alvo) alvo.innerHTML = json.avatar_html;
                        form.reset();
                    } else {
                        alert(json.mensagem || 'Não foi possível atualizar o avatar.');
                    }
                })
                .catch(function (erro) {
                    alert('Falha de conexão ao atualizar o avatar: ' + String(erro));
                })
                .finally(function () {
                    if (botao) { botao.disabled = false; botao.innerHTML = textoBotaoOriginal; }
                });
        });
    });

    // escaparHtmlCalendario() e inicializarCalendarioMensal() vêm de
    // assets/js/calendario_visual.js (compartilhado com portal/calendario.php).

    function abrirNovoTreinamentoNaData(container, dataIso) {
        var campoData = document.getElementById('novoTreinamentoDataInicio');
        var campoSetor = document.getElementById('novoTreinamentoSetor');
        var campoRetorno = document.getElementById('novoTreinamentoAbaRetorno');
        if (campoData) campoData.value = dataIso;
        if (campoSetor) campoSetor.value = container.getAttribute('data-setor-id') || '';
        if (campoRetorno) campoRetorno.value = container.getAttribute('data-aba-retorno') || 'setores';
        PrimeUI.openModal(document.getElementById('modalNovoTreinamento'));
    }

    // Clique num evento existente do calendário -- os dados já vieram
    // prontos no data-eventos, então só preenche o modal de edição, sem
    // precisar buscar nada no servidor.
    function abrirEditarTreinamento(evento, abaRetorno) {
        document.getElementById('editarTreinamentoId').value = evento.id;
        document.getElementById('editarTreinamentoAbaRetorno').value = abaRetorno || 'setores';
        document.getElementById('editarTreinamentoTitulo').value = evento.titulo || '';
        document.getElementById('editarTreinamentoTipo').value = evento.tipo || 'treinamento';
        document.getElementById('editarTreinamentoSetor').value = evento.setor_id || '';
        document.getElementById('editarTreinamentoDescricao').value = evento.descricao || '';
        document.getElementById('editarTreinamentoDataInicio').value = evento.data_inicio || '';
        document.getElementById('editarTreinamentoHoraInicio').value = evento.hora_inicio ? evento.hora_inicio.substring(0, 5) : '';
        document.getElementById('editarTreinamentoDataFim').value = evento.data_fim || '';
        document.getElementById('editarTreinamentoHoraFim').value = evento.hora_fim ? evento.hora_fim.substring(0, 5) : '';
        document.getElementById('editarTreinamentoLink').value = evento.link_reuniao || '';
        document.getElementById('editarTreinamentoChamado').value = evento.chamado_id || '';
        document.getElementById('editarTreinamentoStatus').value = evento.status || 'planejado';
        document.getElementById('editarTreinamentoResponsavel').value = evento.responsavel_id || '';
        var destinatariosMarcados = evento.destinatarios || [];
        document.querySelectorAll('#modalEditarTreinamento input[name="destinatarios[]"]').forEach(function (chk) {
            chk.checked = destinatariosMarcados.indexOf(chk.value) !== -1;
        });
        document.getElementById('excluirTreinamentoId').value = evento.id;
        PrimeUI.openModal(document.getElementById('modalEditarTreinamento'));
    }

    document.querySelector('.botao-excluir-treinamento').addEventListener('click', function () {
        if (!confirm('Excluir este treinamento/reunião? Essa ação não pode ser desfeita.')) return;
        document.getElementById('formExcluirTreinamento').submit();
    });

    // Editar/excluir KPI -- mesma ideia do treinamento: os dados já vêm nos
    // data-* do botão (preenchidos pelo PHP), sem precisar buscar no
    // servidor pra abrir o modal.
    document.querySelectorAll('.botao-editar-kpi').forEach(function (botao) {
        botao.addEventListener('click', function () {
            document.getElementById('editarKpiId').value = botao.getAttribute('data-id');
            document.getElementById('editarKpiAbaRetorno').value = botao.getAttribute('data-aba-retorno') || 'kpis';
            document.getElementById('editarKpiNome').value = botao.getAttribute('data-nome') || '';
            document.getElementById('editarKpiValor').value = botao.getAttribute('data-valor') || '';
            document.getElementById('editarKpiMeta').value = botao.getAttribute('data-meta') || '';
            document.getElementById('editarKpiUnidade').value = botao.getAttribute('data-unidade') || '';
            document.getElementById('editarKpiDirecao').value = botao.getAttribute('data-direcao') || 'maior';
            document.getElementById('editarKpiPeriodo').value = botao.getAttribute('data-periodo') || '';
            document.getElementById('editarKpiSetor').value = botao.getAttribute('data-setor-id') || '';
            document.getElementById('editarKpiObservacoes').value = botao.getAttribute('data-observacoes') || '';
            document.getElementById('excluirKpiId').value = botao.getAttribute('data-id');
            document.getElementById('excluirKpiAbaRetorno').value = botao.getAttribute('data-aba-retorno') || 'kpis';
            PrimeUI.openModal(document.getElementById('modalEditarKpi'));
        });
    });

    document.querySelector('.botao-excluir-kpi').addEventListener('click', function () {
        if (!confirm('Excluir esse KPI? Essa ação não pode ser desfeita.')) return;
        document.getElementById('formExcluirKpi').submit();
    });

    document.querySelectorAll('.calendario-mensal').forEach(function (container) {
        inicializarCalendarioMensal(container, {
            aoAbrirNovo: abrirNovoTreinamentoNaData,
            aoAbrirEvento: abrirEditarTreinamento,
        });
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
