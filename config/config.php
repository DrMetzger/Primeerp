<?php
/**
 * Configurações da aplicação Prime Hub.
 *
 * IMPORTANTE: este arquivo fica dentro de um repositório PRIVADO e é protegido
 * por .htaccess (acesso direto via navegador é bloqueado). Ainda assim, evite
 * versionar credenciais de produção em outros lugares além deste arquivo.
 */

// Ambiente: 'local' (desenvolvimento/testes) ou 'production' (Hostinger)
// Detecta automaticamente pelo host, mas pode forçar via variável de ambiente.
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? 'cli', ['localhost', '127.0.0.1', 'cli'], true);

if ($isLocal && getenv('ADMINPRIME_ENV') !== 'production') {
    // --- Configuração LOCAL (ambiente de testes) ---
    define('DB_HOST', getenv('ADMINPRIME_DB_HOST') ?: 'localhost');
    define('DB_NAME', getenv('ADMINPRIME_DB_NAME') ?: 'adminprime_test');
    define('DB_USER', getenv('ADMINPRIME_DB_USER') ?: 'adminprime');
    define('DB_PASS', getenv('ADMINPRIME_DB_PASS') ?: 'testpass123');

    // Banco do HESK (helpdesk) — em branco por padrão localmente, mas pode
    // ser apontado para um banco de teste via variáveis de ambiente.
    define('HESK_DB_HOST', getenv('ADMINPRIME_HESK_DB_HOST') ?: '');
    define('HESK_DB_NAME', getenv('ADMINPRIME_HESK_DB_NAME') ?: '');
    define('HESK_DB_USER', getenv('ADMINPRIME_HESK_DB_USER') ?: '');
    define('HESK_DB_PASS', getenv('ADMINPRIME_HESK_DB_PASS') ?: '');

    // SMTP local: em branco por padrão (Mailer cai para mail() do PHP, que
    // localmente nem chega a enviar de verdade -- é só pra não quebrar).
    define('SMTP_HOST', getenv('ADMINPRIME_SMTP_HOST') ?: '');
    define('SMTP_PORT', (int) (getenv('ADMINPRIME_SMTP_PORT') ?: 587));
    define('SMTP_SECURE', getenv('ADMINPRIME_SMTP_SECURE') ?: 'tls'); // 'ssl' ou 'tls'
    define('SMTP_USER', getenv('ADMINPRIME_SMTP_USER') ?: '');
    define('SMTP_PASS', getenv('ADMINPRIME_SMTP_PASS') ?: '');
} else {
    // --- Configuração de PRODUÇÃO (Hostinger) ---
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u195563148_claude');
    define('DB_USER', 'u195563148_claude');
    define('DB_PASS', '*@2^@cIdb4J?');

    // Banco do HESK (sistema de chamados atual, primeerp.com.br/helpdesk/)
    define('HESK_DB_HOST', 'localhost');
    define('HESK_DB_NAME', 'u195563148_help');
    define('HESK_DB_USER', 'u195563148_help');
    define('HESK_DB_PASS', 'I31&vq~=k5');

    // SMTP autenticado (Hostinger) -- e-mails de notificação (novo chamado,
    // resposta da equipe, convite de acesso ao portal) passam a ser enviados
    // de verdade via a caixa do Kleber, em vez do mail() nativo do PHP (mais
    // confiável contra spam/rejeição). Confirmado via DNS (MX = mx1/mx2.
    // hostinger.com, SPF inclui _spf.mail.hostinger.com) que o domínio usa o
    // e-mail hospedado da própria Hostinger -- por isso host/porta abaixo
    // seguem o padrão documentado da Hostinger para esse serviço.
    define('SMTP_HOST', 'smtp.hostinger.com');
    define('SMTP_PORT', 465);
    define('SMTP_SECURE', 'ssl');
    define('SMTP_USER', 'kleber.farias@primeerp.com.br');
    define('SMTP_PASS', 'Km@2024@#$');
}

define('DB_CHARSET', 'utf8mb4');

// Nome da aplicação (exibido nas telas) / URL base — a pasta no domínio
// continua se chamando /AdminPrime (é só o diretório no servidor, não muda
// os links do portal já enviados aos clientes), mas o nome mostrado é Prime Hub.
define('APP_NAME', 'Prime Hub');
define('APP_BASE_PATH', $isLocal ? '' : '/AdminPrime');

// Notificações por e-mail (novos chamados avisam a equipe interna).
// Envia como o próprio e-mail do Kleber (autenticado via SMTP, ver acima)
// para cair na caixa de entrada normal do cliente, com o nome de exibição
// "Prime Hub" mantendo a marca.
define('MAIL_FROM', 'Prime Hub <' . (SMTP_USER !== '' ? SMTP_USER : 'nao-responda@primeerp.com.br') . '>');
define('NOTIFICACAO_EMAILS_EQUIPE', ['kleber.farias@primeerp.com.br', 'pcorreajr@primeerp.com.br']);

// WhatsApp Business Platform (API oficial da Meta, direto -- sem BSP) -- bot
// de menu guiado que abre chamado pelo WhatsApp (ver webhook_whatsapp.php,
// src/WhatsAppBot.php, src/WhatsAppApi.php). Fica tudo em branco até o
// Kleber terminar de configurar a conta/número na Meta; enquanto isso,
// WhatsAppApi só registra em log em vez de chamar a API de verdade (mesmo
// esquema do e-mail/SMTP em ambiente local).
define('WHATSAPP_ACCESS_TOKEN', getenv('ADMINPRIME_WHATSAPP_ACCESS_TOKEN') ?: '');
define('WHATSAPP_PHONE_NUMBER_ID', getenv('ADMINPRIME_WHATSAPP_PHONE_NUMBER_ID') ?: '');
define('WHATSAPP_APP_SECRET', getenv('ADMINPRIME_WHATSAPP_APP_SECRET') ?: '');
// Token que a própria Meta usa pra confirmar o webhook na primeira vez
// (Configuração > Webhook, no painel do app) -- é a gente que inventa essa
// senha, não vem da Meta.
define('WHATSAPP_VERIFY_TOKEN', getenv('ADMINPRIME_WHATSAPP_VERIFY_TOKEN') ?: 'primehub-verify-token');
// Número (só dígitos, com DDI 55) que recebe o aviso de "novo chamado
// aberto pelo WhatsApp" -- o celular pessoal do Kleber.
define('WHATSAPP_NOTIFICAR_TELEFONE', getenv('ADMINPRIME_WHATSAPP_NOTIFICAR_TELEFONE') ?: '');

// Diretório de uploads (relativo à raiz do projeto)
define('UPLOADS_DIR', __DIR__ . '/../uploads');
define('UPLOADS_MAX_SIZE', 15 * 1024 * 1024); // 15MB por arquivo

// Anexos do formulário público de abertura de chamado.
define('ANEXO_CHAMADO_MAX_SIZE', 10 * 1024 * 1024); // 10MB por arquivo
define('ANEXO_CHAMADO_MAX_QUANTIDADE', 5);
define('ANEXO_CHAMADO_TIPOS_PERMITIDOS', [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
]);

// Perfis de usuário da equipe. Diretor e CEO têm hoje o mesmo nível de
// acesso que Administrador (ver Auth::isAdmin()) -- o CEO é pensado pro
// futuro módulo financeiro (contratos/faturas/CNAB), que ainda está em
// stand-by.
define('PERFIS_USUARIO', [
    'colaborador' => 'Colaborador',
    'admin' => 'Administrador',
    'diretor' => 'Diretor',
    'ceo' => 'CEO',
]);

// Quantidade de avatares prontos em assets/img/avatares/ (avatar-1.svg até
// avatar-N.svg) -- opção pra quem não quiser subir a própria foto.
define('QUANTIDADE_AVATARES_PREDEFINIDOS', 16);

// Categorias de chamado -- mesma lista de área que já existia no HESK (o
// sistema de chamados antigo), pra manter a mesma linguagem que os clientes
// já conhecem em vez de inventar uma nova.
define('CATEGORIAS_CHAMADO', [
    'Geral',
    'Compras',
    'Estoque/Custos',
    'Faturamento',
    'Financeiro',
    'PCP',
    'Gestão de Pessoal',
    'Ponto Eletrônico',
    'Fiscal',
    'Contabilidade',
    'Integrações',
    'Desenvolvimento',
]);

// Sugestões de nome pra cadastro rápido de setor (cliente.php, modal
// Gerenciar setores) -- modelo inicial passado pelo Kleber. Setor é
// totalmente flexível/cadastrável por cliente, isso aqui é só atalho.
define('SETORES_SUGERIDOS', [
    'Ativo Fixo',
    'Compras',
    'Estoque/Custos',
    'Faturamento',
    'Financeiro',
    'PCP',
    'RH',
    'Ponto Eletrônico',
    'Contabilidade',
]);

// Horário comercial em que o PORTAL DO CLIENTE fica acessível (login E
// qualquer página, mesmo já logado) -- pedido do Kleber depois de um
// cliente ter aberto 37 chamados de madrugada (1h às 7h) de forma abusiva.
// Só afeta o portal do cliente; equipe (Auth) não tem essa restrição.
define('PORTAL_CLIENTE_HORARIO_INICIO', 9);  // 09:00
define('PORTAL_CLIENTE_HORARIO_FIM', 18);    // até 17:59

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Sessão
if (session_status() === PHP_SESSION_NONE) {
    session_name('adminprime_session');
    session_start();
}

// Exibição de erros (desligar em produção real de clientes externos, mas
// como é ferramenta interna, deixamos log ativo e tela só em local)
error_reporting(E_ALL);
ini_set('display_errors', $isLocal ? '1' : '0');
ini_set('log_errors', '1');
