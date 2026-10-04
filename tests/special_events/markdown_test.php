<?php
// /tests/special_events/markdown_test.php — se_markdown() escapes first and
// only then adds tags (guide §19.5, §22.1).

echo "    escaping comes first\n";
$out = se_markdown('<script>alert(1)</script>');
ok('a script tag cannot survive', !str_contains($out, '<script'), $out);
ok('it is escaped as text', str_contains($out, '&lt;script&gt;'), $out);

$out = se_markdown('<img src=x onerror=alert(1)>');
ok('an img tag cannot survive', !str_contains($out, '<img'), $out);
// The handler text remains, but only as escaped characters inside a <p>, so
// no element exists for it to attach to. Assert that, not its absence.
ok('the whole tag is inert text', str_contains($out, '&lt;img'), $out);
// The only markup in the output is the paragraph the renderer itself added.
preg_match_all('#</?([a-z0-9]+)#i', $out, $m);
is_same('the only element emitted is the paragraph', ['p', 'p'], $m[1]);

$out = se_markdown('Hello " and \' and & and <');
ok('quotes are escaped', str_contains($out, '&quot;') || str_contains($out, '&#039;'), $out);
ok('ampersands are escaped', str_contains($out, '&amp;'), $out);

echo "    links\n";
ok('an https link is kept', str_contains(se_markdown('[x](https://example.com)'), 'href="https://example.com"'));
ok('a relative link is kept', str_contains(se_markdown('[x](/privacy)'), 'href="/privacy"'));
ok('an anchor is kept', str_contains(se_markdown('[x](#faq)'), 'href="#faq"'));
ok('an external link opens safely',
    str_contains(se_markdown('[x](https://example.com)'), 'rel="noopener noreferrer"'));
ok('a relative link does not get a target',
    !str_contains(se_markdown('[x](/privacy)'), 'target='));

foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>',
          'vbscript:x', 'file:///etc/passwd', '//evil.com'] as $bad) {
    $out = se_markdown("[click]({$bad})");
    ok("'{$bad}' never becomes an href", !str_contains($out, 'href='), $out);
    ok("'{$bad}' leaves only the label", str_contains($out, 'click'), $out);
}

echo "    formatting\n";
ok('bold', str_contains(se_markdown('**loud**'), '<strong>loud</strong>'));
ok('italic', str_contains(se_markdown('*soft*'), '<em>soft</em>'));
ok('code', str_contains(se_markdown('`x`'), '<code'));
ok('h2 from #', str_contains(se_markdown('# Title'), '<h2'));
ok('h3 from ##', str_contains(se_markdown('## Sub'), '<h3'));
ok('h4 from ###', str_contains(se_markdown('### Deep'), '<h4'));
ok('no h1 is emitted, so the hero keeps the only one',
    !str_contains(se_markdown('# Title'), '<h1'));
ok('a bullet list', str_contains(se_markdown("- one\n- two"), '<ul'));
ok('a numbered list', str_contains(se_markdown("1. one\n2. two"), '<ol'));
ok('two list items', substr_count(se_markdown("- one\n- two"), '<li>') === 2);
ok('a quote', str_contains(se_markdown('> quiet'), '<blockquote'));
ok('a rule', str_contains(se_markdown('---'), '<hr'));
ok('a paragraph', str_contains(se_markdown('Just text.'), '<p>'));

echo "    structure\n";
$doc = se_markdown("# Title\n\nSome **text**.\n\n- a\n- b\n\n> note");
ok('every opened tag is closed',
    substr_count($doc, '<p>') === substr_count($doc, '</p>')
    && substr_count($doc, '<ul') === substr_count($doc, '</ul>')
    && substr_count($doc, '<li>') === substr_count($doc, '</li>'));
is_same('empty input yields empty output', '', se_markdown(''));
ok('a lone newline is harmless', se_markdown("\n\n\n") === '');

echo "    excerpts\n";
is_same('tags are gone', 'Hello there', se_markdown_excerpt('**Hello** there'));
ok('long text is cut and ellipsised',
    str_ends_with(se_markdown_excerpt(str_repeat('word ', 100), 40), '…'));
ok('an excerpt respects its length', mb_strlen(se_markdown_excerpt(str_repeat('word ', 100), 40), 'UTF-8') <= 40);
ok('an excerpt is plain text', !str_contains(se_markdown_excerpt('<b>x</b> [l](https://e.com)'), '<'));
