<?php
/**
 * forgot_password.php — the "send me a reset link" screen.
 *
 * The contract (docs/issues/issue-016.md, §9, and docs/issues/issue-022.md,
 * §7.1 and §7.2):
 *
 *   1. The answer is the SAME for a known address and for an unknown one —
 *      the same body, the same status code, the same time. Any difference
 *      between them is an account-enumeration leak.
 *   2. No row is created for an unknown address, and the address is never
 *      echoed back — in ANY state. The bodies of two different addresses
 *      are therefore byte-identical, which is what issue-022 §8 asks for.
 *   3. The request NEVER talks to the SMTP server. It writes a row into
 *      the outbox, and the CLI worker (database/outbox_worker.php) sends
 *      it. That is what makes the timing floor mean something again: the
 *      known branch pays for ONE INSERT — milliseconds — where it used to
 *      pay for an SMTP round trip — seconds (issue-022 §7.1).
 *   4. The installation-level states — "email is not configured" and "the
 *      queue cannot take a message" — are entered BEFORE the branch that
 *      knows the address, so they read the same for every address
 *      (issue-022 §7.1, option B).
 *   5. The screen is RATE LIMITED, per submitted address and per source
 *      (issue-022 §7.2). The refusal happens on the request path, BEFORE
 *      anything is queued — the refusal is what stops the mail cannon.
 *   6. A rare failure of the QUEUE WRITE is not reported to the visitor:
 *      it would be a difference between the two addresses again. It goes
 *      to the log instead, loudly.
 */

declare(strict_types=1);

$title = 'Reset your password';
$shell = 'auth';
$pageCss = 'auth.css';

$alert = null;      // ['kind' => ..., 'title' => ...]
$fieldError = null; // the message under the email field
$email = '';
$state = 'form';    // 'form' | 'sent' | 'not-configured' | 'failed' | 'limited'

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

        // The source limiter bounds the SPRAY — the attacker who walks
        // through a list of addresses, one request each. The address
        // limiter bounds the CANNON — the requests that all aim at ONE
        // address.
        //
        // BOTH are consulted in ONE call, and the counters are raised only
        // when NEITHER of them is blocked. A request that was turned away
        // must not extend the window of the counter that turned it away,
        // nor the window of the other one: two separate calls would raise
        // the source counter for a request the address counter refused,
        // and a visitor behind a shared address would then lock out
        // everybody else along with them.
        $quota = takeRequestQuota(array_merge(
            resetSourceLimiters(),
            resetAddressLimiters($email)
        ));

        if ($quota['blocked']) {
            refuseRateLimited((int) $quota['retry_after']);

            $state = 'limited';
        } elseif (!isValidEmail($email)) {
            // The field error, not the alert — the mockup's "invalid
            // address" state.
            $fieldError = 'Enter a valid email address.';

            $state = 'form';
        } else {
            // The state of the INSTALLATION, decided before the
            // branch that knows the address. It is the same for every
            // address, so it cannot tell a visitor whether the address
            // has an account.
            $queue = mailQueueState();

            if ($queue === 'not-configured') {
                $alert = [
                    'kind'  => 'warning',
                    'title' => 'Email is not configured for this installation, so reset links cannot be sent.',
                ];

                $state = 'not-configured';
            } elseif ($queue === 'unavailable') {
                // The queue table is missing — the installation was not
                // migrated. It is an installation-level failure, so it
                // reads the same for every address.
                $alert = [
                    'kind'  => 'danger',
                    'title' => 'Email could not be sent. Please try again later.',
                ];

                $state = 'failed';
            } else {
                $user = null;

                try {
                    $user = findUserByEmail($email);
                } catch (Throwable $ex) {
                    logFailure('auth: the reset lookup failed', $ex);

                    $user = null;
                }

                if ($user !== null) {
                    try {
                        $resetToken = createPasswordReset((int) $user['id']);
                        $link = absoluteUrl('/reset-password?token=' . urlencode($resetToken));

                        $body = "Someone asked to reset the password of your Financial Tracker account.\n\n"
                            . "Open this link to choose a new password:\n\n"
                            . $link . "\n\n"
                            . "The link is valid for one hour and can be used once.\n\n"
                            . "If you did not ask for this, you can ignore this message — your password stays as it is.\n";

                        // The message is QUEUED, not sent: the worker
                        // sends it (issue-022 §7.1).
                        enqueueMail((string) $user['email'], 'Reset your password', $body);
                    } catch (Throwable $ex) {
                        // The queue write failed. The visitor is NOT
                        // told: a difference between the two addresses
                        // is exactly what this screen must not have.
                        // The operator reads the log.
                        logFailure('auth: the reset message could not be queued', $ex);
                    }
                }

                // The same answer as for an unknown address. Nothing was
                // created for an unknown address, and nothing is said
                // about the address.
                $state = 'sent';
            }
        }
    }

    // The floor: the answer takes the same time whichever branch it came
    // from. A REFUSED request does not wait — a 429 is the same answer for
    // every address, so it leaks nothing, and a caller who is early should
    // not be able to make the server hold a slot for half a second.
    if ($state !== 'limited') {
        $elapsed = (microtime(true) - $startedAt) * 1000;

        if ($elapsed < AUTH_TIMING_FLOOR_MILLISECONDS) {
            usleep((int) ((AUTH_TIMING_FLOOR_MILLISECONDS - $elapsed) * 1000));
        }
    }
}

// The address is never echoed back: the bodies of two different addresses
// must be byte-identical (issue-022 §8). The field is empty in every state
// that redisplays the form.
$email = '';

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
<?php elseif ($state === 'failed'): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">Email could not be sent. Please try again later.</p>
            </div>
        </div>
    </div>

    <p class="field__hint">
        Enter the email address on your account and we will send you a link to
        choose a new password.
    </p>

    <form method="post" action="/forgot-password">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="fp-mail">Email</label>
            <input class="input" id="fp-mail" name="email" type="email"
                   autocomplete="email" inputmode="email" value=""
                   <?= $fieldError !== null ? 'aria-invalid="true" aria-describedby="fp-mail-msg"' : '' ?>>
<?php if ($fieldError !== null): ?>
            <span class="field__error" id="fp-mail-msg"><?= e($fieldError) ?></span>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--block">Try again</button>
    </form>

    <div class="auth__links">
        <a href="/login">Back to sign in</a>
    </div>
<?php else: ?>
<?php if ($state === 'limited'): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--warning">
            <div class="alert__body">
                <p class="alert__title">Too many requests. Please try again in a few minutes.</p>
            </div>
        </div>
    </div>
<?php elseif ($alert !== null): ?>
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
                   autocomplete="email" inputmode="email" value=""
                   <?= $fieldError !== null ? 'aria-invalid="true" aria-describedby="fp-mail-msg"' : '' ?>>
<?php if ($fieldError !== null): ?>
            <span class="field__error" id="fp-mail-msg"><?= e($fieldError) ?></span>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary btn--block">Send reset link</button>
    </form>

    <div class="auth__links">
        <a href="/login">Back to sign in</a>
    </div>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/../includes/footer.php';
