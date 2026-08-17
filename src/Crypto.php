<?php

namespace AdminPrime;

/**
 * Cifra reversível pra dado sensível que precisa ser RECUPERADO em texto
 * puro depois (senha de servidor/RDP/AnyDesk/VPN no cofre de acessos --
 * ver cliente_credenciais) -- diferente de password_hash() (que é só de
 * mão única, serve pra CONFERIR senha de login, nunca pra mostrar ela de
 * volta). AES-256-GCM: autenticado (detecta se o texto cifrado foi
 * adulterado, não só decifra), com um IV (nonce) aleatório gerado A CADA
 * chamada de cifrar() -- por isso a mesma senha cifrada duas vezes produz
 * dois textos diferentes (nunca reusar um IV com a mesma chave é o que dá
 * a segurança de verdade no GCM).
 *
 * A chave (CREDENCIAIS_CIFRA_CHAVE, config/config.php) nunca é gravada no
 * banco nem sai do servidor -- só quem tem acesso ao código/config.php
 * consegue decifrar, mesmo que o banco inteiro vaze.
 */
class Crypto
{
    private const METODO = 'aes-256-gcm';

    /**
     * Retorna uma string única (base64) contendo IV + tag de autenticação +
     * texto cifrado, pronta pra gravar direto numa coluna TEXT. Retorna
     * null se $texto for null/vazio (nada pra cifrar -- evita gravar um
     * "cifrado de string vazia" sem sentido).
     */
    public static function cifrar(?string $texto): ?string
    {
        if ($texto === null || $texto === '') {
            return null;
        }

        $chave = base64_decode(CREDENCIAIS_CIFRA_CHAVE, true);
        $iv = random_bytes(openssl_cipher_iv_length(self::METODO));
        $tag = '';

        $cifrado = openssl_encrypt($texto, self::METODO, $chave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifrado === false) {
            throw new \RuntimeException('Falha ao cifrar dado sensível.');
        }

        return base64_encode($iv . $tag . $cifrado);
    }

    /**
     * Desfaz cifrar(). Retorna null se o valor for vazio, malformado, ou se
     * a autenticação falhar (chave errada ou dado adulterado) -- nunca
     * lança erro pra fora (quem chama trata null como "não deu pra
     * recuperar", sem derrubar a tela).
     */
    public static function decifrar(?string $valorCifrado): ?string
    {
        if ($valorCifrado === null || $valorCifrado === '') {
            return null;
        }

        $bruto = base64_decode($valorCifrado, true);
        if ($bruto === false) {
            return null;
        }

        $tamanhoIv = openssl_cipher_iv_length(self::METODO);
        $tamanhoTag = 16; // GCM: tag de autenticação sempre 16 bytes.
        if (strlen($bruto) <= $tamanhoIv + $tamanhoTag) {
            return null;
        }

        $iv = substr($bruto, 0, $tamanhoIv);
        $tag = substr($bruto, $tamanhoIv, $tamanhoTag);
        $cifrado = substr($bruto, $tamanhoIv + $tamanhoTag);

        $chave = base64_decode(CREDENCIAIS_CIFRA_CHAVE, true);
        $texto = openssl_decrypt($cifrado, self::METODO, $chave, OPENSSL_RAW_DATA, $iv, $tag);

        return $texto === false ? null : $texto;
    }
}
