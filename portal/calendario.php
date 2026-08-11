<?php

/**
 * Calendário do portal -- só-leitura: o cliente vê os treinamentos/reuniões
 * agendados, o link de cada um e quem está marcado pra ser avisado no
 * WhatsApp, mas não cria/edita/exclui nada (isso continua só em
 * cliente.php, com a Prime). Login de setor vê só os eventos do próprio
 * setor + os gerais da empresa; o master vê tudo (mesmo padrão de escopo já
 * usado em chamado.php/perfil.php).
 */

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();
$ehMaster = ClienteAuth::isMaster();
$setorIdLogado = ClienteAuth::setorId();

if ($ehMaster) {
    $treinamentos = $pdo->prepare('
        SELECT t.*, s.nome AS setor_nome
        FROM treinamentos t
        LEFT JOIN setores s ON s.id = t.setor_id
        WHERE t.cliente_id = ?
        ORDER BY t.data_inicio DESC, t.hora_inicio ASC
    ');
    $treinamentos->execute([$clienteId]);
} else {
    $treinamentos = $pdo->prepare('
        SELECT t.*, s.nome AS setor_nome
        FROM treinamentos t
        LEFT JOIN setores s ON s.id = t.setor_id
        WHERE t.cliente_id = ? AND (t.setor_id = ? OR t.setor_id IS NULL)
        ORDER BY t.data_inicio DESC, t.hora_inicio ASC
    ');
    $treinamentos->execute([$clienteId, $setorIdLogado]);
}
$treinamentos = $treinamentos->fetchAll();

// Quem está marcado pra ser avisado no WhatsApp de cada evento -- já com
// nome/telefone pra mostrar na tela (diferente do admin, que só guarda a
// "tag" pra marcar checkbox no modal de edição).
$notificadosPorTreinamentoId = [];
if ($treinamentos) {
    $stmtDestinatarios = $pdo->prepare('
        SELECT d.treinamento_id, d.tipo,
               COALESCE(c.contato_nome, c.nome) AS nome_master, c.contato_telefone AS telefone_master,
               sr.nome AS nome_responsavel, sr.telefone AS telefone_responsavel
        FROM treinamento_destinatarios d
        JOIN treinamentos t ON t.id = d.treinamento_id
        JOIN clientes c ON c.id = t.cliente_id
        LEFT JOIN setor_responsaveis sr ON sr.id = d.setor_responsavel_id
        WHERE d.treinamento_id IN (' . implode(',', array_fill(0, count($treinamentos), '?')) . ')
    ');
    $stmtDestinatarios->execute(array_column($treinamentos, 'id'));
    foreach ($stmtDestinatarios->fetchAll() as $d) {
        $nome = $d['tipo'] === 'master' ? $d['nome_master'] : $d['nome_responsavel'];
        $telefone = $d['tipo'] === 'master' ? $d['telefone_master'] : $d['telefone_responsavel'];
        if (!$telefone) {
            continue;
        }
        $notificadosPorTreinamentoId[$d['treinamento_id']][] = ['nome' => $nome, 'telefone' => $telefone];
    }
}

$mapearEventosCalendarioPortal = fn (array $lista) => array_map(fn ($t) => [
    'id' => $t['id'],
    'titulo' => $t['titulo'],
    'tipo' => $t['tipo'],
    'data_inicio' => $t['data_inicio'],
    'hora_inicio' => $t['hora_inicio'],
    'data_fim' => $t['data_fim'],
    'hora_fim' => $t['hora_fim'],
    'link_reuniao' => $t['link_reuniao'],
    'status' => $t['status'],
    'setor_id' => $t['setor_id'],
], $lista);

$tituloPagina = 'Calendário';
$larguraPortalPx = 1200; // grade do calendário ficava espremida em 900px (mesma reclamação de equipe.php)
require __DIR__ . '/../templates/portal_header.php';
?>

<h4 class="mb-1">Calendário</h4>
<p class="text-muted small mb-4">
    Treinamentos e reuniões agendados<?= $ehMaster ? '' : ' pro seu setor (e os gerais da empresa)' ?>. Essa tela é só pra consulta -- pra agendar ou alterar algo, fale com a Prime.
</p>

<div class="card card-stat mb-3">
    <div class="card-body">
        <div class="calendario-mensal" data-eventos='<?= e(json_encode($mapearEventosCalendarioPortal($treinamentos), JSON_UNESCAPED_UNICODE)) ?>'></div>
    </div>
</div>

<div class="card card-stat">
    <div class="card-header bg-white fw-bold"><i class="bi bi-list-ul"></i> Lista de eventos</div>
    <div class="card-body p-0">
        <?php if (!$treinamentos): ?>
            <p class="text-muted text-center py-4 mb-0">Nenhum treinamento ou reunião agendado ainda.</p>
        <?php endif; ?>
        <?php foreach ($treinamentos as $t):
            $notificados = $notificadosPorTreinamentoId[$t['id']] ?? [];
            $quando = formatar_data($t['data_inicio']) . ($t['hora_inicio'] ? ' às ' . substr($t['hora_inicio'], 0, 5) : '');
        ?>
        <div class="p-3 border-bottom">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <?= badge_tipo_treinamento($t['tipo']) ?>
                    <?php if ($t['status'] === 'cancelado'): ?><span class="badge bg-secondary">Cancelado</span><?php endif; ?>
                    <?php if ($t['setor_nome']): ?><span class="badge bg-light text-dark border"><?= e($t['setor_nome']) ?></span><?php endif; ?>
                    <div class="fw-semibold mt-1"><?= e($t['titulo']) ?></div>
                    <div class="text-muted small"><?= e($quando) ?></div>
                </div>
                <?php if ($t['link_reuniao']): ?>
                    <a href="<?= e($t['link_reuniao']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-camera-video"></i> Entrar na reunião</a>
                <?php endif; ?>
            </div>
            <?php if ($notificados): ?>
            <p class="text-muted small mb-0 mt-2">
                <i class="bi bi-whatsapp"></i> Notificados via WhatsApp:
                <?= e(implode(', ', array_map(fn ($p) => $p['nome'], $notificados))) ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="<?= asset_url('assets/js/calendario_visual.js') ?>"></script>
<script>
document.querySelectorAll('.calendario-mensal').forEach(function (container) {
    inicializarCalendarioMensal(container);
});
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
