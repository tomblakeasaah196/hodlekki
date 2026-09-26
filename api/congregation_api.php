<?php
// /api/congregation_api.php

// 1. Core Includes & Headers
require_once '../includes/db.php';
header('Content-Type: application/json');

// 2. Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 3. RBAC Check (Leadership & Admin only)
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'];
$is_admin = false;

// Check ALL roles the user possesses on their lanyard, not just their primary active one
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles)) {
            $is_admin = true;
            break; // Found an admin badge! Stop checking.
        }
    }
}

if (!$is_admin) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. You do not have clearance to manage the master congregation database.']);
    exit;
}
/**
 * Helper: Process Image Uploads
 */
function processProfileImage($file) {
    $target_dir = "../../uploads/profiles/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $filename = uniqid('cong_', true) . '.' . $ext;
    $target_path = $target_dir . $filename;

    if($file["size"] > 2000000) return ['error' => 'Image too large. Max 2MB.'];
    if(!in_array($ext, ['jpg', 'jpeg', 'png'])) return ['error' => 'Only JPG, JPEG & PNG allowed.'];

    if (move_uploaded_file($file["tmp_name"], $target_path)) {
        return ['success' => '/uploads/profiles/' . $filename];
    }
    return ['error' => 'Upload failed.'];
}

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: READ - FETCH DASHBOARD & KPIs
        // =====================================================================================
        case 'fetch_dashboard':
            // KPI 1: Active Non-Members
            $activeNonMembersStmt = $pdo->query("
                SELECT id, first_name, last_name, phone, spiritual_status 
                FROM users 
                WHERE attendance_status = 'Active' 
                AND spiritual_status IN ('Visitor', '1st_Timer', '2nd_Timer', '3rd_Timer', 'Non_Member')
            ");
            $active_non_members = $activeNonMembersStmt->fetchAll(PDO::FETCH_ASSOC);

            // KPI 2: Consistent Workers & Pastors
            $consistentWorkersStmt = $pdo->query("
                SELECT id, first_name, last_name, phone 
                FROM users 
                WHERE attendance_status = 'Active' 
                AND spiritual_status IN ('Worker', 'Pastor')
            ");
            $consistent_workers = $consistentWorkersStmt->fetchAll(PDO::FETCH_ASSOC);

            // KPI 3: Consistent Members
            $consistentMembersStmt = $pdo->query("
                SELECT id, first_name, last_name, phone 
                FROM users 
                WHERE attendance_status = 'Active' 
                AND spiritual_status = 'Member'
            ");
            $consistent_members = $consistentMembersStmt->fetchAll(PDO::FETCH_ASSOC);

            // FETCH FILTER OPTIONS
            $tribesList = $pdo->query("SELECT id, name FROM tribes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $deptsList = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

            // THE MASTER ROSTER
            $rosterStmt = $pdo->query("
                SELECT u.id, u.first_name, u.last_name, u.phone, u.gender, u.spiritual_status, u.attendance_status, u.picture_path, u.updated_at,
                       u.physical_address, u.marital_status, u.wedding_anniversary, u.comments, u.new_church_name, u.dob, u.email,
                       (SELECT GROUP_CONCAT(d.name SEPARATOR ', ') 
                        FROM user_departments ud 
                        JOIN departments d ON ud.department_id = d.id 
                        WHERE ud.user_id = u.id AND ud.is_active = 1) as active_departments,
                       (SELECT t.name 
                        FROM user_tribes ut 
                        JOIN tribes t ON ut.tribe_id = t.id 
                        WHERE ut.user_id = u.id ORDER BY ut.joined_at DESC LIMIT 1) as active_tribe
                FROM users u
                ORDER BY 
                    CASE WHEN u.attendance_status = 'Unknown' THEN 1 ELSE 2 END, 
                    u.first_name ASC
            ");
            $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'filters' => [
                    'tribes' => $tribesList,
                    'departments' => $deptsList
                ],
                'kpis' => [
                    'active_non_members' => $active_non_members,
                    'consistent_workers' => $consistent_workers,
                    'consistent_members' => $consistent_members
                ],
                'roster' => $roster
            ]);
            break;

        // =====================================================================================
        // ACTION 2: CREATE - ADD A NEW MEMBER (Independent of Embrace)
        // =====================================================================================
        case 'create_member':
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');

            // Auto-generate intelligent & unique email if left blank
            if (empty($email)) {
                // Helper function to extract the shortest name part
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

                $short_first = $getShortest($first_name);
                $short_last = $getShortest($last_name);

                $prefix = '';
                if (!empty($short_first) && !empty($short_last)) {
                    $prefix = $short_first . '.' . $short_last;
                    // If combined name is still too long (e.g. > 20 chars), fallback to just first name
                    if (strlen($prefix) > 20) $prefix = $short_first;
                } else {
                    $prefix = $short_first ?: $short_last;
                }

                if (!empty($prefix)) {
                    // Check database for uniqueness and append number if necessary
                    $base_email = $prefix . '@hodlc.com';
                    $final_email = $base_email;
                    $counter = 1;
                    
                    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    while (true) {
                        $checkStmt->execute([$final_email]);
                        if (!$checkStmt->fetch()) break; // Email is completely unique!
                        
                        $final_email = $prefix . $counter . '@hodlc.com';
                        $counter++;
                    }
                    $email = $final_email;
                } else {
                    $email = null; // Absolute fallback
                }
            }
            $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
            $gender = $_POST['gender'] ?? null;
            $marital_status = $_POST['marital_status'] ?? 'Single';
            $spiritual_status = $_POST['spiritual_status'] ?? 'Visitor';
            $attendance_status = $_POST['attendance_status'] ?? 'New';
            $address = trim($_POST['physical_address'] ?? 'To be updated');
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $anniversary = ($marital_status === 'Married' && !empty($_POST['wedding_anniversary'])) ? $_POST['wedding_anniversary'] : null;
            $comments = trim($_POST['comments'] ?? '');

            if (empty($first_name) || empty($last_name) || empty($phone)) {
                echo json_encode(['status' => 'error', 'message' => 'First Name, Last Name, and Phone are mandatory.']);
                exit;
            }

            // Generate QR Code Hash for Future Check-ins
            $qr_hash = hash('sha256', uniqid($phone, true));

            // Handle Image
            $pic_path = null;
            if (!empty($_FILES['profile_pic']['name'])) {
                $upload = processProfileImage($_FILES['profile_pic']);
                if (isset($upload['error'])) {
                    echo json_encode(['status' => 'error', 'message' => $upload['error']]); exit;
                }
                $pic_path = $upload['success'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (first_name, last_name, email, phone, dob, gender, marital_status, spiritual_status, attendance_status, physical_address, latitude, longitude, wedding_anniversary, comments, qr_code_hash, picture_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$first_name, $last_name, $email, $phone, $dob, $gender, $marital_status, $spiritual_status, $attendance_status, $address, $latitude, $longitude, $anniversary, $comments, $qr_hash, $pic_path]);

            // NOTIFICATION TRIGGER: Alert IDI (Department ID 1) of the new master roster entry
            $idiStmt = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 1 AND is_active = 1");
            $idi_users = $idiStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($idi_users)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Congregation Profile', ?, '/modules/congregation/index.php')");
                $alertMessage = "A new profile for {$first_name} {$last_name} has been manually added to the master congregation database.";
                
                foreach($idi_users as $uid) {
                    $notifStmt->execute([$uid, $alertMessage]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'New profile successfully created in the master database.']);
            break;

        // =====================================================================================
        // ACTION 3: READ - FETCH SINGLE MEMBER PROFILE (For Editing)
        case 'get_member':
            $target_id = $_POST['user_id'] ?? '';
            if (empty($target_id)) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, real_email, phone, dob, gender, marital_status, spiritual_status, attendance_status, physical_address, new_church_name, wedding_anniversary, comments, picture_path FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($member) {
                echo json_encode(['status' => 'success', 'data' => $member]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Profile not found.']);
            }
            break;

        // =====================================================================================
        // ACTION 4: UPDATE - EDIT FULL MEMBER PROFILE
        // =====================================================================================
        case 'update_member':
            $target_id = $_POST['user_id'] ?? '';
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $real_email = trim($_POST['real_email'] ?? null);
            $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
            $gender = $_POST['gender'] ?? null;
            
            $address = trim($_POST['physical_address'] ?? '');
            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            
            $attendance_status = $_POST['attendance_status'] ?? 'New';
            $spiritual_status = $_POST['spiritual_status'] ?? 'Visitor';
            $marital_status = $_POST['marital_status'] ?? 'Single';
            $anniversary = ($marital_status === 'Married' && !empty($_POST['wedding_anniversary'])) ? $_POST['wedding_anniversary'] : null;
            $new_church = ($attendance_status === 'Attends_Another_Church') ? trim($_POST['new_church_name'] ?? '') : null;
            $comments = trim($_POST['comments'] ?? '');

            if (empty($target_id) || empty($first_name) || empty($last_name)) {
                echo json_encode(['status' => 'error', 'message' => 'Core identity fields cannot be blank.']);
                exit;
            }

            // Handle Image Update
            $pic_update_sql = "";
            $params = [$first_name, $last_name, $real_email, $phone, $dob, $gender, $address, $latitude, $longitude, $attendance_status, $spiritual_status, $marital_status, $anniversary, $new_church, $comments];
            
            if (!empty($_FILES['profile_pic']['name'])) {
                $upload = processProfileImage($_FILES['profile_pic']);
                if (isset($upload['error'])) {
                    echo json_encode(['status' => 'error', 'message' => $upload['error']]); exit;
                }
                $pic_update_sql = ", picture_path = ?";
                $params[] = $upload['success'];
            }
            $params[] = $target_id;

            $stmt = $pdo->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, real_email = ?, phone = ?, dob = ?, gender = ?, physical_address = ?, latitude = ?, longitude = ?, attendance_status = ?, spiritual_status = ?, marital_status = ?, wedding_anniversary = ?, new_church_name = ?, comments = ? $pic_update_sql
                WHERE id = ?
            ");
            $stmt->execute($params);

            echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully.']);
            break;

        // =====================================================================================
        // ACTION 5: DELETE - REMOVE MEMBER RECORD (Hard Delete)
        // =====================================================================================
        case 'delete_member':
            $target_id = $_POST['user_id'] ?? '';
            
            if (empty($target_id)) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required for deletion.']);
                exit;
            }

            // A strict delete. Due to ON DELETE CASCADE on your related tables (like attendance, user_departments), 
            // this will seamlessly wipe their entire trace from the system.
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$target_id]);

            echo json_encode(['status' => 'success', 'message' => 'Member profile permanently deleted from the database.']);
            break;

        // =====================================================================================
        // ACTION 6: PUSH "UNKNOWN" TO CHARIS (EMBRACE BRIDGE)
        // =====================================================================================
        case 'push_to_charis':
            $target_id = $_POST['user_id'] ?? '';
            if (empty($target_id)) {
                echo json_encode(['status' => 'error', 'message' => 'User ID is required.']);
                exit;
            }

            $checkStmt = $pdo->prepare("SELECT id FROM embrace_followups WHERE visitor_id = ? AND status = 'Pending'");
            $checkStmt->execute([$target_id]);
            if ($checkStmt->fetch()) {
                echo json_encode(['status' => 'warning', 'message' => 'This member already has an active pending follow-up in the Charis/Embrace module.']);
                exit;
            }

            // Fetch the target member's name to make the notification actionable
            $userStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $userStmt->execute([$target_id]);
            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            $memberName = $targetUser ? "{$targetUser['first_name']} {$targetUser['last_name']}" : "A member";

            $pushStmt = $pdo->prepare("
                INSERT INTO embrace_followups (visitor_id, assigned_worker_id, followup_method, followup_date, status, followup_notes)
                VALUES (?, NULL, 'Call', CURDATE(), 'Pending', 'SYSTEM FLAG: Member pushed from Congregation Module due to Unknown/Absent status. Needs immediate welfare check.')
            ");
            $pushStmt->execute([$target_id]);

            // NOTIFICATION TRIGGER: Alert Charis Directors and HODs of the urgent manual flag
            $charisLeadersStmt = $pdo->query("
                SELECT ud.user_id 
                FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE (d.name LIKE '%Charis%' OR d.name LIKE '%Welfare%') 
                AND ud.role_in_dept IN ('Director', 'HOD') AND ud.is_active = 1
            ");
            $charis_leaders = $charisLeadersStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($charis_leaders)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Urgent Welfare Flag', ?, '/modules/charis/index.php')");
                $alertMessage = "{$memberName} has been manually flagged from the Congregation Roster for an urgent welfare check due to absence.";
                
                foreach($charis_leaders as $leader_id) {
                    $notifStmt->execute([$leader_id, $alertMessage]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Member successfully pushed to Charis/Embrace for welfare follow-up!']);
            break;
            
            // =====================================================================================
        // ACTION 7: FETCH MEMBER ATTENDANCE HISTORY
        // =====================================================================================
        case 'fetch_member_attendance':
            $target_id = $_POST['user_id'] ?? '';
            $period = $_POST['period'] ?? '1_year'; // Default to 1 year
            
            if (empty($target_id)) exit(json_encode(['status' => 'error', 'message' => 'User ID is required.']));

            // Determine the date filter
            $date_filter = "";
            if ($period === '3_months') $date_filter = "AND event_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
            elseif ($period === '6_months') $date_filter = "AND event_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
            elseif ($period === '1_year') $date_filter = "AND event_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            // 'all_time' leaves $date_filter blank

            // 1. Get Total Events that occurred in this period
            $eventsStmt = $pdo->query("SELECT COUNT(*) FROM events WHERE 1=1 $date_filter");
            $total_events = (int)$eventsStmt->fetchColumn();

            // 2. Get the User's specific attendance records for events in this period
            $attStmt = $pdo->prepare("
                SELECT a.status, e.title, e.event_date, e.event_category 
                FROM attendance a
                JOIN events e ON a.event_id = e.id
                WHERE a.user_id = ? $date_filter
                ORDER BY e.event_date DESC
            ");
            $attStmt->execute([$target_id]);
            $records = $attStmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Calculate Summary
            $present = 0; $excused = 0;
            foreach ($records as $r) {
                if ($r['status'] === 'Present') $present++;
                elseif ($r['status'] === 'Excused') $excused++;
            }
            
            // If they weren't marked present or excused, it counts as a missed event
            $missed = $total_events - ($present + $excused);

            echo json_encode([
                'status' => 'success',
                'summary' => [
                    'total_events' => $total_events,
                    'present' => $present,
                    'excused' => $excused,
                    'missed' => $missed < 0 ? 0 : $missed // Failsafe
                ],
                'records' => $records
            ]);
            break;

        // =====================================================================================
        // DEFAULT: INVALID ACTION
        // =====================================================================================
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Congregation API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
}
?>