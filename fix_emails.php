<?php
// fix_emails.php
require_once 'includes/db.php';

try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, email FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assigned_emails = [];
    $updated_count = 0;

    $getShortest = function($nameStr) {
        $parts = preg_split('/[\s\-]+/', trim($nameStr));
        $shortest = '';
        foreach ($parts as $part) {
            $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $part));
            if (empty($clean)) continue;
            if ($shortest === '' || strlen($clean) < strlen($shortest)) $shortest = $clean;
        }
        return $shortest;
    };

    $pdo->beginTransaction();

    foreach ($users as $user) {
        // Safely move existing personal emails to the new real_email column
        $real_email = null;
        if (!empty($user['email']) && strpos($user['email'], '@hodlc.com') === false) {
            $real_email = $user['email'];
        }

        // Generate the shortest name combo
        $short_first = $getShortest($user['first_name']);
        $short_last = $getShortest($user['last_name']);

        $prefix = '';
        if (!empty($short_first) && !empty($short_last)) {
            $prefix = $short_first . '.' . $short_last;
            if (strlen($prefix) > 20) $prefix = $short_first;
        } else {
            $prefix = $short_first ?: $short_last;
        }

        // Ensure absolute uniqueness across the batch
        if (!empty($prefix)) {
            $base_email = $prefix . '@hodlc.com';
            $final_email = $base_email;
            $counter = 1;
            
            while (in_array($final_email, $assigned_emails)) {
                $final_email = $prefix . $counter . '@hodlc.com';
                $counter++;
            }
            
            $assigned_emails[] = $final_email; // Track it so the next loop knows it's taken

            // Update the database row
            $update = $pdo->prepare("UPDATE users SET email = ?, real_email = ? WHERE id = ?");
            $update->execute([$final_email, $real_email, $user['id']]);
            $updated_count++;
        }
    }

    $pdo->commit();
    echo "<h1>Success!</h1><p>{$updated_count} user emails have been standardized to @hodlc.com and real emails safely migrated.</p>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage();
}
?>