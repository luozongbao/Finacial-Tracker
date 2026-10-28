<?php
/**
 * reports.php — the reports.
 *
 * The contract (docs/issues/issue-019.md):
 *
 *   1. requireLogin() is the FIRST call. An unauthenticated visitor
 *      never reaches a query and never sees a row of somebody else's
 *      money.
 *   2. resolvePeriodRange() is the ONLY place a period becomes a
 *      range — resolveMonthRange() for the month= parameter, which
 *      selects a calendar month other than the current one.
 *   3. Every sum is computed in SQL. The period totals come from ONE
 *      statement, and the Total row of the category table is rendered
 *      from THOSE totals — never from the sum of the rows, which is how
 *      a table can be self-consistent and still disagree with the
 *      tiles above it.
 *   4. The bucket expression is selected from literal strings by the
 *      grain and by WEEK_START. Nothing from the query string reaches
 *      the SQL.
 *   5. The share column has ONE denominator, so the shares add up to
 *      100%.
 *   6. A bucket with no transactions is OMITTED. It is not padded with
 *      a row of zeros: an absence is not a fact.
 *   7. An error is never rendered as 0.00. The error state shows no
 *      tiles at all, so a failed query and a query that summed
 *      nothing can never look the same.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen. Everything below it — the queries, the
// tiles, the two tables — belongs to the signed-in account.
// ---------------------------------------------------------------------------

requireLogin();

// ---------------------------------------------------------------------------
// 2. The shell.
//
// The application shell: the app bar, the navigation and the bottom bar.
// These variables are read by includes/header.php.
// ---------------------------------------------------------------------------

$title   = 'Reports';
$active  = 'reports';
$pageCss = 'reports.css';

// ---------------------------------------------------------------------------
// 3. The period.
//
// The vocabulary is shared with the dashboard: normalizePeriod() owns
// it, so "today" and "day" are the same period and an unknown value
// falls back to the month.
// ---------------------------------------------------------------------------

$period     = normalizePeriod(query('period', 'month'));
$fromRaw    = query('from');
$toRaw      = query('to');
$monthParam = query('month');

// ---------------------------------------------------------------------------
// 4. The range.
//
// resolvePeriodRange() is the only thing that turns a period into a
// range. The one exception is the month= parameter, which selects a
// calendar month other than the current one — that is what the year
// view's month rows drill into.
//
// An invalid date is treated as ABSENT, so it falls back to the default
// the parameter table names: the current month's first and last day.
// The values that reach the form are the resolved ones, so the form
// always shows the range the report is about.
//
// A custom range whose start is after its end is an ERROR, not a swap:
// the form comes back with the message and the values that were typed,
// so the visitor can correct them instead of starting over.
// ---------------------------------------------------------------------------

$monthRange = resolvePeriodRange('month');

$from = isValidDateString($fromRaw) ? $fromRaw : $monthRange[0];
$to   = isValidDateString($toRaw) ? $toRaw : $monthRange[1];

$range      = null;   // [from, to] once the period has been resolved
$rangeError = '';     // the message for an unusable custom range
$monthValue = '';     // the calendar month the month= parameter selected

try {
    if ($period === 'month' && $monthParam !== '') {
        $monthRangeParam = resolveMonthRange($monthParam);

        if ($monthRangeParam !== null) {
            $range      = $monthRangeParam;
            $monthValue = $monthParam;
        } else {
            $range = resolvePeriodRange('month');
        }
    } else {
        $range = resolvePeriodRange($period, $from, $to);
    }
} catch (InvalidArgumentException $ex) {
    $rangeError = $ex->getMessage();
}

// ---------------------------------------------------------------------------
// 5. The grain.
//
// The "Over time" table breaks the period down one step below it. The
// grain follows from the period — it is never the visitor's choice —
// so the dashboard and the report always break the same period down
// the same way.
// ---------------------------------------------------------------------------

$grain = ['grain' => 'day', 'header' => 'Day'];

if ($range !== null) {
    $grain = reportGrain($period, $range[0], $range[1]);
}

// ---------------------------------------------------------------------------
// 6. The data.
//
// Three statements. Every one of them filters on the owning user id,
// and the id comes from the session.
//
// The period totals are ONE statement: the net is the difference of the
// two sides, computed by the database in DECIMAL. SIGN() comes back
// with it, so the sign of the net is decided by the value and never by
// a PHP comparison of money.
// ---------------------------------------------------------------------------

$totalsSql = <<<'SQL'
SELECT
    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0)        AS income,
    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0)        AS spending,
    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                      WHEN type = 'expense' THEN -amount END), 0)       AS net,
    SIGN(COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                            WHEN type = 'expense' THEN -amount END), 0)) AS net_sign,
    COUNT(*)                                                             AS tx_count
  FROM transactions
 WHERE user_id = ?
   AND occurred_on BETWEEN ? AND ?
SQL;

// The bucket expression is a LITERAL chosen by the grain and by
// WEEK_START. It is interpolated into the SQL, but it is never a
// request value — reportBucketExpression() returns one of four fixed
// strings.
$bucketExpression = reportBucketExpression($grain['grain']);

$bucketsSql = <<<SQL
SELECT {$bucketExpression} AS bucket_start,
       COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0)        AS income,
       COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0)        AS spending,
       COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                         WHEN type = 'expense' THEN -amount END), 0)       AS net,
       SIGN(COALESCE(SUM(CASE WHEN type = 'income'  THEN amount
                             WHEN type = 'expense' THEN -amount END), 0)) AS net_sign,
       COUNT(*)                                                             AS tx_count
  FROM transactions
 WHERE user_id = ?
   AND occurred_on BETWEEN ? AND ?
 GROUP BY bucket_start
 ORDER BY bucket_start
SQL;

$categoriesSql = <<<'SQL'
SELECT c.id AS category_id,
       c.name AS category_name,
       COUNT(t.id) AS tx_count,
       COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0)        AS income,
       COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0)        AS spending,
       COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                         WHEN t.type = 'expense' THEN -t.amount END), 0)       AS net,
       SIGN(COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount
                             WHEN t.type = 'expense' THEN -t.amount END), 0)) AS net_sign
  FROM transactions t
  LEFT JOIN categories c
         ON c.id = t.category_id
        AND c.user_id = t.user_id
 WHERE t.user_id = ?
   AND t.occurred_on BETWEEN ? AND ?
 GROUP BY c.id, c.name
 ORDER BY c.name IS NULL, c.name
SQL;

$uid        = (int) getCurrentUserId();
$totals     = null;
$buckets    = [];
$categories = [];
$failed     = false;

try {
    $db = Database::getInstance();

    if ($range !== null) {
        $totals = $db->fetchOne($totalsSql, [$uid, $range[0], $range[1]]);

        // The buckets come back only for the periods that HAVE
        // transactions — the GROUP BY produces no row for an empty
        // one, and nothing pads it afterwards.
        $buckets = $db->fetchAll($bucketsSql, [$uid, $range[0], $range[1]]);

        $categories = $db->fetchAll($categoriesSql, [$uid, $range[0], $range[1]]);
    }
} catch (Throwable $ex) {
    // The detail goes to the error log, where the operator can read it.
    // The page gets the error state: an error is never rendered as
    // 0.00, and neither table is rendered either — they come from the
    // same failure.
    error_log('[reports] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());

    $failed     = true;
    $totals     = null;
    $buckets    = [];
    $categories = [];
}

// ---------------------------------------------------------------------------
// 7. The display values.
//
// The sign is chosen here and applied by formatSignedMoney(); the
// template only concatenates. The net's sign comes from the SQL, so no
// PHP ever compares money.
//
// The zero of the net is NEUTRAL: a net of exactly zero is not a good
// month and not a bad one.
// ---------------------------------------------------------------------------

$incomeLabel   = '';
$spendingLabel = '';
$netLabel      = '';
$netModifier   = 'tile--net-zero';
$netAmountClass = 'amount amount--zero';
$txCount       = 0;
$periodLabel   = '';

if ($totals !== false && $totals !== null) {
    $netSign = (int) ($totals['net_sign'] ?? 0);

    $incomeLabel   = formatSignedMoney((string) $totals['income'], '+');
    $spendingLabel = formatSignedMoney((string) $totals['spending'], '−');
    $netLabel      = formatSignedMoney(
        (string) $totals['net'],
        $netSign > 0 ? '+' : ($netSign < 0 ? '−' : '')
    );

    $netModifier = $netSign > 0
        ? 'tile--net-positive'
        : ($netSign < 0 ? 'tile--net-negative' : 'tile--net-zero');

    $netAmountClass = $netSign > 0
        ? 'amount amount--income'
        : ($netSign < 0 ? 'amount amount--expense' : 'amount amount--zero');

    $txCount = (int) $totals['tx_count'];
}

if ($range !== null) {
    $periodLabel = formatPeriodLabel($range[0], $range[1], $period);
}

// The period is empty when the count is zero — the query ran and summed
// nothing. That is a fact about the data, and it is told apart from a
// FAILED query by the two never sharing a state.
$periodEmpty = !$failed && $range !== null && $txCount === 0;

// ---------------------------------------------------------------------------
// 8. The "Over time" rows.
//
// The label names the NATURAL bucket — the whole week — and the link
// shows the transactions that are actually in the report, so the link's
// range is clipped to the period.
// ---------------------------------------------------------------------------

$bucketRows = [];

foreach ($buckets as $bucket) {
    $start   = (string) $bucket['bucket_start'];
    $end     = reportBucketEnd($start, $grain['grain']);
    $clipped = clipDateRange($start, $end, $range[0], $range[1]);

    $rowSign = (int) ($bucket['net_sign'] ?? 0);

    if ($grain['grain'] === 'month') {
        // The year view drills into the month report.
        $rowLink  = '/reports?period=month&month=' . substr($start, 0, 7);
        $rowLabel = formatPeriodLabel($start, $end, 'month');
        $rowMeta  = '';
    } elseif ($grain['grain'] === 'week') {
        $rowLink  = '/transactions?from=' . $clipped[0] . '&to=' . $clipped[1];
        $rowLabel = 'Week of ' . formatLongDate($start);
        $rowMeta  = 'to ' . formatLongDate($end);
    } else {
        $rowLink  = '/transactions?from=' . $clipped[0] . '&to=' . $clipped[1];
        $rowLabel = formatLongDate($start);
        $rowMeta  = '';
    }

    $bucketRows[] = [
        'link'    => $rowLink,
        'label'   => $rowLabel,
        'meta'    => $rowMeta,
        'income'  => formatIncomeMoney((string) $bucket['income']),
        'spending' => formatSignedMoney((string) $bucket['spending'], '−'),
        'net'     => formatSignedMoney(
            (string) $bucket['net'],
            $rowSign > 0 ? '+' : ($rowSign < 0 ? '−' : '')
        ),
        'netClass' => $rowSign > 0
            ? 'amount amount--income'
            : ($rowSign < 0 ? 'amount amount--expense' : 'amount amount--zero'),
    ];
}

// ---------------------------------------------------------------------------
// 9. The "By category" rows.
//
// The rows come from the breakdown query. A category with no
// transactions in the period is not listed — the report is about the
// period, not about the category list.
//
// The Uncategorised row is ALWAYS present, so the visitor can see that
// nothing is missing from the report. The query produces it when there
// are uncategorised entries; the application adds it when there are
// none.
//
// The share has ONE denominator — the period's income plus its
// spending — so the column adds up to 100%.
// ---------------------------------------------------------------------------

$categoryRows = [];
$hasUncategorised = false;

// The volume of the period. It is the denominator of every share, and
// it is never zero when the table renders: the table only renders when
// the period has transactions, and a transaction has a volume.
$periodVolume = 0.0;

if ($totals !== false && $totals !== null) {
    $periodVolume = (float) $totals['income'] + (float) $totals['spending'];
}

foreach ($categories as $category) {
    $isUncategorised = $category['category_id'] === null;

    if ($isUncategorised) {
        $hasUncategorised = true;
    }

    $rowIncome   = (string) $category['income'];
    $rowSpending = (string) $category['spending'];
    $rowNet      = (string) $category['net'];
    $rowSign     = (int) ($category['net_sign'] ?? 0);

    $incomeZero   = isZeroAmount($rowIncome);
    $spendingZero = isZeroAmount($rowSpending);
    $netZero      = isZeroAmount($rowNet);

    $volume = (float) $rowIncome + (float) $rowSpending;
    $share  = $periodVolume > 0 ? ($volume / $periodVolume) * 100 : 0.0;

    $categoryRows[] = [
        'name'    => $isUncategorised ? 'Uncategorised' : (string) $category['category_name'],
        'link'    => $isUncategorised ? '/transactions?category=none'
                                      : '/transactions?category=' . (int) $category['category_id'],
        'count'   => (int) $category['tx_count'],
        // A zero money cell is an EM DASH, not "0.00": the row has no
        // income, and "0.00" reads like a recorded zero.
        'income'    => $incomeZero ? '—' : formatIncomeMoney($rowIncome),
        'incomeClass' => $incomeZero ? 'amount amount--muted' : 'amount amount--income',
        'spending'  => $spendingZero ? '—' : formatSignedMoney($rowSpending, '−'),
        'spendingClass' => $spendingZero ? 'amount amount--muted' : 'amount amount--expense',
        'net'     => $netZero ? '—' : formatSignedMoney(
            $rowNet,
            $rowSign > 0 ? '+' : ($rowSign < 0 ? '−' : '')
        ),
        'netClass' => $netZero ? 'amount amount--muted'
            : ($rowSign > 0 ? 'amount amount--income' : 'amount amount--expense'),
        'share'   => formatShare($share),
    ];
}

if (!$hasUncategorised) {
    $categoryRows[] = [
        'name'    => 'Uncategorised',
        'link'    => '/transactions?category=none',
        'count'   => 0,
        'income'    => '—',
        'incomeClass' => 'amount amount--muted',
        'spending'  => '—',
        'spendingClass' => 'amount amount--muted',
        'net'     => '—',
        'netClass' => 'amount amount--muted',
        'share'   => formatShare(0.0),
    ];
}

// ---------------------------------------------------------------------------
// 10. The captions and the status line.
//
// The period label is produced by ONE helper and used in three places:
// the status line, the tiles' period line and the table captions.
// ---------------------------------------------------------------------------

$grainWords = ['day' => 'day', 'week' => 'week', 'month' => 'month'];

if ($period === 'day') {
    $timeCaption = 'Income, spending and net for ' . $periodLabel;
} else {
    $timeCaption = 'Income, spending and net for each '
        . $grainWords[$grain['grain']] . ' of ' . $periodLabel;
}

$categoryCaption = 'Income, spending, net and share for each category in ' . $periodLabel;

$statusLabel = $periodLabel . ' · ' . $txCount . ' '
    . ($txCount === 1 ? 'transaction' : 'transactions');

// The exact range, as a phrase inside a sentence.
$rangePhrase = $range !== null ? formatPeriodSpan($range[0], $range[1], 'and') : '';

// ---------------------------------------------------------------------------
// 11. The "Try again" URL.
//
// The control reloads the same report, so it is rebuilt from the KNOWN
// parameters — never from the raw query string, which a visitor
// controls.
// ---------------------------------------------------------------------------

$reloadParams = ['period' => $period];

if ($period === 'custom') {
    $reloadParams['from'] = $from;
    $reloadParams['to']   = $to;
} elseif ($monthValue !== '') {
    $reloadParams['month'] = $monthValue;
}

$reloadUrl = '/reports?' . http_build_query($reloadParams);

// ---------------------------------------------------------------------------
// 12. The status.
//
// Three states, and they are told apart on the server: a broken query
// is a 500, an unusable range is a 400, and everything else is a 200.
// ---------------------------------------------------------------------------

http_response_code($failed ? 500 : ($rangeError !== '' ? 400 : 200));

// ---------------------------------------------------------------------------
// 13. The page.
//
// A view owns its page: the header and the footer are included here,
// and the controller never emits them.
// ---------------------------------------------------------------------------

require __DIR__ . '/../includes/header.php';
?>
<div class="page__header">
    <div>
        <h1 class="page__title">Reports</h1>
        <p class="page__subtitle">Summed over a period, then broken down by time and by category.</p>
    </div>
</div>

<div class="period" data-period-group="<?= e($period) ?>">
    <a class="period__link" href="/reports?period=day"    data-period="day"<?= $period === 'day' ? ' aria-current="true"' : '' ?>>Day</a>
    <a class="period__link" href="/reports?period=week"   data-period="week"<?= $period === 'week' ? ' aria-current="true"' : '' ?>>Week</a>
    <a class="period__link" href="/reports?period=month"  data-period="month"<?= $period === 'month' ? ' aria-current="true"' : '' ?>>Month</a>
    <a class="period__link" href="/reports?period=year"   data-period="year"<?= $period === 'year' ? ' aria-current="true"' : '' ?>>Year</a>
    <a class="period__link" href="/reports?period=custom" data-period="custom"<?= $period === 'custom' ? ' aria-current="true"' : '' ?>>Custom</a>
</div>

<?php if ($period === 'custom'): ?>

    <form class="filters rep__range" aria-label="Custom date range" method="get" action="/reports">
        <input type="hidden" name="period" value="custom">
        <div class="filters__from">
            <label class="field__label" for="rep-from">From</label>
            <input class="input" id="rep-from" name="from" type="date" value="<?= e($from) ?>"<?= $rangeError !== '' ? ' aria-invalid="true" aria-describedby="rep-range-msg"' : '' ?>>
        </div>
        <div class="filters__to">
            <label class="field__label" for="rep-to">To</label>
            <input class="input" id="rep-to" name="to" type="date" value="<?= e($to) ?>"<?= $rangeError !== '' ? ' aria-invalid="true" aria-describedby="rep-range-msg"' : '' ?>>
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn btn--primary">Apply range</button>
        </div>
    </form>

<?php endif; ?>

<?php if ($failed): ?>

    <div role="alert" class="rep__block">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">The report could not be loaded.</p>
                <p>Please try again.</p>
            </div>
        </div>
    </div>

    <div class="card rep__block">
        <div class="empty">
            <h3 class="empty__title">No report available</h3>
            <p class="empty__body">The totals could not be retrieved, so no figures are shown.</p>
            <a class="btn btn--secondary" href="<?= e($reloadUrl) ?>">Try again</a>
        </div>
    </div>

<?php elseif ($rangeError !== ''): ?>

    <div role="alert" class="rep__block">
        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title" id="rep-range-msg"><?= e($rangeError) ?></p>
            </div>
        </div>
    </div>

    <div class="card rep__block">
        <div class="empty">
            <h3 class="empty__title">No report</h3>
            <p class="empty__body">Correct the dates to see a report.</p>
        </div>
    </div>

<?php else: ?>

    <p class="pagination__status"><?= e($statusLabel) ?></p>

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

<?php if ($periodEmpty): ?>

    <div class="card rep__block">
        <div class="empty">
            <h3 class="empty__title">No transactions in this period.</h3>
            <p class="empty__body">Nothing was recorded between <?= e($rangePhrase) ?>.</p>
            <a class="btn btn--secondary" href="/reports?period=month">Look at this month</a>
        </div>
    </div>

<?php else: ?>

    <h2>Over time</h2>

    <div class="card card--flush rep__block">
        <div class="table-wrap">
            <table class="data-table">
                <caption class="sr-only"><?= e($timeCaption) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e($grain['header']) ?></th>
                        <th scope="col" class="num">Income</th>
                        <th scope="col" class="num">Spending</th>
                        <th scope="col" class="num">Net</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($bucketRows as $row): ?>
                    <tr>
                        <th scope="row">
                            <a href="<?= e($row['link']) ?>"><?= e($row['label']) ?></a><?php if ($row['meta'] !== ''): ?> <span class="tx__desc-meta"><?= e($row['meta']) ?></span><?php endif; ?>
                        </th>
                        <td data-label="Income" class="num amount amount--income"><?= e($row['income']) ?></td>
                        <td data-label="Spending" class="num amount amount--expense"><?= e($row['spending']) ?></td>
                        <td data-label="Net" class="num <?= e($row['netClass']) ?>"><?= e($row['net']) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <h2>By category</h2>

    <div class="card card--flush rep__block">
        <div class="table-wrap">
            <table class="data-table">
                <caption class="sr-only"><?= e($categoryCaption) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col" class="num">Transactions</th>
                        <th scope="col" class="num">Income</th>
                        <th scope="col" class="num">Spending</th>
                        <th scope="col" class="num">Net</th>
                        <th scope="col" class="num">Share</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($categoryRows as $row): ?>
                    <tr>
                        <th scope="row"><span class="badge badge--neutral"><a href="<?= e($row['link']) ?>"><?= e($row['name']) ?></a></span></th>
                        <td data-label="Transactions" class="num"><?= e((string) $row['count']) ?></td>
                        <td data-label="Income" class="num <?= e($row['incomeClass']) ?>"><?= e($row['income']) ?></td>
                        <td data-label="Spending" class="num <?= e($row['spendingClass']) ?>"><?= e($row['spending']) ?></td>
                        <td data-label="Net" class="num <?= e($row['netClass']) ?>"><?= e($row['net']) ?></td>
                        <td data-label="Share" class="num"><?= e($row['share']) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Total</th>
                        <td data-label="Transactions" class="num"><?= e((string) $txCount) ?></td>
                        <td data-label="Income" class="num amount amount--income"><?= e($incomeLabel) ?></td>
                        <td data-label="Spending" class="num amount amount--expense"><?= e($spendingLabel) ?></td>
                        <td data-label="Net" class="num <?= e($netAmountClass) ?>"><?= e($netLabel) ?></td>
                        <td data-label="Share" class="num">100.0%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php endif; ?>

<?php
require __DIR__ . '/../includes/footer.php';
