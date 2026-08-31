<?php

/**
 * Agenda de trabalho da gestão -- pedido do Kleber: ele tem todos os
 * chamados cadastrados mas fica perdido em qual cliente atender primeiro
 * num dia. Aqui ele (e o Petronilho, mesmo quadro compartilhado -- não é
 * agenda pessoal de cada um) monta um plano do dia: tarefas com prioridade
 * ARRASTÁVEL, opcionalmente ligadas a um cliente/chamado, ou uma tarefa
 * livre qualquer, com anotações. Um calendário mensal/semanal/diário
 * (reaproveitando o mesmo componente já usado pros treinamentos, ver
 * assets/js/calendario_visual.js) dá a visão geral e deixa navegar/criar/
 * editar; o painel "Prioridades do dia" logo abaixo é onde acontece a
 * reordenação de verdade. Só admin/diretor/CEO usam isso por enquanto
 * ("colaborador não precisa").
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\AiSugestoes;
use AdminPrime\Database;

const MESES_ABREV_PT_AGENDA = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

Auth::requireAdmin();

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';
    $ehAjax = ($_POST['ajax'] ?? '') === '1';

    if ($acao === 'criar_tarefa') {
        $titulo = trim($_POST['titulo'] ?? '');
        $data = $_POST['data'] ?? '';
        $clienteId = (int) ($_POST['cliente_id'] ?? 0) ?: null;
        $chamadoId = (int) ($_POST['chamado_id'] ?? 0) ?: null;
        $notas = trim($_POST['notas'] ?? '') ?: null;

        if ($titulo === '' || !$data || !strtotime($data)) {
            flash_set('erro', 'Informe um título e uma data válida.');
        } else {
            $stmtOrdemMax = $pdo->prepare('SELECT COALESCE(MAX(ordem), -1) FROM agenda_tarefas WHERE data = ?');
            $stmtOrdemMax->execute([$data]);
            $ordemMax = (int) $stmtOrdemMax->fetchColumn();

            $pdo->prepare('INSERT INTO agenda_tarefas (data, ordem, titulo, cliente_id, chamado_id, notas, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$data, $ordemMax + 1, $titulo, $clienteId, $chamadoId, $notas, Auth::userId()]);
            flash_set('sucesso', 'Tarefa adicionada na agenda.');
        }
    }

    if ($acao === 'atualizar_tarefa') {
        $tarefaId = (int) ($_POST['tarefa_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $data = $_POST['data'] ?? '';
        $clienteId = (int) ($_POST['cliente_id'] ?? 0) ?: null;
        $chamadoId = (int) ($_POST['chamado_id'] ?? 0) ?: null;
        $notas = trim($_POST['notas'] ?? '') ?: null;
        $status = in_array($_POST['status'] ?? '', ['pendente', 'em_andamento', 'concluida'], true) ? $_POST['status'] : 'pendente';

        if ($titulo === '' || !$data || !strtotime($data)) {
            flash_set('erro', 'Informe um título e uma data válida.');
        } else {
            $pdo->prepare('UPDATE agenda_tarefas SET titulo=?, data=?, cliente_id=?, chamado_id=?, notas=?, status=? WHERE id=?')
                ->execute([$titulo, $data, $clienteId, $chamadoId, $notas, $status, $tarefaId]);
            flash_set('sucesso', 'Tarefa atualizada.');
        }
    }

    if ($acao === 'excluir_tarefa') {
        $pdo->prepare('DELETE FROM agenda_tarefas WHERE id = ?')->execute([(int) ($_POST['tarefa_id'] ?? 0)]);
        flash_set('sucesso', 'Tarefa removida.');
    }

    // Só as duas ações abaixo são AJAX de verdade (chamadas via fetch, sem
    // navegar a página) -- as de cima seguem o padrão comum do resto do
    // sistema (POST + redirect).
    if ($acao === 'mudar_status_tarefa' && $ehAjax) {
        $tarefaId = (int) ($_POST['tarefa_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pendente', 'em_andamento', 'concluida'], true) ? $_POST['status'] : 'pendente';
        $pdo->prepare('UPDATE agenda_tarefas SET status = ? WHERE id = ?')->execute([$status, $tarefaId]);
        header('Content-Type: application/json');
        echo json_encode(['sucesso' => true]);
        exit;
    }

    if ($acao === 'reordenar_tarefas' && $ehAjax) {
        $mudancas = json_decode($_POST['mudancas'] ?? '[]', true);
        if (is_array($mudancas)) {
            $stmtReordenar = $pdo->prepare('UPDATE agenda_tarefas SET data = ?, ordem = ? WHERE id = ?');
            foreach ($mudancas as $m) {
                if (!isset($m['id'], $m['data'], $m['ordem'])) {
                    continue;
                }
                $stmtReordenar->execute([$m['data'], (int) $m['ordem'], (int) $m['id']]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['sucesso' => true]);
        exit;
    }

    if ($acao === 'gerar_sugestao_ia' && $ehAjax) {
        // $clientes/$nomesClientesPorId só são montados lá embaixo, na seção
        // de carregamento do GET -- esse handler sempre dá exit antes de
        // chegar lá, então carrega a própria cópia aqui.
        $clientesSugestao = $pdo->query("SELECT id, nome FROM clientes WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
        $nomesClientesPorIdSugestao = array_column($clientesSugestao, 'nome', 'id');

        $dataAncoraSugestao = $_POST['data'] ?? date('Y-m-d');
        if (!strtotime($dataAncoraSugestao)) {
            $dataAncoraSugestao = date('Y-m-d');
        }
        $periodoSugestao = in_array($_POST['periodo'] ?? '', ['dia', 'semana', 'mes'], true) ? $_POST['periodo'] : 'dia';
        $clienteIdSugestao = (int) ($_POST['cliente_id'] ?? 0) ?: null;
        $criarTarefasSugestao = ($_POST['criar_tarefas'] ?? '') === '1';

        $tsAncoraSugestao = strtotime($dataAncoraSugestao);
        if ($periodoSugestao === 'semana') {
            $diaSemanaAncora = (int) date('N', $tsAncoraSugestao); // 1=segunda .. 7=domingo
            $dataInicioSugestao = date('Y-m-d', strtotime('-' . ($diaSemanaAncora - 1) . ' days', $tsAncoraSugestao));
            $dataFimSugestao = date('Y-m-d', strtotime('+' . (7 - $diaSemanaAncora) . ' days', $tsAncoraSugestao));
        } elseif ($periodoSugestao === 'mes') {
            $dataInicioSugestao = date('Y-m-01', $tsAncoraSugestao);
            $dataFimSugestao = date('Y-m-t', $tsAncoraSugestao);
        } else {
            $dataInicioSugestao = $dataFimSugestao = $dataAncoraSugestao;
        }

        // Só dias úteis (seg-sex) entram como data permitida pra IA distribuir
        // tarefa -- fim de semana o Kleber não trabalha, não faz sentido
        // sugerir tarefa nesses dias quando o período é semana/mês.
        $datasUteisSugestao = [];
        for ($ts = strtotime($dataInicioSugestao); $ts <= strtotime($dataFimSugestao); $ts += 86400) {
            if ((int) date('N', $ts) <= 5) {
                $datasUteisSugestao[] = date('Y-m-d', $ts);
            }
        }
        if (!$datasUteisSugestao) {
            $datasUteisSugestao[] = $dataAncoraSugestao;
        }

        $stmtTarefasPeriodoSugestao = $pdo->prepare("
            SELECT t.data, t.titulo, t.status, t.notas, c.nome AS nome_cliente
            FROM agenda_tarefas t
            LEFT JOIN clientes c ON c.id = t.cliente_id
            WHERE t.data BETWEEN ? AND ?" . ($clienteIdSugestao ? ' AND t.cliente_id = ?' : '') . "
            ORDER BY t.data ASC, t.ordem ASC
        ");
        $paramsTarefasSugestao = [$dataInicioSugestao, $dataFimSugestao];
        if ($clienteIdSugestao) {
            $paramsTarefasSugestao[] = $clienteIdSugestao;
        }
        $stmtTarefasPeriodoSugestao->execute($paramsTarefasSugestao);
        $tarefasDoPeriodoSugestao = $stmtTarefasPeriodoSugestao->fetchAll();

        $stmtChamadosSugestao = $pdo->prepare("
            SELECT ch.id, ch.titulo, ch.prioridade, ch.status, ch.criado_em, ch.cliente_id, c.nome AS nome_cliente
            FROM chamados ch
            INNER JOIN clientes c ON c.id = ch.cliente_id
            WHERE ch.status != 'fechado'" . ($clienteIdSugestao ? ' AND ch.cliente_id = ?' : '') . "
            ORDER BY FIELD(ch.prioridade, 'urgente', 'alta', 'media', 'baixa'), ch.criado_em ASC
            LIMIT 80
        ");
        $stmtChamadosSugestao->execute($clienteIdSugestao ? [$clienteIdSugestao] : []);
        $chamadosAbertosSugestao = $stmtChamadosSugestao->fetchAll();

        $rotuloPeriodoSugestao = [
            'dia' => 'hoje (' . formatar_data($dataAncoraSugestao) . ')',
            'semana' => 'essa semana (' . formatar_data($dataInicioSugestao) . ' a ' . formatar_data($dataFimSugestao) . ', dias úteis)',
            'mes' => 'esse mês (' . formatar_data($dataInicioSugestao) . ' a ' . formatar_data($dataFimSugestao) . ', dias úteis)',
        ][$periodoSugestao];
        $rotuloClienteSugestao = $clienteIdSugestao ? ($nomesClientesPorIdSugestao[$clienteIdSugestao] ?? 'esse cliente') : 'todos os clientes';

        $blocoContexto = "Você é um assistente de organização pra um gestor de uma empresa de suporte/consultoria em ERP (Protheus). "
            . "Ele tem chamados de suporte abertos de vários clientes e precisa priorizar o que atacar em {$rotuloPeriodoSugestao}, olhando só pro cliente/clientes: {$rotuloClienteSugestao}.\n\n"
            . "Tarefas já planejadas no período:\n";
        if (!$tarefasDoPeriodoSugestao) {
            $blocoContexto .= "(nenhuma ainda)\n";
        }
        foreach ($tarefasDoPeriodoSugestao as $t) {
            $blocoContexto .= '- ' . formatar_data($t['data']) . ': ' . $t['titulo'] . ($t['nome_cliente'] ? ' (' . $t['nome_cliente'] . ')' : '') . ' [' . $t['status'] . "]\n";
        }
        $blocoContexto .= "\nChamados abertos (id / título / cliente / cliente_id / prioridade / status / aberto em):\n";
        if (!$chamadosAbertosSugestao) {
            $blocoContexto .= "(nenhum chamado aberto no momento)\n";
        }
        foreach ($chamadosAbertosSugestao as $c) {
            $blocoContexto .= "- #{$c['id']} | {$c['titulo']} | {$c['nome_cliente']} | cliente_id={$c['cliente_id']} | {$c['prioridade']} | {$c['status']} | " . formatar_data($c['criado_em']) . "\n";
        }

        if (!$criarTarefasSugestao) {
            $prompt = $blocoContexto
                . "\nAnalise a lista de chamados abertos acima (já ordenados por prioridade/urgência/idade) e as tarefas já planejadas, "
                . "e sugira uma ordem de prioridade -- poucas linhas, direto ao ponto, focando nos clientes/chamados mais urgentes ou mais antigos sem resposta. "
                . "Pode sugerir agrupar por cliente ou por dia se fizer sentido. Responda em texto corrido, sem markdown, curto (no máximo uns 10-15 linhas).";

            $resultadoIa = AiSugestoes::gerar($prompt);
            header('Content-Type: application/json');
            echo json_encode($resultadoIa['ok']
                ? ['sucesso' => true, 'tipo' => 'texto', 'sugestao' => $resultadoIa['texto']]
                : ['sucesso' => false, 'mensagem' => $resultadoIa['erro']]);
            exit;
        }

        $prompt = $blocoContexto
            . "\nDatas permitidas pra colocar tarefa (dias úteis do período): " . implode(', ', $datasUteisSugestao) . "\n\n"
            . "Monte um plano de tarefas pra esse período, priorizando os chamados mais urgentes/antigos, distribuindo entre os dias úteis de forma equilibrada "
            . "(não empilhe tudo num dia só se o período for semana/mês). Pode juntar mais de um chamado do mesmo cliente numa tarefa só se fizer sentido, "
            . "ou criar uma tarefa por chamado -- use seu critério. Não recrie tarefas que já existem no período (listadas acima).\n\n"
            . "Responda APENAS com um objeto JSON válido (sem markdown, sem texto antes ou depois, sem blocos de código), exatamente neste formato:\n"
            . '{"resumo": "3 a 5 frases em português explicando a lógica geral da priorização", '
            . '"tarefas": [{"data": "AAAA-MM-DD (uma das datas permitidas acima)", "titulo": "ação curta e clara", '
            . '"cliente_id": (um dos cliente_id listados acima, ou null se não for de um cliente específico), '
            . '"chamado_id": (um dos id de chamado listados acima, ou null se a tarefa não for sobre um chamado específico), '
            . '"notas": "detalhe opcional, string vazia se não tiver"}]}';

        $resultadoIa = AiSugestoes::gerar($prompt);
        if (!$resultadoIa['ok']) {
            header('Content-Type: application/json');
            echo json_encode(['sucesso' => false, 'mensagem' => $resultadoIa['erro']]);
            exit;
        }

        $textoJson = trim((string) $resultadoIa['texto']);
        $textoJson = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $textoJson);
        $dadosPlano = json_decode($textoJson, true);

        if (!is_array($dadosPlano) || !isset($dadosPlano['tarefas']) || !is_array($dadosPlano['tarefas'])) {
            header('Content-Type: application/json');
            echo json_encode(['sucesso' => false, 'mensagem' => 'A IA não retornou um plano em formato válido. Tente de novo.']);
            exit;
        }

        $idsClientesValidos = array_map('intval', array_column($clientesSugestao, 'id'));
        $idsChamadosValidos = array_map('intval', array_column($chamadosAbertosSugestao, 'id'));
        $clientePorChamado = array_column($chamadosAbertosSugestao, 'cliente_id', 'id');
        $datasPermitidasSet = array_flip($datasUteisSugestao);

        $ordemMaxPorData = [];
        $tarefasCriadasSugestao = [];
        $stmtInserirSugestao = $pdo->prepare('INSERT INTO agenda_tarefas (data, ordem, titulo, cliente_id, chamado_id, notas, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?)');

        foreach ($dadosPlano['tarefas'] as $item) {
            $dataItem = (string) ($item['data'] ?? '');
            if (!isset($datasPermitidasSet[$dataItem])) {
                continue;
            }
            $tituloItem = mb_substr(trim((string) ($item['titulo'] ?? '')), 0, 200);
            if ($tituloItem === '') {
                continue;
            }
            $clienteIdItem = isset($item['cliente_id']) ? (int) $item['cliente_id'] : 0;
            if (!in_array($clienteIdItem, $idsClientesValidos, true)) {
                $clienteIdItem = null;
            }
            $chamadoIdItem = isset($item['chamado_id']) ? (int) $item['chamado_id'] : 0;
            if (!in_array($chamadoIdItem, $idsChamadosValidos, true)) {
                $chamadoIdItem = null;
            } elseif (!$clienteIdItem && isset($clientePorChamado[$chamadoIdItem])) {
                $clienteIdItem = (int) $clientePorChamado[$chamadoIdItem];
            }
            $notasItem = trim((string) ($item['notas'] ?? '')) ?: null;

            if (!isset($ordemMaxPorData[$dataItem])) {
                $stmtOrdemMaxSugestao = $pdo->prepare('SELECT COALESCE(MAX(ordem), -1) FROM agenda_tarefas WHERE data = ?');
                $stmtOrdemMaxSugestao->execute([$dataItem]);
                $ordemMaxPorData[$dataItem] = (int) $stmtOrdemMaxSugestao->fetchColumn();
            }
            $ordemMaxPorData[$dataItem]++;

            $stmtInserirSugestao->execute([$dataItem, $ordemMaxPorData[$dataItem], $tituloItem, $clienteIdItem, $chamadoIdItem, $notasItem, Auth::userId()]);
            $tarefasCriadasSugestao[] = $dataItem;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'sucesso' => true,
            'tipo' => 'tarefas',
            'resumo' => $dadosPlano['resumo'] ?? '',
            'totalCriadas' => count($tarefasCriadasSugestao),
            'dataFoco' => $tarefasCriadasSugestao[0] ?? $dataAncoraSugestao,
        ]);
        exit;
    }

    header('Location: ' . url('agenda.php' . (isset($_POST['data']) && $_POST['data'] ? '?data=' . urlencode($_POST['data']) : '')));
    exit;
}

// --- Carregamento de dados -------------------------------------------------

$dataFocoParam = $_GET['data'] ?? date('Y-m-d');
if (!strtotime($dataFocoParam)) {
    $dataFocoParam = date('Y-m-d');
}

$clientes = $pdo->query("SELECT id, nome FROM clientes WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$nomesClientesPorId = array_column($clientes, 'nome', 'id');

$chamadosAbertos = $pdo->query("
    SELECT id, titulo, cliente_id, prioridade
    FROM chamados
    WHERE status != 'fechado'
    ORDER BY criado_em DESC
")->fetchAll();

// Todas as tarefas -- o volume aqui é baixo (uso interno, só uns poucos
// gestores criando algumas tarefas por dia), não precisa de filtro de
// período pra não pesar a consulta.
$todasTarefas = $pdo->query("
    SELECT t.*, c.nome AS nome_cliente, ch.titulo AS titulo_chamado
    FROM agenda_tarefas t
    LEFT JOIN clientes c ON c.id = t.cliente_id
    LEFT JOIN chamados ch ON ch.id = t.chamado_id
    ORDER BY t.data ASC, t.ordem ASC
")->fetchAll();

$tarefasPorData = [];
foreach ($todasTarefas as $t) {
    $tarefasPorData[$t['data']][] = $t;
}
$tarefasDoDiaFoco = $tarefasPorData[$dataFocoParam] ?? [];

// Formato leve pro calendário visual reutilizado (mesmo componente dos
// treinamentos) -- setor_id aqui é o cliente_id mesmo, só reaproveitando o
// mesmo campo que o widget já sabe colorir (ver cor_cliente()/cor_setor()
// serem a MESMA paleta/regra).
$eventosCalendarioAgenda = array_map(fn ($t) => [
    'id' => (int) $t['id'],
    'titulo' => $t['titulo'],
    'tipo' => null,
    'descricao' => $t['notas'],
    'data_inicio' => $t['data'],
    'hora_inicio' => null,
    'data_fim' => null,
    'hora_fim' => null,
    'link_reuniao' => null,
    'status' => $t['status'] === 'concluida' ? 'cancelado' : $t['status'],
    'setor_id' => $t['cliente_id'] ? (int) $t['cliente_id'] : null,
    'cliente_id' => $t['cliente_id'] ? (int) $t['cliente_id'] : null,
    'chamado_id' => $t['chamado_id'] ? (int) $t['chamado_id'] : null,
    'notas' => $t['notas'],
    'status_real' => $t['status'],
], $todasTarefas);

function icone_status_tarefa(string $status): string
{
    return match ($status) {
        'concluida' => 'bi-check-circle-fill',
        'em_andamento' => 'bi-arrow-repeat',
        default => 'bi-circle',
    };
}

$tituloPagina = 'Agenda';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-1"><i class="bi bi-calendar3"></i> Agenda</h4>
<p class="text-muted small mb-3">Organize o que precisa atender em cada dia -- compartilhado entre a gestão. Arraste os cards do painel "Prioridades do dia" pra reordenar.</p>

<div class="calendario-mensal mb-4" data-eventos='<?= e(json_encode($eventosCalendarioAgenda, JSON_UNESCAPED_UNICODE)) ?>' data-aba-retorno="agenda"></div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="d-flex align-items-center gap-2">
        <h5 class="mb-0" id="tituloPrioridadesDia">Prioridades do dia</h5>
        <input type="date" class="form-control form-control-sm" id="seletorDataFoco" value="<?= e($dataFocoParam) ?>" style="width:auto;">
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-dark" id="botaoSugestaoIa" <?= AiSugestoes::configurada() ? '' : 'disabled title="IA ainda não configurada (ver Configurações)"' ?>>
            <i class="bi bi-stars"></i> Sugestão da IA
        </button>
        <button type="button" class="btn btn-sm btn-dark" id="botaoNovaTarefa"><i class="bi bi-plus-lg"></i> Nova tarefa</button>
    </div>
</div>

<!-- Antes de gerar, pergunta cliente/período/se é pra só sugerir em texto ou
     já criar as tarefas na agenda -- pedido do Kleber pra não ter que ficar
     copiando a sugestão manualmente dia a dia. -->
<div class="modal fade" id="modalSugestaoIa" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-stars"></i> Sugestão da IA</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Cliente</label>
          <select class="form-select" id="sugestaoIaCliente">
            <option value="">Todos os clientes</option>
            <?php foreach ($clientes as $c): ?>
            <option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Período</label>
          <select class="form-select" id="sugestaoIaPeriodo">
            <option value="dia">Hoje</option>
            <option value="semana">Essa semana (dias úteis)</option>
            <option value="mes">Esse mês (dias úteis)</option>
          </select>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="sugestaoIaCriarTarefas">
          <label class="form-check-label" for="sugestaoIaCriarTarefas">
            Criar essas tarefas direto na agenda (em vez de só sugerir em texto)
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-dark" id="botaoGerarSugestaoIa"><i class="bi bi-stars"></i> Gerar</button>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-light border d-none" id="painelSugestaoIa">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div><i class="bi bi-stars"></i> <strong>Sugestão da IA</strong></div>
        <button type="button" class="btn-close" id="fecharSugestaoIa"></button>
    </div>
    <div style="white-space: pre-wrap;" id="textoSugestaoIa" class="mt-2 small"></div>
</div>

<div class="agenda-coluna-dia" id="colunaDiaFoco" data-date="<?= e($dataFocoParam) ?>">
    <?php if (!$tarefasDoDiaFoco): ?>
        <p class="text-muted text-center py-4 mb-0" id="avisoDiaVazio"><i class="bi bi-emoji-smile" style="font-size:1.5rem;"></i><br>Nada planejado pra esse dia ainda.</p>
    <?php endif; ?>
    <?php foreach ($tarefasDoDiaFoco as $t): ?>
    <div class="agenda-card" draggable="true" data-id="<?= $t['id'] ?>"
        data-titulo="<?= e($t['titulo']) ?>" data-cliente-id="<?= $t['cliente_id'] ?: '' ?>" data-chamado-id="<?= $t['chamado_id'] ?: '' ?>"
        data-notas="<?= e($t['notas'] ?? '') ?>" data-status="<?= e($t['status']) ?>"
        style="border-left-color: <?= $t['cliente_id'] ? e(cor_cliente((int) $t['cliente_id'])) : 'var(--ph-border)' ?>;">
        <div class="agenda-card-topo">
            <button type="button" class="agenda-card-status" data-id="<?= $t['id'] ?>" data-status="<?= e($t['status']) ?>" title="Marcar status">
                <i class="bi <?= icone_status_tarefa($t['status']) ?>"></i>
            </button>
            <div class="flex-grow-1" style="min-width:0;">
                <div class="agenda-card-titulo <?= $t['status'] === 'concluida' ? 'text-decoration-line-through text-muted' : '' ?>"><?= e($t['titulo']) ?></div>
                <?php if ($t['nome_cliente']): ?><span class="badge" style="background-color: <?= e(cor_cliente((int) $t['cliente_id'])) ?>;"><?= e($t['nome_cliente']) ?></span><?php endif; ?>
                <?php if ($t['titulo_chamado']): ?><span class="badge bg-secondary"><i class="bi bi-life-preserver"></i> #<?= $t['chamado_id'] ?></span><?php endif; ?>
            </div>
            <i class="bi bi-grip-vertical agenda-card-alca" title="Arrastar"></i>
        </div>
        <?php if ($t['notas']): ?><p class="agenda-card-notas"><?= e($t['notas']) ?></p><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Nova/editar tarefa -- UM modal só, reaproveitado pros dois casos. -->
<div class="modal fade" id="modalTarefaAgenda" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="formTarefaAgenda">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" id="tarefaAgendaAcao" value="criar_tarefa">
        <input type="hidden" name="tarefa_id" id="tarefaAgendaId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="tarefaAgendaModalTitulo">Nova tarefa</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Título *</label>
              <input type="text" name="titulo" id="tarefaAgendaTitulo" class="form-control" placeholder="Ex.: Ligar pra CIPLA sobre o chamado de faturamento" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Data *</label>
              <input type="date" name="data" id="tarefaAgendaData" class="form-control" required>
            </div>
            <div class="col-md-6" id="blocoStatusTarefaAgenda" style="display:none;">
              <label class="form-label">Status</label>
              <select name="status" id="tarefaAgendaStatus" class="form-select">
                <option value="pendente">Pendente</option>
                <option value="em_andamento">Em andamento</option>
                <option value="concluida">Concluída</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Cliente</label>
              <select name="cliente_id" id="tarefaAgendaCliente" class="form-select">
                <option value="">Nenhum (tarefa livre)</option>
                <?php foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Chamado (opcional)</label>
              <select name="chamado_id" id="tarefaAgendaChamado" class="form-select">
                <option value="">Nenhum</option>
                <?php foreach ($chamadosAbertos as $ch): ?>
                <option value="<?= $ch['id'] ?>" data-cliente-id="<?= $ch['cliente_id'] ?>">#<?= $ch['id'] ?> -- <?= e($ch['titulo']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Anotações</label>
              <textarea name="notas" id="tarefaAgendaNotas" class="form-control" rows="4" placeholder="O que precisa levar em conta, próximos passos, o que já foi combinado..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-outline-danger d-none" id="botaoExcluirTarefaAgenda"><i class="bi bi-trash"></i> Excluir</button>
          <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> Salvar</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<form method="post" id="formExcluirTarefaAgenda" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="excluir_tarefa">
    <input type="hidden" name="tarefa_id" id="excluirTarefaAgendaId" value="">
    <input type="hidden" name="data" id="excluirTarefaAgendaData" value="">
</form>

<script src="<?= asset_url('assets/js/calendario_visual.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var CHAMADOS_POR_CLIENTE = <?= json_encode(array_map(fn ($ch) => ['id' => (int) $ch['id'], 'cliente_id' => (int) $ch['cliente_id']], $chamadosAbertos)) ?>;

    function filtrarChamadosPorCliente(clienteId) {
        var selectChamado = document.getElementById('tarefaAgendaChamado');
        var valorAtual = selectChamado.value;
        Array.from(selectChamado.options).forEach(function (opt) {
            if (!opt.value) return; // "Nenhum" sempre visível
            var chamadoClienteId = opt.getAttribute('data-cliente-id');
            opt.hidden = !!clienteId && chamadoClienteId !== String(clienteId);
        });
        // Se o chamado selecionado não pertence mais ao cliente escolhido, limpa.
        var opcaoAtual = selectChamado.querySelector('option[value="' + valorAtual + '"]');
        if (valorAtual && opcaoAtual && opcaoAtual.hidden) {
            selectChamado.value = '';
        }
    }

    document.getElementById('tarefaAgendaCliente').addEventListener('change', function () {
        filtrarChamadosPorCliente(this.value);
    });

    // --- Modal: nova tarefa -------------------------------------------
    function abrirNovaTarefa(dataIso) {
        document.getElementById('tarefaAgendaModalTitulo').textContent = 'Nova tarefa';
        document.getElementById('tarefaAgendaAcao').value = 'criar_tarefa';
        document.getElementById('tarefaAgendaId').value = '';
        document.getElementById('tarefaAgendaTitulo').value = '';
        document.getElementById('tarefaAgendaData').value = dataIso || document.getElementById('seletorDataFoco').value;
        document.getElementById('tarefaAgendaCliente').value = '';
        document.getElementById('tarefaAgendaChamado').value = '';
        document.getElementById('tarefaAgendaNotas').value = '';
        document.getElementById('blocoStatusTarefaAgenda').style.display = 'none';
        document.getElementById('botaoExcluirTarefaAgenda').classList.add('d-none');
        filtrarChamadosPorCliente('');
        PrimeUI.openModal(document.getElementById('modalTarefaAgenda'));
    }

    document.getElementById('botaoNovaTarefa').addEventListener('click', function () { abrirNovaTarefa(null); });

    // --- Modal: editar tarefa (a partir do card do painel do dia) -----
    function abrirEditarTarefa(dados) {
        document.getElementById('tarefaAgendaModalTitulo').textContent = 'Editar tarefa';
        document.getElementById('tarefaAgendaAcao').value = 'atualizar_tarefa';
        document.getElementById('tarefaAgendaId').value = dados.id;
        document.getElementById('tarefaAgendaTitulo').value = dados.titulo || '';
        document.getElementById('tarefaAgendaData').value = dados.data || document.getElementById('seletorDataFoco').value;
        document.getElementById('tarefaAgendaCliente').value = dados.clienteId || '';
        filtrarChamadosPorCliente(dados.clienteId || '');
        document.getElementById('tarefaAgendaChamado').value = dados.chamadoId || '';
        document.getElementById('tarefaAgendaNotas').value = dados.notas || '';
        document.getElementById('tarefaAgendaStatus').value = dados.status || 'pendente';
        document.getElementById('blocoStatusTarefaAgenda').style.display = '';
        document.getElementById('botaoExcluirTarefaAgenda').classList.remove('d-none');
        document.getElementById('excluirTarefaAgendaId').value = dados.id;
        document.getElementById('excluirTarefaAgendaData').value = dados.data || '';
        PrimeUI.openModal(document.getElementById('modalTarefaAgenda'));
    }

    document.getElementById('botaoExcluirTarefaAgenda').addEventListener('click', function () {
        if (!confirm('Excluir essa tarefa da agenda?')) return;
        document.getElementById('formExcluirTarefaAgenda').submit();
    });

    // --- Calendário mensal/semanal/diário reaproveitado ----------------
    document.querySelectorAll('.calendario-mensal').forEach(function (container) {
        inicializarCalendarioMensal(container, {
            aoAbrirNovo: function (cont, dataIso) { abrirNovaTarefa(dataIso); },
            aoAbrirEvento: function (evento) {
                abrirEditarTarefa({
                    id: evento.id,
                    titulo: evento.titulo,
                    data: evento.data_inicio,
                    clienteId: evento.cliente_id,
                    chamadoId: evento.chamado_id,
                    notas: evento.notas,
                    status: evento.status_real,
                });
            },
        });
    });

    // --- Painel "Prioridades do dia": troca de data ---------------------
    document.getElementById('seletorDataFoco').addEventListener('change', function () {
        window.location.href = '<?= url('agenda.php') ?>?data=' + encodeURIComponent(this.value);
    });

    // --- Clique no card abre edição (exceto no botão de status/alça) ----
    var colunaDia = document.getElementById('colunaDiaFoco');
    colunaDia.addEventListener('click', function (e) {
        if (e.target.closest('.agenda-card-status')) return;
        var card = e.target.closest('.agenda-card');
        if (!card) return;
        abrirEditarTarefa({
            id: card.getAttribute('data-id'),
            titulo: card.getAttribute('data-titulo'),
            data: colunaDia.getAttribute('data-date'),
            clienteId: card.getAttribute('data-cliente-id'),
            chamadoId: card.getAttribute('data-chamado-id'),
            notas: card.getAttribute('data-notas'),
            status: card.getAttribute('data-status'),
        });
    });

    // Ciclo rápido de status direto no card, sem abrir o modal: pendente ->
    // em andamento -> concluída -> pendente de novo.
    var PROXIMO_STATUS = { pendente: 'em_andamento', em_andamento: 'concluida', concluida: 'pendente' };
    var ICONE_STATUS = { pendente: 'bi-circle', em_andamento: 'bi-arrow-repeat', concluida: 'bi-check-circle-fill' };
    colunaDia.addEventListener('click', function (e) {
        var botao = e.target.closest('.agenda-card-status');
        if (!botao) return;
        e.stopPropagation();
        var novoStatus = PROXIMO_STATUS[botao.getAttribute('data-status')] || 'pendente';
        var card = botao.closest('.agenda-card');
        var titulo = card.querySelector('.agenda-card-titulo');

        botao.setAttribute('data-status', novoStatus);
        card.setAttribute('data-status', novoStatus);
        botao.querySelector('i').className = 'bi ' + ICONE_STATUS[novoStatus];
        titulo.classList.toggle('text-decoration-line-through', novoStatus === 'concluida');
        titulo.classList.toggle('text-muted', novoStatus === 'concluida');

        var dados = new FormData();
        dados.set('acao', 'mudar_status_tarefa');
        dados.set('ajax', '1');
        dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
        dados.set('tarefa_id', botao.getAttribute('data-id'));
        dados.set('status', novoStatus);
        fetch(window.location.href, { method: 'POST', body: dados }).catch(function () {});
    });

    // --- Arrastar-e-soltar pra reordenar (mesmo padrão HTML5 nativo do
    // dashboard.php: solta antes/depois do card mais próximo do cursor,
    // dependendo de qual metade dele o mouse está passando por cima). -----
    var arrastando = null;
    colunaDia.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.agenda-card');
        if (!card) return;
        arrastando = card;
        card.classList.add('agenda-card-arrastando');
        e.dataTransfer.effectAllowed = 'move';
    });
    colunaDia.addEventListener('dragover', function (e) {
        if (!arrastando) return;
        e.preventDefault();
        var alvo = e.target.closest('.agenda-card');
        if (!alvo || alvo === arrastando) return;
        var retangulo = alvo.getBoundingClientRect();
        var depois = e.clientY > retangulo.top + retangulo.height / 2;
        colunaDia.insertBefore(arrastando, depois ? alvo.nextSibling : alvo);
    });
    colunaDia.addEventListener('dragend', function () {
        if (!arrastando) return;
        arrastando.classList.remove('agenda-card-arrastando');
        arrastando = null;

        var mudancas = Array.from(colunaDia.querySelectorAll('.agenda-card')).map(function (card, indice) {
            return { id: parseInt(card.getAttribute('data-id'), 10), data: colunaDia.getAttribute('data-date'), ordem: indice };
        });
        var dados = new FormData();
        dados.set('acao', 'reordenar_tarefas');
        dados.set('ajax', '1');
        dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
        dados.set('mudancas', JSON.stringify(mudancas));
        fetch(window.location.href, { method: 'POST', body: dados }).catch(function () {});
    });

    // --- Sugestão da IA: primeiro pergunta cliente/período/se cria tarefa
    // de verdade, só depois gera -- pedido do Kleber pra não ficar copiando
    // a sugestão manualmente todo dia.
    var botaoSugestaoIa = document.getElementById('botaoSugestaoIa');
    var modalSugestaoIa = document.getElementById('modalSugestaoIa');
    var botaoGerarSugestaoIa = document.getElementById('botaoGerarSugestaoIa');

    botaoSugestaoIa.addEventListener('click', function () {
        PrimeUI.openModal(modalSugestaoIa);
    });

    botaoGerarSugestaoIa.addEventListener('click', function () {
        var painel = document.getElementById('painelSugestaoIa');
        var textoEl = document.getElementById('textoSugestaoIa');
        var criarTarefas = document.getElementById('sugestaoIaCriarTarefas').checked;
        var periodoEscolhido = document.getElementById('sugestaoIaPeriodo').value;

        PrimeUI.closeModal(modalSugestaoIa);
        painel.classList.remove('d-none');
        textoEl.textContent = 'Gerando sugestão...';
        botaoGerarSugestaoIa.disabled = true;

        var dados = new FormData();
        dados.set('acao', 'gerar_sugestao_ia');
        dados.set('ajax', '1');
        dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
        dados.set('data', document.getElementById('seletorDataFoco').value);
        dados.set('cliente_id', document.getElementById('sugestaoIaCliente').value);
        dados.set('periodo', periodoEscolhido);
        dados.set('criar_tarefas', criarTarefas ? '1' : '0');

        fetch(window.location.href, { method: 'POST', body: dados })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.sucesso) {
                    textoEl.textContent = 'Não foi possível gerar: ' + json.mensagem;
                    return;
                }
                if (json.tipo === 'tarefas') {
                    var partes = [];
                    if (json.resumo) partes.push(json.resumo);
                    partes.push(json.totalCriadas + ' tarefa(s) criada(s) na agenda. Atualizando a página...');
                    textoEl.textContent = partes.join('\n\n');
                    setTimeout(function () {
                        window.location.href = '<?= url('agenda.php') ?>?data=' + encodeURIComponent(json.dataFoco || document.getElementById('seletorDataFoco').value);
                    }, 2200);
                } else {
                    textoEl.textContent = json.sugestao;
                }
            })
            .catch(function () { textoEl.textContent = 'Falha de conexão ao gerar a sugestão.'; })
            .finally(function () { botaoGerarSugestaoIa.disabled = false; });
    });
    document.getElementById('fecharSugestaoIa').addEventListener('click', function () {
        document.getElementById('painelSugestaoIa').classList.add('d-none');
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
