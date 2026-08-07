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

    public static function configurada(): bool
    {
        return defined('WHATSAPP_ACCESS_TOKEN') && WHATSAPP_ACCESS_TOKEN !== '' && WHATSAPP_PHONE_NUMBER_ID !== '';
    }

    public static function enviarTexto(string $telefoneDestino, string $texto): bool
    {
        if (!self::configurada()) {
            error_log('WhatsApp (não configurado ainda) -- texto pra ' . $telefoneDestino . ': ' . $texto);
            return false;
        }

        return self::chamarGraphApi(WHATSAPP_PHONE_NUMBER_ID . '/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $telefoneDestino,
            'type' => 'text',
            'text' => ['body' => $texto],
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
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WHATSAPP_ACCESS_TOKEN],
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
        if (!defined('WHATSAPP_APP_SECRET') || WHATSAPP_APP_SECRET === '') {
            return true;
        }
        if (!$assinaturaRecebida) {
            return false;
        }

        $esperada = 'sha256=' . hash_hmac('sha256', $corpoBruto, WHATSAPP_APP_SECRET);
        return hash_equals($esperada, $assinaturaRecebida);
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
                'Authorization: Bearer ' . WHATSAPP_ACCESS_TOKEN,
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
            return null;
        }

        return json_decode($resposta, true);
    }
}
