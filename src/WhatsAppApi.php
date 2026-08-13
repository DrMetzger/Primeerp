<?php

namespace AdminPrime;

/**
 * Fala direto com a WhatsApp Business Platform (Cloud API) da Meta -- sem
 * intermediador/BSP, pedido explícito do Kleber pra não pagar mensalidade
 * em dólar. Enquanto WHATSAPP_ACCESS_TOKEN não estiver configurado (a
 * conta ainda está sendo criada na Meta), só registra em log -- mesmo
 * esquema do Mailer/Notificacoes::whatsapp() em ambiente sem credenciais.
 */
class WhatsAppApi
{
    private const VERSAO_API = 'v21.0';

    /**
     * Token/phone_number_id/app_secret preferem o valor salvo pela tela
     * configuracoes.php (tabela `configuracoes`) -- só cai pra constante de
     * config/config.php se nada tiver sido salvo ainda pela tela. Isso é o
     * que permite trocar o token sem precisar tocar em código/fazer deploy.
     */
    private static function token(): string
    {
        return Configuracoes::get(Database::connection(), 'whatsapp_access_token', defined('WHATSAPP_ACCESS_TOKEN') ? WHATSAPP_ACCESS_TOKEN : '') ?? '';
    }

    private static function phoneNumberId(): string
    {
        return Configuracoes::get(Database::connection(), 'whatsapp_phone_number_id', defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '') ?? '';
    }

    private static function appSecret(): string
    {
        return Configuracoes::get(Database::connection(), 'whatsapp_app_secret', defined('WHATSAPP_APP_SECRET') ? WHATSAPP_APP_SECRET : '') ?? '';
    }

    public static function configurada(): bool
    {
        return self::token() !== '' && self::phoneNumberId() !== '';
    }

    public static function enviarTexto(string $telefoneDestino, string $texto): bool
    {
        if (!self::configurada()) {
            error_log('WhatsApp (não configurado ainda) -- texto pra ' . $telefoneDestino . ': ' . $texto);
            return false;
        }

        return self::chamarGraphApi(self::phoneNumberId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $telefoneDestino,
            'type' => 'text',
            'text' => ['body' => $texto],
        ]) !== null;
    }

    /**
     * Mensagem com botões de resposta rápida (a pessoa toca em vez de
     * digitar) -- limite da própria Meta: no máximo 3 botões, título de até
     * 20 caracteres cada. Cada botão tem um $id que volta no webhook quando
     * clicado (ver webhook_whatsapp.php), e WhatsAppBot trata esse id
     * exatamente como se fosse o texto digitado (mesmo "sim"/"não"/número).
     * $botoes = [['id' => 'sim', 'titulo' => '✅ Sim, abrir'], ...]
     */
    public static function enviarBotoes(string $telefoneDestino, string $corpo, array $botoes): bool
    {
        if (!self::configurada()) {
            error_log('WhatsApp (não configurado ainda) -- botões pra ' . $telefoneDestino . ': ' . $corpo);
            return false;
        }

        return self::chamarGraphApi(self::phoneNumberId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $telefoneDestino,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => self::limitarCorpoInterativo($corpo)],
                'action' => [
                    'buttons' => array_map(fn ($b) => [
                        'type' => 'reply',
                        'reply' => ['id' => $b['id'], 'title' => mb_substr($b['titulo'], 0, 20)],
                    ], $botoes),
                ],
            ],
        ]) !== null;
    }

    /**
     * Mensagem com lista de opções (menu que abre ao tocar num botão) --
     * usada quando são mais de 3 opções (limite dos botões acima), até no
     * máximo 10 linhas no total (limite da Meta). $linhas = [['id' => '1',
     * 'titulo' => '🟢 Baixa'], ...], mesma ideia de id do enviarBotoes().
     */
    public static function enviarLista(string $telefoneDestino, string $corpo, string $tituloBotao, array $linhas): bool
    {
        if (!self::configurada()) {
            error_log('WhatsApp (não configurado ainda) -- lista pra ' . $telefoneDestino . ': ' . $corpo);
            return false;
        }

        return self::chamarGraphApi(self::phoneNumberId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $telefoneDestino,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => self::limitarCorpoInterativo($corpo)],
                'action' => [
                    'button' => mb_substr($tituloBotao, 0, 20),
                    'sections' => [[
                        'rows' => array_map(fn ($l) => array_filter([
                            'id' => $l['id'],
                            'title' => mb_substr($l['titulo'], 0, 24),
                            'description' => isset($l['descricao']) ? mb_substr($l['descricao'], 0, 72) : null,
                        ], fn ($v) => $v !== null), $linhas),
                    ]],
                ],
            ],
        ]) !== null;
    }

    /**
     * Baixa uma mídia recebida (print/arquivo) -- a Meta manda só o ID no
     * webhook, é preciso pedir a URL de download separado (ela expira
     * rápido) e então baixar os bytes de fato.
     */
    public static function baixarMidia(string $mediaId): ?array
    {
        if (!self::configurada()) {
            error_log('WhatsApp (não configurado ainda) -- não deu pra baixar mídia ' . $mediaId);
            return null;
        }

        $info = self::chamarGraphApi($mediaId, null, 'GET');
        if (!$info || empty($info['url'])) {
            return null;
        }

        $ch = curl_init($info['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::token()],
            CURLOPT_TIMEOUT => 20,
        ]);
        $bytes = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($bytes === false || $codigoHttp !== 200) {
            error_log('WhatsApp: falha ao baixar bytes da mídia ' . $mediaId);
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime' => $info['mime_type'] ?? 'application/octet-stream',
        ];
    }

    /**
     * Confere a assinatura HMAC que a Meta manda em todo webhook (cabeçalho
     * X-Hub-Signature-256), pra garantir que a requisição é mesmo da Meta e
     * não de qualquer um que descobriu a URL. Só valida se um app secret
     * estiver configurado -- sem ele, aceita sem checar (nunca bloqueia por
     * falta de configuração, só perde essa camada extra de segurança).
     */
    public static function assinaturaValida(string $corpoBruto, ?string $assinaturaRecebida): bool
    {
        $appSecret = self::appSecret();
        if ($appSecret === '') {
            return true;
        }
        if (!$assinaturaRecebida) {
            return false;
        }

        $esperada = 'sha256=' . hash_hmac('sha256', $corpoBruto, $appSecret);
        return hash_equals($esperada, $assinaturaRecebida);
    }

    /**
     * O corpo de mensagem INTERATIVA (botões/lista) tem limite de 1024
     * caracteres na Cloud API da Meta -- passar disso faz a Graph API
     * rejeitar a chamada (HTTP 400) e a mensagem NUNCA chega no cliente,
     * sem nenhum aviso visível pra ele (nem erro, nem nada -- só "para de
     * responder"). Bug real: cliente colava uma descrição de chamado bem
     * longa (ex.: um texto de especificação técnica) e, ao tocar em "Sem
     * anexo", o resumo de confirmação (que reproduz a descrição inteira)
     * passava do limite -- a Graph API recusava silenciosamente e o
     * cliente ficava sem conseguir prosseguir nem abrir outro chamado.
     * Corta com reticências em vez de deixar a Graph API rejeitar (mensagem
     * de texto normal, sem botão, não tem esse limite -- só as interativas).
     */
    private static function limitarCorpoInterativo(string $corpo): string
    {
        return mb_strlen($corpo) > 1024 ? mb_substr($corpo, 0, 1021) . '...' : $corpo;
    }

    /**
     * $caminho já vem completo (relativo à raiz da Graph API), ex.:
     * "{phone_number_id}/messages" pra enviar, ou só o media-id pra
     * consultar a URL de uma mídia recebida.
     */
    private static function chamarGraphApi(string $caminho, ?array $corpo, string $metodo = 'POST'): ?array
    {
        $url = 'https://graph.facebook.com/' . self::VERSAO_API . '/' . $caminho;

        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . self::token(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ];
        if ($metodo === 'POST') {
            $opcoes[CURLOPT_POST] = true;
            $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo);
        }
        curl_setopt_array($ch, $opcoes);

        $resposta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $codigoHttp >= 300) {
            error_log('WhatsApp: falha na chamada à Graph API (' . $url . '): HTTP ' . $codigoHttp . ' ' . $erroCurl . ' -- ' . $resposta);
            // 401/403 é quase sempre token expirado/inválido -- diferente de
            // outros erros (número invalido, etc.), esse tipo derruba TODO
            // envio de WhatsApp de uma vez só, e antes ficava só no error_log
            // (ninguém via até um cliente reclamar que só chegou aviso por
            // e-mail). Manda um alerta pra equipe por e-mail (que continua
            // funcionando, é outro sistema) pra pegar rápido da próxima vez.
            if ($codigoHttp === 401 || $codigoHttp === 403) {
                self::alertarFalhaAutenticacao('HTTP ' . $codigoHttp . ' -- ' . $resposta);
            }
            return null;
        }

        return json_decode($resposta, true);
    }

    private static function alertarFalhaAutenticacao(string $detalheErro): void
    {
        // No máximo 1 e-mail de alerta por hora enquanto o problema
        // persistir -- numa hora de pico de chamados, cada mensagem que
        // falhasse geraria um e-mail novo (viraria spam rápido).
        $arquivoMarca = sys_get_temp_dir() . '/adminprime_whatsapp_alerta_token.marker';
        if (is_file($arquivoMarca) && (time() - filemtime($arquivoMarca)) < 3600) {
            return;
        }
        @touch($arquivoMarca);

        $corpo = '<p>A API do WhatsApp está rejeitando as mensagens (HTTP 401/403 -- token de acesso da Meta provavelmente expirado ou inválido).</p>'
            . '<p>Avisos de chamado/treinamento estão saindo só por e-mail até isso ser corrigido -- gere um token novo (de preferência permanente, via System User) na Meta Business Manager e atualize em Configurações &gt; WhatsApp.</p>'
            . '<p>Detalhe técnico: ' . e($detalheErro) . '</p>';

        foreach (Notificacoes::destinatariosEquipe() as $emailEquipe) {
            Mailer::enviar($emailEquipe, '⚠️ WhatsApp da Prime parou de enviar mensagens', $corpo);
        }
    }
}
