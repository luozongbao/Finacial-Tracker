# Issue-022 — Security: the unauthenticated endpoints that cost the server something

> **Status:** **open** — found by the audit of 2026-10-28, all of it measured
> against the running application.
> **Target:** `views/forgot_password.php`, `views/register.php`,
> `includes/auth.php`, `includes/mailer.php`, `database/schema.sql`,
> `database/migrations/`, `database/auth_test.php`,
> `database/live/live_security_test.php`
> **Severity:** **Medium to High** — an unauthenticated visitor can turn the
> installation into a mail cannon aimed at a third party, and can enumerate
> which addresses have an account.
> **Requested by the user:** "Now the implementation is complete. before release
> v.1.0, let's focus on security for a bit. 1. help check sql injection, or any
> security risk. and write issue files for dev to fix those risks."
> **Depends on:** [issue-011](issue-011.md) (the schema),
> [issue-012](issue-012.md) (the helpers and the mailer),
> [issue-016](issue-016.md) (the authentication screens and the two-sided
> registration gate),
> [issue-010](issue-010.md) (the SMTP mailer and its failure contract)
> **Related:** [issue-021](issue-021.md) (the sign-in throttle — the throttle
> that *does* exist, and the pattern the missing ones should follow),
> [issue-024](issue-024.md) (the response-hardening headers)

---

## Table of contents

1. [Objective](#1-objective)
2. [Finding A — the forgot-password failure state is a deterministic enumeration oracle](#2-finding-a--the-forgot-password-failure-state-is-a-deterministic-enumeration-oracle)
3. [Finding B — the timing floor cannot equalise the two branches](#3-finding-b--the-timing-floor-cannot-equalise-the-two-branches)
4. [Finding C — `/register` has no throttle, and its accounts are immediately usable](#4-finding-c--register-has-no-throttle-and-its-accounts-are-immediately-usable)
5. [Finding D — `/forgot-password` has no throttle: the mail cannon](#5-finding-d--forgot-password-has-no-throttle-the-mail-cannon)
6. [Finding E — the exception messages put personal data into the log](#6-finding-e--the-exception-messages-put-personal-data-into-the-log)
7. [The fix](#7-the-fix)
8. [Acceptance criteria](#8-acceptance-criteria)
9. [Verification](#9-verification)
10. [Rollback](#10-rollback)
11. [Risks](#11-risks)
12. [Out of scope](#12-out-of-scope)
13. [Findings that were audited and found clean](#13-findings-that-were-audited-and-found-clean)

---

## 1. Objective

Three of the application's unauthenticated endpoints — `/register`,
`/forgot-password` and `/login` — are the only doors an anonymous visitor can
knock on. `/login` is protected by a throttle
([issue-021](issue-021.md)). The other two are not protected at all, and the
protection that `/forgot-password` believes it has — the response-time floor —
does not do what its comment claims.

This issue closes three gaps:

| Gap | Effect |
|---|---|
| **A — the enumeration oracle** | A visitor can tell, from the response body, whether an address has an account. |
| **B — the timing oracle** | A visitor can tell the same thing from the response time, even when the body is identical. |
| **C + D — no throttle** | A visitor can create accounts at will and make the server send mail at will. |

> **Note on the design intent.** The screen is *written* to be neutral: the
> success message says *"If that address has an account, a reset link is on its
> way."* and the code comment in the unknown branch says *"The same answer as
> for a known address."* The intent is right. The implementation delivers it
> only on the happy path.

---

## 2. Finding A — the forgot-password failure state is a deterministic enumeration oracle

**Severity: High for enumeration.** (Medium overall — it discloses which
addresses have an account, and it is trivially automatable.)

`views/forgot_password.php` has **four** states, and one of them can only be
reached for an address that **has** an account:

```php
// views/forgot_password.php
if ($user !== null) {
    // ...
    $sent = sendMail((string) $user['email'], 'Reset your password', $body);

    if (!$sent) {
        // Only a real account reaches this branch. The raw SMTP error
        // stays in the log.
        $alert = [
            'kind'  => 'danger',
            'title' => 'The reset link could not be sent. Please try again.',
        ];

        $state = 'failed';
    } else {
        $state = 'sent';
    }
} else {
    // The same answer as for a known address. Nothing was
    // created, and nothing is said about the address.
    $state = 'sent';
}
```

The comment is correct, and it is the finding. The two branches render
**different bodies**:

| State | The alert | The button |
|---|---|---|
| `sent` (known address, mail sent — **or** unknown address) | `alert--success` — *"If that address has an account, a reset link is on its way."* | `Send reset link` |
| `failed` (known address, mail failed) | `alert--danger` — *"The reset link could not be sent. Please try again."* | `Try again` |

So: **when the SMTP transport is configured but the send fails, a known
address produces a danger alert and an unknown address produces a success
alert.** The attacker needs no timing measurement, no statistics and no
network conditions — the body answers the question outright.

Two things make this reachable in practice:

1. **A misconfigured or flaky SMTP server.** The configuration is *proved* by
   the installer (step 2), but the server can fail later: a credential
   rotation, a greylisting policy, a quota, a full mailbox, a transient DNS
   or TLS failure. The window in which `sendMail()` returns `false` is exactly
   the window in which the oracle works.
2. **An address that overflows the mail size.** `sendMail()` returns `false`
   for a transport failure of any kind.

The fix is simple and belongs in the same place the message was chosen:

> **Every address must produce the same body.** The send failure is a property
> of the **installation**, not of the visitor's address. It must be reported as
> an installation-level failure — the same message for every address — or
> logged and shown as the neutral "sent" state.

---

## 3. Finding B — the timing floor cannot equalise the two branches

**Severity: Medium.** (Enumeration by timing.)

The screen has an explicit, documented defence:

```php
// views/forgot_password.php
// The floor of the response time. The two branches of this screen must
// not be told apart by how long they take.
$startedAt = microtime(true);
// ... the branches ...
$elapsed = (microtime(true) - $startedAt) * 1000;

if ($elapsed < AUTH_TIMING_FLOOR_MILLISECONDS) {
    usleep((int) ((AUTH_TIMING_FLOOR_MILLISECONDS - $elapsed) * 1000));
}
```

**The floor raises the floor; it does not equalise the branches.** The
unknown branch does one `SELECT` and then sleeps up to 500 ms. The known
branch does the same `SELECT`, an `INSERT`, **a synchronous SMTP round trip**,
and then sleeps up to 500 ms — so it pays the round trip **on top of** the
floor.

Measured against a stub mailer with a **2-second** artificial SMTP delay
(`mailer-mode.txt` → `slow`), two requests each:

| Branch | Time |
|---|---|
| Known address | **2.004 s** |
| Unknown address | **0.501 s** |
| **Gap** | **1.50 s** — a **4×** ratio |
| Response bodies | **byte-identical** |

So even with Finding A fixed, the screen would still leak. The bodies being
identical is the *right* design; the timing is the remaining channel.

> **A floor cannot fix a variable added on top of it.** The only ways to
> equalise are to make the unknown branch pay the same cost, or to make
> **neither** branch pay it inside the request.

---

## 4. Finding C — `/register` has no throttle, and its accounts are immediately usable

**Severity: Medium.**

`/register` accepts unlimited registrations. Measured:

```
--- 10 registrations with distinct addresses ---
user count before: 2
user count after:  12
delta:             10
```

Ten requests, ten accounts, no rate limit, no CAPTCHA, no per-source counter,
no cap on the number of accounts.

The accounts are **immediately usable**, and that is what makes this more than
a row-count nuisance:

- `views/register.php` creates the row with `email_verified_at = NULL` and
  `status = 'active'` (the behaviour issue-016 specifies).
- The sign-in path never checks `email_verified_at` — `views/login.php` checks
  the identifier, the password and the **status**, and a `NULL`
  `email_verified_at` does not block the sign-in.

So an attacker who registers ten accounts has ten working accounts, without
owning a single mailbox. Each one can then be used to:

- create categories and transactions, so the owner's dashboard and reports are
  no longer the owner's own data;
- fill the database;
- **and target a third party** — see Finding D.

There is also a **queue-jumping** consequence for a legitimate owner: on an
installation where the owner is the *first* user, a registration spam can
consume the "first user" slot before the owner arrives.

> **`ALLOW_REGISTRATION = false` closes this completely.** The flag is
> two-sided (issue-016), so with the flag off the `POST` is refused before the
> CSRF check and before any input is read. But the flag is a **global
> switch**, not a limiter: it cannot say "one registration per hour". It is a
> mitigation for a single-user installation, not a fix.

---

## 5. Finding D — `/forgot-password` has no throttle: the mail cannon

**Severity: High.** (Third-party abuse and the exhaustion of the SMTP
reputation.)

`/forgot-password` has no throttle either. Measured:

```
--- 20 POSTs to /forgot-password for one registered address ---
mails recorded by the stub mailer: 20
```

Twenty requests, twenty real emails, no limit. Three distinct harms:

### D.1 — Mail bombing

Each request produces a real email to a **registered** address. The owner of
that address receives them. A victim can be made to receive thousands of
reset mails — a denial of service against their inbox, carried out with the
operator's SMTP server and the operator's sender identity.

### D.2 — The cannon can be aimed at a third party

Because `/register` creates an account for **any** address without proving
ownership of it, an attacker can:

1. register `victim@example.com` — one registration, one row; and
2. send `/forgot-password` for that address as many times as they like.

The server then sends the reset mail to `victim@example.com` — **a stranger's
inbox**. The installation becomes a mail cannon pointed at arbitrary third
parties, using the operator's SMTP credentials and the operator's reputation
with their mail provider. A provider that sees this will throttle, block or
blacklist the operator's domain.

> **The one-per-address limit is not a defence here.** The unique key on
> `users.email` means the attacker registers the address **once** and then
> sends as many reset mails as they like. The limiter has to count the
> **sends**, not the registrations.

### D.3 — The reset-token table churns

Each request for a known address runs `createPasswordReset()`, which deletes
the account's unused token and inserts a new one. The table does not grow
without bound, but an attacker who knows a victim's address can **continuously
cancel the victim's pending reset link** — a legitimate user who requested a
reset will find their link dead. Minor, but free to fix with the same limiter.

---

## 6. Finding E — the exception messages put personal data into the log

**Severity: Low.** (Data protection, not an exploit.)

Twenty-eight `error_log()` calls in the application log
`$ex->getMessage()` verbatim. A MySQL unique-key violation embeds the
offending value:

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'victim@example.com' for key 'users.email_unique'
```

That string — with the e-mail address in it — lands in the LiteSpeed error log
when two registrations race for the same address. A PHPMailer exception
carries the SMTP host and sometimes the SMTP user name.

The log file is inside the container and is not web-reachable, so this is not
an exposure on its own. It becomes one when the operator ships the logs
somewhere else, turns on the MySQL general log, or attaches the log to a
support ticket.

The mitigation is not complicated: the messages that are logged should carry
the **context of the failure**, not the **data that failed**. A duplicate-key
error should be logged as *"the address is already registered"* — and handled
as the neutral "that address is already registered" field error, which the
screen already has.

---

## 7. The fix

### 7.1 Neutralise the enumeration oracles (Findings A and B)

Two changes, both in `views/forgot_password.php`:

**(a) One body for every address.** When `sendMail()` returns `false`, the
screen must render the **same** neutral response it renders for an unknown
address. The transport failure is reported to the **operator** through the log
and through an installation-level indicator; it is not reported to the
visitor as a difference between two addresses.

There is a design decision to make here, and it should be made explicitly:

| Option | Visitor sees | Operator sees | Note |
|---|---|---|---|
| **A. Always neutral** | *"If that address has an account, a reset link is on its way."* | the log | The simplest and the safest. The visitor is not told the mail failed — but the log says why the mail never arrived. |
| **B. Fail loudly for everyone** | *"Email could not be sent. Please try again later."* for **every** address | the log | Also neutral between addresses. Better UX when SMTP is genuinely down. The state must be entered **before** the branch that knows the address. |

**Option B is preferable**, and it is neutral between addresses because it is
produced by the *installation's* state, not by the address. It requires the
state to be decided from `mailIsConfigured()` **and** the send result, both of
which must produce the **same** message regardless of the address.

**(b) Take the SMTP round trip out of the request.** This is what actually
fixes the timing gap, and it is the change that makes the floor meaningful.

> **The outbox.** The request writes a row into a small `outbox` table and
> returns immediately. A **CLI worker** sends the queued messages and marks
> them sent. The repository already has the precedent: the migrations are
> "applied by hand", and the LIVE tier is a shell script. A worker script
> fits the project's style exactly.

The consequences are decisive:

| | Before | After |
|---|---|---|
| Known address | `SELECT` + `INSERT` + SMTP round trip | `SELECT` + `INSERT` + `INSERT` |
| Unknown address | `SELECT` | `SELECT` |
| The difference | **the SMTP round trip** (seconds) | **one `INSERT`** (milliseconds) |
| The floor | 500 ms, and the gap is on top of it | 500 ms, and the gap is inside it |

The known branch still does one extra `INSERT` (the outbox row) that the
unknown branch does not. That is **milliseconds** against a **500 ms** floor —
a difference of three orders of magnitude, and therefore not measurable.

The worker also gives the **rate limit** somewhere to live: the limiter
counts the **outbox rows**, not the requests, so it works for the mail-cannon
case as well as for the registration case.

**If the outbox is judged too large a change for v1.0**, the fallback is to
make the unknown branch pay a comparable cost — but the honest answer is that
there is no reliable way to simulate an SMTP round trip, so the fallback
reduces the gap by *guessing* and does not close it. **The outbox is the fix.**

### 7.2 The throttles (Findings C and D)

Both endpoints need a limiter, and the limiter should be the **same kind** of
limiter the sign-in throttle becomes after
[issue-021](issue-021.md) — a **decaying counter per key**, with the effective
penalty being the stricter of the per-key and the per-source counters.

| Endpoint | Key | Proposed limit |
|---|---|---|
| `/register` | the source address | a handful of registrations per hour |
| `/register` | the submitted address | one — the unique key already does this |
| `/forgot-password` | the submitted address | a small number of mails per hour, **regardless of whether the address is registered** |
| `/forgot-password` | the source address | a small number of requests per hour |

The per-address limit on `/forgot-password` must be counted on the **attempt**,
not on the successful sends — it is the **attempt** that costs a mail when the
address is known, and the **attempt** is what the attacker controls.

The counters need a home. The `login_attempts` table is keyed on a SHA-256 of
an identifier and is specific to the sign-in path; reusing it for the
registration path would couple two unrelated mechanisms. A small, generic
`request_throttles` table — `(key_hash, bucket, hits, window_started_at)` —
serves all of them, and it is the table the housekeeping in
[issue-021](issue-021.md) §8.5 also cleans.

### 7.3 Bound the SMTP wait (Finding D)

`sendMail()` must not be able to hold a request open for an unbounded time.
The PHPMailer timeouts (`Timeout`, `SMTPKeepAlive`, the connect timeout) must
be set **explicitly** and **bounded** — a request that waits 30 seconds for a
dead SMTP server is an easy denial of service on its own, and it widens the
timing gap of Finding B. With the outbox of §7.1 this becomes a worker
problem rather than a request problem, which is the better place for it.

### 7.4 The log (Finding E)

Replace `$ex->getMessage()` with a **sanitised** message for the paths that
handle personal data. The exception message stays available to the operator
through the log's *level*, not through its *content*:

```php
// Before — the value is in the message.
error_log('[auth] the registration failed: ' . $ex->getMessage());

// After — the context is in the message, the data is not.
error_log('[auth] the registration failed: ' . $ex->getCode());
```

A unique-key violation should additionally be **caught** and turned into the
screen's existing *"that address is already registered"* field error, so the
common race does not reach the log at all.

---

## 8. Acceptance criteria

- [ ] `/forgot-password` returns a **byte-identical** response body for a
      known address and an unknown address, in **every** state: mail sent, mail
      failed, mail not configured, and invalid address.
- [ ] The response **time** for a known address and an unknown address differ
      by **less than 100 ms** at the median over 20 requests each — measured
      against a mailer stub with a 2-second delay, so the test fails against
      the current implementation.
- [ ] The SMTP send happens **outside** the request (an outbox table and a
      CLI worker), or an equivalent mechanism that removes the round trip
      from the response path. The worker is documented in
      `docs/issues/README.md` and in the operator's notes.
- [ ] The outbox worker **sends** what was queued, **marks** it sent, and
      **retries** a transient failure without sending a duplicate.
- [ ] `/register` is rate-limited **per source address**. A test sends more
      registrations than the limit and asserts that the surplus is refused
      with a **429** and a `Retry-After` header.
- [ ] `/forgot-password` is rate-limited **per submitted address** and **per
      source address**. A test sends more requests than the limit for **one**
      address and asserts the surplus is refused **before** the mail is
      queued — the refusal is what stops the cannon, so it must happen on the
      request path.
- [ ] The per-address limiter counts the **attempt**, so a known address and
      an unknown address are both counted.
- [ ] The SMTP timeouts are **explicit and bounded**, and the bounds are
      asserted by the test.
- [ ] The exception messages that reach `error_log()` **do not contain** the
      submitted address, the submitted password or any other submitted value.
      A test asserts that a deliberate duplicate registration does not put the
      address into the log.
- [ ] A duplicate registration produces the screen's existing **field error**
      (*"that address is already registered"*) and **not** an error page.
- [ ] The rate limiters are **decaying** — a count older than the window
      stops counting — and the tables are cleaned so they do not grow without
      bound.
- [ ] `database/auth_test.php` covers the mechanism in a sandbox and
      `database/live/live_security_test.php` covers the end-to-end behaviour
      over HTTP.

---

## 9. Verification

````bash
# The lint, on every file that was touched.
docker compose exec -T app php -l /var/www/html/views/forgot_password.php
docker compose exec -T app php -l /var/www/html/views/register.php
docker compose exec -T app php -l /var/www/html/includes/auth.php

# The auth regression tier.
docker compose exec -T app php /var/www/html/database/auth_test.php

# The LIVE tier.
bash database/live/run-live-tests.sh

# The whole suite.
.github/skills/dev-stack-ops/scripts/run-tests.sh
````

The **manual acceptance walk-through** — the one that caught this:

````bash
# 1. The enumeration check: the two bodies must be identical.
KNOWN=$(curl -s -c /tmp/j1 http://127.0.0.1:8088/forgot-password \
        | grep -oP 'name="csrf_token" value="\K[a-f0-9]+')
curl -s -b /tmp/j1 -c /tmp/j1 -d "csrf_token=$KNOWN" \
     -d "email=known@example.com" http://127.0.0.1:8088/forgot-password > /tmp/known.html

UNKNOWN=$(curl -s -c /tmp/j2 http://127.0.0.1:8088/forgot-password \
        | grep -oP 'name="csrf_token" value="\K[a-f0-9]+')
curl -s -b /tmp/j2 -c /tmp/j2 -d "csrf_token=$UNKNOWN" \
     -d "email=nobody@example.com" http://127.0.0.1:8088/forgot-password > /tmp/unknown.html

diff /tmp/known.html /tmp/unknown.html && echo "BODIES IDENTICAL"

# 2. The timing check, against a mailer stub with a 2-second delay.
for i in $(seq 1 20); do
  curl -s -o /dev/null -w '%{time_total}\n' ... -d "email=known@example.com"  ...
  curl -s -o /dev/null -w '%{time_total}\n' ... -d "email=nobody@example.com" ...
done | sort -n | awk '{a[NR]=$1} END {print "median known:", a[int(NR/2)], " median unknown:", a[int(NR/2)+1]}'

# 3. The cannon check: the surplus must be refused BEFORE a mail is queued.
for i in $(seq 1 30); do
  curl -s -o /dev/null -w '%{http_code}\n' ... -d "email=known@example.com" ...
done | sort | uniq -c
# Expected: the first N are 200, the rest are 429.

# 4. The registration check: the surplus must be refused.
for i in $(seq 1 30); do
  curl -s -o /dev/null -w '%{http_code}\n' ... -d "email=x$i@example.com" ...
done | sort | uniq -c
````

---

## 10. Rollback

The change is confined to `views/forgot_password.php`,
`views/register.php`, `includes/auth.php`, `includes/mailer.php` and (if the
outbox is added) one table plus one worker script.

- The outbox is **additive**: the table is new, and the worker's removal
  leaves the request path writing to a table nobody reads — the mails simply
  stop being sent. The rollback is to restore the synchronous send in
  `views/forgot_password.php` and `views/register.php` and to drop the table.
- The throttles are **additive**: the limiter can be disabled with a
  configuration constant, and the limiter's absence restores the current
  behaviour exactly.
- The neutered messages change what the visitor sees in the failure state.
  If the operator prefers to keep the loud failure message, use **option B**
  in §7.1 — it is neutral between addresses and tells the operator what is
  wrong.

---

## 11. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| The outbox is never drained, and no mail is ever sent | Medium | High | The worker must be documented as **required**, the installation card in `/settings` must show the queue depth, and the tests must assert that a queued message is eventually sent. |
| The worker runs twice and sends a duplicate | Low | Medium | The worker marks a row sent **before** the next one is claimed, and it uses a `SELECT … FOR UPDATE` claim, exactly as `consumePasswordReset()` does. |
| The throttles lock out a legitimate owner who mistypes their address | Medium | Low | The limit is per **submitted address** *and* per source; a mistyped address that is not registered is not counted against the source. The limit is generous, and the reset flow still works from a different browser if it is hit. |
| The neutral message hides a genuine SMTP outage from the operator | Medium | Medium | The transport failure is logged loudly, the installation card shows the mail configuration state, and option B in §7.1 keeps a loud **installation-level** message. |
| The timing test is flaky | Medium | Low | The test measures the **median** over 20 requests each, not a single sample, and it runs against a **deterministic** 2-second stub mailer rather than a real SMTP server. |
| The rate limiter adds a write to the hot path | Low | Low | The limiter is one `INSERT … ON DUPLICATE KEY UPDATE` against a small table, and it replaces a synchronous SMTP round trip that was far more expensive. |

---

## 12. Out of scope

- **The registration gate itself** (`ALLOW_REGISTRATION`) — it is correct and
  it is two-sided (issue-016). It is a mitigation here, not the fix.
- **Requiring e-mail verification before the first sign-in.** Issue-016
  deliberately created the account as `status = 'active'` with
  `email_verified_at = NULL`, and the owner can sign in without verifying. That
  is a **product** decision, and it is the operator's to make; this issue
  does not change it. It is noted here because it is what makes Finding C
  worse than a row-count nuisance.
- **CAPTCHA / proof-of-work.** Not proportionate for a personal installation
  once the throttles are in place. If the installation is ever opened to the
  public internet, this becomes worth revisiting.
- **The sign-in throttle** — that is [issue-021](issue-021.md).

---

## 13. Findings that were audited and found clean

These were checked during the same audit and need **no change**.

| Area | Verdict |
|---|---|
| **SQL injection in the registration and the reset path** | Clean. Every value is bound as a parameter; the token is stored as a SHA-256 hash and compared with `hash_equals()`. |
| **The reset token** | Correct. `bin2hex(random_bytes(32))` — 256 bits of entropy — stored only as a hash, single-use (`used_at`), with a one-hour expiry, and redeemed inside a transaction with `SELECT … FOR UPDATE` so two concurrent redemptions cannot both succeed. |
| **The verification token** | Correct. The same shape: 256 bits, stored as a hash, 24-hour expiry, single-use. |
| **The CSRF token** | Correct. Validated **before** any input is read, in every branch of every POST. |
| **The response escaping** | Correct. Every dynamic value goes through `e()`. |
| **The `Host` header** | Correct. `absoluteUrl()` reads `$_SERVER['SERVER_NAME']` and **never** `HTTP_HOST`, so a reset link cannot be pointed at an attacker's host. |
| **`sendMail()` never throws** | Correct. A transport error is caught and becomes `false`; a public form cannot become a 500 because SMTP is down. |
| **The mail is not configured** | Correct. `mailIsConfigured()` is checked, and the "not configured" state is shown for **every** address — it is a property of the installation, not of the visitor. |
| **The registration gate** | Correct. Two-sided: the `GET` renders no form, and the `POST` is refused **before** the CSRF check and before any input is read. |
| **The password hash** | Correct. `password_hash()` with `PASSWORD_DEFAULT`; the hash is never logged and never enters the session. |
