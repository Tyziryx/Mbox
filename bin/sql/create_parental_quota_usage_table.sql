-- Table de suivi quota journalier par appareil (volume de donnees)
-- Base cible: db_box (ou la base definie par PARENTAL_STATS_DB_NAME)

CREATE TABLE IF NOT EXISTS parental_quota_usage (
    mac VARCHAR(17) NOT NULL,
    device_name VARCHAR(255) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) DEFAULT NULL,
    quota_gb DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    usage_date DATE NOT NULL DEFAULT '1970-01-01',
    used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    remaining_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_counter_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    blocked_by_quota TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (mac),
    KEY idx_usage_date (usage_date),
    KEY idx_blocked_by_quota (blocked_by_quota),
    KEY idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SHOW TABLES LIKE 'parental_quota_usage';
DESCRIBE parental_quota_usage;
