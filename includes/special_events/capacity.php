<?php
// /includes/special_events/capacity.php
//
// The capacity engine (guide §10.4). Seats are scarce and people press the
// button at the same second, so every allocation decision here is made from
// counts read INSIDE se_lock_event() — never from a cached number, never
// check-then-insert.
//
// The functions split into two kinds:
//   * pure deciders  — se_registration_state(), se_seats_left(),
//                      se_seat_for_walkin(): no database, table-driven tests.
//   * lock holders   — se_capacity_counts(), se_promote_waitlist(): the caller
//                      must already hold the event row lock.

// --------------------------------------------------------------------------
// Counts (§10.4.1)
// --------------------------------------------------------------------------

/**
 * Live seat counts for one event.
 *
 * @return array{online_taken:int, walkin_taken:int, waitlisted:int,
 *               confirmed:int, cancelled:int}
 */
function se_capacity_counts(PDO $pdo, int $eventId): array
{
    $counts = [
        'online_taken' => 0,
        'walkin_taken' => 0,
        'waitlisted'   => 0,
        'confirmed'    => 0,
        'cancelled'    => 0,
    ];

    if (!se_table_exists($pdo, 'se_registrations')) {
        return $counts;
    }

    $stmt = $pdo->prepare(
        "SELECT status, seat_pool, COUNT(*) AS n
           FROM se_registrations
          WHERE event_id = ? AND is_test = 0
          GROUP BY status, seat_pool"
    );
    $stmt->execute([$eventId]);

    foreach ($stmt->fetchAll() as $row) {
        $n      = (int) $row['n'];
        $status = (string) $row['status'];
        $pool   = $row['seat_pool'] !== null ? (string) $row['seat_pool'] : null;

        if ($status === 'confirmed') {
            $counts['confirmed'] += $n;
            if ($pool === 'online') {
                $counts['online_taken'] += $n;
            } elseif ($pool === 'walkin') {
                $counts['walkin_taken'] += $n;
            }
        } elseif ($status === 'waitlisted') {
            $counts['waitlisted'] += $n;
        } elseif ($status === 'cancelled') {
            $counts['cancelled'] += $n;
        }
    }

    return $counts;
}

/** Free online seats, or null for "no limit". */
function se_online_free(array $event, array $counts): ?int
{
    $cap = $event['online_capacity'] ?? null;
    if ($cap === null || $cap === '') {
        return null;
    }

    return max(0, (int) $cap - (int) ($counts['online_taken'] ?? 0));
}

/** Free walk-in seats, or null for "no limit". */
function se_walkin_free(array $event, array $counts): ?int
{
    $cap = $event['walkin_capacity'] ?? null;
    if ($cap === null || $cap === '') {
        return null;
    }

    return max(0, (int) $cap - (int) ($counts['walkin_taken'] ?? 0));
}

// --------------------------------------------------------------------------
// Registration state (§10.4.2)
// --------------------------------------------------------------------------

/**
 * What the Register button is allowed to do right now.
 *
 * One of: open | waitlist | full | closed | closed_manual | closed_deadline |
 * not_open_yet. Pure: the same inputs always give the same answer, which is
 * what makes the table-driven test in tests/special_events/ possible.
 */
function se_registration_state(array $event, array $phaseInfo, array $counts, ?DateTimeImmutable $now = null): string
{
    $now ??= se_now();

    // Registration only ever happens before the doors open. On the day,
    // walk-ins go through check-in instead (§10.4.6).
    if (($phaseInfo['phase'] ?? '') !== 'upcoming') {
        return 'closed';
    }

    if (($event['reg_override'] ?? 'none') === 'force_closed') {
        return 'closed_manual';
    }

    $opensAt = se_parse_datetime($event['reg_opens_at'] ?? null);
    if ($opensAt !== null && $now < $opensAt) {
        return 'not_open_yet';
    }

    $closeAt = se_parse_datetime($event['reg_closes_at'] ?? null)
        ?? se_parse_datetime($phaseInfo['first_day']['starts_at'] ?? null);
    if ($closeAt !== null && $now >= $closeAt) {
        return 'closed_deadline';
    }

    if (($event['reg_override'] ?? 'none') === 'force_open') {
        return 'open';
    }

    $cap = $event['online_capacity'] ?? null;
    if ($cap === null || $cap === '') {
        return 'open';
    }
    if ((int) ($counts['online_taken'] ?? 0) < (int) $cap) {
        return 'open';
    }
    if (!se_bool($event['auto_close_at_capacity'] ?? 1)) {
        // Soft cap: keep taking people, flag the overshoot in the Studio.
        return 'open';
    }

    $wlCap = $event['waitlist_capacity'] ?? null;
    if (se_bool($event['waitlist_enabled'] ?? 0)
        && ($wlCap === null || $wlCap === '' || (int) ($counts['waitlisted'] ?? 0) < (int) $wlCap)) {
        return 'waitlist';
    }

    return 'full';
}

/** True when a `register` call may create or revive a row. */
function se_registration_state_accepts(string $state): bool
{
    return $state === 'open' || $state === 'waitlist';
}

/** The sentence the portal shows for a state that is not `open`. */
function se_registration_state_message(string $state, array $event = []): string
{
    return match ($state) {
        'open'            => 'Registration is open.',
        'waitlist'        => 'We are full — join the waitlist and we will text you the moment a seat opens.',
        'full'            => 'Registration is full.',
        'not_open_yet'    => 'Registration opens soon.',
        'closed_deadline' => 'Registration has closed.',
        'closed_manual'   => trim((string) ($event['reg_override_note'] ?? '')) !== ''
            ? (string) $event['reg_override_note']
            : 'Registration is paused.',
        default           => 'Registration is closed.',
    };
}

// --------------------------------------------------------------------------
// Seats left (§10.4.8)
// --------------------------------------------------------------------------

/** The number to show on the portal, or null when it must stay hidden. */
function se_seats_left(array $event, array $counts): ?int
{
    $cap  = $event['online_capacity'] ?? null;
    $mode = (string) ($event['seats_left_mode'] ?? 'never');

    if ($cap === null || $cap === '' || $mode === 'never') {
        return null;
    }

    $cap  = (int) $cap;
    $left = max(0, $cap - (int) ($counts['online_taken'] ?? 0));

    if ($mode === 'always') {
        return $left;
    }

    $pct = 100 * (int) ($counts['online_taken'] ?? 0) / max(1, $cap);

    return $pct >= (int) ($event['seats_left_threshold_pct'] ?? 70) ? $left : null;
}

// --------------------------------------------------------------------------
// Walk-in seating (§10.4.6)
// --------------------------------------------------------------------------

/**
 * Which pool a person at the door goes into: 'online' | 'walkin' | null.
 *
 * null means the desk must decide (WALKINS_DISABLED / WALKIN_FULL). The
 * caller holds the event lock.
 */
function se_seat_for_walkin(array $event, array $counts, bool $deskOverride = false): ?string
{
    $onlineFree = se_online_free($event, $counts);
    if ($onlineFree === null || $onlineFree > 0) {
        // A cancelled online seat is reused before a walk-in seat is spent.
        return 'online';
    }

    if (!se_bool($event['walkin_enabled'] ?? 1) && !$deskOverride) {
        return null;
    }

    $walkinFree = se_walkin_free($event, $counts);
    if ($walkinFree === null || $walkinFree > 0) {
        return 'walkin';
    }

    if (!se_bool($event['walkin_hard_cap'] ?? 0)) {
        return 'walkin';   // soft cap: allowed, flagged as over the cap
    }

    return $deskOverride ? 'walkin' : null;
}

// --------------------------------------------------------------------------
// Waitlist (§10.4.5)
// --------------------------------------------------------------------------

/**
 * Promote up to $seats people off the waitlist, oldest first.
 *
 * The caller holds the event lock. Returns the promoted registration ids, in
 * promotion order, so the caller can enqueue their SMS after the commit.
 *
 * @return list<int>
 */
function se_promote_waitlist(PDO $pdo, array $event, int $seats): array
{
    if ($seats <= 0) {
        return [];
    }
    if (!se_bool($event['waitlist_enabled'] ?? 0)) {
        return [];
    }
    if ((string) ($event['waitlist_promotion'] ?? 'auto_confirm') !== 'auto_confirm') {
        return [];   // manual: the Attendees tab offers Promote per person
    }

    return se_promote_waitlist_force($pdo, $event, $seats);
}

/**
 * Promote ONE named person off the waitlist (§12.5 `attendee_promote`).
 *
 * Crew sometimes need to jump the queue — a volunteer, a speaker's guest —
 * so this is allowed, but it is deliberately a different function from the
 * in-order promotion, and the audit row says which one happened.
 *
 * The caller holds the event lock.
 */
function se_promote_registration(PDO $pdo, array $event, int $registrationId): bool
{
    if (!se_table_exists($pdo, 'se_registrations')) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM se_registrations
          WHERE id = ? AND event_id = ? AND status = 'waitlisted' FOR UPDATE"
    );
    $stmt->execute([$registrationId, (int) $event['id']]);
    if (!$stmt->fetchColumn()) {
        return false;
    }

    $update = $pdo->prepare(
        "UPDATE se_registrations
            SET status = 'confirmed', seat_pool = 'online', confirmed_at = NOW()
          WHERE id = ? AND status = 'waitlisted'"
    );
    $update->execute([$registrationId]);

    return $update->rowCount() > 0;
}

/**
 * The same promotion, skipping the auto/manual check — used by the Studio's
 * explicit "Promote" action and by capacity increases.
 *
 * @return list<int>
 */
function se_promote_waitlist_force(PDO $pdo, array $event, int $seats): array
{
    if ($seats <= 0 || !se_table_exists($pdo, 'se_registrations')) {
        return [];
    }

    $eventId = (int) $event['id'];
    $counts  = se_capacity_counts($pdo, $eventId);
    $free    = se_online_free($event, $counts);
    $n       = $free === null ? $seats : min($seats, $free);
    if ($n <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM se_registrations
          WHERE event_id = ? AND status = 'waitlisted'
          ORDER BY waitlisted_at IS NULL, waitlisted_at, id
          LIMIT " . (int) $n . " FOR UPDATE"
    );
    $stmt->execute([$eventId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $promoted = [];
    $update   = $pdo->prepare(
        "UPDATE se_registrations
            SET status = 'confirmed', seat_pool = 'online', confirmed_at = NOW()
          WHERE id = ? AND status = 'waitlisted'"
    );

    foreach ($ids as $id) {
        $update->execute([$id]);
        if ($update->rowCount() > 0) {
            $promoted[] = $id;
            se_audit($pdo, $eventId, 'promote', ['registration_id' => $id, 'reason' => 'seat_freed'], 'registration', $id);
        }
    }

    return $promoted;
}

/**
 * How many seats could be handed to the waitlist right now. Used after a
 * capacity raise, a force_open, or auto_close_at_capacity being turned off.
 */
function se_promotable_seats(PDO $pdo, array $event): int
{
    $counts = se_capacity_counts($pdo, (int) $event['id']);
    $free   = se_online_free($event, $counts);

    return $free === null ? (int) $counts['waitlisted'] : $free;
}

/** 1-based position of a waitlisted registration, or null if not waitlisted. */
function se_waitlist_position(PDO $pdo, array $registration): ?int
{
    if ((string) ($registration['status'] ?? '') !== 'waitlisted') {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM se_registrations
          WHERE event_id = ? AND status = 'waitlisted'
            AND (waitlisted_at < ? OR (waitlisted_at = ? AND id < ?))"
    );
    $stmt->execute([
        (int) $registration['event_id'],
        $registration['waitlisted_at'],
        $registration['waitlisted_at'],
        (int) $registration['id'],
    ]);

    return 1 + (int) $stmt->fetchColumn();
}

// --------------------------------------------------------------------------
// After a change (§10.4.3)
// --------------------------------------------------------------------------

/**
 * Everything that must happen once seats have moved and the transaction has
 * committed: refresh the public snapshot (PR3 adds the writer) and send the
 * three one-off capacity notices.
 */
function se_after_capacity_change(PDO $pdo, array $event): void
{
    try {
        $counts = se_capacity_counts($pdo, (int) $event['id']);
        se_capacity_notify($pdo, $event, $counts);
    } catch (Throwable $e) {
        error_log('SE capacity/after_change: ' . $e->getMessage());
    }

    if (function_exists('se_live_publish')) {
        try {
            // Debounced (no force): a burst of registrations marks the state
            // dirty and the next tick or publish writes one snapshot.
            se_live_publish($pdo, (int) $event['id']);
        } catch (Throwable $e) {
            error_log('SE capacity/publish: ' . $e->getMessage());
        }
    }
}

/**
 * "Someone just registered" for the crew (§10.4.3).
 *
 * Called from se_register() on every channel — the public portal, the desk,
 * the Studio's manual add — so there is one place that decides whether a
 * registration is worth a buzz.
 *
 * Why it is a digest by default: a Chara night takes 300 registrations in an
 * evening. Three hundred notifications is not information, it is the reason
 * people turn notifications off and then miss the one that mattered. So the
 * first registration of an event always goes out immediately (that is the
 * "we are live" moment nobody wants to miss), and after that a summary is
 * sent at most every SE_REG_NOTICE_MIN_GAP_MINUTES. The floor scales the
 * right way on its own: a quiet event where somebody signs up once a day
 * still gets a notice per person, because a quarter of an hour has always
 * passed. An organiser who genuinely wants one per head can set
 * registration.notify_crew to 'every'.
 *
 * The audit log is the ledger, exactly as it is for the capacity notices
 * below: a `reg_notice` row is written in the same breath as the
 * notification, and its timestamp is what the next registration measures
 * itself against.
 */
function se_registration_notify(PDO $pdo, array $event, array $registration, array $settings): void
{
    try {
        $mode = (string) ($settings['registration']['notify_crew'] ?? 'digest');
        if ($mode === 'off') {
            return;
        }

        // A test-mode registration must never page a real person.
        if (se_bool($registration['is_test'] ?? 0) || se_bool($event['is_test'] ?? 0)) {
            return;
        }
        if (!se_table_exists($pdo, 'se_audit_log') || !se_table_exists($pdo, 'se_registrations')) {
            return;
        }

        $eventId = (int) $event['id'];

        $stmt = $pdo->prepare(
            "SELECT created_at FROM se_audit_log
              WHERE event_id = ? AND action = 'reg_notice'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$eventId]);
        $lastNotice = $stmt->fetchColumn();

        $newCount = 1;

        if ($mode === 'digest' && $lastNotice !== false && $lastNotice !== null) {
            $elapsed = (time() - strtotime((string) $lastNotice)) / 60;
            if ($elapsed < SE_REG_NOTICE_MIN_GAP_MINUTES) {
                return;                                   // still inside the quiet gap
            }

            // Everyone who arrived while we were quiet, this one included.
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM se_registrations
                  WHERE event_id = ? AND created_at > ?
                    AND status IN ('confirmed', 'waitlisted')
                    AND COALESCE(is_test, 0) = 0"
            );
            $stmt->execute([$eventId, $lastNotice]);
            $newCount = max(1, (int) $stmt->fetchColumn());
        }

        $recipients = se_capacity_notice_recipients($pdo, $eventId);
        if (!$recipients) {
            return;
        }

        $counts  = se_capacity_counts($pdo, $eventId);
        $who     = se_line((string) ($registration['display_name'] ?? ''), 40) ?: 'Someone';
        $title   = (string) $event['title'];
        $waiting = (int) $counts['waitlisted'];

        $heading = $newCount > 1
            ? $newCount . ' new registrations — ' . $title
            : $who . ' just registered for ' . $title;

        $body = ($newCount > 1 ? 'Latest: ' . $who . '. ' : '')
            . (int) $counts['confirmed'] . ' going'
            . ($waiting > 0 ? ', ' . $waiting . ' on the waitlist' : '')
            . '.';

        $insert = $pdo->prepare(
            "INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)"
        );
        foreach ($recipients as $recipientId) {
            try {
                $insert->execute([
                    $recipientId,
                    se_line($heading, 180),
                    $body,
                    se_studio_url($eventId, 'attendees'),
                ]);
            } catch (Throwable $e) {
                error_log('SE registration/notify insert: ' . $e->getMessage());
            }
        }

        se_audit($pdo, $eventId, 'reg_notice',
            ['new' => $newCount, 'confirmed' => (int) $counts['confirmed'], 'mode' => $mode],
            'event', $eventId);
    } catch (Throwable $e) {
        // A notification must never cost somebody their seat.
        error_log('SE registration/notify: ' . $e->getMessage());
    }
}

/**
 * capacity_90, capacity_full and waitlist_started, once each per event.
 *
 * The audit log is the ledger: `notice_sent:<kind>` is written in the same
 * breath as the notification, and its presence is what stops a second send.
 */
function se_capacity_notify(PDO $pdo, array $event, array $counts): void
{
    $cap = $event['online_capacity'] ?? null;
    if (!se_table_exists($pdo, 'se_audit_log')) {
        return;
    }

    $kinds = [];
    if ($cap !== null && $cap !== '' && (int) $cap > 0) {
        $taken = (int) $counts['online_taken'];
        $pct   = 100 * $taken / (int) $cap;
        if ($pct >= 90) {
            $kinds['capacity_90'] = 'Registration is at ' . (int) $pct . '% of capacity ('
                . $taken . ' of ' . (int) $cap . ').';
        }
        if ($taken >= (int) $cap) {
            $kinds['capacity_full'] = 'Online registration has reached capacity (' . (int) $cap . ' seats).';
        }
    }
    if ((int) $counts['waitlisted'] > 0) {
        $kinds['waitlist_started'] = 'The waitlist has started — ' . (int) $counts['waitlisted']
            . ' ' . ((int) $counts['waitlisted'] === 1 ? 'person is' : 'people are') . ' waiting for a seat.';
    }

    if (!$kinds) {
        return;
    }

    $eventId = (int) $event['id'];
    $stmt    = $pdo->prepare(
        "SELECT action FROM se_audit_log WHERE event_id = ? AND action LIKE 'notice_sent:%'"
    );
    $stmt->execute([$eventId]);
    $already = array_flip(array_map(
        static fn($a) => substr((string) $a, strlen('notice_sent:')),
        $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
    ));

    $recipients = null;

    foreach ($kinds as $kind => $message) {
        if (isset($already[$kind])) {
            continue;
        }

        $recipients ??= se_capacity_notice_recipients($pdo, $eventId);
        if ($recipients) {
            $insert = $pdo->prepare(
                "INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)"
            );
            foreach ($recipients as $userId) {
                try {
                    $insert->execute([
                        $userId,
                        (string) $event['title'] . ' — registration update',
                        $message,
                        se_studio_url($eventId, 'attendees'),
                    ]);
                } catch (Throwable $e) {
                    error_log('SE capacity/notify: ' . $e->getMessage());
                }
            }
        }

        se_audit($pdo, $eventId, 'notice_sent:' . $kind, ['kind' => $kind, 'recipients' => count($recipients ?? [])]);
    }
}

/**
 * Producers on this event plus module managers. Duplicates removed.
 *
 * @return list<int>
 */
function se_capacity_notice_recipients(PDO $pdo, int $eventId): array
{
    $ids = [];

    try {
        if (se_table_exists($pdo, 'se_crew')) {
            $stmt = $pdo->prepare(
                "SELECT user_id FROM se_crew WHERE event_id = ? AND role = 'producer' AND revoked_at IS NULL"
            );
            $stmt->execute([$eventId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $ids[(int) $id] = true;
            }
        }

        // Module managers, through the same roles table the login reads.
        $in   = implode(',', array_fill(0, count(SE_MANAGER_ROLES), '?'));
        $stmt = $pdo->prepare(
            "SELECT ur.user_id
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE r.role_name IN ($in)
              LIMIT 50"
        );
        $stmt->execute(SE_MANAGER_ROLES);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $ids[(int) $id] = true;
        }
    } catch (Throwable $e) {
        error_log('SE capacity/recipients: ' . $e->getMessage());
    }

    return array_values(array_map('intval', array_keys($ids)));
}
