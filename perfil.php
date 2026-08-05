<?php

/**
 * Perfil do usuário da equipe logado: nome, e-mail, telefone, foto (upload
 * ou avatar pré-definido) e troca de senha. Antes não existia nada disso —
 * o único jeito de mudar qualquer coisa do próprio usuário era outro admin
 * editar direto no banco.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireLogin();

$pdo = Database::connection();
$usuarioId = Auth::userId();

$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
$stmt->execute([$usuarioId]);
$usuario = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'atualizar_dados') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = apenas_digitos($_POST['telefone'] ?? '');
        $documento = normalizar_documento($_POST['documento'] ?? '');

        if ($nome === '' || $email === '') {
            flash_set('erro', 'Nome e e-mail são obrigatórios.');
        } else {
            $existe = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ? AND id != ?');
            $existe->execute([$email, $usuarioId]);
            if ($existe->fetchColumn()) {
                flash_set('erro', 'Já existe outro usuário com esse e-mail.');
            } else {
                $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, telefone = ?, documento = ? WHERE id = ?')
                    ->execute([$nome, $email, $telefone, $documento, $usuarioId]);
                $_SESSION['usuario_nome'] = $nome;
                flash_set('sucesso', 'Dados atualizados.');
            }
        }
    }

    if ($acao === 'trocar_senha') {
        $senhaAtual = $_POST['senha_atual'] ?? '';
        $novaSenha = $_POST['nova_senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';

        if (!password_verify($senhaAtual, $usuario['senha_hash'])) {
            flash_set('erro', 'Senha atual incorreta.');
        } elseif (strlen($novaSenha) < 6) {
            flash_set('erro', 'A nova senha precisa ter pelo menos 6 caracteres.');
        } elseif ($novaSenha !== $confirmarSenha) {
            flash_set('erro', 'As senhas não coincidem.');
        } else {
            $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                ->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $usuarioId]);
            flash_set('sucesso', 'Senha alterada.');
        }
    }

    if ($acao === 'atualizar_foto') {
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
                    $dirAvatares = __DIR__ . '/assets/avatars';
                    if (!is_dir($dirAvatares)) {
                        mkdir($dirAvatares, 0755, true);
                    }
                    foreach (glob($dirAvatares . '/usuario_' . $usuarioId . '.*') as $antigo) {
                        @unlink($antigo);
                    }
                    $nomeArquivo = 'usuario_' . $usuarioId . '.' . $tiposPermitidos[$mime];
                    $caminhoDestino = $dirAvatares . '/' . $nomeArquivo;
                    if (move_uploaded_file($arquivo['tmp_name'], $caminhoDestino)) {
                        redimensionar_imagem_se_necessario($caminhoDestino, $mime, 400, 400);
                        $novoCaminho = 'assets/avatars/' . $nomeArquivo;
                        // Backup dos bytes no banco -- ver Migrations::restaurarUploadsPerdidos().
                        $pdo->prepare('UPDATE usuarios SET foto_perfil = ?, foto_perfil_dados = ? WHERE id = ?')
                            ->execute([$novoCaminho, file_get_contents($caminhoDestino), $usuarioId]);
                        $_SESSION['usuario_foto'] = $novoCaminho;
                        flash_set('sucesso', 'Foto de perfil atualizada.');
                    } else {
                        flash_set('erro', 'Não foi possível salvar a imagem no servidor.');
                    }
                }
            }
        }
    }

    if ($acao === 'escolher_avatar_predefinido') {
        $indice = (int) ($_POST['avatar'] ?? 0);
        if ($indice >= 1 && $indice <= QUANTIDADE_AVATARES_PREDEFINIDOS) {
            $novoCaminho = 'assets/img/avatares/avatar-' . $indice . '.svg';
            // Avatar pré-definido é arquivo versionado no código -- zera o backup
            // de bytes de uma foto enviada antes, que não serve mais pra nada.
            $pdo->prepare('UPDATE usuarios SET foto_perfil = ?, foto_perfil_dados = NULL WHERE id = ?')
                ->execute([$novoCaminho, $usuarioId]);
            $_SESSION['usuario_foto'] = $novoCaminho;
            flash_set('sucesso', 'Avatar atualizado.');
        }
    }

    header('Location: ' . url('perfil.php'));
    exit;
}

$tituloPagina = 'Meu perfil';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-3">Meu perfil</h4>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card card-stat">
            <div class="card-body text-center py-4">
                <div class="perfil-avatar d-inline-block"><?= avatar_html($usuario['nome'], $usuario['foto_perfil'] ?? null, 'equipe') ?></div>
                <div class="mt-3">
                    <h6 class="mb-0"><?= e($usuario['nome']) ?></h6>
                    <p class="text-muted small mb-0"><?= e(PERFIS_USUARIO[$usuario['perfil']] ?? $usuario['perfil']) ?></p>
                </div>

                <form method="post" enctype="multipart/form-data" class="mt-4">
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
    </div>

    <div class="col-lg-8">
        <div class="card card-stat mb-3">
            <div class="card-header bg-white fw-bold">Meus dados</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_dados">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome *</label>
                            <input type="text" name="nome" class="form-control" value="<?= e($usuario['nome']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail *</label>
                            <input type="email" name="email" class="form-control" value="<?= e($usuario['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="text" name="telefone" class="form-control input-telefone" value="<?= e(formatar_telefone($usuario['telefone'] ?? '')) ?>" placeholder="(00) 00000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CPF</label>
                            <input type="text" name="documento" class="form-control" value="<?= e($usuario['documento'] ?? '') ?>" placeholder="Só números">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3"><i class="bi bi-check2"></i> Salvar dados</button>
                </form>
            </div>
        </div>

        <div class="card card-stat">
            <div class="card-header bg-white fw-bold">Trocar senha</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="trocar_senha">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Senha atual *</label>
                            <input type="password" name="senha_atual" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nova senha *</label>
                            <input type="password" name="nova_senha" class="form-control" minlength="6" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirmar nova senha *</label>
                            <input type="password" name="confirmar_senha" class="form-control" minlength="6" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-dark mt-3"><i class="bi bi-shield-lock"></i> Trocar senha</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
