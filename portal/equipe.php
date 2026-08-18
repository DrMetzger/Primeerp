<?php

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\ClienteAuth;
use AdminPrime\Database;
use AdminPrime\Notificacoes;

/**
 * Versão BÁSICA, restrita ao MASTER, do que a equipe interna já gerencia em
 * cliente.php (aba Setores + aba Usuários) -- pedido do Kleber pra deixar o
 * próprio cliente (ex.: Vitor Alvim, da Vitapet) cadastrar setor, cadastrar
 * pessoa e relacionar uma coisa à outra, sem precisar pedir pra Prime.
 *
 * De propósito NÃO tem nada de IA/KPI/Documentos/Propostas/Treinamentos
 * (isso continua só na tela admin) -- aqui é só setor + usuário + vínculo,
 * mais o básico de acesso ao portal (convite/reset/desativar), porque sem
 * isso a pessoa cadastrada não consegue nem entrar pra usar o sistema.
 *
 * Só CRIAR e ALTERAR -- de propósito NÃO tem exclusão de setor nem de
 * usuário aqui (pedido explícito do Kleber: cliente não deve conseguir
 * apagar nada, só incluir e ajustar; exclusão de verdade continua exigindo
 * a Prime em cliente.php). E só existe pra clientes liberados um a um
 * (clientes.portal_permite_gerenciar_equipe) -- tem cliente sem preparo
 * pra isso, ver ClienteAuth::exigirGerenciarEquipe().
 */

ClienteAuth::requireLogin();
ClienteAuth::exigirGerenciarEquipe();

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();

$stmtCliente = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$stmtCliente->execute([$clienteId]);
$cliente = $stmtCliente->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar_setor') {
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '' || !nome_setor_valido($nomeSetor)) {
            flash_set('erro', 'Informe um nome válido pro setor (só pontuação, como "-", não é um nome).');
        } else {
            $pdo->prepare('INSERT INTO setores (cliente_id, nome, icone) VALUES (?, ?, ?)')
                ->execute([$clienteId, $nomeSetor, $_POST['icone'] ?: null]);
            flash_set('sucesso', 'Setor "' . $nomeSetor . '" criado.');
        }
    }

    if ($acao === 'atualizar_setor') {
        $setorId = (int) $_POST['setor_id'];
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '' || !nome_setor_valido($nomeSetor)) {
            flash_set('erro', 'Informe um nome válido pro setor (só pontuação, como "-", não é um nome).');
        } else {
            $pdo->prepare('UPDATE setores SET nome = ?, icone = ?, ativo = ? WHERE id = ? AND cliente_id = ?')
                ->execute([$nomeSetor, $_POST['icone'] ?: null, isset($_POST['ativo']) ? 1 : 0, $setorId, $clienteId]);
            flash_set('sucesso', 'Setor atualizado.');
        }
    }

    if ($acao === 'criar_usuario_cliente') {
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');
        $telefoneRespObrigatorio = apenas_digitos($_POST['telefone'] ?? '');

        if ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } elseif (!$telefoneRespObrigatorio) {
            // Telefone é obrigatório aqui (diferente do cadastro pela
            // equipe interna) -- é o que permite avisar essa pessoa no
            // WhatsApp quando um chamado cair no setor dela.
            flash_set('erro', 'Informe o telefone -- é por ele que a pessoa recebe aviso no WhatsApp quando um chamado cai no setor dela.');
        } else {
            $telefoneResp = apenas_digitos($_POST['telefone'] ?? '');
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $indiceAvatarNovoPortal = (int) ($_POST['avatar'] ?? 0);
            $avatarNovoPortal = ($indiceAvatarNovoPortal >= 1 && $indiceAvatarNovoPortal <= QUANTIDADE_AVATARES_PREDEFINIDOS)
                ? 'assets/img/avatares/avatar-' . $indiceAvatarNovoPortal . '.svg'
                : null;
            $ehMasterRespPortal = isset($_POST['eh_master']) ? 1 : 0;
            try {
                $pdo->prepare('INSERT INTO setor_responsaveis (cliente_id, nome, email, telefone, documento, foto_perfil, eh_master) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$clienteId, $nomeResp, $emailResp, $telefoneResp, $documentoResp, $avatarNovoPortal, $ehMasterRespPortal]);
                substituir_setores_do_responsavel($pdo, $clienteId, (int) $pdo->lastInsertId(), $_POST['setor_ids'] ?? []);
                flash_set('sucesso', 'Usuário cadastrado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível cadastrar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    // Foto de perfil de uma pessoa já cadastrada -- upload de imagem própria
    // ou escolha de um avatar pronto. Sempre confere que a pessoa é desse
    // MESMO cliente antes (diferente da tela admin, aqui não pode confiar
    // que quem manda o responsavel_id é de confiança).
    if ($acao === 'atualizar_avatar_usuario_portal') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);
        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            $erroFotoPortal = processar_upload_foto_setor_responsavel($pdo, $responsavelId);
            flash_set($erroFotoPortal ? 'erro' : 'sucesso', $erroFotoPortal ?? 'Foto atualizada.');
        }
    }

    if ($acao === 'escolher_avatar_predefinido_usuario_portal') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);
        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            $indicePortal = (int) ($_POST['avatar'] ?? 0);
            if (definir_avatar_predefinido_setor_responsavel($pdo, $responsavelId, $indicePortal)) {
                flash_set('sucesso', 'Avatar atualizado.');
            }
        }
    }

    if ($acao === 'editar_usuario_cliente') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');

        $telefoneRespEditar = apenas_digitos($_POST['telefone'] ?? '');

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } elseif ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } elseif (!$telefoneRespEditar) {
            flash_set('erro', 'Informe o telefone -- é por ele que a pessoa recebe aviso no WhatsApp quando um chamado cai no setor dela.');
        } else {
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $ehMasterRespPortal = isset($_POST['eh_master']) ? 1 : 0;
            try {
                $pdo->prepare('UPDATE setor_responsaveis SET nome=?, email=?, telefone=?, documento=?, eh_master=? WHERE id=?')
                    ->execute([$nomeResp, $emailResp, $telefoneRespEditar, $documentoResp, $ehMasterRespPortal, $responsavelId]);
                substituir_setores_do_responsavel($pdo, $clienteId, $responsavelId, $_POST['setor_ids'] ?? []);
                flash_set('sucesso', 'Usuário atualizado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível salvar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    // Multi-select da linha/modal de usuário -- substitui de uma vez TODOS
    // os setores dessa pessoa pelo conjunto marcado (ela pode estar em mais
    // de um agora, ver substituir_setores_do_responsavel() em helpers.php).
    if ($acao === 'definir_setores_responsavel') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            substituir_setores_do_responsavel($pdo, $clienteId, $responsavelId, $_POST['setor_ids'] ?? []);
            flash_set('sucesso', 'Setores atualizados.');
        }
    }

    if ($acao === 'enviar_convite_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $stmt = $pdo->prepare('SELECT sr.*, s.nome AS setor_nome FROM setor_responsaveis sr LEFT JOIN setores s ON s.id = sr.setor_id WHERE sr.id = ? AND sr.cliente_id = ?');
        $stmt->execute([$responsavelId, $clienteId]);
        $responsavel = $stmt->fetch();

        if (!$responsavel) {
            flash_set('erro', 'Usuário não encontrado.');
        } elseif (!$responsavel['setor_id']) {
            flash_set('erro', 'Relacione esse usuário a um setor antes de convidar pro portal -- é o setor que define o que ele vai ver.');
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO setor_senha_tokens (setor_responsavel_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$responsavelId, $token]);
            Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token);
            flash_set('sucesso', 'Convite enviado para ' . $responsavel['email'] . '.');
        }
    }

    if ($acao === 'resetar_senha_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $stmt = $pdo->prepare('SELECT sr.*, s.nome AS setor_nome FROM setor_responsaveis sr LEFT JOIN setores s ON s.id = sr.setor_id WHERE sr.id = ? AND sr.cliente_id = ?');
        $stmt->execute([$responsavelId, $clienteId]);
        $responsavel = $stmt->fetch();

        if (!$responsavel || !$responsavel['setor_id']) {
            flash_set('erro', 'Usuário não encontrado ou sem setor relacionado.');
        } else {
            $pdo->prepare('UPDATE setor_responsaveis SET senha_hash = NULL WHERE id = ?')->execute([$responsavelId]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO setor_senha_tokens (setor_responsavel_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$responsavelId, $token]);
            Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token);
            flash_set('sucesso', 'Senha resetada e novo link enviado para ' . $responsavel['email'] . '.');
        }
    }

    if ($acao === 'desativar_portal_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $pdo->prepare('UPDATE setor_responsaveis SET portal_ativo = 0, senha_hash = NULL WHERE id = ? AND cliente_id = ?')
            ->execute([$responsavelId, $clienteId]);
        flash_set('sucesso', 'Acesso ao portal desativado.');
    }

    header('Location: ' . url('portal/equipe.php'));
    exit;
}

$setores = $pdo->prepare('SELECT * FROM setores WHERE cliente_id = ? ORDER BY nome ASC');
$setores->execute([$clienteId]);
$setores = $setores->fetchAll();

$usuariosCliente = $pdo->prepare('SELECT * FROM setor_responsaveis WHERE cliente_id = ? ORDER BY nome ASC');
$usuariosCliente->execute([$clienteId]);
$usuariosCliente = $usuariosCliente->fetchAll();

// TODOS os setores de cada pessoa (não só o "principal" em setor_id) --
// junta numa única query pra não fazer 1 SELECT por pessoa na tabela.
$nomesSetoresPorId = array_column($setores, 'nome', 'id');
if ($usuariosCliente) {
    $idsResponsaveisPortal = array_column($usuariosCliente, 'id');
    $placeholdersResponsaveisPortal = implode(',', array_fill(0, count($idsResponsaveisPortal), '?'));
    $stmtSetoresPorResponsavelPortal = $pdo->prepare("SELECT setor_responsavel_id, setor_id FROM setor_responsavel_setores WHERE setor_responsavel_id IN ($placeholdersResponsaveisPortal) ORDER BY setor_id ASC");
    $stmtSetoresPorResponsavelPortal->execute($idsResponsaveisPortal);
    $setorIdsPorResponsavelIdPortal = [];
    foreach ($stmtSetoresPorResponsavelPortal->fetchAll() as $vinculoPortal) {
        $setorIdsPorResponsavelIdPortal[$vinculoPortal['setor_responsavel_id']][] = (int) $vinculoPortal['setor_id'];
    }
    foreach ($usuariosCliente as &$ucComSetoresPortal) {
        $ucComSetoresPortal['setor_ids'] = $setorIdsPorResponsavelIdPortal[$ucComSetoresPortal['id']] ?? [];
    }
    unset($ucComSetoresPortal);
}

$tituloPagina = 'Setores e Usuários';
$larguraPortalPx = 1200; // tabela de usuários tem várias colunas + botões de ação -- 900px forçava rolagem horizontal feia
require __DIR__ . '/../templates/portal_header.php';
?>

<h4 class="mb-1">Setores e Usuários</h4>
<p class="text-muted small mb-4">Cadastre os setores da empresa e as pessoas de cada um, e relacione uma coisa à outra. O telefone de cada pessoa é obrigatório -- é por ele que a Prime avisa no WhatsApp quando um chamado cai no setor dela. Depois de relacionar a um setor, dá pra mandar o convite de acesso ao portal pra pessoa. Aqui só dá pra criar e alterar -- exclusão é só com a Prime.</p>

<ul class="nav nav-tabs mb-3" id="equipeTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#equipeSetores"><i class="bi bi-diagram-3"></i> Setores <span class="badge bg-secondary"><?= count($setores) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#equipeUsuarios"><i class="bi bi-people"></i> Usuários <span class="badge bg-secondary"><?= count($usuariosCliente) ?></span></a></li>
</ul>

<div class="tab-content">

    <!-- SETORES -->
    <div class="tab-pane show active" id="equipeSetores">
        <div class="card card-stat mb-3">
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="criar_setor">
                    <input type="hidden" name="icone" id="novoSetorIconePortal" value="bi-diagram-3">
                    <div class="col-md-8"><label class="form-label">Nome do novo setor</label><input type="text" name="nome" class="form-control" placeholder="Ex.: Financeiro" required></div>
                    <div class="col-md-4"><button type="submit" class="btn btn-dark w-100"><i class="bi bi-plus-lg"></i> Criar setor</button></div>
                </form>
                <label class="form-label mt-2 mb-1">Ícone do setor</label>
                <div class="seletor-icone-setor mb-2" data-campo-destino="novoSetorIconePortal">
                    <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                        <button type="button" class="icone-opcao <?= $ic === 'bi-diagram-3' ? 'active' : '' ?>" data-icone="<?= $ic ?>" title="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if (!$setores): ?>
            <p class="text-muted text-center py-4"><i class="bi bi-diagram-3" style="font-size:2rem;"></i><br>Nenhum setor cadastrado ainda.</p>
        <?php endif; ?>

        <?php foreach ($setores as $s): ?>
        <div class="card card-stat mb-2">
            <div class="card-body py-3">
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_setor">
                    <input type="hidden" name="setor_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="icone" id="setorIconePortal<?= $s['id'] ?>" value="<?= e($s['icone'] ?: 'bi-diagram-3') ?>">
                    <div class="col-md-5"><input type="text" name="nome" class="form-control" value="<?= e($s['nome']) ?>" required></div>
                    <div class="col-md-3 form-check ms-2 mb-2">
                        <input type="checkbox" name="ativo" id="setorAtivo<?= $s['id'] ?>" class="form-check-input" value="1" <?= $s['ativo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="setorAtivo<?= $s['id'] ?>">Ativo</label>
                    </div>
                    <div class="col-md-4"><button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-check2"></i> Salvar</button></div>
                </form>
                <div class="seletor-icone-setor mt-2" data-campo-destino="setorIconePortal<?= $s['id'] ?>">
                    <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                        <button type="button" class="icone-opcao <?= $ic === ($s['icone'] ?: 'bi-diagram-3') ? 'active' : '' ?>" data-icone="<?= $ic ?>" title="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- USUÁRIOS -->
    <div class="tab-pane" id="equipeUsuarios">
        <div class="card card-stat mb-3">
            <div class="card-header bg-white fw-bold">Novo usuário</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="criar_usuario_cliente">
                    <input type="hidden" name="avatar" id="novoUsuarioPortalAvatar" value="1">
                    <div class="col-12">
                        <label class="form-label">Avatar (pode trocar depois)</label>
                        <div class="galeria-avatares mb-2" id="galeriaAvatarNovoUsuarioPortal">
                            <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                                <button type="button" class="avatar-opcao <?= $i === 1 ? 'active' : '' ?>" data-avatar="<?= $i ?>" title="Usar este avatar">
                                    <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="col-md-4"><input type="text" name="nome" class="form-control" placeholder="Nome *" required></div>
                    <div class="col-md-4"><input type="email" name="email" class="form-control" placeholder="E-mail *" required></div>
                    <div class="col-md-4"><input type="text" name="telefone" class="form-control input-telefone" placeholder="Telefone (WhatsApp) *" required></div>
                    <div class="col-md-6"><input type="text" name="documento" class="form-control" placeholder="CPF (só números)"></div>
                    <div class="col-md-6">
                        <label class="form-label">Setor(es)</label>
                        <?php if ($setores): ?>
                            <?php foreach ($setores as $s): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="setor_ids[]" value="<?= $s['id'] ?>" id="novoUsuarioPortalSetor<?= $s['id'] ?>">
                                    <label class="form-check-label" for="novoUsuarioPortalSetor<?= $s['id'] ?>"><?= e($s['nome']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted small mb-0">Sem setor cadastrado ainda -- pode deixar em branco por enquanto.</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 form-check">
                        <input type="checkbox" name="eh_master" id="novoUsuarioPortalEhMaster" class="form-check-input" value="1">
                        <label class="form-check-label" for="novoUsuarioPortalEhMaster">Este usuário é MASTER? (vê e gerencia todos os setores, igual a você)</label>
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Cadastrar</button></div>
                </form>
            </div>
        </div>

        <div class="card card-stat">
            <div class="card-body p-2 pb-0">
                <input type="text" class="form-control form-control-sm busca-toolbar" id="buscaUsuariosPortal" placeholder="Buscar por nome, e-mail, telefone ou setor...">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tabela-cards-mobile" id="tabelaUsuariosPortal">
                    <thead><tr><th></th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Setor</th><th>Portal</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($usuariosCliente as $uc):
                        $avatarUc = avatar_html($uc['nome'], resolver_avatar_por_email($pdo, $uc['email'], $uc['foto_perfil']), 'cliente');
                        $ucComAvatar = $uc + ['avatar_html' => $avatarUc];
                        $nomesSetoresUc = array_map(fn ($sid) => $nomesSetoresPorId[$sid] ?? '?', $uc['setor_ids']);
                        $buscaUc = mb_strtolower($uc['nome'] . ' ' . $uc['email'] . ' ' . $uc['telefone'] . ' ' . implode(' ', $nomesSetoresUc));
                    ?>
                        <tr data-busca="<?= e($buscaUc) ?>">
                            <td><?= $avatarUc ?></td>
                            <td class="fw-semibold" data-rotulo="Nome"><?= e($uc['nome']) ?> <?= $uc['eh_master'] ? '<span class="badge bg-primary">Master</span>' : '' ?></td>
                            <td data-rotulo="E-mail"><?= e($uc['email']) ?></td>
                            <td class="telefone-linha" data-rotulo="Telefone"><?= e(formatar_telefone($uc['telefone']) ?: '-') ?></td>
                            <td data-rotulo="Setor">
                                <button type="button" class="btn btn-sm btn-outline-dark botao-editar-setores-usuario-portal w-100 text-start" data-responsavel-id="<?= $uc['id'] ?>" data-nome="<?= e($uc['nome']) ?>" data-setores-atuais='<?= e(json_encode($uc['setor_ids'])) ?>' <?= $setores ? '' : 'disabled title="Cadastre um setor primeiro"' ?>>
                                    <?= $nomesSetoresUc ? e(implode(', ', $nomesSetoresUc)) : 'Sem setor' ?>
                                </button>
                            </td>
                            <td data-rotulo="Portal"><span class="badge bg-<?= $uc['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $uc['portal_ativo'] ? 'Ativo' : 'Sem acesso' ?></span></td>
                            <td class="text-end">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="enviar_convite_setor">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary btn-acao-linha" title="<?= $uc['setor_id'] ? 'Enviar convite de acesso' : 'Relacione a um setor primeiro' ?>" <?= $uc['setor_id'] ? '' : 'disabled' ?>><i class="bi bi-send"></i> Convite</button>
                                    </form>
                                    <?php if ($uc['portal_ativo']): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Isso invalida a senha atual de <?= e($uc['nome']) ?>. Confirma?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="resetar_senha_setor">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-orange btn-acao-linha" title="Resetar senha"><i class="bi bi-key"></i> Resetar</button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="desativar_portal_setor">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger btn-acao-linha" title="Desativar acesso ao portal"><i class="bi bi-slash-circle"></i> Bloquear</button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-warning btn-acao-linha botao-editar-usuario-portal" data-usuario='<?= e(json_encode($ucComAvatar, JSON_UNESCAPED_UNICODE)) ?>' title="Editar"><i class="bi bi-pencil"></i> Editar</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$usuariosCliente): ?>
                        <tr><td colspan="7" class="text-muted text-center py-3">Nenhum usuário cadastrado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Editar usuário -->
<div class="modal fade" id="modalEditarUsuarioPortal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Editar usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
            <div class="text-center mb-3">
                <div class="perfil-avatar d-inline-block" id="editarUsuarioPortalAvatar"></div>
            </div>

            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-2">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="atualizar_avatar_usuario_portal">
                <input type="hidden" name="responsavel_id" class="editar-usuario-portal-id-campo">
                <div class="col"><input type="file" name="foto" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required></div>
                <div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Enviar foto</button></div>
            </form>

            <p class="text-muted small mb-1">Ou escolha um avatar pronto:</p>
            <div class="galeria-avatares mb-4">
                <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="escolher_avatar_predefinido_usuario_portal">
                        <input type="hidden" name="responsavel_id" class="editar-usuario-portal-id-campo">
                        <input type="hidden" name="avatar" value="<?= $i ?>">
                        <button type="submit" class="avatar-opcao" title="Usar este avatar">
                            <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                        </button>
                    </form>
                <?php endfor; ?>
            </div>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="editar_usuario_cliente">
                <input type="hidden" name="responsavel_id" id="editarUsuarioPortalId">
                <div class="mb-3"><label class="form-label">Nome *</label><input type="text" name="nome" id="editarUsuarioPortalNome" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">E-mail *</label><input type="email" name="email" id="editarUsuarioPortalEmail" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Telefone (WhatsApp) *</label><input type="text" name="telefone" id="editarUsuarioPortalTelefone" class="form-control input-telefone" required></div>
                <div class="mb-3"><label class="form-label">CPF</label><input type="text" name="documento" id="editarUsuarioPortalDocumento" class="form-control"></div>
                <div class="mb-3"><label class="form-label">Setor(es)</label>
                    <?php if ($setores): ?>
                        <?php foreach ($setores as $s): ?>
                            <div class="form-check">
                                <input class="form-check-input checkbox-editar-usuario-portal-setor" type="checkbox" name="setor_ids[]" value="<?= $s['id'] ?>" id="editarUsuarioPortalSetor<?= $s['id'] ?>">
                                <label class="form-check-label" for="editarUsuarioPortalSetor<?= $s['id'] ?>"><?= e($s['nome']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Sem setor cadastrado ainda.</p>
                    <?php endif; ?>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" name="eh_master" id="editarUsuarioPortalEhMaster" class="form-check-input" value="1">
                    <label class="form-check-label" for="editarUsuarioPortalEhMaster">Este usuário é MASTER? (vê e gerencia todos os setores, igual a você)</label>
                </div>
                <div class="modal-footer px-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-dark">Salvar</button>
                </div>
            </form>
      </div>
    </div>
  </div>
</div>

<!-- Setor(es) de um usuário -- pedido do Kleber: uma pessoa pode estar em
     mais de um setor ao mesmo tempo agora, mesma tela usada na aba Usuários
     de cliente.php do lado admin. -->
<div class="modal fade" id="modalSetoresUsuarioPortal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Setores de <span id="modalSetoresUsuarioPortalNome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if ($setores): ?>
            <?php foreach ($setores as $s): ?>
            <div class="form-check">
                <input class="form-check-input checkbox-setor-modal-usuario-portal" type="checkbox" value="<?= $s['id'] ?>" id="modalSetorUsuarioPortal<?= $s['id'] ?>">
                <label class="form-check-label" for="modalSetorUsuarioPortal<?= $s['id'] ?>"><?= e($s['nome']) ?></label>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted small mb-0">Cadastre um setor primeiro.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-dark" id="botaoConfirmarSetoresUsuarioPortal">Salvar</button>
      </div>
    </div>
  </div>
</div>

<script>
var buscaUsuariosPortal = document.getElementById('buscaUsuariosPortal');
if (buscaUsuariosPortal) {
    buscaUsuariosPortal.addEventListener('input', function () {
        var termo = buscaUsuariosPortal.value.trim().toLowerCase();
        document.querySelectorAll('#tabelaUsuariosPortal tbody tr[data-busca]').forEach(function (linha) {
            linha.style.display = linha.getAttribute('data-busca').includes(termo) ? '' : 'none';
        });
    });
}

document.querySelectorAll('.botao-editar-usuario-portal').forEach(function (botao) {
    botao.addEventListener('click', function () {
        var dados = JSON.parse(botao.getAttribute('data-usuario'));
        document.getElementById('editarUsuarioPortalId').value = dados.id;
        document.getElementById('editarUsuarioPortalNome').value = dados.nome || '';
        document.getElementById('editarUsuarioPortalEmail').value = dados.email || '';
        document.getElementById('editarUsuarioPortalTelefone').value = dados.telefone ? PrimeUI.formatarTelefone(dados.telefone) : '';
        document.getElementById('editarUsuarioPortalDocumento').value = dados.documento || '';
        var setorIdsAtuaisPortal = (dados.setor_ids || []).map(String);
        document.querySelectorAll('.checkbox-editar-usuario-portal-setor').forEach(function (chk) {
            chk.checked = setorIdsAtuaisPortal.indexOf(chk.value) !== -1;
        });
        document.getElementById('editarUsuarioPortalEhMaster').checked = !!parseInt(dados.eh_master || 0, 10);
        document.getElementById('editarUsuarioPortalAvatar').innerHTML = dados.avatar_html || '';
        document.querySelectorAll('.editar-usuario-portal-id-campo').forEach(function (campo) { campo.value = dados.id; });
        PrimeUI.openModal(document.getElementById('modalEditarUsuarioPortal'));
    });
});

// Seletor de avatar no cadastro de NOVO usuário -- escolhe um avatar pronto
// já na criação (antes só dava pra trocar depois de editar).
var galeriaAvatarNovoPortal = document.getElementById('galeriaAvatarNovoUsuarioPortal');
if (galeriaAvatarNovoPortal) {
    galeriaAvatarNovoPortal.querySelectorAll('.avatar-opcao').forEach(function (botao) {
        botao.addEventListener('click', function () {
            document.getElementById('novoUsuarioPortalAvatar').value = botao.getAttribute('data-avatar');
            galeriaAvatarNovoPortal.querySelectorAll('.avatar-opcao').forEach(function (b) { b.classList.remove('active'); });
            botao.classList.add('active');
        });
    });
}

// Seletor de ícone de setor -- clicar num ícone marca ele como escolhido
// (visualmente) e preenche o input escondido que vai no form de criar/
// atualizar setor (mesma ideia da tela admin, ver cliente.php).
function selecionarIconeSetorPortal(grupo, icone) {
    if (!grupo) return;
    var campo = document.getElementById(grupo.getAttribute('data-campo-destino'));
    if (campo) campo.value = icone;
    grupo.querySelectorAll('.icone-opcao').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-icone') === icone);
    });
}
document.querySelectorAll('.seletor-icone-setor').forEach(function (grupo) {
    grupo.querySelectorAll('.icone-opcao').forEach(function (botaoIcone) {
        botaoIcone.addEventListener('click', function () {
            selecionarIconeSetorPortal(grupo, botaoIcone.getAttribute('data-icone'));
        });
    });
});

// Botão "Setor(es)" de cada linha -- abre o modal de marcar/desmarcar
// setores (uma pessoa pode estar em mais de um agora) e salva tudo de uma
// vez em 'definir_setores_responsavel'.
var responsavelIdSetoresPendentePortal = '';
document.querySelectorAll('.botao-editar-setores-usuario-portal').forEach(function (botao) {
    botao.addEventListener('click', function () {
        responsavelIdSetoresPendentePortal = botao.getAttribute('data-responsavel-id') || '';
        var setorIdsAtuaisUsuarioPortal = (JSON.parse(botao.getAttribute('data-setores-atuais') || '[]')).map(String);
        document.querySelectorAll('.checkbox-setor-modal-usuario-portal').forEach(function (chk) {
            chk.checked = setorIdsAtuaisUsuarioPortal.indexOf(chk.value) !== -1;
        });
        document.getElementById('modalSetoresUsuarioPortalNome').textContent = botao.getAttribute('data-nome') || '';
        PrimeUI.openModal(document.getElementById('modalSetoresUsuarioPortal'));
    });
});

var botaoConfirmarSetoresUsuarioPortal = document.getElementById('botaoConfirmarSetoresUsuarioPortal');
if (botaoConfirmarSetoresUsuarioPortal) {
    botaoConfirmarSetoresUsuarioPortal.addEventListener('click', function () {
        var setorIdsMarcadosPortal = [];
        document.querySelectorAll('.checkbox-setor-modal-usuario-portal:checked').forEach(function (chk) { setorIdsMarcadosPortal.push(chk.value); });

        var formTempPortal = document.createElement('form');
        formTempPortal.method = 'post';
        formTempPortal.action = window.location.href;
        var camposOcultosPortal = '<?= csrf_field() ?>'
            + '<input type="hidden" name="acao" value="definir_setores_responsavel">'
            + '<input type="hidden" name="responsavel_id" value="' + responsavelIdSetoresPendentePortal + '">';
        setorIdsMarcadosPortal.forEach(function (sid) { camposOcultosPortal += '<input type="hidden" name="setor_ids[]" value="' + sid + '">'; });
        formTempPortal.innerHTML = camposOcultosPortal;
        document.body.appendChild(formTempPortal);
        formTempPortal.submit();
    });
}
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
