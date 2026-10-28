# Issue-025 — Security: the installer keeps the credentials in the session store

> **Status:** **open** — the audit of 2026-10-28 confirmed that the installer
> writes the database password, and later the SMTP password, into the PHP
> session in clear text, and that the file-session handler then writes them to
> disk in the shared `/tmp` directory of the host, under a **file name an
> attacker can choose**.
> **Target:** `install.php`, `includes/functions.php` (`startSession()`),
> `database/install_test.php`, `database/live/live_security_test.php`
> **Severity:** **Medium** — the window is short (the install is a one-time
> operation) and the file is mode `0600`, but the exposed secret is the
> **production database credential**, and the file name is attacker-controllable.
> **Requested by the user:** "Now the implementation is complete. before release
> v.1.0, let's focus on security for a bit. 1. help check sql injection, or any
> security risk. and write issue files for dev to fix those risks."
> **Depends on:** [issue-010](issue-010.md) (the SMTP mailer and its
> configuration), [issue-012](issue-012.md) (`includes/functions.php`, the
> session), [issue-015](issue-015.md) (the install wizard and its three steps)
> **Related:** [issue-024](issue-024.md) (the response headers — the installer
> sends none), [issue-023](issue-023.md) (the web-root hardening)

---

## Table of contents

1. [Objective](#1-objective)
2. [How the installer holds its state today](#2-how-the-installer-holds-its-state-today)
3. [Finding 1 — the secret is written to disk in clear text](#3-finding-1--the-secret-is-written-to-disk-in-clear-text)
4. [Finding 2 — the file name is chosen by the client](#4-finding-2--the-file-name-is-chosen-by-the-client)
5. [Finding 3 — an abandoned wizard leaves the secret behind](#5-finding-3--an-abandoned-wizard-leaves-the-secret-behind)
6. [Finding 4 — the SMTP password follows the same path](#6-finding-4--the-smtp-password-follows-the-same-path)
7. [The fix](#7-the-fix)
8. [Acceptance criteria](#8-acceptance-criteria)
9. [Verification](#9-verification)
10. [Rollback](#10-rollback)
11. [Risks](#11-risks)
12. [Out of scope](#12-out-of-scope)
13. [Findings that were audited and found clean](#13-findings-that-were-audited-and-found-clean)

---

## 1. Objective

Keep the **database password** and the **SMTP password** out of the session
store, and out of the session store's **backing files**.

The install wizard collects two secrets — the database password and the SMTP
password — and it has to carry them from the form that collects them to the
moment it writes `config.php`. The current implementation carries them in
`$_SESSION`, which is the wrong carrier for a secret that never needs to
survive a page boundary:

- The session is **serialised to disk** by PHP's file handler, in clear text,
  in the host's shared `/tmp`.
- The session **survives the wizard**: it is only destroyed on a *successful*
  install, so an abandoned or failed wizard leaves the secret on disk.
- The session **id is not regenerated**, and `session.use_strict_mode` is `0`,
  so the *file name* the secret lands in is chosen by the client.

The fix is to **stop using the session as a secret carrier**. The wizard needs
the step state (the database name, the mail host, the registration flag) across
requests; it does **not** need the passwords across requests.

---

## 2. How the installer holds its state today

The state lives in `$_SESSION['install']`:

| Piece | Where | What it does |
|---|---|---|
| `installState()` | `install.php:275` | Reads `$_SESSION['install']` and merges the defaults. |
| `installSaveState()` | `install.php:300` | Writes the **whole** state back into `$_SESSION`. |
| `installCollectDb()` | `install.php:365` | Reads `db_pass` with `installRawPost()` — **untrimmed**, on purpose. |
| `installCollectMail()` | `install.php:379` | Reads `smtp_pass` with `installRawPost()` — untrimmed. |
| `installRawPost()` | `install.php:398` | Reads `$_POST[$key]` without trimming. |
| `installDestroySession()` | `install.php:954` | Empties the session, expires the cookie and calls `session_destroy()` — **only** on a successful install. |
| `startSession()` | `includes/functions.php:247` | Starts the session with `httponly`, `samesite=Lax` and `secure` over HTTPS. It does **not** set `session.use_strict_mode` and does **not** call `session_regenerate_id()`. |

The state that is written back after a successful step 1 is (measured, not
guessed — see §3):

```
install|a:6:{s:4:"step";i:2;
  s:2:"db";a:4:{s:7:"db_host";s:2:"db";s:8:"db_name";s:8:"probe_ft";
                s:7:"db_user";s:8:"probe_ft";
                s:7:"db_pass";s:23:"Pr0beDbSecretValue_9182";}
  s:4:"mail";a:0:{}s:18:"allow_registration";b:1;
  s:10:"db_created";b:0;s:15:"mail_configured";b:0;}
```

`db_pass` is there, in clear text, in the serialised session.

### The measured configuration

The measurement was taken inside the running container:

```
session.save_path             = ''          <- the default: /tmp
session.save_handler          = 'files'
session.use_strict_mode       = '0'         <- a client-supplied id is accepted
session.use_only_cookies      = '1'
session.use_trans_sid         = '0'
session.gc_maxlifetime        = '1440'      <- 24 minutes
```

and the directory the files land in:

```
drwxrwxrwt 1 root root 69632 /tmp
```

`/tmp` is **world-writable with the sticky bit** — the shared temporary
directory of the host. On the Docker stack that is the container's own `/tmp`;
on a **native installation** — the supported production path, per
[issue-003](issue-003.md) and [issue-009](issue-009.md) — it is the **host's**
`/tmp`, shared by every process of every user on the box.

---

## 3. Finding 1 — the secret is written to disk in clear text

**Measured.** A sandbox copy of the wizard (no `config.php`, so the wizard
runs) was driven through a **successful** step 1 against a scratch database.
The session file that PHP wrote was then read:

```
--- the session file /tmp/sess_60ad75e3a3ca341206a9f47988842399 ---
-rw------- 1 root root 349 Oct 29 00:51 /tmp/sess_60ad75e3a3ca341206a9f47988842399

--- is the password in clear text? ---
YES.

--- the raw session payload ---
csrf_token|s:64:"67e49eab54a3d63486736f02d9ed2d3d686cff01364343f631f943118539ff08";
install|a:6:{s:4:"step";i:2;s:2:"db";a:4:{s:7:"db_host";s:2:"db";s:8:"db_name";
s:8:"probe_ft";s:7:"db_user";s:8:"probe_ft";s:7:"db_pass";
s:23:"Pr0beDbSecretValue_9182";}...}
```

The password is written to disk **verbatim**. The mode `0600` limits the read
to the account that runs PHP — which, on a shared host, is the account that
runs **every other site on the box**.

---

## 4. Finding 2 — the file name is chosen by the client

This is the sharp edge of Finding 1. Without it, the file name is a random
32-character id that the attacker cannot guess. With it, the attacker picks the
name in advance.

`session.use_strict_mode = 0` means PHP accepts a client-supplied session id
**even when no such session exists**, and creates it. `startSession()` never
calls `session_regenerate_id()`, so the id the client sent is the id that is
kept for the whole wizard.

**Measured.** The probe sent a request with `Cookie: PHPSESSID=aaaaaaaa...`
(26 characters, PHP's default id length), then drove step 1 to completion with
the same cookie:

```
=== 1. the attacker chooses the session id ===
before: no such file
the CSRF token came back: 0ec0a4033679d287 ...
-rw------- 1 root root 83 Oct 29 00:54 /tmp/sess_aaaaaaaaaaaaaaaaaaaaaaaaaa
-> PHP CREATED the file at the chosen name

=== 2. the victim completes step 1 ===
step1 status: 302

=== 3. the file the attacker chose ===
-rw------- 1 root root 349 Oct 29 00:54 /tmp/sess_aaaaaaaaaaaaaaaaaaaaaaaaaa

>>> THE PASSWORD IS IN THE ATTACKER-CHOSEN FILE <<<
s:7:"db_pass"

=== 4. did the server rotate the session id? ===
(empty — no Set-Cookie header)
```

So the chain is complete and measured:

1. The attacker sets `PHPSESSID` in the victim's browser.
2. The victim (the operator, on their own installation) types the database
   password into the installer.
3. The password lands in `/tmp/sess_<the attacker's chosen name>`.
4. The attacker — running as the same `www-data` account on the shared host,
   or as root — reads it.

**How the attacker sets the cookie.** `session.use_only_cookies = 1` means the
id cannot be carried in the URL, so this needs cookie injection rather than a
link. The supported deployment paths make that reachable:

- A **plain-HTTP installation on a LAN** — explicitly supported by
  [issue-009](issue-009.md) — lets anyone on the wire inject a cookie.
- A **sibling site on the same registrable domain** can set a cookie for the
  parent domain, and the installer is usually the same host.
- Any **XSS anywhere on the host** can set the cookie before the operator opens
  the installer.

The window is narrow — the operator has to be running the installer — but the
installer is exactly when the production credential is being typed into a
browser.

---

## 5. Finding 3 — an abandoned wizard leaves the secret behind

`installDestroySession()` is called from **one** place: the success branch of
the `install` action, after `config.php` has been written. Every other exit
leaves the session file on disk:

| How the wizard ends | The secret on disk |
|---|---|
| The operator closes the tab after step 1 | **Yes** — for up to `gc_maxlifetime` = **1440 s (24 min)** |
| Step 2 fails (the SMTP server is unreachable) | **Yes** — the DB password is still in the session |
| The operator mistypes the password in step 3 | **Yes** — the DB password is still in the session |
| `config.php` cannot be written (permissions) | **Yes** — and the wizard says *"your entries are still here"* |
| The install succeeds | No — `installDestroySession()` runs |

The garbage collector removes the file only when the session has been **idle**
for 1440 seconds. Twenty-four minutes is a comfortable window: it covers a
lunch break, a phone call, or an overnight if the machine is suspended.

---

## 6. Finding 4 — the SMTP password follows the same path

The SMTP password is collected with the same `installRawPost()` and stored
with the same `installSaveState()`:

```php
// install.php — step 2, the mail server.
$result = installTestMail($submitted);

if ($result['ok'] === false) {
    $errors[] = $result['message'];
    $mail     = array_merge($mail, $submitted);
} else {
    $state['mail']            = $submitted;   // <- smtp_pass, in the clear
    $state['mail_configured'] = true;
    $state['step']            = 3;
    installSaveState($state);                  // <- into $_SESSION
    redirect(INSTALL_PATH);
}
```

So the SMTP password lands in the session file on exactly the same terms. This
path was **not** measured live — standing up a STARTTLS server that PHPMailer
accepts is out of proportion to the point — but the carrier is byte-for-byte
the same code path as the database password, whose live behaviour is measured
in §3.

Note the one path that does **not** leak: `skip_mail` replaces the whole mail
array with empty values before saving, so a skipped step writes no SMTP
password.

---

## 7. The fix

Three changes, in order of value.

### 7.1 Do not put the secrets in the session — the primary fix

The wizard needs the step state across requests. It does **not** need the
passwords across requests.

- **Step 1 (database).** The password is needed twice: to *test* the connection,
  and to *write* `config.php`. Test it on the step-1 POST; **do not keep it**.
  The connection test happens on the same request that collects it.
- **Step 3 (install).** `config.php` needs the database password again. Two
  acceptable designs:
  - **Re-ask.** Step 3 asks for the password again (a single password field,
    no echo of the previous value) and tests it once more before writing. The
    wizard holds no secret at all. This is the cleanest design and it is what
    this issue asks for.
  - **Hold it for one request.** Keep the secret in a value that lives for the
    duration of a **single** request and never touches the session — for
    example a closure over a local variable, or a value passed between the
    handler and the writer inside the same request.

  The same applies to the SMTP password: it is needed on the step-2 POST (to
  test the mail server) and on the step-3 POST (to write `config.php`).
- Whatever design is chosen, **`installSaveState()` must never receive a
  password.** The regression test must assert this structurally.

The non-obvious part: the wizard's own error messages must not print the
password back either. *"That username and password do not match"* is the
pattern the rest of the application uses; the installer should not echo the
submitted value into the page.

### 7.2 Rotate the session id and lock the id down — the defence in depth

Even with the secrets out of the session, the wizard's session is an
unauthenticated, unauthenticated-state cookie, and a fixated session id in the
installer is still a session-fixation foothold.

- Call `session_regenerate_id(true)` at the **start** of the wizard and after
  every step boundary.
- Set `session.use_strict_mode = 1` for the wizard's session so PHP refuses a
  client-supplied id that has no session file behind it. (This is a `php.ini`
  setting, not a function call: `ini_set('session.use_strict_mode', '1')`
  **before** `session_start()` works, and is a legitimate use of `ini_set`.)
- Consider `session.sid_length` and `session.sid_bits_per_character` — the
  defaults (26 chars from 4 bits each) are acceptable; no change is required.

### 7.3 Move the session files out of the shared `/tmp`

Even the step state and the CSRF token should not live in a world-writable
directory that every process on the host can see the name of.

- Point `session.save_path` at a directory that is **not** shared — for the
  native install, a directory under the application's own storage (e.g.
  `storage/sessions/`) created with mode `0700`, or the distribution's
  `php.ini` default (`/var/lib/php/sessions`, which is `0700` on Debian and
  Ubuntu).
- If the application creates the directory itself, it must do so **without**
  following a symlink, and it must `chmod 0700` it.
- On the Docker stack the container's `/tmp` is already private to the
  container, so this change matters mainly for the native path — which is the
  production path.

> **Do not** ship this as a hard-coded absolute path. The correct location is
> host-specific, so it must be **configurable** — and `config.php` does not
> exist yet during the install, so the value has to come from
> `config.template.php` or from an `ini_set()` in `install.php` itself.

---

## 8. Acceptance criteria

- [ ] `$_SESSION['install']` **never contains** `db_pass` or `smtp_pass`, at
      any step, in any outcome — including the success path.
- [ ] The wizard still completes a full install: step 1 tests the database,
      step 2 tests (or skips) the mail server, step 3 writes `config.php` with
      the **correct** password and the correct `ALLOW_REGISTRATION` boolean.
- [ ] The `config.php` that is written contains the password **exactly** as it
      was typed — not trimmed, not re-encoded (the current `installRawPost()`
      behaviour must be preserved for the value that is written, even if the
      value is no longer stored in the session).
- [ ] The session id is regenerated at the start of the wizard and after every
      step boundary; a request carrying an unknown `PHPSESSID` does **not**
      cause a session file with that name to be created.
- [ ] `session.use_strict_mode` is `1` for the wizard's session.
- [ ] The session file is **not** written to the shared `/tmp` (or, if the host
      has no alternative, the directory is at least not world-readable).
- [ ] The wizard still renders the same three steps, the same validation
      messages and the same success page — **no** regression to the operator's
      experience.
- [ ] The wizard still refuses to run once `config.php` exists, with a 403.
- [ ] The wizard's own document still sends the security headers of
      [issue-024](issue-024.md).
- [ ] `database/install_test.php` asserts the structural rule: **no** code path
      writes a password into `$_SESSION`.
- [ ] A LIVE test asserts that after a completed step 1, **no** file under the
      session save path contains the submitted password.

---

## 9. Verification

```bash
# 1. The structural rule: no password ever reaches the session.
grep -n "db_pass\|smtp_pass" install.php
# Every hit must be either a read from $_POST, a use in a same-request
# function call, or a use in the config.php writer. None may be an assignment
# into $_SESSION['install'].

grep -n "installSaveState" install.php -A 3
# The state array it writes must contain no secret.

# 2. The live rule: after a completed step 1, the password is nowhere on disk.
docker compose exec -T app php -r 'echo ini_get("session.save_path") ?: "/tmp";'
# Then drive the wizard to a successful step 1 and search the save path:
docker compose exec -T app sh -c 'grep -rl "THE_PROBE_PASSWORD" "$(php -r "echo ini_get(\"session.save_path\") ?: \"/tmp\";")" 2>/dev/null'
# Expected: no output.

# 3. The fixation rule: an unknown session id does not create a file.
docker compose exec -T app sh -c 'rm -f /tmp/sess_probeunknownid1234567890ab'
curl -s -H 'Cookie: PHPSESSID=probeunknownid1234567890ab' -o /dev/null \
  http://127.0.0.1:8088/install.php
docker compose exec -T app ls -l /tmp/sess_probeunknownid1234567890ab
# Expected: "No such file or directory" — the id is not adopted.

# 4. The suite.
bash .github/skills/dev-stack-ops/scripts/run-tests.sh
# Expected: every test PASSes, with the completion marker.
bash database/live/run-live-tests.sh
# Expected: every live test PASSes.
```

> The LIVE tier needs a booted stack and an installed `config.php`, and it
> **writes to the dev database**. Every live test must clean up after itself.

---

## 10. Rollback

The change is confined to `install.php` and, if the session save path is
relocated, `includes/functions.php`.

- The wizard's behaviour is unchanged: the same three steps, the same
  validation, the same `config.php`.
- To roll back, revert the code. **No migration** is involved — the schema is
  untouched.
- **Do not** run `docker compose down -v` to "reset" anything. Take a dump
  first.

---

## 11. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Re-asking for the password annoys the operator | Medium | Low | A single extra password field on step 3, with the field labelled to say which credential it is. The wizard is a one-time operation. |
| The re-entered password is wrong and the install fails late | Medium | Low | The step-3 POST **tests the password again** before it writes `config.php`, so the failure happens at step 3 with a clear message — not on the first request of the running application. |
| A stray session file from a previous wizard still holds an old password | Low | Low | The regression test scans the session save path, and the wizard destroys the session on success. A stale file is also covered by the 24-minute GC window. |
| Moving `session.save_path` breaks the running application | Low | High | The change is opt-in and reversible; `config.php` does not exist during the install, so the value must be applied with `ini_set()` in `install.php` **or** from `config.template.php` — never from an environment variable in application code. |
| `session.use_strict_mode = 1` breaks a legitimate return visit | Low | Low | Strict mode only refuses an id with **no** session file behind it. A browser that already holds a live wizard session keeps it. |
| The developer "fixes" the mail step and now stores the SMTP password in the session | Medium | Medium | The structural test in **§9, step 1** is a **grep-free source assertion** in `database/install_test.php` that fails the build, plus the live scan in **§9, step 2**. |

---

## 12. Out of scope

- **The application's own session** — it holds the user id and the credential
  version, never a secret, and `loginUser()` already calls
  `session_regenerate_id(true)`. That is audited and clean; see §13.
- **The `config.php` file itself** — it must contain the database password in
  clear text; that is the configuration contract of
  [issue-010](issue-010.md) and [issue-015](issue-015.md). The protection for
  that file is the **web-root deny block**, audited in
  [issue-023](issue-023.md).
- **The installer's HTTP headers** — that is [issue-024](issue-024.md).
- **A secrets manager / vault** — out of scope for a personal, single-host
  installation.

---

## 13. Findings that were audited and found clean

These were checked during the same audit and need **no change**. They are
recorded here so that the next reader does not repeat the work.

| Area | Verdict |
|---|---|
| **The installer refuses to run once `config.php` exists** | Correct — 403, and the message tells the operator to delete `install.php`. A live installer is a re-configuration hole, and the hole is closed. |
| **The database name is validated before it is quoted** | Correct — `/^[A-Za-z0-9_]+$/`, and the identifier is the only interpolated SQL fragment in the wizard. |
| **`config.php` is written atomically** | Correct — `var_export()` into a temporary file, the file is **parse-checked**, and it is then `rename()`d into place; the temporary file is `unlink()`ed on failure. A half-written `config.php` cannot exist. |
| **The CSRF token is validated before any input is read** | Correct — the check is the first thing the POST handler does, and every form carries the hidden token. |
| **The wizard keeps its state in the session, not in a hidden field** | Correct — a hand-crafted hidden field cannot reach a step the session has not reached. (This issue does not change that; it only changes **what** the session holds.) |
| **The raw driver error never reaches the page** | Correct — the exception is classified into a plain-language sentence, and the raw text goes to the error log. |
| **The password is not trimmed** | Correct — `installRawPost()` exists precisely so that a password may begin or end with a space. That behaviour must survive the fix. |
| **The installer renders its own document** | Correct — no `includes/header.php`, no `includes/footer.php`, no application navigation, no footer link farm. |
| **The wizard loads `vendor/autoload.php` only when it exists** | Correct — a machine without the vendor tree can still finish the wizard and configure the database. |
