<?php

/**
 * Issue 018 — the transaction list and the transaction form.
 *
 * Two tiers:
 *
 *   A. The sources   — the files exist, the guard is the first statement,
 *                      the CSRF token is validated before any input is
 *                      read, every dynamic value is escaped, the ownership
 *                      is enforced in the SQL, and the helpers behave the
 *                      way the specification says they behave.
 *
 *   B. The sandbox   — the real screens, against a scratch database:
 *                      the routes, the states, the filters, the
 *                      validation, the save, the delete, the sort and the
 *                      pagination.
 *
 * A test passes on its EXIT CODE, not on its output. The success markers
 * are not consistent across this repository, so nothing below greps for
 * one.
 *
 * Run it with:
 *
 *     docker compose exec -T app php /var/www/html/database/transactions_test.php
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// The harness
// ---------------------------------------------------------------------------

/** @var string[] */
$failures = [];

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
function tt_reset_cookies(): void
{
    $GLOBALS['tt_cookies'] = [];
}

/**
 * A tiny HTTP client: one cookie jar, a status, the headers and the body.
 *
 * @param array<string, string> $fields
 * @param string[]              $extraHeaders
 *
 * @return array{status: int, body: string, headers: array<string, string>, set_cookie: string[]}
 */
function tt_http_request(
    string $method,
    string $url,
    array $fields = [],
    array $extraHeaders = [],
    int $timeout = 30
): array {
    $headers = [
        'User-Agent: financial-tracker-transactions-test',
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

    $jar = $GLOBALS['tt_cookies'] ?? [];

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

    $status     = null;
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
            unset($GLOBALS['tt_cookies'][$name]);
            continue;
        }

        $GLOBALS['tt_cookies'][$name] = $value;
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
function tt_csrf_of(string $body): string
{
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * The rows of the table, without the rest of the page.
 *
 * The filter bar carries the category names too, so an assertion about
 * "the list" has to look at the table and not at the whole document.
 */
function tt_tbody(string $body): string
{
    if (preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $body, $match) === 1) {
        return $match[1];
    }

    return '';
}

/**
 * The position of a needle in the body, or -1.
 */
function tt_pos_of(string $haystack, string $needle): int
{
    $pos = strpos($haystack, $needle);

    return $pos === false ? -1 : $pos;
}

/**
 * The label a period span carries: "19 – 25 October 2026".
 */
function tt_expected_span(string $from, string $to): string
{
    $fromTs = strtotime($from . ' 00:00:00');
    $toTs   = strtotime($to . ' 00:00:00');

    if ($fromTs === false || $toTs === false) {
        return '';
    }

    if (date('Y-m', $fromTs) === date('Y-m', $toTs)) {
        return date('j', $fromTs) . ' – ' . date('j', $toTs) . ' ' . date('F Y', $fromTs);
    }

    return date('j F Y', $fromTs) . ' – ' . date('j F Y', $toTs);
}

/**
 * Strip the comments out of a source file, so an assertion about code
 * does not trip over a comment that mentions the same word.
 */
function tt_strip_comments(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#//[^\n]*#', '', $src);

    return (string) $src;
}

/**
 * The two branches of a ternary, or null when the expression is not one.
 *
 * @return array{0: string, 1: string}|null
 */
function tt_split_ternary(string $expression): ?array
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
function tt_is_literal_expression(string $expression): bool
{
    return preg_match("/^'[^']*'$/", trim($expression)) === 1
        || preg_match('/^"[^"]*"$/', trim($expression)) === 1;
}

/**
 * Every short echo in a source that prints something which is NOT escaped.
 *
 * A short echo is allowed when it calls e(), when it prints a literal, or
 * when it is a ternary whose two branches are both literals.
 *
 * @return string[]
 */
function tt_unescaped_outputs(string $src): array
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

        if (tt_is_literal_expression($expression)) {
            continue;
        }

        $ternary = tt_split_ternary($expression);

        if ($ternary !== null
            && tt_is_literal_expression($ternary[0])
            && tt_is_literal_expression($ternary[1])) {
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
function tt_env_values(string $root): array
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

        $values[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1), " \t\"'");
    }

    return $values;
}

/**
 * A free TCP port on the loopback interface.
 */
function tt_free_port(): int
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
function tt_start_server(string $docroot): array
{
    $port = tt_free_port();

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
        $res = tt_http_request('GET', $base . '/__ping__', [], [], 2);

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
function tt_stop_server(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc'], 15);
        proc_close($server['proc']);
    }
}

/**
 * Remove a directory tree.
 */
function tt_remove_tree(string $dir): void
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
            tt_remove_tree($path);
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
function tt_make_sandbox(string $root, array $db): string
{
    $dir = sys_get_temp_dir() . '/ft_tx_' . bin2hex(random_bytes(5));

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

    // The error pages live in includes/views — the front controller
    // renders them for a 403 and a 404.
    mkdir($dir . '/includes/views', 0700, true);

    foreach (glob($root . '/includes/views/*.php') ?: [] as $file) {
        copy($file, $dir . '/includes/views/' . basename($file));
    }

    // The assets and the vendor tree are read-only for the sandbox.
    @symlink($root . '/assets', $dir . '/assets');
    @symlink($root . '/vendor', $dir . '/vendor');

    // The configuration of the sandbox.
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

    // The router: every path that is not a real file goes to the front
    // controller.
    file_put_contents($dir . '/router.php', <<<'ROUTER'
<?php
$_SERVER['SERVER_NAME'] = 'tx.test';

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
function tt_create_scratch_database(string $root): ?array
{
    $env  = tt_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return null;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_tx_test`');
        $pdo->exec('CREATE DATABASE `ft_tx_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `ft_tx_test`');

        $schema = (string) file_get_contents($root . '/database/schema.sql');
        $pdo->exec($schema);
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be prepared: " . $ex->getMessage() . "\n";

        return null;
    }

    return [
        'host' => $host,
        'name' => 'ft_tx_test',
        'user' => 'root',
        'pass' => $pass,
        'pdo'  => $pdo,
    ];
}

/**
 * Drop the scratch database.
 */
function tt_drop_scratch_database(string $root): void
{
    $env  = tt_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_tx_test`');
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be dropped: " . $ex->getMessage() . "\n";
    }
}

/**
 * Seed an account and return its id.
 */
function tt_seed_user(PDO $pdo, string $username, string $email, string $password): int
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
function tt_seed_category(PDO $pdo, int $userId, string $name, string $type): int
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
function tt_seed_transaction(
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
 * The sum of a user's transactions, straight from the database.
 */
function tt_sum_amount(PDO $pdo, int $userId, string $from, string $to, string $type = ''): string
{
    $sql = 'SELECT COALESCE(SUM(amount), 0) FROM transactions
            WHERE user_id = ? AND occurred_on BETWEEN ? AND ?';
    $params = [$userId, $from, $to];

    if ($type !== '') {
        $sql .= ' AND type = ?';
        $params[] = $type;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (string) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// The fixtures
// ---------------------------------------------------------------------------

$root = dirname(__DIR__);
$sandboxRoot = '';

$today       = gmdate('Y-m-d');
$monthStart  = gmdate('Y-m-01');
$monthEnd    = gmdate('Y-m-t');
$outsideDate = gmdate('Y-m-d', strtotime('-40 days'));

$demoUser     = 'demo';
$demoPassword = 'CorrectHorseBattery9!';

$otherUser     = 'other';
$otherPassword = 'CorrectHorseBattery9!';

echo "Issue 018 — the transaction list and the transaction form\n";

// ---------------------------------------------------------------------------
// A. The file tier
// ---------------------------------------------------------------------------

echo "\n## A. The sources\n";

// --- A1. The files exist ---

$files = [
    'includes/functions.php',
    'views/transactions.php',
    'views/transaction_form.php',
    'views/transaction_save.php',
    'views/transaction_delete.php',
    'assets/css/pages/transactions.css',
    'assets/js/app.js',
];

foreach ($files as $file) {
    ok('the file ' . $file . ' exists', is_file($root . '/' . $file));
}

$src = [];

foreach ($files as $file) {
    $source = @file_get_contents($root . '/' . $file);
    $src[$file] = $source === false ? '' : $source;
}

// --- A2. The guard is the first statement of every view ---

foreach (['views/transactions.php', 'views/transaction_form.php', 'views/transaction_save.php', 'views/transaction_delete.php'] as $file) {
    $code = tt_strip_comments($src[$file]);
    $guardAt = strpos($code, 'requireLogin()');
    $queryAt = strpos($code, "\$_GET");
    $postAt  = strpos($code, "\$_POST");

    ok(
        $file . ' calls requireLogin() before it reads any input',
        $guardAt !== false
            && ($queryAt === false || $guardAt < $queryAt)
            && ($postAt === false || $guardAt < $postAt),
        'guard at ' . ($guardAt === false ? 'none' : (string) $guardAt)
    );
}

// --- A3. The CSRF token is validated before any input is read ---

foreach (['views/transaction_save.php', 'views/transaction_delete.php'] as $file) {
    $code = tt_strip_comments($src[$file]);
    $csrfAt = strpos($code, 'validateCsrfToken');

    // The first read of a posted FIELD. The token itself may be read
    // before the validation — that is the point of the validation.
    $fieldReads = [];

    foreach (["post('", "\$_POST['id']", "\$_POST['type']", "\$_POST['amount']", "\$_POST['date']"] as $needle) {
        $at = strpos($code, $needle);

        if ($at !== false) {
            $fieldReads[] = $at;
        }
    }

    $firstFieldRead = $fieldReads === [] ? PHP_INT_MAX : min($fieldReads);

    ok(
        $file . ' validates the CSRF token before it reads the posted fields',
        $csrfAt !== false && $csrfAt < $firstFieldRead,
        'the token is checked at ' . ($csrfAt === false ? 'none' : (string) $csrfAt)
            . ', the first field is read at ' . (string) $firstFieldRead
    );
}

// --- A4. Every dynamic output is escaped ---

foreach (['views/transactions.php', 'views/transaction_form.php', 'views/transaction_save.php', 'views/transaction_delete.php'] as $file) {
    $bad = tt_unescaped_outputs($src[$file]);

    ok(
        $file . ' escapes every dynamic value it prints',
        $bad === [],
        implode(' | ', array_slice($bad, 0, 4))
    );
}

// --- A5. The ownership is enforced in the SQL ---

ok(
    'the transaction list filters on the user id in SQL',
    str_contains($src['views/transactions.php'], 't.user_id = ?')
);

ok(
    'the save handler updates with the user id in the WHERE clause',
    preg_match(
        '/UPDATE\s+transactions\s+SET.*WHERE\s+id\s*=\s*\?\s+AND\s+user_id\s*=\s*\?/is',
        $src['views/transaction_save.php']
    ) === 1
);

ok(
    'the delete handler deletes with the user id in the WHERE clause',
    preg_match(
        '/DELETE\s+FROM\s+transactions\s+WHERE\s+id\s*=\s*\?\s+AND\s+user_id\s*=\s*\?/is',
        $src['views/transaction_delete.php']
    ) === 1
);

ok(
    'the delete handler reads the row before it deletes it',
    str_contains($src['views/transaction_delete.php'], 'SELECT')
);

ok(
    'the form reads the row it is editing with the user id in the WHERE clause',
    preg_match(
        '/FROM\s+transactions\s+.*WHERE\s+t\.id\s*=\s*\?\s+AND\s+t\.user_id\s*=\s*\?/is',
        $src['views/transaction_form.php']
    ) === 1
);

// --- A6. The helpers ---

require_once $root . '/includes/functions.php';

ok('escapeLike() escapes the LIKE wildcards', escapeLike('50%_x\\') === '50\\%\\_x\\\\');
ok('escapeLike() leaves an ordinary string alone', escapeLike('groceries') === 'groceries');
ok('formatLongDate() uses the long month name', formatLongDate('2026-10-19') === '19 October 2026');
ok('formatLongDate() returns an empty string for no date', formatLongDate(null) === '');

$sort = normalizeTransactionSort('date', 'asc');
ok('normalizeTransactionSort() accepts the date column', $sort['key'] === 'date', json_encode($sort));
ok('normalizeTransactionSort() accepts the direction', $sort['dir'] === 'asc', json_encode($sort));
ok('normalizeTransactionSort() orders by the column and the id', $sort['order'] === 't.occurred_on ASC, t.id ASC', (string) $sort['order']);

$sort = normalizeTransactionSort('amount', 'desc');
ok('normalizeTransactionSort() accepts the amount column', $sort['key'] === 'amount', json_encode($sort));

$sort = normalizeTransactionSort('nonsense', 'sideways');
ok('normalizeTransactionSort() falls back to the date column', $sort['key'] === 'date', json_encode($sort));
ok('normalizeTransactionSort() falls back to the descending direction', $sort['dir'] === 'desc', json_encode($sort));

$sort = normalizeTransactionSort('amount', 'nonsense');
ok('normalizeTransactionSort() falls back to the ascending direction', $sort['dir'] === 'asc', json_encode($sort));

$filters = parseTransactionFilters([]);
ok('parseTransactionFilters() reads the search term', $filters['q'] === '');
ok('parseTransactionFilters() defaults the type to every type', $filters['type'] === '');
ok('parseTransactionFilters() defaults the category to every category', $filters['category'] === '');
ok('parseTransactionFilters() defaults the range to the current month', $filters['from'] === $monthStart, (string) $filters['from']);
ok('parseTransactionFilters() defaults the end to the end of the month', $filters['to'] === $monthEnd, (string) $filters['to']);

$filters = parseTransactionFilters(['q' => 'coffee', 'type' => 'expense', 'category' => '7', 'from' => '2026-01-01', 'to' => '2026-01-31']);
ok('parseTransactionFilters() keeps a valid type', $filters['type'] === 'expense');
ok('parseTransactionFilters() keeps a valid category', $filters['category'] === '7');
ok('parseTransactionFilters() keeps a valid range', $filters['from'] === '2026-01-01' && $filters['to'] === '2026-01-31');

$filters = parseTransactionFilters(['type' => 'nonsense', 'category' => '0', 'from' => 'nonsense', 'to' => 'nonsense']);
ok('parseTransactionFilters() drops an unknown type', $filters['type'] === '');
ok('parseTransactionFilters() drops a category that is not a positive id', $filters['category'] === '');
ok('parseTransactionFilters() falls back to the current month for an impossible range', $filters['from'] === $monthStart, (string) $filters['from']);

$filters = parseTransactionFilters(['from' => '', 'to' => '']);
ok('parseTransactionFilters() reads an empty range as unbounded', $filters['from'] === '' && $filters['to'] === '', json_encode($filters));

$filters = parseTransactionFilters(['q' => str_repeat('a', 400)]);
ok('parseTransactionFilters() truncates the search term at 255 characters', mb_strlen($filters['q']) === 255, (string) mb_strlen($filters['q']));

$predicate = buildTransactionPredicate(42, [
    'q' => 'coffee',
    'type' => 'expense',
    'category' => '3',
    'from' => '2026-01-01',
    'to' => '2026-01-31',
]);
ok('buildTransactionPredicate() filters on the user id', str_contains($predicate['where'], 't.user_id = ?'), $predicate['where']);
ok('buildTransactionPredicate() escapes the search term', str_contains($predicate['where'], "LIKE ? ESCAPE"), $predicate['where']);
ok('buildTransactionPredicate() filters on the type', str_contains($predicate['where'], 't.type = ?'), $predicate['where']);
ok('buildTransactionPredicate() filters on the category', str_contains($predicate['where'], 't.category_id = ?'), $predicate['where']);
ok('buildTransactionPredicate() filters on the range', str_contains($predicate['where'], 't.occurred_on >= ?') && str_contains($predicate['where'], 't.occurred_on <= ?'), $predicate['where']);
ok('buildTransactionPredicate() counts one placeholder per parameter', substr_count($predicate['where'], '?') === count($predicate['params']), $predicate['where'] . ' / ' . json_encode($predicate['params']));

$predicate = buildTransactionPredicate(42, ['q' => '', 'type' => '', 'category' => 'none', 'from' => '', 'to' => '']);
ok('buildTransactionPredicate() reads "none" as an uncategorised filter', str_contains($predicate['where'], 't.category_id IS NULL'), $predicate['where']);
ok('buildTransactionPredicate() leaves the range open when it is unbounded', !str_contains($predicate['where'], 'occurred_on >='), $predicate['where']);

ok('normalizeAmountString() gives an amount two decimals', normalizeAmountString('42.8') === '42.80');
ok('normalizeAmountString() keeps a whole amount whole', normalizeAmountString('42') === '42.00');
ok('normalizeAmountString() truncates a third decimal', normalizeAmountString('42.806') === '42.80');
ok('normalizeAmountString() leaves an empty amount empty', normalizeAmountString('') === '');
ok('isPositiveAmount() rejects zero', isPositiveAmount('0') === false);
ok('isPositiveAmount() rejects zero with decimals', isPositiveAmount('0.00') === false);
ok('isPositiveAmount() accepts a cent', isPositiveAmount('0.01') === true);
ok('isPositiveAmount() rejects an empty amount', isPositiveAmount('') === false);

$result = validateTransactionInput([
    'type' => 'income',
    'amount' => '10.5',
    'date' => '2026-10-19',
    'category' => '',
    'description' => 'Salary',
], []);
ok('validateTransactionInput() accepts a valid transaction', $result['errors'] === [], json_encode($result['errors']));
ok('validateTransactionInput() normalises the amount', $result['values']['amount'] === '10.50', (string) $result['values']['amount']);

$result = validateTransactionInput([
    'type' => 'nonsense',
    'amount' => '0',
    'date' => '2026-02-30',
    'category' => '9',
    'description' => str_repeat('x', 256),
], ['1', '2']);
ok('validateTransactionInput() collects every error at once', count($result['errors']) === 5, json_encode($result['errors']));
ok('validateTransactionInput() rejects an unknown type', ($result['errors']['type'] ?? '') === 'Choose either income or spending.', (string) ($result['errors']['type'] ?? ''));
ok('validateTransactionInput() rejects an amount that is not positive', ($result['errors']['amount'] ?? '') === 'The amount must be greater than zero.', (string) ($result['errors']['amount'] ?? ''));
ok('validateTransactionInput() rejects an impossible date', ($result['errors']['date'] ?? '') === 'Enter a valid date.', (string) ($result['errors']['date'] ?? ''));
ok('validateTransactionInput() rejects a category the visitor does not own', ($result['errors']['category'] ?? '') === 'That category is not available.', (string) ($result['errors']['category'] ?? ''));
ok('validateTransactionInput() rejects a long description', ($result['errors']['description'] ?? '') === 'The description must be at most 255 characters long.', (string) ($result['errors']['description'] ?? ''));
ok('validateTransactionInput() keeps the values it rejected', $result['values']['date'] === '2026-02-30', (string) $result['values']['date']);
ok('validateTransactionInput() does not truncate a long description', mb_strlen($result['values']['description']) === 256, (string) mb_strlen($result['values']['description']));
ok('validateTransactionInput() falls back to the default type', $result['values']['type'] === 'income', (string) $result['values']['type']);

ok('transactionListUrl() builds a query string', str_contains(transactionListUrl(['q' => 'coffee']), 'q=coffee'));
ok('transactionListUrl() leaves the empty values out', !str_contains(transactionListUrl(['q' => '', 'type' => '']), 'q='));
ok('transactionListUrl() leaves the page out when it is the first one', !str_contains(transactionListUrl(['q' => ''], null, null, 1), 'page='));
ok('transactionListUrl() keeps the page when it is not the first one', str_contains(transactionListUrl(['q' => ''], null, null, 3), 'page=3'));

// --- A7. The page size lives in one place ---

ok('the page size is 25', defined('TRANSACTIONS_PER_PAGE') && TRANSACTIONS_PER_PAGE === 25);
ok(
    'the page size is defined once',
    substr_count($src['includes/functions.php'], "define('TRANSACTIONS_PER_PAGE'") === 1
);
ok('the list uses the constant', str_contains($src['views/transactions.php'], 'TRANSACTIONS_PER_PAGE'));

// --- A8. The forms ---

ok(
    'the filter form is a GET form',
    preg_match('/<form[^>]*method="get"/i', $src['views/transactions.php']) === 1
);

$filterForm = '';
if (preg_match('/<form[^>]*method="get".*?<\/form>/is', $src['views/transactions.php'], $match) === 1) {
    $filterForm = $match[0];
}

ok('the filter form is found', $filterForm !== '');
ok('the filter form carries no CSRF token', !str_contains($filterForm, 'csrf_token'));

$formCode = tt_strip_comments($src['views/transaction_form.php']);
ok(
    'the transaction form carries the CSRF hidden input',
    str_contains($formCode, 'name="csrf_token"')
);
ok('the transaction form has no required attribute', !preg_match('/\brequired\b/i', $formCode));
ok('the transaction form names the five fields', str_contains($formCode, 'name="type"')
    && str_contains($formCode, 'name="amount"')
    && str_contains($formCode, 'name="date"')
    && str_contains($formCode, 'name="category"')
    && str_contains($formCode, 'name="description"'));

// --- A9. The typography ---

$listBody = $src['views/transactions.php'] . $src['views/transaction_form.php'];
ok('the money minus sign is the typographic one', str_contains($listBody, "\u{2212}"), 'the U+2212 glyph is missing');
ok('the money minus sign is the literal character', !str_contains($listBody, '\u{2212}'), 'the escape sequence was used instead of the glyph');

// --- A10. The stylesheet ---

$css = $src['assets/css/pages/transactions.css'];
ok('the stylesheet has no literal colour', preg_match('/#[0-9a-fA-F]{3,8}\b/', $css) !== 1);
ok('the stylesheet has no rgb() or hsl() colour', preg_match('/\b(rgb|rgba|hsl|hsla)\(/i', $css) !== 1);
ok('the stylesheet uses the tokens', str_contains($css, 'var(--'));

$cssClasses = [];
if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $css, $matches) === 1 || !empty($matches[1])) {
    foreach ($matches[1] as $class) {
        $cssClasses[] = $class;
    }
}
ok('the stylesheet names some classes', $cssClasses !== []);

// --- A11. The script ---

$js = $src['assets/js/app.js'];
ok('the script computes the preset range', str_contains($js, 'presetRange'));
ok('the script reads the preset attributes', str_contains($js, 'data-preset-from') && str_contains($js, 'data-preset-to'));
ok('the script writes the range into the form', str_contains($js, '[name="from"]') && str_contains($js, '[name="to"]'));
ok('the script hides the modals it can open', str_contains($js, 'data-modal-open'));

// ---------------------------------------------------------------------------
// B. The behaviour tier
// ---------------------------------------------------------------------------

echo "\n## B. The sandbox\n";

$server = null;
$db     = null;

try {
    $db = tt_create_scratch_database($root);
} catch (Throwable $ex) {
    $db = null;
}

if ($db === null) {
    ok('the scratch database is created', false, 'the database server did not answer');
} else {
    ok('the scratch database is created', true);

    $sandboxRoot = tt_make_sandbox($root, $db);
    $pdo = $db['pdo'];

    $demoUserId  = tt_seed_user($pdo, $demoUser, $demoUser . '@example.test', $demoPassword);
    $otherUserId = tt_seed_user($pdo, $otherUser, $otherUser . '@example.test', $otherPassword);

    $groceriesId = tt_seed_category($pdo, $demoUserId, 'Groceries', 'expense');
    $salaryId    = tt_seed_category($pdo, $demoUserId, 'Salary', 'income');

    $otherCategoryId = tt_seed_category($pdo, $otherUserId, 'Other groceries', 'expense');

    $salaryTx = tt_seed_transaction($pdo, $demoUserId, 'income', '1200.00', $today, 'Salary', $salaryId);
    $foodTx   = tt_seed_transaction($pdo, $demoUserId, 'expense', '42.80', $today, 'Groceries', $groceriesId);
    $coffeeTx = tt_seed_transaction($pdo, $demoUserId, 'expense', '13.37', $monthStart, 'Coffee', null);
    $olderTx  = tt_seed_transaction($pdo, $demoUserId, 'expense', '5.00', $outsideDate, 'Older entry', $groceriesId);
    $otherTx  = tt_seed_transaction($pdo, $otherUserId, 'expense', '99.00', $today, 'Someone else', $otherCategoryId);

    $server = tt_start_server($sandboxRoot);
    $base = $server['base'];

    // --- B1. The guard ---

    tt_reset_cookies();
    $response = tt_http_request('GET', $base . '/transactions');
    ok('a guest is sent away from the transaction list', $response['status'] === 302, (string) $response['status']);

    tt_reset_cookies();
    $response = tt_http_request('GET', $base . '/transactions/new');
    ok('a guest is sent away from the transaction form', $response['status'] === 302, (string) $response['status']);

    tt_reset_cookies();
    $response = tt_http_request('POST', $base . '/transactions/save', ['type' => 'income']);
    ok('a guest cannot save a transaction', $response['status'] === 302, (string) $response['status']);

    // --- B2. The list ---

    tt_reset_cookies();
    $loginPage = tt_http_request('GET', $base . '/login');
    $token = tt_csrf_of($loginPage['body']);
    $response = tt_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $demoUser,
        'password'   => $demoPassword,
    ]);
    ok('the sign-in succeeds', $response['status'] === 302, (string) $response['status']);

    $response = tt_http_request('GET', $base . '/transactions');
    $body = $response['body'];

    ok('the transaction list answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the list has the heading', str_contains($body, 'Transactions'));
    ok('the list has the filter bar', str_contains($body, 'id="tx-search"'));
    ok('the list has the type filter', str_contains($body, 'id="tx-type"'));
    ok('the list has the category filter', str_contains($body, 'id="tx-category"'));
    ok('the list has the date filters', str_contains($body, 'id="tx-from"') && str_contains($body, 'id="tx-to"'));
    ok('the list has the period presets', str_contains($body, 'data-preset="month"')
        && str_contains($body, 'data-preset="week"')
        && str_contains($body, 'data-preset="year"'));
    ok('the list offers the other periods', str_contains($body, '/transactions?from=') || str_contains($body, 'data-preset'));

    ok('the list shows the income tile', str_contains($body, '1,200.00'), 'the income total is missing');
    ok('the list shows the spending tile', str_contains($body, '56.17'), 'the spending total is missing');
    ok('the list shows the net tile', str_contains($body, '1,143.83'), 'the net total is missing');
    ok('the money minus sign is the typographic one', str_contains($body, "\u{2212}"), 'the U+2212 glyph is missing');

    ok('the status line counts the transactions', str_contains($body, '3 transactions'), 'the count is missing');
    ok(
        'the status line carries the period label',
        str_contains($body, tt_expected_span($monthStart, $monthEnd)),
        tt_expected_span($monthStart, $monthEnd)
    );

    ok('the table carries the caption', str_contains($body, 'Transactions from ' . formatLongDate($monthStart) . ' to ' . formatLongDate($monthEnd)));
    ok('the table shows the salary', str_contains(tt_tbody($body), 'Salary'));
    ok('the table shows the groceries', str_contains(tt_tbody($body), 'Groceries'));
    ok('the table shows the uncategorised entry', str_contains(tt_tbody($body), 'Uncategorised'));
    ok('the table hides the entry outside the month', !str_contains(tt_tbody($body), 'Older entry'));
    ok('the table hides the other visitor’s entry', !str_contains(tt_tbody($body), 'Someone else'));

    $cells = [];
    if (preg_match_all('/<td[^>]*>/', $body, $matches) === 1 || !empty($matches[0])) {
        $cells = $matches[0];
    }
    ok('the table has cells', $cells !== []);

    $withoutLabel = 0;
    foreach ($cells as $cell) {
        if (!str_contains($cell, 'data-label=')) {
            $withoutLabel++;
        }
    }
    ok('every cell carries a data-label', $withoutLabel === 0, (string) $withoutLabel . ' cells without a label');

    ok('the pagination status names the range', str_contains($body, 'Showing 1–' . '3 of 3 transactions'), 'the pagination status is wrong');
    ok('the list offers the add button', str_contains($body, '/transactions/new'));

    // --- B3. The filters ---

    $response = tt_http_request('GET', $base . '/transactions?type=income');
    $body = $response['body'];
    $rows = tt_tbody($body);
    ok('the type filter narrows the list', str_contains($rows, 'Salary') && !str_contains($rows, 'Groceries'));
    ok('the type filter changes the totals', str_contains($body, '1,200.00') && str_contains($body, '0.00'));
    ok('the type filter counts one transaction', str_contains($body, '1 transaction'), 'the count is wrong');

    $response = tt_http_request('GET', $base . '/transactions?q=groceries');
    $body = $response['body'];
    $rows = tt_tbody($body);
    ok('the search narrows the list', str_contains($rows, 'Groceries') && !str_contains($rows, 'Salary'));
    ok('the search changes the totals', str_contains($body, '42.80'));

    $response = tt_http_request('GET', $base . '/transactions?q=%25');
    $body = $response['body'];
    ok('a percent sign in the search is not a wildcard', !str_contains(tt_tbody($body), 'Groceries'), 'the search returned rows');
    ok('a percent sign in the search matches nothing', str_contains($body, 'No transactions match these filters.'));

    $response = tt_http_request('GET', $base . '/transactions?category=none');
    $body = $response['body'];
    $rows = tt_tbody($body);
    ok('the uncategorised filter narrows the list', str_contains($rows, 'Coffee') && !str_contains($rows, 'Groceries'));

    $response = tt_http_request('GET', $base . '/transactions?category=' . $groceriesId);
    $body = $response['body'];
    $rows = tt_tbody($body);
    ok('the category filter narrows the list', str_contains($rows, 'Groceries') && !str_contains($rows, 'Coffee'));

    $response = tt_http_request('GET', $base . '/transactions?from=&to=');
    $body = $response['body'];
    ok('an empty range means all time', str_contains($body, '4 transactions'), 'the count is wrong');
    ok('an empty range shows the older entry', str_contains(tt_tbody($body), 'Older entry'));
    ok('an empty range says so', str_contains($body, 'all time'), 'the range label is missing');

    // The totals are cross-checked against the database.
    $response = tt_http_request('GET', $base . '/transactions?type=expense');
    $body = $response['body'];
    $expected = tt_sum_amount($pdo, $demoUserId, $monthStart, $monthEnd, 'expense');
    ok(
        'the spending tile agrees with the database',
        str_contains($body, number_format((float) $expected, 2, '.', ',')),
        'the database says ' . $expected
    );

    // --- B4. The states ---

    $response = tt_http_request('GET', $base . '/transactions?q=nothingmatchesthis');
    $body = $response['body'];
    ok('the filtered state says so', str_contains($body, 'No transactions match these filters.'));
    ok('the filtered state keeps the filter bar', str_contains($body, 'id="tx-search"'));
    ok('the filtered state has no tiles', !str_contains($body, '1,200.00'));

    // --- B5. The form ---

    $response = tt_http_request('GET', $base . '/transactions/new');
    $body = $response['body'];
    ok('the add form answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the add form has the heading', str_contains($body, 'Add transaction'));
    ok('the add form has the type radios', str_contains($body, 'id="tf-in"') && str_contains($body, 'id="tf-out"'));
    ok('the add form has the amount field', str_contains($body, 'id="tf-amount"'));
    ok('the add form has the date field', str_contains($body, 'id="tf-date"'));
    ok('the add form has the category field', str_contains($body, 'id="tf-cat"'));
    ok('the add form has the description field', str_contains($body, 'id="tf-desc"'));
    ok('the add form carries the CSRF token', tt_csrf_of($body) !== '');
    ok('the add form has no danger zone', !str_contains($body, 'transactions__danger'));
    ok('the add form has no hidden id', !str_contains($body, 'name="id"'));

    $response = tt_http_request('GET', $base . '/transactions/edit?id=' . $salaryTx);
    $body = $response['body'];
    ok('the edit form answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the edit form has the heading', str_contains($body, 'Edit transaction'));
    ok('the edit form carries the amount', str_contains($body, '1200.00'));
    ok('the edit form carries the description', str_contains($body, 'Salary'));
    ok('the edit form carries the hidden id', str_contains($body, 'name="id"'));
    ok('the edit form has the danger zone', str_contains($body, 'transactions__danger'));

    $response = tt_http_request('GET', $base . '/transactions/edit?id=' . $otherTx);
    ok('another visitor’s transaction is a 404', $response['status'] === 404, (string) $response['status']);

    $response = tt_http_request('GET', $base . '/transactions/edit?id=abc');
    ok('an impossible id is a 404', $response['status'] === 404, (string) $response['status']);

    $response = tt_http_request('GET', $base . '/transactions/edit?id=0');
    ok('a zero id is a 404', $response['status'] === 404, (string) $response['status']);

    // --- B6. The validation ---

    $form = tt_http_request('GET', $base . '/transactions/new');
    $token = tt_csrf_of($form['body']);

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'type'        => 'income',
        'amount'      => '10',
        'date'        => '2026-02-30',
        'category'    => '',
        'description' => 'Impossible date',
    ]);
    $body = $response['body'];
    ok('an impossible date is rejected', str_contains($body, 'Enter a valid date.'), (string) $response['status']);
    ok('the rejected form keeps the amount', str_contains($body, '10'));
    ok('the rejected form keeps the description', str_contains($body, 'Impossible date'));
    ok('the rejected form is not a redirect', $response['status'] === 200, (string) $response['status']);

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'type'        => 'income',
        'amount'      => '0',
        'date'        => '2026-10-19',
        'category'    => '',
        'description' => 'Zero',
    ]);
    ok('a zero amount is rejected', str_contains($response['body'], 'The amount must be greater than zero.'));

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'type'        => 'income',
        'amount'      => '10',
        'date'        => '2026-10-19',
        'category'    => '',
        'description' => str_repeat('x', 300),
    ]);
    $body = $response['body'];
    ok('a long description is rejected', str_contains($body, 'The description must be at most 255 characters long.'));
    ok('the long description is not truncated', str_contains($body, str_repeat('x', 300)), 'the description was cut short');

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'type'        => 'nonsense',
        'amount'      => '10',
        'date'        => '2026-10-19',
        'category'    => '',
        'description' => 'Bad type',
    ]);
    ok('an unknown type is rejected', str_contains($response['body'], 'Choose either income or spending.'));

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'type'        => 'income',
        'amount'      => '10',
        'date'        => '2026-10-19',
        'category'    => (string) $otherCategoryId,
        'description' => 'Other category',
    ]);
    ok('another visitor’s category is rejected', str_contains($response['body'], 'That category is not available.'));

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'type'        => 'nonsense',
        'amount'      => '0',
        'date'        => '2026-02-30',
        'category'    => (string) $otherCategoryId,
        'description' => str_repeat('x', 300),
    ]);
    $body = $response['body'];
    ok('every error is reported at once', str_contains($body, 'Choose either income or spending.')
        && str_contains($body, 'The amount must be greater than zero.')
        && str_contains($body, 'Enter a valid date.')
        && str_contains($body, 'That category is not available.')
        && str_contains($body, 'The description must be at most 255 characters long.'));

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'type'   => 'income',
        'amount' => '10',
        'date'   => '2026-10-19',
    ]);
    ok('a save without the CSRF token is refused', $response['status'] === 403, (string) $response['status']);

    // --- B7. The save ---

    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'type'        => 'income',
        'amount'      => '42.8',
        'date'        => '2026-10-19',
        'category'    => (string) $salaryId,
        'description' => 'Saved by the test',
    ]);
    ok('a valid save redirects', $response['status'] === 303, (string) $response['status']);
    ok('the redirect points at the list', ($response['headers']['location'] ?? '') === '/transactions', (string) ($response['headers']['location'] ?? ''));
    ok('the save page confirms', str_contains($response['body'], 'Transaction saved.'));

    $statement = $pdo->prepare(
        'SELECT amount, occurred_on, description, category_id FROM transactions
         WHERE user_id = ? AND description = ?'
    );
    $statement->execute([$demoUserId, 'Saved by the test']);
    $saved = $statement->fetch(PDO::FETCH_ASSOC);

    ok('the row is in the database', is_array($saved));
    ok('the amount is stored with two decimals', ($saved['amount'] ?? '') === '42.80', (string) ($saved['amount'] ?? ''));
    ok('the date is stored', ($saved['occurred_on'] ?? '') === '2026-10-19', (string) ($saved['occurred_on'] ?? ''));
    ok('the category is stored', (int) ($saved['category_id'] ?? 0) === $salaryId);

    // An update.
    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token'  => $token,
        'id'          => (string) $salaryTx,
        'type'        => 'expense',
        'amount'      => '7.25',
        'date'        => '2026-10-20',
        'category'    => '',
        'description' => 'Updated by the test',
    ]);
    ok('a valid update redirects', $response['status'] === 303, (string) $response['status']);

    $statement = $pdo->prepare('SELECT type, amount, description, category_id FROM transactions WHERE id = ?');
    $statement->execute([$salaryTx]);
    $updated = $statement->fetch(PDO::FETCH_ASSOC);

    ok('the update changed the type', ($updated['type'] ?? '') === 'expense', (string) ($updated['type'] ?? ''));
    ok('the update changed the amount', ($updated['amount'] ?? '') === '7.25', (string) ($updated['amount'] ?? ''));
    ok('the update changed the description', ($updated['description'] ?? '') === 'Updated by the test');
    ok(
        'the update cleared the category',
        array_key_exists('category_id', $updated) && $updated['category_id'] === null,
        var_export($updated['category_id'] ?? null, true)
    );

    $before = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    $response = tt_http_request('POST', $base . '/transactions/save', [
        'csrf_token' => $token,
        'id'         => (string) $otherTx,
        'type'       => 'income',
        'amount'     => '10',
        'date'       => '2026-10-19',
    ]);
    $after = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    ok('another visitor’s transaction cannot be updated', $before === $after, $before . ' -> ' . $after);

    // --- B8. The sort and the pagination ---
    //
    // Before the delete: the sort is checked against the rows that the
    // list starts with.

    $response = tt_http_request('GET', $base . '/transactions?sort=amount&dir=asc');
    $rows = tt_tbody($response['body']);
    $coffeeAt = tt_pos_of($rows, 'Coffee');
    $groceriesAt = tt_pos_of($rows, 'Groceries');
    ok('the sort reorders the rows', $coffeeAt !== -1 && $groceriesAt !== -1 && $coffeeAt < $groceriesAt,
        'coffee at ' . $coffeeAt . ', groceries at ' . $groceriesAt);

    $response = tt_http_request('GET', $base . '/transactions?sort=nonsense');
    ok('an unknown sort falls back to the date', $response['status'] === 200, (string) $response['status']);

    $response = tt_http_request('GET', $base . '/transactions?page=2');
    ok('a page beyond the end still answers 200', $response['status'] === 200, (string) $response['status']);

    // --- B9. The delete ---

    $response = tt_http_request('POST', $base . '/transactions/delete', ['id' => (string) $coffeeTx]);
    ok('a delete without the CSRF token is refused', $response['status'] === 403, (string) $response['status']);

    $statement = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE id = ?');
    $statement->execute([$coffeeTx]);
    ok('the refused delete left the row alone', (int) $statement->fetchColumn() === 1);

    $response = tt_http_request('POST', $base . '/transactions/delete', [
        'csrf_token' => $token,
        'id'         => (string) $otherTx,
    ]);
    ok('another visitor’s transaction cannot be deleted', $response['status'] === 404, (string) $response['status']);

    $statement->execute([$otherTx]);
    ok('the refused delete left the other row alone', (int) $statement->fetchColumn() === 1);

    $response = tt_http_request('POST', $base . '/transactions/delete', [
        'csrf_token' => $token,
        'id'         => (string) $coffeeTx,
    ]);
    ok('a valid delete redirects', $response['status'] === 303, (string) $response['status']);
    ok('the delete confirms', str_contains($response['body'], 'Transaction deleted.'));

    $statement->execute([$coffeeTx]);
    ok('the row is gone', (int) $statement->fetchColumn() === 0);

    // --- B10. The empty account ---

    tt_reset_cookies();
    $emptyUser = 'empty';
    $emptyPassword = 'CorrectHorseBattery9!';
    tt_seed_user($pdo, $emptyUser, $emptyUser . '@example.test', $emptyPassword);

    $loginPage = tt_http_request('GET', $base . '/login');
    $token = tt_csrf_of($loginPage['body']);
    tt_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $emptyUser,
        'password'   => $emptyPassword,
    ]);

    $response = tt_http_request('GET', $base . '/transactions');
    $body = $response['body'];
    ok('the empty account says so', str_contains($body, 'no transactions yet'), 'the empty state is missing');
    ok('the empty account has no filter bar', !str_contains($body, 'id="tx-search"'));
    ok('the empty account has no tiles', !str_contains($body, '0.00'));
}

// ---------------------------------------------------------------------------
// The teardown
// ---------------------------------------------------------------------------

echo "\n## Teardown\n";

if ($server !== null) {
    tt_stop_server($server);
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
    tt_remove_tree($sandboxRoot);
    ok('the sandbox is removed', !is_dir($sandboxRoot));
}

tt_drop_scratch_database($root);
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
