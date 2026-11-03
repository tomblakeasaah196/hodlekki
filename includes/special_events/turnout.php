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
// Every person's phone is checked against the ERP `users` table each time
// the tab loads. A match is a MEMBER and is shown under their exact system
// name (what they typed is kept alongside, never overwritten); no match is a
// GUEST and keeps the name they typed. Nothing is written: the module never
// writes `users`, and a guest's own registration is the record of what they
// told us.
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
          ORDER BY r.id ASC"
    );
    $stmt->execute([$eventId, $eventId]);
    $rows = $stmt->fetchAll() ?: [];

    $index = se_turnout_member_index($pdo);
    foreach ($rows as &$row) {
        $row['member'] = se_turnout_match_member($index, (string) ($row['phone_e164'] ?? ''));
    }
    unset($row);

    // Alphabetical by the name the list actually shows.
    usort($rows, static fn(array $a, array $b): int =>
        [mb_strtolower(se_turnout_shown_name($a)), (int) $a['id']]
        <=> [mb_strtolower(se_turnout_shown_name($b)), (int) $b['id']]);

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
    $phone  = (string) ($row['phone_e164'] ?? '');
    $typed  = se_turnout_typed_name($row);
    $member = $row['member'] ?? null;
    $shown  = se_turnout_shown_name($row);

    return [
        'id'               => (int) $row['id'],
        'name'             => $shown,
        // What they typed, only when it differs from the system name shown.
        'typed_name'       => $member !== null && se_turnout_name_key($typed) !== se_turnout_name_key($shown) ? $typed : null,
        'is_member'        => $member !== null,
        'member_ambiguous' => $member !== null && !empty($member['ambiguous']),
        'phone'            => $phone === '' ? null : ($withPii ? se_phone_display($phone) : se_mask_phone($phone)),
        'phone_e164'       => $withPii && $phone !== '' ? $phone : null,
        'status'           => (string) $row['status'],
        'channel'          => (string) $row['channel'],
        'registered_at'    => se_iso($row['created_at'] ?? null),
        'checked_in_at'    => se_iso($row['checked_in_at'] ?? null),
    ];
}

// --------------------------------------------------------------------------
// Member names from the ERP directory
// --------------------------------------------------------------------------

/** The name the guest typed on their registration. */
function se_turnout_typed_name(array $row): string
{
    return trim(preg_replace('/\s+/u', ' ', (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?? '');
}

/** The system name for a member (when the directory has one), else the typed name. */
function se_turnout_shown_name(array $row): string
{
    $system = (string) ($row['member']['name'] ?? '');

    return $system !== '' ? $system : se_turnout_typed_name($row);
}

/** Case- and spacing-insensitive form of a name, for "did they type it right?". */
function se_turnout_name_key(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
}

/**
 * The last ten digits of a phone number written in any form, or null.
 * "0803 123 4567", "+234 803-123-4567" and "2348031234567" all give
 * "8031234567" — the same fallback se_find_member() uses.
 */
function se_turnout_phone_key(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    return strlen($digits) >= 10 ? substr($digits, -10) : null;
}

/**
 * Directory rows (id, first_name, last_name, phone) grouped by phone key.
 * Pure, so the matching rules can be tested without a database.
 *
 * @return array<string, array<int, array>>
 */
function se_turnout_member_index_build(array $users): array
{
    $index = [];
    foreach ($users as $user) {
        $key = se_turnout_phone_key((string) ($user['phone'] ?? ''));
        if ($key !== null) {
            $index[$key][] = $user;
        }
    }

    return $index;
}

/**
 * Every user with a phone, in one query, rather than se_find_member()'s two
 * queries per person (its fallback scans `users` each time — 300 guests would
 * be 300 scans). Same table, same columns, same matching rules.
 */
function se_turnout_member_index(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            "SELECT id, first_name, last_name, phone FROM users
              WHERE phone IS NOT NULL AND phone <> '' ORDER BY id ASC"
        );

        return se_turnout_member_index_build($stmt ? ($stmt->fetchAll() ?: []) : []);
    } catch (Throwable $e) {
        error_log('SE turnout/member_index: ' . $e->getMessage());
        return [];
    }
}

/**
 * The member behind a registration's phone, or null for a guest.
 *
 * Mirrors se_find_member(): Nigerian mobiles only (a ten-digit tail means
 * nothing across country codes), an exact stored format wins over a
 * punctuation-insensitive match, and two users on one number still count as
 * a member, flagged `ambiguous` for IDI to tidy.
 *
 * @return array{id:int, name:string, ambiguous:bool}|null
 */
function se_turnout_match_member(array $index, string $e164): ?array
{
    if ($e164 === '' || !se_phone_sms_capable($e164)) {
        return null;
    }

    $candidates = $index[substr($e164, -10)] ?? [];
    if (!$candidates) {
        return null;
    }

    $exact = [$e164, '+' . $e164, '0' . substr($e164, 3)];
    $pick  = $candidates[0];
    foreach ($candidates as $user) {
        if (in_array(trim((string) ($user['phone'] ?? '')), $exact, true)) {
            $pick = $user;
            break;
        }
    }

    return [
        'id'        => (int) $pick['id'],
        'name'      => trim(preg_replace('/\s+/u', ' ', (string) ($pick['first_name'] ?? '') . ' ' . (string) ($pick['last_name'] ?? '')) ?? ''),
        'ambiguous' => count($candidates) > 1,
    ];
}
