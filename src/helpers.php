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
