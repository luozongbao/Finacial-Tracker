# Financial Tracker — Agent Guide

Personal finance tracker for personal use: record **income and spending**, then **search and
sum over a time period** (day, week, month, year).

The product brief lives in [docs/About.md](docs/About.md) — read it before changing scope.
This file holds only what an agent cannot infer from the tree.

## Status

**Greenfield — the application code barely exists yet: only the schema, the
core runtime, the design-token layer, the front controller, the install
wizard, the authentication, the dashboard, the transaction, the
categories/settings and the reports screens have been written. The development
infrastructure and the design deliverables are complete.**

| Exists and works | Exists, spec only | Does not exist yet |
|---|---|---|
| `docker-compose.yml` (app + db + phpMyAdmin), `docker/entrypoint.sh`, `docker/mockup-nginx.conf`, `.env.example`, `.htaccess` (web-root hardening + the front-controller rewrite), `config.template.php`, `composer.json` + `composer.lock` (tracked) + `vendor/` (git-ignored), `index.php` (the front controller), `install.php` (the three-step install wizard), `includes/mailer.php`, `includes/bootstrap.php`, `includes/Database.php`, `includes/functions.php`, `includes/auth.php`, `includes/Router.php`, `includes/header.php`, `includes/footer.php`, `includes/views/` (the four error pages + the bootstrap page), `views/` (the six auth screens: login, register, forgot password, reset password, verify email, sign out) + `views/dashboard.php` + `views/transactions.php` + `views/transaction_form.php` + `views/transaction_save.php` + `views/transaction_delete.php` + `views/categories.php` + `views/category_save.php` + `views/category_delete.php` + `views/settings.php` + `views/reports.php`, `assets/css/tokens.css` + `base.css` + `components.css` + `pages/install.css` + `pages/auth.css` + `pages/dashboard.css` + `pages/transactions.css` + `pages/categories.css` + `pages/settings.css` + `pages/reports.css`, `assets/js/app.js`, `database/config_test.php`, `database/mailer_test.php`, `database/webroot_hardening_test.php`, `database/schema.sql` + `database/schema_test.php`, `database/runtime_test.php`, `database/assets_test.php`, `database/front_controller_test.php`, `database/install_test.php`, `database/auth_test.php`, `database/dashboard_test.php`, `database/transactions_test.php`, `database/categories_test.php`, `database/settings_test.php`, `database/reports_test.php`, `database/migrations/20261028_add_login_attempts.sql`, `database/live/` (the LIVE tier — the running-application sweep + the runner), `docs/mockup/` (the full design-system reference page + all twelve screen mockups + the shared `assets/` layer) | — | — |

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

The **application track (issues 011–020) has all ten issues in.** Issue 011
(the schema and the data model) is **implemented and verified** —
`database/schema.sql` creates the five tables, and `database/schema_test.php`
guards the column types, the unique keys, the indexes and the foreign-key
delete rules against `information_schema`. Issue 012 (the core runtime) is
**implemented and verified** — `includes/Database.php` (the PDO singleton),
`includes/functions.php` (escaping, money, dates, CSRF, validation, the
ownership filter), `includes/auth.php` (the session, the guard, the
registration gate) and `includes/Router.php` (the method-and-path table) exist,
and `database/runtime_test.php` guards them. Issue 013 (the design-token layer
and the shared shell) is **implemented and verified** — `assets/css/tokens.css`,
`base.css` and `components.css` are carried over from `docs/mockup/assets/`
verbatim, `assets/js/app.js` holds every behaviour behind a single
`DOMContentLoaded` listener, `includes/header.php` and `includes/footer.php`
render the app and guest shells, and `database/assets_test.php` guards all of
it. Issue 014 (the front controller) is **implemented and verified** —
`index.php` holds the install gate, the security headers and the error
boundary, `includes/views/` holds the four error pages and the bootstrap page,
the `.htaccess` rewrite hands everything that is not a real file to the
controller, and `database/front_controller_test.php` guards all of it. Issue 015
(the install wizard) is **implemented and verified** — `install.php` collects
the database, the mail and the access settings across three steps, proves each
one before it advances, loads the schema, writes `config.php` atomically and
refuses to run once `config.php` exists, and `database/install_test.php` guards
all of it. Issue 016 (the authentication) is **implemented and verified** —
`includes/auth.php` holds the session, the sign-in throttle, the password and
verification tokens and the credential version; `views/` holds the six auth
screens; `assets/css/pages/auth.css` is the stylesheet they share; the
mailer is loaded by the front controller; and `database/auth_test.php` guards
all of it in two tiers — the sources, and a sandbox that runs the real screens
against a scratch database with a stub mailer. Issue 017 (the dashboard) is
**implemented and verified** — `views/dashboard.php` holds the three tiles,
the recent card and the three empty states; the four shared helpers
(`formatSignedMoney()`, `isZeroAmount()`, `formatPeriodLabel()`,
`formatPeriodSpan()`) live in `includes/functions.php`;
`assets/css/pages/dashboard.css` is the stylesheet the screen needs; and
`database/dashboard_test.php` guards all of it in two tiers — the sources,
and a sandbox that runs the real screen against a scratch database. Issue 018
(the transaction list and the transaction form) is **implemented and
verified** — `views/transactions.php` holds the filter bar, the presets, the
table and the three states; `views/transaction_form.php` serves the add and
the edit screen from **one** template; `views/transaction_save.php` and
`views/transaction_delete.php` are the two write paths; the new helpers live
in `includes/functions.php`; `assets/css/pages/transactions.css` is the
stylesheet the screens share; and `database/transactions_test.php` guards all
of it in two tiers — the sources, and a sandbox that runs the real screens
against a scratch database. Issue 020 (the categories and the settings) is
**implemented and verified** — `views/categories.php` holds the list, the
create form, the empty state, the server-rendered rename and the delete
dialogs; `views/category_save.php` and `views/category_delete.php` are the
two write paths; `views/settings.php` serves the profile, the password and
the read-only installation card from one view; the new helpers live in
`includes/functions.php`; `assets/css/pages/categories.css` and
`assets/css/pages/settings.css` are the two stylesheets the screens need; and
`database/categories_test.php` and `database/settings_test.php` guard all of
it in two tiers each — the sources, and a sandbox that runs the real screens
against a scratch database. Issue 019 (the reports) is **implemented and
verified** — `views/reports.php` holds the period selector, the custom-range
form, the status line, the three tiles and the two tables; the eight report
helpers (`resolveMonthRange()`, `reportGrain()`, `reportSpanDays()`,
`reportBucketExpression()`, `reportBucketEnd()`, `clipDateRange()`,
`formatShare()` and `formatIncomeMoney()`) live in `includes/functions.php`;
`assets/css/pages/reports.css` is the stylesheet the screen needs; and
`database/reports_test.php` guards all of it in two tiers — the sources, and a
sandbox that runs the real screen against a scratch database. **All twenty
issues are in.** The repository itself is still mostly greenfield — the
remaining work is the user's own data, not more code.

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
index.php              # front controller: install gate → route table        [exists — issue-014]
install.php            # web installer; writes config.php (delete it after install)  [exists — issue-015]
config.php             # git-ignored; written by install.php                  [written by issue-015]
config.template.php    # tracked twin — same constant set as config.php        [exists]
composer.json         # tracked — the dependency manifest                      [exists]
composer.lock         # tracked — the pinned dependency versions              [exists]
vendor/               # git-ignored; produced by the vendor-time install       [exists]
.htaccess              # web-root deny block + the front-controller rewrite   [issue-014]
.dockerignore  .env  .env.example
docker-compose.yml     # app + db + phpmyadmin (+ the "mockup" profile)
docker/
  entrypoint.sh        # adds the /var/www/html compatibility symlink
  mockup-nginx.conf    # static viewer for docs/mockup/
includes/              # [exists] mailer.php, bootstrap.php, Database.php, functions.php, auth.php, Router.php
                       # [exists] header.php, footer.php — the two shells   [issue-013]
  views/               # [exists] the four error pages + the bootstrap page [issue-014]
views/                 # [exists] the six auth screens                   [issue-016]
                       # [exists] dashboard.php                          [issue-017]
                       # [exists] transactions.php, transaction_form.php,
                       #            transaction_save.php, transaction_delete.php [issue-018]
                       # [exists] categories.php, category_save.php,
                       #            category_delete.php, settings.php    [issue-020]
                       # [exists] reports.php                           [issue-019]
assets/css/            # [exists] tokens.css → base.css → components.css   [issue-013]
  pages/               # [exists] install.css                            [issue-015]
                       # [exists] auth.css                              [issue-016]
                       # [exists] dashboard.css                         [issue-017]
                       # [exists] transactions.css                     [issue-018]
                       # [exists] categories.css, settings.css         [issue-020]
                       # [exists] reports.css                           [issue-019]
assets/js/app.js       # [exists] every behaviour, one DOMContentLoaded     [issue-013]
assets/img/            # [exists] empty — the app has no raster assets in v0.2
database/
  schema.sql           # single source of truth for a fresh install   [issue-011 — exists]
  migrations/          # YYYYMMDD_short_name.sql, applied by hand (README.md = the contract)
  *_test.php           # standalone PHP CLI regression tests (the SOURCE tier)
  live/                # the LIVE tier — sweeps the running application over HTTP
    *_test.php         # live_app_test.php, live_security_test.php
    run-live-tests.sh  # the runner (NOT part of run-tests.sh)
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
   **This is implemented** — the `is_file()` check runs *before* the dependency bootstrap,
   so a fresh checkout redirects to the installer instead of dying on a missing
   `vendor/`. `database/front_controller_test.php` guards the ordering.
2. **`install.php` creates `config.php`** from user input: database host (default
   `localhost`), database name, database user, database password, SMTP server, SMTP port,
   email user, SMTP password, plus the sender identity (`MAIL_FROM`, `MAIL_FROM_NAME`).
   **This is implemented** — the wizard collects the settings across three steps
   (database, mail, access), proves each step before it advances, loads
   `database/schema.sql`, writes `config.php` with `var_export()` into a temporary
   file, **parse-checks the file it wrote** and renames it into place.
   `database/install_test.php` guards all of it.
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
  re-configuration hole. The wizard tells the operator to delete it, shows the exact
  `rm -f` command, and **refuses to run with a 403** once `config.php` exists — the
  refusal is the belt to the operator's braces.
- **`install.php` is a single file and it is the only writer of `config.php`.** It
  renders its **own** HTML document — no `includes/header.php`, no
  `includes/footer.php`, no app nav, no footer link farm — and it requires only
  `includes/functions.php` (plus `vendor/autoload.php` **when it exists**, so a
  machine with no `vendor/` can still finish the wizard).
- **The wizard keeps its state in the session, never in a hidden field.** The CSRF
  token is validated **before** any input is read, and a POST that names a step the
  session has not reached is refused.
- **`index.php` is the only entry point.** The `.htaccess` rewrite hands every URL that is
  not a real file to the controller, and the route table lives in
  `Router::defaultRoutes()` — never in a second copy inside the controller.
- **A view owns its page.** The controller never emits the header or the footer; each
  view requires `includes/header.php` and `includes/footer.php` itself. A `redirect:`
  route answers with a zero-byte body.
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
# It requires exit code 0 AND a completion marker — a test that died half way
# through is reported as [ERROR], never as [PASS].
.github/skills/dev-stack-ops/scripts/run-tests.sh

# The LIVE tier — sweeps the RUNNING application over HTTP.
# It is deliberately NOT part of run-tests.sh: it needs a booted stack and an
# installed config.php, and it writes to (and cleans up) the dev database.
bash database/live/run-live-tests.sh
# LIVE_BASE_URL=http://127.0.0.1:8088 bash database/live/run-live-tests.sh

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

**Exit code 0 alone is not enough — the test must also have printed a completion
marker** (a `Failed: N` line, or `ALL ASSERTIONS PASSED`). A test that dies half way
through leaves exit code 0 and no summary: a handler that calls `exit()`, or a fatal
error with `display_errors` off, ends the process silently and every tier after the
point of death never runs. `run-tests.sh` enforces this and reports such a run as
`[ERROR]`, never `[PASS]`.

> This is not theoretical. `database/runtime_test.php` §D used to dispatch a real
> route; the handler called `requireLogin()` → `redirect()` → `exit()`; the test
> died silently; **§E (the database tier) and §F (the gate and the guard tier)
> never ran** — and the suite still reported PASS. A test must never call
> `exit`-terminating application code in-process: use a child process.

### A bare substring scan over a page body is a flaky assertion

Every rendered page carries a **random 64-character hex CSRF token**. A
`str_contains($body, '587')` scan for the SMTP port therefore matches the
*token*, not a leak — roughly one run in nine. The token is the only
dynamic value with that property: `SMTP`, `smtp`, `SQLSTATE` and
`PDOException` all contain letters outside the hex alphabet, so they cannot
be faked that way.

- A **numeric** needle must not match inside a longer word or digit run:
  `/(?<![\w.])587(?![\w.])/`. A real leak still reads `:587`.
- **Prove a tightened guard can still fire.** `database/auth_test.php` carries
  two self-checks: a synthetic real leak must be caught, and the offending
  token must be ignored. A guard nobody has proved can still fire is not a
  guard.
- **A failing assertion must print where the needle matched** — the offset
  and the surrounding text — not just which needle. Without that, the next
  person has to guess.

### The SOURCE tier and the LIVE tier answer different questions

`database/*_test.php` (the **SOURCE** tier) exercises the code without a browser:
it greps the sources, and it drives the classes against a scratch database. It
is fast, hermetic and it runs in CI.

`database/live/*_test.php` (the **LIVE** tier) drives the **running** application
over HTTP — real routing, real cookies, real headers, real forms. It is slower
and it needs a booted stack, so `run-tests.sh` does **not** include it. Run it
with `bash database/live/run-live-tests.sh`.

> The LIVE tier exists because the SOURCE tier could not see a whole class of
> defect. The install bug was **web-SAPI-only**: under `PHP_SAPI === 'litespeed'`
> (what `install.php` runs under) `PHP_BINARY` is the **empty string**, while
> under `PHP_SAPI === 'cli'` (what every test ran under) it is the full path.
> A CLI-only suite passed cleanly while the shipped installer could never
> succeed. **A defect that only exists in the deployed SAPI is invisible to a
> CLI-only suite** — so the release gate needs both tiers.

A LIVE test **writes to the dev database**, so it must clean up after itself.
Every live test registers a shutdown function that deletes the accounts it
created (`live_test_*`). A live test that rewrites `config.php` does **not**
belong in this tier — that belongs in a sandbox (`database/auth_test.php`
covers the `ALLOW_REGISTRATION` gate two-sided, in a sandbox, for exactly that
reason).

### `Database::execute()` returns bool, not a row count

````php
$db->execute($sql, $params);
$id = (int)$db->lastInsertId();        // correct
$id = (int)$db->execute($sql, $params); // BUG — always 1
````

`EMULATE_PREPARES` is **false** → native prepares → **one statement per call**.

There is **no `rowCount()`** on the class. A write that must know whether the
row existed has to **SELECT it first**, with the ownership predicate in the
same statement — a delete of a row that is not there is a **404**, not a
silent success.

### A heredoc interpolates a variable, never an expression

````php
$rowsSql = <<<SQL
    LIMIT {(int) $perPage} OFFSET {(int) $offset}
SQL;
````

The text above reaches the database as the literal string `{(int) 25} OFFSET
{(int) 0}` — a heredoc interpolates a **variable**, so the cast is never
evaluated. **Cast first, interpolate second:**

````php
$limitValue  = (int) $perPage;
$offsetValue = (int) $offset;

$rowsSql = <<<SQL
    LIMIT {$limitValue} OFFSET {$offsetValue}
SQL;
````

### A `??` comparison can never prove a value is null

`($row['category_id'] ?? 'unset') === null` is **always false**: the `??`
operator returns its right-hand side whenever the left one is null. To test
for a null column, use `array_key_exists('category_id', $row) && $row['category_id'] === null`.

### Money is never a float

Amounts are `DECIMAL(14,2)` in the database. Never do arithmetic on PHP floats for money
(`0.1 + 0.2 !== 0.3`). Compute sums in SQL, read them back as strings, and format for
display with `number_format()`.

The negative sign is **U+2212** (`−`), never an ASCII hyphen, and it is written as the
**literal character** — `"\u{2212}"` renders the same but is invisible to a source-level
check, and a grep for the glyph does not find it.

### Period sums are computed in SQL

Day / week / month / year grouping belongs in the query, not in a PHP loop over rows.
Store transaction dates as `DATE` in UTC, and keep the week-start rule in one place
(`WEEK_START` config constant, Monday by default) so reports and the UI agree.

The **period label** is a span, not a month: a range that straddles two months must not
read "October", because the screen summed something that is not a calendar month.
`formatPeriodLabel()` and `formatPeriodSpan()` are the only places a label is built, and
they use the **long** month name (`F`) — the mockup says "19 – 25 October 2026", not
"19 – 25 Oct 2026".

### Output escaping and CSRF

- `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) on **every** dynamic value in HTML.
- Validate the CSRF token **before** reading any input, and put the hidden input in
  **every** form: `<input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">`.
- **A GET form carries no CSRF token.** A token in a GET form lands in the URL, in the
  history and in the `Referer` header. A form that cannot change state does not need one.
- Ownership is enforced **in the SQL** by filtering on the owning user id.

### The theme travels in a cookie

Dark mode is a **designed** feature: `assets/css/tokens.css` carries four blocks —
`:root`, the `prefers-color-scheme: dark` media query, `:root[data-theme="dark"]`
and `:root[data-theme="light"]`. The choice must be in `<html>` **before the first
paint**, and the application has **no inline script** while the server cannot read
`localStorage` — so the channel is a **`theme` cookie**.

- The server reads `$_COOKIE['theme']` and **whitelists** it to `dark` / `light`.
  An unknown value is dropped, and the media query then applies the system
  preference — which is the correct default.
- `assets/js/app.js` mirrors the value into `localStorage` **and** writes the cookie
  back, so the two never disagree.
- The toggle **always sets** the explicit `data-theme` attribute and **never removes
  it**. Removing it hands the page back to the system preference, so a system-dark
  visitor would stay dark after choosing light.
- The active state is **`aria-current`**, never a class: `page` on the nav and
  bottom-nav links, `true` on the period links. `components.css` knows no
  `is-active`.

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

### The auth layer — the traps that leak other people's money records

- **The front controller loads the mailer.** `index.php` requires
  `includes/mailer.php`. A view that calls `mailIsConfigured()` or `sendMail()`
  without it dies with *"Call to undefined function"*, which the error boundary
  turns into a **500**. This was a real defect, found by `database/auth_test.php`
  — the mailer file existed and was tested, but nothing required it.
- **A link in a mail is built with `absoluteUrl()`.** It reads
  `$_SERVER['SERVER_NAME']` and **never** `HTTP_HOST` — the `Host:` header is
  client-controlled, and a reset link built from it can be pointed at an
  attacker's host.
- **The session carries the user id — never the password hash.** It also carries
  a *credential version* (the hash of the stored hash), and the front controller
  compares it with the row on every request, so a password change ends the
  other sessions. `loginUser()` and `requireLogin()` must not touch the
  database.
- **The sign-in throttle is counted per identifier**, in `login_attempts`,
  keyed by a SHA-256 of the lower-cased identifier. The account row can only
  lock an account that exists, so without the table the limit is bypassable by
  typing a name that does not exist.
- **The registration gate is two-sided** — `GET` and `POST`, and the `POST`
  check runs **before** the CSRF check and before any input is read (see the
  install/config contract above).
- **`views/` is denied in `.htaccess`.** The views are rendered by the front
  controller and never requested directly. **Extend the deny block — never
  replace the file wholesale.**

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
