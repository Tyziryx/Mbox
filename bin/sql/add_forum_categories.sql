-- Migration forum: categories + rattachement des sujets existants
-- Usage: sudo mysql box_db < /var/www/html/bin/sql/add_forum_categories.sql

CREATE TABLE IF NOT EXISTS forum_categories (
    id INT(11) NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT(11) NOT NULL DEFAULT 100,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forum_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO forum_categories (slug, name, sort_order) VALUES
    ('general', 'General', 10),
    ('controle-parental', 'Controle parental', 20),
    ('reseau', 'Reseau', 30),
    ('telephonie', 'Telephonie', 40),
    ('autre', 'Autre', 50)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    sort_order = VALUES(sort_order);

SET @has_category_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'forum_topics'
      AND COLUMN_NAME = 'category_id'
);
SET @sql := IF(
    @has_category_col = 0,
    'ALTER TABLE forum_topics ADD COLUMN category_id INT(11) NULL AFTER author',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_category_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'forum_topics'
      AND INDEX_NAME = 'idx_forum_topics_category_id'
);
SET @sql := IF(
    @has_category_idx = 0,
    'ALTER TABLE forum_topics ADD INDEX idx_forum_topics_category_id (category_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE forum_topics t
LEFT JOIN forum_categories c ON t.category_id = c.id
SET t.category_id = NULL
WHERE t.category_id IS NOT NULL
  AND c.id IS NULL;

UPDATE forum_topics t
JOIN forum_categories c ON c.slug = 'general'
SET t.category_id = c.id
WHERE t.category_id IS NULL;

SET @has_fk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'forum_topics'
      AND CONSTRAINT_NAME = 'fk_forum_topics_category'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
    @has_fk = 0,
    'ALTER TABLE forum_topics ADD CONSTRAINT fk_forum_topics_category FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;