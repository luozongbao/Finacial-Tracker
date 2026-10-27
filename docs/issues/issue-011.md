# Issue-011 — App: the schema and the data model

> **Status:** **specified, not implemented** — `database/schema.sql` does not exist yet.
> **Target:** v0.2 (the application) — the foundation every other application issue depends on.
> **Severity:** **high** — every screen in the product reads or writes these tables; a
> wrong schema here is expensive to unwind later.
>
> **Requested by the user:**
> > "I guess, we now have everything, ready for dev, write issue files for dev to
> > implement the project."
>
> **Depends on:** [issue-001.md](issue-001.md) (MariaDB 10.11 in the compose stack).
> **Related:** [issue-012.md](issue-012.md) (the `Database` wrapper),
> [issue-015.md](issue-015.md) (the installer that loads this schema),
> [issue-016.md](issue-016.md) (accounts),
> [issue-018.md](issue-018.md) (transactions),
> [issue-019.md](issue-019.md) (reports),
> [issue-020.md](issue-020.md) (categories);
> `AGENTS.md` (Hard rules — money is never a float, sums in SQL);
> `.github/instructions/database.instructions.md` (**the binding conventions for this file**).

---

## Table of contents

1. Objective
2. Constraints
3. The entity model
4. Specification — `users`
5. Specification — `categories`
6. Specification — `transactions`
7. Specification — `sessions` and the auth support tables
8. Specification — the indexes
9. Specification — `database/schema.sql`
10. Specification — `database/migrations/`
11. Acceptance criteria
12. Verification
13. Rollback
14. Risks
15. Out of scope

---

## 1. Objective

Create the **single source of truth** for the database shape: one
`database/schema.sql` that a fresh install loads, and the migration contract
that keeps an existing install in step.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `database/schema.sql` exists and creates the **complete** schema in one file |
| 2 | The schema loads cleanly on a **fresh** MariaDB 10.11 database with `utf8mb4` |
| 3 | `database/migrations/` holds the contract (a `README.md`) and is where every future change goes |
| 4 | Every amount is `DECIMAL(14,2)` — never an integer, never a float column |
| 5 | Ownership is expressible **in the SQL**: every user-scoped table carries `user_id` |
| 6 | Income and spending live in **one** `transactions` table with a `type` column |
| 7 | The schema supports day / week / month / year rollups **in SQL**, with no summary table |
| 8 | The three initialisation paths (fresh volume, installer, migration) all produce the same shape |
| 9 | A regression test asserts the schema, so a later edit cannot silently drift |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| MariaDB **10.11**, `utf8mb4` | No `CHECK` enforcement on older MariaDB in some contexts — validate in PHP *and* keep the column type strict. |
| **No ORM, no migrations framework** | `schema.sql` is hand-written SQL; migrations are hand-applied `.sql` files. |
| **No `START TRANSACTION`** in a migration | MariaDB DDL implicitly commits. Say so in the file header. |
| `EMULATE_PREPARES = false` | One statement per `Database::execute()` call. |
| Money is never a float | `DECIMAL(14,2)`; aggregate in SQL, read back as a string, format with `number_format()`. |
| The DB stores **UTC** | `DATE` columns for the business date (`occurred_on`), `DATETIME` for audit columns. |
| Every file in the repo is web-reachable unless denied | `database/` is already denied by `.htaccess`; keep it that way. |
| The repo root is the document root | `schema.sql` must be readable by the LSPHP worker (uid 1000) — a bind mount preserves the host's permissions. |

---

## 3. The entity model

````text
users
  id, username, email, password_hash, created_at, updated_at
  │
  │ 1 ──── n
  ▼
categories            (user_id → users.id, ON DELETE CASCADE)
  id, user_id, name, type, created_at
  │
  │ 1 ──── n
  ▼
transactions          (user_id → users.id, ON DELETE CASCADE)
  id, user_id, category_id → categories.id (NULLable, ON DELETE SET NULL)
  type, amount, occurred_on, description, created_at, updated_at
````

Two rules carry the whole authorisation model:

1. **`transactions.user_id` → `users.id` is the ownership boundary.** Every read and
   every write filters on it, in the SQL.
2. **`transactions.category_id` is nullable, with `ON DELETE SET NULL`.** A transaction
   without a category is valid, and deleting a category must **never** delete its
   transactions — the mockup says so in as many words
   ([09-categories.html](../mockup/09-categories.html)): *"N transactions will become
   uncategorised. They will not be deleted."*

---

## 4. Specification — `users`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `INT UNSIGNED` | no | `AUTO_INCREMENT` | Primary key. |
| `username` | `VARCHAR(32)` | no | — | **Unique.** 3–32 chars: letters, digits, `_`, `-`. |
| `email` | `VARCHAR(254)` | no | — | **Unique.** 254 is the RFC 5321 maximum length. |
| `password_hash` | `VARCHAR(255)` | no | — | `password_hash()` output (`PASSWORD_DEFAULT`). Never the plain password. |
| `email_verified_at` | `DATETIME` | **yes** | `NULL` | `NULL` until the confirmation link is used. |
| `status` | `ENUM('active','disabled')` | no | `'active'` | The "This account is not available." state ([01-login.html](../mockup/01-login.html)). |
| `failed_attempts` | `INT UNSIGNED` | no | `0` | Server-side login throttle. |
| `locked_until` | `DATETIME` | **yes** | `NULL` | When the attempt limit was hit; `NULL` means not locked. |
| `created_at` | `DATETIME` | no | `CURRENT_TIMESTAMP` | Audit. |
| `updated_at` | `DATETIME` | no | `CURRENT_TIMESTAMP` `ON UPDATE CURRENT_TIMESTAMP` | Audit. |

Rules:

- `username` and `email` are both `NOT NULL UNIQUE`. The registration screen makes
  each of them a separate, differently-worded rejection, so the uniqueness is a
  **database** guarantee, not only a PHP check.
- Store the email address **as given, lowercased** (`LOWER()` at write time) so the
  uniqueness index is case-insensitive in effect. Do **not** rely on a case-insensitive
  collation for the whole table — the schema uses `utf8mb4_unicode_ci` for readability,
  and the application normalises anyway.
- `password_hash` is `VARCHAR(255)`, not `VARCHAR(60)`: `PASSWORD_DEFAULT` is
  `bcrypt` today, but the algorithm may change and the column must not have to.
- **There is no `role` column.** The product is for personal use and there is one
  kind of account. Do not add a role system that nothing in the brief asks for.

---

## 5. Specification — `categories`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `INT UNSIGNED` | no | `AUTO_INCREMENT` | Primary key. |
| `user_id` | `INT UNSIGNED` | no | — | FK → `users.id`, `ON DELETE CASCADE`. |
| `name` | `VARCHAR(60)` | no | — | Unique **per user** (see below). Maxlength 60 matches the form. |
| `type` | `ENUM('income','expense')` | no | — | A category belongs to one side only — this is what lets the reports group by type. |
| `created_at` | `DATETIME` | no | `CURRENT_TIMESTAMP` | Audit. |
| `updated_at` | `DATETIME` | no | `CURRENT_TIMESTAMP` `ON UPDATE CURRENT_TIMESTAMP` | Audit. |

Uniqueness: `UNIQUE KEY (user_id, name)` — **not** a global `UNIQUE(name)`. Two users
may both have a category called "Food". The mockup's category list is a per-account
list, so the scope is per user.

`ON DELETE CASCADE` here is safe: deleting a *category* is the user's own action and
the transactions are **not** cascaded — they keep their rows and lose their category
via the `SET NULL` on `transactions.category_id`.

---

## 6. Specification — `transactions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `INT UNSIGNED` | no | `AUTO_INCREMENT` | Primary key. |
| `user_id` | `INT UNSIGNED` | no | — | FK → `users.id`, `ON DELETE CASCADE`. **The ownership boundary.** |
| `category_id` | `INT UNSIGNED` | **yes** | `NULL` | FK → `categories.id`, `ON DELETE SET NULL`. `NULL` = uncategorised. |
| `type` | `ENUM('income','expense')` | no | — | Exactly two values. There is no `'credit'` and no `'debit'`. |
| `amount` | `DECIMAL(14,2)` | no | — | **Never a float, never integer cents.** Always positive; the direction is in `type`. |
| `occurred_on` | `DATE` | no | — | The business date, stored in UTC. **This** is the column every report groups on — never `created_at`. |
| `description` | `VARCHAR(255)` | **yes** | `NULL` | Free text, maxlength 255. |
| `created_at` | `DATETIME` | no | `CURRENT_TIMESTAMP` | Audit. |
| `updated_at` | `DATETIME` | no | `CURRENT_TIMESTAMP` `ON UPDATE CURRENT_TIMESTAMP` | Audit. |

Rules:

- **`amount` is always positive.** The mockup's form has `min="0"` and `step="0.01"`,
  and the type radio carries the direction. Storing a negative expense would make
  `SUM()` wrong in a way that is very hard to see. The server rejects a negative or
  zero amount.
- **One table, not two.** Income and spending share the table so that a report sums
  both sides in a single pass and the "net = income − expense" rule lives in one
  place.
- `occurred_on` is a `DATE`, and it is **UTC**. The week-start rule (`WEEK_START`)
  lives in the PHP/config layer and is applied to the date range, not to the stored
  value.
- The description is nullable — a transaction may be recorded with only an amount and
  a date.

---

## 7. Specification — `sessions` and the auth support tables

PHP's own session handler can store sessions on disk, but the product needs three
things the default handler does not give: **a server-side attempt counter**, **a
single-use, expiring reset token**, and **a session list the user can revoke**.

Add these three tables:

### `password_resets`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | no | Primary key, `AUTO_INCREMENT`. |
| `user_id` | `INT UNSIGNED` | no | FK → `users.id`, `ON DELETE CASCADE`. |
| `token_hash` | `CHAR(64)` | no | **The SHA-256 hash of the token, never the token itself.** Unique. |
| `expires_at` | `DATETIME` | no | Created + 1 hour. |
| `used_at` | `DATETIME` | **yes** | Set when the token is redeemed. A token is **single-use**. |
| `created_at` | `DATETIME` | no | `CURRENT_TIMESTAMP`. |

The raw token goes in the email link; only its hash is stored. A database dump then
does not yield a working reset link.

### `email_verifications`

The same shape, for the registration confirmation link: `id`, `user_id`,
`token_hash` (`CHAR(64)`, unique), `expires_at`, `used_at`, `created_at`. It can be
merged into `password_resets` with a `purpose` column, but **two tables are easier to
reason about** and neither is large.

### `login_attempts` (optional but recommended)

An alternative to the `users.failed_attempts` / `users.locked_until` columns. Use
**one** of the two, not both:

| Option | Shape | Trade-off |
|---|---|---|
| **A — columns on `users`** (recommended here) | `failed_attempts INT`, `locked_until DATETIME` | Simple, one row per user, no cleanup job. Chosen by this issue. |
| B — a `login_attempts` table | `id`, `username_attempted`, `ip`, `attempted_at` | Better against distributed guessing; needs a cleanup rule. |

If option B is chosen, option A's two columns must be **removed** from `users` in the
same change — two competing counters is a bug.

> **Session storage.** PHP's file-based session handler is acceptable for a
> single-server personal install and needs no table. Do **not** invent a database
> session handler unless the product later requires sessions to survive a
> multi-server deploy; it is out of scope here.

---

## 8. Specification — the indexes

Indexes are not decoration — they are what keeps the reports fast as the table grows.

````sql
-- users
UNIQUE KEY uq_users_username (username),
UNIQUE KEY uq_users_email    (email)

-- categories
UNIQUE KEY uq_categories_user_name (user_id, name),
KEY        idx_categories_user     (user_id)

-- transactions
KEY idx_tx_user_date    (user_id, occurred_on),
KEY idx_tx_user_type    (user_id, type, occurred_on),
KEY idx_tx_user_cat     (user_id, category_id, occurred_on),
KEY idx_tx_occurred_on  (occurred_on)

-- password_resets
UNIQUE KEY uq_reset_token (token_hash),
KEY        idx_reset_user (user_id, expires_at)
````

Reading the list against the query patterns:

| Query | Index it serves |
|---|---|
| The transaction list, newest first, for one user | `idx_tx_user_date` |
| The dashboard tile sums over a period for one user | `idx_tx_user_date` |
| The "by category" report for one user and a period | `idx_tx_user_cat` |
| The "over time" rollup by week / month | `idx_tx_user_date` |
| The category list with its transaction count | `idx_categories_user` + `idx_tx_user_cat` |

A composite index on `(user_id, occurred_on)` serves the "list for this user in this
period, ordered by date" query directly — the column order matters, and it is
**user first**, because every query is already scoped to one user.

---

## 9. Specification — `database/schema.sql`

The file must:

1. **Start with a header comment** stating the purpose, the MariaDB version it targets,
   and the three ways it gets applied:

   ````text
   -- Financial Tracker — schema.
   -- Target: MariaDB 10.11, utf8mb4.
   --
   -- Applied by:
   --   1. /docker-entrypoint-initdb.d/schema.sql  (fresh Docker volume — DEV only)
   --   2. install.php                             (the web installer — UNIVERSAL)
   --   3. database/migrations/*.sql               (by hand — never automatic)
   --
   -- This file is the single source of truth for 1 and 2.
   ````

2. **Select the database** — `CREATE DATABASE IF NOT EXISTS … CHARACTER SET utf8mb4
   COLLATE utf8mb4_unicode_ci; USE …;` — so it works whether it is piped into
   `mariadb` with no database selected or loaded by `install.php` against a named
   database. The `IF NOT EXISTS` + `USE` pair must not fail when the database already
   exists.

3. Create the tables in **foreign-key order** — `users`, then `categories`, then
   `transactions` — because `categories.user_id` and `transactions.category_id` reference
   tables that must already exist.

4. Use `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` on every
   table. InnoDB is required for the foreign keys.

5. End with a short comment listing the tables and the seed of the "uncategorised"
   behaviour (which is *not* a row — it is `category_id IS NULL`).

> **The MariaDB entrypoint only processes the top level of the mounted directory.**
> The compose file mounts the whole `database/` directory at
> `/docker-entrypoint-initdb.d`, so the container-side name is `schema.sql` and the
> files under `database/migrations/` are never auto-run. The **file name stays
> `schema.sql`**; only the directory it is seen in changes.

### The SQL that must not be in the file

| Not allowed | Why |
|---|---|
| `DROP TABLE` | A fresh install has nothing to drop, and the file is also read by the installer against a live database. |
| `TRUNCATE` | Same reason — it would destroy a live install's data. |
| `CREATE DATABASE` **without** `IF NOT EXISTS` | The installer creates the database first; the file must not fail if it exists. |
| `INSERT` seed rows | There is no global seed. Every row belongs to a user. |

---

## 10. Specification — `database/migrations/`

Create the directory with a `README.md` that is the **contract**, not a comment. It
must state:

1. **Naming** — `YYYYMMDD_short_name.sql` (UTC date of authoring).
2. **The header block** every file carries — the purpose, the exact run command, and
   the note that MariaDB DDL implicitly commits so there is no `START TRANSACTION`.
3. **The idempotency guard** — the file must be safe to re-run. The canonical pattern:

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

4. **The four-step schema-change checklist** — the same one in
   `.github/instructions/database.instructions.md`:

   | Step | What |
   |---|---|
   | 1 | Add `database/migrations/YYYYMMDD_short_name.sql`. |
   | 2 | **Mirror the change in `database/schema.sql`** — fresh installs read that file. |
   | 3 | Add a DB-backed regression test that seeds with `Database::execute()` and cleans up in `try { } finally { }`. |
   | 4 | Update column-specific tests only if a column is renamed or dropped. |

5. **The run command** — the exact, paste-able one:

   ````bash
   docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" financial_tracker \
       < database/migrations/YYYYMMDD_short_name.sql
   ````

6. **The reminder that migrations are not automatic** — on neither the fresh-volume
   path nor the installer path.

---

## 11. Acceptance criteria

- [ ] `database/schema.sql` exists and creates `users`, `categories`, `transactions`,
      `password_resets` and `email_verifications`.
- [ ] The file starts with a header comment naming the three initialisation paths.
- [ ] The file contains `CREATE DATABASE IF NOT EXISTS` + `USE`, so it loads with or
      without a database selected.
- [ ] Every table is `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- [ ] `transactions.amount` is `DECIMAL(14,2)` — verified by `information_schema`, not
      by reading the file.
- [ ] `transactions.type` is `ENUM('income','expense')` — no third value.
- [ ] `transactions.category_id` is nullable with `ON DELETE SET NULL`.
- [ ] `transactions.user_id` has `ON DELETE CASCADE` and an index leading with
      `user_id`.
- [ ] `users.username` and `users.email` are both `NOT NULL UNIQUE`.
- [ ] `categories` is unique on `(user_id, name)`, **not** on `name` alone.
- [ ] `password_resets.token_hash` is `CHAR(64)` and unique; the raw token is never
      stored.
- [ ] `password_resets` has `expires_at` and `used_at` — a token is single-use.
- [ ] `database/schema.sql` contains **no** `DROP TABLE`, `TRUNCATE` or seed `INSERT`.
- [ ] `database/migrations/README.md` exists and contains the naming rule, the
      idempotency guard, the four-step checklist, the run command and the "not
      automatic" reminder.
- [ ] A regression test `database/schema_test.php` asserts the table list, the column
      types (read from `information_schema`) and the foreign-key actions.
- [ ] The test **skips cleanly** when the database is not reachable, and reports
      success by **exit code 0** — never by a printed marker.
- [ ] The schema loads on a **fresh** database with no errors.

---

## 12. Verification

```bash
# 1 — the schema file exists and has the required sections
cd /home/zongbao/projects/financial-tracker
test -f database/schema.sql                 && echo "schema.sql: present"
test -f database/migrations/README.md       && echo "migrations/README.md: present"
grep -qi 'CREATE DATABASE IF NOT EXISTS' database/schema.sql && echo "create-db: ok"
grep -qi 'ENGINE=InnoDB' database/schema.sql && echo "innodb: ok"
! grep -qiE 'DROP[[:space:]]+TABLE|TRUNCATE' database/schema.sql && echo "no-destructive-ddl: ok"

# 2 — it loads into a throw-away database
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" \
    -e "DROP DATABASE IF EXISTS ft_schema_check; CREATE DATABASE ft_schema_check;" 2>/dev/null
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" ft_schema_check \
    < database/schema.sql && echo "load: ok"

# 3 — the tables exist
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" ft_schema_check -e "SHOW TABLES;"

# 4 — money is DECIMAL, type is the two-value enum, category_id is nullable
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" ft_schema_check -e "
  SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='ft_schema_check' AND TABLE_NAME='transactions'
     AND COLUMN_NAME IN ('amount','type','category_id','occurred_on','user_id');"

# 5 — the foreign-key actions
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" ft_schema_check -e "
  SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE
    JOIN information_schema.REFERENTIAL_CONSTRAINTS USING (CONSTRAINT_NAME, CONSTRAINT_SCHEMA)
   WHERE CONSTRAINT_SCHEMA='ft_schema_check'
     AND REFERENCED_TABLE_NAME IS NOT NULL;"

# 6 — the regression test
docker compose exec -T app php /var/www/html/database/schema_test.php
echo "exit=$?"
```

Expected: steps 1–2 print `ok`, step 3 lists the five tables, step 4 shows
`decimal(14,2)` for `amount`, `enum('income','expense')` for `type` and `YES` for
`category_id`, step 5 shows `SET NULL` for `transactions.category_id`, and step 6
exits **0**.

---

## 13. Rollback

The schema has not shipped, so "rollback" means **delete the artefacts**:

````bash
rm -f database/schema.sql
rm -rf database/migrations
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" \
    -e "DROP DATABASE IF EXISTS financial_tracker;"
````

> **Never `docker compose down -v` as a rollback.** It deletes the MariaDB data
> volume for every database in it. Drop the one database you mean, by name.

If the schema has already been applied to a live database, the rollback is a
**migration in the opposite direction** — `database/migrations/YYYYMMDD_revert_*.sql`
— not an edit of `schema.sql`.

---

## 14. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A `FLOAT`/`DOUBLE` column sneaks into `amount` | Low | The schema test asserts the type from `information_schema`, not from the file text. |
| Integer-cent storage is chosen "for performance" | Low | Stated in §6 and in `AGENTS.md`; the schema test asserts `DECIMAL(14,2)`. |
| A `role` column is added "for later" | Medium | §4 forbids it explicitly. |
| A second category-uniqueness scope is added later | Medium | The `(user_id, name)` key is documented; a global unique key would break multi-user installs. |
| `occurred_on` drifts from `created_at` in a report | Medium | §6 names `occurred_on` as the only grouping column; issue-019 requires it in every query. |
| The installer and a fresh volume produce different schemas | Low | Both read the **same** `schema.sql`; the four-step checklist keeps them in step. |
| The DB root password is needed for the verification commands | Certain | It lives in `.env` as `DB_ROOT_PASSWORD`. Use `$(grep …)` rather than hard-coding it. |

---

## 15. Out of scope

- **Any PHP code.** This issue produces SQL and the migration contract only. The
  `Database` wrapper is [issue-012.md](issue-012.md).
- **The installer.** Loading `schema.sql` is [issue-015.md](issue-015.md).
- **Multi-currency, exchange rates, or a `currency` column.** The brief has one
  currency and no conversion.
- **Recurring transactions, budgets, or reminders.** Not in the brief.
- **Tags, notes, attachments, or a `notes` table.** Not in the brief.
- **Soft deletes / an `archived` flag.** Not in the brief; the delete flows in the
  mockups are hard deletes with explicit warnings.
- **A migration runner, a schema-diff tool, or a seeding script.**
