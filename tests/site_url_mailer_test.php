<?php
// /tests/site_url_mailer_test.php
/**
 * The site's address and outgoing email — no database, no network.
 *     php tests/site_url_mailer_test.php
 * Covers includes/site_url.php (SITE_URL first, never connect.hodlekki.org or the
 * old hodlc.lpc.cm in a link that leaves the browser; the Connect page's own
 * host) and the settings half of includes/mailer.php (system@ sends, embrace@
 * gets the replies, certificate checks relaxed for localhost only). The lock
 * script's .htaccess block is checked when bash is available.
 */

require_once __DIR__ . '/../includes/site_url.php';
require_once __DIR__ . '/../includes/mailer.php';

$fails = 0;
$count = 0;
function is_same(string $label, $want, $got): void
{
    global $fails, $count;
    $count++;
    if ($want === $got) {
        echo "  ok   {$label}\n";
        return;
    }
    $fails++;
    echo "  FAIL {$label}\n       want " . var_export($want, true) . "\n       got  " . var_export($got, true) . "\n";
}

/** Run $fn with $_ENV and $_SERVER changed, then put them back. */
function with_env(array $env, array $server, callable $fn)
{
    $savedEnv = $_ENV;
    $savedServer = $_SERVER;
    foreach ($env as $k => $v) {
        $_ENV[$k] = $v;
    }
    foreach ($server as $k => $v) {
        if ($v === null) {
            unset($_SERVER[$k]);
        } else {
            $_SERVER[$k] = $v;
        }
    }
    try {
        return $fn();
    } finally {
        $_ENV = $savedEnv;
        $_SERVER = $savedServer;
    }
}

echo "site_origin / site_url / site_host\n";
is_same('SITE_URL wins, trailing slash dropped', 'https://hodlekki.org',
    with_env(['SITE_URL' => 'https://hodlekki.org/'], [], 'site_origin'));
is_same('site_url joins a path once', 'https://hodlekki.org/sermons?sermon=x',
    with_env(['SITE_URL' => 'https://hodlekki.org'], [], fn () => site_url('/sermons?sermon=x')));
is_same('site_url without a leading slash', 'https://hodlekki.org/assets/a.png',
    with_env(['SITE_URL' => 'https://hodlekki.org'], [], fn () => site_url('assets/a.png')));
is_same('site_host for printed text', 'hodlekki.org',
    with_env(['SITE_URL' => 'https://hodlekki.org'], [], 'site_host'));
// Under the CLI there is no request host: no SITE_URL means the default.
is_same('CLI with no SITE_URL falls back to hodlekki.org', 'https://hodlekki.org',
    with_env(['SITE_URL' => ''], ['HTTP_HOST' => 'connect.hodlekki.org'], 'site_origin'));

echo "connect host\n";
is_same('connect_origin from SITE_URL', 'https://connect.hodlekki.org',
    with_env(['SITE_URL' => 'https://hodlekki.org', 'CONNECT_URL' => ''], [], 'connect_origin'));
is_same('CONNECT_URL overrides', 'https://welcome.example.org',
    with_env(['SITE_URL' => 'https://hodlekki.org', 'CONNECT_URL' => 'https://welcome.example.org/'], [], 'connect_origin'));
// is_connect_host() is false under the CLI whatever the host says.
is_same('never a connect request under the CLI', false,
    with_env(['SITE_URL' => 'https://hodlekki.org'], ['HTTP_HOST' => 'connect.hodlekki.org'], 'is_connect_host'));

echo "mail settings\n";
$mailEnv = [
    'MAIL_HOST' => '', 'MAIL_PORT' => '', 'MAIL_ENCRYPTION' => '', 'MAIL_USERNAME' => 'system@hodlekki.org',
    'MAIL_PASSWORD' => 'not-a-real-password', 'MAIL_FROM_ADDRESS' => '', 'MAIL_FROM_NAME' => '',
    'MAIL_REPLY_TO' => 'embrace@hodlekki.org', 'MAIL_REPLY_TO_NAME' => '',
];
$s = with_env($mailEnv, [], 'hod_mail_settings');
is_same('host defaults to localhost', 'localhost', $s['host']);
is_same('port defaults to 465 with ssl', 465, $s['port']);
is_same('encryption defaults to ssl', 'ssl', $s['encryption']);
is_same('sender defaults to the mailbox', 'system@hodlekki.org', $s['from_address']);
is_same('sender name', 'Household of David Lekki', $s['from_name']);
is_same('replies go to embrace@', 'embrace@hodlekki.org', $s['reply_to']);
is_same('configured with a sign-in and a sender', true, hod_mail_configured($s));
is_same('not configured without a password', false, hod_mail_configured(['password' => ''] + $s));
is_same('tls defaults to port 587', 587,
    with_env(['MAIL_ENCRYPTION' => 'tls', 'MAIL_PORT' => ''] + $mailEnv, [], 'hod_mail_settings')['port']);
is_same('an unknown encryption falls back to ssl', 'ssl',
    with_env(['MAIL_ENCRYPTION' => 'starttls-please'] + $mailEnv, [], 'hod_mail_settings')['encryption']);
is_same('localhost skips certificate checks', true, hod_mail_is_local_host('localhost'));
is_same('127.0.0.1 skips certificate checks', true, hod_mail_is_local_host('127.0.0.1'));
is_same('a real host keeps certificate checks', false, hod_mail_is_local_host('mail.hodlekki.org'));
$unsent = with_env(['MAIL_PASSWORD' => ''] + $mailEnv, [], fn () => hod_send_mail('a@example.com', 'x', '<p>x</p>'));
is_same('an unconfigured send fails quietly, no exception', false, $unsent['ok']);
is_same('and says why', true, str_contains((string) $unsent['error'], 'not set up'));
is_same('the result always carries a message_id key', true, array_key_exists('message_id', $unsent));
is_same('List-Unsubscribe is an allowed extra header', true, hod_mail_header_ok('List-Unsubscribe', '<https://hodlekki.org/email/unsubscribe.php?r=x>'));
is_same('a header PHPMailer owns is refused (From)', false, hod_mail_header_ok('From', 'someone@example.com'));
is_same('a header PHPMailer owns is refused (Bcc)', false, hod_mail_header_ok('bcc', 'all@example.com'));
is_same('a value with a line break is refused (injection)', false, hod_mail_header_ok('X-HOD-Ref', "abc\r\nBcc: all@example.com"));
is_same('a name with odd characters is refused', false, hod_mail_header_ok('X Bad:Name', 'v'));

echo "account-mail route (ZeptoMail)\n";
$accountEnv = ['ACCOUNT_MAIL_HOST' => 'smtp.zeptomail.com', 'ACCOUNT_MAIL_PORT' => '', 'ACCOUNT_MAIL_ENCRYPTION' => '',
    'ACCOUNT_MAIL_USERNAME' => 'emailapikey', 'ACCOUNT_MAIL_PASSWORD' => 'not-a-real-token'] + $mailEnv;
is_same('no ACCOUNT_MAIL_HOST: no account route', null,
    with_env(['ACCOUNT_MAIL_HOST' => ''] + $accountEnv, [], 'hod_mail_account_settings'));
$a = with_env($accountEnv, [], 'hod_mail_account_settings');
is_same('the account route uses its own host', 'smtp.zeptomail.com', $a['host']);
is_same('…tls on 587 by default', [587, 'tls'], [$a['port'], $a['encryption']]);
is_same('…its own sign-in', ['emailapikey', 'not-a-real-token'], [$a['username'], $a['password']]);
is_same('…and the same sender and reply-to', ['system@hodlekki.org', 'embrace@hodlekki.org'], [$a['from_address'], $a['reply_to']]);
is_same('no token: no account route (the host keeps sending)', null,
    with_env(['ACCOUNT_MAIL_PASSWORD' => ''] + $accountEnv, [], 'hod_mail_account_settings'));

echo "origin lock (bin/edge_lock.sh)\n";
$bash = trim((string) @shell_exec('command -v bash 2>/dev/null'));
if ($bash === '') {
    echo "  skip no bash here\n";
} else {
    $dir = sys_get_temp_dir() . '/edge_lock_' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/.htaccess', "# site rules\nRewriteEngine On\n");
    $run = fn () => shell_exec('bash ' . escapeshellarg(dirname(__DIR__) . '/bin/edge_lock.sh') . ' ' . escapeshellarg($dir) . ' 2>&1');
    $token = str_repeat('a1', 20);
    file_put_contents($dir . '/.env', "DB_HOST=localhost\nEDGE_TOKEN=\"{$token}\"\n");
    $out = (string) $run();
    $ht = (string) file_get_contents($dir . '/.htaccess');
    is_same('lock on: block at the top', true, str_starts_with($ht, '# hod-edge-lock-begin'));
    is_same('lock on: header checked', true, str_contains($ht, "RewriteCond %{HTTP:X-Hodlekki-Edge} !^{$token}\$"));
    is_same('lock on: site rules kept', true, str_contains($ht, '# site rules'));
    is_same('lock on: token never printed', false, str_contains($out, $token));
    $run();
    is_same('running twice keeps one block', 1, substr_count((string) file_get_contents($dir . '/.htaccess'), '# hod-edge-lock-begin'));
    file_put_contents($dir . '/.env', "DB_HOST=localhost\n");
    $run();
    $ht = (string) file_get_contents($dir . '/.htaccess');
    is_same('no EDGE_TOKEN: block removed', false, str_contains($ht, 'hod-edge-lock'));
    is_same('no EDGE_TOKEN: site rules kept', true, str_contains($ht, '# site rules'));
    file_put_contents($dir . '/.env', "EDGE_TOKEN=\"short\"\n");
    $before = (string) file_get_contents($dir . '/.htaccess');
    $run();
    is_same('a bad token leaves the file alone', $before, (string) file_get_contents($dir . '/.htaccess'));
    array_map('unlink', glob($dir . '/{,.}*', GLOB_BRACE) ?: []);
    @rmdir($dir);
}

echo "\n{$count} checks, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);
