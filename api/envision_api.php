<?php
// /api/envision_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Strict Security Gate
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Check Clearance: Super Admin, Pastors, or Envision Department
$has_clearance = false;
if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'])) {
    $has_clearance = true;
} else {
    $deptStmt = $pdo->prepare("
        SELECT d.id FROM departments d 
        JOIN user_departments ud ON d.id = ud.department_id 
        WHERE ud.user_id = ? AND ud.is_active = 1 AND d.name LIKE '%Envision%'
    ");
    $deptStmt->execute([$user_id]);
    if ($deptStmt->fetch()) $has_clearance = true;
}

if (!$has_clearance) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: Envision clearance required.']);
    exit;
}

// Ensure Upload Directories Exist
$cover_dir = '../uploads/sermons/covers/';
$audio_dir = '../uploads/sermons/audio/';
if (!is_dir($cover_dir)) mkdir($cover_dir, 0777, true);
if (!is_dir($audio_dir)) mkdir($audio_dir, 0777, true);

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH DASHBOARD DATA
        // ==========================================
        case 'fetch_dashboard':
            
            // A. Stats
            $stats = [
                'total_sermons' => $pdo->query("SELECT COUNT(*) FROM sermons")->fetchColumn(),
                'published_sermons' => $pdo->query("SELECT COUNT(*) FROM sermons WHERE is_published = 1")->fetchColumn(),
                'total_downloads' => $pdo->query("SELECT SUM(audio_downloads) FROM sermons")->fetchColumn() ?? 0,
                'pending_comments' => $pdo->query("SELECT COUNT(*) FROM sermon_comments WHERE is_approved = 0")->fetchColumn()
            ];

            // B. Fetch Master Sermons List
            $sermons = $pdo->query("
                SELECT *, DATE_FORMAT(date_preached, '%M %D, %Y') as nice_date,
                       (SELECT COUNT(*) FROM sermon_duties sd WHERE sd.sermon_id = sermons.id) as media_crew_count
                FROM sermons 
                ORDER BY date_preached DESC LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);

            // C. Fetch Envision Roster (Grouped)
            $rosterStmt = $pdo->query("
                SELECT e.id as roster_id, e.role_category, u.id as user_id, u.first_name, u.last_name, u.picture_path
                FROM envision_roster e
                JOIN users u ON e.user_id = u.id
                ORDER BY e.role_category ASC, u.first_name ASC
            ");
            $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            // D. Fetch Available members (Strictly NOT on the roster yet)
            $available = $pdo->query("
                SELECT u.id, u.first_name, u.last_name 
                FROM users u
                JOIN user_departments ud ON u.id = ud.user_id
                JOIN departments d ON ud.department_id = d.id
                WHERE d.name LIKE '%Envision%' AND ud.is_active = 1
                AND u.id NOT IN (SELECT user_id FROM envision_roster)
                ORDER BY u.first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'stats' => $stats,
                'sermons' => $sermons,
                'roster' => $roster,
                'available_members' => $available
            ]);
            break;

        // ==========================================
        // ACTION 2: ASSIGN TEAM MEMBER
        // ==========================================
        case 'assign_member':
            $target_id = $_POST['user_id'] ?? '';
            $category = $_POST['role_category'] ?? '';

            if (empty($target_id) || empty($category)) {
                echo json_encode(['status' => 'error', 'message' => 'User and role category required.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO envision_roster (user_id, role_category) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_category = VALUES(role_category)");
            $stmt->execute([$target_id, $category]);

            // NOTIFICATION TRIGGER: Inform the worker of their Envision roster assignment
            $cleanCategory = str_replace('_', ' ', $category);
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Envision Media Team', ?, '/modules/profile/index.php')");
            $notifStmt->execute([$target_id, "You have been officially placed on the Envision Media Roster as a {$cleanCategory} specialist."]);

            echo json_encode(['status' => 'success', 'message' => 'Media member assigned.']);
            break;

        // ==========================================
        // ACTION 3: SAVE SERMON (WITH FILE UPLOADS)
        // ==========================================
        case 'save_sermon':
            // 1. Detect if PHP dropped the payload because it exceeded post_max_size
            $content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
            $max_post_size = ini_get('post_max_size');
            
            // Helper function to convert shorthand sizes (e.g., 130M) to bytes
            function return_bytes($val) {
                $val = trim($val);
                if (empty($val)) return 0;
                $last = strtolower($val[strlen($val)-1]);
                $val = (int)$val;
                switch($last) {
                    case 'g': $val *= 1024;
                    case 'm': $val *= 1024;
                    case 'k': $val *= 1024;
                }
                return $val;
            }

            if ($content_length > 0 && empty($_POST) && empty($_FILES)) {
                if ($content_length > return_bytes($max_post_size)) {
                    echo json_encode(['status' => 'error', 'message' => 'Upload failed. The file is larger than the server allows (post_max_size exceeded).']);
                    exit;
                }
            }

            $sermon_id = $_POST['sermon_id'] ?? $_GET['sermon_id'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $preacher = trim($_POST['preacher'] ?? '');
            $date = $_POST['date_preached'] ?? '';
            $type = $_POST['service_type'] ?? 'Total Experience';
            $youtube = trim($_POST['youtube_link'] ?? '');
            $summary = trim($_POST['summary'] ?? '');
            $notes = trim($_POST['full_notes'] ?? '');
            
            if (empty($title) || empty($preacher) || empty($date)) {
                echo json_encode(['status' => 'error', 'message' => 'Title, preacher, and date are required. (If you filled these out, your file may still be too large).']);
                exit;
            }

            // SLUG GENERATOR: Creates a clean URL string (e.g., "the-sequence-of-faith-2026-09-06")
            $slug_string = $title . '-' . $date;
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $slug_string)));
            $slug = preg_replace('/-+/', '-', $slug);

            // Convert YouTube Link to Embed Format cleanly
            if (!empty($youtube) && strpos($youtube, 'watch?v=') !== false) {
                $youtube = str_replace('watch?v=', 'embed/', $youtube);
            }

            // Function to map upload errors to readable messages
            function get_upload_error($code) {
                $errors = [
                    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                    UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk. Check folder permissions.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
                ];
                return $errors[$code] ?? 'Unknown upload error.';
            }

            // Handle Cover Image Upload
            $cover_path = $_POST['existing_cover'] ?? null;
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION);
                    $filename = 'cover_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $cover_dir . $filename)) {
                        $cover_path = '/uploads/sermons/covers/' . $filename;
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Failed to save cover image. Check directory permissions (0777).']);
                        exit;
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Cover upload error: ' . get_upload_error($_FILES['cover_image']['error'])]);
                    exit;
                }
            }

            // Handle Audio Upload
            $audio_path = $_POST['existing_audio'] ?? null;
            if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['mp3', 'wav', 'm4a'])) {
                        $filename = 'audio_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $audio_dir . $filename)) {
                            $audio_path = '/uploads/sermons/audio/' . $filename;
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Failed to save audio file. Check directory permissions (0777).']);
                            exit;
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Invalid audio format. Please upload MP3, WAV, or M4A.']);
                        exit;
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Audio upload error: ' . get_upload_error($_FILES['audio_file']['error'])]);
                    exit;
                }
            }

            if (empty($sermon_id)) {
                $stmt = $pdo->prepare("INSERT INTO sermons (title, slug, preacher, date_preached, service_type, cover_image_path, audio_file_path, youtube_link, summary, full_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $preacher, $date, $type, $cover_path, $audio_path, $youtube, $summary, $notes]);
                $msg = "Sermon successfully archived!";
            } else {
                $stmt = $pdo->prepare("UPDATE sermons SET title=?, slug=?, preacher=?, date_preached=?, service_type=?, cover_image_path=?, audio_file_path=?, youtube_link=?, summary=?, full_notes=? WHERE id=?");
                $stmt->execute([$title, $slug, $preacher, $date, $type, $cover_path, $audio_path, $youtube, $summary, $notes, $sermon_id]);
                $msg = "Sermon details updated!";
            }

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 4: TOGGLE PUBLISH STATUS
        // ==========================================
        case 'toggle_publish':
            $sermon_id = $_POST['sermon_id'] ?? '';
            $is_published = $_POST['is_published'] ?? 0; // 1 or 0
            
            $stmt = $pdo->prepare("UPDATE sermons SET is_published = ? WHERE id = ?");
            $stmt->execute([$is_published, $sermon_id]);

            $msg = $is_published == 1 ? 'Sermon is now LIVE on the public portal!' : 'Sermon hidden from public view.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 5: MANAGE SERVICE DUTIES
        // ==========================================
        case 'fetch_duties':
            $sermon_id = $_POST['sermon_id'] ?? '';
            $stmt = $pdo->prepare("
                SELECT sd.id as duty_id, sd.duty_role, u.id as user_id, u.first_name, u.last_name, u.picture_path
                FROM sermon_duties sd
                JOIN users u ON sd.user_id = u.id
                WHERE sd.sermon_id = ?
                ORDER BY sd.duty_role ASC
            ");
            $stmt->execute([$sermon_id]);
            echo json_encode(['status' => 'success', 'duties' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'assign_duty':
            $sermon_id = $_POST['sermon_id'] ?? '';
            $user_id = $_POST['user_id'] ?? '';
            $role = trim($_POST['duty_role'] ?? '');

            if(empty($sermon_id) || empty($user_id) || empty($role)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields required to assign duty.']);
                exit;
            }

            // Transaction to ensure data integrity with the notification
            $pdo->beginTransaction();
            
            try {
                $stmt = $pdo->prepare("INSERT INTO sermon_duties (sermon_id, user_id, duty_role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE duty_role = VALUES(duty_role)");
                $stmt->execute([$sermon_id, $user_id, $role]);

                // NOTIFICATION TRIGGER: Alert the media worker of their specific service duty
                // First, fetch the sermon title/date for context
                $sermonStmt = $pdo->prepare("SELECT title, DATE_FORMAT(date_preached, '%b %D') as short_date FROM sermons WHERE id = ?");
                $sermonStmt->execute([$sermon_id]);
                $sermon = $sermonStmt->fetch(PDO::FETCH_ASSOC);

                if ($sermon) {
                    $cleanRole = str_replace('_', ' ', $role);
                    $notifMsg = "You have been assigned to '{$cleanRole}' for the upcoming service: {$sermon['title']} ({$sermon['short_date']}).";
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Service Duty Assignment', ?, '/modules/profile/index.php')");
                    $notifStmt->execute([$user_id, $notifMsg]);
                }

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Media duty logged!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        // ==========================================
        // ACTION 6: FETCH MODERATION COMMENTS
        // ==========================================
        case 'fetch_comments':
            $page = max(1, intval($_POST['page'] ?? 1));
            $limit = 50;
            $offset = ($page - 1) * $limit;
            $search = trim($_POST['search'] ?? '');

            $whereClause = "";
            $params = [];

            // Simple keyword filter logic
            if (!empty($search)) {
                $whereClause = "WHERE sc.comment_text LIKE ? OR sc.author_name LIKE ?";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            // Count total for pagination math
            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM sermon_comments sc $whereClause");
            $totalStmt->execute($params);
            $total = $totalStmt->fetchColumn();

            // Fetch the batch of 50
            $query = "
                SELECT sc.id, sc.author_name, sc.comment_text, DATE_FORMAT(sc.created_at, '%b %d %Y, %h:%i %p') as nice_date, s.title as sermon_title
                FROM sermon_comments sc
                LEFT JOIN sermons s ON sc.sermon_id = s.id
                $whereClause
                ORDER BY sc.created_at DESC
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            
            echo json_encode([
                'status' => 'success', 
                'comments' => $stmt->fetchAll(PDO::FETCH_ASSOC), 
                'total_pages' => ceil($total / $limit),
                'current_page' => $page,
                'total_records' => $total
            ]);
            break;

        // ==========================================
        // ACTION 7: DELETE/CENSOR COMMENT
        // ==========================================
        case 'delete_comment':
            $comment_id = $_POST['comment_id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM sermon_comments WHERE id = ?");
            $stmt->execute([$comment_id]);
            echo json_encode(['status' => 'success', 'message' => 'Comment permanently deleted.']);
            break;
            
            // ==========================================
        // ACTION 8: FETCH RADIO CONFIGURATION
        // ==========================================
        case 'fetch_radio_config':
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('zeno_stream_url', 'is_live_audio_on')");
            $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            echo json_encode([
                'status' => 'success', 
                'zeno_stream_url' => $config['zeno_stream_url'] ?? '',
                'is_live_audio_on' => (int)($config['is_live_audio_on'] ?? 0)
            ]);
            break;

        // ==========================================
        // ACTION 9: SAVE RADIO CONFIGURATION
        // ==========================================
        case 'save_radio_config':
            $stream_url = trim($_POST['zeno_stream_url'] ?? '');
            
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('zeno_stream_url', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$stream_url]);
            
            echo json_encode(['status' => 'success', 'message' => 'Broadcast settings saved successfully!']);
            break;

        // ==========================================
        // ACTION 10: TOGGLE LIVE RADIO STATUS
        // ==========================================
        case 'toggle_radio_live':
            $is_live = $_POST['is_live'] ?? 0; // 1 or 0
            
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('is_live_audio_on', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$is_live]);

            $msg = $is_live == 1 ? 'Live Audio Broadcast is now ON! The player is visible to the public.' : 'Broadcast ended. The player is now hidden.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 11: TEST ZENO.FM CONNECTION
        // ==========================================
        case 'test_radio_connection':
            $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'zeno_stream_url'");
            $stream_url = $stmt->fetchColumn();

            if (empty($stream_url) || !filter_var($stream_url, FILTER_VALIDATE_URL)) {
                echo json_encode(['status' => 'error', 'message' => 'Please save a valid Zeno.fm stream URL first.']);
                exit;
            }

            // Ping the stream URL to see if the server is broadcasting audio
            $ch = curl_init($stream_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true); // We only need headers, not the actual audio data
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400 && strpos($contentType, 'audio/') !== false) {
                echo json_encode(['status' => 'success', 'message' => 'Connection successful! Audio stream is active and responding correctly.']);
            } else {
                echo json_encode(['status' => 'warning', 'message' => 'The URL is reachable, but we could not detect an active audio stream. Ensure OBS is currently streaming to Zeno.fm.']);
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Envision API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
?>