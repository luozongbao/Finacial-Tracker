---
description: "Use when writing, running, or debugging the regression tests in database/. Covers the plain-PHP harness convention, the fact that php is not on the host PATH, exit-code detection, DB seeding and cleanup, source-grep assertion pitfalls, and money/date assertions."
applyTo: ["**/*_test.php", "database/**"]
---

# Regression test conventions

There is **no PHPUnit, no composer script, and no CI**. Every test is a standalone PHP CLI
script that decides pass/fail by its own exit code.

## Running

`php` is **not on the host PATH** — a bare `php test.php` returns exit **127**
(command not found).

````bash
docker compose exec -T app php database/<test>_test.php
# or, with an explicit container name:
docker exec <app-container> php /var/www/html/database/<test>_test.php
````

The container is a **dev convenience**, not a requirement — the tests are plain PHP CLI
scripts and run identically against any **PHP 8.2+** binary with a reachable MariaDB 10.11
(the DB-backed ones read `config.php` for credentials). A non-Docker developer runs
`php database/<test>_test.php` locally.

## Detecting success

Success markers are **inconsistent** across the suite — some print `ALL ASSERTIONS PASSED`,
some prose, some nothing. **Detect by exit code 0.**

- Never treat "no output" as a pass.
- Never grep for the literal `Failed` — `Failed: 0` is an *informational* line.
- A missing interpreter must never read as a pass. A shell loop that only checks
  "output does not contain FAIL" reports a **false green** for every test when `php` is
  absent, because the error text never contains the word.

A reliable check:

````bash
out=$(docker compose exec -T app php "database/$t" 2>&1); code=$?
nf=$(echo "$out" | grep -oE '^Failed: [0-9]+' | tail -1 | grep -oE '[0-9]+')
[[ $code -eq 0 && "${nf:-0}" == "0" ]] && ! echo "$out" | grep -qiE 'ASSERTION\(S\) FAILED'
````

The helper [`.github/skills/dev-stack-ops/scripts/run-tests.sh`](../../skills/dev-stack-ops/scripts/run-tests.sh)
implements exactly this logic — prefer it over an ad-hoc loop.

## Harness shape

Follow the surrounding file's dialect. The common one:

````php
$failures = [];
function ok($label, $ok, $detail = '') {
    global $failures;
    if ($ok) { echo "  [PASS] $label\n"; }
    else { $failures[] = $label . ($detail !== '' ? " — $detail" : '');
           echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : "") . "\n"; }
}
````

Sections are numbered (`// ---- 1. …`) with `echo "## A. …"` headers, and the file ends with
a summary plus a non-zero `exit(1)` on failure.

**Bootstrap order matters:** DB-backed tests `require_once __DIR__ . '/../config.php'`
**first**, then `includes/Database.php`, then `includes/functions.php`. Structural-only
tests skip `config.php` and use `realpath(__DIR__ . '/..')` as the root.

## Seeding and cleanup

- Seed with a **random marker** (`bin2hex(random_bytes(8))`) so re-runs never collide with
  `UNIQUE` constraints.
- Seed a **dedicated user → category → transaction chain** so cleanup is targeted.
- `users` has `username` and `email` as `NOT NULL UNIQUE` — always populate both.
- Cleanup is **unconditional**: `try { … } finally { … }`, or `register_shutdown_function()`
  so a fatal error cannot leak seed rows.
- Cleanup failures print a warning to STDERR but must never mask assertion failures.
- FK cleanup order is `transactions → categories → users`.

## Money assertions

- Amounts are `DECIMAL(14,2)`. Assert against **strings** (`'12.50'`), not floats —
  `0.1 + 0.2 !== 0.3` makes float comparisons unreliable.
- Read a summed amount back as a string and compare the formatted output
  (`number_format($sum, 2)`) rather than the raw value when the test is about presentation.
- A `SUM()` over a `DECIMAL` column returns a decimal string; cast with
  `(float)` only for magnitude comparisons, never for equality.

## Date and period assertions

- Seed `occurred_on` as a literal `'YYYY-MM-DD'` string — never `date('Y-m-d')`, which
  makes the test depend on the day it runs.
- Period grouping is computed in SQL, so the test should assert on the SQL result
  (e.g. `DATE_FORMAT(occurred_on, '%Y-%m')`), not on a PHP-side re-implementation of the
  grouping rule — a test that duplicates the logic proves nothing.
- When asserting week boundaries, remember the week-start rule is a config constant
  (Monday by default). Set it explicitly in the test rather than inheriting the default.

## Source-grep assertions

- Strip comments before a negative assertion, or a comment like
  `// we used to call addslashes()` will match it.
- Use **single-quoted** PHP strings for any regex containing `$` (`$_POST`, `$_GET`,
  `$_SESSION`) — otherwise PHP interpolates before the regex engine sees it.
- A selector or literal can survive in a comment while the rule body is deleted — assert on
  the **rule body**, not just the selector string.
- A repo-wide grep is not a scoped assertion: when a check is about one function, capture
  that function's body first, then assert on the capture.

## Live-render proxies

To render a page from CLI:

````php
$proxy_file = __DIR__ . '/../_render_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($proxy_file, $proxy);
register_shutdown_function(static function () use ($proxy_file) {
    if (is_file($proxy_file)) { @unlink($proxy_file); }
});
$rendered = shell_exec('php ' . escapeshellarg($proxy_file) . ' 2>&1');
@unlink($proxy_file);
````

- The proxy file must be written **next to the page being rendered** and the target must be
  `realpath()`'d.
- In the proxy: do all session setup first, then `ob_start()`, then any debug output, then
  `require $target`, then `ob_get_clean()`. **Any echo before `ob_start()` makes
  `session_start()` fail silently**, the CSRF check then rejects everything, and the
  failure surfaces as a misleading unrelated error.
- `session.use_strict_mode` must be `0` before `session_id()`, and `session.use_cookies` `0`,
  or the CLI session never starts.
- A CLI session id may contain only `[A-Za-z0-9-,]` — use `bin2hex(random_bytes(16))`, not
  an underscore-bearing name.

## Prove a regression net is real

After adding a test, confirm it actually fails when the behaviour it guards is broken —
otherwise it is a green test that proves nothing.
