<?php
// /includes/auth_welcome.php
//
// The welcome beside the sign-in form (auth/login.php): a greeting for the
// time of day in Lagos, the two weekly services with the next one marked
// (or "On now" while it runs), and the scripture that rotates under the
// title (assets/ui/js/pages/login.js). Pure functions, no database: the
// sign-in page stays as light as it was.

/** The weekly services (the Events module shows the same two). day: 0 = Sunday. */
const AUTH_WELCOME_SERVICES = [
    ['day' => 0, 'start' => '09:30', 'hours' => 3, 'days' => 'Sundays', 'time' => '9:30 AM', 'name' => 'Total Experience'],
    ['day' => 4, 'start' => '18:30', 'hours' => 2, 'days' => 'Thursdays', 'time' => '6:30 PM', 'name' => 'Mercy Experience'],
];

/**
 * The scripture under the title, King James Version, word for word as the
 * Bible module has it (includes/bible/kjv/). The first is printed with the
 * page; the script rotates the rest.
 */
const AUTH_WELCOME_SCRIPTURE = [
    ['Now therefore ye are no more strangers and foreigners, but fellowcitizens with the saints, and of the household of God.', 'Ephesians 2:19'],
    ['I was glad when they said unto me, Let us go into the house of the LORD.', 'Psalm 122:1'],
    ['Behold, how good and how pleasant it is for brethren to dwell together in unity!', 'Psalm 133:1'],
    ['Wherefore receive ye one another, as Christ also received us to the glory of God.', 'Romans 15:7'],
    ['Those that be planted in the house of the LORD shall flourish in the courts of our God.', 'Psalm 92:13'],
    ['As for me and my house, we will serve the LORD.', 'Joshua 24:15'],
    ['For a day in thy courts is better than a thousand.', 'Psalm 84:10'],
    ['Serve the LORD with gladness: come before his presence with singing.', 'Psalm 100:2'],
    ['Let us do good unto all men, especially unto them who are of the household of faith.', 'Galatians 6:10'],
    ['And whatsoever ye do, do it heartily, as to the Lord, and not unto men.', 'Colossians 3:23'],
    ['And let us not be weary in well doing: for in due season we shall reap, if we faint not.', 'Galatians 6:9'],
    ['Surely goodness and mercy shall follow me all the days of my life: and I will dwell in the house of the LORD for ever.', 'Psalm 23:6'],
    ['For God hath not given us the spirit of fear; but of power, and of love, and of a sound mind.', '2 Timothy 1:7'],
    ['Let all your things be done with charity.', '1 Corinthians 16:14'],
];

function auth_welcome_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
}

/** "Good morning" until noon, "Good afternoon" until five, then "Good evening". */
function auth_welcome_greeting(DateTimeImmutable $now): string
{
    $hour = (int) $now->format('G');
    if ($hour >= 5 && $hour < 12) {
        return 'Good morning';
    }
    return $hour >= 12 && $hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * The services, the soonest marked: state "live" while it runs (when: "On
 * now"), else "next" with when = Tonight / Today / Tomorrow / Next.
 *
 * @return list<array{days:string, time:string, name:string, state:string, when:string}>
 */
function auth_welcome_services(DateTimeImmutable $now): array
{
    $soonest = null;
    $out = [];
    foreach (AUTH_WELCOME_SERVICES as $i => $s) {
        [$h, $m] = array_map('intval', explode(':', $s['start']));
        // This week's start (today counts), then back a week if it is still on.
        $ahead = ($s['day'] - (int) $now->format('w') + 7) % 7;
        $start = $now->setTime($h, $m)->modify("+{$ahead} days");
        $last = $start->modify('-7 days');
        if ($now >= $last && $now < $last->modify('+' . $s['hours'] . ' hours')) {
            $start = $last;
        } elseif ($now >= $start->modify('+' . $s['hours'] . ' hours')) {
            $start = $start->modify('+7 days');
        }
        $live = $now >= $start;
        $out[$i] = ['days' => $s['days'], 'time' => $s['time'], 'name' => $s['name'], 'state' => '', 'when' => '', '_at' => $start, '_live' => $live];
        if ($soonest === null || $live || (!$out[$soonest]['_live'] && $start < $out[$soonest]['_at'])) {
            $soonest = $i;
        }
    }
    if ($soonest !== null) {
        $s = $out[$soonest];
        $days = (int) $now->setTime(0, 0)->diff($s['_at']->setTime(0, 0))->format('%a');
        $out[$soonest]['state'] = $s['_live'] ? 'live' : 'next';
        $out[$soonest]['when'] = $s['_live'] ? 'On now'
            : ($days === 0 ? ((int) $s['_at']->format('G') >= 17 ? 'Tonight' : 'Today')
            : ($days === 1 ? 'Tomorrow' : 'Next'));
    }
    return array_values(array_map(static function (array $s): array {
        unset($s['_at'], $s['_live']);
        return $s;
    }, $out));
}
