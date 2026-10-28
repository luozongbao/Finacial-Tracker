<?php
/**
 * login.php — the sign-in screen.
 *
 * The contract (docs/issues/issue-016.md, §5 and §6):
 *
 *   1. The screen never says WHICH half of the credentials was wrong. A
 *      missing account and a wrong password take the same branch and get
 *      the same message: "That username and password do not match."
 *   2. The account's status is checked AFTER the password, so a disabled
 *      account is not an oracle. It says "This account is not available."
 *   3. The attempt limit is enforced on the server: five failures, a
 *      fifteen-minute wait, counted against the IDENTIFIER so that a name
 *      which matches no account is throttled too.
 *   4. The CSRF token is validated BEFORE any input is read.
 *   5. The username field keeps its value after a failure; the password
 *      field is cleared.
 *
 * The screen is rendered on the auth shell — no app bar, no navigation, no
 * bottom bar. A guest must never see the signed-in navigation.
 */

declare(strict_types=1);

$title = 'Sign in';
$shell = 'auth';
$pageCss = 'auth.css';

// The state of the screen. $alert is null or ['kind' => ..., 'title' => ...];
// $fieldErrors maps a field name to its message.
$alert = null;
$fieldErrors = [];
$username = '';
$locked = false;

// A signed-in visitor has no business on this page.
if (isLoggedIn()) {
    redirect('/dashboard');
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    // The CSRF token is validated before a single byte of input is read.
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;

    if (!validateCsrfToken($token)) {
        $alert = [
            'kind'  => 'danger',
            'title' => 'Your session could not be verified. Reload the page and submit the form again.',
        ];
    } else {
        // The password is NOT trimmed: leading and trailing spaces are
        // legitimate characters of a password.
        $identifier = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        // The username field keeps its value; the password field does not.
        $username = $identifier;

        if ($identifier === '') {
            $fieldErrors['username'] = 'Enter your username or email.';
        }

        if ($password === '') {
            $fieldErrors['password'] = 'Enter your password.';
        }

        if ($fieldErrors === []) {
            // The lock is checked BEFORE the password: it is cheaper, and it
            // is what makes the limit effective.
            $throttle = loginThrottleState($identifier);

            if ($throttle['locked']) {
                $locked = true;
                $alert = [
                    'kind'  => 'warning',
                    'title' => 'Too many attempts. Try again in 15 minutes.',
                ];
            } else {
                $user = null;
                $lookupFailed = false;

                try {
                    $user = findUserByIdentifier($identifier);
                } catch (Throwable $ex) {
                    // The detail goes to the log. The page gets a generic
                    // sentence — never a SQL error.
                    error_log('[auth] the sign-in lookup failed: ' . $ex->getMessage());

                    $lookupFailed = true;
                }

                if ($lookupFailed) {
                    $alert = [
                        'kind'  => 'danger',
                        'title' => 'Something went wrong on our side. Please try again.',
                    ];
                } else {
                    $hash = $user !== null ? (string) $user['password_hash'] : null;

                    // A missing account is verified against the decoy hash, so
                    // the two cases cost the same time.
                    $passwordOk = verifyPasswordOrDecoy($password, $hash);

                    if (!$passwordOk) {
                        // ONE branch for both halves. The counter is raised
                        // against the identifier, with or without an account.
                        recordLoginFailure($identifier, $user !== null ? (int) $user['id'] : null);

                        $alert = [
                            'kind'  => 'danger',
                            'title' => 'That username and password do not match.',
                        ];
                    } elseif ((string) $user['status'] !== 'active') {
                        // The password was right and the account is not
                        // usable. The message says nothing about why.
                        $alert = [
                            'kind'  => 'danger',
                            'title' => 'This account is not available.',
                        ];
                    } else {
                        $userId = (int) $user['id'];
                        $storedHash = (string) $user['password_hash'];

                        // The algorithm improves with PHP; the hash does not
                        // have to. A re-hash changes the credential version,
                        // which ends the other sessions of this account — a
                        // one-off event when the default algorithm changes.
                        if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                            $storedHash = hashPassword($password);

                            Database::getInstance()->execute(
                                'UPDATE users SET password_hash = ? WHERE id = ?',
                                [$storedHash, $userId]
                            );
                        }

                        // A successful sign-in clears the counter.
                        clearLoginFailures($identifier, $userId);

                        // The privilege change: a new session id, a new CSRF
                        // token, and the version of the credentials.
                        loginUser($userId, credentialVersion($storedHash));

                        // The visitor lands where they were going — but only
                        // at a route the product knows.
                        redirect(consumeIntendedUrl());
                    }
                }
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h2>Sign in</h2>

<?php if ($alert !== null): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--<?= e($alert['kind']) ?>">
            <div class="alert__body">
                <p class="alert__title"><?= e($alert['title']) ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="login-user">Username or email</label>
            <span class="field__hint" id="login-user-hint">Either your username or your email address.</span>
            <input class="input" id="login-user" name="username" type="text"
                   autocomplete="username" value="<?= e($username) ?>"
                   aria-describedby="login-user-hint<?= isset($fieldErrors['username']) ? ' login-user-msg' : '' ?>"
                   <?= isset($fieldErrors['username']) ? 'aria-invalid="true"' : '' ?>>
<?php if (isset($fieldErrors['username'])): ?>
            <span class="field__error" id="login-user-msg"><?= e($fieldErrors['username']) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="login-pass">Password</label>
            <input class="input" id="login-pass" name="password" type="password"
                   autocomplete="current-password"
                   <?= isset($fieldErrors['password']) ? 'aria-invalid="true" aria-describedby="login-pass-msg"' : '' ?>>
<?php if (isset($fieldErrors['password'])): ?>
            <span class="field__error" id="login-pass-msg"><?= e($fieldErrors['password']) ?></span>
<?php else: ?>
            <p class="field__hint">
                <button type="button" class="btn btn--ghost btn--sm"
                        data-reveal-target="login-pass" aria-pressed="false">Show</button>
            </p>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--block"<?= $locked ? ' disabled' : '' ?>>Sign in</button>
    </form>

    <div class="auth__links">
        <a href="/forgot-password">Forgot password?</a>
<?php if (registrationAllowed()): ?>
        <a href="/register">Create an account</a>
<?php endif; ?>
    </div>
</div>
<?php
require __DIR__ . '/../includes/footer.php';
