<?php
/**
 * Configurações da aplicação Prime Hub.
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

    // Banco do HESK (helpdesk) — em branco por padrão localmente, mas pode
    // ser apontado para um banco de teste via variáveis de ambiente.
    define('HESK_DB_HOST', getenv('ADMINPRIME_HESK_DB_HOST') ?: '');
    define('HESK_DB_NAME', getenv('ADMINPRIME_HESK_DB_NAME') ?: '');
    define('HESK_DB_USER', getenv('ADMINPRIME_HESK_DB_USER') ?: '');
    define('HESK_DB_PASS', getenv('ADMINPRIME_HESK_DB_PASS') ?: '');

    // SMTP local: em branco por padrão (Mailer cai para mail() do PHP, que
    // localmente nem chega a enviar de verdade -- é só pra não quebrar).
    define('SMTP_HOST', getenv('ADMINPRIME_SMTP_HOST') ?: '');
    define('SMTP_PORT', (int) (getenv('ADMINPRIME_SMTP_PORT') ?: 587));
    define('SMTP_SECURE', getenv('ADMINPRIME_SMTP_SECURE') ?: 'tls'); // 'ssl' ou 'tls'
    define('SMTP_USER', getenv('ADMINPRIME_SMTP_USER') ?: '');
    define('SMTP_PASS', getenv('ADMINPRIME_SMTP_PASS') ?: '');
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

    // SMTP autenticado (Hostinger) -- e-mails de notificação (novo chamado,
    // resposta da equipe, convite de acesso ao portal) passam a ser enviados
    // de verdade via a caixa do Kleber, em vez do mail() nativo do PHP (mais
    // confiável contra spam/rejeição). Confirmado via DNS (MX = mx1/mx2.
    // hostinger.com, SPF inclui _spf.mail.hostinger.com) que o domínio usa o
    // e-mail hospedado da própria Hostinger -- por isso host/porta abaixo
    // seguem o padrão documentado da Hostinger para esse serviço.
    define('SMTP_HOST', 'smtp.hostinger.com');
    define('SMTP_PORT', 465);
    define('SMTP_SECURE', 'ssl');
    define('SMTP_USER', 'kleber.farias@primeerp.com.br');
    define('SMTP_PASS', 'Km@2024@#$');
}

define('DB_CHARSET', 'utf8mb4');

// Nome da aplicação (exibido nas telas) / URL base — a pasta no domínio
// continua se chamando /AdminPrime (é só o diretório no servidor, não muda
// os links do portal já enviados aos clientes), mas o nome mostrado é Prime Hub.
define('APP_NAME', 'Prime Hub');
define('APP_BASE_PATH', $isLocal ? '' : '/AdminPrime');

// Notificações por e-mail (novos chamados avisam a equipe interna).
// Envia como o próprio e-mail do Kleber (autenticado via SMTP, ver acima)
// para cair na caixa de entrada normal do cliente, com o nome de exibição
// "Prime Hub" mantendo a marca.
define('MAIL_FROM', 'Prime Hub <' . (SMTP_USER !== '' ? SMTP_USER : 'nao-responda@primeerp.com.br') . '>');
define('NOTIFICACAO_EMAILS_EQUIPE', ['kleber.farias@primeerp.com.br', 'pcorreajr@primeerp.com.br']);

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
