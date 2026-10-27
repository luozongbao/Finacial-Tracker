# Financial Tracker — Agent Guide

Personal finance tracker for personal use: record **income and spending**, then **search and
sum over a time period** (day, week, month, year).

The product brief lives in [docs/About.md](docs/About.md) — read it before changing scope.
This file holds only what an agent cannot infer from the tree.

## Status

**Greenfield.** The repo currently contains only `docs/About.md`. The layout below is the
*planned* structure — create it, do not go looking for it. Nothing here has been implemented
yet, so no command in this file has been executed successfully against this project.

## Stack

| Layer | Choice | Constraint |
|---|---|---|
| Web server | **OpenLiteSpeed / LSPHP** | `.htaccess` **is** honoured (the vhost enables `rewrite { RewriteFile .htaccess }`) — front-controller rewrites work the same in dev and prod. |
| Language | **PHP** | The dev image ships **PHP 8.2.33** (`lsphp82`). **PHP 8.2 is the floor.** |
| Database | **MariaDB 10.11** | `utf8mb4`. |
| Dev environment | **Docker Compose** | **Dev only.** The app must install and run with Docker entirely out of the picture. |

No framework, no ORM, no bundler, no npm, no build step. **The runtime image has no
`composer` and no `git`** — any third-party PHP library must be vendored into the repo or
baked into a custom image, not `composer install`-ed at start-up.

## Planned layout

````text
index.php              # front controller: install gate → route table
install.php            # web installer; writes config.php (delete it after install)
config.php             # git-ignored; written by install.php
config.template.php    # tracked twin — same constant set as config.php
includes/
  Database.php         # PDO singleton
  functions.php        # e(), CSRF, session, redirect, date/period helpers
  Router.php           # route table — single source of truth for URLs
  auth.php             # login/register + the ALLOW-REGISTRATION gate
  header.php / footer.php
assets/css/ assets/js/
database/
  schema.sql           # single source of truth for a fresh install
  migrations/          # YYYYMMDD_short_name.sql, applied by hand
  *_test.php           # standalone PHP CLI regression tests
docs/About.md
````

## Install and config contract — agent-critical

This is the one part of the brief that is easy to get subtly wrong.

1. **`config.php` presence is the install gate.** `index.php` checks for `config.php`; if it
   exists, the app starts. If it does not, the visitor is sent to the installer.
2. **`install.php` creates `config.php`** from user input: database name, database user,
   database password, SMTP server, SMTP port, email user, SMTP password.
3. **`config.php` also carries `ALLOW-REGISTRATION`, default `'true'`.**
4. **When `ALLOW-REGISTRATION` is `'false'`, registration is closed on the front end *and*
   the back end.** "Front end" = the register page does not render a form / returns 403.
   "Back end" = the server-side handler rejects the POST regardless of what was rendered.
   A check in only one of the two places is a bug.
5. The value is the **string** `'true'` / `'false'`, not a PHP boolean. Compare it strictly
   (`'false'`) — `ALLOW_REGISTRATION === false` never matches the string.

Consequences that follow from this:

- **`config.php` is the only configuration source.** No `getenv()`, no `$_ENV`, no `.env`
  parsing in application code. A Docker entrypoint may translate environment variables
  into `config.php`, but that translation lives in `docker/`, never in `includes/`.
- **`config.php` is git-ignored.** `config.template.php` is the tracked twin and must
  declare the **same constant set** — only the values differ. Add or remove a constant in
  one, add or remove it in the other.
- **Delete or lock `install.php` after a successful install.** A live installer is a
  re-configuration hole.
- Never add a code path that only works under Docker.

## Commands

`php`, `composer`, `mysql`, and `mariadb` are **not on the host PATH** — everything runs
inside the container. A bare `php file.php` on the host exits **127**.

````bash
# Stack
docker compose up -d
docker compose ps
docker compose logs -f app
docker compose down            # keeps the DB volume

# Lint — always, on every file you touch
docker compose exec -T app php -l includes/functions.php

# One regression test
docker compose exec -T app php database/<test>_test.php

# Whole suite (the helper handles pass/fail detection correctly)
.github/skills/dev-stack-ops/scripts/run-tests.sh
````

See [the dev-stack-ops skill](.github/skills/dev-stack-ops/SKILL.md) for the full operating
procedure, database access, and troubleshooting.

## Hard rules

These are the traps that cost time in the sibling PHP projects this repo is modelled on.

### A test passes on exit code, not on output

Success markers are inconsistent — some print `ALL ASSERTIONS PASSED`, some prose, some
nothing. **Detect success by exit code 0.** Never grep for the literal `Failed` (`Failed: 0`
is an informational line). Never treat "no output" as a pass, and never let a missing
interpreter masquerade as one.

### `Database::execute()` returns bool, not a row count

````php
$db->execute($sql, $params);
$id = (int)$db->lastInsertId();        // correct
$id = (int)$db->execute($sql, $params); // BUG — always 1
````

`EMULATE_PREPARES` is **false** → native prepares → **one statement per call**.

### Money is never a float

Amounts are `DECIMAL(14,2)` in the database. Never do arithmetic on PHP floats for money
(`0.1 + 0.2 !== 0.3`). Compute sums in SQL, read them back as strings, and format for
display with `number_format()`.

### Period sums are computed in SQL

Day / week / month / year grouping belongs in the query, not in a PHP loop over rows.
Store transaction dates as `DATE` in UTC, and keep the week-start rule in one place
(`WEEK_START` config constant, Monday by default) so reports and the UI agree.

### Output escaping and CSRF

- `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) on **every** dynamic value in HTML.
- Validate the CSRF token **before** reading any input, and put the hidden input in
  **every** form: `<input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">`.
- Ownership is enforced **in the SQL** by filtering on the owning user id.

### PHP tokenizer traps

- A literal `?>` inside a `//` comment **still closes PHP mode** — the rest of the file
  becomes raw HTML.
- A literal `<?php` inside a `//` comment **still opens a script block**.
- Write "PHP close tag" / "PHP open tag" in prose instead. **Always `php -l` every changed
  file.**

### Errors are not on the page

The OLS image sets `display_errors = Off`, so PHP errors go to the LiteSpeed error log, not
to the browser. Check `docker compose logs app` and the container's error log when a page
renders blank.

### Never `docker compose down -v`

`-v` deletes the MariaDB data volume. Take a dump first.

## Docs

| Doc | Use it for |
|---|---|
| [docs/About.md](docs/About.md) | The product brief: purpose, stack, installation contract. |
| [.github/skills/dev-stack-ops/SKILL.md](.github/skills/dev-stack-ops/SKILL.md) | Operating the LiteSpeed + MariaDB dev stack, running tests, DB access. |
| [.github/instructions/](.github/instructions/) | Scoped conventions for PHP, database, tests, and front-end code. |
