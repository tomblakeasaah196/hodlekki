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
    die(json_encode([
        "status" => "error", 
        "message" => "Database Connection Failed: Check your configuration or credentials."
    ]));
}
?>
