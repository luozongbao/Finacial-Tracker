<?php

/**
 * Issue 020 — the settings screen.
 *
 * Two tiers:
 *
 *   A. The sources   — the files exist, the guard is the first statement,
 *                      the CSRF token is validated before any input is
 *                      read, every dynamic value is escaped, the two
 *                      passwords are never even named, the ownership is
 *                      enforced in the SQL, and the helpers behave the way
 *                      the specification says they behave.
 *
 *   B. The sandbox   — the real screen, against a scratch database: the
 *                      four cards, the profile save, the change-password
 *                      form, the sign-out and the other sessions.
 *
 * A test passes on its EXIT CODE, not on its output. The success markers
 * are not consistent across this repository, so nothing below greps for
 * one.
 *
 * Run it with:
 *
 *     docker compose exec -T app php /var/www/html/database/settings_test.php
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
function st_reset_cookies(): void
{
    $GLOBALS['st_cookies'] = [];
}

/**
 * The cookie jar, so a second session can be kept aside.
 *
 * @return array<string, string>
 */
function st_cookies(): array
{
    return $GLOBALS['st_cookies'] ?? [];
}

/**
 * Put a cookie jar back, so a request can be made as another session.
 *
 * @param array<string, string> $jar
 */
function st_set_cookies(array $jar): void
{
    $GLOBALS['st_cookies'] = $jar;
}

/**
 * A tiny HTTP client: one cookie jar, a status, the headers and the body.
 *
 * @param array<string, string> $fields
 * @param string[]              $extraHeaders
 *
 * @return array{status: int, body: string, headers: array<string, string>, set_cookie: string[]}
 */
function st_http_request(
    string $method,
    string $url,
    array $fields = [],
    array $extraHeaders = [],
    int $timeout = 30
): array {
    $headers = [
        'User-Agent: financial-tracker-settings-test',
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

    $jar = $GLOBALS['st_cookies'] ?? [];

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
            unset($GLOBALS['st_cookies'][$name]);
            continue;
        }

        $GLOBALS['st_cookies'][$name] = $value;
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
function st_csrf_of(string $body): string
{
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * Strip the comments out of a source file, so an assertion about code
 * does not trip over a comment that mentions the same word.
 */
function st_strip_comments(string $src): string
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
function st_split_ternary(string $expression): ?array
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
function st_is_literal_expression(string $expression): bool
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
function st_unescaped_outputs(string $src): array
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

        if (st_is_literal_expression($expression)) {
            continue;
        }

        $ternary = st_split_ternary($expression);

        if ($ternary !== null
            && st_is_literal_expression($ternary[0])
            && st_is_literal_expression($ternary[1])) {
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
function st_env_values(string $root): array
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
function st_free_port(): int
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
function st_start_server(string $docroot): array
{
    $port = st_free_port();

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
        $res = st_http_request('GET', $base . '/__ping__', [], [], 2);

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
function st_stop_server(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc'], 15);
        proc_close($server['proc']);
    }
}

/**
 * Remove a directory tree.
 */
function st_remove_tree(string $dir): void
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
            st_remove_tree($path);
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
function st_make_sandbox(string $root, array $db): string
{
    $dir = sys_get_temp_dir() . '/ft_set_' . bin2hex(random_bytes(5));

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

    // The SMTP host and the SMTP user are EMPTY on purpose: the card has
    // to render an em dash for them.
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
$_SERVER['SERVER_NAME'] = 'set.test';

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
function st_create_scratch_database(string $root): ?array
{
    $env  = st_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return null;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_set_test`');
        $pdo->exec('CREATE DATABASE `ft_set_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `ft_set_test`');

        $schema = (string) file_get_contents($root . '/database/schema.sql');
        $pdo->exec($schema);
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be prepared: " . $ex->getMessage() . "\n";

        return null;
    }

    return [
        'host' => $host,
        'name' => 'ft_set_test',
        'user' => 'root',
        'pass' => $pass,
        'pdo'  => $pdo,
    ];
}

/**
 * Drop the scratch database.
 */
function st_drop_scratch_database(string $root): void
{
    $env  = st_env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_set_test`');
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be dropped: " . $ex->getMessage() . "\n";
    }
}

/**
 * Seed an account and return its id.
 */
function st_seed_user(PDO $pdo, string $username, string $email, string $password): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, email_verified_at, status)
         VALUES (?, ?, ?, NOW(), \'active\')'
    );

    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);

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

echo "Issue 020 — the settings screen\n";

// ---------------------------------------------------------------------------
// A. The file tier
// ---------------------------------------------------------------------------

echo "\n## A. The sources\n";

// --- A1. The files exist ---

$files = [
    'includes/functions.php',
    'includes/auth.php',
    'views/settings.php',
    'views/logout.php',
    'assets/css/pages/settings.css',
];

foreach ($files as $file) {
    ok('the file ' . $file . ' exists', is_file($root . '/' . $file));
}

$src = [];

foreach ($files as $file) {
    $source = @file_get_contents($root . '/' . $file);
    $src[$file] = $source === false ? '' : $source;
}

// --- A2. The guard is the first statement of the view ---

$viewCode = st_strip_comments($src['views/settings.php']);
$guardAt  = strpos($viewCode, 'requireLogin()');
$getAt    = strpos($viewCode, '$_GET');
$postAt   = strpos($viewCode, "\$_POST");

ok(
    'views/settings.php calls requireLogin() before it reads any input',
    $guardAt !== false
        && ($getAt === false || $guardAt < $getAt)
        && ($postAt === false || $guardAt < $postAt),
    'the guard is at ' . ($guardAt === false ? 'none' : (string) $guardAt)
);

// --- A3. The CSRF token is validated before any input is read ---

$csrfAt = strpos($viewCode, 'validateCsrfToken');

$fieldReads = [];

foreach (["post('username'", "post('email'", "postRaw('current_password'", "postRaw('password'"] as $needle) {
    $at = strpos($viewCode, $needle);

    if ($at !== false) {
        $fieldReads[] = $at;
    }
}

$firstFieldRead = $fieldReads === [] ? PHP_INT_MAX : min($fieldReads);

ok(
    'views/settings.php validates the CSRF token before it reads the posted fields',
    $csrfAt !== false && $csrfAt < $firstFieldRead,
    'the token is checked at ' . ($csrfAt === false ? 'none' : (string) $csrfAt)
        . ', the first field is read at ' . (string) $firstFieldRead
);

// --- A4. Every dynamic output is escaped ---

$bad = st_unescaped_outputs($src['views/settings.php']);

ok(
    'views/settings.php escapes every dynamic value it prints',
    $bad === [],
    implode(' | ', array_slice($bad, 0, 4))
);

// --- A5. The two passwords are never even named ---

ok(
    'views/settings.php never names DB_PASS',
    !str_contains($src['views/settings.php'], 'DB_PASS')
);

ok(
    'views/settings.php never names SMTP_PASS',
    !str_contains($src['views/settings.php'], 'SMTP_PASS')
);

$viewsWithSecrets = [];

foreach (glob($root . '/views/*.php') ?: [] as $file) {
    $code = (string) file_get_contents($file);

    if (str_contains($code, 'DB_PASS') || str_contains($code, 'SMTP_PASS')) {
        $viewsWithSecrets[] = basename($file);
    }
}

ok('no view names a secret', $viewsWithSecrets === [], implode(', ', $viewsWithSecrets));

// --- A6. The ownership is enforced in the SQL ---

// The two UPDATE statements, taken out of the view one by one: the
// profile one and the password one.
$profileUpdate  = '';
$passwordUpdate = '';

if (preg_match('/UPDATE\s+users\s+SET\s+username.*?WHERE\s+id\s*=\s*:uid/is', $viewCode, $match) === 1) {
    $profileUpdate = $match[0];
}

if (preg_match('/UPDATE\s+users\s+SET\s+password_hash.*?WHERE\s+id\s*=\s*:uid/is', $viewCode, $match) === 1) {
    $passwordUpdate = $match[0];
}

ok(
    'the profile update is scoped on the signed-in user',
    $profileUpdate !== ''
);

ok(
    'the profile update does not touch the password hash',
    $profileUpdate !== '' && !str_contains($profileUpdate, 'password_hash')
);

ok(
    'the profile update writes both fields',
    str_contains($profileUpdate, 'username = :username')
    && str_contains($profileUpdate, 'email = :email')
);

ok(
    'the password update is scoped on the signed-in user',
    $passwordUpdate !== ''
);

ok(
    'the password update clears the lock',
    str_contains($viewCode, 'failed_attempts = 0')
    && str_contains($viewCode, 'locked_until = NULL')
);

ok(
    'the password read is scoped on the signed-in user',
    preg_match('/SELECT\s+id,\s*password_hash\s+FROM\s+users\s+WHERE\s+id\s*=\s*\?/is', $viewCode) === 1
);

ok(
    'the credential version is refreshed after the hash changed',
    str_contains($viewCode, "\$_SESSION['credential_version'] = credentialVersion(\$hash);")
);

// --- A7. The installation values come from config.php only ---

ok(
    'the view reads no environment variable',
    !str_contains($viewCode, 'getenv(')
    && !str_contains($viewCode, '$_ENV')
    && !str_contains($viewCode, '$_SERVER[\'HTTP_HOST\']')
);

ok(
    'the registration flag is compared as a boolean',
    str_contains($viewCode, "ALLOW_REGISTRATION === true")
    && !preg_match("/ALLOW_REGISTRATION\s*===\s*'true'/", $viewCode)
    && !preg_match("/ALLOW_REGISTRATION\s*===\s*'false'/", $viewCode)
);

// --- A8. The helpers ---

require_once $root . '/includes/functions.php';

$profile = validateProfileInput('demo', 'Demo@Example.test');
ok('validateProfileInput() accepts a valid profile', $profile['errors'] === []);
ok('validateProfileInput() lowercases the email', $profile['values']['email'] === 'demo@example.test');

$profile = validateProfileInput('a', 'demo@example.test');
ok('validateProfileInput() rejects a short username', isset($profile['errors']['username']));

$profile = validateProfileInput('demo', 'not-an-email');
ok('validateProfileInput() rejects a broken email', isset($profile['errors']['email']));

ok(
    'usernameValidationMessage() names the length rule',
    usernameValidationMessage('ab') === 'The username must be at least 3 characters long.'
);

ok(
    'usernameValidationMessage() names the character rule',
    str_contains(usernameValidationMessage('de mo'), 'may only contain letters')
);

$change = validatePasswordChangeInput('short', 'short');
ok(
    'validatePasswordChangeInput() rejects a short password',
    ($change['password'] ?? '') === 'The password must be at least 10 characters long.'
);

$change = validatePasswordChangeInput('longenoughpassword', 'differentpassword');
ok(
    'validatePasswordChangeInput() rejects a mismatch',
    ($change['password_confirmation'] ?? '') === 'The two passwords do not match.'
);

$change = validatePasswordChangeInput('longenoughpassword', 'longenoughpassword');
ok('validatePasswordChangeInput() accepts a matching pair', $change === []);

$change = validatePasswordChangeInput(' paddedpassword ', ' paddedpassword ');
ok(
    'validatePasswordChangeInput() does not trim the password',
    $change === []
);

ok('weekStartName() names Sunday', weekStartName(0) === 'Sunday');
ok('weekStartName() names Monday', weekStartName(1) === 'Monday');
ok('weekStartName() names Saturday', weekStartName(6) === 'Saturday');
ok('weekStartName() shows a broken value as it is', weekStartName(9) === '9');

ok('formatAccountDate() renders the long form', formatAccountDate('2026-03-12 10:00:00') === '12 March 2026');
ok('formatAccountDate() is empty for nothing', formatAccountDate('') === '');

// --- A9. The forms ---

$forms = [];
if (preg_match_all('/<form\b.*?<\/form>/is', $src['views/settings.php'], $matches) === 1 || !empty($matches[0])) {
    $forms = $matches[0];
}

ok('the screen has forms', count($forms) === 3, (string) count($forms) . ' forms');

$withoutToken = 0;

foreach ($forms as $form) {
    if (!str_contains($form, 'name="csrf_token"')) {
        $withoutToken++;
    }
}

ok('every form carries a CSRF token', $withoutToken === 0, (string) $withoutToken . ' forms without a token');

ok(
    'the profile form posts to the profile handler',
    preg_match('/<form[^>]*method="post"[^>]*action="\/settings\/profile"/i', $src['views/settings.php']) === 1
);

ok(
    'the password form posts to the password handler',
    preg_match('/<form[^>]*method="post"[^>]*action="\/settings\/password"/i', $src['views/settings.php']) === 1
);

ok(
    'the sign-out form posts to the logout handler',
    preg_match('/<form[^>]*method="post"[^>]*action="\/logout"/i', $src['views/settings.php']) === 1
);

ok(
    'the sign-out button is a secondary button',
    preg_match('/action="\/logout".*?type="submit"[^>]*class="btn btn--secondary"/is', $src['views/settings.php']) === 1
);

ok(
    'the profile fields carry their autocomplete',
    str_contains($src['views/settings.php'], 'autocomplete="username"')
    && str_contains($src['views/settings.php'], 'autocomplete="email"')
);

ok(
    'the password fields carry their autocomplete',
    str_contains($src['views/settings.php'], 'autocomplete="current-password"')
    && substr_count($src['views/settings.php'], 'autocomplete="new-password"') === 2
);

// --- A10. The installation card ---

$installation = '';
if (preg_match('/<dl class="readonly-list">.*?<\/dl>/is', $src['views/settings.php'], $match) === 1) {
    $installation = $match[0];
}

$rows = [];
if (preg_match_all('/<dt>([^<]*)<\/dt>/', $installation, $matches) === 1 || !empty($matches[1])) {
    $rows = $matches[1];
}

$expectedRows = [
    'Database name',
    'Database user',
    'SMTP host',
    'SMTP port',
    'SMTP user',
    'Registration allowed',
    'Week start',
];

ok('the installation card has seven rows', count($rows) === 7, (string) count($rows) . ' rows');
ok('the installation rows are the seven of the specification', $rows === $expectedRows, implode(', ', $rows));

ok(
    'the installation card has no input',
    !preg_match('/<input|<button|<select|<textarea/i', $installation)
);

ok(
    'the installation card is a definition list',
    str_contains($src['views/settings.php'], '<dl class="readonly-list">')
);

// --- A11. The stylesheet ---

$css = $src['assets/css/pages/settings.css'];
ok('the stylesheet has no literal colour', preg_match('/#[0-9a-fA-F]{3,8}\b/', $css) !== 1);
ok('the stylesheet has no rgb() or hsl() colour', preg_match('/\b(rgb|rgba|hsl|hsla)\(/i', $css) !== 1);
ok('the stylesheet uses the tokens', str_contains($css, 'var(--'));
ok('the stylesheet uses no token that does not exist', !preg_match('/var\(--sp-7\)/', $css));

// ---------------------------------------------------------------------------
// B. The behaviour tier
// ---------------------------------------------------------------------------

echo "\n## B. The sandbox\n";

$server = null;
$db     = null;

try {
    $db = st_create_scratch_database($root);
} catch (Throwable $ex) {
    $db = null;
}

if ($db === null) {
    ok('the scratch database is created', false, 'the database server did not answer');
} else {
    ok('the scratch database is created', true);

    $sandboxRoot = st_make_sandbox($root, $db);
    $pdo = $db['pdo'];

    $demoUserId  = st_seed_user($pdo, $demoUser, $demoUser . '@example.test', $demoPassword);
    $otherUserId = st_seed_user($pdo, $otherUser, $otherUser . '@example.test', $otherPassword);

    $server = st_start_server($sandboxRoot);
    $base = $server['base'];

    // --- B1. The guard ---

    st_reset_cookies();
    $response = st_http_request('GET', $base . '/settings');
    ok('a guest is sent away from the settings', $response['status'] === 302, (string) $response['status']);

    st_reset_cookies();
    $response = st_http_request('POST', $base . '/settings/profile', [
        'username' => 'sneaky',
        'email'    => 'sneaky@example.test',
    ]);
    ok('a guest cannot save a profile', $response['status'] === 302, (string) $response['status']);

    st_reset_cookies();
    $response = st_http_request('POST', $base . '/settings/password', [
        'current_password'      => $demoPassword,
        'password'              => 'AnotherPassword9!',
        'password_confirmation' => 'AnotherPassword9!',
    ]);
    ok('a guest cannot change a password', $response['status'] === 302, (string) $response['status']);

    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $statement->execute([$demoUserId]);
    $storedHash = (string) $statement->fetchColumn();
    ok('the refused change left the hash alone', password_verify($demoPassword, $storedHash));

    // --- B2. The sign-in ---

    st_reset_cookies();
    $loginPage = st_http_request('GET', $base . '/login');
    $token = st_csrf_of($loginPage['body']);
    $response = st_http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $demoUser,
        'password'   => $demoPassword,
    ]);
    ok('the sign-in succeeds', $response['status'] === 302, (string) $response['status']);

    // --- B3. The screen ---

    $response = st_http_request('GET', $base . '/settings');
    $body = $response['body'];

    ok('the settings screen answers 200', $response['status'] === 200, (string) $response['status']);
    ok('the screen has the heading', str_contains($body, '<h1 class="page__title">Settings</h1>'));
    ok(
        'the screen has the subtitle',
        str_contains($body, 'Your account, your password and a read-only view of this installation.')
    );

    $cards = [];
    if (preg_match_all('/<h2>([^<]*)<\/h2>/', $body, $matches) === 1 || !empty($matches[1])) {
        $cards = $matches[1];
    }

    ok(
        'the screen is four cards in order',
        $cards === ['Profile', 'Change password', 'Session', 'Installation'],
        implode(', ', $cards)
    );

    ok('the session card names the visitor', str_contains($body, 'You are signed in as <strong>demo</strong>'));

    // The installation rows.
    $installation = '';
    if (preg_match('/<dl class="readonly-list">.*?<\/dl>/is', $body, $match) === 1) {
        $installation = $match[0];
    }

    ok('the installation card shows the database name', str_contains($installation, 'ft_set_test'));
    ok('the installation card shows the database user', str_contains($installation, 'root'));
    ok('the installation card shows the SMTP port', str_contains($installation, '>587<'));
    ok('the installation card shows the registration flag as Yes', str_contains($installation, '>Yes<'));
    ok('the installation card shows the week start', str_contains($installation, '>Monday<'));
    ok('the installation card shows an em dash for an empty SMTP host', str_contains($installation, "\u{2014}"));
    ok('the installation card shows no password row', !str_contains(strtolower($installation), 'password'));

    // The secrets are absent from the page altogether.
    ok('the database password is absent from the page', !str_contains($body, $db['pass']));
    ok('the page never says DB_PASS', !str_contains($body, 'DB_PASS'));
    ok('the page never says SMTP_PASS', !str_contains($body, 'SMTP_PASS'));
    ok('the page never says password_hash', !str_contains($body, 'password_hash'));

    // The token rotates on the sign-in, so it is read again here.
    $token = st_csrf_of($body);
    ok('the screen carries a CSRF token', $token !== '');

    // --- B4. The profile ---

    // Saving the profile without changing anything is not a conflict.
    $response = st_http_request('POST', $base . '/settings/profile', [
        'csrf_token' => $token,
        'username'   => $demoUser,
        'email'      => $demoUser . '@example.test',
    ]);
    ok('an unchanged profile redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the unchanged profile is saved', str_contains($response['body'], 'Your profile was saved.'));
    ok('the unchanged profile is not a conflict', !str_contains($response['body'], 'already taken'));

    // A real change.
    $response = st_http_request('POST', $base . '/settings/profile', [
        'csrf_token' => $token,
        'username'   => 'demo-renamed',
        'email'      => 'Demo.Renamed@Example.test',
    ]);
    ok('a valid profile save redirects', $response['status'] === 302, (string) $response['status']);

    $statement = $pdo->prepare('SELECT username, email FROM users WHERE id = ?');
    $statement->execute([$demoUserId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    ok('the username was updated', is_array($row) && $row['username'] === 'demo-renamed');
    ok('the email was lowercased', is_array($row) && $row['email'] === 'demo.renamed@example.test');

    $response = st_http_request('GET', $base . '/settings');
    ok('the profile save is confirmed', str_contains($response['body'], 'Your profile was saved.'));

    // A duplicate username — the other visitor's name.
    $response = st_http_request('POST', $base . '/settings/profile', [
        'csrf_token' => $token,
        'username'   => $otherUser,
        'email'      => 'demo.renamed@example.test',
    ]);
    ok('a duplicate username redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the duplicate username is refused', str_contains($response['body'], 'That username is already taken.'));
    ok(
        'the duplicate username does not reject the email',
        !str_contains($response['body'], 'That email address is already registered.')
    );
    ok('the refused profile keeps the typed name', str_contains($response['body'], 'value="other"'));

    // A duplicate email — the other visitor's address.
    $response = st_http_request('POST', $base . '/settings/profile', [
        'csrf_token' => $token,
        'username'   => 'demo-renamed',
        'email'      => $otherUser . '@example.test',
    ]);
    ok('a duplicate email redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the duplicate email is refused', str_contains($response['body'], 'That email address is already registered.'));
    ok(
        'the duplicate email does not reject the username',
        !str_contains($response['body'], 'That username is already taken.')
    );

    // An invalid username.
    $response = st_http_request('POST', $base . '/settings/profile', [
        'csrf_token' => $token,
        'username'   => 'a',
        'email'      => 'demo.renamed@example.test',
    ]);
    ok('an invalid username redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok(
        'the invalid username is refused',
        str_contains($response['body'], 'The username must be at least 3 characters long.')
    );

    // An invalid email.
    $response = st_http_request('POST', $base . '/settings/profile', [
        'csrf_token' => $token,
        'username'   => 'demo-renamed',
        'email'      => 'not-an-email',
    ]);
    ok('an invalid email redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the invalid email is refused', str_contains($response['body'], 'Enter a valid email address.'));

    // A profile save without the token.
    $response = st_http_request('POST', $base . '/settings/profile', [
        'username' => 'demo-renamed',
        'email'    => 'demo.renamed@example.test',
    ]);
    ok('a profile save without the token is refused', $response['status'] === 403, (string) $response['status']);

    // The account is untouched by all of that.
    $statement = $pdo->prepare('SELECT username, email FROM users WHERE id = ?');
    $statement->execute([$demoUserId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    ok('the account still holds the saved profile', is_array($row) && $row['username'] === 'demo-renamed');

    // --- B5. The change-password form ---

    // The wrong current password.
    $response = st_http_request('POST', $base . '/settings/password', [
        'csrf_token'           => $token,
        'current_password'      => 'TheWrongPassword9!',
        'password'              => 'AnotherPassword9!',
        'password_confirmation' => 'AnotherPassword9!',
    ]);
    ok('a wrong current password redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok(
        'the wrong current password is refused',
        str_contains($response['body'], 'Your current password is not correct.')
    );

    // The two new passwords do not match.
    $response = st_http_request('POST', $base . '/settings/password', [
        'csrf_token'           => $token,
        'current_password'      => $demoPassword,
        'password'              => 'AnotherPassword9!',
        'password_confirmation' => 'SomethingElse9!',
    ]);
    ok('a mismatch redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the mismatch is refused', str_contains($response['body'], 'The two passwords do not match.'));

    // The new password is too short.
    $response = st_http_request('POST', $base . '/settings/password', [
        'csrf_token'           => $token,
        'current_password'      => $demoPassword,
        'password'              => 'short',
        'password_confirmation' => 'short',
    ]);
    ok('a short password redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok(
        'the short password is refused',
        str_contains($response['body'], 'The password must be at least 10 characters long.')
    );

    // The hash has not moved yet.
    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $statement->execute([$demoUserId]);
    ok('the refused changes left the hash alone', password_verify($demoPassword, (string) $statement->fetchColumn()));

    // A password change without the token.
    $response = st_http_request('POST', $base . '/settings/password', [
        'current_password'      => $demoPassword,
        'password'              => 'AnotherPassword9!',
        'password_confirmation' => 'AnotherPassword9!',
    ]);
    ok('a password change without the token is refused', $response['status'] === 403, (string) $response['status']);

    // --- B6. The second session ---

    // A second session of the same account, kept aside. A password change
    // has to end it — and keep the current one.
    //
    // The account was renamed in B4, so the new name is the one that
    // signs in from here on.
    $renamedUser = 'demo-renamed';

    $firstJar = st_cookies();

    st_reset_cookies();
    $loginPage = st_http_request('GET', $base . '/login');
    $token2 = st_csrf_of($loginPage['body']);
    $response = st_http_request('POST', $base . '/login', [
        'csrf_token' => $token2,
        'username'   => $renamedUser,
        'password'   => $demoPassword,
    ]);
    ok('the second sign-in succeeds', $response['status'] === 302, (string) $response['status']);

    $secondJar = st_cookies();

    // Back to the first session.
    st_set_cookies($firstJar);
    $response = st_http_request('GET', $base . '/settings');
    ok('the first session is still signed in', $response['status'] === 200, (string) $response['status']);

    // --- B7. The change ---

    $response = st_http_request('POST', $base . '/settings/password', [
        'csrf_token'           => $token,
        'current_password'      => $demoPassword,
        'password'              => 'AnotherPassword9!',
        'password_confirmation' => 'AnotherPassword9!',
    ]);
    ok('a valid password change redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the password change is confirmed', str_contains($response['body'], 'Your password was changed.'));
    ok('the confirmation names the next sign-in', str_contains($response['body'], 'Use it the next time you sign in.'));

    // No password lingers in the page.
    ok('the page carries no password value', !str_contains($response['body'], 'AnotherPassword9!'));
    ok(
        'the password fields are empty',
        preg_match('/name="password"[^>]*value="[^"]+"/', $response['body']) !== 1
        && preg_match('/name="current_password"[^>]*value="[^"]+"/', $response['body']) !== 1
    );

    // The session survived its own password change.
    ok('the session survives the change', $response['status'] === 200, (string) $response['status']);

    // The hash moved.
    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $statement->execute([$demoUserId]);
    $newHash = (string) $statement->fetchColumn();
    ok('the new password is stored', password_verify('AnotherPassword9!', $newHash));
    ok('the old password is gone', !password_verify($demoPassword, $newHash));

    // --- B8. The other session ---

    // The second session still holds the OLD credential version, so the
    // front controller ends it.
    st_set_cookies($secondJar);
    $response = st_http_request('GET', $base . '/settings');
    ok('the other session was ended by the change', $response['status'] === 302, (string) $response['status']);

    // The current session is still the current one.
    st_set_cookies($firstJar);
    $response = st_http_request('GET', $base . '/settings');
    ok('the current session survived the change', $response['status'] === 200, (string) $response['status']);

    // The new password signs in.
    st_reset_cookies();
    $loginPage = st_http_request('GET', $base . '/login');
    $token3 = st_csrf_of($loginPage['body']);
    $response = st_http_request('POST', $base . '/login', [
        'csrf_token' => $token3,
        'username'   => $renamedUser,
        'password'   => 'AnotherPassword9!',
    ]);
    ok('the new password signs in', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the new password opens the screen', $response['status'] === 200, (string) $response['status']);

    // The old password does not.
    st_reset_cookies();
    $loginPage = st_http_request('GET', $base . '/login');
    $token4 = st_csrf_of($loginPage['body']);
    $response = st_http_request('POST', $base . '/login', [
        'csrf_token' => $token4,
        'username'   => $renamedUser,
        'password'   => $demoPassword,
    ]);
    ok(
        'the old password does not sign in',
        $response['status'] === 200 && str_contains($response['body'], 'do not match'),
        (string) $response['status']
    );

    $response = st_http_request('GET', $base . '/settings');
    ok('the old password leaves the visitor signed out', $response['status'] === 302, (string) $response['status']);

    // --- B9. The password is not trimmed ---

    st_reset_cookies();
    $loginPage = st_http_request('GET', $base . '/login');
    $token5 = st_csrf_of($loginPage['body']);
    st_http_request('POST', $base . '/login', [
        'csrf_token' => $token5,
        'username'   => $renamedUser,
        'password'   => 'AnotherPassword9!',
    ]);

    $response = st_http_request('GET', $base . '/settings');
    $token = st_csrf_of($response['body']);

    $spaced = 'AnotherPassword9! ';

    $response = st_http_request('POST', $base . '/settings/password', [
        'csrf_token'           => $token,
        'current_password'      => 'AnotherPassword9!',
        'password'              => $spaced,
        'password_confirmation' => $spaced,
    ]);
    ok('a password with a trailing space redirects', $response['status'] === 302, (string) $response['status']);

    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $statement->execute([$demoUserId]);
    $spacedHash = (string) $statement->fetchColumn();

    ok('the trailing space is part of the password', password_verify($spaced, $spacedHash));
    ok('the trimmed password is not the one', !password_verify('AnotherPassword9!', $spacedHash));

    // --- B10. The sign-out ---

    $response = st_http_request('GET', $base . '/settings');
    $token = st_csrf_of($response['body']);

    $response = st_http_request('POST', $base . '/logout', ['csrf_token' => $token]);
    ok('the sign-out redirects', $response['status'] === 302, (string) $response['status']);

    $response = st_http_request('GET', $base . '/settings');
    ok('the sign-out ended the session', $response['status'] === 302, (string) $response['status']);
}

// ---------------------------------------------------------------------------
// The teardown
// ---------------------------------------------------------------------------

echo "\n## Teardown\n";

if ($server !== null) {
    st_stop_server($server);
    ok('the server is stopped', true);
}

if ($sandboxRoot !== '' && is_dir($sandboxRoot)) {
    st_remove_tree($sandboxRoot);
    ok('the sandbox is removed', !is_dir($sandboxRoot));
}

st_drop_scratch_database($root);
ok('the scratch database is dropped', true);

// ---------------------------------------------------------------------------
// The summary
// ---------------------------------------------------------------------------

$failed = count($failures);

echo "\nFailed: " . $failed . "\n";

if ($failed > 0) {
    echo "\nThe failures:\n";

    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }

    exit(1);
}

exit(0);
