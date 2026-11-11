<?php
// /includes/permissions/legacy_rules.php
//
// A FROZEN COPY of the access rules as they stood before the switch to can()
// (main at ea78344, audited line by line on 11 Nov 2026). It exists for one
// purpose: to prove, person by person, that the day-one matrix gives everyone
// what they had. bin/permissions_parity.php and tests/permissions/ compare it
// with can(); nothing else may call it, and no page or endpoint ever decides
// anything with it.
//
// It deliberately keeps today's quirks, including the ones the switch removes:
// role names, department ids and names, the "active role" (the person's role
// with the lowest id — what auth_start_session() picks in practice, because
// MySQL walks user_roles by its (user_id, role_id) key), and the account id
// charis_api.php hard-codes as Finance Director. Do not "fix" anything here.
//
// "What a person can do" means what works through the screens: where a page
// and its API disagree, the narrower of the two; endpoints with no gate at all
// are marked as holes (closed by the switch, decisions 1 and 8).

if (defined('HOD_PERMISSIONS_LEGACY')) {
    return;
}
define('HOD_PERMISSIONS_LEGACY', true);

/** The account charis_api.php:1936 treats as Finance Director today. */
const LEGACY_CHARIS_COUNTERSIGN_USER_ID = 22;

/** Envision's department id in includes/special_events/constants.php. */
const LEGACY_SE_ENVISION_DEPT_ID = 3;

/**
 * Module => abilities whose LOSS is a hole the switch closes on purpose
 * (lead decisions 1 and 8): reachable today only by calling an ungated
 * endpoint, never offered by a screen.
 */
const LEGACY_HOLES = [
    'embrace' => ['view', 'create', 'edit', 'delete', 'approve', 'export', 'manage'],
    'junior_church' => ['view', 'create', 'edit', 'delete'],
    'announcements' => ['edit'],
    'events.private_notes' => ['create', 'edit'],
    'requisition' => ['export'],
];

/**
 * Everything the old rules looked at for one person.
 *
 * @return array{user_id:int, roles:list<string>, frozen_roles:list<string>, ar:string,
 *               seats:list<array{dept:int,seat:string}>, any_seats:list<array{dept:int,seat:string}>}
 */
function legacy_snapshot(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.role_name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = ? AND COALESCE(ur.is_frozen, 0) = 0 ORDER BY r.id'
    );
    $stmt->execute([$userId]);
    $roles = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare(
        'SELECT r.role_name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = ? AND COALESCE(ur.is_frozen, 0) = 1 ORDER BY r.id'
    );
    $stmt->execute([$userId]);
    $frozen = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare('SELECT department_id, role_in_dept, is_active FROM user_departments WHERE user_id = ?');
    $stmt->execute([$userId]);
    $seats = [];
    $any = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $seat = ['dept' => (int) $row['department_id'], 'seat' => (string) $row['role_in_dept']];
        $any[] = $seat;
        if ((int) $row['is_active'] === 1) {
            $seats[] = $seat;
        }
    }

    return [
        'user_id' => $userId,
        'roles' => $roles,
        'frozen_roles' => $frozen,
        'ar' => $roles[0] ?? 'Member',
        'seats' => $seats,
        'any_seats' => $any,
    ];
}

/** id => ['name' => …, 'type' => …, 'parent' => …] */
function legacy_departments(PDO $pdo): array
{
    static $cache = null;
    static $for = null;
    if ($cache !== null && $for === spl_object_id($pdo)) {
        return $cache;
    }
    $out = [];
    try {
        $rows = $pdo->query('SELECT id, name, type, parent_id FROM departments')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = $pdo->query('SELECT id, name, NULL AS type, parent_id FROM departments')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rows as $row) {
        $out[(int) $row['id']] = [
            'name' => (string) $row['name'],
            'type' => $row['type'] === null ? null : (string) $row['type'],
            'parent' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        ];
    }
    $for = spl_object_id($pdo);
    return $cache = $out;
}

/** SQL LIKE as MySQL's case-insensitive collations evaluate it (% and _ wildcards). */
function legacy_like(string $value, string $pattern): bool
{
    $regex = '';
    foreach (preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $regex .= $ch === '%' ? '.*' : ($ch === '_' ? '.' : preg_quote($ch, '/'));
    }
    return (bool) preg_match('/^' . $regex . '$/isu', $value);
}

/** Department ids whose name matches any of the patterns (= is exact, case-insensitive). */
function legacy_depts_named(array $depts, array $patterns): array
{
    $ids = [];
    foreach ($depts as $id => $d) {
        foreach ($patterns as $p) {
            if (legacy_like($d['name'], $p)) {
                $ids[] = $id;
                break;
            }
        }
    }
    return $ids;
}

/** The department plus every department below it. */
function legacy_with_subunits(array $depts, array $ids): array
{
    $out = array_fill_keys($ids, true);
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($depts as $id => $d) {
            if ($d['parent'] !== null && isset($out[$d['parent']]) && !isset($out[$id])) {
                $out[$id] = true;
                $changed = true;
            }
        }
    }
    $ids = array_keys($out);
    sort($ids);
    return $ids;
}

/**
 * The old rules, evaluated for one person with a given "active role".
 *
 * @return array<string, array<string, array>> module => ability => scope
 *         (scopes in the shape of includes/permissions.php)
 */
function legacy_abilities(array $p, array $depts, ?string $ar = null): array
{
    $ar ??= $p['ar'];
    $roles = $p['roles'];
    $C = ['church' => true, 'depts' => [], 'own' => true];
    $O = ['church' => false, 'depts' => [], 'own' => true];
    $D = static fn (array $ids): array => ['church' => false, 'depts' => array_values(array_unique($ids)), 'own' => true];

    $sr = static fn (array $list): bool => array_intersect($list, $roles) !== [];
    $arIn = static fn (array $list): bool => in_array($ar, $list, true);
    $seatDepts = static function (?array $seatNames = null, bool $evenInactive = false) use ($p): array {
        $ids = [];
        foreach ($evenInactive ? $p['any_seats'] : $p['seats'] as $s) {
            if ($seatNames === null || in_array($s['seat'], $seatNames, true)) {
                $ids[] = $s['dept'];
            }
        }
        return array_values(array_unique($ids));
    };
    $seatIn = static fn (array $ids, ?array $seatNames = null, bool $evenInactive = false): bool
        => array_intersect($ids, $seatDepts($seatNames, $evenInactive)) !== [];

    $sa = in_array('Super_Admin', $roles, true);
    $P3 = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];

    // full(scope, ladder max, extras): every ability up to the level, plus extras.
    $full = static function (array $scope, int $max, array $extras = []) : array {
        $out = [];
        foreach (['view' => 1, 'create' => 2, 'edit' => 3, 'delete' => 4] as $name => $rank) {
            if ($rank <= $max) {
                $out[$name] = $scope;
            }
        }
        foreach ($extras as $x) {
            $out[$x] = $scope;
        }
        return $out;
    };

    $a = [];

    // --- Open to every signed-in member --------------------------------------
    $a['member_portal'] = $full($O, 3);
    $a['profile'] = $full($O, 3);
    $a['notifications'] = $full($O, 3);
    $a['bible'] = ['view' => $C, 'create' => $O, 'edit' => $O];
    if ($sr(['Super_Admin', 'Resident_Pastor'])) {                       // security_is_admin (live roles)
        $a['bible']['manage'] = $C;
    }
    $a['library'] = ['view' => $C, 'create' => $O];

    // --- Dashboard: tier from the active role, scope from the seat ----------
    if ($arIn($P3)) {
        $a['dashboard'] = ['view' => $C];
    } elseif ($ar === 'Director' || $ar === 'HOD') {
        $led = legacy_with_subunits($depts, $seatDepts([$ar]));
        $a['dashboard'] = ['view' => $led === [] ? $O : $D($led)];
    } else {
        $a['dashboard'] = ['view' => $O];
    }

    // --- Hymnary: Studio = pastors or ANY River of Life seat ----------------
    $rol = legacy_depts_named($depts, ['%River of Life%']);
    $rolId = $rol === [] ? 4 : min($rol);
    $a['hymnal'] = ['view' => $C];
    if ($sr($P3) || $seatIn([$rolId])) {
        $a['hymnal'] += ['create' => $C, 'edit' => $C];
    }
    if ($sr($P3)) {
        $a['hymnal'] += ['approve' => $C, 'manage' => $C];
    }

    // --- Regions --------------------------------------------------------------
    $a['regions'] = $full($O, 2);
    $idiRole = false;
    foreach ($roles as $r) {
        if (str_contains($r, 'IDI')) {
            $idiRole = true;
        }
    }
    if ($sr(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director']) || $idiRole) {
        $a['regions'] = $full($C, 4, ['export', 'manage']);
    }

    // --- IDI ------------------------------------------------------------------
    if ($sr($P3) || $seatIn([1])) {
        $a['idi'] = ['view' => $C];
    }

    // --- Congregation (and the search palette's people search) ---------------
    if ($sr(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'])) {
        $a['congregation'] = $full($C, 4);
    }

    // --- Reach: any seat in a department NAMED Reach/Evangelism --------------
    $reach = legacy_depts_named($depts, ['%Reach%', '%Evangelism%']);
    if ($arIn($P3) || $seatIn($reach)) {
        $a['reach'] = $full($C, 4, ['export']);
        if ($arIn($P3) || $seatIn($reach, ['HOD', 'Director'])) {
            $a['reach']['manage'] = $C;
        }
    }

    // --- Embrace: no gate at all today (a hole) ------------------------------
    $a['embrace'] = $full($C, 4, ['approve', 'export', 'manage']);

    // --- Assimilation: managers (the volunteer roster is the module's own) ---
    if ($arIn($P3) || $seatDepts(['HOD', 'Director']) !== []) {
        $a['assimilation'] = $full($C, 4, ['export', 'manage']);
    }

    // --- Charis ----------------------------------------------------------------
    $charis = array_values(array_unique(array_merge([1, 10],
        legacy_depts_named($depts, ['%Charis%', '%Welfare%', 'IDI']))));
    $charisAccess = $sr($P3) || $seatIn($charis);
    if ($charisAccess) {
        $a['charis'] = $full($C, 3);
        if ($sr($P3) || $seatIn($charis, ['Director', 'HOD'])) {
            $a['charis'] += ['delete' => $C, 'approve' => $C, 'export' => $C];
        }
        if ($sa || $seatIn($charis, ['Director', 'HOD'])) {
            $a['charis']['manage'] = $C;
        }
    }

    // --- Academy: admin = pastors or ANY seat, even inactive -----------------
    $a['academy'] = $full($O, 2);
    $academy = legacy_depts_named($depts, ['%Academy%', '%School%']);
    if ($arIn($P3) || $seatIn($academy, null, true)) {
        $a['academy'] = $full($C, 4, ['approve', 'manage']);
    }

    // --- Junior Church: no gate at all today (a hole) ------------------------
    $a['junior_church'] = $full($C, 4);

    // --- River of Life: id 4 ---------------------------------------------------
    if ($arIn($P3) || $seatIn([4])) {
        $a['river_of_life'] = $full($C, 4);
    }

    // --- Envision: any seat in a department NAMED Envision -------------------
    if ($arIn($P3) || $seatIn(legacy_depts_named($depts, ['%Envision%']))) {
        $a['envision'] = $full($C, 4, ['approve', 'manage']);
    }

    // --- Special Events (crew-only access is the module's own roster) --------
    $seRoles = array_unique(array_merge($roles, [$ar]));
    if (array_intersect($P3, $seRoles) !== [] || $seatIn([LEGACY_SE_ENVISION_DEPT_ID], ['HOD', 'Director'])) {
        $a['special_events'] = $full($C, 4, ['manage']);
    } elseif ($seatIn([LEGACY_SE_ENVISION_DEPT_ID])) {
        $a['special_events'] = $full($C, 2);
    }

    // --- Zoe (membership is the module's own roster) -------------------------
    $a['zoe'] = $full($O, 2);
    if ($arIn(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'])) {
        $a['zoe'] = $full($C, 4, ['manage']);
    }

    // --- Departments: the page AND the API must both let you in --------------
    $page = $arIn($P3) || $sa || $seatDepts(['Director', 'HOD', 'Sub_Unit_Head']) !== [];
    $api = $arIn(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD']);
    if ($page && $api) {
        $edit = $arIn($P3)
            ? $C
            : $D(legacy_with_subunits($depts, $seatDepts(['Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'])));
        $a['departments'] = ['view' => $C, 'create' => $edit, 'edit' => $edit, 'export' => $C];
        if ($arIn(['Super_Admin', 'Resident_Pastor'])) {
            $a['departments']['manage'] = $C;
        }
    }

    // --- Tribes: global view for pastors (the Director/HOD branch errors) ----
    $a['tribes'] = $full($O, 2);
    if ($arIn($P3)) {
        $a['tribes'] = $full($C, 4);
    }

    // --- Events and everything hanging off them ------------------------------
    $eventsRoles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
    if ($sr($eventsRoles)) {
        $a['events'] = $full($C, 4, ['export']);
        $a['events.private_notes'] = ['create' => $C, 'edit' => $C];      // write-only today (a hole)
    }
    if ($sr(['Resident_Pastor', 'Assoc_Pastor', 'Super_Admin'])) {
        $a['events.private_notes'] = $full($C, 3);
    }
    if ($sa) {
        $a['events.unlock'] = $full($C, 3);
    }
    if ($sr(array_merge($eventsRoles, ['Admin']))) {
        $a['contact_extractor'] = ['view' => $C, 'export' => $C];
    }

    // --- SMS Studio -------------------------------------------------------------
    if ($sr(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'])) {
        $a['sms_studio'] = $full($C, 4, ['manage']);
    }

    // --- Assets: names IDI / Envision / River of Life ------------------------
    if ($arIn($P3) || $seatIn(legacy_depts_named($depts, ['%IDI%', '%Envision%', '%River of Life%']))) {
        $a['assets'] = $full($C, 4);
    }

    // --- Pastoral Desk ------------------------------------------------------------
    $a['pastoral'] = $full($O, 2);
    if ($arIn(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'])) {
        $a['pastoral'] = $full($C, 3);
    }
    if ($ar === 'Super_Admin') {
        $a['pastoral']['manage'] = $C;
    }

    // --- Announcements: everyone reads all and may rewrite any (a hole) ------
    $a['announcements'] = ['view' => $C, 'create' => $O, 'edit' => $C];
    if ($arIn(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'])) {
        $a['announcements'] = $full($C, 4, ['approve']);
    }

    // --- Testimonies, Finance -----------------------------------------------------
    if ($arIn(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'])) {
        $a['testimonies'] = $full($C, 4);
    }
    if ($arIn(['Super_Admin', 'Resident_Pastor', 'Director'])) {
        $a['finance'] = $full($C, 4);
    }
    if ($charisAccess
        && ((int) $p['user_id'] === LEGACY_CHARIS_COUNTERSIGN_USER_ID || $sr(['Director', 'HOD', 'Super_Admin']))) {
        $a['finance.countersign'] = ['approve' => $C];
    }

    // --- Requisitions ---------------------------------------------------------------
    $finance = [];
    foreach ($depts as $id => $d) {
        if ($d['name'] === 'Finance' && $d['type'] === 'Administrative') {
            $finance[] = $id;
            break;
        }
    }
    $isFinDir = $finance !== [] && $seatIn($finance, ['Director']);
    $saRp = $sr(['Super_Admin', 'Resident_Pastor']);
    $directorSeatDepts = $seatDepts(['Director']);

    $req = ['view' => $O];
    if ($saRp) {
        $req['view'] = $C;
    } elseif ($sr(['Director']) && $directorSeatDepts !== []) {
        $req['view'] = $D($directorSeatDepts);
    }
    if ($sr(['Super_Admin', 'Resident_Pastor', 'Director', 'HOD', 'Assoc_Pastor'])) {
        $submit = $saRp ? $C : $seatDepts(['HOD', 'Director', 'Assoc_Pastor']);
        if ($submit === $C) {
            $req['create'] = $C;
        } elseif ($submit !== []) {
            $req['create'] = $D($submit);
        }
    }
    if ($saRp) {
        $req['approve'] = $C;
    } elseif ($sr(['Director']) && $directorSeatDepts !== []) {
        $req['approve'] = $D($directorSeatDepts);
    }
    if ($sr(['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD'])) {
        $req['export'] = $C;                                               // any requisition's PDF (a hole)
    }
    $a['requisition'] = $req;

    if ($sa || $isFinDir) {
        $a['requisition.disburse'] = $full($C, 3);
    } elseif ($sr(['Resident_Pastor'])) {
        $a['requisition.disburse'] = ['view' => $C];
    }

    // --- Stewardship and system ----------------------------------------------------
    if ($sa) {
        $a['roles'] = ['view' => $C, 'manage' => $C];
        $a['system_health'] = ['view' => $C];
        $a['ui_guide'] = ['view' => $C];
    }
    if ($sr(['Super_Admin', 'Resident_Pastor'])) {
        $a['security'] = $full($C, 3, ['manage']);
        $a['alerts'] = ['view' => $C, 'manage' => $C];
    }

    // Normalise every scope so equal access compares equal.
    foreach ($a as $module => $abilities) {
        foreach ($abilities as $ability => $scope) {
            $a[$module][$ability] = perm_scope_normalise($scope);
        }
    }
    return $a;
}

/**
 * The old sidebar (includes/ui/nav.php as merged in ea78344), for one person.
 * Assimilation and Special Events are judged without their own volunteer and
 * crew rosters — those stay the modules' own business after the switch too.
 *
 * @return array<string,bool> nav key => visible
 */
function legacy_nav(array $p, array $abilities): array
{
    $roles = $p['roles'];
    $sa = in_array('Super_Admin', $roles, true);
    $deptIds = array_values(array_unique(array_column($p['seats'], 'dept')));
    $deptRoles = array_values(array_unique(array_column($p['seats'], 'seat')));
    $has = static function (array $r, array $d = []) use ($sa, $roles, $deptIds): bool {
        return $sa || array_intersect($r, $roles) !== [] || ($d !== [] && array_intersect($d, $deptIds) !== []);
    };
    $P = ['Resident_Pastor', 'Assoc_Pastor'];
    $PD = ['Resident_Pastor', 'Assoc_Pastor', 'Director'];
    $SMS = ['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head'];
    $core = $has(array_merge($PD, $SMS), [1, 3, 13]);
    $stew = $has($PD);

    return [
        'dashboard' => $has($PD, [1]),
        'member_portal' => true, 'profile' => true, 'bible' => true, 'hymnal' => true, 'regions' => true, 'academy' => true,
        'idi' => $has($P, [1]),
        'congregation' => $has($P, [1]),
        'reach' => $has($P, [1, 8]),
        'embrace' => $has($P, [1, 2]),
        'assimilation' => isset($abilities['assimilation']),
        'charis' => $has($P, [1, 10]),
        'junior_church' => $has($P, [1, 11]),
        'river_of_life' => $has($P, [1, 4]),
        'envision' => $has($P, [1, 3]),
        'special_events' => isset($abilities['special_events']),
        'zoe' => $has($P, [1, 7]),
        'departments' => $core && ($has($P) || array_intersect($deptRoles, ['Director', 'HOD', 'Sub_Unit_Head']) !== []),
        'tribes' => $has($P, [1, 13]),
        'events' => $has($PD, [1]),
        'pastoral' => $has($PD, [1]),
        'announcements' => $has($PD, [1]),
        'testimonies' => $has($PD, [1]),
        'sms_studio' => $has($SMS),
        'assets' => $has($PD, [1, 3]),
        'finance' => $stew,
        'requisition' => $stew && $has(['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD']),
        'roles' => $sa, 'ui_guide' => $sa, 'system_health' => $sa,
        'security' => $has(['Resident_Pastor']),
        'alerts' => $has(['Resident_Pastor']),
    ];
}
