<?php
// /api/dashboard_api.php

// 1. Core Includes & Headers
require_once '../includes/db.php';
header('Content-Type: application/json');

// 2. Security Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_role'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.', 'redirect' => '/auth/login.php']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];

// Prepare the unified response structure
$response = [
    'status' => 'success',
    'role' => $active_role,
    'user_name' => $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
    'metrics' => [],
    'announcements' => []
];

try {
    // 3. THE ROLE-BASED DATA ENGINE
    // =========================================================================
    
    // LEVEL 1: LEADERSHIP (Global Pulse)
    if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'])) {
        
        // Metric: Total Workforce
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE spiritual_status = 'Worker'");
        $response['metrics']['total_workers'] = $stmt->fetchColumn();

        // Metric: New Funnel (Visitors + 1st Timers currently in pipeline)
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE spiritual_status IN ('Visitor', '1st_Timer')");
        $response['metrics']['total_visitors'] = $stmt->fetchColumn();

        // Metric: Pending Pastoral Questions
        $stmt = $pdo->query("SELECT COUNT(*) FROM pastor_qa WHERE status = 'Pending'");
        $response['metrics']['pending_qa'] = $stmt->fetchColumn();

        // Metric: Active Retention Tasks (Embrace Follow-ups currently unassigned or pending)
        $stmt = $pdo->query("SELECT COUNT(*) FROM embrace_followups WHERE status = 'Pending'");
        $response['metrics']['pending_followups'] = $stmt->fetchColumn();

        // Metric: Last Sunday Attendance Count
        $stmt = $pdo->query("
            SELECT COUNT(a.id) 
            FROM attendance a 
            JOIN events e ON a.event_id = e.id 
            WHERE e.event_category = 'Sunday_Service' 
            AND e.event_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $response['metrics']['last_sunday_attendance'] = $stmt->fetchColumn();
    }

    // LEVEL 2: DIRECTORS (Unit View)
    elseif ($active_role === 'Director') {
        // FIX: Query the pivot table for active Director roles
        $stmt = $pdo->prepare("SELECT department_id FROM user_departments WHERE user_id = ? AND role_in_dept = 'Director' AND is_active = 1");
        $stmt->execute([$user_id]);
        $dept_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($dept_ids)) {
            // Count total unique workers across all managed units
            $in = str_repeat('?,', count($dept_ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM user_departments WHERE department_id IN ($in) AND is_active = 1");
            $stmt->execute($dept_ids);
            
            $response['metrics']['team_size'] = $stmt->fetchColumn();
            $response['metrics']['managed_units'] = count($dept_ids);
        } else {
            // Fallback if they are marked as Director but have no assigned units yet
            $response['metrics']['team_size'] = 0;
            $response['metrics']['managed_units'] = 0;
        }
    }

    // LEVEL 3: HOD (Departmental View)
    elseif ($active_role === 'HOD') {
        // FIX: Query the pivot table for the active HOD role
        $stmt = $pdo->prepare("SELECT department_id FROM user_departments WHERE user_id = ? AND role_in_dept = 'HOD' AND is_active = 1 LIMIT 1");
        $stmt->execute([$user_id]);
        $dept_id = $stmt->fetchColumn();

        if ($dept_id) {
            // Count total active workers in this specific unit
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM user_departments WHERE department_id = ? AND is_active = 1");
            $stmt->execute([$dept_id]);
            $response['metrics']['active_workers'] = $stmt->fetchColumn();
        } else {
             // Fallback if they are marked as HOD but have no assigned unit yet
            $response['metrics']['active_workers'] = 0;
        }
    }

    // LEVEL 4: STANDARD WORKER/MEMBER (Personal Inbox)
    else {
        // Personal Notifications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $response['metrics']['unread_alerts'] = $stmt->fetchColumn();
    }

    // 4. GLOBAL DATA (Fetched for every role)
    // =========================================================================
    
    // Live Ministry Announcements (Only Approved notices for current/future events)
    $stmt = $pdo->query("
        SELECT a.title, a.content, e.title as event_name, DATE_FORMAT(e.event_date, '%b %D') as date_label
        FROM announcements a
        JOIN events e ON a.target_event_id = e.id
        WHERE a.status = 'Approved' 
        AND e.event_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ORDER BY e.event_date ASC
        LIMIT 4
    ");
    $response['announcements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Dashboard API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Internal Engine Error.']);
}
?>