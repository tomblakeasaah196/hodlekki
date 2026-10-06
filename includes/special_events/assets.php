<?php
// /includes/special_events/assets.php
//
// Uploads for the brand asset kit (guide §14.1) and the hardening around them
// (§19.6): size caps per role, type detection by MAGIC BYTES, GD re-encoding,
// responsive WebP variants, the SVG sanitiser and the uploads/se/.htaccess
// that denies script execution.
//
// The production host has NO ext-fileinfo (the deploy passes
// --ignore-platform-req=ext-fileinfo), so mime_content_type() and finfo MUST
// NOT be used anywhere in this file. Types come from the bytes themselves.

// --------------------------------------------------------------------------
// Type detection (§19.6 item 2)
// --------------------------------------------------------------------------

/** Detected type => [mime, extension, kind]. */
const SE_DETECTED_TYPES = [
    'png'   => ['image/png',  'png',   'image'],
    'jpeg'  => ['image/jpeg', 'jpg',   'image'],
    'webp'  => ['image/webp', 'webp',  'image'],
    'gif'   => ['image/gif',  'gif',   'image'],
    'svg'   => ['image/svg+xml', 'svg', 'svg'],
    'pdf'   => ['application/pdf', 'pdf', 'doc'],
    'mp4'   => ['video/mp4',  'mp4',   'video'],
    'm4a'   => ['audio/mp4',  'm4a',   'audio'],
    'mp3'   => ['audio/mpeg', 'mp3',   'audio'],
    'json'  => ['application/json', 'json', 'json'],
];

/**
 * Identify a file from its leading bytes. Returns the SE_DETECTED_TYPES key,
 * or null when nothing matches — the extension the client sent is never
 * trusted and never consulted.
 */
function se_detect_type(string $path): ?string
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $head = (string) fread($fh, 4096);
    fclose($fh);

    if ($head === '') {
        return null;
    }

    if (str_starts_with($head, "\x89PNG\r\n\x1a\n"))              { return 'png'; }
    if (str_starts_with($head, "\xFF\xD8\xFF"))                    { return 'jpeg'; }
    if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) { return 'gif'; }
    if (str_starts_with($head, '%PDF-'))                           { return 'pdf'; }
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') { return 'webp'; }

    // ISO base media: the 'ftyp' box sits at offset 4. The brand decides
    // whether the payload is video (MP4) or audio-only (M4A).
    if (substr($head, 4, 4) === 'ftyp') {
        $brand = strtolower(trim(substr($head, 8, 4)));
        if (in_array($brand, ['m4a', 'm4b', 'm4p'], true)) {
            return 'm4a';
        }
        if (in_array($brand, ['isom', 'iso2', 'mp41', 'mp42', 'avc1', 'mmp4', 'dash', 'qt'], true)) {
            return 'mp4';
        }
        return 'mp4';
    }

    // MP3: an ID3 tag, or a bare frame sync.
    if (str_starts_with($head, 'ID3')) {
        return 'mp3';
    }
    if (strlen($head) > 1 && $head[0] === "\xFF" && in_array($head[1], ["\xFB", "\xF3", "\xF2", "\xFA"], true)) {
        return 'mp3';
    }

    // SVG and JSON need a parse, not a signature. Read the whole file: both
    // are capped at a few MB by their role limits.
    $contents = (string) @file_get_contents($path, false, null, 0, 4 * 1024 * 1024);

    $trimmed = ltrim($contents);
    if ($trimmed !== '' && ($trimmed[0] === '<')) {
        if (se_svg_root_is_svg($contents)) {
            return 'svg';
        }
    }
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        json_decode($contents, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return 'json';
        }
    }

    return null;
}

/** True when the document parses as XML whose root element is <svg>. */
function se_svg_root_is_svg(string $xml): bool
{
    $doc = se_svg_load($xml);

    return $doc !== null
        && $doc->documentElement !== null
        && strtolower($doc->documentElement->localName) === 'svg';
}

/**
 * Parse XML with external entities and network access disabled, so a
 * malicious SVG cannot read server files (XXE) or call out.
 */
function se_svg_load(string $xml): ?DOMDocument
{
    // PHP 8 raises a ValueError rather than returning false for an empty
    // document, and a sanitiser must never throw on hostile input.
    if (trim($xml) === '') {
        return null;
    }

    $previous = libxml_use_internal_errors(true);
    // PHP 8 disables entity substitution by default; LIBXML_NONET and the
    // explicit NOENT absence keep it that way regardless of php.ini.
    $doc = new DOMDocument();
    $doc->resolveExternals = false;
    $doc->substituteEntities = false;
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $ok ? $doc : null;
}

// --------------------------------------------------------------------------
// SVG sanitiser (§19.6 item 4)
// --------------------------------------------------------------------------

/** Elements removed outright: script hosts and HTML-in-SVG escape hatches. */
const SE_SVG_FORBIDDEN_ELEMENTS = [
    'script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video',
    'animate', 'animatetransform', 'animatemotion', 'set', 'handler',
];

/**
 * Sanitise an SVG and return the safe serialisation, or null when it is not
 * SVG at all.
 *
 * Removes scripting elements, every on* handler, href/xlink:href that is not
 * a local fragment or a data:image URI, <use> pointing outside the document,
 * external <image>, and <style> blocks containing @import or a remote url().
 */
function se_svg_sanitize(string $xml): ?string
{
    $doc = se_svg_load($xml);
    if ($doc === null || $doc->documentElement === null
        || strtolower($doc->documentElement->localName) !== 'svg') {
        return null;
    }

    // Drop doctypes (entity declarations live there) and processing
    // instructions before walking the tree.
    foreach (iterator_to_array($doc->childNodes) as $node) {
        if ($node->nodeType === XML_DOCUMENT_TYPE_NODE || $node->nodeType === XML_PI_NODE) {
            $doc->removeChild($node);
        }
    }

    $xpath = new DOMXPath($doc);
    $all   = $xpath->query('//*');
    if ($all === false) {
        return null;
    }

    /** @var DOMElement[] $elements */
    $elements = [];
    foreach ($all as $el) {
        if ($el instanceof DOMElement) {
            $elements[] = $el;
        }
    }

    foreach ($elements as $el) {
        if ($el->parentNode === null) {
            continue;   // Already removed with an ancestor.
        }

        $name = strtolower($el->localName);

        if (in_array($name, SE_SVG_FORBIDDEN_ELEMENTS, true)) {
            $el->parentNode->removeChild($el);
            continue;
        }

        if ($name === 'style') {
            $css = $el->textContent;
            if (preg_match('/@import/i', $css) || preg_match('/url\(\s*[\'"]?\s*(?!#|data:image\/)[a-z0-9.\/\\\\]/i', $css)) {
                $el->parentNode->removeChild($el);
                continue;
            }
        }

        // Attributes: collect first, DOM removal invalidates a live list.
        $attributes = [];
        if ($el->attributes !== null) {
            foreach ($el->attributes as $attr) {
                $attributes[] = $attr;
            }
        }

        foreach ($attributes as $attr) {
            $attrName = strtolower($attr->localName);
            $value    = $attr->value;

            // Event handlers.
            if (str_starts_with($attrName, 'on')) {
                $el->removeAttributeNode($attr);
                continue;
            }

            // Link targets: local fragments and inline images only.
            if ($attrName === 'href' || $attrName === 'xlink:href' || $attr->nodeName === 'xlink:href') {
                $target = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
                $safe   = str_starts_with($target, '#')
                    || preg_match('#^data:image/(png|jpeg|gif|webp);base64,#i', $target) === 1;
                if (!$safe) {
                    $el->removeAttributeNode($attr);
                }
                continue;
            }

            // A style attribute can still fetch a remote resource.
            if ($attrName === 'style') {
                if (preg_match('/url\(\s*[\'"]?\s*(?!#|data:image\/)/i', $value)
                    || preg_match('/(expression|javascript:|@import)/i', $value)) {
                    $el->removeAttributeNode($attr);
                }
                continue;
            }

            // Any other attribute whose value is a javascript: URL.
            if (preg_match('/^\s*(javascript|vbscript|data:text\/html)/i', html_entity_decode($value, ENT_QUOTES, 'UTF-8'))) {
                $el->removeAttributeNode($attr);
            }
        }
    }

    $out = $doc->saveXML($doc->documentElement);

    return $out === false ? null : $out;
}

/**
 * The `se__*` tokens a Format Studio template declares (§14.4), so the Studio
 * can list what it recognised before accepting the upload.
 */
function se_svg_template_tokens(string $svg): array
{
    $tokens = ['text' => [], 'box' => [], 'fill' => [], 'stroke' => [], 'stop' => [], 'image' => [], 'qr' => [], 'if' => [], 'ifnot' => []];

    if (preg_match_all('/\bid\s*=\s*"(se__([a-z]+)__[a-z0-9_]+(?:--[a-z0-9-]+)?)"/i', $svg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $group = strtolower($m[2]);
            if (!array_key_exists($group, $tokens)) {
                continue;
            }
            // se__text__title  ->  title ; se__box__verse_text--wrap-6 -> verse_text
            $rest  = substr($m[1], strlen('se__' . $group . '__'));
            $field = explode('--', $rest)[0];
            if ($field !== '' && !in_array($field, $tokens[$group], true)) {
                $tokens[$group][] = $field;
            }
        }
    }

    return $tokens;
}

// --------------------------------------------------------------------------
// Storage (§14.1, §19.6 item 5)
// --------------------------------------------------------------------------

/** Absolute path of the repository/docroot root. */
function se_docroot(): string
{
    $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($root !== '' && is_dir($root)) {
        return rtrim($root, '/');
    }

    return dirname(__DIR__, 2);
}

/**
 * Create uploads/se/ with the .htaccess that denies script execution (§19.6).
 * Called on first upload; the file is also documented for manual creation.
 */
function se_uploads_dir(string $publicId, string $role): string
{
    $base = se_docroot() . '/uploads/se';

    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }

    $htaccess = $base . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, <<<'HTA'
Options -Indexes
<FilesMatch "\.(php|phtml|phar|pl|py|cgi|sh)$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  <FilesMatch "\.svg$">
    Header always set Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'"
  </FilesMatch>
</IfModule>
HTA);
    }

    // public_id and role are generated/allowlisted values, never user text,
    // but they are re-checked here because this builds a filesystem path.
    if (!preg_match('/^[0-9A-Z]{1,12}$/', $publicId) || !preg_match('/^[a-z0-9_]{1,30}$/', $role)) {
        throw new SeRuleException('VALIDATION', 'Invalid upload target.');
    }

    $dir = $base . '/' . $publicId . '/' . $role;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create upload directory.');
    }

    return $dir;
}

/** `<yyyymmdd>-<8 hex>.<ext>` — never derived from the client's file name. */
function se_asset_filename(string $extension): string
{
    return se_now()->format('Ymd') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
}

// --------------------------------------------------------------------------
// Image processing (§19.6 item 3, §14.1)
// --------------------------------------------------------------------------

/** Widths generated per image role (§14.1). */
const SE_IMAGE_VARIANTS = [
    'hero'  => [480, 960, 1600, 2400],
    'default' => [480, 960, 1600],
    // Check-in posters are rendered once at their exact print/screen size and
    // never served responsively (nothing reads their srcset — see
    // se_asset_srcset()'s only caller, the portal hero). Skipping the resize
    // pass here more than halves the peak memory a full-bleed A3 (3508×4961)
    // needs to go from canvas PNG to stored asset.
    'poster_a4'     => [],
    'poster_a3'     => [],
    'poster_screen' => [],
];

/**
 * Roles whose source image is large enough (A3 at 300 dpi is ~17 megapixels)
 * that decoding and re-encoding it can outrun a shared host's default
 * memory_limit. GD's fatal "allowed memory size exhausted" is not a
 * Throwable — it cannot be caught by se_api_fail() — so the only fix is to
 * not run out in the first place (see se_asset_store()).
 */
const SE_IMAGE_HEAVY_ROLES = ['poster_a4', 'poster_a3', 'poster_screen'];

/**
 * Re-encode a raster image with GD and write its responsive WebP variants.
 *
 * The re-encode is the security step, not an optimisation: it strips EXIF and
 * GPS data and destroys any polyglot payload hidden after the image data,
 * because the bytes we store are the ones GD just produced from pixels.
 *
 * @return array{path: string, mime: string, width: int, height: int, variants: array}
 */
function se_image_process(string $sourcePath, string $detected, string $dir, string $role): array
{
    $image = match ($detected) {
        'png'  => @imagecreatefrompng($sourcePath),
        'jpeg' => @imagecreatefromjpeg($sourcePath),
        'webp' => @imagecreatefromwebp($sourcePath),
        'gif'  => @imagecreatefromgif($sourcePath),
        default => false,
    };
    if ($image === false) {
        throw new SeRuleException('VALIDATION', 'That image could not be read. Try saving it again as PNG or JPEG.');
    }

    try {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $width  = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            throw new SeRuleException('VALIDATION', 'That image has no dimensions.');
        }

        $hasWebp = function_exists('imagewebp');
        $mainExt = $hasWebp ? 'webp' : ($detected === 'jpeg' ? 'jpg' : 'png');
        $mainMime = $hasWebp ? 'image/webp' : ($detected === 'jpeg' ? 'image/jpeg' : 'image/png');

        $mainName = se_asset_filename($mainExt);
        $mainPath = $dir . '/' . $mainName;
        se_image_write($image, $mainPath, $mainExt);

        $variants = [];
        $widths   = SE_IMAGE_VARIANTS[$role] ?? SE_IMAGE_VARIANTS['default'];
        foreach ($widths as $targetWidth) {
            if ($targetWidth >= $width) {
                continue;   // Never upscale.
            }
            $targetHeight = max(1, (int) round($height * $targetWidth / $width));
            $resized = imagescale($image, $targetWidth, $targetHeight);
            if ($resized === false) {
                continue;
            }
            imagealphablending($resized, true);
            imagesavealpha($resized, true);

            $vName = se_asset_filename($mainExt);
            se_image_write($resized, $dir . '/' . $vName, $mainExt);
            imagedestroy($resized);

            $variants[] = ['width' => $targetWidth, 'height' => $targetHeight, 'file' => $vName];
        }

        return [
            'file'     => $mainName,
            'path'     => $mainPath,
            'mime'     => $mainMime,
            'width'    => $width,
            'height'   => $height,
            'variants' => $variants,
        ];
    } finally {
        imagedestroy($image);
    }
}

/** Write a GD image in the chosen format. */
function se_image_write(\GdImage $image, string $path, string $extension): void
{
    $ok = match ($extension) {
        'webp' => imagewebp($image, $path, 82),
        'jpg'  => imagejpeg($image, $path, 86),
        default => imagepng($image, $path, 6),
    };
    if (!$ok) {
        throw new RuntimeException('Could not write ' . $extension . ' image.');
    }
    @chmod($path, 0644);
}

// --------------------------------------------------------------------------
// The upload entry point
// --------------------------------------------------------------------------

/**
 * Validate, process and record one uploaded file.
 *
 * @param array $file  One entry of $_FILES.
 * @param array $event The event row (for public_id).
 * @param array $meta  {title?, alt_text?, rights_confirmed?}
 * @return array The created se_assets row.
 */
function se_asset_store(PDO $pdo, array $event, string $role, array $file, array $meta, ?int $actorId): array
{
    if (!se_table_exists($pdo, 'se_assets')) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Assets are not available until the database is migrated.');
    }
    if (!isset(SE_ASSET_ROLES[$role])) {
        throw new SeValidationException(['role' => 'Unknown asset role.']);
    }

    // Rights attestation (§14.1). Music is the one kind of upload the church
    // can be billed for getting wrong, so the tick is checked BEFORE the file
    // is written: an unattested track never reaches the disk at all.
    if (in_array($role, SE_ASSET_ROLES_NEED_RIGHTS, true) && !se_bool($meta['rights_confirmed'] ?? false)) {
        throw new SeValidationException(
            ['rights_confirmed' => 'Tick the box to confirm this track is clear to use.'],
            'Confirm the music rights first.'
        );
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new SeRuleException('VALIDATION', match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
            UPLOAD_ERR_NO_FILE  => 'Choose a file to upload.',
            UPLOAD_ERR_PARTIAL  => 'The upload was interrupted. Please try again.',
            default             => 'The upload failed. Please try again.',
        });
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new SeRuleException('VALIDATION', 'The upload failed. Please try again.');
    }

    $spec  = SE_ASSET_ROLES[$role];
    $bytes = (int) ($file['size'] ?? filesize($tmp) ?: 0);
    if ($bytes > $spec['max_bytes']) {
        throw new SeRuleException('VALIDATION', sprintf(
            'That file is %s. The limit for %s is %s.',
            se_format_bytes($bytes), $role, se_format_bytes($spec['max_bytes'])
        ));
    }

    $detected = se_detect_type($tmp);
    if ($detected === null) {
        throw new SeRuleException('VALIDATION', 'We could not recognise that file type. Use PNG, JPEG, WebP, SVG, PDF, MP4, MP3 or JSON.');
    }
    [$detectedMime, $extension, $detectedKind] = SE_DETECTED_TYPES[$detected];

    // The role decides which kinds are acceptable; 'doc' accepts images too
    // (a flyer may be either), and 'image' never accepts a video or a PDF.
    $allowedKinds = match ($spec['kind']) {
        'image' => ['image'],
        'svg'   => ['svg'],
        'video' => ['video'],
        'audio' => ['audio'],
        'json'  => ['json'],
        'doc'   => ['doc', 'image'],
        default => [$spec['kind']],
    };
    if (!in_array($detectedKind, $allowedKinds, true)) {
        throw new SeRuleException('VALIDATION', sprintf(
            'A %s file is not valid for "%s". Expected %s.',
            $detectedKind, $role, implode(' or ', $allowedKinds)
        ));
    }

    // Alt text is required for every image (§13.14): the Studio must not be
    // able to save an inaccessible picture.
    $altText = se_line($meta['alt_text'] ?? '', 255);
    if ($detectedKind === 'image' && !in_array($role, ['og_card', 'story', 'square', 'portrait', 'projector'], true) && $altText === '') {
        throw new SeValidationException(
            ['alt_text' => 'Describe the image for screen readers and slow connections.'],
            'Alt text is required for images.'
        );
    }

    $publicId = (string) $event['public_id'];
    $dir      = se_uploads_dir($publicId, $role);
    $sha256   = (string) hash_file('sha256', $tmp);

    $width = $height = null;
    $variants = [];
    $metaJson = [];

    if ($detectedKind === 'image') {
        // Smaller images are valid too. They may look softer when stretched to
        // a full-screen background, but rejecting them makes otherwise useful
        // phone and WhatsApp images impossible to upload. The image processor
        // still creates the normal responsive variants; the Studio can show
        // the dimensions so producers can choose a higher-resolution source.
        if (in_array($role, SE_IMAGE_HEAVY_ROLES, true)) {
            // A 300 dpi A3 decodes to ~70 MB of raw pixels before GD's own
            // overhead; raise the ceiling for this one request rather than
            // the whole host, and give it more than the default 30s too.
            @ini_set('memory_limit', '512M');
            @set_time_limit(90);
        }
        $processed = se_image_process($tmp, $detected, $dir, $role);
        $fileName  = $processed['file'];
        $mime      = $processed['mime'];
        $width     = $processed['width'];
        $height    = $processed['height'];
        $variants  = $processed['variants'];
        $storedBytes = (int) (@filesize($processed['path']) ?: $bytes);
    } elseif ($detected === 'svg') {
        $clean = se_svg_sanitize((string) file_get_contents($tmp));
        if ($clean === null) {
            throw new SeRuleException('VALIDATION', 'That SVG could not be read.');
        }
        $fileName = se_asset_filename('svg');
        if (@file_put_contents($dir . '/' . $fileName, $clean) === false) {
            throw new RuntimeException('Could not store the SVG.');
        }
        @chmod($dir . '/' . $fileName, 0644);
        $mime = 'image/svg+xml';
        $storedBytes = strlen($clean);
        if ($role === 'template') {
            $metaJson['tokens'] = se_svg_template_tokens($clean);
        }
        // The sanitiser rewrote the file, so the stored hash is of what we kept.
        $sha256 = hash('sha256', $clean);
    } else {
        // Video, audio, PDF and JSON are stored as-is after the magic-byte
        // check; they are never executed and are served with nosniff.
        $fileName = se_asset_filename($extension);
        if (!@move_uploaded_file($tmp, $dir . '/' . $fileName)) {
            throw new RuntimeException('Could not store the file.');
        }
        @chmod($dir . '/' . $fileName, 0644);
        $mime = $detectedMime;
        $storedBytes = $bytes;
    }

    $webPath = '/uploads/se/' . $publicId . '/' . $role . '/' . $fileName;

    // Record the attestation on the asset itself, not only on whatever row
    // points at it. The claim belongs to the FILE: if the track is later
    // moved, re-pointed or audited, the tick travels with the bytes.
    if (in_array($role, SE_ASSET_ROLES_NEED_RIGHTS, true)) {
        $metaJson['rights'] = [
            'confirmed' => true,
            'by'        => $actorId,
            'at'        => se_now()->format('c'),
        ];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO se_assets
            (event_id, kind, role, title, alt_text, path, mime, bytes, width, height, sha256, variants_json, meta_json, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        (int) $event['id'],
        $detectedKind,
        $role,
        se_line($meta['title'] ?? '', 160) ?: null,
        $altText ?: null,
        $webPath,
        $mime,
        $storedBytes,
        $width,
        $height,
        $sha256,
        $variants ? se_json_encode($variants) : null,
        $metaJson ? se_json_encode($metaJson) : null,
        $actorId,
    ]);
    $assetId = (int) $pdo->lastInsertId();

    se_audit($pdo, (int) $event['id'], 'asset_upload', [
        'role' => $role, 'kind' => $detectedKind, 'bytes' => $storedBytes, 'mime' => $mime,
    ], 'asset', $assetId, $actorId);

    return se_asset_find($pdo, $assetId) ?? [];
}

function se_asset_find(PDO $pdo, int $id): ?array
{
    if (!se_table_exists($pdo, 'se_assets')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM se_assets WHERE id = ?");
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/** Assets of an event, newest first, optionally filtered by role. */
function se_asset_list(PDO $pdo, int $eventId, ?string $role = null): array
{
    if (!se_table_exists($pdo, 'se_assets')) {
        return [];
    }
    if ($role !== null && $role !== '' && isset(SE_ASSET_ROLES[$role])) {
        $stmt = $pdo->prepare(
            "SELECT * FROM se_assets WHERE event_id = ? AND role = ? AND deleted_at IS NULL ORDER BY id DESC"
        );
        $stmt->execute([$eventId, $role]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM se_assets WHERE event_id = ? AND deleted_at IS NULL ORDER BY id DESC"
        );
        $stmt->execute([$eventId]);
    }

    return $stmt->fetchAll();
}

/** Soft-delete (§9.1). The file stays on disk; nothing references it again. */
function se_asset_delete(PDO $pdo, int $eventId, int $assetId, ?int $actorId): void
{
    $stmt = $pdo->prepare(
        "UPDATE se_assets SET deleted_at = NOW() WHERE id = ? AND event_id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([$assetId, $eventId]);

    // Clear any event column that pointed at it, so the portal never renders
    // a deleted asset.
    $pdo->prepare(
        "UPDATE se_events SET
            logo_asset_id       = IF(logo_asset_id = ?, NULL, logo_asset_id),
            hero_asset_id       = IF(hero_asset_id = ?, NULL, hero_asset_id),
            hero_video_asset_id = IF(hero_video_asset_id = ?, NULL, hero_video_asset_id),
            og_asset_id         = IF(og_asset_id = ?, NULL, og_asset_id)
         WHERE id = ?"
    )->execute([$assetId, $assetId, $assetId, $assetId, $eventId]);

    // Same for the playlist. The FK is ON DELETE CASCADE, but this is a SOFT
    // delete, so it never fires — se_music_list() would keep an orphan row
    // that the JOIN then hides, leaving a producer a track they can neither
    // see nor replace. Deleting the row here keeps the two in step whichever
    // door the file was removed through.
    if (se_table_exists($pdo, 'se_music')) {
        $pdo->prepare("DELETE FROM se_music WHERE event_id = ? AND asset_id = ?")
            ->execute([$eventId, $assetId]);
    }

    se_audit($pdo, $eventId, 'asset_delete', ['asset_id' => $assetId], 'asset', $assetId, $actorId);
}

/** Editable metadata: title, alt text and role. */
function se_asset_update(PDO $pdo, int $eventId, int $assetId, array $fields, ?int $actorId): array
{
    $asset = se_asset_find($pdo, $assetId);
    if (!$asset || (int) $asset['event_id'] !== $eventId || $asset['deleted_at'] !== null) {
        throw new SeNotFoundException('That file is no longer in this event.');
    }

    $altText = array_key_exists('alt_text', $fields) ? se_line($fields['alt_text'], 255) : (string) ($asset['alt_text'] ?? '');
    if ((string) $asset['kind'] === 'image' && $altText === ''
        && !in_array((string) $asset['role'], ['og_card', 'story', 'square', 'portrait', 'projector'], true)) {
        throw new SeValidationException(['alt_text' => 'Describe the image for screen readers and slow connections.']);
    }

    $role = array_key_exists('role', $fields) ? se_str($fields['role'], 30) : (string) $asset['role'];
    if (!isset(SE_ASSET_ROLES[$role])) {
        $role = (string) $asset['role'];
    }

    $stmt = $pdo->prepare("UPDATE se_assets SET title = ?, alt_text = ?, role = ? WHERE id = ? AND event_id = ?");
    $stmt->execute([
        array_key_exists('title', $fields) ? (se_line($fields['title'], 160) ?: null) : $asset['title'],
        $altText ?: null,
        $role,
        $assetId,
        $eventId,
    ]);

    return se_asset_find($pdo, $assetId) ?? [];
}

/** "4.2 MB" — for the size messages above. */
function se_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }

    return $bytes . ' bytes';
}

/** A `srcset` string from an asset's stored variants. */
function se_asset_srcset(array $asset): string
{
    $variants = se_json_decode($asset['variants_json'] ?? null);
    if (!$variants) {
        return '';
    }
    $dir   = dirname((string) $asset['path']);
    $parts = [];
    foreach ($variants as $variant) {
        if (!isset($variant['file'], $variant['width'])) {
            continue;
        }
        $parts[] = $dir . '/' . $variant['file'] . ' ' . (int) $variant['width'] . 'w';
    }
    if (!empty($asset['width'])) {
        $parts[] = $asset['path'] . ' ' . (int) $asset['width'] . 'w';
    }

    return implode(', ', $parts);
}
