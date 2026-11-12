<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Config;

/**
 * One line in ~/logs/hod-mail.log for a cron run that had problems (cron/mail_minute.php sends its output to
 * /dev/null, so without this a failing step leaves no trace an admin can read). Runs without problems write nothing.
 * The line also goes to PHP's error_log. MAIL_LOG_FILE moves the file.
 *
 *   2026-11-12 06:01:00 mail_minute: quota: cPanel gave no readable answer to list_pops_with_disk: Failed to execute …
 */
final class CronLog
{
    /** The log file, or null when no home folder is known (then error_log only) */
    public static function path(): ?string
    {
        $configured = trim((string) Config::get('log.file', ''));
        if ($configured !== '') {
            return $configured;
        }
        $home = (string) (getenv('HOME') ?: '');
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = (string) ((posix_getpwuid(posix_geteuid()) ?: [])['dir'] ?? '');
        }

        return $home !== '' ? rtrim($home, '/\\') . '/logs/hod-mail.log' : null;
    }

    /**
     * @param list<string> $problems one short phrase each ("quota: …", "sync 3: …")
     * @return string|null the line written, or null when there was nothing to write
     */
    public static function problems(string $script, array $problems): ?string
    {
        $problems = array_values(array_filter(array_map(
            static fn ($p): string => trim((string) preg_replace('/\s+/', ' ', (string) $p)),
            $problems,
        ), 'strlen'));
        if (!$problems) {
            return null;
        }
        $line = Clock::now()->format('Y-m-d H:i:s') . ' ' . $script . ': ' . mb_substr(implode('; ', $problems), 0, 1000) . "\n";
        error_log('Mail cron: ' . rtrim($line));
        $path = self::path();
        if ($path !== null) {
            $dir = dirname($path);
            if (is_dir($dir) || @mkdir($dir, 0750, true)) {
                @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
            }
        }

        return $line;
    }
}
