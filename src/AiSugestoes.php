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
     * Retorna sempre um array ['ok' => bool, 'texto' => ?string, 'erro' =>
     * ?string] -- diferente da versão anterior (que só devolvia null em
     * qualquer falha), pra dar pra mostrar NA TELA o motivo real quando o
     * Google recusar a chamada (conta sem cota liberada, chave errada,
     * modelo indisponível, etc.), sem precisar ir atrás de log de servidor
     * pra descobrir por quê.
     */
    public static function gerar(string $prompt): array
    {
        if (!self::configurada()) {
            return ['ok' => false, 'texto' => null, 'erro' => 'Chave do Gemini não configurada (ADMINPRIME_GEMINI_API_KEY).'];
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

        if ($resposta === false) {
            error_log('IA: falha de rede chamando o Gemini: ' . $erroCurl);
            return ['ok' => false, 'texto' => null, 'erro' => 'Falha de rede ao chamar o Gemini: ' . $erroCurl];
        }

        $dados = json_decode($resposta, true);

        if ($codigoHttp >= 300) {
            $mensagemGoogle = $dados['error']['message'] ?? $resposta;
            error_log('IA: falha na chamada à API do Gemini: HTTP ' . $codigoHttp . ' -- ' . $mensagemGoogle);
            return ['ok' => false, 'texto' => null, 'erro' => "Gemini retornou HTTP {$codigoHttp}: {$mensagemGoogle}"];
        }

        $texto = $dados['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($texto === null) {
            // Resposta 200 mas sem texto -- normalmente bloqueio de safety
            // (finishReason=SAFETY) ou resposta vazia. Mostra o que o
            // Google mandou pra dar pra entender o motivo.
            $motivo = $dados['candidates'][0]['finishReason'] ?? 'desconhecido';
            return ['ok' => false, 'texto' => null, 'erro' => "Gemini respondeu sem texto (motivo: {$motivo})."];
        }

        return ['ok' => true, 'texto' => $texto, 'erro' => null];
    }
}
