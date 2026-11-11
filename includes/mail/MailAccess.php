<?php

namespace HOD\Mail;

/**
 * Who may do what in Mail (docs/MAIL.md, "Access"). Every screen, endpoint, job and query asks here, through ONE
 * decision: MailAccess::decide() (procedural front: mail_can() in functions.php). It names no role, seat or
 * department: abilities come from the permissions matrix (can() and its resolver, includes/permissions.php), and
 * who works from a shared mailbox comes from mail_grants, which hold role ids, department ids and stable seat-type
 * keys (perm_seat_types), resolved live.
 *
 * Abilities (modules registered in includes/permissions/modules.php):
 *   admin            can('mail', 'manage')                  the Mailboxes screen; never reading mail
 *   hold_personal    can('mail.personal', 'view')           may hold a personal mailbox
 *   ai               can('mail.ai', 'view')                 may use AI summaries and drafts
 *   export_admin     can('mail.export_admin', 'approve')    one side of a departed holder's export
 *   export_pastoral  can('mail.export_pastoral', 'approve') the other side; break-glass does NOT count here, so
 *                                                           one Super Admin can never stand in for both sides
 *
 * Actions on a mailbox, lowest first: read, send (= organise; also replies, files, changes status), assign, manage
 * (the mailbox's own settings). A personal mailbox opens to its holder only ('owner'), while they still hold
 * mail.personal; never to anyone else, mail admins and break-glass included.
 */
final class MailAccess
{
    public const RANK = ['read' => 1, 'send' => 2, 'assign' => 3, 'manage' => 4, 'owner' => 5];

    public const LEVELS = ['read', 'send', 'assign', 'manage'];

    public const LEVEL_LABELS = [
        'read' => 'Read', 'send' => 'Read and send', 'assign' => 'Read, send and assign', 'manage' => 'Manage', 'owner' => 'Own mailbox',
    ];

    /** ability => [module key, level, break-glass counts?]. tests/mail checks every key exists in the registry. */
    public const ABILITIES = [
        'admin' => ['mail', 'manage', true],
        'hold_personal' => ['mail.personal', 'view', true],
        'ai' => ['mail.ai', 'view', true],
        'export_admin' => ['mail.export_admin', 'approve', true],
        'export_pastoral' => ['mail.export_pastoral', 'approve', false],
    ];

    private const ACTION_RANK = ['read' => 1, 'send' => 2, 'organise' => 2, 'assign' => 3, 'manage' => 4];

    /** Mailbox states in which people may open it */
    public const OPEN_STATES = ['active', 'needs_reset'];

    private static array $cache = [];

    /**
     * THE access decision. $action is an ability above (no mailbox) or a mailbox action (needs the mailbox row).
     */
    public static function decide(?int $userId, string $action, ?array $account = null): bool
    {
        if ($userId === null || $userId <= 0 || !People::isActive($userId)) {
            return false;
        }
        if (isset(self::ABILITIES[$action])) {
            return self::$cache['a' . $userId . ':' . $action] ??= self::ability($userId, ...self::ABILITIES[$action]);
        }
        if ($account === null || !isset(self::ACTION_RANK[$action])) {
            return false;
        }
        $level = self::level($userId, $account);

        return $level !== null && self::RANK[$level] >= self::ACTION_RANK[$action];
    }

    /** 'owner', 'manage', 'assign', 'send', 'read' or null */
    public static function level(?int $userId, array $account): ?string
    {
        if ($userId === null || $userId <= 0 || !in_array($account['status'] ?? '', self::OPEN_STATES, true)) {
            return null;
        }
        $key = 'l' . $userId . ':' . $account['id'];
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        if (!People::isActive($userId)) {
            return self::$cache[$key] = null;
        }
        if (!Store::isShared($account)) {
            $owner = (int) ($account['owner_user_id'] ?? 0) === $userId && self::decide($userId, 'hold_personal');

            return self::$cache[$key] = $owner ? 'owner' : null;
        }

        return self::$cache[$key] = self::grantLevel($userId, (int) $account['id']);
    }

    public static function can(?int $userId, array $account, string $action): bool
    {
        return self::decide($userId, $action, $account);
    }

    /** @throws MailError 403 */
    public static function authorize(?int $userId, array $account, string $action): void
    {
        if (!self::decide($userId, $action, $account)) {
            throw MailError::denied();
        }
    }

    public static function allows(?int $userId, string $ability): bool
    {
        return self::decide($userId, $ability);
    }

    public static function canThread(?int $userId, array $thread, string $action): bool
    {
        $account = Store::account((int) $thread['account_id']);

        return $account !== null && self::decide($userId, $action, $account);
    }

    public static function canMessage(?int $userId, array $message, string $action): bool
    {
        $account = Store::account((int) $message['account_id']);

        return $account !== null && self::decide($userId, $action, $account);
    }

    /**
     * The mailboxes this person can open: their own first, then shared ones by address. Each row carries
     * 'access_level' (not a column: never save it back).
     *
     * @return list<array>
     */
    public static function accounts(?int $userId, string $action = 'read'): array
    {
        if ($userId === null || $userId <= 0) {
            return [];
        }
        $rows = Store::accounts(
            'status IN (' . Db::marks(count(self::OPEN_STATES)) . ") AND (kind <> 'personal' OR owner_user_id = ?)",
            array_merge(self::OPEN_STATES, [$userId]),
        );
        $out = [];
        foreach ($rows as $a) {
            if (self::decide($userId, $action, $a)) {
                $a['access_level'] = self::level($userId, $a);
                $out[] = $a;
            }
        }
        usort($out, fn ($a, $b) => [Store::isShared($a) ? 1 : 0, $a['email']] <=> [Store::isShared($b) ? 1 : 0, $b['email']]);

        return $out;
    }

    /** @return list<int> */
    public static function accountIds(?int $userId, string $action = 'read'): array
    {
        return array_map(fn ($a) => (int) $a['id'], self::accounts($userId, $action));
    }

    /**
     * Active people who can open a mailbox now (new-mail alerts, assignee pickers, the nightly snapshot).
     * @return array<int, string> user id => level
     */
    public static function readers(array $account): array
    {
        $candidates = Store::isShared($account)
            ? self::grantCandidates((int) $account['id'])
            : [(int) ($account['owner_user_id'] ?? 0)];
        $out = [];
        foreach (array_unique($candidates) as $id) {
            $level = $id > 0 ? self::level($id, $account) : null;
            if ($level !== null) {
                $out[$id] = $level;
            }
        }
        ksort($out);

        return $out;
    }

    /** Who is told when a mailbox needs attention (password, storage, jobs): the mail admins. @return list<int> */
    public static function mailAdmins(): array
    {
        $pdo = Db::pdo();
        if (!function_exists('perm_holders')) {
            return [];
        }
        $out = array_values(array_filter(
            perm_holders($pdo, 'mail', 'manage', null, true),
            fn ($id) => self::decide((int) $id, 'admin'),
        ));
        sort($out);

        return $out;
    }

    public static function flush(): void
    {
        self::$cache = [];
        People::flush();
        MailSettings::flush();
        if (function_exists('perm_reset')) {
            perm_reset();
        }
    }

    // ------------------------------------------------------------------ the permissions engine

    /** One matrix ability for any person (the signed-in one, or someone else from cron) */
    private static function ability(int $userId, string $module, string $level, bool $breakGlass): bool
    {
        if (!function_exists('perm_resolve')) {
            return false;
        }
        $pdo = Db::pdo();
        $sessionUser = (int) ($_SESSION['user_id'] ?? 0);
        $ctx = $userId === $sessionUser ? perm_current() : (self::$cache['ctx' . $userId] ??= perm_resolve($pdo, $userId));
        if ($ctx === null) {
            return false;
        }
        if (perm_ctx_can($ctx, $module, $level)) {
            return true;
        }
        if ($breakGlass && !empty($ctx['breakglass'])) {
            if ($userId === $sessionUser) {
                perm_breakglass_note($module, $level);
            }

            return true;
        }

        return false;
    }

    /** The highest level this person's grants give on a shared mailbox, or null */
    private static function grantLevel(int $userId, int $accountId): ?string
    {
        $grants = Db::all('SELECT subject_type, subject_id, seat_type, level FROM mail_grants WHERE account_id = ?', [$accountId]);
        if (!$grants || !function_exists('perm_person_roles')) {
            return null;
        }
        $pdo = Db::pdo();
        $roles = self::$cache['roles' . $userId] ??= array_keys(perm_person_roles($pdo, $userId, perm_today()));
        $seats = self::$cache['seats' . $userId] ??= perm_person_seats($pdo, $userId);

        $best = null;
        foreach ($grants as $g) {
            $id = (int) $g['subject_id'];
            $match = match ($g['subject_type']) {
                'user' => $id === $userId,
                'role' => in_array($id, $roles, true),
                'department' => (bool) array_filter($seats, fn ($s) => (int) $s['department_id'] === $id),
                'seat' => (bool) array_filter($seats, fn ($s) => (int) $s['department_id'] === $id && $s['seat_key'] === $g['seat_type']),
                default => false,
            };
            if ($match && ($best === null || self::RANK[$g['level']] > self::RANK[$best])) {
                $best = $g['level'];
            }
        }

        return $best;
    }

    /** Everyone a shared mailbox's grants could reach (checked one by one by readers()) @return list<int> */
    private static function grantCandidates(int $accountId): array
    {
        if (!function_exists('perm_seat_holders')) {
            return [];
        }
        $pdo = Db::pdo();
        $ids = [];
        foreach (Db::all('SELECT subject_type, subject_id, seat_type FROM mail_grants WHERE account_id = ?', [$accountId]) as $g) {
            $id = (int) $g['subject_id'];
            switch ($g['subject_type']) {
                case 'user':
                    $ids[] = $id;
                    break;
                case 'role':
                    $ids = array_merge($ids, array_map('intval', Db::column(
                        'SELECT user_id FROM user_roles WHERE role_id = ? AND COALESCE(is_frozen, 0) = 0',
                        [$id],
                    )));
                    break;
                case 'seat':
                    $ids = array_merge($ids, perm_seat_holders($pdo, (string) $g['seat_type'], $id));
                    break;
                case 'department':
                    $ids = array_merge($ids, array_column(perm_live_seats($pdo, null, perm_today(), $id), 'user_id'));
                    break;
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
