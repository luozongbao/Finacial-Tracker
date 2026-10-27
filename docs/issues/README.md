# Issues — Financial Tracker

Every issue in this folder is a **self-contained specification**. Each one
states the objective, the constraints, the deliverable, the acceptance
criteria and the verification commands, so that it can be picked up by
someone who has not read this repository.

Two tracks run through this folder:

| Track | Owner | Issues | Produces |
|---|---|---|---|
| **DevOps / infrastructure** | DevOps | [001](issue-001.md) · [002](issue-002.md) · [003](issue-003.md) | The running development stack, the mockup viewer, and the web-root hardening. |
| **UX/UI design** | Design | [004](issue-004.md) · [005](issue-005.md) · [006](issue-006.md) · [007](issue-007.md) · [008](issue-008.md) | The static mockups in [`docs/mockup/`](../mockup/), and through them, the design of the application itself. |

---

## Index

| # | Title | Track | Status | Depends on | Key deliverable |
|---|---|---|---|---|---|
| [001](issue-001.md) | DevOps: the Docker Compose development stack | DevOps | **implemented & verified** | — | `docker-compose.yml`, `docker/entrypoint.sh`, `.env.example` |
| [002](issue-002.md) | DevOps: the static mockup viewer container | DevOps | **implemented & verified** | 001 | `docker/mockup-nginx.conf`, the `mockup` compose profile |
| [003](issue-003.md) | DevOps: harden the web root; `config.php` write path; CLI harness path | DevOps | **implemented & verified** | 001 | `.htaccess` deny block, the `/var/www/html` symlink, `database/webroot_hardening_test.php` |
| [004](issue-004.md) | UX/UI: mockup charter — IA, tokens, shared shell | UX/UI | ready for pickup | — | `index.html`, `00-design-system.html`, the `assets/` layer |
| [005](issue-005.md) | UX/UI: authentication screens | UX/UI | ready for pickup | 004 | `01-login.html`, `02-register.html`, `03-register-closed.html`, `04-forgot-password.html` |
| [006](issue-006.md) | UX/UI: dashboard and transaction screens | UX/UI | ready for pickup | 004 | `05-dashboard.html`, `06-transactions.html`, `07-transaction-form.html` |
| [007](issue-007.md) | UX/UI: reports, categories and settings screens | UX/UI | ready for pickup | 004 | `08-reports.html`, `09-categories.html`, `10-settings.html` |
| [008](issue-008.md) | UX/UI: install wizard screen | UX/UI | ready for pickup | 004 | `11-install-wizard.html` |

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

> **Open decision.** The mockup skeleton introduces `--surface` and
> `--surface-2` so that the page background and the card surface can differ
> in dark mode. The application must either adopt these two names or replace
> them — before the first application screen is written. This is recorded in
> issue-004 §6.1.

### Point 2 — the data shape

Every mockup uses placeholder data with the **exact shape** the application
must produce: a signed, server-formatted amount; a `DATE` (not a timestamp);
a period label that respects the **Monday** week start; a nullable category.
The implementation copies the shape, not the text.

---

## Reading order

| If you are… | Read |
|---|---|
| picking up the DevOps work | 001 → 002 → 003 — **already implemented and verified** |
| picking up the UX/UI work | 004 → 005 → 006 → 007 → 008 |
| writing the application | 001, 003 (the runtime), then 004 → 008 (the design), then `AGENTS.md` and `docs/About.md` |
| reviewing security | 003 (the web root), 005 (`ALLOW_REGISTRATION`), 008 (the installer) |

> The DevOps track is done: the stack runs, the mockup viewer runs, and the web
> root is hardened with a regression test that guards it. The UX/UI track is
> still open — `docs/mockup/` holds only the placeholder gallery and the
> shared `assets/` layer.

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
