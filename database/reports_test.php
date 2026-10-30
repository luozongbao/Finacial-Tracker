<?php

/**
 * Issue 019 — the reports screen.
 *
 * Two tiers:
 *
 *   A. The sources   — the files exist, the guard is the first statement,
 *                      every dynamic value is escaped, the ownership is
 *                      enforced in the SQL, the bucket expression is a
 *                      literal, the Total row comes from the period
 *                      totals, and the helpers behave the way the
 *                      specification says they behave.
 *
 *   B. The sandbox   — the real screen, against a scratch database: the
 *                      month report, the year report, the day report, the
 *                      custom range, the invalid range, the empty period
 *                      and the failed query.
 *
 * A test passes on its EXIT CODE, not on its output. The success markers
 * are not consistent across this repository, so nothing below greps for
 * one.
 *
 * Run it with:
 *
 *     docker compose exec -T app php /var/www/html/database/reports_test.php
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// The harness
// ---------------------------------------------------------------------------

/** @var string[] */
$failures = [];

/** The one cookie jar of the client. */
$GLOBALS['rt_cookies'] = [];

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $failures;

    if ($condition) {
        echo "  ok   " . $label . "\n";

        return;
    }

    $failures[] = $label . ($detail !== '' ? ' -- ' . $detail : '');
    echo "  FAIL " . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
}

/**
 * Forget every cookie the previous request set, so a new session starts
 * from a clean slate.
 */
function rt_reset_cookies(): void
{
    $GLOBALS['rt_cookies'] = [];
}

/**
 * A tiny HTTP client: one cookie jar, a status, the headers and the body.
 *
 * @param array<string, string> $fields
 * @param string[]              $extraHeaders
 *
 * @return array{status: int|null, body: string, headers: array<string, string>, set_cookie: string[]}
 */
function rt_http_request(
    string $method,
    string $url,
    array $fields = [],
    array $extraHeaders = [],
    int $timeout = 30
): array {
    $headers = [
        'User-Agent: financial-tracker-reports-test',
        'Accept: text/html',
    ];

    $body = '';

    if ($method === 'POST') {
        $body = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    foreach ($extraHeaders as $extra) {
        $headers[] = $extra;
    }

    $jar = $GLOBALS['rt_cookies'] ?? [];

    if ($jar !== []) {
        $pairs = [];

        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => $method,
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'content'         => $body,
            'header'          => implode("\r\n", $headers),
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);

    $status      = null;
    $respHeaders = [];
    $setCookies  = [];

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
            continue;
        }

        $pos = strpos($header, ':');

        if ($pos === false) {
            continue;
        }

        $name  = strtolower(trim(substr($header, 0, $pos)));
        $value = trim(substr($header, $pos + 1));

        $respHeaders[$name] = $value;

        if ($name === 'set-cookie') {
            $setCookies[] = $value;
        }
    }

    foreach ($setCookies as $cookie) {
        $pair = explode(';', $cookie)[0];
        $eq   = strpos($pair, '=');

        if ($eq === false) {
            continue;
        }

        $name  = trim(substr($pair, 0, $eq));
        $value = trim(substr($pair, $eq + 1));

        if ($value === '' || $value === 'deleted') {
            unset($GLOBALS['rt_cookies'][$name]);
            continue;
        }

        $GLOBALS['rt_cookies'][$name] = $value;
    }

    return [
        'status'     => $status,
        'body'       => $response === false ? '' : $response,
        'headers'    => $respHeaders,
        'set_cookie' => $setCookies,
    ];
}

/**
 * The CSRF token out of a rendered page.
 */
function rt_csrf_of(string $body): string
{
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * Every table body of a page, in the order the tables appear.
 *
 * @return string[]
 */
function rt_tbodies(string $body): array
{
    $bodies = [];

    if (preg_match_all('/<tbody[^>]*>(.*?)<\/tbody>/is', $body, $matches) > 0) {
        $bodies = $matches[1];
    }

    return $bodies;
}

/**
 * The rows of a table body.
 *
 * @return string[]
 */
function rt_rows(string $tbody): array
{
    $rows = [];

    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tbody, $matches) > 0) {
        $rows = $matches[1];
    }

    return $rows;
}

/**
 * The money out of a cell: the sign is kept, the thousands separator and
 * the glyphs are removed and the value comes back as a number.
 *
 * A float is fine HERE: the assertion is about a rendered figure, not
 * about the money path.
 */
function rt_number(string $cell): float
{
    $clean = str_replace(
        ["\u{2212}", ',', "\u{2014}", ' ', '+'],
        ['-', '', '', '', ''],
        $cell
    );

    return (float) $clean;
}

/**
 * The sum of a list of rendered money cells.
 *
 * @param string[] $cells
 */
function rt_sum(array $cells): float
{
    $sum = 0.0;

    foreach ($cells as $cell) {
        $sum += rt_number($cell);
    }

    return $sum;
}

/**
 * The source of a file with its comments removed.
 *
 * The structural checks look for a pattern in the CODE. A comment that
 * mentions a function must not satisfy a check, and must not break one
 * either. The tokenizer knows the difference between a comment and a
 * string; a regular expression does not.
 */
function rt_strip_comments(string $source): string
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
 * The comments of a source that carry a PHP tag.
 *
 * A literal close tag inside a // comment still closes PHP mode, and a
 * literal open tag inside a // comment still opens a script block. Both
 * are outages waiting for the next edit.
 *
 * @return string[]
 */
function rt_comment_traps(string $source): array
{
    $traps = [];

    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
            continue;
        }

        if (str_contains($token[1], '?>') || str_contains($token[1], '<?php')) {
            $traps[] = trim($token[1]);
        }
    }

    return $traps;
}

/**
 * The two branches of a ternary, or null when the expression is not one.
 *
 * @return array{0: string, 1: string}|null
 */
function rt_split_ternary(string $expression): ?array
{
    $expression = trim($expression);

    if (substr_count($expression, '?') !== 1 || substr_count($expression, ':') !== 1) {
        return null;
    }

    $parts = explode('?', $expression, 2);
    $then  = trim($parts[1]);
    $colon = strpos($then, ':');

    if ($colon === false) {
        return null;
    }

    return [trim(substr($then, 0, $colon)), trim(substr($then, $colon + 1))];
}

/**
 * Is this expression a pair of string literals?
 */
function rt_is_literal_expression(string $expression): bool
{
    return preg_match("/^'[^']*'$/", trim($expression)) === 1
        || preg_match('/^"[^"]*"$/', trim($expression)) === 1;
}

/**
 * Every short echo in a source that prints something which is NOT escaped.
 *
 * A short echo is allowed when it calls e(), when it prints a literal, or
 * when it is a ternary whose two branches are both literals — the condition
 * is dynamic, the printed text is not.
 *
 * @return string[]
 */
function rt_unescaped_outputs(string $src): array
{
    $bad = [];

    if (preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $src, $matches) === false) {
        return $bad;
    }

    foreach ($matches[1] as $expression) {
        $expression = trim($expression);

        if (str_contains($expression, 'e(')) {
            continue;
        }

        if (rt_is_literal_expression($expression)) {
            continue;
        }

        $ternary = rt_split_ternary($expression);

        if ($ternary !== null
            && rt_is_literal_expression($ternary[0])
            && rt_is_literal_expression($ternary[1])) {
            continue;
        }

        $bad[] = $expression;
    }

    return $bad;
}

/**
 * The values of the .env file.
 *
 * @return array<string, string>
 */
function rt_env_values(string $root): array
{
    $values = [];
    $file   = $root . '/.env';

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

        $values[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1), "\t\"'");
    }

    return $values;
}

/**
 * A free TCP port on the loopback interface.
 */
function rt_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($sock === false) {
        return 0;
    }

    $name = (string) stream_socket_get_name($sock, false);
    fclose($sock);

    $colon = strrpos($name, ':');

    return $colon === false ? 0 : (int) substr($name, $colon + 1);
}

/**
 * Start the PHP built-in server on the sandbox and wait for it to answer.
 *
 * @return array{proc: resource|null, base: string}
 */
function rt_start_server(string $docroot): array
{
    $port = rt_free_port();

    if ($port === 0) {
        return ['proc' => null, 'base' => ''];
    }

    $log = $docroot . '/server.log';

    $cmd = escapeshellarg(PHP_BINARY)
        . ' -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($docroot)
        . ' ' . escapeshellarg($docroot . '/router.php');

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'w'],
        2 => ['file', $log, 'a'],
    ];

    $pipes = [];
    $proc  = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($proc)) {
        return ['proc' => null, 'base' => ''];
    }

    $base = 'http://127.0.0.1:' . $port;

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $res = rt_http_request('GET', $base . '/__ping__', [], [], 2);

        if ($res['status'] !== null) {
            break;
        }

        usleep(50000);
    }

    return ['proc' => $proc, 'base' => $base];
}

/**
 * Stop the built-in server.
 *
 * @param array{proc: resource|null, base: string} $server
 */
function rt_stop_server(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc'], 15);
        proc_close($server['proc']);
    }
}

/**
 * Remove a directory tree.
 */
function rt_remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path) && !is_link($path)) {
            rt_remove_tree($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

/**
 * Build the sandbox: a copy of the application with its own config.
 *
 * @param array{host: string, name: string, user: string, pass: string} $db
 */
function rt_make_sandbox(string $root, array $db): string
{
    $dir = sys_get_temp_dir() . '/ft_rep_' . bin2hex(random_bytes(5));

    mkdir($dir, 0700, true);
    mkdir($dir . '/includes', 0700, true);
    mkdir($dir . '/views', 0700, true);

    copy($root . '/index.php', $dir . '/index.php');

    foreach (glob($root . '/includes/*.php') ?: [] as $file) {
        copy($file, $dir . '/includes/' . basename($file));
    }

    foreach (glob($root . '/views/*.php') ?: [] as $file) {
        copy($file, $dir . '/views/' . basename($file));
    }

    mkdir($dir . '/includes/views', 0700, true);

    foreach (glob($root . '/includes/views/*.php') ?: [] as $file) {
        copy($file, $dir . '/includes/views/' . basename($file));
    }

    @symlink($root . '/assets', $dir . '/assets');
    @symlink($root . '/vendor', $dir . '/vendor');

    $config = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . 'const DB_HOST = ' . var_export($db['host'], true) . ";\n"
        . 'const DB_NAME = ' . var_export($db['name'], true) . ";\n"
        . 'const DB_USER = ' . var_export($db['user'], true) . ";\n"
        . 'const DB_PASS = ' . var_export($db['pass'], true) . ";\n"
        . "const SMTP_HOST = '';\n"
        . "const SMTP_PORT = 587;\n"
        . "const SMTP_USER = '';\n"
        . "const SMTP_PASS = '';\n"
        . "const MAIL_FROM = 'no-reply@example.test';\n"
        . "const MAIL_FROM_NAME = 'Financial Tracker';\n"
        . "const ALLOW_REGISTRATION = true;\n"
        . "const WEEK_START = 1;\n"
        . "const TIMEZONE = 'UTC';\n";

    file_put_contents($dir . '/config.php', $config);

    file_put_contents($dir . '/router.php', <<<'ROUTER'
<?php
$_SERVER['SERVER_NAME'] = 'rep.test';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . '/' . ltrim($path, '/');

if ($path !== '/' && $path !== '/router.php' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
ROUTER);

    return $dir;
}

/**
 * Create the scratch database and load the schema.
 *
 * @return array{host: string, name: string, user: string, pass: string, pdo: PDO}|null
 */
function rt_create_scratch_database(string $root): ?array
{
    $env  = rt_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return null;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_rep_test`');
        $pdo->exec('CREATE DATABASE `ft_rep_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `ft_rep_test`');

        $schema = (string) file_get_contents($root . '/database/schema.sql');
        $pdo->exec($schema);
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be prepared: " . $ex->getMessage() . "\n";

        return null;
    }

    return [
        'host' => $host,
        'name' => 'ft_rep_test',
        'user' => 'root',
        'pass' => $pass,
        'pdo'  => $pdo,
    ];
}

/**
 * Drop the scratch database.
 */
function rt_drop_scratch_database(string $root): void
{
    $env  = rt_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_rep_test`');
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be dropped: " . $ex->getMessage() . "\n";
    }
}

/**
 * Seed an account and return its id.
 */
function rt_seed_user(PDO $pdo, string $username, string $email, string $password): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, email_verified_at, status)
         VALUES (?, ?, ?, NOW(), \'active\')'
    );

    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);

    return (int) $pdo->lastInsertId();
}

/**
 * Seed a category and return its id.
 */
function rt_seed_category(PDO $pdo, int $userId, string $name, string $type): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)'
    );

    $stmt->execute([$userId, $name, $type]);

    return (int) $pdo->lastInsertId();
}

/**
 * Seed a transaction and return its id.
 */
function rt_seed_transaction(
    PDO $pdo,
    int $userId,
    string $type,
    string $amount,
    string $occurredOn,
    ?string $description,
    ?int $categoryId
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO transactions (user_id, category_id, type, amount, occurred_on, description)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([$userId, $categoryId, $type, $amount, $occurredOn, $description]);

    return (int) $pdo->lastInsertId();
}

/**
 * The number of an average figure: "−27.35 per day" is -27.35.
 *
 * The minus is the typographic one (U+2212), the same glyph the screen
 * prints. A figure that does not read as a number is null — and a
 * figure that does not name its unit is a figure that should not be on
 * the screen at all.
 */
function rt_average_value(string $figure): ?float
{
    if (preg_match('/^([+−-]?)([\d,]+(?:\.\d+)?)\s+per\s+(day|week|month|year)$/u', trim($figure), $m) !== 1) {
        return null;
    }

    $value = (float) str_replace(',', '', $m[2]);

    if ($m[1] === '−') {
        return -$value;
    }

    return $value;
}

/**
 * The unit an average figure names: "39.68 per day" is "day".
 */
function rt_average_unit(string $figure): string
{
    if (preg_match('/\bper\s+(day|week|month|year)$/u', trim($figure), $m) === 1) {
        return $m[1];
    }

    return '';
}

// ---------------------------------------------------------------------------
// The fixtures
// ---------------------------------------------------------------------------

$root        = dirname(__DIR__);
$sandboxRoot = '';
$server      = null;

$demoUser     = 'demo';
$demoPassword = 'CorrectHorseBattery9!';

$otherUser     = 'other';
$otherPassword = 'CorrectHorseBattery9!';

echo "Issue 019 — the reports screen\n";

// ---------------------------------------------------------------------------
// A. The file tier
// ---------------------------------------------------------------------------

echo "\n## A. The sources\n";

// --- A1. The files exist ---

$files = [
    'includes/functions.php',
    'views/reports.php',
    'assets/css/pages/reports.css',
];

foreach ($files as $file) {
    ok('the file ' . $file . ' exists', is_file($root . '/' . $file));
}

$src = [];

foreach ($files as $file) {
    $source = @file_get_contents($root . '/' . $file);
    $src[$file] = $source === false ? '' : $source;
}

$view = $src['views/reports.php'];
$code = rt_strip_comments($view);

// --- A2. The guard is the first statement of the view ---

$guardAt = strpos($code, 'requireLogin()');
$getAt   = strpos($code, '$_GET');
$postAt  = strpos($code, '$_POST');

ok(
    'the view calls requireLogin() before it reads any input',
    $guardAt !== false
        && ($getAt === false || $guardAt < $getAt)
        && ($postAt === false || $guardAt < $postAt),
    'guard at ' . ($guardAt === false ? 'none' : (string) $guardAt)
);

ok(
    'the view does not read the superglobals directly',
    !str_contains($code, '$_GET') && !str_contains($code, '$_POST'),
    'the view reads a superglobal'
);

// --- A3. Every dynamic output is escaped ---

$bad = rt_unescaped_outputs($view);

ok('the view escapes every dynamic value it prints', $bad === [], implode(' | ', array_slice($bad, 0, 4)));

// --- A4. No PHP tag hides in a comment ---

$traps = rt_comment_traps($view) + rt_comment_traps($src['includes/functions.php']);

ok('no comment carries a PHP tag', $traps === [], implode(' | ', array_slice($traps, 0, 3)));

// --- A5. The ownership is enforced in the SQL ---

ok('the totals filter on the user id', (bool) preg_match('/WHERE\s+user_id\s*=\s*\?/i', $code));

ok(
    'the buckets filter on the user id',
    str_contains($code, 'AND occurred_on BETWEEN ? AND ?')
);

ok(
    'the category join is scoped to the owner',
    str_contains($code, 'c.user_id = t.user_id'),
    'the join leaks another account’s category name'
);

ok(
    'the category query filters on the user id',
    (bool) preg_match('/WHERE\s+t\.user_id\s*=\s*\?/i', $code)
);

// --- A6. The sums are computed in SQL ---

ok('the net is a SUM(CASE ...) in SQL', substr_count($code, 'SUM(CASE WHEN') >= 3);

ok(
    'the sign of the net comes from the database',
    str_contains($code, 'net_sign'),
    'the sign of the net is decided in PHP'
);

// --- A7. The bucket expression is a literal ---

require_once $root . '/includes/functions.php';

$literals = [
    'occurred_on',
    'DATE_SUB(occurred_on, INTERVAL WEEKDAY(occurred_on) DAY)',
    'DATE_SUB(occurred_on, INTERVAL (DAYOFWEEK(occurred_on) - 1) DAY)',
    'DATE_SUB(occurred_on, INTERVAL (DAYOFMONTH(occurred_on) - 1) DAY)',
];

ok(
    'the day bucket is the date itself',
    reportBucketExpression('day') === 'occurred_on',
    reportBucketExpression('day')
);

ok(
    'the month bucket is the first day of the month',
    reportBucketExpression('month') === $literals[3],
    reportBucketExpression('month')
);

ok(
    'the week bucket follows WEEK_START',
    reportBucketExpression('week') === $literals[1],
    reportBucketExpression('week')
);

ok(
    'every bucket expression is one of the four literals',
    in_array(reportBucketExpression('week'), $literals, true)
);

ok(
    'the bucket expression is chosen by the grain, never by the request',
    str_contains($code, "\$bucketExpression = reportBucketExpression(\$grain['grain'])")
);

ok(
    'no request value reaches the SQL',
    !preg_match('/\{\$(from|to|period|month|grain|uid|unit)/', $code),
    'a request value is interpolated into a statement'
);

// --- A8. The Total row comes from the period totals ---

ok(
    'the Total row prints the period totals',
    str_contains($view, '$incomeLabel') && str_contains($view, '$spendingLabel')
);

ok(
    'the tiles print the same period totals',
    substr_count($view, '$incomeLabel') >= 2
        && substr_count($view, '$spendingLabel') >= 2
        && substr_count($view, '$netLabel') >= 2
);

ok(
    'the share is taken against the period volume',
    str_contains($view, '$periodVolume')
);

ok(
    'the share denominator is guarded',
    str_contains($view, '$periodVolume > 0')
);

// --- A9. The helpers ---

ok(
    'resolveMonthRange() resolves a calendar month',
    resolveMonthRange('2026-08') === ['2026-08-01', '2026-08-31'],
    json_encode(resolveMonthRange('2026-08'))
);

ok(
    'resolveMonthRange() refuses an impossible month',
    resolveMonthRange('2026-13') === null
);

ok(
    'resolveMonthRange() refuses a malformed month',
    resolveMonthRange('2026-8') === null
);

ok(
    'resolveMonthRange() handles February in a leap year',
    resolveMonthRange('2028-02') === ['2028-02-01', '2028-02-29'],
    json_encode(resolveMonthRange('2028-02'))
);

ok(
    'the day period breaks down by day',
    reportGrain('day', '2026-10-19', '2026-10-19') === ['grain' => 'day', 'header' => 'Day']
);

ok(
    'the week period breaks down by day',
    reportGrain('week', '2026-10-19', '2026-10-25') === ['grain' => 'day', 'header' => 'Day']
);

ok(
    'the month period breaks down by week',
    reportGrain('month', '2026-10-01', '2026-10-31') === ['grain' => 'week', 'header' => 'Week of']
);

ok(
    'the year period breaks down by month',
    reportGrain('year', '2026-01-01', '2026-12-31') === ['grain' => 'month', 'header' => 'Month']
);

ok(
    'a custom range of 62 days is daily',
    reportGrain('custom', '2026-01-01', '2026-03-03')['grain'] === 'day'
);

ok(
    'a custom range of 63 days is weekly',
    reportGrain('custom', '2026-01-01', '2026-03-04')['grain'] === 'week'
);

ok(
    'a custom range of 366 days is weekly',
    reportGrain('custom', '2026-01-01', '2027-01-01')['grain'] === 'week'
);

ok(
    'a custom range of 367 days is monthly',
    reportGrain('custom', '2026-01-01', '2027-01-02')['grain'] === 'month'
);

ok(
    'the span of a range counts both ends',
    reportSpanDays('2026-10-19', '2026-10-19') === 1,
    (string) reportSpanDays('2026-10-19', '2026-10-19')
);

ok(
    'the span of a week is seven days',
    reportSpanDays('2026-10-19', '2026-10-25') === 7
);

ok(
    'a day bucket ends on the same day',
    reportBucketEnd('2026-10-19', 'day') === '2026-10-19'
);

ok(
    'a week bucket ends six days later',
    reportBucketEnd('2026-10-19', 'week') === '2026-10-25'
);

ok(
    'a month bucket ends on the last day of the month',
    reportBucketEnd('2026-10-19', 'month') === '2026-10-31'
);

ok(
    'a range inside the period is not clipped',
    clipDateRange('2026-10-19', '2026-10-25', '2026-10-01', '2026-10-31')
        === ['2026-10-19', '2026-10-25']
);

ok(
    'a range that starts before the period is clipped at the start',
    clipDateRange('2026-08-10', '2026-08-16', '2026-08-15', '2026-10-31')
        === ['2026-08-15', '2026-08-16'],
    json_encode(clipDateRange('2026-08-10', '2026-08-16', '2026-08-15', '2026-10-31'))
);

ok(
    'a range that ends after the period is clipped at the end',
    clipDateRange('2026-10-26', '2026-11-01', '2026-10-01', '2026-10-31')
        === ['2026-10-26', '2026-10-31']
);

ok(
    'a share is one decimal with a percent sign',
    formatShare(57.8) === '57.8%',
    formatShare(57.8)
);

ok(
    'a share is rounded to one decimal',
    formatShare(57.77777) === '57.8%',
    formatShare(57.77777)
);

ok(
    'the income cell keeps its sign on a zero',
    formatIncomeMoney('0.00') === '+0.00',
    formatIncomeMoney('0.00')
);

ok(
    'the income cell carries a plus on a positive amount',
    formatIncomeMoney('1200.00') === '+1,200.00',
    formatIncomeMoney('1200.00')
);

// --- A10. The period selector ---

ok(
    'the selector carries the five links',
    str_contains($view, 'data-period="day"')
        && str_contains($view, 'data-period="week"')
        && str_contains($view, 'data-period="month"')
        && str_contains($view, 'data-period="year"')
        && str_contains($view, 'data-period="custom"')
);

ok(
    'the active link carries aria-current',
    str_contains($view, 'aria-current="true"')
);

ok(
    'the selector works without JavaScript',
    (bool) preg_match('/class="period__link" href="\/reports\?period=day&amp;unit=<\?= e\(\$unit\) \?>"/', $view)
);

ok(
    'the selector carries the normalised period',
    str_contains($view, 'data-period-group="<?= e($period) ?>')
);

// --- A11. The custom range form ---

ok(
    'the range form is a GET form to the report',
    (bool) preg_match('/<form class="filters rep__range"[^>]*method="get"[^>]*action="\/reports"/i', $view)
);

ok(
    'the range form carries the hidden period',
    str_contains($view, '<input type="hidden" name="period" value="custom">')
);

ok(
    'the range form carries the two date inputs',
    str_contains($view, 'name="from" type="date"') && str_contains($view, 'name="to" type="date"')
);

ok(
    'the range form submits the range',
    str_contains($view, '>Apply range</button>')
);

ok(
    'the range form carries no CSRF token',
    !str_contains($view, 'name="csrf_token"'),
    'a GET form must not carry a token'
);

// --- A12. The markup ---

ok(
    'the row headers are row headers',
    str_contains($view, '<th scope="row">')
);

ok(
    'the tables are captioned',
    str_contains($view, '<caption class="sr-only">')
);

$cells = [];

if (preg_match_all('/<td[^>]*>/', $view, $matches) > 0) {
    $cells = $matches[0];
}

ok('the view has cells', $cells !== []);

$withoutLabel = 0;

foreach ($cells as $cell) {
    if (!str_contains($cell, 'data-label=')) {
        $withoutLabel++;
    }
}

ok('every cell carries a data-label', $withoutLabel === 0, (string) $withoutLabel . ' cells without a label');

ok(
    'the Total row carries a share of 100.0%',
    str_contains($view, '<td data-label="Share" class="num">100.0%</td>')
);

ok(
    'the status line uses the middle dot',
    str_contains($view, "' \u{00B7} '")
        || str_contains($view, "·"),
    'the separator is missing'
);

ok(
    'the error state renders no tiles',
    str_contains($view, 'The report could not be loaded.')
        && str_contains($view, 'No report available')
);

ok(
    'the empty period renders no tables',
    str_contains($view, 'No transactions in this period.')
        && str_contains($view, 'Look at this month')
);

// --- A13. The average ---

ok(
    'the three helpers exist',
    function_exists('normalizeAverageUnit')
        && function_exists('reportUnitCount')
        && function_exists('formatAverageMoney')
);

ok(
    'the unit is whitelisted',
    normalizeAverageUnit('day') === 'day'
        && normalizeAverageUnit('WEEK') === 'week'
        && normalizeAverageUnit(' month ') === 'month'
        && normalizeAverageUnit('year') === 'year',
    normalizeAverageUnit('nonsense')
);

ok(
    'an unknown unit falls back to the day',
    normalizeAverageUnit('nonsense') === 'day',
    normalizeAverageUnit('nonsense')
);

ok(
    'the denominator of a month is its days',
    reportUnitCount('2026-10-01', '2026-10-31', 'day') === 31,
    (string) reportUnitCount('2026-10-01', '2026-10-31', 'day')
);

ok(
    'the denominator of a month is its five weeks',
    reportUnitCount('2026-10-01', '2026-10-31', 'week') === 5,
    (string) reportUnitCount('2026-10-01', '2026-10-31', 'week')
);

ok(
    'the denominator of a month is one month',
    reportUnitCount('2026-10-01', '2026-10-31', 'month') === 1,
    (string) reportUnitCount('2026-10-01', '2026-10-31', 'month')
);

ok(
    'the denominator of a month is one year',
    reportUnitCount('2026-10-01', '2026-10-31', 'year') === 1,
    (string) reportUnitCount('2026-10-01', '2026-10-31', 'year')
);

ok(
    'a range that straddles three months has three months',
    reportUnitCount('2026-01-31', '2026-03-01', 'month') === 3,
    (string) reportUnitCount('2026-01-31', '2026-03-01', 'month')
);

ok(
    'a range that straddles two years has two years',
    reportUnitCount('2025-12-31', '2026-01-01', 'year') === 2,
    (string) reportUnitCount('2025-12-31', '2026-01-01', 'year')
);

ok(
    'a full week is one week',
    reportUnitCount('2026-10-05', '2026-10-11', 'week') === 1,
    (string) reportUnitCount('2026-10-05', '2026-10-11', 'week')
);

ok(
    'a week that straddles the week start is two weeks',
    reportUnitCount('2026-10-07', '2026-10-13', 'week') === 2,
    (string) reportUnitCount('2026-10-07', '2026-10-13', 'week')
);

ok(
    'the week starts on the Monday of the week',
    reportWeekStart('2026-10-07') === '2026-10-05',
    reportWeekStart('2026-10-07')
);

ok(
    'a range that ends before it starts has no unit',
    reportUnitCount('2026-10-31', '2026-10-01', 'day') === 0,
    (string) reportUnitCount('2026-10-31', '2026-10-01', 'day')
);

ok(
    'the average names its unit',
    formatAverageMoney('39.68', 'day', '+') === '+39.68 per day',
    formatAverageMoney('39.68', 'day', '+')
);

ok(
    'the average of a zero carries no sign',
    formatAverageMoney('0.00', 'day', '+') === '0.00 per day',
    formatAverageMoney('0.00', 'day', '+')
);

ok(
    'the average of a spending carries the minus sign',
    formatAverageMoney('27.35', 'day', '−') === '−27.35 per day',
    formatAverageMoney('27.35', 'day', '−')
);

ok(
    'the average is not an AVG()',
    !preg_match('/\bAVG\s*\(/i', $code),
    'the statement averages the rows that exist'
);

ok(
    'the divisor is bound',
    str_contains($code, 'CAST(? AS DECIMAL(10,0))'),
    'the divisor is interpolated into the statement'
);

ok(
    'the divisor is the unit count',
    str_contains($code, 'reportUnitCount($range[0], $range[1], $unit)')
        && str_contains($code, '$unitCount')
);

ok(
    'the net average is computed from the rounded sides',
    str_contains($code, 'ROUND(avg_income - avg_spending, 2)')
);

ok(
    'the sign of the net average comes from the database',
    str_contains($code, 'avg_net_sign')
);

ok(
    'the average is not divided in PHP',
    !preg_match('/\$average\w*Label\s*=[^;]*\/[^;]*;/', $code),
    'the view divides the average in PHP'
);

ok(
    'the three averages are formatted by the helper',
    substr_count($code, 'formatAverageMoney(') >= 3,
    (string) substr_count($code, 'formatAverageMoney(')
);

ok(
    'the unit is normalised before it is used',
    str_contains($code, "normalizeAverageUnit(query('unit', 'day'))")
);

ok(
    'the unit control carries the four links',
    str_contains($view, 'data-unit="day"')
        && str_contains($view, 'data-unit="week"')
        && str_contains($view, 'data-unit="month"')
        && str_contains($view, 'data-unit="year"')
);

ok(
    'the unit control names itself',
    str_contains($view, '<span class="period__label">Average per</span>')
);

ok(
    'the three tiles carry an average',
    substr_count($view, 'tile__average') === 3,
    (string) substr_count($view, 'tile__average')
);

ok(
    'the range form keeps the unit',
    str_contains($view, '<input type="hidden" name="unit" value="<?= e($unit) ?>">')
);

// ---------------------------------------------------------------------------
// B. The sandbox
// ---------------------------------------------------------------------------

echo "\n## B. The sandbox\n";

$db = rt_create_scratch_database($root);

if ($db === null) {
    ok('the scratch database is created', false, 'the database server did not answer');
} else {
    ok('the scratch database is created', true);

    $sandboxRoot = rt_make_sandbox($root, $db);
    $pdo = $db['pdo'];

    // --- the fixtures --------------------------------------------------
    //
    // The demo account's October is the specification's own figure set
    // (issue-019 §13.3): 18 transactions, +1,229.99 of income,
    // -847.80 of spending and +382.19 of net, spread over three weeks
    // that start on a Monday.
    //
    // Two entries sit outside October: one in August, so a custom range
    // can start in the middle of a week, and one in September, so the
    // year view has a month that is not the last one.

    $demoId   = rt_seed_user($pdo, $demoUser, $demoUser . '@example.test', $demoPassword);
    $otherId  = rt_seed_user($pdo, $otherUser, $otherUser . '@example.test', $otherPassword);

    $salaryId    = rt_seed_category($pdo, $demoId, 'Salary', 'income');
    $groceriesId = rt_seed_category($pdo, $demoId, 'Groceries', 'expense');
    $utilitiesId = rt_seed_category($pdo, $demoId, 'Utilities', 'expense');
    $eatingId    = rt_seed_category($pdo, $demoId, 'Eating out', 'expense');

    $otherCategoryId = rt_seed_category($pdo, $otherId, 'Other groceries', 'expense');

    // Groceries: 8 entries, 342.60.
    $groceries = [
        ['42.80', '2026-10-06'],
        ['55.30', '2026-10-07'],
        ['18.35', '2026-10-08'],
        ['61.25', '2026-10-13'],
        ['30.15', '2026-10-14'],
        ['52.35', '2026-10-20'],
        ['37.50', '2026-10-21'],
        ['44.90', '2026-10-22'],
    ];

    foreach ($groceries as $entry) {
        rt_seed_transaction($pdo, $demoId, 'expense', $entry[0], $entry[1], 'Groceries', $groceriesId);
    }

    // Utilities: 5 entries, 418.25.
    $utilities = [
        ['78.90', '2026-10-05'],
        ['50.05', '2026-10-09'],
        ['112.30', '2026-10-20'],
        ['90.00', '2026-10-23'],
        ['87.00', '2026-10-24'],
    ];

    foreach ($utilities as $entry) {
        rt_seed_transaction($pdo, $demoId, 'expense', $entry[0], $entry[1], 'Utilities', $utilitiesId);
    }

    // Eating out: 3 entries, 86.95.
    $eating = [
        ['11.35', '2026-10-17'],
        ['30.60', '2026-10-24'],
        ['45.00', '2026-10-25'],
    ];

    foreach ($eating as $entry) {
        rt_seed_transaction($pdo, $demoId, 'expense', $entry[0], $entry[1], 'Eating out', $eatingId);
    }

    // The salary and the uncategorised entry.
    rt_seed_transaction($pdo, $demoId, 'income', '1200.00', '2026-10-19', 'Salary', $salaryId);
    rt_seed_transaction($pdo, $demoId, 'income', '29.99', '2026-10-13', 'Freelance', null);

    // Outside October.
    rt_seed_transaction($pdo, $demoId, 'income', '100.00', '2026-08-16', 'Salary', $salaryId);
    rt_seed_transaction($pdo, $demoId, 'income', '50.00', '2026-09-15', 'Salary', $salaryId);

    // Somebody else's money.
    rt_seed_transaction($pdo, $otherId, 'expense', '999.99', '2026-10-19', 'Somebody else', $otherCategoryId);

    $server = rt_start_server($sandboxRoot);
    $base = $server['base'];

    ok('the sandbox answers', $base !== '', 'the server did not start');

    // --- B1. The guard -------------------------------------------------

    echo "\n## B1. The guard\n";

    rt_reset_cookies();
    $response = rt_http_request('GET', $base . '/reports');

    ok(
        'an unauthenticated visitor is redirected to the sign-in screen',
        $response['status'] === 302 && ($response['headers']['location'] ?? '') === '/login',
        'status=' . (string) $response['status']
    );

    ok('the guard answers with no body', $response['body'] === '');

    // --- B2. The month -------------------------------------------------

    echo "\n## B2. The month\n";

    rt_reset_cookies();
    $loginPage = rt_http_request('GET', $base . '/login');
    $token = rt_csrf_of($loginPage['body']);
    $response = rt_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $demoUser,
        'password'   => $demoPassword,
    ]);

    ok('the sign-in succeeds', $response['status'] === 302, (string) $response['status']);

    // The month report of October 2026 — the month= parameter is what
    // the year view's month rows drill into, and it makes the figures
    // independent of the day the test runs on.
    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10');
    $body = $response['body'];

    ok('the month report answers 200', $response['status'] === 200, (string) $response['status']);

    ok(
        'the period label is the month',
        str_contains($body, 'October 2026'),
        'the label is missing'
    );

    ok(
        'the status line counts the transactions of the period',
        str_contains($body, 'October 2026 · 18 transactions'),
        'the status line is missing'
    );

    // The three tiles.
    $tiles = [];

    if (preg_match_all('/tile__amount">([^<]*)</', $body, $matches) > 0) {
        $tiles = $matches[1];
    }

    ok('the report has three tiles', count($tiles) === 3, (string) count($tiles));

    ok('the income tile is the period income', ($tiles[0] ?? '') === '+1,229.99', (string) ($tiles[0] ?? ''));
    ok('the spending tile is the period spending', ($tiles[1] ?? '') === "\u{2212}847.80", (string) ($tiles[1] ?? ''));
    ok('the net tile is the period net', ($tiles[2] ?? '') === '+382.19', (string) ($tiles[2] ?? ''));

    ok(
        'the net tile is marked as positive',
        str_contains($body, 'tile--net tile--net-positive')
    );

    // The tables.
    $bodies = rt_tbodies($body);
    $timeRows = rt_rows($bodies[0] ?? '');
    $catRows  = rt_rows($bodies[1] ?? '');

    ok('the report has two tables', count($bodies) === 2, (string) count($bodies));
    ok('the over-time table has three weeks', count($timeRows) === 3, (string) count($timeRows));
    ok('the category table has five rows', count($catRows) === 5, (string) count($catRows));

    // The week buckets.
    ok(
        'the first week starts on a Monday',
        str_contains($body, 'Week of 5 October 2026'),
        'the first week is missing'
    );

    ok('the second week starts on a Monday', str_contains($body, 'Week of 12 October 2026'));
    ok('the third week starts on a Monday', str_contains($body, 'Week of 19 October 2026'));

    ok(
        'the week row names the end of the week',
        str_contains($body, '<span class="tx__desc-meta">to 11 October 2026</span>')
    );

    ok(
        'the week row links to the transactions of the week',
        str_contains($body, 'href="/transactions?from=2026-10-05&amp;to=2026-10-11"')
    );

    // The money of the over-time table.
    $timeIncome = [];
    $timeSpend = [];
    $timeNet   = [];

    if (preg_match_all('/data-label="Income" class="num[^"]*">([^<]*)</', $bodies[0] ?? '', $matches) > 0) {
        $timeIncome = $matches[1];
    }

    if (preg_match_all('/data-label="Spending" class="num[^"]*">([^<]*)</', $bodies[0] ?? '', $matches) > 0) {
        $timeSpend = $matches[1];
    }

    if (preg_match_all('/data-label="Net" class="num[^"]*">([^<]*)</', $bodies[0] ?? '', $matches) > 0) {
        $timeNet = $matches[1];
    }

    ok('the weeks carry three income cells', count($timeIncome) === 3, (string) count($timeIncome));

    ok(
        'the week incomes sum to the tile',
        abs(rt_sum($timeIncome) - 1229.99) < 0.001,
        (string) rt_sum($timeIncome)
    );

    ok(
        'the week spendings sum to the tile',
        abs(rt_sum($timeSpend) + 847.80) < 0.001,
        (string) rt_sum($timeSpend)
    );

    ok(
        'the week nets sum to the tile',
        abs(rt_sum($timeNet) - 382.19) < 0.001,
        (string) rt_sum($timeNet)
    );

    ok(
        'an empty income keeps its plus sign',
        str_contains($bodies[0] ?? '', '>+0.00<'),
        'the zero income is not +0.00'
    );

    // The category table.
    ok('the category table names Salary', str_contains($bodies[1] ?? '', 'Salary'));
    ok('the category table names Groceries', str_contains($bodies[1] ?? '', 'Groceries'));
    ok('the category table names Utilities', str_contains($bodies[1] ?? '', 'Utilities'));
    ok('the category table names Eating out', str_contains($bodies[1] ?? '', 'Eating out'));

    ok(
        'the uncategorised row is always present',
        str_contains($bodies[1] ?? '', 'Uncategorised')
    );

    ok(
        'the uncategorised row links to the uncategorised sentinel',
        str_contains($bodies[1] ?? '', 'href="/transactions?category=none"')
    );

    ok(
        'a category links by its id',
        str_contains($bodies[1] ?? '', 'href="/transactions?category=' . $salaryId . '"')
    );

    ok(
        'a category with no income shows the em dash',
        str_contains($bodies[1] ?? '', 'class="num amount amount--muted">—</td>')
    );

    ok(
        'the groceries carry their spending',
        str_contains($bodies[1] ?? '', "\u{2212}342.60")
    );

    ok(
        'the utilities carry their spending',
        str_contains($bodies[1] ?? '', "\u{2212}418.25")
    );

    ok(
        'the eating out carries its spending',
        str_contains($bodies[1] ?? '', "\u{2212}86.95")
    );

    ok(
        'the salary carries its income',
        str_contains($bodies[1] ?? '', '+1,200.00')
    );

    // The shares: ONE denominator, and they add up to 100%.
    $shares = [];

    if (preg_match_all('/data-label="Share" class="num">([0-9.]+)%/', $bodies[1] ?? '', $matches) > 0) {
        $shares = $matches[1];
    }

    ok('the category table has five shares', count($shares) === 5, (string) count($shares));

    $shareSum = 0.0;

    foreach ($shares as $share) {
        $shareSum += (float) $share;
    }

    ok(
        'the shares add up to 100%',
        abs($shareSum - 100.0) < 0.15,
        (string) $shareSum
    );

    ok('the salary carries a share of 57.8%', in_array('57.8', $shares, true), implode(', ', $shares));
    ok('the groceries carry a share of 16.5%', in_array('16.5', $shares, true), implode(', ', $shares));
    ok('the utilities carry a share of 20.1%', in_array('20.1', $shares, true), implode(', ', $shares));
    ok('the eating out carries a share of 4.2%', in_array('4.2', $shares, true), implode(', ', $shares));
    ok('the uncategorised carries a share of 1.4%', in_array('1.4', $shares, true), implode(', ', $shares));

    // The reconciliation: the tiles and the Total row are the same
    // numbers.
    $tfoot = '';

    if (preg_match('/<tfoot>(.*?)<\/tfoot>/is', $body, $matches) === 1) {
        $tfoot = $matches[1];
    }

    ok('the category table has a Total row', $tfoot !== '');

    $footCells = [];

    if (preg_match_all('/data-label="(Income|Spending|Net)"[^>]*>([^<]*)</', $tfoot, $matches, PREG_SET_ORDER) > 0) {
        $footCells = $matches;
    }

    $foot = [];

    foreach ($footCells as $cell) {
        $foot[$cell[1]] = $cell[2];
    }

    ok(
        'the Total row carries the income of the tiles',
        ($foot['Income'] ?? '') === ($tiles[0] ?? ''),
        (string) ($foot['Income'] ?? '')
    );

    ok(
        'the Total row carries the spending of the tiles',
        ($foot['Spending'] ?? '') === ($tiles[1] ?? ''),
        (string) ($foot['Spending'] ?? '')
    );

    ok(
        'the Total row carries the net of the tiles',
        ($foot['Net'] ?? '') === ($tiles[2] ?? ''),
        (string) ($foot['Net'] ?? '')
    );

    ok(
        'the Total row carries the count of the period',
        str_contains($tfoot, '>18<')
    );

    ok(
        'the Total row carries a share of 100.0%',
        str_contains($tfoot, '100.0%')
    );

    // Somebody else's money is not in the report.
    ok(
        'the report does not carry another account’s spending',
        !str_contains($body, '999.99')
    );

    ok(
        'the report does not name another account’s category',
        !str_contains($body, 'Other groceries')
    );

    // --- B3. The period selector ---------------------------------------

    echo "\n## B3. The period selector\n";

    foreach (['day', 'week', 'month', 'year', 'custom'] as $period) {
        $response = rt_http_request('GET', $base . '/reports?period=' . $period);

        ok(
            'the ' . $period . ' period answers 200',
            $response['status'] === 200,
            (string) $response['status']
        );

        $current = substr_count($response['body'], 'aria-current="true"');
        $periodCurrent = substr_count($response['body'], 'data-period="' . $period . '" aria-current="true"');
        $unitCurrent = substr_count($response['body'], 'data-unit="day" aria-current="true"');

        ok(
            'the ' . $period . ' period marks exactly one link',
            $periodCurrent === 1,
            (string) $periodCurrent
        );

        ok(
            'the ' . $period . ' period marks exactly one unit',
            $unitCurrent === 1,
            (string) $unitCurrent
        );

        ok(
            'the ' . $period . ' period marks two links in total',
            $current === 2,
            (string) $current
        );
    }

    $response = rt_http_request('GET', $base . '/reports?period=today');
    ok('today is a synonym of day', $response['status'] === 200, (string) $response['status']);
    ok(
        'today marks the day link',
        str_contains($response['body'], 'data-period="day" aria-current="true"')
    );

    $response = rt_http_request('GET', $base . '/reports?period=nonsense');
    ok('an unknown period falls back to the month', $response['status'] === 200, (string) $response['status']);
    ok(
        'the fallback marks the month link',
        str_contains($response['body'], 'data-period="month" aria-current="true"')
    );

    // --- B4. The year --------------------------------------------------

    echo "\n## B4. The year\n";

    // The month grain is exercised with a range of more than 366 days, so
    // the test does not depend on the month the calendar is in.
    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2025-01-01&to=2026-12-31');
    $body = $response['body'];

    ok('the long range answers 200', $response['status'] === 200, (string) $response['status']);

    $bodies = rt_tbodies($body);
    $yearRows = rt_rows($bodies[0] ?? '');

    ok('the long range breaks down by month', count($yearRows) === 3, (string) count($yearRows));
    ok('the long range has the Month header', str_contains($body, '<th scope="col">Month</th>'));

    ok('the long range names August 2026', str_contains($body, 'August 2026'));
    ok('the long range names September 2026', str_contains($body, 'September 2026'));
    ok('the long range names October 2026', str_contains($body, 'October 2026'));

    ok(
        'the month row drills into the month report',
        str_contains($body, 'href="/reports?period=month&amp;month=2026-10&amp;unit=day"')
    );

    ok(
        'an empty month is omitted',
        !str_contains($bodies[0] ?? '', 'January 2026')
    );

    // The year view itself: the calendar year the clock is in.
    $response = rt_http_request('GET', $base . '/reports?period=year');
    $body = $response['body'];

    ok('the year answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the year has the Month header', str_contains($body, '<th scope="col">Month</th>'));

    $bodies = rt_tbodies($body);
    $yearRows = rt_rows($bodies[0] ?? '');

    ok(
        'the year carries fewer rows than months',
        count($yearRows) < 12,
        (string) count($yearRows) . ' rows'
    );

    // --- B5. The day ---------------------------------------------------

    echo "\n## B5. The day\n";

    // Nine days: the grain is daily, and the 18th has no transaction, so
    // it must not produce a row.
    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2026-10-17&to=2026-10-25');
    $body = $response['body'];

    $bodies = rt_tbodies($body);
    $dayRows = rt_rows($bodies[0] ?? '');

    ok('the short range answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the short range breaks down by day', count($dayRows) === 8, (string) count($dayRows));
    ok('the short range has the Day header', str_contains($body, '<th scope="col">Day</th>'));

    ok(
        'the day row links to the transactions of the day',
        str_contains($body, 'href="/transactions?from=2026-10-17&amp;to=2026-10-17"')
    );

    ok(
        'the day without a transaction is omitted',
        !str_contains($bodies[0] ?? '', '18 October 2026')
    );

    // --- B6. The custom range ------------------------------------------

    echo "\n## B6. The custom range\n";

    $response = rt_http_request('GET', $base . '/reports?period=custom');
    $body = $response['body'];

    ok('the custom period answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the custom period carries the range form', str_contains($body, 'aria-label="Custom date range"'));
    ok('the custom period carries the hidden period', str_contains($body, '<input type="hidden" name="period" value="custom">'));
    ok('the custom period carries the submit button', str_contains($body, '>Apply range</button>'));

    // A range that starts in the middle of a week: the LABEL names the
    // natural week, the LINK is clipped to the period.
    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2026-08-15&to=2026-10-31');
    $body = $response['body'];

    ok(
        'the clipped week names the natural week',
        str_contains($body, 'Week of 10 August 2026'),
        'the label is missing'
    );

    ok(
        'the clipped week links to the part of the week in the period',
        str_contains($body, 'href="/transactions?from=2026-08-15&amp;to=2026-08-16"'),
        'the link is not clipped'
    );

    // --- B7. The invalid range -----------------------------------------

    echo "\n## B7. The invalid range\n";

    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2026-10-31&to=2026-10-01');
    $body = $response['body'];

    ok('the invalid range answers 400', $response['status'] === 400, (string) $response['status']);

    ok(
        'the invalid range says what is wrong',
        str_contains($body, 'The start date must be on or before the end date.')
    );

    ok('the invalid range renders no tiles', !str_contains($body, 'tile__amount'));
    ok('the invalid range renders no tables', !str_contains($body, 'data-table'));

    ok(
        'the invalid range marks the inputs',
        substr_count($body, 'aria-invalid="true"') === 2,
        (string) substr_count($body, 'aria-invalid="true"')
    );

    ok(
        'the invalid range points at the message',
        str_contains($body, 'aria-describedby="rep-range-msg"')
        && str_contains($body, 'id="rep-range-msg"')
    );

    ok(
        'the invalid range keeps the submitted values',
        str_contains($body, 'name="from" type="date" value="2026-10-31"')
        && str_contains($body, 'name="to" type="date" value="2026-10-01"')
    );

    // --- B8. The empty period ------------------------------------------

    echo "\n## B8. The empty period\n";

    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2020-01-01&to=2020-01-31');
    $body = $response['body'];

    ok('the empty period answers 200', $response['status'] === 200, (string) $response['status']);

    $tiles = [];

    if (preg_match_all('/tile__amount">([^<]*)</', $body, $matches) > 0) {
        $tiles = $matches[1];
    }

    ok('the empty period has three tiles', count($tiles) === 3, (string) count($tiles));

    foreach ($tiles as $index => $tile) {
        ok(
            'the empty tile ' . ($index + 1) . ' is a bare zero',
            $tile === '0.00',
            $tile
        );
    }

    ok(
        'the empty period marks the net as neutral',
        str_contains($body, 'tile--net tile--net-zero')
    );

    ok(
        'the empty period counts no transactions',
        str_contains($body, '1 – 31 January 2020 · 0 transactions')
    );

    ok('the empty period renders no tables', !str_contains($body, 'data-table'));
    ok('the empty period renders no Over time heading', !str_contains($body, '<h2>Over time</h2>'));

    ok(
        'the empty period explains itself',
        str_contains($body, 'No transactions in this period.')
    );

    ok(
        'the empty period names the range',
        str_contains($body, 'Nothing was recorded between 1 and 31 January 2020.')
    );

    ok(
        'the empty period offers the month',
        str_contains($body, 'href="/reports?period=month&amp;unit=day"')
    );

    // --- B9. The failed query ------------------------------------------

    echo "\n## B9. The failed query\n";

    $pdo->exec('RENAME TABLE transactions TO transactions_hidden');

    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10');
    $body = $response['body'];

    ok('the failed report answers 500', $response['status'] === 500, (string) $response['status']);

    ok(
        'the failed report says so',
        str_contains($body, 'The report could not be loaded.')
    );

    ok(
        'the failed report asks the visitor to try again',
        str_contains($body, 'Please try again.')
    );

    ok(
        'the failed report explains the missing figures',
        str_contains($body, 'The totals could not be retrieved, so no figures are shown.')
    );

    ok('the failed report renders no tiles', !str_contains($body, 'tile__amount'));
    ok('the failed report renders no tables', !str_contains($body, 'data-table'));
    ok('the failed report renders no status line', !str_contains($body, 'pagination__status'));

    ok(
        'the failed report offers a retry',
        str_contains($body, 'Try again')
    );

    $pdo->exec('RENAME TABLE transactions_hidden TO transactions');

    // --- B10. The other account ----------------------------------------

    echo "\n## B10. The other account\n";

    rt_reset_cookies();
    $loginPage = rt_http_request('GET', $base . '/login');
    $token = rt_csrf_of($loginPage['body']);
    $response = rt_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $otherUser,
        'password'   => $otherPassword,
    ]);

    ok('the other account signs in', $response['status'] === 302, (string) $response['status']);

    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10');
    $body = $response['body'];

    ok('the other account reads the report', $response['status'] === 200, (string) $response['status']);

    ok(
        'the other account sees its own spending',
        str_contains($body, "\u{2212}999.99")
    );

    ok(
        'the other account does not see the first account’s spending',
        !str_contains($body, "\u{2212}342.60")
    );

    ok(
        'the other account does not see the first account’s income',
        !str_contains($body, '+1,229.99')
    );

    ok(
        'the other account counts one transaction',
        str_contains($body, 'October 2026 · 1 transaction')
    );

    // --- B11. The average ----------------------------------------------

    echo "\n## B11. The average\n";

    // The first account again: the average is the account's own.
    rt_reset_cookies();
    $loginPage = rt_http_request('GET', $base . '/login');
    $token = rt_csrf_of($loginPage['body']);
    $response = rt_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $demoUser,
        'password'   => $demoPassword,
    ]);

    ok('the first account signs in again', $response['status'] === 302, (string) $response['status']);

    $averages = [];

    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10&unit=day');
    $body = $response['body'];

    ok('the average answers 200', $response['status'] === 200, (string) $response['status']);

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok('the average is on the three tiles', count($averages) === 3, (string) count($averages));

    ok(
        'the income average of the month is per day',
        ($averages[0] ?? '') === '+39.68 per day',
        $averages[0] ?? ''
    );

    ok(
        'the spending average of the month is per day',
        ($averages[1] ?? '') === '−27.35 per day',
        $averages[1] ?? ''
    );

    ok(
        'the net average of the month is per day',
        ($averages[2] ?? '') === '+12.33 per day',
        $averages[2] ?? ''
    );

    ok(
        'the average names its unit',
        rt_average_unit($averages[0] ?? '') === 'day',
        $averages[0] ?? ''
    );

    $income   = rt_average_value($averages[0] ?? '');
    $spending = rt_average_value($averages[1] ?? '');
    $net      = rt_average_value($averages[2] ?? '');

    ok(
        'the three averages keep the identity after the rounding',
        $income !== null && $spending !== null && $net !== null
            && abs(abs($income) - abs($spending) - abs($net)) < 0.005,
        ($averages[0] ?? '') . ' / ' . ($averages[1] ?? '') . ' / ' . ($averages[2] ?? '')
    );

    // The week is the calendar week, not the transaction week.
    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10&unit=week');
    $body = $response['body'];

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok(
        'the income average of the month is per week',
        ($averages[0] ?? '') === '+246.00 per week',
        $averages[0] ?? ''
    );

    ok(
        'the spending average of the month is per week',
        ($averages[1] ?? '') === '−169.56 per week',
        $averages[1] ?? ''
    );

    ok(
        'the net average of the month is per week',
        ($averages[2] ?? '') === '+76.44 per week',
        $averages[2] ?? ''
    );

    // The month and the year: one unit each for a single month.
    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10&unit=month');
    $body = $response['body'];

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok(
        'the income average of the month is per month',
        ($averages[0] ?? '') === '+1,229.99 per month',
        $averages[0] ?? ''
    );

    ok(
        'the spending average of the month is per month',
        ($averages[1] ?? '') === '−847.80 per month',
        $averages[1] ?? ''
    );

    ok(
        'the net average of the month is per month',
        ($averages[2] ?? '') === '+382.19 per month',
        $averages[2] ?? ''
    );

    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10&unit=year');
    $body = $response['body'];

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok(
        'the income average of the month is per year',
        ($averages[0] ?? '') === '+1,229.99 per year',
        $averages[0] ?? ''
    );

    ok(
        'the unit control marks the chosen unit',
        str_contains($body, 'data-unit="year" aria-current="true"')
    );

    ok(
        'the unit control keeps the period',
        str_contains($body, 'href="/reports?period=month&amp;month=2026-10&amp;unit=day"')
    );

    // An unknown unit falls back to the day and is never echoed.
    $response = rt_http_request('GET', $base . '/reports?period=month&month=2026-10&unit=nonsense');
    $body = $response['body'];

    ok('the unknown unit answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the unknown unit falls back to the day', str_contains($body, 'data-unit="day" aria-current="true"'));
    ok('the unknown unit is never echoed', !str_contains($body, 'nonsense'));

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok(
        'the unknown unit reads as the day',
        ($averages[0] ?? '') === '+39.68 per day',
        $averages[0] ?? ''
    );

    // The month denominator counts the DISTINCT months the range touches.
    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2026-08-01&to=2026-10-31&unit=month');
    $body = $response['body'];

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok(
        'the income average of the three months is per month',
        ($averages[0] ?? '') === '+460.00 per month',
        $averages[0] ?? ''
    );

    ok(
        'the spending average of the three months is per month',
        ($averages[1] ?? '') === '−282.60 per month',
        $averages[1] ?? ''
    );

    ok(
        'the net average of the three months is per month',
        ($averages[2] ?? '') === '+177.40 per month',
        $averages[2] ?? ''
    );

    // The year denominator counts the DISTINCT years the range touches.
    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2025-10-01&to=2026-10-31&unit=year');
    $body = $response['body'];

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok(
        'the spending average of the two years is per year',
        ($averages[1] ?? '') === '−423.90 per year',
        $averages[1] ?? ''
    );

    ok(
        'the income average of the two years is not the undivided total',
        ($averages[0] ?? '') !== '+1,379.99 per year',
        $averages[0] ?? ''
    );

    // The empty period: an average of zero, and a zero carries no sign.
    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2020-01-01&to=2020-01-31&unit=day');
    $body = $response['body'];

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok('the empty period has three averages', count($averages) === 3, (string) count($averages));

    foreach ($averages as $index => $average) {
        ok(
            'the empty average ' . ($index + 1) . ' is a bare zero',
            $average === '0.00 per day',
            $average
        );
    }

    ok(
        'the empty average carries no sign',
        !str_contains($body, '+0.00 per day') && !str_contains($body, '−0.00 per day')
    );

    // The week of the PHP twin is the week of the SQL bucket.
    $sqlWeek = $pdo->query("SELECT DATE_SUB('2026-10-01', INTERVAL WEEKDAY('2026-10-01') DAY)")->fetchColumn();

    ok(
        'the week of the helper is the week of the SQL bucket',
        (string) $sqlWeek === reportWeekStart('2026-10-01'),
        (string) $sqlWeek
    );

    // The rounding identity, on a fixture that breaks it.
    // The income is 1.00 over three days (0.33), the spending is 0.50
    // over three days (0.17) and the net is 0.50 over three days (0.17).
    // The identity says 0.33 - 0.17 = 0.16, and the screen must show 0.16.
    rt_seed_transaction($pdo, $demoId, 'income', '1.00', '2026-06-01', 'Salary', $salaryId);
    rt_seed_transaction($pdo, $demoId, 'expense', '0.50', '2026-06-02', 'Groceries', $groceriesId);

    $response = rt_http_request('GET', $base . '/reports?period=custom&from=2026-06-01&to=2026-06-03&unit=day');
    $body = $response['body'];

    if (preg_match_all('/tile__average">([^<]*)</', $body, $matches) > 0) {
        $averages = $matches[1];
    }

    ok(
        'the income average of the rounding fixture is 0.33',
        ($averages[0] ?? '') === '+0.33 per day',
        $averages[0] ?? ''
    );

    ok(
        'the spending average of the rounding fixture is 0.17',
        ($averages[1] ?? '') === '−0.17 per day',
        $averages[1] ?? ''
    );

    ok(
        'the net average of the rounding fixture is 0.16',
        ($averages[2] ?? '') === '+0.16 per day',
        $averages[2] ?? ''
    );

    ok(
        'the net average is not the rounded net total',
        !str_contains($body, '+0.17 per day'),
        'the net average was computed from the net total'
    );
}

// ---------------------------------------------------------------------------
// The teardown
// ---------------------------------------------------------------------------

echo "\n## Teardown\n";

if ($server !== null) {
    rt_stop_server($server);
    ok('the server is stopped', true);
}

// When something failed, the sandbox's server log is the shortest road to
// the cause — a 500 in the page is a fatal error in the log.
if ($failures !== [] && $sandboxRoot !== '' && is_file($sandboxRoot . '/server.log')) {
    $log = (string) file_get_contents($sandboxRoot . '/server.log');
    $lines = array_slice(array_filter(explode("\n", $log)), -40);

    if ($lines !== []) {
        echo "\nThe sandbox server log:\n";

        foreach ($lines as $line) {
            echo '  ' . $line . "\n";
        }
    }
}

if ($sandboxRoot !== '') {
    rt_remove_tree($sandboxRoot);
    ok('the sandbox is removed', !is_dir($sandboxRoot));
}

rt_drop_scratch_database($root);
ok('the scratch database is dropped', true);

echo "\nFailed: " . count($failures) . "\n";

if ($failures !== []) {
    echo "\nThe failures:\n";

    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }

    exit(1);
}

exit(0);
