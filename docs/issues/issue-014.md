# Issue-014 — App: the front controller and the routing table

> **Status:** **implemented and verified** — `index.php`, the five error views and the
> `.htaccess` rewrite exist. The behaviour was measured against the running stack
> and against a sandbox on 2026-10-27, and the layer is guarded by
> `database/front_controller_test.php`.
> **Target:** v0.2 (the application) — the single entry point of the whole product.
> **Severity:** **high** — the install gate and the error pages are the two places
> where a mistake takes the whole application down.
>
> **Requested by the user:**
> > "I guess, we now have everything, ready for dev, write issue files for dev to
> > implement the project."
>
> **Depends on:** [issue-012.md](issue-012.md) (the runtime),
> [issue-013.md](issue-013.md) (the shell).
> **Related:** [issue-015.md](issue-015.md) (the installer the gate redirects to),
> [issue-003.md](issue-003.md) (the `.htaccess` deny block this issue must not break),
> `AGENTS.md` (Hard rules — errors are not on the page);
> `.github/instructions/php-app.instructions.md` (**the binding conventions**).

---

## Implementation notes — 2026-10-27

The specification contradicted itself in two places and left four questions open.
Each decision is recorded here and guarded by
`database/front_controller_test.php`, so a later change cannot quietly reintroduce
the shape this issue rejected.

| # | The spec said | The decision | Why |
|---|---|---|---|
| 1 | "It has **24 routes**" (§4, §8) | **23 routes.** | The spec's own table sums to 23 — 8 guest + 1 dashboard + 5 transactions + 1 reports + 3 categories + 4 settings + 1 root — the table in [issue-012.md](issue-012.md) §10 holds 23, and `Router::defaultRoutes()` (committed, and asserted by `database/runtime_test.php`) holds 23. The prose count is a miscount; the table is the source of truth. |
| 2 | The skeleton does `require_once __DIR__ . '/config.php';` **before** the gate (§4) | **The gate first, the require after it.** | On a fresh checkout there is no `config.php`, so the skeleton's order is a fatal "Failed opening required" — the visitor never reaches the installer. The spec's own §3 and its acceptance criteria put the gate first; the skeleton is a sketch. |
| 3 | The lifecycle requires `includes/bootstrap.php` first (§4, §7) | **`includes/functions.php` is loaded before the bootstrap.** | The bootstrap failure page renders through the shared shell, and the shell needs `e()` and `asset_url()`. Neither file has a side effect, so the order is free to choose. |
| 4 | "The route table is **literal** … in the controller" (§4) | **The table lives in `Router::defaultRoutes()`; the controller calls `Router::withDefaultRoutes()`.** | [issue-012.md](issue-012.md) §10 puts the table in one place and `database/runtime_test.php` asserts its 23 entries there. A second copy inside the controller would be two sources of truth for the same URLs. The table is still literal, and still one list — one file away. |
| 5 | The lifecycle ends with "include footer.php" **in the controller** (§7) | **The view owns its page: it includes `header.php` and `footer.php`.** | The one redirect route (`GET /` → 302) must answer with a body of exactly zero bytes. A controller that always closed the page would emit a stray footer after the redirect. The test asserts that the redirect carries no body. |
| 6 | The 404 is "the path is not in the route table" (§6) | **A missing handler file is a 500, not a 404.** | The router throws a `RuntimeException` for a missing handler, and that is a broken deployment, not a bad URL. The 404 keeps its one meaning: the URL is not in the table. |
| 7 | "Each renders inside the app shell (or the auth shell for the guest-only pages)" (§6) | **The error pages use the guest shell.** | An error page can be reached by a signed-in user and by a guest, and the guest shell needs no database and no user context — so the page still renders when the database is unreachable. |
| 8 | The 403 is "the registration is closed, or the visitor is not allowed" (§6) | **The 403 page is generic.** | It says "You do not have access to that page." and nothing else, so it leaks neither whether an account exists nor whether the registration is closed. The closed-registration screen is a page of its own (issue-016), not this page. |
| 9 | The rewrite is the three rules of §5 | **The three rules, plus one for the bare root.** | The document root is a directory, so the `!-f` / `!-d` conditions skip it and the root is left to the server's directory index. Measured: on LiteSpeed the root does reach `index.php` through the default `DirectoryIndex` — the gate answers 302 — but that is the server's choice, not ours. `RewriteRule ^$ index.php [QSA,L]` makes the root routing explicit and server-independent. |

Three facts belong on the record:

- **`views/` is not denied yet.** The deny block denies `includes/`, `database/`,
  `docker/` and `vendor/`, but not `views/` — and the screens will live there from
  issue-016. A direct request for `/views/dashboard.php` would run a view outside
  the controller. The deny block was left **untouched**, because the acceptance
  criteria require it unchanged and there is nothing to expose yet (the directory
  does not exist). **Issue-016 must add the `views/` rule when it creates the
  first screen.**
- **Two acceptance criteria cannot be met until the installer and the screens
  exist — and are met in the sandbox instead.** §9 expects `/nope` → 404,
  `POST /dashboard` → 405 and `/dashboard` → 302/200. Those need an installed
  application (`config.php`, written by the installer in issue-015) and the
  screens (issues 016–020). Until then the live stack answers **every** URL with
  the gate's 302 to `/install.php`, and §9's step 4 measures the gate rather than
  the error pages. The behaviour is therefore proved in the **sandbox tier** of
  `database/front_controller_test.php`: a copy of the application in a temporary
  directory, served by the PHP built-in server through a router script that
  mimics the rewrite. It walks the same controller through three configurations —
  no `config.php`, a `config.php` without `vendor/`, and a fully installed copy —
  and asserts the 302, the 404 page, the 405 with its `Allow` header, the 302 to
  `/dashboard`, the security headers and the 500 page with nothing leaked.
- **The error boundary wraps the dispatch, not the requires** — as §7 says. A
  failure in a `require` is a deployment error and stays visible; the bootstrap is
  the one require with its own catch, because that is the failure a fresh checkout
  hits first.

---

## Table of contents

*Implementation notes — 2026-10-27* (the places where the specification
contradicted itself, and how each was resolved)

1. Objective
2. Constraints
3. The install gate
4. Specification — `index.php`
5. Specification — the `.htaccess` rewrite
6. Specification — the error pages
7. Specification — the request lifecycle
8. Acceptance criteria
9. Verification
10. Rollback
11. Risks
12. Out of scope

---

## 1. Objective

Build the **front controller**: the one file every request enters, the install gate
that redirects an unconfigured installation to the wizard, the route table that maps
a request to a view, and the error pages that replace PHP's default output.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `index.php` exists and is the **only** entry point for the application's URLs |
| 2 | A missing `config.php` sends the visitor to `install.php` — and **only** that |
| 3 | The `RuntimeException` from `includes/bootstrap.php` is caught and **shown**, not swallowed into a blank page |
| 4 | The session starts **first**, so the security headers are never dropped |
| 5 | A 404, a 403, a 405 and a 500 each render a page — never a stack trace |
| 6 | The `.htaccess` deny block still holds after the rewrite is added |
| 7 | The rewrite rules are appended **below** the deny block, never replacing it |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| `display_errors = Off` | A PHP error produces a **blank page**. The controller must catch it and render something. |
| `output_buffering = 0` | `startSession()` must be the first call — it opens `ob_start()`. |
| **LiteSpeed honours `.htaccess`** | The rewrite works the same in dev and in production. |
| **LiteSpeed caches the parsed `.htaccess` in memory** | After editing it, `docker compose restart app` — otherwise the live status codes disagree with the file. |
| The web root is the repository root | Every file is web-reachable unless denied. The deny block is load-bearing. |
| `config.php` is **git-ignored** | The gate is a file-existence check, not a constant check. |
| No framework | The routing table is a literal array in the controller. |

---

## 3. The install gate

**`config.php` existing means "installed".** There is exactly one gate, and it is a
file-existence check:

````php
if (!is_file(__DIR__ . '/config.php')) {
    header('Location: /install.php', true, 302);
    exit;
}
````

| Rule | Reason |
|---|---|
| The check is `is_file()`, not `file_exists()` | `is_file()` is false for a directory named `config.php`. |
| It is the **only** gate | There is no second probe. A DB-backed "does the `users` table exist?" probe is **unreachable** in the wizard — the wizard does not load `config.php`, so the `DB_*` constants are undefined and any such probe is dead code. |
| The redirect happens **before** the bootstrap | A fresh checkout has no `vendor/` yet, and the installer must stay reachable. |
| The redirect is a **302**, and it exits immediately | No further code runs. |

> **Order matters.** `config.php` → gate → `bootstrap.php` → the rest. If the
> bootstrap were loaded first, a missing `vendor/` would throw before the visitor
> ever reached the installer.

---

## 4. Specification — `index.php`

````php
<?php
/**
 * index.php — the front controller.
 *
 * Contract:
 *   1. The install gate runs FIRST: no config.php -> redirect to install.php.
 *   2. The gate runs BEFORE the bootstrap, so a missing vendor/ never blocks
 *      the installer.
 *   3. startSession() is the first call after the gate: output_buffering is 0
 *      in this stack, so any byte emitted before it drops the security headers.
 *   4. Every Throwable is caught. display_errors is Off, so an uncaught error
 *      is a blank page. The detail goes to the error log; the page gets a
 *      generic sentence.
 *   5. The route table is literal. No wildcards, no regex routes.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';   // 1. the constants

// ---- 2. The install gate ---------------------------------------------------
if (!is_file(__DIR__ . '/config.php')) {
    header('Location: /install.php', true, 302);
    exit;
}

// ---- 3. The dependencies ----------------------------------------------------
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/Router.php';

startSession();

// ---- 4. The error boundary -------------------------------------------------
try {
    $router = new Router();

    // The route table. See issue-012 section 10 for the full list.
    $router->get('/login',   'views/login.php');
    // …

    $router->dispatch();
} catch (Throwable $ex) {
    error_log('[app] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    require __DIR__ . '/includes/views/error_500.php';
}
````

### The route table

The full table is in [issue-012.md](issue-012.md) §10. It has **24 routes**:

| Group | Count | Auth |
|---|---|---|
| Guest — sign in, register, forgot password, reset password | 8 | none |
| Dashboard | 1 | required |
| Transactions — list, new, edit, save, delete | 5 | required |
| Reports | 1 | required |
| Categories — list, save, delete | 3 | required |
| Settings — page, profile, password, logout | 4 | required |
| Root redirect `/` → `/dashboard` | 1 | — |

### The rules

| Rule | Reason |
|---|---|
| The route table is **literal** | A developer reading the controller sees every URL the product answers to. |
| The handler path comes from the table, **never from the request** | A handler path built from user input is a remote code execution hole. |
| `require`, not `include` | The handler runs once. |
| `/` redirects to `/dashboard` | One canonical home URL. |
| A `HEAD` request is dispatched as `GET` | The router does it. |

---

## 5. Specification — the `.htaccess` rewrite

### The rule that must not be broken

> The deny block is **load-bearing**. Append the rewrite **below** it. **Never
> replace the file wholesale.**

The existing file denies dotfiles, `config.php`, `database/`, `includes/`, `docker/`,
`vendor/`, `composer.json`, `composer.lock` and `docs/issues/`. Every one of those
must still return **403** after this issue.

### The rules to append

````apache
    # ---- Front controller -------------------------------------------------
    # Everything that is not a real file and not a real directory is handed
    # to index.php. Real files (install.php, assets/, docs/mockup/) are served
    # directly.
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
````

| Rule | Reason |
|---|---|
| `!-f` and `!-d` conditions | An existing file (`/install.php`, `/assets/css/tokens.css`) is served by the server, not routed. |
| `QSA` | The query string is carried into the front controller — `/transactions?q=coffee` must arrive as `$_GET['q']`. |
| `L` | Stop rewriting. |
| **Below** the deny block | The deny rules use `[F,L]` and must be evaluated first. |
| The pattern is `^` | Everything that is not a real path goes to `index.php`. |

### The consequence that must be tested

After the rewrite is added, `/.env` must still be **403** — not a 404 from the
front controller. The deny rules run first and terminate the request, so the
front controller never sees it.

### The cache rule

> **LiteSpeed caches the parsed `.htaccess` in memory.** A rule you just added to
> the file is **not** enforced until the server re-reads it. After editing
> `.htaccess`, run `docker compose restart app` — otherwise the live status codes
> disagree with the file and the hardening test fails for the wrong reason.

This is not a suggestion. It is the documented behaviour of the server, and it is
the reason a hardening test can fail for a reason that has nothing to do with the
rule under test.

---

## 6. Specification — the error pages

Four statuses, four pages. Each renders inside the app shell (or the auth shell for
the guest-only pages) and each says something a human can act on.

| Status | When | The page says |
|---|---|---|
| **404** | The path is not in the route table | *"That page does not exist."* + a link to the dashboard. |
| **403** | The registration is closed, or the visitor is not allowed | *"You do not have access to that page."* |
| **405** | The path exists with a different method | *"That page does not accept this request."* + an `Allow:` header listing the methods that do. |
| **500** | An uncaught `Throwable` | *"Something went wrong on our side. Please try again."* |

### The rules

| Rule | Reason |
|---|---|
| **Never** a stack trace, a raw error, a PHP message or a file path | The LiteSpeed error log holds the detail. |
| The 500 page does **not** echo `$ex->getMessage()` | It is written to the log with `error_log()` — with the file and line — and that is where it stays. |
| The status code is set **before** the body is emitted | `http_response_code(404)` then the template. |
| The error pages are **views**, in `includes/views/` | They are rendered like any other page, with the same shell and the same escaping. |
| The 403 for a closed registration is **not** a 404 | The visitor gets a clear answer, and the page does not leak whether an account exists. |

### The error view files

````text
includes/views/error_404.php
includes/views/error_403.php
includes/views/error_405.php
includes/views/error_500.php
````

Each one is a small card in the shell. They are reached by `throw new
HttpException(404)` from a handler, caught by the controller's boundary, which maps
the status to the view.

````php
class HttpException extends RuntimeException
{
    public function __construct(public int $status, string $message = '')
    {
        parent::__construct($message);
    }
}
````

`HttpException` lives in `includes/functions.php`. The controller's catch block
checks for it **first** and renders the matching view; any other `Throwable` is a
500.

---

## 7. Specification — the request lifecycle

The order of operations in every request. This is the part that has caused outages.

````text
1.  index.php
2.      require config.php                    (the constants)
3.      is_file('config.php')?  no -> 302 /install.php, exit
4.      require includes/bootstrap.php        (may throw RuntimeException)
5.      require Database / functions / auth / Router
6.      startSession()                        (opens ob_start)
7.      try { $router->dispatch(); }
8.      catch (HttpException $e)  -> render the matching error view
9.      catch (Throwable $e)     -> error_log() + render the 500 view
10. include footer.php                        (closes main, script tag)
````

| Rule | Reason |
|---|---|
| `startSession()` is at step 6 — **before any output** | `output_buffering = 0`; a byte emitted first breaks `header()` and silently drops the security headers. |
| The bootstrap's `RuntimeException` is caught | Left uncaught it is a **blank page**, because `display_errors` is Off. The message names the exact command to run. |
| The error boundary wraps the **dispatch**, not the requires | A failure in `require` is a deployment error, and it must be visible, not swallowed. |
| Every mutating request ends with a **redirect** | The Post/Redirect/Get pattern. |

### The security headers

The controller sends them once, before any output:

````php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
````

They are sent **after** `startSession()` — which is why `startSession()` must come
first. A `header()` call after output has begun emits a warning and the header is
dropped.

### The bootstrap failure page

````php
try {
    require_once __DIR__ . '/includes/bootstrap.php';
} catch (RuntimeException $ex) {
    http_response_code(500);
    $message = $ex->getMessage();
    require __DIR__ . '/includes/views/error_bootstrap.php';
    exit;
}
````

The page shows the message and the command to run. It is the **only** place a raw
exception message is shown, and the exception is one the application itself
threw with a safe, actionable string.

---

## 8. Acceptance criteria

- [ ] `index.php` exists and is the single front controller.
- [ ] The install gate is `is_file(__DIR__ . '/config.php')` and it redirects with a
      **302** to `/install.php` and exits.
- [ ] The gate runs **before** `includes/bootstrap.php` is required.
- [ ] The `RuntimeException` from `bootstrap.php` is caught and its message is
      rendered, with the `composer install` command shown.
- [ ] `startSession()` is called before any output and before any `header()` call.
- [ ] The controller sends `X-Content-Type-Options`, `X-Frame-Options` and
      `Referrer-Policy`.
- [ ] The route table holds all **24** routes from
      [issue-012.md](issue-012.md) §10, and `/` redirects to `/dashboard`.
- [ ] A 404, a 403, a 405 and a 500 each render a distinct page.
- [ ] The 405 response carries an `Allow:` header.
- [ ] No error page contains a stack trace, a file path or a raw exception message —
      except the dedicated bootstrap page.
- [ ] `HttpException` carries a status code, and the catch block checks for it
      **before** the generic `Throwable` catch.
- [ ] The rewrite rules are appended **below** the deny block in `.htaccess`, with
      the `!-f` / `!-d` conditions and the `QSA,L` flags.
- [ ] The deny block is **unchanged** — `/.env`, `/.git/config`, `/config.php`,
      `/database/…`, `/includes/…`, `/vendor/…`, `/composer.json` all still return
      **403**.
- [ ] `docker compose restart app` was run after the `.htaccess` edit, and the status
      codes were re-measured **after** the restart.
- [ ] `index.php` passes `php -l`.

---

## 9. Verification

```bash
cd /home/zongbao/projects/financial-tracker

# 1 — lint
docker compose exec -T app php -l /var/www/html/index.php

# 2 — the rewrite is present AND below the deny block
grep -n 'RewriteRule' .htaccess
grep -n 'Front controller' .htaccess          # must be AFTER the deny rules

# 3 — restart the app so LiteSpeed re-reads the parsed .htaccess
docker compose restart app
sleep 2

# 4 — the front controller answers
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8088/dashboard     # 302 (to /login) or 200
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8088/nope           # 404
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8088/dashboard  # 405

# 5 — the deny block still holds (these must ALL be 403)
for p in /.env /.git/config /config.php /database/schema.sql /includes/functions.php \
         /vendor/autoload.php /composer.json /composer.lock /docs/issues/README.md; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8088$p")
  printf "%-34s %s\n" "$p" "$code"
  [[ "$code" == "403" ]] || echo "  !! expected 403"
done

# 6 — the real files are still served directly
curl -s -o /dev/null -w "install.php %{http_code}\n" http://localhost:8088/install.php
curl -s -o /dev/null -w "mockup   %{http_code}\n" http://localhost:8088/docs/mockup/01-login.html

# 7 — no stack trace leaks on an unknown path
curl -s http://localhost:8088/nope | grep -iE 'stack trace|fatal error|\.php on line' \
  && echo "!! LEAK" || echo "no-leak: ok"

# 8 — the hardening regression test still passes
docker compose exec -T app php /var/www/html/database/webroot_hardening_test.php
echo "exit=$?"

# 9 — the whole suite
.github/skills/dev-stack-ops/scripts/run-tests.sh
echo "suite_exit=$?"
```

Expected: step 4 shows **302/200** for `/dashboard`, **404** for `/nope` and **405**
for the POST; step 5 prints **403** on every line with no `!!`; step 7 prints
`no-leak: ok`; step 8 and step 9 exit **0**.

---

## 10. Rollback

````bash
# Remove the rewrite rules from .htaccess — keep the deny block.
# (edit .htaccess and delete the "Front controller" section)

docker compose restart app
rm -f index.php
rm -f includes/views/error_*.php
````

> Do **not** `git checkout .htaccess` blindly — the deny block has been verified
> against this image and must survive. Remove only the section this issue added.

There is no data to unwind: the controller writes nothing.

---

## 11. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The rewrite is added **above** the deny block | Medium | §5 states the order, and the verification greps for the block's position. |
| `startSession()` lands after some output | Medium | §7 fixes the order at step 6; the acceptance criterion names it. |
| The bootstrap's `RuntimeException` is uncaught → **blank page** | Medium | §7 gives the dedicated catch, and it is an acceptance criterion. |
| A raw exception message reaches the browser | Medium | §6 forbids it, and the verification greps the 404 body for a leak. |
| The `.htaccess` edit is not re-read by LiteSpeed | **High** if forgotten | §5 states the restart requirement; the verification restarts before measuring. |
| The 403 for a closed registration leaks that an account exists | Low | §6 keeps the 403 generic. |
| A handler path is built from the request | Low | §4 states that the handler path comes from the table only. |
| The install gate is checked *after* the bootstrap | Medium | §3 and §4 put the gate first; it is the first acceptance criterion. |

---

## 12. Out of scope

- **The installer itself** — [issue-015.md](issue-015.md).
- **The views.** The error pages are in scope; the product screens are issues
  [016](issue-016.md) – [020](issue-020.md).
- **A middleware pipeline, a controller class hierarchy, or a DI container.** The
  route table is a literal array.
- **A REST or JSON API.** This is a server-rendered HTML application.
- **A custom 404 route in the database.** The 404 page is a static view.
- **Server-side caching, ETags, or a CDN configuration.**
- **A health-check endpoint.** Not needed for a personal install.
