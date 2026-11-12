<?php
// /api/rol_api.php
//
// River of Life (modules/river_of_life/index.php, assets/ui/js/pages/river-of-life.js).
// Access: the 'river_of_life' row of Roles & permissions. View reads the
// roster and rehearsals; Create assigns a member to a section and schedules a
// rehearsal; Edit takes the roll-call; Delete takes a member off the roster.
// Writes need the CSRF token.

require_once '../includes/db.php';
csrf_require();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

api_require_can('river_of_life', 'view');
$levels = [
    'assign_member' => 'create',
    'schedule_rehearsal' => 'create',
    'mark_attendance' => 'edit',
    'remove_member' => 'delete',
];
if (isset($levels[$action])) {
    api_require_can('river_of_life', $levels[$action]);
}

// The fixed lists the page offers; nothing else is stored.
$rol_categories = ['Vocalist', 'Band_Instrumentalist', 'Technical'];
$rol_marks = ['Present', 'Absent', 'Excused'];

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH DASHBOARD DATA (ROSTER & REHEARSALS)
        // ==========================================
        case 'fetch_dashboard':

            // A. The roster, with what the page shows of each person
            $rosterStmt = $pdo->query("
                SELECT r.id as roster_id, r.role_category, r.section_name,
                       u.id as user_id, u.first_name, u.last_name, u.phone, u.picture_path, u.gender
                FROM river_of_life_roster r
                JOIN users u ON r.user_id = u.id
                ORDER BY r.role_category ASC, r.section_name ASC, u.first_name ASC
            ");
            $raw_roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

            // Grouped by category, then by section
            $grouped_roster = array_fill_keys($rol_categories, []);
            foreach ($raw_roster as $member) {
                $category = $member['role_category'];
                $section = $member['section_name'];
                if (!isset($grouped_roster[$category])) {
                    continue;
                }
                $grouped_roster[$category][$section][] = $member;
            }

            // B. Rehearsals (upcoming & recent)
            $rehearsalsStmt = $pdo->query("
                SELECT r.id, r.rehearsal_date, r.focus_topic, r.status,
                       (SELECT COUNT(*) FROM rol_attendance a WHERE a.rehearsal_id = r.id AND a.status = 'Present') as present_count,
                       (SELECT COUNT(*) FROM rol_attendance a WHERE a.rehearsal_id = r.id) as total_expected
                FROM rol_rehearsals r
                ORDER BY r.rehearsal_date DESC
                LIMIT 15
            ");
            $rehearsals = $rehearsalsStmt->fetchAll(PDO::FETCH_ASSOC);

            // C. Who can be added: on the River of Life team, not yet in a section
            $team = array_map('intval', perm_module_team($pdo, 'river_of_life'));
            $availableStmt = $pdo->query("
                SELECT u.id, u.first_name, u.last_name, u.gender
                FROM users u
                WHERE u.id IN (" . ($team ? implode(',', $team) : '0') . ")
                AND u.id NOT IN (SELECT user_id FROM river_of_life_roster)
                ORDER BY u.first_name ASC
            ");
            $available_members = $availableStmt->fetchAll(PDO::FETCH_ASSOC);

            // D. Quick stats
            $counts = array_count_values(array_column($raw_roster, 'role_category'));
            $stats = [
                'total_members' => count($raw_roster),
                'vocalists' => $counts['Vocalist'] ?? 0,
                'band' => $counts['Band_Instrumentalist'] ?? 0,
                'tech' => $counts['Technical'] ?? 0,
            ];

            echo json_encode([
                'status' => 'success',
                'grouped_roster' => $grouped_roster,
                'rehearsals' => $rehearsals,
                'available_members' => $available_members,
                'stats' => $stats
            ]);
            break;

        // ==========================================
        // ACTION 2: ASSIGN MEMBER TO A SECTION
        // ==========================================
        case 'assign_member':
            $target_user_id = (int) ($_POST['user_id'] ?? 0);
            $role_category = $_POST['role_category'] ?? '';
            $section_name = trim($_POST['section_name'] ?? '');

            if ($target_user_id <= 0 || $section_name === '') {
                echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
                exit;
            }
            if (!in_array($role_category, $rol_categories, true)) {
                echo json_encode(['status' => 'error', 'field' => 'role_category', 'message' => 'Choose a role.']);
                exit;
            }
            if (mb_strlen($section_name) > 50) {
                echo json_encode(['status' => 'error', 'field' => 'section_name', 'message' => 'Keep the section to 50 characters.']);
                exit;
            }
            // Only someone on the River of Life team (the page lists exactly these).
            if (!in_array($target_user_id, array_map('intval', perm_module_team($pdo, 'river_of_life')), true)) {
                echo json_encode(['status' => 'error', 'field' => 'user_id', 'message' => 'Choose someone on the River of Life team.']);
                exit;
            }

            $checkStmt = $pdo->prepare("SELECT id FROM river_of_life_roster WHERE user_id = ?");
            $checkStmt->execute([$target_user_id]);
            if ($checkStmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Member is already assigned to a section.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO river_of_life_roster (user_id, role_category, section_name) VALUES (?, ?, ?)");
            $stmt->execute([$target_user_id, $role_category, $section_name]);

            // Tell the person
            $cleanRole = str_replace('_', ' ', $role_category);
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'River of Life Roster', ?, '/modules/profile/')");
            $notifMsg = "You have been officially assigned to the River of Life team as a {$cleanRole} ({$section_name}).";
            $notifStmt->execute([$target_user_id, $notifMsg]);

            echo json_encode(['status' => 'success', 'message' => 'Member successfully assigned to ' . $section_name . '.']);
            break;

        // ==========================================
        // ACTION 3: REMOVE MEMBER FROM ROSTER
        // ==========================================
        case 'remove_member':
            $roster_id = (int) ($_POST['roster_id'] ?? 0);
            if ($roster_id > 0) {
                $stmt = $pdo->prepare("DELETE FROM river_of_life_roster WHERE id = ?");
                $stmt->execute([$roster_id]);
                echo json_encode(['status' => 'success', 'message' => 'Member removed from specific section.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid ID.']);
            }
            break;

        // ==========================================
        // ACTION 4: SCHEDULE A REHEARSAL
        // ==========================================
        case 'schedule_rehearsal':
            $raw_date = (string) ($_POST['rehearsal_date'] ?? '');
            $when = DateTime::createFromFormat('!Y-m-d\TH:i', $raw_date) ?: DateTime::createFromFormat('!Y-m-d H:i:s', $raw_date);
            $rehearsal_date = $when ? $when->format('Y-m-d H:i:s') : '';
            $focus_topic = trim($_POST['focus_topic'] ?? '');

            if ($rehearsal_date === '' || $focus_topic === '') {
                echo json_encode(['status' => 'error', 'message' => 'Date, time, and focus topic are required.']);
                exit;
            }
            if (mb_strlen($focus_topic) > 255) {
                echo json_encode(['status' => 'error', 'field' => 'focus_topic', 'message' => 'Keep the focus to 255 characters.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. The rehearsal
                $stmt = $pdo->prepare("INSERT INTO rol_rehearsals (rehearsal_date, focus_topic) VALUES (?, ?)");
                $stmt->execute([$rehearsal_date, $focus_topic]);
                $rehearsal_id = (int) $pdo->lastInsertId();

                // 2. A roll-call sheet with everyone on the roster (Absent until marked)
                $pdo->prepare("
                    INSERT INTO rol_attendance (rehearsal_id, user_id, status)
                    SELECT ?, user_id, 'Absent' FROM river_of_life_roster
                ")->execute([$rehearsal_id]);

                // Tell the roster
                $rol_members = $pdo->query("SELECT user_id FROM river_of_life_roster")->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($rol_members)) {
                    $nice_date = $when->format('l, M jS @ g:i A');
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Rehearsal Scheduled', ?, '/modules/river_of_life/')");
                    $msg = "A new rehearsal has been scheduled for {$nice_date}. Focus: {$focus_topic}. Please be punctual.";
                    foreach ($rol_members as $uid) {
                        if ($uid != $user_id) {
                            $notifStmt->execute([$uid, $msg]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Rehearsal scheduled and attendance roster generated.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==========================================
        // ACTION 5: ONE REHEARSAL'S ROLL-CALL
        // ==========================================
        case 'fetch_attendance':
            $rehearsal_id = (int) ($_POST['rehearsal_id'] ?? $_GET['rehearsal_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT a.status, a.user_id, u.first_name, u.last_name, u.picture_path, r.role_category, r.section_name
                FROM rol_attendance a
                JOIN users u ON a.user_id = u.id
                JOIN river_of_life_roster r ON u.id = r.user_id
                WHERE a.rehearsal_id = ?
                ORDER BY r.role_category ASC, r.section_name ASC, u.first_name ASC
            ");
            $stmt->execute([$rehearsal_id]);

            echo json_encode(['status' => 'success', 'attendance' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION 6: MARK ONE PERSON
        // ==========================================
        case 'mark_attendance':
            $rehearsal_id = (int) ($_POST['rehearsal_id'] ?? 0);
            $target_user_id = (int) ($_POST['user_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            if (!in_array($status, $rol_marks, true)) {
                echo json_encode(['status' => 'error', 'message' => 'Unknown mark.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE rol_attendance SET status = ? WHERE rehearsal_id = ? AND user_id = ?");
            $stmt->execute([$status, $rehearsal_id, $target_user_id]);

            echo json_encode(['status' => 'success']);
            break;

        // upload_audio_key (a setlist with a recorded starting pitch) was removed in
        // Nov 2026: nothing on the page could open it, song_id was always 1, and it
        // wrote to ../../uploads/rol_keys/, outside the site, under View access.

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("River of Life API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
