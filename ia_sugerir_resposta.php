<?php
/**
 * Botão "Sugerir resposta com IA" dentro do chamado (cliente.php).
 *
 *  - acao=iniciar: começa a análise e responde NA HORA; a análise roda em
 *    segundo plano no servidor (cada requisição na Hostinger tem limite de
 *    tempo, e a análise faz várias chamadas ao Google).
 *  - acao=status: passo a passo até agora (a tela pergunta a cada ~1,5s) e,
 *    quando terminar, o texto sugerido.
 *
 * NUNCA envia nada pro cliente: só devolve o texto. Quem envia é a pessoa
 * da equipe, depois de ler/editar, pelo botão normal de responder.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\AiSugestoes;
use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\IaChamados;

function responder_json(array $dados, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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

// Libera a sessão: nada daqui pra frente precisa dela, e segurar o lock
// travaria as outras abas do sistema enquanto a análise roda.
session_write_close();

$acao = $_POST['acao'] ?? 'iniciar';

if ($acao === 'status') {
    $stmt = $pdo->prepare('SELECT *, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS segundos_parado FROM chamado_ia_analises WHERE chamado_id = ?');
    $stmt->execute([$chamadoId]);
    $analise = $stmt->fetch();
    if (!$analise) {
        responder_json(['ok' => false, 'erro' => 'Nenhuma análise em andamento.']);
    }

    $passos = json_decode($analise['progresso'] ?? '[]', true) ?: [];
    $emAndamento = in_array($analise['status'], ['processando', 'pendente'], true);

    // Sem nenhum passo novo há muito tempo = o processo morreu no servidor
    // (limite de tempo da hospedagem, por exemplo). Encerra e avisa onde parou.
    if ($emAndamento && (int) $analise['segundos_parado'] > IaChamados::SEGUNDOS_SEM_PROGRESSO_PARADO) {
        $ultimo = $passos ? end($passos)['texto'] : 'antes de começar';
        $erro = 'A análise parou no servidor na etapa: "' . $ultimo . '". Tente de novo.';
        $pdo->prepare("UPDATE chamado_ia_analises SET status = 'erro', erro = ? WHERE chamado_id = ?")->execute([mb_substr($erro, 0, 500), $chamadoId]);
        responder_json(['ok' => true, 'terminou' => true, 'sucesso' => false, 'passos' => $passos, 'erro' => $erro]);
    }

    if ($emAndamento) {
        responder_json(['ok' => true, 'terminou' => false, 'passos' => $passos]);
    }

    if ($analise['status'] !== 'sugerida' || !$analise['resposta']) {
        $erro = $analise['erro'] ?: 'a IA não trouxe sugestão.';
        if (preg_match('/API key|HTTP (401|403)/i', $erro)) {
            $erro = 'o Google recusou a chave do Gemini. Confira a chave em Configurações > IA Gemini.';
        } elseif (preg_match('/HTTP (429|500|503)/', $erro)) {
            $erro = 'o Google está sobrecarregado agora. Tente de novo em alguns segundos.';
        }
        responder_json(['ok' => true, 'terminou' => true, 'sucesso' => false, 'passos' => $passos, 'erro' => $erro]);
    }

    $rotulosFonte = [
        'base' => 'baseada em chamados que já resolvemos' . ($analise['chamados_referencia'] ? ' (#' . str_replace(',', ', #', $analise['chamados_referencia']) . ')' : ''),
        'internet' => 'baseada no TDN/User Function',
        'conversa' => 'sem solução documentada: só com base na conversa, confira com atenção',
    ];

    responder_json([
        'ok' => true,
        'terminou' => true,
        'sucesso' => true,
        'passos' => $passos,
        'resposta' => $analise['resposta'],
        'fonte' => $rotulosFonte[$analise['fonte']] ?? '',
        'links' => json_decode($analise['links'] ?? '[]', true) ?: [],
    ]);
}

// --- acao=iniciar ---------------------------------------------------------

if (!AiSugestoes::configurada()) {
    responder_json(['ok' => false, 'erro' => 'Chave do Gemini não configurada (Configurações > IA Gemini).']);
}

// Já tem uma análise rodando pra esse chamado (clique duplo, outra aba)?
// Só acompanha a que já existe, não começa outra (e não gasta 2x).
$stmt = $pdo->prepare("SELECT 1 FROM chamado_ia_analises WHERE chamado_id = ? AND status = 'processando'
    AND atualizado_em > DATE_SUB(NOW(), INTERVAL " . IaChamados::SEGUNDOS_SEM_PROGRESSO_PARADO . ' SECOND)');
$stmt->execute([$chamadoId]);
if ($stmt->fetchColumn()) {
    responder_json(['ok' => true, 'iniciado' => true, 'ja_estava_rodando' => true]);
}

IaChamados::iniciarManual($pdo, $chamadoId);

// Responde pro navegador AGORA e só então roda a análise (mesmo mecanismo
// das notificações: litespeed_finish_request na Hostinger).
notificar_depois(fn () => IaChamados::processar($pdo, $chamadoId, true));
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'iniciado' => true]);
finalizar_resposta_e_notificar();
exit;
