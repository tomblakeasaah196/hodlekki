<?php
// /includes/monitor/alerts.php
/**
 * Telling people (docs/monitor.md, "Alerts").
 *
 * Two channels:
 *   - The bell (and so a push notification, through the normal fan-out) of every
 *     active Super_Admin: a system_notifications row each. Never a direct push:
 *     AGENTS.md, "Every INSERT INTO system_notifications is already a push".
 *   - Email, through hod_send_mail(), to the addresses in monitor_recipients that
 *     their owners CONFIRMED by link. Every email carries a personal stop link.
 *
 * What is sent, and how often (one bug must never send a storm):
 *   monitor_alert_new_errors()  ONE message per cron run listing every new or
 *                               returning problem: server fatal/error at once;
 *                               a browser error once it has reached 3 people;
 *                               warnings, CSP reports and slow pages only in the
 *                               morning summary
 *   monitor_alert_spikes()      a problem suddenly far more frequent (store.php), at most every 6 h
 *   monitor_alert_health()      an urgent check turning red (then every 6 h while red), and recoveries
 *   monitor_alert_digest()      the morning summary, once a day at 08:00 Lagos (monitor_state digest_hour)
 *   monitor_watchdog()          from page requests: the monitor's own cron has stopped
 * Caps: 6 alert emails and 3 bells an hour; what does not fit waits for the next run.
 *
 * Recipients: monitor_recipient_add() (pending + confirmation email),
 * monitor_recipient_confirm(), monitor_recipient_stop(), monitor_recipient_remove().
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/brief.php';

const MONITOR_EMAILS_PER_HOUR = 6;
const MONITOR_BELLS_PER_HOUR  = 3;

/** The monitor's screen, relative (bells) or absolute (emails); the home page until the screen exists. */
function monitor_screen_link(string $fragment = '', bool $absolute = false): string
{
    $path = is_file(monitor_root() . '/modules/monitor/index.php') ? '/modules/monitor/index.php' . $fragment : '/index.php';

    return $absolute ? site_url($path) : $path;
}

/* ───────────────────────────── the bell ───────────────────────────── */

/** Active Super_Admins (live roles, frozen roles excluded, suspended accounts left out). */
function monitor_super_admin_ids(PDO $pdo): array
{
    $base = "SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE r.role_name = 'Super_Admin' AND COALESCE(ur.is_frozen, 0) = 0";
    try {
        $ids = $pdo->query("SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id
                             WHERE r.role_name = 'Super_Admin' AND COALESCE(ur.is_frozen, 0) = 0
                               AND COALESCE(u.account_status, 'active') = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        try {
            $ids = $pdo->query($base)->fetchAll(PDO::FETCH_COLUMN);   // before the security migration
        } catch (Throwable $e2) {
            return [];
        }
    }

    return array_values(array_unique(array_map('intval', $ids)));
}

/** How many alerts went out on a channel in the last hour (confirmations and summaries aside). */
function monitor_alerts_last_hour(PDO $db, string $channel): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM monitor_alert_log WHERE channel = ? AND ok = 1 AND at >= ? AND kind NOT IN ('confirm', 'digest')");
    $stmt->execute([$channel, monitor_fmt(monitor_time() - 3600)]);

    return (int) $stmt->fetchColumn();
}

function monitor_alert_log(PDO $db, string $channel, string $kind, string $subject, int $recipients, bool $ok, ?string $error = null): void
{
    $db->prepare('INSERT INTO monitor_alert_log (at, channel, kind, subject, recipients, ok, error) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([monitor_fmt(), $channel, $kind, mb_substr($subject, 0, 255), $recipients, $ok ? 1 : 0, $error !== null ? mb_substr($error, 0, 255) : null]);
}

/**
 * Text that is safe anywhere: no markup, no quotes, no backticks, one line.
 * The bell (includes/header.php) has rendered notification text as HTML, and an
 * error's text can come from anyone (a browser beacon, a crafted request), so
 * everything the monitor writes there goes through this, whatever its source.
 */
function monitor_plain(string $text, int $max = 500): string
{
    $s = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $s = str_replace(['<', '>', '"', '`', '&', '\\'], ' ', $s);
    $s = str_replace("'", '’', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

    return mb_substr($s, 0, $max);
}

/** A problem's title as it may appear in a bell or an email subject: never text a browser sent. */
function monitor_safe_title(array $g, int $max = 120): string
{
    if (in_array($g['kind'] ?? '', ['js', 'csp'], true)) {
        return ($g['kind'] === 'csp' ? 'A Content-Security-Policy report' : 'A browser error') . ' on ' . monitor_plain((string) ($g['route'] ?: 'a page'), 80);
    }

    return monitor_plain((string) $g['title'], $max);
}

/** A bell for each active Super_Admin. Returns how many were written. */
function monitor_notify_admins(PDO $pdo, PDO $db, string $kind, string $title, string $message, string $link, bool $capped = true): int
{
    if ($capped && monitor_alerts_last_hour($db, 'bell') >= MONITOR_BELLS_PER_HOUR) {
        return 0;
    }
    $ids = monitor_super_admin_ids($pdo);
    $title = monitor_plain($title, 150);
    $message = monitor_plain($message, 500);
    $n = 0;
    try {
        $stmt = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
        foreach ($ids as $id) {
            $stmt->execute([$id, $title, $message, $link]);
            $n++;
        }
        monitor_alert_log($db, 'bell', $kind, $title, $n, $n > 0);
    } catch (Throwable $e) {
        monitor_alert_log($db, 'bell', $kind, $title, $n, false, monitor_redact($e->getMessage()));
    }

    return $n;
}

/* ───────────────────────────── email ───────────────────────────── */

/**
 * The monitor's ONE way to send an email. Returns ['ok' => bool, 'error' => ?string].
 *
 * Today: hod_send_mail() (includes/mailer.php). When the campaigns outbox lands
 * (includes/campaigns/outbox.php: one queue and one hourly budget for the whole
 * domain, with lanes so alerts always get through), this is the only place to
 * change: send with email_send_now($db, …, ['lane' => $kind === 'confirm' ?
 * 'transactional' : 'alert']) so the monitor's mail counts in that budget. The
 * monitor's own caps (6 alert emails an hour) stay as they are.
 */
function monitor_mail(PDO $db, string $to, string $subject, string $html, string $text, string $kind): array
{
    require_once dirname(__DIR__) . '/mailer.php';
    if (!function_exists('hod_send_mail')) {
        return ['ok' => false, 'error' => 'includes/mailer.php is missing'];
    }

    return hod_send_mail($to, $subject, $html, $text);
}

function monitor_recipients_active(PDO $db): array
{
    return $db->query("SELECT id, email, added_at FROM monitor_recipients WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

/** The personal, signed stop link of a recipient (no table column: derived from the monitor's key). */
function monitor_stop_token(array $recipient): string
{
    return (int) $recipient['id'] . '.' . substr(hash_hmac('sha256', 'stop|' . (int) $recipient['id'] . '|' . strtolower((string) $recipient['email']), monitor_secret()), 0, 32);
}

/** A plain, well-mannered HTML email in the church's colours. Every value in $rows is escaped here. */
function monitor_email_html(string $heading, string $intro, array $sections, ?string $buttonUrl, ?string $buttonText, ?string $stopUrl): string
{
    $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f3f5f9;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#0A0E17">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #dfe3ec;border-radius:12px">'
        . '<tr><td style="background:#0A0E17;border-radius:12px 12px 0 0;padding:16px 24px;color:#ffffff;font-size:13px;letter-spacing:.04em">'
        . '<span style="display:inline-block;width:8px;height:8px;border-radius:4px;background:#D11920;margin-right:8px"></span>HOD LEKKI · SYSTEM MONITOR</td></tr>'
        . '<tr><td style="padding:24px 24px 8px"><h1 style="margin:0 0 8px;font-size:20px;line-height:1.3;color:#1D356A">' . $e($heading) . '</h1>'
        . '<p style="margin:0;font-size:15px;line-height:1.5;color:#3a4256">' . $e($intro) . '</p></td></tr>';
    foreach ($sections as $title => $rows) {
        if (!$rows) {
            continue;
        }
        $html .= '<tr><td style="padding:16px 24px 0"><h2 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#69728a">' . $e($title) . '</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">';
        foreach ($rows as $row) {
            $dot = ['bad' => '#D11920', 'warn' => '#c27c0e', 'ok' => '#1f8a4c', 'info' => '#1D356A'][$row['tone'] ?? 'info'] ?? '#1D356A';
            $html .= '<tr><td style="padding:8px 0;border-top:1px solid #eef0f5;vertical-align:top;width:14px"><span style="display:inline-block;width:8px;height:8px;border-radius:4px;background:' . $dot . ';margin-top:6px"></span></td>'
                . '<td style="padding:8px 0;border-top:1px solid #eef0f5;font-size:14px;line-height:1.45"><strong>' . $e($row['title']) . '</strong>'
                . (!empty($row['detail']) ? '<br><span style="color:#59627a">' . $e($row['detail']) . '</span>' : '') . '</td></tr>';
        }
        $html .= '</table></td></tr>';
    }
    if ($buttonUrl) {
        $html .= '<tr><td style="padding:20px 24px 4px"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:#1D356A;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 18px;border-radius:8px">' . $e($buttonText ?? 'Open the monitor') . '</a></td></tr>';
    }
    $html .= '<tr><td style="padding:20px 24px 24px;font-size:12px;line-height:1.5;color:#7a8296">Sent by the system monitor of ' . $e(site_host()) . '. No member\'s personal details are ever included.'
        . ($stopUrl ? ' <a href="' . $e($stopUrl) . '" style="color:#7a8296">Stop these emails</a>.' : '') . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    return $html;
}

/** The same content as plain text. */
function monitor_email_text(string $heading, string $intro, array $sections, ?string $buttonUrl, ?string $stopUrl): string
{
    $t = $heading . "\n\n" . $intro . "\n";
    foreach ($sections as $title => $rows) {
        if (!$rows) {
            continue;
        }
        $t .= "\n" . strtoupper($title) . "\n";
        foreach ($rows as $row) {
            $t .= '- ' . $row['title'] . (!empty($row['detail']) ? "\n  " . $row['detail'] : '') . "\n";
        }
    }
    if ($buttonUrl) {
        $t .= "\nOpen the monitor: " . $buttonUrl . "\n";
    }
    if ($stopUrl) {
        $t .= "\nStop these emails: " . $stopUrl . "\n";
    }

    return $t;
}

/**
 * Email every confirmed recipient (each with their own stop link). Returns
 * how many were sent. $capped: counts against the hourly limit.
 */
function monitor_email_recipients(PDO $db, string $kind, string $subject, string $heading, string $intro, array $sections, bool $capped = true): int
{
    if ($capped && monitor_alerts_last_hour($db, 'email') >= MONITOR_EMAILS_PER_HOUR) {
        return 0;
    }
    $recipients = monitor_recipients_active($db);
    if (!$recipients) {
        return 0;
    }
    $sent = 0;
    $error = null;
    $button = monitor_screen_link('', true);
    foreach ($recipients as $r) {
        $stop = site_url('/monitor_confirm.php?stop=' . rawurlencode(monitor_stop_token($r)));
        $res = monitor_mail($db, (string) $r['email'], $subject,
            monitor_email_html($heading, $intro, $sections, $button, 'Open the monitor', $stop),
            monitor_email_text($heading, $intro, $sections, $button, $stop), $kind);
        if ($res['ok']) {
            $sent++;
            $db->prepare('UPDATE monitor_recipients SET last_sent_at = ? WHERE id = ?')->execute([monitor_fmt(), $r['id']]);
        } else {
            $error = (string) $res['error'];
        }
    }
    monitor_alert_log($db, 'email', $kind, $subject, $sent, $sent > 0, $error !== null ? monitor_redact($error) : null);

    return $sent;
}

/* ───────────────────────────── what gets announced ───────────────────────────── */

/** "fatal · Undefined array key "x" · /modules/events/ · ×12, 4 people" (browser text never shown: see monitor_safe_title()) */
function monitor_alert_row(PDO $db, array $g): array
{
    $s = monitor_group_stats($db, (int) $g['id']);
    $where = $g['route'] ?: ($g['file'] ? $g['file'] . ($g['line'] ? ':' . $g['line'] : '') : '');
    $tone = in_array($g['level'], ['fatal', 'error'], true) ? 'bad' : 'warn';

    return [
        'tone'   => $tone,
        'title'  => monitor_safe_title($g, 160),
        'detail' => monitor_kind_label($g) . ($where !== '' ? ' · ' . monitor_plain((string) $where, 120) : '') . ' · ' . (int) $g['occurrences'] . '×'
            . ($s['people_24h'] ? ', ' . $s['people_24h'] . ' ' . ($s['people_24h'] === 1 ? 'person' : 'people') : '')
            . ((int) $g['regressions'] > 0 ? ' · came back after a fix' : '') . ' · #' . $g['id'],
    ];
}

/**
 * Who can be told, and whether email has room this hour. When there are
 * confirmed addresses, an alert waits until email has room, so the two
 * channels go out together and nothing is marked sent that was not emailed.
 */
function monitor_alert_channels(PDO $pdo, PDO $db): array
{
    $recipients = count(monitor_recipients_active($db));

    return [
        'recipients' => $recipients,
        'admins'     => count(monitor_super_admin_ids($pdo)),
        'email_room' => $recipients === 0 || monitor_alerts_last_hour($db, 'email') < MONITOR_EMAILS_PER_HOUR,
    ];
}

/** Did an alert reach someone (by email when there are recipients, else by the bell), or is there nobody to tell? */
function monitor_alert_delivered(array $ch, int $mailed, int $belled): bool
{
    if ($ch['recipients'] === 0 && $ch['admins'] === 0) {
        return true;
    }

    return $ch['recipients'] > 0 ? $mailed > 0 : $belled > 0;
}

/** New and returning problems worth a message now. One message for all of them. */
function monitor_alert_new_errors(PDO $pdo, PDO $db): int
{
    // Server problems and browser problems are picked separately, so browser
    // errors still waiting for their third person can never crowd a server
    // error out of the list.
    $server = $db->query(
        "SELECT * FROM monitor_error_groups
          WHERE status = 'new' AND alerted_at IS NULL AND level IN ('fatal', 'error') AND kind NOT IN ('csp', 'slow', 'js')
          ORDER BY CASE level WHEN 'fatal' THEN 0 ELSE 1 END, last_seen_at DESC LIMIT 40"
    )->fetchAll(PDO::FETCH_ASSOC);
    $browser = $db->query(
        "SELECT * FROM monitor_error_groups
          WHERE status = 'new' AND alerted_at IS NULL AND level IN ('fatal', 'error') AND kind = 'js'
          ORDER BY last_seen_at DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    $due = $server;
    $people = $db->prepare('SELECT COUNT(DISTINCT user_hash) FROM monitor_error_people WHERE group_id = ? AND day >= ?');
    foreach ($browser as $g) {
        // A browser error is announced once it has reached 3 people (or came back
        // after a fix): one phone with a flaky extension is not worth a message.
        $people->execute([$g['id'], monitor_fmt(monitor_time() - 86400, 'Y-m-d')]);
        if ((int) $g['regressions'] > 0 || (int) $people->fetchColumn() >= 3) {
            $due[] = $g;
        }
    }
    if (!$due) {
        return 0;
    }
    $ch = monitor_alert_channels($pdo, $db);
    if (!$ch['email_room']) {
        return 0;   // over the hourly email cap: everything waits for the next hour, in one message
    }
    $show = array_slice($due, 0, 10);
    $back = count(array_filter($due, fn ($g) => (int) $g['regressions'] > 0));
    $n = count($due);
    $subject = $n === 1
        ? ((int) $due[0]['regressions'] > 0 ? 'Came back: ' : 'New error: ') . monitor_safe_title($due[0], 90)
        : $n . ' new or returning errors on ' . site_host();
    $intro = $n === 1
        ? ((int) $due[0]['regressions'] > 0 ? 'A problem that was marked fixed has happened again.' : 'The site met a new problem.')
          . ' The monitor has everything needed to fix it: open it and use "Copy for AI".'
        : 'The site met ' . $n . ' problem(s) that need a look' . ($back ? ', ' . $back . ' of them back after being marked fixed' : '') . '. Each has a "Copy for AI" brief in the monitor.';
    $list = array_map(fn ($g) => monitor_alert_row($db, $g), $show);
    if ($n > count($show)) {
        $list[] = ['tone' => 'info', 'title' => '… and ' . ($n - count($show)) . ' more', 'detail' => 'All of them are in the monitor.'];
    }
    $mailed = monitor_email_recipients($db, 'errors', 'HOD Lekki: ' . $subject, $subject, $intro, ['Problems' => $list]);
    $belled = monitor_notify_admins($pdo, $db, 'errors', $n === 1 ? 'New error on the site' : $n . ' new errors on the site',
        monitor_safe_title($due[0], 120) . ($n > 1 ? ' (+' . ($n - 1) . ' more)' : '') . '. Open the monitor.', monitor_screen_link('#errors'));
    if (monitor_alert_delivered($ch, $mailed, $belled)) {
        $ids = array_map(fn ($g) => (int) $g['id'], $due);
        $db->prepare('UPDATE monitor_error_groups SET alerted_at = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([monitor_fmt()]);
        return $n;
    }

    return 0;   // nothing could be sent: the next run tries again
}

/** Problems that suddenly happen far more often (store.php notes them). */
function monitor_alert_spikes(PDO $pdo, PDO $db): int
{
    $rows = $db->query("SELECT * FROM monitor_error_groups WHERE spiked_at IS NOT NULL AND status <> 'ignored'
                          AND (spike_alerted_at IS NULL OR spike_alerted_at < spiked_at) ORDER BY spiked_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return 0;
    }
    $ch = monitor_alert_channels($pdo, $db);
    if (!$ch['email_room']) {
        return 0;
    }
    $subject = count($rows) === 1 ? 'Spiking: ' . monitor_safe_title($rows[0], 90) : count($rows) . ' errors are spiking';
    $mailed = monitor_email_recipients($db, 'spike', 'HOD Lekki: ' . $subject, $subject,
        'These problems are suddenly happening far more often than usual (at least 20 in the last hour and five times their normal rate).',
        ['Spiking' => array_map(fn ($g) => monitor_alert_row($db, $g), $rows)]);
    $belled = monitor_notify_admins($pdo, $db, 'spike', 'An error is spiking', monitor_safe_title($rows[0], 120) . '. Open the monitor.', monitor_screen_link('#errors'));
    if (monitor_alert_delivered($ch, $mailed, $belled)) {
        $stmt = $db->prepare('UPDATE monitor_error_groups SET spike_alerted_at = ? WHERE id = ?');
        foreach ($rows as $g) {
            $stmt->execute([monitor_fmt(), $g['id']]);
        }
        return count($rows);
    }

    return 0;
}

/**
 * Urgent checks that turned red (then again every 6 hours while red), and
 * the ones that recovered. One message per run.
 */
function monitor_alert_health(PDO $pdo, PDO $db, ?array $checks = null): int
{
    $now = monitor_time();
    $checks = $checks ?? monitor_checks_current($db);
    $stored = [];
    foreach ($db->query('SELECT check_key, alerted_at, alerted_state FROM monitor_checks')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stored[$r['check_key']] = $r;
    }
    $urgent = monitor_urgent_checks();
    $red = [];
    $recovered = [];
    foreach ($checks as $c) {
        $key = $c['key'];
        $s = $stored[$key] ?? ['alerted_at' => null, 'alerted_state' => null];
        if ($c['state'] === 'bad' && in_array($key, $urgent, true)) {
            $last = monitor_ts($s['alerted_at']);
            if ($s['alerted_state'] !== 'bad' || $last === null || $last < $now - 6 * 3600) {
                $red[] = $c;
            }
        } elseif ($s['alerted_state'] === 'bad' && $c['state'] === 'ok') {
            $recovered[] = $c;
        }
    }
    if (!$red && !$recovered) {
        return 0;
    }
    $subject = $red
        ? (count($red) === 1 ? $red[0]['label'] . ' needs attention' : count($red) . ' things need attention')
        : (count($recovered) === 1 ? 'Recovered: ' . $recovered[0]['label'] : count($recovered) . ' checks recovered');
    $sections = [
        'Needs attention' => array_map(fn ($c) => ['tone' => 'bad', 'title' => $c['label'], 'detail' => trim($c['detail'] . ($c['fix'] ? ' What to do: ' . $c['fix'] : ''))], $red),
        'Recovered'       => array_map(fn ($c) => ['tone' => 'ok', 'title' => $c['label'], 'detail' => $c['detail']], $recovered),
    ];
    $ch = monitor_alert_channels($pdo, $db);
    if (!$ch['email_room']) {
        return 0;   // the next run, when email has room again
    }
    $mailed = monitor_email_recipients($db, 'health', 'HOD Lekki: ' . $subject, $subject,
        $red ? 'A health check of the site turned red. Each item says what is wrong and what to do.' : 'Checks that were red are fine again.', $sections);
    $belled = $red ? monitor_notify_admins($pdo, $db, 'health', $subject, mb_substr($red[0]['detail'], 0, 200) . ' See the monitor.', monitor_screen_link('#health')) : 0;
    if (!$red || monitor_alert_delivered($ch, $mailed, $belled)) {
        $stmt = $db->prepare('UPDATE monitor_checks SET alerted_at = ?, alerted_state = ? WHERE check_key = ?');
        foreach ($red as $c) {
            $stmt->execute([monitor_fmt($now), 'bad', $c['key']]);
        }
        foreach ($recovered as $c) {
            $stmt->execute([monitor_fmt($now), 'ok', $c['key']]);
        }
    }

    return count($red) + count($recovered);
}

/** The morning summary: once a day from the digest hour (08:00 Lagos unless set otherwise). */
function monitor_alert_digest(PDO $pdo, PDO $db, bool $force = false): bool
{
    $now = monitor_time();
    $hour = (int) (monitor_state_get($db, 'digest_hour', '8') ?? 8);
    $today = monitor_fmt($now, 'Y-m-d');
    if (!$force && ((int) monitor_fmt($now, 'G') < $hour || (int) monitor_fmt($now, 'G') >= $hour + 3 || monitor_state_get($db, 'digest:' . $today) !== null)) {
        return false;
    }
    monitor_state_set($db, 'digest:' . $today, '1');

    $checks = monitor_checks_current($db);
    $notOk = array_values(array_filter($checks, fn ($c) => $c['state'] !== 'ok'));
    $worst = monitor_checks_worst($checks);
    $since = monitor_fmt($now - 86400);

    $stmt = $db->prepare("SELECT * FROM monitor_error_groups WHERE first_seen_at >= ? AND level NOT IN ('notice', 'deprecated') ORDER BY CASE level WHEN 'fatal' THEN 0 WHEN 'error' THEN 1 ELSE 2 END, occurrences DESC LIMIT 8");
    $stmt->execute([$since]);
    $new = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT g.*, t.day_n FROM monitor_error_groups g
                            JOIN (SELECT group_id, SUM(n) AS day_n FROM monitor_error_hourly WHERE hour >= ? GROUP BY group_id) t ON t.group_id = g.id
                           WHERE g.status IN ('new', 'investigating') AND g.level NOT IN ('notice', 'deprecated')
                           ORDER BY t.day_n DESC LIMIT 5");
    $stmt->execute([monitor_fmt($now - 86400, 'Y-m-d H:00:00')]);
    $top = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $open = (int) $db->query("SELECT COUNT(*) FROM monitor_error_groups WHERE status IN ('new', 'investigating') AND level NOT IN ('notice', 'deprecated') AND kind <> 'csp'")->fetchColumn();

    $deploys = array_values(array_filter(monitor_recent_deploys(10), fn ($d) => ($d['started_at'] ?? 0) >= $now - 86400));
    $headline = $worst === 'ok' ? 'All good this morning' : (count(array_filter($notOk, fn ($c) => $c['state'] === 'bad')) ? 'Some things need attention' : 'Mostly fine, a few things to look at');
    $intro = 'Yesterday on ' . site_host() . ': ' . count($new) . ' new problem(s), ' . $open . ' open in all'
        . ($deploys ? ', ' . count($deploys) . ' deploy(s)' : '') . '. '
        . ($notOk ? count($notOk) . ' health check(s) are not green.' : 'Every health check is green.');
    $sections = [
        'Health checks not green' => array_map(fn ($c) => ['tone' => $c['state'] === 'bad' ? 'bad' : ($c['state'] === 'warn' ? 'warn' : 'info'),
            'title' => $c['label'], 'detail' => $c['detail']], $notOk),
        'New since yesterday morning' => array_map(fn ($g) => monitor_alert_row($db, $g), $new),
        'Most frequent open problems' => array_map(fn ($g) => ['tone' => in_array($g['level'], ['fatal', 'error'], true) ? 'bad' : 'warn',
            'title' => mb_substr((string) $g['title'], 0, 160), 'detail' => (int) $g['day_n'] . ' time(s) in the last 24 hours · #' . $g['id']], $top),
        'Deploys' => array_map(fn ($d) => ['tone' => $d['ok'] ? 'ok' : 'bad', 'title' => ($d['commit'] ?? substr((string) $d['sha'], 0, 7)) . ' ' . ($d['subject'] ?? ''),
            'detail' => monitor_fmt($d['finished_at'] ?? $d['started_at'], 'D H:i') . ($d['ok'] ? '' : ' · did not finish')], $deploys),
    ];
    $mailed = monitor_email_recipients($db, 'digest', 'HOD Lekki morning summary: ' . strtolower($headline), $headline, $intro, $sections, false);
    $belled = monitor_notify_admins($pdo, $db, 'digest', 'Morning summary: ' . strtolower($headline), $intro, monitor_screen_link(), false);

    return $mailed > 0 || $belled > 0;
}

/**
 * Is the monitor's own cron alive? Asked from page requests now and then
 * (store.php), since a stopped cron cannot say so itself. At most every 6 hours.
 */
function monitor_watchdog(string $dir): void
{
    $beat = is_file($dir . '/beats/monitor.json') ? json_decode((string) @file_get_contents($dir . '/beats/monitor.json'), true) : null;
    $installed = is_file($dir . '/installed_at') ? (int) @file_get_contents($dir . '/installed_at') : time();
    $last = is_array($beat) ? (int) ($beat['finished_at'] ?? $beat['started_at'] ?? 0) : 0;
    if ($last > 0 ? $last > time() - 20 * 60 : $installed > time() - 2 * 3600) {
        return;   // alive, or only just installed
    }
    $marker = $dir . '/watchdog_alerted';
    $t = @filemtime($marker);
    if ($t !== false && $t > time() - 6 * 3600) {
        return;
    }
    @touch($marker);
    $db = monitor_db();
    if (!monitor_ready($db)) {
        return;
    }
    $what = $last > 0 ? 'The monitor has not run for ' . monitor_ago($last, time()) . '.' : 'The monitor\'s cron job has never run on this server.';
    $detail = $what . ' Until it runs again, no log is read, no check is made and no alert is sent.';
    $line = monitor_cron_jobs()['monitor']['line'];
    monitor_email_recipients($db, 'watchdog', 'HOD Lekki: the system monitor has stopped', 'The system monitor has stopped', $detail,
        ['What to do' => [['tone' => 'bad', 'title' => 'cPanel → Cron Jobs', 'detail' => 'It needs: ' . $line . ' — then run "php cron/monitor.php" once in cPanel → Terminal to see any error.']]], false);
    monitor_notify_admins($db, $db, 'watchdog', 'The system monitor has stopped', $detail, monitor_screen_link('#health'), false);
}

/* ───────────────────────────── recipients ───────────────────────────── */

/**
 * Add an address (or send a fresh link to one that is pending or was removed).
 * Nothing is sent to it until its owner clicks the link in the confirmation
 * email. Returns ['ok', 'status', 'error'].
 */
function monitor_recipient_add(PDO $db, string $email, ?int $by): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        return ['ok' => false, 'status' => null, 'error' => 'That is not a valid email address.'];
    }
    $stmt = $db->prepare('SELECT * FROM monitor_recipients WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['status'] === 'active') {
        return ['ok' => false, 'status' => 'active', 'error' => 'That address already receives the alerts.'];
    }
    $secret = bin2hex(random_bytes(24));
    $now = monitor_fmt();
    $expires = monitor_fmt(monitor_time() + 7 * 86400);
    if ($row) {
        $db->prepare("UPDATE monitor_recipients SET status = 'pending', token_hash = ?, token_expires_at = ?, added_by = ?, added_at = ?, removed_at = NULL WHERE id = ?")
           ->execute([hash('sha256', $secret), $expires, $by, $now, $row['id']]);
        $id = (int) $row['id'];
    } else {
        $db->prepare("INSERT INTO monitor_recipients (email, status, token_hash, token_expires_at, added_by, added_at) VALUES (?, 'pending', ?, ?, ?, ?)")
           ->execute([$email, hash('sha256', $secret), $expires, $by, $now]);
        $id = (int) $db->lastInsertId();
    }
    $link = site_url('/monitor_confirm.php?t=' . $id . '.' . $secret);
    $heading = 'Confirm the system alerts';
    $intro = 'Someone with admin access to ' . site_host() . ' asked for this address to receive the site\'s system alerts: new errors, health problems, and a short summary each morning. '
        . 'Nothing is sent until you confirm. If you did not expect this, ignore this email.';
    $res = monitor_mail($db, $email, 'Confirm HOD Lekki system alerts',
        monitor_email_html($heading, $intro, [], $link, 'Confirm this address', null),
        monitor_email_text($heading, $intro, [], $link, null) . "\nConfirm: " . $link . "\n", 'confirm');
    monitor_alert_log($db, 'email', 'confirm', 'Confirm HOD Lekki system alerts', $res['ok'] ? 1 : 0, (bool) $res['ok'], $res['ok'] ? null : monitor_redact((string) $res['error']));
    if (!$res['ok']) {
        return ['ok' => false, 'status' => 'pending', 'error' => 'Saved, but the confirmation email could not be sent: ' . monitor_redact((string) $res['error'])];
    }

    return ['ok' => true, 'status' => 'pending', 'error' => null];
}

/** The link in the confirmation email. Returns ['ok', 'email', 'error']. */
function monitor_recipient_confirm(PDO $db, string $token): array
{
    if (!preg_match('/^(\d+)\.([0-9a-f]{48})$/', $token, $m)) {
        return ['ok' => false, 'email' => null, 'error' => 'This link is not complete. Open it from the email again.'];
    }
    $stmt = $db->prepare('SELECT * FROM monitor_recipients WHERE id = ?');
    $stmt->execute([(int) $m[1]]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r || !$r['token_hash'] || !hash_equals((string) $r['token_hash'], hash('sha256', $m[2]))) {
        return ['ok' => false, 'email' => null, 'error' => 'This link has already been used or replaced by a newer one.'];
    }
    if ((monitor_ts($r['token_expires_at']) ?? 0) < monitor_time()) {
        return ['ok' => false, 'email' => null, 'error' => 'This link has expired. Ask an admin to send a new one.'];
    }
    $db->prepare("UPDATE monitor_recipients SET status = 'active', confirmed_at = ?, token_hash = NULL, token_expires_at = NULL WHERE id = ?")
       ->execute([monitor_fmt(), $r['id']]);

    return ['ok' => true, 'email' => $r['email'], 'error' => null];
}

/** The stop link in every alert email. */
function monitor_recipient_stop(PDO $db, string $token): array
{
    if (!preg_match('/^(\d+)\.[0-9a-f]{32}$/', $token, $m)) {
        return ['ok' => false, 'email' => null, 'error' => 'This link is not complete. Open it from the email again.'];
    }
    $stmt = $db->prepare('SELECT * FROM monitor_recipients WHERE id = ?');
    $stmt->execute([(int) $m[1]]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r || !hash_equals(monitor_stop_token($r), $token)) {
        return ['ok' => false, 'email' => null, 'error' => 'This link is not valid.'];
    }
    if ($r['status'] !== 'removed') {
        $db->prepare("UPDATE monitor_recipients SET status = 'removed', removed_at = ?, token_hash = NULL WHERE id = ?")->execute([monitor_fmt(), $r['id']]);
    }

    return ['ok' => true, 'email' => $r['email'], 'error' => null];
}

function monitor_recipient_remove(PDO $db, int $id): bool
{
    $stmt = $db->prepare("UPDATE monitor_recipients SET status = 'removed', removed_at = ?, token_hash = NULL WHERE id = ? AND status <> 'removed'");
    $stmt->execute([monitor_fmt(), $id]);

    return $stmt->rowCount() > 0;
}

function monitor_recipients_list(PDO $db): array
{
    return $db->query("SELECT id, email, status, added_at, confirmed_at, removed_at, last_sent_at FROM monitor_recipients
                        ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, email")->fetchAll(PDO::FETCH_ASSOC);
}
