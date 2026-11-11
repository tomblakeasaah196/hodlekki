<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Clock;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailSettings;
use HOD\Mail\People;
use HOD\Mail\Store;

/**
 * Who works from a shared mailbox (mail_grants): matrix roles (by id), department seats (department id + stable
 * seat-type key from perm_seat_types), whole departments, or named people, each at Read / Read and send / Read,
 * send and assign / Manage. Data, edited by mail admins; MailAccess resolves it live. No name of a role, seat or
 * department appears in code: the department template below holds seat-type KEYS, and lives in mail_settings.
 */
final class Grants
{
    /** Offered for a new department mailbox until a mail admin changes 'grants.department_template' */
    private const DEFAULT_TEMPLATE = [
        ['seat_type' => 'pastor_in_charge', 'level' => 'manage'],
        ['seat_type' => 'director', 'level' => 'manage'],
        ['seat_type' => 'hod', 'level' => 'assign'],
        ['seat_type' => 'deputy_hod', 'level' => 'assign'],
        ['seat_type' => 'worker', 'level' => 'send'],
    ];

    /** @return list<array{subject_type, subject_id, seat_type, level, label}> */
    public static function list(int $accountId): array
    {
        $out = [];
        foreach (Db::all('SELECT subject_type, subject_id, seat_type, level FROM mail_grants WHERE account_id = ? ORDER BY subject_type, subject_id, seat_type', [$accountId]) as $g) {
            $g['subject_id'] = (int) $g['subject_id'];
            $g['label'] = self::label($g);
            $out[] = $g;
        }

        return $out;
    }

    /** Replace a mailbox's grants. @param list<array> $grants */
    public static function set(int $actorId, array $account, array $grants): void
    {
        Lifecycle::assertAdmin($actorId);
        if (!Store::isShared($account)) {
            throw MailError::invalid('A personal mailbox opens to its holder only.');
        }
        $rows = [];
        foreach ($grants as $g) {
            $row = self::clean($g, (int) $account['id'], $actorId);
            $rows[$row['subject_type'] . ':' . $row['subject_id'] . ':' . $row['seat_type']] = $row;
        }
        $before = self::list((int) $account['id']);
        Db::tx(function () use ($account, $rows) {
            Db::exec('DELETE FROM mail_grants WHERE account_id = ?', [$account['id']]);
            Db::insertMany('mail_grants', array_values($rows));
        });
        MailAccess::flush();
        Audit::log('mail_grants', $account['email'] . ': ' . json_encode(array_map(fn ($g) => $g['label'] . ' ' . $g['level'], $before)) . ' -> '
            . json_encode(array_map(fn ($g) => self::label($g) . ' ' . $g['level'], array_values($rows))), $actorId);
    }

    public static function applyTemplate(array $account, int $actorId): void
    {
        $template = MailSettings::get('grants.department_template', self::DEFAULT_TEMPLATE);
        $known = self::seatTypes();
        $rows = [];
        foreach ((array) $template as $t) {
            if (isset($known[$t['seat_type'] ?? '']) && in_array($t['level'] ?? '', MailAccess::LEVELS, true)) {
                $rows[] = ['account_id' => (int) $account['id'], 'subject_type' => 'seat', 'subject_id' => (int) $account['department_id'],
                    'seat_type' => $t['seat_type'], 'level' => $t['level'], 'created_by' => $actorId, 'created_at' => Clock::sql()];
            }
        }
        Db::insertMany('mail_grants', $rows);
    }

    /** What the grant editor offers: roles, departments, seat types @return array */
    public static function options(): array
    {
        return [
            'roles' => array_map(fn ($r) => ['id' => (int) $r['id'], 'label' => str_replace('_', ' ', (string) $r['role_name'])],
                Db::all('SELECT id, role_name FROM roles WHERE COALESCE(is_archived, 0) = 0 ORDER BY role_name')),
            'departments' => array_map(fn ($d) => ['id' => (int) $d['id'], 'label' => (string) $d['name']],
                Db::all('SELECT id, name FROM departments ORDER BY name')),
            'seats' => array_map(fn ($k, $t) => ['key' => $k, 'label' => (string) $t['label']], array_keys(self::seatTypes()), self::seatTypes()),
            'levels' => MailAccess::LEVEL_LABELS,
        ];
    }

    /** @return array<string, array> live seat types by key */
    private static function seatTypes(): array
    {
        return array_filter(function_exists('perm_seat_types') ? perm_seat_types(Db::pdo()) : [], fn ($t) => (int) ($t['is_archived'] ?? 0) === 0);
    }

    private static function clean(array $g, int $accountId, int $actorId): array
    {
        $type = (string) ($g['subject_type'] ?? '');
        $id = (int) ($g['subject_id'] ?? 0);
        $seat = (string) ($g['seat_type'] ?? '');
        $level = (string) ($g['level'] ?? '');
        if (!in_array($level, MailAccess::LEVELS, true)) {
            throw MailError::field('grants', 'Choose a level for every line.');
        }
        $ok = match ($type) {
            'role' => (bool) Db::value('SELECT 1 FROM roles WHERE id = ?', [$id]),
            'department' => (bool) Db::value('SELECT 1 FROM departments WHERE id = ?', [$id]),
            'seat' => (bool) Db::value('SELECT 1 FROM departments WHERE id = ?', [$id]) && isset(self::seatTypes()[$seat]),
            'user' => People::find($id) !== null,
            default => false,
        };
        if (!$ok) {
            throw MailError::field('grants', 'One line names a role, department, seat or person that no longer exists. Remove it and save again.');
        }

        return ['account_id' => $accountId, 'subject_type' => $type, 'subject_id' => $id, 'seat_type' => $type === 'seat' ? $seat : '',
            'level' => $level, 'created_by' => $actorId, 'created_at' => Clock::sql()];
    }

    private static function label(array $g): string
    {
        $id = (int) $g['subject_id'];

        return match ($g['subject_type']) {
            'role' => 'Role: ' . str_replace('_', ' ', (string) (Db::value('SELECT role_name FROM roles WHERE id = ?', [$id]) ?? '(removed role)')),
            'department' => 'Everyone serving in ' . (Db::value('SELECT name FROM departments WHERE id = ?', [$id]) ?? '(removed department)'),
            'seat' => (self::seatTypes()[$g['seat_type']]['label'] ?? $g['seat_type']) . ', ' . (Db::value('SELECT name FROM departments WHERE id = ?', [$id]) ?? '(removed department)'),
            'user' => People::name($id),
            default => '?',
        };
    }
}
