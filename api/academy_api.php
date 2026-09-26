<?php
// /api/academy_api.php

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

// 2. Role-Based Access Control (RBAC): Admin vs. Student
$is_admin = false;
if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'])) {
    $is_admin = true;
} else {
    // Check if user is officially assigned to the Academy Department
    $deptStmt = $pdo->prepare("
        SELECT d.id FROM departments d 
        JOIN user_departments ud ON d.id = ud.department_id 
        WHERE ud.user_id = :uid AND (d.name LIKE '%Academy%' OR d.name LIKE '%School%')
    ");
    $deptStmt->execute(['uid' => $user_id]);
    if ($deptStmt->fetch()) $is_admin = true;
}

try {
    switch ($action) {

        // =====================================================================================
        // SECTION A: DASHBOARD & ROUTING
        // =====================================================================================
        
       case 'fetch_dashboard':
            $response = ['status' => 'success', 'is_admin' => $is_admin, 'data' => []];

            if ($is_admin) {
                // ADMIN DATA: Pending Apps, Active Batches, Programs, Panel Interviews to conduct
                $response['data']['pending_applications'] = $pdo->query("SELECT * FROM academy_applications WHERE application_status = 'Pending' ORDER BY applied_at DESC")->fetchAll();
                
                $response['data']['programs'] = $pdo->query("SELECT * FROM academy_programs ORDER BY name ASC")->fetchAll();
                
                // Courses WITH instructor names and timeframes
                $response['data']['courses'] = $pdo->query("SELECT c.*, p.name as program_name, u.first_name as instr_fname, u.last_name as instr_lname FROM academy_courses c LEFT JOIN academy_programs p ON c.program_id = p.id LEFT JOIN users u ON c.instructor_id = u.id ORDER BY p.name ASC, c.name ASC")->fetchAll();
                
                $response['data']['active_batches'] = $pdo->query("SELECT b.*, p.name as program_name FROM academy_batches b JOIN academy_programs p ON b.program_id = p.id WHERE b.status = 'Active' ORDER BY b.start_date DESC")->fetchAll();
                
                // Submissions requiring grading (Restored!)
                $response['data']['pending_grading'] = $pdo->query("
                    SELECT s.id, a.title, u.first_name, u.last_name, s.submitted_at, s.file_url, s.submission_text
                    FROM academy_submissions s
                    JOIN academy_assignments a ON s.assignment_id = a.id
                    JOIN users u ON s.student_id = u.id
                    WHERE s.score IS NULL ORDER BY s.submitted_at ASC
                ")->fetchAll();

            } else {
                // STUDENT DATA: Status, Batches, Forums, Materials, Exams, Assignments
                $meStmt = $pdo->prepare("SELECT spiritual_status FROM users WHERE id = :uid");
                $meStmt->execute(['uid' => $user_id]);
                $my_status = $meStmt->fetchColumn();

                if ($my_status === 'Worker') {
                    $response['data']['status'] = 'Graduated';
                } else {
                    // Check Application Status
                    $appStmt = $pdo->prepare("SELECT application_status FROM academy_applications WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
                    $appStmt->execute(['uid' => $user_id]);
                    $app = $appStmt->fetch();

                    if (!$app) {
                        $response['data']['status'] = 'Not_Applied';
                    } elseif ($app['application_status'] === 'Pending') {
                        $response['data']['status'] = 'Application_Pending';
                    } elseif ($app['application_status'] === 'Approved') {
                        $response['data']['status'] = 'Enrolled';
                        
                        // Fetch active enrolled batches & programs
                        $enrollStmt = $pdo->prepare("
                            SELECT e.id as enrollment_id, b.id as batch_id, b.batch_name, p.id as program_id, p.name as program_name, e.is_captain 
                            FROM academy_enrollments e
                            JOIN academy_batches b ON e.batch_id = b.id
                            JOIN academy_programs p ON b.program_id = p.id
                            WHERE e.user_id = :uid AND e.graduation_status = 'Enrolled'
                        ");
                        $enrollStmt->execute(['uid' => $user_id]);
                        $enrollment = $enrollStmt->fetch();

                        if ($enrollment) {
                            $response['data']['enrollment'] = $enrollment;
                            $program_id = $enrollment['program_id'];
                            
                            // Fetch Courses & Materials for this Program (Ensuring it respects is_course_published)
                            $cStmt = $pdo->prepare("
                                SELECT c.id, c.name, c.description, c.timer_minutes, c.is_exam_published, p.name as program_name,
                                       c.start_date, c.end_date, u.first_name as instr_fname, u.last_name as instr_lname
                                FROM academy_courses c 
                                JOIN academy_programs p ON c.program_id = p.id 
                                JOIN academy_batches b ON b.program_id = p.id 
                                JOIN academy_enrollments e ON e.batch_id = b.id 
                                LEFT JOIN users u ON c.instructor_id = u.id
                                WHERE e.user_id = :uid AND e.graduation_status != 'Graduated' AND c.is_course_published = 1 AND c.program_id = :pid
                            ");
                            $cStmt->execute(['uid' => $user_id, 'pid' => $program_id]);
                            $courses = $cStmt->fetchAll();
                            
                            foreach ($courses as &$course) {
                                // Materials
                                $matStmt = $pdo->prepare("SELECT title, material_type, file_url FROM course_materials WHERE course_id = :cid");
                                $matStmt->execute(['cid' => $course['id']]);
                                $course['materials'] = $matStmt->fetchAll();
                                
                                // Exam Status
                                $exStmt = $pdo->prepare("SELECT status, score FROM user_exams WHERE user_id = :uid AND course_id = :cid ORDER BY id DESC LIMIT 1");
                                $exStmt->execute(['uid' => $user_id, 'cid' => $course['id']]);
                                $course['exam_status'] = $exStmt->fetch() ?: null;
                            }
                            $response['data']['courses'] = $courses;

                            // Fetch Assignments (Sermon Reviews, Book Reviews)
                            $assStmt = $pdo->prepare("
                                SELECT a.id, a.title, a.assignment_type, a.description, a.deadline,
                                       (SELECT score FROM academy_submissions s WHERE s.assignment_id = a.id AND s.student_id = :uid LIMIT 1) as my_score
                                FROM academy_assignments a WHERE a.program_id = :pid ORDER BY a.deadline ASC
                            ");
                            $assStmt->execute(['uid' => $user_id, 'pid' => $program_id]);
                            $response['data']['assignments'] = $assStmt->fetchAll();

                            // Fetch Upcoming Mandatory Events
                            $evtStmt = $pdo->prepare("SELECT id, title, event_date FROM events WHERE academy_batch_id = :bid AND event_date >= CURDATE() ORDER BY event_date ASC");
                            $evtStmt->execute(['bid' => $enrollment['batch_id']]);
                            $response['data']['events'] = $evtStmt->fetchAll();
                        }
                    }
                }
            }
            echo json_encode($response);
            break;

        // =====================================================================================
        // SECTION B: APPLICATION & ENROLLMENT (The Entry Pipeline)
        // =====================================================================================

        case 'submit_application':
            // Students submit the thorough 22-point form
            $full_name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $salvation_info = trim($_POST['salvation_info'] ?? '');
            
            if(empty($full_name) || empty($phone)) exit(json_encode(['status' => 'error', 'message' => 'Core contact details are required.']));

            $stmt = $pdo->prepare("
                INSERT INTO academy_applications 
                (user_id, full_name, phone_number, whatsapp_number, birth_date, email_address, residential_address, occupation, work_address, marital_status, wedding_anniversary, salvation_info, baptism_info, holy_spirit_info, invited_by, why_hod, previous_church, past_service_experience, other_ministries, service_interests, tribe_captain, class_date, comments) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id, $full_name, $phone, $_POST['whatsapp_number'] ?? '', empty($_POST['birth_date']) ? null : $_POST['birth_date'], 
                $_POST['email_address'] ?? '', $_POST['residential_address'] ?? '', $_POST['occupation'] ?? '', $_POST['work_address'] ?? '', 
                $_POST['marital_status'] ?? '', empty($_POST['wedding_anniversary']) ? null : $_POST['wedding_anniversary'], $salvation_info, 
                $_POST['baptism_info'] ?? '', $_POST['holy_spirit_info'] ?? '', $_POST['invited_by'] ?? '', $_POST['why_hod'] ?? '', 
                $_POST['previous_church'] ?? '', $_POST['past_service_experience'] ?? '', $_POST['other_ministries'] ?? '', 
                $_POST['service_interests'] ?? '', $_POST['tribe_captain'] ?? '', $_POST['class_date'] ?? '', $_POST['comments'] ?? ''
            ]);

            echo json_encode(['status' => 'success', 'message' => 'Your application has been submitted to the HOD Academy Dean successfully.']);
            break;

        case 'approve_application':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $app_id = $_POST['application_id'] ?? '';
            $batch_id = $_POST['batch_id'] ?? ''; // Which batch to drop them in

            if(empty($app_id) || empty($batch_id)) exit(json_encode(['status' => 'error', 'message' => 'Application ID and Target Batch are required.']));

            // 1. Mark Approved
            $pdo->prepare("UPDATE academy_applications SET application_status = 'Approved' WHERE id = ?")->execute([$app_id]);
            
            // 2. Auto-Enroll in Batch
            $app = $pdo->prepare("SELECT user_id FROM academy_applications WHERE id = ?")->execute([$app_id]);
            $student_id = $pdo->prepare("SELECT user_id FROM academy_applications WHERE id = ?")->execute([$app_id]);
            $student_id = $pdo->query("SELECT user_id FROM academy_applications WHERE id = $app_id")->fetchColumn();

            if($student_id) {
                try {
                    $pdo->prepare("INSERT INTO academy_enrollments (user_id, batch_id) VALUES (?, ?)")->execute([$student_id, $batch_id]);
                } catch(PDOException $e) { /* Already enrolled */ }
            }

            echo json_encode(['status' => 'success', 'message' => 'Student approved and officially enrolled in the batch.']);
            break;

        // =====================================================================================
        // SECTION C: ARCHITECTURE SETUP (Programs, Courses, Batches)
        // =====================================================================================

        case 'create_program':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if(empty($name)) exit(json_encode(['status' => 'error', 'message' => 'Program name is required.']));
            
            $pdo->prepare("INSERT INTO academy_programs (name, description) VALUES (?, ?)")->execute([$name, $desc]);
            echo json_encode(['status' => 'success', 'message' => "Master Program '$name' created!"]);
            break;

        case 'create_course':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            
            $program_id = $_POST['program_id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $timer = (int)($_POST['timer_minutes'] ?? 30);
            $passing = (int)($_POST['passing_percentage'] ?? 70);
            
            $start_date = empty($_POST['start_date']) ? null : $_POST['start_date'];
            $end_date = empty($_POST['end_date']) ? null : $_POST['end_date'];
            $instructor_id = empty($_POST['instructor_id']) ? null : $_POST['instructor_id'];
            
            if(empty($program_id) || empty($name)) exit(json_encode(['status' => 'error', 'message' => 'Program and Course name are required.']));
            
            $stmt = $pdo->prepare("INSERT INTO academy_courses (program_id, name, description, timer_minutes, passing_percentage, start_date, end_date, instructor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$program_id, $name, $desc, $timer, $passing, $start_date, $end_date, $instructor_id]);
            
            echo json_encode(['status' => 'success', 'message' => "Course '$name' created successfully!"]);
            break;

        case 'create_batch':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $program_id = $_POST['program_id'] ?? '';
            $batch_name = trim($_POST['batch_name'] ?? '');
            if(empty($program_id) || empty($batch_name)) exit(json_encode(['status' => 'error', 'message' => 'Program and Batch Name required.']));
            
            $pdo->prepare("INSERT INTO academy_batches (program_id, batch_name, start_date) VALUES (?, ?, CURDATE())")->execute([$program_id, $batch_name]);
            echo json_encode(['status' => 'success', 'message' => 'Cohort/Batch created successfully.']);
            break;

        case 'fetch_setup_data':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            
            // Fetch Workers/Pastors as potential Course Directors
            $instructors = $pdo->query("SELECT id, first_name, last_name FROM users WHERE spiritual_status = 'Worker' ORDER BY first_name ASC")->fetchAll();

            echo json_encode([
                'status' => 'success', 
                'programs' => $pdo->query("SELECT id, name FROM academy_programs ORDER BY name ASC")->fetchAll(),
                'courses' => $pdo->query("SELECT id, name FROM academy_courses ORDER BY name ASC")->fetchAll(),
                'batches' => $pdo->query("SELECT id, batch_name FROM academy_batches WHERE status = 'Active' ORDER BY id DESC")->fetchAll(),
                'students' => $pdo->query("SELECT id, first_name, last_name FROM users WHERE spiritual_status IN ('1st_Timer', '2nd_Timer', '3rd_Timer', 'Member') ORDER BY first_name ASC")->fetchAll(),
                'instructors' => $instructors
            ]);
            break;

        // =====================================================================================
        // SECTION D: ACADEMIC MATERIALS & ASSIGNMENTS
        // =====================================================================================

        case 'add_material':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $course_id = $_POST['course_id'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $type = $_POST['material_type'] ?? 'Document';
            $file_url = trim($_POST['file_url'] ?? '');

            if(empty($title)) exit(json_encode(['status' => 'error', 'message' => 'Title is required.']));

            if ($type === 'Document' && isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/academy/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['file_upload']['name']));
                if (move_uploaded_file($_FILES['file_upload']['tmp_name'], $upload_dir . $filename)) {
                    $file_url = '../../uploads/academy/' . $filename; 
                } else {
                    exit(json_encode(['status' => 'error', 'message' => 'Failed to upload document.']));
                }
            }

            $pdo->prepare("INSERT INTO course_materials (course_id, title, material_type, file_url) VALUES (?, ?, ?, ?)")->execute([$course_id, $title, $type, $file_url]);
            echo json_encode(['status' => 'success', 'message' => 'Material published successfully.']);
            break;

        case 'create_assignment':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $program_id = $_POST['program_id'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $type = $_POST['assignment_type'] ?? 'Sermon_Review';
            $deadline = $_POST['deadline'] ?? '';

            if(empty($title) || empty($deadline)) exit(json_encode(['status' => 'error', 'message' => 'Title and Deadline are required.']));
            
            $pdo->prepare("INSERT INTO academy_assignments (program_id, title, assignment_type, description, deadline) VALUES (?, ?, ?, ?, ?)")->execute([$program_id, $title, $type, $_POST['description'] ?? '', $deadline]);
            echo json_encode(['status' => 'success', 'message' => 'Assignment created and distributed to students.']);
            break;

        case 'submit_assignment':
            $assignment_id = $_POST['assignment_id'] ?? '';
            $sub_text = trim($_POST['submission_text'] ?? '');
            $file_url = null;

            // Handle optional file upload for reviews/presentations
            if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/submissions/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = time() . '_SUB_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['submission_file']['name']));
                if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $upload_dir . $filename)) {
                    $file_url = '../../uploads/submissions/' . $filename; 
                }
            }

            if(empty($sub_text) && empty($file_url)) exit(json_encode(['status' => 'error', 'message' => 'You must either type a response or upload a file.']));

            $pdo->prepare("INSERT INTO academy_submissions (assignment_id, student_id, submission_text, file_url) VALUES (?, ?, ?, ?)")->execute([$assignment_id, $user_id, $sub_text, $file_url]);
            echo json_encode(['status' => 'success', 'message' => 'Assignment submitted successfully for grading.']);
            break;

        case 'grade_assignment':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $submission_id = $_POST['submission_id'] ?? '';
            $score = (int)($_POST['score'] ?? 0);

            $pdo->prepare("UPDATE academy_submissions SET score = ?, graded_by = ? WHERE id = ?")->execute([$score, $user_id, $submission_id]);
            echo json_encode(['status' => 'success', 'message' => 'Submission graded successfully.']);
            break;

        // =====================================================================================
        // SECTION E: THE FORUM (Two-Way Communication)
        // =====================================================================================

        case 'post_forum_message':
            $batch_id = $_POST['batch_id'] ?? '';
            $msg = trim($_POST['message'] ?? '');
            if(empty($msg) || empty($batch_id)) exit(json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']));
            
            $pdo->prepare("INSERT INTO academy_forum_messages (batch_id, sender_id, message) VALUES (?, ?, ?)")->execute([$batch_id, $user_id, $msg]);
            echo json_encode(['status' => 'success']);
            break;

        case 'fetch_forum':
            $batch_id = $_POST['batch_id'] ?? '';
            $stmt = $pdo->prepare("
                SELECT m.message, m.created_at, u.first_name, u.last_name, u.spiritual_status 
                FROM academy_forum_messages m 
                JOIN users u ON m.sender_id = u.id 
                WHERE m.batch_id = ? ORDER BY m.created_at ASC
            ");
            $stmt->execute([$batch_id]);
            echo json_encode(['status' => 'success', 'messages' => $stmt->fetchAll()]);
            break;

        // =====================================================================================
        // SECTION F: EXAMINATIONS & GRADING
        // =====================================================================================

        case 'add_exam_question':
            // Logic exists perfectly in previous iterations, duplicated here for completeness
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $c_id = $_POST['course_id'] ?? ''; $q = trim($_POST['question'] ?? ''); $a = trim($_POST['option_a'] ?? ''); $c = $_POST['correct_option'] ?? '';
            if(empty($c_id) || empty($q) || empty($a) || empty($c)) exit(json_encode(['status' => 'error', 'message' => 'Required fields missing.']));
            $pdo->prepare("INSERT INTO exam_questions (course_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$c_id, $q, $a, $_POST['option_b'] ?? '', $_POST['option_c'] ?? '', $_POST['option_d'] ?? null, $c]);
            echo json_encode(['status' => 'success', 'message' => 'Question added.']);
            break;

        case 'publish_exam':
            if (!$is_admin) exit();
            $pdo->prepare("UPDATE academy_courses SET is_exam_published = ? WHERE id = ?")->execute([$_POST['publish_status'] ?? 1, $_POST['course_id'] ?? '']);
            echo json_encode(['status' => 'success', 'message' => "Exam visibility updated."]);
            break;
        
        case 'publish_course':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $course_id = $_POST['course_id'] ?? '';
            $status = $_POST['publish_status'] ?? 1; 
            
            $pdo->prepare("UPDATE academy_courses SET is_course_published = ? WHERE id = ?")->execute([$status, $course_id]);
            $msg = $status == 1 ? "Course pushed to student portals!" : "Course hidden from students.";
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;
            
        case 'start_exam':
            $course_id = $_POST['course_id'] ?? '';
            $check = $pdo->prepare("SELECT id, status FROM user_exams WHERE user_id = ? AND course_id = ? ORDER BY id DESC LIMIT 1");
            $check->execute([$user_id, $course_id]);
            $ex = $check->fetch();
            if ($ex && $ex['status'] !== 'Pending') exit(json_encode(['status' => 'error', 'message' => 'Exam locked. Contact Dean.']));
            if (!$ex) $pdo->prepare("INSERT INTO user_exams (user_id, course_id, status, date_taken) VALUES (?, ?, 'Pending', NOW())")->execute([$user_id, $course_id]);
            
            $qs = $pdo->prepare("SELECT id, question, option_a, option_b, option_c, option_d FROM exam_questions WHERE course_id = ?"); $qs->execute([$course_id]);
            $timer = $pdo->prepare("SELECT timer_minutes FROM academy_courses WHERE id = ?"); $timer->execute([$course_id]);
            echo json_encode(['status' => 'success', 'questions' => $qs->fetchAll(), 'timer_minutes' => $timer->fetchColumn()]);
            break;

        case 'submit_exam':
            $course_id = $_POST['course_id'] ?? '';
            $answers = json_decode($_POST['answers'], true);
            $session = $pdo->prepare("SELECT id FROM user_exams WHERE user_id = ? AND course_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1"); $session->execute([$user_id, $course_id]);
            $user_exam_id = $session->fetchColumn();
            if (!$user_exam_id) exit(json_encode(['status' => 'error', 'message' => 'No active session.']));

            $threshold = $pdo->prepare("SELECT passing_percentage FROM academy_courses WHERE id = ?"); $threshold->execute([$course_id]); $pass_mark = $threshold->fetchColumn() ?: 70;
            $keys = $pdo->prepare("SELECT id, correct_option FROM exam_questions WHERE course_id = ?"); $keys->execute([$course_id]); $master_key = $keys->fetchAll(PDO::FETCH_KEY_PAIR);

            $correct = 0; $breakdown = [];
            foreach ($master_key as $q_id => $ans) {
                $stu_ans = $answers[$q_id] ?? null;
                $is_c = ($stu_ans === $ans) ? 1 : 0;
                if ($is_c) $correct++;
                $pdo->prepare("INSERT INTO user_exam_answers (user_exam_id, question_id, selected_option, is_correct) VALUES (?, ?, ?, ?)")->execute([$user_exam_id, $q_id, $stu_ans, $is_c]);
                $breakdown[] = ['question_id' => $q_id, 'your_answer' => $stu_ans, 'correct_answer' => $ans, 'is_correct' => (bool)$is_c];
            }

            $score = count($master_key) > 0 ? ($correct / count($master_key)) * 100 : 0;
            $status = $score >= $pass_mark ? 'Passed' : 'Failed';
            $pdo->prepare("UPDATE user_exams SET score = ?, status = ? WHERE id = ?")->execute([$score, $status, $user_exam_id]);

            echo json_encode(['status' => 'success', 'message' => "Scored " . number_format($score, 1) . "%", 'final_score' => $score, 'final_status' => $status, 'breakdown' => $breakdown]);
            break;

        // =====================================================================================
        // SECTION G: FINAL PANEL INTERVIEW & MERIT LIST
        // =====================================================================================

        case 'submit_interview_score':
            if (!$is_admin) exit(json_encode(['status' => 'error', 'message' => 'Unauthorized.']));
            $enrollment_id = $_POST['enrollment_id'] ?? '';
            $doc = (int)($_POST['doctrinal_score'] ?? 0);
            $zeal = (int)($_POST['zeal_score'] ?? 0);
            $char = (int)($_POST['character_score'] ?? 0);
            $total = $doc + $zeal + $char;

            $pdo->prepare("INSERT INTO academy_interview_scores (enrollment_id, doctrinal_score, zeal_score, character_score, total_score, panel_comments, conducted_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$enrollment_id, $doc, $zeal, $char, $total, $_POST['comments'] ?? '', $user_id]);
            
            echo json_encode(['status' => 'success', 'message' => "Panel Rubric Score ($total/30) officially logged."]);
            break;

        case 'fetch_batch_merit_list':
            if (!$is_admin) exit();
            $batch_id = $_POST['batch_id'] ?? '';
            $total_events = $pdo->prepare("SELECT COUNT(*) FROM events WHERE academy_batch_id = ?"); $total_events->execute([$batch_id]);
            
            $stmt = $pdo->prepare("
                SELECT u.id as student_id, u.first_name, u.last_name, e.id as enrollment_id, e.graduation_status, e.is_captain,
                       (SELECT AVG(score) FROM user_exams ue JOIN academy_courses ac ON ue.course_id = ac.id WHERE ue.user_id = u.id AND ac.program_id = (SELECT program_id FROM academy_batches WHERE id = ?) AND ue.status = 'Passed') as avg_exam_score,
                       (SELECT COUNT(*) FROM attendance a JOIN events ev ON a.event_id = ev.id WHERE ev.academy_batch_id = ? AND a.user_id = u.id AND a.status = 'Present') as attended_events,
                       (SELECT total_score FROM academy_interview_scores WHERE enrollment_id = e.id ORDER BY id DESC LIMIT 1) as interview_score
                FROM academy_enrollments e
                JOIN users u ON e.user_id = u.id
                WHERE e.batch_id = ?
                ORDER BY avg_exam_score DESC, attended_events DESC
            ");
            $stmt->execute([$batch_id, $batch_id, $batch_id]);
            echo json_encode(['status' => 'success', 'total_events' => $total_events->fetchColumn() ?: 0, 'students' => $stmt->fetchAll()]);
            break;

        case 'graduate_student':
            if (!$is_admin) exit();
            $pdo->prepare("UPDATE users SET spiritual_status = 'Worker' WHERE id = ?")->execute([$_POST['student_id'] ?? '']);
            $pdo->prepare("UPDATE academy_enrollments SET graduation_status = 'Graduated' WHERE user_id = ? AND batch_id = ?")->execute([$_POST['student_id'] ?? '', $_POST['batch_id'] ?? '']);
            echo json_encode(['status' => 'success', 'message' => 'Student officially promoted to Worker!']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Academy API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A severe server database error occurred. Consult the logs.']);
}
?>