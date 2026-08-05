<?php

namespace AdminPrime;

use PDO;

/**
 * Autenticação do PORTAL DO CLIENTE — separada do login da equipe (Auth).
 * Usa chaves de sessão diferentes para não conflitar com o login interno.
 */
class ClienteAuth
{
    public static function attempt(PDO $pdo, string $email, string $senha): bool
    {
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE contato_email = ? AND portal_ativo = 1 AND senha_hash IS NOT NULL LIMIT 1");
        $stmt->execute([$email]);
        $cliente = $stmt->fetch();

        if (!$cliente || !password_verify($senha, $cliente['senha_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['portal_cliente_id'] = $cliente['id'];
        $_SESSION['portal_cliente_nome'] = $cliente['nome'];
        $_SESSION['portal_cliente_email'] = $cliente['contato_email'];
        $_SESSION['portal_cliente_foto'] = $cliente['foto_perfil'] ?? null;

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['portal_cliente_id'], $_SESSION['portal_cliente_nome'], $_SESSION['portal_cliente_email']);
    }

    public static function check(): bool
    {
        return isset($_SESSION['portal_cliente_id']);
    }

    public static function clienteId(): ?int
    {
        return $_SESSION['portal_cliente_id'] ?? null;
    }

    public static function clienteNome(): string
    {
        return $_SESSION['portal_cliente_nome'] ?? '';
    }

    /**
     * Caminho da foto/avatar do cliente logado no portal (ou null = cai
     * pro círculo com a inicial do nome). Atualizado sempre que o cliente
     * troca a foto em portal/perfil.php. Sessões abertas antes desse campo
     * existir buscam uma vez no banco e guardam na sessão (sem precisar
     * deslogar/logar de novo depois do deploy).
     */
    public static function clienteFoto(): ?string
    {
        if (!array_key_exists('portal_cliente_foto', $_SESSION) && self::check()) {
            $stmt = Database::connection()->prepare('SELECT foto_perfil FROM clientes WHERE id = ?');
            $stmt->execute([self::clienteId()]);
            $_SESSION['portal_cliente_foto'] = $stmt->fetchColumn() ?: null;
        }

        return $_SESSION['portal_cliente_foto'] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . APP_BASE_PATH . '/portal/login.php');
            exit;
        }
    }
}
