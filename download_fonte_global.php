<?php

/**
 * Download de um fonte/patch da biblioteca GERAL (fontes.php, tabela
 * fontes_globais) -- não é dado de cliente nenhum, então basta estar
 * logado na equipe (mesmo padrão de acesso da própria fontes.php), sem a
 * trava de Auth::podeAcessarDadosSensiveis() usada em download_fonte_advpl.php.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Migrations;

Auth::requireLogin();

$pdo = Database::connection();

$fonteId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM fontes_globais WHERE id = ?');
$stmt->execute([$fonteId]);
$fonte = $stmt->fetch();

if (!$fonte) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

Migrations::restaurarArquivoSeNecessario($pdo, 'fontes_globais', $fonteId, 'arquivo_caminho', 'dados', UPLOADS_DIR);

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $fonte['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($fonte['arquivo_nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoCompleto));
header('Cache-Control: private');

readfile($caminhoCompleto);
exit;
