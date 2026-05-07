-- Table de stats pour l'aide catégories du contrôle parental
-- Exécuter sur la base MBox (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS parental_category_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mode VARCHAR(16) NOT NULL,
    category VARCHAR(32) NOT NULL,
    count_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mode_category (mode, category),
    KEY idx_mode (mode),
    KEY idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed initial minimal (idempotent)
INSERT INTO parental_category_stats (mode, category, count_value)
VALUES
('child', 'adult', 3),
('child', 'games', 2),
('child', 'streaming', 2),
('child', 'social', 2),
('child', 'ads', 3),
('teen', 'adult', 2),
('teen', 'games', 3),
('teen', 'streaming', 3),
('teen', 'social', 3),
('teen', 'ads', 2),
('adult', 'adult', 1),
('adult', 'games', 1),
('adult', 'streaming', 1),
('adult', 'social', 1),
('adult', 'ads', 1)
ON DUPLICATE KEY UPDATE count_value = count_value;
