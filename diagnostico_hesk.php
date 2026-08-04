<?php

/**
 * Página de diagnóstico (admin, ou link temporário gerado por um admin) para
 * inspecionar a estrutura do banco do HESK antes de construirmos a integração
 * de chamados. Remover depois que não for mais necessária.
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\HeskDatabase;
use AdminPrime\TempAccess;

Auth::requireAdminOrTempToken(Database::connection());

$linkGerado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'gerar_link' && Auth::check() && Auth::isAdmin()) {
    csrf_verify();
    $token = TempAccess::gerar(Database::connection(), Auth::userId(), 60);
    $linkGerado = url('diagnostico_hesk.php') . '?diag_token=' . $token;
}

$tituloPagina = 'Diagnóstico HESK';
require __DIR__ . '/templates/header.php';

$pdo = HeskDatabase::connection();
?>

<h4 class="mb-3">Diagnóstico do banco do HESK</h4>

<?php if (Auth::check() && Auth::isAdmin()): ?>
<div class="card card-stat mb-3">
    <div class="card-body">
        <form method="post" class="d-flex align-items-center gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="gerar_link">
            <button type="submit" class="btn btn-dark btn-sm">Gerar link temporário (60 min)</button>
            <span class="text-muted small">Gera um link de leitura válido por 1 hora, para eu poder olhar sem precisar de print.</span>
        </form>
        <?php if ($linkGerado): ?>
            <div class="alert alert-success mt-3 mb-0">
                Link gerado: <br>
                <code><?= e($linkGerado) ?></code>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$pdo): ?>
    <div class="alert alert-danger">Não foi possível conectar ao banco do HESK. Verifique as credenciais em config/config.php.</div>
<?php else: ?>
    <?php
    $tabelas = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    ?>
    <p class="text-muted">Encontradas <?= count($tabelas) ?> tabelas.</p>

    <?php foreach ($tabelas as $tabela): ?>
        <?php
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$tabela`")->fetchColumn();
        $colunas = $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll();
        ?>
        <div class="card card-stat mb-3">
            <div class="card-header bg-white fw-bold">
                <?= e($tabela) ?> <span class="badge bg-secondary"><?= $count ?> linhas</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Coluna</th><th>Tipo</th><th>Chave</th></tr></thead>
                    <tbody>
                    <?php foreach ($colunas as $col): ?>
                        <tr>
                            <td><?= e($col['Field']) ?></td>
                            <td><?= e($col['Type']) ?></td>
                            <td><?= e($col['Key']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/templates/footer.php'; ?>
