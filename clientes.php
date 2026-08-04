<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireLogin();

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar_cliente') {
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
    }

    if ($acao === 'atualizar_cliente') {
        $clienteId = (int) $_POST['cliente_id'];
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

    if ($acao === 'excluir_cliente') {
        $clienteId = (int) $_POST['cliente_id'];
        $stmt = $pdo->prepare('SELECT nome FROM clientes WHERE id = ?');
        $stmt->execute([$clienteId]);
        $nomeAtual = $stmt->fetchColumn();

        // Confirmação por nome digitado -- exclusão é irreversível e
        // carrega junto (ON DELETE CASCADE) todas as demandas, KPIs,
        // documentos, treinamentos e chamados desse cliente.
        if (!$nomeAtual) {
            flash_set('erro', 'Cliente não encontrado.');
        } elseif (trim($_POST['confirmar_nome'] ?? '') !== $nomeAtual) {
            flash_set('erro', 'O nome digitado não confere. Nada foi excluído.');
        } else {
            $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$clienteId]);
            flash_set('sucesso', 'Cliente "' . $nomeAtual . '" e todos os registros vinculados foram excluídos.');
        }
    }

    header('Location: ' . url('clientes.php'));
    exit;
}

$sql = "
    SELECT
        c.*,
        (SELECT COUNT(*) FROM demandas d WHERE d.cliente_id = c.id AND d.status IN ('aberta','em_andamento')) AS demandas_abertas,
        (SELECT COUNT(*) FROM demandas d WHERE d.cliente_id = c.id AND d.status IN ('aberta','em_andamento') AND d.prioridade = 'urgente') AS urgentes,
        (SELECT COUNT(*) FROM demandas d WHERE d.cliente_id = c.id) AS total_demandas,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.cliente_id = c.id) AS total_chamados,
        (SELECT COUNT(*) FROM documentos doc WHERE doc.cliente_id = c.id) AS total_documentos,
        (SELECT COUNT(*) FROM kpis k WHERE k.cliente_id = c.id) AS total_kpis,
        (SELECT COUNT(*) FROM treinamentos t WHERE t.cliente_id = c.id) AS total_treinamentos
    FROM clientes c
    ORDER BY c.nome ASC
";
$clientes = $pdo->query($sql)->fetchAll();

$tituloPagina = 'Clientes';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-3">Clientes</h4>
<p class="text-muted small mb-2"><i class="bi bi-info-circle"></i> Clique em um cliente da lista para visualizar, alterar ou excluir.</p>

<div class="toolbar">
    <div class="toolbar-acoes">
        <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoCliente">
            <i class="bi bi-plus-lg"></i> Novo cliente
        </button>
    </div>
    <div class="toolbar-filtros">
        <input type="text" class="form-control form-control-sm busca-toolbar" id="buscaClientes" placeholder="Buscar por nome ou contato...">
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
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr data-busca="<?= e(mb_strtolower($c['nome'] . ' ' . $c['contato_nome'] . ' ' . $c['contato_email'])) ?>"
                        class="linha-cliente" role="button" tabindex="0"
                        data-cliente='<?= e(json_encode($c, JSON_UNESCAPED_UNICODE)) ?>'>
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

<!-- Modal compartilhado: Visualizar / Alterar / Excluir (preenchido via JS ao clicar numa linha) -->
<div class="modal fade" id="modalCliente" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalClienteTitulo">Cliente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <ul class="nav nav-tabs px-3 pt-2" id="modalClienteTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#modalClienteVer">Visualizar</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#modalClienteAlterar">Alterar</a></li>
        <li class="nav-item"><a class="nav-link text-danger" data-bs-toggle="tab" href="#modalClienteExcluir">Excluir</a></li>
      </ul>
      <div class="modal-body">
        <div class="tab-content">

            <!-- Visualizar -->
            <div class="tab-pane show active" id="modalClienteVer">
                <dl class="row mb-0">
                    <dt class="col-4">Status</dt><dd class="col-8" id="verStatus"></dd>
                    <dt class="col-4">Contato</dt><dd class="col-8" id="verContatoNome"></dd>
                    <dt class="col-4">E-mail</dt><dd class="col-8" id="verContatoEmail"></dd>
                    <dt class="col-4">Telefone</dt><dd class="col-8" id="verContatoTelefone"></dd>
                    <dt class="col-4">Observações</dt><dd class="col-8" id="verObservacoes"></dd>
                    <dt class="col-4">Demandas</dt><dd class="col-8" id="verDemandas"></dd>
                    <dt class="col-4">Chamados</dt><dd class="col-8" id="verChamados"></dd>
                </dl>
                <a href="#" id="verLinkAbrir" class="btn btn-dark mt-2"><i class="bi bi-box-arrow-up-right"></i> Abrir página completa do cliente</a>
            </div>

            <!-- Alterar -->
            <div class="tab-pane" id="modalClienteAlterar">
                <form method="post" id="formAlterarCliente">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_cliente">
                    <input type="hidden" name="cliente_id" id="alterarId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome *</label>
                            <input type="text" name="nome" id="alterarNome" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="alterarStatus" class="form-select">
                                <option value="ativo">Ativo</option>
                                <option value="pausado">Pausado</option>
                                <option value="encerrado">Encerrado</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nome do contato</label>
                            <input type="text" name="contato_nome" id="alterarContatoNome" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">E-mail do contato</label>
                            <input type="email" name="contato_email" id="alterarContatoEmail" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefone do contato</label>
                            <input type="text" name="contato_telefone" id="alterarContatoTelefone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" id="alterarObservacoes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3"><i class="bi bi-check2"></i> Salvar alterações</button>
                </form>
            </div>

            <!-- Excluir -->
            <div class="tab-pane" id="modalClienteExcluir">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i> Excluir um cliente remove <strong>permanentemente</strong> tudo o que está vinculado a ele:
                    <span id="excluirContagens"></span>. Essa ação não pode ser desfeita.
                </div>
                <form method="post" id="formExcluirCliente">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="excluir_cliente">
                    <input type="hidden" name="cliente_id" id="excluirId">
                    <div class="mb-3">
                        <label class="form-label">Pra confirmar, digite o nome do cliente (<strong id="excluirNomeEsperado"></strong>):</label>
                        <input type="text" name="confirmar_nome" id="excluirConfirmarNome" class="form-control" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-danger" id="btnConfirmarExclusao" disabled><i class="bi bi-trash"></i> Excluir cliente definitivamente</button>
                </form>
            </div>

        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var busca = document.getElementById('buscaClientes');
    if (busca) {
        busca.addEventListener('input', function () {
            var termo = busca.value.trim().toLowerCase();
            document.querySelectorAll('#tabelaClientes tbody tr[data-busca]').forEach(function (linha) {
                linha.style.display = linha.getAttribute('data-busca').includes(termo) ? '' : 'none';
            });
        });
    }

    var modalCliente = document.getElementById('modalCliente');
    var baseUrlCliente = <?= json_encode(url('cliente.php?id=')) ?>;

    function abrirModalCliente(dados) {
        document.getElementById('modalClienteTitulo').textContent = dados.nome;

        document.getElementById('verStatus').textContent = dados.status.charAt(0).toUpperCase() + dados.status.slice(1);
        document.getElementById('verContatoNome').textContent = dados.contato_nome || '-';
        document.getElementById('verContatoEmail').textContent = dados.contato_email || '-';
        document.getElementById('verContatoTelefone').textContent = dados.contato_telefone || '-';
        document.getElementById('verObservacoes').textContent = dados.observacoes || '-';
        document.getElementById('verDemandas').textContent = dados.total_demandas + ' no total (' + dados.demandas_abertas + ' aberta(s))';
        document.getElementById('verChamados').textContent = dados.total_chamados + ' no total';
        document.getElementById('verLinkAbrir').href = baseUrlCliente + dados.id;

        document.getElementById('alterarId').value = dados.id;
        document.getElementById('alterarNome').value = dados.nome;
        document.getElementById('alterarStatus').value = dados.status;
        document.getElementById('alterarContatoNome').value = dados.contato_nome || '';
        document.getElementById('alterarContatoEmail').value = dados.contato_email || '';
        document.getElementById('alterarContatoTelefone').value = dados.contato_telefone || '';
        document.getElementById('alterarObservacoes').value = dados.observacoes || '';

        document.getElementById('excluirId').value = dados.id;
        document.getElementById('excluirNomeEsperado').textContent = dados.nome;
        document.getElementById('excluirConfirmarNome').value = '';
        document.getElementById('btnConfirmarExclusao').disabled = true;
        var partes = [];
        if (dados.total_demandas > 0) partes.push(dados.total_demandas + ' demanda(s)');
        if (dados.total_chamados > 0) partes.push(dados.total_chamados + ' chamado(s)');
        if (dados.total_documentos > 0) partes.push(dados.total_documentos + ' documento(s)');
        if (dados.total_kpis > 0) partes.push(dados.total_kpis + ' KPI(s)');
        if (dados.total_treinamentos > 0) partes.push(dados.total_treinamentos + ' treinamento(s)');
        document.getElementById('excluirContagens').textContent = partes.length ? partes.join(', ') : 'nenhum registro (nada mais além do cadastro dele)';

        // Sempre volta pra aba "Visualizar" ao abrir.
        PrimeUI.showTab(document.querySelector('#modalClienteTabs a[href="#modalClienteVer"]'));

        PrimeUI.openModal(modalCliente);
    }

    document.querySelectorAll('.linha-cliente').forEach(function (linha) {
        linha.addEventListener('click', function () {
            abrirModalCliente(JSON.parse(linha.getAttribute('data-cliente')));
        });
        linha.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') linha.click();
        });
    });

    var campoConfirmarNome = document.getElementById('excluirConfirmarNome');
    campoConfirmarNome.addEventListener('input', function () {
        var nomeEsperado = document.getElementById('excluirNomeEsperado').textContent;
        document.getElementById('btnConfirmarExclusao').disabled = (campoConfirmarNome.value !== nomeEsperado);
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
