---
description: "Use when writing or editing CSS and JavaScript in assets/. Covers the no-build-step constraint, the CSS layer load order, the token-only colour rule, dark mode, the escaping of user data in JS, and the asset path conventions."
applyTo: ["assets/**"]
---

# Front-end conventions

Vanilla JS and plain CSS. **No framework, no bundler, no npm, no build step.** The
OpenLiteSpeed container has no `node` and no `composer`, so anything that requires a
compile step cannot run here. (PHP dependencies are vendored at vendor time on the host —
see `docs/issues/issue-010.md` — but the front end has no dependency step at all.)

## CSS layers

Load in this exact order — legacy first so the newer layer wins at equal specificity:

````
assets/css/tokens.css        → custom properties, :root only, no selectors
assets/css/base.css          → element/reset styles
assets/css/components.css    → the component vocabulary
assets/css/pages/<page>.css  → per-page stylesheets
````

- `tokens.css` holds **no selectors** — it is `:root` custom properties only.
- `components.css` is linked by `includes/header.php`. Any page that renders its own
  `<head>` must link it explicitly.
- **Diagnostic:** a component computing to `0px none`, `transparent`, or `padding 0px`
  almost always means the component layer is missing from the cascade. Check the `<link>`
  before suspecting the CSS itself.

## Tokens

- Use the token, not a literal colour or size: `--brand-*`, `--gray-*`,
  `--success/-warning/-danger/-info-{100,500,700}`, `--font-*`, `--fs-*`, `--lh-*`,
  `--sp-1..20`, `--r-*`, shadows, durations.
- Semantic tints use the `-100` / `-500` / `-700` triple.
- New tokens go in `tokens.css` as `:root` custom properties.

## Dark mode is a designed feature

Dark colours under `@media (prefers-color-scheme: dark)` are **expected**, not a defect.
When comparing against a light reference, emulate `colorScheme: 'light'` first.

## Responsive contract

The mobile layout is designed, not improvised. The reference implementation is
`docs/mockup/assets/components.css` — mirror it, do not re-invent it.

- **Breakpoints:** `< 640 px` (single column, fixed bottom navigation, stacked
  table cards), `640–1023 px` (single column, wider gutters, top navigation),
  `≥ 1024 px` (capped, centred content).
- **Page margin:** page content sits in a capped, gutter-padded container
  (`max-width: var(--content-max)`, `margin: 0 auto`). Content placed directly
  in `<body>` has no page margin — that is the defect this rule catches.
- **No sideways scrolling at any width.** The guard is `overflow-x: clip` on
  `body` — use `clip`, not `hidden`, because `hidden` creates a scroll
  container and breaks `position: sticky`.
- **Tables re-lay out; they do not scroll.** Below 640 px the `<thead>` is
  hidden visually and each row becomes a card, so **every `<td>` must carry a
  `data-label` attribute** with the column name. The attribute is the contract:
  without it the column names disappear on a phone.

## Naming traps

- Modifiers use a **double** hyphen: `.tx--income`, `.btn--primary`, `.alert--danger`.
  Single-hyphen forms match nothing and render unstyled — easy to miss because the
  *status value* uses an underscore (`income`, `expense`).
- A later duplicate declaration of the same property in a block **silently wins** — grep for
  a second occurrence before assuming the earlier rule applies.

## JavaScript

- Shared behaviour lives in `assets/js/app.js` — `init*()` functions registered from a
  single `DOMContentLoaded` listener. **No inline `<script>` blocks**; move behaviour there.
- **Never interpolate user data into JavaScript.** Put the value in a `data-` attribute and
  read it with `dataset` — then the PHP side escapes it with `e()` exactly like any other
  output. Building a JS string by concatenation is an XSS hole even when the value is
  numeric.
- The server is the single source of truth for any predicate (e.g. whether an entry is
  over budget): publish it as a `data-` flag and read it in JS. Do not re-derive the rule
  in JavaScript, and do not weaken the server-side validation to match.
- Every `localStorage` access must be wrapped in try/catch (sandboxed iframe, private mode).
- `navigator.clipboard` does not exist outside a secure context — always provide a
  fallback.

## Assets

- Asset paths are **root-absolute** (`/assets/css/…`) and go through `asset_url()` for
  cache-busting.
- The page `<title>` and any heading containing a category or note name must be escaped
  with `e()` — the same rule as any other dynamic HTML.
