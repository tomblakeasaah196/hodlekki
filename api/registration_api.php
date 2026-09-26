<?php
// /api/registration_api.php

// 1. Core Includes
require_once '../includes/db.php';
header('Content-Type: application/json');

// NOTE: This is a PUBLIC endpoint - we do NOT block on session here.

function normalizeName($name) {
    $name = strtolower(trim((string) $name));
    $name = str_replace(['-', '_', "'"], ' ', $name);
    $name = preg_replace('/[^a-z\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function normalizePhone($phone) {
    $digits = preg_replace('/\D/', '', (string) $phone);
    return substr($digits, -10);
}

function getEventDayList($event) {
    $days = [];
    $startTs = strtotime($event['event_date']);
    $endTs = !empty($event['end_date']) ? strtotime($event['end_date']) : $startTs;
    if ($endTs < $startTs) $endTs = $startTs;
    $cursor = strtotime(date('Y-m-d', $startTs));
    $endDayOnly = strtotime(date('Y-m-d', $endTs));
    while ($cursor <= $endDayOnly) {
        $days[] = date('Y-m-d', $cursor);
        $cursor = strtotime('+1 day', $cursor);
    }
    return $days;
}

function findFuzzyMemberMatch($pdo, $name, $phone, $email) {
    $normName = normalizeName($name);
    $normPhone = normalizePhone($phone);
    $normEmail = strtolower(trim((string) $email));

    if (empty($normName) && empty($normPhone)) return null;

    $nameTokens = array_filter(explode(' ', $normName), function($t) { return strlen($t) >= 3; });
    $likeClauses = [];
    $params = [];

    if ($normPhone) {
        $likeClauses[] = "RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(u.phone, ''), '+', ''), '-', ''), ' ', ''), 10) = :phone_suffix";
        $params['phone_suffix'] = $normPhone;
    }
    if ($normEmail) {
        $likeClauses[] = "LOWER(IFNULL(u.real_email, '')) = :email_a";
        $likeClauses[] = "LOWER(IFNULL(u.email, '')) = :email_b";
        $params['email_a'] = $normEmail;
        $params['email_b'] = $normEmail;
    }
    foreach (array_values($nameTokens) as $i => $token) {
        $key = "tok{$i}";
        $likeClauses[] = "LOWER(u.first_name) LIKE :{$key}a OR LOWER(u.last_name) LIKE :{$key}b";
        $params["{$key}a"] = "%{$token}%";
        $params["{$key}b"] = "%{$token}%";
    }

    if (empty($likeClauses)) return null;

    $sql = "SELECT id, first_name, last_name, phone, email, real_email FROM users u
            WHERE (" . implode(' OR ', $likeClauses) . ") LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $best = null;
    $bestScore = 0;

    foreach ($candidates as $cand) {
        $candName = normalizeName($cand['first_name'] . ' ' . $cand['last_name']);
        $candPhone = normalizePhone($cand['phone'] ?? '');
        $candEmail = strtolower(trim($cand['real_email'] ?: ($cand['email'] ?: '')));

        $namePct = 0;
        if ($normName && $candName) similar_text($normName, $candName, $namePct);

        $phoneMatch = ($normPhone !== '' && $candPhone !== '' && $normPhone === $candPhone);
        $emailMatch = ($normEmail !== '' && $candEmail !== '' && $normEmail === $candEmail);

        $score = $namePct;
        if ($phoneMatch) $score += 20;
        if ($emailMatch) $score += 10;
        $score = min(100, $score);

        if ($score > $bestScore) { $bestScore = $score; $best = $cand; }
    }

    if ($best && $bestScore >= 80) return ['user_id' => (int) $best['id'], 'confidence' => round($bestScore, 2)];
    return null;
}

/**
 * Normalise the stored ministers value into a list of {name, image}.
 * New events store a JSON array of {name, image}. Older events may store
 * plain text in `ministers` with a single `ministers_image_url`.
 */
function parseMinisters($raw, $legacyImg) {
    if (empty($raw)) return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $out = [];
        foreach ($decoded as $m) {
            if (!is_array($m)) continue;
            $name = isset($m['name']) ? trim(strip_tags((string)$m['name'])) : '';
            $img  = isset($m['image']) ? trim((string)$m['image']) : '';
            if ($img !== '' && !preg_match('#^(https?://|/)#i', $img)) $img = '';
            if ($name === '' && $img === '') continue;
            $out[] = ['name' => $name, 'image' => $img];
        }
        return $out;
    }
    $text = trim(strip_tags((string)$raw));
    if ($text === '') return [];
    return [['name' => $text, 'image' => $legacyImg ? (string)$legacyImg : '']];
}

/**
 * Allow only basic formatting tags (bold/italic/underline/lists/line-breaks)
 * and strip all attributes — keeps admin-entered description safe to render
 * as HTML on the public registration page.
 */
function safe_rich($html){
    if(empty($html)) return '';
    $allowed = ['b','strong','i','em','u','br','p','ul','ol','li'];
    $html = strip_tags($html, '<' . implode('><', $allowed) . '>');
    $html = preg_replace('/on\w+="[^"]*"/i', '', $html);
    $html = preg_replace("/on\w+='[^']*'/i", '', $html);
    $html = preg_replace('/<(\w+)(?:\s+[^>]*)?>/i', '<$1>', $html);
    return $html;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$token = $_POST['token'] ?? $_GET['token'] ?? '';

if (empty($token)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing registration link.']);
    exit;
}

// Find your SELECT statement at the top and add external_registration_url:
$stmt = $pdo->prepare("SELECT id, title, event_category, event_date, end_date, description, location, youtube_url, external_registration_url,
                       banner_image_url, ministers, ministers_image_url,
                       requires_registration, allow_visitors
                       FROM events WHERE registration_token = ?");
$stmt->execute([$token]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!empty($event['description'])) $event['description'] = safe_rich($event['description']);
$event['ministers'] = parseMinisters($event['ministers'] ?? '', $event['ministers_image_url'] ?? '');

if (!$event || $event['requires_registration'] == 0) {
    echo json_encode(['status' => 'error', 'message' => 'This event is currently closed or does not require registration.']);
    exit;
}

$event_id = $event['id'];

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH DYNAMIC FORM
        // =====================================================================================
        case 'fetch_form':
            $fieldsStmt = $pdo->prepare("SELECT id, field_label, field_type, field_options, is_required, placeholder FROM event_custom_fields WHERE event_id = ? ORDER BY field_order ASC, id ASC");
            $fieldsStmt->execute([$event_id]);
            $custom_fields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($custom_fields as &$field) {
                if ($field['field_options']) {
                    $field['field_options'] = json_decode($field['field_options'], true);
                }
            }
            unset($field);

            $event_days = getEventDayList($event);

            $member_profile = null;
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            if (!empty($_SESSION['user_id'])) {
                $memberStmt = $pdo->prepare("SELECT first_name, last_name, phone, email, real_email, gender FROM users WHERE id = ?");
                $memberStmt->execute([$_SESSION['user_id']]);
                $member_profile = $memberStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            echo json_encode([
                'status' => 'success',
                'event_info' => $event,
                'event_days' => $event_days,
                'custom_fields' => $custom_fields,
                'member_profile' => $member_profile
            ]);
            break;

        case 'submit_registration':
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            // Capture the actual logged-in user, but keep a mutable $user_id variable
            $actual_logged_in_user = $_SESSION['user_id'] ?? null;
            $user_id = $actual_logged_in_user;

            $guest_name = trim($_POST['guest_name'] ?? '');
            $guest_phone = trim($_POST['guest_phone'] ?? '');
            $guest_email = trim($_POST['guest_email'] ?? '');
            $guest_gender = $_POST['guest_gender'] ?? null;

            if (!in_array($guest_gender, ['Male', 'Female'], true)) $guest_gender = null;

            // 1. Security Check: Block visitors if the event is closed, BUT allow logged-in members through.
            if ($event['allow_visitors'] == 0 && !$actual_logged_in_user) {
                echo json_encode(['status' => 'error', 'message' => 'This is a closed church event. Please log into your member account to register.']);
                exit;
            }

            // 2. Proxy Check: If a member checks the box to register someone else, 
            // nullify $user_id so it bypasses their personal uniqueness check and acts as a guest.
            if ($actual_logged_in_user && isset($_POST['registering_someone_else']) && $_POST['registering_someone_else'] == '1') {
                $user_id = null;
            }

            if (empty($guest_name) || empty($guest_phone)) {
                echo json_encode(['status' => 'error', 'message' => 'Name and Phone Number are mandatory.']);
                exit;
            }
            if (empty($guest_gender)) {
                echo json_encode(['status' => 'error', 'message' => 'Please select your gender.']);
                exit;
            }

            // Capture dynamic custom fields into a clean JSON array.
            // Checkboxes send custom_fields[field_id][] (PHP gives an array); everything
            // else sends custom_fields[field_id] (scalar). json_encode handles both.
            $custom_responses = [];
            if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
                foreach ($_POST['custom_fields'] as $k => $v) {
                    $custom_responses[$k] = is_array($v) ? array_values(array_map('trim', $v)) : trim((string) $v);
                }
            }
            $json_responses = json_encode($custom_responses);

            if ($user_id) {
                $check = $pdo->prepare("SELECT id FROM event_registrations WHERE event_id = ? AND user_id = ?");
                $check->execute([$event_id, $user_id]);
                if ($check->fetch()) {
                    echo json_encode(['status' => 'warning', 'message' => 'You are already registered for this event!']);
                    exit;
                }
            } else {
                $check = $pdo->prepare("SELECT id FROM event_registrations WHERE event_id = ? AND (guest_phone = ? OR (guest_email != '' AND guest_email = ?))");
                $check->execute([$event_id, $guest_phone, $guest_email]);
                if ($check->fetch()) {
                    echo json_encode(['status' => 'warning', 'message' => 'A registration with this phone number or email already exists.']);
                    exit;
                }
            }

            $matched_user_id = null;
            $match_confidence = null;
            $match_status = 'none';
            if (!$user_id) {
                $match = findFuzzyMemberMatch($pdo, $guest_name, $guest_phone, $guest_email);
                if ($match) {
                    $matched_user_id = $match['user_id'];
                    $match_confidence = $match['confidence'];
                    $match_status = 'pending';
                }
            }

            $event_days = getEventDayList($event);
            $isMultiDay = count($event_days) > 1;

            $selected_days = $_POST['selected_days'] ?? [];
            if (!is_array($selected_days)) $selected_days = [$selected_days];
            $selected_days = array_values(array_intersect(array_unique(array_filter(array_map('trim', $selected_days))), $event_days));

            if ($isMultiDay && empty($selected_days)) {
                echo json_encode(['status' => 'error', 'message' => "Please select at least one day you'll be attending."]);
                exit;
            }
            if (empty($selected_days)) {
                $selected_days = [date('Y-m-d', strtotime($event['event_date']))];
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO event_registrations (event_id, user_id, matched_user_id, match_confidence, match_status, guest_name, guest_phone, guest_email, guest_gender, custom_responses)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$event_id, $user_id, $matched_user_id, $match_confidence, $match_status, $guest_name, $guest_phone, $guest_email, $guest_gender, $json_responses]);

            $registration_id = $pdo->lastInsertId();

            $dayInsertStmt = $pdo->prepare("INSERT INTO event_registration_days (registration_id, attendance_date) VALUES (?, ?)");
            foreach ($selected_days as $day) {
                $ts = strtotime($day);
                if ($ts === false) continue;
                $dayInsertStmt->execute([$registration_id, date('Y-m-d', $ts)]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Registration confirmed! We look forward to seeing you.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action request.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Public Registration API Error: " . $e->getMessage());
    $friendly = 'A system error occurred. Please try again later.';
    if (stripos($e->getMessage(), 'Data truncated') !== false) {
        $friendly = 'One of your answers was in an unexpected format (often a dropdown left on its default option). Please re-check every field and try again.';
    }
    echo json_encode(['status' => 'error', 'message' => $friendly]);
}
?>
