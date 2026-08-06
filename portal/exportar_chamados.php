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

// Datas vêm de <input type="date"> (formato YYYY-MM-DD) -- valida antes de
// usar na query pra não aceitar lixo. Mesmo esquema de exportar_chamados.php
// (lado da equipe).
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
// Login de setor só exporta os PRÓPRIOS chamados -- mesma independência
// entre setores aplicada aqui. Login master exporta de todos os setores de
// uma vez, sem esse filtro (é o pedido original do master).
if (!ClienteAuth::isMaster()) {
    $sql .= ' AND c.setor_id = ?';
    $params[] = ClienteAuth::setorId();
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
