# Issue-026 — Requirements: the average is missing

> **Status:** **implemented and verified** — the product brief requires the
> application to *"search and sum/average in time period like day, month,
> weeks, year"*, and the requirement check of 2026-10-28 found that the
> **sum** is implemented everywhere and the **average** was implemented
> nowhere. Implemented on 2026-10-30, in the order this issue demands: the
> **design track first**, then the application, then the tests. See
> [§14](#14-the-implementation-as-built) for what was built and what was
> measured.
> **Target:** `docs/mockup/08-reports.html`, `views/reports.php`,
> `includes/functions.php`, `assets/css/pages/reports.css`,
> `database/reports_test.php`
> **Severity:** **High** — this is not a defect, it is a **missing half of a
> stated requirement**. The application does not do what
> [docs/About.md](../About.md) says it does.
> **Requested by the user:** "And also I check the final product for the
> requirements. - user should be able to search spending or income, and show
> total, average by day by moth, by week, by year. is missing"
> **Depends on:** [issue-007](issue-007.md) (the reports mockup),
> [issue-019](issue-019.md) (the reports screen and the report helpers)
> **Related:** [issue-017](issue-017.md) (the dashboard tiles),
> [issue-018](issue-018.md) (the transaction list tiles)

---

## Table of contents

1. [Objective](#1-objective)
2. [The requirement check](#2-the-requirement-check)
3. [What "average" must mean — the semantic decision](#3-what-average-must-mean--the-semantic-decision)
4. [The data contract](#4-the-data-contract)
5. [The edge cases](#5-the-edge-cases)
6. [The presentation — the design track owns this](#6-the-presentation--the-design-track-owns-this)
7. [The implementation — the application track](#7-the-implementation--the-application-track)
8. [Acceptance criteria](#8-acceptance-criteria)
9. [Verification](#9-verification)
10. [Rollback](#10-rollback)
11. [Risks](#11-risks)
12. [Out of scope](#12-out-of-scope)
13. [The rest of the requirement check — what is already met](#13-the-rest-of-the-requirement-check--what-is-already-met)
14. [The implementation, as built](#14-the-implementation-as-built)

---

## 1. Objective

Add the **average** to the reports screen, so that the application does the
second half of what the brief asks for:

> *"This project is to record spending andd income for personal use. it should
> be able to search and **sum/average** in time period like day, month, weeks,
> year."* — [docs/About.md](../About.md)

The search exists. The sum exists. **The average does not exist**, in any
screen, for any period, for any measure.

The word **"average"** is the whole of the missing requirement, and it is the
one word in it that carries an **ambiguity**. "Sum in a time period" has
exactly one meaning. "Average in a time period" has at least three, and a
developer who picks the wrong one will ship a number that is wrong in a way
that is not visible on the screen. §3 settles it; §4 fixes the arithmetic;
§5 fixes the edge cases. **Those three sections are the specification.** The
rest is the delivery.

---

## 2. The requirement check

The brief has three testable claims about the record/summarise capability.
Here is the verdict for each, against the **running application**.

| # | The brief says | Verdict | Evidence |
|---|---|---|---|
| 1 | *"record spending and income"* | **Met** | `transactions` with `type` ∈ {`income`, `expense`}, `amount DECIMAL(14,2)`, `occurred_on DATE`; the add/edit/delete write paths are `views/transaction_save.php` and `views/transaction_delete.php`. |
| 2 | *"search"* | **Met** | `buildTransactionPredicate()` in `views/transactions.php` filters on a free-text description (`LIKE`, with `escapeLike()`), on the type, on the category and on the date range. The search box is on the transaction list. |
| 3 | *"sum"* | **Met** | `SUM(CASE WHEN type = 'income' THEN amount END)` and the `expense` twin, summed **in SQL**; the Income / Spending / Net tiles on `/transactions`, `/dashboard` and `/reports`. |
| 4 | *"average"* | **NOT MET** | There is **no** average anywhere: not on a tile, not in a table column, not in a helper. `grep -n "average\|Average\|AVG" views/ includes/` returns **nothing**. |

The audit that produced this issue also confirmed the other two "average"
traps that a naive implementation would fall into:

- There is **no `AVG()`** in any query — correct, because `AVG()` over the
  rows that exist is *not* the average the brief asks for (see §3).
- The "Over time" table already carries `tx_count` per bucket, and the
  transaction count is shown in the "By category" table. So the ingredients of
  an average are on the screen — but the division is never performed.

---

## 3. What "average" must mean — the semantic decision

Three candidate readings exist. They are **not** interchangeable, and the
chosen one must be stated in the code, in the UI and in the tests.

| # | Reading | Formula | Example (October 2026: spending 847.80 over 31 days) |
|---|---|---|---|
| **A1** | **Average per time unit** | period total ÷ number of calendar time units in the period | 847.80 ÷ 31 = **27.35 per day** |
| **A2** | **Average per bucket** | period total ÷ number of buckets in the "Over time" table | 847.80 ÷ 5 weeks = **169.56 per week** |
| **A3** | **Average per transaction** | period total ÷ number of transactions | 847.80 ÷ 24 = **35.33 per transaction** |

**The decision: the reports screen shows A1 — the average per time unit — and
the unit is always named on the screen.**

The reasons:

1. **The brief's words are "average by day by month by week by year".** That is
   a list of **time units**, not of buckets and not of transactions. A1 is the
   only reading that produces a number for each of the four named units.
2. **A2 is an accident of the grain.** The grain is derived from the period
   (`reportGrain()`): a day period buckets by day, a week period buckets by
   day, a month period buckets by week, a year period buckets by month. So A2
   would show "per day" for two periods, "per week" for one and "per month" for
   one — and **never** "per year". The brief names four units; A2 covers three.
3. **A2 is not stable.** The first and last buckets of a month view are
   **partial** — the month view's weekly buckets hang over the ends of the
   month. Dividing by the bucket *count* treats a 3-day bucket as a 7-day one.
   A1 divides by the actual calendar length and does not care.
4. **A3 is a different metric.** It is useful, and the data for it is on the
   screen (`tx_count`), but it is not what *"average by day by month by week by
   year"* asks for. It must not be labelled "average" next to A1.

### The unit is a **visitor's choice**, and it is independent of the period

The period selector already offers day / week / month / year. If the averaging
unit were derived from the period, the requirement would collapse:

- period = day → one day → "average per day" = the total. Correct, and
  useless.
- period = year → "average per month" only. "Average per day for this year"
  would be unreachable.

So the averaging unit must be **its own control**, independent of the period:

| Period | Range | The unit is chosen by the visitor |
|---|---|---|
| Day | one day | day · week · month · year |
| Week | seven days | day · week · month · year |
| Month | one calendar month | day · week · month · year |
| Year | one calendar year | day · week · month · year |
| Custom | the range between two dates | day · week · month · year |

The **default** unit is **day** — it is the unit people think in, and it is the
only unit that is always meaningful for any range, including a single day.

> The unit must be carried in the **query string** (`?period=month&unit=day`),
> must be **whitelisted** to the four names, and must **not** be interpolated
> into SQL. An unknown unit falls back to `day`.

### The rule that must not be broken

> **The average is always `period total ÷ number of calendar time units in the
> period`. It is never the mean of the per-bucket totals, and never `AVG()` over
> the rows that exist.**

`AVG()` over the existing rows answers "the average of the transactions I
happened to record" — not "the average per day". A month with two recorded
spendings and a month with sixty must both report the same *daily* average if
their totals are equal. The denominator is **calendar**, not **recorded**.

---

## 4. The data contract

### 4.1 The three measures

The average is computed for the **same three measures** the tiles already
show, and with the same signs:

| Measure | The tile | The average |
|---|---|---|
| Income | `+1,229.99` | `+41.00 per day` |
| Spending | `−847.80` | `−27.35 per day` |
| Net | `+382.19` | `+12.33 per day` |

The **net average is not a third division.** It is `net_total ÷ count`, which
equals `income_average − spending_average` exactly, before rounding.

### 4.2 The denominator — the number of calendar units

The count is a property of the **range**, not of the data. It is computed from
the range alone and is therefore the same for every user and every account.

| Unit | The count | How it is computed |
|---|---|---|
| **day** | the number of days in the range, inclusive on both ends | `reportSpanDays($from, $to)` — **already exists** at `includes/functions.php:727` |
| **week** | the number of calendar weeks the range touches, aligned to `WEEK_START` | `ceil(spanDays / 7)` is **wrong** when the range does not start on the week start; the count must be the number of **distinct week buckets** the range touches |
| **month** | the number of distinct `(year, month)` pairs the range touches | e.g. `2026-01-31 … 2026-03-01` touches **3** months, not 2 |
| **year** | the number of distinct years the range touches | e.g. `2025-12-31 … 2026-01-01` touches **2** years |

**The week count is the trap.** `reportSpanDays()` is an inclusive day count;
`ceil(days / 7)` is right only when the range starts on the week start. A
range of Monday → Sunday is 7 days and 1 week; a range of Wednesday → Tuesday
is 7 days and **2** week buckets. The count must be derived from the **same
week-bucket expression** the "Over time" table uses — `reportBucketExpression('week')` —
so the two agree about what a week is. The same `WEEK_START` constant drives
both. **A hard-coded Monday in one place and a `WEEK_START` in the other is a
bug**, exactly as `reportBucketExpression()` already warns about.

**The month and year counts are calendar counts, not `months_between()`**
arithmetic: a range that starts on the 31st and ends on the 1st touches three
months, and a month-length division gets it wrong.

The count must be computed in **PHP**, from the range, and **injected into the
SQL as a bound parameter** — never interpolated.

### 4.3 The SQL

The period totals are already computed in one statement (`$totalsSql` in
`views/reports.php:133`). The averages are the **same statement** with the
division added:

```sql
SELECT
    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0)        AS income,
    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0)        AS spending,
    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                      WHEN type = 'expense' THEN -amount END), 0)       AS net,
    SIGN(COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                            WHEN type = 'expense' THEN -amount END), 0)) AS net_sign,
    COUNT(*)                                                             AS tx_count
  FROM transactions
 WHERE user_id = ?
   AND occurred_on BETWEEN ? AND ?
```

The division, in the same statement or in a second one over the same range:

```sql
    ROUND(COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) / ?, 2) AS avg_income,
    ROUND(COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) / ?, 2) AS avg_spending,
    ROUND(COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                             WHEN type = 'expense' THEN -amount END), 0) / ?, 2) AS avg_net
```

with the divisor bound as a parameter — **three times**, once per column.
`?` in a `DECIMAL` division yields a `DECIMAL`, and `ROUND(..., 2)` keeps it a
decimal string. **`AVG()` is not used, and the division is never performed in
PHP**, because `0.1 + 0.2 !== 0.3` is a bug in this codebase's money path and
the sum must not become a float on the way to the screen.

### 4.4 The rounding identity

Three independently rounded averages can break the identity
`avg_net = avg_income − avg_spending` by a cent:

```
avg_income   = 41.005 → 41.01     (rounded)
avg_spending = 27.335 → 27.34     (rounded)
avg_net      = 13.670 → 13.67     (rounded)
41.01 − 27.34 = 13.67             ✓ here
```

but with different values it can fail:

```
avg_income   = 10.005 → 10.01
avg_spending =  0.005 →  0.01
avg_net      = 10.000 → 10.00
10.01 − 0.01 = 10.00             ✓
```

and in the other direction the three can disagree by a cent. **The rule: the
average of the net is derived from the rounded income and spending averages**,
so the three numbers on the screen always reconcile:

```php
// The net average is the difference of the two rounded averages, so the
// three figures on the screen always add up.
$avgNet = bcsub((string) $avgIncome, (string) $avgSpending, 2);
```

`bcsub()` is **not available** in this image (the container has no `bcmath` —
verified). The subtraction must therefore be done in **SQL** as well, or in
integer cents:

> **The net average must be computed in SQL from the rounded income and
> spending averages** — `ROUND(net_total / count, 2)` is *not* sufficient; use
> `ROUND(...)` on the two sides and subtract them, or compute the net average
> as `ROUND(avg_income_rounded − avg_spending_rounded, 2)`.

A regression test must assert the identity **on a fixture that breaks it**,
not on a round-number fixture where it holds by luck.

### 4.5 The formatting

The average uses the **existing** money formatters, with the sign rules they
already enforce:

- `formatIncomeMoney()` for the income average — it keeps the `+`, including
  on a zero (`+0.00`), because the column says "income".
- `formatSignedMoney($value, '−')` for the spending average — the **U+2212**
  glyph, written as the **literal character**, never as `"\u{2212}"` and never
  as an ASCII hyphen.
- The net average's sign follows `avg_net_sign`, which must come **from SQL**
  (`SIGN()`), never from a PHP comparison of money — the same rule the tiles
  already follow.
- **A zero carries no sign.** `formatSignedMoney()` already handles that.

---

## 5. The edge cases

| # | Case | Required behaviour |
|---|---|---|
| 1 | The period contains **no transactions** | The average is **0.00** — an average of nothing is zero, not an em dash and not "—". It must be told apart from a **failed** query, which the application already does with the `$failed` flag and the `$periodEmpty` flag. |
| 2 | The range is a **single day** | `spanDays` = 1 → the average per day is the total. The average per month / per year is the total ÷ the number of calendar months / years the single day touches — **not** the total. |
| 3 | The range **straddles two months** | The month count is 2; the day count is the inclusive span. This is the same "a range is a span, not a month" rule that `formatPeriodLabel()` and `formatPeriodSpan()` already enforce for the label. |
| 4 | The range **straddles two years** | The year count is 2. |
| 5 | The range **does not start on the week start** | The week count is the number of distinct week buckets, **not** `ceil(days / 7)`. See §4.2. |
| 6 | The divisor would be **zero** | Impossible for a valid inclusive range — but the code must **guard** it, because a malformed custom range must not produce a division by zero. The screen shows the empty state instead. |
| 7 | The unit is **not one of the four** | Fall back to `day`. Never echo the raw value into the page. |
| 8 | The period **failed** to load | The average is not rendered at all — the error state, not a zero. |
| 9 | The average is **zero** and the total is **not** | Possible (income 0, spending 100). The sign rules apply to the average independently of the total. |
| 10 | A **custom range** with `from > to` | Already rejected by `resolvePeriodRange()`; the error state is unchanged and no average is computed. |

---

## 6. The presentation — the design track owns this

`docs/mockup/08-reports.html` is the **source of truth** for the appearance,
and it currently has **no average** — the mockup was built to
[issue-007](issue-007.md), which was written before the average was identified
as a requirement.

**The mockup must be extended before the screen is built**, so that the
application is built from the mockup and not from a developer's imagination.
The mockup must show, at minimum:

1. The **"Average per" control** — a four-way choice (Day / Week / Month /
   Year) beside the existing period selector, in the same visual language as
   the period links (`aria-current` on the active one, never a class).
2. The **average line** on each of the three tiles — the total, and the
   average per unit, with the unit named.
3. The **"Over time" table** with the average, or with a note that the bucket
   rows *are* the per-bucket figures. **This is a design decision** — see
   §6.1.
4. The **empty state** with the average at zero.

The mockup's own figures **must reconcile** — the same rule
[issue-019](issue-019.md) §13 established and the current mockup satisfies.
The reconciliation check is part of the acceptance criteria below.

### 6.1 The "Over time" table — the one design decision left open

The table's rows are the **per-bucket totals**. The tile's average is
**per unit**. When the unit and the grain happen to agree — the month view
with a week unit — the tile's average and the table's bucket rows are the
same family of number.

**This issue does not dictate that.** It dictates that:

- If the table gains a column, the column's header names **exactly** what it
  is: *"Average per day"*, *"Avg / day"* — never a bare *"Average"* that
  could be mistaken for the tile's per-unit average.
- The **per-transaction** average (`tx_count` is already there) is labelled
  *"Avg / transaction"* — a distinct metric with a distinct label.

The mockup decides. This issue decides that **one screen must not use the
bare word "Average" for two different quantities.**

### 6.2 The token rule

The mockup's `assets/tokens.css` and the application's
`assets/css/tokens.css` must declare the **same names in the same order**
(the rule in [issue-004](issue-004.md) §6.1 and
[issue-013](issue-013.md)). If the mockup needs a new token for the average
line, it must be added to **both** files, in the same position, and
`database/assets_test.php` must still pass.

---

## 7. The implementation — the application track

### 7.1 The helpers — `includes/functions.php`

Add the helpers next to the existing report helpers (`reportSpanDays()` at
:727, `reportGrain()` at :690), and name them so that they cannot be confused
with the other averages:

| Helper | Signature | Returns |
|---|---|---|
| `normalizeAverageUnit()` | `(string $unit): string` | `'day'`, `'week'`, `'month'` or `'year'`; anything else → `'day'`. |
| `reportUnitCount()` | `(string $from, string $to, string $unit): int` | the number of calendar units in the range — the denominator. |
| `formatAverageMoney()` | `(string $amount, string $unit, string $sign): string` | the formatted average with the unit named, e.g. `−27.35 per day`. |

`reportUnitCount()` is the piece that must not be re-derived at the call site.
It must agree with `reportGrain()` and `reportBucketExpression()` about what a
week is — the same `WEEK_START` constant drives all three.

### 7.2 The screen — `views/reports.php`

- Read the unit from `$_GET['unit']`, normalise it with
  `normalizeAverageUnit()`, and **whitelist** it. Never interpolate it.
- Compute the count with `reportUnitCount($range[0], $range[1], $unit)`.
- Add the three average columns to `$totalsSql` (or a second statement over
  the same range), with the count bound as a parameter.
- Render the average line on each tile, and the empty-state average at zero.
- The status line and the period label are **unchanged**.

### 7.3 The stylesheet

The average line reuses the existing tile tokens. It must not introduce a
token that is not in `tokens.css`.

> **As built:** the two new rules live in the **shared** layer,
> `components.css` — and in **both** copies of it (the mockup's and the
> application's). `assets/css/pages/reports.css` is **unchanged**: the
> mockup has **no** `pages/` layer, so a rule that lives only in the
> application's `pages/` layer would render in the application and not in
> the mockup it is the source of. §14.1 has the list.

### 7.4 The tests — `database/reports_test.php`

The existing file has two tiers (the sources, and a sandbox that runs the
real screen against a scratch database). **Both** tiers must grow:

- The **source tier** asserts that the helpers exist, that the unit is
  whitelisted, and that no `AVG(` appears in the query.
- The **sandbox tier** runs the real screen with a **known fixture** and
  asserts the average figures — including the **rounding-identity** case from
  §4.4.

---

## 8. Acceptance criteria

- [x] The `/reports` screen shows an **average** for income, spending and net,
      for a **day**, a **week**, a **month**, a **year** and a **custom
      range**.
      *The average line is on all three tiles in every state. The unit is
      the visitor's choice and is independent of the period, so a month
      view can be read per day, per week, per month or per year.*
- [x] The averaging unit is a **visitor's choice** — Day / Week / Month /
      Year — independent of the period, and the unit is **named on the
      screen** next to every average figure.
      *The "Average per" control sits beside the period selector; the
      figure itself reads `−27.35 per day` — the unit is in the figure, not
      in a heading that the reader has to carry in their head. The unit is
      carried in `?unit=`, whitelisted to the four names and never
      interpolated.*
- [x] The average is `period total ÷ calendar units in the period`, and the
      denominator is the **calendar**, not the number of recorded transactions
      and not the number of buckets.
      *October 2026: 1,229.99 ÷ **31** days = 39.68 per day, while the
      "Over time" table shows **three** weeks with transactions. The two
      numbers are not the same and must not be.*
- [x] The week denominator agrees with the `WEEK_START` constant and with the
      "Over time" table's week buckets — a range that does not start on the
      week start counts the **partial** week.
      *`2026-10-01 … 2026-10-31` touches **5** week buckets (two of them
      hold nothing); `2026-10-07 … 2026-10-13` — Wednesday to Tuesday —
      is 7 days and **2** buckets; `2026-10-05 … 2026-10-11` is 1. The PHP
      twin of the SQL bucket (`reportWeekStart()`) is asserted against the
      database's own `DATE_SUB(… INTERVAL WEEKDAY(…) DAY)` in §B11.*
- [x] The month denominator counts the distinct `(year, month)` pairs the
      range touches, and the year denominator counts the distinct years.
      *`2026-01-31 … 2026-03-01` is **3** months; `2025-12-31 … 2026-01-01`
      is **2** years. Measured on the screen: a range of three months with
      1,379.99 of income reads `+460.00 per month`, and a range of two
      years reads `−423.90 per year`.*
- [x] The division is performed **in SQL**, in `DECIMAL`, with the divisor
      **bound as a parameter**. There is no `AVG()` and no float arithmetic
      anywhere in the average path.
      *The divisor is bound **twice** and **cast**: `CAST(? AS
      DECIMAL(10,0))`. The cast is not decoration — a divisor that is bound
      as a string makes MariaDB divide in DOUBLE, and the average arrives
      at the screen as a float. Measured: `ROUND(1229.99 / ?, 6)` comes
      back as the PHP float `39.677097`; `ROUND(1229.99 / CAST(? AS
      DECIMAL(10,0)), 6)` comes back as the **string** `'39.677097'`. The
      source tier asserts that `AVG(` appears nowhere and that the average
      is not divided in PHP.*
- [x] The three averages satisfy `avg_net = avg_income − avg_spending`
      **after** rounding, on a fixture that would break it if the averages
      were rounded independently.
      *The net average is derived **in SQL** from the two rounded sides
      (`ROUND(avg_income − avg_spending, 2)`), so the identity holds by
      construction. The fixture breaks it: income 1.00 and spending 0.50
      over three days — 0.33, 0.17 and **0.16**, where the independently
      rounded net total would read 0.17. The screen reads `+0.16 per day`,
      and the test asserts that `+0.17 per day` is **not** on the page.*
- [x] An **empty** period shows an average of `0.00` — not an em dash, and
      not a failed-query state.
      *An empty range reads `0.00 per day` on all three tiles, and the
      failed-query state renders **no** average at all.*
- [x] The negative sign is **U+2212** (`−`), the literal character, and a
      zero carries no sign.
      *The glyph is the literal character in the source — a grep for it
      finds it — and the zero carries no sign: the empty period reads
      `0.00 per day`, never `+0.00` or `−0.00`.*
- [x] An unknown `unit` in the query string falls back to `day` and is never
      echoed into the page.
      *`?unit=nonsense` answers 200, marks the **day** as the current unit,
      reads `+39.68 per day` and never contains the word it was given.*
- [x] `docs/mockup/08-reports.html` shows the average control, the average
      line on the tiles and the empty-state average, and its **figures
      reconcile** with each other and with the period totals.
      *The month view reads `+39.68 / −27.35 / +12.33 per day` over 31
      days; the year view reads `+1,237.50 / −767.79 / +469.71 per month`
      over twelve months of `+14,849.99 / −9,213.45`; the custom view
      reads `+246.00 / −169.56 / +76.44 per week` over the five calendar
      weeks of October 2026; the empty view reads `0.00 per day` three
      times. Every pair reconciles: 39.68 − 27.35 = 12.33,
      1,237.50 − 767.79 = 469.71, 246.00 − 169.56 = 76.44.*
- [x] The mockup's `assets/tokens.css` and the application's
      `assets/css/tokens.css` still declare the same names in the same order,
      and `database/assets_test.php` passes.
      *No token was added at all: the average line uses the existing
      `--font-mono`, `--fs-sm` and `--gray-700`. The two new rules live in
      the **shared** `components.css` layer, and the mockup's copy and the
      application's copy are byte-identical.*
- [x] `database/reports_test.php` guards the helpers, the whitelisting, the
      absence of `AVG(`, and the fixture figures — in **both** tiers.
      *§A13 (the sources) and §B11 (the sandbox). The sandbox drives the
      **real** screen against a scratch database and reads the figures off
      the page.*
- [x] The **search** and the **sum** still work exactly as before: no
      regression on the period selector, the custom-range form, the "Over
      time" table or the "By category" table.
      *The period totals statement is **unchanged**; the averages are a
      second statement over the same range. The suite is 15/15 and the LIVE
      tier is 2/2.*

---

## 9. Verification

> **As run on 2026-10-30.** Every command below was executed against the
> running stack; the output quoted after it is what it returned.

```bash
# 1. The unit is whitelisted and never interpolated.
grep -n "unit" views/reports.php
# Every use must go through normalizeAverageUnit().

# 2. There is no AVG() and no float division in the average path.
grep -n "AVG(" views/reports.php includes/functions.php
# Expected: no output.

# 3. The denominators.
docker compose exec -T app php -r '
require "/var/www/html/config.php";
require "/var/www/html/includes/functions.php";
// WEEK_START = 1 (Monday), so 2026-10-05 is a Monday and 2026-10-07 a Wednesday.
$cases = [
    ["2026-10-01", "2026-10-31", "day",   31],
    ["2026-10-01", "2026-10-31", "week",  5],
    ["2026-10-01", "2026-10-31", "month", 1],
    ["2026-10-01", "2026-10-31", "year",  1],
    ["2026-01-31", "2026-03-01", "month", 3],
    ["2025-12-31", "2026-01-01", "year",  2],
    ["2026-10-05", "2026-10-11", "week",  1],   // Monday..Sunday  -> 1 bucket
    ["2026-10-07", "2026-10-13", "week",  2],   // Wednesday..Tuesday -> 2 buckets
];
foreach ($cases as [$f, $t, $u, $want]) {
    $got = reportUnitCount($f, $t, $u);
    printf("%s..%s per %-5s got %2d want %2d  %s\n", $f, $t, $u, $got, $want,
        $got === $want ? "ok" : "FAIL");
}'

# 4. The live screen: the average appears and the three figures reconcile.
#    (Sign in, then:)
curl -s -b /tmp/j "http://127.0.0.1:8088/reports?period=month&unit=day" \
  | grep -oP "per (day|week|month|year)</td>|per (day|week|month|year)"

# 5. The mockup reconciles.
grep -n "Average per\|per day\|per week\|per month\|per year" \
  docs/mockup/08-reports.html

# 6. The suite.
bash .github/skills/dev-stack-ops/scripts/run-tests.sh
# Expected: every test PASSes, with the completion marker.
bash database/live/run-live-tests.sh
# Expected: every live test PASSes.
```

> The LIVE tier needs a booted stack and an installed `config.php`, and it
> **writes to the dev database**. Every live test must clean up after itself.

---

## 10. Rollback

The change is confined to `views/reports.php`, `includes/functions.php`,
`assets/css/components.css`, `docs/mockup/assets/components.css`,
`docs/mockup/08-reports.html` and `database/reports_test.php`.
`assets/css/pages/reports.css` is **unchanged** — the two new rules live in
the shared layer, because the mockup has no `pages/` layer.

- The **search** and the **sum** are untouched — the period totals statement
  keeps its existing columns, and the averages are **added** beside them. A
  rollback removes the added columns and the added tile line; the tiles keep
  showing the totals.
- **No migration** is involved. The schema is not touched.
- The `unit` query parameter is additive: a rollback simply stops reading it.
  A stale link with `?unit=day` keeps working — the parameter is ignored.
- **Do not** run `docker compose down -v` to "reset" anything. Take a dump
  first.

---

## 11. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| The developer uses `AVG()` because it is one word | Medium | High | §3 states the rule explicitly, and the acceptance criteria require **no `AVG(`** in the sources. The regression test asserts it. |
| The average is computed in PHP with floats | Medium | High | The acceptance criteria require the division **in SQL**, in `DECIMAL`. The money path's `0.1 + 0.2 !== 0.3` rule is already the house rule; this issue extends it to the average. |
| The week denominator uses `ceil(days / 7)` | Medium | Medium | §4.2 gives the failing example (Wednesday → Tuesday is 7 days and **2** buckets). The verification recipe in **§9, step 3** includes that exact case. |
| The three averages disagree by a cent | Medium | Low | §4.4 fixes the order of operations, and the test fixture is chosen to break it. |
| The design track and the application track drift | Medium | Medium | §6 requires the mockup first, and the token-parity test (`database/assets_test.php`) guards the shared layer. |
| The average makes the tiles too tall | Low | Low | A design concern, owned by the mockup. The existing tile markup has a `tile__period` line that can carry it. |
| The developer adds the average to the dashboard and the transaction list as well | Medium | Low | §12 scopes it out. Those screens have a **fixed** period, so "per day" is the only meaningful unit there, and the requirement is satisfied by `/reports`. Adding it is a separate, welcome change — but it is not this issue. |

---

## 12. Out of scope

- **The dashboard tiles** (`views/dashboard.php`) — the dashboard shows a
  **fixed** period, so only "per day" is meaningful there. The requirement is
  satisfied by `/reports`, which has the day / week / month / year selector.
  Adding a daily average to the dashboard is a **separate, welcome** change.
- **The transaction list tiles** (`views/transactions.php`) — the filtered
  total's average is a filtered-range average, and the filtered range is a
  different concept from the reported period. Out of scope.
- **A median** — not asked for, and not a `DECIMAL`.
- **An average of the average** — no.
- **A budget or a forecast** — the brief does not ask for one, and a forecast
  from a personal spending history is a different product.
- **The search** — already implemented; see §13.

---

## 13. The rest of the requirement check — what is already met

Recorded so that the next reader does not repeat the work, and so that the
requirement check has a **complete** verdict rather than a partial one.

| The brief says | Verdict | Where it lives |
|---|---|---|
| *"record spending and income"* | **Met** | `database/schema.sql` — `transactions (id, user_id, category_id, type, amount DECIMAL(14,2), occurred_on DATE, description, …)`; the write paths are `views/transaction_save.php` and `views/transaction_delete.php`. |
| *"for personal use"* | **Met** | A single-user-per-account model with every query filtered on `user_id`; there is no sharing, no team, no multi-tenancy. |
| *"search"* | **Met** | `buildTransactionPredicate()` — the description `LIKE` (with `escapeLike()` and `ESCAPE`), the type filter, the category filter and the date range. |
| *"sum"* | **Met** | The Income / Spending / Net tiles on `/transactions`, `/dashboard` and `/reports`; the "Over time" and "By category" tables. All summed **in SQL**. |
| *"in time period like day, month, weeks, year"* | **Met** | `resolvePeriodRange()`, `resolveMonthRange()`, `normalizePeriod()` and the day / week / month / year selector on `/reports`; the presets on `/transactions`. |
| *"average"* | **Met** | `/reports` — the "Average per" control, the average line on the Income / Spending / Net tiles, the denominator in `reportUnitCount()`, the division in SQL. See [issue-026](issue-026.md) §14. |
| *"webserver: litespeed"* | **Met** | OpenLiteSpeed / LSPHP, the `.htaccess` rewrite, the pinned `litespeedtech/openlitespeed:1.9.4-lsphp85` image. |
| *"language: php"* | **Met** | Plain PHP, no framework. |
| *"db: mariadb"* | **Met** | MariaDB 10.11, `utf8mb4`. |
| *"docker … not depend on docker"* | **Met** | `config.php` is the only configuration source; the app runs natively with no Docker code path. |
| *"search for config.php if exist goto index.php"* | **Met** | The install gate in `index.php` — the `is_file()` check runs **before** the dependency bootstrap. |
| *"if not install"* | **Met** | `install.php`, the three-step wizard. |
| *"install create config.php, allow user to customize database name, database user, database password, smtp server, smtp port, email user, smtp password"* | **Met** | Step 1 (database) and step 2 (mail) of the wizard; the values are written with `var_export()`. |
| *"config.php also included ALLOW_REGISTRATION default true"* | **Met** | `const ALLOW_REGISTRATION = true;` in `config.template.php`; a **real PHP boolean**, compared with `=== true` / `=== false`. Guarded by `database/config_test.php`. |
| *"if set to false don't allow user registration, both FE/BE are restricted"* | **Met** | Two-sided: the GET renders **no form** and returns 403; the POST returns 403 **before** the CSRF check and before any input is read. Guarded by `database/auth_test.php`. |

---

## 14. The implementation, as built

Implemented on 2026-10-30, in the order this issue demands: the **design
track first** (§6), then the application (§7), then the tests (§7.4).

### 14.1 What changed

| File | What it carries |
|---|---|
| `docs/mockup/08-reports.html` | the "Average per" control, the average line on every tile, the hidden `unit` field of the range form, the empty state at zero, and the two spec notes that explain the decisions |
| `docs/mockup/assets/components.css` | `.tile__average` and `.period__label` — **no new token** |
| `assets/css/components.css` | the same two rules, byte-identical to the mockup's copy |
| `includes/functions.php` | `normalizeAverageUnit()`, `reportWeekStartDayNumber()`, `reportWeekStart()`, `reportUnitCount()`, `formatAverageMoney()` — and the week-bucket fix of §14.7 |
| `views/reports.php` | the unit, the averages statement, the three tile lines, the control, the links |
| `database/reports_test.php` | §A13 (the sources) and §B11 (the sandbox) |

`assets/css/pages/reports.css` is **unchanged**. The two new rules live in
the **shared** layer, `components.css`, because the mockup has **no**
`pages/` layer: a rule that lived only in the application's `pages/` layer
would render in the application and not in the mockup it is the source of.

### 14.2 The helpers

Five, in `includes/functions.php`, next to the other report helpers:

| Helper | Signature | Returns |
|---|---|---|
| `normalizeAverageUnit()` | `(string $unit): string` | one of the four names; anything else → `'day'` |
| `reportWeekStartDayNumber()` | `(?int $start = null): int` | the week start in **MariaDB's** numbering — the one conversion between the two day numberings |
| `reportWeekStart()` | `(string $date): string` | the first day of the week a date falls into — the **PHP twin** of the SQL bucket |
| `reportUnitCount()` | `(string $from, string $to, string $unit): int` | the denominator; **0** for a range that ends before it starts |
| `formatAverageMoney()` | `(string $amount, string $unit, string $sign): string` | e.g. `−27.35 per day` |

`reportUnitCount()` is the only place a denominator is computed. The week
count is **not** `ceil(days / 7)`: it is the number of distinct week buckets
the range touches, and it is derived from `reportWeekStart()` — the PHP twin
of `reportBucketExpression('week')`. The two agree about what a week is
because the same `WEEK_START` constant drives both, and because the day
numbering conversion lives in **one** function (§14.7).

### 14.3 The SQL — a second statement, and a cast

The averages are a **second statement** over the same range, not three more
columns of the totals statement. The totals statement is **unchanged**, which
is what makes the rollback in §10 a one-line revert.

```sql
SELECT avg_income,
       avg_spending,
       ROUND(avg_income - avg_spending, 2)       AS avg_net,
       SIGN(ROUND(avg_income - avg_spending, 2)) AS avg_net_sign
  FROM (
    SELECT ROUND(COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0)
                 / CAST(? AS DECIMAL(10,0)), 2) AS avg_income,
           ROUND(COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0)
                 / CAST(? AS DECIMAL(10,0)), 2) AS avg_spending
      FROM transactions
     WHERE user_id = ?
       AND occurred_on BETWEEN ? AND ?
   ) AS averages
```

Three things in there are not decoration:

1. **The divisor is bound twice** — once per side — and it is the value
   `reportUnitCount()` returned. It is never interpolated.
2. **The divisor is cast**: `CAST(? AS DECIMAL(10,0))`. A divisor that is
   bound as a **string** makes MariaDB divide in **DOUBLE**, and the average
   would arrive at the screen as a float. Measured through the application's
   own PDO wrapper: `ROUND(1229.99 / ?, 6)` comes back as the PHP float
   `39.677097`; `ROUND(1229.99 / CAST(? AS DECIMAL(10,0)), 6)` comes back as
   the **string** `'39.677097'`. This is the same rule as the rest of the
   money path, and it is invisible until somebody measures it.
3. **The net is derived from the two rounded sides**, so the identity of
   §4.4 holds by construction rather than by luck.

The divisor is guarded in PHP as well: `$unitCount < 1` means **no averages
query at all**, so a malformed range can never produce a division by zero.

### 14.4 The sign convention — a deliberate deviation from §4.5

§4.5 says the income average uses `formatIncomeMoney()`, which keeps the
"+" on a zero — "+0.00" — because "the column says income". The **tile**
convention is the opposite: the tile shows a bare `0.00` for an empty
period, and §8 says *"An empty period shows an average of 0.00"*.

The average appears **only on the tiles**, so the tile convention wins: all
three averages use `formatSignedMoney()`, and a zero carries **no sign**.
The income average of an empty period is therefore `0.00 per day`, not
`+0.00 per day`.

This is a **deviation from §4.5**, and it is recorded here rather than
quietly applied: §4.5's premise was a **column** whose header says "income",
and the design decision of §14.5 removed that column.

### 14.5 The presentation

- The **"Average per" control** sits beside the period selector, in the same
  `.period` visual language, with `aria-current="true"` on the active one
  and never a class.
- The **average line** is a fourth line on each tile, under the amount and
  above the period line.
- The **"Over time" table gains no average column.** Its rows **are** the
  per-bucket figures, and a per-bucket average would be **A2** — the reading
  §3 rejects. The mockup carries a spec note that says so. One screen, one
  meaning of the word "average" (§6.1).
- The **per-transaction average** (A3) is not added: `tx_count` is on the
  screen, but a second metric called "average" would break §6.1.
- The **empty state** shows `0.00 per day` on all three tiles.
- The **status line and the period label** are unchanged.

The unit travels in `?unit=` and is carried by every link on the screen: the
period links, the unit links, the "Over time" row links and the empty
state's "Look at this month" button all keep it.

### 14.6 The regression test, as written

`database/reports_test.php` grew in **both** tiers.

**§A13 — the sources** (28 assertions): the three helpers exist; the unit is
whitelisted (`'WEEK'` → `week`, `' month '` → `month`, `'nonsense'` →
`day`); the eight denominator cases of §9; a range that ends before it
starts has no units; `reportWeekStart('2026-10-07')` is `2026-10-05`; the
figure names its unit; a zero carries no sign; **no `AVG(`** anywhere; the
divisor is bound and cast; the divisor is the unit count; the net is
computed from the rounded sides; the sign comes from the database; the
average is **not** divided in PHP; the unit control carries the four links
and names itself; the three tiles carry an average; the range form keeps
the unit.

**§B11 — the sandbox** (34 assertions): the real screen, against a scratch
database, with the figures read off the page — per day, per week, per month
and per year; the active unit is marked and the period is kept; an unknown
unit falls back to the day and is **never echoed**; the three-month and the
two-year denominators; the empty period at `0.00 per day` with **no sign**;
the PHP week twin against the database's own `DATE_SUB(… INTERVAL
WEEKDAY(…) DAY)`; and the **rounding fixture** — income 1.00 and spending
0.50 over three days, which reads `+0.33`, `−0.17` and **+0.16**, where the
independently rounded net total would read `+0.17`.

### 14.7 The one thing this issue did not say — the week bucket was off by one

The requirement "the week denominator agrees with the `WEEK_START` constant
and with the 'Over time' table's week buckets" is not checkable without a
week-bucket expression that is **correct** — and `reportBucketExpression()`
was not, for every week start **except** Monday and Sunday.

Its general branch mixed the two day numberings: it subtracted the
**config's** week-start number from MariaDB's `DAYOFWEEK()`, and the two
numberings differ by one for every day except Sunday. A week configured to
start on **Tuesday** therefore produced a **Monday-aligned** bucket — and the
wrap made it worse than a plain off-by-one, because the last day of the week
landed in a bucket of its own.

Measured, with `WEEK_START = 2` (Tuesday):

| The date | The old bucket | The fixed bucket |
|---|---|---|
| 2026-10-06 (Tuesday) | 2026-10-05 (Monday) | **2026-10-06** (Tuesday) |
| 2026-10-07 (Wednesday) | 2026-10-05 (Monday) | **2026-10-06** (Tuesday) |
| 2026-10-11 (Sunday) | 2026-10-05 (Monday) | **2026-10-06** (Tuesday) |
| 2026-10-12 (Monday) | 2026-10-12 (Monday) | **2026-10-06** (Tuesday) |

The fix is one function — `reportWeekStartDayNumber()` — that converts the
config's day number into MariaDB's, and it is used by **both** the SQL
expression and the PHP twin. The default (`WEEK_START = 1`, Monday) is
unaffected: it has its own branch, and the literal the existing test
asserts is unchanged.

The defect was **latent**: the shipped configuration starts on Monday, so
nothing on the screen was wrong. It would have become wrong the moment
`WEEK_START` was set to anything else — and the average would have been the
first screen to show it, because the average's denominator is the week.

### 14.8 The measured result

| The check | The result |
|---|---|
| The SOURCE suite | **15 PASS / 0 FAIL** |
| The LIVE tier | **2 PASS / 0 FAIL** |
| `grep -n "AVG(" views/reports.php includes/functions.php` | **no output** |
| The denominators of §9, step 3 | **8 of 8 ok** |
| The average of October 2026 | `+39.68 / −27.35 / +12.33 per day` |
| The average of October 2026 | `+246.00 / −169.56 / +76.44 per week` |
| The average of the empty period | `0.00 per day` × 3 |
| The unknown unit | `200`, the **day** is marked, the word is not echoed |
| The rounding fixture | `+0.33 / −0.17 / **+0.16** per day` — not `+0.17` |
| The token parity | unchanged — **no token was added** |
