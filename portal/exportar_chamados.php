<?php

/**
 * Exporta os PRÓPRIOS chamados do cliente logado no portal pra CSV (abre
 * direto no Excel) -- pedido do Kleber, tem cliente que faz esse export
 * todo mês. Sempre os chamados do cliente da SESSÃO (nunca aceita um id de
 * cliente por parâmetro), pra um cliente nunca conseguir baixar chamado de
 * outra empresa trocando a URL.
 */

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();

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

$nomeArquivo = 'meus_chamados_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

echo "\xEF\xBB\xBF"; // BOM UTF-8

$saida = fopen('php://output', 'w');
fputcsv($saida, ['Título', 'Status', 'Prioridade', 'Categoria', 'Responsável PrimeERP', 'Aberto em', 'Descrição'], ';', '"', '\\');

$mapaStatus = ['aberto' => 'Aberto', 'aguardando_equipe' => 'Pendente (com a PrimeERP)', 'aguardando_cliente' => 'Atrasado (com o cliente)', 'fechado' => 'Encerrado'];
$mapaPrioridade = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];

foreach ($chamados as $c) {
    fputcsv($saida, [
        $c['titulo'],
        $mapaStatus[$c['status']] ?? $c['status'],
        $mapaPrioridade[$c['prioridade']] ?? $c['prioridade'],
        $c['categoria'] ?? '',
        $c['responsavel_nome'] ?? '',
        formatar_data($c['criado_em']),
        preg_replace('/\s+/', ' ', trim($c['descricao'])),
    ], ';', '"', '\\');
}

fclose($saida);
