<?php
// /api/embrace_public_api.php
// Public endpoint behind connect.php ("I'm New Here"); no login.
// Every error names the form field it is about ('field'), so the wizard can
// open that step, focus the field and show the message beside it.

require_once '../includes/db.php';
header('Content-Type: application/json');

// Church-issued login addresses. Visitors give a personal email instead.
const CONNECT_SYSTEM_EMAIL_DOMAIN = '@hodlc.com';

// users.phone with the usual separators stripped, for comparing with connect_phone_key().
const CONNECT_PHONE_SQL = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";

// Column named in a MySQL error => [form field, how to name it to the visitor].
const CONNECT_COLUMNS = [
    'first_name'       => ['first_name', 'first name'],
    'last_name'        => ['last_name', 'last name'],
    'email'            => ['email', 'email address'],
    'phone'            => ['phone', 'phone number'],
    'gender'           => ['gender', 'gender'],
    'dob'              => ['dob', 'date of birth'],
    'marital_status'   => ['marital_status', 'marital status'],
    'physical_address' => ['physical_address', 'address'],
    'latitude'         => ['physical_address', 'address'],
    'longitude'        => ['physical_address', 'address'],
    'invited_by'       => ['invited_by', '"who invited you" answer'],
    'prayer_requests'  => ['prayer_requests', 'prayer request'],
];

const CONNECT_PHONE_TAKEN = 'This phone number is already registered in our family database. Please log in instead, or use a different number.';
const CONNECT_EMAIL_TAKEN = 'This email is already linked to another profile. Please use a different email, or leave it blank.';

function connect_fail(string $message, ?string $field = null, array $extra = []): never {
    echo json_encode(['status' => 'error', 'message' => $message, 'field' => $field] + $extra);
    exit;
}

// A posted value as a trimmed string ('' when missing or not a string).
function connect_input(string $key): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) return '';
    if (!mb_check_encoding($value, 'UTF-8')) $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    return trim($value);
}

// Last 10 digits, so 0803 123 4567, +234 803 123 4567 and 2348031234567 all match.
function connect_phone_key(string $phone): string {
    return substr(preg_replace('/\D/', '', $phone), -10);
}

// Squeezes spaces and swaps ' for ’: Embrace puts names inside HTML and
// onclick='...' strings, where a plain apostrophe breaks the buttons.
function connect_clean_name(string $name): string {
    return str_replace("'", '’', preg_replace('/\s+/u', ' ', $name));
}

// Step 1 fields (names, phone, email). Checked when leaving Step 1 and again
// on submit; stops with a field error on bad input or an existing profile.
function connect_identity(PDO $pdo): array {
    $fname = connect_clean_name(connect_input('first_name'));
    $lname = connect_clean_name(connect_input('last_name'));
    $phone = preg_replace('/\s+/', ' ', connect_input('phone'));
    $email = strtolower(connect_input('email'));

    foreach (['first_name' => $fname, 'last_name' => $lname] as $field => $value) {
        $label = CONNECT_COLUMNS[$field][1];
        if ($value === '') connect_fail("Please enter your {$label}.", $field);
        if (mb_strlen($value) > 50) connect_fail("Your {$label} is too long. Please keep it under 50 characters.", $field);
        if (!preg_match('/^\p{L}[\p{L}\p{M} .’\-]*$/u', $value)) {
            connect_fail("Your {$label} can only contain letters, spaces, hyphens and apostrophes.", $field);
        }
    }

    $digits = preg_replace('/\D/', '', $phone);
    if ($phone === '') connect_fail('Please enter your phone number so we can reach you.', 'phone');
    if (!preg_match('/^\+?[0-9 ().\-]+$/', $phone)) {
        connect_fail('Please use only digits in your phone number, e.g. 0803 123 4567 or +234 803 123 4567.', 'phone');
    }
    if (strlen($digits) < 9 || strlen($digits) > 15) {
        connect_fail('Please enter a valid phone number (9 to 15 digits), e.g. 0803 123 4567.', 'phone');
    }

    if ($email !== '') {
        if (mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            connect_fail("That email address doesn't look right. Please check it (e.g. name@gmail.com), or leave it blank.", 'email');
        }
        if (str_ends_with($email, CONNECT_SYSTEM_EMAIL_DOMAIN)) {
            connect_fail('@hodlc.com addresses are issued by the church. Please enter your personal email (e.g. name@gmail.com), or leave it blank.', 'email');
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE " . CONNECT_PHONE_SQL . " LIKE ? LIMIT 1");
    $stmt->execute(['%' . connect_phone_key($phone) . '%']);
    if ($stmt->fetch()) connect_fail(CONNECT_PHONE_TAKEN, 'phone', ['exists' => true]);

    // users.email is unique, so a taken address would otherwise crash the insert.
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) connect_fail(CONNECT_EMAIL_TAKEN, 'email', ['exists' => true]);
    }

    return [$fname, $lname, $phone, $email];
}

// Logs the error under a short reference and tells the visitor what to fix
// when MySQL names the column or key; otherwise a generic message with the
// reference, so staff can find the matching line in error_log.
function connect_server_fail(Throwable $e): never {
    $ref = strtoupper(bin2hex(random_bytes(3)));
    $msg = $e->getMessage();
    error_log("Embrace Public API Error [{$ref}]: " . $msg);

    if ($e instanceof PDOException) {
        if (preg_match("/Duplicate entry .* for key '([^']+)'/", $msg, $m)) {
            if (stripos($m[1], 'email') !== false) connect_fail(CONNECT_EMAIL_TAKEN, 'email', ['exists' => true]);
            if (stripos($m[1], 'phone') !== false) connect_fail(CONNECT_PHONE_TAKEN, 'phone', ['exists' => true]);
        }
        if (preg_match('/(too long|truncated|Incorrect|Out of range).*?column\s+([`\'\w.]+)/i', $msg, $m)) {
            $parts = explode('.', str_replace(['`', "'"], '', $m[2]));
            $column = end($parts);
            if (isset(CONNECT_COLUMNS[$column])) {
                [$field, $label] = CONNECT_COLUMNS[$column];
                connect_fail(stripos($msg, 'too long') !== false
                    ? "Your {$label} is too long. Please shorten it and try again."
                    : "We couldn't accept your {$label}. Please check it and try again.", $field);
            }
        }
    }

    connect_fail("Sorry, we couldn't save your details because of a problem on our side. Please try again in a moment, or ask an usher for help. (Ref: {$ref})");
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 0: STEP 1 CHECK (validation + existing phone/email)
        // =====================================================================================
        case 'check_existing_user':
            connect_identity($pdo);
            echo json_encode(['status' => 'success', 'exists' => false]);
            break;

        // =====================================================================================
        // ACTION 1: PUBLIC VISITOR REGISTRATION
        // =====================================================================================
        case 'submit_connect_card':
            [$fname, $lname, $phone, $email] = connect_identity($pdo);

            // Step 2: optional details. ENUM and DATE columns reject anything else in strict mode.
            $gender = connect_input('gender');
            if ($gender !== '' && !in_array($gender, ['Male', 'Female'], true)) {
                connect_fail('Please choose your gender from the list.', 'gender');
            }

            $marital = connect_input('marital_status') ?: 'Single';
            if (!in_array($marital, ['Single', 'Married', 'Separated', 'Divorced'], true)) {
                connect_fail('Please choose your marital status from the list.', 'marital_status');
            }

            $dob = connect_input('dob');
            if ($dob !== '') {
                $date = DateTime::createFromFormat('!Y-m-d', $dob);
                if (!$date || $date->format('Y-m-d') !== $dob || (int) $date->format('Y') < 1900) {
                    connect_fail('Please enter a valid date of birth, or leave it blank.', 'dob');
                }
                if ($date > new DateTime('today')) {
                    connect_fail("Your date of birth can't be in the future.", 'dob');
                }
            }

            $address = preg_replace('/\s+/u', ' ', connect_input('physical_address'));
            if (mb_strlen($address) > 255) {
                connect_fail('Your address is too long. Please shorten it (255 characters max).', 'physical_address');
            }
            // Coordinates only arrive when a suggestion was picked; drop anything malformed.
            $lat = connect_input('latitude');
            $lng = connect_input('longitude');
            $has_coords = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;

            // Step 3
            $invited_by = connect_input('invited_by');
            if (mb_strlen($invited_by) > 150) {
                connect_fail('Your answer to "Who invited you?" is too long. Please shorten it (150 characters max).', 'invited_by');
            }
            $prayer = connect_input('prayer_requests');
            if (mb_strlen($prayer) > 2000) {
                connect_fail('Your prayer request is too long. Please shorten it (2,000 characters max).', 'prayer_requests');
            }
            $wants_to_join = connect_input('wants_to_join') === '1' ? 1 : 0;
            $visitation_preference = connect_input('wants_visitation') === '1' ? 'In-Person' : 'None';

            // Generate unique QR hash for swift check-ins at physical services
            $qr_hash = hash('sha256', random_bytes(16) . $phone);

            // Insert as a 1st_Timer. Optional fields go in as NULL, not '', so
            // ENUM, DATE and UNIQUE columns never see an empty string.
            $sql = "INSERT INTO users (
                        first_name, last_name, email, phone, gender, dob, marital_status,
                        physical_address, latitude, longitude, spiritual_status, invited_by, wants_to_join,
                        visitation_preference, prayer_requests, qr_code_hash
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '1st_Timer', ?, ?, ?, ?, ?)";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $fname,
                    $lname,
                    $email !== '' ? $email : null,
                    $phone,
                    $gender !== '' ? $gender : null,
                    $dob !== '' ? $dob : null,
                    $marital,
                    $address !== '' ? $address : null,
                    $has_coords ? $lat : null,
                    $has_coords ? $lng : null,
                    $invited_by,
                    $wants_to_join,
                    $visitation_preference,
                    $prayer,
                    $qr_hash
                ]);
            } catch (PDOException $e) {
                connect_server_fail($e);
            }
            $new_id = (int) $pdo->lastInsertId();

            // The card is saved. Everything below is best-effort: a failure is
            // logged, never reported, so the visitor isn't told to retry a card
            // that went through (the retry would be refused as a duplicate).
            try {
                // Reach: if this first timer was met through Reach, mark that lead "Visited Church".
                require_once __DIR__ . '/../includes/reach_helpers.php';
                reach_mark_visited_church($pdo, $phone, $new_id);
            } catch (Throwable $e) {
                error_log("Embrace Public API (reach sync, user {$new_id}): " . $e->getMessage());
            }

            try {
                // Staff notifications are rendered as HTML, so the name is escaped.
                $safe_name = htmlspecialchars("{$fname} {$lname}", ENT_QUOTES, 'UTF-8');

                // Same name, different phone: saved, but flagged for IDI to check.
                $nameStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE first_name = ? AND last_name = ? AND id <> ?");
                $nameStmt->execute([$fname, $lname, $new_id]);
                $duplicate_note = (int) $nameStmt->fetchColumn() > 0
                    ? " Note: another profile already uses the name {$safe_name}; please check whether it is the same person."
                    : '';

                // NOTIFICATION TRIGGER 1: Alert Embrace Leadership
                $embraceStmt = $pdo->query("
                    SELECT ud.user_id
                    FROM user_departments ud
                    JOIN departments d ON ud.department_id = d.id
                    WHERE d.name LIKE '%Embrace%' AND ud.role_in_dept IN ('Director', 'HOD') AND ud.is_active = 1
                ");
                $embrace_leaders = $embraceStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($embrace_leaders)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Connect Card', ?, '/modules/embrace/index.php')");
                    $alertMessage = "{$safe_name} has just submitted a Connect Card online and is waiting in the queue to be assigned.";
                    foreach ($embrace_leaders as $uid) {
                        $notifStmt->execute([$uid, $alertMessage]);
                    }
                }

                // NOTIFICATION TRIGGER 2: Alert IDI
                $idiStmt = $pdo->query("SELECT user_id FROM user_departments WHERE department_id = 1 AND is_active = 1");
                $idi_users = $idiStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($idi_users)) {
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Connect Card Profile', ?, '/modules/congregation/index.php')");
                    $alertMessage = "A new profile for {$safe_name} was autonomously generated via the public Connect Card. Please review the entry.{$duplicate_note}";
                    foreach ($idi_users as $uid) {
                        $notifStmt->execute([$uid, $alertMessage]);
                    }
                }
            } catch (Throwable $e) {
                error_log("Embrace Public API (notifications, user {$new_id}): " . $e->getMessage());
            }

            echo json_encode(['status' => 'success', 'message' => 'Welcome home! Your details have been received with love.']);
            break;

        default:
            connect_fail('Invalid action requested.');
    }
} catch (Throwable $e) {
    connect_server_fail($e);
}
?>
