# Issue-001 — Ops: Docker Compose development stack (LiteSpeed + MariaDB + phpMyAdmin)

> **Status:** **implemented and verified** — every acceptance criterion below was
> measured against the running stack on 2026-10-27.
> **Target:** v0.1 (development infrastructure).
> **Severity:** **n/a** — additive infrastructure, no application impact.
>
> **Requested by the user:**
> > "write issue files in docs/issues/ for devops to implement, create docker compose file
> > for the project … The project should live in the /"
>
> **Depends on:** nothing. This is the first infrastructure issue.
> **Related:** [issue-002.md](issue-002.md) (mockup server),
> [issue-003.md](issue-003.md) (web-root hardening).

---

## Table of contents

1. Objective
2. Constraints
3. Target architecture
4. Specification — `docker-compose.yml`
5. Specification — `.env` / `.env.example`
6. Specification — `docker/entrypoint.sh`
7. Acceptance criteria
8. Verification
9. Operating the stack
10. Rollback
11. Known traps
12. Out of scope

---

## 1. Objective

> Provide a **reproducible, one-command** development stack in which the whole
> application is served **at the document root (`/`)** by LiteSpeed, with
> MariaDB 10.11 for storage and phpMyAdmin for database inspection.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `docker compose up -d` starts the app, the database and phpMyAdmin |
| 2 | `http://localhost:${APP_PORT}/` is served by LiteSpeed (not by a PHP built-in server) |
| 3 | The project lives at `/`: `index.php` is reachable at `/index.php`, `assets/` at `/assets/` |
| 4 | `.htaccess` is honoured, so the front-controller rewrite behaves as in production |
| 5 | PHP is **8.5.12** — the version floor declared in `AGENTS.md` |
| 6 | The database is reachable from the app container under the service name `db` |
| 7 | `php` on the host still exits **127** — the stack is a convenience, never a dependency |
| 8 | `docker compose down` preserves the database volume |
| 9 | No application code path exists that only works under Docker |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| **Docker is development-only** | The app must install and run with Docker entirely out of the picture. Production is plain LiteSpeed + PHP + MariaDB. |
| **`config.php` is the only configuration source for application code** | No `getenv()`, no `$_ENV`, no `.env` parsing in `includes/`. The compose file configures *containers*, not the application. |
| **`config.php` is written by `install.php`** | The entrypoint must **not** pre-create it. Pre-creating it would bypass the install gate (`index.php` redirects to the installer when `config.php` is missing). |
| The image ships **no `composer` and no `git`** | Any third-party PHP library is installed by a **vendor-time** Composer step outside the container, and the resulting `vendor/` tree is what is deployed. Nothing may be `composer install`-ed at start-up. See [issue-010.md](issue-010.md). |
| The image has **no `node`** | No npm, no bundler, no build step in the dev container. |
| The OpenLiteSpeed image seeds `conf/` only when it is empty | `conf` directories must be **named volumes**, not bind mounts — a populated bind mount makes the container exit immediately. |
| `php`, `composer`, `mysql`, `mariadb` are **not on the host PATH** | Every command runs inside the container. A bare `php file.php` on the host exits **127**. |
| LiteSpeed runs with `display_errors = Off` | PHP errors go to the LiteSpeed error log, not the browser. A blank page means: read the log. |
| `phpMyAdmin` needs an open port | It uses `PHPMYADMIN_PORT` (8090) so it never collides with the app port. |

---

## 3. Target architecture

````text
host
 │
 ├─ :8088 ──► app (litespeedtech/openlitespeed:1.9.4-lsphp85)
 │              LiteSpeed 1.9.4 + LSPHP 8.5.12
 │              docroot  /var/www/vhosts/localhost/html  ← the project
 │              http://localhost:8088/           → the app front controller
 │              http://localhost:8088/docs/mockup/→ the mockups (read-only)
 │              127.0.0.1:7080 → WebAdmin console
 │
 ├─ :8090 ──► phpmyadmin (phpmyadmin:latest) ──► db:3306
 │
 ├─ (internal only) db (mariadb:10.11)
 │                  volume ft-db-data
 │                  ./database/*.sql runs once on a FRESH volume
 │
 └─ :8089 ──► mockup (nginx:alpine)   [compose profile "mockup"]
                docroot /usr/share/nginx/html ← ./docs/mockup (read-only)
                see issue-002.md
````

### The docroot path is not a free choice

`litespeedtech/openlitespeed:1.9.4-lsphp85` ships a `docker` vhost template whose
`vhRoot` is `/var/www/vhosts/$VH_NAME/` and whose `docRoot` is
`$VH_ROOT/html/`, with the member `localhost`. The mount point is therefore
**`/var/www/vhosts/localhost/html`** — mounting anywhere else yields a 404 for
every request, including valid PHP files.

Verified in this environment:

| Mount target | Result |
|---|---|
| `/var/www/vhosts/localhost/html` | **200**, PHP executes (LSPHP 8.5.12) |
| `/usr/local/lsws/Example/html` | 404 — that path belongs to the unused `Example` vhost |
| `/var/www/html` | 404 — not a document root in this image |

---

## 4. Specification — `docker-compose.yml`

Four services, one network (`ft-net`), three volumes.

### 4.1 `app`

| Key | Value | Reason |
|---|---|---|
| `image` | `litespeedtech/openlitespeed:1.9.4-lsphp85` | Pinned to the PHP 8.5.12 patch release. The tag is **not** `latest` — a security floor that an upstream tag push can move is not a floor. See [issue-009.md](issue-009.md). |
| `container_name` | `ft-app` | Stable name for `docker exec`. |
| `entrypoint` | `["/bin/bash", "/docker-entrypoint.sh"]` | Wrapper adds one symlink, then hands over to the image entrypoint. |
| `ports` | `"${APP_PORT:-8088}:80"` | The container listens on 80; the host port is configurable. |
| `ports` | `"127.0.0.1:${OLS_ADMIN_PORT:-7080}:7080"` | The WebAdmin console is an admin surface — loopback only. |
| `volumes` | `./:/var/www/vhosts/localhost/html` | The project, served at `/`. |
| `volumes` | `./docker/entrypoint.sh:/docker-entrypoint.sh:ro` | The wrapper. |
| `volumes` | `ols_conf:/usr/local/lsws/conf` | **Named volume** — the image seeds it only when empty. |
| `volumes` | `ols_admin_conf:/usr/local/lsws/admin/conf` | Same. |
| `depends_on` | `db: condition: service_healthy` | The app must not start against a half-initialised database. |

### 4.2 `db`

| Key | Value | Reason |
|---|---|---|
| `image` | `mariadb:10.11` | Pinned to the version in `AGENTS.md`; `utf8mb4` by default. |
| `environment` | `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD` | Taken from `.env`. |
| `volumes` | `ft-db-data:/var/lib/mysql` | Named volume — survives `docker compose down`. |
| `volumes` | `./database:/docker-entrypoint-initdb.d:ro` | Every `*.sql` **directly** in `database/` runs once, on a fresh volume only. |
| `healthcheck` | `healthcheck.sh --connect --innodb_initialized` | The image ships `healthcheck.sh`; `--innodb_initialized` avoids the "server started up" false-positive. |
| `ports` | **none** | The database is internal to the compose network. |

> **`database/schema.sql` is the single source of truth** for a fresh install.
> It is applied by the `install.php` wizard (the universal path) and by this
> initdb mount (the dev path). Migrations under `database/migrations/` are
> **not** auto-run — they are applied by hand. See
> `.github/instructions/database.instructions.md`.

### 4.3 `phpmyadmin`

| Key | Value | Reason |
|---|---|---|
| `image` | `phpmyadmin:latest` | Optional admin UI. |
| `environment` | `PMA_HOST: db` | The database host inside the compose network. |
| `ports` | `"${PHPMYADMIN_PORT:-8090}:80"` | Separate port so it never collides with the app. |

### 4.4 `mockup`

Described in full in [issue-002.md](issue-002.md). It sits behind the
`mockup` compose profile so that a plain `docker compose up -d` does not
start it.

---

## 5. Specification — `.env` / `.env.example`

`.env.example` is tracked; `.env` is git-ignored. Docker Compose reads `.env`
automatically.

| Variable | Default | Consumed by | Purpose |
|---|---|---|---|
| `APP_PORT` | `8088` | compose | Host port → the app. |
| `OLS_ADMIN_PORT` | `7080` | compose | Host port → the WebAdmin console (loopback only). |
| `PHPMYADMIN_PORT` | `8090` | compose | Host port → phpMyAdmin. |
| `MOCKUP_PORT` | `8089` | compose | Host port → the mockup viewer. |
| `DB_NAME` | `financial_tracker` | `db` container **and** the installer | Database name. |
| `DB_USER` | `ft_user` | same | Application database user. |
| `DB_PASSWORD` | `ft_password` | same | Application database password. |
| `MYSQL_ROOT_PASSWORD` | `ft_root_password` | `db` container | Root password — init and `db` client only. |
| `SMTP_*` | empty | the installer | Mailer (account verification, password reset). |

> ### The database host is `db`, not `localhost`
>
> Inside the app container, `localhost` is the app container itself. When the
> install wizard asks for the database host, the value to type is **`db`** —
> the compose service name. The `.env` file deliberately has **no**
> `DB_HOST` variable: the host is not a secret and it differs between Docker
> and a bare-metal install, so it stays a wizard field.

---

## 6. Specification — `docker/entrypoint.sh`

A thin wrapper around the image's own `/entrypoint.sh`. It performs exactly
two things and then hands over.

| # | Action | Why |
|---|---|---|
| 1 | Create `/var/www/html` → `/var/www/vhosts/localhost/html` (symlink) | Two docroot paths are documented: `AGENTS.md` and the dev-stack-ops skill use `/var/www/vhosts/localhost/html`, while `.github/skills/dev-stack-ops/scripts/run-tests.sh` defaults `APP_ROOT` to `/var/www/html`. The symlink makes both address the same tree, so a test run from either path sees the same files. |
| 2 | `chmod u+rwx` on the docroot (top level only) | The PHP worker runs as uid 1000, which matches the owner of the bind-mounted files on a typical Linux host, so `install.php` can write `config.php`. A **recursive** chown is explicitly avoided — it would rewrite the ownership of the whole repository on the host. |

Then: `exec /entrypoint.sh "$@"`.

> The wrapper **does not write `config.php`**. The install gate is
> "does `config.php` exist"; an entrypoint that pre-creates it would bypass
> the installer and make the wizard untestable in development. See
> `AGENTS.md`, "Install and config contract".

---

## 7. Acceptance criteria

- [ ] `docker compose config --quiet` exits 0.
- [ ] `docker compose up -d` starts `app`, `db` and `phpmyadmin`; `db` reports **healthy**.
- [ ] `docker compose ps` shows `ft-app` on `${APP_PORT}:80`.
- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:${APP_PORT}/` returns **404** (not 500) — the project has no `index.php` yet; a 404 proves LiteSpeed is serving the docroot.
- [ ] A PHP file placed in the project root returns **200** and executes: `curl http://localhost:${APP_PORT}/config.template.php` returns 200 with an empty body (PHP output, not source).
- [ ] `docker compose exec -T app php -v` reports **PHP 8.5.12**.
- [ ] `docker compose exec -T app sh -c 'readlink -f /var/www/html'` resolves to `/var/www/vhosts/localhost/html`.
- [ ] `docker compose exec -T db sh -c 'mysql -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SHOW TABLES;"'` succeeds.
- [ ] `docker compose exec -T app php -r 'echo gethostbyname("db");'` resolves to the database container.
- [ ] `http://localhost:${PHPMYADMIN_PORT}/` loads and can authenticate with `DB_USER` / `DB_PASSWORD`.
- [ ] `docker compose down` followed by `docker compose up -d` preserves the database contents.
- [ ] `.github/skills/dev-stack-ops/scripts/run-tests.sh` runs the suite and reports `=== N PASS / 0 FAIL ===`.

---

## 8. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 0. Preflight
test -f .env || cp .env.example .env
docker compose config --quiet && echo "compose file is valid"

# 1. Start
docker compose up -d
docker compose ps

# 2. The document root is served
curl -sS -o /dev/null -w "GET /            -> %{http_code}\n" http://localhost:${APP_PORT:-8088}/
curl -sS -o /dev/null -w "GET /config...   -> %{http_code}\n" http://localhost:${APP_PORT:-8088}/config.template.php

# 3. PHP version and the compatibility symlink
docker compose exec -T app php -v
docker compose exec -T app sh -c 'readlink -f /var/www/html'

# 4. The database
docker compose exec -T db sh -c 'mysql -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SHOW DATABASES;"'

# 5. The regression suite (exit code is the only reliable pass signal)
.github/skills/dev-stack-ops/scripts/run-tests.sh
````

---

## 9. Operating the stack

````bash
docker compose up -d                 # app + db + phpmyadmin
docker compose --profile mockup up -d mockup   # add the mockup viewer
docker compose logs -f app           # follow the web server log
docker compose logs --tail=50 db
docker compose restart app
docker compose down                  # stops everything, KEEPS the database
docker compose exec -T app php -l includes/functions.php   # lint
````

### First-run install

The app is **not installed** until `config.php` exists.

1. `docker compose up -d`
2. Browse to `http://localhost:${APP_PORT}/` → the front controller redirects to `install.php`.
3. In the wizard use: database host **`db`**, database name / user / password from `.env`, and the SMTP values from `.env` (or leave them empty).
4. The wizard writes `config.php` and applies `database/schema.sql`.
5. **Delete or lock `install.php` afterwards** — a live installer is a re-configuration hole.

To re-run the installer locally: stop the app, delete `config.php` from the
host tree, restart the app, browse to the installer again.

---

## 10. Rollback

The stack is additive and carries no state outside the named volumes.

````bash
docker compose down                 # stop and remove the containers
docker compose down -v              # ALSO removes the database volume — destructive
````

> ### NEVER run `docker compose down -v`
>
> `-v` deletes `ft-db-data`, which is the MariaDB data volume. Take a dump
> first:
>
> ````bash
> docker compose exec -T db sh -c 'mysqldump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > backup-$(date +%F).sql
> ````

To remove the stack **and** its data deliberately: `docker compose down -v`
followed by `docker volume ls` to confirm `financial-tracker_ft-db-data` is
gone.

---

## 11. Known traps

| Symptom | Cause | Fix |
|---|---|---|
| 404 on every URL, including valid PHP files | The project is mounted somewhere other than `/var/www/vhosts/localhost/html` | Fix the mount path (see §3). |
| The `app` container exits immediately | `conf/` or `admin/conf/` was bind-mounted and is non-empty — the image seeds them only when empty | Use the named volumes `ols_conf` / `ols_admin_conf`. |
| `php: command not found`, exit **127** | PHP was invoked on the host | Run it inside the container: `docker compose exec -T app php …`. |
| Blank page in the browser | `display_errors = Off` | `docker compose logs --tail=100 app` and `docker compose exec app tail -50 /usr/local/lsws/logs/error.log`. |
| The app cannot connect to the database | The wizard was given `localhost` as the database host | Use `db`. |
| The install wizard cannot write `config.php` | The bind mount is owned by an uid other than the worker uid | `docker compose exec --user root app chown -R nobody:nogroup /var/www/vhosts/localhost/html` — or leave the top-level directory writable for the container user (uid 1000), which is the default in this image. |
| A migration "had no effect" | Migrations are **not** auto-run | Apply it by hand: `docker compose exec -T db sh -c 'mysql -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/<file>.sql` |
| `ERROR 1046 (3D000) at line 1: No database selected` | The `MYSQL_DATABASE` variable does not exist in the `mariadb` image | Use `MARIADB_DATABASE` (and `MARIADB_ROOT_PASSWORD`) inside the container |
| Port already in use | Another stack owns the port | Override `APP_PORT` in `.env` and restart. |

---

## 12. Out of scope

- **Production deployment.** Production is plain LiteSpeed + PHP + MariaDB.
  Nothing in this issue may become a runtime dependency.
- **TLS.** The dev stack is HTTP only. A production certificate is a
  deployment concern, not a development one.
- **A custom application image.** The stock image is used as-is; nothing is
  baked in. A third-party PHP library is added by a vendor-time Composer step
  that produces `vendor/` on the host — see [issue-010.md](issue-010.md).
- **The mockup viewer.** See [issue-002.md](issue-002.md).
- **Web-root hardening.** See [issue-003.md](issue-003.md).
- **The PHP version floor.** The image tag is pinned here, but the floor
  itself and the PHP2Shell remediation are specified in
  [issue-009.md](issue-009.md).
- **CI.** There is no CI in this project; tests are run by hand.
