<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Migrations;

Auth::requireLogin();

$pdo = Database::connection();

$docId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$docId]);
$documento = $stmt->fetch();

if (!$documento) {
    http_response_code(404);
    die('Documento não encontrado.');
}

// Restaura do backup no banco se o arquivo tiver desaparecido do disco
// (deploy do Hostinger recria uploads/ do zero -- ver Migrations).
Migrations::restaurarArquivoSeNecessario($pdo, 'documentos', $docId, 'arquivo_caminho', 'dados', UPLOADS_DIR);

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $documento['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

// Garante que o caminho resolvido continua dentro da pasta de uploads
// (proteção contra path traversal).
if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($documento['arquivo_nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoCompleto));
header('Cache-Control: private');

readfile($caminhoCompleto);
exit;
