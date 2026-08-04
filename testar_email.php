<?php

/**
 * Ferramenta pontual: envia um e-mail de teste real via Mailer (SMTP
 * configurado) pra confirmar que a entrega está funcionando em produção,
 * já que o sandbox de desenvolvimento não tem acesso de rede pra testar a
 * conexão SMTP diretamente.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Mailer;

Auth::requireAdminOrTempToken(Database::connection());

$destino = trim($_GET['para'] ?? '');
$resultado = null;

if ($destino !== '') {
    $ok = Mailer::enviar(
        $destino,
        'Teste de SMTP - Prime Hub',
        '<p>Este é um e-mail de teste enviado pelo Prime Hub para confirmar que o envio via SMTP autenticado está funcionando.</p>
         <p>Se você recebeu esta mensagem, a configuração está correta.</p>'
    );
    $resultado = $ok ? 'Enviado com sucesso (retorno true).' : 'Falhou (retorno false) -- ver log de erros do PHP para detalhes.';
}

header('Content-Type: text/plain; charset=UTF-8');
echo "SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : '(não definido)') . "\n";
echo "SMTP_USER: " . (defined('SMTP_USER') ? SMTP_USER : '(não definido)') . "\n";
echo "Destino: " . ($destino ?: '(nenhum -- use ?para=email@exemplo.com)') . "\n";
echo "Resultado: " . ($resultado ?? '(nenhum teste executado)') . "\n";
