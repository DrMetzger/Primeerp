<?php

/**
 * Exporta os chamados de um cliente para CSV (abre direto no Excel).
 * Usa ; como separador e BOM UTF-8 pra acentuação abrir certa no Excel.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireLogin();

$pdo = Database::connection();

$clienteId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    die('Cliente não encontrado.');
}

$statusFiltro = $_GET['status'] ?? '';

// Datas vêm de <input type="date"> (formato YYYY-MM-DD) -- valida antes de
// usar na query pra não aceitar lixo.
function data_valida(?string $valor): ?string
{
    if (!$valor) {
        return null;
    }
    $d = \DateTime::createFromFormat('Y-m-d', $valor);
    return ($d && $d->format('Y-m-d') === $valor) ? $valor : null;
}

$dataDe = data_valida($_GET['data_de'] ?? null);
$dataAte = data_valida($_GET['data_ate'] ?? null);

// Período obrigatório -- pedido do Kleber pra não deixar exportar sem
// querer o histórico inteiro (arquivo enorme, difícil de abrir/analisar).
if ($dataDe === null || $dataAte === null) {
    http_response_code(400);
    die('Informe o período (data de / data até) antes de exportar.');
}

$sql = 'SELECT c.*, u.nome AS responsavel_nome FROM chamados c LEFT JOIN usuarios u ON u.id = c.responsavel_id WHERE c.cliente_id = ? AND c.criado_em >= ? AND c.criado_em <= ?';
$params = [$clienteId, $dataDe . ' 00:00:00', $dataAte . ' 23:59:59'];
if ($statusFiltro !== '' && $statusFiltro !== 'todos') {
    $sql .= ' AND c.status = ?';
    $params[] = $statusFiltro;
}
$sql .= ' ORDER BY c.criado_em DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$chamados = $stmt->fetchAll();

$nomeArquivo = 'chamados_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $cliente['nome']) . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

echo "\xEF\xBB\xBF"; // BOM UTF-8

$saida = fopen('php://output', 'w');
fputcsv($saida, ['Título', 'Status', 'Prioridade', 'Categoria', 'Aberto por', 'Responsável', 'Aberto em', 'Descrição'], ';', '"', '\\');

$mapaStatus = ['aberto' => 'Aberto', 'aguardando_equipe' => 'Aguardando nós', 'aguardando_cliente' => 'Aguardando cliente', 'fechado' => 'Fechado'];
$mapaPrioridade = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];

foreach ($chamados as $c) {
    fputcsv($saida, [
        $c['titulo'],
        $mapaStatus[$c['status']] ?? $c['status'],
        $mapaPrioridade[$c['prioridade']] ?? $c['prioridade'],
        $c['categoria'] ?? '',
        $c['aberto_por'] === 'cliente' ? 'Cliente' : 'Equipe',
        $c['responsavel_nome'] ?? '',
        formatar_data($c['criado_em']),
        preg_replace('/\s+/', ' ', trim($c['descricao'])),
    ], ';', '"', '\\');
}

fclose($saida);
