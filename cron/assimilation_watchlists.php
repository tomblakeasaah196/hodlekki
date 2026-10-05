<?php
// /cron/assimilation_watchlists.php
// Daily: re-runs every active Assimilation watchlist, records who now falls
// into it, and sends ONE in-app digest per watchlist naming only the people
// who are newly drifted. Managed lists notify the managers; open lists
// notify the managers AND every team volunteer, because open lists also push
// each new drift-in straight into the unclaimed pool on the volunteer page.
// Also runs the returned-home sweep so a Sunday check-in is noticed even if
// nobody opens the module.
//
// Nobody is announced twice: assimilation_watchlist_hits.first_seen_at is set
// the first time a person appears in a watchlist and announced_at the first
// time they are named in a digest. Running this file twice in a day is safe
// and produces nothing the second time. Someone who starts attending again
// drops out of the list, so if they drift a second time they are announced
// again — which is what we want.
//
// Crontab: 15 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/assimilation_watchlists.php >/dev/null 2>&1

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/assimilation_helpers.php';

$managers = assim_manager_ids($pdo);

// ---------------------------------------------------------------------------
// 1. Anyone who has come home since we last looked
// ---------------------------------------------------------------------------
try {
    $back = assim_detect_returned_home($pdo);
    assim_announce_returned_home($pdo, $back);
    echo '[assimilation] returned home: ' . count($back) . "\n";
} catch (PDOException $e) {
    error_log('Assimilation cron returned-home sweep: ' . $e->getMessage());
    echo "[assimilation] returned-home sweep failed (logged)\n";
}

// ---------------------------------------------------------------------------
// 2. Each active watchlist
// ---------------------------------------------------------------------------
// is_open arrives with 20261005120000; the tree ships before migrations run,
// so fall back to a 0 column rather than die on an unknown column.
$has_open = assim_has_open_watchlists($pdo);

$watchlists = $pdo->query(
    "SELECT id, name, rule_json, notify, " . ($has_open ? "is_open" : "0") . " AS is_open
       FROM assimilation_watchlists WHERE is_active = 1 ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$watchlists) {
    echo "[assimilation] no active watchlists\n";
    exit(0);
}

$selectHit = $pdo->prepare("
    SELECT h.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name
      FROM assimilation_watchlist_hits h JOIN users u ON u.id = h.user_id
     WHERE h.watchlist_id = ? AND h.announced_at IS NULL
     ORDER BY u.first_name, u.last_name
");
$insertHit = $pdo->prepare("
    INSERT INTO assimilation_watchlist_hits (watchlist_id, user_id) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)
");
$deleteHit = $pdo->prepare("DELETE FROM assimilation_watchlist_hits WHERE watchlist_id = ? AND user_id = ?");
$markHit   = $pdo->prepare("UPDATE assimilation_watchlist_hits SET announced_at = NOW() WHERE watchlist_id = ? AND user_id = ?");

foreach ($watchlists as $w) {
    $id   = (int) $w['id'];
    $rule = assim_normalize_rule($w['rule_json']);

    try {
        $matching = assim_rule_user_ids($pdo, $rule);
    } catch (PDOException $e) {
        error_log("Assimilation cron watchlist {$id}: " . $e->getMessage());
        echo "[assimilation] {$w['name']}: query failed (logged)\n";
        continue;
    }

    $existing = $pdo->prepare("SELECT user_id FROM assimilation_watchlist_hits WHERE watchlist_id = ?");
    $existing->execute([$id]);
    $before = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));

    foreach (array_diff($matching, $before) as $uid) {
        $insertHit->execute([$id, $uid]);
    }
    // Back in the house often enough — stop watching them.
    foreach (array_diff($before, $matching) as $uid) {
        $deleteHit->execute([$id, $uid]);
    }

    $is_open = (int) ($w['is_open'] ?? 0) === 1;

    // Only the ones nobody has been told about yet.
    $selectHit->execute([$id]);
    $fresh = $selectHit->fetchAll(PDO::FETCH_ASSOC);
    if (!$fresh) {
        echo "[assimilation] {$w['name']}: " . count($matching) . " in list, nothing new\n";
        continue;
    }

    // Open lists push every new drift-in straight to the volunteer pool so
    // someone can pick them up before the day is out.
    $pushed = 0;
    if ($is_open) {
        $pushed = assim_auto_pool_open($pdo, $id, array_column($fresh, 'user_id'), 0);
    }

    if ((int) $w['notify'] === 1) {
        $names = array_column($fresh, 'name');
        $list  = implode(', ', array_slice($names, 0, 5))
            . (count($names) > 5 ? ' and ' . (count($names) - 5) . ' more' : '');
        $count = count($names);
        $title = $count === 1 ? '1 person newly drifted' : "{$count} people newly drifted";
        if ($is_open) {
            $aud = assim_watchlist_audience($pdo, true);
            assim_notify($pdo, $aud['managers'] ?: $managers, $title,
                "\"{$w['name']}\" — " . assim_rule_summary($rule) . ": {$list}. "
                . ($pushed > 0 ? "{$pushed} pushed to the volunteers' pool. " : '')
                . 'Follow the pickups from the module.');
            if ($aud['volunteers']) {
                assim_notify($pdo, $aud['volunteers'], $title,
                    "\"{$w['name']}\": {$list} just landed on your volunteer page — pick someone and call with love.",
                    '/assimilation.php');
            }
        } else {
            assim_notify($pdo, $managers, $title,
                "\"{$w['name']}\" — " . assim_rule_summary($rule) . ": {$list}. "
                . 'Open Assimilation to assign someone to reach out to them.');
        }
    }

    foreach ($fresh as $hit) {
        $markHit->execute([$id, (int) $hit['user_id']]);
    }
    echo "[assimilation] {$w['name']}: " . count($matching) . ' in list, ' . count($fresh)
        . ' newly announced'
        . ($is_open ? ", {$pushed} pushed to the pool" : '')
        . ((int) $w['notify'] === 1 ? ' and told to the team' : ' (digest off)') . "\n";
}

echo "[assimilation] done\n";
