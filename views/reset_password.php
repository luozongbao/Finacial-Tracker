<?php
/**
 * reset_password.php — the "choose a new password" screen.
 *
 * There is no mockup for this screen (issue-016 §10): it is built from the
 * auth shell and the same field vocabulary as the registration form.
 *
 * The contract:
 *
 *   1. The token is compared AS A HASH. The lookup filters on
 *      `used_at IS NULL` and `expires_at > NOW()`, and the comparison of the
 *      hash is constant-time.
 *   2. The token is marked used in the SAME transaction as the password
 *      change, so two concurrent redemptions cannot both succeed.
 *   3. A password change ends every other session of the account: the
 *      credential version travels in the session, and the new hash gives it
 *      a new value.
 *   4. The link is single-use. A second visit sees "This reset link is no
 *      longer valid. Request a new one."
 */

declare(strict_types=1);

$title = 'Choose a new password';
$shell = 'auth';
$pageCss = 'auth.css';

$alert = null;
$fieldErrors = [];
$token = '';
$state = 'form';    // 'form' | 'invalid' | 'changed'

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    // The CSRF token is validated before a single byte of input is read.
    $csrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;

    if (!validateCsrfToken($csrf)) {
        $alert = [
            'kind'  => 'danger',
            'title' => 'Your session could not be verified. Reload the page and submit the form again.',
        ];

        $state = 'form';
    } else {
        $token = trim((string) ($_POST['token'] ?? ''));

        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

        $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

        if ($passwordLength < PASSWORD_MIN_LENGTH) {
            $fieldErrors['password'] = 'The password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
        } elseif ($passwordLength > PASSWORD_MAX_LENGTH) {
            $fieldErrors['password'] = 'The password may be at most ' . PASSWORD_MAX_LENGTH . ' characters long.';
        } elseif ($password !== $passwordConfirmation) {
            $fieldErrors['password_confirmation'] = 'The two passwords do not match.';
        }

        if ($fieldErrors === []) {
            $result = consumePasswordReset($token, $password);

            if (($result['status'] ?? '') === 'ok') {
                // The password is changed and the token is spent. The user is
                // signed in: they proved control of the mailbox.
                $userId = (int) $result['user_id'];

                loginUser($userId, credentialVersion((string) $result['password_hash']));

                $state = 'changed';
                $token = '';
            } elseif (($result['status'] ?? '') === 'invalid') {
                $state = 'invalid';
            } else {
                $alert = [
                    'kind'  => 'danger',
                    'title' => 'Something went wrong on our side. Please try again.',
                ];

                $state = 'form';
            }
        }
    }
} else {
    $token = trim((string) ($_GET['token'] ?? ''));

    // The token is checked before the form is rendered: a spent or expired
    // link never shows a password field.
    if ($token === '' || findPasswordReset($token) === null) {
        $state = 'invalid';
        $token = '';
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h2>Choose a new password</h2>

<?php if ($state === 'changed'): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title">Your password has been changed.</p>
                <p>You can now sign in with your new password.</p>
            </div>
        </div>
    </div>

    <a class="btn btn--primary btn--block" href="/login">Go to sign in</a>
<?php elseif ($state === 'invalid'): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">This reset link is no longer valid. Request a new one.</p>
            </div>
        </div>
    </div>

    <a class="btn btn--primary btn--block" href="/forgot-password">Request a new link</a>
<?php else: ?>
<?php if ($alert !== null): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--<?= e($alert['kind']) ?>">
            <div class="alert__body">
                <p class="alert__title"><?= e($alert['title']) ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

    <form method="post" action="/reset-password">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="field">
            <label class="field__label" for="rp-pass">New password</label>
            <span class="field__hint" id="rp-pass-hint">At least 10 characters.</span>
            <input class="input" id="rp-pass" name="password" type="password"
                   autocomplete="new-password"
                   aria-describedby="rp-pass-hint<?= isset($fieldErrors['password']) ? ' rp-pass-msg' : '' ?>"
                   <?= isset($fieldErrors['password']) ? 'aria-invalid="true"' : '' ?>>
<?php if (isset($fieldErrors['password'])): ?>
            <span class="field__error" id="rp-pass-msg"><?= e($fieldErrors['password']) ?></span>
<?php else: ?>
            <p class="field__hint">
                <button type="button" class="btn btn--ghost btn--sm"
                        data-reveal-target="rp-pass" aria-pressed="false">Show</button>
            </p>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="rp-pass2">Confirm password</label>
            <input class="input" id="rp-pass2" name="password_confirmation" type="password"
                   autocomplete="new-password"
                   <?= isset($fieldErrors['password_confirmation']) ? 'aria-invalid="true" aria-describedby="rp-pass2-msg"' : '' ?>>
<?php if (isset($fieldErrors['password_confirmation'])): ?>
            <span class="field__error" id="rp-pass2-msg"><?= e($fieldErrors['password_confirmation']) ?></span>
<?php else: ?>
            <p class="field__hint">
                <button type="button" class="btn btn--ghost btn--sm"
                        data-reveal-target="rp-pass2" aria-pressed="false">Show</button>
            </p>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--block">Set new password</button>
    </form>

    <div class="auth__links">
        <a href="/login">Back to sign in</a>
    </div>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/../includes/footer.php';
