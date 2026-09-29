<?php
// /api/events_api.php

// 1. Core Includes & Headers
require_once '../includes/db.php';
header('Content-Type: application/json');

/**
 * Helper: Process Event Banner Uploads
 */
function processEventBanner($file) {
    $target_dir = "../assets/uploads/event_banners/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $filename = uniqid('event_', true) . '.' . $ext;
    $target_path = $target_dir . $filename;

    if ($file["size"] > 4000000) return ['error' => 'Banner image too large. Max 4MB.'];
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) return ['error' => 'Only JPG, JPEG, PNG & WEBP allowed for the banner.'];

    if (move_uploaded_file($file["tmp_name"], $target_path)) {
        return ['success' => '/assets/uploads/event_banners/' . $filename];
    }
    return ['error' => 'Banner upload failed.'];
}

/**
 * Helper: Process Ministers / Speaker Image Upload
 */
function processMinistersImage($file) {
    $target_dir = "../assets/uploads/ministers/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $filename = uniqid('min_', true) . '.' . $ext;
    $target_path = $target_dir . $filename;

    if ($file["size"] > 4000000) return ['error' => 'Ministers image too large. Max 4MB.'];
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) return ['error' => 'Only JPG, JPEG, PNG & WEBP allowed for the ministers image.'];

    if (move_uploaded_file($file["tmp_name"], $target_path)) {
        return ['success' => '/assets/uploads/ministers/' . $filename];
    }
    return ['error' => 'Ministers image upload failed.'];
}

/**
 * Build the repeatable Ministers payload from the admin form.
 * Expects POST['ministers_json'] = JSON array of {name, image}
 *   where `image` is either '' (new upload pending) or an existing URL/path to keep.
 * New uploads arrive as $_FILES['minister_image'] (array, indexed to match).
 * Returns ['json'=>string, 'primary_image'=>?string] or ['error'=>string].
 */
function buildMinistersPayload() {
    $raw = $_POST['ministers_json'] ?? '[]';
    $list = json_decode($raw, true);
    if (!is_array($list)) $list = [];

    $files = $_FILES['minister_image'] ?? null;
    $final = [];
    $primary = null;

    foreach ($list as $i => $entry) {
        if (!is_array($entry)) $entry = ['name' => '', 'image' => ''];
        $name  = isset($entry['name'])  ? trim((string)$entry['name'])  : '';
        $image = isset($entry['image']) ? trim((string)$entry['image']) : '';

        // A new file was uploaded for this index?
        if ($files && isset($files['name'][$i]) && $files['name'][$i] !== '') {
            $single = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            $up = processMinistersImage($single);
            if (isset($up['error'])) return ['error' => $up['error']];
            $image = $up['success'];
        } elseif ($image !== '') {
            // Keep an existing URL/path only if it looks safe.
            if (!preg_match('#^(https?://|/)#i', $image)) $image = '';
        }

        if ($name === '' && $image === '') continue;
        $final[] = ['name' => $name, 'image' => $image];
        if ($primary === null && $image !== '') $primary = $image;
    }

    return ['json' => json_encode($final), 'primary_image' => $primary];
}

// 2. Security Check (Internal Module)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 3. RBAC Check (Only authorized leadership can manage events and take attendance)
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
$is_authorized = false;

if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles)) {
            $is_authorized = true;
            break;
        }
    }
}

if (!$is_authorized) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. You do not have clearance to manage events.']);
    exit;
}

// Allowed custom-field types for the parametric builder
$ALLOWED_FIELD_TYPES = ['text', 'textarea', 'email', 'number', 'date', 'select', 'radio', 'checkbox'];

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH DASHBOARD EVENTS (Tab 1 - Master List & KPIs)
        // =====================================================================================
        case 'fetch_events':
            $is_pastor = false;
            if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
                foreach ($_SESSION['roles'] as $role) {
                    if (in_array($role['role_name'], ['Resident_Pastor', 'Assoc_Pastor', 'Super_Admin'])) $is_pastor = true;
                }
            }
            $pastor_column = $is_pastor ? ", e.pastor_private_notes" : "";

            $stmt = $pdo->query("
                SELECT e.id, e.title, e.event_category, e.event_date, e.end_date, e.description, e.location, e.youtube_url, e.external_registration_url,
                       e.banner_image_url, e.ministers, e.ministers_image_url,
                       e.requires_registration, e.registration_token, e.is_closed, e.report_notes $pastor_column,
                       (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.status = 'Present') as total_attendance,
                       (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id) as total_registered
                FROM events e
                ORDER BY e.event_date DESC
                LIMIT 50
            ");
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $events]);
            break;

        // =====================================================================================
        // ACTION 2: CREATE NEW EVENT
        // =====================================================================================
        case 'create_event':
            $title = trim($_POST['title'] ?? '');
            $category = $_POST['event_category'] ?? '';
            $event_date = $_POST['event_date'] ?? '';
            $description = trim($_POST['description'] ?? '') ?: null;
            $location = trim($_POST['location'] ?? '') ?: null;
            $youtube_url = trim($_POST['youtube_url'] ?? '') ?: null;
            $external_registration_url = trim($_POST['external_registration_url'] ?? '') ?: null;
            $ministers = trim($_POST['ministers'] ?? '') ?: null;

            $is_custom_period = isset($_POST['is_custom_period']) && $_POST['is_custom_period'] == '1';
            $end_date = null;
            if ($is_custom_period && !empty($_POST['end_date'])) {
                $end_date = $_POST['end_date'];
            }

            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $tribe_id = !empty($_POST['tribe_id']) ? $_POST['tribe_id'] : null;

            $requires_registration = isset($_POST['requires_registration']) ? 1 : 0;
            $allow_visitors = isset($_POST['allow_visitors']) ? 1 : 0;
            $token = null;

            if (empty($title) || empty($category) || empty($event_date)) {
                echo json_encode(['status' => 'error', 'message' => 'Title, Category, and Date are mandatory fields.']);
                exit;
            }

            if ($end_date && strtotime($end_date) < strtotime($event_date)) {
                echo json_encode(['status' => 'error', 'message' => 'The end date cannot be before the start date.']);
                exit;
            }

            // Banner image upload
            $banner_image_url = null;
            if (!empty($_FILES['banner_image']['name'])) {
                $upload = processEventBanner($_FILES['banner_image']);
                if (isset($upload['error'])) {
                    echo json_encode(['status' => 'error', 'message' => $upload['error']]);
                    exit;
                }
                $banner_image_url = $upload['success'];
            }

            // Ministers (repeatable: name + photo per minister)
            $ministersPayload = buildMinistersPayload();
            if (isset($ministersPayload['error'])) {
                echo json_encode(['status' => 'error', 'message' => $ministersPayload['error']]);
                exit;
            }
            $ministers_json = $ministersPayload['json'];
            $ministers_image_url = $ministersPayload['primary_image'];

            if ($requires_registration) {
                $token = bin2hex(random_bytes(16));
            }

            // Update your INSERT query and execute array:
            $stmt = $pdo->prepare("
                INSERT INTO events (title, event_category, event_date, end_date, description, location, youtube_url, external_registration_url,
                                    banner_image_url, ministers, ministers_image_url,
                                    department_id, tribe_id, created_by, requires_registration, allow_visitors, registration_token)
                VALUES (:title, :category, :edate, :end_date, :description, :location, :youtube_url, :ext_reg, :banner, :ministers, :min_img, :dept, :tribe, :uid, :req_reg, :allow_vis, :token)
            ");

            $stmt->execute([
                'title' => $title,
                'category' => $category,
                'edate' => $event_date,
                'end_date' => $end_date,
                'description' => $description,
                'location' => $location,
                'youtube_url' => $youtube_url,
                'ext_reg' => $external_registration_url,
                'banner' => $banner_image_url,
                'ministers' => $ministers_json,
                'min_img' => $ministers_image_url,
                'dept' => $department_id,
                'tribe' => $tribe_id,
                'uid' => $user_id,
                'req_reg' => $requires_registration,
                'allow_vis' => $allow_visitors,
                'token' => $token
            ]);

            $new_event_id = $pdo->lastInsertId();

            // Notification trigger for department leaders
            if ($department_id) {
                $leaderStmt = $pdo->prepare("SELECT user_id FROM user_departments WHERE department_id = ? AND role_in_dept IN ('HOD', 'Director') AND is_active = 1");
                $leaderStmt->execute([$department_id]);
                $dept_leaders = $leaderStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($dept_leaders)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Department Event', ?, '/modules/events/index.php')");
                    $nice_date = date('M jS, Y', strtotime($event_date));
                    foreach ($dept_leaders as $leader_id) {
                        if ($leader_id != $user_id) {
                            $notifStmt->execute([$leader_id, "A new event '{$title}' has been scheduled for your department on {$nice_date}."]);
                        }
                    }
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Event created successfully!',
                'event_id' => $new_event_id,
                'token' => $token
            ]);
            break;

        // =====================================================================================
        // ACTION 2.5: CREATE THE RECURRING MONTHLY SUNDAY + MIDWEEK SERVICES
        // =====================================================================================
        case 'create_monthly_services':
            $month = trim((string)($_POST['month'] ?? ''));
            if (!preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/', $month, $monthParts)) {
                echo json_encode(['status' => 'error', 'message' => 'Please select a valid month.']);
                exit;
            }

            $year = (int)$monthParts[1];
            $monthNumber = (int)$monthParts[2];
            $monthStart = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                sprintf('%04d-%02d-01', $year, $monthNumber),
                new DateTimeZone('Africa/Lagos')
            );
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$monthStart || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                echo json_encode(['status' => 'error', 'message' => 'Please select a valid month.']);
                exit;
            }

            $location = 'Hebron, Kon-X Building, Beside Scapular Plaza, Agungi, Lekki';
            $daysInMonth = (int)$monthStart->format('t');
            $lastDay = $monthStart->modify('last day of this month');
            $lastSundayDay = $daysInMonth - (int)$lastDay->format('w');
            $serviceRows = [];

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $serviceDate = $monthStart->setDate($year, $monthNumber, $day);
                $weekday = (int)$serviceDate->format('w'); // Sunday = 0, Thursday = 4

                if ($weekday === 0) {
                    $isThanksgiving = ($day === $lastSundayDay);
                    $serviceRows[] = [
                        'title' => $isThanksgiving ? 'Total Experience - Thanksgiving Service' : 'Total Experience',
                        'category' => 'Sunday_Service',
                        'event_date' => $serviceDate->format('Y-m-d') . ' 09:30:00',
                        'description' => $isThanksgiving
                            ? 'Join us for our monthly Thanksgiving Service as we give thanks to God for His faithfulness.'
                            : 'A time of worship, the Word and fellowship at our Total Experience Sunday Service.',
                    ];
                } elseif ($weekday === 4) {
                    $serviceRows[] = [
                        'title' => 'Mercy Experience',
                        'category' => 'Midweek_Service',
                        'event_date' => $serviceDate->format('Y-m-d') . ' 18:30:00',
                        'description' => 'Join us for Mercy Experience, our midweek service for worship, the Word and fellowship.',
                    ];
                }
            }

            $created = [];
            $skipped = [];
            $existingStmt = $pdo->prepare(
                'SELECT id FROM events WHERE title = ? AND event_category = ? AND event_date = ? LIMIT 1'
            );
            $insertStmt = $pdo->prepare('
                INSERT INTO events (
                    title, event_category, event_date, end_date, description, location,
                    youtube_url, external_registration_url, banner_image_url, ministers,
                    ministers_image_url, department_id, tribe_id, created_by,
                    requires_registration, allow_visitors, registration_token
                ) VALUES (?, ?, ?, NULL, ?, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, 0, 0, NULL)
            ');

            try {
                $pdo->beginTransaction();
                foreach ($serviceRows as $service) {
                    $existingStmt->execute([$service['title'], $service['category'], $service['event_date']]);
                    $existingId = $existingStmt->fetchColumn();
                    if ($existingId !== false) {
                        $skipped[] = ['id' => (int)$existingId, 'title' => $service['title'], 'event_date' => $service['event_date']];
                        continue;
                    }

                    $insertStmt->execute([
                        $service['title'],
                        $service['category'],
                        $service['event_date'],
                        $service['description'],
                        $location,
                        $user_id,
                    ]);
                    $created[] = [
                        'id' => (int)$pdo->lastInsertId(),
                        'title' => $service['title'],
                        'event_date' => $service['event_date'],
                    ];
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $monthLabel = $monthStart->format('F Y');
            $createdCount = count($created);
            $skippedCount = count($skipped);
            if ($createdCount > 0 && $skippedCount > 0) {
                $message = "Created {$createdCount} service" . ($createdCount === 1 ? '' : 's') . " for {$monthLabel}; {$skippedCount} already existed and were skipped.";
            } elseif ($createdCount > 0) {
                $message = "Created {$createdCount} service" . ($createdCount === 1 ? '' : 's') . " for {$monthLabel}.";
            } else {
                $message = "All {$skippedCount} services for {$monthLabel} already exist. Nothing was duplicated.";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $message,
                'month' => $month,
                'created_count' => $createdCount,
                'skipped_count' => $skippedCount,
                'created' => $created,
                'skipped' => $skipped,
            ]);
            break;

        // =====================================================================================
        // ACTION 3: UPDATE EXISTING EVENT (Parametric edit)
        // =====================================================================================
        case 'update_event':
            $event_id = $_POST['event_id'] ?? '';
            if (empty($event_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Event ID is required.']);
                exit;
            }

            $title = trim($_POST['title'] ?? '');
            $category = $_POST['event_category'] ?? '';
            $event_date = $_POST['event_date'] ?? '';
            $description = trim($_POST['description'] ?? '') ?: null;
            $location = trim($_POST['location'] ?? '') ?: null;
            $youtube_url = trim($_POST['youtube_url'] ?? '') ?: null;
            $external_registration_url = trim($_POST['external_registration_url'] ?? '') ?: null;
            $ministers = trim($_POST['ministers'] ?? '') ?: null;

            $is_custom_period = isset($_POST['is_custom_period']) && $_POST['is_custom_period'] == '1';
            $end_date = null;
            if ($is_custom_period && !empty($_POST['end_date'])) {
                $end_date = $_POST['end_date'];
            }

            $requires_registration = isset($_POST['requires_registration']) ? 1 : 0;
            $allow_visitors = isset($_POST['allow_visitors']) ? 1 : 0;

            if (empty($title) || empty($category) || empty($event_date)) {
                echo json_encode(['status' => 'error', 'message' => 'Title, Category, and Date are mandatory fields.']);
                exit;
            }
            if ($end_date && strtotime($end_date) < strtotime($event_date)) {
                echo json_encode(['status' => 'error', 'message' => 'The end date cannot be before the start date.']);
                exit;
            }

            // Resolve current images AND existing token
            $curStmt = $pdo->prepare("SELECT banner_image_url, ministers, ministers_image_url, registration_token FROM events WHERE id = ?");
            $curStmt->execute([$event_id]);
            $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
            $banner_image_url = $cur['banner_image_url'] ?? null;
            $ministers_image_url = $cur['ministers_image_url'] ?? null;
            $ministers_json = $cur['ministers'] ?? null;
            $token = $cur['registration_token'] ?? null;

            // Generate token if required but doesn't exist yet
            if ($requires_registration && empty($token)) {
                $token = bin2hex(random_bytes(16));
            }

            if (!empty($_FILES['banner_image']['name'])) {
                $upload = processEventBanner($_FILES['banner_image']);
                if (isset($upload['error'])) { echo json_encode(['status' => 'error', 'message' => $upload['error']]); exit; }
                $banner_image_url = $upload['success'];
            }

            if (isset($_POST['ministers_json']) && $_POST['ministers_json'] !== '') {
                $ministersPayload = buildMinistersPayload();
                if (isset($ministersPayload['error'])) { echo json_encode(['status' => 'error', 'message' => $ministersPayload['error']]); exit; }
                $ministers_json = $ministersPayload['json'];
                $ministers_image_url = $ministersPayload['primary_image'];
            }

            // Update your UPDATE query:
            $stmt = $pdo->prepare("
                UPDATE events
                SET title = :title, event_category = :category, event_date = :edate, end_date = :end_date,
                    description = :description, location = :location, youtube_url = :youtube_url, external_registration_url = :ext_reg, ministers = :ministers,
                    banner_image_url = :banner, ministers_image_url = :min_img,
                    requires_registration = :req_reg, allow_visitors = :allow_vis, registration_token = :token
                WHERE id = :id
            ");
            
            // Update the execute array to include 'ext_reg' => $external_registration_url
            $stmt->execute([
                'title' => $title, 'category' => $category, 'edate' => $event_date, 'end_date' => $end_date,
                'description' => $description, 'location' => $location, 'youtube_url' => $youtube_url, 'ext_reg' => $external_registration_url, 'ministers' => $ministers_json,
                'banner' => $banner_image_url, 'min_img' => $ministers_image_url,
                'req_reg' => $requires_registration, 'allow_vis' => $allow_visitors, 'token' => $token,
                'id' => $event_id
            ]);

            echo json_encode(['status' => 'success', 'message' => 'Event updated successfully.', 'token' => $token]);
            break;

        // =====================================================================================
        // ACTION 3: FETCH STRICT ATTENDANCE ROSTER
        // =====================================================================================
        case 'fetch_attendance_roster':
            $event_id = $_POST['event_id'] ?? '';
            if (empty($event_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Event ID is required.']);
                exit;
            }

            $eventStmt = $pdo->prepare("SELECT event_category, title, department_id, tribe_id FROM events WHERE id = ?");
            $eventStmt->execute([$event_id]);
            $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                echo json_encode(['status' => 'error', 'message' => 'Event not found.']);
                exit;
            }

            $baseQuery = "
                SELECT u.id, u.first_name, u.last_name, u.gender, u.spiritual_status, u.phone,
                       a.id as attendance_id, a.check_in_time
                FROM users u
                LEFT JOIN attendance a ON u.id = a.user_id AND a.event_id = :eid
                WHERE u.attendance_status != 'Relocated'
            ";
            $params = ['eid' => $event_id];

            if ($event['event_category'] === 'Workers_Meeting' || stripos($event['title'], 'worker') !== false) {
                $baseQuery .= " AND u.spiritual_status IN ('Worker', 'Pastor')";
            } elseif (!empty($event['department_id'])) {
                $baseQuery .= " AND u.id IN (SELECT user_id FROM user_departments WHERE department_id = :dept_id AND is_active = 1)";
                $params['dept_id'] = $event['department_id'];
            } elseif (!empty($event['tribe_id'])) {
                $currentSeason = date('Y');
                $baseQuery .= " AND u.id IN (SELECT user_id FROM user_tribes WHERE tribe_id = :tribe_id AND season = :season)";
                $params['tribe_id'] = $event['tribe_id'];
                $params['season'] = $currentSeason;
            }

            $baseQuery .= " ORDER BY a.check_in_time DESC, u.first_name ASC";

            $rosterStmt = $pdo->prepare($baseQuery);
            $rosterStmt->execute($params);
            $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            $pending = [];
            $checked_in = [];
            foreach ($roster as $person) {
                if ($person['check_in_time']) $checked_in[] = $person;
                else $pending[] = $person;
            }

            echo json_encode(['status' => 'success', 'pending' => $pending, 'checked_in' => $checked_in, 'event_info' => $event]);
            break;

        // =====================================================================================
        // ACTION 4: MARK ATTENDANCE
        // =====================================================================================
        case 'mark_attendance':
            $event_id = $_POST['event_id'] ?? '';
            $target_user_id = $_POST['user_id'] ?? '';

            if (empty($event_id) || empty($target_user_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing event or user data.']);
                exit;
            }

                        // Dedup 1: already in the attendance table for this event+user (existing behaviour)
            $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE event_id = ? AND user_id = ?");
            $checkStmt->execute([$event_id, $target_user_id]);
            if ($checkStmt->fetch()) {
                echo json_encode(['status' => 'warning', 'message' => 'User is already checked in.']);
                exit;
            }

            // Dedup 2: already checked in today via the QR check-in system
            $today = date('Y-m-d');
            $ciStmt = $pdo->prepare("SELECT id FROM checkins WHERE event_id = ? AND user_id = ? AND checkin_date = ?");
            $ciStmt->execute([$event_id, $target_user_id, $today]);
            if ($ciStmt->fetch()) {
                echo json_encode(['status' => 'warning', 'message' => 'User already checked in via QR check-in today.']);
                exit;
            }

            $attStmt = $pdo->prepare("
                INSERT INTO attendance (event_id, user_id, status, check_in_time, checked_in_by, attendance_date)
                VALUES (?, ?, 'Present', NOW(), ?, ?)
            ");
            $attStmt->execute([$event_id, $target_user_id, $user_id, $today]);

            $timeStmt = $pdo->prepare("SELECT DATE_FORMAT(check_in_time, '%h:%i %p') as time_format FROM attendance WHERE id = ?");
            $timeStmt->execute([$pdo->lastInsertId()]);
            $timeData = $timeStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'message' => 'Attendance logged successfully.', 'clock_time' => $timeData['time_format']]);
            break;

        // =====================================================================================
        // ACTION 4B: CLOCK OUT (UNDO A MISTAKEN CLOCK-IN)
        // =====================================================================================
        case 'clock_out':
            $event_id = $_POST['event_id'] ?? '';
            $target_user_id = $_POST['user_id'] ?? '';

            if (empty($event_id) || empty($target_user_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing event or user data.']);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM attendance WHERE event_id = ? AND user_id = ?");
            $delStmt->execute([$event_id, $target_user_id]);

            if ($delStmt->rowCount() === 0) {
                echo json_encode(['status' => 'warning', 'message' => 'User was not checked in.']);
                exit;
            }

            echo json_encode(['status' => 'success', 'message' => 'Attendee moved back to pending.']);
            break;

        // =====================================================================================
        // ACTION 5: ADD CUSTOM REGISTRATION FIELD
        // =====================================================================================
        case 'add_custom_field':
            $event_id = $_POST['event_id'] ?? '';
            $label = trim($_POST['field_label'] ?? '');
            $type = $_POST['field_type'] ?? 'text';
            $is_required = isset($_POST['is_required']) ? 1 : 0;
            $placeholder = trim($_POST['placeholder'] ?? '') ?: null;

            if (!in_array($type, $ALLOWED_FIELD_TYPES)) $type = 'text';

            $options = null;
            if (in_array($type, ['select', 'radio', 'checkbox']) && !empty($_POST['field_options'])) {
                $options_array = array_map('trim', explode(',', $_POST['field_options']));
                $options = json_encode($options_array);
            }

            if (empty($event_id) || empty($label)) {
                echo json_encode(['status' => 'error', 'message' => 'Field label is required.']);
                exit;
            }

            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(field_order), 0) + 1 AS next_order FROM event_custom_fields WHERE event_id = ?");
            $orderStmt->execute([$event_id]);
            $next_order = $orderStmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO event_custom_fields (event_id, field_label, field_type, field_options, is_required, placeholder, field_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$event_id, $label, $type, $options, $is_required, $placeholder, $next_order]);

            echo json_encode(['status' => 'success', 'message' => 'Custom field added to public form.']);
            break;

        // =====================================================================================
        // ACTION 5.5: UPDATE CUSTOM FIELD
        // =====================================================================================
        case 'update_custom_field':
            $field_id = $_POST['field_id'] ?? '';
            $label = trim($_POST['field_label'] ?? '');
            $type = $_POST['field_type'] ?? 'text';
            $is_required = isset($_POST['is_required']) ? 1 : 0;
            $placeholder = trim($_POST['placeholder'] ?? '') ?: null;

            if (!in_array($type, $ALLOWED_FIELD_TYPES)) $type = 'text';

            $options = null;
            if (in_array($type, ['select', 'radio', 'checkbox']) && !empty($_POST['field_options'])) {
                $options_array = array_map('trim', explode(',', $_POST['field_options']));
                $options = json_encode($options_array);
            }

            if (empty($field_id) || empty($label)) {
                echo json_encode(['status' => 'error', 'message' => 'Field label is required.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE event_custom_fields SET field_label = ?, field_type = ?, field_options = ?, is_required = ?, placeholder = ? WHERE id = ?");
            $stmt->execute([$label, $type, $options, $is_required, $placeholder, $field_id]);

            echo json_encode(['status' => 'success', 'message' => 'Field updated.']);
            break;

        // =====================================================================================
        // ACTION 5.6: DELETE CUSTOM FIELD
        // =====================================================================================
        case 'delete_custom_field':
            $field_id = $_POST['field_id'] ?? '';
            if (empty($field_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Field ID is required.']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM event_custom_fields WHERE id = ?");
            $stmt->execute([$field_id]);
            echo json_encode(['status' => 'success', 'message' => 'Field deleted.']);
            break;

        // =====================================================================================
        // ACTION 5.7: REORDER CUSTOM FIELDS
        // Expects POST['ordered_ids'] = comma separated ids in new order
        // =====================================================================================
        case 'reorder_custom_fields':
            $event_id = $_POST['event_id'] ?? '';
            $ordered = $_POST['ordered_ids'] ?? '';
            if (empty($event_id) || empty($ordered)) {
                echo json_encode(['status' => 'error', 'message' => 'Nothing to reorder.']);
                exit;
            }
            $ids = array_filter(array_map('intval', explode(',', $ordered)));
            foreach ($ids as $idx => $fid) {
                $stmt = $pdo->prepare("UPDATE event_custom_fields SET field_order = ? WHERE id = ? AND event_id = ?");
                $stmt->execute([$idx, $fid, $event_id]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Order updated.']);
            break;

        // =====================================================================================
        // ACTION 6: FETCH REGISTRANTS & CUSTOM FIELDS
        // =====================================================================================
        case 'fetch_manage_data':
            $event_id = $_POST['event_id'] ?? '';

            $fStmt = $pdo->prepare("SELECT * FROM event_custom_fields WHERE event_id = ? ORDER BY field_order ASC, id ASC");
            $fStmt->execute([$event_id]);
            $fields = $fStmt->fetchAll(PDO::FETCH_ASSOC);

            $rStmt = $pdo->prepare("
                SELECT r.*, u.first_name, u.last_name, u.phone as member_phone,
                       m.first_name as matched_first_name, m.last_name as matched_last_name, m.phone as matched_phone
                FROM event_registrations r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN users m ON r.matched_user_id = m.id
                WHERE r.event_id = ?
                ORDER BY r.registered_at DESC
            ");
            $rStmt->execute([$event_id]);
            $registrants = $rStmt->fetchAll(PDO::FETCH_ASSOC);

            $dayStmt = $pdo->prepare("SELECT attendance_date FROM event_registration_days WHERE registration_id = ? ORDER BY attendance_date ASC");
            foreach ($registrants as &$r) {
                $dayStmt->execute([$r['id']]);
                $r['attendance_days'] = $dayStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            unset($r);

            echo json_encode(['status' => 'success', 'fields' => $fields, 'registrants' => $registrants]);
            break;

        // =====================================================================================
        // ACTION 6.5: CONFIRM OR REJECT A SUGGESTED FUZZY MATCH
        // =====================================================================================
        case 'confirm_match':
            $registration_id = $_POST['registration_id'] ?? '';
            $decision = $_POST['decision'] ?? '';

            if (empty($registration_id) || !in_array($decision, ['confirm', 'reject'])) {
                echo json_encode(['status' => 'error', 'message' => 'Missing or invalid match decision.']);
                exit;
            }

            $regStmt = $pdo->prepare("SELECT matched_user_id FROM event_registrations WHERE id = ?");
            $regStmt->execute([$registration_id]);
            $reg = $regStmt->fetch(PDO::FETCH_ASSOC);

            if (!$reg || empty($reg['matched_user_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'No suggested match found for this registration.']);
                exit;
            }

            if ($decision === 'confirm') {
                $upd = $pdo->prepare("UPDATE event_registrations SET user_id = matched_user_id, match_status = 'confirmed' WHERE id = ?");
                $upd->execute([$registration_id]);
                echo json_encode(['status' => 'success', 'message' => 'Match confirmed. Registration linked to the member profile.']);
            } else {
                $upd = $pdo->prepare("UPDATE event_registrations SET match_status = 'rejected' WHERE id = ?");
                $upd->execute([$registration_id]);
                echo json_encode(['status' => 'success', 'message' => 'Suggested match dismissed. Registration kept as a separate guest entry.']);
            }
            break;

        // =====================================================================================
        // ACTION 7: SAVE EVENT REPORT
        // =====================================================================================
        case 'save_report':
            $event_id = $_POST['event_id'] ?? '';
            $notes = trim($_POST['report_notes'] ?? '');

            if (isset($_POST['pastor_private_notes'])) {
                $private_notes = trim($_POST['pastor_private_notes']);
                $stmt = $pdo->prepare("UPDATE events SET report_notes = ?, pastor_private_notes = ? WHERE id = ?");
                $stmt->execute([$notes, $private_notes, $event_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE events SET report_notes = ? WHERE id = ?");
                $stmt->execute([$notes, $event_id]);
            }

            $pastorStmt = $pdo->query("
                SELECT user_id FROM user_roles
                JOIN roles ON user_roles.role_id = roles.id
                WHERE roles.role_name IN ('Resident_Pastor', 'Assoc_Pastor', 'Super_Admin')
            ");
            $pastors = $pastorStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($pastors)) {
                $evtStmt = $pdo->prepare("SELECT title FROM events WHERE id = ?");
                $evtStmt->execute([$event_id]);
                $evtTitle = $evtStmt->fetchColumn() ?: 'an event';

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Event Report Updated', ?, '/modules/events/index.php')");
                $alertMsg = "The post-event report for '{$evtTitle}' has been submitted/updated and is ready for review.";

                foreach ($pastors as $uid) {
                    if ($uid != $user_id) {
                        $notifStmt->execute([$uid, $alertMsg]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Event report saved successfully.']);
            break;

        // =====================================================================================
        // ACTION 8: CLOSE EVENT
        // =====================================================================================
        case 'close_event':
            $event_id = $_POST['event_id'] ?? '';
            $stmt = $pdo->prepare("UPDATE events SET is_closed = 1 WHERE id = ?");
            $stmt->execute([$event_id]);
            echo json_encode(['status' => 'success', 'message' => 'Event officially closed and locked.']);
            break;

        // =====================================================================================
        // ACTION 8.5: UNLOCK EVENT (SUPER ADMIN ONLY)
        // =====================================================================================
        case 'unlock_event':
            $is_super_admin = false;
            if (isset($_SESSION['roles'])) {
                foreach ($_SESSION['roles'] as $role) {
                    if ($role['role_name'] === 'Super_Admin') { $is_super_admin = true; break; }
                }
            }
            if (!$is_super_admin) {
                echo json_encode(['status' => 'error', 'message' => 'Action restricted to Super Administrators only.']);
                exit;
            }

            $event_id = $_POST['event_id'] ?? '';
            $stmt = $pdo->prepare("UPDATE events SET is_closed = 0 WHERE id = ?");
            $stmt->execute([$event_id]);

            $creatorStmt = $pdo->prepare("SELECT title, created_by FROM events WHERE id = ?");
            $creatorStmt->execute([$event_id]);
            $eventData = $creatorStmt->fetch(PDO::FETCH_ASSOC);

            if ($eventData && $eventData['created_by']) {
                if ($eventData['created_by'] != $user_id) {
                    $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Event Unlocked', ?, '/modules/events/index.php')")
                        ->execute([$eventData['created_by'], "Super Admin Override: Your event '{$eventData['title']}' has been unlocked. You may now modify attendance and report records."]);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Super Admin Override: Event unlocked.']);
            break;

        // =====================================================================================
        // ACTION 9: FETCH CLOCKED-IN USERS
        // =====================================================================================
        case 'fetch_clocked_in_users':
            $event_id = $_POST['event_id'] ?? '';
            $stmt = $pdo->prepare("
                SELECT u.first_name as attendee_fname, u.last_name as attendee_lname,
                       DATE_FORMAT(a.check_in_time, '%h:%i %p') as time_in,
                       admin.first_name as admin_fname, admin.last_name as admin_lname
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN users admin ON a.checked_in_by = admin.id
                WHERE a.event_id = ? AND a.status = 'Present'
                ORDER BY a.check_in_time DESC
            ");
            $stmt->execute([$event_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
            
        // ==========================================
        // FETCH REGISTRATIONS (With KPIs, Search & Pagination)
        // ==========================================
        case 'fetch_registrations':
            $event_id = (int)($_POST['event_id'] ?? 0);
            $page = max(1, (int)($_POST['page'] ?? 1));
            $search = trim($_POST['search'] ?? '');
            $limit = 50; 
            $offset = ($page - 1) * $limit;

            if (!$event_id) {
                echo json_encode(['status' => 'error', 'message' => 'Event ID is missing.']);
                exit;
            }

            // --- 1. KPI CALCULATIONS ---
            // Total Registrations
            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ?");
            $totalStmt->execute([$event_id]);
            $total_all = $totalStmt->fetchColumn();

            // Registered Today (Fixed to use registered_at)
            $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND DATE(registered_at) = CURDATE()");
            $todayStmt->execute([$event_id]);
            $total_today = $todayStmt->fetchColumn();

            // Registered Yesterday (Fixed to use registered_at)
            $yestStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND DATE(registered_at) = CURDATE() - INTERVAL 1 DAY");
            $yestStmt->execute([$event_id]);
            $total_yesterday = $yestStmt->fetchColumn();

            // Calculate Growth Trend
            $growth_percentage = 0;
            if ($total_yesterday > 0) {
                $growth_percentage = round((($total_today - $total_yesterday) / $total_yesterday) * 100);
            } elseif ($total_today > 0) {
                $growth_percentage = 100; // Spiked from 0
            }

            // --- 2. SERVER-SIDE SEARCH & PAGINATION ---
            $params = [$event_id];
            $whereSql = "WHERE r.event_id = ?";

            // Added complex search targeting both Members and Guests
            if (!empty($search)) {
                $whereSql .= " AND (
                    CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR 
                    r.guest_name LIKE ? OR 
                    u.email LIKE ? OR 
                    r.guest_email LIKE ? OR 
                    u.phone LIKE ? OR 
                    r.guest_phone LIKE ?
                )";
                $searchParam = "%{$search}%";
                array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
            }

            // Centralized JOIN logic to pull member data
            $baseJoin = "
                FROM event_registrations r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN users m ON r.matched_user_id = m.id
            ";

            // Get total matching rows for accurate pagination
            $countStmt = $pdo->prepare("SELECT COUNT(*) $baseJoin $whereSql");
            $countStmt->execute($params);
            $filtered_total = $countStmt->fetchColumn();
            $total_pages = ceil($filtered_total / $limit);

            // Fetch the actual paginated data with correct JOINs
            $dataStmt = $pdo->prepare("
                SELECT r.*, 
                       u.first_name, u.last_name, u.phone as member_phone, u.email as member_email,
                       m.first_name as matched_first_name, m.last_name as matched_last_name, m.phone as matched_phone,
                       DATE_FORMAT(r.registered_at, '%b %d %Y, %h:%i %p') as nice_date 
                $baseJoin
                $whereSql 
                ORDER BY r.registered_at DESC 
                LIMIT $limit OFFSET $offset
            ");
            $dataStmt->execute($params);

            echo json_encode([
                'status' => 'success',
                'kpis' => [
                    'total' => $total_all,
                    'today' => $total_today,
                    'yesterday' => $total_yesterday,
                    'growth' => $growth_percentage
                ],
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'total_records' => $filtered_total,
                    'offset' => $offset
                ],
                'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
            break;
            
                    // =====================================================================
        // ATTENDANCE KPIS — live check-in statistics for one event
        // =====================================================================
        case 'attendance_kpis':
            $event_id = (int)($_POST['event_id'] ?? 0);
            if (!$event_id) { echo json_encode(['status'=>'error','message'=>'Event ID is required.']); exit; }

            // Total check-ins (all days)
            $t = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE event_id = ?");
            $t->execute([$event_id]); $total = (int)$t->fetchColumn();

            // Checked in today
            $td = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE event_id = ? AND checkin_date = ?");
            $td->execute([$event_id, date('Y-m-d')]); $today = (int)$td->fetchColumn();

            // Checked in yesterday
            $ye = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE event_id = ? AND checkin_date = ?");
            $ye->execute([$event_id, date('Y-m-d', strtotime('-1 day'))]); $yesterday = (int)$ye->fetchColumn();

            // Members vs walk-ins
            $mix = $pdo->prepare("SELECT SUM(is_member=1) AS members, SUM(is_walkin=1) AS walkins FROM checkins WHERE event_id = ?");
            $mix->execute([$event_id]); $m = $mix->fetch(PDO::FETCH_ASSOC);

            // Per-day breakdown
            $days = $pdo->prepare("SELECT checkin_date, COUNT(*) AS n FROM checkins WHERE event_id = ? GROUP BY checkin_date ORDER BY checkin_date");
            $days->execute([$event_id]); $byDay = $days->fetchAll(PDO::FETCH_ASSOC);

            // Registered total (from event_registrations) for context
            $reg = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ?");
            $reg->execute([$event_id]); $registered = (int)$reg->fetchColumn();

            // growth trend (today vs yesterday)
            $growth = 0;
            if ($yesterday > 0) $growth = round((($today - $yesterday) / $yesterday) * 100);
            elseif ($today > 0) $growth = 100;

            echo json_encode([
                'status'=>'success',
                'kpis'=>[
                    'total'=>$total,
                    'today'=>$today,
                    'yesterday'=>$yesterday,
                    'growth'=>$growth,
                    'members'=>(int)($m['members']??0),
                    'walkins'=>(int)($m['walkins']??0),
                    'registered'=>$registered,
                    'by_day'=>$byDay,
                ]
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Events API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred while processing the request.']);
}
?>
