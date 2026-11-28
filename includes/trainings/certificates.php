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
//   - Who signs (name, title, signature image) is never written in code: up
//     to two per training (training_signatories, its Settings tab; owner,
//     19 Nov 2026), else the Academy's signatory (training_settings). The
//     names and titles are part of what was printed (the snapshot), kept in
//     step: saving who signs re-signs the current certificates (same code,
//     new hash), and a download re-signs one that fell behind.
//   - The PDF is dompdf (Composer, as the Charis and event reports); the QR is
//     includes/qr.php (no package), drawn as a vector. The design (owner's
//     pick, 19 Nov 2026, "Gilded"): ivory paper, a navy and gold double frame,
//     the HOD logo, the name in script, the flat gold seal of the HOD Academy
//     paper certificate. Its fonts (SIL OFL) are in assets/fonts/certificate.
//   - It is drawn at each download, so a certificate issued before keeps its
//     code and QR and takes the new design.

require_once dirname(__DIR__) . '/qr.php';

const TRAININGS_CERT_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const TRAININGS_CERT_PATTERN = '/^CERT-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';
const TRAININGS_SIGNATURE_URL = '/uploads/trainings/signature/';
const TRAININGS_SIGNATURE_MAX_BYTES = 2 * 1024 * 1024;
const TRAININGS_SETTINGS = ['signatory_name' => '', 'signatory_title' => 'Resident Pastor', 'signature_image' => ''];
const TRAININGS_SIGNERS_MAX = 2;
const TRAININGS_CERT_FONTS = [
    'Cert Cinzel' => 'Cinzel-Regular.ttf', 'Cert Cinzel SemiBold' => 'Cinzel-SemiBold.ttf', 'Cert Cinzel Bold' => 'Cinzel-Bold.ttf',
    'Cert Garamond Italic' => 'EBGaramond-Italic.ttf', 'Cert Garamond Medium' => 'EBGaramond-Medium.ttf',
    'Cert Garamond SemiBold' => 'EBGaramond-SemiBold.ttf', 'Cert Script' => 'PinyonScript-Regular.ttf',
];

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
    trainings_certificates_resign_academy($pdo);   // the certificates this person signs carry the change
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
// A training's own signatories (up to two, side by side on the certificate)
// ---------------------------------------------------------------------------

/** A training's signatories by position (1, 2); none: the Academy's signs. @return array<int,array{name:string,title:string,signature_image:string}> */
function trainings_signatories(PDO $pdo, int $trainingId): array
{
    if (!trainings_table_exists($pdo, 'training_signatories')) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT position, name, title, signature_image FROM training_signatories WHERE training_id = ? ORDER BY position');
    $stmt->execute([$trainingId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $p = (int) $r['position'];
        if ($p >= 1 && $p <= TRAININGS_SIGNERS_MAX) {
            $out[$p] = ['name' => (string) $r['name'], 'title' => (string) $r['title'], 'signature_image' => (string) $r['signature_image']];
        }
    }
    return $out;
}

/**
 * Save a training's signatories from its Settings form: signerN_name,
 * signerN_title, signerN_remove and $files['signerN_file'] (N = 1, 2). A row
 * left without a name or a title is cleared. Every row is checked and every
 * image stored before anything is written, so a mistake saves nothing.
 */
function trainings_signatories_save(PDO $pdo, int $trainingId, array $in, array $files, int $by): array
{
    if (!trainings_table_exists($pdo, 'training_signatories')) {
        return ['ok' => true];
    }
    $plan = [];
    for ($p = 1; $p <= TRAININGS_SIGNERS_MAX; $p++) {
        if (!array_key_exists("signer{$p}_name", $in) && !array_key_exists("signer{$p}_title", $in)) {
            continue;   // the form did not send this row: it stays as it is
        }
        $name = trainings_text($in["signer{$p}_name"] ?? '', 120);
        $title = trainings_text($in["signer{$p}_title"] ?? '', 120);
        $f = $files["signer{$p}_file"] ?? null;
        $file = is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $f : null;
        if ($title === '' && ($name !== '' || $file !== null)) {
            return ['ok' => false, 'message' => 'Give their title.', 'field' => "signer{$p}_title"];
        }
        $plan[$p] = ['name' => $name, 'title' => $title, 'file' => $file, 'remove' => !empty($in["signer{$p}_remove"])];
    }
    $stored = [];
    foreach ($plan as $p => $row) {
        if ($row['file'] === null) {
            continue;
        }
        $up = trainings_signature_upload($row['file']);
        if (!$up['ok']) {
            array_map('trainings_signature_forget', $stored);
            return ['ok' => false, 'message' => $up['error'], 'field' => "signer{$p}_file"];
        }
        $stored[$p] = $up['url'];
    }
    $old = trainings_signatories($pdo, $trainingId);
    $now = trainings_sql_dt();
    foreach ($plan as $p => $row) {
        $image = $old[$p]['signature_image'] ?? '';
        if (isset($stored[$p]) || $row['remove'] || $row['title'] === '') {
            trainings_signature_forget($image);
            $image = $stored[$p] ?? '';
        }
        if ($row['title'] === '') {
            $pdo->prepare('DELETE FROM training_signatories WHERE training_id = ? AND position = ?')->execute([$trainingId, $p]);
        } elseif (isset($old[$p])) {
            $pdo->prepare('UPDATE training_signatories SET name = ?, title = ?, signature_image = ?, updated_by = ?, updated_at = ? WHERE training_id = ? AND position = ?')
                ->execute([$row['name'], $row['title'], $image, $by ?: null, $now, $trainingId, $p]);
        } else {
            $pdo->prepare('INSERT INTO training_signatories (training_id, position, name, title, signature_image, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$trainingId, $p, $row['name'], $row['title'], $image, $by ?: null, $now]);
        }
    }
    return ['ok' => true];
}

/** Clear a training's signatories and their images (the training is being deleted). */
function trainings_signatories_forget(PDO $pdo, int $trainingId): void
{
    foreach (trainings_signatories($pdo, $trainingId) as $s) {
        trainings_signature_forget($s['signature_image']);
    }
    if (trainings_table_exists($pdo, 'training_signatories')) {
        $pdo->prepare('DELETE FROM training_signatories WHERE training_id = ?')->execute([$trainingId]);
    }
}

/** Who signs a training's certificates now: its own signatories, else the Academy's. @return list<array{name:string,title:string}> */
function trainings_certificate_signers_now(PDO $pdo, int $trainingId): array
{
    $own = trainings_signatories($pdo, $trainingId);
    if ($own === []) {
        $s = trainings_settings($pdo);
        $own = [1 => ['name' => $s['signatory_name'], 'title' => $s['signatory_title']]];
    }
    $out = [];
    foreach ($own as $s) {
        if (trim($s['name']) !== '' || trim($s['title']) !== '') {
            $out[] = ['name' => trim($s['name']), 'title' => trim($s['title'])];
        }
    }
    return $out;
}

/**
 * Re-sign certificates with who signs their training now (owner, 19 Nov
 * 2026: signatures added after a certificate was issued must be on it). The
 * code and QR stay; the snapshot's signatories and the hash change together,
 * so the PDF, /verify and the hash agree. Only current certificates: a
 * revoked or replaced one keeps what it said. $enrolmentId: just that one.
 * @return int how many changed
 */
function trainings_certificates_resign(PDO $pdo, int $trainingId, ?int $enrolmentId = null): int
{
    if (!trainings_certs_ready($pdo)) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT c.id, c.code, c.enrolment_id, c.snapshot FROM training_certificates c
                           JOIN training_enrolments e ON e.id = c.enrolment_id
                           WHERE e.training_id = ? AND c.revoked_at IS NULL' . ($enrolmentId !== null ? ' AND c.enrolment_id = ?' : ''));
    $stmt->execute($enrolmentId !== null ? [$trainingId, $enrolmentId] : [$trainingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return 0;
    }
    $signers = trainings_certificate_signers_now($pdo, $trainingId);
    $changed = 0;
    foreach ($rows as $c) {
        $snap = trainings_decode($c['snapshot']);
        if ($snap === [] || ($snap['signatories'] ?? null) === $signers) {
            continue;
        }
        $snap['signatory_name'] = $signers[0]['name'] ?? '';
        $snap['signatory_title'] = $signers[0]['title'] ?? '';
        $snap['signatories'] = $signers;
        $json = trainings_json($snap);
        $hash = hash('sha256', $c['code'] . '|' . (int) $c['enrolment_id'] . '|' . $json);
        $pdo->prepare('UPDATE training_certificates SET snapshot = ?, hash = ? WHERE id = ?')->execute([$json, $hash, $c['id']]);
        $pdo->prepare('UPDATE training_enrolments SET certificate_hash = ? WHERE id = ? AND certificate_no = ?')->execute([$hash, $c['enrolment_id'], $c['code']]);
        $changed++;
    }
    return $changed;
}

/** Re-sign the certificates of every training the Academy's signatory signs (none of its own). */
function trainings_certificates_resign_academy(PDO $pdo): int
{
    if (!trainings_certs_ready($pdo)) {
        return 0;
    }
    $ids = $pdo->query('SELECT DISTINCT e.training_id FROM training_certificates c JOIN training_enrolments e ON e.id = c.enrolment_id
                        WHERE c.revoked_at IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    $changed = 0;
    foreach ($ids as $tid) {
        if (trainings_signatories($pdo, (int) $tid) === []) {
            $changed += trainings_certificates_resign($pdo, (int) $tid);
        }
    }
    return $changed;
}

/** The people a certificate names (one issued before 20 Nov 2026 names one). @return list<array{name:string,title:string}> */
function trainings_certificate_named(array $snap): array
{
    $list = isset($snap['signatories']) && is_array($snap['signatories']) ? $snap['signatories']
        : [['name' => $snap['signatory_name'] ?? '', 'title' => $snap['signatory_title'] ?? '']];
    $out = [];
    foreach (array_slice(array_values($list), 0, TRAININGS_SIGNERS_MAX) as $s) {
        $name = trim((string) ($s['name'] ?? ''));
        $title = trim((string) ($s['title'] ?? ''));
        if ($name !== '' || $title !== '') {
            $out[] = ['name' => $name, 'title' => $title];
        }
    }
    return $out;
}

/**
 * Who is drawn on a certificate: the people it names, each with today's
 * signature image when the same person (name and title) still signs in that
 * place, the training's first, then the Academy's. A certificate from before
 * per-training signatories takes the Academy's image, as it always did.
 * $own: trainings_signatories() of its training. @return list<array{name:string,title:string,image:?string}> (image: a file)
 */
function trainings_certificate_signers_drawn(array $snap, array $own, array $settings): array
{
    $key = static fn (string $n, string $t): string => strtolower(trim($n)) . '|' . strtolower(trim($t));
    $legacy = !isset($snap['signatories']) || !is_array($snap['signatories']);
    $academy = $key((string) ($settings['signatory_name'] ?? ''), (string) ($settings['signatory_title'] ?? ''));
    $out = [];
    foreach (trainings_certificate_named($snap) as $i => $s) {
        $mine = $own[$i + 1] ?? null;
        $image = null;
        if ($mine !== null && $key($mine['name'], $mine['title']) === $key($s['name'], $s['title'])) {
            $image = trainings_signature_path($mine['signature_image']);
        } elseif ($legacy || $academy === $key($s['name'], $s['title'])) {
            $image = trainings_signature_path((string) ($settings['signature_image'] ?? ''));
        }
        $out[] = $s + ['image' => $image];
    }
    return $out;
}

/** trainings_certificate_signers_drawn() for a stored certificate. */
function trainings_certificate_signers(PDO $pdo, array $cert): array
{
    $stmt = $pdo->prepare('SELECT training_id FROM training_enrolments WHERE id = ?');
    $stmt->execute([(int) ($cert['enrolment_id'] ?? 0)]);
    $trainingId = (int) ($stmt->fetchColumn() ?: 0);
    return trainings_certificate_signers_drawn((array) $cert['snapshot'], $trainingId > 0 ? trainings_signatories($pdo, $trainingId) : [], trainings_settings($pdo));
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
    // The learner's full name, middle name included: a certificate is the record.
    $stmt = $pdo->prepare('SELECT first_name, ' . person_middle_sql($pdo, '') . ' AS middle_name, last_name FROM users WHERE id = ?');
    $stmt->execute([$e['user_id']]);
    $signers = trainings_certificate_signers_now($pdo, (int) $t['id']);
    $snapshot = [
        'learner' => trainings_person_name($stmt->fetch(PDO::FETCH_ASSOC) ?: []),
        'training' => (string) $t['title'],
        'completed_on' => substr((string) ($official ? $e['confirmed_at'] : ($e['completed_at'] ?? trainings_sql_dt())), 0, 10),
        'signatory_name' => $signers[0]['name'] ?? '',     // the first, as before (older readers)
        'signatory_title' => $signers[0]['title'] ?? '',
        'signatories' => $signers,
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
    // A standalone test has no course page: the bell opens the test's result, where the certificate is (tests.php).
    $testItem = trainings_is_test($t) ? trainings_test_item_id($pdo, (int) $t['id']) : null;
    trainings_notify($pdo, [(int) $e['user_id']], 'Certificate ready', (string) $t['title'],
        $testItem !== null && function_exists('exam_url_take') ? exam_url_take($testItem) : '/modules/trainings/?t=' . (int) $t['id']);
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
        'signatory_name' => (string) ($snap['signatory_name'] ?? ''), 'signatory_title' => (string) ($snap['signatory_title'] ?? ''),
        'signatories' => trainings_certificate_named($snap)];
}

/** The verify API's reply for a certificate, in the shape the page shows (fields + labels). */
function trainings_certificate_verify_reply(array $l): array
{
    $status = ['valid' => 'VALID', 'revoked' => 'REVOKED', 'replaced' => 'REPLACED BY A NEWER CERTIFICATE'][$l['state']];
    $people = $l['signatories'] ?? [['name' => $l['signatory_name'], 'title' => $l['signatory_title']]];
    $signed = implode('; ', array_filter(array_map(static fn ($s): string => trim($s['name'] . ($s['name'] !== '' && $s['title'] !== '' ? ', ' : '') . $s['title']), $people)));
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
// The PDF: the "Gilded" design (owner's pick, 19 Nov 2026)
// ---------------------------------------------------------------------------

/** The QR as one vector path in a colour, with a two-module quiet zone (sharp at any print size). */
function trainings_certificate_qr_svg(string $data, string $color): string
{
    $m = hod_qr_matrix($data);
    $n = count($m);
    $q = 2;
    $d = '';
    foreach ($m as $y => $row) {
        $x = 0;
        while ($x < $n) {
            if (!$row[$x]) {
                $x++;
                continue;
            }
            $s = $x;
            while ($x < $n && $row[$x]) {
                $x++;
            }
            $d .= 'M' . ($s + $q) . ' ' . ($y + $q) . 'h' . ($x - $s) . 'v1h-' . ($x - $s) . 'z';
        }
    }
    $w = $n + 2 * $q;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $w . '" viewBox="0 0 ' . $w . ' ' . $w . '"><path d="' . $d . '" fill="' . $color . '"/></svg>';
}

/**
 * The flat gold foil seal of the HOD Academy paper certificate: a fine
 * saw-tooth edge and a pressed plain inner disc. dompdf's SVG has no
 * gradients, so the foil is flat layers: a darker lower-right rim and a
 * lighter upper-left rim make the inner disc look pressed.
 */
function trainings_certificate_seal_svg(): string
{
    $teeth = 72;
    $pts = [];
    for ($i = 0; $i < $teeth * 2; $i++) {
        $r = $i % 2 ? 46.6 : 50;
        $a = M_PI * $i / $teeth;
        $pts[] = round(50 + $r * cos($a), 2) . ',' . round(50 + $r * sin($a), 2);
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'
        . '<polygon points="' . implode(' ', $pts) . '" fill="#D29A1C"/>'
        . '<circle cx="50" cy="50" r="44" fill="#DBA626"/>'
        . '<circle cx="51.2" cy="51.2" r="29.5" fill="#B9820F"/>'
        . '<circle cx="48.9" cy="48.9" r="29.5" fill="#F1C955"/>'
        . '<circle cx="50" cy="50" r="28.6" fill="#DEA92A"/>'
        . '<circle cx="50" cy="50" r="40.5" fill="none" stroke="#E6B840" stroke-width="0.5"/>'
        . '</svg>';
}

/** The page behind everything: ivory paper, the navy and gold double frame, its corner blocks, the divider under the title. */
function trainings_certificate_frame_svg(): string
{
    $navy = '#1D356A';
    $gold = '#B8892B';
    $corner = static function (float $x, float $y) use ($navy, $gold): string {
        return '<rect x="' . ($x - 4.5) . '" y="' . ($y - 4.5) . '" width="9" height="9" fill="' . $navy . '"/>'
            . '<polygon points="' . $x . ',' . ($y - 3) . ' ' . ($x + 3) . ',' . $y . ' ' . $x . ',' . ($y + 3) . ' ' . ($x - 3) . ',' . $y . '" fill="' . $gold . '"/>';
    };
    return '<svg xmlns="http://www.w3.org/2000/svg" width="297" height="210" viewBox="0 0 297 210">'
        . '<rect x="0" y="0" width="297" height="210" fill="#FBF7EE"/>'
        . '<rect x="8" y="8" width="281" height="194" fill="none" stroke="' . $navy . '" stroke-width="1.6"/>'
        . '<rect x="11.5" y="11.5" width="274" height="187" fill="none" stroke="' . $gold . '" stroke-width="0.6"/>'
        . '<rect x="13" y="13" width="271" height="184" fill="none" stroke="' . $gold . '" stroke-width="0.25"/>'
        . $corner(11.5, 11.5) . $corner(285.5, 11.5) . $corner(11.5, 198.5) . $corner(285.5, 198.5)
        . '<line x1="113" y1="76" x2="143.5" y2="76" stroke="' . $gold . '" stroke-width="0.4"/>'
        . '<polygon points="148.5,73.5 151,76 148.5,78.5 146,76" fill="#D11920"/>'
        . '<line x1="153.5" y1="76" x2="184" y2="76" stroke="' . $gold . '" stroke-width="0.4"/>'
        . '</svg>';
}

/** @font-face rules for the certificate's fonts (assets/fonts/certificate, SIL OFL); a missing file falls back to DejaVu. */
function trainings_certificate_fonts_css(): string
{
    $dir = str_replace('\\', '/', dirname(__DIR__, 2) . '/assets/fonts/certificate');
    $css = '';
    foreach (TRAININGS_CERT_FONTS as $family => $file) {
        if (is_file($dir . '/' . $file)) {
            $css .= '@font-face { font-family: "' . $family . '"; font-style: normal; font-weight: normal; src: url("' . $dir . '/' . $file . '") format("truetype"); }' . "\n";
        }
    }
    return $css;
}

/**
 * The certificate as HTML for dompdf (A4 landscape). Every value escaped.
 * $signers: trainings_certificate_signers() (who signs, with their image
 * file); left out, the people the certificate names with the Academy's image.
 */
function trainings_certificate_html(array $cert, array $settings, ?array $signers = null): string
{
    $snap = (array) $cert['snapshot'];
    $signers = array_slice($signers ?? trainings_certificate_signers_drawn($snap, [], $settings), 0, TRAININGS_SIGNERS_MAX);
    $e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $uri = static fn (string $mime, string $bytes): string => 'data:' . $mime . ';base64,' . base64_encode($bytes);
    $len = static fn (string $s): int => function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    // The logo at 400px (23mm printed = ~440 dpi). dompdf splits a PNG's alpha
    // channel pixel by pixel in PHP, and the 1080px site logo made a download
    // take 7-12 s on the host (monitor #114). Falls back to the full logo.
    $logoFile = dirname(__DIR__, 2) . '/assets/images/logo_hod_certificate.png';
    if (!is_file($logoFile)) {
        $logoFile = dirname(__DIR__, 2) . '/assets/images/logo_hod.png';
    }
    $logo = is_file($logoFile) ? $uri('image/png', (string) file_get_contents($logoFile)) : '';
    $learner = (string) ($snap['learner'] ?? '');
    $training = (string) ($snap['training'] ?? '');
    $nameSize = max(24, min(46, (int) floor(46 * 30 / max(1, $len($learner)))));   // a long name gets smaller, never under 24pt
    $nameTop = round(87 + (46 - $nameSize) * 0.335, 1);                               // ...on the same baseline, just above the rule
    $twoLines = $len($training) > 70;                                                 // a long title wraps to two smaller lines
    $titleSize = $twoLines ? 16 : 20;
    $givenTop = $twoLines ? 143 : 134;
    $given = ($snap['completed_on'] ?? '') !== ''
        ? $e('Given on the ' . (new DateTimeImmutable((string) $snap['completed_on']))->format('jS \o\f F, Y')) . ' &#183; HOD Academy' : 'HOD Academy';

    $sigs = '';
    $left = count($signers) === 1 ? [50] : [50, 177];
    foreach ($signers as $i => $s) {
        $ink = '';
        if (!empty($s['image']) && is_file((string) $s['image'])) {
            $mime = str_ends_with(strtolower((string) $s['image']), '.png') ? 'image/png' : 'image/jpeg';
            $ink = '<img src="' . $uri($mime, (string) file_get_contents((string) $s['image'])) . '" alt="">';
        }
        $name = $s['name'] !== '' ? $s['name'] : $s['title'];
        $sigs .= '<div class="sig" style="left:' . $left[$i] . 'mm;"><table class="ink"><tr><td>' . $ink . '</td></tr></table>'
            . '<div class="sig-name">' . $e($name) . '</div>'
            . ($s['name'] !== '' && $s['title'] !== '' ? '<div class="sig-title">' . $e($s['title']) . '</div>' : '') . '</div>';
    }

    $navy = '#1D356A';
    $gold = '#B8892B';
    $serif = '"DejaVu Serif", serif';
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . trainings_certificate_fonts_css() . '
        @page { size: A4 landscape; margin: 0; }
        html, body { margin: 0; padding: 0; }
        body { font-family: "Cert Garamond Medium", ' . $serif . '; color: #0A0E17; }
        .abs { position: absolute; }
        .wide { position: absolute; left: 0; width: 297mm; text-align: center; }
        .church { top: 43mm; font-family: "Cert Cinzel SemiBold", ' . $serif . '; font-size: 10pt; letter-spacing: 4pt; color: ' . $navy . '; }
        .title { top: 56mm; font-family: "Cert Cinzel Bold", ' . $serif . '; font-size: 27pt; letter-spacing: 4pt; color: ' . $navy . '; }
        .mid { position: absolute; left: 30mm; width: 237mm; text-align: center; }
        .lead { font-family: "Cert Garamond Italic", ' . $serif . '; font-size: 14pt; color: #5A4F3C; }
        .name { font-family: "Cert Script", ' . $serif . '; color: ' . $navy . '; }
        .rule { position: absolute; left: 88.5mm; width: 120mm; top: 112mm; height: 0; border-top: 0.3mm solid ' . $gold . '; }
        .training { top: 121mm; font-family: "Cert Garamond SemiBold", ' . $serif . '; line-height: 1.05; color: #A3161C; }
        .given { font-family: "Cert Garamond Medium", ' . $serif . '; font-size: 12pt; letter-spacing: 0.3pt; color: ' . $navy . '; }
        .sig { position: absolute; top: 148mm; width: 70mm; text-align: center; }
        .ink { width: 60mm; margin: 0 auto; border-collapse: collapse; border-bottom: 0.3mm solid ' . $gold . '; }
        .ink td { height: 19mm; padding: 0 0 1mm; vertical-align: bottom; text-align: center; }
        .ink img { max-height: 18mm; max-width: 56mm; }
        .sig-name { margin-top: 1.8mm; font-family: "Cert Garamond SemiBold", ' . $serif . '; font-size: 11.5pt; color: ' . $navy . '; }
        .sig-title { margin-top: 0.4mm; font-family: "Cert Cinzel", ' . $serif . '; font-size: 7pt; letter-spacing: 0.8pt; color: #6B5B3E; }
        .verify { left: 176mm; width: 100mm; top: 189mm; text-align: right; font-family: "Cert Garamond Medium", ' . $serif . '; font-size: 7.5pt; color: #6B5B3E; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-size: 6.8pt; }
    </style></head><body>'
        . '<img class="abs" src="' . $uri('image/svg+xml', trainings_certificate_frame_svg()) . '" style="left:0; top:0; width:297mm; height:210mm;" alt="">'
        . ($logo !== '' ? '<img class="abs" src="' . $logo . '" style="left:93.5mm; top:50mm; width:110mm; height:110mm; opacity:0.03;" alt="">'
            . '<img class="abs" src="' . $logo . '" style="left:137mm; top:18mm; width:23mm; height:23mm;" alt="HOD">' : '')
        . '<div class="wide church">HOUSEHOLD OF DAVID &#183; LEKKI CENTRE</div>'
        . '<div class="wide title">CERTIFICATE OF COMPLETION</div>'
        . '<div class="mid lead" style="top:83mm;">This certificate is proudly presented to</div>'
        . '<div class="mid name" style="top:' . $nameTop . 'mm; font-size:' . $nameSize . 'pt;">' . $e($learner) . '</div>'
        . '<div class="rule"></div>'
        . '<div class="mid lead" style="top:114mm;">for faithfully completing</div>'
        . '<div class="mid training" style="left:40mm; width:217mm; font-size:' . $titleSize . 'pt;">' . $e($training) . '</div>'
        . '<div class="mid given" style="top:' . $givenTop . 'mm;">' . $given . '</div>'
        . $sigs
        . '<img class="abs" src="' . $uri('image/svg+xml', trainings_certificate_seal_svg()) . '" style="left:133.5mm; top:151mm; width:30mm; height:30mm;" alt="">'
        . '<img class="abs" src="' . $uri('image/svg+xml', trainings_certificate_qr_svg(trainings_certificate_verify_url((string) $cert['code']), $navy)) . '" style="left:255mm; top:166mm; width:21mm; height:21mm;" alt="">'
        . '<div class="abs verify"><span class="code">' . $e($cert['code']) . '</span> &#183; Verify at hodlekki.org/verify</div>'
        . '</body></html>';
}

/**
 * The PDF bytes, or null when dompdf is not installed (it comes with Composer
 * on the server). $signers: trainings_certificate_signers(), as for the HTML.
 */
function trainings_certificate_pdf(array $cert, array $settings, ?array $signers = null): ?string
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
    // The certificate's own fonts need their metrics written once: a folder of
    // ours under the temp dir, not vendor/ (replaced at every deploy).
    $cache = rtrim(sys_get_temp_dir(), '/\\') . '/hod-certificate-fonts';
    if ((is_dir($cache) || @mkdir($cache, 0775, true)) && is_writable($cache)) {
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);
    }
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(trainings_certificate_html($cert, $settings, $signers), 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    return (string) $dompdf->output();
}
