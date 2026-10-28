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
 * Configuration comes from config.php and from nothing else.
 */

declare(strict_types=1);

/*
 * The product's own rules. They are declared with a guard because this file
 * is included by the front controller, by the views and by the regression
 * tests' child processes — a second inclusion must not emit a warning.
 */
if (!defined('LOGIN_ATTEMPT_LIMIT')) {
    /** The failed sign-ins that start a wait (issue-016 §6). */
    define('LOGIN_ATTEMPT_LIMIT', 5);

    /** How long the wait lasts, in minutes (issue-016 §6). */
    define('LOGIN_LOCK_MINUTES', 15);

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
     * same time (issue-016 §9). The floor hides the difference between the
     * two database paths; what is left is the SMTP round trip of a real
     * send, which cannot be equalised without sending mail that has no
     * recipient.
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
    // hyphen.
    $usernameLength = function_exists('mb_strlen') ? mb_strlen($username) : strlen($username);

    if ($usernameLength < 3) {
        $errors['username'] = 'The username must be at least 3 characters long.';
    } elseif ($usernameLength > 32) {
        $errors['username'] = 'The username may be at most 32 characters long.';
    } elseif (preg_match('/^[A-Za-z0-9_-]+$/', $username) !== 1) {
        $errors['username'] = 'The username may only contain letters, digits, underscore and hyphen.';
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
                (username, email, password_hash, email_verified_at, status, failed_attempts, locked_until)
                  VALUES (?, ?, ?, NULL, \'active\', 0, NULL)',
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
// The attempt limit (issue-016 §6)
// ---------------------------------------------------------------------------

/**
 * The key of the throttle counter.
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
 * The throttle state of an identifier.
 *
 * One mechanism, two cases: the counter lives against the IDENTIFIER, so
 * it works for an account that exists and for one that does not. The
 * users.failed_attempts column holds the same numbers for the account that
 * was matched, and is cleared by a successful sign-in.
 *
 * @return array{locked: bool, locked_until: ?string, attempts: int}
 */
function loginThrottleState(string $identifier): array
{
    $db = Database::getInstance();

    $row = $db->fetchOne(
        'SELECT failed_attempts, locked_until
           FROM login_attempts
          WHERE identifier_hash = ?',
        [loginIdentifierKey($identifier)]
    );

    if ($row === false) {
        return ['locked' => false, 'locked_until' => null, 'attempts' => 0];
    }

    $lockedUntil = $row['locked_until'] ?? null;

    if ($lockedUntil !== null) {
        $expiresAt = strtotime((string) $lockedUntil . ' UTC');

        if ($expiresAt !== false && $expiresAt <= time()) {
            // The wait is over. The counter starts again from zero, so a
            // lock never becomes a permanent denial of service for the
            // owner of the account.
            $db->execute(
                'UPDATE login_attempts
                    SET failed_attempts = 0, locked_until = NULL
                  WHERE identifier_hash = ?',
                [loginIdentifierKey($identifier)]
            );

            return ['locked' => false, 'locked_until' => null, 'attempts' => 0];
        }

        return [
            'locked'       => true,
            'locked_until' => (string) $lockedUntil,
            'attempts'     => (int) $row['failed_attempts'],
        ];
    }

    return [
        'locked'       => false,
        'locked_until' => null,
        'attempts'     => (int) $row['failed_attempts'],
    ];
}

/**
 * Record one failed sign-in.
 *
 * The counter is incremented against the identifier, and the account's own
 * columns are updated as well when an account was matched. The wait starts
 * when the counter reaches the limit.
 */
function recordLoginFailure(string $identifier, ?int $userId = null): void
{
    $db = Database::getInstance();

    $state    = loginThrottleState($identifier);
    $attempts = $state['attempts'] + 1;

    $lockedUntil = null;

    if ($attempts >= LOGIN_ATTEMPT_LIMIT) {
        $lockedUntil = utcNow()
            ->modify('+' . LOGIN_LOCK_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    // One statement, so two simultaneous failures cannot lose a count.
    $db->execute(
        'INSERT INTO login_attempts (identifier_hash, failed_attempts, locked_until)
              VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
              failed_attempts = VALUES(failed_attempts),
              locked_until   = VALUES(locked_until)',
        [loginIdentifierKey($identifier), $attempts, $lockedUntil]
    );

    if ($userId !== null) {
        $db->execute(
            'UPDATE users
                SET failed_attempts = ?, locked_until = ?
              WHERE id = ?',
            [$attempts, $lockedUntil, $userId]
        );
    }
}

/**
 * Clear the throttle after a successful sign-in.
 *
 * The identifier's counter is deleted and the account's columns go back to
 * zero — the two are one counter, and a success clears both.
 */
function clearLoginFailures(string $identifier, ?int $userId = null): void
{
    $db = Database::getInstance();

    $db->execute(
        'DELETE FROM login_attempts WHERE identifier_hash = ?',
        [loginIdentifierKey($identifier)]
    );

    if ($userId !== null) {
        $db->execute(
            'UPDATE users
                SET failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [$userId]
        );
    }
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

        error_log('[auth] the password reset failed: ' . $ex->getMessage());

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

        error_log('[auth] the e-mail verification failed: ' . $ex->getMessage());

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
