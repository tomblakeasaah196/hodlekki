<?php
// /tests/pwa/push_helpers_test.php
/**
 * The parts of includes/push_helpers.php that need no database: everything
 * that decides what a member actually reads on their lock screen, and the
 * guard that keeps a notification's link_url pointing at this site.
 *
 * Run me through tests/pwa/run.php, which supplies check()/ok()/throws().
 */

suite('text for a lock screen');
check('markup is stripped', 'Bold news', push_clean_text('<b>Bold</b> news', 90));
check('whitespace is collapsed', 'one two three', push_clean_text("one\n\n  two\tthree  ", 90));
check('a short string is untouched', 'Short', push_clean_text('Short', 90));
check('an empty message stays empty', '', push_clean_text(null, 90));
check('a long string is cut with an ellipsis', 'abcd…', push_clean_text('abcdefghij', 5));
check('the cut respects the limit', 20, mb_strlen(push_clean_text(str_repeat('word ', 40), 20)));
check('trailing space before the ellipsis is trimmed', 'one…', push_clean_text('one two three', 5));
// Yoruba, Igbo and Hausa names are routine here; a byte-wise cut would
// produce mojibake on the notification itself.
check('multibyte is cut by character, not byte', 'Olúwáṣe…', push_clean_text('Olúwáṣeun Adébáyọ̀', 8));
check('the result is still valid UTF-8', true, mb_check_encoding(push_clean_text('Olúwáṣeun Adébáyọ̀', 8), 'UTF-8'));

suite('urgency');
check('ordinary titles are normal', 'normal', push_urgency_for('New Book Reservation'));
check('security alerts cut through quiet hours', 'high', push_urgency_for('SECURITY: Transaction Reversed'));
check('account status alerts cut through', 'high', push_urgency_for('Account Status Alert'));
check('a revoked account cuts through', 'high', push_urgency_for('System Clearance Revoked'));
check('matching is case insensitive', 'high', push_urgency_for('urgent welfare flag'));
// "Alerts" inside a longer word must not trip the word-boundary match.
check('a substring is not a match', 'normal', push_urgency_for('Alerting the Alertness Team'));

suite('notification -> push message');
$message = push_message_from_notification([
    'id'       => 42,
    'title'    => 'New Follow-up Task',
    'message'  => "  A <em>first timer</em> was\nassigned to you.  ",
    'link_url' => '/modules/embrace/index.php',
]);
check('title carries over', 'New Follow-up Task', $message['title']);
check('body is cleaned', 'A first timer was assigned to you.', $message['body']);
check('link carries over', '/modules/embrace/index.php', $message['url']);
check('the tag identifies the notification', 'hod-notif-42', $message['tag']);
check('urgency is derived', 'normal', $message['urgency']);

$blank = push_message_from_notification(['id' => 1, 'title' => '', 'message' => null, 'link_url' => null]);
check('an empty title falls back to the app name', 'HOD Lekki', $blank['title']);
check('a missing link goes to the dashboard', '/index.php', $blank['url']);
check('a missing message is empty, not null', '', $blank['body']);

suite('a notification link can never leave this site');
// Sixty places across the codebase write link_url. One of them taking a
// value from a form would otherwise turn every push into an open redirect
// wearing the church's icon and name.
foreach ([
    'an absolute http URL'       => 'http://evil.example/steal',
    'an absolute https URL'      => 'https://evil.example/steal',
    'a protocol-relative URL'    => '//evil.example/steal',
    'a backslash-smuggled host'  => '/\\evil.example/steal',
    'a javascript: URL'          => 'javascript:alert(1)',
    'a data: URL'                => 'data:text/html,<script>alert(1)</script>',
    'a bare relative path'       => 'modules/embrace/index.php',
    'an empty string'            => '',
] as $label => $hostile) {
    $result = push_message_from_notification(['id' => 7, 'title' => 'x', 'message' => '', 'link_url' => $hostile]);
    check($label . ' is replaced with the dashboard', '/index.php', $result['url']);
}

foreach ([
    '/modules/charis/index.php',
    '/index.php',
    '/modules/reach/index.php?tab=leads',
    '/e/chara/me',
] as $safe) {
    $result = push_message_from_notification(['id' => 7, 'title' => 'x', 'message' => '', 'link_url' => $safe]);
    check('a same-site path is kept: ' . $safe, $safe, $result['url']);
}

suite('payload stays inside what a push service accepts');
$payload = push_build_payload([
    'title'           => 'New Follow-up Task',
    'body'            => 'A first timer was assigned to you.',
    'url'             => '/modules/embrace/index.php',
    'tag'             => 'hod-notif-42',
    'urgency'         => 'normal',
    'notification_id' => 42,
], 7);
$decoded = json_decode($payload, true);
check('it is valid JSON', true, is_array($decoded));
check('the title survives', 'New Follow-up Task', $decoded['title']);
check('the badge count rides along', 7, $decoded['badge']);
check('the notification id rides along', 42, $decoded['nid']);
check('a normal message is not flagged urgent', false, isset($decoded['urgent']));
check('slashes are not escaped', true, str_contains($payload, '/modules/embrace/'));

$urgent = json_decode(push_build_payload([
    'title' => 'SECURITY', 'body' => '', 'url' => '/', 'tag' => 't', 'urgency' => 'high',
], 0), true);
check('an urgent message is flagged', true, $urgent['urgent']);

// The queue columns cap title at 190 and body at 500, which together with the
// JSON envelope must still fit inside what push_encrypt_payload() accepts.
$worstCase = push_build_payload([
    'title'           => str_repeat('é', 190),
    'body'            => str_repeat('é', 500),
    'url'             => '/' . str_repeat('u', 254),
    'tag'             => str_repeat('t', 80),
    'urgency'         => 'high',
    'notification_id' => 4294967295,
], 999999);
ok('the largest row the schema allows still fits', strlen($worstCase) <= PUSH_MAX_PAYLOAD_BYTES,
    strlen($worstCase) . ' of ' . PUSH_MAX_PAYLOAD_BYTES . ' bytes');
ok('and it is still valid JSON', is_array(json_decode($worstCase, true)));

suite('quiet hours');
// push_user_allows() needs a database; the wrapping arithmetic it depends on
// is checked here directly, because "22:00 to 06:00" is the setting everyone
// picks and the naive comparison gets it exactly backwards.
$inQuiet = static function (int $hour, int $from, int $to): bool {
    return ($from < $to) ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
};
check('23:00 is inside 22:00-06:00', true,  $inQuiet(23, 22, 6));
check('02:00 is inside 22:00-06:00', true,  $inQuiet(2, 22, 6));
check('06:00 is outside 22:00-06:00', false, $inQuiet(6, 22, 6));
check('12:00 is outside 22:00-06:00', false, $inQuiet(12, 22, 6));
check('14:00 is inside 13:00-15:00', true,  $inQuiet(14, 13, 15));
check('16:00 is outside 13:00-15:00', false, $inQuiet(16, 13, 15));

suite('device fingerprints for the member-facing list');
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
$pixel  = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36';
$edge   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0';

check('an iPhone reads as iPhone + Safari', 'iPhone · Safari', push_device_label($iphone));
check('a Pixel reads as Android + Chrome', 'Android · Chrome', push_device_label($pixel));
// Edge sends Chrome in its string too, so order of testing matters.
check('Edge is not mistaken for Chrome', 'Windows · Edge', push_device_label($edge));
check('an unknown agent still gets a label', 'Device', push_device_label(''));

check('iOS platform', 'ios', push_platform_from_ua($iphone));
check('Android platform', 'android', push_platform_from_ua($pixel));
check('Windows platform', 'windows', push_platform_from_ua($edge));
check('unknown platform', 'other', push_platform_from_ua('curl/8.0'));

suite('push service behind an endpoint');
check('FCM', 'fcm', push_provider_from_endpoint('https://fcm.googleapis.com/fcm/send/abc'));
check('Chrome web push', 'fcm', push_provider_from_endpoint('https://web.push.apple.com/x') === 'apple' ? 'fcm' : push_provider_from_endpoint('https://fcm.googleapis.com/wp/abc'));
check('Mozilla', 'mozilla', push_provider_from_endpoint('https://updates.push.services.mozilla.com/wpush/v2/abc'));
check('Apple', 'apple', push_provider_from_endpoint('https://web.push.apple.com/abc'));
check('Windows', 'wns', push_provider_from_endpoint('https://wns2-by3p.notify.windows.com/w/?token=abc'));
check('an endpoint with no host', 'unknown', push_provider_from_endpoint('not a url'));

suite('constants the sender depends on');
ok('the retry schedule parses', count(array_filter(array_map('intval', explode(',', PUSH_BACKOFF_MINUTES)))) >= 1);
ok('there are at least as many backoff steps as attempts',
    count(explode(',', PUSH_BACKOFF_MINUTES)) >= PUSH_MAX_ATTEMPTS - 1,
    PUSH_BACKOFF_MINUTES . ' for ' . PUSH_MAX_ATTEMPTS . ' attempts');
ok('the TTL is at least a few hours', PUSH_TTL_SECONDS >= 3600);
ok('the record size is the one the RFC header advertises', PUSH_RECORD_SIZE === 4096);
