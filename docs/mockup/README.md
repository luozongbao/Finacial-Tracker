# Mockups — contract for the UX/UI team

This folder holds the **static HTML mockups** of the Financial Tracker
interface. They are design artefacts, not application code: no PHP, no build
step, no framework, no npm.

The mockups are what the application is built from. The application itself
lives in the repository root (`/`) and is served by LiteSpeed; **these files
are never served by the application** — they are served by a separate,
read-only static container.

---

## How to view them

````bash
docker compose up -d mockup
# open http://localhost:8089/
````

The mockup container is independent of the application stack: it has no
database, no PHP and no dependency on `app` or `db`. See
[docs/issues/issue-002.md](../../issues/issue-002.md).

While the application stack is running, the same files are also reachable at
`http://localhost:8088/docs/mockup/` — that path is convenient, but the
static container is the sanctioned viewer.

---

## Delivery contract

| Rule | Detail |
|---|---|
| One file per screen | `NN-screen-name.html`, two digits, kebab-case. |
| Self-contained | A mockup must render correctly when opened directly from the file system (`file://`). No server-side includes, no PHP. |
| Shared assets | Shared CSS and JS live in `assets/`. A screen links them with a **relative** path (`assets/tokens.css`, `assets/base.css`, `assets/components.css`, `assets/mockup.js`) so the folder can be moved. |
| No absolute paths | Never reference `/assets/...` — it breaks the `file://` case and the static container's document root. |
| No frameworks | Vanilla CSS and vanilla JS only. No CDN dependency for anything structural; a CDN icon font may be used but the layout must survive without it. |
| Placeholder data only | Never real credentials, real names or real amounts. |
| States must be visible | Empty, loading, validation error, success and permission-denied states are part of the screen, not separate files. |
| Gallery is kept current | When a screen is delivered, its entry in `index.html` becomes a live link and its badge flips from `pending` to `ready`. The gallery is maintained by hand — there is no build step. |
| No links outside this folder | The viewer serves `docs/mockup/` only, so a link to `../issues/…` would 404 in the viewer and 403 in the app container. The gallery names the issues by **number** instead. |

### Planned file inventory

The screens below are specified in the UX/UI issues. File names are fixed —
the implementation issue refers to them.

| File | Screen | Issue |
|---|---|---|
| `index.html` | Gallery / table of contents | [issue-004](../issues/issue-004.md) |
| `00-design-system.html` | Colour, type, spacing, components | [issue-004](../issues/issue-004.md) |
| `01-login.html` | Sign in | [issue-005](../issues/issue-005.md) |
| `02-register.html` | Create account | [issue-005](../issues/issue-005.md) |
| `03-register-closed.html` | Registration disabled state | [issue-005](../issues/issue-005.md) |
| `04-forgot-password.html` | Request a reset link | [issue-005](../issues/issue-005.md) |
| `05-dashboard.html` | Dashboard and period summary | [issue-006](../issues/issue-006.md) |
| `06-transactions.html` | Transaction list, search and filters | [issue-006](../issues/issue-006.md) |
| `07-transaction-form.html` | Add / edit a transaction | [issue-006](../issues/issue-006.md) |
| `08-reports.html` | Day / week / month / year reports | [issue-007](../issues/issue-007.md) |
| `09-categories.html` | Category management | [issue-007](../issues/issue-007.md) |
| `10-settings.html` | Profile and settings | [issue-007](../issues/issue-007.md) |
| `11-install-wizard.html` | First-run installer | [issue-008](../issues/issue-008.md) |

`assets/` holds the shared stylesheet and script. Its exact file names are
fixed in [issue-004](../issues/issue-004.md).

---

## The index gallery

`index.html` is the entry point of the folder: it lists every planned mockup,
states which issue specifies it, and marks each one `ready` or `pending`. A
screen that has been delivered is a **live link**; one that has not is plain
text with a `pending` badge. **When you deliver a screen, flip its gallery
entry to a link and the badge to `ready`** — the gallery is maintained by
hand, because there is no build step. It is the file the DevOps acceptance
test checks (`GET /` returns 200 with the gallery).

---

## Design source of truth

The tokens defined in `00-design-system.html` are the **same** tokens the
application uses (`assets/css/tokens.css`). The application front-end
conventions are in
[.github/instructions/frontend.instructions.md](../../../.github/instructions/frontend.instructions.md):

- colours are referenced by token, never by literal value,
- semantic tints come in a `-100` / `-500` / `-700` triple,
- dark mode is a designed feature, not an accident,
- component modifiers use a **double** hyphen (`.btn--primary`).

A mockup that invents a colour which does not exist as a token is a defect.
