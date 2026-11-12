<?php
// /api/junior_church_api.php
//
// Junior Church (modules/junior_church/index.php, assets/ui/js/pages/junior-church.js):
// the children and their guardians, Sunday classes with attendance, and the
// curriculum vault.
// Access: the 'junior_church' row of Roles & permissions (pastors, and the
// seats of the department that owns it). View reads; Edit registers or
// changes a child and takes attendance; Create logs a class and uploads
// curriculum; Delete removes curriculum. Writes need the CSRF token.

require_once '../includes/db.php';
require_once '../includes/upload_guard.php';
csrf_require();
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);

// Children's records: until roles & permissions PR 2 this endpoint was open
// to every signed-in member.
api_require_can('junior_church', 'view');
$jc_level = ['save_child' => 'edit', 'create_service' => 'create', 'upload_curriculum' => 'create',
    'delete_curriculum' => 'delete', 'mark_attendance' => 'edit', 'mark_all_present' => 'edit'][$action] ?? null;
if ($jc_level !== null) {
    api_require_can('junior_church', $jc_level);
}

const JC_DOC_TYPES = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
const JC_MAX_BYTES = 10000000;

/** One document into uploads/junior_church/<folder>/, allow-listed, random name, at most 10 MB. */
function jc_store_document(array $file, string $folder): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && (int) ($file['size'] ?? 0) > JC_MAX_BYTES) {
        return ['ok' => false, 'error' => 'File too large. Max 10MB.'];
    }
    $stored = hod_upload_store($file, __DIR__ . '/../uploads/junior_church/' . $folder . '/', '/uploads/junior_church/' . $folder . '/', JC_DOC_TYPES);
    if (!$stored['ok']) {
        return ['ok' => false, 'error' => $stored['error']];
    }
    return ['ok' => true, 'url' => $stored['url'], 'type' => strtolower(pathinfo($stored['url'], PATHINFO_EXTENSION))];
}

/** A YouTube link as its embed address, any other http(s) link as it is, '' when empty, null when unusable. */
function jc_media_link(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^(?:https?://)?(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&\#/].*)?$#i', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (mb_strlen($url) <= 255 && preg_match('#^https?://[^\s"\'<>]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    return null;
}

/** A Y-m-d date, or '' when it does not parse. */
function jc_date(string $raw): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $raw);
    return $d && $d->format('Y-m-d') === $raw ? $raw : '';
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: DASHBOARD
        // ==========================================
        case 'fetch_dashboard':
            // Children and both guardians
            $children = $pdo->query("
                SELECT c.*,
                       u1.first_name as parent_fname, u1.last_name as parent_lname, u1.phone as parent_phone,
                       u2.first_name as parent2_fname, u2.last_name as parent2_lname, u2.phone as parent2_phone
                FROM junior_church_roster c
                LEFT JOIN users u1 ON c.parent_id = u1.id
                LEFT JOIN users u2 ON c.parent2_id = u2.id
                ORDER BY c.child_first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Teachers: the Junior Church team (seated in the department that owns it)
            $team = array_map('intval', perm_module_team($pdo, 'junior_church'));
            $teachers = $pdo->query("
                SELECT u.id, u.first_name, u.last_name
                FROM users u
                WHERE u.id IN (" . ($team ? implode(',', $team) : '0') . ")
                ORDER BY u.first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Every member, for the guardian pickers
            $parents = $pdo->query("SELECT id, first_name, last_name, phone FROM users ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC);

            // The latest 20 classes with their resources
            $services = $pdo->query("
                SELECT s.*, u.first_name as teacher_fname, u.last_name as teacher_lname,
                       (SELECT COUNT(*) FROM junior_church_attendance a WHERE a.service_id = s.id AND a.status = 'Present') as kids_present
                FROM junior_church_services s
                JOIN users u ON s.teacher_id = u.id
                ORDER BY s.service_date DESC
                LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);

            $curriculums = $pdo->query("SELECT * FROM junior_church_curriculum ORDER BY uploaded_at DESC")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'children' => $children,
                'teachers' => $teachers,
                'parents' => $parents,
                'services' => $services,
                'curriculums' => $curriculums
            ]);
            break;

        // ==========================================
        // ACTION 2: SAVE CHILD (add or edit)
        // ==========================================
        case 'save_child':
            $child_id = (int) ($_POST['child_id'] ?? 0);
            $fname = trim($_POST['child_first_name'] ?? '');
            $lname = trim($_POST['child_last_name'] ?? '');
            $rawDob = (string) ($_POST['dob'] ?? '');
            $dob = $rawDob === '' ? null : (jc_date($rawDob) ?: false);
            $parent_id = (int) ($_POST['parent_id'] ?? 0);
            $parent2_id = (int) ($_POST['parent2_id'] ?? 0) ?: null;
            $medical = trim($_POST['medical_notes'] ?? '');
            $image_base64 = (string) ($_POST['image_base64'] ?? '');

            if ($fname === '' || $lname === '' || $parent_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'First name, last name, and primary guardian are required.']);
                exit;
            }
            if (mb_strlen($fname) > 50 || mb_strlen($lname) > 50) {
                echo json_encode(['status' => 'error', 'message' => 'Keep each name to 50 characters.']);
                exit;
            }
            if ($dob === false) {
                echo json_encode(['status' => 'error', 'field' => 'dob', 'message' => 'That date of birth is not a date.']);
                exit;
            }
            $known = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id IN (?, ?)');
            $known->execute([$parent_id, $parent2_id ?? $parent_id]);
            if ((int) $known->fetchColumn() < ($parent2_id && $parent2_id !== $parent_id ? 2 : 1)) {
                echo json_encode(['status' => 'error', 'field' => 'parent_id', 'message' => 'Choose guardians from the member list.']);
                exit;
            }

            // The stored photo stays unless a new one is sent.
            $picture_path = null;
            if ($child_id) {
                $cur = $pdo->prepare('SELECT picture_path FROM junior_church_roster WHERE id = ?');
                $cur->execute([$child_id]);
                $row = $cur->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    echo json_encode(['status' => 'error', 'message' => 'That child record no longer exists.']);
                    exit;
                }
                $picture_path = $row['picture_path'];
            }

            // A new cropped photo: a PNG or JPEG data URL, checked to really be one, at most 5 MB.
            if ($image_base64 !== '') {
                if (!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\r\n]+)$#', $image_base64, $m)) {
                    echo json_encode(['status' => 'error', 'message' => 'The photo could not be read. Crop it again.']);
                    exit;
                }
                $bytes = base64_decode($m[2], true);
                $info = $bytes !== false && strlen($bytes) <= 5000000 ? @getimagesizefromstring($bytes) : false;
                if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                    echo json_encode(['status' => 'error', 'message' => 'The photo could not be read. Crop it again.']);
                    exit;
                }
                $dir = __DIR__ . '/../uploads/profiles/children/';
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    echo json_encode(['status' => 'error', 'message' => 'Could not save the photo.']);
                    exit;
                }
                $name = hod_upload_name($info[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
                if (file_put_contents($dir . $name, $bytes) === false) {
                    echo json_encode(['status' => 'error', 'message' => 'Could not save the photo.']);
                    exit;
                }
                $picture_path = '/uploads/profiles/children/' . $name;
            }

            if (!$child_id) {
                $stmt = $pdo->prepare("INSERT INTO junior_church_roster (child_first_name, child_last_name, picture_path, dob, parent_id, parent2_id, medical_notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fname, $lname, $picture_path, $dob, $parent_id, $parent2_id, $medical]);
                $msg = "Child successfully registered!";
                $notif_msg = "Your child, {$fname} {$lname}, has been successfully registered in the Junior Church system.";
            } else {
                $stmt = $pdo->prepare("UPDATE junior_church_roster SET child_first_name=?, child_last_name=?, picture_path=?, dob=?, parent_id=?, parent2_id=?, medical_notes=? WHERE id=?");
                $stmt->execute([$fname, $lname, $picture_path, $dob, $parent_id, $parent2_id, $medical, $child_id]);
                $msg = "Child details updated!";
                $notif_msg = "The Junior Church profile and medical notes for your child, {$fname} {$lname}, have been updated.";
            }

            // Tell the guardian(s)
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Junior Church Update', ?, '/modules/member_portal/index.php')");
            $notifStmt->execute([$parent_id, $notif_msg]);
            if ($parent2_id && $parent2_id !== $parent_id) $notifStmt->execute([$parent2_id, $notif_msg]);

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 3: LOG A SUNDAY CLASS
        // ==========================================
        case 'create_service':
            $service_date = jc_date((string) ($_POST['service_date'] ?? ''));
            $teacher_id = (int) ($_POST['teacher_id'] ?? 0);
            $topic = trim($_POST['topic'] ?? '');
            $pages_covered = mb_substr(trim($_POST['master_pages_covered'] ?? ''), 0, 100);
            $notes = trim($_POST['notes'] ?? '');
            $service_file_path = null;

            if ($service_date === '' || $teacher_id <= 0 || $topic === '') {
                echo json_encode(['status' => 'error', 'message' => 'Date, Teacher, and Topic are required.']);
                exit;
            }
            if (mb_strlen($topic) > 255) {
                echo json_encode(['status' => 'error', 'field' => 'topic', 'message' => 'Keep the topic to 255 characters.']);
                exit;
            }
            if (!in_array($teacher_id, array_map('intval', perm_module_team($pdo, 'junior_church')), true)) {
                echo json_encode(['status' => 'error', 'field' => 'teacher_id', 'message' => 'Choose a teacher on the Junior Church team.']);
                exit;
            }
            $media_link = jc_media_link((string) ($_POST['media_link'] ?? ''));
            if ($media_link === null) {
                echo json_encode(['status' => 'error', 'field' => 'media_link', 'message' => 'Paste a web link (https://…), or leave it empty.']);
                exit;
            }

            if (isset($_FILES['service_file']) && $_FILES['service_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = jc_store_document($_FILES['service_file'], 'service_files');
                if (!$upload['ok']) {
                    echo json_encode(['status' => 'error', 'field' => 'service_file', 'message' => $upload['error']]);
                    exit;
                }
                $service_file_path = $upload['url'];
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO junior_church_services (service_date, teacher_id, topic, master_pages_covered, service_file_path, media_link, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$service_date, $teacher_id, $topic, $pages_covered, $service_file_path, $media_link, $notes]);
                $new_service_id = (int) $pdo->lastInsertId();

                // Every registered child on the sheet, absent until marked
                $pdo->prepare("INSERT INTO junior_church_attendance (service_id, child_id, status) SELECT ?, id, 'Absent' FROM junior_church_roster")
                    ->execute([$new_service_id]);

                // Tell the teacher
                $nice_date = date('l, M jS', strtotime($service_date));
                $notifMsg = "You have been scheduled to teach the Junior Church class on {$nice_date}. Topic: {$topic}.";
                $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Teaching Assignment', ?, '/modules/junior_church/index.php')")
                    ->execute([$teacher_id, $notifMsg]);

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Sunday service & resources initialized. Ready for attendance!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==========================================
        // ACTION 4: CURRICULUM VAULT
        // ==========================================
        case 'upload_curriculum':
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($title === '') {
                echo json_encode(['status' => 'error', 'field' => 'title', 'message' => 'Curriculum title is required.']);
                exit;
            }
            if (mb_strlen($title) > 255) {
                echo json_encode(['status' => 'error', 'field' => 'title', 'message' => 'Keep the title to 255 characters.']);
                exit;
            }
            if (!isset($_FILES['curriculum_file']) || $_FILES['curriculum_file']['error'] === UPLOAD_ERR_NO_FILE) {
                echo json_encode(['status' => 'error', 'field' => 'curriculum_file', 'message' => 'Please select a file to upload.']);
                exit;
            }

            $upload = jc_store_document($_FILES['curriculum_file'], 'master_curriculums');
            if (!$upload['ok']) {
                echo json_encode(['status' => 'error', 'field' => 'curriculum_file', 'message' => $upload['error']]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO junior_church_curriculum (title, description, file_path, file_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $description, $upload['url'], $upload['type']]);

            // Tell the teachers
            $teachers = perm_module_team($pdo, 'junior_church');
            if (!empty($teachers)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Curriculum Uploaded', ?, '/modules/junior_church/index.php')");
                $alertMsg = "A new master curriculum resource titled '{$title}' has been uploaded to the digital vault.";
                foreach ($teachers as $uid) {
                    if ((int) $uid !== $user_id) {
                        $notifStmt->execute([$uid, $alertMsg]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Master curriculum successfully added to the digital vault.']);
            break;

        case 'delete_curriculum':
            $cur_id = (int) ($_POST['curriculum_id'] ?? 0);
            if ($cur_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Unknown resource.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT file_path FROM junior_church_curriculum WHERE id = ?");
            $stmt->execute([$cur_id]);
            $file = (string) $stmt->fetchColumn();

            // Only a file inside our own upload folder is removed from disk.
            // (The old code looked two folders up from api/, outside the site.)
            if (preg_match('#^/uploads/junior_church/master_curriculums/([A-Za-z0-9._-]+)$#', $file, $m) && !str_contains($m[1], '..')) {
                $disk = __DIR__ . '/../uploads/junior_church/master_curriculums/' . $m[1];
                if (is_file($disk)) {
                    @unlink($disk);
                }
            }

            $pdo->prepare("DELETE FROM junior_church_curriculum WHERE id = ?")->execute([$cur_id]);
            echo json_encode(['status' => 'success', 'message' => 'Resource deleted.']);
            break;

        // ==========================================
        // ACTION 5: ATTENDANCE
        // ==========================================
        case 'fetch_attendance':
            $service_id = (int) ($_POST['service_id'] ?? $_GET['service_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT c.id as child_id, c.child_first_name, c.child_last_name, c.picture_path, c.medical_notes,
                       u1.first_name as parent_fname, u1.phone as parent_phone, u2.phone as parent2_phone,
                       a.status
                FROM junior_church_attendance a
                JOIN junior_church_roster c ON a.child_id = c.id
                LEFT JOIN users u1 ON c.parent_id = u1.id
                LEFT JOIN users u2 ON c.parent2_id = u2.id
                WHERE a.service_id = ?
                ORDER BY c.child_first_name ASC
            ");
            $stmt->execute([$service_id]);

            echo json_encode(['status' => 'success', 'attendance' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'mark_attendance':
            $service_id = (int) ($_POST['service_id'] ?? 0);
            $child_id = (int) ($_POST['child_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            if (!in_array($status, ['Present', 'Absent'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'Unknown mark.']);
                exit;
            }
            if ($service_id > 0 && $child_id > 0) {
                $stmt = $pdo->prepare("UPDATE junior_church_attendance SET status = ? WHERE service_id = ? AND child_id = ?");
                $stmt->execute([$status, $service_id, $child_id]);
            }
            echo json_encode(['status' => 'success']);
            break;

        case 'mark_all_present':
            $service_id = (int) ($_POST['service_id'] ?? 0);
            if ($service_id > 0) {
                $stmt = $pdo->prepare("UPDATE junior_church_attendance SET status = 'Present' WHERE service_id = ?");
                $stmt->execute([$service_id]);
                echo json_encode(['status' => 'success', 'message' => 'All children marked present!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid service ID.']);
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Junior Church Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A secure database error occurred.']);
} catch (Exception $e) {
    error_log("Junior Church Server Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected server error occurred.']);
}
