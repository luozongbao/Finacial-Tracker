# Issue-015 — App: the install wizard

> **Status:** **implemented and verified** — `install.php` and
> `assets/css/pages/install.css` exist. The wizard was walked through all three
> steps against the running stack and against a sandbox on 2026-10-27, and the
> layer is guarded by `database/install_test.php`.
> **Target:** v0.2 (the application) — the one screen that must work on a machine
> that has never run the product.
> **Severity:** **critical** — if the wizard cannot finish, the product cannot be
> installed; and if it leaves `install.php` reachable, the product can be
> re-configured by anyone.
>
> **Requested by the user:**
> > "I guess, we now have everything, ready for dev, write issue files for dev to
> > implement the project."
>
> **Depends on:** [issue-011.md](issue-011.md) (the schema it loads),
> [issue-012.md](issue-012.md) (`startSession()`, the CSRF helpers),
> [issue-013.md](issue-013.md) (the shell and the install page stylesheet).
> **Related:** [issue-016.md](issue-016.md) (the mailer it configures),
> [issue-003.md](issue-003.md) (the `config.php` write path),
> [issue-010.md](issue-010.md) (the mailer configuration contract),
> `config.template.php` (**the tracked twin the written file must match**),
> `database/config_test.php` (**the parity guard**),
> `docs/mockup/11-install-wizard.html` (**the screen being built**);
> `.github/instructions/php-app.instructions.md` (**the binding conventions**).

---

## Implementation notes — 2026-10-27

The specification left five questions open, and one of its rules conflicted with
the mockup. Each decision is recorded here and guarded by
`database/install_test.php`, so a later change cannot quietly reintroduce the
shape this issue rejected.

| # | The spec said | The decision | Why |
|---|---|---|---|
| 1 | The wizard "is **one file**" (§3) and the page stylesheet is `assets/css/pages/install.css` (§3) | **`install.php` is one file; the stylesheet is a second.** | The rule is about the *logic* being in one file, so that deleting `install.php` leaves no fragment behind. A stylesheet is not a fragment — it is a static asset that the application shell also loads, and the mockup's layer is `tokens → base → components → pages/install.css` like every other screen. |
| 2 | The mail fields "may be empty" for an open relay (§6), while the mockup asks for a user, a password and a From address whenever an SMTP host is set | **The issue wins: `smtp_user` and `smtp_pass` may be empty.** | The mockup is a picture of one configuration; the issue is the contract. An open relay is a real deployment, and a wizard that refuses to describe it is a wizard that cannot install one. The From address and the From name are still required — `setFrom()` needs them — and the **test** refuses to run without them. |
| 3 | The failure messages are one sentence each (§10) | **One sentence, split into an alert title and a hint.** | The mockup's alert has a short title — *The database server refused the connection.* — and a hint under it. `installSplitMessage()` cuts the message at the first stop, so the sentence reads the way the mockup reads, and the wording itself is the mockup's wording. |
| 4 | The wizard "**may** attempt the deletion itself when `is_writable()` allows it" (§9) | **The wizard does not delete itself.** | A bind-mounted project directory makes `is_writable()` a poor proxy for "the operator can remove this file", and a wizard that removes its own entry point is a wizard whose next run is a 404 instead of a refusal. The page says truthfully that the installer did not remove itself, and shows the exact `rm -f` command. |
| 5 | The wizard "has **no app bar and no nav**" and "**no footer link farm**" (§3) | **The wizard renders its own complete HTML document.** | It does not include `includes/header.php` / `includes/footer.php` — those are the *application* and *guest* shells, and they carry the app bar, the nav and the footer. The wizard still requires `includes/functions.php` for `e()`, `startSession()`, the CSRF helpers, `redirect()` and `asset_url()`. |
| 6 | §7 says the wizard loads `database/schema.sql` "with the wizard's own PDO handle" | **The handle is opened **with** the database selected, and the schema resolves the name at run time.** | `database/schema.sql` picks its database from `DATABASE()` with `financial_tracker` as the fallback, so the tables land in the database the operator typed — not in a hard-coded name. |

Three facts belong on the record:

- **A live installer is refused, not hidden.** The wizard answers **403** when
  `config.php` already exists, before it reads the request. The specification
  makes the *deletion* of `install.php` the operator's job; the refusal is the
  belt to that braces, and it costs one `is_file()`.
- **The wizard has no `vendor/`.** §1's first criterion is a machine with no
  `vendor/`, so the wizard cannot depend on Composer's autoloader. It requires
  `includes/functions.php` (no dependency) and requires
  `vendor/autoload.php` **only when it exists** — the mail test then reports
  *"The mail library is not installed. Run `composer install --no-dev
  --optimize-autoloader`, or skip this step and add mail later."* and the step
  does not advance. **Skipping stays available**, so the criterion holds: a
  machine with no `vendor/` finishes the wizard.
- **The `views/` deny rule is still owed.** The deny block refuses dotfiles,
  `config.php`, `database/`, `includes/`, `docker/`, `vendor/` and
  `composer.json|lock` — but not `views/`, which does not exist yet. **Issue-016
  must add the `views/` rule when it creates the first screen**, exactly as
  issue-014 recorded it. — **Paid on 2026-10-28 by issue-016**: the rule is in
  `.htaccess`, and `database/webroot_hardening_test.php` asserts that `/views/`
  and `/views/login.php` answer **403** over live HTTP.

---

## Table of contents

*Implementation notes — 2026-10-27* (the places where the specification
contradicted itself, and how each was resolved)

1. Objective
2. Constraints
3. The screen
4. Specification — the session and the step state
5. Specification — step 1, the database
6. Specification — step 2, the mail
7. Specification — step 3, access and summary
8. Specification — writing `config.php`
9. Specification — the success page
10. Specification — the failure messages
11. Acceptance criteria
12. Verification
13. Rollback
14. Risks
15. Out of scope

---

## 1. Objective

Build the **web installer**: a three-step wizard that collects the configuration,
proves it works, creates the schema, writes `config.php`, and then tells the
operator to delete itself.

Success criteria:

| # | Criterion |
|---|---|
| 1 | The wizard finishes on a machine with no `config.php` and no `vendor/` |
| 2 | Three steps — database, mail, access — each with a **test** before it advances |
| 3 | `config.php` is written with **the same constant set** as `config.template.php` |
| 4 | `ALLOW_REGISTRATION` is written as a **real boolean**, never as a string |
| 5 | The database connection is **proved** before the wizard advances |
| 6 | The mail step is **all-or-nothing** — either it works or it is skipped |
| 7 | A raw driver error **never** reaches the page |
| 8 | The schema is created by the wizard — a fresh install needs no manual SQL |
| 9 | The success page tells the operator to **delete `install.php`**, prominently |
| 10 | The wizard has **no app nav and no footer link farm** |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| The wizard runs **before `config.php` exists** | It cannot load `config.php`, so `DB_*` is undefined until step 3 finishes. |
| **No DB-backed probe** ("does the `users` table exist?") | It is **unreachable** in the wizard — the `DB_*` constants are undefined there, so any such probe is dead code. `config.php` existing is the only real gate. |
| `config.php` is written with **`var_export()`** | It produces valid PHP literal syntax. **Never `addslashes()`.** |
| The document root is a **bind mount** | The LSPHP worker (uid 1000) can already create `config.php`. No `chown` is needed. |
| `install.php` must be the **only** writer of `config.php` | A Docker entrypoint that pre-creates it would bypass the install gate and make the wizard untestable. |
| The wizard must be **deleted or locked** after a successful install | A live installer is a re-configuration hole. |
| `$_SERVER['HTTP_HOST']` is **user-controllable** | Any absolute URL is derived from `$_SERVER['SERVER_NAME']`. |
| `output_buffering = 0` | `startSession()` is the first call, before any output. |
| CSRF tokens must survive the step redirects | The session starts **before** the first token is issued. |
| `display_errors = Off` | The raw driver error goes to the error log, never to the page. |

---

## 3. The screen

The reference is [11-install-wizard.html](../mockup/11-install-wizard.html).

````text
┌──────────────────────────────────────────────┐
│  ① Database   ② Mail   ③ Access            │   the stepper
├──────────────────────────────────────────────┤
│  Step 1 — Database                           │
│    Database host      [ localhost      ]    │
│    Database name      [ financial_tracker ] │
│    Database user      [                 ]    │
│    Database password  [                 ]    │
│    [ Test and continue ]                     │
├──────────────────────────────────────────────┤
│  Step 2 — Mail (optional)                    │
│    SMTP host / port / user / password        │
│    From address / From name                  │
│    [ Test and continue ]  [ Skip mail setup ]│
├──────────────────────────────────────────────┤
│  Step 3 — Access and summary                 │
│    [switch] Allow public registration        │
│    Read-only summary (passwords masked)      │
│    [ Install ]                                │
└──────────────────────────────────────────────┘
````

### The rules

| Rule | Reason |
|---|---|
| The stepper is a **status list**, not a list of links | The steps are not navigable — they are a progress indicator. `stepper` / `stepper__item` / `stepper__num`. |
| There is **no app bar and no nav** | The visitor is not signed in. The wizard uses its own narrow shell. |
| There is **no footer link farm** | No documentation links, no social links, no "powered by". |
| Every step is a **separate request** | The steps are POSTs that redirect. That is what makes the CSRF token survive. |
| The wizard is **one file** — `install.php` | It is deleted after the install; splitting it across files would leave fragments behind. |
| The page stylesheet is `assets/css/pages/install.css` | Loaded after `components.css`. |

---

## 4. Specification — the session and the step state

The wizard keeps its state **in the session**, not in a hidden form field. A hidden
field can be tampered with; the session cannot.

````php
startSession();               // FIRST — before any output

if (!isset($_SESSION['install'])) {
    $_SESSION['install'] = [
        'step'  => 1,
        'db'    => [],
        'mail'  => [],
        'allow_registration' => true,
        'errors' => [],
    ];
}
````

| Rule | Reason |
|---|---|
| `startSession()` is the first call | `output_buffering = 0`; a byte emitted first breaks `header()`. |
| The session starts **before** the first token is issued | A token issued before the session exists is lost on the redirect. |
| Every mutating POST carries the CSRF token | The wizard is a POST-only form flow. |
| The step is validated against the **server-side** state | A visitor who posts `step=3` without having done step 1 is sent back to step 1. |
| The session is **destroyed** after a successful install | The next visitor must not inherit the step state. |

### The step advance

````php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session could not be verified. Please reload and submit again.';
    } else {
        // validate the step's fields, test the connection, then:
        $_SESSION['install']['step'] = $step + 1;
        redirect('/install.php');       // Post/Redirect/Get
    }
}
````

The **redirect** is what makes the step state server-side. A refresh on step 3 does
not re-post step 1.

---

## 5. Specification — step 1, the database

### The fields

| Field name | Default | Rule |
|---|---|---|
| `db_host` | `localhost` | Non-empty. |
| `db_name` | `financial_tracker` | Non-empty. No spaces. |
| `db_user` | — | Non-empty. |
| `db_pass` | — | **May be empty** — a local socket with `unix_socket` auth has no password. |

The mockup pre-fills `localhost`. It pre-fills the database name too; the wizard
must not create a database with a name the visitor did not ask for without saying
so.

### The test

The button is labelled **"Test and continue"** — so it really tests:

1. **Connect** to the server with the given host, user and password.
2. **Create the database** if it does not exist — `CREATE DATABASE IF NOT EXISTS`
   with `utf8mb4` / `utf8mb4_unicode_ci`.
3. **Select it** and prove the user can write to it — a `CREATE TABLE` / `DROP TABLE`
   round trip, or `SHOW GRANTS`. A user that can connect but not create tables will
   fail later, at schema time, with a much worse error.

The test happens **before** the wizard advances. A step that has not been tested
cannot advance.

### The rules

| Rule | Reason |
|---|---|
| The connection is made with `PDO` directly, **not** through `Database::getInstance()` | The `Database` singleton reads `DB_*` from `config.php`, which does not exist yet. The wizard builds its own PDO handle with the **submitted** values. |
| The handle is closed before the redirect | The wizard does not hold a connection across requests. |
| The database name is validated **before** it reaches SQL | It is a hard-coded allow-list check: `preg_match('/^[A-Za-z0-9_]+$/', $name)`. It is the only string interpolation into SQL in the wizard, and it is interpolated **only after** that check. |
| The charset is set on the connection | `charset=utf8mb4`. |

---

## 6. Specification — step 2, the mail

**Optional.** The wizard must be installable on a machine with no mail server — that
is why the button says *"Skip mail setup and continue"*.

### The fields

| Field name | Default | Rule |
|---|---|---|
| `smtp_host` | — | Empty means "not configured". |
| `smtp_port` | `587` | An integer, 1–65535. `465` is implicit TLS; everything else is STARTTLS — see `includes/mailer.php`. |
| `smtp_user` | — | May be empty for an open relay. |
| `smtp_pass` | — | May be empty. |
| `mail_from` | `no-reply@example.com` | Must be a valid address. |
| `mail_from_name` | `Financial Tracker` | Non-empty. |

### All-or-nothing

> The mail step is **all-or-nothing**. If the SMTP test fails, the wizard does not
> advance with a half-configured mailer.

Two ways to satisfy this, and only these two:

1. **Skip** — the visitor presses *"Skip mail setup and continue"*. `SMTP_HOST` is
   written as `''` and the mailer is not configured.
2. **Succeed** — the test connects, authenticates, and sends a real message to
   `mail_from`. Only then does the wizard advance.

There is no third state. A partial configuration is **not** written.

### The test

The test must actually **send** a message, not merely connect. A server that accepts
the connection and then refuses the message is common, and the wizard is the only
place that can find out.

The message goes to `mail_from` — the operator's own address. It says what it is.

### The `mailIsConfigured()` contract

`includes/mailer.php` decides "is mail configured" by **`SMTP_HOST !== ''`**. The
wizard must therefore write an **empty string**, not `null` and not `'0'`, when mail
is skipped. `null` would be a `TypeError` in the strict comparison.

---

## 7. Specification — step 3, access and summary

### The access switch

A single control: **"Allow public registration"**, backed by the
`ALLOW_REGISTRATION` constant.

| Property | Value |
|---|---|
| The default | **`true`** — the product brief says so. |
| The value written | A real PHP boolean. |
| The component | `switch` / `switch__track`. |
| The submitted field | `allow_registration` — `"1"` or `"0"`. |

> **The boolean trap.** The value must be written as `true` / `false`, **never** as
> the strings `'true'` / `'false'`. A string comparison fails **open**:
> `'false' === false` is `false`, so registration stays open when the operator meant
> to close it. `database/config_test.php` guards this — and it guards the
> **template**, so the written file must match it.

### The summary

A read-only list of everything collected so far, so the operator can check it before
the install commits. It uses `readonly-list` / `readonly-list__row` / `masked`.

| Row | Masked? |
|---|---|
| Database host | no |
| Database name | no |
| Database user | no |
| **Database password** | **yes** |
| SMTP host | no |
| SMTP port | no |
| SMTP user | no |
| **SMTP password** | **yes** |
| From address | no |
| From name | no |
| Registration allowed | no (yes / no) |

> **The passwords are masked and present.** This is the *wizard's* summary, where
> the value was just typed by the person reading it. It is the **opposite** of the
> settings screen, where the passwords must be **absent from the HTML source
> altogether** ([10-settings.html](../mockup/10-settings.html)). Do not carry the
> rule from one to the other.

### The install button

Pressing it:

1. Re-validates the CSRF token.
2. Creates the schema — loads `database/schema.sql` into the database.
3. Writes `config.php`.
4. Destroys the session.
5. Renders the success page.

### The schema load

The wizard loads `database/schema.sql` itself — this is the **production** path
(the fresh Docker volume is dev only, per
[issue-003.md](issue-003.md) and the database instructions).

| Rule | Reason |
|---|---|
| The file is read from disk with `file_get_contents()` and executed | It is one file, trusted, and part of the repository. |
| The file contains `CREATE DATABASE IF NOT EXISTS` + `USE` | So it loads whether or not a database is selected. |
| The file contains **no** `DROP TABLE` and **no** `TRUNCATE` | It must not destroy an existing installation's data. |
| The load is done with the **wizard's own PDO handle** | `Database::execute()` cannot run a multi-statement file. |

If `database/schema.sql` is missing, the wizard says so in plain language and does
**not** write `config.php` — a half-installed state is worse than none.

---

## 8. Specification — writing `config.php`

### The writer

````php
function writeConfig(array $values): bool
{
    $php = "<?php\n"
        . "/**\n"
        . " * config.php — written by install.php on " . gmdate('Y-m-d H:i:s') . " UTC.\n"
        . " *\n"
        . " * This file is git-ignored. config.template.php is the tracked twin and\n"
        . " * declares the SAME constant set. If you change one, change the other.\n"
        . " */\n\n"
        . "declare(strict_types=1);\n\n"
        . "const DB_HOST = " . var_export($values['db_host'], true) . ";\n"
        . "const DB_NAME = " . var_export($values['db_name'], true) . ";\n"
        . "const DB_USER = " . var_export($values['db_user'], true) . ";\n"
        . "const DB_PASS = " . var_export($values['db_pass'], true) . ";\n"
        . "const SMTP_HOST = " . var_export($values['smtp_host'], true) . ";\n"
        . "const SMTP_PORT = " . var_export((int) $values['smtp_port'], true) . ";\n"
        . "const SMTP_USER = " . var_export($values['smtp_user'], true) . ";\n"
        . "const SMTP_PASS = " . var_export($values['smtp_pass'], true) . ";\n"
        . "const MAIL_FROM = " . var_export($values['mail_from'], true) . ";\n"
        . "const MAIL_FROM_NAME = " . var_export($values['mail_from_name'], true) . ";\n"
        . "const ALLOW_REGISTRATION = " . var_export($allowRegistration, true) . ";\n"
        . "const WEEK_START = " . var_export(1, true) . ";\n"
        . "const TIMEZONE = " . var_export('UTC', true) . ";\n";

    return file_put_contents(__DIR__ . '/config.php', $php, LOCK_EX) !== false;
}
````

### The rules

| Rule | Reason |
|---|---|
| **`var_export($value, true)`** | It produces valid PHP literal syntax for strings, integers and booleans. `var_export(true, true)` emits `true`; `var_export(false, true)` emits `false`. The same call serves the string settings **and** the boolean flag. |
| **Never `addslashes()`** | It is not PHP escaping. A password containing `'` or `\` would produce a file that does not parse — and the product would be uninstallable with that password. |
| `SMTP_PORT` is cast to **`int`** first | `var_export('587', true)` emits `'587'` — a quoted string. `includes/mailer.php` casts it back, but the contract says the port is an integer. |
| `ALLOW_REGISTRATION` is written with `var_export(bool, true)` | A real boolean literal. |
| `LOCK_EX` | The write is exclusive. |
| The file is written **atomically** — write to a temp file, then `rename()` | A half-written `config.php` is a fatal parse error on the next request. |
| The constant set is **exactly** the 13 in `config.template.php` | `database/config_test.php` compares the two sets by name and fails if they differ. |
| The file starts with `<?php` and a docblock | It is a source file, and it should read like one. |

### The constant set — all thirteen

| Constant | Type | Written from |
|---|---|---|
| `DB_HOST` | string | Step 1 |
| `DB_NAME` | string | Step 1 |
| `DB_USER` | string | Step 1 |
| `DB_PASS` | string | Step 1 |
| `SMTP_HOST` | string | Step 2 (`''` when skipped) |
| `SMTP_PORT` | int | Step 2 (587 when skipped) |
| `SMTP_USER` | string | Step 2 (`''` when skipped) |
| `SMTP_PASS` | string | Step 2 (`''` when skipped) |
| `MAIL_FROM` | string | Step 2 |
| `MAIL_FROM_NAME` | string | Step 2 |
| `ALLOW_REGISTRATION` | **bool** | Step 3 |
| `WEEK_START` | int | Fixed at `1` (Monday) |
| `TIMEZONE` | string | Fixed at `'UTC'` |

`WEEK_START` and `TIMEZONE` are **not collected** — the mockup has no field for
them, and they are not operator choices in v0.2. They are written with their
defaults. If a later issue adds a setting for either, the wizard gains a field
**and** the constant is still written from the form.

### After the write

The wizard **verifies** the file it just wrote:

````php
// Parse-check the file we just wrote before declaring success.
$check = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');
if (!is_string($check) || strpos($check, 'No syntax errors') === false) {
    // unlink the broken file and report the failure
}
````

A `config.php` that does not parse is worse than no `config.php` — the install gate
would let the application start, and every request would fatal.

---

## 9. Specification — the success page

After a successful install, the wizard renders:

````html
<div class="alert alert--success">
  <div class="alert__body">
    <p class="alert__title">Financial Tracker is installed.</p>
    <p>…</p>
  </div>
</div>

<div class="alert alert--danger">
  <div class="alert__body">
    <p class="alert__title">Delete install.php now.</p>
    <p>This file can reconfigure the installation. Remove it before you go live.</p>
  </div>
</div>

<a class="btn btn--primary btn--block" href="/dashboard">Go to the dashboard</a>
````

| Rule | Reason |
|---|---|
| The danger alert is **prominent**, not a footnote | A live installer is a re-configuration hole. |
| The exact wording is **"Delete install.php now."** | It is the instruction the operator must not miss. |
| The success alert says **"Financial Tracker is installed."** | The mockup's exact string. |
| There is a **"Go to the dashboard"** button | The operator lands somewhere useful. |
| The wizard **offers** to delete itself but does not require it | The wizard may not have the permission, and the file is on a bind mount. Offer the command. |

### The self-deletion

The page offers the exact command:

````bash
rm -f /var/www/html/install.php
````

and the wizard **may** attempt the deletion itself when `is_writable()` allows it. It
must **not** pretend it succeeded when it did not — the page says which happened.

> **Do not** delete `install.php` from a Docker entrypoint or a `.htaccess` rule.
> The file is the operator's to remove, and the wizard telling them to is the whole
> mechanism.

---

## 10. Specification — the failure messages

The wizard's job is to explain a connection failure **without** leaking the driver
error. The mockup names three failures, each described in plain language:

| The real failure | What the page says |
|---|---|
| The server refused the connection | *"The database server refused the connection. Check the host and that the database is running."* |
| The database does not exist | *"That database does not exist on the server. Create it, or check the name."* |
| The user may not use the database | *"That user is not allowed to use the database. Check the user's permissions."* |

### The rules

| Rule | Reason |
|---|---|
| The **raw driver error never reaches the page** | It names the host, the socket path and the internal state of the server. It goes to `error_log()`. |
| The message is **classified**, not printed | The wizard matches the PDO error code (`1045` access denied, `1049` unknown database, `2002` / `2003` cannot connect) and picks a sentence. |
| An **unclassified** error gets the generic message | *"The database connection failed. Check the settings and try again."* |
| The submitted values are **kept** | The operator does not retype everything because of a typo. The password fields are the exception — they are cleared, because a password should not linger in the browser. |
| The step does **not** advance | The operator stays on the step that failed. |

### The error codes

| PDO error code | Meaning | Message |
|---|---|---|
| `1045` | Access denied for the user | *"That user is not allowed to use the database. Check the user's permissions."* |
| `1049` | Unknown database | *"That database does not exist on the server. Create it, or check the name."* |
| `2002` | Cannot connect (socket) | *"The database server refused the connection. Check the host and that the database is running."* |
| `2003` | Cannot connect (TCP) | *"The database server refused the connection. Check the host and that the database is running."* |
| `2005` | Unknown host | *"The database server refused the connection. Check the host and that the database is running."* |
| anything else | — | *"The database connection failed. Check the settings and try again."* |

---

## 11. Acceptance criteria

- [ ] `install.php` exists and is a **single file**.
- [ ] `startSession()` is the first call, and the session is started **before** the
      first CSRF token is issued.
- [ ] The step state lives in `$_SESSION['install']`, not in a hidden form field.
- [ ] Every step is a POST that **redirects** after a successful advance.
- [ ] Every form carries `<input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">`,
      and the token is validated **before** any input is read.
- [ ] A visitor who posts a step they have not passed is sent back to step 1.
- [ ] Step 1 has the fields `db_host` (default `localhost`), `db_name`, `db_user`,
      `db_pass`, and the button **"Test and continue"**.
- [ ] Step 1 **proves** the connection: it connects, creates the database with
      `CREATE DATABASE IF NOT EXISTS`, and proves the user can write.
- [ ] The database name is validated against `/^[A-Za-z0-9_]+$/` **before** it
      reaches SQL.
- [ ] Step 2 has the fields `smtp_host`, `smtp_port` (default `587`), `smtp_user`,
      `smtp_pass`, `mail_from`, `mail_from_name` (default `Financial Tracker`), and
      both **"Test and continue"** and **"Skip mail setup and continue"**.
- [ ] Step 2 is **all-or-nothing**: a failed test does not advance, and a partial
      configuration is never written.
- [ ] The mail test **sends a real message** to `mail_from`.
- [ ] Skipping the mail writes `SMTP_HOST = ''` — an empty string, not `null`.
- [ ] Step 3 has the `ALLOW_REGISTRATION` switch (default **on**) and a read-only
      summary with the passwords **masked but present**.
- [ ] The install loads `database/schema.sql` and creates the tables.
- [ ] The install **fails cleanly** when `database/schema.sql` is missing, and does
      **not** write `config.php`.
- [ ] `config.php` is written with `var_export($value, true)` — **never**
      `addslashes()`.
- [ ] The written file declares **exactly** the 13 constants of
      `config.template.php`, with the same names.
- [ ] `ALLOW_REGISTRATION` is written as a **bare `true` / `false` literal**.
- [ ] `SMTP_PORT` is written as an **integer**.
- [ ] `WEEK_START` is written as `1` and `TIMEZONE` as `'UTC'`.
- [ ] The file is written **atomically** (temp file + `rename()`).
- [ ] The wizard **parse-checks** the file it wrote, and reports a failure rather
      than declaring success.
- [ ] The success page says **"Financial Tracker is installed."**, shows a
      **prominent** danger alert reading **"Delete install.php now."**, and offers a
      **"Go to the dashboard"** button.
- [ ] The wizard has **no app bar, no nav and no footer link farm**.
- [ ] The stepper is a **status list**, not a set of links.
- [ ] A PDO failure never prints the raw driver message; the wizard classifies
      `1045`, `1049`, `2002`, `2003` and `2005` and shows plain-language sentences.
- [ ] The database error path writes the raw message to `error_log()`.
- [ ] `install.php` passes `php -l`.

---

## 12. Verification

```bash
cd /home/zongbao/projects/financial-tracker

# 0 — the automated guard: it walks the whole wizard in a sandbox
docker compose exec -T app php /var/www/html/database/install_test.php
echo "exit=$?"          # 0 = pass

# 1 — lint
docker compose exec -T app php -l /var/www/html/install.php

# 2 — the wizard is reachable when config.php is missing
mv config.php /tmp/config.php.bak 2>/dev/null || true
docker compose restart app
sleep 2
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8088/          # 302 -> /install.php
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8088/install.php   # 200

# 3 — the wizard has no app nav and no footer link farm
curl -s http://localhost:8088/install.php | grep -c 'app__bar\|nav__link'  # must be 0
curl -s http://localhost:8088/install.php | grep -c 'stepper__num'         # must be > 0

# 4 — the CSRF token is present and the step state is in the session
curl -s -c /tmp/ck.txt http://localhost:8088/install.php | grep -o 'name="csrf_token" value="[a-f0-9]\{64\}"'
curl -s -b /tmp/ck.txt -o /dev/null -w "%{http_code}\n" \
     -d "csrf_token=wrong&db_host=localhost&db_name=x&db_user=x&db_pass=" \
     http://localhost:8088/install.php        # 200 with the CSRF error, and step 1 NOT advanced

# 5 — a real database failure shows a plain-language message, not the driver error
curl -s -b /tmp/ck.txt -d "csrf_token=$(curl -s -b /tmp/ck.txt http://localhost:8088/install.php | grep -oP 'csrf_token" value="\K[a-f0-9]+' | head -1)&db_host=localhost&db_name=definitely_missing&db_user=nope&db_pass=nope" \
     http://localhost:8088/install.php | grep -iE 'SQLSTATE|Access denied for user|pdoexception' \
     && echo "!! RAW DRIVER ERROR LEAKED" || echo "no-raw-error: ok"

# 6 — install it for real and check the written file
curl -s -b /tmp/ck.txt -o /dev/null -w "%{http_code}\n" \
  -d "csrf_token=...&db_host=db&db_name=financial_tracker&db_user=ft_user&db_pass=ft_pass" \
  http://localhost:8088/install.php
docker compose exec -T app php -l /var/www/html/config.php

# 7 — the config contract guard still passes
docker compose exec -T app php /var/www/html/database/config_test.php
echo "exit=$?"

# 8 — the ALLOW_REGISTRATION value in the written file is a bare literal
grep -E 'const ALLOW_REGISTRATION = (true|false);' config.php

# 9 — restore
mv /tmp/config.php.bak config.php 2>/dev/null || true
docker compose restart app

# 10 — the whole suite
.github/skills/dev-stack-ops/scripts/run-tests.sh
echo "suite_exit=$?"
```

Expected: step 0 exits **0**; step 2 shows **302** and **200**; step 3 shows **0**
for the nav grep and a positive count for the stepper; step 4 shows a
64-hex-character token; step 5 prints `no-raw-error: ok`; step 6 prints `No syntax
errors detected`; step 7 exits **0**; step 8 prints the bare literal; step 10
reports **0 FAIL**.

> Steps 6–8 need the real database credentials. Take them from `.env` — the `db`
> service's `MARIADB_USER` / `MARIADB_PASSWORD` — and from the compose file's
> `db` service, which is reachable from the app container as the host name `db`.

> **Step 0 is the one that matters.** `database/install_test.php` automates the
> whole of §11: it copies the application into a temporary directory, serves it
> with the PHP built-in server, walks the three steps with a real session and a
> real CSRF token, and checks the written `config.php` — its constant set, its
> bare boolean, its integer port, its parse — and the five tables in the
> database. It then checks the refusal on a second run. The manual steps above
> remain useful for looking at the screen; they are not the guard.
>
> The sandbox writes its `config.php` **inside the temporary directory**, so the
> repository's own `config.php` is never created and the development database is
> never touched. The scratch database `ft_install_test` is created with the root
> account when that account is reachable and dropped afterwards; when it is not,
> the test falls back to the application user and the configured database.

---

## 13. Rollback

````bash
# Remove the written configuration and the installer
rm -f config.php
rm -f install.php

# Drop the database the wizard created
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" \
    -e "DROP DATABASE IF EXISTS financial_tracker;"

docker compose restart app
````

> **Never `docker compose down -v`.** It deletes the whole MariaDB data volume.

The wizard is not part of the application — removing it removes nothing the product
needs.

---

## 14. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The password contains a `'` and `addslashes()` corrupts the file | Medium | §8 mandates `var_export()` and forbids `addslashes()`. |
| `ALLOW_REGISTRATION` is written as the string `'false'` | Medium | §8 mandates `var_export(bool, true)`; `database/config_test.php` guards the shape; the acceptance criteria name the literal. |
| `SMTP_PORT` is written as `'587'` | Low | §8 casts to `int` before `var_export()`. |
| The constant set drifts from `config.template.php` | Medium | The thirteen names are tabulated in §8; `config_test.php` compares the sets by name and fails on a difference. |
| A half-written `config.php` is left behind | Medium | §8 requires the temp-file + `rename()` pattern and the parse check. |
| The raw driver error leaks to the page | Medium | §10 tabulates the codes; the verification greps for `SQLSTATE` and `PDOException`. |
| The database name is interpolated into SQL unvalidated | Low | §5 requires the `^[A-Za-z0-9_]+$` check first. |
| The CSRF token is lost between the steps | Medium | §4 starts the session before the first token and redirects between steps. |
| `install.php` survives the install | Medium | §9 requires the prominent danger alert with the exact wording. |
| The wizard tries to load `config.php` and fatals | Low | §2 states it must not; the constants are undefined until step 3. |
| The schema load silently half-succeeds | Low | §7 requires the load to happen **before** `config.php` is written, and the wizard reports a failure rather than declaring success. |

---

## 15. Out of scope

- **The application screens** — issues [016](issue-016.md) – [020](issue-020.md).
- **The first user account.** The wizard creates the schema; the account is created
  through the register screen (or the first sign-in, if registration is closed).
- **Automatic updates, a version check, or a "check for a new release" call-out.**
- **A multi-tenant or multi-database setup.** One database, one installation.
- **Importing an existing configuration.** The wizard starts empty.
- **A CLI installer.** The web wizard is the contract in the brief.
- **Backing up an existing installation.** The wizard is for a fresh install.
