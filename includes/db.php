<?php
// /includes/db.php

// 1. Start the session globally if it hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Native .env loader function (Bypasses Composer vendor directory entirely)
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$name] = $value;
        }
    }
}

// Load your variables using the absolute server root path
loadEnv($_SERVER['DOCUMENT_ROOT'] . '/.env');

// 3. Set the default timezone for Household of David Lekki Centre (PHP Level)
date_default_timezone_set('Africa/Lagos');

// 4. Database Credentials (Pulled securely from .env)
$host     = $_ENV['DB_HOST'] ?? 'localhost'; 
$dbname   = $_ENV['DB_NAME'] ?? '';
$username = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASS'] ?? ''; 

// 5. PDO Connection String
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

// 6. Security & Error Handling Options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Stop and throw error if SQL fails
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return rows as associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Native prepared statements to prevent SQL injection
];

// 7. Establish the Connection
try {
    $pdo = new PDO($dsn, $username, $password, $options);
    
    // 8. Force MySQL to operate in West Africa Time (Lagos / UTC+1)
    $pdo->exec("SET time_zone = '+01:00';");
    
} catch (\PDOException $e) {
    // A cron job (CLI) must fail loudly: the JSON below went to stdout with exit
    // code 0, so a worker whose database connection failed looked like a run
    // that did nothing, every minute, with nothing in any log.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Database Connection Failed: ' . $e->getMessage() . "\n");
        error_log('CLI: database connection failed: ' . $e->getMessage());
        exit(1);
    }
    die(json_encode([
        "status" => "error", 
        "message" => "Database Connection Failed: Check your configuration or credentials."
    ]));
}

// 9. Account security gate.
//    Hooking this in here (rather than in header.php) means a single call
//    covers every authenticated page AND every api/*.php endpoint: when an
//    admin suspends, revokes or force-signs-out an account in the Security
//    Centre, the very next request from that browser loses its session.
//    It never redirects or prints — it only tears the session down, so
//    header.php bounces the user to /auth/login.php and API endpoints fall
//    through to their own "Unauthorized" JSON. CLI (cron) is skipped.
require_once __DIR__ . '/security_helpers.php';
security_enforce_session($pdo);

// 10. Web Push dispatcher.
//     Same reasoning as the gate above: one hook here covers every page and
//     every api/*.php endpoint, so the ~60 places that INSERT into
//     system_notifications deliver a phone notification without knowing push
//     exists. It runs NO query during the request — it only registers a
//     shutdown function, and only on the request methods that write
//     notifications at all. The actual sending happens after
//     fastcgi_finish_request(), so nothing is added to any page's load time,
//     and cron/push_worker.php picks up anything this misses.
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/push_dispatch.php';
push_register_dispatcher($pdo);
?>
