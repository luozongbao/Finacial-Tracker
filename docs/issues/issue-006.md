# Issue-006 — UX/UI: dashboard and transaction screens (summary, list, entry form)

> **Status:** ready for UX/UI pickup.
> **Target:** v0.1 (design foundation).
> **Severity:** **n/a** — design deliverable, no application impact.
>
> **Requested by the user:**
> > "alsow write issue files for UX/UI design to write mockup html in /docs/mockup/"
>
> **Depends on:** [issue-004.md](issue-004.md) (tokens, shell, components).
> **Related:** `.github/instructions/database.instructions.md` — the
> `transactions` table and the money rules.

---

## Table of contents

1. Objective
2. Deliverables
3. Constraints
4. The data the screens must express
5. `05-dashboard.html` — dashboard
6. `06-transactions.html` — transaction list, search and filters
7. `07-transaction-form.html` — add / edit a transaction
8. Acceptance criteria
9. Verification
10. Handoff notes for the application implementer
11. Out of scope

---

## 1. Objective

> Deliver the three screens that carry the product's core loop — **see the
> period**, **search the ledger**, **record an entry** — as mockups that
> specify the aggregation, the filtering and the money handling precisely
> enough to be built without further interpretation.

Success criteria:

| # | Criterion |
|---|---|
| 1 | The three files exist and render standalone from `file://` |
| 2 | The dashboard always shows **income, spending and net together** |
| 3 | The period selector offers **day / week / month / year** and a custom range |
| 4 | The transaction list expresses **every filter** the product needs, including text search |
| 5 | The entry form makes **sign, amount, date and category** unambiguous |
| 6 | Empty, loading, error and success states are all present |
| 7 | Money is formatted as money everywhere, with a visible sign |

---

## 2. Deliverables

| File | Screen | Purpose |
|---|---|---|
| `05-dashboard.html` | Dashboard | The landing page after sign-in: the period summary and a short recent list. |
| `06-transactions.html` | Transaction list | Search, filter, page and act on the full ledger. |
| `07-transaction-form.html` | Add / edit an entry | Record income or spending. |

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| Income and spending live in **one** table with a `type` column | The UI never splits them into two lists. One list, one sign convention, one filter. |
| Amounts are **`DECIMAL(14,2)`** | The UI formats money exactly as the server does — `number_format()` semantics: thousands separators, always two decimals, a currency symbol, and a sign. |
| **Sums are computed in SQL** | Every total on screen is a value the server sent. The mockup must not imply a browser-side sum. |
| Period grouping is **day / week / month / year**, and the week starts on **Monday** (`WEEK_START = 1`) | The period selector and every period label must agree with that rule. |
| Dates are stored as **`DATE` in UTC** | The mockup shows a date, not a timestamp, and does not display a time of day. |
| `transactions.category_id` is **nullable** | "No category" is a valid, non-error state. The design must offer it without a warning tone. |
| A transaction belongs to **exactly one user** | The UI never offers a user picker and never shows another user’s data. |
| The application is **server-rendered** | The list is a server-side page with a query string. Filters are a form, not a client-side state machine. |
| The entry form is **server-validated** | The design shows the server-rejection state, not only the client-side one. |

---

## 4. The data the screens must express

The mockups use **placeholder data** only. The shape must be exactly right,
because the implementation copies it.

### 4.1 A transaction row

| Field | Example placeholder | Notes |
|---|---|---|
| Date | `2026-10-24` | The date the entry is **on**, not the date it was recorded. |
| Description / note | "Weekly groceries" | Free text. May be empty. |
| Category | "Groceries" | May be **uncategorised** — show "Uncategorised" as a neutral label, never as an error. |
| Type | income / expense | Drives the sign and the badge. |
| Amount | `−42.80` / `+1,200.00` | The sign is always visible. The currency symbol is placed consistently. |
| Row actions | Edit, Delete | Delete asks for confirmation. |

### 4.2 The summary trio

Every period view shows the same three numbers, in the same order, in the same
place:

| Tile | Sign | Colour |
|---|---|---|
| **Income** | always `+` | `--success-*` |
| **Spending** | always `−` | `--danger-*` |
| **Net** | `+`, `−`, or **neither** when exactly zero | `--success-*` / `--danger-*` / **neutral** |

> **Net exactly zero is neutral.** Never green, never red. This is a rule, not
> a preference.

### 4.3 The period label

| Period | Label shape | Example |
|---|---|---|
| Day | The date | "Friday, 24 October 2026" |
| Week | The week range | "Week of 19 – 25 October 2026" |
| Month | The month | "October 2026" |
| Year | The year | "2026" |
| Custom | The chosen range | "1 – 31 October 2026" |

---

## 5. `05-dashboard.html` — dashboard

### Structure

1. **App shell** — the navigation defined in issue-004.
2. **Page title** — "Dashboard", plus the **current user** in the header
   (the account context, not a user switcher).
3. **Period selector** — `Today · This week · This month · This year · Custom`.
   The active option is marked with `aria-current`.
4. **Summary trio** — income / spending / net, with the period label above.
5. **Recent transactions** — the last 5–10 entries, with a link to the full
   list ("View all transactions →").
6. **A primary action** — "Add transaction", reachable from the shell and
   repeated next to the recent list.

### States

| State | What is shown |
|---|---|
| **Populated** | The default state. All three tiles, the recent list. |
| **Empty — no transactions at all** | The trio shows `0.00` for all three (with the correct signs), and the recent area is replaced by the empty state: one sentence and the **Add transaction** action. The tiles must still render — an empty dashboard with no tiles looks broken. |
| **Empty — no transactions in the selected period** | The tiles render as zero; the recent area says "No transactions this week." with a link to the full list. |
| **Loading** | A skeleton for the tiles and the list. The mockup shows the skeleton, and the note that the server renders the real state. |
| **Error** | One alert: "The summary could not be loaded." with a retry. The tiles show a placeholder, **not** a misleading `0.00`. |

### Design notes

- The three tiles are **equal in size and equally weighted**. The net tile
  may be visually emphasised, but it must not look like a different metric.
- Each tile shows the amount, the label, and the period. Nothing else — no
  percentage change, no trend arrow (there is no comparison baseline in the
  brief).
- The recent list uses the **same row component** as the transaction list.
  One row design, two screens.

---

## 6. `06-transactions.html` — transaction list

### Structure

1. **App shell.**
2. **Page title** — "Transactions".
3. **Search and filter bar** (a form that submits to the server):

| Control | Type | Purpose |
|---|---|---|
| **Search** | `text` | Free-text over the description and the category. |
| **Type** | `select` | All · Income · Spending. |
| **Category** | `select` | All categories · each category · Uncategorised. |
| **From date** | `date` | Inclusive lower bound on `occurred_on`. |
| **To date** | `date` | Inclusive upper bound on `occurred_on`. |
| **Period presets** | buttons | Today · This week · This month · This year — they set the two date fields. |
| **Apply** | submit | The filter is server-side. |
| **Clear** | link | Resets to "everything". |

4. **Result summary** — "18 transactions · 1 – 31 October 2026" plus the
   **filtered** income / spending / net trio, so the filter and the total
   agree on screen.
5. **The table** — sortable by date and by amount; the sort indicator is
   visible and accessible.
6. **Pagination** — page size 25, with the current position stated.
7. **Primary action** — "Add transaction".

### The table

| Column | Alignment | Notes |
|---|---|---|
| Date | left | `YYYY-MM-DD` or a short date — pick one and keep it. |
| Description | left | May be empty; render an em dash, not an empty cell. |
| Category | left | A badge; "Uncategorised" is neutral, never a warning colour. |
| Type | left | A badge: "Income" / "Spending". |
| Amount | **right** | Monospaced/tabular digits, explicit sign. |

### States

| State | What is shown |
|---|---|
| **Populated** | The default. |
| **No matches for the filter** | The empty state, naming the filter that produced it: "No transactions match these filters." + **Clear filters**. It must not say "no transactions" — that would be wrong. |
| **Empty — no transactions at all** | "You have no transactions yet." + **Add transaction**. |
| **Loading** | Table skeleton. |
| **Error** | One alert + retry. The table is not replaced by a misleading empty state. |
| **A single page** | The pagination control is hidden or shows "1 page" — do not render disabled arrows with no meaning. |

### Design notes

- The filter bar is **not** a live-updating client-side filter. The mockup
  shows a submit button; the server does the filtering.
- The result summary always shows the **filtered** totals, so the user can
  see the effect of the filter without leaving the page.
- Deleting a row asks for confirmation, naming the row (date, description,
  amount) so the user can tell which entry is about to be deleted.

---

## 7. `07-transaction-form.html` — add / edit a transaction

One screen serves both **add** and **edit**; the title and the submit label
change. The mockup shows the **add** state, and the **edit** state with
pre-filled values.

### Fields

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| **Type** | radio group — Income / Spending | yes | one of the two | The **first** field, and the default. The radio group is the primary control of the whole form: it decides the sign and the default category list. |
| **Amount** | `number`, `step="0.01"`, `inputmode="decimal"` | yes | > 0, at most two decimals, at most 12 digits before the point | The server rejects `0` and negative values. The label states the unit. |
| **Date** | `date` | yes | a real date; not in the future **by default** (future-dated entries are allowed but flagged) | Defaults to today. |
| **Category** | `select` | no | — | Includes an explicit **"No category"** option. The list is filtered by the type when the categories are type-specific — the design must show the *unfiltered* behaviour and note the rule. |
| **Description / note** | `text`, max 255 | no | — | Free text; shown truncated in the list. |

### Actions

- Primary: **Save transaction** (loading state shown).
- Secondary: **Cancel** — returns to the previous screen without saving.
- On **edit**: the primary action is **Save changes**, and a **Delete
  transaction** destructive action is present, separated from the other
  actions and behind a confirmation.

### States

| State | What is shown |
|---|---|
| **Empty (add)** | The default. |
| **Pre-filled (edit)** | The values are filled in; the title and the submit label change. |
| **Client-side validation** | Per-field messages under the field. |
| **Server rejection — amount out of range** | Attached to the amount field. |
| **Server rejection — invalid date** | Attached to the date field. A date such as `2026-02-31` must be rejected by the server. |
| **Server error** | One alert above the form; **the entered values are preserved** so the user does not retype them. |
| **Success** | A confirmation, then a redirect to the list (or the dashboard). The design shows the confirmation state and notes the redirect. |

### Design notes

- The amount field is the visual centre of the form: large, monospaced, right-aligned.
- The type radio group is **above** the amount and is never a dropdown — it
  is the most consequential choice on the form.
- A **running total** is **not** shown. There is nothing to total: this form
  records one entry.
- The "no category" option must not look like a mistake. Neutral text, no
  warning colour.
- The delete action is a **danger** button and sits apart from the save
  actions, in its own block.

---

## 8. Acceptance criteria

- [ ] The three files exist, link the shared stylesheets by relative path, and render from `file://`.
- [ ] `05-dashboard.html` shows the income / spending / net trio in every state, including empty and error.
- [ ] The net tile is **neutral** in the "net exactly zero" state.
- [ ] The period selector offers Today / This week / This month / This year / Custom, with one marked active.
- [ ] The week label in the mockup starts on **Monday**.
- [ ] `06-transactions.html` shows every filter in the search and filter bar (§6, item 3), including the two date fields and the text search.
- [ ] The list shows the **filtered** totals next to the result count.
- [ ] The "no matches for the filter" state is distinct from the "no transactions yet" state.
- [ ] The amount column is right-aligned and monospaced, and every amount carries a sign.
- [ ] "Uncategorised" is rendered neutrally.
- [ ] `07-transaction-form.html` shows the type radio group, the amount, the date, the category (with a "No category" option) and the description.
- [ ] The edit state is shown with pre-filled values and a changed submit label.
- [ ] The server-rejection state is shown, with the entered values preserved.
- [ ] Every one of the three screens fits 360 px without horizontal scrolling; the table becomes stacked cards below 640 px.
- [ ] All three screens are complete in light and dark mode.

---

## 9. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 1. The files exist
ls -la docs/mockup/05-*.html docs/mockup/06-*.html docs/mockup/07-*.html

# 2. Relative paths only
grep -RIn 'href="/\|src="/' docs/mockup/0[567]-*.html && echo "FAIL: absolute path" || echo "OK"

# 3. No inline scripts
grep -RIn '<script>' docs/mockup/0[567]-*.html && echo "FAIL: inline script" || echo "OK"

# 4. No literal colours outside tokens.css
grep -RInE '#[0-9a-fA-F]{3,8}\b' docs/mockup/0[567]-*.html && echo "FAIL: literal colour" || echo "OK"

# 5. Every screen has exactly one <h1>
for f in docs/mockup/0[567]-*.html; do printf "%-40s h1=%s\n" "$f" "$(grep -c '<h1' "$f")"; done

# 6. Every <input> has a matching label (count only — eyeball the pairing)
for f in docs/mockup/0[567]-*.html; do
  printf "%-40s inputs=%s labels=%s\n" "$f" "$(grep -c '<input\|<select\|<textarea' "$f")" "$(grep -c '<label' "$f")"
done

# 7. It renders in the viewer
docker compose up -d mockup
for f in 05-dashboard 06-transactions 07-transaction-form; do
  printf "%-26s " "$f"
  curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:${MOCKUP_PORT:-8089}/$f.html"
done
````

---

## 10. Handoff notes for the application implementer

| Mockup element | Application obligation |
|---|---|
| The period selector | Produces a server-side query parameter; the period grouping is computed in **SQL**, not in PHP and not in the browser. |
| The filtered totals | One SQL pass over the filtered set. Never a PHP loop over rows. |
| The week label | Uses the `WEEK_START` constant so the label and the query agree. |
| The amount column | The server sends the formatted amount; the template escapes it. The browser does not re-format it. |
| The sort control | A server-side `ORDER BY` over an **allow-listed** column name — never a value interpolated from the query string. |
| The filter bar | A `GET` form; every value is bound as a prepared-statement parameter. |
| Ownership | Every query filters on the owning user id **in the SQL**. |
| The delete action | A `POST` with a CSRF token, filtered by the owning user id. |
| The entry form | Server-side validation; the form re-renders with the submitted values on failure. |

---

## 11. Out of scope

- **Charts and graphs.** The brief asks for search and sum. A chart is a
  plausible later addition, but it is not specified here — a mockup that
  invents one sets an expectation the application will not meet.
- **Budgets and limits.** Not in the brief.
- **Recurring transactions.** Not in the brief.
- **Import from CSV or bank statements.**
- **Attachments and receipt images.**
- **Multi-currency.** One currency; the server formats it.
- **Bulk edit.**
