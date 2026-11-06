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
    'gemini_api_key' => defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '',
    'gemini_api_key_busca' => defined('GEMINI_API_KEY_BUSCA') ? GEMINI_API_KEY_BUSCA : '',
    'ia_chamados_modo' => 'sugestao',
    'ia_chamados_usuario_id' => '',
    'ia_chamados_limite_dia' => '50',
    'ia_chamados_limite_busca_dia' => '15',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!in_array($_POST['ia_chamados_modo'] ?? '', \AdminPrime\IaChamados::MODOS, true)) {
        $_POST['ia_chamados_modo'] = 'sugestao';
    }
    foreach (['ia_chamados_limite_dia', 'ia_chamados_limite_busca_dia'] as $campoLimite) {
        $_POST[$campoLimite] = (string) max(0, (int) ($_POST[$campoLimite] ?? 0));
    }

    foreach (array_keys($camposConfiguracao) as $chave) {
        Configuracoes::definir($pdo, $chave, $_POST[$chave] ?? '', Auth::userId());
    }

    // Timbrado (cabeçalho/rodapé real do PDF, ver src/PdfExport.php) --
    // upload é opcional a cada salvamento (só troca a imagem se um arquivo
    // novo vier junto), mas a altura configurada é sempre atualizada.
    $errosTimbrado = array_filter([
        processar_upload_timbrado($pdo, 'cabecalho'),
        processar_upload_timbrado($pdo, 'rodape'),
    ]);

    $alturaCabecalhoTimbrado = max(5, min(60, (float) str_replace(',', '.', $_POST['cabecalho_altura_mm'] ?? '28')));
    $alturaRodapeTimbrado = max(5, min(60, (float) str_replace(',', '.', $_POST['rodape_altura_mm'] ?? '16')));
    $pdo->prepare('UPDATE timbrado_pdf SET cabecalho_altura_mm = ?, rodape_altura_mm = ?, atualizado_por = ? WHERE id = 1')
        ->execute([$alturaCabecalhoTimbrado, $alturaRodapeTimbrado, Auth::userId()]);

    if ($errosTimbrado) {
        flash_set('erro', implode(' ', $errosTimbrado));
    } else {
        flash_set('sucesso', 'Configurações salvas.');
    }
    header('Location: ' . url('configuracoes.php'));
    exit;
}

$timbrado = $pdo->query('SELECT * FROM timbrado_pdf WHERE id = 1')->fetch();

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

<form method="post" enctype="multipart/form-data">
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

    <div class="card card-stat mb-3">
        <div class="card-body">
            <strong class="d-block mb-1"><i class="bi bi-stars"></i> IA Gemini (Google)</strong>
            <p class="text-muted small">Duas chaves de propósito -- não é redundância, é isolamento de custo. Enquanto a chave de análise não estiver preenchida, os botões de sugestão de IA ficam desabilitados (sem quebrar a tela).</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Chave de análise (chamados/KPIs/implantação)</label>
                    <div class="campo-senha">
                        <input type="password" name="gemini_api_key" class="form-control" value="<?= e($valores['gemini_api_key']) ?>" autocomplete="off">
                        <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar chave"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="form-text">Analisa só dados JÁ CADASTRADOS no sistema, nunca busca na internet -- fica sempre dentro do plano gratuito do Google AI Studio.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Chave de busca (perfil público da empresa)</label>
                    <div class="campo-senha">
                        <input type="password" name="gemini_api_key_busca" class="form-control" value="<?= e($valores['gemini_api_key_busca']) ?>" autocomplete="off">
                        <button type="button" class="toggle-senha" tabindex="-1" aria-label="Mostrar chave"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="form-text"><strong>Precisa ser de um projeto Google Cloud SEPARADO da chave de análise</strong>, com faturamento ativado -- ativar a ferramenta de busca do Google remove o plano gratuito do projeto INTEIRO. Se for a mesma chave/projeto da análise, a análise interna que hoje é de graça passa a ser cobrada também.</div>
                </div>
            </div>
        </div>
    </div>

    <?php
    $usoIaHoje = $pdo->query("SELECT COUNT(*) AS analises, COALESCE(SUM(usou_busca), 0) AS buscas,
            COALESCE(SUM(tokens_entrada), 0) AS entrada, COALESCE(SUM(tokens_saida), 0) AS saida
        FROM chamado_ia_analises WHERE atualizado_em >= CURDATE() AND status IN ('sugerida','enviada','sem_resposta','erro')")->fetch();
    $usoIaMes = $pdo->query("SELECT COUNT(*) AS analises, COALESCE(SUM(usou_busca), 0) AS buscas
        FROM chamado_ia_analises WHERE atualizado_em >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND status IN ('sugerida','enviada','sem_resposta','erro')")->fetch();
    ?>
    <div class="card card-stat mb-3">
        <div class="card-body">
            <strong class="d-block mb-1"><i class="bi bi-robot"></i> IA nos chamados</strong>
            <p class="text-muted small">Quando o cliente abre um chamado (portal ou WhatsApp), a IA procura primeiro chamados parecidos que a equipe já resolveu. Só se não achar nada pesquisa no TDN da TOTVS e no User Function, que é a etapa que gera custo maior. Os limites abaixo cortam o gasto: passou do limite, a IA para até o dia seguinte.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Modo</label>
                    <select name="ia_chamados_modo" class="form-select">
                        <option value="sugestao" <?= $valores['ia_chamados_modo'] === 'sugestao' ? 'selected' : '' ?>>Sugestão: só a equipe vê, e decide se envia</option>
                        <option value="automatico" <?= $valores['ia_chamados_modo'] === 'automatico' ? 'selected' : '' ?>>Automático: já responde o cliente sozinha</option>
                        <option value="desligado" <?= $valores['ia_chamados_modo'] === 'desligado' ? 'selected' : '' ?>>Desligado</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Responder em nome de</label>
                    <?php $autorIaAtual = \AdminPrime\IaChamados::autorResposta($pdo); ?>
                    <select name="ia_chamados_usuario_id" class="form-select">
                        <?php foreach ($pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll() as $usuarioIa): ?>
                            <option value="<?= (int) $usuarioIa['id'] ?>" <?= (int) $usuarioIa['id'] === (int) $autorIaAtual['id'] ? 'selected' : '' ?>><?= e($usuarioIa['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">As respostas automáticas saem com o nome e a foto dessa pessoa, igual a uma resposta feita pela tela. O cliente não vê nenhuma menção a IA.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Máximo de análises por dia</label>
                    <input type="number" min="0" name="ia_chamados_limite_dia" class="form-control" value="<?= e($valores['ia_chamados_limite_dia']) ?>">
                    <div class="form-text">Cada análise na nossa base custa frações de centavo.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Máximo de buscas no TDN/User Function por dia</label>
                    <input type="number" min="0" name="ia_chamados_limite_busca_dia" class="form-control" value="<?= e($valores['ia_chamados_limite_busca_dia']) ?>">
                    <div class="form-text">A busca do Google é cobrada à parte, por pesquisa feita. 0 = nunca busca na internet.</div>
                </div>
            </div>
            <p class="small text-muted mt-3 mb-0">
                Hoje: <?= (int) $usoIaHoje['analises'] ?> análise(s), <?= (int) $usoIaHoje['buscas'] ?> com busca na internet,
                <?= number_format((int) $usoIaHoje['entrada'] + (int) $usoIaHoje['saida'], 0, ',', '.') ?> tokens.
                Neste mês: <?= (int) $usoIaMes['analises'] ?> análise(s), <?= (int) $usoIaMes['buscas'] ?> com busca.
            </p>
        </div>
    </div>

    <div class="card card-stat mb-3">
        <div class="card-body">
            <strong class="d-block mb-1"><i class="bi bi-file-earmark-pdf"></i> Timbrado dos PDFs</strong>
            <p class="text-muted small">Cabeçalho e rodapé usados em <strong>todo PDF gerado pelo sistema</strong> (análises de IA, sugestões de implantação etc., ver <code>src/PdfExport.php</code>). Enquanto nenhuma imagem for enviada aqui, o sistema desenha um timbrado padrão automaticamente -- não precisa preencher isso pra continuar funcionando.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Cabeçalho</label>
                    <?php if (arquivo_publico_existe($timbrado['cabecalho_caminho'])): ?>
                        <div class="mb-2"><img src="<?= e(asset_url($timbrado['cabecalho_caminho'])) ?>" alt="Cabeçalho atual" style="max-width:100%; max-height:80px; border:1px solid #e5e7eb; border-radius:4px;"></div>
                    <?php endif; ?>
                    <input type="file" name="timbrado_cabecalho" class="form-control" accept="image/png,image/jpeg">
                    <div class="form-text">JPG ou PNG, cobrindo a LARGURA TOTAL da página A4 (recomendado ~2000&times;230px, ou qualquer imagem com essa proporção larga -- ela é esticada pra caber exatamente na altura configurada ao lado).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Altura do cabeçalho (mm)</label>
                    <input type="number" step="0.5" min="5" max="60" name="cabecalho_altura_mm" class="form-control" value="<?= e($timbrado['cabecalho_altura_mm']) ?>">
                    <div class="form-text">Página A4 tem 297mm de altura -- 28mm (padrão) é uma faixa fina no topo.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rodapé</label>
                    <?php if (arquivo_publico_existe($timbrado['rodape_caminho'])): ?>
                        <div class="mb-2"><img src="<?= e(asset_url($timbrado['rodape_caminho'])) ?>" alt="Rodapé atual" style="max-width:100%; max-height:60px; border:1px solid #e5e7eb; border-radius:4px;"></div>
                    <?php endif; ?>
                    <input type="file" name="timbrado_rodape" class="form-control" accept="image/png,image/jpeg">
                    <div class="form-text">Mesma ideia do cabeçalho, só que pra faixa de baixo da página.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Altura do rodapé (mm)</label>
                    <input type="number" step="0.5" min="5" max="60" name="rodape_altura_mm" class="form-control" value="<?= e($timbrado['rodape_altura_mm']) ?>">
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
