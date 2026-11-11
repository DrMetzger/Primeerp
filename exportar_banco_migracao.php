<?php
/**
 * Exportação do banco em pedaços pra migração da VM (ver
 * src/ExportarBanco.php::passoWeb()). Só responde progresso, nunca dados.
 * Exige o token de uso único colocado por FTP em config/migracao/TOKEN;
 * sem esse arquivo, não faz nada. Remover depois da troca definitiva.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
@set_time_limit(40);
try {
    $r = \AdminPrime\ExportarBanco::passoWeb((string) ($_POST['token'] ?? ''));
} catch (\Throwable $e) {
    error_log('exportar_banco_migracao: ' . $e->getMessage());
    $r = ['ok' => false, 'erro' => mb_substr($e->getMessage(), 0, 200)];
}
http_response_code($r['ok'] ? 200 : 403);
echo json_encode($r);
