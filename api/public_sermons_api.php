<?php
// /api/public_sermons_api.php

require_once '../includes/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'fetch_sermons':
            $stmt = $pdo->query("
                SELECT id, slug, title, preacher, service_type, cover_image_path, summary, 
                       COALESCE(view_count, 0) as view_count, 
                       COALESCE(play_count, 0) as play_count,
                       DATE_FORMAT(date_preached, '%M %D, %Y') as nice_date
                FROM sermons 
                WHERE is_published = 1 
                ORDER BY date_preached DESC
            ");
            echo json_encode(['status' => 'success', 'sermons' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_sermon':
            $slug = trim($_GET['slug'] ?? '');
            if (empty($slug)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid Sermon Link.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT *, DATE_FORMAT(date_preached, '%M %D, %Y') as nice_date 
                FROM sermons 
                WHERE slug = ? AND is_published = 1
            ");
            $stmt->execute([$slug]);
            $sermon = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sermon) {
                echo json_encode(['status' => 'error', 'message' => 'Sermon not found or is currently offline.']);
                exit;
            }

            // Keep the comments query exactly as it is, using $sermon['id']
            $commentsStmt = $pdo->prepare("
                SELECT author_name, comment_text, is_question, DATE_FORMAT(created_at, '%b %d, %Y') as nice_date 
                FROM sermon_comments 
                WHERE sermon_id = ? AND is_approved = 1 AND show_publicly = 1
                ORDER BY created_at DESC
            ");
            $commentsStmt->execute([$sermon['id']]);
            $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'sermon' => $sermon, 'comments' => $comments]);
            break;

        case 'track_analytics':
            $sermon_id = filter_input(INPUT_POST, 'sermon_id', FILTER_VALIDATE_INT);
            $type = $_POST['type'] ?? '';
            $valid_types = ['view', 'play', 'download'];

            if ($sermon_id && in_array($type, $valid_types)) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; 
                $stmt = $pdo->prepare("INSERT INTO sermon_analytics_logs (sermon_id, action_type, ip_address) VALUES (?, ?, ?)");
                $stmt->execute([$sermon_id, $type, $ip]);
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid tracking data.']);
            }
            break;

        // ==========================================
        // ACTION: SUBMIT COMMENT (Public Facing)
        // ==========================================
        case 'submit_comment':
            $honeypot = $_POST['honeypot_contact'] ?? '';
            if (!empty($honeypot)) {
                echo json_encode(['status' => 'success', 'message' => 'Reflection posted!']);
                exit;
            }

            $time_opened = (int)($_POST['time_opened'] ?? 0);
            $current_time = time() * 1000;
            if ($time_opened > 0 && ($current_time - $time_opened) < 3000) {
                echo json_encode(['status' => 'success', 'message' => 'Reflection posted!']);
                exit;
            }

            $sermon_id = filter_input(INPUT_POST, 'sermon_id', FILTER_VALIDATE_INT);
            $author_name = htmlspecialchars(strip_tags(trim($_POST['author_name'] ?? '')), ENT_QUOTES, 'UTF-8');
            $comment_text = htmlspecialchars(strip_tags(trim($_POST['comment_text'] ?? '')), ENT_QUOTES, 'UTF-8');
            
            if (empty($author_name)) $author_name = 'Anonymous';
            $is_question = isset($_POST['is_question']) ? 1 : 0;
            $show_publicly = 1; 

            if (!$sermon_id || empty($comment_text)) {
                echo json_encode(['status' => 'error', 'message' => 'Please write a valid reflection before submitting.']);
                exit;
            }

            $is_approved = 1; 
            $text_to_check = strtolower($author_name . ' ' . $comment_text);
            $suspicious_keywords = [
                'http://', 'https://', 'www.', '.com', '.org', '.net', 
                'crypto', 'bitcoin', 'cashapp', 'telegram', 'whatsapp', 
                'investment', 'forex', 'prophet', 'spell', 'lottery'
            ];

            foreach ($suspicious_keywords as $keyword) {
                if (strpos($text_to_check, $keyword) !== false) {
                    $is_approved = 0; 
                    break; 
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO sermon_comments (sermon_id, author_name, comment_text, is_question, show_publicly, is_approved) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sermon_id, $author_name, $comment_text, $is_question, $show_publicly, $is_approved]);

            // NOTIFICATION TRIGGER: Alert Envision Leadership of the new engagement
            $envStmt = $pdo->query("
                SELECT ud.user_id 
                FROM user_departments ud 
                JOIN departments d ON ud.department_id = d.id 
                WHERE d.name LIKE '%Envision%' AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
            ");
            $env_leaders = $envStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($env_leaders)) {
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Sermon Engagement', ?, '/modules/envision/index.php')");
                
                // Tailor the message based on what happened
                if ($is_approved === 0) {
                    $alertMsg = "A sermon comment from {$author_name} was flagged by the automated spam filter and requires manual moderation.";
                } elseif ($is_question) {
                    $alertMsg = "{$author_name} submitted a new question on a recent sermon.";
                } else {
                    $alertMsg = "{$author_name} posted a new reflection on a recent sermon.";
                }

                foreach($env_leaders as $leader_id) {
                    $notifStmt->execute([$leader_id, $alertMsg]);
                }
            }

            if ($is_approved === 0) {
                echo json_encode(['status' => 'success', 'message' => 'Reflection received! It is pending pastoral review.']);
            } else {
                echo json_encode(['status' => 'success', 'message' => 'Reflection posted successfully!']);
            }
            break;
            
            // ==========================================
        // ACTION: CHECK LIVE BROADCAST STATUS
        // ==========================================
        case 'check_live_broadcast':
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('zeno_stream_url', 'is_live_audio_on')");
            $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            echo json_encode([
                'status' => 'success', 
                'is_live' => (int)($config['is_live_audio_on'] ?? 0),
                'stream_url' => $config['zeno_stream_url'] ?? ''
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid API route.']);
            break;
    }

} catch (PDOException $e) {
    // Returning the actual error safely so you can see exactly why it crashes on the frontend
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>