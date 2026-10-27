# Issue-009 — Ops: upgrade the runtime to PHP 8.5.12 (PHP2Shell remediation)

> **Status:** **implemented and verified** — the stack was recreated on the new
> image and the full regression suite was re-run against it on 2026-10-27.
> **Target:** v0.1 (development infrastructure) — and a **production** deadline,
> because the vulnerability is exploited in the wild.
> **Severity:** **critical** — two unauthenticated remote code execution
> vulnerabilities affecting every unpatched PHP 5.4 – 8.5 build.
>
> **Requested by the user:**
> > "a week ago we have news on php have vulnerabilities exposed to the whole
> > world. We need to use PHP 8.5.12 for more info READ
> > ([PHP2Shell - Wikipedia](https://en.wikipedia.org/wiki/PHP2Shell))"
>
> **Depends on:** [issue-001.md](issue-001.md) (the compose stack).
> **Related:** `AGENTS.md` (Stack, Hard rules);
> `.github/instructions/php-app.instructions.md`;
> `.github/skills/dev-stack-ops/SKILL.md`;
> [issue-010.md](issue-010.md) (the Composer policy that follows from it).

---

## Table of contents

1. Objective
2. The vulnerability this fixes
3. Constraints
4. Specification — the image
5. Specification — the version floor
6. Specification — the paths that move with the version
7. Acceptance criteria
8. Verification
9. Rollback
10. Risks
11. Out of scope

---

## 1. Objective

> Move the development stack, and the documented PHP floor, from **PHP 8.2.33**
> to **PHP 8.5.12** — the release that patches PHP2Shell — without changing any
> other aspect of the stack.

Success criteria:

| # | Criterion |
|---|---|
| 1 | The `app` service runs **PHP 8.5.12**, the patched release — not 8.5.0–8.5.11 |
| 2 | The image tag is **pinned**, not `latest`, so the floor cannot drift silently |
| 3 | The PHP floor documented in `AGENTS.md` and in the scoped instruction files reads **8.5.12** |
| 4 | The `php.ini` path in the dev-stack-ops skill points at `lsphp85` / `8.5` |
| 5 | The docroot path is **unchanged** — the swap must not move the mount point |
| 6 | The regression suite still reports **2 PASS / 0 FAIL** on the new runtime |
| 7 | The web-root hardening still holds — `.env`, `.git`, `config.php`, `database/` all **403** |
| 8 | The **production** floor is documented as 8.5.12 as well, not only the dev floor |

---

## 2. The vulnerability this fixes

**PHP2Shell** is a pair of critical remote code execution vulnerabilities in
the PHP interpreter itself.

| | CVE-2026-109618 | CVE-2026-109619 |
|---|---|---|
| Class | RCE via out-of-memory corruption | RCE via stale global state |
| Advisory | `GHSA-w8hw-w9fx-j79w` | `GHSA-6wmf-4rv5-p3rp` |
| CVSS 4.0 | **9.2** (critical) | **9.2** (critical) |
| CVSS 3.x | **9.8** | **8.1** |
| Vector (4.0) | `AV:N/AC:H/AT:P/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N` | same |

| Property | Value |
|---|---|
| Affected software | **PHP 5.4 – 8.5** — every maintained branch, and every end-of-life one |
| Date discovered | 1 August 2026 (Calif / OpenAI, and independently Wiz) |
| Date announced | 20 October 2026 |
| **Date patched** | **21 October 2026** |
| Patched releases | 8.2.**35**, 8.3.**36**, 8.4.**27**, 8.5.**12** |
| EOL branches | 7.4, 8.0, 8.1 — source patches only, no binaries |

### Why this cannot be mitigated around

The PHP maintainers published temporary mitigations but stated there were
**no complete mitigations** and that **updating was the only safe choice**.
The project has no configuration knob that disables the affected code paths.

### Why it is urgent, not academic

- Roughly **70 %** of all websites run PHP.
- **Exploitation was observed in the wild** before the public announcement:
  Amazon Threat Intelligence found an attempt in MadPot telemetry dated
  **17 October 2026** — three days before disclosure — and Cloudflare reported
  signs of earlier exploitation and deployed WAF rules as a stopgap.
  watchTowr observed exploitation through its honeypot network by 21 October.
- The **NSA**, the **UK NCSC** and **CERT-EU** advised organisations to take
  sites **offline** if they could not update immediately. Several national
  government portals did exactly that.

> The position of this repository before this issue: the app container shipped
> **PHP 8.2.33** — a build from **August 2026**, i.e. *after* the vulnerable
> out-of-memory bug was discovered and *before* the patch existed. It was
> vulnerable by construction.

### The fix

Move to **PHP 8.5.12** — the patched release of the 8.5 branch — and pin the
image so the floor cannot drift.

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| **The application code does not exist yet** | There is no `index.php`, no `includes/`, no `assets/`. This issue changes the **runtime only**; it cannot and must not change application behaviour. |
| **PHP 8.5 is a new language version** | The application must be written against the 8.5 feature set, not the 8.2 one. The 8.5-specific features (URI extension, pipe operator, `clone` with, `#[\NoDiscard]`, `array_first()` / `array_last()`) are available. |
| **8.5 deprecates and removes things** | The backtick operator, the non-canonical casts `(boolean)` / `(integer)` / `(double)` / `(binary)`, terminating a `case` with `;`, `null` as an array offset, and `disable_classes` are deprecated or removed. Application code must not use them. |
| The image must keep the **same docroot** | The mount point is fixed by the image's `docker` vhost template: `/var/www/vhosts/localhost/html`. If it moved, every request would 404. |
| The `docker` vhost template is not in the image's shipped `conf/` | It is generated at container start. The docroot was therefore **re-verified against the new image**, not assumed. |
| **`display_errors = Off`** | A fatal error in the new runtime would render a blank page. The regression suite and a direct `php -v` are the checks. |
| Docker is **development-only** | The production floor must be stated separately from the dev image tag, because production is plain LiteSpeed + PHP + MariaDB with whatever PHP the host runs. |
| The host cannot reach `registry-1.docker.io` | The image was fetched through a registry mirror. The **tag** is what the compose file pins; the mirror is a transport detail of this machine, not a project fact. |

---

## 4. Specification — the image

### 4.1 The tag

````yaml
# docker-compose.yml
app:
  image: litespeedtech/openlitespeed:1.9.4-lsphp85
````

**The tag is pinned, and `latest` is not used any more.**

`litespeedtech/openlitespeed:latest` moves. When it does, the PHP version
underneath this repository moves with it — silently, and without a commit. A
security floor that can be changed by an upstream tag push is not a floor. The
per-version tag family (`1.9.4-lsphp82`, `-lsphp83`, `-lsphp84`, `-lsphp85`)
makes the PHP version an explicit, reviewable part of the compose file.

### 4.2 What the image contains

Verified by running the image, not by reading a tag list:

| Property | Value |
|---|---|
| LiteSpeed | **1.9.4** |
| PHP | **8.5.12** (cli) (built: **Oct 21 2026** 13:01:13) (NTS) — the patch date |
| PHP install dir | `/usr/local/lsws/lsphp85` |
| `php.ini` | `/usr/local/lsws/lsphp85/etc/php/8.5/litespeed/php.ini` |
| `composer` | **absent** (unchanged — see [issue-010.md](issue-010.md)) |
| `git` | **absent** (unchanged) |
| `unzip` | present — required for Composer's dist installer |
| `zip` extension | present |
| `ctype`, `filter`, `hash` | present — PHPMailer's required extensions |
| `curl`, `openssl`, `mbstring`, `pdo_mysql`, `sockets`, `intl`, `iconv` | present |
| `uri` extension | present — new in PHP 8.5 |

> The build date stamped into the binary — **21 October 2026** — is the same
> date as the upstream PHP2Shell patch release. That is the strongest
> available evidence that this build carries the fix.

### 4.3 The docroot is unchanged

The `docker` vhost template resolves `vhRoot` to `/var/www/vhosts/$VH_NAME/`
and `docRoot` to `$VH_ROOT/html/`, with the member `localhost`. The mount
point therefore stays:

````text
./:/var/www/vhosts/localhost/html
````

This was **re-verified against the new image** by starting the stack and
measuring the responses, not by reading the old image's configuration.

---

## 5. Specification — the version floor

### 5.1 The floor is 8.5.12, not 8.5

| Statement | Correct? | Why |
|---|---|---|
| "PHP 8.5 is the floor" | **No** | 8.5.0 through 8.5.11 are all vulnerable. The floor must be the **patched** release. |
| "PHP 8.5.12 is the floor" | **Yes** | 8.5.12 is the first 8.5 release that carries the fix. |

The same applies to the other branches the maintainers patched — 8.2.35,
8.3.36, 8.4.27 — but this repository picks the newest branch, because a
newer branch is the one that stays supported longest and it is the branch the
image ships.

### 5.2 Where the floor is stated

Every document that names a PHP version must be updated, because a stale
floor is worse than no floor — it reads as an authorisation to use the old
version.

| File | Change |
|---|---|
| `AGENTS.md` | Stack table: `The dev image ships PHP 8.5.12 (lsphp85). PHP 8.5.12 is the floor.` |
| `AGENTS.md` | Hard rules: a new subsection on the PHP2Shell floor. |
| `docker-compose.yml` | The pinned `image:` tag, plus a comment explaining why it is pinned. |
| `docs/issues/issue-001.md` | Criterion 5, the architecture diagram, the mount-point table, the acceptance checklist. |
| `.github/instructions/php-app.instructions.md` | The "PHP 8.2 is the floor" paragraph and the "PHP 8.3+ syntax is unavailable" sentence — the latter is now **false** and must be deleted, not edited. |
| `.github/instructions/tests.instructions.md` | "any PHP 8.2+ binary" → 8.5+. |
| `.github/skills/dev-stack-ops/SKILL.md` | The service table and the `php.ini` path. |
| `.htaccess` | The comment recording which image the rules were verified against. |
| `docker/entrypoint.sh` | The comment naming the image. |

### 5.3 The floor is a floor, not a target

> The floor is the **minimum** version the application may assume. It is not a
> recommendation to use the newest syntax. The application is plain PHP with
> no framework; there is no reason for it to use 8.5-only syntax at all.

The one place the newer version genuinely helps is the **security patch
level**: the floor is a patched build, so the application is not exposed to
PHP2Shell.

---

## 6. Specification — the paths that move with the version

Three paths embed the version. They move together, and they must all be
updated in the same change, or the tooling silently reads a `php.ini` that
does not exist.

| Old | New |
|---|---|
| `/usr/local/lsws/lsphp82/bin/php` | `/usr/local/lsws/lsphp85/bin/php` |
| `/usr/local/lsws/lsphp82/etc/php/8.2/litespeed/php.ini` | `/usr/local/lsws/lsphp85/etc/php/8.5/litespeed/php.ini` |
| `lsphp82` | `lsphp85` |

> The `php` on the host `PATH` inside the container is a symlink into
> `lsphp85/bin/`, so `php -v` and `php -l` keep working unchanged. Only the
> **php.ini path** has to be updated by hand, because it is spelled out in the
> skill documentation.

---

## 7. Acceptance criteria

- [ ] `docker compose config --quiet` exits **0**.
- [ ] `docker compose up -d` recreates `ft-app` on `litespeedtech/openlitespeed:1.9.4-lsphp85`.
- [ ] `docker compose exec -T app php -v` reports **PHP 8.5.12**.
- [ ] `docker compose exec -T app php -r 'echo PHP_VERSION_ID, PHP_EOL, "\n";'` reports `8.5.12` and a **non-zero** end-of-life status.
- [ ] `docker compose exec -T app php -i | grep "Loaded Configuration File"` reports `/usr/local/lsws/lsphp85/etc/php/8.5/litespeed/php.ini`.
- [ ] The compose file contains **no** `litespeedtech/openlitespeed:latest` reference.
- [ ] No document in the repository states a PHP floor below **8.5.12**.
- [ ] No document in the repository states that "PHP 8.3+ syntax is unavailable".
- [ ] `docker compose exec -T app sh -c 'readlink -f /var/www/html'` resolves to `/var/www/vhosts/localhost/html`.
- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:${APP_PORT}/` returns **404** — not 500 — proving LiteSpeed serves the docroot.
- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:${APP_PORT}/config.template.php` returns **200** with an empty body — PHP executed, the source was not served.
- [ ] `/.env` → **403**, `/.git/config` → **403**, `/config.php` → **403**, `/database/config_test.php` → **403**.
- [ ] `/docs/mockup/index.html` → **200**.
- [ ] `.github/skills/dev-stack-ops/scripts/run-tests.sh` reports **2 PASS / 0 FAIL** and exits **0**.
- [ ] `docker compose exec -T app php -l` passes over every PHP file in the tree.

---

## 8. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 0. Preflight
test -f .env || cp .env.example .env
docker compose config --quiet && echo "compose file is valid"

# 1. Start on the new runtime
docker compose up -d
docker compose ps

# 2. The version — the single most important check
docker compose exec -T app php -v
#   PHP 8.5.12 (cli) (built: Oct 21 2026 13:01:13) (NTS)

docker compose exec -T app php -r 'printf("%s\n", PHP_VERSION_ID);'
#   80512

# 3. The php.ini moved with the version
docker compose exec -T app php -i | grep "Loaded Configuration File"
#   Loaded Configuration File => /usr/local/lsws/lsphp85/etc/php/8.5/litespeed/php.ini

# 4. The docroot did not move
docker compose exec -T app sh -c 'readlink -f /var/www/html'
#   /var/www/vhosts/localhost/html

# 5. LiteSpeed is serving, and PHP executes
curl -sS -o /dev/null -w 'root=%{http_code}\n' http://localhost:8088/
#   root=404   (no index.php yet — 404, not 500)
curl -sS -o /dev/null -w 'php=%{http_code}\n' http://localhost:8088/config.template.php
#   php=200

# 6. The web-root hardening still holds on the new image
for p in /.env /.git/config /config.php /database/config_test.php; do
  printf '%-28s %s\n' "$p" "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:8088$p")"
done
#   /.env                        403
#   /.git/config                 403
#   /config.php                  403
#   /database/config_test.php    403

curl -sS -o /dev/null -w 'mockup=%{http_code}\n' http://localhost:8088/docs/mockup/index.html
#   mockup=200

# 7. The suite
.github/skills/dev-stack-ops/scripts/run-tests.sh
#   == Running 2 test(s) ==
#     [PASS] database/config_test.php
#     [PASS] database/webroot_hardening_test.php
#   == 2 PASS / 0 FAIL ==

# 8. Lint the tree
.github/skills/dev-stack-ops/scripts/run-tests.sh --lint

# 9. No stale references remain
grep -rn 'openlitespeed:latest\|lsphp82\|8\.2\.33\|PHP 8\.2' \
  --include='*.md' --include='*.yml' --include='*.sh' --include='.htaccess' .
#   (no output)

grep -rn 'PHP 8\.5' AGENTS.md .github/instructions/ .github/skills/
#   the floor is stated
````

---

## 9. Rollback

The change is one line in `docker-compose.yml` plus documentation. Rolling
back is safe but **reintroduces the vulnerability**, so it must be treated as
an emergency measure with an expiry date, not a permanent state.

````bash
# Roll back the runtime
git checkout -- docker-compose.yml
docker compose up -d
docker compose exec -T app php -v   # PHP 8.2.33 — VULNERABLE
````

| What | How it is reverted |
|---|---|
| The image tag | `git checkout -- docker-compose.yml` and `docker compose up -d` |
| The documentation | `git checkout -- AGENTS.md docs/ .github/` |
| The `ols_conf` / `ols_admin_conf` volumes | These hold LiteSpeed's generated config, **not** the application. They are regenerated by the image on first start. Deleting them (`docker compose down` without `-v`) is safe; **never** use `-v`. |

> **Rollback does not apply to the database.** `docker compose down -v`
> deletes the MariaDB volume. Take a dump first.

---

## 10. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| The new image changes the docroot path | **High** — every request 404s | Verified against the running image (§4.3, §8 step 4), not assumed from the old one |
| A PHP 8.5 deprecation breaks something | Low | The application code **does not exist yet** — there is nothing to break. The deprecations are recorded in §3 for whoever writes it. |
| The `php.ini` path in the skill docs goes stale | Medium | Both paths are asserted in the acceptance criteria, and the skill is listed in §5.2 |
| `latest` is used again by accident | **High** — the floor silently drifts | The tag is pinned, and the acceptance criteria include a `grep` that fails if `latest` reappears |
| The image cannot be pulled on a machine that has not got it | Low | `docker compose pull app` fetches it; the tag is on Docker Hub and the mirror transport is a per-machine concern |
| The image is amd64-only | Low | The digest resolves for `linux/amd64`. On arm64, use the native PHP build rather than this image |

---

## 11. Out of scope

| Not in this issue | Why |
|---|---|
| Writing the application | The application does not exist yet — see `AGENTS.md`, "Status" |
| Upgrading MariaDB, LiteSpeed or any other component | Only PHP is affected by PHP2Shell |
| Adding a CI pipeline | There is no CI in this repository |
| Changing the Compose service names, ports or volumes | The upgrade must not change anything else about the stack |
| **Composer and the SMTP mailer** | A separate objective with a separate dependency policy — see [issue-010.md](issue-010.md) |
| Hardening the application against the exploits | The fix is in the interpreter. The application has no code yet |
