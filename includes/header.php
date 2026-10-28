<?php
/**
 * header.php — the shared page frame.
 *
 * Two shells live here, chosen with $shell:
 *
 *   'app'   the application shell — the app bar, the navigation and the
 *           content column. Every signed-in screen renders inside it.
 *   'auth'  the guest shell — one narrow column carrying the brand, the
 *           card and the footer. The guest must never see the signed-in
 *           navigation, so the auth shell has no app bar and no nav.
 *
 * The contract (docs/issues/issue-013.md §8):
 *
 *   1. $title    — the page title. The caller sets it; the shell does not
 *                  guess it.
 *   2. $active   — 'dashboard' | 'transactions' | 'reports' | 'categories'
 *                  | 'settings'. It marks the current link with
 *                  aria-current="page".
 *   3. $username — the signed-in user, shown in the app bar.
 *   4. $pageCss  — an OPTIONAL page stylesheet under assets/css/pages/.
 *                  A screen that needs no rules beyond the component layer
 *                  sets nothing and links nothing.
 *   5. $shell    — 'app' (the default) or 'auth'.
 *
 * Every dynamic value is escaped with e(). There is no exception.
 *
 * The stylesheet order is load-bearing (issue-013 §6):
 *   tokens -> base -> components -> pages/<page>
 */

declare(strict_types=1);

// -------------------------------------------------------------------------
// The caller's variables, normalised. A missing or malformed value falls
// back to a safe one rather than reaching the page.
// -------------------------------------------------------------------------

$title = isset($title) && is_string($title) && $title !== '' ? $title : 'Financial Tracker';
$active = isset($active) && is_string($active) ? $active : '';
$username = isset($username) && is_string($username) ? $username : '';
$pageCss = isset($pageCss) && is_string($pageCss) ? $pageCss : '';
$shell = isset($shell) && is_string($shell) && $shell === 'auth' ? 'auth' : 'app';

// The page stylesheet name is a developer-supplied constant, never a user
// value. It is still whitelisted: only a plain file name may reach the
// path, so a stray "../" cannot walk out of assets/css/pages/.
if ($pageCss !== '' && preg_match('/^[a-z0-9-]+\.css$/', $pageCss) !== 1) {
    $pageCss = '';
}

// -------------------------------------------------------------------------
// The theme preference.
//
// The server renders <html data-theme="..."> so the choice holds BEFORE the
// first paint. The server can only know the preference through a cookie —
// localStorage is invisible to it — and there is no inline script block in
// this application (issue-013 §11), so the cookie is the channel.
// assets/js/app.js writes it, and mirrors it into localStorage for the
// browser.
//
// A cookie is user-controlled, so the value is whitelisted to the two the
// stylesheet understands. An unknown value is dropped: the media query in
// tokens.css then applies the system preference, which is the correct
// default.
// -------------------------------------------------------------------------

$themeCookie = isset($_COOKIE['theme']) && is_string($_COOKIE['theme']) ? $_COOKIE['theme'] : '';
$theme = ($themeCookie === 'dark' || $themeCookie === 'light') ? $themeCookie : '';

// -------------------------------------------------------------------------
// The navigation. The order is the navigation order in every mockup, and
// the four links are the same four on the desktop bar and on the mobile
// bottom bar. Settings follows the spacer, outside the <nav>.
// -------------------------------------------------------------------------

$navItems = [
    'dashboard' => ['/dashboard', 'Dashboard'],
    'transactions' => ['/transactions', 'Transactions'],
    'reports' => ['/reports', 'Reports'],
    'categories' => ['/categories', 'Categories'],
];

?>
<!DOCTYPE html>
<html lang="en"<?= $theme !== '' ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — Financial Tracker</title>
<link rel="stylesheet" href="<?= e(asset_url('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/components.css')) ?>">
<?php if ($pageCss !== ''): ?>
<link rel="stylesheet" href="<?= e(asset_url('css/pages/' . $pageCss)) ?>">
<?php endif; ?>
</head>
<body>
<?php if ($shell === 'auth'): ?>
<main class="auth">
    <header class="auth__brand">
        <h1 class="auth__brand-name">Financial Tracker</h1>
        <p class="auth__brand-note">Record income and spending, then sum it over any period.</p>
    </header>
<?php else: ?>
<div class="app">
    <header class="app__bar">
        <div class="app__bar-inner">
            <a class="app__brand" href="/dashboard">Financial Tracker</a>
            <nav class="nav" aria-label="Primary">
<?php foreach ($navItems as $navKey => [$navHref, $navLabel]): ?>
                <a class="nav__link" href="<?= e($navHref) ?>"<?= $active === $navKey ? ' aria-current="page"' : '' ?>><?= e($navLabel) ?></a>
<?php endforeach; ?>
            </nav>
            <span class="app__spacer"></span>
            <span class="app__user">signed in as <strong><?= e($username) ?></strong></span>
            <a class="nav__link" href="/settings"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>>Settings</a>
            <button type="button" class="btn btn--ghost btn--sm" data-theme-toggle aria-pressed="false">Dark mode</button>
        </div>
    </header>
    <main class="app__main">
<?php endif; ?>
