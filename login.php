<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

if (Auth::check()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($email === '' || $senha === '') {
        $erro = 'Informe e-mail e senha.';
    } elseif (Auth::attempt(Database::connection(), $email, $senha)) {
        header('Location: ' . url('dashboard.php'));
        exit;
    } else {
        $erro = 'E-mail ou senha inválidos.';
    }
}

$tituloPagina = 'Login';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · AdminPrime</title>
    <link rel="icon" type="image/png" href="<?= asset_url('assets/img/favicon.png') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="login-wrapper">
    <div class="card login-card shadow-lg overflow-hidden">
        <div class="login-logo-wrap text-center py-4">
            <img src="<?= asset_url('assets/img/logo.webp') ?>" alt="PrimeERP" height="36">
            <div class="mt-2 text-white fw-semibold">AdminPrime</div>
            <small class="text-white-50">Gestão de clientes</small>
        </div>
        <div class="card-body p-4">
            <?php if ($erro): ?>
                <div class="alert alert-danger"><?= e($erro) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-control" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Senha</label>
                    <input type="password" name="senha" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-dark w-100">Entrar</button>
            </form>
        </div>
    </div>
</body>
</html>
