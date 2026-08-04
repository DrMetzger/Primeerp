<?php

/**
 * Importa os chamados históricos do HESK para dentro do Prime Hub, associando
 * cada chamado ao cliente cujo nome apareça no e-mail do solicitante
 * (ex: e-mail contendo "sgt" -> cliente SGT). Idempotente: chamados já
 * importados (hesk_ticket_id preenchido) não são importados de novo.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\HeskDatabase;

Auth::requireAdminOrTempToken(Database::connection());

$pdo = Database::connection();
$heskPdo = HeskDatabase::connection();

function normalizar_chave(string $texto): string
{
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = str_replace([' ', '-', '_'], '', $texto);
    return $texto;
}

$clientes = $pdo->query('SELECT id, nome FROM clientes')->fetchAll();
$chavesClientes = [];
foreach ($clientes as $c) {
    $chavesClientes[$c['id']] = normalizar_chave($c['nome']);
}

$jaImportados = $pdo->query('SELECT hesk_ticket_id FROM chamados WHERE hesk_ticket_id IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);
$jaImportados = array_flip($jaImportados);

$resultado = null;
$mensagemImportacao = null;

if ($heskPdo) {
    $tickets = $heskPdo->query('SELECT id, trackid, name, email, subject, message, dt, priority, status, closedat FROM hesk_tickets ORDER BY id')->fetchAll();

    $porCliente = [];
    $semMatch = [];
    $ambiguos = [];

    foreach ($tickets as $t) {
        $emailNormalizado = mb_strtolower($t['email'] ?? '', 'UTF-8');
        $matches = [];
        foreach ($chavesClientes as $clienteId => $chave) {
            if ($chave !== '' && str_contains($emailNormalizado, $chave)) {
                $matches[] = $clienteId;
            }
        }

        if (count($matches) === 1) {
            $porCliente[$matches[0]][] = $t;
        } elseif (count($matches) > 1) {
            $ambiguos[] = $t + ['matches' => $matches];
        } else {
            $semMatch[] = $t;
        }
    }

    // --- Ação: importar de fato ---
    // Duas formas de disparar, ambas já passaram pelo gate no topo do arquivo
    // (Auth::requireAdminOrTempToken): sessão de admin real via POST (botão na
    // tela, com CSRF normal) OU o próprio token temporário via GET com uma
    // confirmação explícita na URL (usado quando quem está operando é o
    // Claude, que só consegue fazer requisições GET) — como a importação é
    // idempotente (hesk_ticket_id evita duplicar), disparar mais de uma vez
    // não tem efeito colateral.
    $viaPost = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'importar';
    $viaGetComToken = $_SERVER['REQUEST_METHOD'] === 'GET'
        && ($_GET['acao'] ?? '') === 'importar'
        && ($_GET['confirmar'] ?? '') === 'SIM'
        && ($_GET['diag_token'] ?? '') !== '';

    if ($viaPost || $viaGetComToken) {
        if ($viaPost) {
            csrf_verify();
        }

        $mapaPrioridade = ['0' => 'urgente', '1' => 'alta', '2' => 'media', '3' => 'baixa'];
        $importados = 0;
        $respostasImportadas = 0;

        $pdo->beginTransaction();
        try {
            foreach ($porCliente as $clienteId => $ticketsDoCliente) {
                foreach ($ticketsDoCliente as $t) {
                    if (isset($jaImportados[$t['id']])) {
                        continue; // já importado antes
                    }

                    $fechado = $t['closedat'] && strtotime($t['closedat']) > 0;
                    $descricao = trim(strip_tags(html_entity_decode($t['message'] ?? '', ENT_QUOTES)));

                    $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, prioridade, status, aberto_por, hesk_ticket_id, criado_em) VALUES (?, ?, ?, ?, ?, "cliente", ?, ?)');
                    $stmt->execute([
                        $clienteId,
                        mb_substr($t['subject'] ?: '(sem título)', 0, 200),
                        $descricao !== '' ? $descricao : '(sem descrição)',
                        $mapaPrioridade[$t['priority']] ?? 'media',
                        $fechado ? 'fechado' : 'aberto',
                        $t['id'],
                        $t['dt'],
                    ]);
                    $novoId = (int) $pdo->lastInsertId();
                    $importados++;

                    $respostas = $heskPdo->prepare('SELECT name, message, dt, staffid FROM hesk_replies WHERE replyto = ? ORDER BY dt ASC');
                    $respostas->execute([$t['id']]);
                    foreach ($respostas->fetchAll() as $r) {
                        $mensagemResposta = trim(strip_tags(html_entity_decode($r['message'] ?? '', ENT_QUOTES)));
                        $stmt2 = $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, mensagem, criado_em) VALUES (?, ?, ?, ?, ?)');
                        $stmt2->execute([
                            $novoId,
                            ((int) $r['staffid']) > 0 ? 'equipe' : 'cliente',
                            $r['name'] ?: 'PrimeERP',
                            $mensagemResposta !== '' ? $mensagemResposta : '(sem conteúdo)',
                            $r['dt'],
                        ]);
                        $respostasImportadas++;
                    }
                }
            }
            $pdo->commit();
            $mensagemImportacao = "Importação concluída: $importados chamados e $respostasImportadas respostas.";
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $mensagemImportacao = 'Erro na importação: ' . $e->getMessage();
        }

        // Recalcular "já importados" pra tela não mostrar de novo os que acabaram de entrar
        $jaImportados = $pdo->query('SELECT hesk_ticket_id FROM chamados WHERE hesk_ticket_id IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);
        $jaImportados = array_flip($jaImportados);
    }

    $resultado = compact('porCliente', 'semMatch', 'ambiguos');
}

$tituloPagina = 'Importar chamados do HESK';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-3">Importar chamados do HESK</h4>

<?php if ($mensagemImportacao): ?>
    <div class="alert alert-success"><?= e($mensagemImportacao) ?></div>
<?php endif; ?>

<?php if (!$heskPdo): ?>
    <div class="alert alert-danger">Não foi possível conectar ao banco do HESK.</div>
<?php else: ?>
    <div class="card card-stat mb-3">
        <div class="card-body">
            <p class="mb-2">Total de chamados no HESK: <strong><?= count($tickets) ?></strong></p>
            <table class="table table-sm mb-0">
                <thead><tr><th>Cliente</th><th class="text-center">A importar</th><th class="text-center">Já importados</th></tr></thead>
                <tbody>
                <?php foreach ($resultado['porCliente'] as $clienteId => $ticketsDoCliente): ?>
                    <?php
                    $nomeCliente = array_values(array_filter($clientes, fn($c) => $c['id'] == $clienteId))[0]['nome'] ?? '?';
                    $pendentes = count(array_filter($ticketsDoCliente, fn($t) => !isset($jaImportados[$t['id']])));
                    $importadosCount = count($ticketsDoCliente) - $pendentes;
                    ?>
                    <tr>
                        <td><?= e($nomeCliente) ?></td>
                        <td class="text-center"><?= $pendentes ?></td>
                        <td class="text-center text-muted"><?= $importadosCount ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $totalPendente = 0;
    foreach ($resultado['porCliente'] as $ticketsDoCliente) {
        $totalPendente += count(array_filter($ticketsDoCliente, fn($t) => !isset($jaImportados[$t['id']])));
    }
    ?>
    <?php if ($totalPendente > 0 && Auth::check() && Auth::isAdmin()): ?>
    <form method="post" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="importar">
        <button type="submit" class="btn btn-dark" onclick="return confirm('Importar <?= $totalPendente ?> chamados agora?')">
            Importar <?= $totalPendente ?> chamados pendentes
        </button>
    </form>
    <?php endif; ?>

    <div class="card card-stat mb-3">
        <div class="card-header bg-white fw-bold">
            Sem cliente identificado (<?= count($resultado['semMatch']) ?>)
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>#</th><th>E-mail</th><th>Assunto</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($resultado['semMatch'], 0, 50) as $t): ?>
                    <tr><td><?= $t['id'] ?></td><td><?= e($t['email']) ?></td><td><?= e($t['subject']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($resultado['semMatch']) > 50): ?>
                <p class="text-muted small p-2 mb-0">Mostrando 50 de <?= count($resultado['semMatch']) ?>.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($resultado['ambiguos'])): ?>
    <div class="card card-stat mb-3">
        <div class="card-header bg-white fw-bold text-warning">
            Ambíguos — bateu com mais de um cliente (<?= count($resultado['ambiguos']) ?>)
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>#</th><th>E-mail</th><th>Assunto</th></tr></thead>
                <tbody>
                <?php foreach ($resultado['ambiguos'] as $t): ?>
                    <tr><td><?= $t['id'] ?></td><td><?= e($t['email']) ?></td><td><?= e($t['subject']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/templates/footer.php'; ?>
