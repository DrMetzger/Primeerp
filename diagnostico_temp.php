<?php
/**
 * TEMPORÁRIO -- diagnóstico do erro 500 (09/11/2026). Mostra as últimas
 * linhas do log de erros do PHP, com chaves/senhas mascaradas. Protegido
 * pelo CRON_SECRET. Remover assim que o problema for corrigido.
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');
if (!defined('CRON_SECRET') || !hash_equals(CRON_SECRET, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit("Acesso negado.\n");
}

echo 'PHP ' . PHP_VERSION . ' | ' . PHP_SAPI . ' | memória ' . ini_get('memory_limit') . ' | log=' . ini_get('error_log') . "\n";
echo 'disco livre: ' . round((float) @disk_free_space(__DIR__) / 1048576) . " MB\n";
echo 'sessões: ' . session_save_path() . ' gravável=' . (is_writable(session_save_path() ?: sys_get_temp_dir()) ? 'sim' : 'NÃO') . "\n\n";

$mascarar = fn ($t) => preg_replace(['/(key=)[^\s&"\']+/i', '/AIza[0-9A-Za-z_-]{20,}/', '/AQ\.[0-9A-Za-z_.-]{20,}/', '/(senha|password|pass)\S*\s*[=:]\s*\S+/i'], ['$1***', '***', '***', '$1=***'], $t);

$candidatos = array_unique(array_filter([
    ini_get('error_log'),
    __DIR__ . '/error_log',
    __DIR__ . '/portal/error_log',
    dirname(__DIR__) . '/error_log',
]));
foreach ($candidatos as $arquivo) {
    if (!is_file($arquivo)) {
        continue;
    }
    $tamanho = filesize($arquivo);
    echo "=== $arquivo (" . round($tamanho / 1024) . " KB, modificado " . date('d/m H:i', filemtime($arquivo)) . ") ===\n";
    $fp = fopen($arquivo, 'r');
    fseek($fp, max(0, $tamanho - 12000));
    $linhas = array_slice(explode("\n", stream_get_contents($fp)), -40);
    fclose($fp);
    foreach ($linhas as $linha) {
        echo $mascarar(mb_substr($linha, 0, 300)) . "\n";
    }
    echo "\n";
}
