<?php
// /bin/mail_test.php

/**
 * Send one test email with the settings in .env (includes/mailer.php), to check
 * the SMTP set-up after a change. CLI only; it never prints the password.
 *
 *   /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/bin/mail_test.php you@example.com
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/includes/mailer.php';

$to = $argv[1] ?? '';
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/mail_test.php you@example.com\n");
    exit(2);
}

$s = hod_mail_settings();
printf(
    "Sending through %s:%d (%s) as %s <%s>%s\n",
    $s['host'],
    $s['port'],
    $s['encryption'],
    $s['from_name'],
    $s['from_address'],
    $s['reply_to'] !== '' ? ", replies to {$s['reply_to']}" : ''
);
if (!hod_mail_configured($s)) {
    fwrite(STDERR, "Not set up: MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS are needed in .env\n");
    exit(1);
}

$site = site_origin();
$result = hod_send_mail(
    $to,
    'Test email from ' . site_host(),
    '<p>This is a test email from the Household of David Lekki Centre system at '
        . '<a href="' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(site_host(), ENT_QUOTES, 'UTF-8') . '</a>.</p>'
        . '<p>If you can read this, sending works. Reply to it to check the reply address.</p>'
);

if ($result['ok']) {
    echo "Sent to {$to}.\n";
    exit(0);
}
fwrite(STDERR, 'Failed: ' . $result['error'] . "\n");
exit(1);
