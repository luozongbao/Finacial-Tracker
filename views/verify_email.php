<?php
/**
 * verify_email.php — the account-confirmation screen.
 *
 * There is no mockup for this screen (issue-016 §3): it is built from the
 * auth shell.
 *
 * The contract:
 *
 *   1. The token is redeemed once. The lookup filters on `used_at IS NULL`
 *      and `expires_at > NOW()`, and the comparison of the hash is
 *      constant-time.
 *   2. The screen says whether the confirmation worked. It never says
 *      anything about the account behind the token.
 *   3. The confirmation does NOT sign the user in. The visitor signs in with
 *      their password, like everybody else.
 */

declare(strict_types=1);

$title = 'Confirm your address';
$shell = 'auth';
$pageCss = 'auth.css';

$token = trim((string) ($_GET['token'] ?? ''));
$confirmed = false;

if ($token !== '') {
    try {
        $confirmed = consumeEmailVerification($token) !== null;
    } catch (Throwable $ex) {
        error_log('[auth] the confirmation failed: ' . $ex->getMessage());

        $confirmed = false;
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h2>Confirm your address</h2>

<?php if ($confirmed): ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title">Your email address is confirmed.</p>
                <p>You can now sign in with your account.</p>
            </div>
        </div>
    </div>

    <a class="btn btn--primary btn--block" href="/login">Go to sign in</a>
<?php else: ?>
    <div role="status" aria-live="polite">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">This confirmation link is no longer valid.</p>
            </div>
        </div>
    </div>

    <p>
        Ask the person who runs this installation to confirm your address, then
        sign in as usual.
    </p>

    <a class="btn btn--secondary btn--block" href="/login">Back to sign in</a>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/../includes/footer.php';
