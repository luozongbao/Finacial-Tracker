<?php
/**
 * functions.php — the shared helpers every screen uses.
 *
 * The contract (docs/issues/issue-012.md, §5–§8):
 *
 *   1. e() is applied to EVERY dynamic value in HTML, and there is exactly
 *      one implementation of it. There is no "safe" subset of fields.
 *   2. formatMoney() takes a string. A DECIMAL(14,2) arrives from SQL as a
 *      string and stays one; the cast to float happens once, at the edge,
 *      for number_format() and nothing else. A total is summed in SQL, never
 *      in PHP — 0.1 + 0.2 !== 0.3.
 *   3. The CSRF token is validated BEFORE any input is read, and every form
 *      carries the hidden input.
 *   4. The week-start rule lives in exactly one place: the WEEK_START
 *      constant, read by weekStartDay().
 *   5. Ownership is enforced IN THE SQL, by userScope(). Never fetch first
 *      and check afterwards — that is a TOCTOU bug waiting for a second
 *      account.
 *   6. Every period becomes a date range in exactly one place:
 *      resolvePeriodRange().
 *   7. A sign is added to an amount AFTER it is formatted, by
 *      formatSignedMoney() — never inside number_format(), which renders a
 *      negative with an ASCII hyphen. The typographic minus (U+2212) is the
 *      glyph the design uses.
 *
 * Configuration comes from config.php and from nothing else — no getenv(),
 * no $_ENV, no $_SERVER-based configuration.
 */

declare(strict_types=1);

/**
 * A failure that carries an HTTP status code.
 *
 * The front controller (issue-014) catches this first and renders the
 * matching error view; any other Throwable is a 500. The $headers array
 * carries the extra response headers a status needs — the Allow: header of
 * a 405, for instance.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers Extra response headers.
     */
    public function __construct(
        public int $status,
        string $message = '',
        public array $headers = []
    ) {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

/**
 * Escape a value for HTML.
 *
 * ENT_QUOTES escapes the single quote as well as the double quote — the
 * default (ENT_COMPAT) leaves a single quote live inside a single-quoted
 * attribute. ENT_SUBSTITUTE turns an invalid UTF-8 byte into U+FFFD instead
 * of an empty string, which is the silent-truncation trap. The charset is
 * explicit: the default depends on default_charset, and it must not be an
 * implicit dependency.
 *
 * The parameter accepts the scalar types a view is likely to hand over —
 * an id from SQL is an int, a name is a string, a missing value is null —
 * so that e() never turns a missing field into a fatal type error.
 */
function e(string|int|float|bool|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format an amount for display.
 *
 * The parameter is a STRING on purpose. The value comes from a
 * DECIMAL(14,2) column, and ATTR_STRINGIFY_FETCHES = false keeps it a
 * string all the way here. The cast to float happens once, inside this
 * function, for number_format() only — no arithmetic is done on it here.
 *
 * The separators are explicit. Do not rely on the locale.
 */
function formatMoney(string $amount): string
{
    return number_format((float) $amount, 2, '.', ',');
}

/**
 * Format an amount with a sign — the way the money tiles show it.
 *
 * The sign is applied AFTER the formatting, never by number_format():
 * number_format() renders a negative amount with an ASCII hyphen, and the
 * design uses the typographic minus (U+2212).
 *
 * The magnitude is taken by stripping the sign as a STRING, so a signed
 * value that arrives from SQL ("-158.20") is shown as its absolute value
 * with the glyph the caller chose. Nothing here compares money.
 *
 * A zero carries NO sign. "+0.00" is noise, and the check is made on the
 * FORMATTED value — the only place a zero has one stable shape.
 *
 * @param string $amount The decimal string, e.g. "158.20" or "-158.20".
 * @param string $sign   The glyph to prepend: "+", the minus sign, or "".
 */
function formatSignedMoney(string $amount, string $sign): string
{
    $magnitude = formatMoney(ltrim(trim($amount), '+-'));

    if ($magnitude === '0.00' || $sign === '') {
        return $magnitude;
    }

    return $sign . $magnitude;
}

/**
 * Is this decimal string a zero?
 *
 * A pattern and not a float comparison: the value comes from a
 * DECIMAL(14,2) column and the money path must not turn it into a float
 * to decide anything.
 *
 * "0", "0.00" and "-0.00" are zeros. An empty string is NOT a zero — a
 * missing value is a broken query, not a sum of nothing.
 */
function isZeroAmount(string $value): bool
{
    return preg_match('/^[+-]?0+(\.0+)?$/', trim($value)) === 1;
}

/**
 * Format a count for display, or an em dash when there is nothing.
 *
 * The em dash is U+2014. It is written as an escape so that no editor or
 * diff tool can silently turn it into a hyphen.
 *
 * A COUNT(*) from SQL arrives as a string (ATTR_STRINGIFY_FETCHES = false),
 * so both types are accepted and cast here — once — instead of at twenty
 * call sites.
 */
function formatCount(int|string $count): string
{
    $n = (int) $count;

    return $n === 0 ? "\u{2014}" : (string) $n;
}

/**
 * The URL of an asset, with a cache-busting stamp.
 *
 * The stamp is the file's mtime, so the browser refetches the file when it
 * changes. It is not a build step and there is no manifest to maintain.
 *
 * The path must be a hard-coded literal at the call site. A user-supplied
 * value must never reach this function.
 *
 * The path is relative to the assets directory — asset_url('css/base.css')
 * is /assets/css/base.css — so the file the stamp is taken from is looked
 * up under assets/ as well. The two must agree: when they did not, the
 * stamp silently became 0 and no asset was ever cache-busted.
 */
function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = __DIR__ . '/../assets/' . $path;
    $version = is_file($file) ? substr((string) filemtime($file), -6) : '0';

    return '/assets/' . $path . '?v=' . $version;
}

// ---------------------------------------------------------------------------
// Absolute URLs
// ---------------------------------------------------------------------------

/**
 * An absolute URL on this installation.
 *
 * The host comes from `$_SERVER['SERVER_NAME']` and NEVER from
 * `$_SERVER['HTTP_HOST']`. The latter is the `Host:` header, which the
 * client controls: a password-reset link built from it can be pointed at an
 * attacker's host, and the victim would carry their own token to it.
 * SERVER_NAME comes from the server configuration.
 *
 * The path is always a literal at the call site — never a user-supplied
 * value.
 */
function absoluteUrl(string $path): string
{
    $scheme = requestIsHttps() ? 'https' : 'http';

    $host = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));

    if ($host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

// ---------------------------------------------------------------------------
// Session and CSRF
// ---------------------------------------------------------------------------

/**
 * Is this request HTTPS?
 *
 * The answer decides two things: the scheme of a link in a mail, and the
 * Secure flag on the session cookie. It lives in ONE place so the two can
 * never disagree.
 */
function requestIsHttps(): bool
{
    if (isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/**
 * Start the session.
 *
 * This is the FIRST call in every request. It opens ob_start() because
 * output_buffering is 0 in this stack: any byte emitted before it breaks
 * header() and silently drops the security headers.
 *
 * The cookie parameters are set BEFORE the session starts, because PHP
 * only applies them to the cookie it issues at that moment:
 *
 *   - httponly  -- a script injected into the page cannot read the session
 *                  id out of document.cookie. Without it, any XSS is an
 *                  instant account takeover.
 *   - samesite  -- Lax, so the browser does not attach the session cookie
 *                  to a cross-site POST. The CSRF token is the real
 *                  defence; this is the second lock on the same door.
 *   - secure    -- ONLY over HTTPS. A plain-HTTP installation (a box on
                  the LAN, a reverse proxy in front) would otherwise
 *                  never get its session cookie back and every request
 *                  would land on the sign-in screen.
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => requestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        ob_start();
    }
}

/**
 * The CSRF token of this session, created on first use.
 *
 * random_bytes(32) is cryptographically random — uniqid() and mt_rand() are
 * not. The token is stored in the session: a per-session token is enough for
 * a personal application.
 */
function getCsrfToken(): string
{
    startSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

/**
 * Replace the CSRF token with a fresh one.
 *
 * Called whenever the privilege level changes: sign-in, sign-out and a
 * password change. The old token stops working immediately.
 */
function rotateCsrfToken(): string
{
    startSession();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return (string) $_SESSION['csrf_token'];
}

/**
 * Is this the session's CSRF token?
 *
 * hash_equals() is a constant-time comparison: a plain === on a secret is a
 * timing leak.
 */
function validateCsrfToken(?string $token): bool
{
    startSession();

    if (empty($_SESSION['csrf_token']) || !is_string($token) || $token === '') {
        return false;
    }

    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

// ---------------------------------------------------------------------------
// The flash messages
// ---------------------------------------------------------------------------

/**
 * Put a value in the session for the NEXT request.
 *
 * A POST that ends with a redirect needs a way to carry a message across
 * the redirect: the query string is visible in the address bar and the
 * Referer header, and a message in a URL is a message a stranger can
 * write. The session is the only channel that survives the redirect.
 *
 * The value is read exactly once — takeFlash() removes it — so a refresh
 * does not repeat the message.
 */
function setFlash(string $key, mixed $value): void
{
    startSession();

    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][$key] = $value;
}

/**
 * Read a flash message — and forget it.
 *
 * The value is removed on the way out, so it is shown once and once only.
 */
function takeFlash(string $key, mixed $default = null): mixed
{
    startSession();

    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash']) || !array_key_exists($key, $_SESSION['flash'])) {
        return $default;
    }

    $value = $_SESSION['flash'][$key];

    unset($_SESSION['flash'][$key]);

    return $value;
}

// ---------------------------------------------------------------------------
// Dates and periods
// ---------------------------------------------------------------------------

/**
 * Now, in UTC.
 *
 * The database stores UTC. A local-timezone conversion here would shift a
 * transaction across the day boundary and put it in the wrong period.
 */
function utcNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

/**
 * The weekday a week starts on: 1 = Monday ... 7 = Sunday, 0 = Sunday.
 *
 * The rule lives in the WEEK_START config constant and in this one
 * function. Nothing else may hard-code a week start — the dashboard, the
 * transaction filters and the reports all have to agree about what "this
 * week" means.
 */
function weekStartDay(): int
{
    if (!defined('WEEK_START')) {
        return 1;
    }

    $day = (int) WEEK_START;

    return ($day >= 0 && $day <= 7) ? $day : 1;
}

/**
 * Normalise a period name.
 *
 * The dashboard uses `today` and the reports use `day` for the same period,
 * so both spellings are accepted and normalised in one place. An unknown
 * period becomes `month`, which is what the dashboard mockup opens on.
 */
function normalizePeriod(?string $period): string
{
    $period = strtolower(trim((string) $period));

    if ($period === 'today') {
        $period = 'day';
    }

    return in_array($period, ['day', 'week', 'month', 'year', 'custom'], true)
        ? $period
        : 'month';
}

/**
 * Resolve a period into an inclusive [from, to] range of UTC dates.
 *
 * This is the ONLY place a period becomes a date range. The range is
 * inclusive on both ends, because the SQL is `occurred_on BETWEEN ? AND ?`.
 *
 * A custom range whose start is after its end is an error, not a swap: the
 * mockup has a dedicated message for it. An impossible date is rejected
 * too.
 *
 * @return array{0: string, 1: string} The two dates, YYYY-MM-DD.
 *
 * @throws InvalidArgumentException When a custom range is not usable.
 */
function resolvePeriodRange(string $period, ?string $from = null, ?string $to = null): array
{
    $period = normalizePeriod($period);
    $now = utcNow();

    switch ($period) {
        case 'day':
            $day = $now->format('Y-m-d');

            return [$day, $day];

        case 'week':
            // The number of days since the start of the week. The weekday
            // number is ISO: 1 = Monday ... 7 = Sunday.
            $weekday = (int) $now->format('N');
            $start = weekStartDay();
            $start = $start === 0 ? 7 : $start;      // Sunday as 7
            $offset = ($weekday - $start + 7) % 7;
            $first = $now->modify('-' . $offset . ' days');
            $last = $first->modify('+6 days');

            return [$first->format('Y-m-d'), $last->format('Y-m-d')];

        case 'year':
            return [$now->format('Y') . '-01-01', $now->format('Y') . '-12-31'];

        case 'custom':
            $start = trim((string) $from);
            $end = trim((string) $to);

            if ($start === '' || $end === '') {
                throw new InvalidArgumentException('Enter a start date and an end date.');
            }

            if (!isValidDateString($start) || !isValidDateString($end)) {
                throw new InvalidArgumentException('Enter a valid date.');
            }

            // The dates are YYYY-MM-DD, so the string comparison is the
            // chronological one.
            if ($start > $end) {
                throw new InvalidArgumentException('The start date must be on or before the end date.');
            }

            return [$start, $end];

        case 'month':
        default:
            return [$now->format('Y-m-01'), $now->format('Y-m-t')];
    }
}

/**
 * Resolve a YYYY-MM string into an inclusive [from, to] date range.
 *
 * The reports screen needs one month other than the current one — the
 * year view's month rows drill into it. It is a helper of its own and
 * not a parameter of resolvePeriodRange(), because "the current month"
 * and "a named month" are two different things and must not be
 * confused.
 *
 * A string that is not a real calendar month — 2026-13, 2026-00,
 * 2026-8 — is not a range at all, and null says so.
 *
 * @return array{0: string, 1: string}|null The two dates, YYYY-MM-DD, or null.
 */
function resolveMonthRange(string $ym): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return null;
    }

    $year  = (int) $m[1];
    $month = (int) $m[2];

    if ($month < 1 || $month > 12) {
        return null;
    }

    $from = sprintf('%04d-%02d-01', $year, $month);

    // gmdate() and not date(): the timestamp is midnight UTC, and a
    // server that is not itself on UTC would render the previous day —
    // and with it the last day of the PREVIOUS month.
    $to = gmdate('Y-m-t', strtotime($from . ' UTC'));

    return [$from, $to];
}

/**
 * Is this a real calendar date in YYYY-MM-DD?
 *
 * checkdate() and not DateTime::createFromFormat(): the latter returns a
 * DateTime WITH WARNINGS for an impossible date such as 2026-02-31, so
 * `if (!$date)` never fires and the impossible date sails through.
 */
function isValidDateString(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return false;
    }

    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * Format a UTC date for display: "19 October 2026".
 *
 * gmdate() and not date(): the timestamp is midnight UTC, so date() would
 * render the previous or the next day for any server that is not itself on
 * UTC.
 */
function formatDate(?string $isoDate): string
{
    if ($isoDate === null || $isoDate === '') {
        return '';
    }

    $ts = strtotime($isoDate . ' UTC');

    return $ts === false ? '' : gmdate('j M Y', $ts);
}

/**
 * The human label of a resolved period range.
 *
 * The SHAPE comes from the period and the VALUES come from the range:
 *
 *   day     "24 October 2026"
 *   week    "19 – 25 October 2026"
 *   month   "October 2026"
 *   year    "2026"
 *   custom  "1 – 31 October 2026"
 *
 * The week and the custom labels are always SPANS. A custom range that
 * straddles two months must not read "October" — it is not a calendar
 * month, and the label has to say what was actually summed.
 *
 * @param string $from   The first day of the range, YYYY-MM-DD.
 * @param string $to     The last day of the range, YYYY-MM-DD.
 * @param string $period The normalised period: day, week, month, year or custom.
 */
function formatPeriodLabel(string $from, string $to, string $period): string
{
    $period = normalizePeriod($period);

    $fromTs = strtotime($from . ' UTC');

    if ($fromTs === false) {
        return '';
    }

    if ($period === 'day') {
        return gmdate('j F Y', $fromTs);
    }

    if ($period === 'month') {
        return gmdate('F Y', $fromTs);
    }

    if ($period === 'year') {
        return gmdate('Y', $fromTs);
    }

    return formatPeriodSpan($from, $to);
}

/**
 * A date range as one phrase: "19 – 25 October 2026".
 *
 * The dash between the two ends is an EN DASH (U+2013) with a space on
 * each side. A hyphen is a different glyph, and in a money column it
 * reads as a separator.
 *
 * The joiner is a parameter because the same range is also needed inside
 * a sentence — "Nothing was recorded between 19 and 25 October 2026." —
 * and a dash does not belong there.
 *
 * @param string $from   The first day of the range, YYYY-MM-DD.
 * @param string $to     The last day of the range, YYYY-MM-DD.
 * @param string $joiner What sits between the two ends.
 */
function formatPeriodSpan(string $from, string $to, string $joiner = "\u{2013}"): string
{
    $fromTs = strtotime($from . ' UTC');
    $toTs   = strtotime($to . ' UTC');

    if ($fromTs === false || $toTs === false) {
        return '';
    }

    $sameYear  = gmdate('Y', $fromTs) === gmdate('Y', $toTs);
    $sameMonth = $sameYear && gmdate('m', $fromTs) === gmdate('m', $toTs);

    // 19 – 25 October 2026
    if ($sameMonth) {
        return gmdate('j', $fromTs) . ' ' . $joiner . ' ' . gmdate('j', $toTs) . ' ' . gmdate('F Y', $toTs);
    }

    // 28 October – 3 November 2026
    if ($sameYear) {
        return gmdate('j F', $fromTs) . ' ' . $joiner . ' ' . gmdate('j F', $toTs) . ' ' . gmdate('Y', $toTs);
    }

    // 20 December 2026 – 3 January 2027
    return gmdate('j F Y', $fromTs) . ' ' . $joiner . ' ' . gmdate('j F Y', $toTs);
}

/**
 * The label of a range that may be unbounded on either side.
 *
 * The list screen's range comes from the query string and the visitor can
 * clear the dates, so one end of the range can be missing. The bounded
 * case is the shared formatPeriodSpan(); a half-open range says which end
 * is missing, and the fully unbounded one reads "all time".
 */
function transactionRangeLabel(string $from, string $to): string
{
    $fromOk = isValidDateString($from);
    $toOk   = isValidDateString($to);

    if (!$fromOk && !$toOk) {
        return 'all time';
    }

    if (!$fromOk) {
        return 'until ' . formatLongDate($to);
    }

    if (!$toOk) {
        return 'from ' . formatLongDate($from);
    }

    return formatPeriodSpan($from, $to);
}

// ---------------------------------------------------------------------------
// The report
// ---------------------------------------------------------------------------

/**
 * The grain of the report's "Over time" table: one step below the
 * period.
 *
 * The rules are fixed (docs/issues/issue-019.md §7):
 *
 *   day     one row per day
 *   week    one row per day
 *   month   one row per week
 *   year    one row per month
 *   custom  by the length of the range — daily for 62 days and fewer,
 *           weekly for 366 and fewer, monthly beyond that
 *
 * The grain is never a visitor's choice. It follows from the period,
 * so the dashboard and the report always break the same period down
 * the same way.
 *
 * @return array{grain: string, header: string} The bucket size — day,
 *                                                    week or month —
 *                                                    and the first
 *                                                    column's header.
 */
function reportGrain(string $period, string $from, string $to): array
{
    switch (normalizePeriod($period)) {
        case 'day':
            return ['grain' => 'day', 'header' => 'Day'];

        case 'week':
            return ['grain' => 'day', 'header' => 'Day'];

        case 'month':
            return ['grain' => 'week', 'header' => 'Week of'];

        case 'year':
            return ['grain' => 'month', 'header' => 'Month'];
    }

    // The custom range: the span decides the grain. The thresholds are
    // inclusive — a 62-day range is daily, a 63-day range is weekly.
    $days = reportSpanDays($from, $to);

    if ($days <= 62) {
        return ['grain' => 'day', 'header' => 'Day'];
    }

    if ($days <= 366) {
        return ['grain' => 'week', 'header' => 'Week of'];
    }

    return ['grain' => 'month', 'header' => 'Month'];
}

/**
 * The number of days in an inclusive date range.
 *
 * The count is inclusive on both ends: a range that starts and ends on
 * the same day is ONE day, not zero.
 */
function reportSpanDays(string $from, string $to): int
{
    $fromTs = strtotime($from . ' UTC');
    $toTs   = strtotime($to . ' UTC');

    if ($fromTs === false || $toTs === false) {
        return 0;
    }

    return (int) floor(($toTs - $fromTs) / 86400) + 1;
}

/**
 * The SQL expression that buckets a transaction by time.
 *
 * The expression is selected from LITERAL strings by the grain and by
 * the WEEK_START constant. Nothing from the query string ever reaches
 * the SQL — the bucket is the one place where an interpolated fragment
 * would be a hole in the wall.
 *
 * The week bucket starts on WEEK_START, the same constant the
 * dashboard uses, so the two screens agree about what "this week"
 * means. A week that starts on Monday is not the same week as one that
 * starts on Sunday, and a hard-coded Monday would quietly disagree
 * with the dashboard.
 */
function reportBucketExpression(string $grain): string
{
    if ($grain === 'day') {
        return 'occurred_on';
    }

    if ($grain === 'month') {
        return 'DATE_SUB(occurred_on, INTERVAL (DAYOFMONTH(occurred_on) - 1) DAY)';
    }

    $start = weekStartDay();

    if ($start === 0 || $start === 7) {
        // A week that starts on Sunday.
        return 'DATE_SUB(occurred_on, INTERVAL (DAYOFWEEK(occurred_on) - 1) DAY)';
    }

    if ($start === 1) {
        // A week that starts on Monday.
        return 'DATE_SUB(occurred_on, INTERVAL WEEKDAY(occurred_on) DAY)';
    }

    // A week that starts on Tuesday, Wednesday, Thursday, Friday or
    // Saturday: the offset is the number of days since the week start.
    // The day number is the config constant, never a request value.
    return 'DATE_SUB(occurred_on, INTERVAL ((DAYOFWEEK(occurred_on) - ' . $start . ' + 7) MOD 7) DAY)';
}

/**
 * The last day of the bucket a start date opens.
 *
 * A day bucket is one day, a week bucket is seven days and a month
 * bucket is the calendar month.
 */
function reportBucketEnd(string $start, string $grain): string
{
    $ts = strtotime($start . ' UTC');

    if ($ts === false) {
        return $start;
    }

    if ($grain === 'day') {
        return gmdate('Y-m-d', $ts);
    }

    if ($grain === 'week') {
        return gmdate('Y-m-d', $ts + 6 * 86400);
    }

    return gmdate('Y-m-t', $ts);
}

/**
 * Clip a bucket's range to the period it belongs to.
 *
 * The LABEL names the natural bucket — the whole week — but the LINK
 * must show the transactions that are actually in the report, so the
 * range is clipped to the period on both ends.
 *
 * @return array{0: string, 1: string} The clipped range, YYYY-MM-DD.
 */
function clipDateRange(string $from, string $to, string $periodFrom, string $periodTo): array
{
    return [
        $from >= $periodFrom ? $from : $periodFrom,
        $to <= $periodTo ? $to : $periodTo,
    ];
}

/**
 * A share of the period's volume, as a percentage.
 *
 * The share is the ONE float in the money path: it is a ratio, not an
 * amount, and a ratio rounded to one decimal is not money. The
 * denominator is the period's income plus its spending — ONE
 * denominator for the whole column, so the shares add up to 100%.
 */
function formatShare(float $share): string
{
    return number_format($share, 1, '.', '') . '%';
}

/**
 * Format an income cell of a report table.
 *
 * The income column keeps its "+" on a zero — "+0.00" — because the
 * column says "income" and the sign is part of the reading. The
 * spending column does not: a zero there is "0.00", and a minus sign
 * in front of nothing is a lie.
 */
function formatIncomeMoney(string $amount): string
{
    return '+' . formatMoney(ltrim(trim($amount), '+-'));
}

// ---------------------------------------------------------------------------
// Request input
// ---------------------------------------------------------------------------

/**
 * A trimmed POST value.
 *
 * trim() happens on the way in; the length, the format and the range are
 * validated by the caller, field by field.
 */
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/**
 * A POST value that is NOT trimmed.
 *
 * A password is the one field where a leading or a trailing space is part
 * of the secret: trimming it would quietly change the password the
 * visitor chose. The value is still refused unless it is a string, so a
 * posted array can never reach a comparison.
 */
function postRaw(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

/**
 * A trimmed GET value.
 */
function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/**
 * The ownership filter of a user-scoped query.
 *
 * Ownership is enforced IN THE SQL. Every statement that touches
 * transactions or categories carries the owning user id in its WHERE
 * clause — reads, sums, updates and deletes alike.
 *
 * The alias is a hard-coded literal at the call site (it comes from the
 * query, not from the request). Without it the fragment is `user_id = ?`.
 *
 * @return array{0: string, 1: array} The WHERE fragment and its parameters.
 */
function userScope(int $userId, string $alias = ''): array
{
    $column = $alias === '' ? 'user_id' : $alias . '.user_id';

    return [$column . ' = ?', [$userId]];
}

/**
 * Join the WHERE fragments of a query.
 *
 * @param string[] $fragments The fragments, each with its own placeholders.
 */
function composeWhere(array $fragments): string
{
    return implode(' AND ', $fragments);
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * A valid username: 3 to 32 characters of letters, digits, underscore and
 * hyphen.
 */
function isValidUsername(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{3,32}$/', $username);
}

/**
 * A valid email address, at most 254 characters.
 */
function isValidEmail(string $email): bool
{
    if ($email === '' || strlen($email) > 254) {
        return false;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * A valid amount: at most twelve digits and at most two decimals, with no
 * sign and no thousands separator.
 *
 * This is deliberately a pattern and not is_numeric(): is_numeric('1e5') is
 * true, is_numeric(' 12 ') is true and is_numeric('1,234.50') is false. The
 * pattern accepts exactly what the form's step="0.01" can produce, and it
 * accepts no exponent — which is what keeps the value safe to hand to a
 * DECIMAL(14,2).
 */
function isValidAmount(string $amount): bool
{
    return (bool) preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount);
}

/**
 * A valid transaction type: income or expense. There is no third value.
 */
function isValidType(string $type): bool
{
    return in_array($type, ['income', 'expense'], true);
}

/**
 * A valid category name: 1 to 60 characters.
 */
function isValidCategoryName(string $name): bool
{
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

    return $length >= 1 && $length <= 60;
}

// ---------------------------------------------------------------------------
// Transactions
// ---------------------------------------------------------------------------

/**
 * The number of rows on one page of the transaction list.
 *
 * The page size lives here and nowhere else: the query's LIMIT, the
 * OFFSET arithmetic and the status line all read it, so a change cannot
 * make the page and the status line disagree.
 */
if (!defined('TRANSACTIONS_PER_PAGE')) {
    define('TRANSACTIONS_PER_PAGE', 25);
}

/**
 * Escape the wildcards of a LIKE pattern.
 *
 * addcslashes() and not addslashes(): addslashes() escapes the quote, the
 * double quote, the backslash and the NUL byte — but NOT the percent sign
 * and NOT the underscore, which are the two wildcards a LIKE pattern has.
 *
 * The backslash is the character the ESCAPE clause names, so it has to be
 * escaped as well.
 */
function escapeLike(string $value): string
{
    return addcslashes($value, '%_\\');
}

/**
 * A date in the long form: "1 October 2026".
 *
 * formatDate() renders the SHORT month ("1 Oct 2026") — that is the table
 * cell. The caption, the delete confirmation and the period label need the
 * long month, and the month name is spelled in exactly one place per
 * shape.
 */
function formatLongDate(?string $isoDate): string
{
    if ($isoDate === null || $isoDate === '') {
        return '';
    }

    $ts = strtotime($isoDate . ' UTC');

    return $ts === false ? '' : gmdate('j F Y', $ts);
}

/**
 * The sort of the transaction list — the column and the direction.
 *
 * Both come from a WHITELIST. The query string never reaches the SQL: the
 * column is picked from a fixed table and the direction is one of two
 * words, so there is nothing to inject and nothing to quote.
 *
 * The ORDER BY always ends with the id, so the order is total: two rows
 * of the same date do not swap places between two pages, and the visitor
 * never sees a row twice.
 *
 * @return array{key: string, dir: string, order: string}
 */
function normalizeTransactionSort(?string $sort, ?string $dir): array
{
    // The whitelist: the sort key, the column it stands for and the
    // direction it defaults to.
    $columns = [
        'date'   => ['t.occurred_on', 'desc'],
        'amount' => ['t.amount', 'asc'],
    ];

    $key = is_string($sort) && isset($columns[$sort]) ? $sort : 'date';

    [$column, $defaultDir] = $columns[$key];

    $direction = is_string($dir) && ($dir === 'asc' || $dir === 'desc') ? $dir : $defaultDir;

    return [
        'key'   => $key,
        'dir'   => $direction,
        'order' => $column . ' ' . strtoupper($direction) . ', t.id ' . strtoupper($direction),
    ];
}

/**
 * A category filter value: a positive category id.
 *
 * The filter bar also carries the literal "none" for the uncategorised
 * entries, and that one is not an id.
 */
function isCategoryFilterValue(string $value): bool
{
    return $value !== '' && ctype_digit($value) && (int) $value > 0;
}

/**
 * The filters of the transaction list, parsed from the query string.
 *
 * An unknown value is IGNORED, never an error: a hand-edited URL must not
 * produce a 500, and a value that is not in the whitelist is simply not
 * applied. The value that is applied is the value that is echoed back
 * into the filter bar, so the visitor always sees the state they are in.
 *
 * The default range is the current month — the same range the dashboard
 * opens on, and the same one resolvePeriodRange() produces.
 *
 * @param array<string, mixed>|null $source The query string. Defaults to $_GET.
 *
 * @return array{q: string, type: string, category: string, from: string, to: string}
 */
function parseTransactionFilters(?array $source = null): array
{
    $source = $source ?? $_GET;

    $q = trim((string) ($source['q'] ?? ''));

    if (mb_strlen($q) > 255) {
        $q = mb_substr($q, 0, 255);
    }

    $type = trim((string) ($source['type'] ?? ''));

    if (!isValidType($type)) {
        $type = '';
    }

    $category = trim((string) ($source['category'] ?? ''));

    if ($category !== 'none' && !isCategoryFilterValue($category)) {
        $category = '';
    }

    $month = resolvePeriodRange('month');

    // An ABSENT date falls back to the default month. A date that is
    // present but EMPTY is the visitor clearing the field, and that is an
    // unbounded range — "all time" — not a fallback to the month.
    $from = array_key_exists('from', $source) ? trim((string) $source['from']) : $month[0];
    $to   = array_key_exists('to', $source) ? trim((string) $source['to']) : $month[1];

    if ($from !== '' && !isValidDateString($from)) {
        $from = $month[0];
    }

    if ($to !== '' && !isValidDateString($to)) {
        $to = $month[1];
    }

    return [
        'q'        => $q,
        'type'     => $type,
        'category' => $category,
        'from'     => $from,
        'to'       => $to,
    ];
}

/**
 * The WHERE clause of the transaction list — built ONCE.
 *
 * The row query, the totals query and the count query all read the result
 * of this one call. Writing the predicate twice is how a total starts
 * drifting from the list it belongs to, so it is built here and passed
 * around as a pair.
 *
 * The placeholders are POSITIONAL. The spec sketches this with named
 * placeholders (:uid, :q, ...), but the PDO wrapper runs with
 * EMULATE_PREPARES = false, and under native prepares a named placeholder
 * cannot be reused and cannot be bound positionally. The two are
 * reconciled here: the values are bound in the order the fragments are
 * appended, which is the order the placeholders appear in the string.
 *
 * @param int                  $userId  The signed-in user. The ownership
 *                                       filter is part of the predicate,
 *                                       never a check done afterwards.
 * @param array<string, mixed> $filters The parsed filters.
 *
 * @return array{where: string, params: array<int, mixed>}
 */
function buildTransactionPredicate(int $userId, array $filters): array
{
    $where  = ['t.user_id = ?'];
    $params = [$userId];

    $q = trim((string) ($filters['q'] ?? ''));

    if ($q !== '') {
        // The wildcards in the visitor's text are escaped, and the ESCAPE
        // clause tells the server which character does the escaping — so
        // a search for "%" finds a percent sign and not the whole table.
        $where[]  = "t.description LIKE ? ESCAPE '\\\\'";
        $params[] = '%' . escapeLike($q) . '%';
    }

    $type = trim((string) ($filters['type'] ?? ''));

    if (isValidType($type)) {
        $where[]  = 't.type = ?';
        $params[] = $type;
    }

    $category = trim((string) ($filters['category'] ?? ''));

    if ($category === 'none') {
        $where[] = 't.category_id IS NULL';
    } elseif (isCategoryFilterValue($category)) {
        $where[]  = 't.category_id = ?';
        $params[] = (int) $category;
    }

    $from = trim((string) ($filters['from'] ?? ''));
    $to   = trim((string) ($filters['to'] ?? ''));

    if (isValidDateString($from)) {
        $where[]  = 't.occurred_on >= ?';
        $params[] = $from;
    }

    if (isValidDateString($to)) {
        $where[]  = 't.occurred_on <= ?';
        $params[] = $to;
    }

    return ['where' => implode(' AND ', $where), 'params' => $params];
}

/**
 * The URL of the transaction list, with the given state.
 *
 * The filters travel with the sort and the page, so a sort link never
 * loses the search and a page link never loses the order. An empty value
 * is left out, and the page is left out when it is the first one.
 */
function transactionListUrl(
    array $filters,
    ?string $sort = null,
    ?string $dir = null,
    int $page = 1
): string
{
    $params = [];

    foreach (['q', 'type', 'category', 'from', 'to'] as $key) {
        $value = trim((string) ($filters[$key] ?? ''));

        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    if ($sort !== null && $sort !== '') {
        $params['sort'] = $sort;
    }

    if ($dir !== null && $dir !== '') {
        $params['dir'] = $dir;
    }

    if ($page > 1) {
        $params['page'] = $page;
    }

    return '/transactions' . ($params === [] ? '' : '?' . http_build_query($params));
}

/**
 * The canonical form of an amount: "42.8" becomes "42.80".
 *
 * A DECIMAL(14,2) is written with two decimals, and the value that is
 * bound to the INSERT is the value that is stored. This is a STRING
 * operation throughout — an amount is never a float.
 */
function normalizeAmountString(string $amount): string
{
    $amount = trim($amount);

    if ($amount === '') {
        return '';
    }

    if (!str_contains($amount, '.')) {
        return $amount . '.00';
    }

    [$whole, $decimals] = explode('.', $amount, 2);

    return $whole . '.' . str_pad(substr($decimals, 0, 2), 2, '0');
}

/**
 * Is this amount greater than zero?
 *
 * A pattern and not a float comparison: the value comes from a string, and
 * the money path must not turn it into a float to decide anything. "0",
 * "0.00" and "0.000" are not positive; "0.01" is.
 */
function isPositiveAmount(string $amount): bool
{
    $normalized = normalizeAmountString($amount);

    if ($normalized === '') {
        return false;
    }

    return preg_match('/^0*(\.0*)?$/', $normalized) !== 1;
}

/**
 * Validate a submitted transaction.
 *
 * EVERY error is collected, in the order the fields appear in the form —
 * type, amount, date, category, description — so the visitor sees the
 * whole list at once and fixes everything in one pass. A form that reports
 * one error per round trip is a worse form.
 *
 * The values are returned with the errors, unchanged, so a rejected form
 * comes back with what the visitor typed.
 *
 * @param array<string, mixed> $input       The submitted fields.
 * @param array<int, string>   $categoryIds The ids of the visitor's
 *                                          categories, as strings.
 *
 * @return array{errors: array<string, string>, values: array<string, string>}
 */
function validateTransactionInput(array $input, array $categoryIds): array
{
    $errors = [];
    $values = [];

    // --- type ---
    $type = trim((string) ($input['type'] ?? ''));

    if (!isValidType($type)) {
        $errors['type'] = 'Choose either income or spending.';
        // The radio group falls back to its default, so a rejected form
        // never comes back with nothing selected.
        $type = 'income';
    }

    $values['type'] = $type;

    // --- amount ---
    $amount = trim((string) ($input['amount'] ?? ''));

    if (!isValidAmount($amount) || !isPositiveAmount($amount)) {
        $errors['amount'] = 'The amount must be greater than zero.';
    } else {
        $amount = normalizeAmountString($amount);
    }

    $values['amount'] = $amount;

    // --- date ---
    $date = trim((string) ($input['date'] ?? ''));

    if (!isValidDateString($date)) {
        $errors['date'] = 'Enter a valid date.';
    }

    $values['date'] = $date;

    // --- category ---
    $category = trim((string) ($input['category'] ?? ''));

    if ($category !== '' && !in_array($category, $categoryIds, true)) {
        $errors['category'] = 'That category is not available.';
    }

    $values['category'] = $category;

    // --- description ---
    $description = trim((string) ($input['description'] ?? ''));

    // The length is counted in CHARACTERS, not bytes, and a long
    // description is REJECTED — never silently truncated.
    if (mb_strlen($description) > 255) {
        $errors['description'] = 'The description must be at most 255 characters long.';
    }

    $values['description'] = $description;

    return ['errors' => $errors, 'values' => $values];
}

// ---------------------------------------------------------------------------
// The categories, the profile and the installation
// ---------------------------------------------------------------------------

/**
 * Is this exception the database's duplicate-key error?
 *
 * Two things have to match: the SQLSTATE 23000 (an integrity constraint
 * violation) and the driver code 1062 (ER_DUP_ENTRY). The message text is
 * never matched — it is localised and it changes between versions.
 */
function isDuplicateKeyError(Throwable $ex): bool
{
    if (!($ex instanceof PDOException)) {
        return false;
    }

    if ((string) $ex->getCode() !== '23000') {
        return false;
    }

    $driverCode = isset($ex->errorInfo[1]) ? (int) $ex->errorInfo[1] : 0;

    return $driverCode === 1062;
}

/**
 * The validation of the category form.
 *
 * The UNIQUENESS of the name is not checked here: the database owns it
 * (uq_categories_user_name) and the duplicate-key error is mapped to the
 * friendly message by the caller. A SELECT first would race — two
 * simultaneous creates would both pass the SELECT, and the index would
 * still refuse one of them.
 *
 * @return array{errors: array<string, string>, values: array{name: string, type: string}}
 */
function validateCategoryInput(string $name, string $type): array
{
    $errors = [];

    $name = trim($name);

    if ($name === '') {
        $errors['name'] = 'Enter a name for the category.';
    } elseif (!isValidCategoryName($name)) {
        $errors['name'] = 'The name may be at most 60 characters long.';
    }

    if (!isValidType($type)) {
        $errors['type'] = 'Choose either income or expense.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'name' => $name,
            // The type is passed on as it was posted, valid or not: the
            // radio group has to show what the visitor picked.
            'type' => $type,
        ],
    ];
}

/**
 * The label of a category type on the categories screen.
 *
 * The stored value is 'income' or 'expense'. This screen says "Expense"
 * where the transaction screens say "Spending" — the inconsistency is
 * recorded for the design owner in docs/issues/issue-020.md.
 */
function categoryTypeLabel(string $type): string
{
    return $type === 'income' ? 'Income' : 'Expense';
}

/**
 * The total of a category, as the table shows it.
 *
 * A category without transactions shows an em dash: a total of 0.00 would
 * look like a category that really spent nothing, and the difference
 * matters.
 *
 * The sign follows the TRANSACTIONS, not the declared type. A category
 * declared as an expense can hold an income entry, and the table has to
 * tell the truth about the money, not about the label.
 *
 * The sign is read from the string, never from a float: the value comes
 * from a DECIMAL(14,2) column and the money path must not turn it into a
 * float to decide anything.
 *
 * @return array{label: string, modifier: string}
 */
function formatCategoryTotal(string $total, int $count): array
{
    if ($count === 0) {
        return ['label' => '—', 'modifier' => 'muted'];
    }

    $trimmed = ltrim(trim($total), '+');
    $negative = str_starts_with($trimmed, '-');
    $zero = isZeroAmount($trimmed);

    $sign = $zero ? '' : ($negative ? '−' : '+');

    return [
        'label' => formatSignedMoney($trimmed, $sign),
        'modifier' => $zero ? 'zero' : ($negative ? 'expense' : 'income'),
    ];
}

/**
 * The sentence the delete confirmation carries.
 *
 * It is the most important sentence on the screen: it says that the
 * transactions SURVIVE. One transaction takes the singular, more take the
 * plural, and the count is the same number the table shows.
 */
function uncategorisedNotice(int $count): string
{
    if ($count === 1) {
        return '1 transaction will become uncategorised. It will not be deleted.';
    }

    return $count . ' transactions will become uncategorised. They will not be deleted.';
}

/**
 * The validation of the profile form.
 *
 * The UNIQUENESS is not checked here: the caller checks it, because the
 * check has to leave the signed-in account's own row out.
 *
 * @return array{errors: array<string, string>, values: array{username: string, email: string}}
 */
function validateProfileInput(string $username, string $email): array
{
    $errors = [];

    $username = trim($username);
    $email = trim($email);

    if (!isValidUsername($username)) {
        $errors['username'] = usernameValidationMessage($username);
    }

    if (!isValidEmail($email)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'username' => $username,
            // The address is stored lowercased, so the unique index sees
            // one spelling of one address.
            'email' => strtolower($email),
        ],
    ];
}

/**
 * The uniqueness of a profile, leaving the signed-in account's own row out.
 *
 * The account's own row has to be left out: saving the profile without
 * changing anything must not answer "already taken" with the visitor's
 * own name.
 *
 * The comparison is case-insensitive, because the column's collation is
 * — `Demo` and `demo` are one name in the database and one name here.
 *
 * @return array<string, string> The field errors. Empty when the input is free.
 */
function profileUniquenessErrors(string $username, string $email, int $userId): array
{
    $errors = [];

    $db = Database::getInstance();

    if ($username !== '') {
        $row = $db->fetchOne(
            'SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1',
            [$username, $userId]
        );

        if ($row !== false) {
            $errors['username'] = 'That username is already taken.';
        }
    }

    if ($email !== '') {
        $row = $db->fetchOne(
            'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
            [$email, $userId]
        );

        if ($row !== false) {
            $errors['email'] = 'That email address is already registered.';
        }
    }

    return $errors;
}

/**
 * The message for an invalid username.
 *
 * The copy is the registration screen's — one product, one voice — and it
 * lives in one place so the two screens cannot drift apart.
 */
function usernameValidationMessage(string $username): string
{
    $length = function_exists('mb_strlen') ? mb_strlen($username) : strlen($username);

    if ($length < 3) {
        return 'The username must be at least 3 characters long.';
    }

    if ($length > 32) {
        return 'The username may be at most 32 characters long.';
    }

    return 'The username may only contain letters, digits, underscore and hyphen.';
}

/**
 * The validation of the change-password form.
 *
 * The password is NEVER trimmed: a leading or a trailing space is part of
 * the password the visitor chose, and trimming it would quietly sign them
 * out of the account they are trying to change.
 *
 * The two new passwords are compared with hash_equals(): a plain === on a
 * secret is a timing leak.
 *
 * @return array<string, string> The field errors. Empty when the input is good.
 */
function validatePasswordChangeInput(string $password, string $confirmation): array
{
    $errors = [];

    $min = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 10;
    $max = defined('PASSWORD_MAX_LENGTH') ? PASSWORD_MAX_LENGTH : 200;

    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

    if ($length < $min) {
        $errors['password'] = 'The password must be at least ' . $min . ' characters long.';
    } elseif ($length > $max) {
        $errors['password'] = 'The password may be at most ' . $max . ' characters long.';
    } elseif (!hash_equals($password, $confirmation)) {
        $errors['password_confirmation'] = 'The two passwords do not match.';
    }

    return $errors;
}

/**
 * The name of the weekday a week starts on.
 *
 * WEEK_START is a weekday number, and the configuration and the helper
 * document two spellings of Sunday — 0 and 7 — so both are accepted and
 * normalised to 0 here. A number outside the range is shown as it is: a
 * broken configuration is better visible than quietly hidden.
 */
function weekStartName(int $day): string
{
    if ($day === 7) {
        $day = 0;
    }

    if ($day < 0 || $day > 6) {
        return (string) $day;
    }

    $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    return $names[$day];
}

/**
 * The timezone the application renders timestamps in.
 *
 * The TIMEZONE constant is the only source: the server's own timezone is
 * not the application's business.
 */
function appTimezone(): DateTimeZone
{
    $name = defined('TIMEZONE') ? (string) TIMEZONE : 'UTC';

    try {
        return new DateTimeZone($name);
    } catch (Throwable) {
        return new DateTimeZone('UTC');
    }
}

/**
 * A timestamp in the long form: "12 March 2026".
 *
 * The column is stored in UTC; the reading is rendered in the app's
 * timezone, so the date a visitor sees is the date of THEIR day.
 */
function formatAccountDate(?string $datetime): string
{
    if ($datetime === null || trim($datetime) === '') {
        return '';
    }

    try {
        $moment = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return '';
    }

    return $moment->setTimezone(appTimezone())->format('j F Y');
}

/**
 * The path of the current request, normalised.
 *
 * One leading slash, no trailing slash, no doubled slashes — the same
 * shape the router matches. A view that serves more than one route reads
 * the path to tell them apart.
 *
 * The normalisation is written out here and not borrowed from the router:
 * the installer loads this file on its own, without the router.
 */
function requestPath(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';

    $path = (string) preg_replace('#/{2,}#', '/', '/' . ltrim($path, '/'));

    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    return $path === '' ? '/' : $path;
}

// ---------------------------------------------------------------------------
// Redirecting
// ---------------------------------------------------------------------------

/**
 * Send a redirect and stop.
 *
 * The path is always a hard-coded literal or a path built from validated
 * parts — never a raw value from the request.
 *
 * Every successful POST ends here: the Post/Redirect/Get pattern is what
 * makes the browser's refresh button safe.
 */
function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

/**
 * A request URI that is safe to redirect to after a sign-in.
 *
 * Only a path is accepted: it starts with a single slash, it is not a
 * protocol-relative URL, and it carries no control character. Anything else
 * falls back to the dashboard, so the target can never be turned into an
 * open redirect.
 */
function safeRedirectTarget(?string $uri): string
{
    $uri = trim((string) $uri);

    if ($uri === '') {
        return '/dashboard';
    }

    // A control character in a header is a response-splitting attempt.
    if (preg_match('/[\x00-\x1F\x7F]/', $uri)) {
        return '/dashboard';
    }

    // It has to be a path: one slash, and not the two of a protocol-relative
    // URL ("//evil.example.com").
    if ($uri[0] !== '/' || str_starts_with($uri, '//')) {
        return '/dashboard';
    }

    return $uri;
}

// ---------------------------------------------------------------------------
// Refusing
// ---------------------------------------------------------------------------

/**
 * Refuse a request that has run out of quota.
 *
 * The status is 429 and the header is Retry-After: the number of seconds
 * the caller has to wait before trying again (issue-022 §7.2).
 *
 * Both must happen BEFORE the first byte of the body — PHP drops a header
 * that is sent after the output has started, and a 429 without the header
 * is a 429 the client cannot schedule against.
 *
 * The BODY is the view's business, and it must be the same body for every
 * address: the refusal must not tell a caller more than "you are early".
 */
function refuseRateLimited(int $retryAfterSeconds): void
{
    http_response_code(429);

    header('Retry-After: ' . max(1, $retryAfterSeconds));
}
