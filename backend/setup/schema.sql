-- =============================================
-- City085 Monitor Beta — Database Schema
-- MariaDB 11.4 | Fortaleza Urban Monitor
-- =============================================
-- Execute via phpMyAdmin ou terminal:
-- mysql -u mikaeldm_user -p mikaeldm_banco < schema.sql
-- =============================================

-- ===== Mensagens do Chat Público =====
CREATE TABLE IF NOT EXISTS city_messages (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(64) NOT NULL,
    role ENUM('user', 'assistant') NOT NULL DEFAULT 'user',
    content TEXT NOT NULL,
    neighborhood VARCHAR(100) DEFAULT NULL,
    ip_hash VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_created (created_at DESC),
    INDEX idx_neighborhood (neighborhood)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Tópicos extraídos das conversas =====
CREATE TABLE IF NOT EXISTS city_topics (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    message_id BIGINT NOT NULL,
    topic VARCHAR(255) NOT NULL,
    category ENUM(
        'seguranca', 'transito', 'saude', 'educacao', 
        'eventos', 'politica', 'clima', 'infraestrutura',
        'cultura', 'economia', 'meio_ambiente', 'esporte',
        'tecnologia', 'servicos_publicos', 'outros'
    ) NOT NULL DEFAULT 'outros',
    sentiment ENUM('positivo', 'negativo', 'neutro') DEFAULT 'neutro',
    relevance FLOAT DEFAULT 0.5,
    neighborhood VARCHAR(100) DEFAULT NULL,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_topic (topic),
    INDEX idx_category (category),
    INDEX idx_created (created_at DESC),
    INDEX idx_neighborhood (neighborhood),
    INDEX idx_relevance (relevance DESC),
    FOREIGN KEY (message_id) REFERENCES city_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Trending Topics Agregados (cache horário) =====
CREATE TABLE IF NOT EXISTS city_trending (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    topic VARCHAR(255) NOT NULL,
    category VARCHAR(50) NOT NULL,
    mention_count INT DEFAULT 1,
    avg_sentiment FLOAT DEFAULT 0,
    top_neighborhoods JSON DEFAULT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_period (period_start, period_end),
    INDEX idx_mentions (mention_count DESC),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Cache de Dados Públicos =====
CREATE TABLE IF NOT EXISTS city_open_data (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    source_key VARCHAR(100) NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    source_url TEXT,
    category VARCHAR(100) NOT NULL,
    data_json LONGTEXT NOT NULL,
    record_count INT DEFAULT 0,
    last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_source (source_key),
    INDEX idx_category (category),
    UNIQUE KEY uk_source (source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Eventos da Cidade =====
CREATE TABLE IF NOT EXISTS city_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(500) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL,
    source ENUM('chat', 'open_data', 'manual', 'weather_api', 'air_quality_api', 'holidays_api', 'seed', 'auto') DEFAULT 'chat',
    neighborhood VARCHAR(100) DEFAULT NULL,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    event_date DATETIME DEFAULT NULL,
    mention_count INT DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_active (is_active),
    INDEX idx_date (event_date),
    INDEX idx_neighborhood (neighborhood)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Log de Sincronização de Dados =====
CREATE TABLE IF NOT EXISTS city_sync_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source VARCHAR(100) NOT NULL,
    status ENUM('started', 'completed', 'failed') NOT NULL,
    records_fetched INT DEFAULT 0,
    message TEXT,
    duration_ms INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source (source),
    INDEX idx_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
