-- ==========================================================================
-- Financial Tracker — schema.
-- Target: MariaDB 10.11, utf8mb4.
--
-- Applied by:
--   1. /docker-entrypoint-initdb.d/schema.sql  (fresh Docker volume — DEV only)
--   2. install.php                             (the web installer — UNIVERSAL)
--   3. database/migrations/*.sql               (by hand — never automatic)
--
-- This file is the single source of truth for 1 and 2.
--
-- HOW TO LOAD IT
--   Load the file AS A WHOLE, on ONE connection. The database selection below
--   uses session variables, so splitting the file per statement loses them:
--
--     mariadb < database/schema.sql                 # no database selected
--     mariadb financial_tracker < database/schema.sql
--     docker compose exec -T db mariadb -u root -p"$MYSQL_ROOT_PASSWORD" \
--         financial_tracker < database/schema.sql
--
--   install.php reads the file with file_get_contents() and hands the whole
--   string to its own PDO handle ($pdo->exec($sql)). PDO's multi-statement
--   mode is on by default in pdo_mysql, so the file runs as written.
--
--   MariaDB DDL implicitly commits, so there is no START TRANSACTION here and
--   wrapping the load in one buys nothing: a half-applied schema is the only
--   outcome a transaction could not improve.
--
-- WHAT THIS FILE MUST NOT CONTAIN
--   DROP TABLE, TRUNCATE — the installer loads this file against a live
--   database; it must never destroy data.
--   INSERT seed rows — every row belongs to a user. There is no global seed.
--
-- THE RULES THIS FILE ENCODES
--   * Money is DECIMAL(14,2). Never a float column, never integer cents.
--   * Income and spending share one table, separated by `type`. A report then
--     sums both sides in a single pass.
--   * `transactions.user_id` is the ownership boundary: every read and every
--     write filters on it, in SQL.
--   * `transactions.category_id` is nullable with ON DELETE SET NULL, so
--     deleting a category never deletes its transactions.
--   * `occurred_on` (DATE, UTC) is the only column a report groups on.
--     `created_at` is an audit stamp and must never be used for periods.
--   * No `role` column: this is a personal install with one kind of account.
-- ==========================================================================


-- --------------------------------------------------------------------------
-- Database selection.
--
-- The name is resolved at RUN TIME, never hard-coded:
--
--   DATABASE()            the database the loader is already connected to —
--                          install.php connects to the name the operator typed
--                          into the wizard, so the tables land there.
--   'financial_tracker'   the fallback when the loader has no database
--                          selected (a bare `mariadb < schema.sql`).
--
-- The statements are built with CONCAT and run through PREPARE/EXECUTE
-- because the name is not known until the file runs. MariaDB 10.11 accepts
-- `USE` in a prepared statement (verified against the dev image); MySQL
-- does not, and this project targets MariaDB 10.11.
--
-- The CREATE DATABASE step is skipped when the database already exists: the
-- account the installer connects with usually has rights on its own database
-- only, and a CREATE against an existing name would raise "access denied" and
-- abort the rest of the file. The installer creates the database itself when
-- it is missing.
-- --------------------------------------------------------------------------
SET @ft_db := IFNULL(DATABASE(), 'financial_tracker');

SET @ft_ddl := IF(
    (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = @ft_db) = 0,
    CONCAT('CREATE DATABASE IF NOT EXISTS `', @ft_db, '`',
           ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),
    'DO 0'
);
PREPARE ft_stmt FROM @ft_ddl;
EXECUTE ft_stmt;
DEALLOCATE PREPARE ft_stmt;

SET @ft_ddl := CONCAT('USE `', @ft_db, '`');
PREPARE ft_stmt FROM @ft_ddl;
EXECUTE ft_stmt;
DEALLOCATE PREPARE ft_stmt;


-- --------------------------------------------------------------------------
-- users — the accounts.
--
--   username / email   NOT NULL UNIQUE. The register screen rejects each of
--                      them separately, so the uniqueness is a database
--                      guarantee and not only a PHP check.
--   email              stored lowercased by the application, so the unique
--                      index is case-insensitive in effect.
--   password_hash      password_hash() output. VARCHAR(255), not 60: the
--                      algorithm may change and the column must not have to.
--   status             'disabled' is the "This account is not available."
--                      state on the sign-in screen.
--
-- There are NO throttle columns on the account. They were there from
-- issue-016 and they were never read: the counter that decides the wait
-- lives in login_attempts, against the identifier and against the source,
-- so the columns on the account were a second copy of a number nothing
-- looked at — and a password change "cleared" them, promising an unlock
-- it could not perform (issue-021 §6 and §8.4).
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`          VARCHAR(32)  NOT NULL,
    `email`             VARCHAR(254) NOT NULL,
    `password_hash`     VARCHAR(255) NOT NULL,
    `email_verified_at` DATETIME     NULL DEFAULT NULL,
    `status`            ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------------
-- login_attempts — the sign-in throttle: TWO counters, one table.
--
--   scope             'identifier' or 'source'. The scope says what the
--                    key is, and the two counters are independent: the
--                    effective penalty is the STRICTER of them, never the
--                    sum (issue-021 §8.1).
--   key_hash          sha256 of the lowercased sign-in identifier (a
--                    username or an email address) for the 'identifier'
--                    scope, and sha256 of REMOTE_ADDR for the 'source'
--                    scope. The hash is the key: it is a fixed length, it
--                    is case-insensitive by construction, and the table
--                    never stores an address that was typed at the form.
--   failed_attempts  the number of failures in the current window.
--   locked_until     when the counter reached the threshold, the moment the
--                    wait ends. NULL while the counter is not serving a
--                    wait.
--   last_failure_at  when the last failure was recorded. The quiet window
--                    is measured from here: a failure older than the
--                    window does not count, which is what lets the owner
--                    of an account sign in again after the attacker stops
--                    (issue-021 §3 and §8.1).
--
-- WHY A TABLE OF ITS OWN: the throttle has to cover an identifier that
-- matches NO account. A counter on the account could only lock an account
-- that exists, so a visitor who types a name that does not exist five
-- times would never be throttled — the limit would be bypassable by using
-- a non-existent name (issue-016 §6).
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope`           ENUM('identifier','source') NOT NULL DEFAULT 'identifier',
    `key_hash`        CHAR(64)     NOT NULL,
    `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`    DATETIME     NULL DEFAULT NULL,
    `last_failure_at` DATETIME     NULL DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_login_attempts_scope_key` (`scope`,`key_hash`),
    KEY `idx_login_attempts_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------------
-- categories — the per-user labels.
--
--   user_id  FK -> users.id, ON DELETE CASCADE. The account goes, its
--            categories go with it.
--   name     unique PER USER (uq_categories_user_name), not globally: two
--            accounts may both have a category called "Food".
--   type     a category belongs to one side only. This is what lets the
--            "by category" report group income and spending apart.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(60)  NOT NULL,
    `type`       ENUM('income','expense') NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_user_name` (`user_id`, `name`),
    KEY `idx_categories_user` (`user_id`),
    CONSTRAINT `fk_categories_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------------
-- transactions — the ledger. Income and spending share this table.
--
--   user_id     FK -> users.id, ON DELETE CASCADE. THE OWNERSHIP BOUNDARY:
--               every query filters on it, in SQL.
--   category_id FK -> categories.id, ON DELETE SET NULL. Nullable: a
--               transaction without a category is valid, and deleting a
--               category leaves its transactions in place, uncategorised.
--   type        exactly two values. There is no 'credit' and no 'debit'.
--   amount      DECIMAL(14,2). Always POSITIVE — the direction is in `type`.
--               The server rejects a zero or negative amount; there is no
--               CHECK constraint, because the rule is the application's.
--   occurred_on the business date, UTC. This is the column every report
--               groups on — never created_at.
--   description free text, may be NULL.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED  NOT NULL,
    `category_id` INT UNSIGNED  NULL DEFAULT NULL,
    `type`        ENUM('income','expense') NOT NULL,
    `amount`      DECIMAL(14,2) NOT NULL,
    `occurred_on` DATE          NOT NULL,
    `description` VARCHAR(255)  NULL DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tx_user_date` (`user_id`, `occurred_on`),
    KEY `idx_tx_user_type` (`user_id`, `type`, `occurred_on`),
    KEY `idx_tx_user_cat` (`user_id`, `category_id`, `occurred_on`),
    KEY `idx_tx_occurred_on` (`occurred_on`),
    CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_transactions_category` FOREIGN KEY (`category_id`)
        REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------------
-- password_resets — the single-use reset token.
--
--   token_hash  the SHA-256 HASH of the token, never the token itself. The
--               raw token goes in the email link, so a database dump does not
--               yield a working reset link. CHAR(64) is the SHA-256 length.
--   expires_at  created + 1 hour.
--   used_at     set when the token is redeemed. A token is single-use: the
--               lookup filters on `used_at IS NULL` and `expires_at > NOW()`.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64)     NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used_at`    DATETIME     NULL DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_reset_token` (`token_hash`),
    KEY `idx_reset_user` (`user_id`, `expires_at`),
    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------------
-- email_verifications — the registration confirmation token.
--
-- The same shape as password_resets, kept as a second table on purpose: two
-- tables are easier to reason about than one table with a `purpose` column,
-- and neither of them is large.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64)     NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used_at`    DATETIME     NULL DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_verification_token` (`token_hash`),
    KEY `idx_verification_user` (`user_id`, `expires_at`),
    CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================================================================
-- The tables:
--
--   users               the accounts
--   login_attempts      the sign-in throttle, counted per identifier
--   categories          the per-user labels (income | expense)
--   transactions        the ledger — income and spending in one table
--   password_resets     the single-use password reset tokens (hash only)
--   email_verifications the single-use confirmation tokens (hash only)
--
-- There is NO "uncategorised" row. An uncategorised transaction is a
-- transaction whose `category_id IS NULL` — that is what deleting a category
-- leaves behind. The transactions are never deleted with it.
--
-- There is NO sessions table: PHP's file-based session handler is enough for
-- a single-server personal install.
--
-- There is NO summary table: the day / week / month / year rollups are
-- computed in SQL from `occurred_on`.
-- ==========================================================================
