<?php

/**
 * Biblioteca GERAL de fontes/patches ADVPL (PRW/PRX/TLPP/PTM) da equipe,
 * organizada por categoria/departamento -- pedido do Kleber. Diferente da
 * aba "Fontes ADVPL" de cliente.php (que é o fonte DESSE cliente/setor
 * específico): aqui é fonte/patch de uso geral, não vinculado a nenhum
 * cliente, pra reaproveitar entre projetos. Restrito a admin/diretor/CEO --
 * pedido explícito do Kleber: "colaborador não pode ter acesso aos fontes,
 * somente os fontes que são disponibilizados para os clientes" (ou seja,
 * colaborador continua vendo só a aba "Fontes ADVPL" de dentro de cada
 * cliente, que já é escopada por vínculo/apenasProprios() -- essa
 * biblioteca GERAL fica de fora do alcance dele por completo).
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireAdmin();

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar_fonte_global') {
        $categoria = $_POST['categoria'] ?? '';
        $nomeDigitado = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '') ?: null;

        if (!array_key_exists($categoria, FONTES_GLOBAIS_CATEGORIAS)) {
            flash_set('erro', 'Categoria inválida.');
        } elseif (empty($_FILES['arquivo']['name'][0] ?? '')) {
            flash_set('erro', 'Escolha ao menos um arquivo.');
        } else {
            // Aceita VÁRIOS arquivos de uma vez (pedido do Kleber: "precisa
            // poder ir acumulando arquivos e depois enviar todos de uma vez
            // só") -- cada arquivo vira um registro próprio. O nome digitado
            // só faz sentido pra 1 arquivo (não dá pra usar o MESMO nome em
            // vários fontes diferentes); em lote, ignora o campo e usa o
            // nome de cada arquivo (sem extensão).
            $arquivos = $_FILES['arquivo'];
            $quantidade = count($arquivos['name']);
            $erros = [];
            $cadastrados = 0;

            $dirFontesGlobais = UPLOADS_DIR . '/fontes_globais';
            if (!is_dir($dirFontesGlobais)) {
                mkdir($dirFontesGlobais, 0755, true);
            }

            for ($i = 0; $i < $quantidade; $i++) {
                $nomeOriginal = $arquivos['name'][$i];
                if ($arquivos['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($arquivos['error'][$i] !== UPLOAD_ERR_OK) {
                    $erros[] = 'Falha no upload de "' . $nomeOriginal . '".';
                    continue;
                }
                if ($arquivos['size'][$i] > UPLOADS_MAX_SIZE) {
                    $erros[] = '"' . $nomeOriginal . '" passa do limite de ' . (int) (UPLOADS_MAX_SIZE / 1024 / 1024) . 'MB.';
                    continue;
                }
                $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
                if (!in_array($extensao, FONTES_GLOBAIS_EXTENSOES_PERMITIDAS, true)) {
                    $erros[] = '"' . $nomeOriginal . '" não é .prw, .prx, .tlpp ou .ptm.';
                    continue;
                }

                $nomeArmazenado = bin2hex(random_bytes(16)) . '.' . $extensao;
                $destino = $dirFontesGlobais . '/' . $nomeArmazenado;

                if (!move_uploaded_file($arquivos['tmp_name'][$i], $destino)) {
                    $erros[] = 'Não foi possível salvar "' . $nomeOriginal . '" no servidor.';
                    continue;
                }

                $nomeFonte = ($quantidade === 1 && $nomeDigitado !== '') ? $nomeDigitado : pathinfo($nomeOriginal, PATHINFO_FILENAME);

                $pdo->prepare('INSERT INTO fontes_globais (categoria, nome, descricao, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $categoria,
                        $nomeFonte,
                        $descricao,
                        $nomeOriginal,
                        'fontes_globais/' . $nomeArmazenado,
                        $arquivos['size'][$i],
                        file_get_contents($destino),
                        Auth::userId(),
                    ]);
                $cadastrados++;
            }

            if ($cadastrados > 0) {
                $mensagem = $cadastrados === 1 ? 'Fonte/patch cadastrado.' : $cadastrados . ' fontes/patches cadastrados.';
                flash_set($erros ? 'erro' : 'sucesso', $erros ? $mensagem . ' Mas: ' . implode(' ', $erros) : $mensagem);
            } else {
                flash_set('erro', $erros ? implode(' ', $erros) : 'Nenhum arquivo válido enviado.');
            }
        }
    }

    if ($acao === 'atualizar_fonte_global') {
        $fonteId = (int) ($_POST['fonte_id'] ?? 0);
        $categoria = $_POST['categoria'] ?? '';
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '') ?: null;

        $stmtAtual = $pdo->prepare('SELECT arquivo_caminho FROM fontes_globais WHERE id = ?');
        $stmtAtual->execute([$fonteId]);
        $fonteAtual = $stmtAtual->fetch();

        if (!$fonteAtual) {
            flash_set('erro', 'Fonte/patch não encontrado.');
        } elseif (!array_key_exists($categoria, FONTES_GLOBAIS_CATEGORIAS)) {
            flash_set('erro', 'Categoria inválida.');
        } elseif ($nome === '') {
            flash_set('erro', 'Informe o nome do fonte/patch.');
        } else {
            // Arquivo novo é OPCIONAL na edição -- em branco mantém o
            // arquivo já cadastrado (mesmo padrão de editar_fonte_advpl em
            // cliente.php), pra dar pra corrigir só o nome/categoria/
            // descrição sem precisar reenviar o arquivo inteiro de novo.
            $temArquivoNovo = isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK && $_FILES['arquivo']['size'] > 0;

            if (!$temArquivoNovo) {
                $pdo->prepare('UPDATE fontes_globais SET categoria=?, nome=?, descricao=? WHERE id=?')
                    ->execute([$categoria, $nome, $descricao, $fonteId]);
                flash_set('sucesso', 'Fonte/patch atualizado.');
            } elseif ($_FILES['arquivo']['size'] > UPLOADS_MAX_SIZE) {
                flash_set('erro', 'Arquivo maior que o limite permitido (' . (int) (UPLOADS_MAX_SIZE / 1024 / 1024) . 'MB).');
            } else {
                $extensaoNova = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                if (!in_array($extensaoNova, FONTES_GLOBAIS_EXTENSOES_PERMITIDAS, true)) {
                    flash_set('erro', 'Só é permitido enviar arquivos .prw, .prx, .tlpp ou .ptm.');
                } else {
                    $dirFontesGlobais = UPLOADS_DIR . '/fontes_globais';
                    if (!is_dir($dirFontesGlobais)) {
                        mkdir($dirFontesGlobais, 0755, true);
                    }
                    $nomeArmazenadoNovo = bin2hex(random_bytes(16)) . '.' . $extensaoNova;
                    $destinoNovo = $dirFontesGlobais . '/' . $nomeArmazenadoNovo;

                    if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destinoNovo)) {
                        flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
                    } else {
                        $pdo->prepare('UPDATE fontes_globais SET categoria=?, nome=?, descricao=?, arquivo_nome_original=?, arquivo_caminho=?, arquivo_tamanho=?, dados=? WHERE id=?')
                            ->execute([
                                $categoria,
                                $nome,
                                $descricao,
                                $_FILES['arquivo']['name'],
                                'fontes_globais/' . $nomeArmazenadoNovo,
                                $_FILES['arquivo']['size'],
                                file_get_contents($destinoNovo),
                                $fonteId,
                            ]);
                        // Só apaga o arquivo antigo depois do UPDATE ter dado
                        // certo -- senão, numa falha no meio do caminho, o
                        // registro ficaria apontando pra um arquivo já apagado.
                        if ($fonteAtual['arquivo_caminho']) {
                            @unlink(UPLOADS_DIR . '/' . $fonteAtual['arquivo_caminho']);
                        }
                        flash_set('sucesso', 'Fonte/patch atualizado (arquivo substituído).');
                    }
                }
            }
        }
    }

    if ($acao === 'excluir_fonte_global') {
        $fonteId = (int) ($_POST['fonte_id'] ?? 0);
        $stmtExcluir = $pdo->prepare('SELECT arquivo_caminho FROM fontes_globais WHERE id = ?');
        $stmtExcluir->execute([$fonteId]);
        $fonteExcluir = $stmtExcluir->fetch();

        if ($fonteExcluir) {
            $pdo->prepare('DELETE FROM fontes_globais WHERE id = ?')->execute([$fonteId]);
            if ($fonteExcluir['arquivo_caminho']) {
                @unlink(UPLOADS_DIR . '/' . $fonteExcluir['arquivo_caminho']);
            }
            flash_set('sucesso', 'Fonte/patch removido.');
        } else {
            flash_set('erro', 'Fonte/patch não encontrado.');
        }
    }

    header('Location: ' . url('fontes.php'));
    exit;
}

$stmtFontes = $pdo->query('
    SELECT f.*, u.nome AS criado_por_nome
    FROM fontes_globais f
    LEFT JOIN usuarios u ON u.id = f.criado_por
    ORDER BY f.nome ASC
');
$fontesPorCategoria = array_fill_keys(array_keys(FONTES_GLOBAIS_CATEGORIAS), []);
foreach ($stmtFontes->fetchAll() as $f) {
    if (isset($fontesPorCategoria[$f['categoria']])) {
        $fontesPorCategoria[$f['categoria']][] = $f;
    }
}
$totalFontesGlobais = array_sum(array_map('count', $fontesPorCategoria));

function icone_fonte_global(string $extensao): string
{
    return match ($extensao) {
        'prx' => 'bi-file-earmark-binary',
        'ptm' => 'bi-bandaid',
        default => 'bi-file-earmark-code',
    };
}

$tituloPagina = 'Fontes';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-1"><i class="bi bi-file-earmark-code"></i> Fontes e Patches</h4>
<p class="text-muted small mb-3">Biblioteca geral de fontes ADVPL (.prw/.tlpp), fonte precompilado (.prx) e patch (.ptm) do Protheus, organizada por categoria -- não é o fonte de um cliente específico (isso continua na aba "Fontes ADVPL" de cada cliente), é pra reaproveitar entre projetos.</p>

<div class="toolbar mb-3">
    <div class="toolbar-acoes">
        <span class="text-muted small"><?= $totalFontesGlobais ?> fonte(s)/patch(es) cadastrados no total</span>
    </div>
    <div class="toolbar-filtros d-flex gap-2 flex-wrap">
        <input type="text" class="form-control form-control-sm" id="buscaFontesGlobais" placeholder="Buscar por nome ou descrição..." style="max-width:260px;">
        <select class="form-select form-select-sm" id="filtroExtensaoFontesGlobais" style="max-width:170px;">
            <option value="">Todas as extensões</option>
            <option value="prw">.PRW (fonte)</option>
            <option value="tlpp">.TLPP (fonte)</option>
            <option value="prx">.PRX (precompilado)</option>
            <option value="ptm">.PTM (patch)</option>
        </select>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach (FONTES_GLOBAIS_CATEGORIAS as $slugCat => $labelCat): ?>
        <a href="#cat-<?= e($slugCat) ?>" class="btn btn-sm btn-outline-dark">
            <?= e($labelCat) ?> <span class="badge bg-secondary"><?= count($fontesPorCategoria[$slugCat]) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<p class="text-muted text-center py-4 d-none" id="avisoFiltroVazioFontesGlobais">Nenhum fonte/patch encontrado com esses filtros.</p>

<?php foreach (FONTES_GLOBAIS_CATEGORIAS as $slugCat => $labelCat): $fontesCat = $fontesPorCategoria[$slugCat]; ?>
<div class="mb-5 secao-categoria-fontes-globais" id="cat-<?= e($slugCat) ?>">
    <h5 class="text-uppercase text-muted small fw-bold mb-2"><i class="bi bi-folder2"></i> <?= e($labelCat) ?> <span class="badge bg-secondary"><?= count($fontesCat) ?></span></h5>

    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3 card card-stat p-3 form-criar-fonte-global">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_fonte_global">
        <input type="hidden" name="categoria" value="<?= e($slugCat) ?>">
        <div class="col-md-3">
            <label class="form-label small mb-1">Nome do fonte/patch</label>
            <input type="text" name="nome" class="form-control form-control-sm" placeholder="Ex.: U_MEUFONTE01">
            <span class="text-muted d-block" style="font-size:.72rem;">Em branco = usa o nome de cada arquivo (obrigatório só se digitar um nome com vários arquivos escolhidos).</span>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Descrição</label>
            <input type="text" name="descricao" class="form-control form-control-sm" placeholder="O que esse fonte/patch faz">
        </div>
        <div class="col-md-5">
            <label class="dropzone-arquivo dropzone-arquivo-sm dropzone-arquivo-multi mb-1">
                <input type="file" name="arquivo[]" class="dropzone-arquivo-input" accept="<?= e(FONTES_GLOBAIS_ACCEPT_HTML) ?>" multiple>
                <i class="bi bi-cloud-arrow-up dropzone-arquivo-icone"></i>
                <span class="dropzone-arquivo-texto">Arraste os arquivos aqui ou <strong>clique para escolher</strong> -- pode escolher vários de uma vez, ou repetir pra ir acumulando</span>
            </label>
            <ul class="lista-arquivos-fonte-pendentes list-unstyled small mb-0"></ul>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-sm btn-dark w-100" title="Cadastrar"><i class="bi bi-plus-lg"></i></button>
        </div>
    </form>

    <?php if (!$fontesCat): ?>
        <p class="text-muted small">Nenhum fonte/patch cadastrado nessa categoria ainda.</p>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($fontesCat as $f):
            $extensaoFonte = strtolower(pathinfo($f['arquivo_nome_original'], PATHINFO_EXTENSION));
        ?>
        <div class="col-md-4 col-lg-3 card-fonte-global" data-busca="<?= e(mb_strtolower($f['nome'] . ' ' . ($f['descricao'] ?? ''))) ?>" data-extensao="<?= e($extensaoFonte) ?>">
            <div class="card card-stat p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-start gap-2 mb-1">
                    <i class="bi <?= e(icone_fonte_global($extensaoFonte)) ?> fs-4 text-muted"></i>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="fw-semibold text-truncate" title="<?= e($f['nome']) ?>"><?= e($f['nome']) ?></div>
                        <span class="badge bg-secondary"><?= e(strtoupper($extensaoFonte)) ?></span>
                    </div>
                </div>
                <?php if ($f['descricao']): ?><p class="text-muted small mb-2"><?= e($f['descricao']) ?></p><?php endif; ?>
                <p class="text-muted small mb-2 mt-auto"><?= round($f['arquivo_tamanho'] / 1024) ?> KB &middot; <?= formatar_data($f['criado_em']) ?><br><?= e($f['criado_por_nome'] ?: '-') ?></p>
                <div class="d-flex gap-1">
                    <a class="btn btn-sm btn-outline-dark flex-grow-1" href="<?= url('download_fonte_global.php?id=' . $f['id']) ?>"><i class="bi bi-download"></i> Baixar</a>
                    <button type="button" class="btn btn-sm btn-outline-dark botao-editar-fonte-global"
                        data-id="<?= $f['id'] ?>"
                        data-categoria="<?= e($slugCat) ?>"
                        data-nome="<?= e($f['nome']) ?>"
                        data-descricao="<?= e($f['descricao'] ?? '') ?>"
                        data-arquivo="<?= e($f['arquivo_nome_original']) ?>"
                        title="Editar"><i class="bi bi-pencil"></i></button>
                    <form method="post" onsubmit="return confirm('Excluir &quot;<?= e($f['nome']) ?>&quot;?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="excluir_fonte_global">
                        <input type="hidden" name="fonte_id" value="<?= $f['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- Editar fonte/patch -- arquivo é opcional aqui: em branco mantém o já
     cadastrado, preenchido substitui (mesmo padrão de editar_fonte_advpl
     em cliente.php). -->
<div class="modal fade" id="modalEditarFonteGlobal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="atualizar_fonte_global">
        <input type="hidden" name="fonte_id" id="editarFonteGlobalId" value="">
        <div class="modal-header">
          <h5 class="modal-title">Editar fonte/patch</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Categoria</label>
              <select name="categoria" id="editarFonteGlobalCategoria" class="form-select" required>
                <?php foreach (FONTES_GLOBAIS_CATEGORIAS as $slugCat => $labelCat): ?>
                <option value="<?= e($slugCat) ?>"><?= e($labelCat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Nome *</label>
              <input type="text" name="nome" id="editarFonteGlobalNome" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Descrição</label>
              <textarea name="descricao" id="editarFonteGlobalDescricao" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="dropzone-arquivo">
                <input type="file" name="arquivo" class="dropzone-arquivo-input" accept="<?= e(FONTES_GLOBAIS_ACCEPT_HTML) ?>">
                <i class="bi bi-cloud-arrow-up dropzone-arquivo-icone"></i>
                <span class="dropzone-arquivo-texto">Arraste um novo arquivo aqui ou <strong>clique para escolher</strong></span>
                <span class="dropzone-arquivo-nome text-muted small"></span>
              </label>
              <p class="text-muted small mb-0">Deixe em branco pra manter o arquivo atual (<span id="editarFonteGlobalArquivoAtual"></span>).</p>
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
    // Arrastar-e-soltar genérico: alterna a classe de "arrastando por cima"
    // em qualquer .dropzone-arquivo (funciona igual pro modal de editar --
    // single -- e pro formulário de cadastro em lote -- multi, tratado à
    // parte logo abaixo).
    function ligarDragDropGenerico(zona, aoSoltar) {
        ['dragenter', 'dragover'].forEach(function (evento) {
            zona.addEventListener(evento, function (e) {
                e.preventDefault();
                zona.classList.add('dropzone-arquivo-sobre');
            });
        });
        ['dragleave', 'dragend'].forEach(function (evento) {
            zona.addEventListener(evento, function (e) {
                e.preventDefault();
                zona.classList.remove('dropzone-arquivo-sobre');
            });
        });
        zona.addEventListener('drop', function (e) {
            e.preventDefault();
            zona.classList.remove('dropzone-arquivo-sobre');
            var arquivos = (e.dataTransfer || {}).files;
            if (arquivos && arquivos.length) {
                aoSoltar(arquivos);
            }
        });
    }

    // Modal de editar (single) -- clique já funciona sozinho (o <input> fica
    // DENTRO do <label>), só falta o drag-and-drop e mostrar o nome do
    // arquivo escolhido. Além do texto, liga a classe "tem-arquivo" (cor
    // persistente, ver style.css) -- só o texto pequeno embaixo mudar era
    // fácil de não perceber que o arquivo realmente entrou (relato real:
    // "não dá pra ver que foi").
    document.querySelectorAll('.dropzone-arquivo:not(.dropzone-arquivo-multi)').forEach(function (zona) {
        var inputArquivoZona = zona.querySelector('.dropzone-arquivo-input');
        var nomeArquivoEl = zona.querySelector('.dropzone-arquivo-nome');

        function mostrarArquivoEscolhido() {
            var arquivo = inputArquivoZona.files[0];
            nomeArquivoEl.textContent = arquivo ? (arquivo.name + ' (' + (arquivo.size / 1024 / 1024).toFixed(1) + ' MB)') : '';
            zona.classList.toggle('dropzone-arquivo-tem-arquivo', !!arquivo);
        }
        inputArquivoZona.addEventListener('change', mostrarArquivoEscolhido);
        ligarDragDropGenerico(zona, function (arquivos) {
            inputArquivoZona.files = arquivos;
            mostrarArquivoEscolhido();
        });
    });

    // Formulário de cadastro em lote (multi) -- pedido do Kleber: "precisa
    // poder ir acumulando arquivos e depois enviar todos de uma vez só,
    // quando clico e arrasto só vai um e fica sobrescrevendo". Cada clique/
    // solta ACRESCENTA à lista (em vez de substituir o que já tinha
    // escolhido) -- a lista de verdade (arquivosNaLista) é sincronizada de
    // volta no <input type="file" multiple> via DataTransfer, então o
    // "Cadastrar" do formulário já manda tudo junto de uma vez, sem precisar
    // de um botão "enviar" no meio do caminho.
    document.querySelectorAll('.dropzone-arquivo-multi').forEach(function (zona) {
        var inputArquivoZona = zona.querySelector('.dropzone-arquivo-input');
        var lista = zona.parentElement.querySelector('.lista-arquivos-fonte-pendentes');
        var arquivosNaLista = [];

        function sincronizarInput() {
            var dt = new DataTransfer();
            arquivosNaLista.forEach(function (f) { dt.items.add(f); });
            inputArquivoZona.files = dt.files;
            zona.classList.toggle('dropzone-arquivo-tem-arquivo', arquivosNaLista.length > 0);
        }

        function renderizarLista() {
            lista.innerHTML = '';
            arquivosNaLista.forEach(function (arquivo, indice) {
                var li = document.createElement('li');
                li.className = 'd-flex align-items-center gap-1 text-muted';
                li.innerHTML = '<i class="bi bi-file-earmark-code"></i> <span class="flex-grow-1 text-truncate">' + arquivo.name + '</span>'
                    + '<button type="button" class="btn-close btn-close-sm" aria-label="Remover" data-indice="' + indice + '"></button>';
                lista.appendChild(li);
            });
        }

        function adicionarArquivos(novosArquivos) {
            arquivosNaLista = arquivosNaLista.concat(Array.from(novosArquivos));
            renderizarLista();
            sincronizarInput();
        }

        inputArquivoZona.addEventListener('change', function () {
            if (inputArquivoZona.files && inputArquivoZona.files.length) {
                adicionarArquivos(inputArquivoZona.files);
            }
        });
        ligarDragDropGenerico(zona, adicionarArquivos);

        lista.addEventListener('click', function (e) {
            var botaoRemover = e.target.closest('[data-indice]');
            if (!botaoRemover) return;
            arquivosNaLista.splice(parseInt(botaoRemover.dataset.indice, 10), 1);
            renderizarLista();
            sincronizarInput();
        });

        // Limpa a lista depois de enviar o formulário (senão, ao voltar pra
        // essa mesma página via cache do navegador -- "voltar" no menu --
        // a lista em memória ficava presa, mas o <input> real já tinha sido
        // resetado pelo navegador, ficando os dois campos incoerentes).
        zona.closest('form').addEventListener('submit', function () {
            arquivosNaLista = [];
        });
    });

    // Editar -- preenche o modal a partir dos data-* do botão da linha.
    var modalEditar = document.getElementById('modalEditarFonteGlobal');
    document.querySelectorAll('.botao-editar-fonte-global').forEach(function (botao) {
        botao.addEventListener('click', function () {
            document.getElementById('editarFonteGlobalId').value = botao.dataset.id;
            document.getElementById('editarFonteGlobalCategoria').value = botao.dataset.categoria;
            document.getElementById('editarFonteGlobalNome').value = botao.dataset.nome;
            document.getElementById('editarFonteGlobalDescricao').value = botao.dataset.descricao;
            document.getElementById('editarFonteGlobalArquivoAtual').textContent = botao.dataset.arquivo;
            PrimeUI.openModal(modalEditar);
        });
    });

    // Filtros: busca por nome/descrição + extensão. Uma seção de categoria
    // só some INTEIRA quando tem filtro ativo e nada bateu nela -- sem
    // filtro nenhum, toda categoria fica visível (mesmo vazia, com o
    // formulário de upload) pra dar pra cadastrar o primeiro fonte dela.
    var inputBusca = document.getElementById('buscaFontesGlobais');
    var selectExtensao = document.getElementById('filtroExtensaoFontesGlobais');
    var avisoVazio = document.getElementById('avisoFiltroVazioFontesGlobais');

    function aplicarFiltrosFontesGlobais() {
        var termo = (inputBusca.value || '').toLowerCase().trim();
        var extensaoAlvo = selectExtensao.value;
        var temFiltroAtivo = !!termo || !!extensaoAlvo;
        var totalVisivel = 0;

        document.querySelectorAll('.secao-categoria-fontes-globais').forEach(function (secao) {
            var algumVisivelNaSecao = false;
            secao.querySelectorAll('.card-fonte-global').forEach(function (card) {
                var bateBusca = !termo || (card.dataset.busca || '').indexOf(termo) !== -1;
                var bateExtensao = !extensaoAlvo || card.dataset.extensao === extensaoAlvo;
                var visivel = bateBusca && bateExtensao;
                card.classList.toggle('d-none', !visivel);
                if (visivel) { algumVisivelNaSecao = true; totalVisivel++; }
            });
            secao.classList.toggle('d-none', temFiltroAtivo && !algumVisivelNaSecao);
        });

        avisoVazio.classList.toggle('d-none', !temFiltroAtivo || totalVisivel > 0);
    }

    inputBusca.addEventListener('input', aplicarFiltrosFontesGlobais);
    selectExtensao.addEventListener('change', aplicarFiltrosFontesGlobais);
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
