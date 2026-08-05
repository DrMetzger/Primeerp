<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();
$chamadoId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM chamados WHERE id = ? AND cliente_id = ?');
$stmt->execute([$chamadoId, $clienteId]);
$chamado = $stmt->fetch();

$stmtCliente = $pdo->prepare('SELECT foto_perfil FROM clientes WHERE id = ?');
$stmtCliente->execute([$clienteId]);
$fotoPerfilCliente = $stmtCliente->fetchColumn() ?: null;

if (!$chamado) {
    http_response_code(404);
    die('Chamado não encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'responder') {
    csrf_verify();
    $mensagem = trim($_POST['mensagem'] ?? '');
    $temAnexo = !empty($_FILES['anexos']['name'][0] ?? '');

    if ($mensagem !== '' || $temAnexo) {
        $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, mensagem) VALUES (?, ?, ?, ?)')
            ->execute([$chamadoId, 'cliente', ClienteAuth::clienteNome(), $mensagem !== '' ? $mensagem : '(anexo enviado)']);
        $respostaId = (int) $pdo->lastInsertId();

        $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, $respostaId, 'cliente');

        // Recalcula o status a partir de quem respondeu por último -- se o
        // chamado estava fechado, isso também reabre automaticamente (o
        // cliente respondendo é sinal suficiente de que precisa reabrir).
        recalcular_status_chamado($pdo, $chamadoId);

        flash_set('sucesso', $errosAnexo ? implode(' ', $errosAnexo) : 'Resposta enviada.');
    }

    header('Location: ' . url('portal/chamado.php?id=' . $chamadoId));
    exit;
}

$respostas = $pdo->prepare('SELECT * FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em ASC');
$respostas->execute([$chamadoId]);
$respostas = $respostas->fetchAll();

// Anexos da abertura + de cada resposta, pra desenhar junto de cada bolha.
$stmtAnexos = $pdo->prepare('SELECT * FROM chamado_anexos WHERE chamado_id = ? ORDER BY criado_em ASC');
$stmtAnexos->execute([$chamadoId]);
$anexosPorLinha = [];
foreach ($stmtAnexos->fetchAll() as $a) {
    $chave = $a['resposta_id'] ?? 'abertura';
    $anexosPorLinha[$chave][] = $a;
}

// Normaliza a mensagem de abertura + as respostas num único formato, igual
// ao que já é feito em cliente.php -- antes a mensagem de abertura ficava
// fora do chat, sem bolha, cor ou nome de contato.
$linhasChat = [];
$linhasChat[] = [
    'autor_tipo' => $chamado['aberto_por'] === 'equipe' ? 'equipe' : 'cliente',
    'autor_nome' => $chamado['aberto_por'] === 'equipe' ? 'Equipe PrimeERP' : ClienteAuth::clienteNome(),
    'foto' => $chamado['aberto_por'] === 'equipe' ? null : $fotoPerfilCliente,
    'mensagem' => $chamado['descricao'],
    'criado_em' => $chamado['criado_em'],
    'anexos' => $anexosPorLinha['abertura'] ?? [],
];
foreach ($respostas as $r) {
    $linhasChat[] = [
        'autor_tipo' => $r['autor_tipo'],
        'autor_nome' => $r['autor_nome'],
        'foto' => $r['autor_tipo'] === 'cliente' ? $fotoPerfilCliente : null,
        'mensagem' => $r['mensagem'],
        'criado_em' => $r['criado_em'],
        'anexos' => $anexosPorLinha[$r['id']] ?? [],
    ];
}

$tituloPagina = $chamado['titulo'];
require __DIR__ . '/../templates/portal_header.php';
?>

<a href="<?= url('portal/chamados.php') ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> Voltar para meus chamados</a>

<div class="card card-stat mt-3">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= e($chamado['titulo']) ?></h5>
            <?= badge_status_chamado($chamado['status']) ?>
        </div>
    </div>
    <div class="card-body">
        <p class="mb-1"><?= badge_prioridade($chamado['prioridade']) ?> <?= $chamado['categoria'] ? '<span class="badge bg-light text-dark border">' . e($chamado['categoria']) . '</span>' : '' ?></p>
        <p class="text-muted small mb-0">Aberto em <?= formatar_data($chamado['criado_em']) ?></p>
    </div>
</div>

<h6 class="mt-4 mb-2">Conversa</h6>
<!-- Suas próprias mensagens ficam à direita (convenção normal de chat), a
     equipe PrimeERP à esquerda -- mas a cor da bolha segue quem escreveu
     (você = verde, equipe = azul/roxo), não o lado. Inclui a mensagem de
     abertura do chamado, que antes ficava sem bolha/cor/nome. -->
<div class="chat-thread">
<?php foreach ($linhasChat as $linha): ?>
    <?php $ladoDireita = $linha['autor_tipo'] === 'cliente'; ?>
    <div class="chat-linha <?= $ladoDireita ? 'chat-direita' : 'chat-esquerda' ?>">
        <?= avatar_html($linha['autor_nome'], $linha['foto'], $linha['autor_tipo']) ?>
        <div class="chat-bolha bolha-<?= e($linha['autor_tipo']) ?>">
            <span class="chat-autor"><?= e($linha['autor_nome']) ?><?= $linha['autor_tipo'] === 'equipe' ? ' · Prime Hub' : '' ?></span>
            <span style="white-space: pre-wrap;"><?= e($linha['mensagem']) ?></span>
            <?= render_anexos_html($linha['anexos']) ?>
            <span class="chat-hora"><?= date('d/m/Y H:i', strtotime($linha['criado_em'])) ?></span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($chamado['status'] === 'fechado'): ?>
    <p class="text-muted small mt-3 mb-1"><i class="bi bi-info-circle"></i> Este chamado está fechado. Se responder, ele é reaberto automaticamente.</p>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="mt-2">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="responder">
    <textarea name="mensagem" class="form-control mb-2" rows="3" placeholder="Escreva uma resposta..."></textarea>
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <input type="file" name="anexos[]" class="form-control form-control-sm" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" style="max-width: 320px;">
        <button type="submit" class="btn btn-dark">Enviar resposta</button>
    </div>
    <p class="anexos-upload-preview mb-0">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada (imagem ou PDF).</p>
</form>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
