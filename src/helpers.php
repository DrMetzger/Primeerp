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
 * Fila de notificações (e-mail/WhatsApp) pra rodar DEPOIS que a resposta já
 * foi mandada pro navegador -- ver finalizar_resposta_e_notificar(). Cada
 * chamada de API externa (Graph API do WhatsApp, SMTP) já tem timeout curto
 * (12-20s) pra não travar pra sempre, mas SOMAR várias seguidas (responder
 * chamado pode disparar e-mail + WhatsApp pro cliente + WhatsApp do bot de
 * pós-resposta, por exemplo) passava do max_execution_time em rede lenta --
 * a requisição morria sem NENHUMA resposta pro navegador (nem erro, nem
 * sucesso), travando a barra de progresso em "Registrando..." pra sempre.
 * Bug real reportado pelo Kleber. Enfileirar em vez de chamar direto resolve
 * isso: o redirect sai pro navegador na hora, essas chamadas continuam
 * rodando em segundo plano no mesmo processo PHP.
 */
$GLOBALS['__fila_notificacoes'] = [];

function notificar_depois(callable $callback): void
{
    $GLOBALS['__fila_notificacoes'][] = $callback;
}

/**
 * Fecha a resposta HTTP pro navegador AGORA (chamar depois de já ter
 * mandado o header('Location: ...') do redirect de POST) e só então executa
 * a fila de notificações registrada via notificar_depois() -- ver o
 * comentário lá acima.
 *
 * IMPORTANTE: a Hostinger roda PHP via LiteSpeed/lsphp (LSAPI), NÃO
 * FastCGI/PHP-FPM -- fastcgi_finish_request() não existe (ou não faz nada
 * de verdade) nesse SAPI. A função equivalente de verdade é
 * litespeed_finish_request(). Primeira versão desse fix só checava
 * fastcgi_finish_request() e por isso NÃO tinha efeito nenhum em produção
 * (bug continuou acontecendo -- reportado pelo Kleber de novo). Checa as
 * duas, na ordem certa pro SAPI de cada host, com um fallback manual
 * (Content-Length + flush) pra qualquer outro SAPI sem nenhuma das duas.
 */
function finalizar_resposta_e_notificar(): void
{
    session_write_close();

    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Fallback genérico (mod_php, CLI server etc.) -- manda o
        // Content-Length exato do que já foi gerado e fecha a conexão
        // explicitamente, pro navegador saber que a resposta terminou
        // mesmo sem uma função nativa de "termina agora, continua depois".
        ignore_user_abort(true);
        $conteudoAcumulado = '';
        while (ob_get_level() > 0) {
            $conteudoAcumulado .= ob_get_clean();
        }
        if (!headers_sent()) {
            header('Content-Length: ' . strlen($conteudoAcumulado));
            header('Connection: close');
        }
        echo $conteudoAcumulado;
        flush();
    }

    foreach ($GLOBALS['__fila_notificacoes'] as $callback) {
        try {
            $callback();
        } catch (\Throwable $e) {
            error_log('Falha ao executar notificação em segundo plano: ' . $e->getMessage());
        }
    }
    $GLOBALS['__fila_notificacoes'] = [];
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

// Mesma paleta/regra de cor_setor() acima, reaproveitada pra dar uma cor
// própria e fixa a cada CLIENTE (ver agenda.php) -- é só um hash por id, não
// tem nada de "setor" nele de verdade, mas não faz sentido duplicar a
// paleta pra isso.
function cor_cliente(int $clienteId): string
{
    return CORES_SETOR[$clienteId % count(CORES_SETOR)];
}

// "HH:MM" ou "HH:MM:SS" (como vem do banco pra coluna TIME) -> minutos desde
// meia-noite. Usado pelo Gantt por horário da Agenda (ver agenda.php) tanto
// pra desenhar a régua/posicionar os cards quanto pra validar horário
// sugerido pela IA.
function minutos_do_dia(string $horaHms): int
{
    $partes = explode(':', $horaHms);
    return ((int) ($partes[0] ?? 0)) * 60 + ((int) ($partes[1] ?? 0));
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
 * Todos os setor_id que um responsável de setor pertence hoje (ver tabela
 * setor_responsavel_setores em Migrations.php -- um responsável agora pode
 * estar em vários setores ao mesmo tempo, não só um). Vazio = sem setor
 * nenhum ainda.
 */
function setores_do_responsavel(\PDO $pdo, int $responsavelId): array
{
    $stmt = $pdo->prepare('SELECT setor_id FROM setor_responsavel_setores WHERE setor_responsavel_id = ? ORDER BY setor_id ASC');
    $stmt->execute([$responsavelId]);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}

/** Mesma ideia de setores_do_responsavel(), só que pro contato MASTER do cliente. */
function setores_do_cliente_master(\PDO $pdo, int $clienteId): array
{
    $stmt = $pdo->prepare('SELECT setor_id FROM cliente_setores WHERE cliente_id = ? ORDER BY setor_id ASC');
    $stmt->execute([$clienteId]);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}

/**
 * setor_responsaveis.setor_id continua existindo como "setor principal" --
 * é o que o login, o bot do WhatsApp e o pré-preenchimento de "abrir
 * chamado" leem (não precisaram mudar pra saber lidar com vários setores).
 * Chame isso depois de QUALQUER alteração em setor_responsavel_setores pra
 * manter esse valor em sincronia (o menor setor_id do conjunto atual, ou
 * NULL se a pessoa não estiver em setor nenhum).
 */
function sincronizar_setor_principal_responsavel(\PDO $pdo, int $responsavelId): void
{
    $setores = setores_do_responsavel($pdo, $responsavelId);
    $pdo->prepare('UPDATE setor_responsaveis SET setor_id = ? WHERE id = ?')->execute([$setores[0] ?? null, $responsavelId]);
}

/** Mesma ideia de sincronizar_setor_principal_responsavel(), pro contato MASTER. */
function sincronizar_setor_principal_master(\PDO $pdo, int $clienteId): void
{
    $setores = setores_do_cliente_master($pdo, $clienteId);
    $pdo->prepare('UPDATE clientes SET setor_id = ? WHERE id = ?')->execute([$setores[0] ?? null, $clienteId]);
}

/**
 * Nome de setor precisa ter pelo menos 1 letra ou número -- só "-" (ou
 * "...", "___" etc.) passa pela checagem de "não vazio" mas não é um nome
 * de verdade, e polui os cards da aba Chamados.
 */
function nome_setor_valido(string $nome): bool
{
    return (bool) preg_match('/[\p{L}\p{N}]/u', $nome);
}

/**
 * Filtra uma lista bruta de setor_id (vinda de $_POST, ex.: setor_ids[])
 * pra só os que realmente pertencem a esse cliente -- sem isso, alguém
 * poderia forjar o id de um setor de OUTRO cliente no formulário e vincular
 * uma pessoa a ele.
 */
function validar_setor_ids_do_cliente(\PDO $pdo, int $clienteId, array $setorIdsBrutos): array
{
    $setorIds = array_values(array_unique(array_map('intval', $setorIdsBrutos)));
    if (!$setorIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($setorIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM setores WHERE cliente_id = ? AND id IN ($placeholders)");
    $stmt->execute([$clienteId, ...$setorIds]);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}

/**
 * Substitui de uma vez TODOS os setores de um responsável pelo conjunto
 * informado (usado pelo multi-select da aba Usuários -- manda a lista
 * completa desejada a cada salvamento, não um delta). Retorna a lista já
 * validada/aplicada.
 */
function substituir_setores_do_responsavel(\PDO $pdo, int $clienteId, int $responsavelId, array $setorIdsBrutos): array
{
    $setorIds = validar_setor_ids_do_cliente($pdo, $clienteId, $setorIdsBrutos);
    $pdo->prepare('DELETE FROM setor_responsavel_setores WHERE setor_responsavel_id = ?')->execute([$responsavelId]);
    $stmt = $pdo->prepare('INSERT INTO setor_responsavel_setores (setor_responsavel_id, setor_id) VALUES (?, ?)');
    foreach ($setorIds as $setorId) {
        $stmt->execute([$responsavelId, $setorId]);
    }
    sincronizar_setor_principal_responsavel($pdo, $responsavelId);
    return $setorIds;
}

/** Mesma ideia de substituir_setores_do_responsavel(), pro contato MASTER. */
function substituir_setores_do_cliente_master(\PDO $pdo, int $clienteId, array $setorIdsBrutos): array
{
    $setorIds = validar_setor_ids_do_cliente($pdo, $clienteId, $setorIdsBrutos);
    $pdo->prepare('DELETE FROM cliente_setores WHERE cliente_id = ?')->execute([$clienteId]);
    $stmt = $pdo->prepare('INSERT INTO cliente_setores (cliente_id, setor_id) VALUES (?, ?)');
    foreach ($setorIds as $setorId) {
        $stmt->execute([$clienteId, $setorId]);
    }
    sincronizar_setor_principal_master($pdo, $clienteId);
    return $setorIds;
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
 * Processa o upload do timbrado (cabeçalho OU rodapé) real do PDF -- ver
 * src/PdfExport.php, configuracoes.php. $tipo é 'cabecalho' ou 'rodape',
 * usado tanto pro nome do campo ($_FILES['timbrado_' . $tipo]) quanto pra
 * escolher a coluna certa em timbrado_pdf. Só JPG/PNG -- é o que o FPDF
 * "puro" (sem extensões) sabe decodificar nativamente (nada de WEBP/SVG
 * aqui, diferente do logo do cliente acima). Opcional: se não veio arquivo
 * nenhum, não retorna erro (só não troca a imagem já salva). Retorna null
 * se deu tudo certo, ou uma mensagem de erro pra mostrar via flash_set().
 */
function processar_upload_timbrado(\PDO $pdo, string $tipo): ?string
{
    $campo = 'timbrado_' . $tipo;
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $arquivo = $_FILES[$campo];
    $tiposPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return 'Falha no upload da imagem de ' . $tipo . '.';
    }
    if ($arquivo['size'] > 5 * 1024 * 1024) {
        return 'Imagem de ' . $tipo . ' maior que o limite permitido (5MB).';
    }

    $mime = mime_content_type($arquivo['tmp_name']);
    if (!isset($tiposPermitidos[$mime])) {
        return 'Formato não suportado pra ' . $tipo . '. Envie uma imagem JPG ou PNG (é o que o gerador de PDF sabe ler).';
    }

    $dirTimbrado = __DIR__ . '/../assets/img';
    foreach (glob($dirTimbrado . '/timbrado-' . $tipo . '.*') as $antigo) {
        @unlink($antigo);
    }
    $nomeArquivo = 'timbrado-' . $tipo . '.' . $tiposPermitidos[$mime];
    $caminhoDestino = $dirTimbrado . '/' . $nomeArquivo;
    if (!move_uploaded_file($arquivo['tmp_name'], $caminhoDestino)) {
        return 'Não foi possível salvar a imagem de ' . $tipo . ' no servidor.';
    }

    // Cap generoso (largura de banner, não uma foto) -- só pra não guardar
    // um arquivo do tamanho de uma foto de celular sem necessidade nenhuma,
    // preservando a proporção (ver redimensionar_imagem_se_necessario()).
    redimensionar_imagem_se_necessario($caminhoDestino, $mime, 3000, 800);

    $colunaCaminho = $tipo . '_caminho';
    $colunaDados = $tipo . '_dados';
    // Backup dos bytes no banco -- mesmo motivo/padrão de logo/foto de
    // usuário, ver Migrations::restaurarArquivoSeNecessario().
    $pdo->prepare("UPDATE timbrado_pdf SET $colunaCaminho = ?, $colunaDados = ? WHERE id = 1")
        ->execute(['assets/img/' . $nomeArquivo, file_get_contents($caminhoDestino)]);

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
 *
 * $tamanhoMaximo permite um limite diferente de UPLOADS_MAX_SIZE pra quem
 * chamar (ex.: vídeo aula, que pode ser bem maior que documento comum) --
 * null usa o padrão. $comBackupBanco desliga o backup do arquivo no banco
 * (ver comentário abaixo): pra arquivo MUITO grande (vídeo de vários GB),
 * ler o arquivo inteiro pra memória com file_get_contents() pode estourar o
 * memory_limit e derrubar a requisição de um jeito NÃO recuperável (erro de
 * memória não é um Throwable pego pelo catch abaixo) -- melhor nem tentar.
 */
function salvar_documento_upload(\PDO &$pdo, int $clienteId, ?int $setorId, string $titulo, ?string $descricao, ?string $categoria, array $arquivo, ?int $criadoPor, ?string &$erro = null, ?int $tamanhoMaximo = null, bool $comBackupBanco = true): ?int
{
    $tamanhoMaximo ??= UPLOADS_MAX_SIZE;

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        $erro = 'Falha no upload do arquivo.';
        return null;
    }
    if ($arquivo['size'] > $tamanhoMaximo) {
        $erro = 'Arquivo maior que o limite permitido (' . (int) ($tamanhoMaximo / 1024 / 1024) . 'MB).';
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

    if (!$comBackupBanco) {
        $stmt = $pdo->prepare('INSERT INTO documentos (cliente_id, setor_id, titulo, descricao, categoria, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
        ]);

        return (int) $pdo->lastInsertId();
    }

    // Backup dos bytes no banco -- ver Migrations::restaurarArquivoSeNecessario().
    // Arquivo grande (até 500MB) pode passar do max_allowed_packet configurado
    // no MySQL da hospedagem (fora do nosso controle) -- se acontecer, o
    // arquivo já está salvo em disco (linha acima), então não trava a
    // requisição por causa só do backup: grava sem o backup e loga o motivo.
    try {
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
    } catch (\Throwable $e) {
        error_log('salvar_documento_upload: falha ao gravar backup do arquivo no banco (' . $e->getMessage() . ')');
        // Erro de max_allowed_packet derruba a conexão ("MySQL server has
        // gone away") -- reconecta antes de tentar de novo, senão a query
        // abaixo falha igual mesmo sem o BLOB.
        $pdo = \AdminPrime\Database::reconnect();
        $stmt = $pdo->prepare('INSERT INTO documentos (cliente_id, setor_id, titulo, descricao, categoria, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
        ]);
    }

    return (int) $pdo->lastInsertId();
}

/**
 * Mesma ideia de salvar_documento_upload(), mas pra VÁRIOS arquivos de uma
 * vez (categoria fixa "Implantação") -- usado pela equipe em cliente.php
 * (anexos gerais e por etapa da aba Implantação). Antes só dava pra anexar
 * um arquivo por vez, o que obrigava repetir o formulário várias vezes pra
 * um lote de documentos (pedido do Kleber). Espera o formato multi-arquivo
 * do PHP ($_FILES['arquivos'] com name[]/tmp_name[]/etc, ver <input multiple>).
 * Retorna um array de mensagens de erro (vazio = tudo certo).
 */
function salvar_documentos_implantacao(\PDO $pdo, int $clienteId, ?int $etapaId, array $arquivosFiles, ?int $criadoPor): array
{
    $erros = [];
    $quantidade = count($arquivosFiles['name'] ?? []);

    for ($i = 0; $i < $quantidade; $i++) {
        if ($arquivosFiles['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $arquivoUnico = [
            'name' => $arquivosFiles['name'][$i],
            'type' => $arquivosFiles['type'][$i],
            'tmp_name' => $arquivosFiles['tmp_name'][$i],
            'error' => $arquivosFiles['error'][$i],
            'size' => $arquivosFiles['size'][$i],
        ];

        $erroUnico = null;
        $idDoc = salvar_documento_upload($pdo, $clienteId, null, $arquivoUnico['name'], null, 'Implantação', $arquivoUnico, $criadoPor, $erroUnico);

        if ($idDoc && $etapaId) {
            $pdo->prepare('UPDATE documentos SET implantacao_etapa_id = ? WHERE id = ?')->execute([$etapaId, $idDoc]);
        }

        if (!$idDoc) {
            $erros[] = $erroUnico ?: ('Não foi possível enviar "' . $arquivoUnico['name'] . '".');
        }
    }

    return $erros;
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
 * Formata o valor/meta de um KPI de cliente (aba KPIs/Métricas) -- número
 * de chamados não pode ganhar duas casas decimais à toa ("0,00 chamados"
 * não faz sentido nenhum), então só mostra decimal quando o valor realmente
 * tem parte fracionária. Unidade "min" delega pra formatar_duracao_minutos()
 * (fica "2h 5min" em vez de "125,00 min").
 */
function formatar_numero_kpi(float $valor, string $unidade = ''): string
{
    if ($unidade === 'min') {
        return formatar_duracao_minutos($valor);
    }
    $temFracao = abs($valor - round($valor)) > 0.001;
    $numeroFormatado = number_format($valor, $temFracao ? 2 : 0, ',', '.');
    return $unidade !== '' ? $numeroFormatado . ' ' . $unidade : $numeroFormatado;
}

/**
 * Classifica um KPI contra a meta em vermelho/amarelo/verde. Sem saber se
 * pra esse KPI é melhor o valor ser MAIOR (ex.: chamados resolvidos) ou
 * MENOR (ex.: chamados urgentes, tempo médio) não tem como colorir nada --
 * 5 chamados urgentes pode ser ótimo ou péssimo dependendo da meta e da
 * direção. Sem meta cadastrada o card fica neutro (cinza), nunca "vermelho
 * por padrão" -- ausência de meta não é a mesma coisa que meta não batida.
 * Retorna [status, texto, classe-bootstrap-de-cor, ícone-bootstrap].
 */
function kpi_status(array $kpi): array
{
    if ($kpi['meta'] === null || $kpi['meta'] === '') {
        return ['neutro', 'Sem meta definida', 'secondary', 'bi-dash-circle'];
    }
    $valor = (float) $kpi['valor'];
    $meta = (float) $kpi['meta'];
    $menorMelhor = ($kpi['direcao_melhor'] ?? 'maior') === 'menor';

    if ($menorMelhor) {
        if ($valor <= $meta) {
            return ['verde', 'Dentro da meta', 'success', 'bi-check-circle-fill'];
        }
        if ($valor <= $meta * 1.2) {
            return ['amarelo', 'Perto da meta', 'warning', 'bi-exclamation-triangle-fill'];
        }
        return ['vermelho', 'Fora da meta', 'danger', 'bi-x-circle-fill'];
    }

    if ($valor >= $meta) {
        return ['verde', 'Dentro da meta', 'success', 'bi-check-circle-fill'];
    }
    if ($meta > 0 && $valor >= $meta * 0.8) {
        return ['amarelo', 'Perto da meta', 'warning', 'bi-exclamation-triangle-fill'];
    }
    return ['vermelho', 'Fora da meta', 'danger', 'bi-x-circle-fill'];
}

const KPI_CORES_HEX = [
    'success' => '#15803d',
    'warning' => '#b45309',
    'danger' => '#b91c1c',
    'secondary' => '#52525b',
];

/**
 * Nome do mês em português + ano, tipo "Agosto de 2026" -- usado pra
 * agrupar os cards de KPI por período (pedido do Kleber: "separar eles por
 * mes"). $periodo é a data (YYYY-MM-DD, sempre dia 01) que vem da coluna
 * kpis.periodo.
 */
function mes_ano_extenso(string $periodo): string
{
    static $meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    $timestamp = strtotime($periodo);

    return $meses[((int) date('n', $timestamp)) - 1] . ' de ' . date('Y', $timestamp);
}

/**
 * Agrupa uma lista de KPIs (já ordenada por periodo DESC) em blocos por
 * mês, cada um com o título por extenso e os KPIs daquele mês -- pra
 * renderizar "Agosto de 2026 / cards..." depois "Julho de 2026 / cards..."
 * em vez de uma lista só misturando todos os meses (pedido do Kleber).
 * Não precisa reordenar nada: só quebra a lista onde o período muda.
 */
function agrupar_kpis_por_periodo(array $kpis): array
{
    $grupos = [];
    foreach ($kpis as $kpi) {
        $chave = $kpi['periodo'];
        if (!isset($grupos[$chave])) {
            $grupos[$chave] = ['label' => mes_ano_extenso($chave), 'itens' => []];
        }
        $grupos[$chave]['itens'][] = $kpi;
    }

    return $grupos;
}

/**
 * Card colorido (vermelho/amarelo/verde/cinza) de um KPI de cliente --
 * substitui a antiga linha de tabela (pedido do Kleber: "as KPIS pra mim
 * precisam ser em CARDS ter cores... igual ao POWER BI"). Reaproveitado
 * tanto na aba "KPIs" (geral do cliente) quanto na sub-aba "Métricas" de
 * cada setor, pra não duplicar marcação e os dois nunca ficarem diferentes
 * um do outro. Item de grid direto (o pai precisa ter a classe
 * .kpi-grade) -- não depende de nenhuma classe tipo "col-md-*", de
 * propósito: a quantidade de colunas é controlada pelo seletor
 * "Personalizar" (var CSS --kpi-cols), não por breakpoint fixo.
 * $abaRetorno é "kpis" ou "setores" -- pra onde a página volta depois de
 * editar/excluir (mesma convenção já usada nos botões antigos).
 */
function kpi_card_html(array $kpi, string $abaRetorno): string
{
    [, $statusLabel, $corClasse, $icone] = kpi_status($kpi);
    $corHex = KPI_CORES_HEX[$corClasse] ?? KPI_CORES_HEX['secondary'];
    $unidade = $kpi['unidade'] ?? '';
    $valorFormatado = formatar_numero_kpi((float) $kpi['valor'], $unidade);
    $metaDefinida = ($kpi['meta'] !== null && $kpi['meta'] !== '');
    $metaFormatada = $metaDefinida ? formatar_numero_kpi((float) $kpi['meta'], $unidade) : null;
    $periodoFormatado = date('m/Y', strtotime($kpi['periodo']));

    // Barra de progresso: sempre expressa "quanto da meta já foi
    // alcançado" (0-100%+), mesmo quando o bom é o valor ser MENOR --
    // nesse caso passar de 100% é ruim, não bom, mas a barra preenche na
    // MESMA cor do status (vermelho), então continua lendo certo.
    $percentualBarra = 0;
    if ($metaDefinida) {
        $metaFloat = (float) $kpi['meta'];
        $percentualBarra = $metaFloat > 0 ? min(140, round(((float) $kpi['valor'] / $metaFloat) * 100)) : ((float) $kpi['valor'] > 0 ? 100 : 0);
    }

    $html = '<div class="kpi-card" style="--kpi-cor:' . e($corHex) . ';">';
    $html .= '<div class="kpi-card-topo">';
    $html .= '<span class="kpi-card-icone"><i class="bi ' . e($icone) . '"></i></span>';
    $html .= '<span class="kpi-card-periodo">' . e($periodoFormatado) . '</span>';
    $html .= '</div>';
    $html .= '<div class="kpi-card-nome text-truncate" title="' . e($kpi['nome_kpi']) . '">' . e($kpi['nome_kpi']) . '</div>';
    $html .= '<div class="kpi-card-valor">' . e($valorFormatado) . '</div>';
    if ($metaDefinida) {
        $html .= '<div class="kpi-card-barra"><div class="kpi-card-barra-preenchida" style="width:' . min(100, $percentualBarra) . '%"></div></div>';
        $html .= '<div class="kpi-card-status">' . e($statusLabel) . ' &middot; ' . $percentualBarra . '% da meta</div>';
        $html .= '<div class="kpi-card-meta">Meta: ' . e($metaFormatada) . '</div>';
    } else {
        $html .= '<div class="kpi-card-status kpi-card-status-neutro">' . e($statusLabel) . '</div>';
    }
    if (!empty($kpi['observacoes'])) {
        $html .= '<div class="kpi-card-obs" title="' . e($kpi['observacoes']) . '">' . e($kpi['observacoes']) . '</div>';
    }
    $html .= '<div class="kpi-card-rodape">';
    $html .= '<button type="button" class="btn btn-sm btn-outline-dark botao-editar-kpi"'
        . ' data-id="' . (int) $kpi['id'] . '" data-nome="' . e($kpi['nome_kpi']) . '" data-valor="' . e($kpi['valor']) . '"'
        . ' data-meta="' . e($kpi['meta'] ?? '') . '" data-unidade="' . e($unidade) . '"'
        . ' data-direcao="' . e($kpi['direcao_melhor'] ?? 'maior') . '"'
        . ' data-periodo="' . e(date('Y-m', strtotime($kpi['periodo']))) . '" data-setor-id="' . e((string) ($kpi['setor_id'] ?? '')) . '"'
        . ' data-observacoes="' . e($kpi['observacoes'] ?? '') . '" data-aba-retorno="' . e($abaRetorno) . '"'
        . ' title="Editar"><i class="bi bi-pencil"></i></button>';
    $html .= '<form method="post" class="d-inline" onsubmit="return confirm(\'Excluir esse KPI? Essa ação não pode ser desfeita.\');">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="acao" value="excluir_kpi">';
    $html .= '<input type="hidden" name="aba_retorno" value="' . e($abaRetorno) . '">';
    $html .= '<input type="hidden" name="kpi_id" value="' . (int) $kpi['id'] . '">';
    $html .= '<button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * Controle "Personalizar" (quantos cards por linha) + o wrapper de um
 * grupo de cards de KPI de um mês. Reaproveitado pela aba KPIs geral e por
 * cada sub-aba Métricas de setor -- o controle de quantidade É COMPARTILHADO
 * entre todos eles (guarda em localStorage, aplica via CSS var --kpi-cols
 * na raiz do documento, ver app.js) -- mudar num lugar muda em todos.
 */
function kpi_grades_html(array $kpis, string $abaRetorno, string $mensagemVazio): string
{
    if (!$kpis) {
        return '<p class="text-muted text-center py-4 mb-0">' . e($mensagemVazio) . '</p>';
    }

    $html = '';
    foreach (agrupar_kpis_por_periodo($kpis) as $grupo) {
        $html .= '<h6 class="kpi-mes-titulo">' . e($grupo['label']) . '</h6>';
        $html .= '<div class="kpi-grade">';
        foreach ($grupo['itens'] as $kpi) {
            $html .= kpi_card_html($kpi, $abaRetorno);
        }
        $html .= '</div>';
    }

    return $html;
}

/**
 * Reduz um texto em Markdown (gerado pela IA) a uma prévia em texto corrido
 * -- usado no card de Análise de Risco/Melhorias, que antes mostrava o
 * Markdown crú escapado (tipo "## Nível de risco" aparecendo literalmente
 * com os "##", colado no timestamp do card -- reclamação do Kleber: "coisa
 * horrível... tudo descompensado"). Não é um renderizador de Markdown de
 * verdade (a exportação em PDF já tem um em src/PdfExport.php) -- só tira
 * os símbolos de heading/negrito/itálico/lista pra virar um parágrafo
 * legível, que depois passa por e() normalmente antes de imprimir.
 */
function markdown_para_texto_simples(string $texto, int $maximoCaracteres = 220): string
{
    $limpo = preg_replace('/^#{1,6}\s*/m', '', $texto);
    $limpo = preg_replace('/\*\*(.+?)\*\*/s', '$1', $limpo);
    $limpo = preg_replace('/__(.+?)__/s', '$1', $limpo);
    $limpo = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/s', '$1', $limpo);
    $limpo = preg_replace('/^[-*]\s+/m', '• ', $limpo);
    $limpo = trim(preg_replace('/\s+/', ' ', $limpo));

    return mb_strlen($limpo) > $maximoCaracteres ? mb_substr($limpo, 0, $maximoCaracteres - 1) . '…' : $limpo;
}

/**
 * Detecta o nível de risco ("**Alto**", "**Médio**", "**Baixo**") que a IA
 * escreve logo no início da análise de tipo "risco" (ver prompt em
 * cliente.php) e devolve uma cor pra colorir o card -- mesma linguagem
 * visual dos cards de KPI (vermelho/amarelo/verde), em vez do card ficar
 * cinza sem informação nenhuma. Análises de "melhorias" não têm nível de
 * risco (não faz sentido pra um relatório pro cliente), ficam sempre no
 * acento neutro da marca.
 */
function analise_risco_cor(string $tipo, string $texto): array
{
    if ($tipo !== 'risco') {
        return ['primary', null];
    }
    if (preg_match('/\*\*\s*(alto|m[eé]dio|baixo)\s*\*\*/iu', $texto, $m)) {
        $mapa = [
            'alto' => ['danger', 'Risco alto'],
            'medio' => ['warning', 'Risco médio'],
            'médio' => ['warning', 'Risco médio'],
            'baixo' => ['success', 'Risco baixo'],
        ];

        return $mapa[mb_strtolower($m[1])] ?? ['secondary', null];
    }

    return ['secondary', null];
}

const ANALISE_CORES_HEX = [
    'danger' => '#b91c1c',
    'warning' => '#b45309',
    'success' => '#15803d',
    'secondary' => '#52525b',
    'primary' => '#4f46e5',
];

const ANALISE_ICONES_COR = [
    'danger' => 'bi-exclamation-triangle-fill',
    'warning' => 'bi-exclamation-circle-fill',
    'success' => 'bi-check-circle-fill',
    'secondary' => 'bi-dash-circle',
    'primary' => 'bi-stars',
];

/**
 * Card de uma Análise de Risco/Melhorias gerada pela IA -- mesma
 * linguagem visual dos cards de KPI (acento colorido lateral, ícone,
 * badge, hover-lift), corrigindo o card antigo que dependia de classes
 * "col-lg-4"/"flex-column"/"bg-danger" que nem existiam no CSS do projeto
 * (por isso saía torto/descompensado -- reclamação real do Kleber, não só
 * gosto: o card-body virava uma linha horizontal em vez de empilhar por
 * cima, já que .flex-column não tinha efeito nenhum). A prévia do texto
 * agora passa por markdown_para_texto_simples() -- antes mostrava o
 * Markdown crú escapado ("## Nível de risco" aparecendo literalmente).
 * $tituloSecao/$corBadgeSecaoLabel são só o rótulo da seção (Risco ou
 * Melhorias), não confundir com a cor de risco individual do card.
 */
function analise_card_html(array $analise, string $tituloSecao): string
{
    [$corClasse, $nivelLabel] = analise_risco_cor($analise['tipo'], $analise['analise']);
    $corHex = ANALISE_CORES_HEX[$corClasse] ?? ANALISE_CORES_HEX['primary'];
    $icone = ANALISE_ICONES_COR[$corClasse] ?? ANALISE_ICONES_COR['primary'];
    $escopo = $analise['setor_nome'] ?: 'Cliente inteiro';
    $dataLabel = formatar_data($analise['criado_em']) . (!empty($analise['atualizado_em']) ? ' · editado em ' . formatar_data($analise['atualizado_em']) : '');
    $preview = markdown_para_texto_simples($analise['analise'], 240);
    $labelCompleto = $tituloSecao . ($analise['setor_nome'] ? ' - ' . $analise['setor_nome'] : '');

    $html = '<div class="analise-card" style="--analise-cor:' . e($corHex) . ';" data-id="' . (int) $analise['id'] . '" data-tipo="' . e($analise['tipo']) . '" data-label="' . e($labelCompleto) . '">';
    $html .= '<div class="analise-card-topo">';
    $html .= '<span class="analise-card-icone"><i class="bi ' . e($icone) . '"></i></span>';
    $html .= '<span class="analise-card-escopo text-truncate" title="' . e($escopo) . '">' . e($escopo) . '</span>';
    $html .= '<span class="analise-card-data">' . e($dataLabel) . '</span>';
    $html .= '</div>';
    if ($nivelLabel !== null) {
        $html .= '<div class="analise-card-nivel">' . e($nivelLabel) . '</div>';
    }
    $html .= '<p class="analise-card-preview">' . e($preview) . '</p>';
    $html .= '<textarea class="d-none analise-texto-completo">' . e($analise['analise']) . '</textarea>';
    $html .= '<div class="analise-card-rodape">';
    $html .= '<button type="button" class="btn btn-sm btn-outline-dark botao-visualizar-analise" title="Visualizar"><i class="bi bi-eye"></i></button>';
    $html .= '<button type="button" class="btn btn-sm btn-outline-dark botao-editar-analise" title="Editar"><i class="bi bi-pencil"></i></button>';
    $html .= '<button type="button" class="btn btn-sm btn-outline-dark botao-exportar-pdf-analise" title="Exportar PDF"><i class="bi bi-file-earmark-pdf"></i></button>';
    $html .= '<form method="post" class="d-inline form-excluir-analise">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="acao" value="excluir_analise_risco">';
    $html .= '<input type="hidden" name="aba_retorno" value="analise-risco">';
    $html .= '<input type="hidden" name="id" value="' . (int) $analise['id'] . '">';
    $html .= '<button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
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
function processar_anexos_chamado(\PDO &$pdo, int $chamadoId, ?int $respostaId, string $enviadoPor, string $campoArquivo = 'anexos'): array
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

        // Fonte/objeto ADVPL do Protheus passa direto pela extensão original
        // (ver comentário de ANEXO_CHAMADO_EXTENSOES_PROTHEUS) -- só cai no
        // mime_content_type() pra qualquer outra coisa.
        $extensaoOriginal = strtolower(pathinfo($arquivos['name'][$i], PATHINFO_EXTENSION));
        if (in_array($extensaoOriginal, ANEXO_CHAMADO_EXTENSOES_PROTHEUS, true)) {
            $extensaoPermitida = $extensaoOriginal;
        } else {
            $tipoMime = mime_content_type($arquivos['tmp_name'][$i]);
            $extensaoPermitida = ANEXO_CHAMADO_TIPOS_PERMITIDOS[$tipoMime] ?? null;
        }
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
            try {
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
            } catch (\Throwable $e) {
                // Arquivo grande demais pro max_allowed_packet do MySQL da
                // hospedagem (fora do nosso controle) -- o anexo já está
                // salvo em disco (linha acima), então grava sem o backup em
                // vez de travar a resposta/chamado inteiro por causa disso.
                // Esse erro derruba a conexão ("MySQL server has gone away"),
                // por isso reconecta antes de tentar de novo.
                error_log('processar_anexos_chamado: falha ao gravar backup do anexo no banco (' . $e->getMessage() . ')');
                $pdo = \AdminPrime\Database::reconnect();
                $pdo->prepare('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $chamadoId,
                        $respostaId,
                        $enviadoPor,
                        $arquivos['name'][$i],
                        'chamados/' . $chamadoId . '/' . $nomeArmazenado,
                        $arquivos['size'][$i],
                    ]);
            }
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
function salvar_bytes_como_anexo_chamado(\PDO &$pdo, int $chamadoId, ?int $respostaId, string $enviadoPor, string $nomeOriginal, string $mime, string $bytes): ?string
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

    try {
        $pdo->prepare('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, dados) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$chamadoId, $respostaId, $enviadoPor, $nomeOriginal, 'chamados/' . $chamadoId . '/' . $nomeArmazenado, strlen($bytes), $bytes]);
    } catch (\Throwable $e) {
        error_log('salvar_bytes_como_anexo_chamado: falha ao gravar backup do anexo no banco (' . $e->getMessage() . ')');
        $pdo = \AdminPrime\Database::reconnect();
        $pdo->prepare('INSERT INTO chamado_anexos (chamado_id, resposta_id, enviado_por, arquivo_nome_original, arquivo_caminho, arquivo_tamanho) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$chamadoId, $respostaId, $enviadoPor, $nomeOriginal, 'chamados/' . $chamadoId . '/' . $nomeArmazenado, strlen($bytes)]);
    }

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
 * Ícone (classe do Bootstrap Icons) mais próximo do tipo real de arquivo,
 * pela extensão -- antes TODO anexo não-imagem usava o mesmo
 * "bi-file-earmark-pdf" fixo, então RAR/ZIP/Word/etc apareciam com o ícone
 * errado (ou, dependendo da fonte de ícone carregada, em branco -- print do
 * Kleber). Mesma tabela é espelhada em assets/js/app.js pro widget de
 * anexos pendentes (ainda não salvos no banco, então sem passar por aqui).
 */
function icone_bootstrap_por_extensao(string $extensao): string
{
    $porExtensao = [
        'pdf' => 'bi-filetype-pdf',
        'doc' => 'bi-filetype-doc', 'docx' => 'bi-filetype-docx',
        'xls' => 'bi-filetype-xls', 'xlsx' => 'bi-filetype-xlsx',
        'ppt' => 'bi-filetype-ppt', 'pptx' => 'bi-filetype-pptx',
        'txt' => 'bi-filetype-txt', 'csv' => 'bi-filetype-csv',
        'rtf' => 'bi-file-earmark-richtext',
        'zip' => 'bi-file-earmark-zip', 'rar' => 'bi-file-earmark-zip', '7z' => 'bi-file-earmark-zip',
        'odt' => 'bi-file-earmark-text', 'ods' => 'bi-file-earmark-spreadsheet', 'odp' => 'bi-file-earmark-slides',
        'jpg' => 'bi-filetype-jpg', 'jpeg' => 'bi-filetype-jpg', 'png' => 'bi-filetype-png', 'gif' => 'bi-filetype-gif',
        'webp' => 'bi-file-earmark-image',
    ];

    return $porExtensao[strtolower($extensao)] ?? 'bi-file-earmark';
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
        . '<i class="bi ' . icone_bootstrap_por_extensao($extensao) . '"></i> <span class="anexo-nome">' . e($a['arquivo_nome_original']) . '</span></a>';
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

/**
 * "Sistema operacional · Navegador" a partir do cabeçalho User-Agent (ver
 * usuario_login_log/relatório de atividade do colaborador). É só um
 * palpite por padrão de texto (o próprio navegador manda esse cabeçalho,
 * então dá pra mentir/trocar) -- serve pra identificar de relance de onde
 * alguém acessou, não é uma trava de segurança nem um dado 100% confiável.
 *
 * IMPORTANTE: nome da máquina, processador e memória RAM NÃO aparecem
 * aqui de propósito -- nenhum site tem acesso a isso, em NENHUM
 * navegador. É bloqueado por design (privacidade): o único jeito de um
 * site saber o hardware de quem acessa seria a pessoa instalar um
 * programa/extensão específica que reporte isso, não existe via web.
 */
function identificar_dispositivo(?string $userAgent): string
{
    if (!$userAgent) {
        return '-';
    }

    $so = 'Sistema desconhecido';
    if (stripos($userAgent, 'Windows') !== false) {
        $so = 'Windows';
    } elseif (stripos($userAgent, 'Mac OS X') !== false && stripos($userAgent, 'iPhone') === false && stripos($userAgent, 'iPad') === false) {
        $so = 'macOS';
    } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
        $so = 'iOS';
    } elseif (stripos($userAgent, 'Android') !== false) {
        $so = 'Android';
    } elseif (stripos($userAgent, 'Linux') !== false) {
        $so = 'Linux';
    }

    $navegador = 'navegador desconhecido';
    if (stripos($userAgent, 'Edg/') !== false) {
        $navegador = 'Edge';
    } elseif (stripos($userAgent, 'OPR/') !== false || stripos($userAgent, 'Opera') !== false) {
        $navegador = 'Opera';
    } elseif (stripos($userAgent, 'Chrome/') !== false) {
        $navegador = 'Chrome';
    } elseif (stripos($userAgent, 'Firefox/') !== false) {
        $navegador = 'Firefox';
    } elseif (stripos($userAgent, 'Safari/') !== false) {
        $navegador = 'Safari';
    }

    return $so . ' · ' . $navegador;
}

/**
 * Sistema novo (TypeScript) -> PHP: se a pessoa entrou pelo login novo
 * (cookie ph_token ou cabeçalho Authorization: Bearer, um JWT HS256
 * assinado com ADMINPRIME_JWT_SECRET), abre a sessão PHP correspondente
 * sozinha. Assim as ações que o sistema novo reaproveita daqui (responder
 * chamado com notificações, anexos, IA, exportar relatórios) funcionam sem
 * pedir login de novo. Sem o segredo configurado (ex.: Hostinger), não faz nada.
 */
function sessao_pelo_login_novo(\PDO $pdo): void
{
    $segredo = (string) getenv('ADMINPRIME_JWT_SECRET');
    if ($segredo === '' || !empty($_SESSION['usuario_id']) || !empty($_SESSION['portal_cliente_id'])) {
        return;
    }
    $token = $_COOKIE['ph_token'] ?? '';
    $cabecalhoAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($cabecalhoAuth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nomeCab => $valorCab) {
            if (strcasecmp($nomeCab, 'Authorization') === 0) {
                $cabecalhoAuth = $valorCab;
            }
        }
    }
    if ($token === '' && preg_match('/^Bearer\s+(\S+)$/', $cabecalhoAuth, $m)) {
        $token = $m[1];
    }
    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        return;
    }
    $b64 = static fn (string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    $esperada = rtrim(strtr(base64_encode(hash_hmac('sha256', $partes[0] . '.' . $partes[1], $segredo, true)), '+/', '-_'), '=');
    if (!hash_equals($esperada, $partes[2])) {
        return;
    }
    $dados = json_decode((string) $b64($partes[1]), true);
    $u = is_array($dados) ? ($dados['u'] ?? null) : null;
    if (!is_array($u) || ($dados['exp'] ?? 0) < time()) {
        return;
    }
    if (($u['tipo'] ?? '') === 'equipe') {
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? AND ativo = 1 LIMIT 1');
        $stmt->execute([(int) $u['id']]);
        if ($usuario = $stmt->fetch()) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_perfil'] = $usuario['perfil'];
            $_SESSION['usuario_foto'] = $usuario['foto_perfil'] ?? null;
        }
    } elseif (($u['tipo'] ?? '') === 'cliente') {
        \AdminPrime\ClienteAuth::iniciarSessaoPorId($pdo, (string) ($u['perfil'] ?? 'master'), (int) $u['id']);
    }
}
