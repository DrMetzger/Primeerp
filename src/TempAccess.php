<?php

namespace AdminPrime;

use PDO;

/**
 * Links temporários de leitura, gerados por um admin logado, para permitir
 * que uma ferramenta externa (ex: o Claude) acesse UMA página de relatório
 * específica sem precisar de sessão/cookie — sem deixar nenhum "bypass" fixo
 * no código (cada token é aleatório, guardado no banco e expira sozinho).
 */
class TempAccess
{
    public static function gerar(PDO $pdo, int $usuarioId, int $minutos = 30): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO temp_access_tokens (token, criado_por, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))');
        $stmt->execute([$token, $usuarioId, $minutos]);
        return $token;
    }

    public static function valido(PDO $pdo, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM temp_access_tokens WHERE token = ? AND expira_em > NOW()');
        $stmt->execute([$token]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
