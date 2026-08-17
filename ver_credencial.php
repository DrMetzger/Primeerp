<?php

/**
 * Revela (decifra) a senha de uma credencial do cofre de acessos, via AJAX
 * -- chamado só quando o usuário clica em "Mostrar senha" (nunca embutida
 * em texto puro no HTML da página, ver aba "Acessos" em cliente.php).
 * POST + CSRF (não GET) pra não virar um link "clicável"/cacheável que
 * expõe segredo, e pra exigir o mesmo token de sessão que qualquer outra
 * ação sensível do sistema.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Crypto;
use AdminPrime\Database;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']);
    exit;
}

Auth::requireAcessoDadosSensiveis();
csrf_verify();

$pdo = Database::connection();
$credencialId = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM cliente_credenciais WHERE id = ?');
$stmt->execute([$credencialId]);
$credencial = $stmt->fetch();

if (!$credencial) {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Credencial não encontrada.']);
    exit;
}

$senha = Crypto::decifrar($credencial['senha_cifrada']);

Auth::registrarAcessoSensivel('credencial', 'visualizar_senha', $credencialId, (int) $credencial['cliente_id']);

echo json_encode(['sucesso' => true, 'senha' => $senha ?? '']);
