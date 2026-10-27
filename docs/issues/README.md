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
| **Application** | Dev | [011](issue-011.md) – [020](issue-020.md) | The application itself: the schema, the runtime, the design-token layer, the front controller, the installer, the authentication, and the five screens. **Issues 011–013 are implemented & verified; issues 014–020 are specification only.** |

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
| [014](issue-014.md) | App: the front controller and the routing table | App | **specification** | 012 | `index.php` — the install gate, the route table, the 404 and 403 pages |
| [015](issue-015.md) | App: the install wizard | App | **specification** | 011, 012, 013 | `install.php` — the three-step wizard that writes `config.php` |
| [016](issue-016.md) | App: authentication and the mailer | App | **specification** | 012, 014, 015 | `includes/auth.php`, the sign-in / register / reset screens, the two-sided registration gate |
| [017](issue-017.md) | App: the dashboard | App | **specification** | 013, 014, 016 | `views/dashboard.php` — the period tiles, the recent card, the empty states |
| [018](issue-018.md) | App: the transaction list and the transaction form | App | **specification** | 013, 014, 016 | `views/transactions.php`, `views/transaction_form.php` — the filters, the presets, the table, the validation |
| [019](issue-019.md) | App: the reports | App | **specification** | 013, 014, 016 | `views/reports.php` — the period selector, the "Over time" and "By category" tables |
| [020](issue-020.md) | App: the categories and the settings | App | **specification** | 013, 014, 016 | `views/categories.php`, `views/settings.php` — the category list, the profile, the read-only installation card |

> **The Application track has its first three issues in.** Issue 011 — the
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
> Issues 014–020 describe the code that has to be written; none of it exists
> yet. `index.php`, `install.php` and `views/` are still `[TODO]` in
> [`AGENTS.md`](../../AGENTS.md).

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
| adding a third-party PHP library | 010 (Composer as a vendor-time tool) |

### The application track, in order

| Issue | Builds | Depends on |
|---|---|---|
| [011](issue-011.md) | `database/schema.sql` | the runtime and the web-root rules |
| [012](issue-012.md) | `includes/Database.php`, `includes/functions.php`, `includes/auth.php`, `includes/Router.php` | 011 |
| [013](issue-013.md) | `assets/css/`, `includes/header.php`, `includes/footer.php` | the mockup's `assets/` layer |
| [014](issue-014.md) | `index.php` | 012 |
| [015](issue-015.md) | `install.php` | 011, 012, 013 |
| [016](issue-016.md) | `includes/auth.php`, the auth screens | 012, 014, 015 |
| [017](issue-017.md) | `views/dashboard.php` | 013, 014, 016 |
| [018](issue-018.md) | `views/transactions.php`, `views/transaction_form.php` | 013, 014, 016 |
| [019](issue-019.md) | `views/reports.php` | 013, 014, 016 |
| [020](issue-020.md) | `views/categories.php`, `views/settings.php` | 013, 014, 016 |

> **Two known contradictions between the issues**, both flagged in place:
> issue-012's route table listed `GET /transactions/{id}/edit`, which issue-018
> §3 replaces with `GET /transactions/edit?id=<id>` — **resolved**: issue-012's
> table now carries the query-parameter form, because the router matches
> exactly and has no placeholder syntax; and the mockups' figures do not
> reconcile — issue-019 §13 lists the four contradictions and the
> corrected figure set.

> The DevOps track is done: the stack runs **on PHP 8.5.12**, the mockup viewer
> runs, and the web root is hardened with a regression test that guards it. The
> UX/UI track is done too: `docs/mockup/` holds the design-system reference page,
> all twelve screens and the shared `assets/` layer, and every screen fits a
> 320 px viewport without sideways scrolling. Issue 010 is **implemented and
> verified** — `composer.json` and `composer.lock` are tracked, `vendor/` is
> git-ignored and never served, and `includes/mailer.php` is guarded by
> `database/mailer_test.php`.
>
> **The Application track has its first three issues in.** Issue 011 — the schema
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
> guards all of it. Issues 014–020 are the specification a developer picks up
> to build `index.php`, `install.php` and `views/`. The repository itself is
> still mostly greenfield — those files do not exist yet.

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
