# Issue-021 — Security: the sign-in throttle is a denial of service

> **Status:** **open** — the audit of 2026-10-28 found the throttle working as
> specified, and found that the way it works is an unauthenticated denial of
> service against any account whose username is known.
> **Target:** `includes/auth.php`, `views/login.php`, `database/schema.sql`,
> `database/migrations/`, `database/auth_test.php`,
> `database/live/live_security_test.php`
> **Severity:** **Medium** — no data is disclosed and no data is destroyed, but
> any anonymous visitor can lock the owner of a known account out of their own
> installation, for as long as they care to.
> **Requested by the user:** "Now the implementation is complete. before release
> v.1.0, let's focus on security for a bit. 1. help check sql injection, or any
> security risk. and write issue files for dev to fix those risks."
> **Depends on:** [issue-011](issue-011.md) (the `login_attempts` table),
> [issue-012](issue-012.md) (`includes/auth.php`, the session and the throttle),
> [issue-014](issue-014.md) (the front controller),
> [issue-016](issue-016.md) (§6 — the attempt limit: 5 failures, a 15-minute
> wait, counted against the identifier)
> **Related:** [issue-022](issue-022.md) (the other unauthenticated endpoints),
> [issue-025](issue-025.md) (the installer's secret handling)

---

## Table of contents

1. [Objective](#1-objective)
2. [What the throttle does today](#2-what-the-throttle-does-today)
3. [Finding 1 — the lockout is an unauthenticated denial of service](#3-finding-1--the-lockout-is-an-unauthenticated-denial-of-service)
4. [Finding 2 — the wait can be renewed forever](#4-finding-2--the-wait-can-be-renewed-forever)
5. [Finding 3 — the counter update loses a count under concurrency](#5-finding-3--the-counter-update-loses-a-count-under-concurrency)
6. [Finding 4 — the account's own lock columns are never read](#6-finding-4--the-accounts-own-lock-columns-are-never-read)
7. [Finding 5 — the throttle table grows without bound](#7-finding-5--the-throttle-table-grows-without-bound)
8. [The fix](#8-the-fix)
9. [Acceptance criteria](#9-acceptance-criteria)
10. [Verification](#10-verification)
11. [Rollback](#11-rollback)
12. [Risks](#12-risks)
13. [Out of scope](#13-out-of-scope)
14. [Findings that were audited and found clean](#14-findings-that-were-audited-and-found-clean)

---

## 1. Objective

Make the sign-in throttle a **brute-force defence** instead of a
**denial-of-service weapon**, without weakening it against a password guesser.

The throttle itself is well built and is not in question. It is keyed against
the **identifier** rather than against the account, so a name that matches no
account is throttled too; it is checked **before** the password is verified, so
it is cheap; and the failed-attempt count is written in a single statement. All
three are correct decisions, and all three must survive this issue.

What is in question is the **shape of the penalty**. A hard, renewable,
identifier-keyed lock is a lever that any anonymous visitor can pull: knowing a
username is enough to take the account out of service for a quarter of an hour
at a time, for as long as the attacker cares to keep pulling it.

---

## 2. What the throttle does today

The whole mechanism lives in `includes/auth.php`:

| Piece | Where | What it does |
|---|---|---|
| `LOGIN_ATTEMPT_LIMIT` | `includes/auth.php:45` | `5` — the number of failures that starts a wait. |
| `LOGIN_LOCK_MINUTES` | `includes/auth.php:49` | `15` — how long the wait lasts. |
| `loginIdentifierKey()` | `includes/auth.php:481` | SHA-256 of the trimmed, lower-cased identifier. |
| `loginThrottleState()` | `includes/auth.php:505` | Reads the counter for an identifier; resets an **expired** lock to zero. |
| `recordLoginFailure()` | `includes/auth.php:560` | Raises the counter and sets `locked_until` when the limit is reached. |
| `clearLoginFailures()` | `includes/auth.php:596` | Deletes the identifier's row and clears the account's columns after a **successful** sign-in. |

The enforcement point is `views/login.php`:

```php
// views/login.php — the lock is checked BEFORE the password.
$throttle = loginThrottleState($identifier);

if ($throttle['locked']) {
    $locked = true;
    $alert = [
        'kind'  => 'warning',
        'title' => 'Too many attempts. Try again in 15 minutes.',
    ];
} else {
    // ... the password is verified here
}
```

Because the lock is checked **before** the password, a locked identifier is
refused regardless of whether the password is correct. That is what makes the
limiter effective against a guesser — and it is what makes it a denial of
service.

### The measurement

The behaviour was confirmed against the running application, not inferred from
the source. A seeded account was signed in with the **correct** password
(control: `HTTP 302 → /dashboard`), then five wrong passwords were sent from a
fresh cookie jar, and then the **correct** password was sent again:

```
--- the CORRECT password works first (control) ---
control status: 302 -> http://127.0.0.1:8088/dashboard

--- now five WRONG passwords from a fresh cookie jar ---
  attempt 1: That username and password do not match.
  attempt 2: That username and password do not match.
  attempt 3: That username and password do not match.
  attempt 4: That username and password do not match.
  attempt 5: That username and password do not match.

--- the state of the throttle ---
identifier_hash  failed_attempts  locked_until
fc450c29…        5                2026-10-28 17:37:55
users.failed_attempts: 5   locked_until: 2026-10-28 17:37:55

--- the CORRECT password, again ---
Too many attempts. Try again in 15 minutes.
STATUS:200
```

Five anonymous requests, and the owner of the account cannot sign in. The
attacker does not need the password, the session, or any credential.

---

## 3. Finding 1 — the lockout is an unauthenticated denial of service

**Severity: Medium.**

The counter is keyed on the identifier and nothing else. The throttle therefore
has two consequences that a deployment cannot avoid:

1. **An attacker who knows a username can lock the account.** They do not need
   the password; five wrong attempts are enough, and the account is unusable
   for fifteen minutes.
2. **The lockout is not bounded.** Because the lock expires and the counter
   resets to zero, the attacker can simply start again. The account is locked
   *indefinitely* for as long as the attacker keeps trying — it is never
   available while the attacker is active.

For a personal installation this is a nuisance; for an installation reachable
over the internet it is a permanent, zero-cost, unauthenticated denial of
service against a named account.

### What must change

The limiter must still stop a password guesser, but it must **stop being a
lever**. The two requirements that together achieve this:

| Requirement | Why |
|---|---|
| **The penalty must decay.** A failure older than a quiet window must stop counting, so the attacker's own attempts eventually stop mattering. | Without decay the attacker can hold the lock indefinitely. With decay, the owner's own successful sign-in (or simply the owner's patience) breaks the cycle — provided the attacker is not continuously refreshing the counter, which the per-IP counter in §8 handles. |
| **The penalty must be per-source as well as per-identifier.** | Without it, an attacker rotates the *username* and gets an unlimited number of five-attempt bursts. A per-source counter bounds the attacker; the per-identifier counter keeps the defence meaningful. |

The per-source counter must **never be the only counter**: an attacker who
knows a username must not be able to lock the owner by locking the *owner's*
source address. The two counters are independent, and the effective penalty is
the **stricter** of the two — never the sum of them, or a single attacker could
lock an account by combining a small number of identifier failures with a
small number of source failures.

---

## 4. Finding 2 — the wait can be renewed forever

**Severity: Medium.** (The mechanism behind Finding 1.)

`loginThrottleState()` resets an **expired** lock:

```php
// includes/auth.php:527 — the wait is over, the counter starts again.
if ($expiresAt !== false && $expiresAt <= time()) {
    $db->execute(
        'UPDATE login_attempts
            SET failed_attempts = 0, locked_until = NULL
          WHERE identifier_hash = ?',
        [loginIdentifierKey($identifier)]
    );

    return ['locked' => false, 'locked_until' => null, 'attempts' => 0];
}
```

The reset happens the moment the lock expires, and the attacker needs only one
more request to start a new count. There is no quiet window: the counter that
was five a moment ago is zero a moment later, so the attacker never has to
"build up" anything and the owner never gets a stable, usable account.

The fix is the decay requirement of §3: a failure must stop counting after a
quiet period, so the counter that reaches the limit must be *recent* failures
— not failures accumulated over an arbitrary span.

---

## 5. Finding 3 — the counter update loses a count under concurrency

**Severity: Low.** (It weakens the limiter; it does not open a hole.)

`recordLoginFailure()` reads the current count and then writes `count + 1`:

```php
// includes/auth.php:562
$state    = loginThrottleState($identifier);
$attempts = $state['attempts'] + 1;
// ...
$db->execute(
    'INSERT INTO login_attempts (identifier_hash, failed_attempts, locked_until)
          VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE
          failed_attempts = VALUES(failed_attempts),
          locked_until   = VALUES(locked_until)',
    [loginIdentifierKey($identifier), $attempts, $lockedUntil]
);
```

The **statement** is atomic. The **value** is not: it was computed from a read
that happened before the write. Two simultaneous failures both read `4`, both
compute `5`, and both write `5` — one failure is lost.

The comment above the statement says:

> `// One statement, so two simultaneous failures cannot lose a count.`

**That claim is false**, and it should not survive into v1.0 as a comment that
contradicts the code. The fix is to let the database do the increment:

```sql
INSERT INTO login_attempts (identifier_hash, failed_attempts, locked_until)
     VALUES (?, 1, NULL)
ON DUPLICATE KEY UPDATE
    failed_attempts = failed_attempts + 1
```

The `locked_until` then has to be decided from the **post-increment** value,
which means a second statement that reads the row the upsert just wrote. Two
statements are acceptable here — the point is that the *increment* must not be
computed in PHP from a stale read.

---

## 6. Finding 4 — the account's own lock columns are never read

**Severity: Low.** (A correctness and clarity defect, not an exploitable one.)

The `users` table carries two columns for this:

```sql
`failed_attempts`   INT UNSIGNED NOT NULL DEFAULT 0,
`locked_until`      DATETIME     NULL DEFAULT NULL,
```

`recordLoginFailure()` writes them (`includes/auth.php:586`) and
`views/settings.php:189` clears them. **Nothing ever reads them.** The whole
enforcement is in `login_attempts`, and `loginThrottleState()` queries only
that table.

The consequences:

- A password change in `/settings` clears `users.failed_attempts` and
  `users.locked_until` — and has **no effect whatsoever** on the lock, because
  the lock lives in the other table. The visitor is told the password was
  changed; the account is still locked.
- The next reader of `users` sees a `locked_until` in the future and will
  reasonably conclude the account is locked — which it happens to be, but for
  an unrelated reason, and clearing that column would not unlock it.

The design decision to key the counter on the **identifier** (so a name that
matches no account is throttled too) is right and must stay. The duplicated
columns on the account are what is wrong. Either they are read — as a second,
independent signal — or they are removed.

> **This is a schema change.** `database/schema.sql` is the single source of
> truth for a fresh install, and the change must reach it through a migration
> in `database/migrations/`, exactly as issue-011 §"migrations" specifies.

---

## 7. Finding 5 — the throttle table grows without bound

**Severity: Low.** (A resource leak, not a vulnerability.)

`login_attempts` rows are deleted in exactly one place: `clearLoginFailures()`,
which runs **after a successful sign-in**. Every identifier that fails and is
never successfully signed in keeps its row forever.

The measured consequence, from the same probe run:

```
login_attempts rows after a SUCCESS: 12
```

Twelve rows, from twelve distinct identifiers, one of which was the account
under test. An attacker who sends one request per random username creates one
row per request, and every row is a 64-character hash plus two small columns.
On a personal install this is a slow leak; on an install reachable over the
internet it is a table that an attacker can grow at the rate of the network.

There is already a natural expiry — `locked_until` in the past means the row
is meaningless — but nothing acts on it. A row whose lock has expired is
resurrected the moment the identifier is presented again, so the row cannot
simply be deleted on expiry without changing the reset semantics of §4.

---

## 8. The fix

### 8.1 The shape of the limiter

Replace the hard lock with a **decaying, exponential backoff**, and add a
per-source counter beside the per-identifier one.

| Element | Proposal |
|---|---|
| **Per-identifier counter** | Stays. It is the defence against a password guesser and the reason a name that matches no account is throttled. |
| **Per-source counter** | New. Keyed by a hash of the client address. It bounds an attacker who rotates the identifier. |
| **Decay** | A failure older than a quiet window (the lock window, 15 minutes) stops counting. The counter is therefore "failures in the last 15 minutes". |
| **Backoff** | The wait grows with the recent failure count: 5 failures → 15 minutes; 6 → 30; 7 → 60; … capped at 60 minutes. The **first** failure should cost nothing. |
| **Effective penalty** | The **stricter** of the two counters — never the sum. |
| **Reset** | A successful sign-in clears **both** counters for that identifier. |

The **decay** is the load-bearing part. Without it the attacker can hold the
lock forever; with it, the attacker's own activity is what keeps the lock
alive, so the defender's answer — wait 15 minutes and sign in — works.

### 8.2 The source address

The client address is used **only** as a throttle key, never as an identity.
It must be hashed exactly like the identifier (`hash('sha256', …)`) so the
table never stores a raw address, and the helper must be careful about the
proxy case:

- `REMOTE_ADDR` is the only trustworthy value on a bare install.
- If a reverse proxy is in front, `REMOTE_ADDR` is the proxy's address. The
  application must **not** trust `X-Forwarded-For` — it is client-controlled.
  The value of the proxy's address as the source key is a **shared** bucket,
  which is why the per-identifier counter must remain the primary one.

### 8.3 The increment

Let the database increment (§5). The `locked_until` is then derived from the
post-increment value.

### 8.4 The account columns

Either read `users.failed_attempts` / `users.locked_until` as a second
signal, or drop them. The recommendation is to **drop them** and keep
`login_attempts` as the single source of truth for the throttle — one table,
one mechanism, one place to reason about. That needs a migration:

```sql
-- database/migrations/YYYYMMDD_drop_user_lock_columns.sql
ALTER TABLE `users`
    DROP COLUMN `failed_attempts`,
    DROP COLUMN `locked_until`;
```

…and a change to `database/schema.sql` so a fresh install matches. If the
columns are kept instead, `views/settings.php` must stop clearing them (it
promises an effect it does not have), and `loginThrottleState()` must read
them.

### 8.5 The housekeeping

Expired and idle rows must be removed. The cheapest correct trigger is **on
the sign-in path**: a sign-in attempt already writes to this table, so a
periodic, low-cost cleanup belongs there — for example, delete the rows whose
`locked_until` is in the past **and** whose last update is older than the
quiet window, at most once every few minutes. A plain `DELETE` bounded by a
`LIMIT` is enough; this table is small.

### 8.6 The message

The locked screen says *"Too many attempts. Try again in 15 minutes."* With a
backoff the wait is no longer a constant, so the message must state the
**actual** remaining time rather than a fixed number that would be a lie.

---

## 9. Acceptance criteria

- [ ] A failed sign-in for a known username no longer prevents the **owner**
      from signing in with the correct password once the quiet window has
      elapsed. A regression test asserts the whole cycle: fail ×5 → the correct
      password is refused → the quiet window elapses (or the counter decays) →
      the correct password is accepted.
- [ ] The penalty **decays**: a failure that is older than the quiet window
      does not count towards the limit. A test ages a failure past the window
      and asserts the counter is back below the limit.
- [ ] The wait is **exponential** and **capped**, and the counter is bounded
      by the cap.
- [ ] There are **two** counters — per identifier and per source — and the
      effective penalty is the **stricter** of the two, never the sum. A test
      asserts that a small number of identifier failures combined with a small
      number of source failures does **not** produce a combined penalty.
- [ ] The source key is a **hash**, and the table never stores a raw address.
- [ ] `X-Forwarded-For` is **not** trusted. A request that sends the header
      does not change its source bucket.
- [ ] The counter increment happens **in SQL**. Two concurrent failures raise
      the counter by **two**, not by one. The test asserts the post-increment
      value and the comment in `includes/auth.php` matches the code.
- [ ] Either `users.failed_attempts` / `users.locked_until` are read, or they
      are **dropped** by a migration and removed from `database/schema.sql`. A
      test asserts the two files agree, and that a password change in
      `/settings` no longer implies an unlock it cannot perform.
- [ ] `login_attempts` does not grow without bound: a row that is expired and
      idle is removed, and a test asserts the cleanup runs.
- [ ] The locked screen states the **actual** remaining wait.
- [ ] The identifier-keyed behaviour is **unchanged**: a name that matches no
      account is still throttled, and the decoy hash still equalises the cost
      of a missing account.
- [ ] The message for a wrong password is **unchanged**:
      *"That username and password do not match."* for both halves.
- [ ] `database/auth_test.php` and `database/live/live_security_test.php` both
      cover the new behaviour — the SOURCE tier for the mechanism, the LIVE
      tier for the end-to-end cycle.

---

## 10. Verification

````bash
# The lint, on every file that was touched.
docker compose exec -T app php -l /var/www/html/includes/auth.php
docker compose exec -T app php -l /var/www/html/views/login.php

# The auth regression tier (the SOURCE tier; the sandbox runs the real screens).
docker compose exec -T app php /var/www/html/database/auth_test.php

# The LIVE tier — the sign-in cycle over real HTTP, with real cookies.
bash database/live/run-live-tests.sh

# The whole suite.
.github/skills/dev-stack-ops/scripts/run-tests.sh
````

The **manual acceptance walk-through** — the one that caught this:

````bash
# 1. Seed a known account.
docker compose exec -T app php -r '
require "/var/www/html/config.php";
require "/var/www/html/includes/Database.php";
$db = Database::getInstance();
$db->execute(
    "INSERT INTO users (username, email, password_hash, email_verified_at, status)
     VALUES (?, ?, ?, NOW(), \"active\")",
    ["victim", "victim@example.com", password_hash("CorrectHorseBatteryStaple1", PASSWORD_DEFAULT)]
);'

# 2. Five wrong passwords, then the RIGHT one.
#    Before the fix: "Too many attempts. Try again in 15 minutes."
#    After the fix: the lock decays, and the owner is let back in.
for i in 1 2 3 4 5; do
  rm -f /tmp/j
  C=$(curl -s -c /tmp/j http://127.0.0.1:8088/login | grep -oP 'name="csrf_token" value="\K[a-f0-9]+')
  curl -s -b /tmp/j -c /tmp/j -o /dev/null \
    -d "csrf_token=$C" -d "username=victim" -d "password=wrongwrongwrong" \
    http://127.0.0.1:8088/login
done

rm -f /tmp/j
C=$(curl -s -c /tmp/j http://127.0.0.1:8088/login | grep -oP 'name="csrf_token" value="\K[a-f0-9]+')
curl -s -b /tmp/j -c /tmp/j -o /dev/null -w "%{http_code} -> %{redirect_url}\n" \
  -d "csrf_token=$C" -d "username=victim" -d "password=CorrectHorseBatteryStaple1" \
  http://127.0.0.1:8088/login
# Expected after the fix: the account is usable again once the window has elapsed.

# 3. The table must not grow without bound.
docker compose exec -T db mariadb -u root -p"$MYSQL_ROOT_PASSWORD" financial_tracker -e \
  "SELECT COUNT(*) AS rows_now FROM login_attempts;"
````

---

## 11. Rollback

The change is confined to `includes/auth.php`, `views/login.php` and (if the
account columns are dropped) one migration plus `database/schema.sql`.

- The limiter is a **defence in depth** feature: the password policy, the
  `password_verify()` call, the decoy hash and the CSRF token are untouched, so
  reverting the limiter does not open an authentication bypass.
- To roll back, revert the code and, if the migration ran, reverse it:
  ``sql
  ALTER TABLE `users`
      ADD COLUMN `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
      ADD COLUMN `locked_until` DATETIME NULL DEFAULT NULL;
  ``
- **Do not** run `docker compose down -v` to "reset" anything. Take a dump
  first.

---

## 12. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| The backoff is too gentle and a distributed guesser gets through | Low | Medium | The per-identifier counter is **not** weakened; only the shape of the penalty changes. The regression test asserts that N failures still lock the identifier for the backoff window. |
| The per-source counter locks out a whole NAT / office | Medium | Low | The per-source counter is **never** the only one and never adds to the per-identifier one. The effective penalty is the stricter of the two. |
| The decay lets an attacker reset the count by waiting | Low | Low | The decay window is short relative to a brute-force run, and the per-source counter does not decay on the same schedule. |
| The `users` column migration breaks a fresh install | Low | High | `database/schema.sql` and the migration must be changed **in the same commit**, and `database/schema_test.php` must assert the two agree. |
| The source address is wrong behind a proxy, and every visitor shares one bucket | Medium | Low | Documented explicitly: `REMOTE_ADDR` only, never `X-Forwarded-For`. The per-identifier counter remains the primary defence. |

---

## 13. Out of scope

- **The password policy** (`PASSWORD_MIN_LENGTH`, `PASSWORD_MAX_LENGTH`) — it
  is already specified and enforced, and it is not weakened here.
- **The decoy hash** (`decoyPasswordHash()` / `verifyPasswordOrDecoy()`) — it
  is correct and it stays.
- **Two-factor authentication** — out of scope for a personal install.
- **The registration throttle** — that is [issue-022](issue-022.md).

---

## 14. Findings that were audited and found clean

These were checked during the same audit and need **no change**. They are
recorded here so that the next reader does not repeat the work.

| Area | Verdict |
|---|---|
| **SQL injection in the throttle** | Clean. `loginIdentifierKey()` produces a 64-character hex digest and every query binds it as a parameter. No value is interpolated into SQL. |
| **The identifier is never stored in the clear** | Correct. The table holds only the SHA-256 hash, so a leaked table does not disclose the usernames that were typed at the form. |
| **The password hash never enters the session** | Correct. The session carries the user id and a *credential version* (the digest of the stored hash), never the hash itself. |
| **The session id is regenerated on the privilege change** | Correct. `loginUser()` calls `session_regenerate_id(true)`, and `logoutUser()` clears the session, regenerates and deletes the cookie. Session fixation is closed at the boundary. |
| **The cookie flags** | Correct. `httponly`, `samesite=Lax`, and `secure` **only** over HTTPS — `requestIsHttps()` decides, so a plain-HTTP installation still receives its cookie. |
| **The CSRF token** | Correct. It is validated **before** any input is read, it is a 32-byte `random_bytes()` value, and it is compared with `hash_equals()`. |
| **The generic failure message** | Correct. *"That username and password do not match."* for both a missing account and a wrong password — no enumeration oracle in the body. |
| **The account's status check** | Correct. It runs **after** the password, so a disabled account is not an oracle. |
| **The password hash algorithm** | Correct. `password_hash()` with `PASSWORD_DEFAULT`, verified with `password_verify()`, and re-hashed on sign-in when the default algorithm improves. |
| **The credential version** | Correct. A password change ends every other session of the account, and the check happens on every request in the front controller. |
