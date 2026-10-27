# Issue-012 — App: the core runtime

> **Status:** **specified, not implemented** — `includes/Database.php`,
> `includes/functions.php`, `includes/auth.php` and `includes/Router.php` do not exist yet.
> **Target:** v0.2 (the application) — the layer every screen is built on.
> **Severity:** **high** — a bug in the escaping helper or the CSRF helper is a
> security bug in every screen at once.
>
> **Requested by the user:**
> > "I guess, we now have everything, ready for dev, write issue files for dev to
> > implement the project."
>
> **Depends on:** [issue-011.md](issue-011.md) (the schema these helpers query).
> **Related:** [issue-014.md](issue-014.md) (the front controller that loads them),
> [issue-013.md](issue-013.md) (the asset helper and the shell),
> [issue-016.md](issue-016.md) (the auth flows that use `includes/auth.php`);
> `.github/instructions/php-app.instructions.md` (**the binding conventions**);
> `.github/instructions/database.instructions.md` (the `Database` API);
> `includes/bootstrap.php` (the existing vendor bootstrap — **do not rewrite it**);
> `includes/mailer.php` (the existing SMTP wrapper — **do not rewrite it**).

---

## Table of contents

1. Objective
2. Constraints
3. The file layout
4. Specification — `includes/Database.php`
5. Specification — `includes/functions.php`, part 1: output and formatting
6. Specification — `includes/functions.php`, part 2: CSRF
7. Specification — `includes/functions.php`, part 3: dates and periods
8. Specification — `includes/functions.php`, part 4: request helpers
9. Specification — `includes/auth.php`
10. Specification — `includes/Router.php`
11. Acceptance criteria
12. Verification
13. Rollback
14. Risks
15. Out of scope

---

## 1. Objective

Build the **shared runtime**: the database wrapper, the escaping and formatting
helpers, the CSRF helper, the date/period helpers, the session and authorisation
helpers, and the router.

Success criteria:

| # | Criterion |
|---|---|
| 1 | Every screen can be written without repeating a single SQL, escaping, or session idiom |
| 2 | `e()` is applied to **every** dynamic value, and there is exactly one implementation of it |
| 3 | CSRF is validated **before** any input is read, and every form carries the token |
| 4 | Money is formatted from a **string** returned by SQL — never from a PHP float |
| 5 | The week-start rule lives in **one** place, driven by the `WEEK_START` constant |
| 6 | Ownership is enforced in SQL by a **filter helper**, not by a check after the fetch |
| 7 | The router maps a method + path to a handler, and nothing else |
| 8 | The whole layer is covered by a **DB-backed regression test** and by structural assertions |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| **No framework, no ORM, no autoloader of our own** | Plain `require_once` of four files, in a fixed order. |
| **No build step, no bundler** | The helper files ship as they are. |
| **Plain PHP 8.5.12** — but plain for 8.2 | Do not use 8.5-only syntax. The floor is a *patch* floor, not a language floor. |
| `output_buffering = 0` in this stack | `startSession()` must be the **first** call in a request, because it opens `ob_start()`. |
| `display_errors = Off` | Errors go to the LiteSpeed error log, never to the page. |
| `EMULATE_PREPARES = false` | Native prepares — **one statement per call**. |
| `Database::execute()` returns `bool` | Use `lastInsertId()` afterwards. Never treat the return value as a row count. |
| `config.php` is the **only** configuration source | No `getenv()`, no `$_ENV`, no `$_SERVER`-based configuration, no `.env` parsing. |
| The runtime never mutates itself | No `composer install`, no self-repair, no schema writes from a request handler. |

---

## 3. The file layout

````text
includes/
  bootstrap.php     # EXISTS — the Composer autoloader loader. Do not rewrite.
  mailer.php        # EXISTS — the SMTP wrapper. Do not rewrite.
  Database.php      # NEW  — the PDO singleton.
  functions.php     # NEW  — escaping, money, dates, CSRF, request helpers.
  auth.php          # NEW  — session, current user, authorisation.
  Router.php        # NEW  — method + path → handler.
  header.php        # NEW  — the shared <head> + app bar.        (issue-013)
  footer.php        # NEW  — the shared footer.                  (issue-013)
  views/
    …               # NEW  — the page templates                 (issues 016–020)
````

The load order in `index.php` is fixed:

````php
require_once __DIR__ . '/config.php';       // 1. the constants
require_once __DIR__ . '/includes/bootstrap.php';  // 2. the autoloader
require_once __DIR__ . '/includes/Database.php';   // 3.
require_once __DIR__ . '/includes/functions.php';  // 4.
require_once __DIR__ . '/includes/auth.php';       // 5.
require_once __DIR__ . '/includes/Router.php';     // 6.
````

`config.php` comes **first** — `ALLOW_REGISTRATION`, `WEEK_START` and `TIMEZONE` are
read at call time, and a missing constant is a fatal `Error` in PHP 8 (the
fail-closed behaviour we want).

---

## 4. Specification — `includes/Database.php`

A **singleton** over one PDO handle. One connection per request, lazily opened.

### The API

| Method | Returns | Notes |
|---|---|---|
| `Database::getInstance(): self` | the singleton | Lazy-connects on first use. |
| `query(string $sql, array $params = []): PDOStatement` | the statement | Prepared, `?` placeholders. |
| `fetchAll(string $sql, array $params = []): array` | an array of assoc rows | `[]` when there are none. |
| `fetchOne(string $sql, array $params = []): array\|false` | one assoc row or `false` | `false` when there is none — **not** `null`, so `if (!$row)` works. |
| `execute(string $sql, array $params = []): bool` | **`bool`** | **Not a row count.** |
| `lastInsertId(): int` | the id | Call it **after** `execute()`. |
| `getConnection(): PDO` | the raw handle | Only for the rare multi-statement case. |

### The connection

````php
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]
);
$pdo->exec("SET time_zone = '+00:00'");
````

| Setting | Value | Why |
|---|---|---|
| `ERRMODE` | `EXCEPTION` | A failed query throws; it is never a silent `false`. |
| `DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | Rows are arrays, not objects. |
| `EMULATE_PREPARES` | **`false`** | Real prepared statements — the only defence against SQL injection that does not depend on remembering to escape. |
| `STRINGIFY_FETCHES` | `false` | Keeps `DECIMAL` values as **strings**, so money is never silently converted to a float. |
| `time_zone` | `'+00:00'` | The DB stores UTC; the PHP layer converts for display. |

### The rules

1. **`execute()` returns `bool`.** The correct idiom is:

   ````php
   $db->execute($sql, $params);
   $id = (int)$db->lastInsertId();
   ````

   and **never** `$id = (int)$db->execute($sql, $params);` — that is always `1`.

2. **Never interpolate a value into SQL.** The only interpolation is a hard-coded
   **table allow-list** for the one query that needs a dynamic table name.

3. **One statement per call.** `EMULATE_PREPARES = false` means a multi-statement
   string fails silently or errors. Split it.

4. **A failed query throws.** The caller decides what the visitor sees; the front
   controller catches it and renders the generic 500 page
   ([issue-014.md](issue-014.md)). The exception message never reaches the browser.

5. **`getInstance()` is the only constructor path.** No `new Database()` from outside.

---

## 5. Specification — `includes/functions.php`, part 1: output and formatting

### `e()` — the escaping helper

````php
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
````

| Rule | Reason |
|---|---|
| `ENT_QUOTES` | Escapes `'` as well as `"`. The default (`ENT_COMPAT`) leaves single quotes live inside a single-quoted attribute. |
| `ENT_SUBSTITUTE` | An invalid UTF-8 byte becomes U+FFFD instead of an **empty string** — the silent-truncation trap. |
| `'UTF-8'` | Explicit. The default charset is whatever `default_charset` says, and it must not be an implicit dependency. |
| The cast `(string)` | `null` renders as `''`, not as a PHP warning. |

`e()` is called on **every** dynamic value in HTML — a category name, a description,
a date, a query-string value, a user name, a page title. There is no exception to
this rule and no "safe" subset of fields.

### Money formatting

````php
function formatMoney(string $amount): string
{
    return number_format((float) $amount, 2, '.', ',');
}
````

| Rule | Reason |
|---|---|
| The **parameter type is `string`** | The value comes from a `DECIMAL(14,2)` column, and `ATTR_STRINGIFY_FETCHES = false` keeps it a string. The cast to `float` happens **once, here, at the edge** — for display only. |
| Never arithmetic | The cast is for `number_format()`. A sum is computed in SQL and arrives here already summed. |
| `number_format($x, 2, '.', ',')` | Two decimals, dot decimal separator, comma thousands separator. Explicit separators — do not rely on the locale. |
| The negative sign | `number_format()` gives `-1,234.50`. The mockup's negative net tile wants the sign visible — keep it. |

> **The trap this helper exists to prevent:** `0.1 + 0.2 !== 0.3`. If a total is
> computed in PHP by adding up row amounts, the result drifts. The total is summed in
> SQL (`SUM(amount)`) and this helper only formats it.

### A helper for the "no value" case

The mockups use an **em dash** for a category with no transactions
([09-categories.html](../mockup/09-categories.html)). Provide it explicitly rather
than letting each screen invent one:

````php
function formatCount(int $count): string
{
    return $count === 0 ? '—' : (string) $count;
}
````

### `asset_url()`

````php
function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = __DIR__ . '/../' . $path;
    $version = is_file($file) ? substr((string) filemtime($file), -6) : '0';
    return '/assets/' . $path . '?v=' . $version;
}
````

- **Root-absolute** paths (`/assets/css/…`) — the app lives at the document root.
- The `?v=` stamp is the file's `mtime`, so the browser cache busts when the file
  changes. It is **not** a build step and there is no manifest to maintain.
- The path must be a **hard-coded literal** at every call site. Never pass a
  user-supplied value into it.

---

## 6. Specification — `includes/functions.php`, part 2: CSRF

### The token

````php
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        ob_start();          // output_buffering = 0 in this stack
    }
}
````

`startSession()` is the **first call in every request**. It opens `ob_start()`
because `output_buffering=0` in the OLS image — any byte emitted before it breaks
`header()` and silently drops the security headers.

````php
function getCsrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool
{
    startSession();
    if (empty($_SESSION['csrf_token']) || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
````

| Rule | Reason |
|---|---|
| `random_bytes(32)` → 64 hex characters | Cryptographically random. `uniqid()` and `mt_rand()` are not. |
| `hash_equals()` | Constant-time comparison — no timing leak. `===` on a secret is a leak. |
| The token is stored **in the session** | A per-session token is enough for a personal app. |
| The token is **rotated on privilege change** | Regenerate it after sign-in, sign-out, and password change. `session_regenerate_id(true)` on sign-in. |

### The contract every form obeys

````php
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your session could not be verified. Please reload and submit again.';
} else {
    // read and validate the fields into $errors[]
}
if (empty($errors)) {
    // persist, then redirect
}
````

````html
<input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
````

Two non-negotiable rules:

1. **Validate the token before reading any input.** A rejected token must not have
   touched the payload.
2. **The hidden input is in every form**, including the ones the mockup draws as
   plain buttons.

---

## 7. Specification — `includes/functions.php`, part 3: dates and periods

This is the part that must live in **one** place, because the dashboard, the
transaction filters and the reports all have to agree about what "this week" means.

### The period vocabulary

| Period | Query parameter | Date range (UTC) |
|---|---|---|
| Day | `today` | the current date |
| Week | `week` | the current week, from `WEEK_START` |
| Month | `month` | the first to the last day of the current month |
| Year | `year` | 1 January to 31 December of the current year |
| Custom | `custom` | `from` … `to`, given by the visitor |

The dashboard's period selector uses `?period=today|week|month|year|custom`
([05-dashboard.html](../mockup/05-dashboard.html)); the reports screen uses
`?period=day|week|month|year|custom`
([08-reports.html](../mockup/08-reports.html)). **Accept both spellings** — `today`
and `day` are the same period. Normalise in one place:

````php
function normalizePeriod(?string $period): string
{
    $period = strtolower(trim((string) $period));
    if ($period === 'today') {
        $period = 'day';
    }
    return in_array($period, ['day', 'week', 'month', 'year', 'custom'], true)
        ? $period
        : 'month';
}
````

The **default is `month`** — the dashboard mockup opens on the month.

### The one place the week-start rule lives

````php
function weekStartDay(): int
{
    return defined('WEEK_START') ? (int) WEEK_START : 1;   // 1 = Monday
}
````

`WEEK_START` is a `config.php` constant. **Nothing else may hard-code a week start.**
`WEEK_START = 1` means Monday; `0` means Sunday.

### The range resolver

````php
/**
 * Resolve a period into an inclusive [from, to] UTC date range.
 *
 * @return array{0: string, 1: string}  YYYY-MM-DD, YYYY-MM-DD
 */
function resolvePeriodRange(string $period, ?string $from = null, ?string $to = null): array
{
    // …day / week / month / year from the current UTC date…
    // …custom from the two supplied dates, validated…
}
````

| Rule | Reason |
|---|---|
| The range is computed in **UTC** | The DB stores UTC; a local-timezone conversion here would shift transactions across the day boundary. |
| The range is **inclusive on both ends** | The SQL is `occurred_on BETWEEN ? AND ?`. |
| `custom` with `from` **after** `to` is an **error**, not a swap | The mockup has a dedicated message for it: *"The start date must be on or before the end date."* |
| An out-of-range or impossible date is **rejected** | See the date-validation rule below. |

### Date validation — never `createFromFormat()`

`DateTime::createFromFormat('Y-m-d', '2026-02-31')` returns a `DateTime` **with
warnings**, not `false`. So `if (!$date) { … }` never fires and the impossible date
sails through. Validate with explicit checks instead:

````php
function isValidDateString(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}
````

`checkdate()` rejects `2026-02-31` and `2026-13-01` exactly. The transaction form
must reject `2026-02-31` — the mockup says so in as many words
([07-transaction-form.html](../mockup/07-transaction-form.html)).

### Formatting for display

````php
function formatDate(?string $isoDate): string
{
    if ($isoDate === null || $isoDate === '') {
        return '';
    }
    $ts = strtotime($isoDate . ' UTC');
    return $ts === false ? '' : date('j M Y', $ts);
}
````

`19 October 2026` — the format the mockups use for the tile period labels, the table
dates and the report week headings.

---

## 8. Specification — `includes/functions.php`, part 4: request helpers

### Reading the input

````php
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}
````

`trim()` is applied on the way in; anything else (length, format, range) is validated
by the caller, per field.

### The ownership filter — the authorisation primitive

````php
/**
 * Build the WHERE clause and the parameters for a user-scoped query.
 *
 * Ownership is enforced IN THE SQL. Never fetch first and check afterwards —
 * that is a TOCTOU bug waiting for a second account.
 */
function userScope(int $userId): array
{
    return ['user_id = ?', [$userId]];
}
````

Every transaction and category query composes this fragment. The table alias is
passed in when the query joins:

````php
// $where = ['t.user_id = ?'];  $params = [$user_id];
````

The rule is simple and absolute: **every** statement that touches `transactions` or
`categories` carries the owning `user_id` in its `WHERE` clause — reads, sums,
updates and deletes alike.

### Validation helpers

| Helper | Signature | Purpose |
|---|---|---|
| `isValidUsername()` | `(string $u): bool` | 3–32 chars, `[A-Za-z0-9_-]`. |
| `isValidEmail()` | `(string $e): bool` | `filter_var($e, FILTER_VALIDATE_EMAIL)` plus a length cap of 254. |
| `isValidAmount()` | `(string $a): bool` | Matches `/^\d{1,12}(\.\d{1,2})?$/` — at most two decimals, no thousands separator, no sign. |
| `isValidType()` | `(string $t): bool` | `in_array($t, ['income','expense'], true)`. |
| `isValidCategoryName()` | `(string $n): bool` | 1–60 characters after trimming. |

`isValidAmount()` is deliberately a **string** check, not a `is_numeric()` check.
`is_numeric('1e5')` is `true`; `is_numeric(' 12 ')` is `true`; `is_numeric('1,234.50')`
is `false`. The regex is the only thing that accepts exactly what the form's
`step="0.01"` can produce, and it accepts **no exponent**, which is what keeps the
value safe to hand to `DECIMAL(14,2)`.

### Redirecting

````php
function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}
````

- The path is always a **hard-coded literal** or a path built from validated parts.
  Never a raw `$_SERVER['REQUEST_URI']`.
- **Redirect after every successful POST** (the Post/Redirect/Get pattern). It is
  what makes the browser refresh button safe.

---

## 9. Specification — `includes/auth.php`

### The session

````php
function startSession(): void          // defined in functions.php, re-exported
function isLoggedIn(): bool
function getCurrentUserId(): ?int
function getCurrentUser(): ?array
function loginUser(int $userId): void
function logoutUser(): void
````

| Function | Behaviour |
|---|---|
| `loginUser()` | Sets `$_SESSION['user_id']`, calls `session_regenerate_id(true)`, and rotates the CSRF token. |
| `logoutUser()` | Clears `$_SESSION`, regenerates the ID, and destroys the session cookie. |
| `getCurrentUserId()` | `int|null` — the session value cast to `int`, or `null` when there is no session. |

### The authorisation guard

````php
function requireLogin(): void
{
    if (!isLoggedIn()) {
        // Remember where the visitor was going, so the sign-in page can send
        // them back — but only for a GET, and only a path we control.
        $target = safeRedirectTarget($_SERVER['REQUEST_URI'] ?? '/');
        $_SESSION['intended_url'] = $target;
        redirect('/login');
    }
}
````

`requireLogin()` is the **first** thing a protected handler does — before any DB
work, before any output. `safeRedirectTarget()` accepts only a **path** (starts with
`/`, does not start with `//`, contains no scheme) so the redirect cannot be turned
into an open redirect.

### The registration gate — one helper, called by both sides

````php
function registrationAllowed(): bool
{
    return ALLOW_REGISTRATION === true;
}
````

This is the rule that has caused outages in the sibling project: when
`ALLOW_REGISTRATION` is `false`, registration must be closed on the **front end**
(the register page renders no form and returns 403) **and** on the **back end** (the
POST handler rejects the request, whatever was rendered). **A check in only one of the
two places is a bug. Write both.**

### The login throttle

| Property | Value |
|---|---|
| The limit | 5 failed attempts |
| The lock | 15 minutes |
| The counter | `users.failed_attempts` + `users.locked_until` (see [issue-011.md](issue-011.md) §7) |
| The message | *"Too many attempts. Try again in 15 minutes."* |

The **server** enforces the limit and publishes the wait. The client never counts
attempts itself — the mockup says exactly that
([01-login.html](../mockup/01-login.html)). A successful sign-in resets the counter.

### The password helper

````php
function hashPassword(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}
````

`password_hash()` with `PASSWORD_DEFAULT` — never `md5()`, never `sha1()`, never a
home-grown salt. The cost factor is the default's, and it can be raised later
without a schema change because the hash carries its own parameters.

---

## 10. Specification — `includes/Router.php`

A **method + path → handler** map. That is the entire job.

````php
$router = new Router();
$router->get('/dashboard',        'views/dashboard.php');
$router->get('/transactions',      'views/transactions.php');
$router->post('/transactions',     'views/transactions.php');
$router->get('/transactions/new',  'views/transaction_form.php');
$router->post('/transactions/save','views/transaction_save.php');
$router->dispatch();
````

### The rules

1. **Exact path matching.** No wildcards, no regex routes, no `:id` placeholders in
   v0.2. A literal map of about twenty routes is easier to read than a router.
2. **The method is part of the match.** `GET /transactions` and `POST /transactions`
   are different handlers.
3. **`HEAD` is treated as `GET`.**
4. **An unknown path is a 404** — a plain page with the app shell, not a stack trace.
5. **A known path with the wrong method is a 405**, with an `Allow:` header.
6. The handler is **included**, not `eval`ed, and the path comes from the route table
   — never from the request.

### The route table

| Method | Path | Handler | Auth |
|---|---|---|---|
| `GET` | `/login` | `views/login.php` | guest |
| `POST` | `/login` | `views/login.php` | guest |
| `GET` | `/register` | `views/register.php` | guest |
| `POST` | `/register` | `views/register.php` | guest |
| `GET` | `/forgot-password` | `views/forgot_password.php` | guest |
| `POST` | `/forgot-password` | `views/forgot_password.php` | guest |
| `GET` | `/reset-password` | `views/reset_password.php` | guest |
| `POST` | `/reset-password` | `views/reset_password.php` | guest |
| `GET` | `/` | → `302 /dashboard` | — |
| `GET` | `/dashboard` | `views/dashboard.php` | required |
| `GET` | `/transactions` | `views/transactions.php` | required |
| `GET` | `/transactions/new` | `views/transaction_form.php` | required |
| `GET` | `/transactions/{id}/edit` | `views/transaction_form.php` | required |
| `POST` | `/transactions/save` | `views/transaction_save.php` | required |
| `POST` | `/transactions/delete` | `views/transaction_delete.php` | required |
| `GET` | `/reports` | `views/reports.php` | required |
| `GET` | `/categories` | `views/categories.php` | required |
| `POST` | `/categories/save` | `views/category_save.php` | required |
| `POST` | `/categories/delete` | `views/category_delete.php` | required |
| `GET` | `/settings` | `views/settings.php` | required |
| `POST` | `/settings/profile` | `views/settings.php` | required |
| `POST` | `/settings/password` | `views/settings.php` | required |
| `POST` | `/logout` | `views/logout.php` | required |

The `{id}` in `/transactions/{id}/edit` is a **literal segment handled by the
handler**, not a router feature: the handler reads the id from `$_GET['id']`,
validates it as an integer, and the ownership check happens in the SQL.

> A **tab-separated** file, a **REST** API, and **JSON responses** are out of scope.
> This is a server-rendered HTML application.

---

## 11. Acceptance criteria

- [ ] `includes/Database.php` exists with the six-method API in §4 and a lazy
      singleton.
- [ ] The PDO handle sets `ERRMODE_EXCEPTION`, `FETCH_ASSOC`,
      `EMULATE_PREPARES = false`, `ATTR_STRINGIFY_FETCHES = false` and
      `SET time_zone = '+00:00'`.
- [ ] `execute()` returns **bool** and `lastInsertId()` is used for the id.
- [ ] No value is interpolated into SQL anywhere in `includes/`.
- [ ] `e()` uses `ENT_QUOTES | ENT_SUBSTITUTE` and `'UTF-8'`.
- [ ] `formatMoney()` takes a **string** and returns `number_format(…, 2, '.', ',')`.
- [ ] `getCsrfToken()` uses `random_bytes(32)`; `validateCsrfToken()` uses
      `hash_equals()`.
- [ ] `startSession()` is the first call in every request and opens `ob_start()`.
- [ ] `isValidDateString()` rejects `2026-02-31` and `2026-13-01`.
- [ ] `isValidAmount()` rejects `1e5`, `1,234.50` and `-5.00`, and accepts
      `1234.56`.
- [ ] `resolvePeriodRange()` is the **only** place a period becomes a date range, and
      it reads the week start from `WEEK_START`.
- [ ] `normalizePeriod()` maps `today` → `day` and defaults to `month`.
- [ ] `requireLogin()` runs before any DB work in every protected handler.
- [ ] `registrationAllowed()` exists as **one** helper and is called by both the
      front-end and the back-end registration checks.
- [ ] `Router` matches on method **and** path; an unknown path is 404 and a wrong
      method is 405 with an `Allow:` header.
- [ ] A regression test `database/runtime_test.php` asserts the pure helpers
      (`e()`, `formatMoney()`, `isValidAmount()`, `isValidDateString()`,
      `normalizePeriod()`, `resolvePeriodRange()`, `validateCsrfToken()`).
- [ ] The test reports success by **exit code 0**, and **skips** (exit 0 with a
      notice) when the database is unreachable — never a false green.
- [ ] Every changed file passes `php -l`.

---

## 12. Verification

```bash
cd /home/zongbao/projects/financial-tracker

# 1 — the files exist
for f in includes/Database.php includes/functions.php includes/auth.php includes/Router.php; do
  test -f "$f" && echo "ok: $f" || echo "MISSING: $f"
done

# 2 — lint every changed PHP file (absolute path — the exec cwd is /var/www/vhosts)
docker compose exec -T app php -l /var/www/html/includes/Database.php
docker compose exec -T app php -l /var/www/html/includes/functions.php
docker compose exec -T app php -l /var/www/html/includes/auth.php
docker compose exec -T app php -l /var/www/html/includes/Router.php

# 3 — the hard security settings are present
grep -q 'EMULATE_PREPARES' includes/Database.php && grep -q 'false' includes/Database.php \
  && echo "emulate-prepares: present"
grep -q 'ENT_QUOTES' includes/functions.php && echo "ent-quotes: present"
grep -q 'hash_equals' includes/functions.php && echo "hash-equals: present"
grep -q 'random_bytes' includes/functions.php && echo "random-bytes: present"
grep -q "SET time_zone" includes/Database.php && echo "utc: present"

# 4 — no money arithmetic in PHP and no float columns
grep -rnE '\$[a-z_]*amount\s*\+|\+\s*\$[a-z_]*amount' includes/ && echo "MONEY ARITHMETIC FOUND" || echo "no-amount-arithmetic: ok"
grep -rn 'float' includes/ database/schema.sql | grep -v 'is_float' || echo "no-float-columns: ok"

# 5 — the regression test
docker compose exec -T app php /var/www/html/database/runtime_test.php
echo "exit=$?"

# 6 — the whole suite still passes
.github/skills/dev-stack-ops/scripts/run-tests.sh
echo "suite_exit=$?"
```

Expected: step 1 lists four `ok:` lines, step 2 prints `No syntax errors detected`
four times, step 3 prints five `ok` lines, step 4 prints `no-amount-arithmetic: ok`
and `no-float-columns: ok`, step 5 exits **0**, and step 6 reports **0 FAIL**.

---

## 13. Rollback

This layer has no persisted state of its own — it is code, not data.

````bash
rm -f includes/Database.php includes/functions.php includes/auth.php includes/Router.php
rm -f database/runtime_test.php
````

The schema and the data are untouched by a rollback of this layer. If a helper was
introduced in a later change, revert that change instead — the runtime is additive
and has no migration.

---

## 14. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| `e()` is forgotten on one field | **High** without a review rule | The acceptance criteria name the helper explicitly; a reviewer greps for `<?=` without `e(`. |
| `execute()`'s return value is used as a row count | Medium | Documented in §4 with the wrong and right code side by side. |
| `createFromFormat()` is used for date validation | Medium | §7 names the exact trap and the `checkdate()` replacement. |
| `$_SERVER['HTTP_HOST']` leaks into a URL | Medium | §8 restricts redirects to validated paths; `$_SERVER['SERVER_NAME']` is the only server-controlled host source. |
| A float creeps into a money path | Medium | `ATTR_STRINGIFY_FETCHES = false` keeps the value a string; `formatMoney()` takes a string. |
| The CSRF token is validated after the payload is read | Low | §6 states the order as a rule, not a preference. |
| The router grows a wildcard syntax | Low | §10 forbids it explicitly for v0.2. |
| The session is started late, dropping the security headers | Medium | `startSession()` is the first call; the front controller in [issue-014.md](issue-014.md) enforces it. |

---

## 15. Out of scope

- **The front controller, the route table wiring and the error pages** —
  [issue-014.md](issue-014.md).
- **The design-token layer, the header and the footer** — [issue-013.md](issue-013.md).
- **The mailer** — `includes/mailer.php` already exists and is not modified.
- **A JSON API, a REST layer, or a tab-separated route file.**
- **A database session handler** — the file handler is enough for a single-server
  personal install.
- **Rate limiting on the API level, or a Redis/Memcached layer.**
- **An ORM, a query builder, or a templating engine.**
