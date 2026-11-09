<?php

namespace AdminPrime;

use PDO;
use PDOException;

/**
 * Conexão PDO única (singleton) com o MySQL/MariaDB.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                // O MySQL da Hostinger derruba conexão ociosa muito rápido
                // ("MySQL server has gone away") -- a IA espera o Google por
                // 25s+ entre uma query e outra e a conexão morria no meio.
                try {
                    self::$instance->exec('SET SESSION wait_timeout = 600, SESSION interactive_timeout = 600');
                } catch (PDOException $e) {
                    error_log('Não foi possível ajustar o wait_timeout do MySQL: ' . $e->getMessage());
                }
            } catch (PDOException $e) {
                error_log('Falha na conexão com o banco: ' . $e->getMessage());
                http_response_code(500);
                die('Não foi possível conectar ao banco de dados. Verifique as configurações em config/config.php.');
            }
        }

        return self::$instance;
    }

    /**
     * Descarta a conexão atual e abre uma nova -- usado depois de um erro que
     * mata a conexão no meio (ex.: INSERT de anexo grande passando do
     * max_allowed_packet do MySQL, que faz o servidor fechar a conexão --
     * "MySQL server has gone away"). Sem isso, qualquer tentativa de usar o
     * mesmo $pdo depois desse erro falha de novo, mesmo numa query pequena.
     */
    public static function reconnect(): PDO
    {
        self::$instance = null;

        return self::connection();
    }
}
