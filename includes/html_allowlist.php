<?php
// /includes/html_allowlist.php
//
// Formatted text that people typed in an editor, made safe to show as HTML.
//
// hod_html_allowlist($html) keeps a short list of formatting tags (paragraphs,
// line breaks, bold, italic, underline, strike-through, lists) with NO
// attributes at all: no style, no class, no links, no event handlers. Script,
// style, media, forms and frames are dropped with everything inside them;
// any other tag is unwrapped (its text stays). Comments go.
//
// Plain text passes through unchanged in meaning: "&lt;b&gt;" stays the text
// "<b>", never a tag. Uses PHP's DOM; on a server without it the input is
// returned as escaped text (safe, formatting lost), never as it came.
//
// Used by the Testimonies admin (api/testimony_admin_api.php) on save and on
// read, and by the public wall (api/testimony_public_api.php) on read, so rows
// saved before the allowlist existed are cleaned on the way out too.

const HOD_HTML_KEEP = ['p', 'br', 'div', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li'];
const HOD_HTML_DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
    'head', 'title', 'meta', 'link', 'base', 'img', 'picture', 'source', 'svg', 'math', 'video', 'audio', 'track', 'canvas',
    'form', 'input', 'button', 'select', 'option', 'textarea'];

if (!function_exists('hod_html_allowlist')) {
    function hod_html_allowlist(?string $html, array $keep = HOD_HTML_KEEP): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        if (!class_exists('DOMDocument')) {
            return htmlspecialchars(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $html = preg_replace('/<meta\b[^>]*>/i', '', $html) ?? '';
        $doc->loadHTML('<?xml encoding="utf-8"?><html><body><div id="hod-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('hod-root');
        if (!$root) {
            return htmlspecialchars(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        }
        hod_html_allowlist_walk($root, $keep);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** @internal */
    function hod_html_allowlist_walk(DOMNode $node, array $keep): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child);   // comments, processing instructions, CDATA
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, HOD_HTML_DROP, true)) {
                $node->removeChild($child);
                continue;
            }

            hod_html_allowlist_walk($child, $keep);

            if (!in_array($tag, $keep, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->nodeName);
            }
        }
    }

    /** True when the formatted text has no words in it (only tags, breaks and spaces). */
    function hod_html_is_blank(?string $html): bool
    {
        $text = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text, " \t\n\r\0\x0B\u{00A0}") === '';
    }
}
