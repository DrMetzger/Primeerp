<?php

namespace AdminPrime;

/**
 * Envio simples de e-mail via mail() do PHP (padrão em hospedagens
 * compartilhadas como a Hostinger, sem precisar de SMTP externo).
 *
 * Se no futuro a entrega não for confiável, trocar a implementação de
 * enviar() por SMTP (ex: PHPMailer) sem precisar mudar quem chama.
 */
class Mailer
{
    public static function enviar(string $para, string $assunto, string $corpoHtml): bool
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . MAIL_FROM,
        ];

        try {
            $ok = mail($para, $assunto, $corpoHtml, implode("\r\n", $headers));
            if (!$ok) {
                error_log("Mailer: falha ao enviar e-mail para $para (assunto: $assunto)");
            }
            return $ok;
        } catch (\Throwable $e) {
            error_log('Mailer: exceção ao enviar e-mail: ' . $e->getMessage());
            return false;
        }
    }
}
