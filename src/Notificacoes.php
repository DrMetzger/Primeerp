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
     * Avisa a equipe (NOTIFICACAO_EMAILS_EQUIPE, hoje Kleber e Petronilho)
     * quando um treinamento ou reunião é agendado pra um cliente -- só por
     * e-mail por enquanto (o WhatsApp ainda não está liberado pela Meta,
     * pedido explícito do Kleber pra não tentar mandar por lá até isso sair).
     */
    public static function novoTreinamento(array $treinamento, string $nomeCliente, ?string $nomeSetor): void
    {
        $link = self::baseUrlAbsoluta() . url('cliente.php?id=' . $treinamento['cliente_id'] . '#setores');
        $ehReuniao = $treinamento['tipo'] === 'reuniao';
        $quando = formatar_data($treinamento['data_inicio']) . ($treinamento['hora_inicio'] ? ' às ' . substr($treinamento['hora_inicio'], 0, 5) : '');

        $corpo = "
            <p>Nov" . ($ehReuniao ? 'a reunião' : 'o treinamento') . " agendad" . ($ehReuniao ? 'a' : 'o') . " para <strong>" . e($nomeCliente) . "</strong>" . ($nomeSetor ? ' (setor ' . e($nomeSetor) . ')' : '') . ".</p>
            <p><strong>Título:</strong> " . e($treinamento['titulo']) . "</p>
            <p><strong>Quando:</strong> " . e($quando) . "</p>
            " . ($treinamento['link_reuniao'] ? '<p><strong>Link:</strong> <a href="' . e($treinamento['link_reuniao']) . '">' . e($treinamento['link_reuniao']) . '</a></p>' : '') . "
            <p><a href=\"$link\">Ver no Prime Hub</a></p>
        ";

        $assunto = ($ehReuniao ? 'Nova reunião' : 'Novo treinamento') . ": {$treinamento['titulo']} ({$nomeCliente})";
        foreach (NOTIFICACAO_EMAILS_EQUIPE as $emailEquipe) {
            Mailer::enviar($emailEquipe, $assunto, $corpo);
        }
    }

    /**
     * Lembrete de "falta pouco" -- disparado pelo cron_lembretes_treinamento.php
     * uns 5 minutos antes do horário marcado. Só e-mail por enquanto, mesmo
     * motivo de novoTreinamento() (WhatsApp ainda não liberado pela Meta).
     */
    public static function lembreteTreinamento(array $treinamento, string $nomeCliente, ?string $nomeSetor): void
    {
        $link = self::baseUrlAbsoluta() . url('cliente.php?id=' . $treinamento['cliente_id'] . '#setores');
        $ehReuniao = $treinamento['tipo'] === 'reuniao';
        $hora = $treinamento['hora_inicio'] ? substr($treinamento['hora_inicio'], 0, 5) : '';

        $corpo = "
            <p>⏰ " . ($ehReuniao ? 'Reunião' : 'Treinamento') . " com <strong>" . e($nomeCliente) . "</strong>" . ($nomeSetor ? ' (setor ' . e($nomeSetor) . ')' : '') . " começa em poucos minutos (às <strong>{$hora}</strong>).</p>
            <p><strong>Título:</strong> " . e($treinamento['titulo']) . "</p>
            " . ($treinamento['link_reuniao'] ? '<p><strong>Link:</strong> <a href="' . e($treinamento['link_reuniao']) . '">' . e($treinamento['link_reuniao']) . '</a></p>' : '') . "
            <p><a href=\"$link\">Ver no Prime Hub</a></p>
        ";

        $assunto = '⏰ Em 5 min: ' . ($ehReuniao ? 'reunião' : 'treinamento') . " {$treinamento['titulo']} ({$nomeCliente})";
        foreach (NOTIFICACAO_EMAILS_EQUIPE as $emailEquipe) {
            Mailer::enviar($emailEquipe, $assunto, $corpo);
        }
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
     * $telefone null = manda pro número do Kleber (WHATSAPP_NOTIFICAR_TELEFONE),
     * mesma ideia de NOTIFICACAO_EMAILS_EQUIPE pro e-mail -- "avisa a
     * equipe" por padrão. Ver WhatsAppApi pra saber quando isso vira uma
     * chamada de verdade pra Meta (só depende de WHATSAPP_ACCESS_TOKEN
     * estar configurado).
     */
    public static function whatsapp(?string $telefone, string $mensagem): void
    {
        $destino = $telefone ?: (defined('WHATSAPP_NOTIFICAR_TELEFONE') ? WHATSAPP_NOTIFICAR_TELEFONE : '');
        if (!$destino) {
            error_log('WhatsApp (sem número de destino configurado): ' . $mensagem);
            return;
        }

        WhatsAppApi::enviarTexto($destino, $mensagem);
    }

    private static function baseUrlAbsoluta(): string
    {
        return (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['SERVER_NAME'] ?? 'www.primeerp.com.br');
    }
}
