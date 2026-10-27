# Issue-016 — App: authentication and the mailer

> **Status:** **specified, not implemented** — `includes/auth.php` and the five auth
> screens do not exist yet.
> **Target:** v0.2 (the application) — the screens that stand between a visitor and
> the data.
> **Severity:** **critical** — this issue carries every authentication control in
> the product, and it is where a mistake leaks other people's money records.
>
> **Requested by the user:**
> > "I guess, we now have everything, ready for dev, write issue files for dev to
> > implement the project."
>
> **Depends on:** [issue-011.md](issue-011.md) (the `users`, `password_resets` and
> `email_verifications` tables), [issue-012.md](issue-012.md) (`startSession()`, the
> CSRF helpers, `e()`), [issue-013.md](issue-013.md) (the auth shell),
> [issue-014.md](issue-014.md) (the routes), [issue-015.md](issue-015.md) (the
> `SMTP_*` constants).
> **Related:** [issue-010.md](issue-010.md) (the mailer contract),
> `includes/mailer.php` (**the existing, tested mailer**),
> `docs/mockup/01-login.html`, `02-register.html`, `03-register-closed.html`,
> `04-forgot-password.html` (**the screens being built**);
> `.github/instructions/php-app.instructions.md` (**the binding conventions**).

---

## Table of contents

1. Objective
2. Constraints
3. The routes
4. The auth shell
5. Specification — sign in
6. Specification — the attempt limit
7. Specification — create an account
8. Specification — registration closed
9. Specification — forgot password
10. Specification — reset password
11. Specification — the mailer
12. Specification — sign out
13. Acceptance criteria
14. Verification
15. Rollback
16. Risks
17. Out of scope

---

## 1. Objective

Build the five **auth screens** and the session handling behind them.

Success criteria:

| # | Criterion |
|---|---|
| 1 | Sign in, register, registration-closed, forgot-password and reset-password |
| 2 | Passwords are **hashed**, never stored, never logged, never echoed |
| 3 | A failed sign-in says **"That username and password do not match."** — never which half was wrong |
| 4 | The attempt limit is enforced **server-side** |
| 5 | Registration is closed on **both** sides when `ALLOW_REGISTRATION === false` |
| 6 | The forgot-password confirmation is **word-for-word identical** for a known and an unknown address |
| 7 | A reset token is **single-use** and expires after **one hour** |
| 8 | The **raw token is never stored** — only its SHA-256 hash |
| 9 | A mail failure is a **`false`**, not a 500 |
| 10 | The SMTP host, port and error **never** reach the page |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| **Never** reveal whether an account exists | Three separate screens are affected: login, register, forgot-password. |
| The client-side validation is **advisory** | The server owns every rule. The client check is a convenience. |
| The password is never written to the log | `error_log()` must not receive a password, a token, or a raw SMTP error. |
| `session_regenerate_id(true)` on privilege change | Sign-in and password change both regenerate the session id. |
| `includes/mailer.php` **never throws** | `sendMail()` returns `true` or `false`. A caller must never wrap it in a try/catch that expects an exception. |
| `mailIsConfigured()` reads **only** `config.php` | There is no `getenv()`. |
| `display_errors = Off` | A PHP error on these pages is a **blank page**, not a warning. |
| The pages are reachable **without** a session cookie | A first-time visitor must be able to sign in. |
| The `users.status` column is `ENUM('active','disabled')` | A disabled account is refused at sign-in. |

---

## 3. The routes

| Method | Path | Auth | Behaviour |
|---|---|---|---|
| `GET` | `/login` | none | The sign-in form |
| `POST` | `/login` | none | Validate the credentials, start the session |
| `GET` | `/register` | none | The registration form — **403** when closed |
| `POST` | `/register` | none | Create the account — **403** when closed |
| `GET` | `/forgot-password` | none | The reset-request form |
| `POST` | `/forgot-password` | none | Send the reset link |
| `GET` | `/reset-password?token=…` | none | The new-password form |
| `POST` | `/reset-password` | none | Set the new password |
| `GET` | `/verify-email?token=…` | none | Redeem the registration confirmation |
| `POST` | `/logout` | **yes** | End the session |

| Rule | Reason |
|---|---|
| A signed-in visitor hitting `/login` is **redirected to the dashboard** | There is no reason to show the form again. |
| A signed-in visitor hitting `/register` is **redirected to the dashboard** | Same. |
| `/logout` is **POST-only** | A GET logout can be triggered by any image tag on any page. |
| Every POST validates the CSRF token **before** reading any input | The house rule. |
| Every redirect target is a **known route** | Never a user-supplied URL. |

---

## 4. The auth shell

All five screens share one shell. It is **not** the application shell — no nav, no
app bar, no footer link farm.

````html
<main class="auth">
  <header class="auth__brand">
    <h1 class="auth__brand-name">Financial Tracker</h1>
    <p class="auth__brand-note">Record income and spending, then sum it over any period.</p>
  </header>

  <section class="card">
    <h2>…</h2>
    …
  </section>

  <footer class="auth__footer">
    <p>Personal finance tracker · your data stays on your server.</p>
  </footer>
</main>
````

| Rule | Reason |
|---|---|
| The `<h1>` is the product name; the card's `<h2>` is the screen title | One `h1` per page, and the screen title is the second level. |
| The brand note is **fixed copy** | It is not a user value, but it is escaped like one. |
| The footer is **fixed copy** | "Personal finance tracker · your data stays on your server." |
| The card holds exactly **one** form | The screens are single-purpose. |
| The `auth__links` block sits **below** the button | "Forgot password?", "Create an account", "I already have an account", "Back to sign in". |
| The error alert sits **inside** the card, above the fields | It is where the visitor's eye already is. |
| The error alert is wrapped in `<div role="status" aria-live="polite">` | The error is announced. |
| The field error is a `<span class="field__error">` **inside** `.field` | The mockup's exact structure. |
| The page stylesheet is `assets/css/pages/auth.css` | One file for the five screens. |

### The link that is conditional

On the sign-in screen:

````php
<?php if (registrationAllowed()): ?>
    <a href="/register">Create an account</a>
<?php endif; ?>
````

> **The link is rendered only when `ALLOW_REGISTRATION === true`.** When
> registration is closed the link is **absent** — not greyed out, not disabled,
> absent. The visitor is not told that a register route exists.

---

## 5. Specification — sign in

### The form

| Field | Name | Type | Attributes |
|---|---|---|---|
| Username or email | `username` | `text` | `autocomplete="username"`, `aria-describedby` → the hint |
| Password | `password` | `password` | `autocomplete="current-password"` |

The hint under the username field is fixed copy: *"Either your username or your
email address."*

The submit button is labelled **"Sign in"** and is `btn btn--primary btn--block`.

### The server

````php
$identifier = trim((string) ($_POST['username'] ?? ''));
$password   = (string) ($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    // the field error: "Enter your password." (and "Enter your username or email.")
}

$user = findUserByIdentifier($identifier);   // username OR email, one query

if ($user === null || !verifyPassword($password, $user['password_hash'])) {
    // ONE message for both halves:
    // "That username and password do not match."
}
````

| Rule | Reason |
|---|---|
| The lookup accepts **username or email** | The label promises it. One query with `WHERE username = :id OR email = :id`. |
| A missing user and a wrong password take the **same branch** | Two different messages would confirm whether an account exists. |
| The password is verified with **`password_verify()`** | Never `==`, never a manual hash comparison. |
| The failure message is **"That username and password do not match."** | The mockup's exact string. |
| The username field is **not** cleared; the password field **is** | The mockup's security note. |
| The account's `status` is checked **after** the password | Otherwise a disabled account's status would be an oracle. |
| A disabled account says **"This account is not available."** | Vague on purpose — it does not reveal *why*, and it offers no contact address. |
| An unexpected error says **"Something went wrong on our side. Please try again."** | Never a stack trace, never a PHP message, never the SQL error. |
| The error is logged with `error_log()` | The LiteSpeed error log holds the detail. |

### On success

````php
session_regenerate_id(true);              // privilege change
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];
redirect('/dashboard');
````

| Rule | Reason |
|---|---|
| `session_regenerate_id(true)` | The old session id must not survive the privilege change. |
| The session stores the **user id** | Everything else is read from the database, so a changed username is picked up. |
| `$_SESSION` is **not** trusted for authorization | The user id in the session selects the row; every query filters on it. |
| The password hash is **not** in the session | It has no business there. |

### The password rules

| Rule | Value | Where it comes from |
|---|---|---|
| Minimum length | **10 characters** | The mockup's hint: *"At least 10 characters."* |
| Maximum length | 200 characters | A hash of a 10 MB password is a denial-of-service vector. |
| The two entries must match | — | The mockup: *"The two passwords do not match."* |
| Trimming | Passwords are **not** trimmed | Leading and trailing spaces are legitimate characters. |
| Hashing | `password_hash($password, PASSWORD_DEFAULT)` | The default algorithm changes with PHP; the hash carries its own parameters. |
| Verification | `password_verify($password, $hash)` | |
| Rehash | `password_needs_rehash()` → re-hash on sign-in | The algorithm improves; the stored hash does not have to. |
| Storage | `password_hash` is `VARCHAR(255)` | A bcrypt hash is 60 characters; argon2id is longer but still under 255. |

> The 10-character minimum is the **product's** rule, not PHP's. It is enforced by
> the server with `strlen()`, and the client-side check is advisory — the
> server's rejection is authoritative and is rendered in the same place.

---

## 6. Specification — the attempt limit

The limit is **server-side**. The client never counts attempts.

| Property | Value | Why |
|---|---|---|
| The threshold | **5** failed attempts | |
| The window | **15 minutes** | The mockup: *"Too many attempts. Try again in 15 minutes."* |
| Where the count lives | `users.failed_attempts` and `users.locked_until` | [issue-011.md](issue-011.md) §`users` — option A, chosen there. |
| The message | **"Too many attempts. Try again in 15 minutes."** | The mockup's exact string. |
| The alert kind | `alert--warning` | It is a wait, not a rejection. |
| The button | **disabled** while the wait is in force | The mockup's disabled state. |

### The algorithm

````php
function isLockedOut(array $user): bool
{
    if ($user['locked_until'] === null) {
        return false;
    }
    return strtotime($user['locked_until']) > time();
}
````

| Rule | Reason |
|---|---|
| The check happens **before** the password is verified | It is cheaper, and it makes the throttle effective. |
| A successful sign-in **resets** `failed_attempts` to 0 and clears `locked_until` | |
| A failed attempt **increments** the counter | |
| The counter resets when the lock **expires** | A lock that never lifts is a denial-of-service on the owner. |
| The message publishes the **wait**, not the count | "Try again in 15 minutes" tells the visitor what to do. |

> **A subtlety.** The lock is checked *before* the password, but the **message** for a
> locked account must be the same regardless of whether the identifier matches a
> real user. If a visitor types a username that does not exist 5 times, no row
> exists to lock — so the throttle for an unknown identifier must be kept
> **somewhere else**, or the throttle is trivially bypassed by using a non-existent
> name. The simplest correct answer: **count the failures for the identifier, not
> for the account** — store the counter against the identifier when the account
> does not exist. [issue-011.md](issue-011.md) offers `login_attempts` (option B)
> for exactly this reason. **Pick one mechanism and apply it to both cases.**

### The disabled button

The mockup disables the submit button while the wait is in force. The button is
disabled **client-side for presentation**, but the server-side check is what
actually enforces it. A visitor who re-enables the button in the browser still
gets the warning.

---

## 7. Specification — create an account

### The form

| Field | Name | Type | Hint |
|---|---|---|---|
| Username | `username` | `text` | *3–32 characters. Letters, digits, underscore and hyphen.* |
| Email | `email` | `email` | *Used for account verification and the password reset link. It must be unique.* |
| Confirm email | `email_confirmation` | `email` | *Type the same address again.* |
| Password | `password` | `password` | *At least 10 characters.* |
| Confirm password | `password_confirmation` | `password` | — |

The submit button is labelled **"Create account"**.

### The server rules

| Rule | Error message |
|---|---|
| The username is 3–32 characters | *"The username must be at least 3 characters long."* |
| The username matches `/^[A-Za-z0-9_-]+$/` | *"The username may only contain letters, digits, underscore and hyphen."* |
| The email is a valid address | *"Enter a valid email address."* |
| The two emails match | *"The two email addresses do not match."* |
| The password is at least 10 characters | *"The password must be at least 10 characters long."* |
| The two passwords match | *"The two passwords do not match."* |
| The username is not taken | *"That username is already taken."* |
| The email is not taken | *"That email address is already registered."* |

> The username-taken message says **nothing about the email**. Confirming whether
> the email exists would be an enumeration oracle. The check order is fixed:
> **validate the format first, then the uniqueness** — otherwise an invalid
> username reports "already taken", which is nonsense.

### The uniqueness check

| Rule | Reason |
|---|---|
| The uniqueness check is a **`SELECT`** before the insert | |
| The `INSERT` relies on the **`UNIQUE` constraint** as the real guard | A `SELECT` has a race: two simultaneous registrations both pass it. The constraint is the guard; the `SELECT` is the friendly message. |
| The duplicate-key error is caught and mapped to the friendly message | The constraint violation is a `PDOException` with SQLSTATE `23000`. |
| The **email** is compared case-insensitively | The column is `utf8mb4` with a `_ci` collation, so `Demo@Example.com` and `demo@example.com` are the same address. |
| The username is compared **case-insensitively** too | Otherwise `Demo` and `demo` are two accounts, and the "already taken" message would be a lie. |

### Creating the user

````php
$userId = (int) $db->lastInsertId();   // NOT the return value of execute()
````

> `Database::execute()` returns **bool**. The insert id comes from
> `lastInsertId()` — see [issue-012.md](issue-012.md).

The new row has `email_verified_at = NULL`, `status = 'active'`,
`failed_attempts = 0`, `locked_until = NULL`.

### The confirmation email

After the account is created, the server:

1. Generates a token: `bin2hex(random_bytes(32))` — **64 hex characters**.
2. Stores **`hash('sha256', $token)`** in `email_verifications.token_hash`.
3. Sets `expires_at` to now + 24 hours.
4. Sends the link `/verify-email?token=<raw token>` to the address.
5. **Deletes every other unused verification row** for that user.

> **Only the hash is stored.** A database dump then yields no working
> confirmation link.

### The success state

The mockup replaces the form with a confirmation:

````html
<div role="status" aria-live="polite">
  <div class="alert alert--success">
    <div class="alert__body">
      <p class="alert__title">Your account is ready.</p>
      <p>We sent a confirmation message to <strong>demo@example.com</strong>. Check your inbox to confirm the address, then sign in and record your first transaction.</p>
    </div>
  </div>
</div>

<a class="btn btn--primary btn--block" href="/login">Go to sign in</a>
````

| Rule | Reason |
|---|---|
| The form is **replaced**, not shown above the message | The mockup's note. |
| The address in the message is escaped with `e()` | It is user-supplied data. |
| The link says **"Go to sign in"** | The application does **not** sign the new user in — the copy must not promise a session the server does not create. |
| The message says *"We sent a confirmation message"* — **only when it was sent** | |

### When the mail cannot be sent

Two different failures, two different words:

| State | How it is detected | What the page says |
|---|---|---|
| Mail is **not configured** | `mailIsConfigured() === false` | *"Your account is ready, but the confirmation message could not be sent — email is not configured for this installation. Ask the person who runs this installation to confirm your address."* |
| Mail is configured but the **send failed** | `sendMail(...) === false` | *"Your account was created, but the confirmation message could not be sent. Please try again later or ask the person who runs this installation to confirm your address."* |

| Rule | Reason |
|---|---|
| **The account is created either way** | The account is the primary artefact; the mail is a courtesy. |
| The alert is **`alert--warning`**, not `alert--danger` | It is a partial success, not a failure. |
| The failure is **never silent** | The mockup's note: *"instead of failing silently."* |
| The message **never** names the SMTP host, the port, or the transport error | |
| The raw error goes to `error_log()` | `includes/mailer.php` already does this. |

---

## 8. Specification — registration closed

When `ALLOW_REGISTRATION === false`, the register route returns **403** and renders
the closed screen — the page, **not** a status-code dump.

````html
<section class="card">
  <h2>Registration is closed</h2>
  <p>This tracker is currently in private use, so new accounts cannot be created.</p>
  <p>If you already have an account, sign in as usual. If you do not, ask the person who runs this installation for an account.</p>
  <a class="btn btn--primary btn--block" href="/login">Sign in</a>
</section>
````

### The two-sided gate

> **A check in only one of the two places is a bug.**

| Side | The check |
|---|---|
| **Front end** — `GET /register` | The route returns **403** and renders the closed screen. No form is rendered. |
| **Back end** — `POST /register` | The handler rejects the request **independently**, with 403, before reading any input. |
| **Elsewhere** | The "Create an account" link is **absent** from the sign-in screen. |

````php
function registrationAllowed(): bool
{
    return ALLOW_REGISTRATION === true;
}
````

| Rule | Reason |
|---|---|
| The comparison is **`=== true`** | The string `'false'` would make `=== true` false — but so would a string `'true'`. The real guard is the value being a boolean, which `database/config_test.php` enforces. |
| The **back-end** check comes **first**, before the CSRF token and before any input is read | A rejected request does no work. |
| The closed screen contains **no** form, no hidden fields and no CSRF token | |

---

## 9. Specification — forgot password

### The form

| Field | Name | Type |
|---|---|---|
| Email | `email` | `email`, `autocomplete="email"`, `inputmode="email"` |

The button is labelled **"Send reset link"**. The screen title is **"Reset your
password"**.

### The server

````php
if (!mailIsConfigured()) {
    // warning alert — see below
}
$user = findUserByEmail($email);       // may be null
if ($user !== null) {
    $token = bin2hex(random_bytes(32));
    insertPasswordReset($user['id'], hash('sha256', $token));
    sendMail($user['email'], 'Reset your password', $body);
}
// THE SAME RESPONSE EITHER WAY
````

### The enumeration rule

> **The two confirmations are word-for-word identical.** The server does not vary
> the response with the address, the timing, or the HTTP status. Any difference
> between them is an account-enumeration leak.

The confirmation is exactly:

````html
<div role="status" aria-live="polite">
  <div class="alert alert--success">
    <div class="alert__body">
      <p class="alert__title">If that address has an account, a reset link is on its way.</p>
    </div>
  </div>
</div>

<p class="field__hint">The link is valid for one hour and can be used once.</p>

<a class="btn btn--primary btn--block" href="/login">Back to sign in</a>
````

| Rule | Reason |
|---|---|
| The **same** status code (200) for a known and an unknown address | |
| The **same** body for a known and an unknown address | |
| The **same** response **time** | A timing difference is the same leak with a smaller margin. |
| No row is created for an unknown address | |
| The address is **not** echoed back | Echoing it proves nothing, but it does add a fingerprint. The mockup does not echo it. |
| The form is **replaced** by the confirmation | |

### The two failure states

| State | Alert | Copy |
|---|---|---|
| **Mail is not configured** | `alert--warning` | *"Email is not configured for this installation, so reset links cannot be sent."* Plus: *"The person who runs this installation can set a new password for you directly."* and a **secondary** "Back to sign in" button. |
| **The send failed** | `alert--danger` | *"The reset link could not be sent. Please try again."* The form stays, with the address retained, and the button becomes **"Try again"**. |

| Rule | Reason |
|---|---|
| The not-configured state is checked **before** the lookup | The condition is a property of the installation, not of the visitor. |
| The send failure is detected by **`sendMail() === false`** | Not by an exception — `sendMail()` never throws. |
| The raw SMTP error, the host and the port **never** reach the page | The mockup's security note. |
| The **send-failed** state is only shown when the address **is** known | Otherwise an unknown address would get a *different* response — the enumeration leak. |

> **This is the one place where the two failure states need care.** The
> "mail is not configured" warning is installation-wide and safe to show to
> anyone. The "the send failed" danger must **only** be shown when a real account
> matched — otherwise the page becomes an oracle. The **not-configured** state is
> rendered *instead of* the confirmation for every address, known or unknown.

### The reset token

| Property | Value |
|---|---|
| The token | `bin2hex(random_bytes(32))` — 64 hex characters |
| What is stored | `hash('sha256', $token)` — **never the token** |
| The link | `/reset-password?token=<raw token>` |
| The lifetime | **1 hour** — `expires_at` |
| The use count | **once** — `used_at` is set on redemption |
| Old rows | Every unused row for the user is deleted when a new one is issued |

---

## 10. Specification — reset password

> **There is no mockup for this screen.** It is built from the auth shell and the
> same field vocabulary as the registration form. It must look like the other four
> auth screens, and it must obey the same rules.

### The form

| Field | Name | Type | Notes |
|---|---|---|---|
| New password | `password` | `password` | `autocomplete="new-password"`, hint *"At least 10 characters."* |
| Confirm password | `password_confirmation` | `password` | `autocomplete="new-password"` |

A **"Show"** reveal button sits under the password field — the same
`data-reveal-target` / `aria-pressed="false"` pattern as the other screens.

The screen title is **"Choose a new password"**.

### The server

````php
$token  = (string) ($_POST['token'] ?? '');
$row    = findUnusedPasswordReset(hash('sha256', $token));

if ($row === null || strtotime($row['expires_at']) <= time()) {
    // "This reset link is no longer valid. Request a new one."
}
````

| Rule | Reason |
|---|---|
| The token is compared **as a hash** | The database lookup is `WHERE token_hash = :hash AND used_at IS NULL`. |
| The comparison uses `hash_equals()` | A timing-safe comparison. |
| An **expired** token is refused | The expiry is checked in SQL (`expires_at > NOW()`) as well as in PHP. |
| A **used** token is refused | `used_at IS NULL`. |
| The token is **marked used** in the same transaction as the password change | Otherwise two concurrent redemptions both succeed. |
| On success, the new password is written and **every other session is invalidated** | A password change must end the sessions the thief might hold. |
| The user is **signed in** after the reset | The user proved control of the mailbox. |
| The reset link is **single-use** — a second visit shows the "no longer valid" message | |

### The success state

````html
<div role="status" aria-live="polite">
  <div class="alert alert--success">
    <div class="alert__body">
      <p class="alert__title">Your password has been changed.</p>
      <p>You can now sign in with your new password.</p>
    </div>
  </div>
</div>

<a class="btn btn--primary btn--block" href="/login">Go to sign in</a>
````

---

## 11. Specification — the mailer

`includes/mailer.php` **already exists** and is **already tested**
([`database/mailer_test.php`](../../database/mailer_test.php)). This issue wires it
into the application; it does not rewrite it.

### The public surface

| Function | Returns | Notes |
|---|---|---|
| `mailIsConfigured(): bool` | `false` when `SMTP_HOST === ''` | **No connection is attempted.** |
| `createMailer(): PHPMailer` | The configured mailer | Port 465 ⇒ `ENCRYPTION_SMTPS`; otherwise `ENCRYPTION_STARTTLS`. `Timeout = 30`. |
| `sendMail(string $to, string $subject, string $body): bool` | `true` / `false` | **Never throws.** |

### The two states a caller must distinguish

| State | How to detect it | What the visitor sees |
|---|---|---|
| Mail is **not configured at all** | `mailIsConfigured()` is `false` | *"…is not configured for this installation…"* — a warning. |
| Mail is configured but the **send failed** | `sendMail()` returned `false` | *"…could not be sent. Please try again."* — a danger. |

> **A failed mail is a `false`, not an exception.** A public "forgot password" form
> must not become a 500 because the SMTP server was down.

### The rules

| Rule | Reason |
|---|---|
| The mailer reads **only** `config.php` | No `getenv()`, no `$_ENV`, no `$_SERVER`. |
| The application **never** calls `createMailer()` directly | It calls `sendMail()`. The transport is the mailer's business. |
| `sendMail()` is called **after** the database work is committed | A mail that says "your account is ready" must not be sent for a transaction that rolled back. |
| The subject and body are **plain text** | No HTML mail in v0.2 — it is a plain-text link, and plain text cannot carry a phishing lure. |
| The reset / verification link is built from **`$_SERVER['SERVER_NAME']`**, not `HTTP_HOST` | `HTTP_HOST` is user-controllable. A reset link built from it can be pointed at an attacker's host. |
| The link is **HTTPS** in production | |
| The sender is **`MAIL_FROM` / `MAIL_FROM_NAME`** | The mailer sets it; the caller does not pass a from address. |
| The SMTP password is **never** in a log, a page, or an error message | |

### The absolute URL

````php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['SERVER_NAME'] ?? 'localhost';
$url    = $scheme . '://' . $host . '/reset-password?token=' . urlencode($token);
````

> `$_SERVER['HTTP_HOST']` comes straight from the `Host:` header, which the client
> controls. Using it in a password-reset link hands an attacker a working reset
> link for a victim. `SERVER_NAME` is set by the server configuration.

---

## 12. Specification — sign out

| Rule | Reason |
|---|---|
| `POST /logout` only | A GET logout can be triggered by any image tag on any page. |
| The CSRF token is validated | |
| The session is **destroyed** — `session_destroy()` **and** the cookie is cleared | Clearing only the server-side data leaves the cookie in the browser. |
| The user is redirected to `/login` | |
| A signed-out visitor is redirected **away** from every protected route | `requireLogin()` sends them to `/login`. |
| The redirect **preserves the intended destination** | The visitor lands where they were going — but only after signing in. The target must be a **known route**, never an arbitrary URL. |

---

## 13. Acceptance criteria

- [ ] `GET /login` renders the auth shell with the `username` and `password` fields.
- [ ] The sign-in username field is `autocomplete="username"`, the password field
      is `autocomplete="current-password"`.
- [ ] The password field has a **"Show"** reveal button with `data-reveal-target`
      and `aria-pressed="false"`.
- [ ] The submit button reads **"Sign in"**.
- [ ] The failed-credentials alert reads exactly **"That username and password do
      not match."** — it does **not** say which half was wrong.
- [ ] A missing user and a wrong password produce the **same** message, the **same**
      status code and the **same** body.
- [ ] The username field is **not** cleared on failure; the password field **is**.
- [ ] A disabled account reads **"This account is not available."** and reveals no
      reason.
- [ ] An unexpected error reads **"Something went wrong on our side. Please try
      again."** — no stack trace, no PHP message, no SQL error.
- [ ] The sign-in query accepts **username or email** in one statement.
- [ ] The password is verified with **`password_verify()`** and stored with
      **`password_hash()`** using `PASSWORD_DEFAULT`.
- [ ] The password minimum is **10 characters**; the maximum is **200**.
- [ ] The password is **not** trimmed.
- [ ] `password_needs_rehash()` triggers a re-hash on sign-in.
- [ ] The attempt limit is **5** attempts in **15 minutes**, enforced
      **server-side**.
- [ ] The lock message reads **"Too many attempts. Try again in 15 minutes."** and
      the submit button is disabled while the wait is in force.
- [ ] A successful sign-in **resets** `failed_attempts` and clears `locked_until`.
- [ ] The lock **expires** — it does not lock the account out permanently.
- [ ] The throttle applies to an **unknown identifier** as well as a known one.
- [ ] `session_regenerate_id(true)` runs on sign-in and on a password change.
- [ ] The session stores the **user id**, never the password hash.
- [ ] `GET /register` renders the form with the five fields `username`, `email`,
      `email_confirmation`, `password`, `password_confirmation`.
- [ ] The server enforces the **username format** (3–32, `[A-Za-z0-9_-]`) and
      returns *"The username must be at least 3 characters long."* when it is too
      short.
- [ ] The server enforces the **email format** and returns *"Enter a valid email
      address."*
- [ ] The server enforces the **email match** and returns *"The two email addresses
      do not match."*
- [ ] The server enforces the **password length** and returns *"The password must
      be at least 10 characters long."*
- [ ] The server enforces the **password match** and returns *"The two passwords do
      not match."*
- [ ] The uniqueness checks return *"That username is already taken."* and *"That
      email address is already registered."*
- [ ] The username-taken message says **nothing** about the email address.
- [ ] The uniqueness check relies on the **`UNIQUE` constraint** as the real guard,
      with the friendly message mapped from the constraint violation.
- [ ] The insert id is read with **`lastInsertId()`**, not from the return value of
      `execute()`.
- [ ] The username and the email are compared **case-insensitively**.
- [ ] The confirmation token is `bin2hex(random_bytes(32))` and **only its SHA-256
      hash** is stored.
- [ ] The success state replaces the form, says **"Your account is ready."**, and
      offers a **"Go to sign in"** button — it does **not** sign the user in.
- [ ] The address in the success message is escaped with **`e()`**.
- [ ] When mail is not configured, the registration says the account was created
      but the confirmation **could not be sent** — it does not fail silently.
- [ ] When `ALLOW_REGISTRATION === false`, `GET /register` returns **403** and
      renders the closed screen with **no form**.
- [ ] When `ALLOW_REGISTRATION === false`, `POST /register` returns **403**
      independently, before reading any input.
- [ ] The "Create an account" link is **absent** from the sign-in screen when
      registration is closed.
- [ ] The forgot-password form has one field, `email`, and the button reads
      **"Send reset link"**.
- [ ] The known-address and unknown-address responses are **word-for-word
      identical** — same title, same body, same status code.
- [ ] The confirmation reads **"If that address has an account, a reset link is on
      its way."** and *"The link is valid for one hour and can be used once."*
- [ ] The not-configured state reads **"Email is not configured for this
      installation, so reset links cannot be sent."**
- [ ] The send-failed state reads **"The reset link could not be sent. Please try
      again."**
- [ ] The send-failed state is shown **only** when a real account matched.
- [ ] A reset token is valid for **one hour** and **once**.
- [ ] The reset lookup filters on `used_at IS NULL` and `expires_at > NOW()`.
- [ ] The token comparison uses **`hash_equals()`**.
- [ ] A password change invalidates the other sessions and signs the user in.
- [ ] The mailer's two states are distinguished by `mailIsConfigured()` and
      `sendMail() === false` — **not** by an exception.
- [ ] The SMTP host, port and raw transport error **never** reach the page.
- [ ] The reset and verification links are built from **`$_SERVER['SERVER_NAME']`**,
      never `HTTP_HOST`.
- [ ] `POST /logout` destroys the session, clears the cookie and redirects to
      `/login`.
- [ ] Every POST on these screens validates the **CSRF token before reading any
      input**.
- [ ] Every dynamic value on these screens is escaped with **`e()`**.
- [ ] Every changed PHP file passes **`php -l`**.

---

## 14. Verification

```bash
cd /home/zongbao/projects/financial-tracker

# 1 — lint every file this issue touches
docker compose exec -T app php -l /var/www/html/includes/auth.php
docker compose exec -T app php -l /var/www/html/pages/login.php
docker compose exec -T app php -l /var/www/html/pages/register.php
docker compose exec -T app php -l /var/www/html/pages/forgot-password.php
docker compose exec -T app php -l /var/www/html/pages/reset-password.php

# 2 — the screens render
for p in login register forgot-password; do
  printf "%s -> " "$p"
  curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8088/$p"
done

# 3 — the sign-in failure message does not say which half was wrong
curl -s -d "csrf_token=...&username=nosuchuser&password=whatever" \
     http://localhost:8088/login | grep -o 'That username and password do not match.'
# must print exactly one line; there must be no "unknown user" / "password" wording

# 4 — the forgot-password response is identical for a known and an unknown address
KNOWN=$(curl -s -d "csrf_token=...&email=demo@example.com" http://localhost:8088/forgot-password | md5sum)
UNKNOWN=$(curl -s -d "csrf_token=...&email=nobody@example.com" http://localhost:8088/forgot-password | md5sum)
[ "$KNOWN" = "$UNKNOWN" ] && echo "identical: ok" || echo "!! ENUMERATION LEAK"

# 5 — registration closed is a two-sided gate
#     (set ALLOW_REGISTRATION = false in config.php first)
curl -s -o /dev/null -w "GET  /register -> %{http_code}\n" http://localhost:8088/register     # 403
curl -s -o /dev/null -w "POST /register -> %{http_code}\n" \
     -d "csrf_token=...&username=x&email=x@example.com&password=aaaaaaaaaa&password_confirmation=aaaaaaaaaa" \
     http://localhost:8088/register                                                                      # 403
curl -s http://localhost:8088/login | grep -c 'Create an account'                                   # must be 0

# 6 — the password is never stored in the clear
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "SELECT id, username, LEFT(password_hash, 7) AS alg FROM users;"
# alg is $2y$ (bcrypt) or $argon2 — never a raw password

# 7 — the reset token is stored hashed
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "SELECT LENGTH(token_hash) AS len, used_at IS NULL AS unused FROM password_resets;"
# len = 64

# 8 — no SMTP detail leaks
curl -s -d "csrf_token=...&email=demo@example.com" http://localhost:8088/forgot-password \
  | grep -iE 'smtp|mail transport|535|authentication failed' \
  && echo "!! SMTP DETAIL LEAKED" || echo "no-smtp-detail: ok"

# 9 — the whole suite
.github/skills/dev-stack-ops/scripts/run-tests.sh
echo "suite_exit=$?"
```

Expected: step 3 prints the message once; step 4 prints `identical: ok`; step 5
prints **403**, **403** and **0**; step 6 shows a bcrypt/argon2 prefix; step 7 shows
`64`; step 8 prints `no-smtp-detail: ok`; step 9 reports **0 FAIL**.

> Steps 3–8 need a live CSRF token. Fetch one from the form and post it:
>
> ```bash
> TOKEN=$(curl -s -c /tmp/ck.txt http://localhost:8088/login \
>         | grep -oP 'name="csrf_token" value="\K[a-f0-9]+' | head -1)
> ```

---

## 15. Rollback

The auth screens are new files. Removing them removes the feature and nothing else:

````bash
rm -f pages/login.php pages/register.php pages/forgot-password.php \
      pages/reset-password.php pages/verify-email.php
````

The schema is unchanged — `users`, `password_resets` and `email_verifications`
are required by [issue-011.md](issue-011.md). Rolling those back means dropping
the tables, which **deletes every account**:

````bash
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" "$DB_NAME" \
    -e "DROP TABLE IF EXISTS password_resets, email_verifications;"
````

> Never `docker compose down -v` — it deletes the whole MariaDB data volume.

A password change is not rolled back. There is no password history.

---

## 16. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The login message reveals which half was wrong | Medium | §5 — one branch, one message; step 3 of the verification checks it. |
| The forgot-password response differs for a known address | Medium | §9 — identical wording, status and timing; step 4 compares the two bodies by hash. |
| The send-failed state becomes an enumeration oracle | Medium | §9 — the danger alert is only rendered when a real account matched. |
| The raw token is stored instead of its hash | Medium | §9 — only `hash('sha256', $token)` is written; step 7 checks the column length. |
| A reset token works twice | Medium | §10 — `used_at IS NULL` in the lookup, marked in the same transaction as the password change. |
| `ALLOW_REGISTRATION` is checked on only one side | Medium | §8 — the two-sided table; step 5 checks the GET and the POST separately. |
| The throttle is bypassed with a non-existent username | Medium | §6 — the mechanism must apply to the identifier, not only to the account row. |
| A password containing a `'` breaks a hand-built SQL string | Low | The hash is written through a **prepared statement**, never concatenated. |
| The SMTP host or the transport error reaches the page | Low | §11 — `sendMail()` returns a bool and the caller renders fixed copy; step 8 greps for it. |
| A password change leaves an old session alive | Medium | §10 — the session id is regenerated and the other sessions are invalidated. |
| `password_hash()` with a deliberately long password is slow | Low | The 200-character maximum in §5. |
| The mail is sent for a transaction that then rolls back | Low | §11 — the mail is sent **after** the commit. |
| `session_regenerate_id()` warns because the headers were already sent | Low | `startSession()` is the first call and opens the output buffer. |

---

## 17. Out of scope

- **Two-factor authentication.**
- **Social or OAuth sign-in.**
- **A "remember me" cookie.** The session cookie is a session cookie.
- **Password history and forced rotation.** The minimum is 10 characters, and
  nothing forces a change later.
- **An administrator's "reset another user's password" screen.**
- **Account deletion.** The `users.status` column can disable an account; deleting
  the data is a separate feature.
- **Rate-limiting the forgot-password form.** The enumeration rule is what matters
  here; a per-IP throttle is a later hardening step.
- **The dashboard, transactions, reports, categories and settings** — issues
  [017](issue-017.md) – [020](issue-020.md).
- **Rewriting `includes/mailer.php`.** It exists and is tested.
