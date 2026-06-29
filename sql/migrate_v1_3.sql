-- ============================================================
-- Config Manager v1.3 Migration — SSH Terminal
-- Compatible with MySQL 5.7+ and MySQL 8.0+
-- Run: mysql -u <user> -p <db> < sql/migrate_v1_3.sql
-- ============================================================

SET @dbname = DATABASE();

-- ── config_ssh_tokens (one-time auth tokens) ─────────────────
SET @t1 = (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='config_ssh_tokens');
SET @s1 = IF(@t1=0,
    "CREATE TABLE `config_ssh_tokens` (
        `token_id`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `token`       CHAR(64)      NOT NULL,
        `device_id`   INT UNSIGNED  NOT NULL,
        `zabbix_user` VARCHAR(128)  NOT NULL DEFAULT 'admin',
        `used`        TINYINT(1)    NOT NULL DEFAULT 0,
        `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at`  DATETIME      NOT NULL,
        PRIMARY KEY (`token_id`),
        UNIQUE KEY `uq_token` (`token`),
        KEY `idx_device_id`  (`device_id`),
        KEY `idx_expires_at` (`expires_at`),
        CONSTRAINT `fk_token_device`
            FOREIGN KEY (`device_id`) REFERENCES `config_devices` (`device_id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "SELECT 'config_ssh_tokens already exists' AS status");
PREPARE s1 FROM @s1; EXECUTE s1; DEALLOCATE PREPARE s1;

-- ── config_ssh_sessions (audit log) ──────────────────────────
SET @t2 = (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='config_ssh_sessions');
SET @s2 = IF(@t2=0,
    "CREATE TABLE `config_ssh_sessions` (
        `session_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id`    INT UNSIGNED NOT NULL,
        `zabbix_user`  VARCHAR(128) NOT NULL DEFAULT 'admin',
        `started_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `ended_at`     DATETIME     NULL,
        `duration_sec` INT          NOT NULL DEFAULT 0,
        `client_ip`    VARCHAR(45)  NOT NULL DEFAULT '',
        PRIMARY KEY (`session_id`),
        KEY `idx_device_id`  (`device_id`),
        KEY `idx_started_at` (`started_at`),
        CONSTRAINT `fk_sess_device`
            FOREIGN KEY (`device_id`) REFERENCES `config_devices` (`device_id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "SELECT 'config_ssh_sessions already exists' AS status");
PREPARE s2 FROM @s2; EXECUTE s2; DEALLOCATE PREPARE s2;

SELECT 'Migration v1.3 complete — SSH Terminal tables ready.' AS status;
