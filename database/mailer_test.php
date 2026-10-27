<?php
/**
 * mailer_test.php — guards the Composer dependency policy and the mailer contract.
 *
 * What this file protects (docs/issues/issue-010.md):
 *
 *   1. The dependency is installed, and the installed version is the version
 *      the lock file pins.
 *   2. The manifest declares the patched PHP floor and pins the resolution
 *      target to it, so a developer's older PHP cannot produce a lock file the
 *      production runtime cannot satisfy.
 *   3. The policy: vendor/ and composer.phar are ignored, the manifest and the
 *      lock file are tracked, the runtime image ships no Composer, and nothing
 *      in the entrypoint or in the application ever runs Composer.
 *   4. The mailer contract: it reads config.php and nothing else, it does not
 *      connect when SMTP is not configured, it turns a failed send into a
 *      false return instead of an exception, and it picks the encryption mode
 *      that matches the port.
 *
 * Structural plus child-process tests. No database is needed.
 *
 * Run:  docker compose exec -T app php database/mailer_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by
 * the output — and never treat a missing interpreter as a pass.
 */

declare(strict_types=1);

$failures = [];

function ok($label, $ok, $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  [PASS] $label\n";
        return;
    }
    $failures[] = $label . ($detail !== '' ? " — $detail" : '');
    echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/**
 * Remove comments from PHP source.
 *
 * A negative assertion must not be satisfied by a mention in a comment — the
 * repo's test rules require comments to be stripped first.
 */
function strip_php_comments(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#//[^\n]*#', '', $src);

    return (string) $src;
}

/**
 * Remove comment lines from a shell script.
 */
function strip_shell_comments(string $src): string
{
    $kept = [];
    foreach (preg_split('/\R/', $src) as $line) {
        if (preg_match('/^\s*#/', $line)) {
            continue;
        }
        $kept[] = $line;
    }

    return implode("\n", $kept);
}

/**
 * Run a PHP snippet in a child process.
 *
 * The child gets its own constant table, which is what lets this test exercise
 * the "SMTP is not configured" state without touching config.php.
 *
 * Returns the child's stdout, its stderr and its exit code. The streams are
 * kept apart on purpose: the child prints JSON on stdout and error_log() on
 * stderr, and merging them would make the JSON unparseable exactly in the
 * failure cases this test is about.
 */
function run_child(string $code, array $args = []): array
{
    $file    = sys_get_temp_dir() . '/mailer_child_' . bin2hex(random_bytes(6)) . '.php';
    $errFile = sys_get_temp_dir() . '/mailer_child_err_' . bin2hex(random_bytes(6)) . '.txt';
    file_put_contents($file, $code);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }

    $out = [];
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
 * Build the child prelude that fakes config.php's constants.
 *
 * The values are raw PHP literals, so a test can pass an integer port — the
 * documented type — or a quoted one.
 */
function child_config(array $overrides = []): string
{
    $defaults = [
        'SMTP_HOST'      => "'smtp.example.com'",
        'SMTP_PORT'      => '587',
        'SMTP_USER'      => "'user@example.com'",
        'SMTP_PASS'      => "'secret'",
        'MAIL_FROM'      => "'no-reply@example.com'",
        'MAIL_FROM_NAME' => "'Financial Tracker'",
    ];

    $values = array_merge($defaults, $overrides);
    $out = "<?php\n";
    foreach ($values as $name => $literal) {
        $out .= sprintf("define(%s, %s);\n", var_export($name, true), $literal);
    }

    return $out;
}

/**
 * Start a TCP server that accepts one connection and closes it immediately.
 *
 * That is what a refused or dropped SMTP session looks like to the client,
 * and it is the cheapest way to make PHPMailer fail without going near the
 * network beyond localhost.
 */
function start_refusing_smtp_server(): array
{
    $server = <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
echo $port, "\n";
flush();
$conn = @stream_socket_accept($server, 20);
if ($conn !== false) {
    fclose($conn);
}
fclose($server);
PHP;

    $file = sys_get_temp_dir() . '/smtp_refuse_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, $server);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, $file], $descriptors, $pipes);

    if (!is_resource($proc)) {
        @unlink($file);

        return ['proc' => null, 'port' => 0];
    }

    fclose($pipes[0]);
    $port = (int) trim((string) fgets($pipes[1]));

    return ['proc' => $proc, 'pipes' => $pipes, 'port' => $port, 'file' => $file];
}

/**
 * Stop the refusing server and remove its script.
 */
function stop_refusing_smtp_server(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        foreach (($server['pipes'] ?? []) as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }

    if (isset($server['file']) && is_file($server['file'])) {
        @unlink($server['file']);
    }
}

$root        = realpath(__DIR__ . '/..');
$manifestPath = $root . '/composer.json';
$lockPath     = $root . '/composer.lock';
$autoload     = $root . '/vendor/autoload.php';
$mailerFile   = $root . '/includes/mailer.php';

echo "## A. The dependency is installed and matches the lock file\n";

ok('composer.json exists', is_file($manifestPath), $manifestPath);
ok('composer.lock exists', is_file($lockPath), $lockPath);
ok('vendor/autoload.php exists', is_file($autoload), $autoload);

$manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
$lock     = is_file($lockPath) ? json_decode((string) file_get_contents($lockPath), true) : null;

ok('composer.json is valid JSON', is_array($manifest));
ok('composer.lock is valid JSON', is_array($lock));

if (is_array($manifest)) {
    $phpConstraint = (string) ($manifest['require']['php'] ?? '');
    ok(
        'composer.json requires PHP >= 8.5.12',
        (bool) preg_match('/^>=\s*(\d+\.\d+\.\d+)/', $phpConstraint, $m)
            && version_compare($m[1], '8.5.12', '>='),
        'the requirement is ' . var_export($phpConstraint, true)
    );

    $platform = (string) ($manifest['config']['platform']['php'] ?? '');
    ok(
        'composer.json pins the resolution target to PHP 8.5.12',
        $platform !== '' && version_compare($platform, '8.5.12', '>='),
        'config.platform.php is ' . var_export($platform, true)
    );

    $mailerConstraint = (string) ($manifest['require']['phpmailer/phpmailer'] ?? '');
    ok(
        'composer.json requires phpmailer/phpmailer ^7.1',
        (bool) preg_match('/^\^?7\.1(\.\d+)?$/', $mailerConstraint),
        'the requirement is ' . var_export($mailerConstraint, true)
    );
}

$lockedVersion = null;
if (is_array($lock)) {
    foreach ($lock['packages'] ?? [] as $package) {
        if (($package['name'] ?? '') === 'phpmailer/phpmailer') {
            $lockedVersion = (string) ($package['version'] ?? '');
        }
    }

    ok(
        'composer.lock pins phpmailer/phpmailer to an exact version',
        $lockedVersion !== null && $lockedVersion !== '' && strpos($lockedVersion, '*') === false,
        'the locked version is ' . var_export($lockedVersion, true)
    );

    ok(
        'composer.lock records the platform override php 8.5.12',
        (string) ($lock['platform-overrides']['php'] ?? '') === '8.5.12',
        'platform-overrides is ' . var_export($lock['platform-overrides'] ?? null, true)
    );

    ok('composer.lock carries a content hash', (string) ($lock['content-hash'] ?? '') !== '');
}

if (is_file($autoload)) {
    require_once $autoload;

    $hasMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    ok('PHPMailer\\PHPMailer\\PHPMailer is loadable', $hasMailer);

    if ($hasMailer) {
        $installed = PHPMailer\PHPMailer\PHPMailer::VERSION;
        ok(
            'the installed PHPMailer is the version the lock file pins',
            $lockedVersion !== null && ltrim((string) $lockedVersion, 'v') === $installed,
            'installed ' . $installed . ', locked ' . (string) $lockedVersion
        );
    }
}

echo "\n## B. The dependency policy holds\n";

$ignore = is_file($root . '/.gitignore') ? (string) file_get_contents($root . '/.gitignore') : '';

ok('vendor/ is git-ignored', (bool) preg_match('#^/?vendor/#m', $ignore));
ok('composer.phar is git-ignored', (bool) preg_match('#^composer\.phar$#m', $ignore));
ok('composer.json is not git-ignored', !preg_match('#^/?composer\.json$#m', $ignore));
ok('composer.lock is not git-ignored', !preg_match('#^/?composer\.lock$#m', $ignore));

$entrypoint = $root . '/docker/entrypoint.sh';
if (is_file($entrypoint)) {
    $entryCode = strip_shell_comments((string) file_get_contents($entrypoint));
    ok(
        'docker/entrypoint.sh never invokes Composer',
        stripos($entryCode, 'composer') === false,
        'the entrypoint mentions composer outside a comment'
    );
} else {
    ok('docker/entrypoint.sh exists', false, $entrypoint);
}

// The runtime must not mutate itself. A shell-execution function anywhere in
// includes/ is the shape that would let it do so.
//
// The lookbehind excludes a METHOD call: PDO's own ->exec() runs a
// statement, it does not start a program, and includes/Database.php calls
// it to set the session time zone. Only a bare function call shells out.
$includeFiles = glob($root . '/includes/*.php') ?: [];
$shellers = [];
foreach ($includeFiles as $file) {
    $code = strip_php_comments((string) file_get_contents($file));
    if (preg_match('/(?<![>:])\b(exec|shell_exec|proc_open|passthru|popen|system)\s*\(/', $code)) {
        $shellers[] = basename($file);
    }
}
ok(
    'no file in includes/ shells out to another program',
    count($shellers) === 0,
    'shells out in: ' . implode(', ', $shellers)
);

// The image must not ship Composer. This is only assertable inside the image:
// a developer running this test on a host that has Composer installed would
// otherwise get a failure that says nothing about the project.
$imageComposer = [];
foreach ([
    '/usr/local/bin/composer',
    '/usr/bin/composer',
    '/usr/local/bin/composer.phar',
    '/usr/bin/composer.phar',
    '/usr/local/lsws/lsphp85/bin/composer',
] as $path) {
    if (is_file($path)) {
        $imageComposer[] = $path;
    }
}

if (is_dir('/usr/local/lsws')) {
    ok(
        'the runtime image ships no Composer binary',
        count($imageComposer) === 0,
        'found: ' . implode(', ', $imageComposer)
    );
} else {
    echo "  [SKIP] not running inside the LiteSpeed image — its contents cannot be asserted from here.\n";
}

echo "\n## C. The mailer reads config.php and nothing else\n";

ok('includes/mailer.php exists', is_file($mailerFile), $mailerFile);
ok('includes/bootstrap.php exists', is_file($root . '/includes/bootstrap.php'));

if (is_file($mailerFile)) {
    $mailerSrc = strip_php_comments((string) file_get_contents($mailerFile));

    ok('the mailer reads no environment variable', stripos($mailerSrc, 'getenv(') === false);
    ok('the mailer reads no $_ENV superglobal', strpos($mailerSrc, '$_ENV') === false);
    ok('the mailer reads no $_SERVER superglobal', strpos($mailerSrc, '$_SERVER') === false);
    ok('the mailer sets the From: address with setFrom()', strpos($mailerSrc, 'setFrom(') !== false);
    ok('the mailer sets a UTF-8 charset', strpos($mailerSrc, "'UTF-8'") !== false);
}

echo "\n## D. An unconfigured mailer does not connect\n";

$child = child_config(['SMTP_HOST' => "''"]) . <<<'PHP'
require $argv[1];
require $argv[2];
$started = microtime(true);
$configured = mailIsConfigured();
$sent = sendMail('nobody@example.com', 'Subject', 'Body');
echo json_encode([
    'configured' => $configured,
    'sent' => $sent,
    'elapsed' => microtime(true) - $started,
]);
PHP;

$res = run_child($child, [$autoload, $mailerFile]);
$data = json_decode(trim($res['stdout']), true);

ok('the not-configured child process ran', is_array($data), $res['stdout']);

if (is_array($data)) {
    ok('an empty SMTP_HOST means "not configured"', $data['configured'] === false);
    ok('an unconfigured mailer does not send', $data['sent'] === false);
    ok(
        'an unconfigured mailer does not attempt a connection',
        (float) $data['elapsed'] < 1.0,
        'the call took ' . $data['elapsed'] . 's'
    );
}

echo "\n## E. The transport settings come from config.php\n";

$encryptionSmtps    = class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : 'ssl';
$encryptionStartTls = class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : 'tls';

$transportChild = <<<'PHP'
require $argv[1];
require $argv[2];
$m = createMailer();
echo json_encode([
    'secure' => $m->SMTPSecure,
    'port' => $m->Port,
    'host' => $m->Host,
    'charset' => $m->CharSet,
    'from' => $m->From,
    'from_name' => $m->FromName,
    'auth' => $m->SMTPAuth,
    'username' => $m->Username,
]);
PHP;

foreach ([
    ['port' => 465, 'secure' => $encryptionSmtps, 'label' => 'port 465 uses implicit TLS'],
    ['port' => 587, 'secure' => $encryptionStartTls, 'label' => 'port 587 uses STARTTLS'],
] as $case) {
    $res = run_child(
        child_config(['SMTP_PORT' => (string) $case['port']]) . $transportChild,
        [$autoload, $mailerFile]
    );
    $data = json_decode(trim($res['stdout']), true);

    ok('the transport child process for port ' . $case['port'] . ' ran', is_array($data), $res['stdout']);

    if (!is_array($data)) {
        continue;
    }

    ok($case['label'], $data['secure'] === $case['secure'], 'SMTPSecure is ' . var_export($data['secure'], true));
    ok('the configured port is used', (int) $data['port'] === $case['port']);
    ok('the configured host is used', $data['host'] === 'smtp.example.com');
    ok('the charset is UTF-8', $data['charset'] === 'UTF-8');
    ok('SMTP authentication is enabled', $data['auth'] === true);
    ok('the configured SMTP user is used', $data['username'] === 'user@example.com');
    ok('the From: address is MAIL_FROM, not SMTP_USER', $data['from'] === 'no-reply@example.com');
    ok('the From: display name is MAIL_FROM_NAME', $data['from_name'] === 'Financial Tracker');
}

echo "\n## F. A failed send is a false, never an exception\n";

// An invalid From: address makes PHPMailer throw while building the message.
// The mailer must convert that into a false return — the caller decides what
// the user sees, and a public form must not become a 500.
$res = run_child(
    child_config(['MAIL_FROM' => "'not-an-address'"]) . <<<'PHP'
require $argv[1];
require $argv[2];
$sent = sendMail('nobody@example.com', 'Subject', 'Body');
echo json_encode(['sent' => $sent]);
PHP,
    [$autoload, $mailerFile]
);
$data = json_decode(trim($res['stdout']), true);

ok('a rejected message does not become an uncaught error', $res['code'] === 0, 'child exit ' . $res['code'] . ': ' . $res['stdout']);
ok('a rejected message returns false', is_array($data) && $data['sent'] === false, $res['stdout']);
ok(
    'a rejected message is logged, not swallowed',
    stripos($res['stderr'], 'mailer') !== false,
    'stderr=' . trim($res['stderr'])
);

// A refused connection is the real-world failure. A local server that accepts
// and closes immediately reproduces it without touching the network.
$server = start_refusing_smtp_server();

if ($server['port'] > 0) {
    $res = run_child(
        child_config([
            'SMTP_HOST' => "'127.0.0.1'",
            'SMTP_PORT' => (string) $server['port'],
        ]) . <<<'PHP'
require $argv[1];
require $argv[2];
$sent = sendMail('nobody@example.com', 'Subject', 'Body');
echo json_encode(['sent' => $sent]);
PHP,
        [$autoload, $mailerFile]
    );
    $data = json_decode(trim($res['stdout']), true);

    ok('a refused SMTP connection does not become an uncaught error', $res['code'] === 0, 'child exit ' . $res['code'] . ': ' . $res['stdout']);
    ok('a refused SMTP connection returns false', is_array($data) && $data['sent'] === false, $res['stdout']);
    ok(
        'a refused SMTP connection is logged, not swallowed',
        stripos($res['stderr'], 'mailer') !== false,
        'stderr=' . trim($res['stderr'])
    );
} else {
    echo "  [SKIP] could not bind a local socket to simulate a refused SMTP server.\n";
}

stop_refusing_smtp_server($server);

echo "\n";
echo "Failed: " . count($failures) . "\n";
if (count($failures) > 0) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "ALL ASSERTIONS PASSED\n";
exit(0);
