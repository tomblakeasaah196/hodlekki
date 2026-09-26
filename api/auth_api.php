<?php
// /api/auth_api.php

// 1. Include the DB connection (which also starts the session)
require_once '../includes/db.php';

// 2. Tell the browser we are returning JSON
header('Content-Type: application/json');

// 3. Ensure this is only accessed via a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// 4. Capture and sanitize inputs
$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
$password = $_POST['password'] ?? '';

// 5. Basic validation
if (empty($email) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide both email and password.']);
    exit;
}

try {
    // 6. Fetch user from the database
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, picture_path, password_hash FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    // 7. Verify user exists AND password is correct
    if ($user && password_verify($password, $user['password_hash'])) {
        
        // 8. Fetch all roles assigned to this user
        $roleStmt = $pdo->prepare("
            SELECT r.id, r.role_name 
            FROM roles r
            JOIN user_roles ur ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
        ");
        $roleStmt->execute(['user_id' => $user['id']]);
        $roles = $roleStmt->fetchAll();

        // 9. Store essential user data in the secure session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['picture_path'] = $user['picture_path'];
        
        // 10. Handle RBAC (Role-Based Access Control) setup
        if (count($roles) > 0) {
            $_SESSION['roles'] = $roles; // Store all roles in case they have multiple
            $_SESSION['active_role'] = $roles[0]['role_name']; // Default to primary
            $_SESSION['active_role_id'] = $roles[0]['id'];
        } else {
            $_SESSION['roles'] = [];
            $_SESSION['active_role'] = 'Member'; // Fallback
            $_SESSION['active_role_id'] = null;
        }

        // 11. Determine Redirect URL (Dashboard Access Matrix)
        $has_dashboard_access = false;
        $dashboard_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'];

        // Check if they have a qualifying role
        if (!empty($roles)) {
            foreach ($roles as $r) {
                if (in_array($r['role_name'], $dashboard_roles)) {
                    $has_dashboard_access = true;
                    break;
                }
            }
        }

        // Check if they are in the IDI department (ID: 1)
        if (!$has_dashboard_access) {
            $deptCheck = $pdo->prepare("SELECT 1 FROM user_departments WHERE user_id = ? AND department_id = 1 AND is_active = 1");
            $deptCheck->execute([$user['id']]);
            if ($deptCheck->fetch()) {
                $has_dashboard_access = true;
            }
        }

        // Set the final destination based on their access
        $target_redirect = $has_dashboard_access ? '/index.php' : '/modules/member_portal/index.php';

        // 12. Return success payload with dynamic redirect
        echo json_encode([
            'status' => 'success', 
            'message' => 'Login successful! Setting up workspace...',
            'redirect' => $target_redirect
        ]);

    } else {
        // Return generic error to prevent email enumeration attacks
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
    }

} catch (PDOException $e) {
    // Log the actual error internally, but return a clean message to the user
    error_log("Login Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again later.']);
}
?>