<?php

namespace AdminPrime;

/**
 * Sugestões de melhoria por setor (aba Setores) geradas com IA -- fala
 * direto com a API da Anthropic (sem intermediador), mesmo espírito do
 * WhatsAppApi: enquanto ANTHROPIC_API_KEY não estiver configurada, só avisa
 * em vez de quebrar a tela.
 */
class AiSugestoes
{
    private const VERSAO_API = '2023-06-01';
    private const MODELO = 'claude-sonnet-5';

    public static function configurada(): bool
    {
        return defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
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

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: ' . self::VERSAO_API,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => self::MODELO,
                'max_tokens' => 1024,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
            CURLOPT_TIMEOUT => 40,
        ]);

        $resposta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $codigoHttp >= 300) {
            error_log('IA: falha na chamada à API da Anthropic: HTTP ' . $codigoHttp . ' ' . $erroCurl . ' -- ' . $resposta);
            return null;
        }

        $dados = json_decode($resposta, true);
        return $dados['content'][0]['text'] ?? null;
    }
}
