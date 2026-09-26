<?php
// /api/reach_api.php

require_once '../includes/db.php';
require_once '../includes/reach_helpers.php';
header('Content-Type: application/json');

// ==========================================================================
// AUTH GATE — Super Admin, Pastors, or Reach/Evangelism department.
// Matches the pattern the module has used since v1; every action below
// runs after this passes.
// ==========================================================================
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id     = (int) $_SESSION['user_id'];
$active_role = $_SESSION['active_role'] ?? '';
$action      = $_POST['action'] ?? $_GET['action'] ?? '';

$has_clearance = false;
if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true)) {
    $has_clearance = true;
} else {
    $deptStmt = $pdo->prepare("
        SELECT d.id FROM departments d
        JOIN user_departments ud ON d.id = ud.department_id
        WHERE ud.user_id = ? AND ud.is_active = 1
          AND (d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%')
    ");
    $deptStmt->execute([$user_id]);
    if ($deptStmt->fetch()) {
        $has_clearance = true;
    }
}

if (!$has_clearance) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Access Denied: You must be in the Reach/Evangelism department to access this module.'
    ]);
    exit;
}

// ==========================================================================
// HELPERS
// ==========================================================================

// Fields the public capture form shows per tier. Rapid = the bare minimum
// a volunteer can type in under 10 seconds. Standard = adds address +
// prayer request. Rich = every optional field we know about.
function reach_tier_fields(string $tier): array {
    switch ($tier) {
        case 'Rapid':
            return [];
        case 'Standard':
            return ['address', 'prayer_request'];
        case 'Rich':
        default:
            return ['address', 'prayer_request', 'age_band', 'marital_status', 'language', 'best_time_to_call', 'notes'];
    }
}

function reach_slugify(string $text): string {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9\-\s]/', '', $slug);
    $slug = preg_replace('/[\s\-]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'campaign';
    }
    return substr($slug, 0, 90);
}

function reach_unique_slug(PDO $pdo, string $base, ?int $ignoreId = null): string {
    $slug = $base;
    $i = 1;
    while (true) {
        if ($ignoreId === null) {
            $stmt = $pdo->prepare("SELECT id FROM reach_campaigns WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM reach_campaigns WHERE slug = ? AND id <> ? LIMIT 1");
            $stmt->execute([$slug, $ignoreId]);
        }
        if (!$stmt->fetch()) {
            return $slug;
        }
        $i++;
        $slug = substr($base, 0, 85) . '-' . $i;
    }
}

function reach_sync_campaign_fields(PDO $pdo, int $campaign_id, string $tier): void {
    $pdo->prepare("DELETE FROM reach_campaign_fields WHERE campaign_id = ?")
        ->execute([$campaign_id]);
    $fields = reach_tier_fields($tier);
    if (!$fields) {
        return;
    }
    $ins = $pdo->prepare("INSERT INTO reach_campaign_fields (campaign_id, field_name) VALUES (?, ?)");
    foreach ($fields as $f) {
        $ins->execute([$campaign_id, $f]);
    }
}

function reach_absolute_url(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'hodlc.lpc.cm';
    return $scheme . '://' . $host . $path;
}

// QR data URL. Uses the public Google Chart API (chs) as a zero-dep
// fallback so we don't have to run a QR library server-side; the
// endpoint is only reached by authenticated staff hitting Share, so
// there's no privacy angle to worry about here.
function reach_qr_data_url(string $target): string {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=8&data=' . urlencode($target);
}

const REACH_CATEGORIES = ['New_Convert', 'Unsaved', 'Saved', 'Broken', 'Dechurched', 'Other'];
const REACH_CHANNELS   = ['Call', 'WhatsApp', 'SMS', 'In_Person_Visit', 'Church_Service'];
const REACH_OUTCOMES   = ['Reached', 'Promised_Church', 'No_Answer', 'Wrong_Number', 'Rescheduled', 'Requested_No_Contact', 'Declined'];

function reach_user_name(PDO $pdo, ?int $id): string {
    if (!$id) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
}

function reach_first_capture(PDO $pdo, int $lead_id): array {
    $stmt = $pdo->prepare("
        SELECT lc.captured_by_user_id,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), lc.captured_by_guest_name) AS name
          FROM reach_lead_captures lc
          LEFT JOIN users u ON u.id = lc.captured_by_user_id
         WHERE lc.lead_id = ?
         ORDER BY lc.id ASC LIMIT 1
    ");
    $stmt->execute([$lead_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['captured_by_user_id' => null, 'name' => null];
}

// Status is derived, never set by hand. One unanswered attempt is not yet
// "Cold" — it takes a second attempt with no contact.
function reach_recompute_status(PDO $pdo, int $lead_id): string {
    $stmt = $pdo->prepare("
        SELECT COUNT(f.id) AS total,
               COALESCE(SUM(f.outcome = 'Reached'), 0) AS reached,
               COALESCE(SUM(f.outcome = 'Requested_No_Contact'), 0) AS no_contact,
               COALESCE(SUM(f.outcome = 'Promised_Church'), 0) AS promised,
               l.pushed_to_embrace_at, l.will_attend_church
          FROM reach_leads l
          LEFT JOIN reach_follow_ups f ON f.lead_id = l.id
         WHERE l.id = ?
         GROUP BY l.id, l.pushed_to_embrace_at, l.will_attend_church
    ");
    $stmt->execute([$lead_id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($s['pushed_to_embrace_at'])) {
        $status = 'Converted';
    } elseif ((int) $s['no_contact'] > 0) {
        $status = 'Declined';
    } elseif ((int) $s['promised'] > 0 || (int) $s['will_attend_church'] === 1) {
        $status = 'Will_Attend';
    } elseif ((int) $s['reached'] > 0) {
        $status = 'Spoken_To';
    } elseif ((int) $s['total'] >= 2) {
        $status = 'Cold';
    } else {
        $status = 'Not_Spoken_To';
    }
    $pdo->prepare("UPDATE reach_leads SET status = ? WHERE id = ?")->execute([$status, $lead_id]);
    return $status;
}

function reach_valid_type(PDO $pdo, string $code): string {
    $stmt = $pdo->prepare("SELECT code FROM reach_campaign_types WHERE code = ?");
    $stmt->execute([$code]);
    return $stmt->fetchColumn() ?: 'Other';
}

// Optional flyer from the campaign form; exits with a JSON error if it is invalid.
function reach_flyer_from_request(): ?string {
    if (empty($_FILES['flyer']['name'])) {
        return null;
    }
    try {
        return reach_store_image($_FILES['flyer'], 'flyer');
    } catch (RuntimeException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

function reach_deny(string $message = 'Only Reach HODs, Directors and pastors can do this.'): void {
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

$is_manager = reach_is_manager($pdo, $user_id, $active_role);

// Defaults to the current month; swaps the ends if they arrive reversed.
function reach_date_range(): array {
    $valid = function ($d) {
        $x = DateTime::createFromFormat('Y-m-d', (string) $d);
        return $x && $x->format('Y-m-d') === $d;
    };
    $from = $_POST['from_date'] ?? $_GET['from_date'] ?? '';
    $to   = $_POST['to_date'] ?? $_GET['to_date'] ?? '';
    $from = $valid($from) ? $from : date('Y-m-01');
    $to   = $valid($to) ? $to : date('Y-m-t');
    return $from <= $to ? [$from, $to] : [$to, $from];
}

// Spreadsheet apps execute cells starting with these as formulas.
function reach_csv_safe($v): string {
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

// ==========================================================================
// ACTIONS
// ==========================================================================

try {
    switch ($action) {

        // ------------------------------------------------------------------
        // fetch_campaigns — Tab 1 card list
        // ------------------------------------------------------------------
        case 'fetch_campaigns':
            $stmt = $pdo->query("
                SELECT
                    c.id, c.slug, c.title, c.campaign_type, c.campaign_date,
                    c.start_time, c.end_time, c.location, c.meta_description,
                    c.share_scripture, c.flyer_path, c.payload_tier, c.status, c.created_by,
                    c.created_at, c.updated_at,
                    DATE_FORMAT(c.campaign_date, '%M %D, %Y') AS nice_date,
                    (SELECT COUNT(*) FROM reach_leads l WHERE l.campaign_id = c.id) AS souls_count
                FROM reach_campaigns c
                ORDER BY (c.campaign_date IS NULL), c.campaign_date DESC, c.id DESC
            ");
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $default_image = reach_default_campaign_image($pdo);
            foreach ($campaigns as &$c) {
                $c['image_url'] = $c['flyer_path'] ?: $default_image;
            }
            unset($c);
            echo json_encode(['status' => 'success', 'data' => $campaigns]);
            break;

        // ------------------------------------------------------------------
        // create_campaign
        // ------------------------------------------------------------------
        case 'create_campaign':
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                echo json_encode(['status' => 'error', 'message' => 'Campaign title is required.']);
                exit;
            }

            $type            = reach_valid_type($pdo, trim($_POST['campaign_type'] ?? 'Saturday_Evangelism'));
            $date            = !empty($_POST['campaign_date']) ? $_POST['campaign_date'] : null;
            $start_time      = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
            $end_time        = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
            $location        = trim($_POST['location'] ?? '');
            $meta            = trim($_POST['meta_description'] ?? '');
            $scripture       = trim($_POST['share_scripture'] ?? '');
            $tier_raw        = $_POST['payload_tier'] ?? 'Rich';
            $tier            = in_array($tier_raw, ['Rapid', 'Standard', 'Rich'], true) ? $tier_raw : 'Rich';
            $flyer           = reach_flyer_from_request();

            $base_slug = reach_slugify($title . ($date ? ' ' . $date : ''));
            $slug      = reach_unique_slug($pdo, $base_slug);

            $stmt = $pdo->prepare("
                INSERT INTO reach_campaigns
                    (slug, title, campaign_type, campaign_date, start_time, end_time,
                     location, meta_description, share_scripture, flyer_path, payload_tier, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)
            ");
            $stmt->execute([
                $slug, $title, $type, $date, $start_time, $end_time,
                $location, $meta, $scripture, $flyer, $tier, $user_id
            ]);
            $campaign_id = (int) $pdo->lastInsertId();

            reach_sync_campaign_fields($pdo, $campaign_id, $tier);

            echo json_encode([
                'status'  => 'success',
                'message' => 'Campaign created.',
                'data'    => ['id' => $campaign_id, 'slug' => $slug]
            ]);
            break;

        // ------------------------------------------------------------------
        // edit_campaign
        // ------------------------------------------------------------------
        case 'edit_campaign':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Missing campaign id.']);
                exit;
            }

            $existingStmt = $pdo->prepare("SELECT slug, title, flyer_path FROM reach_campaigns WHERE id = ?");
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                echo json_encode(['status' => 'error', 'message' => 'Campaign not found.']);
                exit;
            }

            $title           = trim($_POST['title'] ?? '');
            if ($title === '') {
                echo json_encode(['status' => 'error', 'message' => 'Campaign title is required.']);
                exit;
            }
            $type            = reach_valid_type($pdo, trim($_POST['campaign_type'] ?? 'Saturday_Evangelism'));
            $date            = !empty($_POST['campaign_date']) ? $_POST['campaign_date'] : null;
            $start_time      = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
            $end_time        = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
            $location        = trim($_POST['location'] ?? '');
            $meta            = trim($_POST['meta_description'] ?? '');
            $scripture       = trim($_POST['share_scripture'] ?? '');
            $status_raw      = $_POST['status'] ?? 'Active';
            $status          = in_array($status_raw, ['Active', 'Completed', 'Cancelled'], true) ? $status_raw : 'Active';
            $tier_raw        = $_POST['payload_tier'] ?? 'Rich';
            $tier            = in_array($tier_raw, ['Rapid', 'Standard', 'Rich'], true) ? $tier_raw : 'Rich';
            $flyer           = $existing['flyer_path'];
            $new_flyer       = reach_flyer_from_request();
            if ($new_flyer || !empty($_POST['remove_flyer'])) {
                reach_delete_upload($flyer);
                $flyer = $new_flyer;
            }

            // Only regenerate the slug when the title actually changed —
            // existing share links stay valid on light edits.
            $slug = $existing['slug'];
            if (strcasecmp(trim($existing['title']), $title) !== 0) {
                $slug = reach_unique_slug($pdo, reach_slugify($title . ($date ? ' ' . $date : '')), $id);
            }

            $upd = $pdo->prepare("
                UPDATE reach_campaigns
                   SET slug = ?, title = ?, campaign_type = ?, campaign_date = ?,
                       start_time = ?, end_time = ?, location = ?,
                       meta_description = ?, share_scripture = ?, flyer_path = ?,
                       payload_tier = ?, status = ?
                 WHERE id = ?
            ");
            $upd->execute([
                $slug, $title, $type, $date, $start_time, $end_time, $location,
                $meta, $scripture, $flyer, $tier, $status, $id
            ]);

            reach_sync_campaign_fields($pdo, $id, $tier);

            echo json_encode([
                'status'  => 'success',
                'message' => 'Campaign updated.',
                'data'    => ['id' => $id, 'slug' => $slug]
            ]);
            break;

        // ------------------------------------------------------------------
        // delete_campaign — hard-delete only when no leads are attached,
        // otherwise soft-cancel so the historical capture record stays intact.
        // ------------------------------------------------------------------
        case 'delete_campaign':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Missing campaign id.']);
                exit;
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reach_leads WHERE campaign_id = ?");
            $countStmt->execute([$id]);
            $souls = (int) $countStmt->fetchColumn();

            if ($souls > 0) {
                $pdo->prepare("UPDATE reach_campaigns SET status = 'Cancelled' WHERE id = ?")
                    ->execute([$id]);
                echo json_encode([
                    'status'  => 'success',
                    'message' => 'Campaign has captures already — marked as Cancelled instead of deleted.'
                ]);
                break;
            }

            $pdo->prepare("DELETE FROM reach_campaign_fields WHERE campaign_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM reach_campaigns WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'Campaign deleted.']);
            break;

        // ------------------------------------------------------------------
        // fetch_campaign_share_bundle — everything the Share modal needs
        // ------------------------------------------------------------------
        case 'fetch_campaign_share_bundle':
            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Missing campaign id.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT id, slug, title, meta_description, share_scripture, campaign_date, location, flyer_path
                  FROM reach_campaigns
                 WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) {
                echo json_encode(['status' => 'error', 'message' => 'Campaign not found.']);
                exit;
            }

            $public_url = reach_absolute_url('/reach.php?c=' . rawurlencode($campaign['slug']));

            $blurb_parts = [];
            if (!empty($campaign['meta_description'])) {
                $blurb_parts[] = trim($campaign['meta_description']);
            } else {
                $blurb_parts[] = 'Join us for ' . $campaign['title'] . '.';
            }
            if (!empty($campaign['share_scripture'])) {
                $blurb_parts[] = '"' . trim($campaign['share_scripture']) . '"';
            }
            $blurb_parts[] = $public_url;
            $whatsapp_text = implode("\n\n", $blurb_parts);

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'id'             => (int) $campaign['id'],
                    'slug'           => $campaign['slug'],
                    'title'          => $campaign['title'],
                    'public_url'     => $public_url,
                    'whatsapp_text'  => $whatsapp_text,
                    'whatsapp_link'  => 'https://wa.me/?text=' . rawurlencode($whatsapp_text),
                    'qr_data_url'    => reach_qr_data_url($public_url),
                    'image_url'      => $campaign['flyer_path'] ?: reach_default_campaign_image($pdo),
                ]
            ]);
            break;

        // ------------------------------------------------------------------
        // list_leads — Tab 2 list, sub-tab counts and overdue widget
        // ------------------------------------------------------------------
        case 'list_leads':
            $where  = [];
            $params = [];

            $campaign_id = (int) ($_POST['campaign_id'] ?? 0);
            if ($campaign_id > 0) {
                $where[]  = 'l.campaign_id = ?';
                $params[] = $campaign_id;
            }
            $category = $_POST['category'] ?? '';
            if (in_array($category, REACH_CATEGORIES, true)) {
                $where[]  = 'FIND_IN_SET(?, l.category) > 0';
                $params[] = $category;
            }
            $area = trim($_POST['area'] ?? '');
            if ($area !== '') {
                $where[]  = 'l.address LIKE ?';
                $params[] = '%' . $area . '%';
            }
            $assignee = $_POST['assignee'] ?? '';
            if ($assignee === 'unassigned') {
                $where[] = 'l.assigned_to IS NULL';
            } elseif ($assignee === 'me') {
                $where[]  = 'l.assigned_to = ?';
                $params[] = $user_id;
            } elseif ((int) $assignee > 0) {
                $where[]  = 'l.assigned_to = ?';
                $params[] = (int) $assignee;
            }
            if (!empty($_POST['willing_only'])) {
                $where[] = 'l.willing_for_visit = 1';
            }
            $search = trim($_POST['search'] ?? '');
            if ($search !== '') {
                $where[]  = "(CONCAT_WS(' ', l.first_name, l.last_name) LIKE ? OR l.phone LIKE ?)";
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }
            // Members see their own overdue leads; managers see everyone's.
            $overdue_scope = reach_overdue_sql(reach_overdue_days($pdo)) . ($is_manager ? '' : ' AND l.assigned_to = ' . $user_id);
            if (!empty($_POST['overdue_only'])) {
                $where[] = $overdue_scope;
            }

            $sub_map = [
                'not_spoken' => "l.status = 'Not_Spoken_To'",
                'will_come'  => "l.status = 'Will_Attend'",
                'spoken'     => "l.status = 'Spoken_To'",
                'cold'       => "l.status = 'Cold'",
                'converted'  => 'l.pushed_to_embrace_at IS NOT NULL',
            ];
            $base_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $countStmt = $pdo->prepare("
                SELECT COUNT(*) AS `all`,
                       COALESCE(SUM({$sub_map['not_spoken']}), 0) AS not_spoken,
                       COALESCE(SUM({$sub_map['will_come']}), 0)  AS will_come,
                       COALESCE(SUM({$sub_map['spoken']}), 0)     AS spoken,
                       COALESCE(SUM({$sub_map['cold']}), 0)       AS cold,
                       COALESCE(SUM({$sub_map['converted']}), 0)  AS converted
                  FROM reach_leads l {$base_where}
            ");
            $countStmt->execute($params);
            $counts = array_map('intval', $countStmt->fetch(PDO::FETCH_ASSOC));

            $overdue = (int) $pdo->query("SELECT COUNT(*) FROM reach_leads l WHERE {$overdue_scope}")->fetchColumn();

            $sub = $_POST['sub_tab'] ?? 'all';
            if (isset($sub_map[$sub])) {
                $where[] = $sub_map[$sub];
            }
            $list_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $per_page = 24;
            $page     = max(1, (int) ($_POST['page'] ?? 1));
            $offset   = ($page - 1) * $per_page;

            $listStmt = $pdo->prepare("
                SELECT l.id, l.first_name, l.last_name, l.phone, l.category, l.willing_for_visit, l.will_attend_church,
                       l.address, l.status, l.assigned_to, l.assigned_at, l.pushed_to_embrace_at,
                       l.created_at, l.last_follow_up_at,
                       c.title AS campaign_title,
                       TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS assignee_name,
                       fc.captured_by_user_id AS capturer_user_id,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', fcu.first_name, fcu.last_name)), ''), fc.captured_by_guest_name) AS capturer_name,
                       fc.captured_at,
                       lf.outcome AS last_outcome, lf.created_at AS last_follow_up_date,
                       TRIM(CONCAT_WS(' ', lfu.first_name, lfu.last_name)) AS last_follower_name
                  FROM reach_leads l
                  LEFT JOIN reach_campaigns c ON c.id = l.campaign_id
                  LEFT JOIN users au ON au.id = l.assigned_to
                  LEFT JOIN reach_lead_captures fc ON fc.id = (SELECT MIN(x.id) FROM reach_lead_captures x WHERE x.lead_id = l.id)
                  LEFT JOIN users fcu ON fcu.id = fc.captured_by_user_id
                  LEFT JOIN reach_follow_ups lf ON lf.id = (SELECT MAX(y.id) FROM reach_follow_ups y WHERE y.lead_id = l.id)
                  LEFT JOIN users lfu ON lfu.id = lf.followed_up_by
                  {$list_where}
                 ORDER BY l.created_at DESC, l.id DESC
                 LIMIT {$per_page} OFFSET {$offset}
            ");
            $listStmt->execute($params);
            $leads = $listStmt->fetchAll(PDO::FETCH_ASSOC);

            $total = $sub === 'all' || !isset($counts[$sub]) ? $counts['all'] : $counts[$sub];
            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'leads'    => $leads,
                    'counts'   => $counts,
                    'overdue'  => $overdue,
                    'has_more' => ($offset + count($leads)) < $total,
                ]
            ]);
            break;

        // ------------------------------------------------------------------
        // fetch_lead_detail — drawer
        // ------------------------------------------------------------------
        case 'fetch_lead_detail':
            $lead_id = (int) ($_POST['lead_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT l.*, c.title AS campaign_title,
                       TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS assignee_name
                  FROM reach_leads l
                  LEFT JOIN reach_campaigns c ON c.id = l.campaign_id
                  LEFT JOIN users au ON au.id = l.assigned_to
                 WHERE l.id = ?
            ");
            $stmt->execute([$lead_id]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                echo json_encode(['status' => 'error', 'message' => 'Lead not found.']);
                exit;
            }

            $capStmt = $pdo->prepare("
                SELECT lc.captured_at, lc.captured_by_user_id,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), lc.captured_by_guest_name) AS name
                  FROM reach_lead_captures lc
                  LEFT JOIN users u ON u.id = lc.captured_by_user_id
                 WHERE lc.lead_id = ?
                 ORDER BY lc.id ASC
            ");
            $capStmt->execute([$lead_id]);

            $fuStmt = $pdo->prepare("
                SELECT f.id, f.channel, f.outcome, f.notes, f.next_touch_date, f.created_at,
                       TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS follower_name
                  FROM reach_follow_ups f
                  LEFT JOIN users u ON u.id = f.followed_up_by
                 WHERE f.lead_id = ?
                 ORDER BY f.id DESC
            ");
            $fuStmt->execute([$lead_id]);

            $asStmt = $pdo->prepare("
                SELECT a.action, a.created_at,
                       TRIM(CONCAT_WS(' ', tu.first_name, tu.last_name)) AS to_name,
                       TRIM(CONCAT_WS(' ', bu.first_name, bu.last_name)) AS by_name
                  FROM reach_lead_assignments a
                  LEFT JOIN users tu ON tu.id = a.to_user_id
                  LEFT JOIN users bu ON bu.id = a.assigned_by
                 WHERE a.lead_id = ?
                 ORDER BY a.id DESC
            ");
            $asStmt->execute([$lead_id]);

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'lead'        => $lead,
                    'captures'    => $capStmt->fetchAll(PDO::FETCH_ASSOC),
                    'follow_ups'  => $fuStmt->fetchAll(PDO::FETCH_ASSOC),
                    'assignments' => $asStmt->fetchAll(PDO::FETCH_ASSOC),
                    'can_assign'  => $is_manager,
                    'can_push'    => $is_manager || (int) $lead['assigned_to'] === $user_id,
                ]
            ]);
            break;

        // ------------------------------------------------------------------
        // log_follow_up
        // ------------------------------------------------------------------
        case 'log_follow_up':
            $lead_id = (int) ($_POST['lead_id'] ?? 0);
            $channel = $_POST['channel'] ?? '';
            $outcome = $_POST['outcome'] ?? '';
            if (!in_array($channel, REACH_CHANNELS, true) || !in_array($outcome, REACH_OUTCOMES, true)) {
                echo json_encode(['status' => 'error', 'message' => 'Pick a channel and an outcome.']);
                exit;
            }
            $exists = $pdo->prepare("SELECT id FROM reach_leads WHERE id = ?");
            $exists->execute([$lead_id]);
            if (!$exists->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Lead not found.']);
                exit;
            }
            $next = trim($_POST['next_touch_date'] ?? '');
            $nextDate = DateTime::createFromFormat('Y-m-d', $next);
            $next = ($nextDate && $nextDate->format('Y-m-d') === $next) ? $next : null;
            $notes = trim($_POST['notes'] ?? '');

            $pdo->prepare("
                INSERT INTO reach_follow_ups (lead_id, followed_up_by, channel, outcome, notes, next_touch_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$lead_id, $user_id, $channel, $outcome, $notes !== '' ? $notes : null, $next]);
            $pdo->prepare("UPDATE reach_leads SET last_follow_up_at = NOW() WHERE id = ?")->execute([$lead_id]);
            $status = reach_recompute_status($pdo, $lead_id);

            echo json_encode(['status' => 'success', 'message' => 'Follow-up logged.', 'data' => ['lead_status' => $status]]);
            break;

        // ------------------------------------------------------------------
        // assign_lead / reassign — managers only
        // ------------------------------------------------------------------
        case 'assign_lead':
        case 'reassign':
            if (!$is_manager) {
                reach_deny();
            }
            $lead_id = (int) ($_POST['lead_id'] ?? 0);
            $to      = (int) ($_POST['to_user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, assigned_to FROM reach_leads WHERE id = ?");
            $stmt->execute([$lead_id]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                echo json_encode(['status' => 'error', 'message' => 'Lead not found.']);
                exit;
            }
            if (!reach_is_member($pdo, $to)) {
                echo json_encode(['status' => 'error', 'message' => 'Pick a member of the Reach department.']);
                exit;
            }
            $from = $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : null;
            if ($from === $to) {
                echo json_encode(['status' => 'success', 'message' => 'Already assigned to them.', 'data' => ['noop' => true]]);
                break;
            }
            $assign_action = $from ? 'reassign' : 'assign';

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE reach_leads SET assigned_to = ?, assigned_at = NOW(), assigned_by = ? WHERE id = ?")
                ->execute([$to, $user_id, $lead_id]);
            $pdo->prepare("
                INSERT INTO reach_lead_assignments (lead_id, from_user_id, to_user_id, assigned_by, action, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$lead_id, $from, $to, $user_id, $assign_action, trim($_POST['notes'] ?? '') ?: null]);
            $pdo->commit();

            $lead_name = trim($lead['first_name'] . ' ' . $lead['last_name']);
            $by_name   = reach_user_name($pdo, $user_id);
            $to_name   = reach_user_name($pdo, $to);
            reach_notify($pdo, [$to], 'New Reach lead assigned', "{$by_name} assigned {$lead_name} to you for follow-up.");
            if ($from) {
                reach_notify($pdo, [$from], 'Reach lead reassigned', "{$lead_name} has been reassigned to {$to_name}.");
            }
            $capturer = (int) (reach_first_capture($pdo, $lead_id)['captured_by_user_id'] ?? 0);
            if ($capturer && !in_array($capturer, [$to, $from, $user_id], true)) {
                reach_notify($pdo, [$capturer], 'Your Reach capture is being followed up', "{$lead_name}, whom you captured, is now assigned to {$to_name}.");
            }

            echo json_encode(['status' => 'success', 'message' => "Assigned to {$to_name}."]);
            break;

        // ------------------------------------------------------------------
        // self_claim — any Reach member, unassigned leads only
        // ------------------------------------------------------------------
        case 'self_claim':
            $lead_id = (int) ($_POST['lead_id'] ?? 0);
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE reach_leads SET assigned_to = ?, assigned_at = NOW(), assigned_by = ? WHERE id = ? AND assigned_to IS NULL");
            $upd->execute([$user_id, $user_id, $lead_id]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'This lead has already been claimed.']);
                exit;
            }
            $pdo->prepare("
                INSERT INTO reach_lead_assignments (lead_id, from_user_id, to_user_id, assigned_by, action)
                VALUES (?, NULL, ?, ?, 'self_claim')
            ")->execute([$lead_id, $user_id, $user_id]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Lead claimed — it is yours to follow up.']);
            break;

        // ------------------------------------------------------------------
        // push_to_embrace — create (or link) a 1st_Timer user. Idempotent.
        // ------------------------------------------------------------------
        case 'push_to_embrace':
            $lead_id = (int) ($_POST['lead_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT l.*, c.title AS campaign_title
                  FROM reach_leads l LEFT JOIN reach_campaigns c ON c.id = l.campaign_id
                 WHERE l.id = ?
            ");
            $stmt->execute([$lead_id]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                echo json_encode(['status' => 'error', 'message' => 'Lead not found.']);
                exit;
            }
            if (!$is_manager && (int) $lead['assigned_to'] !== $user_id) {
                reach_deny('Only the assignee or a Reach HOD/Director can push this lead.');
            }
            if (!empty($lead['pushed_to_embrace_at'])) {
                echo json_encode(['status' => 'success', 'message' => 'Already pushed to Embrace.', 'data' => ['noop' => true, 'user_id' => (int) $lead['converted_to_user_id']]]);
                break;
            }

            $digits = preg_replace('/[^0-9]/', '', (string) $lead['phone']);
            if (strlen($digits) < 9) {
                echo json_encode(['status' => 'error', 'message' => 'Add a full phone number before pushing — Embrace follows up by phone.']);
                exit;
            }
            // Same phone-matching rule as Embrace's own intake, so we link
            // instead of creating a duplicate profile.
            $dupStmt = $pdo->prepare("SELECT id FROM users WHERE phone LIKE ? LIMIT 1");
            $dupStmt->execute(['%' . substr($digits, -9) . '%']);
            $existing_id = $dupStmt->fetchColumn();

            $capturer   = reach_first_capture($pdo, $lead_id)['name'] ?: 'a volunteer';
            $invited_by = 'Reach: ' . $capturer . ' — ' . ($lead['campaign_title'] ?: 'Reach');
            $lead_name  = trim($lead['first_name'] . ' ' . $lead['last_name']);

            $pdo->beginTransaction();
            if ($existing_id) {
                $new_user_id = (int) $existing_id;
            } else {
                $marital = in_array($lead['marital_status'], ['Single', 'Married', 'Widowed', 'Divorced'], true) ? $lead['marital_status'] : 'Single';
                $pdo->prepare("
                    INSERT INTO users (first_name, last_name, phone, marital_status, physical_address,
                                       spiritual_status, invited_by, prayer_requests, qr_code_hash)
                    VALUES (?, ?, ?, ?, ?, '1st_Timer', ?, ?, ?)
                ")->execute([
                    $lead['first_name'], $lead['last_name'] ?? '', $lead['phone'], $marital,
                    $lead['address'] ?: 'To be updated', $invited_by, $lead['prayer_request'],
                    hash('sha256', bin2hex(random_bytes(16)) . $digits)
                ]);
                $new_user_id = (int) $pdo->lastInsertId();
            }
            $upd = $pdo->prepare("
                UPDATE reach_leads
                   SET converted_to_user_id = ?, pushed_to_embrace_at = NOW(), status = 'Converted',
                       existing_member_user_id = COALESCE(existing_member_user_id, ?)
                 WHERE id = ? AND pushed_to_embrace_at IS NULL
            ");
            $upd->execute([$new_user_id, $existing_id ? $new_user_id : null, $lead_id]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['status' => 'success', 'message' => 'Already pushed to Embrace.', 'data' => ['noop' => true]]);
                break;
            }
            $pdo->commit();

            $embrace_leaders = $pdo->query("
                SELECT ud.user_id FROM user_departments ud
                JOIN departments d ON ud.department_id = d.id
                WHERE d.name LIKE '%Embrace%' AND ud.role_in_dept IN ('Director', 'HOD') AND ud.is_active = 1
            ")->fetchAll(PDO::FETCH_COLUMN);
            reach_notify($pdo, $embrace_leaders, 'New 1st Timer from Reach',
                "{$lead_name} was met through Reach ({$lead['campaign_title']}) and is waiting in the Embrace queue.",
                '/modules/embrace/index.php');
            if (!$existing_id) {
                $idi_users = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 1 AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                reach_notify($pdo, $idi_users, 'New Reach Profile',
                    "A profile for {$lead_name} was created from Reach. Please review the entry.",
                    '/modules/congregation/index.php');
            }

            echo json_encode([
                'status'  => 'success',
                'message' => $existing_id ? 'Already in the family database — linked, not duplicated.' : 'Marked as visited church and sent to Embrace.',
                'data'    => ['user_id' => $new_user_id, 'linked_existing' => (bool) $existing_id]
            ]);
            break;

        // ------------------------------------------------------------------
        // Analytics (Tab 3), PDF report and CSV export
        // ------------------------------------------------------------------
        case 'fetch_analytics':
            [$from, $to] = reach_date_range();
            echo json_encode(['status' => 'success', 'data' => reach_analytics($pdo, $from, $to)]);
            break;

        case 'generate_pdf':
            [$from, $to] = reach_date_range();
            try {
                require_once '../includes/reach_report_pdf.php';
                $pdf = reach_build_report_pdf($pdo, reach_analytics($pdo, $from, $to), reach_user_name($pdo, $user_id) ?: 'Reach');
                $dir = __DIR__ . '/../uploads/reach_reports';
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    throw new RuntimeException('cannot create ' . $dir);
                }
                // Random suffix: the report carries prayer requests and uploads/ is web-served.
                $filename = date('Ymd_His') . "_reach_{$from}_{$to}_" . bin2hex(random_bytes(6)) . '.pdf';
                if (file_put_contents($dir . '/' . $filename, $pdf) === false) {
                    throw new RuntimeException('cannot write ' . $filename);
                }
            } catch (Throwable $e) {
                error_log('Reach PDF error: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Could not generate the PDF. Please try again.']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => ['url' => '/uploads/reach_reports/' . $filename, 'filename' => $filename]]);
            break;

        case 'export_csv':
            [$from, $to] = reach_date_range();
            $stmt = $pdo->prepare("
                SELECT l.created_at, l.first_name, l.last_name, l.phone, l.category, l.status,
                       l.willing_for_visit, l.address, c.title AS campaign,
                       (SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), lc.captured_by_guest_name)
                          FROM reach_lead_captures lc LEFT JOIN users u ON u.id = lc.captured_by_user_id
                         WHERE lc.lead_id = l.id ORDER BY lc.id LIMIT 1) AS captured_by,
                       TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS assigned_to,
                       (SELECT COUNT(*) FROM reach_follow_ups f WHERE f.lead_id = l.id) AS follow_ups,
                       l.pushed_to_embrace_at
                  FROM reach_leads l
                  LEFT JOIN reach_campaigns c ON c.id = l.campaign_id
                  LEFT JOIN users au ON au.id = l.assigned_to
                 WHERE l.created_at >= ? AND l.created_at < ?
                 ORDER BY l.created_at
            ");
            $stmt->execute([$from . ' 00:00:00', (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reach_leads_' . $from . '_' . $to . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Captured', 'First name', 'Last name', 'Phone', 'Category', 'Status', 'Willing to visit', 'Area', 'Campaign', 'Captured by', 'Assigned to', 'Follow-ups', 'Pushed to Embrace'], ',', '"', '');
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $r['willing_for_visit'] = $r['willing_for_visit'] ? 'Yes' : 'No';
                $r['category'] = str_replace(['_', ','], [' ', ', '], $r['category']);
                fputcsv($out, array_map('reach_csv_safe', array_values($r)), ',', '"', '');
            }
            fclose($out);
            exit;

        case 'set_testimony_flag':
            if (!$is_manager) {
                reach_deny();
            }
            $pdo->prepare("UPDATE reach_leads SET share_testimony_in_report = ? WHERE id = ?")
                ->execute([!empty($_POST['share']) ? 1 : 0, (int) ($_POST['lead_id'] ?? 0)]);
            echo json_encode(['status' => 'success', 'message' => !empty($_POST['share']) ? 'Testimony will appear in the monthly report.' : 'Testimony removed from reports.']);
            break;

        // ------------------------------------------------------------------
        // Campaign settings: event types and the default campaign image
        // ------------------------------------------------------------------
        case 'fetch_campaign_settings':
            echo json_encode(['status' => 'success', 'data' => [
                'types'         => reach_campaign_types($pdo),
                'default_image' => reach_default_campaign_image($pdo),
                'is_custom'     => reach_setting($pdo, 'default_campaign_image') !== null,
                'overdue_days'  => reach_overdue_days($pdo),
                'guide'         => reach_evangelism_guide($pdo),
            ]]);
            break;

        case 'add_campaign_type':
            if (!$is_manager) {
                reach_deny();
            }
            $label = trim(preg_replace('/\s+/', ' ', $_POST['label'] ?? ''));
            $code  = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_');
            if ($label === '' || $code === '' || mb_strlen($label) > 60) {
                echo json_encode(['status' => 'error', 'message' => 'Give the event type a name (up to 60 characters).']);
                exit;
            }
            $code = substr($code, 0, 60);
            $next_sort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM reach_campaign_types WHERE code <> 'Other'")->fetchColumn();
            $pdo->prepare("
                INSERT INTO reach_campaign_types (code, label, sort_order) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE is_active = 1, label = VALUES(label)
            ")->execute([$code, $label, $next_sort]);
            echo json_encode(['status' => 'success', 'message' => 'Event type added.', 'data' => reach_campaign_types($pdo)]);
            break;

        case 'remove_campaign_type':
            if (!$is_manager) {
                reach_deny();
            }
            $code = $_POST['code'] ?? '';
            if ($code === 'Other') {
                echo json_encode(['status' => 'error', 'message' => '“Other” is always available.']);
                exit;
            }
            $pdo->prepare("UPDATE reach_campaign_types SET is_active = 0 WHERE code = ?")->execute([$code]);
            echo json_encode(['status' => 'success', 'message' => 'Event type removed. Existing campaigns keep it.', 'data' => reach_campaign_types($pdo)]);
            break;

        case 'upload_default_image':
            if (!$is_manager) {
                reach_deny();
            }
            try {
                $path = reach_store_image($_FILES['image'] ?? [], 'default');
            } catch (RuntimeException $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
            reach_delete_upload(reach_setting($pdo, 'default_campaign_image'));
            $pdo->prepare("
                INSERT INTO reach_settings (setting_key, setting_value) VALUES ('default_campaign_image', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([$path]);
            echo json_encode(['status' => 'success', 'message' => 'Default image updated.', 'data' => ['default_image' => $path]]);
            break;

        case 'save_reach_settings':
            if (!$is_manager) {
                reach_deny();
            }
            $days  = (int) ($_POST['overdue_days'] ?? 0);
            $guide = trim((string) ($_POST['guide'] ?? ''));
            if ($days < 1 || $days > 60) {
                echo json_encode(['status' => 'error', 'message' => 'Overdue days must be between 1 and 60.']);
                exit;
            }
            $set = $pdo->prepare("
                INSERT INTO reach_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $set->execute(['overdue_days', (string) $days]);
            // An empty guide or one identical to the built-in text means "use the default".
            if ($guide === '' || $guide === trim((string) @file_get_contents(REACH_DEFAULT_GUIDE_FILE))) {
                $pdo->prepare("DELETE FROM reach_settings WHERE setting_key = 'evangelism_guide'")->execute();
            } else {
                $set->execute(['evangelism_guide', mb_substr($guide, 0, 20000)]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Settings saved.']);
            break;

        case 'reset_default_image':
            if (!$is_manager) {
                reach_deny();
            }
            reach_delete_upload(reach_setting($pdo, 'default_campaign_image'));
            $pdo->prepare("DELETE FROM reach_settings WHERE setting_key = 'default_campaign_image'")->execute();
            echo json_encode(['status' => 'success', 'message' => 'Back to the church photo.', 'data' => ['default_image' => REACH_FALLBACK_CAMPAIGN_IMAGE]]);
            break;

        // ------------------------------------------------------------------
        // suggest_share_copy — 3 meta description + scripture pairs
        // ------------------------------------------------------------------
        case 'suggest_share_copy':
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                echo json_encode(['status' => 'error', 'message' => 'Enter the campaign title first.']);
                exit;
            }
            $facts = array_filter([
                'Title'    => $title,
                'Type'     => str_replace('_', ' ', trim($_POST['campaign_type'] ?? '')),
                'Location' => trim($_POST['location'] ?? ''),
                'Date'     => trim($_POST['campaign_date'] ?? ''),
            ]);
            $prompt = "You write WhatsApp invitation copy for outreach campaigns of Household of David Lekki Centre, a church in Lagos, Nigeria.\n"
                . "Campaign details:\n" . implode("\n", array_map(fn($k, $v) => "- {$k}: {$v}", array_keys($facts), $facts)) . "\n\n"
                . "Return ONLY JSON: {\"options\":[{\"meta_description\":\"...\",\"share_scripture\":\"...\"}]} with exactly 3 options.\n"
                . "meta_description: a warm, welcoming one- or two-sentence invitation that mentions the place, at most 150 characters, no hashtags or emojis.\n"
                . "share_scripture: a real Bible verse that fits the outreach, formatted \"Book chapter:verse — verse text\" (NKJV wording), at most 170 characters. Use a different verse in each option.";
            $raw  = reach_gemini($prompt, 0.9, true);
            $data = $raw ? json_decode($raw, true) : null;
            $options = [];
            foreach (($data['options'] ?? []) as $o) {
                $meta = trim((string) ($o['meta_description'] ?? ''));
                $verse = trim((string) ($o['share_scripture'] ?? ''));
                if ($meta !== '' && $verse !== '') {
                    $options[] = ['meta_description' => mb_substr($meta, 0, 300), 'share_scripture' => mb_substr($verse, 0, 300)];
                }
            }
            if (!$options) {
                echo json_encode(['status' => 'error', 'message' => 'The AI writer is unavailable right now. Please write it yourself or try again.']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => array_slice($options, 0, 3)]);
            break;

        case 'list_reach_members':
            echo json_encode(['status' => 'success', 'data' => reach_members($pdo)]);
            break;

        case 'fetch_sidebar_counts':
            echo json_encode(['status' => 'success', 'data' => reach_sidebar_counts($pdo, $user_id)]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reach API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
