<?php
// /tests/special_events/phone_test.php — phone normalisation (guide §10.3.1,
// §22.1).
//
// The vectors live in fixtures/phones.json and are run by BOTH this file and
// tests/special_events/js/phone.test.mjs. That is the whole point: the
// registration sheet validates in JavaScript and the API validates in PHP,
// so a disagreement between them shows up as a number the form accepts and
// the server rejects. The shared file makes that impossible to miss.

$fixture = __DIR__ . '/fixtures/phones.json';
ok('the shared fixture file exists', is_file($fixture));

$data = json_decode((string) file_get_contents($fixture), true);
ok('the fixture is valid JSON', is_array($data) && isset($data['vectors']));

$vectors = $data['vectors'] ?? [];
ok('at least 40 vectors (§22.1)', count($vectors) >= 40, 'got ' . count($vectors));

echo "    every shared vector\n";
$valid = 0;
$rejected = 0;

foreach ($vectors as $i => $vector) {
    $input    = $vector['input'];
    $expected = $vector['expected'];
    $actual   = se_phone_normalize($input);

    $label = sprintf('[%02d] %s', $i, var_export($input, true));

    if ($expected === null) {
        $rejected++;
        ok($label . ' is rejected', $actual === null, 'got ' . se_test_dump($actual));
        continue;
    }

    $valid++;
    if (!is_array($actual)) {
        ok($label . ' is accepted', false, 'got null');
        continue;
    }

    is_same($label . ' → e164', $expected['e164'], $actual['e164']);
    is_same($label . ' → sms', $expected['sms'], $actual['sms']);
    is_same($label . ' → display', $expected['display'], $actual['display']);
}

ok('the fixture covers both outcomes', $valid > 0 && $rejected > 0, "valid={$valid} rejected={$rejected}");

echo "    the Nigerian rules\n";
// se_phone_normalize() must delegate NG mobiles to the SMS Studio's own
// normaliser (§10.3.1), so a number that works for a church-wide SMS blast
// is the same string here.
if (function_exists('sms_normalize_phone')) {
    foreach (['08031234567', '+234 803 123 4567', '8031234567', '2348031234567'] as $sample) {
        is_same(
            "matches sms_normalize_phone({$sample})",
            sms_normalize_phone($sample),
            se_phone_normalize($sample)['e164'] ?? null
        );
    }
} else {
    echo "      (sms_functions.php not loaded; delegation checked by the shared vectors)\n";
}

is_same('every NG mobile prefix is accepted: 70', '2347031234567', se_phone_normalize('07031234567')['e164']);
is_same('…71', '2347112345678', se_phone_normalize('07112345678')['e164']);
is_same('…80', '2348031234567', se_phone_normalize('08031234567')['e164']);
is_same('…81', '2348131234567', se_phone_normalize('08131234567')['e164']);
is_same('…90', '2349031234567', se_phone_normalize('09031234567')['e164']);
is_same('…91', '2349131234567', se_phone_normalize('09131234567')['e164']);

ok('a landline is not a mobile', se_phone_normalize('012345678') === null);
ok('one digit short is rejected', se_phone_normalize('0803123456') === null);
ok('one digit long is rejected', se_phone_normalize('080312345678') === null);

echo "    international\n";
$uk = se_phone_normalize('+447911123456');
ok('a UK number is accepted when it carries its +', is_array($uk));
is_same('…and is not SMS-capable on our route', false, $uk['sms']);
ok('the same digits without a + are rejected', se_phone_normalize('447911123456') === null);
ok('15 digits is the ITU maximum', is_array(se_phone_normalize('+123456789012345')));
ok('16 digits is too many', se_phone_normalize('+1234567890123456') === null);

echo "    types\n";
ok('null is rejected', se_phone_normalize(null) === null);
ok('an array is rejected', se_phone_normalize(['0803']) === null);
ok('a boolean is rejected', se_phone_normalize(true) === null);
ok('an integer is read as digits', is_array(se_phone_normalize(8031234567)));

echo "    display and capability\n";
is_same('display groups an NG number', '+234 803 123 4567', se_phone_display('2348031234567'));
ok('sms_capable is true for NG mobiles', se_phone_sms_capable('2348031234567'));
ok('sms_capable is false for anything else', !se_phone_sms_capable('447911123456'));
ok('sms_capable is false for rubbish', !se_phone_sms_capable(''));

echo "    idempotence\n";
// Normalising an already-normalised number must not change it.
//
// The stored form is bare digits, and a bare non-Nigerian string is
// deliberately NOT accepted as an input (§10.3.1 step 3 requires a leading
// "+" or "00", otherwise "8031234567" would be ambiguous). So the invariant
// is stated over the two forms that ARE valid inputs: the E.164 digits with
// a "+", and the display string.
foreach ($vectors as $vector) {
    if ($vector['expected'] === null) {
        continue;
    }
    $once = se_phone_normalize($vector['input']);

    is_same('re-normalising +' . $once['e164'] . ' is a no-op',
        $once['e164'], se_phone_normalize('+' . $once['e164'])['e164'] ?? null);

    is_same('re-normalising the display form of ' . $once['e164'] . ' is a no-op',
        $once['e164'], se_phone_normalize($once['display'])['e164'] ?? null);
}

echo "    stored numbers are not re-parsed\n";
// Nothing in the module feeds a stored phone_e164 back through the
// normaliser, so this is documentation as much as a test: if that ever
// changes, a bare international number will come back null and this is the
// line that says why.
ok('a bare NG E.164 still normalises (it is 13 digits starting 234)',
    se_phone_normalize('2348031234567') !== null);
ok('a bare foreign E.164 does not (no + and not Nigerian)',
    se_phone_normalize('447911123456') === null);
