<?php
// /includes/reach_report_pdf.php
// Two-page Reach Ministry Report (dompdf). Charts are SVG data-URI images,
// which dompdf renders natively; Chart.js canvases can't be used here.

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/reach_helpers.php';

const REACH_PDF_CATEGORY_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
const REACH_PDF_FUNNEL_COLORS   = ['#10b981', '#059669', '#047857', '#064e3b'];

function reach_pdf_esc($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function reach_pdf_svg(string $svg, int $w, int $h): string {
    return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" width="' . $w . '" height="' . $h . '">';
}

function reach_pdf_funnel_svg(array $funnel): string {
    $w = 480; $h = 150; $max = max(1, ...array_column($funnel, 'count'));
    $bw = 92; $gap = 22; $x = 20;
    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'>";
    $s .= "<line x1='20' y1='120' x2='" . ($w - 20) . "' y2='120' stroke='#e1e0d9' stroke-width='1'/>";
    foreach ($funnel as $i => $f) {
        $bh = max(2, round(100 * $f['count'] / $max));
        $y = 120 - $bh;
        $s .= "<rect x='{$x}' y='{$y}' width='{$bw}' height='{$bh}' rx='4' fill='" . REACH_PDF_FUNNEL_COLORS[$i] . "'/>";
        $s .= "<text x='" . ($x + $bw / 2) . "' y='" . ($y - 6) . "' font-family='DejaVu Sans' font-size='13' font-weight='bold' fill='#0b0b0b' text-anchor='middle'>{$f['count']}</text>";
        $s .= "<text x='" . ($x + $bw / 2) . "' y='138' font-family='DejaVu Sans' font-size='8.5' fill='#52514e' text-anchor='middle'>" . reach_pdf_esc($f['stage']) . "</text>";
        $x += $bw + $gap;
    }
    return reach_pdf_svg($s . '</svg>', $w, $h);
}

// Horizontal bars; $colors is one colour for all rows or one per row.
function reach_pdf_hbar_svg(array $rows, $colors): string {
    $row = 18; $w = 480; $label = 150; $h = max(1, count($rows)) * $row + 6;
    $max = max(1, ...(array_column($rows, 'count') ?: [1]));
    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'>";
    foreach ($rows as $i => $r) {
        $y = $i * $row + 3;
        $bw = max(2, round(($w - $label - 40) * $r['count'] / $max));
        $fill = is_array($colors) ? $colors[$i] : $colors;
        $name = mb_strimwidth((string) $r['label'], 0, 24, '…');
        $s .= "<text x='" . ($label - 8) . "' y='" . ($y + 11) . "' font-family='DejaVu Sans' font-size='9' fill='#52514e' text-anchor='end'>" . reach_pdf_esc($name) . "</text>";
        $s .= "<rect x='{$label}' y='{$y}' width='{$bw}' height='" . ($row - 5) . "' rx='3' fill='{$fill}'/>";
        $s .= "<text x='" . ($label + $bw + 5) . "' y='" . ($y + 11) . "' font-family='DejaVu Sans' font-size='9' font-weight='bold' fill='#0b0b0b'>{$r['count']}</text>";
    }
    return reach_pdf_svg($s . '</svg>', $w, $h);
}

function reach_report_narrative(array $a): string {
    $k = $a['kpis'];
    $range = date('j M Y', strtotime($a['range']['from'])) . ' – ' . date('j M Y', strtotime($a['range']['to']));
    $fallback = "Between {$range}, the Reach family took the gospel to the streets in {$k['campaigns']} campaign(s) and met {$k['souls']} soul(s). "
        . "{$k['follow_up_rate']}% of them have heard from us again, and {$k['conversion_rate']}% have come to church and been welcomed by Embrace.\n\n"
        . ($k['overdue'] > 0
            ? "{$k['overdue']} assigned lead(s) have waited more than five days for a first call — let us close that gap so no one we met is forgotten."
            : "Every assigned lead has been followed up on time. Thank you for your faithfulness — let us keep going.");

    // Aggregates only: no names or phone numbers leave the server.
    $metrics = [
        'kpis' => $k, 'funnel' => $a['funnel'], 'categories' => $a['category_breakdown'],
        'top_areas' => array_slice($a['area_breakdown'], 0, 5),
        'campaigns' => array_map(fn($c) => ['title' => $c['title'], 'souls' => $c['souls'], 'follow_up_rate' => $c['follow_up_rate']], $a['campaigns_table']),
    ];
    $prompt = "Given these Reach ministry metrics for {$range}, write 2 short paragraphs summarising the story of the month, the wins, and the gaps. "
        . "Warm pastoral tone, ~150 words. Plain text only, no headings or markdown.\n\n" . json_encode($metrics);

    return reach_gemini($prompt, 0.4) ?? $fallback;
}

// Returns the PDF bytes.
function reach_build_report_pdf(PDO $pdo, array $a, string $generated_by): string {
    $lo = $a['range']['from'] . ' 00:00:00';
    $hi = (new DateTime($a['range']['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $stmt = $pdo->prepare("
        SELECT first_name, prayer_request FROM reach_leads
         WHERE willing_for_visit = 1 AND TRIM(COALESCE(prayer_request, '')) <> ''
           AND created_at >= ? AND created_at < ?
         ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$lo, $hi]);
    $prayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT l.first_name, c.title AS campaign,
               COALESCE((SELECT f.notes FROM reach_follow_ups f
                          WHERE f.lead_id = l.id AND f.outcome = 'Reached' AND TRIM(COALESCE(f.notes, '')) <> ''
                          ORDER BY f.id DESC LIMIT 1), l.notes) AS story
          FROM reach_leads l
          LEFT JOIN reach_campaigns c ON c.id = l.campaign_id
         WHERE l.share_testimony_in_report = 1 AND l.pushed_to_embrace_at >= ? AND l.pushed_to_embrace_at < ?
         ORDER BY l.pushed_to_embrace_at DESC LIMIT 3
    ");
    $stmt->execute([$lo, $hi]);
    $testimonies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $e = 'reach_pdf_esc';
    $k = $a['kpis'];
    $range = date('j M Y', strtotime($a['range']['from'])) . ' – ' . date('j M Y', strtotime($a['range']['to']));
    $logo_path = __DIR__ . '/../assets/images/logo_hod.png';
    $logo = is_file($logo_path) ? '<img src="data:image/png;base64,' . base64_encode(file_get_contents($logo_path)) . '" width="52" height="52">' : '';
    $narrative = implode('', array_map(fn($p) => '<p>' . $e(trim($p)) . '</p>', preg_split('/\n\s*\n/', reach_report_narrative($a))));

    $tile = fn($label, $value, $accent = '#1D356A') => "<td class='tile'><div class='tile-v' style='color:{$accent}'>{$value}</div><div class='tile-l'>{$label}</div></td>";

    $campaign_rows = '';
    foreach ($a['campaigns_table'] as $c) {
        $campaign_rows .= '<tr><td>' . $e($c['title']) . '</td><td>' . ($c['date'] ? $e(date('j M Y', strtotime($c['date']))) : '—') . '</td><td class="n">'
            . $c['souls'] . '</td><td class="n">' . $c['follow_up_rate'] . '%</td><td class="n">' . $c['conversion_rate'] . '%</td></tr>';
    }
    $campaign_rows = $campaign_rows ?: '<tr><td colspan="5" class="muted">No campaigns in this period.</td></tr>';

    $top5 = '';
    foreach (array_slice($a['volunteer_leaderboard'], 0, 5) as $i => $v) {
        $top5 .= '<tr><td class="n">' . ($i + 1) . '</td><td>' . $e($v['name']) . ($v['is_member'] ? '' : ' <span class="badge">Guest</span>') . '</td><td class="n">' . $v['souls'] . '</td></tr>';
    }
    $top5 = $top5 ?: '<tr><td colspan="3" class="muted">No captures in this period.</td></tr>';

    $cat_rows = array_map(fn($c) => ['label' => str_replace('_', ' ', $c['category']), 'count' => $c['count']], $a['category_breakdown']);
    $legend = '';
    foreach ($cat_rows as $i => $cat) {
        $legend .= '<tr><td><span class="sw" style="background:' . REACH_PDF_CATEGORY_COLORS[$i] . '"></span>' . $e($cat['label']) . '</td><td class="n">' . $cat['count'] . '</td><td class="n muted">' . reach_pct($cat['count'], $k['souls']) . '%</td></tr>';
    }

    $vol_rows = '';
    foreach ($a['volunteer_leaderboard'] as $i => $v) {
        $vol_rows .= '<tr><td class="n">' . ($i + 1) . '</td><td>' . $e($v['name']) . '</td><td>' . ($v['is_member'] ? 'Member' : 'Guest') . '</td><td class="n">' . $v['souls'] . '</td></tr>';
    }
    $vol_rows = $vol_rows ?: '<tr><td colspan="4" class="muted">No volunteer activity in this period.</td></tr>';

    $prayer_rows = implode('', array_map(fn($p) => '<li><b>' . $e($p['first_name']) . ':</b> ' . $e($p['prayer_request']) . '</li>', $prayers)) ?: '<li class="muted">No prayer requests from visit-ready leads in this period.</li>';
    $testimony_rows = implode('', array_map(fn($t) => '<div class="quote"><p>“' . $e($t['story'] ?: 'Welcomed into the family.') . '”</p><div class="muted">— ' . $e($t['first_name']) . ', met at ' . $e($t['campaign'] ?: 'Reach') . '</div></div>', $testimonies))
        ?: '<p class="muted">No testimonies shared for this period. Tick “Share testimony in report” on a converted lead to include it.</p>';

    $areas = $a['area_breakdown']
        ? reach_pdf_hbar_svg(array_map(fn($r) => ['label' => $r['area'], 'count' => $r['count']], $a['area_breakdown']), '#047857')
        : '<p class="muted">No addresses recorded in this period.</p>';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 34px 38px 46px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1f2937; }
        h1 { font-size: 20px; margin: 0; color: #1D356A; } h2 { font-size: 12px; color: #1D356A; margin: 18px 0 8px; text-transform: uppercase; letter-spacing: 1px; }
        .mast { border-bottom: 3px solid #D11920; padding-bottom: 10px; margin-bottom: 6px; } .mast td { vertical-align: middle; }
        .sub { color: #52514e; font-size: 10px; } .muted { color: #898781; }
        table { width: 100%; border-collapse: collapse; } .grid td, .grid th { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; } .grid .n { text-align: right; }
        .grid th { font-size: 8.5px; text-transform: uppercase; color: #52514e; background: #f3f4f6; } .n { text-align: right; }
        .tiles { border-collapse: separate; border-spacing: 6px; margin: 0 -6px; } .tile { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; width: 25%; background: #f9fafb; }
        .tile-v { font-size: 20px; font-weight: bold; } .tile-l { font-size: 8.5px; text-transform: uppercase; color: #52514e; margin-top: 2px; }
        .badge { background: #fef3c7; color: #92400e; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; }
        .sw { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 5px; }
        .quote { border-left: 3px solid #D11920; padding: 4px 10px; margin-bottom: 8px; } .quote p { margin: 0 0 3px; font-style: italic; }
        .story p { line-height: 1.55; margin: 0 0 7px; } ul { padding-left: 14px; margin: 0; } li { margin-bottom: 4px; }
    </style></head><body>
    <table class="mast"><tr><td style="width:60px">' . $logo . '</td><td><h1>Reach Ministry Report</h1>
        <div class="sub">Household of David Lekki Centre · ' . $e($range) . '</div>
        <div class="muted">Generated ' . $e(date('j M Y, g:i A')) . ' by ' . $e($generated_by) . '</div></td></tr></table>

    <table class="tiles"><tr>'
        . $tile('Souls captured', $k['souls'])
        . $tile('Campaigns run', $k['campaigns'])
        . $tile('Follow-up rate', $k['follow_up_rate'] . '%')
        . $tile('Visited church', $k['conversion_rate'] . '%')
    . '</tr></table>'
    . ($k['overdue'] > 0 ? '<p style="color:#d03b3b;font-weight:bold;margin:2px 0 0">! ' . $k['overdue'] . ' assigned lead(s) overdue for a first follow-up (&gt;' . reach_overdue_days($pdo) . ' days)</p>' : '')
    . '<h2>Follow-up funnel</h2>' . reach_pdf_funnel_svg($a['funnel'])
    . '<h2>Campaigns</h2><table class="grid"><tr><th>Campaign</th><th>Date</th><th class="n">Souls</th><th class="n">Follow-up</th><th class="n">Visited church</th></tr>' . $campaign_rows . '</table>
    <h2>Top 5 capturers</h2><table class="grid"><tr><th class="n" style="width:24px">#</th><th>Volunteer</th><th class="n">Souls</th></tr>' . $top5 . '</table>

    <div style="page-break-before: always"></div>
    <h2 style="margin-top:0">Category breakdown</h2>
    <p class="muted" style="margin:-4px 0 8px">A person can be in more than one category; % is of all souls captured.</p>
    <table><tr><td style="width:62%">' . reach_pdf_hbar_svg($cat_rows, REACH_PDF_CATEGORY_COLORS) . '</td><td style="vertical-align:middle"><table class="grid">' . $legend . '</table></td></tr></table>
    <h2>Top areas</h2>' . $areas . '
    <h2>Volunteer activity</h2><table class="grid"><tr><th class="n" style="width:24px">#</th><th>Volunteer</th><th>Type</th><th class="n">Souls</th></tr>' . $vol_rows . '</table>
    <h2>The story of the month</h2><div class="story">' . $narrative . '</div>
    <h2>Prayer requests</h2><ul>' . $prayer_rows . '</ul>
    <h2>Testimonies</h2>' . $testimony_rows . '
    </body></html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->getCanvas()->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {
        $f = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $canvas->text(250, 822, 'Household of David Lekki Centre  |  Reach  |  Page ' . $pageNumber . ' of ' . $pageCount, $f, 7.5, [0.39, 0.44, 0.55]);
    });
    return $dompdf->output();
}
