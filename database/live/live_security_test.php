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
 * and the session id never reaches the page.
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

/**
 * The random marker of this run. It is the only handle on the accounts the
 * test creates, and it is random hex, so the cleanup pattern cannot match
 * a real account.
 */
$GLOBALS['live_test_marker'] = '';

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
        $stmt = $pdo->prepare('DELETE FROM `users` WHERE `username` LIKE ?');
        $stmt->execute(['live\\_test\\_%' . $marker . '%']);
    } catch (Throwable $e) {
        // A cleanup failure must never turn a green run red -- but it must be
        // visible, or the litter accumulates unnoticed.
        fwrite(STDERR, 'live_cleanup: ' . $e->getMessage() . "\n");
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
$pass = 'Str0ngPass!A1';
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
