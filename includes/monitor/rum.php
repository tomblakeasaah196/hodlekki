<?php
// /includes/monitor/rum.php
/**
 * Real-user speed: Core Web Vitals from members' own browsers, phones and
 * computers apart (docs/monitor.md, "Real-user speed").
 *
 * assets/js/monitor.js measures each page view (LCP, INP, CLS, TTFB, FCP) and
 * sends one report when the page is hidden. The intake checks it and spools it;
 * the cron stores it in monitor_rum (14 days) and rolls it up hourly into
 * monitor_rum_daily: per day, page, device and metric, the 75th percentile and
 * how many views were good / need improvement / poor (web.dev thresholds).
 *
 * No personal data: the page is its route (/modules/events/index.php, /e/:event),
 * the device a class (phone, tablet, desktop), the connection its type (4g, 3g).
 */

require_once __DIR__ . '/store.php';

const MONITOR_RUM_METRICS = ['lcp', 'inp', 'cls', 'ttfb', 'fcp'];
const MONITOR_RUM_MAX     = ['lcp' => 120000, 'inp' => 60000, 'cls' => 100000, 'ttfb' => 120000, 'fcp' => 120000];

/** A browser's report as a row for monitor_rum, or null when it is not usable. */
function monitor_rum_row(array $rum, string $page, string $device, int $at): ?array
{
    $row = ['at' => monitor_fmt($at), 'page' => mb_substr(monitor_clean_url($page)[1], 0, 190),
            'device' => in_array($device, ['phone', 'tablet', 'desktop'], true) ? $device : 'desktop',
            'conn' => null, 'nav' => null, 'release_id' => monitor_release_id()];
    $any = false;
    foreach (MONITOR_RUM_METRICS as $m) {
        $v = $rum[$m] ?? null;
        if (is_numeric($v) && $v >= 0 && $v <= MONITOR_RUM_MAX[$m]) {
            $row[$m] = (int) round((float) $v);
            $any = true;
        } else {
            $row[$m] = null;
        }
    }
    if (!$any) {
        return null;
    }
    if (isset($rum['conn']) && preg_match('/^(slow-2g|2g|3g|4g|5g|wifi|ethernet)$/', (string) $rum['conn'])) {
        $row['conn'] = (string) $rum['conn'];
    }
    if (isset($rum['nav']) && in_array($rum['nav'], ['navigate', 'reload', 'back_forward', 'prerender', 'restore'], true)) {
        $row['nav'] = (string) $rum['nav'];
    }

    return $row;
}

function monitor_rum_store(PDO $db, array $rows): int
{
    $stmt = $db->prepare('INSERT INTO monitor_rum (at, page, device, conn, nav, lcp, inp, cls, ttfb, fcp, release_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($rows as $r) {
        if (!isset($r['page'], $r['device'], $r['at'])) {
            continue;
        }
        $stmt->execute([$r['at'], $r['page'], $r['device'], $r['conn'] ?? null, $r['nav'] ?? null,
                        $r['lcp'] ?? null, $r['inp'] ?? null, $r['cls'] ?? null, $r['ttfb'] ?? null, $r['fcp'] ?? null, $r['release_id'] ?? null]);
        $n++;
    }

    return $n;
}

/** The 75th percentile (nearest rank), as web.dev reports Core Web Vitals. */
function monitor_p75(array $values): ?int
{
    $values = array_values(array_filter($values, fn ($v) => $v !== null));
    if (!$values) {
        return null;
    }
    sort($values);

    return (int) $values[max(0, (int) ceil(0.75 * count($values)) - 1)];
}

/**
 * Recompute yesterday's and today's daily rows from the raw reports, the
 * 7-day summary the health checks read, and drop raw reports over 14 days old.
 */
function monitor_rum_rollup(PDO $db, ?int $now = null): void
{
    $now = $now ?? monitor_time();
    foreach ([$now - 86400, $now] as $t) {
        $day = monitor_fmt($t, 'Y-m-d');
        $stmt = $db->prepare('SELECT page, device, lcp, inp, cls, ttfb, fcp FROM monitor_rum WHERE at >= ? AND at < ?');
        $stmt->execute([$day . ' 00:00:00', monitor_fmt(monitor_ts($day . ' 00:00:00') + 86400)]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach ([$r['page'], '*'] as $page) {
                foreach (MONITOR_RUM_METRICS as $m) {
                    if ($r[$m] !== null) {
                        $groups[$page][$r['device']][$m][] = (int) $r[$m];
                    }
                }
            }
        }
        $db->prepare('DELETE FROM monitor_rum_daily WHERE day = ?')->execute([$day]);
        $ins = $db->prepare('INSERT INTO monitor_rum_daily (day, page, device, metric, n, p75, good, ni, poor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($groups as $page => $byDevice) {
            foreach ($byDevice as $device => $byMetric) {
                foreach ($byMetric as $m => $values) {
                    $count = ['good' => 0, 'ni' => 0, 'poor' => 0];
                    foreach ($values as $v) {
                        $count[monitor_vital_rating_of($m, $v)]++;
                    }
                    $ins->execute([$day, mb_substr((string) $page, 0, 190), $device, $m, count($values), monitor_p75($values), $count['good'], $count['ni'], $count['poor']]);
                }
            }
        }
    }
    $db->prepare('DELETE FROM monitor_rum WHERE at < ?')->execute([monitor_fmt($now - 14 * 86400)]);
    monitor_state_set($db, 'rum_summary', (string) json_encode(monitor_rum_compute_summary($db, 7, $now)));
}

/** web.dev ratings (also in checks.php; repeated here so this file stands alone). */
function monitor_vital_rating_of(string $metric, int $value): string
{
    $limits = ['lcp' => [2500, 4000], 'inp' => [200, 500], 'cls' => [100, 250], 'ttfb' => [800, 1800], 'fcp' => [1800, 3000]][$metric];

    return $value <= $limits[0] ? 'good' : ($value > $limits[1] ? 'poor' : 'ni');
}

/** Per device over $days: visits measured, p75 of each metric, the slowest page by LCP. */
function monitor_rum_compute_summary(PDO $db, int $days = 7, ?int $now = null): array
{
    $now = $now ?? monitor_time();
    $stmt = $db->prepare('SELECT page, device, lcp, inp, cls, ttfb FROM monitor_rum WHERE at >= ?');
    $stmt->execute([monitor_fmt($now - $days * 86400)]);
    $by = [];
    $pages = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $r['device'] === 'tablet' ? 'phone' : $r['device'];   // tablets travel with phones: touch, mobile networks
        $by[$d]['n'] = ($by[$d]['n'] ?? 0) + 1;
        foreach (['lcp', 'inp', 'cls', 'ttfb'] as $m) {
            if ($r[$m] !== null) {
                $by[$d][$m][] = (int) $r[$m];
            }
        }
        if ($r['lcp'] !== null) {
            $pages[$d][$r['page']][] = (int) $r['lcp'];
        }
    }
    $out = [];
    foreach ($by as $d => $v) {
        $row = ['n' => $v['n']];
        foreach (['lcp', 'inp', 'cls', 'ttfb'] as $m) {
            if (!empty($v[$m])) {
                $row[$m] = monitor_p75($v[$m]);
            }
        }
        $worst = null;
        foreach ($pages[$d] ?? [] as $page => $lcps) {
            if (count($lcps) >= 10) {
                $p = monitor_p75($lcps);
                if ($worst === null || $p > $worst[1]) {
                    $worst = [$page, $p];
                }
            }
        }
        if ($worst !== null) {
            $row['worst_page'] = $worst[0] . ' (LCP ' . number_format($worst[1] / 1000, 1) . ' s)';
        }
        $out[$d] = $row;
    }

    return $out;
}

/** The stored 7-day summary (computed hourly), or a fresh one when there is none. */
function monitor_rum_summary(PDO $db, int $days = 7): array
{
    $cached = json_decode((string) monitor_state_get($db, 'rum_summary'), true);
    if (is_array($cached)) {
        return $cached;
    }

    return monitor_rum_compute_summary($db, $days);
}

/** Per page for one device over $days, from the daily rows: the Speed screen's table. */
function monitor_rum_pages(PDO $db, int $days, string $device, int $limit = 40): array
{
    $stmt = $db->prepare('SELECT page, metric, SUM(n) AS n, SUM(good) AS good, SUM(ni) AS ni, SUM(poor) AS poor, MAX(p75) AS p75_max
                            FROM monitor_rum_daily WHERE day >= ? AND device = ? AND page <> ? GROUP BY page, metric');
    $stmt->execute([monitor_fmt(monitor_time() - $days * 86400, 'Y-m-d'), $device, '*']);
    $pages = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pages[$r['page']]['page'] = $r['page'];
        $pages[$r['page']][$r['metric']] = [
            'n' => (int) $r['n'],
            'good_share' => $r['n'] > 0 ? round(100 * $r['good'] / $r['n']) : null,
            'poor_share' => $r['n'] > 0 ? round(100 * $r['poor'] / $r['n']) : null,
        ];
        $pages[$r['page']]['views'] = max($pages[$r['page']]['views'] ?? 0, (int) $r['n']);
    }
    usort($pages, fn ($a, $b) => $b['views'] <=> $a['views']);

    return array_slice($pages, 0, $limit);
}
