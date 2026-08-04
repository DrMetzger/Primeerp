<?php

namespace AdminPrime;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envio de e-mail. Usa SMTP autenticado (via PHPMailer) quando SMTP_HOST
 * estiver configurado -- caso contrário cai para o mail() nativo do PHP
 * (comportamento antigo, mantido como fallback pra não quebrar em ambiente
 * local sem credenciais de SMTP configuradas).
 */
class Mailer
{
    public static function enviar(string $para, string $assunto, string $corpoHtml): bool
    {
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            return self::enviarViaSmtp($para, $assunto, $corpoHtml);
        }

        return self::enviarViaMailNativo($para, $assunto, $corpoHtml);
    }

    private static function enviarViaSmtp(string $para, string $assunto, string $corpoHtml): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 12;
            $mail->SMTPKeepAlive = false;

            // O "From" precisa ser a própria caixa autenticada (SMTP_USER) --
            // a maioria dos provedores (Hostinger inclusive) rejeita ou marca
            // como spam quando o remetente não é o dono da conta autenticada.
            $mail->setFrom(SMTP_USER, 'Prime Hub');
            $mail->addAddress($para);
            $mail->Subject = $assunto;
            $mail->isHTML(true);
            $mail->Body = $corpoHtml;

            $mail->send();
            return true;
        } catch (PHPMailerException | \Throwable $e) {
            error_log('Mailer (SMTP): falha ao enviar e-mail para ' . $para . ' (assunto: ' . $assunto . '): ' . $e->getMessage());
            return false;
        }
    }

    private static function enviarViaMailNativo(string $para, string $assunto, string $corpoHtml): bool
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
