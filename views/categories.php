<?php
/**
 * categories.php — the category list.
 *
 * The contract (docs/issues/issue-020.md):
 *
 *   1. requireLogin() is the FIRST call. An unauthenticated visitor never
 *      reaches a query and never sees a row of somebody else's categories.
 *   2. The list, the transaction counts and the totals come from ONE
 *      query. Two hand-written SELECTs drift, and a total that disagrees
 *      with the row it belongs to is a lie.
 *   3. The counts and the totals are computed in SQL. The money is a
 *      DECIMAL string and it is never turned into a float to be summed or
 *      compared.
 *   4. EVERY POST ends with a redirect. The state that has to survive the
 *      redirect travels in the session as a flash message.
 *   5. A delete never touches the transactions: the foreign key is ON
 *      DELETE SET NULL, so the rows survive as uncategorised.
 *   6. The rename is a SERVER-RENDERED state — /categories?rename=<id> —
 *      and it works without JavaScript.
 *   7. This file is RE-ENTERABLE: views/category_delete.php renders it
 *      when a delete fails, with the dialog still open and the status 500.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen. Everything below it belongs to the
// signed-in account.
// ---------------------------------------------------------------------------

requireLogin();

// ---------------------------------------------------------------------------
// 2. The shell.
//
// These variables are read by includes/header.php.
// ---------------------------------------------------------------------------

$title   = 'Categories';
$active  = 'categories';
$pageCss = 'categories.css';

$currentUser = getCurrentUser();
$username    = is_array($currentUser) ? (string) $currentUser['username'] : '';

// ---------------------------------------------------------------------------
// 3. The state.
//
// The flashes are read ONCE and removed on the way out, so a refresh does
// not repeat the message.
//
// The failed-delete state is NOT a flash: views/category_delete.php sets
// it and renders THIS file, because a failed delete answers with a 500
// and a redirect cannot carry a status code.
// ---------------------------------------------------------------------------

$deleteFailure = isset($deleteFailure) && is_array($deleteFailure) ? $deleteFailure : null;

$created     = takeFlash('category_created');
$createError = takeFlash('category_create_error');
$deleted     = takeFlash('category_deleted');
$renamed     = takeFlash('category_renamed');
$renameError = takeFlash('category_rename_error');

$created     = is_array($created) ? $created : null;
$createError = is_array($createError) ? $createError : null;
$renameError = is_array($renameError) ? $renameError : null;
$deleted     = is_string($deleted) && $deleted !== '' ? $deleted : '';
$renamed     = is_string($renamed) && $renamed !== '' ? $renamed : '';

// The create form's values. A rejected submit keeps what the visitor
// typed; a successful create clears the name and keeps the radio
// selection.
$createName = '';
$createType = 'expense';
$nameError  = '';
$typeError  = '';

if ($createError !== null) {
    $createName = (string) ($createError['name'] ?? '');
    $nameError  = (string) ($createError['name_error'] ?? '');
    $typeError  = (string) ($createError['type_error'] ?? '');
}

if ($created !== null) {
    $createType = (string) ($created['type'] ?? 'expense');
}

if (!in_array($createType, ['income', 'expense'], true)) {
    $createType = 'expense';
}

// The rename state. An id that is not a positive integer names no
// category, and a category of another account is never in the list — so
// its id simply puts no row in edit mode.
$renameRaw = query('rename', '0');
$renameId  = (ctype_digit($renameRaw) && (int) $renameRaw > 0) ? (int) $renameRaw : 0;

// ---------------------------------------------------------------------------
// 4. The list.
//
// ONE query: the categories of the signed-in user, the number of their
// transactions and the net of those transactions.
//
// The LEFT JOIN is what gives a category without transactions a count of
// zero and a total of zero — an inner join would drop the row and the
// category would vanish from the list.
//
// The join is scoped on the owner, so a transaction of another account can
// never be counted.
// ---------------------------------------------------------------------------

$uid        = (int) getCurrentUserId();
$categories = [];
$failed     = false;

try {
    $db = Database::getInstance();

    $listSql = <<<SQL
SELECT c.id,
       c.name,
       c.type,
       COUNT(t.id) AS tx_count,
       COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                         WHEN t.type = 'expense' THEN -t.amount END), 0) AS total
  FROM categories c
  LEFT JOIN transactions t
         ON t.category_id = c.id
        AND t.user_id = c.user_id
 WHERE c.user_id = ?
 GROUP BY c.id, c.name, c.type
 ORDER BY c.name
SQL;

    $categories = $db->fetchAll($listSql, [$uid]);
} catch (Throwable $ex) {
    logFailure('categories: the list could not be read', $ex);

    $failed = true;
}

// A failed delete answers with a 500. A failed list answers with a 500
// too — an error is never rendered as an empty list.
http_response_code(($failed || $deleteFailure !== null) ? 500 : 200);

$hasCategories = count($categories) > 0;

// The failed delete belongs to a row. If the row is gone — it cannot be,
// a failed delete changes nothing — the error is shown at the page level
// anyway, so the visitor is never left without an answer.
$failedRowShown = false;

if ($deleteFailure !== null) {
    foreach ($categories as $row) {
        if ((int) $row['id'] === (int) ($deleteFailure['id'] ?? 0)) {
            $failedRowShown = true;
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// 5. The display values.
//
// The name of the category that was added, for the success message.
// ---------------------------------------------------------------------------

$createdName = $created !== null ? (string) ($created['name'] ?? '') : '';

require __DIR__ . '/../includes/header.php';
?>

<div class="page__header">
    <div>
        <h1 class="page__title">Categories</h1>
        <p class="page__subtitle">Optional labels that group your transactions in the reports.</p>
    </div>
</div>

<?php if ($failed): ?>

<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">The categories could not be loaded.</p>
            <p>Please try again.</p>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($deleteFailure !== null && !$failedRowShown): ?>

<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">The category could not be deleted. Please try again.</p>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($deleted !== '' || $renamed !== ''): ?>

<div role="status" aria-live="polite">
    <div class="alert alert--success">
        <div class="alert__body">
<?php if ($deleted !== ''): ?>
            <p class="alert__title"><?= e($deleted) ?></p>
<?php else: ?>
            <p class="alert__title"><?= e($renamed) ?></p>
<?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<div class="card cat__block">
    <h2>New category</h2>

<?php if ($createdName !== ''): ?>

    <div role="status" aria-live="polite">
        <div class="alert alert--success">
            <div class="alert__body">
                <p class="alert__title">The category &quot;<?= e($createdName) ?>&quot; was added.</p>
            </div>
        </div>
    </div>

<?php endif; ?>

    <form method="post" action="/categories/save" aria-label="Create a category">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field">
            <label class="field__label" for="cat-name">Name</label>
            <span class="field__hint" id="cat-name-hint">Up to 60 characters. The name must be unique.</span>
            <input class="input" id="cat-name" name="name" type="text"
                   maxlength="60" value="<?= e($createName) ?>"
                   aria-describedby="cat-name-hint<?= $nameError !== '' ? ' cat-name-msg' : '' ?>"
                   <?= $nameError !== '' ? 'aria-invalid="true"' : '' ?>>
<?php if ($nameError !== ''): ?>
            <span class="field__error" id="cat-name-msg"><?= e($nameError) ?></span>
<?php endif; ?>
        </div>

        <div class="field">
            <span class="field__label" id="cat-type-label">Type</span>
            <div class="radio-group" role="radiogroup" aria-labelledby="cat-type-label"<?= $typeError !== '' ? ' aria-describedby="cat-type-msg"' : '' ?>>
                <label class="radio radio--income" for="cat-in">
                    <input type="radio" id="cat-in" name="type" value="income"<?= $createType === 'income' ? ' checked' : '' ?><?= $typeError !== '' ? ' aria-invalid="true"' : '' ?>> Income
                </label>
                <label class="radio radio--expense" for="cat-out">
                    <input type="radio" id="cat-out" name="type" value="expense"<?= $createType === 'expense' ? ' checked' : '' ?><?= $typeError !== '' ? ' aria-invalid="true"' : '' ?>> Expense
                </label>
            </div>
<?php if ($typeError !== ''): ?>
            <span class="field__error" id="cat-type-msg"><?= e($typeError) ?></span>
<?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary">Add category</button>
    </form>
</div>

<?php if (!$hasCategories && !$failed): ?>

<div class="card cat__block">
    <div class="empty">
        <h3 class="empty__title">No categories yet.</h3>
        <p class="empty__body">
            That is fine — uncategorised entries are still counted in every
            total and every report.
        </p>
    </div>
</div>

<?php elseif ($hasCategories): ?>

<div class="card card--flush cat__block">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="sr-only">Your categories</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Type</th>
                    <th scope="col" class="num">Transactions</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($categories as $row): ?>
<?php
    $rowId     = (int) $row['id'];
    $rowName   = (string) $row['name'];
    $rowType   = (string) $row['type'];
    $rowCount  = (int) $row['tx_count'];
    $rowTotal  = formatCategoryTotal((string) $row['total'], $rowCount);
    $rowIncome = $rowType === 'income';
    $modalId   = 'cat-del-' . $rowId;

    // The row that is being renamed.
    $isEditing = $renameId === $rowId;

    // The error of a failed rename: it belongs to this row, it keeps the
    // typed value and the row stays in edit mode.
    $renameMessage = '';
    $renameValue   = $rowName;

    if ($isEditing && $renameError !== null && (int) ($renameError['id'] ?? 0) === $rowId) {
        $renameMessage = (string) ($renameError['message'] ?? '');
        $renameValue   = (string) ($renameError['value'] ?? $rowName);
    }

    // The row whose delete failed: its dialog stays open, with the error
    // visible and the button offering to try again.
    $rowFailed = $deleteFailure !== null && (int) ($deleteFailure['id'] ?? 0) === $rowId;
?>
                <tr>
                    <th scope="row" class="cat__name">
<?php if ($isEditing): ?>
                        <label class="sr-only" for="cat-rename-<?= e((string) $rowId) ?>">Rename <?= e($rowName) ?></label>
                        <input class="input" id="cat-rename-<?= e((string) $rowId) ?>" name="name" type="text"
                               form="cat-rename-form-<?= e((string) $rowId) ?>"
                               value="<?= e($renameValue) ?>" maxlength="60"
                               <?= $renameMessage !== '' ? 'aria-invalid="true" aria-describedby="cat-rename-msg-' . e((string) $rowId) . '"' : '' ?>>
<?php if ($renameMessage !== ''): ?>
                        <span class="field__error" id="cat-rename-msg-<?= e((string) $rowId) ?>"><?= e($renameMessage) ?></span>
<?php endif; ?>
<?php else: ?>
                        <?= e($rowName) ?>
<?php endif; ?>
                    </th>
                    <td data-label="Type"><span class="badge badge--<?= $rowIncome ? 'income' : 'expense' ?>"><?= e(categoryTypeLabel($rowType)) ?></span></td>
                    <td data-label="Transactions" class="num"><?= e((string) $rowCount) ?></td>
                    <td data-label="Total" class="num amount amount--<?= e($rowTotal['modifier']) ?>"><?= e($rowTotal['label']) ?></td>
                    <td data-label="Actions">
<?php if ($isEditing): ?>
                        <div class="tx__actions">
                            <form class="cat__rename-form" id="cat-rename-form-<?= e((string) $rowId) ?>" method="post" action="/categories/save">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= e((string) $rowId) ?>">
                                <button type="submit" class="btn btn--primary btn--sm">Save</button>
                            </form>
                            <a class="btn btn--ghost btn--sm" href="/categories">Cancel</a>
                        </div>
<?php else: ?>
                        <div class="tx__actions">
                            <a class="btn btn--ghost btn--sm" href="/categories?rename=<?= e((string) $rowId) ?>">Rename</a>
                            <button type="button" class="btn btn--ghost btn--sm" data-modal-open="<?= e($modalId) ?>">Delete</button>
                        </div>
<?php endif; ?>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* The delete confirmations — one per row, each with its own ids. */ ?>
<div class="cat__dialogs">
<?php foreach ($categories as $row): ?>
<?php
    $modalId    = 'cat-del-' . (int) $row['id'];
    $modalName  = (string) $row['name'];
    $modalCount = (int) $row['tx_count'];
    $rowFailed  = $deleteFailure !== null && (int) ($deleteFailure['id'] ?? 0) === (int) $row['id'];
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= e($modalId) ?>-title" id="<?= e($modalId) ?>"<?= $rowFailed ? ' data-modal-keep' : '' ?>>
    <h3 class="modal__title" id="<?= e($modalId) ?>-title">Delete '<?= e($modalName) ?>'?</h3>
<?php if ($rowFailed): ?>

    <div role="alert">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">The category could not be deleted. Please try again.</p>
            </div>
        </div>
    </div>

<?php endif; ?>

    <p>
        <strong><?= e(uncategorisedNotice($modalCount)) ?></strong>
    </p>
    <form method="post" action="/categories/delete">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
        <div class="modal__actions">
            <a class="btn btn--secondary" href="/categories" data-modal-close="<?= e($modalId) ?>">Cancel</a>
            <button type="submit" class="btn btn--danger"><?= $rowFailed ? 'Try again' : 'Delete category' ?></button>
        </div>
    </form>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
