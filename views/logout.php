<?php
/**
 * logout.php — the sign-out handler.
 *
 * The contract (docs/issues/issue-016.md, §12):
 *
 *   1. The route is POST-only. A GET logout can be triggered by any image
 *      tag on any page, and the route table answers a GET with a 405.
 *   2. The CSRF token is validated.
 *   3. The session is destroyed AND the cookie is cleared. Clearing only
 *      the server-side data leaves a cookie pointing at nothing.
 *   4. The visitor is redirected to the sign-in page.
 */

declare(strict_types=1);

$title = 'Signed out';
$shell = 'auth';
$pageCss = 'auth.css';

// The guard comes first: an anonymous POST does nothing at all.
requireLogin();

// The CSRF token is validated before anything is destroyed.
$token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;

if (!validateCsrfToken($token)) {
    $title = 'Not signed out';

    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
        <h2>Not signed out</h2>

        <div role="status" aria-live="polite">
            <div class="alert alert--danger">
                <div class="alert__body">
                    <p class="alert__title">Your session could not be verified. Reload the page and try again.</p>
                </div>
            </div>
        </div>

        <a class="btn btn--primary btn--block" href="/dashboard">Back to the dashboard</a>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';

    return;
}

// The session data, the session and the cookie all go.
logoutUser();

redirect('/login');
