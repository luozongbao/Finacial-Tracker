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
 * Issue-016 (§5 – §10) adds the sign-in flow itself:
 *
 *   * the lookup by username OR email, in ONE query;
 *   * the attempt limit — 5 failures, a 15-minute wait — counted against
 *     the IDENTIFIER and not only against the account, so that a name
 *     which matches no account is throttled too;
 *   * the single-use, one-hour password-reset token and the 24-hour
 *     e-mail-verification token, of which only the SHA-256 hash is stored;
 *   * the credential version, which ends every other session of an account
 *     when its password changes.
 *
 * Issue-021 (§8) reshapes the attempt limit, because a hard lock is a
 * denial-of-service weapon in the hands of anybody who can reach the form:
 *
 *   * the penalty DECAYS — a failure older than the quiet window stops
 *     counting, so the owner of an account is let back in once the window
 *     has elapsed;
 *   * the wait BACKS OFF — it doubles with every failure beyond the limit
 *     and is capped, and the locked screen states the time that is really
 *     left;
 *   * there are TWO counters, against the identifier and against the
 *     source, and the effective penalty is the STRICTER of the two — never
 *     the sum, so the source counter can never be the only one;
 *   * the count is raised IN SQL, so two failures that land together raise
 *     the counter by two;
 *   * the rows that can no longer produce a wait are removed, so the
 *     table does not grow at the rate of the network.
 *
 * Issue-022 (§7.2) adds a SECOND, generic limiter — the request throttle:
 *
 *   * one table, `request_throttles`, serves every endpoint that needs a
 *     limiter the sign-in throttle does not fit;
 *   * a counter per KEY (a hash of the submitted address) and a counter
 *     per SOURCE (the peer that really connected), and the effective
 *     penalty is the STRICTER of the two;
 *   * the counters DECAY: a count whose window closed reads zero;
 *   * a refused request does NOT raise the counter, so the window is not
 *     extended by the requests that were turned away;
 *   * REQUEST_THROTTLE_ENABLED in config.php is the kill switch.
 *
 * Configuration comes from config.php and from nothing else.
 */

declare(strict_types=1);

// The sanitised error log. Two of the helpers below log a failure, and
// the log must never carry an exception MESSAGE — a unique-key violation
// carries the submitted address in it (issue-022 §7.4).
require_once __DIR__ . '/logging.php';

/*
 * The product's own rules. They are declared with a guard because this file
 * is included by the front controller, by the views and by the regression
 * tests' child processes — a second inclusion must not emit a warning.
 */
if (!defined('LOGIN_ATTEMPT_LIMIT')) {
    /** The failed sign-ins that start a wait (issue-016 §6). */
    define('LOGIN_ATTEMPT_LIMIT', 5);

    /**
     * How long the wait lasts, in minutes (issue-016 §6) — and, since
     * issue-021 §8, also the QUIET WINDOW.
     *
     * A failure older than this does not count towards the limit any more,
     * so the counter is "the failures of the last fifteen minutes" and an
     * attacker cannot hold the lock open by coming back once an hour.
     */
    define('LOGIN_LOCK_MINUTES', 15);

    /**
     * The longest wait a counter ever serves, in minutes (issue-021 §8).
     *
     * The backoff doubles with every failure beyond the limit, and this is
     * where it stops.
     */
    define('LOGIN_BACKOFF_MAX_MINUTES', 60);

    /**
     * The failed sign-ins from ONE source that start a wait (issue-021 §3).
     *
     * The source counter bounds the attacker who rotates the identifier,
     * so its limit is deliberately higher than the identifier's: a visitor
     * behind a NAT shares the bucket with everybody else, and the source
     * counter is never the only counter.
     */
    define('LOGIN_SOURCE_ATTEMPT_LIMIT', 20);

    /**
     * The quiet window of the source counter, in minutes (issue-021 §8).
     *
     * It is longer than the identifier's, so the source counter decays on
     * a slower schedule than the one that guards a single account.
     */
    define('LOGIN_SOURCE_WINDOW_MINUTES', 60);

    /** The shortest password the product accepts (issue-016 §5). */
    define('PASSWORD_MIN_LENGTH', 10);

    /** The longest password the product accepts (issue-016 §5). */
    define('PASSWORD_MAX_LENGTH', 200);

    /** The lifetime of a password-reset link, in minutes (issue-016 §9). */
    define('RESET_TOKEN_TTL_MINUTES', 60);

    /** The lifetime of an account-confirmation link, in minutes (issue-016 §7). */
    define('VERIFICATION_TOKEN_TTL_MINUTES', 1440);

    /**
     * The floor of the forgot-password response time, in milliseconds.
     *
     * The screen must answer a known address and an unknown address in the
     * same time (issue-016 §9). Since issue-022 §7.1 the request does not
     * talk to the SMTP server at all — it writes a row into the outbox —
     * so what is left to hide is the ONE extra INSERT the known branch
     * pays. That is milliseconds, and the floor puts it inside 500 ms,
     * where it cannot be told apart from the unknown branch.
     */
    define('AUTH_TIMING_FLOOR_MILLISECONDS', 500);
}

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
 *
 * The credential version travels with the session. It is the digest of the
 * stored password hash, and it is what lets a password change end every
 * other session of the account. The hash itself never enters the session.
 */
function loginUser(int $userId, ?string $credentialVersion = null): void
{
    startSession();

    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;

    if ($credentialVersion !== null && $credentialVersion !== '') {
        $_SESSION['credential_version'] = $credentialVersion;
    }

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

/**
 * The decoy hash of an account that does not exist.
 *
 * A sign-in against a name that matches no account must cost the same as a
 * sign-in against an account with a wrong password — otherwise the response
 * time says which half was wrong. The hash is a real bcrypt hash of a
 * random string, so password_verify() does the same work it does for a real
 * account.
 */
function decoyPasswordHash(): string
{
    static $hash = null;

    if ($hash === null) {
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    return $hash;
}

/**
 * Verify a password, whether or not the account exists.
 *
 * A missing account is verified against the decoy hash and always fails, so
 * the caller cannot tell the two cases apart by the time it took.
 */
function verifyPasswordOrDecoy(string $plain, ?string $hash): bool
{
    if ($hash === null || $hash === '') {
        password_verify($plain, decoyPasswordHash());

        return false;
    }

    return password_verify($plain, $hash);
}

// ---------------------------------------------------------------------------
// The accounts (issue-016 §5 and §7)
// ---------------------------------------------------------------------------

/**
 * Find an account by its username OR its email address — one query.
 *
 * The column is utf8mb4 with a case-insensitive collation, so `Demo` and
 * `demo` are the same account here too.
 *
 * The row carries the password hash: the sign-in flow verifies the password
 * against it, and nothing else ever reads that column.
 */
function findUserByIdentifier(string $identifier): ?array
{
    $identifier = trim($identifier);

    if ($identifier === '') {
        return null;
    }

    $row = Database::getInstance()->fetchOne(
        'SELECT id, username, email, password_hash, email_verified_at, status, created_at, updated_at
           FROM users
          WHERE username = ? OR email = ?
          LIMIT 1',
        [$identifier, $identifier]
    );

    return $row === false ? null : $row;
}

/**
 * Find an account by its email address.
 *
 * The forgot-password screen uses this one. It must never say whether it
 * found something.
 */
function findUserByEmail(string $email): ?array
{
    $email = trim($email);

    if ($email === '') {
        return null;
    }

    $row = Database::getInstance()->fetchOne(
        'SELECT id, username, email, password_hash, email_verified_at, status, created_at, updated_at
           FROM users
          WHERE email = ?
          LIMIT 1',
        [$email]
    );

    return $row === false ? null : $row;
}

/**
 * Is the username taken?
 *
 * The case-insensitive comparison matters: with a `_ci` collation `Demo`
 * and `demo` are one name, and the "already taken" message must be true.
 */
function usernameTaken(string $username): bool
{
    $username = trim($username);

    if ($username === '') {
        return false;
    }

    $row = Database::getInstance()->fetchOne(
        'SELECT id FROM users WHERE username = ? LIMIT 1',
        [$username]
    );

    return $row !== false;
}

/**
 * Is the email address taken?
 */
function emailTaken(string $email): bool
{
    $email = trim($email);

    if ($email === '') {
        return false;
    }

    $row = Database::getInstance()->fetchOne(
        'SELECT id FROM users WHERE email = ? LIMIT 1',
        [$email]
    );

    return $row !== false;
}

/**
 * The validation of the registration form.
 *
 * The FORMAT is checked first and the UNIQUENESS second: an invalid
 * username must not report "already taken", which is nonsense and leaks the
 * shape of the table.
 *
 * @param array{username?: string, email?: string, email_confirmation?: string, password?: string, password_confirmation?: string} $input
 *
 * @return array<string, string> The field errors. Empty when the input is good.
 */
function validateRegistrationInput(array $input): array
{
    $errors = [];

    $username = (string) ($input['username'] ?? '');
    $email = trim((string) ($input['email'] ?? ''));
    $emailConfirmation = trim((string) ($input['email_confirmation'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

    // The username: 3 to 32 characters of letters, digits, underscore and
    // hyphen. The message lives in the shared helper, so the registration
    // screen and the settings screen cannot drift apart.
    if (!isValidUsername($username)) {
        $errors['username'] = usernameValidationMessage($username);
    }

    // The email address and its confirmation.
    if (!isValidEmail($email)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif ($email !== $emailConfirmation) {
        $errors['email_confirmation'] = 'The two email addresses do not match.';
    }

    // The password and its confirmation. The password is never trimmed.
    $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

    if ($passwordLength < PASSWORD_MIN_LENGTH) {
        $errors['password'] = 'The password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    } elseif ($passwordLength > PASSWORD_MAX_LENGTH) {
        $errors['password'] = 'The password may be at most ' . PASSWORD_MAX_LENGTH . ' characters long.';
    } elseif ($password !== $passwordConfirmation) {
        $errors['password_confirmation'] = 'The two passwords do not match.';
    }

    return $errors;
}

/**
 * Create an account.
 *
 * The UNIQUE constraints are the real guard: two simultaneous registrations
 * both pass the SELECT, and only one of them reaches the table. The
 * constraint violation is mapped back to the friendly message, so the race
 * produces the same copy as the check.
 *
 * @return array{status: string, user_id?: int} status is 'ok' or 'duplicate'
 */
function createUser(string $username, string $email, string $password): array
{
    $db = Database::getInstance();

    try {
        $db->execute(
            'INSERT INTO users
                (username, email, password_hash, email_verified_at, status)
                  VALUES (?, ?, ?, NULL, \'active\')',
            [$username, $email, hashPassword($password)]
        );
    } catch (PDOException $ex) {
        // 23000 is the integrity-constraint violation — the username or the
        // email address is already taken.
        if ((string) $ex->getCode() === '23000') {
            return ['status' => 'duplicate'];
        }

        throw $ex;
    }

    // execute() returns a bool, never a row count. The id comes from
    // lastInsertId().
    return ['status' => 'ok', 'user_id' => $db->lastInsertId()];
}

// ---------------------------------------------------------------------------
// The attempt limit (issue-016 §6, issue-021 §8)
// ---------------------------------------------------------------------------

/**
 * The key of the identifier counter.
 *
 * The identifier is hashed: the counter must work for a name that matches
 * no account, and the table has no business keeping the string that was
 * typed at the sign-in form. The hash is also case-insensitive by
 * construction, which is what the sign-in lookup does too.
 */
function loginIdentifierKey(string $identifier): string
{
    $identifier = trim($identifier);

    if (function_exists('mb_strtolower')) {
        $identifier = mb_strtolower($identifier, 'UTF-8');
    } else {
        $identifier = strtolower($identifier);
    }

    return hash('sha256', $identifier);
}

/**
 * The key of the source counter.
 *
 * The key is the address that OPENED the connection. That is the one
 * value on the path that the visitor cannot choose, so it is the only one
 * that can serve as a throttle key.
 *
 * The address of the connection is NOT simply $_SERVER['REMOTE_ADDR']:
 * LiteSpeed — like every other server with a "use the client IP from the
 * header" option — REWRITES REMOTE_ADDR with the first element of
 * X-Forwarded-For (or with X-Real-IP) whenever the request arrives from a
 * trusted peer, and the loopback interface is trusted by default. After
 * that rewrite REMOTE_ADDR is whatever the visitor typed in the header:
 * a throttle key the visitor picks is an attacker's dial, and it hands
 * the visitor the right to choose their own bucket — or to fill somebody
 * else's.
 *
 * LiteSpeed keeps the address that really connected in
 * $_SERVER['PROXY_REMOTE_ADDR'], and leaves it unset when it folded
 * nothing. So the peer is PROXY_REMOTE_ADDR when it is there, and
 * REMOTE_ADDR otherwise — on a bare install, and on any server that does
 * not rewrite the address, the two are the same value.
 *
 * Behind a reverse proxy the peer is the PROXY's address, so every
 * visitor shares one bucket. That is exactly why the source counter is
 * never the only counter — the identifier counter is the primary defence,
 * and the effective penalty is the stricter of the two, never the sum.
 */
function loginSourceKey(): string
{
    $peer = trim((string) ($_SERVER['PROXY_REMOTE_ADDR'] ?? ''));

    if ($peer === '') {
        $peer = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    return hash('sha256', $peer);
}

/**
 * A moment of the throttle table, in seconds.
 *
 * The table stores UTC, so the parse has to say UTC too — otherwise the
 * wait would be off by the server's own offset.
 */
function loginTimestamp(?string $value): int
{
    if ($value === null || $value === '') {
        return 0;
    }

    $timestamp = strtotime($value . ' UTC');

    return $timestamp === false ? 0 : $timestamp;
}

/**
 * The row of ONE counter, as it is stored.
 *
 * @return array{attempts: int, locked_until: ?string, last_failure_at: ?string}
 */
function loginCounterRow(string $scope, string $key): array
{
    $row = Database::getInstance()->fetchOne(
        'SELECT failed_attempts, locked_until, last_failure_at
           FROM login_attempts
          WHERE scope = ? AND key_hash = ?',
        [$scope, $key]
    );

    if (!is_array($row)) {
        return ['attempts' => 0, 'locked_until' => null, 'last_failure_at' => null];
    }

    return [
        'attempts'        => (int) $row['failed_attempts'],
        'locked_until'    => $row['locked_until'] !== null ? (string) $row['locked_until'] : null,
        'last_failure_at' => $row['last_failure_at'] !== null ? (string) $row['last_failure_at'] : null,
    ];
}

/**
 * ONE counter, with the decay applied.
 *
 * A failure older than the quiet window does not count. When the counter
 * has been quiet for longer than the window, the row is reset and the
 * failures are gone — which is what lets the owner of an account sign in
 * again after the attacker stops.
 *
 * A row that is SERVING a wait is never reset: the count is what decides
 * the length of the NEXT wait, and resetting it mid-wait would shorten the
 * penalty the attacker has already earned.
 *
 * @return array{attempts: int, locked_until: ?string}
 */
function loginCounterState(string $scope, string $key, int $windowMinutes): array
{
    $row = loginCounterRow($scope, $key);

    $lockedUntil = $row['locked_until'];
    $serving     = $lockedUntil !== null && loginTimestamp($lockedUntil) > time();

    if ($serving) {
        return ['attempts' => $row['attempts'], 'locked_until' => $lockedUntil];
    }

    $last = $row['last_failure_at'];

    if ($last !== null && loginTimestamp($last) > time() - ($windowMinutes * 60)) {
        // Still inside the quiet window: the failures count.
        return ['attempts' => $row['attempts'], 'locked_until' => null];
    }

    // Quiet for longer than the window: the failures have decayed and the
    // counter starts again from zero.
    if ($row['attempts'] !== 0 || $lockedUntil !== null) {
        Database::getInstance()->execute(
            'UPDATE login_attempts
                SET failed_attempts = 0, locked_until = NULL
              WHERE scope = ? AND key_hash = ?',
            [$scope, $key]
        );
    }

    return ['attempts' => 0, 'locked_until' => null];
}

/**
 * The length of the wait a counter serves.
 *
 * The wait GROWS with the number of failures: at the limit it is the base
 * window, and every failure beyond that doubles it, up to the cap. Below
 * the limit there is no wait at all — the FIRST failure costs nothing.
 */
function loginBackoffMinutes(int $attempts, int $limit, int $baseMinutes, int $capMinutes): int
{
    if ($attempts < $limit) {
        return 0;
    }

    // The exponent is capped: a counter that reached a thousand must not
    // overflow the multiplication.
    $steps   = $attempts - $limit;
    $minutes = $baseMinutes * (2 ** min($steps, 10));

    return (int) min($capMinutes, $minutes);
}

/**
 * The throttle state of an identifier.
 *
 * TWO counters: the failures against the IDENTIFIER — the defence against
 * a password guesser, and the reason a name that matches no account is
 * throttled too — and the failures against the SOURCE, which bound the
 * attacker who rotates the identifier.
 *
 * The effective penalty is the STRICTER of the two, NEVER the sum. A
 * visitor behind a NAT shares the source bucket with everybody else, so
 * the source counter may never be the only one and may never add to the
 * identifier's.
 *
 * @return array{
 *     locked: bool,
 *     locked_until: ?string,
 *     attempts: int,
 *     source_attempts: int,
 *     wait_minutes: int
 * }
 */
function loginThrottleState(string $identifier): array
{
    $identifierCounter = loginCounterState(
        'identifier',
        loginIdentifierKey($identifier),
        (int) LOGIN_LOCK_MINUTES
    );

    $sourceCounter = loginCounterState(
        'source',
        loginSourceKey(),
        (int) LOGIN_SOURCE_WINDOW_MINUTES
    );

    // The stricter of the two: the wait that ends LAST is the one served.
    $until = null;

    foreach ([$identifierCounter['locked_until'], $sourceCounter['locked_until']] as $candidate) {
        if ($candidate === null) {
            continue;
        }

        if ($until === null || loginTimestamp($candidate) > loginTimestamp($until)) {
            $until = $candidate;
        }
    }

    $waitMinutes = 0;

    if ($until !== null) {
        $remaining   = loginTimestamp($until) - time();
        $waitMinutes = $remaining > 0 ? (int) ceil($remaining / 60) : 0;
    }

    return [
        'locked'          => $until !== null,
        'locked_until'    => $until,
        'attempts'        => $identifierCounter['attempts'],
        'source_attempts' => $sourceCounter['attempts'],
        'wait_minutes'    => $waitMinutes,
    ];
}

/**
 * Raise ONE counter by one failure.
 *
 * The count is raised IN SQL — `failed_attempts = failed_attempts + 1` —
 * so two failures that land together raise the counter by TWO and not by
 * one. The old code read the count, added one in PHP and wrote the total
 * back, which loses a count under concurrency; the comment that claimed
 * the opposite was wrong (issue-021 §5).
 *
 * The wait is derived from the POST-INCREMENT value, which is why the row
 * is read back after the upsert.
 */
function loginRaiseCounter(string $scope, string $key, int $limit, int $windowMinutes): void
{
    $db = Database::getInstance();

    $now = utcNow()->format('Y-m-d H:i:s');

    // The decay first: a failure older than the quiet window does not
    // count, so the count starts again from zero.
    loginCounterState($scope, $key, $windowMinutes);

    $db->execute(
        'INSERT INTO login_attempts
            (scope, key_hash, failed_attempts, locked_until, last_failure_at)
              VALUES (?, ?, 1, NULL, ?)
         ON DUPLICATE KEY UPDATE
              failed_attempts = failed_attempts + 1,
              last_failure_at = VALUES(last_failure_at)',
        [$scope, $key, $now]
    );

    // The row the upsert just wrote — the post-increment value.
    $row = $db->fetchOne(
        'SELECT failed_attempts FROM login_attempts WHERE scope = ? AND key_hash = ?',
        [$scope, $key]
    );

    $attempts = is_array($row) ? (int) $row['failed_attempts'] : 1;

    $minutes    = loginBackoffMinutes($attempts, $limit, (int) LOGIN_LOCK_MINUTES, (int) LOGIN_BACKOFF_MAX_MINUTES);
    $lockedUntil = null;

    if ($minutes > 0) {
        $lockedUntil = utcNow()
            ->modify('+' . $minutes . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    $db->execute(
        'UPDATE login_attempts
            SET locked_until = ?
          WHERE scope = ? AND key_hash = ?',
        [$lockedUntil, $scope, $key]
    );
}

/**
 * Record one failed sign-in.
 *
 * BOTH counters are raised: the one against the identifier and the one
 * against the source.
 */
function recordLoginFailure(string $identifier): void
{
    loginRaiseCounter(
        'identifier',
        loginIdentifierKey($identifier),
        (int) LOGIN_ATTEMPT_LIMIT,
        (int) LOGIN_LOCK_MINUTES
    );

    loginRaiseCounter(
        'source',
        loginSourceKey(),
        (int) LOGIN_SOURCE_ATTEMPT_LIMIT,
        (int) LOGIN_SOURCE_WINDOW_MINUTES
    );

    // The housekeeping: the rows that can no longer produce a wait go.
    pruneLoginAttempts();
}

/**
 * Clear the throttle after a successful sign-in.
 *
 * BOTH counters are cleared: the failures against the identifier and the
 * failures against the source (issue-021 §8.1). A success is the proof
 * that the person at the keyboard knows the password, so the failures that
 * piled up behind it are meaningless.
 */
function clearLoginFailures(string $identifier): void
{
    $db = Database::getInstance();

    $db->execute(
        'DELETE FROM login_attempts WHERE scope = ? AND key_hash = ?',
        ['identifier', loginIdentifierKey($identifier)]
    );

    $db->execute(
        'DELETE FROM login_attempts WHERE scope = ? AND key_hash = ?',
        ['source', loginSourceKey()]
    );
}

/**
 * Clear the failures against ONE identifier and leave the source counter
 * alone.
 *
 * The password change in /settings uses this one: the visitor proved they
 * know the current password, so the failures against that account's
 * identifiers are forgiven — but the failures from the source address are
 * shared with everybody else and are not theirs to clear.
 */
function clearLoginIdentifierFailures(string $identifier): void
{
    Database::getInstance()->execute(
        'DELETE FROM login_attempts WHERE scope = ? AND key_hash = ?',
        ['identifier', loginIdentifierKey($identifier)]
    );
}

/**
 * Remove the rows that can no longer produce a wait.
 *
 * A row whose wait is over AND whose last failure is older than the quiet
 * window is dead weight — it can never lock anything again. Without this
 * the table grows at the rate of the network: one row per identifier an
 * attacker ever typed, kept forever (issue-021 §7).
 *
 * The delete is bounded, and the probe in front of it keeps the statement
 * off the hot path: while nothing is stale the probe answers without
 * touching a row.
 */
function pruneLoginAttempts(): void
{
    $db = Database::getInstance();

    $identifierWindow = (int) LOGIN_LOCK_MINUTES;
    $sourceWindow    = (int) LOGIN_SOURCE_WINDOW_MINUTES;

    $stale = $db->fetchOne(
        'SELECT COUNT(*) AS stale
           FROM login_attempts
          WHERE (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())
            AND last_failure_at IS NOT NULL
            AND (
                 (scope = ? AND last_failure_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()))
              OR (scope = ? AND last_failure_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()))
            )',
        ['identifier', -$identifierWindow, 'source', -$sourceWindow]
    );

    if (!is_array($stale) || (int) $stale['stale'] === 0) {
        return;
    }

    $db->execute(
        'DELETE FROM login_attempts
          WHERE (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())
            AND last_failure_at IS NOT NULL
            AND (
                 (scope = ? AND last_failure_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()))
              OR (scope = ? AND last_failure_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()))
            )
          LIMIT 100',
        ['identifier', -$identifierWindow, 'source', -$sourceWindow]
    );
}

// ---------------------------------------------------------------------------
// The generic request throttle (issue-022 §7.2)
//
// The sign-in throttle above is a SPECIALISED counter: it counts FAILURES
// and it serves a wait that grows. The reset and the registration screens
// need a simpler thing — "how many requests has this key made in the last
// hour" — and they need it for keys that match NO account.
//
// That is the whole point: a limiter that only counts against an existing
// record is bypassable by typing something that does not exist. The reset
// screen therefore counts against the SUBMITTED ADDRESS (known or not) and
// against the SOURCE, and the registration screen counts against the
// source.
// ---------------------------------------------------------------------------

if (!defined('RESET_ADDRESS_LIMIT')) {
    /**
     * The reset requests ONE address may make in a window (issue-022 §7.2).
     *
     * Three an hour: enough for a person who forgot their password twice,
     * and far too few for a mail cannon. The counter is raised for a
     * KNOWN address and for an UNKNOWN one alike, so the limit itself
     * never says whether the address exists.
     */
    define('RESET_ADDRESS_LIMIT', 3);

    /** The window of the per-address reset counter, in minutes. */
    define('RESET_ADDRESS_WINDOW_MINUTES', 60);

    /**
     * The reset requests ONE source may make in a window (issue-022 §5).
     *
     * Higher than the per-address limit on purpose: a visitor behind a
     * NAT shares the bucket, and the per-address counter is the one that
     * stops the cannon. The source counter stops the spray.
     */
    define('RESET_SOURCE_LIMIT', 20);

    /** The window of the per-source reset counter, in minutes. */
    define('RESET_SOURCE_WINDOW_MINUTES', 60);

    /**
     * The registrations ONE source may make in a window (issue-022 §4).
     *
     * Ten an hour. Every one of them creates an account and a
     * confirmation message, so the cost per request is real.
     */
    define('REGISTER_SOURCE_LIMIT', 10);

    /** The window of the per-source registration counter, in minutes. */
    define('REGISTER_SOURCE_WINDOW_MINUTES', 60);

    /**
     * How long a throttle row is kept after its window closed, in
     * minutes.
     *
     * It is LONGER than every limiter window on purpose: a row that is
     * pruned while its window is still open would lose the count and
     * weaken the limiter. A day is long enough to keep the table small
     * and short enough that a dead row does not linger.
     */
    define('REQUEST_THROTTLE_RETENTION_MINUTES', 1440);
}

/**
 * Is the generic request throttle switched on?
 *
 * The kill switch is a CONFIGURATION constant (REQUEST_THROTTLE_ENABLED in
 * config.php), not a product rule: it is the way an operator turns the
 * limiter off if it ever misfires (issue-022 §10). The default is ON, and
 * only a REAL boolean false turns it off — the string 'false' leaves the
 * limiter on, because a limiter that fails open is the wrong way to fail.
 */
function requestThrottleEnabled(): bool
{
    if (!defined('REQUEST_THROTTLE_ENABLED')) {
        return true;
    }

    return constant('REQUEST_THROTTLE_ENABLED') !== false;
}

/**
 * The key of a request throttle.
 *
 * The sha256 of what is being counted, lower-cased and trimmed first: the
 * table never stores what the visitor typed, and the hash is
 * case-insensitive by construction, so "A@Example.test" and
 * "a@example.test" share one counter.
 */
function requestThrottleKey(string $value): string
{
    return hash('sha256', strtolower(trim($value)));
}

/**
 * The key of a SOURCE request throttle.
 *
 * The same peer key the sign-in throttle uses — the address that really
 * opened the connection, never the one the visitor typed into a header.
 * A limiter whose key the visitor chooses is a dial the visitor turns
 * (issue-021 §4, issue-022 §7.2).
 */
function requestSourceKey(): string
{
    return loginSourceKey();
}

/**
 * The state of ONE request counter.
 *
 * The count DECAYS: a counter whose window closed reads zero, so an
 * attempt that happened yesterday costs nothing today. The window is not
 * shortened while it is open, so the retry-after is the real one.
 *
 * @return array{hits: int, blocked: bool, retry_after: int}
 */
function requestCounterState(string $bucket, string $key, int $limit, int $windowMinutes): array
{
    $row = Database::getInstance()->fetchOne(
        'SELECT hits, window_started_at
           FROM request_throttles
          WHERE bucket = ? AND key_hash = ?',
        [$bucket, $key]
    );

    if (!is_array($row)) {
        return ['hits' => 0, 'blocked' => false, 'retry_after' => 0];
    }

    $started = strtotime((string) $row['window_started_at'] . ' UTC');

    if ($started === false) {
        $started = time();
    }

    // The window has closed: the count has decayed away.
    if ($started <= time() - ($windowMinutes * 60)) {
        return ['hits' => 0, 'blocked' => false, 'retry_after' => 0];
    }

    $hits = (int) $row['hits'];

    if ($hits < $limit) {
        return ['hits' => $hits, 'blocked' => false, 'retry_after' => 0];
    }

    return [
        'hits'        => $hits,
        'blocked'     => true,
        'retry_after' => max(1, ($started + ($windowMinutes * 60)) - time()),
    ];
}

/**
 * The state of a SET of request limiters.
 *
 * The stricter of the counters wins: the wait that ends LAST is the one
 * served, and a counter that is not blocked never shortens the wait of
 * the one that is. Never the sum — the same rule the sign-in throttle
 * follows (issue-021 §8.1).
 *
 * @param array<int, array{0: string, 1: string, 2: int, 3: int}> $limiters
 *        bucket, key, limit, window minutes — one entry per counter.
 *
 * @return array{blocked: bool, retry_after: int, hits: int}
 */
function requestThrottleState(array $limiters): array
{
    $blocked    = false;
    $retryAfter = 0;
    $hits       = 0;

    foreach ($limiters as $limiter) {
        [$bucket, $key, $limit, $windowMinutes] = $limiter;

        $state = requestCounterState($bucket, $key, $limit, $windowMinutes);

        $hits += $state['hits'];

        if ($state['blocked']) {
            $blocked = true;

            $retryAfter = max($retryAfter, $state['retry_after']);
        }
    }

    return ['blocked' => $blocked, 'retry_after' => $retryAfter, 'hits' => $hits];
}

/**
 * Raise ONE request counter by one hit.
 *
 * The count is raised IN SQL — `hits = hits + 1` — so two hits that land
 * together raise the counter by two and not by one. The window is reset
 * in the same statement: a hit that arrives after the window closed
 * starts a new one and the count goes back to 1, which is the decay.
 *
 * The two assignments are ORDERED so that both read the OLD
 * window_started_at: the first one never writes that column, so the
 * second one still sees the value the row had. The order therefore does
 * not matter, and the statement is one round trip — the whole limiter is
 * one statement per counter (issue-022 §11).
 */
function raiseRequestCounter(string $bucket, string $key, int $windowMinutes): void
{
    $db = Database::getInstance();

    $db->execute(
        'INSERT INTO request_throttles
            (bucket, key_hash, hits, window_started_at)
              VALUES (?, ?, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
              hits = IF(window_started_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()), 1, hits + 1),
              window_started_at = IF(window_started_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()), UTC_TIMESTAMP(), window_started_at)',
        [$bucket, $key, -$windowMinutes, -$windowMinutes]
    );
}

/**
 * Take one unit of quota from a set of counters.
 *
 * The counters are CONSULTED first and RAISED afterwards, and a request
 * that was refused does NOT raise ANY of them: the window is not extended
 * by the requests that were turned away — not by the counter that turned
 * them away, and not by the other counters of the set either. So exactly
 * `limit` requests are served per window, and the surplus is refused with
 * a 429 (issue-022 §7.2).
 *
 * This is why the callers pass the WHOLE set in one call. Two calls — one
 * per counter — would raise the first counter for a request the second
 * counter refused, and a visitor behind a shared address would then lock
 * out everybody else along with them.
 *
 * The refusal happens HERE, on the request path, before anything is
 * queued — the refusal is what stops the mail cannon, so it cannot be
 * left to the worker.
 *
 * @param array<int, array{0: string, 1: string, 2: int, 3: int}> $limiters
 *
 * @return array{blocked: bool, retry_after: int, hits: int}
 */
function takeRequestQuota(array $limiters): array
{
    if (!requestThrottleEnabled()) {
        return ['blocked' => false, 'retry_after' => 0, 'hits' => 0];
    }

    $state = requestThrottleState($limiters);

    if ($state['blocked']) {
        return $state;
    }

    foreach ($limiters as $limiter) {
        [$bucket, $key, , $windowMinutes] = $limiter;

        raiseRequestCounter($bucket, $key, $windowMinutes);
    }

    return $state;
}

/**
 * The limiters of the reset screen: the source.
 *
 * The source limiter bounds the SPRAY — the attacker who walks through a
 * list of addresses, one request each.
 *
 * @return array<int, array{0: string, 1: string, 2: int, 3: int}>
 */
function resetSourceLimiters(): array
{
    return [
        [
            'reset:source',
            requestSourceKey(),
            (int) RESET_SOURCE_LIMIT,
            (int) RESET_SOURCE_WINDOW_MINUTES,
        ],
    ];
}

/**
 * The limiters of the reset screen: the submitted address.
 *
 * The address limiter is keyed on the SUBMITTED address, so it counts a
 * known address and an unknown one alike — the ATTEMPT is what costs a
 * mail, and the attempt is what the attacker controls. It is read
 * TOGETHER with the source limiter, in one `takeRequestQuota()` call, so
 * that a refusal by either of them raises neither: the source can be
 * refused without touching the address's counter, and the other way round.
 *
 * @return array<int, array{0: string, 1: string, 2: int, 3: int}>
 */
function resetAddressLimiters(string $email): array
{
    return [
        [
            'reset:address',
            requestThrottleKey($email),
            (int) RESET_ADDRESS_LIMIT,
            (int) RESET_ADDRESS_WINDOW_MINUTES,
        ],
    ];
}

/**
 * The limiters of the registration screen: the source.
 *
 * There is no per-address limiter here: a registration is refused anyway
 * when the address is taken, and the per-source limiter is what bounds
 * the accounts an attacker can create.
 *
 * @return array<int, array{0: string, 1: string, 2: int, 3: int}>
 */
function registerRequestLimiters(): array
{
    return [
        [
            'register:source',
            requestSourceKey(),
            (int) REGISTER_SOURCE_LIMIT,
            (int) REGISTER_SOURCE_WINDOW_MINUTES,
        ],
    ];
}

/**
 * Remove the rows whose window closed long ago.
 *
 * Without this the table grows at the rate of the network: one row per
 * address anybody ever typed, kept forever. The delete is bounded, and
 * the probe in front of it keeps the statement off the hot path.
 *
 * The retention window is LONGER than every limiter window, so a row is
 * never pruned while its counter is still counting.
 */
function pruneRequestThrottles(): void
{
    $db = Database::getInstance();

    $stale = $db->fetchOne(
        'SELECT COUNT(*) AS stale
           FROM request_throttles
          WHERE window_started_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP())',
        [-(int) REQUEST_THROTTLE_RETENTION_MINUTES]
    );

    if (!is_array($stale) || (int) $stale['stale'] === 0) {
        return;
    }

    $db->execute(
        'DELETE FROM request_throttles
          WHERE window_started_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP())
          LIMIT 100',
        [-(int) REQUEST_THROTTLE_RETENTION_MINUTES]
    );
}

// ---------------------------------------------------------------------------
// The tokens (issue-016 §7, §9 and §10)
// ---------------------------------------------------------------------------

/**
 * Issue a password-reset token and return the RAW token.
 *
 * The raw token is never stored: only its SHA-256 hash goes into the
 * database, so a dump of the table yields no working reset link. Every
 * unused row of the account is deleted first — one live link at a time.
 */
function createPasswordReset(int $userId): string
{
    $db = Database::getInstance();

    $db->execute(
        'DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL',
        [$userId]
    );

    $token = bin2hex(random_bytes(32));

    $expiresAt = utcNow()
        ->modify('+' . RESET_TOKEN_TTL_MINUTES . ' minutes')
        ->format('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
              VALUES (?, ?, ?)',
        [$userId, hash('sha256', $token), $expiresAt]
    );

    return $token;
}

/**
 * Find a live reset row by its raw token.
 *
 * The lookup filters on `used_at IS NULL` and `expires_at > NOW()`, and
 * the comparison of the hash is constant-time.
 */
function findPasswordReset(string $token): ?array
{
    $token = trim($token);

    if ($token === '') {
        return null;
    }

    $hash = hash('sha256', $token);

    $row = Database::getInstance()->fetchOne(
        'SELECT id, user_id, token_hash, expires_at
           FROM password_resets
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
          LIMIT 1',
        [$hash]
    );

    if ($row === false || !hash_equals((string) $row['token_hash'], $hash)) {
        return null;
    }

    return $row;
}

/**
 * Redeem a reset token and set the new password.
 *
 * The token is marked used in the SAME transaction as the password change,
 * so two concurrent redemptions cannot both succeed. The row is locked for
 * the duration, which is what serialises them.
 *
 * @return array{status: string, user_id?: int} status is 'ok', 'invalid' or 'error'
 */
function consumePasswordReset(string $token, string $newPassword): array
{
    $hash = hash('sha256', trim($token));

    $pdo = Database::getInstance()->getConnection();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, token_hash
               FROM password_resets
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
              LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        if ($row === false || !hash_equals((string) $row['token_hash'], $hash)) {
            $pdo->rollBack();

            return ['status' => 'invalid'];
        }

        $userId = (int) $row['user_id'];

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $update = $pdo->prepare(
            'UPDATE users
                SET password_hash = ?, updated_at = NOW()
              WHERE id = ?'
        );
        $update->execute([$newHash, $userId]);

        $use = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
        $use->execute([(int) $row['id']]);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        logFailure('auth: the password reset failed', $ex);

        return ['status' => 'error'];
    }

    return ['status' => 'ok', 'user_id' => $userId, 'password_hash' => $newHash];
}

/**
 * Issue an account-confirmation token and return the RAW token.
 *
 * As with the reset token, only the SHA-256 hash is stored. The token is
 * valid for 24 hours.
 */
function createEmailVerification(int $userId): string
{
    $db = Database::getInstance();

    $db->execute(
        'DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL',
        [$userId]
    );

    $token = bin2hex(random_bytes(32));

    $expiresAt = utcNow()
        ->modify('+' . VERIFICATION_TOKEN_TTL_MINUTES . ' minutes')
        ->format('Y-m-d H:i:s');

    $db->execute(
        'INSERT INTO email_verifications (user_id, token_hash, expires_at)
              VALUES (?, ?, ?)',
        [$userId, hash('sha256', $token), $expiresAt]
    );

    return $token;
}

/**
 * Redeem an account-confirmation token.
 *
 * @return int|null the user id, or null when the token is not usable
 */
function consumeEmailVerification(string $token): ?int
{
    $hash = hash('sha256', trim($token));

    $pdo = Database::getInstance()->getConnection();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, token_hash
               FROM email_verifications
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
              LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        if ($row === false || !hash_equals((string) $row['token_hash'], $hash)) {
            $pdo->rollBack();

            return null;
        }

        $userId = (int) $row['user_id'];

        $use = $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?');
        $use->execute([(int) $row['id']]);

        $confirm = $pdo->prepare(
            'UPDATE users
                SET email_verified_at = NOW(), updated_at = NOW()
              WHERE id = ? AND email_verified_at IS NULL'
        );
        $confirm->execute([$userId]);

        $clean = $pdo->prepare(
            'DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL'
        );
        $clean->execute([$userId]);

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        logFailure('auth: the e-mail verification failed', $ex);

        return null;
    }

    return $userId;
}

// ---------------------------------------------------------------------------
// The session and the credentials (issue-016 §5 and §10)
// ---------------------------------------------------------------------------

/**
 * The version of the credentials a session was opened with.
 *
 * A password change must end every other session. The schema has no
 * sessions table, so the version is derived from the stored password hash:
 * the hash itself never enters the session, only its digest.
 */
function credentialVersion(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

/**
 * The credential version of an account right now.
 *
 * The result is cached for the request. Pass $fresh = true after a password
 * change, so the cache cannot hand out the version of the old hash.
 */
function currentCredentialVersion(?int $userId = null, bool $fresh = false): ?string
{
    static $cache = [];

    $id = $userId ?? getCurrentUserId();

    if ($id === null) {
        return null;
    }

    if ($fresh || !array_key_exists($id, $cache)) {
        $row = Database::getInstance()->fetchOne(
            'SELECT password_hash FROM users WHERE id = ?',
            [$id]
        );

        $cache[$id] = ($row === false || !isset($row['password_hash']))
            ? null
            : credentialVersion((string) $row['password_hash']);
    }

    return $cache[$id];
}

/**
 * Is the session still valid for the credentials it was opened with?
 *
 * A password change ends every other session: the version in the session no
 * longer matches the stored hash. The session loses its identity here; the
 * front controller turns that into a redirect to the sign-in page.
 */
function sessionCredentialsAreCurrent(): bool
{
    $id = getCurrentUserId();

    if ($id === null) {
        return true;
    }

    $current = currentCredentialVersion($id);

    if ($current === null) {
        // The account is gone — the session points at nothing.
        unset($_SESSION['user_id'], $_SESSION['credential_version']);

        return false;
    }

    $stored = $_SESSION['credential_version'] ?? null;

    if (!is_string($stored) || $stored === '') {
        // A session opened before the version was recorded adopts the current
        // one. From here on a password change ends it.
        $_SESSION['credential_version'] = $current;

        return true;
    }

    if (!hash_equals($current, $stored)) {
        unset($_SESSION['user_id'], $_SESSION['credential_version']);

        return false;
    }

    return true;
}

/**
 * The route the visitor was heading for before the guard sent them to the
 * sign-in page.
 *
 * The target must be a KNOWN route. An arbitrary path would be an open
 * redirect, and the route table is the only list of paths the product
 * answers to.
 */
function consumeIntendedUrl(): string
{
    startSession();

    $intended = $_SESSION['intended_url'] ?? null;

    unset($_SESSION['intended_url']);

    if (!is_string($intended) || $intended === '') {
        return '/dashboard';
    }

    $path = safeRedirectTarget($intended);

    // Strip the query string: only the path is matched against the routes.
    $pathOnly = (string) strtok($path, '?');

    // The guest screens are never a destination. A visitor who was sent to
    // the sign-in page does not want to land on it again after signing in.
    $guestRoutes = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email'];

    foreach (Router::defaultRoutes() as [$method, $routePath, $handler]) {
        if ($method !== 'GET') {
            continue;
        }

        if (in_array($routePath, $guestRoutes, true)) {
            continue;
        }

        if ($routePath === $pathOnly) {
            return $path;
        }
    }

    return '/dashboard';
}
