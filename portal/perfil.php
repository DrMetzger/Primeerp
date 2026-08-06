<?php

/**
 * Perfil do cliente no portal: foto de perfil (upload próprio ou escolher
 * um avatar pré-definido) usada como avatar nos chamados (chat estilo
 * WhatsApp). Foto enviada é guardada fora de uploads/ (que é bloqueado por
 * .htaccess) porque precisa ser exibida como <img> normal pra equipe e pro
 * próprio cliente.
 */

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();
$ehMaster = ClienteAuth::isMaster();
// Login master edita clientes.foto_perfil (dele mesmo); login de setor edita
// setor_responsaveis.foto_perfil (só o próprio, nunca o de outro setor) --
// mesma tela pros dois, só troca qual linha/tabela é alterada por baixo.
$idAlvoFoto = $ehMaster ? $clienteId : ClienteAuth::setorResponsavelId();

$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_foto') {
    csrf_verify();
    $erroFoto = $ehMaster
        ? processar_upload_foto_cliente($pdo, $idAlvoFoto)
        : processar_upload_foto_setor_responsavel($pdo, $idAlvoFoto);
    flash_set($erroFoto ? 'erro' : 'sucesso', $erroFoto ?? 'Foto de perfil atualizada.');

    header('Location: ' . url('portal/perfil.php'));
    exit;
}

// Quem não quiser subir a própria foto pode escolher um dos avatares
// prontos (SVG hospedado no próprio site, sem depender de nenhum serviço
// externo).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'escolher_avatar_predefinido') {
    csrf_verify();
    $indice = (int) ($_POST['avatar'] ?? 0);
    $definiu = $ehMaster
        ? definir_avatar_predefinido_cliente($pdo, $idAlvoFoto, $indice)
        : definir_avatar_predefinido_setor_responsavel($pdo, $idAlvoFoto, $indice);
    if ($definiu) {
        flash_set('sucesso', 'Avatar atualizado.');
    }
    header('Location: ' . url('portal/perfil.php'));
    exit;
}

// Nome/foto/e-mail exibidos: PESSOA logada (contato/key user do master, ou
// responsável do setor), não a empresa -- mesma regra de "Olá, GEOMAQ" ser
// impessoal, aplicada aqui também.
if ($ehMaster) {
    $nomeDaPessoa = $cliente['contato_nome'] ?: $cliente['nome'];
    $emailDaPessoa = $cliente['contato_email'] ?? '';
    $fotoDaPessoa = resolver_avatar_por_email($pdo, $emailDaPessoa, $cliente['foto_perfil'] ?? null);
} else {
    $stmtSetor = $pdo->prepare('SELECT sr.*, s.nome AS setor_nome FROM setor_responsaveis sr JOIN setores s ON s.id = sr.setor_id WHERE sr.id = ?');
    $stmtSetor->execute([$idAlvoFoto]);
    $setorResponsavel = $stmtSetor->fetch();
    $nomeDaPessoa = $setorResponsavel['nome'];
    $emailDaPessoa = $setorResponsavel['email'] ?? '';
    $fotoDaPessoa = resolver_avatar_por_email($pdo, $emailDaPessoa, $setorResponsavel['foto_perfil'] ?? null);
}

$tituloPagina = 'Meu perfil';
require __DIR__ . '/../templates/portal_header.php';
?>

<h5 class="mb-3">Meu perfil</h5>

<div class="card card-stat">
    <div class="card-body text-center py-4">
        <div class="perfil-avatar d-inline-block"><?= avatar_html($nomeDaPessoa, $fotoDaPessoa, 'cliente') ?></div>
        <div class="mt-3">
            <h6 class="mb-0"><?= e($nomeDaPessoa) ?></h6>
            <p class="text-muted small mb-0"><?= $ehMaster ? e($cliente['nome']) : e($setorResponsavel['setor_nome']) . ' · ' . e($cliente['nome']) ?> &middot; <?= e($emailDaPessoa) ?></p>
        </div>

        <form method="post" enctype="multipart/form-data" class="mt-4 mx-auto" style="max-width: 360px;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="atualizar_foto">
            <div class="mb-3 text-start">
                <label class="form-label">Foto de perfil (JPG, PNG ou WEBP, até 5MB)</label>
                <input type="file" name="foto" class="form-control" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <button type="submit" class="btn btn-dark w-100"><i class="bi bi-upload"></i> Enviar foto</button>
        </form>

        <p class="text-muted small mt-4 mb-2">Não quer subir uma foto? Escolha um avatar:</p>
        <div class="galeria-avatares mx-auto">
            <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="escolher_avatar_predefinido">
                    <input type="hidden" name="avatar" value="<?= $i ?>">
                    <button type="submit" class="avatar-opcao" title="Usar este avatar">
                        <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                    </button>
                </form>
            <?php endfor; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
