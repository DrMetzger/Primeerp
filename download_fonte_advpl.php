<?php

/**
 * Download de fonte ADVPL de um setor (sub-aba "Fontes ADVPL" em
 * cliente.php) -- código-fonte proprietário do cliente, mesmo nível de
 * sigilo do cofre de acessos: exige Auth::requireAcessoDadosSensiveis(),
 * não só login de qualquer um da equipe.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Migrations;

Auth::requireAcessoDadosSensiveis();

$pdo = Database::connection();

$fonteId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM setor_fontes_advpl WHERE id = ?');
$stmt->execute([$fonteId]);
$fonte = $stmt->fetch();

if (!$fonte) {
    http_response_code(404);
    die('Fonte não encontrada.');
}

Migrations::restaurarArquivoSeNecessario($pdo, 'setor_fontes_advpl', $fonteId, 'arquivo_caminho', 'dados', UPLOADS_DIR);

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $fonte['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

Auth::registrarAcessoSensivel('fonte_advpl', 'baixar_arquivo', $fonteId);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($fonte['arquivo_nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoCompleto));
header('Cache-Control: private');

readfile($caminhoCompleto);
exit;
