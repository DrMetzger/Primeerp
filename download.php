<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Migrations;

// Normalmente é só a equipe que baixa documento (Auth::requireLogin() puro),
// mas o cliente no portal agora também precisa baixar os anexos da própria
// Implantação (portal/implantacao.php) -- mesma ideia dual-auth de
// download_anexo_chamado.php. Pra não abrir a porta pra QUALQUER documento
// do cliente (contrato social, propostas etc. continuam só da equipe), o
// login de portal só passa se for realmente da categoria Implantação E
// pertencer à empresa dele.
$staffLogado = Auth::check();
$clienteLogado = ClienteAuth::check();

if (!$staffLogado && !$clienteLogado) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

$pdo = Database::connection();

$docId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$docId]);
$documento = $stmt->fetch();

if (!$documento) {
    http_response_code(404);
    die('Documento não encontrado.');
}

if ($clienteLogado && !$staffLogado
    && ((int) $documento['cliente_id'] !== ClienteAuth::clienteId() || $documento['categoria'] !== 'Implantação')
) {
    http_response_code(403);
    die('Acesso não autorizado.');
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
