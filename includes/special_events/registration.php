<?php
// /includes/special_events/registration.php
//
// Registering, cancelling and the `me` payload (guide §10.4.3, §10.4.4,
// §12.2.1). This file holds the writes the public portal is allowed to make;
// the API file above it only validates shapes and applies rate limits.
//
// Two rules run through everything here:
//   * every seat decision happens inside se_lock_event();
//   * a person is their phone number. Names are decoration, and the device
//     cookie only ever points at one of them (§10.3.5).

// --------------------------------------------------------------------------
// Lookup (§12.2 `lookup`)
// --------------------------------------------------------------------------

/**
 * What the portal needs to know after the person types their phone number.
 *
 * Deliberately thin: it never reveals an email, never a full name — only the
 * "Ada O." display form, which is what the person themselves would recognise.
 *
 * @return array{kind:string, display_name:?string, needs:list<string>, ...}
 */
function se_lookup_phone(PDO $pdo, array $event, array $settings, array $phone, ?array $device): array
{
    $contact = se_contact_find_by_phone($pdo, $phone['e164']);
    $reg     = null;

    if ($contact) {
        $contact = se_contact_refresh_member($pdo, $contact);
        $reg     = se_registration_find($pdo, (int) $event['id'], (int) $contact['id']);
    }

    $fields = (array) ($settings['registration']['fields'] ?? []);
    $needs  = [];

    $firstName = (string) ($contact['first_name'] ?? '');
    $lastName  = (string) ($contact['last_name'] ?? '');
    $member    = null;

    if ($contact && $contact['member_user_id'] !== null) {
        $member = se_find_member($pdo, $phone['e164']);
        if ($member) {
            $firstName = $member['first_name'] !== '' ? $member['first_name'] : $firstName;
            $lastName  = $member['last_name'] !== '' ? $member['last_name'] : $lastName;
        }
    }

    $known = $contact !== null && trim($firstName) !== '' && strtolower(trim($firstName)) !== 'guest';

    if (!$known) {
        $needs[] = 'first_name';
        $needs[] = 'last_name';
    }
    if (($fields['gender'] ?? 'required') !== 'off' && ($contact['gender'] ?? null) === null) {
        $needs[] = 'gender';
    }
    if (($fields['email'] ?? 'optional') === 'required' && ($contact['email'] ?? null) === null) {
        $needs[] = 'email';
    }
    if (!$contact || !se_bool($contact['consent_followup'] ?? 0)) {
        $needs[] = 'consent';
    }

    $out = [
        'kind'         => 'new',
        'display_name' => $known ? se_display_name($firstName, $lastName) : null,
        'needs'        => array_values(array_unique($needs)),
        'is_member'    => $contact !== null && $contact['member_user_id'] !== null,
    ];

    if ($contact && $contact['member_user_id'] !== null) {
        $out['kind'] = 'member';
    } elseif ($known) {
        $out['kind'] = 'returning';
    }

    if ($reg && in_array((string) $reg['status'], ['confirmed', 'waitlisted'], true)) {
        $out['kind']              = 'registered';
        $out['display_name']      = (string) $reg['display_name'];
        $out['needs']             = [];
        $out['reg_status']        = (string) $reg['status'];
        $out['waitlist_position'] = se_waitlist_position($pdo, $reg);
        $out['device_owns']       = se_device_owns($device, $reg);
    }

    return $out;
}

/** One registration by (event, contact), whatever its status. */
function se_registration_find(PDO $pdo, int $eventId, int $contactId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE event_id = ? AND contact_id = ? LIMIT 1");
    $stmt->execute([$eventId, $contactId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** The same row, locked for update. The caller is inside the event lock. */
function se_registration_find_for_update(PDO $pdo, int $eventId, int $contactId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM se_registrations WHERE event_id = ? AND contact_id = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$eventId, $contactId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function se_registration_by_id(PDO $pdo, int $id, ?int $eventId = null): ?array
{
    $sql    = "SELECT * FROM se_registrations WHERE id = ?";
    $params = [$id];
    if ($eventId !== null) {
        $sql     .= ' AND event_id = ?';
        $params[] = $eventId;
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** The registration behind a ref_code, for `?r=` attribution. */
function se_registration_by_ref(PDO $pdo, int $eventId, string $refCode): ?array
{
    $refCode = strtoupper(trim($refCode));
    if (!preg_match('/^[0-9A-Z]{' . SE_REF_CODE_LENGTH . '}$/', $refCode)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE event_id = ? AND ref_code = ? LIMIT 1");
    $stmt->execute([$eventId, $refCode]);
    $row = $stmt->fetch();

    return $row ?: null;
}

// --------------------------------------------------------------------------
// Custom questions (§8.5 se_form_fields)
// --------------------------------------------------------------------------

/** Active custom questions for one audience. */
function se_form_fields(PDO $pdo, int $eventId, ?bool $isMember = null): array
{
    if (!se_table_exists($pdo, 'se_form_fields')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM se_form_fields WHERE event_id = ? AND is_active = 1 ORDER BY sort_order, id"
    );
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll() ?: [];

    $out = [];
    foreach ($rows as $row) {
        $audience = (string) $row['audience'];
        if ($isMember !== null && $audience !== 'everyone') {
            if ($audience === 'members' && !$isMember) { continue; }
            if ($audience === 'guests'  && $isMember)  { continue; }
        }
        $row['options'] = se_json_decode($row['options_json'] ?? null);
        unset($row['options_json']);
        $out[] = $row;
    }

    return $out;
}

/** The public shape of a question, for the registration sheet. */
function se_form_field_public(array $field): array
{
    return [
        'key'         => (string) $field['field_key'],
        'label'       => (string) $field['label'],
        'type'        => (string) $field['type'],
        'options'     => array_values((array) ($field['options'] ?? [])),
        'required'    => se_bool($field['is_required']),
        'placeholder' => $field['placeholder'] !== null ? (string) $field['placeholder'] : null,
        'help'        => $field['help_text'] !== null ? (string) $field['help_text'] : null,
        'audience'    => (string) $field['audience'],
    ];
}

/**
 * Validate the answers to the custom questions.
 *
 * @throws SeValidationException with one message per offending field
 * @return array<string,mixed> the answers worth storing
 */
function se_answers_validate(array $fields, mixed $answers): array
{
    $answers = is_array($answers) ? $answers : [];
    $errors  = [];
    $out     = [];

    foreach ($fields as $field) {
        $key   = (string) $field['field_key'];
        $type  = (string) $field['type'];
        $req   = se_bool($field['is_required']);
        $opts  = array_values((array) ($field['options'] ?? []));
        $raw   = $answers[$key] ?? null;
        $label = (string) $field['label'];

        switch ($type) {
            case 'checkbox':
                $value = se_bool($raw);
                if ($req && !$value) {
                    $errors['answers.' . $key] = $label . ' is required.';
                }
                $out[$key] = $value;
                break;

            case 'number':
                if ($raw === null || $raw === '') {
                    if ($req) { $errors['answers.' . $key] = $label . ' is required.'; }
                    break;
                }
                if (!is_numeric($raw)) {
                    $errors['answers.' . $key] = $label . ' must be a number.';
                    break;
                }
                $out[$key] = 0 + $raw;
                break;

            case 'select':
                $value = se_line($raw, 160);
                if ($value === '') {
                    if ($req) { $errors['answers.' . $key] = $label . ' is required.'; }
                    break;
                }
                if ($opts && !in_array($value, $opts, true)) {
                    $errors['answers.' . $key] = 'Choose one of the listed options.';
                    break;
                }
                $out[$key] = $value;
                break;

            case 'multiselect':
                $picked = [];
                foreach ((array) ($raw ?? []) as $one) {
                    $one = se_line($one, 160);
                    if ($one !== '' && (!$opts || in_array($one, $opts, true))) {
                        $picked[] = $one;
                    }
                }
                $picked = array_values(array_unique($picked));
                if ($req && !$picked) {
                    $errors['answers.' . $key] = $label . ' is required.';
                }
                if ($picked) { $out[$key] = $picked; }
                break;

            case 'textarea':
                $value = se_str($raw, 1000);
                if ($value === '') {
                    if ($req) { $errors['answers.' . $key] = $label . ' is required.'; }
                    break;
                }
                $out[$key] = $value;
                break;

            default: // text
                $value = se_line($raw, 255);
                if ($value === '') {
                    if ($req) { $errors['answers.' . $key] = $label . ' is required.'; }
                    break;
                }
                $out[$key] = $value;
                break;
        }
    }

    if ($errors) {
        throw new SeValidationException($errors);
    }

    return $out;
}

// --------------------------------------------------------------------------
// Codes
// --------------------------------------------------------------------------

/**
 * Insert with a freshly generated reg_code/ref_code, retrying on the unique
 * keys rather than checking first (§9.3).
 */
function se_insert_registration(PDO $pdo, array $event, array $contact, string $status, ?string $pool, array $in, array $ctx): array
{
    $eventId = (int) $event['id'];
    $now     = se_sql_datetime($ctx['now'] ?? se_now());

    $sql = "INSERT INTO se_registrations
              (event_id, contact_id, reg_code, ref_code, status, seat_pool, channel, is_member, is_test,
               first_name, last_name, gender, email, display_name, name_correction,
               how_heard, how_heard_other, src, referred_by_registration_id,
               karaoke_interest, answers_json, confirmed_at, waitlisted_at, ip_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $regCode = se_random_code(SE_REG_CODE_LENGTH);
        $refCode = se_random_code(SE_REF_CODE_LENGTH);

        try {
            $stmt->execute([
                $eventId,
                (int) $contact['id'],
                $regCode,
                $refCode,
                $status,
                $pool,
                (string) ($in['channel'] ?? 'portal'),
                !empty($in['is_member']) ? 1 : 0,
                !empty($in['is_test']) ? 1 : 0,
                (string) $in['first_name'],
                (string) ($in['last_name'] ?? ''),
                $in['gender'] ?? null,
                $in['email'] ?? null,
                (string) $in['display_name'],
                $in['name_correction'] ?? null,
                $in['how_heard'] ?? null,
                $in['how_heard_other'] ?? null,
                $in['src'] ?? null,
                $in['referred_by_registration_id'] ?? null,
                !empty($in['karaoke_interest']) ? 1 : 0,
                $in['answers'] ? se_json_encode($in['answers']) : null,
                $status === 'confirmed' ? $now : null,
                $status === 'waitlisted' ? $now : null,
                $ctx['ip_hash'] ?? null,
            ]);

            $row = se_registration_by_id($pdo, (int) $pdo->lastInsertId());
            if ($row) {
                return $row;
            }
        } catch (PDOException $e) {
            if (!se_is_duplicate_key($e)) {
                throw $e;
            }
            // Duplicate (event, contact) means a parallel request won the race:
            // hand back their row, which is exactly the "already" outcome.
            $existing = se_registration_find_for_update($pdo, $eventId, (int) $contact['id']);
            if ($existing) {
                return $existing;
            }
            // Otherwise it was a code collision — try again with new codes.
        }
    }

    throw new RuntimeException('se_insert_registration: could not allocate a unique registration code');
}

/** True when a PDOException is a UNIQUE/PRIMARY violation. */
function se_is_duplicate_key(PDOException $e): bool
{
    return $e->getCode() === '23000' || ((int) ($e->errorInfo[1] ?? 0)) === 1062;
}

/** Bring a cancelled registration back to life, keeping its codes. */
function se_reactivate_registration(PDO $pdo, array $reg, string $status, ?string $pool, array $in): array
{
    $now = se_sql_datetime(se_now());

    $stmt = $pdo->prepare(
        "UPDATE se_registrations
            SET status = ?, seat_pool = ?, cancelled_at = NULL, cancelled_by = NULL, cancel_reason = NULL,
                first_name = ?, last_name = ?, gender = COALESCE(?, gender), email = COALESCE(?, email),
                display_name = ?, is_member = ?, how_heard = COALESCE(?, how_heard),
                how_heard_other = COALESCE(?, how_heard_other), src = COALESCE(?, src),
                karaoke_interest = ?, answers_json = COALESCE(?, answers_json),
                confirmed_at = CASE WHEN ? = 'confirmed' THEN ? ELSE confirmed_at END,
                waitlisted_at = CASE WHEN ? = 'waitlisted' THEN ? ELSE waitlisted_at END
          WHERE id = ?"
    );
    $stmt->execute([
        $status,
        $pool,
        (string) $in['first_name'],
        (string) ($in['last_name'] ?? ''),
        $in['gender'] ?? null,
        $in['email'] ?? null,
        (string) $in['display_name'],
        !empty($in['is_member']) ? 1 : 0,
        $in['how_heard'] ?? null,
        $in['how_heard_other'] ?? null,
        $in['src'] ?? null,
        !empty($in['karaoke_interest']) ? 1 : 0,
        $in['answers'] ? se_json_encode($in['answers']) : null,
        $status, $now,
        $status, $now,
        (int) $reg['id'],
    ]);

    return se_registration_by_id($pdo, (int) $reg['id']) ?? $reg;
}

// --------------------------------------------------------------------------
// Register (§10.4.3)
// --------------------------------------------------------------------------

/**
 * Take one registration, atomically.
 *
 * $in is already validated and normalised by the caller: phone (the
 * se_phone_normalize array), first_name, last_name, gender, email, consent,
 * how_heard, how_heard_other, karaoke_interest, answers, src, ref, channel.
 *
 * $ctx carries now, device (row or null), device_cookie, ip_hash, actor.
 *
 * @return array{outcome:string, ...}
 */
function se_register(PDO $pdo, array $event, array $settings, array $days, array $in, array $ctx): array
{
    $now     = $ctx['now'] ?? se_now();
    $eventId = (int) $event['id'];

    $result = se_lock_event($pdo, $eventId, function (array $ev) use ($pdo, $settings, $days, $in, $ctx, $now): array {
        $counts = se_capacity_counts($pdo, (int) $ev['id']);
        $phase  = se_event_phase($ev, $days, $now);
        $state  = se_registration_state($ev, $phase, $counts, $now);

        // `ignore_state` is the desk adding someone by hand: registration
        // may well be closed, which is exactly why they are at the desk
        // (§10.4.5). It is never set from the public API.
        if (!se_registration_state_accepts($state) && empty($in['ignore_state'])) {
            return ['outcome' => 'closed', 'state' => $state, 'event' => $ev];
        }

        $contact = se_contact_get_or_create($pdo, $in['phone'], $in);
        $isMember = $contact['member_user_id'] !== null;

        $reg = se_registration_find_for_update($pdo, (int) $ev['id'], (int) $contact['id']);

        if ($reg && in_array((string) $reg['status'], ['confirmed', 'waitlisted'], true)) {
            return [
                'outcome'      => 'already',
                'registration' => $reg,
                'contact'      => $contact,
                'event'        => $ev,
                'device_owns'  => se_device_owns($ctx['device'] ?? null, $reg),
            ];
        }
        if ($reg && (string) $reg['status'] === 'removed') {
            // The crew took them off the list. The portal must not quietly
            // put them back on it.
            return ['outcome' => 'blocked', 'event' => $ev];
        }

        // A hand-added person takes a seat if there is one, and a waitlist
        // place if there is not — the same decision the portal would make.
        $takesSeat = $state === 'open'
            || (!empty($in['ignore_state']) && (($free = se_online_free($ev, $counts)) === null || $free > 0));

        $status = $takesSeat ? 'confirmed' : 'waitlisted';
        $pool   = $takesSeat ? 'online' : null;

        $row = [
            'first_name'      => (string) $in['first_name'],
            'last_name'       => (string) ($in['last_name'] ?? ''),
            'gender'          => $in['gender'] ?? null,
            'email'           => $in['email'] ?? null,
            'display_name'    => se_display_name((string) $in['first_name'], (string) ($in['last_name'] ?? '')),
            'name_correction' => $in['name_correction'] ?? null,
            'how_heard'       => $in['how_heard'] ?? null,
            'how_heard_other' => $in['how_heard_other'] ?? null,
            'src'             => $in['src'] ?? null,
            'referred_by_registration_id' => $in['referred_by_registration_id'] ?? null,
            'karaoke_interest' => !empty($in['karaoke_interest']),
            'answers'         => $in['answers'] ?? [],
            'is_member'       => $isMember,
            'is_test'         => !empty($in['is_test']),
            'channel'         => $in['channel'] ?? 'portal',
        ];

        $reg = $reg
            ? se_reactivate_registration($pdo, $reg, $status, $pool, $row)
            : se_insert_registration($pdo, $ev, $contact, $status, $pool, $row, $ctx);

        // The insert can come back as "someone else won the race" — in that
        // case the row already holds a live status and this is an `already`.
        if ((string) $reg['status'] !== $status
            && in_array((string) $reg['status'], ['confirmed', 'waitlisted'], true)) {
            return [
                'outcome'      => 'already',
                'registration' => $reg,
                'contact'      => $contact,
                'event'        => $ev,
                'device_owns'  => se_device_owns($ctx['device'] ?? null, $reg),
            ];
        }

        se_contact_set_consent($pdo, (int) $contact['id'], !empty($in['consent']), (string) ($in['consent_text'] ?? ''));

        $bound = se_device_bind_if_unbound($pdo, $ctx['device'] ?? null, (int) $reg['id']);

        se_audit(
            $pdo,
            (int) $ev['id'],
            'register',
            ['registration_id' => (int) $reg['id'], 'status' => $status, 'src' => $row['src'], 'channel' => $row['channel']],
            'registration',
            (int) $reg['id'],
            $ctx['actor_user_id'] ?? null,
            (int) $reg['id']
        );

        return [
            'outcome'      => $status,
            'registration' => $reg,
            'contact'      => $contact,
            'event'        => $ev,
            'device_owns'  => $bound || se_device_owns($ctx['device'] ?? null, $reg),
            'bound'        => $bound,
        ];
    });

    if (in_array($result['outcome'], ['confirmed', 'waitlisted'], true)) {
        se_after_capacity_change($pdo, $result['event']);
    }

    return $result;
}

// --------------------------------------------------------------------------
// Cancel (§10.4.4)
// --------------------------------------------------------------------------

/**
 * Cancel a registration and, if an online seat came free, promote the
 * waitlist in the same transaction.
 *
 * @param string $actor 'self' | 'crew' | 'system'
 * @return array{status:string, promoted:list<int>}
 */
function se_registration_cancel(PDO $pdo, array $event, array $days, array $registration, string $actor, ?string $reason = null, ?DateTimeImmutable $now = null): array
{
    $now     ??= se_now();
    $eventId   = (int) $event['id'];

    $result = se_lock_event($pdo, $eventId, function (array $ev) use ($pdo, $days, $registration, $actor, $reason, $now): array {
        $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ? FOR UPDATE");
        $stmt->execute([(int) $registration['id']]);
        $reg = $stmt->fetch();

        if (!$reg) {
            throw new SeNotFoundException('That registration no longer exists.');
        }
        if (!in_array((string) $reg['status'], ['confirmed', 'waitlisted'], true)) {
            return ['status' => 'noop', 'promoted' => [], 'event' => $ev, 'registration' => $reg];
        }

        if ($actor === 'self') {
            if (!se_bool($ev['self_cancel_enabled'] ?? 0)) {
                throw new SeRuleException('SELF_CANCEL_DISABLED',
                    'Plans changed? Please let us know at the desk — this event does not take cancellations online.');
            }
            $phase = se_event_phase($ev, $days, $now);
            if (($phase['phase'] ?? '') !== 'upcoming') {
                throw new SeRuleException('TOO_LATE',
                    'It is too late to cancel online. Please see the desk when you arrive.');
            }
        }

        $wasOnline = (string) $reg['status'] === 'confirmed' && (string) $reg['seat_pool'] === 'online';

        $pdo->prepare(
            "UPDATE se_registrations
                SET status = 'cancelled', seat_pool = NULL, cancelled_at = NOW(),
                    cancelled_by = ?, cancel_reason = ?
              WHERE id = ?"
        )->execute([$actor, se_line($reason, 160) ?: null, (int) $reg['id']]);

        if (se_table_exists($pdo, 'se_karaoke_entries')) {
            $pdo->prepare(
                "UPDATE se_karaoke_entries SET status = 'cancelled'
                  WHERE registration_id = ? AND status IN ('held','queued','up_next')"
            )->execute([(int) $reg['id']]);
        }

        $promoted = $wasOnline ? se_promote_waitlist($pdo, $ev, 1) : [];

        se_audit(
            $pdo,
            (int) $ev['id'],
            'cancel',
            ['registration_id' => (int) $reg['id'], 'by' => $actor, 'promoted' => $promoted],
            'registration',
            (int) $reg['id'],
            $actor === 'self' ? null : (((int) ($_SESSION['user_id'] ?? 0)) ?: null),
            $actor === 'self' ? (int) $reg['id'] : null
        );

        return ['status' => 'cancelled', 'promoted' => $promoted, 'event' => $ev, 'registration' => $reg];
    });

    if ($result['status'] === 'cancelled') {
        se_after_capacity_change($pdo, $result['event']);

        if ($result['promoted'] && se_bool($result['event']['waitlist_notify_sms'] ?? 1)) {
            se_messages_enqueue_waitlist_promotion($pdo, $result['event'], $days, $result['promoted']);
        }
    }

    return $result;
}

// --------------------------------------------------------------------------
// Small setters
// --------------------------------------------------------------------------

/** "Would you like a pastor to visit?" — asked after submit (§20.2). */
function se_wants_visit_set(PDO $pdo, array $registration, bool $value): void
{
    $pdo->prepare("UPDATE se_registrations SET wants_visit = ? WHERE id = ?")
        ->execute([$value ? 1 : 0, (int) $registration['id']]);
}

/**
 * Opt out of all future contact. This is about the CONTACT, not the
 * registration — they keep their seat, we simply stop writing to them.
 */
function se_contact_optout(PDO $pdo, array $event, array $registration): void
{
    $pdo->prepare(
        "UPDATE se_contacts SET opted_out_at = NOW(), consent_followup = 0 WHERE id = ?"
    )->execute([(int) $registration['contact_id']]);

    se_audit(
        $pdo,
        (int) $event['id'],
        'optout',
        ['registration_id' => (int) $registration['id']],
        'contact',
        (int) $registration['contact_id'],
        null,
        (int) $registration['id']
    );
}

// --------------------------------------------------------------------------
// The `me` payload (§12.2.1)
// --------------------------------------------------------------------------

/**
 * Everything this device may know about its own registration.
 *
 * PR2 fills the registration, links and wants_visit parts; PR3 adds the
 * team; karaoke, round, score and alerts arrive with PR4–PR5 and are present
 * as nulls so the client shape never changes.
 */
function se_me_payload(PDO $pdo, array $event, array $days, array $registration, ?array $device = null, ?string $manageToken = null): array
{
    $checkedInToday = false;
    if (se_table_exists($pdo, 'se_checkins')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM se_checkins WHERE registration_id = ? AND DATE(checked_in_at) = CURDATE() LIMIT 1"
            );
            $stmt->execute([(int) $registration['id']]);
            $checkedInToday = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('SE registration/me checkin: ' . $e->getMessage());
        }
    }

    $team = !empty($registration['team_id']) && se_teams_ready($pdo)
        ? se_team_find($pdo, (int) $event['id'], (int) $registration['team_id'])
        : null;

    $links = [
        'ref_url' => se_event_url((string) $event['slug']) . '?r=' . rawurlencode((string) $registration['ref_code']),
    ];
    if ($manageToken !== null) {
        $links['manage_url'] = se_event_url((string) $event['slug'], 'me/' . $manageToken);
    }

    $gameMe = function_exists('se_game_me_payload') && se_game_ready($pdo)
        ? se_game_me_payload($pdo, $event, $registration, $device)
        : ['games' => ['joined' => $device !== null && ($device['joined_games_at'] ?? null) !== null], 'round' => null, 'presenter' => null, 'score' => null];

    return [
        'registration' => [
            'status'            => (string) $registration['status'],
            'seat_pool'         => $registration['seat_pool'] !== null ? (string) $registration['seat_pool'] : null,
            'display_name'      => (string) $registration['display_name'],
            'first_name'        => (string) $registration['first_name'],
            'reg_code'          => (string) $registration['reg_code'],
            'waitlist_position' => se_waitlist_position($pdo, $registration),
            'checked_in_today'  => $checkedInToday,
            'player_no'         => $registration['player_no'] !== null ? (int) $registration['player_no'] : null,
            'is_member'         => se_bool($registration['is_member']),
            'karaoke_interest'  => se_bool($registration['karaoke_interest']),
            'wants_visit'       => se_bool($registration['wants_visit']),
            'device_mode'       => (string) ($device['mode'] ?? 'full'),
            'created_at'        => se_iso($registration['created_at'] ?? null),
        ],
        // PR4 fills karaoke; round, presenter and score arrive with PR5.
        'team'      => $team !== null ? se_team_public($team, se_event_theme($event)) : null,
        'karaoke'   => se_karaoke_ready($pdo) ? se_karaoke_me($pdo, $event, (int) $registration['id']) : null,
        'games'     => $gameMe['games'],
        'round'     => $gameMe['round'],
        'presenter' => $gameMe['presenter'],
        'score'     => $gameMe['score'],
        'alerts'    => se_me_alerts($pdo, $event, $registration),
        'links'     => $links,
        'can'       => [
            // What the manage page is allowed to offer. Deciding it here
            // keeps the rule in one place: the client only draws buttons for
            // the things se_registration_cancel() would actually permit.
            'cancel'       => se_can_self_cancel($event, $days, $registration),
            'make_card'    => se_bool(se_event_settings($event)['share_cards']['im_going'] ?? true)
                && in_array((string) $registration['status'], ['confirmed', 'waitlisted'], true),
            'request_link' => se_bool(se_event_settings($event)['registration']['link_on_demand_enabled'] ?? true),
        ],
    ];
}

/**
 * The nudges a phone should show (§12.2.1).
 *
 * Today there is one: "you are up next", which is the whole reason the
 * singer keeps the page open. It carries the moment the DJ pressed the
 * button, so the client can decide whether it has already buzzed for it.
 */
function se_me_alerts(PDO $pdo, array $event, array $registration): array
{
    $alerts = [];

    if (se_karaoke_ready($pdo)) {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, status, on_stage_at, queued_at FROM se_karaoke_entries
                  WHERE event_id = ? AND registration_id = ? AND status IN ('up_next','on_stage')
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([(int) $event['id'], (int) $registration['id']]);
            $entry = $stmt->fetch();

            if ($entry) {
                $when = se_parse_datetime($entry['on_stage_at'] ?? null) ?? se_now();
                $alerts[] = [
                    'type'  => (string) $entry['status'] === 'on_stage' ? 'karaoke_on_stage' : 'karaoke_up_next',
                    'at_ms' => se_epoch_ms($when),
                ];
            }
        } catch (Throwable $e) {
            error_log('SE registration/alerts: ' . $e->getMessage());
        }
    }

    return $alerts;
}

/**
 * Can this person release their own seat right now? (§10.4.7)
 *
 * Mirrors the rules se_registration_cancel() enforces under the lock, so the
 * button is only ever shown when pressing it would work.
 */
function se_can_self_cancel(array $event, array $days, array $registration, ?DateTimeImmutable $now = null): bool
{
    if (!se_bool($event['self_cancel_enabled'] ?? 0)) {
        return false;
    }
    if (!in_array((string) $registration['status'], ['confirmed', 'waitlisted'], true)) {
        return false;
    }

    $phase = se_event_phase($event, $days, $now ?? se_now());

    return ($phase['phase'] ?? '') === 'upcoming';
}
