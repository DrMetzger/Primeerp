<?php

require_once __DIR__ . '/bootstrap.php';

use AdminPrime\Auth;
use AdminPrime\Database;
use AdminPrime\Notificacoes;
use AdminPrime\TempAccess;

Auth::requireLogin();

$pdo = Database::connection();

$clienteId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    die('Cliente não encontrado.');
}

// --- Ações (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'atualizar_cliente') {
        $stmt = $pdo->prepare('UPDATE clientes SET nome=?, status=?, contato_nome=?, contato_email=?, contato_telefone=?, contato_documento=?, observacoes=?, acesso_fora_horario=? WHERE id=?');
        $stmt->execute([
            trim($_POST['nome']),
            $_POST['status'],
            trim($_POST['contato_nome']) ?: null,
            trim($_POST['contato_email']) ?: null,
            apenas_digitos($_POST['contato_telefone'] ?? ''),
            normalizar_documento($_POST['contato_documento'] ?? ''),
            trim($_POST['observacoes']) ?: null,
            isset($_POST['acesso_fora_horario']) ? 1 : 0,
            $clienteId,
        ]);

        $erroLogo = processar_upload_logo_cliente($pdo, $clienteId);
        if ($erroLogo) {
            flash_set('erro', $erroLogo);
        }

        flash_set('sucesso', 'Dados do cliente atualizados.');
    }

    if ($acao === 'criar_kpi') {
        $stmt = $pdo->prepare('INSERT INTO kpis (cliente_id, nome_kpi, valor, meta, unidade, periodo, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            trim($_POST['nome_kpi']),
            str_replace(',', '.', $_POST['valor']),
            $_POST['meta'] !== '' ? str_replace(',', '.', $_POST['meta']) : null,
            trim($_POST['unidade']) ?: null,
            $_POST['periodo'] . '-01',
            trim($_POST['observacoes']) ?: null,
        ]);
        flash_set('sucesso', 'KPI registrado.');
    }

    if ($acao === 'criar_treinamento') {
        $stmt = $pdo->prepare('INSERT INTO treinamentos (cliente_id, setor_id, titulo, tipo, descricao, status, data_inicio, hora_inicio, data_fim, hora_fim, link_reuniao, responsavel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clienteId,
            $_POST['setor_id'] ?: null,
            trim($_POST['titulo']),
            $_POST['tipo'] === 'reuniao' ? 'reuniao' : 'treinamento',
            trim($_POST['descricao']) ?: null,
            $_POST['status'] ?: 'planejado',
            $_POST['data_inicio'] ?: null,
            $_POST['hora_inicio'] ?: null,
            $_POST['data_fim'] ?: null,
            $_POST['hora_fim'] ?: null,
            trim($_POST['link_reuniao'] ?? '') ?: null,
            $_POST['responsavel_id'] ?: null,
        ]);
        flash_set('sucesso', $_POST['tipo'] === 'reuniao' ? 'Reunião registrada.' : 'Treinamento registrado.');
    }

    if ($acao === 'atualizar_treinamento_status') {
        $stmt = $pdo->prepare('UPDATE treinamentos SET status=? WHERE id=? AND cliente_id=?');
        $stmt->execute([$_POST['status'], (int) $_POST['treinamento_id'], $clienteId]);
        flash_set('sucesso', 'Status do treinamento atualizado.');
    }

    if ($acao === 'upload_documento' && isset($_FILES['arquivo'])) {
        $arquivo = $_FILES['arquivo'];
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            flash_set('erro', 'Falha no upload do arquivo.');
        } elseif ($arquivo['size'] > UPLOADS_MAX_SIZE) {
            flash_set('erro', 'Arquivo maior que o limite permitido (15MB).');
        } else {
            $dirCliente = UPLOADS_DIR . '/clientes/' . $clienteId;
            if (!is_dir($dirCliente)) {
                mkdir($dirCliente, 0755, true);
            }
            $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
            $nomeArmazenado = bin2hex(random_bytes(16)) . ($extensao ? '.' . $extensao : '');
            $destino = $dirCliente . '/' . $nomeArmazenado;

            if (move_uploaded_file($arquivo['tmp_name'], $destino)) {
                // Backup dos bytes no banco -- ver Migrations::restaurarArquivoSeNecessario().
                $stmt = $pdo->prepare('INSERT INTO documentos (cliente_id, titulo, descricao, categoria, arquivo_nome_original, arquivo_caminho, arquivo_tamanho, criado_por, dados) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $clienteId,
                    trim($_POST['titulo']) ?: $arquivo['name'],
                    trim($_POST['descricao']) ?: null,
                    trim($_POST['categoria']) ?: null,
                    $arquivo['name'],
                    'clientes/' . $clienteId . '/' . $nomeArmazenado,
                    $arquivo['size'],
                    Auth::userId(),
                    file_get_contents($destino),
                ]);
                flash_set('sucesso', 'Documento enviado.');
            } else {
                flash_set('erro', 'Não foi possível salvar o arquivo no servidor.');
            }
        }
    }

    if ($acao === 'enviar_convite_portal') {
        if (!$cliente['contato_email']) {
            flash_set('erro', 'Cadastre um e-mail de contato para o cliente antes de enviar o convite.');
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO cliente_senha_tokens (cliente_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$clienteId, $token]);
            Notificacoes::conviteAcessoPortal($cliente, $token);
            flash_set('sucesso', 'Convite enviado para ' . $cliente['contato_email'] . '.');
        }
    }

    // Mesma ideia de "Resetar senha" do responsável de setor: zera a senha
    // na hora (invalida a atual) e já manda um convite novo -- antes só
    // existia pra contato master em usuarios.php, agora fica aqui também,
    // junto do resto do cadastro desse cliente.
    if ($acao === 'resetar_senha_master') {
        if (!$cliente['contato_email']) {
            flash_set('erro', 'Cadastre um e-mail de contato para o cliente antes de resetar a senha.');
        } else {
            $pdo->prepare('UPDATE clientes SET senha_hash = NULL WHERE id = ?')->execute([$clienteId]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO cliente_senha_tokens (cliente_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$clienteId, $token]);
            Notificacoes::conviteAcessoPortal($cliente, $token);
            flash_set('sucesso', 'Senha resetada e novo link enviado para ' . $cliente['contato_email'] . '.');
        }
    }

    if ($acao === 'desativar_portal_master') {
        $pdo->prepare('UPDATE clientes SET portal_ativo = 0, senha_hash = NULL WHERE id = ?')->execute([$clienteId]);
        flash_set('sucesso', 'Acesso ao portal desativado.');
    }

    if ($acao === 'criar_chamado_equipe') {
        $categoriaNovoChamado = trim($_POST['categoria'] ?? '');
        if (trim($_POST['titulo'] ?? '') === '' || trim($_POST['descricao'] ?? '') === '' || $categoriaNovoChamado === '') {
            flash_set('erro', 'Preencha título, categoria e descrição do chamado.');
        } else {
            // Título padronizado em MAIÚSCULAS (mesma regra do chamado aberto
            // pelo cliente no portal) -- pedido do Kleber pra não ter títulos
            // com capitalização diferente misturados na listagem.
            $stmt = $pdo->prepare('INSERT INTO chamados (cliente_id, titulo, descricao, categoria, prioridade, aberto_por, responsavel_id, setor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $clienteId,
                mb_strtoupper(trim($_POST['titulo']), 'UTF-8'),
                trim($_POST['descricao']),
                $categoriaNovoChamado,
                $_POST['prioridade'] ?? 'media',
                'equipe',
                $_POST['responsavel_id'] ?: null,
                $_POST['setor_id'] ?: null,
            ]);
            flash_set('sucesso', 'Chamado registrado.');
        }
    }

    // --- Setores (departamentos independentes do cliente) ---------------
    // Ver src/Migrations.php (tabelas setores/setor_responsaveis) e
    // ClienteAuth (login separado do master pra cada setor).

    if ($acao === 'criar_setor') {
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '') {
            flash_set('erro', 'Informe o nome do setor.');
        } else {
            $pdo->prepare('INSERT INTO setores (cliente_id, nome, icone) VALUES (?, ?, ?)')
                ->execute([$clienteId, $nomeSetor, $_POST['icone'] ?: null]);
            flash_set('sucesso', 'Setor "' . $nomeSetor . '" criado.');
        }
    }

    if ($acao === 'atualizar_setor') {
        $setorId = (int) $_POST['setor_id'];
        $nomeSetor = trim($_POST['nome'] ?? '');
        if ($nomeSetor === '') {
            flash_set('erro', 'Informe o nome do setor.');
        } else {
            $pdo->prepare('UPDATE setores SET nome = ?, icone = ?, ativo = ? WHERE id = ? AND cliente_id = ?')
                ->execute([$nomeSetor, $_POST['icone'] ?: null, isset($_POST['ativo']) ? 1 : 0, $setorId, $clienteId]);
            flash_set('sucesso', 'Setor atualizado.');
        }
    }

    // Exclusão é permanente, mas não é destrutiva pros chamados nem pras
    // PESSOAS: chamados ficam sem setor (setor_id vira NULL) e os
    // responsáveis que estavam nesse setor voltam pra "sem setor" (continuam
    // cadastrados na aba Usuários, só perdem o vínculo) -- antes a exclusão
    // apagava o cadastro da pessoa inteiro via CASCADE, o que deixou de fazer
    // sentido agora que o cadastro dela é independente do setor.
    if ($acao === 'excluir_setor') {
        $setorId = (int) $_POST['setor_id'];
        $confereSetor = $pdo->prepare('SELECT 1 FROM setores WHERE id = ? AND cliente_id = ?');
        $confereSetor->execute([$setorId, $clienteId]);
        if ($confereSetor->fetchColumn()) {
            $pdo->prepare('UPDATE chamados SET setor_id = NULL WHERE setor_id = ?')->execute([$setorId]);
            $pdo->prepare('UPDATE setor_responsaveis SET setor_id = NULL WHERE setor_id = ?')->execute([$setorId]);
            if ((int) $cliente['setor_id'] === $setorId) {
                $pdo->prepare('UPDATE clientes SET setor_id = NULL WHERE id = ?')->execute([$clienteId]);
            }
            $pdo->prepare('DELETE FROM setores WHERE id = ?')->execute([$setorId]);
            flash_set('sucesso', 'Setor excluído. Os chamados e responsáveis dele continuam salvos, só sem setor vinculado.');
        }
    }

    // O master pertence a UM setor só (normalmente TI, que costuma tocar os
    // chamados) -- ele já vê/gerencia todos os setores independente disso,
    // então não precisa "participar" de vários como antes.
    if ($acao === 'definir_setor_master') {
        $novoSetorMasterId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
        $pdo->prepare('UPDATE clientes SET setor_id = ? WHERE id = ?')->execute([$novoSetorMasterId, $clienteId]);
        flash_set('sucesso', 'Setor do master atualizado.');
    }

    // --- Usuários do cliente (aba Usuários) ------------------------------
    // Cadastro da PESSOA, independente de setor -- o vínculo com um setor
    // específico é feito depois, relacionando (ver 'relacionar_responsavel_setor').

    if ($acao === 'criar_usuario_cliente') {
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');

        if ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } else {
            $telefoneResp = apenas_digitos($_POST['telefone'] ?? '');
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $setorIdResp = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;

            try {
                $pdo->prepare('INSERT INTO setor_responsaveis (cliente_id, setor_id, nome, email, telefone, documento) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$clienteId, $setorIdResp, $nomeResp, $emailResp, $telefoneResp, $documentoResp]);
                $responsavelId = (int) $pdo->lastInsertId();

                // Mesma pessoa que já é o contato master desse cliente,
                // cadastrada também como usuário (mesmo e-mail) -- sincroniza
                // o avatar com o do master direto no banco, em vez de
                // confiar só no fallback de exibição (resolver_avatar_por_email).
                if ($cliente['contato_email'] && $cliente['foto_perfil'] && strcasecmp(trim($cliente['contato_email']), $emailResp) === 0) {
                    $pdo->prepare('UPDATE setor_responsaveis SET foto_perfil = ?, foto_perfil_dados = ? WHERE id = ?')
                        ->execute([$cliente['foto_perfil'], $cliente['foto_perfil_dados'], $responsavelId]);
                }

                flash_set('sucesso', 'Usuário cadastrado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível cadastrar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    if ($acao === 'editar_usuario_cliente') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);
        $nomeResp = trim($_POST['nome'] ?? '');
        $emailResp = trim($_POST['email'] ?? '');

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } elseif ($nomeResp === '' || $emailResp === '') {
            flash_set('erro', 'Preencha nome e e-mail.');
        } else {
            $telefoneResp = apenas_digitos($_POST['telefone'] ?? '');
            $documentoResp = normalizar_documento($_POST['documento'] ?? '');
            $setorIdResp = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
            try {
                $pdo->prepare('UPDATE setor_responsaveis SET nome=?, email=?, telefone=?, documento=?, setor_id=? WHERE id=?')
                    ->execute([$nomeResp, $emailResp, $telefoneResp, $documentoResp, $setorIdResp, $responsavelId]);
                flash_set('sucesso', 'Usuário atualizado.');
            } catch (\PDOException $e) {
                flash_set('erro', 'Não foi possível salvar (e-mail já usado por outro usuário desse cliente?).');
            }
        }
    }

    if ($acao === 'excluir_usuario_cliente') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $pdo->prepare('DELETE FROM setor_responsaveis WHERE id = ? AND cliente_id = ?')->execute([$responsavelId, $clienteId]);
        flash_set('sucesso', 'Usuário removido.');
    }

    // Vincula (ou desvincula, se setor_id vier vazio) um usuário JÁ
    // cadastrado a um setor específico -- é isso que "Gerenciar setores"
    // usa pra buscar/relacionar em vez de cadastrar uma pessoa nova.
    if ($acao === 'relacionar_responsavel_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $novoSetorId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;
        $confereResp = $pdo->prepare('SELECT id FROM setor_responsaveis WHERE id = ? AND cliente_id = ?');
        $confereResp->execute([$responsavelId, $clienteId]);

        if (!$confereResp->fetchColumn()) {
            flash_set('erro', 'Usuário não encontrado.');
        } else {
            $pdo->prepare('UPDATE setor_responsaveis SET setor_id = ? WHERE id = ?')->execute([$novoSetorId, $responsavelId]);
            flash_set('sucesso', $novoSetorId ? 'Usuário relacionado ao setor.' : 'Usuário desvinculado do setor.');
        }
    }

    if ($acao === 'enviar_convite_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $stmt = $pdo->prepare('SELECT sr.*, s.nome AS setor_nome FROM setor_responsaveis sr LEFT JOIN setores s ON s.id = sr.setor_id WHERE sr.id = ? AND sr.cliente_id = ?');
        $stmt->execute([$responsavelId, $clienteId]);
        $responsavel = $stmt->fetch();

        if (!$responsavel) {
            flash_set('erro', 'Usuário não encontrado.');
        } elseif (!$responsavel['setor_id']) {
            flash_set('erro', 'Relacione esse usuário a um setor antes de convidar pro portal -- é o setor que define o que ele vai ver.');
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO setor_senha_tokens (setor_responsavel_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$responsavelId, $token]);
            Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token);
            flash_set('sucesso', 'Convite enviado para ' . $responsavel['email'] . '.');
        }
    }

    // Mesma ideia de "Resetar senha" dos clientes (usuarios.php): zera a
    // senha na hora (invalida a atual) e já manda um convite novo, em vez
    // de só reenviar o link mantendo a senha antiga válida.
    if ($acao === 'resetar_senha_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $stmt = $pdo->prepare('SELECT sr.*, s.nome AS setor_nome FROM setor_responsaveis sr LEFT JOIN setores s ON s.id = sr.setor_id WHERE sr.id = ? AND sr.cliente_id = ?');
        $stmt->execute([$responsavelId, $clienteId]);
        $responsavel = $stmt->fetch();

        if (!$responsavel || !$responsavel['setor_id']) {
            flash_set('erro', 'Usuário não encontrado ou sem setor relacionado.');
        } else {
            $pdo->prepare('UPDATE setor_responsaveis SET senha_hash = NULL WHERE id = ?')->execute([$responsavelId]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO setor_senha_tokens (setor_responsavel_id, token, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$responsavelId, $token]);
            Notificacoes::conviteAcessoSetor($responsavel, $responsavel['setor_nome'], $token);
            flash_set('sucesso', 'Senha resetada e novo link enviado para ' . $responsavel['email'] . '.');
        }
    }

    if ($acao === 'desativar_portal_setor') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $pdo->prepare('UPDATE setor_responsaveis SET portal_ativo = 0, senha_hash = NULL WHERE id = ? AND cliente_id = ?')
            ->execute([$responsavelId, $clienteId]);
        flash_set('sucesso', 'Acesso ao portal desativado.');
    }

    if ($acao === 'atualizar_avatar_setor_responsavel') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $erroFoto = processar_upload_foto_setor_responsavel($pdo, $responsavelId);
        flash_set($erroFoto ? 'erro' : 'sucesso', $erroFoto ?? 'Foto atualizada.');
    }

    if ($acao === 'escolher_avatar_predefinido_setor_responsavel') {
        $responsavelId = (int) $_POST['responsavel_id'];
        $indice = (int) ($_POST['avatar'] ?? 0);
        if (definir_avatar_predefinido_setor_responsavel($pdo, $responsavelId, $indice)) {
            flash_set('sucesso', 'Avatar atualizado.');
        }
    }

    if ($acao === 'responder_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        $mensagem = trim($_POST['mensagem'] ?? '');
        $temAnexo = !empty($_FILES['anexos']['name'][0] ?? '');

        if ($mensagem !== '' || $temAnexo) {
            $pdo->prepare('INSERT INTO chamado_respostas (chamado_id, autor_tipo, autor_nome, autor_usuario_id, mensagem) VALUES (?, "equipe", ?, ?, ?)')
                ->execute([$chamadoId, Auth::userName(), Auth::userId(), $mensagem !== '' ? $mensagem : '(anexo enviado)']);
            $respostaId = (int) $pdo->lastInsertId();

            $errosAnexo = processar_anexos_chamado($pdo, $chamadoId, $respostaId, 'equipe');
            recalcular_status_chamado($pdo, $chamadoId);

            $chamadoAtualizado = $pdo->prepare('SELECT * FROM chamados WHERE id = ?');
            $chamadoAtualizado->execute([$chamadoId]);
            Notificacoes::novaRespostaEquipe($chamadoAtualizado->fetch(), $cliente);

            flash_set('sucesso', $errosAnexo ? 'Resposta enviada, mas: ' . implode(' ', $errosAnexo) : 'Resposta enviada ao cliente.');
        }
    }

    if ($acao === 'fechar_chamado') {
        $pdo->prepare("UPDATE chamados SET status = 'fechado', fechado_em = NOW() WHERE id = ? AND cliente_id = ?")
            ->execute([(int) $_POST['chamado_id'], $clienteId]);
        flash_set('sucesso', 'Chamado encerrado.');
    }

    if ($acao === 'reabrir_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        // Confirma que o chamado é desse cliente antes de reabrir.
        $confere = $pdo->prepare('SELECT 1 FROM chamados WHERE id = ? AND cliente_id = ?');
        $confere->execute([$chamadoId, $clienteId]);
        if ($confere->fetchColumn()) {
            recalcular_status_chamado($pdo, $chamadoId);
            flash_set('sucesso', 'Chamado reaberto.');
        }
    }

    // Trocar o responsável de um chamado já existente -- antes só dava pra
    // escolher o responsável na hora de CRIAR o chamado, não tinha como
    // reatribuir depois pra outro colega da equipe.
    if ($acao === 'atribuir_responsavel_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        $novoResponsavelId = $_POST['responsavel_id'] !== '' ? (int) $_POST['responsavel_id'] : null;

        $confere = $pdo->prepare('SELECT id, titulo, responsavel_id FROM chamados WHERE id = ? AND cliente_id = ?');
        $confere->execute([$chamadoId, $clienteId]);
        $chamadoAlvo = $confere->fetch();

        if ($chamadoAlvo) {
            $pdo->prepare('UPDATE chamados SET responsavel_id = ? WHERE id = ?')->execute([$novoResponsavelId, $chamadoId]);

            // Só avisa por e-mail se realmente MUDOU pra alguém (não dispara
            // de novo se salvar o mesmo responsável, nem quando desatribui).
            if ($novoResponsavelId && $novoResponsavelId !== (int) $chamadoAlvo['responsavel_id']) {
                $stmtNovoResp = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
                $stmtNovoResp->execute([$novoResponsavelId]);
                $novoResponsavel = $stmtNovoResp->fetch();
                if ($novoResponsavel) {
                    Notificacoes::chamadoAtribuido(['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo'], 'cliente_id' => $clienteId], $novoResponsavel);
                }
            }

            flash_set('sucesso', 'Responsável do chamado atualizado.');
        }
    }

    // Trocar o setor de um chamado depois de aberto -- exige que o setor de
    // destino já tenha um responsável cadastrado (é ele que recebe o e-mail
    // avisando que o chamado caiu pro setor dele; sem responsável não tem
    // pra quem avisar, então a troca é bloqueada). Tirar o setor ("Sem
    // setor") não passa por essa trava, já que não precisa avisar ninguém.
    if ($acao === 'atribuir_setor_chamado') {
        $chamadoId = (int) $_POST['chamado_id'];
        $novoSetorId = $_POST['setor_id'] !== '' ? (int) $_POST['setor_id'] : null;

        $confere = $pdo->prepare('SELECT id, titulo, setor_id FROM chamados WHERE id = ? AND cliente_id = ?');
        $confere->execute([$chamadoId, $clienteId]);
        $chamadoAlvo = $confere->fetch();

        if (!$chamadoAlvo) {
            flash_set('erro', 'Chamado não encontrado.');
        } elseif ($novoSetorId === null) {
            $pdo->prepare('UPDATE chamados SET setor_id = NULL WHERE id = ?')->execute([$chamadoId]);
            flash_set('sucesso', 'Chamado sem setor vinculado.');
        } else {
            $stmtSetorAlvo = $pdo->prepare('SELECT id, nome FROM setores WHERE id = ? AND cliente_id = ?');
            $stmtSetorAlvo->execute([$novoSetorId, $clienteId]);
            $setorAlvo = $stmtSetorAlvo->fetch();

            $stmtMembrosAlvo = $pdo->prepare('SELECT * FROM setor_responsaveis WHERE setor_id = ?');
            $stmtMembrosAlvo->execute([$novoSetorId]);
            $membrosSetorAlvo = $stmtMembrosAlvo->fetchAll();

            $masterNesseSetor = (int) $cliente['setor_id'] === $novoSetorId;

            if (!$setorAlvo || (!$membrosSetorAlvo && !$masterNesseSetor)) {
                flash_set('erro', 'Esse setor ainda não tem nenhum responsável cadastrado -- relacione um usuário em "Gerenciar setores" antes de atribuir chamados a ele.');
            } else {
                $pdo->prepare('UPDATE chamados SET setor_id = ? WHERE id = ?')->execute([$novoSetorId, $chamadoId]);

                // Só avisa por e-mail se realmente MUDOU de setor (não
                // dispara de novo se salvar o mesmo setor) -- avisa TODOS os
                // responsáveis desse setor, não só um (pode ter vários).
                if ($novoSetorId !== (int) $chamadoAlvo['setor_id']) {
                    foreach ($membrosSetorAlvo as $membro) {
                        Notificacoes::chamadoAtribuidoSetor(['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo']], $membro, $setorAlvo['nome']);
                    }
                    if ($masterNesseSetor && $cliente['contato_email']) {
                        Notificacoes::chamadoAtribuidoSetor(
                            ['id' => $chamadoId, 'titulo' => $chamadoAlvo['titulo']],
                            ['nome' => $cliente['contato_nome'] ?: $cliente['nome'], 'email' => $cliente['contato_email']],
                            $setorAlvo['nome']
                        );
                    }
                }

                flash_set('sucesso', 'Chamado vinculado ao setor ' . $setorAlvo['nome'] . '.');
            }
        }
    }

    header('Location: ' . url('cliente.php?id=' . $clienteId . '#' . ($_POST['aba_retorno'] ?? 'visao-geral')));
    exit;
}

// --- Dados para exibição ---
// Ordem pensada pra quem chega direto do Dashboard pra triagem: primeiro o
// que é urgente/alta prioridade E pendência nossa (aberto/aguardando_equipe),
// depois o resto do que é pendência nossa (mais antigo primeiro = mais
// atrasado primeiro), depois o que já respondemos (aguardando_cliente), e só
// por último o que já foi fechado.
$chamados = $pdo->prepare('
    SELECT c.*, u.nome AS responsavel_nome, u.foto_perfil AS responsavel_foto
    FROM chamados c
    LEFT JOIN usuarios u ON u.id = c.responsavel_id
    WHERE c.cliente_id = ?
    ORDER BY
        CASE
            WHEN c.status IN ("aberto", "aguardando_equipe") AND c.prioridade IN ("urgente", "alta") THEN 1
            WHEN c.status IN ("aberto", "aguardando_equipe") THEN 2
            WHEN c.status = "aguardando_cliente" THEN 3
            ELSE 4
        END ASC,
        CASE WHEN c.status = "fechado" THEN -UNIX_TIMESTAMP(c.criado_em) ELSE UNIX_TIMESTAMP(c.criado_em) END ASC
');
$chamados->execute([$clienteId]);
$chamados = $chamados->fetchAll();

// Monta a conversa completa de cada chamado (mensagem de abertura + todas as
// respostas + anexos de cada uma), já num formato único pro template só
// percorrer e desenhar as bolhas -- antes a mensagem de abertura ficava de
// fora do chat, sem bolha/cor/nome do contato, o que ficava estranho.
$linhasChatPorChamado = [];
if ($chamados) {
    $ids = array_column($chamados, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmtResp = $pdo->prepare("SELECT * FROM chamado_respostas WHERE chamado_id IN ($placeholders) ORDER BY criado_em ASC");
    $stmtResp->execute($ids);
    $respostasPorChamado = [];
    foreach ($stmtResp->fetchAll() as $r) {
        $respostasPorChamado[$r['chamado_id']][] = $r;
    }

    $stmtAnexos = $pdo->prepare("SELECT * FROM chamado_anexos WHERE chamado_id IN ($placeholders) ORDER BY criado_em ASC");
    $stmtAnexos->execute($ids);
    $anexosPorChamado = [];
    foreach ($stmtAnexos->fetchAll() as $a) {
        // resposta_id NULL = anexo da mensagem de abertura (chave 'abertura').
        $chave = $a['resposta_id'] ?? 'abertura';
        $anexosPorChamado[$a['chamado_id']][$chave][] = $a;
    }

    // Foto de cada membro da equipe que respondeu -- antes o chat só sabia o
    // NOME de quem respondeu (autor_nome, texto solto), sem saber a foto de
    // qual usuário era, então toda resposta da equipe caía na inicial "K"
    // genérica. Respostas antigas/importadas do HESK não têm
    // autor_usuario_id (NULL) e continuam caindo na inicial, sem problema.
    $idsAutoresEquipe = array_unique(array_filter(array_column($respostasPorChamado ? array_merge(...array_values($respostasPorChamado)) : [], 'autor_usuario_id')));
    $fotoPorUsuarioId = [];
    if ($idsAutoresEquipe) {
        $placeholdersUsuarios = implode(',', array_fill(0, count($idsAutoresEquipe), '?'));
        $stmtFotos = $pdo->prepare("SELECT id, foto_perfil FROM usuarios WHERE id IN ($placeholdersUsuarios)");
        $stmtFotos->execute(array_values($idsAutoresEquipe));
        foreach ($stmtFotos->fetchAll() as $u) {
            $fotoPorUsuarioId[$u['id']] = $u['foto_perfil'];
        }
    }

    // Qual responsável de setor EXATAMENTE abriu/respondeu -- não é mais "o
    // responsável atual do setor" (deixou de bater desde que um setor pode
    // ter vários responsáveis ao mesmo tempo). Foto do master também passa
    // pelo resolver (mesma pessoa pode estar cadastrada com o mesmo e-mail
    // em mais de um lugar, ver helpers.php).
    $idsResponsaveisMencionados = array_unique(array_filter(array_merge(
        array_column($chamados, 'aberto_por_setor_responsavel_id'),
        $respostasPorChamado ? array_column(array_merge(...array_values($respostasPorChamado)), 'autor_setor_responsavel_id') : []
    )));
    $dadosPorResponsavelId = [];
    if ($idsResponsaveisMencionados) {
        $placeholdersResp = implode(',', array_fill(0, count($idsResponsaveisMencionados), '?'));
        $stmtDadosResp = $pdo->prepare("SELECT id, nome, email, foto_perfil FROM setor_responsaveis WHERE id IN ($placeholdersResp)");
        $stmtDadosResp->execute(array_values($idsResponsaveisMencionados));
        foreach ($stmtDadosResp->fetchAll() as $sr) {
            $dadosPorResponsavelId[$sr['id']] = ['nome' => $sr['nome'], 'foto' => resolver_avatar_por_email($pdo, $sr['email'], $sr['foto_perfil'])];
        }
    }
    $fotoMaster = resolver_avatar_por_email($pdo, $cliente['contato_email'] ?? null, $cliente['foto_perfil'] ?? null);

    foreach ($chamados as $c) {
        $linhas = [];

        $dadosAbertura = $c['aberto_por_tipo'] === 'setor' ? ($dadosPorResponsavelId[$c['aberto_por_setor_responsavel_id']] ?? null) : null;

        if ($c['aberto_por'] === 'equipe') {
            $autorTipo = 'equipe';
            $nomeAutor = $c['responsavel_nome'] ?: 'Equipe PrimeERP';
            $foto = $c['responsavel_foto'] ?? null;
        } elseif ($c['aberto_por'] === 'publico') {
            $autorTipo = 'cliente';
            $nomeAutor = $c['contato_nome_publico'] ?: ($c['empresa_informada'] ?: 'Cliente');
            $foto = null;
        } else {
            $autorTipo = 'cliente';
            $nomeAutor = $dadosAbertura['nome'] ?? ($cliente['contato_nome'] ?: $cliente['nome']);
            $foto = $dadosAbertura['foto'] ?? $fotoMaster;
        }

        $linhas[] = [
            'autor_tipo' => $autorTipo,
            'autor_nome' => $nomeAutor,
            'foto' => $foto,
            'mensagem' => $c['descricao'],
            'criado_em' => $c['criado_em'],
            'anexos' => $anexosPorChamado[$c['id']]['abertura'] ?? [],
        ];

        foreach ($respostasPorChamado[$c['id']] ?? [] as $r) {
            $dadosResposta = $r['autor_cliente_tipo'] === 'setor' ? ($dadosPorResponsavelId[$r['autor_setor_responsavel_id']] ?? null) : null;
            $linhas[] = [
                'autor_tipo' => $r['autor_tipo'],
                'autor_nome' => $r['autor_nome'],
                // Resposta da equipe sem autor_usuario_id vinculado (antiga/importada
                // do HESK, cujo autor_nome não bate com nenhum usuário atual) cai pra
                // foto de quem é o responsável atual do chamado, em vez de ficar sem
                // foto nenhuma -- melhor um retrato plausível do que nunca mostrar
                // nada, já que na prática quase toda resposta é de quem está/ficou
                // responsável pelo chamado.
                'foto' => $r['autor_tipo'] === 'cliente'
                    ? ($r['autor_cliente_tipo'] === 'setor' ? ($dadosResposta['foto'] ?? null) : $fotoMaster)
                    : ($fotoPorUsuarioId[$r['autor_usuario_id']] ?? $c['responsavel_foto'] ?? null),
                'mensagem' => $r['mensagem'],
                'criado_em' => $r['criado_em'],
                'anexos' => $anexosPorChamado[$c['id']][$r['id']] ?? [],
            ];
        }

        $linhasChatPorChamado[$c['id']] = $linhas;
    }
}

$kpis = $pdo->prepare('SELECT * FROM kpis WHERE cliente_id = ? ORDER BY periodo DESC, nome_kpi ASC');
$kpis->execute([$clienteId]);
$kpis = $kpis->fetchAll();

$documentos = $pdo->prepare('SELECT d.*, u.nome AS criado_por_nome FROM documentos d LEFT JOIN usuarios u ON u.id = d.criado_por WHERE d.cliente_id = ? ORDER BY d.criado_em DESC');
$documentos->execute([$clienteId]);
$documentos = $documentos->fetchAll();

$treinamentos = $pdo->prepare('
    SELECT t.*, u.nome AS responsavel_nome, s.nome AS setor_nome
    FROM treinamentos t
    LEFT JOIN usuarios u ON u.id = t.responsavel_id
    LEFT JOIN setores s ON s.id = t.setor_id
    WHERE t.cliente_id = ?
    ORDER BY t.data_inicio DESC, t.hora_inicio ASC
');
$treinamentos->execute([$clienteId]);
$treinamentos = $treinamentos->fetchAll();
$treinamentosPorSetorId = [];
foreach ($treinamentos as $t) {
    if ($t['setor_id']) {
        $treinamentosPorSetorId[$t['setor_id']][] = $t;
    }
}

$usuarios = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();

// Todas as pessoas cadastradas pra ESSE cliente (aba Usuários) -- não
// depende mais de já estar num setor (setor_id pode ser NULL, quando ainda
// não foi relacionado a nenhum). O master (contato_* de clientes) não entra
// aqui porque não é uma linha de setor_responsaveis, é tratado à parte.
$usuariosCliente = $pdo->prepare('SELECT * FROM setor_responsaveis WHERE cliente_id = ? ORDER BY nome ASC');
$usuariosCliente->execute([$clienteId]);
$usuariosCliente = $usuariosCliente->fetchAll();

// Setores (departamentos) cadastrados pra esse cliente, com o responsável
// (se já tiver um) e insights próprios -- ver Migrations (setores,
// setor_responsaveis) e ClienteAuth (login separado pra cada setor). Cliente
// sem nenhum setor cadastrado (a maioria, hoje) não muda nada na aba
// Chamados -- os cards só aparecem pra quem tiver pelo menos 1 setor.
$setores = $pdo->prepare('
    SELECT
        s.*,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id) AS chamados_total,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status IN ("aberto","aguardando_equipe")) AS chamados_pendentes,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status IN ("aberto","aguardando_equipe") AND ch.prioridade IN ("alta","urgente")) AS chamados_urgentes,
        (SELECT COUNT(*) FROM chamados ch WHERE ch.setor_id = s.id AND ch.status = "fechado") AS chamados_fechados,
        (SELECT AVG(TIMESTAMPDIFF(MINUTE, ch.criado_em, ch.fechado_em)) FROM chamados ch WHERE ch.setor_id = s.id AND ch.fechado_em IS NOT NULL) AS tempo_medio_min
    FROM setores s
    WHERE s.cliente_id = ?
    ORDER BY s.nome ASC
');
$setores->execute([$clienteId]);
$setores = $setores->fetchAll();
$nomesSetoresPorId = array_column($setores, 'nome', 'id');

// Qual o ÚNICO setor do contato master (substitui o antigo master_participa,
// que permitia "participar" de vários ao mesmo tempo) -- editado na aba
// Usuários, ver ação 'definir_setor_master'.
$idSetorMaster = $cliente['setor_id'] ? (int) $cliente['setor_id'] : null;

// Responsáveis de cada setor, agrupados por setor_id -- um setor pode ter
// VÁRIOS (pedido do Kleber, ex.: Financeiro com mais de uma pessoa). Quando
// o setor É o setor do master, o contato master também entra na lista (id
// null pra diferenciar de uma linha real de setor_responsaveis -- ele não
// tem login separado, é o mesmo login master de sempre).
$responsaveisPorSetorId = [];
if ($setores) {
    $idsSetores = array_column($setores, 'id');
    $placeholdersSetores = implode(',', array_fill(0, count($idsSetores), '?'));
    $stmtResponsaveis = $pdo->prepare("SELECT * FROM setor_responsaveis WHERE setor_id IN ($placeholdersSetores) ORDER BY nome ASC");
    $stmtResponsaveis->execute($idsSetores);
    foreach ($stmtResponsaveis->fetchAll() as $sr) {
        $responsaveisPorSetorId[$sr['setor_id']][] = $sr;
    }
    foreach ($setores as $s) {
        if ($idSetorMaster === (int) $s['id']) {
            $responsaveisPorSetorId[$s['id']] ??= [];
            array_unshift($responsaveisPorSetorId[$s['id']], [
                'id' => null,
                'nome' => $cliente['contato_nome'] ?: $cliente['nome'],
                'email' => $cliente['contato_email'],
                'foto_perfil' => resolver_avatar_por_email($pdo, $cliente['contato_email'], $cliente['foto_perfil']),
                'portal_ativo' => 1,
                'eh_master' => true,
            ]);
        }
    }
}

// Quantos chamados desse cliente não têm setor vinculado (ou porque foram
// abertos antes dessa coluna existir, ou porque o cliente não usa setor pra
// tudo) -- mostrado como um card extra "Sem setor" quando existir pelo
// menos 1 setor cadastrado, senão esses chamados ficariam invisíveis nos
// cards.
$stmtSemSetor = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE cliente_id = ? AND setor_id IS NULL');
$stmtSemSetor->execute([$clienteId]);
$chamadosSemSetor = (int) $stmtSemSetor->fetchColumn();

$tituloPagina = $cliente['nome'];
require __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><?= e($cliente['nome']) ?></h4>
        <a href="<?= url('clientes.php') ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> Voltar para clientes</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="clienteTabs">
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#visao-geral">Visão geral</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#chamados">Chamados <span class="badge bg-secondary"><?= count($chamados) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#kpis">KPIs</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#documentos">Documentos <span class="badge bg-secondary"><?= count($documentos) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#treinamentos">Treinamentos <span class="badge bg-secondary"><?= count($treinamentos) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#usuarios">Usuários <span class="badge bg-secondary"><?= count($usuariosCliente) + 1 ?></span></a></li>
</ul>

<div class="tab-content">

    <!-- VISÃO GERAL -->
    <div class="tab-pane" id="visao-geral">
        <div class="card card-stat">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_cliente">
                    <input type="hidden" name="aba_retorno" value="visao-geral">
                    <div class="row g-3">
                        <div class="col-12 d-flex align-items-center gap-3">
                            <?php if (arquivo_publico_existe($cliente['logo'])): ?>
                                <img src="<?= e(asset_url($cliente['logo'])) ?>" alt="Logo" class="logo-preview-alterar">
                            <?php else: ?>
                                <div class="logo-preview-alterar logo-preview-vazio"><i class="bi bi-building"></i></div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <label class="form-label mb-1">Logo da empresa</label>
                                <input type="file" name="logo" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                                <small class="text-muted">PNG, JPG, WEBP ou SVG, até 5MB. A imagem é redimensionada automaticamente pro tamanho ideal do card.</small>
                                <button type="submit" class="btn btn-sm btn-outline-dark mt-2"><i class="bi bi-upload"></i> Enviar logo</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" value="<?= e($cliente['nome']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['ativo' => 'Ativo', 'pausado' => 'Pausado', 'encerrado' => 'Encerrado'] as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= $cliente['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cliente desde</label>
                            <input type="text" class="form-control" value="<?= formatar_data($cliente['criado_em']) ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - nome</label>
                            <input type="text" name="contato_nome" class="form-control" value="<?= e($cliente['contato_nome']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - e-mail</label>
                            <input type="email" name="contato_email" class="form-control" value="<?= e($cliente['contato_email']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contato - telefone</label>
                            <input type="text" name="contato_telefone" class="form-control input-telefone" value="<?= e(formatar_telefone($cliente['contato_telefone'])) ?>" placeholder="(00) 00000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CPF ou RG do contato *</label>
                            <input type="text" name="contato_documento" class="form-control" required value="<?= e($cliente['contato_documento']) ?>" placeholder="Só números (RG pode terminar em X)">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="4"><?= e($cliente['observacoes']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" name="acesso_fora_horario" id="acessoForaHorario" class="form-check-input" value="1" <?= $cliente['acesso_fora_horario'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="acessoForaHorario">
                                    Liberar acesso ao portal fora do horário comercial (<?= sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_INICIO) ?>–<?= sprintf('%02d:00', PORTAL_CLIENTE_HORARIO_FIM) ?>) pra esse cliente
                                </label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3">Salvar alterações</button>
                </form>
            </div>
        </div>

        <div class="card card-stat mt-3">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>Portal do cliente</strong>
                    <div class="text-muted small">
                        <?= $cliente['portal_ativo'] ? '<span class="badge bg-success">Acesso ativo</span>' : '<span class="badge bg-secondary">Sem acesso ainda</span>' ?>
                        — o cliente usa este e-mail e uma senha própria para acompanhar chamados.
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="enviar_convite_portal">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <button type="submit" class="btn btn-outline-dark btn-sm" <?= $cliente['contato_email'] ? '' : 'disabled' ?>>
                            <i class="bi bi-send"></i> <?= $cliente['portal_ativo'] ? 'Reenviar convite' : 'Enviar convite de acesso' ?>
                        </button>
                    </form>
                    <?php if ($cliente['portal_ativo']): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Isso invalida a senha atual na hora. Confirma o reset?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="resetar_senha_master">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <button type="submit" class="btn btn-outline-dark btn-sm"><i class="bi bi-key"></i> Resetar senha</button>
                    </form>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="desativar_portal_master">
                        <input type="hidden" name="aba_retorno" value="visao-geral">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Desativar acesso</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- CHAMADOS -->
    <div class="tab-pane" id="chamados">
        <p class="text-muted small mb-2"><i class="bi bi-headset"></i> Atendimento e suporte -- dúvidas, problemas e pedidos pontuais do cliente, com resposta direta em formato de conversa.</p>

        <?php if ($setores): ?>
        <div class="painel-setores mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <p class="text-muted small mb-0"><i class="bi bi-diagram-3"></i> Esse cliente tem setores independentes -- clique num card pra ver só os chamados daquele setor.</p>
                <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modalGerenciarSetores"><i class="bi bi-gear"></i> Gerenciar setores</button>
            </div>
            <div class="grid-setores">
                <div class="card-setor card-setor-todos active" data-setor-filtro="todos" role="button" tabindex="0">
                    <div class="card-setor-topo"><i class="bi bi-collection"></i> <span class="fw-semibold">Todos os setores</span></div>
                </div>
                <?php foreach ($setores as $s): ?>
                    <div class="card-setor <?= (int) $s['chamados_urgentes'] > 0 ? 'setor-atencao' : '' ?>" data-setor-filtro="<?= $s['id'] ?>" role="button" tabindex="0">
                        <div class="card-setor-topo">
                            <span class="fw-semibold"><?= e($s['nome']) ?></span>
                            <?php if (!$s['ativo']): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                        </div>
                        <div class="card-setor-responsavel">
                            <?php $membrosSetor = $responsaveisPorSetorId[$s['id']] ?? []; ?>
                            <?php if ($membrosSetor): ?>
                                <div class="card-setor-responsavel-avatares">
                                    <?php foreach (array_slice($membrosSetor, 0, 3) as $m): ?>
                                        <?= avatar_html($m['nome'], resolver_avatar_por_email($pdo, $m['email'], $m['foto_perfil']), 'cliente') ?>
                                    <?php endforeach; ?>
                                </div>
                                <span><?= e($membrosSetor[0]['nome']) ?><?= count($membrosSetor) > 1 ? ' e mais ' . (count($membrosSetor) - 1) : '' ?></span>
                            <?php else: ?>
                                <span class="text-muted small"><i class="bi bi-person-x"></i> Sem responsável cadastrado</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-setor-insights">
                            <span class="badge modulo-pill <?= (int) $s['chamados_pendentes'] > 0 ? 'badge-aguardando-equipe' : 'modulo-ok' ?>">
                                <?= (int) $s['chamados_pendentes'] ?> pendente<?= (int) $s['chamados_pendentes'] !== 1 ? 's' : '' ?>
                            </span>
                            <?php if ((int) $s['chamados_urgentes'] > 0): ?>
                                <span class="badge prio-urgente modulo-pill"><?= (int) $s['chamados_urgentes'] ?> urgente<?= (int) $s['chamados_urgentes'] !== 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                            <span class="badge modulo-pill modulo-neutro"><?= (int) $s['chamados_total'] ?> no total</span>
                            <span class="badge modulo-pill modulo-neutro" title="Tempo médio de atendimento"><i class="bi bi-clock-history"></i> <?= e(formatar_duracao_minutos($s['tempo_medio_min'] !== null ? (float) $s['tempo_medio_min'] : null)) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($chamadosSemSetor > 0): ?>
                    <div class="card-setor" data-setor-filtro="sem-setor" role="button" tabindex="0">
                        <div class="card-setor-topo"><span class="fw-semibold text-muted">Sem setor</span></div>
                        <div class="card-setor-insights"><span class="badge modulo-pill modulo-neutro"><?= $chamadosSemSetor ?> chamado<?= $chamadosSemSetor !== 1 ? 's' : '' ?></span></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="toolbar">
            <div class="toolbar-acoes">
                <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoChamado"><i class="bi bi-plus-lg"></i> Registrar chamado</button>
                <?php if (!$setores): ?>
                    <button type="button" class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modalGerenciarSetores"><i class="bi bi-diagram-3"></i> Configurar setores</button>
                <?php endif; ?>
            </div>
            <div class="toolbar-filtros">
                <div class="btn-group flex-wrap" role="group" id="filtroStatusChamados">
                    <button type="button" class="btn btn-sm filtro-pill filtro-pendentes active" data-filtro="pendentes"><i class="bi bi-exclamation-diamond"></i> Pendentes</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-todos" data-filtro="todos"><i class="bi bi-collection"></i> Todos</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-aberto" data-filtro="aberto"><i class="bi bi-envelope-open"></i> Abertos</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-aguardando_equipe" data-filtro="aguardando_equipe"><i class="bi bi-exclamation-circle-fill"></i> Aguardando nós</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-aguardando_cliente" data-filtro="aguardando_cliente"><i class="bi bi-hourglass-split"></i> Aguardando cliente</button>
                    <button type="button" class="btn btn-sm filtro-pill filtro-fechado" data-filtro="fechado"><i class="bi bi-x-circle"></i> Fechados</button>
                </div>
                <button type="button" class="btn btn-sm btn-exportar" data-bs-toggle="modal" data-bs-target="#modalExportarChamados">
                    <i class="bi bi-file-earmark-excel"></i> Exportar
                </button>
            </div>
        </div>

        <div id="listaChamados">
        <?php foreach ($chamados as $c): ?>
        <div class="card chamado-card mb-3" data-status="<?= e($c['status']) ?>" data-prioridade="<?= e($c['prioridade']) ?>" data-setor="<?= $c['setor_id'] ? (int) $c['setor_id'] : 'sem-setor' ?>">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <strong class="d-block mb-1"><?= e($c['titulo']) ?></strong>
                        <div class="d-flex align-items-center flex-wrap gap-3">
                            <?php if ($setores): ?>
                            <span class="d-flex align-items-center gap-1">
                                <span class="badge-rotulo">Setor</span>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="atribuir_setor_chamado">
                                    <input type="hidden" name="aba_retorno" value="chamados">
                                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                                    <select name="setor_id" class="form-select form-select-sm select-responsavel-chamado" onchange="this.form.submit()" title="Trocar o setor avisa o responsável dele por e-mail -- setor sem responsável cadastrado fica bloqueado aqui">
                                        <option value="" <?= !$c['setor_id'] ? 'selected' : '' ?>>Sem setor</option>
                                        <?php foreach ($setores as $s): $temResponsavel = $idSetorMaster === (int) $s['id'] || !empty($responsaveisPorSetorId[$s['id']]); ?>
                                            <option value="<?= $s['id'] ?>" <?= (int) $c['setor_id'] === (int) $s['id'] ? 'selected' : '' ?> <?= !$temResponsavel ? 'disabled' : '' ?>><?= e($s['nome']) ?><?= !$temResponsavel ? ' (sem responsável)' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </span>
                            <?php endif; ?>
                            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Status</span> <?= badge_status_chamado($c['status']) ?></span>
                            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Prioridade</span> <?= badge_prioridade($c['prioridade']) ?></span>
                            <span class="d-flex align-items-center gap-1"><span class="badge-rotulo">Origem</span> <?= $c['aberto_por'] === 'cliente'
                                ? '<span class="badge badge-origem-cliente"><i class="bi bi-person-fill"></i> Cliente</span>'
                                : '<span class="badge badge-origem-equipe"><i class="bi bi-headset"></i> Equipe</span>' ?></span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="badge-rotulo">Responsável</span>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="atribuir_responsavel_chamado">
                                    <input type="hidden" name="aba_retorno" value="chamados">
                                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                                    <select name="responsavel_id" class="form-select form-select-sm select-responsavel-chamado" onchange="this.form.submit()" title="Trocar o responsável avisa a pessoa por e-mail">
                                        <option value="">Sem responsável</option>
                                        <?php foreach ($usuarios as $u): ?>
                                            <option value="<?= $u['id'] ?>" <?= (int) $c['responsavel_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </span>
                        </div>
                    </div>
                    <!-- O status "no meio" (aguardando nós / aguardando cliente) é sempre
                         automático, calculado por quem respondeu por último -- a única
                         ação manual que sobra é encerrar ou reabrir o chamado. -->
                    <div class="chamado-acoes">
                        <a href="<?= url('imprimir_chamado.php?id=' . $c['id']) ?>" target="_blank" class="btn btn-sm btn-imprimir"><i class="bi bi-printer"></i> Imprimir</a>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="<?= $c['status'] === 'fechado' ? 'reabrir_chamado' : 'fechar_chamado' ?>">
                            <input type="hidden" name="aba_retorno" value="chamados">
                            <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                            <?php if ($c['status'] === 'fechado'): ?>
                                <button type="submit" class="btn btn-reabrir"><i class="bi bi-arrow-counterclockwise"></i> Reabrir</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-encerrar"><i class="bi bi-check2-circle"></i> Encerrar</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-1"><i class="bi bi-clock"></i> Aberto em <?= formatar_data($c['criado_em']) ?><?= $c['categoria'] ? ' · ' . e($c['categoria']) : '' ?></p>

                <?= render_resumo_anexos_chamado($anexosPorChamado[$c['id']] ?? []) ?>

                <!-- Toda a conversa (mensagem de abertura + respostas) já vem normalizada
                     em $linhasChatPorChamado -- cliente sempre à esquerda e em verde,
                     equipe (nós) sempre à direita e em azul/roxo, com nome do contato,
                     avatar e anexos em todas as linhas, inclusive a de abertura. -->
                <div class="chat-thread">
                <?php foreach ($linhasChatPorChamado[$c['id']] ?? [] as $linha): ?>
                    <?php $ladoDireita = $linha['autor_tipo'] === 'equipe'; ?>
                    <div class="chat-linha <?= $ladoDireita ? 'chat-direita' : 'chat-esquerda' ?>">
                        <?= avatar_html($linha['autor_nome'], $linha['foto'], $linha['autor_tipo']) ?>
                        <div class="chat-bolha bolha-<?= e($linha['autor_tipo']) ?>">
                            <span class="chat-autor"><?= e($linha['autor_nome']) ?></span>
                            <span style="white-space: pre-wrap;"><?= e($linha['mensagem']) ?></span>
                            <?= render_anexos_html($linha['anexos']) ?>
                            <span class="chat-hora"><?= date('d/m/Y H:i', strtotime($linha['criado_em'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <form method="post" enctype="multipart/form-data" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="responder_chamado">
                    <input type="hidden" name="aba_retorno" value="chamados">
                    <input type="hidden" name="chamado_id" value="<?= $c['id'] ?>">
                    <textarea name="mensagem" class="form-control form-control-sm mb-2" rows="2" placeholder="Responder ao cliente..."></textarea>
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <input type="file" name="anexos[]" class="form-control form-control-sm" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" style="max-width: 320px;">
                        <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-send"></i> Enviar resposta</button>
                    </div>
                    <p class="anexos-upload-preview mb-0">Até <?= ANEXO_CHAMADO_MAX_QUANTIDADE ?> arquivos, <?= (int) (ANEXO_CHAMADO_MAX_SIZE / 1024 / 1024) ?>MB cada (imagem ou PDF).</p>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php if (empty($chamados)): ?>
            <p class="text-muted text-center py-4">Nenhum chamado registrado ainda.</p>
        <?php endif; ?>
        <p class="text-muted text-center py-3 d-none" id="avisoFiltroVazio">Nenhum chamado com esse status.</p>
    </div>

    <!-- KPIS -->
    <div class="tab-pane" id="kpis">
        <div class="toolbar">
            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoKpi"><i class="bi bi-plus-lg"></i> Registrar KPI</button>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Período</th><th>KPI</th><th>Valor</th><th>Meta</th><th>Observações</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kpis as $k): ?>
                        <tr>
                            <td><?= date('m/Y', strtotime($k['periodo'])) ?></td>
                            <td class="fw-semibold"><?= e($k['nome_kpi']) ?></td>
                            <td><?= e(number_format((float)$k['valor'], 2, ',', '.')) ?> <?= e($k['unidade']) ?></td>
                            <td>
                                <?php if ($k['meta'] !== null): ?>
                                    <?= e(number_format((float)$k['meta'], 2, ',', '.')) ?>
                                    <?= (float)$k['valor'] >= (float)$k['meta'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle-fill text-warning"></i>' ?>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= e($k['observacoes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($kpis)): ?>
                        <tr><td colspan="5" class="text-muted text-center py-3">Nenhum KPI registrado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- DOCUMENTOS -->
    <div class="tab-pane" id="documentos">
        <div class="toolbar">
            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoDocumento"><i class="bi bi-upload"></i> Enviar documento</button>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Título</th><th>Categoria</th><th>Arquivo</th><th>Enviado por</th><th>Data</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($documentos as $doc): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($doc['titulo']) ?></td>
                            <td><?= e($doc['categoria'] ?: '-') ?></td>
                            <td><?= e($doc['arquivo_nome_original']) ?> <small class="text-muted">(<?= round($doc['arquivo_tamanho']/1024) ?> KB)</small></td>
                            <td><?= e($doc['criado_por_nome'] ?: '-') ?></td>
                            <td><?= formatar_data($doc['criado_em']) ?></td>
                            <td class="text-end">
                                <a href="<?= url('download.php?id=' . $doc['id']) ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-download"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($documentos)): ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">Nenhum documento enviado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- TREINAMENTOS -->
    <div class="tab-pane" id="treinamentos">
        <div class="toolbar">
            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoTreinamento"><i class="bi bi-plus-lg"></i> Novo treinamento/reunião</button>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th>Título</th><th>Tipo</th><th>Setor</th><th>Status</th><th>Quando</th><th>Responsável</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($treinamentos as $t): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($t['titulo']) ?></td>
                            <td><?= $t['tipo'] === 'reuniao' ? '<span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-camera-video"></i> Reunião</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="bi bi-mortarboard"></i> Treinamento</span>' ?></td>
                            <td><?= $t['setor_nome'] ? e($t['setor_nome']) : '<span class="text-muted">Geral</span>' ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="atualizar_treinamento_status">
                                    <input type="hidden" name="aba_retorno" value="treinamentos">
                                    <input type="hidden" name="treinamento_id" value="<?= $t['id'] ?>">
                                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <?php foreach (['planejado' => 'Planejado', 'em_andamento' => 'Em andamento', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'] as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= $t['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td class="small">
                                <?= formatar_data($t['data_inicio']) ?><?= $t['hora_inicio'] ? ' ' . substr($t['hora_inicio'], 0, 5) : '' ?>
                                <?php if ($t['data_fim'] && $t['data_fim'] !== $t['data_inicio']): ?><br>até <?= formatar_data($t['data_fim']) ?><?php endif; ?>
                            </td>
                            <td><?= e($t['responsavel_nome'] ?: '-') ?></td>
                            <td class="text-end">
                                <?php if ($t['link_reuniao']): ?>
                                    <a href="<?= e($t['link_reuniao']) ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="bi bi-camera-video"></i> Entrar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($treinamentos)): ?>
                        <tr><td colspan="7" class="text-muted text-center py-3">Nenhum treinamento ou reunião registrado ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- USUÁRIOS -->
    <div class="tab-pane" id="usuarios">
        <p class="text-muted small mb-2"><i class="bi bi-people"></i> Todo mundo que representa <?= e($cliente['nome']) ?> no sistema -- o master vê tudo, cada usuário relacionado a um setor só vê/abre chamado do próprio setor.</p>
        <div class="toolbar">
            <div class="toolbar-acoes">
                <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalNovoUsuarioCliente"><i class="bi bi-plus-lg"></i> Novo usuário</button>
            </div>
            <a class="btn btn-sm btn-exportar" href="<?= url('exportar_usuarios_cliente.php?id=' . $clienteId) ?>" target="_blank"><i class="bi bi-file-earmark-excel"></i> Exportar</a>
        </div>
        <div class="card card-stat">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th></th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Setor</th><th>Portal</th><th></th></tr></thead>
                    <tbody>
                        <tr>
                            <td><?= avatar_html($cliente['contato_nome'] ?: $cliente['nome'], resolver_avatar_por_email($pdo, $cliente['contato_email'], $cliente['foto_perfil']), 'cliente') ?></td>
                            <td class="fw-semibold"><?= e($cliente['contato_nome'] ?: $cliente['nome']) ?> <span class="badge bg-dark">Master</span></td>
                            <td><?= $cliente['contato_email'] ? e($cliente['contato_email']) : '<span class="text-muted">— sem e-mail —</span>' ?></td>
                            <td><?= e(formatar_telefone($cliente['contato_telefone']) ?: '-') ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="definir_setor_master">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <select name="setor_id" class="form-select form-select-sm" onchange="this.form.submit()" <?= $setores ? '' : 'disabled title="Cadastre um setor primeiro"' ?>>
                                        <option value="">Nenhum setor</option>
                                        <?php foreach ($setores as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= $idSetorMaster === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><span class="badge bg-<?= $cliente['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $cliente['portal_ativo'] ? 'Ativo' : 'Sem acesso' ?></span></td>
                            <td class="text-muted small">Editar em "Visão geral"</td>
                        </tr>
                        <?php foreach ($usuariosCliente as $uc): ?>
                        <tr class="linha-usuario-cliente" role="button" tabindex="0" data-usuario='<?= e(json_encode($uc, JSON_UNESCAPED_UNICODE)) ?>'>
                            <td><?= avatar_html($uc['nome'], resolver_avatar_por_email($pdo, $uc['email'], $uc['foto_perfil']), 'cliente') ?></td>
                            <td class="fw-semibold"><?= e($uc['nome']) ?></td>
                            <td><?= e($uc['email']) ?></td>
                            <td><?= e(formatar_telefone($uc['telefone']) ?: '-') ?></td>
                            <td onclick="event.stopPropagation()">
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="relacionar_responsavel_setor">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                    <select name="setor_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="">Sem setor</option>
                                        <?php foreach ($setores as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= (int) $uc['setor_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td><span class="badge bg-<?= $uc['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $uc['portal_ativo'] ? 'Ativo' : 'Sem acesso' ?></span></td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="enviar_convite_setor">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-dark" title="<?= $uc['setor_id'] ? '' : 'Relacione a um setor primeiro' ?>" <?= $uc['setor_id'] ? '' : 'disabled' ?>><i class="bi bi-send"></i></button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remover <?= e($uc['nome']) ?>? Isso também apaga o acesso dele ao portal, se tiver.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="acao" value="excluir_usuario_cliente">
                                    <input type="hidden" name="aba_retorno" value="usuarios">
                                    <input type="hidden" name="responsavel_id" value="<?= $uc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($usuariosCliente)): ?>
                            <tr><td colspan="7" class="text-muted text-center py-3">Nenhum outro usuário cadastrado ainda -- só o master.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p class="text-muted small px-3 py-2 mb-0">Clique numa linha pra editar dados, avatar, convite ou senha.</p>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Modal Novo Chamado (registrado pela equipe) -->
<div class="modal fade" id="modalNovoChamado" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_chamado_equipe">
        <input type="hidden" name="aba_retorno" value="chamados">
        <div class="modal-header"><h5 class="modal-title">Registrar chamado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" style="text-transform:uppercase" required></div>
            <div class="mb-3"><label class="form-label">Descrição *</label><textarea name="descricao" class="form-control" rows="3" required></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Categoria *</label>
                    <select name="categoria" class="form-select" required>
                        <option value="" disabled selected>Selecione</option>
                        <?php foreach (CATEGORIAS_CHAMADO as $cat): ?>
                            <option><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Prioridade *</label>
                    <select name="prioridade" class="form-select" required>
                        <?= opcoes_prioridade_html('media') ?>
                    </select>
                </div>
                <div class="<?= $setores ? 'col-6' : 'col-12' ?>"><label class="form-label">Responsável</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($setores): ?>
                <div class="col-6"><label class="form-label">Setor</label>
                    <select name="setor_id" class="form-select">
                        <option value="">Sem setor</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Registrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Gerenciar setores -->
<div class="modal fade" id="modalGerenciarSetores" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Setores de <?= e($cliente['nome']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Cada setor pode ter um ou mais responsáveis com login próprio no portal -- eles só veem e abrem chamados do setor deles. Cadastre a pessoa na aba <strong>Usuários</strong> primeiro, depois relacione ela a um setor aqui.</p>

        <form method="post" class="row g-2 align-items-end mb-2">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="criar_setor">
            <input type="hidden" name="aba_retorno" value="chamados">
            <input type="hidden" name="icone" id="novoSetorIcone" value="bi-diagram-3">
            <div class="col"><input type="text" name="nome" id="novoSetorNome" class="form-control" placeholder="Nome do novo setor" required></div>
            <div class="col-auto"><button type="submit" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Criar setor</button></div>
        </form>
        <div class="seletor-icone-setor mb-2" data-campo-destino="novoSetorIcone">
            <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                <button type="button" class="icone-opcao <?= $ic === 'bi-diagram-3' ? 'active' : '' ?>" data-icone="<?= $ic ?>" title="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></button>
            <?php endforeach; ?>
        </div>

        <?php
        $nomesSetoresExistentesLower = array_map('mb_strtolower', array_column($setores, 'nome'));
        $sugestoesSetorDisponiveis = array_filter(SETORES_SUGERIDOS, fn ($nomeSug) => !in_array(mb_strtolower($nomeSug), $nomesSetoresExistentesLower, true), ARRAY_FILTER_USE_KEY);
        ?>
        <?php if ($sugestoesSetorDisponiveis): ?>
        <div class="mb-4">
            <p class="text-muted small mb-1">Sugestões rápidas (clique pra preencher nome + ícone):</p>
            <?php foreach ($sugestoesSetorDisponiveis as $nomeSug => $iconeSug): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-1 botao-sugestao-setor" data-nome="<?= e($nomeSug) ?>" data-icone="<?= e($iconeSug) ?>"><i class="bi <?= e($iconeSug) ?>"></i> <?= e($nomeSug) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$setores): ?>
            <p class="text-muted text-center py-4 mb-0"><i class="bi bi-diagram-3" style="font-size:2rem;"></i><br>Nenhum setor cadastrado ainda.</p>
        <?php endif; ?>

        <?php
        // Pra "buscar e relacionar": pessoas dessa empresa que ainda NÃO
        // estão nesse setor específico (podem estar sem setor nenhum, ou
        // relacionadas a outro -- mudar de setor também é uma forma válida
        // de "relacionar").
        ?>
        <?php foreach ($setores as $indiceSetor => $s): $membrosReais = $responsaveisPorSetorId[$s['id']] ?? []; $membrosReais = array_filter($membrosReais, fn ($m) => empty($m['eh_master'])); $treinamentosSetor = $treinamentosPorSetorId[$s['id']] ?? []; ?>
        <details class="setor-detalhe" <?= $indiceSetor === 0 ? 'open' : '' ?>>
            <summary>
                <i class="bi <?= e($s['icone'] ?: 'bi-diagram-3') ?>"></i>
                <span class="fw-semibold"><?= e($s['nome']) ?></span>
                <?php if (!$s['ativo']): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                <span class="text-muted small">
                    — <?= count($membrosReais) ?> <?= count($membrosReais) === 1 ? 'responsável' : 'responsáveis' ?><?= $idSetorMaster === (int) $s['id'] ? ' + master' : '' ?>
                </span>
            </summary>
            <div class="setor-detalhe-corpo">
                <form method="post" class="row g-2 align-items-end mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="atualizar_setor">
                    <input type="hidden" name="aba_retorno" value="chamados">
                    <input type="hidden" name="setor_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="icone" id="setorIcone<?= $s['id'] ?>" value="<?= e($s['icone'] ?: 'bi-diagram-3') ?>">
                    <div class="col-md-6"><label class="form-label">Nome do setor</label><input type="text" name="nome" class="form-control" value="<?= e($s['nome']) ?>" required></div>
                    <div class="col-md-3 form-check ms-2 mb-2">
                        <input type="checkbox" name="ativo" id="setorAtivo<?= $s['id'] ?>" class="form-check-input" value="1" <?= $s['ativo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="setorAtivo<?= $s['id'] ?>">Ativo</label>
                    </div>
                    <div class="col-md-3"><button type="submit" class="btn btn-sm btn-outline-dark w-100"><i class="bi bi-check2"></i> Salvar</button></div>
                </form>
                <div class="seletor-icone-setor mb-3" data-campo-destino="setorIcone<?= $s['id'] ?>">
                    <?php foreach (ICONES_SETOR_DISPONIVEIS as $ic): ?>
                        <button type="button" class="icone-opcao <?= $ic === ($s['icone'] ?: 'bi-diagram-3') ? 'active' : '' ?>" data-icone="<?= $ic ?>" title="<?= $ic ?>"><i class="bi <?= $ic ?>"></i></button>
                    <?php endforeach; ?>
                </div>

                <hr>
                <p class="text-muted small mb-2"><i class="bi bi-people"></i> Responsáveis (cada um com login próprio no portal, só desse setor)</p>

                <?php if (!$membrosReais): ?>
                    <p class="text-muted small">Nenhum responsável relacionado ainda<?= $idSetorMaster === (int) $s['id'] ? ' (o master já cobre esse setor, mas fica à vontade pra relacionar mais alguém)' : '' ?>.</p>
                <?php endif; ?>

                <?php foreach ($membrosReais as $m): ?>
                <div class="responsavel-setor-item d-flex align-items-center gap-3 mb-2">
                    <?= avatar_html($m['nome'], resolver_avatar_por_email($pdo, $m['email'], $m['foto_perfil']), 'cliente') ?>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= e($m['nome']) ?></div>
                        <div class="text-muted small"><?= e($m['email']) ?></div>
                    </div>
                    <span class="badge bg-<?= $m['portal_ativo'] ? 'success' : 'secondary' ?>"><?= $m['portal_ativo'] ? 'Portal ativo' : 'Sem acesso' ?></span>
                    <div class="d-flex flex-wrap gap-1">
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="enviar_convite_setor">
                            <input type="hidden" name="aba_retorno" value="chamados">
                            <input type="hidden" name="responsavel_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-dark" title="<?= $m['portal_ativo'] ? 'Reenviar convite' : 'Enviar convite' ?>"><i class="bi bi-send"></i></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Desvincular <?= e($m['nome']) ?> desse setor? A pessoa continua cadastrada na aba Usuários.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="relacionar_responsavel_setor">
                            <input type="hidden" name="aba_retorno" value="chamados">
                            <input type="hidden" name="responsavel_id" value="<?= $m['id'] ?>">
                            <input type="hidden" name="setor_id" value="">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Desvincular desse setor"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php
                $candidatos = array_filter($usuariosCliente, fn ($u) => (int) $u['setor_id'] !== (int) $s['id']);
                ?>
                <?php if ($candidatos): ?>
                <form method="post" class="row g-2 align-items-end mt-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="relacionar_responsavel_setor">
                    <input type="hidden" name="aba_retorno" value="chamados">
                    <input type="hidden" name="setor_id" value="<?= $s['id'] ?>">
                    <div class="col">
                        <label class="form-label small mb-1"><i class="bi bi-search"></i> Buscar usuário já cadastrado no cliente e relacionar a esse setor:</label>
                        <select name="responsavel_id" class="form-select form-select-sm" required>
                            <option value="" disabled selected>Selecione...</option>
                            <?php foreach ($candidatos as $cand): ?>
                                <option value="<?= $cand['id'] ?>"><?= e($cand['nome']) ?> (<?= e($cand['email']) ?>)<?= $cand['setor_id'] ? ' — hoje em ' . e($nomesSetoresPorId[$cand['setor_id']] ?? '?') : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto"><button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-link-45deg"></i> Relacionar</button></div>
                </form>
                <?php else: ?>
                    <p class="text-muted small">Nenhum outro usuário do cliente disponível pra relacionar -- cadastre um na aba <strong>Usuários</strong>.</p>
                <?php endif; ?>

                <hr>
                <p class="text-muted small mb-2"><i class="bi bi-calendar-event"></i> Treinamentos e reuniões desse setor</p>
                <?php if ($treinamentosSetor): ?>
                    <ul class="list-unstyled mb-3">
                        <?php foreach ($treinamentosSetor as $ts): ?>
                            <li class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi <?= $ts['tipo'] === 'reuniao' ? 'bi-camera-video' : 'bi-mortarboard' ?>"></i>
                                <span class="fw-semibold"><?= e($ts['titulo']) ?></span>
                                <span class="text-muted small"><?= formatar_data($ts['data_inicio']) ?><?= $ts['hora_inicio'] ? ' ' . substr($ts['hora_inicio'], 0, 5) : '' ?></span>
                                <?php if ($ts['link_reuniao']): ?><a href="<?= e($ts['link_reuniao']) ?>" target="_blank" class="btn btn-sm btn-outline-dark ms-auto">Entrar</a><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted small">Nenhum treinamento ou reunião desse setor ainda -- crie na aba <strong>Treinamentos</strong>.</p>
                <?php endif; ?>

                <hr>
                <form method="post" onsubmit="return confirm('Excluir o setor &quot;<?= e($s['nome']) ?>&quot;? Os chamados e responsáveis dele continuam salvos, só ficam sem setor vinculado.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="excluir_setor">
                    <input type="hidden" name="aba_retorno" value="chamados">
                    <input type="hidden" name="setor_id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir setor</button>
                </form>
            </div>
        </details>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Exportar Chamados -->
<div class="modal fade" id="modalExportarChamados" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="get" action="<?= url('exportar_chamados.php') ?>" target="_blank">
        <input type="hidden" name="id" value="<?= $clienteId ?>">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-excel"></i> Exportar chamados</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="exportarStatus" class="form-select">
                    <option value="todos">Todos</option>
                    <option value="aberto">Aberto</option>
                    <option value="aguardando_equipe">Aguardando nós</option>
                    <option value="aguardando_cliente">Aguardando cliente</option>
                    <option value="fechado">Fechado</option>
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">De *</label><input type="date" name="data_de" class="form-control" required></div>
                <div class="col-6"><label class="form-label">Até *</label><input type="date" name="data_ate" class="form-control" required></div>
            </div>
            <p class="text-muted small mt-2 mb-0">O arquivo abre direto no Excel.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-exportar"><i class="bi bi-download"></i> Exportar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Novo KPI -->
<div class="modal fade" id="modalNovoKpi" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_kpi">
        <input type="hidden" name="aba_retorno" value="kpis">
        <div class="modal-header"><h5 class="modal-title">Registrar KPI</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome do KPI *</label><input type="text" name="nome_kpi" class="form-control" required placeholder="Ex: SLA de atendimento, chamados resolvidos..."></div>
            <div class="row g-2">
                <div class="col-4"><label class="form-label">Valor *</label><input type="text" name="valor" class="form-control" required placeholder="Ex: 95,5"></div>
                <div class="col-4"><label class="form-label">Meta</label><input type="text" name="meta" class="form-control" placeholder="Ex: 98"></div>
                <div class="col-4"><label class="form-label">Unidade</label><input type="text" name="unidade" class="form-control" placeholder="%, dias, un."></div>
            </div>
            <div class="mb-3 mt-2"><label class="form-label">Período (mês/ano) *</label><input type="month" name="periodo" class="form-control" required value="<?= date('Y-m') ?>"></div>
            <div class="mb-3"><label class="form-label">Observações</label><input type="text" name="observacoes" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Registrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Novo Documento -->
<div class="modal fade" id="modalNovoDocumento" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="upload_documento">
        <input type="hidden" name="aba_retorno" value="documentos">
        <div class="modal-header"><h5 class="modal-title">Enviar documento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título</label><input type="text" name="titulo" class="form-control" placeholder="Se deixar vazio, usa o nome do arquivo"></div>
            <div class="mb-3"><label class="form-label">Categoria</label><input type="text" name="categoria" class="form-control" placeholder="Ex: Contrato, Manual, Ata de reunião..."></div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
            <div class="mb-3"><label class="form-label">Arquivo * (máx. 15MB)</label><input type="file" name="arquivo" class="form-control" required></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Enviar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Novo Treinamento -->
<div class="modal fade" id="modalNovoTreinamento" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_treinamento">
        <input type="hidden" name="aba_retorno" value="treinamentos">
        <div class="modal-header"><h5 class="modal-title">Novo treinamento/reunião</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
            <div class="row g-2 mb-1">
                <div class="col-6"><label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value="treinamento">Treinamento</option>
                        <option value="reuniao">Reunião</option>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">Setor</label>
                    <select name="setor_id" class="form-select">
                        <option value="">Geral (todo o cliente)</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Data início</label><input type="date" name="data_inicio" class="form-control"></div>
                <div class="col-6"><label class="form-label">Hora início</label><input type="time" name="hora_inicio" class="form-control"></div>
                <div class="col-6"><label class="form-label">Data fim</label><input type="date" name="data_fim" class="form-control"></div>
                <div class="col-6"><label class="form-label">Hora fim</label><input type="time" name="hora_fim" class="form-control"></div>
                <div class="col-12"><label class="form-label">Link da videochamada</label><input type="url" name="link_reuniao" class="form-control" placeholder="Meet, Teams, Zoom..."></div>
                <div class="col-12"><label class="form-label">Responsável (equipe)</label>
                    <select name="responsavel_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Criar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Novo usuário do cliente -->
<div class="modal fade" id="modalNovoUsuarioCliente" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar_usuario_cliente">
        <input type="hidden" name="aba_retorno" value="usuarios">
        <div class="modal-header"><h5 class="modal-title">Novo usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">E-mail *</label><input type="email" name="email" class="form-control" required></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control input-telefone" placeholder="(00) 00000-0000"></div>
                <div class="col-6"><label class="form-label">CPF</label><input type="text" name="documento" class="form-control" placeholder="Só números"></div>
                <div class="col-12"><label class="form-label">Setor</label>
                    <select name="setor_id" class="form-select">
                        <option value="">Sem setor por enquanto</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-dark">Cadastrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Usuário do cliente: editar dados, avatar, convite/senha do portal -->
<div class="modal fade" id="modalUsuarioCliente" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalUsuarioClienteTitulo">Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-center mb-3">
            <img id="usuarioClienteAvatarPreview" class="avatar-circle avatar-foto cliente" style="width:80px;height:80px;font-size:2rem;" alt="Avatar">
        </div>

        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-2">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="atualizar_avatar_setor_responsavel">
            <input type="hidden" name="responsavel_id" id="avatarUsuarioClienteId">
            <input type="hidden" name="aba_retorno" value="usuarios">
            <div class="col"><input type="file" name="foto" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required></div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-dark"><i class="bi bi-upload"></i> Enviar foto</button></div>
        </form>

        <p class="text-muted small mb-1">Ou escolha um avatar pronto:</p>
        <div class="galeria-avatares mb-4">
            <?php for ($i = 1; $i <= QUANTIDADE_AVATARES_PREDEFINIDOS; $i++): ?>
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="escolher_avatar_predefinido_setor_responsavel">
                    <input type="hidden" name="responsavel_id" class="avatar-usuario-cliente-id-campo">
                    <input type="hidden" name="aba_retorno" value="usuarios">
                    <input type="hidden" name="avatar" value="<?= $i ?>">
                    <button type="submit" class="avatar-opcao" title="Usar este avatar">
                        <img src="<?= asset_url('assets/img/avatares/avatar-' . $i . '.svg') ?>" alt="Avatar <?= $i ?>">
                    </button>
                </form>
            <?php endfor; ?>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="editar_usuario_cliente">
            <input type="hidden" name="aba_retorno" value="usuarios">
            <input type="hidden" name="responsavel_id" id="editarUsuarioClienteId">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nome *</label><input type="text" name="nome" id="editarUsuarioClienteNome" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">E-mail *</label><input type="email" name="email" id="editarUsuarioClienteEmail" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Telefone</label><input type="text" name="telefone" id="editarUsuarioClienteTelefone" class="form-control input-telefone" placeholder="(00) 00000-0000"></div>
                <div class="col-md-6"><label class="form-label">CPF</label><input type="text" name="documento" id="editarUsuarioClienteDocumento" class="form-control" placeholder="Só números"></div>
                <div class="col-md-6"><label class="form-label">Setor</label>
                    <select name="setor_id" id="editarUsuarioClienteSetor" class="form-select">
                        <option value="">Sem setor</option>
                        <?php foreach ($setores as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-dark mt-3"><i class="bi bi-check2"></i> Salvar dados</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script>
// Ativa a aba certa com base no hash da URL (ex: #chamados) ou abre "Visão geral" por padrão.
document.addEventListener('DOMContentLoaded', function () {
    var hash = window.location.hash || '#visao-geral';
    var tabLink = document.querySelector('.nav-tabs a[href="' + hash + '"]');
    var tabPane = document.querySelector(hash);
    if (tabLink && tabPane) {
        PrimeUI.showTab(tabLink);
    } else {
        var first = document.querySelector('.nav-tabs a');
        if (first) PrimeUI.showTab(first);
    }
    document.querySelectorAll('.nav-tabs a').forEach(function (link) {
        link.addEventListener('shown.bs.tab', function (e) {
            history.replaceState(null, '', e.target.getAttribute('href'));
        });
    });

    // Filtro de chamados por status + por setor (client-side, sem
    // recarregar a página) -- os dois combinam (ex.: "Pendentes" do setor
    // "Financeiro" ao mesmo tempo).
    var grupoFiltro = document.getElementById('filtroStatusChamados');
    var filtroAtual = 'pendentes';
    var filtroSetorAtual = 'todos';
    function aplicarFiltroChamados() {
        var algumVisivel = false;
        document.querySelectorAll('#listaChamados .chamado-card').forEach(function (card) {
            var status = card.getAttribute('data-status');
            // "Pendentes" = pendência NOSSA (aberto ou aguardando_equipe) --
            // mesma definição usada no Dashboard e nos cards de Clientes, pra
            // não mostrar número diferente em cada tela pro mesmo cliente.
            // "aguardando_cliente" (já respondemos) tem o próprio filtro à
            // parte, não conta como pendência aqui.
            var mostraStatus = filtroAtual === 'todos' || (filtroAtual === 'pendentes' ? (status === 'aberto' || status === 'aguardando_equipe') : status === filtroAtual);
            var mostraSetor = filtroSetorAtual === 'todos' || card.getAttribute('data-setor') === filtroSetorAtual;
            var mostra = mostraStatus && mostraSetor;
            card.style.display = mostra ? '' : 'none';
            if (mostra) algumVisivel = true;
        });
        var aviso = document.getElementById('avisoFiltroVazio');
        if (aviso) aviso.classList.toggle('d-none', algumVisivel || document.querySelectorAll('#listaChamados .chamado-card').length === 0);
    }
    if (grupoFiltro) {
        aplicarFiltroChamados();
        grupoFiltro.querySelectorAll('.filtro-pill').forEach(function (botao) {
            botao.addEventListener('click', function () {
                grupoFiltro.querySelectorAll('.filtro-pill').forEach(function (b) { b.classList.remove('active'); });
                botao.classList.add('active');
                filtroAtual = botao.getAttribute('data-filtro');
                aplicarFiltroChamados();
            });
        });
        // Ao abrir o modal de exportar, já vem com o status do filtro ativo
        // selecionado (mas o usuário pode trocar antes de exportar).
        var modalExportar = document.getElementById('modalExportarChamados');
        if (modalExportar) {
            modalExportar.addEventListener('show.bs.modal', function () {
                var select = document.getElementById('exportarStatus');
                if (select) select.value = filtroAtual;
            });
        }
    }

    document.querySelectorAll('.card-setor').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.card-setor').forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            filtroSetorAtual = card.getAttribute('data-setor-filtro');
            aplicarFiltroChamados();
            document.getElementById('listaChamados').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        card.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') card.click();
        });
    });

    // Sugestões rápidas de nome+ícone de setor (modal Gerenciar setores) --
    // só preenche o campo, quem decide se cria é o próprio botão "Criar setor".
    document.querySelectorAll('.botao-sugestao-setor').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = document.getElementById('novoSetorNome');
            if (campo) {
                campo.value = botao.getAttribute('data-nome');
                campo.focus();
            }
            selecionarIcone(document.querySelector('.seletor-icone-setor[data-campo-destino="novoSetorIcone"]'), botao.getAttribute('data-icone'));
        });
    });

    // Seletor de ícone de setor -- clicar num ícone marca ele como escolhido
    // (visualmente) e preenche o input escondido que vai no form de
    // criar/atualizar setor (mesma ideia da galeria de avatares).
    function selecionarIcone(grupo, icone) {
        if (!grupo) return;
        var campo = document.getElementById(grupo.getAttribute('data-campo-destino'));
        if (campo) campo.value = icone;
        grupo.querySelectorAll('.icone-opcao').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-icone') === icone);
        });
    }
    document.querySelectorAll('.seletor-icone-setor').forEach(function (grupo) {
        grupo.querySelectorAll('.icone-opcao').forEach(function (botaoIcone) {
            botaoIcone.addEventListener('click', function () {
                selecionarIcone(grupo, botaoIcone.getAttribute('data-icone'));
            });
        });
    });

    // Linha da aba Usuários -- clique abre o modal de edição já preenchido
    // (mesmo padrão do modal de usuário da equipe em usuarios.php).
    var baseUrlAvatarCliente = <?= json_encode(url('')) ?>;
    function abrirModalUsuarioCliente(dados) {
        document.getElementById('modalUsuarioClienteTitulo').textContent = dados.nome;
        document.getElementById('usuarioClienteAvatarPreview').src = baseUrlAvatarCliente + (dados.foto_perfil || 'assets/img/avatares/avatar-1.svg');
        document.getElementById('avatarUsuarioClienteId').value = dados.id;
        document.getElementById('editarUsuarioClienteId').value = dados.id;
        document.getElementById('editarUsuarioClienteNome').value = dados.nome;
        document.getElementById('editarUsuarioClienteEmail').value = dados.email;
        document.getElementById('editarUsuarioClienteTelefone').value = dados.telefone ? PrimeUI.formatarTelefone(dados.telefone) : '';
        document.getElementById('editarUsuarioClienteDocumento').value = dados.documento || '';
        document.getElementById('editarUsuarioClienteSetor').value = dados.setor_id || '';
        document.querySelectorAll('.avatar-usuario-cliente-id-campo').forEach(function (campo) { campo.value = dados.id; });
        PrimeUI.openModal(document.getElementById('modalUsuarioCliente'));
    }
    document.querySelectorAll('.linha-usuario-cliente').forEach(function (linha) {
        linha.addEventListener('click', function (e) {
            if (e.target.closest('form, button, a, select')) return;
            abrirModalUsuarioCliente(JSON.parse(linha.getAttribute('data-usuario')));
        });
        linha.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') linha.click();
        });
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
