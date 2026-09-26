<?php
// /cron/reach_lost_souls.php
// Daily: finds Reach leads assigned longer than the overdue window with no
// follow-up and sends every Reach department member one in-app digest, so
// someone claims or reassigns them. Each lead is re-alerted at most once
// per overdue window (tracked in reach_leads.lost_alert_at).
// Crontab: 0 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/reach_lost_souls.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/reach_helpers.php';

$days = reach_overdue_days($pdo);
$stmt = $pdo->prepare("
    SELECT l.id, TRIM(CONCAT_WS(' ', l.first_name, l.last_name)) AS name
      FROM reach_leads l
     WHERE " . reach_overdue_sql($days) . "
       AND (l.lost_alert_at IS NULL OR l.lost_alert_at < NOW() - INTERVAL {$days} DAY)
     ORDER BY l.assigned_at
");
$stmt->execute();
$lost = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$lost) {
    echo "[reach-lost-souls] nothing overdue\n";
    exit(0);
}

$names = array_column($lost, 'name');
$list  = implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' and ' . (count($names) - 5) . ' more' : '');
$members = array_column(reach_members($pdo), 'id');
reach_notify($pdo, $members, 'Souls waiting for follow-up',
    count($lost) . " lead(s) have waited over {$days} days without a follow-up: {$list}. Please follow up, or claim or reassign them in Reach.");

$ids = array_map('intval', array_column($lost, 'id'));
$pdo->exec("UPDATE reach_leads SET lost_alert_at = NOW() WHERE id IN (" . implode(',', $ids) . ")");
echo "[reach-lost-souls] alerted " . count($members) . " member(s) about " . count($lost) . " lead(s)\n";
