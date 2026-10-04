<?php
// /tests/special_events/display_name_test.php — the only name shape that
// reaches a screen (guide D25, §22.1).

echo "    the Ada O. form\n";
is_same('first and last', 'Ada O.', se_display_name('Ada', 'Obi'));
is_same('first only', 'Ada', se_display_name('Ada', ''));
is_same('no name at all', 'Guest', se_display_name('', ''));
is_same('whitespace only', 'Guest', se_display_name('   ', '  '));

echo "    casing\n";
is_same('all caps are normalised', 'Ada O.', se_display_name('ADA', 'OBI'));
is_same('all lower are normalised', 'Ada O.', se_display_name('ada', 'obi'));
is_same('mixed case is respected', 'McBride O.', se_display_name('McBride', 'Obi'));
is_same("O'Neil keeps its capital", "O'Neil A.", se_display_name("o'neil", 'adams'));
is_same('a hyphenated first name', 'Odun-Ayo F.', se_display_name('odun-ayo', 'funmilola'));
is_same('a hyphenated surname uses its first letter', 'Tom S.', se_display_name('tom', 'smith-jones'));

echo "    unicode\n";
is_same('accents survive', 'Zoé D.', se_display_name('zoé', 'dupont'));
is_same('Yoruba dotted vowels survive', 'Ayọ̀ B.', se_display_name('Ayọ̀', 'Bello'));
is_same('a non-Latin script is kept', 'Даша И.', se_display_name('Даша', 'Иванова'));

echo "    cleaning\n";
is_same('digits are stripped', 'Ada O.', se_display_name('Ada123', 'Obi'));
// A name sanitiser keeps letters and drops everything else, so the letters
// inside a tag survive as plain text. What matters is that no markup can.
$injected = se_display_name('Ada<script>', 'Obi');
ok('no angle brackets survive', !preg_match('/[<>]/', $injected), $injected);
ok('the real name is still there', str_starts_with($injected, 'Ada'), $injected);
is_same('inner runs of space collapse', 'Ada O.', se_display_name('Ada    ', 'Obi'));
is_same('an all-symbol name falls back to Guest', 'Guest', se_display_name('@@@@', '###'));

echo "    length\n";
$long = se_display_name(str_repeat('Chidinmaobi', 12), 'Okeke');
ok('never exceeds the 40-character column', mb_strlen($long, 'UTF-8') <= 40, 'got ' . mb_strlen($long, 'UTF-8'));

echo "    se_clean_name\n";
is_same('a clean name passes through', 'Ada', se_clean_name('Ada'));
$cleaned = se_clean_name('<script>alert("x")</script>Ada');
ok('markup characters are all stripped', !preg_match('/[<>"()\/]/', $cleaned), $cleaned);
ok('letters are kept', str_contains($cleaned, 'Ada'), $cleaned);
ok('a javascript: URL cannot survive as a name',
    !str_contains(strtolower(se_clean_name('javascript:alert(1)')), ':'));
is_same('an 80-character cap', 80, mb_strlen(se_clean_name(str_repeat('a', 200)), 'UTF-8'));
is_same('a full stop is allowed inside', 'Jr. Paul', se_clean_name('Jr. Paul'));
