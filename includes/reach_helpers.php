<?php
// /includes/reach_helpers.php
// Shared by api/reach_api.php, api/reach_public_api.php,
// modules/reach/index.php and the Reach badge in includes/header.php.

const REACH_DEPT_SQL = "(d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%')";
const REACH_OVERDUE_DAYS = 5;

// Assigned more than REACH_OVERDUE_DAYS ago and nobody has followed up
// since the assignment.
function reach_overdue_sql(string $alias = 'l.'): string {
    return "({$alias}assigned_to IS NOT NULL AND {$alias}pushed_to_embrace_at IS NULL"
        . " AND {$alias}assigned_at < NOW() - INTERVAL " . REACH_OVERDUE_DAYS . " DAY"
        . " AND ({$alias}last_follow_up_at IS NULL OR {$alias}last_follow_up_at < {$alias}assigned_at))";
}

// Super Admin, pastors, or HOD/Director of the Reach department.
function reach_is_manager(PDO $pdo, int $user_id, string $active_role): bool {
    if (in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true)) {
        return true;
    }
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_departments ud
        JOIN departments d ON d.id = ud.department_id
        WHERE ud.user_id = ? AND ud.is_active = 1
          AND ud.role_in_dept IN ('HOD', 'Director') AND " . REACH_DEPT_SQL . "
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return (bool) $stmt->fetchColumn();
}

function reach_manager_ids(PDO $pdo): array {
    return $pdo->query("
        SELECT DISTINCT ud.user_id FROM user_departments ud
        JOIN departments d ON d.id = ud.department_id
        WHERE ud.is_active = 1 AND ud.role_in_dept IN ('HOD', 'Director') AND " . REACH_DEPT_SQL
    )->fetchAll(PDO::FETCH_COLUMN);
}

function reach_members(PDO $pdo): array {
    return $pdo->query("
        SELECT DISTINCT u.id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name
        FROM users u
        JOIN user_departments ud ON ud.user_id = u.id AND ud.is_active = 1
        JOIN departments d ON d.id = ud.department_id
        WHERE " . REACH_DEPT_SQL . "
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function reach_is_member(PDO $pdo, int $user_id): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_departments ud
        JOIN departments d ON d.id = ud.department_id
        WHERE ud.user_id = ? AND ud.is_active = 1 AND " . REACH_DEPT_SQL . "
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return (bool) $stmt->fetchColumn();
}

function reach_notify(PDO $pdo, array $user_ids, string $title, string $message, string $link = '/modules/reach/index.php'): void {
    $user_ids = array_unique(array_filter(array_map('intval', $user_ids)));
    if (!$user_ids) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)");
    foreach ($user_ids as $uid) {
        $stmt->execute([$uid, $title, $message, $link]);
    }
}

function reach_sidebar_counts(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(l.assigned_to IS NULL AND l.pushed_to_embrace_at IS NULL AND l.status <> 'Declined'), 0) AS unassigned_all,
            COALESCE(SUM(l.assigned_to = ? AND l.pushed_to_embrace_at IS NULL), 0) AS my_assigned,
            COALESCE(SUM(l.assigned_to = ? AND " . reach_overdue_sql() . "), 0) AS my_overdue
        FROM reach_leads l
    ");
    $stmt->execute([$user_id, $user_id]);
    return array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));
}

const REACH_CATEGORY_ORDER = ['New_Convert', 'Unsaved', 'Saved', 'Broken', 'Dechurched', 'Other'];

function reach_pct(int $part, int $whole): float {
    return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
}

// Everything the Analytics tab and the PDF report show, for leads created
// between $from and $to inclusive (Y-m-d).
function reach_analytics(PDO $pdo, string $from, string $to): array {
    $lo = $from . ' 00:00:00';
    $hi = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $has_fu      = 'EXISTS(SELECT 1 FROM reach_follow_ups f WHERE f.lead_id = l.id)';
    $has_reached = "EXISTS(SELECT 1 FROM reach_follow_ups f WHERE f.lead_id = l.id AND f.outcome = 'Reached')";
    $has_visit   = "EXISTS(SELECT 1 FROM reach_follow_ups f WHERE f.lead_id = l.id AND f.channel = 'In_Person_Visit')";

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS souls,
               COALESCE(SUM({$has_fu}), 0) AS followed,
               COALESCE(SUM({$has_reached}), 0) AS spoken,
               COALESCE(SUM(l.pushed_to_embrace_at IS NOT NULL OR {$has_visit}), 0) AS visited_or_converted,
               COALESCE(SUM(l.pushed_to_embrace_at IS NOT NULL), 0) AS converted,
               COALESCE(SUM(" . reach_overdue_sql() . "), 0) AS overdue
          FROM reach_leads l
         WHERE l.created_at >= ? AND l.created_at < ?
    ");
    $stmt->execute([$lo, $hi]);
    $t = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reach_campaigns c
         WHERE c.status <> 'Cancelled' AND COALESCE(c.campaign_date, DATE(c.created_at)) BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    $campaigns_run = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT l.category, COUNT(*) AS n FROM reach_leads l WHERE l.created_at >= ? AND l.created_at < ? GROUP BY l.category");
    $stmt->execute([$lo, $hi]);
    $by_cat = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $categories = [];
    foreach (REACH_CATEGORY_ORDER as $c) {
        $categories[] = ['category' => $c, 'count' => (int) ($by_cat[$c] ?? 0)];
    }

    $stmt = $pdo->prepare("
        SELECT MIN(TRIM(l.address)) AS area, COUNT(*) AS count
          FROM reach_leads l
         WHERE l.created_at >= ? AND l.created_at < ? AND TRIM(COALESCE(l.address, '')) <> ''
         GROUP BY LOWER(TRIM(l.address))
         ORDER BY count DESC, area
         LIMIT 15
    ");
    $stmt->execute([$lo, $hi]);
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Guests are grouped by phone (then name) so one guest isn't split
    // across rows.
    $stmt = $pdo->prepare("
        SELECT MAX(lc.captured_by_user_id) AS user_id,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', MAX(u.first_name), MAX(u.last_name))), ''), MAX(lc.captured_by_guest_name), 'Guest') AS name,
               COUNT(DISTINCT lc.lead_id) AS souls
          FROM reach_lead_captures lc
          LEFT JOIN users u ON u.id = lc.captured_by_user_id
         WHERE lc.captured_at >= ? AND lc.captured_at < ?
         GROUP BY COALESCE(CONCAT('u', lc.captured_by_user_id), CONCAT('g', lc.captured_by_guest_phone), CONCAT('n', lc.captured_by_guest_name), 'anon')
         ORDER BY souls DESC, name
         LIMIT 10
    ");
    $stmt->execute([$lo, $hi]);
    $volunteers = array_map(fn($v) => [
        'name' => $v['name'], 'souls' => (int) $v['souls'], 'is_member' => $v['user_id'] !== null,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.campaign_date,
               COUNT(l.id) AS souls,
               COALESCE(SUM({$has_fu}), 0) AS followed,
               COALESCE(SUM(l.pushed_to_embrace_at IS NOT NULL), 0) AS converted,
               MAX(l.created_at) AS last_capture,
               (SELECT MAX(f.created_at) FROM reach_follow_ups f JOIN reach_leads l2 ON l2.id = f.lead_id WHERE l2.campaign_id = c.id) AS last_follow_up
          FROM reach_campaigns c
          LEFT JOIN reach_leads l ON l.campaign_id = c.id AND l.created_at >= ? AND l.created_at < ?
         WHERE COALESCE(c.campaign_date, DATE(c.created_at)) BETWEEN ? AND ? OR l.id IS NOT NULL
         GROUP BY c.id, c.title, c.campaign_date
         ORDER BY (c.campaign_date IS NULL), c.campaign_date DESC, c.id DESC
    ");
    $stmt->execute([$lo, $hi, $from, $to]);
    $campaigns = array_map(fn($c) => [
        'title'           => $c['title'],
        'date'            => $c['campaign_date'],
        'souls'           => (int) $c['souls'],
        'follow_up_rate'  => reach_pct((int) $c['followed'], (int) $c['souls']),
        'conversion_rate' => reach_pct((int) $c['converted'], (int) $c['souls']),
        'last_activity'   => max((string) $c['last_capture'], (string) $c['last_follow_up']) ?: null,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        'range' => ['from' => $from, 'to' => $to],
        'kpis'  => [
            'souls'           => $t['souls'],
            'campaigns'       => $campaigns_run,
            'follow_up_rate'  => reach_pct($t['followed'], $t['souls']),
            'conversion_rate' => reach_pct($t['converted'], $t['souls']),
            'overdue'         => $t['overdue'],
        ],
        'funnel' => [
            ['stage' => 'Captured', 'count' => $t['souls']],
            ['stage' => 'Spoken To', 'count' => $t['spoken']],
            ['stage' => 'Visited / Converted', 'count' => $t['visited_or_converted']],
        ],
        'category_breakdown'    => $categories,
        'area_breakdown'        => array_map(fn($a) => ['area' => $a['area'], 'count' => (int) $a['count']], $areas),
        'volunteer_leaderboard' => $volunteers,
        'campaigns_table'       => $campaigns,
    ];
}

const REACH_FALLBACK_CAMPAIGN_IMAGE = '/assets/images/hod_lekki.jpeg';

// Returns the model's text, or null on any failure (no key, network, non-200).
function reach_gemini(string $prompt, float $temperature, bool $json = false): ?string {
    $key = $_ENV['GEMINI_API_KEY'] ?? '';
    if ($key === '' || !function_exists('curl_init')) {
        return null;
    }
    $config = ['temperature' => $temperature];
    if ($json) {
        $config['responseMimeType'] = 'application/json';
    }
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $key);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_POSTFIELDS     => json_encode(['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => $config]),
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $response === false) {
        error_log('Reach Gemini: HTTP ' . $code);
        return null;
    }
    $text = trim(json_decode($response, true)['candidates'][0]['content']['parts'][0]['text'] ?? '');
    return $text !== '' ? $text : null;
}

function reach_setting(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare("SELECT setting_value FROM reach_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false || $v === null || $v === '') ? null : (string) $v;
}

function reach_default_campaign_image(PDO $pdo): string {
    try {
        return reach_setting($pdo, 'default_campaign_image') ?? REACH_FALLBACK_CAMPAIGN_IMAGE;
    } catch (PDOException $e) {
        return REACH_FALLBACK_CAMPAIGN_IMAGE;
    }
}

function reach_campaign_types(PDO $pdo, bool $active_only = true): array {
    return $pdo->query("
        SELECT code, label, is_active FROM reach_campaign_types
        " . ($active_only ? 'WHERE is_active = 1' : '') . "
        ORDER BY sort_order, label
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Validates by decoding the image itself (this host has no ext-fileinfo)
// and picks the extension from the detected type, never the client's name.
function reach_store_image(array $file, string $prefix): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The image did not upload. Please try again.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Images must be 5 MB or smaller.');
    }
    $info = @getimagesize($file['tmp_name']);
    $ext  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$info || !$ext) {
        throw new RuntimeException('Please use a JPG, PNG or WebP image.');
    }
    $dir = __DIR__ . '/../uploads/reach_campaigns';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the upload folder.');
    }
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the image.');
    }
    return '/uploads/reach_campaigns/' . $name;
}

function reach_delete_upload(?string $path): void {
    if ($path && str_starts_with($path, '/uploads/reach_campaigns/') && !str_contains($path, '..')) {
        @unlink(__DIR__ . '/..' . $path);
    }
}
