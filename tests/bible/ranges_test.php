<?php
// /tests/bible/ranges_test.php — highlight arithmetic (bible_hl_plan) and
// selection clamping. These are the rules that make exact-word highlights
// behave like a highlighter pen.

$P = static fn(int $v, int $o): int => bible_pos($v, $o);

section('positions');
eq('order across verses', true, $P(3, 900) < $P(4, 0));
eq('split', [16, 42], bible_pos_split($P(16, 42)));

section('painting on empty');
$plan = bible_hl_plan([], $P(16, 4), $P(16, 20), 'yellow');
eq('one insert', [[$P(16, 4), $P(16, 20), 'yellow']], $plan['insert']);
eq('nothing else', [[], []], [$plan['delete'], $plan['update']]);

section('same colour merges');
$ex = [['id' => 1, 'a' => $P(16, 0), 'b' => $P(16, 10), 'color' => 'yellow']];
$plan = bible_hl_plan($ex, $P(16, 8), $P(16, 30), 'yellow');
eq('old deleted', [1], $plan['delete']);
eq('union inserted', [[$P(16, 0), $P(16, 30), 'yellow']], $plan['insert']);
$plan = bible_hl_plan($ex, $P(16, 10), $P(16, 15), 'yellow');
eq('touching ranges merge too', [[$P(16, 0), $P(16, 15), 'yellow']], $plan['insert']);

section('other colour is trimmed or split');
$ex = [['id' => 7, 'a' => $P(16, 0), 'b' => $P(17, 50), 'color' => 'green']];
$plan = bible_hl_plan($ex, $P(16, 40), $P(17, 5), 'pink');
eq('split keeps the left in place', [7 => [$P(16, 0), $P(16, 40)]], $plan['update']);
eq('and re-inserts the right in its colour, then the new one', [[$P(17, 5), $P(17, 50), 'green'], [$P(16, 40), $P(17, 5), 'pink']], $plan['insert']);
$plan = bible_hl_plan($ex, $P(15, 0), $P(16, 10), 'blue');
eq('trim the front', [7 => [$P(16, 10), $P(17, 50)]], $plan['update']);
$plan = bible_hl_plan($ex, $P(16, 0), $P(17, 50), 'blue');
eq('fully covered → deleted', [7], $plan['delete']);

section('erasing');
$ex = [
    ['id' => 1, 'a' => $P(1, 0), 'b' => $P(1, 30), 'color' => 'yellow'],
    ['id' => 2, 'a' => $P(2, 5), 'b' => $P(2, 9), 'color' => 'blue'],
];
$plan = bible_hl_plan($ex, $P(1, 10), $P(2, 6), null);
eq('erase trims one, trims the other', [1 => [$P(1, 0), $P(1, 10)], 2 => [$P(2, 6), $P(2, 9)]], $plan['update']);
eq('erase inserts nothing', [], $plan['insert']);
$plan = bible_hl_plan($ex, $P(1, 10), $P(1, 12), null);
eq('erase in the middle splits', [[$P(1, 12), $P(1, 30), 'yellow']], $plan['insert']);
eq('empty range is a no-op', ['delete' => [], 'update' => [], 'insert' => []], bible_hl_plan($ex, $P(3, 3), $P(3, 3), 'yellow'));

section('clamping a selection to the text');
$texts = [1 => 'In the beginning', 2 => 'And the earth'];
eq('offsets past the end are pulled in', [1, 3, 2, 13], bible_clamp_range($texts, 1, 3, 2, 999));
eq('empty selection rejected', null, bible_clamp_range($texts, 1, 5, 1, 5));
eq('backwards selection rejected', null, bible_clamp_range($texts, 2, 1, 1, 2));
eq('unknown verse rejected', null, bible_clamp_range($texts, 1, 0, 9, 3));
eq('without texts only bounds apply', [4, 0, 4, 12], bible_clamp_range([], 4, -3, 4, 12));

section('quotes');
$texts = [16 => 'For God so loved the world, that he gave', 17 => 'For God sent not his Son'];
eq('within a verse', 'God so loved', bible_cut_quote($texts, 16, 4, 16, 16));
eq('across verses', 'that he gave For God sent', bible_cut_quote($texts, 16, 28, 17, 12));
