<?php
// /includes/campaigns/campaign_actions.php
//
// The Campaigns screen's actions (api/campaigns_api.php → campaign_action()),
// with every access decision in one place:
//   - a campaign's target is its department, or 'church' (campaign_target())
//   - see it:      can('campaigns', 'view', target), or you wrote it
//   - change/send: can('campaigns', 'edit', target), or you wrote it and may
//                  still create for that target
//   - delete:      the same with 'delete' (drafts and stopped campaigns only)
//   - new:         can('campaigns', 'create', 'church') for church campaigns,
//                  else a department you hold Campaigns for (department
//                  campaigns go to that department's people only)
//   - settings:    can('campaigns', 'manage')
// The library (campaigns.php) never checks access itself.

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/editor.php';

const CAMPAIGN_SETTINGS = ['hourly_cap', 'window_start', 'window_end', 'postal_line', 'paused'];

/** What the signed-in person may send to: the whole church, and/or these departments. */
function campaign_sender(): array
{
    $church = can('campaigns', 'create', 'church');
    $scope = $church ? ['church' => true, 'depts' => [], 'own' => true] : perm_scope('campaigns', 'create');
    return ['church' => $church, 'depts' => array_values(array_map('intval', $scope['depts']))];
}

function campaign_may(string $what, array $c, int $me): bool
{
    $t = campaign_target($c);
    $mine = (int) ($c['created_by'] ?? 0) === $me && $me > 0;
    return match ($what) {
        'view'   => can('campaigns', 'view', $t) || $mine,
        'edit'   => can('campaigns', 'edit', $t) || ($mine && can('campaigns', 'create', $t)),
        'delete' => can('campaigns', 'delete', $t) || ($mine && can('campaigns', 'create', $t)),
        default  => false,
    };
}

/** The campaign as the screen needs it, with what this person may do to it. */
function campaign_for_screen(PDO $pdo, array $c, int $me): array
{
    $edit = campaign_may('edit', $c, $me);
    $sender = campaign_sender();
    return [
        'id'            => $c['id'],
        'name'          => (string) $c['name'],
        'subject'       => (string) $c['subject'],
        'status'        => (string) $c['status'],
        'department_id' => $c['department_id'],
        'audience'      => $c['audience'],
        'reply_to'      => (string) ($c['reply_to'] ?? ''),
        'sms_fallback'  => (bool) $c['sms_fallback'],
        'sms_text'      => (string) ($c['sms_text'] ?? ''),
        'track_opens'   => (bool) $c['track_opens'],
        'scheduled_at'  => $c['scheduled_at'],
        'queued_at'     => $c['queued_at'],
        'finished_at'   => $c['finished_at'],
        'counts'        => ['total' => $c['total'], 'email' => $c['emailed'], 'sms' => $c['texted'], 'none' => $c['skipped']],
        'version'       => (string) ($c['updated_at'] ?? $c['created_at']),
        'can'           => [
            'edit'   => $edit && in_array($c['status'], CAMPAIGN_EDITABLE, true),
            'send'   => $edit && in_array($c['status'], CAMPAIGN_EDITABLE, true),
            'cancel' => $edit && in_array($c['status'], ['scheduled', 'queued', 'sending'], true),
            'delete' => campaign_may('delete', $c, $me) && in_array($c['status'], ['draft', 'cancelled', 'failed'], true) && $c['queued_at'] === null,
            'copy'   => $sender['church'] || $sender['depts'] !== [],
        ],
        'problems'      => in_array($c['status'], CAMPAIGN_EDITABLE, true) ? campaign_problems($pdo, $c) : [],
    ];
}

/** The list row: name, status and the numbers. */
function campaign_list_row(array $c): array
{
    return [
        'id' => $c['id'], 'name' => (string) $c['name'], 'subject' => (string) $c['subject'], 'status' => (string) $c['status'],
        'department_id' => $c['department_id'], 'scheduled_at' => $c['scheduled_at'], 'queued_at' => $c['queued_at'],
        'total' => $c['total'], 'emailed' => $c['emailed'], 'texted' => $c['texted'],
        'when' => (string) ($c['queued_at'] ?? $c['scheduled_at'] ?? $c['updated_at'] ?? $c['created_at']),
    ];
}

/** "2026-11-20T18:30" from the time picker, in Lagos. */
function campaign_parse_when(string $v): ?DateTimeImmutable
{
    $v = trim($v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $v)) {
        return null;
    }
    $at = DateTimeImmutable::createFromFormat('Y-m-d H:i', str_replace('T', ' ', $v), new DateTimeZone(CAMPAIGNS_TZ));
    return $at === false ? null : $at;
}

/**
 * One action. The caller has checked sign-in, campaigns view access and CSRF.
 * @return array{status:string, ...}
 */
function campaign_action(PDO $pdo, string $do, array $in, int $me): array
{
    $fail = static fn (string $msg, array $errors = [], ?string $code = null): array =>
        ['status' => 'error', 'message' => $msg] + ($errors !== [] ? ['errors' => $errors] : []) + ($code ? ['code' => $code] : []);
    $load = static function () use ($pdo, $in, $me): ?array {
        $c = campaign_get($pdo, (int) ($in['id'] ?? 0));
        return $c !== null && campaign_may('view', $c, $me) ? $c : null;
    };
    $sender = campaign_sender();
    $version = isset($in['version']) && (string) $in['version'] !== '' ? (string) $in['version'] : null;

    switch ($do) {
        case 'list':
            $rows = campaign_list($pdo, perm_scope('campaigns', 'view'), $me);
            return ['status' => 'success', 'campaigns' => array_map('campaign_list_row', $rows)];

        case 'options':
            return ['status' => 'success', 'options' => campaign_audience_options($pdo, $sender['church'], $sender['depts']),
                    'sender' => $sender];

        case 'get':
            $c = $load();
            if ($c === null) {
                return $fail('That campaign no longer exists.', [], 'NOT_FOUND');
            }
            return ['status' => 'success', 'campaign' => campaign_for_screen($pdo, $c, $me),
                    'mailbox' => campaign_department_mailbox($pdo, $c['department_id'])];

        case 'mailbox':
            return ['status' => 'success', 'mailbox' => campaign_department_mailbox($pdo, (int) ($in['department_id'] ?? 0) ?: null)];

        case 'save':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            if ($id !== null) {
                $c = $load();
                if ($c === null || !campaign_may('edit', $c, $me)) {
                    return $fail('You cannot change this campaign.', [], 'FORBIDDEN');
                }
            } elseif (!$sender['church'] && $sender['depts'] === []) {
                return $fail('You cannot start a campaign.', [], 'FORBIDDEN');
            }
            $r = campaign_save($pdo, $id, $in, $me, $sender['church'], $sender['depts'], $id !== null ? $version : null);
            if (!$r['ok']) {
                return $fail(implode(' ', array_slice($r['errors'], 0, 3)), $r['errors'], $r['code'] ?? null);
            }
            $c = campaign_get($pdo, (int) $r['id']);
            return ['status' => 'success', 'message' => $id === null ? 'Campaign started.' : 'Saved.',
                    'campaign' => campaign_for_screen($pdo, $c, $me)];

        case 'count':
            $aud = campaign_audience_check((array) ($in['audience'] ?? []), (int) ($in['department_id'] ?? 0) ?: null,
                $sender['church'], $sender['depts']);
            if (!$aud['ok']) {
                return ['status' => 'success', 'summary' => null, 'errors' => $aud['errors']];
            }
            return ['status' => 'success', 'summary' => campaign_summary($pdo, $aud['audience'], !empty($in['sms_fallback'])),
                    'reasons' => CAMPAIGN_REASONS];

        case 'email_doc':
            $c = $load();
            if ($c === null) {
                return $fail('That campaign no longer exists.', [], 'NOT_FOUND');
            }
            return ['status' => 'success', 'doc' => $c['doc'], 'version' => (string) ($c['updated_at'] ?? $c['created_at'])];

        case 'save_email':
            $c = $load();
            if ($c === null || !campaign_may('edit', $c, $me)) {
                return $fail('You cannot change this campaign.', [], 'FORBIDDEN');
            }
            $r = campaign_save_doc($pdo, (int) $c['id'], $in['doc'] ?? null, $me, $version);
            if (!$r['ok']) {
                return $fail(implode(' ', array_slice($r['errors'], 0, 3)), $r['errors'], $r['code'] ?? null);
            }
            return ['status' => 'success', 'message' => 'Email saved.',
                    'campaign' => campaign_for_screen($pdo, (array) campaign_get($pdo, (int) $c['id']), $me)];

        case 'preview':
            $c = $load();
            if ($c === null) {
                return $fail('That campaign no longer exists.', [], 'NOT_FOUND');
            }
            $d = email_editor_draft_put($c['doc']);
            return ['status' => 'success', 'url' => '/api/email_draft_preview.php?d=' . rawurlencode($d['token']), 'errors' => $d['errors']];

        case 'test':
            $c = $load();
            if ($c === null || !campaign_may('edit', $c, $me)) {
                return $fail('You cannot send this campaign.', [], 'FORBIDDEN');
            }
            $r = campaign_test_send($pdo, $c, $me);
            return $r['ok'] ? ['status' => 'success', 'message' => $r['message']] : $fail($r['message']);

        case 'send':
            $c = $load();
            if ($c === null || !campaign_may('edit', $c, $me)) {
                return $fail('You cannot send this campaign.', [], 'FORBIDDEN');
            }
            $at = null;
            if (!empty($in['at'])) {
                $at = campaign_parse_when((string) $in['at']);
                if ($at === null) {
                    return $fail('Pick a date and time.');
                }
            }
            $r = campaign_send($pdo, (int) $c['id'], $at, $me, $version);
            if (!$r['ok']) {
                return $fail(implode(' ', array_slice($r['errors'], 0, 3)), $r['errors'], $r['code'] ?? null);
            }
            return ['status' => 'success', 'message' => $r['message'],
                    'campaign' => campaign_for_screen($pdo, (array) campaign_get($pdo, (int) $c['id']), $me)];

        case 'unschedule':
            $c = $load();
            if ($c === null || !campaign_may('edit', $c, $me)) {
                return $fail('You cannot change this campaign.', [], 'FORBIDDEN');
            }
            if (!campaign_unschedule($pdo, (int) $c['id'], $me)) {
                return $fail('It is already going out.');
            }
            return ['status' => 'success', 'message' => 'Back to draft.',
                    'campaign' => campaign_for_screen($pdo, (array) campaign_get($pdo, (int) $c['id']), $me)];

        case 'cancel':
            $c = $load();
            if ($c === null || !campaign_may('edit', $c, $me)) {
                return $fail('You cannot stop this campaign.', [], 'FORBIDDEN');
            }
            $r = campaign_cancel($pdo, (int) $c['id'], $me);
            return $r['ok'] ? ['status' => 'success', 'message' => $r['message'],
                               'campaign' => campaign_for_screen($pdo, (array) campaign_get($pdo, (int) $c['id']), $me)]
                            : $fail($r['message']);

        case 'delete':
            $c = $load();
            if ($c === null || !campaign_may('delete', $c, $me)) {
                return $fail('You cannot delete this campaign.', [], 'FORBIDDEN');
            }
            return campaign_delete($pdo, (int) $c['id'])
                ? ['status' => 'success', 'message' => 'Deleted.']
                : $fail('Only drafts that never went out can be deleted.');

        case 'copy':
            $c = $load();
            if ($c === null) {
                return $fail('That campaign no longer exists.', [], 'NOT_FOUND');
            }
            $t = campaign_target($c);
            if (!can('campaigns', 'create', $t)) {
                return $fail('You cannot start a campaign for that department.', [], 'FORBIDDEN');
            }
            return ['status' => 'success', 'message' => 'Copied.', 'id' => campaign_duplicate($pdo, $c, $me)];

        case 'report':
            $c = $load();
            if ($c === null) {
                return $fail('That campaign no longer exists.', [], 'NOT_FOUND');
            }
            return ['status' => 'success', 'campaign' => campaign_for_screen($pdo, $c, $me), 'report' => campaign_report($pdo, $c)];

        case 'settings':
            if (!can('campaigns', 'manage')) {
                return $fail('You do not have access to this.', [], 'FORBIDDEN');
            }
            return ['status' => 'success', 'settings' => campaign_settings_view($pdo)];

        case 'settings_save':
            if (!can('campaigns', 'manage')) {
                return $fail('You do not have access to this.', [], 'FORBIDDEN');
            }
            $r = campaign_settings_save($pdo, $in, $me);
            return $r === [] ? ['status' => 'success', 'message' => 'Settings saved.', 'settings' => campaign_settings_view($pdo)]
                             : $fail(implode(' ', $r), $r);
    }
    return $fail('Unknown action.');
}

/** The email settings a manager may change, plus how the hour and the bounce reader stand. */
function campaign_settings_view(PDO $pdo): array
{
    $s = [];
    foreach (CAMPAIGN_SETTINGS as $k) {
        $s[$k] = email_setting($pdo, $k);
    }
    $b = email_budget_status($pdo);
    return $s + [
        'budget'       => ['cap' => $b['cap'], 'usable' => $b['usable'], 'bulk' => $b['bulk_limit'], 'sent' => $b['sent_total'], 'refused' => $b['refused']],
        'learned_cap'  => email_setting($pdo, 'learned_cap'),
        'bounces_last' => trim(email_setting($pdo, 'bounces_last_at') . ' ' . email_setting($pdo, 'bounces_last')),
    ];
}

/** @return list<string> errors (empty = saved) */
function campaign_settings_save(PDO $pdo, array $in, int $me): array
{
    $errors = [];
    $cap = trim((string) ($in['hourly_cap'] ?? ''));
    if (!ctype_digit($cap) || (int) $cap < 10 || (int) $cap > 10000) {
        $errors[] = 'Hourly limit: a number from 10 to 10000.';
    }
    $start = campaigns_minutes((string) ($in['window_start'] ?? ''));
    $end = campaigns_minutes((string) ($in['window_end'] ?? ''));
    if ($start === null || $end === null || $start >= $end) {
        $errors[] = 'Sending hours: a start before the end.';
    }
    $postal = campaigns_cut(trim(str_replace(["\r", "\n"], ' ', (string) ($in['postal_line'] ?? ''))), 200);
    if ($postal === '') {
        $errors[] = 'Postal address: needed in every church email.';
    }
    if ($errors !== []) {
        return $errors;
    }
    email_setting_set($pdo, 'hourly_cap', (string) (int) $cap, $me);
    email_setting_set($pdo, 'window_start', sprintf('%02d:%02d', intdiv($start, 60), $start % 60), $me);
    email_setting_set($pdo, 'window_end', sprintf('%02d:%02d', intdiv($end, 60), $end % 60), $me);
    email_setting_set($pdo, 'postal_line', $postal, $me);
    email_setting_set($pdo, 'paused', !empty($in['paused']) ? '1' : '0', $me);
    email_settings_all($pdo, true);
    return [];
}
