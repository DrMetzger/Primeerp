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

    public static function novaRespostaEquipe(array $chamado, array $cliente): void
    {
        if (!$cliente['contato_email']) {
            return;
        }

        $link = self::baseUrlAbsoluta() . url('portal/chamado.php?id=' . $chamado['id']);

        $corpo = "
            <p>Olá, " . e($cliente['nome']) . ".</p>
            <p>Você recebeu uma nova resposta no chamado <strong>" . e($chamado['titulo']) . "</strong>.</p>
            <p><a href=\"$link\">Ver resposta no portal</a></p>
        ";

        Mailer::enviar($cliente['contato_email'], "Nova resposta no seu chamado: {$chamado['titulo']}", $corpo);
    }

    public static function conviteAcessoPortal(array $cliente, string $token): void
    {
        if (!$cliente['contato_email']) {
            return;
        }

        $link = self::baseUrlAbsoluta() . url('portal/criar_senha.php?token=' . $token);

        $corpo = "
            <p>Olá, " . e($cliente['nome']) . ".</p>
            <p>Você agora pode acompanhar seus chamados diretamente pelo nosso portal.</p>
            <p><a href=\"$link\">Clique aqui para criar sua senha de acesso</a></p>
            <p>Este link expira em 24 horas.</p>
        ";

        Mailer::enviar($cliente['contato_email'], 'Acesso ao portal de chamados PrimeERP', $corpo);
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
