# Issue-020 — App: the categories and the settings

> **Status:** **implemented and verified** — `views/categories.php`,
> `views/category_save.php`, `views/category_delete.php` and `views/settings.php`
> exist, `assets/css/pages/categories.css` and `assets/css/pages/settings.css`
> are the stylesheets the two screens need, and the layer is guarded by
> `database/categories_test.php` and `database/settings_test.php` (121 and 127
> assertions, in two tiers each: the sources, and a sandbox that runs the real
> screens against a scratch database). The walk-through found **one real
> defect** — the password errors were overwritten before they were rendered, so
> a wrong current password, a mismatch and a short password were all accepted
> silently — which is fixed and now asserted.
> **Target:** `index.php`, `includes/functions.php`, `views/categories.php`, `views/category_save.php`, `views/category_delete.php`, `views/settings.php`, `views/logout.php`, `assets/css/pages/categories.css`, `assets/css/pages/settings.css`
> **Severity:** Medium — the last two screens of the application
> **Requested by the user:** "I guess, we now have everything, ready for dev, write issue files for dev to implement the project."
> **Depends on:** [issue-011](issue-011.md) (the `categories` and `users` schema), [issue-012](issue-012.md) (core runtime — `isValidCategoryName()`, `isValidType()`, `formatMoney()`, `userScope()`), [issue-013](issue-013.md) (tokens and the shared shell), [issue-014](issue-014.md) (the front controller and the route table), [issue-016](issue-016.md) (the session, the username and email rules, the password policy, `POST /logout`)
> **Related:** [issue-018](issue-018.md) (the transaction form, which picks a category), [issue-019](issue-019.md) (the report, which breaks the period down by category), [docs/mockup/09-categories.html](../mockup/09-categories.html), [docs/mockup/10-settings.html](../mockup/10-settings.html)

---

## Table of contents

1. [Objective](#1-objective)
2. [Constraints](#2-constraints)
3. [The routes](#3-the-routes)
4. [The categories screen — the shell](#4-the-categories-screen--the-shell)
5. [The create form](#5-the-create-form)
6. [The category table](#6-the-category-table)
7. [The empty state](#7-the-empty-state)
8. [The create validation](#8-the-create-validation)
9. [The rename](#9-the-rename)
10. [The delete](#10-the-delete)
11. [The settings screen — the shell](#11-the-settings-screen--the-shell)
12. [The profile](#12-the-profile)
13. [The change-password form](#13-the-change-password-form)
14. [The session card](#14-the-session-card)
15. [The installation card](#15-the-installation-card)
16. [The queries](#16-the-queries)
17. [Acceptance criteria](#17-acceptance-criteria)
18. [Verification](#18-verification)
19. [Rollback](#19-rollback)
20. [Risks](#20-risks)
21. [Out of scope](#21-out-of-scope)

---

## 1. Objective

Deliver the last two screens of the application:

- **`GET /categories`** — the per-account list of categories, with a create form, an inline rename and a delete that **never touches the transactions**.
- **`GET /settings`** — the profile form, the change-password form, the session card and the read-only installation card.

The screens are specified by [docs/mockup/09-categories.html](../mockup/09-categories.html)
and [docs/mockup/10-settings.html](../mockup/10-settings.html). The mockups specify
**appearance and copy**; this issue specifies **behaviour, validation, ownership and
queries**.

### The four things this issue must get right

| # | The rule | Why it matters |
|---|---|---|
| 1 | **Deleting a category does not delete its transactions.** They become uncategorised, and the confirmation says so in those words before the visitor confirms. | The mockup calls this *"the most important sentence on this screen"*. A silent data loss is unacceptable. |
| 2 | **The secrets never reach the HTML.** The database password and the SMTP password are **absent from the source** — not masked, not truncated, not empty-looking. | A secret in the source is a secret in the view-source window. |
| 3 | **Ownership is enforced in the SQL** on every category and user read and write. | Categories are per user. |
| 4 | **Sign out is a POST.** The mockup shows a link; the application shows a form with a POST button. | A `GET /logout` can be triggered by any page on the internet. Issue-016 already decided this. |

---

## 2. Constraints

- **No JavaScript is required.** The rename works as a server-rendered state; the modal is an enhancement.
- **The server is the only source of truth.** Every name, every type and every uniqueness decision is made by the server.
- **The write paths are POST-only**, and every form carries the CSRF token.
- **Every POST ends with a redirect** (Post/Redirect/Get) — never a re-render of the POST body.
- **Money is never a float.** The category totals are `DECIMAL(14,2)` sums computed in SQL and formatted with `formatMoney()`.
- **Output escaping.** `e()` on every dynamic value — the category name, the email, the username, the installation values.
- **`config.php` is the only configuration source.** The installation card reads the constants; it never reads `getenv()`, `$_ENV` or `$_SERVER`.
- **`ALLOW_REGISTRATION` is a real boolean.** The card renders `Yes` or `No` from `ALLOW_REGISTRATION === true` — never the strings `'true'` / `'false'`.

---

## 3. The routes

| Method | Path | Handler | Auth | Purpose |
|---|---|---|---|---|
| `GET` | `/categories` | `views/categories.php` | required | The category list, the create form, the rename state. |
| `POST` | `/categories/save` | `views/category_save.php` | required | Create (no `id`) or rename (`id` present). |
| `POST` | `/categories/delete` | `views/category_delete.php` | required | Delete one category. |
| `GET` | `/settings` | `views/settings.php` | required | The settings screen. |
| `POST` | `/settings/profile` | `views/settings.php` | required | Save the profile. |
| `POST` | `/settings/password` | `views/settings.php` | required | Change the password. |
| `POST` | `/logout` | `views/logout.php` | required | End the session. |

- `requireLogin()` runs on every one of them.
- The route table in issue-012 already lists these seven entries; this issue adds no route.

### The `?rename=<id>` state

The mockup's inline rename is a JavaScript behaviour. The server contract is:

| URL | What it renders |
|---|---|
| `/categories` | The list, with a **Rename** link per row. |
| `/categories?rename=<id>` | The list, with the row for `<id>` **in edit mode**. |

- The **Rename** action is a link to `/categories?rename=<id>`.
- The row in edit mode holds a small POST form to `/categories/save` with the CSRF token and the category id.
- **Cancel** is a link back to `/categories`.
- With JavaScript, the same behaviour may be inlined — but the URL contract above stands, and the form still posts to `/categories/save`.
- A `rename` id that is not the signed-in user's category is **ignored** — the list renders normally, with no row in edit mode. It is not a 404 on the whole page.

---

## 4. The categories screen — the shell

`GET /categories` renders the shared app shell (issue-013) with the nav order
**Dashboard · Transactions · Reports · Categories**, the `Categories` link
carrying `aria-current="page"`, the `app__spacer`, the `app__user` element
reading `signed in as **demo**`, and the **Settings** link after the spacer.

The page header:

```html
<!-- filepath: views/categories.php -->
<div class="page__header">
    <div>
        <h1 class="page__title">Categories</h1>
        <p class="page__subtitle">Optional labels that group your transactions in the reports.</p>
    </div>
</div>
```

The header has **no** page action — the create form is the first card.

---

## 5. The create form

```html
<!-- filepath: views/categories.php -->
<div class="card">
    <h2>New category</h2>

    <form method="post" action="/categories/save" aria-label="Create a category">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="cat-name">Name</label>
            <span class="field__hint" id="cat-name-hint">Up to 60 characters. The name must be unique.</span>
            <input class="input" id="cat-name" name="name" type="text"
                   maxlength="60" aria-describedby="cat-name-hint">
        </div>

        <div class="field">
            <span class="field__label" id="cat-type-label">Type</span>
            <div class="radio-group" role="radiogroup" aria-labelledby="cat-type-label">
                <label class="radio radio--income" for="cat-in">
                    <input type="radio" id="cat-in" name="type" value="income"> Income
                </label>
                <label class="radio radio--expense" for="cat-out">
                    <input type="radio" id="cat-out" name="type" value="expense" checked> Expense
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn--primary">Add category</button>
    </form>
</div>
```

Rules:

- The form posts to `/categories/save` with the CSRF token.
- The name is a text input with `maxlength="60"`.
- The type is a radio group with the values `income` and `expense`, and **Expense is checked by default** — the opposite of the transaction form, where Income is the default. The mockup is explicit about it on both screens.
- The labels are **Income** and **Expense** on this screen. The transaction screens call the same value **Spending**. See §5.1.
- The mockup's button is `type="button"`; in the application it is `type="submit"`.

### 5.1 The "Expense" / "Spending" copy

The two screens use different words for the same stored value:

| Screen | Label |
|---|---|
| The transaction form, the transaction list, the reports | **Spending** |
| The categories screen | **Expense** |

The **stored** value is `expense` everywhere — that is the `ENUM` in the schema
(issue-011 §5) and it never changes.

This issue specifies that **each screen uses its own mockup's copy verbatim**,
because the mockups are the design deliverable. The inconsistency is recorded
here for the design owner; unifying on one word across the application is a
copy change, not a code change, and it must be made in the mockups **first**.

---

## 6. The category table

```html
<!-- filepath: views/categories.php -->
<div class="card card--flush">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="sr-only">Your categories</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Type</th>
                    <th scope="col" class="num">Transactions</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row">Groceries</th>
                    <td data-label="Type"><span class="badge badge--expense">Expense</span></td>
                    <td data-label="Transactions" class="num">8</td>
                    <td data-label="Total" class="num amount amount--expense">−342.60</td>
                    <td data-label="Actions">
                        <span class="tx__actions">
                            <a class="btn btn--ghost btn--sm" href="/categories?rename=12">Rename</a>
                            <button type="button" class="btn btn--ghost btn--sm"
                                    data-modal-open="cat-del-12">Delete</button>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
```

| Column | Content | Classes |
|---|---|---|
| Name | The category name — a `<th scope="row">`. | — |
| Type | `badge--income` with `Income`, or `badge--expense` with `Expense`. | `data-label="Type"` |
| Transactions | The number of the user's transactions in that category. | `data-label="Transactions"` |
| Total | The signed sum of those transactions. | `data-label="Total"` |
| Actions | The Rename link and the Delete button. | `data-label="Actions"` |

Rules:

- **Every `<td>` carries a `data-label`.** The mockup's last cell has an empty `data-label=""` — a defect; the application uses `data-label="Actions"`.
- The name is the row header, so it is a `<th scope="row">` and carries no `data-label`.
- The **Transactions** count and the **Total** are computed by the server for each category.
- A category with **no transactions** shows the count **`0`** and the total as an **em dash**: `<td data-label="Total" class="num amount amount--muted">—</td>`. The em dash is used exactly when the count is `0` — that is a count comparison, not a float comparison of money.
- The Total is **signed**: `−342.60` for an expense category, `+1,200.00` for an income category, with U+2212 on the negative.
- The sign follows the **actual transactions**, not the category's declared type: the total is the net of the transactions filed under the category. A visitor who files an income transaction under an "Expense" category gets a positive total — that is their data, not an error.
- The list is ordered by **name, A–Z, case-insensitive**. The mockup's order is neither alphabetical nor grouped by type; the application uses the deterministic rule.
- The table is wrapped in `div.table-wrap` inside `div.card card--flush`.

---

## 7. The empty state

```html
<div class="card">
    <div class="empty">
        <h3 class="empty__title">No categories yet.</h3>
        <p class="empty__body">
            That is fine — uncategorised entries are still counted in every
            total and every report.
        </p>
    </div>
</div>
```

- The **create form is still rendered** — the visitor can create the first category without navigating anywhere.
- The **table is omitted** entirely when there are no categories. There is no table with an empty body.
- The copy reassures: uncategorised entries still count everywhere. It is true — every sum is over the transactions, not over the categories.

---

## 8. The create validation

### The rules

| Field | Rule | Error copy |
|---|---|---|
| `name` | 1–60 characters after trimming. **Unique per user** (the database's `UNIQUE (user_id, name)` key). | *That name is already in use.* |
| `type` | `income` or `expense`. | *Choose either income or expense.* |

- The name is **not** trimmed of its interior spaces, and the stored name keeps the case the visitor typed — unlike the email address, which issue-011 lowercases.
- The uniqueness check is enforced by the **database constraint**, not by a `SELECT` first. The constraint is the only race-free check. The duplicate-key error is caught and mapped to the friendly message.
- The comparison is **case-insensitive**, because the column's default collation is. `groceries` and `Groceries` collide, and the message says the name is already in use.

### The error state

```html
<input class="input" id="cat-name-2" name="name" type="text"
       maxlength="60" value="Groceries" aria-invalid="true"
       aria-describedby="cat-name-2-hint cat-name-2-msg">
<span class="field__error" id="cat-name-2-msg">That name is already in use.</span>
```

- The error renders in the **name field's own** `.field__error` slot, with `aria-invalid="true"` on the input and the error id appended to the `aria-describedby` list.
- The **name keeps the typed value**, so the visitor can correct it.
- The **radio selection is kept**.

### The success state

```html
<div role="status" aria-live="polite">
    <div class="alert alert--success">
        <div class="alert__body">
            <p class="alert__title">The category "Transport" was added.</p>
        </div>
    </div>
</div>
```

- The message names the category: `The category "<name>" was added.` — the name is escaped with `e()` before it goes into the message.
- After a successful create the **name field is cleared** and the **radio selection is kept** — adding a second expense category should not mean re-selecting the type.
- The success is rendered on the **next** `GET /categories` after the redirect (the Post/Redirect/Get pattern), using a flash message in the session. It is not rendered in the response to the POST.

---

## 9. The rename

### The edit-mode row

```html
<!-- filepath: views/categories.php -->
<tr>
    <th scope="row">
        <label class="sr-only" for="cat-rename">Rename Groceries</label>
        <input class="input" id="cat-rename" name="name" type="text"
               value="Groceries" maxlength="60">
    </th>
    <td data-label="Type"><span class="badge badge--expense">Expense</span></td>
    <td data-label="Transactions" class="num">8</td>
    <td data-label="Total" class="num amount amount--expense">−342.60</td>
    <td data-label="Actions">
        <span class="tx__actions">
            <button type="submit" class="btn btn--primary btn--sm">Save</button>
            <a class="btn btn--ghost btn--sm" href="/categories">Cancel</a>
        </span>
    </td>
</tr>
```

- The row in edit mode is inside a **POST form to `/categories/save`** carrying the CSRF token and the category id as a hidden input.
- The **Save** button is `type="submit"`.
- The **Cancel** action is a link back to `/categories`.
- The input has a **screen-reader label** naming the category: *Rename Groceries*.
- The input is pre-filled with the current name and keeps the typed value when the rename fails.

### The failed rename

```html
<input class="input" id="cat-rename-2" name="name" type="text" value="Utilities"
       maxlength="60" aria-invalid="true" aria-describedby="cat-rename-2-msg">
<span class="field__error" id="cat-rename-2-msg">That name is already in use.</span>
```

- The error renders **in the row**, in the row header cell, in the same `.field__error` slot as the create form.
- **A failed rename leaves the row in edit mode with the typed value intact**, so the visitor can correct it rather than retype it. The response is a redirect back to `/categories?rename=<id>` with the typed value carried in the flash state.
- The other rules are the create rules: 1–60 characters, unique per user, case-insensitive.

### What the rename may not change

**A rename changes the name and nothing else.** The `type` of a category is not editable on this screen, and the rename POST must not update it — an attacker who posts a `type` field must not be able to flip a category from expense to income.

---

## 10. The delete

### The confirmation

```html
<!-- filepath: views/categories.php -->
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="cat-del-title" id="cat-del-12">
    <h3 class="modal__title" id="cat-del-title">Delete 'Groceries'?</h3>
    <p>
        <strong>8 transactions will become uncategorised. They will not be deleted.</strong>
    </p>
    <div class="modal__actions">
        <button type="button" class="btn btn--secondary" data-modal-close="cat-del-12">Cancel</button>
        <button type="submit" class="btn btn--danger">Delete category</button>
    </div>
</div>
```

**The most important sentence on this screen**, in the mockup's own words:

> Deleting a category **does not delete its transactions**. The confirmation must
> say so, in those words, before the visitor confirms. A count of one takes the
> singular "transaction" and "will"; a count of many takes the plural.

So the sentence is exactly:

| Count | Sentence |
|---|---|
| 1 | `1 transaction will become uncategorised. It will not be deleted.` |
| n | `n transactions will become uncategorised. They will not be deleted.` |

- The count is the number of the user's transactions **in that category** — the same number the table's Transactions column shows. The mockup contradicts itself here (the Groceries row says **8**, the Groceries modal says **7**); the application uses **one** number, and the mockup must be corrected.
- The title is `Delete '<name>'?` — the name is escaped with `e()`.
- The sentence is in a `<strong>`.
- The **Delete category** button is a `type="submit"` button inside a **POST form to `/categories/delete`**, carrying the CSRF token and the category id. The mockup's `type="button"` is a mockup artefact.
- The modal is an enhancement: **without JavaScript** the confirmation is rendered inline and the button still posts.

### The delete failed

```html
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="cat-del5-title" id="cat-del-failed">
    <h3 class="modal__title" id="cat-del5-title">Delete 'Groceries'?</h3>

    <div role="alert">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">The category could not be deleted. Please try again.</p>
            </div>
        </div>
    </div>

    <p><strong>8 transactions will become uncategorised. They will not be deleted.</strong></p>
    <div class="modal__actions">
        <button type="button" class="btn btn--secondary" data-modal-close="cat-del-failed">Cancel</button>
        <button type="submit" class="btn btn--danger">Try again</button>
    </div>
</div>
```

- **A failed delete keeps the dialog open with the error visible**, so the visitor can retry without re-opening it.
- The error title is `The category could not be deleted. Please try again.`
- The delete button becomes **"Try again"**.
- The HTTP status is 500; the raw driver error goes to `error_log()`.

### What the delete actually does

- `DELETE FROM categories WHERE id = :id AND user_id = :uid`.
- The `transactions` rows are **not** touched. The `ON DELETE SET NULL` on `transactions.category_id` (issue-011 §5) sets the column to `NULL` — the row survives and becomes uncategorised.
- The delete is **not** `ON DELETE CASCADE` from `categories` to `transactions`; that is the whole point of `SET NULL`.
- The statement is scoped by the owner. Another user's category id is a **404**.

---

## 11. The settings screen — the shell

`GET /settings` renders the shared app shell with the nav order **Dashboard ·
Transactions · Reports · Categories**, the `Settings` link carrying
`aria-current="page"` (it is the last item in the top bar, after the spacer),
the `app__user` element and the mobile bottom nav.

The page header:

```html
<!-- filepath: views/settings.php -->
<div class="page__header">
    <div>
        <h1 class="page__title">Settings</h1>
        <p class="page__subtitle">Your account, your password and a read-only view of this installation.</p>
    </div>
</div>
```

The screen is **four cards**, in this order:

1. **Profile** (§12)
2. **Change password** (§13)
3. **Session** (§14)
4. **Installation** (§15)

Each card is its own `<div class="card">` with an `<h2>`. The four forms are
**independent** — posting one never touches another.

---

## 12. The profile

```html
<!-- filepath: views/settings.php -->
<div class="card">
    <h2>Profile</h2>

    <form method="post" action="/settings/profile" aria-label="Profile">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="set-user">Username</label>
            <span class="field__hint" id="set-user-hint">3–32 characters. Letters, digits, underscore and hyphen.</span>
            <input class="input" id="set-user" name="username" type="text"
                   autocomplete="username" value="demo" aria-describedby="set-user-hint">
        </div>

        <div class="field">
            <label class="field__label" for="set-mail">Email</label>
            <span class="field__hint" id="set-mail-hint">Used for the password reset link. It must be unique.</span>
            <input class="input" id="set-mail" name="email" type="email"
                   autocomplete="email" value="demo@example.com" aria-describedby="set-mail-hint">
        </div>

        <div class="field">
            <span class="field__label" id="set-created-label">Created</span>
            <dl class="readonly-list" aria-labelledby="set-created-label">
                <div class="readonly-list__row">
                    <dt>Account created</dt>
                    <dd>12 March 2026</dd>
                </div>
            </dl>
        </div>

        <button type="submit" class="btn btn--primary">Save changes</button>
    </form>
</div>
```

Rules:

- The form posts to `/settings/profile` with the CSRF token.
- The **username** rules are issue-016's registration rules: 3–32 characters, `[A-Za-z0-9_-]`, unique across all accounts.
- The **email** rules are `filter_var(..., FILTER_VALIDATE_EMAIL)` plus a 254-character cap, unique across all accounts, **stored lowercased** (issue-011 §4).
- The **Account created** row is read-only: `users.created_at`, formatted `12 March 2026`.
- The `autocomplete` attributes are `username` and `email` — they are part of the contract with the browser's password manager.
- The primary button is **Save changes**.

### The error state

```html
<input class="input" id="set-user-2" name="username" type="text"
       autocomplete="username" value="administrator" aria-invalid="true"
       aria-describedby="set-user-2-hint set-user-2-msg">
<span class="field__error" id="set-user-2-msg">That username is already taken.</span>
```

- **The uniqueness check belongs to the server.** The email field is **not** rejected when the username conflicts — the error is attached to the offending field only.
- The error renders in the offending field's own `.field__error` slot, and the other field keeps the value the visitor entered.
- The same two messages as the registration screen: *That username is already taken.* and *That email address is already registered.*
- A username or email that is **unchanged** is not a conflict with itself — the uniqueness check must exclude the signed-in user's own row.

### The success state

After a successful save the screen redirects to `/settings` and renders a
success alert:

```html
<div role="status" aria-live="polite">
    <div class="alert alert--success">
        <div class="alert__body">
            <p class="alert__title">Your profile was saved.</p>
        </div>
    </div>
</div>
```

### The empty profile

The mockup has a *"No profile yet"* state — *"This account has no username or email address yet."* It is **not implemented**: `users.username` and `users.email` are `NOT NULL` (issue-011 §4), and registration requires both (issue-016). The state has no reachable counterpart. It is recorded here so the design owner can remove it from the mockup.

---

## 13. The change-password form

```html
<!-- filepath: views/settings.php -->
<div class="card">
    <h2>Change password</h2>

    <form method="post" action="/settings/password" aria-label="Change password">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="pw-current">Current password</label>
            <input class="input" id="pw-current" name="current_password" type="password"
                   autocomplete="current-password">
        </div>

        <div class="field">
            <label class="field__label" for="pw-new">New password</label>
            <span class="field__hint" id="pw-new-hint">At least 10 characters.</span>
            <input class="input" id="pw-new" name="password" type="password"
                   autocomplete="new-password" aria-describedby="pw-new-hint">
        </div>

        <div class="field">
            <label class="field__label" for="pw-new2">Confirm new password</label>
            <input class="input" id="pw-new2" name="password_confirmation" type="password"
                   autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn--primary">Change password</button>
    </form>
</div>
```

Rules:

- The form posts to `/settings/password` with the CSRF token.
- The password policy is issue-016's: **at least 10 characters**, at most 200, **not trimmed**, hashed with `password_hash(PASSWORD_DEFAULT)`.
- The **current password** is verified with `password_verify()`. If it is wrong, the error is *Your current password is not correct.* — attached to the `current_password` field only.
- The two new passwords are compared with `hash_equals()`; a mismatch gives *The two passwords do not match.* — attached to the `password_confirmation` field.
- The password is hashed with `password_hash()` and stored with `password_needs_rehash()` handling.
- **Other sessions are invalidated** — the same rule as the reset-password flow in issue-016. The current session stays signed in.

### The success state

```html
<div role="status" aria-live="polite">
    <div class="alert alert--success">
        <div class="alert__body">
            <p class="alert__title">Your password was changed.</p>
            <p>Use it the next time you sign in.</p>
        </div>
    </div>
</div>
```

- **All three fields are cleared** after a successful change — no password value lingers in the page. The success alert is rendered **above** the form, inside the card.
- The alert is in the `role="status" aria-live="polite"` wrapper.

---

## 14. The session card

```html
<!-- filepath: views/settings.php -->
<div class="card">
    <h2>Session</h2>
    <p class="field__hint">
        You are signed in as <strong>demo</strong>. Signing out ends
        this session on this device.
    </p>

    <form method="post" action="/logout" aria-label="Sign out">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <button type="submit" class="btn btn--secondary">Sign out</button>
    </form>
</div>
```

- **Sign out is a secondary action**, never a primary button. It is deliberately less prominent than "Save changes" — signing out should not be a tap-away.
- **Sign out is a POST.** The mockup shows an `<a>`; the application shows a form with a POST button, because a `GET /logout` can be triggered by any page on the internet (issue-016). **This is a deliberate, documented deviation from the mockup's markup.**
- The card names the signed-in user — the **username**, escaped.
- `views/logout.php` destroys the session, clears the cookie and redirects to `/login`.

---

## 15. The installation card

```html
<!-- filepath: views/settings.php -->
<div class="card">
    <h2>Installation</h2>
    <p class="field__hint">
        These values come from the configuration file. They are shown
        for reference and cannot be changed here.
    </p>

    <dl class="readonly-list">
        <div class="readonly-list__row">
            <dt>Database name</dt>
            <dd>financial_tracker</dd>
        </div>
        <div class="readonly-list__row">
            <dt>Database user</dt>
            <dd>tracker</dd>
        </div>
        <div class="readonly-list__row">
            <dt>SMTP host</dt>
            <dd>smtp.example.com</dd>
        </div>
        <div class="readonly-list__row">
            <dt>SMTP port</dt>
            <dd>587</dd>
        </div>
        <div class="readonly-list__row">
            <dt>SMTP user</dt>
            <dd>tracker@example.com</dd>
        </div>
        <div class="readonly-list__row">
            <dt>Registration allowed</dt>
            <dd>Yes</dd>
        </div>
        <div class="readonly-list__row">
            <dt>Week start</dt>
            <dd>Monday</dd>
        </div>
    </dl>
</div>
```

### The security rule — the strictest on this screen

> This section has **no inputs** — it is a definition list, not a form. And the
> database password and the SMTP password are **absent** from the HTML source
> altogether: not masked-and-present, not truncated, not empty-looking. A secret
> in the source is a secret in the view-source window.

So:

- The **only** values that may appear are: the database name, the database user, the SMTP host, the SMTP port, the SMTP user, whether registration is allowed, and the week start.
- `DB_PASS` and `SMTP_PASS` are **never** referenced by `views/settings.php` — not read, not masked, not passed to the template. A grep for `DB_PASS` and `SMTP_PASS` in the views directory must return **nothing**.
- The card is a `<dl>`, not a form. There is no input, no button, no submit.

### The values

| Row | Constant | Rendering |
|---|---|---|
| Database name | `DB_NAME` | the raw string |
| Database user | `DB_USER` | the raw string |
| SMTP host | `SMTP_HOST` | the raw string; `—` when it is empty (the mailer was skipped at install) |
| SMTP port | `SMTP_PORT` | the integer, unquoted |
| SMTP user | `SMTP_USER` | the raw string; `—` when it is empty |
| Registration allowed | `ALLOW_REGISTRATION` | `Yes` when `ALLOW_REGISTRATION === true`, `No` when it is `false` |
| Week start | `WEEK_START` | `Sunday` for 0, `Monday` for 1, … the day name for 0–6 |

- **`ALLOW_REGISTRATION` is compared as a real boolean.** `ALLOW_REGISTRATION === true` renders `Yes`; `ALLOW_REGISTRATION === false` renders `No`. Never the string `'true'` or `'false'` — the string form fails **open**, which is the exact bug the constant's type exists to prevent.
- The week start is rendered as a **day name**. The mapping is `0 = Sunday`, `1 = Monday`, … `6 = Saturday`. Any value outside 0–6 renders the raw number rather than a wrong day name.
- Every value is escaped with `e()`.
- The values come from `config.php` **only** — never from `getenv()`, `$_ENV` or `$_SERVER`.

---

## 16. The queries

### The category list with its counts and totals

```sql
-- filepath: includes/functions.php
SELECT c.id,
       c.name,
       c.type,
       COUNT(t.id) AS tx_count,
       COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                         WHEN t.type = 'expense' THEN -t.amount END), 0) AS total
FROM categories c
LEFT JOIN transactions t
       ON t.category_id = c.id
      AND t.user_id = c.user_id
WHERE c.user_id = :uid
GROUP BY c.id, c.name, c.type
ORDER BY c.name
```

- The **`LEFT JOIN`** is what gives a category with no transactions a count of `0` and a total of `0.00` — an inner join would drop the row and the category would vanish from the list.
- The join is scoped on the owner, so a transaction belonging to another account can never be counted.
- The total is the **net of the transactions**, computed in SQL. It comes back as a string and is formatted with `formatMoney()`.
- The `ORDER BY c.name` uses the column's collation, which is case-insensitive — the A–Z rule in §6.

### The create

```php
// filepath: includes/functions.php
// ...existing code...
try {
    $db->execute(
        'INSERT INTO categories (user_id, name, type) VALUES (:uid, :name, :type)',
        [':uid' => $userId, ':name' => $name, ':type' => $type]
    );
    $newId = (int) $db->lastInsertId();
} catch (PDOException $e) {
    // 23000 / SQLSTATE 23000 with the unique key → "That name is already in use."
}
// ...existing code...
```

- The duplicate-key error is detected by the **SQLSTATE** (`23000`) and the driver's error code (`1062`), never by parsing the message text.
- The id comes from `lastInsertId()`, not from the return value of `execute()`.

### The rename

```sql
UPDATE categories SET name = :name WHERE id = :id AND user_id = :uid
```

- The statement updates **only** the name. The type is not in the `SET` list.
- The statement is scoped by the owner.

### The delete

```sql
DELETE FROM categories WHERE id = :id AND user_id = :uid
```

- The transactions are **not** in the statement. `ON DELETE SET NULL` does the work.
- The statement is scoped by the owner.

### The profile update

```sql
UPDATE users
   SET username = :username,
       email = :email,
       updated_at = CURRENT_TIMESTAMP
 WHERE id = :uid
```

- The email is **lowercased** before it is bound.
- The uniqueness of both columns is enforced by the database; the duplicate-key error is mapped to the friendly message on the offending field.
- The password hash is **not** in this statement.

### The password update

```sql
UPDATE users
   SET password_hash = :hash,
       updated_at = CURRENT_TIMESTAMP
 WHERE id = :uid
```

- The hash comes from `password_hash($password, PASSWORD_DEFAULT)`.
- `failed_attempts` and `locked_until` are reset to zero on a successful password change — a changed password clears an expired lock.
- Other sessions are invalidated by the same mechanism issue-016 uses for the reset-password flow.

---

## 17. Acceptance criteria

### The categories screen

- [ ] `GET /categories` renders the app shell with the nav order Dashboard · Transactions · Reports · Categories, the `Categories` link carrying `aria-current="page"`, and the `Settings` link after the spacer.
- [ ] `requireLogin()` runs before any query.
- [ ] The page header shows the title `Categories` and the subtitle *Optional labels that group your transactions in the reports.* and has **no** page action.
- [ ] The create card is headed `New category` and its form is labelled `Create a category`.
- [ ] The name field is a text input with `maxlength="60"` and the hint *Up to 60 characters. The name must be unique.*
- [ ] The type control is a `role="radiogroup"` with the values `income` and `expense`, and **Expense is checked by default**.
- [ ] The type labels on this screen are **Income** and **Expense**.
- [ ] The create form posts to `/categories/save` with the CSRF token.
- [ ] The table has the columns Name · Type · Transactions · Total · Actions, with `class="num"` on the two numeric columns.
- [ ] The name is the row header, a `<th scope="row">`, and carries no `data-label`.
- [ ] **Every `<td>` carries a `data-label`**, including the actions cell (`data-label="Actions"`).
- [ ] The Type cell renders `badge--income` with `Income` or `badge--expense` with `Expense`.
- [ ] The Transactions count and the Total are computed by the server for each category.
- [ ] A category with no transactions shows the count `0` and the total as `<span class="amount amount--muted">—</span>`.
- [ ] The Total is signed with U+2212 on the negative and follows the **actual transactions**, not the category's declared type.
- [ ] The list is ordered by name, A–Z, case-insensitive.
- [ ] The table is wrapped in `div.table-wrap` inside `div.card card--flush`.
- [ ] With no categories the table is omitted, the create form is still rendered, and the empty state reads `No categories yet.` / *That is fine — uncategorised entries are still counted in every total and every report.*

### Create

- [ ] The name must be 1–60 characters after trimming; the stored name keeps the visitor's case.
- [ ] The uniqueness is enforced by the database's `UNIQUE (user_id, name)` key, not by a `SELECT` first.
- [ ] The comparison is case-insensitive — `groceries` collides with `Groceries`.
- [ ] A duplicate name renders *That name is already in use.* in the name field's `.field__error` slot, with `aria-invalid="true"` and the error id appended to the `aria-describedby` list.
- [ ] The name keeps the typed value and the radio selection is kept after a failure.
- [ ] A successful create renders *The category "<name>" was added.* in an `alert--success` inside a `role="status" aria-live="polite"` wrapper, with the name escaped.
- [ ] After a successful create the **name field is cleared** and the **radio selection is kept**.
- [ ] The success is rendered on the `GET` after the redirect, never in the response to the POST.

### Rename

- [ ] The Rename action is a link to `/categories?rename=<id>` and the row renders in edit mode server-side.
- [ ] The edit-mode row holds a POST form to `/categories/save` with the CSRF token and the category id.
- [ ] The input has a screen-reader label naming the category (*Rename Groceries*) and is pre-filled with the current name.
- [ ] The Save button is `type="submit"`; the Cancel action is a link back to `/categories`.
- [ ] A failed rename renders *That name is already in use.* in the row's own error slot and **leaves the row in edit mode with the typed value intact**.
- [ ] The rename updates **only** the name — a `type` field in the POST body cannot change the category's type.
- [ ] A `rename` id belonging to another account is ignored and no row is put in edit mode.

### Delete

- [ ] The confirmation title is `Delete '<name>'?` with the name escaped.
- [ ] The confirmation states, in a `<strong>`: `1 transaction will become uncategorised. It will not be deleted.` for a count of one, and `n transactions will become uncategorised. They will not be deleted.` for many.
- [ ] The count in the confirmation is the **same number** as the Transactions column of that row.
- [ ] The Delete category button is a `type="submit"` button inside a POST form to `/categories/delete` carrying the CSRF token and the id.
- [ ] The confirmation is reachable without JavaScript.
- [ ] **Deleting a category does not delete its transactions** — they survive with `category_id` set to `NULL`.
- [ ] The delete statement filters on `user_id` as well as `id`; another account's id is a **404**.
- [ ] A failed delete renders *The category could not be deleted. Please try again.* in a `role="alert"`, **keeps the dialog open**, and turns the button into **"Try again"**.
- [ ] The failed delete returns the HTTP status 500 and logs the raw driver error.

### The settings screen

- [ ] `GET /settings` renders the app shell with the `Settings` link carrying `aria-current="page"`.
- [ ] The page header shows the title `Settings` and the subtitle *Your account, your password and a read-only view of this installation.*
- [ ] The screen is four cards in order: **Profile**, **Change password**, **Session**, **Installation**.
- [ ] The Profile form posts to `/settings/profile` with the CSRF token; the fields are `username` and `email`, with `autocomplete="username"` and `autocomplete="email"`.
- [ ] The hints read *3–32 characters. Letters, digits, underscore and hyphen.* and *Used for the password reset link. It must be unique.*
- [ ] The **Account created** row is read-only and shows `users.created_at` as `12 March 2026`.
- [ ] A duplicate username renders *That username is already taken.* on the username field only; the email field keeps its value and is **not** rejected.
- [ ] A duplicate email renders *That email address is already registered.* on the email field only.
- [ ] The uniqueness check **excludes the signed-in user's own row** — saving an unchanged profile is not a conflict.
- [ ] The email is stored lowercased.
- [ ] A successful profile save renders *Your profile was saved.*

### The change-password form

- [ ] The form posts to `/settings/password` with the CSRF token; the fields are `current_password`, `password` and `password_confirmation`, with `autocomplete` of `current-password`, `new-password`, `new-password`.
- [ ] The new-password field's hint reads *At least 10 characters.*
- [ ] A wrong current password renders *Your current password is not correct.* on the `current_password` field only.
- [ ] A mismatch renders *The two passwords do not match.* on the `password_confirmation` field only.
- [ ] The password policy is issue-016's: at least 10 characters, at most 200, **not trimmed**, hashed with `password_hash(PASSWORD_DEFAULT)`.
- [ ] A successful change renders *Your password was changed.* / *Use it the next time you sign in.* and **clears all three fields** — no password value lingers in the page.
- [ ] A successful change invalidates the account's **other sessions** and keeps the current one signed in.

### The session card

- [ ] The card is headed `Session` and says *You are signed in as **demo**. Signing out ends this session on this device.* with the username escaped.
- [ ] Sign out is a **secondary** button, never a primary one.
- [ ] Sign out is a **POST** to `/logout` inside a form with the CSRF token — a `GET /logout` does not exist.

### The installation card

- [ ] The card is headed `Installation` with the hint *These values come from the configuration file. They are shown for reference and cannot be changed here.*
- [ ] The card is a `<dl class="readonly-list">` with **no inputs**.
- [ ] The seven rows are Database name, Database user, SMTP host, SMTP port, SMTP user, Registration allowed, Week start — and no others.
- [ ] **`DB_PASS` and `SMTP_PASS` appear nowhere in the HTML source** — not masked, not truncated, not empty-looking.
- [ ] `views/settings.php` never references `DB_PASS` or `SMTP_PASS` at all.
- [ ] **Registration allowed** renders `Yes` when `ALLOW_REGISTRATION === true` and `No` when it is `false` — never the strings `'true'` / `'false'`.
- [ ] **Week start** renders the day name (`Sunday` for 0, `Monday` for 1, …).
- [ ] An empty SMTP host or user renders an em dash.
- [ ] Every value comes from `config.php` — never from `getenv()`, `$_ENV` or `$_SERVER`.

### The data and the queries

- [ ] Every statement that reads or writes a category or a user row filters on the signed-in user's id.
- [ ] The category list's `LEFT JOIN` to `transactions` is scoped on the owner.
- [ ] The category totals are computed in SQL, read back as strings and formatted with `formatMoney()`.
- [ ] The new category's id comes from `lastInsertId()`, not from the return value of `execute()`.
- [ ] The duplicate-key error is detected by the SQLSTATE and the driver code — never by matching the message text.
- [ ] No `?>` or `<?php` appears inside a `//` comment in any file written for this issue.
- [ ] Every PHP file written for this issue passes `php -l`.

---

## 18. Verification

Run from the project root. `php` is **not** on the host PATH — every command runs
inside the container.

### 18.1 Lint

```bash
docker compose exec -T app php -l /var/www/html/views/categories.php
docker compose exec -T app php -l /var/www/html/views/category_save.php
docker compose exec -T app php -l /var/www/html/views/category_delete.php
docker compose exec -T app php -l /var/www/html/views/settings.php
docker compose exec -T app php -l /var/www/html/views/logout.php
docker compose exec -T app php -l /var/www/html/includes/functions.php
```

### 18.2 The secrets are absent from the source

```bash
curl -s -b cookies.txt http://localhost:8088/settings > /tmp/settings.html

for s in DB_PASS SMTP_PASS db_password smtp_password; do
  echo "$s -> $(grep -c "$s" /tmp/settings.html)"
done
```

Every count must be **0**. Then the stronger check — the constants must not even
be referenced in the views:

```bash
# The strict rule: the views never name the secrets at all.
grep -rn 'DB_PASS\|SMTP_PASS' views/          # expect nothing

# Everywhere else, only the two files that legitimately *use* a password.
grep -rn 'DB_PASS\|SMTP_PASS' includes/ | grep -v 'config.template'
```

The second command must return exactly two hits:

```
includes/Database.php:96:        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
includes/mailer.php:67:    $mail->Password   = SMTP_PASS;
```

`includes/Database.php` opens the connection with the database password and
`includes/mailer.php` authenticates with the SMTP password. They are the code
that **uses** the secret, not the code that **shows** it. The file that
**writes** `config.php` (the installer, issue-015) is allowed as well.

### 18.3 The seven installation rows, and only seven

```bash
# The installation card is the only <dl class="readonly-list"> WITHOUT an
# aria-labelledby — the Profile card's "Account created" row is not one of
# the seven, so the block is extracted first.
sed -n '/<dl class="readonly-list">/,/<\/dl>/p' /tmp/settings.html \
  | grep -o '<dt>[^<]*</dt>'
```

The output must be exactly the seven rows in §15 — and it must contain **no**
"Password" row.

### 18.4 The registration flag is a real boolean

```bash
curl -s -b cookies.txt http://localhost:8088/settings | grep -o 'Registration allowed</dt>[^!]*<dd>[^<]*'
```

The value must be `Yes` or `No` — never `true` or `false`.

### 18.5 The category list

```bash
curl -s -b cookies.txt http://localhost:8088/categories > /tmp/categories.html
grep -c 'data-label=' /tmp/categories.html
grep -o '<td[^>]*>' /tmp/categories.html | grep -cv 'data-label='   # expect 0
```

### 18.6 Deleting a category does not delete its transactions

```bash
# before
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
  "SELECT COUNT(*) AS tx_total FROM transactions WHERE user_id = 1;"

# delete the category
curl -s -b cookies.txt -X POST -d "csrf_token=<TOKEN>&id=<CATEGORY_ID>" \
  http://localhost:8088/categories/delete -o /dev/null -w '%{http_code}\n'

# after — the transaction count is UNCHANGED and the rows are now uncategorised
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
  "SELECT COUNT(*) AS tx_total,
          SUM(category_id IS NULL) AS uncategorised
     FROM transactions WHERE user_id = 1;"
```

The `tx_total` must be **unchanged**, and the uncategorised count must have
risen by the category's transaction count.

### 18.7 The delete confirmation names the right count

```bash
curl -s -b cookies.txt "http://localhost:8088/categories" | grep -o '[0-9]* transactions\? will become uncategorised'
```

The number must equal the row's Transactions count.

### 18.8 A duplicate name is rejected

```bash
curl -s -b cookies.txt -X POST \
  -d "csrf_token=<TOKEN>&name=Groceries&type=expense" \
  http://localhost:8088/categories/save | grep -o 'That name is already in use.'
```

And case-insensitively:

```bash
curl -s -b cookies.txt -X POST \
  -d "csrf_token=<TOKEN>&name=groceries&type=expense" \
  http://localhost:8088/categories/save | grep -o 'That name is already in use.'
```

### 18.9 The rename cannot change the type

```bash
curl -s -b cookies.txt -X POST \
  -d "csrf_token=<TOKEN>&id=<CATEGORY_ID>&name=Transport&type=income" \
  http://localhost:8088/categories/save -o /dev/null

docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
  "SELECT name, type FROM categories WHERE id = <CATEGORY_ID>;"
```

The `type` must be **unchanged**.

### 18.10 Ownership

```bash
# as user B, with a valid session
curl -s -o /dev/null -w '%{http_code}\n' -b cookies-b.txt -X POST \
  -d "csrf_token=<TOKEN_B>&id=<USER_A_CATEGORY_ID>" \
  http://localhost:8088/categories/delete
```

Must be **404**.

### 18.11 Sign out is POST-only

```bash
curl -s -o /dev/null -w '%{http_code}\n' "http://localhost:8088/logout"
```

The `GET` must **not** be a success and must **not** end the session.

### 18.12 The whole suite still passes

```bash
.github/skills/dev-stack-ops/scripts/run-tests.sh
```

---

## 19. Rollback

1. Remove `views/categories.php`, `views/category_save.php`, `views/category_delete.php`, `views/settings.php` and `views/logout.php`.
2. Remove the seven route entries from the route table in `index.php` (issue-014).
3. Remove `assets/css/pages/categories.css` and `assets/css/pages/settings.css` and their `<link>` elements from the layout.
4. Restart the app so the route table is re-read.

**The database is not rolled back.** The `categories` table from issue-011 stays.
If the schema itself has to go, it is dropped by hand — **never**
`docker compose down -v`, which takes the whole MariaDB volume with it.

---

## 20. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Deleting a category cascades into its transactions | **Critical** | `transactions.category_id` is `ON DELETE SET NULL`, never `CASCADE` (issue-011 §5). §10 and acceptance criterion 18.6 verify the transaction count is unchanged after a delete. |
| The database password or the SMTP password leaks into the page source | **Critical** | The installation card is a `<dl>` with no inputs; §15 lists the only seven values that may appear; §18.2 greps for both constants and asserts zero hits in `views/`. |
| `ALLOW_REGISTRATION` is compared as a string, so registration fails **open** | **High** | The card compares `ALLOW_REGISTRATION === true` / `=== false`. The string form is the exact bug the boolean type exists to prevent (issue-016, `database/config_test.php`). |
| Sign out becomes a `GET`, so any page can sign the user out | Medium | The route is `POST /logout` with a CSRF token; the mockup's `<a>` is a documented deviation (§14); §18.11 asserts `GET /logout` does not end the session. |
| The uniqueness check is a `SELECT` before the `INSERT`, so two concurrent creates race | Medium | The check is the database's `UNIQUE (user_id, name)` constraint; the duplicate-key error is caught and mapped (§16). |
| The rename POST also updates the category's `type` | Medium | The rename statement's `SET` list contains only `name` (§16); §18.9 verifies the type is unchanged even when the POST carries one. |
| A category's total is computed in PHP, so `0.1 + 0.2` shows up | Medium | The total is a SQL `SUM` over `DECIMAL(14,2)`, read back as a string and formatted with `formatMoney()` (§16). |
| The `LEFT JOIN` to `transactions` is not scoped by the owner, so another account's transactions are counted | High | The join carries `AND t.user_id = c.user_id` (§16); the same pattern is required in the transaction list (issue-018). |
| The rename state is done only in JavaScript, so it does not work without it | Medium | The `?rename=<id>` URL is the contract (§3); the row is rendered server-side and the form posts to `/categories/save`; the JavaScript is an enhancement. |
| The delete confirmation shows a count that disagrees with the row | Medium | One count, one query, used in both places (§6, §10); §18.7 compares the two. |
| The mockup's "Expense" label and the transaction screens' "Spending" label drift apart | Low | §5.1 fixes the stored value as `expense` everywhere and records the copy difference for the design owner; the unification is a mockup change first. |
| The password change does not invalidate the other sessions | Medium | §13 requires the same invalidation as issue-016's reset-password flow. |
| The account-created date is rendered in the server's timezone rather than UTC | Low | `users.created_at` is stored in UTC; the rendering uses the app's `TIMEZONE` constant, not the server default. |

---

## 21. Out of scope

- **Editing a category's type.** The rename changes the name only (§9). A separate "change the type" feature needs its own mockup.
- **Reordering the categories.** There is no drag-and-drop and no sort control; the order is fixed by §6.
- **Bulk operations** — selecting several categories and deleting them at once.
- **Category icons, colours or emoji.** The `type` badge is the only visual distinction.
- **Changing the installation values from the settings screen.** The card is read-only by design (§15). The installer writes `config.php`; the operator edits the file.
- **Rotating the SMTP password from the settings screen.** Same reason.
- **The account-deletion feature** — "delete my account and all my transactions".
- **An avatar or a display name** separate from the username.
- **Two-factor authentication.**
- **The "no profile yet" state** — unreachable with the `NOT NULL` columns of issue-011 (§12).
- **The `ALLOW_REGISTRATION` toggle in the UI.** It is an installer choice and a `config.php` edit; a UI switch would need a config-write path the application deliberately does not have.
- **The weekly or monthly digest by email.**
- **Export to CSV.** A later track; the reports screen (issue-019) is the source of the data.

---

## Implementation notes — 2026-10-29

### What was built

| File | Role |
|---|---|
| `views/categories.php` | The list, the create form, the empty state, the rename state and the delete dialogs. It is **re-entrant**: `views/category_delete.php` renders it when a delete fails. |
| `views/category_save.php` | The write path of the create **and** the rename — one handler, two shapes of the same POST. |
| `views/category_delete.php` | The write path of the delete. |
| `views/settings.php` | The three settings routes: `GET /settings`, `POST /settings/profile`, `POST /settings/password`. |
| `assets/css/pages/categories.css` | The stylesheet the categories screen needs. |
| `assets/css/pages/settings.css` | The stylesheet the settings screen needs. |
| `database/categories_test.php` | The guard — the sources, and a sandbox that runs the real screens. |
| `database/settings_test.php` | The guard — the sources, and a sandbox that runs the real screens. |

The helpers the two screens share live in `includes/functions.php`:
`postRaw()`, `isDuplicateKeyError()`, `validateCategoryInput()`,
`categoryTypeLabel()`, `formatCategoryTotal()`, `uncategorisedNotice()`,
`validateProfileInput()`, `profileUniquenessErrors()`,
`usernameValidationMessage()`, `validatePasswordChangeInput()`,
`weekStartName()`, `formatAccountDate()`, `requestPath()` and `redirect()`.

### The decisions the specification left open

1. **The rename input is bound to its form with the HTML5 `form` attribute.** The input lives in the row-header cell and the buttons in the actions cell, and a form cannot span table cells. The input therefore carries `form="cat-rename-form-<id>"` and the form carries the matching `id` — the row is one form, split across two cells.
2. **The failed delete is not a flash.** A redirect cannot carry a status code, and the failed delete must answer **500** with the dialog open. `views/category_delete.php` sets `$deleteFailure` and **requires** `views/categories.php`, which answers 500 and renders the dialog with `data-modal-keep` — the flag the modal script honours when it decides whether to hide a dialog on load.
3. **Every POST ends with a redirect.** The success of a create, a rename, a delete, a profile save and a password change is confirmed on the **next** `GET`, out of a flash in the session. `redirect()` answers a **302** with an empty body, so a POST never renders the confirmation itself.
4. **The uniqueness check is a `SELECT`, and the race is closed by the constraint.** The `SELECT` gives the friendly message; the `UNIQUE` key is what actually stops the second insert, and the duplicate-key error is caught and mapped to the same message (§16).
5. **`views/settings.php` serves three routes.** One view, dispatched on the request method and `requestPath()` — the profile and the password are independent forms and never touch each other.

### The defect the test found

**The password errors were never rendered.** `views/settings.php` assigned `''` to `$passwordError` *before* the `if ($passwordError !== null)` check, so the guard was always false and a wrong current password, a mismatch and a short password were all **accepted silently** — the form came back with no error at all. The three messages now have their own variables (`$currentPasswordError`, `$newPasswordError`, `$confirmationError`), and the new-password input uses `$newPasswordError` for its `aria-describedby`, its `aria-invalid` and its error slot.

### The mockups that changed with the screens

| File | Change |
|---|---|
| `docs/mockup/09-categories.html` | The actions cell carried an **empty** `data-label=""` — the defect §6 names. It is `data-label="Actions"` now, in all seven rows. The **Groceries** row said 8 transactions and the **Groceries** dialog said 7 — §10 calls the mockup out and says the application uses **one** number, so both are 8 now. The submit-looking buttons were `type="button"`; they are real `type="submit"` buttons now, and the Rename action is the **link** to `?rename=<id>` that §3 specifies. The empty state omitted the create form; §7 says the form is still rendered, so it is there now. |
| `docs/mockup/10-settings.html` | The **"No profile yet"** state has no reachable counterpart (`users.username` and `users.email` are `NOT NULL`) — §12 records it for the design owner and it is removed. The submit-looking buttons are real submits now, and the **profile saved** state of §12 is added. The sign-out stays an `<a>` in the mockup: the POST form in the application is a **documented deviation** (§14), not a mockup defect. |

### The two verification commands that were not precise

1. **§18.2** listed only `includes/mailer.php` as a legitimate hit for `DB_PASS` / `SMTP_PASS`. `includes/Database.php:96` opens the PDO connection with `DB_PASS` — it is the code that *uses* the secret, not the code that *shows* it. The command now names both files, and the strict rule is the first one: **the views never mention the constants.**
2. **§18.3** grepped every `<dt>` on the page, but the **Profile** card has a `<dt>Account created</dt>` of its own, so the command returned eight rows for a seven-row card. It is now scoped to the installation `<dl>` — the only one without an `aria-labelledby`.

### What the guards cover

`database/categories_test.php` and `database/settings_test.php` each check two tiers:

- **A — the sources.** `requireLogin()` is the first call of every view; the CSRF token is validated **before** any input is read; the ownership is in the SQL; every dynamic value is escaped; the two `UPDATE` statements are scoped on the signed-in user's id; the profile statement never touches `password_hash` and the password statement clears `failed_attempts` and `locked_until`; the session's credential version is refreshed after a password change; `DB_PASS` and `SMTP_PASS` are named nowhere in `views/`; the installation card has exactly seven rows and no input; the page stylesheets use the tokens only.
- **B — the behaviour.** A sandbox copy of the application, served by the PHP built-in server against a scratch database, answers the real requests: the guard redirects a guest; the screens render; the counts and the totals are checked **against the database**; the create, the duplicate, the invalid create, the rename and the delete each answer with the right status and the right flash; the profile save updates the row and lowercases the email; a duplicate username is refused without rejecting the email, and the other way round; a wrong current password, a mismatch and a short password are each refused and the hash is untouched; a valid change invalidates the other session, keeps the current one, and the new password signs in while the old one does not; the password is **not** trimmed.

### The verification

```bash
docker compose exec -T app php -l /var/www/html/views/categories.php
docker compose exec -T app php -l /var/www/html/views/category_save.php
docker compose exec -T app php -l /var/www/html/views/category_delete.php
docker compose exec -T app php -l /var/www/html/views/settings.php
docker compose exec -T app php -l /var/www/html/includes/functions.php
docker compose exec -T app php /var/www/html/database/categories_test.php
docker compose exec -T app php /var/www/html/database/settings_test.php
.github/skills/dev-stack-ops/scripts/run-tests.sh
```

All of them pass, and the whole suite is green: **13 PASS / 0 FAIL**.
