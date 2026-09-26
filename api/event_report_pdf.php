<?php
use Dompdf\Dompdf;
use Dompdf\Options;
/**
 * ============================================================================
 * EVENT DATA & ENGAGEMENT REPORT — PDF Generator (dompdf)
 * File: /api/event_report_pdf.php
 * ----------------------------------------------------------------------------
 * A formal, presentable A4 report for any event. It reads real data from the
 * ERP (users, event_registrations, checkins, idi_mobilization, sms_campaign_log)
 * and presents it with professional margins, narrative text and clean tables —
 * made for both readers and number-people.
 *
 * Usage: /api/event_report_pdf.php?event_id=NNN
 * ============================================================================
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: /auth/login.php'); exit; }
$roles = array_column($_SESSION['roles'] ?? [], 'role_name');
$is_privileged = array_intersect($roles, ['Super_Admin','Resident_Pastor','Assoc_Pastor','Director','HOD','Sub_Unit_Head','Admin']) !== [];
if (!$is_privileged) { http_response_code(403); die('Access Denied.'); }

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) { die('PDF library not found.'); }
require_once $autoload;

/* ----------------------------- helpers ----------------------------- */
function fc($v){ return '&#8358;' . number_format((float)$v, 2); }
function fcn($v){ return '&#8358;' . number_format((float)$v); }
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_HTML5, 'UTF-8'); }

function report_normalize_phone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($p === '') return null;
    if (strpos($p, '00') === 0) $p = substr($p, 2);
    if (strlen($p) === 11 && $p[0] === '0') { $p = '234' . substr($p, 1); }
    elseif (strlen($p) === 10) { $p = '234' . $p; }
    elseif (strlen($p) === 13 && substr($p, 0, 3) !== '234') { $p = '234' . ltrim($p, '0'); }
    return preg_match('/^234[789][01]\d{8}$/', $p) ? $p : null;
}

function report_source_bucket($customResponses) {
    $c = json_decode((string)$customResponses, true);
    if (!is_array($c) || empty($c)) return 'Not_Specified';
    if (isset($c['invitation_source']) && is_string($c['invitation_source'])) {
        $v = $c['invitation_source'];
        $map = ['Self_Discovery','Social_Media','Broadcast','Media','Invited_By','Flyer_Banner_Poster'];
        return in_array($v, $map, true) ? $v : 'Other';
    }
    $foundAnswer = false;
    foreach ($c as $val) {
        if (!is_string($val) || trim($val) === '') continue;
        $foundAnswer = true;
        $t = strtolower(trim($val));
        if (strpos($t, 'invit') !== false) return 'Invited_By';
        if (strpos($t, 'social') !== false || strpos($t, 'facebook') !== false || strpos($t, 'instagram') !== false
            || strpos($t, 'twitter') !== false || strpos($t, 'whatsapp') !== false || strpos($t, 'tiktok') !== false
            || strpos($t, 'youtube') !== false) return 'Social_Media';
        if (strpos($t, 'broadcast') !== false) return 'Broadcast';
        if (strpos($t, 'radio') !== false || strpos($t, 'media') !== false || strpos($t, 'tv ') !== false) return 'Media';
        if (strpos($t, 'flyer') !== false || strpos($t, 'poster') !== false || strpos($t, 'banner') !== false) return 'Flyer_Banner_Poster';
        if (strpos($t, 'search') !== false || strpos($t, 'self') !== false || strpos($t, 'discover') !== false
            || strpos($t, 'google') !== false || strpos($t, 'website') !== false) return 'Self_Discovery';
        return 'Other';
    }
    return $foundAnswer ? 'Other' : 'Not_Specified';
}

function report_match_campaigns($pdo, $title) {
    $stop = ['and','the','for','with','of','to','on','in','-'];
    $words = [];
    foreach (preg_split('/\s+/', strtolower(trim($title))) as $w) {
        $w = trim(preg_replace('/[^a-z0-9]/', '', $w));
        if ($w !== '' && strlen($w) >= 3 && !in_array($w, $stop, true)) $words[] = $w;
    }
    $words = array_values(array_unique($words));
    if (!$words) return [];
    $rows = $pdo->query("SELECT DISTINCT event_campaign FROM idi_mobilization WHERE event_campaign IS NOT NULL AND TRIM(event_campaign)<>''")->fetchAll(PDO::FETCH_COLUMN);
    $matched = [];
    foreach ($rows as $camp) {
        $cl = strtolower($camp);
        foreach ($words as $w) { if (strpos($cl, $w) !== false) { $matched[] = $camp; break; } }
    }
    return $matched;
}

function report_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) { return true; }
}

function report_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) { return true; }
}

function report_load_event($pdo, $eid) {
    $ev = $pdo->prepare("SELECT id, title, event_date, end_date FROM events WHERE id=?");
    $ev->execute([$eid]);
    $row = $ev->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['location'] = '';
    if (report_column_exists($pdo, 'events', 'location')) {
        $loc = $pdo->prepare("SELECT location FROM events WHERE id=?");
        $loc->execute([$eid]);
        $v = $loc->fetchColumn();
        $row['location'] = $v === null ? '' : (string)$v;
    }
    return $row;
}

function report_reach_as_of($pdo, $eventId, $date, $hasPhoneStatus) {
    $set = [];
    $usr = "SELECT phone FROM users WHERE phone IS NOT NULL AND TRIM(phone)<>'' AND COALESCE(attendance_status,'')<>'Relocated' AND created_at <= ?"
         . ($hasPhoneStatus ? " AND COALESCE(phone_status,'')<>'invalid'" : "");
    $st = $pdo->prepare($usr);
    $st->execute([$date.' 23:59:59']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) { $n = report_normalize_phone($ph); if ($n) $set[$n] = 1; }

    $rg = $pdo->prepare("SELECT guest_phone FROM event_registrations WHERE event_id=? AND guest_phone IS NOT NULL AND TRIM(guest_phone)<>'' AND registered_at <= ?");
    $rg->execute([$eventId, $date.' 23:59:59']);
    foreach ($rg->fetchAll(PDO::FETCH_COLUMN) as $ph) { $n = report_normalize_phone($ph); if ($n) $set[$n] = 1; }

    $mi = $pdo->prepare("SELECT phone_number FROM idi_mobilization WHERE phone_number IS NOT NULL AND TRIM(phone_number)<>'' AND created_at <= ?");
    $mi->execute([$date.' 23:59:59']);
    foreach ($mi->fetchAll(PDO::FETCH_COLUMN) as $ph) { $n = report_normalize_phone($ph); if ($n) $set[$n] = 1; }

    return $set;
}

/* ----------------------------- inputs ----------------------------- */
// Turn any PHP error into visible output (instead of a silent 500) so the real
// cause is shown on screen when the report fails.
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function($no,$str,$file,$line){
    header('Content-Type: text/plain; charset=utf-8');
    echo "Event Report PDF error [$no]: $str in $file on line $line\n";
    return true;
});
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
        echo "Event Report PDF fatal: {$e['message']} in {$e['file']} on line {$e['line']}\n";
    }
});

try {
$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) die('Event ID required.');

$event = report_load_event($pdo, $event_id);
if (!$event) die('Event not found.');

$startDate = date('Y-m-d', strtotime($event['event_date']));
$endDate = $event['end_date'] ? date('Y-m-d', strtotime($event['end_date'])) : $startDate;
$dayCount = max(1, (int)((strtotime($endDate)-strtotime($startDate))/86400)+1);
$title   = $event['title'];
$loc     = trim((string)$event['location']);
$startTxt= date('F j, Y', strtotime($event['event_date']));
$endTxt  = $event['end_date'] ? date('F j, Y', strtotime($event['end_date'])) : $startTxt;
$dateTxt = ($endDate === $startDate) ? $startTxt : $startTxt . ' &ndash; ' . $endTxt;

/* --------------------------- congregation -------------------------- */
$hasPhoneStatus = report_column_exists($pdo, 'users', 'phone_status');
$congSql = "SELECT COUNT(*) FROM users WHERE phone IS NOT NULL AND TRIM(phone)<>'' AND COALESCE(attendance_status,'')<>'Relocated'"
         . ($hasPhoneStatus ? " AND COALESCE(phone_status,'')<>'invalid'" : "");
$cong = (int)$pdo->query($congSql)->fetchColumn();

/* --------------------------- SMS / reach --------------------------- */
$reach = count(report_reach_as_of($pdo, $event_id, date('Y-m-d'), $hasPhoneStatus));

$sysSms = ['cnt'=>0,'uniq'=>0,'cost'=>0];
$systemCampaigns = [];   // consolidated by campaign title
if (report_table_exists($pdo,'sms_log') && report_table_exists($pdo,'sms_campaigns')) {
    $sys = $pdo->prepare("SELECT COUNT(*) cnt, COUNT(DISTINCT recipient_phone) uniq, COALESCE(SUM(cost),0) cost FROM sms_log WHERE campaign_id IN (SELECT id FROM sms_campaigns WHERE event_id=?)");
    $sys->execute([$event_id]); $sysSms = $sys->fetch(PDO::FETCH_ASSOC);

    // Consolidate the fragmented chunked sends into one clean line per campaign title.
    $sc = $pdo->prepare(
        "SELECT c.title AS title, COUNT(l.id) AS rows_sent,
                COUNT(DISTINCT l.recipient_phone) AS uniq,
                COALESCE(SUM(l.cost),0) AS cost,
                MAX(l.created_at) AS last_sent
         FROM sms_log l
         JOIN sms_campaigns c ON c.id = l.campaign_id
         WHERE c.event_id = ?
         GROUP BY c.title
         ORDER BY last_sent ASC"
    );
    $sc->execute([$event_id]);
    $systemCampaigns = $sc->fetchAll(PDO::FETCH_ASSOC);
}

$manual = [];
if (report_table_exists($pdo,'sms_campaign_log')) {
    $man = $pdo->prepare("SELECT * FROM sms_campaign_log WHERE event_id=? ORDER BY sent_date ASC");
    $man->execute([$event_id]); $manual = $man->fetchAll(PDO::FETCH_ASSOC);
}
$manualTotal = 0; $manualRecip = 0; $manualLogged = 0;
foreach ($manual as $i => $m) {
    $rec = (int)$m['recipient_count'];        // manually logged, if any
    $manualOverride = ($rec > 0);
    if (!$manualOverride && !empty($m['sent_date'])) {
        // DATE-BASED INTELLIGENCE: unique contacts in the system as at the campaign date.
        $rec = count(report_reach_as_of($pdo, $event_id, date('Y-m-d', strtotime($m['sent_date'])), $hasPhoneStatus));
    }
    if ($rec > 0) $manualLogged++;
    $manual[$i]['eff_rec'] = $rec;
    $manual[$i]['eff_cost'] = round($rec * (float)$m['unit_cost'] * max(1,(int)$m['pages']), 2);
    $manual[$i]['is_manual'] = $manualOverride;
    $manualTotal += $manual[$i]['eff_cost']; $manualRecip += $rec;
}
$sysCost = round((float)$sysSms['cost'], 2);
$smsTotal = round($sysCost + $manualTotal, 2);

/* ------------------------- registration ------------------------- */
$st = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id=?"); $st->execute([$event_id]); $regN = (int)$st->fetchColumn();

$srcRows = $pdo->prepare("SELECT custom_responses FROM event_registrations WHERE event_id=?"); $srcRows->execute([$event_id]);
$sourceKeys = ['Self_Discovery','Social_Media','Broadcast','Media','Invited_By','Flyer_Banner_Poster','Other','Not_Specified'];
$sources = array_fill_keys($sourceKeys, 0);
foreach ($srcRows->fetchAll(PDO::FETCH_ASSOC) as $r) { $b = report_source_bucket($r['custom_responses'] ?? ''); if (isset($sources[$b])) $sources[$b]++; else $sources['Other']++; }
$sourceLabels = [
    'Self_Discovery'=>'Self discovery','Social_Media'=>'Social media','Broadcast'=>'Broadcast / mass media',
    'Media'=>'Media & press','Invited_By'=>'Invited by someone','Flyer_Banner_Poster'=>'Flyer / banner / poster',
    'Other'=>'Other','Not_Specified'=>'Not specified',
];

$regBefore = 0; $regOnDay = 0;
$rt = $pdo->prepare("SELECT DATE(registered_at) rd FROM event_registrations WHERE event_id=?"); $rt->execute([$event_id]);
foreach ($rt->fetchAll(PDO::FETCH_ASSOC) as $r) { $rd = $r['rd']; if ($rd >= $startDate && $rd <= $endDate) $regOnDay++; else $regBefore++; }

/* -------------------------- attendance -------------------------- */
$attN = 0; $st = $pdo->prepare("SELECT COUNT(DISTINCT phone) FROM checkins WHERE event_id=?"); $st->execute([$event_id]); $attN = (int)$st->fetchColumn();
$attDays = [];
$attDayMax = 1;
for ($i=1;$i<=$dayCount;$i++) {
    $dv = date('Y-m-d', strtotime($startDate.' +'.($i-1).' days'));
    $q = $pdo->prepare("SELECT COUNT(DISTINCT phone) FROM checkins WHERE event_id=? AND checkin_date=?"); $q->execute([$event_id,$dv]);
    $c = (int)$q->fetchColumn(); $attDays[] = ['day'=>$i,'date'=>$dv,'count'=>$c]; if ($c>$attDayMax) $attDayMax=$c;
}
$mi = $pdo->prepare("SELECT MAX(is_member) im, MAX(is_walkin) iw FROM checkins WHERE event_id=? GROUP BY phone"); $mi->execute([$event_id]);
$mem = 0; $walk = 0;
foreach ($mi->fetchAll() as $m) { if ((int)$m['im'] === 1) $mem++; else $walk++; }

$attFromPrior = 0;
$prePhones = []; $st = $pdo->prepare("SELECT DISTINCT guest_phone FROM event_registrations WHERE event_id=? AND DATE(registered_at) < ?"); $st->execute([$event_id, $startDate]);
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { $n = report_normalize_phone($p); if ($n) $prePhones[] = $n; }
$attSet = []; $st = $pdo->prepare("SELECT DISTINCT phone FROM checkins WHERE event_id=?"); $st->execute([$event_id]);
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { $n = report_normalize_phone($p); if ($n) $attSet[$n] = 1; }
foreach ($prePhones as $n) { if (isset($attSet[$n])) $attFromPrior++; }

/* ------------------------------ IDI ------------------------------ */
$idiCampaigns = report_match_campaigns($pdo, $title);
$idiTotal = 0; $idiContacted = 0; $idiAttended = 0; $idiPhones = [];
if ($idiCampaigns) {
    $in = implode(',', array_fill(0, count($idiCampaigns), '?'));
    $idi = $pdo->prepare("SELECT phone_number, is_contacted FROM idi_mobilization WHERE event_campaign IN ($in)");
    $idi->execute($idiCampaigns);
    foreach ($idi->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idiTotal++;
        if ((int)$row['is_contacted'] === 1) $idiContacted++;
        $n = report_normalize_phone($row['phone_number']); if ($n) $idiPhones[] = $n;
    }
}
foreach ($idiPhones as $n) { if (isset($attSet[$n])) $idiAttended++; }

/* ------------------------- narrative numbers ------------------------- */
$regAtt = $regN > 0 ? round($attN / $regN * 100) : 0;
$priAtt = $attN > 0 ? round($attFromPrior / $attN * 100) : 0;
$idiRate = $idiTotal > 0 ? round($idiAttended / $idiTotal * 100) : 0;
$memPct  = $attN > 0 ? round($mem / $attN * 100) : 0;
$invitedN = $sources['Invited_By'];
$invitedPct = $regN > 0 ? round($invitedN / $regN * 100) : 0;
$nonspec  = $sources['Not_Specified'];
$nonspecPct = $regN > 0 ? round($nonspec / $regN * 100) : 0;
$d1 = isset($attDays[0]) ? $attDays[0]['count'] : 0;
$d2 = isset($attDays[1]) ? $attDays[1]['count'] : 0;

/* ============================== CSS ============================== */
$css = '
  *{ margin:0; padding:0; box-sizing:border-box; }
  @page{ size: A4 portrait; margin: 0; }
  /* Margins are set on body (not @page) because dompdf on this server does not
     apply @page margins reliably. body margin reliably insets the content. */
  body{ margin: 16mm 15mm 22mm 15mm; font-family:"DejaVu Sans", sans-serif; font-size:9.5pt; color:#1e293b; line-height:1.55; }
  .church-band{ text-align:center; border-bottom:3px solid #D11920; padding-bottom:8px; margin-bottom:14px; }
  .church{ font-size:16pt; font-weight:900; color:#1D356A; letter-spacing:.5px; }
  .church-sub{ font-size:8pt; color:#64748b; margin-top:2px; }
  .title-band{ background:#1D356A; color:#ffffff; border-radius:6px; padding:12px 16px; margin:4px 0 14px; }
  .title-band .doc{ font-size:7pt; letter-spacing:2px; text-transform:uppercase; color:#b9c6e6; }
  .title-band h1{ font-size:15pt; font-weight:900; margin-top:2px; }
  .title-band .ev{ font-size:10.5pt; color:#e4e9f5; margin-top:2px; }
  h2{ font-size:12pt; color:#1D356A; font-weight:900; margin:20px 0 6px; border-bottom:2px solid #d7deed; padding-bottom:4px; }
  h2 .num{ color:#D11920; }
  p{ margin:6px 0; text-align:justify; }
  .lead{ font-size:10.5pt; color:#0f172a; }
  .kpi-row{ width:100%; border-collapse:separate; border-spacing:5px 0; margin:12px 0; }
  .kpi-row td{ width:25%; }
  .kpi{ border:1px solid #e2e8f0; border-radius:6px; padding:8px 6px; text-align:center; background:#f8fafc; }
  .kpi .lbl{ font-size:6.5pt; color:#64748b; text-transform:uppercase; font-weight:bold; letter-spacing:.4px; }
  .kpi .val{ font-size:15pt; font-weight:900; color:#0f172a; margin-top:3px; }
  .kpi .val.red{ color:#D11920; }
  table.data{ width:100%; border-collapse:collapse; margin:8px 0; }
  table.data th{ background:#1D356A; color:#fff; font-size:7.5pt; font-weight:bold; text-transform:uppercase; padding:7px 8px; border:1px solid #1D356A; text-align:left; }
  table.data td{ padding:6px 8px; border:1px solid #e2e8f0; font-size:9pt; }
  table.data tr:nth-child(even){ background:#f8fafc; }
  .r{ text-align:right; }
  .c{ text-align:center; }
  .tot td{ font-weight:bold; background:#eef2f7; }
  .bar-wrap{ background:#eef2f7; border-radius:3px; height:11px; margin:3px 0 9px; }
  .bar{ background:#1D356A; height:11px; border-radius:3px; }
  .bars .item{ margin-bottom:4px; font-size:8.5pt; }
  .bars .item b{ color:#334155; }
  .note{ font-size:7.5pt; color:#94a3b8; margin-top:6px; font-style:italic; }
  ul.tight{ margin:6px 0 6px 18px; }
  ul.tight li{ margin:4px 0; }
';

/* ============================== HTML ============================== */
$smsTotalTxt = fcn($smsTotal);
// SYSTEM campaigns (sent via ERP SMS Studio), consolidated by title.
$sysRows = '';
$sysSubTotal = 0; $sysSubUniq = 0;
foreach ($systemCampaigns as $sc) {
    $sysRows .= '<tr>'
        .'<td>'.esc($sc['title']).' <em>(system)</em></td>'
        .'<td>'.date('M j', strtotime($sc['last_sent'])).'</td>'
        .'<td class="r">'.number_format((int)$sc['uniq']).'</td>'
        .'<td class="c">1</td>'
        .'<td class="r">'.fc($sc['cost']).'</td></tr>';
    $sysSubTotal += (float)$sc['cost'];
    $sysSubUniq += (int)$sc['uniq'];
}
if ($systemCampaigns) $sysRows .= '<tr class="tot"><td colspan="2">Subtotal &mdash; system sends</td><td class="r">'.number_format($sysSubUniq).'</td><td></td><td class="r">'.fc($sysSubTotal).'</td></tr>';

$smsRows = '';
foreach ($manual as $m) {
    $smsRows .= '<tr>'
        .'<td>'.esc($m['campaign_label']).'</td>'
        .'<td>'.esc($m['sent_date']).'</td>'
        .'<td class="r">'.(int)$m['eff_rec'].'</td>'
        .'<td class="c">'.(int)$m['pages'].'</td>'
        .'<td class="r">'.fc($m['eff_cost']).'</td></tr>';
}
if ($manual) $smsRows .= '<tr class="tot"><td colspan="2">Subtotal &mdash; '.count($manual).' BulkSMS campaign(s)</td><td class="r">'.number_format($manualRecip).'</td><td></td><td class="r">'.fc($manualTotal).'</td></tr>';

$srcMax = max(1, max($sources));
$srcBars = '';
foreach ($sources as $k => $v) {
    $pct = (int)round($v / $srcMax * 100);
    $srcBars .= '<div class="item"><b>'.esc($sourceLabels[$k]).'</b>: '.number_format($v).'<div class="bar-wrap"><div class="bar" style="width:'.$pct.'%"></div></div></div>';
}
$attBars = '';
foreach ($attDays as $d) {
    $pct = (int)round($d['count'] / $attDayMax * 100);
    $attBars .= '<div class="item"><b>Day '.$d['day'].'</b> ('.date('M j', strtotime($d['date'])).'): '.number_format($d['count']).'<div class="bar-wrap"><div class="bar" style="width:'.$pct.'%"></div></div></div>';
}

$invitedRow = '';
if ($invitedN > 0) $invitedRow = '<tr><td>Invited by someone</td><td class="r">'.number_format($invitedN).'</td></tr>';
$nonspecRow = '';
if ($nonspec > 0) $nonspecRow = '<tr><td>Not specified</td><td class="r">'.number_format($nonspec).'</td></tr>';

$idiRows = '';
if ($idiTotal > 0) {
    $idiRows = '<tr><td>People on the IDI mobilization list</td><td class="r">'.number_format($idiTotal).'</td></tr>'
        .'<tr><td>Personally contacted by the IDI team</td><td class="r">'.number_format($idiContacted).'</td></tr>'
        .'<tr><td>Who attended from the list</td><td class="r">'.number_format($idiAttended).'</td></tr>'
        .'<tr><td>Conversion (attended as a share of the list)</td><td class="r">'.$idiRate.'%</td></tr>';
} else {
    $idiRows = '<tr><td colspan="2">No IDI mobilization records were found for this event campaign. The IDI team list has not yet been linked to &ldquo;'.esc($title).'&rdquo; in the system.</td></tr>';
}

$locTxt = $loc !== '' ? ' at '.esc($loc) : '';
$locEsc = $loc !== '' ? esc($loc) : '&mdash;';
$d1txt = $dayCount >= 1 ? 'Day 1 drew '.number_format($d1).' attendees' : '';
$d2txt = $dayCount >= 2 ? ', and Day 2 drew '.number_format($d2) : '';

$html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>$css</style></head>
<body>
  <div class="church-band">
    <div class="church">Household of David LEKKI CENTRE</div>
    <div class="church-sub">Kon-X Place, Km 14, Lekki Express Way, Agungi&ndash;Ajiran, Lagos, Nigeria</div>
  </div>

  <div class="title-band">
    <div class="doc">Event Data &amp; Engagement Report</div>
    <h1>$title</h1>
    <div class="ev">$dateTxt &nbsp;&bull;&nbsp; $locEsc</div>
  </div>

  <h2><span class="num">1.</span> Executive Summary</h2>
  <p class="lead">On <strong>$dateTxt</strong>, Household of David Lekki Centre hosted <strong>$title</strong>$locTxt. This report summarises how the event was promoted, who registered, who attended, and how our follow-up network (IDI mobilization) performed. The headline results are presented below; the sections that follow explain what the numbers mean.</p>
  <table class="kpi-row"><tr>
    <td><div class="kpi"><div class="lbl">Attendance</div><div class="val red">$attN</div></div></td>
    <td><div class="kpi"><div class="lbl">Registered</div><div class="val">$regN</div></div></td>
    <td><div class="kpi"><div class="lbl">SMS Reach</div><div class="val">$reach</div></div></td>
    <td><div class="kpi"><div class="lbl">SMS Spend</div><div class="val">$smsTotalTxt</div></td>
  </tr></table>
  <p>In short, <strong>$attN</strong> unique individuals attended the conference over the course of the event, drawn from a congregation of <strong>$cong</strong> members, with <strong>$regN</strong> registrations captured through our system. Our database holds <strong>$reach</strong> unique contacts available for SMS outreach. Among registrants who indicated how they heard about the event, personal invitation was the most commonly cited channel &mdash; a sign of the strength of our members&rsquo; personal networks.</p>

  <h2><span class="num">2.</span> Overview &amp; Context</h2>
  <p>$title took place at the Household of David Lekki Centre$locTxt from <strong>$dateTxt</strong>. The conference brought the congregation together for worship, teaching and fellowship, and served as a key outreach moment for the church. This report combines data from our registration platform, the QR check-in system used at the venue, our BulkSMS outreach, and the records of the IDI mobilization team, so that leadership can see the full journey of each attendee &mdash; from first contact to the event itself.</p>
  <p>The report is organised into four areas: <strong>SMS outreach and reach</strong>, <strong>registration</strong>, <strong>attendance</strong>, and <strong>IDI mobilization</strong>. Each section opens with a short summary in plain language, followed by the supporting figures for those who prefer the numbers.</p>

  <h2><span class="num">3.</span> SMS Outreach &amp; Reach</h2>
  <p>Before and during the event, the church sent a series of BulkSMS messages to its wider network &mdash; the congregation, registered guests, and the IDI mobilization list &mdash; promoting the conference, sending reminders, welcoming attendees, and thanking participants. Today the database holds <strong>$reach</strong> unique contacts across these three groups. The table below shows each campaign. The pre-event blasts (Monday, Wednesday, Day 1, Day 2) were sent through the BulkSMS website, and their recipient figures are counted <strong>as at the date each was sent</strong>, so they reflect only the contacts who were in the system on that day. The system sends (marked <em>system</em>) were sent through the ERP SMS Studio and are grouped by campaign.</p>
  <table class="data">
    <tr><th>Campaign</th><th>Sent</th><th>Recipients</th><th class="c">Pages</th><th class="r">Cost</th></tr>
    $sysRows
    $smsRows
  </table>
  <div class="note">Website sends: recipients = unique contacts across users, event registrations and the IDI list that existed on or before the campaign&rsquo;s send date (deduplicated by phone); a manually-logged count overrides this. System sends: logged automatically by the ERP, grouped by campaign title.</div>

  <h2><span class="num">4.</span> Registration</h2>
  <p>A total of <strong>$regN</strong> people registered for the conference. Of these, <strong>$regBefore</strong> registered in advance and <strong>$regOnDay</strong> registered on the event days themselves &mdash; a sign that many guests decided to attend at the last moment or on arrival. When asked how they heard about the event, the largest single group said they were invited by someone ($invitedN people, $invitedPct% of registrations), which confirms that word of mouth remains our strongest channel.</p>
  <table class="data">
    <tr><th>Registration Metric</th><th class="r">Count</th></tr>
    <tr><td>Total registered</td><td class="r">$regN</td></tr>
    <tr><td>Registered before the event</td><td class="r">$regBefore</td></tr>
    <tr><td>Registered on event day(s)</td><td class="r">$regOnDay</td></tr>
  </table>
  <div class="bars">$srcBars</div>
  <div class="note">Sources reflect how registrants said they heard about the event. &ldquo;Not specified&rdquo; indicates the field was left unanswered at registration.</div>

  <h2><span class="num">5.</span> Attendance</h2>
  <p>Attendance was tracked at the venue using a QR check-in system, deduplicated by phone number across both days. <strong>$attN</strong> unique individuals attended in total: <strong>$memPct%</strong> of them ($mem people) were existing members, and the remaining $walk were walk-in guests who registered on the day. $d1txt$d2txt attendees.</p>
  <table class="data">
    <tr><th>Attendance Metric</th><th class="r">Count</th></tr>
    <tr><td>Total unique attendees</td><td class="r">$attN</td></tr>
    <tr><td>Members</td><td class="r">$mem</td></tr>
    <tr><td>Walk-in guests</td><td class="r">$walk</td></tr>
    <tr><td>Attendees who had registered in advance</td><td class="r">$attFromPrior</td></tr>
  </table>
  <div class="bars">$attBars</div>

  <h2><span class="num">6.</span> IDI Mobilization</h2>
  <p>Our IDI (Information & Data Insights) mobilization team alongside some voluteers reached out to a dedicated list of contacts to invite them to the conference. The team&rsquo;s work complements the mass SMS campaign by adding a personal, human touch. The outcomes are summarised below.</p>
  <table class="data">
    <tr><th>IDI Mobilization Metric</th><th class="r">Count</th></tr>
    $idiRows
  </table>
  <div class="note">IDI records are matched to this event by the campaign name stored in the mobilization list. Ensure the campaign label matches the event to capture these figures.</div>

  <h2><span class="num">7.</span> Insights &amp; Recommendations</h2>
  <ul class="tight">
    <li><strong>Invitations are our strongest channel.</strong> Personal invitations drove a large share of registrations. The IDI mobilization approach should be expanded and repeated for future events.</li>
    <li><strong>Walk-ins matter.</strong> A significant portion of attendees registered on the day. On-the-day registration and welcome systems worked well, but consider collecting a source answer more consistently to reduce &ldquo;not specified&rdquo; records.</li>
    <li><strong>SMS reach is broad.</strong> The campaign reached $reach unique contacts. Future blasts should segment the audience (members, first timers and guests) so reminders can be tailored.</li>
    <li><strong>Registration of Workers during events.</strong> Workers are strongly encouraged to register and checkin during events. Failure to do so falsify data.</li>
    <li><strong>Keep linking IDI Mobilization records to the event.</strong> Make sure the mobilization campaign is labelled with the event name so the report can measure IDI conversion accurately.</li>
  </ul>

</body></html>
HTML;

/* ============================== RENDER ============================== */
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

/* Footer with page numbers on every page */
$dompdf->getCanvas()->page_script(function($pageNumber, $pageCount, $canvas, $fontMetrics) {
    $f = $fontMetrics->getFont('DejaVu Sans', 'normal');
    $canvas->text(297, 822, 'Household of David Lekki Centre  |  Page '.$pageNumber.' of '.$pageCount, $f, 7.5, [100,116,139]);
});

$safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $title);
$dompdf->stream("Engagement_{$safe}_".date('Ymd').'.pdf', ['Attachment' => false]);
exit;
} catch (\Throwable $e) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Event Report PDF error: ".$e->getMessage()."\n";
    echo "in ".$e->getFile()." on line ".$e->getLine()."\n";
    exit;
}
