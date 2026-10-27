# Financial Tracker — Agent Guide

Personal finance tracker for personal use: record **income and spending**, then **search and
sum over a time period** (day, week, month, year).

The product brief lives in [docs/About.md](docs/About.md) — read it before changing scope.
This file holds only what an agent cannot infer from the tree.

## Status

**Greenfield — the application code barely exists yet: only the schema has
been written. The development infrastructure and the design deliverables are
complete.**

| Exists and works | Exists, spec only | Does not exist yet |
|---|---|---|
| `docker-compose.yml` (app + db + phpMyAdmin), `docker/entrypoint.sh`, `docker/mockup-nginx.conf`, `.env.example`, `.htaccess` (web-root hardening), `config.template.php`, `composer.json` + `composer.lock` (tracked) + `vendor/` (git-ignored), `includes/mailer.php`, `includes/bootstrap.php`, `database/config_test.php`, `database/mailer_test.php`, `database/webroot_hardening_test.php`, `database/schema.sql` + `database/schema_test.php`, `docs/mockup/` (the full design-system reference page + all twelve screen mockups + the shared `assets/` layer) | `docs/issues/` — the UX/UI issues 004–008 (implemented) and **the application issues 011–020** | `index.php`, `install.php`, `assets/`, `views/`, `includes/Database.php`, `includes/functions.php` |

The DevOps track (issues 001–003, 009, 010) is **implemented and verified** —
the stack runs **on PHP 8.5.12** (the PHP2Shell patch release), the mockup
viewer runs, the web root is hardened with a regression test guarding it, and
the third-party PHP dependency is vendored at build time with Composer.
The UX/UI track (issues 004–008) is **implemented and verified** — `docs/mockup/`
holds the design-system reference page, all twelve screen mockups and the
shared token/base/component/JS layer they are built on. The mockups are
**deliverables, not placeholders**: they specify the design the application is
built from, and they are the source of truth for the token names the
application must reuse verbatim. Issue 010 (Composer as a vendor-time tool,
and the SMTP mailer) is **implemented and verified** — the dependency policy
holds, `includes/mailer.php` wraps PHPMailer, and `database/mailer_test.php`
guards both.

The **application track (issues 011–020) has its first issue in.** Issue 011
(the schema and the data model) is **implemented and verified** —
`database/schema.sql` creates the five tables, and `database/schema_test.php`
guards the column types, the unique keys, the indexes and the foreign-key
delete rules against `information_schema`. The remaining nine issues (012–020)
are **specified but not written** — they are the specification a developer
picks up to build the runtime, the token layer, the front controller, the
installer, the authentication and the five screens. The repository itself is
still mostly greenfield — `index.php`, `install.php`, `includes/`, `views/` and
`assets/` do not exist yet.

Start here: **[docs/issues/README.md](docs/issues/README.md)** — it indexes
every issue, states which track owns it, and shows where the design track
and the application track meet.

## Stack

| Layer | Choice | Constraint |
|---|---|---|
| Web server | **OpenLiteSpeed / LSPHP** | `.htaccess` **is** honoured (the vhost enables `rewrite { RewriteFile .htaccess }`) — front-controller rewrites work the same in dev and prod. |
| Language | **PHP** | The dev image ships **PHP 8.5.12** (`lsphp85`). **PHP 8.5.12 is the floor** — see "Hard rules". |
| Database | **MariaDB 10.11** | `utf8mb4`. |
| Dev environment | **Docker Compose** | **Dev only.** The app must install and run with Docker entirely out of the picture. |

No framework, no ORM, no bundler, no npm, no build step. **The runtime image has no
`composer` and no `git`.** Third-party PHP libraries are managed with **Composer as a
vendor-time tool** — never as a runtime tool:

- `composer.json` and `composer.lock` are **tracked**; `vendor/` is **git-ignored**.
- `composer install --no-dev --optimize-autoloader` runs **once, deliberately**, outside
  the container, against the project's own PHP version. The resulting `vendor/` tree is
  what gets deployed.
- **Nothing may `composer install` at container start-up**, in the installer, or in a
  request handler. The runtime never mutates itself.

See [docs/issues/issue-010.md](docs/issues/issue-010.md) for the full policy.

## Layout

````text
index.php              # front controller: install gate → route table        [TODO — issue-014]
install.php            # web installer; writes config.php (delete it after install)  [TODO — issue-015]
config.php             # git-ignored; written by install.php                  [TODO — issue-015]
config.template.php    # tracked twin — same constant set as config.php        [exists]
composer.json         # tracked — the dependency manifest                      [exists]
composer.lock         # tracked — the pinned dependency versions              [exists]
vendor/               # git-ignored; produced by the vendor-time install       [exists]
.htaccess              # web-root deny block + (later) the front-controller rewrite
.dockerignore  .env  .env.example
docker-compose.yml     # app + db + phpmyadmin (+ the "mockup" profile)
docker/
  entrypoint.sh        # adds the /var/www/html compatibility symlink
  mockup-nginx.conf    # static viewer for docs/mockup/
includes/              # [partly] mailer.php, bootstrap.php; [TODO] Database.php, functions.php, Router.php, auth.php, header/footer
views/                 # [TODO] one PHP view per screen                     [issues-017..020]
assets/css/ assets/js/  # [TODO] tokens → base → components → pages/<page>.css
database/
  schema.sql           # single source of truth for a fresh install   [issue-011 — exists]
  migrations/          # YYYYMMDD_short_name.sql, applied by hand (README.md = the contract)
  *_test.php           # standalone PHP CLI regression tests
docs/
  About.md             # the product brief
  issues/              # issue specifications (20) — see docs/issues/README.md
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

> **LiteSpeed caches the parsed `.htaccess` in memory.** A rule you just added to
> the file is **not** enforced until the server re-reads it. After editing
> `.htaccess`, run `docker compose restart app` — otherwise the live status
> codes disagree with the file and the hardening test fails for the wrong reason.

## Install and config contract — agent-critical

This is the one part of the brief that is easy to get subtly wrong.

1. **`config.php` presence is the install gate.** `index.php` checks for `config.php`; if it
   exists, the app starts. If it does not, the visitor is sent to the installer.
2. **`install.php` creates `config.php`** from user input: database host (default
   `localhost`), database name, database user, database password, SMTP server, SMTP port,
   email user, SMTP password, plus the sender identity (`MAIL_FROM`, `MAIL_FROM_NAME`).
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

### The mailer's two extra constants

The SMTP mailer needs a sender identity, which `SMTP_USER` does not always
provide. Two constants are therefore part of the contract:

````php
const MAIL_FROM = 'no-reply@example.com';   // the From: address
const MAIL_FROM_NAME = 'Financial Tracker'; // the display name
````

They follow the same rule as every other constant: **added to
`config.template.php` and `config.php` in the same change**, written by
`install.php`, and collected by the install wizard. The spec is
[docs/issues/issue-010.md](docs/issues/issue-010.md) §7.

`includes/mailer.php` already reads them. Its public surface is three
functions — `mailIsConfigured()`, `createMailer()` and `sendMail()` — and it
reads **only** `config.php`: no `getenv()`, no `$_ENV`, no `$_SERVER`. The
wizard collects the two new values in step 2 of
`docs/mockup/11-install-wizard.html`.

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
docker compose exec -T app php -l /var/www/html/includes/functions.php

# One regression test
docker compose exec -T app php /var/www/html/database/<test>_test.php

# Apply the schema to the dev database — what a fresh volume does on its own.
# The file selects the database it is given, so it can also be piped into a
# scratch database (mariadb <db> < database/schema.sql).
docker compose exec -T db mariadb -u root -p"$MYSQL_ROOT_PASSWORD" financial_tracker \
    < database/schema.sql

# Whole suite (the helper handles pass/fail detection correctly)
.github/skills/dev-stack-ops/scripts/run-tests.sh

# Install the PHP dependencies — a VENDOR-TIME step, run from the project root.
# Composer is not in the runtime image, so it is mounted in from /tmp.
# Never run this at container start-up.
curl -sSL -o /tmp/composer.phar https://getcomposer.org/composer-stable.phar
docker run --rm -v "$PWD":/app -v /tmp:/w -w /app \
  --entrypoint php litespeedtech/openlitespeed:1.9.4-lsphp85 \
  /w/composer.phar install --no-dev --optimize-autoloader --no-interaction

# After editing .htaccess — LiteSpeed caches the parsed file in memory
docker compose restart app
````

> `vendor/` must exist for the application to boot. `includes/bootstrap.php`
> throws a `RuntimeException` naming the command to run when it is missing —
> the front controller must catch it and show it, or the visitor gets a blank
> page (the image sets `display_errors = Off`).

> **The container's working directory is `/var/www/vhosts`, not the docroot** — a
> relative path fails with *"Could not open input file"*. Use the absolute path
> `/var/www/html/...` in every in-container command (both spellings of the docroot
> work; `docker/entrypoint.sh` creates the symlink).

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

### The PHP floor is 8.5.12 — the patched release, not the 8.5 branch

**PHP 8.5.12 is the floor.** Not 8.5, and not 8.2.

PHP2Shell (`CVE-2026-109618`, `CVE-2026-109619`) is a pair of unauthenticated
remote code execution vulnerabilities in the interpreter itself, affecting
**every PHP 5.4 – 8.5 build**. They were exploited in the wild before they
were even announced. The maintainers published temporary mitigations but
stated there were **no complete mitigations** and that **updating was the only
safe choice**.

| Release | Patched? |
|---|---|
| 8.5.0 – 8.5.11 | **No — vulnerable** |
| **8.5.12** | **Yes** — released 21 Oct 2026 |
| 8.2.35 / 8.3.36 / 8.4.27 | Yes (the other patched branches) |

The dev image is pinned to `litespeedtech/openlitespeed:1.9.4-lsphp85` — the
tag is **pinned, not `latest`**, because a security floor that an upstream tag
push can move is not a floor. Do not re-introduce `:latest`, and do not lower
the floor in any document.

The floor is a **minimum**, not a target: the application is plain PHP with no
framework and has no reason to use 8.5-only syntax. The point of the floor is
the **patch level**, not the language features.

The spec is [docs/issues/issue-009.md](docs/issues/issue-009.md).

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

### A failed mail is a `false`, not an exception

`sendMail()` returns `true` or `false`. It **never** lets a transport error escape — a
public "forgot password" form must not become a 500 because the SMTP server was down. A
failure is also written to the error log, so it is diagnosable.

The two states a caller must distinguish:

| State | How to detect it |
|---|---|
| Mail is not configured at all | `mailIsConfigured()` is `false` — the user must be told, and no connection is attempted. |
| Mail is configured but the send failed | `sendMail()` returned `false` — the user gets a "try again later" message. |

### Never `docker compose down -v`

`-v` deletes the MariaDB data volume. Take a dump first.

## Docs

| Doc | Use it for |
|---|---|
| [docs/About.md](docs/About.md) | The product brief: purpose, stack, installation contract. |
| [docs/issues/README.md](docs/issues/README.md) | The index of all 20 issues — DevOps (001–003, 009–010), UX/UI (004–008) and the application (011–020). |
| [docs/issues/issue-001.md](docs/issues/issue-001.md) | The Docker Compose stack: services, ports, volumes, env variables. |
| [docs/issues/issue-002.md](docs/issues/issue-002.md) | The mockup viewer container and the `docs/mockup/` contract. |
| [docs/issues/issue-003.md](docs/issues/issue-003.md) | The web-root hardening, the `config.php` write path, the CLI harness path. |
| [docs/issues/issue-004.md](docs/issues/issue-004.md) | The design charter: IA, tokens, shell, accessibility, responsive rules. |
| [docs/issues/issue-005.md](docs/issues/issue-005.md) – [issue-008.md](docs/issues/issue-008.md) | The per-screen mockup specifications. |
| [docs/issues/issue-009.md](docs/issues/issue-009.md) | The PHP 8.5.12 runtime floor: PHP2Shell, the pinned image tag, the paths that move. |
| [docs/issues/issue-010.md](docs/issues/issue-010.md) | The Composer vendor-time policy, the PHPMailer choice, the mailer config contract. |
| [docs/issues/issue-011.md](docs/issues/issue-011.md) – [issue-020.md](docs/issues/issue-020.md) | The application specification: the schema, the runtime, the tokens, the front controller, the installer, the authentication and the five screens. |
| [docs/mockup/README.md](docs/mockup/README.md) | The mockup delivery contract: file naming, self-containment, shared assets. |
| [.github/skills/dev-stack-ops/SKILL.md](.github/skills/dev-stack-ops/SKILL.md) | Operating the LiteSpeed + MariaDB dev stack, running tests, DB access. |
| [.github/instructions/](.github/instructions/) | Scoped conventions for PHP, database, tests, and front-end code. |
