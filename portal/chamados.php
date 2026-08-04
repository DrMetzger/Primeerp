<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Notificacoes;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_chamado') {
    csrf_verify();

    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');

    if ($titulo === '' || $descricao === '') {
        flash_set('erro', 'Preencha o título e a descrição do chamado.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            $titulo,
            $descricao,
            trim($_POST['categoria'] ?? '') ?: null,
            $_POST['prioridade'] ?? 'media',
            'cliente',
        ]);
        $chamadoId = (int) $pdo->lastInsertId();

        $chamado = $pdo->query("SELECT * FROM chamados WHERE id = $chamadoId")->fetch();
        $cliente = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $cliente->execute([$clienteId]);
        $cliente = $cliente->fetch();

        Notificacoes::novoChamado($chamado, $cliente);

        flash_set('sucesso', 'Chamado aberto com sucesso! Nossa equipe já foi avisada.');
    }

    header('Location: ' . url('portal/chamados.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM chamados WHERE cliente_id = ? ORDER BY criado_em DESC');
$stmt->execute([$clienteId]);
$chamados = $stmt->fetchAll();

$tituloPagina = 'Meus Chamados';
require __DIR__ . '/../templates/portal_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Meus chamados</h4>
    <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoChamado"><i class="bi bi-plus-lg"></i> Abrir chamado</button>
</div>

<div class="card card-stat">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Título</th><th>Status</th><th>Prioridade</th><th>Aberto em</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($chamados as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= e($c['titulo']) ?></td>
                    <td><?= badge_status_chamado($c['status']) ?></td>
                    <td><?= badge_prioridade($c['prioridade']) ?></td>
                    <td><?= formatar_data($c['criado_em']) ?></td>
                    <td class="text-end"><a href="<?= url('portal/chamado.php?id=' . $c['id']) ?>" class="btn btn-sm btn-outline-dark">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($chamados)): ?>
                <tr><td colspan="5" class="text-muted text-center py-4">Você ainda não abriu nenhum chamado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNovoChamado" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_chamado">
        <div class="modal-header"><h5 class="modal-title">Abrir novo chamado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Categoria</label>
                <select name="categoria" class="form-select">
                    <option value="">Selecione (opcional)</option>
                    <?php foreach (CATEGORIAS_CHAMADO as $cat): ?>
                        <option><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Prioridade</label>
                <select name="prioridade" class="form-select">
                    <option value="baixa">Baixa</option>
                    <option value="media" selected>Média</option>
                    <option value="alta">Alta</option>
                    <option value="urgente">Urgente</option>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Descrição *</label><textarea name="descricao" class="form-control" rows="4" required></textarea></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Enviar chamado</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
