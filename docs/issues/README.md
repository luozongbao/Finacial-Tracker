# Issues — Financial Tracker

Every issue in this folder is a **self-contained specification**. Each one
states the objective, the constraints, the deliverable, the acceptance
criteria and the verification commands, so that it can be picked up by
someone who has not read this repository.

Two tracks run through this folder:

| Track | Owner | Issues | Produces |
|---|---|---|---|
| **DevOps / infrastructure** | DevOps | [001](issue-001.md) · [002](issue-002.md) · [003](issue-003.md) · [009](issue-009.md) · [010](issue-010.md) | The running development stack, the mockup viewer, the web-root hardening, the PHP 8.5.12 runtime floor, and the Composer dependency policy. |
| **UX/UI design** | Design | [004](issue-004.md) · [005](issue-005.md) · [006](issue-006.md) · [007](issue-007.md) · [008](issue-008.md) | The static mockups in [`docs/mockup/`](../mockup/), and through them, the design of the application itself. |
| **Application** | Dev | [011](issue-011.md) – [020](issue-020.md) | The application itself: the schema, the runtime, the design-token layer, the front controller, the installer, the authentication, and the five screens. **All ten issues (011–020) are implemented & verified.** |
| **Security** | Dev | [021](issue-021.md) – [025](issue-025.md) | The findings of the pre-release security audit of 2026-10-28. **021 and 022 are implemented & verified; 023–025 are open.** |
| **Requirements** | Dev + Design | [026](issue-026.md) | The gap between [docs/About.md](../About.md) and the application: the **average** is required and missing. **Open.** |

---

## Index

| # | Title | Track | Status | Depends on | Key deliverable |
|---|---|---|---|---|---|
| [001](issue-001.md) | DevOps: the Docker Compose development stack | DevOps | **implemented & verified** | — | `docker-compose.yml`, `docker/entrypoint.sh`, `.env.example` |
| [002](issue-002.md) | DevOps: the static mockup viewer container | DevOps | **implemented & verified** | 001 | `docker/mockup-nginx.conf`, the `mockup` compose profile |
| [003](issue-003.md) | DevOps: harden the web root; `config.php` write path; CLI harness path | DevOps | **implemented & verified** | 001 | `.htaccess` deny block, the `/var/www/html` symlink, `database/webroot_hardening_test.php` |
| [004](issue-004.md) | UX/UI: mockup charter — IA, tokens, shared shell | UX/UI | **implemented & verified** | — | `index.html`, `00-design-system.html`, the `assets/` layer |
| [005](issue-005.md) | UX/UI: authentication screens | UX/UI | **implemented & verified** | 004 | `01-login.html`, `02-register.html`, `03-register-closed.html`, `04-forgot-password.html` |
| [006](issue-006.md) | UX/UI: dashboard and transaction screens | UX/UI | **implemented & verified** | 004 | `05-dashboard.html`, `06-transactions.html`, `07-transaction-form.html` |
| [007](issue-007.md) | UX/UI: reports, categories and settings screens | UX/UI | **implemented & verified** | 004 | `08-reports.html`, `09-categories.html`, `10-settings.html` |
| [008](issue-008.md) | UX/UI: install wizard screen | UX/UI | **implemented & verified** | 004 | `11-install-wizard.html` |
| [009](issue-009.md) | Ops: upgrade the runtime to PHP 8.5.12 (PHP2Shell remediation) | DevOps | **implemented & verified** | 001 | the pinned `litespeedtech/openlitespeed:1.9.4-lsphp85` image, and the PHP floor in every document |
| [010](issue-010.md) | Ops: introduce Composer as a vendor-time tool and vendor the SMTP mailer | DevOps | **implemented & verified** | 001, 009 | `composer.json`, `composer.lock`, `includes/mailer.php`, `database/mailer_test.php`, the `MAIL_FROM` / `MAIL_FROM_NAME` config constants |
| [011](issue-011.md) | App: the schema and the data model | App | **implemented & verified** | 001, 003 | `database/schema.sql` — `users`, `categories`, `transactions`, `password_resets`, `email_verifications` — guarded by `database/schema_test.php` |
| [012](issue-012.md) | App: the core runtime | App | **implemented & verified** | 011, 013 | `includes/Database.php`, `includes/functions.php`, `includes/auth.php`, `includes/Router.php` — the helpers every screen uses — guarded by `database/runtime_test.php` |
| [013](issue-013.md) | App: the design-token layer and the shared shell | App | **implemented & verified** | 004 | `assets/css/tokens.css`, `base.css`, `components.css`, `assets/js/app.js`, `includes/header.php`, `includes/footer.php` — guarded by `database/assets_test.php` |
| [014](issue-014.md) | App: the front controller and the routing table | App | **implemented & verified** | 012 | `index.php`, `includes/views/error_404.php` … `error_bootstrap.php`, the `.htaccess` rewrite — guarded by `database/front_controller_test.php` |
| [015](issue-015.md) | App: the install wizard | App | **implemented & verified** | 011, 012, 013 | `install.php`, `assets/css/pages/install.css` — the three-step wizard that writes `config.php` — guarded by `database/install_test.php` |
| [016](issue-016.md) | App: authentication and the mailer | App | **implemented & verified** | 012, 014, 015 | `includes/auth.php`, `views/login.php` … `views/verify_email.php`, `assets/css/pages/auth.css`, the two-sided registration gate — guarded by `database/auth_test.php` |
| [017](issue-017.md) | App: the dashboard | App | **implemented & verified** | 013, 014, 016 | `views/dashboard.php`, `assets/css/pages/dashboard.css` — the period tiles, the recent card, the empty states — guarded by `database/dashboard_test.php` |
| [018](issue-018.md) | App: the transaction list and the transaction form | App | **implemented & verified** | 013, 014, 016 | `views/transactions.php`, `views/transaction_form.php`, `views/transaction_save.php`, `views/transaction_delete.php`, `assets/css/pages/transactions.css` — the filters, the presets, the table, the validation — guarded by `database/transactions_test.php` |
| [019](issue-019.md) | App: the reports | App | **implemented & verified** | 013, 014, 016 | `views/reports.php`, `assets/css/pages/reports.css` — the period selector, the "Over time" and "By category" tables — guarded by `database/reports_test.php` |
| [020](issue-020.md) | App: the categories and the settings | App | **implemented & verified** | 013, 014, 016 | `views/categories.php`, `views/category_save.php`, `views/category_delete.php`, `views/settings.php`, `assets/css/pages/categories.css`, `assets/css/pages/settings.css` — the category list, the profile, the read-only installation card — guarded by `database/categories_test.php` and `database/settings_test.php` |
| [021](issue-021.md) | Security: the sign-in throttle is a denial of service | Security | **implemented & verified** | 011, 012, 016 | the decaying two-counter backoff in `includes/auth.php`, the `scope` column in `login_attempts`, the migration, and the source key that follows the **connecting peer** — guarded by `database/auth_test.php` and `database/live/live_security_test.php` |
| [022](issue-022.md) | Security: the unauthenticated endpoints that cost the server something | Security | **implemented & verified** | 016 | the neutral answer, the **outbox** and its CLI worker, the two request limiters, the bounded SMTP transport, and the sanitised log — guarded by `database/auth_test.php` and `database/live/live_security_test.php` |
| [023](issue-023.md) | Security: the web root still serves the deployment topology | Security | **open** | 003 | the deny block for `docker-compose.yml`, `config.template.php` and the internal documentation — and the hardening test that pins the leak in place |
| [024](issue-024.md) | Security: the response-hardening headers | Security | **open** | 013, 014, 015 | `Content-Security-Policy`, a conditional `Strict-Transport-Security`, `Permissions-Policy`, and the installer's missing headers |
| [025](issue-025.md) | Security: the installer keeps the credentials in the session store | Security | **open** | 010, 012, 015 | the database and SMTP passwords in clear text in `/tmp/sess_*`, under an attacker-chosen file name |
| [026](issue-026.md) | Requirements: the average is missing | Requirements | **open** | 007, 019 | the averaging unit, the calendar denominator, the rounding identity, and the mockup that must be extended first |

> **The Application track has its first seven issues in.** Issue 011 — the
> schema and the data model — is **implemented and verified**:
> `database/schema.sql` creates the five tables, and
> `database/schema_test.php` guards the column types, the unique keys, the
> indexes and the foreign-key delete rules against `information_schema`.
> Issue 012 — the core runtime — is **implemented and verified**: the PDO
> singleton, the helpers, the session and the route table exist, and
> `database/runtime_test.php` guards them. Issue 013 — the design-token layer
> and the shared shell — is **implemented and verified**: the three CSS layers
> are carried over from the mockup reference verbatim, `assets/js/app.js`
> holds every behaviour, `includes/header.php` and `includes/footer.php`
> render the two shells, and `database/assets_test.php` guards all of it.
> Issue 014 — the front controller — is **implemented and verified**:
> `index.php` holds the install gate, the security headers and the error
> boundary, `includes/views/` holds the four error pages and the bootstrap
> page, the `.htaccess` rewrite routes everything that is not a real file to
> the controller, and `database/front_controller_test.php` guards all of it —
> including a sandbox tier that runs the real controller through the gate, the
> bootstrap failure, the 404, the 405 and the 500.
> Issue 015 — the install wizard — is **implemented and verified**:
> `install.php` collects the database, the mail and the access settings across
> three steps, proves each one before it advances, loads the schema, writes
> `config.php` atomically with `var_export()`, parse-checks the file it wrote
> and refuses to run once `config.php` exists. `database/install_test.php`
> guards all of it — including a sandbox tier that walks the real wizard
> through all three steps and checks the written file.
> Issue 016 — authentication and the mailer — is **implemented and verified**:
> `includes/auth.php` holds the session, the throttle, the token helpers and
> the credential version; `views/` holds the six auth screens; and
> `assets/css/pages/auth.css` is the one stylesheet they share.
> `database/auth_test.php` guards all of it — a structural tier that reads the
> sources and a behaviour tier that runs the real screens in a sandbox against
> a scratch database, with a stub mailer standing in for SMTP. Issue 017 — the
> dashboard — is **implemented and verified**: `views/dashboard.php` holds the
> three tiles, the recent card and the three empty states, the four shared
> helpers live in `includes/functions.php`, and
> `assets/css/pages/dashboard.css` is the stylesheet the screen needs.
> `database/dashboard_test.php` guards all of it — a source tier and a
> sandbox tier that runs the real screen against a scratch database.
> Issue 018 — the transaction list and the transaction form — is **implemented
> and verified**: `views/transactions.php` holds the filter bar, the presets,
> the table and the three states, `views/transaction_form.php` serves the add
> and the edit screen from **one** template, `views/transaction_save.php` and
> `views/transaction_delete.php` are the two write paths, the new helpers live
> in `includes/functions.php` and `assets/css/pages/transactions.css` is the
> stylesheet the screens share. `database/transactions_test.php` guards all of
> it — a source tier and a sandbox tier that runs the real screens against a
> scratch database. Issue 020 — the categories and the settings — is
> **implemented and verified** too: `views/categories.php` holds the list, the
> create form, the empty state, the server-rendered rename and the delete
> dialogs, `views/category_save.php` and `views/category_delete.php` are the
> two write paths, `views/settings.php` serves the profile, the password and
> the read-only installation card, and
> `assets/css/pages/categories.css` and `assets/css/pages/settings.css` are
> the two stylesheets the screens need. `database/categories_test.php` and
> `database/settings_test.php` guard all of it — a source tier and a sandbox
> tier each. Issue 019 — the reports — is **implemented and verified** as
> well: `views/reports.php` holds the period selector, the custom-range form,
> the status line, the three tiles and the two tables, the eight report
> helpers live in `includes/functions.php`, and
> `assets/css/pages/reports.css` is the stylesheet the screen needs.
> `database/reports_test.php` guards all of it — a source tier and a sandbox
> tier that runs the real screen against a scratch database. **The mockup's
> own figures did not reconcile**, so `docs/mockup/08-reports.html` was
> corrected to a set that does, and three of the verification recipes were
> corrected with it.

> **A pre-release security audit was carried out on 2026-10-28**, against the
> running application rather than against the sources alone. **The SQL
> injection audit came back clean** — no value is interpolated into SQL
> anywhere in `index.php`, `install.php`, `includes/` or `views/`; the only
> interpolated SQL fragment is the bucket expression, which is chosen from four
> literal strings. The **IDOR audit came back clean** — every statement filters
> on the owning user id, and a delete selects the row first, with the
> ownership predicate, and answers 404 when it is not there. The **XSS audit
> came back clean** — every dynamic value goes through `e()`, and
> `assets/js/app.js` has no `innerHTML`, no `eval` and no `fetch`. The
> **CSRF audit came back clean** — all twelve POST forms carry the token, the
> three GET forms carry none, and every handler validates before it reads.
> The **open-redirect audit came back clean** — `safeRedirectTarget()` and
> `consumeIntendedUrl()` reject anything that is not a known route.
> The **session audit came back clean** — the id is regenerated on sign-in and
> sign-out, the cookie is `httponly` and `samesite=Lax`, and the credential
> version ends the other sessions on a password change. The **registration
> gate is two-sided** — the GET renders no form and the POST is refused
> before the CSRF check and before any input is read.
> Six findings were **confirmed live** and are written up as
> [021](issue-021.md) – [025](issue-025.md): the sign-in throttle is a
> denial-of-service weapon; the registration and password-reset endpoints are
> unthrottled and leak account existence; the web root still serves
> `docker-compose.yml` — **with the default credentials in it** — and the
> hardening test asserts that it does; the response headers are thin and the
> installer sends none at all; and the installer writes the database and SMTP
> passwords into the session store, which is a **clear-text file in the
> shared `/tmp`** under a **file name the client chooses**.
> **Issue 021 is implemented and verified**: the hard lock is now a decaying
> exponential backoff with two counters, the increment happens in SQL, the
> account's lock columns are dropped, the table is pruned, and the locked
> screen states the wait that is really left. **The implementation found one
> thing the audit did not** — LiteSpeed **rewrites `REMOTE_ADDR` from
> `X-Forwarded-For`**, so the source key is built from the address that
> really connected (`PROXY_REMOTE_ADDR`) and the header cannot move the
> bucket.
> **Issue 022 is implemented and verified**: the forgot-password screen now
> answers **byte-identically** for a known and an unknown address in every
> state, the mail is **queued** and sent by a **CLI worker** instead of the
> request, the SMTP transport is **bounded**, `/register` and
> `/forgot-password` are **throttled** per source and per submitted address,
> and the log carries the **context** of a failure and never the address.
> **The implementation found one thing the audit did not** — the two limiters
> of the reset screen have to be consulted in **one** call, or a request the
> address counter refused still extends the source window.
> The remaining three findings are still open.
> A requirement check against [docs/About.md](../About.md) found the
> application **meets every claim in the brief except one**: the brief says
> *"search and sum/average in time period like day, month, weeks, year"*, the
> search and the sum are implemented, and **the average is implemented
> nowhere**. That is [issue-026](issue-026.md).

---

## The mockup file inventory

The file names are **fixed**. The design issues specify them; the application
implementation will mirror them.

| File | Screen | Specified by |
|---|---|---|
| `index.html` | Gallery | issue-004 |
| `00-design-system.html` | Colour, type, spacing, components, states | issue-004 |
| `01-login.html` | Sign in | issue-005 |
| `02-register.html` | Create account | issue-005 |
| `03-register-closed.html` | Registration disabled | issue-005 |
| `04-forgot-password.html` | Request a reset link | issue-005 |
| `05-dashboard.html` | Dashboard and period summary | issue-006 |
| `06-transactions.html` | Transaction list, search and filters | issue-006 |
| `07-transaction-form.html` | Add / edit a transaction | issue-006 |
| `08-reports.html` | Day / week / month / year reports | issue-007 |
| `09-categories.html` | Category management | issue-007 |
| `10-settings.html` | Profile and settings | issue-007 |
| `11-install-wizard.html` | First-run installer | issue-008 |

The delivery contract for these files is in
[`docs/mockup/README.md`](../mockup/README.md).

---

## The outbox worker — required, not optional

Since [issue-022](issue-022.md) the application **queues** the mail it would
send and a **CLI worker** sends it. The request path never talks to the SMTP
server, so **an installation that never runs the worker never sends mail**.

| Piece | Where |
|---|---|
| the queue | `mail_outbox` in `database/schema.sql` (and `database/migrations/20261030_mail_outbox_and_request_throttles.sql`) |
| the queue API | `includes/outbox.php` |
| the worker | `database/outbox_worker.php` |

````bash
# Send everything that is waiting, up to 20 messages, and prune the tables.
docker compose exec -T app php /var/www/html/database/outbox_worker.php --limit=20

# The same, quietly — for a cron entry.
docker compose exec -T app php /var/www/html/database/outbox_worker.php --quiet
````

Run it **from cron** (every minute or every five minutes is enough for a
personal installation). The worker refuses to run under a web server, claims
each row exclusively (`SELECT … FOR UPDATE SKIP LOCKED`), re-uses the row when
a send fails, backs off between the attempts and prunes the rows it no longer
needs. The **queue depth** is shown in the installation card of `/settings`,
so a backlog is visible without reading a log.

---

## How the tracks meet

The mockups are not an illustration of the application — they are its
**specification of appearance**, and the two tracks are joined at two points.

### Point 1 — the design tokens

The mockup's `assets/tokens.css` and the application's
`assets/css/tokens.css` must declare the **same names in the same order**.

| Mockup | Application |
|---|---|
| `assets/tokens.css` → `:root` | `assets/css/tokens.css` → `:root` |
| `assets/base.css` | `assets/css/base.css` |
| `assets/components.css` | `assets/css/components.css` |
| a screen `NN-name.html` | `assets/css/pages/<page>.css` + the PHP page |

The load order is fixed: **tokens → base → components → page**.

> **Satisfied.** Issue 013 carried the three layers over from
> `docs/mockup/assets/` verbatim — only the header path comment differs, and
> the reference is untouched. `database/assets_test.php` diffs the token
> inventory against the reference and fails if a name is missing, invented or
> out of order. The page stylesheets arrive with their screen (issue-015 →
> `install.css`, issue-016 → `auth.css`, issue-017 → `dashboard.css`,
> issue-018 → `transactions.css`, issue-019 → `reports.css`, issue-020 →
> `categories.css` and `settings.css`).

> **Resolved.** The mockup introduces `--surface` and `--surface-2` so that
> the page background and the card surface can differ in dark mode. The
> decision was to **adopt both names** — issue-004 §6.1 required an explicit
> choice, and the neutral ramp is inverted in dark mode, so the two surfaces
> need distinct tokens rather than a derived shade. The application must
> declare the same two names in the same position in its token block.

### Point 2 — the data shape

Every mockup uses placeholder data with the **exact shape** the application
must produce: a signed, server-formatted amount; a `DATE` (not a timestamp);
a period label that respects the **Monday** week start; a nullable category.
The implementation copies the shape, not the text.

---

## Reading order

| If you are… | Read |
|---|---|
| picking up the DevOps work | 001 → 002 → 003 → 009 → 010 — **all implemented and verified** |
| picking up the UX/UI work | 004 → 005 → 006 → 007 → 008 — **all implemented and verified** |
| writing the application | 001, 003, 009 (the runtime), 010 (the dependencies and the mailer), then 004 → 008 (the design), then `AGENTS.md` and `docs/About.md`, then **011 → 020** (the application specification) |
| starting the application from the schema | **011** (the schema) → **012** (the runtime) → **013** (the tokens and the shell) → **014** (the front controller) → **015** (the installer) |
| building the screens | **016** (authentication) → **017** (the dashboard) → **018** (transactions) → **019** (the reports) → **020** (categories and settings) |
| reviewing security | 003 (the web root), 005 (`ALLOW_REGISTRATION`), 008 (the installer), 009 (the PHP patch floor), 010 (the vendored dependency tree), 015 (the install gate), 016 (the two-sided registration gate), 020 (the installation card) |
| fixing the findings of the 2026-10-28 audit | **021** (the throttle is a DoS — **done**) → **022** (the unthrottled endpoints — **done**) → **023** (the web root) → **024** (the headers) → **025** (the installer's secrets). All five are independent of each other. |
| closing the gap against the brief | **026** — but read **007** (the mockup contract) and **019** (the reports screen) first; 026 changes the design *and* the screen. |
| adding a third-party PHP library | 010 (Composer as a vendor-time tool) |

### The application track, in order

| Issue | Builds | Depends on |
|---|---|---|
| [011](issue-011.md) | `database/schema.sql` | the runtime and the web-root rules |
| [012](issue-012.md) | `includes/Database.php`, `includes/functions.php`, `includes/auth.php`, `includes/Router.php` | 011 |
| [013](issue-013.md) | `assets/css/`, `includes/header.php`, `includes/footer.php` | the mockup's `assets/` layer |
| [014](issue-014.md) | `index.php` | 012 |
| [015](issue-015.md) | `install.php`, `assets/css/pages/install.css` | 011, 012, 013 |
| [016](issue-016.md) | `includes/auth.php`, the auth screens | 012, 014, 015 |
| [017](issue-017.md) | `views/dashboard.php`, `assets/css/pages/dashboard.css` | 013, 014, 016 |
| [018](issue-018.md) | `views/transactions.php`, `views/transaction_form.php`, `views/transaction_save.php`, `views/transaction_delete.php`, `assets/css/pages/transactions.css` | 013, 014, 016 |
| [019](issue-019.md) | `views/reports.php`, `assets/css/pages/reports.css` | 013, 014, 016 |
| [020](issue-020.md) | `views/categories.php`, `views/category_save.php`, `views/category_delete.php`, `views/settings.php`, `assets/css/pages/categories.css`, `assets/css/pages/settings.css` | 013, 014, 016 |

### The security and requirements issues, in order

| Issue | Fixes | Depends on |
|---|---|---|
| [021](issue-021.md) | the identifier-keyed lockout, the lost count under concurrency, the unbounded `login_attempts` table, the lock columns that are never read | 011, 012, 016 — **implemented & verified** |
| [022](issue-022.md) | the registration and reset floods, the forgot-password enumeration oracle, the PII in the error log | 016 — **implemented & verified** |
| [023](issue-023.md) | the deny block for `docker-compose.yml`, `config.template.php` and the internal documentation, and the hardening test that pins the leak in place | 003 |
| [024](issue-024.md) | `Content-Security-Policy`, a conditional `Strict-Transport-Security`, `Permissions-Policy`, and the installer's missing headers | 013, 014, 015 |
| [025](issue-025.md) | the database and SMTP passwords in clear text in `/tmp/sess_*`, under an attacker-chosen file name | 010, 012, 015 |
| [026](issue-026.md) | the missing **average** — the averaging unit, the calendar denominator, the rounding identity, and the mockup that must be extended first | 007, 019 |

> **Two known contradictions between the issues**, both flagged in place:
> issue-012's route table listed `GET /transactions/{id}/edit`, which
> issue-018 §3 replaces with `GET /transactions/edit?id=<id>` — **resolved**:
> issue-012's table now carries the query-parameter form, because the router
> matches exactly and has no placeholder syntax; and the mockups' figures do
> not reconcile — issue-019 §13 lists the four contradictions and the
> corrected figure set, and `docs/mockup/08-reports.html` now carries the
> corrected set.

> The DevOps track is done: the stack runs **on PHP 8.5.12**, the mockup viewer
> runs, and the web root is hardened with a regression test that guards it. The
> UX/UI track is done too: `docs/mockup/` holds the design-system reference page,
> all twelve screens and the shared `assets/` layer, and every screen fits a
> 320 px viewport without sideways scrolling. Issue 010 is **implemented and
> verified** — `composer.json` and `composer.lock` are tracked, `vendor/` is
> git-ignored and never served, and `includes/mailer.php` is guarded by
> `database/mailer_test.php`.
>
> **The Application track has all ten issues in.** Issue 011 — the schema
> and the data model — is **implemented and verified**: `database/schema.sql`
> creates the five tables and `database/schema_test.php` guards them against
> `information_schema`. Issue 012 — the core runtime — is **implemented and
> verified**: `includes/Database.php`, `includes/functions.php`,
> `includes/auth.php` and `includes/Router.php` are the layer every screen is
> built on, and `database/runtime_test.php` guards them. Issue 013 — the
> design-token layer and the shared shell — is **implemented and verified**:
> the three CSS layers are carried over from `docs/mockup/assets/` verbatim,
> `assets/js/app.js` holds every behaviour, `includes/header.php` and
> `includes/footer.php` render the two shells, and `database/assets_test.php`
> guards all of it. Issues 014–020 are **implemented and verified** too:
> `index.php` is the front controller, `install.php` is the wizard,
> `views/` holds the six auth screens, `views/dashboard.php` the first
> application screen, `views/transactions.php` the second,
> `views/reports.php` the third and `views/categories.php` and
> `views/settings.php` the last two — each guarded by its own regression test.
> The repository itself is still mostly greenfield — **all five screens
> exist**; what is left is the user's own data, not more code.

---

## Conventions

- One issue, one topic. If an issue grows a second objective, split it.
- Every issue ends with **Acceptance criteria**, **Verification**,
  **Rollback** and **Out of scope**. Keep that structure.
- Verification is a **command block** that can be pasted into a terminal. If
  it cannot be pasted, it is not verification.
- The repository's own rules live in [`AGENTS.md`](../../AGENTS.md) and in
  [`.github/instructions/`](../../.github/instructions/). They override
  anything an issue says when the two disagree — and the issue must be
  updated to match.
