<?php

namespace AdminPrime;

use PDO;

/**
 * Auto-instalação do schema: cria as tabelas se não existirem.
 * Executado automaticamente no bootstrap (uma verificação rápida, sem custo
 * perceptível, já que usa CREATE TABLE IF NOT EXISTS).
 */
class Migrations
{
    public static function run(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL UNIQUE,
                senha_hash VARCHAR(255) NOT NULL,
                perfil ENUM('admin','colaborador','diretor','ceo') NOT NULL DEFAULT 'colaborador',
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Perfis novos (04/08/2026): além de admin/colaborador, agora tem
        // diretor e ceo -- pedido do Kleber pensando no futuro módulo
        // financeiro (contratos/faturas/CNAB, ainda em stand-by).
        $colunaPerfil = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'perfil'")->fetch();
        if ($colunaPerfil && stripos($colunaPerfil['Type'], 'ceo') === false) {
            $pdo->exec("ALTER TABLE usuarios MODIFY COLUMN perfil ENUM('admin','colaborador','diretor','ceo') NOT NULL DEFAULT 'colaborador'");
        }

        // Perfil da equipe também pode ter telefone e foto (mesmo padrão do
        // cliente) -- usados na página "Meu perfil".
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS telefone VARCHAR(50) NULL AFTER email");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(255) NULL COMMENT 'Caminho da foto de perfil, relativo à raiz do projeto' AFTER telefone");
        // CPF do agente da equipe -- mesmo motivo do documento do cliente
        // (responsabilização/identificação de quem usa o sistema), agora
        // pedido também pros dados cadastrais da própria equipe.
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS documento VARCHAR(20) NULL COMMENT 'CPF do usuário da equipe, só dígitos' AFTER telefone");

        // Layout customizado do dashboard, POR USUÁRIO -- pedido do Kleber
        // pra poder reorganizar os widgets (ordem + largura de cada um) sem
        // precisar pedir pra gente mexer no código, e cada colega poder ter
        // o próprio arranjo (o do Petronilho pode ficar diferente do dele).
        // JSON: [{"id":"clientes_atencao","span":12}, ...]. NULL = ainda não
        // customizou, usa a ordem padrão (ver dashboard.php).
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS dashboard_layout TEXT NULL COMMENT 'JSON com ordem/largura dos widgets do dashboard desse usuário' AFTER perfil");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clientes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(150) NOT NULL,
                status ENUM('ativo','pausado','encerrado') NOT NULL DEFAULT 'ativo',
                contato_nome VARCHAR(150) NULL,
                contato_email VARCHAR(150) NULL,
                contato_telefone VARCHAR(50) NULL,
                observacoes TEXT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demandas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT NULL,
                status ENUM('aberta','em_andamento','concluida','cancelada') NOT NULL DEFAULT 'aberta',
                prioridade ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
                responsavel_id INT NULL,
                prazo DATE NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_demandas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_demandas_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS kpis (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                nome_kpi VARCHAR(150) NOT NULL,
                valor DECIMAL(12,2) NOT NULL,
                meta DECIMAL(12,2) NULL,
                unidade VARCHAR(30) NULL,
                periodo DATE NOT NULL COMMENT 'Mês de referência do KPI, usar dia 01',
                observacoes VARCHAR(255) NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_kpis_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS documentos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT NULL,
                categoria VARCHAR(80) NULL,
                arquivo_nome_original VARCHAR(255) NOT NULL,
                arquivo_caminho VARCHAR(255) NOT NULL,
                arquivo_tamanho INT NOT NULL DEFAULT 0,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_documentos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_documentos_usuario FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Backup dos bytes do arquivo, mesmo esquema já usado pra foto/logo
        // (ver usuarios.foto_perfil_dados) -- o deploy do Hostinger apaga
        // uploads/ do zero (não respeita .gitignore), então o arquivo em
        // disco pode desaparecer mesmo com o registro no banco intacto. Como
        // documento/anexo é baixado sempre através de um script PHP
        // (download.php/download_anexo_chamado.php), a restauração aqui é
        // MAIS SIMPLES que a de foto/logo: não precisa varrer a tabela toda
        // a cada requisição -- o próprio script de download restaura o
        // arquivo específico na hora, só quando (e se) alguém for baixar um
        // que sumiu (ver Migrations::restaurarArquivoSeNecessario()).
        $pdo->exec("ALTER TABLE documentos ADD COLUMN IF NOT EXISTS dados LONGBLOB NULL COMMENT 'Cópia dos bytes do arquivo, pra restaurar se o deploy apagar uploads/' AFTER arquivo_tamanho");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS treinamentos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT NULL,
                status ENUM('planejado','em_andamento','concluido','cancelado') NOT NULL DEFAULT 'planejado',
                data_inicio DATE NULL,
                data_fim DATE NULL,
                responsavel_id INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_treinamentos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_treinamentos_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS temp_access_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(64) NOT NULL UNIQUE,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expira_em DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // --- Portal do cliente (login + chamados) ---

        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS senha_hash VARCHAR(255) NULL AFTER contato_telefone");
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS portal_ativo TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash");
        // Libera ALGUNS clientes (pedido pontual do Kleber) a usar o portal
        // fora do horário comercial (ver PORTAL_CLIENTE_HORARIO_INICIO/FIM em
        // config.php e ClienteAuth::bloquearForaDoHorario()) -- desligado por
        // padrão pra todo mundo continuar caindo na regra normal, só quem for
        // marcado explicitamente aqui ganha a exceção.
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS acesso_fora_horario TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_ativo");
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(255) NULL COMMENT 'Caminho da foto de perfil enviada pelo cliente, relativo à raiz do projeto' AFTER portal_ativo");

        // Logo da EMPRESA cliente (diferente da foto_perfil, que é a foto do
        // contato/pessoa) -- pedido do Kleber pra lista de clientes virar
        // cards com o logo de cada empresa em vez de tabela simples.
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS logo VARCHAR(255) NULL COMMENT 'Caminho do logo da empresa cliente, relativo à raiz do projeto' AFTER foto_perfil");

        // Backup dos BYTES de cada foto/logo enviado por upload, guardado no
        // próprio banco -- ver restaurarUploadsPerdidos() abaixo. O
        // .gitignore já impede o GIT de apagar assets/avatars/ num deploy,
        // mas isso não protege contra o próprio mecanismo de deploy do
        // Hostinger recriar a pasta do zero (clone limpo/"reimplantar"), que
        // não respeita .gitignore porque nem passa pelo git no destino. Com
        // os bytes também no banco, o arquivo que desaparecer do disco volta
        // sozinho na primeira requisição seguinte, sem precisar reenviar.
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS foto_perfil_dados LONGBLOB NULL COMMENT 'Cópia dos bytes de foto_perfil, pra restaurar o arquivo se o deploy apagar assets/avatars/' AFTER foto_perfil");
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS foto_perfil_dados LONGBLOB NULL COMMENT 'Cópia dos bytes de foto_perfil, mesmo motivo de usuarios.foto_perfil_dados' AFTER foto_perfil");
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS logo_dados LONGBLOB NULL COMMENT 'Cópia dos bytes de logo, mesmo motivo de usuarios.foto_perfil_dados' AFTER logo");

        // CPF ou RG do contato -- pedido do Kleber depois de um cliente ter
        // aberto 37 chamados de madrugada de forma abusiva: quer um
        // documento com foto vinculado a cada contato, pra ter
        // responsabilização/identificação de quem usa o sistema. Cadastros
        // NOVOS/editados exigem o campo (validação em clientes.php); os já
        // existentes ficam com NULL até serem atualizados -- não dá pra
        // forçar retroativamente sem parar o sistema pra todo mundo.
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS contato_documento VARCHAR(20) NULL COMMENT 'CPF ou RG do contato, só dígitos' AFTER contato_telefone");

        // CNPJ da EMPRESA cliente (diferente de contato_documento, que é o
        // CPF/RG da pessoa de contato) -- pedido do Kleber, guardado só
        // dígitos (a máscara XX.XXX.XXX/XXXX-XX é aplicada na tela via
        // formatar_cnpj()).
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS cnpj VARCHAR(20) NULL COMMENT 'CNPJ da empresa, só dígitos' AFTER nome");

        // Perfil público da empresa, gerado pela IA (Gemini + busca do
        // Google, ver AiSugestoes::pesquisarPerfilEmpresa()) -- guardado pra
        // não precisar gastar outra chamada à API só pra reler o que já foi
        // pesquisado antes (mesmo motivo de setor_sugestoes_ia).
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS perfil_ia TEXT NULL COMMENT 'Resumo do perfil público da empresa, gerado por IA com busca' AFTER cnpj");
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS perfil_ia_gerado_em DATETIME NULL AFTER perfil_ia");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cliente_senha_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                usado TINYINT(1) NOT NULL DEFAULT 0,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expira_em DATETIME NOT NULL,
                CONSTRAINT fk_senha_tokens_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Setores (departamentos) de um cliente -- pedido do Kleber depois
        // de um cliente perguntar se os setores da empresa dele (Compras,
        // Financeiro, RH...) podem ser "independentes", cada um com seu
        // próprio responsável abrindo chamado só pro assunto do próprio
        // setor. Fica de fora por padrão (lista vazia) -- só existe pra quem
        // a equipe cadastrar explicitamente, porque tem cliente pequeno que
        // só usa 1 ou 2 setores (ou nenhum, e continua no modelo antigo,
        // um contato só pra empresa toda).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS setores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                nome VARCHAR(100) NOT NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_setores_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Login de cada setor -- SEPARADO do login "master" (que continua
        // sendo clientes.contato_email/senha_hash, sem nenhuma mudança).
        // Cada setor tem seu próprio responsável/conta, só vê e abre chamado
        // do próprio setor; o master (contato principal do cliente) vê todos
        // os setores. Mesmos campos de sempre (foto, backup de bytes,
        // documento) pra reaproveitar avatar_html() e o resto do padrão já
        // usado em usuarios/clientes.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS setor_responsaveis (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setor_id INT NOT NULL,
                nome VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                telefone VARCHAR(50) NULL,
                documento VARCHAR(20) NULL COMMENT 'CPF ou RG, só dígitos',
                senha_hash VARCHAR(255) NULL,
                portal_ativo TINYINT(1) NOT NULL DEFAULT 0,
                foto_perfil VARCHAR(255) NULL,
                foto_perfil_dados LONGBLOB NULL COMMENT 'Backup dos bytes de foto_perfil, mesmo motivo de usuarios.foto_perfil_dados',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_setor_responsaveis_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE,
                UNIQUE KEY uq_setor_responsaveis_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Convite/reset de senha do responsável de setor -- mesmo esquema de
        // cliente_senha_tokens (token por e-mail, expira em 24h), só que
        // separado pra não precisar tornar cliente_id opcional lá.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS setor_senha_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setor_responsavel_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                usado TINYINT(1) NOT NULL DEFAULT 0,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expira_em DATETIME NOT NULL,
                CONSTRAINT fk_setor_senha_tokens_responsavel FOREIGN KEY (setor_responsavel_id) REFERENCES setor_responsaveis(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Setor pode ter VÁRIOS responsáveis (não só 1) -- pedido do Kleber
        // depois de setores como Financeiro precisarem de mais de uma
        // pessoa. E o contato MASTER pode "participar" de um setor também,
        // sem precisar de um cadastro duplicado com o mesmo e-mail dele só
        // pra aparecer como responsável -- daí esse flag por setor, em vez
        // de mais uma linha em setor_responsaveis.
        $pdo->exec("ALTER TABLE setores ADD COLUMN IF NOT EXISTS master_participa TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Contato master do cliente também é responsável desse setor' AFTER ativo");

        // Quem exatamente (qual responsável de setor) abriu/respondeu, pra
        // mostrar o avatar certo no chat -- antes de ter mais de um
        // responsável por setor, dava pra aproximar usando \"o responsável
        // atual do setor\"; com vários, isso deixaria de bater. NULL = aberto
        // pelo master, pela equipe, ou registro de antes dessa coluna
        // existir (cai pro comportamento antigo).
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS aberto_por_setor_responsavel_id INT NULL COMMENT 'Qual setor_responsaveis abriu, quando aberto_por_tipo=setor' AFTER aberto_por_tipo");
        $pdo->exec("ALTER TABLE chamado_respostas ADD COLUMN IF NOT EXISTS autor_setor_responsavel_id INT NULL COMMENT 'Qual setor_responsaveis respondeu, quando autor_cliente_tipo=setor' AFTER autor_cliente_tipo");

        // Bot de abertura de chamado pelo WhatsApp (menu guiado) -- ver
        // WhatsAppBot.php e webhook_whatsapp.php. NULL = aberto pelo
        // portal/admin, sem passar pelo WhatsApp (comportamento de sempre).
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS origem_canal VARCHAR(20) NULL COMMENT \"'portal', 'whatsapp' ou NULL (equipe/admin)\" AFTER aberto_por_setor_responsavel_id");

        // Estado da conversa em andamento com um número de WhatsApp -- uma
        // linha só por telefone (enquanto durar o menu guiado); apagada
        // assim que o chamado nasce ou a conversa é abandonada/reiniciada.
        // Não tem FK pra clientes/setores/setor_responsaveis de propósito
        // (mesmo padrão do resto do arquivo): o vínculo só é confirmado de
        // verdade na hora de criar o chamado.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS whatsapp_conversas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telefone VARCHAR(20) NOT NULL UNIQUE COMMENT 'wa_id da Meta -- só dígitos, com DDI',
                nome_perfil VARCHAR(150) NULL COMMENT 'Nome do perfil do WhatsApp de quem está mandando mensagem',
                nome_pessoa VARCHAR(150) NULL COMMENT 'Nome CADASTRADO da pessoa (setor_responsaveis.nome ou clientes.contato_nome), quando identificada pelo telefone -- mais confiável que nome_perfil pra chamar a pessoa pelo nome',
                cliente_id INT NULL,
                setor_id INT NULL,
                setor_responsavel_id INT NULL COMMENT 'Preenchido só quando o telefone bate com o de um responsável de setor específico (não o master)',
                etapa VARCHAR(40) NOT NULL DEFAULT 'identificando',
                titulo VARCHAR(200) NULL,
                categoria VARCHAR(80) NULL,
                prioridade VARCHAR(20) NULL,
                descricao TEXT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Prints/arquivos mandados durante a conversa, ANTES do chamado
        // existir -- migrados pra chamado_anexos quando o chamado é criado
        // (ver WhatsAppBot::criarChamado()).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS whatsapp_midias_temp (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversa_id INT NOT NULL,
                arquivo_nome_original VARCHAR(255) NULL,
                mime_type VARCHAR(100) NULL,
                dados LONGBLOB NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_whatsapp_midias_conversa FOREIGN KEY (conversa_id) REFERENCES whatsapp_conversas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chamados (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NULL,
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT NOT NULL,
                categoria VARCHAR(80) NULL,
                status ENUM('aberto','aguardando_equipe','aguardando_cliente','fechado') NOT NULL DEFAULT 'aberto',
                prioridade ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
                aberto_por ENUM('cliente','equipe','publico') NOT NULL DEFAULT 'cliente',
                responsavel_id INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_chamados_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_chamados_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Coluna adicionada depois que a tabela chamados já existia em produção
        // (usada para não importar o mesmo chamado do HESK duas vezes).
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS hesk_ticket_id MEDIUMINT NULL COMMENT 'Origem: id do chamado no HESK, quando migrado'");

        // Data/hora exata do encerramento -- usada pra calcular tempo de
        // atendimento nas métricas de usuário (ver usuarios.php). Setada só
        // no fechamento manual explícito (fechar_chamado/encerrar_chamado) e
        // zerada de novo ao reabrir (recalcular_status_chamado() cobre a
        // reabertura implícita quando o cliente responde um chamado
        // fechado). Chamados fechados ANTES dessa coluna existir ficam com
        // fechado_em NULL pra sempre -- não tem como recalcular retroativo
        // com precisão, as métricas só passam a valer pros próximos.
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS fechado_em DATETIME NULL COMMENT 'Quando o chamado foi encerrado -- usado pra métricas de tempo de atendimento' AFTER atualizado_em");

        // Setor (departamento) de origem do chamado -- ver tabela setores
        // acima. NULL pra todo chamado antigo (de antes dessa coluna
        // existir) e pra cliente que não usa setor nenhum (continua no
        // modelo de contato único, sem nenhuma mudança de comportamento).
        // Sem FK de banco de propósito (mesmo padrão das outras colunas
        // adicionadas via ALTER nesse arquivo) -- zera manualmente ao
        // excluir um setor (ver ação 'excluir_setor' em cliente.php).
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'Setor/departamento do cliente que abriu -- ver tabela setores' AFTER cliente_id");

        // Qual TIPO de login do cliente abriu o chamado (master ou um setor
        // específico) -- não dá pra inferir isso só olhando setor_id, porque
        // o master também pode escolher um setor no formulário, e o setor_id
        // pode mudar depois (reatribuição). Sem isso, o avatar mostrado na
        // mensagem de abertura ficava sempre o do master, mesmo quando quem
        // abriu foi o responsável de um setor. NULL = aberto pela equipe,
        // formulário público, ou chamado antigo de antes dessa coluna (nesses
        // casos cai pro comportamento de sempre, mostrando o master).
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS aberto_por_tipo VARCHAR(10) NULL COMMENT \"'master' ou 'setor', só quando aberto_por='cliente'\" AFTER aberto_por");

        // Formulário público de abertura de chamado (abrir_chamado.php, sem
        // login): quem preenche pode não ter batido com nenhum cliente
        // cadastrado (nome da empresa digitado errado, cliente novo, etc.),
        // então cliente_id passa a aceitar NULL e os dados de contato brutos
        // ficam guardados aqui até um humano vincular a um cliente na
        // triagem (chamados_sem_cliente.php).
        $colunaClienteId = $pdo->query("SHOW COLUMNS FROM chamados LIKE 'cliente_id'")->fetch();
        if ($colunaClienteId && stripos($colunaClienteId['Null'], 'YES') === false) {
            $pdo->exec("ALTER TABLE chamados MODIFY COLUMN cliente_id INT NULL");
        }
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS contato_nome_publico VARCHAR(150) NULL COMMENT 'Preenchido só quando veio do formulário público sem cliente identificado' AFTER cliente_id");
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS contato_email_publico VARCHAR(150) NULL AFTER contato_nome_publico");
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS empresa_informada VARCHAR(150) NULL COMMENT 'Nome da empresa como a pessoa digitou no formulário público' AFTER contato_email_publico");

        $colunaAbertoPor = $pdo->query("SHOW COLUMNS FROM chamados LIKE 'aberto_por'")->fetch();
        if ($colunaAbertoPor && stripos($colunaAbertoPor['Type'], 'publico') === false) {
            $pdo->exec("ALTER TABLE chamados MODIFY COLUMN aberto_por ENUM('cliente','equipe','publico') NOT NULL DEFAULT 'cliente'");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chamado_respostas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chamado_id INT NOT NULL,
                autor_tipo ENUM('cliente','equipe') NOT NULL,
                autor_nome VARCHAR(150) NOT NULL,
                mensagem TEXT NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_respostas_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Qual usuário da equipe (id) escreveu a resposta -- antes só se
        // guardava o nome em texto (autor_nome), então não tinha como saber
        // a foto/avatar de quem respondeu pra mostrar no chat. Respostas
        // antigas (e as importadas do HESK) ficam com NULL, cai pra inicial.
        $pdo->exec("ALTER TABLE chamado_respostas ADD COLUMN IF NOT EXISTS autor_usuario_id INT NULL AFTER autor_nome");

        // Mesma ideia de chamados.aberto_por_tipo, mas por RESPOSTA -- uma
        // conversa pode ter réplicas do master e de um setor intercaladas
        // (master responde qualquer chamado; login de setor só responde os
        // do próprio setor). NULL = resposta da equipe, ou de antes dessa
        // coluna existir (nesse caso cai pro master, comportamento de sempre).
        $pdo->exec("ALTER TABLE chamado_respostas ADD COLUMN IF NOT EXISTS autor_cliente_tipo VARCHAR(10) NULL COMMENT \"'master' ou 'setor', só quando autor_tipo='cliente'\" AFTER autor_usuario_id");

        // Anexos de chamado -- podem vir da mensagem de abertura (resposta_id
        // NULL) ou de qualquer resposta, tanto do cliente (portal) quanto da
        // equipe (cliente.php), além do futuro formulário público sem login
        // (abrir_chamado.php).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chamado_anexos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chamado_id INT NOT NULL,
                resposta_id INT NULL COMMENT 'NULL = anexo da mensagem de abertura do chamado; senão, de qual resposta',
                enviado_por ENUM('cliente','equipe') NOT NULL DEFAULT 'cliente',
                arquivo_nome_original VARCHAR(255) NOT NULL,
                arquivo_caminho VARCHAR(255) NOT NULL,
                arquivo_tamanho INT NOT NULL DEFAULT 0,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_anexos_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
                CONSTRAINT fk_anexos_resposta FOREIGN KEY (resposta_id) REFERENCES chamado_respostas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Mesmo backup de bytes de documentos (ver comentário lá em cima) --
        // esse foi o que o Kleber reportou como "removeu todos os anexos dos
        // chamados" depois de um deploy: uploads/chamados/ tem exatamente o
        // mesmo problema de assets/avatars/ antes de ganhar o backup no
        // banco, só que ainda não tinha sido corrigido aqui.
        $pdo->exec("ALTER TABLE chamado_anexos ADD COLUMN IF NOT EXISTS dados LONGBLOB NULL COMMENT 'Cópia dos bytes do arquivo, pra restaurar se o deploy apagar uploads/' AFTER arquivo_tamanho");

        // Modelo de status dos chamados simplificado (04/08/2026). Antes eram
        // 5 valores confusos e setados à mão em pontos diferentes do código
        // de um jeito até invertido (ex.: "em_andamento" era ligado quando o
        // CLIENTE respondia, e "respondido" quando a EQUIPE respondia -- o
        // contrário do que o nome sugere). Agora são só 4, e os dois do meio
        // são sempre RECALCULADOS a partir de quem respondeu por último
        // (nunca setados à mão, ver recalcular_status_chamado() em
        // helpers.php), então não tem como ficar incoerente:
        //   aberto             -> ninguém da equipe respondeu ainda
        //   aguardando_equipe  -> a equipe precisa responder (cliente foi o
        //                         último a falar, ou o cliente reabriu)
        //   aguardando_cliente -> a equipe já respondeu, esperando o cliente
        //   fechado            -> encerrado (substitui "resolvido" também,
        //                         que era a mesma coisa com nome diferente)
        $colunaStatusChamado = $pdo->query("SHOW COLUMNS FROM chamados LIKE 'status'")->fetch();
        if ($colunaStatusChamado && stripos($colunaStatusChamado['Type'], 'aguardando_equipe') === false) {
            // 1) ENUM temporário que aceita os valores antigos E os novos ao
            //    mesmo tempo, só pra dar espaço pro UPDATE de baixo rodar sem
            //    dar erro de valor inválido.
            $pdo->exec("ALTER TABLE chamados MODIFY COLUMN status ENUM('aberto','em_andamento','respondido','resolvido','fechado','aguardando_equipe','aguardando_cliente') NOT NULL DEFAULT 'aberto'");

            // 2) Recalcula o status real de cada chamado já existente a
            //    partir do histórico de respostas (mais confiável do que
            //    confiar no valor antigo, que podia estar errado).
            $pdo->exec("
                UPDATE chamados c SET status = CASE
                    WHEN status IN ('fechado', 'resolvido') THEN 'fechado'
                    WHEN NOT EXISTS (
                        SELECT 1 FROM chamado_respostas r
                        WHERE r.chamado_id = c.id AND r.autor_tipo = 'equipe'
                    ) THEN 'aberto'
                    WHEN (
                        SELECT r2.autor_tipo FROM chamado_respostas r2
                        WHERE r2.chamado_id = c.id
                        ORDER BY r2.criado_em DESC, r2.id DESC LIMIT 1
                    ) = 'equipe' THEN 'aguardando_cliente'
                    ELSE 'aguardando_equipe'
                END
            ");

            // 3) Agora que não sobrou nenhum chamado com valor antigo,
            //    restringe o ENUM de vez pros 4 valores novos.
            $pdo->exec("ALTER TABLE chamados MODIFY COLUMN status ENUM('aberto','aguardando_equipe','aguardando_cliente','fechado') NOT NULL DEFAULT 'aberto'");
        }

        // Usuário admin padrão, criado apenas se a tabela estiver vazia.
        $count = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                'Kleber Farias',
                'kleber.farias@primeerp.com.br',
                password_hash('AdminPrime@2026', PASSWORD_DEFAULT),
                'admin',
            ]);
        }

        // Setor passa a ter um ícone (bootstrap-icons) pra ficar mais fácil
        // de reconhecer visualmente no cadastro -- pedido do Kleber junto
        // com a separação do cadastro de usuário do cliente (ver mais
        // abaixo). NULL = setor antigo, sem ícone escolhido ainda (cai num
        // ícone genérico na exibição).
        $pdo->exec("ALTER TABLE setores ADD COLUMN IF NOT EXISTS icone VARCHAR(50) NULL COMMENT 'Classe do bootstrap-icons, ex.: bi-cash-coin' AFTER nome");

        // Responsável de setor agora nasce SEM setor (cadastrado na aba
        // Usuários do cliente) e só ganha setor_id quando alguém o
        // relaciona a um setor específico -- antes disso era obrigatório
        // informar o setor já na criação, misturando cadastro de pessoa com
        // cadastro de setor no mesmo formulário (o Kleber pediu pra separar).
        $pdo->exec("ALTER TABLE setor_responsaveis MODIFY COLUMN setor_id INT NULL");

        // Sem setor_id obrigatório, não dá mais pra saber de qual CLIENTE é
        // o responsável só através do JOIN com setores -- precisa de um
        // vínculo direto. Nasce nullable pra poder fazer o backfill (a partir
        // do setor atual de cada um) antes de travar como NOT NULL.
        $pdo->exec("ALTER TABLE setor_responsaveis ADD COLUMN IF NOT EXISTS cliente_id INT NULL COMMENT 'Cliente dono desse cadastro -- direto, não depende mais de ter setor' AFTER id");
        $pdo->exec("
            UPDATE setor_responsaveis sr
            INNER JOIN setores s ON s.id = sr.setor_id
            SET sr.cliente_id = s.cliente_id
            WHERE sr.cliente_id IS NULL
        ");
        $temPendenteSemCliente = (int) $pdo->query('SELECT COUNT(*) FROM setor_responsaveis WHERE cliente_id IS NULL')->fetchColumn();
        if ($temPendenteSemCliente === 0) {
            $pdo->exec('ALTER TABLE setor_responsaveis MODIFY COLUMN cliente_id INT NOT NULL');
        }

        // Setor ÚNICO do contato master -- substitui o antigo
        // setores.master_participa (que permitia o master "participar" de
        // VÁRIOS setores ao mesmo tempo). Como o master já vê/gerencia todos
        // os setores do cliente independente disso, só precisa estar
        // atrelado a UM setor (normalmente TI, que costuma tocar os
        // chamados); esse vínculo agora é editado no cadastro do usuário
        // (aba Usuários), não mais num checkbox dentro de cada setor. Sem FK
        // de banco de propósito (mesmo padrão de chamados.setor_id acima).
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'Setor único do contato master, ver setores -- substitui setores.master_participa' AFTER contato_documento");

        // Bloqueio de acesso ao BOT DO WHATSAPP, pessoa por pessoa -- pedido
        // do Kleber pra poder impedir alguém específico (master ou
        // responsável de setor) de abrir chamado por lá, sem precisar
        // desativar o cadastro inteiro (portal_ativo continua servindo pro
        // portal web). Gerenciado na aba Usuários (cliente.php).
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS bloqueado_whatsapp TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_ativo");
        $pdo->exec("ALTER TABLE setor_responsaveis ADD COLUMN IF NOT EXISTS bloqueado_whatsapp TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_ativo");
        // Tabela whatsapp_conversas já existia antes de nome_pessoa nascer no
        // CREATE TABLE acima -- garante a coluna também via ALTER, mesmo
        // padrão de toda evolução de tabela já existente neste arquivo.
        $pdo->exec("ALTER TABLE whatsapp_conversas ADD COLUMN IF NOT EXISTS nome_pessoa VARCHAR(150) NULL AFTER nome_perfil");

        // Telefone de quem abriu o chamado PELO BOT -- guardado no próprio
        // chamado (não só na conversa, que é apagada ao criar o chamado)
        // pra dar pra responder de volta no mesmo WhatsApp depois (ver
        // Notificacoes::novaRespostaEquipe()).
        $pdo->exec("ALTER TABLE chamados ADD COLUMN IF NOT EXISTS telefone_whatsapp VARCHAR(20) NULL COMMENT 'wa_id de quem abriu esse chamado pelo bot do WhatsApp' AFTER origem_canal");

        // Continuidade de atendimento -- quando a pessoa já tem chamado
        // aberto, o bot pergunta se é sobre ele ou um assunto novo antes de
        // sair pedindo categoria/prioridade de novo (chamado_continuar_id
        // guarda qual chamado está em jogo enquanto ela decide/escreve).
        $pdo->exec("ALTER TABLE whatsapp_conversas ADD COLUMN IF NOT EXISTS chamado_continuar_id INT NULL AFTER setor_id");

        // Anti-spam: número que erra a identificação (nome de empresa) demais
        // vezes fica temporariamente sem resposta do bot -- ver
        // WhatsAppBot::etapaIdentificacaoManual().
        $pdo->exec("ALTER TABLE whatsapp_conversas ADD COLUMN IF NOT EXISTS tentativas_identificacao INT NOT NULL DEFAULT 0 AFTER nome_pessoa");
        $pdo->exec("ALTER TABLE whatsapp_conversas ADD COLUMN IF NOT EXISTS bloqueado_ate DATETIME NULL AFTER tentativas_identificacao");

        // Horário de funcionamento do bot (ver WHATSAPP_HORARIO_COMERCIAL_*
        // em config.php) -- clientes com atendimento crítico fora do horário
        // normal podem ganhar liberação especial aqui (aba Usuários).
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS whatsapp_liberacao_horario TINYINT(1) NOT NULL DEFAULT 0 AFTER bloqueado_whatsapp");
        // Evita mandar o aviso de "fora do horário" em toda mensagem se a
        // pessoa insistir escrevendo várias vezes fora do expediente.
        $pdo->exec("ALTER TABLE whatsapp_conversas ADD COLUMN IF NOT EXISTS aviso_horario_enviado_em DATETIME NULL AFTER bloqueado_ate");

        // Treinamento passa a poder ser de um SETOR específico (não só do
        // cliente todo), e ganha os campos pra virar também "reunião" (tipo,
        // horário e link de call) -- pedido do Kleber pra ter uma agenda
        // organizada por setor.
        $pdo->exec("ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'Setor específico, ou NULL = treinamento geral do cliente' AFTER cliente_id");
        $pdo->exec("ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS tipo ENUM('treinamento','reuniao') NOT NULL DEFAULT 'treinamento' AFTER titulo");
        $pdo->exec("ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS hora_inicio TIME NULL AFTER data_inicio");
        $pdo->exec("ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS hora_fim TIME NULL AFTER data_fim");
        $pdo->exec("ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS link_reuniao VARCHAR(255) NULL COMMENT 'Link da videochamada (Meet/Teams/Zoom), quando tiver' AFTER hora_fim");

        // Marca quando o lembrete de "faltam 5 minutos" já foi disparado --
        // ver cron_lembretes_treinamento.php. NULL = ainda não avisou.
        // Editar o treinamento (data/hora) zera esse campo de novo (ver ação
        // 'editar_treinamento' em cliente.php), pra recalcular o lembrete em
        // cima do novo horário.
        $pdo->exec("ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS lembrete_enviado_em DATETIME NULL AFTER link_reuniao");

        // Aba "Setores" (substitui as antigas abas Documentos/Treinamentos,
        // que agora vivem DENTRO de cada setor): documento e KPI passam a
        // poder ser de um setor específico, ou NULL = geral do cliente
        // (inclusive os dois slots fixos de contrato em Visão Geral, que
        // continuam sem setor_id de propósito).
        $pdo->exec("ALTER TABLE documentos ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'Setor específico, ou NULL = documento geral do cliente' AFTER cliente_id");
        $pdo->exec("ALTER TABLE kpis ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'Setor específico, ou NULL = KPI geral do cliente' AFTER cliente_id");

        // Propostas de melhoria/desenvolvimento registradas dentro de um
        // setor -- pedido do Kleber pra ter um lugar de acompanhar ideias de
        // evolução do processo daquele setor, separado do chamado normal
        // (que é atendimento/suporte, não é backlog de melhoria).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS propostas_desenvolvimento (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                setor_id INT NULL,
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT NULL,
                status ENUM('proposta','aprovada','em_andamento','concluida','recusada') NOT NULL DEFAULT 'proposta',
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_propostas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_propostas_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Proposta pode vir com um anexo (ex.: PDF de uma proposta de
        // integração com API) -- em vez de duplicar arquivo_*/dados/backup
        // de documentos aqui, a proposta só GUARDA A REFERÊNCIA pra uma
        // linha em documentos (mesmo setor, categoria 'Proposta de
        // desenvolvimento') -- reaproveita 100% do upload/backup/download
        // que já existe pra documento, sem duplicar lógica.
        $pdo->exec("ALTER TABLE propostas_desenvolvimento ADD COLUMN IF NOT EXISTS documento_id INT NULL COMMENT 'Anexo opcional -- referencia documentos.id' AFTER descricao");

        // Histórico de sugestões geradas por IA pra cada setor (análise do
        // processo a partir de chamados/KPIs/treinamentos) -- guarda cada
        // geração (não sobrescreve), a tela mostra sempre a mais recente.
        // Sem FK pra setores de propósito (mesmo padrão do resto do
        // arquivo) -- se o setor for excluído, essas linhas ficam órfãs mas
        // inofensivas (nunca mais aparecem em tela nenhuma).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS setor_sugestoes_ia (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setor_id INT NOT NULL,
                sugestao TEXT NOT NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_sugestoes_ia_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Clientes iniciais, criados apenas se a tabela estiver vazia.
        $count = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare('INSERT INTO clientes (nome) VALUES (?)');
            foreach ([
                'WERK', 'SCHOTT', 'SGT', 'PREVER', 'DS TECNOLOGIA',
                'GEOMAQ', 'ICOMON', 'LQF', 'VITAPET', '3LIFE',
            ] as $nomeCliente) {
                $stmt->execute([$nomeCliente]);
            }
        }

        // Restaura ANTES de limparReferenciasQuebradas() -- se o arquivo
        // conseguir ser recriado a partir do backup no banco, não sobra
        // "referência quebrada" nenhuma pra limpar.
        self::restaurarUploadsPerdidos($pdo);
        self::limparReferenciasQuebradas($pdo);
        self::atribuirAvatarPadrao($pdo);
        self::vincularAutorRespostasAntigas($pdo);
        self::atribuirResponsavelPadrao($pdo);
        self::migrarSetorMasterAntigo($pdo);
    }

    /**
     * Recria em disco (assets/avatars/) qualquer foto/logo enviado por
     * upload que tenha desaparecido -- ex.: depois de um deploy que recriou
     * a pasta do zero -- usando o backup dos bytes guardado no banco (ver
     * colunas *_dados, preenchidas em perfil.php, portal/perfil.php e
     * processar_upload_logo_cliente() no momento do upload). Roda em toda
     * requisição, mas só lê o BLOB (potencialmente grande) do banco quando o
     * arquivo realmente estiver faltando -- na maioria das requisições, só
     * confere se o caminho existe no disco, sem tocar na coluna de bytes.
     * Avatar pré-definido (assets/img/avatares/*.svg) não passa por aqui --
     * é arquivo versionado no próprio código, nunca depende de upload.
     */
    /**
     * Versão "sob demanda" de restaurarUploadsPerdidos(), pra arquivos que
     * (diferente de foto/avatar, servidos direto como <img src>) sempre
     * passam por um script PHP de download antes de chegar no navegador
     * (download.php, download_anexo_chamado.php). Como tem esse ponto único
     * de passagem, não precisa varrer a tabela toda em toda requisição --
     * só restaura o arquivo ESPECÍFICO que está sendo baixado, e só se ele
     * de fato tiver desaparecido do disco. Retorna o caminho físico (já
     * restaurado, se precisou) ou null se não tem nem arquivo nem backup.
     */
    public static function restaurarArquivoSeNecessario(PDO $pdo, string $tabela, int $id, string $colunaCaminho, string $colunaDados, string $raizUploads): ?string
    {
        $stmt = $pdo->prepare("SELECT $colunaCaminho AS caminho, $colunaDados AS dados FROM $tabela WHERE id = ?");
        $stmt->execute([$id]);
        $linha = $stmt->fetch();
        if (!$linha || !$linha['caminho']) {
            return null;
        }

        $caminhoFisico = rtrim($raizUploads, '/') . '/' . $linha['caminho'];
        if (is_file($caminhoFisico)) {
            return $caminhoFisico;
        }
        if ($linha['dados'] === null) {
            return null;
        }

        $dir = dirname($caminhoFisico);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($caminhoFisico, $linha['dados']);

        return $caminhoFisico;
    }

    private static function restaurarUploadsPerdidos(PDO $pdo): void
    {
        $raiz = __DIR__ . '/..';
        foreach ([
            ['usuarios', 'foto_perfil', 'foto_perfil_dados'],
            ['clientes', 'foto_perfil', 'foto_perfil_dados'],
            ['clientes', 'logo', 'logo_dados'],
            ['setor_responsaveis', 'foto_perfil', 'foto_perfil_dados'],
        ] as [$tabela, $colunaCaminho, $colunaDados]) {
            $linhas = $pdo->query("SELECT id, $colunaCaminho AS caminho FROM $tabela WHERE $colunaCaminho IS NOT NULL AND $colunaDados IS NOT NULL")->fetchAll();
            foreach ($linhas as $linha) {
                if (!str_starts_with($linha['caminho'], 'assets/avatars/')) {
                    continue;
                }
                $caminhoFisico = $raiz . '/' . $linha['caminho'];
                if (is_file($caminhoFisico)) {
                    continue;
                }
                $stmtDados = $pdo->prepare("SELECT $colunaDados FROM $tabela WHERE id = ?");
                $stmtDados->execute([$linha['id']]);
                $dados = $stmtDados->fetchColumn();
                if ($dados === false || $dados === null) {
                    continue;
                }
                $dir = dirname($caminhoFisico);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($caminhoFisico, $dados);
            }
        }
    }

    /**
     * Pedido do Kleber: todo chamado sem responsável definido (a maioria dos
     * importados do HESK, e qualquer chamado aberto pelo cliente que ainda
     * não foi triado por alguém da equipe) cai pra ele por padrão, em vez de
     * ficar com "Responsável: -" pra sempre. Dali alguém pode reatribuir pra
     * outro colega (ver "atribuir_responsavel_chamado" em cliente.php) sem
     * problema -- essa migração só preenche quem está NULL, nunca sobrescreve
     * uma atribuição já feita.
     */
    private static function atribuirResponsavelPadrao(PDO $pdo): void
    {
        $adminPadrao = $pdo->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($adminPadrao) {
            $pdo->prepare('UPDATE chamados SET responsavel_id = ? WHERE responsavel_id IS NULL')->execute([$adminPadrao]);
        }
    }

    /**
     * Auto-cura de foto/logo "quebrados": se o arquivo enviado por upload
     * não existe mais em disco (ex.: perdido num deploy antes do
     * assets/avatars/ ter entrado no .gitignore, ou apagado por qualquer
     * outro motivo) mas o banco ainda guarda o caminho antigo, zera o campo.
     * Assim o site nunca fica preso mostrando ícone de imagem quebrada --
     * cai no fallback normal (inicial colorida pra foto de pessoa, ou o
     * próximo run já reatribui um avatar padrão via atribuirAvatarPadrao(),
     * ou o ícone de "sem logo" pra logo de empresa).
     */
    private static function limparReferenciasQuebradas(PDO $pdo): void
    {
        $raiz = __DIR__ . '/..';
        foreach ([
            ['usuarios', 'foto_perfil'],
            ['clientes', 'foto_perfil'],
            ['clientes', 'logo'],
        ] as [$tabela, $coluna]) {
            $linhas = $pdo->query("SELECT id, $coluna AS caminho FROM $tabela WHERE $coluna IS NOT NULL")->fetchAll();
            foreach ($linhas as $linha) {
                // Avatares pré-definidos (SVG em assets/img/avatares) são
                // parte do código versionado, sempre existem -- só upload
                // real (assets/avatars/...) pode ter se perdido.
                if (!str_starts_with($linha['caminho'], 'assets/avatars/')) {
                    continue;
                }
                if (!is_file($raiz . '/' . $linha['caminho'])) {
                    $pdo->prepare("UPDATE $tabela SET $coluna = NULL WHERE id = ?")->execute([$linha['id']]);
                }
            }
        }
    }

    /**
     * Preenche autor_usuario_id em respostas de chamado antigas (criadas
     * antes dessa coluna existir) tentando casar autor_nome com o nome de
     * um usuário da equipe -- só assim essas respostas antigas passam a
     * mostrar a foto de perfil de quem escreveu, em vez de cair pra sempre
     * na inicial genérica. Se dois usuários tiverem o mesmo nome, ou o nome
     * salvo não bater com nenhum usuário atual, a resposta continua com
     * autor_usuario_id NULL (comportamento de antes, sem piorar nada).
     */
    private static function vincularAutorRespostasAntigas(PDO $pdo): void
    {
        $pdo->exec("
            UPDATE chamado_respostas r
            INNER JOIN (
                SELECT nome, MIN(id) AS id FROM usuarios GROUP BY nome HAVING COUNT(*) = 1
            ) u ON u.nome = r.autor_nome
            SET r.autor_usuario_id = u.id
            WHERE r.autor_tipo = 'equipe' AND r.autor_usuario_id IS NULL
        ");
    }

    /**
     * Migração única: converte o antigo setores.master_participa (o master
     * podia "participar" de vários setores ao mesmo tempo) pro novo
     * clientes.setor_id (o master pertence a UM setor só). Pra cliente que
     * tinha master_participa=1 em mais de um setor, fica com o primeiro
     * (por id) -- não tem como escolher automaticamente qual é "o certo",
     * e como o master já vê todos os setores mesmo assim, não faz diferença
     * prática qual desses foi escolhido; se precisar, o Kleber troca depois
     * na aba Usuários. Só roda enquanto clientes.setor_id ainda estiver NULL
     * (idempotente -- não sobrescreve escolha manual já feita). Depois de
     * migrar os dados, a coluna antiga já não é lida em nenhum lugar do
     * código -- remove ela pra não deixar lixo morto no schema.
     */
    private static function migrarSetorMasterAntigo(PDO $pdo): void
    {
        $temColuna = $pdo->query("SHOW COLUMNS FROM setores LIKE 'master_participa'")->fetch();
        if (!$temColuna) {
            return;
        }

        $pdo->exec("
            UPDATE clientes c
            INNER JOIN (
                SELECT cliente_id, MIN(id) AS setor_id
                FROM setores
                WHERE master_participa = 1
                GROUP BY cliente_id
            ) s ON s.cliente_id = c.id
            SET c.setor_id = s.setor_id
            WHERE c.setor_id IS NULL
        ");

        $pdo->exec('ALTER TABLE setores DROP COLUMN master_participa');
    }

    /**
     * Kleber reportou que os avatares/fotos "não apareciam" nos chamados --
     * na prática era porque cada usuário/contato de cliente só ganha uma
     * foto/avatar quando ALGUÉM entra no próprio perfil e escolhe um, e
     * praticamente ninguém tinha feito isso ainda (foto_perfil = NULL cai na
     * inicial da letra, que é o comportamento correto, só que feio quando
     * quase todo mundo está assim). Em vez de esperar cada pessoa configurar
     * manualmente, atribui automaticamente um avatar variado (por id, pra não
     * repetir sempre o mesmo) pra quem ainda não tem foto nenhuma -- só
     * preenche o que está NULL, nunca sobrescreve uma foto/avatar já
     * escolhido por alguém.
     */
    private static function atribuirAvatarPadrao(PDO $pdo): void
    {
        $qtd = defined('QUANTIDADE_AVATARES_PREDEFINIDOS') ? QUANTIDADE_AVATARES_PREDEFINIDOS : 16;

        foreach (['usuarios', 'clientes'] as $tabela) {
            $pendentes = $pdo->query("SELECT id FROM $tabela WHERE foto_perfil IS NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($pendentes as $id) {
                $indice = ((int) $id % $qtd) + 1;
                $pdo->prepare("UPDATE $tabela SET foto_perfil = ? WHERE id = ? AND foto_perfil IS NULL")
                    ->execute(['assets/img/avatares/avatar-' . $indice . '.svg', $id]);
            }
        }
    }
}
