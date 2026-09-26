<?php
// /api/cron_sync_analytics.php

// 1. Strictly restrict this script so it cannot be run from a web browser.
// It must only be executed via the server's command line (CLI) by the Cron daemon.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden. This script can only be run via cron.");
}

require_once __DIR__ . '/../includes/db.php';

try {
    // 2. The highly-optimized bulk update query
    $sql = "
        UPDATE sermons s
        LEFT JOIN (
            SELECT sermon_id,
                   SUM(IF(action_type = 'view', 1, 0)) as v_count,
                   SUM(IF(action_type = 'play', 1, 0)) as p_count,
                   SUM(IF(action_type = 'download', 1, 0)) as d_count
            FROM sermon_analytics_logs
            GROUP BY sermon_id
        ) as stats ON s.id = stats.sermon_id
        SET s.view_count = COALESCE(stats.v_count, 0),
            s.play_count = COALESCE(stats.p_count, 0),
            s.audio_downloads = COALESCE(stats.d_count, 0);
    ";

    $pdo->exec($sql);
    
    echo "Analytics synchronized successfully at " . date('Y-m-d H:i:s') . "\n";

} catch (PDOException $e) {
    error_log("Cron Analytics Sync Error: " . $e->getMessage());
    echo "Error: Analytics sync failed.\n";
}
?>