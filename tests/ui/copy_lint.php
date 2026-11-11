<?php
// tests/ui/copy_lint.php
/**
 * The short-copy linter (docs/UI.md "Writing: short copy").
 *
 * Scans the pages people read — strict pages (rebuilt on the components) and
 * standalone/public pages (their own document, no shell) — for copy written
 * as a literal in PHP, and checks it against the limits below. Copy built in
 * page scripts (assets/ui/js/pages/*.js) follows the same rule but is
 * reviewed by hand.
 *
 * tests/ui/run.php runs it with tests/ui/copy_allowlist.txt: every finding
 * not on that list fails CI, and every listed line that is no longer a
 * finding fails too ("remove it"), so the list can only shrink. A listed
 * string that is edited no longer matches, so editing old copy means fixing
 * it.
 *
 *   php tests/ui/copy_lint.php          print findings (path | kind | text)
 *   php tests/ui/copy_lint.php --list   the same, sorted, ready for the allowlist
 */

const COPY_LIMITS = [
    'title' => 4,        // page and dialog titles: 2–4 words (a greeting is not counted)
    'subtitle' => 12,    // the one line under a title, dialog descriptions
    'banner-title' => 6,
    'banner-text' => 12, // one line saying what to do
    'empty-text' => 12,  // the line under an empty state's title
    'hint' => 10,        // field hints: only when essential
    'button' => 3,       // a verb and an object
    'paragraph' => 12,   // a plain <p> of fixed copy
];

/** Pages out of scope: their own reading designs and Special Events. */
const COPY_SKIP = '#^(modules/(bible|hymnal|special_events)/|e/|live/|assets/|vendor/|tests/|includes/|api/|db/|bin/|docs/|node_modules/|\.)#';

/** Words in a piece of copy; a leading greeting ("Hi Ada", "Welcome, Ada") is not counted. */
function copy_words(string $text, bool $isTitle = false): int
{
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($isTitle) {
        $text = (string) preg_replace('/^(hi|hello|welcome( back)?|good (morning|afternoon|evening))\b,?[^,!.]*[,!.]?\s*/i', '', $text);
    }
    $text = (string) preg_replace('/[\x{2013}\x{2014}&·|\/+]/u', ' ', $text);
    $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return count(array_filter($parts, static fn ($w) => preg_match('/[\p{L}\p{N}]/u', $w)));
}

/** More than one sentence? ("Done. Next…") */
function copy_sentences(string $text): int
{
    $text = trim(strip_tags($text));
    return max(1, preg_match_all('/[.!?](\s+|$)/u', $text));
}

/** The source of each argument list of every call to $fn (balanced, strings skipped). */
function copy_calls(string $src, string $fn): array
{
    $out = [];
    $offset = 0;
    while (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $quote = null;
        $len = strlen($src);
        for ($i = $start; $i < $len && $depth > 0; $i++) {
            $c = $src[$i];
            if ($quote !== null) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $quote) { $quote = null; }
                continue;
            }
            if ($c === "'" || $c === '"') { $quote = $c; continue; }
            if ($c === '(' || $c === '[') { $depth++; }
            elseif ($c === ')' || $c === ']') { $depth--; }
        }
        $out[] = substr($src, $start, $i - $start - 1);
        $offset = $i;
    }
    return $out;
}

/** A literal 'key' => '…' (or "…") at the top level of an option array. */
function copy_option(string $args, string $key): ?string
{
    if (preg_match('/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")/s', $args, $m)) {
        return stripslashes(substr($m[1], 1, -1));
    }
    return null;
}

/** The first argument when it is a plain string literal. */
function copy_first_literal(string $args): ?string
{
    if (preg_match('/^\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")\s*(,|$)/s', $args, $m)) {
        return stripslashes(substr($m[1], 1, -1));
    }
    return null;
}

/** Which files are pages people read. */
function copy_pages(string $root): array
{
    $pages = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (!preg_match('/\.php$/', $path) || preg_match(COPY_SKIP, $path)) {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        $strict = (bool) preg_match("/'mode'\\s*=>\\s*'strict'/", $src);
        $usesShell = (bool) preg_match('/require(_once)?[^;]*header\.php/', $src);
        $standalone = !$usesShell && (stripos($src, '<!DOCTYPE') !== false || strpos($src, 'ui_public_open(') !== false);
        if ($strict || $standalone) {
            $pages[$path] = $src;
        }
    }
    ksort($pages);
    return $pages;
}

/** Every finding: [path, kind, text]. */
function copy_findings(string $root): array
{
    $found = [];
    $check = static function (string $path, string $kind, ?string $text) use (&$found): void {
        if ($text === null || trim($text) === '' || strpos($text, '<?') !== false) {
            return;
        }
        $limit = COPY_LIMITS[$kind];
        $words = copy_words($text, $kind === 'title');
        $tooLong = $words > $limit;
        // The line under a title is one sentence.
        if (!$tooLong && in_array($kind, ['subtitle', 'banner-text', 'hint'], true) && copy_sentences($text) > 1) {
            $tooLong = true;
        }
        if ($tooLong) {
            $found[] = [$path, $kind, trim(preg_replace('/\s+/', ' ', $text))];
        }
    };

    foreach (copy_pages($root) as $path => $src) {
        foreach (copy_calls($src, 'ui_page_header') as $a) {
            $check($path, 'title', copy_option($a, 'title'));
            $check($path, 'subtitle', copy_option($a, 'description'));
        }
        foreach (copy_calls($src, 'ui_dialog_start') as $a) {
            $check($path, 'title', copy_option($a, 'title'));
            $check($path, 'subtitle', copy_option($a, 'description'));
        }
        foreach (copy_calls($src, 'ui_banner') as $a) {
            $check($path, 'banner-title', copy_option($a, 'title'));
            $check($path, 'banner-text', copy_option($a, 'text'));
        }
        foreach (copy_calls($src, 'ui_empty') as $a) {
            $check($path, 'title', copy_option($a, 'title'));
            $check($path, 'empty-text', copy_option($a, 'text'));
        }
        foreach (copy_calls($src, 'ui_field') as $a) {
            $check($path, 'hint', copy_option($a, 'hint'));
        }
        foreach (copy_calls($src, 'ui_button') as $a) {
            $check($path, 'button', copy_first_literal($a));
        }
        // Plain paragraphs of fixed copy (no PHP inside).
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $src, $ps)) {
            foreach ($ps[1] as $p) {
                if (strpos($p, '<?') === false && strpos($p, '${') === false && strpos($p, '+ ') === false) {
                    $check($path, 'paragraph', $p);
                }
            }
        }
    }
    return $found;
}

/** One allowlist / report line. */
function copy_line(array $f): string
{
    return $f[0] . ' | ' . $f[1] . ' | ' . $f[2];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $lines = array_map('copy_line', copy_findings(dirname(__DIR__, 2)));
    if (in_array('--list', $argv, true)) {
        $lines = array_values(array_unique($lines));
        sort($lines);
    }
    echo implode("\n", $lines), $lines ? "\n" : '';
    fwrite(STDERR, count($lines) . " finding(s)\n");
}
