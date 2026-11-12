<?php
// /api/envision_api.php
//
// Envision, the media unit (modules/envision/index.php, assets/ui/js/pages/envision.js):
// the sermon archive and its media, the media crew, service duties, comment
// moderation and the live audio broadcast.
// Access: the 'envision' row of Roles & permissions.
//   View reads everything here; Create archives a sermon, Edit changes one and
//   logs service duties; Approve publishes or hides a sermon; Delete removes a
//   comment; Manage runs the crew roster and the live broadcast settings.
// Writes need the CSRF token. Uploads go through includes/upload_guard.php.

require_once '../includes/db.php';
csrf_require();
header('Content-Type: application/json');

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

api_require_can('envision', 'view');
$is_new_sermon = $action === 'save_sermon' && (int) ($_POST['sermon_id'] ?? $_GET['sermon_id'] ?? 0) === 0;
$levels = [
    'save_sermon' => $is_new_sermon ? 'create' : 'edit',
    'assign_duty' => 'edit',
    'toggle_publish' => 'approve',
    'delete_comment' => 'delete',
    'assign_member' => 'manage',
    'save_radio_config' => 'manage',
    'toggle_radio_live' => 'manage',
    'test_radio_connection' => 'manage',
];
if (isset($levels[$action])) {
    api_require_can('envision', $levels[$action]);
}

// The fixed lists the page offers; nothing else is stored.
$crew_roles = ['Videography', 'Photography', 'Audio_Engineering', 'Projection', 'Lighting', 'Note_Taking', 'Social_Media', 'Direction'];
$service_types = ['Total Experience', 'Mercy Experience', 'Special Program'];

/** A YouTube link as its embed address, '' when empty, null when it is not YouTube. */
function envision_youtube(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^(?:https?://)?(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&\#/].*)?$#i', $url, $m)) {
        return null;
    }
    return 'https://www.youtube.com/embed/' . $m[1];
}

/** True for a stream address the live player can use: http(s), no spaces or quotes. */
function envision_stream_ok(string $url): bool
{
    return (bool) preg_match('#^https?://[^\s"\'<>]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: DASHBOARD
        // ==========================================
        case 'fetch_dashboard':
            $stats = [
                'total_sermons' => (int) $pdo->query("SELECT COUNT(*) FROM sermons")->fetchColumn(),
                'published_sermons' => (int) $pdo->query("SELECT COUNT(*) FROM sermons WHERE is_published = 1")->fetchColumn(),
                'total_downloads' => (int) ($pdo->query("SELECT SUM(audio_downloads) FROM sermons")->fetchColumn() ?? 0),
                'pending_comments' => (int) $pdo->query("SELECT COUNT(*) FROM sermon_comments WHERE is_approved = 0")->fetchColumn()
            ];

            $sermons = $pdo->query("
                SELECT id, title, preacher, date_preached, service_type, cover_image_path, audio_file_path, youtube_link,
                       summary, full_notes, is_published,
                       (SELECT COUNT(*) FROM sermon_duties sd WHERE sd.sermon_id = sermons.id) as media_crew_count
                FROM sermons
                ORDER BY date_preached DESC LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);

            $roster = $pdo->query("
                SELECT e.id as roster_id, e.role_category, u.id as user_id, u.first_name, u.last_name, u.picture_path
                FROM envision_roster e
                JOIN users u ON e.user_id = u.id
                ORDER BY e.role_category ASC, u.first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // The Envision team (seated in a department that owns Envision) not yet on the crew roster.
            $team = array_map('intval', perm_module_team($pdo, 'envision'));
            $available = $pdo->query("
                SELECT u.id, u.first_name, u.last_name
                FROM users u
                WHERE u.id IN (" . ($team ? implode(',', $team) : '0') . ")
                AND u.id NOT IN (SELECT user_id FROM envision_roster)
                ORDER BY u.first_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'stats' => $stats,
                'sermons' => $sermons,
                'roster' => $roster,
                'available_members' => $available
            ]);
            break;

        // ==========================================
        // ACTION 2: CREW ROSTER (add or change a specialisation)
        // ==========================================
        case 'assign_member':
            $target_id = (int) ($_POST['user_id'] ?? 0);
            $category = $_POST['role_category'] ?? '';

            if ($target_id <= 0 || !in_array($category, $crew_roles, true)) {
                echo json_encode(['status' => 'error', 'message' => 'User and role category required.']);
                exit;
            }
            $onRoster = $pdo->prepare('SELECT 1 FROM envision_roster WHERE user_id = ?');
            $onRoster->execute([$target_id]);
            if (!$onRoster->fetchColumn() && !in_array($target_id, array_map('intval', perm_module_team($pdo, 'envision')), true)) {
                echo json_encode(['status' => 'error', 'field' => 'user_id', 'message' => 'Choose someone on the Envision team.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO envision_roster (user_id, role_category) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_category = VALUES(role_category)");
            $stmt->execute([$target_id, $category]);

            $cleanCategory = str_replace('_', ' ', $category);
            $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Envision Media Team', ?, '/modules/profile/index.php')");
            $notifStmt->execute([$target_id, "You have been officially placed on the Envision Media Roster as a {$cleanCategory} specialist."]);

            echo json_encode(['status' => 'success', 'message' => 'Media member assigned.']);
            break;

        // ==========================================
        // ACTION 3: SAVE SERMON (with the cover and the audio)
        // ==========================================
        case 'save_sermon':
            // PHP drops the whole payload when it is over post_max_size.
            $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($content_length > 0 && empty($_POST) && empty($_FILES)) {
                echo json_encode(['status' => 'error', 'message' => 'Upload failed. The file is larger than the server allows (post_max_size exceeded).']);
                exit;
            }

            $sermon_id = (int) ($_POST['sermon_id'] ?? $_GET['sermon_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $preacher = trim($_POST['preacher'] ?? '');
            $rawDate = (string) ($_POST['date_preached'] ?? '');
            $d = DateTime::createFromFormat('!Y-m-d', $rawDate);
            $date = $d && $d->format('Y-m-d') === $rawDate ? $rawDate : '';
            $type = (string) ($_POST['service_type'] ?? 'Total Experience');
            $summary = trim($_POST['summary'] ?? '');
            $notes = trim($_POST['full_notes'] ?? '');

            if ($title === '' || $preacher === '' || $date === '') {
                echo json_encode(['status' => 'error', 'message' => 'Title, preacher, and date are required. (If you filled these out, your file may still be too large).']);
                exit;
            }
            if (mb_strlen($title) > 255 || mb_strlen($preacher) > 150) {
                echo json_encode(['status' => 'error', 'message' => 'The title or the preacher\'s name is too long.']);
                exit;
            }
            $youtube = envision_youtube((string) ($_POST['youtube_link'] ?? ''));
            if ($youtube === null) {
                echo json_encode(['status' => 'error', 'field' => 'youtube_link', 'message' => 'Paste a YouTube link, or leave it empty.']);
                exit;
            }

            // What is stored now (an update keeps its files unless new ones arrive).
            $current = ['cover_image_path' => null, 'audio_file_path' => null, 'service_type' => null];
            if ($sermon_id) {
                $cur = $pdo->prepare('SELECT cover_image_path, audio_file_path, service_type FROM sermons WHERE id = ?');
                $cur->execute([$sermon_id]);
                $current = $cur->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$current) {
                    echo json_encode(['status' => 'error', 'message' => 'That sermon no longer exists.']);
                    exit;
                }
            }
            if (!in_array($type, $service_types, true) && $type !== (string) $current['service_type']) {
                $type = 'Special Program';
            }

            $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $title . '-' . $date), '-'));
            $slug = (string) preg_replace('/-+/', '-', $slug);

            require_once __DIR__ . '/../includes/upload_guard.php';
            $cover_path = $current['cover_image_path'];
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $stored = hod_upload_store($_FILES['cover_image'], __DIR__ . '/../uploads/sermons/covers/', '/uploads/sermons/covers/', ['jpg', 'jpeg', 'png', 'webp']);
                if (!$stored['ok']) {
                    echo json_encode(['status' => 'error', 'field' => 'cover_image', 'message' => 'Cover: ' . $stored['error']]);
                    exit;
                }
                $cover_path = $stored['url'];
            }
            $audio_path = $current['audio_file_path'];
            if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $stored = hod_upload_store($_FILES['audio_file'], __DIR__ . '/../uploads/sermons/audio/', '/uploads/sermons/audio/', ['mp3', 'wav', 'm4a']);
                if (!$stored['ok']) {
                    echo json_encode(['status' => 'error', 'field' => 'audio_file', 'message' => 'Audio: ' . $stored['error']]);
                    exit;
                }
                $audio_path = $stored['url'];
            }

            if (!$sermon_id) {
                $stmt = $pdo->prepare("INSERT INTO sermons (title, slug, preacher, date_preached, service_type, cover_image_path, audio_file_path, youtube_link, summary, full_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $preacher, $date, $type, $cover_path, $audio_path, $youtube, $summary, $notes]);
                $msg = "Sermon successfully archived!";
            } else {
                $stmt = $pdo->prepare("UPDATE sermons SET title=?, slug=?, preacher=?, date_preached=?, service_type=?, cover_image_path=?, audio_file_path=?, youtube_link=?, summary=?, full_notes=? WHERE id=?");
                $stmt->execute([$title, $slug, $preacher, $date, $type, $cover_path, $audio_path, $youtube, $summary, $notes, $sermon_id]);
                $msg = "Sermon details updated!";
            }

            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 4: PUBLISH / HIDE
        // ==========================================
        case 'toggle_publish':
            $sermon_id = (int) ($_POST['sermon_id'] ?? 0);
            $is_published = (int) ($_POST['is_published'] ?? 0) === 1 ? 1 : 0;

            $stmt = $pdo->prepare("UPDATE sermons SET is_published = ? WHERE id = ?");
            $stmt->execute([$is_published, $sermon_id]);

            $msg = $is_published === 1 ? 'Sermon is now LIVE on the public portal!' : 'Sermon hidden from public view.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // ==========================================
        // ACTION 5: SERVICE DUTIES
        // ==========================================
        case 'fetch_duties':
            $sermon_id = (int) ($_POST['sermon_id'] ?? $_GET['sermon_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT sd.id as duty_id, sd.duty_role, u.id as user_id, u.first_name, u.last_name
                FROM sermon_duties sd
                JOIN users u ON sd.user_id = u.id
                WHERE sd.sermon_id = ?
                ORDER BY sd.duty_role ASC
            ");
            $stmt->execute([$sermon_id]);
            echo json_encode(['status' => 'success', 'duties' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'assign_duty':
            $sermon_id = (int) ($_POST['sermon_id'] ?? 0);
            $crew_id = (int) ($_POST['user_id'] ?? 0);
            $role = $_POST['duty_role'] ?? '';

            if ($sermon_id <= 0 || $crew_id <= 0 || !in_array($role, $crew_roles, true)) {
                echo json_encode(['status' => 'error', 'message' => 'All fields required to assign duty.']);
                exit;
            }
            $onRoster = $pdo->prepare('SELECT 1 FROM envision_roster WHERE user_id = ?');
            $onRoster->execute([$crew_id]);
            if (!$onRoster->fetchColumn()) {
                echo json_encode(['status' => 'error', 'field' => 'user_id', 'message' => 'Choose someone on the media crew.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO sermon_duties (sermon_id, user_id, duty_role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE duty_role = VALUES(duty_role)");
                $stmt->execute([$sermon_id, $crew_id, $role]);

                $sermonStmt = $pdo->prepare("SELECT title, DATE_FORMAT(date_preached, '%b %D') as short_date FROM sermons WHERE id = ?");
                $sermonStmt->execute([$sermon_id]);
                $sermon = $sermonStmt->fetch(PDO::FETCH_ASSOC);
                if ($sermon) {
                    $cleanRole = str_replace('_', ' ', $role);
                    $notifMsg = "You have been assigned to '{$cleanRole}' for the upcoming service: {$sermon['title']} ({$sermon['short_date']}).";
                    $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Service Duty Assignment', ?, '/modules/profile/index.php')")
                        ->execute([$crew_id, $notifMsg]);
                }

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Media duty logged!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ==========================================
        // ACTION 6: COMMENT MODERATION
        // ==========================================
        case 'fetch_comments':
            $page = max(1, (int) ($_POST['page'] ?? $_GET['page'] ?? 1));
            $limit = 50;
            $offset = ($page - 1) * $limit;
            $search = trim((string) ($_POST['search'] ?? $_GET['search'] ?? ''));

            $whereClause = '';
            $params = [];
            if ($search !== '') {
                $whereClause = 'WHERE sc.comment_text LIKE ? OR sc.author_name LIKE ?';
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $params = [$like, $like];
            }

            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM sermon_comments sc $whereClause");
            $totalStmt->execute($params);
            $total = (int) $totalStmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT sc.id, sc.author_name, sc.comment_text, sc.created_at, s.title as sermon_title
                FROM sermon_comments sc
                LEFT JOIN sermons s ON sc.sermon_id = s.id
                $whereClause
                ORDER BY sc.created_at DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);

            echo json_encode([
                'status' => 'success',
                'comments' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'total_pages' => (int) ceil($total / $limit),
                'current_page' => $page,
                'total_records' => $total
            ]);
            break;

        case 'delete_comment':
            $stmt = $pdo->prepare("DELETE FROM sermon_comments WHERE id = ?");
            $stmt->execute([(int) ($_POST['comment_id'] ?? 0)]);
            echo json_encode(['status' => 'success', 'message' => 'Comment permanently deleted.']);
            break;

        // ==========================================
        // ACTION 7: LIVE AUDIO BROADCAST
        // ==========================================
        case 'fetch_radio_config':
            $config = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('zeno_stream_url', 'is_live_audio_on')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            echo json_encode([
                'status' => 'success',
                'zeno_stream_url' => $config['zeno_stream_url'] ?? '',
                'is_live_audio_on' => (int) ($config['is_live_audio_on'] ?? 0)
            ]);
            break;

        case 'save_radio_config':
            $stream_url = trim((string) ($_POST['zeno_stream_url'] ?? ''));
            if ($stream_url !== '' && (mb_strlen($stream_url) > 500 || !envision_stream_ok($stream_url))) {
                echo json_encode(['status' => 'error', 'field' => 'zeno_stream_url', 'message' => 'Paste the stream\'s listen link (http or https).']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('zeno_stream_url', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$stream_url]);
            echo json_encode(['status' => 'success', 'message' => 'Broadcast settings saved successfully!']);
            break;

        case 'toggle_radio_live':
            $is_live = (int) ($_POST['is_live'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('is_live_audio_on', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([(string) $is_live]);

            $msg = $is_live === 1 ? 'Live Audio Broadcast is now ON! The player is visible to the public.' : 'Broadcast ended. The player is now hidden.';
            echo json_encode(['status' => 'success', 'message' => $msg, 'is_live' => $is_live]);
            break;

        case 'test_radio_connection':
            $stream_url = (string) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'zeno_stream_url'")->fetchColumn();
            if ($stream_url === '' || !envision_stream_ok($stream_url)) {
                echo json_encode(['status' => 'error', 'message' => 'Please save a valid stream URL first.']);
                exit;
            }

            // Headers only, http(s) only (no file://, gopher:// and the like), 5 seconds.
            $ch = curl_init($stream_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            $live = $httpCode >= 200 && $httpCode < 400 && str_contains($contentType, 'audio/');
            echo json_encode(['status' => 'success', 'live' => $live, 'message' => $live
                ? 'Connection successful! Audio stream is active and responding correctly.'
                : 'The URL answers, but no audio stream was detected. Make sure OBS is streaming.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            break;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Envision API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
