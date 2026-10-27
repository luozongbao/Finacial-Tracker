# Financial Tracker — Agent Guide

Personal finance tracker for personal use: record **income and spending**, then **search and
sum over a time period** (day, week, month, year).

The product brief lives in [docs/About.md](docs/About.md) — read it before changing scope.
This file holds only what an agent cannot infer from the tree.

## Status

**Greenfield — the application code does not exist yet, but the development
infrastructure does.**

| Exists and works | Exists, spec only | Does not exist yet |
|---|---|---|
| `docker-compose.yml` (app + db + phpMyAdmin), `docker/entrypoint.sh`, `docker/mockup-nginx.conf`, `.env.example`, `.htaccess` (web-root hardening), `config.template.php`, `database/config_test.php` | `docs/issues/` (8 issues), `docs/mockup/` (design skeleton + gallery) | `index.php`, `install.php`, `includes/`, `assets/`, `database/schema.sql` |

The stack in `docker-compose.yml` has been started and verified. The mockup
skeleton in `docs/mockup/` is a **deliverable**, not a placeholder — the real
mockups are specified by the UX issues in `docs/issues/` and are written by
the UX/UI track.

Start here: **[docs/issues/README.md](docs/issues/README.md)** — it indexes
every issue, states which track owns it, and shows where the design track
and the application track meet.

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

## Layout

````text
index.php              # front controller: install gate → route table        [TODO]
install.php            # web installer; writes config.php (delete it after install)  [TODO]
config.php             # git-ignored; written by install.php                  [TODO]
config.template.php    # tracked twin — same constant set as config.php        [exists]
.htaccess              # web-root deny block + (later) the front-controller rewrite
.dockerignore  .env  .env.example
docker-compose.yml     # app + db + phpmyadmin (+ the "mockup" profile)
docker/
  entrypoint.sh        # adds the /var/www/html compatibility symlink
  mockup-nginx.conf    # static viewer for docs/mockup/
includes/              # [TODO] Database.php, functions.php, Router.php, auth.php, header/footer
assets/css/ assets/js/  # [TODO] tokens → base → components → pages/<page>.css
database/
  schema.sql           # [TODO] single source of truth for a fresh install
  migrations/          # [TODO] YYYYMMDD_short_name.sql, applied by hand
  *_test.php           # standalone PHP CLI regression tests
docs/
  About.md             # the product brief
  issues/              # issue specifications (8) — see docs/issues/README.md
  mockup/              # static HTML mockups + the shared assets/ layer
````

`config.template.php` and `database/config_test.php` are the reference for the config
contract — read them before changing anything about configuration.

### The web root is the repository root

The app service mounts the repository at
`/var/www/vhosts/localhost/html`, so the application lives at `/`. **Every file
in the tree is web-reachable unless `.htaccess` denies it.** The deny block in
`.htaccess` is load-bearing — read it before adding a directory, and never
replace the file wholesale. The rules are specified and measured in
[docs/issues/issue-003.md](docs/issues/issue-003.md).

## Install and config contract — agent-critical

This is the one part of the brief that is easy to get subtly wrong.

1. **`config.php` presence is the install gate.** `index.php` checks for `config.php`; if it
   exists, the app starts. If it does not, the visitor is sent to the installer.
2. **`install.php` creates `config.php`** from user input: database name, database user,
   database password, SMTP server, SMTP port, email user, SMTP password.
3. **`config.php` also carries `ALLOW_REGISTRATION`, default `true`.**
4. **When `ALLOW_REGISTRATION` is `false`, registration is closed on the front end *and*
   the back end.** "Front end" = the register page does not render a form / returns 403.
   "Back end" = the server-side handler rejects the POST regardless of what was rendered.
   A check in only one of the two places is a bug.
5. **The value is a real PHP boolean** — `true` / `false`, never the strings `'true'` /
   `'false'`. Compare with `ALLOW_REGISTRATION === false` (or `! ALLOW_REGISTRATION`).
6. The constant is spelled with **underscores**: `ALLOW_REGISTRATION`. A hyphen is not
   legal in a PHP constant name, so `ALLOW-REGISTRATION` could only be read back through
   `constant('ALLOW-REGISTRATION')` — do not do that.

Consequences that follow from this:

- **`config.php` is the only configuration source.** No `getenv()`, no `$_ENV`, no `.env`
  parsing in application code. A Docker entrypoint may translate environment variables
  into `config.php`, but that translation lives in `docker/`, never in `includes/`.
- **`config.php` is git-ignored.** `config.template.php` is the tracked twin and must
  declare the **same constant set** — only the values differ. Add or remove a constant in
  one, add or remove it in the other.
- **The installer writes `ALLOW_REGISTRATION` as a boolean literal.** `var_export(true, true)`
  emits `true` and `var_export(false, true)` emits `false`, so the same `var_export()` call
  used for the string settings also yields a valid boolean for this flag. Never write the
  strings `'true'` / `'false'`.
- **Delete or lock `install.php` after a successful install.** A live installer is a
  re-configuration hole.
- Never add a code path that only works under Docker.

## Commands

`php`, `composer`, `mysql`, and `mariadb` are **not on the host PATH** — everything runs
inside the container. A bare `php file.php` on the host exits **127**.

````bash
# Stack
docker compose up -d                    # app + db + phpmyadmin
docker compose up -d mockup             # the static mockup viewer (separate profile)
docker compose ps
docker compose logs -f app
docker compose down                     # keeps the DB volume — NEVER `-v`

# Lint — always, on every file you touch
docker compose exec -T app php -l includes/functions.php

# One regression test
docker compose exec -T app php database/<test>_test.php

# Whole suite (the helper handles pass/fail detection correctly)
.github/skills/dev-stack-ops/scripts/run-tests.sh
````

### Ports

The host ports are configurable through `.env` (see `.env.example`); the
defaults are chosen so they do not collide with the sibling projects:

| Service | Host port | Container port | Bind |
|---|---|---|---|
| App (LiteSpeed) | `APP_PORT` = **8088** | 80 | all interfaces |
| Mockup viewer (nginx) | `MOCKUP_PORT` = **8089** | 80 | all interfaces |
| phpMyAdmin | `PHPMYADMIN_PORT` = **8090** | 80 | all interfaces |
| LiteSpeed WebAdmin | `OLS_ADMIN_PORT` = **7080** | 7080 | **127.0.0.1 only** |

`docker compose` services are `db`, `app`, `phpmyadmin` (default profile) and
`mockup` (profile `mockup`). A bare `docker compose up -d` must **not** start
the mockup viewer.

### Two document-root paths

`AGENTS.md` and the dev-stack-ops skill say `/var/www/vhosts/localhost/html`;
the `run-tests.sh` helper defaults to `/var/www/html`. **Both work** —
`docker/entrypoint.sh` creates a symlink from the second to the first. Do not
"fix" the helper and do not remove the symlink.

`database/config_test.php` guards the config contract (boolean flag, constant spelling,
and `config.php` / `config.template.php` parity). It needs no database — it is a
structural test.

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
| [docs/issues/README.md](docs/issues/README.md) | The index of all 8 issues — DevOps (001–003) and UX/UI (004–008). |
| [docs/issues/issue-001.md](docs/issues/issue-001.md) | The Docker Compose stack: services, ports, volumes, env variables. |
| [docs/issues/issue-002.md](docs/issues/issue-002.md) | The mockup viewer container and the `docs/mockup/` contract. |
| [docs/issues/issue-003.md](docs/issues/issue-003.md) | The web-root hardening, the `config.php` write path, the CLI harness path. |
| [docs/issues/issue-004.md](docs/issues/issue-004.md) | The design charter: IA, tokens, shell, accessibility, responsive rules. |
| [docs/issues/issue-005.md](docs/issues/issue-005.md) – [issue-008.md](docs/issues/issue-008.md) | The per-screen mockup specifications. |
| [docs/mockup/README.md](docs/mockup/README.md) | The mockup delivery contract: file naming, self-containment, shared assets. |
| [.github/skills/dev-stack-ops/SKILL.md](.github/skills/dev-stack-ops/SKILL.md) | Operating the LiteSpeed + MariaDB dev stack, running tests, DB access. |
| [.github/instructions/](.github/instructions/) | Scoped conventions for PHP, database, tests, and front-end code. |
