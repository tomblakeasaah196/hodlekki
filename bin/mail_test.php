<?php
// /bin/mail_test.php

/**
 * Send one test email with the settings in .env (includes/mailer.php), to check
 * the set-up after a change. CLI only; it never prints a password or key.
 *
 *   /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/bin/mail_test.php you@example.com
 *   ... mail_test.php you@example.com --account   (account mail: ZeptoMail)
 *   ... mail_test.php you@example.com --backup    (account mail's backup: Brevo)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/includes/mailer.php';

$account = in_array('--account', $argv, true);
$backup = in_array('--backup', $argv, true);
$to = (string) (array_values(array_diff(array_slice($argv, 1), ['--account', '--backup']))[0] ?? '');
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/mail_test.php you@example.com [--account | --backup]\n");
    exit(2);
}

$s = $backup ? hod_mail_backup_settings() : ($account ? hod_mail_account_settings() : hod_mail_settings());
if ($s === null) {
    fwrite(STDERR, $backup
        ? "Not set up: ACCOUNT_MAIL_BACKUP_API_KEY is needed in .env\n"
        : "Not set up: ACCOUNT_MAIL_HOST, ACCOUNT_MAIL_USERNAME and ACCOUNT_MAIL_PASSWORD are needed in .env\n");
    exit(1);
}
$api = $backup || ($account && ($s['transport'] ?? '') === 'api');
printf(
    "Sending through %s as %s <%s>%s\n",
    $api ? $s['api_url'] . ' (HTTPS API)' : sprintf('%s:%d (%s)', $s['host'], $s['port'], $s['encryption']),
    $s['from_name'],
    $s['from_address'],
    $s['reply_to'] !== '' ? ", replies to {$s['reply_to']}" : ''
);
if (!$api && !hod_mail_configured($s)) {
    fwrite(STDERR, "Not set up: MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS are needed in .env\n");
    exit(1);
}

$site = site_origin();
$subject = 'Test email from ' . site_host() . ($backup ? ' (backup route)' : '');
$html = '<p>This is a test email from the Household of David Lekki Centre system at '
    . '<a href="' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(site_host(), ENT_QUOTES, 'UTF-8') . '</a>.</p>'
    . '<p>If you can read this, sending works. Reply to it to check the reply address.</p>';
// The API tests carry the same headers as real account mail, so they prove them too.
$message = ['to_email' => $to, 'to_name' => '', 'subject' => $subject, 'html' => $html, 'text' => null,
    'headers' => ['X-HOD-Ref' => 'mail-test', 'Auto-Submitted' => 'auto-generated']];
$result = $backup ? hod_brevo_send($s, $message)
    : ($api ? hod_zepto_send($s, $message)
        : hod_send_mail($to, $subject, $html, null, $account ? ['mailer' => hod_mailer($s)] : []));

if ($result['ok']) {
    echo "Sent to {$to}.\n";
    exit(0);
}
fwrite(STDERR, 'Failed: ' . $result['error'] . "\n");
exit(1);
