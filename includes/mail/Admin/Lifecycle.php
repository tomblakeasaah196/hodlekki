<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailNotices;
use HOD\Mail\People;
use HOD\Mail\Store;
use HOD\Mail\Sync\MailSync;
use HOD\Mail\Vault;

/**
 * Mailboxes from birth to removal (docs/MAIL.md, "Lifecycle"). Two halves:
 *
 *   requests (web, a mail admin)   check, validate, check the quota, write the row, queue a job
 *   handle()  (cron)               carry the job out with `uapi`
 *
 * Passwords: made only inside handle(), stored encrypted as secret_pending BEFORE cPanel takes them, moved to
 * secret once cPanel says yes (MailServer also adopts a pending one that works). Never shown to anyone.
 */
final class Lifecycle
{
    // ------------------------------------------------------------------ requests (web)

    /** A new department or church mailbox. @return array{account: array, job_id: int} */
    public static function requestShared(int $actorId, array $in): array
    {
        self::assertAdmin($actorId);
        $kind = in_array($in['kind'] ?? '', ['department', 'church'], true) ? $in['kind'] : throw MailError::field('kind', 'Choose a department or a church mailbox.');
        $local = Addresses::validate((string) ($in['local_part'] ?? ''), Addresses::onServer());
        $name = self::displayName($in['display_name'] ?? '');
        $deptId = null;
        if ($kind === 'department') {
            $deptId = (int) ($in['department_id'] ?? 0);
            if ($deptId <= 0 || !Db::value('SELECT 1 FROM departments WHERE id = ?', [$deptId])) {
                throw MailError::field('department_id', 'Choose the department this mailbox belongs to.');
            }
        }
        Quota::assertRoom($kind);
        $account = self::insertAccount([
            'kind' => $kind, 'local_part' => $local, 'display_name' => $name, 'department_id' => $deptId,
            'is_pastoral' => !empty($in['is_pastoral']), 'created_by' => $actorId,
            'disk_quota_mb' => (int) Config::get('disk.' . $kind, 2048),
        ]);
        if ($kind === 'department') {
            Grants::applyTemplate($account, $actorId);
        }
        $jobId = Jobs::queue('create', $account, [], $actorId);
        Audit::log('mail_create', "{$account['email']} ({$kind})", $actorId);

        return ['account' => $account, 'job_id' => $jobId];
    }

    /** A personal mailbox for someone who holds mail.personal. @return array{account: array, job_id: int} */
    public static function requestPersonal(int $actorId, int $personId, string $localPart): array
    {
        self::assertAdmin($actorId);
        $person = People::find($personId) ?? throw MailError::field('user_id', 'Choose the person this mailbox is for.');
        if (!$person['active']) {
            throw MailError::field('user_id', "{$person['name']}'s account is not active.");
        }
        if (!MailAccess::allows($personId, 'hold_personal')) {
            throw MailError::field('user_id', "{$person['name']} is not allowed a personal mailbox. Their role or seat needs \"Hold a personal mailbox\" in Roles & permissions first.");
        }
        if (Db::value("SELECT email FROM mail_accounts WHERE kind = 'personal' AND owner_user_id = ? AND status <> 'failed'", [$personId])) {
            throw MailError::field('user_id', "{$person['name']} already has a personal mailbox.");
        }
        $local = Addresses::validate($localPart !== '' ? $localPart : Addresses::suggest($person['first_name'], $person['last_name']), Addresses::onServer());
        Quota::assertRoom('personal');
        $account = self::insertAccount([
            'kind' => 'personal', 'local_part' => $local, 'display_name' => self::displayName($person['name']),
            'owner_user_id' => $personId, 'created_by' => $actorId, 'disk_quota_mb' => (int) Config::get('disk.personal', 1024),
        ]);
        $jobId = Jobs::queue('create', $account, ['notify' => $personId], $actorId);
        Audit::log('mail_issue_personal', $account['email'], $actorId, $personId);

        return ['account' => $account, 'job_id' => $jobId];
    }

    /** Bring a mailbox made in cPanel into the ERP (a new password is set). @return array{account: array, job_id: int} */
    public static function requestBringIn(int $actorId, array $in): array
    {
        self::assertAdmin($actorId);
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $domain = Addresses::domain();
        if (!str_ends_with($email, '@' . $domain) || !in_array($email, Quota::snapshot()['addresses'], true)) {
            throw MailError::field('email', 'Choose a mailbox that exists on the server and is not in the ERP yet.');
        }
        $local = substr($email, 0, -strlen('@' . $domain));
        if (array_key_exists($local, (array) Config::get('outside', []))) {
            throw MailError::field('email', "{$email} stays outside the ERP: " . Config::get('outside.' . $local));
        }
        if (Store::accountByEmail($email)) {
            throw MailError::field('email', "{$email} is already in the ERP.");
        }
        $kind = in_array($in['kind'] ?? '', Store::KINDS, true) ? $in['kind'] : throw MailError::field('kind', 'Choose what kind of mailbox it is.');
        $row = ['kind' => $kind, 'local_part' => $local, 'display_name' => self::displayName($in['display_name'] ?? ''),
            'is_pastoral' => !empty($in['is_pastoral']), 'created_by' => $actorId,
            'disk_quota_mb' => (int) Config::get('disk.' . $kind, 2048)];
        if ($kind === 'personal') {
            $personId = (int) ($in['user_id'] ?? 0);
            if (!People::isActive($personId) || !MailAccess::allows($personId, 'hold_personal')) {
                throw MailError::field('user_id', 'Choose an active person who is allowed a personal mailbox.');
            }
            $row['owner_user_id'] = $personId;
        } elseif ($kind === 'department') {
            $row['department_id'] = (int) ($in['department_id'] ?? 0) ?: throw MailError::field('department_id', 'Choose the department.');
        }
        $account = self::insertAccount($row);
        $jobId = Jobs::queue('bring_in', $account, ['notify' => $row['owner_user_id'] ?? null], $actorId);
        Audit::log('mail_bring_in', $email, $actorId, $row['owner_user_id'] ?? null);

        return ['account' => $account, 'job_id' => $jobId];
    }

    /** suspend, resume, reset, set_quota, auto_reply_on, auto_reply_off, remove: @return int the job id */
    public static function request(int $actorId, string $action, array $account, array $in = []): int
    {
        self::assertAdmin($actorId);
        $params = [];
        switch ($action) {
            case 'suspend':
                self::needState($account, ['active', 'needs_reset']);
                break;
            case 'resume':
                self::needState($account, ['suspended']);
                break;
            case 'reset':
                self::needState($account, ['active', 'needs_reset', 'suspended']);
                break;
            case 'set_quota':
                $mb = (int) ($in['quota_mb'] ?? 0);
                if ($mb < 250 || $mb > 10240) {
                    throw MailError::field('quota_mb', 'Choose a storage limit between 250 MB and 10 GB.');
                }
                $params['quota_mb'] = $mb;
                break;
            case 'auto_reply_on':
                $params['subject'] = mb_substr(trim((string) ($in['subject'] ?? '')), 0, 150) ?: throw MailError::field('subject', 'Give the automatic reply a subject.');
                $params['body'] = mb_substr(trim((string) ($in['body'] ?? '')), 0, 2000) ?: throw MailError::field('body', 'Write what the automatic reply says.');
                $params['days'] = max(1, min(90, (int) ($in['days'] ?? 30)));
                break;
            case 'auto_reply_off':
                break;
            case 'remove':
                if (strtolower(trim((string) ($in['confirm'] ?? ''))) !== $account['email']) {
                    throw MailError::field('confirm', "Type {$account['email']} to confirm. Removing it deletes its mail on the server.");
                }
                if (Exports::openFor((int) $account['id'])) {
                    throw MailError::invalid('An export request for this mailbox is still open. Settle it first.');
                }
                $forward = strtolower(trim((string) ($in['forward_to'] ?? '')));
                if ($forward !== '' && (!filter_var($forward, FILTER_VALIDATE_EMAIL) || !str_ends_with($forward, '@' . Addresses::domain()))) {
                    throw MailError::field('forward_to', 'Forward only to another address @' . Addresses::domain() . ', or leave it empty.');
                }
                $params['forward_to'] = $forward;
                break;
            default:
                throw MailError::invalid('That action is not available here.');
        }
        $jobId = Jobs::queue($action, $account, $params, $actorId);
        Audit::log('mail_' . $action, $account['email'], $actorId, $account['owner_user_id'] ?? null);

        return $jobId;
    }

    // ------------------------------------------------------------------ the cron side

    /** Carry out one job. @return array a small result (never a password) @throws UapiException|MailError */
    public static function handle(array $job): array
    {
        $account = $job['account_id'] ? Store::account($job['account_id']) : null;
        $p = $job['params'];
        $uapi = Uapi::make();
        $domain = Addresses::domain();

        switch ($job['action']) {
            case 'create':
                $account ?? throw MailError::notFound();
                $snap = Quota::refresh();
                if (!in_array($account['email'], $snap['addresses'], true)) {
                    Quota::judge($account['kind'], (int) $snap['domain_used'], (int) Config::get('domain_quota', 15),
                        $snap['account_used'] ?? null, $snap['account_max'] ?? null,
                        $account['kind'] === 'personal' ? 0 : Quota::reservedPersonal());
                    $password = self::stagePassword($account);
                    $uapi->call('Email', 'add_pop', [
                        'email' => $account['local_part'], 'domain' => $domain, 'password' => $password,
                        'quota' => $account['disk_quota_mb'], 'send_welcome_email' => 0,
                    ]);
                } else {
                    // a retry after a crash: the mailbox exists, so set the password again rather than make it twice
                    $password = self::stagePassword($account);
                    $uapi->call('Email', 'passwd_pop', ['email' => $account['email'], 'domain' => $domain, 'password' => $password]);
                }
                self::commitPassword($account, ['status' => 'active']);
                Quota::refresh();
                if (!empty($p['notify'])) {
                    MailNotices::issued(Store::account($account['id']), (int) $p['notify']);
                }
                MailSync::make()->account((int) $account['id'], 15.0, true);

                return ['email' => $account['email']];

            case 'bring_in':
            case 'reset':
                $account ?? throw MailError::notFound();
                $password = self::stagePassword($account);
                $uapi->call('Email', 'passwd_pop', ['email' => $account['email'], 'domain' => $domain, 'password' => $password]);
                $state = $job['action'] === 'reset' && $account['status'] === 'suspended' ? 'suspended' : 'active';
                self::commitPassword($account, ['status' => $state, 'last_error' => null]);
                if ($job['action'] === 'bring_in' && !empty($p['notify'])) {
                    MailNotices::issued(Store::account($account['id']), (int) $p['notify']);
                }
                if ($state === 'active') {
                    MailSync::make()->account((int) $account['id'], 15.0, true);
                }

                return ['email' => $account['email']];

            case 'suspend':
            case 'export_close':
                $account ?? throw MailError::notFound();
                $uapi->call('Email', 'suspend_login', ['email' => $account['email']]);
                if ($job['action'] === 'suspend') {
                    Db::update('mail_accounts', ['status' => 'suspended', 'suspended_by' => $job['requested_by'], 'suspended_at' => Clock::sql(), 'updated_at' => Clock::sql()], $account['id']);
                }

                return [];

            case 'resume':
            case 'export_open':
                $account ?? throw MailError::notFound();
                $uapi->call('Email', 'unsuspend_login', ['email' => $account['email']]);
                if ($job['action'] === 'resume') {
                    Db::update('mail_accounts', ['status' => 'active', 'suspended_by' => null, 'suspended_at' => null, 'updated_at' => Clock::sql()], $account['id']);
                } else {
                    Exports::opened((int) ($p['request_id'] ?? 0));
                }

                return [];

            case 'set_quota':
                $account ?? throw MailError::notFound();
                $uapi->call('Email', 'edit_pop_quota', ['email' => $account['local_part'], 'domain' => $domain, 'quota' => (int) $p['quota_mb']]);
                Db::update('mail_accounts', ['disk_quota_mb' => (int) $p['quota_mb'], 'disk_warned_at' => null, 'updated_at' => Clock::sql()], $account['id']);

                return [];

            case 'auto_reply_on':
                $account ?? throw MailError::notFound();
                self::autoReply($account, (string) $p['subject'], (string) $p['body'], (int) $p['days']);

                return [];

            case 'auto_reply_off':
                $account ?? throw MailError::notFound();
                $uapi->call('Email', 'delete_auto_responder', ['email' => $account['email']]);

                return [];

            case 'leave':
                $account ?? throw MailError::notFound();
                $password = self::stagePassword($account);
                $uapi->call('Email', 'passwd_pop', ['email' => $account['email'], 'domain' => $domain, 'password' => $password]);
                self::commitPassword($account, []);
                $uapi->call('Email', 'suspend_login', ['email' => $account['email']]);
                $days = (int) Config::get('leaving.auto_reply_days', 30);
                $forward = Config::get('leaving.forward_to', 'info') . '@' . $domain;
                self::autoReply($account, 'This address is no longer in use',
                    "Thank you for your email. {$account['email']} is no longer in use at Household of David Lekki. Please write to {$forward} and we will make sure the right person answers you.\n\nGod bless you.",
                    $days);
                Db::update('mail_accounts', [
                    'status' => 'leaving', 'leaving_since' => Clock::sql(), 'remove_after' => Clock::sql(86400 * $days),
                    'suspended_at' => Clock::sql(), 'updated_at' => Clock::sql(),
                ], $account['id']);

                return [];

            case 'remove':
                $email = $account['email'] ?? (string) $job['email'];
                if (in_array($email, Quota::refresh()['addresses'], true)) {
                    $uapi->call('Email', 'delete_pop', ['email' => $email, 'domain' => $domain]);
                }
                $forward = (string) ($p['forward_to'] ?? '');
                if ($forward !== '') {
                    $uapi->call('Email', 'add_forwarder', ['domain' => $domain, 'email' => $email, 'fwdopt' => 'fwd', 'fwdemail' => $forward]);
                }
                if ($account) {
                    Db::exec('DELETE FROM mail_accounts WHERE id = ?', [$account['id']]);
                }
                Quota::refresh();

                return ['email' => $email, 'forwarded_to' => $forward ?: null];

            case 'forwarder_add':
                $uapi->call('Email', 'add_forwarder', ['domain' => $domain, 'email' => (string) $p['from'], 'fwdopt' => 'fwd', 'fwdemail' => (string) $p['to']]);
                Quota::refresh();

                return [];

            case 'forwarder_remove':
                $uapi->call('Email', 'delete_forwarder', ['address' => (string) $p['from'], 'forwarder' => (string) $p['to']]);
                Quota::refresh();

                return [];
        }

        throw MailError::invalid('Unknown job.');
    }

    /** After a failed job: a mailbox that never came to be is marked failed (its row frees no slot on the server) */
    public static function failed(array $job, string $message): void
    {
        if (in_array($job['action'], ['create', 'bring_in'], true) && $job['account_id']) {
            Db::exec("UPDATE mail_accounts SET status = 'failed', secret_pending = NULL, last_error = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
                [mb_substr($message, 0, 500), Clock::sql(), $job['account_id']]);
        }
    }

    /** Queue the "holder left" job once (nightly diff). */
    public static function startLeaving(array $account): void
    {
        if ($account['status'] === 'leaving' || Db::value("SELECT 1 FROM mail_admin_jobs WHERE account_id = ? AND action = 'leave' AND status IN ('queued', 'running')", [$account['id']])) {
            return;
        }
        Jobs::queue('leave', $account, [], null);
        Audit::log('mail_holder_left', $account['email'], null, $account['owner_user_id']);
    }

    // ------------------------------------------------------------------ helpers

    private static function stagePassword(array $account): string
    {
        $password = Vault::newPassword();
        Db::update('mail_accounts', ['secret_pending' => Vault::encrypt($password, (int) $account['id'], $account['email']), 'updated_at' => Clock::sql()], $account['id']);

        return $password;
    }

    private static function commitPassword(array $account, array $fill): void
    {
        Db::exec('UPDATE mail_accounts SET secret = secret_pending, secret_pending = NULL WHERE id = ? AND secret_pending IS NOT NULL', [$account['id']]);
        if ($fill) {
            Db::update('mail_accounts', $fill + ['updated_at' => Clock::sql()], $account['id']);
        }
        Store::bump((int) $account['id']);
    }

    private static function autoReply(array $account, string $subject, string $body, int $days): void
    {
        Uapi::make()->call('Email', 'add_auto_responder', [
            'email' => $account['local_part'], 'domain' => Addresses::domain(), 'from' => $account['display_name'],
            'subject' => $subject, 'body' => $body, 'is_html' => 0, 'interval' => 24,
            'start' => time(), 'stop' => time() + 86400 * $days,
        ]);
    }

    private static function insertAccount(array $row): array
    {
        $now = Clock::sql();
        $row += ['department_id' => null, 'owner_user_id' => null, 'is_pastoral' => false];
        $row['email'] = $row['local_part'] . '@' . Addresses::domain();
        $id = Db::insert('mail_accounts', $row + ['managed' => 1, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);

        return Store::account($id);
    }

    private static function displayName(mixed $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1f"<>]/', '', (string) $name) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw MailError::field('display_name', 'Give the name people will see in the From line (up to 120 characters).');
        }

        return $name;
    }

    private static function needState(array $account, array $states): void
    {
        if (!in_array($account['status'], $states, true)) {
            throw MailError::invalid("{$account['email']} cannot do that while it is " . str_replace('_', ' ', $account['status']) . '.');
        }
        if (Db::value("SELECT 1 FROM mail_admin_jobs WHERE account_id = ? AND status IN ('queued', 'running') LIMIT 1", [$account['id']])) {
            throw MailError::invalid("Another action on {$account['email']} is still waiting for the server. Try again in a minute.");
        }
    }

    public static function assertAdmin(int $actorId): void
    {
        if (!MailAccess::allows($actorId, 'admin')) {
            throw MailError::denied('The Mailboxes screen is open to mail admins only.');
        }
    }
}
