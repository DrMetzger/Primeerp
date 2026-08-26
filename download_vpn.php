<?php

/**
 * Download de arquivo de VPN do cofre de acessos (aba "Acessos" em
 * cliente.php) -- deliberadamente SEPARADO de download.php: aqui exige
 * Auth::requireAcessoDadosSensiveis() (admin/diretor ou acesso especial),
 * não só login de qualquer um da equipe.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Migrations;

Auth::requireAcessoAcessosProprios();

$pdo = Database::connection();

$arquivoId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM cliente_vpn_arquivos WHERE id = ?');
$stmt->execute([$arquivoId]);
$arquivo = $stmt->fetch();

if (!$arquivo) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

if (Auth::apenasProprios() && (int) $arquivo['criado_por'] !== Auth::userId()) {
    http_response_code(403);
    die('Você só pode baixar arquivos cadastrados por você.');
}

Migrations::restaurarArquivoSeNecessario($pdo, 'cliente_vpn_arquivos', $arquivoId, 'arquivo_caminho', 'dados', UPLOADS_DIR);

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $arquivo['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

Auth::registrarAcessoSensivel('vpn_arquivo', 'baixar_arquivo', $arquivoId, (int) $arquivo['cliente_id']);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($arquivo['arquivo_nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoCompleto));
header('Cache-Control: private');

readfile($caminhoCompleto);
exit;
