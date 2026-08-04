<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireLogin();

$pdo = Database::connection();

$totalClientesAtivos = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'ativo'")->fetchColumn();
$totalDemandasAbertas = (int) $pdo->query("SELECT COUNT(*) FROM demandas WHERE status IN ('aberta','em_andamento')")->fetchColumn();
$totalDemandasUrgentes = (int) $pdo->query("SELECT COUNT(*) FROM demandas WHERE status IN ('aberta','em_andamento') AND prioridade IN ('alta','urgente')")->fetchColumn();
$totalDemandasAtrasadas = (int) $pdo->query("SELECT COUNT(*) FROM demandas WHERE status IN ('aberta','em_andamento') AND prazo IS NOT NULL AND prazo < CURDATE()")->fetchColumn();

// Ranking de clientes que precisam de mais atenção:
// pontuação = urgentes*3 + altas*2 + abertas*1 + atrasadas*2
$sqlAtencao = "
    SELECT
        c.id, c.nome, c.status,
        COUNT(d.id) AS total_abertas,
        SUM(CASE WHEN d.prioridade = 'urgente' THEN 1 ELSE 0 END) AS urgentes,
        SUM(CASE WHEN d.prioridade = 'alta' THEN 1 ELSE 0 END) AS altas,
        SUM(CASE WHEN d.prazo IS NOT NULL AND d.prazo < CURDATE() THEN 1 ELSE 0 END) AS atrasadas,
        (
            SUM(CASE WHEN d.prioridade = 'urgente' THEN 3 ELSE 0 END) +
            SUM(CASE WHEN d.prioridade = 'alta' THEN 2 ELSE 0 END) +
            SUM(CASE WHEN d.prazo IS NOT NULL AND d.prazo < CURDATE() THEN 2 ELSE 0 END) +
            COUNT(d.id)
        ) AS pontuacao
    FROM clientes c
    LEFT JOIN demandas d ON d.cliente_id = c.id AND d.status IN ('aberta','em_andamento')
    WHERE c.status = 'ativo'
    GROUP BY c.id, c.nome, c.status
    HAVING total_abertas > 0
    ORDER BY pontuacao DESC
    LIMIT 10
";
$clientesAtencao = $pdo->query($sqlAtencao)->fetchAll();

$tituloPagina = 'Dashboard';
require __DIR__ . '/templates/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Clientes ativos</div>
                <div class="stat-value text-dark"><?= $totalClientesAtivos ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Demandas abertas</div>
                <div class="stat-value text-primary"><?= $totalDemandasAbertas ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Alta prioridade / urgentes</div>
                <div class="stat-value text-danger"><?= $totalDemandasUrgentes ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Demandas atrasadas</div>
                <div class="stat-value text-warning"><?= $totalDemandasAtrasadas ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card card-stat">
    <div class="card-header bg-white fw-bold">
        <i class="bi bi-exclamation-triangle-fill text-danger"></i> Clientes que precisam de mais atenção
    </div>
    <div class="card-body p-0">
        <?php if (empty($clientesAtencao)): ?>
            <p class="text-muted p-3 mb-0">Nenhum cliente com demandas abertas no momento. 🎉</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Cliente</th>
                    <th class="text-center">Abertas</th>
                    <th class="text-center">Urgentes</th>
                    <th class="text-center">Alta prioridade</th>
                    <th class="text-center">Atrasadas</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clientesAtencao as $c): ?>
                    <tr class="<?= ($c['urgentes'] > 0 || $c['atrasadas'] > 0) ? 'cliente-atencao' : '' ?>">
                        <td class="fw-semibold"><?= e($c['nome']) ?></td>
                        <td class="text-center"><?= (int)$c['total_abertas'] ?></td>
                        <td class="text-center"><?= (int)$c['urgentes'] > 0 ? '<span class="badge prio-urgente">'.(int)$c['urgentes'].'</span>' : '0' ?></td>
                        <td class="text-center"><?= (int)$c['altas'] ?></td>
                        <td class="text-center"><?= (int)$c['atrasadas'] > 0 ? '<span class="badge bg-warning text-dark">'.(int)$c['atrasadas'].'</span>' : '0' ?></td>
                        <td class="text-end">
                            <a href="<?= url('cliente.php?id=' . $c['id']) ?>" class="btn btn-sm btn-outline-dark">Ver cliente</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
