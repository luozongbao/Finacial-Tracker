<?php
/**
 * auth.php — the session, the current user and the authorisation helpers.
 *
 * The contract (docs/issues/issue-012.md, §9):
 *
 *   1. requireLogin() is the FIRST thing a protected handler does — before
 *      any database work, before any output.
 *   2. loginUser() regenerates the session id and rotates the CSRF token.
 *      A session id that survives a sign-in is a session-fixation hole, and
 *      a CSRF token that survives a privilege change is a CSRF hole.
 *   3. registrationAllowed() is ONE helper, called by BOTH sides. When
 *      ALLOW_REGISTRATION is false, registration is closed on the front end
 *      (the page renders no form and returns 403) AND on the back end (the
 *      POST handler rejects the request, whatever was rendered). A check in
 *      only one of the two places is a bug.
 *   4. The password is hashed with password_hash() and PASSWORD_DEFAULT —
 *      never md5(), never sha1(), never a home-grown salt. The cost factor
 *      can be raised later without a schema change, because the hash
 *      carries its own parameters.
 *
 * The sign-in attempt limit (5 attempts, a 15-minute lock) is specified in
 * issue-016 §6, which owns isLockedOut() and the counter. It is not
 * duplicated here.
 *
 * Configuration comes from config.php and from nothing else.
 */

declare(strict_types=1);

/**
 * Is somebody signed in?
 *
 * The answer comes from the session alone — no query. A disabled account
 * that still holds a session is handled by the sign-in flow in issue-016,
 * which refuses to start a session for a disabled account in the first
 * place.
 */
function isLoggedIn(): bool
{
    return getCurrentUserId() !== null;
}

/**
 * The id of the signed-in user, or null.
 *
 * The session value is cast to an int, and a value that is not a positive
 * integer is treated as no session at all — a tampered session cookie must
 * not produce user id 0.
 */
function getCurrentUserId(): ?int
{
    startSession();

    $raw = $_SESSION['user_id'] ?? null;

    if (is_int($raw)) {
        $id = $raw;
    } elseif (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
        $id = (int) $raw;
    } else {
        return null;
    }

    return $id > 0 ? $id : null;
}

/**
 * The signed-in user's row, or null.
 *
 * The row is fetched once per request: the shell needs the name, and a
 * second screen needs the address, and neither of them should be a second
 * query. The cache is keyed by the user id and is dropped when the session
 * changes.
 */
function getCurrentUser(): ?array
{
    static $cache = [];

    $id = getCurrentUserId();

    if ($id === null) {
        return null;
    }

    if (!array_key_exists($id, $cache)) {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id, username, email, email_verified_at, status, created_at, updated_at
               FROM users
              WHERE id = ?',
            [$id]
        );

        $cache[$id] = $row === false ? null : $row;
    }

    return $cache[$id];
}

/**
 * Sign a user in.
 *
 * The session id is regenerated and the CSRF token is rotated: both belong
 * to the old, unauthenticated visitor.
 */
function loginUser(int $userId): void
{
    startSession();

    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;

    rotateCsrfToken();
}

/**
 * Sign the visitor out.
 *
 * The session data is cleared, the session is regenerated and destroyed,
 * and the cookie is removed from the browser. Leaving the cookie behind
 * would leave a session id pointing at nothing.
 */
function logoutUser(): void
{
    startSession();

    $_SESSION = [];

    session_regenerate_id(true);

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => (string) $params['path'],
            'domain'   => (string) $params['domain'],
            'secure'   => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
    }

    session_destroy();
}

/**
 * The authorisation guard.
 *
 * The first line of every protected handler. When the visitor is not signed
 * in, the page they wanted is remembered — but only a path we control, so
 * the sign-in page cannot be turned into an open redirect — and the visitor
 * is sent to the sign-in page.
 */
function requireLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    startSession();

    $_SESSION['intended_url'] = safeRedirectTarget($_SERVER['REQUEST_URI'] ?? '/');

    redirect('/login');
}

/**
 * Is registration open?
 *
 * ONE helper, called by the front-end check (the register page renders no
 * form and returns 403) and by the back-end check (the POST handler
 * rejects the request). The constant is a real boolean: the string
 * comparison fails open.
 */
function registrationAllowed(): bool
{
    return ALLOW_REGISTRATION === true;
}

/**
 * Hash a password for storage.
 */
function hashPassword(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * Check a password against its stored hash.
 */
function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}
