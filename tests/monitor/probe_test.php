<?php
// /tests/monitor/probe_test.php — the outside probe's signed reports.

$now = monitor_test_clock('2026-11-11 10:00:00');
$secret = str_repeat('a1', 32);
$body = (string) json_encode([
    'v' => 1, 'at' => $now - 20, 'run_id' => '12345-1', 'main_sha' => '3333333cccccccccccccccccccccccccccccccc', 'every_min' => 60,
    'targets' => [
        'site'        => ['ok' => true, 'status' => 200, 'ttfb_ms' => 410, 'total_ms' => 900, 'host' => 'hodlekki.org'],
        'connect'     => ['ok' => true, 'status' => 200, 'ttfb_ms' => 380, 'total_ms' => 700, 'host' => 'connect.hodlekki.org'],
        'tls'         => ['ok' => true, 'edge_days' => 71, 'origin_days' => 5420],
        'dns'         => ['ok' => true, 'a' => true, 'mx' => true, 'spf' => true, 'dkim' => true, 'dmarc' => true],
        'origin_lock' => ['tested' => true, 'ok' => true, 'status' => 403],
        'nonsense'    => ['ok' => true],
    ],
]);
$ts = (string) ($now - 5);
$sig = monitor_probe_signature($secret, $ts, $body);

eq('a genuine report passes', null, monitor_probe_verify($secret, $ts, $sig, $body, $now));
eq('no secret on the server: not set up', 503, monitor_probe_verify('', $ts, $sig, $body, $now)[0] ?? null);
eq('a wrong signature is refused', 401, monitor_probe_verify($secret, $ts, 'sha256=' . str_repeat('0', 64), $body, $now)[0] ?? null);
eq('a changed body is refused', 401, monitor_probe_verify($secret, $ts, $sig, str_replace('true', 'false', $body), $now)[0] ?? null);
eq('a report over 10 minutes old is refused', 401, monitor_probe_verify($secret, (string) ($now - 700), monitor_probe_signature($secret, (string) ($now - 700), $body), $body, $now)[0] ?? null);

$db = monitor_test_db();
ok('a signature is accepted once', monitor_probe_fresh($db, $sig));
ok('…and refused the second time (a replay)', !monitor_probe_fresh($db, $sig));

eq('the five known targets are stored, unknown ones ignored', 5, monitor_probe_store($db, json_decode($body, true), $now));
$tls = $db->query("SELECT detail FROM monitor_probes WHERE target = 'tls'")->fetchColumn();
eq('certificate days are kept in the detail', ['edge_days' => 71, 'origin_days' => 5420], json_decode((string) $tls, true));
$meta = json_decode((string) monitor_state_get($db, 'probe_last'), true);
eq('the run, main\'s commit and the schedule are remembered', ['12345-1', '3333333cccccccccccccccccccccccccccccccc', 60], [$meta['run_id'], $meta['main_sha'], $meta['every_min']]);

$facts = monitor_facts_outside($db);
$states = array_column(monitor_check_outside($facts['latest'], $facts['uptime'], $now, 60, true), 'state', 'key');
eq('a clean report makes every outside check green',
    ['probe' => 'ok', 'outside_site' => 'ok', 'outside_connect' => 'ok', 'tls' => 'ok', 'dns' => 'ok', 'origin_lock' => 'ok'], $states);

// When GitHub's schedule skips the probe, the server asks GitHub for a run.
$calls = [];
$github = function (int $status) use (&$calls): callable {
    return function (string $url, array $headers, string $body) use (&$calls, $status): int {
        $calls[] = [$url, $headers, $body];
        return $status;
    };
};
$kdb = monitor_test_db();
eq('no token in .env: nothing is asked', null, monitor_probe_kick($kdb, $now, '', 'owner/repo', $github(204)));
eq('a malformed repository name: nothing is asked', null, monitor_probe_kick($kdb, $now, 'tok', 'owner/repo/../x', $github(204)));
eq('no report yet: it asks at once', 'asked GitHub to run the outside probe', monitor_probe_kick($kdb, $now, 'tok', 'owner/repo', $github(204)));
ok('…for the probe workflow, on main, with the token',
    count($calls) === 1
    && $calls[0][0] === 'https://api.github.com/repos/owner/repo/actions/workflows/monitor-probe.yml/dispatches'
    && $calls[0][2] === '{"ref":"main"}' && in_array('Authorization: Bearer tok', $calls[0][1], true));
eq('…and not again within 55 minutes', null, monitor_probe_kick($kdb, $now + 600, 'tok', 'owner/repo', $github(204)));
monitor_state_set($kdb, 'probe_last', (string) json_encode(['at' => $now + 1000]));
eq('a report an hour old: still on time, nothing asked', null, monitor_probe_kick($kdb, $now + 1000 + 3600, 'tok', 'owner/repo', $github(204)));
eq('no report for 72 minutes: it asks again', 'asked GitHub to run the outside probe',
    monitor_probe_kick($kdb, $now + 1000 + 72 * 60, 'tok', 'owner/repo', $github(204)));
eq('two asks in all', 2, count($calls));
$kdb2 = monitor_test_db();
eq('GitHub refuses (an expired token): said, with the status',
    'could not ask GitHub to run the outside probe (HTTP 401)', monitor_probe_kick($kdb2, $now, 'old', 'owner/repo', $github(401)));
eq('…and a failed ask also waits 55 minutes', null, monitor_probe_kick($kdb2, $now + 60, 'old', 'owner/repo', $github(401)));
