<?php

/**
 * Agenda de trabalho da gestão -- pedido do Kleber: ele tem todos os
 * chamados cadastrados mas fica perdido em qual cliente atender primeiro.
 * Virou um gráfico de Gantt de verdade: cada tarefa tem horário de início e
 * duração, organizada dentro do expediente (cada gestor define o próprio
 * horário de trabalho e almoço -- ver "Meu horário"). Ele (e o Petronilho,
 * mesmo quadro compartilhado -- não é agenda pessoal de cada um) monta a
 * semana arrastando os cards entre dias E entre horários. Um calendário
 * mensal (reaproveitando o componente já usado pros treinamentos, ver
 * assets/js/calendario_visual.js) dá a visão geral pra navegar/criar/editar
 * rápido; o Gantt da semana logo abaixo é onde acontece a organização de
 * verdade. Só admin/diretor/CEO usam isso por enquanto ("colaborador não
 * precisa").
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\AiSugestoes;
use AdminPrime\Database;

const MESES_ABREV_PT_AGENDA = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const DIAS_SEMANA_ABREV_PT_AGENDA = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const AGENDA_DURACAO_PADRAO_MINUTOS = 60;
const AGENDA_DURACOES_OPCOES = [30 => '0h30', 45 => '0h45', 60 => '1h', 90 => '1h30', 120 => '2h', 180 => '3h', 240 => '4h'];

/**
 * Horário de trabalho/almoço do usuário logado (NULL = ainda não configurou,
 * usa o padrão 08:00-18:00 / almoço 12:00-13:00). Usado tanto pro Gantt
 * quanto pro prompt da IA -- sempre "HH:MM", nunca "HH:MM:SS".
 */
function agenda_horario_trabalho(PDO $pdo, int $usuarioId): array
{
    $stmt = $pdo->prepare('SELECT agenda_expediente_inicio, agenda_expediente_fim, agenda_almoco_inicio, agenda_almoco_fim FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $linha = $stmt->fetch() ?: [];

    return [
        'expediente_inicio' => substr($linha['agenda_expediente_inicio'] ?? '08:00:00', 0, 5),
        'expediente_fim' => substr($linha['agenda_expediente_fim'] ?? '18:00:00', 0, 5),
        'almoco_inicio' => substr($linha['agenda_almoco_inicio'] ?? '12:00:00', 0, 5),
        'almoco_fim' => substr($linha['agenda_almoco_fim'] ?? '13:00:00', 0, 5),
    ];
}

Auth::requireAdmin();

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';
    $ehAjax = ($_POST['ajax'] ?? '') === '1';

    if ($acao === 'criar_tarefa') {
        $titulo = trim($_POST['titulo'] ?? '');
        $data = $_POST['data'] ?? '';
        $horaInicio = $_POST['hora_inicio'] ?? '';
        $horaInicio = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaInicio) ? $horaInicio : null;
        $duracaoMinutos = (int) ($_POST['duracao_minutos'] ?? AGENDA_DURACAO_PADRAO_MINUTOS);
        if (!isset(AGENDA_DURACOES_OPCOES[$duracaoMinutos])) {
            $duracaoMinutos = AGENDA_DURACAO_PADRAO_MINUTOS;
        }
        $clienteId = (int) ($_POST['cliente_id'] ?? 0) ?: null;
        $chamadoId = (int) ($_POST['chamado_id'] ?? 0) ?: null;
        $notas = trim($_POST['notas'] ?? '') ?: null;

        if ($titulo === '' || !$data || !strtotime($data)) {
            flash_set('erro', 'Informe um título e uma data válida.');
        } else {
            $stmtOrdemMax = $pdo->prepare('SELECT COALESCE(MAX(ordem), -1) FROM agenda_tarefas WHERE data = ?');
            $stmtOrdemMax->execute([$data]);
            $ordemMax = (int) $stmtOrdemMax->fetchColumn();

            $pdo->prepare('INSERT INTO agenda_tarefas (data, hora_inicio, duracao_minutos, ordem, titulo, cliente_id, chamado_id, notas, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$data, $horaInicio, $duracaoMinutos, $ordemMax + 1, $titulo, $clienteId, $chamadoId, $notas, Auth::userId()]);
            flash_set('sucesso', 'Tarefa adicionada na agenda.');
        }
    }

    if ($acao === 'atualizar_tarefa') {
        $tarefaId = (int) ($_POST['tarefa_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $data = $_POST['data'] ?? '';
        $horaInicio = $_POST['hora_inicio'] ?? '';
        $horaInicio = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaInicio) ? $horaInicio : null;
        $duracaoMinutos = (int) ($_POST['duracao_minutos'] ?? AGENDA_DURACAO_PADRAO_MINUTOS);
        if (!isset(AGENDA_DURACOES_OPCOES[$duracaoMinutos])) {
            $duracaoMinutos = AGENDA_DURACAO_PADRAO_MINUTOS;
        }
        $clienteId = (int) ($_POST['cliente_id'] ?? 0) ?: null;
        $chamadoId = (int) ($_POST['chamado_id'] ?? 0) ?: null;
        $notas = trim($_POST['notas'] ?? '') ?: null;
        $status = in_array($_POST['status'] ?? '', ['pendente', 'em_andamento', 'concluida'], true) ? $_POST['status'] : 'pendente';

        if ($titulo === '' || !$data || !strtotime($data)) {
            flash_set('erro', 'Informe um título e uma data válida.');
        } else {
            $pdo->prepare('UPDATE agenda_tarefas SET titulo=?, data=?, hora_inicio=?, duracao_minutos=?, cliente_id=?, chamado_id=?, notas=?, status=? WHERE id=?')
                ->execute([$titulo, $data, $horaInicio, $duracaoMinutos, $clienteId, $chamadoId, $notas, $status, $tarefaId]);
            flash_set('sucesso', 'Tarefa atualizada.');
        }
    }

    if ($acao === 'excluir_tarefa') {
        $pdo->prepare('DELETE FROM agenda_tarefas WHERE id = ?')->execute([(int) ($_POST['tarefa_id'] ?? 0)]);
        flash_set('sucesso', 'Tarefa removida.');
    }

    // Só as ações abaixo são AJAX de verdade (chamadas via fetch, sem navegar
    // a página) -- as de cima seguem o padrão comum do resto do sistema
    // (POST + redirect).
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
            $stmtReordenar = $pdo->prepare('UPDATE agenda_tarefas SET data = ?, hora_inicio = ?, ordem = ? WHERE id = ?');
            foreach ($mudancas as $m) {
                if (!isset($m['id'], $m['data'], $m['ordem'])) {
                    continue;
                }
                $horaMudanca = $m['hora_inicio'] ?? null;
                $horaMudanca = (is_string($horaMudanca) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaMudanca)) ? $horaMudanca : null;
                $stmtReordenar->execute([$m['data'], $horaMudanca, (int) $m['ordem'], (int) $m['id']]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['sucesso' => true]);
        exit;
    }

    if ($acao === 'salvar_horario_trabalho' && $ehAjax) {
        $camposHorario = ['expediente_inicio' => null, 'expediente_fim' => null, 'almoco_inicio' => null, 'almoco_fim' => null];
        foreach (array_keys($camposHorario) as $campo) {
            $valor = $_POST[$campo] ?? '';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor)) {
                header('Content-Type: application/json');
                echo json_encode(['sucesso' => false, 'mensagem' => 'Horário inválido.']);
                exit;
            }
            $camposHorario[$campo] = $valor;
        }
        if ($camposHorario['expediente_inicio'] >= $camposHorario['expediente_fim']) {
            header('Content-Type: application/json');
            echo json_encode(['sucesso' => false, 'mensagem' => 'O expediente precisa terminar depois de começar.']);
            exit;
        }
        if ($camposHorario['almoco_inicio'] >= $camposHorario['almoco_fim']) {
            header('Content-Type: application/json');
            echo json_encode(['sucesso' => false, 'mensagem' => 'O almoço precisa terminar depois de começar.']);
            exit;
        }

        $pdo->prepare('UPDATE usuarios SET agenda_expediente_inicio=?, agenda_expediente_fim=?, agenda_almoco_inicio=?, agenda_almoco_fim=? WHERE id=?')
            ->execute([$camposHorario['expediente_inicio'], $camposHorario['expediente_fim'], $camposHorario['almoco_inicio'], $camposHorario['almoco_fim'], Auth::userId()]);
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
        $clienteIdsSugestao = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['cliente_ids'] ?? ''))))));
        $criarTarefasSugestao = ($_POST['criar_tarefas'] ?? '') === '1';

        $horarioTrabalhoSugestao = agenda_horario_trabalho($pdo, Auth::userId());
        $minExpedienteInicioSugestao = minutos_do_dia($horarioTrabalhoSugestao['expediente_inicio']);
        $minExpedienteFimSugestao = minutos_do_dia($horarioTrabalhoSugestao['expediente_fim']);
        $minAlmocoInicioSugestao = minutos_do_dia($horarioTrabalhoSugestao['almoco_inicio']);
        $minAlmocoFimSugestao = minutos_do_dia($horarioTrabalhoSugestao['almoco_fim']);

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

        $placeholdersClientesSugestao = $clienteIdsSugestao ? implode(',', array_fill(0, count($clienteIdsSugestao), '?')) : '';

        $stmtTarefasPeriodoSugestao = $pdo->prepare("
            SELECT t.data, t.hora_inicio, t.titulo, t.status, t.notas, c.nome AS nome_cliente
            FROM agenda_tarefas t
            LEFT JOIN clientes c ON c.id = t.cliente_id
            WHERE t.data BETWEEN ? AND ?" . ($clienteIdsSugestao ? " AND t.cliente_id IN ($placeholdersClientesSugestao)" : '') . "
            ORDER BY t.data ASC, t.ordem ASC
        ");
        $stmtTarefasPeriodoSugestao->execute(array_merge([$dataInicioSugestao, $dataFimSugestao], $clienteIdsSugestao));
        $tarefasDoPeriodoSugestao = $stmtTarefasPeriodoSugestao->fetchAll();

        $stmtChamadosSugestao = $pdo->prepare("
            SELECT ch.id, ch.titulo, ch.prioridade, ch.status, ch.criado_em, ch.cliente_id, c.nome AS nome_cliente
            FROM chamados ch
            INNER JOIN clientes c ON c.id = ch.cliente_id
            WHERE ch.status != 'fechado'" . ($clienteIdsSugestao ? " AND ch.cliente_id IN ($placeholdersClientesSugestao)" : '') . "
            ORDER BY FIELD(ch.prioridade, 'urgente', 'alta', 'media', 'baixa'), ch.criado_em ASC
            LIMIT 80
        ");
        $stmtChamadosSugestao->execute($clienteIdsSugestao);
        $chamadosAbertosSugestao = $stmtChamadosSugestao->fetchAll();

        $rotuloPeriodoSugestao = [
            'dia' => 'hoje (' . formatar_data($dataAncoraSugestao) . ')',
            'semana' => 'essa semana (' . formatar_data($dataInicioSugestao) . ' a ' . formatar_data($dataFimSugestao) . ', dias úteis)',
            'mes' => 'esse mês (' . formatar_data($dataInicioSugestao) . ' a ' . formatar_data($dataFimSugestao) . ', dias úteis)',
        ][$periodoSugestao];
        $rotuloClienteSugestao = $clienteIdsSugestao
            ? implode(', ', array_map(fn ($id) => $nomesClientesPorIdSugestao[$id] ?? "cliente #$id", $clienteIdsSugestao))
            : 'todos os clientes';

        $blocoContexto = "Você é um assistente de organização pra um gestor de uma empresa de suporte/consultoria em ERP (Protheus). "
            . "Ele tem chamados de suporte abertos de vários clientes e precisa priorizar o que atacar em {$rotuloPeriodoSugestao}, olhando só pro cliente/clientes: {$rotuloClienteSugestao}. "
            . "Expediente dele: {$horarioTrabalhoSugestao['expediente_inicio']} às {$horarioTrabalhoSugestao['expediente_fim']}, com almoço de {$horarioTrabalhoSugestao['almoco_inicio']} às {$horarioTrabalhoSugestao['almoco_fim']} (não colocar nada nesse intervalo). "
            . 'Cada chamado leva em média ' . AGENDA_DURACAO_PADRAO_MINUTOS . " minutos pra atender, salvo se o próprio chamado indicar que precisa de mais ou menos tempo.\n\n"
            . "Tarefas já planejadas no período:\n";
        if (!$tarefasDoPeriodoSugestao) {
            $blocoContexto .= "(nenhuma ainda)\n";
        }
        foreach ($tarefasDoPeriodoSugestao as $t) {
            $horaTxtContexto = $t['hora_inicio'] ? substr($t['hora_inicio'], 0, 5) . ' ' : '';
            $blocoContexto .= '- ' . formatar_data($t['data']) . ' ' . $horaTxtContexto . ': ' . $t['titulo'] . ($t['nome_cliente'] ? ' (' . $t['nome_cliente'] . ')' : '') . ' [' . $t['status'] . "]\n";
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
            . "\nDatas permitidas pra colocar tarefa (dias úteis do período): " . implode(', ', $datasUteisSugestao) . "\n"
            . "Horário permitido em cada dia: {$horarioTrabalhoSugestao['expediente_inicio']} às {$horarioTrabalhoSugestao['expediente_fim']}, PULANDO o almoço ({$horarioTrabalhoSugestao['almoco_inicio']}-{$horarioTrabalhoSugestao['almoco_fim']}). "
            . "Duração de cada tarefa em minutos: use um destes valores -- 30, 45, 60, 90, 120, 180 ou 240 -- normalmente 60, salvo se o chamado precisar claramente de mais ou menos tempo.\n\n"
            . "Monte um plano de tarefas pra esse período, priorizando os chamados mais urgentes/antigos, distribuindo entre os dias úteis e horários de forma equilibrada e SEM SOBREPOR horários no mesmo dia "
            . "(não empilhe tudo num dia só se o período for semana/mês). Pode juntar mais de um chamado do mesmo cliente numa tarefa só se fizer sentido, ou criar uma tarefa por chamado -- use seu critério. "
            . "Não recrie tarefas que já existem no período (listadas acima).\n\n"
            . "Responda APENAS com um objeto JSON válido (sem markdown, sem texto antes ou depois, sem blocos de código), exatamente neste formato:\n"
            . '{"resumo": "3 a 5 frases em português explicando a lógica geral da priorização", '
            . '"tarefas": [{"data": "AAAA-MM-DD (uma das datas permitidas acima)", "hora_inicio": "HH:MM dentro do expediente permitido, fora do almoço", "duracao_minutos": (30|45|60|90|120|180|240), "titulo": "ação curta e clara", '
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

        $idsClientesValidos = $clienteIdsSugestao ?: array_map('intval', array_column($clientesSugestao, 'id'));
        $idsChamadosValidos = array_map('intval', array_column($chamadosAbertosSugestao, 'id'));
        $clientePorChamado = array_column($chamadosAbertosSugestao, 'cliente_id', 'id');
        $datasPermitidasSet = array_flip($datasUteisSugestao);
        $duracoesValidas = array_keys(AGENDA_DURACOES_OPCOES);

        $ordemMaxPorData = [];
        $tarefasCriadasSugestao = [];
        $stmtInserirSugestao = $pdo->prepare('INSERT INTO agenda_tarefas (data, hora_inicio, duracao_minutos, ordem, titulo, cliente_id, chamado_id, notas, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($dadosPlano['tarefas'] as $item) {
            $dataItem = (string) ($item['data'] ?? '');
            if (!isset($datasPermitidasSet[$dataItem])) {
                continue;
            }
            $tituloItem = mb_substr(trim((string) ($item['titulo'] ?? '')), 0, 200);
            if ($tituloItem === '') {
                continue;
            }

            $duracaoItem = (int) ($item['duracao_minutos'] ?? AGENDA_DURACAO_PADRAO_MINUTOS);
            if (!in_array($duracaoItem, $duracoesValidas, true)) {
                $duracaoItem = AGENDA_DURACAO_PADRAO_MINUTOS;
            }

            // Se a IA mandar um horário inválido/fora do expediente/em cima
            // do almoço, a tarefa NÃO é descartada -- só vira "sem horário
            // definido" (hora_inicio null), pra ele reposicionar manualmente
            // no Gantt em vez de perder a sugestão inteira.
            $horaItem = (string) ($item['hora_inicio'] ?? '');
            $horaValidaItem = null;
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $horaItem)) {
                $minItem = minutos_do_dia($horaItem);
                $dentroDoExpediente = $minItem >= $minExpedienteInicioSugestao && ($minItem + $duracaoItem) <= $minExpedienteFimSugestao;
                $emCimaDoAlmoco = $minItem < $minAlmocoFimSugestao && ($minItem + $duracaoItem) > $minAlmocoInicioSugestao;
                if ($dentroDoExpediente && !$emCimaDoAlmoco) {
                    $horaValidaItem = $horaItem;
                }
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

            $stmtInserirSugestao->execute([$dataItem, $horaValidaItem, $duracaoItem, $ordemMaxPorData[$dataItem], $tituloItem, $clienteIdItem, $chamadoIdItem, $notasItem, Auth::userId()]);
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

$horarioTrabalho = agenda_horario_trabalho($pdo, Auth::userId());
$minExpedienteInicio = minutos_do_dia($horarioTrabalho['expediente_inicio']);
$minExpedienteFim = minutos_do_dia($horarioTrabalho['expediente_fim']);
$minAlmocoInicio = minutos_do_dia($horarioTrabalho['almoco_inicio']);
$minAlmocoFim = minutos_do_dia($horarioTrabalho['almoco_fim']);
// 1px = 1 minuto -- altura de cada card/da grade já sai proporcional direto
// do PHP, sem precisar reescalar nada em JS.
$alturaGanttPx = max(60, $minExpedienteFim - $minExpedienteInicio);
$alturaCabecalhoColunaPx = 44;
$alturaZonaNaoAgendadaPx = 34;

// Semana (segunda a domingo) que contém $dataFocoParam -- o Gantt sempre
// mostra a semana inteira (não só o dia), pra dar pra arrastar uma tarefa
// pra outro dia quando o Kleber precisa reorganizar por causa de emergência.
$tsSemanaFoco = strtotime($dataFocoParam);
$diaSemanaFocoIndice = (int) date('N', $tsSemanaFoco); // 1=segunda..7=domingo
$segundaSemanaFoco = date('Y-m-d', strtotime('-' . ($diaSemanaFocoIndice - 1) . ' days', $tsSemanaFoco));
$diasDaSemanaFoco = [];
for ($i = 0; $i < 7; $i++) {
    $diasDaSemanaFoco[] = date('Y-m-d', strtotime("+{$i} days", strtotime($segundaSemanaFoco)));
}
$semanaAnteriorFoco = date('Y-m-d', strtotime('-7 days', strtotime($segundaSemanaFoco)));
$semanaProximaFoco = date('Y-m-d', strtotime('+7 days', strtotime($segundaSemanaFoco)));

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
    ORDER BY t.data ASC, t.hora_inicio ASC, t.ordem ASC
")->fetchAll();

$tarefasPorData = [];
foreach ($todasTarefas as $t) {
    $tarefasPorData[$t['data']][] = $t;
}

// Formato leve pro calendário visual reutilizado (mesmo componente dos
// treinamentos) -- setor_id aqui é o cliente_id mesmo, só reaproveitando o
// mesmo campo que o widget já sabe colorir (ver cor_cliente()/cor_setor()
// serem a MESMA paleta/regra). hora_inicio real é passado pro widget saber
// ordenar/mostrar o horário nos chips do mês também.
$eventosCalendarioAgenda = array_map(fn ($t) => [
    'id' => (int) $t['id'],
    'titulo' => $t['titulo'],
    'tipo' => null,
    'descricao' => $t['notas'],
    'data_inicio' => $t['data'],
    'hora_inicio' => $t['hora_inicio'] ? substr($t['hora_inicio'], 0, 5) : null,
    'data_fim' => null,
    'hora_fim' => null,
    'link_reuniao' => null,
    'status' => $t['status'] === 'concluida' ? 'cancelado' : $t['status'],
    'setor_id' => $t['cliente_id'] ? (int) $t['cliente_id'] : null,
    'cliente_id' => $t['cliente_id'] ? (int) $t['cliente_id'] : null,
    'chamado_id' => $t['chamado_id'] ? (int) $t['chamado_id'] : null,
    'duracao_minutos' => (int) $t['duracao_minutos'],
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

/**
 * Renderiza UM card de tarefa -- reaproveitado tanto pra faixa "sem
 * horário" (lista normal, $posicao null) quanto pra grade do Gantt (posição
 * absoluta calculada em pixels a partir do horário/duração, $posicao
 * ['top'=>.., 'height'=>..]). Cards baixos (< 60px, ou seja tarefa de menos
 * de 1h) escondem badge/notas pra não estourar -- ver .agenda-card-compacta.
 */
function agenda_render_card(array $t, ?array $posicao): string
{
    $duracaoMin = (int) $t['duracao_minutos'];
    $horaTxt = $t['hora_inicio'] ? substr($t['hora_inicio'], 0, 5) : null;
    $horaFimTxt = $horaTxt ? date('H:i', strtotime($horaTxt) + $duracaoMin * 60) : null;
    $compacta = $posicao && $posicao['height'] < 60;

    $estilo = $posicao ? "position:absolute; top:{$posicao['top']}px; height:{$posicao['height']}px; left:2px; right:2px;" : '';
    $estilo .= 'border-left-color: ' . ($t['cliente_id'] ? cor_cliente((int) $t['cliente_id']) : 'var(--ph-border)') . ';';

    ob_start();
    ?>
    <div class="agenda-card<?= $compacta ? ' agenda-card-compacta' : '' ?>" draggable="true" data-id="<?= $t['id'] ?>"
        data-titulo="<?= e($t['titulo']) ?>" data-cliente-id="<?= $t['cliente_id'] ?: '' ?>" data-chamado-id="<?= $t['chamado_id'] ?: '' ?>"
        data-notas="<?= e($t['notas'] ?? '') ?>" data-status="<?= e($t['status']) ?>"
        data-hora-inicio="<?= e($horaTxt ?? '') ?>" data-duracao-minutos="<?= $duracaoMin ?>"
        style="<?= $estilo ?>">
        <button type="button" class="agenda-card-remover" data-id="<?= $t['id'] ?>" data-data="<?= e($t['data']) ?>" title="Remover"><i class="bi bi-x-lg"></i></button>
        <div class="agenda-card-topo">
            <button type="button" class="agenda-card-status" data-id="<?= $t['id'] ?>" data-status="<?= e($t['status']) ?>" title="Marcar status">
                <i class="bi <?= icone_status_tarefa($t['status']) ?>"></i>
            </button>
            <div class="flex-grow-1" style="min-width:0;">
                <?php if ($horaTxt): ?><div class="agenda-card-horario"><?= e($horaTxt) ?>&ndash;<?= e($horaFimTxt) ?></div><?php endif; ?>
                <div class="agenda-card-titulo <?= $t['status'] === 'concluida' ? 'text-decoration-line-through text-muted' : '' ?>"><?= e($t['titulo']) ?></div>
                <?php if ($t['nome_cliente']): ?><span class="badge" style="background-color: <?= e(cor_cliente((int) $t['cliente_id'])) ?>; color:#fff;"><?= e($t['nome_cliente']) ?></span><?php endif; ?>
                <?php if ($t['titulo_chamado']): ?><span class="badge bg-secondary"><i class="bi bi-life-preserver"></i> #<?= $t['chamado_id'] ?></span><?php endif; ?>
            </div>
        </div>
        <?php if ($t['notas']): ?><p class="agenda-card-notas"><?= e($t['notas']) ?></p><?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

$tituloPagina = 'Agenda';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-1"><i class="bi bi-calendar3"></i> Agenda</h4>
<p class="text-muted small mb-3">Organize o que precisa atender em cada dia e horário -- compartilhado entre a gestão. Arraste os cards entre dias e horários pra reorganizar, ou clique no <i class="bi bi-x-lg"></i> pra remover.</p>

<div class="calendario-mensal mb-4" data-eventos='<?= e(json_encode($eventosCalendarioAgenda, JSON_UNESCAPED_UNICODE)) ?>' data-aba-retorno="agenda"></div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <h5 class="mb-0">Prioridades da semana</h5>
        <a href="<?= url('agenda.php') ?>?data=<?= e($semanaAnteriorFoco) ?>" class="btn btn-sm btn-outline-secondary" title="Semana anterior"><i class="bi bi-chevron-left"></i></a>
        <span class="small text-muted"><?= date('d/m', strtotime($segundaSemanaFoco)) ?> a <?= date('d/m', strtotime($diasDaSemanaFoco[6])) ?></span>
        <a href="<?= url('agenda.php') ?>?data=<?= e($semanaProximaFoco) ?>" class="btn btn-sm btn-outline-secondary" title="Próxima semana"><i class="bi bi-chevron-right"></i></a>
        <input type="date" class="form-control form-control-sm" id="seletorDataFoco" value="<?= e($dataFocoParam) ?>" style="width:auto;" title="Ir pra semana desta data">
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="botaoMeuHorario" title="Definir horário de trabalho e almoço">
            <i class="bi bi-clock"></i> Meu horário
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" id="botaoSugestaoIa" <?= AiSugestoes::configurada() ? '' : 'disabled title="IA ainda não configurada (ver Configurações)"' ?>>
            <i class="bi bi-stars"></i> Sugestão da IA
        </button>
        <button type="button" class="btn btn-sm btn-dark" id="botaoNovaTarefa"><i class="bi bi-plus-lg"></i> Nova tarefa</button>
    </div>
</div>

<!-- Horário de trabalho/almoço do usuário logado -- usado pra desenhar a
     régua de horas do Gantt e pra IA não sugerir tarefa fora do expediente. -->
<div class="modal fade" id="modalHorarioTrabalho" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock"></i> Meu horário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Usado pra desenhar a régua de horas do Gantt abaixo e pra IA não sugerir tarefa fora do seu expediente ou em cima do almoço.</p>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label small mb-1">Expediente começa</label>
            <input type="time" class="form-control form-control-sm" id="horarioExpedienteInicio" value="<?= e($horarioTrabalho['expediente_inicio']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small mb-1">Expediente termina</label>
            <input type="time" class="form-control form-control-sm" id="horarioExpedienteFim" value="<?= e($horarioTrabalho['expediente_fim']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small mb-1">Almoço começa</label>
            <input type="time" class="form-control form-control-sm" id="horarioAlmocoInicio" value="<?= e($horarioTrabalho['almoco_inicio']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small mb-1">Almoço termina</label>
            <input type="time" class="form-control form-control-sm" id="horarioAlmocoFim" value="<?= e($horarioTrabalho['almoco_fim']) ?>">
          </div>
        </div>
        <div class="text-danger small mt-2 d-none" id="horarioTrabalhoErro"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-dark" id="botaoSalvarHorarioTrabalho"><i class="bi bi-check2"></i> Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Antes de gerar, pergunta clientes/período/se é pra só sugerir em texto ou
     já criar as tarefas na agenda -- pedido do Kleber pra não ter que ficar
     copiando a sugestão manualmente dia a dia. Clientes é multi-seleção
     (checkbox), não um único select -- às vezes ele quer olhar só um
     conjunto de clientes de cada vez (ex.: emergência), não "todos ou 1". -->
<div class="modal fade" id="modalSugestaoIa" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-stars"></i> Sugestão da IA</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label mb-0">Clientes</label>
            <button type="button" class="btn btn-link btn-sm p-0" id="sugestaoIaClienteToggle">Marcar/desmarcar todos</button>
          </div>
          <div class="border rounded p-2" style="max-height:180px; overflow-y:auto;">
            <?php foreach ($clientes as $c): ?>
            <div class="form-check">
              <input class="form-check-input sugestao-ia-cliente-check" type="checkbox" value="<?= $c['id'] ?>" id="sugestaoIaCliente<?= $c['id'] ?>" checked>
              <label class="form-check-label" for="sugestaoIaCliente<?= $c['id'] ?>"><?= e($c['nome']) ?></label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Todos marcados = considera todos. Desmarque os que não quer incluir dessa vez.</div>
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
            Criar essas tarefas direto na agenda, já com horário (em vez de só sugerir em texto)
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

<div class="agenda-gantt-wrapper">
  <div class="agenda-regua-horas" style="margin-top: <?= $alturaCabecalhoColunaPx + $alturaZonaNaoAgendadaPx ?>px; height: <?= $alturaGanttPx ?>px;">
    <?php for ($m = $minExpedienteInicio; $m < $minExpedienteFim; $m += 60): ?>
    <div class="agenda-regua-hora" style="top: <?= $m - $minExpedienteInicio ?>px;"><?= sprintf('%02d:00', intdiv($m, 60)) ?></div>
    <?php endfor; ?>
  </div>

  <div class="agenda-semana-kanban" id="semanaKanban">
    <?php foreach ($diasDaSemanaFoco as $indiceDia => $dataColuna):
        $tarefasColunaTodas = $tarefasPorData[$dataColuna] ?? [];
        $tarefasAgendadasColuna = array_filter($tarefasColunaTodas, fn ($t) => $t['hora_inicio'] !== null);
        $tarefasNaoAgendadasColuna = array_filter($tarefasColunaTodas, fn ($t) => $t['hora_inicio'] === null);
        $ehHojeColuna = $dataColuna === date('Y-m-d');
    ?>
    <div class="agenda-coluna-dia<?= $ehHojeColuna ? ' agenda-coluna-dia-hoje' : '' ?>" data-date="<?= e($dataColuna) ?>">
      <div class="agenda-coluna-cabecalho" style="height: <?= $alturaCabecalhoColunaPx ?>px;">
        <div>
          <div class="agenda-coluna-dianome"><?= DIAS_SEMANA_ABREV_PT_AGENDA[$indiceDia] ?></div>
          <div class="agenda-coluna-diadata"><?= date('d/m', strtotime($dataColuna)) ?></div>
        </div>
        <button type="button" class="btn btn-sm btn-link p-0 agenda-coluna-add" data-date="<?= e($dataColuna) ?>" title="Nova tarefa nesse dia"><i class="bi bi-plus-lg"></i></button>
      </div>

      <div class="agenda-coluna-naoagendadas" data-date="<?= e($dataColuna) ?>" style="height: <?= $alturaZonaNaoAgendadaPx ?>px;" title="Tarefas sem horário definido -- arraste pra grade abaixo pra dar um horário">
        <?php foreach ($tarefasNaoAgendadasColuna as $t) {
            echo agenda_render_card($t, null);
        } ?>
      </div>

      <div class="agenda-coluna-grade" data-date="<?= e($dataColuna) ?>" style="height: <?= $alturaGanttPx ?>px;">
        <?php if ($minAlmocoFim > $minAlmocoInicio): ?>
        <div class="agenda-almoco" style="top: <?= max(0, $minAlmocoInicio - $minExpedienteInicio) ?>px; height: <?= $minAlmocoFim - $minAlmocoInicio ?>px;" title="Almoço"></div>
        <?php endif; ?>
        <?php foreach ($tarefasAgendadasColuna as $t):
            $inicioMinCard = minutos_do_dia(substr($t['hora_inicio'], 0, 5));
            $topPxCard = max(0, $inicioMinCard - $minExpedienteInicio);
            $alturaPxCard = max(30, (int) $t['duracao_minutos']);
            echo agenda_render_card($t, ['top' => $topPxCard, 'height' => $alturaPxCard]);
        endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
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
            <div class="col-md-4">
              <label class="form-label">Data *</label>
              <input type="date" name="data" id="tarefaAgendaData" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Horário</label>
              <input type="time" name="hora_inicio" id="tarefaAgendaHora" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Duração</label>
              <select name="duracao_minutos" id="tarefaAgendaDuracao" class="form-select">
                <?php foreach (AGENDA_DURACOES_OPCOES as $minutosOpcao => $rotuloOpcao): ?>
                <option value="<?= $minutosOpcao ?>" <?= $minutosOpcao === AGENDA_DURACAO_PADRAO_MINUTOS ? 'selected' : '' ?>><?= $rotuloOpcao ?></option>
                <?php endforeach; ?>
              </select>
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
    var MIN_EXPEDIENTE_INICIO = <?= $minExpedienteInicio ?>;
    var MIN_EXPEDIENTE_FIM = <?= $minExpedienteFim ?>;

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
        document.getElementById('tarefaAgendaHora').value = '';
        document.getElementById('tarefaAgendaDuracao').value = '60';
        document.getElementById('tarefaAgendaCliente').value = '';
        document.getElementById('tarefaAgendaChamado').value = '';
        document.getElementById('tarefaAgendaNotas').value = '';
        document.getElementById('blocoStatusTarefaAgenda').style.display = 'none';
        document.getElementById('botaoExcluirTarefaAgenda').classList.add('d-none');
        filtrarChamadosPorCliente('');
        PrimeUI.openModal(document.getElementById('modalTarefaAgenda'));
    }

    document.getElementById('botaoNovaTarefa').addEventListener('click', function () { abrirNovaTarefa(null); });

    // --- Modal: editar tarefa (a partir de um card do Gantt/calendário) -----
    function abrirEditarTarefa(dados) {
        document.getElementById('tarefaAgendaModalTitulo').textContent = 'Editar tarefa';
        document.getElementById('tarefaAgendaAcao').value = 'atualizar_tarefa';
        document.getElementById('tarefaAgendaId').value = dados.id;
        document.getElementById('tarefaAgendaTitulo').value = dados.titulo || '';
        document.getElementById('tarefaAgendaData').value = dados.data || document.getElementById('seletorDataFoco').value;
        document.getElementById('tarefaAgendaHora').value = dados.horaInicio || '';
        document.getElementById('tarefaAgendaDuracao').value = dados.duracaoMinutos || '60';
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

    // --- Calendário mensal reaproveitado (visão geral / criar+editar rápido) -
    document.querySelectorAll('.calendario-mensal').forEach(function (container) {
        inicializarCalendarioMensal(container, {
            aoAbrirNovo: function (cont, dataIso) { abrirNovaTarefa(dataIso); },
            aoAbrirEvento: function (evento) {
                abrirEditarTarefa({
                    id: evento.id,
                    titulo: evento.titulo,
                    data: evento.data_inicio,
                    horaInicio: evento.hora_inicio,
                    duracaoMinutos: evento.duracao_minutos,
                    clienteId: evento.cliente_id,
                    chamadoId: evento.chamado_id,
                    notas: evento.notas,
                    status: evento.status_real,
                });
            },
        });
    });

    // --- "Prioridades da semana": trocar a data-âncora pula pra semana dessa
    // data -----------------------------------------------------------------
    document.getElementById('seletorDataFoco').addEventListener('change', function () {
        window.location.href = '<?= url('agenda.php') ?>?data=' + encodeURIComponent(this.value);
    });

    // --- Meu horário ---------------------------------------------------
    var modalHorarioTrabalho = document.getElementById('modalHorarioTrabalho');
    document.getElementById('botaoMeuHorario').addEventListener('click', function () {
        document.getElementById('horarioTrabalhoErro').classList.add('d-none');
        PrimeUI.openModal(modalHorarioTrabalho);
    });
    document.getElementById('botaoSalvarHorarioTrabalho').addEventListener('click', function () {
        var ini = document.getElementById('horarioExpedienteInicio').value;
        var fim = document.getElementById('horarioExpedienteFim').value;
        var almocoIni = document.getElementById('horarioAlmocoInicio').value;
        var almocoFim = document.getElementById('horarioAlmocoFim').value;
        var erroEl = document.getElementById('horarioTrabalhoErro');

        function mostrarErro(msg) { erroEl.textContent = msg; erroEl.classList.remove('d-none'); }

        if (!ini || !fim || !almocoIni || !almocoFim) { mostrarErro('Preencha todos os horários.'); return; }
        if (ini >= fim) { mostrarErro('O expediente precisa terminar depois de começar.'); return; }
        if (almocoIni >= almocoFim) { mostrarErro('O almoço precisa terminar depois de começar.'); return; }
        erroEl.classList.add('d-none');

        var dados = new FormData();
        dados.set('acao', 'salvar_horario_trabalho');
        dados.set('ajax', '1');
        dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
        dados.set('expediente_inicio', ini);
        dados.set('expediente_fim', fim);
        dados.set('almoco_inicio', almocoIni);
        dados.set('almoco_fim', almocoFim);

        fetch(window.location.href, { method: 'POST', body: dados })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.sucesso) {
                    window.location.reload();
                } else {
                    mostrarErro(json.mensagem || 'Não foi possível salvar.');
                }
            })
            .catch(function () { mostrarErro('Falha de conexão.'); });
    });

    // --- Sugestão da IA: pergunta clientes (multi-seleção)/período/se cria
    // tarefa de verdade, só depois gera -- pedido do Kleber pra não ficar
    // copiando a sugestão manualmente todo dia, e pra poder escolher um
    // subconjunto de clientes (não só "todos ou 1"). ------------------------
    var botaoSugestaoIa = document.getElementById('botaoSugestaoIa');
    var modalSugestaoIa = document.getElementById('modalSugestaoIa');
    var botaoGerarSugestaoIa = document.getElementById('botaoGerarSugestaoIa');

    document.getElementById('sugestaoIaClienteToggle').addEventListener('click', function () {
        var checks = Array.from(document.querySelectorAll('.sugestao-ia-cliente-check'));
        var todosMarcadosAntes = checks.every(function (c) { return c.checked; });
        checks.forEach(function (c) { c.checked = !todosMarcadosAntes; });
    });

    botaoSugestaoIa.addEventListener('click', function () {
        document.querySelectorAll('.sugestao-ia-cliente-check').forEach(function (c) { c.checked = true; });
        PrimeUI.openModal(modalSugestaoIa);
    });

    botaoGerarSugestaoIa.addEventListener('click', function () {
        var checks = document.querySelectorAll('.sugestao-ia-cliente-check');
        var idsMarcados = Array.from(checks).filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
        if (idsMarcados.length === 0) {
            alert('Selecione pelo menos um cliente (ou deixe todos marcados pra considerar todos).');
            return;
        }
        var clienteIdsParam = idsMarcados.length === checks.length ? '' : idsMarcados.join(',');

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
        dados.set('cliente_ids', clienteIdsParam);
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

    // --- Gantt semanal: clique (nova tarefa/remover/status/editar) --------
    var semanaKanban = document.getElementById('semanaKanban');
    var PROXIMO_STATUS = { pendente: 'em_andamento', em_andamento: 'concluida', concluida: 'pendente' };
    var ICONE_STATUS = { pendente: 'bi-circle', em_andamento: 'bi-arrow-repeat', concluida: 'bi-check-circle-fill' };

    semanaKanban.addEventListener('click', function (e) {
        var botaoAdd = e.target.closest('.agenda-coluna-add');
        if (botaoAdd) { abrirNovaTarefa(botaoAdd.getAttribute('data-date')); return; }

        var botaoRemover = e.target.closest('.agenda-card-remover');
        if (botaoRemover) {
            e.stopPropagation();
            if (!confirm('Excluir essa tarefa da agenda?')) return;
            document.getElementById('excluirTarefaAgendaId').value = botaoRemover.getAttribute('data-id');
            document.getElementById('excluirTarefaAgendaData').value = botaoRemover.getAttribute('data-data');
            document.getElementById('formExcluirTarefaAgenda').submit();
            return;
        }

        var botaoStatus = e.target.closest('.agenda-card-status');
        if (botaoStatus) {
            e.stopPropagation();
            var novoStatus = PROXIMO_STATUS[botaoStatus.getAttribute('data-status')] || 'pendente';
            var cardStatus = botaoStatus.closest('.agenda-card');
            var tituloStatus = cardStatus.querySelector('.agenda-card-titulo');

            botaoStatus.setAttribute('data-status', novoStatus);
            cardStatus.setAttribute('data-status', novoStatus);
            botaoStatus.querySelector('i').className = 'bi ' + ICONE_STATUS[novoStatus];
            tituloStatus.classList.toggle('text-decoration-line-through', novoStatus === 'concluida');
            tituloStatus.classList.toggle('text-muted', novoStatus === 'concluida');

            var dadosStatus = new FormData();
            dadosStatus.set('acao', 'mudar_status_tarefa');
            dadosStatus.set('ajax', '1');
            dadosStatus.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
            dadosStatus.set('tarefa_id', botaoStatus.getAttribute('data-id'));
            dadosStatus.set('status', novoStatus);
            fetch(window.location.href, { method: 'POST', body: dadosStatus }).catch(function () {});
            return;
        }

        var card = e.target.closest('.agenda-card');
        if (!card) return;
        var coluna = card.closest('.agenda-coluna-dia');
        abrirEditarTarefa({
            id: card.getAttribute('data-id'),
            titulo: card.getAttribute('data-titulo'),
            data: coluna.getAttribute('data-date'),
            horaInicio: card.getAttribute('data-hora-inicio'),
            duracaoMinutos: card.getAttribute('data-duracao-minutos'),
            clienteId: card.getAttribute('data-cliente-id'),
            chamadoId: card.getAttribute('data-chamado-id'),
            notas: card.getAttribute('data-notas'),
            status: card.getAttribute('data-status'),
        });
    });

    // --- Gantt semanal: arrastar-e-soltar entre dias E entre horários -----
    // (mesmo padrão HTML5 nativo do dashboard.php, só que agora com
    // múltiplas colunas E posição vertical = horário). Solta na "grade":
    // calcula o horário a partir do Y do cursor (arredondado pro slot de
    // 15min mais próximo). Solta na faixa "sem horário": limpa o horário.
    // Fluido -- sem confirmação, sem reload -- só ao soltar persiste no
    // banco.
    function minutosParaHora(min) {
        var h = Math.floor(min / 60), m = min % 60;
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    var arrastando = null;
    var arrastandoDuracao = 60;

    semanaKanban.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.agenda-card');
        if (!card) return;
        arrastando = card;
        arrastandoDuracao = parseInt(card.getAttribute('data-duracao-minutos'), 10) || 60;
        card.classList.add('agenda-card-arrastando');
        e.dataTransfer.effectAllowed = 'move';
    });

    semanaKanban.addEventListener('dragover', function (e) {
        if (!arrastando) return;
        e.preventDefault();

        var colunaAlvo = e.target.closest('.agenda-coluna-dia');
        if (!colunaAlvo) return;
        semanaKanban.querySelectorAll('.agenda-coluna-dia-alvo').forEach(function (c) { c.classList.remove('agenda-coluna-dia-alvo'); });
        colunaAlvo.classList.add('agenda-coluna-dia-alvo');

        var zonaGrade = e.target.closest('.agenda-coluna-grade');
        if (zonaGrade) {
            var retanguloGrade = zonaGrade.getBoundingClientRect();
            var minutoAbsoluto = MIN_EXPEDIENTE_INICIO + (e.clientY - retanguloGrade.top);
            minutoAbsoluto = Math.round(minutoAbsoluto / 15) * 15;
            minutoAbsoluto = Math.max(MIN_EXPEDIENTE_INICIO, Math.min(minutoAbsoluto, MIN_EXPEDIENTE_FIM - arrastandoDuracao));

            if (arrastando.parentElement !== zonaGrade) zonaGrade.appendChild(arrastando);
            arrastando.style.position = 'absolute';
            arrastando.style.left = '2px';
            arrastando.style.right = '2px';
            arrastando.style.top = (minutoAbsoluto - MIN_EXPEDIENTE_INICIO) + 'px';
            arrastando.style.height = Math.max(30, arrastandoDuracao) + 'px';
            arrastando.setAttribute('data-hora-inicio', minutosParaHora(minutoAbsoluto));
            arrastando.classList.toggle('agenda-card-compacta', arrastandoDuracao < 60);
        } else {
            var zonaNaoAgendada = e.target.closest('.agenda-coluna-naoagendadas');
            if (zonaNaoAgendada && arrastando.parentElement !== zonaNaoAgendada) {
                zonaNaoAgendada.appendChild(arrastando);
                arrastando.style.position = '';
                arrastando.style.left = '';
                arrastando.style.right = '';
                arrastando.style.top = '';
                arrastando.style.height = '';
                arrastando.setAttribute('data-hora-inicio', '');
                arrastando.classList.remove('agenda-card-compacta');
            }
        }
    });

    semanaKanban.addEventListener('dragend', function () {
        if (!arrastando) return;
        arrastando.classList.remove('agenda-card-arrastando');
        semanaKanban.querySelectorAll('.agenda-coluna-dia-alvo').forEach(function (c) { c.classList.remove('agenda-coluna-dia-alvo'); });
        arrastando = null;

        var mudancas = [];
        semanaKanban.querySelectorAll('.agenda-coluna-dia').forEach(function (coluna) {
            var dataColuna = coluna.getAttribute('data-date');
            var indice = 0;
            coluna.querySelectorAll('.agenda-card').forEach(function (card) {
                mudancas.push({
                    id: parseInt(card.getAttribute('data-id'), 10),
                    data: dataColuna,
                    hora_inicio: card.getAttribute('data-hora-inicio') || null,
                    ordem: indice++,
                });
            });
        });

        var dados = new FormData();
        dados.set('acao', 'reordenar_tarefas');
        dados.set('ajax', '1');
        dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
        dados.set('mudancas', JSON.stringify(mudancas));
        fetch(window.location.href, { method: 'POST', body: dados }).catch(function () {});
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
