# Issue-018 — App: the transaction list and the transaction form

> **Status:** **implemented and verified** — `views/transactions.php`,
> `views/transaction_form.php`, `views/transaction_save.php` and
> `views/transaction_delete.php` exist, `assets/css/pages/transactions.css` is
> the stylesheet the screens share, and the layer is guarded by
> `database/transactions_test.php` (200 assertions, in two tiers: the sources,
> and a sandbox that runs the real screens against a scratch database). The
> walk-through found **two real defects** — a `LIMIT` that reached the database
> as text because a heredoc interpolates a variable and never an expression,
> and an unescaped heading in the form — which are fixed and now asserted.
> **Target:** `index.php`, `includes/`, `views/transactions.php`, `views/transaction_form.php`, `views/transaction_save.php`, `views/transaction_delete.php`, `assets/css/pages/transactions.css`, `assets/js/`
> **Severity:** High — the transaction list and the entry form are the two screens the whole product exists for
> **Requested by the user:** "I guess, we now have everything, ready for dev, write issue files for dev to implement the project."
> **Depends on:** [issue-011](issue-011.md) (schema), [issue-012](issue-012.md) (core runtime, `views/` handlers, `formatMoney()`, `isValidDateString()`), [issue-013](issue-013.md) (tokens and the shared shell), [issue-014](issue-014.md) (the front controller and the route table)
> **Related:** [issue-017](issue-017.md) (the dashboard, which links here), [issue-019](issue-019.md) (the reports, which link back into the filtered list), [issue-020](issue-020.md) (the categories that this screen filters on), [docs/mockup/06-transactions.html](../mockup/06-transactions.html), [docs/mockup/07-transaction-form.html](../mockup/07-transaction-form.html)

---

## Table of contents

1. [Objective](#1-objective)
2. [Constraints](#2-constraints)
3. [The routes](#3-the-routes)
4. [The list screen — the shell](#4-the-list-screen--the-shell)
5. [The filter bar](#5-the-filter-bar)
6. [The period presets](#6-the-period-presets)
7. [The filtered totals](#7-the-filtered-totals)
8. [The table](#8-the-table)
9. [Sorting](#9-sorting)
10. [Pagination](#10-pagination)
11. [The three empty and error states](#11-the-three-empty-and-error-states)
12. [The delete confirmation](#12-the-delete-confirmation)
13. [The transaction form](#13-the-transaction-form)
14. [Validation](#14-validation)
15. [The danger zone](#15-the-danger-zone)
16. [The queries](#16-the-queries)
17. [Acceptance criteria](#17-acceptance-criteria)
18. [Verification](#18-verification)
19. [Rollback](#19-rollback)
20. [Risks](#20-risks)
21. [Out of scope](#21-out-of-scope)

---

## 1. Objective

Deliver the two screens that make the tracker a tracker:

- **`GET /transactions`** — the searchable, filterable, sorted, paginated list of the signed-in user's entries, with the totals of the **filtered** set above it.
- **`GET /transactions/new`** and **`GET /transactions/edit`** — the form that creates and edits one entry.
- **`POST /transactions/save`** — the single write path for both create and update.
- **`POST /transactions/delete`** — the single delete path, behind a confirmation that names the row.

The screens are specified by [docs/mockup/06-transactions.html](../mockup/06-transactions.html) and
[docs/mockup/07-transaction-form.html](../mockup/07-transaction-form.html). The mockups specify
**appearance and copy**; this issue specifies **behaviour, validation, queries and ownership**.

### The three things this issue must get right

| # | The rule | Why it matters |
|---|---|---|
| 1 | **Ownership is enforced in the SQL** — every read, every write and every delete filters on the signed-in user's id. | The tracker is multi-user from the first release. A missing `user_id` predicate is a data leak. |
| 2 | **The totals follow the filters** — the tiles are the totals of the rows the filters select, not the account's all-time totals. | The mockup says so explicitly. A mismatch makes the numbers a lie. |
| 3 | **The error state is never an empty state.** | "No transactions match these filters" tells the visitor their filters are wrong when the truth is that the server failed. |

---

## 2. Constraints

- **No JavaScript is required.** The screen works with JavaScript disabled. The preset buttons and the modal are progressive enhancements layered on a working form.
- **The server is the only source of truth.** Every filter, every sort, every page number and every field value is a URL parameter or a form field the server reads. A crafted request must produce the same result as the UI.
- **Money is never a float.** Amounts are `DECIMAL(14,2)` in the database and strings in PHP. The sign is applied **after** `number_format()`, so the minus sign is **U+2212 MINUS SIGN** (`−`), not an ASCII hyphen.
- **Period sums are computed in SQL.** Never in a PHP loop over rows.
- **Dates are `DATE` in UTC.** The stored value is the calendar date the visitor entered — a transaction belongs to the local day it happened, not to an instant.
- **Output escaping.** `e()` on every dynamic value in the HTML, including the description, the category name and the query-string echo-back.
- **CSRF.** The token is validated **before** any input is read, and the hidden input is in **every** form on this screen — the filter form, the save form and the delete form.
- **The write paths are POST-only.** `GET /transactions/delete` does not exist. `GET /transactions/save` does not exist.
- **`Database::execute()` returns `bool`.** The new row's id comes from `lastInsertId()`.
- **The category filter and the category select list only contain the signed-in user's categories.** Categories are per-user (issue-011).

---

## 3. The routes

| Method | Path | Handler | Auth | Purpose |
|---|---|---|---|---|
| `GET` | `/transactions` | `views/transactions.php` | required | The list, filtered and paginated by the query string. |
| `GET` | `/transactions/new` | `views/transaction_form.php` | required | The empty create form. |
| `GET` | `/transactions/edit?id=<id>` | `views/transaction_form.php` | required | The create form pre-filled with one entry. |
| `POST` | `/transactions/save` | `views/transaction_save.php` | required | Create (no `id`) or update (with `id`). |
| `POST` | `/transactions/delete` | `views/transaction_delete.php` | required | Delete one entry. |

> **Reconciliation with issue-012.** The route table in issue-012 lists
> `GET /transactions/{id}/edit`, but issue-012's own note says the router does
> **exact** path matching and that the handler reads the id from `$_GET['id']`.
> Both cannot be true. **This issue fixes it: the route is
> `GET /transactions/edit?id=<id>`** — the path segment is literal and the id is
> a query parameter. The implementer must update the issue-012 route table to
> match, or the router grows a placeholder feature that issue-012 explicitly
> rules out. Nothing else in the application depends on the difference.

### The query string of the list

| Parameter | Values | Default | Effect |
|---|---|---|---|
| `q` | free text, max 255 | `''` | Matches the description, case-insensitively. |
| `type` | `''` \| `income` \| `expense` | `''` | Filters by type. `''` = both. |
| `category` | `''` \| `<id>` \| `none` | `''` | `''` = all categories, `<id>` = one category, `none` = the uncategorised entries. |
| `from` | `YYYY-MM-DD` | first day of the current month | Inclusive lower bound. |
| `to` | `YYYY-MM-DD` | last day of the current month | Inclusive upper bound. |
| `sort` | `date` \| `amount` | `date` | The sort column. |
| `dir` | `asc` \| `desc` | `desc` for `date`, `asc` for `amount` | The sort direction. |
| `page` | positive integer | `1` | The page number. |

An unknown value for any parameter is **ignored**, not an error — a hand-edited
URL must not produce a 500. A `page` below 1 is clamped to 1; a `page` past the
last page shows the last page.

> **Mockup defect, resolved.** The mockup's category `<select>` contains
> `<option value="">All categories</option>` **and** `<option value="">Uncategorised</option>`
> — two options with the same value. The application must distinguish them, so
> the uncategorised option's value is **`none`**. The mockup's `value="groceries"`
> is a slug placeholder; the application's option values are the **category ids**
> of the signed-in user.

---

## 4. The list screen — the shell

`GET /transactions` renders the shared app shell (issue-013) with the nav order
**Dashboard · Transactions · Reports · Categories**, the `Transactions` link
carrying `aria-current="page"`, the `app__spacer`, the `app__user` element
reading `signed in as **demo**`, and the **Settings** link after the spacer.
The mobile bottom nav carries the same four links.

The page header:

```html
<!-- filepath: views/transactions.php -->
<div class="page__header">
    <div>
        <h1 class="page__title">Transactions</h1>
        <p class="page__subtitle">Search, filter and sum your entries over any period.</p>
    </div>
    <div class="page__actions">
        <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
    </div>
</div>
```

`requireLogin()` runs **before** any query. An anonymous visitor is redirected to
the sign-in page (issue-016); the list is never rendered for them.

---

## 5. The filter bar

The filter bar is a `<form class="filters" role="search" aria-label="Filter transactions">`
that submits with **GET** to `/transactions`, so a filtered view is a
bookmarkable URL and the browser's back button behaves.

| Control | Markup | Name |
|---|---|---|
| Search | `<input class="input" type="search" placeholder="Search the description">` | `q` |
| Type | `<select class="select">` — All types / Income / Spending | `type` |
| Category | `<select class="select">` — All categories / one option per category / Uncategorised | `category` |
| From | `<input class="input" type="date">` | `from` |
| To | `<input class="input" type="date">` | `to` |
| Apply filters | `<button class="btn btn--primary">` | — |
| Clear filters | `<a class="btn btn--ghost" href="/transactions">` | — |

Rules:

- Every control is **echoed back with the value that was applied**, so the visitor sees the state they are in. The `selected` / `value` attributes are set server-side from the parsed query string.
- The search box's `value` is `e($q)`.
- The `from` / `to` inputs are rendered with the applied dates, or with the **current month's** first and last day when the visitor has not asked for a range.
- **"Apply filters"** submits. **"Clear filters"** is a link to `/transactions` with no query string — it is a reset, not a submit.
- The filter form carries the **CSRF token** only if it can ever change state. It cannot — it is a GET form — so it **does not** need one, and must not carry a token that would leak into the URL.

---

## 6. The period presets

The presets are three buttons in a `filters__presets` group
(`role="group" aria-label="Period presets"`):

```html
<button type="button" class="btn btn--secondary btn--sm"
        data-preset-from="2026-10-01" data-preset-to="2026-10-31">This month</button>
<button type="button" class="btn btn--secondary btn--sm"
        data-preset-from="2026-10-19" data-preset-to="2026-10-25">This week</button>
<button type="button" class="btn btn--secondary btn--sm"
        data-preset-from="2026-01-01" data-preset-to="2026-12-31">This year</button>
```

- The buttons are `type="button"` and carry **no** form values. The dates are in the `data-preset-from` / `data-preset-to` attributes, read by `assets/js/` and copied into the `from` and `to` inputs.
- The visitor then presses **"Apply filters"**. The preset does not submit by itself.
- The dates are **relative to the day the page is rendered** — the script computes the current month, the current week (respecting `WEEK_START` from `config.php`, issue-012's `weekStartDay()`) and the current year. The hard-coded `2026-10-01` in the mockup is the mockup's own "today".
- **Without JavaScript the presets do nothing**, and the from / to inputs are still there. That is the acceptable degradation: the visitor types the dates.
- The server **never** trusts a preset name from the query string. `from` and `to` are the only range inputs, and they are validated with `isValidDateString()`.

---

## 7. The filtered totals

The trio of tiles above the table shows the totals of the **filtered** set. The
`tile__period` line reads the fixed copy **`filtered total`** — not a date
range — because the range is already stated in the status line above it.

```html
<!-- filepath: views/transactions.php -->
<div class="tiles">
    <div class="tile tile--income">
        <span class="tile__label">Income</span>
        <span class="tile__amount">+1,200.00</span>
        <span class="tile__period">filtered total</span>
    </div>
    <div class="tile tile--spending">
        <span class="tile__label">Spending</span>
        <span class="tile__amount">−1,041.80</span>
        <span class="tile__period">filtered total</span>
    </div>
    <div class="tile tile--net tile--net-positive">
        <span class="tile__label">Net</span>
        <span class="tile__amount">+158.20</span>
        <span class="tile__period">filtered total</span>
    </div>
</div>
```

- The sign convention is issue-017's: `+` for a positive income, `−` (U+2212)
  for a spending total and for a negative net, **no sign** on a zero net.
- The net modifier is `tile--net-positive` / `tile--net-negative` / `tile--net-zero`. **A zero net is neutral** — never a danger colour.
- The totals come from the **same `WHERE` clause** as the row query. The implementation must build the predicate once and use it for both statements; two hand-written predicates will drift.
- **Never render an error as `0.00`.** If the totals query fails, the whole screen renders the error state (§11) — no tiles at all.

---

## 8. The table

```html
<!-- filepath: views/transactions.php -->
<div class="card card--flush">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="sr-only">Transactions from 1 to 31 October 2026</caption>
            <thead>
                <tr>
                    <th scope="col">…</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td data-label="Date">2026-10-24</td>
                    <td data-label="Description">Weekly groceries</td>
                    <td data-label="Category"><span class="badge badge--neutral">Groceries</span></td>
                    <td data-label="Type"><span class="badge badge--expense">Spending</span></td>
                    <td data-label="Amount" class="num amount amount--expense">−42.80</td>
                    <td data-label="Actions">…</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
```

| Column | Content | Classes |
|---|---|---|
| Date | The stored date, `YYYY-MM-DD`. | `data-label="Date"` |
| Description | The description, or the em dash + screen-reader text when it is empty. | `data-label="Description"` |
| Category | `<span class="badge badge--neutral">` with the category name, or **`Uncategorised`**. | `data-label="Category"` |
| Type | `badge--income` with **`Income`**, or `badge--expense` with **`Spending`**. | `data-label="Type"` |
| Amount | The signed amount. | `class="num amount amount--expense"` / `amount--income` |
| Actions | The Edit link and the Delete button. | `data-label="Actions"` |

Rules:

- **Every `<td>` carries a `data-label`.** The table re-lays out below 640 px using those labels, so a missing one produces an unreadable row. The mockup's last cell has an **empty** `data-label=""` — a defect; the application uses `data-label="Actions"`.
- A row with **no description** renders:
  ```html
  <td data-label="Description"><span aria-hidden="true">—</span><span class="sr-only">No description</span></td>
  ```
  The em dash is **U+2014 EM DASH** (`—`), not a hyphen.
- The **actions cell** is:
  ```html
  <td data-label="Actions">
      <span class="tx__actions">
          <a href="/transactions/edit?id=1">Edit</a>
          <button type="button" class="btn btn--ghost btn--sm" data-modal-open="t-del-1">Delete</button>
      </span>
  </td>
  ```
  The **Edit** link points at `/transactions/edit?id=<id>`; the id is the row's own id, escaped.
- The **Delete** button opens the confirmation modal (§12) for that row.
- The `<caption class="sr-only">` reads `Transactions from <from> to <to>` in the long form (`1 October 2026`), not the ISO form the cells use.
- The table is inside `div.table-wrap` so the horizontal scroll on narrow screens is the wrapper's, not the page's. The page itself uses `overflow-x: clip` (issue-013).

---

## 9. Sorting

Two columns are sortable, and only those two:

| Link | `href` | Direction |
|---|---|---|
| Date | `?sort=date` | **newest first** by default (`desc`). |
| Amount | `?sort=amount` | **smallest first** by default (`asc`). |

- The active column's link carries the arrow glyph `<span aria-hidden="true">↓</span>` (or `↑` when ascending) plus the screen-reader text `<span class="sr-only">sorted by date, newest first</span>`.
- Every sort link **preserves the current filters** — it carries the current `q`, `type`, `category`, `from` and `to`, and resets `page` to 1.
- The sort is applied in SQL, with a **tie-break** on `id` so that rows with the same amount keep a stable order across pages. `ORDER BY amount ASC, id ASC` / `ORDER BY occurred_on DESC, id DESC`.
- The sort column is read from a **whitelist** (`date`, `amount`). The value is never interpolated from the query string.

---

## 10. Pagination

- **25 rows per page.** The constant lives in one place (issue-012's runtime), not repeated in the query and the status line.
- The status line under the card footer reads **`Showing 1–25 of 18 transactions`** — with **U+2013 EN DASH** (`–`) and spaces around it.
- **Previous** and **Next** are disabled at the ends of the range: with 18 transactions the mockup shows both disabled.
- The page number travels in the query string as `page=2`, and the pagination links preserve every filter and the sort.
- The count in the status line is the count of the **filtered** rows, not the count of the page.

### The status line above the tiles

```html
<p class="pagination__status">18 transactions · 1 – 31 October 2026</p>
```

- The count is `18 transactions` — the filtered count. The separator is **U+00B7 MIDDLE DOT** (`·`) with a space on each side.
- The range is the applied range in the long form (`1 – 31 October 2026`, en dash with spaces). When the range is unbounded — which does not happen with the default month range — it reads `all time`.

---

## 11. The three empty and error states

The list screen has **three** distinct states that are not the populated list. They
must not be collapsed into one.

### 11.1 The filters match nothing

```html
<div class="card">
    <div class="empty">
        <h3 class="empty__title">No transactions match these filters.</h3>
        <p class="empty__body">Try widening the date range or clearing the filters.</p>
        <a class="btn btn--secondary" href="/transactions">Clear filters</a>
    </div>
</div>
```

- The status line still renders (`0 transactions · 1 – 31 October 2026`) and the **filter bar is still shown** — the visitor's filters are the thing they need to change.
- **No tiles are rendered.** The totals are genuinely zero, but rendering `0.00` under the heading "filtered total" next to an empty table adds nothing and risks reading as a real value. The mockup shows no tiles in this state; match it.
- The action is a **secondary** "Clear filters" button — the same reset link as in the filter bar.

### 11.2 The account has no transactions at all

```html
<div class="card">
    <div class="empty">
        <h3 class="empty__title">You have no transactions yet.</h3>
        <p class="empty__body">Record your first entry to start building your history.</p>
        <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
    </div>
</div>
```

- This state appears **only when the account has no transactions at all** — not when the filters match nothing. The server distinguishes the two with a dedicated existence check:
  ```sql
  SELECT 1 FROM transactions WHERE user_id = :uid LIMIT 1
  ```
  If that query returns a row, the account has transactions and the empty result is a **filter** result; if it returns nothing, it is the **account** result.
- The filter bar, the status line and the tiles are **not** rendered in this state.
- The action is a **primary** "Add transaction" button.

### 11.3 The list could not be loaded

```html
<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">The transactions could not be loaded.</p>
            <p>Please try again.</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="empty">
        <h3 class="empty__title">No results available</h3>
        <p class="empty__body">The request failed, so no list is shown. This is not an empty result.</p>
        <button type="button" class="btn btn--secondary">Try again</button>
    </div>
</div>
```

- **The error state must never look like an empty state.** The titles are different, and the empty state's body says so explicitly.
- **No tiles, no table, no status line, no filter bar** — the screen renders the alert and the explanation. A zeroed tile would be a lie.
- The raw driver error goes to `error_log()`; the page shows the plain-language copy only.
- The **HTTP status is 500**, not 200.

---

## 12. The delete confirmation

The confirmation names the row so the visitor can recognise it without relying
on memory:

```html
<!-- filepath: views/transactions.php -->
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="t-del-1">
    <h3 class="modal__title" id="t-del-1-title">Delete this transaction?</h3>
    <p>
        <strong>Weekly groceries</strong><br>
        24 October 2026 · <span class="amount amount--expense">−42.80</span>
    </p>
    <p>This cannot be undone.</p>
    <div class="modal__actions">
        <button type="button" class="btn btn--secondary" data-modal-close="t-del-1">Cancel</button>
        <button type="submit" class="btn btn--danger" name="action" value="delete">Delete transaction</button>
    </div>
</div>
```

Rules:

- The modal carries **the date, the description and the amount** — exactly those three — plus **"This cannot be undone."**
- The description is rendered in `<strong>`. A row with no description shows the same em dash + "No description" pair as the table.
- The date is in the long form (`24 October 2026`), the separator is **U+00B7**, and the amount carries its sign with U+2212.
- The **Delete transaction** button is a `type="submit"` button inside the **POST form** to `/transactions/delete`, with the CSRF token and the transaction id as hidden inputs. The mockup's `type="button"` is a mockup artefact — in the application the button performs the POST.
- **Without JavaScript** the modal's contents are rendered inline (the `<dialog>` or the plain `div.modal` is visible) and the button still posts. The modal is an enhancement, not the mechanism.
- The **Cancel** button closes the modal without posting. Without JavaScript it is a link back to `/transactions`.
- The `id` attribute of the modal and of the `data-modal-open` / `data-modal-close` pair is unique per row on the page.

---

## 13. The transaction form

One template serves both create and edit. The difference is the mode.

| | Add | Edit |
|---|---|---|
| Heading | `Add transaction` (`h1`) | `Edit transaction` (`h2`) |
| Subtitle | *Record one entry. The date decides which period it belongs to.* | *Changing a transaction changes the totals of every period it belongs to.* |
| Primary button | **Save transaction** | **Save changes** |
| Danger zone | absent | present (§15) |
| Fields | empty (except the type) | pre-filled from the row |

### The five fields, in this order

1. **Type** — a radio group, **Income selected by default**.

   ```html
   <div class="field">
       <span class="field__label" id="tf-type-label">Type</span>
       <div class="radio-group" role="radiogroup" aria-labelledby="tf-type-label">
           <label class="radio radio--income" for="tf-in">
               <input type="radio" id="tf-in" name="type" value="income" checked> Income
           </label>
           <label class="radio radio--expense" for="tf-out">
               <input type="radio" id="tf-out" name="type" value="expense"> Spending
           </label>
       </div>
   </div>
   ```

2. **Amount** — `input input--amount`, `type="number"`, `step="0.01"`, `inputmode="decimal"`, `min="0"`.
   Hint: *Enter the amount with up to two decimals. It must be greater than zero.*

3. **Date** — `type="date"`.
   Hint: *The transaction date.*

4. **Category** — a `<select>` whose first option is **No category** with the empty value.
   Hint: *Optional. An entry without a category is still counted in every total.*

5. **Description** — `type="text"`, `maxlength="255"`.
   Hint: *Optional. Up to 255 characters.*

Rules:

- **The form posts to `/transactions/save`** with the CSRF token, and — in edit mode — a hidden `id` field.
- The **category select lists only the signed-in user's categories**, sorted by name, with **"No category"** first.
- In edit mode the **selected** category is the row's own; a row with no category has "No category" selected.
- The **Cancel** action is a ghost link to `/transactions` — it does not post.
- The **amount is the largest control on the page**, right-aligned and monospaced (`input--amount`).
- **There is no running total on the form.** The form adds one number and nothing else.
- The form has **no `required` attribute** on the fields. The server is the validator; the browser's own bubbles would pre-empt the specified error copy. `min="0"` on the amount stays — it is a hint, not a rule.

---

## 14. Validation

Every rejection is rendered **in the field's own error slot**, and the other
fields **keep the values the visitor entered**.

```html
<input class="input input--amount" id="tf3-amount" name="amount" type="number"
       step="0.01" inputmode="decimal" min="0" value="0" aria-invalid="true"
       aria-describedby="tf3-amount-hint tf3-amount-msg">
<span class="field__error" id="tf3-amount-msg">The amount must be greater than zero.</span>
```

- The error slot is `<span class="field__error">` and the id is referenced by the
  control's `aria-describedby`, **after** the hint's id.
- A control with an error carries `aria-invalid="true"`.
- The field order in the error response is the same as the form order.

### The rules

| Field | Rule | Error copy |
|---|---|---|
| `type` | Must be `income` or `expense`. Anything else is rejected. | *Choose either income or spending.* |
| `amount` | Must be a decimal with **at most two decimals**, **greater than zero**, and within `DECIMAL(14,2)`. `1e5`, `1,234.50` and `-5.00` are rejected. | *The amount must be greater than zero.* |
| `date` | Must be a real calendar date. **`2026-02-31` is rejected** — February 2026 has 28 days. | *Enter a valid date.* |
| `category` | Must be empty or the id of a category **belonging to the signed-in user**. | *That category is not available.* |
| `description` | Optional. **At most 255 characters**, counted in characters (`mb_strlen()`), not bytes. An over-long value is **rejected, never truncated**. | *The description must be at most 255 characters long.* |

> **New copy.** The mockup shows the client-side and the server-side states for
> the amount, the date and the description only. The **type** and the
> **category** rejections are new states the mockup does not cover; the copy
> above is the specification. It must be added to the mockup file so the
> mockup and the application stay in step.

### The validation order

The fields are validated in the order they appear on the page: type, amount,
date, category, description. **All** errors are collected and rendered at once —
the server does not stop at the first bad field.

### The impossible date

`2026-02-31` is rejected by the **server**, not only by the browser's date
control, because a crafted request bypasses the control entirely. The server
uses issue-012's `isValidDateString()`, which is `checkdate()`-based and
therefore rejects it.

### The server error — values preserved

```html
<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">Something went wrong on our side. Please try again.</p>
        </div>
    </div>
</div>
```

- **A server error must not lose the visitor's work.** Every field keeps its
  value so the submit can be retried without retyping.
- The HTTP status is 500. The raw driver error goes to `error_log()`.

### The success

```html
<div role="status" aria-live="polite">
    <div class="alert alert--success">
        <div class="alert__body">
            <p class="alert__title">Transaction saved.</p>
            <p>You are being redirected to the transaction list.</p>
        </div>
    </div>
</div>

<a class="btn btn--primary" href="/transactions">Go to transactions now</a>
```

- After a successful save the server **redirects** (303) to `/transactions`. The
  success page with the **"Go to transactions now"** link is the **fallback** for
  the case where the redirect does not happen (a JavaScript-driven redirect, a
  client that does not follow it). The link must always be present.
- The success message says **"Transaction saved."** for both create and update.

---

## 15. The danger zone

On the **edit** screen only, below the save actions, in its own card:

```html
<!-- filepath: views/transaction_form.php -->
<div class="card">
    <h2>Danger zone</h2>
    <p class="field__hint">Deleting removes this entry from every report it appears in.</p>
    <!-- the delete confirmation, §12, with this row's values -->
</div>
```

- The delete action lives in its own **separated danger block**, below the save
  actions and visually distinct from them. It **never shares a button group**
  with "Save changes".
- The block is rendered only in edit mode. The add screen has no danger zone.
- The confirmation is the same markup as the list screen's modal, with this
  row's date, description and amount.

---

## 16. The queries

Ownership is enforced **in the SQL**. No statement in this issue may read or
write a row without the `user_id` predicate.

### The predicate

The filter predicate is built **once** and used by both the row query and the
totals query:

```php
// filepath: includes/functions.php
// ...existing code...
$where  = ['t.user_id = :uid'];
$params = [':uid' => $userId];

if ($q !== '') {
    $where[] = 't.description LIKE :q';
    $params[':q'] = '%' . $escapeLike($q) . '%';
}
if ($type === 'income' || $type === 'expense') {
    $where[] = 't.type = :type';
    $params[':type'] = $type;
}
if ($categoryId > 0) {
    $where[] = 't.category_id = :cat';
    $params[':cat'] = $categoryId;
} elseif ($category === 'none') {
    $where[] = 't.category_id IS NULL';
}
if ($from !== null) {
    $where[] = 't.occurred_on >= :from';
    $params[':from'] = $from;
}
if ($to !== null) {
    $where[] = 't.occurred_on <= :to';
    $params[':to'] = $to;
}
$sqlWhere = implode(' AND ', $where);
// ...existing code...
```

- The `LIKE` value is **escaped** with `ESCAPE` for `%` and `_` so a search for
  `100%` does not turn into a wildcard. Use `addcslashes($q, '%_\\')` with
  `ESCAPE '\\'`.
- The search matches the **description only** — not the category name. The
  placeholder says "Search the description", and the search box says so too.

### The rows

```sql
-- filepath: includes/functions.php
SELECT t.id, t.occurred_on, t.description, t.type, t.amount,
       t.category_id, c.name AS category_name
FROM transactions t
LEFT JOIN categories c ON c.id = t.category_id
WHERE <the predicate above>
ORDER BY t.occurred_on DESC, t.id DESC
LIMIT 25 OFFSET <offset>
```

- The `LEFT JOIN` is what makes the **Uncategorised** badge possible — an inner
  join would silently drop every uncategorised row.
- The join is additionally constrained on the owner: `AND c.user_id = t.user_id`.
- `LIMIT` and `OFFSET` are the only interpolated values, and both are integers
  cast with `(int)`. Everything else is a bound parameter.

### The totals

```sql
-- filepath: includes/functions.php
SELECT
    COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS income,
    COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS spending,
    COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                      WHEN t.type = 'expense' THEN -t.amount END), 0) AS net
FROM transactions t
WHERE <the same predicate>
```

- The net is computed **in SQL**, so no PHP float comparison of money is needed.
- The sums come back as **strings** (the PDO wrapper sets
  `ATTR_STRINGIFY_FETCHES = false`, so a `DECIMAL` stays a string). They are
  formatted for display with issue-012's `formatMoney()`.
- The `SUM()` over an empty set is `NULL`; `COALESCE(…, 0)` turns it into
  `0.00`.

### The count

```sql
SELECT COUNT(*) FROM transactions t WHERE <the same predicate>
```

The count is used for the status line and for the pagination range. It is **not**
derived from the page's rows.

### The single row

```php
// filepath: includes/functions.php
// ...existing code...
$stmt = $db->prepare(
    'SELECT t.*, c.name AS category_name
       FROM transactions t
       LEFT JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id
      WHERE t.id = :id AND t.user_id = :uid'
);
$stmt->execute([':id' => $id, ':uid' => $userId]);
$row = $stmt->fetch();
// ...existing code...
```

- The `user_id` predicate is what makes another user's id a **404**, not a 403.

### The write

```php
// filepath: includes/functions.php
// ...existing code...
$db->execute(
    'INSERT INTO transactions (user_id, category_id, type, amount, occurred_on, description)
     VALUES (:uid, :cat, :type, :amount, :on, :desc)',
    [
        ':uid'    => $userId,
        ':cat'    => $categoryId,   // null when there is no category
        ':type'   => $type,
        ':amount' => $amount,       // a string, never a float
        ':on'     => $date,
        ':desc'   => $description,  // null when the field was left empty
    ]
);
$newId = (int) $db->lastInsertId();
// ...existing code...
```

- The amount is bound as a **string** in the canonical two-decimal form
  (`'42.80'`), never as a PHP float. The database rounds the value; PHP never
  rounds it.
- The update is the same statement with `WHERE id = :id AND user_id = :uid`.
- **The update and the delete are scoped by the owner.** An `UPDATE`/`DELETE`
  without `user_id` in the `WHERE` clause is a data-loss bug.

---

## 17. Acceptance criteria

### The list screen

- [ ] `GET /transactions` renders the app shell with the nav order Dashboard · Transactions · Reports · Categories, the `Transactions` link carrying `aria-current="page"`, and the `Settings` link after the spacer.
- [ ] `requireLogin()` runs before any query; an anonymous visitor never sees the list.
- [ ] The page header shows the title `Transactions`, the subtitle *Search, filter and sum your entries over any period.* and the primary **Add transaction** link.
- [ ] The filter bar is a **GET** form with the fields `q`, `type`, `category`, `from`, `to`, the **Apply filters** button and the **Clear filters** link.
- [ ] Every filter control is echoed back with the value that was applied.
- [ ] The `category` select contains **All categories**, one option per category of the signed-in user, and **Uncategorised** with the value `none` — and no two options share a value.
- [ ] The `from` / `to` inputs default to the current month's first and last day.
- [ ] An unknown value for any query-string parameter is ignored; a hand-edited URL never produces a 500.
- [ ] The three preset buttons carry `data-preset-from` / `data-preset-to` and copy the dates into the inputs without submitting.
- [ ] The status line reads `<count> transactions · <range>`, with U+00B7 and an en dash.
- [ ] The three tiles show the **filtered** totals, the `tile__period` line reads `filtered total`, and the totals come from the same `WHERE` clause as the rows.
- [ ] The net modifier is `tile--net-positive` / `tile--net-negative` / `tile--net-zero`; a zero net is **neutral**.
- [ ] The sign convention matches issue-017: `+`, `−` (U+2212), no sign on zero, two decimals, the sign applied after `number_format()`.
- [ ] The table has the six columns Date · Description · Category · Type · Amount · Actions, the Amount column carries `class="num"`, and every `<th>` has `scope="col"`.
- [ ] **Every `<td>` carries a `data-label`**, including the actions cell (`data-label="Actions"`).
- [ ] A row with no description renders `<span aria-hidden="true">—</span><span class="sr-only">No description</span>`.
- [ ] The category cell renders `badge--neutral` with the category name, or the literal `Uncategorised`.
- [ ] The type cell renders `badge--income` with `Income` or `badge--expense` with `Spending`.
- [ ] The table has an `<caption class="sr-only">` naming the range in the long form.
- [ ] The table is wrapped in `div.table-wrap` inside `div.card card--flush`.
- [ ] The default sort is **date, newest first**; the amount column is the only other sortable one.
- [ ] The sort column is read from a whitelist — never interpolated from the query string — and the ORDER BY has an `id` tie-break.
- [ ] Every sort link preserves the current filters and resets `page` to 1.
- [ ] The active sort column carries the arrow glyph and the `sr-only` text *sorted by date, newest first*.
- [ ] The page size is **25**; the footer status reads `Showing 1–25 of 18 transactions` with an en dash.
- [ ] Previous and Next are disabled at the ends of the range and the page number travels in `?page=`.
- [ ] The pagination links preserve every filter and the sort.
- [ ] The count in the status line is the count of the **filtered** rows, not the count of the page.

### The three states

- [ ] The "filters match nothing" state renders `No transactions match these filters.` / *Try widening the date range or clearing the filters.* and a **secondary** "Clear filters" button, and **no tiles**.
- [ ] The "no transactions at all" state renders `You have no transactions yet.` / *Record your first entry to start building your history.* and a **primary** "Add transaction" button, and **no filter bar, no status line and no tiles**.
- [ ] The two empty states are distinguished by a dedicated existence check (`SELECT 1 … LIMIT 1`), not by the page's own rows.
- [ ] The error state renders `role="alert"`, the alert title `The transactions could not be loaded.` and the body *Please try again.*, the empty title `No results available` and the body *The request failed, so no list is shown. This is not an empty result.*, and a "Try again" button.
- [ ] The error state renders **no tiles, no table, no status line and no filter bar**, and the HTTP status is **500**.
- [ ] The error state is never rendered with the word "empty" in the title.

### The form

- [ ] `GET /transactions/new` renders the form with the heading `Add transaction`, the subtitle *Record one entry. The date decides which period it belongs to.* and the primary button **Save transaction**.
- [ ] `GET /transactions/edit?id=<id>` renders the form with the heading `Edit transaction`, the subtitle *Changing a transaction changes the totals of every period it belongs to.* and the primary button **Save changes**.
- [ ] The fields appear in the order **type, amount, date, category, description**.
- [ ] The type control is a `role="radiogroup"` radio group with the values `income` and `expense`, and **Income is checked by default** on the add form.
- [ ] The amount control is `input--amount` with `type="number"`, `step="0.01"`, `inputmode="decimal"` and `min="0"`.
- [ ] The date control is `type="date"`; the description control is `type="text"` with `maxlength="255"`.
- [ ] The category select's first option is **No category** with the empty value, and it lists only the signed-in user's categories, sorted by name.
- [ ] The three hints read *Enter the amount with up to two decimals. It must be greater than zero.*, *The transaction date.* and *Optional. Up to 255 characters.*, plus *Optional. An entry without a category is still counted in every total.* for the category.
- [ ] The form posts to `/transactions/save` and carries the CSRF token.
- [ ] In edit mode the hidden `id` field carries the row's id and the form is pre-filled with the row's values.
- [ ] The Cancel action is a ghost link to `/transactions` and does not post.
- [ ] The form has no `required` attributes.
- [ ] Every dynamic value in the form is escaped with `e()`.

### Validation

- [ ] The amount must be greater than zero, have at most two decimals, and fit `DECIMAL(14,2)`; `1e5`, `1,234.50` and `-5.00` are rejected.
- [ ] The date `2026-02-31` is rejected by the **server** with *Enter a valid date.*
- [ ] The description longer than 255 characters is rejected with *The description must be at most 255 characters long.* and is **never truncated**.
- [ ] The length is counted in characters, not bytes.
- [ ] A category that does not belong to the signed-in user is rejected with *That category is not available.*
- [ ] **All** the field errors are collected and rendered at once, in the form's field order.
- [ ] Each error renders in the field's own `<span class="field__error">` slot, the control carries `aria-invalid="true"`, and the control's `aria-describedby` lists the hint id **and** the error id.
- [ ] The fields that were **not** in error keep the values the visitor entered.
- [ ] On a server error the alert reads `Something went wrong on our side. Please try again.`, the status is 500, and **every field keeps its value**.
- [ ] On success the server redirects to `/transactions` with a 303, and the success page offers the **"Go to transactions now"** link as the fallback.
- [ ] The success alert title reads `Transaction saved.` for both create and update.

### Delete

- [ ] The confirmation modal names the **date, the description and the amount**, and ends with *This cannot be undone.*
- [ ] The Delete button is a `type="submit"` button inside a POST form to `/transactions/delete` carrying the CSRF token and the transaction id.
- [ ] The delete is reachable without JavaScript; the modal is an enhancement.
- [ ] The delete statement filters on `user_id` as well as `id`.
- [ ] Another user's transaction id produces a **404**, not a 403 and not a silent success.
- [ ] The edit screen's delete lives in its own **Danger zone** block, below the save actions, never in the same button group as **Save changes**.
- [ ] The danger block is absent from the add screen.

### Data and queries

- [ ] Every statement that reads or writes a transaction filters on the signed-in user's id.
- [ ] The `LEFT JOIN` to `categories` is constrained on the owner as well.
- [ ] The `LIKE` search escapes `%` and `_`.
- [ ] `LIMIT` and `OFFSET` are the only interpolated values, and both are cast to `int`.
- [ ] The sort column is whitelisted; the direction is whitelisted.
- [ ] The sums are computed in SQL, read back as strings, and formatted with `formatMoney()`.
- [ ] The amount is bound as a string, never as a PHP float.
- [ ] An empty description is stored as `NULL` and renders as the em dash.
- [ ] The new row's id comes from `lastInsertId()`, not from the return value of `execute()`.
- [ ] The list and the totals share one predicate — there is no second hand-written copy.
- [ ] No `?>` or `<?php` appears inside a `//` comment in any file written for this issue.
- [ ] Every PHP file written for this issue passes `php -l`.

---

## 18. Verification

Run from the project root. `php` is **not** on the host PATH — every command runs
inside the container.

### 18.1 Lint

```bash
docker compose exec -T app php -l /var/www/html/views/transactions.php
docker compose exec -T app php -l /var/www/html/views/transaction_form.php
docker compose exec -T app php -l /var/www/html/views/transaction_save.php
docker compose exec -T app php -l /var/www/html/views/transaction_delete.php
docker compose exec -T app php -l /var/www/html/includes/functions.php
```

### 18.2 The routes exist and are method-guarded

```bash
for r in /transactions /transactions/new "/transactions/edit?id=1" /transactions/save /transactions/delete; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8088$r")
  echo "$r -> $code"
done
```

`/transactions/save` and `/transactions/delete` must **not** answer `GET` with a
success — they are POST-only.

### 18.3 Ownership is enforced

Create a second user, then try to read, edit and delete the first user's
transaction:

```bash
# as user B, with a valid session and a CSRF token
curl -s -o /dev/null -w '%{http_code}\n' \
  "http://localhost:8088/transactions/edit?id=<USER_A_TX_ID>"      # expect 404
curl -s -o /dev/null -w '%{http_code}\n' \
  -X POST -d "csrf_token=<TOKEN>&id=<USER_A_TX_ID>" \
  "http://localhost:8088/transactions/delete"                        # expect 404
```

Both must be **404**. A 403 is acceptable; a **200** is a bug.

### 18.4 The filters change the totals

```bash
# a month with no entries
curl -s "http://localhost:8088/transactions?from=2026-01-01&to=2026-01-31" \
  | grep -o '0 transactions[^<]*'
```

and

```bash
curl -s "http://localhost:8088/transactions?type=expense&from=2026-10-01&to=2026-10-31" \
  | grep -o 'class="tile__amount">[^<]*'
```

The tile amounts must equal the totals of the **filtered** rows, not the
all-time totals. Cross-check with:

```bash
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
  "SELECT type, SUM(amount) FROM transactions WHERE user_id=1 AND occurred_on BETWEEN '2026-10-01' AND '2026-10-31' GROUP BY type;"
```

### 18.5 The search escapes its wildcards

```bash
curl -s "http://localhost:8088/transactions?q=%25" | grep -c 'data-label="Date"'
```

A search for `%` must return **only** the rows whose description literally
contains `%` — never the whole table.

### 18.6 The impossible date is rejected by the server

```bash
curl -s -X POST \
  -d "csrf_token=<TOKEN>&type=expense&amount=42.80&date=2026-02-31&description=test" \
  "http://localhost:8088/transactions/save" | grep -o 'Enter a valid date.'
```

The phrase must be in the response. A 500, or a redirect with no error, is a bug.

### 18.7 The amount is rejected

```bash
for a in 0 -12.00 1e5 1,234.50; do
  curl -s -X POST -d "csrf_token=<TOKEN>&type=expense&amount=$a&date=2026-10-24" \
    "http://localhost:8088/transactions/save" | grep -o 'The amount must be greater than zero.'
done
```

### 18.8 A long description is rejected, not truncated

```bash
long=$(printf 'x%.0s' $(seq 1 256))
curl -s -X POST -d "csrf_token=<TOKEN>&type=expense&amount=42.80&date=2026-10-24&description=$long" \
  "http://localhost:8088/transactions/save" | grep -o 'The description must be at most 255 characters long.'
```

And confirm the row was **not** written with a truncated description:

```bash
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
  "SELECT CHAR_LENGTH(description) FROM transactions ORDER BY id DESC LIMIT 1;"
```

### 18.9 The money sign is U+2212, not an ASCII hyphen

```bash
curl -s "http://localhost:8088/transactions" | grep -o 'amount--expense">[^<]*' | head -1
```

The output must contain **`−`** (U+2212). A `-` means the sign was applied
before `number_format()`.

### 18.10 Every cell carries a data-label

```bash
curl -s "http://localhost:8088/transactions" \
  | grep -o '<td[^>]*>' | grep -cv 'data-label='
```

The count must be **0**.

### 18.11 The CSRF token is required

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  -d "type=expense&amount=42.80&date=2026-10-24" \
  "http://localhost:8088/transactions/save"
```

Must not be a success status.

### 18.12 The whole suite still passes

```bash
.github/skills/dev-stack-ops/scripts/run-tests.sh
```

---

## 19. Rollback

The change is confined to the views, the helper functions and the new page CSS.

1. Remove `views/transactions.php`, `views/transaction_form.php`, `views/transaction_save.php` and `views/transaction_delete.php`.
2. Remove the five route entries from the route table in `index.php` (issue-014).
3. Remove `assets/css/pages/transactions.css` and its `<link>` from the layout.
4. Remove the transaction helpers from `includes/functions.php`.
5. Restart the app so the cached `.htaccess` and the route table are re-read.

**The database is not rolled back.** The `transactions` table from issue-011 stays,
and no data is destroyed by removing the screens. If the schema itself has to go,
it is dropped by hand — **never** `docker compose down -v`, which takes the whole
MariaDB volume with it.

---

## 20. Risks

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| 1 | A query drops the `user_id` predicate — another user's rows leak. | Medium | **Critical** | The predicate is built once and used by every statement (§16). §18.3 tests it. |
| 2 | The totals drift from the rows because the `WHERE` clause is written twice. | **High** | High | One predicate, used by the row query, the totals query and the count (§7, §16). |
| 3 | A PHP float creeps into the amount path and `0.1 + 0.2` shows up as `0.30`. | Medium | High | The amount is bound as a string; the sums are computed in SQL; `formatMoney()` is the only float cast and it is at the display edge. |
| 4 | The sign is applied **before** `number_format()`, producing an ASCII hyphen instead of U+2212. | **High** | Medium | Issue-017 fixes the order; §18.9 checks the glyph byte-for-byte. |
| 5 | The totals query fails and the screen renders `0.00`, telling the visitor they have no income. | Medium | **High** | The error state renders no tiles at all (§11.3). |
| 6 | The error state is mistaken for an empty state. | Medium | Medium | Distinct titles and an explicit body line (§11.3). |
| 7 | A `LIKE` search for `%` returns the whole table. | Medium | Medium | The value is escaped with an `ESCAPE` clause (§16); §18.5 tests it. |
| 8 | A crafted `sort` parameter injects SQL. | Low | **Critical** | The column and the direction are whitelisted (§9). |
| 9 | The pagination `OFFSET` is interpolated. | Low | High | It is cast with `(int)`; it is the only interpolated value (§16). |
| 10 | The date `2026-02-31` is accepted by a crafted request. | Medium | Medium | `isValidDateString()` uses `checkdate()` (§14); §18.6 tests it. |
| 11 | The description is truncated at the database instead of rejected. | Medium | Medium | The length is checked in PHP first and rejected; §18.8 checks the stored length. |
| 12 | A 500 loses the visitor's typed values. | Medium | Medium | The handler re-renders the form with the submitted values (§14). |
| 13 | The route table's `/transactions/{id}/edit` cannot be matched by an exact-match router. | **Certain** | Medium | Resolved in §3: the route is `/transactions/edit?id=<id>`. The issue-012 table must be updated. |
| 14 | The mockup's two empty category option values collide, so "Uncategorised" is unreachable. | **Certain** | Medium | Resolved in §3 and §17: the uncategorised option's value is `none`. |
| 15 | The two empty states are implemented as one, so a new user is told their filters are wrong. | Medium | Medium | The distinction is made by a dedicated existence query (§11.2). |
| 16 | A transaction with no description renders an empty cell instead of the em dash. | Low | Low | The `sr-only` pair is specified in §8 and §17. |
| 17 | The delete button is rendered as `type="button"` (as in the mockup) and does nothing. | Medium | High | The button is a submit inside the POST form (§12); the delete is reachable without JavaScript. |
| 18 | The period presets are computed on the server from a fixed date and go stale. | Low | Medium | They are computed client-side from the current date (§6). |

---

## 21. Out of scope

- **The transaction list's own column set.** The six columns are fixed; adding, removing or reordering them is a design change and a new mockup.
- **Inline editing.** The row's Edit link opens the form; the list is not editable in place.
- **Bulk actions** — multi-select, bulk categorise, bulk delete.
- **Multi-currency.** Amounts are a single currency; there is no `currency` column and no conversion.
- **Recurring transactions** and their templates.
- **Attachments, receipts, notes and tags** on a transaction.
- **Export** — CSV, PDF, anything.
- **The dashboard's period selector.** That is issue-017; this screen has its own from / to filters.
- **The reports.** Issue-019 links back into this screen with `?from=…&to=…` and `?category=…`; those parameters are read here, but the report itself is built there.
- **The categories** — creating, renaming and deleting them, and the `categories` table's own screen. Issue-020.
- **Uninstall / data export.**

---

## Implementation notes — 2026-10-28

### The decisions the specification left open

| Question | Decision |
|---|---|
| Which id prefix do the form's controls carry? | **`tf-`** — `tf-amount`, `tf-amount-hint`, `tf-amount-msg`. The mockup uses `tf3-` because a gallery holds several copies of the same form and their ids must differ; the application has **one** template, so one prefix is enough. The repo's house convention is `<screen>-<field>`, and the screen here is `tf`. |
| Where does the delete form live? | In its **own card**, below the save actions. A `<form>` inside the `<form>` that posts to `/transactions/save` is invalid HTML, and the delete needs its own action and its own hidden id. |
| What does a delete of a row that is not there do? | A **404**, not a silent success. `Database::execute()` returns a bool and there is no `rowCount()`, so the handler SELECTs the row first — with `WHERE t.id = ? AND t.user_id = ?` — and answers 404 when it is absent. |
| How does the list know which filters are active? | From the **query string alone**. The filter form is a GET form and carries **no** CSRF token (§5): a token in a GET form leaks into the URL, and the form cannot change state. |
| How do the presets get their dates? | The **server** renders `data-preset-from` / `data-preset-to` for the day the page was rendered, and `assets/js/app.js` **recomputes** them for the day the page is read (§6, risk 18) and copies them into the two date inputs. The button never submits. |
| Which dash is which? | Two, and they are different on purpose. The status line **above** the tiles uses spaced dashes — `18 transactions · 1 – 31 October 2026` — because it is a sentence. The pagination line **under** the table uses an unspaced en dash — `Showing 1–25 of 18 transactions` — because the bolded example, the acceptance criterion and the mockup all agree on that one. |
| What does a missing `id` do on the save path? | It **creates**. The hidden `id` input is only rendered when the id is a positive integer, so an update can never be turned into a second row by a crafted POST. |

### The shared helpers

`includes/functions.php` gained a **Transactions** section. Every helper is
pure, takes no request state and is guarded by unit assertions:

| Helper | What it does |
|---|---|
| `TRANSACTIONS_PER_PAGE` | **25** rows. One constant, read by the query and by the status line. |
| `escapeLike(string $value): string` | Escapes `%`, `_` and the escape character, so a search for `%` finds a percent sign and not the whole table (§16). |
| `formatLongDate(?string $isoDate): string` | A date with the **long** month name — `24 October 2026`. `formatDate()` stays short. |
| `normalizeTransactionSort(?string $sort, ?string $dir): array` | Whitelists the column and the direction and returns the key, the direction and the `ORDER BY` fragment. An unknown sort falls back to the date. |
| `isCategoryFilterValue(string $value): bool` | Is this a category filter — a positive id, or the sentinel `none` for the uncategorised rows? |
| `parseTransactionFilters(?array $source = null): array` | Reads the five filters from the query string. An absent `from` / `to` means the current month; a present-but-empty one means unbounded. |
| `buildTransactionPredicate(int $userId, array $filters): array` | Builds the `WHERE` clause and its parameters — **once**, for the row query, the totals query and the count, so the three can never disagree. |
| `transactionListUrl(array $filters, ?string $sort = null, ?string $dir = null, int $page = 1): string` | The URL that carries the current filters — the sort links and the pagination links use it. |
| `normalizeAmountString(string $amount): string` | An amount as a two-decimal **string**. `42.8` becomes `42.80`; the value never becomes a float. |
| `isPositiveAmount(string $amount): bool` | Is this amount greater than zero? `0`, `0.00` and `0.000` are not. |
| `validateTransactionInput(array $input, array $categoryIds): array` | The five rules of §14, **all** of them collected at once, in the order the fields appear. |
| `transactionRangeLabel(string $from, string $to): string` | The range as a phrase — `all time`, `until 31 October 2026`, `from 1 October 2026`, `1 – 31 October 2026`. |

### The two defects the test found

1. **The `LIMIT` reached the database as text.** The query was built in a
   heredoc with `LIMIT {(int) $perPage} OFFSET {(int) $offset}` — and a
   heredoc interpolates a **variable**, never an expression. MariaDB was
   handed the literal string `{(int) 25} OFFSET {(int) 0}`, so **every** list
   request answered 500. The cast now happens first and the **variables** are
   interpolated: `$limitValue = (int) $perPage;` and `$offsetValue = (int)
   $offset;`. The rule is now in `AGENTS.md`: *cast first, interpolate second.*
2. **The form's heading was echoed without `e()`.** The heading was a literal
   the view had chosen, so nothing could leak — but a variable is a dynamic
   value, and the house rule is that every dynamic output is escaped. The
   template now branches into the two literal headings instead of echoing a
   variable.

### The mockups that changed with the screens

| File | Change |
|---|---|
| `docs/mockup/06-transactions.html` | The actions cell carried an **empty** `data-label=""` — the defect §8 names. It is `data-label="Actions"` now, in all four rows. |
| `docs/mockup/07-transaction-form.html` | The **type** and the **category** rejections are new states the mockup did not cover. §14 requires them to be added, so both are there now, with the specified copy. |
| `docs/mockup/assets/mockup.js` | The preset script looked up `data-preset-from` as an **element id** and read the dates from attributes that did not exist — the buttons did nothing. The script now copies the dates the buttons already carry, exactly as the application does. |

### What the guard covers

`database/transactions_test.php` checks two tiers:

- **A — the sources.** `requireLogin()` is the first call of every view; the
  CSRF token is validated **before** any input is read; the ownership is in
  the SQL; every dynamic value is escaped; the filter form is a GET form
  without a token; the form carries the token and no `required` attribute; the
  page stylesheet uses the tokens only; the U+2212 glyph is the **literal
  character** in the source.
- **B — the behaviour.** A sandbox copy of the application, served by the PHP
  built-in server against a scratch database, answers the real requests: the
  guard redirects, the list renders its three tiles and their totals are
  checked **against the database**, the status line and the caption carry the
  right counts, the six `data-label`s are on every cell, the filters select
  (including a search for `%`, a category, the uncategorised rows and an empty
  range), the three states are distinguished, the form adds and edits, another
  account's row is a **404**, the five validations each reject with the
  specified copy and all five appear at once, the save writes **two decimals**,
  the delete removes the row, the sort reorders the rows and the pagination
  answers 200 past the end.

### The verification

```bash
docker compose exec -T app php -l /var/www/html/views/transactions.php
docker compose exec -T app php -l /var/www/html/views/transaction_form.php
docker compose exec -T app php -l /var/www/html/views/transaction_save.php
docker compose exec -T app php -l /var/www/html/views/transaction_delete.php
docker compose exec -T app php /var/www/html/database/transactions_test.php
.github/skills/dev-stack-ops/scripts/run-tests.sh
```

All of them pass, and the whole suite is green: **11 PASS / 0 FAIL**.
