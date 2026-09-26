<?php
// /api/reach_api.php

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

// Check Clearance: Super Admin, Pastors, or Reach/Evangelism Department
$has_clearance = false;
if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'])) {
    $has_clearance = true;
} else {
    $deptStmt = $pdo->prepare("
        SELECT d.id FROM departments d 
        JOIN user_departments ud ON d.id = ud.department_id 
        WHERE ud.user_id = ? AND ud.is_active = 1 
        AND (d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%')
    ");
    $deptStmt->execute([$user_id]);
    if ($deptStmt->fetch()) $has_clearance = true;
}

if (!$has_clearance) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: You must be in the Reach/Evangelism department to access this module.']);
    exit;
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH DASHBOARD DATA
        // ==========================================
        case 'fetch_dashboard':
            
            // A. High-Level Evangelism Stats (Year to Date)
            $stats = [];
            $stats['souls_ytd'] = $pdo->query("SELECT COUNT(*) FROM reach_souls WHERE YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
            $stats['campaigns_ytd'] = $pdo->query("SELECT COUNT(*) FROM reach_campaigns WHERE YEAR(campaign_date) = YEAR(CURDATE())")->fetchColumn();
            $stats['pending_followups'] = $pdo->query("SELECT COUNT(*) FROM reach_souls WHERE status = 'Pending_Followup'")->fetchColumn();
            $stats['wants_to_visit'] = $pdo->query("SELECT COUNT(*) FROM reach_souls WHERE wants_to_visit = 1 AND status = 'Pending_Followup'")->fetchColumn();

            // B. Fetch Recent & Upcoming Campaigns
            $campaignsStmt = $pdo->query("
                SELECT *, DATE_FORMAT(campaign_date, '%M %D, %Y') as nice_date,
                       (SELECT COUNT(*) FROM reach_souls s WHERE s.campaign_id = reach_campaigns.id) as souls_won
                FROM reach_campaigns
                ORDER BY campaign_date DESC
                LIMIT 15
            ");
            $campaigns = $campaignsStmt->fetchAll(PDO::FETCH_ASSOC);

            // C. Fetch Recent Souls Captured
            $soulsStmt = $pdo->query("
                SELECT s.*, c.title as campaign_title, u.first_name as evangelist_fname, u.last_name as evangelist_lname,
                       DATE_FORMAT(s.created_at, '%b %d, %Y') as date_captured
                FROM reach_souls s
                LEFT JOIN reach_campaigns c ON s.campaign_id = c.id
                LEFT JOIN users u ON s.captured_by = u.id
                ORDER BY s.created_at DESC
                LIMIT 30
            ");
            $souls = $soulsStmt->fetchAll(PDO::FETCH_ASSOC);

            // D. Get active campaigns for the rapid-entry form dropdown
            $activeCampaigns = $pdo->query("SELECT id, title FROM reach_campaigns WHERE status != 'Cancelled' ORDER BY campaign_date DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'stats' => $stats,
                'campaigns' => $campaigns,
                'souls' => $souls,
                'active_campaigns' => $activeCampaigns
            ]);
            break;

        // ==========================================
        // ACTION 2: SAVE OUTREACH CAMPAIGN
        // ==========================================
        case 'save_campaign':
            $title = trim($_POST['title'] ?? '');
            $type = $_POST['campaign_type'] ?? 'Saturday_Evangelism';
            $date = $_POST['campaign_date'] ?? '';
            $location = trim($_POST['location'] ?? '');
            
            if (empty($title) || empty($date)) {
                echo json_encode(['status' => 'error', 'message' => 'Campaign title and date are required.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO reach_campaigns (title, campaign_type, campaign_date, location) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $type, $date, $location]);

            // NOTIFICATION TRIGGER: Alert all Reach Department Workers of the upcoming campaign
            $reachStmt = $pdo->query("
                SELECT ud.user_id FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE (d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%') AND ud.is_active = 1
            ");
            $reach_members = $reachStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($reach_members)) {
                $nice_date = date('M jS, Y', strtotime($date));
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Outreach Campaign', ?, '/modules/reach/index.php')");
                $msg = "A new {$type} outreach titled '{$title}' is scheduled for {$nice_date} at {$location}. Get ready to win souls!";
                
                foreach($reach_members as $uid) {
                    if ($uid != $user_id) { // Don't notify the admin creating it
                        $notifStmt->execute([$uid, $msg]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Outreach campaign successfully scheduled!']);
            break;

        // ==========================================
        // ACTION 3: CAPTURE EVANGELISM DATA (SOUL)
        // ==========================================
        case 'save_soul':
            $campaign_id = !empty($_POST['campaign_id']) ? $_POST['campaign_id'] : null;
            $fname = trim($_POST['first_name'] ?? '');
            $lname = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $gender = $_POST['gender'] ?? null;
            
            // Boolean Conversions from Checkboxes
            $is_churched = isset($_POST['is_churched']) ? 1 : 0;
            $previous_church = trim($_POST['previous_church'] ?? '');
            $is_baptized = isset($_POST['is_baptized']) ? 1 : 0;
            $wants_to_visit = isset($_POST['wants_to_visit']) ? 1 : 0;
            
            $prayer_requests = trim($_POST['prayer_requests'] ?? '');

            if (empty($fname)) {
                echo json_encode(['status' => 'error', 'message' => 'First name is strictly required.']);
                exit;
            }

            // Clean up unchurched logic
            if (!$is_churched) {
                $previous_church = null;
            }

            $stmt = $pdo->prepare("
                INSERT INTO reach_souls 
                (campaign_id, first_name, last_name, phone, address, gender, is_churched, previous_church, is_baptized, wants_to_visit, prayer_requests, captured_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $campaign_id, $fname, $lname, $phone, $address, $gender, 
                $is_churched, $previous_church, $is_baptized, $wants_to_visit, $prayer_requests, $user_id
            ]);

            // NOTIFICATION TRIGGER 1: Alert Reach Leadership to assign follow-up
            $reachLeaderStmt = $pdo->query("
                SELECT ud.user_id FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE (d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%') AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
            ");
            $reach_leaders = $reachLeaderStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($reach_leaders)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Soul Captured', ?, '/modules/reach/index.php')");
                foreach($reach_leaders as $uid) {
                    if ($uid != $user_id) {
                        $notifStmt->execute([$uid, "{$fname} {$lname} was just logged in the system. They are pending immediate follow-up."]);
                    }
                }
            }

            // NOTIFICATION TRIGGER 2: Cross-module push to Zoe if they have a prayer request
            if (!empty($prayer_requests)) {
                // Push directly to Zoe's database table
                $pdo->prepare("INSERT INTO zoe_prayer_requests (submitted_by, request_text) VALUES (?, ?)")
                    ->execute([$user_id, "From Outreach ({$fname}): " . $prayer_requests]);

                $zoeStmt = $pdo->query("
                    SELECT ud.user_id FROM user_departments ud 
                    JOIN departments d ON ud.department_id = d.id 
                    WHERE d.name LIKE '%Zoe%' AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
                ");
                $zoe_leaders = $zoeStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($zoe_leaders)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Outreach Prayer Request', ?, '/modules/zoe/index.php')");
                    foreach($zoe_leaders as $uid) {
                        $notifStmt->execute([$uid, "A new prayer request was gathered during evangelism for {$fname}. Please check the Zoe dashboard."]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Soul securely logged for follow-up!']);
            break;

        // ==========================================
        // ACTION 4: UPDATE FOLLOW-UP STATUS
        // ==========================================
        case 'update_soul_status':
            $soul_id = $_POST['soul_id'] ?? '';
            $status = $_POST['status'] ?? '';

            if (empty($soul_id) || empty($status)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid status update request.']);
                exit;
            }

            // Fetch the soul's details before updating so we know who originally captured them
            $soulStmt = $pdo->prepare("SELECT first_name, last_name, captured_by FROM reach_souls WHERE id = ?");
            $soulStmt->execute([$soul_id]);
            $soulData = $soulStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE reach_souls SET status = ? WHERE id = ?");
            $stmt->execute([$status, $soul_id]);

            // NOTIFICATION TRIGGER: Let the original evangelist know their fruit is being nurtured
            if ($soulData && $soulData['captured_by'] && $soulData['captured_by'] != $user_id) {
                $cleanStatus = str_replace('_', ' ', $status);
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Evangelism Follow-up Update', ?, '/modules/reach/index.php')");
                $msg = "Great news! The follow-up status for a soul you captured ({$soulData['first_name']} {$soulData['last_name']}) has been updated to: {$cleanStatus}.";
                $notifStmt->execute([$soulData['captured_by'], $msg]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Follow-up status updated!']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Reach API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
?>