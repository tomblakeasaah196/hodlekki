<?php
// /includes/upload_guard.php
//
// Storing a file someone uploaded, safely. Uploaded files are data, never code:
//   - only extensions on an allow-list are kept (documents, images, audio,
//     video); anything else is refused, so a .php file can never be stored;
//   - the stored name is random (date + 16 hex), never the uploader's name, so
//     it cannot be guessed, overwrite another file or smuggle a second dot;
//   - folders are made 0755, not 0777.
// Second layer: bin/uploads_guard.sh (run on every deploy) puts a .htaccess in
// uploads/ and assets/uploads/ that refuses to serve any script file.
//
//   hod_upload_store($_FILES['x'], $absDir, $urlPrefix, $kinds) -> ['ok', 'url', 'error']

const HOD_UPLOAD_DOCS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt', 'rtf', 'odt', 'ods', 'odp'];
const HOD_UPLOAD_IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const HOD_UPLOAD_MEDIA = ['mp3', 'm4a', 'wav', 'ogg', 'mp4', 'mov', 'webm'];

if (!function_exists('hod_upload_extension')) {
    /** The lower-case extension of an uploaded file name, or '' when it is not on the allow-list. */
    function hod_upload_extension(string $originalName, array $allowed): string
    {
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        return ($ext !== '' && in_array($ext, $allowed, true)) ? $ext : '';
    }
}

if (!function_exists('hod_upload_name')) {
    /** A random stored name: 20261112_ab12cd34ef56ab78.pdf */
    function hod_upload_name(string $ext): string
    {
        return date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    }
}

if (!function_exists('hod_upload_store')) {
    /**
     * Move one uploaded file into $absDir under a random name, if its extension
     * is allowed. $urlPrefix is what the page stores (e.g. '../../uploads/academy/').
     *
     * @return array{ok: bool, url: ?string, error: ?string}
     */
    function hod_upload_store(array $file, string $absDir, string $urlPrefix, array $allowed): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return ['ok' => false, 'url' => null, 'error' => 'The file did not arrive. Please try again.'];
        }
        $ext = hod_upload_extension((string) ($file['name'] ?? ''), $allowed);
        if ($ext === '') {
            return ['ok' => false, 'url' => null,
                    'error' => 'This file type is not allowed. Use: ' . implode(', ', $allowed) . '.'];
        }
        if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return ['ok' => false, 'url' => null, 'error' => 'Could not save the file.'];
        }
        $name = hod_upload_name($ext);
        if (!move_uploaded_file((string) $file['tmp_name'], rtrim($absDir, '/') . '/' . $name)) {
            return ['ok' => false, 'url' => null, 'error' => 'Could not save the file.'];
        }

        return ['ok' => true, 'url' => $urlPrefix . $name, 'error' => null];
    }
}
