<?php
// /bin/mail_doctor.php

/**
 * Mail health from the server's command line (docs/MAIL.md, "Doctor"). Read-only: it changes nothing.
 *
 *   /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/bin/mail_doctor.php
 *
 * Checks: the tables, the vault key (present or not, never its value), the CLI PHP extensions, the `uapi` command
 * (a read-only list of mailboxes, and whether `uapi --help` offers input on stdin), the TLS certificate the mail
 * server presents for IMAP 993 and SMTP 465, the mailbox count against the quota, and when the crons last ran.
 * The web PHP has its own list on the Mailboxes screen (Health), because the two differ on this host.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/mail/bootstrap.php';

use HOD\Mail\Admin\Health;
use HOD\Mail\Admin\Uapi;
use HOD\Mail\Config;
use HOD\Mail\MailServer;

$line = static fn (bool $ok, string $label, string $detail = '') => printf("%s %s%s\n", $ok ? '[ ok ]' : '[FAIL]', $label, $detail !== '' ? ' — ' . $detail : '');

foreach (Health::checks() as $c) {
    $line($c['ok'], $c['label'], $c['detail']);
}

// uapi (CLI only)
try {
    $pops = (array) Uapi::make()->call('Email', 'list_pops', ['domain' => Config::get('domain')]);
    $line(true, 'uapi answers', count($pops) . ' mailbox(es) listed');
} catch (Throwable $e) {
    $line(false, 'uapi answers', $e->getMessage());
}
$binary = (string) Config::get('uapi.binary');
if (is_executable($binary)) {
    $help = (string) shell_exec(escapeshellarg($binary) . ' --help 2>&1');
    $line(true, 'uapi --help mentions stdin input', preg_match('/stdin|--input/i', $help) ? 'yes: switch the runner to it' : 'no: parameters stay arguments');
}

// TLS of the mail server, with certificate and name checks
$s = MailServer::settings();
foreach ([[$s['imap_host'], $s['imap_port'], 'IMAP'], [$s['smtp_host'], $s['smtp_port'], 'SMTP']] as [$host, $port, $label]) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'capture_peer_cert' => true]]);
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if ($sock === false) {
        $line(false, "{$label} TLS {$host}:{$port}", $errstr ?: 'no connection');
        continue;
    }
    $cert = openssl_x509_parse(stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? '') ?: [];
    fclose($sock);
    $line(true, "{$label} TLS {$host}:{$port}", 'certificate for ' . ($cert['subject']['CN'] ?? '?') . ', valid to ' . date('Y-m-d', (int) ($cert['validTo_time_t'] ?? 0)));
}
