-- Financial Tracker — migration: the request throttle and the mail outbox.
-- Purpose:  add the two tables issue-022 needs.
--
--           1. request_throttles — the GENERIC request throttle. One row
--              per (bucket, key): the bucket says which limiter the row
--              belongs to ('reset:address', 'reset:source',
--              'register:source') and the key is the sha256 of what is
--              being counted (the lower-cased address, or the peer address
--              that really opened the connection). `hits` counts the hits
--              in the current window and `window_started_at` says when that
--              window opened, so a count that has aged out stops counting
--              and the row does not grow forever.
--
--           2. mail_outbox — the outgoing mail queue. The registration and
--              the forgot-password screens write here and answer the
--              visitor; a CLI worker sends the message afterwards. That is
--              what makes the two screens answer in the same time for a
--              known address and for an unknown one.
--
--           3. mail_outbox gains `next_attempt_at`, so a failed send is put
--              back with a BACKOFF instead of being retried at once. The
--              step below adds the column to a table that an earlier run
--              of this file already created, so the file stays idempotent
--              in both directions.
--
-- Run:      docker compose exec -T db sh -c \
--              'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
--              < database/migrations/20261030_mail_outbox_and_request_throttles.sql
--
-- MariaDB DDL implicitly commits, so there is no START TRANSACTION here:
-- a half-applied migration is the only outcome a transaction could not have
-- improved.
--
-- Idempotent: re-running this file changes nothing.
--
-- The columns and the keys are the ones database/schema.sql declares — the
-- schema file is the single source of truth, and this file mirrors it.

-- 1. request_throttles.
SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'request_throttles'
);
SET @ddl := IF(@table_exists = 0,
    'CREATE TABLE IF NOT EXISTS `request_throttles` (
        `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bucket`            VARCHAR(32)  NOT NULL,
        `key_hash`          CHAR(64)     NOT NULL,
        `hits`              INT UNSIGNED NOT NULL DEFAULT 0,
        `window_started_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_request_throttles_bucket_key` (`bucket`,`key_hash`),
        KEY `idx_request_throttles_window` (`window_started_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. mail_outbox.
SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'mail_outbox'
);
SET @ddl := IF(@table_exists = 0,
    'CREATE TABLE IF NOT EXISTS `mail_outbox` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `to_address` VARCHAR(254) NOT NULL,
        `subject`    VARCHAR(255) NOT NULL,
        `body`       TEXT         NOT NULL,
        `status`     ENUM(''queued'',''sent'',''failed'') NOT NULL DEFAULT ''queued'',
        `attempts`   INT UNSIGNED NOT NULL DEFAULT 0,
        `last_error` VARCHAR(255) NULL DEFAULT NULL,
        `claimed_at` DATETIME     NULL DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `sent_at`    DATETIME     NULL DEFAULT NULL,
        `next_attempt_at` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_mail_outbox_status_created` (`status`,`created_at`),
        KEY `idx_mail_outbox_claimed` (`claimed_at`),
        KEY `idx_mail_outbox_next_attempt` (`status`,`next_attempt_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3a. The retry column, for a table this file created before it had one.
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'mail_outbox'
      AND COLUMN_NAME = 'next_attempt_at'
);
SET @ddl := IF(@column_exists = 0,
    'ALTER TABLE `mail_outbox`
        ADD COLUMN `next_attempt_at` DATETIME NULL DEFAULT NULL AFTER `sent_at`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3b. The index the worker's claim query uses.
SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'mail_outbox'
      AND INDEX_NAME = 'idx_mail_outbox_next_attempt'
);
SET @ddl := IF(@index_exists = 0,
    'ALTER TABLE `mail_outbox`
        ADD KEY `idx_mail_outbox_next_attempt` (`status`,`next_attempt_at`)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
