<?php
/**
 * Botão "Sugerir resposta com IA" dentro do chamado (cliente.php).
 *
 *  - acao=iniciar: zera e prepara a análise (responde na hora).
 *  - acao=passo: executa UMA etapa e devolve o passo a passo até agora. A
 *    tela chama de novo até terminar. Etapa curta = cabe no limite de tempo
 *    de cada requisição da Hostinger (rodar tudo de uma vez, ou em segundo
 *    plano, fazia o servidor matar o processo no meio da busca no TDN).
 *  - acao=status: só lê o passo a passo/resultado, sem executar nada.
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
// Sistema novo (Prime Hub) manda o cabeçalho X-PrimeHub no lugar do token
// do formulário -- outro site não consegue mandar cabeçalho próprio.
$vemDoSistemaNovo = ($_SERVER['HTTP_X_PRIMEHUB'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!$vemDoSistemaNovo && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))) {
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

/** Passo a passo + resultado, no formato que a tela entende. */
function estado_para_tela(PDO $pdo, int $chamadoId): array
{
    $stmt = $pdo->prepare('SELECT * FROM chamado_ia_analises WHERE chamado_id = ?');
    $stmt->execute([$chamadoId]);
    $analise = $stmt->fetch();
    if (!$analise) {
        return ['ok' => false, 'erro' => 'Nenhuma análise em andamento.'];
    }

    $passos = json_decode($analise['progresso'] ?? '[]', true) ?: [];
    if (in_array($analise['status'], ['processando', 'pendente'], true)) {
        return ['ok' => true, 'terminou' => false, 'passos' => $passos];
    }

    if ($analise['status'] !== 'sugerida' || !$analise['resposta']) {
        $erro = $analise['erro'] ?: 'a IA não trouxe sugestão.';
        if (preg_match('/API key|HTTP (401|403)/i', $erro)) {
            $erro = 'o Google recusou a chave do Gemini. Confira a chave em Configurações > IA Gemini.';
        } elseif (preg_match('/HTTP (429|500|503)/', $erro)) {
            $erro = 'o Google está sobrecarregado agora. Tente de novo em alguns segundos.';
        }

        return ['ok' => true, 'terminou' => true, 'sucesso' => false, 'passos' => $passos, 'erro' => $erro];
    }

    $rotulosFonte = [
        'base' => 'baseada em chamados que já resolvemos' . ($analise['chamados_referencia'] ? ' (#' . str_replace(',', ', #', $analise['chamados_referencia']) . ')' : ''),
        'internet' => 'baseada no TDN/User Function',
        'conversa' => 'sem solução documentada: só com base na conversa, confira com atenção',
    ];

    return [
        'ok' => true,
        'terminou' => true,
        'sucesso' => true,
        'passos' => $passos,
        'resposta' => $analise['resposta'],
        'fonte' => $rotulosFonte[$analise['fonte']] ?? '',
        'links' => json_decode($analise['links'] ?? '[]', true) ?: [],
        'status_sugerido' => $analise['status_sugerido'] ?: 'aguardando_cliente',
        'rotulo_status' => IaChamados::rotuloStatus($analise['status_sugerido']),
    ];
}

if ($acao === 'status') {
    responder_json(estado_para_tela($pdo, $chamadoId));
}

if ($acao === 'passo') {
    // Uma etapa por requisição (cada uma faz no máximo uma chamada ao
    // Google) -- cabe no limite de tempo da hospedagem. A tela chama de novo
    // até terminar; se uma requisição morrer no meio, a próxima pula aquela etapa.
    @set_time_limit(120);
    ignore_user_abort(true);
    $r = IaChamados::executarEtapa($pdo, $chamadoId, true, ($_POST['anterior_falhou'] ?? '') === '1');
    $tela = estado_para_tela($pdo, $chamadoId);
    $tela['ocupada'] = $r['ocupada'];
    responder_json($tela);
}

// --- acao=iniciar ---------------------------------------------------------

if (!AiSugestoes::configurada()) {
    responder_json(['ok' => false, 'erro' => 'Chave do Gemini não configurada (Configurações > IA Gemini).']);
}

// Já tem uma análise manual rodando pra esse chamado (clique duplo, outra
// aba)? Só acompanha a que já existe, não começa outra (e não gasta 2x).
$stmt = $pdo->prepare("SELECT 1 FROM chamado_ia_analises WHERE chamado_id = ? AND status = 'processando'
    AND estado LIKE '%\"manual\":true%' AND atualizado_em > DATE_SUB(NOW(), INTERVAL " . IaChamados::SEGUNDOS_ETAPA_MORTA . ' SECOND)');
$stmt->execute([$chamadoId]);
if (!$stmt->fetchColumn()) {
    IaChamados::iniciarManual($pdo, $chamadoId);
}

responder_json(['ok' => true, 'iniciado' => true]);
