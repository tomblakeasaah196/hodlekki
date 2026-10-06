<?php
// /api/special_events_poster_pdf.php
//
// Print-ready PDF of a check-in poster (guide §14.2).
//
// The poster itself is drawn in the Studio, in the browser, and saved as a
// PNG asset by `render_save`. This endpoint only wraps that PNG in a PDF at
// exactly A4 or A3, full bleed, so that "Print" in any browser or on any
// office machine produces the right thing instead of a shrunken image on
// US Letter with a 20 mm border.
//
// It is a GET because it is a link the Studio opens in a new tab, not an
// action: nothing is written, so there is no CSRF token to carry.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php?next=' . rawurlencode('/modules/special_events/index.php'));
    exit;
}

se_require_module_access($pdo);

$publicId = se_str($_GET['event'] ?? '', 12);
$assetId  = (int) ($_GET['asset'] ?? 0);

$event = $publicId !== '' ? se_event_find_by_public_id($pdo, $publicId) : null;
if (!$event) {
    http_response_code(404);
    exit('That event no longer exists.');
}

se_require_capability($pdo, (int) $event['id'], 'insights.view');

$asset = $assetId > 0 ? se_asset_find($pdo, $assetId) : null;
if (!$asset || (int) $asset['event_id'] !== (int) $event['id']) {
    http_response_code(404);
    exit('That poster could not be found.');
}

$role = (string) $asset['role'];
if (!isset(SE_POSTER_SIZES[$role])) {
    http_response_code(400);
    exit('That file is not a check-in poster.');
}
if (!isset(SE_POSTER_SIZES[$role]['mm'])) {
    // The screen size has no physical dimension to print at — it is meant
    // for a TV or a projector, not paper.
    http_response_code(400);
    exit('This poster is for on-screen display, not print.');
}

// The stored path is web-relative under /uploads/se; resolve it and refuse
// anything that climbs out, even though the path came from our own table.
$docroot = se_docroot();
$file    = realpath($docroot . '/' . ltrim((string) $asset['path'], '/'));
$allowed = realpath($docroot . '/uploads/se');

if ($file === false || $allowed === false || !str_starts_with($file, $allowed . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('That poster file is missing.');
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('PDF library not found.');
}
require_once $autoload;

[$widthMm, $heightMm] = SE_POSTER_SIZES[$role]['mm'];

// dompdf works in points: 1 mm = 72/25.4 pt.
$widthPt  = round($widthMm * 72 / 25.4, 2);
$heightPt = round($heightMm * 72 / 25.4, 2);

$dataUri = 'data:' . (string) $asset['mime'] . ';base64,' . base64_encode((string) file_get_contents($file));

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
    . '@page { margin: 0; } '
    . 'html, body { margin: 0; padding: 0; } '
    . 'img { display: block; width: ' . $widthPt . 'pt; height: ' . $heightPt . 'pt; }'
    . '</style></head><body><img src="' . htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') . '" alt=""></body></html>';

try {
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);   // data: URIs only — never fetch
    $options->set('isHtml5ParserEnabled', true);
    $options->set('chroot', $docroot);

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setPaper([0, 0, $widthPt, $heightPt], 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
} catch (Throwable $e) {
    error_log('SE poster pdf: ' . $e->getMessage());
    http_response_code(500);
    exit('The poster could not be made into a PDF.');
}

$name = preg_replace('/[^a-z0-9]+/', '-', strtolower(
    (string) $event['slug'] . '-checkin-' . SE_POSTER_SIZES[$role]['label']
)) ?? 'checkin-poster';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . trim($name, '-') . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

echo $dompdf->output();
