<?php
// /includes/campaigns/doc.php
//
// An email is a BLOCK DOCUMENT (JSON), never HTML. Nobody writes HTML, and the
// stored document contains none: rich text inside a text block is a small tree
// of paragraphs and lists of runs (bold / italic / link). So the only thing to
// sanitise is STRUCTURE, which email_doc_validate() does strictly — unknown
// block types and fields are refused or dropped, every string is typed and
// capped, links are https/mailto/tel only, images come only from this site,
// merge fields only from the list below. Text is escaped when it is rendered.
//
//   {"v":1, "subject":"…", "preheader":"…", "blocks":[ {"type":"heading",…}, … ]}
//
// Block types: heading, text, image, button, divider, spacer, scripture,
// quote, event, signature, footer. Exactly one footer, always last (added when
// missing): it carries the church's details, why the person got the email and,
// for church news, the unsubscribe link — nobody types that link.

const EMAIL_DOC_VERSION    = 1;
const EMAIL_DOC_MAX_BLOCKS = 60;
const EMAIL_BLOCK_TYPES    = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'scripture',
                              'quote', 'event', 'signature', 'footer'];
const EMAIL_MERGE_FIELDS   = ['first_name', 'last_name', 'full_name', 'email', 'church_name', 'week_number',
                              'weeks_away', 'streak', 'next_sunday', 'next_midweek', 'last_attended'];
const EMAIL_ALIGNS         = ['left', 'center'];
const EMAIL_SPACER_SIZES   = ['s' => 8, 'm' => 16, 'l' => 32];
// Images are only ever served from this site: uploads made in the editor, or
// the church's own assets. No hot-linked pictures (tracking, broken images).
const EMAIL_IMAGE_PATH_RE  = '#^/(?:uploads/email|assets/images|assets/pwa)/[A-Za-z0-9_][A-Za-z0-9/_.-]*\.(?:png|jpe?g|gif|webp)$#i';

/** Merge fields used in a string, e.g. ['first_name']. */
function email_doc_merge_fields(string $s): array
{
    preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $s, $m);
    return $m[1];
}

/** A link target we will put in an email: https, mailto or tel only. */
function email_doc_url_ok(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || preg_match('/[\s\x00-\x1f\x7f<>"\\\\]/', $url)) {
        return false;
    }
    if (str_starts_with($url, 'mailto:')) {
        return email_is_valid_syntax(email_normalize(substr($url, 7)));
    }
    if (str_starts_with($url, 'tel:')) {
        return (bool) preg_match('/^tel:\+?[0-9]{3,20}$/', $url);
    }
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass'])
        && preg_match('/^[a-z0-9.-]+$/i', (string) $parts['host']);
}

/** An image the email may show: this site's uploads or assets only. */
function email_doc_image_ok(string $src): bool
{
    $origin = site_origin();
    if (!str_starts_with($src, $origin . '/')) {
        return false;
    }
    $path = substr($src, strlen($origin));
    return !str_contains($path, '..') && (bool) preg_match(EMAIL_IMAGE_PATH_RE, $path);
}

/**
 * Validate and normalise a document. Never throws on bad input.
 *
 * @param mixed $doc decoded JSON (or a JSON string)
 * @return array{ok:bool, doc:array, errors:list<string>}
 */
function email_doc_validate($doc): array
{
    $errors = [];
    if (is_string($doc)) {
        $doc = json_decode($doc, true);
    }
    if (!is_array($doc)) {
        return ['ok' => false, 'doc' => email_doc_empty(), 'errors' => ['The email could not be read.']];
    }
    if ((int) ($doc['v'] ?? 0) !== EMAIL_DOC_VERSION) {
        $errors[] = 'Unknown email format version.';
    }

    $line = static function ($value, string $label, int $min, int $max) use (&$errors): string {
        if (!is_string($value) && !is_int($value)) {
            $value = '';
        }
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            $errors[] = "{$label} contains characters that cannot be sent.";
            $value = preg_replace('/[\x00-\x1f\x7f]/', '', $value) ?? '';
        }
        $len = mb_strlen($value, 'UTF-8');
        if ($len < $min) {
            $errors[] = $min === 1 ? "{$label} is empty." : "{$label} is too short.";
        } elseif ($len > $max) {
            $errors[] = "{$label} is longer than {$max} characters.";
            $value = mb_substr($value, 0, $max, 'UTF-8');
        }
        foreach (email_doc_merge_fields($value) as $field) {
            if (!in_array($field, EMAIL_MERGE_FIELDS, true)) {
                $errors[] = "{$label} uses an unknown merge field {{{$field}}}.";
            }
        }
        return $value;
    };
    $align = static fn ($v): string => in_array($v, EMAIL_ALIGNS, true) ? $v : 'left';

    $out = [
        'v'         => EMAIL_DOC_VERSION,
        'subject'   => $line($doc['subject'] ?? '', 'The subject', 1, 150),
        'preheader' => $line($doc['preheader'] ?? '', 'The preview text', 0, 150),
        'blocks'    => [],
    ];

    $blocks = $doc['blocks'] ?? null;
    if (!is_array($blocks) || !array_is_list($blocks)) {
        $errors[] = 'The email has no blocks.';
        $blocks = [];
    }
    if (count($blocks) > EMAIL_DOC_MAX_BLOCKS) {
        $errors[] = 'An email can have at most ' . EMAIL_DOC_MAX_BLOCKS . ' blocks.';
        $blocks = array_slice($blocks, 0, EMAIL_DOC_MAX_BLOCKS);
    }

    $footerAt = null;
    foreach ($blocks as $i => $block) {
        $n = $i + 1;
        if (!is_array($block) || !in_array($block['type'] ?? null, EMAIL_BLOCK_TYPES, true)) {
            $errors[] = "Block {$n} is not a kind of block the editor knows.";
            continue;
        }
        $type = $block['type'];
        switch ($type) {
            case 'heading':
                $out['blocks'][] = [
                    'type'  => 'heading',
                    'level' => (int) ($block['level'] ?? 1) === 2 ? 2 : 1,
                    'align' => $align($block['align'] ?? 'left'),
                    'text'  => $line($block['text'] ?? '', "Heading (block {$n})", 1, 200),
                ];
                break;

            case 'text':
                $content = email_doc_validate_rich($block['content'] ?? null, "Text (block {$n})", $errors);
                $out['blocks'][] = ['type' => 'text', 'align' => $align($block['align'] ?? 'left'), 'content' => $content];
                break;

            case 'image':
                $src = is_string($block['src'] ?? null) ? trim($block['src']) : '';
                if (!email_doc_image_ok($src)) {
                    $errors[] = "Picture (block {$n}) must be uploaded in the editor.";
                }
                $href = is_string($block['href'] ?? null) ? trim($block['href']) : '';
                if ($href !== '' && !email_doc_url_ok($href)) {
                    $errors[] = "Picture link (block {$n}) must start with https://.";
                    $href = '';
                }
                $width = (int) ($block['width'] ?? 600);
                $out['blocks'][] = [
                    'type'  => 'image',
                    'src'   => $src,
                    'alt'   => $line($block['alt'] ?? '', "Picture description (block {$n})", 0, 200),
                    'width' => max(50, min(600, $width ?: 600)),
                    'href'  => $href !== '' ? $href : null,
                    'align' => $align($block['align'] ?? 'center'),
                ];
                break;

            case 'button':
                $href = is_string($block['href'] ?? null) ? trim($block['href']) : '';
                if (!email_doc_url_ok($href)) {
                    $errors[] = "Button (block {$n}) needs a link starting with https://.";
                }
                $out['blocks'][] = [
                    'type'  => 'button',
                    'label' => $line($block['label'] ?? '', "Button text (block {$n})", 1, 60),
                    'href'  => $href,
                    'style' => ($block['style'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary',
                    'align' => $align($block['align'] ?? 'left'),
                ];
                break;

            case 'divider':
                $out['blocks'][] = ['type' => 'divider'];
                break;

            case 'spacer':
                $size = (string) ($block['size'] ?? 'm');
                $out['blocks'][] = ['type' => 'spacer', 'size' => isset(EMAIL_SPACER_SIZES[$size]) ? $size : 'm'];
                break;

            case 'scripture':
                $ref = $line($block['ref'] ?? '', "Scripture reference (block {$n})", 1, 60);
                if ($ref !== '' && email_scripture($ref) === null) {
                    $errors[] = "Scripture (block {$n}): \"{$ref}\" is not a reference we can find in the KJV (up to 6 verses of one chapter).";
                }
                $out['blocks'][] = ['type' => 'scripture', 'ref' => $ref];
                break;

            case 'quote':
                $out['blocks'][] = [
                    'type' => 'quote',
                    'text' => $line($block['text'] ?? '', "Quote (block {$n})", 1, 600),
                    'cite' => $line($block['cite'] ?? '', "Quote source (block {$n})", 0, 100),
                ];
                break;

            case 'event':
                $id = (int) ($block['event_id'] ?? 0);
                if ($id <= 0) {
                    $errors[] = "Event (block {$n}): choose an event.";
                }
                $out['blocks'][] = ['type' => 'event', 'event_id' => max(0, $id)];
                break;

            case 'signature':
                $out['blocks'][] = [
                    'type' => 'signature',
                    'name' => $line($block['name'] ?? '', "Signature name (block {$n})", 1, 80),
                    'line' => $line($block['line'] ?? '', "Signature line (block {$n})", 0, 120),
                ];
                break;

            case 'footer':
                if ($footerAt !== null) {
                    $errors[] = 'An email has exactly one footer.';
                    continue 2;
                }
                $footerAt = count($out['blocks']);
                $out['blocks'][] = ['type' => 'footer'];
                break;
        }
    }

    if ($footerAt === null) {
        $out['blocks'][] = ['type' => 'footer'];
    } elseif ($footerAt !== count($out['blocks']) - 1) {
        $errors[] = 'The footer must be the last block.';
    }
    if (count($out['blocks']) < 2) {
        $errors[] = 'The email is empty.';
    }

    return ['ok' => $errors === [], 'doc' => $out, 'errors' => array_values(array_unique($errors))];
}

/**
 * Rich text: [{k:'p', r:[runs]}, {k:'ul'|'ol', items:[[runs], …]}].
 * A run: {t:string, b?:1, i?:1, href?:url}.
 */
function email_doc_validate_rich($content, string $label, array &$errors): array
{
    $out = [];
    if (!is_array($content) || !array_is_list($content) || $content === []) {
        $errors[] = "{$label} is empty.";
        return [['k' => 'p', 'r' => [['t' => '']]]];
    }
    if (count($content) > 50) {
        $errors[] = "{$label} has too many paragraphs (at most 50).";
        $content = array_slice($content, 0, 50);
    }
    $chars = 0;
    $runs = static function ($list) use ($label, &$errors, &$chars): array {
        $clean = [];
        if (!is_array($list) || !array_is_list($list) || $list === []) {
            $errors[] = "{$label} has an empty line.";
            return [['t' => '']];
        }
        foreach (array_slice($list, 0, 100) as $run) {
            if (!is_array($run) || !is_string($run['t'] ?? null)) {
                $errors[] = "{$label} contains something that is not text.";
                continue;
            }
            $t = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $run['t']);
            if (preg_match('/[\x00-\x1f\x7f]/', $t)) {
                $errors[] = "{$label} contains characters that cannot be sent.";
                $t = preg_replace('/[\x00-\x1f\x7f]/', '', $t) ?? '';
            }
            if (mb_strlen($t, 'UTF-8') > 2000) {
                $errors[] = "{$label} has a line longer than 2,000 characters.";
                $t = mb_substr($t, 0, 2000, 'UTF-8');
            }
            foreach (email_doc_merge_fields($t) as $field) {
                if (!in_array($field, EMAIL_MERGE_FIELDS, true)) {
                    $errors[] = "{$label} uses an unknown merge field {{{$field}}}.";
                }
            }
            $chars += mb_strlen($t, 'UTF-8');
            $clean_run = ['t' => $t];
            if (!empty($run['b'])) {
                $clean_run['b'] = 1;
            }
            if (!empty($run['i'])) {
                $clean_run['i'] = 1;
            }
            if (isset($run['href']) && $run['href'] !== null && $run['href'] !== '') {
                $href = is_string($run['href']) ? trim($run['href']) : '';
                if (email_doc_url_ok($href)) {
                    $clean_run['href'] = $href;
                } else {
                    $errors[] = "{$label} has a link that does not start with https:// (or mailto:/tel:).";
                }
            }
            $clean[] = $clean_run;
        }
        return $clean ?: [['t' => '']];
    };

    foreach ($content as $node) {
        $k = is_array($node) ? ($node['k'] ?? null) : null;
        if ($k === 'p') {
            $out[] = ['k' => 'p', 'r' => $runs($node['r'] ?? null)];
        } elseif ($k === 'ul' || $k === 'ol') {
            $items = $node['items'] ?? null;
            if (!is_array($items) || !array_is_list($items) || $items === []) {
                $errors[] = "{$label} has an empty list.";
                continue;
            }
            $out[] = ['k' => $k, 'items' => array_map($runs, array_slice($items, 0, 30))];
        } else {
            $errors[] = "{$label} contains something that is not a paragraph or a list.";
        }
    }
    if ($chars > 10000) {
        $errors[] = "{$label} is longer than 10,000 characters; split it into several blocks.";
    }
    if ($chars === 0) {
        $errors[] = "{$label} is empty.";
    }
    return $out ?: [['k' => 'p', 'r' => [['t' => '']]]];
}

function email_doc_empty(): array
{
    return ['v' => EMAIL_DOC_VERSION, 'subject' => '', 'preheader' => '', 'blocks' => [['type' => 'footer']]];
}

/** Plain paragraphs → a text block (for defaults and tests). */
function email_doc_paragraphs(string ...$paragraphs): array
{
    return ['type' => 'text', 'align' => 'left', 'content' => array_map(
        static fn (string $p): array => ['k' => 'p', 'r' => [['t' => $p]]],
        $paragraphs
    )];
}

/**
 * The KJV words for a reference, from the Bible module's own data (scripture is
 * never typed into code). One chapter, at most 6 verses.
 *
 * @return array{ref:string, text:string}|null
 */
function email_scripture(string $ref): ?array
{
    static $loaded = null;
    if ($loaded === null) {
        $books = __DIR__ . '/../bible/books.php';
        $text = __DIR__ . '/../bible/text.php';
        $loaded = is_file($books) && is_file($text);
        if ($loaded) {
            require_once $books;
            require_once $text;
        }
    }
    if (!$loaded || !function_exists('bible_parse_ref') || !function_exists('bible_kjv_chapter')) {
        return null;
    }
    $parsed = bible_parse_ref($ref);
    if ($parsed === null || $parsed['verse'] === null || $parsed['chapter_end'] !== $parsed['chapter']) {
        return null;
    }
    $from = (int) $parsed['verse'];
    $to = (int) ($parsed['verse_end'] ?? $from);
    if ($to < $from || $to - $from > 5) {
        return null;
    }
    $chapter = bible_kjv_chapter((int) $parsed['book'], (int) $parsed['chapter']);
    if ($chapter === null) {
        return null;
    }
    $words = [];
    foreach ($chapter as $verse) {
        if ($verse['v'] >= $from && $verse['v'] <= $to) {
            $words[] = trim((string) $verse['t']);
        }
    }
    if (count($words) !== $to - $from + 1) {
        return null;
    }
    $label = function_exists('bible_format_ref')
        ? bible_format_ref((int) $parsed['book'], (int) $parsed['chapter'], $from, null, $to !== $from ? $to : null)
        : $ref;
    return ['ref' => $label, 'text' => implode(' ', $words)];
}
