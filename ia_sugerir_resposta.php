<?php
/**
 * Botão "Sugerir resposta com IA" dentro do chamado (cliente.php). Analisa
 * o chamado (conversa + prints anexados -> nossa base -> User Function ->
 * TDN) e devolve o texto pra preencher o campo de resposta.
 *
 * NUNCA envia nada pro cliente: só devolve o texto. Quem envia é a pessoa
 * da equipe, depois de ler/editar, pelo botão normal de responder.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\AiSugestoes;
use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\IaChamados;

header('Content-Type: application/json; charset=utf-8');

function responder_json(array $dados, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Auth::check()) {
    responder_json(['ok' => false, 'erro' => 'Sessão expirada, faça login de novo.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    responder_json(['ok' => false, 'erro' => 'Sessão expirada, recarregue a página.'], 419);
}

$pdo = Database::connection();
$chamadoId = (int) ($_POST['chamado_id'] ?? 0);
$stmt = $pdo->prepare('SELECT cliente_id, setor_id FROM chamados WHERE id = ?');
$stmt->execute([$chamadoId]);
$chamado = $stmt->fetch();

if (!$chamado || !$chamado['cliente_id']
    || !Auth::podeAcessarCliente((int) $chamado['cliente_id'])
    || !Auth::podeAcessarSetor((int) $chamado['cliente_id'], $chamado['setor_id'] !== null ? (int) $chamado['setor_id'] : null)) {
    responder_json(['ok' => false, 'erro' => 'Chamado inválido ou fora do seu acesso.'], 403);
}
if (!AiSugestoes::configurada()) {
    responder_json(['ok' => false, 'erro' => 'Chave do Gemini não configurada (Configurações > IA Gemini).']);
}

// Libera a sessão: a análise leva alguns segundos e não pode travar as
// outras abas do sistema enquanto isso.
session_write_close();

$analise = IaChamados::processar($pdo, $chamadoId, true);

if (!$analise || $analise['status'] !== 'sugerida' || !$analise['resposta']) {
    $erro = $analise['erro'] ?? 'motivo desconhecido';
    if (preg_match('/HTTP (429|500|503)/', $erro)) {
        $erro = 'O Google está sobrecarregado agora. Tente de novo em alguns segundos.';
    }
    responder_json(['ok' => false, 'erro' => 'A IA não conseguiu sugerir uma resposta: ' . $erro]);
}

$rotulosFonte = [
    'base' => 'baseada em chamados que já resolvemos' . ($analise['chamados_referencia'] ? ' (#' . str_replace(',', ', #', $analise['chamados_referencia']) . ')' : ''),
    'internet' => 'baseada no TDN/User Function',
    'conversa' => 'sem solução documentada: só com base na conversa, confira com atenção',
];

responder_json([
    'ok' => true,
    'resposta' => $analise['resposta'],
    'fonte' => $rotulosFonte[$analise['fonte']] ?? '',
    'links' => json_decode($analise['links'] ?? '[]', true) ?: [],
]);
