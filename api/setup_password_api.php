<?php
// /api/setup_password_api.php
require_once '../includes/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ==============================================================================
        // ACTION 1: LOOKUP SYSTEM EMAIL VIA PHONE & FIRST NAME
        // ==============================================================================
        case 'lookup_email':
            $fname = trim($_POST['first_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (empty($fname) || empty($phone)) {
                echo json_encode(['status' => 'error', 'message' => 'Please provide both your First Name and Phone Number.']);
                exit;
            }

            // Clean the phone number of spaces, brackets, or dashes for DB matching
            $cleanPhone = preg_replace('/[\s\-\(\)]/', '', $phone);

            // Using LIKE for phone variations, and checking if the entered name matches EITHER their first or last name
            $stmt = $pdo->prepare("SELECT email FROM users WHERE phone LIKE ? AND (first_name = ? OR last_name = ?) LIMIT 1");
            
            // Note: We pass $fname twice because it checks against both columns
            $stmt->execute(['%' . ltrim($cleanPhone, '0+') . '%', $fname, $fname]);
            $user = $stmt->fetch();

            if ($user && !empty($user['email'])) {
                echo json_encode([
                    'status' => 'success', 
                    'email' => $user['email']
                ]);
            } else {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'We could not find an account matching that name and phone number.'
                ]);
            }
            break;

        // ==============================================================================
        // ACTION 2: SETUP/UPDATE PASSWORD
        // ==============================================================================
        case 'setup_password':
            $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // 1. Basic empty checks
            if (empty($email) || empty($new_password) || empty($confirm_password)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
                exit;
            }

            // 2. Password Match Check
            if ($new_password !== $confirm_password) {
                echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
                exit;
            }

            // 3. Strict Complexity Checks (8 chars, 1 uppercase, 1 number)
            if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long, contain at least one uppercase letter, and one number.']);
                exit;
            }

            // 4. Verify the email exists in our db
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // 5. Hash the new password securely
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

                // 6. Update the user record
                $update = $pdo->prepare("UPDATE users SET password_hash = :pass WHERE id = :id");
                $update->execute(['pass' => $hashed_password, 'id' => $user['id']]);

                echo json_encode(['status' => 'success', 'message' => 'Password updated! Redirecting to login...']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Email address not found in our records.']);
            }
            break;

        // ==============================================================================
        // DEFAULT FALLBACK
        // ==============================================================================
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Security Setup API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'System error. Please contact technical support.']);
}
?>