<?php
/**
 * mailer.php — the SMTP wrapper around PHPMailer.
 *
 * The contract (docs/issues/issue-010.md, §8):
 *
 *   1. Configuration comes from config.php and from nothing else. No getenv(),
 *      no $_ENV, no $_SERVER. The constants are read at call time, which is
 *      what lets a regression test run this file against a fake config in a
 *      child process.
 *   2. An empty SMTP_HOST means "mail is not configured": no connection is
 *      attempted and sendMail() returns false. The forgot-password screen
 *      shows that state instead of a form that can never deliver.
 *   3. A failed send returns false; it never throws. A public form must not
 *      turn a mail failure into a 500.
 *   4. Port 465 is implicit TLS (SMTPS); every other port is STARTTLS.
 *      Getting this wrong is the most common SMTP misconfiguration.
 *   5. The From: address comes from MAIL_FROM / MAIL_FROM_NAME, not from
 *      SMTP_USER. A company SMTP account may send on behalf of a different
 *      mailbox, and a message with no From: is refused by most servers.
 *   6. The body is PLAIN TEXT (issue-016 §11). There is no HTML mail in
 *      v0.2 — the messages carry a link, and plain text cannot carry a
 *      phishing lure.
 *
 * The runtime never runs Composer. vendor/ is produced by a deliberate
 * vendor-time install and is git-ignored — see docs/issues/issue-010.md.
 *
 * Issue-022 (§7.3) adds the transport BOUNDS:
 *
 *   7. Every timeout is EXPLICIT and BOUNDED. The library default is 300
 *      seconds, which is a denial of service on its own: a request that
 *      waits five minutes for a dead SMTP server holds a worker slot the
 *      whole time. The bounds are set on the object, not left to the
 *      library, and the test asserts them.
 *   8. A transport failure is logged SANITISED. The exception message of
 *      a rejected mail carries the recipient address, so it never goes to
 *      the log verbatim — the context, the class and the code do.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/logging.php';

/*
 * The product's own transport bounds. They are declared with a guard
 * because this file is included by the front controller, by the worker and
 * by the regression tests' child processes — a second inclusion must not
 * emit a warning. A config.php that declares the constant wins: the guard
 * keeps the value that is already there.
 */
if (!defined('SMTP_TIMEOUT_SECONDS')) {
    /**
     * How long the SMTP transport may take, in seconds.
     *
     * It bounds the CONNECT and the socket reads and writes: PHPMailer
     * passes $mail->Timeout to the stream context and calls
     * stream_set_timeout() with it, so a server that accepts the
     * connection and then says nothing is cut off after this many
     * seconds (issue-022 §7.3).
     */
    define('SMTP_TIMEOUT_SECONDS', 10);
}

/**
 * Is SMTP configured at all?
 *
 * An empty SMTP_HOST is the "not configured" state. It is checked before any
 * socket is opened, so an unconfigured install never waits for a timeout.
 */
function mailIsConfigured(): bool
{
    return SMTP_HOST !== '';
}

/**
 * Build a PHPMailer instance from config.php.
 *
 * Split out of sendMail() so the transport settings can be asserted without
 * opening a connection — a regression test needs to check the encryption mode
 * and the From: address, and neither requires a server.
 *
 * Exceptions are ON: a silent failure would let the UI say "sent" for a
 * message that never left. sendMail() catches them.
 */
function createMailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    // SMTP_PORT is an integer in config.php. The cast keeps a quoted '465'
    // from silently selecting the wrong encryption mode.
    $port = (int) SMTP_PORT;

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->Port       = $port;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = $port === 465
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    // The messages contain user-supplied text.
    $mail->CharSet = 'UTF-8';

    // Every timeout is explicit and bounded (issue-022 §7.3). The library
    // default is 300 seconds, and a request that waits five minutes for a
    // dead SMTP server is a denial of service on its own.
    $mail->Timeout = SMTP_TIMEOUT_SECONDS;

    // The same bound at the stream level: PHPMailer hands $mail->SMTPOptions
    // straight to stream_context_create(), so the socket's own timeout is
    // the context's socket timeout.
    $mail->SMTPOptions = ['socket' => ['timeout' => SMTP_TIMEOUT_SECONDS]];

    // One message per connection. A kept-alive connection is a socket the
    // worker holds open between two messages, and a socket that is held
    // open is a socket that can go stale without anybody noticing.
    $mail->SMTPKeepAlive = false;

    // setFrom(), never addReplyTo() alone: a message with no From: is refused
    // by most SMTP servers. $auto stays on so the envelope sender matches.
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);

    return $mail;
}

/**
 * Fill a message: the recipient, the subject and a PLAIN-TEXT body.
 *
 * Split out of sendMail() so the plain-text mode is assertable without
 * opening a connection — a regression test needs to check the content type
 * of the message, and that needs no server.
 *
 * The body is plain text on purpose (issue-016 §11): it is a plain-text
 * link, and plain text cannot carry a phishing lure.
 */
function prepareMessage(PHPMailer $mail, string $to, string $subject, string $body): void
{
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->isHTML(false);
    $mail->Body = $body;
}

/**
 * Send one plain-text mail.
 *
 * Returns true only when the message was handed to the server. false means
 * "not sent", for every reason: not configured, refused, unreachable, or the
 * server rejected the message.
 */
function sendMail(string $to, string $subject, string $body): bool
{
    if (!mailIsConfigured()) {
        return false;
    }

    try {
        $mail = createMailer();

        prepareMessage($mail, $to, $subject, $body);

        return $mail->send();
    } catch (Throwable $ex) {
        // display_errors is Off, so the reason belongs in the error log —
        // never in the response body, and never in the user's face. The
        // log is SANITISED: the message of a rejected mail carries the
        // recipient address, so only the context, the class and the code
        // are written (issue-022 §7.4).
        logFailure('mailer: the send failed', $ex);

        return false;
    }
}
