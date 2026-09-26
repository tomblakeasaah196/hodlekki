<?php
// /api/department_api.php
require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Core Security & Session Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 2. RBAC Check (Only Admins, Pastors, Directors, and HODs can access this core module)
$is_admin = in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD']);
if (!$is_admin) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. Department management is restricted to leadership.']);
    exit;
}

// Helper Function: Get Current Active Leader for a Role
function getActiveLeaderId($pdo, $dept_id, $role_in_dept) {
    if (!$dept_id) return null;
    $stmt = $pdo->prepare("SELECT user_id FROM user_departments WHERE department_id = ? AND role_in_dept = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$dept_id, $role_in_dept]);
    return $stmt->fetchColumn();
}

// Helper Function: Sync Global Roles Automatically
function syncGlobalRole($pdo, $user_id, $role_name) {
    if (!$user_id) return;
    $roles = ['Super_Admin'=>1, 'Resident_Pastor'=>2, 'Assoc_Pastor'=>3, 'Director'=>4, 'HOD'=>5, 'Worker'=>6];
    $role_id = $roles[$role_name] ?? 6;
    
    // Give them the global role if they don't already have it
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $role_id]);
}

// Helper Function: Add Leader to Department Roster Automatically (Now includes Season)
function assignLeaderToRoster($pdo, $dept_id, $user_id, $role_in_dept, $season) {
    if (!$user_id) return;
    
    // Check if they are already active in this role for this season
    $check = $pdo->prepare("SELECT id FROM user_departments WHERE user_id = ? AND department_id = ? AND role_in_dept = ? AND season = ? AND is_active = 1");
    $check->execute([$user_id, $dept_id, $role_in_dept, $season]);
    if (!$check->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO user_departments (user_id, department_id, role_in_dept, season, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$user_id, $dept_id, $role_in_dept, $season]);
    }
    syncGlobalRole($pdo, $user_id, $role_in_dept);
}

// Helper Function: Demote Previous Leader
function demotePreviousLeader($pdo, $dept_id, $old_user_id, $role_in_dept) {
    if (!$old_user_id) return;
    // Soft delete their active leadership status, marking the end date
    $stmt = $pdo->prepare("UPDATE user_departments SET is_active = 0, end_date = CURDATE() WHERE department_id = ? AND user_id = ? AND role_in_dept = ? AND is_active = 1");
    $stmt->execute([$dept_id, $old_user_id, $role_in_dept]);
}

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH DASHBOARD (All Departments & Master Lists)
        // =====================================================================================
        case 'fetch_dashboard':
            // Get all Master Departments (Parent = NULL) pulling leadership from the pivot table
            $masterStmt = $pdo->query("
                SELECT d.*, 
                       ap.first_name as ap_fname, ap.last_name as ap_lname,
                       dir.first_name as dir_fname, dir.last_name as dir_lname,
                       hod.first_name as hod_fname, hod.last_name as hod_lname,
                       (SELECT COUNT(*) FROM user_departments ud WHERE ud.department_id = d.id AND ud.is_active = 1) as active_members,
                       (SELECT COUNT(*) FROM departments sub WHERE sub.parent_id = d.id) as sub_unit_count
                FROM departments d
                LEFT JOIN user_departments ud_ap ON d.id = ud_ap.department_id AND ud_ap.role_in_dept = 'Assoc_Pastor' AND ud_ap.is_active = 1
                LEFT JOIN users ap ON ud_ap.user_id = ap.id
                LEFT JOIN user_departments ud_dir ON d.id = ud_dir.department_id AND ud_dir.role_in_dept = 'Director' AND ud_dir.is_active = 1
                LEFT JOIN users dir ON ud_dir.user_id = dir.id
                LEFT JOIN user_departments ud_hod ON d.id = ud_hod.department_id AND ud_hod.role_in_dept = 'HOD' AND ud_hod.is_active = 1
                LEFT JOIN users hod ON ud_hod.user_id = hod.id
                WHERE d.parent_id IS NULL
                ORDER BY d.name ASC
            ");
            $master_departments = $masterStmt->fetchAll(PDO::FETCH_ASSOC);

            // 1. Get ONLY Pastors (Resident or Associate)
            $pastors = $pdo->query("
                SELECT DISTINCT u.id, u.first_name, u.last_name, u.gender 
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                WHERE r.role_name IN ('Resident_Pastor', 'Assoc_Pastor')
                ORDER BY u.first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 2. Get ONLY Directors
            $directors = $pdo->query("
                SELECT DISTINCT u.id, u.first_name, u.last_name, u.gender 
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                WHERE r.role_name = 'Director'
                ORDER BY u.first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 3. Get ALL Workers (For HOD assignments and general roster assignments)
            $all_workers = $pdo->query("
                SELECT id, first_name, last_name, gender 
                FROM users 
                WHERE spiritual_status IN ('Worker', 'Member') 
                ORDER BY first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Get flat list of all departments for Parent selection dropdowns
            $all_depts = $pdo->query("SELECT id, name, target_demographic FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'master_departments' => $master_departments,
                'pastors' => $pastors,
                'directors' => $directors,
                'all_workers' => $all_workers,
                'all_depts' => $all_depts
            ]);
            break;

        // =====================================================================================
        // ACTION 2: CREATE OR UPDATE DEPARTMENT (With Automated RBAC & Transitions)
        // =====================================================================================
        case 'save_department':
            $dept_id = empty($_POST['department_id']) ? null : $_POST['department_id'];
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $type = $_POST['type'] ?? 'Service_Unit';
            $demo = $_POST['target_demographic'] ?? 'All';
            $parent_id = empty($_POST['parent_id']) ? null : $_POST['parent_id'];
            
            // Core Leadership & Season Variables
            $season = $_POST['season'] ?? date('Y'); // Default to current year if not passed
            $assoc_pastor_id = empty($_POST['assoc_pastor_id']) ? null : $_POST['assoc_pastor_id'];
            $director_id = empty($_POST['director_id']) ? null : $_POST['director_id'];
            $hod_id = empty($_POST['hod_id']) ? null : $_POST['hod_id'];

            if (empty($name)) {
                echo json_encode(['status' => 'error', 'message' => 'Department name is required.']);
                exit;
            }

            $notify_new_ap = null;
            $notify_new_dir = null;
            $notify_new_hod = null;

            if ($dept_id) {
                // UPDATE Existing
                $updateStmt = $pdo->prepare("UPDATE departments SET name=?, description=?, type=?, target_demographic=?, parent_id=? WHERE id=?");
                $updateStmt->execute([$name, $desc, $type, $demo, $parent_id, $dept_id]);
                
                // Fetch the current active leaders BEFORE we make changes
                $old_ap = getActiveLeaderId($pdo, $dept_id, 'Assoc_Pastor');
                $old_dir = getActiveLeaderId($pdo, $dept_id, 'Director');
                $old_hod = getActiveLeaderId($pdo, $dept_id, 'HOD');

                // Demote old leaders if a new person is selected, and queue new leaders for notifications
                if ($old_ap != $assoc_pastor_id) {
                    demotePreviousLeader($pdo, $dept_id, $old_ap, 'Assoc_Pastor');
                    $notify_new_ap = $assoc_pastor_id;
                }
                if ($old_dir != $director_id) {
                    demotePreviousLeader($pdo, $dept_id, $old_dir, 'Director');
                    $notify_new_dir = $director_id;
                }
                if ($old_hod != $hod_id) {
                    demotePreviousLeader($pdo, $dept_id, $old_hod, 'HOD');
                    $notify_new_hod = $hod_id;
                }

                $msg = "Department updated and transitions applied.";
            } else {
                // CREATE New
                $insertStmt = $pdo->prepare("INSERT INTO departments (name, description, type, target_demographic, parent_id) VALUES (?, ?, ?, ?, ?)");
                $insertStmt->execute([$name, $desc, $type, $demo, $parent_id]);
                $dept_id = $pdo->lastInsertId();
                
                $notify_new_ap = $assoc_pastor_id;
                $notify_new_dir = $director_id;
                $notify_new_hod = $hod_id;
                
                $msg = "Department created successfully.";
            }

            // AUTO-RBAC: Inject new/current leaders into the pivot table using the Season
            if ($assoc_pastor_id) assignLeaderToRoster($pdo, $dept_id, $assoc_pastor_id, 'Assoc_Pastor', $season);
            if ($director_id) assignLeaderToRoster($pdo, $dept_id, $director_id, 'Director', $season);
            if ($hod_id) assignLeaderToRoster($pdo, $dept_id, $hod_id, 'HOD', $season);

            // NOTIFICATION TRIGGER: Alert the Newly Appointed Leaders directly
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Leadership Appointment', ?, '/modules/departments/index.php')");
            
            if (!empty($notify_new_ap)) $notifStmt->execute([$notify_new_ap, "You have been appointed as the Associate Pastor overseeing the {$name} department."]);
            if (!empty($notify_new_dir)) $notifStmt->execute([$notify_new_dir, "You have been appointed as the Director of the {$name} department."]);
            if (!empty($notify_new_hod)) $notifStmt->execute([$notify_new_hod, "You have been appointed as the Head of Department (HOD) for {$name}."]);

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // =====================================================================================
        // ACTION 3: VIEW DEPARTMENT DETAILS (Roster, History, Sub-units)
        // =====================================================================================
        case 'fetch_department_details':
            $dept_id = $_POST['department_id'] ?? '';
            if (empty($dept_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Department ID missing.']);
                exit;
            }

            // 1. Get Core Info (Dynamically pulls leader IDs so the frontend edit modal can use them)
            $stmt = $pdo->prepare("
                SELECT d.*, 
                       ap.id as assoc_pastor_id, ap.first_name as ap_fname, ap.last_name as ap_lname,
                       dir.id as director_id, dir.first_name as dir_fname, dir.last_name as dir_lname,
                       hod.id as hod_id, hod.first_name as hod_fname, hod.last_name as hod_lname
                FROM departments d
                LEFT JOIN user_departments ud_ap ON d.id = ud_ap.department_id AND ud_ap.role_in_dept = 'Assoc_Pastor' AND ud_ap.is_active = 1
                LEFT JOIN users ap ON ud_ap.user_id = ap.id
                LEFT JOIN user_departments ud_dir ON d.id = ud_dir.department_id AND ud_dir.role_in_dept = 'Director' AND ud_dir.is_active = 1
                LEFT JOIN users dir ON ud_dir.user_id = dir.id
                LEFT JOIN user_departments ud_hod ON d.id = ud_hod.department_id AND ud_hod.role_in_dept = 'HOD' AND ud_hod.is_active = 1
                LEFT JOIN users hod ON ud_hod.user_id = hod.id
                WHERE d.id = ?
            ");
            $stmt->execute([$dept_id]);
            $dept_info = $stmt->fetch(PDO::FETCH_ASSOC);

            // 2. Get Sub-Units
            $subStmt = $pdo->prepare("SELECT id, name, type, (SELECT COUNT(*) FROM user_departments ud WHERE ud.department_id = departments.id AND ud.is_active = 1) as active_members FROM departments WHERE parent_id = ?");
            $subStmt->execute([$dept_id]);
            $sub_units = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Get Full Roster (Active and Historical)
            $rosterStmt = $pdo->prepare("
                SELECT ud.id as record_id, ud.role_in_dept, ud.season, ud.is_active, ud.joined_at, ud.end_date,
                       u.id as user_id, u.first_name, u.last_name, u.gender, u.phone
                FROM user_departments ud
                JOIN users u ON ud.user_id = u.id
                WHERE ud.department_id = ?
                ORDER BY ud.is_active DESC, FIELD(ud.role_in_dept, 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head', 'Worker'), u.first_name ASC
            ");
            $rosterStmt->execute([$dept_id]);
            $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'info' => $dept_info,
                'sub_units' => $sub_units,
                'roster' => $roster
            ]);
            break;

        // =====================================================================================
        // ACTION 4: ASSIGN WORKER TO DEPARTMENT (With Strict Business Rules)
        // =====================================================================================
        case 'assign_worker':
            $dept_id = $_POST['department_id'] ?? '';
            $worker_id = $_POST['user_id'] ?? '';
            $role = $_POST['role_in_dept'] ?? 'Worker';
            $season = $_POST['season'] ?? date('Y');

            if (empty($dept_id) || empty($worker_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Department and User are required.']);
                exit;
            }

            // BUSINESS RULE 1: Verify Gender Demographics
            $deptStmt = $pdo->prepare("SELECT name, target_demographic FROM departments WHERE id = ?");
            $deptStmt->execute([$dept_id]);
            $deptData = $deptStmt->fetch(PDO::FETCH_ASSOC);

            $userStmt = $pdo->prepare("SELECT gender, first_name, last_name FROM users WHERE id = ?");
            $userStmt->execute([$worker_id]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($deptData['target_demographic'] === 'Male_Only' && $userData['gender'] !== 'Male') {
                echo json_encode(['status' => 'error', 'message' => "Gender Restriction: Only male workers can be assigned to {$deptData['name']}."]);
                exit;
            }
            if ($deptData['target_demographic'] === 'Female_Only' && $userData['gender'] !== 'Female') {
                echo json_encode(['status' => 'error', 'message' => "Gender Restriction: Only female workers can be assigned to {$deptData['name']}."]);
                exit;
            }

            // Check if already active in this exact role
            $activeCheck = $pdo->prepare("SELECT id FROM user_departments WHERE user_id = ? AND department_id = ? AND is_active = 1");
            $activeCheck->execute([$worker_id, $dept_id]);
            if ($activeCheck->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'This user is already actively serving in this department.']);
                exit;
            }

            // Insert new active record
            $pdo->prepare("INSERT INTO user_departments (user_id, department_id, role_in_dept, season, is_active) VALUES (?, ?, ?, ?, 1)")
                ->execute([$worker_id, $dept_id, $role, $season]);

            // Sync global role
            syncGlobalRole($pdo, $worker_id, $role);

            // NOTIFICATION TRIGGER 1: Alert the Worker
            $cleanRole = str_replace('_', ' ', $role);
            $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Department Assignment', ?, '/modules/profile/index.php')")
                ->execute([$worker_id, "You have been successfully assigned to the {$deptData['name']} department as a {$cleanRole}."]);

            // NOTIFICATION TRIGGER 2: Alert the HOD and Director of the unit
            $leaderStmt = $pdo->prepare("SELECT user_id FROM user_departments WHERE department_id = ? AND role_in_dept IN ('HOD', 'Director') AND is_active = 1 AND user_id != ?");
            $leaderStmt->execute([$dept_id, $worker_id]);
            $deptLeaders = $leaderStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($deptLeaders)) {
                $notifLeaderStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Team Member', ?, '/modules/departments/index.php')");
                foreach ($deptLeaders as $leader_id) {
                    $notifLeaderStmt->execute([$leader_id, "{$userData['first_name']} {$userData['last_name']} has been assigned to your department ({$deptData['name']})."]);
                }
            }

            // BUSINESS RULE 2: Burnout Monitor (The "Warm Warning")
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_departments WHERE user_id = ? AND is_active = 1");
            $countStmt->execute([$worker_id]);
            $active_count = $countStmt->fetchColumn();

            if ($active_count > 4) {
                echo json_encode([
                    'status' => 'warning', 
                    'message' => "Worker assigned! However, {$userData['first_name']} is now active in $active_count departments. Watch for burnout."
                ]);
            } else {
                echo json_encode(['status' => 'success', 'message' => "Worker successfully assigned to department."]);
            }
            break;

        // =====================================================================================
        // ACTION 5: REMOVE WORKER (Historical Soft Delete)
        // =====================================================================================
        case 'remove_worker':
            $record_id = $_POST['record_id'] ?? '';
            if (empty($record_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Record ID required.']);
                exit;
            }

            // Fetch user and department info BEFORE removing so we can notify them
            $infoStmt = $pdo->prepare("
                SELECT ud.user_id, d.name as dept_name 
                FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE ud.id = ?
            ");
            $infoStmt->execute([$record_id]);
            $recordInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

            // Soft delete: keep the record, mark inactive, stamp the end date
            $stmt = $pdo->prepare("UPDATE user_departments SET is_active = 0, end_date = CURDATE() WHERE id = ?");
            $stmt->execute([$record_id]);

            // NOTIFICATION TRIGGER: Courteously alert the worker
            if ($recordInfo) {
                $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Department Update', ?, '/modules/profile/index.php')")
                    ->execute([$recordInfo['user_id'], "You have been formally removed/discharged from the active roster of the {$recordInfo['dept_name']} department. Thank you for your service!"]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Worker successfully removed. Historical record preserved.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Department API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred. Please check system logs.']);
}
?>