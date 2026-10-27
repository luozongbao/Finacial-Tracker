<?php
/**
 * error_500.php — the page for an uncaught Throwable.
 *
 * The contract (docs/issues/issue-014.md §6):
 *
 *   1. The page NEVER echoes the exception message. The message, the file
 *      and the line were written to the error log by the front controller,
 *      and that is where they stay.
 *   2. No stack trace, no file path, no raw message.
 *   3. The status code was set by the front controller before this file
 *      was required.
 */

declare(strict_types=1);

$title = 'Something went wrong';
$shell = 'auth';

require __DIR__ . '/../header.php';
?>
<div class="card">
    <h2 class="card__title">Something went wrong</h2>
    <p>Something went wrong on our side. Please try again.</p>
</div>
<?php
require __DIR__ . '/../footer.php';
