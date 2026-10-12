<?php
// /includes/push_dispatch.php
/**
 * ============================================================================
 * AFTER-RESPONSE PUSH DISPATCHER
 * ----------------------------------------------------------------------------
 * Why this exists: cron/push_worker.php runs once a minute, which is a long
 * time to wait for "the pastor just assigned you a visit". This hook sends
 * queued pushes the instant the request that created them is finished.
 *
 * What it costs the request: nothing. Registration adds no query. The work
 * runs in a shutdown function, AFTER fastcgi_finish_request() has already
 * handed the response to the browser and the session lock has been released,
 * so no user ever waits on a push service.
 *
 * What stops it running away:
 *   - only on POST / api requests — the only things that write notifications;
 *   - push_run() takes a MySQL named lock, so exactly one dispatcher (or the
 *     cron worker) is ever sending, no matter how busy the site is;
 *   - a hard time budget, and a push_has_work() probe before anything else;
 *   - `inline_dispatch` in push_config turns it off without a deploy.
 *
 * Every path is wrapped in catch (Throwable). A failure here must never be
 * visible on a page that has, by this point, already been delivered.
 * ============================================================================
 */

if (!defined('PUSH_DISPATCH_LOADED')) {
    define('PUSH_DISPATCH_LOADED', true);

    /**
     * Should this request look for work when it finishes?
     * Kept deliberately narrow: every notification in this codebase is
     * written by a POST to api/*.php (or by cron, which has its own worker).
     */
    function push_request_may_dispatch(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
            return true;
        }

        // A handful of endpoints still notify from a GET.
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return str_starts_with($uri, '/api/');
    }

    /** Hand the response to the browser so the work below is invisible. */
    function push_release_response(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        // Releasing the session lock matters more than it looks: without it
        // the member's next click queues behind this dispatcher.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }

    /**
     * Run the queue once, after the response. Safe to call from anywhere.
     * Returns the push_run() summary, or null when there was nothing to do.
     */
    function push_dispatch_now(PDO $pdo, float $budgetSeconds = 6.0): ?array
    {
        try {
            if (!push_table_exists($pdo, 'push_queue')) {
                return null;                       // migration has not run yet
            }
            if (push_config_get($pdo, 'inline_dispatch', '1') !== '1') {
                return null;
            }
            if (!push_has_work($pdo)) {
                return null;
            }

            return push_run($pdo, 40, $budgetSeconds);
        } catch (Throwable $e) {
            error_log('[push] inline dispatch failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Arm the hook for this request. Called at the bottom of includes/db.php,
     * which is the one file every page and endpoint already requires.
     */
    function push_register_dispatcher(PDO $pdo): void
    {
        if (!push_request_may_dispatch()) {
            return;
        }

        register_shutdown_function(static function () use ($pdo) {
            try {
                // A fatal error mid-request still reaches shutdown functions.
                // Sending push notifications off the back of a crashed request
                // is not worth the risk of compounding it.
                $fatal = error_get_last();
                if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    return;
                }

                push_release_response();

                ignore_user_abort(true);
                @set_time_limit(25);

                push_dispatch_now($pdo);
            } catch (Throwable $e) {
                error_log('[push] dispatcher shutdown hook failed: ' . $e->getMessage());
            }
        });
    }
}
