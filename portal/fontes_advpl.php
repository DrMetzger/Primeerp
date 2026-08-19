<?php

/**
 * Fontes ADVPL do portal -- só-LEITURA e só pro MASTER (contato principal):
 * dado extremamente sensível (código-fonte proprietário do cliente), mesmo
 * nível de sigilo do cofre de acessos em cliente.php. Diferente de vídeo
 * aula (portal/videoaulas.php), aqui não existe fallback por setor -- login
 * de setor específico não vê essa página de jeito nenhum, só o master.
 * Cadastrar/editar/excluir fonte continua exclusivo da equipe (cliente.php).
 */

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

if (!ClienteAuth::temPermissaoMaster()) {
    http_response_code(403);
    die('Acesso restrito ao contato master.');
}

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();

$fontesAdvpl = $pdo->prepare("
    SELECT f.*, s.nome AS setor_nome
    FROM setor_fontes_advpl f
    INNER JOIN setores s ON s.id = f.setor_id
    WHERE s.cliente_id = ?
    ORDER BY s.nome ASC, f.titulo ASC
");
$fontesAdvpl->execute([$clienteId]);
$fontesAdvpl = $fontesAdvpl->fetchAll();

$fontesAdvplPorSetorNome = [];
$setorIdPorNome = [];
foreach ($fontesAdvpl as $f) {
    $fontesAdvplPorSetorNome[$f['setor_nome']][] = $f;
    $setorIdPorNome[$f['setor_nome']] = $f['setor_id'];
}

$tituloPagina = 'Fontes ADVPL';
require __DIR__ . '/../templates/portal_header.php';
?>

<h4 class="mb-1"><i class="bi bi-file-earmark-code"></i> Fontes ADVPL</h4>
<p class="text-muted small mb-4">Código-fonte das rotinas customizadas de cada setor -- área restrita, todo download fica registrado.</p>

<?php if (!$fontesAdvpl): ?>
    <div class="card card-stat">
        <div class="card-body text-center py-5">
            <i class="bi bi-file-earmark-code text-muted" style="font-size:2.5rem;"></i>
            <p class="text-muted mt-3 mb-0">Nenhuma fonte ADVPL cadastrada ainda.</p>
        </div>
    </div>
<?php else: ?>
    <input type="text" class="form-control filtro-fonte-advpl-portal mb-3" data-alvo="fontesAdvplGridPortal" placeholder="🔎 Filtrar por rotina, ponto de entrada ou arquivo...">
<?php endif; ?>

<div id="fontesAdvplGridPortal">
<?php foreach ($fontesAdvplPorSetorNome as $nomeSetorFonte => $fontesDoSetor): ?>
    <div class="fonte-advpl-grupo">
        <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
            <h6 class="text-muted text-uppercase small fw-bold mb-0"><?= e($nomeSetorFonte) ?></h6>
            <a href="<?= url('download_fontes_advpl_zip.php?setor_id=' . $setorIdPorNome[$nomeSetorFonte]) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-zip"></i> Baixar tudo (ZIP)</a>
        </div>
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Rotina</th><th>Ponto de entrada</th><th>Arquivo</th><th>Data</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($fontesDoSetor as $f): ?>
                <tr data-nome-filtro="<?= e(mb_strtolower($f['titulo'] . ' ' . $f['ponto_entrada'] . ' ' . $f['arquivo_nome_original'], 'UTF-8')) ?>">
                    <td class="fw-semibold"><?= e($f['titulo']) ?><?= $f['descricao'] ? '<div class="text-muted small">' . e($f['descricao']) . '</div>' : '' ?></td>
                    <td><?= $f['ponto_entrada'] ? '<code>' . e($f['ponto_entrada']) . '</code>' : '-' ?></td>
                    <td><?= e($f['arquivo_nome_original']) ?> <small class="text-muted">(<?= round($f['arquivo_tamanho'] / 1024) ?> KB)</small></td>
                    <td><?= formatar_data($f['criado_em']) ?></td>
                    <td class="text-end">
                        <a href="<?= url('download_fonte_advpl.php?id=' . $f['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i> Baixar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Mesma ideia do filtro de vídeo aula do portal -- esconde o título do
    // setor (fonte-advpl-grupo) se TODAS as fontes dele ficarem escondidas.
    document.querySelectorAll('.filtro-fonte-advpl-portal').forEach(function (campo) {
        var grade = document.getElementById(campo.dataset.alvo);
        if (!grade) return;
        campo.addEventListener('input', function () {
            var termo = campo.value.trim().toLowerCase();
            grade.querySelectorAll('.fonte-advpl-grupo').forEach(function (grupo) {
                var linhas = grupo.querySelectorAll('[data-nome-filtro]');
                var algumaVisivel = false;
                linhas.forEach(function (linha) {
                    var visivel = linha.dataset.nomeFiltro.indexOf(termo) !== -1;
                    linha.style.display = visivel ? '' : 'none';
                    if (visivel) algumaVisivel = true;
                });
                grupo.style.display = algumaVisivel ? '' : 'none';
            });
        });
    });
});
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
