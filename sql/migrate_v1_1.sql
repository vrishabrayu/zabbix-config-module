-- ============================================================
-- Config Manager v1.1 Migration
-- Compatible with MySQL 5.7+ and MySQL 8.0+
--
-- Run:
--   mysql -u <user> -p <zabbix_db> < sql/migrate_v1_1.sql
-- ============================================================

-- Add schedule_interval column (skips if already exists)
SET @dbname = DATABASE();

SET @col1 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'config_devices'
      AND COLUMN_NAME  = 'schedule_interval'
);

SET @sql1 = IF(@col1 = 0,
    "ALTER TABLE `config_devices`
     ADD COLUMN `schedule_interval`
         ENUM('disabled','hourly','every_6h','every_12h','daily','weekly')
         NOT NULL DEFAULT 'disabled'
         COMMENT 'Auto-backup interval'
         AFTER `enabled`",
    "SELECT 'schedule_interval already exists, skipping.' AS status"
);

PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- Add next_run_at column (skips if already exists)
SET @col2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'config_devices'
      AND COLUMN_NAME  = 'next_run_at'
);

SET @sql2 = IF(@col2 = 0,
    "ALTER TABLE `config_devices`
     ADD COLUMN `next_run_at`
         DATETIME NULL
         COMMENT 'Next scheduled backup time'
         AFTER `schedule_interval`",
    "SELECT 'next_run_at already exists, skipping.' AS status"
);

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Add index (skips if already exists)
SET @idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'config_devices'
      AND INDEX_NAME   = 'idx_next_run'
);

SET @sql3 = IF(@idx = 0,
    "ALTER TABLE `config_devices`
     ADD INDEX `idx_next_run` (`next_run_at`, `enabled`)",
    "SELECT 'idx_next_run already exists, skipping.' AS status"
);

PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

SELECT 'Migration v1.1 complete.' AS status;
