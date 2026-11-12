<?php
// /includes/mail/bootstrap.php

/**
 * Mail: the only entry point to the engine (docs/MAIL.md). Pages, endpoints,
 * crons and tests require this after includes/db.php.
 *
 * It maps HOD\Mail\Foo\Bar to includes/mail/Foo/Bar.php. The engine is
 * class-based (ported from bureau's Courrier); this loader answers only for
 * the HOD\Mail\ prefix, and is used instead of Composer's PSR-4 because the
 * deploy copies the tree before `composer install` runs. includes/mail/ is
 * refused over HTTP by its own .htaccess.
 *
 * Every database path asks mail_ready() first: the tree reaches production
 * before db/migrate.php, so nothing here may fail before the tables exist.
 */

if (defined('HOD_MAIL_BOOTSTRAP')) {
    return;
}
define('HOD_MAIL_BOOTSTRAP', true);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'HOD\\Mail\\', 9) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/site_url.php';
require_once dirname(__DIR__) . '/permissions.php';
require_once __DIR__ . '/functions.php';
// The reader's partials print through the shared UI helpers (ui_e, ui_icon, ui_badge, ui_info), also from the API
require_once dirname(__DIR__) . '/ui/bootstrap.php';
