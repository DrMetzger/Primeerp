<?php

namespace AdminPrime;

use PDO;

/**
 * Exporta o banco inteiro (estrutura + dados) pra um .sql.gz -- usado UMA
 * vez na migração da Hostinger pra VM (suporte.primeerp.com.br). Só roda
 * pela linha de comando (cron), nunca pela web: o arquivo vai pra
 * config/migracao/, pasta bloqueada pelo .htaccess, e a VM busca por FTP.
 *
 * Disparo: existir o arquivo config/migracao/EXPORTAR (criado pelo
 * workflow de migração via FTP). Ao terminar, grava config/migracao/PRONTO.
 */
class ExportarBanco
{
    public const PASTA = __DIR__ . '/../config/migracao';

    public static function seSolicitado(): void
    {
        if (PHP_SAPI !== 'cli' || !is_file(self::PASTA . '/EXPORTAR')) {
            return;
        }
        @unlink(self::PASTA . '/EXPORTAR');
        @unlink(self::PASTA . '/PRONTO');
        @set_time_limit(0);

        try {
            $inicio = microtime(true);
            $destino = self::PASTA . '/primehub.sql.gz';
            $linhas = self::exportar($destino . '.parcial');
            rename($destino . '.parcial', $destino);
            file_put_contents(self::PASTA . '/PRONTO', json_encode([
                'linhas' => $linhas,
                'bytes' => filesize($destino),
                'sha256' => hash_file('sha256', $destino),
                'segundos' => round(microtime(true) - $inicio, 1),
                'em' => date('c'),
            ]));
            echo "Banco exportado: {$linhas} linhas\n";
        } catch (\Throwable $e) {
            file_put_contents(self::PASTA . '/PRONTO', json_encode(['erro' => $e->getMessage()]));
            error_log('ExportarBanco: ' . $e->getMessage());
        }
    }

    private static function exportar(string $arquivo): int
    {
        // Conexão própria, sem buffer: tabela com anexos (LONGBLOB) não cabe na memória.
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
        $pdo->exec('SET SESSION wait_timeout = 3600, SESSION net_write_timeout = 600');
        $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $gz = gzopen($arquivo, 'wb6');
        gzwrite($gz, "-- Prime Hub: exportação para migração (" . date('c') . ")\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $total = 0;
        foreach ($meta->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$tabela]) {
            $criar = $meta->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$tabela`;\n$criar;\n\n");

            $binarias = [];
            foreach ($meta->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $binarias[$col['Field']] = (bool) preg_match('/blob|binary/i', $col['Type']);
            }

            $lote = [];
            $tamanhoLote = 0;
            $stmt = $pdo->query("SELECT * FROM `$tabela`");
            while ($linha = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $valores = [];
                foreach ($linha as $campo => $valor) {
                    if ($valor === null) {
                        $valores[] = 'NULL';
                    } elseif ($binarias[$campo] ?? false) {
                        $valores[] = $valor === '' ? "''" : '0x' . bin2hex($valor);
                    } else {
                        $valores[] = $meta->quote((string) $valor);
                    }
                }
                $tupla = '(' . implode(',', $valores) . ')';
                $lote[] = $tupla;
                $tamanhoLote += strlen($tupla);
                $total++;
                if ($tamanhoLote > 4 * 1024 * 1024) {
                    gzwrite($gz, "INSERT INTO `$tabela` VALUES\n" . implode(",\n", $lote) . ";\n");
                    $lote = [];
                    $tamanhoLote = 0;
                }
            }
            $stmt->closeCursor();
            if ($lote) {
                gzwrite($gz, "INSERT INTO `$tabela` VALUES\n" . implode(",\n", $lote) . ";\n");
            }
            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
        gzclose($gz);

        return $total;
    }
}
