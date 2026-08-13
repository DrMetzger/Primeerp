<?php

/**
 * Streaming/download de vídeo aula -- diferente de download.php porque
 * precisa: (1) aceitar login da EQUIPE (Auth) OU do PORTAL DO CLIENTE
 * (ClienteAuth, escopado por setor a menos que tenha permissão de master),
 * e (2) responder a Range requests (206 Partial Content) pra permitir dar
 * play/avançar o vídeo direto no navegador sem baixar o arquivo (até
 * 500MB) inteiro primeiro.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Migrations;

$pdo = Database::connection();

$videoId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM documentos WHERE id = ? AND categoria = 'Vídeo aula'");
$stmt->execute([$videoId]);
$video = $stmt->fetch();

if (!$video) {
    http_response_code(404);
    die('Vídeo aula não encontrada.');
}

$autorizado = false;
if (Auth::check()) {
    $autorizado = true;
} elseif (ClienteAuth::check()) {
    ClienteAuth::bloquearForaDoHorario();
    if ((int) $video['cliente_id'] === ClienteAuth::clienteId()) {
        $autorizado = ClienteAuth::temPermissaoMaster()
            || in_array((int) $video['setor_id'], ClienteAuth::setorIds(), true);
    }
}

if (!$autorizado) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

// Restaura do backup no banco se o arquivo tiver desaparecido do disco
// (deploy do Hostinger recria uploads/ do zero -- ver Migrations).
Migrations::restaurarArquivoSeNecessario($pdo, 'documentos', $videoId, 'arquivo_caminho', 'dados', UPLOADS_DIR);

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $video['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo de vídeo não encontrado.');
}

$tamanho = filesize($caminhoCompleto);
$baixar = ($_GET['baixar'] ?? '') === '1';

header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=0');
if ($baixar) {
    header('Content-Disposition: attachment; filename="' . basename($video['arquivo_nome_original']) . '"');
}

$inicio = 0;
$fim = $tamanho - 1;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $inicio = $m[1] === '' ? 0 : (int) $m[1];
    $fim = $m[2] === '' ? $tamanho - 1 : (int) $m[2];

    if ($inicio > $fim || $inicio >= $tamanho) {
        http_response_code(416);
        header('Content-Range: bytes */' . $tamanho);
        exit;
    }

    http_response_code(206);
    header('Content-Range: bytes ' . $inicio . '-' . $fim . '/' . $tamanho);
}

header('Content-Length: ' . ($fim - $inicio + 1));

$handle = fopen($caminhoCompleto, 'rb');
fseek($handle, $inicio);
$restante = $fim - $inicio + 1;

while ($restante > 0 && !feof($handle)) {
    $bloco = min(8192, $restante);
    echo fread($handle, $bloco);
    $restante -= $bloco;
    flush();
}
fclose($handle);
exit;
