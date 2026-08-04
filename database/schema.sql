-- AdminPrime - schema de referência
-- Este arquivo é apenas para consulta/backup manual. A aplicação cria essas
-- tabelas automaticamente na primeira execução (ver src/Migrations.php).

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('admin','colaborador') NOT NULL DEFAULT 'colaborador',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kpis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nome_kpi VARCHAR(150) NOT NULL,
    valor DECIMAL(12,2) NOT NULL,
    meta DECIMAL(12,2) NULL,
    unidade VARCHAR(30) NULL,
    periodo DATE NOT NULL,
    observacoes VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
