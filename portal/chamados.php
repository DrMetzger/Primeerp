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
    $categoria = trim($_POST['categoria'] ?? '');

    // Quem abre é o login de um setor -- o chamado já nasce vinculado a esse
    // setor, sem depender do que vier do POST (não dá pra confiar em input
    // escondido pra isso). Quem abre é o master, pode escolher um setor
    // (opcional) entre os cadastrados desse cliente, senão fica sem setor.
    if (!ClienteAuth::isMaster()) {
        $setorIdNovoChamado = ClienteAuth::setorId();
    } else {
        $setorIdNovoChamado = null;
        if (!empty($_POST['setor_id'])) {
            $confereSetor = $pdo->prepare('SELECT id FROM setores WHERE id = ? AND cliente_id = ?');
            $confereSetor->execute([(int) $_POST['setor_id'], $clienteId]);
            $setorIdNovoChamado = $confereSetor->fetchColumn() ?: null;
        }
    }

    if ($titulo === '' || $descricao === '' || $categoria === '') {
        flash_set('erro', 'Preencha o título, a categoria e a descrição do chamado.');
    } else {
        // Título padronizado em MAIÚSCULAS -- pedido do Kleber pra não ter
        // cada chamado com uma capitalização diferente ("Erro no sistema",
        // "ERRO NO SISTEMA", "erro no sistema" tudo misturado na listagem).
        $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, aberto_por_tipo, aberto_por_setor_responsavel_id, setor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            mb_strtoupper($titulo, 'UTF-8'),
            $descricao,
            $categoria,
            $_POST['prioridade'] ?? 'media',
            'cliente',
            ClienteAuth::isMaster() ? 'master' : 'setor',
            ClienteAuth::isMaster() ? null : ClienteAuth::setorResponsavelId(),
            $setorIdNovoChamado,
        ]);
        $chamadoId = (int) $pdo->lastInsertId();

        $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, null, 'cliente');
        foreach ($errosAnexo as $erroAnexo) {
            flash_set('erro', $erroAnexo);
        }

        $chamado = $pdo->query("SELECT * FROM chamados WHERE id = $chamadoId")->fetch();
        $cliente = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $cliente->execute([$clienteId]);
        $cliente = $cliente->fetch();

        Notificacoes::novoChamado($pdo, $chamado, $cliente);

        flash_set('sucesso', 'Chamado aberto com sucesso! Nossa equipe já foi avisada.');
    }

    header('Location: ' . url('portal/chamados.php'));
    exit;
}

// Login de setor só vê os chamados do PRÓPRIO setor (independência entre
// setores é o pedido original) -- login master continua vendo tudo, de
// todos os setores, igual sempre foi.
if (ClienteAuth::isMaster()) {
    $stmt = $pdo->prepare('SELECT * FROM chamados WHERE cliente_id = ? ORDER BY criado_em DESC');
    $stmt->execute([$clienteId]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM chamados WHERE cliente_id = ? AND setor_id = ? ORDER BY criado_em DESC');
    $stmt->execute([$clienteId, ClienteAuth::setorId()]);
}
$chamados = $stmt->fetchAll();

// Painel de cards por setor -- só existe pro login MASTER (quem já vê tudo)
// e só aparece se esse cliente tiver algum setor cadastrado. Mesma ideia
// (e mesmas subconsultas de insight) do painel espelho em cliente.php.
$setoresPortal = [];
$responsaveisPorSetorIdPortal = [];
if (ClienteAuth::isMaster()) {
    $setoresPortal = $pdo->prepare('
        SELECT
            s.*,
            (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id) AS chamados_total,
            (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status IN ("aberto","aguardando_equipe")) AS chamados_pendentes,
            (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status IN ("aberto","aguardando_equipe") AND ch.prioridade IN ("alta","urgente")) AS chamados_urgentes,
            (SELECT AVG(TIMESTAMPDIFF(MINUTE, ch.criado_em, ch.fechado_em)) FROM chamados ch WHERE ch.setor_id = s.id AND ch.fechado_em IS NOT NULL) AS tempo_medio_min
        FROM setores s
        WHERE s.cliente_id = ? AND s.ativo = 1
        ORDER BY s.nome ASC
    ');
    $setoresPortal->execute([$clienteId]);
    $setoresPortal = $setoresPortal->fetchAll();

    // Setor pode ter vários responsáveis -- agrupa por setor_id, mesma
    // ideia de cliente.php. Master "participante" de um setor entra como
    // um responsável virtual (sem id de setor_responsaveis).
    if ($setoresPortal) {
        $idsSetoresPortal = array_column($setoresPortal, 'id');
        $placeholdersSetoresPortal = implode(',', array_fill(0, count($idsSetoresPortal), '?'));
        $stmtRespPortal = $pdo->prepare("SELECT * FROM setor_responsaveis WHERE setor_id IN ($placeholdersSetoresPortal) ORDER BY nome ASC");
        $stmtRespPortal->execute($idsSetoresPortal);
        foreach ($stmtRespPortal->fetchAll() as $sr) {
            $responsaveisPorSetorIdPortal[$sr['setor_id']][] = $sr;
        }
        $stmtClienteInfoPortal = $pdo->prepare('SELECT contato_nome, contato_email, nome, foto_perfil, setor_id FROM clientes WHERE id = ?');
        $stmtClienteInfoPortal->execute([$clienteId]);
        $clienteInfoPortal = $stmtClienteInfoPortal->fetch();
        foreach ($setoresPortal as $s) {
            if ((int) $clienteInfoPortal['setor_id'] === (int) $s['id']) {
                $responsaveisPorSetorIdPortal[$s['id']] ??= [];
                array_unshift($responsaveisPorSetorIdPortal[$s['id']], [
                    'nome' => $clienteInfoPortal['contato_nome'] ?: $clienteInfoPortal['nome'],
                    'email' => $clienteInfoPortal['contato_email'],
                    'foto_perfil' => resolver_avatar_por_email($pdo, $clienteInfoPortal['contato_email'], $clienteInfoPortal['foto_perfil']),
                ]);
            }
        }
    }
}
$chamadosSemSetorPortal = ClienteAuth::isMaster() ? count(array_filter($chamados, fn ($c) => $c['setor_id'] === null)) : 0;

// Visão geral em 4 cards -- mapeado 1:1 nos 4 status reais que já existem
// (nenhum "atrasado" novo inventado): Abertos = ninguém respondeu ainda,
// Pendentes = já respondemos e a bola tá com a NOSSA equipe de novo,
// Atrasados = a bola tá com o CLIENTE (ele que está devendo resposta),
// Encerrados = fechado. Clicar num card filtra a lista abaixo.
$totalAbertos = count(array_filter($chamados, fn ($c) => $c['status'] === 'aberto'));
$totalPendentes = count(array_filter($chamados, fn ($c) => $c['status'] === 'aguardando_equipe'));
$totalAtrasados = count(array_filter($chamados, fn ($c) => $c['status'] === 'aguardando_cliente'));
$totalEncerrados = count(array_filter($chamados, fn ($c) => $c['status'] === 'fechado'));

$tituloPagina = 'Meus Chamados';
require __DIR__ . '/../templates/portal_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Meus chamados</h4>
    <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoChamado"><i class="bi bi-plus-lg"></i> Abrir chamado</button>
</div>

<?php if ($setoresPortal): ?>
<div class="painel-setores mb-4">
    <p class="text-muted small mb-2"><i class="bi bi-diagram-3"></i> Sua empresa tem setores independentes -- clique num card pra ver só os chamados e o responsável daquele setor.</p>
    <div class="grid-setores">
        <div class="card-setor card-setor-todos active" data-setor-filtro="todos" role="button" tabindex="0">
            <div class="card-setor-topo"><i class="bi bi-collection"></i> <span class="fw-semibold">Todos os setores</span></div>
        </div>
        <?php foreach ($setoresPortal as $s): ?>
            <div class="card-setor <?= (int) $s['chamados_urgentes'] > 0 ? 'setor-atencao' : '' ?>" data-setor-filtro="<?= $s['id'] ?>" role="button" tabindex="0">
                <div class="card-setor-topo"><span class="fw-semibold"><?= e($s['nome']) ?></span></div>
                <div class="card-setor-responsavel">
                    <?php $membrosSetorPortal = $responsaveisPorSetorIdPortal[$s['id']] ?? []; ?>
                    <?php if ($membrosSetorPortal): ?>
                        <div class="card-setor-responsavel-avatares">
                            <?php foreach (array_slice($membrosSetorPortal, 0, 3) as $m): ?>
                                <?= avatar_html($m['nome'], resolver_avatar_por_email($pdo, $m['email'], $m['foto_perfil']), 'cliente') ?>
                            <?php endforeach; ?>
                        </div>
                        <span><?= e($membrosSetorPortal[0]['nome']) ?><?= count($membrosSetorPortal) > 1 ? ' e mais ' . (count($membrosSetorPortal) - 1) : '' ?></span>
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
        <?php if ($chamadosSemSetorPortal > 0): ?>
            <div class="card-setor" data-setor-filtro="sem-setor" role="button" tabindex="0">
                <div class="card-setor-topo"><span class="fw-semibold text-muted">Sem setor</span></div>
                <div class="card-setor-insights"><span class="badge modulo-pill modulo-neutro"><?= $chamadosSemSetorPortal ?> chamado<?= $chamadosSemSetorPortal !== 1 ? 's' : '' ?></span></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="aberto">
            <div class="card-body">
                <div class="text-muted small">Abertos</div>
                <div class="stat-value text-primary" id="kpiAbertos"><?= $totalAbertos ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="aguardando_equipe">
            <div class="card-body">
                <div class="text-muted small">Pendentes (com a PrimeERP)</div>
                <div class="stat-value text-danger" id="kpiPendentes"><?= $totalPendentes ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="aguardando_cliente">
            <div class="card-body">
                <div class="text-muted small">Atrasados (com você)</div>
                <div class="stat-value text-warning" id="kpiAtrasados"><?= $totalAtrasados ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="fechado">
            <div class="card-body">
                <div class="text-muted small">Encerrados</div>
                <div class="stat-value text-dark" id="kpiEncerrados"><?= $totalEncerrados ?></div>
            </div>
        </a>
    </div>
</div>

<div class="toolbar">
    <div class="toolbar-filtros">
        <div class="btn-group flex-wrap" role="group" id="filtroStatusPortal">
            <button type="button" class="btn btn-sm filtro-pill filtro-todos active" data-filtro="todos"><i class="bi bi-collection"></i> Todos</button>
            <button type="button" class="btn btn-sm filtro-pill filtro-aberto" data-filtro="aberto"><i class="bi bi-envelope-open"></i> Abertos</button>
            <button type="button" class="btn btn-sm filtro-pill filtro-aguardando_equipe" data-filtro="aguardando_equipe"><i class="bi bi-exclamation-circle-fill"></i> Pendentes</button>
            <button type="button" class="btn btn-sm filtro-pill filtro-aguardando_cliente" data-filtro="aguardando_cliente"><i class="bi bi-hourglass-split"></i> Atrasados</button>
            <button type="button" class="btn btn-sm filtro-pill filtro-fechado" data-filtro="fechado"><i class="bi bi-x-circle"></i> Encerrados</button>
        </div>
    </div>
    <button type="button" class="btn btn-sm btn-exportar" data-bs-toggle="modal" data-bs-target="#modalExportarChamadosPortal">
        <i class="bi bi-file-earmark-excel"></i> Exportar
    </button>
</div>

<div class="card card-stat">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="tabelaMeusChamados">
            <thead><tr><th>Título</th><th>Status</th><th>Prioridade</th><th>Aberto em</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($chamados as $c): ?>
                <tr data-status="<?= e($c['status']) ?>" data-setor="<?= $c['setor_id'] ? (int) $c['setor_id'] : 'sem-setor' ?>">
                    <td class="fw-semibold"><?= e($c['titulo']) ?></td>
                    <td><?= badge_status_chamado_portal($c['status']) ?></td>
                    <td><?= badge_prioridade($c['prioridade']) ?></td>
                    <td><?= formatar_data($c['criado_em']) ?></td>
                    <td class="text-end"><a href="<?= url('portal/chamado.php?id=' . $c['id']) ?>" class="btn btn-sm btn-outline-dark">Visualizar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($chamados)): ?>
                <tr><td colspan="5" class="text-muted text-center py-4">Você ainda não abriu nenhum chamado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <p id="avisoSemChamadoFiltro" class="text-muted text-center py-4 mb-0" style="display:none;">Nenhum chamado com esse status.</p>
    </div>
</div>

<div class="modal fade" id="modalNovoChamado" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_chamado">
        <div class="modal-header"><h5 class="modal-title">Abrir novo chamado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" style="text-transform:uppercase" required></div>
            <div class="mb-3"><label class="form-label">Categoria *</label>
                <select name="categoria" class="form-select" required>
                    <option value="" disabled selected>Selecione</option>
                    <?php foreach (CATEGORIAS_CHAMADO as $cat): ?>
                        <option><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Prioridade *</label>
                <select name="prioridade" class="form-select" required>
                    <?= opcoes_prioridade_html('media') ?>
                </select>
            </div>
            <?php if ($setoresPortal): ?>
            <?php $setorPadraoMasterNovoChamado = ClienteAuth::setorMasterId(); ?>
            <div class="mb-3"><label class="form-label">Setor</label>
                <select name="setor_id" class="form-select">
                    <option value="" <?= !$setorPadraoMasterNovoChamado ? 'selected' : '' ?>>Sem setor</option>
                    <?php foreach ($setoresPortal as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $setorPadraoMasterNovoChamado === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($setorPadraoMasterNovoChamado): ?>
                    <small class="text-muted">Já vem selecionado o seu setor padrão -- pode trocar pra abrir num outro setor, se for o caso.</small>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label">Descrição *</label><textarea name="descricao" class="form-control" rows="4" required></textarea></div>
            <div class="mb-3">
                <label class="form-label">Anexos (opcional)</label>
                <input type="file" name="anexos[]" class="form-control" multiple accept="image/jpeg,image/png,image/webp,application/pdf">
                <small class="text-muted">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada (imagem ou PDF).</small>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Enviar chamado</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Exportar: tela explícita pra escolher o status antes de baixar (mesmo
     esquema já usado do lado da equipe em cliente.php) -- antes era só um
     link que exportava direto o que já estava filtrado na tela, mas isso
     confundia (clicar Exportar sem antes escolher um filtro baixava TUDO,
     porque "Todos" é o filtro padrão da tela). Aqui fica explícito. -->
<div class="modal fade" id="modalExportarChamadosPortal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="get" action="<?= url('portal/exportar_chamados.php') ?>" target="_blank">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-excel"></i> Exportar meus chamados</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Quais chamados exportar?</label>
                <select name="status" id="exportarStatusPortal" class="form-select">
                    <option value="todos">Todos</option>
                    <option value="aberto">Abertos</option>
                    <option value="aguardando_equipe">Pendentes (com a PrimeERP)</option>
                    <option value="aguardando_cliente">Atrasados (com você)</option>
                    <option value="fechado">Encerrados</option>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var filtroAtual = 'todos';
    var filtroSetorAtual = 'todos';

    // Os 4 números de cima (Abertos/Pendentes/Atrasados/Encerrados) sempre
    // mostram as 4 categorias ao mesmo tempo (não seguem o pill de status
    // selecionado), mas PRECISAM seguir o setor selecionado -- clicar num
    // card de setor tem que atualizar esses números pra refletir só aquele
    // setor, senão o card mostra "Pendentes: 1" mas o número de cima continua
    // com o total de todos os setores, o que não faz sentido pro usuário.
    function atualizarKpis() {
        var contagem = {aberto: 0, aguardando_equipe: 0, aguardando_cliente: 0, fechado: 0};
        document.querySelectorAll('#tabelaMeusChamados tbody tr[data-status]').forEach(function (linha) {
            var mostraSetor = filtroSetorAtual === 'todos' || linha.getAttribute('data-setor') === filtroSetorAtual;
            if (mostraSetor && contagem.hasOwnProperty(linha.getAttribute('data-status'))) {
                contagem[linha.getAttribute('data-status')]++;
            }
        });
        document.getElementById('kpiAbertos').textContent = contagem.aberto;
        document.getElementById('kpiPendentes').textContent = contagem.aguardando_equipe;
        document.getElementById('kpiAtrasados').textContent = contagem.aguardando_cliente;
        document.getElementById('kpiEncerrados').textContent = contagem.fechado;
    }

    function aplicarFiltro(filtro) {
        filtroAtual = filtro;
        var algumVisivel = false;
        document.querySelectorAll('#tabelaMeusChamados tbody tr[data-status]').forEach(function (linha) {
            var mostraStatus = filtro === 'todos' || linha.getAttribute('data-status') === filtro;
            var mostraSetor = filtroSetorAtual === 'todos' || linha.getAttribute('data-setor') === filtroSetorAtual;
            var mostra = mostraStatus && mostraSetor;
            linha.style.display = mostra ? '' : 'none';
            if (mostra) algumVisivel = true;
        });
        document.getElementById('avisoSemChamadoFiltro').style.display = algumVisivel ? 'none' : '';
        document.querySelectorAll('#filtroStatusPortal .filtro-pill').forEach(function (pill) {
            pill.classList.toggle('active', pill.getAttribute('data-filtro') === filtro);
        });
        atualizarKpis();
    }

    // Ao abrir a tela de exportar, já vem pré-selecionado o mesmo filtro que
    // está sendo exibido na tabela agora -- só um atalho, o usuário ainda
    // escolhe explicitamente antes de baixar (pode mudar se quiser).
    var modalExportarPortal = document.getElementById('modalExportarChamadosPortal');
    if (modalExportarPortal) {
        modalExportarPortal.addEventListener('show.bs.modal', function () {
            document.getElementById('exportarStatusPortal').value = filtroAtual;
        });
    }

    document.querySelectorAll('#filtroStatusPortal .filtro-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            aplicarFiltro(pill.getAttribute('data-filtro'));
        });
    });

    // Cards de visão geral no topo também aplicam o filtro (clicar em
    // "Atrasados", por exemplo, já mostra só os chamados nesse status).
    document.querySelectorAll('.filtro-card').forEach(function (card) {
        card.addEventListener('click', function (e) {
            e.preventDefault();
            aplicarFiltro(card.getAttribute('data-filtro'));
            document.getElementById('tabelaMeusChamados').scrollIntoView({behavior: 'smooth', block: 'start'});
        });
    });

    // ?filtro=aguardando_cliente na URL já chega com o filtro certo aplicado
    // (não é usado ainda por nenhum link nosso, mas deixa a página pronta
    // pra ser linkada assim de outro lugar no futuro).
    var filtroUrl = new URLSearchParams(window.location.search).get('filtro');
    if (filtroUrl) {
        aplicarFiltro(filtroUrl);
    }

    // Cards de setor (só existem pro login master) filtram a tabela igual
    // aos pills de status -- os dois filtros combinam entre si.
    document.querySelectorAll('.card-setor').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.card-setor').forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            filtroSetorAtual = card.getAttribute('data-setor-filtro');
            aplicarFiltro(filtroAtual);
            document.getElementById('tabelaMeusChamados').scrollIntoView({behavior: 'smooth', block: 'start'});
        });
        card.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') card.click();
        });
    });
});
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
