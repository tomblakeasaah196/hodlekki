<?php
// /api/embrace_api.php
require_once '../includes/db.php';
require_once __DIR__ . '/../includes/embrace_helpers.php';
header('Content-Type: application/json');

// 1. Security Check & Session Start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * Helper: Process Image Uploads
 */
function processProfileImage($file) {
    $target_dir = "../../uploads/profiles/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $filename = uniqid('ft_', true) . '.' . $ext;
    $target_path = $target_dir . $filename;

    if($file["size"] > 2000000) return ['error' => 'Image too large. Max 2MB.'];
    if(!in_array($ext, ['jpg', 'jpeg', 'png'])) return ['error' => 'Only JPG, JPEG & PNG allowed.'];

    if (move_uploaded_file($file["tmp_name"], $target_path)) {
        return ['success' => '/uploads/profiles/' . $filename];
    }
    return ['error' => 'Upload failed.'];
}

/**
 * Helper: Auto-check-in a brand new first timer for TODAY's open Sunday
 * Service / Midweek Service. Deterministic + safe: only ever attaches to a
 * service that is (a) the right category, (b) actually happening today, and
 * (c) still open (is_closed = 0) — i.e. exactly the service the Embrace desk
 * is standing in right now. Never touches event_registrations. Never throws
 * — a failure here must not block the first-timer's own registration.
 * Returns the number of services the person was marked present for.
 */
function embrace_auto_checkin_today(PDO $pdo, int $visitorUserId, int $staffUserId): int {
    if ($visitorUserId <= 0) return 0;
    try {
        // Widened SQL filter (DATE(event_date) <= today, still open); the exact
        // event_date..end_date window is then checked in PHP the same way
        // api/checkin_api.php already does it, so an empty-string end_date
        // never trips up a raw SQL date comparison.
        $stmt = $pdo->prepare("
            SELECT id, event_date, end_date FROM events
            WHERE event_category IN ('Sunday_Service', 'Midweek_Service')
              AND is_closed = 0
              AND DATE(event_date) <= CURDATE()
        ");
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$candidates) return 0;

        $today = date('Y-m-d');
        $eventIds = [];
        foreach ($candidates as $c) {
            $startDate = date('Y-m-d', strtotime($c['event_date']));
            $endDate = !empty($c['end_date']) ? $c['end_date'] : $startDate;
            if ($today >= $startDate && $today <= $endDate) $eventIds[] = (int)$c['id'];
        }
        if (!$eventIds) return 0;

        $marked = 0;
        foreach ($eventIds as $eid) {
            $dup = $pdo->prepare("SELECT id FROM attendance WHERE event_id = ? AND user_id = ?");
            $dup->execute([$eid, $visitorUserId]);
            if ($dup->fetch()) continue;

            $pdo->prepare("
                INSERT INTO attendance (event_id, user_id, status, check_in_time, checked_in_by, attendance_date)
                VALUES (?, ?, 'Present', NOW(), ?, CURDATE())
            ")->execute([$eid, $visitorUserId, $staffUserId]);
            $marked++;
        }
        return $marked;
    } catch (\Throwable $e) {
        error_log('embrace_auto_checkin_today failed: ' . $e->getMessage());
        return 0;
    }
}

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: REGISTER NEW FIRST TIMER
        // =====================================================================================
        case 'add_visitor':
            $fname = trim($_POST['first_name'] ?? '');
            $lname = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            
            if (empty($fname) || empty($lname) || empty($phone)) {
                echo json_encode(['status' => 'error', 'message' => 'First Name, Last Name, and Phone are required.']);
                exit;
            }

            $pic_path = null;
            if (!empty($_FILES['profile_pic']['name'])) {
                $upload = processProfileImage($_FILES['profile_pic']);
                if (isset($upload['error'])) {
                    echo json_encode(['status' => 'error', 'message' => $upload['error']]);
                    exit;
                }
                $pic_path = $upload['success'];
            }

            $qr_hash = hash('sha256', bin2hex(random_bytes(16)) . $phone);

            $email = !empty(trim($_POST['email'] ?? '')) ? strtolower(trim($_POST['email'])) : null;
            if ($email && str_ends_with($email, '@hodlc.com')) {
                echo json_encode(['status' => 'error', 'message' => 'Please use a personal email address.']);
                exit;
            }

            $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            $visitation_preference = $_POST['visitation_preference'] ?? 'None';
            $invitation_source = $_POST['invitation_source'] ?? 'Self_Discovery';
            
            $sql = "INSERT INTO users (
                        first_name, last_name, email, phone, gender, dob, marital_status, 
                        wedding_anniversary, physical_address, latitude, longitude, spiritual_status, 
                        picture_path, comments, invitation_source, invited_by, is_born_again, 
                        wants_to_join, visitation_preference, prayer_requests, qr_code_hash
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '1st_Timer', ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $fname, 
                $lname, 
                $email, 
                $phone, 
                $_POST['gender'] ?? null, 
                !empty($_POST['dob']) ? $_POST['dob'] : null,
                $_POST['marital_status'] ?? 'Single',
                ($_POST['marital_status'] === 'Married' && !empty($_POST['wedding_anniversary'])) ? $_POST['wedding_anniversary'] : null,
                trim($_POST['physical_address'] ?? 'To be updated'),
                $latitude, 
                $longitude, 
                $pic_path,
                trim($_POST['comments'] ?? ''),
                $invitation_source, 
                trim($_POST['invited_by'] ?? ''),
                isset($_POST['is_born_again']) ? 1 : 0,
                isset($_POST['wants_to_join']) ? 1 : 0,
                $visitation_preference,
                trim($_POST['prayer_requests'] ?? ''),
                $qr_hash 
            ]);

            // Reach: if this first timer was met through Reach, mark that lead "Visited Church".
            require_once __DIR__ . '/../includes/reach_helpers.php';
            reach_mark_visited_church($pdo, $phone, (int) $pdo->lastInsertId());

            // ATTENDANCE AUTO-CHECK-IN: a first timer captured on Embrace was
            // obviously physically in the building — mark them Present for
            // whichever Sunday/Midweek service is happening right now, so
            // they show up immediately in the Events & Attendance module and
            // the Analytics tab without anyone having to check them in twice.
            $newVisitorId = (int) $pdo->lastInsertId();
            $auto_checked_in_events = embrace_auto_checkin_today($pdo, $newVisitorId, $user_id);

            // NOTIFICATION TRIGGER
            $embStmt = $pdo->query("SELECT ud.user_id FROM user_departments ud JOIN departments d ON ud.department_id = d.id WHERE d.name LIKE '%Embrace%' AND ud.role_in_dept IN ('Director', 'HOD') AND ud.is_active = 1");
            $embrace_leaders = $embStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($embrace_leaders)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New First Timer Registered', ?, '/modules/embrace/index.php')");
                $alertMessage = "{$fname} {$lname} has just been registered as a First Timer and is waiting in the queue to be assigned.";
                foreach($embrace_leaders as $uid) {
                    $notifStmt->execute([$uid, $alertMessage]);
                }
            }

            $successMsg = 'First Timer registered and added to follow-up queue.';
            if ($auto_checked_in_events > 0) {
                $successMsg .= " They've also been marked Present for today's service.";
            }
            echo json_encode(['status' => 'success', 'message' => $successMsg, 'auto_checked_in' => $auto_checked_in_events > 0]);
            break;
            
        // =====================================================================================
        // ACTION 1.5: UPDATE EXISTING FIRST TIMER RECORD
        // =====================================================================================
        case 'update_visitor':
            $visitor_id = filter_var($_POST['visitor_id'] ?? '', FILTER_VALIDATE_INT);
            $fname = trim($_POST['first_name'] ?? '');
            $lname = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            
            if (!$visitor_id || empty($fname) || empty($lname) || empty($phone)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing essential record details.']);
                exit;
            }

            $email = !empty(trim($_POST['email'] ?? '')) ? strtolower(trim($_POST['email'])) : null;
            $visitation_preference = $_POST['visitation_preference'] ?? 'None';
            $invitation_source = $_POST['invitation_source'] ?? 'Self_Discovery';

            $params = [
                $fname, $lname, $email, $phone, $_POST['gender'] ?? null, $_POST['marital_status'] ?? 'Single', 
                ($_POST['marital_status'] === 'Married' && !empty($_POST['wedding_anniversary'])) ? $_POST['wedding_anniversary'] : null, 
                trim($_POST['physical_address'] ?? ''), 
                !empty($_POST['latitude']) ? $_POST['latitude'] : null, 
                !empty($_POST['longitude']) ? $_POST['longitude'] : null, 
                $invitation_source,
                trim($_POST['invited_by'] ?? ''), 
                isset($_POST['is_born_again']) ? 1 : 0, 
                isset($_POST['wants_to_join']) ? 1 : 0, 
                $visitation_preference, 
                trim($_POST['prayer_requests'] ?? '')
            ];
            
            $pic_update_sql = "";
            if (!empty($_FILES['profile_pic']['name'])) {
                $upload = processProfileImage($_FILES['profile_pic']);
                if (isset($upload['error'])) {
                    echo json_encode(['status' => 'error', 'message' => $upload['error']]);
                    exit;
                }
                $pic_update_sql = ", picture_path = ?";
                $params[] = $upload['success'];
            }

            $params[] = $visitor_id;

            $sql = "UPDATE users SET 
                        first_name = ?, last_name = ?, email = ?, phone = ?, gender = ?, 
                        marital_status = ?, wedding_anniversary = ?, physical_address = ?, latitude = ?, longitude = ?, 
                        invitation_source = ?, invited_by = ?, is_born_again = ?, wants_to_join = ?, visitation_preference = ?, 
                        prayer_requests = ? 
                        $pic_update_sql
                    WHERE id = ? AND spiritual_status IN ('1st_Timer', '2nd_Timer', '3rd_Timer')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['status' => 'success', 'message' => 'Record successfully updated.']);
            break;

        // ACTION 2: FETCH FIRST TIMERS (With Secure Notes Array)
        // =====================================================================================
        case 'fetch_visitors':
            $stmt = $pdo->query("
                SELECT u.*, 
                       f.status as followup_status, 
                       f.id as followup_id,
                       f.assigned_worker_id,
                       f.followup_notes, /* <--- ADDED THIS LINE */
                       w.first_name as worker_fname,
                       w.last_name as worker_lname
                FROM users u
                LEFT JOIN embrace_followups f ON f.visitor_id = u.id AND f.id = (SELECT MAX(id) FROM embrace_followups WHERE visitor_id = u.id)
                LEFT JOIN users w ON f.assigned_worker_id = w.id
                WHERE u.spiritual_status IN ('1st_Timer', '2nd_Timer', '3rd_Timer')
                ORDER BY u.created_at DESC
            ");
            $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Every note logged on each person, across all their follow-up cycles.
            $ids = array_column($visitors, 'id');
            $notesByVisitor = embrace_notes_for_visitors($pdo, $ids);
            $workersByVisitor = embrace_worker_history_for_visitors($pdo, $ids);
            foreach ($visitors as &$v) {
                $v['secure_notes'] = $notesByVisitor[(int) $v['id']] ?? [];
                $v['worker_history'] = $workersByVisitor[(int) $v['id']] ?? [];
            }
            unset($v);

            echo json_encode(['status' => 'success', 'data' => $visitors]);
            break;

        // =====================================================================================
        // ACTION 2.5: FETCH ARCHIVE (Promoted Members)
        // =====================================================================================
        case 'fetch_archive':
            $stmt = $pdo->query("
                SELECT u.id, u.first_name, u.last_name, u.phone, u.spiritual_status,
                       f.id as followup_id, f.assigned_worker_id, f.status as followup_status, 
                       COALESCE(f.completed_at, f.followup_date) as archive_date,
                       w.first_name as worker_fname, w.last_name as worker_lname
                FROM users u
                JOIN embrace_followups f ON f.visitor_id = u.id AND f.id = (SELECT MAX(id) FROM embrace_followups WHERE visitor_id = u.id)
                LEFT JOIN users w ON f.assigned_worker_id = w.id
                WHERE u.spiritual_status NOT IN ('1st_Timer', '2nd_Timer', '3rd_Timer')
                ORDER BY archive_date DESC
            ");
            $archived = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ids = array_column($archived, 'id');
            $notesByVisitor = embrace_notes_for_visitors($pdo, $ids);
            $workersByVisitor = embrace_worker_history_for_visitors($pdo, $ids);
            foreach ($archived as &$a) {
                $a['secure_notes'] = $notesByVisitor[(int) $a['id']] ?? [];
                $a['worker_history'] = $workersByVisitor[(int) $a['id']] ?? [];
            }
            unset($a);

            echo json_encode(['status' => 'success', 'data' => $archived]);
            break;

        // =====================================================================================
        // ACTION 3: FETCH EMBRACE WORKERS
        // =====================================================================================
        case 'fetch_workers':
            $stmt = $pdo->query("
                SELECT DISTINCT u.id, u.first_name, u.last_name 
                FROM users u 
                JOIN user_departments ud ON u.id = ud.user_id 
                JOIN departments d ON ud.department_id = d.id 
                WHERE (d.name LIKE '%Embrace%' OR d.name LIKE '%Follow-up%' OR d.name LIKE '%Welcome%') 
                AND ud.is_active = 1
                ORDER BY u.first_name ASC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // =====================================================================================
        // ACTION 4: ASSIGN FOLLOW-UP (Rotational Leadership Enabled)
        // =====================================================================================
        case 'assign_followup':
            $visitor_id = filter_var($_POST['visitor_id'] ?? '', FILTER_VALIDATE_INT);
            $worker_id = filter_var($_POST['worker_id'] ?? '', FILTER_VALIDATE_INT);

            if(!$visitor_id || !$worker_id) {
                echo json_encode(['status'=>'error', 'message'=>'Missing assignment details.']); 
                exit;
            }

            $vStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $vStmt->execute([$visitor_id]);
            $visitor = $vStmt->fetch(PDO::FETCH_ASSOC);
            $vName = $visitor ? "{$visitor['first_name']} {$visitor['last_name']}" : "a First Timer";

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO embrace_followups (visitor_id, assigned_worker_id, status, followup_date) VALUES (?, ?, 'Pending', CURDATE())");
                $stmt->execute([$visitor_id, $worker_id]);

                $notif = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message) VALUES (?, 'New Follow-up Task', ?)");
                $notif->execute([$worker_id, "Please log into the portal. You have been assigned to follow up with {$vName}."]);

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => "Worker assigned successfully to {$vName}."]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // =====================================================================================
        // ACTION 5: LOG FOLLOW-UP RESULTS (Audit Tracked)
        // =====================================================================================
        case 'log_followup':
            $followup_id = filter_var($_POST['followup_id'] ?? '', FILTER_VALIDATE_INT);
            $visitor_id = filter_var($_POST['visitor_id'] ?? '', FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? 'Completed';
            $notes = trim($_POST['followup_notes'] ?? '');
            
            if(!$followup_id || !$visitor_id) {
                echo json_encode(['status'=>'error', 'message'=>'Missing identifiers.']); 
                exit;
            }

            // PREVENT HIJACKING: Ensure the person logging is the assigned worker (or HOD)
            $fCheck = $pdo->prepare("SELECT assigned_worker_id FROM embrace_followups WHERE id = ?");
            $fCheck->execute([$followup_id]);
            $fData = $fCheck->fetch(PDO::FETCH_ASSOC);
            
            if($fData && $fData['assigned_worker_id'] != $user_id) {
                $hodCheck = $pdo->prepare("SELECT ud.id FROM user_departments ud JOIN departments d ON ud.department_id = d.id WHERE ud.user_id = ? AND d.name LIKE '%Embrace%' AND ud.role_in_dept IN ('Director', 'HOD')");
                $hodCheck->execute([$user_id]);
                if(!$hodCheck->fetch()) {
                    echo json_encode(['status' => 'error', 'message' => 'Access Denied: You cannot log a result for a task assigned to someone else.']);
                    exit;
                }
            }

            $is_born_again = isset($_POST['is_born_again']) ? 1 : 0;
            $wants_to_join = isset($_POST['wants_to_join']) ? 1 : 0;
            $visitation_preference = $_POST['visitation_preference'] ?? 'None';
            $needs_pastor = isset($_POST['needs_pastor']) ? 1 : 0;

            if($needs_pastor) $notes = "[PASTORAL ATTENTION REQUIRED]\n" . $notes;

            $pdo->beginTransaction();
            try {
                // 1. Update the final report in the main followups table
                $stmt1 = $pdo->prepare("UPDATE embrace_followups SET followup_notes = ?, status = ?, completed_at = NOW(), logged_by_user_id = ? WHERE id = ?");
                $stmt1->execute([$notes, $status, $user_id, $followup_id]);

                // 2. TIMELINE PATCH: Auto-inject this report into the ongoing "Manage Notes" timeline
                $timeline_prefix = ($needs_pastor) ? "[PASTORAL ATTENTION REQUIRED]\n" : "FINAL CALL REPORT (" . strtoupper($status) . "):\n";
                $timeline_note = $timeline_prefix . $notes;
                
                // Using the flat visibility array
                $vis_json = json_encode(['All']); 
                
                $stmt_timeline = $pdo->prepare("INSERT INTO embrace_followup_notes (followup_id, author_id, note_text, visible_to) VALUES (?, ?, ?, ?)");
                $stmt_timeline->execute([$followup_id, $user_id, $timeline_note, $vis_json]);

                // 3. Update user spiritual status and preferences
                $stmt2 = $pdo->prepare("UPDATE users SET is_born_again = ?, wants_to_join = ?, visitation_preference = ? WHERE id = ?");
                $stmt2->execute([$is_born_again, $wants_to_join, $visitation_preference, $visitor_id]);

                // 4. Handle Pastoral Notifications if flagged
                if ($needs_pastor) {
                    $vStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    $vStmt->execute([$visitor_id]);
                    $vName = $vStmt->fetch(PDO::FETCH_ASSOC);
                    $fullName = $vName ? "{$vName['first_name']} {$vName['last_name']}" : "A First Timer";

                    $pastors = $pdo->query("SELECT user_id FROM user_roles JOIN roles ON user_roles.role_id = roles.id WHERE roles.role_name IN ('Resident_Pastor', 'Assoc_Pastor')")->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($pastors)) {
                        $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Pastoral Attention Required', ?, '/modules/pastoral/index.php')");
                        foreach($pastors as $uid) {
                            $notifStmt->execute([$uid, "An Embrace follow-up report for {$fullName} has been flagged as needing urgent pastoral attention."]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Follow-up result logged securely.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // =====================================================================================
        // ACTION 6: PUSH TO CONGREGATION (Final Integration)
        // =====================================================================================
        case 'promote_member':
            $visitor_id = filter_var($_POST['visitor_id'] ?? '', FILTER_VALIDATE_INT);
            $spiritual_status = $_POST['spiritual_status'] ?? 'Member';
            $attendance_status = $_POST['attendance_status'] ?? 'New';

            if(!$visitor_id) {
                echo json_encode(['status'=>'error', 'message'=>'Missing or invalid visitor ID.']); 
                exit;
            }

            $stmt = $pdo->prepare("UPDATE users SET spiritual_status = ?, attendance_status = ? WHERE id = ?");
            $stmt->execute([$spiritual_status, $attendance_status, $visitor_id]);

            $idiStmt = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 1 AND is_active = 1");
            $idi_users = $idiStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($idi_users)) {
                $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$visitor_id]);
                $user = $uStmt->fetch(PDO::FETCH_ASSOC);
                $userName = $user ? "{$user['first_name']} {$user['last_name']}" : "A first timer";

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Member Promoted', ?, '/modules/congregation/index.php')");
                $alertMessage = "{$userName} has been successfully promoted from the Embrace pipeline into the master Congregation database. Please review their profile data.";
                
                foreach($idi_users as $uid) {
                    $notifStmt->execute([$uid, $alertMessage]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Hallelujah! Record successfully pushed to the Congregation database.']);
            break;
            
        // =====================================================================================
        // ACTION 7: EXPORT FIRST TIMERS TO EXCEL (Date Range)
        // =====================================================================================
        case 'export_excel':
            $start_date = $_GET['start_date'] ?? '';
            $end_date = $_GET['end_date'] ?? '';

            if(empty($start_date) || empty($end_date)) {
                echo "Error: Start and End dates are required.";
                exit;
            }

            try {
                require_once __DIR__ . '/../includes/embrace_export_excel.php';
                if (ob_get_length()) ob_end_clean();
                embrace_export_stream_excel($pdo, $start_date, $end_date);
            } catch (Throwable $e) {
                error_log('Embrace Excel export error: ' . $e->getMessage());
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'The Excel register could not be generated. Please try again or contact an administrator.';
            }
            exit;

        // =====================================================================================
        // ACTION 8: SAVE / EDIT NOTE (Zero-Trust Validation)
        // =====================================================================================
        case 'save_note':
            $followup_id = filter_var($_POST['followup_id'] ?? '', FILTER_VALIDATE_INT);
            $note_id = filter_var($_POST['note_id'] ?? '', FILTER_VALIDATE_INT);
            $note_text = trim($_POST['note_text'] ?? '');
            
            // Reconstruct JSON array from checkboxes
            $visibility = [];
            if (isset($_POST['vis_all'])) $visibility[] = 'All';
            if (isset($_POST['vis_pastor'])) $visibility[] = 'Pastors';
            if (isset($_POST['vis_director'])) $visibility[] = 'Directors';
            if (isset($_POST['vis_worker'])) $visibility[] = 'Assigned_Worker';
            
            if (empty($visibility)) $visibility[] = 'All'; // Fallback safeguard

            if (!$followup_id || empty($note_text)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing note details.']);
                exit;
            }

            $vis_json = json_encode($visibility);

            if ($note_id) {
                // Edit existing note. Must be the author.
                $check = $pdo->prepare("SELECT id FROM embrace_followup_notes WHERE id = ? AND author_id = ?");
                $check->execute([$note_id, $user_id]);
                if (!$check->fetch()) {
                    echo json_encode(['status' => 'error', 'message' => 'Access Denied: You can only edit notes you created.']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE embrace_followup_notes SET note_text = ?, visible_to = ? WHERE id = ?");
                $stmt->execute([$note_text, $vis_json, $note_id]);
            } else {
                // Insert new note
                $stmt = $pdo->prepare("INSERT INTO embrace_followup_notes (followup_id, author_id, note_text, visible_to) VALUES (?, ?, ?, ?)");
                $stmt->execute([$followup_id, $user_id, $note_text, $vis_json]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Note saved successfully.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Action not recognized.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Embrace API Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error. Contact technical team.']);
} catch (Exception $e) {
    error_log("Embrace API General Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
}