<?php
// /api/zoe_api.php
//
// Zoe, the intercessory unit (modules/zoe/index.php, assets/ui/js/pages/zoe.js).
// Access: the 'zoe' row of Roles & permissions, plus Zoe membership.
//   - Anyone who may open Zoe submits a prayer request (Create).
//   - Zoe members (the active Zoe roster, or the team of the department that
//     owns Zoe) read the sessions, the War Room, the requests and the roster,
//     and post to the War Room.
//   - Zoe / Manage runs it: campaigns, sessions and their bulletins, topics,
//     roll-call, request status and the volunteer roster.
// Writes need the CSRF token.

require_once '../includes/db.php';
csrf_require();
header('Content-Type: application/json');

api_require_can('zoe');

$user_id = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$is_zoe_admin = can('zoe', 'manage');

// A Zoe member: on the active roster, in the department that owns Zoe, or running it.
$rosterCheck = $pdo->prepare("SELECT id FROM zoe_roster WHERE user_id = ? AND is_active = 1");
$rosterCheck->execute([$user_id]);
$is_zoe_member = $is_zoe_admin || (bool) $rosterCheck->fetch()
    || in_array($user_id, array_map('intval', perm_module_team($pdo, 'zoe')), true);

// Everyone else may only send a prayer request.
if (!$is_zoe_member && $action !== 'submit_prayer_request') {
    echo json_encode(['status' => 'error', 'code' => 'NOT_MEMBER', 'message' => 'Access Denied. You are not an active member of the Zoe Intercessory unit.']);
    exit;
}

$admin_actions = ['save_campaign', 'save_session', 'update_session_status', 'save_topic', 'delete_topic',
    'mark_attendance', 'update_request_status', 'add_to_roster', 'remove_from_roster'];
if (in_array($action, $admin_actions, true) && !$is_zoe_admin) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

// The fixed lists the page offers; nothing else is stored.
$post_types = ['General_Note', 'Scripture', 'Vision', 'Word_of_Knowledge'];
$request_statuses = ['Pending', 'Active_Prayer', 'Answered_Testimony'];
$session_statuses = ['Draft', 'Published', 'Completed'];
$roster_types = ['Volunteer', 'Official_Worker'];
$marks = ['Present', 'Absent', 'Excused'];

/** A Y-m-d date, or '' when it does not parse. */
function zoe_date(string $raw): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $raw);
    return $d && $d->format('Y-m-d') === $raw ? $raw : '';
}

/** An H:i (or H:i:s) time, or '' when it does not parse. */
function zoe_time(string $raw): string
{
    $t = DateTime::createFromFormat('!H:i', $raw) ?: DateTime::createFromFormat('!H:i:s', $raw);
    return $t ? $t->format('H:i:s') : '';
}

/** A stored bulletin path as a link the page may open: our own uploads folder only. */
function zoe_bulletin_href(?string $stored): ?string
{
    if ($stored === null || !preg_match('#^(?:\.\./)*/?uploads/zoe/([A-Za-z0-9._-]+)$#', $stored, $m) || str_contains($m[1], '..')) {
        return null;
    }
    return '/uploads/zoe/' . $m[1];
}

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH DASHBOARD (War Room, Sessions, Requests, Roster)
        // =====================================================================================
        case 'fetch_dashboard':
            $campaigns = $pdo->query("SELECT id, title, theme_description, start_date, end_date, status FROM zoe_campaigns ORDER BY start_date DESC LIMIT 10")->fetchAll();

            $sessions = $pdo->query("
                SELECT s.id, s.campaign_id, s.title, s.session_date, s.start_time, s.end_time, s.meeting_type, s.status,
                       c.title as campaign_title,
                       (SELECT COUNT(*) FROM zoe_topics t WHERE t.session_id = s.id) as topic_count
                FROM zoe_sessions s
                LEFT JOIN zoe_campaigns c ON s.campaign_id = c.id
                WHERE s.session_date >= CURDATE() OR s.status = 'Draft'
                ORDER BY s.session_date ASC, s.start_time ASC
            ")->fetchAll();

            $war_room = $pdo->query("
                SELECT w.id, w.post_type, w.message, w.posted_at, u.first_name, u.last_name
                FROM zoe_war_room w
                JOIN users u ON w.user_id = u.id
                ORDER BY w.posted_at DESC LIMIT 50
            ")->fetchAll();

            $requests = $pdo->query("
                SELECT r.id, r.request_text, r.status, r.submitted_at, u.first_name, u.last_name, u.phone
                FROM zoe_prayer_requests r
                JOIN users u ON r.submitted_by = u.id
                ORDER BY r.submitted_at DESC LIMIT 100
            ")->fetchAll();

            // Active roster: volunteers and workers on zoe_roster, plus the team of the
            // department that owns Zoe (Roles & permissions → Seats).
            $zoeDeptIn = implode(', ', array_map('intval', array_filter(perm_module_owners($pdo)['zoe'] ?? [], static fn ($d) => $d > 0))) ?: 'NULL';
            $roster = $pdo->query("
                SELECT MAX(roster_id) as id, user_id, MAX(roster_type) as roster_type, first_name, last_name
                FROM (
                    SELECT zr.id as roster_id, zr.user_id, zr.roster_type, u.first_name, u.last_name
                    FROM zoe_roster zr
                    JOIN users u ON zr.user_id = u.id
                    WHERE zr.is_active = 1

                    UNION ALL

                    SELECT NULL as roster_id, ud.user_id, ud.role_in_dept as roster_type, u.first_name, u.last_name
                    FROM user_departments ud
                    JOIN users u ON ud.user_id = u.id
                    WHERE ud.department_id IN (" . $zoeDeptIn . ") AND ud.is_active = 1
                ) as combined_roster
                GROUP BY user_id, first_name, last_name
                ORDER BY roster_type ASC, first_name ASC
            ")->fetchAll();

            // Who could be added as a volunteer (runners only).
            $candidates = [];
            if ($is_zoe_admin) {
                $candidates = $pdo->query("
                    SELECT u.id, u.first_name, u.last_name FROM users u
                    WHERE u.id NOT IN (SELECT user_id FROM zoe_roster WHERE is_active = 1)
                    ORDER BY u.first_name, u.last_name LIMIT 5000
                ")->fetchAll();
            }

            $analytics = $pdo->query("
                SELECT
                    (SELECT COUNT(*) FROM zoe_prayer_requests WHERE status = 'Pending') as pending_requests,
                    (SELECT COUNT(*) FROM zoe_prayer_requests WHERE status = 'Answered_Testimony') as total_testimonies,
                    (SELECT COUNT(*) FROM zoe_sessions WHERE status = 'Completed') as completed_sessions
            ")->fetch();

            echo json_encode([
                'status' => 'success',
                'is_admin' => $is_zoe_admin,
                'campaigns' => $campaigns,
                'sessions' => $sessions,
                'war_room' => $war_room,
                'requests' => $requests,
                'roster' => $roster,
                'candidates' => $candidates,
                'analytics' => $analytics
            ]);
            break;

        // =====================================================================================
        // ACTION 2: CAMPAIGNS
        // =====================================================================================
        case 'save_campaign':
            $id = (int) ($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $theme = trim($_POST['theme_description'] ?? '');
            $start = zoe_date((string) ($_POST['start_date'] ?? ''));
            $end = zoe_date((string) ($_POST['end_date'] ?? ''));

            if ($title === '' || $start === '' || $end === '') exit(json_encode(['status' => 'error', 'message' => 'Title and dates are required.']));
            if (mb_strlen($title) > 150) exit(json_encode(['status' => 'error', 'field' => 'title', 'message' => 'Keep the title to 150 characters.']));
            if ($end < $start) exit(json_encode(['status' => 'error', 'field' => 'end_date', 'message' => 'The end is before the start.']));

            if ($id) {
                $pdo->prepare("UPDATE zoe_campaigns SET title=?, theme_description=?, start_date=?, end_date=? WHERE id=?")->execute([$title, $theme, $start, $end, $id]);
            } else {
                $pdo->prepare("INSERT INTO zoe_campaigns (title, theme_description, start_date, end_date) VALUES (?, ?, ?, ?)")->execute([$title, $theme, $start, $end]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Prayer campaign saved successfully.']);
            break;

        // =====================================================================================
        // ACTION 3: SESSIONS (and the PDF bulletin)
        // =====================================================================================
        case 'save_session':
            $id = (int) ($_POST['id'] ?? 0);
            $campaign_id = (int) ($_POST['campaign_id'] ?? 0) ?: null;
            $title = trim($_POST['title'] ?? '');
            $date = zoe_date((string) ($_POST['session_date'] ?? ''));
            $start_time = zoe_time((string) ($_POST['start_time'] ?? ''));
            $end_time = zoe_time((string) ($_POST['end_time'] ?? ''));
            $type = ($_POST['meeting_type'] ?? '') === 'Virtual' ? 'Virtual' : 'In-Person';
            $location = trim($_POST['location_or_link'] ?? '');

            if ($title === '' || $date === '' || $start_time === '' || $end_time === '' || $location === '') {
                exit(json_encode(['status' => 'error', 'message' => 'Please fill all required session details.']));
            }
            if (mb_strlen($title) > 150 || mb_strlen($location) > 255) {
                exit(json_encode(['status' => 'error', 'message' => 'The title or the place is too long.']));
            }
            if ($type === 'Virtual') {
                if (!preg_match('#^https?://#i', $location)) {
                    $location = 'https://' . $location;   // as the old page did when the scheme was left out
                }
                if (!preg_match('#^https?://[^\s<>"\']+$#i', $location)) {
                    exit(json_encode(['status' => 'error', 'field' => 'location_or_link', 'message' => 'A virtual session needs a web link.']));
                }
            }

            // The bulletin: a PDF, stored under a random name (includes/upload_guard.php).
            // An update with no new file keeps the one already stored.
            $pdf_url = null;
            if ($id) {
                $cur = $pdo->prepare("SELECT bulletin_pdf_url FROM zoe_sessions WHERE id = ?");
                $cur->execute([$id]);
                $pdf_url = $cur->fetchColumn() ?: null;
            }
            if (isset($_FILES['bulletin_pdf']) && $_FILES['bulletin_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
                require_once __DIR__ . '/../includes/upload_guard.php';
                $stored = hod_upload_store($_FILES['bulletin_pdf'], __DIR__ . '/../uploads/zoe/', '../../uploads/zoe/', ['pdf']);
                if (!$stored['ok']) {
                    exit(json_encode(['status' => 'error', 'field' => 'bulletin_pdf', 'message' => $stored['error']]));
                }
                $pdf_url = $stored['url'];
            }

            if ($id) {
                $pdo->prepare("UPDATE zoe_sessions SET campaign_id=?, title=?, session_date=?, start_time=?, end_time=?, meeting_type=?, location_or_link=?, bulletin_pdf_url=? WHERE id=?")
                    ->execute([$campaign_id, $title, $date, $start_time, $end_time, $type, $location, $pdf_url, $id]);
            } else {
                $pdo->prepare("INSERT INTO zoe_sessions (campaign_id, title, session_date, start_time, end_time, meeting_type, location_or_link, bulletin_pdf_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$campaign_id, $title, $date, $start_time, $end_time, $type, $location, $pdf_url]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Prayer session scheduled.']);
            break;

        case 'update_session_status':
            $status = $_POST['status'] ?? '';
            if (!in_array($status, $session_statuses, true)) exit(json_encode(['status' => 'error', 'message' => 'Unknown status.']));
            $pdo->prepare("UPDATE zoe_sessions SET status = ? WHERE id = ?")->execute([$status, (int) ($_POST['session_id'] ?? 0)]);
            echo json_encode(['status' => 'success', 'message' => 'Session status updated.']);
            break;

        // =====================================================================================
        // ACTION 4: ONE SESSION (topics and the roll-call sheet)
        // =====================================================================================
        case 'fetch_session_details':
            $session_id = (int) ($_POST['session_id'] ?? $_GET['session_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT s.*, c.title as campaign_title FROM zoe_sessions s LEFT JOIN zoe_campaigns c ON s.campaign_id = c.id WHERE s.id = ?");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();
            if (!$session) exit(json_encode(['status' => 'error', 'message' => 'That session no longer exists.']));
            // Only our own uploads are linked; a virtual session's link must be https.
            $session['bulletin_href'] = zoe_bulletin_href($session['bulletin_pdf_url'] ?? null);
            $session['link_href'] = preg_match('#^https?://[^\s<>"\']+$#i', (string) $session['location_or_link']) ? $session['location_or_link'] : null;
            unset($session['bulletin_pdf_url']);

            $tStmt = $pdo->prepare("
                SELECT t.id, t.topic_title, t.prayer_points, t.bible_reference, t.allocated_time, t.assigned_user_id, u.first_name, u.last_name
                FROM zoe_topics t
                LEFT JOIN users u ON t.assigned_user_id = u.id
                WHERE t.session_id = ? ORDER BY t.id ASC
            ");
            $tStmt->execute([$session_id]);
            $topics = $tStmt->fetchAll();

            $attStmt = $pdo->prepare("
                SELECT zr.user_id, u.first_name, u.last_name,
                       COALESCE(za.attendance_status, 'Absent') as current_status
                FROM zoe_roster zr
                JOIN users u ON zr.user_id = u.id
                LEFT JOIN zoe_attendance za ON za.user_id = zr.user_id AND za.session_id = ?
                WHERE zr.is_active = 1
                ORDER BY u.first_name ASC
            ");
            $attStmt->execute([$session_id]);
            $attendance_list = $attStmt->fetchAll();

            echo json_encode([
                'status' => 'success',
                'session' => $session,
                'topics' => $topics,
                'attendance_list' => $attendance_list
            ]);
            break;

        // =====================================================================================
        // ACTION 5: TOPICS & SHIFT ASSIGNMENTS
        // =====================================================================================
        case 'save_topic':
            $session_id = (int) ($_POST['session_id'] ?? 0);
            $title = trim($_POST['topic_title'] ?? '');
            $points = trim($_POST['prayer_points'] ?? '');
            $bible = mb_substr(trim($_POST['bible_reference'] ?? ''), 0, 255);
            $time = mb_substr(trim($_POST['allocated_time'] ?? ''), 0, 50);
            $assigned = (int) ($_POST['assigned_user_id'] ?? 0) ?: null;

            if ($session_id <= 0 || $title === '' || $points === '') exit(json_encode(['status' => 'error', 'message' => 'Title and prayer points are required.']));
            if (mb_strlen($title) > 255) exit(json_encode(['status' => 'error', 'field' => 'topic_title', 'message' => 'Keep the title to 255 characters.']));

            $pdo->prepare("INSERT INTO zoe_topics (session_id, topic_title, prayer_points, bible_reference, allocated_time, assigned_user_id) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$session_id, $title, $points, $bible, $time, $assigned]);

            echo json_encode(['status' => 'success', 'message' => 'Prayer topic and assignment locked in.']);
            break;

        case 'delete_topic':
            $pdo->prepare("DELETE FROM zoe_topics WHERE id = ?")->execute([(int) ($_POST['topic_id'] ?? 0)]);
            echo json_encode(['status' => 'success', 'message' => 'Topic removed.']);
            break;

        // =====================================================================================
        // ACTION 6: ROLL-CALL
        // =====================================================================================
        case 'mark_attendance':
            $session_id = (int) ($_POST['session_id'] ?? 0);
            $attendance_data = json_decode((string) ($_POST['attendance_data'] ?? ''), true); // {user_id: 'Present', ...}

            if ($session_id <= 0 || !is_array($attendance_data) || !$attendance_data) exit(json_encode(['status' => 'error', 'message' => 'No data provided.']));

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM zoe_attendance WHERE session_id = ?")->execute([$session_id]);
            $stmt = $pdo->prepare("INSERT INTO zoe_attendance (session_id, user_id, attendance_status, marked_by) VALUES (?, ?, ?, ?)");
            foreach ($attendance_data as $uid => $status) {
                if ((int) $uid > 0 && in_array($status, $marks, true)) {
                    $stmt->execute([$session_id, (int) $uid, $status, $user_id]);
                }
            }
            $pdo->commit();

            echo json_encode(['status' => 'success', 'message' => 'Session attendance roll call saved.']);
            break;

        // =====================================================================================
        // ACTION 7: WAR ROOM
        // =====================================================================================
        case 'post_war_room':
            $type = $_POST['post_type'] ?? 'General_Note';
            $msg = trim($_POST['message'] ?? '');

            if ($msg === '') exit(json_encode(['status' => 'error', 'field' => 'message', 'message' => 'Message cannot be empty.']));
            if (!in_array($type, $post_types, true)) exit(json_encode(['status' => 'error', 'field' => 'post_type', 'message' => 'Choose what it is.']));
            if (mb_strlen($msg) > 5000) exit(json_encode(['status' => 'error', 'field' => 'message', 'message' => 'Keep it to 5,000 characters.']));

            $pdo->prepare("INSERT INTO zoe_war_room (user_id, post_type, message) VALUES (?, ?, ?)")->execute([$user_id, $type, $msg]);
            echo json_encode(['status' => 'success', 'message' => 'Posted to the War Room securely.']);
            break;

        // =====================================================================================
        // ACTION 8: CHURCH-WIDE PRAYER REQUESTS
        // =====================================================================================
        case 'submit_prayer_request':
            api_require_can('zoe', 'create');
            $msg = trim($_POST['request_text'] ?? '');
            if ($msg === '') exit(json_encode(['status' => 'error', 'field' => 'request_text', 'message' => 'Request cannot be empty.']));
            if (mb_strlen($msg) > 5000) exit(json_encode(['status' => 'error', 'field' => 'request_text', 'message' => 'Keep it to 5,000 characters.']));

            $pdo->prepare("INSERT INTO zoe_prayer_requests (submitted_by, request_text) VALUES (?, ?)")->execute([$user_id, $msg]);
            echo json_encode(['status' => 'success', 'message' => 'Your prayer request has been securely submitted to the Zoe Intercessory team.']);
            break;

        case 'update_request_status':
            $req_id = (int) ($_POST['request_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (!in_array($status, $request_statuses, true)) exit(json_encode(['status' => 'error', 'message' => 'Unknown status.']));

            $pdo->prepare("UPDATE zoe_prayer_requests SET status = ? WHERE id = ?")->execute([$status, $req_id]);
            $msg = $status === 'Answered_Testimony' ? 'Hallelujah! Marked as a Testimony.' : 'Status updated.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // =====================================================================================
        // ACTION 9: VOLUNTEER ROSTER
        // =====================================================================================
        case 'add_to_roster':
            $target_user_id = (int) ($_POST['user_id'] ?? 0);
            $type = $_POST['roster_type'] ?? 'Volunteer';

            if ($target_user_id <= 0) exit(json_encode(['status' => 'error', 'field' => 'user_id', 'message' => 'User selection required.']));
            if (!in_array($type, $roster_types, true)) exit(json_encode(['status' => 'error', 'field' => 'roster_type', 'message' => 'Choose a role type.']));

            $chk = $pdo->prepare("SELECT id FROM zoe_roster WHERE user_id = ? AND is_active = 1");
            $chk->execute([$target_user_id]);
            if ($chk->fetch()) exit(json_encode(['status' => 'error', 'message' => 'User is already an active Zoe member.']));

            $pdo->prepare("INSERT INTO zoe_roster (user_id, roster_type) VALUES (?, ?)")->execute([$target_user_id, $type]);
            echo json_encode(['status' => 'success', 'message' => 'Member successfully added to the Zoe roster.']);
            break;

        case 'remove_from_roster':
            $pdo->prepare("UPDATE zoe_roster SET is_active = 0 WHERE id = ?")->execute([(int) ($_POST['roster_id'] ?? 0)]);
            echo json_encode(['status' => 'success', 'message' => 'Member deactivated from the Zoe roster.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Zoe API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred. Consult the logs.']);
}
