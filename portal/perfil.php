<?php

/**
 * Perfil do cliente no portal: hoje só permite enviar/trocar a foto usada
 * como avatar nos chamados (chat estilo WhatsApp). Guardada fora de
 * uploads/ (que é bloqueado por .htaccess) porque a foto precisa ser
 * exibida como <img> normal pra equipe e pro próprio cliente.
 */

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();

$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_foto') {
    csrf_verify();

    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
        flash_set('erro', 'Selecione uma imagem.');
    } else {
        $arquivo = $_FILES['foto'];
        $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            flash_set('erro', 'Falha no upload da imagem.');
        } elseif ($arquivo['size'] > 5 * 1024 * 1024) {
            flash_set('erro', 'Imagem maior que o limite permitido (5MB).');
        } else {
            $mime = mime_content_type($arquivo['tmp_name']);
            if (!isset($tiposPermitidos[$mime])) {
                flash_set('erro', 'Formato não suportado. Envie uma imagem JPG, PNG ou WEBP.');
            } else {
                $dirAvatares = __DIR__ . '/../assets/avatars';
                if (!is_dir($dirAvatares)) {
                    mkdir($dirAvatares, 0755, true);
                }
                // Remove foto antiga desse cliente (pode ter extensão diferente da nova).
                foreach (glob($dirAvatares . '/cliente_' . $clienteId . '.*') as $antigo) {
                    @unlink($antigo);
                }
                $nomeArquivo = 'cliente_' . $clienteId . '.' . $tiposPermitidos[$mime];
                if (move_uploaded_file($arquivo['tmp_name'], $dirAvatares . '/' . $nomeArquivo)) {
                    $pdo->prepare('UPDATE clientes SET foto_perfil = ? WHERE id = ?')
                        ->execute(['assets/avatars/' . $nomeArquivo, $clienteId]);
                    flash_set('sucesso', 'Foto de perfil atualizada.');
                } else {
                    flash_set('erro', 'Não foi possível salvar a imagem no servidor.');
                }
            }
        }
    }

    header('Location: ' . url('portal/perfil.php'));
    exit;
}

$tituloPagina = 'Meu perfil';
require __DIR__ . '/../templates/portal_header.php';
?>

<h5 class="mb-3">Meu perfil</h5>

<div class="card card-stat">
    <div class="card-body text-center py-4">
        <div class="perfil-avatar d-inline-block"><?= avatar_html($cliente['nome'], $cliente['foto_perfil'] ?? null, 'cliente') ?></div>
        <div class="mt-3">
            <h6 class="mb-0"><?= e($cliente['nome']) ?></h6>
            <p class="text-muted small mb-0"><?= e($cliente['contato_email'] ?? '') ?></p>
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
    </div>
</div>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
