<?php
// /tests/special_events/teams_test.php — the team-assignment invariants
// (guide §10.6.2, §22.1).
//
// se_team_choose() is the only piece of the night that has to be provably
// fair. A room notices immediately if one team has three more people than
// another, or if all the men ended up on Red. The guide states three
// invariants and this file is what makes them true rather than hoped for:
//
//   I1  after every assignment, max(n) − min(n) ≤ 1
//   I2  gender spread ≤ 2 always, and ≤ 1 at least 95 % of the time
//   I3  the same inputs always give the same answer
//
// I4 (assignment only ever happens inside se_lock_event) is a database
// property and lives in the integration tests.

/** Empty counts for k teams, keyed by team id. */
function se_test_counts(int $k): array
{
    $counts = [];
    for ($i = 1; $i <= $k; $i++) {
        $counts[$i] = ['n' => 0, 'n_Male' => 0, 'n_Female' => 0, 'n_member' => 0, 'n_guest' => 0];
    }

    return $counts;
}

function se_test_teams(int $k): array
{
    $teams = [];
    for ($i = 1; $i <= $k; $i++) {
        $teams[] = ['id' => $i, 'sort_order' => $i - 1];
    }

    return $teams;
}

/** Apply one arrival to the counts, the way se_assign_team() does. */
function se_test_apply(array &$counts, int $teamId, string $gender, bool $isMember): void
{
    $counts[$teamId]['n']++;
    $counts[$teamId][$gender === 'Female' ? 'n_Female' : 'n_Male']++;
    $counts[$teamId][$isMember ? 'n_member' : 'n_guest']++;
}

/**
 * Run one whole arrival sequence through the chooser.
 *
 * @return array{counts: array, worst_size: int, worst_gender: int}
 */
function se_test_sequence(int $k, array $people): array
{
    $teams   = se_test_teams($k);
    $counts  = se_test_counts($k);
    $pointer = 0;

    $worstSize = 0;
    $worstGender = 0;

    foreach ($people as [$gender, $isMember]) {
        $choice = se_team_choose($teams, $counts, $gender, $isMember, $pointer);
        se_test_apply($counts, (int) $choice['team']['id'], $gender, $isMember);
        $pointer = $choice['next_pointer'];

        // The invariants are checked after EVERY arrival, not just at the
        // end: a room that is briefly lopsided is still a lopsided room.
        // One pass, no array_column — this runs about five million times.
        $minN = $maxN = null;
        $minM = $maxM = null;
        $minF = $maxF = null;

        foreach ($counts as $row) {
            $n = $row['n'];
            $m = $row['n_Male'];
            $f = $row['n_Female'];
            if ($minN === null) {
                $minN = $maxN = $n;
                $minM = $maxM = $m;
                $minF = $maxF = $f;
                continue;
            }
            if ($n < $minN) { $minN = $n; } elseif ($n > $maxN) { $maxN = $n; }
            if ($m < $minM) { $minM = $m; } elseif ($m > $maxM) { $maxM = $m; }
            if ($f < $minF) { $minF = $f; } elseif ($f > $maxF) { $maxF = $f; }
        }

        $sizeSpread = $maxN - $minN;
        if ($sizeSpread > $worstSize) { $worstSize = $sizeSpread; }

        $genderSpread = max($maxM - $minM, $maxF - $minF);
        if ($genderSpread > $worstGender) { $worstGender = $genderSpread; }
    }

    return ['counts' => $counts, 'worst_size' => $worstSize, 'worst_gender' => $worstGender];
}

// --------------------------------------------------------------------------

echo "    argmin\n";

is_same('argmin of an empty list is empty', [], se_argmin([], static fn($x) => $x));
is_same(
    'argmin keeps every tied item, in order',
    [1, 3],
    array_map(static fn($t) => $t['id'], se_argmin(
        [['id' => 1, 'v' => 2], ['id' => 2, 'v' => 5], ['id' => 3, 'v' => 2]],
        static fn($t) => $t['v']
    ))
);

echo "    the four rules, in order\n";

$teams = se_test_teams(3);

// Rule 1 beats everything: the smallest team wins even when its gender and
// mix counts are the worst of the three.
$counts = se_test_counts(3);
$counts[1] = ['n' => 5, 'n_Male' => 0, 'n_Female' => 5, 'n_member' => 0, 'n_guest' => 5];
$counts[2] = ['n' => 5, 'n_Male' => 5, 'n_Female' => 0, 'n_member' => 5, 'n_guest' => 0];
$counts[3] = ['n' => 2, 'n_Male' => 2, 'n_Female' => 0, 'n_member' => 2, 'n_guest' => 0];
is_same('rule 1: the smallest team wins outright', 3,
    (int) se_team_choose($teams, $counts, 'Male', true, 0)['team']['id']);

// Rule 2 decides between equally sized teams.
$counts = se_test_counts(3);
foreach ([1, 2, 3] as $id) { $counts[$id]['n'] = 4; }
$counts[1]['n_Female'] = 4;
$counts[2]['n_Female'] = 1;
$counts[3]['n_Female'] = 3;
is_same('rule 2: fewest of the arriving gender', 2,
    (int) se_team_choose($teams, $counts, 'Female', true, 0)['team']['id']);

// Rule 3 only speaks when size and gender are level.
$counts = se_test_counts(3);
foreach ([1, 2, 3] as $id) { $counts[$id]['n'] = 4; $counts[$id]['n_Male'] = 2; }
$counts[1]['n_guest'] = 4;
$counts[2]['n_guest'] = 0;
$counts[3]['n_guest'] = 2;
is_same('rule 3: guests spread away from guests', 2,
    (int) se_team_choose($teams, $counts, 'Male', false, 0)['team']['id']);

// Rule 4 is the round-robin pointer, and it wraps.
$counts = se_test_counts(3);
is_same('rule 4: an all-square room follows the pointer', 2,
    (int) se_team_choose($teams, $counts, 'Male', true, 1)['team']['id']);
is_same('rule 4: the pointer wraps past the last team', 1,
    (int) se_team_choose($teams, $counts, 'Male', true, 3)['team']['id']);
is_same('rule 4: a negative pointer is still in range', 1,
    (int) se_team_choose($teams, $counts, 'Male', true, -3)['team']['id']);
is_same('the pointer advances past the team we chose', 0,
    se_team_choose($teams, $counts, 'Male', true, 2)['next_pointer']);

throws('no teams is a rule error, not a crash',
    static fn() => se_team_choose([], [], 'Male', true, 0), SeRuleException::class);

echo "    I3 — determinism\n";

$counts = se_test_counts(4);
$counts[1] = ['n' => 3, 'n_Male' => 1, 'n_Female' => 2, 'n_member' => 2, 'n_guest' => 1];
$counts[2] = ['n' => 3, 'n_Male' => 2, 'n_Female' => 1, 'n_member' => 1, 'n_guest' => 2];
$counts[3] = ['n' => 4, 'n_Male' => 2, 'n_Female' => 2, 'n_member' => 2, 'n_guest' => 2];
$counts[4] = ['n' => 3, 'n_Male' => 1, 'n_Female' => 2, 'n_member' => 3, 'n_guest' => 0];

$first = se_team_choose(se_test_teams(4), $counts, 'Female', false, 2);
$same  = true;
for ($i = 0; $i < 500; $i++) {
    $again = se_team_choose(se_test_teams(4), $counts, 'Female', false, 2);
    if ($again !== $first) { $same = false; break; }
}
ok('the same inputs give the same team every time', $same);

echo "    I1 and I2 over 100,000 random arrival sequences\n";

// A fixed seed: this has to be reproducible, because a failure here is a
// bug report that someone else must be able to re-run.
mt_srand(20261004);

$sequences   = 100000;
$breakI1     = 0;
$breakI2Hard = 0;
$withinOne   = 0;
$worstSeen   = ['size' => 0, 'gender' => 0];

for ($s = 0; $s < $sequences; $s++) {
    $k = mt_rand(2, 8);

    // Sizes from "barely anyone" to "a full hall", and a gender balance that
    // ranges from even to very one-sided — Christmas services skew female,
    // and the algorithm has to cope with that, not just with 50/50.
    $n           = mt_rand(1, 90);
    $femaleShare = mt_rand(10, 90) / 100;
    $memberShare = mt_rand(10, 90) / 100;

    $people = [];
    for ($i = 0; $i < $n; $i++) {
        $people[] = [
            (mt_rand(0, 99) / 100) < $femaleShare ? 'Female' : 'Male',
            (mt_rand(0, 99) / 100) < $memberShare,
        ];
    }

    $result = se_test_sequence($k, $people);

    if ($result['worst_size'] > 1) { $breakI1++; }
    if ($result['worst_gender'] > 2) { $breakI2Hard++; }
    if ($result['worst_gender'] <= 1) { $withinOne++; }

    $worstSeen['size']   = max($worstSeen['size'], $result['worst_size']);
    $worstSeen['gender'] = max($worstSeen['gender'], $result['worst_gender']);
}

is_same('I1: team sizes never differ by more than one', 0, $breakI1);
is_same('I2: gender spread never exceeds two', 0, $breakI2Hard);

$withinOnePct = $withinOne / $sequences;
ok('I2: gender spread is at most one in at least 95% of rooms',
    $withinOnePct >= 0.95,
    sprintf('%.2f%% of %d sequences', $withinOnePct * 100, $sequences));

ok('the worst case seen is inside the guide\'s bounds',
    $worstSeen['size'] <= 1 && $worstSeen['gender'] <= 2,
    'size ' . $worstSeen['size'] . ', gender ' . $worstSeen['gender']);

echo "    the 60-person rehearsal from the acceptance list\n";

// Appendix H.2: sixty people, roughly even, four teams.
mt_srand(60);
$people = [];
for ($i = 0; $i < 60; $i++) {
    $people[] = [$i % 2 === 0 ? 'Female' : 'Male', $i % 3 !== 0];
}

$rehearsal = se_test_sequence(4, $people);
$sizes = array_column($rehearsal['counts'], 'n');

is_same('sixty people split into four teams of fifteen', [15, 15, 15, 15], array_values($sizes));
ok('and the gender counts stay within one',
    $rehearsal['worst_gender'] <= 1, 'spread ' . $rehearsal['worst_gender']);

echo "    colour naming and hex parsing\n";

is_same('a flat red is named Red', 'Red', se_color_name('#FF0000'));
is_same('near-black is named Black', 'Black', se_color_name('#0A0A0A'));
is_same('near-white is named White', 'White', se_color_name('#FAFAFA'));

is_same('a pasted list survives commas, spaces and newlines',
    ['#D11920', '#1D356A', '#1E9E62'],
    se_parse_hex_list("#D11920, #1d356a\n  1E9E62  "));
is_same('duplicates are dropped, first one wins',
    ['#D11920', '#1D356A'],
    se_parse_hex_list("#D11920\n#1D356A\n#d11920"));
is_same('rubbish is ignored rather than guessed at',
    ['#D11920'],
    se_parse_hex_list("#D11920\nnot-a-colour\n#12345"));
