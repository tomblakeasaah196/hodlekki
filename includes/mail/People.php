<?php

namespace HOD\Mail;

/**
 * People as Mail sees them: hodlekki's users (int id; account_status 'active' / 'suspended' / 'revoked'), their
 * name from first_name and last_name, and their roles read live (never $_SESSION['active_role'], which the member
 * switches themself), frozen rows left out as api/auth_api.php does.
 */
final class People
{
    private static array $cache = [];

    /** @return array{id: int, name: string, first_name: string, last_name: string, email: string, active: bool}|null */
    public static function find(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        if (array_key_exists('u' . $userId, self::$cache)) {
            return self::$cache['u' . $userId];
        }
        $row = Db::one(
            "SELECT id, first_name, last_name, email, COALESCE(account_status, 'active') AS account_status FROM users WHERE id = ?",
            [$userId],
        );

        return self::$cache['u' . $userId] = $row ? self::shape($row) : null;
    }

    public static function name(?int $userId): string
    {
        $p = $userId ? self::find($userId) : null;

        return $p['name'] ?? 'Someone';
    }

    public static function firstName(?int $userId): string
    {
        $p = $userId ? self::find($userId) : null;

        return $p ? ($p['first_name'] !== '' ? $p['first_name'] : $p['name']) : 'Someone';
    }

    public static function isActive(int $userId): bool
    {
        return (bool) (self::find($userId)['active'] ?? false);
    }

    /** @return list<string> role names held now (not frozen) */
    public static function roleNames(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return self::$cache['r' . $userId] ??= array_values(array_map('strval', Db::column(
            'SELECT r.role_name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND COALESCE(ur.is_frozen, 0) = 0',
            [$userId],
        )));
    }

    /** @param list<string> $roleNames @return list<int> active people holding any of these roles now */
    public static function withRoles(array $roleNames): array
    {
        if (!$roleNames) {
            return [];
        }
        $key = 'w' . implode(',', $roleNames);

        return self::$cache[$key] ??= array_values(array_map('intval', Db::column(
            "SELECT DISTINCT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id AND COALESCE(ur.is_frozen, 0) = 0
               JOIN roles r ON r.id = ur.role_id
              WHERE r.role_name IN (" . Db::marks(count($roleNames)) . ") AND COALESCE(u.account_status, 'active') = 'active'
           ORDER BY u.id",
            $roleNames,
        )));
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function shape(array $row): array
    {
        $first = trim((string) $row['first_name']);
        $last = trim((string) $row['last_name']);
        $name = trim($first . ' ' . $last);

        return [
            'id' => (int) $row['id'],
            'name' => $name !== '' ? $name : (string) $row['email'],
            'first_name' => $first,
            'last_name' => $last,
            'email' => (string) $row['email'],
            'active' => strtolower((string) $row['account_status']) === 'active',
        ];
    }
}
