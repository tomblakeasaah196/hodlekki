<?php
// /tests/special_events/after_event_test.php

is_same('NG E.164 becomes local Reach format', '08031234567', se_local_phone('2348031234567'));
is_same('international hand-off phone keeps plus', '+447700900123', se_local_phone('+44 7700 900123'));
is_same('short hand-off phone is rejected', null, se_local_phone('12345'));

$report = (string) file_get_contents(__DIR__ . '/../../api/special_events_report.php');
ok('PDF builds from aggregate insights', str_contains($report, 'se_insights'));
ok('PDF does not query contacts', !str_contains($report, 'se_contacts'));
ok('PDF has no phone or email field', !preg_match('/phone_e164|contact_email|\bemail\b/i', $report));
