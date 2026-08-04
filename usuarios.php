<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

// Aceita também o token temporário (?diag_token=...) além da sessão de admin
// normal -- mesmo esquema usado em importar_chamados_hesk.php e
// manutencao_clientes.php, pra permitir uma ação pontual (ex.: criar um
// usuário) sem precisar de um segundo login manual do admin.
Auth::requireAdminOrTempToken(Database::connection());

$pdo = Database::connection();

$mensagemAcaoToken = null;

// Criação de usuário via GET com token temporário + confirmação explícita na
// URL (usado quando quem está operando é o Claude, que só consegue fazer
// requisições GET). Sempre exige e-mail ainda não cadastrado -- não
// sobrescreve usuário existente.
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['acao'] ?? '') === 'criar_usuario'
    && ($_GET['confirmar'] ?? '') === 'SIM'
    && ($_GET['diag_token'] ?? '') !== ''
) {
    $nome = trim($_GET['nome'] ?? '');
    $email = trim($_GET['email'] ?? '');
    $senha = $_GET['senha'] ?? '';
    $perfil = ($_GET['perfil'] ?? '') === 'admin' ? 'admin' : 'colaborador';

    if ($nome === '' || $email === '' || strlen($senha) < 6) {
        $mensagemAcaoToken = ['tipo' => 'erro', 'texto' => 'Preencha nome, e-mail e uma senha com pelo menos 6 caracteres.'];
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
            $mensagemAcaoToken = ['tipo' => 'sucesso', 'texto' => 'Usuário "' . $nome . '" criado.'];
        } catch (\PDOException $e) {
            $mensagemAcaoToken = ['tipo' => 'erro', 'texto' => 'Não foi possível criar o usuário (e-mail já cadastrado?).'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar_usuario') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $perfil = $_POST['perfil'] === 'admin' ? 'admin' : 'colaborador';

        if ($nome === '' || $email === '' || strlen($senha) < 6) {
            flash_set('erro', 'Preencha nome, e-mail e uma senha com pelo menos 6 caracteres.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)');
                $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
                flash_set('sucesso', 'Usuário "' . $nome . '" criado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível criar o usuário (e-mail já cadastrado?).');
            }
        }
    }

    if ($acao === 'alternar_status') {
        $usuarioId = (int) $_POST['usuario_id'];
        if ($usuarioId !== Auth::userId()) {
            $stmt = $pdo->prepare('UPDATE usuarios SET ativo = NOT ativo WHERE id = ?');
            $stmt->execute([$usuarioId]);
            flash_set('sucesso', 'Status do usuário atualizado.');
        } else {
            flash_set('erro', 'Você não pode desativar seu próprio usuário.');
        }
    }

    header('Location: ' . url('usuarios.php'));
    exit;
}

$usuarios = $pdo->query('SELECT * FROM usuarios ORDER BY nome ASC')->fetchAll();

$tituloPagina = 'Usuários';
require __DIR__ . '/templates/header.php';
?>

<?php if ($mensagemAcaoToken): ?>
    <div class="alert alert-<?= $mensagemAcaoToken['tipo'] === 'sucesso' ? 'success' : 'danger' ?>"><?= e($mensagemAcaoToken['texto']) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Usuários</h4>
    <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoUsuario"><i class="bi bi-plus-lg"></i> Novo usuário</button>
</div>

<div class="card card-stat">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td class="fw-semibold"><?= e($u['nome']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge bg-<?= $u['perfil'] === 'admin' ? 'dark' : 'secondary' ?>"><?= $u['perfil'] === 'admin' ? 'Administrador' : 'Colaborador' ?></span></td>
                    <td><span class="badge bg-<?= $u['ativo'] ? 'success' : 'secondary' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="text-end">
                        <?php if ((int)$u['id'] !== Auth::userId()): ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="alternar_status">
                            <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-dark"><?= $u['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted small">(você)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNovoUsuario" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_usuario">
        <div class="modal-header"><h5 class="modal-title">Novo usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">E-mail *</label><input type="email" name="email" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Senha *</label><input type="password" name="senha" class="form-control" minlength="6" required></div>
            <div class="mb-3"><label class="form-label">Perfil</label>
                <select name="perfil" class="form-select">
                    <option value="colaborador">Colaborador</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Criar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
