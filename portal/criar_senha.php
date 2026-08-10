<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\Database;

$pdo = Database::connection();
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// O mesmo link/tela serve pra dois tipos de convite: o master (contato
// principal do cliente, cliente_senha_tokens) e o responsável de um setor
// (setor_senha_tokens) -- ver ClienteAuth pra entender a diferença entre os
// dois tipos de login. Tenta os dois; achou num, para.
$stmt = $pdo->prepare('SELECT t.*, c.nome AS cliente_nome, c.contato_nome, c.contato_email FROM cliente_senha_tokens t JOIN clientes c ON c.id = t.cliente_id WHERE t.token = ? AND t.usado = 0 AND t.expira_em > NOW() LIMIT 1');
$stmt->execute([$token]);
$tokenInfo = $stmt->fetch();
$tipoToken = 'master';

if (!$tokenInfo) {
    $stmt = $pdo->prepare("
        SELECT t.*, sr.nome AS contato_nome, sr.email AS contato_email, s.nome AS setor_nome, c.nome AS cliente_nome
        FROM setor_senha_tokens t
        JOIN setor_responsaveis sr ON sr.id = t.setor_responsavel_id
        JOIN setores s ON s.id = sr.setor_id
        JOIN clientes c ON c.id = s.cliente_id
        WHERE t.token = ? AND t.usado = 0 AND t.expira_em > NOW() LIMIT 1
    ");
    $stmt->execute([$token]);
    $tokenInfo = $stmt->fetch();
    $tipoToken = 'setor';
}

$erro = null;

if (!$tokenInfo) {
    $erro = 'Este link é inválido ou já expirou. Solicite um novo convite.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $senha = $_POST['senha'] ?? '';
    $confirmar = $_POST['confirmar_senha'] ?? '';

    if (strlen($senha) < 6) {
        $erro = 'A senha precisa ter pelo menos 6 caracteres.';
    } elseif ($senha !== $confirmar) {
        $erro = 'As senhas não coincidem.';
    } else {
        if ($tipoToken === 'master') {
            $pdo->prepare('UPDATE clientes SET senha_hash = ?, portal_ativo = 1 WHERE id = ?')
                ->execute([password_hash($senha, PASSWORD_DEFAULT), $tokenInfo['cliente_id']]);
            $pdo->prepare('UPDATE cliente_senha_tokens SET usado = 1 WHERE id = ?')
                ->execute([$tokenInfo['id']]);
        } else {
            $pdo->prepare('UPDATE setor_responsaveis SET senha_hash = ?, portal_ativo = 1 WHERE id = ?')
                ->execute([password_hash($senha, PASSWORD_DEFAULT), $tokenInfo['setor_responsavel_id']]);
            $pdo->prepare('UPDATE setor_senha_tokens SET usado = 1 WHERE id = ?')
                ->execute([$tokenInfo['id']]);
        }

        flash_set('sucesso', 'Senha criada com sucesso! Faça login para acessar seus chamados.');
        header('Location: ' . url('portal/login.php'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar senha · Portal PrimeERP</title>
    <link rel="icon" type="image/png" href="<?= asset_url('assets/img/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="login-wrapper">
    <div class="card login-card shadow-lg overflow-hidden">
        <div class="login-logo-wrap text-center py-4">
            <picture>
                <source srcset="<?= asset_url('assets/img/logo.webp') ?>" type="image/webp">
                <img src="<?= asset_url('assets/img/logo.png') ?>" alt="PrimeERP" height="36">
            </picture>
            <div class="mt-2 text-white fw-semibold">Criar senha de acesso</div>
        </div>
        <div class="card-body p-4">
            <?php if ($erro): ?>
                <div class="alert alert-danger"><?= e($erro) ?></div>
            <?php endif; ?>
            <?php if ($tokenInfo): ?>
                <?php
                // Saudação pelo nome da PESSOA (key user/contato master),
                // não da empresa -- "Olá, GEOMAQ" confundia o Kleber apontou
                // como impessoal. Cai pro nome da empresa só se não tiver
                // nenhum contato cadastrado ainda.
                $nomeSaudacao = $tokenInfo['contato_nome'] ?: $tokenInfo['cliente_nome'];
                ?>
                <p class="text-muted">Olá, <strong><?= e($nomeSaudacao) ?></strong>. Defina sua senha de acesso ao portal<?= $tipoToken === 'setor' ? ' do setor ' . e($tokenInfo['setor_nome']) : '' ?>.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="mb-3">
                        <label class="form-label">Nova senha</label>
                        <div class="campo-senha">
                            <input type="password" name="senha" class="form-control" minlength="6" required autofocus>
                            <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirmar senha</label>
                        <div class="campo-senha">
                            <input type="password" name="confirmar_senha" class="form-control" minlength="6" required>
                            <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark w-100">Criar senha e continuar</button>
                </form>
            <?php else: ?>
                <a href="<?= url('portal/login.php') ?>" class="btn btn-outline-dark w-100">Ir para o login</a>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.querySelectorAll('.toggle-senha').forEach(function (botao) {
            botao.addEventListener('click', function () {
                var campo = botao.previousElementSibling;
                var mostrando = campo.type === 'text';
                campo.type = mostrando ? 'password' : 'text';
                botao.querySelector('i').className = mostrando ? 'bi bi-eye' : 'bi bi-eye-slash';
            });
        });
    </script>
</body>
</html>
