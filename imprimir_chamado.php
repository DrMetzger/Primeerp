<?php

/**
 * Relatório de um chamado (pergunta/resposta completo) pra imprimir ou
 * salvar como PDF pelo próprio diálogo de impressão do navegador -- pedido
 * do Kleber: "precisa ser um relatorio bonito de cada chamado com pergunta
 * resposta colorido". Acesso: equipe logada OU o próprio cliente dono do
 * chamado (mesmo esquema de autorização dupla do download_anexo_chamado.php).
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\ClienteAuth;
use AdminPrime\Database;

$staffLogado = Auth::check();
$clienteLogado = ClienteAuth::check();

if (!$staffLogado && !$clienteLogado) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

$pdo = Database::connection();
$chamadoId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT c.*, cl.nome AS cliente_nome, cl.logo AS cliente_logo, cl.contato_nome, u.nome AS responsavel_nome, u.foto_perfil AS responsavel_foto
    FROM chamados c
    LEFT JOIN clientes cl ON cl.id = c.cliente_id
    LEFT JOIN usuarios u ON u.id = c.responsavel_id
    WHERE c.id = ?');
$stmt->execute([$chamadoId]);
$chamado = $stmt->fetch();

if (!$chamado) {
    http_response_code(404);
    die('Chamado não encontrado.');
}

if ($clienteLogado && !$staffLogado && $chamado['cliente_id'] !== ClienteAuth::clienteId()) {
    http_response_code(403);
    die('Acesso não autorizado.');
}

$respostas = $pdo->prepare('SELECT * FROM chamado_respostas WHERE chamado_id = ? ORDER BY criado_em ASC');
$respostas->execute([$chamadoId]);
$respostas = $respostas->fetchAll();

$stmtAnexos = $pdo->prepare('SELECT * FROM chamado_anexos WHERE chamado_id = ? ORDER BY criado_em ASC');
$stmtAnexos->execute([$chamadoId]);
$anexosPorLinha = [];
foreach ($stmtAnexos->fetchAll() as $a) {
    $chave = $a['resposta_id'] ?? 'abertura';
    $anexosPorLinha[$chave][] = $a;
}

$linhas = [];
$linhas[] = [
    'autor_tipo' => $chamado['aberto_por'] === 'equipe' ? 'equipe' : 'cliente',
    'autor_nome' => $chamado['aberto_por'] === 'equipe'
        ? ($chamado['responsavel_nome'] ?: 'Equipe PrimeERP')
        : ($chamado['aberto_por'] === 'publico'
            ? ($chamado['contato_nome_publico'] ?: ($chamado['empresa_informada'] ?: 'Cliente'))
            : ($chamado['contato_nome'] ?: $chamado['cliente_nome'])),
    'mensagem' => $chamado['descricao'],
    'criado_em' => $chamado['criado_em'],
    'anexos' => $anexosPorLinha['abertura'] ?? [],
];
foreach ($respostas as $r) {
    $linhas[] = [
        'autor_tipo' => $r['autor_tipo'],
        'autor_nome' => $r['autor_nome'],
        'mensagem' => $r['mensagem'],
        'criado_em' => $r['criado_em'],
        'anexos' => $anexosPorLinha[$r['id']] ?? [],
    ];
}

$mapaOrigem = [
    'cliente' => 'Aberto pelo cliente',
    'equipe' => 'Aberto pela equipe',
    'publico' => 'Aberto pelo formulário público',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório · <?= e($chamado['titulo']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: 'Roboto', Arial, sans-serif; margin: 0; padding: 2rem; background: #f4f4f6; color: #27272a; }
        .folha { max-width: 780px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 2.5rem; box-shadow: 0 1px 8px rgba(0,0,0,.08); }
        .cabecalho { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #4f46e5; padding-bottom: 1rem; margin-bottom: 1.5rem; }
        .cabecalho .marca-caixa { background: #1e1b4b; padding: .6rem 1rem; border-radius: 8px; display: inline-block; line-height: 0; }
        .cabecalho img.marca { height: 28px; display: block; }
        .cabecalho .titulo-relatorio { text-align: right; }
        .cabecalho .titulo-relatorio h1 { font-size: 1rem; margin: 0; color: #4f46e5; }
        .cabecalho .titulo-relatorio p { margin: 0; font-size: .78rem; color: #71717a; }
        h2.assunto { font-size: 1.4rem; margin: 0 0 .75rem; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .5rem 1.5rem; background: #f8fafc; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.75rem; font-size: .88rem; }
        .info-grid .rotulo { color: #71717a; font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; font-weight: 700; display: block; }
        .conversa h3 { font-size: 1rem; color: #52525b; margin-bottom: 1rem; }
        .msg { border-radius: 10px; padding: .9rem 1.1rem; margin-bottom: 1rem; page-break-inside: avoid; }
        .msg-cliente { background: #dcfce7; border-left: 4px solid #15803d; }
        .msg-equipe { background: #ede9fe; border-left: 4px solid #4f46e5; margin-left: 8%; }
        .msg .autor { font-weight: 700; font-size: .85rem; margin-bottom: .3rem; display: flex; justify-content: space-between; }
        .msg .autor .papel { font-weight: 400; font-size: .72rem; color: #71717a; }
        .msg .texto { white-space: pre-wrap; font-size: .92rem; line-height: 1.5; }
        .msg .hora { font-size: .72rem; color: #71717a; margin-top: .5rem; text-align: right; }
        .msg .anexos { margin-top: .5rem; font-size: .78rem; color: #52525b; }
        .msg .anexos span { display: inline-block; background: rgba(255,255,255,.6); border-radius: 999px; padding: .15rem .55rem; margin-right: .3rem; }
        .rodape-relatorio { text-align: center; font-size: .72rem; color: #a1a1aa; margin-top: 2rem; }
        .barra-acoes { max-width: 780px; margin: 0 auto 1rem; text-align: right; }
        .barra-acoes button { background: #4f46e5; color: #fff; border: none; padding: .55rem 1.1rem; border-radius: 8px; font-size: .9rem; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .folha { box-shadow: none; padding: 0; }
            .barra-acoes, .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="barra-acoes no-print">
        <button onclick="window.print()">🖨️ Imprimir / Salvar como PDF</button>
    </div>
    <div class="folha">
        <div class="cabecalho">
            <span class="marca-caixa"><img class="marca" src="<?= e(asset_url('assets/img/logo.webp')) ?>" alt="Prime Hub"></span>
            <div class="titulo-relatorio">
                <h1>Relatório de chamado</h1>
                <p><?= e(APP_NAME) ?> · <?= date('d/m/Y H:i') ?></p>
            </div>
        </div>

        <h2 class="assunto"><?= e($chamado['titulo']) ?></h2>

        <div class="info-grid">
            <div><span class="rotulo">Cliente</span> <?= e($chamado['cliente_nome'] ?? $chamado['empresa_informada'] ?? '-') ?></div>
            <div><span class="rotulo">Categoria</span> <?= e($chamado['categoria'] ?: '-') ?></div>
            <div><span class="rotulo">Status</span> <?= badge_status_chamado($chamado['status']) ?></div>
            <div><span class="rotulo">Prioridade</span> <?= badge_prioridade($chamado['prioridade']) ?></div>
            <div><span class="rotulo">Origem</span> <?= e($mapaOrigem[$chamado['aberto_por']] ?? $chamado['aberto_por']) ?></div>
            <div><span class="rotulo">Aberto em</span> <?= formatar_data($chamado['criado_em']) ?></div>
        </div>

        <div class="conversa">
            <h3>Histórico completo (pergunta / resposta)</h3>
            <?php foreach ($linhas as $linha): ?>
                <div class="msg msg-<?= e($linha['autor_tipo']) ?>">
                    <div class="autor">
                        <span><?= e($linha['autor_nome']) ?></span>
                        <span class="papel"><?= $linha['autor_tipo'] === 'equipe' ? 'Equipe PrimeERP' : 'Cliente' ?></span>
                    </div>
                    <div class="texto"><?= e($linha['mensagem']) ?></div>
                    <?php if ($linha['anexos']): ?>
                        <div class="anexos">
                            <?php foreach ($linha['anexos'] as $a): ?>
                                <span>📎 <?= e($a['arquivo_nome_original']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="hora"><?= date('d/m/Y H:i', strtotime($linha['criado_em'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="rodape-relatorio">Gerado automaticamente pelo Prime Hub · PrimeERP Soluções em Tecnologia</p>
    </div>
</body>
</html>
