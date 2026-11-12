<?php
/**
 * Ações do sistema novo (TypeScript) que reaproveitam as regras daqui:
 * responder chamado (anexos, status, aviso ao cliente por e-mail/WhatsApp)
 * e trocar responsável -- exatamente o que cliente.php faz, mas
 * respondendo JSON. Login: o mesmo do sistema novo (ver
 * sessao_pelo_login_novo() em helpers.php).
 *
 * Proteção contra outro site disparar ação: exige o cabeçalho X-PrimeHub
 * (navegador não manda cabeçalho próprio em requisição de outro site sem
 * permissão) e o cookie de login é SameSite=Lax.
 */
require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Notificacoes;
use AdminPrime\WhatsAppBot;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder_json_acao(array $dados, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_PRIMEHUB'] ?? '') !== '1') {
    responder_json_acao(['erro' => 'Requisição inválida.'], 400);
    exit;
}
// ---------- Lado do cliente (portal) ----------
if (!Auth::check() && ClienteAuth::check()) {
    $acao = $_POST['acao'] ?? '';
    $chamadoId = (int) ($_POST['chamado_id'] ?? 0);
    $clienteId = ClienteAuth::clienteId();

    if ($acao === 'opcoes_chamado') {
        if (ClienteAuth::temPermissaoMaster()) {
            $st = $pdo->prepare('SELECT id, nome FROM setores WHERE cliente_id = ? AND ativo = 1 ORDER BY nome');
            $st->execute([$clienteId]);
        } else {
            $ids = ClienteAuth::setorIds() ?: [0];
            $st = $pdo->prepare('SELECT id, nome FROM setores WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY nome');
            $st->execute($ids);
        }
        responder_json_acao(['categorias' => CATEGORIAS_CHAMADO, 'setores' => $st->fetchAll(), 'clientes' => [], 'responsaveis' => []]);
        exit;
    }

    if ($acao === 'abrir_chamado') {
        // Mesma lógica do "criar_chamado" de portal/chamados.php.
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $prioridade = in_array($_POST['prioridade'] ?? '', ['baixa', 'media', 'alta', 'urgente'], true) ? $_POST['prioridade'] : 'media';
        if (!ClienteAuth::temPermissaoMaster()) {
            $proprios = ClienteAuth::setorIds();
            $setorId = (count($proprios) > 1 && in_array((int) ($_POST['setor_id'] ?? 0), $proprios, true)) ? (int) $_POST['setor_id'] : ClienteAuth::setorId();
        } else {
            $setorId = null;
            if (!empty($_POST['setor_id'])) {
                $conf = $pdo->prepare('SELECT id FROM setores WHERE id = ? AND cliente_id = ?');
                $conf->execute([(int) $_POST['setor_id'], $clienteId]);
                $setorId = $conf->fetchColumn() ?: null;
            }
        }
        if ($titulo === '' || $descricao === '' || $categoria === '') {
            responder_json_acao(['erro' => 'Preencha o título, a categoria e a descrição do chamado.'], 422);
            exit;
        }
        $tituloPadronizado = mb_strtoupper($titulo, 'UTF-8');
        if (chamado_e_duplicado_recente($pdo, $clienteId, $tituloPadronizado, $descricao)) {
            responder_json_acao(['ok' => true, 'id' => null]);
            exit;
        }
        $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, aberto_por_tipo, aberto_por_setor_responsavel_id, setor_id, origem_canal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$clienteId, $tituloPadronizado, $descricao, $categoria, $prioridade, 'cliente',
                ClienteAuth::isMaster() ? 'master' : 'setor', ClienteAuth::isMaster() ? null : ClienteAuth::setorResponsavelId(), $setorId, 'portal']);
        $novoId = (int) $pdo->lastInsertId();
        $avisos = processar_anexos_chamado($pdo, $novoId, null, 'cliente');
        $novo = $pdo->query("SELECT * FROM chamados WHERE id = $novoId")->fetch();
        $stCli = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $stCli->execute([$clienteId]);
        Notificacoes::novoChamado($pdo, $novo, $stCli->fetch());
        \AdminPrime\IaChamados::agendar($pdo, $novoId);
        responder_json_acao(['ok' => true, 'id' => $novoId, 'avisos' => $avisos]);
        finalizar_resposta_e_notificar();
        exit;
    }
    $sql = 'SELECT * FROM chamados WHERE id = ? AND cliente_id = ?';
    $params = [$chamadoId, $clienteId];
    if (!ClienteAuth::temPermissaoMaster()) {
        $setores = ClienteAuth::setorIds() ?: [0];
        $sql .= ' AND setor_id IN (' . implode(',', array_fill(0, count($setores), '?')) . ')';
        $params = [...$params, ...$setores];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->fetch()) {
        responder_json_acao(['erro' => 'Chamado não encontrado.'], 404);
        exit;
    }
    if ($acao === 'responder_chamado') {
        // Mesma lógica do "responder" de portal/chamado.php.
        $mensagem = trim($_POST['mensagem'] ?? '');
        $temAnexo = !empty($_FILES['anexos']['name'][0] ?? '');
        $errosAnexo = [];
        if ($mensagem !== '' || $temAnexo) {
            $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_cliente_tipo, autor_setor_responsavel_id, mensagem) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $chamadoId, 'cliente', ClienteAuth::clienteNome(),
                    ClienteAuth::isMaster() ? 'master' : 'setor',
                    ClienteAuth::isMaster() ? null : ClienteAuth::setorResponsavelId(),
                    $mensagem !== '' ? $mensagem : '(anexo enviado)',
                ]);
            $respostaId = (int) $pdo->lastInsertId();
            $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, $respostaId, 'cliente');
            recalcular_status_chamado($pdo, $chamadoId);
        }
        responder_json_acao(['ok' => true, 'avisos' => $errosAnexo]);
        exit;
    }
    if ($acao === 'encerrar_chamado' || $acao === 'reabrir_chamado') {
        $fechando = $acao === 'encerrar_chamado';
        $pdo->prepare('UPDATE chamados SET status = ?, fechado_em = ' . ($fechando ? 'NOW()' : 'NULL') . ' WHERE id = ? AND cliente_id = ?')
            ->execute([$fechando ? 'fechado' : 'aguardando_equipe', $chamadoId, $clienteId]);
        responder_json_acao(['ok' => true]);
        exit;
    }
    responder_json_acao(['erro' => 'Ação desconhecida.'], 400);
    exit;
}

if (!Auth::check()) {
    responder_json_acao(['erro' => 'Sessão expirada. Entre de novo.'], 401);
    exit;
}

$acao = $_POST['acao'] ?? '';

if ($acao === 'opcoes_chamado') {
    $clienteOpc = (int) ($_POST['cliente_id'] ?? 0);
    $clientes = array_values(array_filter(
        $pdo->query("SELECT id, nome FROM clientes WHERE status <> 'inativo' ORDER BY nome")->fetchAll(),
        fn ($c) => Auth::podeAcessarCliente((int) $c['id'])
    ));
    $setores = [];
    if ($clienteOpc && Auth::podeAcessarCliente($clienteOpc)) {
        $st = $pdo->prepare('SELECT id, nome FROM setores WHERE cliente_id = ? AND ativo = 1 ORDER BY nome');
        $st->execute([$clienteOpc]);
        $setores = array_values(array_filter($st->fetchAll(), fn ($s) => Auth::podeAcessarSetor($clienteOpc, (int) $s['id'])));
    }
    $responsaveis = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();
    responder_json_acao(['categorias' => CATEGORIAS_CHAMADO, 'setores' => $setores, 'clientes' => $clientes, 'responsaveis' => $responsaveis]);
    exit;
}

if ($acao === 'abrir_chamado') {
    // Mesma lógica do "criar_chamado_equipe" de cliente.php.
    $clienteNovo = (int) ($_POST['cliente_id'] ?? 0);
    $categoria = trim($_POST['categoria'] ?? '');
    $setorNovo = ($_POST['setor_id'] ?? '') !== '' ? (int) $_POST['setor_id'] : null;
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $prioridade = in_array($_POST['prioridade'] ?? '', ['baixa', 'media', 'alta', 'urgente'], true) ? $_POST['prioridade'] : 'media';
    if (!$clienteNovo || !Auth::podeAcessarCliente($clienteNovo)) {
        responder_json_acao(['erro' => 'Escolha um cliente.'], 422);
        exit;
    }
    if ($titulo === '' || $descricao === '' || $categoria === '') {
        responder_json_acao(['erro' => 'Preencha título, categoria e descrição do chamado.'], 422);
        exit;
    }
    if (!Auth::podeAcessarSetor($clienteNovo, $setorNovo)) {
        responder_json_acao(['erro' => 'Você não está vinculado a esse setor.'], 403);
        exit;
    }
    $tituloPadronizado = mb_strtoupper($titulo, 'UTF-8');
    if (chamado_e_duplicado_recente($pdo, $clienteNovo, $tituloPadronizado, $descricao)) {
        responder_json_acao(['ok' => true, 'id' => null]);
        exit;
    }
    $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, responsavel_id, setor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$clienteNovo, $tituloPadronizado, $descricao, $categoria, $prioridade, 'equipe', ($_POST['responsavel_id'] ?? '') !== '' ? (int) $_POST['responsavel_id'] : null, $setorNovo]);
    $novoId = (int) $pdo->lastInsertId();
    $avisos = processar_anexos_chamado($pdo, $novoId, null, 'equipe');
    responder_json_acao(['ok' => true, 'id' => $novoId, 'avisos' => $avisos]);
    exit;
}

if ($acao === 'mover_treinamento') {
    // Arrastar reunião/treinamento na agenda: muda só dia e hora (o fim anda
    // junto, mesma duração). Não mexe em destinatários, link nem descrição.
    $treinamentoId = (int) ($_POST['treinamento_id'] ?? 0);
    $novaData = $_POST['data'] ?? '';
    $novaHora = $_POST['hora_inicio'] ?? '';
    $st = $pdo->prepare('SELECT * FROM treinamentos WHERE id = ?');
    $st->execute([$treinamentoId]);
    $t = $st->fetch();
    if (!$t || !Auth::podeAcessarCliente((int) $t['cliente_id']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $novaHora)) {
        responder_json_acao(['erro' => 'Não deu para mover esse agendamento.'], 422);
        exit;
    }
    $inicioAntigo = strtotime($t['data_inicio'] . ' ' . ($t['hora_inicio'] ?: '00:00:00'));
    $inicioNovo = strtotime($novaData . ' ' . $novaHora . ':00');
    $delta = $inicioNovo - $inicioAntigo;
    $dataFim = $t['data_fim'];
    $horaFim = $t['hora_fim'];
    if ($t['data_fim'] && $t['hora_fim']) {
        $fimNovo = strtotime($t['data_fim'] . ' ' . $t['hora_fim']) + $delta;
        $dataFim = date('Y-m-d', $fimNovo);
        $horaFim = date('H:i:s', $fimNovo);
    } elseif ($t['data_fim']) {
        $dataFim = date('Y-m-d', strtotime($t['data_fim']) + $delta);
    }
    $pdo->prepare('UPDATE treinamentos SET data_inicio = ?, hora_inicio = ?, data_fim = ?, hora_fim = ?, lembrete_enviado_em = NULL WHERE id = ?')
        ->execute([$novaData, $novaHora . ':00', $dataFim, $horaFim, $treinamentoId]);
    responder_json_acao(['ok' => true]);
    exit;
}

if ($acao === 'salvar_cliente') {
    // Mesma regra de clientes.php: cadastro do cliente é coisa de admin.
    if (!Auth::isAdmin()) {
        responder_json_acao(['erro' => 'Só admin/diretor/CEO podem alterar o cadastro de um cliente.'], 403);
        exit;
    }
    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') {
        responder_json_acao(['erro' => 'Informe o nome do cliente.'], 422);
        exit;
    }
    $idCli = (int) ($_POST['cliente_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['ativo', 'pausado', 'inativo'], true) ? $_POST['status'] : 'ativo';
    $dados = [
        $nome,
        trim($_POST['contato_nome'] ?? '') ?: null,
        trim($_POST['contato_email'] ?? '') ?: null,
        apenas_digitos($_POST['contato_telefone'] ?? ''),
        normalizar_documento($_POST['contato_documento'] ?? ''),
    ];
    if ($idCli) {
        $pdo->prepare('UPDATE clientes SET nome=?, contato_nome=?, contato_email=?, contato_telefone=?, contato_documento=?, status=?, responsavel_interno_id=?, observacoes=? WHERE id=?')
            ->execute([...$dados, $status, ($_POST['responsavel_interno_id'] ?? '') !== '' ? (int) $_POST['responsavel_interno_id'] : null, trim($_POST['observacoes'] ?? '') ?: null, $idCli]);
    } else {
        $pdo->prepare('INSERT INTO clientes (nome, contato_nome, contato_email, contato_telefone, contato_documento) VALUES (?, ?, ?, ?, ?)')->execute($dados);
        $idCli = (int) $pdo->lastInsertId();
    }
    $erroLogo = processar_upload_logo_cliente($pdo, $idCli);
    responder_json_acao(['ok' => true, 'id' => $idCli, 'avisos' => $erroLogo ? [$erroLogo] : []]);
    exit;
}

$chamadoId = (int) ($_POST['chamado_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
$stmt->execute([$chamadoId]);
$chamado = $stmt->fetch();
$setorChamado = $chamado && $chamado['setor_id'] !== null ? (int) $chamado['setor_id'] : null;

if (!$chamado || !$chamado['cliente_id']
    || !Auth::podeAcessarCliente((int) $chamado['cliente_id'])
    || !Auth::podeAcessarSetor((int) $chamado['cliente_id'], $setorChamado)) {
    responder_json_acao(['erro' => 'Chamado inválido ou fora do seu acesso.'], 403);
    exit;
}
$clienteId = (int) $chamado['cliente_id'];
$stmtCliente = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$stmtCliente->execute([$clienteId]);
$cliente = $stmtCliente->fetch();

if ($acao === 'responder_chamado') {
    // Mesma lógica do "responder_chamado" de cliente.php.
    $mensagem = trim($_POST['mensagem'] ?? '');
    $temAnexo = !empty($_FILES['anexos']['name'][0] ?? '');
    $notificarCliente = ($_POST['notificar_cliente'] ?? '') === '1';
    $statusAposResposta = $_POST['status_apos_resposta'] ?? 'aguardando_cliente';
    if (!in_array($statusAposResposta, ['aguardando_cliente', 'aguardando_equipe', 'fechado'], true)) {
        $statusAposResposta = 'aguardando_cliente';
    }
    $temMensagemOuAnexo = $mensagem !== '' || $temAnexo;
    $nomeResponsavel = Auth::userName();
    $errosAnexo = [];
    $anexosResposta = [];

    if ($temMensagemOuAnexo) {
        $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_usuario_id, mensagem) VALUES (?, "equipe", ?, ?, ?)')
            ->execute([$chamadoId, $nomeResponsavel, Auth::userId(), $mensagem !== '' ? $mensagem : '(anexo enviado)']);
        $respostaId = (int) $pdo->lastInsertId();
        $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, $respostaId, 'equipe');
        $stmtAnexos = $pdo->prepare('SELECT id, arquivo_nome_original FROM chamado_anexos WHERE resposta_id = ?');
        $stmtAnexos->execute([$respostaId]);
        $anexosResposta = $stmtAnexos->fetchAll();
    }

    if ($statusAposResposta === 'fechado') {
        $pdo->prepare("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ?")->execute([$chamadoId]);
    } else {
        $pdo->prepare('UPDATE chamados SET status = ?, fechado_em = NULL WHERE id = ?')->execute([$statusAposResposta, $chamadoId]);
    }

    $stmt->execute([$chamadoId]);
    $chamadoAtualizado = $stmt->fetch();
    if ($temMensagemOuAnexo && $notificarCliente && $cliente) {
        $pessoa = resolver_pessoa_do_chamado($pdo, $chamadoAtualizado, $cliente);
        notificar_depois(fn () => Notificacoes::novaRespostaEquipe($chamadoAtualizado, $pessoa, $nomeResponsavel, $mensagem, $anexosResposta));
        if ($pessoa['telefone'] && $statusAposResposta !== 'fechado') {
            notificar_depois(fn () => WhatsAppBot::prepararConversaPosResposta($pdo, $chamadoAtualizado, $pessoa['telefone']));
        }
    }

    responder_json_acao(['ok' => true, 'status' => $statusAposResposta, 'avisos' => $errosAnexo]);
    finalizar_resposta_e_notificar();
    exit;
}

if ($acao === 'atribuir_responsavel') {
    $novoResponsavelId = ($_POST['responsavel_id'] ?? '') !== '' ? (int) $_POST['responsavel_id'] : null;
    $pdo->prepare('UPDATE chamados SET responsavel_id = ? WHERE id = ?')->execute([$novoResponsavelId, $chamadoId]);
    if ($novoResponsavelId && $novoResponsavelId !== (int) $chamado['responsavel_id']) {
        $stmtResp = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmtResp->execute([$novoResponsavelId]);
        if ($novoResponsavel = $stmtResp->fetch()) {
            notificar_depois(fn () => Notificacoes::chamadoAtribuido(['id' => $chamadoId, 'titulo' => $chamado['titulo'], 'cliente_id' => $clienteId], $novoResponsavel));
        }
    }
    responder_json_acao(['ok' => true]);
    finalizar_resposta_e_notificar();
    exit;
}

responder_json_acao(['erro' => 'Ação desconhecida.'], 400);
