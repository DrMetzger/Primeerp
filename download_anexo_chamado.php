<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;

// Anexo de chamado pode ser baixado por dois públicos diferentes: a equipe
// (login normal, vê qualquer anexo) ou o próprio cliente dono do chamado
// (login do portal, só pode baixar anexo de chamado que seja dele). Por
// isso não dá pra usar Auth::requireLogin() puro como o download.php normal.
$staffLogado = Auth::check();
$clienteLogado = ClienteAuth::check();

if (!$staffLogado && !$clienteLogado) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

$pdo = Database::connection();

$anexoId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT a.*, c.cliente_id FROM chamado_anexos a JOIN chamados c ON c.id = a.chamado_id WHERE a.id = ?');
$stmt->execute([$anexoId]);
$anexo = $stmt->fetch();

if (!$anexo) {
    http_response_code(404);
    die('Anexo não encontrado.');
}

if ($clienteLogado && !$staffLogado && $anexo['cliente_id'] !== ClienteAuth::clienteId()) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $anexo['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

// Proteção contra path traversal, igual ao download.php.
if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($anexo['arquivo_nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoCompleto));
header('Cache-Control: private');

readfile($caminhoCompleto);
exit;
