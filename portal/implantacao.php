<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

/**
 * Visão do cliente pro checklist de Implantação que a equipe já mantém em
 * cliente.php (aba Implantação) -- pedido do Kleber: o cliente precisa
 * acompanhar as etapas e conseguir anexar os documentos dele (contrato
 * assinado, evidência de teste etc.), sem precisar mandar por chamado ou
 * e-mail. De propósito é SOMENTE LEITURA quanto às etapas em si -- criar,
 * editar, reordenar ou excluir etapa continua exclusivo da Prime em
 * cliente.php, só o anexo é que o cliente consegue mandar por aqui.
 * Restrito ao login master (implantação é da empresa como um todo, não de
 * um setor específico).
 */

ClienteAuth::requireLogin();
ClienteAuth::exigirMaster();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'upload_documento_implantacao' && !empty($_FILES['arquivos']['name'][0])) {
    csrf_verify();

    $etapaIdAnexoPortal = $_POST['etapa_id'] !== '' ? (int) $_POST['etapa_id'] : null;

    // A etapa tem que ser mesmo dessa empresa -- sem essa trava, um cliente
    // poderia anexar documento numa etapa de OUTRO cliente só trocando o
    // etapa_id no POST.
    if ($etapaIdAnexoPortal !== null) {
        $stmtConfereEtapaPortal = $pdo->prepare('SELECT 1 FROM implantacao_etapas WHERE id = ? AND cliente_id = ?');
        $stmtConfereEtapaPortal->execute([$etapaIdAnexoPortal, $clienteId]);
        if (!$stmtConfereEtapaPortal->fetchColumn()) {
            $etapaIdAnexoPortal = false;
        }
    }

    if ($etapaIdAnexoPortal === false) {
        flash_set('erro', 'Etapa inválida.');
    } else {
        $errosUploadImplantacaoPortal = salvar_documentos_implantacao($pdo, $clienteId, $etapaIdAnexoPortal, $_FILES['arquivos'], null);
        flash_set($errosUploadImplantacaoPortal ? 'erro' : 'sucesso', $errosUploadImplantacaoPortal ? implode(' ', $errosUploadImplantacaoPortal) : 'Anexo(s) enviado(s).');
    }

    header('Location: ' . url('portal/implantacao.php'));
    exit;
}

$etapasImplantacaoPortal = $pdo->prepare('SELECT * FROM implantacao_etapas WHERE cliente_id = ? ORDER BY ordem ASC, id ASC');
$etapasImplantacaoPortal->execute([$clienteId]);
$etapasImplantacaoPortal = $etapasImplantacaoPortal->fetchAll();

$stmtDocsImplantacaoPortal = $pdo->prepare("SELECT * FROM documentos WHERE cliente_id = ? AND categoria = 'Implantação' ORDER BY criado_em ASC");
$stmtDocsImplantacaoPortal->execute([$clienteId]);
$anexosImplantacaoPorEtapaIdPortal = [];
$anexosImplantacaoGeraisPortal = [];
foreach ($stmtDocsImplantacaoPortal->fetchAll() as $docPortal) {
    if ($docPortal['implantacao_etapa_id']) {
        $anexosImplantacaoPorEtapaIdPortal[$docPortal['implantacao_etapa_id']][] = $docPortal;
    } else {
        $anexosImplantacaoGeraisPortal[] = $docPortal;
    }
}

$totalEtapasImplantacaoPortal = count($etapasImplantacaoPortal);
$concluidasImplantacaoPortal = count(array_filter($etapasImplantacaoPortal, fn ($e) => $e['status'] === 'concluida'));
$statusLabelImplantacaoPortal = ['pendente' => 'Pendente', 'em_andamento' => 'Em andamento', 'concluida' => 'Concluída'];
$statusBadgeImplantacaoPortal = ['pendente' => 'secondary', 'em_andamento' => 'warning', 'concluida' => 'success'];

$tituloPagina = 'Implantação';
require __DIR__ . '/../templates/portal_header.php';
?>

<h4 class="mb-1">Implantação</h4>
<p class="text-muted small mb-3"><i class="bi bi-rocket-takeoff"></i> Acompanhe o andamento do processo de implantação da sua empresa e envie os documentos pedidos em cada etapa (contrato assinado, evidência de teste etc.).</p>

<?php if ($totalEtapasImplantacaoPortal > 0): ?>
<div class="card card-stat mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center justify-content-between">
            <span class="fw-semibold"><?= $concluidasImplantacaoPortal ?> de <?= $totalEtapasImplantacaoPortal ?> etapas concluídas</span>
            <span class="text-muted small"><?= round($totalEtapasImplantacaoPortal ? $concluidasImplantacaoPortal / $totalEtapasImplantacaoPortal * 100 : 0) ?>%</span>
        </div>
        <div class="progress mt-1" style="height:6px;">
            <div class="progress-bar bg-success" style="width: <?= $totalEtapasImplantacaoPortal ? round($concluidasImplantacaoPortal / $totalEtapasImplantacaoPortal * 100) : 0 ?>%"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$etapasImplantacaoPortal): ?>
    <p class="text-muted text-center py-4"><i class="bi bi-rocket-takeoff" style="font-size:2rem;"></i><br>Nenhuma etapa de implantação cadastrada ainda.</p>
<?php endif; ?>

<?php foreach ($etapasImplantacaoPortal as $etapaPortal):
    $idEtapaAtualPortal = (int) $etapaPortal['id'];
    $anexosEtapaAtualPortal = $anexosImplantacaoPorEtapaIdPortal[$idEtapaAtualPortal] ?? [];
?>
<div class="card card-stat mb-2">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            <span class="fw-semibold"><?= e($etapaPortal['titulo']) ?></span>
            <span class="badge bg-<?= $statusBadgeImplantacaoPortal[$etapaPortal['status']] ?>"><?= $statusLabelImplantacaoPortal[$etapaPortal['status']] ?></span>
            <?php if ($etapaPortal['data_prevista']): ?><span class="text-muted small">Previsto: <?= formatar_data($etapaPortal['data_prevista']) ?></span><?php endif; ?>
            <?php if ($etapaPortal['data_concluida']): ?><span class="text-muted small">Concluído: <?= formatar_data($etapaPortal['data_concluida']) ?></span><?php endif; ?>
        </div>
        <?php if ($etapaPortal['descricao']): ?><p class="text-muted small mb-2"><?= nl2br(e($etapaPortal['descricao'])) ?></p><?php endif; ?>

        <div class="border-top pt-2">
            <?php foreach ($anexosEtapaAtualPortal as $anexoEtapaPortal): ?>
                <a href="<?= url('download.php?id=' . $anexoEtapaPortal['id']) ?>" class="btn btn-sm btn-outline-dark me-1 mb-1"><i class="bi bi-paperclip"></i> <?= e($anexoEtapaPortal['arquivo_nome_original']) ?></a>
            <?php endforeach; ?>
            <?php if (!$anexosEtapaAtualPortal): ?><p class="text-muted small mb-2">Nenhum anexo ainda.</p><?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="d-inline-flex gap-1 align-items-center mt-1">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="upload_documento_implantacao">
                <input type="hidden" name="etapa_id" value="<?= $idEtapaAtualPortal ?>">
                <input type="file" name="arquivos[]" class="form-control form-control-sm" multiple required style="max-width:260px;">
                <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Anexar</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="card card-stat mt-3">
    <div class="card-header bg-white fw-bold"><i class="bi bi-paperclip"></i> Anexos gerais (sem etapa específica)</div>
    <div class="card-body">
        <?php foreach ($anexosImplantacaoGeraisPortal as $anexoGeralPortal): ?>
            <a href="<?= url('download.php?id=' . $anexoGeralPortal['id']) ?>" class="btn btn-sm btn-outline-dark me-1 mb-1"><i class="bi bi-paperclip"></i> <?= e($anexoGeralPortal['arquivo_nome_original']) ?></a>
        <?php endforeach; ?>
        <?php if (!$anexosImplantacaoGeraisPortal): ?><p class="text-muted small mb-2">Nenhum anexo geral ainda.</p><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-1 align-items-center mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="upload_documento_implantacao">
            <input type="hidden" name="etapa_id" value="">
            <input type="file" name="arquivos[]" class="form-control form-control-sm" multiple required style="max-width:300px;">
            <button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Anexar</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
