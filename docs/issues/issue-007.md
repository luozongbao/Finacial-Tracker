# Issue-007 — UX/UI: reports, categories and settings screens

> **Status:** ready for UX/UI pickup.
> **Target:** v0.1 (design foundation).
> **Severity:** **n/a** — design deliverable, no application impact.
>
> **Requested by the user:**
> > "alsow write issue files for UX/UI design to write mockup html in /docs/mockup/"
>
> **Depends on:** [issue-004.md](issue-004.md) (tokens, shell, components).
> **Related:** `docs/About.md` — "search and sum over a time period (day, week,
> month, year)".

---

## Table of contents

1. Objective
2. Deliverables
3. Constraints
4. `08-reports.html` — period reports
5. `09-categories.html` — category management
6. `10-settings.html` — profile and settings
7. Acceptance criteria
8. Verification
9. Handoff notes for the application implementer
10. Out of scope

---

## 1. Objective

> Deliver the three remaining product screens — the **period reports** that
> are the product's stated output, the **category management** that organises
> the ledger, and the **settings** screen — as mockups that specify the
> rollup structure, the empty states and the destructive-action patterns.

Success criteria:

| # | Criterion |
|---|---|
| 1 | The three files exist and render standalone from `file://` |
| 2 | The reports screen shows a **day / week / month / year** rollup with the right aggregation |
| 3 | The reports screen shows a **category breakdown** that adds up to the period total |
| 4 | The categories screen covers **create, rename, change type, delete** — including what happens to the entries in a deleted category |
| 5 | The settings screen covers **profile, password change, sign out** — and shows a read-only view of the operator's settings |
| 6 | Every screen handles its own empty state |

---

## 2. Deliverables

| File | Screen | Purpose |
|---|---|---|
| `08-reports.html` | Reports | Day / week / month / year rollups plus a category breakdown. |
| `09-categories.html` | Categories | Create, rename, re-type and delete categories. |
| `10-settings.html` | Settings | Profile, password change, sign out, and a read-only view of installation settings. |

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| Period grouping belongs **in the SQL** | The reports screen shows a rollup the server computed. The browser never groups, sums or re-formats. |
| The week starts on **Monday** (`WEEK_START = 1`) | The week row label must show a Monday-to-Sunday range. |
| Amounts are **`DECIMAL(14,2)`** | Every total is formatted server-side and shown with a sign and a currency symbol. |
| `transactions.category_id` is **nullable** | The report must account for **uncategorised** spending; the breakdown must reconcile with the total. |
| A category may be **income-type or expense-type** | The category manager shows the type, and the transaction form may filter by it. |
| Ownership is enforced **in the SQL** | The reports query filters on the owning user id. The UI offers no user switcher. |
| The application is server-rendered | The reports page is a server-rendered rollup with query-string parameters for the period. |
| `config.php` is the **only** configuration source | The settings screen may **show** the SMTP configuration, but must never **edit** it — editing it is `install.php`'s job, and `install.php` is deleted after install. |
| `ALLOW_REGISTRATION` is a **real boolean** | The settings screen may show its state as read-only information. |

---

## 4. `08-reports.html` — period reports

This is the screen the brief is actually about: *"search and sum over a time
period (day, week, month, year)."*

### Structure

1. **App shell.**
2. **Page title** — "Reports".
3. **Period selector** — `Day · Week · Month · Year`, plus a **custom range**
   when the user needs one. Each option is a link with a query parameter (the
   server renders the active one).
4. **Period summary** — the period label ("October 2026") and the
   income / spending / net trio.
5. **The rollup table** — one row per period unit inside the selected period:

| Period | Rows | Columns |
|---|---|---|
| **Day** | One row per day in the selected range. | Date · Income · Spending · Net |
| **Week** | One row per week, starting **Monday**. | Week of… · Income · Spending · Net |
| **Month** | One row per month. | Month · Income · Spending · Net |
| **Year** | One row per year. | Year · Income · Spending · Net |

Each row is a **link** to that unit's own period view — drilling down from a
year to a month to a week to a day.

6. **Category breakdown** — for the selected period:

| Column | Notes |
|---|---|
| Category | A badge; "Uncategorised" appears as a neutral row when there is any. |
| Transactions | The count. |
| Income | Right-aligned, signed, coloured. |
| Spending | Right-aligned, signed, coloured. |
| Net | Right-aligned, signed. |
| Share | A percentage bar. Optional — if shown, it is a visual aid and the numbers remain the source of truth. |

7. **Export** — *not* in scope. See §10.

### States

| State | What is shown |
|---|---|
| **Populated** | The default. |
| **Empty — no transactions in the period** | The summary shows zeroed tiles, the rollup table is replaced by the empty state ("No transactions in this period."), and the breakdown is replaced by the same empty state. |
| **A period with no transactions but others that have some** | The rollup keeps the other rows. Only the empty rows are absent — the server does not invent zero rows. |
| **Loading** | Table and breakdown skeletons. |
| **Error** | One alert + retry. The tables are replaced by a placeholder, **not** by zeroed numbers — a failed query must never look like "you spent nothing". |
| **Custom range with an invalid span** (from > to) | A field-level message: "The start date must be on or before the end date." |

### Design notes

- The rollup table is the centre of the screen; the breakdown sits below it.
- Column alignment: dates and categories left, all amounts right and
  monospaced.
- Every row's net carries a sign, and a net of exactly zero is neutral.
- The "drill down" affordance must be obvious but quiet: the row label is a
  link, the row itself is not a card with a big button.

---

## 5. `09-categories.html` — category management

### Structure

1. **App shell.**
2. **Page title** — "Categories".
3. **A create form** (at the top of the list):

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| Name | `text`, max 60 | yes | Non-empty after trimming; **unique per user** | |
| Type | radio — Income / Expense | yes | one of the two | |

   Primary action: **Add category**.
4. **The category list** — a table:

| Column | Notes |
|---|---|
| Name | The category name. |
| Type | A badge: "Income" / "Expense". |
| Transactions | The number of entries in that category. |
| Total | The net for that category. Signed, right-aligned, monospaced. |
| Actions | **Rename**, **Delete**. |

5. **Delete confirmation** — a modal (or an inline confirm row) naming the
   category and stating exactly what will happen to its entries.

### The delete decision — what happens to the entries

This must be settled in the design, because the application has to implement
one behaviour.

**Recommendation: deleting a category does not delete its transactions.**
The entries become **uncategorised**. The confirmation says so in plain
words:

> "Delete 'Groceries'? **7 transactions will become uncategorised.** They will
> not be deleted."

This is the safe default: a category is a label, not a container of data.

> If the application instead wants to delete the entries too, the
> confirmation must say so explicitly and name the count — and the delete
> must be a `POST` with a CSRF token. The mockup must show **one** of the two
> behaviours, not both.

### States

| State | What is shown |
|---|---|
| **Populated** | The default. |
| **No categories yet** | The create form still renders; the list area shows an empty state: "No categories yet." + a hint that uncategorised entries are fine. |
| **Create — validation error** | A message under the name field: "That name is already in use." (the uniqueness is per user). |
| **Create — success** | The new category appears in the list. The create form keeps its name field empty and the type radio keeps its selection. |
| **Delete confirmation** | The confirm dialog, naming the category and the number of affected entries. |
| **Delete — error** | The dialog shows an error and stays open; the category is not removed. |
| **Rename — error** | The row stays in edit mode with the error attached. |

### Design notes

- A category that is in use shows its transaction count — the number is what
  makes the delete confirmation meaningful.
- The "add category" form and the list are visually distinct: the form is a
  small block at the top, the list is a table below.
- The delete action is a **danger** link/button, visually separated from
  rename.

---

## 6. `10-settings.html` — settings

### Structure

1. **App shell.**
2. **Page title** — "Settings".
3. **Profile section:**

| Field | Type | Notes |
|---|---|---|
| Username | `text` | Editable, with the uniqueness rule. |
| Email | `email` | Editable, with the uniqueness rule. |
| Created | a date | Read-only. |

4. **Change password section:**

| Field | Type | Notes |
|---|---|---|
| Current password | `password` | Required — a password change always requires it. |
| New password | `password` | Minimum length requirement stated. |
| Confirm new password | `password` | Must match. |

   Primary action: **Change password**.
5. **Account section:**

| Element | Notes |
|---|---|
| **Sign out** | A secondary action, not a danger action. |
| **Delete account** — *out of scope*; see §10. | |

6. **Installation section — read-only.** The user can see, but not change:

| Item | Shown as |
|---|---|
| Database name | read-only text (not the password) |
| SMTP host and port | read-only text |
| SMTP user | read-only text (not the password) |
| Registration allowed | a yes/no read-only line |
| Week start | a read-only line, e.g. "Monday" |

> **No secrets are shown.** The database password and the SMTP password are
> never rendered — not masked, not truncated, not present in the HTML source
> at all. This is a hard rule.
>
> These values come from `config.php`, which is the only configuration
> source. They are **display only**. Changing them means re-running the
> installer, which is a documented, deliberate action — not a form on this
> page.

### States

| State | What is shown |
|---|---|
| **Populated** | The default. |
| **Profile — save success** | A success notice. |
| **Profile — validation error** | A message under the offending field. |
| **Profile — server rejection** | A message: "That username is already taken." |
| **Password — wrong current password** | A message under the current-password field. |
| **Password — new passwords differ** | A message under the confirmation field. |
| **Password — success** | A success notice, and the password fields are cleared. |
| **Password — server error** | The entered values are preserved; one alert above the form. |

### Design notes

- The read-only installation section is visually distinct — it is information,
  not a form. No inputs, no disabled buttons; plain text rows.
- The password section is a separate card from the profile section. A user
  editing their email should not be near a "delete"-styled control.

---

## 7. Acceptance criteria

- [ ] The three files exist, link the shared stylesheets by relative path, and render from `file://`.
- [ ] `08-reports.html` shows all four period rollups (day, week, month, year) and the custom range.
- [ ] The week rollup row labels start on **Monday**.
- [ ] The rollup table's amount columns are right-aligned and monospaced.
- [ ] The category breakdown includes an **"Uncategorised"** row when there is uncategorised activity, and the numbers reconcile with the period total.
- [ ] The error state does **not** show zeroed numbers.
- [ ] `09-categories.html` has a create form (name + type) and a list with rename and delete actions.
- [ ] The delete confirmation names the category and states what happens to its transactions.
- [ ] "Uncategorised" is styled neutrally in the breakdown and in the category list.
- [ ] `10-settings.html` has a profile section, a change-password section and a read-only installation section.
- [ ] The installation section shows **no** database password and **no** SMTP password anywhere in the HTML source.
- [ ] The settings page has **no** editable inputs for the installation settings.
- [ ] Each screen has its own empty state.
- [ ] All three screens fit 360 px without horizontal scrolling.
- [ ] All three screens are complete in light and dark mode.

---

## 8. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 1. The files exist
ls -la docs/mockup/08-*.html docs/mockup/09-*.html docs/mockup/10-*.html

# 2. Relative paths only
grep -RIn 'href="/\|src="/' docs/mockup/0[89]-*.html docs/mockup/10-*.html && echo "FAIL: absolute path" || echo "OK"

# 3. No inline scripts
grep -RIn '<script>' docs/mockup/0[89]-*.html docs/mockup/10-*.html && echo "FAIL: inline script" || echo "OK"

# 4. No literal colours outside tokens.css
grep -RInE '#[0-9a-fA-F]{3,8}\b' docs/mockup/0[89]-*.html docs/mockup/10-*.html && echo "FAIL: literal colour" || echo "OK"

# 5. Every screen has exactly one <h1>
for f in docs/mockup/08-*.html docs/mockup/09-*.html docs/mockup/10-*.html; do
  printf "%-42s h1=%s\n" "$f" "$(grep -c '<h1' "$f")"
done

# 6. The settings screen must not leak a password placeholder
grep -iE 'password["'\'']?\s*[:=]\s*["'\''][^"'\'']+' docs/mockup/10-settings.html && echo "FAIL: secret in markup" || echo "OK: no secret value"

# 7. The week rollup starts on a Monday
grep -oE 'Week of [0-9]+ [A-Za-z]+ ?[0-9]{4}' docs/mockup/08-reports.html | head -3

# 8. It renders in the viewer
docker compose up -d mockup
for f in 08-reports 09-categories 10-settings; do
  printf "%-26s " "$f"
  curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:${MOCKUP_PORT:-8089}/$f.html"
done
````

---

## 9. Handoff notes for the application implementer

| Mockup element | Application obligation |
|---|---|
| The period links | Query parameters, not a client-side state machine. The group expression lives in **SQL** (`DATE_FORMAT`, `YEARWEEK`, …) chosen from an allow-list. |
| The rollup rows | One grouped query per period. Never a PHP loop over the rows. |
| The breakdown | One grouped query filtered by the same predicate as the summary, so the numbers agree. |
| "Uncategorised" | A `LEFT JOIN` with a `NULL` category, aggregated separately. It is **not** a category row that happens to be empty. |
| The category list | Ownership filtered **in the SQL**. Names are unique per user, enforced by the schema and re-checked by the server. |
| The delete confirmation | The affected count is computed by the **server** and rendered into the confirmation. The client never counts. |
| The settings read-only block | Read from `config.php`. Only the **non-secret** constants are rendered. |
| Every mutating form | A CSRF token, validated **before** any input is read. |

---

## 10. Out of scope

- **Export to CSV or PDF.**
- **Charts.** See issue-006 — a chart is not specified.
- **Budgets and alerts.**
- **Deleting the user account.** Not in the brief, and it raises data-retention
  questions that are not the designer's to answer.
- **Changing the installation settings from the UI.** `config.php` is written
  by `install.php` only.
- **A category icon or colour picker.** The categories carry a type, not a
  colour.
- **Timezone settings.** The application runs in UTC; the week-start rule is
  a single constant.
