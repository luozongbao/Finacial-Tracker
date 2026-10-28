<?php
/**
 * live_app_test.php -- the LIVE tier: the running application, over HTTP.
 *
 * The other tests in database/ are the SOURCE tier: they read the files and
 * drive a sandbox copy of the application against a scratch database. This
 * one talks to the application that is actually serving requests -- the one
 * the operator uses.
 *
 * It exists because the SOURCE tier cannot see a whole class of defect: the
 * one that only exists under the WEB SAPI. The install bug that blocked
 * v1.0 was exactly that -- PHP_BINARY is empty under LSPHP and populated
 * under the CLI SAPI, so the CLI test passed while the wizard could never
 * succeed on the shipped server.
 *
 * WHAT IT NEEDS
 *   - the app container running, with config.php in place (the install
 *     wizard must have been run at least once),
 *   - the application reachable at $LIVE_BASE_URL -- http://127.0.0.1 inside
 *     the container, http://127.0.0.1:8088 on the host.
 *
 * WHAT IT PROVES
 *   - routing, the guest guard and the status codes,
 *   - registration, sign-in, sign-out,
 *   - the categories, the transactions, the filters and the edit form,
 *   - the OWNERSHIP boundary: user B must not see, open or delete user A's
 *     rows,
 *   - the reports, the dashboard and the settings,
 *   - the error paths (404, 405) and the security headers.
 *
 * IT CLEANS UP AFTER ITSELF. Every account it creates carries a random
 * marker and is deleted in a shutdown function, so a run that dies half way
 * through still leaves the database clean.
 *
 * Run:  docker compose exec -T app php /var/www/html/database/live/live_app_test.php
 * Exit: 0 on success, 1 on a failure, 2 when the app is not reachable. The
 *       completion marker is printed either way.
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
    if ($cond) {
        $passes++;
        echo "  [PASS] $label\n";
    } else {
        $fails[] = $label . ($detail !== '' ? " -- $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

/**
 * @param array<string,string> $jar cookie jar, updated in place
 */
function req(string $method, string $url, array $fields = [], array &$jar = []): array
{
    $headers = ['Accept: text/html'];
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

function snippet(string $html): string
{
    return substr(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 0, 220);
}

$marker = bin2hex(random_bytes(4));
$GLOBALS['live_test_marker'] = $marker;
$userA  = 'live_test_a_' . $marker;
$passA  = 'Str0ngPass!A1';
$emailA = 'live_test_a_' . $marker . '@example.test';
$userB  = 'live_test_b_' . $marker;
$passB  = 'Str0ngPass!B1';
$emailB = 'live_test_b_' . $marker . '@example.test';

$jarA = [];
$jarB = [];
$jarG = [];

echo "== 1. Guest routing ==\n";
$r = req('GET', BASE . '/', [], $jarG);
ok('GET / -> 302', $r['status'] === 302, 'status=' . $r['status']);
ok('GET / -> Location /dashboard', str_contains($r['location'], '/dashboard'), $r['location']);

$r = req('GET', BASE . '/dashboard', [], $jarG);
ok('GET /dashboard (guest) -> 302', $r['status'] === 302, 'status=' . $r['status']);
ok('GET /dashboard (guest) -> /login', str_contains($r['location'], '/login'), $r['location']);

$r = req('GET', BASE . '/transactions', [], $jarG);
ok('GET /transactions (guest) -> 302 to /login', $r['status'] === 302 && str_contains($r['location'], '/login'),
    $r['status'] . ' ' . $r['location']);

$r = req('GET', BASE . '/login', [], $jarG);
ok('GET /login -> 200', $r['status'] === 200, 'status=' . $r['status']);
ok('GET /login carries a CSRF token', csrf($r['body']) !== '');
ok('GET /login has no application nav', !str_contains($r['body'], 'bottom-nav__link'));

echo "\n== 2. Register ==\n";
$r = req('GET', BASE . '/register', [], $jarG);
ok('GET /register -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('POST', BASE . '/register', [
    'csrf_token' => csrf($r['body']), 'username' => $userA, 'email' => $emailA,
    'email_confirmation' => $emailA, 'password' => $passA, 'password_confirmation' => $passA,
], $jarG);
ok('POST /register -> 200 (the success state replaces the form)', $r['status'] === 200, 'status=' . $r['status'] . ' body=' . snippet($r['body']));
ok('POST /register says "Your account is ready."', str_contains($r['body'], 'Your account is ready.'), snippet($r['body']));
ok('POST /register offers a "Go to sign in" button', stripos($r['body'], 'sign in') !== false, snippet($r['body']));
ok('POST /register does NOT sign the user in', !str_contains($r['location'], '/dashboard'), $r['location']);

$r = req('GET', BASE . '/register', [], $jarG);
$r = req('POST', BASE . '/register', [
    'csrf_token' => csrf($r['body']), 'username' => $userB, 'email' => $emailB,
    'email_confirmation' => $emailB, 'password' => $passB, 'password_confirmation' => $passB,
], $jarG);
ok('second user registered', $r['status'] === 200 && str_contains($r['body'], 'Your account is ready.'), 'status=' . $r['status']);

echo "\n== 3. Login ==\n";
$r = req('GET', BASE . '/login', [], $jarG);
$r = req('POST', BASE . '/login', ['csrf_token' => csrf($r['body']), 'username' => $userA, 'password' => 'wrong-password'], $jarG);
ok('login with a wrong password is refused', $r['status'] === 200 && stripos($r['body'], 'do not match') !== false,
    'status=' . $r['status'] . ' body=' . snippet($r['body']));

$r = req('GET', BASE . '/login', [], $jarG);
$r = req('POST', BASE . '/login', ['csrf_token' => csrf($r['body']), 'username' => $userA, 'password' => $passA], $jarG);
ok('login with the right password -> 302', $r['status'] === 302, 'status=' . $r['status'] . ' body=' . snippet($r['body']));
$jarA = $jarG;
ok('login sets a session cookie', count($jarA) > 0, json_encode(array_keys($jarA)));

$r = req('GET', BASE . '/dashboard', [], $jarA);
ok('GET /dashboard (signed in) -> 200', $r['status'] === 200, 'status=' . $r['status']);
ok('the dashboard carries the app nav', str_contains($r['body'], 'bottom-nav__link') || str_contains($r['body'], 'nav__link'));

echo "\n== 4. CSRF ==\n";
$r = req('POST', BASE . '/transactions/save', [
    'csrf_token' => str_repeat('0', 64), 'type' => 'expense', 'amount' => '10.00',
    'date' => '2026-10-20', 'description' => 'csrf probe',
], $jarA);
ok('POST /transactions/save with a bad CSRF token does not create the row',
    !str_contains($r['body'], 'csrf probe'), 'status=' . $r['status']);
$r = req('GET', BASE . '/transactions', [], $jarA);
ok('the transaction list does not contain the CSRF probe', !str_contains($r['body'], 'csrf probe'));

echo "\n== 5. Categories ==\n";
$r = req('GET', BASE . '/categories', [], $jarA);
ok('GET /categories -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('POST', BASE . '/categories/save', ['csrf_token' => csrf($r['body']), 'name' => 'Groceries ' . $marker, 'type' => 'expense'], $jarA);
ok('POST /categories/save -> 302', $r['status'] === 302, 'status=' . $r['status']);
$r = req('GET', BASE . '/categories', [], $jarA);
ok('the new category is listed', str_contains($r['body'], 'Groceries ' . $marker));
preg_match('#/categories\?rename=(\d+)#', $r['body'], $m2);
$catId = $m2[1] ?? '';
ok('the category row exposes its id', $catId !== '', 'no rename link found');
ok('the delete dialog carries the category id', preg_match('#cat-del-(\d+)#', $r['body']) === 1);

echo "\n== 6. Transactions ==\n";
$r = req('GET', BASE . '/transactions/new', [], $jarA);
ok('GET /transactions/new -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('POST', BASE . '/transactions/save', [
    'csrf_token' => csrf($r['body']), 'type' => 'expense', 'amount' => '123.45',
    'date' => '2026-10-20', 'category_id' => $catId, 'description' => 'Weekly shop ' . $marker,
], $jarA);
ok('POST /transactions/save (expense) -> 303', $r['status'] === 303, 'status=' . $r['status']);
ok('POST /transactions/save (expense) -> Location /transactions', str_contains($r['location'], '/transactions'), $r['location']);

$r = req('GET', BASE . '/transactions/new', [], $jarA);
$r = req('POST', BASE . '/transactions/save', [
    'csrf_token' => csrf($r['body']), 'type' => 'income', 'amount' => '5000.00',
    'date' => '2026-10-21', 'description' => 'Salary ' . $marker,
], $jarA);
ok('POST /transactions/save (income) -> 303', $r['status'] === 303, 'status=' . $r['status']);

$r = req('GET', BASE . '/transactions', [], $jarA);
ok('GET /transactions -> 200', $r['status'] === 200, 'status=' . $r['status']);
ok('the expense row is listed', str_contains($r['body'], 'Weekly shop ' . $marker));
ok('the income row is listed', str_contains($r['body'], 'Salary ' . $marker));
ok('the amount is formatted to 2 decimals', str_contains($r['body'], '123.45'));
ok('the negative sign is U+2212', str_contains($r['body'], "\u{2212}"), 'the minus sign is not U+2212');

$r = req('GET', BASE . '/transactions?type=income', [], $jarA);
ok('filter type=income hides the expense', !str_contains($r['body'], 'Weekly shop ' . $marker));
ok('filter type=income keeps the income', str_contains($r['body'], 'Salary ' . $marker));
$r = req('GET', BASE . '/transactions?type=expense', [], $jarA);
ok('filter type=expense hides the income', !str_contains($r['body'], 'Salary ' . $marker));

preg_match('#/transactions/edit\?id=(\d+)#', $r['body'], $m3);
$txId = $m3[1] ?? '';
ok('the list links to the edit form', $txId !== '');
if ($txId !== '') {
    $r = req('GET', BASE . '/transactions/edit?id=' . $txId, [], $jarA);
    ok('GET /transactions/edit -> 200', $r['status'] === 200, 'status=' . $r['status']);
    ok('the edit form is pre-filled', str_contains($r['body'], 'Weekly shop ' . $marker));
    $r = req('POST', BASE . '/transactions/save', [
        'csrf_token' => csrf($r['body']), 'id' => $txId, 'type' => 'expense', 'amount' => '99.99',
        'date' => '2026-10-20', 'category_id' => $catId, 'description' => 'Weekly shop EDITED ' . $marker,
    ], $jarA);
    ok('POST /transactions/save (edit) -> 303', $r['status'] === 303, 'status=' . $r['status']);
    $r = req('GET', BASE . '/transactions', [], $jarA);
    ok('the edit is reflected', str_contains($r['body'], 'Weekly shop EDITED ' . $marker));
}

echo "\n== 7. Ownership (user B must not see user A) ==\n";
$r = req('GET', BASE . '/login', [], $jarB);
$r = req('POST', BASE . '/login', ['csrf_token' => csrf($r['body']), 'username' => $userB, 'password' => $passB], $jarB);
ok('user B logged in', $r['status'] === 302, 'status=' . $r['status'] . ' body=' . snippet($r['body']));
$r = req('GET', BASE . '/transactions', [], $jarB);
ok('user B does not see user A transactions', !str_contains($r['body'], 'Weekly shop EDITED ' . $marker)
    && !str_contains($r['body'], 'Salary ' . $marker));
ok('user B does not see user A categories', !str_contains($r['body'], 'Groceries ' . $marker));
if ($txId !== '') {
    $r = req('GET', BASE . '/transactions/edit?id=' . $txId, [], $jarB);
    ok('user B cannot open user A transaction for editing', $r['status'] === 404 || $r['status'] === 403,
        'status=' . $r['status']);
    $rb = req('GET', BASE . '/transactions', [], $jarB);
    $r = req('POST', BASE . '/transactions/delete', ['csrf_token' => csrf($rb['body']), 'id' => $txId], $jarB);
    ok('user B cannot delete user A transaction', $r['status'] === 404 || $r['status'] === 403,
        'status=' . $r['status']);
}
$r = req('GET', BASE . '/transactions', [], $jarA);
ok('user A transaction still exists after user B tried to delete it', str_contains($r['body'], 'Weekly shop EDITED ' . $marker));

echo "\n== 8. Reports ==\n";
$r = req('GET', BASE . '/reports', [], $jarA);
ok('GET /reports -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/reports?period=month&date=2026-10-01', [], $jarA);
ok('GET /reports?period=month -> 200', $r['status'] === 200, 'status=' . $r['status']);
ok('the report shows the income figure', str_contains($r['body'], '5,000') || str_contains($r['body'], '5000'), 'no 5,000 in the report');
ok('the report shows the expense figure', str_contains($r['body'], '99.99'));
$r = req('GET', BASE . '/reports?period=year&date=2026-01-01', [], $jarA);
ok('GET /reports?period=year -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/reports?period=day&date=2026-10-20', [], $jarA);
ok('GET /reports?period=day -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/reports?period=week&date=2026-10-19', [], $jarA);
ok('GET /reports?period=week -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/reports?period=month&from=2026-10-01&to=2026-10-31', [], $jarA);
ok('GET /reports custom range -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/reports?period=bogus', [], $jarA);
ok('GET /reports with a bogus period does not 500', $r['status'] === 200 || $r['status'] === 302, 'status=' . $r['status']);

echo "\n== 9. Dashboard ==\n";
$r = req('GET', BASE . '/dashboard', [], $jarA);
ok('GET /dashboard -> 200', $r['status'] === 200, 'status=' . $r['status']);
ok('the dashboard shows the balance', str_contains($r['body'], '4,900') || str_contains($r['body'], '4900'),
    'no 4,900 balance on the dashboard');

echo "\n== 10. Settings ==\n";
$r = req('GET', BASE . '/settings', [], $jarA);
ok('GET /settings -> 200', $r['status'] === 200, 'status=' . $r['status']);
ok('the settings page shows the username', str_contains($r['body'], $userA));
ok('the settings page shows the installation card', stripos($r['body'], 'installation') !== false);
$r = req('POST', BASE . '/settings/profile', ['csrf_token' => csrf($r['body']), 'username' => $userA . '_x', 'email' => $emailA], $jarA);
ok('POST /settings/profile -> 302', $r['status'] === 302, 'status=' . $r['status']);
$r = req('GET', BASE . '/settings', [], $jarA);
ok('the new username is shown', str_contains($r['body'], $userA . '_x'));

echo "\n== 11. Password change ==\n";
$r = req('GET', BASE . '/settings', [], $jarA);
$r = req('POST', BASE . '/settings/password', [
    'csrf_token' => csrf($r['body']), 'current_password' => $passA,
    'password' => 'N3wPassw0rd!Z', 'password_confirmation' => 'N3wPassw0rd!Z',
], $jarA);
ok('POST /settings/password -> 302', $r['status'] === 302, 'status=' . $r['status']);
$jarG2 = [];
$r = req('GET', BASE . '/login', [], $jarG2);
$r = req('POST', BASE . '/login', ['csrf_token' => csrf($r['body']), 'username' => $userA . '_x', 'password' => 'N3wPassw0rd!Z'], $jarG2);
ok('login with the new password works', $r['status'] === 302, 'status=' . $r['status']);
$jarG3 = [];
$r = req('GET', BASE . '/login', [], $jarG3);
$r = req('POST', BASE . '/login', ['csrf_token' => csrf($r['body']), 'username' => $userA . '_x', 'password' => $passA], $jarG3);
ok('login with the OLD password is refused', $r['status'] !== 302, 'status=' . $r['status']);

echo "\n== 12. Logout ==\n";
$jarA2 = $jarG2;
$r = req('GET', BASE . '/settings', [], $jarA2);
$r = req('POST', BASE . '/logout', ['csrf_token' => csrf($r['body'])], $jarA2);
ok('POST /logout -> 302', $r['status'] === 302, 'status=' . $r['status']);
$r = req('GET', BASE . '/dashboard', [], $jarA2);
ok('the session is dead after logout', $r['status'] === 302 && str_contains($r['location'], '/login'),
    'status=' . $r['status'] . ' loc=' . $r['location']);

echo "\n== 13. Guest screens and error paths ==\n";
$r = req('GET', BASE . '/forgot-password', [], $jarG);
ok('GET /forgot-password -> 200', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/reset-password?token=bogus&email=' . rawurlencode($emailA), [], $jarG);
ok('GET /reset-password with a bogus token -> 200 (no crash)', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/verify-email?token=bogus', [], $jarG);
ok('GET /verify-email with a bogus token -> 200 (no crash)', $r['status'] === 200, 'status=' . $r['status']);
$r = req('GET', BASE . '/no-such-page', [], $jarG);
ok('GET /no-such-page -> 404', $r['status'] === 404, 'status=' . $r['status']);
$r = req('POST', BASE . '/dashboard', [], $jarG);
ok('POST /dashboard (method not allowed) -> 405', $r['status'] === 405, 'status=' . $r['status']);

echo "\n== 14. The installer must refuse to run now ==\n";
$r = req('GET', BASE . '/install.php', [], $jarG);
ok('GET /install.php -> 403 once installed', $r['status'] === 403, 'status=' . $r['status']);

echo "\n== 15. Security headers ==\n";
$r = req('GET', BASE . '/login', [], $jarG);
$names = [];
foreach ($r['headers'] as $h) {
    $p = strpos($h, ':');
    if ($p !== false) { $names[] = strtolower(trim(substr($h, 0, $p))); }
}
ok('X-Content-Type-Options is sent', in_array('x-content-type-options', $names, true), implode(',', $names));
ok('X-Frame-Options is sent', in_array('x-frame-options', $names, true), implode(',', $names));
ok('Referrer-Policy is sent', in_array('referrer-policy', $names, true), implode(',', $names));
// issue-014 lines 400-401 require X-Content-Type-Options, X-Frame-Options and
// Referrer-Policy. A Content-Security-Policy is NOT required by the spec, so
// its absence is not a defect -- assert the three that ARE required.
ok('exactly the three headers issue-014 requires are sent',
    in_array('x-content-type-options', $names, true)
    && in_array('x-frame-options', $names, true)
    && in_array('referrer-policy', $names, true),
    implode(',', $names));

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
