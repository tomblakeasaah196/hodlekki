<?php

namespace HOD\Mail\Read;

/**
 * The Mail page's HTML pieces (includes/mail/Read/partials/*.php): the same PHP draws the first screen
 * (modules/mail/index.php) and the pieces the page script swaps in later (api/mail_api.php), so a list row or
 * a message looks the same either way. Each partial escapes what it prints (ui_e) and answers 404 when reached
 * by URL (includes/ is also refused by the root .htaccess).
 */
final class Partial
{
    public static function render(string $name, array $vars = []): string
    {
        if (!preg_match('/^[a-z_]+$/', $name)) {
            return '';
        }
        $file = __DIR__ . '/partials/' . $name . '.php';
        if (!is_file($file)) {
            return '';
        }
        if (!defined('HOD_MAIL_READ_PARTIAL')) {
            define('HOD_MAIL_READ_PARTIAL', true);
        }
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                include $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Later pieces of the page (the composer in PR 3, the team bar in PR 4) plug in as includes/mail/mounts/<name>.php.
     * Until a piece exists, nothing that leads to it is drawn: no Reply button without a composer.
     */
    public static function mounted(string $name): bool
    {
        return preg_match('/^[a-z_]+$/', $name) === 1 && is_file(dirname(__DIR__) . '/mounts/' . $name . '.php');
    }

    /** A mount's markup, or '' while it does not exist */
    public static function mount(string $name, array $vars = []): string
    {
        if (!self::mounted($name)) {
            return '';
        }
        if (!defined('HOD_MAIL_READ_PARTIAL')) {
            define('HOD_MAIL_READ_PARTIAL', true);
        }
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                include $__file;
            })(dirname(__DIR__) . '/mounts/' . $name . '.php', $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
