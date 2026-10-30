<?php
// /tests/special_events/checkin_handoff_test.php — the pieces of the check-in
// that send a guest elsewhere or let a volunteer stand in for them:
// the external karaoke link, the welcome card's signature and the
// volunteer link's key.

require_once __DIR__ . '/../../includes/special_events/events.php';

echo "    the karaoke link on another platform\n";
is_same('no link set, no button', null, se_karaoke_external_url([]));
is_same('an https link is used as it is', 'https://karena.live/v/hod-lekki',
    se_karaoke_external_url(['karaoke' => ['external_url' => ' https://karena.live/v/hod-lekki ']]));
is_same('plain http is refused (it would be a mixed-content warning on the phone)', null,
    se_karaoke_external_url(['karaoke' => ['external_url' => 'http://karena.live/v/hod-lekki']]));
is_same('a javascript: link can never become a button', null,
    se_karaoke_external_url(['karaoke' => ['external_url' => 'javascript:alert(1)']]));
is_same('nor can text with quotes in it', null,
    se_karaoke_external_url(['karaoke' => ['external_url' => 'https://x.test/"><script>']]));

echo "    the welcome card's signature\n";
$event = ['title' => 'Chara', 'edition_label' => '2026', 'organizer_label' => 'Envision', 'settings_json' => null];
is_same('by default it is the event and the organiser', 'Chara by Envision', se_welcome_signature($event));
$event['settings_json'] = se_json_encode(['checkin' => ['welcome_card' => ['signature' => '{title} {edition} — {organizer}']]]);
is_same('the Studio can change the wording', 'Chara 2026 — Envision', se_welcome_signature($event));
$event['edition_label'] = '';
is_same('an empty edition leaves no double space', 'Chara — Envision', se_welcome_signature($event));

echo "    the volunteer link's key\n";
$state = ['lobby_key' => 'lobbyKEY1234567890abcd'];
$key = se_assist_key($state);
ok('it is URL-safe and fits the 32-character key check', (bool) preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key), $key);
is_same('it is stable for the same lobby key', $key, se_assist_key($state));
ok('rotating the lobby key retires it', $key !== se_assist_key(['lobby_key' => 'anotherKEY234567890abc']));
ok('it is not the lobby key itself', $key !== $state['lobby_key']);
