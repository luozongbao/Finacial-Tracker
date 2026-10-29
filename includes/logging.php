<?php
/**
 * logging.php — the sanitised error log.
 *
 * The contract (docs/issues/issue-022.md, §7.4):
 *
 *   1. An exception MESSAGE is where the personal data leaks. A MySQL
 *      unique-key violation carries the offending value ("Duplicate entry
 *      'victim@example.com' for key 'users.email_unique'"), and a
 *      PHPMailer exception carries the SMTP host and sometimes the SMTP
 *      user name. So the message is NEVER logged.
 *   2. What IS logged is the CONTEXT — which operation failed — plus the
 *      class and the code of the exception, and where it was thrown. That
 *      is enough to find the failure again, and it holds nothing the
 *      visitor submitted.
 *   3. The log is the OPERATOR's channel. It is not a place to put
 *      anything the visitor typed: the log file ends up in a support
 *      ticket, or in a MySQL general log, or in somebody's inbox.
 *
 * Every failure in the application is reported through logFailure(). It
 * is a thin wrapper around error_log() so that the "never the message"
 * rule has exactly ONE place to be broken.
 *
 * The file requires nothing: it is loaded by the front controller, by the
 * mailer (which the mailer test loads on its own, in a child process) and
 * by the outbox worker.
 */

declare(strict_types=1);

/**
 * Write a failure to the error log WITHOUT the data that failed.
 *
 * The context is a short label of the operation — "auth: the registration
 * failed" — and never a value. The exception's class and code identify
 * the failure ("PDOException", "23000" for a unique-key violation), and
 * the file and the line say where it happened.
 *
 * The exception is optional: a failure that has no exception to name (the
 * mail server refused the message, the config did not parse) is logged
 * with the context alone.
 *
 * @param string        $context What was being done, in words. Never a value.
 * @param Throwable|null $ex     The failure, when there is one.
 */
function logFailure(string $context, ?Throwable $ex = null): void
{
    if ($ex === null) {
        error_log(sprintf('[%s]', $context));

        return;
    }

    error_log(sprintf(
        '[%s] %s (code %s) at %s:%d',
        $context,
        get_class($ex),
        (string) $ex->getCode(),
        $ex->getFile(),
        $ex->getLine()
    ));
}
