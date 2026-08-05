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

// --- Segunda linha de KPIs: volume do mês + saúde do atendimento ---------

$chamadosMesAtual = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE criado_em >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
$chamadosMesPassado = (int) $pdo->query("
    SELECT COUNT(*) FROM chamados
    WHERE criado_em >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%Y-%m-01')
      AND criado_em < DATE_FORMAT(NOW(), '%Y-%m-01')
")->fetchColumn();

$fechadosMesAtual = (int) $pdo->query("SELECT COUNT(*) FROM chamados WHERE fechado_em >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
$fechadosMesPassado = (int) $pdo->query("
    SELECT COUNT(*) FROM chamados
    WHERE fechado_em >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%Y-%m-01')
      AND fechado_em < DATE_FORMAT(NOW(), '%Y-%m-01')
")->fetchColumn();

// Tempo médio de atendimento (empresa toda) nos últimos 30 dias -- mesma
// base de fechado_em usada nas métricas por usuário (usuarios.php). Como a
// coluna é nova, só passa a ter dado real a partir de agora.
$tempoMedioMinutos = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, criado_em, fechado_em))
    FROM chamados
    WHERE fechado_em IS NOT NULL AND fechado_em >= NOW() - INTERVAL 30 DAY
")->fetchColumn();
$tempoMedioMinutos = $tempoMedioMinutos !== null ? (float) $tempoMedioMinutos : null;

// Proxy de "atraso": não temos um SLA formal configurado, então em vez de
// inventar um limite arbitrário, mostra a idade média (em dias) de quem
// ainda está pendente agora -- quanto maior, pior sinal de acúmulo.
$idadeMediaDias = $pdo->query("
    SELECT AVG(DATEDIFF(NOW(), criado_em))
    FROM chamados
    WHERE status IN ('aberto','aguardando_equipe')
")->fetchColumn();
$idadeMediaDias = $idadeMediaDias !== null ? (float) $idadeMediaDias : null;

// --- Volume mensal (últimos 6 meses), abertos vs fechados -----------------

// DateTime::format('M') do PHP sempre devolve em inglês (Jan, Feb, Mar...),
// não segue nenhuma configuração de locale/idioma do servidor -- por isso o
// mês precisa ser traduzido manualmente aqui.
$mesesAbreviados = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];

$mesesLabels = [];
$mesesChave = [];
for ($i = 5; $i >= 0; $i--) {
    $ref = new DateTime("first day of -$i month");
    $mesesChave[] = $ref->format('Y-m');
    $mesesLabels[] = $mesesAbreviados[(int) $ref->format('n')] . '/' . $ref->format('y');
}

$abertosPorMes = array_fill_keys($mesesChave, 0);
$stmt = $pdo->query("
    SELECT DATE_FORMAT(criado_em, '%Y-%m') AS mes, COUNT(*) AS total
    FROM chamados
    WHERE criado_em >= '" . reset($mesesChave) . "-01'
    GROUP BY mes
");
foreach ($stmt->fetchAll() as $linha) {
    if (isset($abertosPorMes[$linha['mes']])) {
        $abertosPorMes[$linha['mes']] = (int) $linha['total'];
    }
}

$fechadosPorMes = array_fill_keys($mesesChave, 0);
$stmt = $pdo->query("
    SELECT DATE_FORMAT(fechado_em, '%Y-%m') AS mes, COUNT(*) AS total
    FROM chamados
    WHERE fechado_em IS NOT NULL AND fechado_em >= '" . reset($mesesChave) . "-01'
    GROUP BY mes
");
foreach ($stmt->fetchAll() as $linha) {
    if (isset($fechadosPorMes[$linha['mes']])) {
        $fechadosPorMes[$linha['mes']] = (int) $linha['total'];
    }
}

// Tempo médio de atendimento por mês (só entra no gráfico quem tem
// fechado_em -- meses sem nenhum chamado fechado ficam com null, viram um
// "buraco" no gráfico em vez de aparecerem como zero, o que passaria a
// impressão errada de atendimento instantâneo.
$tempoMedioPorMes = array_fill_keys($mesesChave, null);
$stmt = $pdo->query("
    SELECT DATE_FORMAT(fechado_em, '%Y-%m') AS mes, AVG(TIMESTAMPDIFF(MINUTE, criado_em, fechado_em)) AS media
    FROM chamados
    WHERE fechado_em IS NOT NULL AND fechado_em >= '" . reset($mesesChave) . "-01'
    GROUP BY mes
");
foreach ($stmt->fetchAll() as $linha) {
    if (array_key_exists($linha['mes'], $tempoMedioPorMes)) {
        $tempoMedioPorMes[$linha['mes']] = round(((float) $linha['media']) / 60, 1); // em horas, 1 casa decimal
    }
}
$temDadosTempoAtendimento = count(array_filter($tempoMedioPorMes, fn ($v) => $v !== null)) > 0;

// --- Chamados PENDENTES agora, por status e por prioridade ----------------

$statusMapaLabel = [
    'aberto' => 'Aberto',
    'aguardando_equipe' => 'Aguardando nós',
    'aguardando_cliente' => 'Aguardando cliente',
];
// Mesmas cores já usadas nos badges de status em todo o resto do sistema
// (ver .badge-aberto/.badge-aguardando-equipe/.badge-aguardando-cliente em
// style.css) -- o gráfico usa a MESMA linguagem visual, não uma paleta nova.
$statusMapaCor = [
    'aberto' => '#1d4ed8',
    'aguardando_equipe' => '#be123c',
    'aguardando_cliente' => '#6d28d9',
];
$statusPendentesPorTipo = array_fill_keys(array_keys($statusMapaLabel), 0);
$stmt = $pdo->query("SELECT status, COUNT(*) AS total FROM chamados WHERE status != 'fechado' GROUP BY status");
foreach ($stmt->fetchAll() as $linha) {
    if (isset($statusPendentesPorTipo[$linha['status']])) {
        $statusPendentesPorTipo[$linha['status']] = (int) $linha['total'];
    }
}

$prioridadeMapaLabel = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];
// Mesmas cores de badge_prioridade()/opcoes_prioridade_html() (helpers.php).
$prioridadeMapaCor = ['baixa' => '#15803d', 'media' => '#b45309', 'alta' => '#be123c', 'urgente' => '#b91c1c'];
$prioridadePendentesPorTipo = array_fill_keys(array_keys($prioridadeMapaLabel), 0);
$stmt = $pdo->query("SELECT prioridade, COUNT(*) AS total FROM chamados WHERE status != 'fechado' GROUP BY prioridade");
foreach ($stmt->fetchAll() as $linha) {
    if (isset($prioridadePendentesPorTipo[$linha['prioridade']])) {
        $prioridadePendentesPorTipo[$linha['prioridade']] = (int) $linha['total'];
    }
}
$totalPendentesAgora = array_sum($statusPendentesPorTipo);

// --- Por cliente: volume do mês, total, fechados, pendentes, tempo médio,
//     e idade média dos pendentes (mesma ideia de "atraso" das métricas de
//     usuário, agora por cliente) --------------------------------------

$clientesDetalhe = $pdo->query("
    SELECT
        c.id, c.nome, c.logo,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.criado_em >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS chamados_mes,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id) AS chamados_total,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status = 'fechado') AS chamados_fechados,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status != 'fechado') AS chamados_pendentes,
        (SELECT AVG(TIMESTAMPDIFF(MINUTE, ch.criado_em, ch.fechado_em)) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.fechado_em IS NOT NULL) AS tempo_medio_min,
        (SELECT AVG(DATEDIFF(NOW(), ch.criado_em)) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status != 'fechado') AS idade_media_pendentes_dias
    FROM clientes c
    WHERE c.status = 'ativo'
    ORDER BY chamados_mes DESC, chamados_total DESC
")->fetchAll();

// Só entra no gráfico de "top clientes do mês" quem de fato abriu chamado
// esse mês -- cliente parado não precisa ocupar espaço no gráfico.
$clientesComMovimentoMes = array_values(array_filter($clientesDetalhe, fn ($c) => (int) $c['chamados_mes'] > 0));
usort($clientesComMovimentoMes, fn ($a, $b) => $b['chamados_mes'] <=> $a['chamados_mes']);
$topClientesMes = array_slice($clientesComMovimentoMes, 0, 8);

// Ranking de clientes que precisam de mais atenção -- só chamados de
// suporte (demandas foi removido, era duplicado do mesmo conceito).
$sqlAtencao = "
    SELECT
        c.id, c.nome, c.status, c.logo,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status IN ('aberto','aguardando_equipe')) AS chamados_aguardando,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id AND ch.status IN ('aberto','aguardando_equipe') AND ch.prioridade IN ('alta','urgente')) AS chamados_urgentes
    FROM clientes c
    WHERE c.status = 'ativo'
";
$clientesAtencao = $pdo->query($sqlAtencao)->fetchAll();

foreach ($clientesAtencao as &$c) {
    $c['pontuacao'] = $c['chamados_aguardando'] * 3 + $c['chamados_urgentes'] * 3;
}
unset($c);

$clientesAtencao = array_filter($clientesAtencao, fn ($c) => $c['pontuacao'] > 0);
usort($clientesAtencao, fn ($a, $b) => $b['pontuacao'] <=> $a['pontuacao']);
$clientesAtencao = array_slice($clientesAtencao, 0, 10);

$tituloPagina = 'Dashboard';
require __DIR__ . '/templates/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-4">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Clientes ativos</div>
                <div class="stat-value text-dark"><?= $totalClientesAtivos ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Chamados aguardando nós</div>
                <div class="stat-value text-primary"><?= $totalChamadosAguardandoNos ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Chamados urgentes / alta prioridade</div>
                <div class="stat-value text-danger"><?= $totalChamadosUrgentes ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Chamados abertos (mês)</div>
                <div class="stat-value text-dark"><?= $chamadosMesAtual ?></div>
                <?= tile_delta_html($chamadosMesAtual, $chamadosMesPassado) ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Chamados fechados (mês)</div>
                <div class="stat-value text-dark"><?= $fechadosMesAtual ?></div>
                <?= tile_delta_html($fechadosMesAtual, $fechadosMesPassado) ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Tempo médio de atendimento</div>
                <div class="stat-value text-dark"><?= e(formatar_duracao_minutos($tempoMedioMinutos)) ?></div>
                <div class="small text-muted mt-1">Chamados fechados nos últimos 30 dias</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body">
                <div class="text-muted small">Tempo médio em aberto</div>
                <div class="stat-value text-dark"><?= $idadeMediaDias !== null ? number_format($idadeMediaDias, 1, ',', '.') . ' dias' : '--' ?></div>
                <div class="small text-muted mt-1">Chamados pendentes agora</div>
            </div>
        </div>
    </div>
</div>

<h5 class="mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-graph-up-arrow text-primary"></i> Indicadores e tendências
</h5>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card card-stat h-100">
            <div class="card-header bg-white fw-bold">Volume de chamados -- últimos 6 meses</div>
            <div class="card-body">
                <div class="chart-wrap"><canvas id="graficoVolume"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card card-stat h-100">
            <div class="card-header bg-white fw-bold">Chamados pendentes por status</div>
            <div class="card-body">
                <?php if ($totalPendentesAgora > 0): ?>
                    <div class="chart-wrap chart-wrap-sm"><canvas id="graficoStatus"></canvas></div>
                <?php else: ?>
                    <p class="text-muted text-center py-5 mb-0">Nenhum chamado pendente agora. 🎉</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card card-stat h-100">
            <div class="card-header bg-white fw-bold">Chamados pendentes por prioridade</div>
            <div class="card-body">
                <?php if ($totalPendentesAgora > 0): ?>
                    <div class="chart-wrap chart-wrap-sm"><canvas id="graficoPrioridade"></canvas></div>
                <?php else: ?>
                    <p class="text-muted text-center py-5 mb-0">Nenhum chamado pendente agora. 🎉</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card card-stat h-100">
            <div class="card-header bg-white fw-bold">Tempo médio de atendimento -- últimos 6 meses</div>
            <div class="card-body">
                <?php if ($temDadosTempoAtendimento): ?>
                    <div class="chart-wrap"><canvas id="graficoTempoMedio"></canvas></div>
                <?php else: ?>
                    <p class="text-muted text-center py-5 mb-0">
                        <i class="bi bi-info-circle"></i> Ainda não há chamados fechados o suficiente pra
                        montar essa tendência -- passa a aparecer conforme os próximos forem encerrados.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($topClientesMes): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card card-stat">
            <div class="card-header bg-white fw-bold">Clientes com mais chamados este mês</div>
            <div class="card-body">
                <div class="chart-wrap chart-wrap-bar-h" style="height: <?= 42 * count($topClientesMes) + 20 ?>px;">
                    <canvas id="graficoTopClientes"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<h5 class="mb-2 d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill text-danger"></i> Clientes que precisam de mais atenção
</h5>
<p class="text-muted small mb-3">
    Ordenado do mais urgente pro menos urgente. Clique num cliente pra ir direto pros chamados que
    são pendência nossa (já filtrado e ordenado do mais urgente/atrasado primeiro).
</p>

<?php if (empty($clientesAtencao)): ?>
    <div class="card card-stat">
        <p class="text-muted p-3 mb-0">Nenhum cliente com chamado em aberto no momento. 🎉</p>
    </div>
<?php else: ?>
<div class="grid-clientes mb-4">
    <?php foreach ($clientesAtencao as $c): ?>
        <a href="<?= url('cliente.php?id=' . $c['id'] . '#chamados') ?>"
           class="card-cliente <?= $c['chamados_urgentes'] > 0 ? 'cliente-atencao' : '' ?>">
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
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h5 class="mb-2 d-flex align-items-center gap-2">
    <i class="bi bi-table text-primary"></i> Atendimento por cliente
</h5>
<p class="text-muted small mb-3">Mesmos números dos gráficos acima, detalhados por cliente.</p>

<div class="card card-stat mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th class="text-end">Chamados (mês)</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Pendentes</th>
                    <th class="text-end">Fechados</th>
                    <th class="text-end">Tempo médio de atendimento</th>
                    <th class="text-end">Tempo médio em aberto</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clientesDetalhe as $c): ?>
                <tr>
                    <td class="fw-semibold"><a href="<?= url('cliente.php?id=' . $c['id']) ?>" class="text-dark text-decoration-none"><?= e($c['nome']) ?></a></td>
                    <td class="text-end"><?= (int) $c['chamados_mes'] ?></td>
                    <td class="text-end"><?= (int) $c['chamados_total'] ?></td>
                    <td class="text-end"><?= (int) $c['chamados_pendentes'] ?></td>
                    <td class="text-end"><?= (int) $c['chamados_fechados'] ?></td>
                    <td class="text-end"><?= e(formatar_duracao_minutos($c['tempo_medio_min'] !== null ? (float) $c['tempo_medio_min'] : null)) ?></td>
                    <td class="text-end"><?= $c['idade_media_pendentes_dias'] !== null ? number_format((float) $c['idade_media_pendentes_dias'], 1, ',', '.') . ' dias' : '--' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($clientesDetalhe)): ?>
                <tr><td colspan="7" class="text-muted text-center py-4">Nenhum cliente ativo cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    // Paleta -- reaproveita as MESMAS cores já usadas nos badges/selects do
    // resto do sistema (status e prioridade, ver style.css e
    // badge_prioridade() em helpers.php), pra não inventar uma segunda
    // linguagem visual só pro dashboard. Validada com o script de
    // acessibilidade de cor (contraste + separação pra daltonismo) antes de
    // usar aqui -- ver skill de dataviz.
    var corAbertos = '#4f46e5';   // --ph-primary
    var corFechados = '#15803d';  // --ph-success
    var corTempoMedio = '#0891b2'; // --ph-accent
    var corTexto = '#52525b';
    var corGrid = '#e4e4e7';

    Chart.defaults.font.family = "'Roboto', sans-serif";
    Chart.defaults.color = corTexto;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.boxHeight = 8;

    // --- Volume mensal (abertos vs fechados) ---
    var elVolume = document.getElementById('graficoVolume');
    if (elVolume) {
        new Chart(elVolume, {
            type: 'bar',
            data: {
                labels: <?= json_encode($mesesLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    { label: 'Abertos', data: <?= json_encode(array_values($abertosPorMes)) ?>, backgroundColor: corAbertos, borderRadius: 4, maxBarThickness: 22 },
                    { label: 'Fechados', data: <?= json_encode(array_values($fechadosPorMes)) ?>, backgroundColor: corFechados, borderRadius: 4, maxBarThickness: 22 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: corGrid } }
                }
            }
        });
    }

    // --- Status dos pendentes (donut) ---
    var elStatus = document.getElementById('graficoStatus');
    if (elStatus) {
        new Chart(elStatus, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_values($statusMapaLabel), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($statusPendentesPorTipo)) ?>,
                    backgroundColor: <?= json_encode(array_values($statusMapaCor)) ?>,
                    borderColor: '#fff', borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    // --- Prioridade dos pendentes (barra horizontal, ordem de severidade) ---
    var elPrioridade = document.getElementById('graficoPrioridade');
    if (elPrioridade) {
        new Chart(elPrioridade, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_values($prioridadeMapaLabel), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($prioridadePendentesPorTipo)) ?>,
                    backgroundColor: <?= json_encode(array_values($prioridadeMapaCor)) ?>,
                    borderRadius: 4, maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: corGrid } },
                    y: { grid: { display: false } }
                }
            }
        });
    }

    // --- Tempo médio de atendimento por mês (linha, em horas) ---
    var elTempoMedio = document.getElementById('graficoTempoMedio');
    if (elTempoMedio) {
        new Chart(elTempoMedio, {
            type: 'line',
            data: {
                labels: <?= json_encode($mesesLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    label: 'Tempo médio (horas)',
                    data: <?= json_encode(array_values($tempoMedioPorMes)) ?>,
                    borderColor: corTempoMedio, backgroundColor: corTempoMedio + '1a',
                    borderWidth: 2, pointRadius: 4, pointBackgroundColor: corTempoMedio,
                    pointBorderColor: '#fff', pointBorderWidth: 2,
                    tension: .3, fill: true, spanGaps: false
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: corGrid }, ticks: { callback: function (v) { return v + 'h'; } } }
                }
            }
        });
    }

    // --- Top clientes do mês (barra horizontal, série única) ---
    var elTopClientes = document.getElementById('graficoTopClientes');
    if (elTopClientes) {
        new Chart(elTopClientes, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($topClientesMes, 'nome'), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    data: <?= json_encode(array_map('intval', array_column($topClientesMes, 'chamados_mes'))) ?>,
                    backgroundColor: corAbertos, borderRadius: 4, maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: corGrid } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
