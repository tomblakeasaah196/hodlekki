<?php
/**
 * ============================================================================
 * CONTACT BATCH EXTRACTOR — Backend API
 * File: /api/contact_extractor_api.php
 * ----------------------------------------------------------------------------
 * Gathers every phone number from three tables (users, event_registrations,
 * idi_mobilization), normalizes them to international 234XXXXXXXXXX, and
 * DEDUPLICATES so no number ever appears twice. Returns the unique list for
 * the frontend to split into copy-ready batches.
 *
 * Mirrors the auth/RBAC pattern used by the other modules.
 * ============================================================================
 */

require_once '../includes/db.php';
header('Content-Type: application/json');

/* ---- Auth ---- */
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Unauthorized access. Please log in.']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- RBAC (leadership + admins) ---- */
$allowed_roles = ['Super_Admin','Resident_Pastor','Assoc_Pastor','Director','HOD','Sub_Unit_Head','Admin'];
$is_authorized = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $r) {
        if (in_array($r['role_name'], $allowed_roles)) { $is_authorized = true; break; }
    }
}
if (!$is_authorized) {
    echo json_encode(['status'=>'error','message'=>'Access Denied. You do not have clearance to view contacts.']);
    exit;
}

/**
 * Normalize any Nigerian phone to international 234XXXXXXXXXX.
 * Accepts 08012345678, 8012345678, +2348012345678, 2348012345678, 00234...
 * Returns null if not a valid NG mobile (those are skipped, not sent).
 */
function ce_normalize_phone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($p === '') return null;
    // strip leading 00
    if (strpos($p, '00') === 0) $p = substr($p, 2);
    if (strlen($p) === 11 && $p[0] === '0') {
        $p = '234' . substr($p, 1);
    } elseif (strlen($p) === 10) {
        $p = '234' . $p;
    } elseif (strlen($p) === 13 && substr($p, 0, 3) !== '234') {
        $p = '234' . ltrim($p, '0');
    }
    return preg_match('/^234[789][01]\d{8}$/', $p) ? $p : null;
}

try {
    switch ($action) {

        /* ================================================================
         * FETCH CONTACTS — deduplicated, normalized unique phone numbers
         * ================================================================ */
        case 'fetch_contacts':
            $unique = [];   // dedup keyed on normalized phone
            $sourceBreakdown = ['users'=>0, 'registrations'=>0, 'mobilization'=>0];

            // ---- 1) users ----
            $st = $pdo->query(
                "SELECT phone, attendance_status, phone_status
                 FROM users
                 WHERE phone IS NOT NULL AND TRIM(phone) <> ''
                   AND COALESCE(phone_status,'') <> 'invalid'
                   AND COALESCE(attendance_status,'') <> 'Relocated'"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $n = ce_normalize_phone($u['phone']);
                if ($n === null) continue;
                $unique[$n] = true;
                $sourceBreakdown['users']++;
            }

            // ---- 2) event_registrations (guest_phone) ----
            $st = $pdo->query(
                "SELECT guest_phone FROM event_registrations
                 WHERE guest_phone IS NOT NULL AND TRIM(guest_phone) <> ''"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $n = ce_normalize_phone($r['guest_phone']);
                if ($n === null) continue;
                $unique[$n] = true;
                $sourceBreakdown['registrations']++;
            }

            // ---- 3) idi_mobilization (phone_number) ----
            $st = $pdo->query(
                "SELECT phone_number FROM idi_mobilization
                 WHERE phone_number IS NOT NULL AND TRIM(phone_number) <> ''"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $n = ce_normalize_phone($m['phone_number']);
                if ($n === null) continue;
                $unique[$n] = true;
                $sourceBreakdown['mobilization']++;
            }

            $contacts = array_keys($unique);
            sort($contacts); // stable, predictable order

            echo json_encode([
                'status' => 'success',
                'count'  => count($contacts),
                'contacts' => $contacts,
                'source_breakdown' => $sourceBreakdown,
            ]);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid API action requested.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Contact Extractor API Error: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'A system error occurred.']);
}
