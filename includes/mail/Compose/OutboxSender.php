<?php

namespace HOD\Mail\Compose;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailActions;
use HOD\Mail\MailError;
use HOD\Mail\MailNotices;
use HOD\Mail\MailSendException;
use HOD\Mail\MailTransport;
use HOD\Mail\Store;
use HOD\Mail\Sync\MailSync;
use Throwable;

/**
 * Sends what is due in mail_outbox: cron/mail_minute.php every minute, and the composer itself at the end of the
 * 10-second undo window. A row is taken by one atomic update (queued → sending), so it never goes twice, whoever
 * asks. Every send takes the domain's shared hourly budget first (MailTransport → email_budget_take). Then:
 *
 *   sent      MailTransport sent it from the mailbox and put the copy in Sent; the original is marked \Answered,
 *             the server draft and the files removed, Sent and Drafts read again so the conversation shows it at
 *             once (sync credits sent_by from message_id_hash)
 *   queued    a temporary failure (the server busy, the hour's budget used): tried again after 1, 2, 5, 15, 30
 *             and 60 minutes
 *   failed    a permanent failure (or retries used up): the writer gets a bell notification
 *
 * A row left in "sending" (a run that stopped mid-way) is marked failed after 15 minutes, never sent again by
 * itself: it may have gone.
 */
final class OutboxSender
{
    public const OUTBOX_URL = '/modules/mail/outbox.php';

    public static function make(): self
    {
        return new self();
    }

    /** Send everything due. @return array{sent: int, retry: int, failed: int, stuck: int} */
    public function due(?int $limit = null): array
    {
        $counts = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'stuck' => 0];
        $counts['stuck'] = $this->releaseStuck();
        $ids = Db::column(
            "SELECT id FROM mail_outbox WHERE status = 'queued' AND send_at <= ? ORDER BY send_at LIMIT " . (int) ($limit ?? ComposeConfig::get('batch', 25)),
            [Clock::sql()],
        );
        foreach ($ids as $id) {
            $row = $this->send((string) $id);
            $key = ['sent' => 'sent', 'queued' => 'retry', 'failed' => 'failed'][$row['status'] ?? ''] ?? null;
            if ($key) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * Send one queued message if it is due ($early: up to that many seconds before, for the composer's own call at
     * the end of the undo window). Anything else returns the row unchanged.
     */
    public function send(string $outboxId, int $early = 0): ?array
    {
        $claimed = Db::exec(
            "UPDATE mail_outbox SET status = 'sending', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'queued' AND send_at <= ?",
            [Clock::sql(), $outboxId, Clock::sql(max(0, $early))],
        );
        $row = Outbox::row($outboxId);
        if (!$claimed || !$row) {
            return $row;
        }
        $userId = $row['user_id'];
        $account = Store::account($row['account_id']);
        if (!$account || !MailAccess::can($userId, $account, 'send')) {
            return $this->failed($row, 'You can no longer send from ' . ($account['email'] ?? 'that mailbox') . '.');
        }
        if ($account['status'] !== 'active') {
            return $this->failed($row, "{$account['email']} is not working right now. Ask a mail admin, then send again.");
        }

        try {
            $message = MessageBuilder::build($row, $userId);
            $result = MailTransport::make()->send($userId, $account, $message);
        } catch (MailSendException $e) {
            if ($e->reason === 'auth') {
                return $this->failed($row, "The mail server refused {$account['email']}'s password. Ask a mail admin to reset it, then send again.");
            }

            return $e->permanent ? $this->failed($row, $e->getMessage()) : $this->retry($row, $e->getMessage());
        } catch (MailError $e) {
            return $this->failed($row, $e->getMessage());
        } catch (Throwable $e) {
            error_log('Mail: sending failed (outbox ' . $outboxId . '): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());

            return $this->retry($row, 'Something went wrong while sending.');
        }

        Db::update('mail_outbox', [
            'status' => 'sent',
            'sent_at' => Clock::sql(),
            'message_id' => $result['message_id'],
            'message_id_hash' => Store::hashId($result['message_id']),
            'last_error' => null,
            'updated_at' => Clock::sql(),
        ], $outboxId);
        try {
            $this->afterSent(Outbox::row($outboxId), $account);
        } catch (Throwable $e) {
            error_log('Mail: sent; tidying up afterwards failed (outbox ' . $outboxId . '): ' . $e->getMessage());
        }

        return Outbox::row($outboxId);
    }

    /** The original marked \Answered, the server draft and files removed, Sent and Drafts read again */
    private function afterSent(array $row, array $account): void
    {
        Outbox::make()->removeServerDraft($row);
        Files::remove($row['id']);
        if (in_array($row['kind'], ['reply', 'reply_all'], true) && $row['reply_to_message_id']) {
            try {
                if (Store::message($row['reply_to_message_id'])) {
                    MailActions::make()->markAnswered($row['user_id'], [$row['reply_to_message_id']]);
                }
            } catch (Throwable $e) {
                error_log('Mail: sent; the original could not be marked answered (outbox ' . $row['id'] . '): ' . $e->getMessage());
            }
        }
        // Skipped (busy) when a sync of this mailbox is running: that sync or the next minute's picks it up
        MailSync::make()->roles($account['id'], ['sent', 'drafts'], 8.0);
        $threadId = Db::value(
            'SELECT thread_id FROM mail_messages WHERE account_id = ? AND message_id_hash = ? AND thread_id IS NOT NULL ORDER BY id LIMIT 1',
            [$account['id'], $row['message_id_hash']],
        );
        $threadId = $threadId !== null ? (int) $threadId : $row['thread_id'];
        if ($threadId && $threadId !== $row['thread_id']) {
            Db::update('mail_outbox', ['thread_id' => $threadId], $row['id']);
        }
        // The conversation's timeline (PR 4 shows it) on shared mailboxes
        if ($threadId && Store::isShared($account) && Store::thread($threadId)) {
            Db::insert('mail_thread_activity', [
                'thread_id' => $threadId,
                'user_id' => $row['user_id'],
                'type' => 'sent',
                'data' => Db::json(['outbox_id' => $row['id'], 'kind' => $row['kind'], 'subject' => $row['payload']['subject'] ?? null]),
                'created_at' => Clock::sql(),
            ]);
        }
    }

    private function retry(array $row, string $error): array
    {
        $waits = array_values((array) ComposeConfig::get('retry_minutes', [1, 2, 5, 15, 30, 60]));
        $attempt = max(1, (int) $row['attempts']);
        if ($attempt > count($waits)) {
            return $this->failed($row, $error);
        }
        Db::update('mail_outbox', [
            'status' => 'queued',
            'send_at' => Clock::sql(60 * (int) $waits[$attempt - 1]),
            'last_error' => mb_substr($error, 0, 500),
            'updated_at' => Clock::sql(),
        ], $row['id']);

        return Outbox::row($row['id']);
    }

    private function failed(array $row, string $error): array
    {
        Db::update('mail_outbox', ['status' => 'failed', 'last_error' => mb_substr($error, 0, 500), 'updated_at' => Clock::sql()], $row['id']);
        $p = $row['payload'];
        $first = $p['to'][0] ?? $p['cc'][0] ?? $p['bcc'][0] ?? null;
        $subject = trim((string) ($p['subject'] ?? '')) ?: '(no subject)';
        MailNotices::send(
            [$row['user_id']],
            'Message not sent',
            '“' . mb_substr($subject, 0, 120) . '”' . ($first ? ' to ' . ($first['name'] ?: $first['email']) : '') . " did not go: {$error} Open it to fix or send again.",
            self::OUTBOX_URL,
        );

        return Outbox::row($row['id']);
    }

    /** Rows left in "sending" by a run that stopped: marked not sent (it may have gone; never send twice) */
    private function releaseStuck(): int
    {
        $ids = Db::column(
            "SELECT id FROM mail_outbox WHERE status = 'sending' AND updated_at < ?",
            [Clock::sql(-60 * (int) ComposeConfig::get('stuck_minutes', 15))],
        );
        foreach ($ids as $id) {
            $row = Outbox::row((string) $id);
            if ($row) {
                $this->failed($row, 'The ERP could not confirm it left. Look in Sent before sending again.');
            }
        }

        return count($ids);
    }
}
