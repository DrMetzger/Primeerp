<?php

namespace AdminPrime;

/**
 * Sugestões de melhoria por setor (aba Setores) geradas com IA -- fala
 * direto com a API do Google Gemini (plano gratuito, pedido do Kleber pra
 * não gerar custo nenhum), mesmo espírito do WhatsAppApi: enquanto
 * GEMINI_API_KEY não estiver configurada, só avisa em vez de quebrar a tela.
 */
class AiSugestoes
{
    private const MODELO = 'gemini-2.5-flash';

    public static function configurada(): bool
    {
        return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
    }

    /**
     * $prompt já vem pronto (contexto do setor montado por quem chamou --
     * cliente.php sabe os dados, essa classe só sabe falar com a API).
     * Retorna null se não tiver chave configurada ou se a chamada falhar
     * (fica logado, não trava a tela).
     */
    public static function gerar(string $prompt): ?string
    {
        if (!self::configurada()) {
            error_log('IA (não configurada ainda) -- geraria sugestão a partir do prompt: ' . $prompt);
            return null;
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODELO . ':generateContent?key=' . GEMINI_API_KEY;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
            ]),
            CURLOPT_TIMEOUT => 40,
        ]);

        $resposta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $codigoHttp >= 300) {
            error_log('IA: falha na chamada à API do Gemini: HTTP ' . $codigoHttp . ' ' . $erroCurl . ' -- ' . $resposta);
            return null;
        }

        $dados = json_decode($resposta, true);
        return $dados['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }
}
