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
        $pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(255) NULL COMMENT 'Caminho da foto de perfil enviada pelo cliente, relativo à raiz do projeto' AFTER portal_ativo");

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
    }
}
