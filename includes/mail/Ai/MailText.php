<?php

namespace HOD\Mail\Ai;

use HOD\Mail\Db;
use HOD\Mail\MailFetch;
use HOD\Mail\Mime\Text;
use Throwable;

/**
 * The words of a message as AI reads them (ported from Courrier): plain text, the quoted history stripped (the
 * conversation gives it already), never attachments. Privacy hides phone numbers and email addresses afterwards.
 */
final class MailText
{
    public static function ofMessage(int $userId, array $message, bool $live = false): string
    {
        $text = '';
        $html = null;
        if (($message['body_state'] ?? '') === 'cached') {
            $row = Db::one('SELECT body_text, body_html FROM mail_messages WHERE id = ?', [$message['id']]);
            $text = (string) ($row['body_text'] ?? '');
            $html = $row['body_html'] ?? null;
        } elseif ($live) {
            try {
                $body = MailFetch::make()->body($userId, $message);
                $text = (string) $body['text'];
                $html = $body['html'];
            } catch (Throwable $e) {
                error_log('Mail AI: body not read from the server (message ' . (int) $message['id'] . ', ' . get_class($e) . ')');
            }
        }
        if (trim($text) === '' && is_string($html) && $html !== '') {
            $text = self::fromHtml($html);
        }
        $text = self::withoutQuotes($text);

        return $text !== '' ? $text : trim((string) ($message['snippet'] ?? ''));
    }

    public static function withoutQuotes(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $cuts = [
            '/^[ \t]*On .{0,200}wrote:[ \t]*$/mi',
            '/^[ \t]*-{2,}[ \t]*(Original Message|Forwarded message)[ \t]*-{2,}/mi',
            '/^[ \t]*_{10,}[ \t]*$/m',
            '/^[ \t]*From[ \t]*:[ \t].+\n(?:.+\n){0,3}?[ \t]*(Sent|Date)[ \t]*:[ \t]/mi',
        ];
        foreach ($cuts as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
                $text = substr($text, 0, $m[0][1]);
            }
        }
        $lines = array_filter(explode("\n", $text), fn ($l) => !str_starts_with(ltrim($l), '>'));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<blockquote\b[^>]*>.*?</blockquote\s*>#is', ' ', $html) ?? $html;
        foreach (['gmail_quote', 'divRplyFwdMsg', 'moz-cite-prefix', 'yahoo_quoted'] as $marker) {
            $at = stripos($html, $marker);
            if ($at !== false) {
                $tag = strrpos(substr($html, 0, $at), '<');
                $html = substr($html, 0, $tag === false ? $at : $tag);
            }
        }

        return Text::fromHtml($html);
    }

    public static function clip(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars)) . ' …' : $text;
    }
}
