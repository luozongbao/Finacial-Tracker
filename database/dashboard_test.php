<?php
/**
 * dashboard_test.php — guards the dashboard (issue-017).
 *
 * The dashboard is the first screen that shows a visitor somebody else's
 * money — well, their OWN money, which is the same thing with a different
 * failure mode. The things that are pinned here are the ones that would be
 * expensive to get wrong:
 *
 *   1. THE GUARD COMES FIRST. requireLogin() is the first call of the view.
 *      An unauthenticated visitor reaches no query at all.
 *   2. THE SUMS ARE COMPUTED IN SQL. The net is the difference of the two
 *      sides, and the database does the subtraction in DECIMAL. Two decimal
 *      strings added in PHP become floats, and 0.1 + 0.2 !== 0.3.
 *   3. THE ERROR IS NEVER 0.00. A zero is a claim about the data; a failure
 *      is a claim about the request. They must never look alike.
 *   4. THE TWO EMPTY STATES ARE DIFFERENT. "No transactions yet" and "No
 *      transactions this week." are different sentences, and the browser
 *      never infers which one to show.
 *   5. OWNERSHIP IS IN THE SQL. Every statement filters on the user id, and
 *      the id comes from the session — never from the query string.
 *   6. THE SIGNS ARE GLYPHS. The income carries "+", the spending carries
 *      the typographic minus (U+2212), and the net carries no sign at all
 *      when it is exactly zero.
 *
 * Two tiers:
 *
 *   - The file tier (§A) always runs. It needs no database and no web
 *     server — it reads the sources and it exercises the shared helpers
 *     directly.
 *   - The behaviour tier (§B) runs the REAL screen in a sandbox: a copy of
 *     the application in a temporary directory, served by the PHP built-in
 *     server, against a scratch database.
 *
 * Run:  docker compose exec -T app php database/dashboard_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by
 * the output.
 */

declare(strict_types=1);

$failures = [];

/**
 * The cookie jar of the test. The CSRF token and the session live in it, so
 * the requests have to carry the cookie from one to the next.
 */
$GLOBALS['dt_cookies'] = [];

/**
 * The password of the seeded accounts.
 */
const DT_PASSWORD = 'a-good-long-password';

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

/**
 * Forget the cookies of the jar — a fresh visitor.
 */
function reset_cookies(): void
{
    $GLOBALS['dt_cookies'] = [];
}

/**
 * Perform an HTTP request and return the status, the body and the headers.
 *
 * The session cookie is carried from one request to the next: the CSRF
 * token and the session state live in it, so a request without the cookie
 * would start a new session every time.
 */
function http_request(string $method, string $url, array $fields = [], array $extraHeaders = [], int $timeout = 30): array
{
    $headers = [
        'User-Agent: financial-tracker-dashboard-test',
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

    $jar = $GLOBALS['dt_cookies'] ?? [];

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
            unset($GLOBALS['dt_cookies'][$name]);
            continue;
        }

        $GLOBALS['dt_cookies'][$name] = $value;
    }

    return [
        'status'     => $status,
        'body'       => $response === false ? '' : $response,
        'headers'    => $respHeaders,
        'set_cookie' => $setCookies,
    ];
}

/**
 * The CSRF token of a rendered page, or an empty string.
 */
function csrf_of(string $body): string
{
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * The position of a needle in a haystack, or -1.
 */
function pos_of(string $haystack, string $needle): int
{
    $pos = strpos($haystack, $needle);

    return $pos === false ? -1 : $pos;
}

/**
 * The documented shape of a date range, restated from the specification
 * and not read from the application helper.
 *
 * A range inside one month reads "19 – 25 October 2026", a range inside
 * one year reads "28 October – 3 November 2026" and a range across two
 * years keeps both years. The joiner is a dash in a label and a word in
 * a sentence.
 */
function expected_span(string $from, string $to, string $joiner = '–'): string
{
    $a = new DateTimeImmutable($from . ' UTC');
    $b = new DateTimeImmutable($to . ' UTC');

    if ($a->format('Y-m') === $b->format('Y-m')) {
        return $a->format('j') . ' ' . $joiner . ' ' . $b->format('j') . ' ' . $b->format('F Y');
    }

    if ($a->format('Y') === $b->format('Y')) {
        return $a->format('j F') . ' ' . $joiner . ' ' . $b->format('j F') . ' ' . $b->format('Y');
    }

    return $a->format('j F Y') . ' ' . $joiner . ' ' . $b->format('j F Y');
}

/**
 * The source without its comments.
 *
 * A rule about the CODE must not be satisfied by a sentence in a docblock.
 */
function strip_comments(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src) ?? $src;
    $src = preg_replace('#(^|[\s;(])//[^\n]*#', '$1', $src) ?? $src;

    return $src;
}

/**
 * Split a conditional expression into its three parts.
 *
 * Returns null when the expression is not a conditional. The scanner skips
 * the quoted strings, so a "?" inside a literal does not confuse it.
 *
 * @return array{0: string, 1: string, 2: string}|null
 */
function split_ternary(string $expr): ?array
{
    $length = strlen($expr);

    $scan = static function (string $text, int $from, string $stopAt) use ($length): ?int {
        $inSingle = false;
        $inDouble = false;
        $depth    = 0;

        for ($i = $from; $i < $length; $i++) {
            $ch = $text[$i];

            if ($inSingle) {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }

                if ($ch === "'") {
                    $inSingle = false;
                }

                continue;
            }

            if ($inDouble) {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }

                if ($ch === '"') {
                    $inDouble = false;
                }

                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                continue;
            }

            if ($ch === '"') {
                $inDouble = true;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                continue;
            }

            if ($ch === ')') {
                $depth--;
                continue;
            }

            if ($depth === 0 && $ch === $stopAt) {
                return $i;
            }
        }

        return null;
    };

    $question = $scan($expr, 0, '?');

    if ($question === null) {
        return null;
    }

    $colon = $scan($expr, $question + 1, ':');

    if ($colon === null) {
        return null;
    }

    return [
        substr($expr, 0, $question),
        trim(substr($expr, $question + 1, $colon - $question - 1)),
        trim(substr($expr, $colon + 1)),
    ];
}

/**
 * Is the expression a constant — a literal, or a concatenation of literals?
 */
function is_literal_expression(string $expr): bool
{
    $expr = trim($expr);

    if ($expr === '') {
        return false;
    }

    $literal = '(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';

    if (preg_match('/^' . $literal . '$/', $expr) === 1) {
        return true;
    }

    return preg_match('/^' . $literal . '(?:\s*\.\s*' . $literal . ')*$/', $expr) === 1;
}

/**
 * The dynamic values a view prints without escaping.
 *
 * The rule is simple: everything dynamic goes through e(). The one
 * exception is a conditional whose two branches are constants — it prints a
 * literal, and a literal cannot carry an injection.
 */
function unescaped_outputs(string $src): array
{
    $bad = [];

    if (preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $src, $m) !== 1 && empty($m[0])) {
        return $bad;
    }

    foreach ($m[1] as $expression) {
        $expression = trim($expression);

        if (str_starts_with($expression, 'e(')) {
            continue;
        }

        $parts = split_ternary($expression);

        if (
            $parts !== null
            && is_literal_expression($parts[1])
            && is_literal_expression($parts[2])
        ) {
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
function env_values(string $root): array
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
function free_port(): int
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
function start_server(string $docroot): array
{
    $port = free_port();

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
        $res = http_request('GET', $base . '/__ping__', [], [], 2);

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
function stop_server(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc'], 15);
        proc_close($server['proc']);
    }
}

/**
 * Remove a directory tree.
 */
function remove_tree(string $dir): void
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
            remove_tree($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

/**
 * Build the sandbox: a copy of the application in a temporary directory.
 */
function make_sandbox(string $root, array $db): string
{
    $dir = sys_get_temp_dir() . '/ft_dash_' . bin2hex(random_bytes(5));

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
$_SERVER['SERVER_NAME'] = 'dash.test';

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
function create_scratch_database(string $root): ?array
{
    $env  = env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return null;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_dash_test`');
        $pdo->exec('CREATE DATABASE `ft_dash_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `ft_dash_test`');

        $schema = (string) file_get_contents($root . '/database/schema.sql');
        $pdo->exec($schema);
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be prepared: " . $ex->getMessage() . "\n";

        return null;
    }

    return [
        'host' => $host,
        'name' => 'ft_dash_test',
        'user' => 'root',
        'pass' => $pass,
        'pdo'  => $pdo,
    ];
}

/**
 * Drop the scratch database.
 */
function drop_scratch_database(string $root): void
{
    $env  = env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `ft_dash_test`');
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be dropped: " . $ex->getMessage() . "\n";
    }
}

/**
 * Seed an account and return its id.
 */
function seed_user(PDO $pdo, string $username, string $email, string $password): int
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
function seed_category(PDO $pdo, int $userId, string $name, string $type): int
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
function seed_transaction(
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
 * Sign in and keep the session cookie.
 */
function sign_in(string $base, string $username, string $password): array
{
    reset_cookies();

    $res   = http_request('GET', $base . '/login');
    $token = csrf_of($res['body']);

    return http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => $username,
        'password'   => $password,
    ]);
}

// ---------------------------------------------------------------------------
// The sources.
// ---------------------------------------------------------------------------

$root = realpath(__DIR__ . '/..');

$viewPath = $root . '/views/dashboard.php';

if (!is_file($viewPath)) {
    echo "  [FAIL] views/dashboard.php exists\n";
    $failures[] = 'views/dashboard.php exists';
    echo "\nFailed: " . count($failures) . "\n";
    exit(1);
}

$view = (string) file_get_contents($viewPath);
$code = strip_comments($view);

require_once $root . '/includes/functions.php';

$functions = (string) file_get_contents($root . '/includes/functions.php');
$script    = (string) file_get_contents($root . '/assets/js/app.js');
$cssPath   = $root . '/assets/css/pages/dashboard.css';
$css       = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

// ---------------------------------------------------------------------------
// §A. The file tier.
// ---------------------------------------------------------------------------

echo "\n## A1. The view and the guard\n";

ok('the view is not empty', trim($view) !== '');

ok(
    'the view requires the shell',
    str_contains($view, "require __DIR__ . '/../includes/header.php'")
        && str_contains($view, "require __DIR__ . '/../includes/footer.php'")
);

$guardPos = pos_of($code, 'requireLogin(');
$queryPos = pos_of($code, 'Database::getInstance');
$userPos  = pos_of($code, 'getCurrentUser(');

ok('the view calls requireLogin()', $guardPos !== -1);

ok(
    'requireLogin() is the FIRST call of the view',
    $guardPos !== -1
        && ($queryPos === -1 || $guardPos < $queryPos)
        && ($userPos === -1 || $guardPos < $userPos),
    'guard=' . $guardPos . ' query=' . $queryPos . ' user=' . $userPos
);

ok(
    'the view never reads the request directly',
    !str_contains($code, '$_GET') && !str_contains($code, '$_POST'),
    'the superglobals are in the view'
);

ok(
    'the view never reads a user id from the request',
    !str_contains($code, "query('user_id") && !str_contains($code, "post('user_id")
        && !str_contains($code, "query('uid") && !str_contains($code, "post('uid")
);

ok(
    'the user id comes from the session',
    str_contains($code, 'getCurrentUserId()')
);

echo "\n## A2. The period\n";

ok(
    'the period is normalised and defaults to the month',
    str_contains($code, "normalizePeriod(query('period', 'month'))")
);

ok(
    'the range comes from resolvePeriodRange()',
    str_contains($code, 'resolvePeriodRange(')
);

ok(
    'the view does not build a date range by hand',
    !str_contains($code, "date('Y-m-d')") && !str_contains($code, "gmdate('Y-m-d')")
);

ok(
    'the label comes from formatPeriodLabel()',
    str_contains($code, 'formatPeriodLabel(')
);

ok(
    'the period selector carries the group and the five links',
    str_contains($code, 'data-period-group=')
        && substr_count($code, 'class="period__link"') === 5
);

ok(
    'the active period is marked with aria-current',
    str_contains($code, 'aria-current="true"')
);

echo "\n## A3. The money\n";

ok(
    'the sums are computed in SQL',
    str_contains($code, 'SUM(CASE WHEN t.type') && str_contains($code, 'COALESCE(SUM(')
);

ok(
    'the net is computed in SQL',
    preg_match('/SUM\(CASE WHEN t\.type = \'income\'\s+THEN t\.amount\s+WHEN t\.type = \'expense\' THEN -t\.amount END\)/', $code) === 1
);

ok(
    'the sign of the net comes from the SQL',
    str_contains($code, 'net_sign') && str_contains($code, 'SIGN(')
);

ok(
    'the view does no arithmetic on money',
    !str_contains($code, 'array_sum')
        && !preg_match('/\$summary\[[^\]]+\]\s*[-+]\s*\$summary/', $code),
    'the view adds the two sides up'
);

ok(
    'the amounts are formatted by the shared helper',
    str_contains($code, 'formatSignedMoney(') && !str_contains($code, 'number_format(')
);

ok(
    'the spending carries the typographic minus (U+2212)',
    str_contains($view, "\u{2212}")
);

ok(
    'the income carries the ASCII plus',
    str_contains($view, "'+'")
);

echo "\n## A4. The ownership\n";

$ownershipFilters = substr_count($code, 't.user_id = ?');

ok(
    'every statement filters on the owning user id',
    $ownershipFilters >= 4,
    'the filter appears ' . $ownershipFilters . ' times'
);

ok(
    'the ownership filter is in every statement',
    substr_count($code, 'FROM transactions t') === $ownershipFilters
);

echo "\n## A5. The recent list\n";

ok(
    'the recent list is a LEFT JOIN',
    str_contains($code, 'LEFT JOIN categories')
);

ok(
    'the recent list is capped in the query',
    str_contains($code, 'LIMIT 10')
);

ok(
    'the recent list is newest first',
    str_contains($code, 'ORDER BY t.occurred_on DESC, t.id DESC')
);

ok(
    'the recent list is not filtered by the period',
    preg_match('/recentSql\s*=(.*?);/s', $code, $m) === 1
        && !str_contains($m[1], 'occurred_on BETWEEN'),
    'the recent query filters on the period'
);

ok(
    'the "View all transactions" link ends with the arrow (U+2192)',
    str_contains($view, 'View all transactions') && str_contains($view, "\u{2192}")
);

ok(
    'the link points at /transactions',
    str_contains($view, 'href="/transactions"')
);

ok(
    'the uncategorised badge is in the view',
    str_contains($view, 'Uncategorised')
);

echo "\n## A6. The states\n";

ok(
    'the error state is announced',
    str_contains($code, 'role="alert"')
);

ok(
    'the error title is the mockup string',
    str_contains($view, 'The summary could not be loaded.')
        && str_contains($view, 'Please try again.')
);

ok(
    'the error state says "No summary available", not "No transactions"',
    str_contains($view, 'No summary available')
        && str_contains($view, 'The numbers could not be retrieved, so nothing is shown here.')
);

ok(
    'the raw error goes to the error log',
    str_contains($code, 'error_log(')
);

ok(
    'the first empty state is the account one',
    str_contains($view, 'No transactions yet')
        && str_contains($view, 'Record your first entry to start tracking this period.')
);

ok(
    'the second empty state names the period and the range',
    str_contains($view, 'No transactions ')
        && str_contains($view, 'Nothing was recorded between ')
);

ok(
    'the empty states are chosen on the server',
    str_contains($code, 'SELECT 1') && str_contains($code, 'LIMIT 1')
);

ok(
    'the try again button is a button, not a link',
    str_contains($code, '<button type="button" class="btn btn--secondary" data-reload>Try again</button>')
);

echo "\n## A7. The output\n";

$unescaped = unescaped_outputs($view);

ok('every dynamic value is escaped with e()', $unescaped === [], implode(' | ', $unescaped));

ok(
    'the view has no inline script',
    !str_contains(strtolower($view), '<script')
);

ok(
    'the view has no literal colour',
    preg_match('/#[0-9a-f]{3,8}\b/i', $code) !== 1
);

echo "\n## A8. The stylesheet and the script\n";

ok('the page stylesheet exists', $css !== '');

ok(
    'the stylesheet uses the tokens only',
    preg_match('/#[0-9a-f]{3,8}\b/i', $css) !== 1
);

ok(
    'the script reloads the page for the try-again button',
    str_contains($script, 'data-reload') && str_contains($script, 'initReload')
);

echo "\n## A9. The shared helpers\n";

ok(
    'formatSignedMoney() prepends the plus',
    formatSignedMoney('158.20', '+') === '+158.20',
    formatSignedMoney('158.20', '+')
);

ok(
    'formatSignedMoney() prepends the typographic minus',
    formatSignedMoney('158.20', "\u{2212}") === "\u{2212}158.20",
    formatSignedMoney('158.20', "\u{2212}")
);

ok(
    'formatSignedMoney() strips a negative sign from the value',
    formatSignedMoney('-158.20', "\u{2212}") === "\u{2212}158.20",
    formatSignedMoney('-158.20', "\u{2212}")
);

ok(
    'formatSignedMoney() gives a zero no sign',
    formatSignedMoney('0.00', '+') === '0.00' && formatSignedMoney('0', "\u{2212}") === '0.00',
    formatSignedMoney('0.00', '+')
);

ok(
    'formatSignedMoney() keeps the thousands separator',
    formatSignedMoney('1200.00', '+') === '+1,200.00',
    formatSignedMoney('1200.00', '+')
);

ok(
    'isZeroAmount() recognises a zero',
    isZeroAmount('0') && isZeroAmount('0.00') && isZeroAmount('0.000000') && isZeroAmount('-0.00')
);

ok(
    'isZeroAmount() rejects a non-zero and an empty value',
    !isZeroAmount('0.01') && !isZeroAmount('') && !isZeroAmount('10')
);

ok(
    'formatPeriodLabel() renders a day',
    formatPeriodLabel('2026-10-24', '2026-10-24', 'day') === '24 October 2026',
    formatPeriodLabel('2026-10-24', '2026-10-24', 'day')
);

ok(
    'formatPeriodLabel() renders a month',
    formatPeriodLabel('2026-10-01', '2026-10-31', 'month') === 'October 2026',
    formatPeriodLabel('2026-10-01', '2026-10-31', 'month')
);

ok(
    'formatPeriodLabel() renders a year',
    formatPeriodLabel('2026-01-01', '2026-12-31', 'year') === '2026',
    formatPeriodLabel('2026-01-01', '2026-12-31', 'year')
);

ok(
    'formatPeriodLabel() renders a week as a span with an en dash',
    formatPeriodLabel('2026-10-19', '2026-10-25', 'week') === '19 – 25 October 2026',
    formatPeriodLabel('2026-10-19', '2026-10-25', 'week')
);

ok(
    'formatPeriodLabel() renders a custom range as a span',
    formatPeriodLabel('2026-10-01', '2026-10-31', 'custom') === '1 – 31 October 2026',
    formatPeriodLabel('2026-10-01', '2026-10-31', 'custom')
);

ok(
    'a range that straddles two months is not called a month',
    formatPeriodLabel('2026-10-28', '2026-11-03', 'custom') === '28 October – 3 November 2026',
    formatPeriodLabel('2026-10-28', '2026-11-03', 'custom')
);

ok(
    'a range that straddles two years keeps both years',
    formatPeriodLabel('2026-12-20', '2027-01-03', 'custom') === '20 December 2026 – 3 January 2027',
    formatPeriodLabel('2026-12-20', '2027-01-03', 'custom')
);

ok(
    'the span can be written for a sentence',
    formatPeriodSpan('2026-10-19', '2026-10-25', 'and') === '19 and 25 October 2026',
    formatPeriodSpan('2026-10-19', '2026-10-25', 'and')
);

// ---------------------------------------------------------------------------
// §B. The behaviour tier.
// ---------------------------------------------------------------------------

echo "\n## B. The behaviour tier\n";

$db = create_scratch_database($root);

if ($db === null) {
    echo "  [WARN] the behaviour tier was skipped — no scratch database\n";
} else {
    $pdo = $db['pdo'];

    // The dates the fixtures hang on. The month and the week are the ones
    // the screen computes for itself, so they are derived from the same
    // clock rather than hard-coded.
    //
    // The clock is UTC and the arithmetic is done with DateTimeImmutable,
    // because the container's own time zone is not necessarily UTC and a
    // date that slips over midnight is a test that fails on one night.
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $today      = $now->format('Y-m-d');
    $monthStart = $now->format('Y-m-01');
    $monthEnd   = $now->format('Y-m-t');
    $monthName  = $now->format('F Y');

    // Forty days back is always outside the current month, so it is a
    // transaction the period must NOT count and the recent list MUST show.
    $outside = $now->modify('-40 days')->format('Y-m-d');

    // The current week, Monday to Sunday — the same rule the application
    // uses.
    $weekday  = (int) $now->format('N');
    $offset   = ($weekday - 1 + 7) % 7;
    $weekFrom = $now->modify('-' . $offset . ' days');
    $weekTo   = $now->modify('+' . (6 - $offset) . ' days');

    $weekLabel  = expected_span($weekFrom->format('Y-m-d'), $weekTo->format('Y-m-d'));
    $weekPhrase = expected_span($weekFrom->format('Y-m-d'), $weekTo->format('Y-m-d'), 'and');

    $monthPhrase = expected_span($monthStart, $monthEnd, 'and');

    // --- the fixtures -----------------------------------------------------

    $demoId = seed_user($pdo, 'demo', 'demo@example.test', DT_PASSWORD);
    $emptyId = seed_user($pdo, 'empty', 'empty@example.test', DT_PASSWORD);
    $oldId  = seed_user($pdo, 'older', 'older@example.test', DT_PASSWORD);
    $otherId = seed_user($pdo, 'other', 'other@example.test', DT_PASSWORD);

    $salaryCat    = seed_category($pdo, $demoId, 'Salary', 'income');
    $groceriesCat = seed_category($pdo, $demoId, 'Groceries', 'expense');

    seed_transaction($pdo, $demoId, 'income', '1200.00', $today, 'Salary', $salaryCat);
    seed_transaction($pdo, $demoId, 'expense', '42.80', $today, 'Weekly groceries', $groceriesCat);
    seed_transaction($pdo, $demoId, 'expense', '13.37', $monthStart, 'Coffee', null);
    seed_transaction($pdo, $demoId, 'expense', '5.00', $outside, 'Older entry', null);

    seed_transaction($pdo, $oldId, 'expense', '99.99', $outside, 'Last month entry', null);

    seed_transaction($pdo, $otherId, 'expense', '777.77', $today, 'Somebody elses entry', null);

    $sandbox = make_sandbox($root, $db);
    $server  = start_server($sandbox);
    $base    = $server['base'];

    ok('the sandbox answers', $base !== '', 'the server did not start');

    // --- B1: the guard ----------------------------------------------------

    echo "\n## B1. The guard\n";

    reset_cookies();

    $guest = http_request('GET', $base . '/dashboard');

    ok(
        'an unauthenticated visitor is redirected to the sign-in screen',
        $guest['status'] === 302 && ($guest['headers']['location'] ?? '') === '/login',
        'status=' . (string) $guest['status'] . ' location=' . (string) ($guest['headers']['location'] ?? '')
    );

    ok(
        'the guard answers with no body',
        $guest['body'] === ''
    );

    // --- B2: the month ----------------------------------------------------

    echo "\n## B2. The month\n";

    $signIn = sign_in($base, 'demo', DT_PASSWORD);

    ok('the sign-in works', $signIn['status'] === 302, (string) $signIn['status']);

    $page = http_request('GET', $base . '/dashboard');

    ok('the dashboard answers with a 200', $page['status'] === 200, (string) $page['status']);
    ok('the page title is "Dashboard — Financial Tracker"', str_contains($page['body'], '<title>Dashboard — Financial Tracker</title>'));
    ok('the heading is "Dashboard"', str_contains($page['body'], '<h1 class="page__title">Dashboard</h1>'));
    ok(
        'the subtitle is the fixed sentence',
        str_contains($page['body'], 'Your income, spending and net for the selected period.')
    );
    ok(
        'the page action is "Add transaction"',
        str_contains($page['body'], 'href="/transactions/new">Add transaction</a>')
    );

    // The three tiles, in order.
    $incomePos  = pos_of($page['body'], 'tile__label">Income');
    $spendPos   = pos_of($page['body'], 'tile__label">Spending');
    $netPos     = pos_of($page['body'], 'tile__label">Net');

    ok(
        'the three tiles are Income, Spending and Net, in that order',
        $incomePos !== -1 && $spendPos !== -1 && $netPos !== -1
            && $incomePos < $spendPos && $spendPos < $netPos,
        "income=$incomePos spending=$spendPos net=$netPos"
    );

    ok('the income tile is +1,200.00', str_contains($page['body'], '+1,200.00'));
    ok('the spending tile is −56.17', str_contains($page['body'], "\u{2212}56.17"));
    ok('the net tile is +1,143.83', str_contains($page['body'], '+1,143.83'));
    ok('the income tile carries tile--income', str_contains($page['body'], 'tile tile--income'));
    ok('the spending tile carries tile--spending', str_contains($page['body'], 'tile tile--spending'));
    ok('the net tile carries tile--net tile--net-positive', str_contains($page['body'], 'tile tile--net tile--net-positive'));

    ok(
        'the month label is under all three tiles',
        substr_count($page['body'], '<span class="tile__period">' . $monthName . '</span>') === 3,
        (string) substr_count($page['body'], '<span class="tile__period">' . $monthName . '</span>')
    );

    // The period selector.
    ok('the selector carries the group', str_contains($page['body'], 'data-period-group="month"'));
    ok(
        'the selector has five links',
        substr_count($page['body'], 'class="period__link"') === 5,
        (string) substr_count($page['body'], 'class="period__link"')
    );

    ok(
        'exactly one link is marked as the current period',
        substr_count($page['body'], 'aria-current="true"') === 1,
        (string) substr_count($page['body'], 'aria-current="true"')
    );

    ok(
        'the month link is the marked one',
        str_contains($page['body'], 'aria-current="true">This month</a>')
    );

    // The recent list.
    ok(
        'the recent card lists the four entries',
        substr_count($page['body'], 'class="tx"') === 4,
        (string) substr_count($page['body'], 'class="tx"')
    );

    ok(
        'the recent card carries the "View all" link with the arrow',
        str_contains($page['body'], 'View all transactions →</a>')
    );

    ok(
        'the rows are newest first',
        pos_of($page['body'], 'Weekly groceries') < pos_of($page['body'], '>Salary<')
            && pos_of($page['body'], '>Salary<') < pos_of($page['body'], '>Coffee<')
            && pos_of($page['body'], '>Coffee<') < pos_of($page['body'], '>Older entry<')
    );

    ok(
        'the date is the ISO date',
        str_contains($page['body'], 'tx__date">' . $today . '</span>')
    );

    ok(
        'the category is a badge',
        str_contains($page['body'], 'badge badge--neutral">Groceries</span>')
    );

    ok(
        'a transaction without a category reads "Uncategorised"',
        str_contains($page['body'], 'badge badge--neutral">Uncategorised</span>')
    );

    ok(
        'the row amounts carry the signs',
        str_contains($page['body'], 'amount--income">+1,200.00</span>')
            && str_contains($page['body'], 'amount--expense">−42.80</span>')
    );

    ok(
        'the recent list shows an entry from outside the period',
        str_contains($page['body'], 'Older entry')
    );

    // --- B3: the periods --------------------------------------------------

    echo "\n## B3. The periods\n";

    $week = http_request('GET', $base . '/dashboard?period=week');

    ok('the week answers with a 200', $week['status'] === 200, (string) $week['status']);
    ok('the week group is the week', str_contains($week['body'], 'data-period-group="week"'));
    ok(
        'the week link is the marked one',
        str_contains($week['body'], 'aria-current="true">This week</a>')
    );

    ok(
        'the week label is the span',
        str_contains($week['body'], '<span class="tile__period">' . $weekLabel . '</span>'),
        $weekLabel
    );

    $today1 = http_request('GET', $base . '/dashboard?period=today');
    $day    = http_request('GET', $base . '/dashboard?period=day');

    ok(
        'today and day are the same period',
        str_contains($today1['body'], 'data-period-group="today"')
            && str_contains($day['body'], 'data-period-group="today"')
            && str_contains($day['body'], 'aria-current="true">Today</a>')
    );

    $bogus = http_request('GET', $base . '/dashboard?period=nonsense');

    ok(
        'an unknown period falls back to the month',
        str_contains($bogus['body'], 'data-period-group="month"')
            && str_contains($bogus['body'], 'aria-current="true">This month</a>')
    );

    $year = http_request('GET', $base . '/dashboard?period=year');

    ok(
        'the year label is the year',
        str_contains($year['body'], '<span class="tile__period">' . $now->format('Y') . '</span>')
    );

    // --- B4: the empty account --------------------------------------------

    echo "\n## B4. The empty account\n";

    sign_in($base, 'empty', DT_PASSWORD);

    $blank = http_request('GET', $base . '/dashboard');

    ok('the empty dashboard answers with a 200', $blank['status'] === 200, (string) $blank['status']);
    ok(
        'the title reads "No transactions yet"',
        str_contains($blank['body'], 'empty__title">No transactions yet</h3>')
    );
    ok(
        'the body reads "Record your first entry to start tracking this period."',
        str_contains($blank['body'], 'Record your first entry to start tracking this period.')
    );
    ok(
        'the action is a primary "Add transaction" button',
        str_contains($blank['body'], 'class="btn btn--primary" href="/transactions/new">Add transaction</a>')
    );
    ok(
        'the tiles still render at 0.00',
        substr_count($blank['body'], 'tile__amount">0.00</span>') === 3,
        (string) substr_count($blank['body'], 'tile__amount">0.00</span>')
    );
    ok(
        'the net tile is neutral',
        str_contains($blank['body'], 'tile tile--net tile--net-zero')
    );
    ok(
        'no row is listed',
        !str_contains($blank['body'], 'class="tx"')
    );

    // --- B5: the empty period ---------------------------------------------

    echo "\n## B5. The empty period\n";

    sign_in($base, 'older', DT_PASSWORD);

    $periodEmpty = http_request('GET', $base . '/dashboard?period=week');

    ok(
        'the title names the period',
        str_contains($periodEmpty['body'], 'empty__title">No transactions this week.</h3>'),
        'the title is missing'
    );

    ok(
        'the body gives the exact range',
        str_contains($periodEmpty['body'], 'Nothing was recorded between ' . $weekPhrase . '.'),
        $weekPhrase
    );

    ok(
        'the suggestion is a secondary button to the month',
        str_contains($periodEmpty['body'], 'class="btn btn--secondary" href="/transactions?period=month">Look at this month</a>')
    );

    $monthEmpty = http_request('GET', $base . '/dashboard');

    ok(
        'the month of the same account names the month',
        str_contains($monthEmpty['body'], 'empty__title">No transactions this month.</h3>')
    );

    ok(
        'the month body gives the exact range',
        str_contains($monthEmpty['body'], 'Nothing was recorded between ' . $monthPhrase . '.'),
        $monthPhrase
    );

    // --- B6: the custom range ---------------------------------------------

    echo "\n## B6. The custom range\n";

    sign_in($base, 'demo', DT_PASSWORD);

    $custom = http_request('GET', $base . '/dashboard?period=custom&from=' . $monthStart . '&to=' . $monthEnd);

    ok('the custom range answers with a 200', $custom['status'] === 200, (string) $custom['status']);
    ok('the custom link is the marked one', str_contains($custom['body'], 'aria-current="true">Custom</a>'));
    ok(
        'the tiles render for the custom range',
        str_contains($custom['body'], 'class="tiles"')
    );

    ok(
        'the custom label is the span of the range',
        str_contains($custom['body'], '<span class="tile__period">' . expected_span($monthStart, $monthEnd) . '</span>')
    );

    $noRange = http_request('GET', $base . '/dashboard?period=custom');

    ok('a custom period with no range answers with a 200', $noRange['status'] === 200, (string) $noRange['status']);
    ok(
        'the range form asks for the two dates',
        str_contains($noRange['body'], 'name="from"') && str_contains($noRange['body'], 'name="to"')
    );
    ok(
        'the form carries the apply button',
        str_contains($noRange['body'], '>Apply range</button>')
    );
    ok(
        'no tiles are rendered without a range',
        !str_contains($noRange['body'], 'class="tiles"')
    );

    $reversed = http_request('GET', $base . '/dashboard?period=custom&from=' . $monthEnd . '&to=' . $monthStart);

    ok(
        'a reversed range is refused',
        str_contains($reversed['body'], 'The start date must be on or before the end date.')
    );

    ok(
        'the form keeps the values that were typed',
        str_contains($reversed['body'], 'name="from" type="date" value="' . $monthEnd . '"')
            && str_contains($reversed['body'], 'name="to" type="date" value="' . $monthStart . '"')
    );

    $impossible = http_request('GET', $base . '/dashboard?period=custom&from=2026-02-31&to=2026-03-01');

    ok(
        'an impossible date is refused',
        str_contains($impossible['body'], 'Enter a valid date.')
    );

    // --- B7: the error state ----------------------------------------------

    echo "\n## B7. The error state\n";

    sign_in($base, 'demo', DT_PASSWORD);

    $pdo->exec('RENAME TABLE `transactions` TO `transactions_hidden`');

    $broken = http_request('GET', $base . '/dashboard');

    $pdo->exec('RENAME TABLE `transactions_hidden` TO `transactions`');

    ok('the broken dashboard still answers with a 200', $broken['status'] === 200, (string) $broken['status']);
    ok(
        'the alert announces itself',
        str_contains($broken['body'], 'role="alert"')
            && str_contains($broken['body'], 'alert alert--danger')
    );
    ok(
        'the alert says "The summary could not be loaded."',
        str_contains($broken['body'], 'alert__title">The summary could not be loaded.</p>')
    );
    ok(
        'the alert says "Please try again."',
        str_contains($broken['body'], '<p>Please try again.</p>')
    );
    ok(
        'the empty state says "No summary available"',
        str_contains($broken['body'], 'empty__title">No summary available</h3>')
    );
    ok(
        'the empty state explains itself',
        str_contains($broken['body'], 'The numbers could not be retrieved, so nothing is shown here.')
    );
    ok(
        'the try-again button is there',
        str_contains($broken['body'], '>Try again</button>')
    );

    ok(
        'no tiles are rendered after a failure',
        !str_contains($broken['body'], 'class="tiles"') && !str_contains($broken['body'], 'tile__label')
    );

    ok(
        'the period selector is still rendered',
        str_contains($broken['body'], 'data-period-group=')
    );

    ok(
        'the recent list is not rendered after a failure',
        !str_contains($broken['body'], 'class="tx"')
    );

    $leaks = [];

    foreach (['SQLSTATE', 'PDOException', 'Base table', 'transactions_hidden', 'SELECT'] as $needle) {
        if (str_contains($broken['body'], $needle)) {
            $leaks[] = $needle;
        }
    }

    ok('the error state leaks nothing', $leaks === [], implode(', ', $leaks));

    // The page recovers when the table comes back.
    $recovered = http_request('GET', $base . '/dashboard');

    ok(
        'the dashboard recovers',
        str_contains($recovered['body'], '+1,200.00')
    );

    // --- B8: the ownership ------------------------------------------------

    echo "\n## B8. The ownership\n";

    sign_in($base, 'demo', DT_PASSWORD);

    $mine = http_request('GET', $base . '/dashboard');

    ok(
        'the dashboard shows no entry of another account',
        !str_contains($mine['body'], 'Somebody elses entry')
    );

    sign_in($base, 'other', DT_PASSWORD);

    $theirs = http_request('GET', $base . '/dashboard');

    ok(
        'another account sees its own entry',
        str_contains($theirs['body'], 'Somebody elses entry')
    );

    ok(
        'another account sees no entry of the first',
        !str_contains($theirs['body'], 'Weekly groceries')
    );

    // --- the mutation check -----------------------------------------------

    echo "\n## B9. The mutation check\n";

    $pdo->exec("UPDATE transactions SET amount = '1300.00' WHERE user_id = " . $demoId . " AND description = 'Salary'");

    sign_in($base, 'demo', DT_PASSWORD);

    $mutated = http_request('GET', $base . '/dashboard');

    ok(
        'the numbers come from the database and not from the template',
        str_contains($mutated['body'], '+1,300.00') && !str_contains($mutated['body'], '+1,200.00'),
        'the page did not follow the change'
    );

    $pdo->exec("UPDATE transactions SET amount = '1200.00' WHERE user_id = " . $demoId . " AND description = 'Salary'");

    stop_server($server);
    remove_tree($sandbox);
    drop_scratch_database($root);
}

// ---------------------------------------------------------------------------

echo "\n";
echo "Failed: " . count($failures) . "\n";

if (count($failures) > 0) {
    echo "FAILURES:\n";

    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }

    exit(1);
}

exit(0);
