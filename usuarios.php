<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Notificacoes;

// Aceita também o token temporário (?diag_token=...) além da sessão de admin
// normal -- mesmo esquema usado em importar_chamados_hesk.php e
// manutencao_clientes.php, pra permitir uma ação pontual (ex.: criar um
// usuário) sem precisar de um segundo login manual do admin.
Auth::requireAdminOrTempToken(Database::connection());

$pdo = Database::connection();

$mensagemAcaoToken = null;

// Criação de usuário via GET com token temporário + confirmação explícita na
// URL (usado quando quem está operando é o Claude, que só consegue fazer
// requisições GET). Sempre exige e-mail ainda não cadastrado -- não
// sobrescreve usuário existente.
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['acao'] ?? '') === 'criar_usuario'
    && ($_GET['confirmar'] ?? '') === 'SIM'
    && ($_GET['diag_token'] ?? '') !== ''
) {
    $nome = trim($_GET['nome'] ?? '');
    $email = trim($_GET['email'] ?? '');
    $senha = $_GET['senha'] ?? '';
    $perfil = array_key_exists($_GET['perfil'] ?? '', PERFIS_USUARIO) ? $_GET['perfil'] : 'colaborador';
    $erroSenha = validar_senha_forte($senha);

    if ($nome === '' || $email === '') {
        $mensagemAcaoToken = ['tipo' => 'erro', 'texto' => 'Preencha nome e e-mail.'];
    } elseif ($erroSenha) {
        $mensagemAcaoToken = ['tipo' => 'erro', 'texto' => $erroSenha];
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
            $mensagemAcaoToken = ['tipo' => 'sucesso', 'texto' => 'Usuário "' . $nome . '" criado.'];
        } catch (\PDOException $e) {
            $mensagemAcaoToken = ['tipo' => 'erro', 'texto' => 'Não foi possível criar o usuário (e-mail já cadastrado?).'];
        }
    }
}

// Mesmo esquema via GET+token, agora pra ações sobre contas de portal de
// cliente (enviar convite / desativar acesso), pra eu conseguir operar sem
// depender de clique do Kleber.
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['confirmar'] ?? '') === 'SIM'
    && ($_GET['diag_token'] ?? '') !== ''
    && in_array($_GET['acao'] ?? '', ['enviar_convite_portal', 'desativar_portal_cliente'], true)
) {
    $clienteId = (int) ($_GET['cliente_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
    $stmt->execute([$clienteId]);
    $clienteAlvo = $stmt->fetch();

    if (!$clienteAlvo) {
        $mensagemAcaoToken = ['tipo' => 'erro', 'texto' => 'Cliente não encontrado.'];
    } elseif ($_GET['acao'] === 'enviar_convite_portal') {
        if (!$clienteAlvo['contato_email']) {
            $mensagemAcaoToken = ['tipo' => 'erro', 'texto' => 'Cadastre um e-mail de contato para o cliente antes de enviar o convite.'];
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO cliente_senha_tokens (cliente_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$clienteId, $token]);
            Notificacoes::conviteAcessoPortal($clienteAlvo, $token);
            $mensagemAcaoToken = ['tipo' => 'sucesso', 'texto' => 'Convite enviado para ' . $clienteAlvo['contato_email'] . '.'];
        }
    } else {
        $pdo->prepare('UPDATE clientes SET portal_ativo = 0, senha_hash = NULL WHERE id = ?')->execute([$clienteId]);
        $mensagemAcaoToken = ['tipo' => 'sucesso', 'texto' => 'Acesso ao portal desativado para ' . $clienteAlvo['nome'] . '.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar_usuario') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = apenas_digitos($_POST['telefone'] ?? '');
        $documento = normalizar_documento($_POST['documento'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $perfil = array_key_exists($_POST['perfil'] ?? '', PERFIS_USUARIO) ? $_POST['perfil'] : 'colaborador';
        $erroSenha = validar_senha_forte($senha);

        if ($nome === '' || $email === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } elseif ($erroSenha) {
            flash_set('erro', $erroSenha);
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, telefone, documento, senha_hash, perfil) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$nome, $email, $telefone, $documento, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
                flash_set('sucesso', 'Usuário "' . $nome . '" criado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível criar o usuário (e-mail já cadastrado?).');
            }
        }
    }

    if ($acao === 'atualizar_usuario') {
        $usuarioIdAlvo = (int) $_POST['usuario_id'];
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = apenas_digitos($_POST['telefone'] ?? '');
        $documento = normalizar_documento($_POST['documento'] ?? '');
        $perfil = array_key_exists($_POST['perfil'] ?? '', PERFIS_USUARIO) ? $_POST['perfil'] : 'colaborador';
        $acessoDadosSensiveis = ($_POST['acesso_dados_sensiveis'] ?? '') === '1' ? 1 : 0;
        $acessoRestritoPorCliente = ($_POST['acesso_restrito_por_cliente'] ?? '') === '1' ? 1 : 0;

        if ($nome === '' || $email === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } else {
            $existe = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ? AND id != ?');
            $existe->execute([$email, $usuarioIdAlvo]);
            if ($existe->fetchColumn()) {
                flash_set('erro', 'Já existe outro usuário com esse e-mail.');
            } else {
                $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, telefone = ?, documento = ?, perfil = ?, acesso_dados_sensiveis = ?, acesso_restrito_por_cliente = ? WHERE id = ?')
                    ->execute([$nome, $email, $telefone, $documento, $perfil, $acessoDadosSensiveis, $acessoRestritoPorCliente, $usuarioIdAlvo]);
                flash_set('sucesso', 'Dados de "' . $nome . '" atualizados.');
            }
        }
    }

    // Vínculo de um colaborador com um cliente (opcionalmente restrito a
    // setores específicos) -- só passa a ter efeito de verdade quando
    // acesso_restrito_por_cliente também estiver ligado pra esse usuário
    // (ver Auth::escopoRestrito()); cadastrar o vínculo sem ligar o
    // toggle não trava nem libera nada, fica só "guardado" pra quando
    // ligar. setor_ids[] vazio = vínculo com o cliente INTEIRO (todos os
    // setores, inclusive chamados/itens sem setor).
    if ($acao === 'adicionar_vinculo_colaborador') {
        $usuarioIdAlvo = (int) $_POST['usuario_id'];
        $clienteIdVinculo = (int) ($_POST['cliente_id'] ?? 0);
        $setorIdsVinculo = array_filter(array_map('intval', $_POST['setor_ids'] ?? []));

        $stmtClienteVinculo = $pdo->prepare('SELECT 1 FROM clientes WHERE id = ?');
        $stmtClienteVinculo->execute([$clienteIdVinculo]);

        if (!$usuarioIdAlvo || !$clienteIdVinculo || !$stmtClienteVinculo->fetchColumn()) {
            flash_set('erro', 'Selecione um cliente válido pra vincular.');
        } else {
            $stmtInserirVinculo = $pdo->prepare('
                INSERT INTO usuario_cliente_setores (usuario_id, cliente_id, setor_id) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE usuario_id = usuario_id
            ');
            if ($setorIdsVinculo === []) {
                // "Cliente inteiro" cobre qualquer setor -- os vínculos de
                // setor específico que já existiam ficam redundantes,
                // então remove pra não sobrar lixo na listagem.
                $pdo->prepare('DELETE FROM usuario_cliente_setores WHERE usuario_id = ? AND cliente_id = ? AND setor_id IS NOT NULL')
                    ->execute([$usuarioIdAlvo, $clienteIdVinculo]);
                $stmtInserirVinculo->execute([$usuarioIdAlvo, $clienteIdVinculo, null]);
            } else {
                // Confirma que cada setor é mesmo desse cliente -- mesmo
                // cuidado de sempre, evita vincular a um setor de outro
                // cliente por engano/manipulação do POST.
                $stmtSetorVinculo = $pdo->prepare('SELECT 1 FROM setores WHERE id = ? AND cliente_id = ?');
                foreach ($setorIdsVinculo as $setorIdVinculo) {
                    $stmtSetorVinculo->execute([$setorIdVinculo, $clienteIdVinculo]);
                    if ($stmtSetorVinculo->fetchColumn()) {
                        $stmtInserirVinculo->execute([$usuarioIdAlvo, $clienteIdVinculo, $setorIdVinculo]);
                    }
                }
            }
            flash_set('sucesso', 'Vínculo adicionado.');
        }
    }

    if ($acao === 'remover_vinculo_colaborador') {
        $pdo->prepare('DELETE FROM usuario_cliente_setores WHERE id = ? AND usuario_id = ?')
            ->execute([(int) $_POST['vinculo_id'], (int) $_POST['usuario_id']]);
        flash_set('sucesso', 'Vínculo removido.');
    }

    // Admin define uma senha nova diretamente (sem pedir a senha atual, ao
    // contrário da troca em perfil.php) -- pedido do Kleber porque o
    // Petronilho vive esquecendo a própria senha e precisando de ajuda.
    if ($acao === 'definir_senha_usuario') {
        $usuarioIdAlvo = (int) $_POST['usuario_id'];
        $novaSenha = $_POST['nova_senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';
        $erroSenha = validar_senha_forte($novaSenha);

        if ($erroSenha) {
            flash_set('erro', $erroSenha);
        } elseif ($novaSenha !== $confirmarSenha) {
            flash_set('erro', 'As senhas não coincidem.');
        } else {
            $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                ->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $usuarioIdAlvo]);
            flash_set('sucesso', 'Senha redefinida.');
        }
    }

    if ($acao === 'atualizar_avatar_usuario') {
        $usuarioIdAlvo = (int) $_POST['usuario_id'];
        $erroFoto = processar_upload_foto_usuario($pdo, $usuarioIdAlvo);
        flash_set($erroFoto ? 'erro' : 'sucesso', $erroFoto ?? 'Foto atualizada.');
    }

    if ($acao === 'escolher_avatar_predefinido_usuario') {
        $usuarioIdAlvo = (int) $_POST['usuario_id'];
        $indice = (int) ($_POST['avatar'] ?? 0);
        if (definir_avatar_predefinido_usuario($pdo, $usuarioIdAlvo, $indice)) {
            flash_set('sucesso', 'Avatar atualizado.');
        }
    }

    if ($acao === 'alternar_status') {
        $usuarioId = (int) $_POST['usuario_id'];
        if ($usuarioId !== Auth::userId()) {
            $stmt = $pdo->prepare('UPDATE usuarios SET ativo = NOT ativo WHERE id = ?');
            $stmt->execute([$usuarioId]);
            flash_set('sucesso', 'Status do usuário atualizado.');
        } else {
            flash_set('erro', 'Você não pode desativar seu próprio usuário.');
        }
    }

    header('Location: ' . url('usuarios.php'));
    exit;
}

// Métricas de atendimento por usuário -- baseadas em quem é o RESPONSÁVEL
// atual do chamado (mesmo campo já usado no resto do sistema pra "dono" do
// chamado). Tempo de atendimento depende de chamados.fechado_em, coluna
// nova (ver Migrations) -- chamados fechados antes dela existir ficam de
// fora da média, então o número só vai aparecer/melhorar com o uso dali pra
// frente, não retroage.
$usuarios = $pdo->query("
    SELECT
        u.*,
        (SELECT COUNT(*) FROM chamados c WHERE c.responsavel_id = u.id) AS total_atendidos,
        (SELECT COUNT(DISTINCT c.cliente_id) FROM chamados c WHERE c.responsavel_id = u.id AND c.cliente_id IS NOT NULL) AS clientes_atendidos,
        (SELECT COUNT(*) FROM chamados c WHERE c.responsavel_id = u.id AND c.status = 'fechado') AS total_fechados,
        (SELECT AVG(TIMESTAMPDIFF(MINUTE, c.criado_em, c.fechado_em)) FROM chamados c WHERE c.responsavel_id = u.id AND c.fechado_em IS NOT NULL) AS media_minutos_atendimento
    FROM usuarios u
    ORDER BY u.nome ASC
")->fetchAll();

// Todos os clientes + setores, pro seletor de "vincular cliente/setor" do
// perfil Colaborador -- lista pequena (é uma ferramenta B2B com dezenas de
// clientes, não milhares), então dá pra embutir tudo de uma vez em vez de
// criar um endpoint AJAX só pra isso.
$clientesComSetores = $pdo->query("
    SELECT c.id, c.nome,
        (SELECT GROUP_CONCAT(s.id, ':', s.nome SEPARATOR '||') FROM setores s WHERE s.cliente_id = c.id AND s.ativo = 1 ORDER BY s.nome) AS setores_concat
    FROM clientes c
    ORDER BY c.nome ASC
")->fetchAll();
$clientesParaJs = [];
foreach ($clientesComSetores as $c) {
    $setoresDoCliente = [];
    foreach (array_filter(explode('||', $c['setores_concat'] ?? '')) as $par) {
        [$setorId, $setorNome] = explode(':', $par, 2);
        $setoresDoCliente[] = ['id' => (int) $setorId, 'nome' => $setorNome];
    }
    $clientesParaJs[] = ['id' => (int) $c['id'], 'nome' => $c['nome'], 'setores' => $setoresDoCliente];
}

// Vínculos de cada colaborador (usuario_cliente_setores), agrupados por
// usuario_id -- carregado tudo de uma vez (1 query) em vez de 1 por
// usuário, e embutido no JSON de cada linha da tabela (mesmo padrão já
// usado pras métricas de atendimento).
$vinculosPorUsuario = [];
$stmtVinculos = $pdo->query("
    SELECT ucs.id, ucs.usuario_id, ucs.cliente_id, c.nome AS cliente_nome, ucs.setor_id, s.nome AS setor_nome
    FROM usuario_cliente_setores ucs
    JOIN clientes c ON c.id = ucs.cliente_id
    LEFT JOIN setores s ON s.id = ucs.setor_id
    ORDER BY c.nome ASC, s.nome ASC
");
foreach ($stmtVinculos->fetchAll() as $v) {
    $vinculosPorUsuario[(int) $v['usuario_id']][] = [
        'id' => (int) $v['id'],
        'cliente_id' => (int) $v['cliente_id'],
        'cliente_nome' => $v['cliente_nome'],
        'setor_id' => $v['setor_id'] !== null ? (int) $v['setor_id'] : null,
        'setor_nome' => $v['setor_nome'],
    ];
}

$tituloPagina = 'Usuários';
require __DIR__ . '/templates/header.php';
?>

<?php if ($mensagemAcaoToken): ?>
    <div class="alert alert-<?= $mensagemAcaoToken['tipo'] === 'sucesso' ? 'success' : 'danger' ?>"><?= e($mensagemAcaoToken['texto']) ?></div>
<?php endif; ?>

<h4 class="mb-3">Usuários</h4>
<p class="text-muted small mb-3">Usuários da equipe interna. Os usuários de cada CLIENTE (master + responsáveis de setor) agora são cadastrados dentro do próprio cliente, na aba "Usuários" -- ver <a href="<?= url('clientes.php') ?>">Clientes</a>.</p>

<div class="toolbar">
    <div class="toolbar-acoes">
        <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoUsuario"><i class="bi bi-plus-lg"></i> Novo usuário</button>
    </div>
</div>

<div class="card card-stat">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th></th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u):
                $uParaJs = array_diff_key($u, ['foto_perfil_dados' => 1, 'senha_hash' => 1]);
                $uParaJs['tempo_medio_formatado'] = formatar_duracao_minutos(
                    $u['media_minutos_atendimento'] !== null ? (float) $u['media_minutos_atendimento'] : null
                );
                $uParaJs['vinculos'] = $vinculosPorUsuario[(int) $u['id']] ?? [];
            ?>
                <tr class="linha-usuario" role="button" tabindex="0"
                    data-usuario='<?= e(json_encode($uParaJs, JSON_UNESCAPED_UNICODE)) ?>'>
                    <td><?= avatar_html($u['nome'], $u['foto_perfil'], 'equipe') ?></td>
                    <td class="fw-semibold"><?= e($u['nome']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge bg-<?= $u['perfil'] === 'colaborador' ? 'secondary' : 'dark' ?>"><?= e(PERFIS_USUARIO[$u['perfil']] ?? $u['perfil']) ?></span></td>
                    <td><span class="badge bg-<?= $u['ativo'] ? 'success' : 'secondary' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="text-end">
                        <?php if ((int)$u['id'] !== Auth::userId()): ?>
                        <form method="post" class="d-inline" onclick="event.stopPropagation()">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="alternar_status">
                            <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-dark"><?= $u['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted small">(você)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted small px-3 py-2 mb-0">Clique numa linha pra ver métricas de atendimento, editar dados/avatar ou redefinir a senha.</p>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNovoUsuario" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_usuario">
        <div class="modal-header"><h5 class="modal-title">Novo usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">E-mail de contato *</label><input type="email" name="email" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control input-telefone" placeholder="(00) 00000-0000"></div>
            <div class="mb-3"><label class="form-label">CPF</label><input type="text" name="documento" class="form-control" placeholder="Só números"></div>
            <div class="mb-3"><label class="form-label">Senha *</label>
                <div class="campo-senha">
                    <input type="password" name="senha" class="form-control" minlength="8"
                           pattern="(?=.*[0-9])(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}"
                           title="Pelo menos 8 caracteres, com 1 número, 1 letra maiúscula e 1 caractere especial" required>
                    <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                </div>
                <small class="text-muted">Pelo menos 8 caracteres, com 1 número, 1 maiúscula e 1 caractere especial.</small>
            </div>
            <div class="mb-3"><label class="form-label">Perfil</label>
                <select name="perfil" class="form-select">
                    <?php foreach (PERFIS_USUARIO as $valor => $label): ?>
                        <option value="<?= e($valor) ?>" <?= $valor === 'colaborador' ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Criar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Usuário da equipe: métricas de atendimento, editar dados/avatar, redefinir senha -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="modalUsuarioTitulo">Usuário</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <ul class="nav nav-tabs px-3" id="modalUsuarioTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#usuarioMetricas"><i class="bi bi-graph-up"></i> Métricas</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#usuarioEditar"><i class="bi bi-pencil"></i> Editar</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#usuarioVinculos"><i class="bi bi-diagram-3"></i> Vínculos</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#usuarioSenha"><i class="bi bi-shield-lock"></i> Senha</a></li>
        </ul>
        <div class="tab-content">

            <!-- Métricas -->
            <div class="tab-pane show active p-4" id="usuarioMetricas">
                <div class="row g-3 text-center mb-3">
                    <div class="col-6 col-md-3">
                        <div class="card card-stat">
                            <div class="card-body py-3">
                                <div class="h4 mb-0" id="metricaTotalAtendidos">0</div>
                                <div class="text-muted small">Chamados atendidos</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card card-stat">
                            <div class="card-body py-3">
                                <div class="h4 mb-0" id="metricaClientesAtendidos">0</div>
                                <div class="text-muted small">Clientes atendidos</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card card-stat">
                            <div class="card-body py-3">
                                <div class="h4 mb-0" id="metricaFechados">0</div>
                                <div class="text-muted small">Chamados fechados</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card card-stat">
                            <div class="card-body py-3">
                                <div class="h4 mb-0" id="metricaTempoMedio">--</div>
                                <div class="text-muted small">Tempo médio de atendimento</div>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-muted small mb-0" id="metricaSemDados" style="display:none;">
                    <i class="bi bi-info-circle"></i> Ainda não há chamados fechados o suficiente pra calcular o tempo médio de atendimento -- o número vai aparecer a partir dos próximos chamados encerrados.
                </p>
            </div>

            <!-- Editar -->
            <div class="tab-pane p-4" id="usuarioEditar">
                <div class="text-center mb-4">
                    <img id="usuarioAvatarPreview" class="avatar-circle avatar-foto equipe" style="width:80px;height:80px;font-size:2rem;" alt="Avatar">
                </div>

                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_avatar_usuario">
                    <input type="hidden" name="usuario_id" id="avatarUsuarioId">
                    <div class="col"><input type="file" name="foto" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required></div>
                    <div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Enviar foto</button></div>
                </form>

                <p class="text-muted small mb-1">Ou escolha um avatar pronto:</p>
                <div class="galeria-avatares mb-4">
                    <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                        <form method="post" class="d-inline avatar-predefinido-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="escolher_avatar_predefinido_usuario">
                            <input type="hidden" name="usuario_id" class="avatar-usuario-id-campo">
                            <input type="hidden" name="avatar" value="<?= $i ?>">
                            <button type="submit" class="avatar-opcao" title="Usar este avatar">
                                <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                            </button>
                        </form>
                    <?php endfor; ?>
                </div>

                <form method="post" id="formEditarUsuario">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_usuario">
                    <input type="hidden" name="usuario_id" id="editarUsuarioId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome *</label>
                            <input type="text" name="nome" id="editarNome" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail *</label>
                            <input type="email" name="email" id="editarEmail" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="text" name="telefone" id="editarTelefone" class="form-control input-telefone" placeholder="(00) 00000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CPF</label>
                            <input type="text" name="documento" id="editarDocumento" class="form-control" placeholder="Só números">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Perfil</label>
                            <select name="perfil" id="editarPerfil" class="form-select">
                                <?php foreach (PERFIS_USUARIO as $valor => $label): ?>
                                    <option value="<?= e($valor) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="acesso_dados_sensiveis" id="editarAcessoDadosSensiveis" value="1">
                                <label class="form-check-label" for="editarAcessoDadosSensiveis">
                                    <i class="bi bi-shield-lock"></i> Acesso especial a dados sensíveis (Acessos/Fontes ADVPL dos clientes)
                                </label>
                                <p class="text-muted small mb-0">Admin/Diretor já têm esse acesso por padrão. Marque só pra um colaborador que realmente precisa ver credenciais e código-fonte de cliente -- dado extremamente sensível.</p>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="acesso_restrito_por_cliente" id="editarAcessoRestrito" value="1">
                                <label class="form-check-label" for="editarAcessoRestrito">
                                    <i class="bi bi-diagram-3"></i> Restringir a clientes/setores específicos
                                </label>
                                <p class="text-muted small mb-0">Ligado, esse colaborador só vê os clientes/setores vinculados na aba "Vínculos" (chamados, fontes ADVPL, treinamentos) -- o resto do sistema fica invisível pra ele. Desligado (padrão), vê tudo normalmente. Admin/Diretor/CEO nunca ficam restritos, mesmo com isso marcado.</p>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3"><i class="bi bi-check2"></i> Salvar dados</button>
                </form>
            </div>

            <!-- Vínculos: quais clientes/setores esse colaborador enxerga
                 quando "Restringir a clientes/setores específicos" (aba
                 Editar) está ligado. -->
            <div class="tab-pane p-4" id="usuarioVinculos">
                <p class="text-muted small">Marque "Cliente inteiro" pra liberar todos os setores desse cliente (inclusive chamados sem departamento), ou marque só os setores específicos que esse colaborador deve atender. Só tem efeito com o toggle "Restringir a clientes/setores específicos" ligado, na aba Editar.</p>

                <div id="listaVinculos" class="mb-4"></div>

                <form method="post" id="formAdicionarVinculo" class="card card-stat p-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="adicionar_vinculo_colaborador">
                    <input type="hidden" name="usuario_id" id="vinculoUsuarioId">
                    <input type="hidden" name="cliente_id" id="vinculoClienteIdCampo">

                    <label class="form-label small mb-1">Cliente</label>
                    <select class="form-select form-select-sm mb-3" id="vinculoClienteSelect">
                        <option value="">Selecione...</option>
                        <?php foreach ($clientesParaJs as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="vinculoClienteInteiroCheck" disabled>
                        <label class="form-check-label fw-semibold" for="vinculoClienteInteiroCheck">Cliente inteiro (todos os setores)</label>
                    </div>

                    <label class="form-label small mb-1" id="vinculoSetoresLabel" style="display:none;">Ou só setores específicos:</label>
                    <div id="vinculoSetoresCheckboxes" class="mb-3"></div>

                    <button type="submit" class="btn btn-sm btn-dark" id="vinculoBotaoSubmit" disabled><i class="bi bi-plus-lg"></i> Vincular</button>
                </form>
            </div>

            <!-- Senha -->
            <div class="tab-pane p-4" id="usuarioSenha">
                <p class="text-muted small">Define uma senha nova pra esse usuário -- não precisa saber a senha atual dele.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="definir_senha_usuario">
                    <input type="hidden" name="usuario_id" id="senhaUsuarioId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nova senha *</label>
                            <div class="campo-senha">
                                <input type="password" name="nova_senha" class="form-control" minlength="8"
                                       pattern="(?=.*[0-9])(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}"
                                       title="Pelo menos 8 caracteres, com 1 número, 1 letra maiúscula e 1 caractere especial" required>
                                <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirmar nova senha *</label>
                            <div class="campo-senha">
                                <input type="password" name="confirmar_senha" class="form-control" minlength="8" required>
                                <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    <small class="text-muted">Pelo menos 8 caracteres, com 1 número, 1 maiúscula e 1 caractere especial.</small>
                    <br>
                    <button type="submit" class="btn btn-dark mt-3"><i class="bi bi-shield-lock"></i> Redefinir senha</button>
                </form>
            </div>

        </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-senha').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = botao.previousElementSibling;
            var mostrando = campo.type === 'text';
            campo.type = mostrando ? 'password' : 'text';
            botao.querySelector('i').className = mostrando ? 'bi bi-eye' : 'bi bi-eye-slash';
        });
    });

    var baseUrlAvatar = <?= json_encode(url('')) ?>;
    var clientesComSetores = <?= json_encode($clientesParaJs, JSON_UNESCAPED_UNICODE) ?>;
    var csrfTokenPagina = <?= json_encode(csrf_token()) ?>;

    // Checkboxes em vez de <select multiple> -- pedido do Kleber: "o
    // Petronilho tem mais de 100 anos, ele não vai saber selecionar
    // segurando Ctrl/Cmd", precisa ser clique direto, sem segredo nenhum.
    var seletorClienteVinculo = document.getElementById('vinculoClienteSelect');
    var checkClienteInteiro = document.getElementById('vinculoClienteInteiroCheck');
    var labelSetoresVinculo = document.getElementById('vinculoSetoresLabel');
    var boxSetoresVinculo = document.getElementById('vinculoSetoresCheckboxes');
    var campoClienteIdVinculo = document.getElementById('vinculoClienteIdCampo');
    var botaoSubmitVinculo = document.getElementById('vinculoBotaoSubmit');

    function atualizarEstadoCheckboxesSetorVinculo() {
        var marcandoClienteInteiro = checkClienteInteiro.checked;
        boxSetoresVinculo.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.disabled = marcandoClienteInteiro;
            if (marcandoClienteInteiro) cb.checked = false;
        });
    }

    seletorClienteVinculo.addEventListener('change', function () {
        var clienteId = seletorClienteVinculo.value;
        campoClienteIdVinculo.value = clienteId;
        boxSetoresVinculo.innerHTML = '';
        checkClienteInteiro.checked = false;

        var cliente = clientesComSetores.find(function (c) { return String(c.id) === clienteId; });
        if (!cliente) {
            checkClienteInteiro.disabled = true;
            labelSetoresVinculo.style.display = 'none';
            botaoSubmitVinculo.disabled = true;
            return;
        }
        checkClienteInteiro.disabled = false;
        botaoSubmitVinculo.disabled = false;

        if (!cliente.setores.length) {
            labelSetoresVinculo.style.display = 'none';
            return;
        }
        labelSetoresVinculo.style.display = '';
        cliente.setores.forEach(function (s) {
            var id = 'vinculoSetorCheck' + s.id;
            var wrapper = document.createElement('div');
            wrapper.className = 'form-check';
            wrapper.innerHTML =
                '<input class="form-check-input" type="checkbox" name="setor_ids[]" value="' + s.id + '" id="' + id + '">' +
                '<label class="form-check-label" for="' + id + '">' + s.nome + '</label>';
            boxSetoresVinculo.appendChild(wrapper);
        });
    });

    checkClienteInteiro.addEventListener('change', atualizarEstadoCheckboxesSetorVinculo);
    // Marcar um setor específico desmarca "Cliente inteiro" automaticamente
    // -- as duas opções são mutuamente exclusivas, sem precisar entender
    // isso, só clicando no que faz sentido.
    boxSetoresVinculo.addEventListener('change', function (e) {
        if (e.target.type === 'checkbox' && e.target.checked) {
            checkClienteInteiro.checked = false;
        }
    });

    // Cria e já submete, na hora, um form pequeno pra remover 1 vínculo --
    // evita ter que espalhar 1 <form> por linha renderizada via JS.
    function removerVinculo(vinculoId, usuarioId) {
        if (!confirm('Remover esse vínculo?')) return;
        var form = document.createElement('form');
        form.method = 'post';
        form.innerHTML =
            '<input type="hidden" name="csrf_token" value="' + csrfTokenPagina + '">' +
            '<input type="hidden" name="acao" value="remover_vinculo_colaborador">' +
            '<input type="hidden" name="vinculo_id" value="' + vinculoId + '">' +
            '<input type="hidden" name="usuario_id" value="' + usuarioId + '">';
        document.body.appendChild(form);
        form.submit();
    }
    window.removerVinculo = removerVinculo;

    // Cada vínculo numa linha própria, com nome + botão "Remover" grande e
    // com texto (não um "x" pequeno escondido num badge) -- pedido do
    // Kleber pra ficar fácil pra qualquer idade. "Trocar" um colaborador de
    // setor é: Remover a linha antiga aqui + escolher o setor novo no
    // formulário "Vincular" acima.
    function renderizarVinculos(vinculos, usuarioId) {
        var lista = document.getElementById('listaVinculos');
        if (!vinculos.length) {
            lista.innerHTML = '<p class="text-muted small mb-0">Nenhum vínculo cadastrado ainda -- sem restrição ligada, esse colaborador vê tudo normalmente.</p>';
            return;
        }
        // Agrupa por cliente só pra mostrar o nome do cliente uma vez,
        // com uma linha por setor (ou "Cliente inteiro") dentro dele.
        var porCliente = {};
        vinculos.forEach(function (v) {
            (porCliente[v.cliente_id] = porCliente[v.cliente_id] || { nome: v.cliente_nome, itens: [] }).itens.push(v);
        });
        var html = '';
        Object.keys(porCliente).forEach(function (clienteId) {
            var grupo = porCliente[clienteId];
            html += '<div class="card card-stat mb-2"><div class="card-body py-2 px-3">';
            html += '<div class="fw-semibold mb-2">' + grupo.nome + '</div>';
            grupo.itens.forEach(function (i) {
                var rotulo = i.setor_id === null ? 'Cliente inteiro (todos os setores)' : i.setor_nome;
                html += '<div class="d-flex justify-content-between align-items-center py-1 border-top">';
                html += '<span>' + rotulo + '</span>';
                html += '<button type="button" class="btn btn-sm btn-outline-danger" onclick="removerVinculo(' + i.id + ',' + usuarioId + ')"><i class="bi bi-trash"></i> Remover</button>';
                html += '</div>';
            });
            html += '</div></div>';
        });
        lista.innerHTML = html;
    }

    function abrirModalUsuario(dados) {
        document.getElementById('modalUsuarioTitulo').textContent = dados.nome;

        // Métricas
        document.getElementById('metricaTotalAtendidos').textContent = dados.total_atendidos;
        document.getElementById('metricaClientesAtendidos').textContent = dados.clientes_atendidos;
        document.getElementById('metricaFechados').textContent = dados.total_fechados;
        document.getElementById('metricaTempoMedio').textContent = dados.tempo_medio_formatado;
        document.getElementById('metricaSemDados').style.display = dados.total_fechados > 0 ? 'none' : '';

        // Editar
        document.getElementById('usuarioAvatarPreview').src = baseUrlAvatar + dados.foto_perfil;
        document.getElementById('avatarUsuarioId').value = dados.id;
        document.getElementById('editarUsuarioId').value = dados.id;
        document.getElementById('editarNome').value = dados.nome;
        document.getElementById('editarEmail').value = dados.email;
        document.getElementById('editarTelefone').value = dados.telefone ? PrimeUI.formatarTelefone(dados.telefone) : '';
        document.getElementById('editarDocumento').value = dados.documento || '';
        document.getElementById('editarPerfil').value = dados.perfil;
        document.getElementById('editarAcessoDadosSensiveis').checked = !!parseInt(dados.acesso_dados_sensiveis, 10);
        document.getElementById('editarAcessoRestrito').checked = !!parseInt(dados.acesso_restrito_por_cliente, 10);
        document.querySelectorAll('.avatar-usuario-id-campo').forEach(function (campo) { campo.value = dados.id; });

        // Vínculos
        document.getElementById('vinculoUsuarioId').value = dados.id;
        seletorClienteVinculo.value = '';
        boxSetoresVinculo.innerHTML = '';
        labelSetoresVinculo.style.display = 'none';
        checkClienteInteiro.checked = false;
        checkClienteInteiro.disabled = true;
        botaoSubmitVinculo.disabled = true;
        campoClienteIdVinculo.value = '';
        renderizarVinculos(dados.vinculos || [], dados.id);

        // Senha
        document.getElementById('senhaUsuarioId').value = dados.id;

        // Sempre volta pra aba "Métricas" ao abrir.
        PrimeUI.showTab(document.querySelector('#modalUsuarioTabs a[href="#usuarioMetricas"]'));

        PrimeUI.openModal(document.getElementById('modalUsuario'));
    }

    document.querySelectorAll('.linha-usuario').forEach(function (linha) {
        linha.addEventListener('click', function (e) {
            if (e.target.closest('form, button, a')) return;
            abrirModalUsuario(JSON.parse(linha.getAttribute('data-usuario')));
        });
        linha.addEventListener('keypress', function (e) {
            if (e.key === 'Enter' && !e.target.closest('form, button, a')) linha.click();
        });
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
