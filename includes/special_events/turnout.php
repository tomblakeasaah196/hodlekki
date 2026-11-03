<?php
// /includes/special_events/turnout.php
//
// Studio → Turnout: four lists of people for one event, each behind a count.
//
//   registered — everyone still signed up (confirmed or waitlisted).
//   checked_in — everyone who checked in on any day.
//   day_of     — checked in AND registered on the day: either through a
//                walk-in channel, or created on/after the event's first day.
//   no_show    — signed up (confirmed or waitlisted) and never checked in.
//
// Rehearsal (is_test) and deleted registrations are left out of every list,
// the same way Insights leaves them out of its totals. Names and numbers
// are personal data: phones come back in full only to a caller holding
// `attendee.pii`, masked otherwise (§19.1), exactly like the Attendees list.

/** Registration channels that only exist at the door. */
const SE_TURNOUT_WALKIN_CHANNELS = ['walkin_self', 'walkin_desk'];

/** The four cards, in the order they are shown. */
const SE_TURNOUT_BUCKETS = ['registered', 'checked_in', 'day_of', 'no_show'];

/**
 * Did this person sign up on the day? A walk-in channel says so on its own;
 * otherwise the registration's WAT calendar date is compared with the first
 * event day. `$firstDay` is 'Y-m-d'; an empty one means the event has no
 * date to compare against, and only the channel counts.
 */
function se_turnout_is_day_of(array $row, string $firstDay): bool
{
    if (in_array((string) ($row['channel'] ?? ''), SE_TURNOUT_WALKIN_CHANNELS, true)) {
        return true;
    }

    if ($firstDay === '') {
        return false;
    }

    $created = substr((string) ($row['created_at'] ?? ''), 0, 10);

    return $created !== '' && $created >= $firstDay;
}

/**
 * Sort registration rows into the four buckets.
 *
 * Each row needs `status`, `channel`, `created_at` and `checked_in_at`
 * (null when the person never checked in). A row can sit in several
 * buckets: a walk-in who came is registered, checked in and day-of.
 *
 * @return array<string, array<int, array>>
 */
function se_turnout_classify(array $rows, string $firstDay): array
{
    $out = array_fill_keys(SE_TURNOUT_BUCKETS, []);

    foreach ($rows as $row) {
        $live = in_array((string) ($row['status'] ?? ''), ['confirmed', 'waitlisted'], true);
        $came = ($row['checked_in_at'] ?? null) !== null && (string) $row['checked_in_at'] !== '';

        if ($live) {
            $out['registered'][] = $row;
        }
        if ($came) {
            $out['checked_in'][] = $row;
            if (se_turnout_is_day_of($row, $firstDay)) {
                $out['day_of'][] = $row;
            }
        } elseif ($live) {
            $out['no_show'][] = $row;
        }
    }

    return $out;
}

/** The event's first day as 'Y-m-d', or '' when it has none. */
function se_turnout_first_day(PDO $pdo, array $event): string
{
    $stmt = $pdo->prepare('SELECT MIN(day_date) FROM se_event_days WHERE event_id = ?');
    $stmt->execute([(int) $event['id']]);
    $day = (string) ($stmt->fetchColumn() ?: '');

    if ($day === '') {
        $start = se_parse_datetime($event['starts_at'] ?? null);
        $day   = $start ? $start->format('Y-m-d') : '';
    }

    return $day;
}

/** The whole Turnout payload: counts plus one list per card. */
function se_turnout(PDO $pdo, array $event, bool $withPii): array
{
    $eventId  = (int) $event['id'];
    $firstDay = se_turnout_first_day($pdo, $event);
    $empty    = [
        'first_day' => $firstDay ?: null,
        'pii'       => $withPii,
        'counts'    => array_fill_keys(SE_TURNOUT_BUCKETS, 0),
        'lists'     => array_fill_keys(SE_TURNOUT_BUCKETS, []),
    ];

    if (!se_table_exists($pdo, 'se_registrations') || !se_table_exists($pdo, 'se_checkins')) {
        return $empty;
    }

    // One row per registration, with the first non-rehearsal check-in. Only
    // people who are still signed up or who actually came are fetched.
    $stmt = $pdo->prepare(
        "SELECT r.id, r.first_name, r.last_name, r.status, r.channel, r.created_at,
                c.phone_e164, ck.checked_in_at
           FROM se_registrations r
           LEFT JOIN se_contacts c ON c.id = r.contact_id
           LEFT JOIN (
                SELECT registration_id, MIN(checked_in_at) AS checked_in_at
                  FROM se_checkins
                 WHERE event_id = ? AND is_test = 0
                 GROUP BY registration_id
           ) ck ON ck.registration_id = r.id
          WHERE r.event_id = ? AND r.is_test = 0 AND r.deleted_at IS NULL
            AND (r.status IN ('confirmed','waitlisted') OR ck.checked_in_at IS NOT NULL)
          ORDER BY r.first_name ASC, r.last_name ASC, r.id ASC"
    );
    $stmt->execute([$eventId, $eventId]);
    $rows = $stmt->fetchAll() ?: [];

    $buckets = se_turnout_classify($rows, $firstDay);
    $out     = $empty;

    foreach ($buckets as $key => $list) {
        $out['counts'][$key] = count($list);
        $out['lists'][$key]  = array_map(
            static fn(array $row): array => se_turnout_person($row, $withPii),
            $list
        );
    }

    return $out;
}

/** One person as a card's modal shows them. */
function se_turnout_person(array $row, bool $withPii): array
{
    $phone = (string) ($row['phone_e164'] ?? '');
    $name  = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);

    return [
        'id'            => (int) $row['id'],
        'name'          => $name,
        'phone'         => $phone === '' ? null : ($withPii ? se_phone_display($phone) : se_mask_phone($phone)),
        'phone_e164'    => $withPii && $phone !== '' ? $phone : null,
        'status'        => (string) $row['status'],
        'channel'       => (string) $row['channel'],
        'registered_at' => se_iso($row['created_at'] ?? null),
        'checked_in_at' => se_iso($row['checked_in_at'] ?? null),
    ];
}
