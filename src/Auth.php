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

        // Trilha de login (ver usuario_login_log/relatório de atividade do
        // colaborador) -- não pode travar o login se falhar por qualquer
        // motivo, então nunca deixa exceção subir daqui.
        try {
            $pdo->prepare('UPDATE usuarios SET ultimo_login_em = NOW() WHERE id = ?')->execute([$usuario['id']]);
            $pdo->prepare('INSERT INTO usuario_login_log (usuario_id, ip, user_agent) VALUES (?, ?, ?)')
                ->execute([$usuario['id'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
        } catch (\Throwable $e) {
            error_log('Falha ao registrar login: ' . $e->getMessage());
        }

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

    /**
     * Perfil "Colaborador" com acesso restrito a cliente/setor específico
     * (ver usuario_cliente_setores) -- pedido do Kleber pra contratar gente
     * nova sem dar acesso ao sistema inteiro de cara. admin/diretor/ceo
     * NUNCA ficam restritos (sempre veem tudo, senão travaria a própria
     * equipe de gestão). Pra um colaborador comum, só entra em modo
     * restrito se o toggle `acesso_restrito_por_cliente` estiver ligado --
     * desligado (padrão pra quem já existia antes dessa feature) continua
     * vendo tudo, exatamente como sempre foi. SEMPRE busca no banco (mesmo
     * motivo de podeAcessarDadosSensiveis(): revogar tem que valer na hora).
     */
    public static function escopoRestrito(): bool
    {
        if (!self::check() || self::isAdmin()) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT acesso_restrito_por_cliente FROM usuarios WHERE id = ?');
        $stmt->execute([self::userId()]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * true se o usuário logado pode abrir a ficha desse cliente -- sempre
     * true pra quem não está em modo restrito (admin/diretor/ceo, ou
     * colaborador sem o toggle ligado); pra quem está restrito, só se tiver
     * pelo menos 1 vínculo (cliente inteiro ou algum setor específico) com
     * esse cliente em usuario_cliente_setores.
     */
    public static function podeAcessarCliente(int $clienteId): bool
    {
        if (!self::escopoRestrito()) {
            return true;
        }
        $stmt = Database::connection()->prepare('SELECT 1 FROM usuario_cliente_setores WHERE usuario_id = ? AND cliente_id = ? LIMIT 1');
        $stmt->execute([self::userId(), $clienteId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function requireAcessoCliente(int $clienteId): void
    {
        self::requireLogin();
        if (!self::podeAcessarCliente($clienteId)) {
            http_response_code(403);
            die('Acesso restrito -- você não está vinculado a esse cliente.');
        }
    }

    /**
     * IDs de setor desse cliente que o colaborador logado pode ver/agir
     * (chamados, fontes ADVPL, treinamentos) -- null = sem restrição (vê
     * todos os setores, mesmo comportamento de sempre: admin/diretor/ceo,
     * colaborador sem escopo restrito, OU colaborador com vínculo "cliente
     * inteiro" nesse cliente -- setor_id NULL em usuario_cliente_setores).
     * Array (mesmo vazio) = só pode ver os setores explicitamente listados.
     */
    public static function setoresPermitidos(int $clienteId): ?array
    {
        if (!self::escopoRestrito()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT setor_id FROM usuario_cliente_setores WHERE usuario_id = ? AND cliente_id = ?');
        $stmt->execute([self::userId(), $clienteId]);
        $setorIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array(null, $setorIds, true)) {
            return null; // vínculo "cliente inteiro" -- sem restrição de setor
        }
        return array_map('intval', $setorIds);
    }

    /**
     * true se o colaborador logado pode agir num item associado a esse
     * setor específico (ou sem setor nenhum, $setorId = null -- ex.: um
     * chamado geral do cliente, não ligado a nenhum departamento). Usar
     * SEMPRE que uma ação mexe em algo de um setor específico (responder/
     * atribuir/mudar setor de chamado, subir fonte ADVPL, criar
     * treinamento), depois de já confirmar requireAcessoCliente().
     */
    public static function podeAcessarSetor(int $clienteId, ?int $setorId): bool
    {
        $permitidos = self::setoresPermitidos($clienteId);
        if ($permitidos === null) {
            return true;
        }
        if ($setorId === null) {
            // Item geral do cliente (sem setor) -- qualquer colaborador com
            // pelo menos 1 vínculo nesse cliente pode ver/agir, senão um
            // chamado sem departamento ficaria escondido de todo mundo.
            return $permitidos !== [];
        }
        return in_array($setorId, $permitidos, true);
    }

    /**
     * IDs de cliente que o usuário logado pode ver -- null = todos (mesmo
     * comportamento de sempre, ver escopoRestrito()); array = só esses.
     * Usado pra filtrar a listagem em clientes.php.
     */
    public static function clienteIdsPermitidos(): ?array
    {
        if (!self::escopoRestrito()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT DISTINCT cliente_id FROM usuario_cliente_setores WHERE usuario_id = ?');
        $stmt->execute([self::userId()]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
