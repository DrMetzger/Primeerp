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
 */

ClienteAuth::requireLogin();
ClienteAuth::exigirMaster();

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
        if ($nomeSetor === '') {
            flash_set('erro', 'Informe o nome do setor.');
        } else {
            $pdo->prepare('INSERT INTO setores (cliente_id, nome, icone) VALUES (?, ?, ?)')
                ->execute([$clienteId, $nomeSetor, $_POST['icone'] ?: null]);
            flash_set('sucesso', 'Setor "' . $nomeSetor . '" criado.');
        }
    }

    if ($acao === 'atualizar_setor') {
        $setorId = (int) $_POST['setor_id'];
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '') {
            flash_set('erro', 'Informe o nome do setor.');
        } else {
            $pdo->prepare('UPDATE setores SET nome = ?, icone = ?, ativo = ? WHERE id = ? AND cliente_id = ?')
                ->execute([$nomeSetor, $_POST['icone'] ?: null, isset($_POST['ativo']) ? 1 : 0, $setorId, $clienteId]);
            flash_set('sucesso', 'Setor atualizado.');
        }
    }

    // Mesma regra da tela admin: exclusão não é destrutiva pros chamados nem
    // pras pessoas -- só perdem o vínculo com esse setor, continuam salvos.
    if ($acao === 'excluir_setor') {
        $setorId = (int) $_POST['setor_id'];
        $confereSetor = $pdo->prepare('SELECT 1 FROM setores WHERE id = ? AND cliente_id = ?');
        $confereSetor->execute([$setorId, $clienteId]);
        if ($confereSetor->fetchColumn()) {
            $pdo->prepare('UPDATE chamados SET setor_id = NULL WHERE setor_id = ?')->execute([$setorId]);
            $pdo->prepare('UPDATE setor_responsaveis SET setor_id = NULL WHERE setor_id = ?')->execute([$setorId]);
            if ((int) $cliente['setor_id'] === $setorId) {
                $pdo->prepare('UPDATE clientes SET setor_id = NULL WHERE id = ?')->execute([$clienteId]);
            }
            $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);
            flash_set('sucesso', 'Setor excluído. Os chamados e pessoas dele continuam salvos, só sem setor vinculado.');
        }
    }

    if ($acao === 'criar_usuario_cliente') {
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');

        if ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } else {
            $telefoneResp = apenas_digitos($_POST['telefone'] ?? '');
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $setorIdResp = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
            try {
                $pdo->prepare('INSERT INTO setor_responsaveis (cliente_id, setor_id, nome, email, telefone, documento) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$clienteId, $setorIdResp, $nomeResp, $emailResp, $telefoneResp, $documentoResp]);
                flash_set('sucesso', 'Usuário cadastrado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível cadastrar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    if ($acao === 'editar_usuario_cliente') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } elseif ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } else {
            $telefoneResp = apenas_digitos($_POST['telefone'] ?? '');
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $setorIdResp = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
            try {
                $pdo->prepare('UPDATE setor_responsaveis SET nome=?, email=?, telefone=?, documento=?, setor_id=? WHERE id=?')
                    ->execute([$nomeResp, $emailResp, $telefoneResp, $documentoResp, $setorIdResp, $responsavelId]);
                flash_set('sucesso', 'Usuário atualizado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível salvar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    if ($acao === 'excluir_usuario_cliente') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $pdo->prepare('DELETE FROM setor_responsaveis WHERE id = ? AND cliente_id = ?')->execute([$responsavelId, $clienteId]);
        flash_set('sucesso', 'Usuário removido.');
    }

    if ($acao === 'relacionar_responsavel_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $novoSetorId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            $pdo->prepare('UPDATE setor_responsaveis SET setor_id = ? WHERE id = ?')->execute([$novoSetorId, $responsavelId]);
            flash_set('sucesso', $novoSetorId ? 'Usuário relacionado ao setor.' : 'Usuário desvinculado do setor.');
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

$tituloPagina = 'Setores e Usuários';
require __DIR__ . '/../templates/portal_header.php';
?>

<h4 class="mb-1">Setores e Usuários</h4>
<p class="text-muted small mb-4">Cadastre os setores da empresa e as pessoas de cada um, e relacione uma coisa à outra. Depois de relacionar a um setor, dá pra mandar o convite de acesso ao portal pra pessoa.</p>

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
                    <div class="col-md-5"><label class="form-label">Nome do novo setor</label><input type="text" name="nome" class="form-control" placeholder="Ex.: Financeiro" required></div>
                    <div class="col-md-5">
                        <label class="form-label">Ícone (opcional)</label>
                        <select name="icone" class="form-select">
                            <option value="">-</option>
                            <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                                <option value="<?= $ic ?>"><?= $ic ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><button type="submit" class="btn btn-dark w-100"><i class="bi bi-plus-lg"></i> Criar</button></div>
                </form>
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
                    <div class="col-md-4"><input type="text" name="nome" class="form-control" value="<?= e($s['nome']) ?>" required></div>
                    <div class="col-md-4">
                        <select name="icone" class="form-select">
                            <option value="">-</option>
                            <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                                <option value="<?= $ic ?>" <?= $s['icone'] === $ic ? 'selected' : '' ?>><?= $ic ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 form-check mb-2">
                        <input type="checkbox" name="ativo" id="setorAtivo<?= $s['id'] ?>" class="form-check-input" value="1" <?= $s['ativo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="setorAtivo<?= $s['id'] ?>">Ativo</label>
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-outline-dark flex-grow-1"><i class="bi bi-check2"></i></button>
                    </div>
                </form>
                <form method="post" class="mt-2" onsubmit="return confirm('Excluir o setor \'<?= e($s['nome']) ?>\'? Chamados e pessoas dele continuam salvos, só perdem o vínculo.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="excluir_setor">
                    <input type="hidden" name="setor_id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i> Excluir setor</button>
                </form>
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
                    <div class="col-md-4"><input type="text" name="nome" class="form-control" placeholder="Nome *" required></div>
                    <div class="col-md-4"><input type="email" name="email" class="form-control" placeholder="E-mail *" required></div>
                    <div class="col-md-4"><input type="text" name="telefone" class="form-control input-telefone" placeholder="Telefone"></div>
                    <div class="col-md-6"><input type="text" name="documento" class="form-control" placeholder="CPF (só números)"></div>
                    <div class="col-md-6">
                        <select name="setor_id" class="form-select">
                            <option value="">Sem setor por enquanto</option>
                            <?php foreach ($setores as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Cadastrar</button></div>
                </form>
            </div>
        </div>

        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th></th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Setor</th><th>Portal</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($usuariosCliente as $uc): ?>
                        <tr>
                            <td><?= avatar_html($uc['nome'], resolver_avatar_por_email($pdo, $uc['email'], $uc['foto_perfil']), 'cliente') ?></td>
                            <td class="fw-semibold"><?= e($uc['nome']) ?></td>
                            <td><?= e($uc['email']) ?></td>
                            <td><?= e(formatar_telefone($uc['telefone']) ?: '-') ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="relacionar_responsavel_setor">
                                    <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                    <select name="setor_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="">Sem setor</option>
                                        <?php foreach ($setores as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= (int) $uc['setor_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><span class="badge bg-<?= $uc['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $uc['portal_ativo'] ? 'Ativo' : 'Sem acesso' ?></span></td>
                            <td class="text-end">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="enviar_convite_setor">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-dark" title="<?= $uc['setor_id'] ? '' : 'Relacione a um setor primeiro' ?>" <?= $uc['setor_id'] ? '' : 'disabled' ?>><i class="bi bi-send"></i></button>
                                    </form>
                                    <?php if ($uc['portal_ativo']): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Isso invalida a senha atual de <?= e($uc['nome']) ?>. Confirma?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="resetar_senha_setor">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-dark" title="Resetar senha"><i class="bi bi-key"></i></button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="desativar_portal_setor">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Desativar acesso"><i class="bi bi-slash-circle"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-dark botao-editar-usuario-portal" data-usuario='<?= e(json_encode($uc, JSON_UNESCAPED_UNICODE)) ?>' title="Editar"><i class="bi bi-pencil"></i></button>
                                    <form method="post" class="d-inline formExcluirUsuarioPortal" onsubmit="return confirm('Remover <?= e($uc['nome']) ?>? Essa ação não pode ser desfeita.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="acao" value="excluir_usuario_cliente">
                                        <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                    </form>
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
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="editar_usuario_cliente">
        <input type="hidden" name="responsavel_id" id="editarUsuarioPortalId">
        <div class="modal-header"><h5 class="modal-title">Editar usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome *</label><input type="text" name="nome" id="editarUsuarioPortalNome" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">E-mail *</label><input type="email" name="email" id="editarUsuarioPortalEmail" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Telefone</label><input type="text" name="telefone" id="editarUsuarioPortalTelefone" class="form-control input-telefone"></div>
            <div class="mb-3"><label class="form-label">CPF</label><input type="text" name="documento" id="editarUsuarioPortalDocumento" class="form-control"></div>
            <div class="mb-3"><label class="form-label">Setor</label>
                <select name="setor_id" id="editarUsuarioPortalSetor" class="form-select">
                    <option value="">Sem setor</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.botao-editar-usuario-portal').forEach(function (botao) {
    botao.addEventListener('click', function () {
        var dados = JSON.parse(botao.getAttribute('data-usuario'));
        document.getElementById('editarUsuarioPortalId').value = dados.id;
        document.getElementById('editarUsuarioPortalNome').value = dados.nome || '';
        document.getElementById('editarUsuarioPortalEmail').value = dados.email || '';
        document.getElementById('editarUsuarioPortalTelefone').value = dados.telefone ? PrimeUI.formatarTelefone(dados.telefone) : '';
        document.getElementById('editarUsuarioPortalDocumento').value = dados.documento || '';
        document.getElementById('editarUsuarioPortalSetor').value = dados.setor_id || '';
        PrimeUI.openModal(document.getElementById('modalEditarUsuarioPortal'));
    });
});
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
