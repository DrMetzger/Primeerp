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
                    $novoCaminho = 'assets/avatars/' . $nomeArquivo;
                    $pdo->prepare('UPDATE clientes SET foto_perfil = ? WHERE id = ?')
                        ->execute([$novoCaminho, $clienteId]);
                    $_SESSION['portal_cliente_foto'] = $novoCaminho;
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

// Quem não quiser subir a própria foto pode escolher um dos avatares
// prontos (SVG hospedado no próprio site, sem depender de nenhum serviço
// externo).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'escolher_avatar_predefinido') {
    csrf_verify();
    $indice = (int) ($_POST['avatar'] ?? 0);
    if ($indice >= 1 && $indice <= QUANTIDADE_AVATARES_PREDEFINIDOS) {
        $novoCaminho = 'assets/img/avatares/avatar-' . $indice . '.svg';
        $pdo->prepare('UPDATE clientes SET foto_perfil = ? WHERE id = ?')
            ->execute([$novoCaminho, $clienteId]);
        $_SESSION['portal_cliente_foto'] = $novoCaminho;
        flash_set('sucesso', 'Avatar atualizado.');
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
        <?php
        // Mostra a PESSOA (contato/key user), não a empresa -- mesma regra
        // de "Olá, GEOMAQ" ser impessoal, aplicada aqui também.
        $nomeDaPessoa = $cliente['contato_nome'] ?: $cliente['nome'];
        ?>
        <div class="perfil-avatar d-inline-block"><?= avatar_html($nomeDaPessoa, $cliente['foto_perfil'] ?? null, 'cliente') ?></div>
        <div class="mt-3">
            <h6 class="mb-0"><?= e($nomeDaPessoa) ?></h6>
            <p class="text-muted small mb-0"><?= e($cliente['nome']) ?> &middot; <?= e($cliente['contato_email'] ?? '') ?></p>
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
