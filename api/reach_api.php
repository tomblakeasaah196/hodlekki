<?php
// /api/reach_api.php

require_once '../includes/db.php';
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
                    c.share_scripture, c.payload_tier, c.status, c.created_by,
                    c.created_at, c.updated_at,
                    DATE_FORMAT(c.campaign_date, '%M %D, %Y') AS nice_date,
                    (SELECT COUNT(*) FROM reach_leads l WHERE l.campaign_id = c.id) AS souls_count
                FROM reach_campaigns c
                ORDER BY (c.campaign_date IS NULL), c.campaign_date DESC, c.id DESC
            ");
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

            $type            = trim($_POST['campaign_type'] ?? 'Saturday_Evangelism');
            $date            = !empty($_POST['campaign_date']) ? $_POST['campaign_date'] : null;
            $start_time      = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
            $end_time        = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
            $location        = trim($_POST['location'] ?? '');
            $meta            = trim($_POST['meta_description'] ?? '');
            $scripture       = trim($_POST['share_scripture'] ?? '');
            $tier_raw        = $_POST['payload_tier'] ?? 'Rich';
            $tier            = in_array($tier_raw, ['Rapid', 'Standard', 'Rich'], true) ? $tier_raw : 'Rich';

            $base_slug = reach_slugify($title . ($date ? ' ' . $date : ''));
            $slug      = reach_unique_slug($pdo, $base_slug);

            $stmt = $pdo->prepare("
                INSERT INTO reach_campaigns
                    (slug, title, campaign_type, campaign_date, start_time, end_time,
                     location, meta_description, share_scripture, payload_tier, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)
            ");
            $stmt->execute([
                $slug, $title, $type, $date, $start_time, $end_time,
                $location, $meta, $scripture, $tier, $user_id
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

            $existingStmt = $pdo->prepare("SELECT slug, title FROM reach_campaigns WHERE id = ?");
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
            $type            = trim($_POST['campaign_type'] ?? 'Saturday_Evangelism');
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
                       meta_description = ?, share_scripture = ?,
                       payload_tier = ?, status = ?
                 WHERE id = ?
            ");
            $upd->execute([
                $slug, $title, $type, $date, $start_time, $end_time, $location,
                $meta, $scripture, $tier, $status, $id
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
                SELECT id, slug, title, meta_description, share_scripture, campaign_date, location
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
                ]
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }
} catch (PDOException $e) {
    error_log('Reach API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
