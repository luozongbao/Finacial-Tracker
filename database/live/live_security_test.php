<?php
/**
 * live_security_test.php -- the LIVE tier: the security properties of the
 * running application, over HTTP.
 *
 * The companion of live_app_test.php. Where that one proves the SCREENS
 * work, this one proves the screens are SAFE: the theme cookie is
 * whitelisted, the active nav link uses aria-current and not a class, a GET
 * form carries no CSRF token while every POST form carries one, user data
 * is escaped on the way out, the session cookie is HttpOnly and SameSite,
 * the session id never reaches the page, and the sign-in throttle counts,
 * locks, states the wait that is really left and decays.
 *
 * It needs the same thing live_app_test.php needs: the app container
 * running, config.php in place, and the application reachable at
 * $LIVE_BASE_URL.
 *
 * It CLEANS UP AFTER ITSELF: the account it creates carries a random marker
 * and is deleted in a shutdown function.
 *
 * Run:  docker compose exec -T app php /var/www/html/database/live/live_security_test.php
 * Exit: 0 on success, 1 on a failure, 2 when the app is not reachable.
 */

declare(strict_types=1);

define('BASE', rtrim((string) (getenv('LIVE_BASE_URL') ?: 'http://127.0.0.1'), '/'));

if (!is_file(__DIR__ . '/../../config.php')) {
    fwrite(STDERR, "config.php is missing -- run the install wizard first.\n");
    exit(2);
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

// The limits of the request throttle are PRODUCT constants. The test
// reads them from here instead of repeating the numbers, so a change to
// the product rule cannot leave the test asserting a limit that no
// longer exists. Loading the file has no side effect: it declares the
// constants and the functions, and nothing else.
require_once __DIR__ . '/../../includes/auth.php';

// The shared helpers of the install tests (issue-025). The file declares
// functions only: it never echoes, never exits and never terminates the
// process.
require_once __DIR__ . '/../install_sandbox.php';


/**
 * The values of the development environment file.
 *
 * @return array<string, string>
 */
function live_env_values(): array
{
    $file   = dirname(__DIR__, 2) . '/.env';
    $values = [];

    if (!is_file($file)) {
        return $values;
    }

    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $values[trim($name)] = trim($value);
    }

    return $values;
}

/**
 * A connection as the root account of the database, or null.
 *
 * The probe of section L needs a database user with a password of its own:
 * the needle it searches the disk for has to belong to this run and to
 * nothing else. The application account cannot create a user.
 */
function live_root_pdo(): ?PDO
{
    $pass = live_env_values()['MYSQL_ROOT_PASSWORD'] ?? '';

    if ($pass === '' || !defined('DB_HOST')) {
        return null;
    }

    try {
        return new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', 'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $ex) {
        return null;
    }
}

/**
 * Remove a directory tree.
 */
function live_remove_tree(string $dir): void
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
            live_remove_tree($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

/**
 * The random marker of this run. It is the only handle on the accounts the
 * test creates, and it is random hex, so the cleanup pattern cannot match
 * a real account.
 */
$GLOBALS['live_test_marker'] = '';

/**
 * The addresses this run submitted. The request throttle counts them and
 * the outbox holds their messages, and both have to be cleaned up.
 */
$GLOBALS['live_test_addresses'] = [];

/**
 * Delete every account this run created.
 *
 * The foreign keys cascade: the categories, the transactions, the password
 * resets and the email verifications go with the user. The pattern matches
 * the ORIGINAL username and any rename of it, so a run that dies between
 * the rename and the end still leaves nothing behind.
 *
 * The underscores are escaped because an unescaped _ in LIKE is a
 * single-character wildcard.
 */
function live_cleanup(): void
{
    $marker = (string) ($GLOBALS['live_test_marker'] ?? '');

    if ($marker === '') {
        return;
    }

    try {
        $pdo = Database::getInstance()->getConnection();

        // The throttle rows. They carry no foreign key to the account, so
        // the cascade does not take them: the identifier row of the test
        // account and the source bucket the test emptied both go.
        $username = (string) ($GLOBALS['live_test_username'] ?? '');

        if ($username !== '') {
            $stmt = $pdo->prepare(
                'DELETE FROM `login_attempts` WHERE `scope` = ? AND `key_hash` = ?'
            );
            $stmt->execute(['identifier', hash('sha256', strtolower($username))]);
        }

        // The identifier of the second probe of section H — the one that
        // carries no header.
        $probe = (string) ($GLOBALS['live_test_probe_username'] ?? '');

        if ($probe !== '') {
            $stmt = $pdo->prepare(
                'DELETE FROM `login_attempts` WHERE `scope` = ? AND `key_hash` = ?'
            );
            $stmt->execute(['identifier', hash('sha256', strtolower($probe))]);
        }

        $pdo->exec("DELETE FROM `login_attempts` WHERE `scope` = 'source'");
        // The request throttle rows (issue-022). The source buckets are
        // keyed on the address this test connected from, and the address
        // buckets on the addresses it submitted — so both are the test's
        // own rows and both go.
        foreach (['reset:source', 'register:source'] as $bucket) {
            $stmt = $pdo->prepare('DELETE FROM `request_throttles` WHERE `bucket` = ?');
            $stmt->execute([$bucket]);
        }

        foreach ((array) ($GLOBALS['live_test_addresses'] ?? []) as $address) {
            $stmt = $pdo->prepare(
                'DELETE FROM `request_throttles` WHERE `bucket` = ? AND `key_hash` = ?'
            );
            $stmt->execute(['reset:address', hash('sha256', strtolower(trim((string) $address)))]);
        }

        // The queued messages. They carry a reset link and a confirmation
        // link, so they leave with the accounts they belong to.
        $stmt = $pdo->prepare('DELETE FROM `mail_outbox` WHERE `to_address` LIKE ?');
        $stmt->execute(['live\_test\_%']);
        $stmt = $pdo->prepare('DELETE FROM `users` WHERE `username` LIKE ?');
        $stmt->execute(['live\\_test\\_%' . $marker . '%']);
    } catch (Throwable $e) {
        // A cleanup failure must never turn a green run red -- but it must be
        // visible, or the litter accumulates unnoticed.
        fwrite(STDERR, 'live_cleanup: ' . $e->getMessage() . "\n");
    }

    // The wizard copy of section L. It lives in the document root, so it
    // has to go — and it goes even when the run died half way through.
    $probeDir = (string) ($GLOBALS['live_test_probe_dir'] ?? '');

    if ($probeDir !== '' && is_dir($probeDir)) {
        live_remove_tree($probeDir);
    }

    // The probe database user of section L.
    $root = live_root_pdo();

    if ($root !== null) {
        try {
            $root->exec("DROP USER IF EXISTS `ft_install_probe_user`@'%'");
        } catch (Throwable $e) {
            fwrite(STDERR, 'live_cleanup: ' . $e->getMessage() . "\n");
        }
    }
}

register_shutdown_function('live_cleanup');

$fails = [];
$passes = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fails, $passes;
    if ($cond) { $passes++; echo "  [PASS] $label\n"; }
    else {
        $fails[] = $label . ($detail !== '' ? " -- $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

function req(string $method, string $url, array $fields = [], array &$jar = [], array $extraHeaders = []): array
{
    $headers = array_merge(['Accept: text/html'], $extraHeaders);
    $body = '';
    if ($method === 'POST') {
        $body = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $k => $v) { $pairs[] = $k . '=' . $v; }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    $hdrs = $http_response_header ?? [];
    $status = 0; $location = '';
    foreach ($hdrs as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
        if (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        if (stripos($h, 'Set-Cookie:') === 0) {
            $kv = explode(';', trim(substr($h, 11)));
            $p = explode('=', $kv[0], 2);
            if (count($p) === 2) { $jar[trim($p[0])] = trim($p[1]); }
        }
    }
    return ['status' => $status, 'body' => (string) $res, 'location' => $location, 'headers' => $hdrs];
}

function csrf(string $html): string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) { return $m[1]; }
    return '';
}

$marker = bin2hex(random_bytes(4));
$GLOBALS['live_test_marker'] = $marker;
$user = 'live_test_s_' . $marker;
$GLOBALS['live_test_username'] = $user;
// The password carries the marker of this run, so the needle the session
// store is searched for belongs to THIS run and to nothing else (section L).
$pass = 'Str0ngPass!A1' . $marker;
$email = 'live_test_s_' . $marker . '@example.test';
$jar = [];

echo "== A. Theme cookie whitelist ==\n";
$r = req('GET', BASE . '/login', [], $jar, ['Cookie: theme=dark']);
ok('theme=dark renders data-theme="dark"', str_contains($r['body'], 'data-theme="dark"'));
$r = req('GET', BASE . '/login', [], $jar, ['Cookie: theme=light']);
ok('theme=light renders data-theme="light"', str_contains($r['body'], 'data-theme="light"'));
$r = req('GET', BASE . '/login', [], $jar, ['Cookie: theme=bogus']);
ok('an unknown theme value is dropped', !str_contains($r['body'], 'data-theme='),
    'the attribute was rendered anyway');
$r = req('GET', BASE . '/login', [], $jar, ['Cookie: theme="><script>alert(1)</script>']);
ok('a hostile theme value cannot break out of the attribute', !str_contains($r['body'], '<script>alert(1)</script>')
    && !str_contains($r['body'], 'data-theme="'), 'the attribute was rendered anyway');

echo "\n== B. The active nav link uses aria-current, not a class ==\n";
$r = req('GET', BASE . '/login', [], $jar);
$r = req('POST', BASE . '/register', [
    'csrf_token' => csrf($r['body']), 'username' => $user, 'email' => $email,
    'email_confirmation' => $email, 'password' => $pass, 'password_confirmation' => $pass,
], $jar);
ok('registration succeeded', str_contains($r['body'], 'Your account is ready'), 'status=' . $r['status']);
$r = req('GET', BASE . '/login', [], $jar);
$r = req('POST', BASE . '/login', ['csrf_token' => csrf($r['body']), 'username' => $user, 'password' => $pass], $jar);
ok('signed in', $r['status'] === 302, 'status=' . $r['status']);

$r = req('GET', BASE . '/dashboard', [], $jar);
ok('the dashboard nav marks the current page with aria-current="page"',
    (bool) preg_match('/aria-current="page"[^>]*>\s*Dashboard/i', $r['body'])
    || (bool) preg_match('/href="\/dashboard"[^>]*aria-current="page"/', $r['body']),
    'no aria-current on the dashboard link');
ok('the nav does not use an is-active class', !str_contains($r['body'], 'is-active'));
$r = req('GET', BASE . '/reports', [], $jar);
ok('the reports nav marks the current page', str_contains($r['body'], 'aria-current="page"'));

echo "\n== C. A GET form carries no CSRF token ==\n";

// The filter bar only renders when there is at least one transaction, so
// seed one first -- otherwise the empty state renders and there is no
// GET form to check.
$r = req('GET', BASE . '/transactions/new', [], $jar);
$r = req('POST', BASE . '/transactions/save', [
    'csrf_token' => csrf($r['body']), 'type' => 'expense', 'amount' => '11.00',
    'date' => '2026-10-23', 'description' => 'seed ' . $marker,
], $jar);

$checked = 0;
foreach (['/transactions', '/reports', '/categories', '/dashboard'] as $path) {
    $r = req('GET', BASE . $path, [], $jar);
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $r['body'], $fm, PREG_SET_ORDER);
    $leaked = 0; $seen = 0;
    foreach ($fm as $form) {
        $isPost = stripos($form[1], 'method="post"') !== false
            || stripos($form[1], "method='post'") !== false;
        if ($isPost) { continue; }
        // A form with no method attribute is a GET form.
        $seen++;
        if (stripos($form[2], 'csrf_token') !== false) { $leaked++; }
    }
    $checked += $seen;
    ok('no GET form on ' . $path . ' carries a CSRF token', $leaked === 0, $leaked . ' leaked');
}
ok('at least one GET form was actually inspected', $checked > 0, 'inspected ' . $checked);

echo "\n== D. Every POST form carries a CSRF token ==\n";
foreach (['/transactions/new', '/categories', '/settings'] as $path) {
    $r = req('GET', BASE . $path, [], $jar);
    preg_match_all('#<form\b[^>]*method="post"[^>]*>(.*?)</form>#is', $r['body'], $pm);
    $missing = 0;
    foreach ($pm[1] as $form) {
        if (stripos($form, 'csrf_token') === false) { $missing++; }
    }
    ok('every POST form on ' . $path . ' carries a CSRF token', $missing === 0,
        $missing . ' of ' . count($pm[1]) . ' missing');
}

echo "\n== E. Output escaping ==\n";
$xss = '<script>alert("xss")</script>';
$r = req('GET', BASE . '/categories', [], $jar);
$r = req('POST', BASE . '/categories/save', [
    'csrf_token' => csrf($r['body']), 'name' => 'XSS ' . $xss . ' ' . $marker, 'type' => 'expense',
], $jar);
$r = req('GET', BASE . '/categories', [], $jar);
ok('a script tag in a category name is escaped, not rendered',
    !str_contains($r['body'], '<script>alert("xss")</script>'));
ok('the escaped form is present', str_contains($r['body'], '&lt;script&gt;'));

$r = req('GET', BASE . '/transactions/new', [], $jar);
$r = req('POST', BASE . '/transactions/save', [
    'csrf_token' => csrf($r['body']), 'type' => 'expense', 'amount' => '5.00',
    'date' => '2026-10-22', 'description' => 'Quote " and \' and <b> ' . $marker,
], $jar);
$r = req('GET', BASE . '/transactions', [], $jar);
ok('a quote in a description is escaped', !str_contains($r['body'], '<b> ' . $marker)
    && str_contains($r['body'], '&lt;b&gt; ' . $marker));
ok('a double quote in a description is escaped', !str_contains($r['body'], 'Quote " and'));

echo "\n== F. Session cookie flags ==\n";
// A FRESH jar: with a session cookie already sent, the server does not
// re-send Set-Cookie and the assertion would read an empty header.
$fresh = [];
$r = req('GET', BASE . '/login', [], $fresh);
$setCookie = '';
foreach ($r['headers'] as $h) { if (stripos($h, 'Set-Cookie:') === 0) { $setCookie = $h; } }
ok('a session cookie was issued', $setCookie !== '', 'no Set-Cookie header');
ok('the session cookie is HttpOnly', stripos($setCookie, 'httponly') !== false, $setCookie);
ok('the session cookie is SameSite', stripos($setCookie, 'samesite') !== false, $setCookie);

echo "\n== G. The session id is not readable from the page ==\n";
$r = req('GET', BASE . '/dashboard', [], $jar);
$jarSid = $jar['PHPSESSID'] ?? '';
ok('the session id is not printed in the page', $jarSid === '' || !str_contains($r['body'], $jarSid),
    'the session id was printed');

echo "\n== H. The sign-in throttle, over real HTTP ==\n";

// A FRESH jar: the signed-in session of the earlier sections is not the
// one under test, and a guest is the only visitor the sign-in screen
// serves.
$throttleJar = [];
$pdo         = Database::getInstance()->getConnection();

// The source bucket is shared by every request that comes from this
// address, so the test empties it first: a leftover from an earlier run
// must not change the outcome. The identifier row of the test account is
// emptied for the same reason.
$identifierKey = hash('sha256', strtolower($user));

$stmt = $pdo->prepare('DELETE FROM `login_attempts` WHERE `scope` = ? AND `key_hash` = ?');
$stmt->execute(['identifier', $identifierKey]);
$pdo->exec("DELETE FROM `login_attempts` WHERE `scope` = 'source'");

// FIVE wrong passwords. Every one of them carries a spoofed
// X-Forwarded-For: the header is client-controlled and must not move the
// bucket (issue-021 §8.2).
$wrongMessage = '';

for ($i = 1; $i <= 5; $i++) {
    $r = req('GET', BASE . '/login', [], $throttleJar);
    $token = csrf($r['body']);

    $r = req('POST', BASE . '/login', [
        'csrf_token' => $token, 'username' => $user, 'password' => 'wrong-password-' . $i,
    ], $throttleJar, ['X-Forwarded-For: 203.0.113.9']);

    if ($i === 1) {
        $wrongMessage = 'That username and password do not match.';
        ok('a wrong password still says only that the credentials do not match',
            str_contains($r['body'], $wrongMessage),
            'status=' . $r['status']);
    }
}

// The counter holds EXACTLY five: no failure lost under the upsert, and
// none counted twice.
$stmt = $pdo->prepare('SELECT `failed_attempts` FROM `login_attempts` WHERE `scope` = ? AND `key_hash` = ?');
$stmt->execute(['identifier', $identifierKey]);
$counter = $stmt->fetchColumn();
ok('the counter holds exactly five failures', (int) $counter === 5,
    'the row says ' . var_export($counter, true));

// The spoofed header did not create a bucket of its own: the only source
// row is the one keyed on the address that opened the connection.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM `login_attempts` WHERE `scope` = ?');
$stmt->execute(['source']);
$sourceRows = (int) $stmt->fetchColumn();
ok('the spoofed X-Forwarded-For did not create a bucket of its own', $sourceRows === 1,
    'the source rows: ' . $sourceRows);

// The row is NOT keyed on the address the header named. LiteSpeed rewrites
// REMOTE_ADDR from X-Forwarded-For for a trusted peer, so a key built from
// REMOTE_ADDR alone would be a dial the visitor can turn.
$stmt = $pdo->prepare('SELECT `key_hash` FROM `login_attempts` WHERE `scope` = ?');
$stmt->execute(['source']);
$sourceKey = (string) $stmt->fetchColumn();

ok('the source bucket is not keyed on the spoofed address',
    $sourceKey !== '' && $sourceKey !== hash('sha256', '203.0.113.9'),
    'the bucket is keyed on the header');

// A wrong password for ANOTHER identifier, sent WITHOUT the header, lands
// in the SAME bucket. If the header chose the bucket, this failure would
// have been counted somewhere else — and the visitor would be able to
// spend the bucket of somebody else.
$probeUser = 'live_throttle_probe_' . $GLOBALS['live_test_marker'];
$GLOBALS['live_test_probe_username'] = $probeUser;

$r = req('GET', BASE . '/login', [], $throttleJar);
$token = csrf($r['body']);
$r = req('POST', BASE . '/login', [
    'csrf_token' => $token, 'username' => $probeUser, 'password' => 'wrong-password-probe',
], $throttleJar);

$stmt = $pdo->prepare(
    'SELECT COUNT(*), MAX(`failed_attempts`), MAX(`key_hash`) FROM `login_attempts` WHERE `scope` = ?'
);
$stmt->execute(['source']);
$sourceState = $stmt->fetch(PDO::FETCH_NUM);

ok('a failure sent without the header lands in the same bucket',
    (int) $sourceState[0] === 1 && (int) $sourceState[1] === 6 && (string) $sourceState[2] === $sourceKey,
    'the source rows: ' . var_export($sourceState, true));

// The correct password is refused while the wait is in force.
$r = req('GET', BASE . '/login', [], $throttleJar);
$token = csrf($r['body']);
$r = req('POST', BASE . '/login', [
    'csrf_token' => $token, 'username' => $user, 'password' => $pass,
], $throttleJar);

ok('the correct password is refused while the wait is in force',
    str_contains($r['body'], 'Too many attempts.'),
    'status=' . $r['status']);
ok('the message states the time that is actually left',
    (bool) preg_match('/Too many attempts\. Try again in \d+ minutes?\./', $r['body']),
    'the message does not state the remaining wait');
ok('the refusal does not fall back to the credentials message',
    !str_contains($r['body'], $wrongMessage));

// The failures age past the quiet window: the row is reset and the
// correct password works again. This is the part that stops an attacker
// from locking a person out of their own account forever.
$stmt = $pdo->prepare(
    'UPDATE `login_attempts`
        SET `last_failure_at` = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE),
            `locked_until`      = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
      WHERE `scope` = ? AND `key_hash` = ?'
);
$stmt->execute(['identifier', $identifierKey]);

$r = req('GET', BASE . '/login', [], $throttleJar);
$token = csrf($r['body']);
$r = req('POST', BASE . '/login', [
    'csrf_token' => $token, 'username' => $user, 'password' => $pass,
], $throttleJar);

ok('the correct password is accepted once the failures have decayed',
    $r['status'] === 302,
    'status=' . $r['status'] . ' the body says: ' . substr(trim(strip_tags($r['body'])), 0, 160));

// A successful sign-in clears BOTH counters.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM `login_attempts` WHERE `scope` = ? AND `key_hash` = ?');
$stmt->execute(['identifier', $identifierKey]);
$left = (int) $stmt->fetchColumn();
ok('a successful sign-in clears the identifier counter', $left === 0, 'the rows left: ' . $left);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM `login_attempts` WHERE `scope` = ?');
$stmt->execute(['source']);
$left = (int) $stmt->fetchColumn();
ok('a successful sign-in clears the source counter', $left === 0, 'the rows left: ' . $left);

echo "\n== I. The account-existence oracle, over real HTTP ==\n";

// The counters are emptied first. The source bucket is shared with
// every other request that comes from this address, and a leftover from
// an earlier run must not change the outcome.
$pdo->exec('DELETE FROM `request_throttles`');

$oracleJar = [];

// The KNOWN address -- the account this file created in section B.
$r = req('GET', BASE . '/forgot-password', [], $oracleJar);
$token = csrf($r['body']);

$started = microtime(true);
$r = req('POST', BASE . '/forgot-password', [
    'csrf_token' => $token, 'email' => $email,
], $oracleJar);
$knownElapsed = microtime(true) - $started;
$knownStatus  = $r['status'];
$knownBody    = $r['body'];

// The UNKNOWN address -- nothing in the database carries it.
$unknown = 'live_test_unknown_' . $marker . '@example.test';
$GLOBALS['live_test_addresses'][] = $unknown;

$r = req('GET', BASE . '/forgot-password', [], $oracleJar);
$token = csrf($r['body']);

$started = microtime(true);
$r = req('POST', BASE . '/forgot-password', [
    'csrf_token' => $token, 'email' => $unknown,
], $oracleJar);
$unknownElapsed = microtime(true) - $started;
$unknownStatus  = $r['status'];
$unknownBody    = $r['body'];

ok('the known address answers 200', $knownStatus === 200, 'status=' . $knownStatus);
ok('the unknown address answers 200', $unknownStatus === 200, 'status=' . $unknownStatus);

// The ORACLE. The two answers must not differ in a single byte.
ok('the two answers are byte-identical', $knownBody === $unknownBody,
    'the bodies differ: ' . strlen($knownBody) . ' against ' . strlen($unknownBody) . ' bytes');

ok('the known address is never echoed back', !str_contains($knownBody, $email));
ok('the unknown address is never echoed back', !str_contains($unknownBody, $unknown));

// The TIMING ORACLE. The request never talks to the SMTP server
// (issue-022 section 7.1), so the known branch pays for one INSERT and
// the unknown branch for none -- a difference of milliseconds, and the
// floor swallows it.
ok('the two answers take the same time',
    abs($knownElapsed - $unknownElapsed) < 0.15,
    sprintf('%.3fs against %.3fs', $knownElapsed, $unknownElapsed));

$floor = (AUTH_TIMING_FLOOR_MILLISECONDS / 1000) * 0.8;
ok('the timing floor is honoured', $knownElapsed >= $floor && $unknownElapsed >= $floor,
    sprintf('%.3fs against %.3fs, the floor is %.3fs', $knownElapsed, $unknownElapsed, $floor));

// The queue. A KNOWN address queues one message and an UNKNOWN one
// queues none -- but only when the installation can queue at all. The
// state is read from the answer itself, so the test also works on an
// installation whose mail is not configured.
$canQueue = str_contains($knownBody, 'a reset link is on its way');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM `mail_outbox` WHERE `to_address` = ?');
$stmt->execute([$email]);
$knownQueued = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM `mail_outbox` WHERE `to_address` = ?');
$stmt->execute([$unknown]);
$unknownQueued = (int) $stmt->fetchColumn();

ok('an unknown address queues nothing', $unknownQueued === 0, (string) $unknownQueued);

if ($canQueue) {
    ok('a known address queues exactly one message', $knownQueued === 1, (string) $knownQueued);
} else {
    ok('an installation that cannot send queues nothing', $knownQueued === 0, (string) $knownQueued);
    ok('the installation state is the same for every address',
        str_contains($knownBody, 'Email is not configured for this installation'),
        'the state is not the not-configured one');
}

echo "\n== J. The two limiters, over real HTTP ==\n";

// The counters are emptied again: the section above counted two
// requests against the source bucket.
$pdo->exec('DELETE FROM `request_throttles`');

$limitJar = [];
$served   = 0;

// --- J1. The per-address limiter ---

// An address that matches no account: the limiter counts the ATTEMPT,
// so an address nobody owns is throttled too.
$limiterAddress = 'live_test_limiter_' . $marker . '@example.test';
$GLOBALS['live_test_addresses'][] = $limiterAddress;

$statuses    = [];
$refusal     = '';
$refusalHead = [];

for ($i = 0; $i < 4; $i++) {
    $r = req('GET', BASE . '/forgot-password', [], $limitJar);
    $token = csrf($r['body']);
    $r = req('POST', BASE . '/forgot-password', [
        'csrf_token' => $token, 'email' => $limiterAddress,
    ], $limitJar);

    $statuses[] = $r['status'];

    if ($r['status'] === 200) {
        $served++;
    }

    if ($r['status'] === 429) {
        $refusal     = $r['body'];
        $refusalHead = $r['headers'];
    }
}

ok('the first three requests are served',
    array_slice($statuses, 0, 3) === [200, 200, 200], implode(', ', $statuses));
ok('the fourth request is refused with 429', $statuses[3] === 429, 'status=' . $statuses[3]);
ok('the refusal says that the visitor is early',
    str_contains($refusal, 'Too many requests. Please try again in a few minutes.'),
    'the body says: ' . substr(trim(strip_tags($refusal)), 0, 120));

// The Retry-After header: a client cannot schedule against a refusal
// that does not say how long to wait.
$retryAfter = 0;

foreach ($refusalHead as $h) {
    if (stripos($h, 'Retry-After:') === 0) {
        $retryAfter = (int) trim(substr($h, 12));
    }
}

ok('the refusal carries a Retry-After header', $retryAfter > 0, 'the header says ' . $retryAfter);

// The counter holds EXACTLY the served requests: a refused request
// does not raise it, so the window is not extended by the requests that
// were turned away.
$stmt = $pdo->prepare('SELECT hits FROM `request_throttles` WHERE `bucket` = ? AND `key_hash` = ?');
$stmt->execute(['reset:address', hash('sha256', strtolower($limiterAddress))]);
$addressHits = (int) $stmt->fetchColumn();
ok('the address counter holds exactly the served requests', $addressHits === 3, (string) $addressHits);

// The same refusal for a KNOWN address. The bodies must be identical:
// a refusal that differed would tell the caller which addresses have
// an account.
$knownStatuses = [];
$knownRefusal  = '';

for ($i = 0; $i < 4; $i++) {
    $r = req('GET', BASE . '/forgot-password', [], $limitJar);
    $token = csrf($r['body']);
    $r = req('POST', BASE . '/forgot-password', [
        'csrf_token' => $token, 'email' => $email,
    ], $limitJar);

    $knownStatuses[] = $r['status'];

    if ($r['status'] === 200) {
        $served++;
    }

    if ($r['status'] === 429) {
        $knownRefusal = $r['body'];
    }
}

ok('a known address is refused the same way',
    array_slice($knownStatuses, 0, 3) === [200, 200, 200] && $knownStatuses[3] === 429,
    implode(', ', $knownStatuses));
ok('the refusal is byte-identical for a known and an unknown address',
    $knownRefusal === $refusal,
    'the bodies differ: ' . strlen($knownRefusal) . ' against ' . strlen($refusal) . ' bytes');

// The source counter counted the requests that were SERVED, and only
// those: the two refusals did not extend the window.
$stmt = $pdo->prepare('SELECT hits FROM `request_throttles` WHERE `bucket` = ?');
$stmt->execute(['reset:source']);
$sourceHits = (int) $stmt->fetchColumn();
ok('the source counter holds exactly the served requests', $sourceHits === $served,
    'the counter says ' . $sourceHits . ', the served requests were ' . $served);

// --- J2. The per-source limiter ---

// The source counter is driven to the limit directly: the point of the
// assertion is the REFUSAL, not that twenty requests were counted.
// The address counters are emptied first, so the refusal can only come
// from the source bucket.
$pdo->exec("DELETE FROM `request_throttles` WHERE `bucket` = 'reset:address'");
$pdo->exec("UPDATE `request_throttles` SET `hits` = " . (int) RESET_SOURCE_LIMIT
    . " WHERE `bucket` = 'reset:source'");

$freshAddress = 'live_test_source_' . $marker . '@example.test';
$GLOBALS['live_test_addresses'][] = $freshAddress;

$r = req('GET', BASE . '/forgot-password', [], $limitJar);
$token = csrf($r['body']);
$r = req('POST', BASE . '/forgot-password', [
    'csrf_token' => $token, 'email' => $freshAddress,
], $limitJar);

ok('the source limiter refuses the request', $r['status'] === 429, 'status=' . $r['status']);
ok('the source refusal says that the visitor is early',
    str_contains($r['body'], 'Too many requests. Please try again in a few minutes.'));

$sourceRetry = 0;

foreach ($r['headers'] as $h) {
    if (stripos($h, 'Retry-After:') === 0) {
        $sourceRetry = (int) trim(substr($h, 12));
    }
}

ok('the source refusal carries a Retry-After header', $sourceRetry > 0, 'the header says ' . $sourceRetry);

echo "\n== K. The registration limiter, over real HTTP ==\n";

// The counter is emptied first: the source bucket is shared with the
// earlier sections of this file.
$pdo->exec("DELETE FROM `request_throttles` WHERE `bucket` = 'register:source'");

$registerJar = [];
$registered  = 0;

for ($i = 1; $i <= 2; $i++) {
    $name    = 'live_test_r_' . $marker . '_' . $i;
    $address = $name . '@example.test';
    $GLOBALS['live_test_addresses'][] = $address;

    $r = req('GET', BASE . '/register', [], $registerJar);
    $token = csrf($r['body']);
    $r = req('POST', BASE . '/register', [
        'csrf_token' => $token, 'username' => $name, 'email' => $address,
        'email_confirmation' => $address, 'password' => $pass, 'password_confirmation' => $pass,
    ], $registerJar);

    if ($r['status'] === 200 && str_contains($r['body'], 'Your account is ready')) {
        $registered++;
    }
}

ok('the limiter does not stop an ordinary registration', $registered === 2, (string) $registered);

$stmt = $pdo->prepare('SELECT hits FROM `request_throttles` WHERE `bucket` = ?');
$stmt->execute(['register:source']);
$registerHits = (int) $stmt->fetchColumn();
ok('the register counter counted the requests', $registerHits === 2, (string) $registerHits);

// The counter is driven to the limit: the point of the assertion is
// the REFUSAL -- and that a refused request creates no account.
$pdo->exec("UPDATE `request_throttles` SET `hits` = " . (int) REGISTER_SOURCE_LIMIT
    . " WHERE `bucket` = 'register:source'");

$refusedName    = 'live_test_r_' . $marker . '_refused';
$refusedAddress = $refusedName . '@example.test';
$GLOBALS['live_test_addresses'][] = $refusedAddress;

$r = req('GET', BASE . '/register', [], $registerJar);
$token = csrf($r['body']);
$r = req('POST', BASE . '/register', [
    'csrf_token' => $token, 'username' => $refusedName, 'email' => $refusedAddress,
    'email_confirmation' => $refusedAddress, 'password' => $pass, 'password_confirmation' => $pass,
], $registerJar);

ok('the registration is refused with 429', $r['status'] === 429, 'status=' . $r['status']);
ok('the refusal says that the visitor is early',
    str_contains($r['body'], 'Too many requests. Please try again in a few minutes.'));

$registerRetry = 0;

foreach ($r['headers'] as $h) {
    if (stripos($h, 'Retry-After:') === 0) {
        $registerRetry = (int) trim(substr($h, 12));
    }
}

ok('the registration refusal carries a Retry-After header', $registerRetry > 0,
    'the header says ' . $registerRetry);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE `username` = ?');
$stmt->execute([$refusedName]);
ok('the refused registration created no account', (int) $stmt->fetchColumn() === 0);


// ---------------------------------------------------------------------------
// L. The installer keeps no secret on the disk (issue-025).
//
// The wizard is the one screen where a production credential is typed into
// a browser. This section proves that the credential is nowhere on the
// disk when the step is over: not in the session file, and not in the
// shared temporary directory either.
// ---------------------------------------------------------------------------

echo "\n== L. The installer keeps no secret on the disk ==\n";

$docRoot       = realpath(__DIR__ . '/../..');
$appSessionDir = defined('SESSION_SAVE_PATH') ? (string) SESSION_SAVE_PATH : '';
$tmpDir        = rtrim(sys_get_temp_dir(), '/');

ok('the application declares a session directory', $appSessionDir !== '', 'SESSION_SAVE_PATH is not declared');

if ($appSessionDir !== '') {
    ok(
        'the session directory is the application\'s own directory',
        $appSessionDir === $docRoot . '/storage/sessions',
        $appSessionDir . ' — the document root is ' . $docRoot
    );
}

$sid = (string) ($jar['PHPSESSID'] ?? '');

ok('the signed-in session has an id', $sid !== '');

if ($sid !== '' && $appSessionDir !== '') {
    ok(
        'the session file is in the application\'s own directory',
        is_file($appSessionDir . '/sess_' . $sid),
        $appSessionDir . '/sess_' . $sid
    );
    ok(
        'the session file is not in the shared temporary directory',
        !is_file($tmpDir . '/sess_' . $sid)
    );
}

$leaks = install_sandbox_scan($appSessionDir, $pass);

ok('no file of the session store holds the password', $leaks === [], implode(', ', $leaks));

$tmpLeaks = install_sandbox_scan_sessions($tmpDir, $pass);

ok(
    'no session file in the shared temporary directory holds the password',
    $tmpLeaks === [],
    implode(', ', $tmpLeaks)
);

// --- L2. A copy of the wizard, under the running server ---------------------
//
// The real installer refuses to run: the application is installed, so
// install.php answers 403. The wizard therefore runs from a COPY inside the
// document root — the same copy the SOURCE tier runs, but under the real
// web server this time, with the real session handling.

$probeName = '_ft_install_probe_' . bin2hex(random_bytes(6));
$probeDir  = $docRoot . '/' . $probeName;

$GLOBALS['live_test_probe_dir'] = $probeDir;

install_sandbox_make_at($probeDir, $docRoot);

$probeUrl = BASE . '/' . $probeName . '/install.php';
$probeJar = [];

$r = req('GET', $probeUrl, [], $probeJar);

ok('the wizard answers under the running server', $r['status'] === 200, 'status=' . $r['status']);
ok('the wizard carries a CSRF token', csrf($r['body']) !== '');

$idBefore = (string) ($probeJar['PHPSESSID'] ?? '');

// A database user with a password of its own. The needle of this run has
// to be unique, or the assertion would prove nothing.
$probePass = 'Ft Pr0be ' . bin2hex(random_bytes(8)) . ' ';
$probeUser = 'ft_install_probe_user';
$root      = live_root_pdo();

if ($root === null) {
    ok('the probe database user could be created', false, 'the root account is not reachable');
} else {
    try {
        $root->exec("DROP USER IF EXISTS `{$probeUser}`@'%'");
        $root->exec("CREATE USER `{$probeUser}`@'%' IDENTIFIED BY " . $root->quote($probePass));
        $root->exec('GRANT ALL PRIVILEGES ON `' . DB_NAME . '`.* TO `' . $probeUser . '`@\'%\'');
        ok('the probe database user was created', true);
    } catch (PDOException $ex) {
        ok('the probe database user was created', false, $ex->getMessage());
    }

    $r = req('POST', $probeUrl, [
        'csrf_token' => csrf($r['body']),
        'step'       => '1',
        'action'     => 'test_db',
        'db_host'    => DB_HOST,
        'db_name'    => DB_NAME,
        'db_user'    => $probeUser,
        'db_pass'    => $probePass,
    ], $probeJar);

    ok('step 1 of the wizard succeeds', $r['status'] === 302, 'status=' . $r['status']);

    $probeSessionDir = $probeDir . '/storage/sessions';

    ok(
        'the session store of the wizard is its own directory',
        is_dir($probeSessionDir),
        $probeSessionDir
    );

    $leaks = install_sandbox_scan($probeSessionDir, $probePass);

    ok(
        'no file of the wizard\'s session store holds the password',
        $leaks === [],
        implode(', ', $leaks)
    );

    $tmpLeaks = install_sandbox_scan_sessions($tmpDir, $probePass);

    ok(
        'no session file in the shared temporary directory holds the password',
        $tmpLeaks === [],
        implode(', ', $tmpLeaks)
    );

    $idAfter = (string) ($probeJar['PHPSESSID'] ?? '');

    ok(
        'the session id is regenerated after the step boundary',
        $idAfter !== '' && $idAfter !== $idBefore,
        'before=' . $idBefore . ' after=' . $idAfter
    );

    // --- L3. An unknown session id is not adopted --------------------------

    $forged    = bin2hex(random_bytes(16));
    $forgedJar = ['PHPSESSID' => $forged];

    req('GET', $probeUrl, [], $forgedJar);

    $issued = (string) ($forgedJar['PHPSESSID'] ?? '');

    ok(
        'an unknown session id is not adopted',
        $issued !== '' && $issued !== $forged,
        'sent=' . $forged . ' issued=' . $issued
    );
    ok(
        'no file was created for the unknown session id',
        !is_file($probeSessionDir . '/sess_' . $forged)
    );
}

echo "\nFailed: " . count($fails) . "\n";

if ($fails !== []) {
    echo "\nThe failures:\n";

    foreach ($fails as $failure) {
        echo '  - ' . $failure . "\n";
    }

    exit(1);
}

echo "ALL ASSERTIONS PASSED\n";
exit(0);
