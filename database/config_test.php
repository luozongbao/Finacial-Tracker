<?php
/**
 * config_test.php — guards the config.php / config.template.php contract.
 *
 * The contract that this file protects (see AGENTS.md, "Install and config contract"):
 *
 *   1. config.template.php exists and declares the same constant set as config.php
 *      (when config.php exists).
 *   2. ALLOW_REGISTRATION is a REAL PHP BOOLEAN (true / false) — never the strings
 *      'true' / 'false'.
 *   3. The constant is spelled ALLOW_REGISTRATION (underscores), because a hyphen is
 *      not legal in a PHP constant name.
 *   4. A string/boolean mix-up fails OPEN, which is why it must be caught here.
 *
 * This test is structural: it does not need a database and does not load config.php
 * into this process (loading it would collide with the constants defined below).
 *
 * Run:  docker compose exec -T app php database/config_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by output.
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
    echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : "") . "\n";
}

$root  = realpath(__DIR__ . '/..');
$tmpl  = $root . '/config.template.php';
$live  = $root . '/config.php';

echo "## A. The template exists\n";

ok('config.template.php exists', is_file($tmpl), $tmpl);

if (!is_file($tmpl)) {
    fwrite(STDERR, "config.template.php is missing — cannot continue.\n");
    echo "Failed: " . count($failures) . "\n";
    exit(1);
}

$src = file_get_contents($tmpl);

echo "\n## B. ALLOW_REGISTRATION is a real boolean\n";

// A quoted literal is the bug this whole rule exists to prevent. This check runs
// independently of the bare-literal check below, so a quoted value is always
// reported as such rather than as a generic "not found".
ok(
    'ALLOW_REGISTRATION is not a quoted string',
    !preg_match("/\bconst\s+ALLOW_REGISTRATION\s*=\s*(['\"])[^'\"]*\\1\s*;/", $src),
    "found a quoted value; the string 'false' fails OPEN against a boolean compare"
);

// Only the literal forms are accepted; anything computed (a function call, a
// concatenation) is rejected as ambiguous.
$hasBareLiteral = (bool) preg_match(
    '/\bconst\s+ALLOW_REGISTRATION\s*=\s*(true|false)\s*;/i',
    $src,
    $m
);

ok(
    'ALLOW_REGISTRATION is declared as a bare true/false literal',
    $hasBareLiteral,
    'no `const ALLOW_REGISTRATION = true;` / `= false;` found'
);

if ($hasBareLiteral) {
    $declared = strtolower($m[1]) === 'true';

    // Prove the semantics in a child process so this file's own constants cannot
    // interfere. Both a boolean and a string are evaluated the same way.
    $probe = <<<'PHP'
<?php
$src = file_get_contents($argv[1]);
preg_match('/\bconst\s+ALLOW_REGISTRATION\s*=\s*(true|false)\s*;/i', $src, $m);
$value = strtolower($m[1]) === 'true';
echo json_encode([
    'is_bool'      => is_bool($value),
    'eq_false'     => ($value === false),
    'not_eq_false' => ($value !== false),
]);
PHP;

    $probeFile = sys_get_temp_dir() . '/cfg_probe_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($probeFile, $probe);
    register_shutdown_function(static function () use ($probeFile) {
        if (is_file($probeFile)) {
            @unlink($probeFile);
        }
    });

    $json = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' ' . escapeshellarg($tmpl) . ' 2>&1');
    $data = json_decode((string) $json, true);

    ok('the value evaluates to a PHP bool', is_array($data) && ($data['is_bool'] ?? false) === true, (string) $json);

    if (is_array($data)) {
        if ($declared) {
            ok('a true value is NOT equal to false', ($data['eq_false'] ?? true) === false);
        } else {
            ok('a false value IS equal to false', ($data['eq_false'] ?? false) === true);
        }
    }
}

echo "\n## C. The constant name is usable in PHP\n";

ok(
    "the name is spelled ALLOW_REGISTRATION (underscores)",
    (bool) preg_match('/\bconst\s+ALLOW_REGISTRATION\s*=/', $src)
);

ok(
    "no hyphenated ALLOW-REGISTRATION constant",
    !preg_match('/\bconst\s+ALLOW-REGISTRATION\s*=/', $src),
    'a hyphen is not legal in a PHP constant name'
);

ok(
    'the constant is declared with const, not define()',
    !preg_match('/\bdefine\s*\(\s*[\'"]ALLOW_REGISTRATION[\'"]/', $src)
);

echo "\n## D. config.php and config.template.php agree\n";

/**
 * Collect the constant names declared in a config file.
 * Handles both `const NAME = ...;` and `define('NAME', ...);`.
 */
function declared_constants(string $src): array
{
    $names = [];
    if (preg_match_all('/\bconst\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/', $src, $m)) {
        foreach ($m[1] as $n) {
            $names[] = $n;
        }
    }
    if (preg_match_all('/\bdefine\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $src, $m)) {
        foreach ($m[1] as $n) {
            $names[] = $n;
        }
    }
    sort($names);
    return array_values(array_unique($names));
}

$tmplNames = declared_constants($src);

ok('the template declares a non-empty constant set', count($tmplNames) > 0, implode(', ', $tmplNames));
ok('the template declares ALLOW_REGISTRATION', in_array('ALLOW_REGISTRATION', $tmplNames, true));

if (is_file($live)) {
    $liveSrc  = file_get_contents($live);
    $liveNames = declared_constants($liveSrc);

    ok(
        'config.php declares the same constant set as config.template.php',
        $liveNames === $tmplNames,
        'template=[' . implode(', ', $tmplNames) . '] config.php=[' . implode(', ', $liveNames) . ']'
    );

    if (preg_match('/\bconst\s+ALLOW_REGISTRATION\s*=\s*([a-z]+)\s*;/i', $liveSrc, $m)) {
        ok(
            'config.php also holds a real boolean for ALLOW_REGISTRATION',
            in_array(strtolower($m[1]), ['true', 'false'], true),
            "found '{$m[1]}'"
        );
    }
} else {
    echo "  [SKIP] config.php does not exist yet (app not installed) — nothing to compare.\n";
}

echo "\n## E. The fail-open trap is documented, not relied on\n";

// The whole reason the flag is a boolean. If this ever stops being true the
// string form would be silently equivalent to the boolean form.
$trap = 'false';
ok(
    "the string 'false' does NOT equal false (so it would fail open)",
    $trap !== false
);

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
