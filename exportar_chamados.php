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

$sql = 'SELECT c.*, u.nome AS responsavel_nome FROM chamados c LEFT JOIN usuarios u ON u.id = c.responsavel_id WHERE c.cliente_id = ?';
$params = [$clienteId];
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

$mapaStatus = ['aberto' => 'Aberto', 'em_andamento' => 'Em andamento', 'respondido' => 'Respondido', 'resolvido' => 'Resolvido', 'fechado' => 'Fechado'];
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
