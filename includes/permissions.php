<?php
// /includes/permissions.php
//
// THE ACCESS CHECK. Every page, every api/*.php endpoint and the navigation ask
// one function:
//
//     can(string $module, string $level = 'view', mixed $target = null): bool
//
// and nothing else decides access. Nothing here compares a role NAME, a seat
// NAME or a department NUMBER: roles are ids, seats are stable keys, and the
// administrator's decisions (the roles × modules matrix, which departments own
// which module, which seats a department uses) live in the perm_* tables.
// Renaming a role or a department therefore changes nobody's access.
//
// Who holds what is read LIVE from the database once per request (about four
// indexed queries) and kept in memory for the rest of it, so a role given or a
// roster move takes effect on the very next page load — no signing out.
//
// Vocabulary
//   level    a ladder: none < view < create < edit < delete (each implies the
//            ones below), plus the extras approve / export / manage.
//   scope    own (records that are yours), dept (your departments), church.
//            A cell may give a different scope for viewing (view_scope);
//            export follows the viewing scope, approve/manage the cell's scope.
//   holder   a role (assigned directly, church-wide offices), a seat type (from
//            the Departments roster, in the departments that own the module) or
//            '@everyone' (every signed-in member).
//   break-glass  a role flagged roles.is_break_glass (Super Admin): can() says
//            yes to everything, and every request that relied on it is logged.
//
// Design notes: permissions-design.md (shared notes). Day-one matrix:
// db/migrations/20261126090200_perm_day_one.sql. Tests: tests/permissions/.
//
// Portable SQL only (the tests run it on SQLite). Every function is a safe,
// closed no-op until the perm_* tables exist: the tree reaches production
// before db/migrate.php runs.

if (defined('HOD_PERMISSIONS')) {
    return;
}
define('HOD_PERMISSIONS', true);

const PERM_LEVELS = ['none' => 0, 'view' => 1, 'create' => 2, 'edit' => 3, 'delete' => 4];
const PERM_EXTRAS = ['approve', 'export', 'manage'];
const PERM_ABILITIES = ['view', 'create', 'edit', 'delete', 'approve', 'export', 'manage'];
const PERM_SCOPES = ['own', 'dept', 'church'];
const PERM_EVERYONE = '@everyone';

// --------------------------------------------------------------------------
// Plumbing: registry, database handle, clock, readiness, caches
// --------------------------------------------------------------------------

/** The module registry (includes/permissions/modules.php). */
function perm_modules(): array
{
    static $modules = null;
    return $modules ??= require __DIR__ . '/permissions/modules.php';
}

/** Holds per-request state; perm_reset() empties it (tests, the parity CLI). */
function &perm_state(): array
{
    static $state = [
        'pdo' => null,
        'today' => null,
        'contexts' => [],
        'ready' => null,
        'config' => null,
        'tree' => null,
        'owners' => null,
        'seat_types' => null,
        'dept_seats' => null,
        'roster_years' => null,
        'person_depts' => [],
        'breakglass_used' => [],
        'breakglass_hooked' => false,
        'strict' => false,
    ];
    return $state;
}

function perm_reset(): void
{
    $state = &perm_state();
    $keep = ['pdo' => $state['pdo'], 'today' => $state['today'], 'strict' => $state['strict']];
    $state = array_merge($state, [
        'contexts' => [], 'ready' => null, 'config' => null, 'tree' => null, 'owners' => null,
        'seat_types' => null, 'dept_seats' => null, 'roster_years' => null, 'person_depts' => [], 'breakglass_used' => [],
    ], $keep);
}

/** Use this handle instead of the global $pdo (tests and CLI tools). */
function perm_use_pdo(?PDO $pdo): void
{
    $state = &perm_state();
    $state['pdo'] = $pdo;
    perm_reset();
}

function perm_pdo(): ?PDO
{
    $state = &perm_state();
    if ($state['pdo'] instanceof PDO) {
        return $state['pdo'];
    }
    return (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) ? $GLOBALS['pdo'] : null;
}

/** Unknown module or level: fail closed, and say so (tests turn this into an exception). */
function perm_set_strict(bool $strict): void
{
    $state = &perm_state();
    $state['strict'] = $strict;
}

function perm_complain(string $message): void
{
    $state = &perm_state();
    if ($state['strict']) {
        throw new InvalidArgumentException($message);
    }
    error_log('permissions: ' . $message);
}

/** Today in Lagos as Y-m-d. perm_set_today() pins it (tests, "what if" reports). */
function perm_today(): string
{
    $state = &perm_state();
    if ($state['today'] !== null) {
        return $state['today'];
    }
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
}

function perm_set_today(?string $ymd): void
{
    $state = &perm_state();
    $state['today'] = $ymd;
    $state['contexts'] = [];
    $state['person_depts'] = [];
}

function perm_ready(PDO $pdo): bool
{
    $state = &perm_state();
    if ($state['ready'] !== null) {
        return $state['ready'];
    }
    try {
        $pdo->query('SELECT seat_key FROM perm_seat_types LIMIT 0')->closeCursor();
        $pdo->query('SELECT module_key FROM perm_grants LIMIT 0')->closeCursor();
        $pdo->query('SELECT is_break_glass FROM roles LIMIT 0')->closeCursor();
        return $state['ready'] = true;
    } catch (Throwable $e) {
        return $state['ready'] = false;
    }
}

function perm_config(PDO $pdo, string $key, ?string $default = null): ?string
{
    $state = &perm_state();
    if ($state['config'] === null) {
        $state['config'] = [];
        try {
            foreach ($pdo->query('SELECT cfg_key, cfg_value FROM perm_config')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $state['config'][(string) $row['cfg_key']] = $row['cfg_value'] === null ? null : (string) $row['cfg_value'];
            }
        } catch (Throwable $e) {
            // Not migrated yet: every switch reads as its default.
        }
    }
    return array_key_exists($key, $state['config']) ? $state['config'][$key] : $default;
}

/** Seat end dates count only once the reminders and renewals exist (PR 3 flips it). */
function perm_expiry_enforced(PDO $pdo): bool
{
    return perm_config($pdo, 'expiry_enforced', '0') === '1';
}

/** department id => parent id (null for a top-level department). */
function perm_department_tree(PDO $pdo): array
{
    $state = &perm_state();
    if ($state['tree'] !== null) {
        return $state['tree'];
    }
    $tree = [];
    try {
        foreach ($pdo->query('SELECT id, parent_id FROM departments')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tree[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }
    } catch (Throwable $e) {
        error_log('permissions: departments unreadable: ' . $e->getMessage());
    }
    return $state['tree'] = $tree;
}

/** The department and every department below it, at any depth. */
function perm_department_with_subunits(PDO $pdo, int $deptId): array
{
    $tree = perm_department_tree($pdo);
    $out = [$deptId => true];
    $frontier = [$deptId];
    for ($depth = 0; $frontier !== [] && $depth < 10; $depth++) {
        $next = [];
        foreach ($tree as $id => $parent) {
            if ($parent !== null && in_array($parent, $frontier, true) && !isset($out[$id])) {
                $out[$id] = true;
                $next[] = $id;
            }
        }
        $frontier = $next;
    }
    $ids = array_keys($out);
    sort($ids);
    return $ids;
}

/** module key => [department ids] (0 = every department). */
function perm_module_owners(PDO $pdo): array
{
    $state = &perm_state();
    if ($state['owners'] !== null) {
        return $state['owners'];
    }
    $owners = [];
    try {
        foreach ($pdo->query('SELECT module_key, department_id FROM perm_module_owners')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owners[(string) $row['module_key']][] = (int) $row['department_id'];
        }
    } catch (Throwable $e) {
        error_log('permissions: owners unreadable: ' . $e->getMessage());
    }
    return $state['owners'] = $owners;
}

/** seat_key => seat type row, roster values included. */
function perm_seat_types(PDO $pdo): array
{
    $state = &perm_state();
    if ($state['seat_types'] !== null) {
        return $state['seat_types'];
    }
    $types = [];
    try {
        $rows = $pdo->query('SELECT seat_key, roster_value, label, seat_rank, is_leader, covers_sub_units, max_holders,
                                    can_appoint, sort_order, is_archived
                               FROM perm_seat_types ORDER BY sort_order, seat_rank DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $types[(string) $row['seat_key']] = $row;
        }
    } catch (Throwable $e) {
        error_log('permissions: seat types unreadable: ' . $e->getMessage());
    }
    return $state['seat_types'] = $types;
}

// --------------------------------------------------------------------------
// Scopes: ['church' => bool, 'depts' => int[] (sorted, unique), 'own' => bool]
// --------------------------------------------------------------------------

function perm_scope_none(): array
{
    return ['church' => false, 'depts' => [], 'own' => false];
}

function perm_scope_is_none(array $s): bool
{
    return !$s['church'] && $s['depts'] === [] && !$s['own'];
}

function perm_scope_union(array $a, array $b): array
{
    $depts = array_values(array_unique(array_merge($a['depts'], $b['depts'])));
    sort($depts);
    return ['church' => $a['church'] || $b['church'], 'depts' => $depts, 'own' => $a['own'] || $b['own']];
}

/** Drop what a wider part already covers, so equal access always compares equal. */
function perm_scope_normalise(array $s): array
{
    if ($s['church']) {
        return ['church' => true, 'depts' => [], 'own' => true];
    }
    $depts = array_values(array_unique(array_map('intval', $s['depts'])));
    sort($depts);
    return ['church' => false, 'depts' => $depts, 'own' => (bool) $s['own']];
}

/** True when $outer gives at least what $inner gives. */
function perm_scope_covers(array $outer, array $inner): bool
{
    if ($inner['church'] && !$outer['church']) {
        return false;
    }
    if ($outer['church']) {
        return true;
    }
    if ($inner['own'] && !$outer['own']) {
        return false;
    }
    return array_diff($inner['depts'], $outer['depts']) === [];
}

function perm_scope_intersect(array $a, array $b): array
{
    if ($a['church']) {
        return perm_scope_normalise($b);
    }
    if ($b['church']) {
        return perm_scope_normalise($a);
    }
    return perm_scope_normalise([
        'church' => false,
        'depts' => array_values(array_intersect($a['depts'], $b['depts'])),
        'own' => $a['own'] && $b['own'],
    ]);
}

/** A person's scope holds something beyond their own records. */
function perm_scope_beyond_own(array $s): bool
{
    return $s['church'] || $s['depts'] !== [];
}

// --------------------------------------------------------------------------
// Resolution: everything one person may do, from the live tables
// --------------------------------------------------------------------------

/**
 * The live roles of a person: [role_id => is_break_glass]. Frozen rows,
 * archived roles and rows outside their start/end dates never count. A
 * break-glass role never ends (owner answer 7).
 */
function perm_person_roles(PDO $pdo, int $userId, string $today): array
{
    $out = [];
    $stmt = $pdo->prepare(
        "SELECT r.id, COALESCE(r.is_break_glass, 0) AS bg
           FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = ?
            AND COALESCE(ur.is_frozen, 0) = 0
            AND COALESCE(r.is_archived, 0) = 0
            AND (ur.starts_on IS NULL OR ur.starts_on <= ?)
            AND (ur.ends_on IS NULL OR ur.ends_on >= ? OR COALESCE(r.is_break_glass, 0) = 1)"
    );
    $stmt->execute([$userId, $today, $today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['id']] = (int) $row['bg'] === 1;
    }
    return $out;
}

/**
 * The live seats of one person (or of everyone when $userId is null):
 * serving now in the live ministry year, a seat type the department uses, and
 * — once expiry is enforced — not past its end date (its own end_date, else
 * the ministry year's end).
 *
 * @return list<array{user_id:int, department_id:int, seat_key:string, seat_rank:int,
 *                    covers_sub_units:bool, can_appoint:bool, ends_on:?string}>
 */
function perm_live_seats(PDO $pdo, ?int $userId, string $today, ?int $deptId = null, ?string $seatKey = null): array
{
    // The roster value is matched to its seat type HERE, in PHP, never with a
    // SQL join: production's user_departments is utf8mb4_general_ci while the
    // perm_* tables are utf8mb4_unicode_ci, and comparing strings across the
    // two is an "Illegal mix of collations" error on MySQL and MariaDB.
    $byValue = [];
    foreach (perm_seat_types($pdo) as $key => $type) {
        if ((int) $type['is_archived'] === 0) {
            $byValue[(string) $type['roster_value']] = $type;
        }
    }
    if ($seatKey !== null) {
        $wanted = perm_seat_types($pdo)[$seatKey]['roster_value'] ?? null;
        if ($wanted === null) {
            return [];
        }
    }

    // Ministry years (roster_status, the year's end date) arrived in a later
    // migration than the roster itself; read them only where they exist.
    $years = perm_roster_has_years($pdo);
    $sql = $years
        ? "SELECT ud.user_id, ud.department_id, ud.role_in_dept, COALESCE(ud.end_date, y.end_date) AS ends_on
             FROM user_departments ud
             LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id
            WHERE ud.is_active = 1
              AND COALESCE(ud.roster_status, 'Active') = 'Active'"
        : "SELECT ud.user_id, ud.department_id, ud.role_in_dept, NULL AS ends_on
             FROM user_departments ud
            WHERE ud.is_active = 1";
    $params = [];
    if ($userId !== null) {
        $sql .= ' AND ud.user_id = ?';
        $params[] = $userId;
    }
    if ($deptId !== null) {
        $sql .= ' AND ud.department_id = ?';
        $params[] = $deptId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $enabled = perm_department_seats($pdo);
    $enforce = perm_expiry_enforced($pdo);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $byValue[(string) $row['role_in_dept']] ?? null;
        if ($type === null || ($seatKey !== null && $type['seat_key'] !== $seatKey)) {
            continue;
        }
        $dept = (int) $row['department_id'];
        if (($enabled[$dept][$type['seat_key']] ?? true) === false) {
            continue;   // a seat type this department does not use
        }
        $ends = $row['ends_on'] === null ? null : substr((string) $row['ends_on'], 0, 10);
        if ($enforce && $ends !== null && $ends < $today) {
            continue;
        }
        $out[] = [
            'user_id' => (int) $row['user_id'],
            'department_id' => $dept,
            'seat_key' => (string) $type['seat_key'],
            'seat_rank' => (int) $type['seat_rank'],
            'covers_sub_units' => (int) $type['covers_sub_units'] === 1,
            'is_leader' => (int) $type['is_leader'] === 1,
            'can_appoint' => (int) $type['can_appoint'] === 1,
            'ends_on' => $ends,
        ];
    }
    return $out;
}

/** Does the roster carry ministry years (roster_status, ministry_year_id, end_date)? */
function perm_roster_has_years(PDO $pdo): bool
{
    $state = &perm_state();
    if ($state['roster_years'] === null) {
        try {
            $pdo->query('SELECT ud.roster_status, ud.ministry_year_id, ud.end_date, y.end_date
                           FROM user_departments ud LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id LIMIT 0')
                ->closeCursor();
            $state['roster_years'] = true;
        } catch (Throwable $e) {
            $state['roster_years'] = false;
        }
    }
    return $state['roster_years'];
}

/** department id => seat key => enabled (missing = enabled). */
function perm_department_seats(PDO $pdo): array
{
    $state = &perm_state();
    if ($state['dept_seats'] !== null) {
        return $state['dept_seats'];
    }
    $out = [];
    try {
        foreach ($pdo->query('SELECT department_id, seat_key, is_enabled FROM perm_department_seats')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['department_id']][(string) $row['seat_key']] = (int) $row['is_enabled'] === 1;
        }
    } catch (Throwable $e) {
        error_log('permissions: department seats unreadable: ' . $e->getMessage());
    }
    return $state['dept_seats'] = $out;
}

/** The scope a grant row gives a holder, before it is split into abilities. */
function perm_grant_scope(string $scope, array $deptIds, bool $wholeModule): array
{
    switch ($scope) {
        case 'church':
            return ['church' => true, 'depts' => [], 'own' => true];
        case 'dept':
            if ($wholeModule) {
                // A seat in a department that owns a module whose data is not
                // split by department: the department's data IS the module.
                return ['church' => true, 'depts' => [], 'own' => true];
            }
            return perm_scope_normalise(['church' => false, 'depts' => $deptIds, 'own' => true]);
        case 'own':
            return ['church' => false, 'depts' => [], 'own' => true];
    }
    return perm_scope_none();
}

/**
 * Everything one person may do. Built from: their live roles, their live
 * seats, and '@everyone'. Union rule: the widest scope per ability wins.
 *
 * @return array{user_id:int, today:string, ready:bool, breakglass:bool, role_ids:int[],
 *               seats:list<array>, seat_depts:int[], abilities:array<string,array<string,array>>,
 *               sources:array<string,list<string>>}
 */
function perm_resolve(PDO $pdo, int $userId, ?string $today = null): array
{
    $today ??= perm_today();
    $ctx = [
        'user_id' => $userId, 'today' => $today, 'ready' => false, 'breakglass' => false,
        'role_ids' => [], 'seats' => [], 'seat_depts' => [], 'abilities' => [], 'sources' => [],
    ];
    if ($userId <= 0 || !perm_ready($pdo)) {
        return $ctx;
    }
    $ctx['ready'] = true;

    try {
        $roles = perm_person_roles($pdo, $userId, $today);
        $seats = perm_live_seats($pdo, $userId, $today);
    } catch (Throwable $e) {
        error_log('permissions: could not read roles/seats for user ' . $userId . ': ' . $e->getMessage());
        return $ctx;
    }

    $ctx['role_ids'] = array_keys($roles);
    $ctx['breakglass'] = in_array(true, $roles, true);

    // Departments each seat covers (its own, plus sub-units for seats that
    // oversee them), and the union for role cells scoped to "your departments".
    $seatDepts = [];
    foreach ($seats as $i => $seat) {
        $covered = $seat['covers_sub_units']
            ? perm_department_with_subunits($pdo, $seat['department_id'])
            : [$seat['department_id']];
        $seats[$i]['covers'] = $covered;
        $seatDepts = array_merge($seatDepts, $covered);
    }
    $seatDepts = array_values(array_unique($seatDepts));
    sort($seatDepts);
    $ctx['seats'] = $seats;
    $ctx['seat_depts'] = $seatDepts;

    // The grant rows for every holder this person counts as.
    $holderSeats = array_values(array_unique(array_merge([PERM_EVERYONE], array_column($seats, 'seat_key'))));
    $sql = 'SELECT role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope
              FROM perm_grants
             WHERE (role_id = 0 AND seat_key IN (' . implode(',', array_fill(0, count($holderSeats), '?')) . '))';
    $params = $holderSeats;
    if ($ctx['role_ids'] !== []) {
        $sql .= " OR (seat_key = '' AND role_id IN (" . implode(',', array_fill(0, count($ctx['role_ids']), '?')) . '))';
        $params = array_merge($params, $ctx['role_ids']);
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $grants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('permissions: could not read grants: ' . $e->getMessage());
        return $ctx;
    }

    $modules = perm_modules();
    $owners = perm_module_owners($pdo);

    foreach ($grants as $g) {
        $module = (string) $g['module_key'];
        if (!isset($modules[$module])) {
            continue;   // a row for a module the code no longer has
        }
        $partitioned = !empty($modules[$module]['partitioned']);
        $roleId = (int) $g['role_id'];
        $seatKey = (string) $g['seat_key'];

        if ($roleId > 0) {
            // A role cell. "dept" means the departments the person sits in.
            perm_apply_grant($ctx, $module, $g, $seatDepts, false, 'role:' . $roleId);
        } elseif ($seatKey === PERM_EVERYONE) {
            perm_apply_grant($ctx, $module, $g, [], false, 'everyone');
        } else {
            // A seat cell: once per seat of that type the person holds in a
            // department the cell applies to.
            $ownerIds = $owners[$module] ?? [];
            foreach ($seats as $seat) {
                if ($seat['seat_key'] !== $seatKey) {
                    continue;
                }
                $applies = $partitioned || in_array(0, $ownerIds, true) || in_array($seat['department_id'], $ownerIds, true);
                if (!$applies) {
                    continue;
                }
                $covered = ($modules[$module]['sub_units'] ?? true) ? $seat['covers'] : [$seat['department_id']];
                perm_apply_grant($ctx, $module, $g, $covered, !$partitioned,
                    'seat:' . $seatKey . '@' . $seat['department_id']);
            }
        }
    }

    foreach ($ctx['abilities'] as $module => $abilities) {
        foreach ($abilities as $ability => $scope) {
            $ctx['abilities'][$module][$ability] = perm_scope_normalise($scope);
        }
    }

    return $ctx;
}

/** Add one grant row to a context, split into its abilities. */
function perm_apply_grant(array &$ctx, string $module, array $g, array $deptIds, bool $wholeModule, string $source): void
{
    $level = max(0, min(4, (int) $g['level']));
    $scope = perm_grant_scope((string) $g['scope'], $deptIds, $wholeModule);
    $viewScope = ($g['view_scope'] === null || $g['view_scope'] === '')
        ? $scope
        : perm_grant_scope((string) $g['view_scope'], $deptIds, $wholeModule);
    if (perm_scope_is_none($scope) && perm_scope_is_none($viewScope)) {
        return;
    }

    $give = [];
    foreach (PERM_LEVELS as $name => $rank) {
        if ($rank >= 1 && $rank <= $level) {
            $give[$name] = $name === 'view' ? $viewScope : $scope;
        }
    }
    if ((int) $g['can_approve'] === 1) {
        $give['approve'] = $scope;
    }
    if ((int) $g['can_export'] === 1) {
        $give['export'] = $viewScope;
    }
    if ((int) $g['can_manage'] === 1) {
        $give['manage'] = $scope;
    }

    foreach ($give as $ability => $s) {
        $ctx['abilities'][$module][$ability] = isset($ctx['abilities'][$module][$ability])
            ? perm_scope_union($ctx['abilities'][$module][$ability], $s)
            : $s;
    }
    if ($give !== []) {
        $ctx['sources'][$module][] = $source;
    }
}

/** The signed-in person's context, built once per request. */
function perm_current(): ?array
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $pdo = perm_pdo();
    if ($uid <= 0 || !$pdo) {
        return null;
    }
    $state = &perm_state();
    $key = $uid . '|' . perm_today();
    if (!isset($state['contexts'][$key])) {
        $state['contexts'][$key] = perm_resolve($pdo, $uid);
        if ($state['contexts'][$key]['breakglass']) {
            perm_breakglass_hook();
        }
    }
    return $state['contexts'][$key];
}

// --------------------------------------------------------------------------
// The one decision
// --------------------------------------------------------------------------

/** A target meaning "may act on this person": church, or they sit in one of your departments, or it is you. */
function perm_person(int $userId): array
{
    return ['person' => $userId];
}

/** Is the module and ability one the registry knows? Unknown ones fail closed. */
function perm_known(string $module, string $ability): bool
{
    $modules = perm_modules();
    if (!isset($modules[$module])) {
        perm_complain("unknown module '{$module}'");
        return false;
    }
    if (!in_array($ability, PERM_ABILITIES, true)) {
        perm_complain("unknown level '{$ability}' for '{$module}'");
        return false;
    }
    $def = $modules[$module];
    if (isset(PERM_LEVELS[$ability]) && PERM_LEVELS[$ability] > PERM_LEVELS[$def['max'] ?? 'none']) {
        perm_complain("'{$module}' has no '{$ability}' level");
        return false;
    }
    if (in_array($ability, PERM_EXTRAS, true) && !in_array($ability, (array) ($def['extras'] ?? []), true)) {
        perm_complain("'{$module}' has no '{$ability}'");
        return false;
    }
    return true;
}

/** The scope a context holds for one ability (none when it holds nothing). */
function perm_ctx_scope(array $ctx, string $module, string $ability): array
{
    return $ctx['abilities'][$module][$ability] ?? perm_scope_none();
}

/** The live departments a person serves in (for perm_person targets). */
function perm_person_departments(PDO $pdo, int $userId): array
{
    $state = &perm_state();
    if (!isset($state['person_depts'][$userId])) {
        $ids = [];
        try {
            foreach (perm_live_seats($pdo, $userId, perm_today()) as $seat) {
                $ids[] = $seat['department_id'];
            }
        } catch (Throwable $e) {
            error_log('permissions: departments of user ' . $userId . ': ' . $e->getMessage());
        }
        $state['person_depts'][$userId] = array_values(array_unique($ids));
    }
    return $state['person_depts'][$userId];
}

/** Does $scope reach $target? (null = anything at all) */
function perm_scope_reaches(array $scope, $target, int $me, ?PDO $pdo = null): bool
{
    if (perm_scope_is_none($scope)) {
        return false;
    }
    if ($target === null) {
        return true;
    }
    if ($scope['church']) {
        return true;
    }
    if ($target === 'church') {
        return false;
    }
    if (is_int($target)) {
        return in_array($target, $scope['depts'], true);
    }
    if (is_array($target) && isset($target['person'])) {
        $person = (int) $target['person'];
        if ($scope['own'] && $person === $me && $me > 0) {
            return true;
        }
        if ($scope['depts'] !== [] && $pdo) {
            return array_intersect(perm_person_departments($pdo, $person), $scope['depts']) !== [];
        }
        return false;
    }
    perm_complain('unknown target ' . json_encode($target));
    return false;
}

/** The same decision as can(), for any resolved context (parity, holders, tests). */
function perm_ctx_can(array $ctx, string $module, string $ability = 'view', $target = null): bool
{
    if (!perm_known($module, $ability) || !$ctx['ready']) {
        return false;
    }
    return perm_scope_reaches(perm_ctx_scope($ctx, $module, $ability), $target, (int) $ctx['user_id'], perm_pdo());
}

/**
 * THE ACCESS CHECK.
 *
 *   can('reach')                         may see Reach at all
 *   can('requisition', 'approve', 7)     may approve for department 7
 *   can('congregation', 'delete', 'church')
 *   can('charis', 'edit', perm_person($id))
 */
function can(string $module, string $level = 'view', $target = null): bool
{
    $ctx = perm_current();
    if ($ctx === null || !perm_known($module, $level)) {
        return false;
    }
    if (perm_ctx_can($ctx, $module, $level, $target)) {
        return true;
    }
    if ($ctx['breakglass']) {
        perm_breakglass_note($module, $level);
        return true;
    }
    return false;
}

/** What the signed-in person may reach for one ability, to filter rows with. */
function perm_scope(string $module, string $level = 'view'): array
{
    $ctx = perm_current();
    if ($ctx === null || !perm_known($module, $level)) {
        return perm_scope_none();
    }
    if ($ctx['breakglass']) {
        $mine = perm_ctx_scope($ctx, $module, $level);
        if (!$mine['church']) {
            perm_breakglass_note($module, $level);
        }
        return ['church' => true, 'depts' => [], 'own' => true];
    }
    return perm_ctx_scope($ctx, $module, $level);
}

/**
 * A WHERE fragment limiting rows to what the person may reach.
 *
 * $cols: 'dept' => column holding a department id, 'person' => column holding
 * a user id (matched against the people in your departments, or you),
 * 'owner' => column holding the user id a row belongs to (own scope).
 *
 * @return array{0:string,1:array} [sql, params]
 */
function perm_where(string $module, string $level, array $cols): array
{
    $scope = perm_scope($module, $level);
    if ($scope['church']) {
        return ['1=1', []];
    }
    $parts = [];
    $params = [];
    $me = (int) ($_SESSION['user_id'] ?? 0);
    if ($scope['depts'] !== []) {
        $in = implode(',', array_fill(0, count($scope['depts']), '?'));
        if (!empty($cols['dept'])) {
            $parts[] = $cols['dept'] . " IN ({$in})";
            $params = array_merge($params, $scope['depts']);
        }
        if (!empty($cols['person'])) {
            $pdo = perm_pdo();
            $live = ($pdo && perm_roster_has_years($pdo)) ? " AND COALESCE(pud.roster_status, 'Active') = 'Active'" : '';
            $parts[] = $cols['person'] . " IN (SELECT pud.user_id FROM user_departments pud
                                               WHERE pud.is_active = 1{$live}
                                                 AND pud.department_id IN ({$in}))";
            $params = array_merge($params, $scope['depts']);
        }
    }
    if ($scope['own'] && $me > 0) {
        foreach (['owner', 'person'] as $key) {
            if (!empty($cols[$key])) {
                $parts[] = $cols[$key] . ' = ?';
                $params[] = $me;
                break;
            }
        }
    }
    return $parts === [] ? ['1=0', []] : ['(' . implode(' OR ', $parts) . ')', $params];
}

/** Should the navigation show this module to the person? */
function perm_ctx_nav_visible(array $ctx, string $module): bool
{
    $def = perm_modules()[$module] ?? null;
    if ($def === null || !$ctx['ready']) {
        return false;
    }
    if ($ctx['breakglass'] || !empty($def['member'])) {
        return true;
    }
    $abilities = $def['nav'] ?? PERM_ABILITIES;
    foreach ($abilities as $ability) {
        if (perm_scope_beyond_own(perm_ctx_scope($ctx, $module, $ability))) {
            return true;
        }
    }
    return false;
}

function perm_nav_visible(string $module): bool
{
    $ctx = perm_current();
    return $ctx !== null && perm_ctx_nav_visible($ctx, $module);
}

/**
 * For pages, after includes/header.php: the shell's standard "not open to you"
 * state (ui_no_access(), docs/UI.md) and the footer, then stop.
 */
function require_can(string $module, string $level = 'view', $target = null): void
{
    if (can($module, $level, $target)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(403);
    }
    if (function_exists('ui_no_access')) {
        echo ui_no_access();
        require __DIR__ . '/footer.php';
    } else {
        echo 'This page is not open to you.';
    }
    exit;
}

/** For endpoints: 401 signed out, 403 refused, in the JSON shape every API uses. */
function api_require_can(string $module, string $level = 'view', $target = null): void
{
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.']);
        exit;
    }
    if (!can($module, $level, $target)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'code' => 'FORBIDDEN',
            'message' => 'You do not have access to this. Ask the church office if you think you should.']);
        exit;
    }
}

// --------------------------------------------------------------------------
// Break-glass: always allowed, never silent
// --------------------------------------------------------------------------

function perm_breakglass_note(string $module, string $level): void
{
    $state = &perm_state();
    $state['breakglass_used'][$module . ':' . $level] = true;
    perm_breakglass_hook();
}

/**
 * One security_audit_log row per request in which a break-glass holder either
 * relied on break-glass for a decision, or changed something (any non-GET).
 * Written at shutdown, like the push dispatcher: no output, no extra query
 * while the page is being built.
 */
function perm_breakglass_hook(): void
{
    $state = &perm_state();
    if ($state['breakglass_hooked'] || PHP_SAPI === 'cli') {
        return;
    }
    $state['breakglass_hooked'] = true;
    register_shutdown_function('perm_breakglass_flush');
}

function perm_breakglass_flush(): void
{
    $state = &perm_state();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $used = array_keys($state['breakglass_used']);
    if ($used === [] && in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }
    $pdo = perm_pdo();
    if (!$pdo || !function_exists('security_log')) {
        return;
    }
    $details = json_encode([
        'method' => $method,
        'path' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        'action' => substr((string) ($_POST['action'] ?? $_GET['action'] ?? ''), 0, 60),
        'used' => $used,
    ], JSON_UNESCAPED_SLASHES);
    try {
        security_log($pdo, 'breakglass', null, (string) $details);
    } catch (Throwable $e) {
        error_log('permissions: break-glass log failed: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// Appointing seats (lead decision 6)
// --------------------------------------------------------------------------

/** The stable seat key for a value stored in user_departments.role_in_dept (null if none). */
function perm_seat_key_for(PDO $pdo, string $rosterValue): ?string
{
    foreach (perm_seat_types($pdo) as $key => $type) {
        if ((string) $type['roster_value'] === $rosterValue) {
            return (string) $key;
        }
    }
    return null;
}

/**
 * May this person put someone in (renew, or end) a $seatKey seat of
 * department $deptId? Only a seat ranked BELOW your own standing there:
 * your highest live seat covering that department (if that seat type may
 * appoint) or your highest role appoint_rank (Resident Pastor 80, Assoc Pastor
 * 70 on day one). Super Admins and holders of "Roles & permissions: Manage"
 * are exempt. You also need Departments edit for that department.
 */
function perm_ctx_can_appoint(PDO $pdo, array $ctx, int $deptId, string $seatKey): bool
{
    if (!$ctx['ready']) {
        return false;
    }
    if ($ctx['breakglass'] || perm_ctx_can($ctx, 'roles', 'manage')) {
        return true;
    }
    $target = perm_seat_types($pdo)[$seatKey]['seat_rank'] ?? null;
    if ($target === null || !perm_ctx_can($ctx, 'departments', 'edit', $deptId)) {
        return false;
    }
    $standing = 0;
    foreach ($ctx['seats'] as $seat) {
        if (!empty($seat['can_appoint']) && in_array($deptId, $seat['covers'] ?? [$seat['department_id']], true)) {
            $standing = max($standing, (int) $seat['seat_rank']);
        }
    }
    if ($ctx['role_ids'] !== []) {
        try {
            $stmt = $pdo->prepare('SELECT MAX(COALESCE(appoint_rank, 0)) FROM roles WHERE id IN ('
                . implode(',', array_fill(0, count($ctx['role_ids']), '?')) . ')');
            $stmt->execute($ctx['role_ids']);
            $standing = max($standing, (int) $stmt->fetchColumn());
        } catch (Throwable $e) {
            error_log('permissions: appoint rank: ' . $e->getMessage());
        }
    }
    return $standing > (int) $target;
}

// --------------------------------------------------------------------------
// Who holds what (notification audiences, the mailbox, the parity report)
// --------------------------------------------------------------------------

/**
 * Live seats by STABLE seat key (includes/permissions/modules.php §1b of the
 * design): the people sitting in $seatKey, optionally in one department.
 *
 * @return int[] user ids
 */
function perm_seat_holders(PDO $pdo, string $seatKey, ?int $deptId = null): array
{
    if (!perm_ready($pdo)) {
        return [];
    }
    try {
        $ids = array_column(perm_live_seats($pdo, null, perm_today(), $deptId, $seatKey), 'user_id');
    } catch (Throwable $e) {
        error_log('permissions: seat holders: ' . $e->getMessage());
        return [];
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

/**
 * A module's team: everyone with a live seat in a department that OWNS the
 * module (perm_module_owners; "every department" rows are ignored — that is
 * not a team). Used for "who can this be assigned to" lists: Reach leads,
 * Charis cases, Embrace follow-ups, rosters.
 *
 * @return int[] user ids
 */
function perm_module_team(PDO $pdo, string $module): array
{
    if (!perm_ready($pdo)) {
        return [];
    }
    $owners = array_values(array_filter(perm_module_owners($pdo)[$module] ?? [], static fn (int $d): bool => $d > 0));
    $ids = [];
    try {
        foreach ($owners as $dept) {
            foreach (perm_live_seats($pdo, null, perm_today(), $dept) as $seat) {
                $ids[$seat['user_id']] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('permissions: module team: ' . $e->getMessage());
        return [];
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
}

/** The people in LEADERSHIP seats of the departments that own $module (who hear about its work). */
function perm_module_leaders(PDO $pdo, string $module): array
{
    if (!perm_ready($pdo)) {
        return [];
    }
    $ids = [];
    try {
        foreach (perm_module_owners($pdo)[$module] ?? [] as $dept) {
            if ($dept <= 0) {
                continue;
            }
            foreach (perm_live_seats($pdo, null, perm_today(), $dept) as $seat) {
                if (!empty($seat['is_leader'])) {
                    $ids[$seat['user_id']] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('permissions: module leaders: ' . $e->getMessage());
        return [];
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
}

/** @return list<array{seat_key:string, department_id:int, ends_on:?string}> */
function perm_person_seats(PDO $pdo, int $userId): array
{
    if (!perm_ready($pdo)) {
        return [];
    }
    try {
        $seats = perm_live_seats($pdo, $userId, perm_today());
    } catch (Throwable $e) {
        error_log('permissions: person seats: ' . $e->getMessage());
        return [];
    }
    return array_map(static fn (array $s): array => [
        'seat_key' => $s['seat_key'], 'department_id' => $s['department_id'], 'ends_on' => $s['ends_on'],
    ], $seats);
}

/**
 * Everyone (live, unfrozen, active account) who passes can() for a module and
 * level — and, with $deptId, for that department. "Who can act is who is told."
 * Break-glass holders are left out unless asked for: a Super Admin should not
 * receive every module's notifications.
 *
 * @return int[] user ids
 */
function perm_holders(PDO $pdo, string $module, string $level = 'view', ?int $deptId = null, bool $withBreakGlass = false): array
{
    if (!perm_ready($pdo) || !perm_known($module, $level)) {
        return [];
    }
    $today = perm_today();
    $candidates = [];
    try {
        // Holders of a role with a cell on this module (or break-glass).
        $stmt = $pdo->prepare(
            "SELECT DISTINCT ur.user_id
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE COALESCE(ur.is_frozen, 0) = 0
                AND (EXISTS (SELECT 1 FROM perm_grants g WHERE g.role_id = r.id AND g.module_key = ?)
                     OR (? = 1 AND COALESCE(r.is_break_glass, 0) = 1))"
        );
        $stmt->execute([$module, $withBreakGlass ? 1 : 0]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $candidates[(int) $id] = true;
        }
        // Seat holders of a seat type with a cell on this module.
        $stmt = $pdo->prepare("SELECT DISTINCT seat_key FROM perm_grants WHERE role_id = 0 AND module_key = ? AND seat_key <> ?");
        $stmt->execute([$module, PERM_EVERYONE]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $seatKey) {
            foreach (perm_live_seats($pdo, null, $today, null, (string) $seatKey) as $seat) {
                $candidates[$seat['user_id']] = true;
            }
        }
        // '@everyone' cells reach everybody; a notification audience never
        // wants that, so they are not expanded here.
    } catch (Throwable $e) {
        error_log('permissions: holders: ' . $e->getMessage());
        return [];
    }

    $active = perm_active_accounts($pdo, array_keys($candidates));
    $out = [];
    foreach (array_keys($candidates) as $uid) {
        if (!isset($active[$uid])) {
            continue;
        }
        $ctx = perm_resolve($pdo, $uid, $today);
        $scope = perm_ctx_scope($ctx, $module, $level);
        // An own-only cell is not a reason to be told about other people's work.
        $ok = perm_scope_beyond_own($scope) && perm_scope_reaches($scope, $deptId, $uid, $pdo);
        if (!$ok && $withBreakGlass && $ctx['breakglass']) {
            $ok = true;
        }
        if ($ok) {
            $out[] = $uid;
        }
    }
    sort($out);
    return $out;
}

/** user id => true for accounts that may sign in (all of them before the security schema). */
function perm_active_accounts(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ({$in}) AND COALESCE(account_status, 'active') = 'active'");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ({$in})");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_fill_keys(array_map('intval', $rows), true);
}

// --------------------------------------------------------------------------
// History: who / what / when / before / after
// --------------------------------------------------------------------------

/**
 * Append one change to perm_history. Never throws.
 *
 * $row keys: via, change_type (required); actor_user_id, actor_label,
 * subject_user_id, role_id, department_id, seat_key, module_key, before, after
 * (arrays, stored as JSON), reason.
 */
function perm_history_add(PDO $pdo, array $row): bool
{
    if (empty($row['via']) || empty($row['change_type'])) {
        error_log('permissions: history row without via/change_type');
        return false;
    }
    $actor = $row['actor_user_id'] ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    $label = $row['actor_label'] ?? trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO perm_history (created_at, actor_user_id, actor_label, via, change_type, subject_user_id, role_id,
                                       department_id, seat_key, module_key, before_json, after_json, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s'),
            $actor,
            $label === '' ? null : substr($label, 0, 150),
            substr((string) $row['via'], 0, 20),
            substr((string) $row['change_type'], 0, 40),
            $row['subject_user_id'] ?? null,
            $row['role_id'] ?? null,
            $row['department_id'] ?? null,
            $row['seat_key'] ?? null,
            $row['module_key'] ?? null,
            isset($row['before']) ? json_encode($row['before'], JSON_UNESCAPED_SLASHES) : null,
            isset($row['after']) ? json_encode($row['after'], JSON_UNESCAPED_SLASHES) : null,
            isset($row['reason']) ? substr((string) $row['reason'], 0, 255) : null,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('permissions: history write failed: ' . $e->getMessage());
        return false;
    }
}
