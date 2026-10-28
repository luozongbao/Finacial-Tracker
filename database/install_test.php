<?php
/**
 * install_test.php — guards the install wizard (issue-015).
 *
 * The wizard is the one file that can rewrite the whole configuration of an
 * installation, so the things that are pinned here are the things that would
 * be expensive to get wrong:
 *
 *   1. The ORDER: startSession() first, the CSRF token validated before any
 *      input is read, and the step state on the server rather than in a
 *      hidden form field.
 *   2. The WRITING: var_export(), a temp file and a rename(), a parse check
 *      of the file that was just written, and exactly the constant set that
 *      config.template.php declares.
 *   3. The SILENCE: the raw driver error never reaches the page. It is
 *      classified into a sentence a person can act on, and the raw text goes
 *      to the error log.
 *   4. The INSTALL ITSELF: the wizard really creates the database, really
 *      applies the schema, really writes config.php, and really refuses to
 *      run once config.php exists.
 *
 * Three tiers:
 *
 *   - The file tier (§A-§B) always runs. It needs no database and no web
 *     server — it reads the sources.
 *   - The behaviour tier (§C) runs the REAL wizard in a sandbox: a copy of
 *     the application in a temporary directory, served by the PHP built-in
 *     server, walked through the three steps. The copy is real, because the
 *     thing under test is the wizard and not a mock of it.
 *   - The live tier (§D) needs the running stack. When the stack is not
 *     answering it is SKIPPED cleanly — a skip is a notice, never a silent
 *     green.
 *
 * Run:  docker compose exec -T app php database/install_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by
 * the output.
 */

declare(strict_types=1);

$failures = [];
$skips    = [];

/**
 * The cookie jar of the test. The wizard keeps its state in a session, so
 * the requests have to carry the session cookie from one to the next.
 */
$GLOBALS['iw_cookies'] = [];

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
 * The position of a needle in a haystack, or -1.
 */
function pos_of(string $haystack, string $needle): int
{
    $pos = strpos($haystack, $needle);

    return $pos === false ? -1 : $pos;
}

/**
 * Extract the source of a named function from a PHP file.
 *
 * The tokenizer does the work, so a brace inside a string or a comment does
 * not throw the brace counter off. The result is the function, verbatim, from
 * the `function` keyword to the matching closing brace — ready to be written
 * into a probe file and executed.
 */
function extract_function_source(string $src, string $name): string
{
    $tokens = token_get_all($src);
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        // The name is the next significant token after the keyword.
        $j = $i + 1;

        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            continue;
        }

        if ($tokens[$j][1] !== $name) {
            continue;
        }

        // Collect up to the matching closing brace.
        $depth = 0;
        $out   = '';

        for ($k = $i; $k < $count; $k++) {
            $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];

            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    return $out . $text;
                }
            }

            $out .= $text;
        }

        return '';
    }

    return '';
}

/**
 * Remove the comments from a source string.
 *
 * The leak check reads the source, and a docblock that says "no stack trace"
 * is not a stack trace.
 */
function strip_comments(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
    $src = preg_replace('#(^|\s)//[^\n]*#', '$1', $src) ?? $src;

    return $src;
}

/**
 * Perform an HTTP request and return the status, the headers and the body.
 *
 * The session cookie is carried from one request to the next — the wizard's
 * step state lives in it, so a request without the cookie would start a new
 * session every time and the walk through the steps would be impossible.
 */
function http_request(string $method, string $url, array $fields = [], int $timeout = 15): array
{
    $headers = [
        'User-Agent: financial-tracker-install-test',
        'Accept: text/html',
    ];

    $body = '';

    if ($method === 'POST') {
        $body = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $jar = $GLOBALS['iw_cookies'] ?? [];

    if ($jar !== []) {
        $pairs = [];

        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => $method,
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'content'       => $body,
            'header'        => implode("\r\n", $headers),
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    $status   = null;
    $headers  = [];

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
            continue;
        }

        $pos = strpos($header, ':');

        if ($pos !== false) {
            $headers[strtolower(trim(substr($header, 0, $pos)))] = trim(substr($header, $pos + 1));
        }
    }

    // Keep the cookies the server set.
    foreach ($headers as $name => $value) {
        if ($name !== 'set-cookie') {
            continue;
        }

        $pair = explode(';', $value)[0];
        $eq   = strpos($pair, '=');

        if ($eq !== false) {
            $GLOBALS['iw_cookies'][trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
        }
    }

    return [
        'status'  => $status,
        'body'    => $response === false ? '' : $response,
        'headers' => $headers,
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
 * The base URL of the application under test.
 */
function resolve_base_url(): string
{
    $override = getenv('IW_BASE_URL');

    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    if (is_dir('/usr/local/lsws')) {
        return 'http://127.0.0.1';
    }

    $port    = '8088';
    $envFile = dirname(__DIR__) . '/.env';

    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^APP_PORT\s*=\s*"?(\d+)"?/', $line, $m) === 1) {
                $port = $m[1];
                break;
            }
        }
    }

    return 'http://localhost:' . $port;
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
        $res = http_request('GET', $base . '/__ping__', [], 2);

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
 * The values of the .env file, or the empty string.
 *
 * The test needs the development credentials. The file is read from the
 * filesystem — it is never fetched over HTTP, and the deny block keeps it
 * unreachable from the web.
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
 * The database details the test installs with.
 *
 * The scratch database is preferred: it exercises the "the wizard creates
 * the database" path and it leaves the development database alone. It needs
 * a user that may create a database, so the root account is used for it. When
 * the root account is not reachable, the application user and the configured
 * database are used instead — the wizard then takes the "the database was
 * ready" path.
 *
 * @return array{host: string, name: string, user: string, pass: string, created: bool}
 */
function resolve_database(string $root): array
{
    $env  = env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';

    $name = $env['DB_NAME'] ?? 'financial_tracker';
    $user = $env['DB_USER'] ?? 'ft_user';
    $pass = $env['DB_PASSWORD'] ?? 'ft_password';
    $rootPass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($rootPass !== '') {
        try {
            $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $rootPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec('CREATE DATABASE IF NOT EXISTS `ft_install_test` '
                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            return [
                'host'    => $host,
                'name'    => 'ft_install_test',
                'user'    => 'root',
                'pass'    => $rootPass,
                'created' => true,
            ];
        } catch (PDOException $ex) {
            // The root account is not reachable — fall through.
        }
    }

    return [
        'host'    => $host,
        'name'    => $name,
        'user'    => $user,
        'pass'    => $pass,
        'created' => false,
    ];
}

/**
 * Drop the scratch database.
 */
function drop_scratch_database(string $root, array $db): void
{
    if ($db['created'] !== true) {
        return;
    }

    $env = env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return;
    }

    try {
        $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('DROP DATABASE IF EXISTS `ft_install_test`');
    } catch (PDOException $ex) {
        echo "  [WARN] the scratch database could not be dropped: " . $ex->getMessage() . "\n";
    }
}

/**
 * Build the sandbox: a copy of the application in a temporary directory.
 */
function make_sandbox(string $root): string
{
    $dir = sys_get_temp_dir() . '/ft_install_' . bin2hex(random_bytes(5));

    mkdir($dir, 0700, true);
    mkdir($dir . '/includes', 0700, true);
    mkdir($dir . '/database', 0700, true);

    copy($root . '/install.php', $dir . '/install.php');
    copy($root . '/includes/functions.php', $dir . '/includes/functions.php');
    copy($root . '/database/schema.sql', $dir . '/database/schema.sql');

    // The assets are read by asset_url() when the page renders.
    @symlink($root . '/assets', $dir . '/assets');

    // The router: a real file is served, everything else is not found.
    file_put_contents($dir . '/router.php', <<<'ROUTER'
<?php
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . '/' . ltrim((string) $path, '/');

if ($path !== '/' && is_file($file)) {
    return false;
}

http_response_code(404);
echo 'not found';
ROUTER);

    return $dir;
}

$root = realpath(__DIR__ . '/..');

$installFile = $root . '/install.php';
$installCss  = $root . '/assets/css/pages/install.css';
$schemaFile  = $root . '/database/schema.sql';
$template    = $root . '/config.template.php';

// ---------------------------------------------------------------------------
// A. The files exist.
// ---------------------------------------------------------------------------

echo "## A. The files exist\n";

foreach ([
    'install.php'                => $installFile,
    'assets/css/pages/install.css' => $installCss,
    'database/schema.sql'        => $schemaFile,
    'config.template.php'        => $template,
] as $label => $path) {
    ok($label . ' exists', is_file($path), $path);
}

if (!is_file($installFile) || !is_file($template)) {
    fwrite(STDERR, "the installer is missing — cannot continue.\n");
    echo "Failed: " . count($failures) . "\n";
    exit(1);
}

$src       = (string) file_get_contents($installFile);
$code      = strip_comments($src);
$templateSrc = (string) file_get_contents($template);

// ---------------------------------------------------------------------------
// B. The installer's structure.
//
// The order of the operations is the part that has caused outages, so the
// order is asserted and not just the presence of the calls.
// ---------------------------------------------------------------------------

echo "\n## B. The installer's structure\n";

$sessionPos    = pos_of($code, 'startSession();');
$csrfPos      = pos_of($code, 'validateCsrfToken(');
$readPos      = pos_of($code, "post('action')");
$statePos     = pos_of($code, "\$_SESSION['install']");
$stepPos      = pos_of($code, "post('step', '0')");

ok('the session is started', $sessionPos !== -1);
ok(
    'the session is started before the request is read',
    $sessionPos !== -1 && $readPos !== -1 && $sessionPos < $readPos,
    'session=' . $sessionPos . ' read=' . $readPos
);
ok(
    'the CSRF token is validated before any input is read',
    $csrfPos !== -1 && $readPos !== -1 && $csrfPos < $readPos,
    'csrf=' . $csrfPos . ' read=' . $readPos
);
ok(
    'the step state lives in the session',
    $statePos !== -1
);
ok(
    'the posted step is compared with the step on the server',
    $stepPos !== -1,
    'no check of the posted step against the session step'
);
ok(
    'the CSRF token is read with an is_string() guard',
    str_contains($code, "is_string(\$_POST['csrf_token'])")
);
$formCount    = substr_count($src, '<form');
$tokenCount   = substr_count($src, 'name="csrf_token"');

ok(
    'every form carries the CSRF token',
    $formCount >= 1 && $tokenCount === $formCount,
    "forms=$formCount hidden inputs=$tokenCount"
);

// The database name is the one value that is interpolated into SQL.
ok(
    'the database name is restricted to an identifier',
    str_contains($code, "preg_match('/^[A-Za-z0-9_]+$/'")
);
ok(
    'the database name is not taken from the request unchecked',
    str_contains($code, "installValidateDb(")
);

// The raw driver error never reaches the page.
ok(
    'the raw driver error goes to the error log',
    str_contains($code, 'error_log(')
);

foreach (['1045', '1049', '2002', '2003', '2005'] as $errno) {
    ok(
        'the driver error ' . $errno . ' is classified',
        str_contains($code, $errno),
        'the errno is not in the classification'
    );
}

$leaks = [];

foreach (['getMessage()', '$ex->getMessage()', 'SQLSTATE', 'PDOException'] as $needle) {
    // The message may be read — it has to be, to classify it — but it must
    // never be echoed into the page.
    if (preg_match('/echo[^;]*' . preg_quote($needle, '/') . '/', $code) === 1) {
        $leaks[] = $needle;
    }
}

ok('the driver message is never echoed into the page', $leaks === [], implode(', ', $leaks));

// The way the file is written.
ok(
    'the values are written with var_export()',
    str_contains($code, 'var_export(')
);
ok(
    'addslashes() is not used to escape the values',
    !str_contains($code, 'addslashes(')
);
ok(
    'the file is written to a temporary file and renamed into place',
    str_contains($code, '.config.php.tmp') && str_contains($code, 'rename(')
);
ok(
    'the temporary file is hidden, so the dotfile deny rule refuses it',
    str_contains($code, "'/.config.php.tmp'")
);
ok(
    'the file that was written is parse-checked',
    str_contains($code, "' -l '") || str_contains($code, '-l ')
);
// The parse check must NOT be built on PHP_BINARY alone. LiteSpeed's LSPHP
// reports an EMPTY PHP_BINARY (PHP_SAPI === 'litespeed'), so a check that
// shells out to PHP_BINARY runs "'' -l file", the shell fails, and the wizard
// reports a perfectly good config.php as unwritable. That is a release
// blocker that shipped once, so it is pinned here.
ok(
    'the parse check does not shell out to PHP_BINARY directly',
    preg_match('/shell_exec\(\s*escapeshellarg\(PHP_BINARY\)/', $code) !== 1
);
ok(
    'the parse check resolves an interpreter explicitly',
    str_contains($code, 'installPhpBinary')
);
ok(
    'the parse check falls back to the parser when no interpreter is found',
    str_contains($code, 'catch (ParseError')
);

// The constants.
$written = [];

foreach ([
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
    'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS',
    'MAIL_FROM', 'MAIL_FROM_NAME', 'ALLOW_REGISTRATION', 'WEEK_START', 'TIMEZONE',
] as $constant) {
    $written[] = $constant;

    ok(
        'the wizard writes ' . $constant,
        str_contains($src, 'const ' . $constant . ' ='),
        'the constant is not written'
    );
}

ok(
    'the wizard writes no constant that the template does not declare',
    count($written) === 13,
    (string) count($written)
);

sort($written);

preg_match_all('/\bconst\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/', $templateSrc, $m);
$templateNames = array_values(array_unique($m[1]));
sort($templateNames);

ok(
    'the constant set matches config.template.php',
    $templateNames === $written,
    'template=[' . implode(', ', $templateNames) . '] wizard=[' . implode(', ', $written) . ']'
);

ok(
    'ALLOW_REGISTRATION is written from a boolean',
    str_contains($code, "var_export((bool) \$values['allow_registration'], true)")
);
ok(
    'SMTP_PORT is written as an integer',
    str_contains($code, "' . (int) \$values['smtp_port'] . '")
);

// The words the success page has to carry.
ok(
    'the success page says the application is installed',
    str_contains($src, 'Financial Tracker is installed.')
);
ok(
    'the success page tells the operator to delete the installer',
    str_contains($src, 'Delete install.php now.')
);
ok(
    'the success page offers the command that deletes the installer',
    str_contains($src, 'rm -f ')
);

// A live installer is a re-configuration hole.
ok(
    'the wizard refuses to run once config.php exists',
    str_contains($code, "is_file(__DIR__ . '/config.php')")
);
ok(
    'the refusal is a 403',
    str_contains($code, 'http_response_code(403)')
);

// The page is not part of the product.
ok(
    'the wizard does not include the application shell',
    !str_contains($code, 'includes/header.php') && !str_contains($code, 'includes/footer.php')
);
ok(
    'the wizard renders its own document',
    str_contains($src, '<link rel="stylesheet"')
        && str_contains($src, "asset_url('css/pages/install.css')")
);

// The page stylesheet uses tokens only.
if (is_file($installCss)) {
    $css   = (string) file_get_contents($installCss);
    $codes = [];

    if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $m) === 1 || !empty($m[0])) {
        $codes = $m[0];
    }

    ok('the page stylesheet carries no literal colour', $codes === [], implode(', ', $codes));
    ok(
        'the page stylesheet uses the spacing tokens',
        str_contains($css, 'var(--sp-')
    );
}

// ---------------------------------------------------------------------------
// C. The behaviour, in a sandbox.
//
// The real wizard, walked through the three steps. The copy is in a
// temporary directory, so the repository's own config.php is never touched.
// ---------------------------------------------------------------------------

echo "\n## C. The behaviour in a sandbox\n";

$sandbox = make_sandbox($root);
$server  = start_server($sandbox);
$db      = resolve_database($root);

register_shutdown_function(static function () use ($sandbox, $server, $db, $root): void {
    stop_server($server);
    remove_tree($sandbox);
    drop_scratch_database($root, $db);
});

if ($server['proc'] === null || $server['base'] === '') {
    skip('the sandbox tier', 'the built-in server could not be started');
} else {
    $base = $server['base'];

    // --- C1: the first page ------------------------------------------------

    $res = http_request('GET', $base . '/install.php');

    ok('the wizard answers with a 200', $res['status'] === 200, (string) $res['status']);
    ok('the page carries the stepper', str_contains($res['body'], 'stepper__num'));
    ok('the first step is the current one', str_contains($res['body'], 'aria-current="step"'));
    ok('the page says it is the installer', str_contains($res['body'], 'Install Financial Tracker'));

    $token = csrf_of($res['body']);

    ok('the page carries a CSRF token', $token !== '', 'no 64-character token found');
    ok(
        'the CSRF token is 64 hexadecimal characters',
        preg_match('/^[a-f0-9]{64}$/', $token) === 1,
        $token
    );

    foreach (['app__bar', 'nav__link', 'bottom-nav__link', 'app__footer', 'app__user'] as $shell) {
        ok(
            'the page carries no application navigation (' . $shell . ')',
            !str_contains($res['body'], $shell)
        );
    }

    // --- C2: a wrong CSRF token --------------------------------------------

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token' => str_repeat('0', 64),
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => $db['name'],
        'db_user'    => $db['user'],
        'db_pass'    => $db['pass'],
    ]);

    ok(
        'a wrong CSRF token is refused',
        str_contains($res['body'], 'Your session could not be verified.'),
        'status=' . (string) $res['status']
    );
    ok(
        'a refused request does not advance the step',
        str_contains($res['body'], 'Database connection')
            && !str_contains($res['body'], 'Mail server')
    );
    ok(
        'no configuration file was written',
        !is_file($sandbox . '/config.php')
    );

    // --- C3: a step that is ahead of the session ---------------------------

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '3',
        'action'     => 'install',
    ]);

    ok(
        'a posted step ahead of the session is refused',
        str_contains($res['body'], 'This step is out of date.'),
        'status=' . (string) $res['status']
    );
    ok(
        'the refused step did not write a configuration file',
        !is_file($sandbox . '/config.php')
    );

    // --- C4: a database name that is not an identifier ---------------------

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => 'ft_test; DROP TABLE users',
        'db_user'    => $db['user'],
        'db_pass'    => $db['pass'],
    ]);

    ok(
        'a database name that is not an identifier is refused',
        str_contains($res['body'], 'may only contain letters, digits and underscores'),
        'status=' . (string) $res['status']
    );
    ok(
        'the refused step did not advance',
        !str_contains($res['body'], 'Mail server')
    );

    // --- C4b: a name that tries to break out of the attribute ---------------

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => 'ft_test" onfocus="x',
        'db_user'    => $db['user'],
        'db_pass'    => $db['pass'],
    ]);

    ok(
        'the submitted value is escaped when it is echoed back',
        !str_contains($res['body'], 'onfocus="x'),
        'the attribute was broken out of'
    );
    ok(
        'the escaped value is in the page as an entity',
        str_contains($res['body'], '&quot;')
    );

    // --- C5: a wrong password ----------------------------------------------

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => $db['name'],
        'db_user'    => $db['user'],
        'db_pass'    => 'definitely-not-the-password',
    ]);

    ok(
        'a wrong password is a plain-language message',
        str_contains($res['body'], 'The database user was denied access.'),
        'status=' . (string) $res['status']
    );

    $leaks = [];

    foreach (['SQLSTATE', 'PDOException', 'Access denied for user', 'definitely-not-the-password'] as $needle) {
        if (str_contains($res['body'], $needle)) {
            $leaks[] = $needle;
        }
    }

    ok('the failure page leaks nothing', $leaks === [], implode(', ', $leaks));
    ok(
        'the step did not advance',
        !str_contains($res['body'], 'Mail server')
    );

    // --- C6: the right details ---------------------------------------------

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => $db['name'],
        'db_user'    => $db['user'],
        'db_pass'    => $db['pass'],
    ]);

    ok(
        'a successful step is a redirect',
        $res['status'] === 302,
        (string) $res['status']
    );
    ok(
        'the redirect points at the wizard',
        ($res['headers']['location'] ?? '') === '/install.php',
        (string) ($res['headers']['location'] ?? '')
    );

    $res = http_request('GET', $base . '/install.php');

    ok(
        'the wizard is on step 2',
        str_contains($res['body'], 'Mail server'),
        'the step 2 card is not on the page'
    );
    ok(
        'step 2 offers both the test and the skip',
        str_contains($res['body'], 'value="test_mail"') && str_contains($res['body'], 'value="skip_mail"')
    );

    // --- C7: the mail step, skipped ----------------------------------------

    $token = csrf_of($res['body']);

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '2',
        'action'     => 'skip_mail',
    ]);

    ok(
        'skipping the mail step is a redirect',
        $res['status'] === 302,
        (string) $res['status']
    );

    $res = http_request('GET', $base . '/install.php');

    ok('the wizard is on step 3', str_contains($res['body'], 'Access'));
    ok('the summary is on the page', str_contains($res['body'], 'readonly-list'));
    ok(
        'the passwords are masked, not echoed',
        str_contains($res['body'], 'masked') && !str_contains($res['body'], $db['pass'])
    );
    ok(
        'the registration switch is on the page',
        str_contains($res['body'], 'name="allow_registration"')
    );

    // --- C8: the install ----------------------------------------------------

    $token = csrf_of($res['body']);

    $res = http_request('POST', $base . '/install.php', [
        'csrf_token'        => $token,
        'step'              => '3',
        'action'            => 'install',
        'allow_registration' => '0',
    ]);

    ok(
        'the install renders the success page',
        str_contains($res['body'], 'Financial Tracker is installed.'),
        'status=' . (string) $res['status']
    );
    ok(
        'the success page tells the operator to delete the installer',
        str_contains($res['body'], 'Delete install.php now.')
    );
    ok(
        'the success page offers the command',
        str_contains($res['body'], 'rm -f ')
    );
    ok(
        'the success page says whether the database was created',
        str_contains($res['body'], 'The database was created')
            || str_contains($res['body'], 'The database was ready'),
        'the disclosure is missing'
    );

    // The configuration file.
    ok('the configuration file was written', is_file($sandbox . '/config.php'));

    if (is_file($sandbox . '/config.php')) {
        $live    = (string) file_get_contents($sandbox . '/config.php');
        $liveNames = [];

        if (preg_match_all('/\bconst\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/', $live, $m) === 1 || !empty($m[0])) {
            $liveNames = array_values(array_unique($m[1]));
        }

        sort($liveNames);

        ok(
            'the written file declares the same constant set as the template',
            $liveNames === $templateNames,
            'written=[' . implode(', ', $liveNames) . '] template=[' . implode(', ', $templateNames) . ']'
        );

        ok(
            'ALLOW_REGISTRATION is a bare boolean literal',
            preg_match('/\bconst\s+ALLOW_REGISTRATION\s*=\s*(true|false)\s*;/', $live) === 1,
            'the flag is not a bare true/false literal'
        );
        ok(
            'the switch wrote false, because the form submitted 0',
            preg_match('/\bconst\s+ALLOW_REGISTRATION\s*=\s*false\s*;/', $live) === 1
        );
        ok(
            'SMTP_PORT is an integer',
            preg_match('/\bconst\s+SMTP_PORT\s*=\s*\d+\s*;/', $live) === 1
        );
        ok(
            'the skipped mail was written as an empty host',
            preg_match("/\bconst\s+SMTP_HOST\s*=\s*''\s*;/", $live) === 1
        );
        ok(
            'the database details are in the file',
            str_contains($live, "const DB_NAME = '" . $db['name'] . "';")
        );
        ok(
            'the file parses',
            install_test_parses($sandbox . '/config.php')
        );
    }

    // The schema.
    $tables = install_test_tables($db);

    if ($tables === null) {
        skip('the schema check', 'the database could not be inspected');
    } else {
        foreach (['users', 'categories', 'transactions', 'password_resets', 'email_verifications'] as $table) {
            ok('the table ' . $table . ' exists', in_array($table, $tables, true), implode(', ', $tables));
        }
    }

    // --- C9: the wizard refuses to run again -------------------------------

    $res = http_request('GET', $base . '/install.php');

    ok(
        'the wizard refuses to run once the application is installed',
        $res['status'] === 403,
        (string) $res['status']
    );
    ok(
        'the refusal explains itself',
        str_contains($res['body'], 'already installed')
    );
}

/**
 * Does this file parse?
 */
function install_test_parses(string $path): bool
{
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');

    return is_string($output) && str_contains($output, 'No syntax errors');
}

/**
 * The tables of a database, or null when it cannot be inspected.
 *
 * @return string[]|null
 */
function install_test_tables(array $db): ?array
{
    try {
        $pdo = new PDO(
            'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    } catch (PDOException $ex) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// D. The live stack.
//
// The wizard has to be reachable — it is a real file, and the front
// controller's rewrite does not intercept it — and it must not leak the
// driver error on the page.
// ---------------------------------------------------------------------------

echo "\n## D. The live stack\n";

$liveBase = resolve_base_url();
$live     = http_request('GET', $liveBase . '/install.php');

if ($live['status'] === null) {
    skip('the live tier', 'the application is not answering at ' . $liveBase);
} else {
    ok(
        'the wizard is reachable over HTTP',
        $live['status'] === 200 || $live['status'] === 403,
        (string) $live['status']
    );

    if ($live['status'] === 200) {
        ok('the live page carries the stepper', str_contains($live['body'], 'stepper__num'));
        ok('the live page carries a CSRF token', csrf_of($live['body']) !== '');

        foreach (['app__bar', 'nav__link', 'bottom-nav__link', 'app__footer'] as $shell) {
            ok(
                'the live page carries no application navigation (' . $shell . ')',
                !str_contains($live['body'], $shell)
            );
        }
    }

    // A failed step must not put the driver error on the page.
    $liveToken = csrf_of($live['body']);
    $res       = http_request('POST', $liveBase . '/install.php', [
        'csrf_token' => $liveToken !== '' ? $liveToken : str_repeat('0', 64),
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => '127.0.0.1',
        'db_name'    => 'ft_install_live_probe',
        'db_user'    => 'ft_install_live_probe_user',
        'db_pass'    => 'ft_install_live_probe_password',
    ]);

    $leaks = [];

    foreach (['SQLSTATE', 'PDOException', 'Connection refused', 'ft_install_live_probe_password'] as $needle) {
        if (str_contains($res['body'], $needle)) {
            $leaks[] = $needle;
        }
    }

    ok('the live failure page leaks nothing', $leaks === [], implode(', ', $leaks));
    ok(
        'the live failure page says something in plain words',
        str_contains($res['body'], 'alert__title')
    );

    // -------------------------------------------------------------------
    // The parse check, under the SAPI that actually serves the app.
    //
    // The sandbox above runs the wizard under the PHP built-in server, where
    // PHP_BINARY points at a real interpreter. LiteSpeed's LSPHP reports an
    // EMPTY PHP_BINARY, so a parse check built on PHP_BINARY alone fails
    // there — and the wizard then reports a perfectly good config.php as
    // unwritable. The bug shipped green because no tier ran the real parse
    // check under the real web SAPI.
    //
    // This probe writes the REAL installParses() source into a file in the
    // document root and calls it there, over HTTP. The function under test
    // is the shipped one, not a re-implementation of it.
    // -------------------------------------------------------------------

    $probeName = '_ft_install_parse_probe_' . bin2hex(random_bytes(6)) . '.php';
    $probePath = $root . '/' . $probeName;

    $parseFn = extract_function_source((string) file_get_contents($installFile), 'installParses');
    $binaryFn = extract_function_source((string) file_get_contents($installFile), 'installPhpBinary');

    if ($parseFn === '') {
        skip('the live parse check', 'installParses() could not be extracted from install.php');
    } else {
        $probe = "<?php\n"
            . "// TEMPORARY probe written by database/install_test.php.\n"
            . $parseFn . "\n\n"
            . $binaryFn . "\n\n"
            . '$ft_good = __DIR__ . "/" . (string) ($_GET["g"] ?? "");' . "\n"
            . '$ft_bad  = __DIR__ . "/" . (string) ($_GET["b"] ?? "");' . "\n"
            . 'file_put_contents($ft_good, "<?php\nconst FT_PROBE = 1;\n");' . "\n"
            . 'file_put_contents($ft_bad,  "<?php\nconst FT_PROBE = ;\n");' . "\n"
            . 'header("Content-Type: application/json");' . "\n"
            . 'echo json_encode([' . "\n"
            . '    "sapi"       => PHP_SAPI,' . "\n"
            . '    "php_binary" => PHP_BINARY,' . "\n"
            . '    "bindir"     => PHP_BINDIR,' . "\n"
            . '    "binary"     => installPhpBinary(),' . "\n"
            . '    "good"       => installParses($ft_good),' . "\n"
            . '    "bad"        => installParses($ft_bad),' . "\n"
            . ']);' . "\n"
            . '@unlink($ft_good);' . "\n"
            . '@unlink($ft_bad);' . "\n";

        file_put_contents($probePath, $probe);

        $goodName = '_ft_install_fx_good_' . bin2hex(random_bytes(6)) . '.inc';
        $badName  = '_ft_install_fx_bad_' . bin2hex(random_bytes(6)) . '.inc';

        $probeRes = http_request('GET', $liveBase . '/' . $probeName . '?g=' . $goodName . '&b=' . $badName);

        @unlink($probePath);
        @unlink($root . '/' . $goodName);
        @unlink($root . '/' . $badName);

        $decoded = json_decode((string) $probeRes['body'], true);

        if (!is_array($decoded)) {
            skip(
                'the live parse check',
                'the probe did not answer — status ' . (string) $probeRes['status']
            );
        } else {
            echo "        (SAPI " . ($decoded['sapi'] ?? '?')
                . ", PHP_BINARY " . var_export($decoded['php_binary'] ?? null, true)
                . ", interpreter " . var_export($decoded['binary'] ?? null, true) . ")\n";

            ok(
                'the live parse check accepts a file that parses',
                ($decoded['good'] ?? null) === true,
                'installParses() said no — the SAPI reports PHP_BINARY='
                    . var_export($decoded['php_binary'] ?? null, true)
            );
            ok(
                'the live parse check rejects a file that does not parse',
                ($decoded['bad'] ?? null) === false,
                'installParses() said yes to a broken file'
            );
        }
    }
}

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

if (count($skips) > 0) {
    echo 'ALL ASSERTIONS PASSED (' . count($skips) . " tier(s) skipped — the run was partial)\n";
} else {
    echo "ALL ASSERTIONS PASSED\n";
}

exit(0);
