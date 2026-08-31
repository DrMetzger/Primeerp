<?php
/** @var string $tituloPagina */
use AdminPrime\Auth;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina ?? 'Prime Hub') ?> · Prime Hub</title>
    <link rel="icon" type="image/png" href="<?= asset_url('assets/img/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if (Auth::check()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="<?= url('dashboard.php') ?>">
      <picture>
        <source srcset="<?= asset_url('assets/img/logo.webp') ?>" type="image/webp">
        <img src="<?= asset_url('assets/img/logo.png') ?>" alt="Prime Hub · PrimeERP">
      </picture>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="<?= url('dashboard.php') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('clientes.php') ?>"><i class="bi bi-people-fill"></i> Clientes</a></li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item"><a class="nav-link" href="<?= url('fontes.php') ?>"><i class="bi bi-file-earmark-code"></i> Fontes</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('usuarios.php') ?>"><i class="bi bi-person-gear"></i> Usuários</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('relatorios.php') ?>"><i class="bi bi-clipboard-data"></i> Relatórios</a></li>
        <?php endif; ?>
      </ul>
      <?php if (Auth::isAdmin()): ?>
      <a href="<?= url('configuracoes.php') ?>" class="btn btn-outline-light btn-sm me-3" title="Configurações"><i class="bi bi-gear"></i> Configurações</a>
      <?php endif; ?>
      <a href="<?= url('perfil.php') ?>" class="navbar-text text-light me-3 d-flex align-items-center gap-2">
        <?= avatar_html(Auth::userName(), Auth::userFoto(), 'equipe') ?> <?= e(Auth::userName()) ?>
      </a>
      <a href="<?= url('logout.php') ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Sair</a>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="container-fluid px-4 py-4">
<?php
$erro = flash_get('erro');
$sucesso = flash_get('sucesso');
if ($erro): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= e($erro) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($sucesso): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= e($sucesso) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
