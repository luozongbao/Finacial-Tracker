<?php
/**
 * auth_test.php — guards the authentication screens and the session handling
 * (issue-016).
 *
 * The screens in this issue are the only ones an unauthenticated visitor can
 * reach, and they are the only ones that answer to a stranger. The things
 * that are pinned here are the things that would be expensive to get wrong:
 *
 *   1. THE SILENCE. A failed sign-in says "That username and password do not
 *      match." — a missing account and a wrong password produce the same
 *      message, the same status code and the same body. The forgot-password
 *      screen answers word-for-word identically for a known and an unknown
 *      address, and it takes the same time either way.
 *   2. THE GATE. ALLOW_REGISTRATION === false is a two-sided gate: the GET
 *      answers 403 with no form, and the POST answers 403 on its own,
 *      before any input is read.
 *   3. THE LIMITS. Five attempts in fifteen minutes, counted against the
 *      IDENTIFIER — so a name that matches no account is throttled too. The
 *      lock expires; it is not a permanent denial of service.
 *   4. THE SECRETS. The password is stored hashed, the reset and the
 *      confirmation token are stored as a SHA-256 hash, and only the raw
 *      token ever travels. The SMTP host, the port and the raw transport
 *      error never reach the page.
 *   5. THE LINKS. The reset link is built from $_SERVER['SERVER_NAME'] and
 *      never from the Host: header, so it cannot be pointed at another host.
 *
 * Two tiers:
 *
 *   - The file tier (§A) always runs. It needs no database and no web
 *     server — it reads the sources.
 *   - The behaviour tier (§B) runs the REAL screens in a sandbox: a copy of
 *     the application in a temporary directory, served by the PHP built-in
 *     server, against a scratch database. The copy is real, because the
 *     thing under test is the screens and not a mock of them. The only
 *     substitution is the MAILER: a stub records the messages instead of
 *     sending them, so the walk through the screens needs no SMTP server.
 *     The real mailer is guarded by database/mailer_test.php.
 *
 * Run:  docker compose exec -T app php database/auth_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by
 * the output.
 */

declare(strict_types=1);

$failures = [];

/**
 * The cookie jar of the test. The CSRF token and the session live in it, so
 * the requests have to carry the cookie from one to the next.
 */
$GLOBALS['at_cookies'] = [];

/**
 * The password of the seeded accounts.
 */
const AT_PASSWORD = 'a-good-long-password';
const AT_NEW_PASSWORD = 'another-good-long-password';

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
    $GLOBALS['at_cookies'] = [];
}

/**
 * Perform an HTTP request and return the status, the body, the headers and
 * the time it took.
 *
 * The session cookie is carried from one request to the next: the CSRF
 * token and the session state live in it, so a request without the cookie
 * would start a new session every time.
 */
function http_request(string $method, string $url, array $fields = [], array $extraHeaders = [], int $timeout = 30): array
{
    $headers = [
        'User-Agent: financial-tracker-auth-test',
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

    $jar = $GLOBALS['at_cookies'] ?? [];

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

    $started  = microtime(true);
    $response = @file_get_contents($url, false, $ctx);
    $elapsed  = (microtime(true) - $started) * 1000;

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
            unset($GLOBALS['at_cookies'][$name]);
            continue;
        }

        $GLOBALS['at_cookies'][$name] = $value;
    }

    return [
        'status'     => $status,
        'body'       => $response === false ? '' : $response,
        'headers'    => $respHeaders,
        'set_cookie' => $setCookies,
        'elapsed'    => $elapsed,
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
 * The value of a form field in a rendered page, or an empty string.
 */
function field_value(string $body, string $name): string
{
    $pattern = '/name="' . preg_quote($name, '/') . '" value="([^"]*)"/';

    if (preg_match($pattern, $body, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * Mask the echoed identifier, so two bodies that differ only in the value
 * the visitor typed can be compared.
 */
function mask_identifier(string $body, string $identifier): string
{
    return str_replace('value="' . $identifier . '"', 'value="MASKED"', $body);
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
 * The body of a function in a source file, from its signature to the line
 * that closes it.
 *
 * The function body is what the rule is about: "loginUser() must not touch
 * the database" is a statement about the body, not about the file.
 */
function function_body(string $src, string $function): string
{
    $start = pos_of($src, 'function ' . $function . '(');

    if ($start === -1) {
        return '';
    }

    $brace = strpos($src, '{', $start);

    if ($brace === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($src);

    for ($i = $brace; $i < $length; $i++) {
        if ($src[$i] === '{') {
            $depth++;
            continue;
        }

        if ($src[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

/**
 * The position of the first read of a field that is NOT the CSRF token.
 *
 * The rule is "the token is validated before the input is read". Reading the
 * token itself is not reading the input — the token has to be fetched to be
 * validated — so the needle is the first field other than csrf_token.
 */
function first_input_read(string $src): int
{
    $pattern = '/\$_POST\[\'([a-z_]+)\'\]/';
    $offset  = 0;

    while (preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        if ($m[1][0] !== 'csrf_token') {
            return (int) $m[0][1];
        }

        $offset = (int) $m[0][1] + strlen((string) $m[0][0]);
    }

    return -1;
}

/**
 * The source without its comments.
 *
 * A rule about the CODE must not be satisfied by a sentence in a docblock.
 * The docblock of absoluteUrl() names HTTP_HOST precisely to say that the
 * function never reads it.
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
 * The stub mailer of the sandbox.
 *
 * It records the messages instead of sending them, and its behaviour comes
 * from a mode file next to it:
 *
 *   ok    — mailIsConfigured() is true and sendMail() returns true
 *   fail  — mailIsConfigured() is true and sendMail() returns false
 *   off   — mailIsConfigured() is false
 */
function write_stub_mailer(string $dir): void
{
    $stub = <<<'MAILER'
<?php
/**
 * The stub mailer of the auth sandbox.
 *
 * It stands in for includes/mailer.php so the screens can be walked end to
 * end without an SMTP server. The REAL mailer is guarded by
 * database/mailer_test.php — this file only has to behave the way the
 * screens expect.
 */

declare(strict_types=1);

function mailer_stub_mode(): string
{
    $file = __DIR__ . '/mailer-mode.txt';
    $mode = is_file($file) ? trim((string) file_get_contents($file)) : 'off';

    return in_array($mode, ['ok', 'fail', 'off'], true) ? $mode : 'off';
}

function mailIsConfigured(): bool
{
    return mailer_stub_mode() !== 'off';
}

function createMailer(): object
{
    throw new RuntimeException('the stub mailer has no PHPMailer instance');
}

function sendMail(string $to, string $subject, string $body): bool
{
    $mode = mailer_stub_mode();

    $line = json_encode([
        'to'      => $to,
        'subject' => $subject,
        'body'    => $body,
        'mode'    => $mode,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    file_put_contents(__DIR__ . '/mailer.log', $line . "\n", FILE_APPEND | LOCK_EX);

    return $mode === 'ok';
}

MAILER;

    file_put_contents($dir . '/includes/mailer.php', $stub);
}

/**
 * Set the mode of the stub mailer.
 */
function set_mailer_mode(string $sandbox, string $mode): void
{
    file_put_contents($sandbox . '/includes/mailer-mode.txt', $mode);
}

/**
 * The messages the stub mailer recorded.
 *
 * @return array<int, array<string, string>>
 */
function mailer_messages(string $sandbox): array
{
    $file = $sandbox . '/includes/mailer.log';

    if (!is_file($file)) {
        return [];
    }

    $messages = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);

        if (is_array($decoded)) {
            $messages[] = $decoded;
        }
    }

    return $messages;
}

/**
 * Forget the recorded messages.
 */
function clear_mailer_log(string $sandbox): void
{
    @unlink($sandbox . '/includes/mailer.log');
}

/**
 * Build the sandbox: a copy of the application in a temporary directory.
 */
function make_sandbox(string $root, array $db, bool $allowRegistration): string
{
    $dir = sys_get_temp_dir() . '/ft_auth_' . bin2hex(random_bytes(5));

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

    // The mailer is the one substitution.
    write_stub_mailer($dir);
    set_mailer_mode($dir, 'ok');

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
        . 'const ALLOW_REGISTRATION = ' . ($allowRegistration ? 'true' : 'false') . ";\n"
        . "const WEEK_START = 1;\n"
        . "const TIMEZONE = 'UTC';\n";

    file_put_contents($dir . '/config.php', $config);

    // The router: every path that is not a real file goes to the front
    // controller.
    //
    // SERVER_NAME is FORCED. The reset link is built from it and never from
    // the Host: header, so the test can prove the link points at the
    // server's own host even when the client sends a different one.
    file_put_contents($dir . '/router.php', <<<'ROUTER'
<?php
$_SERVER['SERVER_NAME'] = 'auth.test';

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
 * Create the scratch database, load the schema and seed the accounts.
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

        $pdo->exec('DROP DATABASE IF EXISTS `ft_auth_test`');
        $pdo->exec('CREATE DATABASE `ft_auth_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `ft_auth_test`');

        $schema = (string) file_get_contents($root . '/database/schema.sql');
        $pdo->exec($schema);
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be prepared: " . $ex->getMessage() . "\n";

        return null;
    }

    return [
        'host' => $host,
        'name' => 'ft_auth_test',
        'user' => 'root',
        'pass' => $pass,
        'pdo'  => $pdo,
    ];
}

/**
 * Seed an account.
 */
function seed_user(PDO $pdo, string $username, string $email, string $password, string $status = 'active'): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, email_verified_at, status)
              VALUES (?, ?, ?, NOW(), ?)'
    );

    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $status]);

    return (int) $pdo->lastInsertId();
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

        $pdo->exec('DROP DATABASE IF EXISTS `ft_auth_test`');
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be dropped: " . $ex->getMessage() . "\n";
    }
}

$root = realpath(__DIR__ . '/..');

$authSource   = (string) file_get_contents($root . '/includes/auth.php');
$funcSource   = (string) file_get_contents($root . '/includes/functions.php');
$mailerSource = (string) file_get_contents($root . '/includes/mailer.php');
$routerSource = (string) file_get_contents($root . '/includes/Router.php');
$indexSource  = (string) file_get_contents($root . '/index.php');
$authCss      = (string) file_get_contents($root . '/assets/css/pages/auth.css');

$views = [
    'login'          => (string) file_get_contents($root . '/views/login.php'),
    'register'       => (string) file_get_contents($root . '/views/register.php'),
    'forgot_password' => (string) file_get_contents($root . '/views/forgot_password.php'),
    'reset_password' => (string) file_get_contents($root . '/views/reset_password.php'),
    'verify_email'   => (string) file_get_contents($root . '/views/verify_email.php'),
    'logout'         => (string) file_get_contents($root . '/views/logout.php'),
];

// ---------------------------------------------------------------------------
// A. The structure.
// ---------------------------------------------------------------------------

echo "## A. The structure\n";

foreach ([
    'includes/auth.php'            => $root . '/includes/auth.php',
    'views/login.php'              => $root . '/views/login.php',
    'views/register.php'           => $root . '/views/register.php',
    'views/forgot_password.php'    => $root . '/views/forgot_password.php',
    'views/reset_password.php'     => $root . '/views/reset_password.php',
    'views/verify_email.php'       => $root . '/views/verify_email.php',
    'views/logout.php'             => $root . '/views/logout.php',
    'assets/css/pages/auth.css'    => $root . '/assets/css/pages/auth.css',
] as $label => $path) {
    ok($label . ' exists', is_file($path), $path);
}

// --- A1: the registration gate comes first -------------------------------

echo "\n## A1. The registration gate comes first\n";

$gatePos     = pos_of($views['register'], 'registrationAllowed()');
$csrfPos     = pos_of($views['register'], 'validateCsrfToken(');
$postPos     = pos_of($views['register'], "\$_POST['username']");

ok('the register screen checks the gate', $gatePos !== -1);
ok(
    'the gate is checked before the CSRF token',
    $gatePos !== -1 && $csrfPos !== -1 && $gatePos < $csrfPos,
    'gate=' . $gatePos . ' csrf=' . $csrfPos
);
ok(
    'the gate is checked before any input is read',
    $gatePos !== -1 && $postPos !== -1 && $gatePos < $postPos,
    'gate=' . $gatePos . ' input=' . $postPos
);
ok(
    'the closed screen answers with a 403',
    str_contains($views['register'], 'http_response_code(403)')
);

// --- A2: every POST validates the CSRF token before reading the input -----

echo "\n## A2. The CSRF token is validated before the input is read\n";

foreach ($views as $name => $src) {
    $formCount  = substr_count($src, '<form');
    $tokenCount = substr_count($src, 'name="csrf_token"');

    if ($formCount === 0) {
        continue;
    }

    ok(
        'every form on the ' . $name . ' screen carries a CSRF token',
        $tokenCount === $formCount,
        "forms=$formCount tokens=$tokenCount"
    );

    $checkPos = pos_of($src, 'validateCsrfToken(');
    $readPos  = first_input_read($src);

    ok(
        'the ' . $name . ' screen validates the token before it reads the input',
        $checkPos !== -1 && $readPos !== -1 && $checkPos < $readPos,
        'check=' . $checkPos . ' read=' . $readPos
    );
}

// --- A3: the session holds no secret -------------------------------------

echo "\n## A3. The session holds no secret\n";

$loginUserBody = function_body($authSource, 'loginUser');
$requireLoginBody = function_body($authSource, 'requireLogin');

ok('loginUser() stores the user id', str_contains($loginUserBody, "\$_SESSION['user_id']"));
ok(
    'loginUser() never stores the password hash',
    !str_contains($loginUserBody, 'password_hash')
);
ok(
    'loginUser() never touches the database',
    !str_contains($loginUserBody, 'Database::'),
    'the guard would query the database'
);
ok(
    'requireLogin() never touches the database',
    !str_contains($requireLoginBody, 'Database::'),
    'the guard would query the database'
);
ok(
    'the session id is regenerated on sign-in',
    str_contains($loginUserBody, 'session_regenerate_id(true)')
);

// --- A4: the tokens are hashed --------------------------------------------

echo "\n## A4. The tokens are hashed\n";

ok(
    'the tokens are 32 random bytes, hex-encoded',
    substr_count($authSource, 'bin2hex(random_bytes(32))') >= 2,
    'the reset and the confirmation token both use it'
);
ok(
    'only the SHA-256 hash of the token is stored',
    str_contains($authSource, "hash('sha256', \$token)")
);
ok(
    'the token is compared with hash_equals()',
    str_contains($authSource, 'hash_equals(')
);
ok(
    'the reset lookup filters on used_at IS NULL',
    str_contains($authSource, 'used_at IS NULL')
);
ok(
    'the reset lookup filters on expires_at > NOW()',
    str_contains($authSource, 'expires_at > NOW()')
);
ok(
    'the password is hashed with password_hash()',
    str_contains($authSource, 'password_hash($plain, PASSWORD_DEFAULT)')
);
ok(
    'the password is verified with password_verify()',
    str_contains($authSource, 'password_verify(')
);

// --- A5: the links are built from SERVER_NAME -----------------------------

echo "\n## A5. The links are built from SERVER_NAME\n";

ok(
    'the absolute URL reads SERVER_NAME',
    str_contains($funcSource, "\$_SERVER['SERVER_NAME']")
);
ok(
    'the absolute URL never reads HTTP_HOST',
    !str_contains(strip_comments($funcSource), 'HTTP_HOST'),
    'the code reads the Host: header'
);
ok(
    'the reset link is built with absoluteUrl()',
    str_contains($views['forgot_password'], 'absoluteUrl(')
);
ok(
    'the confirmation link is built with absoluteUrl()',
    str_contains($views['register'], 'absoluteUrl(')
);

// --- A6: the mailer is plain text -----------------------------------------

echo "\n## A6. The mailer is plain text\n";

ok('the message is plain text', str_contains($mailerSource, 'isHTML(false)'));
ok(
    'sendMail() returns false instead of throwing',
    str_contains($mailerSource, 'return false;')
);
ok(
    'the front controller loads the mailer',
    str_contains($indexSource, "require_once __DIR__ . '/includes/mailer.php'"),
    'a screen that calls sendMail() would die with an undefined function'
);

// --- A7: the routes -------------------------------------------------------

echo "\n## A7. The routes\n";

foreach ([
    "['GET',  '/login'"              => 'GET /login',
    "['POST', '/login'"              => 'POST /login',
    "['GET',  '/register'"           => 'GET /register',
    "['POST', '/register'"           => 'POST /register',
    "['GET',  '/forgot-password'"    => 'GET /forgot-password',
    "['POST', '/forgot-password'"    => 'POST /forgot-password',
    "['GET',  '/reset-password'"     => 'GET /reset-password',
    "['POST', '/reset-password'"     => 'POST /reset-password',
    "['GET',  '/verify-email'"       => 'GET /verify-email',
    "['POST', '/logout'"             => 'POST /logout',
] as $needle => $label) {
    ok('the route table has ' . $label, str_contains($routerSource, $needle));
}

// --- A8: the escaping -----------------------------------------------------

echo "\n## A8. The escaping\n";

foreach ($views as $name => $src) {
    $bad = unescaped_outputs($src);

    ok(
        'every dynamic value on the ' . $name . ' screen is escaped',
        $bad === [],
        implode(' | ', $bad)
    );
}

// --- A9: the stylesheet ---------------------------------------------------

echo "\n## A9. The page stylesheet\n";

$hexCodes = [];

if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $authCss, $m) === 1 || !empty($m[0])) {
    $hexCodes = $m[0];
}

ok('the page stylesheet carries no literal colour', $hexCodes === [], implode(', ', $hexCodes));
ok('the page stylesheet uses the spacing tokens', str_contains($authCss, 'var(--sp-'));

// ---------------------------------------------------------------------------
// B. The behaviour, in a sandbox.
// ---------------------------------------------------------------------------

echo "\n## B. The behaviour in a sandbox\n";

$db = create_scratch_database($root);

if ($db === null) {
    echo "  [FAIL] the scratch database could not be prepared\n";
    $failures[] = 'the scratch database could not be prepared';

    echo "\nFailed: " . count($failures) . "\n";
    exit(1);
}

// The accounts. Each scenario has its own account, so one test cannot break
// another through the shared throttle counter.
$demoId     = seed_user($db['pdo'], 'demo', 'demo@example.test', AT_PASSWORD);
$sleeperId  = seed_user($db['pdo'], 'sleeper', 'sleeper@example.test', AT_PASSWORD);
$forgetterId = seed_user($db['pdo'], 'forgetter', 'forgetter@example.test', AT_PASSWORD);
// The account is NOT called "disabled": the screen echoes the typed name
// back, and the test looks for the word in the answer.
$disabledId = seed_user($db['pdo'], 'blocked', 'blocked@example.test', AT_PASSWORD, 'disabled');

// A separate account for the Host: header attack. Issuing a reset token
// replaces the live one of that account, so the attack must not touch the
// account whose token the reset flow is about to use.
$hostedId = seed_user($db['pdo'], 'hosted', 'hosted@example.test', AT_PASSWORD);

$sandbox = make_sandbox($root, $db, true);
$closed  = make_sandbox($root, $db, false);

$server = start_server($sandbox);
$closedServer = start_server($closed);

register_shutdown_function(static function () use ($sandbox, $closed, $server, $closedServer, $root): void {
    stop_server($server);
    stop_server($closedServer);
    remove_tree($sandbox);
    remove_tree($closed);
    drop_scratch_database($root);
});

if ($server['proc'] === null || $closedServer['proc'] === null) {
    echo "  [FAIL] the built-in server could not be started\n";
    $failures[] = 'the built-in server could not be started';

    echo "\nFailed: " . count($failures) . "\n";
    exit(1);
}

$base = $server['base'];

// --- B1: the sign-in screen -----------------------------------------------

echo "\n## B1. The sign-in screen\n";

reset_cookies();

$res = http_request('GET', $base . '/login');

ok('the sign-in screen answers with a 200', $res['status'] === 200, (string) $res['status']);
ok('the screen renders the auth shell', str_contains($res['body'], 'class="auth"'));
ok(
    'the screen carries no application navigation',
    !str_contains($res['body'], 'nav__link') && !str_contains($res['body'], 'bottom-nav')
);
ok('the screen has the username field', str_contains($res['body'], 'name="username"'));
ok('the screen has the password field', str_contains($res['body'], 'name="password"'));
ok(
    'the username field is autocomplete="username"',
    str_contains($res['body'], 'autocomplete="username"')
);
ok(
    'the password field is autocomplete="current-password"',
    str_contains($res['body'], 'autocomplete="current-password"')
);
ok(
    'the password field has a reveal button',
    str_contains($res['body'], 'data-reveal-target="login-pass"')
);
ok(
    'the reveal button starts unpressed',
    str_contains($res['body'], 'aria-pressed="false"')
);
ok('the submit button reads Sign in', str_contains($res['body'], '>Sign in<'));
ok(
    'the screen links the page stylesheet',
    str_contains($res['body'], 'css/pages/auth.css')
);

$token = csrf_of($res['body']);

ok('the screen carries a CSRF token', $token !== '', 'no 64-character token found');

// --- B2: the failure message says nothing ---------------------------------

echo "\n## B2. The failure message says nothing\n";

$wrong   = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'demo',
    'password'   => 'not-the-password',
]);
$missing = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'ghost',
    'password'   => 'not-the-password',
]);

ok('a wrong password is a 200', $wrong['status'] === 200, (string) $wrong['status']);
ok('a missing account is a 200', $missing['status'] === 200, (string) $missing['status']);

ok(
    'a wrong password reads "That username and password do not match."',
    str_contains($wrong['body'], 'That username and password do not match.')
);
ok(
    'a missing account reads the same message',
    str_contains($missing['body'], 'That username and password do not match.')
);

$leaks = [];

foreach ([
    'unknown user', 'no such user', 'does not exist', 'wrong password',
    'incorrect password', 'not found', 'SQLSTATE', 'PDOException',
] as $needle) {
    if (stripos($wrong['body'], $needle) !== false || stripos($missing['body'], $needle) !== false) {
        $leaks[] = $needle;
    }
}

ok('the failure says nothing about which half was wrong', $leaks === [], implode(', ', $leaks));

ok(
    'the two bodies are identical once the typed name is masked',
    md5(mask_identifier($wrong['body'], 'demo')) === md5(mask_identifier($missing['body'], 'ghost')),
    'the bodies differ'
);

ok(
    'the username field keeps its value',
    str_contains($wrong['body'], 'value="demo"')
);
ok(
    'the password field is cleared',
    !str_contains($wrong['body'], 'value="not-the-password"')
);

// --- B3: the throttle ------------------------------------------------------

echo "\n## B3. The attempt limit\n";

for ($attempt = 0; $attempt < 5; $attempt++) {
    http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => 'sleeper',
        'password'   => 'wrong-again',
    ]);
}

$locked = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'sleeper',
    'password'   => 'wrong-again',
]);

ok(
    'the lock message is "Too many attempts. Try again in 15 minutes."',
    str_contains($locked['body'], 'Too many attempts. Try again in 15 minutes.'),
    'status=' . (string) $locked['status']
);
ok('the submit button is disabled while the wait is in force', str_contains($locked['body'], 'disabled'));

$row = $db['pdo']->query(
    "SELECT failed_attempts FROM login_attempts
      WHERE identifier_hash = '" . hash('sha256', 'sleeper') . "'"
)->fetch();

ok(
    'the counter against the identifier holds the failures',
    is_array($row) && (int) $row['failed_attempts'] === 5,
    'row=' . json_encode($row)
);

$user = $db['pdo']->query('SELECT failed_attempts, locked_until FROM users WHERE id = ' . $sleeperId)->fetch();

ok(
    'the account carries the same count',
    is_array($user) && (int) $user['failed_attempts'] === 5
);
ok('the account carries the end of the wait', is_array($user) && $user['locked_until'] !== null);

// The same limit for an identifier that matches NO account.
for ($attempt = 0; $attempt < 5; $attempt++) {
    http_request('POST', $base . '/login', [
        'csrf_token' => $token,
        'username'   => 'nobody-at-all',
        'password'   => 'wrong-again',
    ]);
}

$lockedGhost = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'nobody-at-all',
    'password'   => 'wrong-again',
]);

ok(
    'the throttle applies to an identifier that matches no account',
    str_contains($lockedGhost['body'], 'Too many attempts.'),
    'status=' . (string) $lockedGhost['status']
);

// The lock expires.
$db['pdo']->exec(
    "UPDATE login_attempts
        SET locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
      WHERE identifier_hash = '" . hash('sha256', 'sleeper') . "'"
);

$expired = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'sleeper',
    'password'   => 'wrong-again',
]);

ok(
    'the lock expires — it is not a permanent denial of service',
    !str_contains($expired['body'], 'Too many attempts.'),
    'the account is still locked'
);
ok(
    'the counter starts again after the wait',
    str_contains($expired['body'], 'That username and password do not match.')
);

// --- B4: the successful sign-in -------------------------------------------

echo "\n## B4. The successful sign-in\n";

reset_cookies();

$res     = http_request('GET', $base . '/login');
$token   = csrf_of($res['body']);
$sessionBefore = $GLOBALS['at_cookies']['PHPSESSID'] ?? '';

$success = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'demo',
    'password'   => AT_PASSWORD,
]);

ok('a correct sign-in is a redirect', $success['status'] === 302, (string) $success['status']);
ok(
    'the redirect points at the dashboard',
    ($success['headers']['location'] ?? '') === '/dashboard',
    (string) ($success['headers']['location'] ?? '')
);
ok('the body of the redirect is empty', $success['body'] === '');

$sessionAfter = $GLOBALS['at_cookies']['PHPSESSID'] ?? '';

ok(
    'the session id is regenerated on sign-in',
    $sessionBefore !== '' && $sessionAfter !== '' && $sessionBefore !== $sessionAfter,
    'before=' . $sessionBefore . ' after=' . $sessionAfter
);

$afterLogin = http_request('GET', $base . '/login');

ok(
    'a signed-in visitor is redirected away from the sign-in screen',
    $afterLogin['status'] === 302 && ($afterLogin['headers']['location'] ?? '') === '/dashboard',
    'status=' . (string) $afterLogin['status']
);

$counter = $db['pdo']->query('SELECT failed_attempts FROM users WHERE id = ' . $demoId)->fetch();

ok(
    'a successful sign-in resets the counter of the account',
    is_array($counter) && (int) $counter['failed_attempts'] === 0
);

// --- B5: the sign-out ------------------------------------------------------

echo "\n## B5. The sign-out\n";

$res = http_request('GET', $base . '/forgot-password');
$token = csrf_of($res['body']);

$logout = http_request('POST', $base . '/logout', ['csrf_token' => $token]);

ok('the sign-out is a redirect', $logout['status'] === 302, (string) $logout['status']);
ok(
    'the sign-out points at the sign-in page',
    ($logout['headers']['location'] ?? '') === '/login',
    (string) ($logout['headers']['location'] ?? '')
);

$cleared = false;

foreach ($logout['set_cookie'] as $cookie) {
    if (stripos($cookie, 'PHPSESSID=deleted') !== false) {
        $cleared = true;
    }
}

ok('the session cookie is cleared', $cleared, implode(' | ', $logout['set_cookie']));

$afterLogout = http_request('GET', $base . '/login');

ok(
    'the visitor is signed out',
    $afterLogout['status'] === 200,
    'status=' . (string) $afterLogout['status']
);

// --- B6: the registration --------------------------------------------------

echo "\n## B6. The registration\n";

reset_cookies();

$res = http_request('GET', $base . '/register');

ok('the registration screen answers with a 200', $res['status'] === 200, (string) $res['status']);

foreach (['username', 'email', 'email_confirmation', 'password', 'password_confirmation'] as $field) {
    ok('the form carries the ' . $field . ' field', str_contains($res['body'], 'name="' . $field . '"'));
}

$token = csrf_of($res['body']);

// The validation, one case at a time.
$cases = [
    [
        'label'   => 'a short username',
        'fields'  => ['username' => 'ab', 'email' => 'new@example.test',
                      'email_confirmation' => 'new@example.test',
                      'password' => AT_PASSWORD, 'password_confirmation' => AT_PASSWORD],
        'message' => 'The username must be at least 3 characters long.',
    ],
    [
        'label'   => 'an invalid email address',
        'fields'  => ['username' => 'newbie', 'email' => 'not-an-address',
                      'email_confirmation' => 'not-an-address',
                      'password' => AT_PASSWORD, 'password_confirmation' => AT_PASSWORD],
        'message' => 'Enter a valid email address.',
    ],
    [
        'label'   => 'two different email addresses',
        'fields'  => ['username' => 'newbie', 'email' => 'new@example.test',
                      'email_confirmation' => 'other@example.test',
                      'password' => AT_PASSWORD, 'password_confirmation' => AT_PASSWORD],
        'message' => 'The two email addresses do not match.',
    ],
    [
        'label'   => 'a short password',
        'fields'  => ['username' => 'newbie', 'email' => 'new@example.test',
                      'email_confirmation' => 'new@example.test',
                      'password' => 'short', 'password_confirmation' => 'short'],
        'message' => 'The password must be at least 10 characters long.',
    ],
    [
        'label'   => 'two different passwords',
        'fields'  => ['username' => 'newbie', 'email' => 'new@example.test',
                      'email_confirmation' => 'new@example.test',
                      'password' => AT_PASSWORD, 'password_confirmation' => 'short'],
        'message' => 'The two passwords do not match.',
    ],
];

foreach ($cases as $case) {
    $posted = array_merge($case['fields'], ['csrf_token' => $token]);
    $answer = http_request('POST', $base . '/register', $posted);

    ok(
        $case['label'] . ' is refused with "' . $case['message'] . '"',
        str_contains($answer['body'], $case['message']),
        'status=' . (string) $answer['status']
    );
}

// The uniqueness.
$posted = [
    'csrf_token'           => $token,
    'username'             => 'demo',
    'email'                => 'fresh@example.test',
    'email_confirmation'   => 'fresh@example.test',
    'password'             => AT_PASSWORD,
    'password_confirmation' => AT_PASSWORD,
];

$answer = http_request('POST', $base . '/register', $posted);

ok(
    'a taken username is refused with "That username is already taken."',
    str_contains($answer['body'], 'That username is already taken.'),
    'status=' . (string) $answer['status']
);
ok(
    'the username-taken message says nothing about the email address',
    !str_contains($answer['body'], 'That email address is already registered.')
);

$posted['username']             = 'another-newbie';
$posted['email']                = 'demo@example.test';
$posted['email_confirmation']   = 'demo@example.test';

$answer = http_request('POST', $base . '/register', $posted);

ok(
    'a taken email address is refused with "That email address is already registered."',
    str_contains($answer['body'], 'That email address is already registered.'),
    'status=' . (string) $answer['status']
);

// The successful registration.
clear_mailer_log($sandbox);

$posted = [
    'csrf_token'           => $token,
    'username'             => 'newbie',
    'email'                => 'newbie@example.test',
    'email_confirmation'   => 'newbie@example.test',
    'password'             => AT_PASSWORD,
    'password_confirmation' => AT_PASSWORD,
];

$answer = http_request('POST', $base . '/register', $posted);

ok(
    'the success state says "Your account is ready."',
    str_contains($answer['body'], 'Your account is ready.'),
    'status=' . (string) $answer['status']
);
ok('the success state offers a "Go to sign in" button', str_contains($answer['body'], 'Go to sign in'));
ok('the success state replaces the form', !str_contains($answer['body'], '<form'));
ok(
    'the success state shows the escaped address',
    str_contains($answer['body'], 'newbie@example.test')
);

$stillGuest = http_request('GET', $base . '/login');

ok(
    'the registration does not sign the new account in',
    $stillGuest['status'] === 200,
    'status=' . (string) $stillGuest['status']
);

$created = $db['pdo']->query("SELECT id FROM users WHERE username = 'newbie'")->fetch();

ok('the account was created', is_array($created));

// The confirmation mail and the token.
$messages = mailer_messages($sandbox);

ok('the confirmation mail was sent', count($messages) === 1, (string) count($messages));

if ($messages !== []) {
    $message = $messages[0];

    ok('the mail goes to the new address', ($message['to'] ?? '') === 'newbie@example.test');

    $link = '';

    if (preg_match('#(https?://[^\s]+/verify-email\?token=[a-f0-9]+)#', (string) $message['body'], $m) === 1) {
        $link = $m[1];
    }

    ok('the confirmation carries a link', $link !== '', (string) $message['body']);
    ok(
        'the link is built from the server name, not from the Host: header',
        str_starts_with($link, 'http://auth.test/verify-email?token='),
        $link
    );

    $rawToken = '';
    $queryPos = strpos($link, 'token=');

    if ($queryPos !== false) {
        $rawToken = substr($link, $queryPos + 6);
    }

    ok('the token is 64 hexadecimal characters', preg_match('/^[a-f0-9]{64}$/', $rawToken) === 1, $rawToken);

    $stored = $db['pdo']->query(
        "SELECT token_hash FROM email_verifications WHERE user_id = " . (int) $created['id']
    )->fetch();

    ok(
        'only the hash of the token is stored',
        is_array($stored) && hash_equals((string) $stored['token_hash'], hash('sha256', $rawToken)),
        'stored=' . json_encode($stored)
    );
    ok(
        'the raw token is not in the table',
        !str_contains((string) file_get_contents($root . '/database/schema.sql'), $rawToken)
    );

    // The confirmation itself.
    $confirm = http_request('GET', $base . '/verify-email?token=' . $rawToken);

    ok(
        'the confirmation says "Your email address is confirmed."',
        str_contains($confirm['body'], 'Your email address is confirmed.'),
        'status=' . (string) $confirm['status']
    );

    $verified = $db['pdo']->query('SELECT email_verified_at FROM users WHERE id = ' . (int) $created['id'])->fetch();

    ok('the address is confirmed in the database', is_array($verified) && $verified['email_verified_at'] !== null);

    $again = http_request('GET', $base . '/verify-email?token=' . $rawToken);

    ok(
        'the confirmation link is single-use',
        str_contains($again['body'], 'This confirmation link is no longer valid.'),
        'the second visit still confirmed'
    );
}

// --- B7: the closed registration -------------------------------------------

echo "\n## B7. The closed registration\n";

$closedBase = $closedServer['base'];

reset_cookies();

$closedGet = http_request('GET', $closedBase . '/register');

ok('the closed register screen answers with a 403', $closedGet['status'] === 403, (string) $closedGet['status']);
ok('the closed screen renders no form', !str_contains($closedGet['body'], '<form'));
ok(
    'the closed screen says registration is closed',
    str_contains($closedGet['body'], 'Registration is closed')
);

$closedToken = csrf_of($closedGet['body']);

$closedPost = http_request('POST', $closedBase . '/register', [
    'csrf_token'           => $closedToken,
    'username'             => 'closed-user',
    'email'                => 'closed@example.test',
    'email_confirmation'   => 'closed@example.test',
    'password'             => AT_PASSWORD,
    'password_confirmation' => AT_PASSWORD,
]);

ok(
    'the closed screen refuses the POST with a 403',
    $closedPost['status'] === 403,
    'status=' . (string) $closedPost['status']
);

$closedUser = $db['pdo']->query("SELECT id FROM users WHERE username = 'closed-user'")->fetch();

ok('the refused registration created nothing', $closedUser === false);

$closedLogin = http_request('GET', $closedBase . '/login');

ok(
    'the sign-in screen carries no "Create an account" link',
    !str_contains($closedLogin['body'], 'Create an account'),
    'the link is on the page'
);
ok(
    'the sign-in screen offers the forgotten password instead',
    str_contains($closedLogin['body'], 'Forgot password?')
);

// --- B8: the forgot password ------------------------------------------------

echo "\n## B8. The forgot password\n";

reset_cookies();
set_mailer_mode($sandbox, 'ok');
clear_mailer_log($sandbox);

$res = http_request('GET', $base . '/forgot-password');

ok('the screen answers with a 200', $res['status'] === 200, (string) $res['status']);
ok('the form has the email field', str_contains($res['body'], 'name="email"'));
ok('the button reads "Send reset link"', str_contains($res['body'], '>Send reset link<'));
ok(
    'the screen title is "Reset your password"',
    str_contains($res['body'], '<h2>Reset your password</h2>')
);

$token = csrf_of($res['body']);

$known = http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'forgetter@example.test',
]);
$unknown = http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'nobody@example.test',
]);

ok('a known address is a 200', $known['status'] === 200, (string) $known['status']);
ok('an unknown address is a 200', $unknown['status'] === 200, (string) $unknown['status']);

ok(
    'the confirmation reads "If that address has an account, a reset link is on its way."',
    str_contains($known['body'], 'If that address has an account, a reset link is on its way.')
);
ok(
    'the confirmation says the link is valid for one hour and can be used once',
    str_contains($known['body'], 'The link is valid for one hour and can be used once.')
);
ok(
    'the two responses are word-for-word identical',
    md5($known['body']) === md5($unknown['body']),
    'the bodies differ'
);
ok(
    'the response takes the same time either way',
    abs($known['elapsed'] - $unknown['elapsed']) < 350.0,
    'known=' . round($known['elapsed']) . 'ms unknown=' . round($unknown['elapsed']) . 'ms'
);
ok(
    'the response is not faster than the timing floor',
    $known['elapsed'] >= 450.0 && $unknown['elapsed'] >= 450.0,
    'known=' . round($known['elapsed']) . 'ms unknown=' . round($unknown['elapsed']) . 'ms'
);

$rows = $db['pdo']->query('SELECT COUNT(*) AS c FROM password_resets WHERE user_id = ' . $forgetterId)->fetch();

ok(
    'one reset row was created for the known address',
    is_array($rows) && (int) $rows['c'] === 1,
    'rows=' . json_encode($rows)
);

$noRows = $db['pdo']->query('SELECT COUNT(*) AS c FROM password_resets WHERE user_id = ' . $demoId)->fetch();

ok('no reset row was created for the unknown address', is_array($noRows) && (int) $noRows['c'] === 0);

$messages = mailer_messages($sandbox);

ok('exactly one mail was sent', count($messages) === 1, (string) count($messages));

$resetLink = '';

if ($messages !== []) {
    $message = $messages[0];

    ok('the mail goes to the known address', ($message['to'] ?? '') === 'forgetter@example.test');

    if (preg_match('#(https?://[^\s]+/reset-password\?token=[a-f0-9]+)#', (string) $message['body'], $m) === 1) {
        $resetLink = $m[1];
    }

    ok('the mail carries the reset link', $resetLink !== '');
    ok(
        'the link is built from the server name, not from the Host: header',
        str_starts_with($resetLink, 'http://auth.test/reset-password?token='),
        $resetLink
    );
}

// The link is built from SERVER_NAME even when the client sends another
// Host: header.
$hostAttack = http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'hosted@example.test',
], ['Host: evil.example.com']);

$messages = mailer_messages($sandbox);
$attackLink = '';

if ($messages !== []) {
    $last = $messages[count($messages) - 1];

    if (preg_match('#(https?://[^\s]+/reset-password\?token=[a-f0-9]+)#', (string) $last['body'], $m) === 1) {
        $attackLink = $m[1];
    }
}

ok(
    'a Host: header cannot point the link elsewhere',
    $attackLink !== '' && str_starts_with($attackLink, 'http://auth.test/reset-password?token='),
    $attackLink
);

// The send-failed state.
//
// The account is the hosted one: issuing a reset token replaces the live one
// of that account, and the reset flow below still needs the forgetter's
// first token.
set_mailer_mode($sandbox, 'fail');

$failed = http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'hosted@example.test',
]);
$failedUnknown = http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'nobody@example.test',
]);

ok(
    'a failed send reads "The reset link could not be sent. Please try again."',
    str_contains($failed['body'], 'The reset link could not be sent. Please try again.')
);
ok('the failed screen keeps the form', str_contains($failed['body'], '<form'));
ok('the button becomes "Try again"', str_contains($failed['body'], '>Try again<'));
ok(
    'the send-failed state is shown only when a real account matched',
    !str_contains($failedUnknown['body'], 'The reset link could not be sent.'),
    'an unknown address got the failure state'
);

$leaks = [];

foreach (['SMTP', 'smtp', '587', 'Connection refused', 'SQLSTATE', 'PDOException'] as $needle) {
    if (str_contains($failed['body'], $needle)) {
        $leaks[] = $needle;
    }
}

ok('the failed screen leaks no SMTP detail', $leaks === [], implode(', ', $leaks));

// The not-configured state.
set_mailer_mode($sandbox, 'off');

$offKnown = http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'forgetter@example.test',
]);
$offUnknown = http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'nobody@example.test',
]);

ok(
    'the not-configured state reads "Email is not configured for this installation, so reset links cannot be sent."',
    str_contains($offKnown['body'], 'Email is not configured for this installation, so reset links cannot be sent.')
);
ok(
    'the not-configured state is shown for an unknown address too',
    str_contains($offUnknown['body'], 'Email is not configured for this installation, so reset links cannot be sent.')
);
ok(
    'the two not-configured responses are identical',
    md5($offKnown['body']) === md5($offUnknown['body'])
);

set_mailer_mode($sandbox, 'ok');

// --- B9: the reset ----------------------------------------------------------

echo "\n## B9. The reset\n";

reset_cookies();

$rawToken = '';
$queryPos = strpos($resetLink, 'token=');

if ($queryPos !== false) {
    $rawToken = substr($resetLink, $queryPos + 6);
}

$stored = $db['pdo']->query(
    'SELECT token_hash FROM password_resets WHERE user_id = ' . $forgetterId
)->fetch();

ok(
    'only the hash of the reset token is stored',
    is_array($stored) && hash_equals((string) $stored['token_hash'], hash('sha256', $rawToken)),
    'stored=' . json_encode($stored)
);

$res = http_request('GET', $base . '/reset-password?token=' . $rawToken);

ok('the reset screen answers with a 200', $res['status'] === 200, (string) $res['status']);
ok('the reset screen has the password fields', str_contains($res['body'], 'name="password"'));
ok(
    'the token is carried in a hidden field',
    str_contains($res['body'], 'name="token"')
);

$token = csrf_of($res['body']);

$answer = http_request('POST', $base . '/reset-password', [
    'csrf_token'           => $token,
    'token'                => $rawToken,
    'password'             => AT_NEW_PASSWORD,
    'password_confirmation' => AT_NEW_PASSWORD,
]);

ok(
    'the reset says "Your password has been changed."',
    str_contains($answer['body'], 'Your password has been changed.'),
    'status=' . (string) $answer['status']
);
ok(
    'the reset says "You can now sign in with your new password."',
    str_contains($answer['body'], 'You can now sign in with your new password.')
);
ok('the reset offers a "Go to sign in" button', str_contains($answer['body'], 'Go to sign in'));

$hash = $db['pdo']->query('SELECT password_hash FROM users WHERE id = ' . $forgetterId)->fetch();

ok(
    'the password is stored hashed',
    is_array($hash) && str_starts_with((string) $hash['password_hash'], '$'),
    'hash=' . json_encode($hash)
);
ok(
    'the new password verifies against the stored hash',
    is_array($hash) && password_verify(AT_NEW_PASSWORD, (string) $hash['password_hash'])
);
ok(
    'the old password does not verify any more',
    is_array($hash) && !password_verify(AT_PASSWORD, (string) $hash['password_hash'])
);

// The token is single-use.
$again = http_request('GET', $base . '/reset-password?token=' . $rawToken);

ok(
    'the reset link is single-use',
    str_contains($again['body'], 'This reset link is no longer valid. Request a new one.'),
    'the second visit still worked'
);

// The new password signs the account in.
reset_cookies();

$res     = http_request('GET', $base . '/login');
$token   = csrf_of($res['body']);
$signIn  = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'forgetter',
    'password'   => AT_NEW_PASSWORD,
]);

ok('the new password signs the account in', $signIn['status'] === 302, (string) $signIn['status']);

// The expired token.
clear_mailer_log($sandbox);

http_request('POST', $base . '/forgot-password', [
    'csrf_token' => $token,
    'email'       => 'forgetter@example.test',
]);

$messages = mailer_messages($sandbox);
$secondLink = '';

if ($messages !== []) {
    $last = $messages[count($messages) - 1];

    if (preg_match('#(https?://[^\s]+/reset-password\?token=[a-f0-9]+)#', (string) $last['body'], $m) === 1) {
        $secondLink = $m[1];
    }
}

$secondToken = '';
$queryPos = strpos($secondLink, 'token=');

if ($queryPos !== false) {
    $secondToken = substr($secondLink, $queryPos + 6);
}

$db['pdo']->exec(
    "UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = " . $forgetterId
);

$expired = http_request('GET', $base . '/reset-password?token=' . $secondToken);

ok(
    'an expired token is refused',
    str_contains($expired['body'], 'This reset link is no longer valid. Request a new one.'),
    'the expired token still worked'
);
ok(
    'the expired screen renders no password field',
    !str_contains($expired['body'], 'name="password"')
);

// --- B10: the disabled account and the unexpected error ---------------------

echo "\n## B10. The disabled account and the unexpected error\n";

reset_cookies();

$res   = http_request('GET', $base . '/login');
$token = csrf_of($res['body']);

$disabledAnswer = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'blocked',
    'password'   => AT_PASSWORD,
]);

ok(
    'a disabled account reads "This account is not available."',
    str_contains($disabledAnswer['body'], 'This account is not available.'),
    'status=' . (string) $disabledAnswer['status']
);
ok(
    'the disabled message reveals no reason',
    !str_contains($disabledAnswer['body'], 'disabled')
        && !str_contains($disabledAnswer['body'], 'suspended'),
    'the page says why'
);

// The unexpected error: the users table is not there for a moment.
$db['pdo']->exec('RENAME TABLE `users` TO `users_hidden`');

$broken = http_request('POST', $base . '/login', [
    'csrf_token' => $token,
    'username'   => 'demo',
    'password'   => AT_PASSWORD,
]);

$db['pdo']->exec('RENAME TABLE `users_hidden` TO `users`');

ok(
    'an unexpected error reads "Something went wrong on our side. Please try again."',
    str_contains($broken['body'], 'Something went wrong on our side. Please try again.'),
    'status=' . (string) $broken['status']
);

$leaks = [];

foreach (['SQLSTATE', 'PDOException', 'Base table', 'not found', 'SELECT', 'users_hidden'] as $needle) {
    if (str_contains($broken['body'], $needle)) {
        $leaks[] = $needle;
    }
}

ok('the unexpected error leaks nothing', $leaks === [], implode(', ', $leaks));

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

echo "ALL ASSERTIONS PASSED\n";
exit(0);
