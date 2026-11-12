<?php
// /tests/integrations/run.php
/**
 * Integrations (docs/INTEGRATIONS.md): the vault, the settings table, the Google client against a fake Google
 * (OAuth with state and PKCE, the token cache, invalid_grant → revoked, Calendar with a Meet link, Meet participants),
 * the attendance matcher, and static rules (CSRF, permissions, no token in a log, clean links). No network.
 *
 *   php tests/integrations/run.php        (needs pdo_sqlite)
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/integrations/bootstrap.php';
require_once $root . '/includes/training_live/bootstrap.php';

use HOD\Integrations\Google\Google;
use HOD\Integrations\Google\GoogleError;
use HOD\Integrations\Settings;
use HOD\Integrations\Vault;
use HOD\TrainingLive\Matcher;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "\n        " . $detail) . "\n";
}
function section(string $title): void
{
    echo "\n-- {$title}\n";
}
function throws(callable $fn, string $reason): bool
{
    try {
        $fn();
    } catch (GoogleError $e) {
        return $e->reason === $reason;
    }

    return false;
}
if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(getenv('CI') ? 1 : 0);
}

$key1 = str_repeat('ab', 32);
$key2 = str_repeat('cd', 32);

section('The vault');
Vault::useKeys(null);
$_ENV['INTEGRATIONS_VAULT_KEY'] = '';
check('no key: not ready, and nothing is stored in the clear', !Vault::ready() && (function () {
    try {
        Vault::encrypt('x', 'google.refresh_token');
    } catch (RuntimeException) {
        return true;
    }
    return false;
})());
Vault::useKeys($key1);
$box = Vault::encrypt('1//refresh-secret', 'google.refresh_token');
check('a secret goes in as v1:<key id>:<base64>, never readable', str_starts_with($box, 'v1:') && !str_contains($box, 'refresh-secret'));
check('…and comes back', Vault::decrypt($box, 'google.refresh_token') === '1//refresh-secret');
check('bound to its setting: copied onto another, it does not open', Vault::decrypt($box, 'google.access_token') === null);
check('damaged or empty values read as null', Vault::decrypt('v1:xx:!!', 'google.refresh_token') === null && Vault::decrypt(null, 'k') === null);
Vault::useKeys($key2, $key1);
check('after a key change the previous key still reads', Vault::decrypt($box, 'google.refresh_token') === '1//refresh-secret');
Vault::useKeys($key2);
check('without the old key it reads as null (Connect again)', Vault::decrypt($box, 'google.refresh_token') === null);

section('Settings');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Settings::usePdo($pdo);
check('before the migration: not ready', !Settings::ready());
$sql = (string) file_get_contents($root . '/db/migrations/20261210090000_integrations_google.sql');
$pdo->exec(preg_replace('/\) ENGINE=.*;/', ');', (string) preg_replace('/^--.*$/m', '', $sql)));
Settings::usePdo($pdo);
check('after it: ready', Settings::ready());
Vault::useKeys($key1);
Settings::set('a', '1', 7);
Settings::set('a', '2', 8);
Settings::usePdo($pdo);
check('set, update, read back (a fresh request)', Settings::get('a') === '2' && Settings::changed('a')[1] === 8);
Settings::setSecret('google.refresh_token', 'tok', 3);
$raw = (string) $pdo->query("SELECT setting_value FROM integration_settings WHERE setting_key = 'google.refresh_token'")->fetchColumn();
check('a secret is stored encrypted and read decrypted', str_starts_with($raw, 'v1:') && !str_contains($raw, 'tok') && Settings::secret('google.refresh_token') === 'tok');
Settings::set('a', null);
Settings::usePdo($pdo);
check('null deletes', Settings::get('a') === null && Settings::changed('a') === null);
$pdo->exec('DELETE FROM integration_settings');
Settings::usePdo($pdo);

section('Google: setup and Connect');
$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_ENV['GOOGLE_OAUTH_REDIRECT_URI'] = '';
$_ENV['GOOGLE_OAUTH_CLIENT_ID'] = '';
$_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] = '';
check('no client in .env: not set up', Google::status()['state'] === 'no_client');
check('…and Connect refuses', throws(fn () => Google::authorizationUrl('s', 'c'), 'no_client'));
$_ENV['GOOGLE_OAUTH_CLIENT_ID'] = 'client-123.apps.googleusercontent.com';
$_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] = 'GOCSPX-test';
Vault::useKeys(null);
$_ENV['INTEGRATIONS_VAULT_KEY'] = '';
check('no vault key: not set up', Google::status()['state'] === 'no_key');
Vault::useKeys($key1);
check('client and key: not connected yet', Google::status()['state'] === 'not_connected');
check('the redirect URI is the registered one', Google::redirectUri() === 'https://hodlekki.org/integrations/google/callback');

[$verifier, $challenge] = Google::pkce();
check('PKCE: a 64-character verifier and its S256 challenge', strlen($verifier) === 64 && preg_match('/^[A-Za-z0-9_-]+$/', $verifier)
    && $challenge === rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
parse_str((string) parse_url(Google::authorizationUrl('state-1', $challenge), PHP_URL_QUERY), $q);
check('the consent link asks for exactly the four scopes', $q['scope'] === 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.readonly');
check('…offline, with consent (so a refresh token comes), state and S256', $q['access_type'] === 'offline' && $q['prompt'] === 'consent'
    && $q['state'] === 'state-1' && $q['code_challenge'] === $challenge && $q['code_challenge_method'] === 'S256'
    && $q['redirect_uri'] === 'https://hodlekki.org/integrations/google/callback' && $q['client_id'] === 'client-123.apps.googleusercontent.com');

$calls = [];
$replies = [];
Google::fake(function (array $r) use (&$calls, &$replies): array {
    $calls[] = $r;
    foreach ($replies as $i => [$match, $reply]) {
        if (str_contains($r['method'] . ' ' . $r['url'], $match)) {
            if (!empty($reply['once'])) {
                unset($replies[$i]);
            }
            return is_callable($reply['fn'] ?? null) ? ($reply['fn'])($r) : $reply;
        }
    }
    return ['status' => 500, 'body' => '{}'];
});
$idToken = 'x.' . rtrim(strtr(base64_encode(json_encode(['email' => 'PraxisJBS@gmail.com'])), '+/', '-_'), '=') . '.y';
$scopes = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.readonly';
$replies = [['POST https://oauth2.googleapis.com/token', ['status' => 200, 'once' => true, 'body' => json_encode(['access_token' => 'ya29.first', 'expires_in' => 3599,
    'scope' => $scopes, 'id_token' => $idToken])]]];
check('a code without a refresh token is refused', throws(fn () => Google::connect('code-0', $verifier), 'failed'));
$replies = [['POST https://oauth2.googleapis.com/token', ['status' => 200, 'body' => json_encode(['access_token' => 'ya29.first', 'expires_in' => 3599,
    'refresh_token' => '1//refresh', 'scope' => $scopes, 'id_token' => $idToken])]]];
$calls = [];
$email = Google::connect('code-1', $verifier, 5);
parse_str((string) $calls[0]['body'], $form);
check('Connect sends the code with the PKCE verifier and the redirect URI', $form['code'] === 'code-1' && $form['code_verifier'] === $verifier
    && $form['grant_type'] === 'authorization_code' && $form['redirect_uri'] === 'https://hodlekki.org/integrations/google/callback');
check('…and keeps the account\'s email (lower case)', $email === 'praxisjbs@gmail.com' && Google::status()['email'] === 'praxisjbs@gmail.com');
$raw = (string) $pdo->query("SELECT setting_value FROM integration_settings WHERE setting_key = 'google.refresh_token'")->fetchColumn();
check('the refresh token is stored encrypted', str_starts_with($raw, 'v1:') && !str_contains($raw, 'refresh'));
$st = Google::status();
check('connected, by whom, every permission given', $st['state'] === 'connected' && $st['connected_by'] === 5 && $st['missing_scopes'] === []);

section('Google: calls, the token cache, revoked');
$calls = [];
$replies = [['GET https://www.googleapis.com/calendar/v3/calendars/primary/events', ['status' => 200, 'body' => '{"items":[]}']]];
Google::test();
check('a call uses the cached access token (no token request)', count($calls) === 1 && in_array('Authorization: Bearer ya29.first', $calls[0]['headers'], true));
Settings::setSecret(Google::ACCESS, json_encode(['t' => 'ya29.old', 'e' => time() - 5]));
$calls = [];
$replies = [
    ['POST https://oauth2.googleapis.com/token', ['status' => 200, 'body' => json_encode(['access_token' => 'ya29.second', 'expires_in' => 3599])]],
    ['GET https://www.googleapis.com/calendar/v3', ['status' => 200, 'body' => '{}']],
];
Google::test();
parse_str((string) $calls[0]['body'], $form);
check('an expired token is refreshed first, with the refresh token', $form['grant_type'] === 'refresh_token' && $form['refresh_token'] === '1//refresh'
    && in_array('Authorization: Bearer ya29.second', $calls[1]['headers'], true));
$calls = [];
$replies = [
    ['GET https://www.googleapis.com/calendar/v3', ['status' => 401, 'once' => true, 'body' => '{}']],
    ['POST https://oauth2.googleapis.com/token', ['status' => 200, 'body' => json_encode(['access_token' => 'ya29.third', 'expires_in' => 3599])]],
    ['GET https://www.googleapis.com/calendar/v3', ['status' => 200, 'body' => '{}']],
];
Google::test();
check('a 401 refreshes once and tries again', count($calls) === 3 && in_array('Authorization: Bearer ya29.third', $calls[2]['headers'], true));
$replies = [['GET https://www.googleapis.com/calendar/v3', ['status' => 429, 'body' => '{"error":{"status":"RESOURCE_EXHAUSTED"}}']]];
check('429: quota, and Health is told', throws(fn () => Google::test(), 'quota') && str_starts_with((string) Settings::get(Google::LAST_ERROR), 'quota '));
$replies = [['GET https://www.googleapis.com/calendar/v3', ['status' => 403, 'body' => '{"error":{"errors":[{"reason":"insufficientPermissions"}]}}']]];
check('403 insufficientPermissions: scopes', throws(fn () => Google::test(), 'scopes'));
$replies = [['GET https://www.googleapis.com/calendar/v3', ['status' => 200, 'body' => '{}']]];
Google::test();
check('a call that works clears the last error', Settings::get(Google::LAST_ERROR) === null && Settings::get(Google::LAST_OK) !== null);

section('Google: a Meet link for a live class');
$calls = [];
$replies = [
    ['POST https://www.googleapis.com/calendar/v3/calendars/primary/events', ['status' => 200, 'body' => json_encode(['id' => 'ev1',
        'conferenceData' => ['createRequest' => ['status' => ['statusCode' => 'pending']]]])]],
    ['GET https://www.googleapis.com/calendar/v3/calendars/primary/events/ev1', ['status' => 200, 'body' => json_encode(['id' => 'ev1',
        'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'htmlLink' => 'https://calendar.google.com/e?eid=1',
        'conferenceData' => ['conferenceId' => 'abc-defg-hij', 'createRequest' => ['status' => ['statusCode' => 'success']]]])]],
];
$ev = Google::createEvent(['title' => 'Membership Class: Day 1', 'starts_at' => '2026-12-05 10:00', 'ends_at' => '2026-12-05 12:00']);
$sent = json_decode((string) $calls[0]['body'], true);
check('events.insert with conferenceDataVersion=1 and nobody notified', str_contains($calls[0]['url'], 'conferenceDataVersion=1') && str_contains($calls[0]['url'], 'sendUpdates=none'));
check('…a hangoutsMeet create request with a fresh request id', $sent['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet'
    && strlen($sent['conferenceData']['createRequest']['requestId']) === 32);
check('…in Lagos time, no guests', $sent['start'] === ['dateTime' => '2026-12-05T10:00:00+01:00', 'timeZone' => 'Africa/Lagos']
    && $sent['end']['dateTime'] === '2026-12-05T12:00:00+01:00' && !isset($sent['attendees']));
check('a link still pending is read again', $ev === ['event_id' => 'ev1', 'meet_url' => 'https://meet.google.com/abc-defg-hij',
    'meet_code' => 'abc-defg-hij', 'html_link' => 'https://calendar.google.com/e?eid=1']);
$calls = [];
$replies = [['PATCH https://www.googleapis.com/calendar/v3/calendars/primary/events/ev1', ['status' => 200, 'body' => json_encode(['id' => 'ev1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])]]];
$up = Google::updateEvent('ev1', ['title' => 'Day 1 (moved)', 'starts_at' => new DateTimeImmutable('2026-12-05 09:00:00', new DateTimeZone('UTC')), 'ends_at' => '2026-12-05 12:00']);
$sent = json_decode((string) $calls[0]['body'], true);
check('a change patches the event; the link stays; UTC times arrive in Lagos', $up['meet_url'] === 'https://meet.google.com/abc-defg-hij' && $sent['summary'] === 'Day 1 (moved)'
    && $sent['start']['dateTime'] === '2026-12-05T10:00:00+01:00');
$replies = [['DELETE https://www.googleapis.com/calendar/v3/calendars/primary/events/gone', ['status' => 410, 'body' => '{}']]];
Google::cancelEvent('gone');
check('cancelling an event already gone is fine', true);

section('Google: Meet participants after the class');
$calls = [];
$replies = [
    ['GET https://meet.googleapis.com/v2/conferenceRecords?', ['status' => 200, 'body' => json_encode(['conferenceRecords' => [
        ['name' => 'conferenceRecords/r2', 'startTime' => '2026-12-05T09:40:00Z'], ['name' => 'conferenceRecords/r1', 'startTime' => '2026-12-05T09:01:00Z']]])]],
    ['GET https://meet.googleapis.com/v2/conferenceRecords/r1/participants?', ['status' => 200, 'body' => json_encode(['participants' => [
        ['name' => 'conferenceRecords/r1/participants/p1', 'signedinUser' => ['user' => 'users/1', 'displayName' => 'Ada Obi']]], 'nextPageToken' => 'n2'])]],
    ['GET https://meet.googleapis.com/v2/conferenceRecords/r1/participants/p1/participantSessions', ['status' => 200, 'body' => json_encode(['participantSessions' => [
        ['startTime' => '2026-12-05T09:02:00Z', 'endTime' => '2026-12-05T09:30:00Z']]])]],
];
$records = Google::conferenceRecords('ABC-DEFG-HIJ', new DateTimeImmutable('2026-12-05 09:00', new DateTimeZone('Africa/Lagos')), new DateTimeImmutable('2026-12-05 14:00', new DateTimeZone('Africa/Lagos')));
parse_str((string) parse_url($calls[0]['url'], PHP_URL_QUERY), $q);
check('conferenceRecords filtered by the Meet code and the times (UTC)', $q['filter'] === 'space.meeting_code = "abc-defg-hij" AND start_time >= "2026-12-05T08:00:00Z" AND start_time <= "2026-12-05T13:00:00Z"');
check('…oldest first', array_column($records, 'name') === ['conferenceRecords/r1', 'conferenceRecords/r2']);
$replies[] = ['GET https://meet.googleapis.com/v2/conferenceRecords/r1/participants?pageSize=100&pageToken=n2', ['status' => 200, 'body' => json_encode(['participants' => [
    ['name' => 'conferenceRecords/r1/participants/p2', 'anonymousUser' => ['displayName' => 'Guest'], 'earliestStartTime' => '2026-12-05T09:05:00Z', 'latestEndTime' => '2026-12-05T09:06:00Z']]])]];
array_unshift($replies, $replies[3]);
$replies[] = ['GET https://meet.googleapis.com/v2/conferenceRecords/r1/participants/p2/participantSessions', ['status' => 200, 'body' => '{}']];
$people = Google::participants('conferenceRecords/r1');
check('participants across pages, each with their stretches (or first/last seen)', $people === [
    ['id' => 'conferenceRecords/r1/participants/p1', 'display_name' => 'Ada Obi', 'kind' => 'signed_in', 'sessions' => [['2026-12-05T09:02:00Z', '2026-12-05T09:30:00Z']]],
    ['id' => 'conferenceRecords/r1/participants/p2', 'display_name' => 'Guest', 'kind' => 'anonymous', 'sessions' => [['2026-12-05T09:05:00Z', '2026-12-05T09:06:00Z']]],
], json_encode($people));

section('Google: invalid_grant means revoked; Disconnect forgets');
Settings::setSecret(Google::ACCESS, json_encode(['t' => 'ya29.old', 'e' => time() - 5]));
$replies = [['POST https://oauth2.googleapis.com/token', ['status' => 400, 'body' => '{"error":"invalid_grant"}']]];
check('Google withdrew access: revoked', throws(fn () => Google::test(), 'revoked') && Google::status()['state'] === 'revoked');
$calls = [];
check('…and nothing more is sent until someone connects again', throws(fn () => Google::test(), 'revoked') && $calls === []);
$replies = [['POST https://oauth2.googleapis.com/revoke', ['status' => 200, 'body' => '{}']]];
Google::disconnect();
parse_str((string) $calls[0]['body'], $form);
check('Disconnect revokes the refresh token at Google', $calls[0]['url'] === 'https://oauth2.googleapis.com/revoke' && $form['token'] === '1//refresh');
check('…and forgets the connection (the client in .env stays)', (int) $pdo->query("SELECT COUNT(*) FROM integration_settings WHERE setting_key LIKE 'google.%'")->fetchColumn() === 0
    && Google::status()['state'] === 'not_connected');
$replies = [['POST https://oauth2.googleapis.com/token', ['status' => 401, 'body' => '{"error":"invalid_client"}']]];
check('a wrong client secret: client', throws(fn () => Google::connect('c', $verifier), 'client'));
check('not connected: calls refuse without the network', throws(fn () => Google::test(), 'not_connected'));
Google::fake(null);

section('Attendance: who was in the class');
$end = strtotime('2026-12-05T11:00:00Z');
$learners = [['user_id' => 1, 'name' => 'Adaeze Obi'], ['user_id' => 2, 'name' => 'Chukwuemeka Okafor'], ['user_id' => 3, 'name' => 'Grace Bello'],
    ['user_id' => 4, 'name' => 'Grace Bello'], ['user_id' => 5, 'name' => 'Tunde Bakare'], ['user_id' => 6, 'name' => 'Ngozi Eze']];
$s = fn (string $a, ?string $b) => [$a, $b];
$participants = [
    ['id' => 'p1', 'display_name' => 'Sis. ADAEZE OBI', 'sessions' => [$s('2026-12-05T09:00:00Z', '2026-12-05T09:40:00Z')]],
    ['id' => 'p1b', 'display_name' => 'Adaeze Obi', 'sessions' => [$s('2026-12-05T09:30:00Z', '2026-12-05T09:50:00Z')]],
    ['id' => 'p2', 'display_name' => 'Okafor Chukwuemeka', 'sessions' => [$s('2026-12-05T09:00:00Z', '2026-12-05T09:20:00Z')]],
    ['id' => 'p3', 'display_name' => 'Grace Bello', 'sessions' => [$s('2026-12-05T08:58:00Z', null)]],
    ['id' => 'p4', 'display_name' => 'iPhone (7)', 'sessions' => [$s('2026-12-05T09:03:00Z', '2026-12-05T10:30:00Z')]],
    ['id' => 'p5', 'display_name' => 'T. Bakare', 'sessions' => [$s('2026-12-05T09:15:00Z', '2026-12-05T09:16:00Z')]],
];
$joins = [['user_id' => 6, 'at' => strtotime('2026-12-05T09:02:00Z')], ['user_id' => 5, 'at' => strtotime('2026-12-05T08:00:00Z')]];
$r = Matcher::match($participants, $learners, $joins, $end, 30);
check('titles, case and accents ignored: a sure name match', ($r['matched'][1]['source'] ?? '') === 'meet' && $r['matched'][1]['participants'] === ['p1', 'p1b']);
check('two devices: overlapping time counted once (09:00-09:50 = 50 min), present', $r['matched'][1]['minutes'] === 50 && $r['matched'][1]['present']);
check('word order does not matter', isset($r['matched'][2]) && $r['matched'][2]['minutes'] === 20 && !$r['matched'][2]['present']);
check('two learners with the same name are not guessed between', !isset($r['matched'][3]) && !isset($r['matched'][4]));
check('the Join log: the only one who pressed Join just before "iPhone" entered', ($r['matched'][6]['source'] ?? '') === 'join' && $r['matched'][6]['minutes'] === 87);
check('a still-open stretch counts until the class ended', in_array('Grace Bello', array_column($r['review'], 'display_name'), true)
    && $r['review'][0]['display_name'] === 'Grace Bello' && $r['review'][0]['minutes'] === 122);
$grace = $r['review'][0]['suggestions'];
check('Needs a look suggests both Graces', array_column($grace, 'user_id') === [3, 4]);
$bakare = array_values(array_filter($r['review'], fn ($x) => $x['display_name'] === 'T. Bakare'))[0] ?? null;
check('"T. Bakare" is suggested, not decided (Tunde pressed Join an hour earlier)', $bakare !== null && ($bakare['suggestions'][0]['user_id'] ?? 0) === 5 && $bakare['suggestions'][0]['joined']);
check('pressed Join but not found in the call: listed', $r['joined_unmatched'] === [5]);
$two = Matcher::match([['id' => 'x', 'display_name' => 'Phone', 'sessions' => [$s('2026-12-05T09:03:00Z', '2026-12-05T09:50:00Z')]],
    ['id' => 'y', 'display_name' => 'Tablet', 'sessions' => [$s('2026-12-05T09:04:00Z', '2026-12-05T09:50:00Z')]]],
    $learners, [['user_id' => 6, 'at' => strtotime('2026-12-05T09:02:00Z')]], $end);
check('two unknown devices in one learner\'s Join window: neither is guessed', $two['matched'] === [] && count($two['review']) === 2);
$joinedName = Matcher::match([['id' => 'z', 'display_name' => 'Ngozi Ezeh', 'sessions' => [$s('2026-12-05T10:00:00Z', '2026-12-05T10:40:00Z')]]],
    $learners, [['user_id' => 6, 'at' => strtotime('2026-12-05T09:00:00Z')]], $end);
check('a learner who pressed Join and matches the name at 80%+: counted', ($joinedName['matched'][6]['source'] ?? '') === 'join' && $joinedName['matched'][6]['present']);
check('normalising: titles, accents, brackets and punctuation', Matcher::normalise('Pst. (Dr) Émeka  O\'Neil-Ọkafor') === 'emeka o neil okafor');
check('the present threshold is per class', Matcher::match([$participants[2]], $learners, [], $end, 15)['matched'][2]['present']);

section('Static rules');
$read = fn (string $f): string => (string) file_get_contents("{$root}/{$f}");
check('Connect: POST only, CSRF, manage permission', str_contains($read('integrations/google/connect.php'), 'csrf_require();')
    && str_contains($read('integrations/google/connect.php'), "can('integrations', 'manage')") && str_contains($read('integrations/google/connect.php'), "!== 'POST'"));
$cb = $read('integrations/google/callback.php');
check('the callback: state from this session (hash_equals, 15 minutes), used once, PKCE', str_contains($cb, "hash_equals(\$flow['state'], \$state)")
    && str_contains($cb, "unset(\$_SESSION['google_oauth'])") && str_contains($cb, "\$flow['verifier']") && str_contains($cb, '> 900'));
check('the callback never echoes text from Google into the page', !preg_match('/\$_GET\[[\'"]error_description/', $cb) && !str_contains($cb, 'echo'));
$api = $read('api/integrations_api.php');
check('the API: CSRF and manage permission', str_contains($api, 'csrf_require();') && str_contains($api, "can('integrations', 'manage')"));
check('the page: manage permission', str_contains($read('modules/integrations/index.php'), "can('integrations', 'manage')"));
$g = $read('includes/integrations/Google/Google.php');
check('no token, code or secret is ever logged', !preg_match('/error_log\([^;]*(\$token|\$refresh|\$access|\$code|\$params|\$body|\$data|secret)/i', $g));
check('Google certificates are checked, no redirects followed', str_contains($g, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($g, 'CURLOPT_FOLLOWLOCATION => false'));
check('no transcripts, no Drive, no Docs (the four scopes only)', !preg_match('/transcript|drive|documents/i', implode(' ', Google::SCOPES)));
$modules = require "{$root}/includes/permissions/modules.php";
check('the permission row: Integrations, manage only', ($modules['integrations']['extras'] ?? null) === ['manage'] && $modules['integrations']['max'] === 'none');
check('the links are clean (no .php)', !preg_match('#[\'"]/(modules/)?integrations/[^\'"\s]*\.php#', $read('modules/integrations/index.php') . $g . $cb . $read('integrations/google/connect.php'))
    && str_contains($read('modules/integrations/index.php'), 'action="/integrations/google/connect"'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
