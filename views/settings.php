<?php
/**
 * settings.php — the profile, the password, the session and the installation.
 *
 * ONE view serves THREE routes:
 *
 *   GET  /settings           — the screen
 *   POST /settings/profile   — the profile form
 *   POST /settings/password  — the change-password form
 *
 * The method alone cannot tell the two POSTs apart, so the PATH decides
 * (requestPath()).
 *
 * The contract (docs/issues/issue-020.md §11–§15):
 *
 *   1. requireLogin() is the FIRST call.
 *   2. The CSRF token is validated BEFORE any input is read.
 *   3. Every POST ends with a redirect. The state that has to survive the
 *      redirect travels in the session as a flash message.
 *   4. The four cards are independent: Profile, Change password, Session,
 *      Installation.
 *   5. The installation values come from config.php and ONLY from
 *      config.php — never from getenv(), $_ENV or $_SERVER. The two
 *      passwords are never even named in this file.
 *   6. A password change ends every other session and keeps the current
 *      one. The credential version in the session is the digest of the
 *      stored hash, so the session has to be told about the new hash
 *      before the next request compares them.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen.
// ---------------------------------------------------------------------------

requireLogin();

$uid  = (int) getCurrentUserId();
$user = getCurrentUser();

$username   = is_array($user) ? (string) $user['username'] : '';
$email      = is_array($user) ? (string) $user['email'] : '';
$createdAt  = is_array($user) ? (string) ($user['created_at'] ?? '') : '';

// ---------------------------------------------------------------------------
// 2. The two write paths.
//
// The path — not the method — tells the two POSTs apart.
// ---------------------------------------------------------------------------

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path   = requestPath();

// ---------------------------------------------------------------------------
// 3. The profile.
//
// The uniqueness belongs to the server, and it leaves the signed-in
// account's own row out: saving the profile without changing anything
// must not answer "already taken" with the visitor's own name.
// ---------------------------------------------------------------------------

if ($method === 'POST' && $path === '/settings/profile') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!validateCsrfToken($token)) {
        throw new HttpException(403, 'The form has expired. Please try again.');
    }

    $validation = validateProfileInput(post('username', ''), post('email', ''));
    $newUsername = (string) $validation['values']['username'];
    $newEmail    = (string) $validation['values']['email'];

    $errors = $validation['errors'];

    if ($errors === []) {
        $errors = profileUniquenessErrors($newUsername, $newEmail, $uid);
    }

    if ($errors !== []) {
        setFlash('settings_profile_error', [
            'username'      => $newUsername,
            'email'         => $newEmail,
            'username_error' => (string) ($errors['username'] ?? ''),
            'email_error'   => (string) ($errors['email'] ?? ''),
        ]);

        redirect('/settings');
    }

    try {
        Database::getInstance()->execute(
            'UPDATE users
                SET username = :username,
                    email = :email,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = :uid',
            [
                ':username' => $newUsername,
                ':email'    => $newEmail,
                ':uid'      => $uid,
            ]
        );
    } catch (PDOException $ex) {
        // The index is the judge. A race that slips past the SELECT has to
        // produce the same message the SELECT would have produced, so the
        // uniqueness is worked out again here.
        if (isDuplicateKeyError($ex)) {
            $race = profileUniquenessErrors($newUsername, $newEmail, $uid);

            setFlash('settings_profile_error', [
                'username'      => $newUsername,
                'email'         => $newEmail,
                'username_error' => (string) ($race['username'] ?? 'That username is already taken.'),
                'email_error'   => (string) ($race['email'] ?? 'That email address is already registered.'),
            ]);

            redirect('/settings');
        }

        error_log('[settings] the profile update failed: ' . $ex->getMessage());

        throw new HttpException(500, 'Your profile could not be saved. Please try again.');
    }

    setFlash('settings_profile_saved', 'Your profile was saved.');

    redirect('/settings');
}

// ---------------------------------------------------------------------------
// 4. The password.
//
// The current password is verified with password_verify(). The two new
// ones are compared with hash_equals() — a plain === on a secret is a
// timing leak.
//
// The password is NEVER trimmed: a leading or a trailing space is part of
// the secret the visitor chose.
// ---------------------------------------------------------------------------

if ($method === 'POST' && $path === '/settings/password') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!validateCsrfToken($token)) {
        throw new HttpException(403, 'The form has expired. Please try again.');
    }

    $current      = postRaw('current_password', '');
    $password     = postRaw('password', '');
    $confirmation = postRaw('password_confirmation', '');

    $errors = [];

    $row = Database::getInstance()->fetchOne(
        'SELECT id, password_hash
           FROM users
          WHERE id = ?
          LIMIT 1',
        [$uid]
    );

    if (!is_array($row) || !verifyPassword($current, (string) $row['password_hash'])) {
        $errors['current_password'] = 'Your current password is not correct.';
    }

    $errors = array_merge($errors, validatePasswordChangeInput($password, $confirmation));

    if ($errors !== []) {
        setFlash('settings_password_error', [
            'current_password_error'        => (string) ($errors['current_password'] ?? ''),
            'password_error'                 => (string) ($errors['password'] ?? ''),
            'password_confirmation_error'    => (string) ($errors['password_confirmation'] ?? ''),
        ]);

        redirect('/settings');
    }

    $hash = hashPassword($password);

    try {
        Database::getInstance()->execute(
            'UPDATE users
                SET password_hash = :hash,
                    updated_at = CURRENT_TIMESTAMP,
                    failed_attempts = 0,
                    locked_until = NULL
              WHERE id = :uid',
            [
                ':hash' => $hash,
                ':uid'  => $uid,
            ]
        );
    } catch (Throwable $ex) {
        error_log('[settings] the password update failed: ' . $ex->getMessage());

        throw new HttpException(500, 'Your password could not be changed. Please try again.');
    }

    // The credential version is the digest of the stored hash, and the hash
    // has just changed. The CURRENT session has to be told about it — or
    // the next request would find a mismatch and sign the visitor out of
    // the account they just changed the password of. Every OTHER session
    // still holds the old digest, so it ends by itself.
    $_SESSION['credential_version'] = credentialVersion($hash);

    setFlash('settings_password_saved', 'Your password was changed.');

    redirect('/settings');
}

// ---------------------------------------------------------------------------
// 5. The state.
//
// The flashes are read once and removed on the way out.
// ---------------------------------------------------------------------------

$profileSaved  = takeFlash('settings_profile_saved');
$profileError  = takeFlash('settings_profile_error');
$passwordSaved = takeFlash('settings_password_saved');
$passwordError = takeFlash('settings_password_error');

$profileSaved  = is_string($profileSaved) && $profileSaved !== '' ? $profileSaved : '';
$profileError  = is_array($profileError) ? $profileError : null;
$passwordSaved = is_string($passwordSaved) && $passwordSaved !== '' ? $passwordSaved : '';
$passwordError = is_array($passwordError) ? $passwordError : null;

// The profile form's values. A rejected submit keeps what the visitor
// typed; a successful save shows the saved values.
$profileUsername = $username;
$profileEmail    = $email;
$usernameError   = '';
$emailError      = '';

if ($profileError !== null) {
    $profileUsername = (string) ($profileError['username'] ?? $username);
    $profileEmail    = (string) ($profileError['email'] ?? $email);
    $usernameError   = (string) ($profileError['username_error'] ?? '');
    $emailError      = (string) ($profileError['email_error'] ?? '');
}

// The password form's errors. A password is never put back into the
// page — the fields stay empty and only the messages come back.
//
// The three messages have three names of their own: the flash is one
// array, and a variable that holds it cannot also hold a message.
$currentPasswordError = '';
$newPasswordError    = '';
$confirmationError    = '';

if ($passwordError !== null) {
    $currentPasswordError = (string) ($passwordError['current_password_error'] ?? '');
    $newPasswordError    = (string) ($passwordError['password_error'] ?? '');
    $confirmationError    = (string) ($passwordError['password_confirmation_error'] ?? '');
}

// ---------------------------------------------------------------------------
// 6. The installation.
//
// The values come from config.php and from nowhere else. The two
// passwords are not even named in this file.
// ---------------------------------------------------------------------------

$dbName       = defined('DB_NAME') ? (string) DB_NAME : '';
$dbUser       = defined('DB_USER') ? (string) DB_USER : '';
$smtpHost     = defined('SMTP_HOST') ? (string) SMTP_HOST : '';
$smtpPort     = defined('SMTP_PORT') ? (string) SMTP_PORT : '';
$smtpUser     = defined('SMTP_USER') ? (string) SMTP_USER : '';
$registration = defined('ALLOW_REGISTRATION') && ALLOW_REGISTRATION === true;
$weekStart    = defined('WEEK_START') ? (int) WEEK_START : 0;

// ---------------------------------------------------------------------------
// 7. The shell.
// ---------------------------------------------------------------------------

$title   = 'Settings';
$active  = 'settings';
$pageCss = 'settings.css';

require __DIR__ . '/../includes/header.php';
?>

<div class="page__header">
    <div>
        <h1 class="page__title">Settings</h1>
        <p class="page__subtitle">Your account, your password and a read-only view of this installation.</p>
    </div>
</div>

<div class="card set__block">
    <h2>Profile</h2>

<?php if ($profileSaved !== ''): ?>

    <div role="status" aria-live="polite">
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title"><?= e($profileSaved) ?></p>
            </div>
        </div>
    </div>

<?php endif; ?>

    <form method="post" action="/settings/profile" aria-label="Profile">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="set-user">Username</label>
            <span class="field__hint" id="set-user-hint">3–32 characters. Letters, digits, underscore and hyphen.</span>
            <input class="input" id="set-user" name="username" type="text"
                   autocomplete="username" value="<?= e($profileUsername) ?>"
                   aria-describedby="set-user-hint<?= $usernameError !== '' ? ' set-user-msg' : '' ?>"
                   <?= $usernameError !== '' ? 'aria-invalid="true"' : '' ?>>
<?php if ($usernameError !== ''): ?>
            <span class="field__error" id="set-user-msg"><?= e($usernameError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="set-mail">Email</label>
            <span class="field__hint" id="set-mail-hint">Used for the password reset link. It must be unique.</span>
            <input class="input" id="set-mail" name="email" type="email"
                   autocomplete="email" value="<?= e($profileEmail) ?>"
                   aria-describedby="set-mail-hint<?= $emailError !== '' ? ' set-mail-msg' : '' ?>"
                   <?= $emailError !== '' ? 'aria-invalid="true"' : '' ?>>
<?php if ($emailError !== ''): ?>
            <span class="field__error" id="set-mail-msg"><?= e($emailError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <span class="field__label" id="set-created-label">Created</span>
            <dl class="readonly-list" aria-labelledby="set-created-label">
                <div class="readonly-list__row">
                    <dt>Account created</dt>
                    <dd><?= e(formatAccountDate($createdAt)) ?></dd>
                </div>
            </dl>
        </div>

        <button type="submit" class="btn btn--primary">Save changes</button>
    </form>
</div>

<div class="card set__block">
    <h2>Change password</h2>

<?php if ($passwordSaved !== ''): ?>

    <div role="status" aria-live="polite">
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title"><?= e($passwordSaved) ?></p>
                <p>Use it the next time you sign in.</p>
            </div>
        </div>
    </div>

<?php endif; ?>

    <form method="post" action="/settings/password" aria-label="Change password">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="pw-current">Current password</label>
            <input class="input" id="pw-current" name="current_password" type="password"
                   autocomplete="current-password"
                   <?= $currentPasswordError !== '' ? 'aria-invalid="true" aria-describedby="pw-current-msg"' : '' ?>>
<?php if ($currentPasswordError !== ''): ?>
            <span class="field__error" id="pw-current-msg"><?= e($currentPasswordError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="pw-new">New password</label>
            <span class="field__hint" id="pw-new-hint">At least 10 characters.</span>
            <input class="input" id="pw-new" name="password" type="password"
                   autocomplete="new-password"
                   aria-describedby="pw-new-hint<?= $newPasswordError !== '' ? ' pw-new-msg' : '' ?>"
                   <?= $newPasswordError !== '' ? 'aria-invalid="true"' : '' ?>>
<?php if ($newPasswordError !== ''): ?>
            <span class="field__error" id="pw-new-msg"><?= e($newPasswordError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="pw-new2">Confirm new password</label>
            <input class="input" id="pw-new2" name="password_confirmation" type="password"
                   autocomplete="new-password"
                   <?= $confirmationError !== '' ? 'aria-invalid="true" aria-describedby="pw-new2-msg"' : '' ?>>
<?php if ($confirmationError !== ''): ?>
            <span class="field__error" id="pw-new2-msg"><?= e($confirmationError) ?></span>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary">Change password</button>
    </form>
</div>

<div class="card set__block">
    <h2>Session</h2>
    <p class="field__hint">
        You are signed in as <strong><?= e($username) ?></strong>. Signing out ends
        this session on this device.
    </p>
    <form method="post" action="/logout" aria-label="Sign out">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <button type="submit" class="btn btn--secondary">Sign out</button>
    </form>
</div>

<div class="card set__block">
    <h2>Installation</h2>
    <p class="field__hint">
        These values come from the configuration file. They are shown for
        reference and cannot be changed here.
    </p>
    <dl class="readonly-list">
        <div class="readonly-list__row">
            <dt>Database name</dt>
            <dd class="set__value"><?= e($dbName) ?></dd>
        </div>
        <div class="readonly-list__row">
            <dt>Database user</dt>
            <dd class="set__value"><?= e($dbUser) ?></dd>
        </div>
        <div class="readonly-list__row">
            <dt>SMTP host</dt>
            <dd class="set__value"><?= e($smtpHost !== '' ? $smtpHost : '—') ?></dd>
        </div>
        <div class="readonly-list__row">
            <dt>SMTP port</dt>
            <dd class="set__value"><?= e($smtpPort) ?></dd>
        </div>
        <div class="readonly-list__row">
            <dt>SMTP user</dt>
            <dd class="set__value"><?= e($smtpUser !== '' ? $smtpUser : '—') ?></dd>
        </div>
        <div class="readonly-list__row">
            <dt>Registration allowed</dt>
            <dd class="set__value"><?= $registration ? 'Yes' : 'No' ?></dd>
        </div>
        <div class="readonly-list__row">
            <dt>Week start</dt>
            <dd class="set__value"><?= e(weekStartName($weekStart)) ?></dd>
        </div>
    </dl>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
