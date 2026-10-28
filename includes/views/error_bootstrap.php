<?php
/**
 * error_bootstrap.php — the page shown when the vendor tree is missing.
 *
 * The contract (docs/issues/issue-014.md §7):
 *
 *   1. This is the ONLY place a raw exception message is shown, and the
 *      exception is one the application threw itself with a safe,
 *      actionable string. It is still escaped with e().
 *   2. The page shows the command that fixes it. The message names the
 *      command too, but a copy-pasteable block beats a sentence.
 *   3. It is the one page that is rendered from the front controller's
 *      OWN catch block, before the rest of the application is loaded — so
 *      it depends on nothing but functions.php and the shared shell.
 */

declare(strict_types=1);

$title = 'Dependencies are not installed';
$shell = 'auth';

// Set by the front controller from the caught exception. The view stands on
// its own when the variable is absent.
$bootstrapError = isset($bootstrapError) && is_string($bootstrapError) ? $bootstrapError : '';

require __DIR__ . '/../header.php';
?>
<div class="card">
    <h2 class="card__title">Dependencies are not installed</h2>
    <p>The application cannot start because its Composer dependencies are missing.</p>
    <p class="field__hint"><?= e($bootstrapError) ?></p>
    <p>Run this in the project root, then reload the page:</p>
    <p><code>composer install --no-dev --optimize-autoloader</code></p>
</div>
<?php
require __DIR__ . '/../footer.php';
