---
description: "Use when writing a SQL migration in database/migrations/, editing database/schema.sql, or changing the PDO wrapper in includes/Database.php. Covers the idempotency guard pattern, the four-step schema-change checklist, the money-as-DECIMAL rule, and the PDO pitfalls specific to this project."
applyTo: ["database/migrations/**", "database/schema.sql", "includes/Database.php"]
---

# Database conventions

MariaDB 10.11, `utf8mb4`, accessed through the PDO singleton in `includes/Database.php`.

## `Database` API

| Method | Returns |
|---|---|
| `query($sql, $params = [])` | `PDOStatement` |
| `fetchAll($sql, $params = [])` | array of assoc rows |
| `fetchOne($sql, $params = [])` | one assoc row or `false` |
| `execute($sql, $params = [])` | **bool — not a row count** |
| `lastInsertId()` | int |
| `getConnection()` | the raw `PDO` handle |

Connection settings: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, **`EMULATE_PREPARES = false`**, and
`SET time_zone = '+00:00'` — the DB stores UTC, so convert in PHP.

## Migration pattern

Every file in `database/migrations/` follows the same shape:

1. A header comment stating the purpose and the **exact run command**.
2. An idempotency guard — the file must be safe to re-run:

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

3. **No `START TRANSACTION`** — MariaDB DDL implicitly commits. Say so in the header.
4. Backfills are deterministic (`ORDER BY id`) so a re-run reproduces identical values.
5. Nullable-first, `NOT NULL` later, when a backfill is required.

## Schema-change checklist

Follow all four steps, in order:

1. Add the migration in `database/migrations/YYYYMMDD_short_name.sql`.
2. **Mirror the change in `database/schema.sql`** — fresh installs read that file.
3. Add a DB-backed regression test that seeds via `Database::execute()` and cleans up in
   `try { } finally { }`.
4. Update column-specific tests only if you are renaming or dropping.

## Three DB initialisation paths — know which one you are in

| Path | When it runs | Target |
|---|---|---|
| `database/schema.sql` mounted at `/docker-entrypoint-initdb.d/schema.sql` | Fresh volume only (`docker compose down -v` re-seeds) | **Dev only** |
| `install.php` (loads `schema.sql` during the wizard) | The web install wizard | **Universal** — this is the production path |
| `database/migrations/*.sql` applied by hand | Every upgrade — **not automatic** | Both |

> The compose file mounts the whole `database/` directory at
> `/docker-entrypoint-initdb.d`, so the container-side name of the schema is
> `schema.sql` — the file name is unchanged, only the directory it is seen in.
> The MariaDB entrypoint processes only the **top level** of that directory, so
> the `.sql` files under `database/migrations/` are never auto-run.

A schema change must work on **all** paths: a fresh Docker volume, a fresh `install.php`
wizard run, and a hand-applied migration on an existing production database.
`database/schema.sql` is the single source of truth for the first two.

Migrations are **not** auto-run on either path.

## Money and dates

- **Amounts are `DECIMAL(14,2)`, never `INT` cents and never a float column.** Mixed
  granularity is a bug source; the decimal type also keeps the SQL `SUM()` exact.
- **Never do money arithmetic in PHP floats.** `0.1 + 0.2 !== 0.3`. Aggregate in SQL
  (`SUM(amount)`), read the result back as a string, and format with `number_format()`.
- Income and spending are stored in **one** `transactions` table with a `type` column
  (`'income'` / `'expense'`), not two tables — every report query then sums both in a
  single pass, and the "net = income − expense" rule stays in one place.
- Transaction dates are `DATE` columns (`occurred_on`), stored in UTC. `created_at` is the
  audit timestamp and must not be used for period grouping.

## PDO pitfalls

- `execute()` returns **bool**. Use `$db->execute(...)` then `(int)$db->lastInsertId()`.
- `EMULATE_PREPARES = false` means **native prepares** → **one statement per call**. An
  `INSERT …; SELECT LAST_INSERT_ID();` pair silently fails.
- To apply a **multi-statement** migration file from PHP, `Database::execute()` will not
  work. Either split on `;` (breaks the `SET @x := …; PREPARE …; EXECUTE` idiom) or reach
  the raw PDO handle by reflection and call `exec()`.
- The only string interpolation into SQL is a hard-coded table allow-list.
- Enum values are exact: `transactions.type` is `('income','expense')`. There is no
  `'credit'` or `'debit'`.

## Schema facts worth not re-deriving

- `users` — `username` and `email` are `NOT NULL UNIQUE`.
- `transactions.user_id → users.id` — **this is the entire authorization model**.
- `transactions.category_id → categories.id` is nullable (uncategorised entries are valid)
  and the FK is `ON DELETE SET NULL`.
- Period rollups (day / week / month / year) are computed in **SQL**, not by triggers and
  not by a `summaries` table.
