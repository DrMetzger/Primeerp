<?php

namespace AdminPrime;

use PDO;

/**
 * Autenticação do PORTAL DO CLIENTE — separada do login da equipe (Auth).
 * Usa chaves de sessão diferentes para não conflitar com o login interno.
 *
 * Dois tipos de login, mesma sessão/telas:
 * - MASTER: o contato principal do cliente (clientes.contato_email/senha_hash,
 *   sem nenhuma mudança em relação a como já era) -- vê e exporta os chamados
 *   de TODOS os setores da empresa.
 * - SETOR: login próprio de um responsável de setor (setor_responsaveis),
 *   só vê e abre chamado do próprio setor. Cliente que não cadastra nenhum
 *   setor continua 100% no modelo antigo, só com o master.
 */
class ClienteAuth
{
    public static function attempt(PDO $pdo, string $email, string $senha): bool
    {
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE contato_email = ? AND portal_ativo = 1 AND senha_hash IS NOT NULL LIMIT 1");
        $stmt->execute([$email]);
        $cliente = $stmt->fetch();

        if ($cliente && password_verify($senha, $cliente['senha_hash'])) {
            // Fora do horário comercial, só entra quem tem a liberação
            // especial (clientes.acesso_fora_horario) -- credenciais certas
            // mas sem liberação caem na mesma mensagem genérica de "e-mail
            // ou senha inválidos" em login.php, sem detalhar o motivo.
            if (!self::dentroHorarioComercial() && !$cliente['acesso_fora_horario']) {
                return false;
            }
            self::iniciarSessaoMaster($cliente);
            return true;
        }

        $stmt = $pdo->prepare("
            SELECT sr.*, s.cliente_id, s.nome AS setor_nome, c.nome AS empresa_nome, c.acesso_fora_horario
            FROM setor_responsaveis sr
            JOIN setores s ON s.id = sr.setor_id
            JOIN clientes c ON c.id = s.cliente_id
            WHERE sr.email = ? AND sr.portal_ativo = 1 AND sr.senha_hash IS NOT NULL AND s.ativo = 1
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $setorResp = $stmt->fetch();

        if ($setorResp && password_verify($senha, $setorResp['senha_hash'])) {
            if (!self::dentroHorarioComercial() && !$setorResp['acesso_fora_horario']) {
                return false;
            }
            self::iniciarSessaoSetor($setorResp);
            return true;
        }

        return false;
    }

    private static function iniciarSessaoMaster(array $cliente): void
    {
        session_regenerate_id(true);
        $_SESSION['portal_cliente_id'] = $cliente['id'];
        $_SESSION['portal_tipo'] = 'master';
        $_SESSION['portal_setor_id'] = null;
        $_SESSION['portal_setor_responsavel_id'] = null;
        // "Nome" aqui é da PESSOA (contato/key user), não da empresa -- o
        // Kleber apontou que ser chamado pelo nome da empresa ("Olá, GEOMAQ")
        // é impessoal. Cai pro nome da empresa só se não tiver contato
        // cadastrado ainda. Nome da empresa fica guardado separado
        // (clienteEmpresa()) pra quem realmente precisar dele.
        $_SESSION['portal_cliente_nome'] = $cliente['contato_nome'] ?: $cliente['nome'];
        $_SESSION['portal_cliente_empresa'] = $cliente['nome'];
        $_SESSION['portal_cliente_email'] = $cliente['contato_email'];
    }

    private static function iniciarSessaoSetor(array $setorResp): void
    {
        session_regenerate_id(true);
        $_SESSION['portal_cliente_id'] = $setorResp['cliente_id'];
        $_SESSION['portal_tipo'] = 'setor';
        $_SESSION['portal_setor_id'] = $setorResp['setor_id'];
        $_SESSION['portal_setor_responsavel_id'] = $setorResp['id'];
        $_SESSION['portal_cliente_nome'] = $setorResp['nome'];
        $_SESSION['portal_cliente_empresa'] = $setorResp['empresa_nome'];
        $_SESSION['portal_cliente_email'] = $setorResp['email'];
    }

    public static function logout(): void
    {
        unset(
            $_SESSION['portal_cliente_id'],
            $_SESSION['portal_tipo'],
            $_SESSION['portal_setor_id'],
            $_SESSION['portal_setor_responsavel_id'],
            $_SESSION['portal_cliente_nome'],
            $_SESSION['portal_cliente_empresa'],
            $_SESSION['portal_cliente_email']
        );
    }

    public static function check(): bool
    {
        return isset($_SESSION['portal_cliente_id']);
    }

    /** Id do CLIENTE (empresa) -- igual pra master e pra qualquer setor dele. */
    public static function clienteId(): ?int
    {
        return $_SESSION['portal_cliente_id'] ?? null;
    }

    /**
     * true = login master (contato principal, vê todos os setores).
     * false = login de um responsável de setor específico.
     * Sessão sem a chave (não deveria acontecer, mas por segurança) cai
     * pro comportamento antigo (master), já que só existia esse tipo antes.
     */
    public static function isMaster(): bool
    {
        return ($_SESSION['portal_tipo'] ?? 'master') === 'master';
    }

    /** Id do setor logado, ou null se for o master. */
    public static function setorId(): ?int
    {
        return $_SESSION['portal_setor_id'] ?? null;
    }

    /** Id da linha em setor_responsaveis do login atual, ou null se for o master. */
    public static function setorResponsavelId(): ?int
    {
        return $_SESSION['portal_setor_responsavel_id'] ?? null;
    }

    /**
     * Setor PADRÃO do master (clientes.setor_id, editado na aba Usuários do
     * cliente) -- null se for login de setor, ou se o master ainda não tiver
     * nenhum setor configurado. Não é uma restrição: o master continua
     * vendo e podendo abrir chamado em QUALQUER setor da empresa, isso aqui
     * só decide pra qual setor um chamado ABERTO POR ELE vai por padrão,
     * quando ele não escolher outro explicitamente. SEMPRE busca no banco
     * (mesmo motivo de clienteFoto()/podeAcessarForaDoHorario()).
     */
    public static function setorMasterId(): ?int
    {
        if (!self::isMaster()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT setor_id FROM clientes WHERE id = ?');
        $stmt->execute([self::clienteId()]);
        $setorId = $stmt->fetchColumn();
        return $setorId !== null ? (int) $setorId : null;
    }

    /** Nome do setor logado (busca fresca -- pode ter sido renomeado), ou null se for o master. */
    public static function setorNome(): ?string
    {
        $setorId = self::setorId();
        if ($setorId === null) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT nome FROM setores WHERE id = ?');
        $stmt->execute([$setorId]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Nome da PESSOA (contato/key user, ou responsável de setor) logada no
     * portal -- não da empresa. Sessões abertas antes dessa mudança existir
     * (só guardavam nome da empresa) se auto-curam buscando o contato certo
     * no banco uma vez -- só se aplica a sessão de master antiga, já que
     * login de setor é recém-criado (nunca teve sessão desse jeito).
     */
    public static function clienteNome(): string
    {
        if (!array_key_exists('portal_cliente_empresa', $_SESSION) && self::check() && self::isMaster()) {
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
     * Caminho da foto/avatar de quem está logado (ou null = cai pro círculo
     * com a inicial do nome) -- da linha certa (clientes ou
     * setor_responsaveis) dependendo do tipo de login. SEMPRE busca no
     * banco (não guarda em sessão): mesmo motivo do Auth::userFoto() --
     * cachear na sessão deixava a tela "descompassada" quando a foto mudava
     * por outro motivo que não fosse a própria pessoa trocando em
     * portal/perfil.php.
     */
    public static function clienteFoto(): ?string
    {
        if (!self::check()) {
            return null;
        }
        $pdo = Database::connection();
        if (self::isMaster()) {
            $stmt = $pdo->prepare('SELECT foto_perfil, contato_email FROM clientes WHERE id = ?');
            $stmt->execute([self::clienteId()]);
        } else {
            $stmt = $pdo->prepare('SELECT foto_perfil, email FROM setor_responsaveis WHERE id = ?');
            $stmt->execute([self::setorResponsavelId()]);
        }
        $linha = $stmt->fetch();
        if (!$linha) {
            return null;
        }
        // Padroniza pelo e-mail (ver helpers.php) -- assim o mesmo e-mail
        // cadastrado como master E como responsável de setor mostra sempre o
        // mesmo avatar, em vez de um ter foto real e o outro cair na inicial
        // genérica só porque nunca subiu foto NESSE registro específico.
        return resolver_avatar_por_email($pdo, $linha['contato_email'] ?? $linha['email'] ?? null, $linha['foto_perfil']);
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
     * Telas de gestão (Setores/Usuários do próprio cliente, ver
     * portal/equipe.php) são só do MASTER -- um responsável de setor
     * logado nunca deveria conseguir cadastrar setor ou outro usuário.
     * Chamar depois de requireLogin(), no topo da página.
     */
    public static function exigirMaster(): void
    {
        if (!self::isMaster()) {
            header('Location: ' . APP_BASE_PATH . '/portal/chamados.php');
            exit;
        }
    }

    /**
     * Além de ser master, o CLIENTE precisa ter sido liberado pela Prime pra
     * gerenciar setor/usuário sozinho (clientes.portal_permite_gerenciar_equipe,
     * ver cliente.php/clientes.php) -- desligado por padrão, tem cliente que
     * não tem preparo pra isso sem confundir tudo. SEMPRE busca no banco
     * (mesmo motivo de podeAcessarForaDoHorario()): uma liberação dada/tirada
     * pela Prime tem que valer na hora, não só no próximo login.
     */
    public static function podeGerenciarEquipe(): bool
    {
        if (!self::check() || !self::isMaster()) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT portal_permite_gerenciar_equipe FROM clientes WHERE id = ?');
        $stmt->execute([self::clienteId()]);
        return (bool) $stmt->fetchColumn();
    }

    public static function exigirGerenciarEquipe(): void
    {
        self::exigirMaster();
        if (!self::podeGerenciarEquipe()) {
            header('Location: ' . APP_BASE_PATH . '/portal/chamados.php');
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
     * comercial normal -- pedido pontual do Kleber pra alguns clientes. A
     * liberação é do CLIENTE (clientes.acesso_fora_horario), vale pro master
     * e pra todos os setores dele igual -- não tem liberação por setor
     * separada. SEMPRE busca no banco (mesmo motivo de clienteFoto()): não
     * cachear em sessão, senão uma liberação dada/tirada só valeria a partir
     * do próximo login.
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
