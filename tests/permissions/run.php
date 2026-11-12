<?php
// /tests/permissions/run.php
//
// The permission engine against SQLite built from the real migrations:
// resolver rules, can() targets, perm_where(), renames, holders, the seed's
// shape, and the day-one parity of a synthetic church (every difference must
// be one the owner and the lead decided).
//
// Run from the repo root:  php tests/permissions/run.php
// Exit code 0 = all good, 1 = at least one failure. CLI only.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

require_once __DIR__ . '/../../includes/permissions/parity.php';
require_once __DIR__ . '/lib.php';

perm_set_strict(true);

// ---------------------------------------------------------------------------
section('Seed shape');
$pdo = perm_test_db();
$modules = perm_modules();
foreach ($pdo->query('SELECT * FROM perm_grants')->fetchAll() as $g) {
    $m = $g['module_key'];
    ok("grant module {$m} is registered", isset($modules[$m]));
    if (!isset($modules[$m])) {
        continue;
    }
    ok("{$m} level within max", (int) $g['level'] <= PERM_LEVELS[$modules[$m]['max']]);
    foreach (['approve' => 'can_approve', 'export' => 'can_export', 'manage' => 'can_manage'] as $x => $col) {
        ok("{$m} offers {$x}", (int) $g[$col] === 0 || in_array($x, $modules[$m]['extras'], true));
    }
    ok("{$m} scope valid", in_array($g['scope'], PERM_SCOPES, true));
}
eq('stable seat keys (mailbox chat relies on them)',
    ['pastor_in_charge' => 'Assoc_Pastor', 'director' => 'Director', 'hod' => 'HOD', 'deputy_hod' => 'Deputy_HOD',
     'sub_unit_head' => 'Sub_Unit_Head', 'worker' => 'Worker', 'member' => 'Member'],
    array_column(perm_seat_types($pdo), 'roster_value', 'seat_key'));
eq('Director / HOD roles carry no cells (decision 2)', 0,
    (int) $pdo->query("SELECT COUNT(*) FROM perm_grants g JOIN roles r ON r.id = g.role_id WHERE r.role_name IN ('Director','HOD')")->fetchColumn());
eq('assets owners: Envision + River of Life (no department is NAMED IDI)', [3, 4], perm_module_owners($pdo)['assets']);
eq('Finance owns disbursement', [14], perm_module_owners($pdo)['requisition.disburse']);
foreach ([PERM_TEST_CORE, PERM_TEST_PLATFORM, PERM_TEST_SEED, PERM_TEST_GRANTS] as $f) {
    ok("{$f} carries the _perm_ marker", str_contains($f, '_perm_'));
}
ok('seed is portable (no ON DUPLICATE / INSERT IGNORE)',
    !preg_match('/ON DUPLICATE|INSERT IGNORE/i', perm_test_migration(PERM_TEST_SEED)));
$cells = (int) $pdo->query('SELECT COUNT(*) FROM perm_grants')->fetchColumn();
$pdo->exec(perm_test_migration(PERM_TEST_SEED));
$pdo->exec(perm_test_migration(PERM_TEST_GRANTS));
eq('seed re-runs as a no-op', $cells, (int) $pdo->query('SELECT COUNT(*) FROM perm_grants')->fetchColumn());

// ---------------------------------------------------------------------------
section('Resolver');
$member = perm_test_person($pdo, 'Plain');
$rp = perm_test_person($pdo, 'Resident');
perm_test_role($pdo, $rp, 'Resident_Pastor');
$sa = perm_test_person($pdo, 'Super');
perm_test_role($pdo, $sa, 'Super_Admin', ['ends_on' => '2020-01-01']);
$frozen = perm_test_person($pdo, 'Frozen');
perm_test_role($pdo, $frozen, 'Resident_Pastor', ['frozen' => 1]);
$later = perm_test_person($pdo, 'Later');
perm_test_role($pdo, $later, 'Resident_Pastor', ['starts_on' => '2027-01-01']);
$ended = perm_test_person($pdo, 'Ended');
perm_test_role($pdo, $ended, 'Resident_Pastor', ['ends_on' => '2026-11-10']);
$reachWorker = perm_test_person($pdo, 'ReachWorker');
perm_test_seat($pdo, $reachWorker, 8, 'Worker');
$reachHod = perm_test_person($pdo, 'ReachHod');
perm_test_seat($pdo, $reachHod, 8, 'HOD');
$sanitation = perm_test_person($pdo, 'Sanitation');
perm_test_seat($pdo, $sanitation, 6, 'Worker');
$removed = perm_test_person($pdo, 'Removed');
perm_test_seat($pdo, $removed, 8, 'HOD', ['roster_status' => 'Removed', 'is_active' => 0]);
$charisDir = perm_test_person($pdo, 'CharisDirector');
perm_test_seat($pdo, $charisDir, 10, 'Director', ['end_date' => '2026-01-31']);

$r = static fn (int $uid): array => perm_resolve($pdo, $uid);
ok('member: own portal', perm_ctx_can($r($member), 'member_portal', 'edit', perm_person($member)));
ok('member: no congregation', !perm_ctx_can($r($member), 'congregation'));
ok('RP: congregation delete church-wide', perm_ctx_can($r($rp), 'congregation', 'delete', 'church'));
ok('RP: roles view (decision 7), not manage', perm_ctx_can($r($rp), 'roles') && !perm_ctx_can($r($rp), 'roles', 'manage'));
ok('break-glass never ends', $r($sa)['breakglass']);
ok('frozen role does not count', !perm_ctx_can($r($frozen), 'congregation'));
ok('role not started yet does not count', !perm_ctx_can($r($later), 'congregation'));
ok('ended role does not count', !perm_ctx_can($r($ended), 'congregation'));
ok('Reach worker: delete + export', perm_ctx_can($r($reachWorker), 'reach', 'delete') && perm_ctx_can($r($reachWorker), 'reach', 'export'));
ok('Reach worker: no manage', !perm_ctx_can($r($reachWorker), 'reach', 'manage'));
ok('Reach HOD: manage', perm_ctx_can($r($reachHod), 'reach', 'manage'));
ok('Reach HOD: congregation (retired HOD role, now on the seat)', perm_ctx_can($r($reachHod), 'congregation', 'delete', 'church'));
ok('Sanitation worker: no Reach', !perm_ctx_can($r($sanitation), 'reach'));
ok('removed roster row gives nothing', !perm_ctx_can($r($removed), 'reach'));
eq('Director covers sub-units', perm_test_scope(false, [10, 15]), perm_ctx_scope($r($charisDir), 'departments', 'edit'));
ok('Director views every department', perm_ctx_can($r($charisDir), 'departments', 'view', 'church'));
ok('seat end date ignored until expiry is enforced', perm_ctx_can($r($charisDir), 'charis', 'manage'));
$pdo->exec("UPDATE perm_config SET cfg_value = '1' WHERE cfg_key = 'expiry_enforced'");
perm_reset();
ok('ended seat stops counting once enforced', !perm_ctx_can($r($charisDir), 'charis'));
$pdo->exec("UPDATE perm_config SET cfg_value = '0' WHERE cfg_key = 'expiry_enforced'");
perm_reset();
$pdo->exec("UPDATE perm_department_seats SET is_enabled = 0 WHERE department_id = 8 AND seat_key = 'worker'");
perm_reset();
ok('a seat type the department does not use gives nothing', !perm_ctx_can($r($reachWorker), 'reach'));
$pdo->exec("UPDATE perm_department_seats SET is_enabled = 1 WHERE department_id = 8 AND seat_key = 'worker'");
perm_reset();

// ---------------------------------------------------------------------------
section('can(), targets, perm_where');
perm_test_as($charisDir);
ok('can: own department', can('departments', 'edit', 10));
ok('can: sub-unit', can('departments', 'edit', 15));
ok('can: not another department', !can('departments', 'edit', 8));
ok('can: person in my department', can('departments', 'edit', perm_person($charisDir)));
ok('can: person elsewhere', !can('departments', 'edit', perm_person($reachWorker)));
$pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, dept INTEGER)');
$pdo->exec('INSERT INTO t (dept) VALUES (8), (10), (15), (6)');
[$w, $p] = perm_where('departments', 'edit', ['dept' => 'dept']);
$st = $pdo->prepare("SELECT COUNT(*) FROM t WHERE {$w}");
$st->execute($p);
eq('perm_where keeps own departments only', 2, (int) $st->fetchColumn());
$threw = false;
try {
    can('no_such_module');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok('unknown module is refused loudly', $threw);
perm_set_strict(false);
perm_test_as($sa);
ok('unknown module fails closed, even for break-glass', !can('no_such_module') && !can('reach', 'no_such_level'));
ok('the old monitor.view spelling is not a module', !can('monitor.view'));
perm_set_strict(true);
ok('Super Admin: monitor view + manage as a plain grant (not break-glass)',
    perm_ctx_can($r($sa), 'monitor', 'view') && perm_ctx_can($r($sa), 'monitor', 'manage'));
ok('Resident Pastor: no monitor', !perm_ctx_can($r($rp), 'monitor'));
if (!function_exists('monitor_user_can')) {
    // The monitor's swap point, exactly as includes/monitor/bootstrap.php has it.
    $src = (string) file_get_contents(__DIR__ . '/../../includes/monitor/bootstrap.php');
    ok('monitor_user_can() splits "monitor.view" into can(module, level)',
        str_contains($src, "explode('.', \$ability, 2)") && str_contains($src, 'can($module, $level)'));
}
perm_test_as($member);
ok('member nav: portal yes', perm_nav_visible('member_portal'));
ok('member nav: announcements no (needs approve)', !perm_nav_visible('announcements'));

// ---------------------------------------------------------------------------
section('Every module key the code asks for is registered');
// A key missing from includes/permissions/modules.php fails closed for
// everyone, Super Admins included — so every call site is checked here.
$root = realpath(__DIR__ . '/../..');
$calls = 0;
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
    if ($file->getExtension() !== 'php' || preg_match('#^/(vendor|tests|node_modules|\.git|\.claude)/#', $path)
        || $path === '/includes/permissions/legacy_rules.php') {
        continue;
    }
    // Real calls only: comments and docblocks are dropped first.
    $src = '';
    foreach (token_get_all((string) file_get_contents($file->getPathname())) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $src .= is_array($tok) ? $tok[1] : $tok;
    }
    $found = [];
    // can('module') / can('module', 'level') / api_require_can(...) / require_can(...) / perm_*('module', 'level')
    if (preg_match_all("/\\b(?:can|require_can|api_require_can|perm_scope|perm_where|perm_nav_visible)\\(\\s*'([a-z_.]+)'(?:\\s*,\\s*'([a-z]+)')?/",
        $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $found[] = [$hit[1], $hit[2] ?? 'view'];
        }
    }
    // monitor_user_can($pdo, 'monitor.view') — split the way bootstrap.php splits it.
    if (preg_match_all("/monitor_user_can\\(\\s*\\\$\\w+\\s*,\\s*'([a-z_]+)\\.([a-z]+)'/", $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $found[] = [$hit[1], $hit[2]];
        }
    }
    foreach ($found as [$module, $level]) {
        $calls++;
        $known = false;
        try {
            $known = perm_known($module, $level === '' ? 'view' : $level);
        } catch (InvalidArgumentException $e) {
            $known = false;
        }
        ok("{$path}: '{$module}' / '{$level}' is registered", $known);
    }
}
ok('the scan found the call sites (monitor API at least)', $calls >= 3, (string) $calls);
require_once $root . '/includes/ui/nav.php';
$navToModule = ['system_health' => 'monitor'];
foreach (ui_nav_registry() as $item) {
    $key = $navToModule[$item['key']] ?? $item['key'];
    ok("nav entry {$item['key']} has a module", isset($modules[$key]));
}

// ---------------------------------------------------------------------------
section('No access decision is hard-coded (the CI sweep)');
require_once __DIR__ . '/hardcoding_scan.php';
$findings = perm_hardcoding_findings($root);
ok('no role name, department number/name, active role or session role list decides access',
    $findings === [], implode('; ', array_slice($findings, 0, 8)));
$allowSrc = (string) file_get_contents(__DIR__ . '/hardcoding_allowlist.txt');
foreach (preg_split('/\R/', $allowSrc) as $line) {
    $line = trim($line);
    if ($line !== '' && $line[0] !== '#') {
        [$p, $why] = array_map('trim', explode('|', $line, 2) + [1 => '']);
        ok("allowlisted {$p} exists and says why", is_file($root . '/' . $p) && $why !== '');
    }
}

// ---------------------------------------------------------------------------
section('Finance and the Alerts Centre check each write');
$finance = (string) file_get_contents($root . '/api/finance_api.php');
ok('finance_api needs the CSRF token on writes', str_contains($finance, 'csrf_require();'));
ok('finance_api asks the level each action needs',
    str_contains($finance, "api_require_can('finance', \$levels[\$action]);")
    && str_contains($finance, "'save_transaction' => 'create'") && str_contains($finance, "'delete_transaction' => 'delete'")
    && str_contains($finance, "'save_account' => 'edit'") && str_contains($finance, "'delete_category' => 'delete'"));
ok('a receipt is stored through the upload guard, images and PDF only',
    str_contains($finance, "hod_upload_store(\$_FILES['receipt_file']") && str_contains($finance, "['jpg', 'jpeg', 'png', 'pdf']")
    && !str_contains($finance, 'move_uploaded_file'));
ok('push_admin_api needs the CSRF token on writes', str_contains((string) file_get_contents($root . '/api/push_admin_api.php'), 'csrf_require();'));

// ---------------------------------------------------------------------------
section('Tribes no longer errors for Directors and HODs');
$tribes = '';   // the code, comments stripped
foreach (token_get_all((string) file_get_contents($root . '/api/tribes_api.php')) as $tok) {
    if (!is_array($tok) || !in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        $tribes .= is_array($tok) ? $tok[1] : $tok;
    }
}
ok('tribes_api reads no departments.director_id / hod_id', !str_contains($tribes, 'director_id') && !str_contains($tribes, 'hod_id'));
ok('tribes_api asks the matrix', str_contains($tribes, "api_require_can('tribes', 'view')")
    && str_contains($tribes, "can('tribes', 'view', 'church')"));
$tDir = perm_test_person($pdo, 'TribesDirector');
perm_test_seat($pdo, $tDir, 13, 'Director');
ok('a Director seat holder may open Tribes (their own tribe)', perm_ctx_can($r($tDir), 'tribes', 'view'));
ok('but has no church-wide Tribes view (the branch that used to error)', !perm_ctx_can($r($tDir), 'tribes', 'view', 'church'));

// ---------------------------------------------------------------------------
section('Appointing: only seats ranked below your own (decision 6)');
$appointHod = perm_test_person($pdo, 'AppointHod');
perm_test_seat($pdo, $appointHod, 10, 'HOD');
$appointDeputy = perm_test_person($pdo, 'AppointDeputy');
perm_test_seat($pdo, $appointDeputy, 10, 'Deputy_HOD');
ok('Resident Pastor (80) appoints a Pastor in charge (60)', perm_ctx_can_appoint($pdo, $r($rp), 10, 'pastor_in_charge'));
ok('an HOD (40) appoints a worker', perm_ctx_can_appoint($pdo, $r($appointHod), 10, 'worker'));
ok('an HOD cannot appoint the Director (50)', !perm_ctx_can_appoint($pdo, $r($appointHod), 10, 'director'));
ok('an HOD cannot appoint in another department', !perm_ctx_can_appoint($pdo, $r($appointHod), 8, 'worker'));
ok('a Deputy HOD appoints nobody', !perm_ctx_can_appoint($pdo, $r($appointDeputy), 10, 'worker'));
ok('a Super Admin appoints anything', perm_ctx_can_appoint($pdo, $r($sa), 10, 'pastor_in_charge'));

// ---------------------------------------------------------------------------
section('The global Director / HOD roles retire (decision 2)');
$retired = perm_test_person($pdo, 'RetiredDirector');
perm_test_role($pdo, $retired, 'Director');
$before = (int) $pdo->query("SELECT COUNT(*) FROM perm_history WHERE change_type = 'user_role.remove'")->fetchColumn();
$pdo->exec(perm_test_migration('20261126090500_perm_retire_global_roles.sql'));
eq('no Director / HOD role rows remain', 0, (int) $pdo->query(
    "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_name IN ('Director', 'HOD')")->fetchColumn());
eq('both roles archived', 2, (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE role_name IN ('Director', 'HOD') AND is_archived = 1")->fetchColumn());
ok('one history row per removed role row', (int) $pdo->query("SELECT COUNT(*) FROM perm_history WHERE change_type = 'user_role.remove'")->fetchColumn() > $before);
$after = (int) $pdo->query('SELECT COUNT(*) FROM perm_history')->fetchColumn();
$pdo->exec(perm_test_migration('20261126090500_perm_retire_global_roles.sql'));
eq('re-running it adds nothing', $after, (int) $pdo->query('SELECT COUNT(*) FROM perm_history')->fetchColumn());
perm_reset();

// ---------------------------------------------------------------------------
section('Renames change nothing');
$before = [$r($rp)['abilities'], $r($reachHod)['abilities'], $r($charisDir)['abilities']];
$pdo->exec("UPDATE roles SET role_name = 'Senior_Pastor', label = 'Senior Pastor' WHERE role_name = 'Resident_Pastor'");
$pdo->exec("UPDATE departments SET name = 'Evangelism Unit' WHERE id = 8");
$pdo->exec("UPDATE departments SET name = 'Welfare Ministry' WHERE id = 10");
perm_reset();
eq('same access after renaming a role and two departments', $before,
    [$r($rp)['abilities'], $r($reachHod)['abilities'], $r($charisDir)['abilities']]);

// ---------------------------------------------------------------------------
section('Announcements and the Pastoral Desk check each write');
$code = static function (string $file) use ($root): string {
    $out = '';   // the code, comments stripped
    foreach (token_get_all((string) file_get_contents($root . $file)) as $tok) {
        if (!is_array($tok) || !in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= is_array($tok) ? $tok[1] : $tok;
        }
    }
    return $out;
};
$ann = $code('/api/announcements_api.php');
ok('announcements_api needs the CSRF token on writes', str_contains($ann, 'csrf_require();'));
ok('a new notice needs Create, deleting needs Delete',
    str_contains($ann, "api_require_can('announcements', 'create');") && str_contains($ann, "api_require_can('announcements', 'delete');"));
ok('a status is Approved or Archived, nothing else', str_contains($ann, "in_array(\$new_status, ['Approved', 'Archived'], true)"));
$pas = $code('/api/pastoral_api.php');
ok('pastoral_api needs the CSRF token and the matrix', str_contains($pas, 'csrf_require();') && str_contains($pas, "api_require_can('pastoral');"));
ok('submitting needs Create, triage needs Edit church-wide',
    str_contains($pas, "api_require_can('pastoral', 'create');") && str_contains($pas, "\$can_triage = can('pastoral', 'edit', 'church');"));
ok('the box, status and priority come only from fixed lists',
    str_contains($pas, 'if (!isset($tabs[$tab]))') && str_contains($pas, "in_array(\$new_status, \$tabs[\$tab]['statuses'], true)")
    && str_contains($pas, 'in_array($priority, $priorities, true)'));
ok('an anonymous entry never sends its author to anyone else',
    str_contains($pas, "if (!empty(\$row['is_anonymous']) && !\$mine)") && str_contains($pas, "\$row['phone'] = null;"));

// ---------------------------------------------------------------------------
section('Holders and history');
eq('seat holders by stable key', [$reachHod], perm_seat_holders($pdo, 'hod', 8));
$holders = perm_holders($pdo, 'reach', 'manage');
ok('reach managers: HOD and pastor', in_array($reachHod, $holders, true) && in_array($rp, $holders, true));
ok('reach managers: not workers, not break-glass', !in_array($reachWorker, $holders, true) && !in_array($sa, $holders, true));
ok('history row written', perm_history_add($pdo, ['via' => 'test', 'change_type' => 'grant.set', 'module_key' => 'reach',
    'before' => ['level' => 3], 'after' => ['level' => 4], 'actor_user_id' => $sa, 'actor_label' => 'Test']));

// ---------------------------------------------------------------------------
section('Day-one parity of a synthetic church');
$pdo = perm_test_db();
$people = [];
$add = static function (string $name, array $roles = [], array $seats = []) use ($pdo, &$people): int {
    $uid = perm_test_person($pdo, $name);
    foreach ($roles as $role => $extra) {
        perm_test_role($pdo, $uid, is_int($role) ? $extra : $role, is_int($role) ? [] : $extra);
    }
    foreach ($seats as [$dept, $value, $extra]) {
        perm_test_seat($pdo, $uid, $dept, $value, $extra ?? []);
    }
    return $people[$name] = $uid;
};
$add('member');
foreach (['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Worker'] as $role) {
    $add("role {$role}", [$role]);
}
$add('pastor in charge', ['Assoc_Pastor', 'Worker'], [[3, 'Assoc_Pastor', null]]);
$add('director', ['Director', 'Worker'], [[11, 'Director', null]]);
$add('finance director', ['Director', 'Worker'], [[14, 'Director', null]]);
foreach ([1, 2, 3, 4, 7, 8, 9, 10, 11, 13, 14, 6] as $dept) {
    $add("worker {$dept}", ['Worker'], [[$dept, 'Worker', null]]);
    $add("hod {$dept}", ['HOD', 'Worker'], [[$dept, 'HOD', null]]);
}
$add('relieved hod', ['HOD', 'Worker'], [[9, 'HOD', ['roster_status' => 'Removed', 'is_active' => 0]]]);
$add('frozen pastor in charge', ['Assoc_Pastor' => ['frozen' => 1]], [[8, 'Assoc_Pastor', null]]);

$decided = ['closed hole', 'lost global role', 'combined roles', 'break-glass', 'lead decision', 'academy roster'];
$run = perm_parity_run($pdo);
foreach ($people as $name => $uid) {
    $cats = [];
    foreach (perm_parity_person($pdo, $uid)['diffs'] as $d) {
        $cats[$d['category']] = true;
    }
    $unexpected = array_diff(array_keys($cats), $decided);
    if ($name === 'frozen pastor in charge') {
        eq('frozen pastor in charge: flagged as an unexpected gain', ['UNEXPECTED gain'], array_values($unexpected));
        continue;
    }
    ok("{$name}: only decided differences", $unexpected === [], implode(', ', $unexpected));
}
ok('relieved HOD is named under decision 2', isset($run['categories']['lost global role'][$people['relieved hod']]));
ok('IDI worker loses only dead links', isset($run['categories']['dead link removed'][$people['worker 1']]));
eq('exit code 1 while one unexpected gain stands', 1, perm_parity_exit_code($run));
$pdo->exec("DELETE FROM user_roles WHERE user_id = {$people['frozen pastor in charge']}");
$pdo->exec("DELETE FROM user_departments WHERE user_id = {$people['frozen pastor in charge']}");
perm_reset();
eq('exit code 0 once only decided changes remain', 0, perm_parity_exit_code(perm_parity_run($pdo)));
$pdo->exec("DELETE FROM perm_grants WHERE module_key = 'congregation' AND role_id = 2");
perm_reset();
eq('exit code 2 on a planted loss', 2, perm_parity_exit_code(perm_parity_run($pdo)));
$report = perm_parity_render($pdo, perm_parity_run($pdo), false);
ok('report fits one screen', substr_count($report, "\n") <= 24, (string) substr_count($report, "\n"));

// PR 3: changing the permission data, view-as, end dates.
require __DIR__ . '/admin_tests.php';

// ---------------------------------------------------------------------------
section('AI in Mail for every role (owner, 12 Nov 2026)');
$aiSql = perm_test_migration('20261206090000_perm_ai_for_every_role.sql');
ok('mail.ai migration is portable (no ON DUPLICATE / INSERT IGNORE)', !preg_match('/ON DUPLICATE|INSERT IGNORE/i', $aiSql));
$pdo->exec($aiSql);
$roles = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
eq('every role holds mail.ai at view, church-wide', $roles,
    (int) $pdo->query("SELECT COUNT(*) FROM perm_grants WHERE seat_key = '' AND module_key = 'mail.ai' AND level = 1 AND scope = 'church'")->fetchColumn());
$pdo->exec($aiSql);
eq('mail.ai migration re-runs as a no-op', $roles,
    (int) $pdo->query("SELECT COUNT(*) FROM perm_grants WHERE module_key = 'mail.ai'")->fetchColumn());

echo "\n{$GLOBALS['perm_test_passed']} passed, {$GLOBALS['perm_test_failed']} failed\n";
exit($GLOBALS['perm_test_failed'] === 0 ? 0 : 1);
