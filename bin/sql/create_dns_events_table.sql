USE db_box;

CREATE TABLE IF NOT EXISTS dns_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_hash CHAR(32) NOT NULL,
    event_type ENUM('blocked','seen') NOT NULL,
    domain VARCHAR(255) NOT NULL,
    ip_source VARCHAR(45) DEFAULT NULL,
    mac_source VARCHAR(32) DEFAULT NULL,
    device_name VARCHAR(255) DEFAULT NULL,
    reason_code VARCHAR(100) DEFAULT NULL,
    matched_domain VARCHAR(255) DEFAULT NULL,
    distance INT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    inserted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_hash (event_hash),
    KEY idx_type_created (event_type, created_at),
    KEY idx_domain (domain),
    KEY idx_ip (ip_source),
    KEY idx_mac (mac_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SHOW TABLES LIKE 'dns_events';
DESCRIBE dns_events;
