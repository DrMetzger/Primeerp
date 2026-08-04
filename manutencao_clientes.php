<?php

/**
 * Ferramenta pontual de manutenção de clientes: mostra os clientes cadastrados
 * com contagem de registros relacionados (demandas, kpis, documentos,
 * treinamentos, chamados) e permite mesclar dois clientes em um só (usado
 * para corrigir o caso WERK / SCHOTT, que na verdade é um único cliente
 * "Werk-Schott" — confirmado com o Kleber em 04/08/2026).
 *
 * Mesclar: todos os registros relacionados do cliente de ORIGEM são
 * reatribuídos para o cliente de DESTINO, o nome do destino é atualizado (se
 * informado) e o registro de origem é então removido (nesse ponto já sem
 * nenhum filho apontando pra ele, então a remoção não perde nada).
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;

Auth::requireAdminOrTempToken(Database::connection());

$pdo = Database::connection();

function contarRelacionados(PDO $pdo, int $clienteId): array
{
    $tabelas = ['demandas', 'kpis', 'documentos', 'treinamentos', 'chamados', 'cliente_senha_tokens'];
    $contagens = [];
    foreach ($tabelas as $tabela) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabela} WHERE cliente_id = ?");
        $stmt->execute([$clienteId]);
        $contagens[$tabela] = (int) $stmt->fetchColumn();
    }
    return $contagens;
}

$mensagem = null;
$erro = null;

$viaPost = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'mesclar';
$viaGetComToken = $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['acao'] ?? '') === 'mesclar'
    && ($_GET['confirmar'] ?? '') === 'SIM'
    && ($_GET['diag_token'] ?? '') !== '';

if ($viaPost || $viaGetComToken) {
    if ($viaPost) {
        csrf_verify();
    }

    $dados = $viaPost ? $_POST : $_GET;
    $origemId = (int) ($dados['origem_id'] ?? 0);
    $destinoId = (int) ($dados['destino_id'] ?? 0);
    $novoNome = trim($dados['novo_nome'] ?? '');

    if ($origemId <= 0 || $destinoId <= 0 || $origemId === $destinoId) {
        $erro = 'IDs de origem/destino inválidos.';
    } else {
        $pdo->beginTransaction();
        try {
            $tabelas = ['demandas', 'kpis', 'documentos', 'treinamentos', 'chamados', 'cliente_senha_tokens'];
            $totalMovido = 0;
            foreach ($tabelas as $tabela) {
                $stmt = $pdo->prepare("UPDATE {$tabela} SET cliente_id = ? WHERE cliente_id = ?");
                $stmt->execute([$destinoId, $origemId]);
                $totalMovido += $stmt->rowCount();
            }

            if ($novoNome !== '') {
                $stmt = $pdo->prepare('UPDATE clientes SET nome = ? WHERE id = ?');
                $stmt->execute([$novoNome, $destinoId]);
            }

            // Confirma que não sobrou nada apontando pra origem antes de remover.
            $restante = 0;
            foreach ($tabelas as $tabela) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabela} WHERE cliente_id = ?");
                $stmt->execute([$origemId]);
                $restante += (int) $stmt->fetchColumn();
            }

            if ($restante > 0) {
                throw new \RuntimeException("Ainda há {$restante} registro(s) ligados ao cliente de origem, não removendo por segurança.");
            }

            $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$origemId]);

            $pdo->commit();
            $mensagem = "Mesclagem concluída: {$totalMovido} registro(s) movido(s) do cliente #{$origemId} para o #{$destinoId}"
                . ($novoNome !== '' ? " (renomeado para \"{$novoNome}\")" : '') . '. Cliente de origem removido.';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $erro = 'Erro na mesclagem: ' . $e->getMessage();
        }
    }
}

$clientes = $pdo->query('SELECT id, nome, status, contato_email, portal_ativo FROM clientes ORDER BY id')->fetchAll();

$tituloPagina = 'Manutenção de clientes';
require __DIR__ . '/templates/header.php';
?>

<h4 class="mb-3">Manutenção de clientes</h4>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= e($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger"><?= e($erro) ?></div>
<?php endif; ?>

<div class="card card-stat mb-3">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>ID</th><th>Nome</th><th>Status</th><th>E-mail contato</th><th>Portal ativo</th>
                    <th class="text-center">Demandas</th><th class="text-center">KPIs</th>
                    <th class="text-center">Documentos</th><th class="text-center">Treinamentos</th>
                    <th class="text-center">Chamados</th><th class="text-center">Tokens senha</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clientes as $c): ?>
                <?php $contagens = contarRelacionados($pdo, (int) $c['id']); ?>
                <tr>
                    <td><?= (int) $c['id'] ?></td>
                    <td><?= e($c['nome']) ?></td>
                    <td><?= e($c['status']) ?></td>
                    <td><?= e($c['contato_email'] ?? '') ?></td>
                    <td><?= $c['portal_ativo'] ? 'Sim' : 'Não' ?></td>
                    <td class="text-center"><?= $contagens['demandas'] ?></td>
                    <td class="text-center"><?= $contagens['kpis'] ?></td>
                    <td class="text-center"><?= $contagens['documentos'] ?></td>
                    <td class="text-center"><?= $contagens['treinamentos'] ?></td>
                    <td class="text-center"><?= $contagens['chamados'] ?></td>
                    <td class="text-center"><?= $contagens['cliente_senha_tokens'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
