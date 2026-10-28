<?php
/**
 * error_403.php — the page for a request the visitor is not allowed to make.
 *
 * The contract (docs/issues/issue-014.md §6):
 *
 *   1. The copy is generic. It must not say whether an account exists, and
 *      it must not say WHICH rule refused the request — that is a
 *      fingerprint an attacker can use.
 *   2. No stack trace, no raw message, no file path.
 *   3. The status code was set by the front controller before this file
 *      was required.
 *
 * A closed registration renders its own copy (the register view owns it),
 * not this page: the visitor has to be told that registration is closed on
 * purpose, which is not the same thing as being refused.
 */

declare(strict_types=1);

$title = 'Not allowed';
$shell = 'auth';

require __DIR__ . '/../header.php';
?>
<div class="card">
    <h2 class="card__title">Not allowed</h2>
    <p>You do not have access to that page.</p>
</div>
<?php
require __DIR__ . '/../footer.php';
