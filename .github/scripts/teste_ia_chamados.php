<?php
/**
 * Teste de ponta a ponta da IA dos chamados (src/IaChamados.php), rodado
 * pelo workflow "Testar IA dos chamados" num MySQL descartável -- NUNCA
 * no banco de produção. Cria um cliente e alguns chamados já resolvidos
 * (dados fictícios), abre chamados novos e mostra o que a IA respondeu.
 *
 * Uso: php .github/scripts/teste_ia_chamados.php [--sem-ia]
 *   --sem-ia  só testa a busca de chamados parecidos (não chama o Gemini)
 */

require __DIR__ . '/../../bootstrap.php';

use AdminPrime\Configuracoes;
use AdminPrime\IaChamados;

$semIa = in_array('--sem-ia', $argv, true);

if (!str_contains(DB_NAME, 'test')) {
    exit("Recusado: só roda em banco de teste (DB_NAME precisa conter 'test').\n");
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['chamado_ia_analises', 'chamado_respostas', 'chamados'] as $t) {
    $pdo->exec("DELETE FROM $t");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pdo->exec("INSERT IGNORE INTO clientes (id, nome, contato_nome, status) VALUES (900, 'CLIENTE TESTE IA', 'Mariana Souza', 'ativo')");

function criar_chamado(PDO $pdo, string $titulo, string $descricao, string $status = 'aberto'): int
{
    $pdo->prepare("INSERT INTO chamados (cliente_id, titulo, descricao, categoria, status, aberto_por) VALUES (900, ?, ?, 'Suporte', ?, 'cliente')")
        ->execute([$titulo, $descricao, $status]);

    return (int) $pdo->lastInsertId();
}

function responder(PDO $pdo, int $id, string $tipo, string $msg): void
{
    $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, mensagem) VALUES (?, ?, ?, ?)')
        ->execute([$id, $tipo, $tipo === 'equipe' ? 'Kleber' : 'Cliente', $msg]);
}

// Base "histórica" (fictícia) de chamados resolvidos.
$a = criar_chamado($pdo, 'ERRO AO TRANSMITIR NF-E REJEICAO 539', 'Ao transmitir a nota fiscal de saída pelo SPEDNFE aparece Rejeição 539: Duplicidade de NF-e com diferença na chave de acesso. A nota fica parada.', 'fechado');
responder($pdo, $a, 'equipe', "Oi, tudo bem? Essa rejeição 539 acontece quando a SEFAZ já tem uma nota com o mesmo número mas a chave ficou diferente (normalmente por causa de data/hora de emissão ou do código numérico). Faz assim:\n1) No SPEDNFE, use Monitorar pra ver o retorno completo da SEFAZ;\n2) Consulte a chave que veio no retorno da rejeição no portal da SEFAZ;\n3) Se a nota já estiver autorizada lá, use a opção de recuperar o XML/consultar a NF-e pra atualizar o status no Protheus, sem retransmitir.\nSe não estiver autorizada, inutilize a numeração e emita de novo. Qualquer coisa me chama.");
responder($pdo, $a, 'cliente', 'Deu certo, obrigado!');

$b = criar_chamado($pdo, 'PEDIDO DE VENDA NAO LIBERA - BLOQUEIO DE CREDITO', 'No MATA410 o pedido fica com bloqueio de crédito mesmo o cliente tendo limite. Liberação pelo MATA450 não resolve.', 'fechado');
responder($pdo, $b, 'equipe', "Oi! Verifiquei aqui: o limite do cliente (A1_LC) está ok, mas o vencimento do limite (A1_VENCLC) estava vencido, e aí o Protheus bloqueia tudo por crédito. Atualiza a data de vencimento do limite no cadastro do cliente e depois reavalia o crédito do pedido no MATA450 que ele libera.");

$c = criar_chamado($pdo, 'TROCA DE SENHA DO USUARIO', 'Usuário esqueceu a senha do Protheus.', 'fechado');
responder($pdo, $c, 'equipe', 'Senha resetada, a nova senha provisória foi enviada por e-mail.');

// Chamados NOVOS.
$parecido = criar_chamado($pdo, 'REJEICAO 539 NA TRANSMISSAO DA NFE', "Bom dia, estamos com a nota 12345 travada. Na transmissão no SPEDNFE volta Rejeição 539 duplicidade de NF-e com diferença na chave de acesso. Podem ajudar?");
$novo = criar_chamado($pdo, 'ERRO NO CALCULO DO DIFAL NA NOTA DE SAIDA', "Ao emitir nota de venda para consumidor final não contribuinte em outro estado, o DIFAL não está sendo calculado na nota (campos F2_DIFAL zerados). TES com cálculo de DIFAL ativado. Versão 12.1.2310.");
$curto = criar_chamado($pdo, 'AJUDA', 'socorro');

echo "Palavras/semelhantes (sem IA):\n";
$ref = new ReflectionClass(IaChamados::class);
$palavras = $ref->getMethod('palavrasChave');
$similares = $ref->getMethod('chamadosSimilares');
foreach ([$parecido, $novo, $curto] as $id) {
    $ch = $pdo->query("SELECT ch.*, 'CLIENTE TESTE IA' AS cliente_nome FROM chamados ch WHERE id = $id")->fetch();
    $p = $palavras->invoke(null, $ch['titulo'] . ' ' . $ch['descricao']);
    $s = $similares->invoke(null, $pdo, $ch, $p);
    echo "  #$id palavras=" . implode(',', $p) . ' -> parecidos=' . implode(',', array_map(fn ($x) => '#' . $x['id'] . '(' . $x['pontos'] . ')', $s)) . "\n";
}

if ($semIa) {
    exit(0);
}

echo "\n=== Modo sugestão ===\n";
Configuracoes::definir($pdo, 'ia_chamados_modo', 'sugestao', null);
$falhas = 0;
foreach (['parecido (deve usar nossa base)' => $parecido, 'novo (deve buscar no TDN/User Function)' => $novo, 'curto (deve ignorar)' => $curto] as $rotulo => $id) {
    IaChamados::agendar($pdo, $id);
    $inicio = microtime(true);
    $r = IaChamados::processar($pdo, $id);
    printf("\n--- Chamado #%d, %s: status=%s fonte=%s busca=%d tokens=%d+%d (%.1fs)\n",
        $id, $rotulo, $r['status'], $r['fonte'] ?? '-', $r['usou_busca'], $r['tokens_entrada'], $r['tokens_saida'], microtime(true) - $inicio);
    if ($r['erro']) {
        echo "erro/motivo: {$r['erro']}\n";
    }
    if ($r['resposta']) {
        echo $r['resposta'] . "\n";
    }
    if ($r['links']) {
        echo 'fontes: ' . implode(' | ', array_column(json_decode($r['links'], true), 'titulo')) . "\n";
    }
    if ($r['status'] === 'erro') {
        $falhas++;
    }
}

echo "\n=== Botão 'Sugerir resposta com IA' (manual, conversa em andamento) ===\n";
$manual = criar_chamado($pdo, 'BOLETOS SENHA ITAU', 'DESENVOLVIMENTO DOS BOLETOS COM SENHA DO BANCO ITAÚ', 'aguardando_equipe');
responder($pdo, $manual, 'equipe', 'boleto do banco itaú foi validado e testado no C6 BANK');
$antes = (int) $pdo->query("SELECT COUNT(*) FROM chamado_respostas WHERE chamado_id = $manual")->fetchColumn();
$r = IaChamados::processar($pdo, $manual, true);
for ($i = 0; $i < 2 && $r['status'] === 'erro' && preg_match('/HTTP 503/', (string) $r['erro']); $i++) {
    sleep(15);
    $r = IaChamados::processar($pdo, $manual, true);
}
$depois = (int) $pdo->query("SELECT COUNT(*) FROM chamado_respostas WHERE chamado_id = $manual")->fetchColumn();
printf("status=%s fonte=%s tokens=%d+%d respostas no chamado antes/depois=%d/%d\n", $r['status'], $r['fonte'] ?? '-', $r['tokens_entrada'], $r['tokens_saida'], $antes, $depois);
echo ($r['resposta'] ?? $r['erro']) . "\n";
if ($depois !== $antes || $r['status'] !== 'sugerida') {
    echo "FALHA: botão manual não pode postar nada no chamado e deve trazer sugestão\n";
    $falhas++;
}

echo "\n=== Modo automático: responde e escolhe o status ===\n";
Configuracoes::definir($pdo, 'ia_chamados_modo', 'automatico', null);
$casos = [
    'cliente precisa agir (passos pra seguir)' => [['NOTA COM REJEICAO 539 DUPLICIDADE', 'Transmiti a NF-e e deu rejeição 539 de duplicidade com diferença na chave. O que fazer?'], ['aguardando_cliente']],
    'pedido de desenvolvimento (trabalho nosso)' => [['CAMPO D3_OBSERVA NA ROTINA MATA261', 'Bom dia! A rotina MATA241 tem o campo D3_OBSERVA e quero disponibilizar ele na rotina MATA261, ambas usam a tabela SD3. Pode disponibilizar na base teste para validarmos e posterior na produção?'], ['aguardando_equipe']],
];
foreach ($casos as $rotulo => [[$titulo, $descricao], $esperados]) {
    $id = criar_chamado($pdo, $titulo, $descricao);
    IaChamados::agendar($pdo, $id);
    $r = IaChamados::processar($pdo, $id);
    // Google instável (503) devolve pra fila -- simula o cron tentando de novo.
    for ($i = 0; $i < 2 && $r && $r['status'] === 'pendente'; $i++) {
        echo "Google instável, nova tentativa em 20s\n";
        sleep(20);
        $r = IaChamados::processar($pdo, $id);
    }
    $st = $pdo->query("SELECT status FROM chamados WHERE id = $id")->fetchColumn();
    $resp = $pdo->query("SELECT autor_nome, mensagem FROM chamado_respostas WHERE chamado_id = $id")->fetch();
    printf("\n--- %s: IA=%s fonte=%s chamado ficou=%s (esperado: %s)\n%s\n", $rotulo, $r['status'] ?? '-', $r['fonte'] ?? '-', $st, implode('/', $esperados), $resp['mensagem'] ?? '(nada postado)');
    if (($r['status'] ?? '') !== 'enviada' || !$resp || !in_array($st, $esperados, true)) {
        echo "FALHA\n";
        $falhas++;
    }
}

exit($falhas ? 1 : 0);
