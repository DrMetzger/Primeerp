<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Migrations;

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

// Restaura do backup no banco se o arquivo tiver desaparecido do disco
// (deploy do Hostinger recria uploads/ do zero -- ver Migrations).
Migrations::restaurarArquivoSeNecessario($pdo, 'chamado_anexos', $anexoId, 'arquivo_caminho', 'dados', UPLOADS_DIR);

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $anexo['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

// Proteção contra path traversal, igual ao download.php.
if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

// ?ver=1 (sistema novo): mostra imagem/vídeo/PDF dentro da conversa em vez
// de baixar. Só esses tipos -- qualquer outro continua sendo download.
$tiposVisualizaveis = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm4v' => 'video/mp4', 'pdf' => 'application/pdf'];
$extensaoAnexo = strtolower(pathinfo($anexo['arquivo_nome_original'], PATHINFO_EXTENSION));
$mostrarNaTela = ($_GET['ver'] ?? '') === '1' && isset($tiposVisualizaveis[$extensaoAnexo]);
header('Content-Description: File Transfer');
header('Content-Type: ' . ($mostrarNaTela ? $tiposVisualizaveis[$extensaoAnexo] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($mostrarNaTela ? 'inline' : 'attachment') . '; filename="' . basename($anexo['arquivo_nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoCompleto));
header('Cache-Control: private');

readfile($caminhoCompleto);
exit;
