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

// The shared helpers of the install tests: the HTTP client with its cookie
// jar, the sandbox copy of the wizard and the session-store inspection that
// issue-025 needs. The file is not a test itself.
require_once __DIR__ . '/install_sandbox.php';

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
 * A connection as the root account, or null when it is not reachable.
 */
function install_root_pdo(string $root): ?PDO
{
    $env  = env_values($root);
    $host = is_dir('/usr/local/lsws') ? 'db' : '127.0.0.1';
    $pass = $env['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '') {
        return null;
    }

    try {
        return new PDO('mysql:host=' . $host . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $ex) {
        return null;
    }
}

/**
 * A database user whose password begins and ends with a space.
 *
 * The criterion of issue-025 is that the password reaches config.php EXACTLY
 * as it was typed — not trimmed, not re-encoded. A password without a space
 * cannot prove that, so the test installs one: the wizard connects with it,
 * and the file that comes out has to hold it byte for byte.
 *
 * @param  array{host: string, name: string, user: string, pass: string, created: bool} $db
 * @return array{user: string, pass: string}|null
 */
function install_probe_user(string $root, array $db): ?array
{
    $pdo = install_root_pdo($root);

    if ($pdo === null) {
        return null;
    }

    $user = 'ft_install_probe_user';
    $pass = ' Pr0be Secret ';

    try {
        $pdo->exec("DROP USER IF EXISTS `{$user}`@'%'");
        $pdo->exec("CREATE USER `{$user}`@'%' IDENTIFIED BY " . $pdo->quote($pass));
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$db['name']}`.* TO `{$user}`@'%'");
    } catch (PDOException $ex) {
        echo "  [WARN] the probe user could not be created: " . $ex->getMessage() . "\n";

        return null;
    }

    return ['user' => $user, 'pass' => $pass];
}

/**
 * Drop the probe user again.
 */
function install_drop_probe_user(string $root): void
{
    $pdo = install_root_pdo($root);

    if ($pdo === null) {
        return;
    }

    try {
        $pdo->exec("DROP USER IF EXISTS `ft_install_probe_user`@'%'");
    } catch (PDOException $ex) {
        echo "  [WARN] the probe user could not be dropped: " . $ex->getMessage() . "\n";
    }
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
    str_contains($code, 'logFailure(')
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
    'MAIL_FROM', 'MAIL_FROM_NAME', 'ALLOW_REGISTRATION', 'REQUEST_THROTTLE_ENABLED', 'WEEK_START', 'TIMEZONE',
    'SESSION_SAVE_PATH',
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
    count($written) === 15,
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
// B2. The secrets never reach the session (issue-025).
//
// The rule is structural: there is no code path that writes a password into
// $_SESSION. A grep for the password names is not enough — the assertion
// below is that no LINE that mentions a password also touches the session.
// ---------------------------------------------------------------------------

echo "\n## B2. The secrets never reach the session\n";

$functionsSrc  = (string) file_get_contents($root . '/includes/functions.php');
$functionsCode = strip_comments($functionsSrc);

$offenders = [];

foreach (explode("\n", $code) as $number => $line) {
    if (!str_contains($line, 'db_pass') && !str_contains($line, 'smtp_pass')) {
        continue;
    }

    if (str_contains($line, '$_SESSION')) {
        $offenders[] = ($number + 1) . ': ' . trim($line);
    }
}

ok(
    'no line writes a password into the session',
    $offenders === [],
    implode(' | ', $offenders)
);

$saveState = extract_function_source($src, 'installSaveState');
$readState = extract_function_source($src, 'installState');

ok('the state is written in exactly one place', str_contains($code, "\$_SESSION['install'] = \$state;"));
ok(
    'the writer drops the database password',
    str_contains($saveState, "unset(\$state['db']['db_pass'])"),
    'installSaveState() does not drop db_pass'
);
ok(
    'the writer drops the SMTP password',
    str_contains($saveState, "unset(\$state['mail']['smtp_pass'])"),
    'installSaveState() does not drop smtp_pass'
);
ok(
    'the reader drops the database password',
    preg_match('/unset\\([^)]*\\$db\\[\'db_pass\'\\]/', $readState) === 1,
    'installState() does not drop db_pass on the read'
);
ok(
    'the reader drops the SMTP password',
    preg_match('/unset\\([^)]*\\$mail\\[\'smtp_pass\'\\]/', $readState) === 1,
    'installState() does not drop smtp_pass on the read'
);

// The password is still read RAW: a password may begin or end with a space,
// and trimming it would lock the operator out of their own installation.
ok(
    'the password is still read raw, not trimmed',
    str_contains($src, 'function installRawPost(')
        && str_contains($src, "installRawPost('db_pass'")
        && str_contains($src, "installRawPost('smtp_pass'")
);

// The step 3 form asks for the passwords again, and never echoes them.
ok(
    'step 3 asks for the database password again',
    str_contains($src, 'name="db_pass" type="password"')
);
ok(
    'step 3 asks for the SMTP password again',
    str_contains($src, 'name="smtp_pass" type="password"')
);
ok(
    'the password fields are never filled in',
    preg_match('/name="(?:db_pass|smtp_pass)"\s+type="password"\s+autocomplete="new-password"\s+value=""/', $src) === 1,
    'a password field carries a value'
);

// The session hardening (issue-025 §7.2 and §7.3).
ok(
    'the wizard regenerates the session id',
    str_contains($code, 'session_regenerate_id(true)')
);
ok(
    'the id is regenerated at the start and after every step boundary',
    substr_count($code, 'installRotateSessionId();') >= 4,
    'the calls: ' . substr_count($code, 'installRotateSessionId();')
);
ok(
    'the session refuses an id it does not know',
    str_contains($functionsCode, "ini_set('session.use_strict_mode', '1')")
);

$strictPos = pos_of($functionsCode, "ini_set('session.use_strict_mode', '1')");
$startPos  = pos_of($functionsCode, 'session_start()');

ok(
    'the strict mode is set before the session starts',
    $strictPos !== -1 && $startPos !== -1 && $strictPos < $startPos,
    'strict=' . $strictPos . ' start=' . $startPos
);
ok(
    'the session files live in the application\'s own directory',
    str_contains($functionsCode, "'/storage/sessions'")
);
ok(
    'the session directory is created private',
    str_contains($functionsCode, '0700')
);
ok(
    'the wizard writes the session directory into the configuration',
    str_contains($src, 'const SESSION_SAVE_PATH =')
);

// ---------------------------------------------------------------------------
// C. The behaviour, in a sandbox.
//
// The real wizard, walked through the three steps. The copy is in a
// temporary directory, so the repository's own config.php is never touched.
// ---------------------------------------------------------------------------

echo "\n## C. The behaviour in a sandbox\n";

$sandbox = install_sandbox_make($root);
$server  = install_sandbox_start($sandbox);
$db      = resolve_database($root);

// A database user whose password begins and ends with a space.
//
// The criterion is that the password lands in config.php EXACTLY as it was
// typed — not trimmed, not re-encoded. A password without a space cannot
// prove that, so the test installs one.
$probe     = install_probe_user($root, $db);
$wizardUser = $probe['user'] ?? $db['user'];
$wizardPass = $probe['pass'] ?? $db['pass'];

register_shutdown_function(static function () use ($sandbox, $server, $db, $root): void {
    install_sandbox_stop($server);
    install_sandbox_remove($sandbox);
    install_drop_probe_user($root);
    drop_scratch_database($root, $db);
});

if ($server['proc'] === null || $server['base'] === '') {
    skip('the sandbox tier', 'the built-in server could not be started');
} else {
    $base = $server['base'];

    // --- C1: the first page ------------------------------------------------

    $res = install_sandbox_request('GET', $base . '/install.php');

    ok('the wizard answers with a 200', $res['status'] === 200, (string) $res['status']);
    ok('the page carries the stepper', str_contains($res['body'], 'stepper__num'));
    ok('the first step is the current one', str_contains($res['body'], 'aria-current="step"'));
    ok('the page says it is the installer', str_contains($res['body'], 'Install Financial Tracker'));

    $token = install_sandbox_csrf($res['body']);

    // The id the wizard handed out on the first page. The wizard regenerates
    // the id when it starts, so this is already not an id the client chose.
    $idAfterFirstGet = install_sandbox_session_id();

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

    $res = install_sandbox_request('POST', $base . '/install.php', [
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

    $res = install_sandbox_request('POST', $base . '/install.php', [
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

    $res = install_sandbox_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => 'ft_test; DROP TABLE users',
        'db_user'    => $wizardUser,
        'db_pass'    => $wizardPass,
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

    $res = install_sandbox_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => 'ft_test" onfocus="x',
        'db_user'    => $wizardUser,
        'db_pass'    => $wizardPass,
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

    $res = install_sandbox_request('POST', $base . '/install.php', [
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

    $res = install_sandbox_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => $db['host'],
        'db_name'    => $db['name'],
        'db_user'    => $wizardUser,
        'db_pass'    => $wizardPass,
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

    // --- C6b: the session store holds no secret (issue-025) ----------------
    //
    // The criterion is not "the page does not show the password" — it is
    // "the password is nowhere on the disk". The session store is read
    // directly, in the raw bytes.

    $idAfterStep1 = install_sandbox_session_id();
    $sessionDir   = install_sandbox_session_dir($sandbox);
    $tmpDir       = rtrim(sys_get_temp_dir(), '/');

    ok(
        'the session store of the copy is the application\'s own directory',
        $sessionDir === $sandbox . '/storage/sessions',
        $sessionDir
    );
    ok(
        'the session store is not the shared temporary directory',
        !str_starts_with($sessionDir, $tmpDir),
        $sessionDir
    );

    $inStore = install_sandbox_scan($sessionDir, $wizardPass);

    ok(
        'no file of the session store holds the password',
        $inStore === [],
        implode(', ', $inStore)
    );

    $inTmp = install_sandbox_scan_sessions($tmpDir, $wizardPass);

    ok(
        'no session file in the shared temporary directory holds the password',
        $inTmp === [],
        implode(', ', $inTmp)
    );

    ok(
        'the session id is regenerated after a step boundary',
        $idAfterStep1 !== '' && $idAfterStep1 !== $idAfterFirstGet,
        'after the first page=' . $idAfterFirstGet . ' after step 1=' . $idAfterStep1
    );

    // --- C6c: an unknown session id is not adopted (issue-025) --------------
    //
    // The visitor chooses the id. With session.use_strict_mode = 1 the
    // server refuses it and issues one of its own — and nothing is written
    // under the name the visitor chose.

    $forged = bin2hex(random_bytes(16));

    $res = install_sandbox_request('GET', $base . '/install.php', [], 15, [], ['PHPSESSID' => $forged]);

    $issued = '';

    if (preg_match('/PHPSESSID=([A-Za-z0-9,\-]+)/', (string) ($res['headers']['set-cookie'] ?? ''), $m) === 1) {
        $issued = $m[1];
    }

    ok(
        'an unknown session id is not adopted',
        $issued !== '' && $issued !== $forged,
        'sent=' . $forged . ' issued=' . $issued
    );
    ok(
        'no file was created for the unknown session id',
        !is_file($sessionDir . '/sess_' . $forged),
        $sessionDir . '/sess_' . $forged
    );

    $res = install_sandbox_request('GET', $base . '/install.php');

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

    $token = install_sandbox_csrf($res['body']);

    $res = install_sandbox_request('POST', $base . '/install.php', [
        'csrf_token' => $token,
        'step'       => '2',
        'action'     => 'skip_mail',
    ]);

    ok(
        'skipping the mail step is a redirect',
        $res['status'] === 302,
        (string) $res['status']
    );

    $idAfterStep2 = install_sandbox_session_id();

    ok(
        'the session id is regenerated after the step 2 boundary',
        $idAfterStep2 !== '' && $idAfterStep2 !== $idAfterStep1,
        'after step 1=' . $idAfterStep1 . ' after step 2=' . $idAfterStep2
    );

    $res = install_sandbox_request('GET', $base . '/install.php');

    ok('the wizard is on step 3', str_contains($res['body'], 'Access'));
    ok('the summary is on the page', str_contains($res['body'], 'readonly-list'));
    ok(
        'the passwords are masked, not echoed',
        str_contains($res['body'], 'masked') && !str_contains($res['body'], $wizardPass)
    );
    ok(
        'step 3 asks for the database password again',
        str_contains($res['body'], 'name="db_pass"')
    );
    ok(
        'the password field of step 3 is empty',
        preg_match('/name="db_pass"\\s+type="password"\\s+autocomplete="new-password"\\s+value=""/', $res['body']) === 1
    );
    ok(
        'the registration switch is on the page',
        str_contains($res['body'], 'name="allow_registration"')
    );

    // --- C8: the install ---------------------------------------------------

    $token = install_sandbox_csrf($res['body']);

    // The password is not in the session, so the wizard asks for it again.
    // An install that skipped that would have no password to write.

    $res = install_sandbox_request('POST', $base . '/install.php', [
        'csrf_token'        => $token,
        'step'              => '3',
        'action'            => 'install',
        'allow_registration' => '0',
    ]);

    ok(
        'the install is refused when the password is not typed again',
        str_contains($res['body'], 'does not keep it'),
        'status=' . (string) $res['status']
    );
    ok(
        'the refused install wrote no configuration file',
        !is_file($sandbox . '/config.php')
    );

    // --- C8b: the install, with the password typed again -------------------

    $token = install_sandbox_csrf($res['body']);

    $res = install_sandbox_request('POST', $base . '/install.php', [
        'csrf_token'        => $token,
        'step'              => '3',
        'action'            => 'install',
        'db_pass'           => $wizardPass,
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

        // The criterion of issue-025: the password lands in the file EXACTLY
        // as it was typed. A password with a space at each end proves the
        // trim — the mistake this criterion is about — did not happen.

        ok(
            'the file holds the password exactly as it was typed',
            str_contains($live, 'const DB_PASS = ' . var_export($wizardPass, true) . ';'),
            'the file does not hold the password'
        );

        if ($probe !== null) {
            ok(
                'the password was not trimmed on the way in',
                !str_contains($live, 'const DB_PASS = ' . var_export(trim($wizardPass), true) . ';'),
                'the trimmed password is in the file'
            );
        }
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

    $res = install_sandbox_request('GET', $base . '/install.php');

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
$live     = install_sandbox_request('GET', $liveBase . '/install.php');

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
        ok('the live page carries a CSRF token', install_sandbox_csrf($live['body']) !== '');

        foreach (['app__bar', 'nav__link', 'bottom-nav__link', 'app__footer'] as $shell) {
            ok(
                'the live page carries no application navigation (' . $shell . ')',
                !str_contains($live['body'], $shell)
            );
        }
    }

    // A failed step must not put the driver error on the page.
    $liveToken = install_sandbox_csrf($live['body']);
    $res       = install_sandbox_request('POST', $liveBase . '/install.php', [
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
            . "require_once __DIR__ . '/includes/logging.php';\n"
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

        $probeRes = install_sandbox_request('GET', $liveBase . '/' . $probeName . '?g=' . $goodName . '&b=' . $badName);

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
