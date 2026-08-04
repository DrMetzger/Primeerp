<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();
$chamadoId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM chamados WHERE id = ? AND cliente_id = ?');
$stmt->execute([$chamadoId, $clienteId]);
$chamado = $stmt->fetch();

$stmtCliente = $pdo->prepare('SELECT foto_perfil FROM clientes WHERE id = ?');
$stmtCliente->execute([$clienteId]);
$fotoPerfilCliente = $stmtCliente->fetchColumn() ?: null;

if (!$chamado) {
    http_response_code(404);
    die('Chamado não encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'responder') {
    csrf_verify();
    $mensagem = trim($_POST['mensagem'] ?? '');

    if ($mensagem !== '') {
        $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, mensagem) VALUES (?, ?, ?, ?)')
            ->execute([$chamadoId, 'cliente', ClienteAuth::clienteNome(), $mensagem]);

        // Recalcula o status a partir de quem respondeu por último -- se o
        // chamado estava fechado, isso também reabre automaticamente (o
        // cliente respondendo é sinal suficiente de que precisa reabrir).
        recalcular_status_chamado($pdo, $chamadoId);

        flash_set('sucesso', 'Resposta enviada.');
    }

    header('Location: ' . url('portal/chamado.php?id=' . $chamadoId));
    exit;
}

$respostas = $pdo->prepare('SELECT * FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em ASC');
$respostas->execute([$chamadoId]);
$respostas = $respostas->fetchAll();

$tituloPagina = $chamado['titulo'];
require __DIR__ . '/../templates/portal_header.php';
?>

<a href="<?= url('portal/chamados.php') ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> Voltar para meus chamados</a>

<div class="card card-stat mt-3">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= e($chamado['titulo']) ?></h5>
            <?= badge_status_chamado($chamado['status']) ?>
        </div>
    </div>
    <div class="card-body">
        <p class="mb-1"><?= badge_prioridade($chamado['prioridade']) ?> <?= $chamado['categoria'] ? '<span class="badge bg-light text-dark border">' . e($chamado['categoria']) . '</span>' : '' ?></p>
        <p class="text-muted small">Aberto em <?= formatar_data($chamado['criado_em']) ?></p>
        <p style="white-space: pre-wrap;"><?= e($chamado['descricao']) ?></p>
    </div>
</div>

<h6 class="mt-4 mb-2">Respostas</h6>
<?php if (!empty($respostas)): ?>
    <!-- Aqui quem responde é o cliente logado -- suas próprias mensagens ficam
         à direita (convenção normal de chat), a equipe PrimeERP à esquerda. -->
    <div class="chat-thread">
    <?php foreach ($respostas as $r): ?>
        <?php $ladoDireita = $r['autor_tipo'] === 'cliente'; ?>
        <div class="chat-linha <?= $ladoDireita ? 'chat-direita' : 'chat-esquerda' ?>">
            <?= avatar_html($r['autor_nome'], $ladoDireita ? $fotoPerfilCliente : null, $ladoDireita ? 'cliente' : 'equipe') ?>
            <div class="chat-bolha">
                <span class="chat-autor"><?= e($r['autor_nome']) ?><?= $r['autor_tipo'] === 'equipe' ? ' · Prime Hub' : '' ?></span>
                <span style="white-space: pre-wrap;"><?= e($r['mensagem']) ?></span>
                <span class="chat-hora"><?= date('d/m/Y H:i', strtotime($r['criado_em'])) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php else: ?>
    <p class="text-muted">Nenhuma resposta ainda.</p>
<?php endif; ?>

<?php if ($chamado['status'] === 'fechado'): ?>
    <p class="text-muted small mt-3 mb-1"><i class="bi bi-info-circle"></i> Este chamado está fechado. Se responder, ele é reaberto automaticamente.</p>
<?php endif; ?>
<form method="post" class="mt-2">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="responder">
    <textarea name="mensagem" class="form-control mb-2" rows="3" placeholder="Escreva uma resposta..." required></textarea>
    <button type="submit" class="btn btn-dark">Enviar resposta</button>
</form>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
