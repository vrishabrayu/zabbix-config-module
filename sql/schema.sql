-- ============================================================
-- Config Manager – Database Schema
-- Run against your Zabbix database:
--   mysql -u <user> -p <zabbix_db> < sql/schema.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS `config_devices` (
    `device_id`     INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(128)    NOT NULL,
    `ip_address`    VARCHAR(45)     NOT NULL,
    `vendor`        ENUM('cisco_ios','cisco_nxos','fortinet','mikrotik','juniper') NOT NULL DEFAULT 'cisco_ios',
    `username`      VARCHAR(128)    NOT NULL,
    `password`      VARCHAR(512)    NOT NULL COMMENT 'AES-256 encrypted',
    `port`          SMALLINT        NOT NULL DEFAULT 22,
    `backup_method` ENUM('ssh','telnet') NOT NULL DEFAULT 'ssh',
    `enabled`           TINYINT(1)   NOT NULL DEFAULT 1,
    `schedule_interval` ENUM('disabled','hourly','every_6h','every_12h','daily','weekly') NOT NULL DEFAULT 'disabled' COMMENT 'Auto-backup interval',
    `next_run_at`       DATETIME NULL COMMENT 'Next scheduled backup time',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`device_id`),
    UNIQUE KEY `uq_device_name` (`name`),
    KEY `idx_vendor` (`vendor`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_next_run` (`next_run_at`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `config_backups` (
    `backup_id`     INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `device_id`     INT UNSIGNED    NOT NULL,
    `filename`      VARCHAR(256)    NOT NULL COMMENT 'e.g. 2026-06-20_0100.cfg',
    `filepath`      VARCHAR(512)    NOT NULL COMMENT 'Absolute path on disk',
    `file_size`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `sha256`        CHAR(64)        NOT NULL DEFAULT '',
    `status`        ENUM('success','failed','running') NOT NULL DEFAULT 'running',
    `error_message` TEXT,
    `backed_up_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`backup_id`),
    KEY `idx_device_id`    (`device_id`),
    KEY `idx_backed_up_at` (`backed_up_at`),
    KEY `idx_status`       (`status`),
    CONSTRAINT `fk_backups_device`
        FOREIGN KEY (`device_id`) REFERENCES `config_devices` (`device_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `config_changes` (
    `change_id`     INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `device_id`     INT UNSIGNED    NOT NULL,
    `backup_id_old` INT UNSIGNED,
    `backup_id_new` INT UNSIGNED    NOT NULL,
    `changed`       TINYINT(1)      NOT NULL DEFAULT 0,
    `lines_added`   SMALLINT        NOT NULL DEFAULT 0,
    `lines_removed` SMALLINT        NOT NULL DEFAULT 0,
    `detected_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`change_id`),
    KEY `idx_device_id`   (`device_id`),
    KEY `idx_detected_at` (`detected_at`),
    KEY `idx_changed`     (`changed`),
    CONSTRAINT `fk_changes_device`
        FOREIGN KEY (`device_id`) REFERENCES `config_devices` (`device_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_changes_backup_new`
        FOREIGN KEY (`backup_id_new`) REFERENCES `config_backups` (`backup_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


