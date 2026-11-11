-- Archive Desk database schema
-- Import this file in phpMyAdmin (XAMPP) or run it with the MySQL command line.
CREATE DATABASE IF NOT EXISTS archive_app
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE archive_app;

CREATE TABLE IF NOT EXISTS categories (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_name (category_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS archives (
    archive_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    file_size_kb INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (archive_id),
    KEY idx_archives_category (category_id),
    KEY idx_archives_created (created_at),
    CONSTRAINT fk_archives_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Seed the exact supported categories. Existing records are reassigned before
-- legacy categories are removed, so re-importing this schema preserves archives.
INSERT IGNORE INTO categories (category_name) VALUES
    ('Documents'),
    ('Photos'),
    ('Reports');

-- Keep obvious report records in Reports; archive other legacy categories under
-- Documents. Existing records already in Photos remain assigned there.
UPDATE archives AS a
INNER JOIN categories AS old_category ON old_category.category_id = a.category_id
INNER JOIN categories AS new_category
    ON new_category.category_name = CASE
        WHEN old_category.category_name = 'Reports' THEN 'Reports'
        WHEN old_category.category_name = 'Photos' THEN 'Photos'
        ELSE 'Documents'
    END
SET a.category_id = new_category.category_id
WHERE old_category.category_name NOT IN ('Documents', 'Photos', 'Reports');

DELETE FROM categories
WHERE category_name NOT IN ('Documents', 'Photos', 'Reports');
