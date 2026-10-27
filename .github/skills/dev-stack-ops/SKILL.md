---
name: dev-stack-ops
description: 'Operate the Financial Tracker development stack: start and stop the OpenLiteSpeed + MariaDB + phpMyAdmin containers, run PHP lint and the regression suite inside the container, access the MariaDB client, back up or restore the database, and debug blank pages, 403s, or connection errors. Use for any task mentioning docker compose, the app container, php -l, running tests, the database, phpMyAdmin, LiteSpeed, or config.php in this project.'
argument-hint: '[the operation to perform, e.g. "start stack", "run tests", "backup db", "debug blank page"]'
---

# Dev Stack Operations

Day-to-day procedures for the **LiteSpeed (LSPHP) + MariaDB** stack. All host-side tooling is
Docker only — **`php`, `composer`, `mysql`, and `mariadb` are not installed on the host**.
A bare `php file.php` on the host exits **127**.

## Preflight

````bash
cd /home/zongbao/projects/financial-tracker
test -f .env || cp .env.example .env     # .env is required (DB credentials)
docker compose ps                        # empty output = the stack was never started
````

The stack is expected to expose three services:

| Service | Image | Role |
|---|---|---|
| `app` | `litespeedtech/openlitespeed:1.9.4-lsphp85` | LiteSpeed + LSPHP (PHP **8.5.12**), docroot `/var/www/vhosts/localhost/html` |
| `db` | `mariadb:10.11` | MariaDB, internal only |
| `phpmyadmin` | `phpmyadmin:latest` | admin UI, optional |

A fourth service, `mockup` (static nginx, profile `mockup`), is **not** started by a
plain `docker compose up -d`. Start it explicitly — see issue-002.md:

````bash
docker compose up -d mockup   # http://localhost:${MOCKUP_PORT:-8089}/
````

> The image tag is **pinned** to the PHP 8.5.12 patch release, not `latest` — see
> issue-009.md. The runtime image has **no `composer` and no `git`**. Third-party PHP
> libraries are vendored at **vendor time** on the host: `composer.json` and
> `composer.lock` are tracked, `vendor/` is git-ignored, and nothing runs
> `composer install` at start-up — see issue-010.md.

## Start / stop / inspect

````bash
docker compose up -d
docker compose ps
docker compose logs -f app
docker compose logs --tail=50 db
docker compose restart app
docker compose down                # keeps the DB volume
docker compose down -v             # NEVER — deletes the MariaDB data
````

## Run PHP — always inside the container

````bash
# Lint (always, on every file you touch)
docker compose exec -T app php -l includes/functions.php
docker compose exec -T app sh -c 'find . -name "*.php" -not -path "./vendor/*" -print0 \
  | xargs -0 -n1 php -l'

# One regression test
docker compose exec -T app php database/<test>_test.php

# Whole suite — use the helper, it detects pass/fail correctly
.github/skills/dev-stack-ops/scripts/run-tests.sh
.github/skills/dev-stack-ops/scripts/run-tests.sh database/registration_test.php

# Lint the whole tree via the helper
.github/skills/dev-stack-ops/scripts/run-tests.sh --lint
````

`docker compose exec -T` is preferred over `docker exec <name>` — it applies the project
namespace from `.env` automatically, and `-T` keeps the command non-interactive.

### The `run-tests.sh` helper

| Flag / env | Purpose |
|---|---|
| *(no args)* | Runs every `database/*_test.php`. |
| `<path>` | Runs one test; repeatable. |
| `--lint` | `php -l` over every `.php` file in the tree. |
| `APP_CONTAINER` | Use `docker exec` against this container instead of `docker compose exec`. |
| `APP_ROOT` | Repo root **as seen from inside the container** (default `/var/www/html`). |
| `PHP_BIN` | PHP binary inside the container (default `php`). |

The script runs each test with the container's working directory set to `APP_ROOT`, so
the relative paths it prints are the same on both sides. It prints
`=== N PASS / M FAIL ===` and exits non-zero when anything fails.

### Detecting test success — read this before writing a test loop

Success markers are **inconsistent**: some print `ALL ASSERTIONS PASSED`, some free-form
prose, some nothing. **Only the exit code is reliable.**

- Never grep for the literal `Failed` — `Failed: 0` is an *informational* line.
- Never treat "no output" as a pass.
- A loop that only checks "output does not contain FAIL" reports a **false green for every
  test** when `php` is missing, because the error text never contains the word. Always
  capture the exit code too, and treat **exit 127** (command not found) as an error, never
  as a pass.

## Database

The DB env vars exist **inside** the database container — reference them there instead of
exposing secrets on the command line. The container ships the `mysql` client, **not**
`mariadb`.

> ### The container variables are `MARIADB_*`, not `MYSQL_*`
>
> The `mariadb:10.11` image defines `MARIADB_DATABASE`, `MARIADB_USER`,
> `MARIADB_PASSWORD` and `MARIADB_ROOT_PASSWORD` — and **no** `MYSQL_*` variables
> at all. Referring to `$MYSQL_DATABASE` inside the container expands to the
> empty string, and the command degenerates in two dangerous ways:
>
> - `-p"$MYSQL_ROOT_PASSWORD"` becomes `-p` with an empty password, so `mysql`
>   falls back to an **interactive prompt** — which hangs a script and prints a
>   password prompt into a log.
> - `"$MYSQL_DATABASE"` becomes an empty database name, so the client connects
>   with **no database selected**:
>   `ERROR 1046 (3D000) at line 1: No database selected`.
>
> The `MYSQL_ROOT_PASSWORD` name is correct in **`.env`** (the host-side file) —
> compose maps it to `MARIADB_ROOT_PASSWORD` in the container. Keep the two
> contexts apart.

````bash
# List databases / tables
docker compose exec db sh -c 'mysql -u root -p"$MARIADB_ROOT_PASSWORD" -e "SHOW DATABASES;"'
docker compose exec db sh -c 'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "SHOW TABLES;"'

# Run a query
docker compose exec db sh -c 'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "SELECT * FROM transactions LIMIT 10;"'

# Backup
docker compose exec db sh -c 'mysqldump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > backup-$(date +%F).sql

# Restore
docker compose exec -T db sh -c 'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < backup-2026-10-27.sql

# Apply a migration (migrations are NOT auto-run)
docker compose exec -T db sh -c 'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/<file>.sql
````

phpMyAdmin is reachable at `http://localhost:${PHPMYADMIN_PORT:-8090}` (host `db`, port
`3306`). Log in with `DB_USER` / `DB_PASSWORD` from `.env`.

## LiteSpeed / LSPHP

- **`.htaccess` works.** The vhost enables `rewrite { RewriteFile .htaccess }`, so the
  front-controller rewrite rules behave the same in dev and production.
- **The LSPHP worker runs as uid/gid 1000** (measured: `posix_geteuid()` returns
  `1000`; the image sets `setUIDMode 2`). The host tree is owned by the
  developer's own uid, which is 1000 on a typical Linux host, so `install.php`
  can write `config.php` with no `chown` at all. Files created as **root**
  inside the bind mount are *not* writable by the worker — repair with:

  ````bash
  docker compose exec --user root app chown -R 1000:1000 /var/www/vhosts/localhost/html
  ````

- The PHP config lives at `/usr/local/lsws/lsphp85/etc/php/8.5/litespeed/php.ini`.
- **`display_errors = Off`** — PHP errors go to the LiteSpeed error log, **not** to the
  browser. A blank page means: check the logs, then check the error log file.

  ````bash
  docker compose logs --tail=100 app
  docker compose exec app tail -50 /usr/local/lsws/logs/error.log
  docker compose exec app tail -50 /var/www/vhosts/localhost/logs/error.log
  ````

- Reload the server config without dropping in-flight requests:

  ````bash
  docker compose exec app /usr/local/lsws/bin/lswsctrl graceful
  ````

- The WebAdmin console is on port 7080 — bind it to `127.0.0.1:7080:7080` on a public host
  and tunnel in. The image does **not** read `OLS_ADMIN_USER` / `OLS_ADMIN_PASSWORD`;
  the console credentials are the stock ones baked into the image
  (`/usr/local/lsws/admin/conf/htpasswd`), and the admin `conf/` directory is a named
  volume, so a password set once survives restarts.

## The install flow

The app is **not installed** until `config.php` exists. Until then `index.php` redirects
to `install.php`, which collects the database and SMTP settings and writes `config.php`.

- `config.php` is **git-ignored**. `config.template.php` is the tracked twin and must
  declare the **same constant set**.
- `config.php` also carries `ALLOW_REGISTRATION`, default `true`. When it is `false`,
  registration is closed on the front end **and** the back end.
- `database/config_test.php` checks the flag's type, the constant spelling, and the
  template/live parity. It needs no database.
- **Delete or lock `install.php` after a successful install.**

To re-run the installer locally: stop the app, remove `config.php` from the host tree,
restart the app, then browse to `http://localhost:<port>/install.php`.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Blank page, nothing in the browser | `display_errors = Off`. Check `docker compose logs app` and the LiteSpeed error log. |
| `php: command not found` (exit 127) | You ran PHP on the host. Use `docker compose exec -T app php …`. |
| Every test "passes" instantly | The test loop is treating a missing interpreter as a pass. Check the exit code. |
| 403 on the register page | `ALLOW_REGISTRATION` is `false` in `config.php`. Expected behaviour, not a bug. |
| 404 on every URL | `.htaccess` rewrite is not being applied — confirm it is in the docroot and that the vhost has `RewriteFile .htaccess`. |
| Permission denied writing `config.php` | The bind mount is owned by root; `chown -R 1000:1000` the docroot. |
| Database connection refused | `.env` (`DB_NAME` / `DB_USER` / `DB_PASSWORD`) must match `config.php`; the DB host inside Docker is `db`, not `localhost`. |
| `ERROR 1046 (3D000) at line 1: No database selected` | You used `$MYSQL_DATABASE` inside the container. The image only defines `MARIADB_DATABASE`. |
| The `mysql` client prompts for a password instead of running | Same cause: `-p"$MYSQL_ROOT_PASSWORD"` expands to an empty string. Use `$MARIADB_ROOT_PASSWORD`. |
| OLS container exits immediately | `conf` directories were bind-mounted and are non-empty → the image only seeds them when empty. Use named volumes `ols_conf` / `ols_admin_conf`. |
| Migration seems to have no effect | Migrations are **not** auto-run. Apply it by hand (see above). |

Full project conventions: [AGENTS.md](../../../AGENTS.md).
