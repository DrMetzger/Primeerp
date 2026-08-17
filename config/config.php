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
// src/WhatsAppBot.php, src/WhatsAppApi.php). Número real "Prime HUB" (+55 17
// 99205-5505) configurado em produção em 08/2026; em local fica em branco de
// propósito (mesmo esquema do e-mail/SMTP), pra não mandar mensagem de
// verdade só testando a tela -- WhatsAppApi só registra em log nesse caso.
define('WHATSAPP_ACCESS_TOKEN', getenv('ADMINPRIME_WHATSAPP_ACCESS_TOKEN') ?: ($isLocal ? '' : 'EAAOsbTpGbqABSEORJEYEaApYJCd3xwwU2HbF1GahdcjCj3ud6XRM2IcnsbYPlM0UULInZB0OPDm2MyMx1oO51jZCiWFQExiiJ2MnxnAWnhWDZA5rnIOb3V5MV0g6szAs7P5auxaQ5epbTuwqSv27WYey8VGXVQJa5usgzqiZAc1ArO6sZAUQuyJwbq9jjKgZDZD'));
define('WHATSAPP_PHONE_NUMBER_ID', getenv('ADMINPRIME_WHATSAPP_PHONE_NUMBER_ID') ?: ($isLocal ? '' : '1251785564679814'));
define('WHATSAPP_APP_SECRET', getenv('ADMINPRIME_WHATSAPP_APP_SECRET') ?: ($isLocal ? '' : '1efbe8ba157800e0444bdeeae139d71a'));
// Token que a própria Meta usa pra confirmar o webhook na primeira vez
// (Configuração > Webhook, no painel do app) -- é a gente que inventa essa
// senha, não vem da Meta.
define('WHATSAPP_VERIFY_TOKEN', getenv('ADMINPRIME_WHATSAPP_VERIFY_TOKEN') ?: 'primehub-verify-token');
// Número (só dígitos, com DDI 55) que recebe o aviso de "novo chamado
// aberto pelo WhatsApp" -- o celular pessoal do Kleber.
define('WHATSAPP_NOTIFICAR_TELEFONE', getenv('ADMINPRIME_WHATSAPP_NOTIFICAR_TELEFONE') ?: ($isLocal ? '' : '5517981368201'));
// Horário de funcionamento do bot (seg-sex, hora local America/Sao_Paulo já
// setada abaixo) -- fora disso o bot só avisa o horário e não abre chamado,
// a menos que o cliente tenha "liberação especial" (clientes.whatsapp_liberacao_horario).
define('WHATSAPP_HORARIO_COMERCIAL_INICIO', 8);
define('WHATSAPP_HORARIO_COMERCIAL_FIM', 18);

// IA de sugestões de melhoria por setor (aba Setores, ver src/AiSugestoes.php)
// -- usa a API do Google Gemini (plano gratuito, pedido do Kleber pra não
// gerar custo). Chave real só em produção (mesmo padrão do DB_PASS/SMTP_PASS
// acima) -- em local fica em branco de propósito, pra não gastar cota
// gerando sugestão de verdade só testando a tela. Essa chave SÓ é usada pra
// analisar dados JÁ CADASTRADOS (chamados/KPIs) -- nunca chama a ferramenta
// de busca do Google, então continua 100% dentro do plano gratuito.
define('GEMINI_API_KEY', getenv('ADMINPRIME_GEMINI_API_KEY') ?: ($isLocal ? '' : 'AQ.Ab8RN6LMT65gzPC8BDs0oFobulw8Gh0iKis5sVzHSwrozb8EeA'));

// Chave SEPARADA (projeto Google Cloud diferente da de cima, de propósito)
// só pra pesquisa de perfil público da empresa via busca do Google --
// ativar faturamento nessa API KEY/projeto pra liberar a cota da ferramenta
// google_search REMOVE o plano gratuito do projeto inteiro (toda chamada
// passa a ser cobrada, mesmo dentro da cota que seria gratuita). Por isso
// tem que ser uma chave/projeto à parte: o faturamento fica isolado só
// aqui, sem arriscar cobrar a análise interna de chamados/KPIs (que
// continua de graça na chave de cima). Em branco até o Kleber criar essa
// segunda chave depois do Petronilho liberar o faturamento.
define('GEMINI_API_KEY_BUSCA', getenv('ADMINPRIME_GEMINI_API_KEY_BUSCA') ?: '');

// Token usado por cron_lembretes_treinamento.php quando o cron job é
// disparado por URL (em vez de comando CLI) -- sem ele, qualquer um que
// descobrisse a URL poderia forçar o disparo dos lembretes. Não precisa
// disso se o Cron Job do Hostinger rodar via CLI (php cron_lembretes_...php),
// só quando for via URL/wget.
define('CRON_SECRET', getenv('ADMINPRIME_CRON_SECRET') ?: ($isLocal ? 'teste-local' : '9333d390beced8c03a2a8668c38ee1f9d7333def0501d182'));

// Chave de cifra do cofre de acessos (aba "Acessos" em cliente.php --
// senha de servidor/RDP/AnyDesk/VPN) -- ver src/Crypto.php. AES-256-GCM,
// chave de 32 bytes em base64. NUNCA reusar/trocar essa chave em produção
// depois que já existir credencial cifrada com ela (perde a senha de
// verdade pra sempre, decifrar exige a MESMA chave que cifrou). Local usa
// uma chave fixa só pra desenvolvimento/teste, sem risco nenhum (não é
// dado real).
define('CREDENCIAIS_CIFRA_CHAVE', getenv('ADMINPRIME_CREDENCIAIS_CIFRA_CHAVE') ?: ($isLocal
    ? 'dGVzdGUtbG9jYWwtc2VtLXJpc2NvLTMyLWJ5dGVzISE='
    : 'VAAkLbbFOBQTIZIfFMVms14PIu1S2lhksFtXcqSRdDE='));

// Diretório de uploads (relativo à raiz do projeto)
define('UPLOADS_DIR', __DIR__ . '/../uploads');
define('UPLOADS_MAX_SIZE', 500 * 1024 * 1024); // 500MB por arquivo (pedido do Kleber -- 15MB não dava pra nada)

// Anexos de chamado (portal, admin e WhatsApp). Cliente manda de tudo --
// Excel, Word, PowerPoint, fonte .prw do Protheus, .zip -- então a lista
// cobre os formatos de documento/planilha/apresentação/texto/arquivo
// compactado mais comuns, sem liberar QUALQUER coisa: fica de fora tudo que
// é executável ou script (.exe, .bat, .js, .jar, .msi, .apk, .html etc), que
// é por onde vírus/malware normalmente se disfarça. O tipo aqui é só o que
// o PHP detecta LENDO o conteúdo do arquivo (mime_content_type -- não dá pra
// enganar só renomeando a extensão), e o download sempre força
// "application/octet-stream" + anexo pra baixar (ver download_anexo_chamado.php),
// então mesmo um arquivo aceito aqui nunca é executado/aberto direto pelo navegador.
define('ANEXO_CHAMADO_MAX_SIZE', 500 * 1024 * 1024); // 500MB por arquivo (pedido do Kleber -- 10MB não dava pra nada)
define('ANEXO_CHAMADO_MAX_QUANTIDADE', 5);
define('ANEXO_CHAMADO_TIPOS_PERMITIDOS', [
    // Imagens
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    // PDF
    'application/pdf' => 'pdf',
    // Word / Excel / PowerPoint (formato antigo .doc/.xls/.ppt e novo .docx/.xlsx/.pptx)
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    // LibreOffice/OpenDocument
    'application/vnd.oasis.opendocument.text' => 'odt',
    'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    'application/vnd.oasis.opendocument.presentation' => 'odp',
    // Texto puro -- cobre .txt, .csv e fonte .prw do Protheus (todos texto puro,
    // o PHP não distingue por conteúdo, só a extensão original é preservada
    // no nome exibido pro download).
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/rtf' => 'rtf',
    'text/rtf' => 'rtf',
    // Compactado -- também é o formato real de .docx/.xlsx/.pptx quando o PHP
    // não consegue distinguir o tipo específico (todos são .zip por dentro).
    'application/zip' => 'zip',
    'application/x-rar-compressed' => 'rar',
    'application/vnd.rar' => 'rar',
    'application/x-7z-compressed' => '7z',
]);

// Atributo "accept" do <input type="file"> dos 3 formulários de anexo de
// chamado (portal, admin e resposta) -- filtro só de UX (o cliente escolhe
// mais rápido no seletor do sistema), a validação de verdade é sempre a
// de ANEXO_CHAMADO_TIPOS_PERMITIDOS acima, no servidor.
define('ANEXO_CHAMADO_ACCEPT_HTML', '.jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv,.prw,.rtf,.zip,.rar,.7z');

// Vídeo aulas por setor (cliente.php e portal do cliente) -- Kleber sobe uma
// gravação em MP4 pra cada rotina/processo do setor; reaproveita a mesma
// tabela "documentos" (categoria 'Vídeo aula', mesmo padrão já usado por
// "Proposta de desenvolvimento" em salvar_documento_upload()), restrito a
// MP4 (pedido explícito do Kleber) -- é o formato mais compatível pra
// reproduzir direto no navegador (tag <video>) sem precisar converter.
define('VIDEO_AULA_TIPOS_PERMITIDOS', [
    'video/mp4' => 'mp4',
]);
define('VIDEO_AULA_ACCEPT_HTML', '.mp4');
// Vídeo aula NÃO usa o limite de 500MB de UPLOADS_MAX_SIZE (documento
// comum) -- vídeo de treinamento gravado costuma passar bem disso. "Sem
// limite" de verdade não existe (o PHP sempre precisa de um número), então
// isso aqui é um teto bem alto (5GB) só pra nunca ser o motivo de travar um
// envio -- na prática, quem primeiro vai limitar um arquivo gigante é o
// próprio tempo de upload da internet do cliente, não esse número. Por
// arquivo desse tamanho, NÃO faz backup dos bytes no banco (ver
// salvar_documento_upload(), parâmetro $comBackupBanco) -- ler um vídeo de
// vários GB inteiro pra memória pra gravar como BLOB estouraria o
// memory_limit do PHP. Ou seja: vídeo aula fica só em disco, sem a rede de
// segurança contra o deploy do Hostinger apagar uploads/ -- se isso um dia
// incomodar, a solução é um storage externo (S3 etc.), não esse backup.
define('VIDEO_AULA_MAX_SIZE', 5 * 1024 * 1024 * 1024);

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
// avatar-N.svg) -- opção pra quem não quiser subir a própria foto. Kleber
// pediu pra aumentar a variedade depois de perceber que os cadastros
// rápidos (aba Usuários) nascem sem avatar até alguém escolher um.
define('QUANTIDADE_AVATARES_PREDEFINIDOS', 24);

// Categorias de chamado -- mesma lista de área que já existia no HESK (o
// sistema de chamados antigo), pra manter a mesma linguagem que os clientes
// já conhecem em vez de inventar uma nova.
// Exatamente 10 categorias de propósito -- é o limite máximo de linhas de
// uma lista interativa do WhatsApp (ver WhatsAppBot::etapaTitulo()), pedido
// do Kleber pra categoria também virar botão/lista em vez de numerado por
// texto. "Estoque/Custos" e "PCP" viraram uma categoria só, "Integrações"
// saiu (usar "Geral" ou "Desenvolvimento" nesses casos).
define('CATEGORIAS_CHAMADO', [
    'Geral',
    'Compras',
    'Estoque/Custos/PCP',
    'Faturamento',
    'Financeiro',
    'Gestão de Pessoal',
    'Ponto Eletrônico',
    'Fiscal',
    'Contabilidade',
    'Desenvolvimento',
]);

// Sugestões de nome pra cadastro rápido de setor (cliente.php, modal
// Gerenciar setores) -- modelo inicial passado pelo Kleber, agora com um
// ícone (bootstrap-icons) já sugerido pra cada um. Setor é totalmente
// flexível/cadastrável por cliente, isso aqui é só atalho -- o nome digitado
// não precisa bater com nenhuma dessas chaves.
define('SETORES_SUGERIDOS', [
    'Ativo Fixo' => 'bi-box-seam',
    'Compras' => 'bi-cart',
    'Estoque/Custos' => 'bi-boxes',
    'Faturamento' => 'bi-receipt',
    'Financeiro' => 'bi-cash-coin',
    'PCP' => 'bi-diagram-3',
    'RH' => 'bi-people',
    'Ponto Eletrônico' => 'bi-clock-history',
    'Contabilidade' => 'bi-calculator',
    'Fiscal' => 'bi-file-earmark-text',
    'TI' => 'bi-hdd-network',
    'Jurídico' => 'bi-briefcase',
    'Qualidade' => 'bi-patch-check',
    'Comercial/Vendas' => 'bi-graph-up-arrow',
    'Logística' => 'bi-truck',
    'Manutenção' => 'bi-tools',
    'Inspeção de Processos' => 'bi-clipboard-check',
    'Inspeção de Entradas' => 'bi-box-arrow-in-down',
]);

// Ícones (bootstrap-icons) disponíveis no seletor de setor, além dos já
// sugeridos acima -- pra quando o setor cadastrado não é nenhum dos nomes
// padrão mas o cliente quer um ícone mesmo assim (ex.: um setor com nome
// próprio da empresa). Lista só de classes, sem nome associado.
define('ICONES_SETOR_DISPONIVEIS', [
    'bi-building', 'bi-diagram-3', 'bi-people', 'bi-person-badge',
    'bi-cash-coin', 'bi-receipt', 'bi-graph-up-arrow', 'bi-cart',
    'bi-boxes', 'bi-box-seam', 'bi-truck', 'bi-tools', 'bi-gear',
    'bi-clock-history', 'bi-calculator', 'bi-file-earmark-text',
    'bi-briefcase', 'bi-patch-check', 'bi-shield-check', 'bi-hdd-network',
    'bi-headset', 'bi-chat-dots', 'bi-clipboard-check', 'bi-globe',
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
