<?php

namespace AdminPrime;

/**
 * Centraliza os avisos disparados pelo módulo de chamados: e-mail (ativo) e
 * WhatsApp (preparado, mas inativo até termos as chaves da API da Meta).
 */
class Notificacoes
{
    public static function novoChamado(array $chamado, array $cliente): void
    {
        $link = self::baseUrlAbsoluta() . url('cliente.php?id=' . $cliente['id'] . '#chamados');

        $corpo = "
            <p>Novo chamado aberto por <strong>" . e($cliente['nome']) . "</strong>.</p>
            <p><strong>Título:</strong> " . e($chamado['titulo']) . "</p>
            <p><strong>Prioridade:</strong> " . e($chamado['prioridade']) . "</p>
            <p><strong>Descrição:</strong><br>" . nl2br(e($chamado['descricao'])) . "</p>
            <p><a href=\"$link\">Ver no Prime Hub</a></p>
        ";

        foreach (NOTIFICACAO_EMAILS_EQUIPE as $emailEquipe) {
            Mailer::enviar($emailEquipe, "Novo chamado: {$chamado['titulo']} ({$cliente['nome']})", $corpo);
        }

        self::whatsapp(null, "Novo chamado de {$cliente['nome']}: {$chamado['titulo']}");
    }

    /**
     * Chamado aberto pelo formulário público (abrir_chamado.php, sem login).
     * $clienteId é null quando não foi possível casar automaticamente com
     * nenhum cliente cadastrado -- nesse caso o chamado precisa de triagem
     * manual em chamados_sem_cliente.php.
     */
    public static function novoChamadoPublico(array $chamado, string $nomeExibicao, ?int $clienteId): void
    {
        $link = self::baseUrlAbsoluta() . url($clienteId ? ('cliente.php?id=' . $clienteId . '#chamados') : 'chamados_sem_cliente.php');
        $aviso = $clienteId
            ? ''
            : '<p style="color:#b91c1c"><strong>Atenção:</strong> não foi possível identificar automaticamente o cliente pelo nome da empresa informado. Precisa de triagem manual.</p>';

        $corpo = "
            <p>Novo chamado aberto pelo formulário público do site, de <strong>" . e($nomeExibicao) . "</strong>.</p>
            $aviso
            <p><strong>Título:</strong> " . e($chamado['titulo']) . "</p>
            <p><strong>Prioridade:</strong> " . e($chamado['prioridade']) . "</p>
            <p><strong>Descrição:</strong><br>" . nl2br(e($chamado['descricao'])) . "</p>
            <p><a href=\"$link\">Ver no Prime Hub</a></p>
        ";

        foreach (NOTIFICACAO_EMAILS_EQUIPE as $emailEquipe) {
            Mailer::enviar($emailEquipe, "Novo chamado (formulário público): {$chamado['titulo']}", $corpo);
        }

        self::whatsapp(null, "Novo chamado público de {$nomeExibicao}: {$chamado['titulo']}");
    }

    public static function novaRespostaEquipe(array $chamado, array $cliente): void
    {
        if (!$cliente['contato_email']) {
            return;
        }

        $link = self::baseUrlAbsoluta() . url('portal/chamado.php?id=' . $chamado['id']);
        // Pela PESSOA (contato/key user), não pela empresa -- "Olá, GEOMAQ"
        // é impessoal (mesmo ajuste feito na tela de criar senha e no portal).
        $nomeSaudacao = $cliente['contato_nome'] ?: $cliente['nome'];

        $corpo = "
            <p>Olá, " . e($nomeSaudacao) . ".</p>
            <p>Você recebeu uma nova resposta no chamado <strong>" . e($chamado['titulo']) . "</strong>.</p>
            <p><a href=\"$link\">Ver resposta no portal</a></p>
        ";

        Mailer::enviar($cliente['contato_email'], "Nova resposta no seu chamado: {$chamado['titulo']}", $corpo);
    }

    /**
     * Avisa por e-mail o usuário da equipe que acabou de ser atribuído (ou
     * reatribuído) como responsável por um chamado -- pedido do Kleber ao
     * pedir a possibilidade de reatribuir um chamado pra outro colega.
     */
    public static function chamadoAtribuido(array $chamado, array $usuario): void
    {
        if (!$usuario['email']) {
            return;
        }

        $link = self::baseUrlAbsoluta() . url('cliente.php?id=' . ($chamado['cliente_id'] ?? '') . '#chamados');

        $corpo = "
            <p>Olá, " . e($usuario['nome']) . ".</p>
            <p>Você foi atribuído como responsável pelo chamado <strong>" . e($chamado['titulo']) . "</strong>.</p>
            <p><a href=\"$link\">Ver chamado no Prime Hub</a></p>
        ";

        Mailer::enviar($usuario['email'], "Você foi atribuído ao chamado: {$chamado['titulo']}", $corpo);
    }

    /**
     * Mesma ideia de chamadoAtribuido(), mas pro RESPONSÁVEL DE SETOR do
     * cliente (não um usuário da equipe) -- disparado quando um chamado é
     * movido pra (ou aberto direto em) um setor que já tem responsável
     * cadastrado. Link vai pro portal do cliente, não pro Prime Hub interno.
     */
    public static function chamadoAtribuidoSetor(array $chamado, array $setorResponsavel, string $setorNome): void
    {
        if (!$setorResponsavel['email']) {
            return;
        }

        $link = self::baseUrlAbsoluta() . url('portal/chamado.php?id=' . $chamado['id']);

        $corpo = "
            <p>Olá, " . e($setorResponsavel['nome']) . ".</p>
            <p>O chamado <strong>" . e($chamado['titulo']) . "</strong> foi atribuído ao setor <strong>" . e($setorNome) . "</strong>, do qual você é responsável.</p>
            <p><a href=\"$link\">Ver chamado no portal</a></p>
        ";

        Mailer::enviar($setorResponsavel['email'], "Novo chamado atribuído ao setor {$setorNome}: {$chamado['titulo']}", $corpo);
    }

    public static function conviteAcessoPortal(array $cliente, string $token): void
    {
        if (!$cliente['contato_email']) {
            return;
        }

        $link = self::baseUrlAbsoluta() . url('portal/criar_senha.php?token=' . $token);
        $nomeSaudacao = $cliente['contato_nome'] ?: $cliente['nome'];

        $corpo = "
            <p>Olá, " . e($nomeSaudacao) . ".</p>
            <p>Você agora pode acompanhar seus chamados diretamente pelo nosso portal.</p>
            <p><a href=\"$link\">Clique aqui para criar sua senha de acesso</a></p>
            <p>Este link expira em 24 horas.</p>
        ";

        Mailer::enviar($cliente['contato_email'], 'Acesso ao portal de chamados PrimeERP', $corpo);
    }

    /**
     * Mesma ideia de conviteAcessoPortal(), pro responsável de um SETOR
     * específico (não o master) -- ver ClienteAuth pra entender a diferença.
     */
    public static function conviteAcessoSetor(array $setorResponsavel, string $setorNome, string $token): void
    {
        if (!$setorResponsavel['email']) {
            return;
        }

        $link = self::baseUrlAbsoluta() . url('portal/criar_senha.php?token=' . $token);

        $corpo = "
            <p>Olá, " . e($setorResponsavel['nome']) . ".</p>
            <p>Você agora pode acompanhar e abrir chamados do setor <strong>" . e($setorNome) . "</strong> diretamente pelo nosso portal.</p>
            <p><a href=\"$link\">Clique aqui para criar sua senha de acesso</a></p>
            <p>Este link expira em 24 horas.</p>
        ";

        Mailer::enviar($setorResponsavel['email'], 'Acesso ao portal de chamados PrimeERP', $corpo);
    }

    /**
     * Preparado para a API do WhatsApp (Meta Cloud API). Enquanto as chaves
     * não estiverem configuradas, apenas registra em log e não faz nada.
     */
    public static function whatsapp(?string $telefone, string $mensagem): void
    {
        if (!defined('WHATSAPP_API_TOKEN') || !WHATSAPP_API_TOKEN) {
            error_log('WhatsApp (não configurado ainda): ' . $mensagem);
            return;
        }

        // TODO: implementar chamada à API do WhatsApp (Meta Cloud API) quando
        // as credenciais estiverem disponíveis.
    }

    private static function baseUrlAbsoluta(): string
    {
        return (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['SERVER_NAME'] ?? 'www.primeerp.com.br');
    }
}
