<?php
// /tests/special_events/verses_accept_test.php — the pure half of accepting
// AI verse suggestions (guide §15.7).
//
// se_verses_clean_picks() is what the picker gains by being server-shaped:
// every pick is trimmed, every prayer line is held to the {name} rule, and a
// bad pick comes back with a reason instead of sinking the batch. No
// database is involved here; the lookup-and-insert half of
// se_verses_accept() needs a live PDO and is covered by the Studio flow.

echo "    shaping\n";

[$clean, $rejected] = se_verses_clean_picks([]);
is_same('nothing in, nothing out', [[], []], [$clean, $rejected]);

[$clean, $rejected] = se_verses_clean_picks([
    ['ref' => '  Psalms   16:11 ', 'prayer_template' => '{name}, may joy fill your night.'],
    ['ref' => '', 'prayer_template' => ''],
    ['ref' => 'John 3:16', 'prayer_template' => 'may you be blessed.'],
    'not-a-row',
]);

is_same('a good pick is trimmed, collapsed and kept', [
    ['ref' => 'Psalms 16:11', 'prayer_template' => '{name}, may joy fill your night.'],
], $clean);
is_same('every bad pick comes back with a reason', 3, count($rejected));
is_same('a blank reference keeps no ref to pin', '', $rejected[0]['ref']);
is_same('a prayer line without {name} rejects with the ref to fix', 'John 3:16', $rejected[1]['ref']);
ok('the reason explains the {name} rule', str_contains($rejected[1]['why'], '{name}'));

echo "    optional prayer line\n";

[$clean] = se_verses_clean_picks([['ref' => 'Gen 1:1']]);
is_same('no prayer line means NULL, not an empty string', null, $clean[0]['prayer_template']);

[$clean] = se_verses_clean_picks([['ref' => 'Gen 1:1', 'prayer_template' => '{name}, ' . str_repeat('a', 500)]]);
is_same('an overlong prayer line still passes when it carries {name}', 1, count($clean));
ok('and it is cut to the 300-character field', mb_strlen((string) $clean[0]['prayer_template']) <= 300);

echo "    caps\n";

$many = [];
for ($i = 1; $i <= 30; $i++) {
    $many[] = ['ref' => 'Psalms ' . $i . ':1'];
}
[$clean, $rejected] = se_verses_clean_picks($many);
is_same('a batch is capped at 24 picks', 24, count($clean));
is_same('the overflow is a no-op, not an error', 0, count($rejected));
