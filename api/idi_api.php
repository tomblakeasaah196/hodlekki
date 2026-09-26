<?php
// /api/idi_api.php
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

// 1. STRICT SECURITY GATE: Leadership OR IDI Department
$allowed_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];
$has_access = false;

if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $allowed_roles)) {
            $has_access = true;
            break;
        }
    }
}

if (!$has_access) {
    $deptStmt = $pdo->prepare("SELECT 1 FROM user_departments WHERE user_id = ? AND department_id = 1 AND is_active = 1");
    $deptStmt->execute([$_SESSION['user_id']]);
    if ($deptStmt->fetch()) {
        $has_access = true;
    }
}

if (!$has_access) {
    echo json_encode(['status' => 'error', 'message' => 'CRITICAL CLASSIFIED: Only Leadership and IDI Personnel can access Data Insights.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'fetch_all_insights';
        
        $insights = [
            'status' => 'success',
            'date_range' => ['start' => $start_date, 'end' => $end_date],
            'congregation' => [],
            'attendance' => [],
            'embrace' => [],
            'operations' => [],
            'academy' => [],
            'charis' => [],
            'pastoral' => []
        ];

        // ==========================================================
        // PILLAR 1: CONGREGATION HEALTH & FUNNEL
        // ==========================================================
        // Spiritual Status Breakdown (The Funnel)
        $stmt = $pdo->query("SELECT spiritual_status, COUNT(*) as total FROM users GROUP BY spiritual_status");
        $insights['congregation']['funnel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gender & Demographics
        $stmt = $pdo->query("SELECT gender, COUNT(*) as total FROM users WHERE gender IS NOT NULL GROUP BY gender");
        $insights['congregation']['gender'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Overall Attendance Status (Active, Inconsistent, Relocated, etc.)
        $stmt = $pdo->query("SELECT attendance_status, COUNT(*) as total FROM users GROUP BY attendance_status");
        $insights['congregation']['attendance_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================================
        // PILLAR 2: ATTENDANCE & EVENT TRENDS
        // ==========================================================
        // Registration vs Reality & Category Comparison (Filtered by Date)
        $stmt = $pdo->prepare("
            SELECT e.id, e.title, e.event_category, DATE(e.event_date) as event_date,
                   (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id) as pre_registered,
                   (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.status = 'Present') as actually_attended
            FROM events e
            WHERE e.event_date BETWEEN ? AND ? AND e.is_closed = 1
            ORDER BY e.event_date ASC
        ");
        $stmt->execute([$start_date, $end_date]);
        $insights['attendance']['event_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Averages by Category (Sunday vs Midweek)
        $stmt = $pdo->prepare("
            SELECT e.event_category, ROUND(AVG(
                (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.status = 'Present')
            ), 0) as avg_attendance
            FROM events e
            WHERE e.event_date BETWEEN ? AND ? AND e.is_closed = 1
            GROUP BY e.event_category
        ");
        $stmt->execute([$start_date, $end_date]);
        $insights['attendance']['category_averages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================================
        // PILLAR 3: EMBRACE & RETENTION
        // ==========================================================
        // Follow-up Completion Rates
        $stmt = $pdo->prepare("
            SELECT status, followup_method, COUNT(*) as total 
            FROM embrace_followups 
            WHERE followup_date BETWEEN ? AND ? 
            GROUP BY status, followup_method
        ");
        $stmt->execute([$start_date, $end_date]);
        $insights['embrace']['followup_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================================
        // PILLAR 4: CHARIS (WELFARE) & THE "BACK" CALCULATION
        // ==========================================================
        // Total members currently marked as Unknown/Inconsistent
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE attendance_status IN ('Unknown', 'Inconsistent')");
        $insights['charis']['total_at_risk'] = $stmt->fetchColumn();

        // The Auto-Detection: How many 'Unknown'/'Inconsistent' clocked in over the last 14 days?
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT u.id) 
            FROM users u 
            JOIN attendance a ON u.id = a.user_id 
            WHERE u.attendance_status IN ('Unknown', 'Inconsistent') 
              AND a.status = 'Present' 
              AND a.check_in_time >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        ");
        $insights['charis']['auto_detected_back'] = $stmt->fetchColumn();

        // Upcoming Birthdays (Next 30 days)
        $stmt = $pdo->query("
            SELECT id, first_name, last_name, dob, phone 
            FROM users 
            WHERE dob IS NOT NULL 
              AND DATE_ADD(dob, INTERVAL YEAR(CURDATE())-YEAR(dob) + IF(DAYOFYEAR(CURDATE()) > DAYOFYEAR(dob),1,0) YEAR) 
              BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY MONTH(dob), DAY(dob)
        ");
        $insights['charis']['upcoming_birthdays'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================================
        // PILLAR 5: MINISTRY OPERATIONS & ACADEMY
        // ==========================================================
        // Department Staffing Levels (Active Workers)
        $stmt = $pdo->query("
            SELECT d.name, COUNT(ud.user_id) as total_workers 
            FROM departments d 
            LEFT JOIN user_departments ud ON d.id = ud.department_id AND ud.is_active = 1 
            GROUP BY d.id 
            ORDER BY total_workers DESC
        ");
        $insights['operations']['department_health'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Academy Graduation Pipeline
        $stmt = $pdo->query("
            SELECT graduation_status, COUNT(*) as total 
            FROM academy_enrollments 
            GROUP BY graduation_status
        ");
        $insights['academy']['pipeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================================
        // PILLAR 6: PASTORAL DESK (FEEDBACK)
        // ==========================================================
        // Q&A Status & Anonymity Ratio
        $stmt = $pdo->prepare("
            SELECT status, is_anonymous, priority, COUNT(*) as total 
            FROM pastor_qa 
            WHERE submitted_at BETWEEN ? AND ? 
            GROUP BY status, is_anonymous, priority
        ");
        $stmt->execute([$start_date, $end_date]);
        $insights['pastoral']['qa_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Suggestions Status
        $stmt = $pdo->prepare("
            SELECT status, priority, COUNT(*) as total 
            FROM suggestions 
            WHERE submitted_at BETWEEN ? AND ? 
            GROUP BY status, priority
        ");
        $stmt->execute([$start_date, $end_date]);
        $insights['pastoral']['suggestion_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ----------------------------------------------------------
        // RAW EXPORT DATA (For the Frontend Excel Generator)
        // ----------------------------------------------------------
        // We provide a flat list of users for the master excel export
        $insights['export_data']['master_roster'] = $pdo->query("SELECT first_name, last_name, email, phone, spiritual_status, attendance_status, gender FROM users")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($insights);

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid IDI action.']);
    }

} catch (PDOException $e) {
    error_log("IDI API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error during data aggregation.']);
}
?>