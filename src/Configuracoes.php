<?php

namespace AdminPrime;

use PDO;

/**
 * Configurações editáveis pela tela configuracoes.php (integração WhatsApp
 * e SMTP/e-mail), guardadas na tabela `configuracoes` -- chave/valor simples.
 * Valor NULL/vazio no banco = "sem override", quem chama get() cai pro
 * $padrao (normalmente a constante já definida em config/config.php). Isso
 * significa que o comportamento de hoje (tudo via variável de ambiente/
 * config.php) continua funcionando exatamente igual até alguém preencher
 * algo pela tela -- nenhum código existente precisa saber que essa tabela
 * existe.
 */
class Configuracoes
{
    private static ?array $cache = null;

    private static function carregarTodas(PDO $pdo): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach ($pdo->query('SELECT chave, valor FROM configuracoes')->fetchAll() as $linha) {
                self::$cache[$linha['chave']] = $linha['valor'];
            }
        }

        return self::$cache;
    }

    public static function get(PDO $pdo, string $chave, ?string $padrao = null): ?string
    {
        $valor = self::carregarTodas($pdo)[$chave] ?? null;

        return ($valor !== null && $valor !== '') ? $valor : $padrao;
    }

    public static function definir(PDO $pdo, string $chave, ?string $valor, ?int $usuarioId): void
    {
        $valor = ($valor !== null && trim($valor) !== '') ? trim($valor) : null;

        $pdo->prepare('
            INSERT INTO configuracoes (chave, valor, atualizado_por) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE valor = ?, atualizado_por = ?
        ')->execute([$chave, $valor, $usuarioId, $valor, $usuarioId]);

        if (self::$cache !== null) {
            self::$cache[$chave] = $valor;
        }
    }

    /**
     * Todas as chaves cadastradas (mesmo as vazias/NULL) -- usado só pela
     * tela de configurações pra pré-popular os campos do formulário com o
     * que já foi salvo antes.
     */
    public static function todas(PDO $pdo): array
    {
        return self::carregarTodas($pdo);
    }
}
