<?php
// /tests/monitor/redact_test.php — nothing personal or secret reaches the monitor's tables.

// DB_PASS and MAIL_PASSWORD are set in lib.php, before monitor_secret_values() first caches them.

$cases = [
    'email'                 => ['Could not send to ada.okafor@gmail.com today', 'ada.okafor@gmail.com', '<email>'],
    'Nigerian mobile'       => ['call 08031234567 now', '08031234567', '<phone>'],
    'Nigerian 234 form'     => ['phone=2348031234567', '2348031234567', '<phone>'],
    'international form'    => ['number +234 803 123 4567 refused', '+234 803 123 4567', '<phone>'],
    'Cameroon form'         => ['SMS to 237677123456 failed', '237677123456', '<phone>'],
    'bearer token'          => ['Authorization: Bearer abcDEF123456789xyz', 'abcDEF123456789xyz', 'Bearer <token>'],
    'session cookie'        => ['Cookie: PHPSESSID=abc123def456; theme=dark', 'abc123def456', 'PHPSESSID=<cookie>'],
    'remember cookie'       => ['hod_remember=sel123:val456789', 'sel123:val456789', 'hod_remember=<cookie>'],
    'password field'        => ['login failed, password=Hunter2024!', 'Hunter2024!', '<secret>'],
    'api key field'         => ['GET /x?api_key=ZZZ999yyy', 'ZZZ999yyy', '<secret>'],
    'JSON token'            => ['{"token": "t0k3n-value"}', 't0k3n-value', '<secret>'],
    'long opaque token'     => ['link /e/x/m/9f8e7d6c5b4a39281706f5e4d3c2b1a0aa11bb22', '9f8e7d6c5b4a39281706f5e4d3c2b1a0aa11bb22', '<token>'],
    'JWT'                   => ['jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N', 'eyJhbGciOiJIUzI1NiJ9', '<token>'],
    '.env value by name'    => ['SQLSTATE[HY000] [1045] Access denied (using password: Pw-for-the-test-9431)', 'Pw-for-the-test-9431', '[DB_PASS]'],
    'mail password'         => ['SMTP AUTH as system@ with mailbox-secret-77 failed', 'mailbox-secret-77', '[MAIL_PASSWORD]'],
    'duplicate entry value' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Adaeze Okafor' for key 'full_name'", 'Adaeze Okafor', "entry '?'"],
    'incorrect value'       => ["Incorrect integer value: 'Chinedu' for column 'age' at row 1", 'Chinedu', "value: '?'"],
];
foreach ($cases as $label => [$in, $gone, $mark]) {
    $out = monitor_redact($in);
    lacks("redact {$label}: the value is gone", $out, $gone);
    has("redact {$label}: a marker says what was there", $out, $mark);
}

$keep = [
    'Undefined array key "phone" in modules/events/index.php on line 12',
    "Unknown column 'spiritual_status' in 'field list'",
    'Call to undefined function mime_content_type()',
    'mapping: 3, author: Tom, design=flat',
    'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
    'Maximum execution time of 300 seconds exceeded',
];
foreach ($keep as $text) {
    eq('ordinary error text is left alone: ' . mb_substr($text, 0, 40), $text, monitor_redact($text));
}

// Page addresses
eq('query values are dropped, names kept', ['/api/events_api.php?action=…&id=…', '/api/events_api.php'], monitor_clean_url('/api/events_api.php?action=get&id=55'));
eq('Special Events slugs group as one route', ['/e/chara-night/checkin?token=…', '/e/:event/checkin'], monitor_clean_url('/e/chara-night/checkin?token=abc'));
eq('numbers become :id in the route only', ['/modules/x/42/edit', '/modules/x/:id/edit'], monitor_clean_url('/modules/x/42/edit'));
eq('a token in the path is hidden', '/e/x/m/:token', monitor_clean_url('/e/x/m/a1b2c3d4e5f6a7b8c9d0e1f2')[0]);
eq('an email or phone in the path is hidden', '/u/:value/:value', monitor_clean_url('/u/ada@x.ng/08031234567')[0]);
eq('no host, no fragment', '/modules/events/index.php', monitor_clean_url('https://hodlekki.org/modules/events/index.php#tab=x')[0]);

// Browsers and devices
$android = 'Mozilla/5.0 (Linux; Android 14; SM-A146P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
$edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0';
$ipad = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
eq('Chrome on Android', 'Chrome 131 · Android', monitor_browser_family($android));
eq('Safari on iPhone', 'Safari 17 · iOS', monitor_browser_family($iphone));
eq('Edge on Windows', 'Edge 131 · Windows', monitor_browser_family($edge));
eq('an Android phone is a phone', 'phone', monitor_device_class($android));
eq('an iPhone is a phone', 'phone', monitor_device_class($iphone));
eq('an iPad is a tablet', 'tablet', monitor_device_class($ipad));
eq('Windows is a desktop', 'desktop', monitor_device_class($edge));

// Paths
$root = str_replace('\\', '/', dirname(__DIR__, 2));
eq('a file in this tree is shown relative', 'includes/db.php', monitor_short_path($root . '/includes/db.php'));
eq('the docroot on the server is shown relative', 'api/events_api.php', monitor_short_path('/home/smartqaq/public_html/hodlekki/api/events_api.php'));
eq('the repository checkout is shown relative', 'bin/deploy.sh', monitor_short_path('/home/smartqaq/repositories/hodlekki/bin/deploy.sh'));
eq('the account home is shown as ~', '~/deploy.log', monitor_short_path('/home/smartqaq/deploy.log'));

// ---- Gaps the review found (11 Nov)
foreach (['call 0803 123 4567 now', 'call 0803-123-4567 now', 'call 234 803 123 4567 now', 'SMS to 237 677 123 456'] as $spaced) {
    lacks('a phone number with spaces or dashes is masked: ' . $spaced, monitor_redact($spaced), '123');
}
eq('a URL-encoded email is masked', 'to=<email>', monitor_redact('to=ada%40gmail.com'));
eq('a Special Events manage token with no digit is hidden', '/e/chara/me/:token', monitor_clean_url('/e/chara/me/abcdefghijklmnopqrstuv')[0]);
eq('…in the route too', '/e/:event/me/:token', monitor_clean_url('/e/chara/me/abcdefghijklmnopqrstuv')[1]);
eq('a mixed-case token anywhere is hidden', '/x/:token', monitor_clean_url('/x/AbCdEfGhIjKlMnOpQr')[0]);
eq('a long lower-case slug is kept', '/e/chara-night-karaoke-games/checkin', monitor_clean_url('/e/chara-night-karaoke-games/checkin')[0]);
$_ENV['MONITOR_TEST_LATE_SECRET'] = 'late-secret-value-7781';
eq('a secret added to the environment later is still redacted (no stale cache)', 'got [MONITOR_TEST_LATE_SECRET]', monitor_redact('got late-secret-value-7781'));
unset($_ENV['MONITOR_TEST_LATE_SECRET']);
