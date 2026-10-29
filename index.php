<?php
/**
 * index.php — the front controller.
 *
 * The contract (docs/issues/issue-014.md):
 *
 *   1. The install gate runs FIRST: no config.php -> 302 to /install.php.
 *      The gate is a file-existence check, and it is the ONLY gate.
 *   2. The gate runs BEFORE the bootstrap, so a missing vendor/ never stops
 *      the visitor from reaching the installer.
 *   3. startSession() is the first call that produces output or a header.
 *      output_buffering is 0 in this stack, so a byte emitted before it
 *      drops the security headers.
 *   4. Every Throwable is caught. display_errors is Off, so an uncaught
 *      error is a blank page. The detail goes to the error log; the page
 *      gets a generic sentence.
 *   5. The route table is literal. No wildcards, no regex routes.
 *
 * The lifecycle (issue-014 §7):
 *
 *   the gate -> config.php -> the bootstrap -> the requires -> startSession()
 *   -> the security headers -> dispatch() -> the view.
 *
 * A view owns its page: it includes includes/header.php and
 * includes/footer.php. The controller does not close the page, because the
 * one redirect route has to answer with a body of exactly zero bytes — a
 * controller that always closed the page would emit a stray footer after a
 * 302.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The install gate.
//
// config.php is the only configuration source and it is git-ignored, so its
// presence IS the "installed" flag. The check is is_file() and not
// file_exists(): a directory named config.php is not a configuration.
//
// The gate is the only gate. There is no second probe — a database-backed
// "does the users table exist?" check would be dead code, because the
// installer does not load config.php and the DB_* constants would be
// undefined there.
//
// The gate runs BEFORE the bootstrap: a fresh checkout has no vendor/ yet,
// and a missing vendor/ must not stop the visitor from reaching the
// installer.
//
// The status is 302 and the script exits immediately: no further code runs.
// ---------------------------------------------------------------------------
if (!is_file(__DIR__ . '/config.php')) {
    header('Location: /install.php', true, 302);
    exit;
}

// The constants. Required after the gate — the gate has just proved that the
// file is there.
require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// 2. The dependencies.
//
// functions.php comes first on purpose: it has no vendor dependency, and the
// bootstrap failure page below needs e() and the shared shell.
//
// The bootstrap throws a RuntimeException when vendor/ is missing. That is a
// deployment error, and it must be VISIBLE — display_errors is Off, so an
// uncaught exception is a blank page. The dedicated page shows the message
// and the command that fixes it.
//
// The remaining requires sit OUTSIDE the error boundary: a failure there is
// a broken deployment, and it must be visible rather than swallowed into a
// generic error page.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/includes/functions.php';

try {
    require_once __DIR__ . '/includes/bootstrap.php';
} catch (RuntimeException $ex) {
    http_response_code(500);
    $bootstrapError = $ex->getMessage();
    require __DIR__ . '/includes/views/error_bootstrap.php';
    exit;
}

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/Router.php';

// The sanitised log and the mail outbox.
//
// logging.php holds logFailure() — the ONE place in the application that
// writes to the error log. An exception's MESSAGE is never logged: a MySQL
// unique-key violation carries the submitted address in it (issue-022
// §7.4), and the log ends up in support tickets.
//
// outbox.php holds the mail queue. The request writes a row and returns;
// the CLI worker sends it. The response path therefore never waits for
// SMTP, which is what closes the enumeration timing gap (issue-022 §7.1).
require_once __DIR__ . '/includes/logging.php';
require_once __DIR__ . '/includes/outbox.php';

// The mailer is part of the runtime, so the front controller loads it.
//
// The registration and the forgot-password screens call mailIsConfigured()
// and sendMail(). The functions live in includes/mailer.php, and a view that
// calls a function nobody loaded dies with "Call to undefined function" —
// which the error boundary below turns into a 500.
//
// The file is safe to load here: it only declares functions, and it reads
// the SMTP constants when a function is CALLED, not when it is declared.
require_once __DIR__ . '/includes/mailer.php';

// ---------------------------------------------------------------------------
// 3. The session.
//
// The first call that produces output or a header. output_buffering is 0 in
// this stack, so startSession() opens ob_start() — and everything after it
// can still send a header.
//
// The session starts before the security headers on purpose: a header() call
// after the first byte of the body is a warning, and the header is dropped.
// ---------------------------------------------------------------------------
startSession();

// The security headers. They go out before the first byte of the body.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// ---------------------------------------------------------------------------
// 3b. The credentials of the session.
//
// A password change must end every other session of that account
// (issue-016 §10). The schema has no sessions table, so the version of the
// credentials travels in the session and is compared with the stored
// password hash here — before any handler runs.
//
// The check runs here and not inside requireLogin() on purpose: it has to
// happen for EVERY route, including the ones that never call the guard.
//
// A visitor who is not signed in costs nothing: the answer comes from the
// session alone, and no query is made.
// ---------------------------------------------------------------------------
if (isLoggedIn() && ! sessionCredentialsAreCurrent()) {
    // The session lost its identity. The visitor signs in again — and lands
    // where they were going, because the guard remembers it.
    $_SESSION['intended_url'] = safeRedirectTarget($_SERVER['REQUEST_URI'] ?? '/');

    redirect('/login');
}

// ---------------------------------------------------------------------------
// 4. The route table and the dispatch.
//
// The table is Router::defaultRoutes() (issue-012 §10): one literal entry
// per URL the product answers to. It is built here, so the URLs are listed
// exactly once in the codebase.
//
// The error boundary wraps the dispatch, not the requires.
// ---------------------------------------------------------------------------
try {
    $router = Router::withDefaultRoutes();
    $router->dispatch();
} catch (HttpException $ex) {
    // 403, 404, 405. The status and the extra headers are set before the
    // body, because the status has to travel with the first byte.
    http_response_code($ex->status);

    foreach ($ex->headers as $name => $value) {
        header($name . ': ' . $value);
    }

    // The status selects the view through a whitelist. The file name comes
    // from the map below and never from the exception, so a status can
    // never turn into a path.
    $errorViews = [
        403 => 'error_403.php',
        404 => 'error_404.php',
        405 => 'error_405.php',
    ];

    $errorView = $errorViews[$ex->status] ?? 'error_500.php';

    // The 405 page tells the visitor what the page does accept.
    $allowedMethods = $ex->headers['Allow'] ?? '';

    require __DIR__ . '/includes/views/' . $errorView;
} catch (Throwable $ex) {
    // Anything else is a 500. The CONTEXT, the class and the code of the
    // failure go to the error log, where the operator can read them. The
    // page gets a generic sentence and nothing else: no stack trace, no
    // file path, no raw message — and no message in the log either.
    logFailure('app: the request failed', $ex);

    http_response_code(500);

    require __DIR__ . '/includes/views/error_500.php';
}
