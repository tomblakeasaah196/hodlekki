<?php
/**
 * ============================================================================
 * CHECK-IN QR API — backend for the QR generator
 * File: /api/checkin_qr_api.php
 * ----------------------------------------------------------------------------
 * Lists events (id + title) and returns the check-in URL token for one event.
 * ============================================================================
 */
require_once '../includes/db.php';
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Unauthorized.']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$allowed_roles = ['Super_Admin','Resident_Pastor','Assoc_Pastor','Director','HOD','Sub_Unit_Head','Admin'];
$is_authorized = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $r) {
        if (in_array($r['role_name'], $allowed_roles)) { $is_authorized = true; break; }
    }
}
if (!$is_authorized) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }

// CSRF for state-changing POSTs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['_csrf'] ?? '';
    if ($tok === '' || empty($_SESSION['checkin_csrf']) || !hash_equals($_SESSION['checkin_csrf'], $tok)) {
        echo json_encode(['status'=>'error','message'=>'Invalid security token.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'list_events':
            $rows = $pdo->query("SELECT id, title, registration_token FROM events ORDER BY event_date DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success','data'=>$rows]);
            break;

        case 'get_event':
            $id = (int)($_POST['event_id'] ?? 0);
            $st = $pdo->prepare("SELECT id, title, registration_token, banner_image_url FROM events WHERE id = ?");
            $st->execute([$id]);
            $e = $st->fetch(PDO::FETCH_ASSOC);
            if (!$e) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }
            // ensure a token exists
            if (empty($e['registration_token'])) {
                $token = bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE events SET registration_token = ? WHERE id = ?")->execute([$token, $id]);
                $e['registration_token'] = $token;
            }
            echo json_encode(['status'=>'success','event'=>['id'=>$e['id'],'title'=>$e['title'],'token'=>$e['registration_token'],'banner_image_url'=>$e['banner_image_url']]]);
            break;

        /* ================================================================
         * CHECK-IN KPIs — monitoring dashboard for one event
         * ================================================================ */
        case 'checkin_stats':
            $id = (int)($_POST['event_id'] ?? 0);
            $ev = $pdo->prepare("SELECT id, title, event_date, end_date FROM events WHERE id = ?");
            $ev->execute([$id]);
            $event = $ev->fetch(PDO::FETCH_ASSOC);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }

            // total check-ins
            $t = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE event_id = ?");
            $t->execute([$id]); $total = (int)$t->fetchColumn();

            // today
            $td = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE event_id = ? AND checkin_date = ?");
            $td->execute([$id, date('Y-m-d')]); $today = (int)$td->fetchColumn();

            // per-day breakdown
            $days = $pdo->prepare("SELECT checkin_date, COUNT(*) AS n FROM checkins WHERE event_id = ? GROUP BY checkin_date ORDER BY checkin_date");
            $days->execute([$id]); $byDay = $days->fetchAll(PDO::FETCH_ASSOC);

            // members vs walk-ins
            $m = $pdo->prepare("SELECT SUM(is_member=1) AS members, SUM(is_walkin=1) AS walkins, SUM(name_edited=1) AS edited FROM checkins WHERE event_id = ?");
            $m->execute([$id]); $mix = $m->fetch(PDO::FETCH_ASSOC);

            // recent
            $r = $pdo->prepare("SELECT full_name, phone, is_member, is_walkin, source, checked_in_at FROM checkins WHERE event_id = ? ORDER BY id DESC LIMIT 25");
            $r->execute([$id]); $recent = $r->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'=>'success',
                'event'=>['id'=>$event['id'],'title'=>$event['title']],
                'kpis'=>[
                    'total'=>$total,
                    'today'=>$today,
                    'by_day'=>$byDay,
                    'members'=>(int)($mix['members']??0),
                    'walkins'=>(int)($mix['walkins']??0),
                    'name_edits'=>(int)($mix['edited']??0),
                ],
                'recent'=>$recent,
            ]);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid action.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Checkin QR API Error: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'A system error occurred.']);
}
