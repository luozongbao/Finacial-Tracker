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
 */
function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = __DIR__ . '/../' . $path;
    $version = is_file($file) ? substr((string) filemtime($file), -6) : '0';

    return '/assets/' . $path . '?v=' . $version;
}

// ---------------------------------------------------------------------------
// Session and CSRF
// ---------------------------------------------------------------------------

/**
 * Start the session.
 *
 * This is the FIRST call in every request. It opens ob_start() because
 * output_buffering is 0 in this stack: any byte emitted before it breaks
 * header() and silently drops the security headers.
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
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
