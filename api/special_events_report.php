<?php
// /api/special_events_report.php
// Aggregate, PII-free leadership PDF (guide §18.5).

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';

function se_report_fail(int $status, string $message): never
{
    http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $message; exit;
}
if (!isset($_SESSION['user_id'])) se_report_fail(401, 'Please sign in.');
$event=se_event_find_by_public_id($pdo,se_str($_GET['event']??'',12));
if(!$event)se_report_fail(404,'Event not found.');
$userId=(int)$_SESSION['user_id']; if(!se_has_capability($pdo,(int)$event['id'],'insights.view',$userId))se_report_fail(403,'Not allowed.');
$autoload=dirname(__DIR__).'/vendor/autoload.php'; if(!is_file($autoload))se_report_fail(500,'PDF support is not installed.'); require_once $autoload;
$stats=se_insights($pdo,$event); $title=trim($event['title'].' '.($event['edition_label']??'')); $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$narrative=[
    'headline'=>$title.' at a glance',
    'paragraphs'=>[
        $stats['counts']['registrations'].' people registered and '.$stats['counts']['checked_in'].' checked in.',
        'The event recorded a '.$stats['counts']['show_up_pct'].'% show-up rate and '.$stats['counts']['walkins'].' walk-ins.',
        $stats['feedback']['responses'].' people shared feedback'.($stats['feedback']['nps']!==null?', producing an NPS of '.$stats['feedback']['nps']:'.'),
    ],
    'recommendations'=>['Review the weakest funnel step.','Follow up consenting guests within 72 hours.','Carry the strongest channels into the next edition.'],
];
// Aggregate statistics only enter the prompt. If AI is disabled or rejects
// the shape, the deterministic narrative above remains the report.
try {
    if (se_ai_available()) {
        $aiNarrative = se_ai($pdo, 'report_summary', ['stats_json' => se_json_encode($stats)], ['user_id' => $userId, 'event_id' => (int) $event['id']]);
        if (isset($aiNarrative['headline'], $aiNarrative['paragraphs'], $aiNarrative['recommendations'])) $narrative = $aiNarrative;
    }
} catch (Throwable $ignored) {
    // Fallback is intentional; report generation must never depend on AI.
}
$html='<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;color:#172033;font-size:11px}h1{font-size:28px;color:#0A0E17}h2{margin-top:24px;border-bottom:2px solid #D11920;padding-bottom:5px}.kpis{display:table;width:100%}.kpi{display:table-cell;padding:12px;background:#f3f5f8;border:4px solid white;text-align:center}.big{font-size:22px;font-weight:bold}table{width:100%;border-collapse:collapse}th,td{padding:7px;border-bottom:1px solid #ddd;text-align:left}.bar{height:8px;background:#0A0E17}</style></head><body>';
$html.='<h1>'.$e($title).' — Event Report</h1><p>'.$e($event['organizer_label']).' · '.$e(substr((string)$event['starts_at'],0,10)).' · '.$e($event['venue_name']).'</p><div class="kpis">';
foreach(['Registered'=>$stats['counts']['registrations'],'Checked in'=>$stats['counts']['checked_in'],'Show-up'=>$stats['counts']['show_up_pct'].'%','Walk-ins'=>$stats['counts']['walkins'],'Games joined'=>$stats['counts']['games_joined'],'NPS'=>$stats['feedback']['nps']??'—'] as $label=>$value)$html.='<div class="kpi"><div class="big">'.$e($value).'</div>'.$e($label).'</div>';
$html.='</div><h2>'.$e($narrative['headline']).'</h2>';foreach($narrative['paragraphs'] as $p)$html.='<p>'.$e($p).'</p>';
foreach(['Registration funnel'=>$stats['funnel'],'Channels'=>$stats['channels'],'Teams'=>$stats['teams'],'Hand-off'=>$stats['handoff']] as $heading=>$rows){$html.='<h2>'.$e($heading).'</h2><table><tr><th>Category</th><th>Count</th></tr>';foreach($rows as $r)$html.='<tr><td>'.$e($r['label']).'</td><td>'.$e($r['value']).'</td></tr>';$html.='</table>';}
$html.='<h2>Recommendations</h2><ul>';foreach($narrative['recommendations'] as $r)$html.='<li>'.$e($r).'</li>';$html.='</ul><h2>Definitions</h2><p>Show-up is checked-in registrations divided by confirmed online registrations. NPS is promoters minus detractors as a percentage of responses. Test rows are excluded.</p></body></html>';
$dompdf=new Dompdf\Dompdf(['isRemoteEnabled'=>false]);$dompdf->loadHtml($html);$dompdf->setPaper('A4');$dompdf->render();
se_audit($pdo,(int)$event['id'],'report_pdf',['aggregate_only'=>true],'event',(int)$event['id'],$userId);
$dompdf->stream(preg_replace('/[^a-z0-9-]+/i','-',$title).'-event-report.pdf',['Attachment'=>true]);exit;
