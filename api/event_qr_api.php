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
require_once '../includes/html_allowlist.php';

/** An event description as safe HTML for the public register page. */
function event_qr_description(string $raw): string
{
    $clean = hod_html_allowlist($raw);
    return str_contains($raw, '<') ? $clean : nl2br($clean, false);
}
header('Content-Type: application/json');

/* ---- Auth ---- */
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Unauthorized access. Please log in.']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- RBAC (leadership + admins) ---- */
// Access: Events & Attendance in the permission matrix (Roles & permissions).
$is_authorized = can('events', 'view');
if (!$is_authorized) {
    echo json_encode(['status'=>'error','message'=>'Access Denied.']);
    exit;
}

/** Resolve the public base URL of the site (for building the registration link). */
function qr_base_url() {
    // SITE_URL, not the request's host: a QR printed from connect.hodlekki.org or
    // the old address must still point at the real site.
    require_once __DIR__ . '/../includes/site_url.php';
    return site_origin();
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
                    // The register page shows this as HTML: allowlisted formatting only, rows saved
                    // before the allowlist included; a plain-text description keeps its line breaks.
                    'description'       => event_qr_description((string) ($e['description'] ?? '')),
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
