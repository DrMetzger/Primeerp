<?php
/**
 * Script temporário de investigação do banco do HESK.
 * NÃO FICA NO REPOSITÓRIO — será removido no próximo commit, logo após a leitura.
 * Protegido por token secreto na URL (não por login, para permitir leitura via ferramenta externa).
 */

$token = $_GET['t'] ?? '';
if (!hash_equals('kf9x7m2q8w1z5r3t6y0p4n8v2b6c9x1z', $token)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=u195563148_help;charset=utf8mb4', 'u195563148_help', 'I31&vq~=k5', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "ERRO DE CONEXAO: " . $e->getMessage();
    exit;
}

echo "=== TABELAS ===\n";
$tabelas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tabelas as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "$t (linhas: $count)\n";
}

echo "\n=== ESTRUTURA DE CADA TABELA ===\n";
foreach ($tabelas as $t) {
    echo "\n--- $t ---\n";
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll();
    foreach ($cols as $c) {
        echo "  {$c['Field']} ({$c['Type']}) " . ($c['Key'] ? "[{$c['Key']}]" : '') . "\n";
    }
}
