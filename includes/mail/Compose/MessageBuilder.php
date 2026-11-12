<?php

namespace HOD\Mail\Compose;

use DateTimeInterface;
use HOD\Mail\Clock;
use HOD\Mail\MailError;
use HOD\Mail\MailFetch;
use HOD\Mail\MailSendException;
use HOD\Mail\Mime\Message;
use HOD\Mail\Store;
use Throwable;

/**
 * Turns an outbox row into the message that goes out (payload keys in docs/MAIL.md §7d): the person's text, then
 * their signature (added here, never in the editor), then the quoted original; an HTML and a plain-text version;
 * In-Reply-To and References on answers; files (uploaded ones, and the original's when forwarding, read from the
 * server through MailFetch at send time). From is set by MailTransport (the mailbox).
 *
 * A problem that sending again will not fix (a file gone) throws MailSendException, permanent.
 */
final class MessageBuilder
{
    private const BODY_STYLE = "font-family:'Segoe UI','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#101828;";

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /**
     * The message to send. $forDraft: the copy kept in the server's Drafts (no signature, no files from the original:
     * that copy is for reading elsewhere; the ERP keeps the full draft).
     */
    public static function build(array $outbox, int $userId, bool $forDraft = false): Message
    {
        $p = $outbox['payload'] ?? [];
        $account = Store::account((int) $outbox['account_id']) ?? throw MailError::notFound();
        $m = new Message();
        foreach (['to', 'cc', 'bcc'] as $field) {
            if (!empty($p[$field])) {
                $m->{$field}(array_map(fn ($a) => ['name' => $a['name'] ?? null, 'email' => $a['email']], $p[$field]));
            }
        }
        $m->subject((string) ($p['subject'] ?? ''));
        if (!empty($outbox['message_id'])) {
            $m->messageId((string) $outbox['message_id']);
        }
        if (!empty($p['in_reply_to'])) {
            $m->header('In-Reply-To', '<' . trim($p['in_reply_to'], '<> ') . '>');
        }
        if (!empty($p['references'])) {
            $refs = array_filter(preg_split('/\s+/', trim((string) $p['references'])) ?: []);
            if ($refs) {
                $m->header('References', implode(' ', array_map(fn ($r) => '<' . trim($r, '<>') . '>', $refs)));
            }
        }

        $style = $forDraft ? 'none' : (string) ($p['signature'] ?? 'full');
        $body = (string) ($p['html'] ?? '');
        $quote = !empty($p['quote']) && !empty($p['quote_html']);
        $html = '<div style="' . self::BODY_STYLE . '">' . self::styled($body) . '</div>'
            . Signatures::html($userId, $account, $style)
            . ($quote ? '<div style="margin-top:28px;' . self::BODY_STYLE . '">' . $p['quote_html'] . '</div>' : '');
        $m->html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="x-apple-disable-message-reformatting"></head><body style="margin:0;padding:0;">' . $html . '</body></html>');

        $text = HtmlCleaner::toText($body);
        if (($signature = Signatures::text($userId, $account, $style)) !== '') {
            $text .= "\n\n" . $signature;
        }
        if ($quote && !empty($p['quote_text'])) {
            $text .= "\n\n" . $p['quote_text'];
        }
        $m->text(ltrim($text) . "\n");

        foreach ($p['attachments'] ?? [] as $a) {
            if ($forDraft && ($a['source'] ?? '') !== 'upload') {
                continue;
            }
            [$content, $name, $mime] = self::attachment($outbox, $a, $userId);
            $m->attach($content, $name, $mime);
        }

        return $m;
    }

    /**
     * The original, quoted below an answer or forwarded, as HTML and plain text (prepared when the composer opens
     * and kept in the payload, so the message can still go if the original moves).
     *
     * @param array{text: string, html: ?string} $body
     * @return array{html: string, text: string}
     */
    public static function quote(string $kind, array $original, array $body): array
    {
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '' && !empty($body['html'])) {
            $text = HtmlCleaner::toText($body['html']);
        }
        $text = mb_substr($text, 0, 100000);
        $when = self::when(Clock::parse($original['sent_at'] ?? null) ?? Clock::parse($original['received_at'] ?? null));
        $from = Recipients::format(['name' => $original['from_name'] ?? null, 'email' => (string) ($original['from_email'] ?? '')]);
        $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        if ($kind === 'forward') {
            $lines = array_filter([
                'From: ' . $from,
                $when ? 'Date: ' . $when : null,
                'Subject: ' . ($original['subject'] ?? ''),
                ($to = implode(', ', array_map([Recipients::class, 'format'], $original['to_list'] ?? []))) !== '' ? 'To: ' . $to : null,
                ($cc = implode(', ', array_map([Recipients::class, 'format'], $original['cc_list'] ?? []))) !== '' ? 'Cc: ' . $cc : null,
            ]);
            $head = "---------- Forwarded message ----------\n" . implode("\n", $lines);

            return [
                'html' => '<p style="margin:0 0 12px;color:#475467;">' . nl2br($e($head), false) . '</p>' . self::styled(HtmlCleaner::fromText($text)),
                'text' => $head . "\n\n" . $text,
            ];
        }

        $intro = ($when ? "On {$when}, " : '') . "{$from} wrote:";

        return [
            'html' => '<p style="margin:0 0 8px;color:#475467;">' . $e($intro) . '</p>'
                . '<blockquote style="margin:0 0 0 4px;padding:0 0 0 12px;border-left:2px solid #D0D5DD;color:#475467;">' . self::styled(HtmlCleaner::fromText($text)) . '</blockquote>',
            'text' => $intro . "\n" . preg_replace('/^/m', '> ', $text),
        ];
    }

    /** "3 Nov 2026 at 10:42" in Lagos time (no intl on the live server) */
    public static function when(?DateTimeInterface $at): ?string
    {
        if (!$at) {
            return null;
        }
        $d = \DateTimeImmutable::createFromInterface($at)->setTimezone(Clock::zone());

        return (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1] . ' ' . $d->format('Y') . ' at ' . $d->format('H:i');
    }

    /** Paragraph spacing written into the tags (mail apps ignore most style sheets) */
    private static function styled(string $html): string
    {
        return str_replace(
            ['<p>', '<blockquote>', '<ul>', '<ol>', '<h2>', '<h3>', '<pre>'],
            [
                '<p style="margin:0 0 12px;">',
                '<blockquote style="margin:0 0 12px 4px;padding:0 0 0 12px;border-left:2px solid #D0D5DD;">',
                '<ul style="margin:0 0 12px;padding-left:24px;">',
                '<ol style="margin:0 0 12px;padding-left:24px;">',
                '<h2 style="margin:0 0 10px;font-size:18px;line-height:24px;">',
                '<h3 style="margin:0 0 8px;font-size:15px;line-height:22px;">',
                '<pre style="margin:0 0 12px;white-space:pre-wrap;font-family:Consolas,Menlo,monospace;font-size:13px;">',
            ],
            $html,
        );
    }

    /** @return array{0: string, 1: string, 2: string} content, file name, MIME type */
    private static function attachment(array $outbox, array $a, int $userId): array
    {
        $name = (string) ($a['name'] ?? 'attachment');
        if (($a['source'] ?? '') === 'upload') {
            try {
                return [Files::get((string) $outbox['id'], (string) $a['key']), $name, (string) ($a['mime'] ?? '') ?: 'application/octet-stream'];
            } catch (MailError) {
                throw new MailSendException("{$name} is no longer in the ERP. Remove it, attach it again, then send.", 'rejected', true);
            }
        }
        try {
            $file = MailFetch::make()->attachment($userId, (int) ($a['attachment_id'] ?? 0));
        } catch (MailError $e) {
            throw new MailSendException("The original's attachment {$name} can no longer be sent ({$e->getMessage()}). Remove it, then send.", 'rejected', true);
        } catch (Throwable) {
            throw new MailSendException("The attachment {$name} could not be read on the mail server. The ERP will try again.", 'temporary', false);
        }

        return [$file['content'], $file['filename'] ?: $name, $file['mime'] ?: 'application/octet-stream'];
    }
}
