<?php
/**
 * register.php — the registration screen, and the closed-registration
 * screen.
 *
 * The contract (docs/issues/issue-016.md, §7 and §8):
 *
 *   1. The gate is TWO-SIDED. When ALLOW_REGISTRATION is false, the GET
 *      answers 403 and renders the closed screen with no form at all, and
 *      the POST answers 403 independently — before the CSRF token is read
 *      and before any input is looked at. A check in only one of the two
 *      places is a bug.
 *   2. The format is validated before the uniqueness, so an invalid
 *      username never reports "already taken".
 *   3. The username-taken message says nothing about the email address.
 *      Confirming whether an address exists is an enumeration oracle.
 *   4. The account is created either way — the confirmation mail is a
 *      courtesy, not a condition of the account.
 *   5. The success state REPLACES the form and does not sign the new user
 *      in. The copy must not promise a session the server does not create.
 */

declare(strict_types=1);

$title = 'Create an account';
$shell = 'auth';
$pageCss = 'auth.css';

$alert = null;
$fieldErrors = [];
$values = [
    'username'              => '',
    'email'                 => '',
    'email_confirmation'     => '',
    'password_confirmation' => '',
];
$created = false;
$createdEmail = '';
$mailState = '';       // '' | 'sent' | 'not-configured' | 'failed'

$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';

// ---------------------------------------------------------------------------
// The gate — the back-end side.
//
// It comes FIRST: a rejected request does no work. It is before the CSRF
// token and before any input is read.
// ---------------------------------------------------------------------------
if (!registrationAllowed()) {
    http_response_code(403);

    $title = 'Registration is closed';

    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
        <h2>Registration is closed</h2>

        <p>
            This tracker is currently in private use, so new accounts cannot be
            created.
        </p>

        <p>
            If you already have an account, sign in as usual. If you do not,
            ask the person who runs this installation for an account.
        </p>

        <a class="btn btn--primary btn--block" href="/login">Sign in</a>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';

    return;
}

// A signed-in visitor has no business on this page.
if (isLoggedIn()) {
    redirect('/dashboard');
}

if ($isPost) {
    // The CSRF token is validated before a single byte of input is read.
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;

    if (!validateCsrfToken($token)) {
        $alert = [
            'kind'  => 'danger',
            'title' => 'Your session could not be verified. Reload the page and submit the form again.',
        ];
    } else {
        $values['username'] = trim((string) ($_POST['username'] ?? ''));
        $values['email'] = trim((string) ($_POST['email'] ?? ''));
        $values['email_confirmation'] = trim((string) ($_POST['email_confirmation'] ?? ''));
        $values['password_confirmation'] = (string) ($_POST['password_confirmation'] ?? '');

        $password = (string) ($_POST['password'] ?? '');

        $fieldErrors = validateRegistrationInput([
            'username'              => $values['username'],
            'email'                 => $values['email'],
            'email_confirmation'     => $values['email_confirmation'],
            'password'              => $password,
            'password_confirmation' => $values['password_confirmation'],
        ]);

        if ($fieldErrors === []) {
            // The uniqueness check. The SELECT is the friendly message; the
            // UNIQUE constraint is the guard.
            if (usernameTaken($values['username'])) {
                $fieldErrors['username'] = 'That username is already taken.';
            } elseif (emailTaken($values['email'])) {
                $fieldErrors['email'] = 'That email address is already registered.';
            }
        }

        if ($fieldErrors === []) {
            try {
                $result = createUser($values['username'], $values['email'], $password);
            } catch (Throwable $ex) {
                error_log('[auth] the registration failed: ' . $ex->getMessage());

                $alert = [
                    'kind'  => 'danger',
                    'title' => 'Something went wrong on our side. Please try again.',
                ];

                $result = ['status' => 'error'];
            }

            if (($result['status'] ?? '') === 'duplicate') {
                // The constraint fired. The same messages as the SELECT
                // above, so a race reads like a check.
                if (usernameTaken($values['username'])) {
                    $fieldErrors['username'] = 'That username is already taken.';
                } elseif (emailTaken($values['email'])) {
                    $fieldErrors['email'] = 'That email address is already registered.';
                } else {
                    $alert = [
                        'kind'  => 'danger',
                        'title' => 'Something went wrong on our side. Please try again.',
                    ];
                }
            } elseif (($result['status'] ?? '') === 'ok') {
                $userId = (int) $result['user_id'];

                $created = true;
                $createdEmail = $values['email'];

                // The confirmation mail is sent AFTER the row is committed.
                $mailState = 'sent';

                if (!mailIsConfigured()) {
                    $mailState = 'not-configured';
                } else {
                    try {
                        $token = createEmailVerification($userId);
                        $link = absoluteUrl('/verify-email?token=' . urlencode($token));

                        $body = "Welcome to Financial Tracker.\n\n"
                            . "Confirm your address by opening this link:\n\n"
                            . $link . "\n\n"
                            . "The link is valid for 24 hours.\n\n"
                            . "If you did not create this account, you can ignore this message.\n";

                        if (!sendMail($values['email'], 'Confirm your account', $body)) {
                            $mailState = 'failed';
                        }
                    } catch (Throwable $ex) {
                        error_log('[auth] the confirmation mail failed: ' . $ex->getMessage());

                        $mailState = 'failed';
                    }
                }
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h2>Create an account</h2>

<?php if ($alert !== null): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--<?= e($alert['kind']) ?>">
            <div class="alert__body">
                <p class="alert__title"><?= e($alert['title']) ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($created): ?>
    <div role="status" aria-live="polite">
<?php if ($mailState === 'sent'): ?>
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title">Your account is ready.</p>
                <p>
                    We sent a confirmation message to
                    <strong><?= e($createdEmail) ?></strong>. Check your inbox to
                    confirm the address, then sign in and record your first
                    transaction.
                </p>
            </div>
        </div>
<?php elseif ($mailState === 'not-configured'): ?>
        <div class="alert alert--warning">
            <div class="alert__body">
                <p class="alert__title">Your account is ready.</p>
                <p>
                    Your account is ready, but the confirmation message could not
                    be sent — email is not configured for this installation. Ask
                    the person who runs this installation to confirm your
                    address.
                </p>
            </div>
        </div>
<?php else: ?>
        <div class="alert alert--warning">
            <div class="alert__body">
                <p class="alert__title">Your account is ready.</p>
                <p>
                    Your account was created, but the confirmation message could
                    not be sent. Please try again later or ask the person who
                    runs this installation to confirm your address.
                </p>
            </div>
        </div>
<?php endif; ?>
    </div>

    <a class="btn btn--primary btn--block" href="/login">Go to sign in</a>
<?php else: ?>
    <form method="post" action="/register">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="reg-user">Username</label>
            <span class="field__hint" id="reg-user-hint">3–32 characters. Letters, digits, underscore and hyphen.</span>
            <input class="input" id="reg-user" name="username" type="text"
                   autocomplete="username" value="<?= e($values['username']) ?>"
                   aria-describedby="reg-user-hint<?= isset($fieldErrors['username']) ? ' reg-user-msg' : '' ?>"
                   <?= isset($fieldErrors['username']) ? 'aria-invalid="true"' : '' ?>>
<?php if (isset($fieldErrors['username'])): ?>
            <span class="field__error" id="reg-user-msg"><?= e($fieldErrors['username']) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="reg-email">Email</label>
            <span class="field__hint" id="reg-email-hint">Used for account verification and the password reset link. It must be unique.</span>
            <input class="input" id="reg-email" name="email" type="email"
                   autocomplete="email" value="<?= e($values['email']) ?>"
                   aria-describedby="reg-email-hint<?= isset($fieldErrors['email']) ? ' reg-email-msg' : '' ?>"
                   <?= isset($fieldErrors['email']) ? 'aria-invalid="true"' : '' ?>>
<?php if (isset($fieldErrors['email'])): ?>
            <span class="field__error" id="reg-email-msg"><?= e($fieldErrors['email']) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="reg-email2">Confirm email</label>
            <span class="field__hint" id="reg-email2-hint">Type the same address again.</span>
            <input class="input" id="reg-email2" name="email_confirmation" type="email"
                   autocomplete="email" value="<?= e($values['email_confirmation']) ?>"
                   aria-describedby="reg-email2-hint<?= isset($fieldErrors['email_confirmation']) ? ' reg-email2-msg' : '' ?>"
                   <?= isset($fieldErrors['email_confirmation']) ? 'aria-invalid="true"' : '' ?>>
<?php if (isset($fieldErrors['email_confirmation'])): ?>
            <span class="field__error" id="reg-email2-msg"><?= e($fieldErrors['email_confirmation']) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="reg-pass">Password</label>
            <span class="field__hint" id="reg-pass-hint">At least 10 characters.</span>
            <input class="input" id="reg-pass" name="password" type="password"
                   autocomplete="new-password"
                   aria-describedby="reg-pass-hint<?= isset($fieldErrors['password']) ? ' reg-pass-msg' : '' ?>"
                   <?= isset($fieldErrors['password']) ? 'aria-invalid="true"' : '' ?>>
<?php if (isset($fieldErrors['password'])): ?>
            <span class="field__error" id="reg-pass-msg"><?= e($fieldErrors['password']) ?></span>
<?php else: ?>
            <p class="field__hint">
                <button type="button" class="btn btn--ghost btn--sm"
                        data-reveal-target="reg-pass" aria-pressed="false">Show</button>
            </p>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="reg-pass2">Confirm password</label>
            <input class="input" id="reg-pass2" name="password_confirmation" type="password"
                   autocomplete="new-password"
                   <?= isset($fieldErrors['password_confirmation']) ? 'aria-invalid="true" aria-describedby="reg-pass2-msg"' : '' ?>>
<?php if (isset($fieldErrors['password_confirmation'])): ?>
            <span class="field__error" id="reg-pass2-msg"><?= e($fieldErrors['password_confirmation']) ?></span>
<?php else: ?>
            <p class="field__hint">
                <button type="button" class="btn btn--ghost btn--sm"
                        data-reveal-target="reg-pass2" aria-pressed="false">Show</button>
            </p>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--block">Create account</button>
    </form>

    <div class="auth__links">
        <a href="/login">I already have an account</a>
    </div>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/../includes/footer.php';
