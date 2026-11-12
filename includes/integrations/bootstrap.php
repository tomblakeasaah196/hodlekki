<?php
// /includes/integrations/bootstrap.php
/**
 * Outside services the ERP connects to (docs/INTEGRATIONS.md): first Google, for Trainings' live classes (Calendar
 * events with a Meet link, Meet attendance). Classes live under HOD\Integrations\ in this folder.
 *
 *   require_once __DIR__ . '/../../includes/integrations/bootstrap.php';
 *   HOD\Integrations\Google\Google::status();
 *
 * Needs the site's $pdo (includes/db.php) unless a test hands Settings its own connection.
 */

require_once dirname(__DIR__) . '/site_url.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'HOD\\Integrations\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
