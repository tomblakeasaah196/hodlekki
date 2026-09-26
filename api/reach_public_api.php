<?php
// /api/reach_public_api.php
// UNAUTHENTICATED endpoint for the public capture flow at /reach.php.
// Guarded only by the campaign slug plus the volunteer's known phone,
// mirroring the pattern established by /api/embrace_public_api.php.

require_once '../includes/db.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Last 9 digits, so +234 / 0-prefixed spellings of one number match. A
// short fragment ("1") must not count: the lookup is substring-based and
// would otherwise match an arbitrary member.
function reach_phone_match_key(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    return strlen($digits) >= 9 ? substr($digits, -9) : '';
}

function reach_find_member(PDO $pdo, string $phone): ?array {
    $key = reach_phone_match_key($phone);
    if ($key === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE phone LIKE ? LIMIT 1");
    $stmt->execute(['%' . $key . '%']);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// This endpoint is unauthenticated, so it never returns a full name for
// a phone number — "Chika O." is enough for "is this you?".
function reach_short_name(?string $first, ?string $last): string {
    $first = trim((string) $first);
    $last  = trim((string) $last);
    return trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));
}

function reach_require_volunteer(PDO $pdo): array {
    $volunteer = reach_find_member($pdo, trim($_POST['volunteer_phone'] ?? ''));
    if (!$volunteer) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Please sign in with a phone number from our family database.'
        ]);
        exit;
    }
    return $volunteer;
}

function reach_public_tier_fields(string $tier): array {
    switch ($tier) {
        case 'Rapid':    return [];
        case 'Standard': return ['address', 'prayer_request'];
        case 'Rich':
        default:         return ['address', 'prayer_request', 'age_band', 'marital_status', 'language', 'best_time_to_call', 'notes'];
    }
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        // check_volunteer — is the person capturing this lead in our family
        // database? If not, we block the form; volunteers must be members.
        // ------------------------------------------------------------------
        case 'check_volunteer':
            $row = reach_find_member($pdo, trim($_POST['phone'] ?? ''));
            if ($row) {
                echo json_encode([
                    'exists' => true,
                    'name'   => reach_short_name($row['first_name'], $row['last_name'])
                ]);
            } else {
                echo json_encode(['exists' => false]);
            }
            break;

        // ------------------------------------------------------------------
        // check_existing_lead — has this phone already been captured in
        // this campaign (or elsewhere) by someone else?
        // ------------------------------------------------------------------
        case 'check_existing_lead':
            reach_require_volunteer($pdo);
            $phone       = trim($_POST['phone'] ?? '');
            $campaign_id = (int) ($_POST['campaign_id'] ?? 0);

            $out = [
                'duplicate'            => false,
                'existing_lead_id'     => null,
                'prior_capturer_name'  => null,
                'prior_capture_date'   => null,
                'is_existing_member'   => false,
                'member_name'          => null,
            ];

            $key = reach_phone_match_key($phone);
            if ($key === '') {
                echo json_encode($out);
                exit;
            }

            // Existing member match — informs the capturer this person is
            // already family (they should still get logged so we track the
            // reach touchpoint).
            $member = reach_find_member($pdo, $phone);
            if ($member) {
                $out['is_existing_member'] = true;
                $out['member_name']        = reach_short_name($member['first_name'], $member['last_name']);
            }

            // Prior lead capture — surfaces "Sister Chika captured them
            // on Sept 12" so the volunteer knows before submitting.
            $leadSql = "SELECT id, first_name, last_name, campaign_id
                         FROM reach_leads
                        WHERE phone LIKE ?";
            $params  = ['%' . $key . '%'];
            if ($campaign_id > 0) {
                $leadSql .= " AND (campaign_id = ? OR campaign_id IS NULL)";
                $params[] = $campaign_id;
            }
            $leadSql .= " ORDER BY id DESC LIMIT 1";
            $leadStmt = $pdo->prepare($leadSql);
            $leadStmt->execute($params);
            $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);

            if ($lead) {
                $out['duplicate']         = true;
                $out['existing_lead_id']  = (int) $lead['id'];

                $capStmt = $pdo->prepare("
                    SELECT lc.captured_at,
                           lc.captured_by_guest_name,
                           u.first_name AS u_first, u.last_name AS u_last
                      FROM reach_lead_captures lc
                      LEFT JOIN users u ON u.id = lc.captured_by_user_id
                     WHERE lc.lead_id = ?
                     ORDER BY lc.id DESC LIMIT 1
                ");
                $capStmt->execute([$lead['id']]);
                $cap = $capStmt->fetch(PDO::FETCH_ASSOC);
                if ($cap) {
                    if (!empty($cap['u_first'])) {
                        $out['prior_capturer_name'] = reach_short_name($cap['u_first'], $cap['u_last']);
                    } elseif (!empty($cap['captured_by_guest_name'])) {
                        $out['prior_capturer_name'] = $cap['captured_by_guest_name'];
                    }
                    $out['prior_capture_date'] = $cap['captured_at'];
                }
            }

            echo json_encode($out);
            break;

        // ------------------------------------------------------------------
        // submit_lead — accept a lead. If phone already captured, merge
        // into the existing lead by appending a reach_lead_captures row.
        // ------------------------------------------------------------------
        case 'submit_lead':
            $campaign_id     = (int) ($_POST['campaign_id'] ?? 0);
            if ($campaign_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Missing campaign.']);
                exit;
            }

            $campStmt = $pdo->prepare("SELECT id, payload_tier FROM reach_campaigns WHERE id = ? LIMIT 1");
            $campStmt->execute([$campaign_id]);
            $campaign = $campStmt->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) {
                echo json_encode(['status' => 'error', 'message' => 'Campaign not found.']);
                exit;
            }

            $first_name = trim($_POST['first_name'] ?? '');
            if ($first_name === '') {
                echo json_encode(['status' => 'error', 'message' => 'First name is required.']);
                exit;
            }
            $last_name = trim($_POST['last_name'] ?? '');
            $phone     = trim($_POST['phone'] ?? '');

            $category_raw = $_POST['category'] ?? 'Other';
            $category = in_array($category_raw, ['New_Convert', 'Unsaved', 'Saved', 'Broken', 'Dechurched', 'Other'], true)
                ? $category_raw
                : 'Other';

            $willing_for_visit = !empty($_POST['willing_for_visit']) ? 1 : 0;

            // Only accept the tier's fields; ignore anything else the
            // volunteer's browser might send (belt-and-braces vs a
            // malicious form).
            $tier_fields = reach_public_tier_fields($campaign['payload_tier']);
            $optional = [];
            foreach (['address', 'prayer_request', 'age_band', 'marital_status', 'language', 'best_time_to_call', 'notes'] as $f) {
                $optional[$f] = in_array($f, $tier_fields, true) ? trim($_POST[$f] ?? '') : null;
                if ($optional[$f] === '') {
                    $optional[$f] = null;
                }
            }

            $volunteer_user_id     = (int) reach_require_volunteer($pdo)['id'];
            $volunteer_guest_name  = null;
            $volunteer_guest_phone = null;

            $pdo->beginTransaction();

            // Merge onto the most recent lead that shares this phone in
            // the SAME campaign. Cross-campaign dupes are treated as
            // fresh capture events.
            $lead_id = null;
            $merged  = false;
            $phone_key = reach_phone_match_key($phone);
            if ($phone_key !== '') {
                $dupStmt = $pdo->prepare("
                    SELECT id FROM reach_leads
                     WHERE phone LIKE ? AND campaign_id = ?
                     ORDER BY id DESC LIMIT 1
                ");
                $dupStmt->execute(['%' . $phone_key . '%', $campaign_id]);
                $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    $lead_id = (int) $dup['id'];
                    $merged  = true;

                    // Fill in optional fields the original capture missed —
                    // never overwrite what's already there.
                    $updateSets = [];
                    $updateVals = [];
                    foreach ($optional as $col => $val) {
                        if ($val !== null) {
                            $updateSets[] = "$col = COALESCE(NULLIF($col, ''), ?)";
                            $updateVals[] = $val;
                        }
                    }
                    if ($willing_for_visit === 1) {
                        $updateSets[] = "willing_for_visit = 1";
                    }
                    if ($updateSets) {
                        $updateVals[] = $lead_id;
                        $sql = "UPDATE reach_leads SET " . implode(', ', $updateSets) . " WHERE id = ?";
                        $pdo->prepare($sql)->execute($updateVals);
                    }
                }
            }

            if ($lead_id === null) {
                $insLead = $pdo->prepare("
                    INSERT INTO reach_leads
                        (campaign_id, first_name, last_name, phone, category, willing_for_visit,
                         address, prayer_request, age_band, marital_status, language, best_time_to_call, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insLead->execute([
                    $campaign_id, $first_name, $last_name, $phone, $category, $willing_for_visit,
                    $optional['address'], $optional['prayer_request'], $optional['age_band'],
                    $optional['marital_status'], $optional['language'], $optional['best_time_to_call'],
                    $optional['notes']
                ]);
                $lead_id = (int) $pdo->lastInsertId();
            }

            $insCap = $pdo->prepare("
                INSERT INTO reach_lead_captures
                    (lead_id, captured_by_user_id, captured_by_guest_name, captured_by_guest_phone)
                VALUES (?, ?, ?, ?)
            ");
            $insCap->execute([$lead_id, $volunteer_user_id, $volunteer_guest_name, $volunteer_guest_phone]);

            $pdo->commit();

            echo json_encode([
                'status'  => 'success',
                'message' => $merged ? 'Merged onto existing lead.' : 'Lead captured.',
                'data'    => [
                    'lead_id' => $lead_id,
                    'merged'  => $merged
                ]
            ]);
            break;

        // ------------------------------------------------------------------
        // extract_from_voice — POST transcript to Gemini and return JSON
        // ------------------------------------------------------------------
        case 'extract_from_voice':
            // Each call spends Gemini credit; only signed-in volunteers, short input.
            reach_require_volunteer($pdo);
            $transcript = mb_substr(trim($_POST['transcript'] ?? ''), 0, 600);
            if ($transcript === '') {
                echo json_encode(['status' => 'error', 'message' => 'Empty transcript.']);
                exit;
            }

            $gemini_api_key = $_ENV['GEMINI_API_KEY'] ?? '';
            if ($gemini_api_key === '') {
                echo json_encode(['status' => 'error', 'message' => 'Voice extraction is not configured.']);
                exit;
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $gemini_api_key;

            $prompt = "You are helping an evangelism volunteer capture a person they met on the street. "
                . "Extract structured data from this spoken sentence. Return ONLY a pure JSON object with these keys: "
                . "first_name (string), last_name (string), phone (string, digits only), "
                . "category (one of: New_Convert, Unsaved, Saved, Broken, Dechurched, Other), "
                . "willing_for_visit (boolean), notes (string). "
                . "If a field is not mentioned, use an empty string (or false for willing_for_visit). "
                . "Do not invent values.\n\nTranscript: " . $transcript;

            $payload = [
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature'      => 0.1,
                    'responseMimeType' => 'application/json'
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $response === false) {
                echo json_encode(['status' => 'error', 'message' => 'Voice extraction failed.']);
                exit;
            }

            $result = json_decode($response, true);
            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $data = json_decode(trim($text), true);

            if (!is_array($data)) {
                echo json_encode(['status' => 'error', 'message' => 'Could not parse voice output.']);
                exit;
            }

            // Whitelist output to protect the form.
            $out = [
                'first_name'        => (string) ($data['first_name'] ?? ''),
                'last_name'         => (string) ($data['last_name'] ?? ''),
                'phone'             => preg_replace('/[^0-9+]/', '', (string) ($data['phone'] ?? '')),
                'category'          => in_array(($data['category'] ?? ''), ['New_Convert', 'Unsaved', 'Saved', 'Broken', 'Dechurched', 'Other'], true) ? $data['category'] : 'Other',
                'willing_for_visit' => !empty($data['willing_for_visit']),
                'notes'             => (string) ($data['notes'] ?? '')
            ];

            echo json_encode(['status' => 'success', 'data' => $out]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
            break;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reach Public API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
}
