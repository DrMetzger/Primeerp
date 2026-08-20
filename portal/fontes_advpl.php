<?php

/**
 * Fontes ADVPL do portal -- só pro MASTER (contato principal): dado
 * extremamente sensível (código-fonte proprietário do cliente), mesmo
 * nível de sigilo do cofre de acessos em cliente.php. Diferente de vídeo
 * aula (portal/videoaulas.php), aqui não existe fallback por setor -- login
 * de setor específico não vê essa página de jeito nenhum, só o master.
 *
 * Master pode CADASTRAR e EDITAR fontes (pedido do Kleber) -- mas NUNCA
 * excluir, isso continua exclusivo da equipe (cliente.php). Toda
 * criação/edição feita por aqui é registrada no log de acesso sensível
 * igual um download, porque é um cliente (não a equipe) escrevendo em cima
 * de código-fonte proprietário.
 */

require_once __DIR__ . '/../bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;

ClienteAuth::requireLogin();

if (!ClienteAuth::temPermissaoMaster()) {
    http_response_code(403);
    die('Acesso restrito ao contato master.');
}

$pdo = Database::connection();
$clienteId = ClienteAuth::clienteId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'upload_fonte_advpl' && isset($_FILES['arquivo'])) {
        $tituloFonte = trim($_POST['titulo'] ?? '');
        $setorIdFonte = (int) ($_POST['setor_id'] ?? 0);
        // Mesma trava do lado equipe (cliente.php) -- confirma que o setor
        // é mesmo desse cliente antes de qualquer coisa, senão bastaria
        // trocar o id no POST pra escrever fonte em outro cliente.
        $stmtSetorFonte = $pdo->prepare('SELECT 1 FROM setores WHERE id = ? AND cliente_id = ?');
        $stmtSetorFonte->execute([$setorIdFonte, $clienteId]);
        $setorPertenceAoCliente = (bool) $stmtSetorFonte->fetchColumn();

        if ($tituloFonte === '' || !$setorIdFonte) {
            flash_set('erro', 'Informe o nome da rotina e o setor.');
        } elseif (!$setorPertenceAoCliente) {
            flash_set('erro', 'Setor inválido.');
        } elseif ($_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            flash_set('erro', 'Falha no upload do arquivo.');
        } elseif ($_FILES['arquivo']['size'] > UPLOADS_MAX_SIZE) {
            flash_set('erro', 'Arquivo maior que o limite permitido (' . (int) (UPLOADS_MAX_SIZE / 1024 / 1024) . 'MB).');
        } else {
            $dirClienteFonte = UPLOADS_DIR . '/clientes/' . $clienteId;
            if (!is_dir($dirClienteFonte)) {
                mkdir($dirClienteFonte, 0755, true);
            }
            $extensaoFonte = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
            $nomeArmazenadoFonte = bin2hex(random_bytes(16)) . ($extensaoFonte ? '.' . $extensaoFonte : '');
            $destinoFonte = $dirClienteFonte . '/' . $nomeArmazenadoFonte;

            if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destinoFonte)) {
                flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
            } else {
                $pdo->prepare('INSERT INTO setor_fontes_advpl (setor_id, titulo, ponto_entrada, descricao, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $setorIdFonte,
                        $tituloFonte,
                        trim($_POST['ponto_entrada'] ?? '') ?: null,
                        trim($_POST['descricao'] ?? '') ?: null,
                        $_FILES['arquivo']['name'],
                        'clientes/' . $clienteId . '/' . $nomeArmazenadoFonte,
                        $_FILES['arquivo']['size'],
                        file_get_contents($destinoFonte),
                    ]);
                Auth::registrarAcessoSensivel('fonte_advpl', 'cadastrar_via_portal', (int) $pdo->lastInsertId(), $clienteId);
                flash_set('sucesso', 'Fonte ADVPL cadastrada.');
            }
        }
    }

    if ($acao === 'atualizar_fonte_advpl') {
        $fonteIdAlvo = (int) ($_POST['fonte_id'] ?? 0);
        $tituloFonte = trim($_POST['titulo'] ?? '');

        // JOIN com setores confirma que essa fonte é mesmo desse cliente --
        // setor_fontes_advpl não tem cliente_id direto.
        $stmtFonteEditar = $pdo->prepare('
            SELECT f.arquivo_caminho FROM setor_fontes_advpl f
            INNER JOIN setores s ON s.id = f.setor_id
            WHERE f.id = ? AND s.cliente_id = ?
        ');
        $stmtFonteEditar->execute([$fonteIdAlvo, $clienteId]);
        $fonteAtual = $stmtFonteEditar->fetch();

        if (!$fonteAtual) {
            flash_set('erro', 'Fonte não encontrada.');
        } elseif ($tituloFonte === '') {
            flash_set('erro', 'Informe o nome da rotina.');
        } else {
            $pontoEntradaFonte = trim($_POST['ponto_entrada'] ?? '') ?: null;
            $descricaoFonte = trim($_POST['descricao'] ?? '') ?: null;
            // Arquivo novo é opcional -- em branco mantém o já cadastrado.
            $temArquivoNovo = isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK && $_FILES['arquivo']['size'] > 0;

            if (!$temArquivoNovo) {
                $pdo->prepare('UPDATE setor_fontes_advpl SET titulo=?, ponto_entrada=?, descricao=? WHERE id=?')
                    ->execute([$tituloFonte, $pontoEntradaFonte, $descricaoFonte, $fonteIdAlvo]);
                Auth::registrarAcessoSensivel('fonte_advpl', 'editar_via_portal', $fonteIdAlvo, $clienteId);
                flash_set('sucesso', 'Fonte ADVPL atualizada.');
            } elseif ($_FILES['arquivo']['size'] > UPLOADS_MAX_SIZE) {
                flash_set('erro', 'Arquivo maior que o limite permitido (' . (int) (UPLOADS_MAX_SIZE / 1024 / 1024) . 'MB).');
            } else {
                $dirClienteFonte = UPLOADS_DIR . '/clientes/' . $clienteId;
                if (!is_dir($dirClienteFonte)) {
                    mkdir($dirClienteFonte, 0755, true);
                }
                $extensaoFonte = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                $nomeArmazenadoFonte = bin2hex(random_bytes(16)) . ($extensaoFonte ? '.' . $extensaoFonte : '');
                $destinoFonte = $dirClienteFonte . '/' . $nomeArmazenadoFonte;

                if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destinoFonte)) {
                    flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
                } else {
                    $pdo->prepare('UPDATE setor_fontes_advpl SET titulo=?, ponto_entrada=?, descricao=?, arquivo_nome_original=?, arquivo_caminho=?, arquivo_tamanho=?, dados=? WHERE id=?')
                        ->execute([
                            $tituloFonte,
                            $pontoEntradaFonte,
                            $descricaoFonte,
                            $_FILES['arquivo']['name'],
                            'clientes/' . $clienteId . '/' . $nomeArmazenadoFonte,
                            $_FILES['arquivo']['size'],
                            file_get_contents($destinoFonte),
                            $fonteIdAlvo,
                        ]);
                    // Só apaga o arquivo antigo depois do UPDATE ter dado
                    // certo -- senão, numa falha no meio do caminho, o
                    // registro ficaria apontando pra um arquivo já apagado.
                    if ($fonteAtual['arquivo_caminho']) {
                        @unlink(UPLOADS_DIR . '/' . $fonteAtual['arquivo_caminho']);
                    }
                    Auth::registrarAcessoSensivel('fonte_advpl', 'editar_via_portal', $fonteIdAlvo, $clienteId);
                    flash_set('sucesso', 'Fonte ADVPL atualizada (arquivo substituído).');
                }
            }
        }
    }

    header('Location: ' . url('portal/fontes_advpl.php'));
    exit;
}

$setoresCliente = $pdo->prepare('SELECT id, nome FROM setores WHERE cliente_id = ? ORDER BY nome ASC');
$setoresCliente->execute([$clienteId]);
$setoresCliente = $setoresCliente->fetchAll();

$fontesAdvpl = $pdo->prepare("
    SELECT f.*, s.nome AS setor_nome
    FROM setor_fontes_advpl f
    INNER JOIN setores s ON s.id = f.setor_id
    WHERE s.cliente_id = ?
    ORDER BY f.titulo ASC
");
$fontesAdvpl->execute([$clienteId]);
$fontesAdvplPorSetorId = [];
foreach ($fontesAdvpl->fetchAll() as $f) {
    $fontesAdvplPorSetorId[$f['setor_id']][] = $f;
}

$tituloPagina = 'Fontes ADVPL';
require __DIR__ . '/../templates/portal_header.php';
?>

<h4 class="mb-1"><i class="bi bi-file-earmark-code"></i> Fontes ADVPL</h4>
<p class="text-muted small mb-4">Código-fonte das rotinas customizadas de cada setor -- área restrita, todo cadastro/edição/download fica registrado. Como contato master, você pode cadastrar e editar fontes, mas a exclusão continua só com a equipe Prime.</p>

<?php if (!$setoresCliente): ?>
    <div class="card card-stat">
        <div class="card-body text-center py-5">
            <i class="bi bi-file-earmark-code text-muted" style="font-size:2.5rem;"></i>
            <p class="text-muted mt-3 mb-0">Nenhum setor cadastrado ainda.</p>
        </div>
    </div>
<?php else: ?>
    <input type="text" class="form-control filtro-fonte-advpl-portal mb-3" data-alvo="fontesAdvplGridPortal" placeholder="🔎 Filtrar por rotina, ponto de entrada ou arquivo...">
<?php endif; ?>

<div id="fontesAdvplGridPortal">
<?php foreach ($setoresCliente as $setorPortal): $fontesDoSetor = $fontesAdvplPorSetorId[$setorPortal['id']] ?? []; ?>
    <div class="fonte-advpl-grupo">
        <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
            <h6 class="text-muted text-uppercase small fw-bold mb-0"><?= e($setorPortal['nome']) ?></h6>
            <?php if ($fontesDoSetor): ?>
                <a href="<?= url('download_fontes_advpl_zip.php?setor_id=' . $setorPortal['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-file-earmark-zip"></i> Baixar tudo (ZIP)</a>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end card card-stat p-3 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="upload_fonte_advpl">
            <input type="hidden" name="setor_id" value="<?= $setorPortal['id'] ?>">
            <div class="col-md-4"><label class="form-label small mb-1">Nome da rotina</label><input type="text" name="titulo" class="form-control form-control-sm" placeholder="Ex.: Bloqueio de faturamento" required></div>
            <div class="col-md-4"><label class="form-label small mb-1">Ponto de entrada</label><input type="text" name="ponto_entrada" class="form-control form-control-sm" placeholder="Ex.: U_MEUFONTE01"></div>
            <div class="col-md-4"><label class="form-label small mb-1">Arquivo fonte</label><input type="file" name="arquivo" class="form-control form-control-sm" accept=".prw,.ptm,.prx,.tlpp,.ch,.zip" required></div>
            <div class="col-12"><label class="form-label small mb-1">Descrição</label><textarea name="descricao" class="form-control form-control-sm" rows="1" placeholder="O que esse fonte faz, opcional"></textarea></div>
            <div class="col-12"><button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-upload"></i> Enviar</button></div>
        </form>

        <?php if ($fontesDoSetor): ?>
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Rotina</th><th>Ponto de entrada</th><th>Arquivo</th><th>Data</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($fontesDoSetor as $f): ?>
                <tr data-nome-filtro="<?= e(mb_strtolower($f['titulo'] . ' ' . $f['ponto_entrada'] . ' ' . $f['arquivo_nome_original'], 'UTF-8')) ?>">
                    <td class="fw-semibold"><?= e($f['titulo']) ?><?= $f['descricao'] ? '<div class="text-muted small">' . e($f['descricao']) . '</div>' : '' ?></td>
                    <td><?= $f['ponto_entrada'] ? '<code>' . e($f['ponto_entrada']) . '</code>' : '-' ?></td>
                    <td><?= e($f['arquivo_nome_original']) ?> <small class="text-muted">(<?= round($f['arquivo_tamanho'] / 1024) ?> KB)</small></td>
                    <td><?= formatar_data($f['criado_em']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= url('download_fonte_advpl.php?id=' . $f['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-dark botao-editar-fonte-advpl-portal"
                            data-id="<?= $f['id'] ?>" data-titulo="<?= e($f['titulo']) ?>"
                            data-ponto-entrada="<?= e($f['ponto_entrada']) ?>" data-descricao="<?= e($f['descricao']) ?>"
                            data-arquivo="<?= e($f['arquivo_nome_original']) ?>"
                        ><i class="bi bi-pencil"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="text-muted text-center py-3">Nenhuma fonte ADVPL cadastrada ainda pra esse setor.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<!-- Editar fonte ADVPL -- arquivo é opcional (em branco mantém o atual). -->
<div class="modal fade" id="modalEditarFonteAdvplPortal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="atualizar_fonte_advpl">
        <input type="hidden" name="fonte_id" id="editarFonteAdvplPortalId" value="">
        <div class="modal-header">
          <h5 class="modal-title">Editar fonte ADVPL</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nome da rotina *</label>
              <input type="text" name="titulo" id="editarFonteAdvplPortalTitulo" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Ponto de entrada</label>
              <input type="text" name="ponto_entrada" id="editarFonteAdvplPortalPontoEntrada" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Descrição</label>
              <textarea name="descricao" id="editarFonteAdvplPortalDescricao" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Arquivo fonte</label>
              <input type="file" name="arquivo" class="form-control" accept=".prw,.ptm,.prx,.tlpp,.ch,.zip">
              <p class="text-muted small mb-0">Deixe em branco pra manter o arquivo atual (<span id="editarFonteAdvplPortalArquivoAtual"></span>).</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Mesma ideia do filtro de vídeo aula do portal -- esconde o título do
    // setor (fonte-advpl-grupo) se TODAS as fontes dele ficarem escondidas.
    document.querySelectorAll('.filtro-fonte-advpl-portal').forEach(function (campo) {
        var grade = document.getElementById(campo.dataset.alvo);
        if (!grade) return;
        campo.addEventListener('input', function () {
            var termo = campo.value.trim().toLowerCase();
            grade.querySelectorAll('.fonte-advpl-grupo').forEach(function (grupo) {
                var linhas = grupo.querySelectorAll('[data-nome-filtro]');
                if (!linhas.length) return;
                var algumaVisivel = false;
                linhas.forEach(function (linha) {
                    var visivel = linha.dataset.nomeFiltro.indexOf(termo) !== -1;
                    linha.style.display = visivel ? '' : 'none';
                    if (visivel) algumaVisivel = true;
                });
                grupo.style.display = algumaVisivel ? '' : 'none';
            });
        });
    });

    var modalEditarFonteAdvplPortal = document.getElementById('modalEditarFonteAdvplPortal');
    if (modalEditarFonteAdvplPortal) {
        document.querySelectorAll('.botao-editar-fonte-advpl-portal').forEach(function (botao) {
            botao.addEventListener('click', function () {
                document.getElementById('editarFonteAdvplPortalId').value = botao.dataset.id;
                document.getElementById('editarFonteAdvplPortalTitulo').value = botao.dataset.titulo;
                document.getElementById('editarFonteAdvplPortalPontoEntrada').value = botao.dataset.pontoEntrada || '';
                document.getElementById('editarFonteAdvplPortalDescricao').value = botao.dataset.descricao || '';
                document.getElementById('editarFonteAdvplPortalArquivoAtual').textContent = botao.dataset.arquivo;
                PrimeUI.openModal(modalEditarFonteAdvplPortal);
            });
        });
    }
});
</script>

<?php require __DIR__ . '/../templates/portal_footer.php'; ?>
