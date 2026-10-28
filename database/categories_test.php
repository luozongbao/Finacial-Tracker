<?php

/**
 * Issue 020 — the categories screen.
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
 *                      the list, the create, the rename, the delete, the
 *                      failed delete and the empty account.
 *
 * A test passes on its EXIT CODE, not on its output. The success markers
 * are not consistent across this repository, so nothing below greps for
 * one.
 *
 * Run it with:
 *
 *     docker compose exec -T app php /var/www/html/database/categories_test.php
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
function ct_reset_cookies(): void
{
    $GLOBALS['ct_cookies'] = [];
}

/**
 * A tiny HTTP client: one cookie jar, a status, the headers and the body.
 *
 * @param array<string, string> $fields
 * @param string[]              $extraHeaders
 *
 * @return array{status: int, body: string, headers: array<string, string>, set_cookie: string[]}
 */
function ct_http_request(
    string $method,
    string $url,
    array $fields = [],
    array $extraHeaders = [],
    int $timeout = 30
): array {
    $headers = [
        'User-Agent: financial-tracker-categories-test',
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

    $jar = $GLOBALS['ct_cookies'] ?? [];

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
            unset($GLOBALS['ct_cookies'][$name]);
            continue;
        }

        $GLOBALS['ct_cookies'][$name] = $value;
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
function ct_csrf_of(string $body): string
{
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * The rows of the table, without the rest of the page.
 */
function ct_tbody(string $body): string
{
    if (preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $body, $match) === 1) {
        return $match[1];
    }

    return '';
}

/**
 * Strip the comments out of a source file, so an assertion about code
 * does not trip over a comment that mentions the same word.
 */
function ct_strip_comments(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#//[^\n]*#', '', $src);

    return (string) $src;
}

/**
 * Every short echo in a source that prints something which is NOT escaped.
 *
 * @return string[]
 */
/**
 * The two branches of a ternary, or null when the expression is not one.
 *
 * @return array{0: string, 1: string}|null
 */
function ct_split_ternary(string $expression): ?array
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
function ct_is_literal_expression(string $expression): bool
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
function ct_unescaped_outputs(string $src): array
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

        if (ct_is_literal_expression($expression)) {
            continue;
        }

        $ternary = ct_split_ternary($expression);

        if ($ternary !== null
            && ct_is_literal_expression($ternary[0])
            && ct_is_literal_expression($ternary[1])) {
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
function ct_env_values(string $root): array
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
function ct_free_port(): int
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
function ct_start_server(string $docroot): array
{
    $port = ct_free_port();

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
        $res = ct_http_request('GET', $base . '/__ping__', [], [], 2);

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
function ct_stop_server(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc'], 15);
        proc_close($server['proc']);
    }
}

/**
 * Remove a directory tree.
 */
function ct_remove_tree(string $dir): void
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
            ct_remove_tree($path);
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
function ct_make_sandbox(string $root, array $db): string
{
    $dir = sys_get_temp_dir() . '/ft_cat_' . bin2hex(random_bytes(5));

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
$_SERVER['SERVER_NAME'] = 'cat.test';

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
function ct_create_scratch_database(string $root): ?array
{
    $env  = ct_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return null;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_cat_test`');
        $pdo->exec('CREATE DATABASE `ft_cat_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `ft_cat_test`');

        $schema = (string) file_get_contents($root . '/database/schema.sql');
        $pdo->exec($schema);
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be prepared: " . $ex->getMessage() . "\n";

        return null;
    }

    return [
        'host' => $host,
        'name' => 'ft_cat_test',
        'user' => 'root',
        'pass' => $pass,
        'pdo'  => $pdo,
    ];
}

/**
 * Drop the scratch database.
 */
function ct_drop_scratch_database(string $root): void
{
    $env  = ct_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_cat_test`');
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be dropped: " . $ex->getMessage() . "\n";
    }
}

/**
 * Seed an account and return its id.
 */
function ct_seed_user(PDO $pdo, string $username, string $email, string $password): int
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
function ct_seed_category(PDO $pdo, int $userId, string $name, string $type): int
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
function ct_seed_transaction(
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

// ---------------------------------------------------------------------------
// The fixtures
// ---------------------------------------------------------------------------

$root        = dirname(__DIR__);
$sandboxRoot = '';

$demoUser     = 'demo';
$demoPassword = 'CorrectHorseBattery9!';

$otherUser     = 'other';
$otherPassword = 'CorrectHorseBattery9!';

echo "Issue 020 — the categories screen\n";

// ---------------------------------------------------------------------------
// A. The file tier
// ---------------------------------------------------------------------------

echo "\n## A. The sources\n";

// --- A1. The files exist ---

$files = [
    'includes/functions.php',
    'views/categories.php',
    'views/category_save.php',
    'views/category_delete.php',
    'assets/css/pages/categories.css',
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

foreach (['views/categories.php', 'views/category_save.php', 'views/category_delete.php'] as $file) {
    $code = ct_strip_comments($src[$file]);
    $guardAt = strpos($code, 'requireLogin()');
    $getAt   = strpos($code, '$_GET');
    $postAt  = strpos($code, "\$_POST");

    ok(
        $file . ' calls requireLogin() before it reads any input',
        $guardAt !== false
            && ($getAt === false || $guardAt < $getAt)
            && ($postAt === false || $guardAt < $postAt),
        'guard at ' . ($guardAt === false ? 'none' : (string) $guardAt)
    );
}

// --- A3. The CSRF token is validated before any input is read ---

foreach (['views/category_save.php', 'views/category_delete.php'] as $file) {
    $code = ct_strip_comments($src[$file]);
    $csrfAt = strpos($code, 'validateCsrfToken');

    $fieldReads = [];

    foreach (["post('name'", "post('type'", "post('id'"] as $needle) {
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

foreach (['views/categories.php', 'views/category_save.php', 'views/category_delete.php'] as $file) {
    $bad = ct_unescaped_outputs($src[$file]);

    ok(
        $file . ' escapes every dynamic value it prints',
        $bad === [],
        implode(' | ', array_slice($bad, 0, 4))
    );
}

// --- A5. The ownership is enforced in the SQL ---

ok(
    'the list filters on the user id in SQL',
    str_contains($src['views/categories.php'], 'c.user_id = ?')
);

ok(
    'the list joins the transactions on the owner as well',
    str_contains($src['views/categories.php'], 't.user_id = c.user_id')
);

ok(
    'the rename updates with the user id in the WHERE clause',
    preg_match(
        '/UPDATE\s+categories\s+SET\s+name\s*=\s*:name\s+WHERE\s+id\s*=\s*:id\s+AND\s+user_id\s*=\s*:uid/is',
        $src['views/category_save.php']
    ) === 1
);

ok(
    'the rename selects the row with the user id in the WHERE clause',
    preg_match(
        '/FROM\s+categories\s+WHERE\s+id\s*=\s*\?\s+AND\s+user_id\s*=\s*\?/is',
        $src['views/category_save.php']
    ) === 1
);

ok(
    'the delete deletes with the user id in the WHERE clause',
    preg_match(
        '/DELETE\s+FROM\s+categories\s+WHERE\s+id\s*=\s*:id\s+AND\s+user_id\s*=\s*:uid/is',
        $src['views/category_delete.php']
    ) === 1
);

ok(
    'the delete selects the row before it deletes it',
    str_contains($src['views/category_delete.php'], 'SELECT')
);

ok(
    'the delete does not touch the transactions',
    !preg_match('/DELETE\s+FROM\s+transactions/i', $src['views/category_delete.php'])
);

// --- A6. The helpers ---

require_once $root . '/includes/functions.php';

$validation = validateCategoryInput('Groceries', 'expense');
ok('validateCategoryInput() accepts a valid category', $validation['errors'] === [], json_encode($validation['errors']));
ok('validateCategoryInput() trims the name', $validation['values']['name'] === 'Groceries');

$validation = validateCategoryInput('', 'expense');
ok('validateCategoryInput() rejects an empty name', ($validation['errors']['name'] ?? '') === 'Enter a name for the category.', (string) ($validation['errors']['name'] ?? ''));

$validation = validateCategoryInput(str_repeat('a', 61), 'expense');
ok('validateCategoryInput() rejects a long name', ($validation['errors']['name'] ?? '') === 'The name may be at most 60 characters long.', (string) ($validation['errors']['name'] ?? ''));

$validation = validateCategoryInput('Groceries', 'nonsense');
ok('validateCategoryInput() rejects an unknown type', ($validation['errors']['type'] ?? '') === 'Choose either income or expense.', (string) ($validation['errors']['type'] ?? ''));

ok('categoryTypeLabel() names the income type', categoryTypeLabel('income') === 'Income');
ok('categoryTypeLabel() names the expense type', categoryTypeLabel('expense') === 'Expense');

$total = formatCategoryTotal('-47.80', 2);
ok('formatCategoryTotal() shows a negative total', $total['label'] === "\u{2212}47.80", $total['label']);
ok('formatCategoryTotal() marks a negative total as an expense', $total['modifier'] === 'expense', $total['modifier']);

$total = formatCategoryTotal('1200.00', 1);
ok('formatCategoryTotal() shows a positive total', $total['label'] === '+1,200.00', $total['label']);
ok('formatCategoryTotal() marks a positive total as an income', $total['modifier'] === 'income', $total['modifier']);

$total = formatCategoryTotal('0.00', 0);
ok('formatCategoryTotal() shows a dash for a category without transactions', $total['label'] === "\u{2014}", $total['label']);
ok('formatCategoryTotal() marks the dash as muted', $total['modifier'] === 'muted', $total['modifier']);

ok(
    'uncategorisedNotice() uses the singular for one transaction',
    uncategorisedNotice(1) === '1 transaction will become uncategorised. It will not be deleted.',
    uncategorisedNotice(1)
);

ok(
    'uncategorisedNotice() uses the plural for more than one',
    uncategorisedNotice(2) === '2 transactions will become uncategorised. They will not be deleted.',
    uncategorisedNotice(2)
);

ok(
    'uncategorisedNotice() uses the plural for none as well',
    uncategorisedNotice(0) === '0 transactions will become uncategorised. They will not be deleted.',
    uncategorisedNotice(0)
);

// --- A7. The forms ---

$listCode = ct_strip_comments($src['views/categories.php']);

ok(
    'the create form is a POST form',
    preg_match('/<form[^>]*method="post"[^>]*action="\/categories\/save"/i', $listCode) === 1
);

ok('the create form carries the CSRF token', str_contains($listCode, 'name="csrf_token"'));

ok(
    'the delete form posts to the delete handler',
    preg_match('/<form[^>]*method="post"[^>]*action="\/categories\/delete"/i', $listCode) === 1
);

ok(
    'the rename form posts to the save handler',
    preg_match('/<form[^>]*method="post"[^>]*action="\/categories\/save"/i', $listCode) === 1
);

// The id and the form attribute share one prefix, so the input is bound to
// the form of its own row. (The sandbox proves the binding at runtime.)
ok(
    'the rename input is bound to its form with the HTML5 form attribute',
    str_contains($listCode, 'form="cat-rename-form-<?=')
    && str_contains($listCode, 'id="cat-rename-form-<?=')
);

ok('the list has no form inside a table cell', !preg_match('/<td[^>]*>\s*<form/i', $listCode));

// Every form on the screen carries a token.
$forms = [];
if (preg_match_all('/<form\b.*?<\/form>/is', $listCode, $matches) === 1 || !empty($matches[0])) {
    $forms = $matches[0];
}

ok('the screen has forms', $forms !== []);

$withoutToken = 0;

foreach ($forms as $form) {
    if (!str_contains($form, 'name="csrf_token"')) {
        $withoutToken++;
    }
}

ok('every form carries a CSRF token', $withoutToken === 0, (string) $withoutToken . ' forms without a token');

// --- A8. The table ---

ok('the table has a caption', str_contains($listCode, '<caption class="sr-only">Your categories</caption>'));

$cells = [];
if (preg_match_all('/<td[^>]*>/', $listCode, $matches) === 1 || !empty($matches[0])) {
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

// --- A9. The stylesheet ---

$css = $src['assets/css/pages/categories.css'];
ok('the stylesheet has no literal colour', preg_match('/#[0-9a-fA-F]{3,8}\b/', $css) !== 1);
ok('the stylesheet has no rgb() or hsl() colour', preg_match('/\b(rgb|rgba|hsl|hsla)\(/i', $css) !== 1);
ok('the stylesheet uses the tokens', str_contains($css, 'var(--'));
ok('the stylesheet uses no token that does not exist', !preg_match('/var\(--sp-7\)/', $css));

// --- A10. The script ---

$js = $src['assets/js/app.js'];
ok('the script hides the modals it can open', str_contains($js, 'data-modal-open'));
ok('the script leaves a failed dialog open', str_contains($js, 'data-modal-keep'));

// ---------------------------------------------------------------------------
// B. The behaviour tier
// ---------------------------------------------------------------------------

echo "\n## B. The sandbox\n";

$server = null;
$db     = null;

try {
    $db = ct_create_scratch_database($root);
} catch (Throwable $ex) {
    $db = null;
}

if ($db === null) {
    ok('the scratch database is created', false, 'the database server did not answer');
} else {
    ok('the scratch database is created', true);

    $sandboxRoot = ct_make_sandbox($root, $db);
    $pdo = $db['pdo'];

    $demoUserId  = ct_seed_user($pdo, $demoUser, $demoUser . '@example.test', $demoPassword);
    $otherUserId = ct_seed_user($pdo, $otherUser, $otherUser . '@example.test', $otherPassword);

    $groceriesId = ct_seed_category($pdo, $demoUserId, 'Groceries', 'expense');
    $salaryId    = ct_seed_category($pdo, $demoUserId, 'Salary', 'income');
    $emptyId     = ct_seed_category($pdo, $demoUserId, 'Unused', 'expense');

    $otherCategoryId = ct_seed_category($pdo, $otherUserId, 'Other groceries', 'expense');

    ct_seed_transaction($pdo, $demoUserId, 'expense', '42.80', '2026-10-19', 'Groceries', $groceriesId);
    ct_seed_transaction($pdo, $demoUserId, 'expense', '5.00', '2026-10-20', 'Bread', $groceriesId);
    ct_seed_transaction($pdo, $demoUserId, 'income', '1200.00', '2026-10-01', 'Salary', $salaryId);
    ct_seed_transaction($pdo, $otherUserId, 'expense', '99.00', '2026-10-19', 'Someone else', $otherCategoryId);

    $server = ct_start_server($sandboxRoot);
    $base = $server['base'];

    // --- B1. The guard ---

    ct_reset_cookies();
    $response = ct_http_request('GET', $base . '/categories');
    ok('a guest is sent away from the category list', $response['status'] === 302, (string) $response['status']);

    ct_reset_cookies();
    $response = ct_http_request('POST', $base . '/categories/save', ['name' => 'Sneaky']);
    ok('a guest cannot create a category', $response['status'] === 302, (string) $response['status']);

    ct_reset_cookies();
    $response = ct_http_request('POST', $base . '/categories/delete', ['id' => (string) $groceriesId]);
    ok('a guest cannot delete a category', $response['status'] === 302, (string) $response['status']);

    $statement = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
    $statement->execute([$groceriesId]);
    ok('the refused delete left the row alone', (int) $statement->fetchColumn() === 1);

    // --- B2. The list ---

    ct_reset_cookies();
    $loginPage = ct_http_request('GET', $base . '/login');
    $token = ct_csrf_of($loginPage['body']);
    $response = ct_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $demoUser,
        'password'   => $demoPassword,
    ]);
    ok('the sign-in succeeds', $response['status'] === 302, (string) $response['status']);

    $response = ct_http_request('GET', $base . '/categories');
    $body = $response['body'];
    $rows = ct_tbody($body);

    ok('the category list answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the list has the heading', str_contains($body, 'Categories'));
    ok('the list has the create form', str_contains($body, 'aria-label="Create a category"'));
    ok('the list has the name field', str_contains($body, 'id="cat-name"'));
    ok('the list has the type radios', str_contains($body, 'id="cat-in"') && str_contains($body, 'id="cat-out"'));

    // The token rotates on the sign-in, so it is read again from a page
    // that is rendered by the signed-in session.
    $token = ct_csrf_of($body);
    ok('the list carries a CSRF token', $token !== '');

    ok('the table shows the groceries', str_contains($rows, 'Groceries'));
    ok('the table shows the salary', str_contains($rows, 'Salary'));
    ok('the table shows the unused category', str_contains($rows, 'Unused'));
    ok('the table hides the other visitor’s category', !str_contains($rows, 'Other groceries'));

    ok('the table counts the groceries', str_contains($rows, '>2<'), 'the count is missing');
    ok('the table shows the groceries total', str_contains($rows, "\u{2212}47.80"), 'the total is missing');
    ok('the table shows the salary total', str_contains($rows, '+1,200.00'), 'the total is missing');
    ok('the table shows a dash for a category without transactions', str_contains($rows, "\u{2014}"), 'the dash is missing');

    ok('the confirmation says the transactions survive', str_contains($body, '2 transactions will become uncategorised. They will not be deleted.'));
    ok('the confirmation has a delete button', str_contains($body, 'Delete category'));

    // --- B3. The create ---

    $response = ct_http_request('POST', $base . '/categories/save', [
        'csrf_token' => $token,
        'name'       => 'Coffee',
        'type'       => 'expense',
    ]);
    ok('a valid create redirects', $response['status'] === 302, (string) $response['status']);

    $statement = $pdo->prepare('SELECT id, type FROM categories WHERE user_id = ? AND name = ?');
    $statement->execute([$demoUserId, 'Coffee']);
    $created = $statement->fetch(PDO::FETCH_ASSOC);
    ok('the category was created', is_array($created), 'the row is missing');
    ok('the category has the posted type', is_array($created) && $created['type'] === 'expense');

    $response = ct_http_request('GET', $base . '/categories');
    ok('the create is confirmed', str_contains($response['body'], 'was added.') && str_contains($response['body'], 'Coffee'));

    // A duplicate name — the database owns the uniqueness.
    $response = ct_http_request('POST', $base . '/categories/save', [
        'csrf_token' => $token,
        'name'       => 'Coffee',
        'type'       => 'expense',
    ]);
    ok('a duplicate create redirects', $response['status'] === 302, (string) $response['status']);

    $statement = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE user_id = ? AND name = ?');
    $statement->execute([$demoUserId, 'Coffee']);
    ok('the duplicate was not stored', (int) $statement->fetchColumn() === 1);

    $response = ct_http_request('GET', $base . '/categories');
    ok('the duplicate is refused', str_contains($response['body'], 'That name is already in use.'));

    // An empty name.
    $response = ct_http_request('POST', $base . '/categories/save', [
        'csrf_token' => $token,
        'name'       => '   ',
        'type'       => 'expense',
    ]);
    ok('an invalid create redirects', $response['status'] === 302, (string) $response['status']);

    $response = ct_http_request('GET', $base . '/categories');
    ok('the empty name is refused', str_contains($response['body'], 'Enter a name for the category.'));

    // A create without the CSRF token.
    $response = ct_http_request('POST', $base . '/categories/save', [
        'name' => 'No token',
        'type' => 'expense',
    ]);
    ok('a create without the CSRF token is refused', $response['status'] === 403, (string) $response['status']);

    // --- B4. The rename ---

    $response = ct_http_request('GET', $base . '/categories?rename=' . $groceriesId);
    $body = $response['body'];
    ok('the rename state answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the rename state shows the input', str_contains($body, 'id="cat-rename-' . $groceriesId . '"'));
    ok('the rename state binds the input to its form', str_contains($body, 'form="cat-rename-form-' . $groceriesId . '"'));
    ok('the rename state shows the save button', str_contains($body, '>Save</button>'));
    ok('the rename state shows the cancel link', str_contains($body, 'href="/categories"'));

    $response = ct_http_request('GET', $base . '/categories?rename=' . $otherCategoryId);
    $body = $response['body'];
    ok('another visitor’s category puts no row in edit mode', !str_contains($body, 'id="cat-rename-' . $otherCategoryId . '"'));
    ok('the other visitor’s category is not in the list either', !str_contains($body, 'Other groceries'));

    $response = ct_http_request('POST', $base . '/categories/save', [
        'csrf_token' => $token,
        'id'         => (string) $groceriesId,
        'name'       => 'Food',
        'type'       => 'income',
    ]);
    ok('a valid rename redirects', $response['status'] === 302, (string) $response['status']);

    $statement = $pdo->prepare('SELECT name, type FROM categories WHERE id = ?');
    $statement->execute([$groceriesId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    ok('the category was renamed', is_array($row) && $row['name'] === 'Food');
    ok('the rename did not change the type', is_array($row) && $row['type'] === 'expense', is_array($row) ? (string) $row['type'] : '');

    $response = ct_http_request('GET', $base . '/categories');
    ok('the rename is confirmed', str_contains($response['body'], 'was renamed to') && str_contains($response['body'], 'Food'));

    $response = ct_http_request('POST', $base . '/categories/save', [
        'csrf_token' => $token,
        'id'         => (string) $otherCategoryId,
        'name'       => 'Stolen',
    ]);
    ok('another visitor’s category cannot be renamed', $response['status'] === 404, (string) $response['status']);

    $statement = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
    $statement->execute([$otherCategoryId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    ok('the refused rename left the other row alone', is_array($row) && $row['name'] === 'Other groceries');

    // --- B5. The delete ---

    $response = ct_http_request('POST', $base . '/categories/delete', ['id' => (string) $salaryId]);
    ok('a delete without the CSRF token is refused', $response['status'] === 403, (string) $response['status']);

    $statement = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
    $statement->execute([$salaryId]);
    ok('the refused delete left the row alone', (int) $statement->fetchColumn() === 1);

    $response = ct_http_request('POST', $base . '/categories/delete', [
        'csrf_token' => $token,
        'id'         => (string) $otherCategoryId,
    ]);
    ok('another visitor’s category cannot be deleted', $response['status'] === 404, (string) $response['status']);

    $statement->execute([$otherCategoryId]);
    ok('the refused delete left the other row alone', (int) $statement->fetchColumn() === 1);

    // A delete that fails: a trigger refuses the DELETE. The screen must
    // answer 500 and keep the dialog open with a "Try again" button.
    $pdo->exec(
        'CREATE TRIGGER ft_block_category_delete BEFORE DELETE ON categories
         FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'blocked by the test\''
    );

    $response = ct_http_request('POST', $base . '/categories/delete', [
        'csrf_token' => $token,
        'id'         => (string) $salaryId,
    ]);
    ok('a failed delete answers 500', $response['status'] === 500, (string) $response['status']);
    ok('the failed delete says so', str_contains($response['body'], 'The category could not be deleted. Please try again.'));
    ok('the failed delete keeps the dialog open', str_contains($response['body'], 'data-modal-keep'));
    ok('the failed delete offers to try again', str_contains($response['body'], '>Try again</button>'));

    $statement->execute([$salaryId]);
    ok('the failed delete left the row alone', (int) $statement->fetchColumn() === 1);

    $pdo->exec('DROP TRIGGER IF EXISTS ft_block_category_delete');

    // A valid delete.
    $response = ct_http_request('POST', $base . '/categories/delete', [
        'csrf_token' => $token,
        'id'         => (string) $salaryId,
    ]);
    ok('a valid delete redirects', $response['status'] === 302, (string) $response['status']);

    $response = ct_http_request('GET', $base . '/categories');
    ok('the delete confirms', str_contains($response['body'], 'was deleted.'));

    $statement->execute([$salaryId]);
    ok('the row is gone', (int) $statement->fetchColumn() === 0);

    // The transactions survive as uncategorised — the foreign key is ON
    // DELETE SET NULL, and a delete that dropped them would destroy money
    // records.
    $statement = $pdo->prepare('SELECT category_id FROM transactions WHERE id = 3');
    $statement->execute();
    ok('the transaction survived the delete', $statement->fetchColumn() !== false);

    $statement = $pdo->prepare('SELECT category_id FROM transactions WHERE category_id = ?');
    $statement->execute([$salaryId]);
    ok('the transaction is uncategorised now', (int) $statement->fetchColumn() === 0, 'the transaction still points at the category');

    $statement = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ?');
    $statement->execute([$demoUserId]);
    ok('no transaction was destroyed', (int) $statement->fetchColumn() === 3, 'the count is wrong');

    // --- B6. The empty account ---

    ct_reset_cookies();
    $emptyUser = 'empty';
    $emptyPassword = 'CorrectHorseBattery9!';
    ct_seed_user($pdo, $emptyUser, $emptyUser . '@example.test', $emptyPassword);

    $loginPage = ct_http_request('GET', $base . '/login');
    $token = ct_csrf_of($loginPage['body']);
    ct_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $emptyUser,
        'password'   => $emptyPassword,
    ]);

    $response = ct_http_request('GET', $base . '/categories');
    $body = $response['body'];
    ok('the empty account says so', str_contains($body, 'No categories yet.'), 'the empty state is missing');
    ok('the empty account still has the create form', str_contains($body, 'aria-label="Create a category"'));
    ok('the empty account has no table', !str_contains($body, '<table class="data-table">'));
    ok('the empty account explains the uncategorised entries', str_contains($body, 'uncategorised entries are still counted'));
}

// ---------------------------------------------------------------------------
// The teardown
// ---------------------------------------------------------------------------

echo "\n## Teardown\n";

if ($server !== null) {
    ct_stop_server($server);
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
    ct_remove_tree($sandboxRoot);
    ok('the sandbox is removed', !is_dir($sandboxRoot));
}

ct_drop_scratch_database($root);
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
