<?php
// /includes/campaigns/automation.php
//
// ONE automation engine for every module. A rule belongs to a module and a
// kind (embrace/journey, assimilation/absence, charis/absence); its steps say
// when, to whom, and what (an email document + a short SMS for the person, or
// a bell/push for the team or the pastor). journey.php and absence.php only
// say WHO is due; everything below decides HOW and makes sure it happens once.
//
// The guarantees, each enforced by a unique key, never by check-then-insert:
//   - an occasion (a rule's Monday 10:00, say) runs once: automation_runs
//     UNIQUE (rule_id, occasion_key), claimed before any work;
//   - a step reaches a person once per week/streak: automation_sends UNIQUE
//     (rule_id, step_key, user_id, dedupe_key), claimed before anything is
//     queued, so a re-run, a crash or two racing ticks cannot double-send;
//   - one member-facing automation message per person per day across ALL
//     modules: automation_person_day PRIMARY KEY (user_id, day). A date-bound
//     reminder that loses is skipped; anything else is deferred to a later day.
//
// For each member-facing message: email when the person has a confirmed
// address and said yes to church news; otherwise a short SMS through SMS
// Studio's own queue (sms_campaigns + sms_queue: its spam guard, suppression,
// wallet check and delivery reports all apply; sms_log is never written here);
// otherwise skip. Someone who unsubscribed is skipped, not texted instead.

// Team and pastor audiences come from the permission engine. db.php loads it on
// the web and in cron; CLI harnesses that skip db.php still need it here.
require_once __DIR__ . '/../permissions.php';

const AUTOMATION_KINDS = ['embrace' => 'journey', 'assimilation' => 'absence', 'charis' => 'absence'];
const AUTOMATION_TARGETS = ['member', 'team', 'pastor'];
const AUTOMATION_LOCK = 'hod_automation_tick';
const AUTOMATION_CATCHUP_HOURS = 24;   // an occasion missed for longer is not replayed
const AUTOMATION_DEFER_DAYS = 6;       // a deferred message older than this expires
const AUTOMATION_SMS_MAX = 459;        // three SMS pages
const AUTOMATION_DEPARTED = ['Relocated', 'Attends_Another_Church', 'Attends Another Church'];
const AUTOMATION_MODULE_LABELS = ['embrace' => 'Embrace', 'assimilation' => 'Assimilation', 'charis' => 'Charis'];
const AUTOMATION_MODULE_LINKS = [
    'embrace'      => '/modules/embrace/index.php',
    'assimilation' => '/modules/assimilation/index.php',
    'charis'       => '/modules/charis/index.php',
];
const AUTOMATION_DELIVERED = ['queued', 'alerted'];

// ---------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------

function automation_rule_decode(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['is_enabled'] = (int) $row['is_enabled'] === 1;
    $row['config'] = json_decode((string) $row['config_json'], true) ?: [];
    unset($row['config_json']);
    return $row;
}

function automation_rule_get(PDO $pdo, string $module, ?string $kind = null): ?array
{
    if (!campaigns_table_exists($pdo, 'automation_rules')) {
        return null;
    }
    $kind ??= AUTOMATION_KINDS[$module] ?? '';
    $stmt = $pdo->prepare('SELECT * FROM automation_rules WHERE module = ? AND kind = ?');
    $stmt->execute([$module, $kind]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? automation_rule_decode($row) : null;
}

function automation_rule_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM automation_rules WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? automation_rule_decode($row) : null;
}

/** @return list<array> */
function automation_rules_enabled(PDO $pdo): array
{
    if (!campaigns_table_exists($pdo, 'automation_rules')) {
        return [];
    }
    return array_map('automation_rule_decode',
        $pdo->query('SELECT * FROM automation_rules WHERE is_enabled = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}

/** Create the default rules that do not exist yet — all switched OFF. */
function automation_ensure_defaults(PDO $pdo): int
{
    if (!campaigns_table_exists($pdo, 'automation_rules')) {
        return 0;
    }
    $made = 0;
    foreach (AUTOMATION_KINDS as $module => $kind) {
        if (automation_rule_get($pdo, $module, $kind) !== null) {
            continue;
        }
        $config = automation_default_config($module);
        $check = automation_config_validate($kind, $config);
        if (!$check['ok']) {
            error_log('[automation] default ' . $module . ' rule is invalid: ' . implode('; ', $check['errors']));
            continue;
        }
        try {
            $pdo->prepare('INSERT INTO automation_rules (module, kind, name, is_enabled, config_json, version, created_at)
                           VALUES (?, ?, ?, 0, ?, 1, ?)')
                ->execute([$module, $kind, automation_default_name($module), campaigns_json($check['config']), campaigns_sql_dt(campaigns_now())]);
            $made++;
        } catch (PDOException $e) {
            if (!campaigns_is_duplicate_key($e)) {
                throw $e;
            }
        }
    }
    return $made;
}

/**
 * Save a rule's settings (the Automation tab). Validated in full; the rule's
 * version moves so the tab can spot an edit made by someone else meanwhile.
 *
 * @return array{ok:bool, errors:list<string>, rule?:array}
 */
function automation_rule_save(PDO $pdo, string $module, array $config, int $byUserId, ?int $expectVersion = null): array
{
    $kind = AUTOMATION_KINDS[$module] ?? null;
    if ($kind === null) {
        return ['ok' => false, 'errors' => ['Unknown module.']];
    }
    if (!campaigns_ready($pdo)) {
        return ['ok' => false, 'errors' => ['Automations are being set up. Please try again later.'], 'code' => 'FEATURE_NOT_READY'];
    }
    $check = automation_config_validate($kind, $config);
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    automation_ensure_defaults($pdo);
    $rule = automation_rule_get($pdo, $module, $kind);
    $sql = 'UPDATE automation_rules SET config_json = ?, version = version + 1, updated_by = ?, updated_at = ? WHERE id = ?';
    $params = [campaigns_json($check['config']), $byUserId, campaigns_sql_dt(campaigns_now()), $rule['id']];
    if ($expectVersion !== null) {
        $sql .= ' AND version = ?';
        $params[] = $expectVersion;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'errors' => ['Someone else changed these settings meanwhile. Reload to see their changes.']];
    }
    return ['ok' => true, 'errors' => [], 'rule' => automation_rule_get($pdo, $module, $kind)];
}

/**
 * Switch a rule on or off. Switching on with $start = 'fresh' records the
 * people who are ALREADY past a step as "baselined": only people who cross a
 * step from now on hear anything (no flood of pastor alerts on day one).
 * 'include' lets the next occasion reach them.
 */
function automation_rule_set_enabled(PDO $pdo, int $ruleId, bool $on, int $byUserId, string $start = 'fresh'): array
{
    if (!campaigns_ready($pdo)) {
        return ['ok' => false, 'errors' => ['Automations are being set up. Please try again later.'], 'code' => 'FEATURE_NOT_READY'];
    }
    $rule = automation_rule_by_id($pdo, $ruleId);
    if ($rule === null) {
        return ['ok' => false, 'errors' => ['Unknown rule.']];
    }
    $now = campaigns_now();
    if (!$on) {
        $pdo->prepare('UPDATE automation_rules SET is_enabled = 0, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([$byUserId, campaigns_sql_dt($now), $ruleId]);
        return ['ok' => true, 'errors' => [], 'baselined' => 0];
    }
    $check = automation_config_validate((string) $rule['kind'], $rule['config']);
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    $baselined = $start === 'fresh' ? automation_baseline($pdo, $rule, $now) : 0;
    $pdo->prepare('UPDATE automation_rules SET is_enabled = 1, enabled_at = ?, baseline_at = ?, updated_by = ?, updated_at = ? WHERE id = ?')
        ->execute([campaigns_sql_dt($now), $start === 'fresh' ? campaigns_sql_dt($now) : null, $byUserId, campaigns_sql_dt($now), $ruleId]);
    return ['ok' => true, 'errors' => [], 'baselined' => $baselined];
}

// ---------------------------------------------------------------------------
// Rule settings: validation
// ---------------------------------------------------------------------------

/** @return array{ok:bool, errors:list<string>, config:array} */
function automation_config_validate(string $kind, array $config): array
{
    $errors = [];
    $out = [];
    $out['from_name'] = trim(str_replace(["\r", "\n"], ' ', (string) ($config['from_name'] ?? '')));
    if (mb_strlen($out['from_name'], 'UTF-8') > 80) {
        $errors[] = 'The sender name is longer than 80 characters.';
    }
    $replyTo = email_normalize((string) ($config['reply_to'] ?? ''));
    if ($replyTo !== '' && !email_is_valid_syntax($replyTo)) {
        $errors[] = 'The reply-to address is not a valid email.';
    }
    $out['reply_to'] = $replyTo;

    $when = static function ($w, string $label) use (&$errors): array {
        $day = is_array($w) ? (int) ($w['weekday'] ?? -1) : -1;
        $time = is_array($w) ? (string) ($w['time'] ?? '') : '';
        if ($day < 0 || $day > 6) {
            $errors[] = "{$label}: choose a day of the week.";
        }
        if (campaigns_minutes($time) === null) {
            $errors[] = "{$label}: the time must look like 10:00.";
        }
        return ['weekday' => max(0, min(6, $day)), 'time' => $time];
    };
    $sms = static function ($text, string $label) use (&$errors): string {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if (mb_strlen($text, 'UTF-8') > AUTOMATION_SMS_MAX) {
            $errors[] = "{$label}: the SMS is longer than " . AUTOMATION_SMS_MAX . ' characters (three texts).';
        }
        foreach (email_doc_merge_fields($text) as $field) {
            if (!in_array($field, EMAIL_MERGE_FIELDS, true)) {
                $errors[] = "{$label}: the SMS uses an unknown merge field {{{$field}}}.";
            }
        }
        return $text;
    };
    $doc = static function ($d, string $label) use (&$errors): array {
        $check = email_doc_validate($d);
        foreach ($check['errors'] as $e) {
            $errors[] = "{$label}: {$e}";
        }
        return $check['doc'];
    };
    $stepKey = static function ($k, array &$seen) use (&$errors): string {
        $k = (string) $k;
        if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/', $k)) {
            $errors[] = 'A step has an invalid key.';
        } elseif (isset($seen[$k])) {
            $errors[] = "Two steps share the key {$k}.";
        }
        $seen[$k] = true;
        return $k;
    };

    $steps = $config['steps'] ?? null;
    if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
        $errors[] = 'The rule has no steps.';
        $steps = [];
    }
    $seen = [];

    if ($kind === 'journey') {
        $aud = (array) ($config['audience'] ?? []);
        $stop = (string) ($aud['stop_status'] ?? '3rd_Timer');
        if (!in_array($stop, ['2nd_Timer', '3rd_Timer', 'Visitor'], true)) {
            $errors[] = 'The journey must stop at 2nd Timer, 3rd Timer or Visitor.';
            $stop = '3rd_Timer';
        }
        $weeks = (int) ($aud['weeks'] ?? 6);
        if ($weeks < 1 || $weeks > 12) {
            $errors[] = 'The journey lasts between 1 and 12 weeks.';
        }
        $out['audience'] = ['stop_status' => $stop, 'weeks' => max(1, min(12, $weeks))];
        $out['steps'] = [];
        foreach (array_slice($steps, 0, 6) as $i => $s) {
            $label = 'Step ' . ($i + 1);
            $s = (array) $s;
            $step = [
                'key'    => $stepKey($s['key'] ?? '', $seen),
                'label'  => campaigns_cut(trim((string) ($s['label'] ?? '')), 60),
                'target' => 'member',
                'when'   => $when($s['when'] ?? null, $label),
                'date_bound' => !empty($s['date_bound']),
            ];
            if (isset($s['series'])) {
                $series = is_array($s['series']) && array_is_list($s['series']) ? array_slice($s['series'], 0, 12) : [];
                if ($series === []) {
                    $errors[] = "{$label}: the weekly series is empty.";
                }
                $step['series'] = [];
                foreach ($series as $w => $item) {
                    $item = (array) $item;
                    $step['series'][] = [
                        'email' => $doc($item['email'] ?? null, "{$label}, week " . ($w + 1)),
                        'sms'   => $sms($item['sms'] ?? '', "{$label}, week " . ($w + 1)),
                    ];
                }
            } else {
                $step['email'] = $doc($s['email'] ?? null, $label);
                $step['sms'] = $sms($s['sms'] ?? '', $label);
            }
            $out['steps'][] = $step;
        }
    } elseif ($kind === 'absence') {
        $aud = (array) ($config['audience'] ?? []);
        $statuses = array_values(array_intersect((array) ($aud['statuses'] ?? []), AUTOMATION_ABSENCE_STATUSES));
        if ($statuses === []) {
            $errors[] = 'Choose at least one status the absence check covers.';
        }
        $out['audience'] = ['statuses' => $statuses];
        $out['when'] = $when($config['when'] ?? null, 'The weekly check');
        $out['steps'] = [];
        foreach (array_slice($steps, 0, 6) as $i => $s) {
            $label = 'Step ' . ($i + 1);
            $s = (array) $s;
            $target = (string) ($s['target'] ?? '');
            if (!in_array($target, AUTOMATION_TARGETS, true)) {
                $errors[] = "{$label}: choose who it goes to.";
                $target = 'team';
            }
            $threshold = (int) ($s['threshold'] ?? 0);
            if ($threshold < 1 || $threshold > 52) {
                $errors[] = "{$label}: the number of missed Sundays must be between 1 and 52.";
            }
            $step = [
                'key'       => $stepKey($s['key'] ?? '', $seen),
                'label'     => campaigns_cut(trim((string) ($s['label'] ?? '')), 60),
                'target'    => $target,
                'threshold' => max(1, min(52, $threshold)),
            ];
            if ($target === 'member') {
                $step['email'] = $doc($s['email'] ?? null, $label);
                $step['sms'] = $sms($s['sms'] ?? '', $label);
            } else {
                $alert = (array) ($s['alert'] ?? []);
                $title = trim((string) ($alert['title'] ?? ''));
                $message = trim((string) ($alert['message'] ?? ''));
                if ($title === '' || mb_strlen($title, 'UTF-8') > 80) {
                    $errors[] = "{$label}: the alert needs a title of at most 80 characters.";
                }
                if ($message === '' || mb_strlen($message, 'UTF-8') > 300) {
                    $errors[] = "{$label}: the alert needs a message of at most 300 characters.";
                }
                foreach (array_merge(email_doc_merge_fields($title), email_doc_merge_fields($message)) as $field) {
                    if (!in_array($field, EMAIL_MERGE_FIELDS, true)) {
                        $errors[] = "{$label}: the alert uses an unknown merge field {{{$field}}}.";
                    }
                }
                $step['alert'] = ['title' => campaigns_cut($title, 80), 'message' => campaigns_cut($message, 300)];
            }
            $out['steps'][] = $step;
        }
        usort($out['steps'], static fn (array $a, array $b): int => $a['threshold'] <=> $b['threshold']);
    } else {
        $errors[] = 'Unknown kind of rule.';
    }

    return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'config' => $out];
}

// The statuses an absence rule may cover (Charis's own list, minus "Unspecified").
const AUTOMATION_ABSENCE_STATUSES = [
    'Member', 'Worker', 'Pastor', '1st_Timer', '2nd_Timer', '3rd_Timer', 'Visitor', 'Non_Member',
];

// ---------------------------------------------------------------------------
// Occasions
// ---------------------------------------------------------------------------

/** The latest $weekday at $time at or before $now. */
function automation_last_slot(DateTimeImmutable $now, int $weekday, string $time): DateTimeImmutable
{
    $now = $now->setTimezone(new DateTimeZone(CAMPAIGNS_TZ));
    $back = ((int) $now->format('w') - $weekday + 7) % 7;
    $slot = campaigns_dt($now->modify("-{$back} days")->format('Y-m-d') . ' ' . $time . ':00');
    if ($slot > $now) {
        $slot = $slot->modify('-7 days');
    }
    return $slot;
}

/**
 * Occasions due now: each slot's latest time that has passed, if under 24
 * hours ago and not before the rule was switched on.
 *
 * @return list<array{key:string, at:DateTimeImmutable, steps:list<string>}>
 */
function automation_due_occasions(array $rule, DateTimeImmutable $now, bool $ignoreEnabledAt = false): array
{
    $cfg = $rule['config'];
    $enabledAt = !$ignoreEnabledAt && !empty($rule['enabled_at']) ? campaigns_dt((string) $rule['enabled_at']) : null;
    $slots = [];
    if ($rule['kind'] === 'journey') {
        foreach ($cfg['steps'] ?? [] as $step) {
            $slots[] = ['name' => $step['key'], 'when' => $step['when'], 'steps' => [$step['key']]];
        }
    } else {
        $slots[] = ['name' => 'absence', 'when' => $cfg['when'], 'steps' => array_column($cfg['steps'] ?? [], 'key')];
    }
    $due = [];
    foreach ($slots as $slot) {
        $at = automation_last_slot($now, (int) $slot['when']['weekday'], (string) $slot['when']['time']);
        if ($now->getTimestamp() - $at->getTimestamp() >= AUTOMATION_CATCHUP_HOURS * 3600) {
            continue;
        }
        if ($enabledAt !== null && $at < $enabledAt) {
            continue;
        }
        $due[] = ['key' => $at->format('Y-m-d') . '#' . $slot['name'], 'at' => $at, 'steps' => $slot['steps']];
    }
    return $due;
}

function automation_step(array $rule, string $key): ?array
{
    foreach ($rule['config']['steps'] ?? [] as $step) {
        if ($step['key'] === $key) {
            return $step;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// The tick (cron/automation_tick.php)
// ---------------------------------------------------------------------------

/**
 * Run every due occasion of every enabled rule, then retry yesterday's
 * deferred messages. $opts: now, dry_run (plan only, writes nothing),
 * rule_id (only that rule; with dry_run it may be switched off).
 */
function automation_tick(PDO $pdo, array $opts = []): array
{
    $out = ['ran' => false, 'locked' => false, 'runs' => [], 'retried' => [], 'message' => ''];
    if (!campaigns_ready($pdo)) {
        $out['message'] = 'Automation tables are not there yet (db/migrate.php has not run).';
        return $out;
    }
    $now = ($opts['now'] ?? null) instanceof DateTimeImmutable ? $opts['now'] : campaigns_now();
    $dry = !empty($opts['dry_run']);
    if (!$dry && !campaigns_lock($pdo, AUTOMATION_LOCK, 2)) {
        $out['locked'] = true;
        $out['message'] = 'Another automation run holds the lock.';
        return $out;
    }
    try {
        $out['ran'] = true;
        if (!$dry) {
            automation_ensure_defaults($pdo);
        }
        if (!empty($opts['rule_id'])) {
            $rule = automation_rule_by_id($pdo, (int) $opts['rule_id']);
            $rules = $rule !== null && ($dry || $rule['is_enabled']) ? [$rule] : [];
        } else {
            $rules = automation_rules_enabled($pdo);
        }
        foreach ($rules as $rule) {
            // One rule's trouble never stops the others.
            try {
                foreach (automation_due_occasions($rule, $now, $dry && !$rule['is_enabled']) as $occasion) {
                    $result = automation_run_occasion($pdo, $rule, $occasion, ['dry_run' => $dry, 'now' => $now]);
                    if ($result !== null) {
                        $out['runs'][] = $result;
                    }
                }
            } catch (Throwable $e) {
                error_log('[automation] rule ' . $rule['id'] . ' could not run: ' . $e->getMessage());
            }
        }
        if (!$dry) {
            try {
                $out['retried'] = automation_retry_deferred($pdo, $now);
            } catch (Throwable $e) {
                error_log('[automation] deferred retry failed: ' . $e->getMessage());
            }
        }
        $out['message'] = count($out['runs']) . ' occasion(s) run' . ($dry ? ' (dry run: nothing written)' : '');
    } finally {
        if (!$dry) {
            campaigns_unlock($pdo, AUTOMATION_LOCK);
        }
    }
    return $out;
}

/**
 * Run one occasion: claim it, find who is due, deliver to each, hand the SMS
 * batch to SMS Studio, record the counts. Null when it already ran.
 */
function automation_run_occasion(PDO $pdo, array $rule, array $occasion, array $opts = []): ?array
{
    $dry = !empty($opts['dry_run']);
    $now = ($opts['now'] ?? null) instanceof DateTimeImmutable ? $opts['now'] : campaigns_now();
    $runId = null;
    if (!$dry) {
        try {
            $pdo->prepare("INSERT INTO automation_runs (rule_id, occasion_key, occasion_at, status, started_at) VALUES (?, ?, ?, 'running', ?)")
                ->execute([$rule['id'], $occasion['key'], campaigns_sql_dt($occasion['at']), campaigns_sql_dt($now)]);
            $runId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if (!campaigns_is_duplicate_key($e)) {
                throw $e;
            }
            // Already claimed. A run that FAILED may be picked up again (at most
            // every 30 minutes, while the occasion is still due): the ledger
            // makes sure nobody it already reached hears twice.
            $again = $pdo->prepare("UPDATE automation_runs SET status = 'running', started_at = ?
                                     WHERE rule_id = ? AND occasion_key = ? AND status = 'failed' AND started_at <= ?");
            $again->execute([campaigns_sql_dt($now), $rule['id'], $occasion['key'], campaigns_sql_dt($now->modify('-30 minutes'))]);
            if ($again->rowCount() !== 1) {
                return null;
            }
            $find = $pdo->prepare('SELECT id FROM automation_runs WHERE rule_id = ? AND occasion_key = ?');
            $find->execute([$rule['id'], $occasion['key']]);
            $runId = (int) $find->fetchColumn();
        }
    }

    $ctx = automation_context($rule, $runId, $now, $dry);
    try {
        foreach (automation_candidates($pdo, $rule, $occasion) as $cand) {
            automation_deliver($pdo, $rule, $cand, $ctx);
        }
        automation_flush_sms($pdo, $rule, $ctx);
        $status = 'done';
        $detail = null;
    } catch (Throwable $e) {
        error_log('[automation] rule ' . $rule['id'] . ' occasion ' . $occasion['key'] . ' failed: ' . $e->getMessage());
        $status = 'failed';
        $detail = campaigns_cut($e->getMessage(), 500);
        automation_flush_sms($pdo, $rule, $ctx); // whoever was already claimed still gets their text (never twice)
    }

    if (!$dry) {
        $pdo->prepare('UPDATE automation_runs SET status = ?, people = ?, emailed = ?, texted = ?, alerted = ?, skipped = ?,
                              sms_campaign_id = ?, detail = ?, finished_at = ? WHERE id = ?')
            ->execute([$status, $ctx['counts']['people'], $ctx['counts']['emailed'], $ctx['counts']['texted'],
                       $ctx['counts']['alerted'], $ctx['counts']['skipped'], $ctx['sms_campaign_id'], $detail,
                       campaigns_sql_dt(campaigns_now()), $runId]);
    }
    return [
        'rule_id'   => $rule['id'],
        'module'    => $rule['module'],
        'occasion'  => $occasion['key'],
        'run_id'    => $runId,
        'status'    => $status,
        'counts'    => $ctx['counts'],
        'decisions' => $ctx['decisions'],
    ];
}

function automation_context(array $rule, ?int $runId, DateTimeImmutable $now, bool $dry): array
{
    return [
        'run_id'          => $runId,
        'now'             => $now,
        'day'             => $now->format('Y-m-d'),
        'dry'             => $dry,
        'sms'             => [],
        'sms_campaign_id' => null,
        'counts'          => ['people' => 0, 'emailed' => 0, 'texted' => 0, 'alerted' => 0, 'skipped' => 0, 'deferred' => 0],
        'decisions'       => [],
    ];
}

/**
 * Who is due on this occasion. Each candidate:
 *   user (id, first_name, last_name, phone, …), step (config), dedupe (week /
 *   streak / date), vars (merge values), variant (series index), date_bound,
 *   superseded (step keys the catch-up skips).
 */
function automation_candidates(PDO $pdo, array $rule, array $occasion): array
{
    if ($rule['kind'] === 'journey') {
        return journey_candidates($pdo, $rule, $occasion);
    }
    if ($rule['kind'] === 'absence') {
        return absence_candidates($pdo, $rule, $occasion);
    }
    return [];
}

// ---------------------------------------------------------------------------
// Delivery
// ---------------------------------------------------------------------------

/** Claim a ledger row. Null when that person already had this step this week/streak. */
function automation_ledger_claim(PDO $pdo, int $ruleId, string $stepKey, int $userId, string $dedupe, ?int $runId, string $outcome, ?string $detail = null): ?int
{
    try {
        $pdo->prepare('INSERT INTO automation_sends (rule_id, step_key, user_id, dedupe_key, run_id, channel, outcome, detail, created_at)
                       VALUES (?, ?, ?, ?, ?, \'none\', ?, ?, ?)')
            ->execute([$ruleId, $stepKey, $userId, campaigns_cut($dedupe, 40), $runId, $outcome,
                       $detail !== null ? campaigns_cut($detail, 255) : null, campaigns_sql_dt(campaigns_now())]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (campaigns_is_duplicate_key($e)) {
            return null;
        }
        throw $e;
    }
}

function automation_ledger_exists(PDO $pdo, int $ruleId, string $stepKey, int $userId, string $dedupe): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM automation_sends WHERE rule_id = ? AND step_key = ? AND user_id = ? AND dedupe_key = ?');
    $stmt->execute([$ruleId, $stepKey, $userId, campaigns_cut($dedupe, 40)]);
    return (bool) $stmt->fetchColumn();
}

function automation_ledger_finish(PDO $pdo, int $sendId, string $outcome, string $channel, array $extra = []): void
{
    $pdo->prepare('UPDATE automation_sends SET outcome = ?, channel = ?, outbox_id = ?, detail = ? WHERE id = ?')
        ->execute([$outcome, $channel, $extra['outbox_id'] ?? null,
                   isset($extra['detail']) ? campaigns_cut((string) $extra['detail'], 255) : null, $sendId]);
}

/**
 * Claim today for this person's one member-facing automation message.
 * False = another rule already reached them today.
 */
function automation_person_day_claim(PDO $pdo, int $userId, string $day, int $ruleId, string $stepKey, bool $dry): bool
{
    if ($dry) {
        $stmt = $pdo->prepare('SELECT 1 FROM automation_person_day WHERE user_id = ? AND day = ?');
        $stmt->execute([$userId, $day]);
        return !$stmt->fetchColumn();
    }
    try {
        $pdo->prepare('INSERT INTO automation_person_day (user_id, day, rule_id, step_key, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $day, $ruleId, $stepKey, campaigns_sql_dt(campaigns_now())]);
        return true;
    } catch (PDOException $e) {
        if (campaigns_is_duplicate_key($e)) {
            return false;
        }
        throw $e;
    }
}

function automation_person_day_release(PDO $pdo, int $userId, string $day, int $ruleId): void
{
    $pdo->prepare('DELETE FROM automation_person_day WHERE user_id = ? AND day = ? AND rule_id = ?')
        ->execute([$userId, $day, $ruleId]);
}

/**
 * Deliver one candidate. $ctx carries the run, the SMS batch and the counts.
 * $sendId: an existing ledger row (a deferred message being retried).
 *
 * @return string the outcome
 */
function automation_deliver(PDO $pdo, array $rule, array $cand, array &$ctx, ?int $sendId = null): string
{
    $step = $cand['step'];
    $user = $cand['user'];
    $uid = (int) $user['id'];
    $dry = $ctx['dry'];
    $ruleId = (int) $rule['id'];
    $decide = static function (string $outcome, string $channel, ?string $detail = null) use (&$ctx, $uid, $user, $step): string {
        $ctx['decisions'][] = [
            'user_id' => $uid,
            'name'    => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'step'    => $step['key'],
            'channel' => $channel,
            'outcome' => $outcome,
            'detail'  => $detail,
        ];
        if (in_array($outcome, ['skipped_no_contact', 'skipped_consent', 'skipped_same_day', 'failed'], true)) {
            $ctx['counts']['skipped']++;
        } elseif ($outcome === 'deferred') {
            $ctx['counts']['deferred']++;
        }
        return $outcome;
    };

    foreach ($cand['superseded'] ?? [] as $skipKey) {
        if (!$dry) {
            automation_ledger_claim($pdo, $ruleId, $skipKey, $uid, (string) $cand['dedupe'], $ctx['run_id'], 'superseded',
                'Passed by a later step in one go');
        }
    }

    if ($sendId === null) {
        if ($dry) {
            if (automation_ledger_exists($pdo, $ruleId, $step['key'], $uid, (string) $cand['dedupe'])) {
                return 'already';
            }
        } else {
            $sendId = automation_ledger_claim($pdo, $ruleId, $step['key'], $uid, (string) $cand['dedupe'], $ctx['run_id'], 'pending');
            if ($sendId === null) {
                return 'already';
            }
        }
    }
    $ctx['counts']['people']++;
    $finish = static function (string $outcome, string $channel, array $extra = []) use ($pdo, &$sendId, $dry): void {
        if (!$dry && $sendId !== null) {
            automation_ledger_finish($pdo, $sendId, $outcome, $channel, $extra);
        }
    };

    // Staff steps: a bell (and so a push) to the team or the pastor.
    if ($step['target'] !== 'member') {
        $ids = $step['target'] === 'pastor' ? automation_pastor_ids($pdo, $uid) : automation_team_ids($pdo, (string) $rule['module']);
        $ids = array_values(array_diff($ids, [$uid]));
        if ($ids === []) {
            $finish('skipped_no_contact', 'none', ['detail' => 'Nobody to alert']);
            return $decide('skipped_no_contact', 'none', 'Nobody to alert');
        }
        if (!$dry) {
            automation_alert($pdo, $rule, $step, $cand, $ids);
        }
        $ctx['counts']['alerted'] += count($ids);
        $finish('alerted', 'alert', ['detail' => count($ids) . ' notified']);
        return $decide('alerted', 'alert', count($ids) . ' notified');
    }

    // Member steps: pick the channel first (no point claiming the day for nobody).
    $channel = automation_choose_channel($pdo, $user, $cand);
    if ($channel['channel'] === 'none') {
        $finish($channel['outcome'], 'none', ['detail' => $channel['detail']]);
        return $decide($channel['outcome'], 'none', $channel['detail']);
    }
    if (!automation_person_day_claim($pdo, $uid, $ctx['day'], $ruleId, $step['key'], $dry)) {
        $outcome = !empty($cand['date_bound']) ? 'skipped_same_day' : 'deferred';
        $detail = 'Another automation message reached them today';
        $finish($outcome, 'none', ['detail' => $detail]);
        return $decide($outcome, 'none', $detail);
    }

    if ($channel['channel'] === 'email') {
        if ($dry) {
            $ctx['counts']['emailed']++;
            return $decide('queued', 'email', $channel['email']);
        }
        $cfg = $rule['config'];
        $doc = automation_step_email($step, (int) ($cand['variant'] ?? 0));
        $sent = email_send_doc($pdo, $doc, $user + ['email' => $channel['email']], [
            'lane'               => 'automation',
            'purpose'            => 'news',
            'flow'               => $rule['module'] . '.' . $step['key'],
            'to_email'           => $channel['email'],
            'from_name'          => $cfg['from_name'] !== '' ? $cfg['from_name'] : null,
            'reply_to'           => $cfg['reply_to'] !== '' ? $cfg['reply_to'] : null,
            'vars'               => (array) ($cand['vars'] ?? []),
            'track'              => true,
            'track_opens'        => false,   // opens are counted for campaigns only (lead, 11 Nov)
            // A date-bound reminder is dropped rather than arriving after its day.
            'send_by'            => !empty($cand['date_bound']) ? campaigns_dt($ctx['day'] . ' 23:59:59') : null,
            'automation_send_id' => $sendId,
        ]);
        if (!$sent['ok']) {
            automation_person_day_release($pdo, $uid, $ctx['day'], $ruleId);
            $finish('failed', 'email', ['detail' => $sent['message'] ?? 'Could not queue the email']);
            return $decide('failed', 'email', $sent['message'] ?? null);
        }
        $finish('queued', 'email', ['outbox_id' => $sent['id']]);
        $ctx['counts']['emailed']++;
        return $decide('queued', 'email');
    }

    // SMS: collected per text and handed to SMS Studio at the end of the run.
    $text = automation_sms_prepare(automation_step_sms($step, (int) ($cand['variant'] ?? 0)), (array) ($cand['vars'] ?? []));
    if (!$dry) {
        $ctx['sms'][$step['key'] . "\n" . $text][] = [
            'send_id'   => $sendId,
            'recipient' => [
                'source'     => 'user',
                'source_id'  => $uid,
                'name'       => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'first_name' => (string) ($user['first_name'] ?? ''),
                'last_name'  => (string) ($user['last_name'] ?? ''),
                'phone'      => $channel['phone'],
            ],
        ];
    }
    $finish('queued', 'sms');
    $ctx['counts']['texted']++;
    return $decide('queued', 'sms');
}

function automation_step_email(array $step, int $variant): array
{
    if (isset($step['series'])) {
        $series = $step['series'];
        return $series[max(0, min($variant, count($series) - 1))]['email'];
    }
    return $step['email'];
}

function automation_step_sms(array $step, int $variant): string
{
    if (isset($step['series'])) {
        $series = $step['series'];
        return (string) $series[max(0, min($variant, count($series) - 1))]['sms'];
    }
    return (string) ($step['sms'] ?? '');
}

/**
 * Fill everything except the name fields, which SMS Studio's own renderer
 * fills per person (sms_render() knows {{first_name}}, {{last_name}},
 * {{full_name}}).
 */
function automation_sms_prepare(string $text, array $vars): string
{
    $keep = ['first_name' => '{{first_name}}', 'last_name' => '{{last_name}}', 'full_name' => '{{full_name}}'];
    $base = email_merge_vars([], $vars);
    return trim(email_merge($text, array_merge($base, $keep)));
}

/**
 * Email, SMS or nobody — and why.
 *
 * @return array{channel:string, email?:string, phone?:string, outcome?:string, detail?:?string}
 */
function automation_choose_channel(PDO $pdo, array $user, array $cand): array
{
    $uid = (int) $user['id'];
    $may = email_may_send_news($pdo, $uid);
    if ($may['ok']) {
        return ['channel' => 'email', 'email' => $may['email']];
    }
    // Asked first, whatever the address: someone who said no to church news by
    // email (even with no confirmed address on file) is skipped, never texted.
    if ($may['reason'] === 'withdrawn' || email_consent_state($pdo, $uid, 'news', 'email') === 'withdrawn') {
        return ['channel' => 'none', 'outcome' => 'skipped_consent', 'detail' => 'Unsubscribed from church news by email'];
    }
    if (email_consent_state($pdo, $uid, 'news', 'sms') === 'withdrawn') {
        return ['channel' => 'none', 'outcome' => 'skipped_consent', 'detail' => 'Asked not to receive church texts'];
    }
    if (trim(automation_step_sms($cand['step'], (int) ($cand['variant'] ?? 0))) === '') {
        return ['channel' => 'none', 'outcome' => 'skipped_no_contact', 'detail' => 'No confirmed email, and this step has no SMS'];
    }
    if (!automation_sms_available($pdo)) {
        return ['channel' => 'none', 'outcome' => 'skipped_no_contact', 'detail' => 'No confirmed email, and SMS Studio is not set up'];
    }
    $phone = automation_sms_phone((string) ($user['phone'] ?? ''));
    if ($phone === null) {
        return ['channel' => 'none', 'outcome' => 'skipped_no_contact', 'detail' => 'No confirmed email and no Nigerian mobile number'];
    }
    if (automation_sms_suppressed($pdo, $phone)) {
        return ['channel' => 'none', 'outcome' => 'skipped_no_contact', 'detail' => 'No confirmed email; the number is on SMS Studio\'s suppression list'];
    }
    return ['channel' => 'sms', 'phone' => $phone];
}

function automation_sms_available(PDO $pdo): bool
{
    return campaigns_table_exists($pdo, 'sms_campaigns') && campaigns_table_exists($pdo, 'sms_queue');
}

/**
 * SMS Studio's own phone rules. Loaded on first use: web requests (the
 * Automation tab's preview, Campaigns) do not load SMS Studio otherwise, and
 * without it every number would look unusable.
 */
function automation_sms_phone(string $raw): ?string
{
    if (!function_exists('sms_normalize_phone') && is_file(dirname(__DIR__) . '/sms_functions.php')) {
        require_once dirname(__DIR__) . '/sms_functions.php';
    }
    if (function_exists('sms_normalize_phone')) {
        $p = sms_normalize_phone($raw);
        return $p === null ? null : (string) $p;
    }
    return null;
}

function automation_sms_suppressed(PDO $pdo, string $phone): bool
{
    if (!campaigns_table_exists($pdo, 'sms_suppression')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM sms_suppression WHERE phone = ? AND released_at IS NULL');
    $stmt->execute([$phone]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Hand the run's texts to SMS Studio: one sms_campaigns row per distinct text,
 * one sms_queue row per person. SMS Studio's worker sends them with its spam
 * guard, suppression list, wallet check and delivery reports. sms_log is never
 * written here.
 */
function automation_flush_sms(PDO $pdo, array $rule, array &$ctx): void
{
    if ($ctx['dry'] || $ctx['sms'] === []) {
        return;
    }
    $label = AUTOMATION_MODULE_LABELS[$rule['module']] ?? $rule['module'];
    $insertQueue = $pdo->prepare("INSERT INTO sms_queue (campaign_id, recipient_json, status) VALUES (?, ?, 'queued')");
    foreach (array_keys($ctx['sms']) as $key) {
        // Each text is taken off the batch BEFORE it is written, and written in
        // one transaction: a failure can lose a group (recorded as failed) but
        // can never queue the same people twice.
        $people = $ctx['sms'][$key];
        unset($ctx['sms'][$key]);
        [$stepKey, $text] = explode("\n", $key, 2);
        $step = automation_step($rule, $stepKey);
        $title = 'Automation · ' . $label . ' · ' . ($step['label'] ?? $stepKey) . ' · ' . $ctx['now']->format('j M Y');
        $own = !$pdo->inTransaction();
        try {
            if ($own) {
                $pdo->beginTransaction();
            }
            $pdo->prepare("INSERT INTO sms_campaigns (title, audience, event_id, filters_json, body_template, total, status, created_by, created_at)
                           VALUES (?, 'automation', NULL, ?, ?, ?, 'queued', NULL, ?)")
                ->execute([campaigns_cut($title, 255), campaigns_json(['rule_id' => $rule['id'], 'run_id' => $ctx['run_id'], 'step' => $stepKey]),
                           $text, count($people), campaigns_sql_dt(campaigns_now())]);
            $campaignId = (int) $pdo->lastInsertId();
            foreach ($people as $p) {
                $insertQueue->execute([$campaignId, campaigns_json($p['recipient'])]);
                $queueId = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE automation_sends SET sms_queue_id = ? WHERE id = ?')->execute([$queueId, $p['send_id']]);
            }
            if ($own) {
                $pdo->commit();
            }
            $ctx['sms_campaign_id'] ??= $campaignId;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[automation] SMS hand-off for rule ' . $rule['id'] . ' step ' . $stepKey . ' failed: ' . $e->getMessage());
            foreach ($people as $p) {
                try {
                    automation_ledger_finish($pdo, (int) $p['send_id'], 'failed', 'sms', ['detail' => 'Could not hand the text to SMS Studio']);
                    automation_person_day_release($pdo, (int) $p['recipient']['source_id'], $ctx['day'], (int) $rule['id']);
                } catch (Throwable $e2) {
                    error_log('[automation] could not record the failed hand-off: ' . $e2->getMessage());
                }
            }
        }
    }
}

/** One bell (= a push) per recipient, naming the person and the streak. */
function automation_alert(PDO $pdo, array $rule, array $step, array $cand, array $recipientIds): void
{
    if (!campaigns_table_exists($pdo, 'system_notifications')) {
        return;
    }
    $vars = email_merge_vars($cand['user'], (array) ($cand['vars'] ?? []));
    $title = campaigns_cut(email_merge($step['alert']['title'], $vars), 120);
    $message = email_merge($step['alert']['message'], $vars);
    $link = AUTOMATION_MODULE_LINKS[$rule['module']] ?? '/index.php';
    $stmt = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    foreach (array_unique($recipientIds) as $id) {
        $stmt->execute([(int) $id, $title, $message, $link]);
    }
}

/**
 * Who hears a team alert (permission engine, includes/permissions.php):
 *   assimilation: the module's managers (assim_manager_ids())
 *   charis:       HOD-and-above seats of the departments that own Charis
 *   otherwise, or when nobody holds those: the pastors (perm_office_holders)
 *
 * @return list<int>
 */
function automation_team_ids(PDO $pdo, string $module): array
{
    $ids = [];
    try {
        if ($module === 'assimilation' && function_exists('assim_manager_ids')) {
            $ids = assim_manager_ids($pdo);
        } elseif ($module === 'charis') {
            $ids = perm_module_leaders($pdo, 'charis', 'hod');
        }
    } catch (Throwable $e) {
        error_log('[automation] team lookup for ' . $module . ' failed: ' . $e->getMessage());
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids !== [] ? $ids : perm_office_holders($pdo, 'pastoral', 'edit', false);
}

/** The person's region pastor, else the Resident Pastor(s). */
function automation_pastor_ids(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT r.pastor_id FROM users u JOIN regions r ON r.id = u.region_id WHERE u.id = ? AND r.pastor_id IS NOT NULL');
        $stmt->execute([$userId]);
        $pastor = $stmt->fetchColumn();
        if ($pastor !== false && (int) $pastor > 0 && (int) $pastor !== $userId) {
            return [(int) $pastor];
        }
    } catch (Throwable $e) {
        // no regions table, or no region column: fall through to the Resident Pastor
    }
    return perm_senior_pastor_ids($pdo);
}

// ---------------------------------------------------------------------------
// Deferred messages and "start fresh"
// ---------------------------------------------------------------------------

/**
 * Messages deferred on an earlier day (another module reached the person
 * first) get another chance, once the person is still due: the journey still
 * running, the same absence streak still open. Older than 6 days → expired.
 */
function automation_retry_deferred(PDO $pdo, DateTimeImmutable $now): array
{
    $out = ['retried' => 0, 'expired' => 0];
    $today = $now->format('Y-m-d') . ' 00:00:00';
    $pdo->prepare("UPDATE automation_sends SET outcome = 'expired' WHERE outcome = 'deferred' AND created_at < ?")
        ->execute([campaigns_sql_dt($now->modify('-' . AUTOMATION_DEFER_DAYS . ' days'))]);
    // Never at night (SMS Studio has no quiet hours), and not before the time of
    // day the message was first meant to go: a retry just after midnight would
    // also take the person's one message of the day from that day's own steps.
    if (!email_in_send_window($pdo, $now)) {
        return $out;
    }
    $stmt = $pdo->prepare("SELECT s.*, r.occasion_at FROM automation_sends s
                             LEFT JOIN automation_runs r ON r.id = s.run_id
                            WHERE s.outcome = 'deferred' AND s.created_at < ? ORDER BY s.id");
    $stmt->execute([$today]);
    $byRule = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slot = $row['occasion_at'] !== null ? substr((string) $row['occasion_at'], 11, 5) : '09:00';
        if ($now->format('H:i') < $slot) {
            continue;
        }
        $byRule[(int) $row['rule_id']][] = $row;
    }
    foreach ($byRule as $ruleId => $rows) {
        $rule = automation_rule_by_id($pdo, $ruleId);
        if ($rule === null || !$rule['is_enabled']) {
            continue;
        }
        $occasion = ['key' => 'retry', 'at' => $now, 'steps' => array_values(array_unique(array_column($rows, 'step_key')))];
        $due = [];
        foreach (automation_candidates($pdo, $rule, $occasion) as $cand) {
            $due[$cand['step']['key'] . '|' . $cand['user']['id']] = $cand;
        }
        $ctx = automation_context($rule, null, $now, false);
        foreach ($rows as $row) {
            $cand = $due[$row['step_key'] . '|' . $row['user_id']] ?? null;
            if ($cand === null || ($rule['kind'] === 'absence' && (string) $cand['dedupe'] !== (string) $row['dedupe_key'])) {
                continue; // no longer due (came back, finished the journey…): it expires on its own
            }
            $free = $pdo->prepare('SELECT 1 FROM automation_person_day WHERE user_id = ? AND day = ?');
            $free->execute([(int) $row['user_id'], $now->format('Y-m-d')]);
            if ($free->fetchColumn()) {
                continue;
            }
            $claim = $pdo->prepare("UPDATE automation_sends SET outcome = 'retrying' WHERE id = ? AND outcome = 'deferred'");
            $claim->execute([(int) $row['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $cand['superseded'] = [];
            try {
                $outcome = automation_deliver($pdo, $rule, $cand, $ctx, (int) $row['id']);
            } catch (Throwable $e) {
                error_log('[automation] retrying deferred send ' . $row['id'] . ' failed: ' . $e->getMessage());
                $outcome = 'deferred';
            }
            if ($outcome === 'deferred') {
                // lost the race for today after all (or failed): back to waiting
                $pdo->prepare("UPDATE automation_sends SET outcome = 'deferred' WHERE id = ?")->execute([(int) $row['id']]);
            }
            $out['retried']++;
        }
        automation_flush_sms($pdo, $rule, $ctx);
    }
    return $out;
}

/**
 * "Start fresh": record everyone who is already past a step as baselined, so
 * switching a rule on does not fire every step at once. Absence rules only (a
 * journey simply carries on from the next occasion).
 */
function automation_baseline(PDO $pdo, array $rule, DateTimeImmutable $now): int
{
    if ($rule['kind'] !== 'absence') {
        return 0;
    }
    $occasion = ['key' => 'baseline', 'at' => $now, 'steps' => array_column($rule['config']['steps'] ?? [], 'key')];
    $made = 0;
    foreach (absence_candidates($pdo, $rule, $occasion) as $cand) {
        foreach (array_merge([$cand['step']['key']], $cand['superseded'] ?? []) as $key) {
            if (automation_ledger_claim($pdo, (int) $rule['id'], $key, (int) $cand['user']['id'], (string) $cand['dedupe'], null,
                'baselined', 'Already past this step when the rule was switched on') !== null) {
                $made++;
            }
        }
    }
    return $made;
}

/**
 * What would happen if this rule ran at $at (the tab's preview and
 * `cron/automation_tick.php --dry-run`). Writes nothing.
 */
function automation_preview(PDO $pdo, array $rule, ?DateTimeImmutable $at = null): array
{
    $at ??= campaigns_now();
    $occasion = ['key' => 'preview', 'at' => $at, 'steps' => array_column($rule['config']['steps'] ?? [], 'key')];
    $ctx = automation_context($rule, null, $at, true);
    foreach (automation_candidates($pdo, $rule, $occasion) as $cand) {
        automation_deliver($pdo, $rule, $cand, $ctx);
    }
    return ['counts' => $ctx['counts'], 'decisions' => $ctx['decisions']];
}
