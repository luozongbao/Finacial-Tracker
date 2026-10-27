<?php
/**
 * bootstrap.php — dependency bootstrap.
 *
 * The application has exactly one third-party dependency (PHPMailer), vendored
 * at vendor time. This file loads the Composer autoloader and fails loudly if
 * the vendor tree is missing.
 *
 * The contract (docs/issues/issue-010.md, §8.1):
 *
 *   1. require_once, not require — the autoloader is included exactly once
 *      per request.
 *   2. is_file() guard with a clear, actionable message. A missing vendor/ is
 *      a deployment mistake, and the operator has to be told how to fix it.
 *   3. The runtime NEVER runs Composer. It does not shell out to
 *      `composer install`, and it does not try to repair the tree. The
 *      runtime does not mutate itself.
 *
 * ORDERING — read this before wiring the bootstrap into index.php:
 *
 *   index.php checks for config.php FIRST and redirects to install.php when it
 *   is missing. Only then does it require this bootstrap. A fresh checkout
 *   has no vendor/ yet, and the installer must stay reachable.
 *
 *   The front controller must catch the RuntimeException thrown below and
 *   render the message. Left uncaught it becomes a blank page, because
 *   display_errors is Off — which is exactly the failure this guard exists to
 *   replace with something the operator can act on.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    // Report it; do not try to fix it. The runtime never mutates itself.
    throw new RuntimeException(
        'Dependencies are not installed. Run: composer install --no-dev --optimize-autoloader'
    );
}

require_once $autoload;
