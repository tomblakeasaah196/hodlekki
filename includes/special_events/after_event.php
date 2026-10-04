<?php
// /includes/special_events/after_event.php
// Feedback, aggregate insights, reporting, hand-off and retention (guide §§17–19).

function se_feedback_save(PDO $pdo, array $event, array $registration, array $input): array
{
    if (!se_table_exists($pdo, 'se_feedback')) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Feedback is not available yet.');
    }
    $eventId = (int) $event['id'];
    $nps = isset($input['nps']) && $input['nps'] !== '' ? se_int($input['nps'], 0, 10) : null;
    $favorite = se_line($input['favorite'] ?? '', 30) ?: null;
    $oneWord = se_line($input['one_word'] ?? '', 40) ?: null;
    $comment = se_str($input['comment'] ?? '', 2000) ?: null;
    $wantsVisit = se_bool($input['wants_visit'] ?? false) ? 1 : 0;
    $futureOptin = array_key_exists('future_optin', $input) ? (se_bool($input['future_optin']) ? 1 : 0) : null;

    $pdo->prepare("INSERT INTO se_feedback
        (event_id, registration_id, nps, favorite, one_word, comment, wants_visit, future_optin)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nps=VALUES(nps), favorite=VALUES(favorite), one_word=VALUES(one_word),
        comment=VALUES(comment), wants_visit=VALUES(wants_visit), future_optin=VALUES(future_optin)")
        ->execute([$eventId, (int) $registration['id'], $nps, $favorite, $oneWord, $comment, $wantsVisit, $futureOptin]);
    if ($wantsVisit) {
        $pdo->prepare("UPDATE se_registrations SET wants_visit = 1 WHERE id = ? AND event_id = ?")
            ->execute([(int) $registration['id'], $eventId]);
    }
    if ($futureOptin !== null) {
        $pdo->prepare("UPDATE se_contacts SET consent_followup = ?, consent_followup_at = IF(? = 1, COALESCE(consent_followup_at, NOW()), consent_followup_at) WHERE id = ?")
            ->execute([$futureOptin, $futureOptin, (int) $registration['contact_id']]);
    }
    return ['saved' => true, 'nps' => $nps];
}

/** Aggregate-only event statistics. No PII leaves this function. */
function se_insights(PDO $pdo, array $event): array
{
    $eventId = (int) $event['id'];
    $scalar = static function (string $sql, array $args = []) use ($pdo): int {
        $stmt = $pdo->prepare($sql); $stmt->execute($args); return (int) $stmt->fetchColumn();
    };
    $counts = [
        'registrations' => $scalar("SELECT COUNT(*) FROM se_registrations WHERE event_id=? AND status IN ('confirmed','waitlisted') AND is_test=0", [$eventId]),
        'confirmed' => $scalar("SELECT COUNT(*) FROM se_registrations WHERE event_id=? AND status='confirmed' AND is_test=0", [$eventId]),
        'checked_in' => $scalar("SELECT COUNT(DISTINCT registration_id) FROM se_checkins WHERE event_id=? AND is_test=0", [$eventId]),
        'walkins' => $scalar("SELECT COUNT(DISTINCT registration_id) FROM se_checkins WHERE event_id=? AND is_walkin=1 AND is_test=0", [$eventId]),
        'members' => $scalar("SELECT COUNT(DISTINCT r.id) FROM se_registrations r JOIN se_checkins c ON c.registration_id=r.id AND c.event_id=r.event_id WHERE r.event_id=? AND r.is_member=1 AND r.is_test=0", [$eventId]),
        'games_joined' => $scalar("SELECT COUNT(DISTINCT registration_id) FROM se_devices WHERE event_id=? AND joined_games_at IS NOT NULL", [$eventId]),
        'karaoke_performed' => se_table_exists($pdo, 'se_karaoke_entries') ? $scalar("SELECT COUNT(*) FROM se_karaoke_entries WHERE event_id=? AND status='done' AND is_test=0", [$eventId]) : 0,
    ];
    $counts['show_up_pct'] = $counts['confirmed'] ? round(100 * $counts['checked_in'] / $counts['confirmed'], 1) : 0;
    $counts['game_join_pct'] = $counts['checked_in'] ? round(100 * $counts['games_joined'] / $counts['checked_in'], 1) : 0;

    $group = static function (string $sql, array $args = []) use ($pdo): array {
        $stmt=$pdo->prepare($sql); $stmt->execute($args); $out=[];
        foreach ($stmt->fetchAll() ?: [] as $r) $out[]=['label'=>(string)$r['label'],'value'=>(int)$r['value']];
        return $out;
    };
    $feedback = ['responses'=>0,'nps'=>null,'promoters'=>0,'detractors'=>0];
    if (se_table_exists($pdo, 'se_feedback')) {
        $stmt=$pdo->prepare("SELECT COUNT(*) responses, SUM(nps>=9) promoters, SUM(nps<=6) detractors FROM se_feedback WHERE event_id=? AND nps IS NOT NULL");
        $stmt->execute([$eventId]); $f=$stmt->fetch() ?: [];
        $feedback=['responses'=>(int)($f['responses']??0),'promoters'=>(int)($f['promoters']??0),'detractors'=>(int)($f['detractors']??0),'nps'=>null];
        if ($feedback['responses']) $feedback['nps']=round(100*($feedback['promoters']-$feedback['detractors'])/$feedback['responses']);
    }
    $series = [];
    if (!empty($event['series_id'])) {
        $feedbackJoin = se_table_exists($pdo, 'se_feedback')
            ? "LEFT JOIN se_feedback f ON f.event_id=e.id AND f.registration_id=r.id"
            : "";
        $npsColumn = se_table_exists($pdo, 'se_feedback') ? "ROUND(AVG(f.nps),1)" : "NULL";
        $stmt=$pdo->prepare("SELECT e.id, CONCAT(e.title, ' ', COALESCE(e.edition_label,'')) label,
            COUNT(DISTINCT r.id) registrations, COUNT(DISTINCT c.registration_id) checked_in,
            {$npsColumn} avg_nps
            FROM se_events e LEFT JOIN se_registrations r ON r.event_id=e.id AND r.is_test=0
            LEFT JOIN se_checkins c ON c.event_id=e.id AND c.registration_id=r.id AND c.is_test=0
            {$feedbackJoin}
            WHERE e.series_id=? GROUP BY e.id ORDER BY e.starts_at");
        $stmt->execute([(int)$event['series_id']]); $series=$stmt->fetchAll() ?: [];
    }
    return [
        'counts'=>$counts,
        'funnel'=>[['label'=>'Portal views','value'=>$scalar("SELECT COALESCE(SUM(value),0) FROM se_metrics_daily WHERE event_id=? AND metric='view'",[$eventId])],['label'=>'Registration starts','value'=>$scalar("SELECT COALESCE(SUM(value),0) FROM se_metrics_daily WHERE event_id=? AND metric='reg_start'",[$eventId])],['label'=>'Registered','value'=>$counts['registrations']],['label'=>'Checked in','value'=>$counts['checked_in']]],
        'channels'=>$group("SELECT COALESCE(NULLIF(src,''),'direct') label, COUNT(*) value FROM se_registrations WHERE event_id=? AND is_test=0 GROUP BY label ORDER BY value DESC",[$eventId]),
        'gender'=>$group("SELECT COALESCE(gender,'Not given') label, COUNT(*) value FROM se_registrations WHERE event_id=? AND is_test=0 GROUP BY label",[$eventId]),
        'teams'=>$group("SELECT COALESCE(t.name, CONCAT('Team ',t.color_label)) label, COUNT(DISTINCT c.registration_id) value FROM se_teams t LEFT JOIN se_registrations r ON r.team_id=t.id LEFT JOIN se_checkins c ON c.registration_id=r.id AND c.event_id=t.event_id WHERE t.event_id=? GROUP BY t.id ORDER BY t.sort_order",[$eventId]),
        'feedback'=>$feedback,
        'handoff'=>se_table_exists($pdo,'se_handoff_items') ? $group("SELECT CONCAT(destination, ': ', outcome) label, COUNT(*) value FROM se_handoff_items WHERE event_id=? GROUP BY destination,outcome ORDER BY value DESC",[$eventId]) : [],
        'series'=>$series,
        'hall_of_fame'=>function_exists('se_finale_payload') ? (se_finale_payload($pdo,$event)['champion'] ?? null) : null,
    ];
}

function se_handoff_preview(PDO $pdo, array $event, bool $includeNoShows = false): array
{
    if (!se_table_exists($pdo, 'se_handoffs')) throw new SeRuleException('FEATURE_NOT_READY', 'Hand-off is not migrated yet.');
    $stmt=$pdo->prepare("SELECT r.id registration_id,r.contact_id,r.display_name,r.is_member,r.wants_visit,r.first_checkin_at,c.consent_followup,c.opted_out_at,c.phone_e164,
        EXISTS(SELECT 1 FROM se_handoff_items hi WHERE hi.event_id=r.event_id AND hi.contact_id=r.contact_id AND hi.outcome IN ('created','linked_existing')) already_done
        FROM se_registrations r JOIN se_contacts c ON c.id=r.contact_id WHERE r.event_id=? AND r.is_test=0 AND r.status<>'removed' ORDER BY r.id");
    $stmt->execute([(int)$event['id']]); $items=[];
    foreach ($stmt->fetchAll() ?: [] as $r) {
        $destination='none'; $reason='skipped_excluded';
        if ($r['already_done']) $reason='already_handed_off';
        elseif ($r['opted_out_at']) $reason='skipped_opted_out';
        elseif ((int)$r['is_member']===1) $reason='skipped_member';
        elseif ((int)$r['consent_followup']!==1) $reason='skipped_no_consent';
        elseif (!$r['first_checkin_at'] && !$includeNoShows) $reason='skipped_no_show';
        elseif (!preg_match('/\d{9}$/', preg_replace('/\D/','',(string)$r['phone_e164']))) $reason='skipped_invalid_phone';
        else { $destination=(int)$r['wants_visit']===1?'embrace':'reach'; $reason='ready'; }
        $items[]=['registration_id'=>(int)$r['registration_id'],'contact_id'=>(int)$r['contact_id'],'name'=>(string)$r['display_name'],'destination'=>$destination,'reason'=>$reason];
    }
    $summary=[]; foreach($items as $item) $summary[$item['destination'].':'.$item['reason']]=1+($summary[$item['destination'].':'.$item['reason']]??0);
    return ['items'=>$items,'summary'=>$summary];
}

function se_local_phone(string $phone): ?string
{
    $digits=preg_replace('/\D/','',$phone); if (strlen($digits)<9) return null;
    return str_starts_with($digits,'234') ? '0'.substr($digits,-10) : '+'.$digits;
}

/** Push one reviewed batch. Database unique keys are the final idempotency guard. */
function se_handoff_push(PDO $pdo, array $event, array $options, array $overrides, int $actorId): array
{
    $preview=se_handoff_preview($pdo,$event,se_bool($options['include_no_shows']??false));
    $overrideMap=[]; foreach($overrides as $o) if(isset($o['registration_id'])) $overrideMap[(int)$o['registration_id']]=$o;
    $pdo->prepare("INSERT INTO se_handoffs(event_id,summary_json,created_by) VALUES(?,?,?)")->execute([(int)$event['id'],se_json_encode($options),$actorId]);
    $handoffId=(int)$pdo->lastInsertId(); $reachCampaign=null; $counts=[];
    foreach(array_chunk($preview['items'],50) as $batch) foreach($batch as $item) {
        if(isset($overrideMap[$item['registration_id']])) { $o=$overrideMap[$item['registration_id']]; $item['destination']=se_enum($o['destination']??'none',['reach','embrace','none'],'none'); $item['reason']=$item['destination']==='none'?'skipped_excluded':'ready'; }
        $outcome=$item['reason']; $targetTable=null; $targetId=null;
        if($item['reason']==='ready') {
            $stmt=$pdo->prepare("SELECT r.*,c.phone_e164,c.member_user_id,c.email contact_email FROM se_registrations r JOIN se_contacts c ON c.id=r.contact_id WHERE r.id=? AND r.event_id=?"); $stmt->execute([$item['registration_id'],(int)$event['id']]); $r=$stmt->fetch();
            try {
                if($item['destination']==='reach') { if(!$reachCampaign) $reachCampaign=se_handoff_reach_campaign($pdo,$event,$actorId); $targetId=se_handoff_reach_lead($pdo,$event,$r,$reachCampaign,$actorId); $targetTable='reach_leads'; }
                else { [$targetId,$linked]=se_handoff_embrace($pdo,$event,$r); $targetTable='users'; $outcome=$linked?'linked_existing':'created'; }
                if($outcome==='ready') $outcome=$targetId?'created':'already_handed_off';
            } catch(PDOException $e) { if(se_is_duplicate_key($e)) $outcome='already_handed_off'; else throw $e; }
        }
        $pdo->prepare("INSERT INTO se_handoff_items(handoff_id,event_id,registration_id,contact_id,destination,outcome,target_table,target_id,note) VALUES(?,?,?,?,?,?,?,?,?)")
            ->execute([$handoffId,(int)$event['id'],$item['registration_id'],$item['contact_id'],$item['destination'],$outcome,$targetTable,$targetId,se_line($overrideMap[$item['registration_id']]['reason']??'',160)?:null]);
        $counts[$outcome]=1+($counts[$outcome]??0);
    }
    $pdo->prepare("UPDATE se_handoffs SET reach_campaign_id=?,summary_json=? WHERE id=?")->execute([$reachCampaign,se_json_encode(['options'=>$options,'counts'=>$counts]),$handoffId]);
    se_audit($pdo,(int)$event['id'],'handoff_run',['handoff_id'=>$handoffId,'counts'=>$counts],'handoff',$handoffId,$actorId);
    if (!function_exists('reach_notify') && is_file(__DIR__ . '/../reach_helpers.php')) require_once __DIR__ . '/../reach_helpers.php';
    if (function_exists('reach_notify')) {
        $reachIds=$pdo->query("SELECT ud.user_id FROM user_departments ud JOIN departments d ON d.id=ud.department_id WHERE (d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%') AND ud.role_in_dept IN ('HOD','Director') AND ud.is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        $embraceIds=$pdo->query("SELECT ud.user_id FROM user_departments ud JOIN departments d ON d.id=ud.department_id WHERE d.name LIKE '%Embrace%' AND ud.role_in_dept IN ('HOD','Director') AND ud.is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        $label=trim($event['title'].' '.($event['edition_label']??''));
        if($reachCampaign) reach_notify($pdo,$reachIds,'Special Event guests ready',($counts['created']??0).' guests from '.$label.' are ready in Reach.','/modules/reach/index.php');
        if(($counts['created']??0)>0) reach_notify($pdo,$embraceIds,'Special Event first-timers',($counts['created']??0).' hand-off records from '.$label.' are ready to review.','/modules/embrace/index.php');
    }
    return ['handoff_id'=>$handoffId,'reach_campaign_id'=>$reachCampaign,'counts'=>$counts];
}

function se_handoff_reach_campaign(PDO $pdo,array $event,int $actor): int
{
    $stmt=$pdo->prepare("SELECT reach_campaign_id FROM se_handoffs WHERE event_id=? AND reach_campaign_id IS NOT NULL ORDER BY id LIMIT 1"); $stmt->execute([(int)$event['id']]); if($id=$stmt->fetchColumn()) return (int)$id;
    $days=se_event_days($pdo,(int)$event['id']); $day=$days[0]??[]; $base='se-'.$event['slug'].'-'.str_replace('-','',(string)($day['day_date']??substr($event['starts_at'],0,10))); $slug=$base; $n=2;
    $check=$pdo->prepare("SELECT 1 FROM reach_campaigns WHERE slug=?"); while(true){$check->execute([$slug]);if(!$check->fetchColumn())break;$slug=$base.'-'.$n++;}
    $title=trim($event['title'].' '.($event['edition_label']??''));
    $pdo->prepare("INSERT INTO reach_campaigns(slug,title,campaign_type,campaign_date,start_time,end_time,location,meta_description,payload_tier,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,'Active',?)")
        ->execute([$slug,$title.' (Envision)','Special_Event',$day['day_date']??substr($event['starts_at'],0,10),substr((string)($day['starts_at']??$event['starts_at']),11,8),substr((string)($day['ends_at']??$event['ends_at']),11,8),$event['venue_name'],'Guests who came to '.$title.', handed off by Envision.','Rapid',$actor]);
    return (int)$pdo->lastInsertId();
}

function se_handoff_reach_lead(PDO $pdo,array $event,array $r,int $campaign,int $actor): ?int
{
    $phone=se_local_phone((string)$r['phone_e164']); if(!$phone)return null;
    $q=$pdo->prepare("SELECT id FROM reach_leads WHERE campaign_id=? AND REPLACE(REPLACE(phone,'+',''),' ','') LIKE ? LIMIT 1");$q->execute([$campaign,'%'.substr(preg_replace('/\D/','',$phone),-9)]);if($q->fetchColumn())return null;
    $notes='Met at '.trim($event['title'].' '.($event['edition_label']??'')).' (Envision).'.($r['how_heard']?' Heard via: '.$r['how_heard'].'.':'');
    $pdo->prepare("INSERT INTO reach_leads(campaign_id,first_name,last_name,phone,category,willing_for_visit,notes,status) VALUES(?,?,?,?, 'Other',0,?,'Not_Spoken_To')")
        ->execute([$campaign,$r['first_name'],$r['last_name'],$phone,$notes]); $id=(int)$pdo->lastInsertId();
    if(se_table_exists($pdo,'reach_lead_captures')) $pdo->prepare("INSERT INTO reach_lead_captures(lead_id,captured_by_user_id,captured_by_guest_name) VALUES(?,? ,?)")->execute([$id,$actor,'Envision — '.trim($event['title'].' '.($event['edition_label']??''))]);
    return $id;
}

function se_handoff_embrace(PDO $pdo,array $event,array $r): array
{
    $digits=preg_replace('/\D/','',(string)$r['phone_e164']); $q=$pdo->prepare("SELECT id FROM users WHERE phone LIKE ? LIMIT 1");$q->execute(['%'.substr($digits,-9).'%']);
    if($id=$q->fetchColumn()){ $pdo->prepare("UPDATE se_contacts SET member_user_id=? WHERE id=?")->execute([(int)$id,(int)$r['contact_id']]); return [(int)$id,true]; }
    $phone=se_local_phone((string)$r['phone_e164']); $invited='Envision: '.trim($event['title'].' '.($event['edition_label']??''));
    $email=trim((string)($r['contact_email']??'')); if($email===''||str_ends_with(strtolower($email),'@hodlc.com'))$email=null;
    $pdo->prepare("INSERT INTO users(first_name,last_name,phone,gender,email,marital_status,physical_address,spiritual_status,invitation_source,invited_by,qr_code_hash) VALUES(?,?,?,?,?,'Single','To be updated','1st_Timer','Other',?,?)")
        ->execute([$r['first_name'],$r['last_name'],$phone,$r['gender'],$email,$invited,hash('sha256',bin2hex(random_bytes(16)).$digits)]);
    $id=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE se_contacts SET member_user_id=? WHERE id=?")->execute([$id,(int)$r['contact_id']]);return[$id,false];
}

function se_retention_run(PDO $pdo): array
{
    $months=se_int(se_setting_get($pdo,'retention_months_guest','24'),1,120,24); $out=['contacts'=>0,'devices'=>0,'tokens'=>0];
    $stmt=$pdo->prepare("SELECT DISTINCT c.id FROM se_contacts c JOIN se_registrations r ON r.contact_id=c.id JOIN se_events e ON e.id=r.event_id WHERE e.archived_at<DATE_SUB(NOW(),INTERVAL ? MONTH) AND c.member_user_id IS NULL AND c.consent_followup=0 AND c.erased_at IS NULL LIMIT 200");$stmt->execute([$months]);
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id){$pdo->prepare("UPDATE se_contacts SET first_name='Erased',last_name='',phone_e164=CONCAT('erased:',id),phone_display=NULL,sms_capable=0,email=NULL,gender=NULL,member_user_id=NULL,consent_followup=0,consent_text_hash=NULL,opted_out_at=COALESCE(opted_out_at,NOW()),erased_at=NOW() WHERE id=?")->execute([$id]);$pdo->prepare("UPDATE se_registrations SET first_name='Erased',last_name='',email=NULL,gender=NULL,display_name='Guest',name_correction=NULL,how_heard_other=NULL,answers_json=NULL,ip_hash=NULL WHERE contact_id=?")->execute([$id]);$pdo->prepare("UPDATE se_access_tokens t JOIN se_registrations r ON r.id=t.registration_id SET t.revoked_at=NOW() WHERE r.contact_id=?")->execute([$id]);$pdo->prepare("UPDATE se_devices d JOIN se_registrations r ON r.id=d.registration_id SET d.revoked_at=NOW(),d.registration_id=NULL WHERE r.contact_id=?")->execute([$id]);$out['contacts']++;}
    $out['devices']=$pdo->exec("DELETE d FROM se_devices d JOIN se_events e ON e.id=d.event_id WHERE e.ends_at<DATE_SUB(NOW(),INTERVAL 60 DAY)");
    $out['tokens']=$pdo->exec("DELETE t FROM se_access_tokens t JOIN se_events e ON e.id=t.event_id WHERE e.ends_at<DATE_SUB(NOW(),INTERVAL 30 DAY)");
    return $out;
}
