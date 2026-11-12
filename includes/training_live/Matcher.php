<?php

namespace HOD\TrainingLive;

use DateTimeImmutable;
use Throwable;

/**
 * Who attended a live class, from Meet's participant list (owner's answer 6, docs/INTEGRATIONS.md). Pure: no
 * database, no network, so every rule is tested on its own.
 *
 * A participant counts for a learner automatically only when the match is clear:
 *   1. names: exactly one enrolled learner matches the participant's display name at 95% or more (normalised:
 *      accents, titles such as Pastor or Sis., punctuation and word order do not matter);
 *   2. the Join log: the participant entered the call within 2 minutes before to 10 minutes after exactly one
 *      learner pressed Join class, and no other participant entered in that learner's window; or exactly one
 *      learner who pressed Join matches the name at 80% or more, 10 points clear of the next.
 * Everyone else goes to "Needs a look", with up to three suggestions. A learner who pressed Join but was not found in
 * the call is listed too. Minutes are the learner's time in the call (overlapping stretches counted once); present
 * means at least $presentMinutes. The tutor can always correct the result.
 */
final class Matcher
{
    public const NAME_SURE = 0.95;
    public const NAME_JOINED = 0.80;
    public const JOIN_BEFORE = 120;
    public const JOIN_AFTER = 600;

    private const TITLES = ['pastor', 'pst', 'ps', 'rev', 'reverend', 'bro', 'brother', 'sis', 'sister', 'dr', 'mr', 'mrs', 'ms',
        'miss', 'deacon', 'dcn', 'deaconess', 'dcns', 'evang', 'evangelist', 'min', 'minister', 'elder', 'prophet', 'apostle', 'engr', 'barr', 'prof'];

    /**
     * @param list<array{id: string, display_name: string, sessions: list<array{0: ?string, 1: ?string}>}> $participants
     *        from Google::participants() (all the class's calls together)
     * @param list<array{user_id: int, name: string}> $learners  everyone enrolled
     * @param list<array{user_id: int, at: int}> $joins  the Join class log (Unix times)
     * @param int $end  when the class ended (Unix): a stretch still open counts until then
     * @return array{matched: array<int, array{minutes: int, present: bool, source: string, confidence: float, participants: list<string>}>,
     *               review: list<array{participants: list<string>, display_name: string, minutes: int, suggestions: list<array{user_id: int, score: float, joined: bool}>}>,
     *               joined_unmatched: list<int>}
     */
    public static function match(array $participants, array $learners, array $joins, int $end, int $presentMinutes = 30): array
    {
        $names = [];
        foreach ($learners as $l) {
            $names[(int) $l['user_id']] = self::normalise((string) $l['name']);
        }
        $joinedAt = [];
        foreach ($joins as $j) {
            $uid = (int) $j['user_id'];
            if (isset($names[$uid])) {
                $joinedAt[$uid][] = (int) $j['at'];
            }
        }

        $people = [];
        foreach ($participants as $p) {
            $intervals = self::intervals($p['sessions'] ?? [], $end);
            $people[(string) $p['id']] = [
                'name' => (string) $p['display_name'],
                'norm' => self::normalise((string) $p['display_name']),
                'intervals' => $intervals,
                'first' => $intervals ? min(array_column($intervals, 0)) : null,
                'scores' => [],
            ];
        }
        foreach ($people as $id => $p) {
            foreach ($names as $uid => $n) {
                $people[$id]['scores'][$uid] = self::similarity($p['norm'], $n);
            }
            arsort($people[$id]['scores']);
        }

        $owner = [];    // participant id => [user id, source, confidence]
        // 1. A unique name match of at least 95%
        foreach ($people as $id => $p) {
            $sure = array_filter($p['scores'], fn ($s) => $s >= self::NAME_SURE);
            if (count($sure) === 1) {
                $owner[$id] = [(int) array_key_first($sure), 'meet', (float) reset($sure)];
            }
        }
        // 2. The Join log, for learners not found by name
        $taken = array_flip(array_map(fn ($o) => $o[0], $owner));
        $open = array_diff_key($joinedAt, $taken);
        $inWindow = static function (?int $first, array $ats): bool {
            foreach ($ats as $at) {
                if ($first !== null && $first >= $at - self::JOIN_BEFORE && $first <= $at + self::JOIN_AFTER) {
                    return true;
                }
            }

            return false;
        };
        $free = array_diff_key($people, $owner);
        foreach ($free as $id => $p) {
            $byTime = array_keys(array_filter($open, fn ($ats) => $inWindow($p['first'], $ats)));
            if (count($byTime) === 1) {
                $uid = $byTime[0];
                $rivals = array_filter($free, fn ($q) => $inWindow($q['first'], $open[$uid]));
                if (count($rivals) === 1) {
                    $owner[$id] = [$uid, 'join', (float) ($p['scores'][$uid] ?? 0)];
                    unset($open[$uid]);
                    continue;
                }
            }
            $joinedScores = array_intersect_key($p['scores'], $open);
            arsort($joinedScores);
            $top = array_values($joinedScores);
            if ($top && $top[0] >= self::NAME_JOINED && ($top[0] - ($top[1] ?? 0)) >= 0.10) {
                $owner[$id] = [(int) array_key_first($joinedScores), 'join', (float) $top[0]];
                unset($open[(int) array_key_first($joinedScores)]);
            }
        }

        // Minutes per learner, each stretch counted once
        $matched = [];
        foreach ($owner as $id => [$uid, $source, $confidence]) {
            $m = $matched[$uid] ?? ['intervals' => [], 'source' => $source, 'confidence' => $confidence, 'participants' => []];
            array_push($m['intervals'], ...$people[$id]['intervals']);
            $m['participants'][] = (string) $id;
            if ($source === 'meet') {
                $m['source'] = 'meet';
            }
            $m['confidence'] = max($m['confidence'], $confidence);
            $matched[$uid] = $m;
        }
        foreach ($matched as $uid => $m) {
            $minutes = intdiv(self::covered($m['intervals']), 60);
            $matched[$uid] = ['minutes' => $minutes, 'present' => $minutes >= $presentMinutes, 'source' => $m['source'],
                'confidence' => round($m['confidence'], 3), 'participants' => $m['participants']];
        }
        ksort($matched);

        // Needs a look: the rest, one row per name (a phone and a laptop under the same name show once)
        $review = [];
        foreach (array_diff_key($people, $owner) as $id => $p) {
            $key = $p['norm'] !== '' ? $p['norm'] : '#' . $id;
            $row = $review[$key] ?? ['participants' => [], 'display_name' => $p['name'], 'intervals' => [], 'scores' => $p['scores'], 'first' => $p['first']];
            $row['participants'][] = (string) $id;
            array_push($row['intervals'], ...$p['intervals']);
            $review[$key] = $row;
        }
        $out = [];
        foreach ($review as $row) {
            $suggestions = [];
            foreach ($row['scores'] as $uid => $score) {
                $joined = isset($joinedAt[$uid]);
                if (!isset($matched[$uid]) && ($score >= 0.5 || ($joined && $inWindow($row['first'], $joinedAt[$uid])))) {
                    $suggestions[] = ['user_id' => (int) $uid, 'score' => round($score, 3), 'joined' => $joined];
                }
            }
            usort($suggestions, fn ($a, $b) => [$b['joined'], $b['score']] <=> [$a['joined'], $a['score']]);
            $out[] = ['participants' => $row['participants'], 'display_name' => $row['display_name'],
                'minutes' => intdiv(self::covered($row['intervals']), 60), 'suggestions' => array_slice($suggestions, 0, 3)];
        }
        usort($out, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        return [
            'matched' => $matched,
            'review' => $out,
            'joined_unmatched' => array_values(array_map('intval', array_keys(array_diff_key($joinedAt, $matched)))),
        ];
    }

    /** Lower case, no accents, no titles, no punctuation, single spaces */
    public static function normalise(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
            $s = is_string($t) ? $t : $s;
        } else {
            $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u',
                'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ẹ' => 'e', 'ọ' => 'o', 'ṣ' => 's']);
        }
        $s = (string) preg_replace('/\(.*?\)|[^a-z0-9 ]+/', ' ', $s);
        $words = array_values(array_filter(explode(' ', $s), fn ($w) => $w !== '' && !in_array($w, self::TITLES, true)));

        return implode(' ', $words);
    }

    /** 0..1: the better of the names as written and with their words sorted (so "Obi Ada" = "Ada Obi") */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        $sorted = static function (string $s): string {
            $w = explode(' ', $s);
            sort($w);

            return implode(' ', $w);
        };

        return max(self::ratio($a, $b), self::ratio($sorted($a), $sorted($b)));
    }

    private static function ratio(string $a, string $b): float
    {
        $a = substr($a, 0, 120);
        $b = substr($b, 0, 120);
        $max = max(strlen($a), strlen($b));

        return $max === 0 ? 0.0 : 1 - levenshtein($a, $b) / $max;
    }

    /** @return list<array{0: int, 1: int}> Unix [start, end] pairs; an open stretch ends at $end */
    private static function intervals(array $sessions, int $end): array
    {
        $out = [];
        foreach ($sessions as $s) {
            $from = self::ts($s[0] ?? null);
            if ($from === null) {
                continue;
            }
            $to = self::ts($s[1] ?? null) ?? $end;
            if ($to > $from) {
                $out[] = [$from, $to];
            }
        }

        return $out;
    }

    /** Seconds covered by the intervals together (overlaps counted once) */
    private static function covered(array $intervals): int
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        $cur = null;
        foreach ($intervals as [$from, $to]) {
            if ($cur === null || $from > $cur[1]) {
                if ($cur !== null) {
                    $total += $cur[1] - $cur[0];
                }
                $cur = [$from, $to];
            } else {
                $cur[1] = max($cur[1], $to);
            }
        }

        return $total + ($cur !== null ? $cur[1] - $cur[0] : 0);
    }

    private static function ts(?string $rfc): ?int
    {
        if ($rfc === null || $rfc === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($rfc))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
