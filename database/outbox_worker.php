<?php
/**
 * outbox_worker.php — the mail worker.
 *
 * The request path NEVER talks to the SMTP server: the registration and
 * the forgot-password screens write a row into `mail_outbox` and answer
 * the visitor. THIS script is what sends those messages.
 *
 * WHY THE WORKER EXISTS (docs/issues/issue-022.md, §7.1):
 *
 *   The forgot-password screen used to send the mail inside the request.
 *   A KNOWN address then paid for an SMTP round trip and an UNKNOWN one
 *   did not — 2.004 s against 0.501 s, measured. The response bodies were
 *   identical, but the response TIMES were not, and a timing difference is
 *   an account-enumeration oracle just as much as a different body is.
 *
 *   With the worker the request only writes a row. The known branch pays
 *   for one extra INSERT — milliseconds — where it used to pay for a
 *   network round trip — seconds. The timing floor means something again,
 *   because the difference is inside the floor instead of on top of it.
 *
 * RUNNING IT:
 *
 *   docker compose exec -T app php /var/www/html/database/outbox_worker.php
 *
 *   It is safe to run it as often as you like: a message is CLAIMED with
 *   SELECT ... FOR UPDATE SKIP LOCKED, so two workers cannot send the same
 *   message, and a retry re-uses the same row.
 *
 *   The installation NEEDS it. Without a worker the queue grows and no
 *   mail is ever sent — the installation card in /settings shows the queue
 *   depth for exactly that reason.
 *
 * OPTIONS:
 *
 *   --limit=N   how many messages to send in this run (default 25).
 *   --quiet     print nothing but the errors.
 *
 * EXIT CODES: 0 = the run finished (whatever it sent), 1 = the run could
 * not start at all (no config.php, no vendor tree).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit(1);
}

$root = dirname(__DIR__);

// ---- The options ----------------------------------------------------------

$limit = 25;
$quiet = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int) substr($argument, 8));
    } elseif ($argument === '--quiet') {
        $quiet = true;
    }
}

/**
 * Say something — unless the worker was told to be quiet.
 */
function worker_say(string $message): void
{
    global $quiet;

    if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

// ---- The application ------------------------------------------------------

$config = $root . '/config.php';

if (!is_file($config)) {
    fwrite(STDERR, 'config.php is missing — the installation is not set up. Run the installer first.' . PHP_EOL);

    exit(1);
}

require_once $config;

try {
    require_once $root . '/includes/bootstrap.php';
} catch (Throwable $ex) {
    // The message of the exception is NOT printed. The rule is the same
    // everywhere — the log and the operator's console get the context, the
    // data stays out (issue-022 §7.4).
    fwrite(STDERR, 'The dependencies are not installed. Run: composer install --no-dev --optimize-autoloader' . PHP_EOL);

    exit(1);
}

require_once $root . '/includes/Database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/mailer.php';
require_once $root . '/includes/outbox.php';

// ---- The run --------------------------------------------------------------

try {
    $summary = runOutboxWorker($limit);

    // The housekeeping runs after the sends, so it never delays a message.
    pruneOutbox();
    pruneRequestThrottles();
} catch (Throwable $ex) {
    // The worker must never die with a stack trace in the operator's face:
    // the reason goes to the error log, sanitised, and the exit code says
    // what happened.
    require_once $root . '/includes/logging.php';

    logFailure('outbox: the worker run failed', $ex);

    fwrite(STDERR, 'The worker run failed. The reason is in the error log.' . PHP_EOL);

    exit(1);
}

if (!$summary['configured']) {
    worker_say('SMTP is not configured — nothing was sent.');

    exit(0);
}

worker_say(sprintf(
    'sent %d, retried %d, failed %d, still queued %d',
    $summary['sent'],
    $summary['retried'],
    $summary['failed'],
    $summary['remaining']
));

exit(0);
