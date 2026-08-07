<?php

/**
 * Exporta os usuários (master + responsáveis de setor) de um cliente para
 * CSV (abre direto no Excel). Mesmo esquema de exportar_chamados.php: ; como
 * separador e BOM UTF-8 pra acentuação abrir certa.
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

$usuarios = $pdo->prepare('
    SELECT sr.nome, sr.email, sr.telefone, sr.documento, sr.portal_ativo, s.nome AS setor_nome
    FROM setor_responsaveis sr
    LEFT JOIN setores s ON s.id = sr.setor_id
    WHERE sr.cliente_id = ?
    ORDER BY sr.nome ASC
');
$usuarios->execute([$clienteId]);
$usuarios = $usuarios->fetchAll();

$nomeArquivo = 'usuarios_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $cliente['nome']) . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

echo "\xEF\xBB\xBF"; // BOM UTF-8

$saida = fopen('php://output', 'w');
fputcsv($saida, ['Nome', 'E-mail', 'Telefone', 'CPF/RG', 'Setor', 'Acesso ao portal'], ';', '"', '\\');

fputcsv($saida, [
    $cliente['contato_nome'] ?: $cliente['nome'],
    $cliente['contato_email'] ?? '',
    $cliente['contato_telefone'] ? formatar_telefone($cliente['contato_telefone']) : '',
    $cliente['contato_documento'] ?? '',
    'Master (todos os setores)',
    $cliente['portal_ativo'] ? 'Ativo' : 'Sem acesso',
], ';', '"', '\\');

foreach ($usuarios as $u) {
    fputcsv($saida, [
        $u['nome'],
        $u['email'],
        $u['telefone'] ? formatar_telefone($u['telefone']) : '',
        $u['documento'] ?? '',
        $u['setor_nome'] ?? 'Sem setor',
        $u['portal_ativo'] ? 'Ativo' : 'Sem acesso',
    ], ';', '"', '\\');
}

fclose($saida);
