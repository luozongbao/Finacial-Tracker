<?php
/**
 * install_sandbox.php — the shared helpers of the install regression tests.
 *
 * Two tiers need the same machinery, and neither of them should own it:
 *
 *   - database/install_test.php        the SOURCE tier — it walks a COPY of
 *                                     the wizard through its three steps in a
 *                                     sandbox directory.
 *   - database/live/live_security_test.php
 *                                     the LIVE tier — it walks a COPY of the
 *                                     wizard through the RUNNING server.
 *
 * Both copies have to answer the same questions:
 *
 *   1. Where does the session store of that copy live? (issue-025)
 *   2. Does any file in it hold the password? (issue-025)
 *   3. Is the session id rotated, and is an unknown id adopted? (issue-025)
 *
 * So the helpers live here, once. The file is NOT a test: its name does not
 * end in _test.php, so run-tests.sh does not pick it up. It is covered by
 * the web-root deny block (the whole database/ directory is refused), and
 * database/webroot_hardening_test.php carries it in its denied list.
 *
 * It declares functions only. It never echoes, never exits and never
 * terminates the process: a helper that killed the run would take the rest
 * of the assertions with it.
 */

declare(strict_types=1);

// The cookie jar of the sandbox client. The wizard keeps its step state in
// the session, so the requests have to carry the session cookie from one to
// the next. The jar lives in a global so the helpers stay free of a class
// that would have to be threaded through every call site.
if (!isset($GLOBALS['install_sandbox_cookies']) || !is_array($GLOBALS['install_sandbox_cookies'])) {
    $GLOBALS['install_sandbox_cookies'] = [];
}

/**
 * Forget every cookie the client is holding.
 */
function install_sandbox_reset(): void
{
    $GLOBALS['install_sandbox_cookies'] = [];
}

/**
 * The cookie jar of the client.
 *
 * @return array<string, string>
 */
function install_sandbox_cookies(): array
{
    $jar = $GLOBALS['install_sandbox_cookies'] ?? [];

    return is_array($jar) ? $jar : [];
}

/**
 * Perform an HTTP request and return the status, the headers and the body.
 *
 * The session cookie is carried from one request to the next and the cookies
 * the server sets are kept. The redirect is NOT followed: the wizard answers
 * a step with a 302 and the test wants to see it.
 *
 * @param  array<string, string> $fields
 * @param  array<string, string> $extra  extra request headers
 * @param  array<string, string>|null $cookies  the cookies to send INSTEAD
 *         of the jar. The jar is not touched, so a request that carries a
 *         session id of its own does not disturb the session the client is
 *         holding — that is how the fixation probe works.
 * @return array{status: int|null, body: string, headers: array<string, string>}
 */
function install_sandbox_request(
    string $method,
    string $url,
    array $fields = [],
    int $timeout = 15,
    array $extra = [],
    ?array $cookies = null
): array {
    $headers = [
        'User-Agent: financial-tracker-install-test',
        'Accept: text/html',
    ];

    foreach ($extra as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }

    $body = '';

    if ($method === 'POST') {
        $body = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $jar = $cookies ?? install_sandbox_cookies();

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

    // Keep the cookies the server set — unless the caller sent cookies of
    // its own, in which case the jar belongs to another session.
    if ($cookies !== null) {
        return [
            'status'  => $status,
            'body'    => $response === false ? '' : $response,
            'headers' => $headers,
        ];
    }

    foreach ($headers as $name => $value) {
        if ($name !== 'set-cookie') {
            continue;
        }

        $pair = explode(';', $value)[0];
        $eq   = strpos($pair, '=');

        if ($eq !== false) {
            $GLOBALS['install_sandbox_cookies'][trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
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
function install_sandbox_csrf(string $body): string
{
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * The session id the client is holding, or an empty string.
 */
function install_sandbox_session_id(): string
{
    return install_sandbox_cookies()['PHPSESSID'] ?? '';
}

/**
 * A free TCP port on the loopback interface.
 */
function install_sandbox_free_port(): int
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
function install_sandbox_start(string $docroot): array
{
    $port = install_sandbox_free_port();

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
        $res = install_sandbox_request('GET', $base . '/__ping__', [], 2);

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
function install_sandbox_stop(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc'], 15);
        proc_close($server['proc']);
    }
}

/**
 * Remove a directory tree.
 */
function install_sandbox_remove(string $dir): void
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
            install_sandbox_remove($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

/**
 * Build a sandbox: a copy of the wizard in its own directory.
 *
 * The copy lives under the application's own storage directory, NOT in the
 * shared /tmp. That matters for issue-025: the session store of the copy
 * has to be somewhere the shared /tmp is not, and a copy under /tmp would
 * make the assertion meaningless.
 *
 * The copy is a copy and not a mock: the thing under test is the wizard.
 *
 * @return string the directory of the copy
 */
function install_sandbox_make(string $root): string
{
    // The application's own storage directory.
    //
    // It is created TRAVERSABLE (0755) on purpose. The web server runs as
    // another user and has to be able to create the session store inside
    // this directory. A directory this test creates 0700 and owns would
    // stop the application from writing its sessions there — and the
    // application would fall back to the shared /tmp, which is exactly
    // the defect issue-025 is about.
    $parent = $root . '/storage';

    if (!is_dir($parent)) {
        @mkdir($parent, 0755, true);
    }

    $dir = $parent . '/sandbox/install_' . bin2hex(random_bytes(5));

    install_sandbox_make_at($dir, $root);

    return $dir;
}

/**
 * Build a copy of the wizard at an EXPLICIT path.
 *
 * The SOURCE tier puts the copy under the application's own storage
 * directory, where the web server cannot reach it. The LIVE tier puts it
 * at a path the RUNNING server can reach, and removes it afterwards.
 *
 * The copy is readable by the web server, which runs as another user.
 *
 * @param string $dir  the directory the copy is made in
 * @param string $root the application root the copy is made from
 */
function install_sandbox_make_at(string $dir, string $root): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    mkdir($dir . '/includes', 0755, true);
    mkdir($dir . '/database', 0755, true);

    // The web server runs as the owner of the application tree, not as the
    // user that runs this test. The copy has to belong to that user: a
    // directory the web server cannot write to cannot hold the session
    // store, and the wizard would fall back to the shared /tmp — which is
    // the very defect issue-025 is about.
    $owner = @fileowner($root);
    $group = @filegroup($root);

    if ($owner !== false) {
        @chown($dir, $owner);
        @chgrp($dir, $group);
        @chown($dir . '/includes', $owner);
        @chgrp($dir . '/includes', $group);
        @chown($dir . '/database', $owner);
        @chgrp($dir . '/database', $group);
    }

    copy($root . '/install.php', $dir . '/install.php');
    copy($root . '/includes/functions.php', $dir . '/includes/functions.php');
    copy($root . '/includes/logging.php', $dir . '/includes/logging.php');
    copy($root . '/database/schema.sql', $dir . '/database/schema.sql');

    // The assets are read by asset_url() when the page renders.
    @symlink($root . '/assets', $dir . '/assets');

    // The copy keeps its own session store, and the store is refused by the
    // web server: the name of a session file IS the session id.
    file_put_contents(
        $dir . '/.htaccess',
        "<IfModule mod_rewrite.c>\n"
            . "    RewriteEngine On\n\n"
            . "    RewriteRule \"^/?storage/\" - [F,L]\n"
            . "</IfModule>\n"
    );

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
}

/**
 * The directory the session files of an application live in.
 *
 * The answer is the one the application itself would give: the constant in
 * config.php when there is one, the application's own storage directory
 * otherwise, and the ini value last.
 *
 * The constant is read in a CHILD process, because config.php declares
 * constants and the test process must not inherit them: a test that loaded
 * the application's configuration would answer from the application's
 * configuration instead of from the copy under test.
 */
function install_sandbox_session_dir(string $root): string
{
    $config = $root . '/config.php';

    if (is_file($config)) {
        $code = 'require ' . var_export($config, true)
            . '; echo defined("SESSION_SAVE_PATH") ? SESSION_SAVE_PATH : "";';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');

        if (is_string($out)) {
            $out = trim($out);

            if ($out !== '') {
                return $out;
            }
        }
    }

    $own = $root . '/storage/sessions';

    if (is_dir($own)) {
        return $own;
    }

    $ini = trim((string) ini_get('session.save_path'));

    if ($ini !== '') {
        return $ini;
    }

    return rtrim(sys_get_temp_dir(), '/') . '/ft-sessions-' . getmyuid();
}

/**
 * Every file under a directory, as paths relative to it.
 *
 * @return string[]
 */
function install_sandbox_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $found = [];

    $items = scandir($dir) ?: [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path) && !is_link($path)) {
            foreach (install_sandbox_files($path) as $nested) {
                $found[] = $item . '/' . $nested;
            }

            continue;
        }

        $found[] = $item;
    }

    sort($found);

    return $found;
}

/**
 * The files under a directory whose contents hold a needle.
 *
 * This is the issue-025 assertion: the password must not be in ANY file of
 * the session store. The needle is searched for in the RAW bytes, because
 * the session file is a serialised PHP array and the password is in it
 * verbatim when it is there at all.
 *
 * @return string[]
 */
function install_sandbox_scan(string $dir, string $needle): array
{
    if ($needle === '' || !is_dir($dir)) {
        return [];
    }

    $hits = [];

    foreach (install_sandbox_files($dir) as $name) {
        $path = $dir . '/' . $name;

        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $content = @file_get_contents($path);

        if (is_string($content) && str_contains($content, $needle)) {
            $hits[] = $name;
        }
    }

    return $hits;
}

/**
 * The names of the session files in a directory.
 *
 * PHP names a session file sess_<id>, so the name of the file IS the id.
 *
 * @return string[]
 */
function install_sandbox_session_files(string $dir): array
{
    $files = [];

    foreach (install_sandbox_files($dir) as $name) {
        if (str_starts_with($name, 'sess_')) {
            $files[] = $name;
        }
    }

    return $files;
}

/**
 * The session files in a directory whose contents hold a needle.
 *
 * Only the files PHP named sess_<id> are looked at: the shared temporary
 * directory holds more than the sessions, and a hit in an unrelated file
 * would say nothing about the session store.
 *
 * @return string[]
 */
function install_sandbox_scan_sessions(string $dir, string $needle): array
{
    if ($needle === '' || !is_dir($dir)) {
        return [];
    }

    $hits = [];

    foreach (install_sandbox_session_files($dir) as $name) {
        $path = $dir . '/' . $name;

        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $content = @file_get_contents($path);

        if (is_string($content) && str_contains($content, $needle)) {
            $hits[] = $name;
        }
    }

    return $hits;
}
