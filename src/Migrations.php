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
        // Confirmação de leitura dos chamados (sistema novo): última vez que
        // cada pessoa abriu cada chamado. Mensagem conta como "vista" quando
        // alguém do outro lado abriu o chamado depois dela.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chamado_leituras (
                chamado_id INT NOT NULL,
                leitor_tipo VARCHAR(10) NOT NULL,
                leitor_id INT NOT NULL,
                leitor_nome VARCHAR(150) NULL,
                lido_em DATETIME NOT NULL,
                PRIMARY KEY (chamado_id, leitor_tipo, leitor_id),
                KEY idx_leitura_chamado (chamado_id, lido_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
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

        // Responsável de setor que também é MASTER -- pedido do Victor Alvim
        // (Vitapet): tem gente no TI dele que precisa acompanhar chamado de
        // TODOS os setores, não só o próprio. Marcando essa flag, o login
        // dessa pessoa passa a ter as mesmas permissões de master (ver
        // ClienteAuth::temPermissaoMaster()) SEM perder a identidade própria
        // (nome/foto continuam do registro dela em setor_responsaveis, não
        // do contato master da empresa -- por isso não reaproveitamos o
        // login master existente, ver ClienteAuth::iniciarSessaoSetor()).
        $pdo->exec("ALTER TABLE setor_responsaveis ADD COLUMN IF NOT EXISTS eh_master TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Responsável de setor com permissões de master (vê/gerencia todos os setores)' AFTER portal_ativo");

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

        // Quando o cliente ainda não tem ninguém indicado como contato
        // master (contato_nome/email/telefone em branco), alguém da equipe
        // (Kleber ou Petronilho, tipicamente) pode se atribuir como
        // "responsável interno" por essa conta, pra ficar claro quem
        // acompanha esse cliente enquanto não há um contato de verdade.
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS responsavel_interno_id INT NULL COMMENT 'usuarios.id -- admin da Prime responsável por essa conta, usado sobretudo quando ainda não há contato_nome definido' AFTER contato_documento");

        // Nem todo cliente deve poder cadastrar setor/usuário sozinho no
        // portal (portal/equipe.php) -- fica DESLIGADO por padrão, a Prime
        // libera cliente por cliente (tem cliente que não tem preparo pra
        // isso sem confundir tudo).
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS portal_permite_gerenciar_equipe TINYINT(1) NOT NULL DEFAULT 0 AFTER responsavel_interno_id");
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

        // Quem deve ser avisado (e-mail + WhatsApp) de cada treinamento/
        // reunião -- por padrão ninguém do lado do cliente é avisado (só a
        // equipe interna, ver Notificacoes::novoTreinamento()/lembreteTreinamento());
        // isso aqui é pra marcar pessoas ESPECÍFICAS do cliente (contato
        // master e/ou responsáveis de setor) que também devem receber o
        // aviso de criação e o lembrete de 5min antes.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS treinamento_destinatarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                treinamento_id INT NOT NULL,
                tipo ENUM('master','setor_responsavel') NOT NULL,
                setor_responsavel_id INT NULL COMMENT 'Só quando tipo=setor_responsavel',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (treinamento_id) REFERENCES treinamentos(id) ON DELETE CASCADE,
                FOREIGN KEY (setor_responsavel_id) REFERENCES setor_responsaveis(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Aba "Setores" (substitui as antigas abas Documentos/Treinamentos,
        // que agora vivem DENTRO de cada setor): documento e KPI passam a
        // poder ser de um setor específico, ou NULL = geral do cliente
        // (inclusive os dois slots fixos de contrato em Visão Geral, que
        // continuam sem setor_id de propósito).
        $pdo->exec("ALTER TABLE documentos ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'Setor específico, ou NULL = documento geral do cliente' AFTER cliente_id");
        $pdo->exec("ALTER TABLE kpis ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'Setor específico, ou NULL = KPI geral do cliente' AFTER cliente_id");

        // Redesign dos cards de KPI (cores vermelho/amarelo/verde contra a
        // meta): sem saber se "maior é melhor" ou "menor é melhor" pra cada
        // KPI não dá pra colorir nada -- ex.: "chamados urgentes" quanto
        // menor melhor, já "chamados resolvidos" quanto maior melhor.
        $pdo->exec("ALTER TABLE kpis ADD COLUMN IF NOT EXISTS direcao_melhor ENUM('maior','menor') NOT NULL DEFAULT 'maior' COMMENT 'maior=quanto mais alto melhor, menor=quanto mais baixo melhor (usado para colorir o card contra a meta)' AFTER meta");

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

        // Aba "Implantação" (cliente.php) -- checklist de etapas do processo
        // de onboarding do cliente (contrato, cadastros, treinamento,
        // testes, go-live...), nível CLIENTE (não por setor, é um processo
        // único da empresa toda). "ordem" define a posição no checklist
        // (ver ações reordenar_etapa_implantacao em cliente.php).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS implantacao_etapas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT NULL,
                status ENUM('pendente','em_andamento','concluida') NOT NULL DEFAULT 'pendente',
                ordem INT NOT NULL DEFAULT 0,
                data_prevista DATE NULL,
                data_concluida DATE NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_implantacao_etapas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Anexo de uma etapa de implantação específica -- reaproveita 100%
        // do upload/backup/download de documentos já existente (mesma ideia
        // de propostas_desenvolvimento.documento_id), só que aqui pode ter
        // VÁRIOS anexos (não um só), então em vez de um id único na
        // implantação é a linha de documentos que aponta pra etapa dela.
        // NULL = anexo geral da implantação, sem etapa específica.
        $pdo->exec("ALTER TABLE documentos ADD COLUMN IF NOT EXISTS implantacao_etapa_id INT NULL COMMENT 'Anexo de uma etapa de implantação (categoria=Implantação), ou NULL se for anexo geral' AFTER setor_id");

        // Histórico de sugestões de IA específicas do processo de
        // implantação -- guarda a mais recente POR TIPO (não sobrescreve,
        // só mostra a última de cada tipo na tela), pra dar pra gerar de
        // novo um tipo sem perder os outros já gerados.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS implantacao_sugestoes_ia (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                tipo VARCHAR(40) NOT NULL COMMENT 'treinamento, teste, boas_praticas, migracao_dados, comunicacao, riscos ou go_live',
                sugestao TEXT NOT NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_implantacao_sugestoes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_implantacao_sugestoes_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Cada "seção" é o resultado da IA pra UM documento específico (ou a
        // seção geral, sem documento -- documento_id=0) de uma sugestão de
        // Implantação -- pedido do Kleber pra processar documento por
        // documento numa chamada de cada vez ao Gemini (em vez de mandar
        // todos juntos numa chamada só, que arriscava timeout de proxy com
        // vários documentos grandes) e ir "alimentando" o roteiro conforme
        // cada um é processado. UNIQUE(cliente_id, tipo, documento_id): gerar
        // de novo pro MESMO documento SUBSTITUI a seção dele (não duplica);
        // gerar pra um documento novo ADICIONA uma seção nova. O texto final
        // mostrado/exportado é a concatenação de todas as seções desse
        // (cliente_id, tipo), na ordem em que foram processadas (ver coluna
        // "ordem") -- ver cliente.php, ação "gerar_sugestao_implantacao".
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS implantacao_sugestoes_ia_secoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                tipo VARCHAR(40) NOT NULL,
                documento_id INT NOT NULL DEFAULT 0 COMMENT '0 = seção geral, sem documento específico',
                documento_nome VARCHAR(255) NOT NULL DEFAULT '',
                conteudo MEDIUMTEXT NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_secao_implantacao (cliente_id, tipo, documento_id),
                CONSTRAINT fk_implantacao_secoes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Reunião/treinamento passa a poder ser atrelado a um CHAMADO
        // específico -- pedido do Kleber pra registrar que uma conversa/
        // auxílio no Meet aconteceu por causa de um chamado, e ver isso
        // direto na tela do chamado depois. NULL = evento sem chamado
        // vinculado (continua funcionando exatamente como antes). Também
        // ganha os tipos "conversa" (bate-papo rápido, sem ser treinamento
        // formal) e "auxílio" (ajuda pontual, geralmente decorrente de um
        // chamado) -- só treinamento/reunião não cobriam esses casos.
        $pdo->exec("ALTER TABLE treinamentos MODIFY COLUMN tipo ENUM('treinamento','reuniao','conversa','auxilio') NOT NULL DEFAULT 'treinamento'");
        $pdo->exec("ALTER TABLE treinamentos ADD COLUMN IF NOT EXISTS chamado_id INT NULL COMMENT 'Chamado que originou essa conversa/reunião, ou NULL se não tiver' AFTER setor_id");

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

        // Um responsável (ou o próprio master) agora pode pertencer a VÁRIOS
        // setores ao mesmo tempo -- pedido do Kleber: a Eliete/Ananda da SGT
        // Forjados precisam acompanhar mais de um setor. setor_responsaveis.setor_id
        // e clientes.setor_id continuam existindo como um "setor principal"
        // (mantido em sincronia, ver sincronizarSetorPrincipalResponsavel() e
        // sincronizarSetorPrincipalMaster() em helpers.php) -- é o que o login,
        // o bot do WhatsApp e o pré-preenchimento de "abrir chamado" continuam
        // lendo sem precisar de nenhuma mudança neles. Essas duas tabelas
        // novas são a fonte de verdade de TODOS os setores de cada pessoa;
        // quem precisa saber "quais setores essa pessoa vê" (visibilidade de
        // chamado, notificação, cards de responsáveis) passa a consultar elas.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS setor_responsavel_setores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setor_responsavel_id INT NOT NULL,
                setor_id INT NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_setor_responsavel_setor (setor_responsavel_id, setor_id),
                CONSTRAINT fk_srs_responsavel FOREIGN KEY (setor_responsavel_id) REFERENCES setor_responsaveis(id) ON DELETE CASCADE,
                CONSTRAINT fk_srs_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cliente_setores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                setor_id INT NOT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cliente_setor (cliente_id, setor_id),
                CONSTRAINT fk_cs_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_cs_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::migrarSetorUnicoParaMuitosParaMuitos($pdo);

        // Configurações editáveis pela tela configuracoes.php (WhatsApp/
        // e-mail) -- chave/valor simples porque são poucos campos e cada um
        // é independente (não precisa de colunas tipadas). Valor NULL/vazio
        // = "sem override", quem lê cai pro padrão de config/config.php (ver
        // Configuracoes::get()). Pedido do Kleber pra não precisar mais
        // pedir pra eu trocar token/telefone/SMTP direto no código.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS configuracoes (
                chave VARCHAR(100) NOT NULL PRIMARY KEY,
                valor TEXT NULL,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                atualizado_por INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Permissão especial (além de admin/diretor, que já têm por padrão --
        // ver Auth::podeAcessarDadosSensiveis()) pra um colaborador específico
        // enxergar a aba "Acessos" (credenciais/VPN) e "Fontes ADVPL" de um
        // cliente -- dado extremamente sensível (senha de servidor, RDP,
        // AnyDesk, código-fonte proprietário do cliente), pedido do Kleber
        // pra ficar restrito por padrão e só liberado caso a caso.
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS acesso_dados_sensiveis TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Acesso especial a Acessos/Fontes ADVPL, além do que admin/diretor já têm'");

        // --- Cofre de acessos do cliente (aba "Acessos") -----------------
        // Nível CLIENTE (não por setor) -- servidor/RDP/AnyDesk/VPN costuma
        // ser da empresa toda, não de um setor específico. A senha NUNCA
        // fica em texto puro no banco: senha_cifrada guarda o resultado de
        // Crypto::cifrar() (AES-256-GCM, chave só no config.php do servidor,
        // nunca versionada) -- é decifrada só na hora de mostrar pra quem
        // tem permissão (ver ver_credencial.php), nunca embutida direto no
        // HTML da página.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cliente_credenciais (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                tipo ENUM('servidor','rdp','anydesk','vpn','protheus','sql_server','t_cloud','outro') NOT NULL DEFAULT 'outro',
                titulo VARCHAR(200) NOT NULL,
                host VARCHAR(150) NULL,
                porta VARCHAR(10) NULL,
                usuario VARCHAR(150) NULL,
                senha_cifrada TEXT NULL,
                observacoes TEXT NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_cliente_credenciais_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_cliente_credenciais_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Tipos novos (Protheus, SQL Server, T-Cloud) pedidos pelo Kleber
        // depois da tabela já existir em produção -- CREATE TABLE IF NOT
        // EXISTS não altera enum de tabela já criada.
        $colunaTipoCredencial = $pdo->query("SHOW COLUMNS FROM cliente_credenciais LIKE 'tipo'")->fetch();
        if ($colunaTipoCredencial && stripos($colunaTipoCredencial['Type'], 'protheus') === false) {
            $pdo->exec("ALTER TABLE cliente_credenciais MODIFY COLUMN tipo ENUM('servidor','rdp','anydesk','vpn','protheus','sql_server','t_cloud','outro') NOT NULL DEFAULT 'outro'");
        }

        // Repositório de arquivo de VPN (ex.: .ovpn) pra baixar/repassar --
        // deliberadamente uma tabela PRÓPRIA, não reaproveita "documentos":
        // documentos é acessível por QUALQUER um da equipe (ver
        // download.php), e aqui precisa da MESMA trava restrita da aba
        // Acessos (ver download_vpn.php). Arquivo de VPN é sempre pequeno,
        // então o backup dos bytes no banco (mesma ideia de documentos.dados)
        // não tem o risco de memória que vídeo aula tem.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cliente_vpn_arquivos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                arquivo_nome_original VARCHAR(255) NOT NULL,
                arquivo_caminho VARCHAR(255) NOT NULL,
                arquivo_tamanho INT NOT NULL DEFAULT 0,
                dados LONGBLOB NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_vpn_arquivos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_vpn_arquivos_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // --- Fontes ADVPL por setor (sub-aba "Fontes ADVPL") -------------
        // Código-fonte proprietário do cliente -- mesmo nível de sigilo do
        // cofre de acessos acima, mesma trava de permissão. "ponto_entrada"
        // é o nome da função/rotina customizada (ex.: U_MEUFONTE01) que o
        // Protheus chama pra entrar nesse fonte -- útil na hora de reaplicar
        // o customizado depois de uma atualização do Protheus.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS setor_fontes_advpl (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setor_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                ponto_entrada VARCHAR(150) NULL COMMENT 'Nome da função/ponto de entrada customizado, ex.: U_MEUFONTE01',
                descricao TEXT NULL,
                arquivo_nome_original VARCHAR(255) NOT NULL,
                arquivo_caminho VARCHAR(255) NOT NULL,
                arquivo_tamanho INT NOT NULL DEFAULT 0,
                dados LONGBLOB NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_fontes_advpl_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE,
                CONSTRAINT fk_fontes_advpl_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Auditoria de acesso a dado sensível (boas práticas de mercado pra
        // cofre de credencial/código-fonte proprietário) -- registra TODA
        // vez que alguém revela uma senha ou baixa um arquivo de VPN/fonte
        // ADVPL, mesmo sendo alguém autorizado. Sem FK pra usuario "NOT
        // NULL" de propósito (usuario_id fica NULL se a conta for excluída
        // depois, mas o registro de QUE aconteve um acesso continua valendo).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS acesso_dados_sensiveis_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NULL,
                tipo VARCHAR(30) NOT NULL COMMENT 'credencial, vpn_arquivo ou fonte_advpl',
                acao VARCHAR(30) NOT NULL COMMENT 'visualizar_senha, baixar_arquivo etc.',
                referencia_id INT NOT NULL,
                cliente_id INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_log_sensivel_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // --- Aba T-Cloud (aba nova, mesma trava de dados sensíveis) ------
        // Links de ambiente (produção/teste) do T-Cloud -- 1 linha por
        // ambiente por cliente (chave única cliente_id+tipo, upsert direto
        // via ON DUPLICATE KEY UPDATE). São só URLs, não senha, mas ainda
        // assim ficam atrás da mesma trava de Auth::podeAcessarDadosSensiveis()
        // porque revelam a topologia dos servidores do cliente.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cliente_tcloud_ambientes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                tipo ENUM('dev','teste','homologacao','producao') NOT NULL,
                link_protheus VARCHAR(500) NULL,
                link_rest VARCHAR(500) NULL,
                link_schedule VARCHAR(500) NULL,
                link_exclusivo VARCHAR(500) NULL,
                atualizado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_tcloud_ambiente (cliente_id, tipo),
                CONSTRAINT fk_tcloud_ambientes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_tcloud_ambientes_usuario FOREIGN KEY (atualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Dev e Homologação entraram depois (pedido do Kleber) -- mesmo
        // motivo do ALTER de cliente_credenciais.tipo acima: CREATE TABLE
        // IF NOT EXISTS não atualiza enum de tabela que já existe.
        $colunaTipoTcloudAmbiente = $pdo->query("SHOW COLUMNS FROM cliente_tcloud_ambientes LIKE 'tipo'")->fetch();
        if ($colunaTipoTcloudAmbiente && stripos($colunaTipoTcloudAmbiente['Type'], 'homologacao') === false) {
            $pdo->exec("ALTER TABLE cliente_tcloud_ambientes MODIFY COLUMN tipo ENUM('dev','teste','homologacao','producao') NOT NULL");
        }

        // Configurações de acesso via VSCode (link + porta + rótulo do
        // ambiente) -- lista livre, pode ter mais de uma (ex.: uma pra cada
        // servidor/ambiente que o dev conecta direto pelo VSCode).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cliente_tcloud_vscode (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                titulo VARCHAR(150) NOT NULL,
                link VARCHAR(500) NULL,
                porta VARCHAR(10) NULL,
                ambiente VARCHAR(100) NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_tcloud_vscode_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_tcloud_vscode_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Aba "Análise de Risco" (cliente.php) -- histórico de análises
        // geradas por IA (mesma chave Gemini de análise já usada em
        // Setores/IA e Implantação/IA, ver src/AiSugestoes.php) a partir do
        // histórico de chamados/tempo de atendimento do cliente. Mesmo
        // formato de setor_sugestoes_ia: guarda cada geração (não
        // sobrescreve), a tela mostra sempre a mais recente.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cliente_analise_risco (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                analise MEDIUMTEXT NOT NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_analise_risco_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_analise_risco_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Cada análise agora vira um CARD independente (visualizar/editar/
        // excluir/exportar cada uma na hora, ver cliente.php) em vez de só a
        // mais recente aparecer na tela -- por isso "editar" passou a
        // ATUALIZAR o próprio registro (não insere mais uma versão nova no
        // histórico a cada edição). 'tipo' separa duas análises com
        // propósito e tom BEM diferentes: 'risco' é uso interno da Prime
        // (nível de risco de perda do cliente, sem filtro de linguagem) e
        // 'melhorias' é um relatório com tom profissional pensado pra ser
        // ENTREGUE ao próprio cliente (nunca menciona risco de cancelamento,
        // atendente específico ou qualquer comentário interno). 'setor_id'
        // NULL = análise do cliente inteiro; preenchido = só daquele setor
        // (ON DELETE SET NULL -- se o setor for excluído depois, a análise
        // não se perde, só perde o escopo específico).
        $pdo->exec("ALTER TABLE cliente_analise_risco ADD COLUMN IF NOT EXISTS tipo VARCHAR(20) NOT NULL DEFAULT 'risco' COMMENT 'risco (interno) ou melhorias (relatorio pro cliente)' AFTER cliente_id");
        $pdo->exec("ALTER TABLE cliente_analise_risco ADD COLUMN IF NOT EXISTS setor_id INT NULL COMMENT 'NULL = cliente inteiro' AFTER tipo");
        $pdo->exec("ALTER TABLE cliente_analise_risco ADD COLUMN IF NOT EXISTS atualizado_em DATETIME NULL COMMENT 'Preenchido só quando o card é editado depois de gerado' AFTER criado_em");
        if (!$pdo->query("SHOW KEYS FROM cliente_analise_risco WHERE Key_name = 'fk_analise_risco_setor'")->fetch()) {
            $pdo->exec('ALTER TABLE cliente_analise_risco ADD CONSTRAINT fk_analise_risco_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE SET NULL');
        }

        // Timbrado (cabeçalho/rodapé) real do PDF (ver src/PdfExport.php) --
        // linha única (id sempre 1) com os arquivos enviados pela tela
        // configuracoes.php. Enquanto ninguém subir os arquivos, PdfExport
        // continua caindo no desenho em código de hoje (fallback) -- essa
        // tabela só existe pra guardar imagem real quando/se ela chegar.
        // Mesmo padrão de backup em blob (LONGBLOB) usado em clientes.logo
        // etc., pra sobreviver a um deploy que apague uploads/.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS timbrado_pdf (
                id INT PRIMARY KEY DEFAULT 1,
                cabecalho_caminho VARCHAR(255) NULL,
                cabecalho_dados LONGBLOB NULL,
                cabecalho_altura_mm DECIMAL(5,2) NOT NULL DEFAULT 28,
                rodape_caminho VARCHAR(255) NULL,
                rodape_dados LONGBLOB NULL,
                rodape_altura_mm DECIMAL(5,2) NOT NULL DEFAULT 16,
                atualizado_por INT NULL,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_timbrado_pdf_usuario FOREIGN KEY (atualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("INSERT IGNORE INTO timbrado_pdf (id) VALUES (1)");

        // Perfil "Colaborador" com acesso restrito por cliente/setor (pedido
        // do Kleber pra contratar gente nova sem dar acesso ao sistema
        // inteiro de cara) -- acesso_restrito_por_cliente é um opt-in: fica
        // 0 (desligado) por padrão pra todo mundo que já existe, então
        // ninguém que já usava o sistema normalmente fica travado do nada;
        // só passa a valer pra quem um admin marcar explicitamente em
        // usuarios.php (e, junto, vincular pelo menos 1 cliente/setor).
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS acesso_restrito_por_cliente TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Só enxerga os clientes/setores vinculados em usuario_cliente_setores, em vez do sistema inteiro' AFTER acesso_dados_sensiveis");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS ultimo_login_em DATETIME NULL AFTER acesso_restrito_por_cliente");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuario_cliente_setores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                cliente_id INT NOT NULL,
                setor_id INT NULL COMMENT 'NULL = acesso ao cliente inteiro (todos os setores, inclusive chamados sem setor); preenchido = só esse setor',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ucs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
                CONSTRAINT fk_ucs_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                CONSTRAINT fk_ucs_setor FOREIGN KEY (setor_id) REFERENCES setores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Evita duplicar a MESMA linha (usuario+cliente+setor) -- MySQL/
        // MariaDB não aplicam UNIQUE em colunas com NULL (cada NULL conta
        // como diferente), então "setor_id IS NULL" não dá pra proteger só
        // com UNIQUE KEY; setor_id_norm (0 no lugar de NULL) resolve isso
        // sem mudar o significado de NULL usado no resto do código.
        $pdo->exec("ALTER TABLE usuario_cliente_setores ADD COLUMN IF NOT EXISTS setor_id_norm INT AS (IFNULL(setor_id, 0)) STORED AFTER setor_id");
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_usuario_cliente_setor
            ON usuario_cliente_setores (usuario_id, cliente_id, setor_id_norm)
        ");

        // Trilha de login da equipe (quem entrou e quando) -- base do
        // relatório de atividade por colaborador (ver relatorios.php),
        // já que hoje não existe nenhum log de acesso além do de dados
        // sensíveis (acesso_dados_sensiveis_log, que é só credencial/VPN/
        // fonte). Nunca guarda senha nem nada sensível, só o fato do login.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuario_login_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL COMMENT 'Cabeçalho User-Agent do navegador no login -- só dá pra identificar SO/navegador aproximados, nunca nome da máquina/processador/memória (nenhum site tem acesso a isso, é bloqueado por design pelo navegador)',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ull_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("ALTER TABLE usuario_login_log ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL COMMENT 'Cabeçalho User-Agent do navegador no login -- só dá pra identificar SO/navegador aproximados, nunca nome da máquina/processador/memória (nenhum site tem acesso a isso, é bloqueado por design pelo navegador)' AFTER ip");

        // Biblioteca GERAL de fontes/patches ADVPL (PRW/PRX/TLPP/PTM),
        // organizada por categoria/departamento (ver FONTES_GLOBAIS_
        // CATEGORIAS em config.php) -- pedido do Kleber pra ter um lugar só,
        // fora de qualquer cliente específico, pra guardar fonte/patch de
        // uso geral da equipe (não é o mesmo que setor_fontes_advpl, que é
        // o fonte DESSE cliente/setor específico). Mesmo padrão de backup
        // em dados (LONGBLOB) do resto do sistema -- ver
        // Migrations::restaurarArquivoSeNecessario().
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fontes_globais (
                id INT AUTO_INCREMENT PRIMARY KEY,
                categoria VARCHAR(30) NOT NULL,
                nome VARCHAR(200) NOT NULL,
                descricao TEXT NULL,
                arquivo_nome_original VARCHAR(255) NOT NULL,
                arquivo_caminho VARCHAR(255) NOT NULL,
                arquivo_tamanho INT NOT NULL DEFAULT 0,
                dados LONGBLOB NULL,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_fontes_globais_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fontes_globais_categoria ON fontes_globais (categoria)");

        // Agenda de trabalho da GESTÃO (aba "Agenda", agenda.php) -- pedido
        // do Kleber: ele tem todos os chamados mas fica perdido em qual
        // cliente atender primeiro num dia -- aqui ele monta um plano do dia
        // (opcionalmente ligado a um cliente/chamado, ou só uma tarefa
        // livre), com ordem de prioridade arrastável. Só admin/diretor/CEO
        // usam isso por enquanto ("colaborador não precisa") -- compartilhado
        // entre eles (não é "agenda pessoal" de cada um, é o mesmo quadro pra
        // todo mundo da gestão ver e mexer).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agenda_tarefas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                data DATE NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                titulo VARCHAR(200) NOT NULL,
                cliente_id INT NULL,
                chamado_id INT NULL,
                notas TEXT NULL,
                status ENUM('pendente','em_andamento','concluida') NOT NULL DEFAULT 'pendente',
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_agenda_tarefas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
                CONSTRAINT fk_agenda_tarefas_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE SET NULL,
                CONSTRAINT fk_agenda_tarefas_criado_por FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_agenda_tarefas_data ON agenda_tarefas (data)");

        // Depois de ver a primeira versão, o Kleber pediu que a Agenda vire um
        // gráfico de Gantt DE VERDADE, organizado por horário (não só por
        // dia) -- cada tarefa ganha um horário de início e uma duração
        // estimada, e o board vira uma timeline por hora dentro do
        // expediente. hora_inicio fica NULL pras tarefas antigas/sem horário
        // definido (aparecem como "sem horário" até serem posicionadas).
        $pdo->exec("ALTER TABLE agenda_tarefas ADD COLUMN IF NOT EXISTS hora_inicio TIME NULL AFTER data");
        $pdo->exec("ALTER TABLE agenda_tarefas ADD COLUMN IF NOT EXISTS duracao_minutos INT NOT NULL DEFAULT 60 AFTER hora_inicio");

        // Tarefa sem horário definido podia ficar só "solta" (sem dia certo
        // de terminar) -- pedido do Kleber: "não consigo colocar a mesma
        // tarefa pra mais de um dia, alongar ela" (ex.: um projeto que toma a
        // semana inteira). data_fim = último dia da tarefa; NULL/igual a
        // "data" = tarefa de um dia só (a maioria). Só faz sentido pra tarefa
        // SEM horário -- uma tarefa com hora_inicio marcado continua sempre
        // de 1 dia só (a duração em minutos já cobre isso).
        $pdo->exec("ALTER TABLE agenda_tarefas ADD COLUMN IF NOT EXISTS data_fim DATE NULL AFTER data");

        // Cada gestor define o próprio expediente/almoço (o Kleber e o
        // Petronilho podem trabalhar em horários diferentes) -- usado pra
        // desenhar a régua de horas do Gantt e pra IA não sugerir tarefa fora
        // do expediente ou em cima do almoço. NULL = ainda não configurou,
        // aí a tela usa o padrão 08:00-18:00 / almoço 12:00-13:00.
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS agenda_expediente_inicio TIME NULL COMMENT 'Início do expediente pra régua de horas da Agenda (NULL = usa padrão 08:00)'");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS agenda_expediente_fim TIME NULL COMMENT 'Fim do expediente pra régua de horas da Agenda (NULL = usa padrão 18:00)'");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS agenda_almoco_inicio TIME NULL COMMENT 'Início do almoço pra régua de horas da Agenda (NULL = usa padrão 12:00)'");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS agenda_almoco_fim TIME NULL COMMENT 'Fim do almoço pra régua de horas da Agenda (NULL = usa padrão 13:00)'");

        // Pedido do Kleber: "pode-se atribuir também no gantt quem está
        // atendendo (Kleber/Petronilho/Caio)" -- cada tarefa agora pode ter
        // um responsável (um dos usuários de gestão), mostrado como avatar
        // no card. NULL só acontece pra tarefa antiga de antes dessa coluna
        // existir, ou se o responsável foi excluído depois (ON DELETE SET
        // NULL, não perde a tarefa).
        $pdo->exec("ALTER TABLE agenda_tarefas ADD COLUMN IF NOT EXISTS responsavel_id INT NULL AFTER chamado_id");
        if (!$pdo->query("SHOW KEYS FROM agenda_tarefas WHERE Key_name = 'fk_agenda_tarefas_responsavel'")->fetch()) {
            $pdo->exec('ALTER TABLE agenda_tarefas ADD CONSTRAINT fk_agenda_tarefas_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL');
        }

        // Análise automática de chamado novo pela IA (ver src/IaChamados.php)
        // -- uma linha por chamado: primeiro procura chamados parecidos já
        // resolvidos na nossa base, e só se não achar nada pesquisa na
        // internet (TDN da TOTVS / User Function). Guarda tokens gastos e se
        // usou busca, pra controlar custo e respeitar os limites diários.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chamado_ia_analises (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chamado_id INT NOT NULL,
                status ENUM('pendente','processando','sugerida','enviada','sem_resposta','erro','ignorada') NOT NULL DEFAULT 'pendente',
                fonte ENUM('base','internet') NULL COMMENT 'De onde veio a resposta: chamados já resolvidos ou busca na internet',
                resposta TEXT NULL,
                chamados_referencia VARCHAR(255) NULL COMMENT 'Ids dos chamados da base usados como referência, separados por vírgula',
                links TEXT NULL COMMENT 'JSON com as páginas consultadas na busca',
                erro VARCHAR(500) NULL,
                tokens_entrada INT NOT NULL DEFAULT 0,
                tokens_saida INT NOT NULL DEFAULT 0,
                usou_busca TINYINT(1) NOT NULL DEFAULT 0,
                resposta_id INT NULL COMMENT 'Resposta postada no chamado (modo automático) -- nunca é reaproveitada como referência',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ia_chamado (chamado_id),
                CONSTRAINT fk_ia_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // 'conversa' = botão "Sugerir resposta" sem nada na base/internet:
        // resposta escrita só com o que já está no próprio chamado.
        $colunaFonteIa = $pdo->query("SHOW COLUMNS FROM chamado_ia_analises LIKE 'fonte'")->fetch();
        if ($colunaFonteIa && stripos($colunaFonteIa['Type'], 'conversa') === false) {
            $pdo->exec("ALTER TABLE chamado_ia_analises MODIFY COLUMN fonte ENUM('base','internet','conversa') NULL");
        }
        $pdo->exec("ALTER TABLE chamado_ia_analises ADD COLUMN IF NOT EXISTS status_sugerido VARCHAR(20) NULL COMMENT 'Status que a IA escolheu pro chamado depois da resposta' AFTER fonte");
        // Pedido do Kleber (06/11/2026): IA respondendo os chamados sozinha.
        // A tela de Configurações tinha gravado 'sugestao' (padrão do campo)
        // quando a chave foi salva -- liga o automático UMA vez; depois disso
        // quem manda é só a tela (dá pra voltar pra sugestão quando quiser).
        if (!$pdo->query("SELECT 1 FROM configuracoes WHERE chave = 'ia_chamados_modo_auto_ligado'")->fetchColumn()) {
            $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('ia_chamados_modo', 'automatico') ON DUPLICATE KEY UPDATE valor = IF(valor = 'desligado', valor, 'automatico')");
            $pdo->exec("INSERT INTO configuracoes (chave, valor) VALUES ('ia_chamados_modo_auto_ligado', '1')");
        }
        $pdo->exec("ALTER TABLE chamado_ia_analises ADD COLUMN IF NOT EXISTS estado MEDIUMTEXT NULL COMMENT 'JSON com a etapa atual da análise e o que já foi levantado (termos, artigos, imagens)' AFTER erro");
        $pdo->exec("ALTER TABLE chamado_ia_analises ADD COLUMN IF NOT EXISTS progresso MEDIUMTEXT NULL COMMENT 'JSON com o passo a passo da análise (mostrado ao vivo na tela)' AFTER erro");
        $pdo->exec("ALTER TABLE chamado_ia_analises ADD COLUMN IF NOT EXISTS tentativas TINYINT NOT NULL DEFAULT 0 COMMENT 'Quantas vezes já tentou (falha passageira do Google volta pra fila até 3x)' AFTER usou_busca");
    }

    /**
     * Apaga pedaço de upload de vídeo aula (ver upload_video_aula_chunk.php)
     * abandonado -- alguém que fechou a aba ou perdeu a conexão no meio de
     * um envio deixa pedaços parados em uploads/tmp_chunks/ pra sempre, já
     * que só o ÚLTIMO pedaço aciona a limpeza dos anteriores. NÃO roda em
     * Migrations::run() (isso executa em TODA requisição do site inteiro --
     * varrer um diretório a cada request só por causa de uma feature bem
     * específica seria desperdício); o próprio endpoint de upload chama
     * isso só no PRIMEIRO pedaço de um novo envio, que já é bem mais raro.
     */
    public static function limparPedacosVideoAulaAbandonados(string $raizUploads): void
    {
        $dirTemp = rtrim($raizUploads, '/') . '/tmp_chunks';
        if (!is_dir($dirTemp)) {
            return;
        }
        $limiteAntigo = time() - 24 * 3600;
        foreach (glob($dirTemp . '/*') ?: [] as $arquivo) {
            if (is_file($arquivo) && filemtime($arquivo) < $limiteAntigo) {
                @unlink($arquivo);
            }
        }
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
     * Backfill idempotente: joga o setor_id (único) que cada responsável e
     * cada master já tinham pras tabelas novas de muitos-para-muitos
     * (setor_responsavel_setores / cliente_setores), pra quem já estava
     * relacionado a um setor não "perder" esse vínculo quando a tela de
     * Usuários passar a somar mais setores em cima dele. INSERT ... ON
     * DUPLICATE KEY UPDATE = no-op faz rodar em toda requisição sem duplicar.
     */
    private static function migrarSetorUnicoParaMuitosParaMuitos(PDO $pdo): void
    {
        $pdo->exec("
            INSERT INTO setor_responsavel_setores (setor_responsavel_id, setor_id)
            SELECT id, setor_id FROM setor_responsaveis WHERE setor_id IS NOT NULL
            ON DUPLICATE KEY UPDATE setor_responsavel_id = setor_responsavel_id
        ");
        $pdo->exec("
            INSERT INTO cliente_setores (cliente_id, setor_id)
            SELECT id, setor_id FROM clientes WHERE setor_id IS NOT NULL
            ON DUPLICATE KEY UPDATE cliente_id = cliente_id
        ");
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
