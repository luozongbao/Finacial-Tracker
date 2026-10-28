# Issue-013 — App: the design-token layer and the shared shell

> **Status:** **implemented and verified** — every acceptance criterion below was
> measured against the running stack on 2026-10-27, and the layer is guarded by
> `database/assets_test.php`.
> **Target:** v0.2 (the application) — the visual foundation every screen renders inside.
> **Severity:** **medium** — a wrong token name renders the component unstyled, and
> the failure is silent.
>
> **Requested by the user:**
> > "I guess, we now have everything, ready for dev, write issue files for dev to
> > implement the project."
>
> **Depends on:** [issue-012.md](issue-012.md) (`asset_url()`, `e()`, the session).
> **Related:** [issue-004.md](issue-004.md) (the design charter and the handoff
> table), [issue-014.md](issue-014.md) (the front controller that renders the shell),
> [issue-016.md](issue-016.md) … [issue-020.md](issue-020.md) (the screens that use the
> components);
> `docs/mockup/assets/` (**the reference implementation — copy it, do not reinvent it**);
> `.github/instructions/frontend.instructions.md` (**the binding conventions**).

---

## Implementation notes — 2026-10-27

The specification and the reference implementation disagreed in six places. In every
case the **reference wins**: `docs/mockup/assets/` is the design, and
`components.css` is carried over unchanged. Each decision is now guarded by
`database/assets_test.php`, so a later screen cannot quietly reintroduce the
name the reference does not use.

| # | The spec said | The reference has | The decision |
|---|---|---|---|
| 1 | `is-active` marks the active link (§8, §9) | `aria-current` — `.nav__link[aria-current="page"]`, `.bottom-nav__link[aria-current="page"]`, `.period__link[aria-current="true"]`, `.stepper__item[aria-current="step"]` | **`aria-current` only.** The active link carries `aria-current="page"`, the active period link `aria-current="true"`. `is-active` appears nowhere in the application. |
| 2 | `alert--info` is in the vocabulary (§10) | `.alert` (the base) plus `--success` `--warning` `--danger` `--neutral` | **The info alert is the base `.alert`.** `components.css` was not touched, and the test asserts that `.alert--info` does not exist. |
| 3 | The transaction row has a colour modifier (`tx--income`, §10) | `.tx` has no modifier; the colour comes from `.amount--income` / `.amount--expense` | **No `.tx--*` class exists.** The row takes `.amount--income` / `.amount--expense` on the amount. |
| 4 | The page stylesheets are listed in §3 | The screen issues each own theirs | **The eight page stylesheets arrive with their screen**, not with this issue. issue-013 creates `assets/css/pages/` and nothing else. |
| 5 | The stored theme is applied before first paint by an inline attribute or a tiny head script (§9) | — | **The theme travels in a cookie.** The server renders `<html data-theme="…">` from a `theme` cookie whitelisted to `dark` / `light`; `app.js` mirrors the value into `localStorage` and writes the cookie back. No inline script, no flash. |
| 6 | The theme snippet removes the attribute when going dark → light (§9) | `:root[data-theme="light"]` is a real block | **The attribute is always set explicitly** and never removed. Removing it hands the page back to the system preference, so a system-dark visitor would stay in dark mode after choosing light. |

Two further facts belong on the record:

- **The `.app` wrapper.** The reference's `components.css` styles `.app` as a flex
  column with `min-height: 100vh`, and `.app__main` as `flex: 1 1 auto` — the
  footer sits at the bottom of the viewport. The shell therefore wraps the bar,
  the main column and the footer in `<div class="app">`; the mobile bottom
  navigation stays outside it, exactly as in the reference mockup.
- **`asset_url()` never cache-busted anything.** The helper in
  [issue-012.md](issue-012.md) built the URL as `/assets/<path>` but looked the
  file up at `__DIR__ . '/../' . $path` — one directory too high. Every stamp was
  `?v=0`. This issue is the first consumer of `asset_url()`, which is where the
  bug surfaced; it is fixed, and issue-012's snippet now shows the corrected
  lookup.

---

## Table of contents

*Implementation notes — 2026-10-27* (the six places where the specification and
the reference disagreed, and how each was resolved)

1. Objective
2. Constraints
3. The asset layout
4. Specification — `assets/css/tokens.css`
5. Specification — `assets/css/base.css`
6. Specification — `assets/css/components.css`
7. Specification — `assets/css/pages/`
8. Specification — `includes/header.php` and `includes/footer.php`
9. Specification — `assets/js/app.js`
10. The component vocabulary
11. Acceptance criteria
12. Verification
13. Rollback
14. Risks
15. Out of scope

---

## 1. Objective

Move the design system from the mockup deliverable into the application, **without
inventing anything**, and wrap it in the shared shell (the app bar, the navigation,
the footer) that every authenticated page renders inside.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `assets/css/tokens.css` has the **same token names** as the mockup's, in the same order |
| 2 | `tokens.css` contains **no selectors** — `:root` custom properties only |
| 3 | `base.css` and `components.css` are the mockup's files, carried over **verbatim** except for the asset paths |
| 4 | The stylesheets load in the order `tokens → base → components → pages/<page>` |
| 5 | The application adds **no colour of its own** — a new colour is a new token |
| 6 | `includes/header.php` and `includes/footer.php` render the app bar and the footer |
| 7 | Every dynamic value in the shell is escaped with `e()` |
| 8 | The table re-layout contract holds: **every `<td>` carries a `data-label`** |
| 9 | `assets/js/app.js` holds every behaviour, with **no inline `<script>`** anywhere |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| **No build step, no bundler, no npm** | The CSS ships as it is written. The `?v=` stamp on the asset URL is the whole cache strategy. |
| The container has **no `node`** | Nothing may require a compile. |
| **No inline `<script>` blocks** | Every behaviour lives in `assets/js/app.js`. |
| **Never interpolate user data into JavaScript** | Put the value in a `data-` attribute and read it with `dataset`. |
| The mockup is the **specification of appearance** | The server is the single source of truth for behaviour and data. |
| Dark mode is a **designed feature**, not a defect | The three dark blocks in `tokens.css` are expected. |
| `overflow-x: clip` on `body` — **not** `hidden` | `hidden` creates a scroll container and breaks `position: sticky`. |
| `< 640 px` → the `<thead>` is hidden and each row becomes a card | Every `<td>` needs `data-label`, or the column names vanish on a phone. |

---

## 3. The asset layout

````text
assets/
  css/
    tokens.css              # the design tokens — :root only
    base.css                # the element reset and the element defaults
    components.css          # the component vocabulary
    pages/
      dashboard.css
      transactions.css
      transaction-form.css
      reports.css
      categories.css
      settings.css
      auth.css
      install.css
  js/
    app.js                  # every behaviour, one DOMContentLoaded listener
  img/                      # (empty) — the app has no raster assets in v0.2
````

This is the layout the handoff table in [issue-004.md](issue-004.md) §12 specifies:

| Mockup artefact | Application artefact |
|---|---|
| `docs/mockup/assets/tokens.css` | `assets/css/tokens.css` — same names, same order |
| `docs/mockup/assets/base.css` | `assets/css/base.css` |
| `docs/mockup/assets/components.css` | `assets/css/components.css` |
| `docs/mockup/NN-name.html` | `assets/css/pages/<page>.css` + the PHP page |

> **Copy, do not rewrite.** `docs/mockup/assets/` is the **reference
> implementation** (`.github/instructions/frontend.instructions.md`). The three files
> are copied across with their content unchanged, and the application adds only
> page-level stylesheets on top.

---

## 4. Specification — `assets/css/tokens.css`

### The rule

**`:root` only. No selectors.** Not even `:root[data-theme=…]` — the dark blocks live
in the file as media queries and attribute selectors on `:root`, and no other
selector may appear.

### The token inventory — use these names verbatim

**Brand**

````css
--brand-100  --brand-500  --brand-700
````

**Grey / surface**

````css
--gray-100  --gray-300  --gray-500  --gray-700  --gray-900
--surface  --surface-2
````

`--surface` is the page background; `--surface-2` is the raised card background.
Both are **adopted** — the design charter lists them as part of the system.

**Semantic** — each family is a `-100` / `-500` / `-700` triple

````css
--success-100  --success-500  --success-700
--warning-100  --warning-500  --warning-700
--danger-100   --danger-500   --danger-700
--info-100     --info-500     --info-700
````

**Typography**

````css
--font-sans  --font-mono
--fs-xs  --fs-sm  --fs-md  --fs-lg  --fs-xl  --fs-2xl
--lh-tight  --lh-normal  --lh-loose
````

**Spacing** — seven steps, and **there is no `--sp-7`**

````css
--sp-1  --sp-2  --sp-3  --sp-4  --sp-5  --sp-6  --sp-8
````

> **A documentation defect to fix in the same change.** The scoped instruction file
> `.github/instructions/frontend.instructions.md` lists the spacing scale as
> `--sp-1..20`. The reference implementation has seven steps and stops at `--sp-8`.
> Correct the instruction file to `--sp-1..8` in this issue — otherwise a developer
> will use a token that does not exist and the declaration will be dropped silently.

**Radius, elevation, motion**

````css
--r-sm  --r-md  --r-lg  --r-full
--shadow-sm  --shadow-md
--dur-fast  --dur-base
````

**Layout**

````css
--content-max: 1100px
--auth-max: 420px
--tap-min: 44px
````

`--tap-min` is the **minimum touch target** — 44 px. Every interactive control must
be at least that tall. It is an accessibility requirement, not a preference.

### The three theme blocks

The file carries **three** dark/light blocks, and all three must be carried across:

````css
:root { /* the light defaults */ }

@media (prefers-color-scheme: dark) { :root { /* … */ } }

:root[data-theme="dark"]  { /* … */ }

:root[data-theme="light"] { /* … */ }
````

| Block | Purpose |
|---|---|
| `:root` | The light defaults. |
| `@media (prefers-color-scheme: dark)` | The **system** preference, honoured when the visitor has expressed no choice. |
| `:root[data-theme="dark"]` | The **explicit** choice, set by the theme toggle in `assets/js/app.js`. It must win over the media query. |
| `:root[data-theme="light"]` | The explicit light choice, for a visitor whose system is dark. |

The order matters: the attribute blocks come **after** the media query so that they
override it at equal specificity.

> **Testing against the reference.** When comparing the application against the
> mockup, emulate `colorScheme: 'light'` **first**. A dark rendering is not a
> defect — it is the designed dark mode.

---

## 5. Specification — `assets/css/base.css`

The element reset and the element defaults, carried over from
`docs/mockup/assets/base.css` unchanged. It styles `html`, `body`, `a`, `code`,
`fieldset`, `h1`–`h4`, `hr`, `img`, `legend`, `p`, `table`, `th`, `td`, `ul`, `ol` and
the `:focus-visible` ring.

Three rules that matter to the application:

1. **The page margin.** The content sits in a capped, gutter-padded container:
   `max-width: var(--content-max); margin: 0 auto;`. Content placed **directly in
   `<body>`** has no page margin — that is the defect this rule catches. The shell
   (issue-013 §8) is what provides it.
2. **`overflow-x: clip` on `body`** — not `hidden`.
3. **A visible `:focus-visible` ring.** Never remove it; the keyboard-navigation
   requirement in [issue-004.md](issue-004.md) §8 depends on it.

---

## 6. Specification — `assets/css/components.css`

The component vocabulary, carried over from `docs/mockup/assets/components.css`
unchanged. The full class list is in §11.

### The diagnostic that catches the most common mistake

> A component computing to `0px none`, `transparent`, or `padding 0px` almost always
> means **the component layer is missing from the cascade**. Check the `<link>` before
> suspecting the CSS itself.

The `<link>` order in `includes/header.php` is therefore **load-bearing** and is part
of the acceptance criteria.

### The naming trap

Modifiers use a **double** hyphen:

````css
.btn--primary   .alert--danger   .badge--income   .tile--net-zero   .tx--income
````

The *status value* uses an **underscore** — `income`, `expense`, `net-positive`.
`.btn--income` and `.btn-income` are two different things, and the single-hyphen
form matches nothing and renders unstyled. This is easy to miss precisely because
the status value uses an underscore.

### The responsive contract

| Range | Layout |
|---|---|
| `< 640 px` | One column, fixed bottom navigation, tables become stacked cards. |
| `640 – 1023 px` | One column, wider gutters, top navigation. |
| `≥ 1024 px` | Capped, centred content. |

The reference implementation's media queries are `@media (max-width: 1023px)` and
`@media (max-width: 639px)`.

### The table re-layout contract — the one that is easy to get wrong

Below 640 px the `<thead>` is hidden and each `<tr>` becomes a card. **Every `<td>`
must carry a `data-label` attribute** holding the column name:

````html
<tr>
    <td data-label="Date">19 October 2026</td>
    <td data-label="Description">Coffee</td>
    <td data-label="Amount" class="num">4.50</td>
</tr>
````

The attribute **is** the contract: without it the column names disappear on a phone
and the row becomes an unlabelled stack of values. This is a hard acceptance
criterion for every table in [issue-018.md](issue-018.md),
[issue-019.md](issue-019.md) and [issue-020.md](issue-020.md).

### Reduced motion

`@media (prefers-reduced-motion: reduce)` is already in the reference
`components.css`. Keep it. The only motion in the product is the two duration
tokens.

---

## 7. Specification — `assets/css/pages/`

One stylesheet per screen, loaded **after** `components.css`. Only page-specific
rules live here — nothing that another page would also need.

| File | Screen |
|---|---|
| `pages/dashboard.css` | The tiles trio and the recent-transactions card. |
| `pages/transactions.css` | The filter bar and the pagination row. |
| `pages/transaction-form.css` | The form layout and the danger zone. |
| `pages/reports.css` | The over-time table, the category table and the share bar. |
| `pages/categories.css` | The category table and the inline edit row. |
| `pages/settings.css` | The profile form, the password form and the read-only installation list. |
| `pages/auth.css` | The sign-in / register / reset card. |
| `pages/install.css` | The install wizard stepper and the summary list. |

A page stylesheet is **optional**. If a screen needs no rules beyond the component
layer, it links no page stylesheet — that is not a defect.

> **Ownership.** The eight files above arrive with the screen that needs them,
> not with this issue: issue-015 → `install.css`, issue-016 → `auth.css`,
> issue-017 → `dashboard.css`, issue-018 → `transactions.css`,
> issue-019 → `reports.css`, issue-020 → `categories.css` and `settings.css`.
> issue-013 creates the **directory** (`assets/css/pages/`, with a `.gitkeep`),
> and the shell links a page stylesheet only when the caller sets `$pageCss`.
> A screen with no page stylesheet yet links nothing — the stamp is `?v=0`,
> which is `asset_url()` saying "that file is not there".

---

## 8. Specification — `includes/header.php`

The shared shell. It renders `<head>`, the app bar, the navigation, and opens
`<main class="app__main">`.

### The contract

````php
<?php
/**
 * header.php — the shared application shell.
 *
 * Contract:
 *   1. $title MUST be set by the caller before this file is included.
 *   2. $active MUST be one of: dashboard | transactions | reports | categories
 *      | settings. It marks the current nav link.
 *   3. Every dynamic value is escaped with e(). There is no exception.
 *   4. The stylesheet order is load-bearing — see issue-013 section 6.
 */
if (!isset($title)) { $title = 'Financial Tracker'; }
if (!isset($active)) { $active = ''; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — Financial Tracker</title>
<link rel="stylesheet" href="<?= e(asset_url('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/components.css')) ?>">
<?php if (!empty($pageCss)): ?>
<link rel="stylesheet" href="<?= e(asset_url('css/pages/' . $pageCss)) ?>">
<?php endif; ?>
</head>
<body>
<header class="app__bar">
  <div class="app__bar-inner">
    <a class="app__brand" href="/dashboard">Financial Tracker</a>
    <nav class="nav" aria-label="Main">
      <a class="nav__link" href="/dashboard" aria-current="page">Dashboard</a>
      …
    </nav>
    <div class="app__spacer"></div>
    <div class="app__user">signed in as <strong><?= e($username ?? '') ?></strong></div>
  </div>
</header>
<main class="app__main">
<?php
````

### The rules

| Rule | Reason |
|---|---|
| `$title` is set by the caller | The shell must not guess. Every page has its own title. |
| `asset_url()` for every stylesheet | Cache-busting. A literal path skips the `?v=` stamp. |
| The **exact** four-link order — Dashboard · Transactions · Reports · Categories | It is the navigation order in every mockup. |
| The stylesheet order is exactly tokens → base → components → page | Load-bearing — see §6. |
| `e()` on the title, the user name and every asset path | No exception, no "safe" subset. |
| The app bar renders **only** for an authenticated user | The auth screens use `includes/auth.php`, a different shell. |
| The mobile bottom navigation is rendered too | `.bottom-nav` / `.bottom-nav__inner` / `.bottom-nav__link` — it appears below 640 px. |

> **The `.app` wrapper.** The reference's `components.css` styles `.app` as a flex
> column with `min-height: 100vh`, and `.app__main` as `flex: 1 1 auto` — the
> footer sits at the bottom of the viewport. The shell therefore wraps the app
> bar, the main column and the footer in `<div class="app">`. The mobile bottom
> navigation stays **outside** that wrapper, exactly as in the reference mockup.
>
> **The `$shell` variable.** The header renders one of two frames, chosen by
> `$shell`: `'app'` (the default) or `'auth'`. `includes/footer.php` closes
> whichever frame was opened, so the two files must always be included
> together.

### The auth shell

The sign-in, register and reset screens use a **different, narrower** shell:

````php
<main class="auth">
  <header class="auth__brand">
    <h1 class="auth__brand-name">Financial Tracker</h1>
    <p class="auth__brand-note">Record income and spending, then sum it over any period.</p>
  </header>
  … the card …
  <footer class="auth__footer">
    <p>Personal finance tracker · your data stays on your server.</p>
  </footer>
</main>
````

It has **no app bar and no nav** — the guest must not see the signed-in navigation.

### `includes/footer.php`

````php
</main>
<footer class="app__footer">
  <p>Financial Tracker · personal use.</p>
</footer>
<script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
````

| Rule | Reason |
|---|---|
| The script is **at the end of the body** | The DOM must exist before `app.js` runs. |
| The script tag is in the **footer**, not the head | Same reason. |
| There is **no inline `<script>`** anywhere | Every behaviour is in `app.js`. |

---

## 9. Specification — `assets/js/app.js`

One file. One `DOMContentLoaded` listener. A set of `init*()` functions registered
from it.

### The required behaviours

| Function | Selector | Behaviour |
|---|---|---|
| `initPasswordReveal()` | `[data-reveal-target]` | Toggles the password field's `type` between `password` and `text`, and flips `aria-pressed`. |
| `initRequiredToggle()` | `[data-disable-until-filled]` + `[data-toggle-submit]` | Disables the submit button until the required fields are filled. |
| `initPeriodMarker()` | `[data-period-group]` + `[data-period]` | Marks the active period link with `aria-current="true"`. |
| `initPeriodPresets()` | `[data-preset-from]` / `[data-preset-to]` | Fills the date inputs from the preset's `data-preset-start` / `data-preset-end`. |
| `initModals()` | `[data-modal-open]` / `[data-modal-close]` | Opens and closes the delete-confirmation modal. |
| `initThemeToggle()` | `[data-theme-toggle]` | Flips `data-theme` on `<html>` between `dark` and `light`. |
| `initCopy()` | `[data-copy-target]` | Copies the target's text to the clipboard. |
| `initActiveMarker()` | `[data-screen]` | Marks the current mockup screen — **the mockup only; do not port it.** |

### The rules

| Rule | Reason |
|---|---|
| **Never interpolate user data into JavaScript.** | Building a JS string by concatenation is an XSS hole **even when the value is numeric**. |
| Put the value in a `data-` attribute and read it with `dataset`. | The PHP side escapes it with `e()` exactly like any other output. |
| The **server** is the single source of truth for any predicate. | Publish it as a `data-` flag and read it in JS. Do not re-derive the rule in JavaScript, and do not weaken the server-side validation to match. |
| Every `localStorage` access is wrapped in `try/catch`. | A sandboxed iframe or private mode throws on access. |
| `navigator.clipboard` needs a fallback. | It does not exist outside a secure context. |
| `init*()` functions are registered from a **single** `DOMContentLoaded` listener. | One entry point. |

### The theme toggle and `localStorage`

````js
function initThemeToggle() {
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    Array.prototype.forEach.call(buttons, function (button) {
        button.addEventListener('click', function () {
            var root = document.documentElement;
            var isDark = root.getAttribute('data-theme') === 'dark';
            // The attribute is ALWAYS set — never removed. Removing it hands
            // the page back to the system preference, so a system-dark
            // visitor would stay dark after choosing light.
            var theme = isDark ? 'light' : 'dark';
            root.setAttribute('data-theme', theme);
            storeTheme(theme);
        });
    });
}
````

`localStorage` is only ever used for the **theme preference**. Nothing sensitive is
stored in the browser: the session is a server-side session, and there is no token
in `localStorage`.

> The stored value is applied to `<html>` **before** first paint. The server
> cannot read `localStorage`, and this application has **no inline script**, so
> the preference travels in a **cookie**: the server renders
> `<html data-theme="dark">` from the `theme` cookie (whitelisted to `dark` or
> `light` — an unknown value is dropped and the system preference applies), and
> `app.js` mirrors the value into `localStorage` and writes the cookie back.
> The `?v=` asset stamp makes a flash acceptable; a wrong theme for a whole
> session is not.

---

## 10. The component vocabulary

Carried over unchanged. The application uses **these** names — a component is
either used with its real name or not used at all.

**Shell** — `app` `app__bar` `app__bar-inner` `app__brand` `app__spacer`
`app__user` `app__main` `app__footer` · `nav` `nav__link` ·
`bottom-nav` `bottom-nav__inner` `bottom-nav__link` · `auth` `auth__brand`
`auth__brand-name` `auth__brand-note` `auth__links` `auth__footer`

**Page** — `page__header` `page__title` `page__subtitle` `page__actions`

**Card** — `card` `card__header` `card__body` `card__footer` `card__title`
`card--flush` `card--muted`

**Tiles** — `tiles` `tiles--2` `tiles--3` `tile` `tile__label` `tile__amount`
`tile__period` `tile--income` `tile--spending` `tile--net` `tile--net-positive`
`tile--net-negative` `tile--net-zero`

**Amounts and badges** — `amount` `amount--income` `amount--expense`
`amount--muted` `amount--zero` `amount--large` · `badge` `badge--income`
`badge--expense` `badge--neutral` `badge--success` `badge--warning` `badge--danger`
`badge--info` `badge--pending` `badge--ready`

**Buttons** — `btn` `btn--primary` `btn--secondary` `btn--ghost` `btn--danger`
`btn--sm` `btn--lg` `btn--block` `btn--loading` `btn-group`

**Forms** — `field` `field__label` `field__hint` `field__error` · `input`
`input--amount` `input--mono` `select` `textarea` · `radio-group` `radio`
`radio--income` `radio--expense` · `switch` `switch__track`

**Tables** — `data-table` `data-table__sort` `table-wrap` `num`

**Transaction row** — `tx` `tx__date` `tx__desc` `tx__desc-title` `tx__desc-meta`
`tx__amount` `tx__actions`

**Feedback** — `alert` `alert--success` `alert--warning`
`alert--danger` `alert--neutral` `alert__title` `alert__body` · `empty` `empty__title` `empty__body` ·
`toast` `notice` `notice--warning` · `modal` `modal__title` `modal__actions`

**States** — `skeleton` `skeleton--tile` `skeleton--title` `skeleton--line`
`skeleton--short` · `state` `state__label` · `spec-note` `spec-note__label`

**Layout** — `period` `period__link` · `filters` `filters__search` `filters__type`
`filters__cat` `filters__from` `filters__to` `filters__actions` `filters__presets` ·
`pagination` `pagination__status` · `readonly-list` `readonly-list__row` `masked` ·
`stepper` `stepper__item` `stepper__num` · `share` · `sr-only`

> `spec-note` and `state` exist for the **mockups** — they label the mockup's own
> states. The application does not render them; the application renders the states
> themselves.
>
> **Two names in this list do not exist in the reference**, and the application
> does not use them: `alert--info` (the info alert is the base `.alert`) and any
> `tx--*` modifier (the transaction row takes `.amount--income` /
> `.amount--expense` on its amount). The vocabulary above is the reference's, not
> an invitation to invent.

---

## 11. Acceptance criteria

- [ ] `assets/css/tokens.css` exists and contains **every** token in §4, with the
      **same names** as the mockup's file.
- [ ] `tokens.css` contains **no selector** other than `:root` (the two
      `:root[data-theme=…]` attribute blocks and the dark media query are allowed).
- [ ] `tokens.css` has the three theme blocks: `:root`, the
      `prefers-color-scheme: dark` media query, `:root[data-theme="dark"]` and
      `:root[data-theme="light"]`, in that order.
- [ ] `assets/css/base.css` and `assets/css/components.css` are carried over from
      `docs/mockup/assets/` **unchanged**, apart from any asset path.
- [ ] The component vocabulary in §10 is present in `components.css` — in
      particular the **double-hyphen** modifiers.
- [ ] The **active** state is expressed with `aria-current` (`page` on the nav
      links, `true` on the period links) — the reference knows no `is-active`
      class, and the application does not invent one.
- [ ] The `<link>` order in `includes/header.php` is exactly
      `tokens → base → components → pages/<page>`.
- [ ] `includes/header.php` renders `app__bar`, `nav` with the four links in the
      order Dashboard · Transactions · Reports · Categories, and `app__main`.
- [ ] The footer renders `app__footer` with the text *"Financial Tracker · personal
      use."*
- [ ] The **auth** shell (`auth`, `auth__brand`, `auth__footer`) is used on the guest
      screens and renders **no** app bar and **no** nav.
- [ ] Every dynamic value in the shell is escaped with `e()`.
- [ ] The stylesheets are referenced through `asset_url()`, so each carries a `?v=`
      stamp.
- [ ] `assets/js/app.js` exists, registers every `init*()` function from a **single**
      `DOMContentLoaded` listener, and contains **no** inline-script equivalent.
- [ ] There is **no** `<script>` block with inline code anywhere in `includes/` or in
      any view — `grep -rn '<script>' includes/` shows only the one external tag.
- [ ] The JS reads user data from `dataset` only — no value is concatenated into a JS
      string.
- [ ] Every `localStorage` access is inside a `try/catch`.
- [ ] The theme toggle **always sets** the explicit `data-theme` attribute and
      never removes it, so the `:root[data-theme="light"]` block is honoured.
- [ ] The theme preference reaches the server through a **`theme` cookie**
      whitelisted to `dark` / `light`, so `<html data-theme>` is rendered before
      the first paint without an inline script.
- [ ] `base.css` uses `overflow-x: clip` on `body` — **not** `hidden`.
- [ ] The page content sits inside `.app__main`, which caps at `var(--content-max)`
      and centres with `margin: 0 auto`.
- [ ] `assets/css/pages/` exists; the page stylesheet is linked only when the
      caller sets `$pageCss`, and the eight page stylesheets arrive with their
      screen (issues 015–020).
- [ ] `.github/instructions/frontend.instructions.md` is corrected to list the
      spacing scale as `--sp-1..8`.
- [ ] `docs/mockup/assets/` is **not** modified by this issue.
- [ ] `database/assets_test.php` guards the whole layer — the token inventory, the
      carry-over, the responsive contract, the script, both shells, the theme
      cookie and the escaping — and exits `0`.

---

## 12. Verification

```bash
cd /home/zongbao/projects/financial-tracker

# 1 — the files exist
for f in assets/css/tokens.css assets/css/base.css assets/css/components.css \
         assets/js/app.js includes/header.php includes/footer.php; do
  test -f "$f" && echo "ok: $f" || echo "MISSING: $f"
done

# 2 — tokens.css holds :root only
grep -oE '^[^ /@}].*\{' assets/css/tokens.css | sort -u
grep -c ':root' assets/css/tokens.css

# 3 — the token inventory (compare with the reference)
diff <(grep -oE '\-\-[a-z0-9-]+:' docs/mockup/assets/tokens.css | sort -u) \
     <(grep -oE '\-\-[a-z0-9-]+:' assets/css/tokens.css | sort -u) \
  && echo "tokens: identical to the reference"

# 4 — the three theme blocks
grep -q 'prefers-color-scheme: dark' assets/css/tokens.css && echo "system-dark: ok"
grep -q ':root\[data-theme="dark"\]'  assets/css/tokens.css && echo "explicit-dark: ok"
grep -q ':root\[data-theme="light"\]' assets/css/tokens.css && echo "explicit-light: ok"

# 5 — the stylesheet load order in the shell
grep -oE "asset_url\('css/[a-z/-]+\.css'\)" includes/header.php

# 6 — no inline script anywhere
grep -rn '<script' includes/ | grep -v 'app.js' || echo "no-inline-script: ok"

# 7 — the responsive contract
grep -q 'overflow-x: clip' assets/css/base.css && echo "overflow-clip: ok"
grep -q 'max-width: 639px' assets/css/components.css && echo "bp-640: ok"
grep -q 'max-width: 1023px' assets/css/components.css && echo "bp-1024: ok"

# 8 — the data-label contract is honoured by the views that render tables
grep -rn 'data-label=' views/ | head -20

# 9 — lint the shell
docker compose exec -T app php -l /var/www/html/includes/header.php
docker compose exec -T app php -l /var/www/html/includes/footer.php

# 10 — the regression test that guards this issue
docker compose exec -T app php /var/www/html/database/assets_test.php
```

Expected: steps 1–2 list the files and show only `:root` selectors, step 3 prints
`tokens: identical to the reference`, step 4 prints three `ok` lines, step 5 lists
the stylesheets in the order `tokens → base → components → pages/…`, step 6 prints
`no-inline-script: ok`, step 7 prints three `ok` lines, step 8 prints nothing
until the views exist (the `data-label` contract lands with issues 018–020),
step 9 prints `No syntax errors detected` twice, and step 10 prints
`ALL ASSERTIONS PASSED` and exits `0`.

> **Step 6 greps the whole file, comments included.** A comment that *mentions*
> a script tag would match, so the shell never spells the tag literally in its
> prose — the phrase is written as "inline script block". The one match the
> filter removes is the external tag in the footer, whose `src` names `app.js`.

---

## 13. Rollback

The asset layer is code, not data — a rollback is a delete:

````bash
rm -rf assets/
rm -f includes/header.php includes/footer.php
````

The mockups in `docs/mockup/assets/` are **untouched** by this issue, so the
design deliverable survives any rollback of the application layer.

---

## 14. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A token name is invented in a page stylesheet | Medium | §4 lists the inventory; the verification diffs the token set against the reference. |
| A single-hyphen modifier is used (`.btn-income`) | Medium | §6 names the trap; the component list uses the double-hyphen form throughout. |
| `components.css` is not linked, and the components render unstyled | Medium | The §6 diagnostic, plus the load-order acceptance criterion. |
| A `<td>` is missing its `data-label` | **High** on first pass | §6 states it as a hard contract, and it is an acceptance criterion in issues 018–020. |
| The dark-mode blocks are copied in the wrong order | Medium | §4 gives the order, and the verification greps for all four blocks. |
| A user-supplied value is concatenated into JS | Medium | §9 forbids it; `dataset` is the only channel. |
| `overflow-x: hidden` replaces `clip` | Low | §6 explains the `position: sticky` breakage. |
| The `--sp-1..20` instruction is followed and the spacing token does not exist | Medium | §4 flags it, and the acceptance criteria require correcting the instruction file. |
| The mockup assets are edited "to fix" the application | Low | The acceptance criteria require `docs/mockup/assets/` to be unmodified. |

---

## 15. Out of scope

- **The screens themselves** — issues [016](issue-016.md) to [020](issue-020.md).
- **The mockup deliverable.** `docs/mockup/` is frozen; it is the specification, not
  a work-in-progress.
- **A dark-mode toggle in the settings screen.** The toggle lives in the app bar; a
  persisted per-user preference is a settings feature and belongs to
  [issue-020.md](issue-020.md) if it is wanted at all.
- **Print styles, RTL support, or localisation.**
- **A CSS preprocessor, a build step, or a bundler.**
- **Raster imagery or an icon font.** The v0.2 interface is text and CSS only.
- **A component framework.** The vocabulary in §10 is the whole framework.
