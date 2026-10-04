<?php
// /tests/special_events/karaoke_library_test.php — the song library's
// normalisation and import parsers (guide §10.8, §15.5, §22.1).
//
// The library is global and deduplicated by a normalised key, so the
// normaliser decides whether tonight's list has one "Way Maker" or four.
// It is also what makes "one person per song" enforceable at all: the
// uniqueness index is on the key, not on the typing.

echo "    normalising\n";

is_same('case and spacing collapse', se_song_norm('Way  Maker'), se_song_norm('way maker'));
is_same('punctuation is ignored', se_song_norm("Nothin' But The Blood"), se_song_norm('Nothin But The Blood'));
is_same('accents fold down', se_song_norm('Imelá'), se_song_norm('Imela'));
ok('different songs stay different', se_song_norm('Imela') !== se_song_norm('Amen'));

is_same('a leading "the" is dropped from an artist', se_song_norm('The Beatles', true), se_song_norm('Beatles', true));
ok('but not from a title, where it can be the whole point',
    se_song_norm('The Blessing') !== se_song_norm('Blessing'));

echo "    durations\n";

is_same('mm:ss parses', 312, se_song_duration_parse('5:12'));
is_same('h:mm:ss parses', 3912, se_song_duration_parse('1:05:12'));
is_same('a bare number is seconds', 240, se_song_duration_parse('240'));
is_same('nonsense is nothing', null, se_song_duration_parse('about five minutes'));
is_same('and nothing is nothing', null, se_song_duration_parse(''));
is_same('a duration reads back as mm:ss', '5:12', se_song_duration_label(312));
is_same('no duration has no label', null, se_song_duration_label(null));

echo "    pasted lists\n";

$songLines = se_songs_parse_text(
    "Imela — Nathaniel Bassey\n"
    . "Way Maker - Sinach 5:12\n"
    . "\n"
    . "3. Oceans by Hillsong United\n"
    . "Just A Title\n"
);

is_same('four usable lines out of five', 4, count($songLines));
is_same('an em dash splits title from artist', 'Imela', $songLines[0]['title']);
is_same('…and the artist comes through', 'Nathaniel Bassey', $songLines[0]['artist']);
is_same('a trailing time is read as a duration', 312, $songLines[1]['duration_sec']);
is_same('and is not left in the artist', 'Sinach', $songLines[1]['artist']);
is_same('a leading list number is dropped', 'Oceans', $songLines[2]['title']);
is_same('"by" works as a separator too', 'Hillsong United', $songLines[2]['artist']);
is_same('a line with no artist still gives a song', 'Just A Title', $songLines[3]['title']);
is_same('…with an empty artist rather than a guess', '', $songLines[3]['artist']);

echo "    CSV\n";

$csv = se_songs_parse_csv(
    "title,artist,duration\n"
    . "Imela,Nathaniel Bassey,4:58\n"
    . "\"Way Maker, Miracle Worker\",Sinach,5:12\n"
);

is_same('the header row is not a song', 2, count($csv));
is_same('a quoted comma stays inside the title', 'Way Maker, Miracle Worker', $csv[1]['title']);
is_same('the duration column is parsed', 298, $csv[0]['duration_sec']);

$headerless = se_songs_parse_csv("Imela,Nathaniel Bassey\nAmen,Sinach\n");
is_same('a CSV with no header is still read', 2, count($headerless));
is_same('and the first line is not eaten', 'Imela', $headerless[0]['title']);
