<?php
// /includes/training_live/bootstrap.php
/**
 * Trainings' live classes (docs/INTEGRATIONS.md, "Live attendance"): Meet links on live items, the Join class log,
 * and attendance from Meet's participant list. Classes live under HOD\TrainingLive\ in this folder; Google itself
 * is HOD\Integrations\Google (includes/integrations).
 */

require_once dirname(__DIR__) . '/integrations/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'HOD\\TrainingLive\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
