<?php
/**
 * transactions.php — the transaction list.
 *
 * The contract (docs/issues/issue-018.md):
 *
 *   1. requireLogin() is the FIRST call. An unauthenticated visitor never
 *      reaches a query and never sees a row of somebody else's money.
 *   2. The predicate is built ONCE, by buildTransactionPredicate(), and
 *      the row query, the totals query and the count query all read it.
 *      Two hand-written WHERE clauses drift, and a total that disagrees
 *      with the list it belongs to is a lie.
 *   3. The three tiles show the totals of the FILTERED set, and the net
 *      is computed by the DATABASE — two decimal strings added in PHP
 *      become floats, and the sum drifts by a cent.
 *   4. An error is NEVER rendered as 0.00. A failure renders the error
 *      state and no tiles at all.
 *   5. The three empty and error states are told apart ON THE SERVER. The
 *      browser never infers them from an empty list.
 *   6. The browser sums nothing and sorts nothing: the page is rendered
 *      by PHP, and the sort column comes from a whitelist.
 *   7. Every cell carries a data-label, so the table stays readable when
 *      it collapses into a stack on a narrow screen.
 *   8. The page size lives in one place: TRANSACTIONS_PER_PAGE.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen. Everything below it — the queries, the
// tiles, the table — belongs to the signed-in account.
// ---------------------------------------------------------------------------

requireLogin();

// ---------------------------------------------------------------------------
// 2. The shell.
//
// The application shell: the app bar, the navigation and the bottom bar.
// These variables are read by includes/header.php.
// ---------------------------------------------------------------------------

$title   = 'Transactions';
$active  = 'transactions';
$pageCss = 'transactions.css';

$currentUser = getCurrentUser();
$username    = is_array($currentUser) ? (string) $currentUser['username'] : '';

// ---------------------------------------------------------------------------
// 3. The filters, the sort and the page.
//
// An unknown value in the query string is IGNORED, never an error: a
// hand-edited URL must not produce a 500. The sort column and the sort
// direction both come from a whitelist, so nothing from the request ever
// reaches the SQL as an identifier.
// ---------------------------------------------------------------------------

$filters = parseTransactionFilters();
$sort    = normalizeTransactionSort(query('sort'), query('dir'));

$pageRaw = query('page', '1');
$page    = (ctype_digit($pageRaw) && (int) $pageRaw > 0) ? (int) $pageRaw : 1;

$perPage = TRANSACTIONS_PER_PAGE;

// The three preset ranges. The dates are relative to the day the page is
// rendered, and the script recomputes them in the browser; the values
// here are what a visitor without JavaScript sees.
$presetMonth = resolvePeriodRange('month');
$presetWeek  = resolvePeriodRange('week');
$presetYear  = resolvePeriodRange('year');

// ---------------------------------------------------------------------------
// 4. The queries.
//
// Four statements, ONE predicate:
//
//   - the totals, for the three tiles;
//   - the count, for the status line and the pagination;
//   - the existence check, which tells the account state from the filter
//     state;
//   - the rows, for the page.
//
// The totals and the count are computed over the FILTERED set, so the
// tiles always describe the rows the visitor is looking at.
// ---------------------------------------------------------------------------

$uid          = (int) getCurrentUserId();
$summary      = null;
$rows         = [];
$categories   = [];
$total        = 0;
$pageCount    = 1;
$accountEmpty = false;
$failed       = false;

try {
    $db = Database::getInstance();

    // The categories of the signed-in user, for the filter bar. The
    // ownership is in the SQL.
    $categories = $db->fetchAll(
        'SELECT c.id, c.name
           FROM categories c
          WHERE c.user_id = ?
          ORDER BY c.name ASC',
        [$uid]
    );

    $predicate = buildTransactionPredicate($uid, $filters);
    $where     = $predicate['where'];
    $params    = $predicate['params'];

    $summarySql = <<<SQL
SELECT COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS income,
       COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS spending,
       COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                         WHEN t.type = 'expense' THEN -t.amount END), 0) AS net,
       SIGN(COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                              WHEN t.type = 'expense' THEN -t.amount END), 0)) AS net_sign
  FROM transactions t
 WHERE {$where}
SQL;

    $summary = $db->fetchOne($summarySql, $params);

    $countSql = <<<SQL
SELECT COUNT(*) AS n
  FROM transactions t
 WHERE {$where}
SQL;

    $countRow = $db->fetchOne($countSql, $params);
    $total    = (int) ($countRow['n'] ?? 0);

    // The existence check: does the ACCOUNT have any transaction at all?
    // This is what tells "you have no transactions yet" apart from "your
    // filters match nothing".
    $anySql = <<<'SQL'
SELECT 1
  FROM transactions t
 WHERE t.user_id = ?
 LIMIT 1
SQL;

    $anyTransaction = $db->fetchOne($anySql, [$uid]);
    $accountEmpty   = ($anyTransaction === false);

    // A page past the last one shows the last one, never an empty page.
    $pageCount = max(1, (int) ceil($total / $perPage));
    $page      = min($page, $pageCount);
    $offset    = ($page - 1) * $perPage;

    // The ORDER BY comes from the whitelist, and the LIMIT and the OFFSET
    // are the only interpolated values — both cast to an integer.
    //
    // The cast happens BEFORE the interpolation: a heredoc interpolates a
    // VARIABLE, not an expression, so "{(int) $perPage}" would reach
    // the database as the literal text "{(int) 25}".
    $limitValue  = (int) $perPage;
    $offsetValue = (int) $offset;

    $rowsSql = <<<SQL
SELECT t.id, t.occurred_on, t.description, t.type, t.amount,
       t.category_id, c.name AS category_name
  FROM transactions t
  LEFT JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id
 WHERE {$where}
 ORDER BY {$sort['order']}
 LIMIT {$limitValue} OFFSET {$offsetValue}
SQL;

    $rows = $db->fetchAll($rowsSql, $params);
} catch (Throwable $ex) {
    // The context of the failure goes to the log; the page shows plain copy
    // only, and the status is a 500.
    logFailure('transactions: the list could not be read', $ex);

    $failed = true;
}

// ---------------------------------------------------------------------------
// 5. The state.
//
// Three states that are not the populated list, chosen ON THE SERVER:
//
//   'error'   — the queries failed;
//   'account' — the account has never recorded anything;
//   'filter'  — the account has records, but the filters match none;
//   ''        — there is something to show.
//
// The filter state keeps the filter bar and the status line: the filters
// are the thing the visitor has to change. The account state keeps
// neither — there is nothing to filter yet.
// ---------------------------------------------------------------------------

if ($failed) {
    $state = 'error';
} elseif ($accountEmpty) {
    $state = 'account';
} elseif ($total === 0) {
    $state = 'filter';
} else {
    $state = '';
}

http_response_code($failed ? 500 : 200);

// ---------------------------------------------------------------------------
// 6. The display values.
//
// Every one of them is prepared here, so the markup below only reads.
// ---------------------------------------------------------------------------

$rangeLabel    = transactionRangeLabel($filters['from'], $filters['to']);
$countLabel    = (string) $total;
$incomeLabel   = '';
$spendingLabel = '';
$netLabel      = '';
$netModifier   = 'tile--net-zero';

if ($summary !== null && $summary !== false && !$failed) {
    $netSign = (int) ($summary['net_sign'] ?? 0);

    $incomeLabel   = formatSignedMoney((string) $summary['income'], '+');
    $spendingLabel = formatSignedMoney((string) $summary['spending'], '−');
    $netLabel      = formatSignedMoney(
        (string) $summary['net'],
        $netSign > 0 ? '+' : ($netSign < 0 ? '−' : '')
    );

    // A zero net is NEUTRAL. Never a danger colour.
    $netModifier = $netSign > 0
        ? 'tile--net-positive'
        : ($netSign < 0 ? 'tile--net-negative' : 'tile--net-zero');
}

// The caption of the table, in the long form.
if (isValidDateString($filters['from']) && isValidDateString($filters['to'])) {
    $caption = 'Transactions from ' . formatLongDate($filters['from'])
        . ' to ' . formatLongDate($filters['to']);
} else {
    $caption = 'Transactions';
}

// The pagination status: the rows of THIS page, and the count of the
// whole filtered set.
$firstOnPage = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$lastOnPage  = $total === 0 ? 0 : min($page * $perPage, $total);

// The sort links. Clicking the active column flips the direction; the
// other column takes its own default. Every link keeps the filters and
// goes back to the first page.
$dateDir = $sort['key'] === 'date' ? ($sort['dir'] === 'asc' ? 'desc' : 'asc') : 'desc';
$amtDir  = $sort['key'] === 'amount' ? ($sort['dir'] === 'asc' ? 'desc' : 'asc') : 'asc';

// The filter bar's own values. The visitor always sees the state they
// are in.
//
// The options are VALUE / LABEL pairs and not an associative array: PHP
// turns the numeric string key of a category id into an integer key, and
// the "is this the selected one" comparison would then be a string
// against an integer — and always false.
$typeOptions = [
    ['', 'All types'],
    ['income', 'Income'],
    ['expense', 'Spending'],
];

$categoryOptions = [['', 'All categories']];

foreach ($categories as $category) {
    $categoryOptions[] = [(string) $category['id'], (string) $category['name']];
}

// "Uncategorised" is a FILTER, not a category — it has no id of its own.
$categoryOptions[] = ['none', 'Uncategorised'];

require __DIR__ . '/../includes/header.php';
?>

<div class="page__header">
    <div>
        <h1 class="page__title">Transactions</h1>
        <p class="page__subtitle">Search, filter and sum your entries over any period.</p>
    </div>
    <div class="page__actions">
        <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
    </div>
</div>

<?php if ($state === 'error'): ?>

<div role="alert">
    <div class="alert alert--danger">
        <div class="alert__body">
            <p class="alert__title">The transactions could not be loaded.</p>
            <p>Please try again.</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="empty">
        <h3 class="empty__title">No results available</h3>
        <p class="empty__body">The request failed, so no list is shown. This is not an empty result.</p>
        <button type="button" class="btn btn--secondary" data-reload>Try again</button>
    </div>
</div>

<?php elseif ($state === 'account'): ?>

<div class="card">
    <div class="empty">
        <h3 class="empty__title">You have no transactions yet.</h3>
        <p class="empty__body">Record your first entry to start building your history.</p>
        <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
    </div>
</div>

<?php else: ?>

<form class="filters transactions__filters" role="search" aria-label="Filter transactions" method="get" action="/transactions">
    <div class="filters__search">
        <label class="field__label" for="tx-search">Search</label>
        <input class="input" id="tx-search" name="q" type="search" placeholder="Search the description" value="<?= e($filters['q']) ?>">
    </div>

    <div class="filters__type">
        <label class="field__label" for="tx-type">Type</label>
        <select class="select" id="tx-type" name="type">
<?php foreach ($typeOptions as [$typeValue, $typeLabel]): ?>
            <option value="<?= e($typeValue) ?>"<?= $filters['type'] === $typeValue ? ' selected' : '' ?>><?= e($typeLabel) ?></option>
<?php endforeach; ?>
        </select>
    </div>

    <div class="filters__cat">
        <label class="field__label" for="tx-category">Category</label>
        <select class="select" id="tx-category" name="category">
<?php foreach ($categoryOptions as [$categoryValue, $categoryLabel]): ?>
            <option value="<?= e($categoryValue) ?>"<?= $filters['category'] === $categoryValue ? ' selected' : '' ?>><?= e($categoryLabel) ?></option>
<?php endforeach; ?>
        </select>
    </div>

    <div class="filters__from">
        <label class="field__label" for="tx-from">From</label>
        <input class="input" id="tx-from" name="from" type="date" value="<?= e($filters['from']) ?>">
    </div>

    <div class="filters__to">
        <label class="field__label" for="tx-to">To</label>
        <input class="input" id="tx-to" name="to" type="date" value="<?= e($filters['to']) ?>">
    </div>

    <div class="filters__actions">
        <div class="filters__presets" role="group" aria-label="Period presets" data-week-start="<?= e((string) weekStartDay()) ?>">
            <button type="button" class="btn btn--secondary btn--sm"
                    data-preset="month" data-preset-from="<?= e($presetMonth[0]) ?>" data-preset-to="<?= e($presetMonth[1]) ?>">This month</button>
            <button type="button" class="btn btn--secondary btn--sm"
                    data-preset="week" data-preset-from="<?= e($presetWeek[0]) ?>" data-preset-to="<?= e($presetWeek[1]) ?>">This week</button>
            <button type="button" class="btn btn--secondary btn--sm"
                    data-preset="year" data-preset-from="<?= e($presetYear[0]) ?>" data-preset-to="<?= e($presetYear[1]) ?>">This year</button>
        </div>
        <div class="btn-group">
            <button type="submit" class="btn btn--primary">Apply filters</button>
            <a class="btn btn--ghost" href="/transactions">Clear filters</a>
        </div>
    </div>
</form>

<p class="pagination__status transactions__status"><?= e($countLabel) ?> transactions · <?= e($rangeLabel) ?></p>

<?php if ($state === 'filter'): ?>

<div class="card">
    <div class="empty">
        <h3 class="empty__title">No transactions match these filters.</h3>
        <p class="empty__body">Try widening the date range or clearing the filters.</p>
        <a class="btn btn--secondary" href="/transactions">Clear filters</a>
    </div>
</div>

<?php else: ?>

<div class="tiles">
    <div class="tile tile--income">
        <span class="tile__label">Income</span>
        <span class="tile__amount"><?= e($incomeLabel) ?></span>
        <span class="tile__period">filtered total</span>
    </div>
    <div class="tile tile--spending">
        <span class="tile__label">Spending</span>
        <span class="tile__amount"><?= e($spendingLabel) ?></span>
        <span class="tile__period">filtered total</span>
    </div>
    <div class="tile tile--net <?= e($netModifier) ?>">
        <span class="tile__label">Net</span>
        <span class="tile__amount"><?= e($netLabel) ?></span>
        <span class="tile__period">filtered total</span>
    </div>
</div>

<div class="card card--flush">
    <div class="table-wrap">
        <table class="data-table">
            <caption class="sr-only"><?= e($caption) ?></caption>
            <thead>
                <tr>
                    <th scope="col">
                        <a class="data-table__sort" href="<?= e(transactionListUrl($filters, 'date', $dateDir, 1)) ?>">Date<?php if ($sort['key'] === 'date'): ?> <span aria-hidden="true"><?= $sort['dir'] === 'asc' ? '↑' : '↓' ?></span><span class="sr-only">sorted by date, <?= $sort['dir'] === 'asc' ? 'oldest first' : 'newest first' ?></span><?php endif; ?></a>
                    </th>
                    <th scope="col">Description</th>
                    <th scope="col">Category</th>
                    <th scope="col">Type</th>
                    <th scope="col" class="num">
                        <a class="data-table__sort" href="<?= e(transactionListUrl($filters, 'amount', $amtDir, 1)) ?>">Amount<?php if ($sort['key'] === 'amount'): ?> <span aria-hidden="true"><?= $sort['dir'] === 'asc' ? '↑' : '↓' ?></span><span class="sr-only">sorted by amount, <?= $sort['dir'] === 'asc' ? 'smallest first' : 'largest first' ?></span><?php endif; ?></a>
                    </th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($rows as $row): ?>
<?php
    $rowId        = (int) $row['id'];
    $rowIsIncome  = $row['type'] === 'income';
    $rowAmount    = (string) $row['amount'];
    $rowCategory  = trim((string) ($row['category_name'] ?? ''));
    $rowDescription = trim((string) ($row['description'] ?? ''));
    $rowDate      = (string) $row['occurred_on'];
?>
                <tr>
                    <td data-label="Date"><?= e($rowDate) ?></td>
                    <td data-label="Description"><?php if ($rowDescription !== ''): ?><?= e($rowDescription) ?><?php else: ?><span aria-hidden="true">—</span><span class="sr-only">No description</span><?php endif; ?></td>
                    <td data-label="Category"><span class="badge badge--neutral"><?= e($rowCategory !== '' ? $rowCategory : 'Uncategorised') ?></span></td>
                    <td data-label="Type"><span class="badge badge--<?= $rowIsIncome ? 'income' : 'expense' ?>"><?= $rowIsIncome ? 'Income' : 'Spending' ?></span></td>
                    <td data-label="Amount" class="num amount amount--<?= $rowIsIncome ? 'income' : 'expense' ?>"><?= e(formatSignedMoney($rowAmount, $rowIsIncome ? '+' : '−')) ?></td>
                    <td data-label="Actions">
                        <span class="tx__actions">
                            <a href="/transactions/edit?id=<?= e((string) $rowId) ?>">Edit</a>
                            <button type="button" class="btn btn--ghost btn--sm" data-modal-open="t-del-<?= e((string) $rowId) ?>">Delete</button>
                        </span>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card__footer">
        <div class="pagination">
<?php if ($page > 1): ?>
            <a class="btn btn--secondary btn--sm" href="<?= e(transactionListUrl($filters, $sort['key'], $sort['dir'], $page - 1)) ?>">Previous</a>
<?php else: ?>
            <button type="button" class="btn btn--secondary btn--sm" disabled>Previous</button>
<?php endif; ?>
            <span class="pagination__status">Showing <?= e((string) $firstOnPage) ?>–<?= e((string) $lastOnPage) ?> of <?= e($countLabel) ?> transactions</span>
<?php if ($page < $pageCount): ?>
            <a class="btn btn--secondary btn--sm" href="<?= e(transactionListUrl($filters, $sort['key'], $sort['dir'], $page + 1)) ?>">Next</a>
<?php else: ?>
            <button type="button" class="btn btn--secondary btn--sm" disabled>Next</button>
<?php endif; ?>
        </div>
    </div>
</div>

<?php /* The delete confirmations — one per row, each with its own ids. */ ?>
<?php foreach ($rows as $row): ?>
<?php
    $modalId       = 't-del-' . (int) $row['id'];
    $rowIsIncome   = $row['type'] === 'income';
    $rowAmount     = (string) $row['amount'];
    $rowDescription = trim((string) ($row['description'] ?? ''));
?>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= e($modalId) ?>-title" id="<?= e($modalId) ?>">
    <h3 class="modal__title" id="<?= e($modalId) ?>-title">Delete this transaction?</h3>
    <p>
        <strong><?php if ($rowDescription !== ''): ?><?= e($rowDescription) ?><?php else: ?><span aria-hidden="true">—</span><span class="sr-only">No description</span><?php endif; ?></strong><br>
        <?= e(formatLongDate((string) $row['occurred_on'])) ?> · <span class="amount amount--<?= $rowIsIncome ? 'income' : 'expense' ?>"><?= e(formatSignedMoney($rowAmount, $rowIsIncome ? '+' : '−')) ?></span>
    </p>
    <p>This cannot be undone.</p>
    <form method="post" action="/transactions/delete">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
        <div class="modal__actions">
            <a class="btn btn--secondary" href="/transactions" data-modal-close="<?= e($modalId) ?>">Cancel</a>
            <button type="submit" class="btn btn--danger" name="action" value="delete">Delete transaction</button>
        </div>
    </form>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
