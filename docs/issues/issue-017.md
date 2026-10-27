# Issue-017 — App: the dashboard

> **Status:** **specified, not implemented** — `views/dashboard.php` does not exist
> yet.
> **Target:** v0.2 (the application) — the screen the product opens on.
> **Severity:** **high** — the dashboard is the first screen every signed-in
> visitor sees, and it is where a wrong total is most visible.
>
> **Requested by the user:**
> > "I guess, we now have everything, ready for dev, write issue files for dev to
> > implement the project."
>
> **Depends on:** [issue-011.md](issue-011.md) (the `transactions` table),
> [issue-012.md](issue-012.md) (the period vocabulary, `formatMoney()`,
> `e()`), [issue-013.md](issue-013.md) (the app shell and `components.css`),
> [issue-014.md](issue-014.md) (the front controller), [issue-016.md](issue-016.md)
> (the session).
> **Related:** [issue-018.md](issue-018.md) (the transactions screen),
> [issue-019.md](issue-019.md) (the reports screen — the same period vocabulary),
> `docs/mockup/05-dashboard.html` (**the screen being built**);
> `.github/instructions/database.instructions.md` (**period rollups in SQL**).

---

## Table of contents

1. Objective
2. Constraints
3. The route
4. The shell
5. Specification — the period selector
6. Specification — the three tiles
7. Specification — the recent transactions card
8. Specification — the three empty states
9. Specification — the error state
10. Specification — the queries
11. Acceptance criteria
12. Verification
13. Rollback
14. Risks
15. Out of scope

---

## 1. Objective

Build the dashboard: a period selector, three summary tiles, and the ten most
recent transactions.

Success criteria:

| # | Criterion |
|---|---|
| 1 | The screen opens on **This month** |
| 2 | The period selector covers **today / week / month / year / custom** |
| 3 | The tiles show **Income**, **Spending** and **Net** for the selected period |
| 4 | A net of exactly zero is **neutral**, not green |
| 5 | The sums are computed in **SQL**, never in a PHP loop |
| 6 | The recent list is capped at **ten rows** |
| 7 | "No transactions yet" and "Nothing in this period" are **different messages** |
| 8 | An error is **never** rendered as `0.00` |
| 9 | One user's data is never visible to another |
| 10 | The browser **never sums anything** |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| The server is the **single source of truth** | The mockup is the specification of *appearance*; the server owns *behaviour and data*. |
| Money is `DECIMAL(14,2)` and **never a float** | The sums arrive from SQL as strings; `formatMoney()` formats them for display. |
| Period grouping belongs in the **query** | Not in a PHP loop over rows. |
| `occurred_on` is a **`DATE` in UTC** | The period range is resolved in UTC. |
| `WEEK_START` is the **only** week-start rule | `weekStartDay()` — nothing hard-codes Monday. |
| The app is **server-rendered** | There is no fetch, no JSON, no client-side sum. |
| The recent list is **read-only** | It links to the transactions screen; it does not edit or delete. |
| `display_errors = Off` | A failure renders the error state, not a blank page. |

---

## 3. The route

| Method | Path | Handler | Auth |
|---|---|---|---|
| `GET` | `/dashboard` | `views/dashboard.php` | **required** |

````php
requireLogin();                       // FIRST — before any query
$period = normalizePeriod($_GET['period'] ?? null);   // default: month
````

| Rule | Reason |
|---|---|
| `requireLogin()` is the **first** call | An unauthenticated visitor never reaches a query. |
| The period comes from the **query string**, defaulting to `month` | The mockup opens on the month. |
| Both `today` and `day` are accepted | `normalizePeriod()` maps `today` → `day` ([issue-012.md](issue-012.md) §9). |
| An unknown period value falls back to `month` | `normalizePeriod()` owns that decision. |
| The route is `GET` only | No POST on the dashboard — nothing on it mutates. |

---

## 4. The shell

The dashboard uses the **application** shell from [issue-013.md](issue-013.md) — not
the auth shell.

````html
<div class="app">
  <header class="app__bar">
    <div class="app__bar-inner">
      <a class="app__brand" href="/dashboard">Financial Tracker</a>
      <nav class="nav" aria-label="Primary">
        <a class="nav__link" href="/dashboard" aria-current="page">Dashboard</a>
        <a class="nav__link" href="/transactions">Transactions</a>
        <a class="nav__link" href="/reports">Reports</a>
        <a class="nav__link" href="/categories">Categories</a>
      </nav>
      <span class="app__spacer"></span>
      <span class="app__user">signed in as <strong>demo</strong></span>
      <a class="nav__link" href="/settings">Settings</a>
    </div>
  </header>

  <main class="app__main">
    <div class="page__header">
      <div>
        <h1 class="page__title">Dashboard</h1>
        <p class="page__subtitle">Your income, spending and net for the selected period.</p>
      </div>
      <div class="page__actions">
        <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
      </div>
    </div>
    …
  </main>

  <footer class="app__footer">
    <p>Financial Tracker · personal use.</p>
  </footer>
</div>

<nav class="bottom-nav" aria-label="Primary, mobile">
  <div class="bottom-nav__inner">
    <a class="bottom-nav__link" href="/dashboard" aria-current="page">Dashboard</a>
    <a class="bottom-nav__link" href="/transactions">Transactions</a>
    <a class="bottom-nav__link" href="/reports">Reports</a>
    <a class="bottom-nav__link" href="/categories">Categories</a>
  </div>
</nav>
````

| Rule | Reason |
|---|---|
| The nav order is **Dashboard · Transactions · Reports · Categories** | The mockup's order, fixed. |
| **Settings** is the last item in the app bar, **after** the spacer | It is separated from the primary navigation. |
| The app bar shows **"signed in as `username`"** | The username is escaped with `e()`. |
| The bottom nav has **four** links — **no Settings** | The mockup's mobile nav. Settings is reachable from the app bar. |
| The page subtitle is fixed copy | "Your income, spending and net for the selected period." |
| The page title is **"Dashboard"** | |
| The `<title>` is **"Dashboard — Financial Tracker"** | Escaped, per the house rule. |

---

## 5. Specification — the period selector

````html
<div class="period" data-period-group="month">
  <a class="period__link" href="/dashboard?period=today"  data-period="today">Today</a>
  <a class="period__link" href="/dashboard?period=week"   data-period="week">This week</a>
  <a class="period__link" href="/dashboard?period=month"  data-period="month"  aria-current="true">This month</a>
  <a class="period__link" href="/dashboard?period=year"   data-period="year">This year</a>
  <a class="period__link" href="/dashboard?period=custom" data-period="custom">Custom</a>
</div>
````

| Rule | Reason |
|---|---|
| The active link carries **`aria-current="true"`** | It is how a screen reader knows where the visitor is. |
| The inactive links carry **no** `aria-current` | Not `aria-current="false"` — the attribute is absent. |
| The group carries **`data-period-group`** with the active period | The client-side marker in `assets/js/app.js` reads it. |
| Each link carries **`data-period`** | The same. |
| The labels are **Today · This week · This month · This year · Custom** | The mockup's wording. Not "Day", not "Monthly". |
| The links are **real links with real `href`s** | The screen works with JavaScript disabled. |

### The period range

`resolvePeriodRange($period, $from, $to)` ([issue-012.md](issue-012.md) §9) is the
**only** place a period becomes a date range.

| Period | Range | `tile__period` label |
|---|---|---|
| `day` | today (UTC) | `24 October 2026` |
| `week` | Monday → Sunday, per `WEEK_START` | `19 – 25 October 2026` |
| `month` | the 1st → the last day of the month | `October 2026` |
| `year` | 1 January → 31 December | `2026` |
| `custom` | `from` … `to` | `1 – 31 October 2026` |

| Rule | Reason |
|---|---|
| The range is **inclusive on both ends** | `occurred_on BETWEEN :from AND :to`. |
| The label is **not** the raw ISO range | It is a human-readable period. |
| The week label uses an **en dash** (`–`, U+2013) **with spaces** | `19 – 25 October 2026`. A hyphen is not the same glyph. |
| The range label uses the **same en dash** | `1 – 31 October 2026`. |
| The month label is the month and year **only** | `October 2026`. |
| The year label is the year **only** | `2026`. |
| The label is computed from the **resolved range**, not from the period name | A custom range of 1 – 31 October must not read "October" if it spans two months. |

### The custom period — a specified mockup extension

> **The mockup shows a "Custom" link but no range form.** This issue specifies
> the smallest behaviour that keeps the selector self-contained.

| Case | Behaviour |
|---|---|
| `?period=custom&from=2026-10-01&to=2026-10-31` | The tiles render for that range; the Custom link is `aria-current="true"`. |
| `?period=custom` with no range | The dashboard renders the **custom-range form** in place of the tiles: a `from` date field, a `to` date field and an **"Apply range"** button — the same control the reports screen uses ([issue-019.md](issue-019.md)). |
| `from` after `to` | The message is **"The start date must be on or before the end date."** and the range is not applied. |
| An impossible date (`2026-02-31`) | Rejected by `isValidDateString()`; the form re-renders with the error. |

> This is the **only** place the dashboard adds something the mockup does not show.
> It is a deliberate, minimal extension — the alternative (navigating to the
> reports screen) breaks the period group.

---

## 6. Specification — the three tiles

````html
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
````

### The three labels

**Income**, **Spending**, **Net** — in that order, always, in every state.

### The amount format

| Tile | Sign | Glyph |
|---|---|---|
| Income | `+` | ASCII plus, `+1,200.00` |
| Spending | `−` | **U+2212 MINUS SIGN** — `−1,041.80` |
| Net, positive | `+` | `+158.20` |
| Net, negative | `−` | `−158.20` |
| Net, zero | **none** | `0.00` |
| Anything, zero | none | `0.00` |

| Rule | Reason |
|---|---|
| The negative sign is **U+2212**, not an ASCII hyphen-minus | The mockup uses a typographic minus. A hyphen in a money column looks like a separator. |
| The thousands separator is **`,`** and the decimal separator is **`.`** | `number_format($x, 2, '.', ',')`. Never the locale. |
| There are **always two decimals** | `0.00`, not `0`. |
| The zero has **no sign** | `+0.00` is noise. |
| The sign is **prepended by the template**, not by `number_format()` | `number_format()` renders a negative as `-1,041.80` with an ASCII hyphen. The template converts it. |

### The modifiers

| Tile | Modifier | When |
|---|---|---|
| Income | `tile--income` | always |
| Spending | `tile--spending` | always |
| Net | `tile--net` | always |
| Net | `tile--net-positive` | net > 0 |
| Net | `tile--net-negative` | net < 0 |
| Net | `tile--net-zero` | net = 0 |

| Rule | Reason |
|---|---|
| The **net zero** state is **neutral**, not green | The mockup's rule: *"a net of exactly zero is neutral, not green."* |
| The comparison is on the **decimal value**, not on the string | `"0.00" > 0` is false in PHP for a non-numeric-string comparison — cast once, carefully. |
| The semantic colours come from the **tokens** — the application adds no colours of its own | [issue-013.md](issue-013.md) §12. |

### The period label

`tile__period` repeats the resolved period under **all three** tiles — the same
label three times.

| Rule | Reason |
|---|---|
| The label is **identical** on all three tiles | |
| The label is **never empty** | The mockup's loading state shows `&nbsp;` — that is a mockup artefact, not a rendered state. |

### The error rule

> **An error is never rendered as `0.00`.** A zero is a claim about the data; an
> error is a claim about the request. They must never look alike.

When the summary query fails, the tiles are **not rendered at all** — see §9.

---

## 7. Specification — the recent transactions card

````html
<div class="card">
  <div class="card__header">
    <h2>Recent transactions</h2>
    <a href="/transactions">View all transactions →</a>
  </div>

  <div class="card__body">
    <div class="tx">
      <span class="tx__date">2026-10-24</span>
      <span class="tx__desc">
        <span class="tx__desc-title">Weekly groceries</span><br>
        <span class="tx__desc-meta"><span class="badge badge--neutral">Groceries</span></span>
      </span>
      <span class="tx__amount amount--expense">−42.80</span>
    </div>
    …
  </div>
</div>
````

| Rule | Reason |
|---|---|
| The card header holds the title **and** the "View all" link | The link is a plain `<a>` inside `card__header`. |
| The link text ends with **`→`** (U+2192) | `View all transactions →`. The arrow is part of the mockup's copy. |
| The link points at **`/transactions`** | The whole list, not the current period. |
| The list is capped at **ten rows** | The mockup's note. |
| The rows are **newest first** | `ORDER BY occurred_on DESC, id DESC`. |
| The date is the **ISO** date `2026-10-24` | It is a `DATE` column; `formatDate()` renders it for display. |
| The description is escaped with `e()` | It is user data. |
| The category is a `badge badge--neutral` | |
| A transaction with **no category** shows the badge **"Uncategorised"** | The same word the reports screen uses ([issue-019.md](issue-019.md)). |
| The amount carries `amount--income` / `amount--expense` | And the same sign convention as the tiles: `+` / `−` (U+2212). |
| The card has **no** delete or edit control | The dashboard is read-only. |

### The query

````sql
SELECT t.occurred_on, t.description, t.amount, t.type, c.name AS category_name
FROM transactions t
LEFT JOIN categories c ON c.id = t.category_id
WHERE t.user_id = :uid
ORDER BY t.occurred_on DESC, t.id DESC
LIMIT 10;
````

| Rule | Reason |
|---|---|
| The recent list is **not filtered by the period** | The mockup shows transactions from 14–24 October on the *month* view, but the card is the "recent" list, not "this period". The period applies to the **tiles**. |
| The category join is a **`LEFT JOIN`** | A transaction with no category must still appear. |
| The `LIMIT` is in the query | Not `array_slice()` in PHP. |
| The ownership filter is `t.user_id = :uid` | The authorization model, enforced in the SQL. |

---

## 8. Specification — the three empty states

The dashboard distinguishes **three** situations. They are not the same message.

### A — no transactions at all

The account has never recorded anything.

````html
<div class="empty">
  <h3 class="empty__title">No transactions yet</h3>
  <p class="empty__body">Record your first entry to start tracking this period.</p>
  <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
</div>
````

| Rule | Reason |
|---|---|
| The tiles **still render**, at `0.00` | The mockup's rule. A zero here is a real sum of nothing. |
| The net tile is **`tile--net-zero`** | Neutral. |
| The button is **`btn--primary`** | The one clear next action. |
| The body says *"this period"* | The mockup's wording, even though the account is empty overall. |

### B — transactions exist, but not in this period

````html
<div class="empty">
  <h3 class="empty__title">No transactions this week.</h3>
  <p class="empty__body">Nothing was recorded between 19 and 25 October 2026.</p>
  <a class="btn btn--secondary" href="/transactions?period=month">Look at this month</a>
</div>
````

| Rule | Reason |
|---|---|
| The title **names the period** | "No transactions this week." — not "No transactions yet". |
| The body gives the **exact range** | "Nothing was recorded between 19 and 25 October 2026." |
| The button is **`btn--secondary`** | It is a suggestion, not the main action. |
| The button links to **another period** | The periods are siblings, so the link switches to one that has data. |
| The period word in the title matches the period | "No transactions this month.", "No transactions today." |

### C — the summary failed

See §9.

### How A and B are told apart

| Question | Answer |
|---|---|
| Does the user have **any** transaction at all? | A query: `SELECT 1 FROM transactions WHERE user_id = :uid LIMIT 1`. |
| Does the user have a transaction **in this period**? | The tile query's row count. |

| Rule | Reason |
|---|---|
| The "any transaction at all" check is a **separate, cheap query** | `LIMIT 1`, with an index on `(user_id, occurred_on)`. |
| The empty-state choice is made **server-side** | The browser does not infer it from an empty list. |

---

## 9. Specification — the error state

````html
<div role="alert">
  <div class="alert alert--danger">
    <div class="alert__body">
      <p class="alert__title">The summary could not be loaded.</p>
      <p>Please try again.</p>
    </div>
  </div>
</div>

<div class="card">
  <div class="empty">
    <h3 class="empty__title">No summary available</h3>
    <p class="empty__body">The numbers could not be retrieved, so nothing is shown here.</p>
    <button type="button" class="btn btn--secondary">Try again</button>
  </div>
</div>
````

| Rule | Reason |
|---|---|
| The **tiles are not rendered at all** | Rendering `0.00` after a failure would be a lie about the data. |
| The period selector **is still rendered** | The visitor can pick another period. |
| The recent list is **not rendered** | It comes from the same failure. |
| The alert wrapper carries **`role="alert"`** | It is an error, announced immediately. |
| The title is **"The summary could not be loaded."** | The mockup's exact string. |
| The body is **"Please try again."** | |
| The empty state says **"No summary available"** — *not* "No transactions" | The distinction matters: the data may exist. |
| The "Try again" button is a **`button`**, not a link | It reloads the current period. |
| The raw error goes to **`error_log()`** | The LiteSpeed error log holds the detail. |

> **The loading state is a mockup artefact.** The skeleton markup in the mockup
> (`skeleton--tile`, `skeleton--title`, `skeleton--line`) describes what a
> client-rendered screen would show while a request is in flight. This
> application is **server-rendered**: the browser never holds a half-loaded
> dashboard, so the skeleton never appears. The `skeleton` classes exist in
> `components.css` and are **not** used by the dashboard. Do not build a
> client-side loading state for it.

---

## 10. Specification — the queries

### The period sums — one statement

````sql
SELECT
    COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0)      AS income,
    COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0)      AS spending,
    COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                      WHEN t.type = 'expense' THEN -t.amount END), 0)     AS net
FROM transactions t
WHERE t.user_id = :uid
  AND t.occurred_on BETWEEN :from AND :to;
````

| Rule | Reason |
|---|---|
| The **net is computed in SQL** | `income − spending` in PHP means two decimal strings become floats. The database does the subtraction in `DECIMAL`. |
| The `CASE` has **no `ELSE`** | A row that matches neither type contributes `NULL`, and `SUM` skips it. |
| `COALESCE(…, 0)` handles the **empty period** | `SUM` over no rows is `NULL`, not `0`. |
| The ownership filter is **in the same statement** | |
| The range is `BETWEEN :from AND :to` | Inclusive on both ends. |
| The values arrive as **strings** | `ATTR_STRINGIFY_FETCHES = false` keeps them as `DECIMAL` strings. |

### The empty-period check

````sql
SELECT COUNT(*) AS n FROM transactions t
WHERE t.user_id = :uid AND t.occurred_on BETWEEN :from AND :to;
````

Run it **only if** the sums row shows all zeros — a non-zero sum implies at least
one row, so the count is only needed for the empty state. (The recent list's own
`COUNT` is not needed; its emptiness is visible from the rows.)

### The "any transaction at all" check

````sql
SELECT 1 FROM transactions t WHERE t.user_id = :uid LIMIT 1;
````

### The formatting

````php
$income  = formatMoney((string) $row['income']);     // "1,200.00"
$spending = formatMoney((string) $row['spending']);   // "1,041.80"
$net      = formatMoney((string) $row['net']);        // "158.20"

// The signs are added by the template, with U+2212 for the negative.
$incomeLabel  = '+' . $income;
$spendingLabel = $spending === '0.00' ? '0.00' : '−' . $spending;
$netLabel      = $net === '0.00' ? '0.00'
               : (bccomp($net, '0', 2) > 0 ? '+' . $net : '−' . $net);
````

| Rule | Reason |
|---|---|
| The **sign is applied after** formatting | `number_format()` renders the negative with an ASCII hyphen. |
| The net's sign is decided by the **decimal value** | Comparing the formatted string (`"158.20" > 0`) is a string comparison in PHP 8 — wrong. |
| If `bcmath` is unavailable, compare the **absolute** formatted value and use the SQL `net` sign | The SQL already computed the sign. Prefer returning the sign from the query — e.g. `SUM(...) AS net` plus `SIGN(SUM(...)) AS net_sign` — so **no PHP comparison of money happens at all**. |

> **The cleanest form:** have the query return the signed net and a separate
> `net_sign` column, then the template only concatenates. If `bcmath` is not
> available in the runtime image, this is the way to keep float arithmetic out of
> the money path entirely. Check with
> `docker compose exec -T app php -m | grep bcmath`.

### The ownership rule

> **Ownership is enforced in the SQL by filtering on the owning user id.** Every
> statement above carries `user_id = :uid`, and `:uid` comes from the **session**,
> never from a request parameter.

---

## 11. Acceptance criteria

- [ ] `GET /dashboard` is the route and it calls **`requireLogin()` first**.
- [ ] The screen opens on **This month** when no `period` is given.
- [ ] The period selector renders **five** links — Today, This week, This month,
      This year, Custom.
- [ ] The active link carries **`aria-current="true"`**; the others do not carry
      the attribute at all.
- [ ] The period group carries **`data-period-group`** and each link carries
      **`data-period`**.
- [ ] The period links work **without JavaScript**.
- [ ] `?period=today` and `?period=day` select the **same** period.
- [ ] An unknown period value falls back to **month**.
- [ ] The week range starts on **`WEEK_START`**, not on a hard-coded day.
- [ ] The `tile__period` label is **"October 2026"** for a month, **"2026"** for a
      year, **"19 – 25 October 2026"** for a week (en dash, spaces around it).
- [ ] The three tiles are **Income**, **Spending**, **Net** — in that order, in
      every state.
- [ ] The income amount is prefixed with **`+`**.
- [ ] The spending amount is prefixed with **U+2212** (`−`), not an ASCII hyphen.
- [ ] The net tile carries **`tile--net-positive`**, **`tile--net-negative`** or
      **`tile--net-zero`** according to the value.
- [ ] A net of exactly zero is **`tile--net-zero`** — neutral, not green.
- [ ] A zero amount renders as **`0.00`** with no sign.
- [ ] Every amount has **two decimals** and a comma thousands separator.
- [ ] All three tiles show the **same** period label.
- [ ] The sums are computed in **SQL** — there is no PHP loop over rows.
- [ ] The net is computed in **SQL** as `income − expense`, not in PHP.
- [ ] The values are read as **strings** and formatted by `formatMoney()`.
- [ ] The recent list shows **at most ten** rows, newest first.
- [ ] The recent list is a **`LEFT JOIN`** on the category, so a transaction with no
      category still appears.
- [ ] A transaction with no category shows the badge **"Uncategorised"**.
- [ ] The recent card's header link reads **"View all transactions →"** (U+2192).
- [ ] Every query filters on **`user_id = :uid`** taken from the session.
- [ ] The description, the category name and the username are escaped with
      **`e()`**.
- [ ] The account-empty state reads **"No transactions yet"** with the body *"Record
      your first entry to start tracking this period."* and a **primary** "Add
      transaction" button.
- [ ] The period-empty state reads **"No transactions this week."** with the body
      *"Nothing was recorded between 19 and 25 October 2026."* and a **secondary**
      "Look at this month" button.
- [ ] The two empty states are **distinguished server-side**.
- [ ] The tiles still render (at `0.00`) in the account-empty state.
- [ ] The error state renders **no tiles at all** — never `0.00`.
- [ ] The error alert reads **"The summary could not be loaded."** and **"Please try
      again."**
- [ ] The error empty state reads **"No summary available"** — not "No
      transactions".
- [ ] The raw error goes to **`error_log()`** and never to the page.
- [ ] The app bar shows **"signed in as `username`"** with the username escaped.
- [ ] The nav order is **Dashboard · Transactions · Reports · Categories**, and
      **Settings** follows the spacer.
- [ ] The bottom nav has **four** links and **no** Settings link.
- [ ] The page stylesheet is **`assets/css/pages/dashboard.css`**.
- [ ] Every changed PHP file passes **`php -l`**.

---

## 12. Verification

```bash
cd /home/zongbao/projects/financial-tracker

# 1 — lint
docker compose exec -T app php -l /var/www/html/views/dashboard.php

# 2 — the screen requires a session
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8088/dashboard      # 302 -> /login

# 3 — sign in and keep the cookie
TOKEN=$(curl -s -c /tmp/ck.txt http://localhost:8088/login \
        | grep -oP 'name="csrf_token" value="\K[a-f0-9]+' | head -1)
curl -s -b /tmp/ck.txt -c /tmp/ck.txt -o /dev/null \
     -d "csrf_token=$TOKEN&username=demo&password=demo_password" \
     http://localhost:8088/login

# 4 — the dashboard renders, and the default period is the month
curl -s -b /tmp/ck.txt http://localhost:8088/dashboard | grep -o 'aria-current="true">This month'
curl -s -b /tmp/ck.txt http://localhost:8088/dashboard | grep -o 'page__title">[^<]*'

# 5 — the three tiles, in order
curl -s -b /tmp/ck.txt http://localhost:8088/dashboard | grep -o 'tile__label">[^<]*'

# 6 — the sign glyphs: U+2212 for spending, U+002B for income
curl -s -b /tmp/ck.txt http://localhost:8088/dashboard | grep -c $'\xe2\x88\x92'   # >= 1
curl -s -b /tmp/ck.txt http://localhost:8088/dashboard | grep -o '−[0-9,.]*' | head -3

# 7 — the net tile carries a modifier
curl -s -b /tmp/ck.txt http://localhost:8088/dashboard | grep -o 'tile--net-[a-z]*'

# 8 — the recent list is capped at ten
curl -s -b /tmp/ck.txt http://localhost:8088/dashboard | grep -c 'class="tx"'

# 9 — the ownership filter is in the SQL, not in PHP
grep -n 'user_id' views/dashboard.php

# 10 — no float arithmetic in the money path
grep -nE '\(float\)|[0-9]\s*[-+*]\s*[0-9]' views/dashboard.php | grep -v '//' || echo "no-inline-float-math: ok"

# 11 — the whole suite
.github/skills/dev-stack-ops/scripts/run-tests.sh
echo "suite_exit=$?"
```

Expected: step 2 prints **302**; step 4 prints the month link and the title; step 5
prints Income, Spending, Net; step 6 prints at least one `−`; step 7 prints exactly
one `tile--net-*`; step 8 prints at most **10**; step 10 prints
`no-inline-float-math: ok`; step 11 reports **0 FAIL**.

> Steps 4–8 need a signed-in session. Create the `demo` user through the register
> screen first if it does not exist.

---

## 13. Rollback

The dashboard is one view file. Removing it removes the screen and nothing else:

````bash
rm -f views/dashboard.php
````

`GET /` then needs a new destination; the simplest is a 302 to `/transactions`.

No schema change is involved, so there is **no data rollback**.

---

## 14. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The net is computed in PHP and drifts by a cent | Medium | §10 — the net is a `SUM(CASE …)` in SQL; §10's sign rule keeps the comparison out of PHP too. |
| A failed query renders as `0.00` | Medium | §9 — the tiles are not rendered on failure; step 7 of the verification checks the modifier set. |
| The "no transactions yet" and "nothing this period" states are conflated | Medium | §8 — the two are distinguished by a separate `LIMIT 1` query. |
| One user's data leaks into another's dashboard | Low | §10 — `user_id = :uid` in every statement, taken from the session. |
| The week starts on Monday even when `WEEK_START` says otherwise | Low | §5 — `resolvePeriodRange()` is the only source of the range. |
| The negative sign is an ASCII hyphen | Medium | §6 — U+2212 is specified; the verification greps for the UTF-8 bytes. |
| `SUM()` over an empty period returns `NULL` and renders as an empty string | Medium | §10 — `COALESCE(…, 0)`. |
| The recent list is not capped at ten | Low | §7 — `LIMIT 10` in the query; step 8 counts the rows. |
| The period label is computed from the period name instead of the range | Low | §5 — a custom range spanning two months must not read "October". |
| The comparison `bccomp()` is unavailable in the image | Medium | §10 — the query can return the sign; check with `php -m | grep bcmath`. |
| The dashboard is rebuilt as a client-rendered screen | Low | §9 — the loading skeleton is a mockup artefact; the app is server-rendered. |

---

## 15. Out of scope

- **A chart.** The mockup has no chart; the tiles and the table are the product.
- **A comparison against the previous period.** The tiles show one period.
- **Budgets, targets or alerts.**
- **A configurable set of favourite categories.**
- **Recurring transactions.**
- **Exporting the dashboard.**
- **The transactions, reports, categories and settings screens** — issues
  [018](issue-018.md) – [020](issue-020.md).
- **A client-side loading state.** The dashboard is server-rendered.
