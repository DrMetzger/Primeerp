<?php

/**
 * PHPMailer vendorizado manualmente (sem Composer) porque o deploy do
 * Prime Hub é feito via Git nativo do Hostinger (só um "git pull" no push,
 * sem etapa de build/composer install). Baixado direto do GitHub oficial
 * (github.com é o único host externo liberado no sandbox de desenvolvimento)
 * na versão v6.9.3. Requer só os arquivos necessários para envio via SMTP
 * autenticado (Exception, SMTP e PHPMailer -- OAuth não é usado aqui).
 */

require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/SMTP.php';
require_once __DIR__ . '/src/PHPMailer.php';
