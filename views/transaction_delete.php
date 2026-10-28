<?php
/**
 * transaction_delete.php — the delete handler.
 *
 * The contract (docs/issues/issue-018.md):
 *
 *   1. The CSRF token is validated BEFORE any input is read.
 *   2. The delete is scoped by the owner. Another account's row is a 404
 *      — not a 403, and not a silent success.
 *   3. The row is READ before it is deleted: the wrapper has no
 *      rowCount(), and a delete that matched nothing must not be
 *      reported as a success.
 *   4. The answer is a 303 to the list, and the body is the fallback for
 *      a client that does not follow it.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen.
// ---------------------------------------------------------------------------

requireLogin();

// ---------------------------------------------------------------------------
// 2. The CSRF token.
//
// BEFORE any input is read.
// ---------------------------------------------------------------------------

$token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;

if (!validateCsrfToken($token)) {
    throw new HttpException(403, 'Your session could not be verified. Please try again.');
}

// ---------------------------------------------------------------------------
// 3. The row.
//
// An id that is not a positive integer names no transaction.
// ---------------------------------------------------------------------------

$idRaw = post('id');

if ($idRaw === '' || !ctype_digit($idRaw) || (int) $idRaw <= 0) {
    throw new HttpException(404, 'That transaction does not exist.');
}

$id  = (int) $idRaw;
$uid = (int) getCurrentUserId();

$db = Database::getInstance();

// The ownership is in the SQL. A row that belongs to another account is
// not found, and a row that is not found is a 404.
$row = $db->fetchOne(
    'SELECT t.id
       FROM transactions t
      WHERE t.id = ?
        AND t.user_id = ?',
    [$id, $uid]
);

if ($row === false) {
    throw new HttpException(404, 'That transaction does not exist.');
}

// ---------------------------------------------------------------------------
// 4. The delete.
//
// Scoped by the owner a second time, so the statement is safe on its own
// and not only because of the check above it.
// ---------------------------------------------------------------------------

$db->execute(
    'DELETE FROM transactions
      WHERE id = ?
        AND user_id = ?',
    [$id, $uid]
);

// ---------------------------------------------------------------------------
// 5. The answer.
//
// A 303 to the list, and the body is the fallback.
// ---------------------------------------------------------------------------

header('Location: /transactions', true, 303);

$title   = 'Transactions';
$active  = 'transactions';
$pageCss = 'transactions.css';

$currentUser = getCurrentUser();
$username    = is_array($currentUser) ? (string) $currentUser['username'] : '';

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div role="status" aria-live="polite">
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title">Transaction deleted.</p>
                <p>You are being redirected to the transaction list.</p>
            </div>
        </div>
    </div>

    <a class="btn btn--primary" href="/transactions">Go to transactions now</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
