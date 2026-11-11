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

    /**
     * Exportação em PEDAÇOS, pela web (não depende do cron): cada chamada
     * grava mais um trecho no .sql.gz e devolve só o progresso -- nunca os
     * dados. Só aceita quem mandar o token de uso único que o workflow
     * colocou por FTP em config/migracao/TOKEN (quem tem FTP já tem acesso a
     * tudo mesmo). Arquivo final: config/migracao/primehub.sql.gz + PRONTO.
     */
    public static function passoWeb(string $token, int $segundos = 15): array
    {
        $arqToken = self::PASTA . '/TOKEN';
        if (!is_file($arqToken) || strlen(trim((string) file_get_contents($arqToken))) < 32
            || !hash_equals(trim((string) file_get_contents($arqToken)), $token)) {
            return ['ok' => false, 'erro' => 'token inválido'];
        }

        $arqEstado = self::PASTA . '/estado.json';
        $destino = self::PASTA . '/primehub.sql.gz';
        $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        if (!is_file($arqEstado)) {
            @unlink($destino);
            $tabelas = array_column($meta->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM), 0);
            $estado = ['tabelas' => $tabelas, 'i' => 0, 'offset' => 0, 'linhas' => 0, 'inicio' => microtime(true)];
            $gz = gzopen($destino, 'ab6');
            gzwrite($gz, "-- Prime Hub: exportação para migração (" . date('c') . ")\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
            gzclose($gz);
        } else {
            $estado = json_decode((string) file_get_contents($arqEstado), true);
        }

        $limite = microtime(true) + $segundos;
        $gz = gzopen($destino, 'ab6');
        $primeiraVolta = true; // sempre avança pelo menos um lote por chamada
        while ($estado['i'] < count($estado['tabelas']) && ($primeiraVolta || microtime(true) < $limite)) {
            $primeiraVolta = false;
            $tabela = $estado['tabelas'][$estado['i']];
            if ($estado['offset'] === 0) {
                $criar = $meta->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
                gzwrite($gz, "DROP TABLE IF EXISTS `$tabela`;\n$criar;\n\n");
            }
            $binarias = [];
            foreach ($meta->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $binarias[$col['Field']] = (bool) preg_match('/blob|binary/i', $col['Type']);
            }
            $chave = $meta->query("SHOW KEYS FROM `$tabela` WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
            $ordem = $chave ? ' ORDER BY ' . implode(', ', array_map(fn ($k) => '`' . $k['Column_name'] . '`', $chave)) : '';
            // Lote pequeno quando a tabela tem anexos (LONGBLOB), pra caber na memória/tempo.
            $lote = in_array(true, $binarias, true) ? 20 : 2000;
            $linhas = $meta->query("SELECT * FROM `$tabela`$ordem LIMIT $lote OFFSET " . (int) $estado['offset'])->fetchAll(PDO::FETCH_ASSOC);

            if ($linhas) {
                $tuplas = [];
                foreach ($linhas as $linha) {
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
                    $tuplas[] = '(' . implode(',', $valores) . ')';
                }
                gzwrite($gz, "INSERT INTO `$tabela` VALUES\n" . implode(",\n", $tuplas) . ";\n");
                $estado['offset'] += count($linhas);
                $estado['linhas'] += count($linhas);
            }
            if (count($linhas) < $lote) {
                $estado['i']++;
                $estado['offset'] = 0;
            }
        }

        $pronto = $estado['i'] >= count($estado['tabelas']);
        if ($pronto) {
            gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
        }
        gzclose($gz);

        if (!$pronto) {
            file_put_contents($arqEstado, json_encode($estado));

            return ['ok' => true, 'pronto' => false, 'tabela' => $estado['i'] . '/' . count($estado['tabelas']), 'linhas' => $estado['linhas']];
        }

        @unlink($arqEstado);
        $resumo = [
            'linhas' => $estado['linhas'],
            'bytes' => filesize($destino),
            'sha256' => hash_file('sha256', $destino),
            'segundos' => round(microtime(true) - $estado['inicio'], 1),
            'em' => date('c'),
        ];
        file_put_contents(self::PASTA . '/PRONTO', json_encode($resumo));

        return ['ok' => true, 'pronto' => true] + $resumo;
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
