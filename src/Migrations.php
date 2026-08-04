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
                perfil ENUM('admin','colaborador') NOT NULL DEFAULT 'colaborador',
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

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
                cliente_id INT NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT NOT NULL,
                categoria VARCHAR(80) NULL,
                status ENUM('aberto','em_andamento','respondido','resolvido','fechado') NOT NULL DEFAULT 'aberto',
                prioridade ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
                aberto_por ENUM('cliente','equipe') NOT NULL DEFAULT 'cliente',
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
