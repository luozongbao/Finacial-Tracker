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
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

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

    // A web request must not hang for the library default of 300 seconds.
    $mail->Timeout = 30;

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
        // never in the response body, and never in the user's face.
        error_log('[mailer] ' . $ex->getMessage());

        return false;
    }
}
