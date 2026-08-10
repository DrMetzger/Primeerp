<?php
/** @var string $tituloPagina */
use AdminPrime\ClienteAuth;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina ?? 'Portal do Cliente') ?> · Portal PrimeERP</title>
    <link rel="icon" type="image/png" href="<?= asset_url('assets/img/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if (ClienteAuth::check()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="<?= url('portal/chamados.php') ?>">
      <picture>
        <source srcset="<?= asset_url('assets/img/logo.webp') ?>" type="image/webp">
        <img src="<?= asset_url('assets/img/logo.png') ?>" alt="PrimeERP">
      </picture>
    </a>
    <div class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="<?= url('portal/chamados.php') ?>"><i class="bi bi-headset"></i> Meus Chamados</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= url('portal/calendario.php') ?>"><i class="bi bi-calendar-event"></i> Calendário</a></li>
        <?php if (ClienteAuth::podeGerenciarEquipe()): ?>
        <li class="nav-item"><a class="nav-link" href="<?= url('portal/equipe.php') ?>"><i class="bi bi-diagram-3"></i> Setores e Usuários</a></li>
        <?php endif; ?>
      </ul>
      <!-- "Meu Perfil" como item de menu separado foi removido -- o
           avatar+nome aqui do lado já é um link pro perfil, ter os dois
           levando pro mesmo lugar confundia (Kleber apontou como "2 botões
           pro mesmo lugar"). -->
      <a href="<?= url('portal/perfil.php') ?>" class="navbar-text text-light me-3 d-flex align-items-center gap-2" title="Meu perfil">
        <?= avatar_html(ClienteAuth::clienteNome(), ClienteAuth::clienteFoto(), 'cliente') ?> <?= e(ClienteAuth::clienteNome()) ?>
      </a>
      <a href="<?= url('portal/logout.php') ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Sair</a>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="container-fluid px-4 py-4" style="max-width: 900px;">
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
