<?php

/**
 * Download de fonte ADVPL de um setor (sub-aba "Fontes ADVPL" em
 * cliente.php, ou portal/fontes_advpl.php) -- código-fonte proprietário do
 * cliente, mesmo nível de sigilo do cofre de acessos. Aceita DOIS tipos de
 * sessão, igual video_aula.php: equipe (Auth::requireAcessoDadosSensiveis())
 * OU portal do cliente -- mas aqui, diferente de vídeo aula, o portal só
 * libera pro MASTER (contato principal), nunca por login de setor
 * específico -- pedido explícito do Kleber ("somente acesso master pode
 * baixar esses fontes").
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Migrations;

$pdo = Database::connection();

$fonteId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT f.*, s.cliente_id AS setor_cliente_id
    FROM setor_fontes_advpl f
    INNER JOIN setores s ON s.id = f.setor_id
    WHERE f.id = ?
');
$stmt->execute([$fonteId]);
$fonte = $stmt->fetch();

if (!$fonte) {
    http_response_code(404);
    die('Fonte não encontrada.');
}

$autorizado = false;
if (Auth::check()) {
    $autorizado = Auth::podeAcessarDadosSensiveis();
} elseif (ClienteAuth::check()) {
    ClienteAuth::bloquearForaDoHorario();
    $autorizado = ClienteAuth::temPermissaoMaster() && (int) $fonte['setor_cliente_id'] === ClienteAuth::clienteId();
}

if (!$autorizado) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

Migrations::restaurarArquivoSeNecessario($pdo, 'setor_fontes_advpl', $fonteId, 'arquivo_caminho', 'dados', UPLOADS_DIR);

$caminhoCompleto = realpath(UPLOADS_DIR . '/' . $fonte['arquivo_caminho']);
$uploadsBase = realpath(UPLOADS_DIR);

if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
    http_response_code(404);
    die('Arquivo não encontrado.');
}

Auth::registrarAcessoSensivel('fonte_advpl', 'baixar_arquivo', $fonteId, (int) $fonte['setor_cliente_id']);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($fonte['arquivo_nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoCompleto));
header('Cache-Control: private');

readfile($caminhoCompleto);
exit;
