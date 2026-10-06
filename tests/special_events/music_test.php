<?php
// /tests/special_events/music_test.php — the portal music playlist
// (guide §13.3 S0b, §22.1).
//
// The rule worth protecting with tests is not the playlist, it is the gate:
// a track with no rights attestation must never reach a public page, no
// matter how it got into the table. se_music_playable() is where that is
// decided on the way OUT, so it is tested here against every way a row can
// be wrong.

/** A playlist row as se_music_list() returns it. */
function music_row(array $overrides = []): array
{
    return array_merge([
        'id'               => 1,
        'event_id'         => 7,
        'asset_id'         => 11,
        'title'            => 'Golden Hour',
        'artist'           => 'The Lekki Collective',
        'bpm'              => 92,
        'sort_order'       => 10,
        'is_active'        => 1,
        'rights_confirmed' => 1,
        'path'             => '/uploads/se/abc123/music/golden.mp3',
        'mime'             => 'audio/mpeg',
        'bytes'            => 2_100_000,
        'asset_title'      => 'golden.mp3',
    ], $overrides);
}

echo "    the rights gate\n";

is_same('a confirmed, active track with a file plays', 1,
    count(se_music_playable([music_row()])));

is_same('an unconfirmed track never plays', 0,
    count(se_music_playable([music_row(['rights_confirmed' => 0])])));

is_same('a track taken out of rotation never plays', 0,
    count(se_music_playable([music_row(['is_active' => 0])])));

is_same('a row whose file has gone never plays', 0,
    count(se_music_playable([music_row(['path' => ''])])));

is_same('a row with no path key at all never plays', 0,
    count(se_music_playable([music_row(['path' => null])])));

// The columns arrive from PDO as strings when emulated prepares are on, so
// the gate must not be fooled by '0'.
is_same("the string '0' is still off", 0,
    count(se_music_playable([music_row(['rights_confirmed' => '0'])])));
is_same("the string '1' is still on", 1,
    count(se_music_playable([music_row(['rights_confirmed' => '1'])])));

is_same('a missing rights column fails closed', 0,
    count(se_music_playable([array_diff_key(music_row(), ['rights_confirmed' => null])])));

echo "    order and shape\n";

$mixed = [
    music_row(['id' => 1, 'title' => 'One']),
    music_row(['id' => 2, 'title' => 'Two', 'rights_confirmed' => 0]),
    music_row(['id' => 3, 'title' => 'Three']),
];
is_same('only the attested survive, in the order given',
    ['One', 'Three'],
    array_column(se_music_tracks($mixed), 'title'));

$track = se_music_tracks([music_row()])[0];
is_same('the browser is given the path as src', '/uploads/se/abc123/music/golden.mp3', $track['src']);
is_same('the title travels for the aria-label', 'Golden Hour', $track['title']);
is_same('the artist travels too', 'The Lekki Collective', $track['artist']);
is_same('bpm is an int, not a string', 92, $track['bpm']);
is_same('nothing else is exposed to the public page',
    ['src', 'title', 'artist', 'bpm'], array_keys($track));

ok('the asset id never reaches the browser', !array_key_exists('asset_id', $track));

echo "    titles fall back rather than render empty\n";

is_same('an untitled track borrows the file name', 'golden.mp3',
    se_music_tracks([music_row(['title' => ''])])[0]['title']);

is_same('with neither, it is still named something', 'Track',
    se_music_tracks([music_row(['title' => '', 'asset_title' => ''])])[0]['title']);

is_same('a null bpm stays null rather than becoming 0', null,
    se_music_tracks([music_row(['bpm' => null])])[0]['bpm']);

echo "    the caps and defaults the UI promises\n";

is_same('five tracks is the ceiling', 5, SE_MUSIC_MAX);
is_same('the house volume is 30%', 30, SE_MUSIC_DEFAULT_VOLUME);

ok('music is the role that demands an attestation',
    in_array('music', SE_ASSET_ROLES_NEED_RIGHTS, true));
ok('music is an audio role with a byte cap',
    (SE_ASSET_ROLES['music']['kind'] ?? '') === 'audio' && SE_ASSET_ROLES['music']['max_bytes'] > 0);

echo "    the settings the portal reads\n";

$defaults = se_settings_defaults();
is_same('music is on once a track exists', true, $defaults['portal']['music_enabled']);
is_same('and starts at the house volume', SE_MUSIC_DEFAULT_VOLUME, $defaults['portal']['music_volume']);
is_same('shuffle is off', false, $defaults['portal']['music_shuffle']);

// The volume reaches an <input type=range> and a gain node, so it is clamped
// on the way in rather than trusted.
$loud = se_settings_normalize(['portal' => ['music_volume' => 4000]]);
is_same('an absurd volume is clamped to 100', 100, $loud['portal']['music_volume']);

$quiet = se_settings_normalize(['portal' => ['music_volume' => -10]]);
is_same('a negative volume is clamped to the floor, not to silence',
    5, $quiet['portal']['music_volume']);

$text = se_settings_normalize(['portal' => ['music_volume' => 'loud please']]);
is_same('a non-numeric volume falls back to the default',
    SE_MUSIC_DEFAULT_VOLUME, $text['portal']['music_volume']);

echo "    the Studio tab is wired up\n";

ok('Music has a tab', isset(SE_STUDIO_TABS['music']));
is_same('gated on its own table', 'se_music', SE_STUDIO_TABS['music']['requires']);
ok('and the tab is shipped, not hidden behind a later PR',
    in_array('music', SE_STUDIO_TABS_READY, true));
