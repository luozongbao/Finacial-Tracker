# Issue-010 — Ops: introduce Composer as a build-time tool and vendor the SMTP mailer

> **Status:** **implemented and verified** — the dependency policy, the library
> choice and the install procedure below were **measured** against the real
> PHP 8.5.12 image on 2026-10-27, and the implementation landed on the same
> date. `composer.json` and `composer.lock` are tracked, `vendor/` is
> git-ignored, `includes/mailer.php` and `includes/bootstrap.php` exist, and
> `database/mailer_test.php` guards the whole policy. The one criterion that
> is **not** met is the `install.php` wiring — the installer does not exist
> yet; it belongs to the application track ([issue-008.md](issue-008.md)).
> **Target:** v0.2 (the first release that sends mail).
> **Severity:** **medium** — a missing dependency stops the mail feature; it
> is not a security exposure.
>
> **Requested by the user:**
> > "we might need to use composer to install SMTP mailer to use with our
> > email feature."
>
> **Depends on:** [issue-009.md](issue-009.md) (the PHP 8.5.12 runtime — the
> mailer is installed and verified against it).
> **Related:** `AGENTS.md` (Stack, Install and config contract);
> `config.template.php`; `database/config_test.php`;
> `.github/instructions/php-app.instructions.md`;
> [issue-005.md](issue-005.md) (the "mail is not configured" state).

---

## Table of contents

1. Objective
2. The problem this solves
3. Constraints
4. Specification — the dependency policy
5. Specification — the library choice
6. Specification — the install procedure
7. Specification — the configuration contract
8. Specification — how the application consumes the mailer
9. Acceptance criteria
10. Verification
11. Rollback
12. Risks
13. Out of scope

---

## 1. Objective

> Introduce **Composer** as the supported way to add a third-party PHP
> library — specifically the **SMTP mailer** the email feature needs — while
> keeping the runtime image free of Composer and keeping the install step out
> of container start-up.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `composer.json` and `composer.lock` are **tracked**; `vendor/` is **not** tracked |
| 2 | The install is a **deliberate, reproducible step**, never an entrypoint action |
| 3 | The runtime image still ships **no `composer`** — the app never installs anything at start-up |
| 4 | The mailer library is **PHPMailer**, pinned to a **pinned version** |
| 5 | The mailer is reachable from the application through `vendor/autoload.php` |
| 6 | `config.php` remains the **only** configuration source — the mailer reads `SMTP_*` from it |
| 7 | The application **degrades gracefully** when SMTP is not configured or the send fails |
| 8 | The `vendor/` tree is **denied by `.htaccess`** and never served |

---

## 2. The problem this solves

The repository's current rule, inherited from the sibling PHP projects, is:

> "The runtime image has no `composer` and no `git` — any third-party PHP
> library must be vendored into the repo or baked into a custom image, not
> `composer install`-ed at start-up."

That rule was written when the answer was *"copy the library's files in by
hand"*. The email feature needs a real SMTP client, and hand-vendoring one is
where the rule starts to hurt: no version pinning, no lock file, no
autoloader, no licence bookkeeping, and no way to tell what is actually
deployed.

So the rule needs an amendment, not a deletion. The **intent** of the rule is
sound and must be preserved:

| The intent | Preserved how |
|---|---|
| The runtime never mutates itself | Composer is **never** run by the entrypoint |
| The app does not depend on Docker | `vendor/` is produced by a **host-side** step, not a container-start step |
| The deployed tree is deterministic | `composer.lock` is tracked; `composer install` (not `update`) reproduces it exactly |

### What "use Composer" does **not** mean here

It does **not** mean adding a build step to the deploy pipeline, and it does
**not** mean the container installs its own dependencies when it boots. The
repository's "no build step" property is load-bearing — it is what lets the
app run with Docker entirely out of the picture. Composer runs **before**
the app is served, on a workstation or in a build pipeline, and its output
(`vendor/`) is what gets deployed.

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| **The runtime image ships no `composer`** | Composer cannot be the deploy-time tool inside the container. It must be run **outside** the app container, or from a throwaway container that mounts the project. |
| **The image ships no `git`** | Composer's `--prefer-source` fallback cannot work (it shells out to `git clone`). The `dist` download path must work — it needs `unzip` **or** the `zip` extension, and the `1.9.4-lsphp85` image has **both**. |
| **`php` is not on the host PATH** | The host-side install must run Composer through a container, e.g. `docker run --rm -v "$PWD":/app -v /tmp:/w php:8.5-apache php /w/composer.phar …`. |
| **`config.php` is the only configuration source** | The mailer reads `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS` from `config.php`. No `getenv()`, no `$_ENV`. |
| **`config.php` and `config.template.php` must declare the same constant set** | Any new mailer constant must be added to **both** in the same change, or `database/config_test.php` fails on the parity assertion. |
| **`.htaccess` denies `/vendor/`** | The vendor tree is already denied — see issue-003. That rule is load-bearing and must stay. |
| **No build step** | `vendor/` must be produced by a step the developer runs, and it must be deployable as-is. |
| **Money is never a float** | The mailer sends formatted amounts — `number_format()` on a string read back from SQL, never a float. |
| **The application does not exist yet** | This issue specifies the dependency and its configuration contract. It cannot be implemented until `includes/` exists. |
| **`SMTP_*` may be empty in development** | The mailer must report "not configured" rather than fatal. The forgot-password screen needs that state — see [issue-005.md](issue-005.md) §3. |

---

## 4. Specification — the dependency policy

### 4.1 The policy, stated once

> **Composer is a vendor-time tool. It is never a runtime tool.**
>
> - `composer.json` and `composer.lock` are **tracked** in the repository.
> - `vendor/` is **git-ignored** — it is a build artefact, not source.
> - `composer install --no-dev` is run **once, deliberately**, by a developer
>   or by a CI job, on a machine with the project's PHP version.
> - The resulting `vendor/` tree is what gets deployed, next to `index.php`.
> - **Nothing in the container entrypoint, the installer, or any request
>   handler may run Composer.** If `vendor/` is missing at runtime, the
>   application reports a missing dependency — it does not try to fix it.

### 4.2 What is tracked and what is not

| Path | Tracked? | Why |
|---|---|---|
| `composer.json` | **Yes** | The dependency manifest — the requirement list is source. |
| `composer.lock` | **Yes** | The **exact** resolved versions. This is what makes the install reproducible and auditable. A lock file that is not tracked is a lock file that does not exist. |
| `vendor/` | **No** | A generated tree — 696 KB and 84 files for one dependency. It is recreated by `composer install`. |
| `composer.phar` | **No** | A downloaded binary. It is fetched per-machine, not committed. |

> `.gitignore` already ignores `vendor/`. This issue keeps that line and
> **adds** the requirement that `composer.json` and `composer.lock` are
> tracked — the two files that were not obvious before.

### 4.3 Why `install` and never `update`

| Command | Use it for |
|---|---|
| `composer install` | **Everyday use.** Reads `composer.lock` and installs exactly those versions. |
| `composer update` | **Deliberate upgrades only.** Rewrites `composer.lock`, and the change is then reviewed and committed. |

`composer install` on a lock file that is out of date with `composer.json`
fails with a clear message rather than silently resolving something new.

### 4.4 `--no-dev` and the optimised autoloader

````bash
composer install --no-dev --optimize-autoloader --no-interaction
````

| Flag | Reason |
|---|---|
| `--no-dev` | `require-dev` packages are not needed at runtime. |
| `--optimize-autoloader` | Builds a classmap instead of scanning `vendor/` on every request. On a filesystem-heavy PHP app this is a measurable first-request win. |
| `--no-interaction` | The command must never block on a prompt in a script or a CI job. |

### 4.5 Composer's own platform requirement

`composer.json` declares the platform the project targets:

````json
{
    "require": {
        "php": ">=8.5.12"
    },
    "config": {
        "platform": {
            "php": "8.5.12"
        }
    }
}
````

`config.platform.php` pins the **resolution** target to the floor, so the
lock file resolves against 8.5.12 even when the install runs on a machine
with a different PHP. This is what stops a developer's PHP 8.4 from silently
producing a lock file that the production runtime cannot satisfy.

---

## 5. Specification — the library choice

### 5.1 The choice: PHPMailer

**`phpmailer/phpmailer`, version `7.1.1`.**

| Property | Value |
|---|---|
| Latest stable version | **v7.1.1** (released 2026-05-18) |
| Requires | `php >=5.5.0`, `ext-ctype`, `ext-filter`, `ext-hash` |
| Runtime dependencies | **none** |
| Licence | `LGPL-2.1-only` |
| Installed size | 696 KB total, 84 files |

### 5.2 Why PHPMailer and not something else

The two realistic candidates were compared directly:

| | **PHPMailer 7.1.1** | Symfony Mailer 8.1.7 |
|---|---|---|
| Packages pulled in | **1** | The Symfony component tree — many packages, many transitive dependencies |
| PHP requirement | `>=5.5.0` | `>=8.4.1` |
| Runtime dependencies | **none** | A dozen-plus |
| Framework assumptions | **none** | Expects a Symfony application; pulls in DI, event dispatcher, HTTP client conventions |
| Fits a no-framework project | **Yes** | No |

This repository has **no framework, no ORM and no bundler**. Symfony Mailer
would drag a large dependency tree into a project that has deliberately
avoided exactly that. PHPMailer is one package with zero runtime
dependencies and no framework assumptions — it is the correct shape for this
codebase.

### 5.3 The required extensions are already present

PHPMailer needs `ctype`, `filter` and `hash`. All three are enabled in the
`1.9.4-lsphp85` image, along with `openssl`, `mbstring` and `curl`:

````text
ctype yes   filter yes   hash yes   mbstring yes
openssl yes curl yes    pdo_mysql yes
````

No image change and no `docker-php-ext-install` is required.

---

## 6. Specification — the install procedure

### 6.1 The canonical command

Run from the project root. The project is mounted at `/app`; Composer itself
is fetched once into `/tmp` and mounted in, because the runtime image has no
Composer of its own.

````bash
cd /home/zongbao/projects/financial-tracker

# 1. Fetch Composer once (not committed).
curl -sSL -o /tmp/composer.phar https://getcomposer.org/composer-stable.phar

# 2. Install the dependencies, using the project's own PHP version.
docker run --rm \
  -v "$PWD":/app \
  -v /tmp:/w \
  -w /app \
  --entrypoint php \
  litespeedtech/openlitespeed:1.9.4-lsphp85 \
  /w/composer.phar install --no-dev --optimize-autoloader --no-interaction
````

| Element | Reason |
|---|---|
| `litespeedtech/openlitespeed:1.9.4-lsphp85` | The install runs against **the same PHP the app will run on**. A different PHP resolves a different lock file. |
| `--entrypoint php` | The image's own entrypoint starts LiteSpeed; Composer needs a one-shot PHP process instead. |
| `-v /tmp:/w` | Makes the downloaded `composer.phar` reachable inside the container without baking it into the image. |
| `-v "$PWD":/app` | The project tree — `composer.json`, `composer.lock` and the resulting `vendor/`. |

### 6.2 The alternative: a dedicated Composer image

````bash
docker run --rm -v "$PWD":/app -w /app composer:2 install --no-dev --optimize-autoloader
````

This is **not** the canonical path, because the `composer` image carries its
own PHP version, which may differ from 8.5.12. Use it only for convenience,
and always re-run the lock check afterwards.

### 6.3 What the install produces

Measured on 2026-10-27 against the real image:

````text
Lock file operations: 1 install, 0 updates, 0 removals
  - Locking phpmailer/phpmailer (v7.1.1)
Writing lock file
Installing dependencies from lock file
Package operations: 1 install, 0 updates, 0 removals
  - Downloading phpmailer/phpmailer (v7.1.1)
  - Installing phpmailer/phpmailer (v7.1.1): Extracting archive
Generating optimized autoload files
````

### 6.4 The failure mode to recognise

The `dist` installer needs `unzip` **or** the `zip` extension:

````text
Failed to download phpmailer/phpmailer from dist: The zip extension and
unzip/7z commands are both missing, skipping.
````

The `1.9.4-lsphp85` image has **both**, so this does not occur there. It
**does** occur in minimal images such as `php:8.5-apache`, whose CLI PHP has
neither. The fix is to run the install in the project image, or to install
`unzip` into the throwaway container.

> Note the distinction: this failure happens **after** the lock file was
> written. A lock file without a `vendor/` tree is a half-finished install —
> check both.

---

## 7. Specification — the configuration contract

### 7.1 The existing constants are sufficient

The mailer needs a host, a port, a username and a password. `config.php`
already carries all four:

````php
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_USER = 'your_email@gmail.com';
const SMTP_PASS = 'your_email_password';
````

**No new configuration constant is required.** The install wizard already
collects these four values, and `config.template.php` already declares them,
so `database/config_test.php`'s parity assertion stays green without change.

### 7.2 The one thing that is missing: a sender address

An SMTP server refuses a message with no `From:` header, and a mail client
needs a `From` address and a display name. `SMTP_USER` is normally the
address, but it is not always the address the message should claim to be
from — a company SMTP account may send on behalf of a different mailbox.

Two new constants are therefore specified:

| Constant | Type | Example | Meaning |
|---|---|---|---|
| `MAIL_FROM` | string | `no-reply@example.com` | The `From:` address. |
| `MAIL_FROM_NAME` | string | `Financial Tracker` | The display name shown to the recipient. |

**The rule that goes with them** (from `AGENTS.md`, "Install and config
contract"):

> A constant added to `config.template.php` must be added to `config.php` **in
> the same change**, or `database/config_test.php` fails on the parity
> assertion. The installer (`install.php`) must write them, and the install
> wizard must collect them.

### 7.3 How the wizard collects them

The wizard is specified in [issue-008.md](issue-008.md) and mocked in
`docs/mockup/11-install-wizard.html`. The two new values go into **Step 1**
(next to the SMTP block) or a new short **Step 2**, at the implementer's
discretion — but the mockup must be updated to match, because the mockup is
the specification of the screen.

| Field | Type | Required | Default |
|---|---|---|---|
| `mail_from` | email | **Yes** | pre-filled from `SMTP_USER` |
| `mail_from_name` | text | No | `Financial Tracker` |

### 7.4 The "not configured" state

`SMTP_HOST` empty means **SMTP is not configured**. The mailer must then:

1. **not** attempt a connection,
2. report a clear "mail is not configured" state,
3. let the request continue — the forgot-password screen shows the state
   instead of a form that can never deliver.

This dependency is already recorded in [issue-005.md](issue-005.md) §3.

---

## 8. Specification — how the application consumes the mailer

### 8.1 The autoloader is included once, at bootstrap

````php
// filepath: includes/bootstrap.php
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    // The dependency was never installed. Report it; do not try to fix it.
    // The runtime never runs Composer — see docs/issues/issue-010.md.
    throw new RuntimeException(
        'Dependencies are not installed. Run: composer install --no-dev'
    );
}

require_once $autoload;
````

| Rule | Reason |
|---|---|
| `require_once`, not `require` | The autoloader must be included exactly once per request. |
| `is_file()` guard with a **clear message** | A missing `vendor/` is a deployment mistake. The application must say so rather than fataling with "class not found". |
| **Never** shell out to Composer | The runtime must not mutate itself. |

### 8.2 The mailer lives in `includes/`, not in `vendor/`

`vendor/` is a generated tree and is overwritten by the next
`composer install`. Application code never goes in there.

The repository's `includes/` layout:

````text
includes/
  bootstrap.php     # autoloader + error handling
  mailer.php        # the SMTP wrapper around PHPMailer
  functions.php
  Database.php
  Router.php
  auth.php
````

`includes/mailer.php` owns the PHPMailer specifics and exposes a
project-shaped API:

````php
// filepath: includes/mailer.php
function sendMail(string $to, string $subject, string $body): bool
{
    if (!mailIsConfigured()) {
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);   // true = exceptions

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_PORT === 465
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $body;

        return $mail->send();
    } catch (Exception $ex) {
        error_log('[mailer] ' . $ex->getMessage());
        return false;
    }
}
````

### 8.3 The rules that apply to the mailer

| Rule | Reason |
|---|---|
| **Exceptions on**, then catch | PHPMailer throws with `$mail->send()`'s exception mode; a silent failure would show the user "sent" for a message that never left. |
| **`SMTP_PORT === 465` → SMTPS, otherwise STARTTLS** | Port 465 is implicit TLS; port 587 is STARTTLS. Getting this wrong is the most common SMTP misconfiguration. |
| **A failed send returns `false`, it does not throw** | The caller decides what the user sees. An exception must not become a 500 on a public form. |
| **`error_log()`, never `echo`** | `display_errors = Off`; errors go to the LiteSpeed error log. |
| **`CharSet = 'UTF-8'`** | The messages contain user-supplied text. |
| **`setFrom()`, never `addReplyTo()` alone** | A message with no `From` is rejected by most SMTP servers. |
| **Amounts are formatted with `number_format()`** | Money is never a float — `AGENTS.md`, "Hard rules". |
| **Recipient and subject are escaped with `e()` when they are rendered in HTML** | Output escaping, per `AGENTS.md`. |

### 8.4 What is **not** specified here

The message **templates** — the text of the account-verification and
password-reset mails — are part of the application and of the UX track. The
mockups for the corresponding screens are in `docs/mockup/04-forgot-password.html`
and `docs/mockup/02-register.html`.

---

## 9. Acceptance criteria

- [x] `composer.json` exists, declares `php: >=8.5.12` and `phpmailer/phpmailer: ^7.1`.
- [x] `composer.json` sets `config.platform.php` to `8.5.12`.
- [x] `composer.lock` is **tracked** in git and pins `phpmailer/phpmailer` to an exact version.
- [x] `vendor/` is **git-ignored** and **not** tracked.
- [x] `composer.phar` is **not** tracked.
- [x] `composer install --no-dev --optimize-autoloader --no-interaction` succeeds against the `1.9.4-lsphp85` image.
- [x] `composer install` from the lock file reports **0 updates** — the lock file is consistent with `composer.json`.
- [x] `vendor/autoload.php` exists and `class_exists('PHPMailer\PHPMailer\PHPMailer')` is **true** after the install.
- [x] `PHPMailer\PHPMailer\PHPMailer::VERSION` reports the version in the lock file.
- [x] `composer validate --strict` exits **0**.
- [x] `composer audit` reports **no known vulnerabilities**.
- [x] `config.template.php` declares `MAIL_FROM` and `MAIL_FROM_NAME`.
- [x] `config.php` declares the same two constants — the parity assertion in `database/config_test.php` passes. — the assertion **skips** today, because `config.php` does not exist until the installer writes it. It will run from that moment on.
- [ ] `install.php` writes both constants, and the wizard collects both. — **deferred to the application track** ([issue-008.md](issue-008.md)): `install.php` does not exist yet. The wizard *screen* is specified — `docs/mockup/11-install-wizard.html` now collects `mail_from` and `mail_from_name`.
- [x] `docs/mockup/11-install-wizard.html` is updated to match the wizard.
- [x] `includes/mailer.php` exists and reads **only** `config.php` — no `getenv()`, no `$_ENV`.
- [x] The mailer returns `false` when `SMTP_HOST` is empty, and does not attempt a connection.
- [x] The mailer catches exceptions and returns `false`; a failed send never becomes an uncaught error.
- [x] `http://localhost:${APP_PORT}/vendor/autoload.php` returns **403** — the vendor tree is never served.
- [x] `http://localhost:${APP_PORT}/composer.json` returns **403** — the manifest is never served.
- [x] The **entrypoint does not run Composer** — `docker/entrypoint.sh` contains no `composer` invocation.
- [x] The application reports a **clear error** when `vendor/autoload.php` is missing, instead of a fatal "class not found". — `includes/bootstrap.php` throws a `RuntimeException` naming the command to run. The front controller must catch it and show it; `index.php` does not exist yet (application track).
- [x] `docker compose up -d` on a clean checkout with no `vendor/` does **not** attempt an install.

---

## 10. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 0. Preflight — Composer is fetched, not committed
curl -sSL -o /tmp/composer.phar https://getcomposer.org/composer-stable.phar

# 1. Install against the project's own PHP version
docker run --rm -v "$PWD":/app -v /tmp:/w -w /app \
  --entrypoint php litespeedtech/openlitespeed:1.9.4-lsphp85 \
  /w/composer.phar install --no-dev --optimize-autoloader --no-interaction

# 2. The manifest is valid and the tree is complete
docker run --rm -v "$PWD":/app -v /tmp:/w -w /app \
  --entrypoint php litespeedtech/openlitespeed:1.9.4-lsphp85 \
  /w/composer.phar validate --strict

docker run --rm -v "$PWD":/app -v /tmp:/w -w /app \
  --entrypoint php litespeedtech/openlitespeed:1.9.4-lsphp85 \
  /w/composer.phar audit

# 3. The lock file is consistent — a second install changes nothing
docker run --rm -v "$PWD":/app -v /tmp:/w -w /app \
  --entrypoint php litespeedtech/openlitespeed:1.9.4-lsphp85 \
  /w/composer.phar install --no-dev --dry-run
#   "Nothing to install, update or remove"

# 4. The class actually loads
docker compose exec -T app php -r \
  'require "/var/www/html/vendor/autoload.php";
   printf("%s %s\n", class_exists("PHPMailer\\PHPMailer\\PHPMailer") ? "OK" : "MISSING",
          PHPMailer\PHPMailer\PHPMailer::VERSION);'
#   OK 7.1.1

# 5. The vendor tree is NOT served
for p in /vendor/autoload.php /composer.json /composer.lock; do
  printf '%-24s %s\n' "$p" "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:8088$p")"
done
#   /vendor/autoload.php   403
#   /composer.json        403
#   /composer.lock        403

# 6. The config contract still holds
docker compose exec -T app php database/config_test.php
#   ALL ASSERTIONS PASSED

# 7. The runtime never runs Composer
grep -rn 'composer' docker/entrypoint.sh
#   (no output)

# 8. Tracking policy
git check-ignore -v vendor/          # ignored
git ls-files --error-unmatch composer.json composer.lock   # tracked
#   composer.json
#   composer.lock
````

---

## 11. Rollback

| What | How it is reverted |
|---|---|
| The dependency | Delete `composer.json`, `composer.lock` and the `vendor/` tree. The application falls back to the "not configured" path. |
| The new config constants | Remove `MAIL_FROM` and `MAIL_FROM_NAME` from `config.template.php`, `config.php` and the installer **in the same change** — otherwise the parity assertion in `database/config_test.php` fails. |
| The mockup change | `git checkout -- docs/mockup/11-install-wizard.html` |

Rolling back the dependency policy entirely returns the repository to the
"vendor by hand" rule in `AGENTS.md`. There is no data migration and no
database change, so the rollback is clean.

---

## 12. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| A developer runs `composer update` and the lock file changes silently | **High** — the deployed tree stops matching the reviewed one | The lock file is tracked; `composer install` is the everyday command; `composer update` is an explicit, reviewed change |
| The vendor tree drifts from the lock file | Medium | `composer install --dry-run` reports "Nothing to install, update or remove" |
| Someone puts Composer back in the container entrypoint | **High** — it re-creates the start-up install this policy forbids | The acceptance criteria grep the entrypoint; the policy is stated in `AGENTS.md` |
| The install runs against the wrong PHP version | Medium — the lock file resolves differently | The canonical command uses the project's own image; `config.platform.php` pins the resolution target |
| `vendor/` is served over HTTP | **High** — it exposes dependency source and internals | `.htaccess` already denies `/vendor/`; the acceptance criteria assert the 403 |
| The app fatals when `vendor/` is missing | Medium | The bootstrap guard throws a **clear, actionable** error |
| A mail send fails silently and the user thinks it worked | Medium — a UX-trust problem | Exceptions on, catch, return `false`, `error_log()` the reason; the caller shows the failure state |
| The `MAIL_FROM` constant is added to the template but not to `config.php` | Medium — the parity test fails | `AGENTS.md` states the same-change rule; `database/config_test.php` enforces it |
| PHPMailer is LGPL and that is a licensing question | Low — but real | `LGPL-2.1-only` allows use and modification with attribution for a personal, non-distributed application. If the application is ever distributed as a closed product, this must be re-reviewed. |

---

## 13. Out of scope

| Not in this issue | Why |
|---|---|
| Implementing `includes/mailer.php` | The application code does not exist yet — see `AGENTS.md`, "Status" |
| The message **templates** | Part of the UX track — the mockups in `docs/mockup/` |
| Sending the mail | A feature decision for the application track |
| An SMTP server for development | Not needed — the mailer degrades gracefully when SMTP is unset |
| A mail-catcher (MailHog / Mailpit) service | A useful convenience, but a separate concern from the dependency policy |
| Symfony Mailer | Rejected on the merits — §5.2 |
| A CI pipeline that runs `composer install` | There is no CI in this repository |
| Building a **custom image** with `vendor/` baked in | The policy allows either; the host-side install is simpler and keeps the "no build step" property. Reconsider if the deployment flow changes. |
