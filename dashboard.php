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
// "Urgentes" aqui é sempre um SUBCONJUNTO de "aguardando nós" (pendência
// nossa) -- antes contava qualquer chamado não fechado com prioridade
// alta/urgente, incluindo os que já respondemos e a bola está com o cliente
// (aguardando_cliente). Isso fazia esse número não bater com o "urgentes" da
// tela de Clientes (que já segue essa mesma regra), que foi o que o Kleber
// apontou como inconsistente num print.
$totalChamadosUrgentes = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE status IN ('aberto','aguardando_equipe') AND prioridade IN ('alta','urgente')")->fetchColumn();
$totalDemandasAtrasadas = (int) $pdo->query("SELECT COUNT(*) FROM demandas WHERE status IN ('aberta','em_andamento') AND prazo IS NOT NULL AND prazo < CURDATE()")->fetchColumn();

// Ranking de clientes que precisam de mais atenção -- combina chamados
// (suporte) e demandas (projetos/entregas), usando subconsultas por cliente
// em vez de JOIN direto nas duas tabelas (JOIN simultâneo em demandas E
// chamados multiplicaria as linhas e inflaria as contagens).
$sqlAtencao = "
    SELECT
        c.id, c.nome, c.status, c.logo,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status IN ('aberto','aguardando_equipe')) AS chamados_aguardando,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status IN ('aberto','aguardando_equipe') AND ch.prioridade IN ('alta','urgente')) AS chamados_urgentes,
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

<h5 class="mb-2 d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill text-danger"></i> Clientes que precisam de mais atenção
</h5>
<p class="text-muted small mb-3">
    Ordenado do mais urgente pro menos urgente. Clique num cliente pra ir direto pros chamados que
    são pendência nossa (já filtrado e ordenado do mais urgente/atrasado primeiro).
</p>

<?php if (empty($clientesAtencao)): ?>
    <div class="card card-stat">
        <p class="text-muted p-3 mb-0">Nenhum cliente com chamado ou demanda em aberto no momento. 🎉</p>
    </div>
<?php else: ?>
<div class="grid-clientes mb-4">
    <?php foreach ($clientesAtencao as $c): ?>
        <a href="<?= url('cliente.php?id=' . $c['id'] . '#chamados') ?>"
           class="card-cliente <?= ($c['chamados_urgentes'] > 0 || $c['demandas_atrasadas'] > 0) ? 'cliente-atencao' : '' ?>">
            <div class="card-cliente-logo-wrap">
                <?php if ($c['logo']): ?>
                    <img src="<?= e(asset_url($c['logo'])) ?>" alt="Logo <?= e($c['nome']) ?>" class="card-cliente-logo">
                <?php else: ?>
                    <div class="card-cliente-logo-vazio"><i class="bi bi-building"></i></div>
                <?php endif; ?>
            </div>
            <div class="card-cliente-topo">
                <div class="card-cliente-nome">
                    <div class="fw-semibold"><?= e($c['nome']) ?></div>
                </div>
            </div>
            <div class="card-cliente-modulos">
                <?php if ((int) $c['chamados_aguardando'] > 0): ?>
                    <span class="badge badge-aguardando-equipe modulo-pill" title="Chamados aguardando nós">
                        <i class="bi bi-headset"></i> <?= (int) $c['chamados_aguardando'] ?> aguardando nós
                    </span>
                <?php endif; ?>
                <?php if ((int) $c['chamados_urgentes'] > 0): ?>
                    <span class="badge prio-urgente modulo-pill" title="Chamados urgentes">
                        <i class="bi bi-exclamation-circle-fill"></i> <?= (int) $c['chamados_urgentes'] ?> urgente<?= (int) $c['chamados_urgentes'] > 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
                <?php if ((int) $c['demandas_atrasadas'] > 0): ?>
                    <span class="badge bg-warning text-dark modulo-pill" title="Demandas atrasadas">
                        <i class="bi bi-calendar-x"></i> <?= (int) $c['demandas_atrasadas'] ?> demanda<?= (int) $c['demandas_atrasadas'] > 1 ? 's' : '' ?> atrasada<?= (int) $c['demandas_atrasadas'] > 1 ? 's' : '' ?>
                    </span>
                <?php elseif ((int) $c['demandas_abertas'] > 0): ?>
                    <span class="badge modulo-pill modulo-neutro" title="Demandas abertas">
                        <i class="bi bi-kanban"></i> <?= (int) $c['demandas_abertas'] ?> demanda<?= (int) $c['demandas_abertas'] > 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/templates/footer.php'; ?>
