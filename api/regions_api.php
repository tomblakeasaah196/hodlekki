<?php
// /api/regions_api.php

// 1. Core Includes & Headers
require_once '../includes/db.php';
header('Content-Type: application/json');

// 2. Security Check (Must be a logged-in user)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 3. RBAC Check for Admin/Command Center Tab
$admin_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'];
$is_admin = false;

if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $admin_roles) || strpos($role['role_name'], 'IDI') !== false) {
            $is_admin = true;
            break;
        }
    }
}

// =====================================================================================
// HELPER FUNCTIONS
// =====================================================================================

function getUserRegion($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT region_id FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    return $stmt->fetchColumn();
}

// Injects automated system messages into the Regional Stream
function logSystemEventToStream($pdo, $region_id, $user_id, $type, $content, $ref_id = null) {
    $stmt = $pdo->prepare("INSERT INTO region_stream (region_id, user_id, message_type, content, reference_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$region_id, $user_id, $type, $content, $ref_id]);
}

// Handles Image and Voice Note uploads for the Stream
function handleStreamMediaUpload($file) {
    $target_dir = "../../uploads/stream/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $mime = mime_content_type($file["tmp_name"]);
    
    // Validate extensions
    $allowed_exts = ['jpg', 'jpeg', 'png', 'mp3', 'ogg', 'wav', 'webm', 'mp4', 'm4a'];
    if (!in_array($ext, $allowed_exts)) return ['error' => 'Invalid file type.'];

    // 10MB limit for media
    if ($file["size"] > 10485760) return ['error' => 'File size exceeds 10MB limit.'];

    $filename = uniqid('str_', true) . '.' . $ext;
    $target_path = $target_dir . $filename;

    if (move_uploaded_file($file["tmp_name"], $target_path)) {
        return ['success' => '/uploads/stream/' . $filename];
    }
    return ['error' => 'Failed to process media upload.'];
}

try {
    switch ($action) {

        // =====================================================================================
        // SECTION A: TAB 1 - PUBLIC REGION HUB (Available to all members)
        // =====================================================================================

        // 1. Fetch Hub Data (Leadership, Stats, Welcome details)
        case 'fetch_my_region_hub':
            $region_id = getUserRegion($pdo, $user_id);
            if (!$region_id) {
                echo json_encode(['status' => 'unassigned', 'message' => 'You have not been assigned to a Region yet.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT r.*, 
                    d.first_name as dir_first, d.last_name as dir_last, d.picture_path as dir_pic, d.phone as dir_phone,
                    p.first_name as pas_first, p.last_name as pas_last, p.picture_path as pas_pic, p.phone as pas_phone,
                    h.first_name as head_first, h.last_name as head_last, h.picture_path as head_pic, h.phone as head_phone
                FROM regions r
                LEFT JOIN users d ON r.director_id = d.id
                LEFT JOIN users p ON r.pastor_id = p.id
                LEFT JOIN users h ON r.head_id = h.id
                WHERE r.id = ?
            ");
            $stmt->execute([$region_id]);
            $region = $stmt->fetch(PDO::FETCH_ASSOC);

            $popStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE region_id = ? AND attendance_status != 'Relocated'");
            $popStmt->execute([$region_id]);
            $population = $popStmt->fetchColumn();

            echo json_encode(['status' => 'success', 'data' => ['region' => $region, 'population' => $population]]);
            break;

        // 2. Carpool System
        case 'fetch_carpools':
            $region_id = getUserRegion($pdo, $user_id);
            $stmt = $pdo->prepare("
                SELECT c.*, u.first_name, u.last_name, u.picture_path, u.phone 
                FROM region_carpools c 
                JOIN users u ON c.user_id = u.id 
                WHERE c.region_id = ? AND c.status = 'Active'
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([$region_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'add_carpool':
            $region_id = getUserRegion($pdo, $user_id);
            $type = $_POST['type'] ?? 'Need_Ride';
            $route = trim($_POST['route_details'] ?? '');
            $seats = (int)($_POST['seats_available'] ?? 0);

            if (empty($route)) {
                echo json_encode(['status' => 'error', 'message' => 'Please provide route details.']); exit;
            }

            $stmt = $pdo->prepare("INSERT INTO region_carpools (region_id, user_id, type, route_details, seats_available) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$region_id, $user_id, $type, $route, $seats]);
            $carpool_id = $pdo->lastInsertId();

            // TRIGGER SYSTEM EVENT TO STREAM
            $prefix = $type === 'Offering_Ride' ? "Offering Ride ({$seats} seats): " : "Needs a Ride: ";
            logSystemEventToStream($pdo, $region_id, $user_id, 'system_carpool', $prefix . $route, $carpool_id);

            echo json_encode(['status' => 'success', 'message' => 'Carpool request posted and shared to Regional Stream.']);
            break;

        case 'resolve_carpool':
            $carpool_id = filter_var($_POST['carpool_id'] ?? '', FILTER_VALIDATE_INT);
            $stmt = $pdo->prepare("UPDATE region_carpools SET status = 'Fulfilled' WHERE id = ? AND user_id = ?");
            $stmt->execute([$carpool_id, $user_id]);
            echo json_encode(['status' => 'success', 'message' => 'Carpool marked as fulfilled.']);
            break;

        // 3. Cell Fellowships
        case 'fetch_cells':
            $region_id = getUserRegion($pdo, $user_id);
            $stmt = $pdo->prepare("
                SELECT c.*, u.first_name, u.last_name, u.phone, u.picture_path 
                FROM region_cells c 
                JOIN users u ON c.leader_id = u.id 
                WHERE c.region_id = ? 
                ORDER BY c.cell_name ASC
            ");
            $stmt->execute([$region_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // 4. Prayer & Testimony Wall
        case 'fetch_prayers':
            $region_id = getUserRegion($pdo, $user_id);
            $stmt = $pdo->prepare("
                SELECT p.*, u.first_name, u.last_name, u.picture_path 
                FROM region_prayers p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.region_id = ? 
                ORDER BY p.created_at DESC LIMIT 50
            ");
            $stmt->execute([$region_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'add_prayer':
            $region_id = getUserRegion($pdo, $user_id);
            $content = trim($_POST['prayer_content'] ?? '');
            $is_testimony = isset($_POST['is_testimony']) && $_POST['is_testimony'] == 1 ? 1 : 0;

            if (empty($content)) {
                echo json_encode(['status' => 'error', 'message' => 'Content cannot be empty.']); exit;
            }

            $stmt = $pdo->prepare("INSERT INTO region_prayers (region_id, user_id, prayer_content, is_testimony) VALUES (?, ?, ?, ?)");
            $stmt->execute([$region_id, $user_id, $content, $is_testimony]);
            $prayer_id = $pdo->lastInsertId();

            // TRIGGER SYSTEM EVENT TO STREAM
            $stream_type = $is_testimony ? 'system_testimony' : 'system_prayer';
            logSystemEventToStream($pdo, $region_id, $user_id, $stream_type, $content, $prayer_id);

            echo json_encode(['status' => 'success', 'message' => $is_testimony ? 'Testimony shared to Stream!' : 'Prayer request posted to Stream.']);
            break;

        // 5. Fetch Region Broadcasts (Noticeboard)
        case 'fetch_broadcasts':
            $target_region = $_POST['region_id'] ?? getUserRegion($pdo, $user_id);
            if (!$target_region) { echo json_encode(['status'=>'error','message'=>'No region specified.']); exit; }
            
            $stmt = $pdo->prepare("SELECT * FROM region_broadcasts WHERE region_id = ? ORDER BY created_at DESC");
            $stmt->execute([$target_region]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // =====================================================================================
        // SECTION B: THE REGIONAL STREAM (WhatsApp-style Chat Feed)
        // =====================================================================================

        // Fetch latest messages for the live feed
        case 'fetch_stream':
            $region_id = getUserRegion($pdo, $user_id);
            if (!$region_id) { echo json_encode(['status'=>'error','message'=>'Unassigned']); exit; }

            // Fetch last 100 messages (Ordered by newest first, frontend will reverse array to scroll to bottom)
            $stmt = $pdo->prepare("
                SELECT s.*, u.first_name, u.last_name, u.picture_path, u.is_muted_in_region
                FROM region_stream s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.region_id = ? AND s.is_deleted = 0
                ORDER BY s.created_at DESC 
                LIMIT 100
            ");
            $stmt->execute([$region_id]);
            echo json_encode(['status' => 'success', 'data' => array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC))]);
            break;

        // Post a new chat message, image, or voice note
        case 'post_stream_message':
            $region_id = getUserRegion($pdo, $user_id);
            if (!$region_id) { echo json_encode(['status'=>'error','message'=>'Unassigned']); exit; }

            // Security Check: Is the user muted by an admin?
            $muteStmt = $pdo->prepare("SELECT is_muted_in_region FROM users WHERE id = ?");
            $muteStmt->execute([$user_id]);
            if ($muteStmt->fetchColumn() == 1) {
                echo json_encode(['status' => 'error', 'message' => 'Your account has been temporarily muted in the Regional Stream by an administrator.']);
                exit;
            }

            $content = trim($_POST['content'] ?? '');
            $message_type = 'text';
            $media_path = null;

            // Handle Media Upload (Image or Voice Note)
            if (!empty($_FILES['media']['name'])) {
                $upload = handleStreamMediaUpload($_FILES['media']);
                if (isset($upload['error'])) {
                    echo json_encode(['status' => 'error', 'message' => $upload['error']]); exit;
                }
                $media_path = $upload['success'];
                
                // Infer type from extension
                $ext = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['mp3', 'ogg', 'wav', 'webm', 'm4a'])) {
                    $message_type = 'voice';
                } else {
                    $message_type = 'image';
                }
            }

            if (empty($content) && empty($media_path)) {
                echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']); exit;
            }

            $stmt = $pdo->prepare("INSERT INTO region_stream (region_id, user_id, message_type, content, media_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$region_id, $user_id, $message_type, $content, $media_path]);
            echo json_encode(['status' => 'success', 'message' => 'Sent.']);
            break;


        // =====================================================================================
        // SECTION C: TAB 2 - ADMIN COMMAND CENTER
        // =====================================================================================

        // 1. Fetch Kanban Board Data
        case 'admin_fetch_kanban':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }

            $regionsStmt = $pdo->query("SELECT * FROM regions ORDER BY id ASC");
            $regions = $regionsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch all active users and strictly order them alphabetically A-Z for the UI
            $usersStmt = $pdo->query("
                SELECT id, first_name, last_name, phone, picture_path, physical_address, region_id, attendance_status, latitude, longitude
                FROM users 
                WHERE attendance_status NOT IN ('Relocated', 'Attends_Another_Church')
                ORDER BY first_name ASC, last_name ASC
            ");
            $all_users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

            $board = ['unassigned' => [], 'regions' => []];

            foreach ($regions as $r) {
                $board['regions'][$r['id']] = ['details' => $r, 'users' => []];
            }

            foreach ($all_users as $u) {
                if (empty($u['region_id'])) {
                    $board['unassigned'][] = $u;
                } else if (isset($board['regions'][$u['region_id']])) {
                    $board['regions'][$u['region_id']]['users'][] = $u;
                }
            }

            echo json_encode(['status' => 'success', 'data' => $board]);
            break;
            
            // 1.5 Server-Side Excel Export Engine
        case 'admin_export_excel':
            if (!$is_admin) { 
                die('Access Denied.'); 
            }

            // 1. Bulletproof the Autoloader Path using absolute directory references
            $autoload_path = __DIR__ . '/../vendor/autoload.php';
            if (!file_exists($autoload_path)) {
                error_log("Excel Export Error: Composer autoload.php not found at {$autoload_path}");
                die('System configuration error: Vendor autoload missing. Run composer require phpoffice/phpspreadsheet');
            }
            require_once $autoload_path;

            // 2. Allocate resources for heavy processing
            ini_set('memory_limit', '512M');
            set_time_limit(120);

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $spreadsheet->removeSheetByIndex(0); // Remove default blank sheet

            // Global Headers for our columns
            $headers = ['Names', 'Phone', 'Physical Address', 'Attendance Status', 'Spiritual Status', 'Gender', 'Marital Status'];

            // Step 1: Fetch Regions and create a sheet for each
            $regionsStmt = $pdo->query("SELECT id, name FROM regions ORDER BY id ASC");
            $regions = $regionsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare statement to fetch users for a specific region
            $userStmt = $pdo->prepare("
                SELECT first_name, last_name, phone, physical_address, attendance_status, spiritual_status, gender, marital_status 
                FROM users 
                WHERE region_id = ? AND attendance_status NOT IN ('Relocated', 'Attends_Another_Church')
                ORDER BY first_name ASC, last_name ASC
            ");

            foreach ($regions as $index => $region) {
                // Ensure sheet name doesn't exceed Excel's 31 character limit and strip invalid characters
                $safeSheetName = preg_replace('/[*:\/?\[\]]/', '', substr($region['name'], 0, 31));
                $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $safeSheetName); 
                $spreadsheet->addSheet($worksheet, $index);

                // Set Headers
                $colLetter = 'A';
                foreach ($headers as $header) {
                    $worksheet->setCellValue($colLetter . '1', $header);
                    $worksheet->getStyle($colLetter . '1')->getFont()->setBold(true);
                    $colLetter++;
                }

                // Populate Rows
                $userStmt->execute([$region['id']]);
                $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $rowNum = 2;
                foreach ($users as $user) {
                    $worksheet->setCellValue('A' . $rowNum, $user['first_name'] . ' ' . $user['last_name']);
                    $worksheet->setCellValue('B' . $rowNum, $user['phone'] ?? 'N/A');
                    $worksheet->setCellValue('C' . $rowNum, $user['physical_address'] ?? 'N/A');
                    $worksheet->setCellValue('D' . $rowNum, str_replace('_', ' ', $user['attendance_status']));
                    $worksheet->setCellValue('E' . $rowNum, str_replace('_', ' ', $user['spiritual_status']));
                    $worksheet->setCellValue('F' . $rowNum, $user['gender'] ?? '-');
                    $worksheet->setCellValue('G' . $rowNum, $user['marital_status'] ?? '-');
                    $rowNum++;
                }

                // Auto-size columns for enterprise presentation
                foreach (range('A', 'G') as $colID) {
                    $worksheet->getColumnDimension($colID)->setAutoSize(true);
                }
            }

            // Step 2: Create the Unassigned Sheet
            $unassignedSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Unassigned');
            $spreadsheet->addSheet($unassignedSheet);
            
            $colLetter = 'A';
            foreach ($headers as $header) {
                $unassignedSheet->setCellValue($colLetter . '1', $header);
                $unassignedSheet->getStyle($colLetter . '1')->getFont()->setBold(true);
                $colLetter++;
            }

            $unassignedStmt = $pdo->query("
                SELECT first_name, last_name, phone, physical_address, attendance_status, spiritual_status, gender, marital_status 
                FROM users 
                WHERE region_id IS NULL AND attendance_status NOT IN ('Relocated', 'Attends_Another_Church')
                ORDER BY first_name ASC, last_name ASC
            ");
            $unassignedUsers = $unassignedStmt->fetchAll(PDO::FETCH_ASSOC);

            $rowNum = 2;
            foreach ($unassignedUsers as $user) {
                $unassignedSheet->setCellValue('A' . $rowNum, $user['first_name'] . ' ' . $user['last_name']);
                $unassignedSheet->setCellValue('B' . $rowNum, $user['phone'] ?? 'N/A');
                $unassignedSheet->setCellValue('C' . $rowNum, $user['physical_address'] ?? 'N/A');
                $unassignedSheet->setCellValue('D' . $rowNum, str_replace('_', ' ', $user['attendance_status']));
                $unassignedSheet->setCellValue('E' . $rowNum, str_replace('_', ' ', $user['spiritual_status']));
                $unassignedSheet->setCellValue('F' . $rowNum, $user['gender'] ?? '-');
                $unassignedSheet->setCellValue('G' . $rowNum, $user['marital_status'] ?? '-');
                $rowNum++;
            }

            foreach (range('A', 'G') as $colID) {
                $unassignedSheet->getColumnDimension($colID)->setAutoSize(true);
            }

            // Step 3: Strict Buffer Clear and Output
            $dateAppended = date('Y-m-d');
            $filename = "Regional_Distribution_Report_{$dateAppended}.xlsx";

            // Purge any output buffers (like the global application/json header or errant whitespace) to prevent file corruption
            if (ob_get_length()) {
                ob_end_clean(); 
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            
            // Explicitly exit to prevent the global catch block or JSON formatting from executing
            exit;

        // 2. Drag & Drop Reassignment
        case 'admin_reassign_user':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }

            $target_user_id = filter_var($_POST['target_user_id'] ?? '', FILTER_VALIDATE_INT);
            $new_region_id = filter_var($_POST['new_region_id'] ?? '', FILTER_VALIDATE_INT);

            if (!$target_user_id) { echo json_encode(['status' => 'error', 'message' => 'Invalid User ID.']); exit; }

            $val = $new_region_id ? $new_region_id : null;
            $stmt = $pdo->prepare("UPDATE users SET region_id = ? WHERE id = ?");
            $stmt->execute([$val, $target_user_id]);

            echo json_encode(['status' => 'success', 'message' => 'User successfully reassigned.']);
            break;

        // 3. Manage Broadcasts (Create, Edit, Delete)
        case 'admin_manage_broadcast':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }
            
            $action_type = $_POST['manage_action'] ?? 'create';
            $broadcast_id = filter_var($_POST['broadcast_id'] ?? '', FILTER_VALIDATE_INT);
            $target_region_id = filter_var($_POST['region_id'] ?? '', FILTER_VALIDATE_INT);
            $message = trim($_POST['message'] ?? '');

            if ($action_type === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM region_broadcasts WHERE id = ?");
                $stmt->execute([$broadcast_id]);
                echo json_encode(['status' => 'success', 'message' => 'Broadcast deleted successfully.']);
                exit;
            }

            if (empty($message) || !$target_region_id) {
                echo json_encode(['status' => 'error', 'message' => 'Region and message are required.']); exit;
            }

            if ($action_type === 'update') {
                $stmt = $pdo->prepare("UPDATE region_broadcasts SET message = ? WHERE id = ?");
                $stmt->execute([$message, $broadcast_id]);
                echo json_encode(['status' => 'success', 'message' => 'Broadcast updated successfully.']);
            } else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO region_broadcasts (region_id, message) VALUES (?, ?)");
                $stmt->execute([$target_region_id, $message]);
                $new_broadcast_id = $pdo->lastInsertId();
                
                // TRIGGER SYSTEM EVENT TO REGION STREAM
                logSystemEventToStream($pdo, $target_region_id, $user_id, 'system_broadcast', $message, $new_broadcast_id);

                // Blast Push Notification
                $usersStmt = $pdo->prepare("SELECT id FROM users WHERE region_id = ? AND attendance_status != 'Relocated'");
                $usersStmt->execute([$target_region_id]);
                $region_users = $usersStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($region_users)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, region_id, title, message, link_url) VALUES (?, ?, 'Regional Broadcast', ?, '/modules/regions/index.php')");
                    foreach ($region_users as $uid) {
                        $notifStmt->execute([$uid, $target_region_id, $message]);
                    }
                }
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Broadcast saved, pushed to stream, and notified ' . count($region_users) . ' members.']);
            }
            break;

        // 4. Autonomous Assignment Engine (The Jakande Pivot Logic)
        case 'admin_auto_assign':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }

            $stmt = $pdo->query("SELECT id, latitude, longitude FROM users WHERE region_id IS NULL AND latitude IS NOT NULL AND longitude IS NOT NULL");
            $unassigned = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($unassigned)) {
                echo json_encode(['status' => 'success', 'message' => 'No users with map coordinates are currently unassigned.']); exit;
            }

            $regions = $pdo->query("SELECT id, name FROM regions")->fetchAll(PDO::FETCH_ASSOC);
            $id_east = 1; $id_west = 2; $id_mainland = 3; 

            foreach ($regions as $r) {
                if (stripos($r['name'], '1') !== false || stripos($r['name'], 'Epe') !== false) $id_east = $r['id'];
                if (stripos($r['name'], '2') !== false || stripos($r['name'], 'Ikoyi') !== false) $id_west = $r['id'];
                if (stripos($r['name'], '3') !== false || stripos($r['name'], 'Mainland') !== false) $id_mainland = $r['id'];
            }

            $JAKANDE_LONGITUDE = 3.518500; 
            $MAINLAND_LATITUDE_THRESHOLD = 6.480000; 

            $assigned_count = 0;
            $pdo->beginTransaction();
            $updateStmt = $pdo->prepare("UPDATE users SET region_id = ? WHERE id = ?");

            foreach ($unassigned as $u) {
                $lat = (float)$u['latitude'];
                $lng = (float)$u['longitude'];
                $assigned_region = null;

                if ($lat > $MAINLAND_LATITUDE_THRESHOLD) {
                    $assigned_region = $id_mainland;
                } else {
                    if ($lng >= $JAKANDE_LONGITUDE) $assigned_region = $id_east;
                    else $assigned_region = $id_west;
                }

                if ($assigned_region) {
                    $updateStmt->execute([$assigned_region, $u['id']]);
                    $assigned_count++;
                }
            }
            $pdo->commit();

            echo json_encode(['status' => 'success', 'message' => "Algorithm complete. Auto-assigned {$assigned_count} members to geographic hubs."]);
            break;

        // 5. Add / Edit Region Metadata
        case 'admin_manage_region':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }

            $region_id = $_POST['region_id'] ?? null; 
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $dir_id = !empty($_POST['director_id']) ? $_POST['director_id'] : null;
            $pas_id = !empty($_POST['pastor_id']) ? $_POST['pastor_id'] : null;
            $head_id = !empty($_POST['head_id']) ? $_POST['head_id'] : null;
            $color = $_POST['color_code'] ?? '#1D356A';

            if (empty($name)) { echo json_encode(['status' => 'error', 'message' => 'Region name is required.']); exit; }

            if ($region_id) {
                $stmt = $pdo->prepare("UPDATE regions SET name=?, description=?, director_id=?, pastor_id=?, head_id=?, color_code=? WHERE id=?");
                $stmt->execute([$name, $desc, $dir_id, $pas_id, $head_id, $color, $region_id]);
                $msg = "Region updated successfully.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO regions (name, description, director_id, pastor_id, head_id, color_code) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $desc, $dir_id, $pas_id, $head_id, $color]);
                $msg = "New region established.";
            }

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // 6. Moderation: Soft Delete Stream Message
        case 'admin_delete_stream_message':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }
            $message_id = filter_var($_POST['message_id'] ?? '', FILTER_VALIDATE_INT);
            if ($message_id) {
                $stmt = $pdo->prepare("UPDATE region_stream SET is_deleted = 1 WHERE id = ?");
                $stmt->execute([$message_id]);
                echo json_encode(['status' => 'success', 'message' => 'Message removed from feed.']);
            }
            break;

        // 7. Moderation: Mute/Unmute User
        case 'admin_mute_user':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }
            $target_user = filter_var($_POST['target_user_id'] ?? '', FILTER_VALIDATE_INT);
            $mute_status = filter_var($_POST['mute_status'] ?? 0, FILTER_VALIDATE_INT); // 1 = Mute, 0 = Unmute
            if ($target_user) {
                $stmt = $pdo->prepare("UPDATE users SET is_muted_in_region = ? WHERE id = ?");
                $stmt->execute([$mute_status, $target_user]);
                echo json_encode(['status' => 'success', 'message' => $mute_status ? 'User muted.' : 'User privileges restored.']);
            }
            break;

        // 8. Utility: Fetch Leaders for the Dropdown during Region Creation
        case 'admin_fetch_leaders':
            if (!$is_admin) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }
            $stmt = $pdo->query("
                SELECT DISTINCT u.id, u.first_name, u.last_name, r.role_name
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                WHERE r.role_name IN ('Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD')
                ORDER BY r.role_name ASC, u.first_name ASC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Regions API Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A critical database error occurred. Contact the technical team.']);
} catch (Exception $e) {
    error_log("Regions API General Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected server error occurred.']);
}