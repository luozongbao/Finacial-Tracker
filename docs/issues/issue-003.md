# Issue-003 — Ops: harden the development web root and make `install.php` able to write `config.php`

> **Status:** **implemented and verified** — every acceptance criterion below was
> measured against the running stack on 2026-10-27, and the hardening is guarded
> by `database/webroot_hardening_test.php`.
> **Target:** v0.1 (development infrastructure).
> **Severity:** **high** — an unauthenticated credential disclosure existed in the dev stack until this was fixed.
>
> **Requested by the user:**
> > "create docker compose file for the project … The project should live in the /"
>
> **Depends on:** [issue-001.md](issue-001.md) (the compose stack).
> **Related:** `AGENTS.md`, "Install and config contract";
> `.github/instructions/php-app.instructions.md`.

---

## Table of contents

1. Objective
2. The defect this fixes
3. Constraints
4. Specification — the deny rules
5. Specification — the `config.php` write path
6. Specification — the CLI test-harness path
7. Acceptance criteria
8. Verification
9. Rollback
10. Risks
11. Out of scope

---

## 1. Objective

> Make the development web root safe to publish on a laptop, and make the two
> paths that must agree — the document root and the CLI test harness — agree
> in fact and not only in documentation.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `/.env` is **not** served — it holds the MariaDB root password |
| 2 | `/.git/*` is **not** served |
| 3 | `/config.php` is **not** served |
| 4 | `/database/*`, `/includes/*`, `/docker/*` are **not** served |
| 5 | `/docs/issues/*` is **not** served |
| 6 | `/docs/mockup/*` **is** served — it is a deliverable, not a secret |
| 7 | `install.php` can write `config.php` into the project root |
| 8 | The regression suite runs from the documented path **and** from the helper’s default path |
| 9 | The rules hold in production, because they live in `.htaccess` and LiteSpeed honours it |

---

## 2. The defect this fixes

The project is mounted at the document root so that the application lives at
`/`. That makes **every file in the repository reachable over HTTP** unless it
is explicitly denied.

Measured on the stack from issue-001, before the fix:

````text
GET /.env                  -> 200   "# Local development environment …"
                             ^^^ the file contains MYSQL_ROOT_PASSWORD
GET /.git/config           -> 200   "[core] repositoryformatversion = 0"
GET /.gitignore            -> 200
GET /.htaccess             -> 200
GET /database/config_test.php -> 200
GET /AGENTS.md             -> 200
GET /docs/About.md         -> 200
````

`.env` is git-ignored, which protects the repository — **not** the web
server. On a laptop, on a shared network, or through a port-forward, the
database root password was one `GET` away.

Measured after the fix:

````text
GET /.env                  -> 403
GET /.env.example          -> 403
GET /.git/config           -> 403
GET /.gitignore            -> 403
GET /.htaccess             -> 403
GET /config.php            -> 403
GET /database/config_test.php -> 403
GET /docs/mockup/          -> 200
GET /docs/mockup/index.html -> 200
````

> **Extended by [issue-023](issue-023.md).** The deny block was still a list of
> exceptions, so four files that were committed *after* it was written were
> still served — `AGENTS.md`, `docs/About.md`, `docker-compose.yml` and
> `config.template.php`. Issue 023 closed them:
>
> ````text
> GET /AGENTS.md             -> 403
> GET /docs/About.md         -> 403
> GET /docker-compose.yml    -> 403
> GET /config.template.php   -> 403
> GET /docs/                  -> 403
> ````

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| The project must be served at `/` | The tree cannot be pruned by the mount; it must be pruned by the web server. |
| The stack is LiteSpeed | The rules must be written in Apache-compatible `.htaccess` syntax. LiteSpeed's `docker` vhost template enables `rewrite { RewriteFile .htaccess }` and `autoLoadHtaccess 1`, so the file is honoured — **verified**. |
| `.htaccess` is also the future front-controller file | The deny block must survive the application implementation. The application implementer appends the rewrite rules; the deny block stays on top. |
| `config.php` is **included** by the application, not requested over HTTP | Denying the direct request does not affect the application. |
| `docs/mockup/` must stay reachable | The blanket "deny `docs/`" rule is wrong; only `docs/issues/` is denied. |
| The mockup container serves `docs/mockup/` separately | That container has its own, stricter rules (issue-002). |

---

## 4. Specification — the deny rules

The rules live in the **document root `.htaccess`** — the same file the
application's front-controller rewrite will use. They must not be moved into a
directory the web server does not read, and they must not be replaced
wholesale when the application is implemented.

````apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # ---- Dotfiles --------------------------------------------------------
    # .env, .env.*, .git, .github, .gitignore, .htaccess, editor swap files.
    # .well-known/ stays reachable (it is a standard public path).
    RewriteRule "(^|/)\.(?!well-known)" - [F,L]

    # ---- Secrets and generated configuration -----------------------------
    RewriteRule "^/?config\.php$" - [F,L]

    # ---- Internal source trees ------------------------------------------
    RewriteRule "^/?database/"  - [F,L]
    RewriteRule "^/?includes/"  - [F,L]
    RewriteRule "^/?docker/"    - [F,L]
    RewriteRule "^/?vendor/"    - [F,L]

    # ---- Internal documentation -----------------------------------------
    RewriteRule "^/?docs/issues/" - [F,L]
</IfModule>
````

### Why `RewriteRule … [F,L]` and not `<FilesMatch>` + `Require all denied`

Both were tried. On this stack:

| Form | Result |
|---|---|
| `<FilesMatch "^\.env">` + `Require all denied` | **No effect** — the request still returned 200. |
| `RewriteRule "(^\|/)\.env" - [F,L]` | **403**, as intended. |

The LiteSpeed vhost template sets `restrained 1`, which limits the
`.htaccess` directive set. `RewriteRule` is inside the allowed set; the
Apache 2.4 authorisation directives are not. **Use `RewriteRule` with the
`[F]` flag.** Do not "simplify" this to `<FilesMatch>` later — it will
silently stop protecting anything.

### Two gotchas that cost time

1. **`.htaccess` is read at request time, but a freshly created `.htaccess`
   is not picked up by a running server until it is re-read.** After adding
   or changing the file, restart the app container:
   `docker compose restart app`.
2. **The per-directory rewrite pattern is matched against the path relative
   to the directory.** Both `^config\.php$` and `^/?config\.php$` are used in
   the block above; the `/?` makes the pattern correct whether or not the
   leading slash is present.

---

## 5. Specification — the `config.php` write path

`install.php` writes `config.php` into the project root. That root is a bind
mount from the host.

| Fact | Value |
|---|---|
| The LSPHP worker uid/gid | **1000 / 1000** in this image (`setUIDMode 2`, container user `ubuntu`). |
| The host file owner | The developer's own uid — **1000** on a typical Linux host. |
| Consequence | The worker can already create `config.php`. **No `chown` is needed** in the default setup. |

The entrypoint therefore performs only a top-level `chmod u+rwx` on the
docroot. A **recursive** `chown` is explicitly avoided: it rewrites the
ownership of the whole repository on the host and is an unpleasant surprise.

If the developer’s uid is *not* 1000 (a CI runner, a shared account), the
fallback is:

````bash
docker compose exec --user root app chown -R nobody:nogroup /var/www/vhosts/localhost/html
````

> `install.php` must remain the **only** writer of `config.php`. A Docker
> entrypoint that pre-creates `config.php` would bypass the install gate —
> `index.php` redirects to the installer precisely when `config.php` is
> missing — and would make the wizard untestable in development.

---

## 6. Specification — the CLI test-harness path

Two different document-root paths are documented for this project:

| Document | Path |
|---|---|
| `AGENTS.md`, `.github/skills/dev-stack-ops/SKILL.md` | `/var/www/vhosts/localhost/html` |
| `.github/skills/dev-stack-ops/scripts/run-tests.sh` (`APP_ROOT` default) | `/var/www/html` |

Only the first one exists in the image. Without a fix, the helper runs the
suite from a directory that does not exist and every test "passes" vacuously
— or fails for the wrong reason.

**The fix is in `docker/entrypoint.sh`**, not in the helper:

````bash
ln -s /var/www/vhosts/localhost/html /var/www/html
````

Both paths then address the same tree, and the helper needs no change:

````bash
docker compose exec -T app sh -c 'readlink -f /var/www/html'
# -> /var/www/vhosts/localhost/html
````

The helper was verified end to end against this stack:

````text
== Running 1 test(s) ==
  [PASS] database/config_test.php

=== 1 PASS / 0 FAIL ===
````

> **A passing suite is detected by the exit code, never by the output.** The
> success markers are inconsistent across the suite, and a missing PHP
> interpreter produces output that does not contain the word "FAIL" — which
> makes a naive grep-based loop report a **false green** for every test.
> Use `.github/skills/dev-stack-ops/scripts/run-tests.sh`, which captures the
> exit code and treats exit **127** as an error.

---

## 7. Acceptance criteria

- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:${APP_PORT}/.env` returns **403**.
- [ ] The same request for `/.env.example`, `/.git/config`, `/.gitignore`, `/.htaccess` returns **403**.
- [ ] `curl ... /config.php` returns **403**.
- [ ] `curl ... /database/config_test.php` returns **403**.
- [ ] `curl ... /docs/issues/issue-001.md` returns **403**.
- [ ] `curl ... /docs/mockup/index.html` returns **200**.
- [ ] `curl ... /AGENTS.md` returns **403** — extended by
      [issue-023](issue-023.md), which also closed `/docs/About.md`,
      `/docker-compose.yml` and `/config.template.php`.
- [ ] `docker compose exec -T app sh -c 'readlink -f /var/www/html'` resolves to `/var/www/vhosts/localhost/html`.
- [ ] `docker compose exec -T app sh -c 'touch /var/www/vhosts/localhost/html/.wtest && rm /var/www/vhosts/localhost/html/.wtest'` succeeds — the docroot is writable by the worker.
- [ ] `.github/skills/dev-stack-ops/scripts/run-tests.sh` reports `=== N PASS / 0 FAIL ===` and exits 0.
- [ ] `docker compose exec -T app php -l config.template.php` reports no syntax errors (the lint path resolves through the symlink).
- [ ] A regression test asserts that `/.env` returns 403 — see §7.1.

### 7.1 Regression test

The hardening must not rot. The natural home is a structural test that boots
the app container and asserts on the HTTP status codes, e.g.
`database/webroot_hardening_test.php`:

- boot the stack (or skip with a clear message when the stack is not running),
- `GET /.env` → 403,
- `GET /.git/config` → 403,
- `GET /config.php` → 403,
- `GET /docs/mockup/index.html` → 200.

The test follows the conventions in
`.github/instructions/tests.instructions.md`: plain PHP CLI, exit code 0 on
success, a random marker in any seed, and unconditional cleanup.

---

## 8. Verification

````bash
cd /home/zongbao/projects/financial-tracker
docker compose up -d && docker compose restart app

echo "--- must be 403 ---"
for f in .env .env.example .git/config .gitignore .htaccess config.php database/config_test.php; do
  printf "%-28s " "$f"
  curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:${APP_PORT:-8088}/$f"
done

echo "--- must be 200 ---"
for f in docs/mockup/ docs/mockup/index.html docs/mockup/assets/tokens.css; do
  printf "%-28s " "$f"
  curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:${APP_PORT:-8088}/$f"
done

echo "--- docroot writability ---"
docker compose exec -T app sh -c 'touch /var/www/vhosts/localhost/html/.wtest && rm /var/www/vhosts/localhost/html/.wtest && echo WRITABLE'

echo "--- harness path ---"
docker compose exec -T app sh -c 'readlink -f /var/www/html'

echo "--- suite ---"
.github/skills/dev-stack-ops/scripts/run-tests.sh
````

---

## 9. Rollback

The hardening is one file. To roll it back:

````bash
git checkout -- .htaccess
docker compose restart app
````

> Do **not** roll it back permanently. The exposure it closes is a live
> credential disclosure on a development machine.

---

## 10. Risks

| Risk | Mitigation |
|---|---|
| The application implementer replaces `.htaccess` wholesale and drops the deny block | The file header says explicitly: *keep the deny block intact, append the front-controller rules below it*. A regression test asserts `/.env` → 403. |
| A deny rule is too broad and breaks a legitimate route | The deny list is explicit and short. `docs/mockup/` is deliberately **not** denied. The patterns were verified against real requests, not assumed. |
| `restrained 1` silently drops a directive | Verified empirically: `<FilesMatch>` + `Require all denied` had **no effect**, `RewriteRule … [F]` worked. The rule set uses only the directives that were proven to work. |
| `AGENTS.md` and `docs/About.md` remain public | **Closed** by [issue-023](issue-023.md) — both are refused with **403** now, along with `docker-compose.yml` and `config.template.php`. The deny block is still a list of exceptions, so a **new** file added to the web root is published until a rule names it; the rule must land in the same change as the file. |
| The rules are only in the dev stack | They live in `.htaccess` in the repository, so a production checkout inherits them. The production web server must honour `.htaccess` — LiteSpeed does. |

---

## 11. Out of scope

- **Production hardening.** TLS, HSTS, rate limiting and log retention are
  deployment concerns.
- **A WAF or a reverse proxy.** The dev stack has no reverse proxy in front
  of LiteSpeed.
- **Rewriting the application to avoid serving files.** The application is
  front-controller based; the deny rules are a second layer, not the only one.
- **Secret management.** `.env` holds development credentials by design; the
  tracked `.env.example` holds placeholders only.
