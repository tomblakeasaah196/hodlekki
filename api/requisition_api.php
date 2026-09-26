<?php
// /api/requisition_api.php
// House of David Lekki Centre — Requisition Module API
// Full backend: Submit → Director Stamp → Pastoral Approval → Finance Batch → Disburse
// Architected for Tier-1 Concurrency, Security, and State Machine Integrity.

require_once '../includes/db.php';
header('Content-Type: application/json');

// ============================================================
// SECURITY: Session, Auth Check & CSRF Protection
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}

// CSRF Validation for all state-changing requests
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($is_post && !in_array($action, ['fetch_form_data', 'fetch_my_requisitions', 'fetch_requisition_detail', 'fetch_director_queue', 'fetch_approval_queue', 'fetch_finance_queue', 'fetch_analytics', 'check_signature_vault', 'fetch_accounts_funds'])) {
    $client_csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($client_csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $client_csrf)) {
        exit(json_encode(['status' => 'error', 'message' => 'Security token validation failed. Please refresh the page.']));
    }
}

$user_id    = (int)$_SESSION['user_id'];
$active_role = $_SESSION['active_role'] ?? 'Member';

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/** Get real client IP, accounting for proxies */
function getClientIP(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
        if (!empty($_SERVER[$key])) {
            $candidate = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
                break;
            }
        }
    }
    return $ip;
}

/** Mask IP for public display (privacy-preserving) */
function maskIP(string $ip): string {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return $parts[0] . '.' . $parts[1] . '.xxx.xxx (NG)';
    }
    return substr($ip, 0, 8) . '****';
}

/** Get Finance Department ID dynamically */
function getFinanceDeptId(PDO $pdo): int {
    $stmt = $pdo->query("SELECT id FROM departments WHERE name = 'Finance' AND type = 'Administrative' LIMIT 1");
    return (int)($stmt->fetchColumn() ?: 0);
}

/** Get Finance Director user ID dynamically */
function getFinanceDirectorId(PDO $pdo): int {
    $finance_dept_id = getFinanceDeptId($pdo);
    if (!$finance_dept_id) return 0;
    $stmt = $pdo->prepare("
        SELECT user_id FROM user_departments
        WHERE department_id = ? AND role_in_dept = 'Director' AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$finance_dept_id]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/** Get Resident Pastor user ID dynamically */
function getResidentPastorId(PDO $pdo): int {
    $stmt = $pdo->query("
        SELECT ur.user_id FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE r.role_name = 'Resident_Pastor'
        LIMIT 1
    ");
    return (int)($stmt->fetchColumn() ?: 0);
}

/** Check if current session user has a specific global role */
function hasRole(string $role_name): bool {
    if (!isset($_SESSION['roles']) || !is_array($_SESSION['roles'])) return false;
    foreach ($_SESSION['roles'] as $r) {
        if ($r['role_name'] === $role_name) return true;
    }
    return false;
}

function isSuperAdmin(): bool   { return hasRole('Super_Admin'); }
function isResidentPastor(): bool { return hasRole('Resident_Pastor'); }
function isDirector(): bool     { return hasRole('Director'); }
function isHOD(): bool          { return hasRole('HOD'); }
function isAssocPastor(): bool  { return hasRole('Assoc_Pastor'); }

/** Get department IDs where user is actively a Director */
function getUserDirectorDeptIds(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("
        SELECT department_id FROM user_departments
        WHERE user_id = ? AND role_in_dept = 'Director' AND is_active = 1
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Get departments where user can submit requisitions */
function getUserSubmittableDepts(PDO $pdo, int $user_id): array {
    if (isSuperAdmin() || isResidentPastor()) {
        return $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT d.id, d.name
        FROM user_departments ud
        JOIN departments d ON ud.department_id = d.id
        WHERE ud.user_id = ? AND ud.is_active = 1
          AND ud.role_in_dept IN ('HOD','Director','Assoc_Pastor')
        ORDER BY d.name ASC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Generate a unique, department-scoped reference number.
 * Uses lockless predictive formatting, but must be checked for unique constraints at insert.
 */
function generateRefNumber(PDO $pdo, string $dept_name, string $type, ?int $month, ?int $year): string {
    $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $dept_name));
    $code = substr($code, 0, 6);

    if ($type === 'Monthly' && $month && $year) {
        $mon   = strtoupper(date('M', mktime(0, 0, 0, $month, 1)));
        $yr    = substr((string)$year, 2);
        $prefix = "{$code}-{$mon}{$yr}-";
    } else {
        $yr    = substr(date('Y'), 2);
        $prefix = "{$code}-EVT-{$yr}-";
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM requisition_headers WHERE ref_number LIKE ?");
    $stmt->execute(["{$prefix}%"]);
    $seq = str_pad((int)$stmt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

    return $prefix . $seq;
}

/**
 * Generate SHA-256 hash for a digital stamp utilizing environment-secured salts.
 */
function generateStampHash(int $entity_id, int $user_id, string $timestamp, float $total): string {
    $salt = $_ENV['APP_SECURE_SALT'] ?? 'FALLBACK-INSECURE-SALT-2026';
    return hash('sha256', "{$entity_id}|{$user_id}|{$timestamp}|{$total}|{$salt}");
}

/** Generate a short human-readable verify code */
function generateVerifyCode(int $user_id, int $entity_id, int $unix_ts): string {
    $raw = strtoupper(hash('md5', "HODLC-{$user_id}-{$entity_id}-{$unix_ts}"));
    return 'VRF-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
}

/** Build a full digital stamp data array */
function buildStampData(
    array  $stamper,
    string $role_label,
    string $action_label,
    string $ref,
    float  $total,
    string $ip,
    string $hash_code,
    string $verify_code,
    bool   $is_auto = false
): array {
    return [
        'name'            => $stamper['first_name'] . ' ' . $stamper['last_name'],
        'role'            => $role_label . ', House of David Lekki Centre',
        'church'          => 'House of David Lekki Centre',
        'action'          => $action_label,
        'date_time'       => date('d M Y \—\ H:i:s') . ' WAT',
        'ip_address'      => maskIP($ip),
        'ip_raw'          => $ip,
        'device'          => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device', 0, 120),
        'hash'            => $hash_code,
        'verify_code'     => $verify_code,
        'reference'       => $ref,
        'total_validated' => '₦' . number_format($total, 2),
        'status'          => 'VALID & BINDING',
        'is_auto'         => $is_auto,
    ];
}

/** Insert a system notification */
function notify(PDO $pdo, int $user_id, string $title, string $message, string $link = '/modules/requisition/index.php'): void {
    $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)")
        ->execute([$user_id, $title, $message, $link]);
}

// ============================================================
// RESOLVE ROLE FLAGS FOR THIS SESSION
// ============================================================
$is_super_admin      = isSuperAdmin();
$is_resident_pastor  = isResidentPastor();
$is_finance_director = ((int)getFinanceDirectorId($pdo) === $user_id);
$is_director         = isDirector();
$is_hod              = isHOD();
$is_assoc_pastor     = isAssocPastor();
$can_submit          = $is_super_admin || $is_resident_pastor || $is_director || $is_hod || $is_assoc_pastor;

// ============================================================
// MAIN DISPATCH
// ============================================================
try {
    switch ($action) {

        // ====================================================================
        // fetch_form_data
        // ====================================================================
        case 'fetch_form_data':
            $my_depts   = getUserSubmittableDepts($pdo, $user_id);
            $categories = $pdo->query("
                SELECT id, category_name FROM finance_categories
                WHERE type = 'Expense' AND is_active = 1 ORDER BY category_name ASC
            ")->fetchAll();

            $day = (int)date('j');
            $in_window      = ($day >= 25 || $day <= 5);
            $window_warning = $in_window ? null
                : 'Monthly requisitions are ideally submitted between the 25th of the previous month and the 5th of the current month. Your submission will be accepted, but may affect processing timelines.';

            $has_stored_sig = false;
            if ($is_resident_pastor || $is_super_admin) {
                $s = $pdo->prepare("SELECT id FROM pastor_signature_vault WHERE user_id = ? AND is_active = 1 LIMIT 1");
                $s->execute([$user_id]);
                $has_stored_sig = (bool)$s->fetch();
            }

            echo json_encode([
                'status'               => 'success',
                'my_departments'       => $my_depts,
                'expense_categories'   => $categories,
                'window_warning'       => $window_warning,
                'in_window'            => $in_window,
                'has_stored_signature' => $has_stored_sig,
                'flags' => [
                    'is_super_admin'      => $is_super_admin,
                    'is_resident_pastor'  => $is_resident_pastor,
                    'is_finance_director' => $is_finance_director,
                    'is_director'         => $is_director,
                    'is_hod'              => $is_hod,
                    'can_submit'          => $can_submit,
                ],
            ]);
            break;


        // ====================================================================
        // submit_requisition
        // Added concurrency handling and file uploads.
        // ====================================================================
        case 'submit_requisition':
            if (!$can_submit) {
                exit(json_encode(['status' => 'error', 'message' => 'You do not have permission to submit requisitions.']));
            }

            $dept_id       = (int)($_POST['department_id'] ?? 0);
            $type          = in_array($_POST['type'] ?? '', ['Monthly','Occasional']) ? $_POST['type'] : 'Monthly';
            $period_month  = $type === 'Monthly'    ? (int)($_POST['period_month'] ?? date('n')) : null;
            $period_year   = $type === 'Monthly'    ? (int)($_POST['period_year']  ?? date('Y')) : null;
            $event_label   = $type === 'Occasional' ? trim($_POST['event_label']   ?? '')        : null;
            $sub_note      = trim($_POST['submission_note'] ?? '');
            $items_json    = $_POST['items'] ?? '[]';

            if (!$dept_id) exit(json_encode(['status'=>'error','message'=>'Please select a department.']));
            if ($type === 'Occasional' && empty($event_label)) {
                exit(json_encode(['status'=>'error','message'=>'Please describe the event or purpose for this occasional requisition.']));
            }
            if ($type === 'Monthly' && ($period_month < 1 || $period_month > 12)) {
                exit(json_encode(['status'=>'error','message'=>'Invalid month selected.']));
            }

            $deptStmt = $pdo->prepare("SELECT id, name FROM departments WHERE id = ?");
            $deptStmt->execute([$dept_id]);
            $dept = $deptStmt->fetch();
            if (!$dept) exit(json_encode(['status'=>'error','message'=>'Department not found.']));

            if (!$is_super_admin && !$is_resident_pastor) {
                $accChk = $pdo->prepare("
                    SELECT id FROM user_departments
                    WHERE user_id = ? AND department_id = ? AND is_active = 1
                      AND role_in_dept IN ('HOD','Director','Assoc_Pastor')
                ");
                $accChk->execute([$user_id, $dept_id]);
                if (!$accChk->fetch()) {
                    exit(json_encode(['status'=>'error','message'=>'You are not authorised to submit for this department.']));
                }
            }

            if ($type === 'Monthly') {
                $dupChk = $pdo->prepare("
                    SELECT ref_number FROM requisition_headers
                    WHERE department_id = ? AND type = 'Monthly'
                      AND period_month = ? AND period_year = ?
                      AND status NOT IN ('Rejected', 'Cancelled')
                    LIMIT 1
                ");
                $dupChk->execute([$dept_id, $period_month, $period_year]);
                $dup = $dupChk->fetch();
                if ($dup) {
                    $mon_label = date('F Y', mktime(0, 0, 0, $period_month, 1, $period_year));
                    exit(json_encode([
                        'status'  => 'error',
                        'message' => "A monthly requisition ({$dup['ref_number']}) already exists for {$dept['name']} — {$mon_label}. Check the existing submission.",
                    ]));
                }
            }

            $items = json_decode($items_json, true);
            if (!is_array($items) || count($items) === 0) {
                exit(json_encode(['status'=>'error','message'=>'At least one expense line item is required.']));
            }

            $total_amount = 0.0;
            foreach ($items as &$itm) {
                $itm['item_description']    = trim($itm['item_description'] ?? '');
                $itm['quantity']            = floatval($itm['quantity'] ?? 1);
                $itm['unit_cost']           = floatval($itm['unit_cost'] ?? 0);
                $itm['total_amount']        = round($itm['quantity'] * $itm['unit_cost'], 2);
                $itm['finance_category_id'] = !empty($itm['finance_category_id']) ? (int)$itm['finance_category_id'] : null;

                if (empty($itm['item_description'])) exit(json_encode(['status'=>'error','message'=>'All line items must have a description.']));
                if ($itm['unit_cost'] <= 0) exit(json_encode(['status'=>'error','message'=>"Line item \"{$itm['item_description']}\" must have a cost greater than zero."]));
                $total_amount += $itm['total_amount'];
            }
            unset($itm);

            // Strictly check if the submitter is the actual Director of this department, no exceptions.
            $dirChk = $pdo->prepare("SELECT id FROM user_departments WHERE user_id = ? AND department_id = ? AND role_in_dept = 'Director' AND is_active = 1");
            $dirChk->execute([$user_id, $dept_id]);
            $is_dir_of_dept = (bool)$dirChk->fetch();

            $initial_status = $is_dir_of_dept ? 'Pending_Pastor' : 'Pending_Director';
            $now            = date('Y-m-d H:i:s');
            
            // Handle File Uploads (Proof of Cost)
            $uploaded_files = [];
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = '../uploads/requisitions/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $allowed_types = ['application/pdf', 'image/jpeg', 'image/png'];
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    $file_name = $_FILES['attachments']['name'][$key];
                    $file_size = $_FILES['attachments']['size'][$key];
                    $file_tmp  = $_FILES['attachments']['tmp_name'][$key];
                    $file_type = mime_content_type($file_tmp);
                    
                    if (!in_array($file_type, $allowed_types)) {
                        exit(json_encode(['status'=>'error', 'message'=>"Invalid file type: {$file_name}. Only PDF, JPG, and PNG are allowed."]));
                    }
                    if ($file_size > 5242880) { // 5MB limit
                        exit(json_encode(['status'=>'error', 'message'=>"File too large: {$file_name}. Maximum size is 5MB."]));
                    }
                    
                    $new_file_name = uniqid('REQ_ATT_') . '_' . preg_replace('/[^A-Za-z0-9.\-]/', '', $file_name);
                    $dest_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($file_tmp, $dest_path)) {
                        $uploaded_files[] = [
                            'file_name' => $file_name,
                            'file_path' => '/uploads/requisitions/' . $new_file_name,
                            'file_type' => $file_type
                        ];
                    }
                }
            }

            // Concurrency robust insertion loop (solves Race Condition)
            $max_retries = 3;
            $attempt = 0;
            $req_id = 0;
            $ref_number = '';
            
            while ($attempt < $max_retries && $req_id === 0) {
                try {
                    $pdo->beginTransaction();
                    $ref_number = generateRefNumber($pdo, $dept['name'], $type, $period_month, $period_year);
                    
                    $hStmt = $pdo->prepare("
                        INSERT INTO requisition_headers
                            (ref_number, department_id, submitted_by, type, period_month, period_year,
                             event_label, total_amount, status, submission_note, director_validated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $hStmt->execute([
                        $ref_number, $dept_id, $user_id, $type,
                        $period_month, $period_year, $event_label,
                        $total_amount, $initial_status, $sub_note,
                        $is_dir_of_dept ? $now : null,
                    ]);
                    $req_id = (int)$pdo->lastInsertId();
                    
                    // Insert items
                    $iStmt = $pdo->prepare("
                        INSERT INTO requisition_items
                            (requisition_id, item_description, quantity, unit_cost, total_amount, finance_category_id, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'Pending')
                    ");
                    foreach ($items as $itm) {
                        $iStmt->execute([
                            $req_id, $itm['item_description'], $itm['quantity'],
                            $itm['unit_cost'], $itm['total_amount'], $itm['finance_category_id'],
                        ]);
                    }

                    // Insert Attachments
                    if (!empty($uploaded_files)) {
                        $aStmt = $pdo->prepare("INSERT INTO requisition_attachments (requisition_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");
                        foreach ($uploaded_files as $file) {
                            $aStmt->execute([$req_id, $file['file_name'], $file['file_path'], $file['file_type']]);
                        }
                    }

                    $pdo->commit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    if ($e->getCode() == 23000) { // Integrity constraint violation (duplicate key on ref_number)
                        $attempt++;
                        usleep(100000); // 100ms backoff
                    } else {
                        throw $e;
                    }
                }
            }

            if ($req_id === 0) {
                exit(json_encode(['status'=>'error', 'message'=>'High traffic on department submissions. Please try again in a moment.']));
            }

            // Post-insertion workflow triggers
            $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $uStmt->execute([$user_id]);
            $stamper = $uStmt->fetch();

            $period_label = $type === 'Monthly'
                ? date('F Y', mktime(0, 0, 0, $period_month, 1, $period_year))
                : $event_label;

            if ($is_dir_of_dept) {
                $pdo->beginTransaction();
                $ip          = getClientIP();
                $hash_code   = generateStampHash($req_id, $user_id, $now, $total_amount);
                $verify_code = generateVerifyCode($user_id, $req_id, time());
                $stamp_data  = buildStampData(
                    $stamper, 'Director',
                    'Auto-Validated — Director Submission',
                    $ref_number, $total_amount, $ip, $hash_code, $verify_code, true
                );

                $pdo->prepare("
                    INSERT INTO requisition_director_stamps
                        (requisition_id, stamped_by, stamped_at, ip_address, user_agent, hash_code, verify_code, stamp_json, is_auto)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ")->execute([
                    $req_id, $user_id, $now, $ip,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    $hash_code, $verify_code, json_encode($stamp_data),
                ]);

                $pdo->prepare("UPDATE requisition_items SET status = 'Approved' WHERE requisition_id = ?")->execute([$req_id]);
                $pdo->commit();

                $pastor_id = getResidentPastorId($pdo);
                if ($pastor_id) {
                    notify($pdo, $pastor_id,
                        'Requisition Awaiting Pastoral Approval',
                        "{$stamper['first_name']} {$stamper['last_name']} submitted and auto-validated requisition {$ref_number} ({$dept['name']} — {$period_label}) totalling ₦" . number_format($total_amount, 2) . ". Awaiting your approval."
                    );
                }
            } else {
                $dStmt = $pdo->prepare("
                    SELECT ud.user_id FROM user_departments ud
                    WHERE ud.department_id = ? AND ud.role_in_dept = 'Director' AND ud.is_active = 1
                ");
                $dStmt->execute([$dept_id]);
                $directors = $dStmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($directors as $dir_id) {
                    notify($pdo, (int)$dir_id,
                        'New Requisition Awaiting Your Validation',
                        "{$stamper['first_name']} {$stamper['last_name']} submitted requisition {$ref_number} for {$dept['name']} ({$period_label}) — ₦" . number_format($total_amount, 2) . ". Please review and validate."
                    );
                }

                if (empty($directors)) {
                    $admins = $pdo->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE r.role_name = 'Super_Admin'")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($admins as $a_id) {
                        notify($pdo, (int)$a_id,
                            'Requisition Needs Director Assignment',
                            "Requisition {$ref_number} was submitted for {$dept['name']} but no active Director is assigned. Please validate or assign a Director."
                        );
                    }
                }
            }

            echo json_encode([
                'status'         => 'success',
                'message'        => "Requisition {$ref_number} submitted successfully." . ($is_dir_of_dept ? ' Auto-validated as Director.' : ' Awaiting Director validation.'),
                'ref_number'     => $ref_number,
                'req_id'         => $req_id,
                'auto_validated' => $is_dir_of_dept,
            ]);
            break;


        // ====================================================================
        // cancel_requisition (NEW - Solves State Machine Dead-End)
        // Submitter can cancel their own requisition before Pastor review.
        // ====================================================================
        case 'cancel_requisition':
            $req_id = (int)($_POST['req_id'] ?? 0);
            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));

            $cStmt = $pdo->prepare("SELECT id, submitted_by, status, ref_number FROM requisition_headers WHERE id = ?");
            $cStmt->execute([$req_id]);
            $req = $cStmt->fetch();

            if (!$req) exit(json_encode(['status'=>'error','message'=>'Requisition not found.']));
            if ($req['submitted_by'] != $user_id && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'You can only cancel your own submissions.']));
            }
            if (!in_array($req['status'], ['Draft', 'Pending_Director', 'Revision_Required'])) {
                exit(json_encode(['status'=>'error','message'=>'This requisition has progressed too far in the pipeline to be cancelled by the submitter.']));
            }

            $pdo->prepare("UPDATE requisition_headers SET status = 'Cancelled', updated_at = NOW() WHERE id = ?")->execute([$req_id]);

            echo json_encode(['status'=>'success', 'message'=>"Requisition {$req['ref_number']} has been successfully cancelled."]);
            break;


        // ====================================================================
        // fetch_my_requisitions
        // Fixed: Added server-side Pagination
        // ====================================================================
        case 'fetch_my_requisitions':
            $filter_status = $_POST['filter_status'] ?? 'all';
            $filter_dept   = (int)($_POST['department_id'] ?? 0);
            $page          = max(1, (int)($_POST['page'] ?? 1));
            $limit         = max(10, min(100, (int)($_POST['limit'] ?? 50)));
            $offset        = ($page - 1) * $limit;

            $where = ['1=1'];
            $params = [];

            if ($is_super_admin || $is_resident_pastor) {
                // Full visibility
            } elseif ($is_finance_director) {
                $where[]  = "(rh.status IN ('Approved','Batched','Disbursed') OR rh.submitted_by = ?)";
                $params[] = $user_id;
            } elseif ($is_director) {
                $dir_depts = getUserDirectorDeptIds($pdo, $user_id);
                if (!empty($dir_depts)) {
                    $ph       = implode(',', array_fill(0, count($dir_depts), '?'));
                    $where[]  = "(rh.department_id IN ({$ph}) OR rh.submitted_by = ?)";
                    $params   = array_merge($params, $dir_depts, [$user_id]);
                } else {
                    $where[]  = 'rh.submitted_by = ?';
                    $params[] = $user_id;
                }
            } else {
                $where[]  = 'rh.submitted_by = ?';
                $params[] = $user_id;
            }

            if ($filter_status !== 'all') {
                $where[]  = 'rh.status = ?';
                $params[] = $filter_status;
            }
            if ($filter_dept > 0) {
                $where[]  = 'rh.department_id = ?';
                $params[] = $filter_dept;
            }

            $where_sql = implode(' AND ', $where);

            // Fetch Paginated Data
            $stmt = $pdo->prepare("
                SELECT
                    rh.id, rh.ref_number, rh.type, rh.status, rh.total_amount,
                    rh.period_month, rh.period_year, rh.event_label,
                    rh.director_validated_at, rh.pastor_approved_at,
                    rh.submission_note, rh.created_at, rh.updated_at,
                    d.name  AS department_name,
                    CONCAT(u.first_name,' ',u.last_name) AS submitted_by_name,
                    u.picture_path AS submitted_by_pic,
                    (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = rh.id)                            AS item_count,
                    (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = rh.id AND ri.status = 'Rejected')   AS rejected_item_count,
                    IF(rds.id IS NOT NULL, 1, 0)   AS has_director_stamp,
                    rds.verify_code                AS director_verify_code,
                    rds.is_auto                    AS director_auto
                FROM requisition_headers rh
                JOIN departments d  ON rh.department_id = d.id
                JOIN users u        ON rh.submitted_by  = u.id
                LEFT JOIN requisition_director_stamps rds ON rh.id = rds.requisition_id
                WHERE {$where_sql}
                ORDER BY rh.updated_at DESC
                LIMIT ? OFFSET ?
            ");
            
            // Bind params explicitly for LIMIT/OFFSET
            $param_index = 1;
            foreach ($params as $param_val) {
                $stmt->bindValue($param_index++, $param_val, is_int($param_val) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue($param_index++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($param_index, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $requisitions = $stmt->fetchAll();

            $cntStmt = $pdo->prepare("
                SELECT status, COUNT(*) AS cnt
                FROM requisition_headers rh
                WHERE {$where_sql}
                GROUP BY status
            ");
            // Re-bind params for count query without limit/offset
            $cntStmt->execute($params);
            $status_counts = [];
            foreach ($cntStmt->fetchAll() as $row) {
                $status_counts[$row['status']] = (int)$row['cnt'];
            }

            $filter_depts = getUserSubmittableDepts($pdo, $user_id);

            echo json_encode([
                'status'        => 'success',
                'requisitions'  => $requisitions,
                'status_counts' => $status_counts,
                'filter_depts'  => $filter_depts,
                'page'          => $page,
                'limit'         => $limit
            ]);
            break;


        // ====================================================================
        // fetch_requisition_detail
        // Extended to include attachments
        // ====================================================================
        case 'fetch_requisition_detail':
            $req_id = (int)($_POST['req_id'] ?? 0);
            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));

            $hStmt = $pdo->prepare("
                SELECT rh.*, d.name AS department_name,
                       CONCAT(u.first_name,' ',u.last_name) AS submitted_by_name,
                       u.picture_path AS submitted_by_pic
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                JOIN users u       ON rh.submitted_by  = u.id
                WHERE rh.id = ?
            ");
            $hStmt->execute([$req_id]);
            $header = $hStmt->fetch();
            if (!$header) exit(json_encode(['status'=>'error','message'=>'Requisition not found.']));

            $iStmt = $pdo->prepare("
                SELECT ri.*, fc.category_name
                FROM requisition_items ri
                LEFT JOIN finance_categories fc ON ri.finance_category_id = fc.id
                WHERE ri.requisition_id = ? ORDER BY ri.id ASC
            ");
            $iStmt->execute([$req_id]);
            $items = $iStmt->fetchAll();
            
            $attStmt = $pdo->prepare("SELECT * FROM requisition_attachments WHERE requisition_id = ?");
            $attStmt->execute([$req_id]);
            $attachments = $attStmt->fetchAll();

            $sStmt = $pdo->prepare("
                SELECT rds.*, CONCAT(u.first_name,' ',u.last_name) AS stamped_by_name
                FROM requisition_director_stamps rds
                JOIN users u ON rds.stamped_by = u.id
                WHERE rds.requisition_id = ?
            ");
            $sStmt->execute([$req_id]);
            $director_stamp = $sStmt->fetch();
            if ($director_stamp) {
                $director_stamp['stamp_data'] = $director_stamp['stamp_json']
                    ? json_decode($director_stamp['stamp_json'], true) : null;
                unset($director_stamp['stamp_json']);
            }

            $pStmt = $pdo->prepare("
                SELECT rpa.id, rpa.action, rpa.notes, rpa.acted_at,
                       IF(rpa.signature_base64 IS NOT NULL AND rpa.signature_base64 != '', 1, 0) AS has_signature,
                       CONCAT(u.first_name,' ',u.last_name) AS pastor_name
                FROM requisition_pastor_actions rpa
                JOIN users u ON rpa.action_by = u.id
                WHERE rpa.requisition_id = ? ORDER BY rpa.acted_at DESC
            ");
            $pStmt->execute([$req_id]);
            $pastor_actions = $pStmt->fetchAll();

            $bStmt = $pdo->prepare("
                SELECT rb.batch_ref, rb.status AS batch_status, rb.exported_at, rb.disbursed_at,
                       CONCAT(u.first_name,' ',u.last_name) AS batched_by_name
                FROM requisition_batch_items rbi
                JOIN requisition_batches rb ON rbi.batch_id = rb.id
                JOIN users u ON rb.created_by = u.id
                WHERE rbi.requisition_id = ? LIMIT 1
            ");
            $bStmt->execute([$req_id]);
            $batch_info = $bStmt->fetch();

            echo json_encode([
                'status'         => 'success',
                'header'         => $header,
                'items'          => $items,
                'attachments'    => $attachments,
                'director_stamp' => $director_stamp,
                'pastor_actions' => $pastor_actions,
                'batch_info'     => $batch_info,
            ]);
            break;


        // ====================================================================
        // fetch_director_queue
        // ====================================================================
        case 'fetch_director_queue':
            if (!$is_director && !$is_super_admin && !$is_resident_pastor) {
                exit(json_encode(['status'=>'error','message'=>'Director access required.']));
            }

            $params      = ['Pending_Director'];
            $dept_filter = '';

            if (!$is_super_admin && !$is_resident_pastor) {
                $dir_depts = getUserDirectorDeptIds($pdo, $user_id);
                if (empty($dir_depts)) {
                    echo json_encode(['status'=>'success','queue'=>[]]);
                    break;
                }
                $ph          = implode(',', array_fill(0, count($dir_depts), '?'));
                $dept_filter = "AND rh.department_id IN ({$ph})";
                $params      = array_merge($params, $dir_depts);
            }

            $stmt = $pdo->prepare("
                SELECT rh.id, rh.ref_number, rh.type, rh.total_amount,
                       rh.period_month, rh.period_year, rh.event_label,
                       rh.submission_note, rh.created_at,
                       d.name AS department_name,
                       CONCAT(u.first_name,' ',u.last_name) AS submitted_by_name,
                       u.picture_path AS submitted_by_pic,
                       (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = rh.id) AS item_count
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                JOIN users u       ON rh.submitted_by  = u.id
                WHERE rh.status = ? {$dept_filter}
                ORDER BY rh.created_at ASC
            ");
            $stmt->execute($params);
            echo json_encode(['status'=>'success','queue'=>$stmt->fetchAll()]);
            break;


        // ====================================================================
        // validate_director
        // ====================================================================
        case 'validate_director':
            if (!$is_director && !$is_super_admin && !$is_resident_pastor) {
                exit(json_encode(['status'=>'error','message'=>'Director authority required.']));
            }

            $req_id = (int)($_POST['req_id'] ?? 0);
            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));

            $rStmt = $pdo->prepare("
                SELECT rh.*, d.name AS dept_name
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                WHERE rh.id = ? AND rh.status = 'Pending_Director'
            ");
            $rStmt->execute([$req_id]);
            $req = $rStmt->fetch();
            if (!$req) exit(json_encode(['status'=>'error','message'=>'Requisition not found or not in a validatable state.']));

            if (!$is_super_admin && !$is_resident_pastor) {
                $dir_depts = getUserDirectorDeptIds($pdo, $user_id);
                if (!in_array($req['department_id'], $dir_depts)) {
                    exit(json_encode(['status'=>'error','message'=>'You are not the Director of this department.']));
                }
            }

            $exStmt = $pdo->prepare("SELECT id FROM requisition_director_stamps WHERE requisition_id = ?");
            $exStmt->execute([$req_id]);
            if ($exStmt->fetch()) exit(json_encode(['status'=>'error','message'=>'This requisition has already been validated.']));

            $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $uStmt->execute([$user_id]);
            $stamper = $uStmt->fetch();

            $ip          = getClientIP();
            $now         = date('Y-m-d H:i:s');
            $hash_code   = generateStampHash($req_id, $user_id, $now, (float)$req['total_amount']);
            $verify_code = generateVerifyCode($user_id, $req_id, time());
            $stamp_data  = buildStampData(
                $stamper, 'Director',
                'Approved & Validated',
                $req['ref_number'], (float)$req['total_amount'], $ip, $hash_code, $verify_code
            );

            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO requisition_director_stamps
                    (requisition_id, stamped_by, stamped_at, ip_address, user_agent, hash_code, verify_code, stamp_json, is_auto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
            ")->execute([
                $req_id, $user_id, $now, $ip,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                $hash_code, $verify_code, json_encode($stamp_data),
            ]);

            $pdo->prepare("UPDATE requisition_items SET status = 'Approved' WHERE requisition_id = ?")->execute([$req_id]);
            $pdo->prepare("UPDATE requisition_headers SET status = 'Pending_Pastor', director_validated_at = ? WHERE id = ?")->execute([$now, $req_id]);

            $pastor_id    = getResidentPastorId($pdo);
            $period_label = $req['type'] === 'Monthly'
                ? date('F Y', mktime(0, 0, 0, $req['period_month'], 1, $req['period_year']))
                : $req['event_label'];

            if ($pastor_id) {
                notify($pdo, $pastor_id,
                    'Requisition Validated — Awaiting Your Approval',
                    "{$stamper['first_name']} {$stamper['last_name']} validated requisition {$req['ref_number']} ({$req['dept_name']} — {$period_label}) for ₦" . number_format($req['total_amount'], 2) . ". Please review and approve."
                );
            }

            $pdo->commit();
            echo json_encode([
                'status'      => 'success',
                'message'     => "Requisition {$req['ref_number']} validated. Digital stamp applied.",
                'verify_code' => $verify_code,
            ]);
            break;


        // ====================================================================
        // reject_director (NEW - Solves State Machine Dead-End)
        // Director fully rejects a submission back to the submitter.
        // ====================================================================
        case 'reject_director':
            if (!$is_director && !$is_super_admin && !$is_resident_pastor) {
                exit(json_encode(['status'=>'error','message'=>'Director authority required.']));
            }

            $req_id = (int)($_POST['req_id'] ?? 0);
            $reason = trim($_POST['rejection_reason'] ?? '');
            
            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));
            if (empty($reason)) exit(json_encode(['status'=>'error','message'=>'A rejection reason is mandatory.']));

            $rStmt = $pdo->prepare("
                SELECT rh.*, d.name AS dept_name
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                WHERE rh.id = ? AND rh.status = 'Pending_Director'
            ");
            $rStmt->execute([$req_id]);
            $req = $rStmt->fetch();
            if (!$req) exit(json_encode(['status'=>'error','message'=>'Requisition not found or already processed.']));

            if (!$is_super_admin && !$is_resident_pastor) {
                $dir_depts = getUserDirectorDeptIds($pdo, $user_id);
                if (!in_array($req['department_id'], $dir_depts)) {
                    exit(json_encode(['status'=>'error','message'=>'You are not the Director of this department.']));
                }
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE requisition_headers SET status = 'Rejected', updated_at = NOW() WHERE id = ?")->execute([$req_id]);
            
            // Insert rejection note as an action trail (utilizing pastor action table logically for trail, or system notes)
            $pdo->prepare("INSERT INTO requisition_pastor_actions (requisition_id, action_by, action, notes, acted_at) VALUES (?, ?, 'Rejected', ?, NOW())")
                ->execute([$req_id, $user_id, "Director Rejection: " . $reason]);

            notify($pdo, $req['submitted_by'],
                'Requisition Rejected by Director',
                "Your requisition {$req['ref_number']} ({$req['dept_name']}) was rejected by the Director. Reason: {$reason}"
            );

            $pdo->commit();
            echo json_encode(['status'=>'success', 'message'=>"Requisition {$req['ref_number']} has been rejected."]);
            break;


        // ====================================================================
        // fetch_approval_queue
        // ====================================================================
        case 'fetch_approval_queue':
            if (!$is_resident_pastor && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Pastoral authority required.']));
            }

            $filter_tab = $_POST['filter'] ?? 'pending';
            $status_sql = match($filter_tab) {
                'pending'  => "AND rh.status = 'Pending_Pastor'",
                'revision' => "AND rh.status = 'Revision_Required'",
                default    => "AND rh.status IN ('Pending_Pastor','Revision_Required')",
            };

            $stmt = $pdo->query("
                SELECT
                    rh.id, rh.ref_number, rh.type, rh.total_amount, rh.status,
                    rh.period_month, rh.period_year, rh.event_label,
                    rh.submission_note, rh.director_validated_at, rh.created_at,
                    d.name AS department_name,
                    CONCAT(sub.first_name,' ',sub.last_name) AS submitted_by_name,
                    sub.picture_path AS submitted_by_pic,
                    rds.verify_code  AS director_verify_code,
                    rds.is_auto      AS director_auto,
                    CONCAT(dir.first_name,' ',dir.last_name) AS director_name,
                    (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = rh.id)                            AS item_count,
                    (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = rh.id AND ri.status='Rejected')   AS rejected_item_count
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                JOIN users sub     ON rh.submitted_by  = sub.id
                LEFT JOIN requisition_director_stamps rds ON rh.id = rds.requisition_id
                LEFT JOIN users dir ON rds.stamped_by = dir.id
                WHERE 1=1 {$status_sql}
                ORDER BY rh.director_validated_at ASC
            ");
            echo json_encode(['status'=>'success','queue'=>$stmt->fetchAll()]);
            break;


        // ====================================================================
        // get_signature_for_approval
        // ====================================================================
        case 'get_signature_for_approval':
            if (!$is_resident_pastor && !$is_super_admin) exit(json_encode(['status'=>'error','message'=>'Access denied.']));
            $pin = $_POST['pin'] ?? '';
            if (empty($pin)) exit(json_encode(['status'=>'error','message'=>'PIN required.']));

            $vStmt = $pdo->prepare("SELECT * FROM pastor_signature_vault WHERE user_id = ? AND is_active = 1");
            $vStmt->execute([$user_id]);
            $vault = $vStmt->fetch();
            if (!$vault) exit(json_encode(['status'=>'error','message'=>'No stored signature found. Please draw and save your signature first.']));
            if (!password_verify($pin, $vault['pin_hash'])) exit(json_encode(['status'=>'error','message'=>'Incorrect PIN.']));

            $pdo->prepare("UPDATE pastor_signature_vault SET last_used_at = NOW() WHERE user_id = ?")->execute([$user_id]);
            echo json_encode(['status'=>'success','signature_base64'=>$vault['signature_base64']]);
            break;


        // ====================================================================
        // pastor_approve
        // ====================================================================
        case 'pastor_approve':
            if (!$is_resident_pastor && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Only the Resident Pastor can approve requisitions.']));
            }

            $req_id          = (int)($_POST['req_id'] ?? 0);
            $signature_b64   = $_POST['signature_base64'] ?? '';
            $notes           = trim($_POST['notes'] ?? '');

            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));
            if (empty($signature_b64)) exit(json_encode(['status'=>'error','message'=>'A signature is required for approval.']));
            if (!str_starts_with($signature_b64, 'data:image/')) {
                exit(json_encode(['status'=>'error','message'=>'Invalid signature format.']));
            }

            $rStmt = $pdo->prepare("
                SELECT rh.*, d.name AS dept_name, u.id AS submitter_id,
                       CONCAT(u.first_name,' ',u.last_name) AS submitter_name
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                JOIN users u ON rh.submitted_by = u.id
                WHERE rh.id = ? AND rh.status IN ('Pending_Pastor','Revision_Required')
            ");
            $rStmt->execute([$req_id]);
            $req = $rStmt->fetch();
            if (!$req) exit(json_encode(['status'=>'error','message'=>'Requisition not found or not awaiting pastoral approval.']));

            $now = date('Y-m-d H:i:s');
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO requisition_pastor_actions (requisition_id, action_by, action, signature_base64, notes, acted_at)
                VALUES (?, ?, 'Approved', ?, ?, ?)
            ")->execute([$req_id, $user_id, $signature_b64, $notes, $now]);

            $pdo->prepare("UPDATE requisition_items SET status = 'Approved' WHERE requisition_id = ? AND status = 'Pending'")->execute([$req_id]);
            $pdo->prepare("UPDATE requisition_headers SET status = 'Approved', pastor_approved_at = ? WHERE id = ?")->execute([$now, $req_id]);

            $fin_dir_id = getFinanceDirectorId($pdo);
            $period_label = $req['type'] === 'Monthly'
                ? date('F Y', mktime(0, 0, 0, $req['period_month'], 1, $req['period_year']))
                : $req['event_label'];

            if ($fin_dir_id) {
                notify($pdo, $fin_dir_id,
                    'Requisition Approved — Ready for Finance Batch',
                    "The Resident Pastor approved requisition {$req['ref_number']} ({$req['dept_name']} — {$period_label}) for ₦" . number_format($req['total_amount'], 2) . ". Please include it in the next Finance batch."
                );
            }
            notify($pdo, $req['submitter_id'],
                'Your Requisition Has Been Approved ✓',
                "Requisition {$req['ref_number']} was approved by the Resident Pastor and is now in the Finance queue for disbursement."
            );

            $pdo->commit();
            echo json_encode(['status'=>'success','message'=>"Requisition {$req['ref_number']} approved. Pastoral signature recorded."]);
            break;


        // ====================================================================
        // pastor_reject_lines
        // ====================================================================
        case 'pastor_reject_lines':
            if (!$is_resident_pastor && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Only the Resident Pastor can request revisions.']));
            }

            $req_id          = (int)($_POST['req_id'] ?? 0);
            $rejections      = json_decode($_POST['rejections'] ?? '[]', true);
            $overall_notes   = trim($_POST['notes'] ?? '');

            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));
            if (!is_array($rejections) || empty($rejections)) {
                exit(json_encode(['status'=>'error','message'=>'At least one rejected line with a reason is required.']));
            }
            foreach ($rejections as $rej) {
                if (empty(trim($rej['reason'] ?? ''))) {
                    exit(json_encode(['status'=>'error','message'=>'Every rejected line must have a stated reason.']));
                }
            }

            $rStmt = $pdo->prepare("
                SELECT rh.*, d.name AS dept_name, u.id AS submitter_id
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                JOIN users u ON rh.submitted_by = u.id
                WHERE rh.id = ? AND rh.status IN ('Pending_Pastor','Revision_Required')
            ");
            $rStmt->execute([$req_id]);
            $req = $rStmt->fetch();
            if (!$req) exit(json_encode(['status'=>'error','message'=>'Requisition not found or not in a reviewable state.']));

            $now = date('Y-m-d H:i:s');
            $pdo->beginTransaction();

            $rejStmt = $pdo->prepare("
                UPDATE requisition_items SET status = 'Rejected', rejection_reason = ?
                WHERE id = ? AND requisition_id = ?
            ");
            $reasons = [];
            foreach ($rejections as $rej) {
                $item_id = (int)($rej['item_id'] ?? 0);
                $reason  = trim($rej['reason'] ?? '');
                if ($item_id && $reason) {
                    $rejStmt->execute([$reason, $item_id, $req_id]);
                    $reasons[] = $reason;
                }
            }

            $pdo->prepare("UPDATE requisition_items SET status = 'Pending' WHERE requisition_id = ? AND status = 'Approved'")->execute([$req_id]);

            $pdo->prepare("
                INSERT INTO requisition_pastor_actions (requisition_id, action_by, action, notes, acted_at)
                VALUES (?, ?, 'Partial_Revision', ?, ?)
            ")->execute([$req_id, $user_id, $overall_notes ?: implode('; ', $reasons), $now]);

            $pdo->prepare("UPDATE requisition_headers SET status = 'Revision_Required', updated_at = ? WHERE id = ?")->execute([$now, $req_id]);

            notify($pdo, $req['submitter_id'],
                'Requisition Revision Required',
                "The Resident Pastor flagged " . count($rejections) . " line(s) on requisition {$req['ref_number']} ({$req['dept_name']}) for revision. Reason(s): " . implode('; ', $reasons) . ". Please revise and resubmit."
            );

            $pdo->commit();
            echo json_encode(['status'=>'success','message'=>count($rejections) . " line(s) flagged. Submitter has been notified."]);
            break;

        
        // ====================================================================
        // pastor_reject_full (NEW - Solves State Machine Dead-End)
        // Pastor kills the requisition outright.
        // ====================================================================
        case 'pastor_reject_full':
            if (!$is_resident_pastor && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Only the Resident Pastor can fully reject requisitions.']));
            }

            $req_id = (int)($_POST['req_id'] ?? 0);
            $reason = trim($_POST['notes'] ?? '');

            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));
            if (empty($reason)) exit(json_encode(['status'=>'error','message'=>'A rejection reason is mandatory.']));

            $rStmt = $pdo->prepare("
                SELECT rh.*, d.name AS dept_name
                FROM requisition_headers rh
                JOIN departments d ON rh.department_id = d.id
                WHERE rh.id = ? AND rh.status IN ('Pending_Pastor', 'Revision_Required')
            ");
            $rStmt->execute([$req_id]);
            $req = $rStmt->fetch();
            if (!$req) exit(json_encode(['status'=>'error','message'=>'Requisition not found or not in a reviewable state.']));

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE requisition_headers SET status = 'Rejected', updated_at = NOW() WHERE id = ?")->execute([$req_id]);
            
            $pdo->prepare("INSERT INTO requisition_pastor_actions (requisition_id, action_by, action, notes, acted_at) VALUES (?, ?, 'Rejected', ?, NOW())")
                ->execute([$req_id, $user_id, $reason]);

            notify($pdo, $req['submitted_by'],
                'Requisition Fully Rejected',
                "Your requisition {$req['ref_number']} ({$req['dept_name']}) was fully rejected by the Resident Pastor. Reason: {$reason}"
            );

            $pdo->commit();
            echo json_encode(['status'=>'success', 'message'=>"Requisition {$req['ref_number']} has been fully rejected."]);
            break;


        // ====================================================================
        // resubmit_revised
        // ====================================================================
        case 'resubmit_revised':
            $req_id        = (int)($_POST['req_id'] ?? 0);
            $revised_items = json_decode($_POST['revised_items'] ?? '[]', true);

            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));
            if (!is_array($revised_items) || empty($revised_items)) {
                exit(json_encode(['status'=>'error','message'=>'No revised items provided.']));
            }

            $rStmt = $pdo->prepare("
                SELECT rh.*, d.name AS dept_name
                FROM requisition_headers rh JOIN departments d ON rh.department_id = d.id
                WHERE rh.id = ? AND rh.status = 'Revision_Required'
            ");
            $rStmt->execute([$req_id]);
            $req = $rStmt->fetch();
            if (!$req) exit(json_encode(['status'=>'error','message'=>'Requisition not found or not in revision state.']));

            if ($req['submitted_by'] != $user_id && !$is_super_admin && !$is_resident_pastor) {
                $dir_depts = getUserDirectorDeptIds($pdo, $user_id);
                if (!in_array($req['department_id'], $dir_depts)) {
                    exit(json_encode(['status'=>'error','message'=>'You can only revise your own submissions.']));
                }
            }

            $pdo->beginTransaction();

            $updStmt = $pdo->prepare("
                UPDATE requisition_items
                SET item_description = ?, quantity = ?, unit_cost = ?, total_amount = ?,
                    status = 'Pending', rejection_reason = NULL
                WHERE id = ? AND requisition_id = ? AND status = 'Rejected'
            ");
            foreach ($revised_items as $itm) {
                $item_id = (int)($itm['item_id'] ?? 0);
                $desc    = trim($itm['item_description'] ?? '');
                $qty     = floatval($itm['quantity'] ?? 1);
                $cost    = floatval($itm['unit_cost']  ?? 0);
                $total   = round($qty * $cost, 2);
                if ($item_id && $desc && $cost > 0) {
                    $updStmt->execute([$desc, $qty, $cost, $total, $item_id, $req_id]);
                }
            }

            $totStmt = $pdo->prepare("SELECT SUM(total_amount) FROM requisition_items WHERE requisition_id = ?");
            $totStmt->execute([$req_id]);
            $new_total = (float)$totStmt->fetchColumn();

            $pdo->prepare("UPDATE requisition_headers SET status='Pending_Pastor', total_amount=?, updated_at=NOW() WHERE id=?")->execute([$new_total, $req_id]);

            $pastor_id = getResidentPastorId($pdo);
            if ($pastor_id) {
                notify($pdo, $pastor_id,
                    'Revised Requisition Ready for Review',
                    "Requisition {$req['ref_number']} ({$req['dept_name']}) has been revised and resubmitted. The flagged lines have been updated for your review."
                );
            }

            $pdo->commit();
            echo json_encode(['status'=>'success','message'=>'Revision submitted. The Resident Pastor will be notified.']);
            break;


        // ====================================================================
        // fetch_finance_queue
        // ====================================================================
        case 'fetch_finance_queue':
            if (!$is_finance_director && !$is_super_admin && !$is_resident_pastor) {
                exit(json_encode(['status'=>'error','message'=>'Finance Director access required.']));
            }

            $available = $pdo->query("
                SELECT
                    rh.id, rh.ref_number, rh.type, rh.total_amount,
                    rh.period_month, rh.period_year, rh.event_label,
                    rh.pastor_approved_at, rh.director_validated_at,
                    d.name AS department_name,
                    CONCAT(sub.first_name,' ',sub.last_name) AS submitted_by_name,
                    rds.verify_code  AS director_verify_code,
                    CONCAT(dir.first_name,' ',dir.last_name) AS director_name
                FROM requisition_headers rh
                JOIN departments d  ON rh.department_id = d.id
                JOIN users sub      ON rh.submitted_by  = sub.id
                LEFT JOIN requisition_director_stamps rds ON rh.id = rds.requisition_id
                LEFT JOIN users dir ON rds.stamped_by = dir.id
                WHERE rh.status = 'Approved'
                ORDER BY rh.pastor_approved_at ASC
            ")->fetchAll();

            $batches = $pdo->query("
                SELECT
                    rb.id, rb.batch_ref, rb.batch_label, rb.status, rb.total_amount,
                    rb.created_at, rb.exported_at, rb.disbursed_at,
                    rb.finance_stamp_verify_code, rb.finance_stamp_at,
                    CONCAT(u.first_name,' ',u.last_name) AS created_by_name,
                    (SELECT COUNT(*) FROM requisition_batch_items rbi WHERE rbi.batch_id = rb.id) AS item_count
                FROM requisition_batches rb
                JOIN users u ON rb.created_by = u.id
                ORDER BY rb.created_at DESC
                LIMIT 50
            ")->fetchAll();

            $draft_items = $pdo->query("
                SELECT rbi.batch_id, rbi.requisition_id, rbi.amount_in_batch,
                       rh.ref_number, rh.type, rh.period_month, rh.period_year, rh.event_label,
                       d.name AS dept_name
                FROM requisition_batch_items rbi
                JOIN requisition_headers rh ON rbi.requisition_id = rh.id
                JOIN departments d ON rh.department_id = d.id
                JOIN requisition_batches rb ON rbi.batch_id = rb.id
                WHERE rb.status = 'Draft'
                ORDER BY rbi.created_at ASC
            ")->fetchAll();

            echo json_encode([
                'status'                  => 'success',
                'available_requisitions'  => $available,
                'batches'                 => $batches,
                'draft_items'             => $draft_items,
            ]);
            break;


        // ====================================================================
        // create_batch
        // Added transaction lock & retry logic for batch ref generation
        // ====================================================================
        case 'create_batch':
            if (!$is_finance_director && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Finance Director access required.']));
            }

            $batch_label = trim($_POST['batch_label'] ?? '');

            // Use FOR UPDATE to lock the check, preventing concurrent draft creations
            $pdo->beginTransaction();
            
            $draftChk = $pdo->query("SELECT id, batch_ref FROM requisition_batches WHERE status = 'Draft' LIMIT 1 FOR UPDATE");
            if ($existing_draft = $draftChk->fetch()) {
                $pdo->rollBack();
                exit(json_encode(['status'=>'error','message'=>"A draft batch ({$existing_draft['batch_ref']}) already exists. Finalise or delete it before creating a new one."]));
            }

            $week     = date('W');
            $year     = date('Y');
            $base_ref = "BATCH-W{$week}-{$year}";

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM requisition_batches WHERE batch_ref LIKE ?");
            $cntStmt->execute(["{$base_ref}%"]);
            $cnt      = (int)$cntStmt->fetchColumn();
            $batch_ref = $cnt > 0 ? $base_ref . '-' . chr(65 + $cnt) : $base_ref;
            
            $batch_label = $batch_label ?: "Weekly Batch — Week {$week}, {$year}";

            try {
                $pdo->prepare("INSERT INTO requisition_batches (batch_ref, batch_label, created_by, status, total_amount) VALUES (?, ?, ?, 'Draft', 0.00)")
                    ->execute([$batch_ref, $batch_label, $user_id]);
                $batch_id = (int)$pdo->lastInsertId();
                $pdo->commit();
                
                echo json_encode([
                    'status'    => 'success',
                    'message'   => "Batch {$batch_ref} created. Add requisitions to continue.",
                    'batch_id'  => $batch_id,
                    'batch_ref' => $batch_ref,
                ]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    exit(json_encode(['status'=>'error','message'=>'Batch creation collision. Please try again.']));
                }
                throw $e;
            }
            break;


        // ====================================================================
        // toggle_batch_item
        // Applied FOR UPDATE transaction locking.
        // ====================================================================
        case 'toggle_batch_item':
            if (!$is_finance_director && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Finance Director access required.']));
            }

            $batch_id = (int)($_POST['batch_id'] ?? 0);
            $req_id   = (int)($_POST['req_id']   ?? 0);
            $toggle   = ($_POST['toggle'] ?? 'add') === 'remove' ? 'remove' : 'add';

            if (!$batch_id || !$req_id) exit(json_encode(['status'=>'error','message'=>'Batch ID and Requisition ID required.']));

            $pdo->beginTransaction();
            
            $bStmt = $pdo->prepare("SELECT id, total_amount FROM requisition_batches WHERE id = ? AND status = 'Draft' FOR UPDATE");
            $bStmt->execute([$batch_id]);
            if (!$bStmt->fetch()) {
                $pdo->rollBack();
                exit(json_encode(['status'=>'error','message'=>'Batch not found or not in Draft status.']));
            }

            $ok = false;
            $msg = '';

            if ($toggle === 'add') {
                $rStmt = $pdo->prepare("SELECT id, total_amount FROM requisition_headers WHERE id = ? AND status = 'Approved' FOR UPDATE");
                $rStmt->execute([$req_id]);
                $req = $rStmt->fetch();
                if (!$req) {
                    $pdo->rollBack();
                    exit(json_encode(['status'=>'error','message'=>'Requisition not available for batching.']));
                }
                $dupStmt = $pdo->prepare("SELECT id FROM requisition_batch_items WHERE requisition_id = ?");
                $dupStmt->execute([$req_id]);
                if ($dupStmt->fetch()) {
                    $pdo->rollBack();
                    exit(json_encode(['status'=>'error','message'=>'This requisition is already in a batch.']));
                }
                $pdo->prepare("INSERT INTO requisition_batch_items (batch_id, requisition_id, amount_in_batch) VALUES (?,?,?)")->execute([$batch_id, $req_id, $req['total_amount']]);
                $pdo->prepare("UPDATE requisition_headers SET status='Batched' WHERE id=?")->execute([$req_id]);
                $pdo->prepare("UPDATE requisition_batches SET total_amount = total_amount + ? WHERE id=?")->execute([$req['total_amount'], $batch_id]);
                $ok  = true;
                $msg = 'Requisition added to batch.';
            } else {
                $iStmt = $pdo->prepare("SELECT id, amount_in_batch FROM requisition_batch_items WHERE batch_id=? AND requisition_id=?");
                $iStmt->execute([$batch_id, $req_id]);
                $item = $iStmt->fetch();
                if (!$item) {
                    $pdo->rollBack();
                    exit(json_encode(['status'=>'error','message'=>'Item not found in this batch.']));
                }
                $pdo->prepare("DELETE FROM requisition_batch_items WHERE id=?")->execute([$item['id']]);
                $pdo->prepare("UPDATE requisition_headers SET status='Approved' WHERE id=?")->execute([$req_id]);
                $pdo->prepare("UPDATE requisition_batches SET total_amount = total_amount - ? WHERE id=?")->execute([$item['amount_in_batch'], $batch_id]);
                $ok  = true;
                $msg = 'Requisition removed from batch.';
            }

            $pdo->commit();
            echo json_encode(['status'=>'success','message'=>$msg]);
            break;


        // ====================================================================
        // seal_batch
        // Applied FOR UPDATE lock.
        // ====================================================================
        case 'seal_batch':
            if (!$is_finance_director && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Finance Director authority required to seal a batch.']));
            }

            $batch_id = (int)($_POST['batch_id'] ?? 0);
            if (!$batch_id) exit(json_encode(['status'=>'error','message'=>'Batch ID required.']));

            $pdo->beginTransaction();

            $bStmt = $pdo->prepare("SELECT * FROM requisition_batches WHERE id = ? AND status = 'Draft' FOR UPDATE");
            $bStmt->execute([$batch_id]);
            $batch = $bStmt->fetch();
            if (!$batch) {
                $pdo->rollBack();
                exit(json_encode(['status'=>'error','message'=>'Batch not found or already sealed.']));
            }

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM requisition_batch_items WHERE batch_id = ?");
            $cntStmt->execute([$batch_id]);
            if ((int)$cntStmt->fetchColumn() === 0) {
                $pdo->rollBack();
                exit(json_encode(['status'=>'error','message'=>'Cannot seal an empty batch. Please add at least one requisition.']));
            }

            $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $uStmt->execute([$user_id]);
            $stamper = $uStmt->fetch();

            $ip          = getClientIP();
            $now         = date('Y-m-d H:i:s');
            $hash_code   = generateStampHash($batch_id, $user_id, $now, (float)$batch['total_amount']);
            $verify_code = generateVerifyCode($user_id, $batch_id, time());
            $stamp_data  = buildStampData(
                $stamper, 'Finance Director',
                'Batch Authorised for HQ Submission',
                $batch['batch_ref'], (float)$batch['total_amount'], $ip, $hash_code, $verify_code
            );

            $pdo->prepare("
                UPDATE requisition_batches SET
                    finance_stamp_json        = ?,
                    finance_stamp_ip          = ?,
                    finance_stamp_hash        = ?,
                    finance_stamp_verify_code = ?,
                    finance_stamp_at          = ?,
                    finance_stamp_by          = ?,
                    status                    = 'Exported',
                    exported_at               = ?
                WHERE id = ?
            ")->execute([
                json_encode($stamp_data), $ip, $hash_code, $verify_code,
                $now, $user_id, $now, $batch_id,
            ]);

            $pdo->commit();

            $pastor_id = getResidentPastorId($pdo);
            if ($pastor_id && $pastor_id !== $user_id) {
                notify($pdo, $pastor_id,
                    'Finance Batch Sealed',
                    "Batch {$batch['batch_ref']} has been sealed by {$stamper['first_name']} {$stamper['last_name']} for ₦" . number_format($batch['total_amount'], 2) . " and is ready for PDF export to HQ."
                );
            }

            echo json_encode([
                'status'      => 'success',
                'message'     => "Batch {$batch['batch_ref']} sealed and marked as Exported.",
                'verify_code' => $verify_code,
            ]);
            break;


        // ====================================================================
        // disburse_batch
        // ====================================================================
        case 'disburse_batch':
            if (!$is_finance_director && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Finance Director access required.']));
            }

            $batch_id   = (int)($_POST['batch_id']   ?? 0);
            $account_id = (int)($_POST['account_id'] ?? 0);
            $fund_id    = (int)($_POST['fund_id']    ?? 0);

            if (!$batch_id)   exit(json_encode(['status'=>'error','message'=>'Batch ID required.']));
            if (!$account_id) exit(json_encode(['status'=>'error','message'=>'Please select a Ledger Account.']));
            if (!$fund_id)    exit(json_encode(['status'=>'error','message'=>'Please select a Virtual Fund/Wallet.']));

            $bStmt = $pdo->prepare("SELECT * FROM requisition_batches WHERE id = ? AND status = 'Exported'");
            $bStmt->execute([$batch_id]);
            $batch = $bStmt->fetch();
            if (!$batch) exit(json_encode(['status'=>'error','message'=>'Batch not found or not in Exported state.']));

            $iStmt = $pdo->prepare("
                SELECT rbi.requisition_id, rbi.amount_in_batch,
                       rh.ref_number, rh.type, rh.period_month, rh.period_year, rh.event_label,
                       rh.submitted_by, d.name AS dept_name
                FROM requisition_batch_items rbi
                JOIN requisition_headers rh ON rbi.requisition_id = rh.id
                JOIN departments d ON rh.department_id = d.id
                WHERE rbi.batch_id = ?
            ");
            $iStmt->execute([$batch_id]);
            $batch_reqs = $iStmt->fetchAll();

            $pdo->beginTransaction();

            $txStmt = $pdo->prepare("
                INSERT INTO finance_transactions
                    (transaction_date, transaction_type, account_id, fund_id, category_id, amount, description, entered_by)
                VALUES (?, 'Expense', ?, ?, NULL, ?, ?, ?)
            ");
            $today = date('Y-m-d');

            foreach ($batch_reqs as $br) {
                $period = $br['type'] === 'Monthly'
                    ? date('M Y', mktime(0, 0, 0, $br['period_month'], 1, $br['period_year']))
                    : $br['event_label'];
                $desc = "[REQ:{$br['ref_number']}] {$br['dept_name']} — {$period} | Batch:{$batch['batch_ref']}";

                $txStmt->execute([$today, $account_id, $fund_id, $br['amount_in_batch'], $desc, $user_id]);

                $pdo->prepare("UPDATE finance_accounts SET current_balance = current_balance - ? WHERE id = ?")
                    ->execute([$br['amount_in_batch'], $account_id]);

                $pdo->prepare("UPDATE requisition_headers SET status = 'Disbursed' WHERE id = ?")
                    ->execute([$br['requisition_id']]);

                if ($br['submitted_by']) {
                    notify($pdo, (int)$br['submitted_by'],
                        'Requisition Disbursed ✓',
                        "Your requisition {$br['ref_number']} ({$br['dept_name']}) has been disbursed as part of batch {$batch['batch_ref']}. ₦" . number_format($br['amount_in_batch'], 2) . " processed."
                    );
                }
            }

            $pdo->prepare("UPDATE requisition_batches SET status='Disbursed', disbursed_at=NOW() WHERE id=?")->execute([$batch_id]);

            $pdo->commit();
            echo json_encode([
                'status'  => 'success',
                'message' => "Batch {$batch['batch_ref']} disbursed. ₦" . number_format($batch['total_amount'], 2) . " posted to the Finance Ledger.",
            ]);
            break;


        // ====================================================================
        // fetch_analytics
        // ====================================================================
        case 'fetch_analytics':
            $period       = $_POST['period']      ?? 'quarter';
            $custom_start = $_POST['start_date']  ?? null;
            $custom_end   = $_POST['end_date']    ?? null;
            $f_dept       = (int)($_POST['department_id'] ?? 0);

            switch ($period) {
                case 'month':
                    $s = date('Y-m-01');
                    $e = date('Y-m-t');
                    break;
                case 'year':
                    $s = date('Y-01-01');
                    $e = date('Y-12-31');
                    break;
                case 'custom':
                    $s = $custom_start ?: date('Y-m-01');
                    $e = $custom_end   ?: date('Y-m-t');
                    break;
                default:
                    $q = (int)ceil((int)date('n') / 3);
                    $sm = ($q - 1) * 3 + 1;
                    $s = date("Y-{$sm}-01");
                    $e = date('Y-m-t', strtotime('+' . (3 - ((int)date('n') - $sm)) . ' months', strtotime($s)));
                    break;
            }

            $dept_where  = '1=1';
            $dept_params = [];
            $can_see_all = $is_super_admin || $is_resident_pastor || $is_finance_director;

            if ($can_see_all) {
                if ($f_dept > 0) { $dept_where = 'rh.department_id = ?'; $dept_params[] = $f_dept; }
            } elseif ($is_director) {
                $my_d = getUserDirectorDeptIds($pdo, $user_id);
                if (!empty($my_d)) {
                    if ($f_dept > 0 && in_array($f_dept, $my_d)) {
                        $dept_where = 'rh.department_id = ?'; $dept_params[] = $f_dept;
                    } else {
                        $ph = implode(',', array_fill(0, count($my_d), '?'));
                        $dept_where = "rh.department_id IN ({$ph})"; $dept_params = $my_d;
                    }
                }
            } else {
                $my_d = array_column(getUserSubmittableDepts($pdo, $user_id), 'id');
                if (!empty($my_d)) {
                    $ph = implode(',', array_fill(0, count($my_d), '?'));
                    $dept_where = "rh.department_id IN ({$ph})"; $dept_params = $my_d;
                }
            }

            $date_params = [$s, $e];
            $all_params  = array_merge($dept_params, $date_params);

            $stStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt, SUM(total_amount) AS total FROM requisition_headers rh WHERE {$dept_where} AND DATE(created_at) BETWEEN ? AND ? GROUP BY status");
            $stStmt->execute($all_params);
            $status_breakdown = $stStmt->fetchAll();

            $trStmt = $pdo->prepare("
                SELECT DATE_FORMAT(created_at,'%Y-%m') AS month_key,
                       DATE_FORMAT(created_at,'%b %Y') AS month_label,
                       COUNT(*) AS req_count, SUM(total_amount) AS total_amount,
                       SUM(CASE WHEN status IN ('Approved','Batched','Disbursed') THEN total_amount ELSE 0 END) AS approved_amount
                FROM requisition_headers rh
                WHERE {$dept_where} AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY month_key, month_label ORDER BY month_key ASC
            ");
            $trStmt->execute($dept_params);
            $monthly_trend = $trStmt->fetchAll();

            $topStmt = $pdo->prepare("
                SELECT ri.item_description, SUM(ri.total_amount) AS total_requested, COUNT(*) AS frequency, fc.category_name
                FROM requisition_items ri
                JOIN requisition_headers rh ON ri.requisition_id = rh.id
                LEFT JOIN finance_categories fc ON ri.finance_category_id = fc.id
                WHERE {$dept_where} AND DATE(rh.created_at) BETWEEN ? AND ?
                GROUP BY ri.item_description, fc.category_name
                ORDER BY total_requested DESC LIMIT 10
            ");
            $topStmt->execute($all_params);
            $top_items = $topStmt->fetchAll();

            $cycStmt = $pdo->prepare("
                SELECT AVG(DATEDIFF(pastor_approved_at, created_at)) AS avg_days
                FROM requisition_headers rh
                WHERE {$dept_where} AND pastor_approved_at IS NOT NULL AND DATE(created_at) BETWEEN ? AND ?
            ");
            $cycStmt->execute($all_params);
            $cycle = $cycStmt->fetch();

            $dept_comparison = [];
            if ($is_director || $can_see_all) {
                $cmpStmt = $pdo->prepare("
                    SELECT d.name AS dept_name, COUNT(rh.id) AS req_count, SUM(rh.total_amount) AS total_amount,
                           SUM(CASE WHEN rh.status IN ('Approved','Batched','Disbursed') THEN rh.total_amount ELSE 0 END) AS approved_amount
                    FROM requisition_headers rh JOIN departments d ON rh.department_id = d.id
                    WHERE {$dept_where} AND DATE(rh.created_at) BETWEEN ? AND ?
                    GROUP BY d.id, d.name ORDER BY total_amount DESC
                ");
                $cmpStmt->execute($all_params);
                $dept_comparison = $cmpStmt->fetchAll();
            }

            $batch_history = [];
            if ($can_see_all) {
                $batch_history = $pdo->query("
                    SELECT rb.batch_ref, rb.status, rb.total_amount, rb.created_at, rb.disbursed_at,
                           CONCAT(u.first_name,' ',u.last_name) AS created_by_name,
                           (SELECT COUNT(*) FROM requisition_batch_items rbi WHERE rbi.batch_id = rb.id) AS req_count
                    FROM requisition_batches rb JOIN users u ON rb.created_by = u.id
                    ORDER BY rb.created_at DESC LIMIT 20
                ")->fetchAll();
            }

            $all_depts = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();

            echo json_encode([
                'status'           => 'success',
                'period'           => ['start' => $s, 'end' => $e, 'type' => $period],
                'status_breakdown' => $status_breakdown,
                'monthly_trend'    => $monthly_trend,
                'top_items'        => $top_items,
                'cycle_days'       => round((float)($cycle['avg_days'] ?? 0), 1),
                'dept_comparison'  => $dept_comparison,
                'batch_history'    => $batch_history,
                'all_departments'  => $all_depts,
                'can_see_all'      => $can_see_all,
            ]);
            break;


        // ====================================================================
        // save_signature_vault
        // Security logic upgraded for hard validation of PIN
        // ====================================================================
        case 'save_signature_vault':
            if (!$is_resident_pastor && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Only the Resident Pastor can store a signature vault.']));
            }

            $sig_b64            = $_POST['signature_base64']  ?? '';
            $pin                = $_POST['pin']               ?? '';
            $device_fingerprint = substr(trim($_POST['device_fingerprint'] ?? ''), 0, 255);

            if (empty($sig_b64)) exit(json_encode(['status'=>'error','message'=>'No signature captured.']));
            if (!str_starts_with($sig_b64, 'data:image/')) exit(json_encode(['status'=>'error','message'=>'Invalid signature format.']));
            if (!preg_match('/^\d{4}$/', $pin)) exit(json_encode(['status'=>'error','message'=>'PIN must be exactly 4 numerical digits.']));

            $pin_hash = password_hash($pin, PASSWORD_BCRYPT);

            $exStmt = $pdo->prepare("SELECT id FROM pastor_signature_vault WHERE user_id = ?");
            $exStmt->execute([$user_id]);
            if ($exStmt->fetch()) {
                $pdo->prepare("UPDATE pastor_signature_vault SET signature_base64=?, pin_hash=?, device_fingerprint=?, is_active=1, created_at=NOW() WHERE user_id=?")
                    ->execute([$sig_b64, $pin_hash, $device_fingerprint, $user_id]);
            } else {
                $pdo->prepare("INSERT INTO pastor_signature_vault (user_id, signature_base64, pin_hash, device_fingerprint, is_active) VALUES (?,?,?,?,1)")
                    ->execute([$user_id, $sig_b64, $pin_hash, $device_fingerprint]);
            }

            echo json_encode(['status'=>'success','message'=>'Signature stored securely in your vault. You can import it with your PIN on any approval.']);
            break;


        // ====================================================================
        // verify_pin_get_signature
        // ====================================================================
        case 'verify_pin_get_signature':
            if (!$is_resident_pastor && !$is_super_admin) exit(json_encode(['status'=>'error','message'=>'Access denied.']));

            $pin = $_POST['pin'] ?? '';
            if (empty($pin)) exit(json_encode(['status'=>'error','message'=>'PIN required.']));

            $vStmt = $pdo->prepare("SELECT * FROM pastor_signature_vault WHERE user_id = ? AND is_active = 1");
            $vStmt->execute([$user_id]);
            $vault = $vStmt->fetch();

            if (!$vault) exit(json_encode(['status'=>'error','message'=>'No stored signature found. Please draw and save your signature first.']));
            if (!password_verify($pin, $vault['pin_hash'])) exit(json_encode(['status'=>'error','message'=>'Incorrect PIN. Please try again.']));

            $pdo->prepare("UPDATE pastor_signature_vault SET last_used_at=NOW() WHERE user_id=?")->execute([$user_id]);
            echo json_encode(['status'=>'success','signature_base64'=>$vault['signature_base64']]);
            break;


        // ====================================================================
        // check_signature_vault
        // ====================================================================
        case 'check_signature_vault':
            $vStmt = $pdo->prepare("SELECT id, created_at, last_used_at FROM pastor_signature_vault WHERE user_id = ? AND is_active = 1");
            $vStmt->execute([$user_id]);
            $v = $vStmt->fetch();
            echo json_encode([
                'status'        => 'success',
                'has_signature' => (bool)$v,
                'created_at'    => $v['created_at']   ?? null,
                'last_used_at'  => $v['last_used_at'] ?? null,
            ]);
            break;


        // ====================================================================
        // delete_signature_vault
        // ====================================================================
        case 'delete_signature_vault':
            if (!$is_resident_pastor && !$is_super_admin) exit(json_encode(['status'=>'error','message'=>'Access denied.']));

            $pin = $_POST['pin'] ?? '';
            $vStmt = $pdo->prepare("SELECT * FROM pastor_signature_vault WHERE user_id = ? AND is_active = 1");
            $vStmt->execute([$user_id]);
            $vault = $vStmt->fetch();

            if (!$vault) exit(json_encode(['status'=>'error','message'=>'No stored signature found.']));
            if (!password_verify($pin, $vault['pin_hash'])) exit(json_encode(['status'=>'error','message'=>'Incorrect PIN.']));

            $pdo->prepare("UPDATE pastor_signature_vault SET is_active = 0 WHERE user_id = ?")->execute([$user_id]);
            echo json_encode(['status'=>'success','message'=>'Stored signature deleted from vault.']);
            break;


        // ====================================================================
        // fetch_accounts_funds
        // ====================================================================
        case 'fetch_accounts_funds':
            if (!$is_finance_director && !$is_super_admin) {
                exit(json_encode(['status'=>'error','message'=>'Finance Director access required.']));
            }
            $accounts = $pdo->query("SELECT id, account_name, account_type, current_balance FROM finance_accounts ORDER BY account_name ASC")->fetchAll();
            $funds    = $pdo->query("SELECT id, fund_name FROM finance_funds ORDER BY fund_name ASC")->fetchAll();
            echo json_encode(['status'=>'success','accounts'=>$accounts,'funds'=>$funds]);
            break;


        // ====================================================================
        // get_pastor_signature_for_pdf
        // ====================================================================
        case 'get_pastor_signature_for_pdf':
            if (!$is_resident_pastor && !$is_super_admin && !$is_finance_director) {
                exit(json_encode(['status'=>'error','message'=>'Access denied.']));
            }
            $req_id = (int)($_POST['req_id'] ?? 0);
            if (!$req_id) exit(json_encode(['status'=>'error','message'=>'Requisition ID required.']));

            $stmt = $pdo->prepare("
                SELECT signature_base64 FROM requisition_pastor_actions
                WHERE requisition_id = ? AND action = 'Approved' AND signature_base64 IS NOT NULL
                ORDER BY acted_at DESC LIMIT 1
            ");
            $stmt->execute([$req_id]);
            $row = $stmt->fetch();
            echo json_encode([
                'status'           => $row ? 'success' : 'error',
                'signature_base64' => $row['signature_base64'] ?? null,
                'message'          => $row ? null : 'No signature found for this requisition.',
            ]);
            break;


        default:
            echo json_encode(['status'=>'error','message'=>'Invalid action requested.']);
            break;

    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Requisition API PDOException [{$action}]: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'A database error occurred. Please try again or contact the Information and Data Insights Department.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Requisition API Error [{$action}]: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'An unexpected error occurred. Please try again.']);
}
?>