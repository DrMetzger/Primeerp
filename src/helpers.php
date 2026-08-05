<?php

/**
 * Funções utilitárias globais.
 */

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return APP_BASE_PATH . '/' . ltrim($path, '/');
}

/**
 * Igual a url(), mas acrescenta ?v=<data de modificação do arquivo> para
 * "quebrar" o cache do navegador sempre que o arquivo mudar (evita a tela
 * ficar com CSS/imagem antiga presa em cache depois de um deploy).
 */
function asset_url(string $path): string
{
    $caminhoFisico = __DIR__ . '/../' . ltrim($path, '/');
    $versao = is_file($caminhoFisico) ? filemtime($caminhoFisico) : time();
    return url($path) . '?v=' . $versao;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('Sessão expirada ou token inválido. Volte e tente novamente.');
    }
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

/**
 * Avatar circular usado nas respostas de chamados: mostra a foto de perfil
 * do cliente quando ele tiver enviado uma (via portal/perfil.php); cai para
 * um círculo com a inicial do nome quando não houver foto (também usado
 * sempre para respostas da equipe, que não têm foto de perfil).
 */
function avatar_html(string $nome, ?string $caminhoFoto, string $classeTipo): string
{
    if ($caminhoFoto) {
        return '<img src="' . e(asset_url($caminhoFoto)) . '" class="avatar-circle avatar-foto ' . e($classeTipo) . '" alt="' . e($nome) . '">';
    }
    $inicial = $nome !== '' ? mb_strtoupper(mb_substr($nome, 0, 1)) : '?';
    return '<span class="avatar-circle ' . e($classeTipo) . '">' . e($inicial) . '</span>';
}

function formatar_data(?string $data): string
{
    if (!$data) {
        return '-';
    }
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

function badge_status_demanda(string $status): string
{
    $mapa = [
        'aberta' => ['Aberta', 'badge-aberta', 'bi-circle'],
        'em_andamento' => ['Em andamento', 'badge-andamento', 'bi-arrow-repeat'],
        'concluida' => ['Concluída', 'badge-concluida', 'bi-check-circle-fill'],
        'cancelada' => ['Cancelada', 'badge-cancelada', 'bi-x-circle'],
    ];
    [$label, $classe, $icone] = $mapa[$status] ?? [$status, '', 'bi-circle'];
    return '<span class="badge ' . e($classe) . '"><i class="bi ' . e($icone) . '"></i> ' . e($label) . '</span>';
}

function badge_status_chamado(string $status): string
{
    $mapa = [
        'aberto' => ['Aberto', 'badge-aberto', 'bi-envelope-open'],
        'aguardando_equipe' => ['Aguardando nós', 'badge-aguardando-equipe', 'bi-exclamation-circle-fill'],
        'aguardando_cliente' => ['Aguardando cliente', 'badge-aguardando-cliente', 'bi-hourglass-split'],
        'fechado' => ['Fechado', 'badge-fechado', 'bi-x-circle'],
    ];
    [$label, $classe, $icone] = $mapa[$status] ?? [$status, '', 'bi-circle'];
    return '<span class="badge ' . e($classe) . '"><i class="bi ' . e($icone) . '"></i> ' . e($label) . '</span>';
}

/**
 * Recalcula o status de um chamado a partir de quem respondeu por último --
 * nunca deve ser setado à mão (exceto o fechamento manual). Chamar sempre
 * depois de inserir uma nova linha em chamado_respostas, e também ao
 * reabrir um chamado fechado.
 */
function recalcular_status_chamado(\PDO $pdo, int $chamadoId): void
{
    $stmt = $pdo->prepare('SELECT autor_tipo FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em DESC, id DESC LIMIT 1');
    $stmt->execute([$chamadoId]);
    $ultimoAutor = $stmt->fetchColumn();

    if ($ultimoAutor === false) {
        $novoStatus = 'aberto';
    } else {
        $temRespostaEquipe = $pdo->prepare('SELECT 1 FROM chamado_respostas WHERE chamado_id = ? AND autor_tipo = "equipe" LIMIT 1');
        $temRespostaEquipe->execute([$chamadoId]);
        if (!$temRespostaEquipe->fetchColumn()) {
            $novoStatus = 'aberto';
        } else {
            $novoStatus = $ultimoAutor === 'equipe' ? 'aguardando_cliente' : 'aguardando_equipe';
        }
    }

    $pdo->prepare('UPDATE chamados SET status = ? WHERE id = ?')->execute([$novoStatus, $chamadoId]);
}

/**
 * Processa o upload de anexos de uma mensagem de chamado (abertura ou
 * resposta) -- usado tanto pela resposta da equipe (cliente.php) quanto pela
 * resposta do cliente no portal (portal/chamado.php), e futuramente pelo
 * formulário público de abertura. Espera um campo de upload múltiplo, ex.:
 * <input type="file" name="anexos[]" multiple>. Retorna um array de
 * mensagens de erro (vazio = tudo certo, mesmo que nenhum arquivo tenha
 * sido enviado -- anexo é sempre opcional).
 */
function processar_anexos_chamado(\PDO $pdo, int $chamadoId, ?int $respostaId, string $enviadoPor, string $campoArquivo = 'anexos'): array
{
    $erros = [];

    if (empty($_FILES[$campoArquivo]) || empty($_FILES[$campoArquivo]['name'][0])) {
        return $erros;
    }

    $arquivos = $_FILES[$campoArquivo];
    $quantidade = count($arquivos['name']);

    if ($quantidade > ANEXO_CHAMADO_MAX_QUANTIDADE) {
        $erros[] = 'Envie no máximo ' . ANEXO_CHAMADO_MAX_QUANTIDADE . ' arquivos por vez.';
        return $erros;
    }

    $dirChamado = UPLOADS_DIR . '/chamados/' . $chamadoId;

    for ($i = 0; $i < $quantidade; $i++) {
        if ($arquivos['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($arquivos['error'][$i] !== UPLOAD_ERR_OK) {
            $erros[] = 'Falha no upload de "' . $arquivos['name'][$i] . '".';
            continue;
        }
        if ($arquivos['size'][$i] > ANEXO_CHAMADO_MAX_SIZE) {
            $erros[] = '"' . $arquivos['name'][$i] . '" é maior que o limite de ' . (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) . 'MB.';
            continue;
        }

        $tipoMime = mime_content_type($arquivos['tmp_name'][$i]);
        $extensaoPermitida = ANEXO_CHAMADO_TIPOS_PERMITIDOS[$tipoMime] ?? null;
        if (!$extensaoPermitida) {
            $erros[] = '"' . $arquivos['name'][$i] . '" não é um tipo de arquivo permitido (use imagem ou PDF).';
            continue;
        }

        if (!is_dir($dirChamado)) {
            mkdir($dirChamado, 0755, true);
        }

        $nomeArmazenado = bin2hex(random_bytes(16)) . '.' . $extensaoPermitida;
        $destino = $dirChamado . '/' . $nomeArmazenado;

        if (move_uploaded_file($arquivos['tmp_name'][$i], $destino)) {
            $pdo->prepare('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $chamadoId,
                    $respostaId,
                    $enviadoPor,
                    $arquivos['name'][$i],
                    'chamados/' . $chamadoId . '/' . $nomeArmazenado,
                    $arquivos['size'][$i],
                ]);
        } else {
            $erros[] = 'Não foi possível salvar "' . $arquivos['name'][$i] . '" no servidor.';
        }
    }

    return $erros;
}

/**
 * Renderiza a lista de anexos de uma bolha de chat (mensagem de abertura ou
 * resposta), com link pra baixar cada um via download_anexo_chamado.php
 * (que checa se quem está baixando é a equipe ou o próprio cliente do
 * chamado antes de servir o arquivo).
 */
function render_anexos_html(array $anexos): string
{
    if (!$anexos) {
        return '';
    }

    $html = '<div class="chat-anexos">';
    foreach ($anexos as $a) {
        $extensao = strtolower(pathinfo($a['arquivo_nome_original'], PATHINFO_EXTENSION));
        $icone = in_array($extensao, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? 'bi-image' : 'bi-file-earmark-pdf';
        $html .= '<a href="' . e(url('download_anexo_chamado.php?id=' . $a['id'])) . '" class="anexo-link" target="_blank" rel="noopener">'
            . '<i class="bi ' . $icone . '"></i> ' . e($a['arquivo_nome_original']) . '</a>';
    }
    $html .= '</div>';

    return $html;
}

function badge_prioridade(string $prioridade): string
{
    $mapa = [
        'baixa' => ['Baixa', 'prio-baixa', 'bi-arrow-down-circle'],
        'media' => ['Média', 'prio-media', 'bi-dash-circle'],
        'alta' => ['Alta', 'prio-alta', 'bi-arrow-up-circle'],
        'urgente' => ['Urgente', 'prio-urgente', 'bi-exclamation-circle-fill'],
    ];
    [$label, $classe, $icone] = $mapa[$prioridade] ?? [$prioridade, '', 'bi-circle'];
    return '<span class="badge ' . e($classe) . '"><i class="bi ' . e($icone) . '"></i> ' . e($label) . '</span>';
}
