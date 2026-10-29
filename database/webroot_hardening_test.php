<?php
/**
 * webroot_hardening_test.php — guards the web-root deny rules in .htaccess.
 *
 * The project is mounted at the document root so that the application lives at
 * "/". That makes every file in the repository reachable over HTTP unless the
 * web server denies it. Before the deny block existed, /.env handed out the
 * MariaDB root password and /.git/config handed out the repository metadata.
 *
 * The rules live in .htaccess (issue-003.md). This test is the regression net
 * that stops them rotting: it asserts the HTTP status codes that the rules are
 * supposed to produce, and it asserts that the rules are still written down in
 * the file, so a wholesale replacement of .htaccess is caught even if the
 * running server still has the old rules cached.
 *
 * Issue-023 extended the deny block: the deployment topology
 * (docker-compose.yml), the tracked twin of the configuration
 * (config.template.php) and the internal documentation (AGENTS.md and
 * everything under docs/ except the mockup deliverable) are denied now. The
 * test used to ASSERT that those paths were reachable — it pinned the leak in
 * place — so the lists and the liveness probe changed with the rules.
 *
 * Section F covers the other half of the issue: the compose file itself must
 * bind the admin console to loopback and must not float an image tag.
 *
 * It is a structural + live-HTTP test: no database is needed.
 *
 * Run:  docker compose exec -T app php database/webroot_hardening_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by the
 * output — and never treat an unreachable stack as a pass.
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
 * Perform a GET and return the status code plus the body.
 *
 * ignore_errors is on, so a 403 does not raise a warning — the deny rules are
 * the expected result, not an error condition.
 */
function http_get(string $url, int $timeout = 10): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'         => 'GET',
            'timeout'        => $timeout,
            'ignore_errors'  => true,
            'follow_location'=> 0,
            'header'         => "User-Agent: financial-tracker-webroot-test\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $status = null;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'body' => $body === false ? null : $body];
}

/**
 * Strip the comments out of a YAML source.
 *
 * The compose file explains itself in comments, and the comments legitimately
 * mention "latest" and the port variables. A scan that read the raw file
 * would find those and report a floating tag that is only mentioned in a
 * comment — so the comments come off before anything is asserted.
 *
 * A '#' starts a comment when it is at the start of the line or preceded by
 * whitespace. That is the YAML rule.
 */
function strip_yaml_comments(string $yaml): string
{
    $lines = preg_split('/\R/', $yaml) ?: [];
    $kept  = [];

    foreach ($lines as $line) {
        $kept[] = preg_replace('/(^|\s)#.*$/', '$1', $line);
    }

    return implode("\n", $kept);
}

/**
 * The base URL of the application under test.
 *
 * Inside the app container the vhost answers on 127.0.0.1:80. On the host the
 * port comes from .env, so the test follows the configuration instead of
 * hard-coding a port. WEBROOT_BASE_URL overrides both.
 */
function resolve_base_url(): string
{
    $override = getenv('WEBROOT_BASE_URL');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    // LiteSpeed is installed only inside the app image.
    if (is_dir('/usr/local/lsws')) {
        return 'http://127.0.0.1';
    }

    $port = '8088';
    $envFile = dirname(__DIR__) . '/.env';
    if (is_file($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^APP_PORT\s*=\s*"?(\d+)"?/', $line, $m)) {
                $port = $m[1];
                break;
            }
        }
    }

    return 'http://localhost:' . $port;
}

/**
 * Is the application answering?
 *
 * The marker matters: another stack may already own the port, and a 200 from
 * the wrong application is worse than no answer at all.
 *
 * The probe path is /docs/mockup/index.html — a path that MUST stay public,
 * because it is the UX deliverable. It used to be /AGENTS.md, which is now
 * denied (issue-023): a probe on a denied path reports a running application
 * as down, and the operator is told to start a stack that is already up.
 */
function app_answers(string $baseUrl): bool
{
    $res = http_get($baseUrl . '/docs/mockup/index.html');
    return $res['status'] === 200
        && is_string($res['body'])
        && strpos($res['body'], 'Financial Tracker') !== false;
}

/**
 * Wait for the application to answer.
 *
 * LiteSpeed needs a moment after the container starts before it serves
 * requests, and a test that starts the stack and immediately probes it would
 * fail on a race rather than on a real defect. Always poll before giving up.
 */
function wait_for_app(string $baseUrl, int $attempts = 30, int $delayUs = 1_000_000): bool
{
    for ($i = 0; $i < $attempts; $i++) {
        if (app_answers($baseUrl)) {
            return true;
        }
        usleep($delayUs);
    }
    return false;
}

/**
 * Bring the stack up if it is not running, then wait for it.
 *
 * Starting the stack is only possible on the host — the app image has no
 * docker client. When the test runs inside the app container (the usual case
 * via run-tests.sh) the stack is already up, so this only has to wait.
 *
 * Returns false when the application cannot be reached. The caller treats that
 * as a FAILURE, never as a skip: a hardening test that cannot reach the
 * server has proved nothing.
 */
function ensure_stack_up(string $baseUrl): bool
{
    if (app_answers($baseUrl)) {
        return true;
    }

    $docker = trim((string) @shell_exec('command -v docker 2>/dev/null'));
    if ($docker !== '') {
        $root = dirname(__DIR__);
        @shell_exec(
            'cd ' . escapeshellarg($root) . ' && docker compose up -d app >/dev/null 2>&1'
        );
    }

    return wait_for_app($baseUrl);
}

$root   = realpath(__DIR__ . '/..');
$baseUrl = resolve_base_url();

echo "## A. The stack is reachable\n";

if (!ensure_stack_up($baseUrl)) {
    fwrite(STDERR, "The application is not answering at {$baseUrl}.\n");
    fwrite(STDERR, "Start it with `docker compose up -d` and run this test again.\n");
    echo "  [FAIL] the application answers at {$baseUrl}\n";
    echo "\nFailed: " . count($failures) . "\n";
    exit(1);
}

ok("the application answers at {$baseUrl}", true);

echo "\n## B. Secrets and internal trees are denied (403)\n";

// Every entry is a path that must never be served. The list covers the deny
// rules in .htaccess, not just the files that happen to exist today — a rule
// for a directory that does not exist yet must still fire.
$denied = [
    '/.env',
    '/.env.example',
    '/.git/config',
    '/.gitignore',
    '/.htaccess',
    '/config.php',
    '/config.template.php',
    '/docker-compose.yml',
    '/AGENTS.md',
    '/database/',
    '/database/config_test.php',
    '/database/css_cascade.php',
    '/docs/',
    '/docs/About.md',
    '/docs/issues/',
    '/docs/issues/issue-001.md',
    '/docker/',
    '/docker/entrypoint.sh',
    '/includes/',
    '/views/',
    '/views/login.php',
    '/vendor/',
    '/vendor/autoload.php',
    '/composer.json',
    '/composer.lock',
];

$notDenied = [];
foreach ($denied as $path) {
    $res = http_get($baseUrl . $path);
    ok(
        "GET {$path} -> 403",
        $res['status'] === 403,
        'got ' . ($res['status'] === null ? 'no response' : (string) $res['status'])
    );
    if ($res['status'] !== 403) {
        $notDenied[] = $path;
    }
}

// LiteSpeed keeps the parsed .htaccess in memory. A rule that was just added
// to the file is not enforced until the server re-reads it, so a failure here
// can mean "the file is right but the server is stale" rather than "the file
// is wrong". Say so, instead of leaving the operator to guess.
if (count($notDenied) > 0) {
    echo "  NOTE: the server answered without 403 for: " . implode(', ', $notDenied) . "\n";
    echo "        If the deny rule for those paths is present in .htaccess on disk,\n";
    echo "        the running server is holding a stale copy of it. Restart it:\n";
    echo "          docker compose restart app\n";
}

echo "\n## C. The deliverables stay reachable (200)\n";

// The deny list must not be so broad that it breaks the project.
//
// /AGENTS.md and /docs/About.md were on this list until issue-023: they are
// internal documentation and they are now denied, so they moved to the
// denied list above. The mockup deliverable and the application's own
// assets stay public — they are what the site IS.
$public = [
    '/docs/mockup/',
    '/docs/mockup/index.html',
    '/docs/mockup/assets/tokens.css',
    '/assets/css/tokens.css',
];

foreach ($public as $path) {
    $res = http_get($baseUrl . $path);
    ok(
        "GET {$path} -> 200",
        $res['status'] === 200,
        'got ' . ($res['status'] === null ? 'no response' : (string) $res['status'])
    );
}

// The application itself must still answer: the bare root redirects to the
// dashboard, and the sign-in screen is public.
$res = http_get($baseUrl . '/');
ok(
    'GET / -> 302',
    $res['status'] === 302,
    'got ' . ($res['status'] === null ? 'no response' : (string) $res['status'])
);

$res = http_get($baseUrl . '/login');
ok(
    'GET /login -> 200',
    $res['status'] === 200,
    'got ' . ($res['status'] === null ? 'no response' : (string) $res['status'])
);

echo "\n## D. No PHP source is served by any path\n";

// A PHP file that is denied must be refused, and a PHP file that is an entry
// point must be EXECUTED — never handed out as source. Before issue-023 the
// subject of this section was /config.template.php, which was public and
// therefore had to be proven to be executed rather than leaked. It is denied
// now, so the entry points carry the assertion instead.
$res = http_get($baseUrl . '/index.php');
ok(
    'GET /index.php does not serve PHP source',
    is_string($res['body']) && strpos($res['body'], '<?php') === false,
    'the response body contains the raw source'
);

$res = http_get($baseUrl . '/install.php');
ok(
    'GET /install.php does not serve PHP source',
    is_string($res['body']) && strpos($res['body'], '<?php') === false,
    'the response body contains the raw source'
);

// Every denied path must be refused with a body that is not source either.
foreach (['/config.template.php', '/docker-compose.yml', '/AGENTS.md', '/docs/About.md'] as $path) {
    $res = http_get($baseUrl . $path);
    ok(
        "GET {$path} does not serve PHP source",
        is_string($res['body']) && strpos($res['body'], '<?php') === false,
        'the response body contains the raw source'
    );
}

echo "\n## E. The deny rules are still written down\n";

// A live server keeps serving the rules it loaded at start-up, so a green
// section B does not prove the file on disk is intact. Assert on the rule
// bodies, not on the mere presence of a selector string.
$htaccess = $root . '/.htaccess';
ok('.htaccess exists in the document root', is_file($htaccess), $htaccess);

if (is_file($htaccess)) {
    $src = file_get_contents($htaccess);

    // Collect the RewriteRule directives. Comment lines are skipped by the
    // regex itself (they start with '#'), so a rule mentioned in a comment is
    // never mistaken for a rule.
    //
    //   RewriteRule <pattern> <substitution|-> <flags>
    //
    // A deny rule has "-" as its substitution: nothing is substituted, the
    // flags alone decide the outcome. The future front-controller rules have a
    // real substitution ("/index.php") and are therefore not deny rules.
    $rules = [];
    foreach (preg_split('/\R/', $src) as $line) {
        if (preg_match('/^\s*RewriteRule\s+("[^"]*"|\S+)\s+(\S+)(.*)$/', $line, $m)) {
            $pattern = trim($m[1], '"');
            $rules[] = [
                'pattern' => $pattern,
                'substitution' => $m[2],
                'flags' => trim($m[3]),
                'deny' => $m[2] === '-',
            ];
        }
    }

    ok('the file contains RewriteRule directives', count($rules) > 0, (string) count($rules));

    $denyRules = array_values(array_filter($rules, static function (array $r): bool {
        return $r['deny'];
    }));

    ok('the file contains deny rules', count($denyRules) > 0, (string) count($denyRules));

    // pattern fragment => human label. The fragments are plain substrings of
    // the unquoted pattern, so they are compared verbatim.
    $expected = [
        '(?!well-known)' => 'dotfiles are denied',
        'config\.php'    => 'config.php is denied',
        'config\.template\.php' => 'config.template.php is denied',
        'database/'      => 'database/ is denied',
        'includes/'      => 'includes/ is denied',
        'views/'         => 'views/ is denied',
        'docker/'        => 'docker/ is denied',
        'vendor/'        => 'vendor/ is denied',
        'docs/issues/'   => 'docs/issues/ is denied',
        'docs/(?!mockup' => 'the internal documentation is denied, except the mockup',
        'docker-compose' => 'the compose file is denied',
        'AGENTS\.md'     => 'the agent guide is denied',
        'composer\.(json|lock)' => 'the dependency manifest and lock file are denied',
    ];

    foreach ($expected as $fragment => $label) {
        $found = false;
        foreach ($denyRules as $rule) {
            if (strpos($rule['pattern'], $fragment) !== false) {
                $found = true;
                break;
            }
        }
        ok($label, $found, 'no deny rule matches ' . $fragment);
    }

    // Every deny rule must carry the forbidden flag. [F] is what turns a match
    // into a 403; without it the rule only stops the rewrite and the file is
    // still served.
    $missingFlag = [];
    foreach ($denyRules as $rule) {
        if (strpos($rule['flags'], '[F') === false) {
            $missingFlag[] = $rule['pattern'];
        }
    }

    ok(
        'every deny rule carries the [F] flag',
        count($missingFlag) === 0,
        'rules without [F]: ' . implode(', ', $missingFlag)
    );

    // The dotfile rule must not swallow .well-known/ — it is a standard public
    // path (ACME challenges, security.txt).
    $dotRule = '';
    foreach ($denyRules as $rule) {
        if (strpos($rule['pattern'], '(?!well-known)') !== false) {
            $dotRule = $rule['pattern'];
            break;
        }
    }
    ok(
        'the dotfile rule exempts .well-known/',
        $dotRule !== '' && strpos($dotRule, '(?!well-known)') !== false,
        'pattern=' . $dotRule
    );
}

echo "\n## F. The compose file does not publish the topology\n";

// The web root is only one of the two places the deployment topology is
// disclosed. The other is the compose file itself, which publishes the
// default credential set of the whole stack. The deny block hides the file
// from HTTP; these assertions cover the two properties the file must have on
// its own — the admin console is bound to loopback, and no image tag floats.
$compose = $root . '/docker-compose.yml';
ok('docker-compose.yml exists in the document root', is_file($compose), $compose);

if (is_file($compose)) {
    $yaml = strip_yaml_comments((string) file_get_contents($compose));

    // The WebAdmin is an admin surface: it can read the vhost configuration
    // and the logs, and it can restart the server. It must not answer on the
    // network — and the default credentials for it are published in this very
    // file.
    //
    // The binding is found by the variable, not by its position, so the check
    // survives a reordering of the ports block.
    $adminBindings = [];
    foreach (preg_split('/\R/', $yaml) as $line) {
        if (strpos($line, 'OLS_ADMIN_PORT') !== false && preg_match('/^\s*-\s/', $line) === 1) {
            $adminBindings[] = trim($line);
        }
    }

    ok(
        'the compose file binds the WebAdmin console exactly once',
        count($adminBindings) === 1,
        'bindings: ' . implode(' | ', $adminBindings)
    );

    foreach ($adminBindings as $binding) {
        ok(
            "the WebAdmin binding is loopback-only ({$binding})",
            strpos($binding, '127.0.0.1:') !== false,
            'a port binding without a host address answers on every interface'
        );
    }

    // A floating tag is a supply-chain risk: "latest" and "alpine" both move
    // under the repository, and the difference is never reviewed.
    $images = [];
    foreach (preg_split('/\R/', $yaml) as $line) {
        if (preg_match('/^\s*image:\s*(\S+)\s*$/', $line, $m)) {
            $images[] = $m[1];
        }
    }

    ok('the compose file declares its images', count($images) > 0, (string) count($images));

    foreach ($images as $image) {
        $isDigest = preg_match('/@sha256:[0-9a-f]{64}$/', $image) === 1;
        // A version tag carries a number. "latest" and "alpine" do not.
        $isVersioned = preg_match('/:[^:\s]*\d[^:\s]*$/', $image) === 1;

        ok(
            "the image {$image} is pinned",
            $isDigest || $isVersioned,
            'a floating tag can move under the repository'
        );
    }
}

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
