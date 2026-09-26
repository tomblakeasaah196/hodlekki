<?php
// /api/profile_api.php

// 1. Core Includes & Headers
require_once '../includes/db.php';
header('Content-Type: application/json');

// 2. Security Check (Must be a logged-in user)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH FULL PROFILE (Full Transparency)
        // =====================================================================================
        case 'fetch_profile':
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'Profile not found.']);
                exit;
            }

            // Security: Never send the password hash to the frontend, even if hidden
            unset($user['password_hash']);

            echo json_encode(['status' => 'success', 'data' => $user]);
            break;

        // =====================================================================================
        // ACTION 2: UPDATE DEMOGRAPHICS (Strictly limited fields)
        // =====================================================================================
        case 'update_profile':
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['physical_address'] ?? '');
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $real_email = trim($_POST['real_email'] ?? '');
            $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
            $marital_status = $_POST['marital_status'] ?? 'Single';
            $wedding_anniversary = !empty($_POST['wedding_anniversary']) ? $_POST['wedding_anniversary'] : null;

            if ($marital_status === 'Single') {
                $wedding_anniversary = null;
            }

            $stmt = $pdo->prepare("
                UPDATE users 
                SET phone = ?, physical_address = ?, latitude = ?, longitude = ?, real_email = ?, dob = ?, marital_status = ?, wedding_anniversary = ? 
                WHERE id = ?
            ");
            $stmt->execute([$phone, $address, $latitude, $longitude, $real_email, $dob, $marital_status, $wedding_anniversary, $user_id]);

            // NOTIFICATION TRIGGER: Alert IDI (Department 1) of the autonomous data update
            $idiStmt = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 1 AND is_active = 1");
            $idi_users = $idiStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($idi_users)) {
                $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$user_id]);
                $uName = $uStmt->fetch(PDO::FETCH_ASSOC);
                $memberName = $uName ? "{$uName['first_name']} {$uName['last_name']}" : "A member";

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Profile Update Alert', ?, '/modules/congregation/index.php')");
                $alertMsg = "{$memberName} has autonomously updated their personal information and location in the member portal.";
                
                foreach($idi_users as $uid) {
                    $notifStmt->execute([$uid, $alertMsg]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Your personal information and location have been updated.']);
            break;

        // =====================================================================================
        // ACTION 3: UPLOAD CROPPED PORTRAIT (Base64 Processing)
        // =====================================================================================
        case 'upload_picture':
            $image_base64 = $_POST['image_base64'] ?? '';
            
            if (empty($image_base64)) {
                echo json_encode(['status' => 'error', 'message' => 'No image data received.']);
                exit;
            }

            // Decode the Base64 image coming from the frontend cropper
            $image_parts = explode(";base64,", $image_base64);
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1];
            $image_base64 = base64_decode($image_parts[1]);

            // Ensure the upload directory exists
            $upload_dir = '../uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Create a unique filename
            $filename = 'profile_' . $user_id . '_' . time() . '.png';
            $filepath = $upload_dir . $filename;

            // Save the file to the server
            if (file_put_contents($filepath, $image_base64)) {
                // Save the path to the database
                $db_path = '/uploads/profiles/' . $filename;
                
                $stmt = $pdo->prepare("UPDATE users SET picture_path = ? WHERE id = ?");
                $stmt->execute([$db_path, $user_id]);

                // Update their current session so the navbar image updates immediately
                $_SESSION['picture_path'] = $db_path;

                echo json_encode(['status' => 'success', 'message' => 'Profile portrait updated successfully!', 'path' => $db_path]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Server failed to save the image.']);
            }
            break;
            
            
        // =====================================================================================
        // ACTION 3B: UPLOAD CROPPED WEDDING PICTURE
        // =====================================================================================
        case 'upload_wedding_picture':
            $image_base64 = $_POST['image_base64'] ?? '';
            
            if (empty($image_base64)) {
                echo json_encode(['status' => 'error', 'message' => 'No image data received.']);
                exit;
            }

            // Decode the Base64 image
            $image_parts = explode(";base64,", $image_base64);
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1];
            $image_base64 = base64_decode($image_parts[1]);

            // Ensure the upload directory exists
            $upload_dir = '../uploads/weddings/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Create a unique filename
            $filename = 'wedding_' . $user_id . '_' . time() . '.png';
            $filepath = $upload_dir . $filename;

            // Save the file to the server
            if (file_put_contents($filepath, $image_base64)) {
                // Save the path to the database
                $db_path = '/uploads/weddings/' . $filename;
                
                $stmt = $pdo->prepare("UPDATE users SET wedding_picture_path = ? WHERE id = ?");
                $stmt->execute([$db_path, $user_id]);

                // NOTIFICATION TRIGGER: Alert Charis Leadership of the new media
                $charisStmt = $pdo->query("
                    SELECT ud.user_id FROM user_departments ud 
                    JOIN departments d ON ud.department_id = d.id 
                    WHERE d.name LIKE '%Charis%' AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
                ");
                $charis_leaders = $charisStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($charis_leaders)) {
                    $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    $uStmt->execute([$user_id]);
                    $uName = $uStmt->fetch(PDO::FETCH_ASSOC);
                    $memberName = $uName ? "{$uName['first_name']} {$uName['last_name']}" : "A member";

                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Wedding Media', ?, '/modules/charis/index.php')");
                    $alertMsg = "{$memberName} has uploaded a new wedding picture for their anniversary celebration.";
                    
                    foreach($charis_leaders as $leader_id) {
                        $notifStmt->execute([$leader_id, $alertMsg]);
                    }
                }

                echo json_encode(['status' => 'success', 'message' => 'Wedding picture uploaded successfully!', 'path' => $db_path]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Server failed to save the image.']);
            }
            break;

        // =====================================================================================
        // ACTION 4: REQUEST SECURE NAME CHANGE (Support Ticket System)
        // =====================================================================================
        case 'request_name_change':
            $correct_name = trim($_POST['correct_name'] ?? '');
            $reason = trim($_POST['reason'] ?? '');

            if (empty($correct_name)) {
                echo json_encode(['status' => 'error', 'message' => 'You must provide the correct spelling of your name.']);
                exit;
            }

            // We generate a formatted request and push it into the existing "suggestions" table 
            // so Admin/Charis can review it on the Command Center.
            $content = "OFFICIAL NAME CHANGE REQUEST:\nRequested Name: {$correct_name}\nReason: {$reason}\n\nPlease verify and update this user's profile.";
            
            $stmt = $pdo->prepare("INSERT INTO suggestions (user_id, content, priority, status) VALUES (?, ?, 10, 'New')");
            $stmt->execute([$user_id, $content]);

            // NOTIFICATION TRIGGER: Alert Admins to action the name change
            $adminStmt = $pdo->query("
                SELECT user_id FROM user_roles 
                JOIN roles ON user_roles.role_id = roles.id 
                WHERE roles.role_name IN ('Super_Admin', 'Resident_Pastor')
            ");
            $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($admins)) {
                $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$user_id]);
                $uName = $uStmt->fetch(PDO::FETCH_ASSOC);
                $memberName = $uName ? "{$uName['first_name']} {$uName['last_name']}" : "A member";

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Name Change Request', ?, '/modules/pastoral/index.php')");
                $alertMsg = "{$memberName} has submitted an official name change request. Please review the pastoral desk suggestions.";
                
                foreach($admins as $admin_id) {
                    $notifStmt->execute([$admin_id, $alertMsg]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Name change request securely sent to the Church Admin for review.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Profile API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred while updating your profile.']);
}
?>