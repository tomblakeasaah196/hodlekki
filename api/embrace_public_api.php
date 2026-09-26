<?php
// /api/embrace_public_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        
        // =====================================================================================
        // ACTION 0: REAL-TIME DUPLICATE CHECK (For Step 1)
        // =====================================================================================
        case 'check_existing_user':
            $fname = trim($_POST['first_name'] ?? '');
            $lname = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            // Strip all spaces, dashes, and brackets for a cleaner comparison
            $cleanPhone = preg_replace('/[\s\-\(\)]/', '', $phone);

            // Check if phone matches (using LIKE to catch variations like +234 vs 080)
            // Or if exact First and Last name match
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE phone LIKE ? OR (first_name = ? AND last_name = ?) LIMIT 1");
            $checkStmt->execute(['%' . ltrim($cleanPhone, '0+') . '%', $fname, $lname]);
            
            if ($checkStmt->fetch()) {
                echo json_encode(['exists' => true]);
            } else {
                echo json_encode(['exists' => false]);
            }
            break;

        // =====================================================================================
        // ACTION 1: PUBLIC VISITOR REGISTRATION
        // =====================================================================================
        case 'submit_connect_card':
            // 1. Basic sanitization
            $fname = trim($_POST['first_name'] ?? '');
            $lname = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            
            if (empty($fname) || empty($lname) || empty($phone)) {
                echo json_encode(['status' => 'error', 'message' => 'Please provide your first name, last name, and phone number so we can reach you.']);
                exit;
            }

            // Extract only the numeric digits from the phone number
            $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phoneDigits) < 9) {
                echo json_encode(['status' => 'error', 'message' => 'Please enter a valid phone number with at least 9 digits.']);
                exit;
            }

            // 2. Prevent duplicate entries (Using the cleaned phone to match the Real-Time check)
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE phone LIKE ? OR (first_name = ? AND last_name = ?) LIMIT 1");
            $checkStmt->execute(['%' . ltrim($phoneDigits, '0+') . '%', $fname, $lname]);
            if ($checkStmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Your details are already registered in our family database. Please log in to update your profile instead.']);
                exit;
            }

            // 3. Process Checkboxes & Coordinates
            $wants_to_join = isset($_POST['wants_to_join']) && $_POST['wants_to_join'] == '1' ? 1 : 0;
            $visitation_preference = (isset($_POST['wants_visitation']) && $_POST['wants_visitation'] == '1') ? 'In-Person' : 'None';
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;

            // 4. Safe formatting for Optional / ENUM / UNIQUE Database Fields
            // If empty, force to NULL to prevent Strict Mode ENUM crashes and Unique Key collisions
            $safe_email = !empty(trim($_POST['email'] ?? '')) ? trim($_POST['email']) : null;
            $safe_gender = !empty($_POST['gender']) ? trim($_POST['gender']) : null;
            $safe_dob = !empty($_POST['dob']) ? trim($_POST['dob']) : null;
            $safe_marital = !empty($_POST['marital_status']) ? trim($_POST['marital_status']) : 'Single';
            $safe_address = !empty(trim($_POST['physical_address'] ?? '')) ? trim($_POST['physical_address']) : null;
            
            // 5. Generate unique QR hash for swift check-ins at physical services
            $qr_hash = hash('sha256', uniqid($phone, true));

            // 6. Insert into Database as a 1st_Timer
            $sql = "INSERT INTO users (
                        first_name, last_name, email, phone, gender, dob, marital_status, 
                        physical_address, latitude, longitude, spiritual_status, invited_by, wants_to_join, 
                        visitation_preference, prayer_requests, qr_code_hash
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '1st_Timer', ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $fname, 
                $lname, 
                $safe_email, 
                $phone, 
                $safe_gender, 
                $safe_dob,
                $safe_marital,
                $safe_address,
                $latitude,
                $longitude,
                trim($_POST['invited_by'] ?? ''),
                $wants_to_join,
                $visitation_preference,
                trim($_POST['prayer_requests'] ?? ''),
                $qr_hash
            ]);

            // Reach: if this first timer was met through Reach, mark that lead "Visited Church".
            require_once __DIR__ . '/../includes/reach_helpers.php';
            reach_mark_visited_church($pdo, $phone, (int) $pdo->lastInsertId());

            // NOTIFICATION TRIGGER 1: Alert Embrace Leadership
            $embraceStmt = $pdo->query("
                SELECT ud.user_id 
                FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE d.name LIKE '%Embrace%' AND ud.role_in_dept IN ('Director', 'HOD') AND ud.is_active = 1
            ");
            $embrace_leaders = $embraceStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($embrace_leaders)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Connect Card', ?, '/modules/embrace/index.php')");
                $alertMessage = "{$fname} {$lname} has just submitted a Connect Card online and is waiting in the queue to be assigned.";
                foreach($embrace_leaders as $uid) {
                    $notifStmt->execute([$uid, $alertMessage]);
                }
            }

            // NOTIFICATION TRIGGER 2: Alert IDI
            $idiStmt = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 1 AND is_active = 1");
            $idi_users = $idiStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($idi_users)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Connect Card Profile', ?, '/modules/congregation/index.php')");
                $alertMessage = "A new profile for {$fname} {$lname} was autonomously generated via the public Connect Card. Please review the entry.";
                foreach($idi_users as $uid) {
                    $notifStmt->execute([$uid, $alertMessage]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Welcome home! Your details have been received with love.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Embrace Public API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again or contact an usher.']);
}
?>