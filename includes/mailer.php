<?php
// /includes/mailer.php

/**
 * Outgoing email (November 2026, with the move to hodlekki.org).
 *
 * Mail goes out over SMTP through this server's own mail service, the way
 * nfelia.com sends: from system@hodlekki.org, with replies addressed to
 * embrace@hodlekki.org — the team that follows up first timers reads that inbox,
 * so a person answering an automatic email reaches a real person.
 *
 * Settings live in .env (never in code):
 *   MAIL_HOST          localhost            (the server's own mail service)
 *   MAIL_PORT          465
 *   MAIL_ENCRYPTION    ssl                  (ssl | tls | none)
 *   MAIL_USERNAME      system@hodlekki.org  (the cPanel mailbox)
 *   MAIL_PASSWORD      that mailbox's password
 *   MAIL_FROM_ADDRESS  system@hodlekki.org
 *   MAIL_FROM_NAME     Household of David Lekki
 *   MAIL_REPLY_TO      embrace@hodlekki.org
 *   MAIL_REPLY_TO_NAME HOD Lekki Embrace Team
 *
 * On localhost the connection never leaves the machine, and the server's
 * certificate is issued to its own host name, not "localhost", so certificate
 * checks are relaxed for localhost only. Any other MAIL_HOST is verified normally.
 *
 * Nothing here prints or logs a password. A send that fails returns the reason
 * and writes one line to the error log; it never throws into the page.
 */

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/site_url.php';

if (!function_exists('hod_mail_settings')) {
    /** The mail settings from .env, with the defaults above. */
    function hod_mail_settings(): array
    {
        $get = static fn (string $key, string $default = ''): string => ($v = site_env($key)) !== '' ? $v : $default;
        $encryption = strtolower($get('MAIL_ENCRYPTION', 'ssl'));
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            $encryption = 'ssl';
        }

        return [
            'host'          => $get('MAIL_HOST', 'localhost'),
            'port'          => (int) $get('MAIL_PORT', $encryption === 'tls' ? '587' : '465'),
            'encryption'    => $encryption,
            'username'      => $get('MAIL_USERNAME'),
            'password'      => $get('MAIL_PASSWORD'),
            'from_address'  => $get('MAIL_FROM_ADDRESS', $get('MAIL_USERNAME')),
            'from_name'     => $get('MAIL_FROM_NAME', 'Household of David Lekki'),
            'reply_to'      => $get('MAIL_REPLY_TO'),
            'reply_to_name' => $get('MAIL_REPLY_TO_NAME', 'HOD Lekki Embrace Team'),
        ];
    }
}

if (!function_exists('hod_mail_account_settings')) {
    /**
     * The account-mail route (26 Nov 2026). Codes, reset links, confirmations
     * and alerts leave through a transactional service (ZeptoMail) when
     * ACCOUNT_MAIL_HOST is set, because the shared host caps hodlekki.org at
     * 20 emails an hour and quietly drops the rest. Church news stays on the host.
     *   ACCOUNT_MAIL_HOST        smtp.zeptomail.com
     *   ACCOUNT_MAIL_PORT        587
     *   ACCOUNT_MAIL_ENCRYPTION  tls
     *   ACCOUNT_MAIL_USERNAME    emailapikey
     *   ACCOUNT_MAIL_PASSWORD    the agent's Send Mail token
     * Sender, sender name and reply-to stay MAIL_FROM_ADDRESS / MAIL_FROM_NAME /
     * MAIL_REPLY_TO. Null when it is not set up: everything stays on the host.
     */
    function hod_mail_account_settings(): ?array
    {
        $host = site_env('ACCOUNT_MAIL_HOST');
        if ($host === '') {
            return null;
        }
        $encryption = strtolower(site_env('ACCOUNT_MAIL_ENCRYPTION') ?: 'tls');
        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            $encryption = 'tls';
        }
        $s = [
            'host'       => $host,
            'port'       => (int) (site_env('ACCOUNT_MAIL_PORT') ?: ($encryption === 'ssl' ? '465' : '587')),
            'encryption' => $encryption,
            'username'   => site_env('ACCOUNT_MAIL_USERNAME'),
            'password'   => site_env('ACCOUNT_MAIL_PASSWORD'),
        ] + hod_mail_settings();

        return hod_mail_configured($s) ? $s : null;
    }
}

if (!function_exists('hod_mail_configured')) {
    /** Whether .env has what an SMTP send needs (a sign-in and a sender). */
    function hod_mail_configured(?array $settings = null): bool
    {
        $s = $settings ?? hod_mail_settings();

        return $s['host'] !== '' && $s['username'] !== '' && $s['password'] !== ''
            && filter_var($s['from_address'], FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('hod_mail_is_local_host')) {
    function hod_mail_is_local_host(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}

if (!function_exists('hod_mailer')) {
    /**
     * A PHPMailer set up from .env, ready for addAddress()/Subject/Body. Throws
     * RuntimeException when mail is not configured or PHPMailer is not installed.
     */
    function hod_mailer(?array $settings = null): PHPMailer
    {
        $s = $settings ?? hod_mail_settings();
        if (!hod_mail_configured($s)) {
            throw new RuntimeException('Email is not set up: MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS are needed in .env.');
        }
        if (!class_exists(PHPMailer::class)) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer is not installed (run composer install).');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $s['host'];
        $mail->Port       = $s['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $s['username'];
        $mail->Password   = $s['password'];
        $mail->Timeout    = 20;
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;
        $mail->XMailer    = ' ';
        if ($s['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($s['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        }
        if (hod_mail_is_local_host($s['host'])) {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        $mail->setFrom($s['from_address'], $s['from_name']);
        if ($s['reply_to'] !== '' && filter_var($s['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($s['reply_to'], $s['reply_to_name']);
        }
        // The domain in Message-ID matches the sender's, which spam filters check.
        $domain = substr(strrchr($s['from_address'], '@') ?: '@' . site_host(), 1);
        $mail->MessageID = sprintf('<%s@%s>', bin2hex(random_bytes(12)), $domain);

        return $mail;
    }
}

if (!function_exists('hod_mail_header_ok')) {
    /**
     * A custom header we are willing to add: a plain name, a one-line value,
     * and never one PHPMailer sets itself (From, To, Subject, Message-ID…).
     */
    function hod_mail_header_ok(string $name, string $value): bool
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9-]{1,62}$/', $name) || strpbrk($value, "\r\n\0") !== false
            || strlen($value) > 900) {
            return false;
        }
        $reserved = ['from', 'to', 'cc', 'bcc', 'subject', 'date', 'message-id', 'reply-to', 'sender',
                     'return-path', 'content-type', 'content-transfer-encoding', 'mime-version'];

        return !in_array(strtolower($name), $reserved, true);
    }
}

if (!function_exists('hod_send_mail')) {
    /**
     * Send one email. $to is an address or [address => name]. $html is the body;
     * $text the plain-text part (made from the HTML when omitted).
     * $options:
     *   reply_to, reply_to_name  instead of MAIL_REPLY_TO
     *   from_name                instead of MAIL_FROM_NAME
     *   headers                  [name => value] extra headers (List-Unsubscribe…);
     *                            a header that fails hod_mail_header_ok() fails the send
     *   message_id               '<id@domain>' to use instead of a fresh one, so the
     *                            caller can record it before sending
     *   mailer                   a PHPMailer from hod_mailer() with SMTPKeepAlive on,
     *                            reused for a batch (one SMTP connection); its
     *                            per-message state is reset here, and the caller
     *                            closes it with smtpClose()
     *
     * @return array{ok: bool, error: ?string, message_id: ?string}
     */
    function hod_send_mail($to, string $subject, string $html, ?string $text = null, array $options = []): array
    {
        $messageId = null;
        try {
            $shared = $options['mailer'] ?? null;
            if ($shared instanceof PHPMailer) {
                $mail = $shared;
                $defaults = hod_mail_settings();
                $mail->clearAllRecipients();
                $mail->clearReplyTos();
                $mail->clearCustomHeaders();
                $mail->clearAttachments();
                $mail->FromName = $defaults['from_name'];
                if ($defaults['reply_to'] !== '' && filter_var($defaults['reply_to'], FILTER_VALIDATE_EMAIL)) {
                    $mail->addReplyTo($defaults['reply_to'], $defaults['reply_to_name']);
                }
                $domain = substr(strrchr($defaults['from_address'], '@') ?: '@' . site_host(), 1);
                $mail->MessageID = sprintf('<%s@%s>', bin2hex(random_bytes(12)), $domain);
            } else {
                $mail = hod_mailer();
            }
            foreach ((is_array($to) ? $to : [$to => '']) as $address => $name) {
                if (is_int($address)) {
                    [$address, $name] = [$name, ''];
                }
                $mail->addAddress((string) $address, (string) $name);
            }
            if (!empty($options['from_name'])) {
                $mail->FromName = (string) $options['from_name'];
            }
            if (!empty($options['reply_to']) && filter_var($options['reply_to'], FILTER_VALIDATE_EMAIL)) {
                $mail->clearReplyTos();
                $mail->addReplyTo((string) $options['reply_to'], (string) ($options['reply_to_name'] ?? ''));
            }
            if (!empty($options['message_id'])) {
                $id = (string) $options['message_id'];
                if (!preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/', $id)) {
                    throw new RuntimeException('Invalid Message-ID given to hod_send_mail().');
                }
                $mail->MessageID = $id;
            }
            foreach ((array) ($options['headers'] ?? []) as $name => $value) {
                if (!hod_mail_header_ok((string) $name, (string) $value)) {
                    throw new RuntimeException('Refused header: ' . preg_replace('/[^A-Za-z0-9-]/', '?', (string) $name));
                }
                $mail->addCustomHeader((string) $name, (string) $value);
            }
            $messageId = $mail->MessageID;
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text ?? trim(html_entity_decode(strip_tags(
                preg_replace('#<br\s*/?>|</p>#i', "\n", $html) ?? $html
            ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $mail->send();

            return ['ok' => true, 'error' => null, 'message_id' => $messageId];
        } catch (MailerException | RuntimeException $e) {
            // PHPMailer's messages never contain the password. ErrorInfo adds the
            // server's own words ("550 … exceeded the max emails per hour"),
            // which the exception text leaves out and the outbox needs.
            $reason = $e->getMessage();
            if (isset($mail) && $mail instanceof PHPMailer && $mail->ErrorInfo !== ''
                && strlen($mail->ErrorInfo) > strlen($reason)) {
                $reason = $mail->ErrorInfo;
            }
            error_log('[mailer] send failed: ' . $reason);

            return ['ok' => false, 'error' => $reason, 'message_id' => $messageId];
        }
    }
}
