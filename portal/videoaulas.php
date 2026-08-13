<?php

/**
 * Vídeo aulas do portal -- só-leitura: o cliente assiste (embutido, sem
 * precisar sair do sistema) e baixa as gravações que a Prime sobe pra cada
 * setor (ver cliente.php, sub-aba "Vídeo aulas"). Login de setor vê só as
 * vídeo aulas do(s) próprio(s) setor(es); o master vê de todos (mesmo padrão
 * de escopo já usado em calendario.php/chamado.php/perfil.php).
 */

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();
$ehMaster = ClienteAuth::temPermissaoMaster();
$idsSetoresLogadoVideoAulas = ClienteAuth::setorIds();

if ($ehMaster) {
    $videoAulas = $pdo->prepare("
        SELECT d.*, s.nome AS setor_nome
        FROM documentos d
        LEFT JOIN setores s ON s.id = d.setor_id
        WHERE d.cliente_id = ? AND d.categoria = 'Vídeo aula'
        ORDER BY d.criado_em DESC
    ");
    $videoAulas->execute([$clienteId]);
} elseif ($idsSetoresLogadoVideoAulas) {
    $placeholdersVideoAula = implode(',', array_fill(0, count($idsSetoresLogadoVideoAulas), '?'));
    $videoAulas = $pdo->prepare("
        SELECT d.*, s.nome AS setor_nome
        FROM documentos d
        LEFT JOIN setores s ON s.id = d.setor_id
        WHERE d.cliente_id = ? AND d.categoria = 'Vídeo aula' AND d.setor_id IN ($placeholdersVideoAula)
        ORDER BY d.criado_em DESC
    ");
    $videoAulas->execute([$clienteId, ...$idsSetoresLogadoVideoAulas]);
} else {
    $videoAulas = $pdo->prepare("SELECT * FROM documentos WHERE cliente_id = ? AND categoria = 'Vídeo aula' AND 1 = 0");
    $videoAulas->execute([$clienteId]);
}
$videoAulas = $videoAulas->fetchAll();

$videoAulasPorSetorNomePortal = [];
foreach ($videoAulas as $v) {
    $videoAulasPorSetorNomePortal[$v['setor_nome'] ?: 'Geral'][] = $v;
}

$tituloPagina = 'Vídeo aulas';
require __DIR__ . '/../templates/portal_header.php';
?>

<h4 class="mb-1"><i class="bi bi-play-circle"></i> Vídeo aulas</h4>
<p class="text-muted small mb-4">
    Gravações de rotinas e processos<?= $ehMaster ? '' : ' do seu setor' ?> -- assista direto aqui ou baixe pra ver depois.
</p>

<?php if (!$videoAulas): ?>
    <div class="card card-stat">
        <div class="card-body text-center py-5">
            <i class="bi bi-camera-video text-muted" style="font-size:2.5rem;"></i>
            <p class="text-muted mt-3 mb-0">Nenhuma vídeo aula disponível ainda.</p>
        </div>
    </div>
<?php else: ?>
    <input type="text" class="form-control filtro-video-aula mb-3" data-alvo="videoAulaGridPortal" placeholder="🔎 Filtrar por nome da rotina...">
<?php endif; ?>

<div id="videoAulaGridPortal">
<?php foreach ($videoAulasPorSetorNomePortal as $nomeSetorVideoAula => $videosDoSetor): ?>
    <div class="video-aula-grupo">
        <h6 class="text-muted text-uppercase small fw-bold mt-4 mb-2"><?= e($nomeSetorVideoAula) ?></h6>
        <div class="row g-3 mb-3">
            <?php foreach ($videosDoSetor as $v): ?>
            <div class="col-md-4 col-sm-6" data-nome-filtro="<?= e(mb_strtolower($v['titulo'], 'UTF-8')) ?>">
                <div class="card card-stat video-aula-card h-100">
                    <div class="card-body pb-1">
                        <div class="fw-semibold"><?= e($v['titulo']) ?></div>
                    </div>
                    <div class="video-aula-thumb" role="button" data-bs-toggle="modal" data-bs-target="#modalAssistirVideoAulaPortal" data-video-id="<?= $v['id'] ?>" data-video-nome="<?= e($v['titulo']) ?>">
                        <video preload="metadata" muted playsinline src="<?= url('video_aula.php?id=' . $v['id']) ?>"></video>
                        <div class="video-aula-play-overlay"><i class="bi bi-play-circle-fill"></i></div>
                    </div>
                    <div class="card-body pt-2">
                        <p class="text-muted small mb-2"><?= round($v['arquivo_tamanho'] / 1024 / 1024, 1) ?> MB &middot; <?= formatar_data($v['criado_em']) ?></p>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#modalAssistirVideoAulaPortal" data-video-id="<?= $v['id'] ?>" data-video-nome="<?= e($v['titulo']) ?>"><i class="bi bi-play-fill"></i> Assistir</button>
                            <a href="<?= url('video_aula.php?id=' . $v['id'] . '&baixar=1') ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i> Baixar</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="modal fade" id="modalAssistirVideoAulaPortal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content bg-dark">
      <div class="modal-header border-0">
        <h5 class="modal-title text-white" id="tituloVideoAssistirPortal"></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-0">
        <video id="videoAssistirPlayerPortal" controls style="width:100%; max-height:70vh; border-radius:8px;"></video>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalVideoAulaPortal = document.getElementById('modalAssistirVideoAulaPortal');
    var playerVideoAulaPortal = document.getElementById('videoAssistirPlayerPortal');
    modalVideoAulaPortal.addEventListener('show.bs.modal', function (ev) {
        var gatilho = ev.relatedTarget;
        if (!gatilho) return;
        document.getElementById('tituloVideoAssistirPortal').textContent = gatilho.dataset.videoNome || '';
        playerVideoAulaPortal.src = '<?= url('video_aula.php') ?>?id=' + encodeURIComponent(gatilho.dataset.videoId);
    });
    modalVideoAulaPortal.addEventListener('hidden.bs.modal', function () {
        playerVideoAulaPortal.pause();
        playerVideoAulaPortal.removeAttribute('src');
        playerVideoAulaPortal.load();
    });

    // Filtro por nome da rotina -- também esconde o título do setor
    // (video-aula-grupo) se TODAS as vídeo aulas dele ficarem escondidas.
    document.querySelectorAll('.filtro-video-aula').forEach(function (campo) {
        var grade = document.getElementById(campo.dataset.alvo);
        if (!grade) return;
        campo.addEventListener('input', function () {
            var termo = campo.value.trim().toLowerCase();
            grade.querySelectorAll('.video-aula-grupo').forEach(function (grupo) {
                var cards = grupo.querySelectorAll('[data-nome-filtro]');
                var algumVisivel = false;
                cards.forEach(function (card) {
                    var visivel = card.dataset.nomeFiltro.indexOf(termo) !== -1;
                    card.style.display = visivel ? '' : 'none';
                    if (visivel) algumVisivel = true;
                });
                grupo.style.display = algumVisivel ? '' : 'none';
            });
        });
    });
});
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
