<?php
// /includes/assimilation_report_pdf.php
// Two-page Assimilation report (dompdf). Charts are SVG data-URI images,
// which dompdf renders natively; Chart.js canvases can't be used here.
//
// Palette: the same emerald ordinal ramp and categorical hues the Reach
// report and the Analytics tab use, checked with the dataviz palette
// validator against a light surface. The two-series hues carry a contrast
// WARN on the green, so every bar is directly labelled and the same numbers
// also appear in a table — the relief the validator asks for.

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/assimilation_helpers.php';

const ASSIM_PDF_FUNNEL_COLORS = ['#10b981', '#059669', '#047857', '#064e3b'];
const ASSIM_PDF_BAR_COLOR     = '#047857';
const ASSIM_PDF_SERIES_COLORS = ['#2a78d6', '#1baf7a']; // cases opened · returned home

function assim_pdf_esc($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function assim_pdf_svg(string $svg, int $w, int $h): string {
    return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" width="' . $w . '" height="' . $h . '">';
}

function assim_pdf_funnel_svg(array $funnel): string {
    $w = 480; $h = 150;
    $max = max(1, ...array_column($funnel, 'count'));
    $bw = 92; $gap = 22; $x = 20;
    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'>";
    $s .= "<line x1='20' y1='120' x2='" . ($w - 20) . "' y2='120' stroke='#e1e0d9' stroke-width='1'/>";
    foreach ($funnel as $i => $f) {
        $bh = max(2, (int) round(100 * $f['count'] / $max));
        $y  = 120 - $bh;
        $s .= "<rect x='{$x}' y='{$y}' width='{$bw}' height='{$bh}' rx='4' fill='" . ASSIM_PDF_FUNNEL_COLORS[$i] . "'/>";
        $s .= "<text x='" . ($x + $bw / 2) . "' y='" . ($y - 6) . "' font-family='DejaVu Sans' font-size='13' font-weight='bold' fill='#0b0b0b' text-anchor='middle'>{$f['count']}</text>";
        $s .= "<text x='" . ($x + $bw / 2) . "' y='138' font-family='DejaVu Sans' font-size='8.5' fill='#52514e' text-anchor='middle'>" . assim_pdf_esc($f['stage']) . "</text>";
        $x += $bw + $gap;
    }
    return assim_pdf_svg($s . '</svg>', $w, $h);
}

// Horizontal bars, one or two series per row, always directly labelled.
function assim_pdf_hbar_svg(array $rows, bool $two_series = false): string {
    $row   = $two_series ? 26 : 18;
    $w     = 480; $label = 150;
    $h     = max(1, count($rows)) * $row + 6;
    $max   = max(1, ...(array_column($rows, 'count') ?: [1]));
    $plot  = $w - $label - 46;
    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'>";
    foreach ($rows as $i => $r) {
        $y    = $i * $row + 3;
        $name = mb_strimwidth((string) $r['label'], 0, 24, '…');
        $s .= "<text x='" . ($label - 8) . "' y='" . ($y + ($two_series ? 9 : 11)) . "' font-family='DejaVu Sans' font-size='9' fill='#52514e' text-anchor='end'>" . assim_pdf_esc($name) . "</text>";
        if ($two_series) {
            // 2px surface gap between the two fills, per the mark spec.
            foreach ([['count', ASSIM_PDF_SERIES_COLORS[0], 0], ['returned', ASSIM_PDF_SERIES_COLORS[1], 11]] as [$key, $fill, $dy]) {
                $bw = max(2, (int) round($plot * (int) ($r[$key] ?? 0) / $max));
                $s .= "<rect x='{$label}' y='" . ($y + $dy) . "' width='{$bw}' height='9' rx='3' fill='{$fill}'/>";
                $s .= "<text x='" . ($label + $bw + 5) . "' y='" . ($y + $dy + 8) . "' font-family='DejaVu Sans' font-size='8' font-weight='bold' fill='#0b0b0b'>" . (int) ($r[$key] ?? 0) . "</text>";
            }
        } else {
            $bw = max(2, (int) round($plot * (int) $r['count'] / $max));
            $s .= "<rect x='{$label}' y='{$y}' width='{$bw}' height='" . ($row - 5) . "' rx='3' fill='" . ASSIM_PDF_BAR_COLOR . "'/>";
            $s .= "<text x='" . ($label + $bw + 5) . "' y='" . ($y + 11) . "' font-family='DejaVu Sans' font-size='9' font-weight='bold' fill='#0b0b0b'>" . (int) $r['count'] . "</text>";
        }
    }
    return assim_pdf_svg($s . '</svg>', $w, $h);
}

// Returned home by month: one series, 2px line, 8px markers, no legend.
function assim_pdf_trend_svg(array $trend): string {
    $w = 480; $h = 140; $left = 26; $bottom = 112;
    $max = max(1, ...array_column($trend, 'count'));
    $step = (int) floor(($w - $left - 20) / max(1, count($trend) - 1));
    $s = "<svg xmlns='http://www.w3.org/2000/svg' width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'>";
    $s .= "<line x1='{$left}' y1='{$bottom}' x2='" . ($w - 14) . "' y2='{$bottom}' stroke='#e1e0d9' stroke-width='1'/>";
    $s .= "<text x='" . ($left - 6) . "' y='22' font-family='DejaVu Sans' font-size='8' fill='#898781' text-anchor='end'>{$max}</text>";
    $points = [];
    foreach ($trend as $i => $t) {
        $x = $left + $i * $step;
        $y = $bottom - (int) round(($bottom - 18) * $t['count'] / $max);
        $points[] = "{$x},{$y}";
        $s .= "<text x='{$x}' y='128' font-family='DejaVu Sans' font-size='7.5' fill='#52514e' text-anchor='middle'>" . assim_pdf_esc($t['label']) . "</text>";
    }
    $s .= "<polyline points='" . implode(' ', $points) . "' fill='none' stroke='" . ASSIM_PDF_BAR_COLOR . "' stroke-width='2'/>";
    foreach ($trend as $i => $t) {
        [$x, $y] = explode(',', $points[$i]);
        $s .= "<circle cx='{$x}' cy='{$y}' r='4' fill='" . ASSIM_PDF_BAR_COLOR . "' stroke='#ffffff' stroke-width='2'/>";
        if ($t['count'] > 0) {
            $s .= "<text x='{$x}' y='" . ($y - 8) . "' font-family='DejaVu Sans' font-size='8' font-weight='bold' fill='#0b0b0b' text-anchor='middle'>{$t['count']}</text>";
        }
    }
    return assim_pdf_svg($s . '</svg>', $w, $h);
}

function assim_report_narrative(array $a): string {
    $k     = $a['kpis'];
    $range = date('j M Y', strtotime($a['range']['from'])) . ' – ' . date('j M Y', strtotime($a['range']['to']));
    $median = $k['median_days'] === null ? 'not yet measurable' : $k['median_days'] . ' day(s)';

    $fallback = "Between {$range}, {$k['drifted']} of our people were on an Assimilation watchlist, and the team opened "
        . "{$k['cases_opened']} new follow-up(s). We reached out to {$k['contacted']} of them for the first time, "
        . "typically within {$median} of being asked, and {$k['returned_home']} came home — a return rate of {$k['return_rate']}%.\n\n"
        . ($k['overdue'] > 0
            ? "{$k['overdue']} person(s) have been assigned to a volunteer but have not yet been contacted. Let us close that "
              . 'gap this week, so nobody who has drifted is left waiting for a call that never comes.'
            : 'Every assigned person has been contacted on time. Thank you for your faithfulness — keep going after the one.');

    // Aggregates only: no names or phone numbers leave the server.
    $metrics = [
        'kpis'        => $k,
        'funnel'      => $a['funnel'],
        'by_status'   => $a['status_breakdown'],
        'by_department' => array_slice($a['department_breakdown'], 0, 5),
        'returned_by_month' => array_map(fn($t) => [$t['label'] => $t['count']], array_slice($a['returned_trend'], -6)),
    ];
    $prompt = "These are the monthly numbers for the Assimilation ministry of Household of David Lekki Centre, which brings "
        . "people who have drifted away from church back home, for {$range}. Write 2 short paragraphs summarising the month: "
        . "the story, the wins, and the gaps. Warm pastoral tone, about 150 words. Never name or imply an individual. "
        . "Plain text only, no headings or markdown.\n\n" . json_encode($metrics);

    return reach_gemini($prompt, 0.4) ?? $fallback;
}

// Returns the PDF bytes.
function assim_build_report_pdf(PDO $pdo, array $a, string $generated_by): string {
    $lo = $a['range']['from'] . ' 00:00:00';
    $hi = (new DateTime($a['range']['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    // First names only — this report is printed and passed around a meeting.
    $stmt = $pdo->prepare("
        SELECT u.first_name, c.returned_home_at, c.last_attended_on,
               TRIM(CONCAT_WS(' ', vu.first_name, vu.last_name)) AS volunteer,
               (SELECT COUNT(*) FROM assimilation_follow_ups f WHERE f.case_id = c.id) AS touches
          FROM assimilation_cases c
          JOIN users u ON u.id = c.user_id
          LEFT JOIN users vu ON vu.id = c.assigned_to
         WHERE c.returned_home_at >= ? AND c.returned_home_at < ?
         ORDER BY c.returned_home_at DESC
         LIMIT 40
    ");
    $stmt->execute([$lo, $hi]);
    $home = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prayer points carry what people trusted a volunteer with, so this list
    // is first-name-only too.
    $stmt = $pdo->prepare("
        SELECT u.first_name, f.prayer_points
          FROM assimilation_follow_ups f
          JOIN assimilation_cases c ON c.id = f.case_id
          JOIN users u ON u.id = c.user_id
         WHERE f.created_at >= ? AND f.created_at < ? AND TRIM(COALESCE(f.prayer_points, '')) <> ''
         ORDER BY f.id DESC LIMIT 8
    ");
    $stmt->execute([$lo, $hi]);
    $prayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $e     = 'assim_pdf_esc';
    $k     = $a['kpis'];
    $range = date('j M Y', strtotime($a['range']['from'])) . ' – ' . date('j M Y', strtotime($a['range']['to']));

    $logo_path = __DIR__ . '/../assets/images/logo_hod.png';
    $logo = is_file($logo_path) ? '<img src="data:image/png;base64,' . base64_encode((string) file_get_contents($logo_path)) . '" width="52" height="52">' : '';
    $narrative = implode('', array_map(fn($p) => '<p>' . $e(trim($p)) . '</p>', preg_split('/\n\s*\n/', assim_report_narrative($a)) ?: []));

    $tile = fn($label, $value, $accent = '#1D356A') => "<td class='tile'><div class='tile-v' style='color:{$accent}'>{$value}</div><div class='tile-l'>{$label}</div></td>";

    $home_rows = '';
    foreach ($home as $h) {
        $home_rows .= '<tr><td>' . $e($h['first_name']) . '</td><td>' . $e(date('j M', strtotime((string) $h['returned_home_at']))) . '</td><td>'
            . $e($h['volunteer'] ?: 'Unassigned') . '</td><td class="n">' . (int) $h['touches'] . '</td><td>'
            . $e($h['last_attended_on'] ? str_replace(' ago', '', assim_since_words($h['last_attended_on'])) : 'no earlier record') . '</td></tr>';
    }
    $home_rows = $home_rows ?: '<tr><td colspan="5" class="muted">Nobody came home in this period. Keep calling — the numbers follow the faithfulness.</td></tr>';

    $vol_rows = '';
    foreach ($a['volunteer_leaderboard'] as $i => $v) {
        $vol_rows .= '<tr><td class="n">' . ($i + 1) . '</td><td>' . $e($v['name']) . '</td><td class="n">' . $v['contacts'] . '</td><td class="n">' . $v['returned'] . '</td></tr>';
    }
    $vol_rows = $vol_rows ?: '<tr><td colspan="4" class="muted">No volunteer activity in this period.</td></tr>';

    $watch_rows = '';
    foreach ($a['watchlists'] as $w) {
        $watch_rows .= '<tr><td>' . $e($w['name']) . ($w['is_active'] ? '' : ' <span class="badge">Paused</span>') . '</td><td class="muted">'
            . $e($w['rule']) . '</td><td class="n">' . $w['people'] . '</td><td class="n">' . $w['new_in_range'] . '</td></tr>';
    }
    $watch_rows = $watch_rows ?: '<tr><td colspan="4" class="muted">No watchlists saved yet.</td></tr>';

    $status_rows = '';
    foreach ($a['status_breakdown'] as $s) {
        $status_rows .= '<tr><td>' . $e($s['label']) . '</td><td class="n">' . $s['count'] . '</td><td class="n">' . $s['returned'] . '</td></tr>';
    }
    $status_rows = $status_rows ?: '<tr><td colspan="3" class="muted">No follow-ups opened in this period.</td></tr>';

    $status_chart = $a['status_breakdown'] ? assim_pdf_hbar_svg($a['status_breakdown'], true) : '<p class="muted">No follow-ups opened in this period.</p>';
    $dept_chart   = $a['department_breakdown'] ? assim_pdf_hbar_svg($a['department_breakdown'], true) : '<p class="muted">Nobody in this period belonged to a department.</p>';

    $prayer_rows = implode('', array_map(
        fn($p) => '<li><b>' . $e($p['first_name']) . ':</b> ' . $e($p['prayer_points']) . '</li>',
        $prayers
    )) ?: '<li class="muted">No prayer points were recorded in this period.</li>';

    $median_label = $k['median_days'] === null ? '—' : $k['median_days'] . 'd';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 34px 38px 46px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1f2937; }
        h1 { font-size: 20px; margin: 0; color: #1D356A; }
        h2 { font-size: 12px; color: #1D356A; margin: 18px 0 8px; text-transform: uppercase; letter-spacing: 1px; }
        .mast { border-bottom: 3px solid #D11920; padding-bottom: 10px; margin-bottom: 6px; } .mast td { vertical-align: middle; }
        .sub { color: #52514e; font-size: 10px; } .muted { color: #898781; }
        table { width: 100%; border-collapse: collapse; }
        .grid td, .grid th { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .grid th { font-size: 8.5px; text-transform: uppercase; color: #52514e; background: #f3f4f6; }
        .n { text-align: right; }
        .tiles { border-collapse: separate; border-spacing: 6px; margin: 0 -6px; }
        .tile { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; width: 20%; background: #f9fafb; }
        .tile-v { font-size: 19px; font-weight: bold; } .tile-l { font-size: 8px; text-transform: uppercase; color: #52514e; margin-top: 2px; }
        .badge { background: #fef3c7; color: #92400e; padding: 1px 4px; border-radius: 3px; font-size: 7.5px; }
        .key { font-size: 8px; color: #52514e; margin: 2px 0 6px; }
        .sw { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin: 0 4px 0 10px; }
        .story p { line-height: 1.55; margin: 0 0 7px; }
        ul { padding-left: 14px; margin: 0; } li { margin-bottom: 4px; }
    </style></head><body>
    <table class="mast"><tr><td style="width:60px">' . $logo . '</td><td><h1>Assimilation Report</h1>
        <div class="sub">Household of David Lekki Centre · ' . $e($range) . '</div>
        <div class="muted">Bringing those who have drifted back home · generated ' . $e(date('j M Y, g:i A')) . ' by ' . $e($generated_by) . '</div></td></tr></table>

    <table class="tiles"><tr>'
        . $tile('On a watchlist', $k['drifted'])
        . $tile('Followed up', $k['contacted'])
        . $tile('Returned home', $k['returned_home'], '#047857')
        . $tile('Return rate', $k['return_rate'] . '%', '#047857')
        . $tile('Median days to 1st call', $median_label)
    . '</tr></table>'
    . ($k['overdue'] > 0 ? '<p style="color:#d03b3b;font-weight:bold;margin:2px 0 0">! ' . $k['overdue'] . ' person(s) assigned more than ' . assim_overdue_days($pdo) . ' days ago with no contact yet</p>' : '')
    . '<h2>The journey home</h2>' . assim_pdf_funnel_svg($a['funnel'])
    . '<h2>Returned home by month</h2>' . assim_pdf_trend_svg($a['returned_trend'])
    . '<h2>Who came home</h2><table class="grid"><tr><th>Name</th><th>Came back</th><th>Volunteer who called</th><th class="n">Calls</th><th>Away for</th></tr>'
    . $home_rows . '</table>

    <div style="page-break-before: always"></div>
    <h2 style="margin-top:0">By spiritual status</h2>
    <p class="key"><span class="sw" style="background:' . ASSIM_PDF_SERIES_COLORS[0] . '"></span>Followed up<span class="sw" style="background:' . ASSIM_PDF_SERIES_COLORS[1] . '"></span>Returned home</p>
    <table><tr><td style="width:62%">' . $status_chart . '</td><td style="vertical-align:middle"><table class="grid"><tr><th>Status</th><th class="n">Up</th><th class="n">Home</th></tr>' . $status_rows . '</table></td></tr></table>
    <h2>By department</h2>
    <p class="key"><span class="sw" style="background:' . ASSIM_PDF_SERIES_COLORS[0] . '"></span>Followed up<span class="sw" style="background:' . ASSIM_PDF_SERIES_COLORS[1] . '"></span>Returned home</p>
    ' . $dept_chart . '
    <h2>Volunteer activity</h2><table class="grid"><tr><th class="n" style="width:24px">#</th><th>Volunteer</th><th class="n">Contacts</th><th class="n">Returned home</th></tr>' . $vol_rows . '</table>
    <h2>Watchlists</h2><table class="grid"><tr><th>Watchlist</th><th>Rule</th><th class="n">People</th><th class="n">New</th></tr>' . $watch_rows . '</table>
    <h2>The story of the month</h2><div class="story">' . $narrative . '</div>
    <h2>Prayer points from the calls</h2><ul>' . $prayer_rows . '</ul>
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
        $canvas->text(228, 822, 'Household of David Lekki Centre  |  Assimilation  |  Page ' . $pageNumber . ' of ' . $pageCount, $f, 7.5, [0.39, 0.44, 0.55]);
    });
    return $dompdf->output();
}
