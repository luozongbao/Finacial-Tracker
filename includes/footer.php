<?php
/**
 * footer.php — closes the page frame that header.php opened.
 *
 * The contract (docs/issues/issue-013.md §8):
 *
 *   1. The script tag is the LAST thing in the body, and it is here rather
 *      than in the head: the DOM must exist before assets/js/app.js runs.
 *   2. There is NO inline script block anywhere. Every behaviour lives in
 *      assets/js/app.js.
 *   3. The app shell closes .app__main, then .app__footer, then the .app
 *      wrapper, and then renders the mobile bottom navigation — which sits
 *      outside the wrapper, exactly as in the reference mockup.
 *   4. The auth shell closes .auth__footer and the .auth column. It has no
 *      bottom navigation: the guest has no navigation at all.
 *
 * Every dynamic value is escaped with e(). There is no exception.
 */

declare(strict_types=1);

$shell = isset($shell) && is_string($shell) && $shell === 'auth' ? 'auth' : 'app';
$active = isset($active) && is_string($active) ? $active : '';

// The navigation list is built by header.php, which always runs first. The
// fallback keeps a stray include of this file quiet instead of fatal.
$navItems = isset($navItems) && is_array($navItems) ? $navItems : [];

?>
<?php if ($shell === 'auth'): ?>
    <footer class="auth__footer">
        <p>Personal finance tracker · your data stays on your server.</p>
    </footer>
</main>
<?php else: ?>
</main>

<footer class="app__footer">
    <p>Financial Tracker · personal use.</p>
</footer>
</div>

<nav class="bottom-nav" aria-label="Primary, mobile">
    <div class="bottom-nav__inner">
<?php foreach ($navItems as $navKey => [$navHref, $navLabel]): ?>
        <a class="bottom-nav__link" href="<?= e($navHref) ?>"<?= $active === $navKey ? ' aria-current="page"' : '' ?>><?= e($navLabel) ?></a>
<?php endforeach; ?>
    </div>
</nav>
<?php endif; ?>
<script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
