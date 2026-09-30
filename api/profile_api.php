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

        // =====================================================================================
        // ACTION 5: SECURITY SNAPSHOT (last sign-in, password age, live devices)
        // =====================================================================================
        case 'fetch_security':
            $overview = [
                'last_login_at'       => null,
                'last_login_ip'       => null,
                'password_changed_at' => null,
                'must_change_password'=> false,
                'active_sessions'     => 0,
                'sessions'            => [],
                'rules'               => security_password_rules(),
                'min_length'          => SECURITY_MIN_PASSWORD_LENGTH,
                'recent'              => [],
            ];

            if (security_schema_ready($pdo)) {
                $stmt = $pdo->prepare("
                    SELECT last_login_at, last_login_ip, password_changed_at, must_change_password
                      FROM users WHERE id = ?
                ");
                $stmt->execute([$user_id]);
                $sec = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $overview['last_login_at']        = $sec['last_login_at'] ?? null;
                $overview['last_login_ip']        = $sec['last_login_ip'] ?? null;
                $overview['password_changed_at']  = $sec['password_changed_at'] ?? null;
                $overview['must_change_password'] = (int) ($sec['must_change_password'] ?? 0) === 1;

                $sessStmt = $pdo->prepare("
                    SELECT id, ip_address, user_agent, created_at, last_seen_at, session_hash
                      FROM user_sessions
                     WHERE user_id = ? AND revoked_at IS NULL
                       AND last_seen_at >= (NOW() - INTERVAL 30 DAY)
                     ORDER BY last_seen_at DESC
                     LIMIT 25
                ");
                $sessStmt->execute([$user_id]);
                $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $currentHash = security_current_session_hash();
                $overview['active_sessions'] = count($sessions);
                $overview['sessions'] = array_map(static function (array $s) use ($currentHash) {
                    return [
                        'device'       => security_describe_device($s['user_agent'] ?? ''),
                        'ip_address'   => $s['ip_address'],
                        'created_at'   => $s['created_at'],
                        'last_seen_at' => $s['last_seen_at'],
                        'is_current'   => hash_equals((string) $s['session_hash'], (string) $currentHash),
                    ];
                }, $sessions);

                $logStmt = $pdo->prepare("
                    SELECT action, details, ip_address, created_at
                      FROM security_audit_log
                     WHERE target_user_id = ?
                     ORDER BY created_at DESC
                     LIMIT 8
                ");
                $logStmt->execute([$user_id]);
                $overview['recent'] = $logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            echo json_encode(['status' => 'success', 'data' => $overview]);
            break;

        // =====================================================================================
        // ACTION 6: CHANGE MY OWN PASSWORD (current password required)
        // =====================================================================================
        case 'change_password':
            $current_password = (string) ($_POST['current_password'] ?? '');
            $new_password     = (string) ($_POST['new_password'] ?? '');
            $confirm_password = (string) ($_POST['confirm_password'] ?? '');
            $sign_out_others  = !empty($_POST['sign_out_others']);

            if ($current_password === '' || $new_password === '' || $confirm_password === '') {
                echo json_encode(['status' => 'error', 'message' => 'Please fill in your current password, the new password, and the confirmation.']);
                exit;
            }

            if (!hash_equals($new_password, $confirm_password)) {
                echo json_encode(['status' => 'error', 'message' => 'The new password and its confirmation do not match.']);
                exit;
            }

            // Throttle: stop someone using a hijacked session to brute-force the
            // current password out of an unattended browser.
            $failures = security_recent_failure_count(
                $pdo,
                (int) $user_id,
                'password_change',
                SECURITY_PASSWORD_CHANGE_WINDOW_MINUTES
            );
            if ($failures >= SECURITY_MAX_PASSWORD_CHANGE_FAILURES) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Too many incorrect attempts at your current password. '
                               . 'Please wait ' . SECURITY_PASSWORD_CHANGE_WINDOW_MINUTES . ' minutes and try again.',
                ]);
                exit;
            }

            $uStmt = $pdo->prepare("SELECT id, first_name, last_name, email, password_hash FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$user_id]);
            $me = $uStmt->fetch(PDO::FETCH_ASSOC);

            if (!$me) {
                echo json_encode(['status' => 'error', 'message' => 'Your account could not be found.']);
                exit;
            }

            if (empty($me['password_hash']) || !password_verify($current_password, $me['password_hash'])) {
                security_record_attempt($pdo, (int) $user_id, (string) $me['email'], false, 'wrong_current_password', 'password_change');
                security_log($pdo, 'password_change_failed', (int) $user_id, 'Incorrect current password supplied.');
                echo json_encode(['status' => 'error', 'message' => 'That is not your current password.']);
                exit;
            }

            if (password_verify($new_password, $me['password_hash'])) {
                echo json_encode(['status' => 'error', 'message' => 'Your new password must be different from your current one.']);
                exit;
            }

            $problems = security_password_problems($new_password, $me);
            if (!empty($problems)) {
                echo json_encode(['status' => 'error', 'message' => 'Password rejected: ' . implode(' ', $problems)]);
                exit;
            }

            $hash = password_hash($new_password, PASSWORD_BCRYPT);

            if (security_schema_ready($pdo)) {
                $pdo->prepare("
                    UPDATE users
                       SET password_hash = ?,
                           password_changed_at = NOW(),
                           must_change_password = 0,
                           failed_login_count = 0,
                           locked_until = NULL
                     WHERE id = ?
                ")->execute([$hash, $user_id]);
            } else {
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $user_id]);
            }

            $_SESSION['must_change_password'] = false;

            // Optionally boot every other device, keeping this browser signed in.
            $killed = 0;
            if ($sign_out_others) {
                $killed = security_revoke_sessions(
                    $pdo,
                    (int) $user_id,
                    (int) $user_id,
                    'Signed out after a password change',
                    security_current_session_hash()
                );
            }

            security_record_attempt($pdo, (int) $user_id, (string) $me['email'], true, null, 'password_change');
            security_log(
                $pdo,
                'password_changed_self',
                (int) $user_id,
                'Member changed their own password.' . ($sign_out_others ? " Signed out {$killed} other session(s)." : '')
            );
            security_notify_user(
                $pdo,
                (int) $user_id,
                'Password Changed',
                'Your HOD Lekki workspace password was changed on ' . date('d M Y \a\t g:ia')
                . '. If this was not you, contact the church office immediately.'
            );

            echo json_encode([
                'status'  => 'success',
                'message' => 'Password updated successfully.'
                           . ($sign_out_others && $killed > 0 ? " {$killed} other device(s) signed out." : ''),
            ]);
            break;

        // =====================================================================================
        // ACTION 7: SIGN OUT EVERY OTHER DEVICE
        // =====================================================================================
        case 'sign_out_other_devices':
            $killed = security_revoke_sessions(
                $pdo,
                (int) $user_id,
                (int) $user_id,
                'Member signed out their other devices',
                security_current_session_hash()
            );
            security_log($pdo, 'sessions_revoked_self', (int) $user_id, "Member signed out {$killed} other session(s).");

            echo json_encode([
                'status'  => 'success',
                'message' => $killed > 0
                    ? "Signed out {$killed} other device(s). This one stays signed in."
                    : 'No other devices were signed in.',
            ]);
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