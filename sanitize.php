<?php
// /sanitize.php

require_once 'includes/db.php'; // Adjust path if your db.php is elsewhere

echo "<h2>Starting Database Sanitization...</h2>";

try {
    // 1. Find everyone who has a Birthday trapped in their comments
    $stmt = $pdo->query("SELECT id, first_name, last_name, comments FROM users WHERE comments LIKE '%Birthday:%'");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $success_count = 0;

    foreach ($users as $user) {
        $comment = $user['comments'];
        
        // 2. Extract the date text (e.g., "25th Dec") using Regex
        if (preg_match('/(?:\|\s*)?Birthday:\s*([a-zA-Z0-9\s]+)/i', $comment, $matches)) {
            $raw_date = trim($matches[1]);
            
            // 3. Remove the birthday text from the comment to clean it up
            $clean_comment = preg_replace('/(?:\|\s*)?Birthday:\s*[a-zA-Z0-9\s]+/i', '', $comment);
            $clean_comment = trim($clean_comment);
            
            // If the comment is now empty, set it to NULL
            if ($clean_comment === '') {
                $clean_comment = null;
            }

            // 4. Convert "25th Dec" into a UNIX timestamp
            $timestamp = strtotime($raw_date);
            
            if ($timestamp) {
                // 5. Force the Dummy Year "2000" and format for MySQL (2000-MM-DD)
                $formatted_dob = date('2000-m-d', $timestamp);
                
                // 6. Update the database record!
                $updateStmt = $pdo->prepare("UPDATE users SET dob = ?, comments = ? WHERE id = ?");
                $updateStmt->execute([$formatted_dob, $clean_comment, $user['id']]);
                
                echo "<p style='color: green;'>✅ Updated {$user['first_name']} {$user['last_name']}: Parsed '$raw_date' to <b>$formatted_dob</b></p>";
                $success_count++;
            } else {
                echo "<p style='color: red;'>❌ Failed to parse date for {$user['first_name']} {$user['last_name']}: '$raw_date'</p>";
            }
        }
    }

    echo "<h3>Sanitization Complete! Successfully formatted and moved $success_count birthdays.</h3>";
    echo "<p><b>You can now safely delete this sanitize.php file from your server.</b></p>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>