<?php
// /bin/build_pwa_icons.php
/**
 * ============================================================================
 * PWA ARTWORK BUILDER
 * ----------------------------------------------------------------------------
 * Renders every home-screen icon and iOS launch image in assets/pwa/ from the
 * single source of truth, assets/images/logo_hod.png.
 *
 *     php bin/build_pwa_icons.php
 *
 * The output IS committed — the cPanel host has no build step, so production
 * serves these bytes directly (same reasoning as assets/se/css/se.css). Run
 * this only when the logo itself changes, and commit what it writes.
 *
 * Deliberately font-free: it draws shapes and the logo, never text, so it
 * produces identical output on any machine with ext-gd and needs no TTF.
 * The "HOD Lekki" wordmark lives in the in-app preloader, where the real
 * brand font is available.
 *
 * What gets made and why each one is shaped the way it is:
 *   icon-<n>.png          "any" purpose. Full-bleed white, logo at 92% — the
 *                         platform applies its own corner mask.
 *   maskable-<n>.png      "maskable" purpose. Logo at 58% so it survives the
 *                         aggressive circular crop Android applies.
 *   apple-touch-icon.png  180px, opaque (iOS paints transparency black).
 *   badge-96.png          Monochrome alpha silhouette for the Android status
 *                         bar, which throws every colour away and keeps only
 *                         the alpha channel.
 *   splash-<w>x<h>.png    iOS launch images: brand navy, a white tile holding
 *                         the logo, and the red accent bar.
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

if (!extension_loaded('gd')) {
    fwrite(STDERR, "ext-gd is required to rebuild the PWA artwork.\n");
    exit(1);
}

$root   = dirname(__DIR__);
$source = $root . '/assets/images/logo_hod.png';
$outDir = $root . '/assets/pwa';

if (!is_file($source)) {
    fwrite(STDERR, "Logo not found at {$source}\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Could not create {$outDir}\n");
    exit(1);
}

/* -------------------------------------------------------------------------- *
 * Brand
 * -------------------------------------------------------------------------- */
const BRAND_NAVY  = [0x0B, 0x17, 0x33];   // launch-screen background
const BRAND_BLUE  = [0x1D, 0x35, 0x6A];   // hodBlue
const BRAND_RED   = [0xD1, 0x19, 0x20];   // hodRed
const BRAND_WHITE = [0xFF, 0xFF, 0xFF];

/** Supersampling factor for every shape we draw, downsampled at the end. */
const SS = 4;

$logo = imagecreatefrompng($source);
if ($logo === false) {
    fwrite(STDERR, "Could not read {$source}\n");
    exit(1);
}
$logoW = imagesx($logo);
$logoH = imagesy($logo);

/** Flatten the source onto white once: the logo is drawn for a white canvas. */
function flattened_logo($logo, int $w, int $h)
{
    $flat = imagecreatetruecolor($w, $h);
    imagefilledrectangle($flat, 0, 0, $w, $h, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $logo, 0, 0, 0, 0, $w, $h);
    return $flat;
}

$logoFlat = flattened_logo($logo, $logoW, $logoH);

function new_canvas(int $w, int $h, array $rgb)
{
    $image = imagecreatetruecolor($w, $h);
    imagealphablending($image, true);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $w, $h, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));
    return $image;
}

function transparent_canvas(int $w, int $h)
{
    $image = imagecreatetruecolor($w, $h);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $w, $h, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagealphablending($image, true);
    return $image;
}

/** Centre the logo on $canvas, scaled so its longest side is $target px. */
function place_logo($canvas, $logoFlat, int $logoW, int $logoH, int $target, int $cx, int $cy): void
{
    $scale = $target / max($logoW, $logoH);
    $w     = (int) round($logoW * $scale);
    $h     = (int) round($logoH * $scale);

    imagecopyresampled(
        $canvas, $logoFlat,
        $cx - intdiv($w, 2), $cy - intdiv($h, 2),
        0, 0,
        $w, $h, $logoW, $logoH
    );
}

/** Rounded rectangle, drawn supersampled by the caller for clean edges. */
function rounded_rect($image, int $x, int $y, int $w, int $h, int $radius, int $colour): void
{
    $radius = (int) min($radius, intdiv($w, 2), intdiv($h, 2));
    $d      = $radius * 2;

    imagefilledrectangle($image, $x + $radius, $y, $x + $w - $radius, $y + $h, $colour);
    imagefilledrectangle($image, $x, $y + $radius, $x + $w, $y + $h - $radius, $colour);

    imagefilledellipse($image, $x + $radius, $y + $radius, $d, $d, $colour);
    imagefilledellipse($image, $x + $w - $radius, $y + $radius, $d, $d, $colour);
    imagefilledellipse($image, $x + $radius, $y + $h - $radius, $d, $d, $colour);
    imagefilledellipse($image, $x + $w - $radius, $y + $h - $radius, $d, $d, $colour);
}

/**
 * $palette quantises to 8-bit before writing, WITHOUT dithering. Everything
 * here is flat brand colour plus the logo's antialiasing, so 255 colours
 * are visually lossless — and dithering a flat navy field would scatter
 * noise that triples the file size again. These are committed and shipped
 * to every phone, so the difference is worth having.
 */
function save_png($image, string $path, bool $palette = false): void
{
    if ($palette) {
        imagetruecolortopalette($image, false, 255);
        imagesavealpha($image, true);
    }

    imagepng($image, $path, 9);
    imagedestroy($image);
    echo '  ' . str_pad(basename($path), 26) . number_format(filesize($path) / 1024, 1) . " KB\n";
}

/* -------------------------------------------------------------------------- *
 * 1. "any" icons — full-bleed white, logo at 92%
 * -------------------------------------------------------------------------- */
echo "Home-screen icons\n";
foreach ([48, 72, 96, 128, 144, 152, 167, 192, 256, 384, 512] as $size) {
    $icon = new_canvas($size, $size, BRAND_WHITE);
    place_logo($icon, $logoFlat, $logoW, $logoH, (int) round($size * 0.92), intdiv($size, 2), intdiv($size, 2));
    save_png($icon, $outDir . "/icon-{$size}.png", true);
}

/* -------------------------------------------------------------------------- *
 * 2. Maskable icons — Android crops hard, so the logo sits inside the
 *    40%-radius safe circle the spec guarantees.
 * -------------------------------------------------------------------------- */
echo "Maskable icons\n";
foreach ([192, 512] as $size) {
    $icon = new_canvas($size, $size, BRAND_WHITE);
    place_logo($icon, $logoFlat, $logoW, $logoH, (int) round($size * 0.58), intdiv($size, 2), intdiv($size, 2));
    save_png($icon, $outDir . "/maskable-{$size}.png", true);
}

/* -------------------------------------------------------------------------- *
 * 3. Apple touch icon — opaque, because iOS paints alpha black.
 * -------------------------------------------------------------------------- */
echo "Apple touch icon\n";
$apple = new_canvas(180, 180, BRAND_WHITE);
place_logo($apple, $logoFlat, $logoW, $logoH, 166, 90, 90);
save_png($apple, $outDir . '/apple-touch-icon.png', true);

/* -------------------------------------------------------------------------- *
 * 4. Notification badge — Android keeps the alpha channel and throws the
 *    colours away, so the logo is re-drawn as a white silhouette whose
 *    opacity follows how dark the source pixel was.
 * -------------------------------------------------------------------------- */
echo "Notification badge\n";
$badgeSize = 96;
$scratch   = imagecreatetruecolor($badgeSize, $badgeSize);
imagefilledrectangle($scratch, 0, 0, $badgeSize, $badgeSize, imagecolorallocate($scratch, 255, 255, 255));
$inset = (int) round($badgeSize * 0.08);
imagecopyresampled(
    $scratch, $logoFlat,
    $inset, $inset, 0, 0,
    $badgeSize - 2 * $inset, $badgeSize - 2 * $inset,
    $logoW, $logoH
);

$badge = transparent_canvas($badgeSize, $badgeSize);
imagealphablending($badge, false);
for ($y = 0; $y < $badgeSize; $y++) {
    for ($x = 0; $x < $badgeSize; $x++) {
        $rgb = imagecolorat($scratch, $x, $y);
        $r   = ($rgb >> 16) & 0xFF;
        $g   = ($rgb >> 8) & 0xFF;
        $b   = $rgb & 0xFF;

        // Rec. 709 luminance. White paper -> fully transparent, the red and
        // blue strokes -> close to fully opaque white.
        $luma  = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        $alpha = (int) round(127 * min(1.0, max(0.0, $luma * 1.15)));

        imagesetpixel($badge, $x, $y, imagecolorallocatealpha($badge, 255, 255, 255, $alpha));
    }
}
imagedestroy($scratch);
imagesavealpha($badge, true);
save_png($badge, $outDir . '/badge-96.png');

/* -------------------------------------------------------------------------- *
 * 5. iOS launch images.
 *    Deep navy field, a white tile holding the logo, the red accent bar
 *    beneath it — the same composition the in-app preloader animates into,
 *    so the hand-off from the system splash to the app is seamless.
 * -------------------------------------------------------------------------- */
echo "Launch images\n";
$splashes = [
    // iPhone
    [640, 1136], [750, 1334], [828, 1792], [1125, 2436], [1170, 2532],
    [1179, 2556], [1206, 2622], [1242, 2208], [1242, 2688], [1284, 2778],
    [1290, 2796], [1320, 2868],
    // iPad
    [1536, 2048], [1620, 2160], [1668, 2224], [1668, 2388], [2048, 2732],
];

foreach ($splashes as [$w, $h]) {
    foreach ([[$w, $h], [$h, $w]] as [$cw, $ch]) {
        $canvas = new_canvas($cw, $ch, BRAND_NAVY);

        $short    = min($cw, $ch);
        $tile     = (int) round($short * 0.36);
        $radius   = (int) round($tile * 0.235);        // iOS squircle, near enough
        $centreX  = intdiv($cw, 2);
        $centreY  = (int) round($ch * 0.46);

        // Shapes are drawn supersampled, then resampled down, which is the
        // cheapest way to get antialiased corners out of GD.
        $layerW = $tile * SS;
        $layerH = (int) round($tile * SS * 1.34);
        $layer  = transparent_canvas($layerW, $layerH);

        $white = imagecolorallocate($layer, 255, 255, 255);
        rounded_rect($layer, 0, 0, $layerW - 1, $tile * SS - 1, $radius * SS, $white);

        $red      = imagecolorallocate($layer, BRAND_RED[0], BRAND_RED[1], BRAND_RED[2]);
        $barW     = (int) round($layerW * 0.34);
        $barH     = (int) round($tile * SS * 0.035);
        $barY     = (int) round($tile * SS * 1.17);
        rounded_rect($layer, intdiv($layerW - $barW, 2), $barY, $barW, $barH, intdiv($barH, 2), $red);

        $flatW = $tile;
        $flatH = (int) round($layerH / SS);
        $flat  = transparent_canvas($flatW, $flatH);
        imagealphablending($flat, false);
        imagecopyresampled($flat, $layer, 0, 0, 0, 0, $flatW, $flatH, $layerW, $layerH);
        imagealphablending($flat, true);
        imagedestroy($layer);

        // The logo goes on after the downsample so it stays crisp.
        place_logo($flat, $logoFlat, $logoW, $logoH, (int) round($tile * 0.78), intdiv($tile, 2), intdiv($tile, 2));

        imagecopy($canvas, $flat, $centreX - intdiv($flatW, 2), $centreY - intdiv($tile, 2), 0, 0, $flatW, $flatH);
        imagedestroy($flat);

        save_png($canvas, $outDir . "/splash-{$cw}x{$ch}.png", true);
    }
}

imagedestroy($logo);
imagedestroy($logoFlat);

echo "\nDone. Commit everything under assets/pwa/.\n";
