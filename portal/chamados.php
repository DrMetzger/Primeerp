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
        // Título padronizado em MAIÚSCULAS -- pedido do Kleber pra não ter
        // cada chamado com uma capitalização diferente ("Erro no sistema",
        // "ERRO NO SISTEMA", "erro no sistema" tudo misturado na listagem).
        $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            mb_strtoupper($titulo, 'UTF-8'),
            $descricao,
            trim($_POST['categoria'] ?? '') ?: null,
            $_POST['prioridade'] ?? 'media',
            'cliente',
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

        Notificacoes::novoChamado($chamado, $cliente);

        flash_set('sucesso', 'Chamado aberto com sucesso! Nossa equipe já foi avisada.');
    }

    header('Location: ' . url('portal/chamados.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM chamados WHERE cliente_id = ? ORDER BY criado_em DESC');
$stmt->execute([$clienteId]);
$chamados = $stmt->fetchAll();

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

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="aberto">
            <div class="card-body">
                <div class="text-muted small">Abertos</div>
                <div class="stat-value text-primary"><?= $totalAbertos ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="aguardando_equipe">
            <div class="card-body">
                <div class="text-muted small">Pendentes (com a PrimeERP)</div>
                <div class="stat-value text-danger"><?= $totalPendentes ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="aguardando_cliente">
            <div class="card-body">
                <div class="text-muted small">Atrasados (com você)</div>
                <div class="stat-value text-warning"><?= $totalAtrasados ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="#" class="card card-stat h-100 d-block filtro-card" data-filtro="fechado">
            <div class="card-body">
                <div class="text-muted small">Encerrados</div>
                <div class="stat-value text-dark"><?= $totalEncerrados ?></div>
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
                <tr data-status="<?= e($c['status']) ?>">
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
                    <?= opcoes_prioridade_html('media') ?>
                </select>
            </div>
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
            <p class="text-muted small mb-0">O arquivo abre direto no Excel.</p>
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

    function aplicarFiltro(filtro) {
        filtroAtual = filtro;
        var algumVisivel = false;
        document.querySelectorAll('#tabelaMeusChamados tbody tr[data-status]').forEach(function (linha) {
            var mostra = filtro === 'todos' || linha.getAttribute('data-status') === filtro;
            linha.style.display = mostra ? '' : 'none';
            if (mostra) algumVisivel = true;
        });
        document.getElementById('avisoSemChamadoFiltro').style.display = algumVisivel ? 'none' : '';
        document.querySelectorAll('#filtroStatusPortal .filtro-pill').forEach(function (pill) {
            pill.classList.toggle('active', pill.getAttribute('data-filtro') === filtro);
        });
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
});
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
