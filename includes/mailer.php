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

if (!function_exists('hod_send_mail')) {
    /**
     * Send one email. $to is an address or [address => name]. $html is the body;
     * $text the plain-text part (made from the HTML when omitted).
     * $options: reply_to, reply_to_name (instead of MAIL_REPLY_TO), from_name.
     *
     * @return array{ok: bool, error: ?string}
     */
    function hod_send_mail($to, string $subject, string $html, ?string $text = null, array $options = []): array
    {
        try {
            $mail = hod_mailer();
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
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text ?? trim(html_entity_decode(strip_tags(
                preg_replace('#<br\s*/?>|</p>#i', "\n", $html) ?? $html
            ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $mail->send();

            return ['ok' => true, 'error' => null];
        } catch (MailerException | RuntimeException $e) {
            // PHPMailer's messages never contain the password.
            $reason = $e->getMessage();
            error_log('[mailer] send failed: ' . $reason);

            return ['ok' => false, 'error' => $reason];
        }
    }
}
