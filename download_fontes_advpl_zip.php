<?php

/**
 * Baixa TODAS as fontes ADVPL de um setor de uma vez, compactadas num único
 * ZIP -- mesmo nível de sigilo/autorização de download_fonte_advpl.php
 * (equipe com acesso a dados sensíveis, OU portal do cliente só pro
 * MASTER), só que aqui em lote por setor em vez de um arquivo por vez.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Migrations;

$pdo = Database::connection();

$setorId = (int) ($_GET['setor_id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT s.id, s.nome, s.cliente_id
    FROM setores s
    WHERE s.id = ?
');
$stmt->execute([$setorId]);
$setor = $stmt->fetch();

if (!$setor) {
    http_response_code(404);
    die('Setor não encontrado.');
}

$autorizado = false;
if (Auth::check()) {
    $autorizado = Auth::podeAcessarDadosSensiveis();
} elseif (ClienteAuth::check()) {
    ClienteAuth::bloquearForaDoHorario();
    $autorizado = ClienteAuth::temPermissaoMaster() && (int) $setor['cliente_id'] === ClienteAuth::clienteId();
}

if (!$autorizado) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

$stmt = $pdo->prepare('SELECT * FROM setor_fontes_advpl WHERE setor_id = ? ORDER BY titulo ASC');
$stmt->execute([$setorId]);
$fontes = $stmt->fetchAll();

// Colaborador restrito só baixa em lote o que ele mesmo cadastrou -- mesma
// regra de apenasProprios() aplicada em cliente.php/download_fonte_advpl.php.
if (Auth::check() && Auth::apenasProprios()) {
    $fontes = array_values(array_filter($fontes, fn ($f) => (int) $f['criado_por'] === Auth::userId()));
}

if (!$fontes) {
    http_response_code(404);
    die('Nenhuma fonte ADVPL cadastrada pra esse setor.');
}

$zipTemp = tempnam(sys_get_temp_dir(), 'fontes_advpl_');
$zip = new ZipArchive();
if ($zip->open($zipTemp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Não foi possível montar o arquivo ZIP.');
}

// Nome do arquivo dentro do ZIP -- prefixa com o id quando o mesmo nome
// original se repete (duplicidade é permitida na tabela, mas duas entradas
// "MEUFONTE.tlpp" dentro do MESMO ZIP se sobrescreveriam).
$nomesUsados = [];
foreach ($fontes as $f) {
    Migrations::restaurarArquivoSeNecessario($pdo, 'setor_fontes_advpl', (int) $f['id'], 'arquivo_caminho', 'dados', UPLOADS_DIR);

    $caminhoCompleto = realpath(UPLOADS_DIR . '/' . $f['arquivo_caminho']);
    $uploadsBase = realpath(UPLOADS_DIR);
    if (!$caminhoCompleto || !$uploadsBase || !str_starts_with($caminhoCompleto, $uploadsBase) || !is_file($caminhoCompleto)) {
        continue;
    }

    $nomeNoZip = basename($f['arquivo_nome_original']);
    if (isset($nomesUsados[$nomeNoZip])) {
        $nomeNoZip = $f['id'] . '_' . $nomeNoZip;
    }
    $nomesUsados[$nomeNoZip] = true;

    $zip->addFile($caminhoCompleto, $nomeNoZip);
}

$zip->close();

Auth::registrarAcessoSensivel('fonte_advpl', 'baixar_zip_setor', $setorId, (int) $setor['cliente_id']);

$nomeZip = 'fontes_advpl_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $setor['nome']) . '.zip';

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nomeZip . '"');
header('Content-Length: ' . filesize($zipTemp));
header('Cache-Control: private');

readfile($zipTemp);
unlink($zipTemp);
exit;
