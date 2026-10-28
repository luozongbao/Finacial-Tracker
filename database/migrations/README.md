# Migrations — `database/migrations/`

Every schema change that happens **after** a database already exists is applied
by a migration file in this folder. Migrations are **never** run automatically:

| Path | When it runs | Target |
|---|---|---|
| `database/schema.sql` (mounted at `/docker-entrypoint-initdb.d/schema.sql`) | Fresh volume only | Dev only |
| `install.php` (loads `schema.sql` during the wizard) | The web install wizard | Universal — the production path |
| `database/migrations/*.sql` applied by hand | Every upgrade | Both |

## Naming

````text
YYYYMMDD_short_name.sql
````

The date is the day the migration was written, not the day it was applied.

## Applying a migration by hand

````bash
docker compose exec -T db sh -c 'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  < database/migrations/20261027_add_category_id.sql
```

> The container defines `MARIADB_DATABASE` / `MARIADB_ROOT_PASSWORD` — the
> `mariadb:10.11` image has no `MYSQL_*` variables. Using the `MYSQL_*` names
> here yields an empty database name and an interactive password prompt.

## Rules

1. A header comment states the purpose and the **exact run command**.
2. The file is **idempotent** — it must be safe to re-run. Guard the DDL with
   an `information_schema` probe and a `PREPARE`/`EXECUTE` pair.
3. **No `START TRANSACTION`** — MariaDB DDL implicitly commits.
4. Backfills are deterministic (`ORDER BY id`) so a re-run reproduces identical
   values.
5. Nullable-first, `NOT NULL` later, when a backfill is required.

## The shape of a migration file

Every file carries a header block like this one — the purpose, the exact run
command, and the note that MariaDB DDL implicitly commits:

````sql
-- Financial Tracker — migration: add category_id to transactions.
-- Purpose:  let a transaction exist without a category.
-- Run:      docker compose exec -T db sh -c \
--              'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
--              < database/migrations/20261027_add_category_id.sql
--
-- MariaDB DDL implicitly commits, so there is no START TRANSACTION here:
-- a half-applied migration is the only outcome a transaction could not have
-- improved.
--
-- Idempotent: re-running this file changes nothing.
````

### The idempotency guard

The canonical pattern. Probe `information_schema` first, and run the DDL only
when the change is missing:

````sql
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'category_id'
);
SET @ddl := IF(@column_exists = 0,
    'ALTER TABLE transactions ADD COLUMN category_id INT NULL AFTER occurred_on',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
````

The same shape guards an index (`information_schema.STATISTICS`), a constraint
(`information_schema.TABLE_CONSTRAINTS`) and a table
(`information_schema.TABLES`).

> `Database::execute()` cannot run a migration file: `EMULATE_PREPARES` is
> off, so one call is one statement. Apply the file with the `mariadb` client,
> as the run command above does.

## The initial schema is not a migration

`database/schema.sql` is the starting state of every database — it is applied
by the fresh-volume path and by `install.php`, never by a migration. A
migration only exists for a change made **after** a database was created.

## Checklist for a schema change

1. Add the migration here.
2. **Mirror the change in `database/schema.sql`** — fresh installs read that file.
3. Add a DB-backed regression test that seeds via `Database::execute()` and
   cleans up in `try { } finally { }`.
4. Update column-specific tests only if you are renaming or dropping.

The conventions are specified in
[.github/instructions/database.instructions.md](../../.github/instructions/database.instructions.md).

> The MariaDB initdb mount points at `database/`, not at this folder. The
> entrypoint only processes the **top level** of the initdb directory, so the
> `.sql` files in here are never executed on a fresh volume.
