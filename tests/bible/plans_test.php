<?php
// /tests/bible/plans_test.php — reading-plan schedules and streak arithmetic.

section('partition');
$parts = bible_partition([5, 5, 5, 5], 2);
eq('even split', [[0, 2], [2, 4]], $parts);
$parts = bible_partition([1, 1, 100, 1, 1], 3);
eq('heavy item stands alone', [[0, 2], [2, 3], [3, 5]], $parts);
$parts = bible_partition([3, 3], 4);
eq('more parts than items: the extras are empty', 4, count($parts));
$covered = 0;
foreach (bible_partition(array_fill(0, 50, 7), 7) as [$s, $e]) {
    $covered += $e - $s;
    ok('every part non-empty when items suffice', $e > $s);
}
eq('every item used once', 50, $covered);

section('schedules');
foreach (BIBLE_PLANS as $key => $plan) {
    $sched = bible_plan_schedule($key);
    eq("{$key}: day count", $plan['days'], count($sched));
    $flat = [];
    foreach ($sched as $day => $chapters) {
        ok("{$key}: day {$day} has a reading", $chapters !== []);
        foreach ($chapters as $c) {
            $flat[] = $c[0] . ':' . $c[1];
        }
    }
    eq("{$key}: no chapter twice", count($flat), count(array_unique($flat)));
}
eq('Bible in a Year reads all 1,189 chapters', 1189, array_sum(array_map('count', bible_plan_schedule('bible-year'))));
eq('Old & New reads all 1,189 chapters', 1189, array_sum(array_map('count', bible_plan_schedule('bible-year-mixed'))));
eq('Old & New: OT and NT/Psalms every day', true, (static function (): bool {
    foreach (bible_plan_schedule('bible-year-mixed') as $day) {
        $ot = array_filter($day, static fn($c) => $c[0] <= 39 && $c[0] !== 19);
        $nt = array_filter($day, static fn($c) => $c[0] >= 40 || $c[0] === 19);
        if (!$ot || !$nt) {
            return false;
        }
    }
    return true;
})());
eq('Proverbs N on day N', [20, 17], bible_plan_schedule('psalms-proverbs-31')[17][0]);
eq('NT in 90 starts with Matthew 1', [40, 1], bible_plan_schedule('nt-90')[1][0]);
eq('day label groups chapters', 'Genesis 1–3 · Matthew 1', bible_day_label([[1, 1], [1, 2], [1, 3], [40, 1]]));
$catalog = bible_plan_catalog();
eq('catalogue lists every plan', count(BIBLE_PLANS), count($catalog));

// Days are balanced by verses, not chapters: the heaviest day of the year
// plan stays within a sensible bound of the average.
$counts = bible_kjv_counts();
$loads = array_map(static fn($day) => array_sum(array_map(static fn($c) => $counts[$c[0]][$c[1]], $day)), bible_plan_schedule('bible-year'));
$avg = array_sum($loads) / count($loads);
ok('heaviest day < 2.5× the average', max($loads) < $avg * 2.5, 'max ' . max($loads) . ', avg ' . round($avg));

section('streaks');
$s = bible_streak_from_dates(['2026-10-10', '2026-10-11', '2026-10-12'], '2026-10-12');
eq('three days ending today', [3, true], [$s['current'], $s['today']]);
$s = bible_streak_from_dates(['2026-10-10', '2026-10-11'], '2026-10-12');
eq('still alive until today ends', [2, false], [$s['current'], $s['today']]);
$s = bible_streak_from_dates(['2026-10-09', '2026-10-10'], '2026-10-12');
eq('a missed day breaks it', 0, $s['current']);
$s = bible_streak_from_dates(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-10-12'], '2026-10-12');
eq('best run remembered', [1, 4], [$s['current'], $s['best']]);
eq('week strip has 7 days ending today', ['2026-10-06', '2026-10-12'], [$s['week'][0]['date'], $s['week'][6]['date']]);
$s = bible_streak_from_dates(['2026-02-28', '2026-03-01'], '2026-03-01');
eq('across a month end', 2, $s['current']);
eq('no reading', 0, bible_streak_from_dates([], '2026-10-12')['current']);
