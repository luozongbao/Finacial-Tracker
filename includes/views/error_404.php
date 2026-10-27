<?php
/**
 * error_404.php — the page for a URL that is not in the route table.
 *
 * The contract (docs/issues/issue-014.md §6):
 *
 *   1. No stack trace, no raw message, no file path. The LiteSpeed error
 *      log holds the detail.
 *   2. The status code was set by the front controller before this file
 *      was required — this view writes the body, nothing else.
 *   3. The guest shell (the auth frame): it needs no database, so the page
 *      still renders when the database is unreachable.
 */

declare(strict_types=1);

$title = 'Page not found';
$shell = 'auth';

require __DIR__ . '/../header.php';
?>
<div class="card">
    <h2 class="card__title">Page not found</h2>
    <p>That page does not exist.</p>
    <p><a class="btn btn--primary" href="/dashboard">Go to the dashboard</a></p>
</div>
<?php
require __DIR__ . '/../footer.php';
