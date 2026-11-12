<?php
// /tests/mail/team_unit.php
/**
 * Mail, PR 4 (teamwork, alerts and AI): what needs no server and no database. Phone numbers and email addresses
 * hidden from the model and put back, the scripture check, the draft's HTML, the AI gates that need no database,
 * and static rules: AI never sends, every POST checks CSRF, the mounts answer 404 by URL, the scripts go through
 * HOD.api, English only.
 *
 *   php tests/mail/team_unit.php
 */

require __DIR__ . '/support/lib.php';

use HOD\Mail\Ai\Ai;
use HOD\Mail\Ai\Privacy;
use HOD\Mail\Ai\Writer;
use HOD\Mail\Team\MailTeam;
use HOD\Mail\Team\Prefs;

$root = dirname(__DIR__, 2);

section('Nothing personal reaches Google');
$p = new Privacy();
$hidden = $p->hide('Write to ada.obi@example.org or call +234 801 234 5678 or (01) 234 5678; ada.obi@example.org again.');
check('addresses and numbers become placeholders', !str_contains($hidden, '@') && !str_contains($hidden, '801') && str_contains($hidden, '[email 1]') && str_contains($hidden, '[phone 2]'), $hidden);
check('the same address keeps its placeholder', substr_count($hidden, '[email 1]') === 2 && !str_contains($hidden, '[email 2]'));
check('dates, times and short numbers stay', $p->hide('Sunday 9:30, room 12, 2026') === 'Sunday 9:30, room 12, 2026');
check('placeholders come back in the answer', $p->restore('Reach [email 1] or [phone 1].') === 'Reach ada.obi@example.org or +234 801 234 5678.');

section('Scripture only when the writer gives it');
check('references are found and normalised', Writer::references('As John 3:16 and 1 Corinthians 13:4-7 say, and Ps. 23:1') === ['john 3:16', '1corinthians 13:4', 'ps 23:1']);
check('times are not scripture', Writer::references('Service at 9:30 on Sunday') === []);
check('a draft is escaped HTML paragraphs', Writer::html("Dear <Ada>,\n\nLine one\nLine two") === '<p>Dear &lt;Ada&gt;,</p><p>Line one<br>Line two</p>');

section('Who may use AI');
check('never personal or pastoral mailboxes', !Ai::allowedFor(['kind' => 'personal', 'is_pastoral' => 0]) && !Ai::allowedFor(['kind' => 'church', 'is_pastoral' => 1])
    && Ai::allowedFor(['kind' => 'church', 'is_pastoral' => 0]) && Ai::allowedFor(['kind' => 'department', 'is_pastoral' => 0]));
check('first names for "Dear …"', Ai::firstName('ada OBI') === 'Ada' && Ai::firstName('A') === '' && Ai::firstName(null) === '' && Ai::firstName('"Ngozi" <n@x.org>') === 'Ngozi');
check('Gemini 2.5 Flash, 30 drafts and 30 summaries an hour', Ai::config('model') === 'gemini-2.5-flash' && Ai::config('limits.write') === 30 && Ai::config('limits.summary') === 30);

section('Teamwork words and choices');
check('three statuses, in English', MailTeam::STATUSES === ['open' => 'Open', 'waiting' => 'Waiting', 'done' => 'Done']);
check('push: all, assigned to me, off', array_keys(Prefs::PUSH) === ['all', 'mine', 'off']);
check('four key alerts a person may tick', array_keys(Prefs::PERSONAL) === ['personal_mail', 'assigned', 'summary', 'attention']);
check('presence lasts a minute, renewed every 20 seconds; 20 key alerts a day', MailTeam::config('presence_seconds') === 60 && MailTeam::config('poll_seconds') === 20 && MailTeam::config('personal_per_day') === 20);

section('Static rules');
$code = static fn (string $file): string => (string) file_get_contents("{$root}/{$file}");
foreach (['includes/mail/Ai/Writer.php', 'includes/mail/Ai/Summariser.php', 'includes/mail/Ai/Gemini.php', 'includes/mail/Ai/Ai.php'] as $file) {
    $src = $code($file);
    check(basename($file) . ': AI never sends', !preg_match('/MailTransport|OutboxSender|Outbox::make\(\)->queue|->queue\(|smtp/i', $src));
}
check('a draft only returns text for the composer', str_contains($code('includes/mail/Ai/Writer.php'), "return ['text' => \$text, 'html' => self::html(\$text)];"));
check('Gemini never logs the prompt or the answer', !preg_match('/error_log\([^;]*(\$prompt|\$system|\$text|\$request|\$body)/', $code('includes/mail/Ai/Gemini.php')));
check('the key comes from GEMINI_API_KEY, never a file', str_contains($code('includes/mail/Ai/Ai.php'), "site_env('GEMINI_API_KEY')") && !str_contains($code('includes/mail/Ai/ai_config.php'), 'GEMINI_API_KEY\''));
$api = $code('api/mail_team_api.php');
check('the team API checks CSRF on every POST', str_contains($api, 'csrf_require();'));
check('the composer asks before sending over a teammate\'s reply', str_contains($code('assets/ui/js/pages/mail-compose.js'), "action: 'guard'") && str_contains($code('assets/ui/js/pages/mail-compose.js'), "confirmLabel: 'Send anyway'"));
check('an AI draft enters the message only when the person chooses it', str_contains($code('assets/ui/js/pages/mail-compose.js'), 'aiText.textContent = r.data.text;')
    && str_contains($code('assets/ui/js/pages/mail-compose.js'), "case 'ai-use': aiApply(true)"));
foreach (['thread_team', 'thread_summary'] as $mount) {
    check("{$mount}: answers 404 by URL", str_contains($code("includes/mail/mounts/{$mount}.php"), "defined('HOD_MAIL_READ_PARTIAL')"));
}
class_exists(\HOD\Mail\Events::class);   // loaded, as when Events reads the listeners
$listener = require "{$root}/includes/mail/Listeners/team.php";
check('the listener file hands Events its callables', is_array($listener) && is_callable($listener['mail.received'] ?? null) && is_callable($listener['mail.threads_merged'] ?? null));
foreach (['mail-team.js', 'mail-settings.js'] as $js) {
    $src = $code("assets/ui/js/pages/{$js}");
    check("{$js}: requests go through HOD.api", str_contains($src, 'HOD.api(') && !str_contains($src, 'fetch('));
}
check('new mail is pushed, never a bell per email', str_contains($code('includes/mail/Team/NewMail.php'), "push_enqueue(\$pdo") && !str_contains($code('includes/mail/Team/NewMail.php'), 'MailNotices::send('));
check('key alerts: the alert lane, sender and subject only', str_contains($code('includes/mail/Team/PersonalAlerts.php'), "'lane' => 'alert'") && !preg_match('/body_text|body_html|snippet/', $code('includes/mail/Team/PersonalAlerts.php') . $code('includes/mail/Team/NewMail.php')));
foreach (array_merge(glob("{$root}/includes/mail/{Team,Ai}/*.php", GLOB_BRACE) ?: [], ["{$root}/api/mail_team_api.php", "{$root}/modules/mail/settings.php", "{$root}/includes/mail/mounts/thread_team.php", "{$root}/includes/mail/mounts/thread_summary.php"]) as $file) {
    check(basename($file) . ': English only', !preg_match('/\b(vous|courrier|bureau|Réessayez|Traitée|Ouverte|résumé|Praxis)\b/u', (string) file_get_contents($file)));
}

mail_test_done();
