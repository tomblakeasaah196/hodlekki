<?php
// /includes/trainings/certificates.php
//
// Certificates (owner's answer 14): a branded PDF (learner, training, date,
// the Academy's signatory) with a QR code to the public /verify page.
//   - Only for a training with gives_certificate = 1. Issued when an
//     enrolment completes; for an official class (Membership / Maturity),
//     when the Academy confirms the graduate.
//   - The code is CERT-XXXX-XXXX (no 0/O, 1/I), unique; its SHA-256 covers
//     the code and what was printed. The current certificate is on the
//     enrolment (certificate_no / certificate_hash); every one ever issued is
//     in training_certificates, so /verify can say a code was revoked or
//     replaced by a reissue.
//   - Who signs (name, title, signature image) is an Academy setting
//     (training_settings), never written in code.
//   - The PDF is dompdf (Composer, as the Charis and event reports); the QR is
//     includes/qr.php (no package), drawn as a PNG.

require_once dirname(__DIR__) . '/qr.php';

const TRAININGS_CERT_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const TRAININGS_CERT_PATTERN = '/^CERT-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';
const TRAININGS_SIGNATURE_URL = '/uploads/trainings/signature/';
const TRAININGS_SIGNATURE_MAX_BYTES = 2 * 1024 * 1024;
const TRAININGS_SETTINGS = ['signatory_name' => '', 'signatory_title' => 'Resident Pastor', 'signature_image' => ''];

function trainings_certs_ready(PDO $pdo): bool
{
    return trainings_ready($pdo) && trainings_table_exists($pdo, 'training_certificates') && trainings_table_exists($pdo, 'training_settings');
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/** The Academy's settings, with defaults. @return array<string,string> */
function trainings_settings(PDO $pdo): array
{
    $out = TRAININGS_SETTINGS;
    if (!trainings_table_exists($pdo, 'training_settings')) {
        return $out;
    }
    foreach ($pdo->query('SELECT name, value FROM training_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (array_key_exists((string) $r['name'], $out)) {
            $out[(string) $r['name']] = (string) ($r['value'] ?? '');
        }
    }
    return $out;
}

function trainings_setting_set(PDO $pdo, string $name, string $value, int $by): void
{
    $now = trainings_sql_dt();
    $upd = $pdo->prepare('UPDATE training_settings SET value = ?, updated_by = ?, updated_at = ? WHERE name = ?');
    $upd->execute([$value, $by, $now, $name]);
    if ($upd->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO training_settings (name, value, updated_by, updated_at) VALUES (?, ?, ?, ?)')->execute([$name, $value, $by, $now]);
        } catch (PDOException $e) {
            $upd->execute([$value, $by, $now, $name]);   // a same-value update matches no row on MySQL; the row is there
        }
    }
}

/**
 * Save who signs certificates. $in: signatory_name, signatory_title,
 * remove_signature; $files['signature_file']: a PNG or JPEG, up to 2 MB.
 */
function trainings_settings_save(PDO $pdo, array $in, array $files, int $by): array
{
    $old = trainings_settings($pdo);
    $name = trainings_text($in['signatory_name'] ?? '', 120);
    $title = trainings_text($in['signatory_title'] ?? '', 120);
    if ($title === '') {
        return ['ok' => false, 'message' => 'Give the signatory\'s title.', 'field' => 'signatory_title'];
    }
    $image = $old['signature_image'];
    $f = $files['signature_file'] ?? null;
    if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = trainings_signature_upload($f);
        if (!$up['ok']) {
            return ['ok' => false, 'message' => $up['error'], 'field' => 'signature_file'];
        }
        trainings_signature_forget($image);
        $image = $up['url'];
    } elseif (!empty($in['remove_signature'])) {
        trainings_signature_forget($image);
        $image = '';
    }
    trainings_setting_set($pdo, 'signatory_name', $name, $by);
    trainings_setting_set($pdo, 'signatory_title', $title, $by);
    trainings_setting_set($pdo, 'signature_image', $image, $by);
    return ['ok' => true];
}

/** A signature image (PNG/JPEG, ≤ 2 MB), checked to really be one, under a random name. */
function trainings_signature_upload(array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > TRAININGS_SIGNATURE_MAX_BYTES) {
        return ['ok' => false, 'error' => 'The image is too big. Use up to 2 MB.'];
    }
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The image did not arrive. Try again.'];
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $ext = hod_upload_extension((string) ($file['name'] ?? ''), ['png', 'jpg', 'jpeg']);
    if ($ext === '' || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return ['ok' => false, 'error' => 'Choose a PNG or JPEG image.'];
    }
    $stored = hod_upload_store($file, dirname(__DIR__, 2) . rtrim(TRAININGS_SIGNATURE_URL, '/'), TRAININGS_SIGNATURE_URL, ['png', 'jpg', 'jpeg']);
    return $stored['ok'] ? ['ok' => true, 'url' => (string) $stored['url']] : ['ok' => false, 'error' => (string) $stored['error']];
}

/** The file of a signature image of ours, or null. */
function trainings_signature_path(string $url): ?string
{
    if (!preg_match('#^/uploads/trainings/signature/(\d{8}_[0-9a-f]{16}\.(png|jpe?g))$#', $url, $m)) {
        return null;
    }
    $path = dirname(__DIR__, 2) . '/uploads/trainings/signature/' . $m[1];
    return is_file($path) ? $path : null;
}

function trainings_signature_forget(string $url): void
{
    $path = trainings_signature_path($url);
    if ($path !== null) {
        @unlink($path);
    }
}

// ---------------------------------------------------------------------------
// Issuing, reissuing, revoking
// ---------------------------------------------------------------------------

function trainings_certificate_code(): string
{
    $pick = static function (): string {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= TRAININGS_CERT_ALPHABET[random_int(0, strlen(TRAININGS_CERT_ALPHABET) - 1)];
        }
        return $s;
    };
    return 'CERT-' . $pick() . '-' . $pick();
}

/** Where a code is checked: https://hodlekki.org/verify?code=… (the QR's target). */
function trainings_certificate_verify_url(string $code): string
{
    $path = '/verify?code=' . rawurlencode($code);
    return function_exists('site_url') ? site_url($path) : $path;
}

/** The certificate an enrolment holds now (not revoked), or null. */
function trainings_certificate_current(PDO $pdo, int $enrolmentId): ?array
{
    if (!trainings_certs_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM training_certificates WHERE enrolment_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$enrolmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['snapshot'] = trainings_decode($row['snapshot']);
    return $row;
}

/**
 * Issue (or reissue) an enrolment's certificate. The training must give one
 * and the enrolment must be completed (an official class: confirmed). A
 * reissue replaces the current certificate, whose code then verifies as
 * "replaced". @return array{ok:bool, code?:string, message?:string}
 */
function trainings_certificate_issue(PDO $pdo, array $enrolment, ?int $by = null): array
{
    if (!trainings_certs_ready($pdo)) {
        return ['ok' => false, 'message' => 'Certificates are being set up. Try again later.', 'code' => 'FEATURE_NOT_READY'];
    }
    $t = trainings_get($pdo, (int) $enrolment['training_id']);
    if ($t === null || !$t['gives_certificate']) {
        return ['ok' => false, 'message' => 'This training gives no certificate. Tick it in Settings.'];
    }
    $e = trainings_enrolment_get($pdo, (int) $enrolment['id']) ?? $enrolment;
    if ($e['status'] !== 'completed') {
        return ['ok' => false, 'message' => 'They have not finished yet.'];
    }
    $official = $t['kind'] === 'official' && $t['official_key'] !== null;
    if ($official && $e['confirmed_at'] === null) {
        return ['ok' => false, 'message' => 'Confirm the graduate first.'];
    }
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
    $stmt->execute([$e['user_id']]);
    $settings = trainings_settings($pdo);
    $snapshot = [
        'learner' => trainings_person_name($stmt->fetch(PDO::FETCH_ASSOC) ?: []),
        'training' => (string) $t['title'],
        'completed_on' => substr((string) ($official ? $e['confirmed_at'] : ($e['completed_at'] ?? trainings_sql_dt())), 0, 10),
        'signatory_name' => $settings['signatory_name'],
        'signatory_title' => $settings['signatory_title'],
    ];
    $now = trainings_sql_dt();
    $old = trainings_certificate_current($pdo, (int) $e['id']);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $id = 0;
        $code = '';
        $hash = '';
        for ($try = 0; $try < 5 && $id === 0; $try++) {
            $code = trainings_certificate_code();
            $hash = hash('sha256', $code . '|' . (int) $e['id'] . '|' . trainings_json($snapshot));
            try {
                $pdo->prepare('INSERT INTO training_certificates (enrolment_id, code, hash, snapshot, issued_by, issued_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$e['id'], $code, $hash, trainings_json($snapshot), $by, $now]);
                $id = (int) $pdo->lastInsertId();
            } catch (PDOException $ex) {
                if ($try === 4) {
                    throw $ex;   // five clashes in a 32^8 space is not luck
                }
            }
        }
        if ($old !== null) {
            $pdo->prepare('UPDATE training_certificates SET revoked_at = ?, revoked_by = ?, revoke_reason = ?, replaced_by = ? WHERE id = ?')
                ->execute([$now, $by, 'Reissued', $id, $old['id']]);
        }
        $pdo->prepare('UPDATE training_enrolments SET certificate_no = ?, certificate_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$code, $hash, $now, $e['id']]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
    trainings_notify($pdo, [(int) $e['user_id']], 'Certificate ready', (string) $t['title'], '/modules/trainings/?t=' . (int) $t['id']);
    return ['ok' => true, 'code' => $code, 'reissued' => $old !== null];
}

/** Revoke an enrolment's current certificate: /verify then says "revoked". */
function trainings_certificate_revoke(PDO $pdo, array $enrolment, int $by, string $reason): array
{
    $cert = trainings_certificate_current($pdo, (int) $enrolment['id']);
    if ($cert === null) {
        return ['ok' => false, 'message' => 'There is no certificate to revoke.'];
    }
    $reason = trainings_text($reason, 200);
    if ($reason === '') {
        return ['ok' => false, 'message' => 'Say why it is revoked.', 'field' => 'reason'];
    }
    $now = trainings_sql_dt();
    $pdo->prepare('UPDATE training_certificates SET revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL')
        ->execute([$now, $by, $reason, $cert['id']]);
    $pdo->prepare('UPDATE training_enrolments SET certificate_no = NULL, certificate_hash = NULL, updated_at = ? WHERE id = ?')
        ->execute([$now, $enrolment['id']]);
    return ['ok' => true];
}

/**
 * Called when an enrolment completes or a graduate is confirmed: issue the
 * certificate if the training gives one and none is held. Never throws.
 */
function trainings_certificate_auto(PDO $pdo, int $enrolmentId): void
{
    try {
        if (!trainings_certs_ready($pdo) || trainings_certificate_current($pdo, $enrolmentId) !== null) {
            return;
        }
        $e = trainings_enrolment_get($pdo, $enrolmentId);
        $t = $e !== null ? trainings_get($pdo, $e['training_id']) : null;
        if ($e === null || $t === null || !$t['gives_certificate'] || $e['status'] !== 'completed') {
            return;
        }
        if ($t['kind'] === 'official' && $t['official_key'] !== null && $e['confirmed_at'] === null) {
            return;   // the Academy's confirm issues it
        }
        trainings_certificate_issue($pdo, $e, null);
    } catch (Throwable $ex) {
        error_log('[trainings] certificate for enrolment ' . $enrolmentId . ' not issued: ' . $ex->getMessage());
    }
}

/**
 * What /verify shows for a code (api/public_verify_api.php). Null when the
 * code is unknown. state: valid | revoked | replaced.
 */
function trainings_certificate_lookup(PDO $pdo, string $code): ?array
{
    $code = strtoupper(trim($code));
    if (!preg_match(TRAININGS_CERT_PATTERN, $code) || !trainings_certs_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM training_certificates WHERE code = ?');
    $stmt->execute([$code]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        return null;
    }
    $snap = trainings_decode($c['snapshot']);
    $state = $c['revoked_at'] === null ? 'valid' : ($c['replaced_by'] !== null ? 'replaced' : 'revoked');
    return ['state' => $state, 'code' => (string) $c['code'], 'hash' => (string) $c['hash'], 'issued_at' => (string) $c['issued_at'],
        'revoked_at' => $c['revoked_at'], 'reason' => (string) ($c['revoke_reason'] ?? ''), 'learner' => (string) ($snap['learner'] ?? ''),
        'training' => (string) ($snap['training'] ?? ''), 'completed_on' => (string) ($snap['completed_on'] ?? ''),
        'signatory_name' => (string) ($snap['signatory_name'] ?? ''), 'signatory_title' => (string) ($snap['signatory_title'] ?? '')];
}

/** The verify API's reply for a certificate, in the shape the page shows (fields + labels). */
function trainings_certificate_verify_reply(array $l): array
{
    $status = ['valid' => 'VALID', 'revoked' => 'REVOKED', 'replaced' => 'REPLACED BY A NEWER CERTIFICATE'][$l['state']];
    $signed = trim($l['signatory_name'] . ($l['signatory_name'] !== '' ? ', ' : '') . $l['signatory_title']);
    return [
        'document_type' => 'Training Certificate',
        'reference' => $l['code'],
        'department' => $l['training'],
        'amount' => $l['learner'],
        'validated_by' => $signed !== '' ? $signed : 'HOD Academy',
        'role' => 'HOD Academy',
        'timestamp' => $l['completed_on'],
        'stamp_level' => $l['state'] === 'valid' ? 'Issued ' . substr($l['issued_at'], 0, 10)
            : ucfirst($l['state']) . ' ' . substr((string) $l['revoked_at'], 0, 10) . ($l['state'] === 'revoked' && $l['reason'] !== '' ? ': ' . $l['reason'] : ''),
        'cryptographic_hash' => $l['hash'],
        'verification_status' => $status,
        'revoked' => $l['state'] !== 'valid',
        'labels' => ['department' => 'Training', 'amount' => 'Awarded to', 'validated_by' => 'Signed by', 'timestamp' => 'Completed on'],
    ];
}

// ---------------------------------------------------------------------------
// The PDF
// ---------------------------------------------------------------------------

/** The certificate as HTML for dompdf (A4 landscape). Every value escaped. */
function trainings_certificate_html(array $cert, array $settings): string
{
    $snap = $cert['snapshot'];
    $e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $qr = 'data:image/png;base64,' . base64_encode(hod_qr_png(hod_qr_matrix(trainings_certificate_verify_url((string) $cert['code'])), 6, 2));
    $sig = '';
    $sigPath = trainings_signature_path((string) ($settings['signature_image'] ?? ''));
    if ($sigPath !== null) {
        $mime = str_ends_with(strtolower($sigPath), '.png') ? 'image/png' : 'image/jpeg';
        $sig = '<img class="sig" src="data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($sigPath)) . '" alt="">';
    }
    $date = $snap['completed_on'] !== '' ? (new DateTimeImmutable($snap['completed_on']))->format('j F Y') : '';
    $signName = trim((string) ($snap['signatory_name'] ?? ''));
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        @page { size: A4 landscape; margin: 0; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #0A0E17; }
        .frame { position: absolute; top: 14mm; left: 14mm; right: 14mm; bottom: 14mm; border: 2mm solid #1D356A; }
        .inner { position: absolute; top: 4mm; left: 4mm; right: 4mm; bottom: 4mm; border: 0.4mm solid #D11920; text-align: center; }
        .church { margin-top: 16mm; font-size: 11pt; letter-spacing: 3pt; color: #1D356A; text-transform: uppercase; }
        h1 { margin: 6mm 0 0; font-size: 30pt; color: #1D356A; }
        .lead { margin-top: 8mm; font-size: 12pt; color: #555; }
        .name { margin-top: 3mm; font-size: 26pt; font-weight: bold; }
        .training { margin-top: 3mm; font-size: 17pt; color: #1D356A; }
        .date { margin-top: 4mm; font-size: 11pt; color: #555; }
        .sign { position: absolute; left: 22mm; bottom: 16mm; width: 80mm; text-align: center; }
        .sig { max-height: 18mm; max-width: 70mm; }
        .line { border-top: 0.3mm solid #0A0E17; margin-top: 1mm; padding-top: 1.5mm; font-size: 10pt; }
        .qr { position: absolute; right: 22mm; bottom: 14mm; width: 42mm; text-align: center; font-size: 8pt; color: #555; }
        .qr img { width: 32mm; height: 32mm; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-size: 10pt; color: #0A0E17; }
    </style></head><body><div class="frame"><div class="inner">'
        . '<div class="church">Household of David &middot; Lekki Centre</div>'
        . '<h1>Certificate of Completion</h1>'
        . '<div class="lead">This certifies that</div>'
        . '<div class="name">' . $e($snap['learner'] ?? '') . '</div>'
        . '<div class="lead">has completed</div>'
        . '<div class="training">' . $e($snap['training'] ?? '') . '</div>'
        . ($date !== '' ? '<div class="date">' . $e($date) . '</div>' : '')
        . '<div class="sign">' . $sig . '<div class="line">' . ($signName !== '' ? $e($signName) . '<br>' : '') . $e($snap['signatory_title'] ?? '') . '</div></div>'
        . '<div class="qr"><img src="' . $qr . '" alt=""><div class="code">' . $e($cert['code']) . '</div>Check it at hodlekki.org/verify</div>'
        . '</div></div></body></html>';
}

/** The PDF bytes, or null when dompdf is not installed (it comes with Composer on the server). */
function trainings_certificate_pdf(array $cert, array $settings): ?string
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return null;
    }
    require_once $autoload;
    if (!class_exists(\Dompdf\Dompdf::class)) {
        return null;
    }
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', realpath(dirname(__DIR__, 2)));
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(trainings_certificate_html($cert, $settings), 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    return (string) $dompdf->output();
}
