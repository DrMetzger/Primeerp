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

        // Fora do horário comercial, só entra quem tem a liberação especial
        // (clientes.acesso_fora_horario) -- credenciais certas mas sem
        // liberação caem na mesma mensagem genérica de "e-mail ou senha
        // inválidos" em login.php, sem entrar em detalhe do motivo (não é
        // informação sensível o suficiente pra abrir uma exceção de UX aqui).
        if (!self::dentroHorarioComercial() && !$cliente['acesso_fora_horario']) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['portal_cliente_id'] = $cliente['id'];
        // "Nome" aqui é da PESSOA (contato/key user), não da empresa -- o
        // Kleber apontou que ser chamado pelo nome da empresa ("Olá, GEOMAQ")
        // é impessoal. Cai pro nome da empresa só se não tiver contato
        // cadastrado ainda. Nome da empresa fica guardado separado
        // (clienteEmpresa()) pra quem realmente precisar dele.
        $_SESSION['portal_cliente_nome'] = $cliente['contato_nome'] ?: $cliente['nome'];
        $_SESSION['portal_cliente_empresa'] = $cliente['nome'];
        $_SESSION['portal_cliente_email'] = $cliente['contato_email'];
        $_SESSION['portal_cliente_foto'] = $cliente['foto_perfil'] ?? null;

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['portal_cliente_id'], $_SESSION['portal_cliente_nome'], $_SESSION['portal_cliente_empresa'], $_SESSION['portal_cliente_email']);
    }

    public static function check(): bool
    {
        return isset($_SESSION['portal_cliente_id']);
    }

    public static function clienteId(): ?int
    {
        return $_SESSION['portal_cliente_id'] ?? null;
    }

    /**
     * Nome da PESSOA (contato/key user) logada no portal -- não da empresa.
     * Sessões abertas antes dessa mudança existir (só guardavam nome da
     * empresa) se auto-curam buscando o contato certo no banco uma vez.
     */
    public static function clienteNome(): string
    {
        if (!array_key_exists('portal_cliente_empresa', $_SESSION) && self::check()) {
            $stmt = Database::connection()->prepare('SELECT nome, contato_nome FROM clientes WHERE id = ?');
            $stmt->execute([self::clienteId()]);
            $dados = $stmt->fetch();
            $_SESSION['portal_cliente_empresa'] = $dados['nome'] ?? '';
            $_SESSION['portal_cliente_nome'] = ($dados['contato_nome'] ?? '') ?: ($dados['nome'] ?? '');
        }

        return $_SESSION['portal_cliente_nome'] ?? '';
    }

    /** Nome da EMPRESA cliente (diferente da pessoa/contato, ver clienteNome()). */
    public static function clienteEmpresa(): string
    {
        self::clienteNome(); // garante a auto-cura acima, se precisar
        return $_SESSION['portal_cliente_empresa'] ?? '';
    }

    /**
     * Caminho da foto/avatar do cliente logado no portal (ou null = cai
     * pro círculo com a inicial do nome). SEMPRE busca no banco (não guarda
     * em sessão) -- mesmo motivo do Auth::userFoto(): cachear na sessão
     * deixava a tela "descompassada" quando a foto mudava por outro motivo
     * que não fosse o próprio cliente trocando em portal/perfil.php (ex.:
     * migração de auto-cura). Consulta simples por chave primária.
     */
    public static function clienteFoto(): ?string
    {
        if (!self::check()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT foto_perfil FROM clientes WHERE id = ?');
        $stmt->execute([self::clienteId()]);
        return $stmt->fetchColumn() ?: null;
    }

    public static function requireLogin(): void
    {
        self::bloquearForaDoHorario();

        if (!self::check()) {
            header('Location: ' . APP_BASE_PATH . '/portal/login.php');
            exit;
        }
    }

    /**
     * Portal do cliente só funciona em horário comercial (ver config.php) --
     * inclusive pra quem já está logado (não é só bloquear o login). Se
     * abrir uma página fora do horário, mostra um aviso simples e para a
     * execução, em vez de deixar ver/usar o sistema.
     */
    public static function dentroHorarioComercial(): bool
    {
        $hora = (int) date('G');
        return $hora >= PORTAL_CLIENTE_HORARIO_INICIO && $hora < PORTAL_CLIENTE_HORARIO_FIM;
    }

    /**
     * Cliente com liberação especial (marcada em cliente.php/clientes.php,
     * ver acao=atualizar_cliente) pode usar o portal fora do horário
     * comercial normal -- pedido pontual do Kleber pra alguns clientes.
     * SEMPRE busca no banco (mesmo motivo de clienteFoto()/userFoto(): não
     * cachear em sessão, senão uma liberação dada/tirada só valeria a partir
     * do próximo login).
     */
    public static function podeAcessarForaDoHorario(): bool
    {
        if (!self::check()) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT acesso_fora_horario FROM clientes WHERE id = ?');
        $stmt->execute([self::clienteId()]);
        return (bool) $stmt->fetchColumn();
    }

    public static function bloquearForaDoHorario(): void
    {
        if (self::dentroHorarioComercial() || self::podeAcessarForaDoHorario()) {
            return;
        }

        http_response_code(503);
        $inicio = sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_INICIO);
        $fim = sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_FIM);
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<title>Portal fora do horário · Prime Hub</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<style>body{font-family:Arial,sans-serif;background:#f4f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}'
            . '.caixa{background:#fff;border-radius:12px;padding:2.5rem;max-width:420px;text-align:center;box-shadow:0 1px 8px rgba(0,0,0,.08);}'
            . 'h1{font-size:1.1rem;color:#27272a;}p{color:#71717a;font-size:.92rem;}</style></head><body>'
            . '<div class="caixa"><h1>⏰ Portal disponível em horário comercial</h1>'
            . '<p>O acesso ao portal do cliente funciona das <strong>' . $inicio . '</strong> às <strong>' . $fim . '</strong>.</p>'
            . '<p>Volte dentro desse horário para abrir ou acompanhar chamados.</p></div>'
            . '</body></html>';
        exit;
    }
}
