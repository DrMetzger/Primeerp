<?php
/**
 * Configurações da aplicação AdminPrime.
 *
 * IMPORTANTE: este arquivo fica dentro de um repositório PRIVADO e é protegido
 * por .htaccess (acesso direto via navegador é bloqueado). Ainda assim, evite
 * versionar credenciais de produção em outros lugares além deste arquivo.
 */

// Ambiente: 'local' (desenvolvimento/testes) ou 'production' (Hostinger)
// Detecta automaticamente pelo host, mas pode forçar via variável de ambiente.
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? 'cli', ['localhost', '127.0.0.1', 'cli'], true);

if ($isLocal && getenv('ADMINPRIME_ENV') !== 'production') {
    // --- Configuração LOCAL (ambiente de testes) ---
    define('DB_HOST', getenv('ADMINPRIME_DB_HOST') ?: 'localhost');
    define('DB_NAME', getenv('ADMINPRIME_DB_NAME') ?: 'adminprime_test');
    define('DB_USER', getenv('ADMINPRIME_DB_USER') ?: 'adminprime');
    define('DB_PASS', getenv('ADMINPRIME_DB_PASS') ?: 'testpass123');

    // Banco do HESK (helpdesk) não existe localmente ainda — deixado em branco.
    define('HESK_DB_HOST', '');
    define('HESK_DB_NAME', '');
    define('HESK_DB_USER', '');
    define('HESK_DB_PASS', '');
} else {
    // --- Configuração de PRODUÇÃO (Hostinger) ---
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u195563148_claude');
    define('DB_USER', 'u195563148_claude');
    define('DB_PASS', '*@2^@cIdb4J?');

    // Banco do HESK (sistema de chamados atual, primeerp.com.br/helpdesk/)
    define('HESK_DB_HOST', 'localhost');
    define('HESK_DB_NAME', 'u195563148_help');
    define('HESK_DB_USER', 'u195563148_help');
    define('HESK_DB_PASS', 'I31&vq~=k5');
}

define('DB_CHARSET', 'utf8mb4');

// Nome da aplicação / URL base (ajustado para a subpasta /AdminPrime no domínio)
define('APP_NAME', 'AdminPrime');
define('APP_BASE_PATH', $isLocal ? '' : '/AdminPrime');

// Diretório de uploads (relativo à raiz do projeto)
define('UPLOADS_DIR', __DIR__ . '/../uploads');
define('UPLOADS_MAX_SIZE', 15 * 1024 * 1024); // 15MB por arquivo

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Sessão
if (session_status() === PHP_SESSION_NONE) {
    session_name('adminprime_session');
    session_start();
}

// Exibição de erros (desligar em produção real de clientes externos, mas
// como é ferramenta interna, deixamos log ativo e tela só em local)
error_reporting(E_ALL);
ini_set('display_errors', $isLocal ? '1' : '0');
ini_set('log_errors', '1');
