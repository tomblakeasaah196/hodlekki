<?php
// /includes/permissions/parity.php
//
// Person-by-person comparison of today's rules (legacy_rules.php) with can()
// on the day-one matrix. Used by bin/permissions_parity.php (run by the owner
// in cPanel Terminal before the switch) and by tests/permissions/.
//
// Every difference gets a category. Expected categories are the decisions the
// owner and the lead made; anything else is UNEXPECTED and fails the run:
//
//   gains   break-glass        a Super Admin: sees everything (owner answer 7)
//           combined roles     everything a person holds now counts together
//                              (owner answer 5) — they could reach it before by
//                              holding that role as their "active" one
//           lead decision      Resident Pastor sees Roles & permissions (decision 7)
//   losses  closed hole        an endpoint no screen offers is now checked
//                              (decisions 1 and 8) — reported as a count
//           lost global role   a Director/HOD role without the matching live seat
//                              (decision 2) — reported by name
//           academy roster     Academy admin now needs a LIVE roster seat
//   links   link added / dead link removed: the menu now follows what works

require_once dirname(__DIR__) . '/permissions.php';
require_once __DIR__ . '/legacy_rules.php';

const PERM_PARITY_LEAD_GAINS = [
    // module => [ability => roles that gain it by decision]
    'roles' => ['view' => ['Resident_Pastor']],
];

/** The new side, as the switched pages will ask it. */
function perm_parity_new_abilities(array $ctx): array
{
    if ($ctx['breakglass']) {
        $all = [];
        foreach (perm_modules() as $module => $def) {
            foreach (PERM_ABILITIES as $ability) {
                if (isset(PERM_LEVELS[$ability]) && PERM_LEVELS[$ability] > PERM_LEVELS[$def['max']]) {
                    continue;
                }
                if (in_array($ability, PERM_EXTRAS, true) && !in_array($ability, $def['extras'], true)) {
                    continue;
                }
                $all[$module][$ability] = ['church' => true, 'depts' => [], 'own' => true];
            }
        }
        return $all;
    }
    $new = $ctx['abilities'];
    // The Charis countersign lives inside the Charis screen: it needs Charis too.
    if (isset($new['finance.countersign']['approve'])) {
        $charis = $new['charis']['view'] ?? perm_scope_none();
        $new['finance.countersign']['approve'] = perm_scope_intersect($new['finance.countersign']['approve'], $charis);
        if (perm_scope_is_none($new['finance.countersign']['approve'])) {
            unset($new['finance.countersign']['approve']);
        }
    }
    return $new;
}

/** The old rules over every role the person holds as "active" (what combining gives). */
function perm_parity_legacy_union(array $snap, array $depts): array
{
    $union = [];
    foreach (array_unique(array_merge([$snap['ar']], $snap['roles'])) as $ar) {
        foreach (legacy_abilities($snap, $depts, $ar) as $module => $abilities) {
            foreach ($abilities as $ability => $scope) {
                $union[$module][$ability] = isset($union[$module][$ability])
                    ? perm_scope_normalise(perm_scope_union($union[$module][$ability], $scope))
                    : $scope;
            }
        }
    }
    return $union;
}

/** The old rules without the retired global Director / HOD roles (decision 2). */
function perm_parity_legacy_without_retired(array $snap, array $depts): array
{
    $snap['roles'] = array_values(array_diff($snap['roles'], ['Director', 'HOD']));
    $snap['ar'] = $snap['roles'][0] ?? 'Member';
    return legacy_abilities($snap, $depts);
}

/**
 * Every difference for one person.
 *
 * @return array{diffs: list<array>, nav: list<array>, snapshot: array, breakglass: bool}
 */
function perm_parity_person(PDO $pdo, int $userId, ?array $depts = null): array
{
    $depts ??= legacy_departments($pdo);
    $snap = legacy_snapshot($pdo, $userId);
    $legacy = legacy_abilities($snap, $depts);
    $ctx = perm_resolve($pdo, $userId);
    $new = perm_parity_new_abilities($ctx);

    $union = null;
    $withoutRetired = null;
    $diffs = [];
    $none = perm_scope_none();

    foreach (perm_modules() as $module => $def) {
        foreach (PERM_ABILITIES as $ability) {
            $old = $legacy[$module][$ability] ?? $none;
            $now = $new[$module][$ability] ?? $none;
            $gain = !perm_scope_covers($old, $now);
            $loss = !perm_scope_covers($now, $old);
            if (!$gain && !$loss) {
                continue;
            }
            if ($gain) {
                if ($ctx['breakglass']) {
                    $cat = 'break-glass';
                } else {
                    $union ??= perm_parity_legacy_union($snap, $depts);
                    if (perm_scope_covers($union[$module][$ability] ?? $none, $now)) {
                        $cat = 'combined roles';
                    } elseif (array_intersect(PERM_PARITY_LEAD_GAINS[$module][$ability] ?? [], $snap['roles']) !== []) {
                        $cat = 'lead decision';
                    } else {
                        $cat = 'UNEXPECTED gain';
                    }
                }
                $diffs[] = ['module' => $module, 'ability' => $ability, 'kind' => 'gain', 'category' => $cat,
                    'old' => $old, 'new' => $now];
            }
            if ($loss) {
                if (in_array($ability, LEGACY_HOLES[$module] ?? [], true)) {
                    $cat = 'closed hole';
                } else {
                    $withoutRetired ??= perm_parity_legacy_without_retired($snap, $depts);
                    $hadRetired = array_intersect(['Director', 'HOD'], $snap['roles']) !== [];
                    if ($hadRetired && perm_scope_covers($now, $withoutRetired[$module][$ability] ?? $none)) {
                        $cat = 'lost global role';
                    } elseif ($module === 'academy' && perm_parity_academy_inactive_only($snap, $depts)) {
                        $cat = 'academy roster';
                    } else {
                        $cat = 'UNEXPECTED loss';
                    }
                }
                $diffs[] = ['module' => $module, 'ability' => $ability, 'kind' => 'loss', 'category' => $cat,
                    'old' => $old, 'new' => $now];
            }
        }
    }

    $nav = [];
    $oldNav = legacy_nav($snap, $legacy);
    foreach ($oldNav as $key => $was) {
        $is = perm_ctx_nav_visible($ctx, $key);
        if ($was !== $is) {
            $nav[] = ['module' => $key, 'kind' => $is ? 'link added' : 'dead link removed'];
        }
    }

    return ['diffs' => $diffs, 'nav' => $nav, 'snapshot' => $snap, 'breakglass' => $ctx['breakglass']];
}

/** Academy admin today came only from a roster row that is no longer active. */
function perm_parity_academy_inactive_only(array $snap, array $depts): bool
{
    $academy = legacy_depts_named($depts, ['%Academy%', '%School%']);
    $active = array_intersect($academy, array_column($snap['seats'], 'dept'));
    $any = array_intersect($academy, array_column($snap['any_seats'], 'dept'));
    return $active === [] && $any !== [];
}

/**
 * The whole church. Returns per-category people, ready to print.
 *
 * @return array{people:int, categories:array<string,array<int,list<string>>>, unexpected_gain:bool,
 *               unexpected_loss:bool, facts:array}
 */
function perm_parity_run(PDO $pdo): array
{
    $depts = legacy_departments($pdo);
    $ids = array_map('intval', $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $categories = [];
    foreach ($ids as $uid) {
        $r = perm_parity_person($pdo, $uid, $depts);
        foreach ($r['diffs'] as $d) {
            $categories[$d['category']][$uid][] = $d['module'] . ($d['ability'] === 'view' ? '' : ':' . $d['ability'])
                . ($d['kind'] === 'gain' ? ' +' : ' -');
        }
        foreach ($r['nav'] as $n) {
            $categories[$n['kind']][$uid][] = $n['module'];
        }
        // Say WHY an unexpected gain happens when the reason is plain to see:
        // a roster seat counts on its own now, and this person's role is frozen
        // (the old "Account Freeze") or missing.
        $snap = $r['snapshot'];
        if (isset($categories['UNEXPECTED gain'][$uid])) {
            array_unshift($categories['UNEXPECTED gain'][$uid], $snap['frozen_roles'] !== []
                ? 'why: seated, but role frozen (' . implode(', ', $snap['frozen_roles']) . ')'
                : 'why: seated without the matching role');
        }
        // Decision 2, by name even when nothing they could do is lost.
        $seatNames = array_column($snap['seats'], 'seat');
        foreach (['Director', 'HOD'] as $role) {
            if (in_array($role, $snap['roles'], true) && !in_array($role, $seatNames, true)) {
                $categories['lost global role'][$uid][] = $role . ' role, no live ' . $role . ' seat';
            }
        }
    }
    foreach ($categories as $cat => $people) {
        foreach ($people as $uid => $items) {
            $categories[$cat][$uid] = array_values(array_unique($items));
        }
    }

    return [
        'people' => count($ids),
        'categories' => $categories,
        'unexpected_gain' => !empty($categories['UNEXPECTED gain']),
        'unexpected_loss' => !empty($categories['UNEXPECTED loss']),
        'facts' => perm_parity_facts($pdo),
    ];
}

function perm_parity_facts(PDO $pdo): array
{
    $roles = [];
    foreach ($pdo->query(
        "SELECT r.id, r.role_name,
                SUM(CASE WHEN ur.user_id IS NOT NULL AND COALESCE(ur.is_frozen, 0) = 0 THEN 1 ELSE 0 END) AS held
           FROM roles r LEFT JOIN user_roles ur ON ur.role_id = r.id
          GROUP BY r.id, r.role_name ORDER BY r.id"
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roles[] = $row['role_name'] . ' ' . (int) $row['held'];
    }
    $statusRoles = [];
    foreach ($pdo->query('SELECT role_name FROM roles')->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (in_array($name, ['Pending_Pastor', 'Pending_Director', 'Non_Member', 'Church_Member'], true)) {
            $statusRoles[] = $name;
        }
    }
    $finance = 'no Finance department';
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM perm_module_owners o
               JOIN user_departments ud ON ud.department_id = o.department_id
              WHERE o.module_key = 'requisition.disburse' AND ud.role_in_dept = 'Director'
                AND ud.is_active = 1 AND COALESCE(ud.roster_status, 'Active') = 'Active'"
        );
        $n = (int) $stmt->fetchColumn();
        $finance = $n === 1 ? 'held' : ($n === 0 ? 'EMPTY' : $n . ' holders');
    } catch (Throwable $e) {
        $finance = 'not migrated';
    }
    return ['roles' => $roles, 'status_roles' => $statusRoles, 'finance_director' => $finance];
}

/** People's names for the report (screen only — never written anywhere). */
function perm_parity_names(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['id']] = trim($row['first_name'] . ' ' . $row['last_name']) ?: ('#' . $row['id']);
    }
    return $out;
}

/** Exit code: 1 = someone gains unexpectedly, 2 = someone loses unexpectedly, 0 = only decided changes. */
function perm_parity_exit_code(array $run): int
{
    return $run['unexpected_gain'] ? 1 : ($run['unexpected_loss'] ? 2 : 0);
}

/**
 * The one-screen report.
 *
 * @param bool $names   print names (false: counts only — for development on a copy)
 * @param ?string $detail one category to print in full, person by person
 */
function perm_parity_render(PDO $pdo, array $run, bool $names = true, ?string $detail = null): string
{
    $order = [
        'EXPECTED' => ['closed hole', 'lost global role', 'combined roles', 'break-glass', 'lead decision',
            'academy roster', 'dead link removed', 'link added'],
        'UNEXPECTED' => ['UNEXPECTED gain', 'UNEXPECTED loss'],
    ];
    $countOnly = ['closed hole', 'dead link removed', 'link added'];
    $cats = $run['categories'];
    $allIds = [];
    foreach ($cats as $people) {
        $allIds = array_merge($allIds, array_keys($people));
    }
    $who = $names ? perm_parity_names($pdo, $allIds) : [];

    $lines = [];
    $lines[] = sprintf('Permissions parity — %s — %d accounts checked',
        (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i'), $run['people']);
    foreach ($order as $heading => $list) {
        $lines[] = $heading;
        foreach ($list as $cat) {
            $people = $cats[$cat] ?? [];
            $label = str_pad($cat . ' ', 26, '.') . ' ' . str_pad((string) count($people), 5);
            if ($people === [] || in_array($cat, $countOnly, true) || !$names) {
                $lines[] = '  ' . rtrim($label);
                continue;
            }
            $shown = [];
            foreach (array_slice($people, 0, 10, true) as $uid => $items) {
                $shown[] = ($who[$uid] ?? '#' . $uid) . ' (' . implode(', ', array_slice($items, 0, 3))
                    . (count($items) > 3 ? ', …' : '') . ')';
            }
            $more = count($people) > 10 ? ' +' . (count($people) - 10) . ' more' : '';
            $lines[] = '  ' . $label . implode('; ', $shown) . $more;
        }
    }
    $f = $run['facts'];
    $lines[] = 'Roles: ' . implode(', ', $f['roles']);
    $lines[] = 'Finance Director seat: ' . $f['finance_director']
        . ' · roles named like a status: ' . ($f['status_roles'] === [] ? 'none' : implode(', ', $f['status_roles']));
    $lines[] = 'Exit ' . perm_parity_exit_code($run) . ' (0 = only decided changes, 1 = unexpected gain, 2 = unexpected loss)';

    if ($detail !== null) {
        $lines[] = '';
        $lines[] = 'Detail: ' . $detail;
        foreach ($cats[$detail] ?? [] as $uid => $items) {
            $lines[] = '  ' . ($names ? ($who[$uid] ?? '#' . $uid) : '#' . $uid) . ': ' . implode(', ', $items);
        }
    }
    return implode("\n", $lines) . "\n";
}
