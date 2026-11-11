<?php
/**
 * Cron job -- processa as análises de IA dos chamados novos (ver
 * src/IaChamados.php): é ELE que responde os chamados, a cada 5 minutos.
 * Roda por linha de comando, fora do servidor web, de propósito: a análise
 * espera o Google por vários segundos e, dentro do site, isso prendia
 * processos e derrubava o site inteiro (erro 500). Processa poucos por vez,
 * pra não gastar de uma vez só.
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

// Migração pra VM: exporta o banco se o workflow pediu (ver src/ExportarBanco.php).
\AdminPrime\ExportarBanco::seSolicitado();

$processados = IaChamados::processarPendentes(Database::connection());

echo "Análises de IA processadas: {$processados}\n";
