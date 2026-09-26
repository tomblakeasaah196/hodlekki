<?php
/**
 * ============================================================================
 * EVENT QR POSTER — Backend API
 * File: /api/event_qr_api.php
 * ----------------------------------------------------------------------------
 * Returns the data needed to build a branded registration QR poster for an
 * event: event details, banner image, and the public registration URL to encode.
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
    echo json_encode(['status'=>'error','message'=>'Access Denied.']);
    exit;
}

/** Resolve the public base URL of the site (for building the registration link). */
function qr_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'hodlc.lpc.cm';
    return $scheme . '://' . $host;
}

try {
    switch ($action) {

        /* ================================================================
         * LIST EVENTS (for the dropdown)
         * ================================================================ */
        case 'list_events':
            $rows = $pdo->query(
                "SELECT id, title, event_date, event_category
                 FROM events
                 ORDER BY event_date DESC LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success','data'=>$rows]);
            break;

        /* ================================================================
         * GET ONE EVENT — full poster data
         * ================================================================ */
        case 'get_event':
            $event_id = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
            if (!$event_id) { echo json_encode(['status'=>'error','message'=>'Event ID is required.']); exit; }

            $stmt = $pdo->prepare(
                "SELECT id, title, event_category, event_date, end_date, description,
                        location, banner_image_url, registration_token,
                        requires_registration, is_closed
                 FROM events WHERE id = ?"
            );
            $stmt->execute([$event_id]);
            $e = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$e) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }

            // Build the registration URL to encode into the QR (if a token exists)
            $registration_url = '';
            if (!empty($e['registration_token'])) {
                $registration_url = rtrim(qr_base_url(), '/') . '/register.php?token=' . urlencode($e['registration_token']);
            }

            // Human-friendly date
            $nice_date = '';
            if (!empty($e['event_date'])) {
                $ts = strtotime($e['event_date']);
                $nice_date = date('l, F j, Y', $ts);
            }
            $nice_time = '';
            if (!empty($e['event_date'])) {
                $ts = strtotime($e['event_date']);
                $nice_time = date('g:i A', $ts);
            }

            echo json_encode([
                'status' => 'success',
                'event' => [
                    'id'                => $e['id'],
                    'title'             => $e['title'],
                    'category'          => $e['event_category'],
                    'date'              => $nice_date,
                    'time'              => $nice_time,
                    'event_date_raw'    => $e['event_date'],
                    'end_date'          => $e['end_date'],
                    'location'          => $e['location'],
                    'description'       => $e['description'],
                    'banner_image_url'  => $e['banner_image_url'],
                    'requires_registration' => $e['requires_registration'],
                    'is_closed'         => $e['is_closed'],
                    'registration_url'  => $registration_url,
                ],
            ]);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid API action requested.']);
            break;
    }
} catch (PDOException $ex) {
    error_log("Event QR API Error: " . $ex->getMessage());
    echo json_encode(['status'=>'error','message'=>'A system error occurred.']);
}
