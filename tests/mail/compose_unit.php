<?php
// /tests/mail/compose_unit.php
/**
 * Mail, PR 3 (writing): what needs no server and no database. The editor's HTML made safe (HtmlCleaner), plain
 * text both ways, addresses, the quoted original, the church signature (escaped, both styles, numbers checked),
 * file types from first bytes, and the composer's endpoints, mount and script (CSRF, limits, gating, English,
 * strict CSP).
 *
 *   php tests/mail/compose_unit.php
 */

require __DIR__ . '/support/lib.php';

use HOD\Mail\Compose\ComposeConfig;
use HOD\Mail\Compose\HtmlCleaner;
use HOD\Mail\Compose\MessageBuilder;
use HOD\Mail\Compose\Outbox;
use HOD\Mail\Compose\Recipients;
use HOD\Mail\Compose\Signatures;
use HOD\Mail\MailError;

$root = dirname(__DIR__, 2);

section('The editor\'s HTML is cleaned before it is kept or sent');
$cases = [
    'script' => '<p>Hi</p><script>alert(1)</script>',
    'event handler' => '<p onclick="alert(1)">Hi</p>',
    'javascript: link' => '<a href="javascript:alert(1)">x</a>',
    'data: link' => '<a href="data:text/html,<script>alert(1)</script>">x</a>',
    'style and class' => '<p style="color:red" class="x" id="y">Hi</p>',
    'image (a tracker in a reply)' => '<img src="https://evil.example/p.gif">',
    'iframe and form' => '<iframe src="x"></iframe><form><input></form>',
    'svg' => '<svg onload="alert(1)"><circle /></svg>',
];
foreach ($cases as $label => $html) {
    $out = strtolower(HtmlCleaner::clean($html));
    check("{$label}: nothing that runs, no attribute but href", !preg_match('/<script|<iframe|<form|<input|<svg|<img|\son[a-z]+=|javascript:|data:|style=|class=|id=/', $out), $out);
}
check('formatting stays', HtmlCleaner::clean('<p><b>Bold</b> <i>it</i> <u>u</u></p><ul><li>One</li></ul><blockquote>q</blockquote>') === '<p><b>Bold</b> <i>it</i> <u>u</u></p><ul><li>One</li></ul><blockquote>q</blockquote>');
check('safe links stay', HtmlCleaner::clean('<a href="https://hodlekki.org" target="_blank">Site</a> <a href="mailto:info@hodlekki.org">mail</a>') === '<a href="https://hodlekki.org">Site</a> <a href="mailto:info@hodlekki.org">mail</a>');
check('unknown tags are unwrapped, their words kept', HtmlCleaner::clean('<span><font>Kept</font></span>') === 'Kept');
check('an editor left with empty lines is empty', HtmlCleaner::clean('<p><br></p><div>&nbsp;</div>') === '');
check('plain text: paragraphs, links as text (address)', HtmlCleaner::toText('<p>Hello</p><p>See <a href="https://hodlekki.org/give">our page</a></p>') === "Hello\n\nSee our page (https://hodlekki.org/give)");
check('text to HTML is escaped', HtmlCleaner::fromText("<b>x</b>\n\nTwo") === '<p>&lt;b&gt;x&lt;/b&gt;</p><p>Two</p>');

section('Addresses');
$r = Recipients::parse('Ada Obi <Ada@Example.org>, bola@x.org; nope, bola@x.org');
check('names and addresses read, lower case, no duplicates', $r['valid'] === [['name' => 'Ada Obi', 'email' => 'ada@example.org'], ['name' => null, 'email' => 'bola@x.org']]);
check('what is not an address is reported', $r['invalid'] === ['nope']);
check('format', Recipients::format(['name' => 'Ada Obi', 'email' => 'ada@example.org']) === 'Ada Obi <ada@example.org>' && Recipients::format(['name' => null, 'email' => 'a@b.org']) === 'a@b.org');
check('without', Recipients::without([['name' => null, 'email' => 'a@b.org'], ['name' => null, 'email' => 'c@d.org']], ['A@B.org']) === [['name' => null, 'email' => 'c@d.org']]);
check('a name cannot smuggle header characters', Recipients::parse([['name' => "Eve\r\nBcc: x@y.org", 'email' => 'eve@x.org']])['valid'][0]['name'] === 'EveBcc: x@y.org');

section('The original, quoted');
$original = ['from_name' => 'Ada <Obi>', 'from_email' => 'ada@example.org', 'subject' => 'Camp', 'received_at' => '2026-11-03 10:42:00', 'to_list' => [['name' => null, 'email' => 'embrace@hodlekki.org']], 'cc_list' => []];
$q = MessageBuilder::quote('reply', $original, ['text' => "<script>x</script>\nSee you", 'html' => null]);
check('a reply quotes in English, escaped', str_contains($q['html'], 'wrote:') && str_contains($q['html'], '&lt;script&gt;') && !str_contains($q['html'], '<script'));
check('the plain twin quotes with >', str_contains($q['text'], "\n> <script>x</script>\n> See you"));
$f = MessageBuilder::quote('forward', $original, ['text' => 'Body', 'html' => null]);
check('a forward has the original\'s header lines', str_contains($f['text'], "---------- Forwarded message ----------\nFrom: Ada Obi <ada@example.org>") && str_contains($f['text'], 'Subject: Camp'));
check('dates in English', (bool) preg_match('/^\d{1,2} [A-Z][a-z]{2} \d{4} at \d{2}:\d{2}$/', (string) MessageBuilder::when(new DateTimeImmutable('2026-11-03 10:42:00'))));

section('The church signature');
$person = ['name' => 'Ada <Obi>', 'title' => 'Head of Department', 'department' => 'Embrace', 'phone' => '+234 801 234 5678', 'mobile' => null];
$church = ['name' => 'Household of David Lekki', 'tagline' => 'A house of prayer', 'address' => 'Lekki, Lagos', 'phone' => '', 'website' => 'https://hodlekki.org', 'logo_url' => 'https://hodlekki.org/assets/images/mail/hod-signature-logo.png', 'show_logo' => true];
$full = Signatures::render($person, $church, 'embrace@hodlekki.org', 'full');
check('names are escaped', str_contains($full, 'Ada &lt;Obi&gt;') && !str_contains($full, 'Ada <Obi>'));
check('tables and inline styles only (mail apps drop the rest)', str_contains($full, 'role="presentation"') && !str_contains($full, '<style') && !str_contains($full, 'class='));
check('the logo is a sized PNG on https', str_contains($full, 'width="76" height="76"') && str_contains($full, 'src="https://hodlekki.org/assets/images/mail/hod-signature-logo.png"'));
check('phone, mailbox and site are links', str_contains($full, 'href="tel:+2348012345678"') && str_contains($full, 'href="mailto:embrace@hodlekki.org"') && str_contains($full, '>hodlekki.org</a>'));
check('the place in the department shows', str_contains($full, 'Head of Department · Embrace'));
$slim = Signatures::render($person, $church, 'embrace@hodlekki.org', 'slim');
check('the short one is one block, no logo', !str_contains($slim, '<img') && str_contains($slim, 'Household of David Lekki'));
check('none is nothing', Signatures::render($person, $church, 'x@y.org', 'none') === '' && Signatures::renderText($person, $church, 'x@y.org', 'none') === '');
check('the plain twin starts with the "-- " line', str_starts_with(Signatures::renderText($person, $church, 'embrace@hodlekki.org', 'full'), "-- \nAda <Obi>\nHead of Department · Embrace"));
$e = throws(fn () => Signatures::checkNumbers(['phone' => 'call me', 'mobile' => '']));
check('a number that is not one is refused, in English', $e instanceof MailError && $e->field === 'phone' && str_contains($e->getMessage(), 'digits'));
check('blank numbers are hidden', Signatures::checkNumbers(['phone' => ' ', 'mobile' => '0801 234 5678']) === ['phone' => null, 'mobile' => '0801 234 5678']);
$e = throws(fn () => Signatures::checkChurch(['name' => 'HOD', 'logo_url' => 'http://insecure.example/logo.png']));
check('a logo must be on https', $e instanceof MailError && $e->field === 'logo_url');
check('a site without a scheme gets https', Signatures::checkChurch(['name' => 'HOD', 'website' => 'hodlekki.org'])['website'] === 'https://hodlekki.org');
check('three styles, in English', array_values(Signatures::STYLES) === ['Full', 'Short', 'None']);

section('Files and limits');
check('types from first bytes, then the name', Outbox::mimeOf('x.bin', '%PDF-1.4') === 'application/pdf' && Outbox::mimeOf('photo.jpg', "\xFF\xD8\xFF\xE0") === 'image/jpeg'
    && Outbox::mimeOf('notes.docx', 'PK') === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' && Outbox::mimeOf('x.exe', 'MZ') === 'application/octet-stream');
check('18 MB of files together, 50 recipients, 10 s to undo', ComposeConfig::get('max_attachment_bytes') === 18 * 1024 * 1024 && ComposeConfig::get('max_recipients') === 50 && ComposeConfig::get('undo_seconds') === 10);
check('replies get the short signature, new mail the full one', Outbox::emptyPayload('reply')['signature'] === 'slim' && Outbox::emptyPayload('new')['signature'] === 'full');
check('the logo file is small enough for every recipient', is_file("{$root}/assets/images/mail/hod-signature-logo.png") && filesize("{$root}/assets/images/mail/hod-signature-logo.png") < 60000);

section('The composer\'s endpoint, mount and scripts');
$api = (string) file_get_contents("{$root}/api/mail_compose_api.php");
check('every POST checks CSRF', str_contains($api, 'csrf_require();'));
check('it waits for its tables', str_contains($api, 'mail_compose_ready()'));
check('uploads too large for PHP are told so', str_contains($api, 'UPLOAD_ERR_INI_SIZE'));
check('the church part is for mail admins only', substr_count($api, "if (!mail_can('admin'))") === 2);
check('errors go through mail_json_error', str_contains($api, 'mail_json_error($e)'));
$mount = (string) file_get_contents("{$root}/includes/mail/mounts/composer.php");
check('the mount answers 404 when reached by URL', str_contains($mount, "defined('HOD_MAIL_READ_PARTIAL')"));
$partial = (string) file_get_contents("{$root}/includes/mail/Read/Partial.php");
check('Reply waits for the composer\'s tables', str_contains($partial, "\$name !== 'composer' || !function_exists('mail_compose_ready') || mail_compose_ready()"));
$js = (string) file_get_contents("{$root}/assets/ui/js/pages/mail-compose.js");
check('signature and quote reach the page through CSSOM (strict CSP)', str_contains($js, 'paint(sigPreview') && str_contains($js, 'paint(quoteBody') && str_contains($js, 'style.cssText'));
check('pasted HTML is cleaned in the browser too', str_contains($js, 'function cleanHtml'));
check('requests go through HOD.api', str_contains($js, 'HOD.api(API') && !str_contains($js, 'fetch('));
$cron = (string) file_get_contents("{$root}/cron/mail_minute.php");
check('the minute cron sends what is due', str_contains($cron, 'OutboxSender::make()->due()'));
foreach (array_merge(glob("{$root}/includes/mail/Compose/*.php") ?: [], [$api, "{$root}/includes/mail/mounts/composer.php", "{$root}/modules/mail/signature.php", "{$root}/modules/mail/outbox.php"]) as $file) {
    $src = is_file($file) ? (string) file_get_contents($file) : $file;
    $name = is_file($file) ? basename($file) : 'mail_compose_api.php';
    check("{$name}: English only", !preg_match('/\b(vous|courrier|bureau|Réessayez|Brouillon|Envoyer|Répondre|Annuler)\b/u', $src));
}

mail_test_done();
