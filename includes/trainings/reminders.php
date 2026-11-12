<?php
// /includes/trainings/reminders.php
//
// Reminders (owner's answer 15): email + phone push, never SMS.
//   class_day    the day before a live class (items type live with a time)
//   class_hour   an hour before it
//   exam_day     the day before an exam opens (PR 2's training_exams.window_open)
//   exam_hour    an hour before it opens
//   due          the day before a training is due (enrolment due_on)
//   item_due     the morning a required item is scheduled (a reading, video,
//                audio or assignment with a date) and is not done yet
// Only active enrolments in published trainings; an exam already sat and a
// done item are skipped. Nothing goes between 21:00 and 06:30 Lagos time,
// except the hour-before ones. Each reminder is claimed in training_reminders
// (UNIQUE kind + enrolment + item + the time it is about) BEFORE it is sent,
// so two runs at once can never send it twice.
//
// Runs: cron/automation_tick.php every 5 minutes (the hour-before ones need
// that beat) and cron/trainings_daily.php at 06:30. Push goes through
// trainings_notify() (system_notifications, which push_fanout() sends to
// phones); email through email_send_doc() to a confirmed personal email.

const TRAININGS_QUIET_FROM = '21:00';
const TRAININGS_QUIET_UNTIL = '06:30';
const TRAININGS_REMINDER_KINDS = ['class_day', 'class_hour', 'exam_day', 'exam_hour', 'due', 'item_due'];

function trainings_reminders_ready(PDO $pdo): bool
{
    return trainings_ready($pdo) && trainings_table_exists($pdo, 'training_reminders');
}

/** Is it quiet time (21:00 to 06:30 Lagos)? */
function trainings_quiet_hours(DateTimeImmutable $now): bool
{
    $hm = $now->format('H:i');
    return $hm >= TRAININGS_QUIET_FROM || $hm < TRAININGS_QUIET_UNTIL;
}

/**
 * Send what is due now. $opts: now (DateTimeImmutable), dry_run (bool).
 * @return array{sent:int, skipped:int, by_kind:array<string,int>}
 */
function trainings_reminders_run(PDO $pdo, array $opts = []): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'by_kind' => array_fill_keys(TRAININGS_REMINDER_KINDS, 0)];
    if (!trainings_reminders_ready($pdo)) {
        return $out;
    }
    $now = ($opts['now'] ?? null) instanceof DateTimeImmutable ? $opts['now'] : trainings_now();
    $quiet = trainings_quiet_hours($now);
    $today = $now->format('Y-m-d');
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $hourAhead = trainings_sql_dt($now->modify('+60 minutes'));
    $nowSql = trainings_sql_dt($now);

    $jobs = [];
    // Live classes: the day before (not in quiet hours) and the hour before (always).
    $stmt = $pdo->prepare("SELECT i.id AS item_id, i.title AS item_title, i.starts_at, i.details, t.id AS training_id, t.title AS training_title
                             FROM training_items i JOIN trainings t ON t.id = i.training_id
                            WHERE i.type = 'live' AND t.status = 'published' AND i.starts_at IS NOT NULL
                              AND i.starts_at > ? AND i.starts_at <= ?");
    $stmt->execute([$nowSql, $tomorrow . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $at = (string) $r['starts_at'];
        if ($at <= $hourAhead) {
            $jobs[] = ['kind' => 'class_hour', 'at' => $at] + $r;
        } elseif (!$quiet && substr($at, 0, 10) === $tomorrow) {
            $jobs[] = ['kind' => 'class_day', 'at' => $at] + $r;
        }
    }
    // Exams opening (PR 2's table, when it is there).
    if (trainings_table_exists($pdo, 'training_exams')) {
        $stmt = $pdo->prepare("SELECT i.id AS item_id, i.title AS item_title, x.window_open, t.id AS training_id, t.title AS training_title
                                 FROM training_exams x JOIN training_items i ON i.id = x.item_id JOIN trainings t ON t.id = i.training_id
                                WHERE t.status = 'published' AND x.window_open IS NOT NULL AND x.window_open > ? AND x.window_open <= ?");
        $stmt->execute([$nowSql, $tomorrow . ' 23:59:59']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $at = (string) $r['window_open'];
            if ($at <= $hourAhead) {
                $jobs[] = ['kind' => 'exam_hour', 'at' => $at] + $r;
            } elseif (!$quiet && substr($at, 0, 10) === $tomorrow) {
                $jobs[] = ['kind' => 'exam_day', 'at' => $at] + $r;
            }
        }
    }

    foreach ($jobs as $job) {
        foreach (trainings_reminder_learners($pdo, (int) $job['training_id'], (int) $job['item_id']) as $p) {
            trainings_reminder_send($pdo, $job['kind'], $p, $job, $job['at'], $now, !empty($opts['dry_run']), $out);
        }
    }

    if (!$quiet) {
        // A training due tomorrow, not finished.
        $stmt = $pdo->prepare("SELECT e.id AS enrolment_id, e.user_id, e.due_on, t.id AS training_id, t.title AS training_title
                                 FROM training_enrolments e JOIN trainings t ON t.id = e.training_id
                                WHERE e.status = 'active' AND t.status = 'published' AND e.due_on = ?");
        $stmt->execute([$tomorrow]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            trainings_reminder_send($pdo, 'due', $r, ['item_id' => 0, 'item_title' => '', 'training_id' => $r['training_id'],
                'training_title' => $r['training_title']], $r['due_on'] . ' 00:00:00', $now, !empty($opts['dry_run']), $out);
        }
        // A required item scheduled today (not a class or an exam), not done.
        $stmt = $pdo->prepare("SELECT i.id AS item_id, i.title AS item_title, i.starts_at, t.id AS training_id, t.title AS training_title
                                 FROM training_items i JOIN trainings t ON t.id = i.training_id
                                WHERE i.type IN ('video', 'audio', 'reading', 'assignment') AND i.required = 1 AND t.status = 'published'
                                  AND i.starts_at >= ? AND i.starts_at <= ?");
        $stmt->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (trainings_reminder_learners($pdo, (int) $r['training_id'], (int) $r['item_id']) as $p) {
                trainings_reminder_send($pdo, 'item_due', $p, $r, (string) $r['starts_at'], $now, !empty($opts['dry_run']), $out);
            }
        }
    }
    return $out;
}

/** Active learners on a training who have not finished this item. @return list<array{enrolment_id:int, user_id:int}> */
function trainings_reminder_learners(PDO $pdo, int $trainingId, int $itemId): array
{
    $stmt = $pdo->prepare("SELECT e.id AS enrolment_id, e.user_id FROM training_enrolments e
                            WHERE e.training_id = ? AND e.status = 'active'
                              AND NOT EXISTS (SELECT 1 FROM training_progress p WHERE p.enrolment_id = e.id AND p.item_id = ?
                                                 AND p.status IN ('completed', 'submitted'))");
    $stmt->execute([$trainingId, $itemId]);
    return array_map(static fn ($r) => ['enrolment_id' => (int) $r['enrolment_id'], 'user_id' => (int) $r['user_id']],
        $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Claim, then send one reminder by push and (with a confirmed personal email) by email. */
function trainings_reminder_send(PDO $pdo, string $kind, array $person, array $job, string $targetAt, DateTimeImmutable $now, bool $dry, array &$out): void
{
    $enrolmentId = (int) $person['enrolment_id'];
    $itemId = (int) ($job['item_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT 1 FROM training_reminders WHERE kind = ? AND enrolment_id = ? AND item_id = ? AND target_at = ?');
    $stmt->execute([$kind, $enrolmentId, $itemId, $targetAt]);
    if ($stmt->fetchColumn()) {
        $out['skipped']++;
        return;
    }
    if ($dry) {
        $out['sent']++;
        $out['by_kind'][$kind]++;
        return;
    }
    try {
        $pdo->prepare('INSERT INTO training_reminders (kind, enrolment_id, item_id, target_at, channels, sent_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$kind, $enrolmentId, $itemId, $targetAt, '', trainings_sql_dt($now)]);
    } catch (PDOException $e) {
        $out['skipped']++;   // another run claimed it a moment ago
        return;
    }
    $claim = (int) $pdo->lastInsertId();
    [$title, $line] = trainings_reminder_words($kind, $job, $targetAt);
    $link = '/modules/trainings/?t=' . (int) $job['training_id'];
    $channels = [];
    if (trainings_notify($pdo, [(int) $person['user_id']], $title, $line, $link) > 0) {
        $channels[] = 'push';
    }
    if (trainings_reminder_email($pdo, (int) $person['user_id'], $kind, $title, $line, $link)) {
        $channels[] = 'email';
    }
    $pdo->prepare('UPDATE training_reminders SET channels = ? WHERE id = ?')->execute([implode(',', $channels), $claim]);
    $out['sent']++;
    $out['by_kind'][$kind]++;
}

/** [title, one line] for a reminder. */
function trainings_reminder_words(string $kind, array $job, string $targetAt): array
{
    $time = substr($targetAt, 11, 5);
    $what = trim((string) ($job['item_title'] ?? '')) !== '' ? $job['item_title'] . ' · ' . $job['training_title'] : (string) $job['training_title'];
    return match ($kind) {
        'class_day' => ['Class tomorrow', $what . ' at ' . $time],
        'class_hour' => ['Class in an hour', $what . ' at ' . $time],
        'exam_day' => ['Exam opens tomorrow', $what . ' at ' . $time],
        'exam_hour' => ['Exam opens in an hour', $what . ' at ' . $time],
        'due' => ['Training due tomorrow', (string) $job['training_title']],
        default => ['Due today', $what],
    };
}

/** Email a reminder to the person's confirmed personal email (none: push only). */
function trainings_reminder_email(PDO $pdo, int $userId, string $kind, string $title, string $line, string $link): bool
{
    if (!function_exists('email_send_doc') || !function_exists('campaigns_ready') || !campaigns_ready($pdo)
        || !function_exists('member_confirmed_email')) {
        return false;
    }
    try {
        $to = member_confirmed_email($pdo, $userId);
        if ($to === null || (function_exists('email_is_suppressed') && email_is_suppressed($pdo, $to))) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            return false;
        }
        $url = function_exists('site_url') ? site_url($link) : $link;
        $doc = ['v' => 1, 'subject' => $title . ': ' . $line, 'preheader' => $line, 'blocks' => [
            ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => $title],
            email_doc_paragraphs('Hi {{first_name}},', $line . '.'),
            ['type' => 'button', 'label' => 'Open my training', 'href' => $url, 'style' => 'primary', 'align' => 'left'],
            ['type' => 'footer'],
        ]];
        $hour = str_ends_with($kind, '_hour');
        $r = email_send_doc($pdo, $doc, $person + ['email' => $to], [
            'lane' => $hour ? 'alert' : 'automation', 'purpose' => 'account', 'flow' => 'training_reminder', 'to_email' => $to, 'now' => $hour,
        ]);
        return !empty($r['ok']);
    } catch (Throwable $e) {
        error_log('[trainings] reminder email to user ' . $userId . ' failed: ' . $e->getMessage());
        return false;
    }
}
