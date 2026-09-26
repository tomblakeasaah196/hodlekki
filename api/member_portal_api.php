<?php
// /api/member_portal_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH PERSONAL DASHBOARD DATA
        // ==========================================
        case 'fetch_dashboard':
            $period_start = $_GET['start_date'] ?? date('Y-m-01'); // Default to current month
            $period_end = $_GET['end_date'] ?? date('Y-m-t');

            // 1. User Identity & Groups
            $userStmt = $pdo->prepare("
                SELECT u.first_name, u.last_name, u.spiritual_status,
                       (SELECT t.name FROM user_tribes ut JOIN tribes t ON ut.tribe_id = t.id WHERE ut.user_id = u.id ORDER BY ut.joined_at DESC LIMIT 1) as tribe_name,
                       (SELECT GROUP_CONCAT(d.name SEPARATOR ', ') FROM user_departments ud JOIN departments d ON ud.department_id = d.id WHERE ud.user_id = u.id AND ud.is_active = 1) as departments
                FROM users u WHERE u.id = ?
            ");
            $userStmt->execute([$user_id]);
            $user_info = $userStmt->fetch(PDO::FETCH_ASSOC);

            // 2. Financial Contributions Summary (Selected Period)
            $finStmt = $pdo->prepare("
                SELECT contribution_type, SUM(amount) as total_amount 
                FROM member_contributions 
                WHERE user_id = ? AND contribution_date BETWEEN ? AND ?
                GROUP BY contribution_type
            ");
            $finStmt->execute([$user_id, $period_start, $period_end]);
            $financials = $finStmt->fetchAll(PDO::FETCH_ASSOC);

            $total_given = 0;
            foreach ($financials as $f) { $total_given += (float)$f['total_amount']; }

            // Pastoral Financial Exhortation
            $finance_message = "";
            if ($total_given > 0) {
                $finance_message = "Dear {$user_info['first_name']}, thank you for your faithful partnership in expanding God's kingdom. 'Bring the whole tithe into the storehouse... and see if I will not throw open the floodgates of heaven.' (Malachi 3:10). May the Lord multiply your seed sown! \n\n— Pastor Ebele Uzo-Peters";
            } else {
                $finance_message = "Dear {$user_info['first_name']}, giving is a profound act of worship and trust in God's provision. 'Honor the Lord with your wealth, with the firstfruits of all your crops' (Proverbs 3:9). We encourage you to step into the blessing of faithful giving. \n\n— Pastor Ebele Uzo-Peters";
            }

            // 3. Attendance History (Last 5 events)
            $attStmt = $pdo->prepare("
                SELECT e.title, e.event_date, a.status 
                FROM attendance a
                JOIN events e ON a.event_id = e.id
                WHERE a.user_id = ? AND e.event_date <= CURDATE()
                ORDER BY e.event_date DESC LIMIT 5
            ");
            $attStmt->execute([$user_id]);
            $attendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);

            // Pastoral Attendance Exhortation
            $present_count = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));
            $attendance_message = "";
            if ($present_count >= 3) {
                $attendance_message = "Your consistency in the house of God is truly inspiring! 'I rejoiced with those who said to me, Let us go to the house of the Lord.' (Psalm 122:1). Keep fueling your spiritual fire!";
            } else {
                $attendance_message = "We have missed seeing you consistently! 'And let us not neglect our meeting together, as some people do, but encourage one another.' (Hebrews 10:25). We can't wait to worship with you at our next service.";
            }

            // 4. Upcoming Events & Registrations
            $upcomingStmt = $pdo->query("
                SELECT id, title, event_category, event_date 
                FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5
            ");
            $upcoming_events = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Active Announcements (Adapted to your actual schema)
            $annStmt = $pdo->query("
                SELECT title, content 
                FROM announcements 
                WHERE status = 'Approved' 
                ORDER BY id DESC 
                LIMIT 3
            ");
            $announcements = $annStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'user_info' => $user_info,
                'financials' => [
                    'breakdown' => $financials,
                    'total' => $total_given,
                    'message' => $finance_message
                ],
                'attendance' => [
                    'history' => $attendance,
                    'message' => $attendance_message
                ],
                'upcoming_events' => $upcoming_events,
                'announcements' => $announcements
            ]);
            break;

        // ==========================================
        // ACTION 2: SUBMIT CONTRIBUTION
        // ==========================================
        case 'submit_contribution':
            $amount = floatval($_POST['amount'] ?? 0);
            $type = $_POST['contribution_type'] ?? 'Tithe';
            $date = $_POST['contribution_date'] ?? date('Y-m-d');
            
            if ($amount <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Please enter a valid amount.']);
                exit;
            }

            // Handle optional receipt upload
            $receipt_path = null;
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/receipts/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
                
                if (in_array($ext, $allowed)) {
                    $filename = 'receipt_' . $user_id . '_' . time() . '.' . $ext;
                    $target_file = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target_file)) {
                        $receipt_path = '/uploads/receipts/' . $filename;
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid file format. Only JPG, PNG, and PDF are allowed.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO member_contributions (user_id, amount, contribution_type, contribution_date, receipt_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $amount, $type, $date, $receipt_path]);

            // NOTIFICATION TRIGGER: Alert Finance/Leadership of the new contribution for reconciliation
            $adminStmt = $pdo->query("
                SELECT user_id FROM user_roles 
                JOIN roles ON user_roles.role_id = roles.id 
                WHERE roles.role_name IN ('Super_Admin', 'Resident_Pastor', 'Director')
            ");
            $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($admins)) {
                $formatted_amount = number_format($amount, 2);
                $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$user_id]);
                $uName = $uStmt->fetch(PDO::FETCH_ASSOC);
                $memberName = $uName ? "{$uName['first_name']} {$uName['last_name']}" : "A member";

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Member Contribution', ?, '/modules/finance/index.php')");
                $alertMsg = "{$memberName} has submitted a {$type} contribution of ₦{$formatted_amount} pending reconciliation.";
                
                foreach($admins as $admin_id) {
                    if ($admin_id != $user_id) { // In case an admin is logging their own tithe
                        $notifStmt->execute([$admin_id, $alertMsg]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Your contribution has been recorded successfully. May God bless you!']);
            break;
            
            // ==========================================
        // ACTION 3: SUBMIT PRAYER REQUEST (TO ZOE)
        // ==========================================
        case 'submit_prayer_request':
            $request_text = trim($_POST['request_text'] ?? '');
            
            if (empty($request_text)) {
                echo json_encode(['status' => 'error', 'message' => 'Please enter your prayer request.']);
                exit;
            }

            // Insert into the Zoe Prayer Requests table
            $stmt = $pdo->prepare("INSERT INTO zoe_prayer_requests (submitted_by, request_text) VALUES (?, ?)");
            $stmt->execute([$user_id, $request_text]);

            // NOTIFICATION TRIGGER: Alert Zoe Intercessory Leaders
            $zoeStmt = $pdo->query("
                SELECT ud.user_id FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE d.name LIKE '%Zoe%' AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
            ");
            $zoe_leaders = $zoeStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($zoe_leaders)) {
                $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$user_id]);
                $uName = $uStmt->fetch(PDO::FETCH_ASSOC);
                $memberName = $uName ? "{$uName['first_name']} {$uName['last_name']}" : "A member";

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Prayer Request', ?, '/modules/zoe/index.php')");
                $alertMsg = "{$memberName} has submitted a new prayer request. Please keep them in your prayers.";
                
                foreach($zoe_leaders as $leader_id) {
                    $notifStmt->execute([$leader_id, $alertMsg]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Your prayer request has been securely sent to the Zoe Intercessory team.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Member Portal API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
}
?>