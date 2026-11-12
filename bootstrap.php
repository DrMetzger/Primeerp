<?php

// ---------------------------------------------------------------------------
// CORTE PARA O SERVIDOR NOVO (12/11/2026): o sistema oficial é
// https://suporte.primeerp.com.br. Esta mesma pasta continua sendo enviada
// pra Hostinger, mas LÁ ela só: redireciona quem abrir o endereço antigo,
// repassa o webhook do WhatsApp pro servidor novo (até o endereço ser trocado
// na Meta) e não roda mais cron (o servidor novo tem o dele). Assim nada é
// gravado no banco antigo e ninguém recebe aviso em dobro.
// ---------------------------------------------------------------------------
if (str_contains(__DIR__, '/public_html/')) {
    $novoSistema = 'https://suporte.primeerp.com.br';
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, "Desligado: o Prime Hub agora roda em $novoSistema.\n");
        exit(0);
    }
    $arquivo = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($arquivo === 'webhook_whatsapp.php') {
        $ch = curl_init($novoSistema . '/api/whatsapp/webhook' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
        $cabecalhos = ['Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'application/json')];
        if (!empty($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
            $cabecalhos[] = 'X-Hub-Signature-256: ' . $_SERVER['HTTP_X_HUB_SIGNATURE_256'];
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $cabecalhos]);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        }
        $resposta = curl_exec($ch);
        http_response_code((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 200);
        echo $resposta === false ? '' : $resposta;
        exit;
    }
    if ($arquivo !== 'exportar_banco_migracao.php') {
        $caminho = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . $novoSistema . (str_contains($caminho, '/portal') ? '/portal' : '/login'), true, 302);
        exit;
    }
}

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
responder_json_ao_sistema_novo();
