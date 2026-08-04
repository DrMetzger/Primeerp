<?php

require_once __DIR__ . '/config/config.php';

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
