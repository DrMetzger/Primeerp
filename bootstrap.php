<?php

require_once __DIR__ . '/config/config.php';

// PHPMailer vendorizado manualmente (sem Composer) -- usado pelo Mailer.php
// para envio autenticado via SMTP.
require_once __DIR__ . '/vendor/phpmailer/autoload.php';

// FPDF vendorizado manualmente (sem Composer), mesmo motivo do PHPMailer --
// usado pelo PdfExport.php pra gerar todo PDF timbrado do sistema.
require_once __DIR__ . '/vendor/fpdf/fpdf.php';

// Autoloader simples (sem Composer) para o namespace AdminPrime\*
spl_autoload_register(function (string $class) {
    $prefix = 'AdminPrime\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use AdminPrime\Database;
use AdminPrime\Migrations;

$pdo = Database::connection();

// Auto-instalação do schema (idempotente, seguro em toda requisição).
Migrations::run($pdo);

require_once __DIR__ . '/src/helpers.php';

// Login do sistema novo vale aqui também (ver sessao_pelo_login_novo()).
sessao_pelo_login_novo($pdo);
