<?php
/**
 * Ponte do sistema novo (TypeScript, /api) pras telas clássicas: o novo
 * gera um link /sso.php?t=<corpo>.<assinatura> (HMAC-SHA256 com
 * ADMINPRIME_SSO_SECRET, válido por 60s) e aqui a sessão PHP é aberta
 * pra mesma pessoa, sem pedir login de novo. Sem o segredo configurado
 * (ex.: Hostinger), não faz nada.
 */
require_once __DIR__ . '/bootstrap.php';

use AdminPrime\ClienteAuth;

$segredo = (string) getenv('ADMINPRIME_SSO_SECRET');
$token = (string) ($_GET['t'] ?? '');
$partes = explode('.', $token);

$b64 = fn (string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
$assinaturaOk = $segredo !== '' && count($partes) === 2
    && hash_equals(rtrim(strtr(base64_encode(hash_hmac('sha256', $partes[0], $segredo, true)), '+/', '-_'), '='), $partes[1]);
$dados = $assinaturaOk ? json_decode((string) $b64($partes[0]), true) : null;

if (!is_array($dados) || ($dados['exp'] ?? 0) < time() || !preg_match('#^/(?!/)#', (string) ($dados['d'] ?? ''))) {
    header('Location: ' . url('login.php'));
    exit;
}

$id = (int) $dados['id'];
$ok = false;
if ($dados['t'] === 'equipe') {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? AND ativo = 1 LIMIT 1');
    $stmt->execute([$id]);
    if ($usuario = $stmt->fetch()) {
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_perfil'] = $usuario['perfil'];
        $_SESSION['usuario_foto'] = $usuario['foto_perfil'] ?? null;
        $ok = true;
    }
} elseif ($dados['t'] === 'cliente') {
    $ok = ClienteAuth::iniciarSessaoPorId($pdo, (string) ($dados['p'] ?? 'master'), $id);
}

header('Location: ' . ($ok ? $dados['d'] : url($dados['t'] === 'cliente' ? 'portal/login.php' : 'login.php')));
exit;
