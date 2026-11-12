<?php
// /includes/campaigns/automation_tab.php
//
// The Automation tab: ONE panel shared by Embrace (the first-timer journey)
// and Assimilation and Charis (the absence ladders). Rendered here on the
// foundation components; api/automation_api.php runs its actions through
// automation_tab_action(); assets/ui/js/pages/automation.js wires the
// buttons. The email preview is api/email_preview.php (its own CSP, in a
// sandboxed frame). Callers gate access: can(<module>, 'manage').
//
// What the tab edits: when each step happens (day and time, or missed
// Sundays), who the rule covers, the sender name and reply-to, each SMS, and
// each team/pastor alert. The email documents themselves are edited in the
// block editor (next PR); until then they stay the defaults (defaults.php).
// Rules are created OFF; switching one on is always a person's choice.

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/ui/bootstrap.php';

const AUTOMATION_TAB_WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
const AUTOMATION_TAB_TARGETS  = ['member' => 'Person', 'team' => 'Team', 'pastor' => 'Pastor'];
const AUTOMATION_TAB_STOPS    = ['2nd_Timer' => '2nd Timer', '3rd_Timer' => '3rd Timer', 'Visitor' => 'Visitor'];
const AUTOMATION_TAB_OUTCOMES = [
    'deferred'           => 'Moved to the next day',
    'skipped_no_contact' => 'No email or phone',
    'skipped_consent'    => 'Unsubscribed',
    'skipped_same_day'   => 'Had a message that day',
    'superseded'         => 'A later step went instead',
    'baselined'          => 'Already past it at switch-on',
    'departed'           => 'Moved away',
    'expired'            => 'Too late to send',
    'failed'             => 'Failed',
];

/** Why the tab describes the rule the way it does: one line, the rest behind (i). */
function automation_tab_about(string $kind): string
{
    return $kind === 'journey'
        ? 'Weekly emails to first timers until they reach the stop status or the weeks run out, plus the midweek and Sunday reminders. Email when they confirmed one, else SMS, else nothing. Nobody gets two messages from the church in one day.'
        : 'Checks attendance once a week. After the missed Sundays of each step it emails the person, then tells the team, then their pastor. Each step happens once per absence, and again if they come back and drift away later.';
}

/** The module's rule, created (OFF) on first look. Null before the migration. */
function automation_tab_rule(PDO $pdo, string $module): ?array
{
    if (!isset(AUTOMATION_KINDS[$module]) || !campaigns_ready($pdo)) {
        return null;
    }
    automation_ensure_defaults($pdo);
    return automation_rule_get($pdo, $module);
}

/** Statuses both absence rules cover (Assimilation and Charis would both act). */
function automation_tab_overlap(PDO $pdo, string $module): array
{
    if ((AUTOMATION_KINDS[$module] ?? '') !== 'absence') {
        return [];
    }
    $other = $module === 'charis' ? 'assimilation' : 'charis';
    $mine = automation_rule_get($pdo, $module);
    $theirs = automation_rule_get($pdo, $other);
    if ($mine === null || $theirs === null) {
        return [];
    }
    return array_values(array_intersect(
        (array) ($mine['config']['audience']['statuses'] ?? []),
        (array) ($theirs['config']['audience']['statuses'] ?? [])
    ));
}

/** @return list<array> the latest runs, newest first */
function automation_tab_runs(PDO $pdo, int $ruleId, int $limit = 8): array
{
    $stmt = $pdo->prepare('SELECT * FROM automation_runs WHERE rule_id = ? ORDER BY started_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute([$ruleId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array{name:string, step:string, result:string}> who a run reached, and how */
function automation_tab_run_people(PDO $pdo, array $rule, int $runId): array
{
    $stmt = $pdo->prepare('SELECT s.step_key, s.channel, s.outcome, s.detail, u.first_name, u.last_name
                             FROM automation_sends s LEFT JOIN users u ON u.id = s.user_id
                            WHERE s.rule_id = ? AND s.run_id = ? ORDER BY u.first_name, u.last_name, s.id');
    $stmt->execute([(int) $rule['id'], $runId]);
    $labels = array_column($rule['config']['steps'] ?? [], 'label', 'key');
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = [
            'name'   => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Someone removed',
            'step'   => (string) ($labels[$row['step_key']] ?? $row['step_key']),
            'result' => automation_tab_outcome((string) $row['outcome'], (string) $row['channel']),
        ];
    }
    return $out;
}

function automation_tab_outcome(string $outcome, string $channel): string
{
    if ($outcome === 'queued') {
        return $channel === 'sms' ? 'Texted' : 'Emailed';
    }
    if ($outcome === 'alerted') {
        return 'Alerted';
    }
    return AUTOMATION_TAB_OUTCOMES[$outcome] ?? ucfirst(str_replace('_', ' ', $outcome));
}

/** "160 characters · 1 text" for an SMS (GSM-7: 160 then 153 a page; anything else 70 then 67). */
function automation_tab_sms_count(string $text): string
{
    $len = mb_strlen($text, 'UTF-8');
    $gsm = (bool) preg_match('/^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&\'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\\\\[~\]|€]*$/u', $text);
    [$one, $many] = $gsm ? [160, 153] : [70, 67];
    $pages = $len === 0 ? 0 : ($len <= $one ? 1 : (int) ceil($len / $many));
    return $len . ' characters · ' . $pages . ($pages === 1 ? ' text' : ' texts');
}

/**
 * The rule's settings with the tab's edits applied ($in: the form). Only what
 * the tab edits moves; the email documents and step targets stay. The result
 * still goes through automation_rule_save() (and its validation).
 */
function automation_tab_apply(array $rule, array $in): array
{
    $config = $rule['config'];
    $config['from_name'] = (string) ($in['from_name'] ?? ($config['from_name'] ?? ''));
    $config['reply_to'] = (string) ($in['reply_to'] ?? ($config['reply_to'] ?? ''));
    if ($rule['kind'] === 'journey') {
        $config['audience'] = [
            'stop_status' => (string) ($in['stop_status'] ?? ($config['audience']['stop_status'] ?? '3rd_Timer')),
            'weeks'       => (int) ($in['weeks'] ?? ($config['audience']['weeks'] ?? 6)),
        ];
    } else {
        $config['audience'] = ['statuses' => array_values(array_map('strval', (array) ($in['statuses'] ?? [])))];
        $config['when'] = [
            'weekday' => (int) ($in['when_weekday'] ?? ($config['when']['weekday'] ?? 1)),
            'time'    => (string) ($in['when_time'] ?? ($config['when']['time'] ?? '09:00')),
        ];
    }
    $edits = (array) ($in['steps'] ?? []);
    foreach ($config['steps'] as $i => $step) {
        $e = (array) ($edits[$step['key']] ?? []);
        if ($e === []) {
            continue;
        }
        if ($rule['kind'] === 'journey') {
            $config['steps'][$i]['when'] = [
                'weekday' => (int) ($e['weekday'] ?? $step['when']['weekday']),
                'time'    => (string) ($e['time'] ?? $step['when']['time']),
            ];
        } elseif (isset($e['threshold'])) {
            $config['steps'][$i]['threshold'] = (int) $e['threshold'];
        }
        if (isset($step['series'])) {
            foreach ($step['series'] as $w => $item) {
                if (isset($e['series'][$w]['sms'])) {
                    $config['steps'][$i]['series'][$w]['sms'] = (string) $e['series'][$w]['sms'];
                }
            }
        } elseif (($step['target'] ?? 'member') === 'member') {
            if (isset($e['sms'])) {
                $config['steps'][$i]['sms'] = (string) $e['sms'];
            }
        } else {
            $config['steps'][$i]['alert'] = [
                'title'   => (string) ($e['alert_title'] ?? ($step['alert']['title'] ?? '')),
                'message' => (string) ($e['alert_message'] ?? ($step['alert']['message'] ?? '')),
            ];
        }
    }
    return $config;
}

/** Example values for a preview or a test (the real ones come from attendance). */
function automation_tab_sample_vars(array $rule, array $step, int $week): array
{
    $vars = ['week_number' => (string) $week];
    if ($rule['kind'] === 'absence') {
        $n = (int) ($step['threshold'] ?? 2);
        $vars['streak'] = (string) $n;
        $vars['weeks_away'] = (string) $n;
        $vars['last_attended'] = campaigns_now()->modify('-' . ($n * 7) . ' days')->format('j M Y');
    }
    return $vars;
}

/** A step's email for week $week (1-based; series steps only). Null when the step has none. */
function automation_tab_step_doc(array $rule, string $stepKey, int $week = 1): ?array
{
    $step = automation_step($rule, $stepKey);
    if ($step === null || ($step['target'] ?? 'member') !== 'member') {
        return null;
    }
    return automation_step_email($step, max(0, $week - 1));
}

/** Render a step's email for $person, as the preview shows it (no tracking). */
function automation_tab_render(PDO $pdo, array $rule, string $stepKey, int $week, array $person): ?array
{
    $doc = automation_tab_step_doc($rule, $stepKey, $week);
    if ($doc === null) {
        return null;
    }
    $step = automation_step($rule, $stepKey);
    return email_render($doc, $person, [
        'purpose' => 'news',
        'preview' => true,
        'pdo'     => $pdo,
        'vars'    => automation_tab_sample_vars($rule, $step, $week),
    ]);
}

/** Send a step's email to the viewer's own confirmed address, marked as a test. */
function automation_tab_test_send(PDO $pdo, array $rule, string $stepKey, int $week, int $viewerId): array
{
    $doc = automation_tab_step_doc($rule, $stepKey, $week);
    if ($doc === null) {
        return ['ok' => false, 'message' => 'This step has no email.'];
    }
    $to = member_confirmed_email($pdo, $viewerId);
    $person = email_person($pdo, $viewerId);
    if ($to === null || $person === null) {
        return ['ok' => false, 'message' => 'Confirm your email in Profile first.'];
    }
    $doc['subject'] = campaigns_cut('Test: ' . (string) $doc['subject'], 150);
    // Account mail in the test lane: it always arrives, and has no unsubscribe
    // link (an unsubscribe from a test would stop the tester's own news).
    $r = email_send_doc($pdo, $doc, $person + ['email' => $to], [
        'lane'       => 'test',
        'purpose'    => 'account',
        'flow'       => 'automation_test',
        'to_email'   => $to,
        'created_by' => $viewerId,
        'vars'       => automation_tab_sample_vars($rule, automation_step($rule, $stepKey), $week),
        'now'        => true,
    ]);
    return !empty($r['ok'])
        ? ['ok' => true, 'message' => 'Test sent to ' . email_mask($to) . '.']
        : ['ok' => false, 'message' => (string) ($r['message'] ?? 'The test could not be queued.')];
}

/** "Switch on?" numbers: who is already past a step today, and by which channel. */
function automation_tab_switch_preview(PDO $pdo, array $rule): array
{
    $p = automation_preview($pdo, $rule);
    $c = $p['counts'];
    return [
        'people'   => (int) $c['people'],
        'emailed'  => (int) $c['emailed'],
        'texted'   => (int) $c['texted'],
        'alerted'  => (int) $c['alerted'],
        'skipped'  => (int) $c['skipped'] + (int) ($c['deferred'] ?? 0),
    ];
}

function automation_tab_switch_line(array $n): string
{
    if ($n['people'] === 0) {
        return 'Nobody is past a step today.';
    }
    $parts = [];
    foreach (['emailed' => 'by email', 'texted' => 'by SMS', 'alerted' => 'as alerts', 'skipped' => 'unreachable'] as $k => $label) {
        if ($n[$k] > 0) {
            $parts[] = $n[$k] . ' ' . $label;
        }
    }
    return $n['people'] . ($n['people'] === 1 ? ' person is' : ' people are') . ' already past a step'
        . ($parts !== [] ? ' (' . implode(', ', $parts) . ').' : '.');
}

// ---------------------------------------------------------------------------
// HTML
// ---------------------------------------------------------------------------

function automation_tab_select(string $name, string $id, string $label, array $options, string $value, array $o = []): string
{
    return ui_field('select', ['name' => $name, 'id' => $id, 'label' => $label, 'options' => $options, 'value' => $value] + $o);
}

function automation_tab_when(string $module, string $prefix, string $idBase, array $when, string $dayLabel = 'Day'): string
{
    return '<div class="ui-form-grid">'
        . automation_tab_select($prefix . '[weekday]', $idBase . '-day', $dayLabel, AUTOMATION_TAB_WEEKDAYS, (string) ($when['weekday'] ?? 1))
        . ui_field('time', ['name' => $prefix . '[time]', 'id' => $idBase . '-time', 'label' => 'Time', 'value' => (string) ($when['time'] ?? '10:00'), 'required' => true])
        . '</div>';
}

function automation_tab_sms_field(string $name, string $id, string $value): string
{
    return ui_field('textarea', [
        'name' => $name, 'id' => $id, 'label' => 'SMS', 'value' => $value,
        'info' => 'Sent when the person has no confirmed email. Leave it empty to send no SMS. Merge fields like {{first_name}} work.',
        'attrs' => ['rows' => 3, 'maxlength' => AUTOMATION_SMS_MAX, 'data-action-input' => 'automation.sms-count', 'data-count-target' => $id . '-count'],
    ]) . '<p class="ui-hint -mt-2" id="' . ui_e($id) . '-count" aria-live="polite">' . ui_e(automation_tab_sms_count($value)) . '</p>';
}

function automation_tab_email_row(string $module, string $stepKey, int $week, array $doc, ?string $title = null): string
{
    $attrs = ['data-module' => $module, 'data-step' => $stepKey, 'data-week' => (string) $week];
    return '<div class="flex flex-wrap items-center justify-between gap-2 rounded-ui-lg border border-line bg-surface-subtle px-3 py-2">'
        . '<div class="min-w-0"><p class="ui-meta">' . ui_e($title ?? 'Email') . '</p>'
        . '<p class="truncate font-medium text-ink">' . ui_e((string) ($doc['subject'] ?? '')) . '</p></div>'
        . '<div class="flex shrink-0 gap-2">'
        . ui_button('Preview', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'eye', 'attrs' => $attrs + ['data-action' => 'automation.preview']])
        . ui_button('Send test', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'send', 'attrs' => $attrs + ['data-action' => 'automation.test']])
        . '</div></div>';
}

function automation_tab_step_html(string $module, array $rule, array $step): string
{
    $key = (string) $step['key'];
    $prefix = 'steps[' . $key . ']';
    $idBase = 'auto-' . $module . '-' . $key;
    $target = (string) ($step['target'] ?? 'member');
    $html = '<div class="rounded-ui-lg border border-line p-4 ui-stack" data-automation-step="' . ui_e($key) . '">'
        . '<div class="flex flex-wrap items-center gap-2"><h4 class="ui-h3">' . ui_e((string) ($step['label'] ?: $key)) . '</h4>'
        . ui_badge(AUTOMATION_TAB_TARGETS[$target] ?? $target, $target === 'member' ? 'info' : 'neutral') . '</div>';

    if ($rule['kind'] === 'journey') {
        $html .= automation_tab_when($module, $prefix, $idBase, (array) $step['when']);
    } else {
        $html .= ui_field('number', [
            'name' => $prefix . '[threshold]', 'id' => $idBase . '-threshold', 'label' => 'After missed Sundays',
            'value' => (string) $step['threshold'], 'required' => true, 'attrs' => ['min' => 1, 'max' => 52, 'inputmode' => 'numeric'], 'class' => 'max-w-[12rem]',
        ]);
    }

    if (isset($step['series'])) {
        foreach ($step['series'] as $w => $item) {
            $week = $w + 1;
            $html .= '<details class="rounded-ui-lg border border-line"' . ($w === 0 ? ' open' : '') . '><summary class="cursor-pointer px-3 py-2 font-medium text-ink">Week ' . $week . '</summary><div class="ui-stack p-3">'
                . automation_tab_email_row($module, $key, $week, (array) $item['email'], 'Week ' . $week . ' email')
                . automation_tab_sms_field($prefix . '[series][' . $w . '][sms]', $idBase . '-w' . $week . '-sms', (string) ($item['sms'] ?? ''))
                . '</div></details>';
        }
    } elseif ($target === 'member') {
        $html .= automation_tab_email_row($module, $key, 1, (array) $step['email'])
            . automation_tab_sms_field($prefix . '[sms]', $idBase . '-sms', (string) ($step['sms'] ?? ''));
    } else {
        $alert = (array) ($step['alert'] ?? []);
        $html .= ui_field('text', ['name' => $prefix . '[alert_title]', 'id' => $idBase . '-title', 'label' => 'Alert title', 'value' => (string) ($alert['title'] ?? ''), 'required' => true, 'attrs' => ['maxlength' => 80]])
            . ui_field('textarea', ['name' => $prefix . '[alert_message]', 'id' => $idBase . '-message', 'label' => 'Alert message', 'value' => (string) ($alert['message'] ?? ''), 'required' => true,
                'info' => $target === 'team'
                    ? 'A notification to the team, naming the person and linking to their record. {{full_name}}, {{streak}} and {{last_attended}} work.'
                    : 'A notification to their region pastor, else the Resident Pastor. {{full_name}}, {{streak}} and {{last_attended}} work.',
                'attrs' => ['rows' => 3, 'maxlength' => 300]]);
    }
    return $html . '</div>';
}

function automation_tab_runs_html(array $rule, array $runs): string
{
    if ($runs === []) {
        return ui_empty(['icon' => 'history', 'title' => 'No runs yet', 'text' => 'Runs show here once it is on.', 'compact' => true]);
    }
    $html = '<ul class="ui-list rounded-ui-lg border border-line">';
    foreach ($runs as $run) {
        $at = campaigns_dt((string) $run['occasion_at'])->format('D j M, H:i');
        $bits = [];
        foreach (['emailed' => 'emailed', 'texted' => 'texted', 'alerted' => 'alerted', 'skipped' => 'skipped'] as $col => $word) {
            if ((int) $run[$col] > 0) {
                $bits[] = (int) $run[$col] . ' ' . $word;
            }
        }
        $summary = (int) $run['people'] . ((int) $run['people'] === 1 ? ' person' : ' people') . ($bits !== [] ? ': ' . implode(', ', $bits) : '');
        $html .= '<li class="ui-list__item flex-col items-stretch gap-2" data-automation-run="' . (int) $run['id'] . '">'
            . '<div class="flex flex-wrap items-center justify-between gap-2"><div class="min-w-0">'
            . '<p class="font-medium text-ink">' . ui_e($at) . '</p>'
            . '<p class="ui-meta">' . ui_e($summary) . '</p></div>'
            . '<div class="flex items-center gap-2">'
            . ($run['status'] === 'failed' ? ui_badge('Failed', 'danger') : ($run['status'] === 'running' ? ui_badge('Running', 'warning') : ''))
            . ((int) $run['people'] > 0 ? ui_button('Who', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => [
                'data-action' => 'automation.run', 'data-module' => (string) $rule['module'], 'data-run' => (string) $run['id'], 'aria-expanded' => 'false']]) : '')
            . '</div></div><div data-automation-run-people hidden></div></li>';
    }
    return $html . '</ul>';
}

/**
 * The whole panel. $o['note'] / $o['note_tone']: a banner after a save or a
 * switch (the API answers with the panel again).
 */
function automation_tab_html(PDO $pdo, string $module, array $o = []): string
{
    $label = AUTOMATION_MODULE_LABELS[$module] ?? $module;
    $rule = automation_tab_rule($pdo, $module);
    $open = '<div id="automation-' . ui_e($module) . '" class="ui-stack" data-automation="' . ui_e($module) . '">';
    if ($rule === null) {
        return $open . '<section class="ui-card"><div class="ui-card__body">'
            . ui_empty(['icon' => 'settings', 'title' => 'Not set up yet', 'text' => 'Automations arrive with the next update.'])
            . '</div></section></div>';
    }
    $kind = (string) $rule['kind'];
    $c = $rule['config'];
    $on = !empty($rule['is_enabled']);
    $id = 'auto-' . $module;

    $html = $open;
    if (!empty($o['note'])) {
        $html .= ui_banner((string) ($o['note_tone'] ?? 'success'), ['title' => (string) $o['note']]);
    }

    // Status and the switch.
    $html .= '<section class="ui-card"><div class="ui-card__body flex flex-wrap items-center justify-between gap-3">'
        . '<div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="ui-h2">' . ui_e((string) $rule['name']) . '</h2>'
        . ui_badge($on ? 'On' : 'Off', $on ? 'success' : 'neutral', ['dot' => true])
        . ui_info(automation_tab_about($kind), 'About this automation') . '</div>'
        . '<p class="ui-meta">' . ui_e($on && !empty($rule['enabled_at'])
            ? 'On since ' . campaigns_dt((string) $rule['enabled_at'])->format('j M Y') . '.'
            : 'Nothing is sent while it is off.') . '</p></div>'
        . ($on
            ? ui_button('Switch off', ['variant' => 'danger-ghost', 'attrs' => ['data-action' => 'automation.off', 'data-module' => $module]])
            : ui_button('Switch on', ['variant' => 'primary', 'icon' => 'bell-ring', 'attrs' => ['data-action' => 'automation.on', 'data-module' => $module]]))
        . '</div></section>';

    if ($kind === 'absence' && ($overlap = automation_tab_overlap($pdo, $module)) !== []) {
        $names = implode(', ', array_map(static fn ($s) => str_replace('_', ' ', $s), $overlap));
        $html .= ui_banner('warning', ['title' => 'Two rules cover ' . $names,
            'text' => 'Untick them here or in ' . ($module === 'charis' ? 'Assimilation' : 'Charis') . '.',
            'info' => 'Both absence rules would follow the same people. Nobody gets two messages in one day, but the team and pastor alerts would come from both.']);
    }

    // Settings.
    $html .= '<form class="ui-card" data-action="automation.save" data-module="' . ui_e($module) . '" novalidate aria-labelledby="' . ui_e($id) . '-settings">'
        . '<input type="hidden" name="module" value="' . ui_e($module) . '">'
        . '<input type="hidden" name="version" value="' . (int) $rule['version'] . '">'
        . '<div class="ui-card__header ui-card__header--divided"><h3 class="ui-card__title" id="' . ui_e($id) . '-settings">Settings</h3></div>'
        . '<div class="ui-card__body ui-form">'
        . '<fieldset class="ui-fieldset"><legend class="ui-legend">Who and when</legend><div class="ui-form-grid">';
    if ($kind === 'journey') {
        $aud = (array) ($c['audience'] ?? []);
        $html .= automation_tab_select('stop_status', $id . '-stop', 'Stops at', AUTOMATION_TAB_STOPS, (string) ($aud['stop_status'] ?? '3rd_Timer'),
                ['info' => 'The journey ends the day the Sunday ladder gives them this status.'])
            . ui_field('number', ['name' => 'weeks', 'id' => $id . '-weeks', 'label' => 'For weeks', 'value' => (string) ($aud['weeks'] ?? 6), 'required' => true,
                'info' => 'Counted from their first Sunday or their record, whichever is earlier.', 'attrs' => ['min' => 1, 'max' => 12, 'inputmode' => 'numeric']])
            . '</div>';
    } else {
        $chosen = (array) ($c['audience']['statuses'] ?? []);
        $html .= '</div><div class="ui-field"><span class="ui-label">Who it covers</span><div class="flex flex-wrap gap-x-5 gap-y-2">';
        foreach (AUTOMATION_ABSENCE_STATUSES as $status) {
            $html .= '<label class="ui-check"><input type="checkbox" name="statuses[]" value="' . ui_e($status) . '"' . (in_array($status, $chosen, true) ? ' checked' : '') . '>'
                . '<span>' . ui_e(str_replace('_', ' ', $status)) . '</span></label>';
        }
        $html .= '</div></div>'
            . '<div class="ui-form-grid">'
            . automation_tab_select('when_weekday', $id . '-check-day', 'Weekly check', AUTOMATION_TAB_WEEKDAYS, (string) ($c['when']['weekday'] ?? 1),
                ['info' => 'After Sunday attendance is in. Streaks come from the Charis AWOL rules.'])
            . ui_field('time', ['name' => 'when_time', 'id' => $id . '-check-time', 'label' => 'Time', 'value' => (string) ($c['when']['time'] ?? '09:00'), 'required' => true])
            . '</div>';
    }
    $html .= '</fieldset>'
        . '<fieldset class="ui-fieldset"><legend class="ui-legend">Sender</legend><div class="ui-form-grid">'
        . ui_field('text', ['name' => 'from_name', 'id' => $id . '-from', 'label' => 'From name', 'value' => (string) ($c['from_name'] ?? ''), 'attrs' => ['maxlength' => 80]])
        . ui_field('email', ['name' => 'reply_to', 'id' => $id . '-reply', 'label' => 'Replies go to', 'optional' => true, 'value' => (string) ($c['reply_to'] ?? ''),
            'info' => 'Empty: the church office address.', 'autocomplete' => 'off'])
        . '</div></fieldset>'
        . '<fieldset class="ui-fieldset"><legend class="ui-legend">Steps</legend><div class="ui-stack">';
    foreach ((array) ($c['steps'] ?? []) as $step) {
        $html .= automation_tab_step_html($module, $rule, (array) $step);
    }
    $html .= '</div></fieldset></div>'
        . '<div class="ui-card__footer"><div class="ui-form-actions w-full sm:w-auto">'
        . ui_button('Save changes', ['variant' => 'primary', 'type' => 'submit']) . '</div></div></form>';

    // Recent runs.
    $html .= '<section class="ui-card" aria-labelledby="' . ui_e($id) . '-runs"><div class="ui-card__header ui-card__header--divided">'
        . '<h3 class="ui-card__title" id="' . ui_e($id) . '-runs">Recent runs</h3></div><div class="ui-card__body">'
        . automation_tab_runs_html($rule, automation_tab_runs($pdo, (int) $rule['id']))
        . '</div></section>';

    // Dialogs: switch on, preview.
    $html .= ui_dialog_start($id . '-enable', ['title' => 'Switch on?', 'size' => 'sm'])
        . '<p data-automation-enable-line>…</p>'
        . ($kind === 'absence'
            ? '<fieldset class="ui-fieldset mt-4"><legend class="sr-only">People already past a step</legend><div class="ui-stack ui-stack--sm">'
              . '<label class="ui-check"><input type="radio" name="' . ui_e($id) . '-start" value="fresh" checked><span>Start fresh<span class="ui-check__hint">Only people who pass a step from now on.</span></span></label>'
              . '<label class="ui-check"><input type="radio" name="' . ui_e($id) . '-start" value="include"><span>Include them<span class="ui-check__hint">They hear at the next weekly check.</span></span></label>'
              . '</div></fieldset>'
            : '')
        . ui_dialog_end(
            ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
            . ui_button('Switch on', ['variant' => 'primary', 'attrs' => ['data-action' => 'automation.enable', 'data-module' => $module]])
        );
    $html .= ui_dialog_start($id . '-preview', ['title' => 'Preview', 'size' => 'lg'])
        . '<div class="mb-3 flex flex-wrap items-center justify-between gap-2"><p class="ui-meta" data-automation-preview-name></p>'
        . '<div class="ui-segmented" role="group" aria-label="Width">'
        . '<button type="button" class="ui-segmented__item" data-action="automation.width" data-width="375" aria-pressed="false">' . ui_icon('smartphone', 'ui-icon--sm') . 'Phone</button>'
        . '<button type="button" class="ui-segmented__item" data-action="automation.width" data-width="640" aria-pressed="true">' . ui_icon('layout-dashboard', 'ui-icon--sm') . 'Desktop</button>'
        . '</div></div>'
        . '<div class="overflow-hidden rounded-ui-lg border border-line bg-surface-sunken"><iframe class="mx-auto block h-[70vh] w-full max-w-[640px] bg-white" title="Email preview" sandbox="" referrerpolicy="no-referrer" data-automation-frame></iframe></div>'
        . ui_dialog_end(ui_button('Close', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]]));

    return $html . '</div>';
}

// ---------------------------------------------------------------------------
// Actions (api/automation_api.php)
// ---------------------------------------------------------------------------

/**
 * One action of the tab. The caller has checked can($module, 'manage') and
 * the CSRF token. $do: panel | save | switch_preview | enable | disable |
 * test | run_people. Answers carry the panel again where it changed.
 *
 * @return array{status:string, message?:string, html?:string, line?:string, people?:list<array>}
 */
function automation_tab_action(PDO $pdo, string $module, string $do, array $in, int $viewerId): array
{
    if (!isset(AUTOMATION_KINDS[$module])) {
        return ['status' => 'error', 'message' => 'Unknown module.'];
    }
    $rule = automation_tab_rule($pdo, $module);
    if ($rule === null) {
        return ['status' => 'error', 'message' => 'Automations are being set up. Try again later.'];
    }
    $panel = static fn (array $o = []): string => automation_tab_html($pdo, $module, $o);
    switch ($do) {
        case 'panel':
            return ['status' => 'success', 'html' => $panel()];

        case 'save':
            $r = automation_rule_save($pdo, $module, automation_tab_apply($rule, $in), $viewerId,
                isset($in['version']) ? (int) $in['version'] : null);
            if (empty($r['ok'])) {
                return ['status' => 'error', 'message' => implode(' ', array_slice((array) $r['errors'], 0, 3))];
            }
            return ['status' => 'success', 'message' => 'Saved.', 'html' => $panel()];

        case 'switch_preview':
            return ['status' => 'success', 'line' => automation_tab_switch_line(automation_tab_switch_preview($pdo, $rule))];

        case 'enable':
            $start = ($in['start'] ?? 'fresh') === 'include' ? 'include' : 'fresh';
            $r = automation_rule_set_enabled($pdo, (int) $rule['id'], true, $viewerId, $start);
            if (empty($r['ok'])) {
                return ['status' => 'error', 'message' => implode(' ', array_slice((array) $r['errors'], 0, 3))];
            }
            return ['status' => 'success', 'message' => 'Switched on.', 'html' => $panel()];

        case 'disable':
            automation_rule_set_enabled($pdo, (int) $rule['id'], false, $viewerId);
            return ['status' => 'success', 'message' => 'Switched off.', 'html' => $panel()];

        case 'test':
            $r = automation_tab_test_send($pdo, $rule, (string) ($in['step'] ?? ''), max(1, (int) ($in['week'] ?? 1)), $viewerId);
            return ['status' => $r['ok'] ? 'success' : 'error', 'message' => $r['message']];

        case 'run_people':
            return ['status' => 'success', 'people' => automation_tab_run_people($pdo, $rule, (int) ($in['run'] ?? 0))];
    }
    return ['status' => 'error', 'message' => 'Unknown action.'];
}
