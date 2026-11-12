<?php
// /tests/mail/read_unit.php
/**
 * Mail, PR 2 (reading): what needs no server and no database. A sender's HTML made safe (an XSS corpus, the two
 * JBS HQ fixes: links that wrap tables, and dark-only CSS), the reader's document and its policy, plain text,
 * dates and sizes, search words, the partials (Reply only with the composer), the page's endpoints (own
 * CSP, sniffed files) and the short copy.
 *
 *   php tests/mail/read_unit.php
 */

require __DIR__ . '/support/lib.php';

use HOD\Mail\Read\DarkPalette;
use HOD\Mail\Read\Format;
use HOD\Mail\Read\HtmlSanitizer;
use HOD\Mail\Read\Partial;
use HOD\Mail\Read\Reader;
use HOD\Mail\Read\TextFormatter;
use HOD\Mail\Read\ThreadList;

$root = dirname(__DIR__, 2);
$clean = static fn (string $html, bool $remote = false, ?callable $cid = null): array => HtmlSanitizer::clean($html, $remote, $cid);
$all = static function (string $html) use ($clean): string {
    $c = $clean($html);

    return $c['html'] . "\n" . $c['styles'];
};

section('A sender\'s HTML never runs');
$corpus = [
    'script tag' => '<p>hi</p><script>alert(1)</script>',
    'event handler' => '<img src="x" onerror="alert(1)">',
    'javascript: link' => '<a href="javascript:alert(1)">x</a>',
    'entity-encoded javascript:' => '<a href="&#106;avascript:alert(1)">x</a>',
    'tab inside javascript:' => "<a href=\"java\tscript:alert(1)\">x</a>",
    'vbscript: link' => '<a href="vbscript:msgbox(1)">x</a>',
    'data:text/html link' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    'iframe' => '<iframe src="https://evil.example/"></iframe>',
    'object and embed' => '<object data="x.swf"></object><embed src="x.swf">',
    'svg with script' => '<svg><script>alert(1)</script></svg>',
    'svg onload' => '<svg onload="alert(1)"></svg>',
    'math with href' => '<math><a xlink:href="javascript:alert(1)">x</a></math>',
    'form and input' => '<form action="https://evil.example/"><input name="p" type="password"></form>',
    'meta refresh' => '<meta http-equiv="refresh" content="0;url=https://evil.example/">',
    'base href' => '<base href="https://evil.example/">',
    'link stylesheet' => '<link rel="stylesheet" href="https://evil.example/x.css">',
    'style expression' => '<div style="width: expression(alert(1))">x</div>',
    'style url javascript' => '<div style="background:url(javascript:alert(1))">x</div>',
    'style @import' => '<style>@import url(https://evil.example/x.css);</style><p>x</p>',
    'style behavior' => '<div style="behavior:url(x.htc)">x</div>',
    'moz-binding' => '<div style="-moz-binding:url(x.xml#x)">x</div>',
    'closing style early' => '<style>p{color:red}</style><style></style><script>alert(1)</script>',
    'srcdoc attribute' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'formaction' => '<button formaction="javascript:alert(1)">x</button>',
    'comment trick' => '<!--<img src="--><img src=x onerror=alert(1)//">',
    'nested quotes' => '<img src="x" alt="&quot; onerror=&quot;alert(1)">',
    'upper case tag' => '<SCRIPT>alert(1)</SCRIPT>',
    'noscript' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
    'template' => '<template><script>alert(1)</script></template>',
];
foreach ($corpus as $label => $html) {
    // only markup and CSS can run: text that merely reads like an attribute (escaped) is harmless
    preg_match_all('/<[^>]*>/', strtolower($all($html)), $tags);
    $out = implode(' ', $tags[0]) . ' ' . strtolower($clean($html)['styles']);
    $bad = preg_match('/<script|<iframe|<object|<embed|<form|<input|<meta|<base|<link|<svg|<math|<template|\son[a-z]+\s*=|javascript:|vbscript:|data:text\/html|expression\s*\(|@import|behavior\s*:|-moz-binding|srcdoc|formaction/', $out);
    check("{$label}: nothing that runs is left", $bad === 0, $out);
}

section('What mail needs survives');
$c = $clean('<table><tr><td style="color:#336699;padding:4px">Cell</td></tr></table><p><b>Bold</b> <a href="https://hodlekki.org/give">Give</a></p>');
check('tables and cells stay', str_contains($c['html'], '<table') && str_contains($c['html'], '<td'));
check('a safe inline style stays', str_contains($c['html'], 'color:#336699') || str_contains($c['html'], 'color: #336699'));
check('an https link stays and opens apart', str_contains($c['html'], 'href="https://hodlekki.org/give"') && str_contains($c['html'], 'rel="noopener noreferrer nofollow"'));
check('mailto: links stay', str_contains($clean('<a href="mailto:info@hodlekki.org">x</a>')['html'], 'mailto:info@hodlekki.org'));

section('Pictures from the internet wait for the person');
$c = $clean('<img src="https://tracker.example/p.gif"><img src="http://x.example/a.png">');
check('remote pictures are held back', !str_contains($c['html'], 'tracker.example') && !str_contains($c['html'], 'x.example'));
check('and counted, so the page can offer them', $c['blocked'] === 2, (string) $c['blocked']);
$c = $clean('<img src="https://tracker.example/p.gif">', true);
check('once allowed, they load', str_contains($c['html'], 'https://tracker.example/p.gif') && $c['blocked'] === 0);
$c = $clean('<div style="background-image:url(https://tracker.example/bg.png)">x</div>');
check('a background picture is held back too', !str_contains($c['html'] . $c['styles'], 'tracker.example'));
$c = $clean('<img src="cid:logo@hod">', false, fn (string $id) => $id === 'logo@hod' ? '/api/mail_file.php?m=7&cid=logo%40hod' : null);
check('cid: pictures come from the ERP', str_contains($c['html'], '/api/mail_file.php?m=7&amp;cid=logo%40hod') || str_contains($c['html'], '/api/mail_file.php?m=7&cid=logo%40hod'));
$c = $clean('<img src="cid:missing@hod">', false, fn () => null);
check('an unknown cid: picture is dropped', !str_contains($c['html'], 'cid:'));
check('data: pictures stay (no request leaves)', str_contains($clean('<img src="data:image/png;base64,iVBORw0KGgo=">')['html'], 'data:image/png'));

section('JBS fix: a link that wraps a table keeps its table');
$c = $clean('<a href="https://hodlekki.org/event"><table><tr><td>Register now</td></tr></table></a>');
check('the table is still inside the link', (bool) preg_match('~<a [^>]*href="https://hodlekki.org/event"[^>]*>\s*<table~', $c['html']), $c['html']);
check('no stray hoda element is left', !str_contains($c['html'], 'hoda'));

section('JBS fix: CSS for a dark device is taken out');
$css = 'p{color:#111}@media (prefers-color-scheme: dark){p{color:#e8eaed}}';
check('a dark-only block goes', DarkPalette::strip($css) === 'p{color:#111}', DarkPalette::strip($css));
check('a light-true query stays', str_contains(DarkPalette::strip('@media not (prefers-color-scheme: dark){p{color:#000}}'), 'color:#000'));
check('the other half of a list stays', str_contains(DarkPalette::strip('@media (prefers-color-scheme: dark), print{p{color:#000}}'), '@media print{p{color:#000}}'));
check('color-scheme declarations go', !str_contains(DarkPalette::strip(':root{color-scheme:light dark;}p{color:red}'), 'color-scheme'));
check('braces inside strings are not structure', str_contains(DarkPalette::strip('p::after{content:"}"}@media (prefers-color-scheme:dark){p{color:#fff}}'), 'content:"}"'));
check('unbalanced braces drop the rest', DarkPalette::strip('a{b:c}@media (prefers-color-scheme: dark){p{color:#fff}') === 'a{b:c}');
$c = $clean('<style>@media (prefers-color-scheme: dark){.x{color:#e8eaed}}.y{color:#202124}</style><p class="y">x</p>');
check('the sanitizer applies it', !str_contains($c['styles'], '#e8eaed') && str_contains($c['styles'], '#202124'), $c['styles']);

section('The reader\'s document');
$doc = html_entity_decode(Reader::document('<p>Hello</p>', '', false), ENT_QUOTES);
check('its policy refuses every script', str_contains($doc, "default-src 'none'") && !str_contains($doc, 'script-src'));
check('pictures only from the ERP and data: until allowed', str_contains($doc, "img-src 'self' data:;"));
check('allowed pictures may come from anywhere', str_contains(html_entity_decode(Reader::document('<p>x</p>', '', true), ENT_QUOTES), "img-src 'self' data: https: http:"));
check('no forms, no base', str_contains($doc, "form-action 'none'") && str_contains($doc, "base-uri 'none'"));
check('links open outside the frame', str_contains($doc, '<base target="_blank">'));
$p = Reader::present('', '<p>Hi <b>Ada</b></p>', false, fn () => null, Reader::VIEW_URL . '?m=5');
check('an HTML body is shown from mail_view.php', $p['kind'] === 'html' && $p['src'] === '/api/mail_view.php?m=5');
check('a text body is escaped', Reader::present('<b>not bold</b>', null, false, fn () => null)['kind'] === 'text'
    && str_contains(Reader::present('<b>not bold</b>', null, false, fn () => null)['html'], '&lt;b&gt;'));
check('an empty body says so', Reader::present('  ', '  ', false, fn () => null)['kind'] === 'empty');

section('Plain text');
$t = TextFormatter::toHtml("Hello <Ada>\nSee https://hodlekki.org/a?b=1&c=2.\n\n> quoted line\n> more");
check('text is escaped', str_contains($t, '&lt;Ada&gt;'));
check('links become links', str_contains($t, 'href="https://hodlekki.org/a?b=1&amp;c=2"'));
check('the full stop is not part of the link', !str_contains($t, 'c=2."'));
check('quoted lines fold away', str_contains($t, HtmlSanitizer::QUOTE_LABEL));
check('a javascript: word is never a link', !str_contains(TextFormatter::toHtml('javascript:alert(1)'), 'href'));

section('Dates, sizes, people');
check('sizes read as people say them', Format::size(512) === '512 B' && Format::size(2048) === '2 KB' && Format::size(1468006) === '1.4 MB');
check('counts agree', Format::count(1, 'match', 'matches') === '1 match' && Format::count(3, 'match', 'matches') === '3 matches');
check('initials from a name', Format::initials('Ada Obi') === 'AO');
check('initials from an address', Format::initials('media.team@hodlekki.org') === 'MT');
check('the mailbox itself is left out of a row', Format::people([['email' => 'info@hodlekki.org', 'name' => 'Info'], ['email' => 'ada@example.org', 'name' => 'Ada']], 'INFO@hodlekki.org') === 'Ada');
check('addresses with names', Format::addresses([['name' => 'Ada Obi', 'email' => 'ada@example.org'], ['name' => '', 'email' => 'x@y.org']]) === 'Ada Obi <ada@example.org>, x@y.org');
check('a full date in English', (bool) preg_match('/^\d{1,2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4}, \d{2}:\d{2}$/', Format::full('2026-11-03 10:42:00')), Format::full('2026-11-03 10:42:00'));

section('Search words');
[$words, $from] = ThreadList::parse('from:ada "youth camp" budget');
check('from: looks at the sender', $from === ['ada']);
check('quoted words stay together', $words === ['youth camp', 'budget'], json_encode($words));
check('full text needs every word, as a prefix', ThreadList::fullText(['youth camp', 'budget']) === '+youth* +camp* +budget*');
check('short words are left to LIKE', ThreadList::fullText(['to', 'ab']) === '');
check('the waiting filter exists for shared mailboxes', in_array('waiting', ThreadList::filtersFor(['kind' => 'church']), true));
check('personal mailboxes have no teamwork filters', ThreadList::filtersFor(['kind' => 'personal']) === ['all', 'unread']);

section('Pieces of the page');
check('the composer is a mount (PR 3), so Reply exists', is_file("{$root}/includes/mail/mounts/composer.php"));
check('a mount name cannot leave its folder', !Partial::mounted('../partials/list') && Partial::mount('../x') === '');
foreach (['list', 'reader_head', 'message', 'live', 'live_list'] as $name) {
    $src = (string) file_get_contents("{$root}/includes/mail/Read/partials/{$name}.php");
    check("{$name}: answers 404 when reached by URL", str_contains($src, "defined('HOD_MAIL_READ_PARTIAL')"));
    check("{$name}: Reply only behind the composer", !str_contains($src, 'data-compose="reply"') || str_contains($src, "Partial::mounted('composer')"));
    check("{$name}: no frame from srcdoc", !str_contains($src, 'srcdoc'));
    check("{$name}: frames never run scripts", !str_contains($src, 'allow-scripts'));
}

section('Endpoints');
$view = (string) file_get_contents("{$root}/api/mail_view.php");
check('mail_view sends its own CSP', str_contains($view, 'Content-Security-Policy') && str_contains($view, "frame-ancestors 'self'"));
check('mail_view sends no referrer and no sniffing', str_contains($view, 'Referrer-Policy: no-referrer') && str_contains($view, 'nosniff'));
check('mail_view checks the person can read', str_contains($view, "MailAccess::canMessage(\$uid, \$m, 'read')") && str_contains($view, "MailAccess::can(\$uid, \$account, 'read')"));
check('mail_view never marks read', !str_contains($view, 'markOpened'));
$file = (string) file_get_contents("{$root}/api/mail_file.php");
check('mail_file checks first bytes before showing a file', str_contains($file, 'mail_file_looks_like($mime, $file[\'content\'])'));
check('mail_file never shows SVG', !str_contains($file, 'image/svg'));
check('everything else downloads as bytes', str_contains($file, 'application/octet-stream'));
$api = (string) file_get_contents("{$root}/api/mail_api.php");
check('every POST checks CSRF', str_contains($api, 'csrf_require();'));
check('the API never answers with a sender\'s raw HTML', !str_contains($api, "'doc'"));

section('Short copy (owner rule, 11 Nov 2026)');
$page = (string) file_get_contents("{$root}/modules/mail/index.php");
check('the page has no French left', !preg_match('/\b(Boîte|Dossiers|Répondre|Corbeille|Indésirables|courrier)\b/u', $page));
$mailboxes = (string) file_get_contents("{$root}/modules/mail/mailboxes.php");
check('Mailboxes: the line under the title is one short sentence', (bool) preg_match('/\'description\' => "Mailboxes on \{\$domain\}\."/', $mailboxes));
check('Mailboxes: the bring-in note names the three addresses behind (i)', substr_count($mailboxes, 'Leave info@, embrace@ and tom.blake@ outside') === 2);
foreach (glob("{$root}/includes/mail/Read/{*.php,partials/*.php}", GLOB_BRACE) ?: [] as $f) {
    $src = (string) file_get_contents($f);
    check(basename($f) . ': English only', !preg_match('/\b(vous|courrier|Réessayez|Pièce jointe|bureau)\b/u', $src));
}

mail_test_done();
