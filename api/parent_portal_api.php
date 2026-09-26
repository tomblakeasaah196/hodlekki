<?php
// /api/parent_portal_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

// Start session safely if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: AUTHENTICATE PARENT (Frictionless Gateway)
        // ==========================================
        case 'authenticate_parent':
            $phone = trim($_POST['phone'] ?? '');
            $dob = $_POST['child_dob'] ?? '';

            if (empty($phone) || empty($dob)) {
                echo json_encode(['status' => 'error', 'message' => 'Phone number and Child\'s Date of Birth are required.']);
                exit;
            }

            // 1. Find the User ID via Phone Number
            // We clean the phone input slightly in case they added spaces or pluses, depending on your DB format
            $stmt = $pdo->prepare("SELECT id, first_name FROM users WHERE phone = ? OR phone LIKE ? LIMIT 1");
            $stmt->execute([$phone, "%$phone"]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'We could not find a parent account linked to this phone number.']);
                exit;
            }

            // 2. The Security Gate: Does this user have a child with the provided DOB?
            $verifyStmt = $pdo->prepare("
                SELECT child_first_name 
                FROM junior_church_roster 
                WHERE (parent_id = ? OR parent2_id = ?) 
                AND dob = ? 
                LIMIT 1
            ");
            $verifyStmt->execute([$user['id'], $user['id'], $dob]);
            $child = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($child) {
                // Success! Set a secure temporary session specifically for the parent portal
                $_SESSION['parent_portal_auth_id'] = $user['id'];
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Welcome, ' . $user['first_name'] . '! Loading ' . $child['child_first_name'] . '\'s dashboard...'
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Security check failed. The Date of Birth does not match our records for your children.']);
            }
            break;

        // ==========================================
        // ACTION 2: FETCH PARENT DASHBOARD DATA
        // ==========================================
        case 'fetch_dashboard':
            // Verify Authentication (Check Main Portal Session OR Temporary Parent Gateway Session)
            $auth_user_id = $_SESSION['user_id'] ?? $_SESSION['parent_portal_auth_id'] ?? null;

            if (!$auth_user_id) {
                echo json_encode(['status' => 'auth_required']);
                exit;
            }

            // 1. Fetch Children belonging to this parent (excluding internal/medical notes)
            $childStmt = $pdo->prepare("
                SELECT id, child_first_name, child_last_name, picture_path, dob 
                FROM junior_church_roster 
                WHERE parent_id = ? OR parent2_id = ?
                ORDER BY child_first_name ASC
            ");
            $childStmt->execute([$auth_user_id, $auth_user_id]);
            $children = $childStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($children)) {
                echo json_encode(['status' => 'success', 'children' => [], 'message' => 'No children found assigned to this profile.']);
                exit;
            }

            // 2. Fetch the interactive timeline for each child (Last 10 Services)
            $timeline_data = [];
            foreach ($children as $child) {
                $timeStmt = $pdo->prepare("
                    SELECT a.status, s.service_date, s.topic, s.master_pages_covered, s.service_file_path, s.media_link 
                    FROM junior_church_attendance a
                    JOIN junior_church_services s ON a.service_id = s.id
                    WHERE a.child_id = ?
                    ORDER BY s.service_date DESC 
                    LIMIT 10
                ");
                $timeStmt->execute([$child['id']]);
                $timeline_data[$child['id']] = $timeStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'status' => 'success',
                'children' => $children,
                'timeline' => $timeline_data
            ]);
            break;

        // ==========================================
        // ACTION 3: UPDATE CHILD PROFILE PICTURE
        // ==========================================
        case 'update_child_pic':
            // Ensure they are authenticated
            $auth_user_id = $_SESSION['user_id'] ?? $_SESSION['parent_portal_auth_id'] ?? null;
            if (!$auth_user_id) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in again.']);
                exit;
            }

            $child_id = filter_var($_POST['child_id'] ?? '', FILTER_VALIDATE_INT);
            $image_base64 = $_POST['image_base64'] ?? '';

            // Verify Ownership: Ensure the parent actually "owns" this child record
            $checkStmt = $pdo->prepare("SELECT id FROM junior_church_roster WHERE id = ? AND (parent_id = ? OR parent2_id = ?)");
            $checkStmt->execute([$child_id, $auth_user_id, $auth_user_id]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Permission denied. You cannot edit this profile.']);
                exit;
            }

            // Process Base64 Image
            if (!empty($image_base64)) {
                $image_parts = explode(";base64,", $image_base64);
                if (count($image_parts) == 2) {
                    $image_base64_decoded = base64_decode($image_parts[1]);
                    
                    // Same target directory as the Admin module
                    $upload_dir = '../uploads/profiles/children/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    $filename = 'child_' . time() . '_' . rand(1000, 9999) . '.png';
                    if (file_put_contents($upload_dir . $filename, $image_base64_decoded)) {
                        $picture_path = '/uploads/profiles/children/' . $filename;
                        
                        $updateStmt = $pdo->prepare("UPDATE junior_church_roster SET picture_path = ? WHERE id = ?");
                        $updateStmt->execute([$picture_path, $child_id]);
                        
                        // NOTIFICATION TRIGGER: Alert Junior Church Leadership
                        $jcStmt = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 11 AND role_in_dept IN ('HOD', 'Director') AND is_active = 1");
                        $jc_leaders = $jcStmt->fetchAll(PDO::FETCH_COLUMN);

                        if (!empty($jc_leaders)) {
                            // Get child name for context
                            $cStmt = $pdo->prepare("SELECT child_first_name, child_last_name FROM junior_church_roster WHERE id = ?");
                            $cStmt->execute([$child_id]);
                            $child = $cStmt->fetch(PDO::FETCH_ASSOC);
                            $childName = $child ? "{$child['child_first_name']} {$child['child_last_name']}" : "A child";

                            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Child Profile Updated', ?, '/modules/junior_church/index.php')");
                            $alertMsg = "A parent has updated the profile picture for {$childName} via the Parent Portal.";
                            
                            foreach($jc_leaders as $leader_id) {
                                $notifStmt->execute([$leader_id, $alertMsg]);
                            }
                        }
                        
                        echo json_encode(['status' => 'success', 'message' => 'Child profile picture updated successfully!']);
                        exit;
                    }
                }
            }
            
            echo json_encode(['status' => 'error', 'message' => 'Failed to process image payload.']);
            break;

        // ==========================================
        // ACTION 4: LOGOUT PARENT PORTAL
        // ==========================================
        case 'logout':
            // We only destroy the temporary parent portal session. 
            // If they are logged into the main portal ($_SESSION['user_id']), we leave that intact.
            unset($_SESSION['parent_portal_auth_id']);
            echo json_encode(['status' => 'success', 'message' => 'Logged out securely.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint requested.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Parent Portal Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A secure database error occurred.']);
} catch (Exception $e) {
    error_log("Parent Portal System Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected server error occurred.']);
}
?>