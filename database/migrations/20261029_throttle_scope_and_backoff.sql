-- Financial Tracker — migration: the throttle scope, the decay, and the backoff.
-- Purpose:  reshape the sign-in throttle (issue-021 §8) so that a hard lock
--           cannot be used as a denial-of-service weapon.
--
--           1. login_attempts gains a `scope` column, so the table holds TWO
--              independent counters: 'identifier' (the failures against one
--              sign-in identifier — the defence against a password guesser)
--              and 'source' (the failures from one source address — the
--              defence against an attacker who rotates the identifier).
--              The effective penalty is the STRICTER of the two, never the
--              sum.
--           2. `identifier_hash` is renamed `key_hash`, because a source
--              hash now lives in the column too.
--           3. `last_failure_at` is added: the quiet window is measured from
--              it, and a failure older than the window stops counting. That
--              is what lets the owner of an account sign in again after the
--              attacker stops.
--           4. The unique key becomes (scope, key_hash), so one identifier
--              and one source address can each hold a row.
--           5. users.failed_attempts and users.locked_until are DROPPED.
--              They were a second copy of a number nothing ever read — the
--              authoritative counter has always been in login_attempts —
--              and a password change "cleared" them, promising an unlock it
--              could not perform.
--
-- Run:      docker compose exec -T db sh -c \
--              'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
--              < database/migrations/20261029_throttle_scope_and_backoff.sql
--
-- MariaDB DDL implicitly commits, so there is no START TRANSACTION here:
-- a half-applied migration is the only outcome a transaction could not have
-- improved.
--
-- Idempotent: re-running this file changes nothing.
--
-- The order matters: the scope column is added and backfilled to
-- 'identifier' BEFORE the unique key is swapped, so the new key never
-- collides with the old one.

-- 1. The scope column.
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
      AND COLUMN_NAME = 'scope'
);
SET @ddl := IF(@column_exists = 0,
    'ALTER TABLE `login_attempts`
        ADD COLUMN `scope` ENUM(''identifier'',''source'') NOT NULL DEFAULT ''identifier'' AFTER `id`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. identifier_hash -> key_hash.
SET @old_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
      AND COLUMN_NAME = 'identifier_hash'
);
SET @new_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
      AND COLUMN_NAME = 'key_hash'
);
SET @ddl := IF(@old_exists = 1 AND @new_exists = 0,
    'ALTER TABLE `login_attempts` CHANGE COLUMN `identifier_hash` `key_hash` CHAR(64) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. The quiet window needs a timestamp to be measured from.
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
      AND COLUMN_NAME = 'last_failure_at'
);
SET @ddl := IF(@column_exists = 0,
    'ALTER TABLE `login_attempts`
        ADD COLUMN `last_failure_at` DATETIME NULL DEFAULT NULL AFTER `locked_until`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4a. Drop the old single-column unique key.
SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
      AND INDEX_NAME = 'uq_login_attempts_identifier'
);
SET @ddl := IF(@index_exists = 1,
    'ALTER TABLE `login_attempts` DROP INDEX `uq_login_attempts_identifier`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4b. The unique key now covers the scope and the key together.
SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
      AND INDEX_NAME = 'uq_login_attempts_scope_key'
);
SET @ddl := IF(@index_exists = 0,
    'ALTER TABLE `login_attempts` ADD UNIQUE KEY `uq_login_attempts_scope_key` (`scope`,`key_hash`)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5a. The account copy of the counter goes.
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'failed_attempts'
);
SET @ddl := IF(@column_exists = 1,
    'ALTER TABLE `users` DROP COLUMN `failed_attempts`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5b. And with it the lock column nothing ever read.
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'locked_until'
);
SET @ddl := IF(@column_exists = 1,
    'ALTER TABLE `users` DROP COLUMN `locked_until`',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
