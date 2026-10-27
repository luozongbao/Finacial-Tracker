<?php
/**
 * error_405.php — the page for a known path that was asked for with the
 * wrong method.
 *
 * The contract (docs/issues/issue-014.md §6):
 *
 *   1. The response carries an `Allow:` header listing the methods the path
 *      does accept. The router sends it, and the front controller forwards
 *      the header the exception carries.
 *   2. The page repeats the allowed methods, so a visitor who cannot read
 *      the headers still knows what to use.
 *   3. No stack trace, no raw message, no file path.
 */

declare(strict_types=1);

$title = 'Method not allowed';
$shell = 'auth';

// Set by the front controller from the exception's Allow header. The view
// stands on its own when the variable is absent.
$allowedMethods = isset($allowedMethods) && is_string($allowedMethods) ? $allowedMethods : '';

require __DIR__ . '/../header.php';
?>
<div class="card">
    <h2 class="card__title">Method not allowed</h2>
    <p>That page does not accept this request.</p>
<?php if ($allowedMethods !== ''): ?>
    <p class="field__hint">This page accepts: <code><?= e($allowedMethods) ?></code></p>
<?php endif; ?>
</div>
<?php
require __DIR__ . '/../footer.php';
