<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Notificacoes;
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
        $stmt = $pdo->prepare('UPDATE clientes SET nome=?, status=?, contato_nome=?, contato_email=?, contato_telefone=?, observacoes=? WHERE id=?');
        $stmt->execute([
            trim($_POST['nome']),
            $_POST['status'],
            trim($_POST['contato_nome']) ?: null,
            trim($_POST['contato_email']) ?: null,
            trim($_POST['contato_telefone']) ?: null,
            trim($_POST['observacoes']) ?: null,
            $clienteId,
        ]);
        flash_set('sucesso', 'Dados do cliente atualizados.');
    }

    if ($acao === 'criar_demanda') {
        $stmt = $pdo->prepare('INSERT INTO demandas (cliente_id, titulo, descricao, status, prioridade, responsavel_id, prazo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            trim($_POST['titulo']),
            trim($_POST['descricao']) ?: null,
            $_POST['status'] ?: 'aberta',
            $_POST['prioridade'] ?: 'media',
            $_POST['responsavel_id'] ?: null,
            $_POST['prazo'] ?: null,
        ]);
        flash_set('sucesso', 'Demanda criada.');
    }

    if ($acao === 'atualizar_demanda') {
        $stmt = $pdo->prepare('UPDATE demandas SET titulo=?, descricao=?, status=?, prioridade=?, responsavel_id=?, prazo=? WHERE id=? AND cliente_id=?');
        $stmt->execute([
            trim($_POST['titulo']),
            trim($_POST['descricao']) ?: null,
            $_POST['status'],
            $_POST['prioridade'],
            $_POST['responsavel_id'] ?: null,
            $_POST['prazo'] ?: null,
            (int) $_POST['demanda_id'],
            $clienteId,
        ]);
        flash_set('sucesso', 'Demanda atualizada.');
    }

    if ($acao === 'criar_kpi') {
        $stmt = $pdo->prepare('INSERT INTO kpis (cliente_id, nome_kpi, valor, meta, unidade, periodo, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            trim($_POST['nome_kpi']),
            str_replace(',', '.', $_POST['valor']),
            $_POST['meta'] !== '' ? str_replace(',', '.', $_POST['meta']) : null,
            trim($_POST['unidade']) ?: null,
            $_POST['periodo'] . '-01',
            trim($_POST['observacoes']) ?: null,
        ]);
        flash_set('sucesso', 'KPI registrado.');
    }

    if ($acao === 'criar_treinamento') {
        $stmt = $pdo->prepare('INSERT INTO treinamentos (cliente_id, titulo, descricao, status, data_inicio, data_fim, responsavel_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            trim($_POST['titulo']),
            trim($_POST['descricao']) ?: null,
            $_POST['status'] ?: 'planejado',
            $_POST['data_inicio'] ?: null,
            $_POST['data_fim'] ?: null,
            $_POST['responsavel_id'] ?: null,
        ]);
        flash_set('sucesso', 'Treinamento registrado.');
    }

    if ($acao === 'atualizar_treinamento_status') {
        $stmt = $pdo->prepare('UPDATE treinamentos SET status=? WHERE id=? AND cliente_id=?');
        $stmt->execute([$_POST['status'], (int) $_POST['treinamento_id'], $clienteId]);
        flash_set('sucesso', 'Status do treinamento atualizado.');
    }

    if ($acao === 'upload_documento' && isset($_FILES['arquivo'])) {
        $arquivo = $_FILES['arquivo'];
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            flash_set('erro', 'Falha no upload do arquivo.');
        } elseif ($arquivo['size'] > UPLOADS_MAX_SIZE) {
            flash_set('erro', 'Arquivo maior que o limite permitido (15MB).');
        } else {
            $dirCliente = UPLOADS_DIR . '/clientes/' . $clienteId;
            if (!is_dir($dirCliente)) {
                mkdir($dirCliente, 0755, true);
            }
            $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
            $nomeArmazenado = bin2hex(random_bytes(16)) . ($extensao ? '.' . $extensao : '');
            $destino = $dirCliente . '/' . $nomeArmazenado;

            if (move_uploaded_file($arquivo['tmp_name'], $destino)) {
                $stmt = $pdo->prepare('INSERT INTO documentos (cliente_id, titulo, descricao, categoria, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $clienteId,
                    trim($_POST['titulo']) ?: $arquivo['name'],
                    trim($_POST['descricao']) ?: null,
                    trim($_POST['categoria']) ?: null,
                    $arquivo['name'],
                    'clientes/' . $clienteId . '/' . $nomeArmazenado,
                    $arquivo['size'],
                    Auth::userId(),
                ]);
                flash_set('sucesso', 'Documento enviado.');
            } else {
                flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
            }
        }
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

    if ($acao === 'criar_chamado_equipe') {
        $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, responsavel_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            trim($_POST['titulo']),
            trim($_POST['descricao']),
            trim($_POST['categoria'] ?? '') ?: null,
            $_POST['prioridade'] ?? 'media',
            'equipe',
            $_POST['responsavel_id'] ?: null,
        ]);
        flash_set('sucesso', 'Chamado registrado.');
    }

    if ($acao === 'responder_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        $mensagem = trim($_POST['mensagem'] ?? '');

        if ($mensagem !== '') {
            $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, mensagem) VALUES (?, "equipe", ?, ?)')
                ->execute([$chamadoId, Auth::userName(), $mensagem]);
            recalcular_status_chamado($pdo, $chamadoId);

            $chamadoAtualizado = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
            $chamadoAtualizado->execute([$chamadoId]);
            Notificacoes::novaRespostaEquipe($chamadoAtualizado->fetch(), $cliente);

            flash_set('sucesso', 'Resposta enviada ao cliente.');
        }
    }

    if ($acao === 'fechar_chamado') {
        $pdo->prepare("UPDATE chamados SET status = 'fechado' WHERE id = ? AND cliente_id = ?")
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

    header('Location: ' . url('cliente.php?id=' . $clienteId . '#' . ($_POST['aba_retorno'] ?? 'visao-geral')));
    exit;
}

// --- Dados para exibição ---
$chamados = $pdo->prepare('SELECT c.*, u.nome AS responsavel_nome FROM chamados c LEFT JOIN usuarios u ON u.id = c.responsavel_id WHERE c.cliente_id = ? ORDER BY (c.status = "fechado") ASC, (c.status = "aguardando_equipe") DESC, c.criado_em DESC');
$chamados->execute([$clienteId]);
$chamados = $chamados->fetchAll();

$respostasPorChamado = [];
if ($chamados) {
    $ids = array_column($chamados, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtResp = $pdo->prepare("SELECT * FROM chamado_respostas WHERE chamado_id IN ($placeholders) ORDER BY criado_em ASC");
    $stmtResp->execute($ids);
    foreach ($stmtResp->fetchAll() as $r) {
        $respostasPorChamado[$r['chamado_id']][] = $r;
    }
}

$demandas = $pdo->prepare('SELECT d.*, u.nome AS responsavel_nome FROM demandas d LEFT JOIN usuarios u ON u.id = d.responsavel_id WHERE d.cliente_id = ? ORDER BY (d.status IN ("aberta","em_andamento")) DESC, d.prazo IS NULL, d.prazo ASC, d.criado_em DESC');
$demandas->execute([$clienteId]);
$demandas = $demandas->fetchAll();

$kpis = $pdo->prepare('SELECT * FROM kpis WHERE cliente_id = ? ORDER BY periodo DESC, nome_kpi ASC');
$kpis->execute([$clienteId]);
$kpis = $kpis->fetchAll();

$documentos = $pdo->prepare('SELECT d.*, u.nome AS criado_por_nome FROM documentos d LEFT JOIN usuarios u ON u.id = d.criado_por WHERE d.cliente_id = ? ORDER BY d.criado_em DESC');
$documentos->execute([$clienteId]);
$documentos = $documentos->fetchAll();

$treinamentos = $pdo->prepare('SELECT t.*, u.nome AS responsavel_nome FROM treinamentos t LEFT JOIN usuarios u ON u.id = t.responsavel_id WHERE t.cliente_id = ? ORDER BY t.data_inicio DESC');
$treinamentos->execute([$clienteId]);
$treinamentos = $treinamentos->fetchAll();

$usuarios = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();

$tituloPagina = $cliente['nome'];
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><?= e($cliente['nome']) ?></h4>
        <a href="<?= url('clientes.php') ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> Voltar para clientes</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="clienteTabs">
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#visao-geral">Visão geral</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#chamados">Chamados <span class="badge bg-secondary"><?= count($chamados) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#demandas">Demandas <span class="badge bg-secondary"><?= count($demandas) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#kpis">KPIs</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#documentos">Documentos <span class="badge bg-secondary"><?= count($documentos) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#treinamentos">Treinamentos <span class="badge bg-secondary"><?= count($treinamentos) ?></span></a></li>
</ul>

<div class="tab-content">

    <!-- VISÃO GERAL -->
    <div class="tab-pane" id="visao-geral">
        <div class="card card-stat">
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_cliente">
                    <input type="hidden" name="aba_retorno" value="visao-geral">
                    <div class="row g-3">
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
                            <label class="form-label">Contato - nome</label>
                            <input type="text" name="contato_nome" class="form-control" value="<?= e($cliente['contato_nome']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - e-mail</label>
                            <input type="email" name="contato_email" class="form-control" value="<?= e($cliente['contato_email']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - telefone</label>
                            <input type="text" name="contato_telefone" class="form-control" value="<?= e($cliente['contato_telefone']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="4"><?= e($cliente['observacoes']) ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3">Salvar alterações</button>
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
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="enviar_convite_portal">
                    <input type="hidden" name="aba_retorno" value="visao-geral">
                    <button type="submit" class="btn btn-outline-dark btn-sm" <?= $cliente['contato_email'] ? '' : 'disabled' ?>>
                        <i class="bi bi-send"></i> <?= $cliente['portal_ativo'] ? 'Reenviar convite / redefinir senha' : 'Enviar convite de acesso' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- CHAMADOS -->
    <div class="tab-pane" id="chamados">
        <p class="text-muted small mb-2"><i class="bi bi-headset"></i> Atendimento e suporte -- dúvidas, problemas e pedidos pontuais do cliente, com resposta direta em formato de conversa.</p>
        <div class="toolbar">
            <div class="toolbar-acoes">
                <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoChamado"><i class="bi bi-plus-lg"></i> Registrar chamado</button>
            </div>
            <div class="toolbar-filtros">
                <div class="btn-group flex-wrap" role="group" id="filtroStatusChamados">
                    <button type="button" class="btn btn-sm filtro-pill filtro-todos active" data-filtro="todos"><i class="bi bi-collection"></i> Todos</button>
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
        <div class="card chamado-card mb-3" data-status="<?= e($c['status']) ?>" data-prioridade="<?= e($c['prioridade']) ?>">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <strong><?= e($c['titulo']) ?></strong>
                        <?= badge_status_chamado($c['status']) ?>
                        <?= badge_prioridade($c['prioridade']) ?>
                        <?= $c['aberto_por'] === 'cliente'
                            ? '<span class="badge badge-origem-cliente"><i class="bi bi-person-fill"></i> Aberto pelo cliente</span>'
                            : '<span class="badge badge-origem-equipe"><i class="bi bi-headset"></i> Aberto pela equipe</span>' ?>
                    </div>
                    <!-- O status "no meio" (aguardando nós / aguardando cliente) é sempre
                         automático, calculado por quem respondeu por último -- a única
                         ação manual que sobra é encerrar ou reabrir o chamado. -->
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="<?= $c['status'] === 'fechado' ? 'reabrir_chamado' : 'fechar_chamado' ?>">
                        <input type="hidden" name="aba_retorno" value="chamados">
                        <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                        <?php if ($c['status'] === 'fechado'): ?>
                            <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-arrow-counterclockwise"></i> Reabrir</button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-check2-circle"></i> Encerrar</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-clock"></i> Aberto em <?= formatar_data($c['criado_em']) ?><?= $c['categoria'] ? ' · ' . e($c['categoria']) : '' ?></p>
                <p style="white-space: pre-wrap;"><?= e($c['descricao']) ?></p>

                <?php if (!empty($respostasPorChamado[$c['id']])): ?>
                    <hr>
                    <!-- Cliente sempre à esquerda, equipe (nós) sempre à direita -- convenção
                         pedida pelo Kleber, igual estilo WhatsApp. -->
                    <div class="chat-thread">
                    <?php foreach ($respostasPorChamado[$c['id']] as $r): ?>
                        <?php $ladoDireita = $r['autor_tipo'] === 'equipe'; ?>
                        <div class="chat-linha <?= $ladoDireita ? 'chat-direita' : 'chat-esquerda' ?>">
                            <?= avatar_html($r['autor_nome'], $ladoDireita ? null : ($cliente['foto_perfil'] ?? null), $ladoDireita ? 'equipe' : 'cliente') ?>
                            <div class="chat-bolha">
                                <span class="chat-autor"><?= e($r['autor_nome']) ?></span>
                                <span style="white-space: pre-wrap;"><?= e($r['mensagem']) ?></span>
                                <span class="chat-hora"><?= date('d/m/Y H:i', strtotime($r['criado_em'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="responder_chamado">
                    <input type="hidden" name="aba_retorno" value="chamados">
                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                    <textarea name="mensagem" class="form-control form-control-sm mb-2" rows="2" placeholder="Responder ao cliente..."></textarea>
                    <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-send"></i> Enviar resposta</button>
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

    <!-- DEMANDAS -->
    <div class="tab-pane" id="demandas">
        <p class="text-muted small mb-2"><i class="bi bi-kanban"></i> Projetos e entregas -- desenvolvimentos, implementações e melhorias planejadas, com responsável e prazo definidos.</p>
        <div class="toolbar">
            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaDemanda"><i class="bi bi-plus-lg"></i> Nova demanda</button>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Título</th><th>Status</th><th>Prioridade</th><th>Responsável</th><th>Prazo</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($demandas as $d): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($d['titulo']) ?></td>
                            <td><?= badge_status_demanda($d['status']) ?></td>
                            <td><?= badge_prioridade($d['prioridade']) ?></td>
                            <td><?= e($d['responsavel_nome'] ?: '-') ?></td>
                            <td><?= formatar_data($d['prazo']) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modalEditarDemanda<?= $d['id'] ?>">Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($demandas)): ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">Nenhuma demanda cadastrada ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- KPIS -->
    <div class="tab-pane" id="kpis">
        <div class="toolbar">
            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoKpi"><i class="bi bi-plus-lg"></i> Registrar KPI</button>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Período</th><th>KPI</th><th>Valor</th><th>Meta</th><th>Observações</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kpis as $k): ?>
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
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($kpis)): ?>
                        <tr><td colspan="5" class="text-muted text-center py-3">Nenhum KPI registrado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- DOCUMENTOS -->
    <div class="tab-pane" id="documentos">
        <div class="toolbar">
            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoDocumento"><i class="bi bi-upload"></i> Enviar documento</button>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Título</th><th>Categoria</th><th>Arquivo</th><th>Enviado por</th><th>Data</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($documentos as $doc): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($doc['titulo']) ?></td>
                            <td><?= e($doc['categoria'] ?: '-') ?></td>
                            <td><?= e($doc['arquivo_nome_original']) ?> <small class="text-muted">(<?= round($doc['arquivo_tamanho']/1024) ?> KB)</small></td>
                            <td><?= e($doc['criado_por_nome'] ?: '-') ?></td>
                            <td><?= formatar_data($doc['criado_em']) ?></td>
                            <td class="text-end">
                                <a href="<?= url('download.php?id=' . $doc['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($documentos)): ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">Nenhum documento enviado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TREINAMENTOS -->
    <div class="tab-pane" id="treinamentos">
        <div class="toolbar">
            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoTreinamento"><i class="bi bi-plus-lg"></i> Novo treinamento</button>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Título</th><th>Status</th><th>Início</th><th>Fim</th><th>Responsável</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($treinamentos as $t): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($t['titulo']) ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="atualizar_treinamento_status">
                                    <input type="hidden" name="aba_retorno" value="treinamentos">
                                    <input type="hidden" name="treinamento_id" value="<?= $t['id'] ?>">
                                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <?php foreach (['planejado' => 'Planejado', 'em_andamento' => 'Em andamento', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'] as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= $t['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><?= formatar_data($t['data_inicio']) ?></td>
                            <td><?= formatar_data($t['data_fim']) ?></td>
                            <td><?= e($t['responsavel_nome'] ?: '-') ?></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($treinamentos)): ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">Nenhum treinamento registrado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
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
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Descrição *</label><textarea name="descricao" class="form-control" rows="3" required></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Categoria</label>
                    <select name="categoria" class="form-select">
                        <option value="">-</option>
                        <?php foreach (CATEGORIAS_CHAMADO as $cat): ?>
                            <option><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Prioridade</label>
                    <select name="prioridade" class="form-select">
                        <option value="baixa">Baixa</option>
                        <option value="media" selected>Média</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
                <div class="col-12"><label class="form-label">Responsável</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                <div class="col-6"><label class="form-label">De</label><input type="date" name="data_de" class="form-control"></div>
                <div class="col-6"><label class="form-label">Até</label><input type="date" name="data_ate" class="form-control"></div>
            </div>
            <p class="text-muted small mt-2 mb-0">Deixe as datas vazias para exportar todo o período. O arquivo abre direto no Excel.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-exportar"><i class="bi bi-download"></i> Exportar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Nova Demanda -->
<div class="modal fade" id="modalNovaDemanda" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_demanda">
        <input type="hidden" name="aba_retorno" value="demandas">
        <div class="modal-header"><h5 class="modal-title">Nova demanda</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="3"></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="aberta">Aberta</option>
                        <option value="em_andamento">Em andamento</option>
                        <option value="concluida">Concluída</option>
                        <option value="cancelada">Cancelada</option>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Prioridade</label>
                    <select name="prioridade" class="form-select">
                        <option value="baixa">Baixa</option>
                        <option value="media" selected>Média</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Responsável</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Prazo</label><input type="date" name="prazo" class="form-control"></div>
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

<?php foreach ($demandas as $d): ?>
<!-- Modal Editar Demanda -->
<div class="modal fade" id="modalEditarDemanda<?= $d['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="atualizar_demanda">
        <input type="hidden" name="aba_retorno" value="demandas">
        <input type="hidden" name="demanda_id" value="<?= $d['id'] ?>">
        <div class="modal-header"><h5 class="modal-title">Editar demanda</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required value="<?= e($d['titulo']) ?>"></div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="3"><?= e($d['descricao']) ?></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['aberta' => 'Aberta', 'em_andamento' => 'Em andamento', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $d['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Prioridade</label>
                    <select name="prioridade" class="form-select">
                        <?php foreach (['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $d['prioridade'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Responsável</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= (int)$d['responsavel_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Prazo</label><input type="date" name="prazo" class="form-control" value="<?= e($d['prazo']) ?>"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Modal Novo KPI -->
<div class="modal fade" id="modalNovoKpi" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_kpi">
        <input type="hidden" name="aba_retorno" value="kpis">
        <div class="modal-header"><h5 class="modal-title">Registrar KPI</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome do KPI *</label><input type="text" name="nome_kpi" class="form-control" required placeholder="Ex: SLA de atendimento, chamados resolvidos..."></div>
            <div class="row g-2">
                <div class="col-4"><label class="form-label">Valor *</label><input type="text" name="valor" class="form-control" required placeholder="Ex: 95,5"></div>
                <div class="col-4"><label class="form-label">Meta</label><input type="text" name="meta" class="form-control" placeholder="Ex: 98"></div>
                <div class="col-4"><label class="form-label">Unidade</label><input type="text" name="unidade" class="form-control" placeholder="%, dias, un."></div>
            </div>
            <div class="mb-3 mt-2"><label class="form-label">Período (mês/ano) *</label><input type="month" name="periodo" class="form-control" required value="<?= date('Y-m') ?>"></div>
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

<!-- Modal Novo Documento -->
<div class="modal fade" id="modalNovoDocumento" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="upload_documento">
        <input type="hidden" name="aba_retorno" value="documentos">
        <div class="modal-header"><h5 class="modal-title">Enviar documento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título</label><input type="text" name="titulo" class="form-control" placeholder="Se deixar vazio, usa o nome do arquivo"></div>
            <div class="mb-3"><label class="form-label">Categoria</label><input type="text" name="categoria" class="form-control" placeholder="Ex: Contrato, Manual, Ata de reunião..."></div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
            <div class="mb-3"><label class="form-label">Arquivo * (máx. 15MB)</label><input type="file" name="arquivo" class="form-control" required></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Enviar</button>
        </div>
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
        <input type="hidden" name="aba_retorno" value="treinamentos">
        <div class="modal-header"><h5 class="modal-title">Novo treinamento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Início</label><input type="date" name="data_inicio" class="form-control"></div>
                <div class="col-6"><label class="form-label">Fim</label><input type="date" name="data_fim" class="form-control"></div>
                <div class="col-12"><label class="form-label">Responsável</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
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

<script>
// Ativa a aba certa com base no hash da URL (ex: #demandas) ou abre "Visão geral" por padrão.
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

    // Filtro de chamados por status (client-side, sem recarregar a página).
    var grupoFiltro = document.getElementById('filtroStatusChamados');
    var filtroAtual = 'todos';
    if (grupoFiltro) {
        grupoFiltro.querySelectorAll('.filtro-pill').forEach(function (botao) {
            botao.addEventListener('click', function () {
                grupoFiltro.querySelectorAll('.filtro-pill').forEach(function (b) { b.classList.remove('active'); });
                botao.classList.add('active');
                var filtro = botao.getAttribute('data-filtro');
                filtroAtual = filtro;
                var algumVisivel = false;
                document.querySelectorAll('#listaChamados .chamado-card').forEach(function (card) {
                    var mostra = (filtro === 'todos' || card.getAttribute('data-status') === filtro);
                    card.style.display = mostra ? '' : 'none';
                    if (mostra) algumVisivel = true;
                });
                var aviso = document.getElementById('avisoFiltroVazio');
                if (aviso) aviso.classList.toggle('d-none', algumVisivel || document.querySelectorAll('#listaChamados .chamado-card').length === 0);
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
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
