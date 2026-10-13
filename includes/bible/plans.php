<?php
// /includes/bible/plans.php
//
// Reading plans, the reading log and streaks.
//
// Plans are defined here, in code, and cut from the canon by verse count so
// every day is roughly the same length to read (Psalm 119 is not Psalm 117).
// Only a member's progress is stored. A day ticks itself off as soon as every
// chapter in it appears in the member's reading log since they started the
// plan, so reading normally is enough — nobody has to remember to tick boxes.
//
// A streak is the run of consecutive days, ending today or yesterday, on
// which the member finished at least one chapter.

const BIBLE_PLANS = [
    'bible-year' => [
        'title'   => 'The Bible in a Year',
        'summary' => 'Genesis to Revelation in 365 days — about 12 minutes a day.',
        'days'    => 365,
        'tracks'  => [[1, 66]],
    ],
    'bible-year-mixed' => [
        'title'   => 'Old & New Together',
        'summary' => 'A year through the whole Bible: an Old Testament reading and a New Testament or Psalms reading every day.',
        'days'    => 365,
        'tracks'  => [[1, 18, 20, 39], [40, 66, 19, 19]],
    ],
    'nt-90' => [
        'title'   => 'New Testament in 90 Days',
        'summary' => 'Matthew to Revelation in three months — about three chapters a day.',
        'days'    => 90,
        'tracks'  => [[40, 66]],
    ],
    'gospels-30' => [
        'title'   => 'The Gospels in 30 Days',
        'summary' => 'Walk with Jesus through Matthew, Mark, Luke and John in a month.',
        'days'    => 30,
        'tracks'  => [[40, 43]],
    ],
    'psalms-proverbs-31' => [
        'title'   => 'Psalms & Proverbs in a Month',
        'summary' => 'A chapter of Proverbs for each day of the month, with five or so Psalms.',
        'days'    => 31,
        'tracks'  => [[20, 20], [19, 19]],
    ],
];

/**
 * Split weights into $parts contiguous runs of near-equal total weight. Items
 * are never split, and every run gets at least one item while there are
 * enough to go round.
 *
 * @param list<int> $weights
 * @return list<array{0:int, 1:int}> [start, end) index pairs, one per part
 */
function bible_partition(array $weights, int $parts): array
{
    $n = count($weights);
    $out = [];
    if ($parts < 1) {
        return $out;
    }
    $total = array_sum($weights);
    $i = 0;
    $acc = 0;

    for ($k = 1; $k <= $parts; $k++) {
        if ($i >= $n) {
            $out[] = [$n, $n];
            continue;
        }
        if ($k === $parts) {
            $out[] = [$i, $n];
            $i = $n;
            continue;
        }
        $target = $total * $k / $parts;
        $start = $i;
        // Always take one item, then keep taking while the next item's
        // midpoint still falls inside this part's share and enough items are
        // left for the parts after it.
        $acc += $weights[$i];
        $i++;
        $left = $parts - $k;
        while ($i < $n && ($n - $i) > $left && $acc + $weights[$i] / 2 <= $target) {
            $acc += $weights[$i];
            $i++;
        }
        $out[] = [$start, $i];
    }

    return $out;
}

/**
 * Chapters for one track spec: [from, to] or [from, to, from2, to2] book
 * ranges, in that order.
 *
 * @return list<array{0:int, 1:int}>
 */
function bible_track_chapters(array $spec): array
{
    $out = [];
    for ($i = 0; $i + 1 < count($spec); $i += 2) {
        $out = array_merge($out, bible_chapter_sequence((int) $spec[$i], (int) $spec[$i + 1]));
    }

    return $out;
}

/**
 * A plan's schedule: day number (1-based) → list of [book, chapter].
 *
 * @return array<int, list<array{0:int, 1:int}>>
 */
function bible_plan_schedule(string $key): array
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $plan = BIBLE_PLANS[$key] ?? null;
    if ($plan === null) {
        return [];
    }

    $counts = bible_kjv_counts();
    $days = array_fill(1, $plan['days'], []);

    if ($key === 'psalms-proverbs-31') {
        // Proverbs has 31 chapters: one for each day of the month.
        for ($d = 1; $d <= 31; $d++) {
            $days[$d][] = [20, $d];
        }
        $psalms = bible_track_chapters([19, 19]);
        $weights = array_map(static fn(array $c): int => $counts[$c[0]][$c[1]], $psalms);
        foreach (bible_partition($weights, 31) as $d => [$s, $e]) {
            for ($i = $s; $i < $e; $i++) {
                $days[$d + 1][] = $psalms[$i];
            }
        }
        return $cache[$key] = $days;
    }

    foreach ($plan['tracks'] as $spec) {
        $chapters = bible_track_chapters($spec);
        $weights = array_map(static fn(array $c): int => $counts[$c[0]][$c[1]], $chapters);
        foreach (bible_partition($weights, $plan['days']) as $d => [$s, $e]) {
            for ($i = $s; $i < $e; $i++) {
                $days[$d + 1][] = $chapters[$i];
            }
        }
    }

    return $cache[$key] = $days;
}

/**
 * "Genesis 1–3 · Matthew 1" for a day's chapters.
 *
 * @param list<array{0:int, 1:int}> $chapters
 */
function bible_day_label(array $chapters): string
{
    $groups = [];
    foreach ($chapters as [$b, $c]) {
        $last = count($groups) - 1;
        if ($last >= 0 && $groups[$last][0] === $b && $groups[$last][2] === $c - 1) {
            $groups[$last][2] = $c;
        } else {
            $groups[] = [$b, $c, $c];
        }
    }

    return implode(' · ', array_map(static fn(array $g): string => bible_format_ref($g[0], $g[1], null, $g[2]), $groups));
}

/**
 * The plan catalogue for the Plans tab.
 *
 * @return list<array{key:string, title:string, summary:string, days:int, chapters:int}>
 */
function bible_plan_catalog(): array
{
    $out = [];
    foreach (BIBLE_PLANS as $key => $p) {
        $chapters = 0;
        foreach (bible_plan_schedule($key) as $day) {
            $chapters += count($day);
        }
        $out[] = ['key' => $key, 'title' => $p['title'], 'summary' => $p['summary'], 'days' => $p['days'], 'chapters' => $chapters];
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------

function bible_days_between(string $from, string $to): int
{
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);

    return (int) $a->diff($b)->format('%r%a');
}

function bible_date_add(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

// ---------------------------------------------------------------------------
// Streaks
// ---------------------------------------------------------------------------

/**
 * Streak figures from the distinct dates a member read on.
 *
 * @param list<string> $dates Y-m-d, any order
 * @return array{current:int, best:int, today:bool, week:list<array{date:string, read:bool}>, days_read:int}
 */
function bible_streak_from_dates(array $dates, string $today): array
{
    $set = array_fill_keys($dates, true);
    $readToday = isset($set[$today]);

    // Current run ends today, or yesterday if nothing has been read today yet
    // (the streak is still alive until the day is over).
    $current = 0;
    $cursor = $readToday ? $today : bible_date_add($today, -1);
    while (isset($set[$cursor])) {
        $current++;
        $cursor = bible_date_add($cursor, -1);
    }

    $best = 0;
    $sorted = array_keys($set);
    sort($sorted);
    $run = 0;
    $prev = null;
    foreach ($sorted as $d) {
        $run = ($prev !== null && bible_date_add($prev, 1) === $d) ? $run + 1 : 1;
        $best = max($best, $run);
        $prev = $d;
    }

    $week = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = bible_date_add($today, -$i);
        $week[] = ['date' => $d, 'read' => isset($set[$d])];
    }

    return ['current' => $current, 'best' => max($best, $current), 'today' => $readToday, 'week' => $week, 'days_read' => count($set)];
}

function bible_streak(PDO $pdo, int $userId): array
{
    $today = bible_today();
    $stmt = $pdo->prepare('SELECT DISTINCT read_on FROM bible_reading_log WHERE user_id = ? AND read_on >= ?');
    $stmt->execute([$userId, bible_date_add($today, -800)]);
    $dates = array_map(static fn($d): string => substr((string) $d, 0, 10), $stmt->fetchAll(PDO::FETCH_COLUMN));

    $chapters = $pdo->prepare('SELECT COUNT(*) FROM bible_reading_log WHERE user_id = ?');
    $chapters->execute([$userId]);

    return bible_streak_from_dates($dates, $today) + ['chapters' => (int) $chapters->fetchColumn()];
}

// ---------------------------------------------------------------------------
// Reading log
// ---------------------------------------------------------------------------

/**
 * Record that a member finished a chapter today. Safe to call twice. Ticks
 * off any plan day this completes.
 *
 * @return array{new:bool, completed_days:list<array{enrollment_id:int, plan_key:string, day:int}>}
 */
function bible_mark_read(PDO $pdo, int $userId, string $translation, int $book, int $chapter, string $source = 'read', ?string $date = null): array
{
    $date = $date ?? bible_today();
    $source = in_array($source, ['read', 'voice', 'plan', 'manual'], true) ? $source : 'read';
    $new = false;
    try {
        $pdo->prepare('INSERT INTO bible_reading_log (user_id, read_on, book, chapter, translation, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $date, $book, $chapter, $translation, $source, bible_now()]);
        $new = true;
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
    }

    return ['new' => $new, 'completed_days' => bible_plans_autotick($pdo, $userId, $book, $chapter)];
}

/**
 * Has the member read each of these chapters on or after $since?
 *
 * @param list<array{0:int, 1:int}> $chapters
 */
function bible_chapters_read_since(PDO $pdo, int $userId, array $chapters, string $since): bool
{
    if ($chapters === []) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM bible_reading_log WHERE user_id = ? AND book = ? AND chapter = ? AND read_on >= ? LIMIT 1');
    foreach ($chapters as [$b, $c]) {
        $stmt->execute([$userId, $b, $c, $since]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }
    }

    return true;
}

/**
 * After a chapter is read: complete every unfinished day of the member's
 * active plans that contains it and is now fully read.
 *
 * @return list<array{enrollment_id:int, plan_key:string, day:int}>
 */
function bible_plans_autotick(PDO $pdo, int $userId, int $book, int $chapter): array
{
    $done = [];
    foreach (bible_enrollments($pdo, $userId, 'active') as $en) {
        $schedule = bible_plan_schedule($en['plan_key']);
        $completed = bible_plan_completed_days($pdo, (int) $en['id']);
        foreach ($schedule as $day => $chapters) {
            if (isset($completed[$day]) || !in_array([$book, $chapter], $chapters, true)) {
                continue;
            }
            if (bible_chapters_read_since($pdo, $userId, $chapters, $en['start_date'])) {
                bible_plan_set_day($pdo, $userId, (int) $en['id'], $day, true);
                $done[] = ['enrollment_id' => (int) $en['id'], 'plan_key' => $en['plan_key'], 'day' => $day];
            }
        }
    }

    return $done;
}

// ---------------------------------------------------------------------------
// Enrolments
// ---------------------------------------------------------------------------

/**
 * @return list<array{id:int, plan_key:string, start_date:string, status:string, completed_at:?string}>
 */
function bible_enrollments(PDO $pdo, int $userId, ?string $status = null): array
{
    $sql = 'SELECT id, plan_key, start_date, status, completed_at FROM bible_plan_enrollments WHERE user_id = ?';
    $args = [$userId];
    if ($status !== null) {
        $sql .= ' AND status = ?';
        $args[] = $status;
    }
    $sql .= ' ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset(BIBLE_PLANS[$r['plan_key']])) {
            continue;
        }
        $out[] = [
            'id'           => (int) $r['id'],
            'plan_key'     => (string) $r['plan_key'],
            'start_date'   => substr((string) $r['start_date'], 0, 10),
            'status'       => (string) $r['status'],
            'completed_at' => $r['completed_at'] !== null ? (string) $r['completed_at'] : null,
        ];
    }

    return $out;
}

function bible_enrollment(PDO $pdo, int $userId, int $id): ?array
{
    foreach (bible_enrollments($pdo, $userId) as $en) {
        if ($en['id'] === $id) {
            return $en;
        }
    }

    return null;
}

/**
 * @return array<int, true> day numbers completed
 */
function bible_plan_completed_days(PDO $pdo, int $enrollmentId): array
{
    $stmt = $pdo->prepare('SELECT day_no FROM bible_plan_progress WHERE enrollment_id = ?');
    $stmt->execute([$enrollmentId]);

    return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

/**
 * @return array{ok:bool, message?:string, id?:int}
 */
function bible_plan_start(PDO $pdo, int $userId, string $key, ?string $startDate = null): array
{
    if (!isset(BIBLE_PLANS[$key])) {
        return ['ok' => false, 'message' => 'That reading plan does not exist.'];
    }
    foreach (bible_enrollments($pdo, $userId, 'active') as $en) {
        if ($en['plan_key'] === $key) {
            return ['ok' => true, 'id' => $en['id']];
        }
    }
    $today = bible_today();
    $start = $startDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : $today;
    // No starting in the past beyond a month, nor in the future.
    if ($start > $today || bible_days_between($start, $today) > 31) {
        $start = $today;
    }

    $pdo->prepare('INSERT INTO bible_plan_enrollments (user_id, plan_key, start_date, status, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $key, $start, 'active', bible_now()]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function bible_plan_stop(PDO $pdo, int $userId, int $enrollmentId): bool
{
    $stmt = $pdo->prepare("UPDATE bible_plan_enrollments SET status = 'stopped' WHERE id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$enrollmentId, $userId]);

    return $stmt->rowCount() > 0;
}

/**
 * Tick or untick one day. Finishing the last open day completes the plan.
 */
function bible_plan_set_day(PDO $pdo, int $userId, int $enrollmentId, int $day, bool $done): bool
{
    $en = bible_enrollment($pdo, $userId, $enrollmentId);
    if ($en === null) {
        return false;
    }
    $schedule = bible_plan_schedule($en['plan_key']);
    if (!isset($schedule[$day])) {
        return false;
    }

    if ($done) {
        try {
            $pdo->prepare('INSERT INTO bible_plan_progress (enrollment_id, day_no, completed_at) VALUES (?, ?, ?)')
                ->execute([$enrollmentId, $day, bible_now()]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    } else {
        $pdo->prepare('DELETE FROM bible_plan_progress WHERE enrollment_id = ? AND day_no = ?')->execute([$enrollmentId, $day]);
    }

    $completed = bible_plan_completed_days($pdo, $enrollmentId);
    if (count($completed) >= count($schedule) && $en['status'] === 'active') {
        $pdo->prepare("UPDATE bible_plan_enrollments SET status = 'done', completed_at = ? WHERE id = ? AND user_id = ?")
            ->execute([bible_now(), $enrollmentId, $userId]);
    } elseif (count($completed) < count($schedule) && $en['status'] === 'done') {
        $pdo->prepare("UPDATE bible_plan_enrollments SET status = 'active', completed_at = NULL WHERE id = ? AND user_id = ?")
            ->execute([$enrollmentId, $userId]);
    }

    return true;
}

/**
 * Everything the Plans tab shows for one enrolment.
 */
function bible_plan_overview(PDO $pdo, int $userId, array $en, bool $withDays = false): array
{
    $plan = BIBLE_PLANS[$en['plan_key']];
    $schedule = bible_plan_schedule($en['plan_key']);
    $completed = bible_plan_completed_days($pdo, $en['id']);
    $today = bible_today();
    $dayToday = max(1, min($plan['days'], bible_days_between($en['start_date'], $today) + 1));

    // The day to read next: the first unfinished day, so missing a day never
    // skips a passage.
    $next = null;
    foreach ($schedule as $day => $_) {
        if (!isset($completed[$day])) {
            $next = $day;
            break;
        }
    }
    $behind = $next !== null ? max(0, $dayToday - $next) : 0;

    $out = [
        'id'         => $en['id'],
        'plan_key'   => $en['plan_key'],
        'title'      => $plan['title'],
        'summary'    => $plan['summary'],
        'days'       => $plan['days'],
        'start_date' => $en['start_date'],
        'status'     => $en['status'],
        'done_count' => count($completed),
        'percent'    => (int) floor(count($completed) * 100 / max(1, $plan['days'])),
        'day_today'  => $dayToday,
        'behind'     => $behind,
        'next'       => null,
    ];
    if ($next !== null) {
        $out['next'] = [
            'day'      => $next,
            'label'    => bible_day_label($schedule[$next]),
            'chapters' => array_map(static fn(array $c): array => [
                'book'    => $c[0],
                'chapter' => $c[1],
                'ref'     => bible_format_ref($c[0], $c[1]),
                'read'    => bible_chapters_read_since($pdo, $userId, [$c], $en['start_date']),
            ], $schedule[$next]),
        ];
    }
    if ($withDays) {
        $out['schedule'] = [];
        foreach ($schedule as $day => $chapters) {
            $out['schedule'][] = [
                'day'   => $day,
                'label' => bible_day_label($chapters),
                'first' => $chapters[0] ?? [1, 1],
                'done'  => isset($completed[$day]),
                'date'  => bible_date_add($en['start_date'], $day - 1),
            ];
        }
    }

    return $out;
}
