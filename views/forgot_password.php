<?php
/**
 * forgot_password.php — the "send me a reset link" screen.
 *
 * The contract (docs/issues/issue-016.md, §9):
 *
 *   1. The answer is the SAME for a known address and for an unknown one —
 *      the same body, the same status code, the same time. Any difference
 *      between them is an account-enumeration leak.
 *   2. No row is created for an unknown address, and the address is never
 *      echoed back.
 *   3. The "mail is not configured" warning is a property of the
 *      installation, so it is shown to everyone — known address or not.
 *   4. The "the send failed" danger is shown ONLY when a real account
 *      matched. Otherwise an unknown address would get a different answer,
 *      and the screen would be an oracle.
 *   5. The token is a one-hour, single-use link. Only its SHA-256 hash is
 *      stored.
 */

declare(strict_types=1);

$title = 'Reset your password';
$shell = 'auth';
$pageCss = 'auth.css';

$alert = null;      // ['kind' => ..., 'title' => ...]
$fieldError = null; // the message under the email field
$email = '';
$state = 'form';    // 'form' | 'sent' | 'not-configured' | 'failed'

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    // The floor of the response time. The two branches of this screen must
    // not be told apart by how long they take.
    $startedAt = microtime(true);

    // The CSRF token is validated before a single byte of input is read.
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;

    if (!validateCsrfToken($token)) {
        $alert = [
            'kind'  => 'danger',
            'title' => 'Your session could not be verified. Reload the page and submit the form again.',
        ];

        $state = 'form';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!isValidEmail($email)) {
            // The field error, not the alert — the mockup's "invalid
            // address" state.
            $fieldError = 'Enter a valid email address.';

            $state = 'form';
        } elseif (!mailIsConfigured()) {
            // The condition is a property of the installation, not of the
            // visitor. It is shown for every address — known or not.
            $alert = [
                'kind'  => 'warning',
                'title' => 'Email is not configured for this installation, so reset links cannot be sent.',
            ];

            $state = 'not-configured';
        } else {
            $user = null;

            try {
                $user = findUserByEmail($email);
            } catch (Throwable $ex) {
                error_log('[auth] the reset lookup failed: ' . $ex->getMessage());

                $user = null;
            }

            if ($user !== null) {
                $sent = false;

                try {
                    $resetToken = createPasswordReset((int) $user['id']);
                    $link = absoluteUrl('/reset-password?token=' . urlencode($resetToken));

                    $body = "Someone asked to reset the password of your Financial Tracker account.\n\n"
                        . "Open this link to choose a new password:\n\n"
                        . $link . "\n\n"
                        . "The link is valid for one hour and can be used once.\n\n"
                        . "If you did not ask for this, you can ignore this message — your password stays as it is.\n";

                    $sent = sendMail((string) $user['email'], 'Reset your password', $body);
                } catch (Throwable $ex) {
                    error_log('[auth] the reset mail failed: ' . $ex->getMessage());

                    $sent = false;
                }

                if (!$sent) {
                    // Only a real account reaches this branch. The raw SMTP
                    // error stays in the log.
                    $alert = [
                        'kind'  => 'danger',
                        'title' => 'The reset link could not be sent. Please try again.',
                    ];

                    $state = 'failed';
                } else {
                    $state = 'sent';
                }
            } else {
                // The same answer as for a known address. Nothing was
                // created, and nothing is said about the address.
                $state = 'sent';
            }
        }
    }

    // The floor: the answer takes the same time whichever branch it came
    // from.
    $elapsed = (microtime(true) - $startedAt) * 1000;

    if ($elapsed < AUTH_TIMING_FLOOR_MILLISECONDS) {
        usleep((int) ((AUTH_TIMING_FLOOR_MILLISECONDS - $elapsed) * 1000));
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h2>Reset your password</h2>

<?php if ($state === 'sent'): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title">If that address has an account, a reset link is on its way.</p>
            </div>
        </div>
    </div>

    <p class="field__hint">
        The link is valid for one hour and can be used once.
    </p>

    <a class="btn btn--primary btn--block" href="/login">Back to sign in</a>
<?php elseif ($state === 'not-configured'): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--warning">
            <div class="alert__body">
                <p class="alert__title">Email is not configured for this installation, so reset links cannot be sent.</p>
            </div>
        </div>
    </div>

    <p>
        The person who runs this installation can set a new password for you
        directly.
    </p>

    <a class="btn btn--secondary btn--block" href="/login">Back to sign in</a>
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

    <p class="field__hint">
        Enter the email address on your account and we will send you a link to
        choose a new password.
    </p>

    <form method="post" action="/forgot-password">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="fp-mail">Email</label>
            <input class="input" id="fp-mail" name="email" type="email"
                   autocomplete="email" inputmode="email" value="<?= e($email) ?>"
                   <?= $fieldError !== null ? 'aria-invalid="true" aria-describedby="fp-mail-msg"' : '' ?>>
<?php if ($fieldError !== null): ?>
            <span class="field__error" id="fp-mail-msg"><?= e($fieldError) ?></span>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--block"><?= $state === 'failed' ? 'Try again' : 'Send reset link' ?></button>
    </form>

    <div class="auth__links">
        <a href="/login">Back to sign in</a>
    </div>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/../includes/footer.php';
