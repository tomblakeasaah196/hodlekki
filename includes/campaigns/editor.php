<?php
// /includes/campaigns/editor.php
//
// The block editor's server side (assets/ui/js/email-editor.js; actions in
// api/email_editor_api.php):
//   - who may use it (email_editor_allowed)
//   - drafts for the live preview: a validated copy of the document in the
//     session, rendered by api/email_draft_preview.php (never srcdoc)
//   - pictures: uploaded once, RE-ENCODED with GD (only the pixels survive:
//     no metadata, no polyglot payload), at most 1200 px wide, saved under
//     uploads/email/YYYY/MM/ with a name we choose, in a folder that runs no
//     scripts
//   - saved templates (email_templates)
// Validation stays email_doc_validate(): the editor never sends HTML, and the
// server never trusts what it sends.

require_once __DIR__ . '/bootstrap.php';

const EMAIL_EDITOR_MODULES      = ['embrace', 'assimilation', 'charis'];
const EMAIL_EDITOR_DRAFTS       = 5;            // kept per session, newest first
const EMAIL_EDITOR_UPLOAD_MAX   = 5 * 1024 * 1024;
const EMAIL_EDITOR_IMAGE_WIDTH  = 1200;         // twice the 600 px email width
const EMAIL_EDITOR_IMAGE_PIXELS = 40000000;     // refuse decompression bombs before decoding

/** May the signed-in person use the editor? Anyone who manages an Automation tab or writes campaigns. */
function email_editor_allowed(): bool
{
    if (!function_exists('can')) {
        return false;
    }
    foreach (EMAIL_EDITOR_MODULES as $module) {
        if (can($module, 'manage')) {
            return true;
        }
    }
    return can('campaigns', 'create');
}

// ---------------------------------------------------------------------------
// Drafts for the preview
// ---------------------------------------------------------------------------

/**
 * Keep a validated copy of the document for the preview frame.
 * @return array{token:string, errors:list<string>}
 */
function email_editor_draft_put($doc): array
{
    $check = email_doc_validate($doc);
    $token = campaigns_token(16);
    $drafts = isset($_SESSION['email_drafts']) && is_array($_SESSION['email_drafts']) ? $_SESSION['email_drafts'] : [];
    $drafts = [$token => $check['doc']] + $drafts;
    $_SESSION['email_drafts'] = array_slice($drafts, 0, EMAIL_EDITOR_DRAFTS, true);
    return ['token' => $token, 'errors' => $check['errors']];
}

function email_editor_draft_get(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
        return null;
    }
    $doc = $_SESSION['email_drafts'][$token] ?? null;
    return is_array($doc) ? $doc : null;
}

// ---------------------------------------------------------------------------
// Pictures
// ---------------------------------------------------------------------------

function email_editor_upload_root(): string
{
    $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    return ($root !== '' && is_dir($root) ? rtrim($root, '/\\') : dirname(__DIR__, 2)) . '/uploads/email';
}

/** uploads/email/ with the .htaccess that runs nothing and serves pictures only. */
function email_editor_upload_dir(DateTimeImmutable $at, ?string $root = null): string
{
    $base = $root ?? email_editor_upload_root();
    if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
        throw new RuntimeException('Cannot create the email pictures folder.');
    }
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "Options -Indexes\n"
            . "<FilesMatch \"^(?!.*\\.(png|jpe?g)$).*\">\n  Require all denied\n</FilesMatch>\n"
            . "<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options \"nosniff\"\n</IfModule>\n");
    }
    $dir = $base . '/' . $at->format('Y') . '/' . $at->format('m');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the email pictures folder.');
    }
    return $dir;
}

/**
 * Store an uploaded picture. $file: one entry of $_FILES. PNG stays PNG (it
 * may be transparent); JPEG, GIF (first frame) and WebP become JPEG — every
 * mail app shows those two, Outlook shows no WebP.
 *
 * @return array{ok:bool, message?:string, src?:string, width?:int, height?:int}
 */
function email_editor_store_image(array $file, ?string $root = null, ?DateTimeImmutable $at = null): array
{
    if (!function_exists('imagecreatefromstring')) {
        return ['ok' => false, 'message' => 'Pictures cannot be processed on this server.'];
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => (int) ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || (int) ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
            ? 'That picture is too large (5 MB at most).' : 'The picture did not arrive. Try again.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_file($tmp) || ($root === null && !is_uploaded_file($tmp))) {
        return ['ok' => false, 'message' => 'The picture did not arrive. Try again.'];
    }
    if ($size <= 0 || $size > EMAIL_EDITOR_UPLOAD_MAX || filesize($tmp) > EMAIL_EDITOR_UPLOAD_MAX) {
        return ['ok' => false, 'message' => 'That picture is too large (5 MB at most).'];
    }
    $bytes = (string) file_get_contents($tmp);
    $info = @getimagesizefromstring($bytes);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'jpg', IMAGETYPE_WEBP => 'jpg'];
    if (!is_array($info) || !isset($types[$info[2] ?? 0])) {
        return ['ok' => false, 'message' => 'Use a JPG, PNG, GIF or WebP picture.'];
    }
    [$w, $h] = [(int) $info[0], (int) $info[1]];
    if ($w < 1 || $h < 1 || $w * $h > EMAIL_EDITOR_IMAGE_PIXELS) {
        return ['ok' => false, 'message' => 'That picture is too large to use.'];
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return ['ok' => false, 'message' => 'That picture could not be read.'];
    }
    $ext = $types[$info[2]];
    $nw = min($w, EMAIL_EDITOR_IMAGE_WIDTH);
    $nh = max(1, (int) round($h * $nw / $w));
    $out = imagecreatetruecolor($nw, $nh);
    if ($ext === 'png') {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent GIF/WebP areas become white
    }
    imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $at ??= campaigns_now();
    $dir = email_editor_upload_dir($at, $root);
    $name = $at->format('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $ok = $ext === 'png' ? imagepng($out, $dir . '/' . $name, 6) : imagejpeg($out, $dir . '/' . $name, 85);
    imagedestroy($out);
    if (!$ok) {
        return ['ok' => false, 'message' => 'The picture could not be saved.'];
    }
    return ['ok' => true, 'src' => site_url('/uploads/email/' . $at->format('Y/m') . '/' . $name), 'width' => $nw, 'height' => $nh];
}

// ---------------------------------------------------------------------------
// Saved templates
// ---------------------------------------------------------------------------

/** @return list<array{id:int, name:string, updated:string}> */
function email_templates_list(PDO $pdo): array
{
    if (!campaigns_table_exists($pdo, 'email_templates')) {
        return [];
    }
    $rows = $pdo->query('SELECT id, name, COALESCE(updated_at, created_at) AS updated FROM email_templates
                          WHERE is_archived = 0 ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'updated' => (string) $r['updated']], $rows);
}

function email_template_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id = ? AND is_archived = 0');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'doc' => email_doc_validate((string) $row['doc_json'])['doc']];
}

/**
 * Save the document as a template (a new one, or over $id). Only a valid
 * document is kept.
 * @return array{ok:bool, errors:list<string>, id?:int}
 */
function email_template_save(PDO $pdo, string $name, $doc, int $byUserId, ?int $id = null): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
        return ['ok' => false, 'errors' => ['Give the template a name (120 characters at most).']];
    }
    $check = email_doc_validate($doc);
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    $now = campaigns_sql_dt(campaigns_now());
    if ($id !== null) {
        $stmt = $pdo->prepare('UPDATE email_templates SET name = ?, doc_json = ?, updated_by = ?, updated_at = ? WHERE id = ? AND is_archived = 0');
        $stmt->execute([$name, campaigns_json($check['doc']), $byUserId, $now, $id]);
        return $stmt->rowCount() === 1 ? ['ok' => true, 'errors' => [], 'id' => $id] : ['ok' => false, 'errors' => ['That template no longer exists.']];
    }
    $pdo->prepare('INSERT INTO email_templates (name, doc_json, created_by, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$name, campaigns_json($check['doc']), $byUserId, $now]);
    return ['ok' => true, 'errors' => [], 'id' => (int) $pdo->lastInsertId()];
}

function email_template_archive(PDO $pdo, int $id, int $byUserId): bool
{
    $stmt = $pdo->prepare('UPDATE email_templates SET is_archived = 1, updated_by = ?, updated_at = ? WHERE id = ? AND is_archived = 0');
    $stmt->execute([$byUserId, campaigns_sql_dt(campaigns_now()), $id]);
    return $stmt->rowCount() === 1;
}

// ---------------------------------------------------------------------------
// Actions (api/email_editor_api.php)
// ---------------------------------------------------------------------------

/**
 * One editor action. The caller has checked email_editor_allowed() and CSRF.
 * $do: check | draft | templates | template | template_save | template_archive.
 * (Pictures go through email_editor_store_image() with $_FILES.)
 */
function email_editor_action(PDO $pdo, string $do, array $in, int $viewerId): array
{
    switch ($do) {
        case 'check':
            $c = email_doc_validate($in['doc'] ?? null);
            return ['status' => 'success', 'ok' => $c['ok'], 'errors' => $c['errors'], 'doc' => $c['doc']];

        case 'draft':
            $d = email_editor_draft_put($in['doc'] ?? null);
            return ['status' => 'success', 'token' => $d['token'], 'errors' => $d['errors']];

        case 'templates':
            return ['status' => 'success', 'templates' => email_templates_list($pdo)];

        case 'template':
            $t = email_template_get($pdo, (int) ($in['id'] ?? 0));
            return $t !== null ? ['status' => 'success', 'template' => $t] : ['status' => 'error', 'message' => 'That template no longer exists.'];

        case 'template_save':
            $r = email_template_save($pdo, (string) ($in['name'] ?? ''), $in['doc'] ?? null, $viewerId,
                !empty($in['id']) ? (int) $in['id'] : null);
            return $r['ok'] ? ['status' => 'success', 'message' => 'Template saved.', 'id' => $r['id']]
                : ['status' => 'error', 'message' => implode(' ', array_slice($r['errors'], 0, 3)), 'errors' => $r['errors']];

        case 'template_archive':
            return email_template_archive($pdo, (int) ($in['id'] ?? 0), $viewerId)
                ? ['status' => 'success', 'message' => 'Template removed.']
                : ['status' => 'error', 'message' => 'That template no longer exists.'];
    }
    return ['status' => 'error', 'message' => 'Unknown action.'];
}
