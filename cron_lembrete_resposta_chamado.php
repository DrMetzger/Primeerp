<?php
/**
 * Cron job -- se a equipe respondeu um chamado no WhatsApp e perguntou o
 * que a pessoa quer fazer (encerrar/responder/nada -- ver
 * WhatsAppBot::prepararConversaPosResposta()) e ela demorou demais pra
 * decidir, manda uma mensagem de agradecimento e libera a conversa (sem
 * ficar travada esperando resposta pra sempre).
 *
 * Pensado pra rodar de poucos em poucos minutos via Cron Job do Hostinger --
 * mesmo esquema do cron_lembretes_treinamento.php.
 *
 * Configuração no hPanel (Cron Jobs):
 *  - Comando (CLI), sem token:
 *      php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/AdminPrime/cron_lembrete_resposta_chamado.php
 *  - Ou por URL:
 *      https://www.primeerp.com.br/AdminPrime/cron_lembrete_resposta_chamado.php?token=SEGREDO
 *    onde SEGREDO é o valor de CRON_SECRET (config/config.php).
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Database;
use AdminPrime\WhatsAppApi;

$viaCli = PHP_SAPI === 'cli';
if (!$viaCli && ($_GET['token'] ?? '') !== CRON_SECRET) {
    http_response_code(403);
    exit('Acesso negado.');
}

$pdo = Database::connection();

// 2h sem decidir o que fazer -- tempo suficiente pra não incomodar quem só
// está ocupado, mas sem deixar a conversa aberta pra sempre.
$stmt = $pdo->query("
    SELECT id, telefone FROM whatsapp_conversas
    WHERE etapa = 'aguardando_acao_resposta'
      AND atualizado_em <= DATE_SUB(NOW(), INTERVAL 2 HOUR)
");
$conversas = $stmt->fetchAll();

$enviados = 0;
foreach ($conversas as $c) {
    try {
        WhatsAppApi::enviarTexto($c['telefone'], '🙏 Obrigado por usar nosso atendimento! Se ainda precisar fazer algo com esse chamado (encerrar ou responder), é só me mandar mensagem por aqui de novo.');
        $pdo->prepare('DELETE FROM whatsapp_conversas WHERE id = ?')->execute([$c['id']]);
        $enviados++;
    } catch (\Throwable $e) {
        error_log('cron_lembrete_resposta_chamado: falha na conversa #' . $c['id'] . ': ' . $e->getMessage());
    }
}

echo "Lembretes enviados: {$enviados}\n";
