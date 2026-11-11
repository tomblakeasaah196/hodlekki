<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Db;
use Throwable;

/** Mailbox administration goes to security_audit_log, as the Alerts and Security Centres do. Never a password. */
final class Audit
{
    public static function log(string $action, string $details, ?int $actorId = null, ?int $targetUserId = null): void
    {
        try {
            if (!function_exists('security_log')) {
                $file = dirname(__DIR__, 2) . '/security_helpers.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
            if (function_exists('security_log')) {
                security_log(Db::pdo(), $action, $targetUserId, $details, $actorId, $actorId === null ? 'Mail (cron)' : null);
            }
        } catch (Throwable $e) {
            error_log('Mail: audit not written: ' . $e->getMessage());
        }
    }
}
