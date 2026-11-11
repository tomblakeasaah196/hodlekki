<?php

namespace HOD\Mail;

use HOD\Mail\Mime\Message;
use Throwable;

/**
 * Sending one message from a mailbox (docs/MAIL.md; PR 3 builds the composer, outbox and signatures on
 * this). The From line is always the mailbox itself (its name and address); the person shows in their
 * signature, and the ERP records who sent it (mail_messages.sent_by, through mail_outbox). Bcc travels in the
 * envelope only.
 *
 *   $result = MailTransport::make()->send($userId, $account, $message);
 *   // ['message_id' => 'abc@lpc.cm' (no brackets), 'raw' => message as sent, 'sent_uid' => ?int UID in Sent]
 *
 * Throws MailSendException when SMTP refuses (permanent or not) and MailError when the person may not send
 * from this mailbox. The copy in Sent is best effort: a failure there is logged; the message has gone.
 */
final class MailTransport
{
    public static function make(): self
    {
        return new self();
    }

    /** A new Message-ID (without angle brackets) on the mailbox's own domain */
    public static function newMessageId(array $account): string
    {
        $domain = substr(strrchr((string) $account['email'], '@') ?: '', 1) ?: (string) Config::get('local_domain', 'hodlekki.org');

        return bin2hex(random_bytes(12)) . '.' . Clock::now()->format('YmdHis') . '@' . strtolower($domain);
    }

    /** @return array{message_id: string, raw: string, sent_uid: ?int} */
    public function send(int $userId, array $account, Message $message, bool $copyToSent = true): array
    {
        MailAccess::authorize($userId, $account, 'send');

        $message->from($account['email'], (string) $account['display_name']);
        if ($message->getMessageId() === null) {
            $message->messageId(self::newMessageId($account));
        }
        $message->date(Clock::now());

        // One hourly budget for the whole domain (docs/EMAIL.md, "One budget"): every recipient of a mailbox send
        // takes a slot in the 'mailbox' lane first, so people's mail, alerts and campaigns together never pass
        // the host's per-hour limit. A send that fails hands its slots back; the host's own "max emails per hour"
        // refusal stops every sender for the rest of the hour.
        $slots = count($message->recipients());
        if (!self::budget('take', $slots)) {
            throw new MailSendException('The church has sent all the email it may this hour. Your message will go in the next hour.', 'temporary', false, 451);
        }
        try {
            $raw = MailServer::make()->send($account, $message);
        } catch (MailSendException $e) {
            $cap = function_exists('email_parse_cap_refusal') ? email_parse_cap_refusal($e->getMessage()) : null;
            $cap !== null ? self::budget('refused', $cap['cap'] ?? 0) : self::budget('give_back', $slots);
            throw $e;
        }

        return [
            'message_id' => (string) $message->getMessageId(),
            'raw' => $raw,
            'sent_uid' => $copyToSent ? $this->appendToSent($account, $raw) : null,
        ];
    }

    /**
     * The shared email budget (includes/campaigns/budget.php). 'take' answers whether $n slots were reserved;
     * 'give_back' returns them; 'refused' records the host's hourly refusal ($n = the cap it named, 0 = none).
     * Before the email foundation's tables exist nothing is metered (its own rule).
     */
    private static function budget(string $what, int $n): bool
    {
        if (!function_exists('email_budget_take')) {
            $file = dirname(__DIR__) . '/campaigns/bootstrap.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        if (!function_exists('email_budget_take')) {
            return true;
        }
        $pdo = Db::pdo();
        match ($what) {
            'take' => $taken = email_budget_take($pdo, 'mailbox', $n),
            'give_back' => email_budget_give_back($pdo, 'mailbox', $n),
            'refused' => email_budget_refused($pdo, $n > 0 ? $n : null),
        };

        return $taken ?? true;
    }

    /** Put a copy in the mailbox's Sent folder (the server's exim does not), marked read */
    public function appendToSent(array $account, string $raw): ?int
    {
        $sent = Store::folderByRole((int) $account['id'], 'sent');
        if (!$sent) {
            return null;
        }
        try {
            $session = MailServer::make()->open($account);
            try {
                return $session->append($sent['path'], $raw, ['\\Seen']);
            } finally {
                $session->logout();
            }
        } catch (Throwable $e) {
            error_log('Mail: sent, but the copy in Sent could not be saved (account ' . (int) $account['id'] . '): ' . $e->getMessage());

            return null;
        }
    }
}
