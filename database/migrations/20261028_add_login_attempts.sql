-- Financial Tracker — migration: add the login_attempts table.
-- Purpose:  give the sign-in throttle (issue-016 §6) ONE place to count the
--           failures of an IDENTIFIER, so the limit also covers a username
--           or an email address that matches no account. The users row can
--           only be locked when the account exists; without this table the
--           throttle is bypassed by typing a name that does not exist.
-- Run:      docker compose exec -T db sh -c \
--              'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
--              < database/migrations/20261028_add_login_attempts.sql
--
-- MariaDB DDL implicitly commits, so there is no START TRANSACTION here:
-- a half-applied migration is the only outcome a transaction could not have
-- improved.
--
-- Idempotent: re-running this file changes nothing.
--
-- The table:
--
--   identifier_hash  sha256 of the lowercased sign-in identifier. The hash
--                    is the key rather than the identifier itself: it is a
--                    fixed length, it is case-insensitive by construction,
--                    and the table never stores an address that was typed at
--                    the sign-in form.
--   failed_attempts  the number of failures in the current window.
--   locked_until     when the counter reached the threshold, the moment the
--                    wait ends. NULL while the identifier is not locked.
--
-- The column names match the users columns on purpose: the same counter,
-- the same vocabulary, one mechanism applied to both cases.

SET @table_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
);
SET @ddl := IF(@table_exists = 0,
    'CREATE TABLE IF NOT EXISTS `login_attempts` (
         `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
         `identifier_hash` CHAR(64)     NOT NULL,
         `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
         `locked_until`    DATETIME     NULL DEFAULT NULL,
         `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         UNIQUE KEY `uq_login_attempts_identifier` (`identifier_hash`),
         KEY `idx_login_attempts_updated` (`updated_at`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
