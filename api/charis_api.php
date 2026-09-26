<?php
// /api/charis_api.php

// 1. Core Includes & Headers
require_once '../includes/db.php';
header('Content-Type: application/json');

// 2. Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 3. Robust RBAC Check (The Lanyard System)
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'];
$is_charis_admin = false;

// Check ALL roles the user possesses, not just their primary active one
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (isset($role['role_name']) && in_array($role['role_name'], $allowed_roles)) {
            $is_charis_admin = true;
            break; // Found an admin badge! Stop checking.
        }
    }
}

try {
    switch ($action) {

        // ==========================================
        // ACTION: TRIGGER SYSTEM ALERTS (CRON OR MANUAL CALL)
        // ==========================================
        case 'trigger_system_reminders':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            
            $missingDataStmt = $pdo->query("
                SELECT id, first_name, picture_path, dob 
                FROM users 
                WHERE spiritual_status IN ('Member', 'Worker', 'Pastor') 
                AND (picture_path IS NULL OR picture_path = '' OR dob IS NULL)
            ");
            $usersNeedingUpdates = $missingDataStmt->fetchAll(PDO::FETCH_ASSOC);

            $notifStmt = $pdo->prepare("
                INSERT INTO system_notifications (user_id, title, message, link_url) 
                VALUES (?, ?, ?, '/profile/edit.php')
            ");

            $alertsSent = 0;
            foreach ($usersNeedingUpdates as $u) {
                $messages = [];
                if (empty($u['picture_path'])) $messages[] = "profile picture";
                if (empty($u['dob'])) $messages[] = "date of birth";
                
                $missingStr = implode(" and ", $messages);
                $title = "Update Your Profile";
                $body = "Hello {$u['first_name']}, to help us celebrate you properly, please update your $missingStr on your profile.";
                
                // Prevent spamming
                $checkSpam = $pdo->prepare("SELECT id FROM system_notifications WHERE user_id = ? AND title = ? AND DATE(created_at) = CURDATE() AND is_read = 0");
                $checkSpam->execute([$u['id'], $title]);
                
                if (!$checkSpam->fetch()) {
                    $notifStmt->execute([$u['id'], $title, $body]);
                    $alertsSent++;
                }
            }
            echo json_encode(['status' => 'success', 'message' => "$alertsSent profile reminders queued successfully."]);
            break;

        // ==========================================
        // ACTION: FETCH CHARIS DASHBOARD DATA
        // ==========================================
        case 'fetch_dashboard':
            // A. Fetch Upcoming Birthdays
            $bdayStmt = $pdo->query("
                SELECT * FROM (
                    SELECT u.id as target_user_id, u.first_name, u.last_name, u.phone, u.dob as event_date, 
                           u.picture_path, DATE_FORMAT(u.dob, '%M %D') as formatted_date,
                           ca.id as assignment_id, ca.status as assignment_status, 
                           w.first_name as worker_fname, w.last_name as worker_lname
                    FROM users u
                    LEFT JOIN charis_assignments ca ON u.id = ca.target_user_id AND ca.event_type = 'Birthday' AND ca.assignment_year = YEAR(CURDATE())
                    LEFT JOIN users w ON ca.assigned_worker_id = w.id
                    WHERE u.dob IS NOT NULL
                    AND ((MONTH(u.dob) = MONTH(CURDATE()) AND DAY(u.dob) >= DAY(CURDATE())) OR (MONTH(u.dob) = MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH))))

                    UNION ALL

                    SELECT CONCAT('jc_', jc.id) as target_user_id, jc.child_first_name as first_name, CONCAT(jc.child_last_name, ' (JC)') as last_name, 
                           p.phone, jc.dob as event_date, 
                           jc.picture_path, DATE_FORMAT(jc.dob, '%M %D') as formatted_date,
                           ca.id as assignment_id, ca.status as assignment_status, 
                           w.first_name as worker_fname, w.last_name as worker_lname
                    FROM junior_church_roster jc
                    LEFT JOIN users p ON jc.parent_id = p.id
                    LEFT JOIN charis_assignments ca ON jc.id = ca.target_user_id AND ca.event_type = 'JC_Birthday' AND ca.assignment_year = YEAR(CURDATE())
                    LEFT JOIN users w ON ca.assigned_worker_id = w.id
                    WHERE jc.dob IS NOT NULL
                    AND ((MONTH(jc.dob) = MONTH(CURDATE()) AND DAY(jc.dob) >= DAY(CURDATE())) OR (MONTH(jc.dob) = MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH))))
                ) as combined_bdays
                ORDER BY MONTH(event_date) ASC, DAY(event_date) ASC LIMIT 20
            ");
            $birthdays = $bdayStmt->fetchAll(PDO::FETCH_ASSOC);

            // B. Fetch Upcoming Anniversaries
            $annivStmt = $pdo->query("
                SELECT u.id as target_user_id, 'Wedding_Anniversary' as event_type, u.wedding_anniversary as event_date, 
                       DATE_FORMAT(u.wedding_anniversary, '%M %D') as formatted_date,
                       u.first_name, u.last_name, u.phone, u.picture_path, u.wedding_picture_path,
                       ca.id as assignment_id, ca.status as assignment_status, w.first_name as worker_fname, w.last_name as worker_lname
                FROM users u
                LEFT JOIN charis_assignments ca ON u.id = ca.target_user_id AND ca.event_type = 'Wedding_Anniversary' AND ca.assignment_year = YEAR(CURDATE())
                LEFT JOIN users w ON ca.assigned_worker_id = w.id
                WHERE u.wedding_anniversary IS NOT NULL
                AND ((MONTH(u.wedding_anniversary) = MONTH(CURDATE()) AND DAY(u.wedding_anniversary) >= DAY(CURDATE())) OR (MONTH(u.wedding_anniversary) = MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH))))
                
                UNION ALL
                
                SELECT le.user_id as target_user_id, le.event_type, le.event_date, 
                       DATE_FORMAT(le.event_date, '%M %D') as formatted_date,
                       u.first_name, u.last_name, u.phone, u.picture_path, u.wedding_picture_path,
                       ca.id as assignment_id, ca.status as assignment_status, w.first_name as worker_fname, w.last_name as worker_lname
                FROM life_events le
                JOIN users u ON le.user_id = u.id
                LEFT JOIN charis_assignments ca ON le.user_id = ca.target_user_id AND ca.event_type = le.event_type AND ca.assignment_year = YEAR(CURDATE())
                LEFT JOIN users w ON ca.assigned_worker_id = w.id
                WHERE ((MONTH(le.event_date) = MONTH(CURDATE()) AND DAY(le.event_date) >= DAY(CURDATE())) OR (MONTH(le.event_date) = MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH))))
                ORDER BY MONTH(event_date) ASC, DAY(event_date) ASC LIMIT 20
            ");
            $anniversaries = $annivStmt->fetchAll(PDO::FETCH_ASSOC);

            // C. Fetch all Charis Workers
            $workersStmt = $pdo->query("
                SELECT DISTINCT u.id, u.first_name, u.last_name 
                FROM users u
                JOIN user_departments ud ON u.id = ud.user_id
                JOIN departments d ON ud.department_id = d.id
                WHERE (d.name LIKE '%Charis%' OR d.name LIKE '%Welfare%') AND ud.is_active = 1
                ORDER BY u.first_name ASC
            ");
            $charis_workers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);

            // D. Fetch Upcoming AND Past Church Events
            $eventsStmt = $pdo->query("
                SELECT e.id, e.title, e.event_category, e.event_date,
                       DATE_FORMAT(e.event_date, '%W, %b %D, %Y') as nice_date,
                       IFNULL(cl.status, 'No_Plan') as logistics_status, cl.assigned_worker_id
                FROM events e
                LEFT JOIN charis_event_logistics cl ON e.id = cl.event_id
                WHERE e.event_date >= CURDATE()
                ORDER BY e.event_date ASC LIMIT 15
            ");
            $upcoming_events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

            $pastEventsStmt = $pdo->query("
                SELECT e.id, e.title, e.event_category, e.event_date,
                       DATE_FORMAT(e.event_date, '%W, %b %D, %Y') as nice_date,
                       IFNULL(cl.status, 'No_Plan') as logistics_status, cl.assigned_worker_id
                FROM events e
                LEFT JOIN charis_event_logistics cl ON e.id = cl.event_id
                WHERE e.event_date < CURDATE()
                ORDER BY e.event_date DESC LIMIT 15
            ");
            $past_events = $pastEventsStmt->fetchAll(PDO::FETCH_ASSOC);

            // E. Fetch all Members
            $membersStmt = $pdo->query("SELECT id, first_name, last_name FROM users ORDER BY first_name ASC");
            $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

            // F. Fetch Urgent Welfare Checks (Manual pushes)
            $manualStmt = $pdo->query("
                SELECT ef.id as followup_id, 'Manual' as alert_type, ef.followup_date, u.id as target_user_id, u.first_name, u.last_name, u.phone, u.physical_address,
                       cwa.id as assignment_id, cwa.worker_id, cwa.status as assignment_status, w.first_name as worker_fname, w.last_name as worker_lname
                FROM embrace_followups ef
                JOIN users u ON ef.visitor_id = u.id
                LEFT JOIN charis_welfare_assignments cwa ON cwa.followup_id = ef.id AND cwa.status != 'Resolved'
                LEFT JOIN users w ON cwa.worker_id = w.id
                WHERE ef.status = 'Pending' AND ef.followup_notes LIKE '%SYSTEM FLAG%'
                ORDER BY ef.followup_date DESC
            ");
            $manual_checks = $manualStmt->fetchAll(PDO::FETCH_ASSOC);

            // G. Dynamic 3-week absences (Explicitly exempting 1st/2nd/3rd timers)
            $awol_checks = [];
            $last3EventsStmt = $pdo->query("SELECT id FROM events WHERE event_category = 'Sunday_Service' AND event_date <= CURDATE() ORDER BY event_date DESC LIMIT 3");
            $last3EventIds = $last3EventsStmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($last3EventIds) == 3) {
                $placeholders = implode(',', array_fill(0, count($last3EventIds), '?'));
                $awolStmt = $pdo->prepare("
                    SELECT 0 as followup_id, 'AWOL' as alert_type, CURDATE() as followup_date, u.id as target_user_id, u.first_name, u.last_name, u.phone, u.physical_address,
                           cwa.id as assignment_id, cwa.worker_id, cwa.status as assignment_status, w.first_name as worker_fname, w.last_name as worker_lname
                    FROM users u
                    LEFT JOIN charis_welfare_assignments cwa ON cwa.target_user_id = u.id AND cwa.followup_id = 0 AND cwa.status != 'Resolved'
                    LEFT JOIN users w ON cwa.worker_id = w.id
                    WHERE u.spiritual_status IN ('Member', 'Worker', 'Pastor')
                    AND u.spiritual_status NOT IN ('Visitor', '1st_Timer', '2nd_Timer', '3rd_Timer')
                    AND u.attendance_status NOT IN ('Relocated', 'Attends_Another_Church', 'Unknown')
                    AND u.id NOT IN (
                        SELECT user_id FROM attendance WHERE event_id IN ($placeholders) AND status = 'Present'
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM charis_welfare_assignments cwa2
                        WHERE cwa2.target_user_id = u.id AND cwa2.followup_id = 0 AND cwa2.status = 'Resolved'
                        AND cwa2.created_at >= (SELECT MIN(event_date) FROM events WHERE id IN ($placeholders))
                    )
                ");
                $awolStmt->execute(array_merge($last3EventIds, $last3EventIds));
                $awol_checks = $awolStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $welfare_checks = [];
            $seen_users = [];
            foreach (array_merge($manual_checks, $awol_checks) as $check) {
                if (!isset($seen_users[$check['target_user_id']])) {
                    $welfare_checks[] = $check;
                    $seen_users[$check['target_user_id']] = true;
                }
            }

            echo json_encode([
                'status' => 'success',
                'is_charis_admin' => $is_charis_admin,
                'current_user_id' => $user_id,
                'birthdays' => $birthdays,
                'anniversaries' => $anniversaries,
                'charis_workers' => $charis_workers,
                'upcoming_events' => $upcoming_events,
                'past_events' => $past_events,
                'members' => $members,
                'welfare_checks' => $welfare_checks
            ]);
            break;

        // ==========================================
        // ACTION: GET ENVISION RECAP (CELEBRATIONS)
        // ==========================================
        case 'get_envision_recap':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $month = filter_var($_POST['month'] ?? date('m'), FILTER_VALIDATE_INT);
            
            // Fallback Logic: Celebration Pic -> Wedding Pic (if anniversary) -> Profile Pic
            $stmt = $pdo->prepare("
                SELECT u.id, u.first_name, u.last_name, 
                       COALESCE(NULLIF(u.celebration_picture, ''), NULLIF(u.picture_path, '')) as display_picture,
                       'Birthday' as event_type, DATE_FORMAT(u.dob, '%D %b') as event_date,
                       ca.celebratory_message
                FROM users u
                LEFT JOIN charis_assignments ca ON u.id = ca.target_user_id AND ca.event_type = 'Birthday'
                WHERE MONTH(u.dob) = ? AND u.spiritual_status IN ('Member', 'Worker', 'Pastor')
                
                UNION ALL
                
                SELECT u.id, u.first_name, u.last_name, 
                       COALESCE(NULLIF(u.celebration_picture, ''), NULLIF(u.wedding_picture_path, ''), NULLIF(u.picture_path, '')) as display_picture,
                       'Anniversary' as event_type, DATE_FORMAT(u.wedding_anniversary, '%D %b') as event_date,
                       ca.celebratory_message
                FROM users u
                LEFT JOIN charis_assignments ca ON u.id = ca.target_user_id AND ca.event_type = 'Wedding_Anniversary'
                WHERE MONTH(u.wedding_anniversary) = ? AND u.spiritual_status IN ('Member', 'Worker', 'Pastor')
            ");
            $stmt->execute([$month, $month]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION: UPDATE CELEBRATION PICTURE (BASE64)
        // ==========================================
        case 'update_celebration_picture':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            
            $target_user_id = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
            $image_base64 = $_POST['image_base64'] ?? '';
            
            if (!$target_user_id || empty($image_base64)) {
                exit(json_encode(['status' => 'error', 'message' => 'Invalid data provided.']));
            }

            // Extract the base64 data
            $image_parts = explode(";base64,", $image_base64);
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1] ?? 'jpeg';
            $image_base64_decoded = base64_decode($image_parts[1]);
            
            $filename = uniqid('celeb_', true) . '.' . $image_type;
            $upload_dir = '../uploads/celebrations/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $file_path = $upload_dir . $filename;
            
            if (file_put_contents($file_path, $image_base64_decoded)) {
                $db_path = '/uploads/celebrations/' . $filename;
                $stmt = $pdo->prepare("UPDATE users SET celebration_picture = ? WHERE id = ?");
                $stmt->execute([$db_path, $target_user_id]);
                
                echo json_encode(['status' => 'success', 'message' => 'Celebration picture perfectly cropped and applied!', 'new_path' => $db_path]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to save the processed image to the server.']);
            }
            break;

        // ==========================================
        // ACTION: LOG FINANCIAL EXPENSE (2-STEP)
        // ==========================================
        case 'log_expense':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            
            $event_id = !empty($_POST['event_id']) ? filter_var($_POST['event_id'], FILTER_VALIDATE_INT) : null;
            $item_name = trim($_POST['item_name'] ?? '');
            $category = $_POST['category'] ?? 'Other';
            $amount = filter_var($_POST['amount_requested'], FILTER_VALIDATE_FLOAT);
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($item_name) || !$amount) {
                exit(json_encode(['status' => 'error', 'message' => 'Item name and valid amount are required.']));
            }

            $stmt = $pdo->prepare("INSERT INTO charis_expenses (event_id, requested_by_id, item_name, category, amount_requested, status, notes) VALUES (?, ?, ?, ?, ?, 'Pending_Director', ?)");
            $stmt->execute([$event_id, $user_id, $item_name, $category, $amount, $notes]);
            
            // Grab the newly created ID
            $new_expense_id = $pdo->lastInsertId();

            // NOTIFICATION TRIGGER: Alert the Charis Director/HOD
            $dirStmt = $pdo->query("
                SELECT ud.user_id 
                FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE (d.name LIKE '%Charis%' OR d.name LIKE '%Welfare%') 
                AND ud.role_in_dept IN ('Director', 'HOD') AND ud.is_active = 1
            ");
            $directors = $dirStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($directors)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Expense Request', ?, '/modules/finance/index.php')");
                $formatted_amount = number_format($amount, 2);
                foreach($directors as $dir_id) {
                    $notifStmt->execute([$dir_id, "A new expense request of ₦{$formatted_amount} for '{$item_name}' requires your approval."]);
                }
            }

            // Return the full data object so your frontend can instantly draw the new row
            echo json_encode([
                'status' => 'success', 
                'message' => 'Expense logged and routed to the Director-in-Charge.',
                'data' => [
                    'id' => $new_expense_id,
                    'item_name' => $item_name,
                    'category' => $category,
                    'amount_requested' => $amount,
                    'status' => 'Pending_Director'
                ]
            ]);
            break;

        // ==========================================
        // ACTION: FINANCIAL ANALYTICS & FORECASTING
        // ==========================================
        case 'get_financial_analytics':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            
            $catStmt = $pdo->query("SELECT category, SUM(amount_spent) as total FROM charis_expenses WHERE MONTH(created_at) = MONTH(CURDATE()) AND status = 'Completed' GROUP BY category");
            $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $forecastStmt = $pdo->query("SELECT category, (SUM(amount_spent) / 3) as projected_budget FROM charis_expenses WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND status = 'Completed' GROUP BY category");
            $forecast = $forecastStmt->fetchAll(PDO::FETCH_ASSOC);

            $ledgerStmt = $pdo->query("SELECT id, item_name, category, amount_requested, status FROM charis_expenses ORDER BY created_at DESC LIMIT 10");
            $recent_expenses = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => ['breakdown' => $categories, 'forecast' => $forecast, 'recent_expenses' => $recent_expenses]]);
            break;

        // ==========================================
        // ACTION: BULK CREATE SUNDAY SERVICES
        // ==========================================
        case 'bulk_create_services':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $year = filter_var($_POST['year'] ?? date('Y'), FILTER_VALIDATE_INT);
            $month = filter_var($_POST['month'] ?? date('m'), FILTER_VALIDATE_INT);
            
            $start_date = new DateTime("$year-$month-01");
            $end_date = clone $start_date;
            $end_date->modify('last day of this month');
            
            $created_count = 0;
            $pdo->beginTransaction();
            try {
                $insertStmt = $pdo->prepare("INSERT INTO events (title, event_category, event_date, created_by) VALUES (?, 'Sunday_Service', ?, ?)");
                while ($start_date <= $end_date) {
                    if ($start_date->format('w') == 0) { 
                        $title = "Sunday Service - " . $start_date->format('M jS');
                        $date_str = $start_date->format('Y-m-d 09:00:00'); 
                        $insertStmt->execute([$title, $date_str, $user_id]);
                        $created_count++;
                    }
                    $start_date->modify('+1 day');
                }
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => "$created_count Sunday Services successfully generated."]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Failed to generate services.']);
            }
            break;

                // ==========================================
        // ACTION: AI METADATA EXTRACTION (GEMINI)
        // ==========================================
        case 'extract_book_metadata':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            if (!isset($_FILES['front_cover']) || $_FILES['front_cover']['error'] !== UPLOAD_ERR_OK) {
                exit(json_encode(['status' => 'error', 'message' => 'Front cover is required for extraction.']));
            }

            // 1. Native .env loader helper (Bypasses Composer dependency completely)
            if (!function_exists('loadEnvHelper')) {
                function loadEnvHelper($path) {
                    if (!file_exists($path)) return;
                    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $line) {
                        if (strpos(trim($line), '#') === 0) continue;
                        list($name, $value) = explode('=', $line, 2);
                        $name = trim($name);
                        $value = trim($value, " \t\n\r\0\x0B\"'");
                        $_ENV[$name] = $value;
                    }
                }
            }

            // 2. Safely read environmental variables from server root
            loadEnvHelper($_SERVER['DOCUMENT_ROOT'] . '/.env');

            // 3. Fetch the Gemini key securely from your .env array configuration
            $gemini_api_key = $_ENV['GEMINI_API_KEY'] ?? ''; 
            $url = 'https://googleapis.com' . $gemini_api_key;



            function encodeImageForGemini($fileInfo) {
                $mime = mime_content_type($fileInfo['tmp_name']);
                $data = file_get_contents($fileInfo['tmp_name']);
                return ['mimeType' => $mime, 'data' => base64_encode($data)];
            }

            $inlineDataList = [];
            $inlineDataList[] = ['inlineData' => encodeImageForGemini($_FILES['front_cover'])];
            if (isset($_FILES['back_cover']) && $_FILES['back_cover']['error'] === UPLOAD_ERR_OK) {
                $inlineDataList[] = ['inlineData' => encodeImageForGemini($_FILES['back_cover'])];
            }

            $payload = [
                'contents' => [
                    [
                        'parts' => array_merge([
                            ['text' => 'Analyze these book covers (front and optionally back). Extract the exact Title, Author, and write a 2-3 sentence engaging synopsis based on the text found. Return ONLY a pure JSON object in this format: {"title": "...", "author": "...", "description": "..."}']
                        ], $inlineDataList)
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'responseMimeType' => 'application/json'
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) exit(json_encode(['status' => 'error', 'message' => 'AI extraction failed.']));
            $result = json_decode($response, true);
            
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $bookData = json_decode(trim($result['candidates'][0]['content']['parts'][0]['text']), true);
                echo json_encode(['status' => 'success', 'data' => $bookData]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Could not parse AI response.']);
            }
            break;

        // ==========================================
        // ACTION: FETCH LIBRARY ADMIN DASHBOARD
        // ==========================================
        case 'fetch_library_admin':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));

            // PATCHED: Now fetches ALL data, including cover and ebook paths
            $catalogStmt = $pdo->query("SELECT id, title, author, category, description, book_type, total_copies, available_copies, audiobook_link, estimated_read_time, cover_image_path, ebook_file_path FROM charis_books ORDER BY title ASC");
            $catalog = $catalogStmt->fetchAll(PDO::FETCH_ASSOC);

            $borrowsStmt = $pdo->query("
                SELECT b.id as borrow_id, b.status, b.reserved_date, b.due_date, b.extension_status, 
                       cb.title, cb.book_type, u.first_name, u.last_name, u.phone 
                FROM charis_book_borrowing b
                JOIN charis_books cb ON b.book_id = cb.id
                JOIN users u ON b.user_id = u.id
                WHERE b.status IN ('Reserved', 'Picked_Up', 'Overdue')
                ORDER BY b.due_date ASC
            ");
            $active_borrows = $borrowsStmt->fetchAll(PDO::FETCH_ASSOC);

            $waitlistStmt = $pdo->query("
                SELECT w.id, w.request_date, cb.title, u.first_name, u.last_name, u.phone
                FROM charis_book_waitlist w
                JOIN charis_books cb ON w.book_id = cb.id
                JOIN users u ON w.user_id = u.id
                WHERE w.status = 'Waiting'
                ORDER BY w.request_date ASC
            ");
            $waitlist = $waitlistStmt->fetchAll(PDO::FETCH_ASSOC);

            $catStmt = $pdo->query("SELECT DISTINCT category FROM charis_books WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
            $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['status' => 'success', 'catalog' => $catalog, 'active_borrows' => $active_borrows, 'waitlist' => $waitlist, 'categories' => $categories]);
            break;

        // ==========================================
        // ACTION: ADD NEW BOOK
        // ==========================================
        case 'add_new_book':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));

            $title = trim($_POST['title'] ?? '');
            $author = trim($_POST['author'] ?? '');
            $category = trim($_POST['category'] ?? 'General'); 
            $book_type = $_POST['book_type'] ?? 'Physical';
            $total_copies = ($book_type === 'E-Book') ? 0 : filter_var($_POST['total_copies'] ?? 1, FILTER_VALIDATE_INT);
            $desc = trim($_POST['description'] ?? '');
            $read_time = trim($_POST['estimated_read_time'] ?? '');
            $audiobook_link = trim($_POST['audiobook_link'] ?? ''); 

            if (empty($title) || empty($author) || empty($category)) exit(json_encode(['status' => 'error', 'message' => 'Title, Author, and Category are required.']));

            $cover_path = null; 
            $ebook_path = null; 
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/library/';
            if (!is_dir($uploadDir . 'covers')) mkdir($uploadDir . 'covers', 0777, true);
            if (!is_dir($uploadDir . 'ebooks')) mkdir($uploadDir . 'ebooks', 0777, true);

            // FIX: Match HTML input name `front_cover` from the Add Book modal
            if (isset($_FILES['front_cover']) && $_FILES['front_cover']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['front_cover']['name'], PATHINFO_EXTENSION);
                $coverName = uniqid('cover_') . '.' . $ext;
                $targetCover = $uploadDir . 'covers/' . $coverName;
                if (move_uploaded_file($_FILES['front_cover']['tmp_name'], $targetCover)) {
                    $cover_path = '/uploads/library/covers/' . $coverName;
                }
            }

            if (($book_type === 'E-Book' || $book_type === 'Both')) {
                if (!isset($_FILES['ebook_file']) || $_FILES['ebook_file']['error'] !== UPLOAD_ERR_OK) exit(json_encode(['status' => 'error', 'message' => 'E-Book PDF file is required.']));
                $ext = pathinfo($_FILES['ebook_file']['name'], PATHINFO_EXTENSION);
                if (strtolower($ext) !== 'pdf') exit(json_encode(['status' => 'error', 'message' => 'Only PDF files are allowed for E-Books.']));
                
                $ebookName = uniqid('book_') . '.pdf';
                if (move_uploaded_file($_FILES['ebook_file']['tmp_name'], $uploadDir . 'ebooks/' . $ebookName)) {
                    $ebook_path = '/uploads/library/ebooks/' . $ebookName;
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO charis_books (title, author, category, description, cover_image_path, book_type, total_copies, available_copies, ebook_file_path, audiobook_link, estimated_read_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $author, $category, $desc, $cover_path, $book_type, $total_copies, $total_copies, $ebook_path, $audiobook_link, $read_time]);
            echo json_encode(['status' => 'success', 'message' => 'New book successfully added!']);
            break;

        // ==========================================
        // ACTION: BORROW BOOK (SMART DATE SCHEDULING)
        // ==========================================
        case 'borrow_book':
            // require_login($user_id); // Handled globally by the top-level session check
            $book_id = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$book_id) exit(json_encode(['status' => 'error', 'message' => 'Invalid book ID.']));

            $pdo->beginTransaction();
            try {
                // Check limit (Max 3 active books)
                $limitStmt = $pdo->prepare("SELECT COUNT(*) FROM charis_book_borrowing WHERE user_id = ? AND status IN ('Reserved', 'Picked_Up', 'Overdue')");
                $limitStmt->execute([$user_id]);
                if ($limitStmt->fetchColumn() >= 3) throw new Exception('Maximum limit of 3 active books reached.');

                // Check availability
                $bookStmt = $pdo->prepare("SELECT available_copies, title FROM charis_books WHERE id = ? FOR UPDATE");
                $bookStmt->execute([$book_id]);
                $book = $bookStmt->fetch(PDO::FETCH_ASSOC);
                if (!$book || $book['available_copies'] <= 0) throw new Exception('Book is out of stock. Please join the waitlist.');

                // SMART SCHEDULING: 1 Month out, snapped to the nearest Thursday (4) or Sunday (0)
                $due_date = new DateTime('+1 month');
                while (!in_array($due_date->format('w'), [0, 4])) {
                    $due_date->modify('+1 day');
                }
                $formatted_due_date = $due_date->format('Y-m-d');

                $insertStmt = $pdo->prepare("INSERT INTO charis_book_borrowing (book_id, user_id, due_date, status) VALUES (?, ?, ?, 'Reserved')");
                $insertStmt->execute([$book_id, $user_id, $formatted_due_date]);

                $updateStmt = $pdo->prepare("UPDATE charis_books SET available_copies = available_copies - 1 WHERE id = ?");
                $updateStmt->execute([$book_id]);

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Book reserved! Pickup required within 7 days.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        // ==========================================
        // ACTION: REQUEST RETURN EXTENSION
        // ==========================================
        case 'request_extension':
            $borrow_id = filter_var($_POST['borrow_id'] ?? '', FILTER_VALIDATE_INT);
            
            $stmt = $pdo->prepare("UPDATE charis_book_borrowing SET extension_requested = 1, extension_status = 'Pending' WHERE id = ? AND user_id = ? AND status = 'Picked_Up'");
            $stmt->execute([$borrow_id, $user_id]);
            
            if ($stmt->rowCount() > 0) {
                // NOTIFICATION TRIGGER: Alert the Charis Admins
                $adminStmt = $pdo->query("
                    SELECT ud.user_id 
                    FROM user_departments ud 
                    JOIN departments d ON ud.department_id = d.id 
                    WHERE d.name LIKE '%Charis%' AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
                ");
                $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($admins)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Library Extension Request', 'A member has requested a due date extension for a library book.', '/modules/charis/index.php')");
                    foreach($admins as $admin_id) {
                        $notifStmt->execute([$admin_id]);
                    }
                }

                echo json_encode(['status' => 'success', 'message' => 'Extension requested successfully. Awaiting Charis approval.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Unable to request extension.']);
            }
            break;
        
        // ==========================================
        // ACTION: APPROVE EXTENSION
        // ==========================================
        case 'approve_extension':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $borrow_id = filter_var($_POST['borrow_id'] ?? '', FILTER_VALIDATE_INT);

            $bStmt = $pdo->prepare("SELECT user_id, due_date FROM charis_book_borrowing WHERE id = ?");
            $bStmt->execute([$borrow_id]);
            $borrowData = $bStmt->fetch(PDO::FETCH_ASSOC);
            
            $current_due = new DateTime($borrowData['due_date']);
            $current_due->modify('+14 days');
            
            while (!in_array($current_due->format('w'), [0, 4])) { $current_due->modify('+1 day'); }
            $new_due_date = $current_due->format('Y-m-d');
            $nice_date = $current_due->format('M jS, Y');

            $stmt = $pdo->prepare("UPDATE charis_book_borrowing SET due_date = ?, extension_status = 'Approved' WHERE id = ?");
            $stmt->execute([$new_due_date, $borrow_id]);

            // NOTIFICATION TRIGGER: Alert the member who requested it
            $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Book Extension Approved', ?, '/modules/member_portal/index.php')")
                ->execute([$borrowData['user_id'], "Your library book extension has been approved. Your new due date is {$nice_date}."]);

            echo json_encode(['status' => 'success', 'message' => 'Extension approved to ' . $new_due_date]);
            break;

        // ==========================================
        // ACTION: CONFIRM PICKUP & PROCESS RETURN
        // ==========================================
        case 'confirm_book_pickup':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $borrow_id = filter_var($_POST['borrow_id'] ?? '', FILTER_VALIDATE_INT);
            
            $stmt = $pdo->prepare("UPDATE charis_book_borrowing SET status = 'Picked_Up', borrow_date = CURDATE() WHERE id = ? AND status = 'Reserved'");
            $stmt->execute([$borrow_id]);
            if ($stmt->rowCount() > 0) echo json_encode(['status' => 'success', 'message' => 'Pickup Confirmed.']);
            else echo json_encode(['status' => 'error', 'message' => 'Could not update status.']);
            break;

        // ==========================================
        // ACTION: PROCESS RETURN & AUTO-RESERVE WAITLIST
        // ==========================================
        case 'process_book_return':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $borrow_id = filter_var($_POST['borrow_id'] ?? '', FILTER_VALIDATE_INT);
            $condition = $_POST['condition'] ?? 'Good';
            
            $pdo->beginTransaction();
            try {
                // 1. Strict State Check (Prevent Ghost Inventory)
                $bStmt = $pdo->prepare("SELECT book_id, status FROM charis_book_borrowing WHERE id = ? FOR UPDATE");
                $bStmt->execute([$borrow_id]);
                $borrowData = $bStmt->fetch(PDO::FETCH_ASSOC);

                if (!$borrowData || !in_array($borrowData['status'], ['Picked_Up', 'Overdue'])) {
                    throw new Exception('Only checked-out books can be returned. Use Cancel Reservation instead.');
                }
                $book_id = $borrowData['book_id'];

                // 2. Mark as Returned
                $updateBorrow = $pdo->prepare("UPDATE charis_book_borrowing SET status = 'Returned', return_date = CURDATE(), return_condition = ? WHERE id = ?");
                $updateBorrow->execute([$condition, $borrow_id]);

                // 3. Handle Inventory & Waitlist Sniping Trap
                if ($condition === 'Lost' || $condition === 'Damaged') {
                    $pdo->prepare("UPDATE charis_books SET total_copies = GREATEST(0, total_copies - 1) WHERE id = ?")->execute([$book_id]);
                } else {
                    // Check Waitlist FIRST before returning to general shelf
                    $waitStmt = $pdo->prepare("SELECT id, user_id FROM charis_book_waitlist WHERE book_id = ? AND status = 'Waiting' ORDER BY request_date ASC LIMIT 1");
                    $waitStmt->execute([$book_id]);
                    $nextInLine = $waitStmt->fetch(PDO::FETCH_ASSOC);

                    if ($nextInLine) {
                        // Mark waitlist fulfilled
                        $pdo->prepare("UPDATE charis_book_waitlist SET status = 'Notified' WHERE id = ?")->execute([$nextInLine['id']]);
                        
                        // AUTO-RESERVE for 48 hours so it cannot be sniped!
                        $due_date = date('Y-m-d', strtotime('+2 days'));
                        $pdo->prepare("INSERT INTO charis_book_borrowing (book_id, user_id, due_date, status) VALUES (?, ?, ?, 'Reserved')")->execute([$book_id, $nextInLine['user_id'], $due_date]);
                        
                        // Notify
                        $pdo->prepare("INSERT INTO system_notifications (user_id, title, message) VALUES (?, 'Waitlist Alert', 'A book you requested is available and has been automatically reserved for you for 48 hours!')")->execute([$nextInLine['user_id']]);
                        // Note: We DO NOT increment available_copies because it is instantly re-reserved.
                    } else {
                        // No waitlist, return to general shelf
                        $pdo->prepare("UPDATE charis_books SET available_copies = available_copies + 1 WHERE id = ?")->execute([$book_id]);
                    }
                }

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Return processed securely.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        // ==========================================
        // ACTION: ASSIGN A CELEBRATION
        // ==========================================
        case 'assign_celebration':
            if (!$is_charis_admin) {
                exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            }

            $target = strip_tags($_POST['target_user_id'] ?? '');
$type = strip_tags($_POST['event_type'] ?? '');
            $worker = filter_var($_POST['worker_id'] ?? '', FILTER_VALIDATE_INT);
            
            if (empty($target) || empty($type) || !$worker) {
                exit(json_encode(['status' => 'error', 'message' => 'Missing required assignment data.']));
            }

            if (strpos($target, 'jc_') === 0) { 
                $target = str_replace('jc_', '', $target); 
                $type = 'JC_Birthday'; 
            }

            $stmt = $pdo->prepare("
                INSERT INTO charis_assignments (target_user_id, event_type, assignment_year, assigned_worker_id, status) 
                VALUES (?, ?, YEAR(CURDATE()), ?, 'Assigned') 
                ON DUPLICATE KEY UPDATE assigned_worker_id = VALUES(assigned_worker_id), status = 'Assigned'
            ");
            $stmt->execute([$target, $type, $worker]);

            // NOTIFICATION TRIGGER: Alert the assigned worker
            $cleanType = str_replace('_', ' ', $type);
            $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Celebration Task', ?, '/modules/charis/index.php')")
                ->execute([$worker, "You have been assigned to coordinate a {$cleanType} celebration. Please check your Charis dashboard."]);

            echo json_encode(['status' => 'success', 'message' => 'Celebration assigned successfully!']);
            break;

        // ==========================================
        // ACTION: ASSIGN WELFARE CASE (DELEGATION)
        // ==========================================
        case 'assign_welfare_case':
            if (!$is_charis_admin) {
                exit(json_encode(['status' => 'error', 'message' => 'Unauthorized mapping.']));
            }
            
            $target = filter_var($_POST['target_user_id'] ?? '', FILTER_VALIDATE_INT);
            $followup = empty($_POST['followup_id']) ? 0 : filter_var($_POST['followup_id'], FILTER_VALIDATE_INT);
            $worker = filter_var($_POST['worker_id'] ?? '', FILTER_VALIDATE_INT);
            
            if (!$target || !$worker) {
                exit(json_encode(['status' => 'error', 'message' => 'Missing worker or target ID.']));
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO charis_welfare_assignments (followup_id, target_user_id, worker_id, status) 
                VALUES (?, ?, ?, 'Assigned') 
                ON DUPLICATE KEY UPDATE worker_id = VALUES(worker_id), status = 'Assigned'
            ");
            $stmt->execute([$followup, $target, $worker]);

            // NOTIFICATION TRIGGER: Alert the assigned worker
            $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Welfare Assignment', 'You have been assigned a new welfare/follow-up case. Please review the details on your dashboard.', '/modules/charis/index.php')")
                ->execute([$worker]);

            echo json_encode(['status' => 'success', 'message' => 'Welfare case successfully delegated.']);
            break;

        // ==========================================
        // ACTION: UPDATE ASSIGNMENT STATUS
        // ==========================================
        case 'update_assignment_status':
            $assignment_id = filter_var($_POST['assignment_id'] ?? '', FILTER_VALIDATE_INT);
            $new_status = strip_tags($_POST['status'] ?? '');

            if (!$assignment_id || empty($new_status)) {
                exit(json_encode(['status' => 'error', 'message' => 'Invalid status update.']));
            }

            $stmt = $pdo->prepare("UPDATE charis_assignments SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $assignment_id]);

            $msg = $new_status === 'Flyer_Posted' ? 'Flyer marked as posted!' : 'Celebration marked as fully completed!';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION: ADD MANUAL LIFE EVENT
        // ==========================================
        case 'add_life_event':
            $target_id = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
            $event_date = strip_tags($_POST['event_date'] ?? '');
$type = strip_tags($_POST['event_type'] ?? '');
if ($type === 'Other') {
    $type = trim(strip_tags($_POST['custom_event_type'] ?? 'Special Milestone'));
}

            if (!$target_id || empty($type) || empty($event_date)) {
                exit(json_encode(['status' => 'error', 'message' => 'All fields are required.']));
            }

            $stmt = $pdo->prepare("INSERT INTO life_events (user_id, event_type, event_date) VALUES (?, ?, ?)");
            $stmt->execute([$target_id, $type, $event_date]);

            echo json_encode(['status' => 'success', 'message' => 'Life event securely added.']);
            break;

        // ==========================================
        // ACTION: RESOLVE WELFARE
        // ==========================================
        case 'resolve_welfare':
            $followup_id = filter_var($_POST['followup_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$followup_id) {
                exit(json_encode(['status' => 'error', 'message' => 'Missing case identifier.']));
            }

            $stmt = $pdo->prepare("
                UPDATE embrace_followups 
                SET status = 'Completed', 
                    followup_notes = CONCAT(IFNULL(followup_notes,''), '\n\n[RESOLVED BY CHARIS TEAM]') 
                WHERE id = ?
            ");
            $stmt->execute([$followup_id]);

            echo json_encode(['status' => 'success', 'message' => 'Welfare check marked as resolved.']);
            break;

        // ==========================================
        // ACTION: UPDATE AWOL STATUS
        // ==========================================
        case 'update_awol_status':
            $target_id = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
            $followup_id = filter_var($_POST['followup_id'] ?? '', FILTER_VALIDATE_INT);
            $status = strip_tags($_POST['attendance_status'] ?? '');
$comments = trim(strip_tags($_POST['comments'] ?? ''));

            if (!$target_id) {
                exit(json_encode(['status' => 'error', 'message' => 'User ID missing.']));
            }

            if (!empty($status)) {
                $stmt = $pdo->prepare("UPDATE users SET attendance_status = ?, comments = CONCAT(IFNULL(comments,''), '\n\n[Welfare Update]: ', ?) WHERE id = ?");
                $stmt->execute([$status, $comments, $target_id]);
            } else if (!empty($comments)) {
                $stmt = $pdo->prepare("UPDATE users SET comments = CONCAT(IFNULL(comments,''), '\n\n[Welfare Update]: ', ?) WHERE id = ?");
                $stmt->execute([$comments, $target_id]);
            }

            if ($followup_id) {
                $stmt = $pdo->prepare("UPDATE embrace_followups SET status = 'Completed', followup_notes = CONCAT(IFNULL(followup_notes,''), '\n\n[RESOLVED VIA WELFARE MODAL]') WHERE id = ?");
                $stmt->execute([$followup_id]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Welfare status successfully updated!']);
            break;

        // ==========================================
        // ACTION: REQUEST WELFARE CASE (WORKER INITIATED)
        // ==========================================
        case 'request_welfare_case':
            $target = filter_var($_POST['target_user_id'] ?? '', FILTER_VALIDATE_INT);
            $followup = empty($_POST['followup_id']) ? 0 : filter_var($_POST['followup_id'], FILTER_VALIDATE_INT);
            
            if (!$target) exit(json_encode(['status' => 'error', 'message' => 'Missing target ID.']));

            $assignment_status = $is_charis_admin ? 'Assigned' : 'Requested';
            
            $stmt = $pdo->prepare("
                INSERT INTO charis_welfare_assignments (followup_id, target_user_id, worker_id, status) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE worker_id=VALUES(worker_id), status=VALUES(status)
            ");
            $stmt->execute([$followup, $target, $user_id, $assignment_status]);
            
            $msg = $is_charis_admin ? 'Case auto-assigned to you successfully.' : 'Request submitted! Awaiting HOD approval.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION: APPROVE WELFARE CASE (HOD INITIATED)
        // ==========================================
        case 'approve_welfare_case':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            
            $assign_id = filter_var($_POST['assignment_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$assign_id) exit(json_encode(['status' => 'error', 'message' => 'Missing assignment ID.']));

            // Get the worker ID before updating
            $wStmt = $pdo->prepare("SELECT worker_id FROM charis_welfare_assignments WHERE id = ?");
            $wStmt->execute([$assign_id]);
            $assigned_worker = $wStmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE charis_welfare_assignments SET status = 'Assigned' WHERE id = ?");
            $stmt->execute([$assign_id]);

            // NOTIFICATION TRIGGER: Alert the worker who requested the case
            if ($assigned_worker) {
                $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Welfare Request Approved', 'Your request to handle a welfare case has been approved by the HOD. You may now proceed with the follow-up.', '/modules/charis/index.php')")
                    ->execute([$assigned_worker]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Case officially assigned.']);
            break;

        // ==========================================
        // ACTION: GET EVENT LOGISTICS DETAILS
        // ==========================================
        case 'get_event_logistics':
            $event_id = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$event_id) exit(json_encode(['status' => 'error', 'message' => 'Event ID missing.']));
            
            $stmt = $pdo->prepare("SELECT * FROM charis_event_logistics WHERE event_id = ?");
            $stmt->execute([$event_id]);
            
            $logistics = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'event_id' => $event_id, 
                'assigned_worker_id' => '', 
                'supply_plan' => '', 
                'budget_requested' => '0.00', 
                'amount_spent' => '0.00', 
                'status' => 'Planning'
            ];
            
            echo json_encode(['status' => 'success', 'data' => $logistics]);
            break;

        // ==========================================
        // ACTION: SAVE EVENT LOGISTICS
        // ==========================================
        case 'save_event_logistics':
            $event_id = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
            $worker_id = !empty($_POST['assigned_worker_id']) ? filter_var($_POST['assigned_worker_id'], FILTER_VALIDATE_INT) : null;
            $plan = strip_tags($_POST['supply_plan'] ?? '');
            $log_status = strip_tags($_POST['status'] ?? 'Planning');
            $spent = filter_var($_POST['amount_spent'] ?? 0, FILTER_VALIDATE_FLOAT);
            $log_status = filter_var($_POST['status'] ?? 'Planning', FILTER_SANITIZE_STRING);

            if (!$event_id) exit(json_encode(['status' => 'error', 'message' => 'Invalid Event.']));

            $stmt = $pdo->prepare("
                INSERT INTO charis_event_logistics (event_id, assigned_worker_id, supply_plan, budget_requested, amount_spent, status) 
                VALUES (?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    assigned_worker_id=VALUES(assigned_worker_id), 
                    supply_plan=VALUES(supply_plan), 
                    budget_requested=VALUES(budget_requested), 
                    amount_spent=VALUES(amount_spent), 
                    status=VALUES(status)
            ");
            $stmt->execute([$event_id, $worker_id, $plan, $budget, $spent, $log_status]);

            echo json_encode(['status' => 'success', 'message' => 'Logistics & Budget successfully updated!']);
            break;

        // ==========================================
        // ACTION: EDIT EXISTING BOOK (WITH FILE UPLOADS)
        // ==========================================
        case 'edit_book':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $book_id = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$book_id) exit(json_encode(['status' => 'error', 'message' => 'Book ID required.']));

            $title = trim($_POST['title'] ?? '');
            $author = trim($_POST['author'] ?? '');
            $category = trim($_POST['category'] ?? 'General'); 
            $book_type = $_POST['book_type'] ?? 'Physical';
            $new_total_copies = ($book_type === 'E-Book') ? 0 : filter_var($_POST['total_copies'] ?? 0, FILTER_VALIDATE_INT);
            $desc = trim($_POST['description'] ?? '');
            $read_time = trim($_POST['estimated_read_time'] ?? '');
            $audiobook_link = trim($_POST['audiobook_link'] ?? ''); 

            // Handle optional file uploads
            $cover_query_part = "";
            $ebook_query_part = "";
            $params = [$title, $author, $category, $desc, $book_type, $new_total_copies, $audiobook_link, $read_time];

            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/library/';
            if (!is_dir($uploadDir . 'covers')) mkdir($uploadDir . 'covers', 0777, true);
            if (!is_dir($uploadDir . 'ebooks')) mkdir($uploadDir . 'ebooks', 0777, true);

            // 1. Process new Cover Image if provided
            if (isset($_FILES['front_cover']) && $_FILES['front_cover']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['front_cover']['name'], PATHINFO_EXTENSION);
                $coverName = uniqid('cover_') . '.' . $ext;
                if (move_uploaded_file($_FILES['front_cover']['tmp_name'], $uploadDir . 'covers/' . $coverName)) {
                    $cover_query_part = ", cover_image_path = ?";
                    $params[] = '/uploads/library/covers/' . $coverName;
                }
            }

            // 2. Process new E-Book PDF if provided
            if (($book_type === 'E-Book' || $book_type === 'Both')) {
                if (isset($_FILES['ebook_file']) && $_FILES['ebook_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['ebook_file']['name'], PATHINFO_EXTENSION);
                    if (strtolower($ext) !== 'pdf') exit(json_encode(['status' => 'error', 'message' => 'Only PDF files are allowed for E-Books.']));
                    
                    $ebookName = uniqid('book_') . '.pdf';
                    if (move_uploaded_file($_FILES['ebook_file']['tmp_name'], $uploadDir . 'ebooks/' . $ebookName)) {
                        $ebook_query_part = ", ebook_file_path = ?";
                        $params[] = '/uploads/library/ebooks/' . $ebookName;
                    }
                }
            }

            $pdo->beginTransaction();
            try {
                // Safely recalculate active inventory based on existing checkouts
                $currStmt = $pdo->prepare("SELECT total_copies, available_copies FROM charis_books WHERE id = ? FOR UPDATE");
                $currStmt->execute([$book_id]);
                $currBook = $currStmt->fetch(PDO::FETCH_ASSOC);
                
                $active_borrows = $currBook['total_copies'] - $currBook['available_copies'];
                $new_available_copies = max(0, $new_total_copies - $active_borrows);
                
                // Add final parameters for available copies and ID
                $params[] = $new_available_copies;
                $params[] = $book_id; 

                $stmt = $pdo->prepare("
                    UPDATE charis_books 
                    SET title=?, author=?, category=?, description=?, book_type=?, total_copies=?, audiobook_link=?, estimated_read_time=? 
                    $cover_query_part $ebook_query_part, available_copies=? 
                    WHERE id=?
                ");
                $stmt->execute($params);
                
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Book and media updated successfully.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Failed to update book.']);
            }
            break;

        // ==========================================
        // ACTION: CANCEL NO-SHOW RESERVATION
        // ==========================================
        case 'cancel_reservation':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $borrow_id = filter_var($_POST['borrow_id'] ?? '', FILTER_VALIDATE_INT);

            $pdo->beginTransaction();
            try {
                $bStmt = $pdo->prepare("SELECT book_id, status FROM charis_book_borrowing WHERE id = ? FOR UPDATE");
                $bStmt->execute([$borrow_id]);
                $borrow = $bStmt->fetch(PDO::FETCH_ASSOC);

                if (!$borrow || $borrow['status'] !== 'Reserved') throw new Exception('Only pending reservations can be cancelled.');

                // Cancel it and return inventory to shelf
                $pdo->prepare("UPDATE charis_book_borrowing SET status = 'Cancelled' WHERE id = ?")->execute([$borrow_id]);
                $pdo->prepare("UPDATE charis_books SET available_copies = available_copies + 1 WHERE id = ?")->execute([$borrow['book_id']]);
                
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Reservation cancelled. Book returned to active inventory.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        // ==========================================
        // ACTION: DELETE BOOK
        // ==========================================
        case 'delete_book':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $book_id = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);
            
            // Check for active borrows before allowing deletion
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM charis_book_borrowing WHERE book_id = ? AND status IN ('Reserved', 'Picked_Up', 'Overdue')");
            $checkStmt->execute([$book_id]);
            if ($checkStmt->fetchColumn() > 0) {
                exit(json_encode(['status' => 'error', 'message' => 'Cannot delete a book that is currently checked out by members.']));
            }

            $pdo->prepare("DELETE FROM charis_books WHERE id = ?")->execute([$book_id]);
            echo json_encode(['status' => 'success', 'message' => 'Book removed from library.']);
            break;
        
        // =====================================================================================
        // ACTION: RESOLVE AWOL CASE (marks assignment Resolved - removes from AWOL list)
        // =====================================================================================
        case 'resolve_awol_case':
            $target_id  = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
            $att_status = strip_tags($_POST['attendance_status'] ?? '');
            $comments   = trim(strip_tags($_POST['comments'] ?? ''));

            if (!$target_id) exit(json_encode(['status' => 'error', 'message' => 'User ID missing.']));

            $pdo->beginTransaction();
            if (!empty($att_status)) {
                $pdo->prepare("UPDATE users SET attendance_status = ?, comments = CONCAT(IFNULL(comments,''), '\n\n[Welfare Resolved]: ', ?) WHERE id = ?")
                    ->execute([$att_status, $comments, $target_id]);
            } elseif (!empty($comments)) {
                $pdo->prepare("UPDATE users SET comments = CONCAT(IFNULL(comments,''), '\n\n[Welfare Resolved]: ', ?) WHERE id = ?")
                    ->execute([$comments, $target_id]);
            }
            $pdo->prepare("UPDATE charis_welfare_assignments SET status = 'Resolved' WHERE target_user_id = ? AND followup_id = 0")
                ->execute([$target_id]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Case resolved. Member removed from the AWOL list.']);
            break;

        // =====================================================================================
        // ACTION: ADD EVENT TASK
        // =====================================================================================
        case 'add_event_task':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $event_id   = filter_var($_POST['event_id']             ?? '', FILTER_VALIDATE_INT);
            $title      = trim(strip_tags($_POST['task_title']      ?? ''));
            $desc       = trim(strip_tags($_POST['task_description'] ?? ''));
            $worker_id  = filter_var($_POST['assigned_worker_id']   ?? 0,  FILTER_VALIDATE_INT) ?: null;
            $est_budget = filter_var($_POST['estimated_budget']     ?? 0,  FILTER_VALIDATE_FLOAT) ?: 0;
            $category   = in_array($_POST['task_category'] ?? '', ['Logistics','Supplies']) ? $_POST['task_category'] : 'Logistics';

            if (!$event_id || empty($title))
                exit(json_encode(['status' => 'error', 'message' => 'Event ID and task title are required.']));

            $pdo->prepare("
                INSERT INTO charis_event_tasks
                  (event_id, task_title, task_category, task_description, assigned_worker_id, estimated_budget, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending')
            ")->execute([$event_id, $title, $category, $desc, $worker_id, $est_budget]);

            if ($worker_id) {
                $evtRow = $pdo->prepare("SELECT title, event_date FROM events WHERE id = ?");
                $evtRow->execute([$event_id]);
                $evt = $evtRow->fetch();
                $lbl = $evt ? $evt['title'] . ' on ' . date('d M Y', strtotime($evt['event_date'])) : 'an upcoming event';
                $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Event Task Assigned', ?, '/modules/charis/index.php')")
                    ->execute([$worker_id, "You've been assigned the task \"{$title}\" for {$lbl}. Open Event Planning to review."]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Task added and member notified.']);
            break;

        // =====================================================================================
        // ACTION: GET EVENT TASKS
        // =====================================================================================
        case 'get_event_tasks':
            $event_id = filter_var($_GET['event_id'] ?? $_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$event_id) exit(json_encode(['status' => 'error', 'message' => 'Event ID required.']));

            $stmt = $pdo->prepare("
                SELECT t.id, t.task_title, t.task_category, t.task_description,
                       t.estimated_budget, t.actual_spent, t.funds_disbursed, t.funds_disbursed_at,
                       t.member_report, t.receipt_note, t.status, t.completed_at,
                       t.created_at, t.assigned_worker_id,
                       u.first_name as worker_first, u.last_name as worker_last, u.phone as worker_phone
                FROM charis_event_tasks t
                LEFT JOIN users u ON t.assigned_worker_id = u.id
                WHERE t.event_id = ?
                ORDER BY t.created_at ASC
            ");
            $stmt->execute([$event_id]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$is_charis_admin) {
                $tasks = array_values(array_filter($tasks, fn($t) => (int)$t['assigned_worker_id'] === (int)$user_id));
            }

            $evtRow = $pdo->prepare("SELECT id, title, event_date, DATE_FORMAT(event_date,'%W, %b %D, %Y') as nice_date FROM events WHERE id = ?");
            $evtRow->execute([$event_id]);
            $event_info = $evtRow->fetch(PDO::FETCH_ASSOC);

            $stampRow = $pdo->prepare("SELECT * FROM charis_event_completion_stamps WHERE event_id = ?");
            $stampRow->execute([$event_id]);
            $stamp = $stampRow->fetch(PDO::FETCH_ASSOC);
            if ($stamp) {
                $stamp['hod_stamp_data']     = $stamp['hod_stamp_json']     ? json_decode($stamp['hod_stamp_json'],     true) : null;
                $stamp['finance_stamp_data'] = $stamp['finance_stamp_json'] ? json_decode($stamp['finance_stamp_json'], true) : null;
                unset($stamp['hod_stamp_json'], $stamp['finance_stamp_json']);
            }

            echo json_encode([
                'status'   => 'success',
                'tasks'    => $tasks,
                'event'    => $event_info,
                'stamp'    => $stamp ?: null,
                'is_admin' => $is_charis_admin,
            ]);
            break;

        // =====================================================================================
        // ACTION: UPDATE EVENT TASK
        // =====================================================================================
        case 'update_event_task':
            $task_id = filter_var($_POST['task_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$task_id) exit(json_encode(['status' => 'error', 'message' => 'Task ID required.']));

            $ownerRow = $pdo->prepare("SELECT assigned_worker_id, event_id FROM charis_event_tasks WHERE id = ?");
            $ownerRow->execute([$task_id]);
            $task = $ownerRow->fetch();
            if (!$task) exit(json_encode(['status' => 'error', 'message' => 'Task not found.']));
            if (!$is_charis_admin && (int)$task['assigned_worker_id'] !== (int)$user_id)
                exit(json_encode(['status' => 'error', 'message' => 'This task is not assigned to you.']));

            $new_status   = strip_tags($_POST['status'] ?? '');
            $actual_spent = filter_var($_POST['actual_spent'] ?? '', FILTER_VALIDATE_FLOAT);
            $report       = trim(strip_tags($_POST['member_report'] ?? ''));
            $receipt      = trim(strip_tags($_POST['receipt_note'] ?? ''));
            $mark_funds   = isset($_POST['mark_funds_received']) ? (int)$_POST['mark_funds_received'] : -1;

            $sets = []; $params = [];
            if (!empty($new_status) && in_array($new_status, ['Pending','In_Progress','Completed'])) {
                $sets[] = 'status = ?'; $params[] = $new_status;
                if ($new_status === 'Completed') $sets[] = 'completed_at = NOW()';
            }
            if ($actual_spent !== false) { $sets[] = 'actual_spent = ?';  $params[] = $actual_spent; }
            if (!empty($report))         { $sets[] = 'member_report = ?'; $params[] = $report; }
            if (!empty($receipt))        { $sets[] = 'receipt_note = ?';  $params[] = $receipt; }
            if ($mark_funds === 1)       { $sets[] = 'funds_disbursed = 1'; $sets[] = 'funds_disbursed_at = NOW()'; }

            if (empty($sets)) exit(json_encode(['status' => 'error', 'message' => 'Nothing to update.']));
            $params[] = $task_id;
            $pdo->prepare("UPDATE charis_event_tasks SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

            if ($new_status === 'Completed') {
                $tRow = $pdo->prepare("SELECT COUNT(*) FROM charis_event_tasks WHERE event_id = ?");
                $tRow->execute([$task['event_id']]);
                $dRow = $pdo->prepare("SELECT COUNT(*) FROM charis_event_tasks WHERE event_id = ? AND status = 'Completed'");
                $dRow->execute([$task['event_id']]);
                if ($tRow->fetchColumn() == $dRow->fetchColumn()) {
                    $pdo->prepare("UPDATE charis_event_logistics SET status = 'Completed' WHERE event_id = ?")->execute([$task['event_id']]);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Task updated successfully.']);
            break;

        // =====================================================================================
        // ACTION: DELETE EVENT TASK
        // =====================================================================================
        case 'delete_event_task':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $task_id = filter_var($_POST['task_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$task_id) exit(json_encode(['status' => 'error', 'message' => 'Task ID required.']));
            $pdo->prepare("DELETE FROM charis_event_tasks WHERE id = ?")->execute([$task_id]);
            echo json_encode(['status' => 'success', 'message' => 'Task removed.']);
            break;

        // =====================================================================================
        // ACTION: SET MONTHLY BUDGET
        // =====================================================================================
        case 'set_monthly_budget':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $budget_month = filter_var($_POST['budget_month'] ?? date('n'), FILTER_VALIDATE_INT);
            $budget_year  = filter_var($_POST['budget_year']  ?? date('Y'), FILTER_VALIDATE_INT);
            $amount       = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
            $notes        = trim(strip_tags($_POST['notes'] ?? ''));

            if (!$budget_month || !$budget_year || $amount === false || $amount < 0)
                exit(json_encode(['status' => 'error', 'message' => 'Valid month, year, and amount required.']));

            $pdo->prepare("
                INSERT INTO charis_monthly_budget (budget_month, budget_year, amount, set_by, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), set_by = VALUES(set_by), notes = VALUES(notes)
            ")->execute([$budget_month, $budget_year, $amount, $user_id, $notes]);
            echo json_encode(['status' => 'success', 'message' => 'Monthly budget saved.']);
            break;

        // =====================================================================================
        // ACTION: GET FINANCE DATA
        // =====================================================================================
        case 'get_finance_data':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $f_month = filter_var($_POST['month'] ?? date('n'), FILTER_VALIDATE_INT);
            $f_year  = filter_var($_POST['year']  ?? date('Y'), FILTER_VALIDATE_INT);

            $bRow = $pdo->prepare("SELECT amount, notes FROM charis_monthly_budget WHERE budget_month = ? AND budget_year = ?");
            $bRow->execute([$f_month, $f_year]);
            $monthly_budget = $bRow->fetch(PDO::FETCH_ASSOC);

            $actRow = $pdo->prepare("SELECT SUM(COALESCE(amount_spent, amount_requested)) FROM charis_expenses WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?");
            $actRow->execute([$f_month, $f_year]);
            $total_actual = (float)($actRow->fetchColumn() ?? 0);

            $catRow = $pdo->prepare("SELECT category, SUM(COALESCE(amount_spent, amount_requested)) as total FROM charis_expenses WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? GROUP BY category ORDER BY total DESC");
            $catRow->execute([$f_month, $f_year]);
            $categories = $catRow->fetchAll(PDO::FETCH_ASSOC);

            $trendRow = $pdo->query("
                SELECT DATE_FORMAT(created_at,'%b %Y') as label, MONTH(created_at) as mo, YEAR(created_at) as yr,
                       SUM(COALESCE(amount_spent, amount_requested)) as total
                FROM charis_expenses
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY YEAR(created_at), MONTH(created_at)
                ORDER BY YEAR(created_at) ASC, MONTH(created_at) ASC
            ");
            $trend = $trendRow->fetchAll(PDO::FETCH_ASSOC);

            $budgetTrend = [];
            foreach ($trend as $t) {
                $bTmp = $pdo->prepare("SELECT amount FROM charis_monthly_budget WHERE budget_month = ? AND budget_year = ?");
                $bTmp->execute([$t['mo'], $t['yr']]);
                $budgetTrend[] = (float)($bTmp->fetchColumn() ?? 0);
            }

            $fcRow = $pdo->query("SELECT category, ROUND(SUM(COALESCE(amount_spent, amount_requested)) / 3, 2) as projected FROM charis_expenses WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) GROUP BY category");
            $forecast = $fcRow->fetchAll(PDO::FETCH_ASSOC);

            $ledgerRow = $pdo->prepare("SELECT id, item_name, category, amount_requested, amount_approved, amount_spent, status FROM charis_expenses WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? ORDER BY created_at DESC");
            $ledgerRow->execute([$f_month, $f_year]);
            $ledger = $ledgerRow->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'         => 'success',
                'monthly_budget' => $monthly_budget,
                'total_actual'   => $total_actual,
                'categories'     => $categories,
                'trend'          => $trend,
                'budget_trend'   => $budgetTrend,
                'forecast'       => $forecast,
                'ledger'         => $ledger,
            ]);
            break;

        // =====================================================================================
        // ACTION: HOD STAMPS EVENT COMPLETION
        // =====================================================================================
        case 'stamp_event_completion':
            if (!$is_charis_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $event_id = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$event_id) exit(json_encode(['status' => 'error', 'message' => 'Event ID required.']));

            $sigRow = $pdo->prepare("SELECT signature_base64 FROM pastor_signature_vault WHERE user_id = ? AND is_active = 1");
            $sigRow->execute([$user_id]);
            if (!$sigRow->fetchColumn())
                exit(json_encode(['status' => 'error', 'message' => 'No saved signature found. Please set up your digital signature first.']));

            $stamperRow = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $stamperRow->execute([$user_id]);
            $stamper = $stamperRow->fetch();

            $tSpent = $pdo->prepare("SELECT SUM(COALESCE(actual_spent,0)) FROM charis_event_tasks WHERE event_id = ?");
            $tSpent->execute([$event_id]);
            $total_spent = (float)($tSpent->fetchColumn() ?? 0);

            $ref         = 'EVT-' . $event_id . '-' . date('Ymd');
            $now         = date('Y-m-d H:i:s');
            $ip          = getClientIP();
            $hash_code   = generateStampHash($event_id, $user_id, $now, $total_spent);
            $verify_code = generateVerifyCode($user_id, $event_id, time());
            $stamp_data  = buildStampData($stamper, 'HOD, Charis Dept', 'Event Report - HOD Sign-Off', $ref, $total_spent, $ip, $hash_code, $verify_code);

            $pdo->prepare("
                INSERT INTO charis_event_completion_stamps
                  (event_id, hod_stamp_json, hod_stamped_by, hod_stamp_at, hod_hash_code, hod_verify_code)
                VALUES (?, ?, ?, NOW(), ?, ?)
                ON DUPLICATE KEY UPDATE
                  hod_stamp_json = VALUES(hod_stamp_json), hod_stamped_by = VALUES(hod_stamped_by),
                  hod_stamp_at = NOW(), hod_hash_code = VALUES(hod_hash_code), hod_verify_code = VALUES(hod_verify_code)
            ")->execute([$event_id, json_encode($stamp_data), $user_id, $hash_code, $verify_code]);

            $pdo->prepare("UPDATE charis_event_logistics SET status = 'Completed' WHERE event_id = ?")->execute([$event_id]);
            echo json_encode(['status' => 'success', 'message' => 'Signed off. Notify Finance Director to countersign.', 'stamp' => $stamp_data]);
            break;

        // =====================================================================================
        // ACTION: FINANCE DIRECTOR COUNTERSIGN (Lola Bowale user_id=22 or any Director/HOD)
        // =====================================================================================
        case 'finance_stamp_event':
            $event_id = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$event_id) exit(json_encode(['status' => 'error', 'message' => 'Event ID required.']));

            $isFinanceDir = ((int)$user_id === 22);
            if (!$isFinanceDir && isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
                foreach ($_SESSION['roles'] as $r) {
                    if (in_array($r['role_name'] ?? '', ['Director','HOD','Super_Admin'])) { $isFinanceDir = true; break; }
                }
            }
            if (!$isFinanceDir) exit(json_encode(['status' => 'error', 'message' => 'Only the Finance Director can countersign.']));

            $hodCheck = $pdo->prepare("SELECT hod_stamp_at FROM charis_event_completion_stamps WHERE event_id = ?");
            $hodCheck->execute([$event_id]);
            if (!$hodCheck->fetchColumn())
                exit(json_encode(['status' => 'error', 'message' => 'HOD must sign off first.']));

            $sigRow = $pdo->prepare("SELECT signature_base64 FROM pastor_signature_vault WHERE user_id = ? AND is_active = 1");
            $sigRow->execute([$user_id]);
            if (!$sigRow->fetchColumn())
                exit(json_encode(['status' => 'error', 'message' => 'No saved signature on file.']));

            $stamperRow = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $stamperRow->execute([$user_id]);
            $stamper = $stamperRow->fetch();

            $tSpent = $pdo->prepare("SELECT SUM(COALESCE(actual_spent,0)) FROM charis_event_tasks WHERE event_id = ?");
            $tSpent->execute([$event_id]);
            $total_spent = (float)($tSpent->fetchColumn() ?? 0);

            $ref         = 'EVT-' . $event_id . '-FIN-' . date('Ymd');
            $now         = date('Y-m-d H:i:s');
            $ip          = getClientIP();
            $hash_code   = generateStampHash($event_id, $user_id, $now, $total_spent);
            $verify_code = generateVerifyCode($user_id, $event_id, time());
            $stamp_data  = buildStampData($stamper, 'Finance Director, HODLC', 'Event Report - Finance Countersign', $ref, $total_spent, $ip, $hash_code, $verify_code);

            $pdo->prepare("
                UPDATE charis_event_completion_stamps SET
                  finance_stamp_json = ?, finance_stamped_by = ?, finance_stamp_at = NOW(),
                  finance_hash_code = ?, finance_verify_code = ?
                WHERE event_id = ?
            ")->execute([json_encode($stamp_data), $user_id, $hash_code, $verify_code, $event_id]);

            echo json_encode(['status' => 'success', 'message' => 'Finance countersignature applied. Report fully sealed.', 'stamp' => $stamp_data]);
            break;

        // =====================================================================================
        // DEFAULT
        // =====================================================================================
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Charis API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
?>
