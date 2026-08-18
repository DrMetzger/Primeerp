<?php

/**
 * Recebe UM PEDAÇO (poucos MB) de um upload de vídeo aula, via AJAX --
 * chamado várias vezes em sequência pelo JS de cliente.php, uma vez por
 * pedaço do arquivo (ver .form-video-aula-chunk em assets/js embutido).
 *
 * Existe pra resolver um bug real: mandar o vídeo inteiro (até 5GB) numa
 * ÚNICA requisição deixava a barra travada em "Registrando..." de verdade
 * (não só visualmente) por dezenas de minutos, sem nenhuma resposta do
 * servidor -- shared hosting tem várias camadas (proxy, LiteSpeed, disco)
 * fora do nosso controle que podem segurar uma requisição gigante por muito
 * tempo, e nenhum ini_set/set_time_limit do lado do PHP resolve isso de
 * verdade. Quebrando em pedaços pequenos, cada requisição individual
 * termina em segundos (sempre dá pra confirmar sucesso de verdade), e a
 * barra de progresso reflete pedaço enviado de fato, não um chute.
 *
 * Cada pedaço vira um arquivo próprio em uploads/tmp_chunks/ (nomeado
 * upload_id + índice) -- no ÚLTIMO pedaço, concatena todos em ordem no
 * destino final e só ENTÃO grava o registro em "documentos" (mesma tabela/
 * categoria 'Vídeo aula' de sempre, ver salvar_documento_upload()).
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Migrations;

Auth::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']);
    exit;
}

// Quando post_max_size é excedido, o PHP silenciosamente zera $_POST/$_FILES
// inteiros (sem avisar em lugar nenhum que dê pra pegar aqui) -- sem essa
// checagem, isso vira um "sessão expirada" (csrf_token vazio) bem confuso
// de diagnosticar depois. Detecta esse caso específico e avisa direto.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Pedaço grande demais pro servidor aceitar (post_max_size). Avise o suporte.']);
    exit;
}

csrf_verify();

$pdo = Database::connection();

$clienteId = (int) ($_POST['cliente_id'] ?? 0);
$setorId = (int) ($_POST['setor_id'] ?? 0) ?: null;
$nomeRotina = trim($_POST['nome_rotina'] ?? '');
$nomeArquivoOriginal = basename($_POST['nome_arquivo'] ?? 'video.mp4');
// upload_id vem do JS (32 chars hex, gerado por requisição de upload) -- só
// serve pra agrupar os pedaços de UM envio, não é segredo nem precisa ser
// imprevisível; sanitiza mesmo assim pra nunca virar um caminho de arquivo
// malicioso (path traversal) na hora de montar o nome do pedaço em disco.
$uploadId = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['upload_id'] ?? ''));
$indice = (int) ($_POST['indice'] ?? -1);
$totalPartes = (int) ($_POST['total_partes'] ?? 0);

$stmtCliente = $pdo->prepare('SELECT 1 FROM clientes WHERE id = ?');
$stmtCliente->execute([$clienteId]);

$valido = $clienteId > 0 && $stmtCliente->fetchColumn()
    && $nomeRotina !== ''
    && strlen($uploadId) === 32
    && $indice >= 0 && $totalPartes > 0 && $indice < $totalPartes
    && isset($_FILES['pedaco']);

// Confirma que o setor é mesmo desse cliente -- mesmo cuidado já tomado em
// upload_fonte_advpl (cliente.php): sem isso, alguém poderia mandar o id de
// um setor de OUTRO cliente e a vídeo aula ficaria visível pra quem não
// devia.
if ($valido && $setorId) {
    $stmtSetor = $pdo->prepare('SELECT 1 FROM setores WHERE id = ? AND cliente_id = ?');
    $stmtSetor->execute([$setorId, $clienteId]);
    $valido = (bool) $stmtSetor->fetchColumn();
}

if (!$valido) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Requisição inválida.']);
    exit;
}

if ($indice === 0) {
    Migrations::limparPedacosVideoAulaAbandonados(UPLOADS_DIR);
}

if ($_FILES['pedaco']['error'] !== UPLOAD_ERR_OK) {
    // UPLOAD_ERR_INI_SIZE aqui quer dizer que upload_max_filesize está
    // menor que o tamanho do pedaço -- normalmente já resolvido pelo
    // override do .htaccess (mesmo que sustenta qualquer outro upload
    // desse site), mas se algum dia mudar de hospedagem sem levar o
    // .htaccess, é isso que vai aparecer aqui.
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Falha ao receber o pedaço no servidor (código ' . $_FILES['pedaco']['error'] . ').']);
    exit;
}

$dirTemp = UPLOADS_DIR . '/tmp_chunks';
if (!is_dir($dirTemp)) {
    mkdir($dirTemp, 0755, true);
}

$caminhoParte = $dirTemp . '/' . $uploadId . '_' . $indice;
if (!move_uploaded_file($_FILES['pedaco']['tmp_name'], $caminhoParte)) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Falha ao salvar o pedaço no servidor.']);
    exit;
}

// Ainda não é o último pedaço -- só confirma recebimento, nada mais a fazer
// até o pedido do próximo.
if ($indice < $totalPartes - 1) {
    echo json_encode(['sucesso' => true]);
    exit;
}

// --- Último pedaço: monta o arquivo final e grava o registro -----------
set_time_limit(120); // concatenar pedaços é I/O local rápido, mas dá uma folga.

$tamanhoTotal = 0;
for ($i = 0; $i < $totalPartes; $i++) {
    $parte = $dirTemp . '/' . $uploadId . '_' . $i;
    if (!is_file($parte)) {
        // Algum pedaço no meio nunca chegou (aba fechada, rede caiu etc.)
        // -- limpa o que já tem desse upload_id em vez de deixar lixo.
        for ($j = 0; $j < $totalPartes; $j++) {
            @unlink($dirTemp . '/' . $uploadId . '_' . $j);
        }
        http_response_code(409);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Pedaço ' . ($i + 1) . ' de ' . $totalPartes . ' não chegou. Tente enviar de novo.']);
        exit;
    }
    $tamanhoTotal += filesize($parte);
}

if ($tamanhoTotal > VIDEO_AULA_MAX_SIZE) {
    for ($i = 0; $i < $totalPartes; $i++) {
        @unlink($dirTemp . '/' . $uploadId . '_' . $i);
    }
    http_response_code(413);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Arquivo maior que o limite permitido (' . (int) (VIDEO_AULA_MAX_SIZE / 1024 / 1024) . 'MB).']);
    exit;
}

$dirCliente = UPLOADS_DIR . '/clientes/' . $clienteId;
if (!is_dir($dirCliente)) {
    mkdir($dirCliente, 0755, true);
}
$nomeArmazenado = bin2hex(random_bytes(16)) . '.mp4';
$destino = $dirCliente . '/' . $nomeArmazenado;

$destinoHandle = fopen($destino, 'wb');
for ($i = 0; $i < $totalPartes; $i++) {
    $caminhoParteAtual = $dirTemp . '/' . $uploadId . '_' . $i;
    $parteHandle = fopen($caminhoParteAtual, 'rb');
    stream_copy_to_stream($parteHandle, $destinoHandle);
    fclose($parteHandle);
    @unlink($caminhoParteAtual);
}
fclose($destinoHandle);

if (!isset(VIDEO_AULA_TIPOS_PERMITIDOS[mime_content_type($destino)])) {
    @unlink($destino);
    http_response_code(422);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Envie um arquivo de vídeo no formato MP4.']);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO documentos (cliente_id, setor_id, titulo, categoria, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $clienteId,
    $setorId,
    $nomeRotina,
    'Vídeo aula',
    $nomeArquivoOriginal,
    'clientes/' . $clienteId . '/' . $nomeArmazenado,
    $tamanhoTotal,
    Auth::userId(),
]);

echo json_encode(['sucesso' => true, 'finalizado' => true, 'documento_id' => (int) $pdo->lastInsertId()]);
