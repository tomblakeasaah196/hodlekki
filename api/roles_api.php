<?php
// /api/roles_api.php
//
// Roles & permissions (modules/roles/). Reading needs roles: View (Super
// Admins, the Resident Pastor); every change needs roles: Manage (Super Admins)
// AND fits within the editor's own access (includes/permissions/admin.php).
// Decided on the person's OWN access, never a "view as" one: while viewing as
// someone, every change here is refused (view-as is read-only).
//
// Every change writes perm_history (role_assignment_logs is retired: kept for
// the old rows, written by nothing). Freezing accounts lives in the Security
// Centre.
//
// Responses: {status: 'success', …} or {status: 'error', code, message[, link]}.

require_once '../includes/db.php';
require_once '../includes/permissions/expiry.php';
csrf_require();

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$isForm = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SERVER['HTTP_X_CSRF_TOKEN'])
    && stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === false;

/** Stop "view as" (the shell's banner posts a plain form here; the screen uses HOD.api). */
if ($action === 'view_as_stop') {
    if (!empty($_SESSION['user_id'])) {
        perm_view_as_stop($pdo);
    }
    if ($isForm) {
        header('Location: /modules/roles/', true, 303);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Back to your own view.']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

function roles_out(array $data): void
{
    echo json_encode(['status' => 'success'] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

function roles_fail(string $message, string $code = 'error', int $http = 200, ?string $link = null): void
{
    http_response_code($http);
    echo json_encode(['status' => 'error', 'code' => $code, 'message' => $message] + ($link ? ['link' => $link] : []));
    exit;
}

if (empty($_SESSION['user_id'])) {
    roles_fail('Please sign in again.', 'SIGNED_OUT', 401);
}
$real = perm_real_current();
if ($real === null || !$real['ready']) {
    roles_fail('Roles & permissions is being set up. Try again after the next deploy.', 'not_ready');
}
$canView = $real['breakglass'] || perm_ctx_can($real, 'roles', 'view');
$canManage = $real['breakglass'] || perm_ctx_can($real, 'roles', 'manage');
if (!$canView) {
    roles_fail('Roles & permissions is not open to you.', 'FORBIDDEN', 403);
}

// Reads and "view as" need View. Renewing or ending a seat needs View plus the
// right to appoint that seat (checked in perm_seat_renew / perm_seat_end). Everything else needs Manage,
// and passes fits-within inside admin.php.
$reads = ['matrix', 'roles', 'people_search', 'person', 'seats', 'history', 'impact', 'view_as_start'];
if (!in_array($action, $reads, true)) {
    if (!in_array($action, ['seat_renew', 'seat_end'], true) && !$canManage) {
        roles_fail('Only a Super Admin can change roles and permissions.', 'FORBIDDEN', 403);
    }
    if (perm_view_as() !== null) {
        roles_fail('Stop viewing as someone first.', 'view_as');
    }
}

$in = static fn (string $k, $d = '') => $_POST[$k] ?? $_GET[$k] ?? $d;
$int = static fn (string $k): int => (int) ($_POST[$k] ?? $_GET[$k] ?? 0);

try {
    switch ($action) {
        case 'matrix':
            roles_out(perm_matrix_data($pdo, $real) + ['can_manage' => $canManage]);

        case 'roles':
            $presets = [];
            foreach (perm_presets() as $key => $p) {
                $presets[] = ['key' => $key, 'label' => $p['label'], 'info' => $p['info']];
            }
            roles_out(['roles' => perm_roles_list($pdo), 'presets' => $presets, 'can_manage' => $canManage]);

        case 'people_search':
            roles_out(['people' => perm_people_search($pdo, (string) $in('q'))]);

        case 'person':
            roles_out(perm_person_overview($pdo, $int('user_id')) + ['can_manage' => $canManage]);

        case 'seats':
            $modules = [];
            foreach (perm_modules() as $key => $def) {
                $modules[] = ['key' => $key, 'label' => $def['label'], 'group' => $def['group'], 'partitioned' => !empty($def['partitioned'])];
            }
            roles_out(perm_seats_overview($pdo) + ['modules' => $modules, 'can_manage' => $canManage,
                'blockers' => perm_expiry_blockers($pdo),
                'can_roll' => $real['breakglass'] || perm_ctx_can($real, 'departments', 'manage')]);

        case 'history':
            $f = ['person' => $int('person'), 'role' => $int('role'), 'module' => (string) $in('module'),
                'type' => (string) $in('type'), 'from' => (string) $in('from'), 'to' => (string) $in('to')];
            roles_out(perm_history_list($pdo, $f, 50, max(0, $int('offset'))));

        case 'impact':
            roles_out(['people' => perm_holder_reach($pdo, (string) $in('holder'))]);

        case 'view_as_start':
            $va = perm_view_as_start($pdo, $real, (string) $in('kind'), $int('id'));
            roles_out(['view_as' => $va, 'message' => 'Viewing as ' . $va['label'] . '.']);

        case 'seat_renew':
            $new = perm_seat_renew($pdo, $real, $int('record_id'));
            roles_out(['ends_on' => $new, 'message' => 'Renewed until ' . perm_date_text($new) . '.']);

        case 'seat_end':
            perm_seat_end($pdo, $real, $int('record_id'));
            roles_out(['message' => 'Seat ended.']);

        // ---------------------------------------------------------------- changes
        case 'expiry_on':
            perm_expiry_switch_on($pdo, $real);
            roles_out(['message' => 'End dates are on.']);

        case 'cell_set':
            $n = perm_cells_set($pdo, $real, [[
                'holder' => (string) $in('holder'), 'module' => (string) $in('module'),
                'cell' => ['level' => $in('level', 0), 'approve' => $in('approve', 0), 'export' => $in('export', 0),
                    'manage' => $in('manage', 0), 'scope' => $in('scope', 'church'), 'view_scope' => $in('view_scope', '')],
            ]]);
            $h = perm_holder_parse((string) $in('holder'));
            $cell = perm_cell_get($pdo, $h, (string) $in('module'));
            roles_out(['changed' => $n, 'cell' => $cell, 'message' => $n ? 'Saved.' : 'No change.']);

        case 'column':
            $changes = perm_column_changes($pdo, (string) $in('holder'), (string) $in('op'), (string) $in('from'));
            $n = perm_cells_set($pdo, $real, $changes, 'roles_screen', 'column: ' . $in('op'));
            roles_out(['changed' => $n, 'message' => $n . ' ' . ($n === 1 ? 'cell' : 'cells') . ' changed.']);

        case 'group_set':
            $changes = perm_group_changes((string) $in('holder'), (string) $in('group'), [
                'level' => $in('level', 0), 'scope' => $in('scope', 'church'),
                'approve' => $in('approve', 0), 'export' => $in('export', 0), 'manage' => $in('manage', 0),
            ]);
            $n = perm_cells_set($pdo, $real, $changes, 'roles_screen', 'group: ' . $in('group'));
            roles_out(['changed' => $n, 'message' => $n . ' ' . ($n === 1 ? 'cell' : 'cells') . ' changed.']);

        case 'role_create':
            $id = perm_role_create($pdo, $real, (string) $in('label'), (string) $in('description'),
                (string) $in('preset', 'blank'), $int('copy_from'));
            roles_out(['role_id' => $id, 'message' => 'Role created.']);

        case 'role_update':
            perm_role_update($pdo, $real, $int('role_id'), (string) $in('label'), (string) $in('description'));
            roles_out(['message' => 'Role saved.']);

        case 'role_archive':
            $archive = (string) $in('archive', '1') !== '0';
            perm_role_archive($pdo, $real, $int('role_id'), $archive);
            roles_out(['message' => $archive ? 'Role archived.' : 'Role restored.']);

        case 'user_role_add':
            perm_user_role_add($pdo, $real, $int('user_id'), $int('role_id'), (string) $in('starts_on'), (string) $in('ends_on'),
                (string) $in('custom_title'));
            roles_out(['message' => 'Role given.']);

        case 'user_role_remove':
            perm_user_role_remove($pdo, $real, $int('user_id'), $int('role_id'), (string) $in('reason'));
            roles_out(['message' => 'Role removed.']);

        case 'user_role_dates':
            perm_user_role_dates($pdo, $real, $int('user_id'), $int('role_id'), (string) $in('starts_on'), (string) $in('ends_on'),
                (string) $in('custom_title'));
            roles_out(['message' => 'Dates saved.']);

        case 'user_role_renew':
            $new = perm_user_role_renew($pdo, $real, $int('user_id'), $int('role_id'));
            roles_out(['ends_on' => $new, 'message' => 'Renewed until ' . perm_date_text($new) . '.']);

        case 'seat_type_set':
            perm_seat_type_update($pdo, $real, (string) $in('seat_key'), [
                'label' => $in('label'), 'seat_rank' => $in('seat_rank'), 'max_holders' => $in('max_holders', ''),
                'covers_sub_units' => $in('covers_sub_units', 0), 'can_appoint' => $in('can_appoint', 0),
            ]);
            roles_out(['message' => 'Seat saved.']);

        case 'dept_seat_set':
            perm_dept_seat_set($pdo, $real, $int('department_id'), (string) $in('seat_key'),
                (string) $in('enabled', '1') !== '0', $in('max_holders', ''));
            roles_out(['message' => 'Saved.']);

        case 'owner_set':
            perm_owner_set($pdo, $real, (string) $in('module'), $int('department_id'), (string) $in('owns', '1') !== '0');
            roles_out(['message' => 'Saved.']);

        default:
            roles_fail('Unknown action. Reload the page.', 'action');
    }
} catch (PermAdminError $e) {
    roles_fail($e->getMessage(), $e->errorCode, 200, $e->link);
} catch (Throwable $e) {
    error_log('roles_api ' . $action . ': ' . $e->getMessage());
    roles_fail('That did not save. Try again; if it keeps failing, tell Information and Data Insights (IDI).', 'server', 500);
}
