<?php

/**
 * Webhook do WhatsApp Business Platform (API oficial da Meta, direto, sem
 * BSP). Endpoint PÚBLICO -- a Meta é quem chama isso, não tem login. Duas
 * responsabilidades:
 *
 * - GET: handshake de verificação (a Meta chama isso uma vez só, quando o
 *   Kleber salva a URL no painel do app).
 * - POST: mensagem/mídia recebida de verdade -- repassa pro WhatsAppBot.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Database;
use AdminPrime\WhatsAppApi;
use AdminPrime\WhatsAppBot;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && ($_GET['hub_verify_token'] ?? '') === WHATSAPP_VERIFY_TOKEN) {
        echo $_GET['hub_challenge'] ?? '';
        exit;
    }
    http_response_code(403);
    exit('Token de verificação inválido.');
}

$corpoBruto = file_get_contents('php://input');
$assinatura = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

if (!WhatsAppApi::assinaturaValida($corpoBruto, $assinatura)) {
    http_response_code(403);
    exit;
}

$payload = json_decode($corpoBruto, true);

// A Meta espera 200 rapidinho (senão ela reenvia o mesmo webhook depois) --
// responde OK já de cara e só então processa. Se o processamento demorar
// ou falhar, a próxima mensagem do cliente ainda chega normalmente (o
// estado da conversa fica salvo no banco a cada etapa).
http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$pdo = Database::connection();

foreach ($payload['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $mudanca) {
        $valor = $mudanca['value'] ?? [];

        // "statuses" = confirmação de entrega/leitura de mensagem QUE A
        // GENTE mandou -- não é mensagem nova de cliente, ignora.
        if (empty($valor['messages'])) {
            continue;
        }

        $nomesPerfilPorTelefone = [];
        foreach ($valor['contacts'] ?? [] as $contato) {
            $nomesPerfilPorTelefone[$contato['wa_id']] = $contato['profile']['name'] ?? '';
        }

        foreach ($valor['messages'] as $mensagem) {
            $telefone = $mensagem['from'] ?? null;
            if (!$telefone) {
                continue;
            }
            $nomePerfil = $nomesPerfilPorTelefone[$telefone] ?? '';

            try {
                if ($mensagem['type'] === 'text') {
                    WhatsAppBot::processarTexto($pdo, $telefone, $nomePerfil, $mensagem['text']['body'] ?? '');
                } elseif (in_array($mensagem['type'], ['image', 'document'], true)) {
                    $midia = $mensagem[$mensagem['type']];
                    WhatsAppBot::processarMidia($pdo, $telefone, $nomePerfil, $midia['id'], $midia['mime_type'] ?? 'application/octet-stream', $midia['filename'] ?? null);
                }
                // Outros tipos (áudio, localização, sticker...) ainda não
                // têm tratamento no menu guiado -- ignorados por enquanto.
            } catch (\Throwable $e) {
                error_log('WhatsApp webhook: erro processando mensagem de ' . $telefone . ': ' . $e->getMessage());
            }
        }
    }
}
