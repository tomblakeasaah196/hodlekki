<?php

namespace HOD\Mail\Compose;

use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\Mime\Headers;
use HOD\Mail\People;

/**
 * Addresses in the composer: reading what a person typed or pasted ("Ada Obi <ada@example.org>, bola@x.org"), and
 * suggestions while they type: the church's mailboxes and its active people first, then people in mail the person
 * can read (MailAccess), most recent first. Members' own addresses are suggested by name only to someone who can
 * already send from a mailbox (the composer is closed to everyone else).
 */
final class Recipients
{
    /**
     * @param string|list<string|array{name?: ?string, email: string}> $input
     * @return array{valid: list<array{name: ?string, email: string}>, invalid: list<string>}
     */
    public static function parse(string|array $input): array
    {
        $items = is_array($input) ? $input : preg_split('/[,;\n]+(?=(?:[^"]*"[^"]*")*[^"]*$)/', $input);
        $valid = [];
        $invalid = [];
        $seen = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $one = ['name' => self::name($item['name'] ?? null), 'email' => strtolower(trim((string) ($item['email'] ?? '')))];
                $shown = (string) ($item['email'] ?? '');
            } else {
                $item = trim((string) $item);
                if ($item === '') {
                    continue;
                }
                $parsed = Headers::addresses($item)[0] ?? null;
                $one = $parsed ? ['name' => self::name($parsed['name'] ?? null), 'email' => strtolower(trim((string) $parsed['email']))] : ['name' => null, 'email' => $item];
                $shown = $item;
            }
            if ($one['email'] === '' || !filter_var($one['email'], FILTER_VALIDATE_EMAIL)) {
                if (trim($shown) !== '') {
                    $invalid[] = mb_substr(trim($shown), 0, 120);
                }
                continue;
            }
            if (isset($seen[$one['email']])) {
                continue;
            }
            $seen[$one['email']] = true;
            $valid[] = $one;
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /** "Ada Obi <ada@example.org>" or the bare address */
    public static function format(array $address): string
    {
        $name = self::name($address['name'] ?? null);

        return $name ? "{$name} <{$address['email']}>" : (string) $address['email'];
    }

    /** @param list<array{name: ?string, email: string}> $list everyone in $list not in $without (by address) */
    public static function without(array $list, array $without): array
    {
        $drop = array_map('strtolower', array_map(fn ($a) => is_array($a) ? (string) $a['email'] : (string) $a, $without));

        return array_values(array_filter($list, fn ($a) => !in_array(strtolower($a['email']), $drop, true)));
    }

    /** @return list<array{name: ?string, email: string, kind: 'mailbox'|'person'|'contact'}> */
    public static function suggest(int $userId, string $query, ?int $limit = null): array
    {
        $limit ??= (int) ComposeConfig::get('suggestions', 8);
        $query = trim($query);
        if (mb_strlen($query) < 2 || !MailAccess::accountIds($userId, 'send')) {
            return [];
        }
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $out = [];

        // The church's own mailboxes (shared ones by their name, personal ones by their holder)
        foreach (Db::all(
            "SELECT email, kind, display_name, owner_user_id FROM mail_accounts
              WHERE status IN ('active', 'needs_reset') AND (email LIKE ? OR display_name LIKE ?)
           ORDER BY kind = 'personal', email LIMIT " . (int) $limit,
            [$like, $like],
        ) as $a) {
            $personal = $a['kind'] === 'personal';
            $name = $personal && $a['owner_user_id'] ? People::name((int) $a['owner_user_id']) : (string) $a['display_name'];
            $out[strtolower($a['email'])] = ['name' => self::name($name), 'email' => strtolower($a['email']), 'kind' => $personal ? 'person' : 'mailbox'];
        }

        // Active people by name: their personal mailbox if they have one, else their sign-in address
        $people = Db::all(
            "SELECT u.id, u.email, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name,
                    (SELECT a.email FROM mail_accounts a WHERE a.kind = 'personal' AND a.owner_user_id = u.id AND a.status = 'active' LIMIT 1) AS mailbox
               FROM users u
              WHERE COALESCE(u.account_status, 'active') = 'active'
                AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?)
           ORDER BY name LIMIT " . (int) $limit,
            [$like, $like, $like, $like],
        );
        foreach ($people as $s) {
            $email = strtolower((string) ($s['mailbox'] ?: $s['email']));
            if (!isset($out[$email]) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = ['name' => self::name($s['name']), 'email' => $email, 'kind' => 'person'];
            }
        }

        // People in mail this person can read, most recent first
        $accountIds = MailAccess::accountIds($userId, 'read');
        if ($accountIds && count($out) < $limit) {
            $contacts = Db::all(
                'SELECT from_email, MAX(from_name) AS from_name, MAX(received_at) AS last_at FROM mail_messages
                  WHERE account_id IN (' . Db::marks(count($accountIds)) . ') AND is_outbound = 0 AND from_email IS NOT NULL
                    AND (from_email LIKE ? OR from_name LIKE ?)
               GROUP BY from_email ORDER BY last_at DESC LIMIT ' . (int) ($limit * 2),
                array_merge($accountIds, [$like, $like]),
            );
            foreach ($contacts as $c) {
                $email = strtolower((string) $c['from_email']);
                if (!isset($out[$email]) && filter_var($email, FILTER_VALIDATE_EMAIL) && !preg_match('/(^|[.-])no-?reply|mailer-daemon|postmaster@/i', $email)) {
                    $out[$email] = ['name' => self::name($c['from_name']), 'email' => $email, 'kind' => 'contact'];
                }
            }
        }

        return array_slice(array_values($out), 0, $limit);
    }

    private static function name(?string $name): ?string
    {
        $name = trim(str_replace(['"', "\r", "\n", '<', '>'], '', (string) $name));

        return $name === '' ? null : mb_substr($name, 0, 120);
    }
}
