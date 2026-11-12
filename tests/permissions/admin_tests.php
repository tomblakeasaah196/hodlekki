<?php
// /tests/permissions/admin_tests.php
//
// Permissions PR 3: changing the permission data (includes/permissions/admin.php)
// and end dates (includes/permissions/expiry.php). Loaded by run.php after its
// own sections; uses its ok()/eq()/section() and the SQLite church in lib.php.

require_once __DIR__ . '/../../includes/permissions/expiry.php';

/** A fresh church with notifications, contact columns and the PR 3 migration. */
function perm_admin_db(): PDO
{
    $pdo = perm_test_db();
    $pdo->exec('ALTER TABLE users ADD COLUMN phone TEXT');
    $pdo->exec('ALTER TABLE users ADD COLUMN email TEXT');
    $pdo->exec('CREATE TABLE system_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT,
                message TEXT, link_url TEXT, is_read INTEGER DEFAULT 0)');
    $pdo->exec(perm_test_migration('20261201090000_perm_expiry_on.sql'));
    perm_reset();
    return $pdo;
}

function perm_admin_person(PDO $pdo, string $first, string $status = 'Worker'): int
{
    $uid = perm_test_person($pdo, $first);
    $pdo->prepare('UPDATE users SET spiritual_status = ?, phone = ? WHERE id = ?')->execute([$status, '0800000' . $uid, $uid]);
    return $uid;
}

function perm_admin_messages(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare('SELECT title FROM system_notifications WHERE user_id = ? ORDER BY id');
    $stmt->execute([$uid]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function perm_admin_history(PDO $pdo, string $type): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM perm_history WHERE change_type = ?');
    $stmt->execute([$type]);
    return (int) $stmt->fetchColumn();
}

/** Expect a PermAdminError with this code. */
function perm_admin_refused(string $label, string $code, callable $fn): void
{
    try {
        $fn();
        ok($label, false, 'no error');
    } catch (PermAdminError $e) {
        eq($label, $code, $e->errorCode);
    }
}

// ---------------------------------------------------------------------------
section('PR 3: the expiry migration switches end dates on');
$pdo = perm_admin_db();
ok('expiry_enforced = 1 after 20261201090000', perm_expiry_enforced($pdo));
$pdo->exec(perm_test_migration('20261201090000_perm_expiry_on.sql'));
eq('running it twice leaves one row', 1, (int) $pdo->query("SELECT COUNT(*) FROM perm_config WHERE cfg_key = 'expiry_enforced'")->fetchColumn());

// ---------------------------------------------------------------------------
section('PR 3: cells, fits within, history');
$sa = perm_admin_person($pdo, 'Admin');
perm_test_role($pdo, $sa, 'Super_Admin');
$saCtx = perm_resolve($pdo, $sa);
$clean = perm_cell_clean('requisition', ['level' => 'delete', 'approve' => 1, 'manage' => 1, 'export' => '1', 'scope' => 'dept']);
eq('a cell is clamped to the module: requisition stops at create, has no manage', ['level' => 2, 'approve' => 1, 'export' => 1, 'manage' => 0, 'scope' => 'dept', 'view_scope' => null], $clean);
eq('everyone has no department: dept becomes own', 'own', perm_cell_clean('reach', ['level' => 'view', 'scope' => 'dept'], 'everyone')['scope']);

// A manager who is not break-glass: Roles manage + Charis edit for their departments only.
$pdo->exec("INSERT INTO roles (id, role_name, label) VALUES (20, 'Access_Officer', 'Access officer')");
$pdo->exec("INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
            VALUES (20, '', 'roles', 1, 0, 0, 1, 'church', NULL), (20, '', 'charis', 3, 0, 0, 0, 'dept', 'church')");
$officer = perm_admin_person($pdo, 'Officer');
perm_test_role($pdo, $officer, 'Access_Officer');
perm_test_seat($pdo, $officer, 6, 'Worker');   // Sanitation: owns nothing
$offCtx = perm_resolve($pdo, $officer);
ok('fits: view Charis church-wide (they view church-wide)', perm_fits_within($offCtx, 'charis', perm_cell_clean('charis', ['level' => 'view', 'scope' => 'church'])));
ok('fits: edit Charis for "your departments"', perm_fits_within($offCtx, 'charis', perm_cell_clean('charis', ['level' => 'edit', 'scope' => 'dept'])));
eq('does not fit: edit Charis church-wide', 'create', perm_cell_overreach($offCtx, 'charis', perm_cell_clean('charis', ['level' => 'edit', 'scope' => 'church']), 'role'));
eq('does not fit: a seat cell on an undivided module reaches all of it', 'create',
    perm_cell_overreach($offCtx, 'charis', perm_cell_clean('charis', ['level' => 'edit', 'scope' => 'dept'], 'seat'), 'seat'));
eq('does not fit: Charis approve', 'approve', perm_cell_overreach($offCtx, 'charis', perm_cell_clean('charis', ['level' => 'view', 'approve' => 1, 'scope' => 'dept']), 'role'));
ok('break-glass fits everything', perm_fits_within($saCtx, 'finance', perm_cell_clean('finance', ['level' => 'delete', 'scope' => 'church'])));

$pdo->exec("INSERT INTO roles (id, role_name) VALUES (21, 'Test_Blank')");
perm_admin_refused('the API refuses a cell beyond the editor', 'fits', fn () => perm_cells_set($pdo, $offCtx,
    [['holder' => 'r:21', 'module' => 'finance', 'cell' => ['level' => 'view', 'scope' => 'church']]]));
$before = perm_admin_history($pdo, 'grant.set');
eq('one changed cell', 1, perm_cells_set($pdo, $offCtx, [['holder' => 'r:21', 'module' => 'charis', 'cell' => ['level' => 'view', 'scope' => 'church']]]));
eq('one history row per changed cell', $before + 1, perm_admin_history($pdo, 'grant.set'));
eq('saving the same cell again changes nothing', 0, perm_cells_set($pdo, $offCtx, [['holder' => 'r:21', 'module' => 'charis', 'cell' => ['level' => 'view', 'scope' => 'church']]]));
$h = $pdo->query("SELECT before_json, after_json FROM perm_history WHERE change_type = 'grant.set' ORDER BY id DESC LIMIT 1")->fetch();
ok('history keeps before and after', str_contains((string) $h['after_json'], '"level":1') && str_contains((string) $h['before_json'], '"level":0'));
perm_admin_refused('changing a cell the editor does not hold is refused (the Worker seat reaches all of Charis)', 'fits', fn () => perm_cells_set($pdo, $offCtx,
    [['holder' => 's:worker', 'module' => 'charis', 'cell' => ['level' => 'view', 'scope' => 'church']]]));
perm_admin_refused('nobody takes away what they do not hold (Finance on the Director seat)', 'fits', fn () => perm_cells_set($pdo, $offCtx,
    [['holder' => 's:director', 'module' => 'finance', 'cell' => []]]));
eq('a blank cell deletes the row', 1, perm_cells_set($pdo, $saCtx, [['holder' => 'r:21', 'module' => 'charis', 'cell' => []]]));
eq('…and it is gone', 0, (int) $pdo->query("SELECT COUNT(*) FROM perm_grants WHERE role_id = 21")->fetchColumn());
$n = perm_cells_set($pdo, $saCtx, perm_column_changes($pdo, 's:member', 'readonly'));
ok('read-only church-wide: view everywhere except the sensitive modules', $n > 20
    && (int) $pdo->query("SELECT COUNT(*) FROM perm_grants WHERE seat_key = 'member' AND module_key IN ('finance', 'roles', 'pastoral_notes')")->fetchColumn() === 0);
perm_cells_set($pdo, $saCtx, perm_column_changes($pdo, 's:member', 'clear'));
eq('clear empties the column', 0, (int) $pdo->query("SELECT COUNT(*) FROM perm_grants WHERE seat_key = 'member'")->fetchColumn());
$n = perm_cells_set($pdo, $saCtx, perm_column_changes($pdo, 's:member', 'copy', 's:worker'));
$w = perm_grants_all($pdo)['s:worker'] ?? [];
$m = perm_grants_all($pdo)['s:member'] ?? [];
ksort($w);
ksort($m);
eq('copy makes the column equal to the source', $w, $m);

// ---------------------------------------------------------------------------
section('PR 3: roles — presets, copy, rename, archive');
perm_admin_refused('a role may not be called a member status', 'label', fn () => perm_role_create($pdo, $saCtx, 'Worker'));
perm_admin_refused('names are unique', 'label', fn () => perm_role_create($pdo, $saCtx, 'access officer'));
$ro = perm_role_create($pdo, $saCtx, 'Auditor', 'Reads everything', 'readonly');
ok('preset: read-only church-wide', count(perm_grants_all($pdo)['r:' . $ro] ?? []) > 20);
eq('preset never opens Finance', null, perm_grants_all($pdo)['r:' . $ro]['finance'] ?? null);
ok('role.create in history', perm_admin_history($pdo, 'role.create') === 1);
$copy = perm_role_create($pdo, $saCtx, 'Auditor two', '', 'blank', $ro);
eq('copy has the same cells', perm_grants_all($pdo)['r:' . $ro], perm_grants_all($pdo)['r:' . $copy]);
perm_admin_refused('a non-break-glass editor cannot create a role beyond them', 'fits', fn () => perm_role_create($pdo, $offCtx, 'Big role', '', 'readonly'));
eq('…and nothing was left behind', 0, (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE label = 'Big role'")->fetchColumn());

$aud = perm_admin_person($pdo, 'Audrey');
perm_user_role_add($pdo, $saCtx, $aud, $ro);
$canBefore = perm_ctx_can(perm_resolve($pdo, $aud), 'congregation', 'view', 'church');
perm_role_update($pdo, $saCtx, $ro, 'Church auditor', 'Reads everything');
ok('renaming a role changes no decision', $canBefore && perm_ctx_can(perm_resolve($pdo, $aud), 'congregation', 'view', 'church'));
eq('the new name shows', 'Church auditor', perm_role_label(perm_role_row($pdo, $ro)));
perm_admin_refused('archive refused while someone holds it', 'held', fn () => perm_role_archive($pdo, $saCtx, $ro));
perm_admin_refused('the Super Admin role is never archived', 'breakglass', fn () => perm_role_archive($pdo, $saCtx, 1));
perm_role_archive($pdo, $saCtx, $copy);
ok('archived role leaves the matrix', !in_array('r:' . $copy, array_column(perm_matrix_data($pdo, $saCtx)['holders'], 'key'), true));

// ---------------------------------------------------------------------------
section('PR 3: people — giving and taking roles, dates, notifications');
$visitor = perm_admin_person($pdo, 'Vera', 'Visitor');
perm_admin_refused('only a Worker or a Pastor gets a role', 'status', fn () => perm_user_role_add($pdo, $saCtx, $visitor, $ro));
$noContact = perm_admin_person($pdo, 'Nadia');
$pdo->exec("UPDATE users SET phone = NULL WHERE id = {$noContact}");
perm_admin_refused('a profile without contact details is refused', 'profile', fn () => perm_user_role_add($pdo, $saCtx, $noContact, $ro));
$wanda = perm_admin_person($pdo, 'Wanda');
perm_admin_refused('nobody gives a role beyond themselves', 'fits', fn () => perm_user_role_add($pdo, $offCtx, $wanda, 2));
perm_admin_refused('only break-glass gives break-glass', 'fits', fn () => perm_user_role_add($pdo, $offCtx, $aud, 1));
eq('the new holder was told', ['New role: Auditor'], perm_admin_messages($pdo, $aud));
eq('user_role.add in history', 1, perm_admin_history($pdo, 'user_role.add'));
perm_admin_refused('holding it twice is refused', 'exists', fn () => perm_user_role_add($pdo, $saCtx, $aud, $ro));

perm_user_role_dates($pdo, $saCtx, $aud, $ro, null, '2026-11-10');
ok('a role past its end date no longer counts', !perm_ctx_can(perm_resolve($pdo, $aud), 'congregation', 'view', 'church'));
perm_user_role_dates($pdo, $saCtx, $aud, $ro, '2026-11-20', null);
ok('nor one that has not started', !perm_ctx_can(perm_resolve($pdo, $aud), 'congregation', 'view', 'church'));
perm_user_role_dates($pdo, $saCtx, $aud, $ro, null, '2026-11-30');
ok('dated and live: counts', perm_ctx_can(perm_resolve($pdo, $aud), 'congregation', 'view', 'church'));
eq('renew moves a role 12 months on when no next year exists', '2027-11-30', perm_user_role_renew($pdo, $saCtx, $aud, $ro));
perm_user_role_dates($pdo, $saCtx, $sa, 1, null, '2026-01-01');
ok('Super Admin never ends', perm_resolve($pdo, $sa)['breakglass'] && perm_user_role_rows($pdo, $sa)[0]['ends_on'] === null);
perm_admin_refused('the last Super Admin cannot be removed', 'last', fn () => perm_user_role_remove($pdo, $saCtx, $sa, 1));
perm_user_role_remove($pdo, $saCtx, $aud, $ro);
ok('removed and told', !perm_ctx_can(perm_resolve($pdo, $aud), 'congregation', 'view', 'church')
    && in_array('Role removed: Church auditor', perm_admin_messages($pdo, $aud), true));
$o = perm_person_overview($pdo, $officer);
$charis = array_values(array_filter($o['access'], fn ($a) => $a['module'] === 'charis'))[0] ?? null;
ok('effective access says why: the role', $charis !== null && $charis['level'] === 'edit' && in_array('Role: Access officer', $charis['why'], true),
    json_encode($charis));
ok('…and the seat shows', ($o['seats'][0]['seat'] ?? '') === 'Worker' && ($o['seats'][0]['department'] ?? '') === 'Sanitation');
eq('people search finds by name', [$officer], array_column(perm_people_search($pdo, 'Offi'), 'id'));

// ---------------------------------------------------------------------------
section('PR 3: view as — read-only, never more than the viewer');
$rp = perm_admin_person($pdo, 'Pastor', 'Pastor');
perm_test_role($pdo, $rp, 'Resident_Pastor');
$hod = perm_admin_person($pdo, 'Hannah');
perm_test_seat($pdo, $hod, 10, 'HOD');
perm_test_as($rp);
perm_reset();
ok('the Resident Pastor holds roles: view', can('roles'));
$hodCtx = perm_resolve($pdo, $hod);
perm_view_as_start($pdo, perm_real_current(), 'person', $hod);
perm_reset();
eq('viewing as: can() follows the subject', perm_ctx_can($hodCtx, 'charis', 'view'), can('charis'));
ok('read-only: no edit where the subject may edit', perm_ctx_can($hodCtx, 'charis', 'edit') && !can('charis', 'edit'));
ok('read-only: no export', !can('charis', 'export'));
ok('never more than the viewer: the subject\'s Roles screen is not theirs anyway', !can('roles', 'manage'));
ok('the real context is untouched', perm_real_current()['user_id'] === $rp && perm_ctx_can(perm_real_current(), 'roles', 'view'));
perm_view_as_stop($pdo);
perm_reset();
ok('stop: back to their own access', can('roles') && perm_view_as() === null);
eq('start and stop are in history', [1, 1], [perm_admin_history($pdo, 'view_as.start'), perm_admin_history($pdo, 'view_as.stop')]);

// The officer views as a Super Admin: only what the officer holds, read-only.
perm_test_as($officer);
perm_reset();
perm_view_as_start($pdo, perm_real_current(), 'person', $sa);
perm_reset();
ok('viewing a Super Admin: Charis view (the viewer has it)', can('charis', 'view', 'church'));
ok('…but not Finance (the viewer lacks it)', !can('finance'));
perm_view_as_stop($pdo);
perm_view_as_start($pdo, perm_real_current(), 'role', $ro);
perm_reset();
ok('view as a role works', perm_view_as()['kind'] === 'role' && !can('finance') && !can('charis', 'edit'));
perm_view_as_stop($pdo);
perm_test_as($hod);
perm_reset();
perm_admin_refused('view-as needs roles: view', 'forbidden', fn () => perm_view_as_start($pdo, perm_real_current(), 'person', $sa));
$_SESSION['perm_view_as'] = ['kind' => 'person', 'id' => $sa, 'label' => 'x'];
perm_reset();
ok('a planted view-as session without roles: view is dropped', perm_current()['user_id'] === $hod && perm_view_as() === null);
$_SESSION = [];

// ---------------------------------------------------------------------------
section('PR 3: seats & ownership');
perm_seat_type_update($pdo, $saCtx, 'deputy_hod', ['label' => 'Assistant HOD', 'seat_rank' => 35, 'max_holders' => 3, 'covers_sub_units' => 1, 'can_appoint' => 0]);
eq('seat type relabelled, key unchanged', 'Assistant HOD', perm_seat_types($pdo)['deputy_hod']['label']);
perm_dept_seat_set($pdo, $saCtx, 10, 'deputy_hod', false);
eq('a department can stop using a seat type', false, perm_department_seats($pdo)[10]['deputy_hod']);
perm_owner_set($pdo, $saCtx, 'charis', 3, true);
ok('ownership added', in_array(3, perm_module_owners($pdo)['charis'], true));
perm_admin_refused('ownership needs the module church-wide', 'fits', fn () => perm_owner_set($pdo, perm_resolve($pdo, $hod), 'finance', 3, true));
$ov = perm_seats_overview($pdo);
ok('overview counts live seats', $ov['types'][array_search('hod', array_column($ov['types'], 'key'), true)]['people'] >= 1 && $ov['year']['id'] === 1);

// ---------------------------------------------------------------------------
section('PR 3: end dates — renew, reminders, the daily run');
$pdo = perm_admin_db();
$sa = perm_admin_person($pdo, 'Admin');
perm_test_role($pdo, $sa, 'Super_Admin');
$rp = perm_admin_person($pdo, 'Pastor', 'Pastor');
perm_test_role($pdo, $rp, 'Resident_Pastor');
$pic = perm_admin_person($pdo, 'Paul');
perm_test_seat($pdo, $pic, 10, 'Assoc_Pastor');
$hod = perm_admin_person($pdo, 'Hannah');
perm_test_seat($pdo, $hod, 10, 'HOD');
$early = perm_admin_person($pdo, 'Esther');
perm_test_seat($pdo, $early, 2, 'Worker', ['end_date' => '2026-12-20']);
$gone = perm_admin_person($pdo, 'Gideon');
perm_test_seat($pdo, $gone, 2, 'Worker', ['end_date' => '2026-11-01']);
$dated = perm_admin_person($pdo, 'Dora');
perm_test_role($pdo, $dated, 'Assoc_Pastor', ['ends_on' => '2026-11-20']);
$pdo->exec("UPDATE user_roles SET assigned_by = {$sa} WHERE user_id = {$dated}");
$saCtx = perm_resolve($pdo, $sa);

ok('a seat past its own end date no longer counts (enforced)', perm_person_seats($pdo, $gone) === []);
eq('next end: twelve months on', '2027-12-31', perm_next_end($pdo, '2026-12-31'));
$pdo->exec("INSERT INTO ministry_years (id, label, start_date, end_date, is_active) VALUES (2, '2027', '2027-01-01', '2027-08-31', 0)");
eq('next end: the next ministry year\'s end', '2027-08-31', perm_next_end($pdo, '2026-12-31'));
$hodRecord = (int) $pdo->query("SELECT id FROM user_departments WHERE user_id = {$hod}")->fetchColumn();
perm_admin_refused('a Worker cannot renew a HOD seat', 'rank', fn () => perm_seat_renew($pdo, perm_resolve($pdo, $early), $hodRecord));
eq('renew: one click, to the next year\'s end', '2027-08-31', perm_seat_renew($pdo, perm_resolve($pdo, $pic), $hodRecord));
ok('renewal told the holder', in_array('Seat renewed', perm_admin_messages($pdo, $hod), true));
$pdo->exec("UPDATE user_departments SET end_date = NULL WHERE id = {$hodRecord}");
$pdo->exec('DELETE FROM ministry_years WHERE id = 2');
$pdo->exec('DELETE FROM system_notifications');

perm_set_today('2026-11-11');
$dry = perm_daily_run($pdo, '2026-11-11', true);
eq('dry run counts the dated role reminder', 1, $dry['role_reminders']);
eq('dry run writes nothing', 0, (int) $pdo->query('SELECT COUNT(*) FROM system_notifications')->fetchColumn());
$r = perm_daily_run($pdo, '2026-11-11');
eq('11 Nov: one role reminder, no seat reminders yet', [1, 0, 0], [$r['role_reminders'], $r['seat_reminders'], $r['summaries']]);
eq('holder and the person who gave it are told', [['Your role ends soon'], ['A role ends soon']], [perm_admin_messages($pdo, $dated), perm_admin_messages($pdo, $sa)]);
eq('the next day: nothing new', 0, perm_daily_run($pdo, '2026-11-12')['role_reminders']);

$pdo->exec('DELETE FROM system_notifications');
perm_set_today('2026-12-17');
$r = perm_daily_run($pdo, '2026-12-17');
eq('17 Dec: every live seat is reminded once', 3, $r['seat_reminders']);
eq('17 Dec: one year-end summary', 1, $r['summaries']);
ok('the holder hears about their seat', in_array('Your seat ends soon', perm_admin_messages($pdo, $hod), true));
ok('their pastor (Pastor in charge of Charis) hears once', perm_admin_messages($pdo, $pic) === ['Your seat ends soon', 'Seats ending soon']);
ok('the summary goes to the Resident Pastor and the Super Admin', in_array('Ministry year ends soon', perm_admin_messages($pdo, $rp), true)
    && in_array('Ministry year ends soon', perm_admin_messages($pdo, $sa), true));
ok('…and to nobody else', !in_array('Ministry year ends soon', perm_admin_messages($pdo, $hod), true));
$link = (string) $pdo->query("SELECT link_url FROM system_notifications WHERE title = 'Seats ending soon'")->fetchColumn();
ok('the pastor\'s message links to Renew with the seat', str_starts_with($link, '/modules/departments/renew.php?ids=') && str_contains($link, (string) $hodRecord));
$again = perm_daily_run($pdo, '2026-12-17');
eq('run twice: nothing new', [0, 0, 0], [$again['seat_reminders'], $again['summaries'], $again['messages']]);
$r = perm_daily_run($pdo, '2026-12-29');
eq('29 Dec: the 3-day summary, and the seat whose own end (20 Dec) passed is closed', [1, 1], [$r['summaries'], $r['seats_closed']]);
eq('closed like "remove from roster"', 'Removed', $pdo->query("SELECT roster_status FROM user_departments WHERE user_id = {$early}")->fetchColumn());
eq('30 Dec: no third summary', 0, perm_daily_run($pdo, '2026-12-30')['summaries']);

perm_set_today('2027-01-01');
$r = perm_daily_run($pdo, '2027-01-01');
eq('1 Jan: the year-end seats stand down', [0, 2], [$r['seats_closed'], $r['seats_lapsed']]);
eq('lapsed seats stay on the roster for a late rollover', ['Active', 0],
    array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($pdo->query("SELECT roster_status, is_active FROM user_departments WHERE user_id = {$hod}")->fetch())));
eq('the dated role ended and was removed', 0, (int) $pdo->query("SELECT COUNT(*) FROM user_roles WHERE user_id = {$dated}")->fetchColumn());
eq('the Super Admin never ends', 1, (int) $pdo->query("SELECT COUNT(*) FROM user_roles WHERE user_id = {$sa}")->fetchColumn());
eq('history: seat.expire (4 seats) and role.expire', [4, 1], [perm_admin_history($pdo, 'seat.expire'), perm_admin_history($pdo, 'role.expire')]);
eq('the next day: nothing left to close', [0, 0, 0], array_values(array_intersect_key(perm_daily_run($pdo, '2027-01-02'),
    ['seats_closed' => 1, 'seats_lapsed' => 1, 'roles_ended' => 1])));
$pdo->exec("UPDATE perm_config SET cfg_value = '0' WHERE cfg_key = 'expiry_enforced'");
perm_reset();
ok('with end dates off the run does nothing', perm_daily_run($pdo, '2027-01-02')['skipped'] !== null);
perm_set_today('2026-11-11');

// ---------------------------------------------------------------------------
section('PR 3: who is told (notification audiences) and Departments seats');
$pdo = perm_admin_db();
$sa = perm_admin_person($pdo, 'Admin');
perm_test_role($pdo, $sa, 'Super_Admin');
$rp = perm_admin_person($pdo, 'Pastor', 'Pastor');
perm_test_role($pdo, $rp, 'Resident_Pastor');
$ap = perm_admin_person($pdo, 'Assoc', 'Pastor');
perm_test_role($pdo, $ap, 'Assoc_Pastor');
$charisHod = perm_admin_person($pdo, 'Chika');
perm_test_seat($pdo, $charisHod, 10, 'HOD');
$charisWorker = perm_admin_person($pdo, 'Wole');
perm_test_seat($pdo, $charisWorker, 10, 'Worker');
$deputy = perm_admin_person($pdo, 'Deji');
perm_test_seat($pdo, $deputy, 10, 'Deputy_HOD');
$embraceDir = perm_admin_person($pdo, 'Efe');
perm_test_seat($pdo, $embraceDir, 2, 'Director');
eq('announcements approvers: the pastors\' roles and the Super Admin, not every Director/HOD seat',
    [$sa, $rp, $ap], perm_office_holders($pdo, 'announcements', 'approve'));
eq('finance: the Resident Pastor and the Super Admin (as before)', [$sa, $rp], perm_office_holders($pdo, 'finance', 'edit'));
eq('…without break-glass: the Resident Pastor only', [$rp], perm_office_holders($pdo, 'finance', 'edit', false));
eq('Charis leaders: HOD and above in the departments that own Charis', [$charisHod], perm_module_leaders($pdo, 'charis', 'hod'));
eq('every leadership seat without a floor', [$charisHod, $deputy], perm_module_leaders($pdo, 'charis'));
eq('a department\'s leaders', [$embraceDir], perm_department_leaders($pdo, 2));
eq('the senior pastor: the highest appoint rank', [$rp], perm_senior_pastor_ids($pdo));
$pdo->exec("UPDATE users SET account_status = 'suspended' WHERE id = {$charisHod}");
eq('a suspended account is not told', [], perm_module_leaders($pdo, 'charis', 'hod'));
$pdo->exec("INSERT INTO perm_module_owners (module_key, department_id) VALUES ('zoe', 7)");
$pdo->exec(perm_test_migration('20261201090100_perm_zoe_owner.sql'));
eq('the Zoe owner migration is idempotent', 1, (int) $pdo->query("SELECT COUNT(*) FROM perm_module_owners WHERE module_key = 'zoe' AND department_id = 7")->fetchColumn());

require_once __DIR__ . '/../../includes/department_helpers.php';
perm_reset();
eq('Departments: the leadership seats by stable key', ['Assoc_Pastor', 'Director', 'HOD'], dept_leader_roles());
ok('Departments: Add person offers Deputy HOD, never a leadership seat', isset(dept_member_seats()['Deputy_HOD'])
    && !isset(dept_member_seats()['HOD']) && dept_member_seats()['Worker'] === 'Worker');
$pdo->exec("UPDATE perm_seat_types SET label = 'Unit Head' WHERE seat_key = 'hod'");
perm_reset();
eq('renaming a seat type renames it in Departments', 'Unit Head', dept_role_labels()['HOD']);
[$order, $values] = dept_seat_order('ud.role_in_dept');
eq('roster order follows seat rank', ['Assoc_Pastor', 'Director', 'HOD', 'Deputy_HOD', 'Sub_Unit_Head', 'Worker', 'Member'], $values);
