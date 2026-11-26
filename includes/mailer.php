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
     *
     * 'transport' is 'api' for ZeptoMail unless ACCOUNT_MAIL_TRANSPORT=smtp:
     * this host refuses outgoing SMTP to other servers (465 refused, 587
     * answered by its own Exim, 26 Nov 2026), so the mail goes over HTTPS to
     * ACCOUNT_MAIL_API_URL (default https://cpaas.zoho.com/v1.1/email) with
     * ACCOUNT_MAIL_API_KEY, or the same Send Mail token as ACCOUNT_MAIL_PASSWORD.
     */
    function hod_mail_account_settings(): ?array
    {
        $host = site_env('ACCOUNT_MAIL_HOST');
        if ($host === '') {
            return null;
        }
        $transport = strtolower(site_env('ACCOUNT_MAIL_TRANSPORT'));
        if (!in_array($transport, ['api', 'smtp'], true)) {
            $transport = str_ends_with(strtolower($host), 'zeptomail.com') ? 'api' : 'smtp';
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
            'transport'  => $transport,
            'api_url'    => site_env('ACCOUNT_MAIL_API_URL') ?: 'https://cpaas.zoho.com/v1.1/email',
            'api_key'    => (string) preg_replace('/^Zoho-enczapikey\s+/i', '',
                site_env('ACCOUNT_MAIL_API_KEY') ?: site_env('ACCOUNT_MAIL_PASSWORD')),
        ] + hod_mail_settings();

        if ($transport === 'api') {
            return $s['api_key'] !== '' && filter_var($s['from_address'], FILTER_VALIDATE_EMAIL) !== false ? $s : null;
        }
        return hod_mail_configured($s) ? $s : null;
    }
}

if (!function_exists('hod_zepto_payload')) {
    /**
     * The ZeptoMail API body for one message ($m as the outbox worker builds it:
     * to_email, to_name, subject, html, text, from_name, reply_to, reply_to_name,
     * headers). Custom headers (X-HOD-Ref, Auto-Submitted) travel as mime_headers.
     */
    function hod_zepto_payload(array $s, array $m): array
    {
        $to = ['address' => (string) $m['to_email']];
        if (trim((string) ($m['to_name'] ?? '')) !== '') {
            $to['name'] = (string) $m['to_name'];
        }
        $body = [
            'from'     => ['address' => $s['from_address'], 'name' => (string) (($m['from_name'] ?? '') ?: $s['from_name'])],
            'to'       => [['email_address' => $to]],
            'subject'  => (string) $m['subject'],
            'htmlbody' => (string) $m['html'],
        ];
        if (($m['text'] ?? null) !== null && $m['text'] !== '') {
            $body['textbody'] = (string) $m['text'];
        }
        $replyTo = (string) (($m['reply_to'] ?? '') ?: $s['reply_to']);
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyName = (string) (($m['reply_to'] ?? '') ? ($m['reply_to_name'] ?? '') : $s['reply_to_name']);
            $body['reply_to'] = [array_filter(['address' => $replyTo, 'name' => $replyName], static fn ($v) => $v !== '')];
        }
        $headers = [];
        foreach ((array) ($m['headers'] ?? []) as $name => $value) {
            if (hod_mail_header_ok((string) $name, (string) $value)) {
                $headers[(string) $name] = (string) $value;
            }
        }
        if ($headers !== []) {
            $body['mime_headers'] = $headers;
        }
        return $body;
    }
}

if (!function_exists('hod_zepto_error')) {
    /**
     * One line for the outbox from a ZeptoMail answer, worded so that
     * email_classify_failure() files it right: 401/403 are our own set-up,
     * 429 and 5xx are temporary, other 4xx are permanent. Never the token.
     */
    function hod_zepto_error(int $status, string $raw): string
    {
        return hod_mail_api_error('ZeptoMail', $status, $raw);
    }
}

if (!function_exists('hod_mail_api_error')) {
    /** hod_zepto_error() for any mail API (ZeptoMail, Brevo): same wording rules. */
    function hod_mail_api_error(string $provider, int $status, string $raw): string
    {
        $json = json_decode($raw, true);
        $err = is_array($json) ? ($json['error'] ?? $json) : [];
        $parts = array_filter([
            (string) ($err['code'] ?? ''),
            (string) ($err['message'] ?? ''),
            (string) ($err['details'][0]['message'] ?? ''),
            (string) ($err['details'][0]['target'] ?? ''),
        ]);
        $what = $parts !== [] ? implode(' · ', $parts) : substr(trim(strip_tags($raw)), 0, 200);
        if ($status === 401 || $status === 403) {
            return "{$provider} authentication failed (status {$status}): {$what}";
        }
        if ($status === 429 || $status >= 500 || $status === 0) {
            return "[temporary] {$provider} could not take it now (status {$status}): {$what}";
        }
        return "[permanent] {$provider} refused it (status {$status}): {$what}";
    }
}

if (!function_exists('hod_mail_api_post')) {
    /**
     * POST one JSON body to a mail API over HTTPS (this host blocks outgoing
     * SMTP). Never logs the headers: they carry the key.
     * @return array{ok: bool, error: ?string, message_id: ?string}
     */
    function hod_mail_api_post(string $provider, string $url, array $headers, array $body, ?string $messageId): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => "[permanent] The message could not be encoded for {$provider}.", 'message_id' => $messageId];
        }
        $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
        $status = 0;
        $raw = '';
        $netError = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $raw = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status === 0) {
                $netError = curl_error($ch);
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
                'timeout' => 20, 'ignore_errors' => true,
            ]]);
            $raw = (string) @file_get_contents($url, false, $ctx);
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $hit)) {
                    $status = (int) $hit[1];
                }
            }
            if ($status === 0) {
                $netError = 'no answer';
            }
        }
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'error' => null, 'message_id' => $messageId];
        }
        $error = $status === 0
            ? "Could not connect to {$provider}: " . $netError
            : hod_mail_api_error($provider, $status, $raw);
        error_log("[mailer] {$provider} send failed: " . $error);
        return ['ok' => false, 'error' => $error, 'message_id' => $messageId];
    }
}

if (!function_exists('hod_zepto_send')) {
    /**
     * Send one message through the ZeptoMail API over HTTPS.
     * @return array{ok: bool, error: ?string, message_id: ?string}
     */
    function hod_zepto_send(array $s, array $m): array
    {
        return hod_mail_api_post('ZeptoMail', $s['api_url'], ['Authorization: Zoho-enczapikey ' . $s['api_key']],
            hod_zepto_payload($s, $m), isset($m['message_id']) ? (string) $m['message_id'] : null);
    }
}

if (!function_exists('hod_mail_backup_settings')) {
    /**
     * The backup for account mail: Brevo's API (owner, 26 Nov 2026), used when
     * ZeptoMail cannot take a message (no credit, a bad token, an outage).
     *   ACCOUNT_MAIL_BACKUP_API_KEY  the Brevo API key (xkeysib-…)
     *   ACCOUNT_MAIL_BACKUP_API_URL  default https://api.brevo.com/v3/smtp/email
     * Same sender and reply-to as everything else. Null when not set up.
     */
    function hod_mail_backup_settings(): ?array
    {
        $key = site_env('ACCOUNT_MAIL_BACKUP_API_KEY');
        $s = [
            'api_key' => $key,
            'api_url' => site_env('ACCOUNT_MAIL_BACKUP_API_URL') ?: 'https://api.brevo.com/v3/smtp/email',
        ] + hod_mail_settings();

        return $key !== '' && filter_var($s['from_address'], FILTER_VALIDATE_EMAIL) !== false ? $s : null;
    }
}

if (!function_exists('hod_brevo_payload')) {
    /** The Brevo API body for one message ($m as for hod_zepto_payload()). */
    function hod_brevo_payload(array $s, array $m): array
    {
        $to = ['email' => (string) $m['to_email']];
        if (trim((string) ($m['to_name'] ?? '')) !== '') {
            $to['name'] = (string) $m['to_name'];
        }
        $body = [
            'sender'      => ['email' => $s['from_address'], 'name' => (string) (($m['from_name'] ?? '') ?: $s['from_name'])],
            'to'          => [$to],
            'subject'     => (string) $m['subject'],
            'htmlContent' => (string) $m['html'],
        ];
        if (($m['text'] ?? null) !== null && $m['text'] !== '') {
            $body['textContent'] = (string) $m['text'];
        }
        $replyTo = (string) (($m['reply_to'] ?? '') ?: $s['reply_to']);
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyName = (string) (($m['reply_to'] ?? '') ? ($m['reply_to_name'] ?? '') : $s['reply_to_name']);
            $body['replyTo'] = array_filter(['email' => $replyTo, 'name' => $replyName], static fn ($v) => $v !== '');
        }
        $headers = [];
        foreach ((array) ($m['headers'] ?? []) as $name => $value) {
            if (hod_mail_header_ok((string) $name, (string) $value)) {
                $headers[(string) $name] = (string) $value;
            }
        }
        if ($headers !== []) {
            $body['headers'] = $headers;
        }
        return $body;
    }
}

if (!function_exists('hod_brevo_send')) {
    /** Send one message through Brevo's API over HTTPS. @return array{ok: bool, error: ?string, message_id: ?string} */
    function hod_brevo_send(array $s, array $m): array
    {
        return hod_mail_api_post('Brevo', $s['api_url'], ['api-key: ' . $s['api_key']],
            hod_brevo_payload($s, $m), isset($m['message_id']) ? (string) $m['message_id'] : null);
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
