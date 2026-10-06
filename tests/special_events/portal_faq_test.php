<?php
// /tests/special_events/portal_faq_test.php — the portal page branch of the
// settings document and the "Good to know" editor behind Studio → Details
// (guide §13.2 S6, §20.3).
//
// The six questions in Appendix E are STARTER CONTENT, not fixed copy. These
// checks are what stops them silently becoming hard-coded again: an explicit
// list must replace them, an empty list must stay empty (no FAQ section on
// the page), and a half-filled row must come back as a field error instead of
// an empty accordion in front of a guest.

$defaults = se_settings_defaults();

echo "    the starter questions\n";
is_same('six ship with a new event', 6, count($defaults['portal']['faq']));
is_same('the reset value is exactly those six', $defaults['portal']['faq'], se_portal_faq_reset_value());
is_same('se_settings_default() reads the same path', $defaults['portal']['faq'], se_settings_default('portal.faq'));
ok('every starter question has an answer', array_reduce(
    $defaults['portal']['faq'],
    static fn(bool $carry, array $row): bool => $carry && trim($row['q']) !== '' && trim($row['a']) !== '',
    true
));

echo "    an event's own questions replace them\n";
$mine = se_settings_normalize(['portal' => ['faq' => [
    ['q' => 'Can I come late?', 'a' => 'Yes — slip in at the back, we save seats until 8.'],
    ['q' => 'Is there parking?', 'a' => 'Yes, in the school yard next door.'],
]]]);
is_same('two questions stored', 2, count($mine['portal']['faq']));
is_same('the first question survives', 'Can I come late?', $mine['portal']['faq'][0]['q']);
is_same('its answer survives', 'Yes — slip in at the back, we save seats until 8.', $mine['portal']['faq'][0]['a']);
is_same('the order is the order given', 'Is there parking?', $mine['portal']['faq'][1]['q']);

echo "    an empty list hides the section\n";
$none = se_settings_normalize(['portal' => ['faq' => []]]);
is_same('an explicit empty list stays empty', [], $none['portal']['faq']);
is_same('a missing key still gets the starters', 6, count(se_settings_normalize(['portal' => []])['portal']['faq']));

echo "    the normaliser's own limits\n";
$many = se_settings_normalize(['portal' => ['faq' => array_map(
    static fn(int $i): array => ['q' => 'Q' . $i, 'a' => 'A' . $i],
    range(1, SE_PORTAL_FAQ_MAX + 10)
)]]);
is_same('at most SE_PORTAL_FAQ_MAX are kept', SE_PORTAL_FAQ_MAX, count($many['portal']['faq']));
$long = se_settings_normalize(['portal' => ['faq' => [
    ['q' => str_repeat('q', SE_PORTAL_FAQ_Q_MAX + 50), 'a' => str_repeat('a', SE_PORTAL_FAQ_A_MAX + 50)],
]]]);
is_same('a long question is clamped', SE_PORTAL_FAQ_Q_MAX, mb_strlen($long['portal']['faq'][0]['q']));
is_same('a long answer is clamped', SE_PORTAL_FAQ_A_MAX, mb_strlen($long['portal']['faq'][0]['a']));

echo "    se_portal_faq_clean() — what the Studio may send\n";
$clean = se_portal_faq_clean([
    ['q' => '  Is it free?  ', 'a' => "  Yes.\nJust register.  "],
    ['q' => '', 'a' => ''],
    ['q' => 'What time?', 'a' => 'Doors open at 5.'],
]);
is_same('a blank row is dropped, the rest kept', 2, count($clean));
is_same('the question is trimmed', 'Is it free?', $clean[0]['q']);
is_same('the answer is trimmed, newlines kept', "Yes.\nJust register.", $clean[0]['a']);
is_same('the following row keeps its place', 'What time?', $clean[1]['q']);
is_same('nothing at all is fine', [], se_portal_faq_clean([]));

echo "    a half-filled row is an error, not an empty accordion\n";
throws('an answer with no question', static fn() => se_portal_faq_clean([['q' => '', 'a' => 'Yes.']]), SeValidationException::class);
throws('a question with no answer', static fn() => se_portal_faq_clean([['q' => 'Is it free?', 'a' => '']]), SeValidationException::class);
throws('too many questions', static fn() => se_portal_faq_clean(array_map(
    static fn(int $i): array => ['q' => 'Q' . $i, 'a' => 'A' . $i],
    range(1, SE_PORTAL_FAQ_MAX + 1)
)), SeValidationException::class);
throws('a question longer than the limit', static fn() => se_portal_faq_clean([
    ['q' => str_repeat('q', SE_PORTAL_FAQ_Q_MAX + 1), 'a' => 'Yes.'],
]), SeValidationException::class);
throws('something that is not a list of rows', static fn() => se_portal_faq_clean('nonsense'), SeValidationException::class);

try {
    se_portal_faq_clean([
        ['q' => 'Is it free?', 'a' => 'Yes.'],
        ['q' => '', 'a' => 'Not that one.'],
    ]);
    ok('the error names the row the Studio drew', false, 'nothing was thrown');
} catch (SeValidationException $e) {
    // The Studio's <Field name> for row 1's question. A renamed key means the
    // message appears nowhere near the box it belongs to.
    ok('the error names the row the Studio drew', array_key_exists('faq_1_q', $e->fields),
        'got ' . se_json_encode(array_keys($e->fields)));
}

echo "    se_portal_settings_patch() — only what was submitted\n";
$patch = se_portal_settings_patch(['faq' => [['q' => 'Is it free?', 'a' => 'Yes.']]]);
is_same('a FAQ-only save touches only the FAQ', ['faq'], array_keys($patch));
is_same('and it is the cleaned list', [['q' => 'Is it free?', 'a' => 'Yes.']], $patch['faq']);

$patch = se_portal_settings_patch(['intro_line' => 'One night. Everybody sings.', 'show_countdown' => false]);
is_same('the switches come through', ['intro_line', 'show_countdown'], array_keys($patch));
is_same('the intro line is the one typed', 'One night. Everybody sings.', $patch['intro_line']);

$merged = se_settings_normalize(['portal' => se_portal_settings_patch(['show_countdown' => false])]);
is_same('a patch normalises into the document', false, $merged['portal']['show_countdown']);
is_same('and leaves the starter questions alone', 6, count($merged['portal']['faq']));

throws('an empty save', static fn() => se_portal_settings_patch([]), SeValidationException::class);
throws('a save of nothing we know', static fn() => se_portal_settings_patch(['nonsense' => 1]), SeValidationException::class);
