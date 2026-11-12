<?php
// /includes/permissions/admin.php
//
// CHANGING the permission data — what the Roles & permissions screen
// (modules/roles/, api/roles_api.php) does: the roles × modules matrix, roles
// (presets, copy, rename, archive), who holds a role and until when, seat
// types, which seats a department uses, which departments own a module, and
// "view as". Reading access stays in includes/permissions.php (can()).
//
// Three rules hold for every change made here:
//   1. Fits within: nobody gives more than they hold (perm_cell_overreach()).
//      Break-glass fits everything.
//   2. History: one perm_history row per changed cell / assignment, with the
//      value before and after.
//   3. A person who GAINS or LOSES a role is told (system_notifications).
//      Matrix edits tell nobody: they change many people at once and the
//      screen states the impact before saving.
//
// Portable SQL only (the tests run it on SQLite). Errors meant for the person
// at the screen are thrown as PermAdminError with one short line.

require_once dirname(__DIR__) . '/permissions.php';

class PermAdminError extends RuntimeException
{
    public string $errorCode;
    public ?string $link;

    public function __construct(string $message, string $code = 'error', ?string $link = null)
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->link = $link;
    }
}

const PERM_SCOPE_RANK = ['own' => 1, 'dept' => 2, 'church' => 3];
const PERM_GROUP_LABELS = [
    'command' => 'Command', 'growth' => 'Growth', 'units' => 'Units',
    'operations' => 'Operations', 'stewardship' => 'Stewardship', 'system' => 'System',
];
// users.spiritual_status values: a role may never be called one of these
// (statuses are not access; design §5.6).
const PERM_STATUS_NAMES = ['visitor', '1st_timer', '2nd_timer', '3rd_timer', 'member', 'worker', 'pastor', 'non_member'];
// Modules a "read-only church-wide" preset or bulk action never opens:
// people's private notes, money, security and the permission screen itself.
const PERM_SENSITIVE = [
    'roles', 'security', 'alerts', 'monitor', 'ui_guide', 'pastoral', 'pastoral_notes', 'events.private_notes',
    'events.unlock', 'contact_extractor', 'finance', 'finance.countersign', 'requisition.disburse', 'sms_studio',
];

// --------------------------------------------------------------------------
// Holders and cells
// --------------------------------------------------------------------------

/** 'r:<role id>', 's:<seat key>' or 'e' (every signed-in member) → [kind, role_id, seat_key], or null. */
function perm_holder_parse(string $key): ?array
{
    if ($key === 'e') {
        return ['kind' => 'everyone', 'role_id' => 0, 'seat_key' => PERM_EVERYONE];
    }
    if (preg_match('/^r:(\d+)$/', $key, $m) && (int) $m[1] > 0) {
        return ['kind' => 'role', 'role_id' => (int) $m[1], 'seat_key' => ''];
    }
    if (preg_match('/^s:([a-z0-9_]{1,30})$/', $key, $m)) {
        return ['kind' => 'seat', 'role_id' => 0, 'seat_key' => $m[1]];
    }
    return null;
}

function perm_holder_key(int $roleId, string $seatKey): string
{
    if ($roleId > 0) {
        return 'r:' . $roleId;
    }
    return $seatKey === PERM_EVERYONE ? 'e' : 's:' . $seatKey;
}

/** The holder must exist and be in use; returns [kind, role_id, seat_key, label]. */
function perm_holder_require(PDO $pdo, string $key): array
{
    $h = perm_holder_parse($key);
    if ($h === null) {
        throw new PermAdminError('Pick a role or a seat.', 'holder');
    }
    if ($h['kind'] === 'role') {
        $role = perm_role_row($pdo, $h['role_id']);
        if ($role === null || (int) $role['is_archived'] === 1) {
            throw new PermAdminError('That role is archived or gone. Reload the page.', 'holder');
        }
        return $h + ['label' => perm_role_label($role)];
    }
    if ($h['kind'] === 'seat') {
        $type = perm_seat_types($pdo)[$h['seat_key']] ?? null;
        if ($type === null || (int) $type['is_archived'] === 1) {
            throw new PermAdminError('That seat type is not in use. Reload the page.', 'holder');
        }
        return $h + ['label' => (string) $type['label']];
    }
    return $h + ['label' => 'Everyone'];
}

function perm_cell_blank(): array
{
    return ['level' => 0, 'approve' => 0, 'export' => 0, 'manage' => 0, 'scope' => 'church', 'view_scope' => null];
}

function perm_cell_is_blank(array $c): bool
{
    return (int) $c['level'] === 0 && !$c['approve'] && !$c['export'] && !$c['manage'];
}

/** A cell as stored, clamped to what the module offers. Level may be a name or a number. */
function perm_cell_clean(string $module, array $in, string $kind = 'role'): array
{
    $def = perm_modules()[$module] ?? null;
    if ($def === null) {
        throw new PermAdminError('That module is unknown. Reload the page.', 'module');
    }
    $max = PERM_LEVELS[$def['max'] ?? 'none'];
    $level = $in['level'] ?? 0;
    $level = is_string($level) && isset(PERM_LEVELS[$level]) ? PERM_LEVELS[$level] : (int) $level;
    $extras = (array) ($def['extras'] ?? []);
    $truthy = static fn ($v): bool => !empty($v) && $v !== '0' && $v !== 'false';
    $scope = in_array($in['scope'] ?? 'church', PERM_SCOPES, true) ? (string) ($in['scope'] ?? 'church') : 'church';
    $view = $in['view_scope'] ?? null;
    $view = in_array($view, PERM_SCOPES, true) ? (string) $view : null;
    if ($kind === 'everyone') {
        // Everyone has no department: "your departments" would mean nothing.
        $scope = $scope === 'dept' ? 'own' : $scope;
        $view = $view === 'dept' ? 'own' : $view;
    }
    $cell = [
        'level' => max(0, min($max, $level)),
        'approve' => in_array('approve', $extras, true) && $truthy($in['approve'] ?? 0) ? 1 : 0,
        'export' => in_array('export', $extras, true) && $truthy($in['export'] ?? 0) ? 1 : 0,
        'manage' => in_array('manage', $extras, true) && $truthy($in['manage'] ?? 0) ? 1 : 0,
        'scope' => $scope,
        'view_scope' => $view === $scope ? null : $view,
    ];
    return perm_cell_is_blank($cell) ? perm_cell_blank() : $cell;
}

/** ability => scope name, for everything a cell gives. */
function perm_cell_abilities(array $cell): array
{
    $view = ($cell['view_scope'] ?? null) ?: $cell['scope'];
    $out = [];
    foreach (PERM_LEVELS as $name => $rank) {
        if ($rank >= 1 && $rank <= (int) $cell['level']) {
            $out[$name] = $name === 'view' ? $view : $cell['scope'];
        }
    }
    if (!empty($cell['approve'])) {
        $out['approve'] = $cell['scope'];
    }
    if (!empty($cell['export'])) {
        $out['export'] = $view;
    }
    if (!empty($cell['manage'])) {
        $out['manage'] = $cell['scope'];
    }
    return $out;
}

/** How far a cell part reaches: own 1, dept 2, church 3 (a seat on an undivided module reaches all of it). */
function perm_cell_rank(string $module, string $scope, string $kind): int
{
    if ($kind === 'seat' && $scope === 'dept' && empty(perm_modules()[$module]['partitioned'])) {
        return 3;
    }
    return PERM_SCOPE_RANK[$scope] ?? 0;
}

/** How far a context reaches for one ability: none 0, own 1, departments 2, church 3. */
function perm_ctx_rank(array $ctx, string $module, string $ability): int
{
    if (!empty($ctx['breakglass'])) {
        return 3;
    }
    $s = perm_ctx_scope($ctx, $module, $ability);
    return $s['church'] ? 3 : ($s['depts'] !== [] ? 2 : ($s['own'] ? 1 : 0));
}

/**
 * Fits within (design §3): the first ability of $cell that reaches further
 * than the editor's own access to $module, or null when it all fits.
 */
function perm_cell_overreach(array $ctx, string $module, array $cell, string $kind): ?string
{
    if (!empty($ctx['breakglass'])) {
        return null;
    }
    foreach (perm_cell_abilities($cell) as $ability => $scope) {
        if (perm_ctx_rank($ctx, $module, $ability) < perm_cell_rank($module, $scope, $kind)) {
            return $ability;
        }
    }
    return null;
}

function perm_fits_within(array $ctx, string $module, array $cell, string $kind = 'role'): bool
{
    return perm_cell_overreach($ctx, $module, $cell, $kind) === null;
}

/** Every cell of a role fits inside the editor: the module it first fails on, or null. */
function perm_role_overreach(PDO $pdo, array $ctx, int $roleId): ?string
{
    if (!empty($ctx['breakglass'])) {
        return null;
    }
    $role = perm_role_row($pdo, $roleId);
    if ($role !== null && (int) $role['is_break_glass'] === 1) {
        return 'everything';   // only break-glass may hand out break-glass
    }
    foreach (perm_grants_all($pdo)['r:' . $roleId] ?? [] as $module => $cell) {
        if (perm_cell_overreach($ctx, $module, $cell, 'role') !== null) {
            return $module;
        }
    }
    return null;
}

function perm_grant_row_cell(array $g): array
{
    return [
        'level' => (int) $g['level'], 'approve' => (int) $g['can_approve'], 'export' => (int) $g['can_export'],
        'manage' => (int) $g['can_manage'], 'scope' => (string) $g['scope'],
        'view_scope' => ($g['view_scope'] === null || $g['view_scope'] === '') ? null : (string) $g['view_scope'],
    ];
}

/** holder key => module => cell, for every stored grant row. */
function perm_grants_all(PDO $pdo): array
{
    $out = [];
    $rows = $pdo->query('SELECT role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope
                           FROM perm_grants')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $g) {
        $out[perm_holder_key((int) $g['role_id'], (string) $g['seat_key'])][(string) $g['module_key']] = perm_grant_row_cell($g);
    }
    return $out;
}

function perm_cell_get(PDO $pdo, array $h, string $module): array
{
    $stmt = $pdo->prepare('SELECT level, can_approve, can_export, can_manage, scope, view_scope
                             FROM perm_grants WHERE role_id = ? AND seat_key = ? AND module_key = ?');
    $stmt->execute([$h['role_id'], $h['seat_key'], $module]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? perm_grant_row_cell($row) : perm_cell_blank();
}

function perm_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
}

/** The acting person's id for updated_by / assigned_by (null for the system). */
function perm_actor_id(array $ctx): ?int
{
    $id = (int) ($ctx['user_id'] ?? 0);
    return $id > 0 ? $id : null;
}

/** Write one cell (blank deletes it). No checks: callers check. */
function perm_cell_write(PDO $pdo, array $h, string $module, array $cell, ?int $actor): void
{
    $pdo->prepare('DELETE FROM perm_grants WHERE role_id = ? AND seat_key = ? AND module_key = ?')
        ->execute([$h['role_id'], $h['seat_key'], $module]);
    if (!perm_cell_is_blank($cell)) {
        $pdo->prepare('INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage,
                                                scope, view_scope, updated_by, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$h['role_id'], $h['seat_key'], $module, $cell['level'], $cell['approve'], $cell['export'],
                $cell['manage'], $cell['scope'], $cell['view_scope'], $actor, perm_now()]);
    }
}

/**
 * Set several cells at once, all or nothing: [['holder' => key, 'module' =>
 * key, 'cell' => [...]], …]. Each changed cell is checked (fits within) and
 * gets its own history row. Returns how many cells changed.
 */
function perm_cells_set(PDO $pdo, array $ctx, array $changes, string $via = 'roles_screen', string $reason = ''): int
{
    $todo = [];
    foreach ($changes as $c) {
        $h = perm_holder_require($pdo, (string) ($c['holder'] ?? ''));
        $module = (string) ($c['module'] ?? '');
        $cell = perm_cell_clean($module, (array) ($c['cell'] ?? []), $h['kind']);
        $before = perm_cell_get($pdo, $h, $module);
        if ($before == $cell) {
            continue;
        }
        $label = perm_modules()[$module]['label'];
        if (!perm_cell_is_blank($cell) && ($ability = perm_cell_overreach($ctx, $module, $cell, $h['kind'])) !== null) {
            throw new PermAdminError("You can't give more than you hold: {$label} ({$ability}).", 'fits');
        }
        // Taking away also needs the editor to hold what is being taken.
        if (!perm_cell_is_blank($before) && ($ability = perm_cell_overreach($ctx, $module, $before, $h['kind'])) !== null) {
            throw new PermAdminError("You can't change access you don't hold: {$label} ({$ability}).", 'fits');
        }
        $todo[] = [$h, $module, $before, $cell];
    }
    if ($todo === []) {
        return 0;
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($todo as [$h, $module, $before, $cell]) {
            perm_cell_write($pdo, $h, $module, $cell, perm_actor_id($ctx));
            perm_history_add($pdo, [
                'via' => $via, 'change_type' => 'grant.set', 'actor_user_id' => perm_actor_id($ctx),
                'role_id' => $h['role_id'] ?: null, 'seat_key' => $h['seat_key'] !== '' ? $h['seat_key'] : null,
                'module_key' => $module, 'before' => $before, 'after' => $cell, 'reason' => $reason !== '' ? $reason : null,
            ]);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    perm_reset();
    return count($todo);
}

/** A whole column: copy another holder's cells, clear it, or make it read-only church-wide. */
function perm_column_changes(PDO $pdo, string $holder, string $op, string $from = ''): array
{
    $h = perm_holder_require($pdo, $holder);
    $all = perm_grants_all($pdo);
    $changes = [];
    foreach (perm_modules() as $module => $def) {
        if ($op === 'copy') {
            // A seat's "department" is its own department; a role's is the
            // departments its holder sits in. Same words, so cells copy as they are.
            perm_holder_require($pdo, $from);
            $cell = $all[$from][$module] ?? perm_cell_blank();
        } elseif ($op === 'clear') {
            $cell = perm_cell_blank();
        } elseif ($op === 'readonly') {
            if (in_array($module, PERM_SENSITIVE, true) || PERM_LEVELS[$def['max'] ?? 'none'] < 1) {
                continue;
            }
            $cell = ['level' => 1, 'scope' => 'church'];
        } else {
            throw new PermAdminError('Pick an action.', 'op');
        }
        $changes[] = ['holder' => $holder, 'module' => $module, 'cell' => $cell];
    }
    return $changes;
}

/** Every module of one nav group set to the same cell, for one holder. */
function perm_group_changes(string $holder, string $group, array $cell): array
{
    $changes = [];
    foreach (perm_modules() as $module => $def) {
        if (($def['group'] ?? '') === $group) {
            $changes[] = ['holder' => $holder, 'module' => $module, 'cell' => $cell];
        }
    }
    return $changes;
}

/** Starting points for a new role (Roles → New role). Clamped to each module's levels when applied. */
function perm_presets(): array
{
    $readonly = [];
    foreach (perm_modules() as $module => $def) {
        if (!in_array($module, PERM_SENSITIVE, true) && PERM_LEVELS[$def['max'] ?? 'none'] >= 1) {
            $readonly[$module] = ['level' => 'view', 'scope' => 'church'];
        }
    }
    return [
        'blank' => ['label' => 'Blank', 'info' => 'No access. Fill in the matrix yourself.', 'cells' => []],
        'readonly' => ['label' => 'Read-only church-wide', 'info' => 'View every everyday module across the church. No money, security or private notes.',
            'cells' => $readonly],
        'dept_leader' => ['label' => 'Department leader', 'info' => 'Runs their own departments: roster, requisitions, dashboard.',
            'cells' => [
                'dashboard' => ['level' => 'view', 'scope' => 'dept'],
                'departments' => ['level' => 'edit', 'scope' => 'dept'],
                'requisition' => ['level' => 'create', 'approve' => 1, 'scope' => 'dept'],
                'events' => ['level' => 'view', 'scope' => 'church'],
                'congregation' => ['level' => 'view', 'scope' => 'dept'],
                'announcements' => ['level' => 'create', 'scope' => 'own'],
            ]],
        'pastoral' => ['label' => 'Pastoral', 'info' => 'Sees the people side of the church: members, care, notes for pastors.',
            'cells' => [
                'dashboard' => ['level' => 'view', 'scope' => 'church'],
                'congregation' => ['level' => 'view', 'scope' => 'church'],
                'embrace' => ['level' => 'view', 'scope' => 'church'],
                'assimilation' => ['level' => 'view', 'scope' => 'church'],
                'charis' => ['level' => 'view', 'scope' => 'church'],
                'pastoral_notes' => ['level' => 'view', 'scope' => 'church'],
                'pastoral' => ['level' => 'edit', 'scope' => 'church'],
                'zoe' => ['level' => 'view', 'scope' => 'church'],
                'events' => ['level' => 'view', 'scope' => 'church'],
            ]],
        'finance_officer' => ['label' => 'Finance officer', 'info' => 'Records and reports giving and expenses; reads requisitions.',
            'cells' => [
                'finance' => ['level' => 'edit', 'scope' => 'church'],
                'requisition' => ['level' => 'view', 'export' => 1, 'scope' => 'church'],
            ]],
    ];
}

// --------------------------------------------------------------------------
// Reading for the screen
// --------------------------------------------------------------------------

function perm_role_row(PDO $pdo, int $roleId): ?array
{
    $stmt = $pdo->prepare('SELECT id, role_name, label, description, COALESCE(is_break_glass, 0) AS is_break_glass,
                                  COALESCE(is_system, 0) AS is_system, COALESCE(is_archived, 0) AS is_archived,
                                  appoint_rank, sort_order
                             FROM roles WHERE id = ?');
    $stmt->execute([$roleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** A role's display name: its label, else its frozen role_name made readable. */
function perm_role_label(array $role): string
{
    $label = trim((string) ($role['label'] ?? ''));
    return $label !== '' ? $label : str_replace('_', ' ', (string) $role['role_name']);
}

/** Every role, with how many people hold it (any row, frozen or dated included). */
function perm_roles_list(PDO $pdo): array
{
    $counts = [];
    foreach ($pdo->query('SELECT role_id, COUNT(*) AS n FROM user_roles GROUP BY role_id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[(int) $r['role_id']] = (int) $r['n'];
    }
    $rows = $pdo->query('SELECT id, role_name, label, description, COALESCE(is_break_glass, 0) AS is_break_glass,
                                COALESCE(is_system, 0) AS is_system, COALESCE(is_archived, 0) AS is_archived,
                                appoint_rank, sort_order
                           FROM roles ORDER BY COALESCE(is_archived, 0), sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'label' => perm_role_label($r), 'description' => (string) ($r['description'] ?? ''),
            'breakglass' => (int) $r['is_break_glass'] === 1, 'system' => (int) $r['is_system'] === 1,
            'archived' => (int) $r['is_archived'] === 1, 'appoint_rank' => $r['appoint_rank'] === null ? null : (int) $r['appoint_rank'],
            'holders' => $counts[(int) $r['id']] ?? 0,
        ];
    }
    return $out;
}

/** The live holders of a role (unfrozen, within their dates). */
function perm_role_holders(PDO $pdo, int $roleId): array
{
    $today = perm_today();
    $stmt = $pdo->prepare('SELECT DISTINCT user_id FROM user_roles
                            WHERE role_id = ? AND COALESCE(is_frozen, 0) = 0
                              AND (starts_on IS NULL OR starts_on <= ?) AND (ends_on IS NULL OR ends_on >= ?)');
    $stmt->execute([$roleId, $today, $today]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** How many people a holder column reaches right now (the impact line before a save). */
function perm_holder_reach(PDO $pdo, string $key): int
{
    $h = perm_holder_parse($key);
    if ($h === null) {
        return 0;
    }
    if ($h['kind'] === 'role') {
        return count(perm_role_holders($pdo, $h['role_id']));
    }
    if ($h['kind'] === 'seat') {
        return count(perm_seat_holders($pdo, $h['seat_key']));
    }
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(account_status, 'active') = 'active'")->fetchColumn();
    } catch (Throwable $e) {
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}

/** department id => name. */
function perm_department_names(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $out[(int) $d['id']] = (string) $d['name'];
    }
    return $out;
}

/** Everything the matrix screen draws, for the editor in $ctx. */
function perm_matrix_data(PDO $pdo, array $ctx): array
{
    $modules = [];
    foreach (perm_modules() as $key => $def) {
        $modules[] = [
            'key' => $key, 'label' => (string) $def['label'], 'group' => (string) $def['group'],
            'max' => PERM_LEVELS[$def['max'] ?? 'none'], 'extras' => array_values((array) ($def['extras'] ?? [])),
            'partitioned' => !empty($def['partitioned']), 'own' => (string) ($def['own'] ?? ''),
        ];
    }
    $holders = [];
    foreach (perm_roles_list($pdo) as $r) {
        if (!$r['archived']) {
            $holders[] = ['key' => 'r:' . $r['id'], 'kind' => 'role', 'label' => $r['label'], 'info' => $r['description'],
                'people' => count(perm_role_holders($pdo, $r['id'])), 'breakglass' => $r['breakglass']];
        }
    }
    foreach (perm_seat_types($pdo) as $key => $t) {
        if ((int) $t['is_archived'] === 0) {
            $holders[] = ['key' => 's:' . $key, 'kind' => 'seat', 'label' => (string) $t['label'], 'info' => '',
                'people' => count(perm_seat_holders($pdo, (string) $key)), 'breakglass' => false];
        }
    }
    $holders[] = ['key' => 'e', 'kind' => 'everyone', 'label' => 'Everyone', 'info' => 'Every signed-in member.',
        'people' => perm_holder_reach($pdo, 'e'), 'breakglass' => false];

    // What the editor holds per module and ability (0–3), so the screen can
    // grey out what they may not give. Null = break-glass: everything.
    $mine = null;
    if (empty($ctx['breakglass'])) {
        $mine = [];
        foreach (perm_modules() as $key => $def) {
            foreach (PERM_ABILITIES as $ability) {
                $rank = perm_ctx_rank($ctx, $key, $ability);
                if ($rank > 0) {
                    $mine[$key][$ability] = $rank;
                }
            }
        }
    }
    return [
        'groups' => PERM_GROUP_LABELS, 'modules' => $modules, 'holders' => $holders,
        'cells' => (object) perm_grants_all($pdo), 'mine' => $mine,
    ];
}

// --------------------------------------------------------------------------
// Roles
// --------------------------------------------------------------------------

/** A role name must be 2–60 characters, unique, and never a member status. */
function perm_role_name_check(PDO $pdo, string $label, int $exceptId = 0): string
{
    $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if (mb_strlen($label) < 2 || mb_strlen($label) > 60) {
        throw new PermAdminError('Give the role a name of 2 to 60 characters.', 'label');
    }
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_'));
    if (in_array($slug, PERM_STATUS_NAMES, true)) {
        throw new PermAdminError('That name is a member status. Pick another name.', 'label');
    }
    foreach (perm_roles_list($pdo) as $r) {
        if ($r['id'] !== $exceptId && strcasecmp($r['label'], $label) === 0) {
            throw new PermAdminError('A role with that name exists. Pick another name.', 'label');
        }
    }
    return $label;
}

/** Create a role from a preset or as a copy of another role. Returns its id. */
function perm_role_create(PDO $pdo, array $ctx, string $label, string $description = '', string $preset = 'blank', int $copyFrom = 0): int
{
    $label = perm_role_name_check($pdo, $label);
    $description = mb_substr(trim($description), 0, 255);
    if ($copyFrom > 0) {
        if (perm_role_row($pdo, $copyFrom) === null) {
            throw new PermAdminError('The role to copy is gone. Reload the page.', 'copy');
        }
        $cells = perm_grants_all($pdo)['r:' . $copyFrom] ?? [];
    } else {
        $presets = perm_presets();
        if (!isset($presets[$preset])) {
            throw new PermAdminError('Pick a starting point.', 'preset');
        }
        $cells = $presets[$preset]['cells'];
    }

    $base = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_') ?: 'Role';
    $base = substr($base, 0, 44);
    $name = $base;
    $taken = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE LOWER(role_name) = LOWER(?)');
    for ($i = 2; $i < 100; $i++) {
        $taken->execute([$name]);
        if ((int) $taken->fetchColumn() === 0) {
            break;
        }
        $name = $base . '_' . $i;
    }

    $pdo->beginTransaction();
    try {
        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM roles')->fetchColumn() + 10;
        $pdo->prepare('INSERT INTO roles (role_name, label, description, created_by, created_at, sort_order) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $label, $description !== '' ? $description : null, perm_actor_id($ctx), perm_now(), $sort]);
        $roleId = (int) $pdo->lastInsertId();
        perm_history_add($pdo, [
            'via' => 'roles_screen', 'change_type' => 'role.create', 'actor_user_id' => perm_actor_id($ctx), 'role_id' => $roleId,
            'after' => ['label' => $label, 'description' => $description, 'from' => $copyFrom > 0 ? 'copy:' . $copyFrom : $preset],
        ]);
        $changes = [];
        foreach ($cells as $module => $cell) {
            if (isset(perm_modules()[$module])) {
                $changes[] = ['holder' => 'r:' . $roleId, 'module' => $module, 'cell' => $cell];
            }
        }
        perm_cells_set($pdo, $ctx, $changes, 'roles_screen', 'new role');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    perm_reset();
    return $roleId;
}

/** Rename or describe a role. Renaming changes nobody's access: code keys on the id. */
function perm_role_update(PDO $pdo, array $ctx, int $roleId, string $label, string $description): void
{
    $role = perm_role_row($pdo, $roleId);
    if ($role === null) {
        throw new PermAdminError('That role is gone. Reload the page.', 'role');
    }
    if (perm_role_overreach($pdo, $ctx, $roleId) !== null) {
        throw new PermAdminError("You can't change a role that holds more than you do.", 'fits');
    }
    $label = perm_role_name_check($pdo, $label, $roleId);
    $description = mb_substr(trim($description), 0, 255);
    $before = ['label' => perm_role_label($role), 'description' => (string) ($role['description'] ?? '')];
    $after = ['label' => $label, 'description' => $description];
    if ($before === $after) {
        return;
    }
    $pdo->prepare('UPDATE roles SET label = ?, description = ? WHERE id = ?')
        ->execute([$label, $description !== '' ? $description : null, $roleId]);
    perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'role.rename', 'actor_user_id' => perm_actor_id($ctx),
        'role_id' => $roleId, 'before' => $before, 'after' => $after]);
}

/** Archive (or bring back) a role. Refused while anyone holds it, and for break-glass. */
function perm_role_archive(PDO $pdo, array $ctx, int $roleId, bool $archive = true): void
{
    $role = perm_role_row($pdo, $roleId);
    if ($role === null) {
        throw new PermAdminError('That role is gone. Reload the page.', 'role');
    }
    if ((int) $role['is_break_glass'] === 1) {
        throw new PermAdminError('The Super Admin role cannot be archived.', 'breakglass');
    }
    if (perm_role_overreach($pdo, $ctx, $roleId) !== null) {
        throw new PermAdminError("You can't archive a role that holds more than you do.", 'fits');
    }
    if ($archive) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = ?');
        $stmt->execute([$roleId]);
        $n = (int) $stmt->fetchColumn();
        if ($n > 0) {
            throw new PermAdminError("Remove its {$n} holder" . ($n === 1 ? '' : 's') . ' first, in People.', 'held');
        }
    }
    if ((int) $role['is_archived'] === ($archive ? 1 : 0)) {
        return;
    }
    $pdo->prepare('UPDATE roles SET is_archived = ? WHERE id = ?')->execute([$archive ? 1 : 0, $roleId]);
    perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'role.archive', 'actor_user_id' => perm_actor_id($ctx),
        'role_id' => $roleId, 'before' => ['archived' => !$archive], 'after' => ['archived' => $archive]]);
    perm_reset();
}

// --------------------------------------------------------------------------
// People: who holds a role, and until when
// --------------------------------------------------------------------------

function perm_date_or_null($value): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new PermAdminError('Use a date like 2026-12-31.', 'date');
    }
    return $value;
}

/** "31 Dec 2026" */
function perm_date_text(?string $ymd): string
{
    if ($ymd === null || $ymd === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    return $d ? $d->format('j M Y') : $ymd;
}

function perm_user_row(PDO $pdo, int $uid): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function perm_user_name(?array $u): string
{
    return $u ? trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')) : '';
}

/** Tell people something (active accounts only). Returns how many were told. */
function perm_notify(PDO $pdo, array $userIds, string $title, string $message, string $link = '/modules/profile/'): int
{
    $ids = array_keys(perm_active_accounts($pdo, $userIds));
    if ($ids === []) {
        return 0;
    }
    $stmt = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    foreach ($ids as $id) {
        try {
            $stmt->execute([$id, $title, $message, $link]);
        } catch (Throwable $e) {
            error_log('permissions: notification failed: ' . $e->getMessage());
        }
    }
    return count($ids);
}

/** Every role row a person has (frozen, future and ended included), for People. */
function perm_user_role_rows(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare('SELECT ur.role_id, ur.custom_title, COALESCE(ur.is_frozen, 0) AS is_frozen, ur.starts_on, ur.ends_on,
                                  r.role_name, r.label, COALESCE(r.is_break_glass, 0) AS is_break_glass, COALESCE(r.is_archived, 0) AS is_archived
                             FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                            WHERE ur.user_id = ? ORDER BY r.sort_order, r.id');
    $stmt->execute([$uid]);
    $today = perm_today();
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $starts = $r['starts_on'] ? substr((string) $r['starts_on'], 0, 10) : null;
        $ends = $r['ends_on'] ? substr((string) $r['ends_on'], 0, 10) : null;
        $bg = (int) $r['is_break_glass'] === 1;
        $state = (int) $r['is_frozen'] === 1 ? 'frozen'
            : ((int) $r['is_archived'] === 1 ? 'archived'
            : ($starts !== null && $starts > $today ? 'future'
            : (!$bg && $ends !== null && $ends < $today ? 'ended' : 'live')));
        $out[] = [
            'role_id' => (int) $r['role_id'], 'label' => perm_role_label($r), 'custom_title' => (string) ($r['custom_title'] ?? ''),
            'starts_on' => $starts, 'ends_on' => $bg ? null : $ends, 'breakglass' => $bg, 'state' => $state,
        ];
    }
    return $out;
}

/** The last live break-glass holder may never be removed. */
function perm_is_last_breakglass(PDO $pdo, int $uid, int $roleId): bool
{
    $role = perm_role_row($pdo, $roleId);
    if ($role === null || (int) $role['is_break_glass'] !== 1) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                            WHERE COALESCE(r.is_break_glass, 0) = 1 AND COALESCE(ur.is_frozen, 0) = 0 AND ur.user_id <> ?');
    $stmt->execute([$uid]);
    return perm_active_accounts($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN)) === [];
}

/** Give someone a role, optionally from / until a date. */
function perm_user_role_add(PDO $pdo, array $ctx, int $uid, int $roleId, ?string $startsOn = null, ?string $endsOn = null,
                            string $customTitle = '', string $via = 'roles_screen'): void
{
    $user = perm_user_row($pdo, $uid);
    if ($user === null) {
        throw new PermAdminError('That person is gone. Reload the page.', 'person');
    }
    $role = perm_role_row($pdo, $roleId);
    if ($role === null || (int) $role['is_archived'] === 1) {
        throw new PermAdminError('That role is archived or gone. Reload the page.', 'role');
    }
    // The old screen's two blocks (owner, 2026): a complete profile, and a Worker or Pastor.
    $first = (string) ($user['first_name'] ?? '');
    $last = (string) ($user['last_name'] ?? '');
    $link = '/modules/congregation/?edit=' . $uid;
    if (stripos($first . ' ' . $last, 'unknown') !== false
        || (trim((string) ($user['phone'] ?? '')) === '' && trim((string) ($user['email'] ?? '')) === '')) {
        throw new PermAdminError('Complete their name and contact details first.', 'profile', $link);
    }
    if (!in_array((string) ($user['spiritual_status'] ?? ''), ['Worker', 'Pastor'], true)) {
        throw new PermAdminError('Make them a Worker in Congregation first.', 'status', $link);
    }
    if (($module = perm_role_overreach($pdo, $ctx, $roleId)) !== null) {
        throw new PermAdminError("You can't give a role that holds more than you do ({$module}).", 'fits');
    }
    $startsOn = perm_date_or_null($startsOn);
    $endsOn = perm_date_or_null($endsOn);
    if ((int) $role['is_break_glass'] === 1) {
        $endsOn = null;   // never expires (decision 4)
    }
    if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
        throw new PermAdminError('The end date must be after the start date.', 'date');
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?');
    $exists->execute([$uid, $roleId]);
    if ((int) $exists->fetchColumn() > 0) {
        throw new PermAdminError('They already hold this role. Change its dates instead.', 'exists');
    }
    $title = mb_substr(trim($customTitle), 0, 100);
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_primary, custom_title, starts_on, ends_on, assigned_by, assigned_at)
                   VALUES (?, ?, 0, ?, ?, ?, ?, ?)')
        ->execute([$uid, $roleId, $title !== '' ? $title : null, $startsOn, $endsOn, perm_actor_id($ctx), perm_now()]);
    $label = perm_role_label($role);
    perm_history_add($pdo, ['via' => $via, 'change_type' => 'user_role.add', 'actor_user_id' => perm_actor_id($ctx),
        'subject_user_id' => $uid, 'role_id' => $roleId,
        'after' => ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'custom_title' => $title]]);
    perm_notify($pdo, [$uid], 'New role: ' . $label,
        "You now have the {$label} role" . ($endsOn ? ' until ' . perm_date_text($endsOn) : '') . '. Your menu shows what it opens.');
    perm_reset();
}

/** Take a role away. */
function perm_user_role_remove(PDO $pdo, array $ctx, int $uid, int $roleId, string $reason = '', string $via = 'roles_screen'): void
{
    $rows = array_values(array_filter(perm_user_role_rows($pdo, $uid), static fn ($r) => $r['role_id'] === $roleId));
    if ($rows === []) {
        throw new PermAdminError('They no longer hold this role. Reload the page.', 'gone');
    }
    if (($module = perm_role_overreach($pdo, $ctx, $roleId)) !== null) {
        throw new PermAdminError("You can't remove a role that holds more than you do ({$module}).", 'fits');
    }
    if (perm_is_last_breakglass($pdo, $uid, $roleId)) {
        throw new PermAdminError('This is the last Super Admin. Add another one first.', 'last');
    }
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?')->execute([$uid, $roleId]);
    perm_history_add($pdo, ['via' => $via, 'change_type' => 'user_role.remove', 'actor_user_id' => perm_actor_id($ctx),
        'subject_user_id' => $uid, 'role_id' => $roleId, 'before' => $rows[0], 'reason' => $reason !== '' ? $reason : null]);
    perm_notify($pdo, [$uid], 'Role removed: ' . $rows[0]['label'],
        "Your {$rows[0]['label']} role has been removed. Ask the church office if this looks wrong.");
    perm_reset();
}

/** Change a held role's dates or display title. */
function perm_user_role_dates(PDO $pdo, array $ctx, int $uid, int $roleId, ?string $startsOn, ?string $endsOn,
                              ?string $customTitle = null, string $via = 'roles_screen'): void
{
    $rows = array_values(array_filter(perm_user_role_rows($pdo, $uid), static fn ($r) => $r['role_id'] === $roleId));
    if ($rows === []) {
        throw new PermAdminError('They no longer hold this role. Reload the page.', 'gone');
    }
    if (($module = perm_role_overreach($pdo, $ctx, $roleId)) !== null) {
        throw new PermAdminError("You can't change a role that holds more than you do ({$module}).", 'fits');
    }
    $startsOn = perm_date_or_null($startsOn);
    $endsOn = $rows[0]['breakglass'] ? null : perm_date_or_null($endsOn);
    if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
        throw new PermAdminError('The end date must be after the start date.', 'date');
    }
    $title = $customTitle === null ? $rows[0]['custom_title'] : mb_substr(trim($customTitle), 0, 100);
    $before = ['starts_on' => $rows[0]['starts_on'], 'ends_on' => $rows[0]['ends_on'], 'custom_title' => $rows[0]['custom_title']];
    $after = ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'custom_title' => $title];
    if ($before === $after) {
        return;
    }
    $resetReminder = $before['ends_on'] !== $endsOn;
    $pdo->prepare('UPDATE user_roles SET starts_on = ?, ends_on = ?, custom_title = ?' . ($resetReminder ? ', reminded_at = NULL' : '')
                  . ' WHERE user_id = ? AND role_id = ?')
        ->execute([$startsOn, $endsOn, $title !== '' ? $title : null, $uid, $roleId]);
    perm_history_add($pdo, ['via' => $via, 'change_type' => 'user_role.dates', 'actor_user_id' => perm_actor_id($ctx),
        'subject_user_id' => $uid, 'role_id' => $roleId, 'before' => $before, 'after' => $after]);
    if ($resetReminder) {
        perm_notify($pdo, [$uid], 'Role dates changed: ' . $rows[0]['label'],
            $endsOn ? "Your {$rows[0]['label']} role now runs until " . perm_date_text($endsOn) . '.'
                    : "Your {$rows[0]['label']} role no longer has an end date.");
    }
    perm_reset();
}

/** People search for the People tab (name, email or phone). */
function perm_people_search(PDO $pdo, string $q, int $limit = 20): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $cols = ['first_name', 'last_name', "first_name || ' ' || last_name"];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $cols[2] = "CONCAT(first_name, ' ', last_name)";
        $cols[] = 'email';
        $cols[] = 'phone';
    }
    $where = implode(' OR ', array_map(static fn ($c) => "{$c} LIKE ?", $cols));
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, spiritual_status FROM users WHERE {$where}
                            ORDER BY first_name, last_name LIMIT " . max(1, min(50, $limit)));
    $stmt->execute(array_fill(0, count($cols), $like));
    return array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => perm_user_name($u),
        'status' => str_replace('_', ' ', (string) ($u['spiritual_status'] ?? ''))], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** A seat's display name: "Head of Department, Charis". */
function perm_seat_text(PDO $pdo, string $seatKey, int $deptId, ?array $names = null): string
{
    $names ??= perm_department_names($pdo);
    $label = (string) (perm_seat_types($pdo)[$seatKey]['label'] ?? $seatKey);
    return $label . ', ' . ($names[$deptId] ?? ('department ' . $deptId));
}

/** Words for a source in perm_resolve()'s 'sources': why someone has a cell. */
function perm_source_text(PDO $pdo, string $source, array $roleLabels, array $names): string
{
    if ($source === 'everyone') {
        return 'Everyone';
    }
    if (preg_match('/^role:(\d+)$/', $source, $m)) {
        return 'Role: ' . ($roleLabels[(int) $m[1]] ?? ('#' . $m[1]));
    }
    if (preg_match('/^seat:([a-z0-9_]+)@(\d+)$/', $source, $m)) {
        return 'Seat: ' . perm_seat_text($pdo, $m[1], (int) $m[2], $names);
    }
    return $source;
}

/** A scope in words: "Church", "Charis, Choir", "Own". */
function perm_scope_text(array $scope, array $names): string
{
    if ($scope['church']) {
        return 'Church';
    }
    if ($scope['depts'] !== []) {
        return implode(', ', array_map(static fn ($d) => $names[$d] ?? ('#' . $d), $scope['depts']));
    }
    return $scope['own'] ? 'Own' : '';
}

/** People tab: one person's roles, seats and effective access, with the reason for each module. */
function perm_person_overview(PDO $pdo, int $uid): array
{
    $user = perm_user_row($pdo, $uid);
    if ($user === null) {
        throw new PermAdminError('That person is gone. Reload the page.', 'person');
    }
    $names = perm_department_names($pdo);
    $roleLabels = [];
    foreach (perm_roles_list($pdo) as $r) {
        $roleLabels[$r['id']] = $r['label'];
    }
    $ctx = perm_resolve($pdo, $uid);
    $seats = [];
    if (perm_roster_has_years($pdo)) {
        $stmt = $pdo->prepare("SELECT ud.id, ud.department_id, ud.role_in_dept, COALESCE(ud.end_date, y.end_date) AS ends_on,
                                      COALESCE(y.is_active, 0) AS year_live
                                 FROM user_departments ud LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id
                                WHERE ud.user_id = ? AND ud.is_active = 1 AND COALESCE(ud.roster_status, 'Active') = 'Active'");
        $stmt->execute([$uid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $key = perm_seat_key_for($pdo, (string) $s['role_in_dept']);
            $seats[] = [
                'record_id' => (int) $s['id'], 'department' => $names[(int) $s['department_id']] ?? '',
                'seat' => $key !== null ? (string) perm_seat_types($pdo)[$key]['label'] : str_replace('_', ' ', (string) $s['role_in_dept']),
                'ends_on' => $s['ends_on'] ? substr((string) $s['ends_on'], 0, 10) : null,
                'can_renew' => $key !== null && (int) $s['year_live'] === 1,
                'department_id' => (int) $s['department_id'], 'seat_key' => $key,
            ];
        }
    }
    $access = [];
    foreach (perm_modules() as $module => $def) {
        $abilities = $ctx['abilities'][$module] ?? [];
        if ($abilities === [] && !$ctx['breakglass']) {
            continue;
        }
        $top = null;
        foreach (['delete', 'edit', 'create', 'view'] as $level) {
            if (isset($abilities[$level])) {
                $top = $level;
                break;
            }
        }
        $extras = array_values(array_filter(PERM_EXTRAS, static fn ($x) => isset($abilities[$x])));
        $access[] = [
            'module' => $module, 'label' => (string) $def['label'], 'group' => (string) $def['group'],
            'level' => $top, 'extras' => $extras,
            'scope' => $top !== null ? perm_scope_text($abilities[$top], $names) : '',
            'why' => array_values(array_unique(array_map(static fn ($s) => perm_source_text($pdo, $s, $roleLabels, $names),
                $ctx['sources'][$module] ?? []))),
        ];
    }
    return [
        'user' => ['id' => $uid, 'name' => perm_user_name($user), 'status' => str_replace('_', ' ', (string) ($user['spiritual_status'] ?? '')),
            'account' => (string) ($user['account_status'] ?? 'active')],
        'breakglass' => $ctx['breakglass'],
        'roles' => perm_user_role_rows($pdo, $uid),
        'seats' => $seats,
        'access' => $access,
    ];
}

// --------------------------------------------------------------------------
// Seats & ownership
// --------------------------------------------------------------------------

/** Change a seat type's label, rank, holder limit, sub-unit cover or appointing power. Its key never changes. */
function perm_seat_type_update(PDO $pdo, array $ctx, string $seatKey, array $in): void
{
    $type = perm_seat_types($pdo)[$seatKey] ?? null;
    if ($type === null) {
        throw new PermAdminError('That seat type is gone. Reload the page.', 'seat');
    }
    $label = trim((string) ($in['label'] ?? $type['label']));
    if (mb_strlen($label) < 2 || mb_strlen($label) > 40) {
        throw new PermAdminError('Give the seat a name of 2 to 40 characters.', 'label');
    }
    $rank = (int) ($in['seat_rank'] ?? $type['seat_rank']);
    if ($rank < 1 || $rank > 99) {
        throw new PermAdminError('Rank is a number from 1 to 99.', 'seat_rank');
    }
    $max = $in['max_holders'] ?? $type['max_holders'];
    $max = ($max === null || $max === '' || (int) $max <= 0) ? null : min(50, (int) $max);
    $after = [
        'label' => $label, 'seat_rank' => $rank, 'max_holders' => $max,
        'covers_sub_units' => !empty($in['covers_sub_units']) ? 1 : 0, 'can_appoint' => !empty($in['can_appoint']) ? 1 : 0,
    ];
    $before = [
        'label' => (string) $type['label'], 'seat_rank' => (int) $type['seat_rank'],
        'max_holders' => $type['max_holders'] === null ? null : (int) $type['max_holders'],
        'covers_sub_units' => (int) $type['covers_sub_units'], 'can_appoint' => (int) $type['can_appoint'],
    ];
    if ($before === $after) {
        return;
    }
    $pdo->prepare('UPDATE perm_seat_types SET label = ?, seat_rank = ?, max_holders = ?, covers_sub_units = ?, can_appoint = ? WHERE seat_key = ?')
        ->execute([$label, $rank, $max, $after['covers_sub_units'], $after['can_appoint'], $seatKey]);
    perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'seat_type.set', 'actor_user_id' => perm_actor_id($ctx),
        'seat_key' => $seatKey, 'before' => $before, 'after' => $after]);
    perm_reset();
}

/** Does a department use a seat type, and how many may hold it there (null = the seat type's limit)? */
function perm_dept_seat_set(PDO $pdo, array $ctx, int $deptId, string $seatKey, bool $enabled, $max = null): void
{
    if (!isset(perm_seat_types($pdo)[$seatKey]) || !isset(perm_department_names($pdo)[$deptId])) {
        throw new PermAdminError('That seat or department is gone. Reload the page.', 'gone');
    }
    $max = ($max === null || $max === '' || (int) $max <= 0) ? null : min(50, (int) $max);
    $stmt = $pdo->prepare('SELECT is_enabled, max_holders FROM perm_department_seats WHERE department_id = ? AND seat_key = ?');
    $stmt->execute([$deptId, $seatKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $before = $row ? ['enabled' => (int) $row['is_enabled'] === 1, 'max_holders' => $row['max_holders'] === null ? null : (int) $row['max_holders']]
                   : ['enabled' => true, 'max_holders' => null];
    $after = ['enabled' => $enabled, 'max_holders' => $max];
    if ($before === $after) {
        return;
    }
    $pdo->prepare('DELETE FROM perm_department_seats WHERE department_id = ? AND seat_key = ?')->execute([$deptId, $seatKey]);
    $pdo->prepare('INSERT INTO perm_department_seats (department_id, seat_key, is_enabled, max_holders) VALUES (?, ?, ?, ?)')
        ->execute([$deptId, $seatKey, $enabled ? 1 : 0, $max]);
    perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'dept_seat.set', 'actor_user_id' => perm_actor_id($ctx),
        'department_id' => $deptId, 'seat_key' => $seatKey, 'before' => $before, 'after' => $after]);
    perm_reset();
}

/** Does a department own a module (0 = every department)? Ownership decides where seat cells apply. */
function perm_owner_set(PDO $pdo, array $ctx, string $module, int $deptId, bool $owns): void
{
    if (!isset(perm_modules()[$module]) || ($deptId !== 0 && !isset(perm_department_names($pdo)[$deptId]))) {
        throw new PermAdminError('That module or department is gone. Reload the page.', 'gone');
    }
    // Ownership widens or narrows every seat cell of the module: the editor
    // must hold the module church-wide (or be break-glass).
    if (empty($ctx['breakglass']) && perm_ctx_rank($ctx, $module, 'view') < 3) {
        throw new PermAdminError("You can't change who owns a module you don't hold church-wide.", 'fits');
    }
    $had = in_array($deptId, perm_module_owners($pdo)[$module] ?? [], true);
    if ($had === $owns) {
        return;
    }
    if ($owns) {
        $pdo->prepare('INSERT INTO perm_module_owners (module_key, department_id) VALUES (?, ?)')->execute([$module, $deptId]);
    } else {
        $pdo->prepare('DELETE FROM perm_module_owners WHERE module_key = ? AND department_id = ?')->execute([$module, $deptId]);
    }
    perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'owner.set', 'actor_user_id' => perm_actor_id($ctx),
        'module_key' => $module, 'department_id' => $deptId ?: null, 'before' => ['owns' => $had], 'after' => ['owns' => $owns]]);
    perm_reset();
}

/** The live ministry year, or null. */
function perm_live_year(PDO $pdo): ?array
{
    try {
        $row = $pdo->query('SELECT id, label, start_date, end_date FROM ministry_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $row ? ['id' => (int) $row['id'], 'label' => (string) $row['label'],
        'start_date' => substr((string) $row['start_date'], 0, 10), 'end_date' => substr((string) $row['end_date'], 0, 10)] : null;
}

/** Seats tab: seat types, seats in use per department, module owners, and the year end. */
function perm_seats_overview(PDO $pdo): array
{
    $names = perm_department_names($pdo);
    $tree = perm_department_tree($pdo);
    $enabled = perm_department_seats($pdo);
    $max = [];
    foreach ($pdo->query('SELECT department_id, seat_key, max_holders FROM perm_department_seats')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['max_holders'] !== null) {
            $max[(int) $r['department_id']][(string) $r['seat_key']] = (int) $r['max_holders'];
        }
    }
    $live = perm_live_seats($pdo, null, perm_today());
    $counts = [];
    $typeCounts = [];
    foreach ($live as $s) {
        $counts[$s['department_id']][$s['seat_key']] = ($counts[$s['department_id']][$s['seat_key']] ?? 0) + 1;
        $typeCounts[$s['seat_key']] = ($typeCounts[$s['seat_key']] ?? 0) + 1;
    }
    $types = [];
    foreach (perm_seat_types($pdo) as $key => $t) {
        $types[] = [
            'key' => (string) $key, 'label' => (string) $t['label'], 'rank' => (int) $t['seat_rank'],
            'max_holders' => $t['max_holders'] === null ? null : (int) $t['max_holders'],
            'covers_sub_units' => (int) $t['covers_sub_units'] === 1, 'can_appoint' => (int) $t['can_appoint'] === 1,
            'is_leader' => (int) $t['is_leader'] === 1, 'archived' => (int) $t['is_archived'] === 1, 'people' => $typeCounts[$key] ?? 0,
        ];
    }
    $depts = [];
    foreach ($names as $id => $name) {
        $row = ['id' => $id, 'name' => $name, 'parent_id' => $tree[$id] ?? null, 'seats' => []];
        foreach (perm_seat_types($pdo) as $key => $t) {
            $row['seats'][$key] = ['enabled' => $enabled[$id][$key] ?? true, 'max' => $max[$id][$key] ?? null, 'people' => $counts[$id][$key] ?? 0];
        }
        $depts[] = $row;
    }
    $year = perm_live_year($pdo);
    if ($year !== null) {
        $ending = 0;
        $endingDepts = [];
        if (perm_roster_has_years($pdo)) {
            $stmt = $pdo->prepare("SELECT ud.department_id FROM user_departments ud
                                    WHERE ud.ministry_year_id = ? AND ud.is_active = 1 AND ud.end_date IS NULL
                                      AND COALESCE(ud.roster_status, 'Active') = 'Active'");
            $stmt->execute([$year['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $ending++;
                $endingDepts[(int) $d] = true;
            }
        }
        $days = (int) (new DateTimeImmutable(perm_today()))->diff(new DateTimeImmutable($year['end_date']))->format('%r%a');
        $year += ['days_left' => $days, 'seats_ending' => $ending, 'departments_ending' => count($endingDepts)];
    }
    return [
        'types' => $types, 'departments' => $depts, 'owners' => (object) perm_module_owners($pdo), 'year' => $year,
        'expiry_enforced' => perm_expiry_enforced($pdo),
    ];
}

// --------------------------------------------------------------------------
// History
// --------------------------------------------------------------------------

/** perm_history, newest first. Filters: person, role, module, type (prefix), from, to (Y-m-d). */
function perm_history_list(PDO $pdo, array $f = [], int $limit = 50, int $offset = 0): array
{
    $where = [];
    $params = [];
    if (!empty($f['person'])) {
        $where[] = '(h.subject_user_id = ? OR h.actor_user_id = ?)';
        $params[] = (int) $f['person'];
        $params[] = (int) $f['person'];
    }
    if (!empty($f['role'])) {
        $where[] = 'h.role_id = ?';
        $params[] = (int) $f['role'];
    }
    if (!empty($f['module']) && isset(perm_modules()[$f['module']])) {
        $where[] = 'h.module_key = ?';
        $params[] = (string) $f['module'];
    }
    if (!empty($f['type']) && preg_match('/^[a-z_.]{2,40}$/', (string) $f['type'])) {
        $where[] = 'h.change_type LIKE ?';
        $params[] = $f['type'] . '%';
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) {
            $where[] = "h.created_at {$op} ?";
            $params[] = $f[$k] . ($k === 'to' ? ' 23:59:59' : ' 00:00:00');
        }
    }
    $sql = 'SELECT h.*, s.first_name AS s_first, s.last_name AS s_last
              FROM perm_history h LEFT JOIN users s ON s.id = h.subject_user_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY h.created_at DESC, h.id DESC LIMIT ' . max(1, min(200, $limit) + 1) . ' OFFSET ' . max(0, $offset);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $roleLabels = [];
    foreach (perm_roles_list($pdo) as $r) {
        $roleLabels[$r['id']] = $r['label'];
    }
    $names = perm_department_names($pdo);
    $out = [];
    foreach ($rows as $h) {
        $out[] = [
            'id' => (int) $h['id'], 'at' => (string) $h['created_at'], 'type' => (string) $h['change_type'], 'via' => (string) $h['via'],
            'actor' => (string) ($h['actor_label'] ?? '') ?: ($h['actor_user_id'] ? '#' . $h['actor_user_id'] : 'System'),
            'subject' => trim((string) ($h['s_first'] ?? '') . ' ' . (string) ($h['s_last'] ?? '')),
            'subject_id' => $h['subject_user_id'] === null ? null : (int) $h['subject_user_id'],
            'role' => $h['role_id'] ? ($roleLabels[(int) $h['role_id']] ?? '#' . $h['role_id']) : '',
            'seat' => $h['seat_key'] ? (string) (perm_seat_types($pdo)[$h['seat_key']]['label'] ?? $h['seat_key']) : '',
            'department' => $h['department_id'] ? ($names[(int) $h['department_id']] ?? '') : '',
            'module' => $h['module_key'] ? (string) (perm_modules()[$h['module_key']]['label'] ?? $h['module_key']) : '',
            'before' => $h['before_json'] ? json_decode((string) $h['before_json'], true) : null,
            'after' => $h['after_json'] ? json_decode((string) $h['after_json'], true) : null,
            'reason' => (string) ($h['reason'] ?? ''),
        ];
    }
    return ['rows' => $out, 'more' => $more];
}

// --------------------------------------------------------------------------
// View as (design §3): read-only, never more than the viewer holds
// --------------------------------------------------------------------------

function perm_view_as_start(PDO $pdo, array $real, string $kind, int $id): array
{
    if (!$real['breakglass'] && !perm_ctx_can($real, 'roles', 'view')) {
        throw new PermAdminError('Viewing as someone needs Roles & permissions access.', 'forbidden');
    }
    if ($kind === 'person') {
        $user = perm_user_row($pdo, $id);
        if ($user === null) {
            throw new PermAdminError('That person is gone. Reload the page.', 'person');
        }
        $label = perm_user_name($user);
    } elseif ($kind === 'role') {
        $role = perm_role_row($pdo, $id);
        if ($role === null) {
            throw new PermAdminError('That role is gone. Reload the page.', 'role');
        }
        $label = perm_role_label($role) . ' (role)';
    } else {
        throw new PermAdminError('Pick a person or a role.', 'kind');
    }
    $_SESSION['perm_view_as'] = ['kind' => $kind, 'id' => $id, 'label' => $label];
    perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'view_as.start', 'actor_user_id' => (int) $real['user_id'],
        'subject_user_id' => $kind === 'person' ? $id : null, 'role_id' => $kind === 'role' ? $id : null]);
    perm_reset();
    return ['kind' => $kind, 'id' => $id, 'label' => $label];
}

function perm_view_as_stop(PDO $pdo): void
{
    $va = perm_view_as();
    unset($_SESSION['perm_view_as']);
    if ($va !== null) {
        perm_history_add($pdo, ['via' => 'roles_screen', 'change_type' => 'view_as.stop',
            'subject_user_id' => $va['kind'] === 'person' ? $va['id'] : null, 'role_id' => $va['kind'] === 'role' ? $va['id'] : null]);
    }
    perm_reset();
}
