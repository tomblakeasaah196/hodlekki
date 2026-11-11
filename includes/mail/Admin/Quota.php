<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailError;
use HOD\Mail\MailSettings;
use Throwable;

/**
 * How many mailboxes hodlekki.org holds (owner: at most 15 of the account's 50), from cPanel itself so mailboxes
 * made by hand count too. The web never calls cPanel: the cron reads the count (refresh()) every few minutes and
 * whenever a job is queued, and keeps it in mail_settings 'quota.server'.
 *
 * Checked twice: when an action is queued (the cached count plus creates already queued, so two admins cannot
 * both take the last slot), and again in the job, just before add_pop, with a fresh count. Jobs run one at a time
 * (the cron's lock). `quota.reserved_personal` (lead: 10 for now) keeps slots for personal mailboxes: a department
 * or church mailbox is refused while it would use one.
 */
final class Quota
{
    /**
     * @return array{domain_used: int, domain_quota: int, domain_left: int, reserved_personal: int, account_used: ?int,
     *               account_max: ?int, queued_creates: int, read_at: ?string, addresses: list<string>}
     */
    public static function snapshot(): array
    {
        $snap = (array) MailSettings::get('quota.server', []);
        $used = (int) ($snap['domain_used'] ?? Db::value("SELECT COUNT(*) FROM mail_accounts WHERE status <> 'failed'"));
        $quota = (int) Config::get('domain_quota', 15);

        return [
            'domain_used' => $used,
            'domain_quota' => $quota,
            'domain_left' => max(0, $quota - $used),
            'reserved_personal' => self::reservedPersonal(),
            'account_used' => isset($snap['account_used']) ? (int) $snap['account_used'] : null,
            'account_max' => isset($snap['account_max']) ? (int) $snap['account_max'] : null,
            'queued_creates' => self::queuedCreates(),
            'read_at' => $snap['read_at'] ?? null,
            'addresses' => array_values((array) ($snap['addresses'] ?? [])),
        ];
    }

    public static function reservedPersonal(): int
    {
        return max(0, (int) MailSettings::get('quota.reserved_personal', 0));
    }

    /** @throws MailError when a new mailbox of this kind would not fit (checked when queuing) */
    public static function assertRoom(string $kind): void
    {
        $s = self::snapshot();
        self::judge($kind, $s['domain_used'] + $s['queued_creates'], $s['domain_quota'], $s['account_used'] !== null
            ? $s['account_used'] + $s['queued_creates'] : null, $s['account_max'], $s['reserved_personal']);
    }

    /** The rule itself, pure (tests): @throws MailError */
    public static function judge(string $kind, int $domainUsed, int $domainQuota, ?int $accountUsed, ?int $accountMax, int $reservedPersonal): void
    {
        $domain = (string) Config::get('domain');
        if ($domainUsed >= $domainQuota) {
            throw MailError::invalid("{$domain} already has {$domainUsed} of its {$domainQuota} mailboxes. Remove one, or use a forwarder, before adding another.");
        }
        if ($accountMax !== null && $accountMax > 0 && $accountUsed !== null && $accountUsed >= $accountMax) {
            throw MailError::invalid("The hosting account holds {$accountUsed} of its {$accountMax} mailboxes across all its sites. Remove one before adding another.");
        }
        $free = $domainQuota - $domainUsed;
        if ($kind !== 'personal' && $free <= $reservedPersonal) {
            throw MailError::invalid("The last {$reservedPersonal} mailbox slots are kept for personal mailboxes. A mail admin can change that number on the Mailboxes screen.");
        }
    }

    /**
     * Read the counts from cPanel (cron only) and keep them. @return array the stored snapshot
     */
    public static function refresh(): array
    {
        $domain = (string) Config::get('domain');
        $pops = (array) Uapi::make()->call('Email', 'list_pops_with_disk', ['domain' => $domain]);
        $addresses = [];
        $disk = [];
        foreach ($pops as $p) {
            $email = strtolower((string) ($p['email'] ?? $p['login'] ?? ''));
            if (!str_ends_with($email, '@' . $domain)) {
                continue;
            }
            $addresses[] = $email;
            $disk[$email] = [
                'used_mb' => (int) round((float) ($p['diskused'] ?? 0)),
                'quota_mb' => is_numeric($p['diskquota'] ?? null) ? (int) $p['diskquota'] : null,
                'suspended_login' => (int) ($p['suspended_login'] ?? 0) === 1,
            ];
        }
        $forwarders = [];
        try {
            foreach ((array) Uapi::make()->call('Email', 'list_forwarders', ['domain' => $domain]) as $f) {
                $dest = strtolower((string) ($f['dest'] ?? ''));
                if (str_ends_with($dest, '@' . $domain)) {
                    $forwarders[] = $dest;
                }
            }
        } catch (Throwable $e) {
            error_log('Mail: forwarders not read: ' . $e->getMessage());
        }
        $accountUsed = null;
        $accountMax = null;
        try {
            foreach ((array) Uapi::make()->call('StatsBar', 'get_stats', ['display' => 'emailaccounts']) as $row) {
                if (($row['name'] ?? $row['id'] ?? '') === 'emailaccounts') {
                    $accountUsed = isset($row['count']) ? (int) $row['count'] : null;
                    $accountMax = isset($row['max']) && is_numeric($row['max']) ? (int) $row['max'] : null;
                }
            }
        } catch (Throwable $e) {
            error_log('Mail: account mailbox count not read: ' . $e->getMessage());
        }
        sort($addresses);
        $snap = [
            'domain_used' => count($addresses),
            'addresses' => $addresses,
            'forwarders' => array_values(array_unique($forwarders)),
            'disk' => $disk,
            'account_used' => $accountUsed,
            'account_max' => $accountMax,
            'read_at' => Clock::sql(),
        ];
        MailSettings::set('quota.server', $snap);

        return $snap;
    }

    public static function due(): bool
    {
        $snap = (array) MailSettings::get('quota.server', []);
        $age = Clock::ageSeconds($snap['read_at'] ?? null);

        return $age === null || $age > 60 * (int) Config::get('jobs.quota_every', 5)
            || (bool) Db::value("SELECT 1 FROM mail_admin_jobs WHERE status = 'queued' LIMIT 1");
    }

    private static function queuedCreates(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM mail_admin_jobs WHERE action = 'create' AND status IN ('queued', 'running')");
    }
}
