<?php

namespace HOD\Mail;

use HOD\Mail\Imap\ImapClient;
use HOD\Mail\Imap\ImapException;
use HOD\Mail\Imap\ImapSession;
use HOD\Mail\Imap\SocketImapSession;
use HOD\Mail\Mime\Message;
use HOD\Mail\Smtp\SmtpClient;

/**
 * The one door to the mail server: IMAP sessions and SMTP. Everything goes through MailServer::make(), so tests
 * swap in tests/mail/support/FakeMailServer.php with MailServer::use().
 *
 * The server is fixed (Config 'server': the server's own name, srv-web-ns9.newtoncorp.fr, IMAP 993 and SMTP 465, both TLS). Nothing here takes a
 * host, a port or a password from a request: there is no way for a person to point the ERP at another machine.
 *
 * Passwords come from the vault. While a password change is half done (Lifecycle stores the new one as
 * secret_pending before cPanel takes it), a refused login tries the pending one and, when it works, makes it
 * the current one: a rotation never locks the ERP out of a mailbox.
 *
 * Throws Imap\ImapException (IMAP) and MailSendException (SMTP).
 */
class MailServer
{
    private static ?MailServer $instance = null;

    public static function make(): MailServer
    {
        return self::$instance ??= new MailServer();
    }

    /** Tests: put a fake in (or null to go back to the real server) */
    public static function use(?MailServer $server): void
    {
        self::$instance = $server;
    }

    /** A signed-in session on a mailbox (an account row; the password is read here, never passed around) */
    public function open(array $account): ImapSession
    {
        $full = Store::accountWithSecret((int) $account['id']) ?? throw MailError::notFound();

        return self::withPassword($full, fn (string $password) => $this->connect($full['email'], $password));
    }

    /** A signed-in session with this address and password (the admin jobs prove a new password this way) */
    public function connect(string $username, string $password): ImapSession
    {
        $s = self::settings();
        $client = new ImapClient(
            $s['imap_host'],
            (int) $s['imap_port'],
            $s['imap_encryption'],
            (int) Config::get('timeout', 20),
            (bool) Config::get('verify_tls', true),
        );
        $client->connect();
        $client->login($username, $password);

        return new SocketImapSession($client);
    }

    /**
     * Send through the mailbox's own SMTP login. From must already be set (MailTransport sets the mailbox);
     * the envelope is To, Cc and Bcc. @return string the message exactly as sent (no Bcc), for the Sent folder
     */
    public function send(array $account, Message $message): string
    {
        $full = Store::accountWithSecret((int) $account['id']) ?? throw MailError::notFound();
        $raw = $message->toString();
        $recipients = $message->recipients();

        self::withPassword($full, function (string $password) use ($full, $raw, $recipients): bool {
            $this->smtpSend($full['email'], $password, $recipients, $raw);

            return true;
        });

        return $raw;
    }

    /** @return array{imap_host: string, imap_port: int, imap_encryption: string, smtp_host: string, smtp_port: int, smtp_encryption: string} */
    public static function settings(): array
    {
        return (array) Config::get('server', []);
    }

    /**
     * Run $use with the mailbox's password; if the server refuses it and a newer one is waiting (a rotation the
     * cron did not finish recording), try that one and keep it.
     */
    private static function withPassword(array $full, callable $use): mixed
    {
        $id = (int) $full['id'];
        $current = Vault::decrypt($full['secret'] ?? null, $id, $full['email']);
        $pending = Vault::decrypt($full['secret_pending'] ?? null, $id, $full['email']);
        if ($current === null && $pending === null) {
            throw new ImapException('The stored password for this mailbox cannot be read. A mail admin can reset it on the Mailboxes screen.', 'auth');
        }

        try {
            return $use($current ?? $pending);
        } catch (ImapException|MailSendException $e) {
            if ($e->reason !== 'auth' || $current === null || $pending === null) {
                throw $e;
            }
            $result = $use($pending);
            Db::exec(
                'UPDATE mail_accounts SET secret = secret_pending, secret_pending = NULL, updated_at = ? WHERE id = ? AND secret_pending = ?',
                [Clock::sql(), $id, $full['secret_pending']],
            );

            return $result;
        }
    }

    /** One SMTP conversation: sign in as the mailbox, send, leave (tests replace it) */
    protected function smtpSend(string $username, string $password, array $recipients, string $raw): void
    {
        $smtp = $this->smtp();
        try {
            $smtp->connect();
            $smtp->login($username, $password);
            $smtp->send($username, $recipients, $raw);
        } finally {
            $smtp->quit();
        }
    }

    private function smtp(): SmtpClient
    {
        $s = self::settings();

        return new SmtpClient(
            $s['smtp_host'],
            (int) $s['smtp_port'],
            $s['smtp_encryption'],
            (int) Config::get('timeout', 20),
            (bool) Config::get('verify_tls', true),
            (string) Config::get('local_domain', 'hodlekki.org'),
        );
    }
}
