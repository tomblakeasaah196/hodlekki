<?php
// /tests/special_events/svg_sanitize_test.php — the SVG sanitiser
// (guide §19.6 item 4, §22.1).

$wrap = static fn(string $inner): string =>
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' . $inner . '</svg>';

echo "    scripting is removed\n";
$out = se_svg_sanitize($wrap('<script>alert(1)</script><rect width="10" height="10"/>'));
ok('the svg still parses', $out !== null);
ok('no script element', !str_contains((string) $out, '<script'), (string) $out);
ok('the real content survives', str_contains((string) $out, '<rect'), (string) $out);

foreach (['foreignObject', 'iframe', 'object', 'embed', 'handler'] as $tag) {
    $out = (string) se_svg_sanitize($wrap("<{$tag}><p>x</p></{$tag}><circle r=\"5\"/>"));
    ok("<{$tag}> is removed", !str_contains($out, '<' . $tag), $out);
}

foreach (['animate', 'animateTransform', 'animateMotion', 'set'] as $tag) {
    $out = (string) se_svg_sanitize($wrap("<rect width=\"10\" height=\"10\"><{$tag} attributeName=\"x\" to=\"99\"/></rect>"));
    ok("<{$tag}> is removed", !str_contains($out, '<' . $tag), $out);
}

echo "    event handlers are removed\n";
foreach (['onload', 'onclick', 'onmouseover', 'onerror', 'onfocus', 'onbegin'] as $attr) {
    $out = (string) se_svg_sanitize($wrap("<rect {$attr}=\"alert(1)\" width=\"10\" height=\"10\"/>"));
    ok("{$attr} is removed", !str_contains(strtolower($out), $attr), $out);
}

echo "    link targets\n";
$out = (string) se_svg_sanitize($wrap('<use href="#icon"/>'));
ok('a local fragment is kept', str_contains($out, 'href="#icon"'), $out);

$out = (string) se_svg_sanitize($wrap('<use href="https://evil.com/x.svg#a"/>'));
ok('an external use target is removed', !str_contains($out, 'evil.com'), $out);

$out = (string) se_svg_sanitize($wrap('<image href="https://evil.com/track.png"/>'));
ok('a remote image is removed', !str_contains($out, 'evil.com'), $out);

$out = (string) se_svg_sanitize($wrap('<image href="data:image/png;base64,iVBORw0KGgo="/>'));
ok('an inline data image is kept', str_contains($out, 'data:image/png'), $out);

$out = (string) se_svg_sanitize($wrap('<a href="javascript:alert(1)"><rect width="5" height="5"/></a>'));
ok('a javascript: href is removed', !str_contains(strtolower($out), 'javascript'), $out);

$out = (string) se_svg_sanitize($wrap('<image href="data:text/html;base64,PHNjcmlwdD4="/>'));
ok('a data:text/html image is removed', !str_contains($out, 'data:text/html'), $out);

echo "    CSS\n";
$out = (string) se_svg_sanitize($wrap('<style>@import url("https://evil.com/x.css");</style><rect width="5" height="5"/>'));
ok('a style block with @import is removed', !str_contains($out, '@import'), $out);

$out = (string) se_svg_sanitize($wrap('<style>.a{fill:red}</style><rect class="a" width="5" height="5"/>'));
ok('a harmless style block is kept', str_contains($out, 'fill:red'), $out);

$out = (string) se_svg_sanitize($wrap('<rect style="background:url(https://evil.com/x.png)" width="5" height="5"/>'));
ok('a remote url() in a style attribute is removed', !str_contains($out, 'evil.com'), $out);

echo "    entity expansion cannot read files\n";
$xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
     . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';
$out = se_svg_sanitize($xxe);
ok('no file contents leak', $out === null || !str_contains((string) $out, 'root:'), (string) $out);
ok('no doctype survives', $out === null || !str_contains((string) $out, '<!DOCTYPE'), (string) $out);

echo "    non-SVG input\n";
is_same('HTML is rejected', null, se_svg_sanitize('<html><body>hi</body></html>'));
is_same('plain text is rejected', null, se_svg_sanitize('not xml at all'));
is_same('an empty string is rejected', null, se_svg_sanitize(''));
is_same('a PNG header is rejected', null, se_svg_sanitize("\x89PNG\r\n\x1a\n"));

echo "    type detection does not accept a polyglot\n";
$tmp = tempnam(sys_get_temp_dir(), 'se');
file_put_contents($tmp, "GIF89a" . '<?php echo "pwned"; ?>');
is_same('a GIF/PHP polyglot is detected as a gif, never executed', 'gif', se_detect_type($tmp));
file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>x</script></svg>');
is_same('an svg with a script is still detected as svg', 'svg', se_detect_type($tmp));
file_put_contents($tmp, '<?php echo 1;');
is_same('a bare PHP file is not a recognised type', null, se_detect_type($tmp));
unlink($tmp);

echo "    template tokens\n";
$tokens = se_svg_template_tokens(
    '<svg><text id="se__text__title">T</text><rect id="se__box__verse_text--wrap-6"/>'
    . '<path id="se__fill__primary"/><rect id="se__qr__checkin_url"/><g id="se__if__has_photo"/></svg>'
);
ok('a text token is found', in_array('title', $tokens['text'], true));
ok('a box token drops its fit suffix', in_array('verse_text', $tokens['box'], true));
ok('a fill token is found', in_array('primary', $tokens['fill'], true));
ok('a qr token is found', in_array('checkin_url', $tokens['qr'], true));
ok('a conditional group is found', in_array('has_photo', $tokens['if'], true));
