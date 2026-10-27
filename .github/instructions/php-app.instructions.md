---
description: "Use when writing or editing PHP application code in this repo — index.php, install.php, includes/, or any page. Covers the install/config contract, the config.php-only configuration rule, prepared statements, escaping, CSRF, and the PHP tokenizer traps that have caused outages."
applyTo: ["*.php", "includes/**/*.php", "assets/**/*.php"]
---

# PHP application conventions

## Runtime and configuration

**PHP 8.5.12 is the floor.** The OpenLiteSpeed dev image ships PHP 8.5.12 (`lsphp85`).
This is a **patch** floor, not a language floor: PHP2Shell (`CVE-2026-109618`,
`CVE-2026-109619`) is an unauthenticated RCE affecting every PHP 5.4 – 8.5 build, and
8.5.12 is the patched release. Do not lower the floor and do not use 8.5-only syntax just
because it is available — the application is plain PHP with no framework.

**Third-party libraries are vendored at vendor time, never at runtime.** `composer.json`
and `composer.lock` are tracked; `vendor/` is git-ignored and produced by a deliberate
host-side `composer install --no-dev --optimize-autoloader`. The runtime image has no
`composer` and no `git`, and **nothing** may run `composer install` at container start-up,
in the installer, or in a request handler. See `docs/issues/issue-010.md`.

**Docker is development-only.** The app must install and run with Docker entirely out of
the picture (production is plain Litespeed + PHP + MariaDB). Consequences:

- **Configuration comes from `config.php` and nothing else.** No `getenv()`, no `$_ENV`,
  no `.env` parsing in application code. A Docker entrypoint may translate environment
  variables into `config.php` before the app starts, but that file lives in `docker/`.
- **Never add a code path that only works under Docker.**
- If a feature needs a setting, add a `config.php` constant — and add it to
  `config.template.php` in the same change. The two must declare the **same constant set**.
  `database/config_test.php` enforces this parity; run it after touching either file.
- `config.template.php` and `database/config_test.php` already exist and are the reference
  implementation of this contract. Read them before changing it.

## The install gate

`config.php` existing means "installed". `index.php` checks for it and sends the visitor
to the installer when it is missing.

- `install.php` writes `config.php` with `var_export()` (produces valid PHP literal syntax)
  — never `addslashes()`.
- Derive any absolute URL from server-controlled values (`$_SERVER['SERVER_NAME']`),
  **never** `$_SERVER['HTTP_HOST']` (user-controllable).
- CSRF-token every mutating POST in the wizard; the session must start before the first
  token is issued so it survives the step redirects.
- The installer must be deleted or locked after a successful install.
- A second, DB-backed probe ("does the `users` table exist?") is **unreachable** in the
  wizard — it does not load `config.php`, so the `DB_*` constants are undefined and any
  such probe is dead code. `config.php` existing is the only real gate.

## The `ALLOW_REGISTRATION` flag

`config.php` carries `ALLOW_REGISTRATION`, default `true`.

- The value is a **real PHP boolean** — `true` / `false`. Never the strings `'true'` /
  `'false'`. A string/boolean mix-up fails **open**: `=== 'false'` never matches a
  boolean, so registration stays open when the operator meant to close it.
- The constant is spelled with **underscores**: `ALLOW_REGISTRATION`. A hyphen is not
  legal in a PHP constant name.
- Declare it with `const`, not `define()`:

````php
const ALLOW_REGISTRATION = true;   // in config.php and config.template.php
````

- Compare with a boolean: `if (ALLOW_REGISTRATION === false) { … }` or the simpler
  `if (! ALLOW_REGISTRATION) { … }`. Both are correct for a real boolean.
- When it is `false`, registration is closed **on both sides**:
  - **Front end** — the register page does not render a form and returns 403.
  - **Back end** — the POST handler rejects the request, regardless of what was rendered.
- A check in only one of the two places is a bug. Write both.

````php
// includes/auth.php — one helper, called by both sides
function registrationAllowed(): bool
{
    return ALLOW_REGISTRATION === true;
}
````

`config.php` is included before any handler runs, so the constant is always defined. A
missing constant is a fatal `Error` in PHP 8, not a silent default — that is the
fail-closed behaviour we want.

## Page skeleton

````php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();                      // FIRST — before any DB work

$db = Database::getInstance();
$user_id = getCurrentUserId();
?>

<?php include __DIR__ . '/includes/header.php'; ?>
...
<?php include __DIR__ . '/includes/footer.php'; ?>
````

Set `$title` before including the header. `startSession()` must be the **first** call in a
page — it opens `ob_start()`, because `output_buffering=0` in this stack. Any byte emitted
before it breaks `header()` and silently drops the security headers.

## Queries

- Prepared statements with `?` placeholders, always.
- The only interpolation into SQL is a hard-coded table allow-list.
- Ownership is enforced **in the SQL**, by filtering on the owning user id — not by
  fetching first and checking afterwards.

````php
$where = ['t.user_id = ?'];
$params = [$user_id];
$sql = "SELECT t.* FROM transactions t WHERE " . implode(' AND ', $where) . " ORDER BY t.occurred_on DESC";
$rows = $db->fetchAll($sql, $params);
````

- `Database::execute()` returns **bool**, not a row count. Use `lastInsertId()` after it.
- `EMULATE_PREPARES = false` → native prepares → **one statement per call**.
- **Money is never a float.** Amounts are `DECIMAL(14,2)`. Compute sums in SQL, read them
  back as strings, format with `number_format()`.

## Output escaping and CSRF

- `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) on **every** dynamic value in HTML.
- Validate the CSRF token **before** reading any input, and put the hidden input in
  **every** form:

````php
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your session could not be verified. Please reload and submit again.';
} else {
    // read + validate into $errors[]
}
if (empty($errors)) {
    // persist, then redirect
}
````

````html
<input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
````

- Validation errors are plain strings in `$errors`, rendered as one alert block. On
  failure the form re-renders with the submitted values.

## Dates and periods

- Store transaction dates as `DATE` in UTC (`occurred_on`), with `created_at` as the
  audit timestamp.
- Day / week / month / year grouping and summing belong **in the SQL**, not in a PHP loop
  over rows.
- The week-start rule lives in exactly one place (`WEEK_START` config constant, Monday by
  default) so the reports and the UI always agree.
- Avoid `DateTime::createFromFormat()` for validation — it returns a `DateTime` with
  warnings (not `false`) for impossible dates like `2026-02-31`, so `if (!$date)` never
  fires. Validate with explicit range checks instead.
- **Render dates with `gmdate()`, never `date()`.** The application stores and renders
  in UTC (`SET time_zone = '+00:00'` on the connection). `date()` renders in the
  *server's* default timezone, which shifts the calendar day of a `DATE` column when
  the server is not on UTC — `2026-10-19` becomes `20 October 2026` east of
  Greenwich. `formatDate()` in `includes/functions.php` already uses `gmdate()`.

## Style

- 4-space indent, `function foo() {`, `} else {`, `} elseif (...) {`.
- A long docblock at the top of every file describing its contract.
- `//` comments explain **why** and cite the relevant brief section.
- `unset($x)` after any `foreach ($x as &$x)` reference loop.

## PHP tokenizer traps

- A literal `?>` inside a `//` comment **still closes PHP mode** — the rest of the file
  becomes raw HTML.
- A literal `<?php` inside a `//` comment **still opens a script block**.
- A `/* … */` comment containing `*/` terminates early → `php -l` reports `Unmatched '}'`.
- Write "PHP close tag" / "PHP open tag" in prose instead. **Always `php -l` every changed
  file.**
