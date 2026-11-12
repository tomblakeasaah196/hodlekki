<?php

namespace HOD\Mail\Ai;

use HOD\Mail\Clock;
use HOD\Mail\MailError;
use HOD\Mail\MailSettings;

/** AI requests per person per hour (ai_config 'limits'), kept in mail_settings `ai.usage.<user id>` */
final class Limits
{
    public const KINDS = ['write', 'summary'];

    public const MESSAGES = [
        'write' => 'You asked for many drafts this hour. Try again later, or write it yourself.',
        'summary' => 'You asked for many summaries this hour. Try again later.',
    ];

    public static function allowed(int $userId, string $kind): bool
    {
        return self::used($userId, $kind) < self::limit($kind);
    }

    /** Count one; throws MailError 429 when the hour's limit is reached */
    public static function take(int $userId, string $kind, ?string $field = null): void
    {
        $now = Clock::now()->getTimestamp();
        $usage = self::usage($userId, $now);
        if (count($usage[$kind] ?? []) >= self::limit($kind)) {
            throw new MailError(self::MESSAGES[$kind] ?? self::MESSAGES['write'], $field, 429);
        }
        $usage[$kind][] = $now;
        MailSettings::set('ai.usage.' . $userId, $usage, $userId);
    }

    public static function used(int $userId, string $kind): int
    {
        return count(self::usage($userId, Clock::now()->getTimestamp())[$kind] ?? []);
    }

    public static function limit(string $kind): int
    {
        return max(0, (int) Ai::config('limits.' . $kind, 0));
    }

    private static function usage(int $userId, int $now): array
    {
        $stored = MailSettings::get('ai.usage.' . $userId);
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $kind => $times) {
            if (!in_array($kind, self::KINDS, true) || !is_array($times)) {
                continue;
            }
            $recent = array_values(array_filter(array_map('intval', $times), fn ($t) => $t > $now - 3600 && $t <= $now + 60));
            if ($recent) {
                $out[$kind] = $recent;
            }
        }

        return $out;
    }
}
