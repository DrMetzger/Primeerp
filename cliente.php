<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\AiSugestoes;
use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Notificacoes;
use AdminPrime\PdfExport;
use AdminPrime\TempAccess;

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
        $stmt = $pdo->prepare('UPDATE clientes SET nome=?, cnpj=?, status=?, contato_nome=?, contato_email=?, contato_telefone=?, contato_documento=?, responsavel_interno_id=?, observacoes=?, acesso_fora_horario=?, portal_permite_gerenciar_equipe=? WHERE id=?');
        $stmt->execute([
            trim($_POST['nome']),
            apenas_digitos($_POST['cnpj'] ?? ''),
            $_POST['status'],
            trim($_POST['contato_nome']) ?: null,
            trim($_POST['contato_email']) ?: null,
            apenas_digitos($_POST['contato_telefone'] ?? ''),
            normalizar_documento($_POST['contato_documento'] ?? ''),
            $_POST['responsavel_interno_id'] ?: null,
            trim($_POST['observacoes']) ?: null,
            isset($_POST['acesso_fora_horario']) ? 1 : 0,
            isset($_POST['portal_permite_gerenciar_equipe']) ? 1 : 0,
            $clienteId,
        ]);

        $erroLogo = processar_upload_logo_cliente($pdo, $clienteId);
        if ($erroLogo) {
            flash_set('erro', $erroLogo);
        }

        flash_set('sucesso', 'Dados do cliente atualizados.');
    }

    if ($acao === 'criar_kpi') {
        $stmt = $pdo->prepare('INSERT INTO kpis (cliente_id, setor_id, nome_kpi, valor, meta, unidade, periodo, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            $_POST['setor_id'] ?: null,
            trim($_POST['nome_kpi']),
            str_replace(',', '.', $_POST['valor']),
            $_POST['meta'] !== '' ? str_replace(',', '.', $_POST['meta']) : null,
            trim($_POST['unidade']) ?: null,
            $_POST['periodo'] . '-01',
            trim($_POST['observacoes']) ?: null,
        ]);
        flash_set('sucesso', 'KPI registrado.');
    }

    if ($acao === 'editar_kpi') {
        $stmt = $pdo->prepare('UPDATE kpis SET setor_id = ?, nome_kpi = ?, valor = ?, meta = ?, unidade = ?, periodo = ?, observacoes = ? WHERE id = ? AND cliente_id = ?');
        $stmt->execute([
            $_POST['setor_id'] ?: null,
            trim($_POST['nome_kpi']),
            str_replace(',', '.', $_POST['valor']),
            $_POST['meta'] !== '' ? str_replace(',', '.', $_POST['meta']) : null,
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
        // setor_id vazio = KPI GERAL do cliente (chamados sem setor
        // vinculado) -- precisa virar NULL de verdade, não 0: "0" não bate
        // com nenhum chamado (nem os com setor de verdade, nem os sem
        // setor, que ficam com setor_id IS NULL no banco).
        $setorIdCalculo = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
        $condicaoSetor = $setorIdCalculo !== null ? 'setor_id = ?' : 'setor_id IS NULL';
        $paramsSetor = $setorIdCalculo !== null ? [$setorIdCalculo] : [];

        $periodoCalculo = $_POST['periodo'] . '-01';
        $inicioPeriodo = $periodoCalculo . ' 00:00:00';
        $fimPeriodo = date('Y-m-t 23:59:59', strtotime($periodoCalculo));

        $stmtCalculo = $pdo->prepare("
            SELECT
                COUNT(*) AS total_abertos,
                SUM(CASE WHEN prioridade IN ('alta', 'urgente') THEN 1 ELSE 0 END) AS total_urgentes,
                (SELECT COUNT(*) FROM chamados WHERE {$condicaoSetor} AND cliente_id = ? AND fechado_em BETWEEN ? AND ?) AS total_resolvidos,
                (SELECT AVG(TIMESTAMPDIFF(MINUTE, criado_em, fechado_em)) FROM chamados WHERE {$condicaoSetor} AND cliente_id = ? AND fechado_em BETWEEN ? AND ?) AS tempo_medio_min
            FROM chamados
            WHERE {$condicaoSetor} AND cliente_id = ? AND criado_em BETWEEN ? AND ?
        ");
        $stmtCalculo->execute([
            ...$paramsSetor, $clienteId, $inicioPeriodo, $fimPeriodo,
            ...$paramsSetor, $clienteId, $inicioPeriodo, $fimPeriodo,
            ...$paramsSetor, $clienteId, $inicioPeriodo, $fimPeriodo,
        ]);
        $resultadoCalculo = $stmtCalculo->fetch();

        $kpisAutomaticos = [
            ['Chamados abertos', (int) $resultadoCalculo['total_abertos'], 'chamados'],
            ['Chamados urgentes/alta prioridade', (int) $resultadoCalculo['total_urgentes'], 'chamados'],
            ['Chamados resolvidos', (int) $resultadoCalculo['total_resolvidos'], 'chamados'],
            ['Tempo médio de atendimento', $resultadoCalculo['tempo_medio_min'] !== null ? round((float) $resultadoCalculo['tempo_medio_min'], 2) : 0, 'min'],
        ];

        foreach ($kpisAutomaticos as [$nomeKpiAuto, $valorKpiAuto, $unidadeKpiAuto]) {
            $stmtMetaAnterior = $pdo->prepare("SELECT meta FROM kpis WHERE {$condicaoSetor} AND nome_kpi = ? AND meta IS NOT NULL ORDER BY periodo DESC LIMIT 1");
            $stmtMetaAnterior->execute([...$paramsSetor, $nomeKpiAuto]);
            $metaAnteriorKpi = $stmtMetaAnterior->fetchColumn();
            $metaAnteriorKpi = $metaAnteriorKpi !== false ? $metaAnteriorKpi : null;

            $stmtKpiExistente = $pdo->prepare("SELECT id FROM kpis WHERE {$condicaoSetor} AND nome_kpi = ? AND periodo = ?");
            $stmtKpiExistente->execute([...$paramsSetor, $nomeKpiAuto, $periodoCalculo]);
            $idKpiExistente = $stmtKpiExistente->fetchColumn();

            if ($idKpiExistente) {
                $pdo->prepare('UPDATE kpis SET valor = ?, unidade = ?, meta = COALESCE(meta, ?), observacoes = ? WHERE id = ?')
                    ->execute([$valorKpiAuto, $unidadeKpiAuto, $metaAnteriorKpi, 'Calculado automaticamente a partir dos chamados.', $idKpiExistente]);
            } else {
                $pdo->prepare('INSERT INTO kpis (cliente_id, setor_id, nome_kpi, valor, meta, unidade, periodo, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$clienteId, $setorIdCalculo, $nomeKpiAuto, $valorKpiAuto, $metaAnteriorKpi, $unidadeKpiAuto, $periodoCalculo, 'Calculado automaticamente a partir dos chamados.']);
            }
        }

        flash_set('sucesso', '4 KPIs calculados automaticamente a partir dos chamados.');
    }

    if ($acao === 'criar_treinamento') {
        $setorIdTreinamentoNovo = $_POST['setor_id'] ?: null;
        $tipoTreinamentoNovo = $_POST['tipo'] === 'reuniao' ? 'reuniao' : 'treinamento';
        $tituloTreinamentoNovo = trim($_POST['titulo']);

        $stmt = $pdo->prepare('INSERT INTO treinamentos (cliente_id, setor_id, titulo, tipo, descricao, status, data_inicio, hora_inicio, data_fim, hora_fim, link_reuniao, responsavel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
        Notificacoes::novoTreinamento($pdo, [
            'id' => $treinamentoIdNovo,
            'cliente_id' => $clienteId,
            'titulo' => $tituloTreinamentoNovo,
            'tipo' => $tipoTreinamentoNovo,
            'data_inicio' => $_POST['data_inicio'] ?: null,
            'hora_inicio' => $_POST['hora_inicio'] ?: null,
            'link_reuniao' => trim($_POST['link_reuniao'] ?? '') ?: null,
        ], $cliente['nome'], $nomeSetorTreinamentoNovo);

        flash_set('sucesso', $tipoTreinamentoNovo === 'reuniao' ? 'Reunião registrada.' : 'Treinamento registrado.');
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
        $stmt = $pdo->prepare('
            UPDATE treinamentos SET
                setor_id = ?, titulo = ?, tipo = ?, descricao = ?, status = ?,
                data_inicio = ?, hora_inicio = ?, data_fim = ?, hora_fim = ?,
                link_reuniao = ?, responsavel_id = ?, lembrete_enviado_em = NULL
            WHERE id = ? AND cliente_id = ?
        ');
        $stmt->execute([
            $_POST['setor_id'] ?: null,
            trim($_POST['titulo']),
            $_POST['tipo'] === 'reuniao' ? 'reuniao' : 'treinamento',
            trim($_POST['descricao']) ?: null,
            $_POST['status'] ?: 'planejado',
            $_POST['data_inicio'] ?: null,
            $_POST['hora_inicio'] ?: null,
            $_POST['data_fim'] ?: null,
            $_POST['hora_fim'] ?: null,
            trim($_POST['link_reuniao'] ?? '') ?: null,
            $_POST['responsavel_id'] ?: null,
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

    if ($acao === 'upload_documento_implantacao' && isset($_FILES['arquivo'])) {
        $erroUploadImplantacao = null;
        $etapaIdAnexo = $_POST['etapa_id'] !== '' ? (int) $_POST['etapa_id'] : null;
        $idDocImplantacao = salvar_documento_upload(
            $pdo,
            $clienteId,
            null,
            trim($_POST['titulo'] ?? ''),
            null,
            'Implantação',
            $_FILES['arquivo'],
            Auth::userId(),
            $erroUploadImplantacao
        );
        if ($idDocImplantacao) {
            $pdo->prepare('UPDATE documentos SET implantacao_etapa_id = ? WHERE id = ?')->execute([$etapaIdAnexo, $idDocImplantacao]);
        }
        flash_set($idDocImplantacao ? 'sucesso' : 'erro', $idDocImplantacao ? 'Anexo enviado.' : $erroUploadImplantacao);
    }

    // IA: várias sugestões diferentes pro processo de implantação (não só
    // uma) -- cada tipo gera e guarda separado, pra dar pra ter todas
    // geradas ao mesmo tempo sem uma sobrescrever a outra.
    if ($acao === 'gerar_sugestao_implantacao') {
        $ehAjaxImplantacaoIa = ($_POST['ajax'] ?? '') === '1';
        $tipoSugestaoImplantacao = $_POST['tipo'] ?? '';
        $forcarSemEtapasImplantacaoIa = ($_POST['forcar_sem_etapas'] ?? '') === '1';

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

                // Documentos anexados na aba Implantação (gerais + de cada
                // etapa) -- manda o CONTEÚDO real pro Gemini (não só nome/
                // contagem), pra sugestão ser específica desse cliente.
                $stmtDocsImplantacaoIa = $pdo->prepare("SELECT titulo, arquivo_nome_original, arquivo_tamanho, dados FROM documentos WHERE cliente_id = ? AND categoria = 'Implantação' ORDER BY criado_em ASC");
                $stmtDocsImplantacaoIa->execute([$clienteId]);
                $docsImplantacaoIa = $stmtDocsImplantacaoIa->fetchAll();

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
                $nomesIgnoradosImplantacaoIa = [];
                $bytesAcumuladosImplantacaoIa = 0;
                $limiteTotalImplantacaoIa = 18 * 1024 * 1024; // margem de segurança abaixo do limite do Gemini pra requisição inline
                foreach ($docsImplantacaoIa as $docIa) {
                    $extensaoDocIa = strtolower(pathinfo($docIa['arquivo_nome_original'], PATHINFO_EXTENSION));
                    $mimeDocIa = $mimesAceitosImplantacaoIa[$extensaoDocIa] ?? null;
                    if (!$mimeDocIa || count($arquivosImplantacaoIa) >= 8 || $bytesAcumuladosImplantacaoIa + (int) $docIa['arquivo_tamanho'] > $limiteTotalImplantacaoIa) {
                        $nomesIgnoradosImplantacaoIa[] = $docIa['arquivo_nome_original'];
                        continue;
                    }
                    $arquivosImplantacaoIa[] = ['mime' => $mimeDocIa, 'dados' => $docIa['dados']];
                    $bytesAcumuladosImplantacaoIa += (int) $docIa['arquivo_tamanho'];
                }

                $promptImplantacao = "Você é um consultor de implantação de ERP (TOTVS Protheus) de uma consultoria (PrimeERP). "
                    . "Gere {$tiposSugestaoImplantacao[$tipoSugestaoImplantacao]} pro cliente \"{$cliente['nome']}\". "
                    . "Responda em português, de forma prática e organizada em tópicos ou lista numerada, pronto pra ser usado de verdade (evite generalidades).\n\n"
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

                if ($arquivosImplantacaoIa) {
                    $promptImplantacao .= "\nVocê tem acesso ao conteúdo de " . count($arquivosImplantacaoIa) . ' documento(s) anexados a essa implantação (anexados nessa mesma mensagem, abaixo do texto) -- USE o conteúdo real deles pra tornar a sugestão específica desse cliente, não genérica.' . "\n";
                }
                if ($nomesIgnoradosImplantacaoIa) {
                    $promptImplantacao .= '- Não foi possível ler o conteúdo de: ' . implode(', ', $nomesIgnoradosImplantacaoIa) . " (formato não suportado ou lote de anexos grande demais) -- considere só o nome desses arquivos.\n";
                }

                $promptImplantacao .= "\nSe faltar alguma informação importante pra dar uma resposta específica (em vez de genérica), ou se você tiver alguma dúvida real sobre esse cliente, liste isso primeiro, num bloco curto no topo da resposta com o título \"❓ Pontos a confirmar antes de aplicar isso:\" -- só inclua esse bloco se for realmente necessário, não invente dúvida à toa.";

                $resultadoImplantacaoIa = AiSugestoes::gerar($promptImplantacao, [], null, $arquivosImplantacaoIa);
                if (!$resultadoImplantacaoIa['ok']) {
                    $respostaImplantacaoIa = ['sucesso' => false, 'mensagem' => 'Não foi possível gerar a sugestão: ' . $resultadoImplantacaoIa['erro'], 'sugestao' => null, 'gerado_em' => null];
                } else {
                    $pdo->prepare('INSERT INTO implantacao_sugestoes_ia (cliente_id, tipo, sugestao, criado_por) VALUES (?, ?, ?, ?)')
                        ->execute([$clienteId, $tipoSugestaoImplantacao, $resultadoImplantacaoIa['texto'], Auth::userId()]);
                    $respostaImplantacaoIa = [
                        'sucesso' => true,
                        'mensagem' => 'Sugestão gerada.',
                        'sugestao' => $resultadoImplantacaoIa['texto'],
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
        $pdfSugestao->escreverTexto($textoSugestaoPdf);

        $nomeArquivoPdf = 'implantacao-' . preg_replace('/[^a-z0-9]+/i', '-', $labelSugestaoPdf) . '-' . $clienteId . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivoPdf . '"');
        echo $pdfSugestao->bytes();
        exit;
    }

    if ($acao === 'enviar_convite_portal') {
        if (!$cliente['contato_email']) {
            flash_set('erro', 'Cadastre um e-mail de contato para o cliente antes de enviar o convite.');
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO cliente_senha_tokens (cliente_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$clienteId, $token]);
            Notificacoes::conviteAcessoPortal($cliente, $token);
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
            Notificacoes::conviteAcessoPortal($cliente, $token);
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
    // portal só pra isso).
    if ($acao === 'atualizar_avatar_master') {
        $erroFotoMaster = processar_upload_foto_cliente($pdo, $clienteId);
        flash_set($erroFotoMaster ? 'erro' : 'sucesso', $erroFotoMaster ?? 'Foto atualizada.');
    }

    if ($acao === 'escolher_avatar_predefinido_master') {
        $indiceAvatarMaster = (int) ($_POST['avatar'] ?? 0);
        if (definir_avatar_predefinido_cliente($pdo, $clienteId, $indiceAvatarMaster)) {
            flash_set('sucesso', 'Avatar atualizado.');
        }
    }

    if ($acao === 'criar_chamado_equipe') {
        $categoriaNovoChamado = trim($_POST['categoria'] ?? '');
        if (trim($_POST['titulo'] ?? '') === '' || trim($_POST['descricao'] ?? '') === '' || $categoriaNovoChamado === '') {
            flash_set('erro', 'Preencha título, categoria e descrição do chamado.');
        } else {
            // Título padronizado em MAIÚSCULAS (mesma regra do chamado aberto
            // pelo cliente no portal) -- pedido do Kleber pra não ter títulos
            // com capitalização diferente misturados na listagem.
            $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, responsavel_id, setor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $clienteId,
                mb_strtoupper(trim($_POST['titulo']), 'UTF-8'),
                trim($_POST['descricao']),
                $categoriaNovoChamado,
                $_POST['prioridade'] ?? 'media',
                'equipe',
                $_POST['responsavel_id'] ?: null,
                $_POST['setor_id'] ?: null,
            ]);
            flash_set('sucesso', 'Chamado registrado.');
        }
    }

    // --- Setores (departamentos independentes do cliente) ---------------
    // Ver src/Migrations.php (tabelas setores/setor_responsaveis) e
    // ClienteAuth (login separado do master pra cada setor).

    if ($acao === 'criar_setor') {
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '') {
            flash_set('erro', 'Informe o nome do setor.');
        } else {
            $pdo->prepare('INSERT INTO setores (cliente_id, nome, icone) VALUES (?, ?, ?)')
                ->execute([$clienteId, $nomeSetor, $_POST['icone'] ?: null]);
            flash_set('sucesso', 'Setor "' . $nomeSetor . '" criado.');
        }
    }

    if ($acao === 'atualizar_setor') {
        $setorId = (int) $_POST['setor_id'];
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '') {
            flash_set('erro', 'Informe o nome do setor.');
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
            $pdo->prepare('UPDATE setor_responsaveis SET setor_id = NULL WHERE setor_id = ?')->execute([$setorId]);
            if ((int) $cliente['setor_id'] === $setorId) {
                $pdo->prepare('UPDATE clientes SET setor_id = NULL WHERE id = ?')->execute([$clienteId]);
            }
            $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);
            flash_set('sucesso', 'Setor excluído. Os chamados e responsáveis dele continuam salvos, só sem setor vinculado.');
        }
    }

    // O master pertence a UM setor só (normalmente TI, que costuma tocar os
    // chamados) -- ele já vê/gerencia todos os setores independente disso,
    // então não precisa "participar" de vários como antes.
    if ($acao === 'definir_setor_master') {
        $novoSetorMasterId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
        $pdo->prepare('UPDATE clientes SET setor_id = ? WHERE id = ?')->execute([$novoSetorMasterId, $clienteId]);
        flash_set('sucesso', 'Setor do master atualizado.');
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
            $setorIdResp = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
            $indiceAvatarNovo = (int) ($_POST['avatar'] ?? 0);
            $avatarNovo = ($indiceAvatarNovo >= 1 && $indiceAvatarNovo <= QUANTIDADE_AVATARES_PREDEFINIDOS)
                ? 'assets/img/avatares/avatar-' . $indiceAvatarNovo . '.svg'
                : null;

            try {
                $pdo->prepare('INSERT INTO setor_responsaveis (cliente_id, setor_id, nome, email, telefone, documento, foto_perfil) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$clienteId, $setorIdResp, $nomeResp, $emailResp, $telefoneResp, $documentoResp, $avatarNovo]);
                $responsavelId = (int) $pdo->lastInsertId();

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
            $setorIdResp = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
            try {
                $pdo->prepare('UPDATE setor_responsaveis SET nome=?, email=?, telefone=?, documento=?, setor_id=? WHERE id=?')
                    ->execute([$nomeResp, $emailResp, $telefoneResp, $documentoResp, $setorIdResp, $responsavelId]);
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

    // Vincula (ou desvincula, se setor_id vier vazio) um usuário JÁ
    // cadastrado a um setor específico -- é isso que "Gerenciar setores"
    // usa pra buscar/relacionar em vez de cadastrar uma pessoa nova.
    if ($acao === 'relacionar_responsavel_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $novoSetorId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            $pdo->prepare('UPDATE setor_responsaveis SET setor_id = ? WHERE id = ?')->execute([$novoSetorId, $responsavelId]);
            flash_set('sucesso', $novoSetorId ? 'Usuário relacionado ao setor.' : 'Usuário desvinculado do setor.');
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
            Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token);
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
            Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token);
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

        if ($mensagem !== '' || $temAnexo) {
            $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_usuario_id, mensagem) VALUES (?, "equipe", ?, ?, ?)')
                ->execute([$chamadoId, Auth::userName(), Auth::userId(), $mensagem !== '' ? $mensagem : '(anexo enviado)']);
            $respostaId = (int) $pdo->lastInsertId();

            $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, $respostaId, 'equipe');
            recalcular_status_chamado($pdo, $chamadoId);

            $chamadoAtualizado = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
            $chamadoAtualizado->execute([$chamadoId]);
            Notificacoes::novaRespostaEquipe($chamadoAtualizado->fetch(), $cliente);

            flash_set('sucesso', $errosAnexo ? 'Resposta enviada, mas: ' . implode(' ', $errosAnexo) : 'Resposta enviada ao cliente.');
        }
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
                    Notificacoes::chamadoAtribuido(['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo'], 'cliente_id' => $clienteId], $novoResponsavel);
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

            $stmtMembrosAlvo = $pdo->prepare('SELECT * FROM setor_responsaveis WHERE setor_id = ?');
            $stmtMembrosAlvo->execute([$novoSetorId]);
            $membrosSetorAlvo = $stmtMembrosAlvo->fetchAll();

            $masterNesseSetor = (int) $cliente['setor_id'] === $novoSetorId;

            if (!$setorAlvo || (!$membrosSetorAlvo && !$masterNesseSetor)) {
                flash_set('erro', 'Esse setor ainda não tem nenhum responsável cadastrado -- relacione um usuário em "Gerenciar setores" antes de atribuir chamados a ele.');
            } else {
                $pdo->prepare('UPDATE chamados SET setor_id = ? WHERE id = ?')->execute([$novoSetorId, $chamadoId]);

                // Só avisa por e-mail se realmente MUDOU de setor (não
                // dispara de novo se salvar o mesmo setor) -- avisa TODOS os
                // responsáveis desse setor, não só um (pode ter vários).
                if ($novoSetorId !== (int) $chamadoAlvo['setor_id']) {
                    foreach ($membrosSetorAlvo as $membro) {
                        Notificacoes::chamadoAtribuidoSetor(['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo']], $membro, $setorAlvo['nome']);
                    }
                    if ($masterNesseSetor) {
                        Notificacoes::chamadoAtribuidoSetor(
                            ['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo']],
                            ['nome' => $cliente['contato_nome'] ?: $cliente['nome'], 'email' => $cliente['contato_email'], 'telefone' => $cliente['contato_telefone']],
                            $setorAlvo['nome']
                        );
                    }
                }

                flash_set('sucesso', 'Chamado vinculado ao setor ' . $setorAlvo['nome'] . '.');
            }
        }
    }

    header('Location: ' . url('cliente.php?id=' . $clienteId . '#' . ($_POST['aba_retorno'] ?? 'visao-geral')));
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
$documentosGerais = [];
$contratoSocial = null;
$contratoPrestacaoServicos = null;
foreach ($documentos as $doc) {
    if ($doc['setor_id']) {
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

$etapasImplantacao = $pdo->prepare('SELECT * FROM implantacao_etapas WHERE cliente_id = ? ORDER BY ordem ASC, id ASC');
$etapasImplantacao->execute([$clienteId]);
$etapasImplantacao = $etapasImplantacao->fetchAll();

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

// Qual o ÚNICO setor do contato master (substitui o antigo master_participa,
// que permitia "participar" de vários ao mesmo tempo) -- editado na aba
// Usuários, ver ação 'definir_setor_master'.
$idSetorMaster = $cliente['setor_id'] ? (int) $cliente['setor_id'] : null;

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
    $stmtResponsaveis = $pdo->prepare("SELECT * FROM setor_responsaveis WHERE setor_id IN ($placeholdersSetores) ORDER BY nome ASC");
    $stmtResponsaveis->execute($idsSetores);
    foreach ($stmtResponsaveis->fetchAll() as $sr) {
        $responsaveisPorSetorId[$sr['setor_id']][] = $sr;
    }
    foreach ($setores as $s) {
        if ($idSetorMaster === (int) $s['id']) {
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
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#visao-geral">Visão geral</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#implantacao"><i class="bi bi-rocket-takeoff"></i> Implantação <span class="badge bg-secondary"><?= count($etapasImplantacao) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#chamados">Chamados <span class="badge bg-secondary"><?= count($chamados) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#kpis">KPIs</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#setores">Setores <span class="badge bg-secondary"><?= count($setores) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#usuarios">Usuários <span class="badge bg-secondary"><?= count($usuariosCliente) + 1 ?></span></a></li>
</ul>

<div class="tab-content">

    <!-- VISÃO GERAL -->
    <div class="tab-pane" id="visao-geral">
        <div class="card card-stat">
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
                        <div class="col-md-4">
                            <label class="form-label">CNPJ</label>
                            <input type="text" name="cnpj" class="form-control input-cnpj" value="<?= e(formatar_cnpj($cliente['cnpj'])) ?>" placeholder="00.000.000/0000-00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - nome</label>
                            <input type="text" name="contato_nome" class="form-control" value="<?= e($cliente['contato_nome']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - e-mail</label>
                            <input type="email" name="contato_email" class="form-control" value="<?= e($cliente['contato_email']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - telefone</label>
                            <input type="text" name="contato_telefone" class="form-control input-telefone" value="<?= e(formatar_telefone($cliente['contato_telefone'])) ?>" placeholder="(00) 00000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CPF ou RG do contato *</label>
                            <input type="text" name="contato_documento" class="form-control" required value="<?= e($cliente['contato_documento']) ?>" placeholder="Só números (RG pode terminar em X)">
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
                                <input type="checkbox" name="acesso_fora_horario" id="acessoForaHorario" class="form-check-input" value="1" <?= $cliente['acesso_fora_horario'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="acessoForaHorario">
                                    Liberar acesso ao portal fora do horário comercial (<?= sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_INICIO) ?>–<?= sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_FIM) ?>) pra esse cliente
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="portal_permite_gerenciar_equipe" id="portalPermiteGerenciarEquipe" class="form-check-input" value="1" <?= $cliente['portal_permite_gerenciar_equipe'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="portalPermiteGerenciarEquipe">
                                    Deixar o master cadastrar setor/usuário sozinho no portal (aba "Setores e Usuários") -- só CRIAR e ALTERAR, exclusão continua só aqui. Desligado por padrão.
                                </label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3">Salvar alterações</button>
                </form>
            </div>
        </div>

        <div class="card card-stat mt-3">
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
                    <p class="text-muted small">Busca por IA ainda não configurada (ADMINPRIME_GEMINI_API_KEY_BUSCA) -- precisa de uma chave/projeto novo, com faturamento ativado no Google Cloud pra liberar a ferramenta de busca.</p>
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
                <strong class="d-block mb-3">Foto do contato (avatar)</strong>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <?= avatar_html($cliente['contato_nome'] ?: $cliente['nome'], resolver_avatar_por_email($pdo, $cliente['contato_email'], $cliente['foto_perfil']), 'cliente') ?>
                    <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="atualizar_avatar_master">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <input type="file" name="foto" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
                        <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Enviar foto</button>
                    </form>
                </div>
                <p class="text-muted small mb-1">Ou escolha um avatar pronto:</p>
                <div class="galeria-avatares">
                    <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                        <form method="post" class="d-inline">
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
                        <button type="submit" class="btn btn-sm btn-outline-dark flex-grow-1"><i class="bi bi-check2"></i> Salvar</button>
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
                        <input type="file" name="arquivo" class="form-control form-control-sm" required style="max-width:220px;">
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
                    <input type="file" name="arquivo" class="form-control form-control-sm" required style="max-width:260px;">
                    <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Anexar</button>
                </form>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-header bg-white fw-bold"><i class="bi bi-stars"></i> Sugestões de IA pra essa implantação</div>
            <div class="card-body">
                <p class="text-muted small">
                    <i class="bi bi-info-circle"></i> A IA usa os setores, a quantidade de usuários, as etapas e o CONTEÚDO dos anexos dessa implantação pra gerar cada sugestão. O texto gerado é editável -- ajuste como quiser antes de exportar em PDF.
                    <?php if (!AiSugestoes::configurada()): ?><span class="d-block mt-1">IA ainda não configurada (ADMINPRIME_GEMINI_API_KEY).</span><?php endif; ?>
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
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
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
        <div class="card chamado-card mb-3" data-status="<?= e($c['status']) ?>" data-prioridade="<?= e($c['prioridade']) ?>" data-setor="<?= $c['setor_id'] ? (int) $c['setor_id'] : 'sem-setor' ?>">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <strong class="d-block mb-1"><?= e($c['titulo']) ?></strong>
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <?php if ($setores): ?>
                            <span class="d-flex align-items-center gap-1">
                                <span class="badge-rotulo">Setor</span>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="atribuir_setor_chamado">
                                    <input type="hidden" name="aba_retorno" value="chamados">
                                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                                    <select name="setor_id" class="form-select form-select-sm select-responsavel-chamado" onchange="this.form.submit()" title="Trocar o setor avisa o responsável dele por e-mail -- setor sem responsável cadastrado fica bloqueado aqui">
                                        <option value="" <?= !$c['setor_id'] ? 'selected' : '' ?>>Sem setor</option>
                                        <?php foreach ($setores as $s): $temResponsavel = $idSetorMaster === (int) $s['id'] || !empty($responsaveisPorSetorId[$s['id']]); ?>
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
                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                    <textarea name="mensagem" class="form-control form-control-sm mb-2" rows="2" placeholder="Responder ao cliente..."></textarea>
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <input type="file" name="anexos[]" class="form-control form-control-sm" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" style="max-width: 320px;">
                        <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-send"></i> Enviar resposta</button>
                    </div>
                    <p class="anexos-upload-preview mb-0">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada (imagem ou PDF).</p>
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
        <p class="text-muted small mb-1"><i class="bi bi-graph-up"></i> KPI (métrica) é qualquer número que ajuda a acompanhar a saúde do atendimento -- ex.: quantos chamados abriram, quantos foram resolvidos, tempo médio de resposta, % dentro do prazo (SLA). Serve pra comparar mês a mês e enxergar se o processo está piorando ou melhorando.</p>
        <p class="text-muted small mb-2">Essa aba aqui é só o <strong>geral do cliente</strong> (chamados sem setor vinculado) -- se esse cliente tem setores (aba Setores), a métrica de cada setor fica dentro dele mesmo, não aqui. <?= $setores ? 'Como esse cliente tem setor cadastrado, o normal é a maioria (ou todos) dos KPIs aparecerem lá, não aqui.' : '' ?></p>
        <div class="toolbar d-flex flex-wrap gap-2 align-items-end">
            <button type="button" class="btn btn-dark btn-sm botao-novo-kpi-geral"><i class="bi bi-plus-lg"></i> Registrar KPI</button>
            <form method="post" class="d-flex gap-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="calcular_kpis_automaticos">
                <input type="hidden" name="aba_retorno" value="kpis">
                <input type="hidden" name="setor_id" value="">
                <div>
                    <label class="form-label small mb-1">Calcular a partir dos chamados sem setor</label>
                    <input type="month" name="periodo" class="form-control form-control-sm" value="<?= date('Y-m') ?>" required>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-magic"></i> Calcular automaticamente</button>
            </form>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Período</th><th>KPI</th><th>Valor</th><th>Meta</th><th>Observações</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kpisGerais as $k): ?>
                        <tr>
                            <td><?= date('m/Y', strtotime($k['periodo'])) ?></td>
                            <td class="fw-semibold"><?= e($k['nome_kpi']) ?></td>
                            <td><?= e(number_format((float)$k['valor'], 2, ',', '.')) ?> <?= e($k['unidade']) ?></td>
                            <td>
                                <?php if ($k['meta'] !== null): ?>
                                    <?= e(number_format((float)$k['meta'], 2, ',', '.')) ?>
                                    <?= (float)$k['valor'] >= (float)$k['meta'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle-fill text-warning"></i>' ?>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= e($k['observacoes']) ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-dark botao-editar-kpi"
                                    data-id="<?= $k['id'] ?>" data-nome="<?= e($k['nome_kpi']) ?>" data-valor="<?= e($k['valor']) ?>"
                                    data-meta="<?= e($k['meta'] ?? '') ?>" data-unidade="<?= e($k['unidade']) ?>"
                                    data-periodo="<?= date('Y-m', strtotime($k['periodo'])) ?>" data-setor-id="<?= $k['setor_id'] ?? '' ?>"
                                    data-observacoes="<?= e($k['observacoes']) ?>" data-aba-retorno="kpis"
                                ><i class="bi bi-pencil"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($kpisGerais)): ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">Nenhum KPI geral registrado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
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
            $propostasSetor = $propostasPorSetorId[$idSetorAtual] ?? [];
            $kpisSetor = $kpisPorSetorId[$idSetorAtual] ?? [];
            $sugestaoIaSetor = $ultimaSugestaoIaPorSetorId[$idSetorAtual] ?? null;
            $candidatosRelacionar = array_filter($usuariosCliente, fn ($u) => (int) $u['setor_id'] !== $idSetorAtual);
        ?>
        <details class="setor-detalhe" style="border-left-color: <?= cor_setor($idSetorAtual) ?>" <?= $indiceSetor === 0 ? 'open' : '' ?>>
            <summary>
                <?= icone_setor_html($s) ?>
                <span class="fw-semibold"><?= e($s['nome']) ?></span>
                <?php if (!$s['ativo']): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                <span class="text-muted small">
                    — <?= count($membrosReais) ?> <?= count($membrosReais) === 1 ? 'responsável' : 'responsáveis' ?><?= $idSetorMaster === $idSetorAtual ? ' + master' : '' ?>
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
                            <div class="col-md-3"><button type="submit" class="btn btn-sm btn-outline-dark w-100"><i class="bi bi-check2"></i> Salvar</button></div>
                        </form>
                        <div class="seletor-icone-setor mb-3" data-campo-destino="setorIcone<?= $idSetorAtual ?>">
                            <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                                <button type="button" class="icone-opcao <?= $ic === ($s['icone'] ?: 'bi-diagram-3') ? 'active' : '' ?>" data-icone="<?= $ic ?>" title="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></button>
                            <?php endforeach; ?>
                        </div>

                        <hr>
                        <p class="text-muted small mb-2"><i class="bi bi-people"></i> Responsáveis (cada um com login próprio no portal, só desse setor)</p>
                        <?php if (!$membrosReais): ?>
                            <p class="text-muted small">Nenhum responsável relacionado ainda<?= $idSetorMaster === $idSetorAtual ? ' (o master já cobre esse setor, mas fica à vontade pra relacionar mais alguém)' : '' ?>.</p>
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
                                <form method="post" class="d-inline" onsubmit="return confirm('Desvincular <?= e($m['nome']) ?> desse setor? A pessoa continua cadastrada na aba Usuários.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="relacionar_responsavel_setor">
                                    <input type="hidden" name="aba_retorno" value="setores">
                                    <input type="hidden" name="responsavel_id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="setor_id" value="">
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
                                        <option value="<?= $cand['id'] ?>"><?= e($cand['nome']) ?> (<?= e($cand['email']) ?>)<?= $cand['setor_id'] ? ' — hoje em ' . e($nomesSetoresPorId[$cand['setor_id']] ?? '?') : '' ?></option>
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
                                    <td class="fw-semibold"><?= e($t['titulo']) ?></td>
                                    <td><?= $t['tipo'] === 'reuniao' ? '<span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-camera-video"></i> Reunião</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="bi bi-mortarboard"></i> Treinamento</span>' ?></td>
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
                        <?php if ($kpisSetor): ?>
                        <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Período</th><th>KPI</th><th>Valor</th><th>Meta</th><th>Observações</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($kpisSetor as $k): ?>
                                <tr>
                                    <td><?= date('m/Y', strtotime($k['periodo'])) ?></td>
                                    <td class="fw-semibold"><?= e($k['nome_kpi']) ?></td>
                                    <td><?= e(number_format((float) $k['valor'], 2, ',', '.')) ?> <?= e($k['unidade']) ?></td>
                                    <td>
                                        <?php if ($k['meta'] !== null): ?>
                                            <?= e(number_format((float) $k['meta'], 2, ',', '.')) ?>
                                            <?= (float) $k['valor'] >= (float) $k['meta'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle-fill text-warning"></i>' ?>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= e($k['observacoes']) ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-dark botao-editar-kpi"
                                            data-id="<?= $k['id'] ?>" data-nome="<?= e($k['nome_kpi']) ?>" data-valor="<?= e($k['valor']) ?>"
                                            data-meta="<?= e($k['meta'] ?? '') ?>" data-unidade="<?= e($k['unidade']) ?>"
                                            data-periodo="<?= date('Y-m', strtotime($k['periodo'])) ?>" data-setor-id="<?= $k['setor_id'] ?? '' ?>"
                                            data-observacoes="<?= e($k['observacoes']) ?>" data-aba-retorno="setores"
                                        ><i class="bi bi-pencil"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">Nenhuma métrica registrada ainda pra esse setor -- registre pra ajudar a IA a avaliar o processo.</p>
                        <?php endif; ?>
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
                                <span class="text-muted small ms-2">IA ainda não configurada (ADMINPRIME_GEMINI_API_KEY).</span>
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
                    <thead><tr><th></th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Setor</th><th>Portal</th><th>WhatsApp</th><th>Horário</th><th></th></tr></thead>
                    <tbody>
                        <tr>
                            <td><?= avatar_html($cliente['contato_nome'] ?: $cliente['nome'], resolver_avatar_por_email($pdo, $cliente['contato_email'], $cliente['foto_perfil']), 'cliente') ?></td>
                            <td class="fw-semibold"><?= e($cliente['contato_nome'] ?: $cliente['nome']) ?> <span class="badge bg-dark">Master</span></td>
                            <td><?= $cliente['contato_email'] ? e($cliente['contato_email']) : '<span class="text-muted">— sem e-mail —</span>' ?></td>
                            <td class="telefone-linha"><?= e(formatar_telefone($cliente['contato_telefone']) ?: '-') ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="definir_setor_master">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <select name="setor_id" class="form-select form-select-sm select-setor-linha" onchange="this.form.submit()" <?= $setores ? '' : 'disabled title="Cadastre um setor primeiro"' ?>>
                                        <option value="">Nenhum setor</option>
                                        <?php foreach ($setores as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= $idSetorMaster === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
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
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= $cliente['whatsapp_liberacao_horario'] ? 'Remover' : 'Dar' ?> liberação especial pro WhatsApp de <?= e($cliente['nome']) ?> funcionar fora do horário comercial (seg-sex, ' . WHATSAPP_HORARIO_COMERCIAL_INICIO . 'h-' . WHATSAPP_HORARIO_COMERCIAL_FIM . 'h)?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="alternar_liberacao_horario">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <button type="submit" class="btn btn-sm <?= $cliente['whatsapp_liberacao_horario'] ? 'btn-outline-warning' : 'btn-outline-secondary' ?>" title="Vale pra qualquer pessoa desse cliente, no master ou em qualquer setor">
                                        <i class="bi <?= $cliente['whatsapp_liberacao_horario'] ? 'bi-clock-fill' : 'bi-clock' ?>"></i> <?= $cliente['whatsapp_liberacao_horario'] ? '24h liberado' : 'Só horário comercial' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-muted small">Editar em "Visão geral"</td>
                        </tr>
                        <?php foreach ($usuariosCliente as $uc): ?>
                        <tr class="linha-usuario-cliente" role="button" tabindex="0" data-usuario='<?= e(json_encode($uc, JSON_UNESCAPED_UNICODE)) ?>'>
                            <td><?= avatar_html($uc['nome'], resolver_avatar_por_email($pdo, $uc['email'], $uc['foto_perfil']), 'cliente') ?></td>
                            <td class="fw-semibold"><?= e($uc['nome']) ?></td>
                            <td><?= e($uc['email']) ?></td>
                            <td class="telefone-linha"><?= e(formatar_telefone($uc['telefone']) ?: '-') ?></td>
                            <td onclick="event.stopPropagation()">
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="relacionar_responsavel_setor">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                    <select name="setor_id" class="form-select form-select-sm select-setor-linha" onchange="this.form.submit()">
                                        <option value="">Sem setor</option>
                                        <?php foreach ($setores as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= (int) $uc['setor_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
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
                            <td class="text-muted small" title="Liberação de horário vale pro cliente inteiro, é configurada só na linha do master">—</td>
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

</div>

<!-- Modal Novo Chamado (registrado pela equipe) -->
<div class="modal fade" id="modalNovoChamado" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
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
            <div class="mb-3 mt-2"><label class="form-label">Período (mês/ano) *</label><input type="month" name="periodo" class="form-control" required value="<?= date('Y-m') ?>"></div>
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
                <div class="col-12"><label class="form-label">Setor</label>
                    <select name="setor_id" class="form-select">
                        <option value="">Sem setor por enquanto</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="col-md-6"><label class="form-label">Setor</label>
                    <select name="setor_id" id="editarUsuarioClienteSetor" class="form-select">
                        <option value="">Sem setor</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
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

<script src="<?= asset_url('assets/js/calendario_visual.js') ?>"></script>
<script>
// Ativa a aba certa com base no hash da URL (ex: #chamados) ou abre "Visão geral" por padrão.
document.addEventListener('DOMContentLoaded', function () {
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
        document.getElementById('editarUsuarioClienteSetor').value = dados.setor_id || '';
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
            if (campoSetor) campoSetor.value = '';
            if (campoRetorno) campoRetorno.value = 'kpis';
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

    function renderizarResultadoSugestaoImplantacao(tipo, label, json) {
        var alvo = document.getElementById('resultadoSugestaoImplantacao' + tipo);
        if (!alvo) return;
        alvo.innerHTML = '<p class="text-muted small mb-1">Gerado em ' + escaparHtmlCalendario(json.gerado_em) + '</p>'
            + '<textarea class="form-control form-control-sm textarea-sugestao-implantacao mb-2" rows="8">' + escaparHtmlCalendario(json.sugestao) + '</textarea>'
            + '<button type="button" class="btn btn-sm btn-outline-dark botao-exportar-pdf-implantacao" data-tipo="' + tipo + '" data-label="' + escaparHtmlCalendario(label) + '"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</button>';
    }

    function gerarSugestaoImplantacao(form, forcarSemEtapas) {
        var tipo = form.getAttribute('data-tipo');
        var label = form.closest('.card-body').querySelector('.fw-semibold').textContent.trim();
        mostrarCarregando('Gerando sugestão com IA...', true);

        var dados = new FormData(form);
        dados.set('ajax', '1');
        if (forcarSemEtapas) dados.set('forcar_sem_etapas', '1');

        fetch(window.location.href, { method: 'POST', body: dados })
            .then(function (resp) { return resp.json(); })
            .then(function (json) {
                var alvo = document.getElementById('resultadoSugestaoImplantacao' + tipo);
                if (!alvo) return;
                if (json.sucesso) {
                    renderizarResultadoSugestaoImplantacao(tipo, label, json);
                    form.querySelector('button').innerHTML = '<i class="bi bi-stars"></i> Gerar de novo';
                } else if (json.precisa_confirmar_sem_etapas) {
                    escondercarregando();
                    if (confirm(json.mensagem + '\n\nGerar assim mesmo?')) {
                        gerarSugestaoImplantacao(form, true);
                    }
                    return;
                } else {
                    alvo.innerHTML = '<div class="alert alert-danger">' + escaparHtmlCalendario(json.mensagem) + '</div>';
                }
            })
            .catch(function (erro) {
                var alvo = document.getElementById('resultadoSugestaoImplantacao' + tipo);
                if (alvo) alvo.innerHTML = '<div class="alert alert-danger">Falha de conexão ao gerar a sugestão: ' + escaparHtmlCalendario(String(erro)) + '</div>';
            })
            .finally(function () {
                escondercarregando();
            });
    }

    document.querySelectorAll('.form-gerar-sugestao-implantacao').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            gerarSugestaoImplantacao(form, false);
        });
    });

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
