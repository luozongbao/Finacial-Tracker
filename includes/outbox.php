<?php
/**
 * outbox.php — the outgoing mail queue.
 *
 * The contract (docs/issues/issue-022.md, §7.1):
 *
 *   1. The request NEVER talks to the SMTP server. It writes a row here and
 *      answers the visitor. A CLI worker (database/outbox_worker.php) sends
 *      the message afterwards.
 *   2. That is what makes the forgot-password screen answer in the SAME
 *      time for a known address and for an unknown one. The known branch
 *      pays for one extra INSERT — milliseconds — where it used to pay for
 *      an SMTP round trip — seconds. The timing floor then means something
 *      again, because the difference is inside the floor instead of on top
 *      of it.
 *   3. A retry RE-USES the same row. A retry is never a second row, so a
 *      transient failure cannot send the message twice.
 *   4. The row is CLAIMED with a SELECT ... FOR UPDATE SKIP LOCKED, so two
 *      workers cannot send the same message, and a worker that dies
 *      mid-send leaves a claim that expires.
 *   5. The table holds personal data — a reset link lives in it until the
 *      worker sends it — so it belongs in the database/, which .htaccess
 *      denies.
 *
 * The module requires the mailer, because the queue state and the worker
 * both need to know whether SMTP is configured at all.
 */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

if (!defined('OUTBOX_MAX_ATTEMPTS')) {
    /**
     * How many times a message is tried before it is given up on.
     *
     * Three: the first try, and two retries. A message that fails three
     * times is not going to succeed on the fourth — the SMTP server is
     * down, or the address is refused — and the row is marked 'failed' so
     * it stops being picked up.
     */
    define('OUTBOX_MAX_ATTEMPTS', 3);

    /**
     * How long a claim is held, in minutes.
     *
     * A worker that dies mid-send leaves the row claimed. The lease
     * expires after this many minutes and the row is taken back, so a
     * crash cannot strand a message for ever.
     */
    define('OUTBOX_CLAIM_LEASE_MINUTES', 5);

    /**
     * The first backoff, in minutes.
     *
     * A failed send is put back with a wait that GROWS: the first retry
     * waits twice this, the next one four times this. A dead SMTP server
     * is not hammered, and a server that is only briefly unhappy is
     * retried before the visitor gives up.
     */
    define('OUTBOX_BACKOFF_BASE_MINUTES', 1);

    /**
     * How long a finished row is kept, in days.
     *
     * The row is the audit trail of what was sent. A week is enough to
     * answer "did my reset mail go out?" and short enough that the table
     * does not grow without bound.
     */
    define('OUTBOX_RETENTION_DAYS', 7);
}

/**
 * The state of the mail queue, as the INSTALLATION sees it.
 *
 * This is the answer the screens use, and it is deliberately NOT a
 * function of the address the visitor typed:
 *
 *   'ready'          the queue can take a message.
 *   'not-configured' SMTP is not configured — a property of the install.
 *   'unavailable'    the queue table is missing — the installation was not
 *                    migrated. It is an installation-level failure, so it
 *                    is reported the same way for every address.
 *
 * @return string
 */
function mailQueueState(): string
{
    if (!mailIsConfigured()) {
        return 'not-configured';
    }

    if (!outboxIsReady()) {
        return 'unavailable';
    }

    return 'ready';
}

/**
 * Can the queue take a message?
 *
 * The probe is a query against the table itself, so it fails only when the
 * table is missing — which means the migration was not applied. It is
 * cheap, and it never writes.
 */
function outboxIsReady(): bool
{
    try {
        Database::getInstance()->fetchOne('SELECT id FROM mail_outbox ORDER BY id LIMIT 1');

        return true;
    } catch (Throwable $ex) {
        // The reason is not logged here: the caller decides what to tell
        // the visitor, and the state itself is the message.
        return false;
    }
}

/**
 * Queue one message.
 *
 * The message is NOT sent here. It is written, and the worker sends it.
 *
 * @return int The id of the new row.
 */
function enqueueMail(string $to, string $subject, string $body): int
{
    $db = Database::getInstance();

    $db->execute(
        "INSERT INTO mail_outbox (to_address, subject, body, status, attempts)
              VALUES (?, ?, ?, 'queued', 0)",
        [$to, $subject, $body]
    );

    return $db->lastInsertId();
}

/**
 * How many messages are waiting to be sent.
 *
 * The installation card shows it, so an operator can see at a glance that
 * the worker is not running.
 */
function outboxQueueDepth(): int
{
    $row = Database::getInstance()->fetchOne(
        "SELECT COUNT(*) AS depth
           FROM mail_outbox
          WHERE status = 'queued'"
    );

    return is_array($row) ? (int) $row['depth'] : 0;
}

/**
 * Take the next message, and only that one.
 *
 * The claim is a SELECT ... FOR UPDATE SKIP LOCKED inside a transaction:
 * two workers that run at the same time cannot take the same row, and the
 * row that was taken is marked as claimed before the transaction ends. A
 * claim that is older than the lease is taken back, so a worker that died
 * mid-send does not strand the message.
 *
 * The message is returned; the SEND happens outside the transaction. A
 * transaction that spans a network round trip holds a lock for the length
 * of the round trip, which is exactly what the lock must not do.
 *
 * @return array{id: int, to_address: string, subject: string, body: string}|null
 */
function claimOutboxMessage(): ?array
{
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $pdo->beginTransaction();

    try {
        $row = $db->fetchOne(
            "SELECT id, to_address, subject, body
               FROM mail_outbox
              WHERE status = 'queued'
                AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
                AND (claimed_at IS NULL OR claimed_at <= TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()))
              ORDER BY id
              LIMIT 1
              FOR UPDATE SKIP LOCKED",
            [-(int) OUTBOX_CLAIM_LEASE_MINUTES]
        );

        if (!is_array($row)) {
            $pdo->commit();

            return null;
        }

        $db->execute(
            'UPDATE mail_outbox SET claimed_at = UTC_TIMESTAMP() WHERE id = ?',
            [(int) $row['id']]
        );

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $ex;
    }

    return [
        'id'         => (int) $row['id'],
        'to_address' => (string) $row['to_address'],
        'subject'    => (string) $row['subject'],
        'body'       => (string) $row['body'],
    ];
}

/**
 * Mark a message as sent.
 */
function markOutboxSent(int $id): void
{
    Database::getInstance()->execute(
        "UPDATE mail_outbox
            SET status = 'sent',
                sent_at = UTC_TIMESTAMP(),
                attempts = attempts + 1,
                claimed_at = NULL,
                next_attempt_at = NULL,
                last_error = NULL
          WHERE id = ?",
        [$id]
    );
}

/**
 * Give a message back: the send failed.
 *
 * The SAME row is reused — a retry is never a second row — so a transient
 * failure cannot send the message twice. The row goes back to 'queued'
 * with a BACKOFF, and after the last attempt it is marked 'failed' and
 * left alone.
 *
 * The assignments are ORDERED, and the order is the point:
 *
 *   status          reads the OLD attempts, so it knows whether this
 *                   attempt was the last one.
 *   next_attempt_at reads the OLD attempts too, so the wait grows with
 *                   every attempt.
 *   attempts        is increased LAST.
 *
 * @return string The new status of the row: 'queued' or 'failed'.
 */
function releaseOutboxMessage(int $id, string $error): string
{
    $db = Database::getInstance();

    $db->execute(
        "UPDATE mail_outbox
            SET status = IF(attempts + 1 >= ?, 'failed', 'queued'),
                next_attempt_at = TIMESTAMPADD(MINUTE, ? * (2 * (attempts + 1)), UTC_TIMESTAMP()),
                attempts = attempts + 1,
                claimed_at = NULL,
                last_error = ?
          WHERE id = ?",
        [(int) OUTBOX_MAX_ATTEMPTS, (int) OUTBOX_BACKOFF_BASE_MINUTES, substr($error, 0, 255), $id]
    );

    $row = $db->fetchOne('SELECT status FROM mail_outbox WHERE id = ?', [$id]);

    return is_array($row) ? (string) $row['status'] : 'queued';
}

/**
 * Send what is queued.
 *
 * The worker calls this. It sends at most $limit messages, and it stops
 * at the first message it cannot claim.
 *
 * @return array{sent: int, failed: int, retried: int, remaining: int, configured: bool}
 */
function runOutboxWorker(int $limit = 25): array
{
    $summary = [
        'sent'       => 0,
        'failed'     => 0,
        'retried'    => 0,
        'remaining'  => 0,
        'configured' => mailIsConfigured(),
    ];

    if (!$summary['configured']) {
        // Nothing can be sent. The queue is left untouched: the messages
        // are still there when SMTP is configured again.
        $summary['remaining'] = outboxQueueDepth();

        return $summary;
    }

    for ($index = 0; $index < $limit; $index++) {
        $message = claimOutboxMessage();

        if ($message === null) {
            break;
        }

        $sent = false;

        try {
            $sent = sendMail($message['to_address'], $message['subject'], $message['body']);
        } catch (Throwable $ex) {
            // sendMail() never throws — but the worker must not die because
            // it did.
            logFailure('outbox: the queued message could not be sent', $ex);

            $sent = false;
        }

        if ($sent) {
            markOutboxSent($message['id']);

            $summary['sent']++;

            continue;
        }

        $status = releaseOutboxMessage($message['id'], 'the transport refused the message');

        if ($status === 'failed') {
            $summary['failed']++;
        } else {
            $summary['retried']++;
        }
    }

    $summary['remaining'] = outboxQueueDepth();

    return $summary;
}

/**
 * Remove the rows that are finished and old.
 *
 * The delete is bounded, and the probe in front of it keeps the statement
 * off the hot path.
 */
function pruneOutbox(): void
{
    $db = Database::getInstance();

    $stale = $db->fetchOne(
        "SELECT COUNT(*) AS stale
           FROM mail_outbox
          WHERE status IN ('sent','failed')
            AND created_at <= TIMESTAMPADD(DAY, ?, UTC_TIMESTAMP())",
        [-(int) OUTBOX_RETENTION_DAYS]
    );

    if (!is_array($stale) || (int) $stale['stale'] === 0) {
        return;
    }

    $db->execute(
        "DELETE FROM mail_outbox
          WHERE status IN ('sent','failed')
            AND created_at <= TIMESTAMPADD(DAY, ?, UTC_TIMESTAMP())
          LIMIT 100",
        [-(int) OUTBOX_RETENTION_DAYS]
    );
}
