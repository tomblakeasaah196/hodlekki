<?php
// /includes/permissions/expiry.php
//
// End dates (design §5.7, lead decision 4). can() already ends a seat or a
// role on its last day by itself (perm_live_seats / perm_person_roles, once
// perm_config.expiry_enforced = 1). This file does the rest:
//
//   Renew                  one click moves the end to the next ministry year's
//                          end (or +12 months when none exists yet).
//   perm_daily_run()       cron/permissions_daily.php, once a day:
//     - 14 days before a seat ends: the holder and their pastor are told
//       (one message per person per run, reminded_at stops repeats);
//     - 14 days before a dated role ends: the holder and whoever gave it;
//     - year-end summary to the Resident Pastor and the Super Admins (who may
//       roll over the ministry year) at 14 and at 3 days;
//     - the day after: a seat whose OWN end date passed is closed like
//       "remove from roster"; a seat that lapsed with its ministry year only
//       stands down (is_active = 0), so a late rollover can still carry it;
//       an ended role row is deleted. History and a message each time.
//   Idempotent; --dry-run counts without writing. Break-glass never ends.
//
// Switching end dates ON is perm_expiry_switch_on() only (Roles & permissions,
// refused while anything live is past its end, so it never cuts anyone off);
// the migration does the same check. The daily job never switches it.

require_once __DIR__ . '/admin.php';

const PERM_REMIND_DAYS = 14;
const PERM_YEAREND_STAGES = [14, 3];

function perm_add_days(string $ymd, int $days): string
{
    return (new DateTimeImmutable($ymd))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** Where a renewal moves an end date: the next ministry year's end, or 12 months on. */
function perm_next_end(PDO $pdo, ?string $currentEnd, ?string $today = null): string
{
    $today ??= perm_today();
    $base = ($currentEnd !== null && $currentEnd !== '' && substr($currentEnd, 0, 10) > $today) ? substr($currentEnd, 0, 10) : $today;
    try {
        $stmt = $pdo->prepare('SELECT end_date FROM ministry_years WHERE start_date > ? AND end_date > ? ORDER BY end_date ASC LIMIT 1');
        $stmt->execute([$base, $base]);
        $next = $stmt->fetchColumn();
        if ($next) {
            return substr((string) $next, 0, 10);
        }
    } catch (Throwable $e) {
        // No ministry years: fall through to twelve months.
    }
    return (new DateTimeImmutable($base))->modify('+12 months')->format('Y-m-d');
}

/** One roster row with its effective end date, or null. */
function perm_seat_record(PDO $pdo, int $recordId): ?array
{
    if (!perm_roster_has_years($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT ud.id, ud.user_id, ud.department_id, ud.role_in_dept, ud.end_date AS own_end,
                                  COALESCE(ud.roster_status, 'Active') AS roster_status, ud.is_active,
                                  y.end_date AS year_end, COALESCE(y.is_active, 0) AS year_live
                             FROM user_departments ud LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id
                            WHERE ud.id = ?");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $own = $row['own_end'] ? substr((string) $row['own_end'], 0, 10) : null;
    $row['ends_on'] = $own ?? ($row['year_end'] ? substr((string) $row['year_end'], 0, 10) : null);
    $row['seat_key'] = perm_seat_key_for($pdo, (string) $row['role_in_dept']);
    return $row;
}

/**
 * Renew a seat (one click): only someone who may appoint that seat in that
 * department (lead decision 6). Returns the new end date.
 */
function perm_seat_renew(PDO $pdo, array $ctx, int $recordId, string $via = 'roles_screen'): string
{
    $seat = perm_seat_record($pdo, $recordId);
    if ($seat === null || $seat['roster_status'] !== 'Active') {
        throw new PermAdminError('That seat is no longer on the roster. Reload the page.', 'gone');
    }
    if ((int) $seat['year_live'] !== 1) {
        throw new PermAdminError('That ministry year is closed. Roll over the year instead.', 'year');
    }
    if ($seat['seat_key'] === null || !perm_ctx_can_appoint($pdo, $ctx, (int) $seat['department_id'], $seat['seat_key'])) {
        throw new PermAdminError('Only someone ranked above this seat can renew it.', 'rank');
    }
    $new = perm_next_end($pdo, $seat['ends_on']);
    $pdo->prepare('UPDATE user_departments SET end_date = ?, reminded_at = NULL, is_active = 1 WHERE id = ?')->execute([$new, $recordId]);
    $text = perm_seat_text($pdo, $seat['seat_key'], (int) $seat['department_id']);
    perm_history_add($pdo, ['via' => $via, 'change_type' => 'seat.renew', 'actor_user_id' => perm_actor_id($ctx),
        'subject_user_id' => (int) $seat['user_id'], 'department_id' => (int) $seat['department_id'], 'seat_key' => $seat['seat_key'],
        'before' => ['ends_on' => $seat['ends_on']], 'after' => ['ends_on' => $new]]);
    perm_notify($pdo, [(int) $seat['user_id']], 'Seat renewed', "Your seat as {$text} now runs until " . perm_date_text($new) . '.');
    perm_reset();
    return $new;
}

/**
 * What switching end dates on would end AT ONCE: live roster rows and live
 * dated roles already past their end date. Counted strictly, like migration
 * 20261202090000: every live roster row, whatever its seat. Break-glass never
 * ends, so it is never listed.
 *
 * @return list<array{kind:string, id:int, user_id:int, name:string, what:string, ends_on:string,
 *                    department_id:?int, seat_key:?string}>
 */
function perm_expiry_blockers(PDO $pdo, ?string $today = null): array
{
    $today ??= perm_today();
    if (!perm_ready($pdo)) {
        return [];
    }
    $out = [];
    $names = perm_department_names($pdo);
    $people = [];
    $name = static function (int $uid) use ($pdo, &$people): string {
        return $people[$uid] ??= perm_user_name(perm_user_row($pdo, $uid));
    };
    try {
        if (perm_roster_has_years($pdo)) {
            $stmt = $pdo->prepare("SELECT ud.id, ud.user_id, ud.department_id, ud.role_in_dept, COALESCE(ud.end_date, y.end_date) AS ends_on
                                     FROM user_departments ud LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id
                                    WHERE ud.is_active = 1 AND COALESCE(ud.roster_status, 'Active') = 'Active'
                                      AND COALESCE(ud.end_date, y.end_date) < ?
                                    ORDER BY ends_on, ud.id");
            $stmt->execute([$today]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = perm_seat_key_for($pdo, (string) $r['role_in_dept']);
                $out[] = [
                    'kind' => 'seat', 'id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'name' => $name((int) $r['user_id']),
                    'what' => $key !== null ? perm_seat_text($pdo, $key, (int) $r['department_id'], $names)
                        : str_replace('_', ' ', (string) $r['role_in_dept']) . ', ' . ($names[(int) $r['department_id']] ?? ''),
                    'ends_on' => substr((string) $r['ends_on'], 0, 10), 'department_id' => (int) $r['department_id'], 'seat_key' => $key,
                ];
            }
        }
        $stmt = $pdo->prepare('SELECT ur.user_id, ur.role_id, ur.ends_on, r.role_name, r.label
                                 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                                WHERE COALESCE(ur.is_frozen, 0) = 0 AND COALESCE(r.is_archived, 0) = 0
                                  AND COALESCE(r.is_break_glass, 0) = 0 AND ur.ends_on IS NOT NULL AND ur.ends_on < ?
                                ORDER BY ur.ends_on');
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'kind' => 'role', 'id' => (int) $r['role_id'], 'user_id' => (int) $r['user_id'], 'name' => $name((int) $r['user_id']),
                'what' => 'Role: ' . perm_role_label($r), 'ends_on' => substr((string) $r['ends_on'], 0, 10),
                'department_id' => null, 'seat_key' => null,
            ];
        }
    } catch (Throwable $e) {
        error_log('permissions: expiry blockers: ' . $e->getMessage());
    }
    return $out;
}

/** ['enforced' => bool, 'blockers' => int] for the screen and System Health. */
function perm_expiry_status(PDO $pdo): array
{
    return ['enforced' => perm_expiry_enforced($pdo), 'blockers' => count(perm_expiry_blockers($pdo))];
}

/**
 * "Enforce end dates" (Roles & permissions, roles: Manage). Refused while
 * anything live is past its end date, so switching on never cuts anyone off.
 * Kept in perm_history. Nothing else switches it on — not the daily job.
 */
function perm_expiry_switch_on(PDO $pdo, array $ctx): void
{
    if (!perm_ready($pdo) || perm_expiry_enforced($pdo)) {
        return;
    }
    $n = count(perm_expiry_blockers($pdo));
    if ($n > 0) {
        throw new PermAdminError("Renew or end the {$n} past their end date first.", 'blocked');
    }
    perm_config_set($pdo, 'expiry_enforced', '1');
    perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'config.expiry', 'actor_user_id' => perm_actor_id($ctx),
        'before' => ['expiry_enforced' => false], 'after' => ['expiry_enforced' => true]]);
    perm_reset();
}

/**
 * End a seat now (the "End" next to a seat past its end date): closed the way
 * "remove from roster" closes one. Same rank rule as appointing.
 */
function perm_seat_end(PDO $pdo, array $ctx, int $recordId, string $via = 'roles_screen'): void
{
    $seat = perm_seat_record($pdo, $recordId);
    if ($seat === null || $seat['roster_status'] !== 'Active') {
        throw new PermAdminError('That seat is no longer on the roster. Reload the page.', 'gone');
    }
    $exempt = !empty($ctx['breakglass']) || perm_ctx_can($ctx, 'roles', 'manage');
    if (!$exempt && ($seat['seat_key'] === null || !perm_ctx_can_appoint($pdo, $ctx, (int) $seat['department_id'], $seat['seat_key']))) {
        throw new PermAdminError('Only someone ranked above this seat can end it.', 'rank');
    }
    $pdo->prepare("UPDATE user_departments SET roster_status = 'Removed', is_active = 0, end_date = COALESCE(end_date, ?) WHERE id = ?")
        ->execute([perm_today(), $recordId]);
    $text = $seat['seat_key'] !== null ? perm_seat_text($pdo, $seat['seat_key'], (int) $seat['department_id'])
        : str_replace('_', ' ', (string) $seat['role_in_dept']);
    perm_history_add($pdo, ['via' => $via, 'change_type' => 'seat.remove', 'actor_user_id' => perm_actor_id($ctx),
        'subject_user_id' => (int) $seat['user_id'], 'department_id' => (int) $seat['department_id'], 'seat_key' => $seat['seat_key'],
        'before' => ['ends_on' => $seat['ends_on']], 'reason' => 'past its end date']);
    perm_notify($pdo, [(int) $seat['user_id']], 'Your seat has ended', "Your seat as {$text} has ended. Thank you for serving.");
    perm_reset();
}

/**
 * For bin/permissions_parity.php: who would LOSE access if end dates were
 * enforced today — each blocker's person and the modules they would no
 * longer open. With end dates already on, the same people have lost it.
 *
 * @return array{enforced:bool, blockers:list<array>, people:array<int, array{name:string, items:list<string>, loses:list<string>}>}
 */
function perm_expiry_impact(PDO $pdo): array
{
    $blockers = perm_expiry_blockers($pdo);
    $enforced = perm_expiry_enforced($pdo);
    $people = [];
    $state = &perm_state();
    foreach ($blockers as $b) {
        $people[$b['user_id']]['name'] = $b['name'];
        $people[$b['user_id']]['items'][] = $b['what'] . ' (ended ' . perm_date_text($b['ends_on']) . ')';
    }
    foreach (array_keys($people) as $uid) {
        $mods = [];
        foreach ([false, true] as $on) {
            perm_config($pdo, 'expiry_enforced');                 // fill the cache, then pin the switch
            $state['config']['expiry_enforced'] = $on ? '1' : '0';
            $mods[(int) $on] = array_keys(array_filter(perm_resolve($pdo, $uid)['abilities']));
        }
        $state['config'] = null;
        $labels = [];
        foreach (array_diff($mods[0], $mods[1]) as $m) {
            $labels[] = (string) (perm_modules()[$m]['label'] ?? $m);
        }
        $people[$uid]['loses'] = $labels;
    }
    return ['enforced' => $enforced, 'blockers' => $blockers, 'people' => $people];
}

/** Renew a dated role by one period. Returns the new end date. */
function perm_user_role_renew(PDO $pdo, array $ctx, int $uid, int $roleId): string
{
    $rows = array_values(array_filter(perm_user_role_rows($pdo, $uid), static fn ($r) => $r['role_id'] === $roleId));
    if ($rows === [] || $rows[0]['ends_on'] === null) {
        throw new PermAdminError('This role has no end date to renew.', 'none');
    }
    $new = perm_next_end($pdo, $rows[0]['ends_on']);
    perm_user_role_dates($pdo, $ctx, $uid, $roleId, $rows[0]['starts_on'], $new, null);
    return $new;
}

/**
 * The pastors who look after a department: live Pastor-in-charge seats over
 * it (their own department or one above it), else everyone who may edit
 * every department (the pastors' roles).
 */
function perm_department_pastors(PDO $pdo, int $deptId, string $today): array
{
    $ids = [];
    foreach (perm_live_seats($pdo, null, $today, null, 'pastor_in_charge') as $s) {
        if (in_array($deptId, perm_department_with_subunits($pdo, $s['department_id']), true)) {
            $ids[$s['user_id']] = true;
        }
    }
    if ($ids === []) {
        return perm_holders($pdo, 'departments', 'edit', $deptId);
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
}

/** Who may roll over the ministry year: holders of Departments: Manage, and the Super Admins. */
function perm_rollover_people(PDO $pdo): array
{
    $ids = perm_holders($pdo, 'departments', 'manage');
    $stmt = $pdo->query('SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                          WHERE COALESCE(r.is_break_glass, 0) = 1 AND COALESCE(ur.is_frozen, 0) = 0');
    $ids = array_merge($ids, array_keys(perm_active_accounts($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN))));
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    return $ids;
}

function perm_config_set(PDO $pdo, string $key, ?string $value): void
{
    $pdo->prepare('DELETE FROM perm_config WHERE cfg_key = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO perm_config (cfg_key, cfg_value) VALUES (?, ?)')->execute([$key, $value]);
    $state = &perm_state();
    $state['config'] = null;
}

/** "A and B", "A, B and C". */
function perm_join(array $items): string
{
    $items = array_values($items);
    if (count($items) <= 1) {
        return (string) ($items[0] ?? '');
    }
    return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
}

/**
 * The daily run. Returns counts: seat_reminders, role_reminders, summaries,
 * seats_closed, seats_lapsed, roles_ended, messages.
 */
function perm_daily_run(PDO $pdo, ?string $today = null, bool $dryRun = false): array
{
    $today ??= perm_today();
    $report = ['seat_reminders' => 0, 'role_reminders' => 0, 'summaries' => 0, 'seats_closed' => 0,
        'seats_lapsed' => 0, 'roles_ended' => 0, 'messages' => 0, 'skipped' => null];
    if (!perm_ready($pdo)) {
        $report['skipped'] = 'the permission tables are not installed';
        return $report;
    }
    if (!perm_expiry_enforced($pdo)) {
        // Never switched on from here: only perm_expiry_switch_on() (Roles & permissions).
        $report['skipped'] = 'end dates are off (Roles & permissions shows what blocks switching them on)';
        return $report;
    }
    $names = perm_department_names($pdo);
    $limit = perm_add_days($today, PERM_REMIND_DAYS);
    $now = perm_now();
    $system = ['actor_user_id' => null, 'actor_label' => 'System', 'via' => 'cron'];
    $tell = static function (array $ids, string $title, string $message, string $link = '/modules/profile/index.php') use ($pdo, $dryRun, &$report): void {
        $report['messages'] += $dryRun ? count($ids) : perm_notify($pdo, $ids, $title, $message, $link);
    };

    // Live roster rows with their effective end (seat types matched in PHP:
    // the two tables do not share a collation).
    $rows = [];
    if (perm_roster_has_years($pdo)) {
        $stmt = $pdo->query("SELECT ud.id, ud.user_id, ud.department_id, ud.role_in_dept, ud.end_date AS own_end, ud.reminded_at,
                                    y.end_date AS year_end
                               FROM user_departments ud LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id
                              WHERE ud.is_active = 1 AND COALESCE(ud.roster_status, 'Active') = 'Active'");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = perm_seat_key_for($pdo, (string) $r['role_in_dept']);
            $own = $r['own_end'] ? substr((string) $r['own_end'], 0, 10) : null;
            $ends = $own ?? ($r['year_end'] ? substr((string) $r['year_end'], 0, 10) : null);
            if ($key !== null && $ends !== null) {
                $rows[] = ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'department_id' => (int) $r['department_id'],
                    'seat_key' => $key, 'own_end' => $own, 'ends_on' => $ends, 'reminded' => $r['reminded_at'] !== null];
            }
        }
    }

    // 1. Seats ending within 14 days: the holder, and their pastor.
    $holders = [];
    $pastors = [];
    $remind = [];
    foreach ($rows as $r) {
        if ($r['reminded'] || $r['ends_on'] < $today || $r['ends_on'] > $limit) {
            continue;
        }
        $text = perm_seat_text($pdo, $r['seat_key'], $r['department_id'], $names);
        $holders[$r['user_id']][] = [$text, $r['ends_on']];
        foreach (perm_department_pastors($pdo, $r['department_id'], $today) as $p) {
            if ($p !== $r['user_id']) {
                $pastors[$p][] = $r['id'];
            }
        }
        $remind[] = $r['id'];
    }
    foreach ($holders as $uid => $seats) {
        $first = min(array_column($seats, 1));
        $tell([$uid], count($seats) === 1 ? 'Your seat ends soon' : 'Your seats end soon',
            'Your ' . (count($seats) === 1 ? 'seat' : 'seats') . ' as ' . perm_join(array_column($seats, 0)) . ' '
            . (count($seats) === 1 ? 'ends' : 'end') . ' on ' . perm_date_text($first) . '. Ask your pastor to renew '
            . (count($seats) === 1 ? 'it' : 'them') . '.');
    }
    foreach ($pastors as $uid => $ids) {
        $ids = array_values(array_unique($ids));
        $tell([$uid], 'Seats ending soon', count($ids) . (count($ids) === 1 ? ' seat you look after ends' : ' seats you look after end')
            . ' within ' . PERM_REMIND_DAYS . ' days. Renew the ones that continue.',
            '/modules/departments/renew.php?ids=' . implode(',', array_slice($ids, 0, 200)));
    }
    $report['seat_reminders'] = count($remind);
    if (!$dryRun && $remind !== []) {
        foreach (array_chunk($remind, 200) as $chunk) {
            $pdo->prepare('UPDATE user_departments SET reminded_at = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')
                ->execute(array_merge([$now], $chunk));
        }
    }

    // 2. Dated roles ending within 14 days: the holder, and whoever gave it.
    $stmt = $pdo->prepare('SELECT ur.user_id, ur.role_id, ur.ends_on, ur.assigned_by, r.role_name, r.label
                             FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                            WHERE ur.ends_on IS NOT NULL AND ur.ends_on >= ? AND ur.ends_on <= ? AND ur.reminded_at IS NULL
                              AND COALESCE(ur.is_frozen, 0) = 0 AND COALESCE(r.is_break_glass, 0) = 0');
    $stmt->execute([$today, $limit]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = perm_role_label($r);
        $date = perm_date_text(substr((string) $r['ends_on'], 0, 10));
        $tell([(int) $r['user_id']], 'Your role ends soon', "Your {$label} role ends on {$date}.");
        if ($r['assigned_by'] && (int) $r['assigned_by'] !== (int) $r['user_id']) {
            $name = perm_user_name(perm_user_row($pdo, (int) $r['user_id']));
            $tell([(int) $r['assigned_by']], 'A role ends soon', "{$name}'s {$label} role ends on {$date}. Renew it if it continues.",
                '/modules/roles/index.php#tab=people');
        }
        $report['role_reminders']++;
        if (!$dryRun) {
            $pdo->prepare('UPDATE user_roles SET reminded_at = ? WHERE user_id = ? AND role_id = ?')
                ->execute([$now, (int) $r['user_id'], (int) $r['role_id']]);
        }
    }

    // 3. The year-end summary (lead, round 2): once at 14 days, once at 3.
    $year = perm_live_year($pdo);
    if ($year !== null) {
        $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($year['end_date']))->format('%r%a');
        $due = null;
        foreach (PERM_YEAREND_STAGES as $stage) {
            if ($days >= 0 && $days <= $stage && perm_config($pdo, "yearend_{$year['id']}_{$stage}") === null) {
                $due = $stage;   // the latest stage reached wins; earlier ones are marked done with it
            }
        }
        if ($due !== null) {
            $lapsing = array_filter($rows, static fn ($r) => $r['own_end'] === null && $r['ends_on'] === $year['end_date']);
            if ($lapsing !== []) {
                $depts = count(array_unique(array_column($lapsing, 'department_id')));
                $tell(perm_rollover_people($pdo), 'Ministry year ends soon',
                    count($lapsing) . ' seats in ' . $depts . ' ' . ($depts === 1 ? 'department' : 'departments') . ' end on '
                    . perm_date_text($year['end_date']) . '. Roll over the ministry year.', '/modules/departments/index.php#rollover');
                $report['summaries']++;
            }
            if (!$dryRun) {
                foreach (PERM_YEAREND_STAGES as $stage) {
                    if ($stage >= $due && perm_config($pdo, "yearend_{$year['id']}_{$stage}") === null) {
                        perm_config_set($pdo, "yearend_{$year['id']}_{$stage}", $today);
                    }
                }
            }
        }
    }

    // 4. Seats past their end: close (own end date) or stand down (the year ended).
    foreach ($rows as $r) {
        if ($r['ends_on'] >= $today) {
            continue;
        }
        $text = perm_seat_text($pdo, $r['seat_key'], $r['department_id'], $names);
        if ($r['own_end'] !== null) {
            $report['seats_closed']++;
            if (!$dryRun) {
                $pdo->prepare("UPDATE user_departments SET roster_status = 'Removed', is_active = 0 WHERE id = ?")->execute([$r['id']]);
            }
        } else {
            $report['seats_lapsed']++;
            if (!$dryRun) {
                $pdo->prepare('UPDATE user_departments SET is_active = 0 WHERE id = ?')->execute([$r['id']]);
            }
        }
        if (!$dryRun) {
            perm_history_add($pdo, $system + ['change_type' => 'seat.expire', 'subject_user_id' => $r['user_id'],
                'department_id' => $r['department_id'], 'seat_key' => $r['seat_key'], 'before' => ['ends_on' => $r['ends_on']],
                'reason' => $r['own_end'] !== null ? 'end date passed' : 'ministry year ended']);
        }
        $tell([$r['user_id']], 'Your seat has ended', "Your seat as {$text} ended on " . perm_date_text($r['ends_on']) . '. Thank you for serving.');
    }

    // 5. Dated roles past their end (break-glass never ends).
    $stmt = $pdo->prepare('SELECT ur.user_id, ur.role_id, ur.ends_on, r.role_name, r.label
                             FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                            WHERE ur.ends_on IS NOT NULL AND ur.ends_on < ? AND COALESCE(r.is_break_glass, 0) = 0');
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = perm_role_label($r);
        $report['roles_ended']++;
        if (!$dryRun) {
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?')->execute([(int) $r['user_id'], (int) $r['role_id']]);
            perm_history_add($pdo, $system + ['change_type' => 'role.expire', 'subject_user_id' => (int) $r['user_id'],
                'role_id' => (int) $r['role_id'], 'before' => ['ends_on' => substr((string) $r['ends_on'], 0, 10)]]);
        }
        $tell([(int) $r['user_id']], 'Your role has ended', "Your {$label} role ended on " . perm_date_text(substr((string) $r['ends_on'], 0, 10)) . '.');
    }

    if (!$dryRun) {
        perm_config_set($pdo, 'expiry_last_run', $now);
    }
    perm_reset();
    return $report;
}
