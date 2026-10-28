# Issue-023 — Security: the web root still serves the deployment topology and the internal documentation

> **Status:** **open** — found by the audit of 2026-10-28, measured against the
> running application.
> **Target:** `.htaccess`, `database/webroot_hardening_test.php`,
> `docker-compose.yml`, `AGENTS.md`
> **Severity:** **Medium** — nothing secret is disclosed, but the *default
> credential set* of the whole development stack is published, and the
> hardening test currently **asserts** that the leak is there.
> **Requested by the user:** "Now the implementation is complete. before release
> v.1.0, let's focus on security for a bit. 1. help check sql injection, or any
> security risk. and write issue files for dev to fix those risks."
> **Depends on:** [issue-003](issue-003.md) (the web-root hardening — the deny
> block this issue extends),
> [issue-001](issue-001.md) (the compose stack whose topology is disclosed),
> [issue-014](issue-014.md) (the front-controller rewrite, which shares the
> same file)
> **Related:** [issue-024](issue-024.md) (the response headers)

---

## Table of contents

1. [Objective](#1-objective)
2. [Finding 1 — the deny block is a list of exceptions, not a default](#2-finding-1--the-deny-block-is-a-list-of-exceptions-not-a-default)
3. [Finding 2 — the measured disclosure set](#3-finding-2--the-measured-disclosure-set)
4. [Finding 3 — what `/docker-compose.yml` actually discloses](#4-finding-3--what-docker-composeyml-actually-discloses)
5. [Finding 4 — the admin console is bound more widely than the documentation says](#5-finding-4--the-admin-console-is-bound-more-widely-than-the-documentation-says)
6. [Finding 5 — the hardening test pins the leak in place](#6-finding-5--the-hardening-test-pins-the-leak-in-place)
7. [The fix](#7-the-fix)
8. [Acceptance criteria](#8-acceptance-criteria)
9. [Verification](#9-verification)
10. [Rollback](#10-rollback)
11. [Risks](#11-risks)
12. [Out of scope](#12-out-of-scope)

---

## 1. Objective

The web root **is** the repository root. The `.htaccess` deny block exists
because of that, and it works: `.env`, `.git/`, `database/`, `includes/`,
`views/`, `docker/`, `vendor/`, `composer.json`, `composer.lock` and
`docs/issues/` are all refused with **403**.

The problem is what is **left**. The deny block is a list of specific paths to
refuse, and four files that were added to the repository *after* that list was
written are still served. One of them publishes the **default credential set**
of the development stack.

Two stages of work, in this order:

| Stage | What | Risk |
|---|---|---|
| **1** | Extend the deny block with the four measured paths. | Low — additive, and the regression test covers it. |
| **2** | Invert the rule: deny everything, allow the known public paths explicitly. | Medium — a new mechanism, so it needs its own regression. |

> **The repository's rule stands.** `AGENTS.md` says: *"Extend the deny block
> — never replace the file wholesale."* Stage 1 **extends** it. Stage 2 is a
> deliberate, separately-tested inversion, and it is **not** part of the v1.0
> critical path.

---

## 2. Finding 1 — the deny block is a list of exceptions, not a default

**Severity: Medium** (the structural cause of the other findings).

`.htaccess` is written as a series of specific refusals:

```
RewriteRule "^/?database/"  - [F,L]
RewriteRule "^/?includes/"  - [F,L]
RewriteRule "^/?docker/"    - [F,L]
RewriteRule "^/?vendor/"    - [F,L]
RewriteRule "^/?views/"     - [F,L]
RewriteRule "^/?composer\.(json|lock)$" - [F,L]
RewriteRule "^/?docs/issues/" - [F,L]
```

Every path that is **not** on that list is served. The deny block therefore
covers the files that existed when it was written, and it silently fails to
cover the files that were added afterwards — which is exactly what happened.

The consequence is a permanent, silent gap: a future `README.md`, a
`CHANGELOG.md`, a `LICENSE`, a `Makefile`, a `phpunit.xml`, a `.editorconfig`
or a stray `notes.txt` is **published** the moment it is committed, with no
error, no warning and no failing test.

---

## 3. Finding 2 — the measured disclosure set

A sweep of the running application:

| Path | Status | Verdict |
|---|---|---|
| `/.env` | **403** | Correct — the MariaDB root password is not published. |
| `/.env.example` | **403** | Correct. |
| `/.gitignore`, `/.dockerignore`, `/.htaccess`, `/.github/` | **403** | Correct. |
| `/config.php` | **403** | Correct. |
| `/database/`, `/includes/`, `/docker/`, `/vendor/`, `/views/` | **403** | Correct. |
| `/composer.json`, `/composer.lock` | **403** | Correct. |
| `/docs/issues/` | **403** | Correct. |
| `/docs/mockup/` | **200** | **Deliberate** — the UX deliverable, also served by the mockup container. |
| **`/docker-compose.yml`** | **200** | **The finding.** |
| **`/config.template.php`** | **200** | **The finding.** |
| **`/AGENTS.md`** | **200** | **The finding.** |
| **`/docs/About.md`** | **200** | **The finding.** |
| `/docs/` | 404 | No directory index — acceptable, but the directory should still be denied so a future index cannot appear. |

---

## 4. Finding 3 — what `/docker-compose.yml` actually discloses

**Severity: Medium.** (The file is a development artefact, but it publishes
the defaults.)

`docker-compose.yml` is the development stack. It is not the production
deployment — production is plain LiteSpeed + PHP + MariaDB — so this is not a
production secret. It is a **map with the default keys drawn on it**:

```yaml
    environment:
      - TZ=Asia/Bangkok
      - ADMIN_USER=${OLS_ADMIN_USER:-admin}
      - ADMIN_PASSWORD=${OLS_ADMIN_PASSWORD:-P@ssw0rd}
```

```yaml
    environment:
      MARIADB_DATABASE: ${DB_NAME:-financial_tracker}
      MARIADB_USER: ${DB_USER:-ft_user}
      MARIADB_PASSWORD: ${DB_PASSWORD:-ft_password}
      MARIADB_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-ft_root_password}
```

What an anonymous visitor therefore learns, without authentication:

| Disclosed | Why it matters |
|---|---|
| **The LiteSpeed WebAdmin credentials** — `admin` / `P@ssw0rd` — and the **port** (7080) it listens on. | The WebAdmin is an **admin surface**: it can read the vhost configuration, the error log and the access log, and it can restart the server. The defaults are in the file, so the attacker does not have to guess. |
| **The MariaDB root password** — `ft_root_password` — and the application's own credentials — `ft_user` / `ft_password`. | The database is on the internal `ft-net` network and is not published directly, but phpMyAdmin **is** published, on port 8090. The default credentials are now known. |
| **The phpMyAdmin port** (8090) and that it exists at all. | It is the path to the database if the credentials are unchanged. |
| **The exact image tags** — `litespeedtech/openlitespeed:1.9.4-lsphp85`, `mariadb:10.11`, `phpmyadmin:latest`, `nginx:alpine`. | The attacker knows precisely which versions to look up advisories for. Note `phpmyadmin:latest` is **unpinned** — an unpinned tag is a supply-chain risk in its own right, and it is documented here. |
| **The internal hostnames and the network** — `db`, `ft-net`, `ft-app`, `ft-db`. | Useful for an SSRF or a DNS-rebinding attempt, and for understanding the topology. |
| **The mount paths**, the container names, the restart policies and the health checks. | The whole operating picture. |

The other three files are less severe but still internal:

| File | What it discloses |
|---|---|
| `/config.template.php` | The **complete constant set** of the configuration: the names of the database, SMTP, sender, registration and week-start settings. It holds no secret — `config_test.php` asserts the parity — but it is the schema of the configuration, and it confirms which variables exist and what their defaults are. |
| `/AGENTS.md` | The agent guide: the internal conventions, the commands, the credentials' *locations*, the "never `docker compose down -v`" rule, and the whole status table. It is the closest thing the repository has to an operations manual. |
| `/docs/About.md` | The product brief: the purpose, the stack and the installation contract. |

---

## 5. Finding 4 — the admin console is bound more widely than the documentation says

**Severity: Medium.** (A dev-stack finding, but a real one.)

`docker-compose.yml` binds the WebAdmin with:

```yaml
    ports:
      - ${OLS_ADMIN_PORT:-7080}:7080
```

No host address — so it binds to **every** interface. `AGENTS.md` says
something different:

> | LiteSpeed WebAdmin | `OLS_ADMIN_PORT` = **7080** | 7080 | **127.0.0.1 only** |

**The documentation is wrong.** The port is **not** loopback-only. The
operator reading `AGENTS.md` believes the admin console is unreachable from
the network; it is not. On a laptop on shared Wi-Fi, `http://<host>:7080/` is
an admin console with the **published default password**
`admin` / `P@ssw0rd`.

Two things must change:

1. The compose file must bind it to loopback:
   ````yaml
   - "127.0.0.1:${OLS_ADMIN_PORT:-7080}:7080"
   ````
   — which is what `AGENTS.md` already claims.
2. `AGENTS.md`'s ports table must be corrected to say **what the file does**,
   not what it was intended to do. A table that is wrong about a security
   boundary is worse than no table.

> **This is a development-stack finding.** The production deployment is plain
> LiteSpeed + PHP + MariaDB and does not use the compose file. But "dev only"
> is a statement about the **deployment**, not about the **network**: the
> laptop is on the same network as everything else.

---

## 6. Finding 5 — the hardening test pins the leak in place

**Severity: Low.** (A process defect, but it is the reason the leak survived.)

`database/webroot_hardening_test.php` does not merely fail to cover the four
paths — it **asserts that they are reachable**:

```php
// database/webroot_hardening_test.php — section C
// The deny list must not be so broad that it breaks the project.
$public = [
    '/AGENTS.md',
    '/docs/About.md',
    '/docs/mockup/',
    '/docs/mockup/index.html',
    '/docs/mockup/assets/tokens.css',
];
```

```php
// section D — config.template.php is asserted to be public AND to be
// executed by PHP rather than served as source.
$res = http_get($baseUrl . '/config.template.php');
ok('GET /config.template.php -> 200', $res['status'] === 200, ...);
```

And the **liveness probe** uses the leak as its marker:

```php
function app_answers(string $baseUrl): bool
{
    $res = http_get($baseUrl . '/AGENTS.md');
    return $res['status'] === 200
        && is_string($res['body'])
        && strpos($res['body'], 'Financial Tracker') !== false;
}
```

So the test would **fail** if the leak were fixed, and the operator following
the failure message would be led to re-open the hole. This has to change
**in the same change** as the deny block — otherwise the fix and the test
contradict each other and the next person breaks one to satisfy the other.

The replacement liveness probe is `/docs/mockup/index.html`, which stays
public and contains the marker string:

```
<title>Financial Tracker — UI mockups</title>
```

---

## 7. The fix

### 7.1 Stage 1 — extend the deny block

Four new rules, added **inside** the existing deny block, before the
front-controller rewrite:

```apache
    # ---- The deployment topology and the internal documentation ----------
    # docker-compose.yml publishes the default credential set of the whole
    # development stack — the WebAdmin user and password, the MariaDB root
    # password, the application credentials, the image tags and the internal
    # hostnames. It is a development artefact and it has no business being
    # on a public web root.
    RewriteRule "^/?docker-compose\.ya?ml$" - [F,L]

    # The tracked twin of config.php. It holds no secret, but it is the
    # complete constant set of the configuration.
    RewriteRule "^/?config\.template\.php$" - [F,L]

    # The agent guide and the product brief. Internal documentation.
    RewriteRule "^/?AGENTS\.md$" - [F,L]
    RewriteRule "^/?docs/About\.md$" - [F,L]
```

> **The `.htaccess` file is cached by LiteSpeed.** A rule that was just added
> to the file is **not enforced** until the server re-reads it. After editing
> `.htaccess`, run `docker compose restart app` — otherwise the live status
> codes disagree with the file, and the hardening test fails for the wrong
> reason.

### 7.2 Stage 2 — invert to a default deny (recommended, separately tested)

Replace the "deny these specific paths" model with "allow these specific
paths, deny everything else". The order matters, and it is the reverse of
what it looks like:

```apache
    # ---- The public surface, explicitly allowed --------------------------
    # The static assets, the mockup deliverable, the well-known directory and
    # the two PHP entry points. Everything else is refused.
    RewriteRule "^/?(assets/|docs/mockup/|\.well-known/)" - [L]
    RewriteRule "^/?(index|install)\.php$" - [L]

    # ---- The front controller --------------------------------------------
    # A URL that is not a real file and not a real directory is a route.
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]

    # ---- Everything else is refused --------------------------------------
    # A real file or directory that is not on the public surface above.
    RewriteRule "^" - [F,L]
```

Three consequences the implementer must verify rather than assume:

| Question | Why it matters |
|---|---|
| Does the bare root still route? | `/` is a **directory**, so `!-d` excludes it from the front-controller rule and it falls through to the catch-all. The explicit `RewriteRule ^$ index.php [QSA,L]` must stay **above** the catch-all. |
| Is `install.php` still reachable? | It is a real file, so it falls through to the catch-all unless it is explicitly allowed. It must be allowed — it is the installer, and it refuses itself with a 403 once `config.php` exists. |
| Does the front controller still reach the 404 page? | A URL that is not a file must reach `index.php`; the catch-all must not swallow it. |

> **Stage 2 is not a v1.0 blocker.** Stage 1 closes the measured disclosure.
> Stage 2 closes the *class* of disclosure, and it should be done with its own
> regression, not as a drive-by edit to the deny block.

### 7.3 The hardening test

`database/webroot_hardening_test.php` changes **in the same commit**:

| Change | Where |
|---|---|
| Move `/AGENTS.md` and `/docs/About.md` from `$public` to `$denied`. | Sections B and C. |
| Add `/docker-compose.yml` and `/config.template.php` to `$denied`. | Section B. |
| Change the liveness probe from `/AGENTS.md` to `/docs/mockup/index.html`. | `app_answers()`. |
| Rewrite section D: `/config.template.php` is no longer public, so the "allowed PHP is executed, not leaked" assertions need a new subject. | Section D. |

For section D, the replacement assertions are:

```php
// The PHP entry points must never serve their source, whether they are
// routed, refused or answered.
$res = http_get($baseUrl . '/index.php');
ok('GET /index.php does not serve PHP source',
    is_string($res['body']) && strpos($res['body'], '<?php') === false, ...);

$res = http_get($baseUrl . '/install.php');
ok('GET /install.php does not serve PHP source',
    is_string($res['body']) && strpos($res['body'], '<?php') === false, ...);
```

### 7.4 The compose file and the documentation

| Change | File |
|---|---|
| Bind the WebAdmin to loopback: `- "127.0.0.1:${OLS_ADMIN_PORT:-7080}:7080"` | `docker-compose.yml` |
| Correct the ports table to match what the file does. | `AGENTS.md` |
| Pin `phpmyadmin:latest` to a specific tag, or state why it must float. | `docker-compose.yml` |

---

## 8. Acceptance criteria

- [ ] `GET /docker-compose.yml` → **403**.
- [ ] `GET /config.template.php` → **403**.
- [ ] `GET /AGENTS.md` → **403**.
- [ ] `GET /docs/About.md` → **403**.
- [ ] `GET /docs/mockup/` → **200** — the deliverable stays public.
- [ ] `GET /docs/mockup/index.html` → **200**.
- [ ] `GET /docs/mockup/assets/tokens.css` → **200**.
- [ ] `GET /assets/css/tokens.css` → **200**.
- [ ] `GET /login`, `GET /dashboard` (redirected), `GET /transactions` → the
      normal application responses.
- [ ] `GET /` → **302** to `/dashboard` (or the install gate).
- [ ] `GET /index.php` → **404**, and the body does **not** contain `<?php`.
- [ ] `GET /install.php` → **403**, and the body does **not** contain `<?php`.
- [ ] **No PHP source is served by any path.** The test asserts this for every
      entry in the denied list and for both PHP entry points.
- [ ] `database/webroot_hardening_test.php` no longer asserts that
      `/AGENTS.md` or `/docs/About.md` are reachable, and its liveness probe
      uses a path that stays public.
- [ ] The WebAdmin is bound to **127.0.0.1** in `docker-compose.yml`, and the
      ports table in `AGENTS.md` matches the file.
- [ ] The `phpmyadmin` image tag is **pinned**, or the reason it floats is
      written down.
- [ ] The rules are written in the **deny block**, and the front-controller
      rewrite still comes after it.
- [ ] `docker compose restart app` has been run, and the **live** status codes
      — not the file contents — are what the test asserts.

---

## 9. Verification

````bash
# 1. The file, on disk.
grep -n "docker-compose\|config\.template\|AGENTS\.md\|docs/About" .htaccess

# 2. LiteSpeed caches the parsed .htaccess — restart before testing.
docker compose restart app
sleep 3

# 3. The live status codes.
for p in /docker-compose.yml /config.template.php /AGENTS.md /docs/About.md \
         /docs/mockup/ /docs/mockup/index.html /assets/css/tokens.css \
         /index.php /install.php /login / .env /database/ /views/; do
  printf '%-28s %s\n' "$p" \
    "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8088$p")"
done
# Expected: 403 for the four new paths, 200 for the public ones, 404/302/403
# for the entry points.

# 4. The regression.
docker compose exec -T app php /var/www/html/database/webroot_hardening_test.php

# 5. The whole suite.
.github/skills/dev-stack-ops/scripts/run-tests.sh
bash database/live/run-live-tests.sh
````

> **The status codes must be read from the running server.** A rule in the file
> that the server has not re-read is a rule that is not enforced.

---

## 10. Rollback

Stage 1 is four `RewriteRule` lines. Removing them restores the current
behaviour exactly — which is the behaviour the hardening test currently
asserts, so the test and the file stay consistent either way.

The hardening-test changes are the **other** half of the rollback: if the
deny rules are reverted, the test's `$public` list must be restored too, or
the suite fails. Change them together.

Stage 2 is a different mechanism and has its own rollback: restore the
previous `.htaccess` wholesale and restart the app. Because Stage 2 changes
the *order* of the rules, the front-controller rewrite must be restored with
it.

---

## 11. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A path that must stay public is denied by mistake | Medium | High | The public list is asserted explicitly in the test, and the mockup container serves `docs/mockup/` independently of the application — so the deliverable is verifiable even if the application's copy is denied. |
| LiteSpeed serves a **stale** `.htaccess` and the test fails for the wrong reason | **High** | Low | `docker compose restart app` is in the documented procedure, and the test already prints a NOTE explaining the stale-copy case. |
| The hardening test and the deny block disagree | Medium | Medium | Both changes land in the **same commit**, and the test is the specification — it is updated first. |
| The WebAdmin becomes unreachable for a developer who works remotely | Low | Low | The WebAdmin is a development convenience; `docker compose logs` and the shell cover the same ground. If remote access is genuinely needed, it should go through an SSH tunnel, not a bound port. |
| Stage 2 breaks a route | Medium | High | Stage 2 is separately tested, and Stage 1 already closes the measured disclosure. Stage 2 is not a v1.0 blocker. |

---

## 12. Out of scope

- **The mockup container** — `docs/mockup/` stays public by design; it is the
  UX deliverable and the standalone viewer serves it (issue-002).
- **`docs/issues/`** — already denied.
- **Moving the application out of the repository root** — a deployment change,
  not a v1.0 change. The web root being the repository root is the root cause
  of this class of finding, and the deny block is the mitigation the project
  chose.
- **The production deployment's own web-root configuration** — a separate
  document from `AGENTS.md`.
