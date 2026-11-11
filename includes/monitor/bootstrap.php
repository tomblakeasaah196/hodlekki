<?php
// /includes/monitor/bootstrap.php
/**
 * Everything the monitor's API, cron and CLI need, in one require (after
 * includes/db.php, which installs the capture and owns $pdo). Pages that only
 * report do not need this: capture.php and browser.php are enough.
 *
 *   monitor_user_can($pdo, 'monitor.view' | 'monitor.manage')
 *       THE access check. Super_Admin (live roles, never $_SESSION['active_role'])
 *       until the permissions module lands; then it defers to can() — one swap point.
 */

require_once __DIR__ . '/capture.php';
require_once __DIR__ . '/keys.php';
require_once __DIR__ . '/redact.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/logscan.php';
require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/rum.php';
require_once __DIR__ . '/intake.php';
require_once __DIR__ . '/probe.php';
require_once __DIR__ . '/brief.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/wall.php';

if (!function_exists('monitor_user_can')) {
    function monitor_user_can(PDO $pdo, string $ability, ?int $userId = null): bool
    {
        $userId = $userId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);
        if ($userId <= 0) {
            return false;
        }
        // The permissions module (erp-permissions) will define can(); until then,
        // Super_Admin only, from the live roles table.
        if (function_exists('can')) {
            return (bool) can($ability);
        }
        if (!function_exists('security_is_super_admin')) {
            require_once dirname(__DIR__) . '/security_helpers.php';
        }

        return security_is_super_admin($pdo, $userId);
    }
}
