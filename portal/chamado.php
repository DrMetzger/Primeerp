<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Notificacoes;

ClienteAuth::requireLogin();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();
$chamadoId = (int) ($_GET['id'] ?? 0);

// Login de setor só pode ver/responder chamado do PRÓPRIO setor -- sem essa
// trava, um responsável de setor poderia ver chamado de outro setor da
// mesma empresa só trocando o id na URL (o que quebraria a independência
// entre setores que é o pedido original). Login master continua vendo
// qualquer chamado da empresa, de qualquer setor.
if (ClienteAuth::isMaster()) {
    $stmt = $pdo->prepare('SELECT c.*, u.nome AS responsavel_nome, u.foto_perfil AS responsavel_foto FROM chamados c LEFT JOIN usuarios u ON u.id = c.responsavel_id WHERE c.id = ? AND c.cliente_id = ?');
    $stmt->execute([$chamadoId, $clienteId]);
} else {
    $stmt = $pdo->prepare('SELECT c.*, u.nome AS responsavel_nome, u.foto_perfil AS responsavel_foto FROM chamados c LEFT JOIN usuarios u ON u.id = c.responsavel_id WHERE c.id = ? AND c.cliente_id = ? AND c.setor_id = ?');
    $stmt->execute([$chamadoId, $clienteId, ClienteAuth::setorId()]);
}
$chamado = $stmt->fetch();

$stmtCliente = $pdo->prepare('SELECT nome, foto_perfil, contato_nome, contato_email, contato_telefone FROM clientes WHERE id = ?');
$stmtCliente->execute([$clienteId]);
$clienteInfo = $stmtCliente->fetch();
$fotoPerfilCliente = resolver_avatar_por_email($pdo, $clienteInfo['contato_email'] ?? null, $clienteInfo['foto_perfil'] ?? null);
$nomeMasterCliente = ($clienteInfo['contato_nome'] ?? '') ?: ($clienteInfo['nome'] ?? '');

if (!$chamado) {
    http_response_code(404);
    die('Chamado não encontrado.');
}

// Trocar o setor de um chamado -- só o login MASTER pode (é quem vê/gerencia
// todos os setores; login de setor não deveria conseguir mandar o próprio
// chamado pra outro setor, senão perderia acesso a ele na mesma hora). Exige
// que o setor de destino já tenha responsável cadastrado, senão bloqueia
// (mesma trava e mesmo aviso por e-mail de cliente.php, do lado da equipe).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atribuir_setor_chamado' && ClienteAuth::isMaster()) {
    csrf_verify();
    $novoSetorId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;

    if ($novoSetorId === null) {
        $pdo->prepare('UPDATE chamados SET setor_id = NULL WHERE id = ? AND cliente_id = ?')->execute([$chamadoId, $clienteId]);
        flash_set('sucesso', 'Chamado sem setor vinculado.');
    } else {
        $stmtSetorAlvoPortal = $pdo->prepare('SELECT id, nome FROM setores WHERE id = ? AND cliente_id = ?');
        $stmtSetorAlvoPortal->execute([$novoSetorId, $clienteId]);
        $setorAlvoPortal = $stmtSetorAlvoPortal->fetch();

        $stmtMembrosAlvoPortal = $pdo->prepare('SELECT * FROM setor_responsaveis WHERE setor_id = ?');
        $stmtMembrosAlvoPortal->execute([$novoSetorId]);
        $membrosAlvoPortal = $stmtMembrosAlvoPortal->fetchAll();

        $stmtSetorMasterPortal = $pdo->prepare('SELECT setor_id FROM clientes WHERE id = ?');
        $stmtSetorMasterPortal->execute([$clienteId]);
        $masterNesseSetorPortal = (int) $stmtSetorMasterPortal->fetchColumn() === $novoSetorId;

        if (!$setorAlvoPortal || (!$membrosAlvoPortal && !$masterNesseSetorPortal)) {
            flash_set('erro', 'Esse setor ainda não tem nenhum responsável cadastrado -- relacione um usuário antes de atribuir chamados a ele.');
        } else {
            $setorAntigoId = (int) $chamado['setor_id'];
            $pdo->prepare('UPDATE chamados SET setor_id = ? WHERE id = ? AND cliente_id = ?')->execute([$novoSetorId, $chamadoId, $clienteId]);

            if ($novoSetorId !== $setorAntigoId) {
                foreach ($membrosAlvoPortal as $membro) {
                    Notificacoes::chamadoAtribuidoSetor(['id' => $chamadoId, 'titulo' => $chamado['titulo']], $membro, $setorAlvoPortal['nome']);
                }
                if ($masterNesseSetorPortal) {
                    Notificacoes::chamadoAtribuidoSetor(
                        ['id' => $chamadoId, 'titulo' => $chamado['titulo']],
                        ['nome' => $nomeMasterCliente, 'email' => $clienteInfo['contato_email'], 'telefone' => $clienteInfo['contato_telefone']],
                        $setorAlvoPortal['nome']
                    );
                }
            }

            flash_set('sucesso', 'Chamado vinculado ao setor ' . $setorAlvoPortal['nome'] . '.');
        }
    }

    header('Location: ' . url('portal/chamado.php?id=' . $chamadoId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['encerrar_chamado', 'reabrir_chamado'], true)) {
    csrf_verify();
    // O SELECT lá em cima já garante "AND c.cliente_id = ?" -- ou seja, só
    // chega até aqui se o chamado for mesmo desse cliente logado. Sem essa
    // trava, um cliente poderia encerrar/reabrir chamado de outra empresa
    // só trocando o id na URL.
    $novoStatus = $_POST['acao'] === 'encerrar_chamado' ? 'fechado' : 'aguardando_equipe';
    $fechandoAgora = $_POST['acao'] === 'encerrar_chamado';
    $pdo->prepare('UPDATE chamados SET status = ?, fechado_em = ' . ($fechandoAgora ? 'NOW()' : 'NULL') . ' WHERE id = ? AND cliente_id = ?')
        ->execute([$novoStatus, $chamadoId, $clienteId]);
    flash_set('sucesso', $_POST['acao'] === 'encerrar_chamado' ? 'Chamado encerrado.' : 'Chamado reaberto.');
    header('Location: ' . url('portal/chamado.php?id=' . $chamadoId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'responder') {
    csrf_verify();
    $mensagem = trim($_POST['mensagem'] ?? '');
    $temAnexo = !empty($_FILES['anexos']['name'][0] ?? '');

    if ($mensagem !== '' || $temAnexo) {
        $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_cliente_tipo, autor_setor_responsavel_id, mensagem) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $chamadoId, 'cliente', ClienteAuth::clienteNome(),
                ClienteAuth::isMaster() ? 'master' : 'setor',
                ClienteAuth::isMaster() ? null : ClienteAuth::setorResponsavelId(),
                $mensagem !== '' ? $mensagem : '(anexo enviado)',
            ]);
        $respostaId = (int) $pdo->lastInsertId();
        // (Cliente não tem usuario_id de equipe -- autor_usuario_id fica NULL aqui, o que é correto.)

        $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, $respostaId, 'cliente');

        // Recalcula o status a partir de quem respondeu por último -- se o
        // chamado estava fechado, isso também reabre automaticamente (o
        // cliente respondendo é sinal suficiente de que precisa reabrir).
        recalcular_status_chamado($pdo, $chamadoId);

        flash_set('sucesso', $errosAnexo ? implode(' ', $errosAnexo) : 'Resposta enviada.');
    }

    header('Location: ' . url('portal/chamado.php?id=' . $chamadoId));
    exit;
}

$respostas = $pdo->prepare('SELECT * FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em ASC');
$respostas->execute([$chamadoId]);
$respostas = $respostas->fetchAll();

// Anexos da abertura + de cada resposta, pra desenhar junto de cada bolha.
$stmtAnexos = $pdo->prepare('SELECT * FROM chamado_anexos WHERE chamado_id = ? ORDER BY criado_em ASC');
$stmtAnexos->execute([$chamadoId]);
$anexosPorLinha = [];
foreach ($stmtAnexos->fetchAll() as $a) {
    $chave = $a['resposta_id'] ?? 'abertura';
    $anexosPorLinha[$chave][] = $a;
}

// Foto de cada membro da equipe que respondeu -- mesma ideia de cliente.php:
// antes só se sabia o NOME (autor_nome, texto solto), nunca a foto de qual
// usuário era, então toda resposta da equipe caía na inicial genérica.
$idsAutoresEquipe = array_unique(array_filter(array_column($respostas, 'autor_usuario_id')));
$fotoPorUsuarioId = [];
if ($idsAutoresEquipe) {
    $placeholdersUsuarios = implode(',', array_fill(0, count($idsAutoresEquipe), '?'));
    $stmtFotos = $pdo->prepare("SELECT id, foto_perfil FROM usuarios WHERE id IN ($placeholdersUsuarios)");
    $stmtFotos->execute(array_values($idsAutoresEquipe));
    foreach ($stmtFotos->fetchAll() as $u) {
        $fotoPorUsuarioId[$u['id']] = $u['foto_perfil'];
    }
}

// Qual responsável de setor EXATAMENTE abriu/respondeu (não mais "o
// responsável atual do setor" -- desde que um setor pode ter vários
// responsáveis ao mesmo tempo, essa aproximação deixou de bater). Uma
// resposta/abertura de antes dessas colunas existirem cai pro fallback
// (letra genérica), já que não tem como saber quem foi.
$idsResponsaveisMencionados = array_unique(array_filter(array_merge(
    [$chamado['aberto_por_setor_responsavel_id']],
    array_column($respostas, 'autor_setor_responsavel_id')
)));
$dadosPorResponsavelId = [];
if ($idsResponsaveisMencionados) {
    $placeholdersResp = implode(',', array_fill(0, count($idsResponsaveisMencionados), '?'));
    $stmtDadosResp = $pdo->prepare("SELECT id, nome, email, foto_perfil FROM setor_responsaveis WHERE id IN ($placeholdersResp)");
    $stmtDadosResp->execute(array_values($idsResponsaveisMencionados));
    foreach ($stmtDadosResp->fetchAll() as $sr) {
        $dadosPorResponsavelId[$sr['id']] = ['nome' => $sr['nome'], 'foto' => resolver_avatar_por_email($pdo, $sr['email'], $sr['foto_perfil'])];
    }
}

// Normaliza a mensagem de abertura + as respostas num único formato, igual
// ao que já é feito em cliente.php -- antes a mensagem de abertura ficava
// fora do chat, sem bolha, cor ou nome de contato.
$dadosAbertura = $chamado['aberto_por_tipo'] === 'setor' ? ($dadosPorResponsavelId[$chamado['aberto_por_setor_responsavel_id']] ?? null) : null;
$linhasChat = [];
$linhasChat[] = [
    'autor_tipo' => $chamado['aberto_por'] === 'equipe' ? 'equipe' : 'cliente',
    'autor_nome' => $chamado['aberto_por'] === 'equipe'
        ? ($chamado['responsavel_nome'] ?: 'Equipe PrimeERP')
        : ($dadosAbertura['nome'] ?? $nomeMasterCliente),
    'foto' => $chamado['aberto_por'] === 'equipe'
        ? ($chamado['responsavel_foto'] ?? null)
        : ($dadosAbertura['foto'] ?? $fotoPerfilCliente),
    'mensagem' => $chamado['descricao'],
    'criado_em' => $chamado['criado_em'],
    'anexos' => $anexosPorLinha['abertura'] ?? [],
];
foreach ($respostas as $r) {
    $dadosResposta = $r['autor_cliente_tipo'] === 'setor' ? ($dadosPorResponsavelId[$r['autor_setor_responsavel_id']] ?? null) : null;
    $linhasChat[] = [
        'autor_tipo' => $r['autor_tipo'],
        'autor_nome' => $r['autor_nome'],
        // Mesma ideia de cliente.php: resposta antiga/importada sem
        // autor_usuario_id vinculado cai pra foto do responsável atual do
        // chamado, em vez de ficar sem foto nenhuma.
        'foto' => $r['autor_tipo'] === 'cliente'
            ? ($r['autor_cliente_tipo'] === 'setor' ? ($dadosResposta['foto'] ?? null) : $fotoPerfilCliente)
            : ($fotoPorUsuarioId[$r['autor_usuario_id']] ?? $chamado['responsavel_foto'] ?? null),
        'mensagem' => $r['mensagem'],
        'criado_em' => $r['criado_em'],
        'anexos' => $anexosPorLinha[$r['id']] ?? [],
    ];
}

// Setores pra montar o select de reatribuição -- só usado pelo master (ver
// header abaixo), mas fica fora do "if" pra não duplicar a query nos dois
// lugares se um dia precisar dela em mais de um ponto da página.
$setoresPortal = [];
if (ClienteAuth::isMaster()) {
    $stmtSetoresPortal = $pdo->prepare('
        SELECT s.id, s.nome, (c.setor_id = s.id) AS eh_setor_master,
            EXISTS(SELECT 1 FROM setor_responsaveis sr WHERE sr.setor_id = s.id) AS tem_responsavel
        FROM setores s
        INNER JOIN clientes c ON c.id = s.cliente_id
        WHERE s.cliente_id = ?
        ORDER BY s.nome ASC
    ');
    $stmtSetoresPortal->execute([$clienteId]);
    $setoresPortal = $stmtSetoresPortal->fetchAll();
}

$tituloPagina = $chamado['titulo'];
require __DIR__ . '/../templates/portal_header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <a href="<?= url('portal/chamados.php') ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> Voltar para meus chamados</a>
    <div class="chamado-acoes">
        <a href="<?= url('imprimir_chamado.php?id=' . $chamadoId) ?>" target="_blank" class="btn btn-sm btn-imprimir"><i class="bi bi-printer"></i> Imprimir</a>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="<?= $chamado['status'] === 'fechado' ? 'reabrir_chamado' : 'encerrar_chamado' ?>">
            <?php if ($chamado['status'] === 'fechado'): ?>
                <button type="submit" class="btn btn-reabrir"><i class="bi bi-arrow-counterclockwise"></i> Reabrir</button>
            <?php else: ?>
                <button type="submit" class="btn btn-encerrar"><i class="bi bi-check2-circle"></i> Encerrar</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card card-stat mt-3">
    <div class="card-header bg-white">
        <h5 class="mb-2"><span class="text-muted fw-normal">#<?= (int) $chamado['id'] ?></span> <?= e($chamado['titulo']) ?></h5>
        <div class="d-flex align-items-center flex-wrap gap-3">
            <?php if ($setoresPortal): ?>
            <span class="d-flex align-items-center gap-1">
                <span class="badge-rotulo">Setor</span>
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atribuir_setor_chamado">
                    <select name="setor_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Trocar o setor avisa o responsável dele por e-mail -- setor sem responsável cadastrado fica bloqueado aqui">
                        <option value="" <?= !$chamado['setor_id'] ? 'selected' : '' ?>>Sem setor</option>
                        <?php foreach ($setoresPortal as $s): $temResponsavelPortal = $s['tem_responsavel'] || $s['eh_setor_master']; ?>
                            <option value="<?= $s['id'] ?>" <?= (int) $chamado['setor_id'] === (int) $s['id'] ? 'selected' : '' ?> <?= !$temResponsavelPortal ? 'disabled' : '' ?>><?= e($s['nome']) ?><?= !$temResponsavelPortal ? ' (sem responsável)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </span>
            <?php endif; ?>
            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Status</span> <?= badge_status_chamado_portal($chamado['status']) ?></span>
            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Prioridade</span> <?= badge_prioridade($chamado['prioridade']) ?></span>
            <?php if ($chamado['categoria']): ?>
                <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Categoria</span> <span class="badge bg-light text-dark border"><?= e($chamado['categoria']) ?></span></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-0">Aberto em <?= formatar_data($chamado['criado_em']) ?></p>
    </div>
</div>

<?= render_resumo_anexos_chamado($anexosPorLinha) ?>

<h6 class="mt-4 mb-2">Conversa</h6>
<!-- Suas próprias mensagens ficam à direita (convenção normal de chat), a
     equipe PrimeERP à esquerda -- mas a cor da bolha segue quem escreveu
     (você = verde, equipe = azul/roxo), não o lado. Inclui a mensagem de
     abertura do chamado, que antes ficava sem bolha/cor/nome. -->
<div class="chat-thread">
<?php foreach ($linhasChat as $linha): ?>
    <?php $ladoDireita = $linha['autor_tipo'] === 'cliente'; ?>
    <div class="chat-linha <?= $ladoDireita ? 'chat-direita' : 'chat-esquerda' ?>">
        <?= avatar_html($linha['autor_nome'], $linha['foto'], $linha['autor_tipo']) ?>
        <div class="chat-bolha bolha-<?= e($linha['autor_tipo']) ?>">
            <span class="chat-autor"><?= e($linha['autor_nome']) ?><?= $linha['autor_tipo'] === 'equipe' ? ' · Prime Hub' : '' ?></span>
            <span style="white-space: pre-wrap;"><?= e($linha['mensagem']) ?></span>
            <?= render_anexos_html($linha['anexos']) ?>
            <span class="chat-hora"><?= date('d/m/Y H:i', strtotime($linha['criado_em'])) ?></span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($chamado['status'] === 'fechado'): ?>
    <p class="text-muted small mt-3 mb-1"><i class="bi bi-info-circle"></i> Este chamado está fechado. Se responder, ele é reaberto automaticamente.</p>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="mt-2">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="responder">
    <textarea name="mensagem" class="form-control mb-2" rows="3" placeholder="Escreva uma resposta..."></textarea>
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <input type="file" name="anexos[]" class="form-control form-control-sm" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" style="max-width: 320px;">
        <button type="submit" class="btn btn-dark">Enviar resposta</button>
    </div>
    <p class="anexos-upload-preview mb-0">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada (imagem ou PDF).</p>
</form>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
