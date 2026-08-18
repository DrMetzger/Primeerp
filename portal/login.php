<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

// Fora do horário comercial, a TELA de login nem chega a aparecer -- mesmo
// aviso padrão de "portal fora do horário" que qualquer página do portal já
// mostra pra quem está logado (ver ClienteAuth::bloquearForaDoHorario()).
// Pedido explícito do Kleber: nada de mensagem alternativa/tela de login
// liberada fora do horário -- é pra ficar IGUAL ao resto do portal.
ClienteAuth::bloquearForaDoHorario();

if (ClienteAuth::check()) {
    header('Location: ' . url('portal/chamados.php'));
    exit;
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($email === '' || $senha === '') {
        $erro = 'Informe e-mail e senha.';
    } elseif (ClienteAuth::attempt(Database::connection(), $email, $senha)) {
        header('Location: ' . url('portal/chamados.php'));
        exit;
    } else {
        $erro = 'E-mail ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal do Cliente · PrimeERP</title>
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
            <div class="mt-2 text-white fw-semibold">Portal do Cliente</div>
            <small class="text-white-50">Acompanhe seus chamados</small>
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
                    <div class="campo-senha">
                        <input type="password" name="senha" class="form-control" required>
                        <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-dark w-100">Entrar</button>
            </form>
            <p class="text-center text-muted small mt-3 mb-0">
                Ainda não tem acesso? Fale com seu contato na PrimeERP.
            </p>
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
