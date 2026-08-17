<?php

namespace AdminPrime;

use PDO;

class Auth
{
    public static function attempt(PDO $pdo, string $email, string $senha): bool
    {
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_perfil'] = $usuario['perfil'];
        $_SESSION['usuario_foto'] = $usuario['foto_perfil'] ?? null;

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['usuario_id']);
    }

    public static function isAdmin(): bool
    {
        // Diretor e CEO têm o mesmo acesso de administrador por enquanto --
        // o módulo financeiro que vai diferenciar o CEO (contratos, faturas,
        // CNAB) ainda está em stand-by, então não faz sentido criar uma
        // conta de CEO/Diretor que fica travada sem acesso a nada.
        return in_array($_SESSION['usuario_perfil'] ?? null, ['admin', 'diretor', 'ceo'], true);
    }

    public static function userId(): ?int
    {
        return $_SESSION['usuario_id'] ?? null;
    }

    public static function userName(): string
    {
        return $_SESSION['usuario_nome'] ?? '';
    }

    /**
     * Caminho da foto/avatar do usuário logado (ou null = cai pro círculo
     * com a inicial do nome em avatar_html()). SEMPRE busca no banco (não
     * guarda em sessão) -- já tentamos cachear na sessão antes, mas isso
     * criava uma tela "descompassada": se a foto mudar por qualquer motivo
     * que não seja a própria pessoa trocando em perfil.php (ex.: uma
     * migração de auto-cura reatribuindo avatar padrão, ver
     * Migrations::atribuirAvatarPadrao()), a sessão já aberta ficava com o
     * valor antigo pra sempre, mostrando uma foto no cabeçalho e outra
     * (a certa) em "Meu perfil". É uma consulta simples por chave primária,
     * custo desprezível pro tamanho desse sistema.
     */
    public static function userFoto(): ?string
    {
        if (!self::check()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT foto_perfil FROM usuarios WHERE id = ?');
        $stmt->execute([self::userId()]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Redireciona para o login se não estiver autenticado. Chamar no topo de
     * toda página protegida.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . APP_BASE_PATH . '/login.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            die('Acesso restrito a administradores.');
        }
    }

    /**
     * Acesso ao cofre de credenciais (aba "Acessos") e às Fontes ADVPL de
     * cliente.php -- dado extremamente sensível (senha de servidor/RDP/
     * AnyDesk/VPN, código-fonte proprietário do cliente). Restrito por
     * padrão a admin/diretor (CEO de propósito NÃO entra aqui, mesmo tendo
     * isAdmin()=true pro resto do sistema -- é um nível de sigilo à parte,
     * liberar geral demais contraria o pedido explícito do Kleber). Um
     * colaborador específico pode ganhar acesso especial via
     * usuarios.acesso_dados_sensiveis (ver usuarios.php), pra quem realmente
     * precisa sem promover o perfil dele. SEMPRE busca no banco (não
     * cachear em sessão) -- uma liberação dada/revogada tem que valer na
     * hora, mesmo pra quem já está com sessão aberta.
     */
    public static function podeAcessarDadosSensiveis(): bool
    {
        if (!self::check()) {
            return false;
        }
        if (in_array($_SESSION['usuario_perfil'] ?? null, ['admin', 'diretor'], true)) {
            return true;
        }
        $stmt = Database::connection()->prepare('SELECT acesso_dados_sensiveis FROM usuarios WHERE id = ?');
        $stmt->execute([self::userId()]);
        return (bool) $stmt->fetchColumn();
    }

    public static function requireAcessoDadosSensiveis(): void
    {
        self::requireLogin();
        if (!self::podeAcessarDadosSensiveis()) {
            http_response_code(403);
            die('Acesso restrito -- essa área guarda dados sensíveis do cliente (credenciais, código-fonte).');
        }
    }

    /**
     * Registra toda vez que alguém autorizado revela uma senha ou baixa um
     * arquivo do cofre de acessos/Fontes ADVPL -- boas práticas de mercado
     * pra dado desse nível de sigilo (trilha de auditoria, mesmo que seja
     * um acesso legítimo). Nunca bloqueia o acesso se o log falhar (só
     * loga o erro) -- auditoria não pode ser motivo de travar quem já tem
     * permissão de verdade.
     */
    public static function registrarAcessoSensivel(string $tipo, string $acao, int $referenciaId, ?int $clienteId = null): void
    {
        try {
            Database::connection()->prepare('INSERT INTO acesso_dados_sensiveis_log (usuario_id, tipo, acao, referencia_id, cliente_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([self::userId(), $tipo, $acao, $referenciaId, $clienteId]);
        } catch (\Throwable $e) {
            error_log('Falha ao registrar log de acesso sensível: ' . $e->getMessage());
        }
    }

    /**
     * Igual requireAdmin(), mas também aceita um token temporário válido
     * (?diag_token=...) gerado por um admin logado, para permitir leitura
     * por uma ferramenta externa sem sessão de navegador.
     */
    public static function requireAdminOrTempToken(PDO $pdo): void
    {
        $token = $_GET['diag_token'] ?? '';
        if ($token !== '' && TempAccess::valido($pdo, $token)) {
            return;
        }
        self::requireAdmin();
    }
}
