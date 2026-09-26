<?php
// /api/junior_church_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

// Security Check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * Helper: Secure File Uploader for Documents & Media
 */
function processDocumentUpload($file, $subfolder) {
    $target_dir = "../uploads/junior_church/" . $subfolder . "/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $filename = uniqid('jc_', true) . '.' . $ext;
    $target_path = $target_dir . $filename;

    $allowed_exts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
    
    if($file["size"] > 10000000) return ['error' => 'File too large. Max 10MB.'];
    if(!in_array($ext, $allowed_exts)) return ['error' => 'Invalid file format. Only PDF, Word, PPT, or Images allowed.'];

    if (move_uploaded_file($file["tmp_name"], $target_path)) {
        return ['success' => '/uploads/junior_church/' . $subfolder . '/' . $filename, 'type' => $ext];
    }
    return ['error' => 'Upload failed due to a server error.'];
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH DASHBOARD DATA
        // ==========================================
        case 'fetch_dashboard':
            
            // A. Fetch all registered children + BOTH Parent details
            $childrenStmt = $pdo->query("
                SELECT c.*, 
                       u1.first_name as parent_fname, u1.last_name as parent_lname, u1.phone as parent_phone,
                       u2.first_name as parent2_fname, u2.last_name as parent2_lname, u2.phone as parent2_phone
                FROM junior_church_roster c
                LEFT JOIN users u1 ON c.parent_id = u1.id
                LEFT JOIN users u2 ON c.parent2_id = u2.id
                ORDER BY c.child_first_name ASC
            ");
            $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);

            // B. Fetch Junior Church Workers (Department ID = 11)
            $teachersStmt = $pdo->query("
                SELECT u.id, u.first_name, u.last_name 
                FROM users u
                JOIN user_departments ud ON u.id = ud.user_id
                WHERE ud.department_id = 11 AND ud.is_active = 1
                ORDER BY u.first_name ASC
            ");
            $teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);

            // C. Fetch all adult members (To populate the 'Select Parent' dropdown)
            $parentsStmt = $pdo->query("SELECT id, first_name, last_name, phone FROM users ORDER BY first_name ASC");
            $parents = $parentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // D. Fetch Service/Class History (Now includes media & files)
            $servicesStmt = $pdo->query("
                SELECT s.*, u.first_name as teacher_fname, u.last_name as teacher_lname,
                       (SELECT COUNT(*) FROM junior_church_attendance a WHERE a.service_id = s.id AND a.status = 'Present') as kids_present
                FROM junior_church_services s
                JOIN users u ON s.teacher_id = u.id
                ORDER BY s.service_date DESC
                LIMIT 20
            ");
            $services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

            // E. Fetch Master Curriculums
            $curriculumStmt = $pdo->query("SELECT * FROM junior_church_curriculum ORDER BY uploaded_at DESC");
            $curriculums = $curriculumStmt->fetchAll(PDO::FETCH_ASSOC);

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
        // ACTION 2: SAVE CHILD (Add or Edit)
        // ==========================================
        case 'save_child':
            $child_id = filter_var($_POST['child_id'] ?? '', FILTER_VALIDATE_INT);
            $fname = trim($_POST['child_first_name'] ?? '');
            $lname = trim($_POST['child_last_name'] ?? '');
            $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
            $parent_id = filter_var($_POST['parent_id'] ?? '', FILTER_VALIDATE_INT);
            $parent2_id = filter_var($_POST['parent2_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
            $medical = trim($_POST['medical_notes'] ?? '');
            $image_base64 = $_POST['image_base64'] ?? '';
            $picture_path = $_POST['existing_picture'] ?? null;

            if (empty($fname) || empty($lname) || empty($parent_id)) {
                echo json_encode(['status' => 'error', 'message' => 'First name, last name, and primary guardian are required.']);
                exit;
            }

            // Handle Base64 Image Upload if a new picture was cropped
            if (!empty($image_base64)) {
                $image_parts = explode(";base64,", $image_base64);
                if (count($image_parts) == 2) {
                    $image_base64_decoded = base64_decode($image_parts[1]);
                    $upload_dir = '../uploads/profiles/children/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    $filename = 'child_' . time() . '_' . rand(1000, 9999) . '.png';
                    if (file_put_contents($upload_dir . $filename, $image_base64_decoded)) {
                        $picture_path = '/uploads/profiles/children/' . $filename;
                    }
                }
            }

            if (empty($child_id)) {
                // INSERT NEW
                $stmt = $pdo->prepare("INSERT INTO junior_church_roster (child_first_name, child_last_name, picture_path, dob, parent_id, parent2_id, medical_notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fname, $lname, $picture_path, $dob, $parent_id, $parent2_id, $medical]);
                $msg = "Child successfully registered!";
                $notif_msg = "Your child, {$fname} {$lname}, has been successfully registered in the Junior Church system.";
            } else {
                // UPDATE EXISTING
                $stmt = $pdo->prepare("UPDATE junior_church_roster SET child_first_name=?, child_last_name=?, picture_path=?, dob=?, parent_id=?, parent2_id=?, medical_notes=? WHERE id=?");
                $stmt->execute([$fname, $lname, $picture_path, $dob, $parent_id, $parent2_id, $medical, $child_id]);
                $msg = "Child details updated!";
                $notif_msg = "The Junior Church profile and medical notes for your child, {$fname} {$lname}, have been updated.";
            }

            // NOTIFICATION TRIGGER: Alert the Parent(s)
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Junior Church Update', ?, '/modules/member_portal/index.php')");
            if ($parent_id) $notifStmt->execute([$parent_id, $notif_msg]);
            if ($parent2_id) $notifStmt->execute([$parent2_id, $notif_msg]);

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 3: CREATE SUNDAY SERVICE
        // ==========================================
        case 'create_service':
            $service_date = $_POST['service_date'] ?? '';
            $teacher_id = filter_var($_POST['teacher_id'] ?? '', FILTER_VALIDATE_INT);
            $topic = trim($_POST['topic'] ?? '');
            $pages_covered = trim($_POST['master_pages_covered'] ?? '');
            $media_link = trim($_POST['media_link'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $service_file_path = null;

            if (empty($service_date) || empty($teacher_id) || empty($topic)) {
                echo json_encode(['status' => 'error', 'message' => 'Date, Teacher, and Topic are required.']);
                exit;
            }

            // Clean YouTube links to ensure embedded formats work smoothly
            if (!empty($media_link)) {
                if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $media_link, $matches)) {
                    $media_link = 'https://www.youtube.com/embed/' . $matches[1];
                }
            }

            // Handle Weekly File Upload (Worksheets, crafts, etc.)
            if (!empty($_FILES['service_file']['name'])) {
                $upload = processDocumentUpload($_FILES['service_file'], 'service_files');
                if (isset($upload['error'])) {
                    echo json_encode(['status' => 'error', 'message' => $upload['error']]);
                    exit;
                }
                $service_file_path = $upload['success'];
            }

            // Transaction ensures service and attendance create together
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO junior_church_services (service_date, teacher_id, topic, master_pages_covered, service_file_path, media_link, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$service_date, $teacher_id, $topic, $pages_covered, $service_file_path, $media_link, $notes]);
                $new_service_id = $pdo->lastInsertId();

                // Auto-populate the attendance roster with all registered children (defaulting to Absent)
                $pdo->query("INSERT INTO junior_church_attendance (service_id, child_id, status) SELECT $new_service_id, id, 'Absent' FROM junior_church_roster");

                // NOTIFICATION TRIGGER: Alert the Assigned Teacher
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
        // ACTION 4: UPLOAD MASTER CURRICULUM
        // ==========================================
        case 'upload_curriculum':
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($title)) {
                echo json_encode(['status' => 'error', 'message' => 'Curriculum title is required.']);
                exit;
            }

            if (empty($_FILES['curriculum_file']['name'])) {
                echo json_encode(['status' => 'error', 'message' => 'Please select a file to upload.']);
                exit;
            }

            $upload = processDocumentUpload($_FILES['curriculum_file'], 'master_curriculums');
            if (isset($upload['error'])) {
                echo json_encode(['status' => 'error', 'message' => $upload['error']]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO junior_church_curriculum (title, description, file_path, file_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $description, $upload['success'], $upload['type']]);

            // NOTIFICATION TRIGGER: Alert all Junior Church Teachers (Dept ID 11)
            $teacherStmt = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 11 AND is_active = 1");
            $teachers = $teacherStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($teachers)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Curriculum Uploaded', ?, '/modules/junior_church/index.php')");
                $alertMsg = "A new master curriculum resource titled '{$title}' has been uploaded to the digital vault.";
                foreach($teachers as $uid) {
                    if ($uid != $_SESSION['user_id']) { // Don't notify the person who just uploaded it
                        $notifStmt->execute([$uid, $alertMsg]);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Master curriculum successfully added to the digital vault.']);
            break;

        // ==========================================
        // ACTION 5: DELETE CURRICULUM
        // ==========================================
        case 'delete_curriculum':
            $cur_id = filter_var($_POST['curriculum_id'] ?? '', FILTER_VALIDATE_INT);
            if ($cur_id) {
                $stmt = $pdo->prepare("SELECT file_path FROM junior_church_curriculum WHERE id = ?");
                $stmt->execute([$cur_id]);
                $file = $stmt->fetchColumn();
                
                if ($file && file_exists("../../" . ltrim($file, '/'))) {
                    unlink("../../" . ltrim($file, '/')); // Delete physical file
                }
                
                $pdo->prepare("DELETE FROM junior_church_curriculum WHERE id = ?")->execute([$cur_id]);
                echo json_encode(['status' => 'success', 'message' => 'Resource deleted.']);
            }
            break;

        // ==========================================
        // ACTION 6: FETCH ATTENDANCE FOR A SERVICE (Expanded for UI Dials & Alerts)
        // ==========================================
        case 'fetch_attendance':
            $service_id = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
            
            $stmt = $pdo->prepare("
                SELECT c.id as child_id, c.child_first_name, c.child_last_name, c.picture_path, c.medical_notes,
                       u1.phone as parent_phone, u2.phone as parent2_phone,
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

        // ==========================================
        // ACTION 7: MARK INDIVIDUAL ATTENDANCE
        // ==========================================
        case 'mark_attendance':
            $service_id = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
            $child_id = filter_var($_POST['child_id'] ?? '', FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? 'Absent';

            if ($service_id && $child_id) {
                $stmt = $pdo->prepare("UPDATE junior_church_attendance SET status = ? WHERE service_id = ? AND child_id = ?");
                $stmt->execute([$status, $service_id, $child_id]);
            }
            echo json_encode(['status' => 'success']);
            break;

        // ==========================================
        // ACTION 8: MARK ALL PRESENT (Batch Action)
        // ==========================================
        case 'mark_all_present':
            $service_id = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
            
            if ($service_id) {
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
    error_log("Junior Church Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A secure database error occurred.']);
} catch (Exception $e) {
    error_log("Junior Church Server Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected server error occurred.']);
}