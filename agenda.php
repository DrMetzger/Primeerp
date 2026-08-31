<?php

/**
 * Agenda de trabalho da gestão -- pedido do Kleber: ele tem todos os
 * chamados cadastrados mas fica perdido em qual cliente atender primeiro.
 * Calendário/Gantt por horário com visão Mês/Semana/Dia de verdade (pedido:
 * "o gantt precisa ter visibilidade por mês, semana e diário"), construído
 * em cima do FullCalendar (biblioteca open-source consolidada -- "procure
 * plugins de autores renomados" -- em vez de reinventar mês/semana/dia na
 * mão). Cada tarefa tem horário de início e duração, organizada dentro do
 * expediente (cada gestor define o próprio horário de trabalho e almoço --
 * ver "Meu horário", inclusive dentro do fluxo da sugestão da IA), pode ter
 * um responsável da gestão atribuído (avatar no card, auto-atribuído a
 * quem criou a tarefa) e, se estiver ligada a um chamado, dá pra responder
 * com anexo e mudar o status (aguardando cliente/aguardando nós/encerrar)
 * direto por aqui, igual à tela de chamados. Ele (e o Petronilho/Caio,
 * mesmo quadro compartilhado -- não é agenda pessoal de cada um) monta a
 * semana arrastando os cards entre dias E entre horários (dentro do
 * calendário, ou da faixa "sem horário" pro calendário). Só admin/diretor/
 * CEO usam isso por enquanto ("colaborador não precisa").
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\AiSugestoes;
use AdminPrime\Database;
use AdminPrime\Notificacoes;

const MESES_ABREV_PT_AGENDA = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const MESES_PT_AGENDA = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const DIAS_SEMANA_ABREV_PT_AGENDA = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const AGENDA_DURACAO_PADRAO_MINUTOS = 60;
const AGENDA_DURACOES_OPCOES = [30 => '0h30', 45 => '0h45', 60 => '1h', 90 => '1h30', 120 => '2h', 180 => '3h', 240 => '4h'];
// Multiplicador usado só pra calcular a ALTURA TOTAL (em px) das visões
// Semana/Dia do FullCalendar a partir da duração do expediente -- dá espaço
// de sobra pro título/logo/badge de cada card sem cortar (versão anterior,
// sem essa folga, deixava tudo cortado e colado -- "ainda está muito ruim",
// pedido do Kleber). Quem decide o pixel/minuto de cada linha dentro dessa
// altura é o próprio FullCalendar, não esse valor diretamente.
const AGENDA_PX_POR_MINUTO = 1.5;

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
        $responsavelId = (int) ($_POST['responsavel_id'] ?? 0) ?: null;
        $notas = trim($_POST['notas'] ?? '') ?: null;

        if ($titulo === '' || !$data || !strtotime($data)) {
            flash_set('erro', 'Informe um título e uma data válida.');
        } else {
            $stmtOrdemMax = $pdo->prepare('SELECT COALESCE(MAX(ordem), -1) FROM agenda_tarefas WHERE data = ?');
            $stmtOrdemMax->execute([$data]);
            $ordemMax = (int) $stmtOrdemMax->fetchColumn();

            $pdo->prepare('INSERT INTO agenda_tarefas (data, hora_inicio, duracao_minutos, ordem, titulo, cliente_id, chamado_id, responsavel_id, notas, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$data, $horaInicio, $duracaoMinutos, $ordemMax + 1, $titulo, $clienteId, $chamadoId, $responsavelId, $notas, Auth::userId()]);
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
        $responsavelId = (int) ($_POST['responsavel_id'] ?? 0) ?: null;
        $notas = trim($_POST['notas'] ?? '') ?: null;
        $status = in_array($_POST['status'] ?? '', ['pendente', 'em_andamento', 'concluida'], true) ? $_POST['status'] : 'pendente';

        if ($titulo === '' || !$data || !strtotime($data)) {
            flash_set('erro', 'Informe um título e uma data válida.');
        } else {
            $pdo->prepare('UPDATE agenda_tarefas SET titulo=?, data=?, hora_inicio=?, duracao_minutos=?, cliente_id=?, chamado_id=?, responsavel_id=?, notas=?, status=? WHERE id=?')
                ->execute([$titulo, $data, $horaInicio, $duracaoMinutos, $clienteId, $chamadoId, $responsavelId, $notas, $status, $tarefaId]);
            flash_set('sucesso', 'Tarefa atualizada.');
        }
    }

    if ($acao === 'excluir_tarefa') {
        $pdo->prepare('DELETE FROM agenda_tarefas WHERE id = ?')->execute([(int) ($_POST['tarefa_id'] ?? 0)]);
        flash_set('sucesso', 'Tarefa removida.');
    }

    if ($acao === 'limpar_periodo') {
        $escopoLimpar = in_array($_POST['escopo'] ?? '', ['dia', 'semana', 'mes'], true) ? $_POST['escopo'] : 'dia';
        $dataAncoraLimpar = $_POST['data'] ?? date('Y-m-d');
        if (!strtotime($dataAncoraLimpar)) {
            $dataAncoraLimpar = date('Y-m-d');
        }
        $tsAncoraLimpar = strtotime($dataAncoraLimpar);

        if ($escopoLimpar === 'semana') {
            $diaSemanaLimpar = (int) date('N', $tsAncoraLimpar);
            $dataInicioLimpar = date('Y-m-d', strtotime('-' . ($diaSemanaLimpar - 1) . ' days', $tsAncoraLimpar));
            $dataFimLimpar = date('Y-m-d', strtotime('+' . (7 - $diaSemanaLimpar) . ' days', $tsAncoraLimpar));
        } elseif ($escopoLimpar === 'mes') {
            $dataInicioLimpar = date('Y-m-01', $tsAncoraLimpar);
            $dataFimLimpar = date('Y-m-t', $tsAncoraLimpar);
        } else {
            $dataInicioLimpar = $dataFimLimpar = $dataAncoraLimpar;
        }

        $stmtLimpar = $pdo->prepare('DELETE FROM agenda_tarefas WHERE data BETWEEN ? AND ?');
        $stmtLimpar->execute([$dataInicioLimpar, $dataFimLimpar]);
        flash_set('sucesso', $stmtLimpar->rowCount() . ' tarefa(s) excluída(s) da agenda.');
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

    // Mover/redimensionar uma tarefa arrastando dentro do FullCalendar (ou
    // arrastando da faixa "sem horário" pra dentro dele) -- substitui o
    // antigo "reordenar_tarefas" (que reordenava a coluna inteira à mão);
    // agora é o próprio FullCalendar quem decide onde cada card fica na
    // tela, só precisamos persistir o novo horário/duração dessa tarefa.
    if ($acao === 'mover_tarefa' && $ehAjax) {
        $tarefaId = (int) ($_POST['tarefa_id'] ?? 0);
        $data = $_POST['data'] ?? '';
        $horaInicio = $_POST['hora_inicio'] ?? '';
        $horaInicio = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaInicio) ? $horaInicio : null;
        $duracaoMinutos = (int) ($_POST['duracao_minutos'] ?? 0);
        if (!isset(AGENDA_DURACOES_OPCOES[$duracaoMinutos])) {
            $duracaoMinutos = AGENDA_DURACAO_PADRAO_MINUTOS;
        }

        header('Content-Type: application/json');
        if ($tarefaId && $data && strtotime($data) && $horaInicio) {
            $pdo->prepare('UPDATE agenda_tarefas SET data = ?, hora_inicio = ?, duracao_minutos = ? WHERE id = ?')
                ->execute([$data, $horaInicio, $duracaoMinutos, $tarefaId]);
            echo json_encode(['sucesso' => true]);
        } else {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos.']);
        }
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

    // Responder o chamado ligado a uma tarefa, sem sair da Agenda -- pedido
    // do Kleber: "ao interagir com o gráfico de Gantt devo responder ao
    // chamado", "preciso poder anexar, clicar e arrastar", e "tem que ser o
    // mesmo padrão da tela de chamados: encerrar, aguardar cliente, aguardar
    // nós" (não só um botão fixo de "encerrar"). POST de verdade
    // (multipart/form-data, não AJAX) -- mesmo padrão exato do
    // "responder_chamado" de cliente.php: INSERT em chamado_respostas +
    // processar_anexos_chamado() + UPDATE chamados direto (sem passar por
    // recalcular_status_chamado(), que é só pro reabrir implícito). Só
    // quando o status escolhido é 'fechado' a tarefa vinculada vira
    // 'concluida' (some do Gantt ativo); nos outros dois status ela continua
    // como estava.
    if ($acao === 'responder_chamado_tarefa') {
        $tarefaId = (int) ($_POST['tarefa_id'] ?? 0);
        $mensagem = trim($_POST['mensagem'] ?? '');
        $temAnexoResposta = !empty($_FILES['anexos']['name'][0] ?? '');
        $statusAposResposta = in_array($_POST['status_apos_resposta'] ?? '', ['aguardando_cliente', 'aguardando_equipe', 'fechado'], true)
            ? $_POST['status_apos_resposta'] : 'aguardando_cliente';
        $notificarCliente = ($_POST['notificar_cliente'] ?? '') === '1';
        $temMensagemOuAnexoResposta = $mensagem !== '' || $temAnexoResposta;

        $stmtTarefaChamado = $pdo->prepare('SELECT chamado_id FROM agenda_tarefas WHERE id = ?');
        $stmtTarefaChamado->execute([$tarefaId]);
        $chamadoIdTarefa = (int) $stmtTarefaChamado->fetchColumn();

        if (!$chamadoIdTarefa) {
            flash_set('erro', 'Essa tarefa não está ligada a um chamado.');
        } else {
            $stmtChamadoAlvo = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
            $stmtChamadoAlvo->execute([$chamadoIdTarefa]);
            $chamadoAlvo = $stmtChamadoAlvo->fetch();

            if (!$chamadoAlvo) {
                flash_set('erro', 'Chamado não encontrado (pode ter sido excluído).');
            } else {
                $nomeResponsavel = Auth::userName();
                $anexosResposta = [];
                $errosAnexoResposta = [];

                if ($temMensagemOuAnexoResposta) {
                    $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_usuario_id, mensagem) VALUES (?, "equipe", ?, ?, ?)')
                        ->execute([$chamadoIdTarefa, $nomeResponsavel, Auth::userId(), $mensagem !== '' ? $mensagem : '(anexo enviado)']);
                    $respostaId = (int) $pdo->lastInsertId();
                    $errosAnexoResposta = processar_anexos_chamado($pdo, $chamadoIdTarefa, $respostaId, 'equipe');

                    $stmtAnexosResposta = $pdo->prepare('SELECT id, arquivo_nome_original FROM chamado_anexos WHERE resposta_id = ?');
                    $stmtAnexosResposta->execute([$respostaId]);
                    $anexosResposta = $stmtAnexosResposta->fetchAll();
                }

                if ($statusAposResposta === 'fechado') {
                    $pdo->prepare("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ?")->execute([$chamadoIdTarefa]);
                    $pdo->prepare("UPDATE agenda_tarefas SET status = 'concluida' WHERE id = ?")->execute([$tarefaId]);
                } else {
                    $pdo->prepare('UPDATE chamados SET status = ?, fechado_em = NULL WHERE id = ?')->execute([$statusAposResposta, $chamadoIdTarefa]);
                }

                if ($temMensagemOuAnexoResposta && $notificarCliente) {
                    $stmtClienteAlvo = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
                    $stmtClienteAlvo->execute([(int) $chamadoAlvo['cliente_id']]);
                    $clienteAlvo = $stmtClienteAlvo->fetch();
                    if ($clienteAlvo) {
                        $stmtChamadoAtualizado = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
                        $stmtChamadoAtualizado->execute([$chamadoIdTarefa]);
                        $chamadoAtualizado = $stmtChamadoAtualizado->fetch();
                        $pessoaChamado = resolver_pessoa_do_chamado($pdo, $chamadoAtualizado, $clienteAlvo);
                        notificar_depois(fn () => Notificacoes::novaRespostaEquipe($chamadoAtualizado, $pessoaChamado, $nomeResponsavel, $mensagem, $anexosResposta));
                    }
                }

                flash_set($errosAnexoResposta ? 'erro' : 'sucesso', $errosAnexoResposta
                    ? ('Chamado atualizado, mas alguns anexos não puderam ser enviados: ' . implode('; ', $errosAnexoResposta))
                    : 'Chamado atualizado.');
            }
        }
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

        // Todos os dias do período entram como data permitida pra IA
        // distribuir tarefa -- inclusive sábado/domingo (pedido do Kleber:
        // às vezes precisa atender cliente no fim de semana também).
        $datasUteisSugestao = [];
        for ($ts = strtotime($dataInicioSugestao); $ts <= strtotime($dataFimSugestao); $ts += 86400) {
            $datasUteisSugestao[] = date('Y-m-d', $ts);
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
            'semana' => 'essa semana (' . formatar_data($dataInicioSugestao) . ' a ' . formatar_data($dataFimSugestao) . ')',
            'mes' => 'esse mês (' . formatar_data($dataInicioSugestao) . ' a ' . formatar_data($dataFimSugestao) . ')',
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
            . "\nDatas permitidas pra colocar tarefa: " . implode(', ', $datasUteisSugestao) . "\n"
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

    // Preserva a visão (mês/semana/dia) escolhida no redirect pós-ação, senão
    // toda vez que ele salva/exclui/limpa uma tarefa a tela voltaria pra
    // visão padrão.
    $paramsRedirect = [];
    if (!empty($_POST['data'])) {
        $paramsRedirect['data'] = $_POST['data'];
    }
    if (!empty($_POST['visao'])) {
        $paramsRedirect['visao'] = $_POST['visao'];
    }
    header('Location: ' . url('agenda.php' . ($paramsRedirect ? '?' . http_build_query($paramsRedirect) : '')));
    exit;
}

// --- Carregamento de dados -------------------------------------------------

$dataFocoParam = $_GET['data'] ?? date('Y-m-d');
if (!strtotime($dataFocoParam)) {
    $dataFocoParam = date('Y-m-d');
}
$visaoInicialAgenda = in_array($_GET['visao'] ?? '', ['mes', 'semana', 'dia'], true) ? $_GET['visao'] : 'semana';
$visaoFullCalendarPorNome = ['mes' => 'dayGridMonth', 'semana' => 'timeGridWeek', 'dia' => 'timeGridDay'];

$horarioTrabalho = agenda_horario_trabalho($pdo, Auth::userId());
$minExpedienteInicio = minutos_do_dia($horarioTrabalho['expediente_inicio']);
$minExpedienteFim = minutos_do_dia($horarioTrabalho['expediente_fim']);
$minAlmocoInicio = minutos_do_dia($horarioTrabalho['almoco_inicio']);
$minAlmocoFim = minutos_do_dia($horarioTrabalho['almoco_fim']);
// Altura (em px) das visões Semana/Dia do FullCalendar -- calculada a
// partir da duração do expediente pra dar espaço de sobra por linha de
// horário (mesmo espírito do AGENDA_PX_POR_MINUTO da versão anterior, sem
// deixar tudo cramped/cortado -- "ainda está muito ruim" era exatamente
// isso).
$alturaGanttPx = (int) max(500, ($minExpedienteFim - $minExpedienteInicio) * AGENDA_PX_POR_MINUTO + 60);

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
$clientes = $pdo->query("SELECT id, nome, logo FROM clientes WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Quem pode ser "responsável" por uma tarefa -- só gestão (mesma audiência
// que usa a Agenda), não colaborador.
$responsaveisAgenda = $pdo->query("SELECT id, nome, foto_perfil FROM usuarios WHERE perfil IN ('admin','diretor','ceo') AND ativo = 1 ORDER BY nome ASC")->fetchAll();

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
    SELECT t.*, c.nome AS nome_cliente, c.logo AS logo_cliente, ch.titulo AS titulo_chamado, r.nome AS nome_responsavel, r.foto_perfil AS foto_responsavel
    FROM agenda_tarefas t
    LEFT JOIN clientes c ON c.id = t.cliente_id
    LEFT JOIN chamados ch ON ch.id = t.chamado_id
    LEFT JOIN usuarios r ON r.id = t.responsavel_id
    ORDER BY t.data ASC, t.hora_inicio ASC, t.ordem ASC
")->fetchAll();

function icone_status_tarefa(string $status): string
{
    return match ($status) {
        'concluida' => 'bi-check-circle-fill',
        'em_andamento' => 'bi-arrow-repeat',
        default => 'bi-circle',
    };
}

/**
 * <img> do logo do cliente se tiver, senão um círculo colorido com a
 * inicial (mesmo espírito do avatar_html() de usuário) -- "o avatar do
 * cliente precisa ser maior, precisa ter o logo" (pedido do Kleber). Maior
 * e mais informativo que o badge de texto que tinha antes.
 */
function agenda_logo_cliente_html(?string $logo, string $nomeCliente, string $cor): string
{
    if (arquivo_publico_existe($logo)) {
        return '<span class="agenda-card-cliente-logo-wrap"><img src="' . e(asset_url($logo)) . '" alt="' . e($nomeCliente) . '" class="agenda-card-cliente-logo"></span>';
    }
    $inicial = $nomeCliente !== '' ? mb_strtoupper(mb_substr($nomeCliente, 0, 1)) : '?';

    return '<span class="agenda-card-cliente-logo-wrap agenda-card-cliente-logo-vazio" style="background-color:' . e($cor) . ';">' . e($inicial) . '</span>';
}

/**
 * Renderiza UM card de tarefa -- reaproveitado tanto pra faixa "sem
 * horário" (lista normal, $posicao null) quanto pra grade do Gantt (posição
 * absoluta calculada em pixels a partir do horário/duração, $posicao
 * ['top'=>.., 'height'=>..]). Cards baixos (< 75px, ~45min) escondem
 * logo/notas pra não estourar -- ver .agenda-card-compacta.
 */
function agenda_render_card(array $t, ?array $posicao, bool $forcarCompacta = false): string
{
    $duracaoMin = (int) $t['duracao_minutos'];
    $horaTxt = $t['hora_inicio'] ? substr($t['hora_inicio'], 0, 5) : null;
    $horaFimTxt = $horaTxt ? date('H:i', strtotime($horaTxt) + $duracaoMin * 60) : null;
    $compacta = ($posicao && $posicao['height'] < 75) || $forcarCompacta;
    $corCliente = $t['cliente_id'] ? cor_cliente((int) $t['cliente_id']) : 'var(--ph-border)';

    $estilo = $posicao ? "position:absolute; top:{$posicao['top']}px; height:{$posicao['height']}px; left:2px; right:2px;" : '';
    $estilo .= 'border-left-color: ' . $corCliente . ';';

    ob_start();
    ?>
    <div class="agenda-card<?= $compacta ? ' agenda-card-compacta' : '' ?>" draggable="true" data-id="<?= $t['id'] ?>"
        data-titulo="<?= e($t['titulo']) ?>" data-cliente-id="<?= $t['cliente_id'] ?: '' ?>" data-chamado-id="<?= $t['chamado_id'] ?: '' ?>"
        data-responsavel-id="<?= $t['responsavel_id'] ?: '' ?>"
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
            </div>
            <?php if ($t['nome_responsavel']): ?>
            <span class="agenda-card-responsavel" title="Responsável: <?= e($t['nome_responsavel']) ?>"><?= avatar_html($t['nome_responsavel'], $t['foto_responsavel'], 'equipe') ?></span>
            <?php endif; ?>
        </div>
        <?php if (!$compacta && ($t['nome_cliente'] || $t['titulo_chamado'])): ?>
        <div class="agenda-card-meta">
            <?php if ($t['nome_cliente']): ?><?= agenda_logo_cliente_html($t['logo_cliente'], $t['nome_cliente'], $corCliente) ?><span class="agenda-card-cliente-nome"><?= e($t['nome_cliente']) ?></span><?php endif; ?>
            <?php if ($t['titulo_chamado']): ?><span class="agenda-card-chamado-ref"><i class="bi bi-life-preserver"></i> #<?= $t['chamado_id'] ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!$compacta && $t['notas']): ?><p class="agenda-card-notas"><?= e($t['notas']) ?></p><?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Monta os eventos pro FullCalendar -- carrega TODAS as tarefas com horário
 * de uma vez (mesmo raciocínio que já existia pro resto da página: uso
 * interno, volume baixo, não compensa complicar com busca por período via
 * AJAX) e deixa o FullCalendar mostrar/esconder conforme a visão (mês/
 * semana/dia) e a navegação, tudo no cliente, sem precisar recarregar a
 * página pra trocar de semana/mês. O HTML de cada card reaproveita
 * agenda_render_card() -- mesmo visual em qualquer visão, um lugar só pra
 * manter. Tarefas curtas (<=45min) forçam o modo compacto mesmo sem saber
 * a altura final em pixels (quem decide isso agora é o FullCalendar) --
 * senão card com logo+notas podia estourar a caixinha de um evento curto
 * de novo (era exatamente o motivo do "ainda está muito ruim" antes).
 */
function agenda_eventos_fullcalendar(array $todasTarefas): array
{
    $eventos = [];
    foreach ($todasTarefas as $t) {
        if ($t['status'] === 'concluida' || $t['hora_inicio'] === null) {
            continue;
        }
        $duracaoMin = (int) $t['duracao_minutos'];
        $inicioIso = $t['data'] . 'T' . substr($t['hora_inicio'], 0, 5) . ':00';
        $fimIso = date('Y-m-d\TH:i:s', strtotime($inicioIso) + $duracaoMin * 60);
        $corCliente = $t['cliente_id'] ? cor_cliente((int) $t['cliente_id']) : '#8a94a6';

        $eventos[] = [
            'id' => (string) $t['id'],
            'title' => $t['titulo'],
            'start' => $inicioIso,
            'end' => $fimIso,
            'backgroundColor' => $corCliente,
            'borderColor' => $corCliente,
            'textColor' => '#fff',
            'extendedProps' => [
                'clienteId' => $t['cliente_id'] ? (string) $t['cliente_id'] : '',
                'responsavelId' => $t['responsavel_id'] ? (string) $t['responsavel_id'] : '',
                'chamadoId' => $t['chamado_id'],
                'duracaoMinutos' => $duracaoMin,
                'notas' => $t['notas'] ?? '',
                'status' => $t['status'],
                'cardHtml' => agenda_render_card($t, null, $duracaoMin <= 45),
            ],
        ];
    }

    return $eventos;
}

$tituloPagina = 'Agenda';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-1"><i class="bi bi-calendar3"></i> Agenda</h4>
<p class="text-muted small mb-3">Organize o que precisa atender em cada dia e horário -- compartilhado entre a gestão. Arraste os cards pra reorganizar (inclusive da faixa "sem horário" pro calendário), ou clique no <i class="bi bi-x-lg"></i> pra remover.</p>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="agendaBotaoHoje">Hoje</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="agendaBotaoAnterior" title="Anterior"><i class="bi bi-chevron-left"></i></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="agendaBotaoProximo" title="Próximo"><i class="bi bi-chevron-right"></i></button>
        <h5 class="mb-0" id="agendaTituloPeriodo">&nbsp;</h5>
        <input type="date" class="form-control form-control-sm" id="seletorDataFoco" value="<?= e($dataFocoParam) ?>" style="width:auto;" title="Ir pra essa data">
        <div class="btn-group btn-group-sm" role="group" id="agendaGrupoVisao">
            <button type="button" class="btn btn-outline-dark" data-visao="dayGridMonth">Mês</button>
            <button type="button" class="btn btn-outline-dark" data-visao="timeGridWeek">Semana</button>
            <button type="button" class="btn btn-outline-dark" data-visao="timeGridDay">Dia</button>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="botaoFiltrosAgenda" title="Filtrar por cliente/responsável">
            <i class="bi bi-funnel"></i> Filtros
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="botaoLimparAgenda" title="Excluir tarefas de um período">
            <i class="bi bi-trash"></i> Limpar
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="botaoMeuHorario" title="Definir horário de trabalho e almoço">
            <i class="bi bi-clock"></i> Meu horário
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" id="botaoSugestaoIa" <?= AiSugestoes::configurada() ? '' : 'disabled title="IA ainda não configurada (ver Configurações)"' ?>>
            <i class="bi bi-stars"></i> Sugestão da IA
        </button>
        <button type="button" class="btn btn-sm btn-dark" id="botaoNovaTarefa"><i class="bi bi-plus-lg"></i> Nova tarefa</button>
    </div>
</div>

<!-- Filtrar quais cards aparecem no Gantt, por cliente e/ou responsável --
     "preciso poder escolher clientes ticando" + "preciso ter essa
     visualização" (de quem tá atendendo cada coisa). Filtro é só visual
     (esconde/mostra card na hora, via JS), não mexe no banco. -->
<div class="modal fade" id="modalFiltrosAgenda" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-funnel"></i> Filtrar Gantt</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label mb-0">Clientes</label>
            <button type="button" class="btn btn-link btn-sm p-0" id="filtroClienteToggle">Marcar/desmarcar todos</button>
          </div>
          <div class="border rounded p-2" style="max-height:180px; overflow-y:auto;">
            <?php foreach ($clientes as $c): ?>
            <div class="form-check">
              <input class="form-check-input filtro-gantt-cliente" type="checkbox" value="<?= $c['id'] ?>" id="filtroCliente<?= $c['id'] ?>" checked>
              <label class="form-check-label" for="filtroCliente<?= $c['id'] ?>"><?= e($c['nome']) ?></label>
            </div>
            <?php endforeach; ?>
            <div class="form-check">
              <input class="form-check-input filtro-gantt-cliente" type="checkbox" value="" id="filtroClienteLivre" checked>
              <label class="form-check-label" for="filtroClienteLivre"><em>Tarefa livre (sem cliente)</em></label>
            </div>
          </div>
        </div>
        <div class="mb-1">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label mb-0">Responsável</label>
            <button type="button" class="btn btn-link btn-sm p-0" id="filtroResponsavelToggle">Marcar/desmarcar todos</button>
          </div>
          <div class="border rounded p-2">
            <?php foreach ($responsaveisAgenda as $r): ?>
            <div class="form-check">
              <input class="form-check-input filtro-gantt-responsavel" type="checkbox" value="<?= $r['id'] ?>" id="filtroResp<?= $r['id'] ?>" checked>
              <label class="form-check-label" for="filtroResp<?= $r['id'] ?>"><?= e($r['nome']) ?></label>
            </div>
            <?php endforeach; ?>
            <div class="form-check">
              <input class="form-check-input filtro-gantt-responsavel" type="checkbox" value="" id="filtroRespNenhum" checked>
              <label class="form-check-label" for="filtroRespNenhum"><em>Sem responsável definido</em></label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Excluir tudo de um dia/semana/mês de uma vez -- pedido do Kleber. -->
<div class="modal fade" id="modalLimparAgenda" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle"></i> Limpar agenda</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Isso exclui PERMANENTEMENTE todas as tarefas do período escolhido. Não pode ser desfeito.</p>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="limparEscopo" id="limparEscopoDia" value="dia" checked>
          <label class="form-check-label" for="limparEscopoDia">Só o dia focado (<?= e(formatar_data($dataFocoParam)) ?>)</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="limparEscopo" id="limparEscopoSemana" value="semana">
          <label class="form-check-label" for="limparEscopoSemana">Essa semana inteira (<?= date('d/m', strtotime($segundaSemanaFoco)) ?> a <?= date('d/m', strtotime($diasDaSemanaFoco[6])) ?>)</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="limparEscopo" id="limparEscopoMes" value="mes">
          <label class="form-check-label" for="limparEscopoMes">Esse mês inteiro (<?= MESES_PT_AGENDA[(int) date('n', strtotime($dataFocoParam))] ?>/<?= date('Y', strtotime($dataFocoParam)) ?>)</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="botaoConfirmarLimparAgenda"><i class="bi bi-trash"></i> Limpar tudo</button>
      </div>
    </div>
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

<!-- Antes de gerar, pergunta clientes/período/horário de trabalho/se é pra só
     sugerir em texto ou já criar as tarefas na agenda -- pedido do Kleber:
     "devo escolher o horário que vou trabalhar e quantas horas de almoço
     antes da Gemini criar as tarefas, e quais clientes vou atender". Vem
     pré-preenchido com o que já tava salvo em "Meu horário", mas editável
     aqui mesmo, na hora -- ao clicar em Gerar, salva esse horário (fica
     valendo pra próxima também) e só depois manda pra IA. Clientes é
     multi-seleção (checkbox), não um único select -- às vezes ele quer
     olhar só um conjunto de clientes de cada vez (ex.: emergência), não
     "todos ou 1". -->
<div class="modal fade" id="modalSugestaoIa" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-stars"></i> Sugestão da IA</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label mb-0">Clientes que vou atender</label>
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
            <option value="semana">Essa semana</option>
            <option value="mes">Esse mês</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label mb-1">Meu horário de trabalho e almoço</label>
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1">Expediente começa</label>
              <input type="time" class="form-control form-control-sm" id="sugestaoIaExpedienteInicio" value="<?= e($horarioTrabalho['expediente_inicio']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1">Expediente termina</label>
              <input type="time" class="form-control form-control-sm" id="sugestaoIaExpedienteFim" value="<?= e($horarioTrabalho['expediente_fim']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1">Almoço começa</label>
              <input type="time" class="form-control form-control-sm" id="sugestaoIaAlmocoInicio" value="<?= e($horarioTrabalho['almoco_inicio']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small mb-1">Almoço termina</label>
              <input type="time" class="form-control form-control-sm" id="sugestaoIaAlmocoFim" value="<?= e($horarioTrabalho['almoco_fim']) ?>">
            </div>
          </div>
          <div class="text-danger small mt-1 d-none" id="sugestaoIaHorarioErro"></div>
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

<!-- Tela de carregamento animada enquanto a IA gera a sugestão/tarefas --
     pedido do Kleber: "preciso de uma tela de carregamento com varinha de
     IA gerando". Cobre a tela toda; a mensagem de status vai trocando pra
     dar a sensação de progresso enquanto espera a resposta. -->
<div class="agenda-ia-overlay d-none" id="agendaIaOverlay">
  <div class="agenda-ia-overlay-caixa">
    <div class="agenda-ia-varinha"><i class="bi bi-magic"></i><i class="bi bi-stars agenda-ia-estrela agenda-ia-estrela-1"></i><i class="bi bi-stars agenda-ia-estrela agenda-ia-estrela-2"></i></div>
    <div class="agenda-ia-overlay-titulo">Gerando com IA...</div>
    <div class="agenda-ia-overlay-status" id="agendaIaOverlayStatus">Analisando chamados abertos...</div>
  </div>
</div>

<!-- Tarefas sem horário definido -- ficam numa faixa acima do calendário,
     arrastáveis pra dentro dele (dá um horário na hora em que solta). -->
<div class="agenda-naoagendadas-faixa">
  <div class="agenda-naoagendadas-titulo"><i class="bi bi-inbox"></i> Sem horário definido <span class="text-muted fw-normal">-- arraste pro calendário pra agendar</span></div>
  <div class="agenda-naoagendadas-lista" id="agendaNaoAgendadasLista">
    <?php
    $tarefasNaoAgendadas = array_filter($todasTarefas, fn ($t) => $t['status'] !== 'concluida' && $t['hora_inicio'] === null);
    if (!$tarefasNaoAgendadas): ?>
    <p class="text-muted small mb-0" id="agendaNaoAgendadasVazio">Nenhuma -- toda tarefa já tem horário.</p>
    <?php else: foreach ($tarefasNaoAgendadas as $t) {
        echo agenda_render_card($t, null);
    } endif; ?>
  </div>
</div>

<div id="agendaCalendario"></div>

<!-- Nova/editar tarefa -- UM modal só, reaproveitado pros dois casos. -->
<div class="modal fade" id="modalTarefaAgenda" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tarefaAgendaModalTitulo">Nova tarefa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="formTarefaAgenda">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" id="tarefaAgendaAcao" value="criar_tarefa">
        <input type="hidden" name="tarefa_id" id="tarefaAgendaId" value="">
        <input type="hidden" name="visao" id="tarefaAgendaVisao" value="">
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
              <label class="form-label">Responsável</label>
              <select name="responsavel_id" id="tarefaAgendaResponsavel" class="form-select">
                <option value="">Ninguém definido</option>
                <?php foreach ($responsaveisAgenda as $r): ?>
                <option value="<?= $r['id'] ?>"><?= e($r['nome']) ?></option>
                <?php endforeach; ?>
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

      <!-- Responder o chamado ligado a essa tarefa -- pedido do Kleber:
           "ao interagir com o gráfico de Gantt devo responder ao chamado",
           "preciso poder anexar clicar e arrastar", e "tem que ser o mesmo
           padrão da tela de chamados: encerrar, aguardar cliente, aguardar
           nós" (não só um botão fixo de "encerrar"). É um FORM SEPARADO
           (não aninhado dentro do de cima) porque precisa de
           enctype=multipart/form-data pros anexos -- é um POST de verdade
           (não AJAX), igual ao "responder chamado" de cliente.php. -->
      <form method="post" enctype="multipart/form-data" id="formResponderChamadoTarefa" class="d-none border-top">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="responder_chamado_tarefa">
        <input type="hidden" name="tarefa_id" id="responderChamadoTarefaId" value="">
        <input type="hidden" name="data" id="responderChamadoTarefaData" value="">
        <input type="hidden" name="visao" id="responderChamadoTarefaVisao" value="">
        <div class="modal-body">
          <label class="form-label">Responder o chamado #<span id="tarefaAgendaChamadoIdTexto"></span></label>
          <textarea name="mensagem" id="responderChamadoMensagem" class="form-control mb-2" rows="3" placeholder="Mensagem de resposta pro cliente..."></textarea>
          <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" name="notificar_cliente" value="1" id="responderChamadoNotificar" checked>
              <label class="form-check-label" for="responderChamadoNotificar">Notificar o cliente</label>
            </div>
            <div class="d-flex align-items-center gap-2">
              <label class="small text-muted mb-0" for="responderChamadoStatus">Deixar como:</label>
              <select name="status_apos_resposta" id="responderChamadoStatus" class="form-select form-select-sm w-auto">
                <option value="aguardando_cliente" selected>Aguardando cliente</option>
                <option value="aguardando_equipe">Aguardando nós</option>
                <option value="fechado">Fechado (encerrar)</option>
              </select>
            </div>
          </div>
          <div class="anexos-stager" data-max-arquivos="<?= ANEXO_CHAMADO_MAX_QUANTIDADE ?>" data-max-tamanho-bytes="<?= ANEXO_CHAMADO_MAX_SIZE ?>">
            <input type="file" name="anexos[]" class="anexos-stager-input" multiple accept="<?= e(ANEXO_CHAMADO_ACCEPT_HTML) ?>">
            <div class="anexos-stager-controles">
              <button type="button" class="btn btn-outline-dark btn-sm anexos-stager-escolher"><i class="bi bi-paperclip"></i> Escolher anexos</button>
              <button type="button" class="btn btn-dark btn-sm anexos-stager-enviar" disabled><i class="bi bi-plus-lg"></i> Enviar anexos</button>
              <span class="anexos-stager-pendente text-muted"></span>
            </div>
            <div class="anexos-stager-erro alert alert-danger d-none"></div>
            <ul class="anexos-stager-lista list-unstyled"></ul>
          </div>
          <p class="anexos-upload-preview">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada -- clique em "Escolher anexos" ou arraste o arquivo pra essa área.</p>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success"><i class="bi bi-send"></i> Responder</button>
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
    <input type="hidden" name="visao" id="excluirTarefaAgendaVisao" value="">
</form>

<!-- FullCalendar (MIT, biblioteca open-source consolidada de calendário/
     agenda) -- pedido do Kleber: "procure plugins de autores renomados, faça
     algo incrível". Bundle "global" já traz dayGrid/timeGrid/interaction
     (drag-and-drop) juntos. Vendorizado localmente (mesmo padrão do FPDF
     nesse projeto -- ver src/Pdf.php) em vez de CDN, pra não depender de
     internet externa em produção. -->
<script src="<?= asset_url('assets/js/vendor/fullcalendar/index.global.min.js') ?>"></script>
<script src="<?= asset_url('assets/js/vendor/fullcalendar/locales-all.global.min.js') ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var CHAMADOS_POR_CLIENTE = <?= json_encode(array_map(fn ($ch) => ['id' => (int) $ch['id'], 'cliente_id' => (int) $ch['cliente_id']], $chamadosAbertos)) ?>;
    var EVENTOS_AGENDA = <?= json_encode(agenda_eventos_fullcalendar($todasTarefas)) ?>;
    var HORARIO_TRABALHO = <?= json_encode($horarioTrabalho) ?>;
    var ALTURA_GANTT_PX = <?= $alturaGanttPx ?>;
    var VISAO_INICIAL = '<?= e($visaoFullCalendarPorNome[$visaoInicialAgenda]) ?>';
    var DATA_FOCO_INICIAL = '<?= e($dataFocoParam) ?>';
    var ROTULOS_VISAO = { dayGridMonth: 'mes', timeGridWeek: 'semana', timeGridDay: 'dia' };

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
    function abrirNovaTarefa(dataIso, horaIso) {
        document.getElementById('tarefaAgendaModalTitulo').textContent = 'Nova tarefa';
        document.getElementById('tarefaAgendaAcao').value = 'criar_tarefa';
        document.getElementById('tarefaAgendaId').value = '';
        document.getElementById('tarefaAgendaTitulo').value = '';
        document.getElementById('tarefaAgendaData').value = dataIso || document.getElementById('seletorDataFoco').value;
        document.getElementById('tarefaAgendaHora').value = horaIso || '';
        document.getElementById('tarefaAgendaDuracao').value = '60';
        // Quem cria a tarefa já entra como responsável por padrão -- ainda dá
        // pra trocar pra outra pessoa da gestão antes de salvar.
        document.getElementById('tarefaAgendaResponsavel').value = '<?= (int) Auth::userId() ?>';
        document.getElementById('tarefaAgendaCliente').value = '';
        document.getElementById('tarefaAgendaChamado').value = '';
        document.getElementById('tarefaAgendaNotas').value = '';
        document.getElementById('blocoStatusTarefaAgenda').style.display = 'none';
        document.getElementById('formResponderChamadoTarefa').classList.add('d-none');
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
        document.getElementById('tarefaAgendaResponsavel').value = dados.responsavelId || '';
        document.getElementById('tarefaAgendaCliente').value = dados.clienteId || '';
        filtrarChamadosPorCliente(dados.clienteId || '');
        document.getElementById('tarefaAgendaChamado').value = dados.chamadoId || '';
        document.getElementById('tarefaAgendaNotas').value = dados.notas || '';
        document.getElementById('tarefaAgendaStatus').value = dados.status || 'pendente';
        document.getElementById('blocoStatusTarefaAgenda').style.display = '';
        document.getElementById('botaoExcluirTarefaAgenda').classList.remove('d-none');
        document.getElementById('excluirTarefaAgendaId').value = dados.id;
        document.getElementById('excluirTarefaAgendaData').value = dados.data || '';

        // Responder o chamado ligado a essa tarefa -- é um FORM SEPARADO
        // (POST de verdade, com anexo) do formTarefaAgenda; só populamos os
        // campos ocultos e mostramos/escondemos ele aqui.
        var formResponder = document.getElementById('formResponderChamadoTarefa');
        if (dados.chamadoId) {
            document.getElementById('tarefaAgendaChamadoIdTexto').textContent = dados.chamadoId;
            document.getElementById('responderChamadoTarefaId').value = dados.id;
            document.getElementById('responderChamadoTarefaData').value = dados.data || '';
            document.getElementById('responderChamadoMensagem').value = '';
            document.getElementById('responderChamadoStatus').value = 'aguardando_cliente';
            formResponder.classList.remove('d-none');
        } else {
            formResponder.classList.add('d-none');
        }

        PrimeUI.openModal(document.getElementById('modalTarefaAgenda'));
    }

    document.getElementById('botaoExcluirTarefaAgenda').addEventListener('click', function () {
        if (!confirm('Excluir essa tarefa da agenda?')) return;
        document.getElementById('formExcluirTarefaAgenda').submit();
    });

    // --- Trocar a data-âncora pula o calendário pra essa data, sem
    // recarregar a página (o FullCalendar já tem todos os eventos
    // carregados) -- ligado depois de o calendário ser criado, mais abaixo.
    document.getElementById('seletorDataFoco').addEventListener('change', function () {
        if (window.agendaCalendar) window.agendaCalendar.gotoDate(this.value);
    });

    // --- Horário de trabalho/almoço: validação + salvamento compartilhados
    // entre o modal "Meu horário" e o campo dentro de "Sugestão da IA"
    // (pedido do Kleber: "devo escolher o horário que vou trabalhar e
    // quantas horas de almoço antes da Gemini criar as tarefas"). -----------
    function validarHorarioTrabalho(ini, fim, almocoIni, almocoFim) {
        if (!ini || !fim || !almocoIni || !almocoFim) return 'Preencha todos os horários.';
        if (ini >= fim) return 'O expediente precisa terminar depois de começar.';
        if (almocoIni >= almocoFim) return 'O almoço precisa terminar depois de começar.';
        return null;
    }

    function salvarHorarioTrabalho(ini, fim, almocoIni, almocoFim) {
        var dados = new FormData();
        dados.set('acao', 'salvar_horario_trabalho');
        dados.set('ajax', '1');
        dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
        dados.set('expediente_inicio', ini);
        dados.set('expediente_fim', fim);
        dados.set('almoco_inicio', almocoIni);
        dados.set('almoco_fim', almocoFim);
        return fetch(window.location.href, { method: 'POST', body: dados }).then(function (r) { return r.json(); });
    }

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

        var erroValidacao = validarHorarioTrabalho(ini, fim, almocoIni, almocoFim);
        if (erroValidacao) { erroEl.textContent = erroValidacao; erroEl.classList.remove('d-none'); return; }
        erroEl.classList.add('d-none');

        salvarHorarioTrabalho(ini, fim, almocoIni, almocoFim)
            .then(function (json) {
                if (json.sucesso) {
                    window.location.reload();
                } else {
                    erroEl.textContent = json.mensagem || 'Não foi possível salvar.';
                    erroEl.classList.remove('d-none');
                }
            })
            .catch(function () { erroEl.textContent = 'Falha de conexão.'; erroEl.classList.remove('d-none'); });
    });

    // --- Limpar agenda (dia/semana/mês) ---------------------------------
    var modalLimparAgenda = document.getElementById('modalLimparAgenda');
    document.getElementById('botaoLimparAgenda').addEventListener('click', function () {
        PrimeUI.openModal(modalLimparAgenda);
    });
    document.getElementById('botaoConfirmarLimparAgenda').addEventListener('click', function () {
        var escopo = document.querySelector('input[name="limparEscopo"]:checked').value;
        if (!confirm('Tem certeza? Isso vai excluir essas tarefas permanentemente.')) return;

        var form = document.createElement('form');
        form.method = 'post';
        form.action = '<?= url('agenda.php') ?>';
        form.innerHTML =
            '<input type="hidden" name="csrf_token" value="' + document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value + '">' +
            '<input type="hidden" name="acao" value="limpar_periodo">' +
            '<input type="hidden" name="escopo" value="' + escopo + '">' +
            '<input type="hidden" name="data" value="' + document.getElementById('seletorDataFoco').value + '">';
        document.body.appendChild(form);
        form.submit();
    });

    // --- Filtros do Gantt (cliente/responsável) -- só esconde/mostra card,
    // não mexe no banco. Aplica na hora, sem precisar de botão "aplicar". --
    // Cobre tanto os eventos já dentro do calendário (via display:'none' da
    // API do FullCalendar) quanto a faixa "sem horário" (DOM comum).
    var modalFiltrosAgenda = document.getElementById('modalFiltrosAgenda');
    document.getElementById('botaoFiltrosAgenda').addEventListener('click', function () {
        PrimeUI.openModal(modalFiltrosAgenda);
    });

    function aplicarFiltrosGantt() {
        var clientesMarcados = Array.from(document.querySelectorAll('.filtro-gantt-cliente:checked')).map(function (c) { return c.value; });
        var respMarcados = Array.from(document.querySelectorAll('.filtro-gantt-responsavel:checked')).map(function (c) { return c.value; });

        if (window.agendaCalendar) {
            window.agendaCalendar.getEvents().forEach(function (evento) {
                var clienteOk = clientesMarcados.indexOf(evento.extendedProps.clienteId || '') !== -1;
                var respOk = respMarcados.indexOf(evento.extendedProps.responsavelId || '') !== -1;
                evento.setProp('display', (clienteOk && respOk) ? 'auto' : 'none');
            });
        }
        document.querySelectorAll('#agendaNaoAgendadasLista .agenda-card').forEach(function (card) {
            var clienteOk = clientesMarcados.indexOf(card.getAttribute('data-cliente-id') || '') !== -1;
            var respOk = respMarcados.indexOf(card.getAttribute('data-responsavel-id') || '') !== -1;
            card.style.display = (clienteOk && respOk) ? '' : 'none';
        });
    }
    document.querySelectorAll('.filtro-gantt-cliente, .filtro-gantt-responsavel').forEach(function (chk) {
        chk.addEventListener('change', aplicarFiltrosGantt);
    });
    document.getElementById('filtroClienteToggle').addEventListener('click', function () {
        var checks = Array.from(document.querySelectorAll('.filtro-gantt-cliente'));
        var todosMarcadosAntes = checks.every(function (c) { return c.checked; });
        checks.forEach(function (c) { c.checked = !todosMarcadosAntes; });
        aplicarFiltrosGantt();
    });
    document.getElementById('filtroResponsavelToggle').addEventListener('click', function () {
        var checks = Array.from(document.querySelectorAll('.filtro-gantt-responsavel'));
        var todosMarcadosAntes = checks.every(function (c) { return c.checked; });
        checks.forEach(function (c) { c.checked = !todosMarcadosAntes; });
        aplicarFiltrosGantt();
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

    // --- Tela de carregamento com a "varinha de IA gerando" -- pedido do
    // Kleber: "preciso de uma tela de carregamento com varinha de IA
    // gerando, depois recarregar a tela com todas informações
    // automaticamente". As mensagens de status vão trocando sozinhas pra dar
    // sensação de progresso enquanto espera a IA responder (não dá pra saber
    // o progresso real de uma chamada de IA, então é só feedback visual).
    var agendaIaOverlay = document.getElementById('agendaIaOverlay');
    var agendaIaOverlayStatus = document.getElementById('agendaIaOverlayStatus');
    var MENSAGENS_IA_CARREGANDO = [
        'Analisando chamados abertos...',
        'Priorizando por urgência e idade...',
        'Organizando os horários do expediente...',
        'Distribuindo entre os dias...',
        'Quase lá...',
    ];
    var timerMensagensIa = null;
    function mostrarOverlayIa() {
        var indice = 0;
        agendaIaOverlayStatus.textContent = MENSAGENS_IA_CARREGANDO[0];
        agendaIaOverlay.classList.remove('d-none');
        timerMensagensIa = setInterval(function () {
            indice = (indice + 1) % MENSAGENS_IA_CARREGANDO.length;
            agendaIaOverlayStatus.textContent = MENSAGENS_IA_CARREGANDO[indice];
        }, 1600);
    }
    function esconderOverlayIa() {
        clearInterval(timerMensagensIa);
        agendaIaOverlay.classList.add('d-none');
    }

    botaoGerarSugestaoIa.addEventListener('click', function () {
        var checks = document.querySelectorAll('.sugestao-ia-cliente-check');
        var idsMarcados = Array.from(checks).filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
        if (idsMarcados.length === 0) {
            alert('Selecione pelo menos um cliente (ou deixe todos marcados pra considerar todos).');
            return;
        }
        var clienteIdsParam = idsMarcados.length === checks.length ? '' : idsMarcados.join(',');

        var ini = document.getElementById('sugestaoIaExpedienteInicio').value;
        var fim = document.getElementById('sugestaoIaExpedienteFim').value;
        var almocoIni = document.getElementById('sugestaoIaAlmocoInicio').value;
        var almocoFim = document.getElementById('sugestaoIaAlmocoFim').value;
        var erroHorarioEl = document.getElementById('sugestaoIaHorarioErro');
        var erroValidacao = validarHorarioTrabalho(ini, fim, almocoIni, almocoFim);
        if (erroValidacao) { erroHorarioEl.textContent = erroValidacao; erroHorarioEl.classList.remove('d-none'); return; }
        erroHorarioEl.classList.add('d-none');

        var criarTarefas = document.getElementById('sugestaoIaCriarTarefas').checked;
        var periodoEscolhido = document.getElementById('sugestaoIaPeriodo').value;
        var dataReferencia = (window.agendaCalendar ? window.agendaCalendar.getDate() : new Date()).toISOString().slice(0, 10);

        botaoGerarSugestaoIa.disabled = true;

        // Salva o horário (fica valendo pra próxima vez também) antes de
        // mandar pra IA -- só depois disso o expediente/almoço usados no
        // prompt e na validação do servidor batem com o que foi digitado
        // aqui.
        salvarHorarioTrabalho(ini, fim, almocoIni, almocoFim).then(function (jsonHorario) {
            if (!jsonHorario.sucesso) {
                erroHorarioEl.textContent = jsonHorario.mensagem || 'Não foi possível salvar o horário.';
                erroHorarioEl.classList.remove('d-none');
                botaoGerarSugestaoIa.disabled = false;
                return;
            }

            PrimeUI.closeModal(modalSugestaoIa);
            mostrarOverlayIa();

            var dados = new FormData();
            dados.set('acao', 'gerar_sugestao_ia');
            dados.set('ajax', '1');
            dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
            dados.set('data', dataReferencia);
            dados.set('cliente_ids', clienteIdsParam);
            dados.set('periodo', periodoEscolhido);
            dados.set('criar_tarefas', criarTarefas ? '1' : '0');

            fetch(window.location.href, { method: 'POST', body: dados })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    esconderOverlayIa();
                    botaoGerarSugestaoIa.disabled = false;
                    if (!json.sucesso) {
                        alert('Não foi possível gerar: ' + json.mensagem);
                        return;
                    }
                    if (json.tipo === 'tarefas') {
                        // Já criou as tarefas de verdade -- recarrega a tela
                        // com tudo atualizado automaticamente (pedido do
                        // Kleber), mantendo a visão (mês/semana/dia) atual.
                        var visaoAtual = window.agendaCalendar ? ROTULOS_VISAO[window.agendaCalendar.view.type] : 'semana';
                        window.location.href = '<?= url('agenda.php') ?>?data=' + encodeURIComponent(json.dataFoco || dataReferencia) + '&visao=' + visaoAtual;
                    } else {
                        var painel = document.getElementById('painelSugestaoIa');
                        document.getElementById('textoSugestaoIa').textContent = json.sugestao;
                        painel.classList.remove('d-none');
                    }
                })
                .catch(function () {
                    esconderOverlayIa();
                    botaoGerarSugestaoIa.disabled = false;
                    alert('Falha de conexão ao gerar a sugestão.');
                });
        }).catch(function () {
            erroHorarioEl.textContent = 'Falha de conexão ao salvar o horário.';
            erroHorarioEl.classList.remove('d-none');
            botaoGerarSugestaoIa.disabled = false;
        });
    });
    document.getElementById('fecharSugestaoIa').addEventListener('click', function () {
        document.getElementById('painelSugestaoIa').classList.add('d-none');
    });

    // --- Calendário (FullCalendar): status/remover/editar/mover/redimensionar
    // --------------------------------------------------------------------
    var PROXIMO_STATUS = { pendente: 'em_andamento', em_andamento: 'concluida', concluida: 'pendente' };
    var ICONE_STATUS = { pendente: 'bi-circle', em_andamento: 'bi-arrow-repeat', concluida: 'bi-check-circle-fill' };

    function visaoAtualRotulo() {
        return window.agendaCalendar ? (ROTULOS_VISAO[window.agendaCalendar.view.type] || 'semana') : 'semana';
    }
    // Preserva a visão (mês/semana/dia) atual nos POSTs de verdade (que
    // navegam/recarregam a página), senão salvar/excluir/responder chamado
    // sempre voltaria pra visão padrão.
    ['formTarefaAgenda', 'formResponderChamadoTarefa', 'formExcluirTarefaAgenda'].forEach(function (idForm) {
        document.getElementById(idForm).addEventListener('submit', function () {
            var campoVisao = this.querySelector('[name="visao"]');
            if (campoVisao) campoVisao.value = visaoAtualRotulo();
        });
    });

    function persistirMovimentoTarefa(tarefaId, dataIso, horaIso, duracaoMinutos, onErro) {
        var dados = new FormData();
        dados.set('acao', 'mover_tarefa');
        dados.set('ajax', '1');
        dados.set('csrf_token', document.querySelector('#formTarefaAgenda input[name="csrf_token"]').value);
        dados.set('tarefa_id', tarefaId);
        dados.set('data', dataIso);
        dados.set('hora_inicio', horaIso);
        dados.set('duracao_minutos', duracaoMinutos);
        fetch(window.location.href, { method: 'POST', body: dados })
            .then(function (r) { return r.json(); })
            .then(function (json) { if (!json.sucesso) onErro(); })
            .catch(onErro);
    }

    // Clique num card de status/remover troca o ícone/exclui na hora --
    // funciona tanto pros cards dentro do calendário (via delegação no
    // eventClick, mais abaixo) quanto pros da faixa "sem horário" (aqui).
    function tratarCliqueStatus(botaoStatus) {
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
        return novoStatus;
    }

    // --- Faixa "sem horário": clique (remover/status/editar) --------------
    document.getElementById('agendaNaoAgendadasLista').addEventListener('click', function (e) {
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
            var novoStatus = tratarCliqueStatus(botaoStatus);
            if (novoStatus === 'concluida') botaoStatus.closest('.agenda-card').style.display = 'none';
            return;
        }

        var card = e.target.closest('.agenda-card');
        if (!card) return;
        var botaoRemoverInfo = card.querySelector('.agenda-card-remover');
        abrirEditarTarefa({
            id: card.getAttribute('data-id'),
            titulo: card.getAttribute('data-titulo'),
            data: botaoRemoverInfo ? botaoRemoverInfo.getAttribute('data-data') : '',
            horaInicio: card.getAttribute('data-hora-inicio'),
            duracaoMinutos: card.getAttribute('data-duracao-minutos'),
            clienteId: card.getAttribute('data-cliente-id'),
            chamadoId: card.getAttribute('data-chamado-id'),
            responsavelId: card.getAttribute('data-responsavel-id'),
            notas: card.getAttribute('data-notas'),
            status: card.getAttribute('data-status'),
        });
    });

    // --- FullCalendar: mês/semana/dia, tudo num só calendário -- pedido do
    // Kleber: "o gantt precisa ter visibilidade por mês, semana e diário".
    // Horário exibido em semana/dia = expediente do usuário (Meu horário);
    // almoço vira um "buraco" no meio do expediente via businessHours (duas
    // faixas, uma antes e outra depois do almoço) -- sábado/domingo contam
    // como dia normal (pedido: "deixe gerar tarefa em sábado e domingo
    // também", o mesmo vale pra tarefa manual). ------------------------------
    var businessHoursAgenda = [
        { daysOfWeek: [0, 1, 2, 3, 4, 5, 6], startTime: HORARIO_TRABALHO.expediente_inicio + ':00', endTime: HORARIO_TRABALHO.almoco_inicio + ':00' },
        { daysOfWeek: [0, 1, 2, 3, 4, 5, 6], startTime: HORARIO_TRABALHO.almoco_fim + ':00', endTime: HORARIO_TRABALHO.expediente_fim + ':00' },
    ];

    var calendarEl = document.getElementById('agendaCalendario');
    window.agendaCalendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'pt-br',
        firstDay: 1,
        headerToolbar: false,
        initialView: VISAO_INICIAL,
        initialDate: DATA_FOCO_INICIAL,
        slotMinTime: HORARIO_TRABALHO.expediente_inicio + ':00',
        slotMaxTime: HORARIO_TRABALHO.expediente_fim + ':00',
        slotDuration: '00:15:00',
        slotLabelInterval: '01:00:00',
        allDaySlot: false,
        nowIndicator: true,
        editable: true,
        droppable: true,
        dayMaxEvents: true,
        businessHours: businessHoursAgenda,
        events: EVENTOS_AGENDA,
        // A altura por visão (views: { timeGridWeek: {height:...} }) não
        // "pega" nessa versão do FullCalendar -- só a opção height no nível
        // raiz do calendário funciona de verdade. Por isso a altura certa
        // pra cada visão é ajustada via setOption() dentro de datesSet, mais
        // abaixo (roda de novo toda vez que troca de mês/semana/dia).
        height: ALTURA_GANTT_PX,
        // Semana/dia: card completo (mesmo visual de sempre, com
        // logo/avatar/status/remover). Mês: renderização padrão do
        // FullCalendar (compacta, já colorida por cliente) -- um card
        // "cheio" não cabe direito numa célula de mês.
        eventContent: function (arg) {
            if (arg.view.type === 'dayGridMonth') return;
            return { html: arg.event.extendedProps.cardHtml };
        },
        eventDidMount: function (arg) {
            // O card reaproveita o HTML da faixa "sem horário" (que é
            // draggable="true" nativo) -- dentro do calendário quem cuida do
            // arrasto é o próprio FullCalendar, então tira o atributo pra não
            // disparar um drag HTML5 nativo por cima.
            arg.el.querySelectorAll('[draggable]').forEach(function (el) { el.removeAttribute('draggable'); });
        },
        eventClick: function (info) {
            var alvoStatus = info.jsEvent.target.closest('.agenda-card-status');
            if (alvoStatus) {
                var novoStatus = tratarCliqueStatus(alvoStatus);
                if (novoStatus === 'concluida') info.event.remove();
                return;
            }

            var alvoRemover = info.jsEvent.target.closest('.agenda-card-remover');
            if (alvoRemover) {
                if (!confirm('Excluir essa tarefa da agenda?')) return;
                document.getElementById('excluirTarefaAgendaId').value = info.event.id;
                document.getElementById('excluirTarefaAgendaData').value = info.event.startStr.slice(0, 10);
                document.getElementById('formExcluirTarefaAgenda').submit();
                return;
            }

            var props = info.event.extendedProps;
            abrirEditarTarefa({
                id: info.event.id,
                titulo: info.event.title,
                data: info.event.startStr.slice(0, 10),
                horaInicio: info.event.startStr.slice(11, 16),
                duracaoMinutos: props.duracaoMinutos,
                clienteId: props.clienteId,
                chamadoId: props.chamadoId,
                responsavelId: props.responsavelId,
                notas: props.notas,
                status: props.status,
            });
        },
        dateClick: function (info) {
            var dataIso = info.dateStr.slice(0, 10);
            var horaIso = info.allDay ? '' : info.dateStr.slice(11, 16);
            abrirNovaTarefa(dataIso, horaIso);
        },
        eventDrop: function (info) {
            var duracaoMin = Math.round((info.event.end - info.event.start) / 60000);
            persistirMovimentoTarefa(info.event.id, info.event.startStr.slice(0, 10), info.event.startStr.slice(11, 16), duracaoMin, function () { info.revert(); });
        },
        eventResize: function (info) {
            var duracaoMin = Math.round((info.event.end - info.event.start) / 60000);
            persistirMovimentoTarefa(info.event.id, info.event.startStr.slice(0, 10), info.event.startStr.slice(11, 16), duracaoMin, function () { info.revert(); });
        },
        // Arrastou um card da faixa "sem horário" pra dentro do calendário --
        // dá um horário na hora, persiste e some da faixa.
        eventReceive: function (info) {
            var duracaoMin = Math.round((info.event.end - info.event.start) / 60000);
            var trayCard = document.querySelector('#agendaNaoAgendadasLista .agenda-card[data-id="' + info.event.id + '"]');
            persistirMovimentoTarefa(info.event.id, info.event.startStr.slice(0, 10), info.event.startStr.slice(11, 16), duracaoMin, function () {
                info.event.remove();
                alert('Não foi possível agendar essa tarefa.');
            });
            if (trayCard) {
                trayCard.remove();
                var lista = document.getElementById('agendaNaoAgendadasLista');
                if (!lista.querySelector('.agenda-card')) {
                    lista.innerHTML = '<p class="text-muted small mb-0">Nenhuma -- toda tarefa já tem horário.</p>';
                }
            }
        },
        datesSet: function (info) {
            document.getElementById('agendaTituloPeriodo').textContent = info.view.title;
            document.querySelectorAll('#agendaGrupoVisao button').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-visao') === info.view.type);
            });
            var dataAtual = window.agendaCalendar.getDate().toISOString().slice(0, 10);
            document.getElementById('seletorDataFoco').value = dataAtual;
            var novaUrl = '<?= url('agenda.php') ?>?data=' + dataAtual + '&visao=' + (ROTULOS_VISAO[info.view.type] || 'semana');
            window.history.replaceState(null, '', novaUrl);

            // Altura por visão -- ver comentário acima de "height:" na
            // criação do calendário.
            var alturaAlvo = info.view.type === 'dayGridMonth' ? 700 : ALTURA_GANTT_PX;
            if (window.agendaCalendar.getOption('height') !== alturaAlvo) {
                window.agendaCalendar.setOption('height', alturaAlvo);
            }
        },
    });
    window.agendaCalendar.render();

    // Arrastar da faixa "sem horário" pra dentro do calendário.
    new FullCalendar.Draggable(document.getElementById('agendaNaoAgendadasLista'), {
        itemSelector: '.agenda-card',
        eventData: function (el) {
            var duracaoMin = parseInt(el.getAttribute('data-duracao-minutos'), 10) || 60;
            var h = Math.floor(duracaoMin / 60), m = duracaoMin % 60;
            var corCliente = el.style.borderLeftColor || '#8a94a6';
            return {
                id: el.getAttribute('data-id'),
                title: el.getAttribute('data-titulo'),
                duration: (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m,
                backgroundColor: corCliente,
                borderColor: corCliente,
                textColor: '#fff',
                extendedProps: {
                    clienteId: el.getAttribute('data-cliente-id') || '',
                    responsavelId: el.getAttribute('data-responsavel-id') || '',
                    chamadoId: el.getAttribute('data-chamado-id') || '',
                    duracaoMinutos: duracaoMin,
                    notas: el.getAttribute('data-notas') || '',
                    status: el.getAttribute('data-status') || 'pendente',
                    cardHtml: el.outerHTML,
                },
            };
        },
    });

    document.getElementById('agendaBotaoHoje').addEventListener('click', function () { window.agendaCalendar.today(); });
    document.getElementById('agendaBotaoAnterior').addEventListener('click', function () { window.agendaCalendar.prev(); });
    document.getElementById('agendaBotaoProximo').addEventListener('click', function () { window.agendaCalendar.next(); });
    document.querySelectorAll('#agendaGrupoVisao button').forEach(function (btn) {
        btn.addEventListener('click', function () { window.agendaCalendar.changeView(btn.getAttribute('data-visao')); });
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
