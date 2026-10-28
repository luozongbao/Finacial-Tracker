<?php
/**
 * runtime_test.php — guards the core runtime layer (issue-012).
 *
 * The runtime is the layer every screen stands on: the PDO wrapper, the
 * output helpers, the session and the CSRF token, the ownership filter and
 * the router. A silent change in any of them is expensive to find later —
 * a money value that becomes a float, a CSRF check that runs after the
 * payload is read, a router that matches the wrong method — so the
 * behaviour is pinned here.
 *
 * What this test protects:
 *
 *   1. The four files exist, load cleanly and declare the contract of
 *      issue-012: the functions, the classes and the hard settings
 *      (ERRMODE_EXCEPTION, EMULATE_PREPARES = false, ENT_QUOTES,
 *      hash_equals(), random_bytes(), the UTC session time zone).
 *   2. The pure helpers behave exactly as specified — the escaping, the
 *      money formatting, the amount and date validation, the period
 *      resolution, the ownership filter, the redirect target.
 *   3. The CSRF token is 64 hex characters, is compared in constant time
 *      and is invalidated when it is rotated.
 *   4. The router matches on the method AND the path: an unknown path is a
 *      404, a wrong method is a 405 with an Allow: header, and the handler
 *      path never comes from the request.
 *   5. The database wrapper behaves: a failed query throws, execute()
 *      returns a bool and not a row count, a DECIMAL comes back as a
 *      string, and userScope() really does isolate one account from
 *      another.
 *   6. The registration gate and the sign-in guard behave — in a child
 *      process, because both of them end the request.
 *
 * Two tiers:
 *
 *   - The file tier (§A–§D, §F) always runs. It needs no database.
 *   - The database tier (§E) needs a reachable MariaDB. When the database
 *     is not reachable it is SKIPPED cleanly and the test still exits 0 —
 *     a skip is a notice, never a silent green.
 *
 * Credentials, in order of preference:
 *   1. config.php — the installed application's own constants.
 *   2. FT_DB_HOST / FT_DB_NAME / FT_DB_USER / FT_DB_PASS — test-scoped
 *      environment overrides.
 *   3. The dev stack's .env file, against the compose service host.
 *      Reading .env here is a TEST-HARNESS concern only: the application
 *      never parses .env (config.php is the only configuration source).
 *
 * Run:  docker compose exec -T app php database/runtime_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by
 * the output — and never treat an unreachable database as a pass.
 */

declare(strict_types=1);

$failures = [];
$skips    = [];

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $failures;

    if ($condition) {
        echo "  [PASS] $label\n";
        return;
    }

    $failures[] = $label . ($detail !== '' ? " — $detail" : '');
    echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function skip(string $label, string $why): void
{
    global $skips;

    $skips[] = $label . ($why !== '' ? " — $why" : '');
    echo "  [SKIP] $label" . ($why !== '' ? " — $why" : '') . "\n";
}

/**
 * Run a PHP snippet in a child process.
 *
 * The child gets its own constant table, which is what lets this test
 * exercise the registration gate with ALLOW_REGISTRATION = false without
 * touching config.php, and the sign-in guard — which ends the request —
 * without ending the test.
 *
 * The streams are kept apart on purpose: the child prints on stdout, and
 * a warning on stderr would otherwise be mistaken for output.
 *
 * @param string[] $args Passed to the child as $argv.
 *
 * @return array{stdout: string, stderr: string, code: int}
 */
function run_child(string $code, array $args = []): array
{
    $file    = sys_get_temp_dir() . '/runtime_child_' . bin2hex(random_bytes(6)) . '.php';
    $errFile = sys_get_temp_dir() . '/runtime_child_err_' . bin2hex(random_bytes(6)) . '.txt';
    file_put_contents($file, $code);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }

    $out    = [];
    $status = 0;
    exec($cmd . ' 2>' . escapeshellarg($errFile), $out, $status);

    $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

    if (is_file($file)) {
        @unlink($file);
    }
    if (is_file($errFile)) {
        @unlink($errFile);
    }

    return ['stdout' => implode("\n", $out), 'stderr' => $stderr, 'code' => $status];
}

/**
 * Parse a .env-style file. TEST-ONLY — see the header note. The
 * application never reads .env.
 */
function read_env_file(string $file): array
{
    $values = [];

    if (!is_file($file)) {
        return $values;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $values[trim(substr($line, 0, $pos))] = trim(trim(substr($line, $pos + 1)), "\"'");
    }

    return $values;
}

/**
 * Every connection worth trying, most specific first.
 *
 * @return array<int, array{label: string, host: string, name: string, user: string, pass: string}>
 */
function db_candidates(string $root): array
{
    $candidates = [];

    // 1. Test-scoped environment overrides.
    $envHost = getenv('FT_DB_HOST');
    if (is_string($envHost) && $envHost !== '') {
        $candidates[] = [
            'label' => 'FT_DB_* environment',
            'host'  => $envHost,
            'name'  => (string) (getenv('FT_DB_NAME') ?: 'financial_tracker'),
            'user'  => (string) (getenv('FT_DB_USER') ?: 'root'),
            'pass'  => (string) (getenv('FT_DB_PASS') ?: ''),
        ];
    }

    // 2. The dev stack's .env, against the compose service name and the
    //    loopback addresses. Inside the app container the database is the
    //    "db" service; on the host it is 127.0.0.1.
    $env = read_env_file($root . '/.env');
    if (isset($env['DB_NAME'])) {
        foreach (['db', '127.0.0.1', 'localhost'] as $host) {
            $candidates[] = [
                'label' => '.env (' . $host . ')',
                'host'  => $host,
                'name'  => $env['DB_NAME'],
                'user'  => $env['DB_USER'] ?? 'root',
                'pass'  => $env['DB_PASSWORD'] ?? '',
            ];
        }
    }

    // Deduplicate — the same database may be reachable by several routes.
    $unique = [];
    $seen   = [];
    foreach ($candidates as $candidate) {
        $key = $candidate['host'] . '|' . $candidate['name'] . '|' . $candidate['user'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[]   = $candidate;
    }

    return $unique;
}

/**
 * The source of a file with its comments removed.
 *
 * The structural checks below look for a pattern in the CODE. A comment
 * that mentions getenv() — or that explains why a value is never
 * interpolated into SQL — must not satisfy a check, and must not break
 * one either. The tokenizer knows the difference between a comment and a
 * string; a regular expression does not.
 */
function strip_php_comments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
            continue;
        }

        $out .= $token;
    }

    return $out;
}

/**
 * The quoted strings in a source file that look like SQL.
 *
 * Used to prove that no value is interpolated into a statement: a SQL
 * string that carries a $ is a value that was pasted in.
 *
 * @return string[]
 */
function sql_strings_with_variables(string $source): array
{
    $found = [];

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/s", $source, $matches);

    foreach ($matches[1] as $string) {
        if (str_contains($string, '$') && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|SET)\b/i', $string)) {
            $found[] = $string;
        }
    }

    return $found;
}

// ---------------------------------------------------------------------------
// The bootstrap — the constants, then the four files under test.
// ---------------------------------------------------------------------------

$root = dirname(__DIR__);

// The installed application's own configuration, when there is one. It is
// the only configuration source in the application.
if (is_file($root . '/config.php')) {
    require_once $root . '/config.php';
}

// The credentials for the database tier. When config.php defines them,
// those are the only ones the application can use, so they are the only
// ones the test tries.
if (defined('DB_HOST') && defined('DB_NAME')) {
    $candidates = [[
        'label' => 'config.php',
        'host'  => (string) DB_HOST,
        'name'  => (string) DB_NAME,
        'user'  => (string) DB_USER,
        'pass'  => (string) DB_PASS,
    ]];
} else {
    $candidates = db_candidates($root);
}

// The first candidate that answers wins. The probe uses its own handle
// and drops it, so the tier below opens the connection the application
// would open.
$chosen     = null;
$tried      = 0;
$lastError  = '';

foreach ($candidates as $candidate) {
    $tried++;

    try {
        $probe = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $candidate['host'], $candidate['name']),
            $candidate['user'],
            $candidate['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $probe = null;
        $chosen = $candidate;
        break;
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }
}

// Define whatever config.php did not define. The fallback is the first
// candidate, so the constants always exist — a missing constant is a
// fatal Error in PHP 8, and the runtime must not die before the skip
// notice is printed.
$fallback = $candidates[0] ?? [
    'label' => 'default',
    'host'  => 'localhost',
    'name'  => 'financial_tracker',
    'user'  => 'root',
    'pass'  => '',
];
$use = $chosen ?? $fallback;

if (!defined('DB_HOST')) {
    define('DB_HOST', $use['host']);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $use['name']);
}
if (!defined('DB_USER')) {
    define('DB_USER', $use['user']);
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $use['pass']);
}
if (!defined('ALLOW_REGISTRATION')) {
    define('ALLOW_REGISTRATION', true);
}
if (!defined('WEEK_START')) {
    define('WEEK_START', 1);
}
if (!defined('TIMEZONE')) {
    define('TIMEZONE', 'UTC');
}

if (defined('TIMEZONE')) {
    date_default_timezone_set((string) TIMEZONE);
}

// The four files under test. The autoloader (includes/bootstrap.php) is
// not needed here: the runtime layer has no namespaced class and no
// third-party dependency.
require_once $root . '/includes/Database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/Router.php';

// The session is the first call in every request; the CSRF token lives
// in it, so it has to be open before §C.
startSession();


// ---------------------------------------------------------------------------
// A. The files, the contract and the hard settings.
// ---------------------------------------------------------------------------

echo "## A. The runtime files\n";

$files = [
    'includes/Database.php',
    'includes/functions.php',
    'includes/auth.php',
    'includes/Router.php',
];

foreach ($files as $relative) {
    ok($relative . ' exists', is_file($root . '/' . $relative), $root . '/' . $relative);
}

$missing = [];
foreach (['e', 'formatMoney', 'formatCount', 'asset_url', 'startSession', 'getCsrfToken',
          'rotateCsrfToken', 'validateCsrfToken', 'utcNow', 'weekStartDay', 'normalizePeriod',
          'resolvePeriodRange', 'isValidDateString', 'formatDate', 'post', 'query', 'userScope',
          'composeWhere', 'isValidUsername', 'isValidEmail', 'isValidAmount', 'isValidType',
          'isValidCategoryName', 'redirect', 'safeRedirectTarget'] as $function) {
    if (!function_exists($function)) {
        $missing[] = $function . '()';
    }
}
ok('every helper of functions.php exists', $missing === [], implode(', ', $missing));

$missing = [];
foreach (['isLoggedIn', 'getCurrentUserId', 'getCurrentUser', 'loginUser', 'logoutUser',
          'requireLogin', 'registrationAllowed', 'hashPassword', 'verifyPassword'] as $function) {
    if (!function_exists($function)) {
        $missing[] = $function . '()';
    }
}
ok('every helper of auth.php exists', $missing === [], implode(', ', $missing));

ok('the Database class exists', class_exists('Database', false));
ok('the Router class exists', class_exists('Router', false));
ok('the HttpException class exists', class_exists('HttpException', false));

$source = [];
$code   = [];
foreach ($files as $relative) {
    $source[$relative] = (string) file_get_contents($root . '/' . $relative);
    $code[$relative]   = strip_php_comments($source[$relative]);
}

$dbSource     = $code['includes/Database.php'];
$fnSource     = $code['includes/functions.php'];
$authSource   = $code['includes/auth.php'];
$routerSource = $code['includes/Router.php'];
$allSource    = implode("\n", $code);

// The hard PDO settings.
ok('the PDO handle sets ERRMODE_EXCEPTION', str_contains($dbSource, 'PDO::ERRMODE_EXCEPTION'));
ok('the PDO handle sets FETCH_ASSOC', str_contains($dbSource, 'PDO::FETCH_ASSOC'));
ok('the PDO handle turns EMULATE_PREPARES off', (bool) preg_match('/ATTR_EMULATE_PREPARES\s*=>\s*false/', $dbSource));
ok('the PDO handle turns STRINGIFY_FETCHES off', (bool) preg_match('/ATTR_STRINGIFY_FETCHES\s*=>\s*false/', $dbSource));
ok('the session time zone is UTC', str_contains($dbSource, "SET time_zone = '+00:00'"));

// The hard output and session settings.
ok('e() uses ENT_QUOTES and ENT_SUBSTITUTE', (bool) preg_match('/ENT_QUOTES\s*\|\s*ENT_SUBSTITUTE/', $fnSource));
ok('e() names the charset explicitly', str_contains($fnSource, "'UTF-8'"));
ok('validateCsrfToken() compares in constant time', str_contains($fnSource, 'hash_equals'));
ok('getCsrfToken() uses random_bytes(32)', str_contains($fnSource, 'random_bytes(32)'));
ok('startSession() opens an output buffer', str_contains($fnSource, 'ob_start()'));
ok('formatMoney() uses explicit separators', (bool) preg_match('/number_format\([^;]*2,\s*\'\.\',\s*\'\,\'/', $fnSource));

// The session cookie flags. A session id that JavaScript can read is one
// injected script away from an account takeover, so the cookie is HttpOnly
// and SameSite=Lax. The Secure flag follows the scheme of the request --
// a plain-HTTP installation must still get its cookie back.
ok('the session cookie is HttpOnly', str_contains($fnSource, "'httponly' => true"));
ok('the session cookie is SameSite=Lax', str_contains($fnSource, "'samesite' => 'Lax'"));
ok('the Secure flag follows the scheme of the request', str_contains($fnSource, "'secure'   => requestIsHttps()"));
ok('the cookie parameters are set before the session starts',
    strpos($fnSource, 'session_set_cookie_params') !== false
    && strpos($fnSource, 'session_start()') !== false
    && strpos($fnSource, 'session_set_cookie_params') < strpos($fnSource, 'session_start()'));
ok('requestIsHttps() decides the scheme in ONE place',
    substr_count($fnSource, 'requestIsHttps()') >= 3,
    'found ' . substr_count($fnSource, 'requestIsHttps()'));

// The hard session settings.
ok('loginUser() regenerates the session id', str_contains($authSource, 'session_regenerate_id(true)'));
ok('the password is hashed with password_hash()', str_contains($authSource, 'password_hash'));
ok('the password hash uses PASSWORD_DEFAULT', str_contains($authSource, 'PASSWORD_DEFAULT'));
ok('the password is verified with password_verify()', str_contains($authSource, 'password_verify'));
ok('the registration gate compares against a real boolean', str_contains($authSource, 'ALLOW_REGISTRATION === true'));

// Configuration comes from config.php and from nothing else.
ok('the runtime never reads the environment', !preg_match('/\bgetenv\s*\(/', $allSource));
ok('the runtime never reads $_ENV', !str_contains($allSource, '$_ENV'));
ok('the runtime never builds a URL from $_SERVER[', !str_contains($allSource, "\$_SERVER['HTTP_HOST']"));

// No value is interpolated into SQL.
$violations = sql_strings_with_variables($allSource);
ok('no value is interpolated into SQL', $violations === [], implode(' | ', $violations));

// No money arithmetic in PHP — a total is summed in SQL.
$arithmetic = [];
foreach ($code as $relative => $text) {
    if (preg_match('/\$[a-z_]*amount\s*[+\-*\/]|[+\-*\/]\s*\$[a-z_]*amount/i', $text)) {
        $arithmetic[] = $relative;
    }
}
ok('no money is added up in PHP', $arithmetic === [], implode(', ', $arithmetic));

// formatMoney() takes a string — a DECIMAL stays a string until the
// single cast inside the helper.
$moneyType = (string) (new ReflectionFunction('formatMoney'))->getParameters()[0]->getType();
ok('formatMoney() takes a string amount', $moneyType === 'string', $moneyType);


// ---------------------------------------------------------------------------
// B. The pure helpers.
// ---------------------------------------------------------------------------

echo "\n## B. The pure helpers\n";

ok('e() escapes the angle brackets', e('<b>x</b>') === '&lt;b&gt;x&lt;/b&gt;', e('<b>x</b>'));
ok('e() escapes both kinds of quote', e('"a" \'b\'') === '&quot;a&quot; &#039;b&#039;', e('"a" \'b\''));
ok('e() escapes the ampersand', e('a & b') === 'a &amp; b', e('a & b'));
ok('e() renders null as an empty string', e(null) === '');
ok('e() renders a number', e(42) === '42');
ok('e() replaces an invalid byte instead of dropping it', str_contains(e("a\xFFb"), "\u{FFFD}"));

ok('formatMoney() formats 1,234.56', formatMoney('1234.56') === '1,234.56', formatMoney('1234.56'));
ok('formatMoney() keeps two decimals', formatMoney('5') === '5.00', formatMoney('5'));
ok('formatMoney() keeps the negative sign', formatMoney('-1234.50') === '-1,234.50', formatMoney('-1234.50'));
ok('formatMoney() rounds to two decimals', formatMoney('1234.567') === '1,234.57', formatMoney('1234.567'));
ok('formatMoney() formats zero', formatMoney('0') === '0.00', formatMoney('0'));

ok('formatCount() renders zero as an em dash', formatCount(0) === "\u{2014}", bin2hex(formatCount(0)));
ok('formatCount() renders a count', formatCount(7) === '7');
ok('formatCount() accepts the string a COUNT(*) returns', formatCount('7') === '7');

ok('formatDate() formats a UTC date', formatDate('2026-10-19') === '19 Oct 2026', formatDate('2026-10-19'));
ok('formatDate() renders an empty string for null', formatDate(null) === '');
ok('formatDate() renders an empty string for an empty value', formatDate('') === '');

// The date must not slide across the day boundary on a server that is not
// on UTC — the storage is UTC, the display is UTC.
$defaultZone = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati');
$shifted = formatDate('2026-10-19');
date_default_timezone_set($defaultZone);
ok('formatDate() is not shifted by the server time zone', $shifted === '19 Oct 2026', $shifted);

ok('isValidAmount() accepts 1234.56', isValidAmount('1234.56'));
ok('isValidAmount() accepts a whole amount', isValidAmount('42'));
ok('isValidAmount() accepts one decimal', isValidAmount('42.5'));
ok('isValidAmount() rejects 1e5', !isValidAmount('1e5'));
ok('isValidAmount() rejects 1,234.50', !isValidAmount('1,234.50'));
ok('isValidAmount() rejects -5.00', !isValidAmount('-5.00'));
ok('isValidAmount() rejects a padded value', !isValidAmount(' 12 '));
ok('isValidAmount() rejects three decimals', !isValidAmount('1.234'));
ok('isValidAmount() rejects an empty string', !isValidAmount(''));

ok('isValidDateString() accepts 2026-10-19', isValidDateString('2026-10-19'));
ok('isValidDateString() rejects 2026-02-31', !isValidDateString('2026-02-31'));
ok('isValidDateString() rejects 2026-13-01', !isValidDateString('2026-13-01'));
ok('isValidDateString() rejects 2026-04-31', !isValidDateString('2026-04-31'));
ok('isValidDateString() rejects a loose format', !isValidDateString('19/10/2026'));
ok('isValidDateString() rejects an empty string', !isValidDateString(''));

ok('normalizePeriod() maps today to day', normalizePeriod('today') === 'day');
ok('normalizePeriod() keeps day', normalizePeriod('day') === 'day');
ok('normalizePeriod() keeps week, month, year and custom', normalizePeriod('week') === 'week'
    && normalizePeriod('month') === 'month'
    && normalizePeriod('year') === 'year'
    && normalizePeriod('custom') === 'custom');
ok('normalizePeriod() defaults to month', normalizePeriod('nonsense') === 'month');
ok('normalizePeriod() defaults to month for null', normalizePeriod(null) === 'month');
ok('normalizePeriod() is case insensitive', normalizePeriod('WEEK') === 'week');

// resolvePeriodRange() is the only place a period becomes a date range.
$now = utcNow();

[$from, $to] = resolvePeriodRange('day');
ok('resolvePeriodRange("day") is today', $from === $now->format('Y-m-d') && $to === $from, "$from .. $to");

[$from, $to] = resolvePeriodRange('today');
ok('resolvePeriodRange("today") is the same range as "day"', $from === $now->format('Y-m-d') && $to === $from);

[$from, $to] = resolvePeriodRange('month');
ok('resolvePeriodRange("month") is the first to the last day of the month',
    $from === $now->format('Y-m-01') && $to === $now->format('Y-m-t'), "$from .. $to");

[$from, $to] = resolvePeriodRange('year');
ok('resolvePeriodRange("year") is the whole year',
    $from === $now->format('Y') . '-01-01' && $to === $now->format('Y') . '-12-31', "$from .. $to");

[$from, $to] = resolvePeriodRange('week');
ok('resolvePeriodRange("week") is seven days long',
    (strtotime($to . ' UTC') - strtotime($from . ' UTC')) === 6 * 86400, "$from .. $to");
ok('resolvePeriodRange("week") contains today',
    $from <= $now->format('Y-m-d') && $now->format('Y-m-d') <= $to, "$from .. $to");
$weekStart = weekStartDay() === 0 ? 7 : weekStartDay();
ok('resolvePeriodRange("week") starts on the configured week start',
    (int) gmdate('N', strtotime($from . ' UTC')) === $weekStart, "$from starts on " . gmdate('N', strtotime($from . ' UTC')));
ok('weekStartDay() reads the WEEK_START constant', weekStartDay() === (int) WEEK_START, (string) weekStartDay());

ok('resolvePeriodRange("custom") returns the two dates',
    resolvePeriodRange('custom', '2026-01-01', '2026-01-31') === ['2026-01-01', '2026-01-31']);

$message = '';
try {
    resolvePeriodRange('custom', '', '');
} catch (InvalidArgumentException $e) {
    $message = $e->getMessage();
}
ok('resolvePeriodRange("custom") without dates is an error',
    $message === 'Enter a start date and an end date.', $message);

$message = '';
try {
    resolvePeriodRange('custom', '2026-02-31', '2026-03-01');
} catch (InvalidArgumentException $e) {
    $message = $e->getMessage();
}
ok('resolvePeriodRange("custom") with an impossible date is an error',
    $message === 'Enter a valid date.', $message);

$message = '';
try {
    resolvePeriodRange('custom', '2026-03-01', '2026-02-01');
} catch (InvalidArgumentException $e) {
    $message = $e->getMessage();
}
ok('resolvePeriodRange("custom") with a reversed range is an error',
    $message === 'The start date must be on or before the end date.', $message);

ok('isValidUsername() accepts a name', isValidUsername('alice_01'));
ok('isValidUsername() accepts a hyphen', isValidUsername('a-b'));
ok('isValidUsername() rejects a short name', !isValidUsername('ab'));
ok('isValidUsername() rejects a space', !isValidUsername('a b'));
ok('isValidUsername() rejects a 33-character name', !isValidUsername(str_repeat('a', 33)));

ok('isValidEmail() accepts an address', isValidEmail('alice@example.com'));
ok('isValidEmail() rejects a broken address', !isValidEmail('alice@@example.com'));
ok('isValidEmail() rejects an empty address', !isValidEmail(''));
ok('isValidEmail() rejects an over-long address', !isValidEmail(str_repeat('a', 250) . '@example.com'));

ok('isValidType() accepts income', isValidType('income'));
ok('isValidType() accepts expense', isValidType('expense'));
ok('isValidType() rejects anything else', !isValidType('credit'));

ok('isValidCategoryName() accepts a name', isValidCategoryName('Food'));
ok('isValidCategoryName() rejects an empty name', !isValidCategoryName(''));
ok('isValidCategoryName() accepts a 60-character name', isValidCategoryName(str_repeat('x', 60)));
ok('isValidCategoryName() rejects a 61-character name', !isValidCategoryName(str_repeat('x', 61)));

[$fragment, $parameters] = userScope(7);
ok('userScope() filters on the user id in SQL', $fragment === 'user_id = ?' && $parameters === [7], $fragment);
[$fragment, $parameters] = userScope(7, 't');
ok('userScope() qualifies the column with the alias', $fragment === 't.user_id = ?' && $parameters === [7], $fragment);
ok('composeWhere() joins the fragments with AND',
    composeWhere(['user_id = ?', 'type = ?']) === 'user_id = ? AND type = ?');

ok('safeRedirectTarget() keeps a path', safeRedirectTarget('/transactions') === '/transactions');
ok('safeRedirectTarget() keeps a path with a query string',
    safeRedirectTarget('/transactions?period=month') === '/transactions?period=month');
ok('safeRedirectTarget() rejects a protocol-relative URL',
    safeRedirectTarget('//evil.example.com') === '/dashboard');
ok('safeRedirectTarget() rejects an absolute URL',
    safeRedirectTarget('http://evil.example.com') === '/dashboard');
ok('safeRedirectTarget() rejects a control character',
    safeRedirectTarget("/trans\r\nactions") === '/dashboard');
ok('safeRedirectTarget() falls back for an empty value', safeRedirectTarget('') === '/dashboard');
ok('safeRedirectTarget() falls back for null', safeRedirectTarget(null) === '/dashboard');

ok('asset_url() is root-absolute', str_starts_with(asset_url('css/base.css'), '/assets/css/base.css'));
ok('asset_url() carries a version stamp',
    (bool) preg_match('#^/assets/css/base\.css\?v=[0-9]{1,6}$#', asset_url('css/base.css')), asset_url('css/base.css'));

$_POST['probe_amount'] = '  12.50  ';
ok('post() trims the value', post('probe_amount') === '12.50');
$_POST['probe_array'] = ['a'];
ok('post() ignores a value that is not a string', post('probe_array') === '');
$_GET['probe_period'] = ' week ';
ok('query() trims the value', query('probe_period') === 'week');
unset($_POST['probe_amount'], $_POST['probe_array'], $_GET['probe_period']);


// ---------------------------------------------------------------------------
// C. The session and the CSRF token.
// ---------------------------------------------------------------------------

echo "\n## C. The session and the CSRF token\n";

ok('the session is started', session_status() === PHP_SESSION_ACTIVE, (string) session_status());

// The cookie the session actually asked for.
$cookieParams = session_get_cookie_params();
ok('the live session cookie is HttpOnly', (bool) $cookieParams['httponly'], (string) $cookieParams['httponly']);
ok('the live session cookie is SameSite=Lax', ($cookieParams['samesite'] ?? '') === 'Lax',
    (string) ($cookieParams['samesite'] ?? ''));
ok('the live session cookie is not Secure over plain HTTP',
    (bool) $cookieParams['secure'] === requestIsHttps(),
    'secure=' . (string) $cookieParams['secure']);

$token = getCsrfToken();
ok('the CSRF token is 64 hex characters', (bool) preg_match('/^[0-9a-f]{64}$/', $token), $token);
ok('the CSRF token is stable within a session', getCsrfToken() === $token);
ok('validateCsrfToken() accepts the token', validateCsrfToken($token));
ok('validateCsrfToken() rejects a wrong token', !validateCsrfToken(str_repeat('0', 64)));
ok('validateCsrfToken() rejects null', !validateCsrfToken(null));
ok('validateCsrfToken() rejects an empty string', !validateCsrfToken(''));

$rotated = rotateCsrfToken();
ok('rotateCsrfToken() produces a different token', $rotated !== $token);
ok('rotateCsrfToken() invalidates the old token', !validateCsrfToken($token));
ok('the rotated token is accepted', validateCsrfToken($rotated));
ok('the rotated token is 64 hex characters', (bool) preg_match('/^[0-9a-f]{64}$/', $rotated), $rotated);


// ---------------------------------------------------------------------------
// D. The router.
// ---------------------------------------------------------------------------

echo "\n## D. The router\n";

$table = Router::defaultRoutes();
// 23 routes from issue-012 plus GET /verify-email, which issue-016 added.
ok('the route table has 24 entries', count($table) === 24, (string) count($table));

$routes = [];
foreach ($table as $route) {
    $routes[$route[0] . ' ' . $route[1]] = $route[2];
}

ok('the root path redirects to the dashboard', ($routes['GET /'] ?? '') === 'redirect:/dashboard');
ok('the transaction form is reached at /transactions/new',
    ($routes['GET /transactions/new'] ?? '') === 'views/transaction_form.php');
ok('the transaction form takes the id from the query string',
    ($routes['GET /transactions/edit'] ?? '') === 'views/transaction_form.php');
ok('there is no wildcard route', !array_key_exists('GET /transactions/{id}/edit', $routes));
ok('the sign-in page has a GET and a POST',
    isset($routes['GET /login'], $routes['POST /login']));
ok('the sign-out route is a POST', isset($routes['POST /logout']));

$router = Router::withDefaultRoutes();

$resolved = $router->resolve('GET', '/dashboard');
ok('a known path resolves to its handler',
    $resolved['status'] === 200 && $resolved['handler'] === 'views/dashboard.php', (string) $resolved['status']);

$resolved = $router->resolve('POST', '/login');
ok('a second method on a known path resolves to its own handler',
    $resolved['status'] === 200 && $resolved['handler'] === 'views/login.php');

$resolved = $router->resolve('POST', '/transactions');
ok('the method is part of the match', $resolved['status'] === 405, (string) $resolved['status']);

$resolved = $router->resolve('GET', '/nope');
ok('an unknown path is a 404',
    $resolved['status'] === 404 && $resolved['handler'] === null, (string) $resolved['status']);

$resolved = $router->resolve('HEAD', '/dashboard');
ok('HEAD is dispatched as GET',
    $resolved['status'] === 200 && $resolved['handler'] === 'views/dashboard.php');

$resolved = $router->resolve('DELETE', '/dashboard');
ok('a wrong method is a 405', $resolved['status'] === 405, (string) $resolved['status']);
ok('the 405 carries an Allow header', ($resolved['headers']['Allow'] ?? '') === 'GET', (string) ($resolved['headers']['Allow'] ?? ''));

$resolved = $router->resolve('GET', '/transactions/');
ok('a trailing slash is the same path', $resolved['status'] === 200, (string) $resolved['status']);

ok('normalizePath() adds the leading slash', Router::normalizePath('dashboard') === '/dashboard');
ok('normalizePath() drops the trailing slash', Router::normalizePath('/transactions/') === '/transactions');
ok('normalizePath() keeps the root', Router::normalizePath('/') === '/');
ok('normalizePath() collapses a doubled slash', Router::normalizePath('/trans//actions') === '/trans/actions');

$thrown = null;
try {
    $router->dispatch('GET', '/nope');
} catch (HttpException $e) {
    $thrown = $e;
}
ok('dispatch() throws a 404 for an unknown path',
    $thrown instanceof HttpException && $thrown->status === 404, $thrown instanceof HttpException ? (string) $thrown->status : 'no exception');

$thrown = null;
try {
    $router->dispatch('POST', '/dashboard');
} catch (HttpException $e) {
    $thrown = $e;
}
ok('dispatch() throws a 405 for the wrong method',
    $thrown instanceof HttpException && $thrown->status === 405, $thrown instanceof HttpException ? (string) $thrown->status : 'no exception');
ok('the 405 exception carries the Allow header',
    $thrown instanceof HttpException && ($thrown->headers['Allow'] ?? '') === 'GET');

// A router whose route points at a file that does not exist. The
// route table is NOT used here on purpose: dispatching a real route
// runs a real handler, and a handler calls requireLogin(), which
// calls redirect(), which calls exit(). The test process would die in
// the middle of the run -- silently, because display_errors is Off --
// and every tier after this one would never be reached while the
// exit code still said zero.
$missingHandler = new Router();
$missingHandler->get('/no-such-handler', 'views/this_handler_does_not_exist.php');

$thrown = null;
try {
    $missingHandler->dispatch('GET', '/no-such-handler');
} catch (Throwable $e) {
    $thrown = $e;
}
ok('dispatch() refuses to run a handler that does not exist',
    $thrown instanceof RuntimeException && str_contains($thrown->getMessage(), 'Route handler not found'),
    $thrown instanceof Throwable ? $thrown->getMessage() : 'no exception');

ok('the router includes the handler file', str_contains($routerSource, 'require $file;'));
ok('the router builds the handler path from the application root',
    str_contains($routerSource, "dirname(__DIR__) . '/'"));
ok('the router never reads the request into a handler path',
    !preg_match('/\$_(?:GET|POST|REQUEST)\b[^;]*\.\s*\$handler/', $routerSource));


// ---------------------------------------------------------------------------
// E. The database.
// ---------------------------------------------------------------------------

echo "\n## E. The database\n";

if ($chosen === null) {
    skip('the database tier', 'no reachable MariaDB — tried ' . $tried . ' connection(s)'
        . ($lastError !== '' ? ': ' . $lastError : ''));
} else {
    $db = Database::getInstance();

    ok('getInstance() returns the same instance', Database::getInstance() === $db);

    $pdo = $db->getConnection();
    ok('the connection is a PDO handle', $pdo instanceof PDO);
    ok('EMULATE_PREPARES is off', $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false);
    ok('ERRMODE is EXCEPTION', $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);
    ok('the fetch mode is ASSOC', $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC);
    ok('STRINGIFY_FETCHES is off', $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) === false);

    $zone = $pdo->query("SELECT @@session.time_zone AS tz")->fetch(PDO::FETCH_ASSOC);
    ok('the session time zone is UTC', (string) ($zone['tz'] ?? '') === '+00:00', (string) ($zone['tz'] ?? ''));

    $tables = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM information_schema.tables
          WHERE table_schema = ? AND table_name IN ('users', 'categories', 'transactions')",
        [(string) DB_NAME]
    );

    if ((int) ($tables['c'] ?? 0) < 3) {
        skip('the database tier', 'the schema is not loaded in ' . DB_NAME
            . ' — run database/schema.sql first');
    } else {
        // Two accounts, each with one category and one transaction. The
        // point is the ownership boundary: what one account may read, and
        // what it may not.
        $stamp = bin2hex(random_bytes(4));
        $idA = 0;
        $idB = 0;

        try {
            $db->execute(
                'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
                ['rt_a_' . $stamp, 'rt_a_' . $stamp . '@example.test', hashPassword('probe')]
            );
            $idA = $db->lastInsertId();

            $db->execute(
                'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
                ['rt_b_' . $stamp, 'rt_b_' . $stamp . '@example.test', hashPassword('probe')]
            );
            $idB = $db->lastInsertId();

            ok('execute() inserts a row and lastInsertId() returns its id', $idA > 0 && $idB > 0);

            $db->execute(
                'INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)',
                [$idA, 'probe category A', 'expense']
            );
            $categoryA = $db->lastInsertId();

            $db->execute(
                'INSERT INTO transactions (user_id, category_id, type, amount, occurred_on, description)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$idA, $categoryA, 'expense', '10.10', '2026-10-19', 'probe A']
            );
            $transactionA = $db->lastInsertId();

            $db->execute(
                'INSERT INTO transactions (user_id, category_id, type, amount, occurred_on, description)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$idB, null, 'income', '99.99', '2026-10-19', 'probe B']
            );
            $transactionB = $db->lastInsertId();

            // execute() returns a bool — never a row count.
            $result = $db->execute(
                'UPDATE transactions SET description = ? WHERE id = ? AND user_id = ?',
                ['probe A edited', $transactionA, $idA]
            );
            ok('execute() returns a bool', is_bool($result));
            ok('execute() returns true for a statement that ran', $result === true);

            $noMatch = $db->execute(
                'UPDATE transactions SET description = ? WHERE id = ? AND user_id = ?',
                ['probe B edited', $transactionB, $idA]
            );
            ok('execute() returns true even when no row matched', $noMatch === true);

            $row = $db->fetchOne('SELECT description FROM transactions WHERE id = ?', [$transactionB]);
            ok('an UPDATE with the wrong owner changed nothing',
                (string) ($row['description'] ?? '') === 'probe B', (string) ($row['description'] ?? ''));

            // fetchAll() and fetchOne() on nothing.
            $rows = $db->fetchAll('SELECT id FROM transactions WHERE id = ?', [-1]);
            ok('fetchAll() returns an empty array when nothing matches', $rows === []);
            $row = $db->fetchOne('SELECT id FROM transactions WHERE id = ?', [-1]);
            ok('fetchOne() returns false when nothing matches', $row === false);

            // A failed query throws — it is never a silent false.
            $thrown = null;
            try {
                $db->query('SELECT * FROM a_table_that_does_not_exist');
            } catch (PDOException $e) {
                $thrown = $e;
            }
            ok('a failed query throws a PDOException', $thrown instanceof PDOException);

            // Money stays a string, all the way from the DECIMAL column.
            $row = $db->fetchOne('SELECT amount FROM transactions WHERE id = ?', [$transactionA]);
            ok('a DECIMAL amount comes back as a string', is_string($row['amount'] ?? null),
                gettype($row['amount'] ?? null));
            ok('the amount is exact', (string) ($row['amount'] ?? '') === '10.10', (string) ($row['amount'] ?? ''));

            $sum = $db->fetchOne('SELECT SUM(amount) AS total FROM transactions WHERE user_id = ?', [$idA]);
            ok('a SUM(amount) comes back as a string', is_string($sum['total'] ?? null), gettype($sum['total'] ?? null));
            ok('the sum is exact', (string) ($sum['total'] ?? '') === '10.10', (string) ($sum['total'] ?? ''));

            // The ownership boundary, enforced in the SQL.
            [$fragment, $parameters] = userScope($idA);
            $rows = $db->fetchAll("SELECT id FROM transactions WHERE {$fragment}", $parameters);
            ok('userScope() isolates one account from another',
                count($rows) === 1 && (int) $rows[0]['id'] === $transactionA, count($rows) . ' row(s)');

            [$fragment, $parameters] = userScope($idB);
            $rows = $db->fetchAll("SELECT id FROM transactions WHERE {$fragment}", $parameters);
            ok('the other account sees only its own row',
                count($rows) === 1 && (int) $rows[0]['id'] === $transactionB, count($rows) . ' row(s)');

            // A delete that filters on the owner leaves the other account's
            // row alone.
            $db->execute(
                'DELETE FROM transactions WHERE id = ? AND ' . $fragment,
                array_merge([$transactionA], $parameters)
            );
            $row = $db->fetchOne('SELECT id FROM transactions WHERE id = ?', [$transactionB]);
            ok('a scoped delete leaves the other account\'s row alone', $row !== false);
        } finally {
            // The probe rows go, whatever happened above. The foreign keys
            // cascade to the categories and the transactions.
            //
            // Two details matter here. The cleanup must not go through
            // Database::execute(): that is the code under test, and a broken
            // one throws here — inside the finally block — leaving debris
            // in the database. And the rows are matched by their probe
            // username rather than by an id: a statement that ran but whose
            // id was never read leaves behind a row the test cannot address
            // by id at all.
            try {
                $pdo = Database::getInstance()->getConnection();
                $stmt = $pdo->prepare('DELETE FROM users WHERE username LIKE ? OR username LIKE ?');
                $stmt->execute(['rt\_a\_' . $stamp, 'rt\_b\_' . $stamp]);
            } catch (Throwable $cleanupError) {
                fwrite(STDERR, 'the probe cleanup failed: ' . $cleanupError->getMessage() . PHP_EOL);
            }
        }

        $leftovers = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM users WHERE username LIKE 'rt\\_%' AND password_hash = ?",
            [hashPassword('probe')]
        );
        ok('the probe rows were cleaned up', (int) ($leftovers['c'] ?? 0) === 0,
            (string) ($leftovers['c'] ?? '?'));
    }
}


// ---------------------------------------------------------------------------
// F. The registration gate and the sign-in guard.
//
// Both of them end the request, so they run in a child process.
// ---------------------------------------------------------------------------

echo "\n## F. The gate and the guard\n";

$gate = <<<'PHP'
<?php
define('ALLOW_REGISTRATION', LITERAL);
$root = $argv[1];
require $root . '/includes/functions.php';
require $root . '/includes/auth.php';
echo registrationAllowed() ? 'OPEN' : 'CLOSED';
PHP;

foreach ([
    ['true',      'OPEN',   'the gate is open when the constant is true'],
    ['false',     'CLOSED', 'the gate is closed when the constant is false'],
    ["'false'",   'CLOSED', "the gate is closed when the constant is the string 'false'"],
] as [$literal, $expected, $description]) {
    $res = run_child(str_replace('LITERAL', $literal, $gate), [$root]);

    ok($description, trim($res['stdout']) === $expected && $res['stderr'] === '',
        trim($res['stdout']) . ($res['stderr'] !== '' ? ' / ' . trim($res['stderr']) : ''));
}

$guard = <<<'PHP'
<?php
$root = $argv[1];
require $root . '/includes/functions.php';
require $root . '/includes/auth.php';
// The session is opened before the first byte of output: in this SAPI a
// session cannot be started once the output has been flushed, and a
// header() after that is refused.
startSession();
$_SERVER['REQUEST_URI'] = '/transactions?period=month';
register_shutdown_function(function () {
    echo "\nINTENDED=" . ($_SESSION['intended_url'] ?? '(none)');
    echo "\nCODE=" . (string) http_response_code();
});
echo "REACHED\n";
requireLogin();
echo "AFTER\n";
PHP;

$res = run_child($guard, [$root]);
ok('requireLogin() ends the request when nobody is signed in',
    str_contains($res['stdout'], 'REACHED') && !str_contains($res['stdout'], 'AFTER'), trim($res['stdout']));
ok('requireLogin() remembers where the visitor was going',
    str_contains($res['stdout'], 'INTENDED=/transactions?period=month'), trim($res['stdout']));
ok('requireLogin() redirects with a 302', str_contains($res['stdout'], 'CODE=302'), trim($res['stdout']));
ok('the guard child ran without a warning', $res['stderr'] === '', trim($res['stderr']));

$pass = <<<'PHP'
<?php
$root = $argv[1];
require $root . '/includes/functions.php';
require $root . '/includes/auth.php';
startSession();
$_SESSION['user_id'] = 42;
echo "REACHED\n";
requireLogin();
echo "AFTER\n";
PHP;

$res = run_child($pass, [$root]);
ok('requireLogin() lets a signed-in visitor through',
    str_contains($res['stdout'], 'REACHED') && str_contains($res['stdout'], 'AFTER'), trim($res['stdout']));
ok('requireLogin() ran without a warning', $res['stderr'] === '', trim($res['stderr']));

$session = <<<'PHP'
<?php
$root = $argv[1];
require $root . '/includes/functions.php';
require $root . '/includes/auth.php';
loginUser(42);
echo "USER=" . (string) getCurrentUserId();
echo "\nTOKEN=" . getCsrfToken();
logoutUser();
echo "\nAFTER_LOGOUT=" . var_export(getCurrentUserId(), true);
PHP;

$res = run_child($session, [$root]);
ok('loginUser() signs the user in', str_contains($res['stdout'], 'USER=42'), trim($res['stdout']));
ok('loginUser() rotates the CSRF token', (bool) preg_match('/TOKEN=[0-9a-f]{64}/', $res['stdout']), trim($res['stdout']));
ok('logoutUser() signs the user out', str_contains($res['stdout'], 'AFTER_LOGOUT=NULL'), trim($res['stdout']));
ok('the session child ran without a warning', $res['stderr'] === '', trim($res['stderr']));

ok('hashPassword() and verifyPassword() agree',
    verifyPassword('probe-secret', hashPassword('probe-secret')));
ok('verifyPassword() rejects a wrong password',
    !verifyPassword('wrong', hashPassword('probe-secret')));
ok('hashPassword() does not store the password', !str_contains(hashPassword('probe-secret'), 'probe-secret'));


// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n";
echo "Failed: " . count($failures) . "\n";

if (count($skips) > 0) {
    echo "Skipped: " . count($skips) . "\n";
    foreach ($skips as $skipped) {
        echo "  - $skipped\n";
    }
}

if (count($failures) > 0) {
    echo "FAILURES:\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

// A skipped tier means the run was partial, not complete. The success line
// says so — a skip must never read as a clean sweep.
if (count($skips) > 0) {
    echo 'ALL ASSERTIONS PASSED (' . count($skips) . " tier(s) skipped — the run was partial)\n";
} else {
    echo "ALL ASSERTIONS PASSED\n";
}
exit(0);
