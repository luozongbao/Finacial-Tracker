# Issue-025 — Security: the installer keeps the credentials in the session store

> **Status:** **implemented and verified** — found by the audit of 2026-10-28,
> measured against the running application; implemented on 2026-10-30.
> See [§14](#14-the-implementation-as-built) for what was built and what was
> measured.
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
14. [The implementation, as built](#14-the-implementation-as-built)

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

- [x] `$_SESSION['install']` **never contains** `db_pass` or `smtp_pass`, at
      any step, in any outcome — including the success path.
      *The state is written by **one** function, `installSaveState()`, and
      that function drops both passwords before the write. The state is also
      stripped **on the read**, so a session written by an earlier version of
      the wizard is cleaned the next time it writes its state.
      §B2 of `database/install_test.php` (17 assertions) proves it
      structurally; the LIVE tier proves it on the disk.*
- [x] The wizard still completes a full install: step 1 tests the database,
      step 2 tests (or skips) the mail server, step 3 writes `config.php` with
      the **correct** password and the correct `ALLOW_REGISTRATION` boolean.
      *§C of `database/install_test.php` drives the real wizard through all
      three steps against a scratch database and reads the `config.php` it
      wrote.*
- [x] The `config.php` that is written contains the password **exactly** as it
      was typed — not trimmed, not re-encoded (the current `installRawPost()`
      behaviour must be preserved for the value that is written, even if the
      value is no longer stored in the session).
      *The probe password is `' Pr0be Secret '` — a leading **and** a
      trailing space — and the written file holds that value verbatim while
      the trimmed form is absent.*
- [x] The session id is regenerated at the start of the wizard and after every
      step boundary; a request carrying an unknown `PHPSESSID` does **not**
      cause a session file with that name to be created.
      *Measured on the running server: the id before the step boundary and
      the id after it differ, and a forged id leaves no file behind.*
- [x] `session.use_strict_mode` is `1` for the wizard's session.
      *Set in `startSession()` **before** `session_start()` — PHP reads it
      when it opens the session, not afterwards.*
- [x] The session file is **not** written to the shared `/tmp` (or, if the host
      has no alternative, the directory is at least not world-readable).
      *The store is `storage/sessions/` under the application root, mode
      `0700`; the directory is created by the application and a **symlink** at
      that path is refused.*
- [x] The wizard still renders the same three steps, the same validation
      messages and the same success page — **no** regression to the operator's
      experience.
      *The wizard renders its own document, exactly as before. The one
      addition is the second password prompt on step 3 — the wizard has to
      ask for the secret it no longer keeps.*
- [x] The wizard still refuses to run once `config.php` exists, with a 403.
      *Asserted by `database/install_test.php`.*
- [x] The wizard's own document still sends the security headers of
      [issue-024](issue-024.md).
      *`sendSecurityHeaders()` is called by both entry points; the wizard's
      copy of the header set is unchanged.*
- [x] `database/install_test.php` asserts the structural rule: **no** code path
      writes a password into `$_SESSION`.
      *§B2 — 17 assertions over the source of `install.php`.*
- [x] A LIVE test asserts that after a completed step 1, **no** file under the
      session save path contains the submitted password.
      *§L of `database/live/live_security_test.php` — 17 assertions, over real
      HTTP, against the running server.*

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

---

## 14. The implementation, as built

Implemented on 2026-10-30, in one change: the wizard, the session layer, the
configuration contract, the two regression tiers and the documentation land
together — because the criterion that holds the whole thing together (the
password is nowhere on the disk) cannot be checked without the test harness
that can drive the wizard.

### 14.1 What changed

| File | Change |
|---|---|
| `install.php` | The passwords are read **raw** and used inside the request that collects them, and they are **never** written to the session. Step 3 asks for them again. The session id is rotated at the start of the wizard and after every step boundary. |
| `includes/functions.php` | Two new functions: `sessionSavePath()` — the private session store — and `sessionPrivateDirectory()` — the directory, created with mode `0700`, a symlink refused. `startSession()` sets `session.use_strict_mode = 1` and points the save path at the private directory. |
| `config.template.php` | The new `SESSION_SAVE_PATH` constant — the 15th constant of the contract. |
| `config.php` | The same constant, written by the installer and by hand in the dev stack. |
| `.htaccess` | The deny rule for `^/?storage/` — the name of a session file **is** the session id, so a directory listing hands out the live sessions. |
| `.gitignore` | `storage/` — the session files are runtime artefacts, never committed. |
| `database/install_sandbox.php` | **New** — the shared harness of the install tests: a copy of the wizard in its own directory, a cookie jar, a CSRF reader and a session-file scanner. |
| `database/install_test.php` | §B2 (the structural rule) and §C (the behaviour) — the SOURCE tier. |
| `database/live/live_security_test.php` | §L — the LIVE tier: the running application and a copy of the wizard under the running server. |
| `database/webroot_hardening_test.php` | `/storage/` and `/database/install_sandbox.php` pinned in the denied list. |
| `AGENTS.md`, `docs/About.md`, `docs/issues/README.md` | The status, the operator notes and the index. |

### 14.2 The rule, in one place

The whole fix rests on **one** function. `installSaveState()` is the only
place that writes the install state, so it is the only place that has to know
the state holds no secret:

```php
function installSaveState(array $state): void
{
    // THE CHOKEPOINT.
    //
    // This is the only place that writes the install state, so this is the
    // only place that has to know the state holds no secret. The passwords
    // are dropped here whatever the caller put into the array: a code path
    // that tries to keep one fails HERE instead of writing it to the disk.
    if (isset($state['db']) && is_array($state['db'])) {
        unset($state['db']['db_pass']);
    }

    if (isset($state['mail']) && is_array($state['mail'])) {
        unset($state['mail']['smtp_pass']);
    }

    $_SESSION['install'] = $state;
}
```

The state is stripped **twice**, and the second strip matters: `installState()`
drops the two passwords on the **read** as well, so a session file that was
written by an earlier version of the wizard — before the fix — is cleaned the
next time the wizard writes its state. A deployment that upgrades in place
does not carry the old secret forward.

### 14.3 The wizard asks for the secret again

The wizard needs the database password twice: to **test** the connection at
step 1 and to **write** `config.php` at step 3. The fix chose the
**re-ask** design of §7.1 — the wizard holds no secret at all, not even for
one request:

- Step 1 reads the password with `installRawPost()` — **untrimmed**, so a
  password may begin or end with a space — and uses it inside that request to
  prove the database. It stores `db_pass_set = true` and **not** the password.
- Step 2 does the same for the SMTP password: `smtp_pass_set = true`, no
  password.
- Step 3 asks for **both** passwords again, in empty fields, and proves them
  before it writes anything. A password that was given at an earlier step and
  is missing now is a **message**, not a silent empty string:

  > Enter the database password again — the wizard does not keep it.

The written `config.php` holds the password **exactly** as it was typed. The
regression test proves it with a password that has a leading **and** a
trailing space (`' Pr0be Secret '`): the written file holds the value
verbatim, and the trimmed form is absent from it.

### 14.4 The session store

`sessionSavePath()` resolves the store once per request, from four candidates
in order:

| # | Candidate | Why |
|---|---|---|
| 1 | `SESSION_SAVE_PATH` from `config.php` | The operator's choice. A **constant**, not an environment variable: `config.php` is the only configuration source of this application. |
| 2 | `<project root>/storage/sessions` | The default. The application creates its own storage directory the first time it runs. |
| 3 | The `php.ini` default | Only when the host has one, and only when it is **private** — `/var/lib/php/sessions` on a stock Debian is `0700`; the shared `/tmp` is not. |
| 4 | `<system temp>/ft-sessions-<uid>` | The last resort before the raw ini value: the directory is `0700` and carries the uid, so another user on the same box cannot read it. |

`sessionPrivateDirectory()` is what makes a candidate acceptable:

- A **symlink** at the path, or at its parent, is refused. The session files
  would be written wherever the symlink points, and that is a place the owner
  of the symlink chose.
- A missing directory is created with mode `0700` — the parent too, when the
  parent is missing as well.
- A directory that is readable by anybody else is refused: the mode is
  corrected first, and the directory is only accepted when the correction
  worked.

`startSession()` sets `session.use_strict_mode = 1` **before** `session_start()`
— PHP reads the setting when it opens the session, not afterwards — and points
`session.save_path` at the resolved directory.

### 14.5 The session id

`installBeginSession()` rotates the id **once**, on the first GET of the
wizard. It never rotates on a POST: a POST has to find the session it was
sent with, because the CSRF token lives in that session and a rotation
before the CSRF check would throw the token away.

After **every** step boundary — step 1, step 2 and step 3 — the wizard calls
`installRotateSessionId()`, which is `session_regenerate_id(true)`: the old id
is destroyed, a new one is issued, and the step state moves with it.

With `session.use_strict_mode = 1` a client-supplied id that has **no** session
file behind it is not adopted at all, and no file is created under that name.

### 14.6 The regression test, as written

The SOURCE tier grew two sections.

**§B2 — the structural rule (17 assertions).** The rule is asserted over the
**source** of `install.php`, not over its behaviour, because a behaviour test
can only prove the paths it happens to walk. The section asserts that:

- no line that mentions `db_pass` or `smtp_pass` touches `$_SESSION`;
- `$_SESSION['install'] = $state;` appears in **exactly one** place;
- that place is `installSaveState()`, and it drops both passwords;
- `installState()` drops both passwords on the read;
- the password is still read **raw**;
- step 3 asks for both passwords again, and the fields are **empty**;
- `session_regenerate_id(true)` is present, and the rotation is called after
  every step boundary;
- `ini_set('session.use_strict_mode', '1')` is present **before**
  `session_start()`;
- the private session directory and the `0700` mode are in
  `includes/functions.php`, and the `SESSION_SAVE_PATH` constant is in the
  wizard.

**§C — the behaviour.** The real wizard is driven through all three steps in a
sandbox against a scratch database, and the test asserts that the session
store of the copy is **its own** directory, that no file of that store holds
the password, that no file of the shared `/tmp` holds it either, that the id
is regenerated after step 1 and after step 2, that an unknown `PHPSESSID` is
not adopted and leaves no file behind, that step 3 refuses an install without
the password and writes no `config.php`, and that the `config.php` it writes
holds the password verbatim.

### 14.7 The LIVE assertion

§L of `database/live/live_security_test.php` proves the same rules over real
HTTP, against the running server — **17 assertions**:

- **L1 — the running application.** `SESSION_SAVE_PATH` is declared and points
  at the application's own directory; the session file of a signed-in visitor
  is in that directory and **not** in the shared `/tmp`; no file of the store
  holds the password.
- **L2 — a copy of the wizard under the running server.** The copy is placed
  in the document root, so the real LiteSpeed process answers it. The probe
  database user is created with a random password; step 1 is driven to
  completion; the copy's own session directory is asserted to exist; no file
  of it holds the password; and the id is regenerated after the step
  boundary.
- **L3 — the fixation.** A request with an unknown `PHPSESSID` is not
  adopted and leaves no file behind.

### 14.8 The one thing the audit did not say

**The test itself can recreate the defect it tests.**

The first run of the LIVE section failed on exactly one assertion — *"the
session store of the wizard is its own directory"* — and the reason is worth
writing down, because it is a trap that will bite the next person who writes
a test against a server that runs as another user:

- The regression test runs as **root** (`docker compose exec` is root), while
  the web server runs as the **application user**.
- The copy of the wizard was created by the test, owned by root, mode `0755`.
- The application creates its own session directory **inside** the copy. It
  could not: the directory belonged to root, and the application user had no
  right to write into it.
- `sessionPrivateDirectory()` therefore refused every candidate, the
  application fell back to the ini value, and the session landed in the shared
  `/tmp` — **the very defect issue-025 is about**.

Two things came out of it:

1. The harness now gives the copy to the **owner of the application tree**,
   so the web server can write into it exactly as it can write into the real
   tree.
2. The assertion was rewritten to look at **where the session file actually
   landed** rather than at the path the copy was expected to use — so a
   fallback to the shared `/tmp` fails the test instead of passing it.

The rule that came out of it, for every test in this repository: **a test that
runs as root must not create a restrictive directory that the web server has
to write into.**

### 14.9 The measured result

```
$ ls -ld storage storage/sessions
drwx------ 2 zongbao zongbao 4096 storage
drwx------ 2 zongbao zongbao 4096 storage/sessions

$ ls -l storage/sessions | head -3
-rw------- 1 zongbao zongbao  83 Oct 30 01:40 sess_098f53bfed6df6804e91d61b08542
-rw------- 1 zongbao zongbao 188 Oct 30 01:39 sess_104b0765c9b1f5f648974b2b156cfd03
-rw------- 1 zongbao zongbao  31 Oct 30 01:39 sess_273e577a15ba1588b7b49881213a6153

$ curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/storage/
403
```

```
== L. The installer keeps no secret on the disk ==
  [PASS] the application declares a session directory
  [PASS] the session directory is the application's own directory
  [PASS] the signed-in session has an id
  [PASS] the session file is in the application's own directory
  [PASS] the session file is not in the shared temporary directory
  [PASS] no file of the session store holds the password
  [PASS] no session file in the shared temporary directory holds the password
  [PASS] the wizard answers under the running server
  [PASS] the wizard carries a CSRF token
  [PASS] the probe database user was created
  [PASS] step 1 of the wizard succeeds
  [PASS] the session store of the wizard is its own directory
  [PASS] no file of the wizard's session store holds the password
  [PASS] no session file in the shared temporary directory holds the password
  [PASS] the session id is regenerated after the step boundary
  [PASS] an unknown session id is not adopted
  [PASS] no file was created for the unknown session id

  Failed: 0
```

### 14.10 The operator's note

The session store is now a directory under the application root. On a native
installation the operator has to make sure the web-server user may write
there — the application creates it, but it cannot create it in a root-owned
tree. The directory is created with mode `0700`, so on a shared host only the
web-server account can read the sessions.

The session files are **not** a backup target and **not** a deployment
artefact: they are runtime state. The application recreates them on the first
request, and every visitor gets a new session.
