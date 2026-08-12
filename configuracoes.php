<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Configuracoes;
use AdminPrime\Database;

Auth::requireAdmin();

$pdo = Database::connection();

// Todas as chaves editáveis por essa tela -- cada uma vira uma linha na
// tabela `configuracoes` (chave/valor). O "padrão" mostrado quando NADA foi
// salvo ainda pela tela é o valor atual da constante equivalente de
// config/config.php (env var em produção), só pra não aparecer em branco.
$camposConfiguracao = [
    'whatsapp_access_token' => defined('WHATSAPP_ACCESS_TOKEN') ? WHATSAPP_ACCESS_TOKEN : '',
    'whatsapp_phone_number_id' => defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '',
    'whatsapp_app_secret' => defined('WHATSAPP_APP_SECRET') ? WHATSAPP_APP_SECRET : '',
    'whatsapp_notificar_telefone' => defined('WHATSAPP_NOTIFICAR_TELEFONE') ? WHATSAPP_NOTIFICAR_TELEFONE : '',
    'smtp_host' => defined('SMTP_HOST') ? SMTP_HOST : '',
    'smtp_port' => defined('SMTP_PORT') ? (string) SMTP_PORT : '587',
    'smtp_secure' => defined('SMTP_SECURE') ? SMTP_SECURE : 'tls',
    'smtp_user' => defined('SMTP_USER') ? SMTP_USER : '',
    'smtp_pass' => defined('SMTP_PASS') ? SMTP_PASS : '',
    'smtp_nome_remetente' => 'Prime Hub',
    'notificacao_emails_equipe' => defined('NOTIFICACAO_EMAILS_EQUIPE') ? implode("\n", NOTIFICACAO_EMAILS_EQUIPE) : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (array_keys($camposConfiguracao) as $chave) {
        Configuracoes::definir($pdo, $chave, $_POST[$chave] ?? '', Auth::userId());
    }

    flash_set('sucesso', 'Configurações salvas.');
    header('Location: ' . url('configuracoes.php'));
    exit;
}

// Valor efetivo mostrado no formulário: o que estiver salvo no banco (já
// editado pela tela alguma vez) ou, se nunca foi editado, o valor atual da
// constante -- assim o admin sempre vê o que está REALMENTE em uso agora,
// de onde quer que ele venha.
$valores = [];
foreach ($camposConfiguracao as $chave => $padrao) {
    $valores[$chave] = Configuracoes::get($pdo, $chave, $padrao) ?? '';
}

$tituloPagina = 'Configurações';
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-gear"></i> Configurações</h4>
</div>

<form method="post">
    <?= csrf_field() ?>

    <div class="card card-stat mb-3">
        <div class="card-body">
            <strong class="d-block mb-1"><i class="bi bi-whatsapp"></i> WhatsApp</strong>
            <p class="text-muted small">Integração direta com a WhatsApp Business Platform (Cloud API) da Meta -- sem intermediador/BSP. Enquanto o token não estiver preenchido, os avisos de chamado/treinamento só saem por e-mail.</p>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Access Token</label>
                    <div class="campo-senha">
                        <input type="password" name="whatsapp_access_token" class="form-control" value="<?= e($valores['whatsapp_access_token']) ?>" autocomplete="off">
                        <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar token"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="form-text">Token da Meta Business Manager -- prefira um token <strong>permanente</strong> (via System User), o temporário expira em 24h e para os avisos de repente.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone Number ID</label>
                    <input type="text" name="whatsapp_phone_number_id" class="form-control" value="<?= e($valores['whatsapp_phone_number_id']) ?>" autocomplete="off">
                </div>
                <div class="col-md-8">
                    <label class="form-label">App Secret</label>
                    <div class="campo-senha">
                        <input type="password" name="whatsapp_app_secret" class="form-control" value="<?= e($valores['whatsapp_app_secret']) ?>" autocomplete="off">
                        <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar app secret"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="form-text">Usado só pra validar a assinatura dos webhooks recebidos da Meta. Opcional, mas recomendado.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Telefone que recebe as notificações</label>
                    <input type="text" name="whatsapp_notificar_telefone" class="form-control" value="<?= e($valores['whatsapp_notificar_telefone']) ?>" placeholder="5517981368201">
                    <div class="form-text">Número da equipe interna que recebe aviso de chamado novo (com DDI+DDD, só números).</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-stat mb-3">
        <div class="card-body">
            <strong class="d-block mb-1"><i class="bi bi-envelope"></i> E-mail (SMTP)</strong>
            <p class="text-muted small">Configuração da caixa usada pra enviar avisos de chamado/treinamento e convites de acesso ao portal.</p>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Servidor SMTP (host)</label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= e($valores['smtp_host']) ?>" placeholder="smtp.hostinger.com">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Porta</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= e($valores['smtp_port']) ?>" placeholder="465">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Segurança</label>
                    <select name="smtp_secure" class="form-select">
                        <?php foreach (['ssl' => 'SSL', 'tls' => 'TLS'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $valores['smtp_secure'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Nome do remetente</label>
                    <input type="text" name="smtp_nome_remetente" class="form-control" value="<?= e($valores['smtp_nome_remetente']) ?>" placeholder="Prime Hub">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Usuário (também é o e-mail remetente)</label>
                    <input type="email" name="smtp_user" class="form-control" value="<?= e($valores['smtp_user']) ?>" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Senha</label>
                    <div class="campo-senha">
                        <input type="password" name="smtp_pass" class="form-control" value="<?= e($valores['smtp_pass']) ?>" autocomplete="off">
                        <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">E-mails da equipe que recebem aviso de todo chamado/treinamento novo</label>
                    <textarea name="notificacao_emails_equipe" class="form-control" rows="3" placeholder="um e-mail por linha"><?= e($valores['notificacao_emails_equipe']) ?></textarea>
                    <div class="form-text">Um e-mail por linha (ou separados por vírgula).</div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-dark"><i class="bi bi-save"></i> Salvar configurações</button>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-senha').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = botao.previousElementSibling;
            var mostrando = campo.type === 'text';
            campo.type = mostrando ? 'password' : 'text';
            botao.querySelector('i').className = mostrando ? 'bi bi-eye' : 'bi bi-eye-slash';
        });
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
