<?php
/**
 * ============================================================================
 * EVENT DATA & ENGAGEMENT REPORT — Backend API
 * File: /api/event_report_api.php
 * ----------------------------------------------------------------------------
 * Gathers all the data needed for the Data & Engagement Report for one event:
 *   - SMS: system sends (sms_log) + manual BulkSMS sends (sms_campaign_log)
 *   - Registration: total, sources, pre-event vs event-day
 *   - Attendance: Day 1 / Day 2, members vs walk-ins, dedup totals
 *   - IDI Mobilization: list size, contacted, who attended from calls
 *   - KPIs across all of the above
 * ============================================================================
 */
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Unauthorized.']); exit; }
$user_id = (int)$_SESSION['user_id'];

$allowed_roles = ['Super_Admin','Resident_Pastor','Assoc_Pastor','Director','HOD','Sub_Unit_Head','Admin'];
$is_auth = false;
if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $r) {
        if (in_array($r['role_name'], $allowed_roles)) { $is_auth = true; break; }
    }
}
if (!$is_auth) { echo json_encode(['status'=>'error','message'=>'Access Denied.']); exit; }

/**
 * Normalize a Nigerian phone to international 234XXXXXXXXXX (same as contact extractor).
 */
function report_normalize_phone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($p === '') return null;
    if (strpos($p, '00') === 0) $p = substr($p, 2);
    if (strlen($p) === 11 && $p[0] === '0') {
        $p = '234' . substr($p, 1);
    } elseif (strlen($p) === 10) {
        $p = '234' . $p;
    } elseif (strlen($p) === 13 && substr($p, 0, 3) !== '234') {
        $p = '234' . ltrim($p, '0');
    }
    return preg_match('/^234[789][01]\d{8}$/', $p) ? $p : null;
}

/**
 * Classify the registration "how did you hear" answer into the report buckets.
 * Online form: JSON text  {"3":"Someone invited me"}
 * Check-in form: JSON enum {"invitation_source":"Invited_By"}
 * Handles BOTH instead of assuming only one key.
 */
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
        if (strpos($t, 'social') !== false || strpos($t, 'facebook') !== false
            || strpos($t, 'instagram') !== false || strpos($t, 'twitter') !== false
            || strpos($t, 'whatsapp') !== false || strpos($t, 'tiktok') !== false
            || strpos($t, 'youtube') !== false) return 'Social_Media';
        if (strpos($t, 'broadcast') !== false) return 'Broadcast';
        if (strpos($t, 'radio') !== false || strpos($t, 'media') !== false || strpos($t, 'tv ') !== false) return 'Media';
        if (strpos($t, 'flyer') !== false || strpos($t, 'poster') !== false || strpos($t, 'banner') !== false) return 'Flyer_Banner_Poster';
        if (strpos($t, 'search') !== false || strpos($t, 'self') !== false
            || strpos($t, 'discover') !== false || strpos($t, 'google') !== false
            || strpos($t, 'website') !== false) return 'Self_Discovery';
        return 'Other';
    }
    return $foundAnswer ? 'Other' : 'Not_Specified';
}

/**
 * Robust IDI campaign matcher: select idi_mobilization campaigns that share at
 * least one significant word with the event title (so it joins whether the
 * stored campaign is "Exousia", "EXOUSIA 2026", or the full title).
 */
function report_match_campaigns($pdo, $title) {
    // Significant words: length >= 3, excluding common stopwords.
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
        foreach ($words as $w) {
            if (strpos($cl, $w) !== false) { $matched[] = $camp; break; }
        }
    }
    return $matched;
}

/**
 * Does a column exist on a table? Used to safely build queries so a missing
 * (migration-not-applied) column can never crash the whole report.
 */
function report_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) {
        return true; // if we can't check, assume present (don't block)
    }
}

/**
 * Does a table exist?
 */
function report_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) {
        return true;
    }
}

/**
 * Safely load an event. Only reads columns we KNOW exist on the events table
 * (id, title, event_date, end_date). The optional `location` column is read
 * only if it exists — some setups don't have it, and a missing column used to
 * 500 the whole report.
 */
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

/**
 * SMS reach "as at" a given date — the heart of the honest SMS reporting.
 * Counts UNIQUE phone numbers across the three sources (users, event
 * registrations for this event, IDI mobilization) whose record EXISTED on or
 * before $date. This means: if we messaged everyone on Monday, we count who was
 * in the system ON Monday — anyone added to users/registrations after Monday is
 * NOT included in Monday's figure. Returns an array of normalized phones.
 */
function report_reach_as_of($pdo, $eventId, $date, $hasPhoneStatus) {
    $set = [];
    // users present as at the date
    $usr = "SELECT phone FROM users WHERE phone IS NOT NULL AND TRIM(phone)<>'' AND COALESCE(attendance_status,'')<>'Relocated' AND created_at <= ?"
         . ($hasPhoneStatus ? " AND COALESCE(phone_status,'')<>'invalid'" : "");
    $st = $pdo->prepare($usr);
    $st->execute([$date.' 23:59:59']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) { $n = report_normalize_phone($ph); if ($n) $set[$n] = 1; }

    // event registrations present as at the date (for this event)
    $rg = $pdo->prepare("SELECT guest_phone FROM event_registrations WHERE event_id=? AND guest_phone IS NOT NULL AND TRIM(guest_phone)<>'' AND registered_at <= ?");
    $rg->execute([$eventId, $date.' 23:59:59']);
    foreach ($rg->fetchAll(PDO::FETCH_COLUMN) as $ph) { $n = report_normalize_phone($ph); if ($n) $set[$n] = 1; }

    // IDI mobilization present as at the date
    $mi = $pdo->prepare("SELECT phone_number FROM idi_mobilization WHERE phone_number IS NOT NULL AND TRIM(phone_number)<>'' AND created_at <= ?");
    $mi->execute([$date.' 23:59:59']);
    foreach ($mi->fetchAll(PDO::FETCH_COLUMN) as $ph) { $n = report_normalize_phone($ph); if ($n) $set[$n] = 1; }

    return $set;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF for state-changing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['report_data','list_events','list_sms_logs'], true)) {
    $tok = $_POST['_csrf'] ?? '';
    if ($tok === '' || empty($_SESSION['report_csrf']) || !hash_equals($_SESSION['report_csrf'], $tok)) {
        echo json_encode(['status'=>'error','message'=>'Invalid security token.']); exit;
    }
}

try {
    switch ($action) {

        case 'list_events':
            $rows = $pdo->query("SELECT id, title, event_date, end_date FROM events ORDER BY event_date DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success','data'=>$rows]);
            break;

        /* ---- Manual SMS log CRUD ---- */
        case 'list_sms_logs':
            $eid = (int)($_POST['event_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sms_campaign_log WHERE event_id = ? ORDER BY sent_date ASC");
            $st->execute([$eid]);
            echo json_encode(['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_sms_log':
            $eid = (int)($_POST['event_id'] ?? 0);
            $label = trim($_POST['campaign_label'] ?? '');
            $date = $_POST['sent_date'] ?? null;
            $aud = trim($_POST['audience_label'] ?? '');
            $count = (int)($_POST['recipient_count'] ?? 0);
            $chars = (int)($_POST['characters'] ?? 0);
            $pages = max(1, (int)($_POST['pages'] ?? 1));
            $cost = (float)($_POST['unit_cost'] ?? 0);
            $total = round($count * $cost * $pages, 2);
            $notes = trim($_POST['notes'] ?? '');
            $id = (int)($_POST['id'] ?? 0);
            if (!$eid || $label === '') { echo json_encode(['status'=>'error','message'=>'Event and campaign label required.']); exit; }
            if ($id > 0) {
                $pdo->prepare("UPDATE sms_campaign_log SET campaign_label=?, sent_date=?, audience_label=?, recipient_count=?, characters=?, pages=?, unit_cost=?, total_cost=?, notes=? WHERE id=?")
                    ->execute([$label,$date,$aud,$count,$chars,$pages,$cost,$total,$notes,$id]);
            } else {
                $pdo->prepare("INSERT INTO sms_campaign_log (event_id,campaign_label,sent_date,audience_label,recipient_count,characters,pages,unit_cost,total_cost,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$eid,$label,$date,$aud,$count,$chars,$pages,$cost,$total,$notes,$user_id]);
            }
            echo json_encode(['status'=>'success','message'=>'SMS campaign saved.']);
            break;

        case 'delete_sms_log':
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM sms_campaign_log WHERE id = ?")->execute([$id]);
            echo json_encode(['status'=>'success','message'=>'SMS campaign deleted.']);
            break;

        /* ---- The full report data ---- */
        case 'report_data':
            $eid = (int)($_POST['event_id'] ?? 0);
            if (!$eid) { echo json_encode(['status'=>'error','message'=>'Event ID required.']); exit; }

            // Event info
            $event = report_load_event($pdo, $eid);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }
            $startDate = date('Y-m-d', strtotime($event['event_date']));
            $endDate = $event['end_date'] ? date('Y-m-d', strtotime($event['end_date'])) : $startDate;
            $dayCount = (int)((strtotime($endDate)-strtotime($startDate))/86400)+1;
            if ($dayCount < 1) $dayCount = 1;

            $d = [];

            // --- CONGREGATION (users with phone) ---
            // phone_status may not exist yet (SMS audit patch not applied) — build the
            // query defensively so a missing column can't crash the whole report.
            $hasPhoneStatus = report_column_exists($pdo, 'users', 'phone_status');
            $congSql = "SELECT COUNT(*) FROM users WHERE phone IS NOT NULL AND TRIM(phone)<>'' AND COALESCE(attendance_status,'')<>'Relocated'"
                     . ($hasPhoneStatus ? " AND COALESCE(phone_status,'')<>'invalid'" : "");
            $d['congregation'] = (int)$pdo->query($congSql)->fetchColumn();

            // --- CURRENT TOTAL REACH: unique people in the 3 source tables today ---
            // (the "as of today" reference number — Copy Contacts figure)
            $todaySet = report_reach_as_of($pdo, $eid, date('Y-m-d'), $hasPhoneStatus);
            $reachToday = count($todaySet);

            // --- MANUAL BULKSMS CAMPAIGNS with DATE-BASED recipient intelligence ---
            // Each campaign was sent on its sent_date. Its true recipient count is the
            // number of UNIQUE contacts across users + event registrations (this event)
            // + IDI that EXISTED on or before that sent_date. Anyone added after that
            // date is NOT counted for that campaign. A manually-logged recipient_count
            // always overrides the computed figure.
            $manualSms = [];
            if (report_table_exists($pdo,'sms_campaign_log')) {
                $man = $pdo->prepare("SELECT * FROM sms_campaign_log WHERE event_id=? ORDER BY sent_date ASC");
                $man->execute([$eid]); $manualSms = $man->fetchAll(PDO::FETCH_ASSOC);
            }
            $manualTotal = 0; $manualRecipients = 0; $manualLogged = 0; $manualComputed = 0;
            foreach ($manualSms as $i => $m) {
                $rec = (int)$m['recipient_count'];           // manually logged, if any
                $manualOverride = ($rec > 0);                // true = user typed the number
                if (!$manualOverride && !empty($m['sent_date'])) {
                    // Intelligent: count unique contacts AS AT this campaign's date.
                    $rec = count(report_reach_as_of($pdo, $eid, date('Y-m-d', strtotime($m['sent_date'])), $hasPhoneStatus));
                    if ($rec > 0) $manualComputed++;
                }
                if ($rec > 0) $manualLogged++;
                $cost = round($rec * (float)$m['unit_cost'] * max(1,(int)$m['pages']), 2);
                $manualSms[$i]['effective_recipients'] = $rec;
                $manualSms[$i]['effective_cost'] = $cost;
                $manualSms[$i]['is_manual'] = $manualOverride;
                $manualSms[$i]['computed_as_of'] = empty($m['sent_date']) ? '' : date('Y-m-d', strtotime($m['sent_date']));
                $manualTotal += $cost; $manualRecipients += $rec;
            }

            // --- SYSTEM SMS (sent via ERP SMS Studio) — optional, counts sms_log ---
            // Guarded so a missing sms_campaigns table can't 500 the report.
            $sysSms = ['cnt'=>0,'unique_recipients'=>0,'cost'=>0];
            $systemCampaigns = [];
            if (report_table_exists($pdo,'sms_log') && report_table_exists($pdo,'sms_campaigns')) {
                $sys = $pdo->prepare("SELECT COUNT(*) AS cnt, COUNT(DISTINCT recipient_phone) AS unique_recipients, SUM(cost) AS cost FROM sms_log WHERE campaign_id IN (SELECT id FROM sms_campaigns WHERE event_id=?)");
                $sys->execute([$eid]); $sysSms = $sys->fetch(PDO::FETCH_ASSOC);

                // Consolidate chunked sends into one line per campaign title.
                $sc = $pdo->prepare(
                    "SELECT c.title AS title, COUNT(l.id) AS rows_sent, COUNT(DISTINCT l.recipient_phone) AS uniq,
                            COALESCE(SUM(l.cost),0) AS cost, MAX(l.created_at) AS last_sent
                     FROM sms_log l JOIN sms_campaigns c ON c.id = l.campaign_id
                     WHERE c.event_id = ? GROUP BY c.title ORDER BY last_sent ASC"
                );
                $sc->execute([$eid]);
                $systemCampaigns = $sc->fetchAll(PDO::FETCH_ASSOC);
            }

            $d['sms'] = [
                'reach_total' => $reachToday,
                'system_count' => (int)$sysSms['cnt'],
                'system_unique' => (int)$sysSms['unique_recipients'],
                'system_cost' => round((float)$sysSms['cost'],2),
                'system_campaigns' => $systemCampaigns,
                'manual' => $manualSms,
                'manual_logged_campaigns' => $manualLogged,
                'manual_computed_campaigns' => $manualComputed,
                'manual_total_cost' => round($manualTotal,2),
                'manual_total_recipients' => $manualRecipients,
                'total_cost' => round((float)$sysSms['cost'] + $manualTotal,2),
            ];

            // --- REGISTRATION ---
            $regTotal = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id=?");
            $regTotal->execute([$eid]);
            $d['registration_total'] = (int)$regTotal->fetchColumn();

            // sources (parsed from custom_responses JSON — handles both text & enum formats)
            $src = $pdo->prepare("SELECT guest_name, guest_phone, custom_responses, user_id FROM event_registrations WHERE event_id=?");
            $src->execute([$eid]);
            $regRows = $src->fetchAll(PDO::FETCH_ASSOC);
            $sourceCounts = ['Self_Discovery'=>0,'Social_Media'=>0,'Broadcast'=>0,'Media'=>0,'Invited_By'=>0,'Flyer_Banner_Poster'=>0,'Other'=>0,'Not_Specified'=>0];
            $regBeforeEvent = 0; $regOnEventDay = 0;
            foreach ($regRows as $r) {
                $bucket = report_source_bucket($r['custom_responses'] ?? '');
                if (isset($sourceCounts[$bucket])) $sourceCounts[$bucket]++;
                else $sourceCounts['Other']++;
            }
            $d['registration_sources'] = $sourceCounts;
            $d['source_labels'] = [
                'Self_Discovery'=>'Self discovery', 'Social_Media'=>'Social media', 'Broadcast'=>'Broadcast / mass media',
                'Media'=>'Media & press', 'Invited_By'=>'Invited by someone', 'Flyer_Banner_Poster'=>'Flyer / banner / poster',
                'Other'=>'Other', 'Not_Specified'=>'Not specified',
            ];

            // pre-event vs event-day (based on registered_at date)
            $regTime = $pdo->prepare("SELECT DATE(registered_at) AS rd, guest_phone FROM event_registrations WHERE event_id=?");
            $regTime->execute([$eid]);
            foreach ($regTime->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                $rd = $rr['rd'];
                if ($rd >= $startDate && $rd <= $endDate) $regOnEventDay++;
                else $regBeforeEvent++;
            }
            $d['registered_before_event'] = $regBeforeEvent;
            $d['registered_on_event_days'] = $regOnEventDay;

            // --- ATTENDANCE (checkins) ---
            $attTotal = $pdo->prepare("SELECT COUNT(DISTINCT phone) FROM checkins WHERE event_id=?");
            $attTotal->execute([$eid]);
            $d['attendance_total'] = (int)$attTotal->fetchColumn();

            // per day
            $d['attendance_by_day'] = [];
            for ($i=1; $i<=$dayCount; $i++) {
                $dVal = date('Y-m-d', strtotime($startDate.' +'.($i-1).' days'));
                $q = $pdo->prepare("SELECT COUNT(DISTINCT phone) FROM checkins WHERE event_id=? AND checkin_date=?");
                $q->execute([$eid,$dVal]);
                $d['attendance_by_day'][] = ['day'=>$i,'date'=>$dVal,'count'=>(int)$q->fetchColumn()];
            }

            // members vs walk-ins (dedup by phone, prefer member flag)
            $mi = $pdo->prepare("SELECT phone, MAX(is_member) AS is_member, MAX(is_walkin) AS is_walkin FROM checkins WHERE event_id=? GROUP BY phone");
            $mi->execute([$eid]);
            $mem=0;$walk=0;
            foreach ($mi->fetchAll(PDO::FETCH_ASSOC) as $m) { if($m['is_member']==1)$mem++; elseif($m['is_walkin']==1)$walk++; else $walk++; }
            $d['attendance_members'] = $mem;
            $d['attendance_walkins'] = $walk;

            // attended from prior registration (people who checked in AND registered before event)
            $priReg = $pdo->prepare("SELECT DISTINCT r.guest_phone FROM event_registrations r WHERE r.event_id=? AND DATE(r.registered_at) < ?");
            $priReg->execute([$eid, $startDate]);
            $preRegPhones = $priReg->fetchAll(PDO::FETCH_COLUMN);
            $attPhones = $pdo->prepare("SELECT DISTINCT phone FROM checkins WHERE event_id=?"); $attPhones->execute([$eid]);
            $attSet = array_flip($attPhones->fetchAll(PDO::FETCH_COLUMN));
            $attFromPrior = 0;
            foreach ($preRegPhones as $p) { $norm = preg_replace('/[^0-9]/','',$p); $norm = (strlen($norm)===11&&$norm[0]==='0')?'234'.substr($norm,1):(strlen($norm)===10?'234'.$norm:$norm); if(isset($attSet[$norm]))$attFromPrior++; }
            $d['attended_from_prior_registration'] = $attFromPrior;

            // --- IDI MOBILIZATION ---
            // Match campaigns by shared word with the event title (robust to how
            // the campaign was named), then build an IN() to fetch only those rows.
            $idiCampaigns = report_match_campaigns($pdo, $event['title']);
            $idiRows = [];
            if ($idiCampaigns) {
                $in = implode(',', array_fill(0, count($idiCampaigns), '?'));
                $idi = $pdo->prepare("SELECT full_name, phone_number, is_contacted FROM idi_mobilization WHERE event_campaign IN ($in)");
                $idi->execute($idiCampaigns);
                $idiRows = $idi->fetchAll(PDO::FETCH_ASSOC);
            }
            $d['idi_campaigns'] = $idiCampaigns;
            $d['idi_total'] = count($idiRows);
            $idiContacted = 0;
            $idiAttended = 0;
            $idiPhones = [];
            foreach ($idiRows as $row) {
                if ((int)$row['is_contacted'] === 1) $idiContacted++;
                $norm = preg_replace('/[^0-9]/','',$row['phone_number']);
                $norm = (strlen($norm)===11&&$norm[0]==='0')?'234'.substr($norm,1):(strlen($norm)===10?'234'.$norm:$norm);
                $idiPhones[] = $norm;
            }
            foreach ($idiPhones as $p) { if (isset($attSet[$p])) $idiAttended++; }
            $d['idi_contacted'] = $idiContacted;
            $d['idi_attended'] = $idiAttended;

            // --- NARRATIVE SUMMARY (plain-text prose for non-number readers) ---
            $regN   = $d['registration_total'];
            $attN   = $d['attendance_total'];
            $regAtt = ($regN > 0) ? round($attN / $regN * 100) : 0;      // attendees as % of registrants
            $priAtt = ($attN > 0) ? round($d['attended_from_prior_registration'] / $attN * 100) : 0;
            $idiAtt = ($d['idi_total'] > 0) ? round($idiAttended / $d['idi_total'] * 100) : 0;
            $memPct = ($attN > 0) ? round($d['attendance_members'] / $attN * 100) : 0;
            $d['narrative'] = [
                'conv_rate'  => $regAtt,
                'prior_rate' => $priAtt,
                'idi_rate'   => $idiAtt,
                'member_pct' => $memPct,
                'title'      => $event['title'],
                'start_date' => date('F j, Y', strtotime($event['event_date'])),
                'end_date'   => $event['end_date'] ? date('F j, Y', strtotime($event['end_date'])) : date('F j, Y', strtotime($event['event_date'])),
            ];

            echo json_encode(['status'=>'success','event'=>$event,'day_count'=>$dayCount,'data'=>$d]);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid action.']);
            break;
    }
} catch (\Throwable $e) {
    error_log("Event Report API Error: ".$e->getMessage());
    // Return the real message so the frontend can surface it instead of failing silently.
    echo json_encode(['status'=>'error','message'=>'Report failed: '.$e->getMessage()]);
}
