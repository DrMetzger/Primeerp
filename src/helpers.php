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
 * Paleta de cores por setor -- cada setor ganha uma cor própria e FIXA
 * (baseada no id, nunca muda de uma tela pra outra), pra parar de tudo
 * ficar na mesma cor índigo do resto do sistema (pedido do Kleber: "só
 * azul, tem que ser mais vivo"). Reaproveitada no ícone do setor, na borda
 * dos cards e nos eventos do calendário daquele setor.
 */
const CORES_SETOR = [
    '#4f46e5', // índigo
    '#0891b2', // ciano
    '#be123c', // rosa
    '#b45309', // âmbar
    '#15803d', // verde
    '#7c3aed', // violeta
    '#0e7490', // teal
    '#c2410c', // laranja
];

function cor_setor(int $setorId): string
{
    return CORES_SETOR[$setorId % count(CORES_SETOR)];
}

function icone_circulo_html(string $icone, string $cor): string
{
    return '<span class="icone-circulo" style="background-color:' . e($cor) . '1f;color:' . e($cor) . ';"><i class="bi ' . e($icone) . '"></i></span>';
}

function icone_setor_html(array $setor): string
{
    return icone_circulo_html($setor['icone'] ?: 'bi-diagram-3', cor_setor((int) $setor['id']));
}

/**
 * Resolve o avatar "efetivo" de uma pessoa pelo E-MAIL -- pedido do Kleber
 * depois de cadastrar um responsável de setor com o mesmo e-mail do contato
 * master do cliente (a mesma pessoa, usando dois logins diferentes) e ver
 * duas fotos diferentes (uma real, outra a inicial genérica) pra quem é,
 * na prática, uma pessoa só. Se o registro já tem foto própria, usa ela
 * (nunca sobrescreve escolha explícita); só cai na busca por e-mail quando
 * está vazio. Procura em clientes (contato master) primeiro, depois em
 * outros responsáveis de setor, depois na equipe -- primeiro que achar com
 * foto ganha.
 */
function resolver_avatar_por_email(\PDO $pdo, ?string $email, ?string $fotoPropria): ?string
{
    if ($fotoPropria) {
        return $fotoPropria;
    }
    if (!$email) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT foto_perfil FROM clientes WHERE contato_email = ? AND foto_perfil IS NOT NULL LIMIT 1');
    $stmt->execute([$email]);
    if ($foto = $stmt->fetchColumn()) {
        return $foto;
    }

    $stmt = $pdo->prepare('SELECT foto_perfil FROM setor_responsaveis WHERE email = ? AND foto_perfil IS NOT NULL LIMIT 1');
    $stmt->execute([$email]);
    if ($foto = $stmt->fetchColumn()) {
        return $foto;
    }

    $stmt = $pdo->prepare('SELECT foto_perfil FROM usuarios WHERE email = ? AND foto_perfil IS NOT NULL LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * Quem de fato abriu/é o dono do chamado -- pra notificação de resposta
 * (e qualquer outra) chamar a PESSOA certa pelo nome e mandar pro
 * telefone/e-mail dela, não sempre o contato master da empresa. Se foi
 * aberto por um responsável de setor específico (chamados.aberto_por_tipo
 * = 'setor'), resolve pra esse responsável; senão cai no contato master.
 */
function resolver_pessoa_do_chamado(\PDO $pdo, array $chamado, array $cliente): array
{
    if (($chamado['aberto_por_tipo'] ?? '') === 'setor' && !empty($chamado['aberto_por_setor_responsavel_id'])) {
        $stmt = $pdo->prepare('SELECT nome, email, telefone FROM setor_responsaveis WHERE id = ?');
        $stmt->execute([$chamado['aberto_por_setor_responsavel_id']]);
        if ($pessoa = $stmt->fetch()) {
            return $pessoa;
        }
    }

    return [
        'nome' => $cliente['contato_nome'] ?: $cliente['nome'],
        'email' => $cliente['contato_email'],
        'telefone' => $cliente['contato_telefone'] ?: ($chamado['telefone_whatsapp'] ?? null),
    ];
}

/**
 * Detecta chamado duplicado (mesmo cliente + título + descrição) criado nos
 * últimos $janelaSegundos -- guarda contra duplo-clique no formulário e
 * reenvio de webhook. Checa nos 3 pontos que fazem INSERT INTO chamados
 * (portal, admin, WhatsApp bot) antes de criar o registro.
 */
function chamado_e_duplicado_recente(\PDO $pdo, int $clienteId, string $titulo, string $descricao, int $janelaSegundos = 20): bool
{
    $stmt = $pdo->prepare('SELECT id FROM chamados
        WHERE cliente_id = ? AND titulo = ? AND descricao = ?
        AND criado_em >= (NOW() - INTERVAL ? SECOND)
        LIMIT 1');
    $stmt->execute([$clienteId, $titulo, $descricao, $janelaSegundos]);

    return (bool) $stmt->fetch();
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
 * Texto puro de dentro de um .docx (Word) -- o Gemini NÃO aceita esse mime
 * como inline_data (só PDF/imagem/texto/áudio/vídeo), então pra ele "ler" um
 * diagrama de processo em Word (comum nos anexos de Implantação) é preciso
 * extrair o texto aqui e mandar como texto puro em vez do arquivo em si.
 * .docx é um .zip com XML dentro -- word/document.xml tem o texto do corpo.
 * Retorna null se não conseguir abrir/ler (arquivo corrompido, não é
 * zip válido, etc.) -- quem chamar trata como "não deu pra ler".
 */
function extrair_texto_docx(string $bytesDocx): ?string
{
    $arquivoTemp = tempnam(sys_get_temp_dir(), 'docx_');
    if ($arquivoTemp === false) {
        return null;
    }
    file_put_contents($arquivoTemp, $bytesDocx);

    $zip = new \ZipArchive();
    $textoExtraido = null;
    if ($zip->open($arquivoTemp) === true) {
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml !== false) {
            // Cada quebra de parágrafo do Word (</w:p>) some ao tirar as
            // tags -- troca por quebra de linha ANTES de remover as tags,
            // senão o texto todo vira um parágrafo gigante sem separação.
            $xml = str_replace('</w:p>', "\n", $xml);
            $textoExtraido = trim(html_entity_decode(strip_tags($xml), ENT_QUOTES, 'UTF-8'));
        }
    }

    // ZipArchive::close() pode remover o arquivo sozinho quando os bytes não
    // formam um .docx de verdade (ex.: arquivo vazio é aceito por open() como
    // "zip vazio válido", mas close() apaga o arquivo por não ter conteúdo
    // pra gravar) -- confere antes pra não gerar warning de unlink() em
    // arquivo que já não existe mais.
    if (file_exists($arquivoTemp)) {
        unlink($arquivoTemp);
    }
    return $textoExtraido !== '' ? $textoExtraido : null;
}

/**
 * Compara o wa_id que a Meta manda (DDI + DDD + número, ex: 5511988887777)
 * com o telefone guardado no cadastro (só DDD + número, sem DDI, ex:
 * 11988887777 ou 1188887777) -- usado pelo bot do WhatsApp pra achar de
 * qual cliente/responsável de setor é aquele número. Gera algumas variações
 * de cada lado (com/sem o "9" extra do celular, que nem todo cadastro tem)
 * em vez de exigir formato exato, porque na prática ninguém digita telefone
 * de forma 100% padronizada.
 */
function telefones_correspondem(string $waId, ?string $telefoneCadastrado): bool
{
    if (!$telefoneCadastrado) {
        return false;
    }

    $variacoes = function (string $digitos): array {
        $v = [$digitos];
        if (str_starts_with($digitos, '55') && strlen($digitos) > 10) {
            $v[] = substr($digitos, 2);
        }
        // DDD (2) + 9 (celular) + 8 dígitos = 11; sem o 9 = 10.
        if (strlen($digitos) === 11 && $digitos[2] === '9') {
            $v[] = substr($digitos, 0, 2) . substr($digitos, 3);
        } elseif (strlen($digitos) === 10) {
            $v[] = substr($digitos, 0, 2) . '9' . substr($digitos, 2);
        }
        return $v;
    };

    $ladoA = $variacoes(apenas_digitos($waId) ?? '');
    $ladoB = $variacoes(apenas_digitos($telefoneCadastrado) ?? '');

    return (bool) array_intersect($ladoA, $ladoB);
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
 * Mesma ideia de processar_upload_foto_usuario(), pro responsável de um
 * SETOR (setor_responsaveis) -- login próprio, separado do master do
 * cliente. Retorna null se deu tudo certo, ou uma mensagem de erro.
 */
function processar_upload_foto_setor_responsavel(\PDO $pdo, int $responsavelId): ?string
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
    foreach (glob($dirAvatares . '/setor_responsavel_' . $responsavelId . '.*') as $antigo) {
        @unlink($antigo);
    }
    $nomeArquivo = 'setor_responsavel_' . $responsavelId . '.' . $tiposPermitidos[$mime];
    $caminhoDestino = $dirAvatares . '/' . $nomeArquivo;
    if (!move_uploaded_file($arquivo['tmp_name'], $caminhoDestino)) {
        return 'Não foi possível salvar a imagem no servidor.';
    }

    redimensionar_imagem_se_necessario($caminhoDestino, $mime, 400, 400);
    $pdo->prepare('UPDATE setor_responsaveis SET foto_perfil = ?, foto_perfil_dados = ? WHERE id = ?')
        ->execute(['assets/avatars/' . $nomeArquivo, file_get_contents($caminhoDestino), $responsavelId]);

    return null;
}

function definir_avatar_predefinido_setor_responsavel(\PDO $pdo, int $responsavelId, int $indice): bool
{
    if ($indice < 1 || $indice > QUANTIDADE_AVATARES_PREDEFINIDOS) {
        return false;
    }
    $pdo->prepare('UPDATE setor_responsaveis SET foto_perfil = ?, foto_perfil_dados = NULL WHERE id = ?')
        ->execute(['assets/img/avatares/avatar-' . $indice . '.svg', $responsavelId]);

    return true;
}

/**
 * Mesma ideia de processar_upload_foto_usuario(), pro CONTATO/key user
 * (login master) de um cliente -- usada em portal/perfil.php. Não confundir
 * com processar_upload_logo_cliente(), que é o logo da EMPRESA, campo
 * separado.
 */
function processar_upload_foto_cliente(\PDO $pdo, int $clienteId): ?string
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
    foreach (glob($dirAvatares . '/cliente_' . $clienteId . '.*') as $antigo) {
        @unlink($antigo);
    }
    $nomeArquivo = 'cliente_' . $clienteId . '.' . $tiposPermitidos[$mime];
    $caminhoDestino = $dirAvatares . '/' . $nomeArquivo;
    if (!move_uploaded_file($arquivo['tmp_name'], $caminhoDestino)) {
        return 'Não foi possível salvar a imagem no servidor.';
    }

    redimensionar_imagem_se_necessario($caminhoDestino, $mime, 400, 400);
    $pdo->prepare('UPDATE clientes SET foto_perfil = ?, foto_perfil_dados = ? WHERE id = ?')
        ->execute(['assets/avatars/' . $nomeArquivo, file_get_contents($caminhoDestino), $clienteId]);

    return null;
}

function definir_avatar_predefinido_cliente(\PDO $pdo, int $clienteId, int $indice): bool
{
    if ($indice < 1 || $indice > QUANTIDADE_AVATARES_PREDEFINIDOS) {
        return false;
    }
    $pdo->prepare('UPDATE clientes SET foto_perfil = ?, foto_perfil_dados = NULL WHERE id = ?')
        ->execute(['assets/img/avatares/avatar-' . $indice . '.svg', $clienteId]);

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
 * Salva um upload genérico na tabela documentos -- extraído pra ser
 * reaproveitado tanto pelo upload "solto" (aba Setores/Visão geral) quanto
 * por um anexo de proposta de desenvolvimento (que quer o mesmo
 * backup/restauração/download que documento já tem, sem duplicar a lógica).
 * $arquivo é o item de $_FILES (ex.: $_FILES['arquivo']). Retorna o id do
 * documento criado, ou null se o upload falhar (erro fica em $erro, por
 * referência, pra quem chamou decidir a mensagem de flash).
 */
function salvar_documento_upload(\PDO $pdo, int $clienteId, ?int $setorId, string $titulo, ?string $descricao, ?string $categoria, array $arquivo, ?int $criadoPor, ?string &$erro = null): ?int
{
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        $erro = 'Falha no upload do arquivo.';
        return null;
    }
    if ($arquivo['size'] > UPLOADS_MAX_SIZE) {
        $erro = 'Arquivo maior que o limite permitido (15MB).';
        return null;
    }

    $dirCliente = UPLOADS_DIR . '/clientes/' . $clienteId;
    if (!is_dir($dirCliente)) {
        mkdir($dirCliente, 0755, true);
    }
    $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    $nomeArmazenado = bin2hex(random_bytes(16)) . ($extensao ? '.' . $extensao : '');
    $destino = $dirCliente . '/' . $nomeArmazenado;

    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        $erro = 'Não foi possível salvar o arquivo no servidor.';
        return null;
    }

    // Backup dos bytes no banco -- ver Migrations::restaurarArquivoSeNecessario().
    $stmt = $pdo->prepare('INSERT INTO documentos (cliente_id, setor_id, titulo, descricao, categoria, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, criado_por, dados) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $clienteId,
        $setorId,
        $titulo ?: $arquivo['name'],
        $descricao,
        $categoria,
        $arquivo['name'],
        'clientes/' . $clienteId . '/' . $nomeArmazenado,
        $arquivo['size'],
        $criadoPor,
        file_get_contents($destino),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Formata telefone brasileiro pra exibição/preenchimento inicial de campo
 * (a máscara ENQUANTO a pessoa digita é feita em JS, ver assets/js/app.js
 * -- essa função aqui é só pra quando o valor já vem do banco sem máscara,
 * ex. registros antigos digitados como "17981368201").
 */
function formatar_cnpj(?string $cnpj): string
{
    $digitos = preg_replace('/\D/', '', $cnpj ?? '');
    if (strlen($digitos) !== 14) {
        return $cnpj ?? '';
    }

    return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos);
}

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
 * Substitui quem deve ser avisado (e-mail + WhatsApp) de um treinamento/
 * reunião -- $destinatariosPost vem do checkbox <input name="destinatarios[]">
 * do modal em cliente.php, com valores "master" ou "responsavel_<id>". Valida
 * que cada responsavel_id realmente pertence a esse cliente antes de salvar,
 * pra não deixar alguém marcar o id de outra empresa via POST manipulado.
 */
function salvar_destinatarios_treinamento(\PDO $pdo, int $treinamentoId, array $destinatariosPost, int $clienteId): void
{
    $pdo->prepare('DELETE FROM treinamento_destinatarios WHERE treinamento_id = ?')->execute([$treinamentoId]);

    $idsResponsaveisValidos = array_column(
        (function () use ($pdo, $clienteId) {
            $stmt = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE cliente_id = ?');
            $stmt->execute([$clienteId]);
            return $stmt->fetchAll();
        })(),
        'id'
    );
    $idsResponsaveisValidos = array_map('intval', $idsResponsaveisValidos);

    $stmt = $pdo->prepare('INSERT INTO treinamento_destinatarios (treinamento_id, tipo, setor_responsavel_id) VALUES (?, ?, ?)');
    foreach ($destinatariosPost as $valor) {
        if ($valor === 'master') {
            $stmt->execute([$treinamentoId, 'master', null]);
        } elseif (str_starts_with($valor, 'responsavel_')) {
            $responsavelId = (int) substr($valor, strlen('responsavel_'));
            if (in_array($responsavelId, $idsResponsaveisValidos, true)) {
                $stmt->execute([$treinamentoId, 'setor_responsavel', $responsavelId]);
            }
        }
    }
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
            $erros[] = '"' . $arquivos['name'][$i] . '" não é um tipo de arquivo permitido (use imagem, PDF, Word/Excel/PowerPoint, texto ou ZIP).';
            continue;
        }

        if (!is_dir($dirChamado)) {
            mkdir($dirChamado, 0755, true);
        }

        $nomeArmazenado = bin2hex(random_bytes(16)) . '.' . $extensaoPermitida;
        $destino = $dirChamado . '/' . $nomeArmazenado;

        if (move_uploaded_file($arquivos['tmp_name'][$i], $destino)) {
            // Backup dos bytes no banco -- ver Migrations::restaurarArquivoSeNecessario().
            // uploads/chamados/ some do disco no mesmo tipo de deploy que já
            // apagou foto/logo antes (Hostinger recria a pasta do zero, não
            // é protegido por .gitignore); sem esse backup o anexo se perde
            // pra sempre no próximo deploy.
            $pdo->prepare('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $chamadoId,
                    $respostaId,
                    $enviadoPor,
                    $arquivos['name'][$i],
                    'chamados/' . $chamadoId . '/' . $nomeArmazenado,
                    $arquivos['size'][$i],
                    file_get_contents($destino),
                ]);
        } else {
            $erros[] = 'Não foi possível salvar "' . $arquivos['name'][$i] . '" no servidor.';
        }
    }

    return $erros;
}

/**
 * Mesma ideia de processar_anexos_chamado(), mas pra bytes que já chegaram
 * na memória (não um upload via formulário) -- usado pelo bot do WhatsApp,
 * que baixa a mídia da API da Meta antes de ter qualquer $_FILES. Retorna
 * uma mensagem de erro, ou null se salvou certo.
 */
function salvar_bytes_como_anexo_chamado(\PDO $pdo, int $chamadoId, ?int $respostaId, string $enviadoPor, string $nomeOriginal, string $mime, string $bytes): ?string
{
    $extensaoPermitida = ANEXO_CHAMADO_TIPOS_PERMITIDOS[$mime] ?? null;
    if (!$extensaoPermitida) {
        return '"' . $nomeOriginal . '" não é um tipo de arquivo permitido (use imagem, PDF, Word/Excel/PowerPoint, texto ou ZIP).';
    }
    if (strlen($bytes) > ANEXO_CHAMADO_MAX_SIZE) {
        return '"' . $nomeOriginal . '" é maior que o limite de ' . (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) . 'MB.';
    }

    $dirChamado = UPLOADS_DIR . '/chamados/' . $chamadoId;
    if (!is_dir($dirChamado)) {
        mkdir($dirChamado, 0755, true);
    }

    $nomeArmazenado = bin2hex(random_bytes(16)) . '.' . $extensaoPermitida;
    $destino = $dirChamado . '/' . $nomeArmazenado;

    if (file_put_contents($destino, $bytes) === false) {
        return 'Não foi possível salvar "' . $nomeOriginal . '" no servidor.';
    }

    $pdo->prepare('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$chamadoId, $respostaId, $enviadoPor, $nomeOriginal, 'chamados/' . $chamadoId . '/' . $nomeArmazenado, strlen($bytes), $bytes]);

    return null;
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
        $html .= render_anexo_item_html($a, 'anexo-link', true);
    }
    $html .= '</div>';

    return $html;
}

/**
 * Um anexo (imagem ou documento) de chamado -- imagem mostra a MINIATURA de
 * verdade em vez de só nome+ícone, que obrigava abrir arquivo por arquivo
 * pra saber o que era (comparado pelo Kleber com o mesmo problema no
 * WhatsApp). $comLightbox decide o que o clique faz: true = abre em tela
 * grande sem baixar nada (dentro da bolha do chat, pra só OLHAR rápido);
 * false = baixa de verdade via download_anexo_chamado.php (resumo "Documentos
 * deste chamado" no topo -- ali o Kleber quer o link de download mesmo,
 * pra salvar/reenviar o arquivo, não só espiar). Documento (PDF etc.)
 * sempre baixa, com ou sem lightbox.
 */
function render_anexo_item_html(array $a, string $classeExtra, bool $comLightbox): string
{
    $url = e(url('download_anexo_chamado.php?id=' . $a['id']));
    $extensao = strtolower(pathinfo($a['arquivo_nome_original'], PATHINFO_EXTENSION));
    $ehImagem = in_array($extensao, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);

    if ($ehImagem && $comLightbox) {
        return '<a href="' . $url . '" class="' . $classeExtra . ' anexo-link-imagem botao-abrir-lightbox" data-lightbox-src="' . $url . '" data-lightbox-legenda="' . e($a['arquivo_nome_original']) . '" title="' . e($a['arquivo_nome_original']) . '">'
            . '<img src="' . $url . '" class="anexo-thumb" alt="' . e($a['arquivo_nome_original']) . '" loading="lazy"></a>';
    }

    if ($ehImagem) {
        return '<a href="' . $url . '" class="' . $classeExtra . ' anexo-link-imagem" target="_blank" rel="noopener" title="Baixar ' . e($a['arquivo_nome_original']) . '">'
            . '<img src="' . $url . '" class="anexo-thumb" alt="' . e($a['arquivo_nome_original']) . '" loading="lazy"></a>';
    }

    return '<a href="' . $url . '" class="' . $classeExtra . '" target="_blank" rel="noopener" title="' . e($a['arquivo_nome_original']) . '">'
        . '<i class="bi bi-file-earmark-pdf"></i> <span class="anexo-nome">' . e($a['arquivo_nome_original']) . '</span></a>';
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
        $html .= render_anexo_item_html($a, 'anexo-link anexo-link-resumo', false);
    }
    $html .= '</div></div>';

    return $html;
}

/**
 * Mostra na tela do chamado (admin e portal) que aconteceu uma reunião/
 * conversa/treinamento/auxílio vinculado a ele (ver treinamentos.chamado_id)
 * -- pedido do Kleber pra poder registrar que rolou um Meet por causa de um
 * chamado e ver isso mais tarde sem precisar procurar na aba Setores.
 */
function render_reunioes_vinculadas_chamado(array $treinamentos): string
{
    if (!$treinamentos) {
        return '';
    }

    $html = '<div class="resumo-reunioes-chamado mb-3"><div class="resumo-anexos-titulo"><i class="bi bi-calendar-event"></i> Reuniões/conversas vinculadas (' . count($treinamentos) . ')</div>';
    foreach ($treinamentos as $t) {
        $quando = $t['data_inicio'] ? formatar_data($t['data_inicio']) . ($t['hora_inicio'] ? ' ' . substr($t['hora_inicio'], 0, 5) : '') : 'sem data';
        $html .= '<div class="reuniao-vinculada-item">' . badge_tipo_treinamento($t['tipo']) . ' <strong>' . e($t['titulo']) . '</strong> <span class="text-muted small">' . e($quando) . '</span>';
        if ($t['link_reuniao']) {
            $html .= ' <a href="' . e($t['link_reuniao']) . '" target="_blank" class="small"><i class="bi bi-camera-video"></i> Entrar</a>';
        }
        $html .= '</div>';
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

/**
 * Badge do tipo de evento da agenda (treinamentos.tipo) -- treinamento e
 * reunião já existiam, conversa/auxílio foram adicionados pra cobrir
 * bate-papo rápido e ajuda pontual (geralmente decorrente de um chamado),
 * que não são nem treinamento formal nem reunião de verdade.
 */
function badge_tipo_treinamento(string $tipo): string
{
    $mapa = [
        'treinamento' => ['Treinamento', 'bg-secondary-subtle text-secondary-emphasis', 'bi-mortarboard'],
        'reuniao' => ['Reunião', 'bg-info-subtle text-info-emphasis', 'bi-camera-video'],
        'conversa' => ['Conversa', 'bg-success-subtle text-success-emphasis', 'bi-chat-dots'],
        'auxilio' => ['Auxílio', 'bg-warning-subtle text-warning-emphasis', 'bi-life-preserver'],
    ];
    [$label, $classe, $icone] = $mapa[$tipo] ?? [$tipo, 'bg-secondary-subtle text-secondary-emphasis', 'bi-circle'];
    return '<span class="badge ' . $classe . '"><i class="bi ' . e($icone) . '"></i> ' . e($label) . '</span>';
}

/**
 * <option>s de prioridade coloridos (mesmas 4 cores do badge_prioridade(),
 * como texto -- <option> nativo não aceita muito estilo de fundo entre
 * navegadores, mas a cor do texto funciona bem e já resolve o problema
 * reportado pelo Kleber: "as prioridades do chamado precisam ter cores,
 * está tudo em branco". Reaproveitado em todo select de prioridade
 * (chamado da equipe, chamado do portal) pra não repetir a lista
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
