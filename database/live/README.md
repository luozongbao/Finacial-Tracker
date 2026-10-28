# The LIVE tier — `database/live/`

Two tiers of regression test live in this repository.

| Tier | Where | What it drives | What it needs |
|---|---|---|---|
| **SOURCE** | `database/*_test.php` | A **sandbox copy** of the application, served against a **scratch database** | Nothing but the container |
| **LIVE** | `database/live/*_test.php` | **The running application**, over HTTP, on the **dev database** | The app container up, `config.php` in place |

`run-tests.sh` runs the SOURCE tier. `run-live-tests.sh` runs the LIVE tier.
**They are separate on purpose** — the LIVE tier needs a running application
and writes to the dev database, so it must never be part of the default run.

---

## Why the LIVE tier exists

The SOURCE tier cannot see a defect that only exists **under the web SAPI**.

This is not theoretical — it is the bug that blocked v1.0:

> `install.php` syntax-checked the `config.php` it had just written by shelling
> out to `escapeshellarg(PHP_BINARY) . ' -l …'`. Under **LSPHP**
> (`PHP_SAPI === 'litespeed'`) `PHP_BINARY` is the **empty string**, so the
> command became `sh: 1: : Permission denied` and the wizard reported
> *"The configuration file could not be written"* for a perfectly good config.
>
> The CLI test passed, because under the **CLI** SAPI `PHP_BINARY` is
> populated. The bug only existed where the application actually runs.

The SOURCE tier now guards that specific case directly
(`database/install_test.php` drives a live-SAPI probe). The LIVE tier is the
general answer to the same class of defect: **nothing in the source tier can
prove the running application works.**

---

## Running it

```bash
# From the repo root.
bash database/live/run-live-tests.sh

# A single test.
bash database/live/run-live-tests.sh database/live/live_app_test.php

# From inside the container.
docker compose exec -T app php /var/www/html/database/live/live_app_test.php
```

| Env | Default | Meaning |
|---|---|---|
| `LIVE_BASE_URL` | `http://127.0.0.1` | The application under test. Inside the container that is the app; on the host it is `http://127.0.0.1:8088`. |

**Success** is the exit code **and** a completion marker (`Failed: 0` /
`ALL ASSERTIONS PASSED`). A test that dies half way through leaves exit code 0
and no summary — a handler that calls `exit()`, or a fatal error with
`display_errors` off — and every assertion after the point of death never ran.
Such a run is reported **`[ERROR]`**, never `[PASS]`.

---

## What each test proves

### `live_app_test.php` — the screens work

- routing, the guest guard and the status codes
- registration, sign-in, sign-out
- the categories, the transactions, the filters and the edit form
- **the ownership boundary**: user B must not see, open or delete user A's rows
- the reports, the dashboard and the settings
- the error paths (404, 405) and the security headers

### `live_security_test.php` — the screens are safe

- the theme cookie is whitelisted, and a hostile value cannot break out of the
  attribute
- the active nav link uses `aria-current`, never an `is-active` class
- a **GET form carries no CSRF token**; every **POST form carries one**
- user data is escaped on the way out
- the session cookie is `HttpOnly` and `SameSite`
- the session id never reaches the page

---

## The cleanup contract

**The LIVE tier must not leave litter in the dev database.**

Every account it creates is named `live_test_<kind>_<random hex>` and is
deleted in a `register_shutdown_function()` — so a run that **dies half way
through** still cleans up. The pattern matches the original username *and* any
rename of it, and the foreign keys cascade (categories, transactions, password
resets and email verifications all go with the user).

The accounts live in their own namespace, so the cleanup pattern can never
match a real account.

> The `ALLOW_REGISTRATION` gate is **not** tested here. The LIVE tier would
> have to rewrite the live `config.php` to close it, which is a far more
> dangerous thing for a test to do than a sandbox copy. `database/auth_test.php`
> already covers the gate two-sided (GET 403 **and** POST 403) in its sandbox.
