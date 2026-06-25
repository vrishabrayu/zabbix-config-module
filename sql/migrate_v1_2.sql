-- ============================================================
-- Config Manager v1.2 Migration
-- Compatible with MySQL 5.7+ and MySQL 8.0+
--
-- Run:
--   mysql -u <user> -p <zabbix_db> < sql/migrate_v1_2.sql
-- ============================================================

SET @dbname = DATABASE();

-- ── config_templates ─────────────────────────────────────────
SET @tbl1 = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'config_templates'
);

SET @sql1 = IF(@tbl1 = 0,
    "CREATE TABLE `config_templates` (
        `template_id`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `name`             VARCHAR(128)  NOT NULL,
        `category`         VARCHAR(64)   NOT NULL DEFAULT 'General',
        `description`      TEXT,
        `template_content` LONGTEXT      NOT NULL,
        `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`template_id`),
        UNIQUE KEY `uq_template_name` (`name`),
        KEY `idx_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "SELECT 'config_templates already exists' AS status"
);
PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;

-- ── config_push_history ──────────────────────────────────────
SET @tbl2 = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'config_push_history'
);

SET @sql2 = IF(@tbl2 = 0,
    "CREATE TABLE `config_push_history` (
        `push_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id`      INT UNSIGNED NOT NULL,
        `zabbix_user`    VARCHAR(128) NOT NULL DEFAULT 'system',
        `push_type`      ENUM('manual','template','file','restore','bulk') NOT NULL DEFAULT 'manual',
        `template_id`    INT UNSIGNED NULL,
        `commands`       LONGTEXT     NOT NULL,
        `status`         ENUM('success','failed','dry_run') NOT NULL DEFAULT 'success',
        `output`         LONGTEXT,
        `execution_time` DECIMAL(8,3) NOT NULL DEFAULT 0,
        `pre_backup_id`  INT UNSIGNED NULL COMMENT 'Backup taken before this push',
        `dry_run`        TINYINT(1)   NOT NULL DEFAULT 0,
        `pushed_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`push_id`),
        KEY `idx_device_id`  (`device_id`),
        KEY `idx_pushed_at`  (`pushed_at`),
        KEY `idx_push_type`  (`push_type`),
        CONSTRAINT `fk_push_device`
            FOREIGN KEY (`device_id`) REFERENCES `config_devices` (`device_id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "SELECT 'config_push_history already exists' AS status"
);
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

SELECT 'Migration v1.2 complete.' AS status;
