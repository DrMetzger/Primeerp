<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireLogin();

$pdo = Database::connection();

$totalClientesAtivos = (int) $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'ativo'")->fetchColumn();

// Chamados que dependem de uma ação nossa agora: os que ainda não tiveram
// nenhuma resposta da equipe ("aberto") + os que o cliente respondeu de
// volta e está esperando a gente ("aguardando_equipe"). Antes o dashboard
// só olhava pra tabela de demandas e nunca pra chamados -- por isso os
// números apareciam sempre zerados mesmo com chamados reais em aberto.
$totalChamadosAguardandoNos = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status IN ('aberto','aguardando_equipe')")->fetchColumn();
$totalChamadosUrgentes = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status != 'fechado' AND prioridade IN ('alta','urgente')")->fetchColumn();
$totalDemandasAtrasadas = (int) $pdo->query("SELECT COUNT(*) FROM demandas WHERE status IN ('aberta','em_andamento') AND prazo IS NOT NULL AND prazo < CURDATE()")->fetchColumn();

// Ranking de clientes que precisam de mais atenção -- combina chamados
// (suporte) e demandas (projetos/entregas), usando subconsultas por cliente
// em vez de JOIN direto nas duas tabelas (JOIN simultâneo em demandas E
// chamados multiplicaria as linhas e inflaria as contagens).
$sqlAtencao = "
    SELECT
        c.id, c.nome, c.status,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status IN ('aberto','aguardando_equipe')) AS chamados_aguardando,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status != 'fechado' AND ch.prioridade IN ('alta','urgente')) AS chamados_urgentes,
        (SELECT COUNT(*) FROM demandas d WHERE d.cliente_id = c.id AND d.status IN ('aberta','em_andamento')) AS demandas_abertas,
        (SELECT COUNT(*) FROM demandas d WHERE d.cliente_id = c.id AND d.status IN ('aberta','em_andamento') AND d.prioridade IN ('alta','urgente')) AS demandas_urgentes,
        (SELECT COUNT(*) FROM demandas d WHERE d.cliente_id = c.id AND d.status IN ('aberta','em_andamento') AND d.prazo IS NOT NULL AND d.prazo < CURDATE()) AS demandas_atrasadas
    FROM clientes c
    WHERE c.status = 'ativo'
";
$clientesAtencao = $pdo->query($sqlAtencao)->fetchAll();

foreach ($clientesAtencao as &$c) {
    $c['pontuacao'] =
        $c['chamados_aguardando'] * 3
        + $c['chamados_urgentes'] * 3
        + $c['demandas_urgentes'] * 2
        + $c['demandas_atrasadas'] * 2
        + $c['demandas_abertas'];
}
unset($c);

$clientesAtencao = array_filter($clientesAtencao, fn ($c) => $c['pontuacao'] > 0);
usort($clientesAtencao, fn ($a, $b) => $b['pontuacao'] <=> $a['pontuacao']);
$clientesAtencao = array_slice($clientesAtencao, 0, 10);

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
                <div class="text-muted small">Chamados aguardando nós</div>
                <div class="stat-value text-primary"><?= $totalChamadosAguardandoNos ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Chamados urgentes / alta prioridade</div>
                <div class="stat-value text-danger"><?= $totalChamadosUrgentes ?></div>
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
            <p class="text-muted p-3 mb-0">Nenhum cliente com chamado ou demanda em aberto no momento. 🎉</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Cliente</th>
                    <th class="text-center">Chamados aguardando nós</th>
                    <th class="text-center">Chamados urgentes</th>
                    <th class="text-center">Demandas abertas</th>
                    <th class="text-center">Demandas atrasadas</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clientesAtencao as $c): ?>
                    <tr class="<?= ($c['chamados_urgentes'] > 0 || $c['demandas_atrasadas'] > 0) ? 'cliente-atencao' : '' ?>">
                        <td class="fw-semibold"><?= e($c['nome']) ?></td>
                        <td class="text-center"><?= (int)$c['chamados_aguardando'] > 0 ? '<span class="badge badge-aguardando-equipe">'.(int)$c['chamados_aguardando'].'</span>' : '0' ?></td>
                        <td class="text-center"><?= (int)$c['chamados_urgentes'] > 0 ? '<span class="badge prio-urgente">'.(int)$c['chamados_urgentes'].'</span>' : '0' ?></td>
                        <td class="text-center"><?= (int)$c['demandas_abertas'] ?></td>
                        <td class="text-center"><?= (int)$c['demandas_atrasadas'] > 0 ? '<span class="badge bg-warning text-dark">'.(int)$c['demandas_atrasadas'].'</span>' : '0' ?></td>
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
