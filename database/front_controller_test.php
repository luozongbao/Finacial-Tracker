<?php
/**
 * front_controller_test.php — guards the front controller (issue-014).
 *
 * The front controller is the one file every request passes through, so a
 * silent change in it breaks the whole application. Three things are pinned
 * here:
 *
 *   1. The install gate: no config.php -> 302 to /install.php, and the gate
 *      runs BEFORE the bootstrap — a fresh checkout has no vendor/ yet, and
 *      it must still be able to reach the installer.
 *   2. The error boundary: a 404, a 403, a 405 and a 500 each render a
 *      distinct page, the 405 carries an Allow header, and no error page
 *      leaks a stack trace, a file path or a raw exception message.
 *   3. The rewrite: the .htaccess deny block is still ABOVE the
 *      front-controller rules, so /.env and /config.php are still 403 and
 *      never reach the controller.
 *
 * Three tiers:
 *
 *   - The file tier (§A-§E) always runs. It needs no database and no web
 *     server — it reads the sources.
 *   - The behaviour tier (§F) runs the REAL front controller in a sandbox: a
 *     copy of the application in a temporary directory, served by the PHP
 *     built-in server with a router script that mimics the .htaccess
 *     rewrite. The same sandbox is walked through three configurations — no
 *     config.php, a config.php without vendor/, and a fully installed copy —
 *     because the ORDER of the gate and the bootstrap is only observable in
 *     the difference between those two failures.
 *   - The live tier (§G) needs the running stack. When the stack is not
 *     answering it is SKIPPED cleanly — a skip is a notice, never a silent
 *     green.
 *
 * Run:  docker compose exec -T app php database/front_controller_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by
 * the output.
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
 * The position of a needle in a haystack, or -1.
 */
function pos_of(string $haystack, string $needle): int
{
    $pos = strpos($haystack, $needle);

    return $pos === false ? -1 : $pos;
}

/**
 * Remove the comments from a source string.
 *
 * The leak check reads the source, and a docblock that says "no stack trace"
 * is not a stack trace. The sources guarded here carry no regular-expression
 * literal and no URL, so a line comment runs to the end of its line.
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
 * ignore_errors is on, so a 404 or a 500 does not raise a warning — they
 * are expected results, not error conditions. follow_location is off, so a
 * 302 is observable instead of being followed.
 */
function http_request(string $method, string $url, int $timeout = 10): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'          => $method,
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'content'         => '',
            'header'          => "User-Agent: financial-tracker-front-controller-test\r\n",
        ],
    ]);

    $body   = @file_get_contents($url, false, $ctx);
    $status = null;
    $headers = [];

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

    return [
        'status'  => $status,
        'body'    => $body === false ? '' : $body,
        'headers' => $headers,
    ];
}

/**
 * The base URL of the application under test.
 *
 * Inside the app container the vhost answers on 127.0.0.1:80. On the host
 * the port comes from .env, so the test follows the configuration instead
 * of hard-coding a port. FC_BASE_URL overrides both.
 */
function resolve_base_url(): string
{
    $override = getenv('FC_BASE_URL');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    if (is_dir('/usr/local/lsws')) {
        return 'http://127.0.0.1';
    }

    $port    = '8088';
    $envFile = dirname(__DIR__) . '/.env';

    if (is_file($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
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

    // The server needs a moment to bind the port. Any answer means it is up.
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $res = http_request('GET', $base . '/__ping__', 2);
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

    $items = scandir($dir) ?: [];

    foreach ($items as $item) {
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
 *
 * The copy is real — the same index.php, the same includes/, the same
 * assets/ — because the thing under test is the controller, not a mock of
 * it. The screens under views/ are stand-ins that echo a marker, so the
 * dispatch can be observed without the product screens (issues 016-020)
 * existing yet.
 */
function make_sandbox(string $root): string
{
    $dir = sys_get_temp_dir() . '/ft_fc_' . bin2hex(random_bytes(5));

    mkdir($dir, 0700, true);
    mkdir($dir . '/includes', 0700, true);
    mkdir($dir . '/includes/views', 0700, true);

    copy($root . '/index.php', $dir . '/index.php');

    foreach (['functions.php', 'bootstrap.php', 'Database.php', 'auth.php', 'Router.php', 'header.php', 'footer.php', 'mailer.php'] as $file) {
        copy($root . '/includes/' . $file, $dir . '/includes/' . $file);
    }

    foreach (glob($root . '/includes/views/*.php') ?: [] as $file) {
        copy($file, $dir . '/includes/views/' . basename($file));
    }

    // The assets are read by asset_url() when the shell renders.
    @symlink($root . '/assets', $dir . '/assets');

    // The stand-in screens.
    mkdir($dir . '/views', 0700, true);

    $screens = [
        'login.php', 'register.php', 'forgot_password.php', 'reset_password.php',
        'dashboard.php', 'transactions.php', 'transaction_form.php',
        'transaction_save.php', 'transaction_delete.php', 'reports.php',
        'categories.php', 'category_save.php', 'category_delete.php',
        'settings.php', 'logout.php',
    ];

    foreach ($screens as $screen) {
        file_put_contents(
            $dir . '/views/' . $screen,
            "<?php echo 'SANDBOX_SCREEN:" . $screen . "';"
        );
    }

    // The router script: a mimic of the .htaccess rewrite. A real file is
    // served by the server, everything else goes to the front controller.
    file_put_contents($dir . '/router.php', <<<'ROUTER'
<?php
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . '/' . ltrim((string) $path, '/');

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
ROUTER);

    return $dir;
}

$root = realpath(__DIR__ . '/..');

$indexFile = $root . '/index.php';
$htaccess  = $root . '/.htaccess';
$viewsDir  = $root . '/includes/views';

$errorViews = [
    'error_404.php'      => 'That page does not exist.',
    'error_403.php'      => 'You do not have access to that page.',
    'error_405.php'      => 'That page does not accept this request.',
    'error_500.php'      => 'Something went wrong on our side. Please try again.',
    'error_bootstrap.php' => 'Dependencies are not installed',
];

echo "## A. The files exist\n";

foreach ([
    'index.php'                        => $indexFile,
    'includes/views/error_404.php'     => $viewsDir . '/error_404.php',
    'includes/views/error_403.php'     => $viewsDir . '/error_403.php',
    'includes/views/error_405.php'     => $viewsDir . '/error_405.php',
    'includes/views/error_500.php'     => $viewsDir . '/error_500.php',
    'includes/views/error_bootstrap.php' => $viewsDir . '/error_bootstrap.php',
    '.htaccess'                        => $htaccess,
] as $label => $path) {
    ok($label . ' exists', is_file($path), $path);
}

if (!is_file($indexFile) || !is_file($htaccess)) {
    fwrite(STDERR, "the front controller is missing — cannot continue.\n");
    echo "Failed: " . count($failures) . "\n";
    exit(1);
}

$indexSrc = (string) file_get_contents($indexFile);
$viewSrc  = [];

foreach (array_keys($errorViews) as $view) {
    $viewSrc[$view] = (string) file_get_contents($viewsDir . '/' . $view);
}

// ---------------------------------------------------------------------------
// B. The controller's structure.
//
// The order of the operations is the part that has caused outages, so the
// order is asserted, not just the presence of the calls.
// ---------------------------------------------------------------------------

echo "\n## B. The controller's structure\n";

ok(
    'the install gate is an is_file() check on config.php',
    preg_match('/is_file\(\s*__DIR__\s*\.\s*\'\/config\.php\'\s*\)/', $indexSrc) === 1
);
ok(
    'the gate is not a file_exists() check',
    preg_match('/file_exists\(\s*__DIR__\s*\.\s*\'\/config\.php\'\s*\)/', $indexSrc) !== 1
);
ok(
    'the gate redirects with a 302 to /install.php',
    str_contains($indexSrc, "header('Location: /install.php', true, 302)") === true
);
ok(
    'the gate exits immediately after the redirect',
    preg_match('/header\(\'Location: \/install\.php\', true, 302\);\s*\n\s*exit;/', $indexSrc) === 1
);

$gatePos = pos_of($indexSrc, "is_file(__DIR__ . '/config.php')");
$configRequirePos = pos_of($indexSrc, "require_once __DIR__ . '/config.php';");
$bootstrapPos     = pos_of($indexSrc, "includes/bootstrap.php");
$sessionPos       = pos_of($indexSrc, 'startSession();');
$headerPos        = pos_of($indexSrc, 'X-Content-Type-Options');
$catchHttpPos     = pos_of($indexSrc, 'catch (HttpException');
$catchAnyPos      = pos_of($indexSrc, 'catch (Throwable');
$dispatchPos      = pos_of($indexSrc, '$router->dispatch();');

ok(
    'the gate runs before the bootstrap is required',
    $gatePos !== -1 && $bootstrapPos !== -1 && $gatePos < $bootstrapPos,
    'gate=' . $gatePos . ' bootstrap=' . $bootstrapPos
);
ok(
    'config.php is required after the gate proved it exists',
    $configRequirePos !== -1 && $gatePos !== -1 && $configRequirePos > $gatePos,
    'gate=' . $gatePos . ' require=' . $configRequirePos
);
ok(
    'startSession() runs before the security headers',
    $sessionPos !== -1 && $headerPos !== -1 && $sessionPos < $headerPos,
    'session=' . $sessionPos . ' headers=' . $headerPos
);
ok(
    'the controller sends X-Content-Type-Options',
    str_contains($indexSrc, "header('X-Content-Type-Options: nosniff')")
);
ok(
    'the controller sends X-Frame-Options',
    str_contains($indexSrc, "header('X-Frame-Options: SAMEORIGIN')")
);
ok(
    'the controller sends Referrer-Policy',
    str_contains($indexSrc, "header('Referrer-Policy: same-origin')")
);
ok(
    'the HttpException catch comes before the generic Throwable catch',
    $catchHttpPos !== -1 && $catchAnyPos !== -1 && $catchHttpPos < $catchAnyPos,
    'http=' . $catchHttpPos . ' throwable=' . $catchAnyPos
);

$dispatchTry = $dispatchPos === -1 ? false : strrpos(substr($indexSrc, 0, $dispatchPos), 'try {');
ok(
    'the dispatch runs inside the error boundary',
    $dispatchTry !== false && $catchHttpPos > $dispatchPos,
    'try=' . var_export($dispatchTry, true) . ' dispatch=' . $dispatchPos
);
ok(
    'the route table comes from the router, not from a second copy',
    str_contains($indexSrc, 'Router::withDefaultRoutes()') && !str_contains($indexSrc, "'/transactions/new'")
);

foreach (['error_404.php', 'error_403.php', 'error_405.php', 'error_500.php'] as $view) {
    ok('the controller maps a status to ' . $view, str_contains($indexSrc, $view));
}

ok(
    'the generic catch writes the detail to the error log',
    str_contains($indexSrc, 'error_log(')
);
ok(
    'the 500 page does not echo the exception message',
    !str_contains($viewSrc['error_500.php'], 'getMessage()')
);

// ---------------------------------------------------------------------------
// C. The route table.
//
// The table lives in Router::defaultRoutes() (issue-012 §10) and the
// controller builds its router from it, so the URLs the product answers to
// are listed exactly once in the codebase.
// ---------------------------------------------------------------------------

echo "\n## C. The route table\n";

require_once $root . '/includes/functions.php';
require_once $root . '/includes/Router.php';

$table  = Router::defaultRoutes();
$routes = [];

foreach ($table as $route) {
    $routes[$route[0] . ' ' . $route[1]] = $route[2];
}

// 23 routes from issue-012 plus GET /verify-email, which issue-016 added.
ok('the route table has 24 entries', count($table) === 24, (string) count($table));
ok(
    'the root path redirects to the dashboard',
    ($routes['GET /'] ?? '') === 'redirect:/dashboard',
    (string) ($routes['GET /'] ?? '')
);
ok(
    'every handler is a view or a redirect',
    count(array_filter($table, static function (array $route): bool {
        return str_starts_with($route[2], 'redirect:') || str_starts_with($route[2], 'views/');
    })) === count($table)
);
ok(
    'the guest screens have a GET and a POST',
    isset($routes['GET /login'], $routes['POST /login'])
        && isset($routes['GET /register'], $routes['POST /register'])
        && isset($routes['GET /forgot-password'], $routes['POST /forgot-password'])
        && isset($routes['GET /reset-password'], $routes['POST /reset-password'])
);
ok(
    'the application screens are all registered',
    isset(
        $routes['GET /dashboard'],
        $routes['GET /transactions'],
        $routes['GET /transactions/new'],
        $routes['GET /transactions/edit'],
        $routes['POST /transactions/save'],
        $routes['POST /transactions/delete'],
        $routes['GET /reports'],
        $routes['GET /categories'],
        $routes['POST /categories/save'],
        $routes['POST /categories/delete'],
        $routes['GET /settings'],
        $routes['POST /settings/profile'],
        $routes['POST /settings/password'],
        $routes['POST /logout']
    )
);

// ---------------------------------------------------------------------------
// D. The error views.
//
// They are views: the same shell, the same escaping, the same card. They
// are reached by the controller's boundary through a status map, so the file
// name never comes from the exception.
// ---------------------------------------------------------------------------

echo "\n## D. The error views\n";

foreach ($errorViews as $view => $sentence) {
    ok(
        $view . ' says its sentence',
        str_contains($viewSrc[$view], $sentence),
        $sentence
    );
    ok(
        $view . ' renders in the guest shell',
        str_contains($viewSrc[$view], "\$shell = 'auth';")
    );
    ok(
        $view . ' opens and closes the shared shell',
        str_contains($viewSrc[$view], "header.php") && str_contains($viewSrc[$view], "footer.php")
    );
}

foreach (['error_404.php', 'error_403.php', 'error_405.php', 'error_500.php'] as $view) {
    $leaks  = [];
    $code   = strip_comments($viewSrc[$view]);

    foreach (['getMessage', 'getTraceAsString', 'Stack trace', 'stack trace', 'Fatal error', '.php on line', '$_SERVER'] as $needle) {
        if (str_contains($code, $needle)) {
            $leaks[] = $needle;
        }
    }

    ok(
        $view . ' leaks nothing',
        $leaks === [],
        implode(', ', $leaks)
    );
}

ok(
    'the 405 view repeats the allowed methods',
    str_contains($viewSrc['error_405.php'], 'allowedMethods')
);
ok(
    'the bootstrap page shows the message and the command',
    str_contains($viewSrc['error_bootstrap.php'], 'bootstrapError')
        && str_contains($viewSrc['error_bootstrap.php'], 'composer install')
);

// ---------------------------------------------------------------------------
// E. The .htaccess rewrite.
//
// The deny block is load-bearing: it is above the rewrite, and every denied
// path must still be 403 after this issue.
// ---------------------------------------------------------------------------

echo "\n## E. The .htaccess rewrite\n";

$htaccessSrc = (string) file_get_contents($htaccess);

$denyRules = [
    'the dotfile rule'     => 'RewriteRule "(^|/)\.(?!well-known)" - [F,L]',
    'the config.php rule'  => 'RewriteRule "^/?config\.php$" - [F,L]',
    'the database rule'    => 'RewriteRule "^/?database/"  - [F,L]',
    'the includes rule'    => 'RewriteRule "^/?includes/"  - [F,L]',
    'the docker rule'      => 'RewriteRule "^/?docker/"    - [F,L]',
    'the vendor rule'      => 'RewriteRule "^/?vendor/"    - [F,L]',
    'the manifest rule'    => 'RewriteRule "^/?composer\.(json|lock)$" - [F,L]',
    'the issues rule'      => 'RewriteRule "^/?docs/issues/" - [F,L]',
];

foreach ($denyRules as $label => $rule) {
    ok('the deny block still holds ' . $label, str_contains($htaccessSrc, $rule), $rule);
}

$denyPos    = pos_of($htaccessSrc, 'RewriteRule "^/?docs/issues/" - [F,L]');
$rewritePos = pos_of($htaccessSrc, 'RewriteRule ^ index.php [QSA,L]');

ok(
    'the rewrite is appended below the deny block',
    $denyPos !== -1 && $rewritePos !== -1 && $rewritePos > $denyPos,
    'deny=' . $denyPos . ' rewrite=' . $rewritePos
);
ok(
    'the rewrite skips the paths that exist',
    str_contains($htaccessSrc, 'RewriteCond %{REQUEST_FILENAME} !-f')
        && str_contains($htaccessSrc, 'RewriteCond %{REQUEST_FILENAME} !-d')
);
ok(
    'the rewrite carries the query string and stops rewriting',
    str_contains($htaccessSrc, 'RewriteRule ^ index.php [QSA,L]')
);
ok(
    'the rewrite lives inside the mod_rewrite block',
    str_contains($htaccessSrc, '<IfModule mod_rewrite.c>') && str_contains($htaccessSrc, '</IfModule>')
        && pos_of($htaccessSrc, '<IfModule mod_rewrite.c>') < $rewritePos
        && $rewritePos < pos_of($htaccessSrc, '</IfModule>')
);

// ---------------------------------------------------------------------------
// F. The behaviour, in a sandbox.
//
// The real controller, in three configurations. The difference between the
// first two IS the contract: the gate runs before the bootstrap, so a
// checkout with no vendor/ still reaches the installer.
// ---------------------------------------------------------------------------

echo "\n## F. The behaviour in a sandbox\n";

$sandbox = make_sandbox($root);
$server  = start_server($sandbox);

// The sandbox and the server go away with the test, whatever happens: a
// failed run must not leave a process behind.
register_shutdown_function(static function () use ($sandbox, $server): void {
    stop_server($server);
    remove_tree($sandbox);
});

if ($server['proc'] === null || $server['base'] === '') {
    skip('the sandbox tier', 'the built-in server could not be started');
} else {
    $base = $server['base'];

    // --- F1: no config.php, no vendor/ ------------------------------------
    // The gate must answer, not the bootstrap. If the order were wrong the
    // answer would be a 500 with the composer command in it.

    $res = http_request('GET', $base . '/');
    ok(
        'without config.php the gate answers with a 302',
        $res['status'] === 302,
        (string) $res['status']
    );
    ok(
        'the gate points at /install.php',
        ($res['headers']['location'] ?? '') === '/install.php',
        (string) ($res['headers']['location'] ?? '')
    );
    ok(
        'the gate answers before the bootstrap is reached',
        !str_contains($res['body'], 'composer install')
    );

    // --- F2: a config.php, still no vendor/ --------------------------------

    copy($root . '/config.template.php', $sandbox . '/config.php');

    $res = http_request('GET', $base . '/');
    ok(
        'a missing vendor/ is a 500, not a blank page',
        $res['status'] === 500,
        (string) $res['status']
    );
    ok(
        'the bootstrap page shows the composer command',
        str_contains($res['body'], 'composer install --no-dev --optimize-autoloader')
    );

    // --- F3: a fully installed copy ----------------------------------------

    symlink($root . '/vendor', $sandbox . '/vendor');

    $res = http_request('GET', $base . '/');
    ok(
        'the root redirects to the dashboard',
        $res['status'] === 302 && ($res['headers']['location'] ?? '') === '/dashboard',
        $res['status'] . ' -> ' . (string) ($res['headers']['location'] ?? '')
    );
    ok(
        'the redirect carries no body',
        trim($res['body']) === '',
        'body length: ' . strlen($res['body'])
    );

    $res = http_request('GET', $base . '/nope');
    ok(
        'an unknown path is a 404',
        $res['status'] === 404,
        (string) $res['status']
    );
    ok(
        'the 404 page says the page does not exist',
        str_contains($res['body'], 'That page does not exist.')
    );

    $res = http_request('POST', $base . '/dashboard');
    ok(
        'the wrong method is a 405',
        $res['status'] === 405,
        (string) $res['status']
    );
    ok(
        'the 405 response carries an Allow header',
        isset($res['headers']['allow']) && str_contains($res['headers']['allow'], 'GET'),
        (string) ($res['headers']['allow'] ?? '')
    );
    ok(
        'the 405 page repeats the allowed methods',
        str_contains($res['body'], 'That page does not accept this request.')
    );

    $res = http_request('GET', $base . '/dashboard');
    ok(
        'a known path dispatches to its handler',
        $res['status'] === 200 && str_contains($res['body'], 'SANDBOX_SCREEN:dashboard.php'),
        $res['status'] . ' body: ' . substr($res['body'], 0, 120)
    );
    ok(
        'the security headers travel with the response',
        ($res['headers']['x-content-type-options'] ?? '') === 'nosniff'
            && ($res['headers']['x-frame-options'] ?? '') === 'SAMEORIGIN'
            && ($res['headers']['referrer-policy'] ?? '') === 'same-origin',
        json_encode($res['headers'])
    );

    // --- F4: a handler that is missing --------------------------------------
    // A broken deployment must be a clean 500 page. The message goes to the
    // log, never to the visitor.

    unlink($sandbox . '/views/reports.php');

    $res = http_request('GET', $base . '/reports');
    ok(
        'a missing handler is a 500',
        $res['status'] === 500,
        (string) $res['status']
    );
    ok(
        'the 500 page says nothing about the failure',
        str_contains($res['body'], 'Something went wrong on our side. Please try again.')
    );

    $leaks = [];

    foreach (['Route handler not found', 'Stack trace', 'Fatal error', '.php on line', $sandbox, '/var/www'] as $needle) {
        if (str_contains($res['body'], $needle)) {
            $leaks[] = $needle;
        }
    }

    ok('the 500 page leaks nothing', $leaks === [], implode(', ', $leaks));
}

// ---------------------------------------------------------------------------
// G. The live stack.
//
// The deny block and the rewrite, measured against the running application.
// The status codes depend on whether the application is installed, so both
// states are accepted — but the 404 must be the front controller's page and
// not the server's default.
// ---------------------------------------------------------------------------

echo "\n## G. The live stack\n";

$liveBase = resolve_base_url();
$live     = http_request('GET', $liveBase . '/AGENTS.md');

if ($live['status'] !== 200) {
    skip('the live tier', 'the application is not answering at ' . $liveBase);
} else {
    ok('the application answers at ' . $liveBase, $live['status'] === 200);

    foreach ([
        '/.env',
        '/.git/config',
        '/config.php',
        '/database/schema.sql',
        '/includes/functions.php',
        '/vendor/autoload.php',
        '/composer.json',
        '/composer.lock',
        '/docs/issues/README.md',
    ] as $path) {
        $res = http_request('GET', $liveBase . $path);
        ok('the deny block still holds ' . $path, $res['status'] === 403, (string) $res['status']);
    }

    $res = http_request('GET', $liveBase . '/assets/css/tokens.css');
    ok('the assets are still served directly', $res['status'] === 200, (string) $res['status']);

    $res = http_request('GET', $liveBase . '/docs/mockup/01-login.html');
    ok('the mockups are still served directly', $res['status'] === 200, (string) $res['status']);

    // An unknown path must reach the front controller. When the application
    // is not installed the gate answers with a 302; when it is, the
    // controller answers with its own 404 page. The server's default 404 is
    // neither of them.
    $res = http_request('GET', $liveBase . '/nope');
    $reachedController = $res['status'] === 302
        || ($res['status'] === 404 && str_contains($res['body'], 'That page does not exist.'));

    ok(
        'an unknown path reaches the front controller',
        $reachedController,
        'status=' . (string) $res['status']
    );

    $res = http_request('POST', $liveBase . '/dashboard');
    $reachedController = $res['status'] === 302
        || ($res['status'] === 405 && str_contains($res['body'], 'That page does not accept this request.'));

    ok(
        'the wrong method reaches the front controller',
        $reachedController,
        'status=' . (string) $res['status']
    );
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
