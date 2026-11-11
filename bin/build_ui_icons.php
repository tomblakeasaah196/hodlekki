<?php
// /bin/build_ui_icons.php
/**
 * Builds the icon sprite assets/ui/icons.svg from assets/ui/src/icons/*.svg.
 * Called by bin/build_ui.sh; not meant to be run on its own.
 *
 *   php bin/build_ui_icons.php <output.svg>
 *
 * Sources are Lucide icons (ISC, see assets/ui/src/icons/LICENSE). Every
 * presentation attribute is stripped: stroke, width, linecaps and colour come
 * from the .ui-icon class, so one rule restyles every icon and an icon always
 * takes the colour of the text around it. To add an icon, copy its SVG from
 * lucide-static (same version as the others) into assets/ui/src/icons/ and
 * rebuild.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$out = $argv[1] ?? '';
if ($out === '') {
    fwrite(STDERR, "usage: php bin/build_ui_icons.php <output.svg>\n");
    exit(2);
}

$srcDir = dirname(__DIR__) . '/assets/ui/src/icons';
$files = glob($srcDir . '/*.svg') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    fwrite(STDERR, "no icons in $srcDir\n");
    exit(1);
}

$allowedElements = ['path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse'];
$allowedAttrs = ['d', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points'];

$symbols = [];
foreach ($files as $file) {
    $name = basename($file, '.svg');
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name)) {
        fwrite(STDERR, "bad icon file name: $name\n");
        exit(1);
    }

    $doc = new DOMDocument();
    if (!@$doc->loadXML(str_replace("\r\n", "\n", (string) file_get_contents($file)))) {
        fwrite(STDERR, "cannot parse $file\n");
        exit(1);
    }
    $svg = $doc->documentElement;
    $viewBox = $svg->getAttribute('viewBox') ?: '0 0 24 24';

    $parts = [];
    foreach ($svg->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if (!in_array($node->tagName, $allowedElements, true)) {
            fwrite(STDERR, "$name: unsupported element <{$node->tagName}>\n");
            exit(1);
        }
        $attrs = '';
        foreach ($allowedAttrs as $attr) {
            if ($node->hasAttribute($attr)) {
                $attrs .= ' ' . $attr . '="' . htmlspecialchars($node->getAttribute($attr), ENT_QUOTES | ENT_XML1) . '"';
            }
        }
        $parts[] = '<' . $node->tagName . $attrs . '/>';
    }

    $symbols[] = '<symbol id="' . $name . '" viewBox="' . htmlspecialchars($viewBox, ENT_QUOTES | ENT_XML1) . '">'
        . implode('', $parts) . '</symbol>';
}

$sprite = '<svg xmlns="http://www.w3.org/2000/svg">' . "\n"
    . '<!-- Built by bin/build_ui_icons.php from assets/ui/src/icons. Lucide icons, ISC licence. Do not edit. -->' . "\n"
    . implode("\n", $symbols) . "\n</svg>\n";

if (file_put_contents($out, $sprite) === false) {
    fwrite(STDERR, "cannot write $out\n");
    exit(1);
}
