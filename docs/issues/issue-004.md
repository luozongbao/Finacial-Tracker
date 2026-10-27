# Issue-004 — UX/UI: mockup charter — information architecture, design tokens, shared shell

> **Status:** ready for UX/UI pickup.
> **Target:** v0.1 (design foundation).
> **Severity:** **n/a** — design deliverable, no application impact.
>
> **Requested by the user:**
> > "alsow write issue files for UX/UI design to write mockup html in /docs/mockup/"
>
> **Depends on:** nothing. This issue defines the system the other UX issues build on.
> **Related:** [issue-002.md](issue-002.md) (how the mockups are served),
> [docs/mockup/README.md](../mockup/README.md) (the delivery contract).

---

## Table of contents

1. Objective
2. Scope of the mockup programme
3. Constraints
4. Product model the design must express
5. Screen inventory and file map
6. The design system
7. The shared shell
8. Accessibility requirements
9. Responsive requirements
10. Acceptance criteria
11. Verification
12. Handoff to the application implementer
13. Out of scope

---

## 1. Objective

> Establish the **information architecture**, the **design tokens** and the
> **shared shell** for the Financial Tracker, and deliver them as the first
> two mockup files — so that every later screen is an instance of one system
> rather than a new invention.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `00-design-system.html` shows every token, every component and every state |
| 2 | `index.html` is a gallery linking every screen, with a status badge per screen |
| 3 | The navigation model is decided and consistent across all screens |
| 4 | Every colour, size and radius in every mockup comes from a token |
| 5 | The token **names** match the application's `assets/css/tokens.css` contract |
| 6 | Light and dark mode are both designed, not one derived from the other |
| 7 | The shell is delivered as reusable CSS, not copied into each screen |

---

## 2. Scope of the mockup programme

The UX/UI track delivers **static HTML mockups** in `docs/mockup/`. It does
not write application code.

| Issue | Deliverable | Screens |
|---|---|---|
| **issue-004 (this)** | Charter, tokens, shell, gallery | `index.html`, `00-design-system.html` |
| [issue-005.md](issue-005.md) | Authentication | `01-login.html`, `02-register.html`, `03-register-closed.html`, `04-forgot-password.html` |
| [issue-006.md](issue-006.md) | Dashboard and transactions | `05-dashboard.html`, `06-transactions.html`, `07-transaction-form.html` |
| [issue-007.md](issue-007.md) | Reports, categories, settings | `08-reports.html`, `09-categories.html`, `10-settings.html` |
| [issue-008.md](issue-008.md) | Installer | `11-install-wizard.html` |

The mockups are the **specification** the application is built from. Where a
mockup and the written issues disagree, the mockup wins — but it must be
raised back into an issue first.

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| **Static HTML, no build step, no framework, no npm** | Vanilla CSS and vanilla JS only. The container has no `node`. |
| The application is **PHP server-rendered** | The mockup must be achievable with server-rendered HTML: no client-side router, no SPA shell, no client-side state store. |
| The application has **no bundler and no asset pipeline** | One shared stylesheet (`assets/css/components.css`) plus per-page stylesheets. The mockup mirrors that layering. |
| **Amounts are money** | `DECIMAL(14,2)` in the database. The UI must format with `number_format()` semantics — thousands separators, exactly two decimals, and a currency symbol. Never show a raw float. |
| **Sums are computed in SQL** | The UI displays sums; it must never recompute them in the browser. A total on screen is a value the server sent. |
| Dates are stored as **`DATE` in UTC** | The mockup shows a date, a period label and a week-start rule. The week starts on **Monday** (`WEEK_START = 1`). |
| `ALLOW_REGISTRATION` is a **real boolean** | The "registration closed" state is a first-class screen (`03-register-closed.html`), not a footnote. |
| The application has **no client-side framework** | Interactive behaviour in a mockup is illustrative only: it must be understandable without running it. |
| Screens must render from **`file://`** | All asset paths are relative. |

---

## 4. Product model the design must express

The product brief (`docs/About.md`) is one sentence: *record spending and
income, then search and sum over a time period — day, week, month, year.*

Everything in the design follows from that.

| Concept | What the UI must make obvious |
|---|---|
| **One ledger, two directions** | Income and spending live in **one** list, distinguished by a direction, not by two separate screens. The design must make the sign of an amount unmistakable at a glance. |
| **A transaction has a date** | The date is a first-class field, not a timestamp buried in a detail view. A transaction is "on" a day. |
| **Periods are relative** | "Today", "this week", "this month", "this year" are the primary controls; a custom range is the secondary one. The week starts on **Monday**. |
| **Sum is the output** | Every period view ends in a total. Income, spending and **net** are always presented together — never one alone. |
| **Categories are optional** | An entry may be uncategorised. The design must not punish that. |
| **The owner is implicit** | A transaction belongs to the signed-in user. The design must never show another user’s data, and must not offer a user switcher. |
| **Registration can be closed** | The operator can disable registration. The UI must handle a closed register page gracefully. |

---

## 5. Screen inventory and file map

| File | Screen | Issue |
|---|---|---|
| `index.html` | Gallery / table of contents | issue-004 |
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

**File naming is fixed** — the later issues and the application implementation
refer to these names. Two digits, a hyphen, kebab-case, `.html`.

### Navigation model

````text
                    ┌──────────────┐
                    │  Dashboard   │  ← default landing page
                    └──────┬───────┘
                           │
       ┌───────────────────┼───────────────────┐
       │                   │                   │
┌──────▼──────┐     ┌──────▼──────┐     ┌──────▼──────┐
│ Transactions│     │   Reports   │     │  Categories │
└──────┬──────┘     └─────────────┘     └─────────────┘
       │
       │ (modal or page, per the UX decision)
┌──────▼──────────┐
│ Transaction form│
└─────────────────┘

Auth (unauthenticated):  Login · Register · Forgot password
Account (everywhere):    Settings · Sign out
Install (unauthenticated, no session): Install wizard
````

Decisions the design must settle and document in `00-design-system.html`:

1. Is the transaction form a **page** or a **modal**? A page is simpler to
   implement in PHP and is more accessible. Default recommendation: a page.
2. Is the primary navigation a **top bar**, a **sidebar** or a **bottom bar**
   (mobile)? Default recommendation: a top bar on desktop, a bottom bar under
   640 px.
3. Does the dashboard **replace** the transactions list, or does the
   dashboard embed a short recent list? Default recommendation: the dashboard
   shows a **recent** list with a link to the full list.

---

## 6. The design system

`00-design-system.html` is the reference page. It must contain, in this order:

### 6.1 Colour

The token names are **fixed by the application convention**
(`.github/instructions/frontend.instructions.md`):

| Group | Tokens | Meaning |
|---|---|---|
| Brand | `--brand-100`, `--brand-500`, `--brand-700` | Primary actions, active navigation, focus. |
| Neutral | `--gray-100` … `--gray-900` | Surfaces, borders, secondary text. |
| Success | `--success-100`, `--success-500`, `--success-700` | **Income**, positive totals, confirmation. |
| Warning | `--warning-100`, `--warning-500`, `--warning-700` | Soft limits, destructive-but-cancellable. |
| Danger | `--danger-100`, `--danger-500`, `--danger-700` | **Spending**, negative totals, destructive confirmation. |
| Info | `--info-100`, `--info-500`, `--info-700` | Neutral information, tips, empty states. |
| Surfaces | `--surface`, `--surface-2` | **Proposed extension** — see below. |

> **The neutral ramp inverts in dark mode.** `--gray-100` is the lightest step
> in light mode and the darkest step in dark mode. That means it cannot double
> as "the page background" and "a card surface". The mockup skeleton
> therefore introduces `--surface` and `--surface-2`.
>
> **Action required:** either the application's `assets/css/tokens.css`
> adopts these two names, or the application uses a different convention —
> in which case the mockups must be updated before implementation. This must
> be settled in issue-004 and recorded in the design-system page.

**Semantic colour mapping is not optional:**

| Meaning | Colour |
|---|---|
| Income / positive amount | `--success-*` |
| Spending / negative amount | `--danger-*` |
| Net positive | `--success-*` |
| Net negative | `--danger-*` |
| Net exactly zero | neutral (`--gray-*`) — **never** green or red |
| Info / hint | `--info-*` |
| Destructive action | `--danger-500` button, `--danger-100` background |

### 6.2 Typography

| Token | Use |
|---|---|
| `--font-sans` | Everything. |
| `--font-mono` | Amounts in tables and on totals — column alignment matters. |
| `--fs-xs` … `--fs-2xl` | The scale. |
| `--lh-tight`, `--lh-normal`, `--lh-loose` | Headings, body, long text. |

Rules:

- An **amount** is always tabular/monospaced so that digits line up in a
  column. This is not decoration — it is how a reader scans a ledger.
- A **total** is larger than the rows above it, and always carries its sign.
- Currency formatting follows the application's `number_format()` output:
  `1,234.50`, with the currency symbol placed consistently (recommendation:
  a leading symbol, `€1,234.50`, matching the server-side formatter).

### 6.3 Spacing, radius, elevation, motion

| Token | Scale |
|---|---|
| `--sp-1` … `--sp-8` | 4, 8, 12, 16, 24, 32, 48, 64 px. |
| `--r-sm`, `--r-md`, `--r-lg`, `--r-full` | 4, 8, 14 px, pill. |
| `--shadow-sm`, `--shadow-md` | Two levels only. |
| `--dur-fast`, `--dur-base` | 120 ms, 200 ms. No other durations. |

### 6.4 Components

The component vocabulary must cover, at minimum:

| Component | Notes |
|---|---|
| **App shell** | Header/nav, page title, content column, footer. |
| **Nav item** | Active, inactive, hover, focus, current-page marker. |
| **Summary tile** | The income / spending / net trio. Amount, label, period. |
| **Amount** | Positive, negative, zero, large, muted. |
| **Badge** | Income / expense / neutral. **Double hyphen**: `.badge--income`. |
| **Button** | Primary, secondary, ghost, danger; small, medium, large; disabled; loading. |
| **Input** | Text, number (amount), date, select, checkbox; label, hint, error, disabled, read-only. |
| **Form field** | Label **above** the control, hint below, error below the hint. |
| **Table** | Header, row, hover, selected, sortable, numeric alignment (right + tabular). |
| **Empty state** | Icon-free, one sentence, one action. |
| **Alert / notice** | Info, success, warning, danger. One per page region. |
| **Modal / drawer** | Only if the transaction form is decided to be a modal. |
| **Period selector** | Today / This week / This month / This year / Custom. |
| **Pagination** | For the transaction list. |
| **Toast / inline confirmation** | After a save or a delete. |

> **Naming trap.** Modifiers use a **double** hyphen — `.btn--primary`,
> `.badge--income`. A single hyphen matches nothing and renders unstyled,
> which is easy to miss because the *status value* uses an underscore
> (`income`, `expense`). This is the exact trap the application front-end
> conventions warn about; do not repeat it.

### 6.5 States

Every component in the design system page must show its states:

`default` · `hover` · `focus-visible` · `active` · `disabled` ·
`loading` · `error` · `empty` · `read-only`

---

## 7. The shared shell

The shell is delivered as **reusable CSS in `docs/mockup/assets/`**, not
copied into each screen.

| File | Role | Rule |
|---|---|---|
| `assets/tokens.css` | Custom properties | **`:root` only. No selectors.** |
| `assets/base.css` | Reset, element defaults, typography | No component styling. |
| `assets/components.css` | The component vocabulary | Linked by every screen. |
| `assets/mockup.js` | Shared behaviour | `init*()` functions registered from one `DOMContentLoaded` listener. |

This mirrors the application's layering exactly
(`tokens.css → base.css → components.css → pages/<page>.css`), so the
mockup and the implementation stay recognisably the same system.

### JavaScript rules for mockups

- **No inline `<script>` blocks.** All behaviour lives in `assets/mockup.js`.
- **Never interpolate data into JavaScript.** Put the value in a `data-`
  attribute and read it with `dataset`.
- **Every `localStorage` access is wrapped in `try/catch`.**
- `navigator.clipboard` does not exist outside a secure context — always
  provide a fallback.
- Mockup interactivity is **illustrative**: a reviewer must understand the
  screen from a still image.

---

## 8. Accessibility requirements

These are acceptance criteria, not aspirations.

| # | Requirement |
|---|---|
| 1 | Every interactive element is reachable by keyboard, with a visible `:focus-visible` style. |
| 2 | Colour is never the **only** carrier of meaning: income/spending also differ by sign, by label and by badge text. |
| 3 | Text contrast is at least **4.5:1** for body text and **3:1** for large text and UI borders — in **both** light and dark mode. |
| 4 | Every form control has a programmatically associated `<label>`. |
| 5 | Errors are identified by text, not by a red border alone, and are announced (e.g. `aria-live="polite"` on the alert region). |
| 6 | Tables use real `<th scope="col">`, and numeric columns are right-aligned. |
| 7 | Icons that carry meaning have a text alternative; decorative icons use `aria-hidden="true"`. |
| 8 | The document has one `<h1>` per screen and a correct heading order. |
| 9 | Touch targets are at least 44 × 44 px on the mobile layout. |
| 10 | Colour-scheme is declared, so the browser's own chrome follows the theme. |

---

## 9. Responsive requirements

| Breakpoint | Layout |
|---|---|
| `< 640 px` | Single column; bottom navigation; tables become stacked cards; the period selector becomes a horizontal scroller. |
| `640–1023 px` | Single column, wider gutters, top navigation. |
| `≥ 1024 px` | Two-column layouts for the dashboard (tiles + recent list); the transaction list may show more columns. |

The design-system page must show **at least one component at two widths**, so
the responsive behaviour is specified rather than improvised per screen.

---

## 10. Acceptance criteria

- [ ] `00-design-system.html` exists, links the three shared stylesheets, and renders standalone from `file://`.
- [ ] Every token in §6 is shown with its light-mode and dark-mode value.
- [ ] Every component in §6.4 is shown in each of its states in §6.5.
- [ ] The **semantic colour mapping** in §6.1 is shown as a table — including the "net zero is neutral" rule.
- [ ] `index.html` lists every file in §5, and shows a status badge per screen (`ready` / `pending`).- [ ] **Gallery rule:** a screen that **exists** is a live `<a href="NN-name.html">`; a screen that does **not yet exist** is rendered as text with `aria-disabled="true"` and a `pending` badge. **When you deliver a screen, flip its gallery entry from text to a link and the badge from `pending` to `ready`.** The gallery is maintained by hand — there is no build step to regenerate it.- [ ] `index.html` lists, per screen, the issue that specifies it.
- [ ] No literal colour, font size, spacing value or radius appears in any mockup CSS outside `tokens.css`.
      The **one** documented exception is the hex column of the token table in
      `00-design-system.html` — §10 requires every token to be shown with its
      light and dark value, and the hex is the content there, not a style.
- [ ] `assets/tokens.css` contains **no selectors other than `:root`** (and its dark-mode `:root` block).
- [ ] Both a light and a dark rendering are shown, side by side, in the design-system page.
- [ ] Every screen the other UX issues deliver uses the shell and the component vocabulary — no screen invents a button style.
- [ ] All of the above hold at 360 px, 768 px and 1280 px viewport widths.

---

## 11. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 1. The files exist and are self-contained
ls -la docs/mockup/
grep -oE 'href="[^"]+"' docs/mockup/index.html

# 2. No absolute asset paths (they break file:// and the static container)
grep -RIn 'href="/\|src="/' docs/mockup/ && echo "FAIL: absolute path found" || echo "OK: all paths relative"

# 3. tokens.css has no selectors other than :root
grep -nE '^\s*[.#a-zA-Z][^{]*\{' docs/mockup/assets/tokens.css | grep -v ':root' && echo "FAIL: selector in tokens.css" || echo "OK: tokens.css is :root-only"

# 4. No literal colours outside tokens.css
#    00-design-system.html is excluded on purpose: that page is the
#    *documentation* of the token values, and §10 requires every token to be
#    shown with its light-mode and dark-mode hex value. The hex there is the
#    content, not a styling decision. Every other file must be token-only.
grep -RInE '#[0-9a-fA-F]{3,8}\b' docs/mockup/assets/base.css docs/mockup/assets/components.css docs/mockup/*.html \
  | grep -v 'docs/mockup/00-design-system.html' \
  && echo "FAIL: literal colour" || echo "OK"

# 5. No inline script blocks
grep -RIn '<script>' docs/mockup/*.html && echo "FAIL: inline script" || echo "OK"

# 6. It renders in the mockup viewer
docker compose up -d mockup
curl -sS -o /dev/null -w "GET / -> %{http_code}\n" http://localhost:${MOCKUP_PORT:-8089}/
````

---

## 12. Handoff to the application implementer

The design system page must end with a **handoff table** that the application
implementation can consume directly:

| Mockup artefact | Application artefact |
|---|---|
| `assets/tokens.css` → `:root` block | `assets/css/tokens.css` (same names, same order) |
| `assets/base.css` | `assets/css/base.css` |
| `assets/components.css` | `assets/css/components.css` |
| A screen `NN-name.html` | `assets/css/pages/<page>.css` + the PHP page |

Rules that survive the handoff:

- The application loads the stylesheets in the order
  `tokens.css → base.css → components.css → pages/<page>.css`.
- The application adds **no** colours of its own; a new colour is a new
  token in `tokens.css`.
- The application escapes every dynamic value with `e()`; a mockup shows a
  literal string, the application shows an escaped one.
- The mockup is the **specification of appearance**; the server remains the
  single source of truth for **behaviour and data**.

---

## 13. Out of scope

- **Writing the application.** The mockups specify what the application must
  look like; they do not implement it.
- **User research and personas.** The product is for personal use; the design
  follows the brief in `docs/About.md`.
- **Brand identity** — logo, name, voice. The product name is "Financial
  Tracker"; the design may propose a wordmark but must not depend on one.
- **Print styles.** Not required for a personal ledger.
- **Localisation.** The copy is English; the layout must not break on longer
  strings, but no translation work is in scope.
- **Animation beyond the two duration tokens.** No motion design system.
