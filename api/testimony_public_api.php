<?php
// /api/testimony_public_api.php

require_once '../includes/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Session is optional here since guests can interact
$user_id = $_SESSION['user_id'] ?? null;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH PUBLISHED TESTIMONIES (The Public Wall)
        // =====================================================================================
        case 'fetch_wall':
            $stmt = $pdo->query("
                SELECT t.id, t.content_text, t.voice_note_url, t.privacy_level, 
                       COALESCE(t.hallelujah_count, 0) as hallelujah_count, 
                       DATE_FORMAT(t.created_at, '%M %D, %Y') as nice_date,
                       t.guest_name, u.first_name, u.last_name, u.gender,
                       (SELECT COUNT(*) FROM testimony_comments c WHERE c.testimony_id = t.id AND c.is_approved = 1) as comment_count
                FROM testimonies t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE t.status = 'Published'
                ORDER BY t.created_at DESC
            ");
            
            $testimonies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Privacy Masking Logic (Happens securely on the server)
            foreach ($testimonies as &$t) {
                if ($t['privacy_level'] === 'Anonymous_To_All' || $t['privacy_level'] === 'Pastoral_Only') {
                    $t['display_name'] = 'Anonymous';
                    $t['avatar_initials'] = '?';
                } else {
                    if (!empty($t['first_name'])) {
                        $t['display_name'] = htmlspecialchars($t['first_name'] . ' ' . $t['last_name'], ENT_QUOTES, 'UTF-8');
                        $t['avatar_initials'] = strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1));
                    } else {
                        $t['display_name'] = htmlspecialchars($t['guest_name'] ?: 'Guest', ENT_QUOTES, 'UTF-8');
                        $t['avatar_initials'] = strtoupper(substr($t['display_name'], 0, 2));
                    }
                }
                
                // Clean up data to minimize payload and prevent data leakage
                unset($t['first_name'], $t['last_name'], $t['guest_name']);
            }

            echo json_encode(['status' => 'success', 'testimonies' => $testimonies]);
            break;

        // =====================================================================================
        // ACTION 2: SUBMIT A NEW TESTIMONY (Handles Text & Voice Notes with Spam Protection)
        // =====================================================================================
        case 'submit_testimony':
            // 1. Bot & Spam Protection (Honeypot & Time-based)
            $honeypot = $_POST['honeypot_contact'] ?? '';
            if (!empty($honeypot)) {
                echo json_encode(['status' => 'success', 'message' => 'Testimony received!']);
                exit;
            }

            $time_opened = (int)($_POST['time_opened'] ?? 0);
            $current_time = time() * 1000;
            if ($time_opened > 0 && ($current_time - $time_opened) < 3000) {
                echo json_encode(['status' => 'success', 'message' => 'Testimony received!']);
                exit;
            }

            // 2. Data Sanitization
            $guest_name = htmlspecialchars(strip_tags(trim($_POST['guest_name'] ?? '')), ENT_QUOTES, 'UTF-8');
            $guest_email = filter_var(trim($_POST['guest_email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $content_text = htmlspecialchars(strip_tags(trim($_POST['content_text'] ?? '')), ENT_QUOTES, 'UTF-8');
            $privacy_level = $_POST['privacy_level'] ?? 'Public';
            $request_editing = isset($_POST['request_editing']) && $_POST['request_editing'] == '1' ? 1 : 0;
            
            // 3. Keyword Spam Filtering
            $is_spam = 0;
            $text_to_check = strtolower($guest_name . ' ' . $content_text);
            $suspicious_keywords = ['http://', 'https://', 'www.', '.com', 'crypto', 'bitcoin', 'investment', 'forex', 'lottery'];
            foreach ($suspicious_keywords as $keyword) {
                if (strpos($text_to_check, $keyword) !== false) {
                    $is_spam = 1;
                    break;
                }
            }

            $status = ($request_editing || $is_spam) ? 'Pending_Editing' : 'Published';

            // 4. Secure Audio Upload Handling
            $voice_note_url = null;
            if (isset($_FILES['voice_note']) && $_FILES['voice_note']['error'] === UPLOAD_ERR_OK) {
                $max_file_size = 15 * 1024 * 1024; // 15MB limit
                if ($_FILES['voice_note']['size'] > $max_file_size) {
                    echo json_encode(['status' => 'error', 'message' => 'Audio file exceeds the 15MB limit.']);
                    exit;
                }

                // Verify exact MIME type natively, bypassing spoofed extensions
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $_FILES['voice_note']['tmp_name']);
                finfo_close($finfo);

                $allowed_mimes = [
                    'audio/mpeg' => 'mp3',
                    'audio/wav' => 'wav',
                    'audio/x-wav' => 'wav',
                    'audio/ogg' => 'ogg',
                    'audio/mp4' => 'm4a',
                    'audio/webm' => 'webm',
                    'audio/aac' => 'aac'
                ];

                if (array_key_exists($mime_type, $allowed_mimes)) {
                    $upload_dir = '../uploads/testimonies/audio/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    
                    // Generate a cryptographically secure filename
                    $ext = $allowed_mimes[$mime_type];
                    $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    
                    if (move_uploaded_file($_FILES['voice_note']['tmp_name'], $upload_dir . $filename)) {
                        $voice_note_url = '/uploads/testimonies/audio/' . $filename; 
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Failed to process audio file.']);
                        exit;
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid audio format detected.']);
                    exit;
                }
            }

            if (empty($content_text) && empty($voice_note_url)) {
                echo json_encode(['status' => 'error', 'message' => 'You must provide either a written testimony or a voice note.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO testimonies (user_id, guest_name, guest_email, content_text, voice_note_url, privacy_level, request_editing, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $guest_name, $guest_email, $content_text, $voice_note_url, $privacy_level, $request_editing, $status]);

            $msg = ($status === 'Pending_Editing') 
                ? 'Hallelujah! Your testimony has been securely submitted to the pastoral desk for review.' 
                : 'Hallelujah! Your testimony is now live on the wall.';
            
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        // =====================================================================================
        // ACTION 3: THE "HALLELUJAH" BUTTON (Like functionality)
        // =====================================================================================
        case 'click_hallelujah':
            $testimony_id = filter_input(INPUT_POST, 'testimony_id', FILTER_VALIDATE_INT);
            if (!$testimony_id) exit;

            $checkStmt = $pdo->prepare("SELECT id FROM testimony_hallelujahs WHERE testimony_id = ? AND (ip_address = ? OR (user_id IS NOT NULL AND user_id = ?))");
            $checkStmt->execute([$testimony_id, $ip_address, $user_id]);
            
            if ($checkStmt->fetch()) {
                echo json_encode(['status' => 'info', 'message' => 'You have already celebrated this testimony!']);
                exit;
            }

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO testimony_hallelujahs (testimony_id, user_id, ip_address) VALUES (?, ?, ?)")->execute([$testimony_id, $user_id, $ip_address]);
            $pdo->prepare("UPDATE testimonies SET hallelujah_count = COALESCE(hallelujah_count, 0) + 1 WHERE id = ?")->execute([$testimony_id]);
            $pdo->commit();

            $newCount = $pdo->query("SELECT hallelujah_count FROM testimonies WHERE id = " . $testimony_id)->fetchColumn();
            echo json_encode(['status' => 'success', 'new_count' => $newCount]);
            break;

        // =====================================================================================
        // ACTION 4: FETCH & POST COMMENTS
        // =====================================================================================
        case 'fetch_comments':
            $testimony_id = filter_input(INPUT_GET, 'testimony_id', FILTER_VALIDATE_INT);
            if (!$testimony_id) exit;

            $stmt = $pdo->prepare("
                SELECT c.comment_text, DATE_FORMAT(c.created_at, '%b %d, %Y %l:%i %p') as nice_date, 
                       c.guest_name, u.first_name, u.last_name 
                FROM testimony_comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.testimony_id = ? AND c.is_approved = 1
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$testimony_id]);
            $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($comments as &$c) {
                $c['display_name'] = htmlspecialchars($c['first_name'] ? ($c['first_name'] . ' ' . $c['last_name']) : ($c['guest_name'] ?: 'Guest'), ENT_QUOTES, 'UTF-8');
            }

            echo json_encode(['status' => 'success', 'comments' => $comments]);
            break;

        case 'post_comment':
            $honeypot = $_POST['honeypot_comment'] ?? '';
            if (!empty($honeypot)) {
                echo json_encode(['status' => 'success', 'message' => 'Comment posted!']);
                exit;
            }

            $testimony_id = filter_input(INPUT_POST, 'testimony_id', FILTER_VALIDATE_INT);
            $comment_text = htmlspecialchars(strip_tags(trim($_POST['comment_text'] ?? '')), ENT_QUOTES, 'UTF-8');
            $guest_name = htmlspecialchars(strip_tags(trim($_POST['guest_name'] ?? '')), ENT_QUOTES, 'UTF-8');

            if (!$testimony_id || empty($comment_text)) {
                echo json_encode(['status' => 'error', 'message' => 'Comment cannot be empty.']);
                exit;
            }

            // Keyword check for comments
            $is_approved = 1;
            $suspicious_keywords = ['http://', 'https://', 'www.', '.com', 'crypto', 'bitcoin', 'investment'];
            foreach ($suspicious_keywords as $keyword) {
                if (strpos(strtolower($comment_text), $keyword) !== false) {
                    $is_approved = 0;
                    break;
                }
            }

            $pdo->prepare("INSERT INTO testimony_comments (testimony_id, user_id, guest_name, comment_text, is_approved) VALUES (?, ?, ?, ?, ?)")
                ->execute([$testimony_id, $user_id, $guest_name, $comment_text, $is_approved]);

            $msg = $is_approved ? 'Comment posted successfully!' : 'Comment received! It is pending pastoral review.';
            echo json_encode(['status' => 'success', 'message' => $msg]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid API route.']);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>