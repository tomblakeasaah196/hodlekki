<?php
// /includes/special_events/checkin.php
//
// Check-in at the door (guide §10.5).
//
// One function does the work for all three entrances — the guest's own phone
// at /e/<slug>/in, the desk, and the Studio — because the hard parts (the
// window, the walk-in pool, the player number, the team, the verse) must
// behave identically no matter who taps the button.
//
// Everything that allocates (seat pool, player number, team, verse, karaoke
// queue number) happens inside se_lock_event(), and the one row that proves
// a person arrived is protected by a unique key on
// (event_id, registration_id, day_date) rather than a check-then-insert.
// Two phones tapping at the same instant therefore produce exactly one
// check-in, one player number and one team.

/** Are the check-in tables migrated yet? (§9.4) */
function se_checkin_ready(PDO $pdo): bool
{
    return se_table_exists($pdo, 'se_checkins');
}

/**
 * The check-in window, as /in needs to render it (§13.6).
 *
 * @return array{open: bool, phase: string, day_date: ?string, opens_at: ?string,
 *               closes_at: ?string, opens_in_ms: ?int, reason: ?string}
 */
function se_checkin_window(array $event, array $days, ?DateTimeImmutable $now = null): array
{
    $now   = $now ?? se_now();
    $phase = se_event_phase($event, $days, $now);
    $state = [
        'open'        => false,
        'phase'       => (string) $phase['phase'],
        'day_date'    => null,
        'opens_at'    => null,
        'closes_at'   => null,
        'opens_in_ms' => null,
        'reason'      => null,
    ];

    $next = $phase['day'] ?? ($phase['next_day'] ?? ($days[0] ?? null));
    if ($next) {
        $doors  = se_parse_datetime($next['doors_open_at'] ?? null);
        $closes = se_parse_datetime($next['checkin_closes_at'] ?? null);

        $state['day_date']  = (string) ($next['day_date'] ?? '');
        $state['opens_at']  = $doors !== null ? $doors->format('c') : null;
        $state['closes_at'] = $closes !== null ? $closes->format('c') : null;

        if ($doors !== null && $doors > $now) {
            $state['opens_in_ms'] = (int) round(($doors->format('U.u') - $now->format('U.u')) * 1000);
        }
    }

    if (($phase['phase'] ?? '') === 'live' && !empty($phase['checkin_open'])) {
        $state['open']     = true;
        $state['day_date'] = (string) ($phase['day']['day_date'] ?? $state['day_date']);
        return $state;
    }

    $state['reason'] = match ((string) $phase['phase']) {
        'upcoming', 'between_days' => 'CHECKIN_NOT_OPEN',
        'live', 'post'             => 'CHECKIN_CLOSED',
        default                    => 'CHECKIN_NOT_OPEN',
    };

    return $state;
}

/** The day a check-in belongs to: the live day, else today in WAT. */
function se_checkin_day(array $phaseInfo, ?DateTimeImmutable $now = null): string
{
    $day = $phaseInfo['day']['day_date'] ?? null;

    return $day !== null && $day !== ''
        ? (string) $day
        : ($now ?? se_now())->format('Y-m-d');
}

/** This person's check-in row for a day, or null. */
function se_checkin_row(PDO $pdo, int $eventId, int $registrationId, string $dayDate): ?array
{
    if (!se_checkin_ready($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM se_checkins WHERE event_id = ? AND registration_id = ? AND day_date = ?"
        );
        $stmt->execute([$eventId, $registrationId, $dayDate]);

        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('SE checkin/row: ' . $e->getMessage());
        return null;
    }
}

/**
 * Take one check-in.
 *
 * $in: phone (the se_phone_normalize array) OR registration_id, plus the
 * optional walk-in fields (first_name, last_name, gender, consent, email,
 * karaoke_interest) and override_walkin_cap for the desk.
 *
 * $ctx: now, days, device (row or null), method ('self'|'desk'|'studio'),
 * actor_user_id, ip_hash, is_crew (a signed-in crew device, which may check
 * in outside the window while test mode is on), is_test.
 *
 * @return array{outcome: string, ...} outcome is checked_in | already |
 *         already_elsewhere | other
 * @throws SeRuleException CHECKIN_NOT_OPEN | CHECKIN_CLOSED | WALKIN_FULL |
 *         NEEDS_DETAILS | NEEDS_GENDER | BLOCKED | FEATURE_NOT_READY
 */
function se_checkin(PDO $pdo, array $event, array $days, array $in, array $ctx): array
{
    $eventId = (int) $event['id'];
    $now     = $ctx['now'] ?? se_now();
    $method  = se_enum($ctx['method'] ?? 'self', ['self', 'desk', 'studio'], 'self');
    $actorId = isset($ctx['actor_user_id']) ? (int) $ctx['actor_user_id'] : null;
    $device  = $ctx['device'] ?? null;

    if (!se_checkin_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Check-in is not available yet.');
    }

    // ---- 1. Window -------------------------------------------------------
    $phase    = se_event_phase($event, $days, $now);
    $window   = se_checkin_window($event, $days, $now);
    $testMode = se_bool(se_event_settings($event)['test_mode'] ?? false);

    // Test mode exists so the crew can rehearse the whole door flow days
    // early (§11.13); refusing them on the window would defeat it.
    $bypass = $testMode && !empty($ctx['is_crew']);

    if (!$window['open'] && !$bypass) {
        throw new SeRuleException(
            (string) ($window['reason'] ?? 'CHECKIN_NOT_OPEN'),
            $window['reason'] === 'CHECKIN_CLOSED'
                ? 'Check-in has closed for today. Please see the desk.'
                : 'Check-in is not open yet.',
            ['opens_at' => $window['opens_at'], 'opens_in_ms' => $window['opens_in_ms']]
        );
    }

    $dayDate = se_checkin_day($phase, $now);
    $isTest  = $testMode || !empty($ctx['is_test']);

    // ---- 2. Resolve (before locking) ------------------------------------
    $phone   = $in['phone'] ?? null;
    $contact = null;
    $byId    = isset($in['registration_id']) ? se_int($in['registration_id'], 0) : 0;

    if ($byId <= 0) {
        if (!is_array($phone) || empty($phone['e164'])) {
            throw new SeValidationException(['phone' => 'Enter the phone number you registered with.']);
        }
        $contact = se_contact_find_by_phone($pdo, (string) $phone['e164']);
    }

    $settings    = se_event_settings($event);
    $teamsOn     = se_teams_ready($pdo) && se_bool(se_settings_path($settings, 'teams.enabled', true));
    $consentText = (string) ($in['consent_text'] ?? '');

    $result = se_lock_event($pdo, $eventId, function (array $ev, PDO $pdo) use (
        $eventId, $byId, $contact, $phone, $in, $ctx, $now, $method, $actorId,
        $device, $dayDate, $isTest, $teamsOn, $consentText
    ): array {
        // ---- 3. Load the registration FOR UPDATE -------------------------
        $reg = null;
        if ($byId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ? AND event_id = ? FOR UPDATE");
            $stmt->execute([$byId, $eventId]);
            $reg = $stmt->fetch() ?: null;
            if (!$reg) {
                throw new SeNotFoundException('We could not find that registration.');
            }
        } elseif ($contact) {
            $reg = se_registration_find_for_update($pdo, $eventId, (int) $contact['id']);
        }

        $isWalkin = false;

        // ---- 4. Branches -------------------------------------------------
        if ($reg && (string) $reg['status'] === 'removed') {
            throw new SeRuleException('BLOCKED', 'Please see the desk — we need to sort something out first.');
        }

        if (!$reg || (string) $reg['status'] !== 'confirmed') {
            $walk = se_checkin_walkin($pdo, $ev, $reg, $contact, $phone, $in, $ctx, $isTest);
            $reg      = $walk['registration'];
            $contact  = $walk['contact'];
            $isWalkin = true;
        }

        $regId = (int) $reg['id'];

        // ---- 5. Already checked in today? --------------------------------
        $existing = se_checkin_row($pdo, $eventId, $regId, $dayDate);
        if ($existing) {
            return se_checkin_already($pdo, $ev, $reg, $existing, $device, $method);
        }

        // ---- 6. Gender ----------------------------------------------------
        $gender = se_normalize_gender($reg['gender'] ?? null);
        if ($teamsOn && $gender === null) {
            $gender = se_normalize_gender($in['gender'] ?? null);
            if ($gender === null) {
                throw new SeRuleException('NEEDS_GENDER', 'One more tap: are you male or female?', [
                    'registration_id' => $regId,
                    'options'         => ['Male', 'Female'],
                ]);
            }

            $pdo->prepare("UPDATE se_registrations SET gender = ? WHERE id = ?")->execute([$gender, $regId]);
            $reg['gender'] = $gender;

            if ($reg['contact_id'] !== null) {
                $pdo->prepare("UPDATE se_contacts SET gender = COALESCE(gender, ?) WHERE id = ?")
                    ->execute([$gender, (int) $reg['contact_id']]);
            }
        }

        // ---- 10. Verse (picked before the insert so it can be stored) -----
        $verse = se_pick_verse($pdo, $eventId);

        // ---- 7. The check-in row -------------------------------------------
        // The unique key is the race winner, not a prior SELECT: a duplicate
        // here means another device got there milliseconds earlier, and the
        // right answer is that person's existing status.
        try {
            $pdo->prepare(
                "INSERT INTO se_checkins
                    (event_id, registration_id, day_date, method, is_walkin, is_test,
                     device_id, checked_in_by, verse_id, checked_in_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))"
            )->execute([
                $eventId, $regId, $dayDate, $method, $isWalkin ? 1 : 0, $isTest ? 1 : 0,
                $device['id'] ?? null, $actorId, $verse['id'] ?? null,
            ]);
        } catch (PDOException $e) {
            if (!se_is_duplicate_key($e)) {
                throw $e;
            }
            $existing = se_checkin_row($pdo, $eventId, $regId, $dayDate);
            if ($existing) {
                return se_checkin_already($pdo, $ev, $reg, $existing, $device, $method);
            }
            throw $e;
        }

        // ---- 8. Player number ---------------------------------------------
        if ($reg['player_no'] === null) {
            $playerNo = (int) $ev['next_player_no'];
            $pdo->prepare("UPDATE se_events SET next_player_no = next_player_no + 1 WHERE id = ?")
                ->execute([$eventId]);
            $pdo->prepare(
                "UPDATE se_registrations
                    SET player_no = ?, first_checkin_at = COALESCE(first_checkin_at, NOW(3))
                  WHERE id = ? AND player_no IS NULL"
            )->execute([$playerNo, $regId]);
            $reg['player_no'] = $playerNo;
        } else {
            $pdo->prepare("UPDATE se_registrations SET first_checkin_at = COALESCE(first_checkin_at, NOW(3)) WHERE id = ?")
                ->execute([$regId]);
        }

        // ---- 9. Team --------------------------------------------------------
        $team = null;
        if ($teamsOn) {
            $assigned = se_assign_team($pdo, $ev, $reg);
            $team     = $assigned['team'];
            if ($team) {
                $reg['team_id'] = (int) $team['id'];
            }
        }

        // ---- 11. Karaoke ----------------------------------------------------
        $karaoke = se_checkin_release_karaoke($pdo, $ev, $regId);

        // ---- 12. Device -----------------------------------------------------
        // Only the guest's own phone binds. The desk checking somebody in
        // must never make the desk tablet that person's device.
        $owns = se_device_owns($device, $reg);
        if ($method === 'self') {
            $owns = se_device_bind_if_unbound($pdo, $device, $regId) || $owns;
        }

        if ($consentText !== '' && $reg['contact_id'] !== null) {
            se_contact_set_consent($pdo, (int) $reg['contact_id'], !empty($in['consent']), $consentText);
        }

        // ---- 13. Audit -------------------------------------------------------
        se_audit($pdo, $eventId, 'checkin', [
            'registration_id' => $regId,
            'method'          => $method,
            'walkin'          => $isWalkin,
            'day'             => $dayDate,
            'team_id'         => $team['id'] ?? null,
            'player_no'       => $reg['player_no'],
            'is_test'         => $isTest,
        ], 'registration', $regId, $actorId, $regId);

        return [
            'outcome'      => 'checked_in',
            'registration' => se_registration_by_id($pdo, $regId, $eventId) ?? $reg,
            'team'         => $team,
            'verse'        => $verse,
            'karaoke'      => $karaoke,
            'device_owns'  => $owns,
            'walkin'       => $isWalkin,
        ];
    });

    // ---- 13. After commit --------------------------------------------------
    if ($result['outcome'] === 'checked_in') {
        se_live_publish($pdo, $eventId, true, ['public', 'lobby', 'room', 'team']);
    }

    return $result + ['payload' => se_checkin_payload($pdo, $event, $result)];
}

/**
 * The walk-in branch of step 4: find a pool, fill in what we do not know and
 * create or revive the registration as `confirmed`.
 *
 * Runs inside the event lock, so the pool it claims cannot be claimed twice.
 */
function se_checkin_walkin(
    PDO $pdo,
    array $event,
    ?array $reg,
    ?array $contact,
    ?array $phone,
    array $in,
    array $ctx,
    bool $isTest
): array {
    $eventId  = (int) $event['id'];
    $counts   = se_capacity_counts($pdo, $eventId);
    $override = !empty($in['override_walkin_cap']) && ($ctx['method'] ?? 'self') !== 'self';
    $pool     = se_seat_for_walkin($event, $counts, $override);

    if ($pool === null) {
        throw new SeRuleException(
            'WALKIN_FULL',
            'We are at capacity tonight. Please see the desk — they may still be able to help.'
        );
    }

    // What we must ask for when nobody has told us yet.
    $firstName = se_clean_name($in['first_name'] ?? ($reg['first_name'] ?? ($contact['first_name'] ?? '')));
    $lastName  = se_clean_name($in['last_name'] ?? ($reg['last_name'] ?? ($contact['last_name'] ?? '')));

    $missing = [];
    if ($firstName === '') {
        $missing[] = 'first_name';
    }
    if (!$contact && !$reg && (!is_array($phone) || empty($phone['e164']))) {
        $missing[] = 'phone';
    }
    if ($missing) {
        throw new SeRuleException(
            'NEEDS_DETAILS',
            'Welcome! We just need a couple of details.',
            ['fields' => $missing]
        );
    }

    $gender = se_normalize_gender($in['gender'] ?? ($reg['gender'] ?? ($contact['gender'] ?? null)));
    $email  = se_contact_clean_email($in['email'] ?? null);

    if (!$contact) {
        if (!is_array($phone) || empty($phone['e164'])) {
            throw new SeValidationException(['phone' => 'Enter a phone number.']);
        }
        $contact = se_contact_get_or_create($pdo, $phone, [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'gender'     => $gender,
            'email'      => $email,
        ]);
    }

    $row = [
        'first_name'       => $firstName,
        'last_name'        => $lastName,
        'gender'           => $gender,
        'email'            => $email,
        'display_name'     => se_display_name($firstName, $lastName),
        'is_member'        => $contact['member_user_id'] !== null,
        'is_test'          => $isTest,
        'karaoke_interest' => !empty($in['karaoke_interest']),
        'channel'          => ($ctx['method'] ?? 'self') === 'self' ? 'walkin_self' : 'walkin_desk',
        'answers'          => [],
    ];

    $reg = $reg
        ? se_reactivate_registration($pdo, $reg, 'confirmed', $pool, $row)
        : se_insert_registration($pdo, $event, $contact, 'confirmed', $pool, $row, $ctx);

    // A reactivated row keeps its old channel; make it say how they really
    // got in, because the report counts walk-ins from this column.
    $pdo->prepare("UPDATE se_registrations SET channel = ?, seat_pool = ?, is_test = ? WHERE id = ?")
        ->execute([$row['channel'], $pool, $isTest ? 1 : 0, (int) $reg['id']]);

    if (!empty($in['override_walkin_cap']) && $override) {
        se_audit($pdo, $eventId, 'walkin_override', [
            'registration_id' => (int) $reg['id'], 'pool' => $pool,
        ], 'registration', (int) $reg['id'], $ctx['actor_user_id'] ?? null);
    }

    return ['registration' => se_registration_by_id($pdo, (int) $reg['id'], $eventId) ?? $reg, 'contact' => $contact];
}

/**
 * Step 5: somebody is already checked in today.
 *
 * The three cases differ only in what the device in front of us is allowed
 * to see — nothing is written to the check-in table either way.
 */
function se_checkin_already(PDO $pdo, array $event, array $reg, array $existing, ?array $device, string $method): array
{
    $owns = se_device_owns($device, $reg);
    $outcome = 'already';

    if (!$owns && $device !== null && $method === 'self') {
        if ($device['registration_id'] === null) {
            // A second phone of the same guest: let it watch, never act.
            se_device_bind_if_unbound($pdo, $device, (int) $reg['id'], 'readonly');
            $outcome = 'already_elsewhere';
        } else {
            // This phone belongs to somebody else — they are looking up a
            // friend, so they get the friend's status and nothing private.
            $outcome = 'other';
        }
    }

    $team = !empty($reg['team_id']) ? se_team_find($pdo, (int) $event['id'], (int) $reg['team_id']) : null;
    $verse = null;
    if (!empty($existing['verse_id'])) {
        $verse = se_verse_find($pdo, (int) $event['id'], (int) $existing['verse_id']);
    }

    return [
        'outcome'      => $outcome,
        'registration' => $reg,
        'team'         => $team,
        'verse'        => $verse,
        'karaoke'      => null,
        'device_owns'  => $owns,
        'walkin'       => se_bool($existing['is_walkin']),
        'checked_in_at' => (string) $existing['checked_in_at'],
    ];
}

/**
 * Step 11: a karaoke entry held at registration joins the real queue now
 * that the singer is actually in the room.
 *
 * Returns null until PR4's karaoke tables exist.
 */
function se_checkin_release_karaoke(PDO $pdo, array $event, int $registrationId): ?array
{
    $eventId = (int) $event['id'];

    if (!se_table_exists($pdo, 'se_karaoke_entries')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM se_karaoke_entries
              WHERE event_id = ? AND registration_id = ? AND status = 'held'
              ORDER BY id ASC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$eventId, $registrationId]);
        $entry = $stmt->fetch();

        if (!$entry) {
            return null;
        }

        $queueNo = (int) $event['next_karaoke_no'];
        $pdo->prepare("UPDATE se_events SET next_karaoke_no = next_karaoke_no + 1 WHERE id = ?")->execute([$eventId]);

        $pos = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM se_karaoke_entries WHERE event_id = ?");
        $pos->execute([$eventId]);
        $position = (int) $pos->fetchColumn();

        $pdo->prepare(
            "UPDATE se_karaoke_entries
                SET status = 'queued', queue_no = ?, position = ?, queued_at = NOW()
              WHERE id = ?"
        )->execute([$queueNo, $position, (int) $entry['id']]);

        return ['status' => 'queued', 'queue_no' => $queueNo, 'ahead' => max(0, $position - 1)];
    } catch (Throwable $e) {
        // A karaoke hiccup must never stop somebody getting into the room.
        error_log('SE checkin/karaoke: ' . $e->getMessage());
        return null;
    }
}

/** Step 14: the response the reveal animation and the welcome card read. */
function se_checkin_payload(PDO $pdo, array $event, array $result): array
{
    $reg   = $result['registration'];
    $theme = se_event_theme($event);
    $self  = !empty($result['device_owns']) && $result['outcome'] !== 'other';

    $payload = [
        'for'           => $self ? 'self' : 'other',
        'already'       => in_array($result['outcome'], ['already', 'already_elsewhere', 'other'], true),
        'already_elsewhere' => $result['outcome'] === 'already_elsewhere',
        'team'          => $result['team'] ? se_team_public($result['team'], $theme) : null,
        'player_no'     => $reg['player_no'] !== null ? (int) $reg['player_no'] : null,
        'display_name'  => (string) $reg['display_name'],
        'verse'         => se_verse_for_card($result['verse'] ?? null, (string) $reg['first_name']),
        'karaoke'       => $result['karaoke'] ?? null,
        'card_signature' => trim((string) $event['title'] . ' ' . (string) ($event['edition_label'] ?? ''))
            . ' by ' . (string) ($event['organizer_label'] ?? 'Envision'),
        'walkin'        => !empty($result['walkin']),
        'test_mode'     => se_bool(se_event_settings($event)['test_mode'] ?? false),
    ];

    // The display keys are what let a phone watch its own team's private
    // snapshot, so they go only to the device that owns the registration.
    if ($self) {
        $state = se_live_state($pdo, (int) $event['id']);
        if ($state) {
            $payload['room_key'] = (string) $state['room_key'];
        }
        if ($result['team']) {
            $payload['team_key'] = (string) $result['team']['team_key'];
        }
    }

    return $payload;
}

// --------------------------------------------------------------------------
// Desk tools (§10.5 desk variants, §13.11)
// --------------------------------------------------------------------------

/**
 * Desk search: name, phone (any format), reg code or player number.
 *
 * Returns at most 20 rows and never the raw phone number — the desk sees a
 * masked one, which is enough to tell two Adas apart (§19.5).
 */
function se_desk_search(PDO $pdo, array $event, array $days, string $query, int $limit = 20): array
{
    $eventId = (int) $event['id'];
    $query   = se_line($query, 60);

    if (mb_strlen($query, 'UTF-8') < 2) {
        return [];
    }

    $where  = [];
    $params = [$eventId];

    $phone = se_phone_normalize($query);
    if ($phone !== null) {
        $where[]  = 'c.phone_e164 = ?';
        $params[] = $phone['e164'];
    }
    if (ctype_digit($query)) {
        $where[]  = 'r.player_no = ?';
        $params[] = (int) $query;
    }
    $where[]  = 'r.reg_code = ?';
    $params[] = strtoupper($query);
    $where[]  = 'r.display_name LIKE ?';
    $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';

    try {
        $stmt = $pdo->prepare(
            "SELECT r.*, c.phone_e164
               FROM se_registrations r
               LEFT JOIN se_contacts c ON c.id = r.contact_id
              WHERE r.event_id = ? AND r.status <> 'removed' AND (" . implode(' OR ', $where) . ")
              ORDER BY r.display_name ASC
              LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('SE checkin/search: ' . $e->getMessage());
        return [];
    }

    $phaseInfo = se_event_phase($event, $days);
    $dayDate   = se_checkin_day($phaseInfo);
    $theme     = se_event_theme($event);

    $out = [];
    foreach ($rows as $row) {
        $checkin = se_checkin_row($pdo, $eventId, (int) $row['id'], $dayDate);
        $team    = !empty($row['team_id']) ? se_team_find($pdo, $eventId, (int) $row['team_id']) : null;

        $out[] = [
            'registration_id' => (int) $row['id'],
            'display_name'    => (string) $row['display_name'],
            'phone_masked'    => $row['phone_e164'] !== null ? se_mask_phone((string) $row['phone_e164']) : null,
            'status'          => (string) $row['status'],
            'is_member'       => se_bool($row['is_member']),
            'player_no'       => $row['player_no'] !== null ? (int) $row['player_no'] : null,
            'team'            => $team ? se_team_public($team, $theme) : null,
            'checked_in'      => $checkin !== null,
            'checked_in_at'   => $checkin !== null ? se_iso((string) $checkin['checked_in_at']) : null,
        ];
    }

    return $out;
}

/**
 * Undo today's check-in (§10.5 Undo).
 *
 * The player number, the team and the karaoke place stay: they were handed
 * out and said out loud, and taking them back would renumber the room.
 */
function se_checkin_undo(PDO $pdo, array $event, array $days, int $registrationId, string $reason, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $reason  = se_line($reason, 160);

    if (mb_strlen($reason, 'UTF-8') < 3) {
        throw new SeValidationException(['reason' => 'Say why in a few words — it goes in the log.']);
    }
    if (!se_checkin_ready($pdo)) {
        throw new SeRuleException('FEATURE_NOT_READY', 'Check-in is not available yet.');
    }

    $dayDate = se_checkin_day(se_event_phase($event, $days));
    $row     = se_checkin_row($pdo, $eventId, $registrationId, $dayDate);

    if (!$row) {
        throw new SeNotFoundException('There is no check-in to undo for today.');
    }

    $pdo->prepare("DELETE FROM se_checkins WHERE id = ? AND event_id = ?")->execute([(int) $row['id'], $eventId]);

    se_audit($pdo, $eventId, 'checkin_undo', [
        'registration_id' => $registrationId,
        'reason'          => $reason,
        'deleted'         => $row,
    ], 'registration', $registrationId, $actorId);

    se_live_publish($pdo, $eventId, true, ['public', 'lobby']);

    return ['undone' => true, 'day_date' => $dayDate];
}

/**
 * Issue a 6-digit transfer code so a guest can move their session to the
 * phone in their hand (§10.5 Transfer code).
 */
function se_transfer_code_issue(PDO $pdo, array $event, array $days, int $registrationId, ?int $actorId): array
{
    $eventId = (int) $event['id'];
    $reg     = se_registration_by_id($pdo, $registrationId, $eventId);

    if (!$reg) {
        throw new SeNotFoundException('We could not find that registration.');
    }

    // Only one live code at a time: an old slip of paper must stop working.
    se_tokens_revoke($pdo, $registrationId, 'transfer');

    [$code] = se_token_issue(
        $pdo,
        $eventId,
        $registrationId,
        'transfer',
        se_now()->add(new DateInterval('PT' . SE_TRANSFER_CODE_TTL_MIN . 'M'))
    );

    se_audit($pdo, $eventId, 'transfer_code_issued', [
        'registration_id' => $registrationId,
    ], 'registration', $registrationId, $actorId);

    return [
        'code'       => $code,
        'expires_in' => SE_TRANSFER_CODE_TTL_MIN * 60,
        'display_name' => (string) $reg['display_name'],
    ];
}

/**
 * Redeem a transfer code on the guest's own phone: this device becomes the
 * one that can act, every other device of theirs becomes read-only.
 */
function se_transfer_code_redeem(PDO $pdo, array $event, string $code, ?array $device): array
{
    $code = preg_replace('/\D+/', '', $code) ?? '';

    if (strlen($code) !== 6) {
        throw new SeValidationException(['code' => 'Enter the 6-digit code from the desk.']);
    }
    if ($device === null) {
        throw new SeRuleException('DEVICE_REQUIRED', 'Please turn cookies on so we can remember this phone.');
    }

    $token = se_token_lookup($pdo, $code, 'transfer', (int) $event['id']);
    if (!$token) {
        throw new SeRuleException('CODE_INVALID', 'That code has expired. Ask the desk for a new one.');
    }

    $registrationId = (int) $token['registration_id'];

    $pdo->prepare("UPDATE se_access_tokens SET used_at = NOW() WHERE id = ?")->execute([(int) $token['id']]);
    $pdo->prepare(
        "UPDATE se_devices SET mode = 'readonly'
          WHERE registration_id = ? AND id <> ?"
    )->execute([$registrationId, (int) $device['id']]);

    se_device_bind_force($pdo, $device, $registrationId, 'full');

    se_audit($pdo, (int) $event['id'], 'device_transfer', [
        'registration_id' => $registrationId,
    ], 'registration', $registrationId, null, $registrationId);

    return ['registration_id' => $registrationId];
}

/**
 * `lookup` with `purpose = "checkin"` (§12.2).
 *
 * The register lookup already works out who this phone belongs to and what
 * we still need to ask; check-in adds two things on top: whether they are
 * already in tonight, and when the door opens.
 */
function se_lookup_phone_checkin(PDO $pdo, array $event, array $days, array $settings, array $phone, ?array $device): array
{
    $out     = se_lookup_phone($pdo, $event, $settings, $phone, $device);
    $window  = se_checkin_window($event, $days);
    $eventId = (int) $event['id'];

    $out['checkin'] = [
        'open'        => $window['open'],
        'opens_at'    => $window['opens_at'],
        'opens_in_ms' => $window['opens_in_ms'],
        'closes_at'   => $window['closes_at'],
    ];

    $contact = se_contact_find_by_phone($pdo, (string) $phone['e164']);
    $reg     = $contact ? se_registration_find($pdo, $eventId, (int) $contact['id']) : null;

    if ($reg && (string) $reg['status'] === 'confirmed' && $window['day_date'] !== null) {
        $row = se_checkin_row($pdo, $eventId, (int) $reg['id'], (string) $window['day_date']);
        if ($row) {
            $out['kind']         = 'checked_in';
            $out['display_name'] = (string) $reg['display_name'];
            $out['needs']        = [];
            $out['device_owns']  = se_device_owns($device, $reg);
            $out['player_no']    = $reg['player_no'] !== null ? (int) $reg['player_no'] : null;
        }
    }

    // A walk-in only ever needs a name and consent — the full registration
    // form belongs on the portal, not on a queue at the door (§13.6).
    $out['needs'] = array_values(array_intersect(
        $out['needs'],
        ['first_name', 'last_name', 'gender', 'consent']
    ));

    return $out;
}

/** Check-in pace in 5-minute buckets, for the Studio live monitor (§18.3). */
function se_checkin_pace(PDO $pdo, int $eventId, int $buckets = 24): array
{
    if (!se_checkin_ready($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(checked_in_at, '%Y-%m-%d %H:%i') AS minute, COUNT(*) AS n
               FROM se_checkins
              WHERE event_id = ? AND checked_in_at >= (NOW() - INTERVAL ? MINUTE)
              GROUP BY FLOOR(UNIX_TIMESTAMP(checked_in_at) / 300)
              ORDER BY minute ASC"
        );
        $stmt->execute([$eventId, $buckets * 5]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['at' => (string) $row['minute'], 'n' => (int) $row['n']];
        }

        return $out;
    } catch (Throwable $e) {
        error_log('SE checkin/pace: ' . $e->getMessage());
        return [];
    }
}
