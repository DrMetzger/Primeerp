<?php
/**
 * Cron job -- dispara o lembrete "em 5 min" de treinamentos/reuniões
 * (Notificacoes::lembreteTreinamento(), só e-mail por enquanto).
 *
 * Pensado pra rodar a cada minuto (ou a cada poucos minutos) via Cron Job do
 * Hostinger. Como o intervalo mínimo do cron varia por plano, a janela de
 * busca abaixo tolera até 30 min de atraso do próprio cron -- se ele não
 * rodar por um tempo e voltar, ainda manda o lembrete de reuniões que já
 * passaram há pouco, mas não reenvia nada (lembrete_enviado_em marca o que
 * já foi mandado) nem manda lembrete de reunião muito antiga.
 *
 * Configuração no hPanel (Cron Jobs):
 *  - Se o painel permitir rodar um COMANDO (CLI), use algo como:
 *      php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/AdminPrime/cron_lembretes_treinamento.php
 *    Rodando via CLI não precisa de token (ver guarda abaixo).
 *  - Se o painel só permitir uma URL, use:
 *      https://www.primeerp.com.br/AdminPrime/cron_lembretes_treinamento.php?token=SEGREDO
 *    onde SEGREDO é o valor de CRON_SECRET (config/config.php).
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Database;
use AdminPrime\Notificacoes;

$viaCli = PHP_SAPI === 'cli';
if (!$viaCli && ($_GET['token'] ?? '') !== CRON_SECRET) {
    http_response_code(403);
    exit('Acesso negado.');
}

$pdo = Database::connection();

$stmt = $pdo->query("
    SELECT t.*, c.nome AS cliente_nome, s.nome AS setor_nome
    FROM treinamentos t
    JOIN clientes c ON c.id = t.cliente_id
    LEFT JOIN setores s ON s.id = t.setor_id
    WHERE t.lembrete_enviado_em IS NULL
      AND t.status != 'cancelado'
      AND t.data_inicio IS NOT NULL AND t.hora_inicio IS NOT NULL
      AND TIMESTAMP(t.data_inicio, t.hora_inicio)
          BETWEEN DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND DATE_ADD(NOW(), INTERVAL 5 MINUTE)
");
$treinamentos = $stmt->fetchAll();

$enviados = 0;
foreach ($treinamentos as $t) {
    try {
        Notificacoes::lembreteTreinamento($t, $t['cliente_nome'], $t['setor_nome']);
        $pdo->prepare('UPDATE treinamentos SET lembrete_enviado_em = NOW() WHERE id = ?')->execute([$t['id']]);
        $enviados++;
    } catch (\Throwable $e) {
        error_log('cron_lembretes_treinamento: falha no treinamento #' . $t['id'] . ': ' . $e->getMessage());
    }
}

echo "Lembretes enviados: {$enviados}\n";
