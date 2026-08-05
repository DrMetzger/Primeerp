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
 * Política de senha forte, exigida da EQUIPE interna (usuarios) -- pedido
 * do Kleber depois do Petronilho esquecer a senha repetidas vezes com uma
 * senha fraca fácil de esquecer. NÃO se aplica à senha do cliente no portal
 * (portal/criar_senha.php) -- pedido foi específico pra equipe, e cliente
 * já erra bastante com regra mais simples. Retorna null se a senha atende
 * a política, ou uma mensagem de erro explicando o que falta.
 */
function validar_senha_forte(string $senha): ?string
{
    if (strlen($senha) < 8) {
        return 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if (!preg_match('/[0-9]/', $senha)) {
        return 'A senha precisa ter pelo menos 1 número.';
    }
    if (!preg_match('/[A-Z]/', $senha)) {
        return 'A senha precisa ter pelo menos 1 letra maiúscula.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $senha)) {
        return 'A senha precisa ter pelo menos 1 caractere especial.';
    }
    return null;
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

/**
 * Confere se um caminho relativo (guardado no banco, tipo
 * "assets/avatars/logo_cliente_3.png") existe de fato no disco. Usado
 * antes de desenhar um <img> pra foto/logo enviada por upload -- se o
 * registro no banco ainda aponta pra um arquivo que não existe mais (foi
 * perdido/apagado), cai no placeholder em vez de mostrar ícone de imagem
 * quebrada.
 */
function arquivo_publico_existe(?string $caminhoRelativo): bool
{
    if (!$caminhoRelativo) {
        return false;
    }
    return is_file(__DIR__ . '/../' . ltrim($caminhoRelativo, '/'));
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
    // Confere se o arquivo realmente existe no disco antes de confiar no
    // caminho que está no banco -- evita ícone de imagem quebrada quando a
    // foto foi perdida (ex.: apagada por engano, ou por algum problema no
    // deploy) mas o banco ainda guarda o caminho antigo. Nesse caso cai
    // graciosamente na inicial coloridas, igual a quem nunca teve foto.
    if (arquivo_publico_existe($caminhoFoto)) {
        return '<img src="' . e(asset_url($caminhoFoto)) . '" class="avatar-circle avatar-foto ' . e($classeTipo) . '" alt="' . e($nome) . '">';
    }
    $inicial = $nome !== '' ? mb_strtoupper(mb_substr($nome, 0, 1)) : '?';
    return '<span class="avatar-circle ' . e($classeTipo) . '">' . e($inicial) . '</span>';
}

/**
 * Guarda telefone só com os dígitos no banco (sem máscara) -- importante
 * pra integração futura com WhatsApp Cloud API, que precisa do número
 * "cru". A máscara é só visual, aplicada na hora de exibir/preencher campo
 * (formatar_telefone()) ou enquanto a pessoa digita (JS, ver app.js).
 */
function apenas_digitos(?string $valor): ?string
{
    $digitos = preg_replace('/\D/', '', $valor ?? '');
    return $digitos !== '' ? $digitos : null;
}

/**
 * Normaliza CPF/RG do contato -- guarda só dígitos (e o "X" que o RG pode
 * terminar com), sem pontos/traços, pra não ter o mesmo documento salvo de
 * formas diferentes.
 */
function normalizar_documento(?string $valor): ?string
{
    $limpo = strtoupper(preg_replace('/[^0-9Xx]/', '', $valor ?? ''));
    return $limpo !== '' ? $limpo : null;
}

/**
 * Redimensiona (só encolhe, nunca aumenta) uma imagem já salva em disco pra
 * caber dentro de uma caixa máxima de $larguraMax x $alturaMax, mantendo a
 * proporção original -- pra logo de cliente não subir do tamanho de uma
 * foto de celular (vários MB, milhares de pixels) quando o card onde ela
 * aparece tem só ~92px de altura. SVG não passa por aqui (é vetor, não
 * precisa). Se o GD falhar por qualquer motivo, simplesmente mantém o
 * arquivo original -- redimensionar é otimização, não pode quebrar o
 * upload.
 */
function redimensionar_imagem_se_necessario(string $caminhoArquivo, string $mime, int $larguraMax, int $alturaMax): void
{
    if (!extension_loaded('gd')) {
        return;
    }

    try {
        $origem = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($caminhoArquivo),
            'image/png' => imagecreatefrompng($caminhoArquivo),
            'image/webp' => imagecreatefromwebp($caminhoArquivo),
            default => null,
        };
        if (!$origem) {
            return;
        }

        $largura = imagesx($origem);
        $altura = imagesy($origem);
        if ($largura <= $larguraMax && $altura <= $alturaMax) {
            imagedestroy($origem);
            return; // já cabe na caixa, não precisa reduzir.
        }

        $escala = min($larguraMax / $largura, $alturaMax / $altura);
        $novaLargura = max(1, (int) round($largura * $escala));
        $novaAltura = max(1, (int) round($altura * $escala));

        $destino = imagecreatetruecolor($novaLargura, $novaAltura);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            // Preserva transparência (fundo transparente não pode virar preto).
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            $transparente = imagecolorallocatealpha($destino, 0, 0, 0, 127);
            imagefilledrectangle($destino, 0, 0, $novaLargura, $novaAltura, $transparente);
        }
        imagecopyresampled($destino, $origem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);

        match ($mime) {
            'image/jpeg' => imagejpeg($destino, $caminhoArquivo, 85),
            'image/png' => imagepng($destino, $caminhoArquivo),
            'image/webp' => imagewebp($destino, $caminhoArquivo, 85),
            default => null,
        };

        imagedestroy($origem);
        imagedestroy($destino);
    } catch (\Throwable $e) {
        // Mantém o arquivo original enviado -- melhor um logo grande do que
        // nenhum logo.
    }
}

/**
 * Processa o upload de foto de perfil de um usuário da EQUIPE. Extraído de
 * perfil.php (troca da própria foto) pra também ser usado em usuarios.php
 * (admin trocando a foto de outro colega, ex.: Petronilho que vive
 * esquecendo de mexer no próprio perfil). Retorna null se deu tudo certo,
 * ou uma mensagem de erro pra mostrar via flash_set().
 */
function processar_upload_foto_usuario(\PDO $pdo, int $usuarioId): ?string
{
    if (empty($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Selecione uma imagem.';
    }

    $arquivo = $_FILES['foto'];
    $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return 'Falha no upload da imagem.';
    }
    if ($arquivo['size'] > 5 * 1024 * 1024) {
        return 'Imagem maior que o limite permitido (5MB).';
    }

    $mime = mime_content_type($arquivo['tmp_name']);
    if (!isset($tiposPermitidos[$mime])) {
        return 'Formato não suportado. Envie uma imagem JPG, PNG ou WEBP.';
    }

    $dirAvatares = __DIR__ . '/../assets/avatars';
    if (!is_dir($dirAvatares)) {
        mkdir($dirAvatares, 0755, true);
    }
    foreach (glob($dirAvatares . '/usuario_' . $usuarioId . '.*') as $antigo) {
        @unlink($antigo);
    }
    $nomeArquivo = 'usuario_' . $usuarioId . '.' . $tiposPermitidos[$mime];
    $caminhoDestino = $dirAvatares . '/' . $nomeArquivo;
    if (!move_uploaded_file($arquivo['tmp_name'], $caminhoDestino)) {
        return 'Não foi possível salvar a imagem no servidor.';
    }

    redimensionar_imagem_se_necessario($caminhoDestino, $mime, 400, 400);
    // Backup dos bytes no banco -- ver Migrations::restaurarUploadsPerdidos().
    $pdo->prepare('UPDATE usuarios SET foto_perfil = ?, foto_perfil_dados = ? WHERE id = ?')
        ->execute(['assets/avatars/' . $nomeArquivo, file_get_contents($caminhoDestino), $usuarioId]);

    return null;
}

/**
 * Troca a foto de um usuário da equipe por um dos avatares pré-definidos
 * (galeria de SVGs), zerando o backup de uma foto enviada antes (não serve
 * mais pra nada -- ver processar_upload_foto_usuario()). Mesmo motivo de
 * extração da função acima.
 */
function definir_avatar_predefinido_usuario(\PDO $pdo, int $usuarioId, int $indice): bool
{
    if ($indice < 1 || $indice > QUANTIDADE_AVATARES_PREDEFINIDOS) {
        return false;
    }
    $pdo->prepare('UPDATE usuarios SET foto_perfil = ?, foto_perfil_dados = NULL WHERE id = ?')
        ->execute(['assets/img/avatares/avatar-' . $indice . '.svg', $usuarioId]);

    return true;
}

/**
 * Processa o upload do LOGO DA EMPRESA de um cliente (campo separado da foto
 * do contato/avatar) -- usado tanto no cadastro rápido (clientes.php) quanto
 * na página completa do cliente (cliente.php), pra não duplicar a mesma regra
 * de validação/limite em dois lugares. Só faz algo se o campo $_FILES['logo']
 * tiver sido enviado; se não, não retorna erro (upload de logo é opcional).
 * Retorna null se deu tudo certo (ou se não tinha arquivo), ou uma mensagem
 * de erro pra mostrar via flash_set().
 */
function processar_upload_logo_cliente(\PDO $pdo, int $clienteId): ?string
{
    if (empty($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $arquivo = $_FILES['logo'];
    $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return 'Falha no upload do logo.';
    }
    if ($arquivo['size'] > 5 * 1024 * 1024) {
        return 'Logo maior que o limite permitido (5MB).';
    }

    $mime = mime_content_type($arquivo['tmp_name']);
    if (!isset($tiposPermitidos[$mime])) {
        return 'Formato de logo não suportado. Envie JPG, PNG, WEBP ou SVG.';
    }

    $dirLogos = __DIR__ . '/../assets/avatars';
    if (!is_dir($dirLogos)) {
        mkdir($dirLogos, 0755, true);
    }
    foreach (glob($dirLogos . '/logo_cliente_' . $clienteId . '.*') as $antigo) {
        @unlink($antigo);
    }
    $nomeArquivo = 'logo_cliente_' . $clienteId . '.' . $tiposPermitidos[$mime];
    $caminhoDestino = $dirLogos . '/' . $nomeArquivo;
    if (move_uploaded_file($arquivo['tmp_name'], $caminhoDestino)) {
        // Tamanho ideal do card = 92px de altura (ver .card-cliente-logo-wrap
        // no CSS) -- usa o dobro pra ficar nítido em tela retina/HiDPI, sem
        // deixar o arquivo do tamanho de uma foto de celular.
        redimensionar_imagem_se_necessario($caminhoDestino, $mime, 480, 184);
        // Backup dos bytes no banco -- ver Migrations::restaurarUploadsPerdidos().
        $pdo->prepare('UPDATE clientes SET logo = ?, logo_dados = ? WHERE id = ?')
            ->execute(['assets/avatars/' . $nomeArquivo, file_get_contents($caminhoDestino), $clienteId]);
    }

    return null;
}

/**
 * Formata telefone brasileiro pra exibição/preenchimento inicial de campo
 * (a máscara ENQUANTO a pessoa digita é feita em JS, ver assets/js/app.js
 * -- essa função aqui é só pra quando o valor já vem do banco sem máscara,
 * ex. registros antigos digitados como "17981368201").
 */
function formatar_telefone(?string $telefone): string
{
    $digitos = preg_replace('/\D/', '', $telefone ?? '');
    if ($digitos === '') {
        return '';
    }
    $digitos = substr($digitos, 0, 11);

    if (strlen($digitos) > 10) {
        return preg_replace('/(\d{2})(\d{5})(\d{0,4})/', '($1) $2-$3', $digitos);
    }
    if (strlen($digitos) > 5) {
        return preg_replace('/(\d{2})(\d{4})(\d{0,4})/', '($1) $2-$3', $digitos);
    }
    if (strlen($digitos) > 2) {
        return preg_replace('/(\d{2})(\d{0,5})/', '($1) $2', $digitos);
    }

    return '(' . $digitos;
}

function formatar_data(?string $data): string
{
    if (!$data) {
        return '-';
    }
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

/**
 * Formata minutos (de TIMESTAMPDIFF) num texto curto e legível, tipo "2 dias
 * e 3h" ou "45 min" -- usado nas métricas de tempo de atendimento de cada
 * usuário (ver usuarios.php). Null = ainda não há chamados fechados o
 * suficiente pra calcular a média.
 */
function formatar_duracao_minutos(?float $minutos): string
{
    if ($minutos === null) {
        return '--';
    }
    $minutos = (int) round($minutos);
    if ($minutos < 60) {
        return $minutos . ' min';
    }
    $horas = intdiv($minutos, 60);
    if ($horas < 24) {
        $restoMin = $minutos % 60;
        return $horas . 'h' . ($restoMin > 0 ? ' ' . $restoMin . 'min' : '');
    }
    $dias = intdiv($horas, 24);
    $restoHoras = $horas % 24;
    return $dias . ' dia' . ($dias > 1 ? 's' : '') . ($restoHoras > 0 ? ' e ' . $restoHoras . 'h' : '');
}

/**
 * Texto pequeno de comparação com o mês anterior, usado nos cards de KPI do
 * dashboard (chamados abertos/fechados no mês). Não usa cor de "bom/ruim"
 * -- mais chamado aberto não é necessariamente uma notícia ruim (pode ser só
 * mais cliente ativo), então fica neutro, só informando a variação.
 */
function tile_delta_html(int $atual, int $anterior): string
{
    if ($anterior === 0) {
        return '<div class="small text-muted mt-1">Mês passado: 0</div>';
    }
    $variacao = (($atual - $anterior) / $anterior) * 100;
    $icone = $variacao > 0 ? 'bi-arrow-up' : ($variacao < 0 ? 'bi-arrow-down' : 'bi-dash');
    $texto = ($variacao > 0 ? '+' : '') . number_format($variacao, 0) . '%';
    return '<div class="small text-muted mt-1"><i class="bi ' . $icone . '"></i> ' . e($texto) . ' vs mês passado (' . $anterior . ')</div>';
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
 * Mesmo badge de status, mas com o texto escrito do ponto de vista do
 * CLIENTE (usado só no portal) -- "Aguardando nós" faz sentido pra equipe
 * (nós = PrimeERP), mas pro cliente lendo a própria tela "nós" fica
 * ambíguo/parece que é ele quem está devendo resposta, quando é o
 * contrário (o status significa que a PrimeERP é quem precisa responder).
 * Mesmas cores/classes de badge_status_chamado(), só troca o texto.
 */
function badge_status_chamado_portal(string $status): string
{
    $mapa = [
        'aberto' => ['Aberto', 'badge-aberto', 'bi-envelope-open'],
        'aguardando_equipe' => ['Aguardando PrimeERP', 'badge-aguardando-equipe', 'bi-exclamation-circle-fill'],
        'aguardando_cliente' => ['Aguardando você', 'badge-aguardando-cliente', 'bi-hourglass-split'],
        'fechado' => ['Encerrado', 'badge-fechado', 'bi-x-circle'],
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

    // Essa função nunca escolhe 'fechado' (só o fechamento manual explícito
    // faz isso) -- ou seja, sempre que ela roda é porque o chamado está ou
    // continua aberto, então fechado_em sempre pode ser zerado aqui. Cobre
    // a reabertura IMPLÍCITA (cliente responde um chamado que estava
    // fechado -- ver comentário mais abaixo de quem chama essa função).
    $pdo->prepare('UPDATE chamados SET status = ?, fechado_em = NULL WHERE id = ?')->execute([$novoStatus, $chamadoId]);
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
        $html .= '<a href="' . e(url('download_anexo_chamado.php?id=' . $a['id'])) . '" class="anexo-link" target="_blank" rel="noopener" title="' . e($a['arquivo_nome_original']) . '">'
            . '<i class="bi ' . $icone . '"></i> <span class="anexo-nome">' . e($a['arquivo_nome_original']) . '</span></a>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Resumo com TODOS os anexos de um chamado (abertura + todas as respostas)
 * num único lugar, no topo do chamado -- pedido do Kleber pra não precisar
 * "garimpar" indo mensagem por mensagem procurando um documento específico
 * (comparou com o WhatsApp, que tem exatamente esse problema). Recebe o
 * mesmo array $anexosPorLinha já usado pra desenhar dentro de cada bolha do
 * chat (chave 'abertura' ou id da resposta), só reorganiza pra uma lista
 * plana ordenada por data. Não substitui os anexos dentro de cada bolha
 * (que dão o contexto de quando/quem enviou) -- é um atalho adicional.
 */
function render_resumo_anexos_chamado(array $anexosPorLinha): string
{
    $todos = array_merge(...array_values($anexosPorLinha ?: [[]]));
    if (!$todos) {
        return '';
    }

    usort($todos, fn ($a, $b) => strtotime($a['criado_em']) <=> strtotime($b['criado_em']));

    $html = '<div class="resumo-anexos-chamado mb-3">';
    $html .= '<div class="resumo-anexos-titulo"><i class="bi bi-paperclip"></i> Documentos deste chamado (' . count($todos) . ')</div>';
    $html .= '<div class="chat-anexos">';
    foreach ($todos as $a) {
        $extensao = strtolower(pathinfo($a['arquivo_nome_original'], PATHINFO_EXTENSION));
        $icone = in_array($extensao, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? 'bi-image' : 'bi-file-earmark-pdf';
        $html .= '<a href="' . e(url('download_anexo_chamado.php?id=' . $a['id'])) . '" class="anexo-link anexo-link-resumo" target="_blank" rel="noopener" title="' . e($a['arquivo_nome_original']) . '">'
            . '<i class="bi ' . $icone . '"></i> <span class="anexo-nome">' . e($a['arquivo_nome_original']) . '</span></a>';
    }
    $html .= '</div></div>';

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

/**
 * <option>s de prioridade coloridos (mesmas 4 cores do badge_prioridade(),
 * como texto -- <option> nativo não aceita muito estilo de fundo entre
 * navegadores, mas a cor do texto funciona bem e já resolve o problema
 * reportado pelo Kleber: "as prioridades do chamado precisam ter cores,
 * está tudo em branco". Reaproveitado em todo select de prioridade
 * (chamado da equipe, chamado do portal, demanda) pra não repetir a lista
 * de opções em cada tela.
 */
function opcoes_prioridade_html(string $selecionada = 'media'): string
{
    $mapa = [
        'baixa' => ['Baixa', '#15803d'],
        'media' => ['Média', '#b45309'],
        'alta' => ['Alta', '#be123c'],
        'urgente' => ['Urgente', '#b91c1c'],
    ];
    $html = '';
    foreach ($mapa as $valor => [$label, $cor]) {
        $sel = $valor === $selecionada ? ' selected' : '';
        $html .= '<option value="' . e($valor) . '" style="color:' . $cor . ';font-weight:600;"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}
