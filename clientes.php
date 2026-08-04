<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireLogin();

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_cliente') {
    csrf_verify();

    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') {
        flash_set('erro', 'Informe o nome do cliente.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO clientes (nome, contato_nome, contato_email, contato_telefone) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $nome,
            trim($_POST['contato_nome'] ?? '') ?: null,
            trim($_POST['contato_email'] ?? '') ?: null,
            trim($_POST['contato_telefone'] ?? '') ?: null,
        ]);
        flash_set('sucesso', 'Cliente "' . $nome . '" cadastrado com sucesso.');
    }

    header('Location: ' . url('clientes.php'));
    exit;
}

$sql = "
    SELECT
        c.*,
        COUNT(d.id) AS demandas_abertas,
        SUM(CASE WHEN d.prioridade = 'urgente' AND d.status IN ('aberta','em_andamento') THEN 1 ELSE 0 END) AS urgentes
    FROM clientes c
    LEFT JOIN demandas d ON d.cliente_id = c.id AND d.status IN ('aberta','em_andamento')
    GROUP BY c.id
    ORDER BY c.nome ASC
";
$clientes = $pdo->query($sql)->fetchAll();

$tituloPagina = 'Clientes';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-3">Clientes</h4>

<div class="toolbar">
    <div class="toolbar-acoes">
        <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoCliente">
            <i class="bi bi-plus-lg"></i> Novo cliente
        </button>
    </div>
    <div class="toolbar-filtros">
        <input type="text" class="form-control form-control-sm" id="buscaClientes" placeholder="Buscar por nome ou contato..." style="max-width: 260px;">
    </div>
</div>

<div class="card card-stat">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tabelaClientes">
                <thead>
                <tr>
                    <th>Nome</th>
                    <th>Status</th>
                    <th>Contato</th>
                    <th class="text-center">Demandas abertas</th>
                    <th class="text-center">Urgentes</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr data-busca="<?= e(mb_strtolower($c['nome'] . ' ' . $c['contato_nome'] . ' ' . $c['contato_email'])) ?>">
                        <td class="fw-semibold"><?= e($c['nome']) ?></td>
                        <td>
                            <?php
                            $statusMap = ['ativo' => 'success', 'pausado' => 'warning', 'encerrado' => 'secondary'];
                            $cor = $statusMap[$c['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $cor ?>"><?= e(ucfirst($c['status'])) ?></span>
                        </td>
                        <td>
                            <?= e($c['contato_nome'] ?: '-') ?>
                            <?php if ($c['contato_email']): ?>
                                <br><small class="text-muted"><?= e($c['contato_email']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int)$c['demandas_abertas'] ?></td>
                        <td class="text-center">
                            <?= (int)$c['urgentes'] > 0 ? '<span class="badge prio-urgente"><i class="bi bi-exclamation-circle-fill"></i> ' . (int)$c['urgentes'] . '</span>' : '-' ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= url('cliente.php?id=' . $c['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Novo Cliente -->
<div class="modal fade" id="modalNovoCliente" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_cliente">
        <div class="modal-header">
          <h5 class="modal-title">Novo cliente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Nome do cliente *</label>
                <input type="text" name="nome" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Nome do contato</label>
                <input type="text" name="contato_nome" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label">E-mail do contato</label>
                <input type="email" name="contato_email" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label">Telefone do contato</label>
                <input type="text" name="contato_telefone" class="form-control">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var busca = document.getElementById('buscaClientes');
    if (!busca) return;
    busca.addEventListener('input', function () {
        var termo = busca.value.trim().toLowerCase();
        document.querySelectorAll('#tabelaClientes tbody tr[data-busca]').forEach(function (linha) {
            linha.style.display = linha.getAttribute('data-busca').includes(termo) ? '' : 'none';
        });
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
