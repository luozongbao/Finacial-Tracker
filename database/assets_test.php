<?php
/**
 * assets_test.php — guards the design-token layer and the shared shell.
 *
 * The contract that this file protects (docs/issues/issue-013.md):
 *
 *   1. The three CSS layers are the mockup's files, carried over unchanged
 *      apart from the path in the header comment. The reference is the
 *      specification — a token renamed in the application renders the
 *      component unstyled, and the failure is silent.
 *   2. tokens.css holds :root custom properties only, and carries the four
 *      theme blocks in the order that makes the explicit choice win over the
 *      system preference.
 *   3. The stylesheets load in the order tokens -> base -> components ->
 *      pages/<page>. The order is load-bearing.
 *   4. The shell escapes every dynamic value, renders no inline <script>,
 *      and the guest shell renders no navigation at all.
 *   5. assets/js/app.js holds every behaviour, reads user data from dataset
 *      only, and wraps every localStorage access in a try/catch.
 *
 * This test is structural: it needs no database. The shell is rendered in a
 * child process, so the assertions are made against real HTML rather than
 * against the source of a template.
 *
 * Run:  docker compose exec -T app php database/assets_test.php
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

$root = realpath(__DIR__ . '/..');

$assets = $root . '/assets';
$refDir = $root . '/docs/mockup/assets';

$tokensFile     = $assets . '/css/tokens.css';
$baseFile       = $assets . '/css/base.css';
$componentsFile = $assets . '/css/components.css';
$appJsFile      = $assets . '/js/app.js';
$headerFile     = $root . '/includes/header.php';
$footerFile     = $root . '/includes/footer.php';

echo "## A. The files exist\n";

foreach ([
    'assets/css/tokens.css'     => $tokensFile,
    'assets/css/base.css'       => $baseFile,
    'assets/css/components.css' => $componentsFile,
    'assets/js/app.js'          => $appJsFile,
    'includes/header.php'       => $headerFile,
    'includes/footer.php'       => $footerFile,
] as $label => $path) {
    ok($label . ' exists', is_file($path), $path);
}

ok('assets/css/pages/ exists', is_dir($assets . '/css/pages'));
ok('assets/img/ exists', is_dir($assets . '/img'));

$missing = [];
foreach ([$tokensFile, $baseFile, $componentsFile, $appJsFile, $headerFile, $footerFile] as $path) {
    if (!is_file($path)) {
        $missing[] = $path;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "the asset layer is incomplete — cannot continue.\n");
    echo "Failed: " . count($failures) . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// A helper: strip a CSS/JS comment so a prose mention is not counted as code.
// ---------------------------------------------------------------------------

/**
 * Remove /* ... *\/ and // comments from a source string.
 *
 * The sources guarded here carry no regular-expression literal and no URL,
 * so a line comment runs to the end of its line and nothing else.
 */
function strip_comments(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
    $src = preg_replace('#(^|\s)//[^\n]*#', '$1', $src) ?? $src;

    return $src;
}

/**
 * The custom-property names declared in a stylesheet, in order.
 *
 * @return string[]
 */
function declared_tokens(string $css): array
{
    preg_match_all('/(--[a-z0-9-]+)\s*:/i', $css, $m);

    return array_values(array_unique($m[1]));
}

echo "\n## B. The token inventory is the reference's\n";

$appTokens = file_get_contents($tokensFile);
$refTokens = is_file($refDir . '/tokens.css') ? file_get_contents($refDir . '/tokens.css') : '';

$appNames = declared_tokens($appTokens);
$refNames = declared_tokens($refTokens);

ok('the reference tokens.css was found', $refNames !== [], $refDir . '/tokens.css');

$missingTokens = array_diff($refNames, $appNames);
$extraTokens   = array_diff($appNames, $refNames);

ok(
    'no token from the reference is missing',
    $missingTokens === [],
    implode(', ', $missingTokens)
);
ok(
    'no token of its own was invented',
    $extraTokens === [],
    implode(', ', $extraTokens)
);
ok(
    'the tokens are declared in the reference order',
    $appNames === $refNames,
    'application=[' . implode(' ', $appNames) . '] reference=[' . implode(' ', $refNames) . ']'
);

ok('the spacing scale stops at --sp-8', in_array('--sp-8', $appNames, true));
ok('there is no --sp-7 (the scale skips it)', !in_array('--sp-7', $appNames, true));

echo "\n## C. tokens.css holds :root only\n";

$bare = strip_comments($appTokens);
preg_match_all('/([^{}]+)\{/', $bare, $m);

$allowed = [':root', ':root[data-theme="dark"]', ':root[data-theme="light"]'];
$selectors = [];
$mediaQueries = 0;

foreach ($m[1] as $selector) {
    $selector = trim($selector);
    if ($selector === '') {
        continue;
    }
    if (str_starts_with($selector, '@media')) {
        $mediaQueries++;
        continue;
    }
    $selectors[] = $selector;
}

$uniqueSelectors = array_values(array_unique($selectors));
$offenders = array_values(array_diff($uniqueSelectors, $allowed));

ok('every selector is a :root', $offenders === [], implode(' | ', $offenders));
ok(
    'the selector set is exactly the three :root forms',
    $uniqueSelectors === $allowed,
    implode(' | ', $uniqueSelectors)
);
ok('the dark media query is present', $mediaQueries === 1, 'found ' . $mediaQueries);

echo "\n## D. The four theme blocks, in order\n";

$markers = [
    ':root {',
    '@media (prefers-color-scheme: dark)',
    ':root[data-theme="dark"]',
    ':root[data-theme="light"]',
];

$positions = [];
foreach ($markers as $marker) {
    $positions[$marker] = strpos($appTokens, $marker);
}

$missingBlocks = [];
foreach ($positions as $marker => $position) {
    if ($position === false) {
        $missingBlocks[] = $marker;
    }
}

ok('every theme block is present', $missingBlocks === [], implode(' | ', $missingBlocks));

if ($missingBlocks === []) {
    $ordered = $positions[$markers[0]] < $positions[$markers[1]]
        && $positions[$markers[1]] < $positions[$markers[2]]
        && $positions[$markers[2]] < $positions[$markers[3]];

    ok(
        'the blocks run :root -> media -> explicit dark -> explicit light',
        $ordered,
        implode(' > ', array_map(static fn ($m) => (string) $positions[$m], $markers))
    );
}

echo "\n## E. The layers were carried over, not rewritten\n";

foreach ([
    'tokens.css'     => 'tokens.css',
    'base.css'       => 'base.css',
    'components.css' => 'components.css',
] as $appName => $refName) {
    $appSrc  = file_get_contents($assets . '/css/' . $appName);
    $refPath = $refDir . '/' . $refName;

    if (!is_file($refPath)) {
        ok($appName . ' — the reference file exists', false, $refPath);
        continue;
    }

    $refSrc = file_get_contents($refPath);
    $appLines = explode("\n", $appSrc);
    $refLines = explode("\n", $refSrc);

    // Line 2 of each file names the file itself; that is the one line the
    // carry-over is allowed to change.
    $appBody = array_slice($appLines, 2);
    $refBody = array_slice($refLines, 2);

    ok(
        $appName . ' is identical to the reference apart from the header path',
        $appBody === $refBody,
        'the body differs from ' . $refPath
    );
    ok(
        $appName . ' names the application path in its header',
        str_contains($appLines[1] ?? '', 'assets/css/'),
        $appLines[1] ?? ''
    );
}

echo "\n## F. The reference deliverable is untouched\n";

ok(
    'docs/mockup/assets/mockup.js still exists',
    is_file($refDir . '/mockup.js')
);

if (is_file($refDir . '/mockup.js')) {
    ok(
        'the mockup still owns initActiveMarker() — the application must not',
        str_contains((string) file_get_contents($refDir . '/mockup.js'), 'initActiveMarker')
    );
}

ok(
    'the reference tokens.css still names the mockup path',
    str_contains((string) file_get_contents($refDir . '/tokens.css'), 'docs/mockup/assets/tokens.css')
);

echo "\n## G. The base and component layers keep their rules\n";

$baseSrc       = file_get_contents($baseFile);
$componentsSrc = file_get_contents($componentsFile);

ok('base.css clips the horizontal overflow', str_contains($baseSrc, 'overflow-x: clip'));
ok('base.css does not hide the horizontal overflow', !str_contains($baseSrc, 'overflow-x: hidden'));
ok('base.css keeps the focus-visible ring', str_contains($baseSrc, ':focus-visible'));

ok('the 640 px breakpoint is present', str_contains($componentsSrc, 'max-width: 639px'));
ok('the 1024 px breakpoint is present', str_contains($componentsSrc, 'max-width: 1023px'));
ok(
    'the reduced-motion block is present',
    str_contains($componentsSrc, 'prefers-reduced-motion')
);
ok(
    'the table contract reads data-label',
    str_contains($componentsSrc, 'content: attr(data-label)')
);

foreach ([
    '.btn--primary',
    '.alert--danger',
    '.badge--income',
    '.tile--net-zero',
    '.amount--income',
] as $modifier) {
    ok('the double-hyphen modifier ' . $modifier . ' exists', str_contains($componentsSrc, $modifier));
}

// The reference styles the info alert with the base .alert and gives the
// transaction row no colour modifier of its own — the amount carries
// .amount--income / .amount--expense. Both are the reference's decision,
// and the application copies it verbatim rather than inventing the names the
// issue's vocabulary list happens to mention.
ok('the base .alert exists', str_contains($componentsSrc, '.alert {'));
ok(
    'the reference has no .alert--info — the info alert is the base .alert',
    !str_contains($componentsSrc, '.alert--info')
);
ok(
    'the reference gives the transaction row no .tx-- modifier',
    !str_contains($componentsSrc, '.tx--')
);

foreach ([
    '.nav__link[aria-current="page"]',
    '.bottom-nav__link[aria-current="page"]',
    '.period__link[aria-current="true"]',
    '.stepper__item[aria-current="step"]',
] as $activeRule) {
    ok('the active state ' . $activeRule . ' exists', str_contains($componentsSrc, $activeRule));
}

echo "\n## H. The script\n";

$appJs = (string) file_get_contents($appJsFile);
$appJsCode = strip_comments($appJs);

$listeners = preg_match_all("/addEventListener\(\s*['\"]DOMContentLoaded['\"]/", $appJsCode);

ok('there is exactly one DOMContentLoaded listener', $listeners === 1, 'found ' . $listeners);

$registered = preg_match_all('/^\s*init[A-Za-z]+\(\);/m', $appJsCode);
$defined = preg_match_all('/function\s+(init[A-Za-z]+)\s*\(/', $appJsCode, $defs);

ok('every init*() function is registered from that listener', $registered === $defined && $defined > 0,
    'registered=' . $registered . ' defined=' . $defined);

ok(
    'the mockup-only initActiveMarker() was not ported',
    !str_contains($appJsCode, 'initActiveMarker')
);
ok(
    'the theme is stored under the key "theme"',
    str_contains($appJsCode, "'theme'")
);
ok(
    'the mockup storage key was not carried over',
    !str_contains($appJsCode, 'mockup-theme')
);
ok(
    'the toggle sets the explicit attribute',
    str_contains($appJsCode, "setAttribute('data-theme'")
);
ok(
    'the toggle never removes the attribute — the explicit light block would be lost',
    !str_contains($appJsCode, "removeAttribute('data-theme'")
);
ok('no PHP leaked into the script', !str_contains($appJs, '<?php') && !str_contains($appJs, '<?='));

$localStorageUses = substr_count($appJsCode, 'localStorage');
$guardedUses = preg_match_all('/try\s*\{[^{}]*localStorage[^{}]*\}\s*catch\s*\(/', $appJsCode);

ok('the script uses localStorage', $localStorageUses > 0, 'found ' . $localStorageUses);
ok(
    'every localStorage access is inside a try/catch',
    $guardedUses === $localStorageUses,
    'accesses=' . $localStorageUses . ' guarded=' . $guardedUses
);

echo "\n## I. The shell renders the right frame\n";

/**
 * Render the shell in a child process and return the HTML.
 *
 * The child is a separate process because the shell reads the caller's
 * variables, and the assertions below are about the rendered page — not
 * about the template that produced it.
 */
function render_shell(string $root, string $mode): string
{
    $probe = <<<'PHP'
<?php
$root = $argv[1];
$mode = $argv[2];

require $root . '/includes/functions.php';

$title = 'Dashboard';
$active = 'dashboard';
$username = 'demo';

if ($mode === 'auth') {
    $shell = 'auth';
}
if ($mode === 'page') {
    $pageCss = 'dashboard.css';
}
if ($mode === 'dark') {
    $_COOKIE['theme'] = 'dark';
}
if ($mode === 'hostile') {
    $title = '<b>Title</b>';
    $username = '<script>alert(1)</script>';
    $_COOKIE['theme'] = 'dark" onload="alert(1)';
}

include $root . '/includes/header.php';
echo "<!-- page content -->";
include $root . '/includes/footer.php';
PHP;

    $probeFile = sys_get_temp_dir() . '/assets_probe_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($probeFile, $probe);
    register_shutdown_function(static function () use ($probeFile) {
        if (is_file($probeFile)) {
            @unlink($probeFile);
        }
    });

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile)
        . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($mode) . ' 2>&1';

    return (string) shell_exec($command);
}

$appHtml = render_shell($root, 'app');
$authHtml = render_shell($root, 'auth');
$pageHtml = render_shell($root, 'page');
$darkHtml = render_shell($root, 'dark');
$hostileHtml = render_shell($root, 'hostile');

ok('the app shell rendered', str_contains($appHtml, '<!DOCTYPE html>'), substr($appHtml, 0, 200));

ok('the app shell renders the app bar', str_contains($appHtml, 'class="app__bar"'));
ok('the app shell renders the navigation', str_contains($appHtml, '<nav class="nav"'));
ok('the app shell renders the content column', str_contains($appHtml, '<main class="app__main">'));
ok('the app shell renders the footer', str_contains($appHtml, 'class="app__footer"'));
ok('the app shell renders the bottom navigation', str_contains($appHtml, 'class="bottom-nav"'));
ok(
    'the footer carries the product line',
    str_contains($appHtml, 'Financial Tracker · personal use.')
);

// The four navigation links live inside the <nav>. The Settings link is a
// .nav__link too, but it sits outside the navigation, after the spacer.
$navBlock = '';
if (preg_match('#<nav class="nav".*?</nav>#s', $appHtml, $navMatch) === 1) {
    $navBlock = $navMatch[0];
}

preg_match_all('/<a class="nav__link" href="([^"]+)"/', $navBlock, $navMatches);
$navHrefs = $navMatches[1];

ok(
    'the navigation is Dashboard, Transactions, Reports, Categories',
    $navHrefs === ['/dashboard', '/transactions', '/reports', '/categories'],
    implode(', ', $navHrefs)
);
ok(
    'the Settings link is rendered',
    str_contains($appHtml, '<a class="nav__link" href="/settings"')
);
ok(
    'the Settings link sits outside the navigation, after the spacer',
    !str_contains($navBlock, '/settings')
);

ok(
    'the active link carries aria-current="page"',
    str_contains($appHtml, 'href="/dashboard" aria-current="page"')
);

ok(
    'no is-active class is used — the reference knows no such name',
    !str_contains($appHtml, 'is-active')
);

ok(
    'the script tag is external and last in the body',
    preg_match('#<script src="[^"]*app\.js[^"]*"></script>\s*</body>#', $appHtml) === 1
);

ok(
    'the shell carries no inline script',
    preg_match('#<script(?![^>]*\bsrc=)#', $appHtml) !== 1
);

preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $appHtml, $linkMatches);
$links = $linkMatches[1];

ok(
    'the stylesheets load tokens -> base -> components',
    count($links) === 3
    && str_contains($links[0], '/assets/css/tokens.css')
    && str_contains($links[1], '/assets/css/base.css')
    && str_contains($links[2], '/assets/css/components.css'),
    implode(' | ', $links)
);

foreach ($links as $link) {
    ok('the stylesheet ' . $link . ' carries a version stamp', str_contains($link, '?v='));
    ok(
        'the version stamp of ' . $link . ' is the file mtime, not a placeholder',
        !str_ends_with($link, '?v=0'),
        $link
    );
}

ok(
    'the asset URL is root-absolute',
    str_starts_with($links[0], '/assets/css/')
);

preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $pageHtml, $pageLinkMatches);
$pageLinks = $pageLinkMatches[1];

ok(
    'the page stylesheet loads last',
    count($pageLinks) === 4 && str_contains($pageLinks[3], '/assets/css/pages/dashboard.css'),
    implode(' | ', $pageLinks)
);

// The page stylesheet belongs to the screen that needs it (issue-017 owns
// dashboard.css), so it may not exist yet — the stamp is then 0, which is
// what asset_url() says for a file that is not there.
ok(
    'the page stylesheet is linked through asset_url()',
    str_contains($pageLinks[3] ?? '', '?v='),
    $pageLinks[3] ?? ''
);

echo "\n## J. The guest shell\n";

ok('the auth shell rendered', str_contains($authHtml, '<!DOCTYPE html>'), substr($authHtml, 0, 200));
ok('the auth shell renders the narrow column', str_contains($authHtml, '<main class="auth">'));
ok('the auth shell renders the brand', str_contains($authHtml, 'class="auth__brand"'));
ok('the auth shell renders the footer', str_contains($authHtml, 'class="auth__footer"'));
ok(
    'the auth shell carries the product line',
    str_contains($authHtml, 'Personal finance tracker · your data stays on your server.')
);
ok('the auth shell renders no app bar', !str_contains($authHtml, 'class="app__bar"'));
ok('the auth shell renders no navigation', !str_contains($authHtml, 'class="nav__link"'));
ok('the auth shell renders no bottom navigation', !str_contains($authHtml, 'class="bottom-nav"'));

preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $authHtml, $authLinkMatches);
ok(
    'the auth shell loads the same three layers, in the same order',
    count($authLinkMatches[1]) === 3
    && str_contains($authLinkMatches[1][0], 'tokens.css')
    && str_contains($authLinkMatches[1][1], 'base.css')
    && str_contains($authLinkMatches[1][2], 'components.css'),
    implode(' | ', $authLinkMatches[1])
);

echo "\n## K. The theme preference\n";

ok(
    'a valid cookie renders the explicit attribute',
    str_contains($darkHtml, '<html lang="en" data-theme="dark">'),
    substr($darkHtml, 0, 120)
);
ok(
    'no cookie renders no attribute — the system preference applies',
    !str_contains($appHtml, 'data-theme='),
    substr($appHtml, 0, 120)
);

echo "\n## L. Every dynamic value is escaped\n";

ok(
    'the title is escaped',
    str_contains($hostileHtml, '&lt;b&gt;Title&lt;/b&gt;')
);
ok(
    'the user name is escaped',
    str_contains($hostileHtml, '&lt;script&gt;alert(1)&lt;/script&gt;')
);
ok(
    'no raw script tag came from a user value',
    !str_contains($hostileHtml, '<script>alert(1)</script>')
);
ok(
    'a hostile theme cookie is dropped — only dark and light are honoured',
    !str_contains($hostileHtml, 'data-theme='),
    substr($hostileHtml, 0, 200)
);

echo "\n## M. No inline script anywhere in the application\n";

$inline = [];
foreach (glob($root . '/includes/*.php') ?: [] as $phpFile) {
    $src = strip_comments((string) file_get_contents($phpFile));
    if (preg_match('#<script(?![^>]*\bsrc=)#', $src) === 1) {
        $inline[] = basename($phpFile);
    }
}

ok('no inline script block in includes/', $inline === [], implode(', ', $inline));

$headerSrc = strip_comments((string) file_get_contents($headerFile));
$footerSrc = strip_comments((string) file_get_contents($footerFile));

ok(
    'the only script tag is the external one in the footer',
    substr_count($footerSrc, '<script') === 1
    && str_contains($footerSrc, "asset_url('js/app.js')")
);
ok('the header carries no script tag', !str_contains($headerSrc, '<script'));

echo "\n## N. The instruction file\n";

$instructionsFile = $root . '/.github/instructions/frontend.instructions.md';
$instructions = is_file($instructionsFile) ? (string) file_get_contents($instructionsFile) : '';

ok('the instruction file exists', $instructions !== '', $instructionsFile);
ok(
    'the spacing scale reads --sp-1..8',
    str_contains($instructions, '--sp-1..8')
);
ok(
    'the spacing scale no longer reads --sp-1..20',
    !str_contains($instructions, '--sp-1..20')
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
