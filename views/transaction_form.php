<?php
/**
 * transaction_form.php — the add and the edit screen.
 *
 * ONE template serves both modes. The difference is the mode, and the
 * mode is decided by the caller:
 *
 *   - as a route target (/transactions/new and /transactions/edit?id=…),
 *     this file is the entry point: it reads the query string, loads the
 *     row and builds the values;
 *   - as a template (the save handler re-renders it after a rejection),
 *     the caller has already set $formMode, $formValues, $formErrors,
 *     $formCategories and $formRow.
 *
 * The contract (docs/issues/issue-018.md):
 *
 *   1. requireLogin() is the FIRST call.
 *   2. The row of another account is a 404 — never a 403, and never a
 *      form that quietly shows somebody else's entry.
 *   3. The five fields keep the visitor's values when the server rejects
 *      the form. A rejected submit never loses work.
 *   4. EVERY error is rendered in its own field's error slot, and the
 *      other fields keep their values.
 *   5. There is no `required` attribute: the server is the validator, and
 *      the browser's own bubbles would pre-empt the specified copy.
 *   6. The delete confirmation lives in its OWN card, below the save
 *      actions, and never shares a button group with "Save changes".
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen.
// ---------------------------------------------------------------------------

requireLogin();

// ---------------------------------------------------------------------------
// 2. The shell.
//
// These variables are read by includes/header.php.
// ---------------------------------------------------------------------------

$title   = 'Add transaction';
$active  = 'transactions';
$pageCss = 'transactions.css';

$currentUser = getCurrentUser();
$username    = is_array($currentUser) ? (string) $currentUser['username'] : '';

// ---------------------------------------------------------------------------
// 3. The mode and the values.
//
// $formMode is set only by a caller that re-renders this template. When
// it is absent this file is the entry point, and it builds the state
// itself from the query string.
// ---------------------------------------------------------------------------

if (!isset($formMode)) {
    $formMode       = 'add';
    $formId         = 0;
    $formValues     = [];
    $formErrors     = [];
    $formCategories = [];
    $formRow        = null;

    $idRaw = query('id');

    if ($idRaw !== '') {
        // An id that is not a positive integer names no transaction.
        if (!ctype_digit($idRaw) || (int) $idRaw <= 0) {
            throw new HttpException(404, 'That transaction does not exist.');
        }

        $uid  = (int) getCurrentUserId();
        $db   = Database::getInstance();

        // The ownership is in the SQL. Another account's row is not found,
        // and a row that is not found is a 404.
        $row = $db->fetchOne(
            'SELECT t.id, t.occurred_on, t.description, t.type, t.amount, t.category_id
               FROM transactions t
              WHERE t.id = ?
                AND t.user_id = ?',
            [(int) $idRaw, $uid]
        );

        if ($row === false) {
            throw new HttpException(404, 'That transaction does not exist.');
        }

        $formMode = 'edit';
        $formId   = (int) $row['id'];
        $formRow  = $row;

        $formValues = [
            'type'        => (string) $row['type'],
            'amount'      => (string) $row['amount'],
            'date'        => (string) $row['occurred_on'],
            'category'    => $row['category_id'] === null ? '' : (string) $row['category_id'],
            'description' => (string) ($row['description'] ?? ''),
        ];
    }

    if ($formMode === 'add') {
        // The type is the only field with a value of its own: Income is
        // the default, because an entry that is not typed is spending far
        // more often than it is income. The date defaults to today, in UTC
        // — the timezone the database stores.
        $formValues = [
            'type'        => 'income',
            'amount'      => '',
            'date'        => utcNow()->format('Y-m-d'),
            'category'    => '',
            'description' => '',
        ];
    }

    $formCategories = Database::getInstance()->fetchAll(
        'SELECT c.id, c.name
           FROM categories c
          WHERE c.user_id = ?
          ORDER BY c.name ASC',
        [(int) getCurrentUserId()]
    );
}

// The id of the row being edited. It is its OWN variable and not a field
// of $formValues, because the hidden input that carries it decides
// whether the save UPDATES a row or CREATES one — and a missing id must
// never turn an update into a second transaction.
$formId = isset($formId) && (int) $formId > 0 ? (int) $formId : 0;

// ---------------------------------------------------------------------------
// 4. The display values.
//
// Every one of them is prepared here, so the markup below only reads.
// ---------------------------------------------------------------------------

$isEdit = $formMode === 'edit';

$values = array_merge([
    'type'        => 'income',
    'amount'      => '',
    'date'        => '',
    'category'    => '',
    'description' => '',
], is_array($formValues) ? $formValues : []);

$errors = is_array($formErrors) ? $formErrors : [];

$type        = (string) $values['type'];
$amount      = (string) $values['amount'];
$date        = (string) $values['date'];
$category    = (string) $values['category'];
$description = (string) $values['description'];

$typeError        = (string) ($errors['type'] ?? '');
$amountError      = (string) ($errors['amount'] ?? '');
$dateError        = (string) ($errors['date'] ?? '');
$categoryError    = (string) ($errors['category'] ?? '');
$descriptionError = (string) ($errors['description'] ?? '');

// The heading and the button follow the mode.
if ($isEdit) {
    $title  = 'Edit transaction';
    $submit = 'Save changes';
} else {
    $title  = 'Add transaction';
    $submit = 'Save transaction';
}

// The server error. A failure must not lose the visitor's work, so the
// values above are kept whatever happens.
$serverError = isset($formServerError) && $formServerError === true;

if ($serverError) {
    http_response_code(500);
}

// The row of the danger zone. The delete confirmation names the row, so
// it needs the date, the description and the amount.
$dangerRow = isset($formRow) && is_array($formRow) ? $formRow : null;

require __DIR__ . '/../includes/header.php';
?>

<div class="page__header">
    <div>
<?php if ($isEdit): ?>
        <h2 class="page__title">Edit transaction</h2>
        <p class="page__subtitle">Changing a transaction changes the totals of every period it belongs to.</p>
<?php else: ?>
        <h1 class="page__title">Add transaction</h1>
        <p class="page__subtitle">Record one entry. The date decides which period it belongs to.</p>
<?php endif; ?>
    </div>
</div>

<?php if ($serverError): ?>

<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">Something went wrong on our side. Please try again.</p>
        </div>
    </div>
</div>

<?php endif; ?>

<div class="card">
    <form method="post" action="/transactions/save">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
<?php if ($formId > 0): ?>
        <input type="hidden" name="id" value="<?= e((string) $formId) ?>">
<?php endif; ?>

        <div class="field">
            <span class="field__label" id="tf-type-label">Type</span>
            <div class="radio-group" role="radiogroup" aria-labelledby="tf-type-label"<?= $typeError !== '' ? ' aria-describedby="tf-type-msg"' : '' ?>>
                <label class="radio radio--income" for="tf-in">
                    <input type="radio" id="tf-in" name="type" value="income"<?= $type === 'income' ? ' checked' : '' ?><?= $typeError !== '' ? ' aria-invalid="true"' : '' ?>> Income
                </label>
                <label class="radio radio--expense" for="tf-out">
                    <input type="radio" id="tf-out" name="type" value="expense"<?= $type === 'expense' ? ' checked' : '' ?><?= $typeError !== '' ? ' aria-invalid="true"' : '' ?>> Spending
                </label>
            </div>
<?php if ($typeError !== ''): ?>
            <span class="field__error" id="tf-type-msg"><?= e($typeError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="tf-amount">Amount</label>
            <span class="field__hint" id="tf-amount-hint">Enter the amount with up to two decimals. It must be greater than zero.</span>
            <input class="input input--amount" id="tf-amount" name="amount" type="number"
                   step="0.01" inputmode="decimal" min="0" value="<?= e($amount) ?>"
                   aria-describedby="tf-amount-hint<?= $amountError !== '' ? ' tf-amount-msg' : '' ?>"
                   <?= $amountError !== '' ? 'aria-invalid="true"' : '' ?>>
<?php if ($amountError !== ''): ?>
            <span class="field__error" id="tf-amount-msg"><?= e($amountError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="tf-date">Date</label>
            <span class="field__hint" id="tf-date-hint">The transaction date.</span>
            <input class="input" id="tf-date" name="date" type="date" value="<?= e($date) ?>"
                   aria-describedby="tf-date-hint<?= $dateError !== '' ? ' tf-date-msg' : '' ?>"
                   <?= $dateError !== '' ? 'aria-invalid="true"' : '' ?>>
<?php if ($dateError !== ''): ?>
            <span class="field__error" id="tf-date-msg"><?= e($dateError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="tf-cat">Category</label>
            <span class="field__hint" id="tf-cat-hint">Optional. An entry without a category is still counted in every total.</span>
            <select class="select" id="tf-cat" name="category"
                    aria-describedby="tf-cat-hint<?= $categoryError !== '' ? ' tf-cat-msg' : '' ?>"
                    <?= $categoryError !== '' ? 'aria-invalid="true"' : '' ?>>
                <option value="">No category</option>
<?php foreach ($formCategories as $formCategory): ?>
                <option value="<?= e((string) $formCategory['id']) ?>"<?= $category === (string) $formCategory['id'] ? ' selected' : '' ?>><?= e((string) $formCategory['name']) ?></option>
<?php endforeach; ?>
            </select>
<?php if ($categoryError !== ''): ?>
            <span class="field__error" id="tf-cat-msg"><?= e($categoryError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <label class="field__label" for="tf-desc">Description</label>
            <span class="field__hint" id="tf-desc-hint">Optional. Up to 255 characters.</span>
            <input class="input" id="tf-desc" name="description" type="text" maxlength="255" value="<?= e($description) ?>"
                   aria-describedby="tf-desc-hint<?= $descriptionError !== '' ? ' tf-desc-msg' : '' ?>"
                   <?= $descriptionError !== '' ? 'aria-invalid="true"' : '' ?>>
<?php if ($descriptionError !== ''): ?>
            <span class="field__error" id="tf-desc-msg"><?= e($descriptionError) ?></span>
<?php endif; ?>
        </div>

        <div class="btn-group">
            <button type="submit" class="btn btn--primary"><?= e($submit) ?></button>
            <a class="btn btn--ghost" href="/transactions">Cancel</a>
        </div>
    </form>
</div>

<?php if ($isEdit && $dangerRow !== null): ?>
<?php
    $dangerId          = (int) $dangerRow['id'];
    $dangerIsIncome    = $dangerRow['type'] === 'income';
    $dangerDescription = trim((string) ($dangerRow['description'] ?? ''));
    $dangerModalId     = 'tf-del-' . $dangerId;
?>

<div class="card transactions__danger">
    <h2>Danger zone</h2>
    <p class="field__hint">Deleting removes this entry from every report it appears in.</p>

    <button type="button" class="btn btn--danger" data-modal-open="<?= e($dangerModalId) ?>">Delete this transaction</button>

    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= e($dangerModalId) ?>-title" id="<?= e($dangerModalId) ?>">
        <h3 class="modal__title" id="<?= e($dangerModalId) ?>-title">Delete this transaction?</h3>
        <p>
            <strong><?php if ($dangerDescription !== ''): ?><?= e($dangerDescription) ?><?php else: ?><span aria-hidden="true">—</span><span class="sr-only">No description</span><?php endif; ?></strong><br>
            <?= e(formatLongDate((string) $dangerRow['occurred_on'])) ?> · <span class="amount amount--<?= $dangerIsIncome ? 'income' : 'expense' ?>"><?= e(formatSignedMoney((string) $dangerRow['amount'], $dangerIsIncome ? '+' : '−')) ?></span>
        </p>
        <p>This cannot be undone.</p>
        <form method="post" action="/transactions/delete">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="id" value="<?= e((string) $dangerId) ?>">
            <div class="modal__actions">
                <a class="btn btn--secondary" href="/transactions" data-modal-close="<?= e($dangerModalId) ?>">Cancel</a>
                <button type="submit" class="btn btn--danger" name="action" value="delete">Delete transaction</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
