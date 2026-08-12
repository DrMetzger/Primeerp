<?php

namespace AdminPrime;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envio de e-mail. Usa SMTP autenticado (via PHPMailer) quando o host SMTP
 * estiver configurado -- caso contrário cai para o mail() nativo do PHP
 * (comportamento antigo, mantido como fallback pra não quebrar em ambiente
 * local sem credenciais de SMTP configuradas).
 *
 * Cada configuração prefere o valor salvo pela tela configuracoes.php
 * (tabela `configuracoes`) e só cai pra constante de config/config.php se
 * nada tiver sido salvo ainda pela tela -- mesmo esquema do WhatsAppApi.
 */
class Mailer
{
    private static function config(string $chave, string $constante): string
    {
        return Configuracoes::get(Database::connection(), $chave, defined($constante) ? (string) constant($constante) : '') ?? '';
    }

    public static function enviar(string $para, string $assunto, string $corpoHtml): bool
    {
        if (self::config('smtp_host', 'SMTP_HOST') !== '') {
            return self::enviarViaSmtp($para, $assunto, $corpoHtml);
        }

        return self::enviarViaMailNativo($para, $assunto, $corpoHtml);
    }

    private static function enviarViaSmtp(string $para, string $assunto, string $corpoHtml): bool
    {
        $mail = new PHPMailer(true);

        try {
            $usuario = self::config('smtp_user', 'SMTP_USER');

            $mail->isSMTP();
            $mail->Host = self::config('smtp_host', 'SMTP_HOST');
            $mail->Port = (int) self::config('smtp_port', 'SMTP_PORT');
            $mail->SMTPAuth = true;
            $mail->Username = $usuario;
            $mail->Password = self::config('smtp_pass', 'SMTP_PASS');
            $mail->SMTPSecure = self::config('smtp_secure', 'SMTP_SECURE') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 12;
            $mail->SMTPKeepAlive = false;

            // O "From" precisa ser a própria caixa autenticada (SMTP_USER) --
            // a maioria dos provedores (Hostinger inclusive) rejeita ou marca
            // como spam quando o remetente não é o dono da conta autenticada.
            $mail->setFrom($usuario, self::config('smtp_nome_remetente', 'SMTP_NOME_REMETENTE') ?: 'Prime Hub');
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
        // Sem host SMTP configurado (nem por variável de ambiente, nem pela
        // tela de configurações) -- cai pro mail() nativo do PHP, remetente
        // só usa o e-mail salvo em smtp_user se tiver algum, senão o padrão.
        $emailRemetente = self::config('smtp_user', '');
        $de = $emailRemetente !== '' ? $emailRemetente : (defined('MAIL_FROM') ? MAIL_FROM : 'nao-responda@primeerp.com.br');
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $de,
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
