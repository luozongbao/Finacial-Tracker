<?php
/**
 * dashboard.php — the dashboard.
 *
 * The contract (docs/issues/issue-017.md):
 *
 *   1. requireLogin() is the FIRST call. An unauthenticated visitor never
 *      reaches a query and never sees a row of somebody else's money.
 *   2. The period comes from the query string and defaults to the month.
 *      normalizePeriod() owns the vocabulary: "today" and "day" are the
 *      same period, and an unknown value falls back to the month.
 *   3. resolvePeriodRange() is the ONLY place a period becomes a range.
 *   4. The three sums are computed in SQL, in ONE statement, and the net
 *      is computed by the DATABASE — two decimal strings added in PHP
 *      become floats, and the sum drifts by a cent.
 *   5. Every statement filters on the owning user id, and the id comes
 *      from the SESSION — never from a request parameter.
 *   6. An error is NEVER rendered as 0.00. A zero is a claim about the
 *      data; an error is a claim about the request. They must never look
 *      alike, so a failure renders the error state and no tiles at all.
 *   7. The two empty states are told apart ON THE SERVER. The browser
 *      never infers them from an empty list.
 *   8. The browser sums nothing. The page is server-rendered, and the
 *      loading skeleton of the mockup is not a state this application has.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen. Everything below it — the queries, the
// tiles, the recent list — belongs to the signed-in account.
// ---------------------------------------------------------------------------

requireLogin();

// ---------------------------------------------------------------------------
// 2. The shell.
//
// The application shell: the app bar, the navigation and the bottom bar.
// These variables are read by includes/header.php.
// ---------------------------------------------------------------------------

$title   = 'Dashboard';
$active  = 'dashboard';
$pageCss = 'dashboard.css';

$currentUser = getCurrentUser();
$username    = is_array($currentUser) ? (string) $currentUser['username'] : '';

// ---------------------------------------------------------------------------
// 3. The period.
//
// The selector's own token for the active period. The day period is
// "Today" in the selector, whatever the query string called it, so the
// group attribute and the link agree with each other.
// ---------------------------------------------------------------------------

$period      = normalizePeriod(query('period', 'month'));
$periodToken = $period === 'day' ? 'today' : $period;

$from = query('from');
$to   = query('to');

// ---------------------------------------------------------------------------
// 4. The range.
//
// resolvePeriodRange() is the only place a period becomes a range. A
// custom range that cannot be used is an ERROR, not a swap: the form
// comes back with the message and the values that were typed, so the
// visitor can correct them instead of starting over.
// ---------------------------------------------------------------------------

$range         = null;   // [from, to] once the period has been resolved
$rangeError    = '';     // the message for an unusable custom range
$showRangeForm = false;  // the form stands in for the tiles

if ($period === 'custom' && $from === '' && $to === '') {
    // A custom period with no range at all: the form asks for one.
    $showRangeForm = true;
} else {
    try {
        $range = resolvePeriodRange($period, $from, $to);
    } catch (InvalidArgumentException $ex) {
        $rangeError    = $ex->getMessage();
        $showRangeForm = true;
    }
}

// ---------------------------------------------------------------------------
// 5. The data.
//
// Three statements. Every one of them filters on the owning user id, and
// the id comes from the session.
//
// The sums are ONE statement: the net is the difference of the two sides,
// computed by the database in DECIMAL. SIGN() comes back with it, so the
// sign of the net is decided by the value and never by a PHP comparison
// of money.
// ---------------------------------------------------------------------------

$summarySql = <<<'SQL'
SELECT
    COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0)          AS income,
    COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0)          AS spending,
    COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                      WHEN t.type = 'expense' THEN -t.amount END), 0)         AS net,
    SIGN(COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                            WHEN t.type = 'expense' THEN -t.amount END), 0))   AS net_sign
  FROM transactions t
 WHERE t.user_id = ?
   AND t.occurred_on BETWEEN ? AND ?
SQL;

$periodCountSql = <<<'SQL'
SELECT COUNT(*) AS n
  FROM transactions t
 WHERE t.user_id = ?
   AND t.occurred_on BETWEEN ? AND ?
SQL;

$anyTransactionSql = <<<'SQL'
SELECT 1
  FROM transactions t
 WHERE t.user_id = ?
 LIMIT 1
SQL;

$recentSql = <<<'SQL'
SELECT t.occurred_on, t.description, t.amount, t.type, c.name AS category_name
  FROM transactions t
  LEFT JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id
 WHERE t.user_id = ?
 ORDER BY t.occurred_on DESC, t.id DESC
 LIMIT 10
SQL;

$uid          = (int) getCurrentUserId();
$summary      = null;
$recent       = [];
$failed       = false;
$periodEmpty  = false;
$accountEmpty = false;

try {
    $db = Database::getInstance();

    if ($range !== null) {
        $summary = $db->fetchOne($summarySql, [$uid, $range[0], $range[1]]);

        // The empty-period check runs ONLY when the sums are all zero: a
        // non-zero sum implies at least one row, so the count is needed
        // for the empty state and for nothing else.
        if (
            $summary !== false
            && isZeroAmount((string) $summary['income'])
            && isZeroAmount((string) $summary['spending'])
        ) {
            $count = $db->fetchOne($periodCountSql, [$uid, $range[0], $range[1]]);

            $periodEmpty = (int) ($count['n'] ?? 0) === 0;
        }
    }

    // The "any transaction at all" check. A separate, cheap query — LIMIT 1
    // on the (user_id, occurred_on) index. It is what tells the two empty
    // states apart: the account may well hold rows from another period.
    $any = $db->fetchOne($anyTransactionSql, [$uid]);

    $accountEmpty = $any === false;

    // The recent list is NOT filtered by the period: it is the account's
    // latest entries, and the LIMIT is in the query.
    $recent = $db->fetchAll($recentSql, [$uid]);
} catch (Throwable $ex) {
    // The context of the failure goes to the error log, where the operator
    // can read it. The page gets the error state: an error is never
    // rendered as 0.00, and the recent list is not rendered either — it
    // comes from the same failure.
    logFailure('dashboard: the totals could not be read', $ex);

    $failed       = true;
    $summary      = null;
    $recent       = [];
    $periodEmpty  = false;
    $accountEmpty = false;
}

// ---------------------------------------------------------------------------
// 6. The display values.
//
// The sign is chosen here and applied by formatSignedMoney(); the template
// only concatenates. The net's sign comes from the SQL, so no PHP ever
// compares money.
//
// The zero of the net is NEUTRAL: a net of exactly zero is not a good
// month and not a bad one.
// ---------------------------------------------------------------------------

$incomeLabel   = '';
$spendingLabel = '';
$netLabel      = '';
$netModifier   = 'tile--net-zero';
$periodLabel   = '';

if ($summary !== null && $summary !== false) {
    $netSign = (int) ($summary['net_sign'] ?? 0);

    $incomeLabel   = formatSignedMoney((string) $summary['income'], '+');
    $spendingLabel = formatSignedMoney((string) $summary['spending'], '−');
    $netLabel      = formatSignedMoney(
        (string) $summary['net'],
        $netSign > 0 ? '+' : ($netSign < 0 ? '−' : '')
    );

    $netModifier = $netSign > 0
        ? 'tile--net-positive'
        : ($netSign < 0 ? 'tile--net-negative' : 'tile--net-zero');
}

if ($range !== null) {
    $periodLabel = formatPeriodLabel($range[0], $range[1], $period);
}

// ---------------------------------------------------------------------------
// 7. The states.
//
// The empty state is chosen ON THE SERVER:
//
//   'account' — the account has never recorded anything.
//   'period'  — the account has records, but not in this period.
//   ''        — there is something to show.
// ---------------------------------------------------------------------------

$emptyState = '';

if (!$failed) {
    if ($accountEmpty) {
        $emptyState = 'account';
    } elseif ($periodEmpty) {
        $emptyState = 'period';
    }
}

// The name of the period, for the empty state that names it.
$periodNames = [
    'day'    => 'today',
    'week'   => 'this week',
    'month'  => 'this month',
    'year'   => 'this year',
    'custom' => 'in this period',
];

$periodName = $periodNames[$period] ?? 'in this period';

// The exact range, as a phrase inside a sentence.
$rangePhrase = $range !== null ? formatPeriodSpan($range[0], $range[1], 'and') : '';

// The sibling period to suggest. The periods are siblings, so the button
// switches to the next one up — the week suggests the month, the month
// suggests the year.
$suggestions = [
    'day'    => ['week',  'Look at this week'],
    'week'   => ['month', 'Look at this month'],
    'month'  => ['year',  'Look at this year'],
    'year'   => ['month', 'Look at this month'],
    'custom' => ['month', 'Look at this month'],
];

$suggestion = $suggestions[$period] ?? $suggestions['month'];

// The two date fields keep the values that were typed — including an
// unusable one, so the visitor can correct it. With no range at all they
// show the current month.
$monthRange = resolvePeriodRange('month');
$fromValue  = $from !== '' ? $from : $monthRange[0];
$toValue    = $to !== '' ? $to : $monthRange[1];

// ---------------------------------------------------------------------------
// 8. The page.
//
// A view owns its page: the header and the footer are included here, and
// the controller never emits them.
// ---------------------------------------------------------------------------

require __DIR__ . '/../includes/header.php';
?>
<div class="page__header">
    <div>
        <h1 class="page__title">Dashboard</h1>
        <p class="page__subtitle">Your income, spending and net for the selected period.</p>
    </div>
    <div class="page__actions">
        <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
    </div>
</div>

<div class="period" data-period-group="<?= e($periodToken) ?>">
    <a class="period__link" href="/dashboard?period=today"  data-period="today"<?= $periodToken === 'today' ? ' aria-current="true"' : '' ?>>Today</a>
    <a class="period__link" href="/dashboard?period=week"   data-period="week"<?= $periodToken === 'week' ? ' aria-current="true"' : '' ?>>This week</a>
    <a class="period__link" href="/dashboard?period=month"  data-period="month"<?= $periodToken === 'month' ? ' aria-current="true"' : '' ?>>This month</a>
    <a class="period__link" href="/dashboard?period=year"   data-period="year"<?= $periodToken === 'year' ? ' aria-current="true"' : '' ?>>This year</a>
    <a class="period__link" href="/dashboard?period=custom" data-period="custom"<?= $periodToken === 'custom' ? ' aria-current="true"' : '' ?>>Custom</a>
</div>

<?php if ($failed): ?>

    <div role="alert">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">The summary could not be loaded.</p>
                <p>Please try again.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="empty">
            <h3 class="empty__title">No summary available</h3>
            <p class="empty__body">The numbers could not be retrieved, so nothing is shown here.</p>
            <button type="button" class="btn btn--secondary" data-reload>Try again</button>
        </div>
    </div>

<?php else: ?>

<?php if ($showRangeForm): ?>

<?php if ($rangeError !== ''): ?>
    <div role="alert">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title"><?= e($rangeError) ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

    <form class="filters dashboard__range" aria-label="Custom date range" method="get" action="/dashboard">
        <input type="hidden" name="period" value="custom">
        <div class="filters__from">
            <label class="field__label" for="dash-from">From</label>
            <input class="input" id="dash-from" name="from" type="date" value="<?= e($fromValue) ?>">
        </div>
        <div class="filters__to">
            <label class="field__label" for="dash-to">To</label>
            <input class="input" id="dash-to" name="to" type="date" value="<?= e($toValue) ?>">
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn btn--primary">Apply range</button>
        </div>
    </form>

<?php else: ?>

    <div class="tiles">
        <div class="tile tile--income">
            <span class="tile__label">Income</span>
            <span class="tile__amount"><?= e($incomeLabel) ?></span>
            <span class="tile__period"><?= e($periodLabel) ?></span>
        </div>
        <div class="tile tile--spending">
            <span class="tile__label">Spending</span>
            <span class="tile__amount"><?= e($spendingLabel) ?></span>
            <span class="tile__period"><?= e($periodLabel) ?></span>
        </div>
        <div class="tile tile--net <?= e($netModifier) ?>">
            <span class="tile__label">Net</span>
            <span class="tile__amount"><?= e($netLabel) ?></span>
            <span class="tile__period"><?= e($periodLabel) ?></span>
        </div>
    </div>

<?php endif; ?>

<?php if ($emptyState === 'account'): ?>

    <div class="card">
        <div class="empty">
            <h3 class="empty__title">No transactions yet</h3>
            <p class="empty__body">Record your first entry to start tracking this period.</p>
            <a class="btn btn--primary" href="/transactions/new">Add transaction</a>
        </div>
    </div>

<?php elseif ($emptyState === 'period'): ?>

    <div class="card">
        <div class="empty">
            <h3 class="empty__title">No transactions <?= e($periodName) ?>.</h3>
            <p class="empty__body">Nothing was recorded between <?= e($rangePhrase) ?>.</p>
            <a class="btn btn--secondary" href="/transactions?period=<?= e($suggestion[0]) ?>"><?= e($suggestion[1]) ?></a>
        </div>
    </div>

<?php else: ?>

    <div class="card">
        <div class="card__header">
            <h2>Recent transactions</h2>
            <a href="/transactions">View all transactions →</a>
        </div>
        <div class="card__body">
<?php foreach ($recent as $row): ?>
<?php
    $isIncome  = (string) $row['type'] === 'income';
    $description = trim((string) ($row['description'] ?? ''));
    $category  = trim((string) ($row['category_name'] ?? ''));
?>
            <div class="tx">
                <span class="tx__date"><?= e((string) $row['occurred_on']) ?></span>
                <span class="tx__desc">
<?php if ($description !== ''): ?>
                    <span class="tx__desc-title"><?= e($description) ?></span><br>
<?php else: ?>
                    <span class="tx__desc-title"><span aria-hidden="true">—</span><span class="sr-only">No description</span></span><br>
<?php endif; ?>
                    <span class="tx__desc-meta"><span class="badge badge--neutral"><?= e($category !== '' ? $category : 'Uncategorised') ?></span></span>
                </span>
                <span class="tx__amount amount--<?= $isIncome ? 'income' : 'expense' ?>"><?= e(formatSignedMoney((string) $row['amount'], $isIncome ? '+' : '−')) ?></span>
            </div>
<?php endforeach; ?>
        </div>
    </div>

<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
