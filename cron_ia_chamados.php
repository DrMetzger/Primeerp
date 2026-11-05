<?php
/**
 * Cron job -- termina análises de IA de chamados que ficaram pendentes (ver
 * src/IaChamados.php). Normalmente a análise roda logo depois do cliente
 * abrir o chamado; isso aqui só pega o que ficou pra trás (requisição
 * interrompida, falha passageira do Google etc.). Processa poucos por vez
 * de propósito, pra não gastar de uma vez só.
 *
 * Configuração no hPanel (Cron Jobs), a cada 5 ou 10 minutos:
 *  - Comando (CLI, sem token):
 *      php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/AdminPrime/cron_ia_chamados.php
 *  - Ou URL:
 *      https://www.primeerp.com.br/AdminPrime/cron_ia_chamados.php?token=SEGREDO
 *    (SEGREDO = CRON_SECRET de config/config.php)
 */

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Database;
use AdminPrime\IaChamados;

$viaCli = PHP_SAPI === 'cli';
if (!$viaCli && ($_GET['token'] ?? '') !== CRON_SECRET) {
    http_response_code(403);
    exit('Acesso negado.');
}

$processados = IaChamados::processarPendentes(Database::connection());

echo "Análises de IA processadas: {$processados}\n";
