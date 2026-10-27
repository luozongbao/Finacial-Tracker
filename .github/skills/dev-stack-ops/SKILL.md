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
| `app` | `litespeedtech/openlitespeed:latest` | LiteSpeed + LSPHP (PHP **8.2**), docroot `/var/www/vhosts/localhost/html` |
| `db` | `mariadb:10.11` | MariaDB, internal only |
| `phpmyadmin` | `phpmyadmin:latest` | admin UI, optional |

> The runtime image has **no `composer` and no `git`**. Any third-party PHP library must be
> vendored into the repo or baked into a custom image.

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

````bash
# List databases / tables
docker compose exec db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "SHOW DATABASES;"'
docker compose exec db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SHOW TABLES;"'

# Run a query
docker compose exec db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT * FROM transactions LIMIT 10;"'

# Backup
docker compose exec db sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > backup-$(date +%F).sql

# Restore
docker compose exec -T db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < backup-2026-10-27.sql

# Apply a migration (migrations are NOT auto-run)
docker compose exec -T db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < database/migrations/<file>.sql
````

phpMyAdmin is reachable at `http://localhost:${PHPMYADMIN_PORT:-8081}` (host `db`, port
`3306`).

## LiteSpeed / LSPHP

- **`.htaccess` works.** The vhost enables `rewrite { RewriteFile .htaccess }`, so the
  front-controller rewrite rules behave the same in dev and production.
- **LSPHP runs as `nobody:nogroup`** (uid 65534). Files created as root inside the bind
  mount are not writable by the worker. Repair with:

  ````bash
  docker compose exec --user root app chown -R nobody:nogroup /var/www/vhosts/localhost/html
  ````

- The PHP config lives at `/usr/local/lsws/lsphp82/etc/php/8.2/litespeed/php.ini`.
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
  and tunnel in. The admin password is regenerated on every container start from
  `OLS_ADMIN_USER` / `OLS_ADMIN_PASSWORD` in `.env`.

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
| Permission denied writing `config.php` | The bind mount is owned by root; `chown -R nobody:nogroup` the docroot. |
| Database connection refused | `.env` (`MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD`) must match `config.php`; the DB host inside Docker is `db`, not `localhost`. |
| OLS container exits immediately | `conf` directories were bind-mounted and are non-empty → the image only seeds them when empty. Use named volumes `ols_conf` / `ols_admin_conf`. |
| Migration seems to have no effect | Migrations are **not** auto-run. Apply it by hand (see above). |

Full project conventions: [AGENTS.md](../../../AGENTS.md).
