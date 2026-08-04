<?php

namespace AdminPrime;

use PDO;
use PDOException;

/**
 * Conexão somente leitura com o banco do HESK (sistema de chamados atual em
 * primeerp.com.br/helpdesk/). Usada para trazer os chamados existentes para
 * dentro do AdminPrime.
 */
class HeskDatabase
{
    private static ?PDO $instance = null;

    public static function connection(): ?PDO
    {
        if (HESK_DB_NAME === '') {
            return null;
        }

        if (self::$instance === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', HESK_DB_HOST, HESK_DB_NAME);
            try {
                self::$instance = new PDO($dsn, HESK_DB_USER, HESK_DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                error_log('Falha na conexão com o banco do HESK: ' . $e->getMessage());
                return null;
            }
        }

        return self::$instance;
    }
}
