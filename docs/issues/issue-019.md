# Issue-019 — App: the reports

> **Status:** Specification — ready for implementation
> **Target:** `index.php`, `includes/functions.php`, `views/reports.php`, `assets/css/pages/reports.css`
> **Severity:** High — the report is the product's reason for existing ("record income and spending, then **search and sum over a time period**")
> **Requested by the user:** "I guess, we now have everything, ready for dev, write issue files for dev to implement the project."
> **Depends on:** [issue-011](issue-011.md) (schema), [issue-012](issue-012.md) (core runtime — `resolvePeriodRange()`, `normalizePeriod()`, `weekStartDay()`, `formatMoney()`, `formatDate()`), [issue-013](issue-013.md) (tokens and the shared shell), [issue-014](issue-014.md) (the front controller and the route table), [issue-018](issue-018.md) (the transaction list the report links into)
> **Related:** [issue-017](issue-017.md) (the dashboard, which shares the period vocabulary), [issue-020](issue-020.md) (the categories the report breaks down), [docs/mockup/08-reports.html](../mockup/08-reports.html), [docs/About.md](../About.md)

---

## Table of contents

1. [Objective](#1-objective)
2. [Constraints](#2-constraints)
3. [The route and the parameters](#3-the-route-and-the-parameters)
4. [The period selector](#4-the-period-selector)
5. [The period label and the status line](#5-the-period-label-and-the-status-line)
6. [The tiles](#6-the-tiles)
7. [The "Over time" table](#7-the-over-time-table)
8. [The "By category" table](#8-the-by-category-table)
9. [The share column](#9-the-share-column)
10. [The custom range](#10-the-custom-range)
11. [The empty period](#11-the-empty-period)
12. [The error state](#12-the-error-state)
13. [The reconciliation rule](#13-the-reconciliation-rule)
14. [The queries](#14-the-queries)
15. [Acceptance criteria](#15-acceptance-criteria)
16. [Verification](#16-verification)
17. [Rollback](#17-rollback)
18. [Risks](#18-risks)
19. [Out of scope](#19-out-of-scope)

---

## 1. Objective

Deliver `GET /reports`: one screen that **sums the signed-in user's transactions over a period** and then breaks that period down **by time** and **by category**.

The screen is specified by [docs/mockup/08-reports.html](../mockup/08-reports.html).
The mockup specifies **appearance and copy**; this issue specifies **behaviour,
the bucketing rules, the reconciliation rules and the queries**.

### The five things this issue must get right

| # | The rule | Why it matters |
|---|---|---|
| 1 | **Every figure on the screen comes from the same period.** The tiles, the "Over time" rows and the "By category" rows must reconcile exactly. | The mockup's own figures do **not** reconcile (§13). The implementer must follow the rule, not the example. |
| 2 | **The week buckets start on `WEEK_START`** — the same constant the dashboard uses. | Two screens that disagree about what "this week" means are worse than one screen. |
| 3 | **A period with no transactions omits its row.** The table never carries a row of zeros for an empty week or month. | An absence is not a fact. |
| 4 | **The share column has one denominator**, and the shares add up to 100%. | The mockup's five shares add up to 183.8% because they use two different denominators (§9). |
| 5 | **The error state shows no tiles.** A failed query and a query that summed nothing must never look the same. | The mockup says so in as many words. |

---

## 2. Constraints

- **Server-rendered.** No charting library, no client-side aggregation, no build step. The tables are HTML.
- **Every sum is computed in SQL.** Never in a PHP loop over rows.
- **Money is never a float.** The sums arrive from the database as strings (`ATTR_STRINGIFY_FETCHES = false` keeps a `DECIMAL` a string) and are formatted with issue-012's `formatMoney()`. The **share** is the one place a float is acceptable — it is a ratio, not money.
- **The sign is applied after `number_format()`**, so a negative amount carries **U+2212 MINUS SIGN** (`−`), not an ASCII hyphen.
- **Ownership is enforced in the SQL.** Every statement filters on the signed-in user's id, and the category join is scoped to the owner as well.
- **Output escaping.** `e()` on every dynamic value — the category name, the period label, the date in a link.
- **The period vocabulary is shared with the dashboard.** The reports screen uses `?period=day|week|month|year|custom`; the dashboard uses `?period=today|week|month|year|custom`. Issue-012's `normalizePeriod()` accepts both.
- **`resolvePeriodRange()` is the only thing that turns a period into a date range.** This screen must not compute a range of its own.

---

## 3. The route and the parameters

| Method | Path | Handler | Auth |
|---|---|---|---|
| `GET` | `/reports` | `views/reports.php` | required |

| Parameter | Values | Default | Effect |
|---|---|---|---|
| `period` | `day` \| `week` \| `month` \| `year` \| `custom` | `month` | The period, via issue-012's `normalizePeriod()`. `today` is accepted as a synonym of `day`. |
| `from` | `YYYY-MM-DD` | the current month's first day | The custom range's start. |
| `to` | `YYYY-MM-DD` | the current month's last day | The custom range's end. |
| `month` | `YYYY-MM` | — | With `period=month`, selects a **specific** calendar month instead of the current one. |

- `requireLogin()` runs before any query.
- An unknown `period` falls back to `month` (issue-012's `normalizePeriod()`).
- An invalid `from` or `to` (not a real date) is treated as **absent**.
- `month=2026-08` is valid only when it is a real calendar month; anything else is ignored.
- The `month=` parameter exists because the year view's month rows link to it (§7.3).

> **The `month=` parameter is an addition to issue-012's `resolvePeriodRange()`.**
> `resolvePeriodRange('month')` resolves the *current* month. This screen needs
> one other month, so it adds a small helper:
>
> ````php
> /**
>  * Resolve a YYYY-MM string into an inclusive [from, to] date range.
>  * Returns null when the string is not a real calendar month.
>  */
> function resolveMonthRange(string $ym): ?array
> {
>     if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
>         return null;
>     }
>     $year  = (int) $m[1];
>     $month = (int) $m[2];
>     if ($month < 1 || $month > 12) {
>         return null;
>     }
>     $from = sprintf('%04d-%02d-01', $year, $month);
>     $to   = date('Y-m-t', strtotime($from . ' UTC'));
>     return [$from, $to];
> }
> ````
>
> It lives beside `resolvePeriodRange()` in `includes/functions.php`, and the
> reports screen uses it **only** for `period=month` with a `month=` parameter.
> Every other period goes through `resolvePeriodRange()`.

---

## 4. The period selector

```html
<!-- filepath: views/reports.php -->
<div class="period" data-period-group="month">
    <a class="period__link" href="/reports?period=day"    data-period="day">Day</a>
    <a class="period__link" href="/reports?period=week"   data-period="week">Week</a>
    <a class="period__link" href="/reports?period=month"  data-period="month" aria-current="true">Month</a>
    <a class="period__link" href="/reports?period=year"   data-period="year">Year</a>
    <a class="period__link" href="/reports?period=custom" data-period="custom">Custom</a>
</div>
```

- The five links are **Day · Week · Month · Year · Custom**, in that order.
- The active link carries `aria-current="true"`; the others do not.
- `data-period-group` carries the **normalised** period (`month`, `year`, `week`, `custom`, `day`).
- Every link is a plain `href` — the selector works without JavaScript.
- The custom link is `/reports?period=custom`; the range form appears below the selector when the custom period is active (§10).

---

## 5. The period label and the status line

The **period label** is the long human name of the resolved range:

| Period | Label | Example |
|---|---|---|
| Day | the date | `24 October 2026` |
| Week | the range | `19 – 25 October 2026` |
| Month | the month | `October 2026` |
| Year | the year | `2026` |
| Custom | the range | `1 – 31 October 2026` |

- The range format uses an **en dash (U+2013)** with a space on each side.
- The label is produced by a helper, not by string concatenation in the view, because it appears in **three** places: the status line, the three tiles' `tile__period` line, and the table captions.

```php
// filepath: includes/functions.php
// ...existing code...
/**
 * The human label for a resolved period range.
 * "October 2026", "24 October 2026", "19 – 25 October 2026".
 */
function formatPeriodLabel(string $from, string $to, string $period): string
{
    // …a single month → "October 2026"; a single day → "24 October 2026";
    // …otherwise the range, "1 – 31 October 2026"…
}
// ...existing code...
```

The status line:

```html
<p class="pagination__status">October 2026 · 18 transactions</p>
```

- The format is `<period label> · <count> transactions`.
- The separator is **U+00B7 MIDDLE DOT** with a space on each side.
- The count is the number of transactions **in the period**, not the number of rows in the tables.
- The year view reads `2026 · 34 transactions`; the custom view reads `1 – 31 October 2026 · 18 transactions`.

---

## 6. The tiles

```html
<!-- filepath: views/reports.php -->
<div class="tiles">
    <div class="tile tile--income">
        <span class="tile__label">Income</span>
        <span class="tile__amount">+1,200.00</span>
        <span class="tile__period">October 2026</span>
    </div>
    <div class="tile tile--spending">
        <span class="tile__label">Spending</span>
        <span class="tile__amount">−1,041.80</span>
        <span class="tile__period">October 2026</span>
    </div>
    <div class="tile tile--net tile--net-positive">
        <span class="tile__label">Net</span>
        <span class="tile__amount">+158.20</span>
        <span class="tile__period">October 2026</span>
    </div>
</div>
```

- The three tiles are the **period totals**: income, spending, net.
- The `tile__period` line carries the **period label** (§5) — not a second range.
- The sign convention is issue-017's: `+` for a positive income, `−` (U+2212) for a spending total and for a negative net, **no sign** on a zero.
- The net modifier is `tile--net-positive` / `tile--net-negative` / `tile--net-zero`. **A zero net is neutral.**
- The spending tile shows the **magnitude** of the spending total with a `−` sign; it never shows a positive number.
- The totals come from **one** query over the period (§14.1) — the same numbers the tables must reconcile against (§13).

---

## 7. The "Over time" table

The table breaks the period down into buckets. **The grain is one step below the
period**, and the bucket start follows `WEEK_START` for the week grain.

| Period | Grain | First column header | Bucket |
|---|---|---|---|
| Day | the day | `Day` | the day itself |
| Week | one row per day | `Day` | the date |
| Month | one row per week | `Week of` | the week's start date |
| Year | one row per month | `Month` | the month's first day |
| Custom | depends on the span (§7.4) | `Day` / `Week of` / `Month` | as above |

### 7.1 The month view

```html
<!-- filepath: views/reports.php -->
<h2>Over time</h2>

<div class="card card--flush">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="sr-only">Income, spending and net for each week of October 2026</caption>
            <thead>
                <tr>
                    <th scope="col">Week of</th>
                    <th scope="col" class="num">Income</th>
                    <th scope="col" class="num">Spending</th>
                    <th scope="col" class="num">Net</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row">
                        <a href="/transactions?from=2026-10-05&amp;to=2026-10-11">Week of 5 October 2026</a>
                        <span class="tx__desc-meta">to 11 October 2026</span>
                    </th>
                    <td data-label="Income" class="num amount amount--income">+0.00</td>
                    <td data-label="Spending" class="num amount amount--expense">−312.40</td>
                    <td data-label="Net" class="num amount amount--expense">−312.40</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
```

- The **row header is a `<th scope="row">`**, not a `<td>`. It carries the link.
- The link text is `Week of <start date in the long form>`; the range end follows in a `<span class="tx__desc-meta">to <end date></span>`.
- The link's `from` / `to` are **clipped to the period**: `from = MAX(week_start, period_from)`, `to = MIN(week_start + 6 days, period_to)`. The **label** names the natural week; the **link** shows the transactions that are actually in the report.
- The money columns are the three `num` cells with the same sign convention and modifiers as the tiles.
- An income total of zero renders `+0.00` (the `+` is kept on the income column — the mockup shows `+0.00`); a spending total of zero renders `0.00` (no sign).
- The `<caption class="sr-only">` reads *Income, spending and net for each week of October 2026*.

### 7.2 The week and day views

- The **week** view has one row per day, the header `Day`, and each row links to `/transactions?from=<date>&to=<date>`.
- The **day** view has a single row, the header `Day`, and the row links to `/transactions?from=<date>&to=<date>`.
- A row's label is the date in the long form (`24 October 2026`). There is no `tx__desc-meta` span — that span exists only for the week range.

### 7.3 The year view

```html
<th scope="row"><a href="/reports?period=month&amp;month=2026-08">August 2026</a></th>
```

- The year view has one row per **month**; the header is `Month`.
- The row's link goes to the **reports** screen, not to the transaction list: `/reports?period=month&month=2026-08`. It drills into the month report.
- The row label is the month in the long form (`August 2026`).
- **A month with no transactions is omitted.** The table does not carry a row of zeros for every month of the year.

### 7.4 The custom view

The grain is chosen from the length of the custom range:

| Range length | Grain | Header |
|---|---|---|
| 1 – 62 days | one row per day | `Day` |
| 63 – 366 days | one row per week | `Week of` |
| more than 366 days | one row per month | `Month` |

The threshold is inclusive: a 62-day range is daily, a 63-day range is weekly.

### 7.5 The week-start rule

The week bucket starts on `WEEK_START` — Monday by default, the same constant
that issue-012's `weekStartDay()` reads. **Nothing on this screen may hard-code a
week start.** The mockup's "Week of 5 October 2026 to 11 October 2026" is a
Monday-to-Sunday week, which is `WEEK_START = 1`.

### 7.6 The empty buckets

**A bucket with no transactions does not appear.** This falls out of the
`GROUP BY` (§14.2): the bucket simply has no row. It must not be padded with a
zero row — neither in SQL nor in a PHP loop afterwards.

---

## 8. The "By category" table

```html
<!-- filepath: views/reports.php -->
<h2>By category</h2>

<div class="card card--flush">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="sr-only">Income, spending, net and share for each category in October 2026</caption>
            <thead>
                <tr>
                    <th scope="col">Category</th>
                    <th scope="col" class="num">Transactions</th>
                    <th scope="col" class="num">Income</th>
                    <th scope="col" class="num">Spending</th>
                    <th scope="col" class="num">Net</th>
                    <th scope="col" class="num">Share</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row"><span class="badge badge--neutral"><a href="/transactions?category=12">Salary</a></span></th>
                    <td data-label="Transactions" class="num">1</td>
                    <td data-label="Income" class="num amount amount--income">+1,200.00</td>
                    <td data-label="Spending" class="num amount amount--muted">—</td>
                    <td data-label="Net" class="num amount amount--income">+1,200.00</td>
                    <td data-label="Share" class="num">57.8%</td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row">Total</th>
                    <td data-label="Transactions" class="num">18</td>
                    <td data-label="Income" class="num amount amount--income">+1,229.99</td>
                    <td data-label="Spending" class="num amount amount--expense">−847.80</td>
                    <td data-label="Net" class="num amount amount--income">+382.19</td>
                    <td data-label="Share" class="num">100.0%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
```

Rules:

- The six columns are **Category · Transactions · Income · Spending · Net · Share**.
- The **row header is a `<th scope="row">`** carrying a `badge badge--neutral` with the category name as a link to the filtered transaction list.
- The category link is `/transactions?category=<id>` — the **id**, not a slug (issue-018 §3). The mockup's `?category=salary` is a mockup artefact.
- The **Uncategorised** row is **always present**, so the visitor can see that nothing is missing from the report. Its link is `/transactions?category=none` (issue-018's uncategorised sentinel).
- The **Transactions** column is a plain integer, `0` included.
- A money cell that is zero renders `<span class="amount amount--muted">—</span>` — the em dash, not `0.00`. This is the case for a category with no income, no spending, and no net.
- The `<tfoot>` **Total** row is present and carries the period totals — **the same numbers as the tiles** (§13).
- The `<tfoot>`'s Share is always `100.0%`.
- Every `<td>` carries a `data-label`; the `<th scope="row">` cells do not need one — they are the row headers.

### Which categories are listed

- A category is listed when it has **at least one transaction in the period**.
- The **Uncategorised** row is listed **always**, even when it has none — it renders `0` and em dashes.
- The user's categories with no transactions in the period are **not** listed. The report is about the period, not about the category list; the category list is the categories screen (issue-020).

---

## 9. The share column

### The rule

**One denominator for the whole table.** A row's share is:

````text
share = (rowIncome + rowSpending) / (periodIncome + periodSpending) × 100
````

where `periodIncome` and `periodSpending` are the **positive magnitudes** of the
period totals — the same two numbers as the tiles.

- The denominator is **never zero** when the table is rendered: the table only
  renders when the period has transactions, and a period with transactions has a
  non-zero volume. The guard is still written (`if ($totalVolume <= 0) { … }`) so a divide-by-zero can never reach the page.
- The share is rendered with **one decimal** and a percent sign: `number_format($share, 1, '.', '') . '%'`.
- The Total row's share is the literal `100.0%`.
- The shares are individually rounded, so their printed sum may be `99.9%` or `100.1%`. That is normal rounding, not a defect. The **Total** row always reads `100.0%`.

### Why the mockup's numbers change

The mockup's five shares are `100.0%`, `32.9%`, `40.1%`, `8.3%` and `2.5%`.
They sum to **183.8%**, not 100%. The reason is that each row's share is taken
against **its own column's total** — the income rows against the income total
(`1,200.00`) and the spending rows against the spending total (`1,041.80`) —
which are two different denominators in one column. The mockup's own
specification note says *"the shares add up to 100%"*, so the note and the
numbers contradict each other.

**This issue follows the note.** The share column has one denominator, and the
mockup file must be updated to match (§13.3).

---

## 10. The custom range

When the custom period is active, a range form appears below the selector:

```html
<!-- filepath: views/reports.php -->
<form class="filters" aria-label="Custom date range" method="get" action="/reports">
    <input type="hidden" name="period" value="custom">
    <div class="filters__from">
        <label class="field__label" for="rep-from">From</label>
        <input class="input" id="rep-from" name="from" type="date" value="2026-10-01">
    </div>
    <div class="filters__to">
        <label class="field__label" for="rep-to">To</label>
        <input class="input" id="rep-to" name="to" type="date" value="2026-10-31">
    </div>
    <div class="filters__actions">
        <button type="submit" class="btn btn--primary">Apply range</button>
    </div>
</form>
```

- The form is a **GET** form to `/reports` with a hidden `period=custom`, so the applied range is a bookmarkable URL.
- The mockup's button is `type="button"`; in the application it is `type="submit"`. The range must be applied by the server.
- The two inputs are pre-filled with the **applied** range, or with the current month's first and last day when there is none.
- The inputs carry the submitted values even when the range is **invalid** (§10.1).

### The invalid range

```html
<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">The start date must be on or before the end date.</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="empty">
        <h3 class="empty__title">No report</h3>
        <p class="empty__body">Correct the dates to see a report.</p>
    </div>
</div>
```

- The message is exactly **"The start date must be on or before the end date."**
- The two date inputs carry `aria-invalid="true"` and `aria-describedby="rep-range-msg"` — the id of the alert's title.
- **No tiles and no tables** are rendered — there is no period to report on.
- The range is **not** silently swapped. Issue-012's `resolvePeriodRange()` treats a custom range with `from` after `to` as an **error**, not a swap, and so does this screen.
- The HTTP status is **400**.

---

## 11. The empty period

```html
<div class="tiles">
    <div class="tile tile--income">
        <span class="tile__label">Income</span>
        <span class="tile__amount">0.00</span>
        <span class="tile__period">19 – 25 October 2026</span>
    </div>
    <div class="tile tile--spending">
        <span class="tile__label">Spending</span>
        <span class="tile__amount">0.00</span>
        <span class="tile__period">19 – 25 October 2026</span>
    </div>
    <div class="tile tile--net tile--net-zero">
        <span class="tile__label">Net</span>
        <span class="tile__amount">0.00</span>
        <span class="tile__period">19 – 25 October 2026</span>
    </div>
</div>

<div class="card">
    <div class="empty">
        <h3 class="empty__title">No transactions in this period.</h3>
        <p class="empty__body">Nothing was recorded between 19 and 25 October 2026.</p>
        <a class="btn btn--secondary" href="/reports?period=month">Look at this month</a>
    </div>
</div>
```

- **Here a zero is the truth.** The query ran and summed nothing, so the tiles render — all three at `0.00`, **with no sign** — and the net modifier is `tile--net-zero` (neutral, never a danger colour).
- **Both tables are omitted entirely.** There is no "Over time" heading and no "By category" table, because there are no buckets and no rows.
- The empty state's body repeats the range in the long form: *Nothing was recorded between 19 and 25 October 2026.* The wording is built from the resolved range, so it always names the right dates.
- The action is a **secondary** "Look at this month" button linking to `/reports?period=month`.
- The status line still renders: `19 – 25 October 2026 · 0 transactions`.

---

## 12. The error state

```html
<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">The report could not be loaded.</p>
            <p>Please try again.</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="empty">
        <h3 class="empty__title">No report available</h3>
        <p class="empty__body">The totals could not be retrieved, so no figures are shown.</p>
        <button type="button" class="btn btn--secondary">Try again</button>
    </div>
</div>
```

- **No tiles.** A failed query must never look like a query that summed nothing.
- **No tables**, no status line with a count, no period label.
- The HTTP status is **500**; the raw driver error goes to `error_log()`.
- The "Try again" button is a `type="button"` element in the mockup; in the application it reloads the current URL — a link to the same URL with the same query string.

---

## 13. The reconciliation rule

### 13.1 The rule

**Every figure on the screen describes the same period and must agree.**

| Figure | Where it comes from |
|---|---|
| The three tiles | the period totals query |
| The "Over time" rows | the bucket query, summed |
| The "By category" rows | the category query, summed |
| The "By category" Total row | the period totals query — **not** a sum of the rows |
| The status line's count | the count query |

The **Total** row of the category table is rendered from the **period totals**,
not from the sum of the category rows. Rendering it from the rows would make the
table self-consistent while letting it disagree with the tiles — the exact bug
the mockup has.

### 13.2 The mockup's figures do not reconcile

The mockup's populated state contains four contradictions. They are listed here
so the implementer does not "fix" the application to match them.

| # | Contradiction | The mockup says |
|---|---|---|
| 1 | The tiles' income vs the category rows' income | tiles `+1,200.00`; the category rows sum to `1,229.99` |
| 2 | The tiles' spending vs the category Total row's spending | tiles `−1,041.80`; the Total row `−847.80` |
| 3 | The tiles' net vs both tables | tiles `+158.20`; the week rows sum to `+188.19`; the Total row `+382.20` |
| 4 | The category rows' income vs the Total row's income | the rows sum to `1,229.99`; the Total row says `1,230.00` |

The transaction **count** is consistent everywhere (18 = 1 + 8 + 5 + 3 + 1) and
must stay that way.

### 13.3 The corrected figures

The mockup's **category rows** are kept as the source of truth, and the other
figures are corrected to match them. The mockup file must be updated to these
values.

**The period totals:**

| | Value |
|---|---|
| Transactions | 18 |
| Income | `+1,229.99` |
| Spending | `−847.80` |
| Net | `+382.19` |

**The "Over time" rows** (the income row already sums correctly; the spending
row is reduced by 194.00 so that it sums to 847.80):

| Week of | Income | Spending | Net |
|---|---|---|---|
| 5 October 2026 | `+0.00` | `−245.40` | `−245.40` |
| 12 October 2026 | `+29.99` | `−102.75` | `−72.76` |
| 19 October 2026 | `+1,200.00` | `−499.65` | `+700.35` |
| **Sum** | **`+1,229.99`** | **`−847.80`** | **`+382.19`** |

**The "By category" rows and their shares** (the volume denominator is
`1,229.99 + 847.80 = 2,077.79`):

| Category | Transactions | Income | Spending | Net | Share |
|---|---|---|---|---|---|
| Salary | 1 | `+1,200.00` | `—` | `+1,200.00` | `57.8%` |
| Groceries | 8 | `—` | `−342.60` | `−342.60` | `16.5%` |
| Utilities | 5 | `—` | `−418.25` | `−418.25` | `20.1%` |
| Eating out | 3 | `—` | `−86.95` | `−86.95` | `4.2%` |
| Uncategorised | 1 | `+29.99` | `—` | `+29.99` | `1.4%` |
| **Total** | **18** | **`+1,229.99`** | **`−847.80`** | **`+382.19`** | **`100.0%`** |

### 13.4 The two mockup links that must change

| Mockup | Application | Why |
|---|---|---|
| `06-transactions.html?category=salary` | `/transactions?category=<id>` | The transaction filter uses the category **id** (issue-018 §3). |
| `06-transactions.html?category=uncategorised` | `/transactions?category=none` | The uncategorised sentinel (issue-018 §3). |

---

## 14. The queries

### 14.1 The period totals

```sql
-- filepath: includes/functions.php
SELECT
    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS spending,
    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                      WHEN type = 'expense' THEN -amount END), 0) AS net,
    COUNT(*) AS tx_count
FROM transactions
WHERE user_id = :uid
  AND occurred_on BETWEEN :from AND :to
```

- The net is computed **in SQL**; no PHP float comparison of money happens.
- The row count is the status line's count.
- The sums come back as **strings** and are formatted with `formatMoney()`.

### 14.2 The time buckets

```sql
-- filepath: includes/functions.php
SELECT <bucketExpr> AS bucket_start,
       COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
       COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS spending,
       COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                         WHEN type = 'expense' THEN -amount END), 0) AS net,
       COUNT(*) AS tx_count
FROM transactions
WHERE user_id = :uid
  AND occurred_on BETWEEN :from AND :to
GROUP BY bucket_start
ORDER BY bucket_start
```

`<bucketExpr>` is chosen in PHP from the grain — **never** from user input:

| Grain | `<bucketExpr>` |
|---|---|
| Day | `occurred_on` |
| Week, `WEEK_START = 1` (Monday) | `DATE_SUB(occurred_on, INTERVAL WEEKDAY(occurred_on) DAY)` |
| Week, `WEEK_START = 0` (Sunday) | `DATE_SUB(occurred_on, INTERVAL (DAYOFWEEK(occurred_on) - 1) DAY)` |
| Month | `DATE_SUB(occurred_on, INTERVAL (DAYOFMONTH(occurred_on) - 1) DAY)` |

- The week expression is selected from **two literal strings** by the `WEEK_START` constant. Nothing from the query string reaches it.
- `GROUP BY bucket_start` uses the alias — MariaDB supports it.
- **The buckets with no transactions do not appear**, because they have no rows. Do not pad the result in PHP.
- The bucket's **end** is computed in PHP from the bucket start (`+ 6 days` for a week, the last day of the month for a month) and **clipped to the period** for the link.

### 14.3 The category breakdown

```sql
-- filepath: includes/functions.php
SELECT c.id AS category_id,
       c.name AS category_name,
       COUNT(t.id) AS tx_count,
       COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS income,
       COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS spending,
       COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                         WHEN t.type = 'expense' THEN -t.amount END), 0) AS net
FROM transactions t
LEFT JOIN categories c
       ON c.id = t.category_id
      AND c.user_id = t.user_id
WHERE t.user_id = :uid
  AND t.occurred_on BETWEEN :from AND :to
GROUP BY c.id, c.name
ORDER BY c.name IS NULL, c.name
```

- The `LEFT JOIN` produces a **NULL group** for the uncategorised rows — that is the `Uncategorised` row.
- The join is scoped to the owner, so a transaction whose category somehow belongs to another user falls into the uncategorised group rather than leaking a name.
- `ORDER BY c.name IS NULL, c.name` puts the named categories first, in name order, and the uncategorised group **last**.
- The **Uncategorised** row is added by the application when the query does not return one (a period with no uncategorised entries), with `0` and em dashes.
- The **share** is computed in PHP from the row's income and spending and the period totals. It is a ratio, so a float is acceptable — but the two **money** values it is derived from are strings that are converted once, at this point.

### 14.4 The category list for the report

The report does **not** need a separate query for the categories: the rows come
from the breakdown query. A category with no transactions in the period is not
listed.

---

## 15. Acceptance criteria

### The route and the period

- [ ] `GET /reports` renders the app shell with the nav order Dashboard · Transactions · Reports · Categories, the `Reports` link carrying `aria-current="page"`, and the `Settings` link after the spacer.
- [ ] `requireLogin()` runs before any query.
- [ ] The page header shows the title `Reports` and the subtitle *Summed over a period, then broken down by time and by category.* and has **no** page action.
- [ ] The period selector has the five links **Day · Week · Month · Year · Custom**, each with a `data-period` attribute.
- [ ] The active link carries `aria-current="true"`; the others do not.
- [ ] `data-period-group` carries the normalised period.
- [ ] The selector works without JavaScript — every link is a plain `href`.
- [ ] `?period=today` is accepted as a synonym of `?period=day`.
- [ ] An unknown `period` falls back to `month`.
- [ ] `?period=month&month=2026-08` reports on **August 2026**; an invalid `month` is ignored.
- [ ] The range is produced **only** by `resolvePeriodRange()` (and `resolveMonthRange()` for the `month=` parameter) — the screen computes no range of its own.

### The label, the status line and the tiles

- [ ] The period label is produced by one helper and used in the status line, the tiles' `tile__period` and the table captions.
- [ ] The label is `24 October 2026` (day), `19 – 25 October 2026` (week and custom), `October 2026` (month) and `2026` (year), with an en dash and spaces around it.
- [ ] The status line reads `<label> · <count> transactions` with U+00B7.
- [ ] The three tiles show the period's income, spending and net, with the `tile__period` line carrying the period label.
- [ ] The sign convention matches issue-017: `+`, `−` (U+2212), no sign on zero, two decimals, the sign applied after `number_format()`.
- [ ] The net modifier is `tile--net-positive` / `tile--net-negative` / `tile--net-zero`; a zero net is **neutral**.
- [ ] The spending tile shows the magnitude of the spending total with a `−`, never a positive number.
- [ ] The tiles, the "Over time" rows and the "By category" Total row show **the same numbers**.

### The "Over time" table

- [ ] The table is headed `Over time` and has the columns `Week of` / `Day` / `Month` per the grain, plus `Income`, `Spending` and `Net`, each with `class="num"`.
- [ ] The month view has one row per **week**, the week view one per **day**, the year view one per **month**, the day view a single row.
- [ ] The custom view's grain is daily for a range of 1–62 days, weekly for 63–366 days, monthly beyond that.
- [ ] The row header is a `<th scope="row">` carrying the link.
- [ ] The week rows link to `/transactions?from=<week start>&to=<week end>`, **clipped to the period**.
- [ ] The year rows link to `/reports?period=month&month=<YYYY-MM>`.
- [ ] The day rows link to `/transactions?from=<date>&to=<date>`.
- [ ] The week row's label reads `Week of <date>` followed by a `<span class="tx__desc-meta">to <date></span>`.
- [ ] The week buckets start on `WEEK_START` — the constant, never a hard-coded Monday.
- [ ] A bucket with no transactions is **omitted** — no zero rows are padded in.
- [ ] The table has an `<caption class="sr-only">` naming the period and the grain.

### The "By category" table

- [ ] The table is headed `By category` and has the columns `Category · Transactions · Income · Spending · Net · Share`.
- [ ] The row header is a `<th scope="row">` carrying a `badge badge--neutral` with the category name as a link.
- [ ] The category link is `/transactions?category=<id>` — the **id**, not a slug.
- [ ] The **Uncategorised** row is always present, with the link `/transactions?category=none`.
- [ ] A category is listed only when it has at least one transaction in the period; the user's other categories are not listed.
- [ ] The Transactions column shows a plain integer, `0` included.
- [ ] A zero money cell renders `<span class="amount amount--muted">—</span>` — the em dash, not `0.00`.
- [ ] The `<tfoot>` **Total** row is present, with the Transactions count, the three money totals and a Share of `100.0%`.
- [ ] The Total row is rendered from the **period totals**, not from the sum of the rows.
- [ ] Every `<td>` carries a `data-label`.

### The share column

- [ ] The share is `(rowIncome + rowSpending) / (periodIncome + periodSpending) × 100` — **one denominator for the whole table**.
- [ ] The share is rendered with one decimal and a percent sign.
- [ ] The denominator is guarded against zero.
- [ ] The printed shares add up to 100% (± 0.1 from rounding).
- [ ] The mockup's five shares are **not** reproduced — they use two denominators and sum to 183.8%.

### The custom range

- [ ] The custom period renders a GET form to `/reports` with a hidden `period=custom`, the `from` and `to` inputs and an **Apply range** submit button.
- [ ] The inputs are pre-filled with the applied range, or with the current month's first and last day when there is none.
- [ ] A range with `from` **after** `to` renders the alert *The start date must be on or before the end date.*, the empty state `No report` / *Correct the dates to see a report.*, and **no tiles and no tables**.
- [ ] The invalid range is **not** silently swapped.
- [ ] The invalid state returns the HTTP status **400**.
- [ ] Both date inputs carry `aria-invalid="true"` and `aria-describedby` pointing at the alert's title id.
- [ ] The submitted values are preserved in the invalid state.

### The empty period

- [ ] A period with no transactions renders the three tiles at `0.00` with **no sign**, the net modifier `tile--net-zero`, and the status line `<label> · 0 transactions`.
- [ ] **Both tables are omitted** — no "Over time" heading and no "By category" table.
- [ ] The empty state reads `No transactions in this period.` and *Nothing was recorded between <from> and <to>.*, with the dates built from the resolved range.
- [ ] The action is a **secondary** "Look at this month" button linking to `/reports?period=month`.

### The error state

- [ ] A failed query renders `role="alert"`, the alert title `The report could not be loaded.` and the body *Please try again.*, the empty title `No report available` and the body *The totals could not be retrieved, so no figures are shown.*, and a "Try again" control that reloads the same URL.
- [ ] The error state renders **no tiles and no tables**.
- [ ] The HTTP status is **500** and the raw driver error goes to `error_log()`.

### The data and the queries

- [ ] Every statement filters on the signed-in user's id.
- [ ] The category join is scoped to the owner as well.
- [ ] Every sum is computed in SQL; the net is a `SUM(CASE …)`.
- [ ] The sums are read back as strings and formatted with `formatMoney()`.
- [ ] The bucket expression is selected from **literal** strings by the grain and by `WEEK_START` — nothing from the query string reaches it.
- [ ] `GROUP BY bucket_start` yields only the buckets that have transactions.
- [ ] The share is the only float in the money path, and it is a ratio.
- [ ] No `?>` or `<?php` appears inside a `//` comment in any file written for this issue.
- [ ] Every PHP file written for this issue passes `php -l`.

### The mockup corrections

- [ ] `docs/mockup/08-reports.html` is updated so the tiles, the week rows, the category rows, the Total row and the shares all reconcile (§13.3).
- [ ] The mockup's category links become `/transactions?category=<id>` and `/transactions?category=none`.
- [ ] The mockup's `?period=month&month=2026-08` link is kept as the drill-down target.

---

## 16. Verification

Run from the project root. `php` is **not** on the host PATH — every command runs
inside the container.

### 16.1 Lint

```bash
docker compose exec -T app php -l /var/www/html/views/reports.php
docker compose exec -T app php -l /var/www/html/includes/functions.php
```

### 16.2 The period selector

```bash
for p in day week month year custom; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8088/reports?period=$p")
  echo "$p -> $code"
done
```

All must be **200**, and each page must carry exactly one `aria-current="true"`
in the period selector:

```bash
curl -s "http://localhost:8088/reports?period=year" | grep -c 'aria-current="true"'
```

### 16.3 The reconciliation — the tiles against the Total row

```bash
curl -s "http://localhost:8088/reports?period=month" > /tmp/reports.html

echo "--- tiles ---"
grep -o 'tile__amount">[^<]*' /tmp/reports.html
echo "--- tfoot ---"
awk '/<tfoot>/,/<\/tfoot>/' /tmp/reports.html | grep -o 'amount[^>]*>[^<]*'
```

The income, the spending and the net must be **identical** in the two blocks.

### 16.4 The shares add up to 100%

```bash
curl -s "http://localhost:8088/reports?period=month" \
  | grep -o 'data-label="Share" class="num">[0-9.]*%' \
  | grep -o '[0-9.]*' | awk '{ s += $1 } END { printf "%.1f\n", s }'
```

The result must be **100.0** (± 0.1).

### 16.5 The week rows sum to the tiles

```bash
curl -s "http://localhost:8088/reports?period=month" \
  | grep -o 'data-label="Income" class="num[^"]*">[^<]*' | grep -o '[+-]\?[0-9,.]*$'
```

The three values must sum to the tile's income.

### 16.6 The week buckets start on Monday

```bash
curl -s "http://localhost:8088/reports?period=month" | grep -o 'Week of [0-9]* [A-Za-z]* [0-9]*'
```

Every date must be a **Monday** (with `WEEK_START = 1`).

### 16.7 The empty buckets are omitted

```bash
curl -s "http://localhost:8088/reports?period=year" | grep -c '<tr>'
```

The row count must be **less than 12** — one row per month that actually has
transactions, not twelve rows.

### 16.8 The empty period renders zeroed tiles and no tables

```bash
curl -s "http://localhost:8088/reports?period=custom&from=2020-01-01&to=2020-01-31" > /tmp/empty.html
grep -o 'No transactions in this period.' /tmp/empty.html
grep -c 'data-table' /tmp/empty.html        # expect 0
grep -o 'tile--net[a-z-]*' /tmp/empty.html   # expect tile--net-zero
```

### 16.9 The invalid range

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  "http://localhost:8088/reports?period=custom&from=2026-10-31&to=2026-10-01"     # expect 400

curl -s "http://localhost:8088/reports?period=custom&from=2026-10-31&to=2026-10-01" \
  | grep -o 'The start date must be on or before the end date.'
```

### 16.10 The month drill-down

```bash
curl -s "http://localhost:8088/reports?period=month&month=2026-08" | grep -o 'August 2026'
```

### 16.11 Ownership

With a second account that has its own categories and transactions:

```bash
curl -s "http://localhost:8088/reports?period=year" | grep -o 'badge badge--neutral"><a[^>]*>[^<]*'
```

The list must contain **only** the signed-in user's categories.

### 16.12 The whole suite still passes

```bash
.github/skills/dev-stack-ops/scripts/run-tests.sh
```

---

## 17. Rollback

1. Remove `views/reports.php`.
2. Remove the `/reports` entry from the route table in `index.php` (issue-014).
3. Remove `assets/css/pages/reports.css` and its `<link>` from the layout.
4. Remove `resolveMonthRange()` and `formatPeriodLabel()` from `includes/functions.php`.
5. Restart the app so the route table is re-read.

**The database is not rolled back.** The report reads the `transactions` table
and adds no schema of its own. If the schema itself has to go, it is dropped by
hand — **never** `docker compose down -v`, which takes the whole MariaDB volume
with it.

---

## 18. Risks

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| 1 | The implementer copies the mockup's figures instead of the rule, and the screen shows numbers that do not add up. | **High** | **High** | §13 lists every contradiction and the corrected set; §16.3–§16.5 check the arithmetic against the database. |
| 2 | The share column keeps the mockup's two denominators, so the shares sum to 183.8%. | **High** | High | §9 fixes one denominator; §16.4 checks the sum. |
| 3 | A week bucket starts on Sunday while the dashboard starts on Monday. | Medium | **High** | The bucket expression is selected from `WEEK_START` (§14.2); §16.6 checks the weekday. |
| 4 | The Total row is summed from the category rows, so it drifts from the tiles. | Medium | **High** | The Total row is rendered from the period totals (§13.1). |
| 5 | A period with no transactions renders zeroed rows in the tables. | Medium | Medium | The `GROUP BY` produces no row; the tables are omitted entirely (§11). |
| 6 | A failed query renders zeroed tiles and is mistaken for an empty period. | Medium | **High** | The error state renders no tiles (§12). |
| 7 | The bucket expression is interpolated from a query-string value. | Low | **Critical** | It is selected from literal strings by the grain and by the constant (§14.2). |
| 8 | The share divides by zero on an empty period. | Low | High | The tables only render when the period has transactions, and the denominator is guarded (§9). |
| 9 | The category join leaks another user's category name. | Low | **High** | The join is scoped on `c.user_id = t.user_id` (§14.3); §16.11 checks it. |
| 10 | A PHP float creeps into the money path. | Medium | High | The sums are computed in SQL and read back as strings; only the share is a float (§14.1, §14.3). |
| 11 | The sign is applied before `number_format()`, producing an ASCII hyphen. | **High** | Medium | Issue-017 fixes the order; the same helper is used here. |
| 12 | The custom range with `from` after `to` is silently swapped instead of rejected. | Medium | Medium | Issue-012's resolver treats it as an error; §10 forbids the swap. |
| 13 | The `month=` parameter is added to the router instead of being read from the query string. | Low | Low | It is a query-string parameter handled by the view (§3). |
| 14 | A category with no transactions in the period is listed, making the report look longer than it is. | Medium | Low | Only categories with transactions are listed; the Uncategorised row is the one exception (§8). |
| 15 | The custom grain threshold is off by one day at the boundary. | Low | Low | The thresholds are inclusive and specified in §7.4. |
| 16 | The empty state's "Nothing was recorded between …" names the wrong dates. | Medium | Medium | The sentence is built from the resolved range, never hard-coded (§11). |

---

## 19. Out of scope

- **Charts.** No graphs, no sparklines, no chart library. The mockup has none, and the brief does not ask for one.
- **Comparison with the previous period** — no "versus last month" column, no percentage change.
- **Averages, medians, burn rate and projections.**
- **Export** — CSV, PDF, anything.
- **Budgets and targets.** There is no budget table and no target to compare against.
- **Recurring transactions.**
- **Multi-currency.** One currency; there is no conversion.
- **A different grain per period.** The grain is fixed by the rules in §7 — it is not a visitor choice.
- **The dashboard's period selector.** Issue-017. This screen shares the period *vocabulary* with it, not its markup.
- **The transaction list.** Issue-018. This screen links into it; it does not reimplement its filters.
- **The categories screen.** Issue-020. This screen breaks the period down by category; it does not create, rename or delete categories.
- **Saving a report** or scheduling one.
