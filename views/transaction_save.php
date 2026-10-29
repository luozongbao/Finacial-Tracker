<?php
/**
 * transaction_save.php — the create and the update handler.
 *
 * The contract (docs/issues/issue-018.md):
 *
 *   1. The CSRF token is validated BEFORE any input is read. A POST
 *      without a valid token never reaches the validation and never
 *      reaches the database.
 *   2. EVERY error is collected and rendered at once, in the order the
 *      fields appear on the form.
 *   3. A rejection keeps the visitor's values. A rejected submit never
 *      loses work.
 *   4. The write is scoped by the owner. Another account's row is a 404,
 *      never a silent success.
 *   5. The amount is bound as a STRING in the canonical two-decimal form,
 *      and the description is NULL when it is empty.
 *   6. A successful save redirects with a 303, and the success page with
 *      the "Go to transactions now" link is rendered as the fallback.
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
// BEFORE any input is read. A missing or a wrong token is a 403.
// ---------------------------------------------------------------------------

$token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : null;

if (!validateCsrfToken($token)) {
    throw new HttpException(403, 'Your session could not be verified. Please try again.');
}

// ---------------------------------------------------------------------------
// 3. The mode.
//
// A hidden id means an UPDATE; no id means a CREATE. An id that is
// present but not a positive integer names no transaction at all.
// ---------------------------------------------------------------------------

$idRaw  = post('id');
$formId = 0;

if ($idRaw !== '') {
    if (!ctype_digit($idRaw) || (int) $idRaw <= 0) {
        throw new HttpException(404, 'That transaction does not exist.');
    }

    $formId = (int) $idRaw;
}

$isEdit = $formId > 0;
$uid    = (int) getCurrentUserId();

// ---------------------------------------------------------------------------
// 4. The input.
//
// The five fields, read once. Nothing else is read from the request.
// ---------------------------------------------------------------------------

$input = [
    'type'        => post('type'),
    'amount'      => post('amount'),
    'date'        => post('date'),
    'category'    => post('category'),
    'description' => post('description'),
];

// ---------------------------------------------------------------------------
// 5. The categories.
//
// The visitor's own categories — for the validation and for the form that
// is re-rendered after a rejection. The ownership is in the SQL.
// ---------------------------------------------------------------------------

$formCategories = [];
$row            = null;

try {
    $db = Database::getInstance();

    $formCategories = $db->fetchAll(
        'SELECT c.id, c.name
           FROM categories c
          WHERE c.user_id = ?
          ORDER BY c.name ASC',
        [$uid]
    );

    if ($isEdit) {
        // The row of the edit. Another account's row is not found, and a
        // row that is not found is a 404.
        $row = $db->fetchOne(
            'SELECT t.id, t.occurred_on, t.description, t.type, t.amount, t.category_id
               FROM transactions t
              WHERE t.id = ?
                AND t.user_id = ?',
            [$formId, $uid]
        );

        if ($row === false) {
            throw new HttpException(404, 'That transaction does not exist.');
        }
    }
} catch (HttpException $ex) {
    throw $ex;
} catch (Throwable $ex) {
    logFailure('transactions: the submitted values could not be read', $ex);

    $formMode        = $isEdit ? 'edit' : 'add';
    $formValues      = $input;
    $formErrors      = [];
    $formServerError = true;

    require __DIR__ . '/transaction_form.php';

    return;
}

$categoryIds = array_map('strval', array_column($formCategories, 'id'));

// ---------------------------------------------------------------------------
// 6. The validation.
//
// Every error is collected, and the values come back with them.
// ---------------------------------------------------------------------------

$result = validateTransactionInput($input, $categoryIds);

if ($result['errors'] !== []) {
    $formMode   = $isEdit ? 'edit' : 'add';
    $formValues = $result['values'];
    $formErrors = $result['errors'];
    $formRow    = $row;

    require __DIR__ . '/transaction_form.php';

    return;
}

// ---------------------------------------------------------------------------
// 7. The write.
//
// The amount is bound as a string in the canonical two-decimal form, and
// the description and the category are NULL when they are empty.
// ---------------------------------------------------------------------------

$amount      = $result['values']['amount'];
$type        = $result['values']['type'];
$occurredOn  = $result['values']['date'];
$categoryId  = $result['values']['category'] === '' ? null : (int) $result['values']['category'];
$description = $result['values']['description'] === '' ? null : $result['values']['description'];

try {
    if ($isEdit) {
        $db->execute(
            'UPDATE transactions
                SET category_id = ?, type = ?, amount = ?, occurred_on = ?, description = ?
              WHERE id = ? AND user_id = ?',
            [$categoryId, $type, $amount, $occurredOn, $description, $formId, $uid]
        );
    } else {
        $db->execute(
            'INSERT INTO transactions (user_id, category_id, type, amount, occurred_on, description)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$uid, $categoryId, $type, $amount, $occurredOn, $description]
        );

        $formId = $db->lastInsertId();
    }
} catch (Throwable $ex) {
    // The context of the failure goes to the log; the page shows plain copy
    // and keeps every value, so the submit can be retried.
    logFailure('transactions: the save failed', $ex);

    $formMode        = $isEdit ? 'edit' : 'add';
    $formValues      = $result['values'];
    $formErrors      = [];
    $formServerError = true;

    require __DIR__ . '/transaction_form.php';

    return;
}

// ---------------------------------------------------------------------------
// 8. The success.
//
// The 303 redirect is the mechanism; the success page is the fallback for
// a client that does not follow it. The link is always there.
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
                <p class="alert__title">Transaction saved.</p>
                <p>You are being redirected to the transaction list.</p>
            </div>
        </div>
    </div>

    <a class="btn btn--primary" href="/transactions">Go to transactions now</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
