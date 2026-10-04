<?php
// /includes/special_events/attendees.php
//
// Studio → Attendees (guide §12.5, §13.13).
//
// This is the crew's view of the registration list: search, correct, cancel,
// restore, promote, remove, add at the desk, reset links, and erase on a
// subject request (§19.9). The capacity rules all live in capacity.php, so
// anything that frees or takes a seat goes through se_lock_event() there.
//
// Personal data: a phone number is only ever returned to a caller holding
// `attendee.pii`; everyone else sees it masked (§19.1).

/** Columns a crew correction may touch (§12.5 `attendee_update`). */
const SE_ATTENDEE_EDITABLE = [
    'first_name', 'last_name', 'gender', 'email',
    'how_heard', 'how_heard_other', 'karaoke_interest', 'wants_visit',
];

/**
 * One page of attendees.
 *
 * @param array $filters status|pool|channel|member|karaoke|wants_visit|src|test
 * @return array{items: list<array>, page:int, pages:int, total:int}
 */
function se_attendees_list(PDO $pdo, int $eventId, array $filters, string $q, int $page, int $perPage, bool $withPii): array
{
    if (!se_table_exists($pdo, 'se_registrations')) {
        return ['items' => [], 'page' => 1, 'pages' => 0, 'total' => 0];
    }

    $perPage = max(1, min(100, $perPage));
    $page    = max(1, $page);

    $where  = ['r.event_id = ?'];
    $params = [$eventId];

    $status = se_str($filters['status'] ?? '', 20);
    if (in_array($status, ['confirmed', 'waitlisted', 'cancelled', 'removed'], true)) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    } elseif ($status !== 'all') {
        // The useful default: the people who still hold a place.
        $where[] = "r.status IN ('confirmed','waitlisted')";
    }

    $pool = se_str($filters['pool'] ?? '', 10);
    if (in_array($pool, ['online', 'walkin'], true)) {
        $where[] = 'r.seat_pool = ?';
        $params[] = $pool;
    }

    $channel = se_str($filters['channel'] ?? '', 20);
    if (in_array($channel, ['portal', 'walkin_self', 'walkin_desk', 'studio'], true)) {
        $where[] = 'r.channel = ?';
        $params[] = $channel;
    }

    foreach ([
        'member'      => 'r.is_member',
        'karaoke'     => 'r.karaoke_interest',
        'wants_visit' => 'r.wants_visit',
        'test'        => 'r.is_test',
    ] as $key => $column) {
        if (!isset($filters[$key]) || $filters[$key] === '' || $filters[$key] === null) {
            continue;
        }
        $where[] = $column . ' = ?';
        $params[] = se_bool($filters[$key]) ? 1 : 0;
    }

    $src = se_str($filters['src'] ?? '', 20);
    if ($src !== '') {
        $where[] = 'r.src = ?';
        $params[] = strtolower($src);
    }

    // Search: a name, a reg code, or a phone number in any written form.
    $q = trim($q);
    if ($q !== '') {
        $like  = '%' . $q . '%';
        $parts = ['r.first_name LIKE ?', 'r.last_name LIKE ?', 'r.display_name LIKE ?', 'r.reg_code = ?'];
        $args  = [$like, $like, $like, strtoupper($q)];

        $phone = se_phone_normalize($q);
        if ($phone !== null) {
            $parts[] = 'c.phone_e164 = ?';
            $args[]  = $phone['e164'];
        }

        $where[] = '(' . implode(' OR ', $parts) . ')';
        $params  = array_merge($params, $args);
    }

    $sql = ' FROM se_registrations r LEFT JOIN se_contacts c ON c.id = r.contact_id WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare('SELECT COUNT(*)' . $sql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT r.*, c.phone_e164, c.phone_display, c.sms_capable, c.email AS contact_email,
                c.consent_followup, c.opted_out_at, c.erased_at'
        . $sql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $stmt->execute($params);

    $items = array_map(
        static fn(array $row): array => se_attendee_payload($pdo, $row, $withPii),
        $stmt->fetchAll() ?: []
    );

    return [
        'items' => $items,
        'page'  => $page,
        'pages' => (int) ceil($total / $perPage),
        'total' => $total,
    ];
}

/** The Studio's view of one registration. */
function se_attendee_payload(PDO $pdo, array $row, bool $withPii): array
{
    $phone = (string) ($row['phone_e164'] ?? '');

    return [
        'id'               => (int) $row['id'],
        'reg_code'         => (string) $row['reg_code'],
        'ref_code'         => (string) $row['ref_code'],
        'display_name'     => (string) $row['display_name'],
        'first_name'       => (string) $row['first_name'],
        'last_name'        => (string) $row['last_name'],
        'phone'            => $phone === '' ? null : ($withPii ? se_phone_display($phone) : se_mask_phone($phone)),
        'phone_e164'       => $withPii && $phone !== '' ? $phone : null,
        'sms_capable'      => se_bool($row['sms_capable'] ?? 1),
        'email'            => $withPii ? ($row['email'] ?? $row['contact_email'] ?? null) : se_mask_email((string) ($row['email'] ?? $row['contact_email'] ?? '')),
        'gender'           => $row['gender'] !== null ? (string) $row['gender'] : null,
        'is_member'        => se_bool($row['is_member']),
        'status'           => (string) $row['status'],
        'seat_pool'        => $row['seat_pool'] !== null ? (string) $row['seat_pool'] : null,
        'channel'          => (string) $row['channel'],
        'src'              => $row['src'] !== null ? (string) $row['src'] : null,
        'how_heard'        => $row['how_heard'] !== null ? (string) $row['how_heard'] : null,
        'how_heard_other'  => $row['how_heard_other'] !== null ? (string) $row['how_heard_other'] : null,
        'karaoke_interest' => se_bool($row['karaoke_interest']),
        'wants_visit'      => se_bool($row['wants_visit']),
        'consent'          => se_bool($row['consent_followup'] ?? 0),
        'opted_out'        => ($row['opted_out_at'] ?? null) !== null,
        'erased'           => ($row['erased_at'] ?? null) !== null,
        'is_test'          => se_bool($row['is_test']),
        'name_correction'  => $row['name_correction'] !== null ? (string) $row['name_correction'] : null,
        'answers'          => se_json_decode($row['answers_json'] ?? null) ?? [],
        'team_id'          => $row['team_id'] !== null ? (int) $row['team_id'] : null,
        'player_no'        => $row['player_no'] !== null ? (int) $row['player_no'] : null,
        'created_at'       => se_iso($row['created_at'] ?? null),
        'confirmed_at'     => se_iso($row['confirmed_at'] ?? null),
        'waitlisted_at'    => se_iso($row['waitlisted_at'] ?? null),
        'cancelled_at'     => se_iso($row['cancelled_at'] ?? null),
        'cancel_reason'    => $row['cancel_reason'] !== null ? (string) $row['cancel_reason'] : null,
        'first_checkin_at' => se_iso($row['first_checkin_at'] ?? null),
    ];
}

/** One attendee by id, scoped to the event so ids cannot be fished across. */
function se_attendee_get(PDO $pdo, int $eventId, int $id, bool $withPii): ?array
{
    if (!se_table_exists($pdo, 'se_registrations')) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT r.*, c.phone_e164, c.phone_display, c.sms_capable, c.email AS contact_email,
                c.consent_followup, c.opted_out_at, c.erased_at
           FROM se_registrations r
           LEFT JOIN se_contacts c ON c.id = r.contact_id
          WHERE r.id = ? AND r.event_id = ? LIMIT 1"
    );
    $stmt->execute([$id, $eventId]);
    $row = $stmt->fetch();

    return $row ? se_attendee_payload($pdo, $row, $withPii) : null;
}

/**
 * A crew correction (§12.5 `attendee_update`).
 *
 * Names and gender are written to BOTH the registration (the record of the
 * night) and the contact (what the next event will pre-fill), because a
 * desk correction is almost always a correction of the person, not a typo
 * unique to this event.
 */
function se_attendee_update(PDO $pdo, array $event, int $id, array $fields, int $actorId): array
{
    $eventId = (int) $event['id'];

    $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->execute([$id, $eventId]);
    $reg = $stmt->fetch();
    if (!$reg) {
        throw new SeNotFoundException('That person is not on this list.');
    }

    $set    = [];
    $params = [];
    $errors = [];
    $changed = [];

    foreach (SE_ATTENDEE_EDITABLE as $key) {
        if (!array_key_exists($key, $fields)) {
            continue;
        }

        switch ($key) {
            case 'first_name':
            case 'last_name':
                $value = se_name_case(se_clean_name($fields[$key]));
                if ($key === 'first_name' && $value === '') {
                    $errors['first_name'] = 'A first name is required.';
                    continue 2;
                }
                break;

            case 'gender':
                $value = se_normalize_gender($fields[$key]);
                break;

            case 'email':
                $value = se_contact_clean_email($fields[$key]);
                if ($value === null && trim((string) $fields[$key]) !== '') {
                    $errors['email'] = 'That email address does not look right.';
                    continue 2;
                }
                break;

            case 'how_heard':
                $value = se_str($fields[$key], 30);
                if ($value !== '' && !isset(SE_HOW_HEARD[$value])) {
                    $errors['how_heard'] = 'Unknown source.';
                    continue 2;
                }
                $value = $value !== '' ? $value : null;
                break;

            case 'how_heard_other':
                $value = se_line($fields[$key], 80) ?: null;
                break;

            default:
                $value = se_bool($fields[$key]) ? 1 : 0;
        }

        $set[] = "{$key} = ?";
        $params[] = $value;
        $changed[] = $key;
    }

    if ($errors) {
        throw new SeValidationException($errors);
    }
    if (!$set) {
        return se_attendee_get($pdo, $eventId, $id, true) ?? [];
    }

    $first = array_key_exists('first_name', $fields) ? se_name_case(se_clean_name($fields['first_name'])) : (string) $reg['first_name'];
    $last  = array_key_exists('last_name', $fields) ? se_name_case(se_clean_name($fields['last_name'])) : (string) $reg['last_name'];

    $set[] = 'display_name = ?';
    $params[] = se_display_name($first, $last);

    // A crew correction IS the answer to "is this name right?", so the
    // pending correction note is cleared along with it.
    if (array_key_exists('first_name', $fields) || array_key_exists('last_name', $fields)) {
        $set[] = 'name_correction = NULL';
    }

    $params[] = $id;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE se_registrations SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        if ($reg['contact_id'] !== null) {
            $contactSet = [];
            $contactParams = [];
            if (array_key_exists('first_name', $fields)) { $contactSet[] = 'first_name = ?'; $contactParams[] = $first; }
            if (array_key_exists('last_name', $fields))  { $contactSet[] = 'last_name = ?';  $contactParams[] = $last; }
            if (array_key_exists('gender', $fields))     { $contactSet[] = 'gender = ?';     $contactParams[] = se_normalize_gender($fields['gender']); }
            if (array_key_exists('email', $fields))      { $contactSet[] = 'email = ?';      $contactParams[] = se_contact_clean_email($fields['email']); }

            if ($contactSet) {
                $contactParams[] = (int) $reg['contact_id'];
                $pdo->prepare('UPDATE se_contacts SET ' . implode(', ', $contactSet) . ' WHERE id = ?')->execute($contactParams);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    se_audit($pdo, $eventId, 'attendee_update', ['registration_id' => $id, 'fields' => $changed],
        'registration', $id, $actorId);

    return se_attendee_get($pdo, $eventId, $id, true) ?? [];
}

/**
 * Put a cancelled or removed person back on the list.
 *
 * It is a fresh seat request, not a rewind: the event may have filled up
 * since, so the capacity engine decides between confirmed and waitlisted.
 */
function se_attendee_restore(PDO $pdo, array $event, array $days, int $id, int $actorId): array
{
    $eventId = (int) $event['id'];

    $result = se_lock_event($pdo, $eventId, function (array $ev) use ($pdo, $days, $id, $actorId): array {
        $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ? AND event_id = ? FOR UPDATE");
        $stmt->execute([$id, (int) $ev['id']]);
        $reg = $stmt->fetch();
        if (!$reg) {
            throw new SeNotFoundException('That person is not on this list.');
        }
        if (in_array((string) $reg['status'], ['confirmed', 'waitlisted'], true)) {
            return ['status' => (string) $reg['status'], 'event' => $ev];
        }

        $counts = se_capacity_counts($pdo, (int) $ev['id']);
        $free   = se_online_free($ev, $counts);
        $status = ($free === null || $free > 0) ? 'confirmed' : 'waitlisted';

        $pdo->prepare(
            "UPDATE se_registrations
                SET status = ?, seat_pool = ?, cancelled_at = NULL, cancelled_by = NULL,
                    cancel_reason = NULL,
                    confirmed_at = IF(? = 'confirmed', NOW(), confirmed_at),
                    waitlisted_at = IF(? = 'waitlisted', NOW(), waitlisted_at)
              WHERE id = ?"
        )->execute([$status, $status === 'confirmed' ? 'online' : null, $status, $status, $id]);

        se_audit($pdo, (int) $ev['id'], 'attendee_restore',
            ['registration_id' => $id, 'status' => $status], 'registration', $id, $actorId);

        return ['status' => $status, 'event' => $ev];
    });

    se_after_capacity_change($pdo, $result['event']);

    return $result;
}

/**
 * Promote one waitlisted person out of order (§10.4.5 manual override).
 *
 * Crew sometimes need this — a volunteer, a speaker's guest — so it is
 * allowed, audited, and it does not pretend the queue said so.
 */
function se_attendee_promote(PDO $pdo, array $event, array $days, int $id, int $actorId): array
{
    $eventId = (int) $event['id'];

    $result = se_lock_event($pdo, $eventId, function (array $ev) use ($pdo, $id, $actorId): array {
        $promoted = se_promote_registration($pdo, $ev, $id);

        if ($promoted) {
            se_audit($pdo, (int) $ev['id'], 'promote', ['registration_id' => $id, 'by' => 'crew'],
                'registration', $id, $actorId);
        }

        return ['promoted' => $promoted, 'event' => $ev];
    });

    if ($result['promoted']) {
        se_after_capacity_change($pdo, $result['event']);
        se_messages_enqueue_waitlist_promotion($pdo, $result['event'], $days, [$id]);
    }

    return ['promoted' => $result['promoted']];
}

/**
 * Remove someone from the event (§12.5 `attendee_remove`).
 *
 * Different from a cancellation: `removed` is a crew decision and the person
 * cannot simply register again from the portal (§12.2 `BLOCKED`).
 */
function se_attendee_remove(PDO $pdo, array $event, array $days, int $id, string $reason, int $actorId): array
{
    $eventId = (int) $event['id'];

    $result = se_lock_event($pdo, $eventId, function (array $ev) use ($pdo, $id, $reason, $actorId): array {
        $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ? AND event_id = ? FOR UPDATE");
        $stmt->execute([$id, (int) $ev['id']]);
        $reg = $stmt->fetch();
        if (!$reg) {
            throw new SeNotFoundException('That person is not on this list.');
        }

        $wasOnline = (string) $reg['status'] === 'confirmed' && (string) $reg['seat_pool'] === 'online';

        $pdo->prepare(
            "UPDATE se_registrations
                SET status = 'removed', seat_pool = NULL, cancelled_at = NOW(),
                    cancelled_by = 'crew', cancel_reason = ?
              WHERE id = ?"
        )->execute([se_line($reason, 160) ?: null, $id]);

        se_tokens_revoke($pdo, $id);

        $promoted = $wasOnline ? se_promote_waitlist($pdo, $ev, 1) : [];

        se_audit($pdo, (int) $ev['id'], 'attendee_remove',
            ['registration_id' => $id, 'reason' => se_line($reason, 160), 'promoted' => $promoted],
            'registration', $id, $actorId);

        return ['promoted' => $promoted, 'event' => $ev];
    });

    se_after_capacity_change($pdo, $result['event']);
    if ($result['promoted']) {
        se_messages_enqueue_waitlist_promotion($pdo, $result['event'], $days, $result['promoted']);
    }

    return ['status' => 'removed', 'promoted' => $result['promoted']];
}

/**
 * Invalidate every link this person holds and hand back a fresh one
 * (§12.5 `attendee_reset_links`). Used when a manage link is shared by
 * mistake or a phone is lost.
 */
function se_attendee_reset_links(PDO $pdo, array $event, array $days, int $id, int $actorId): string
{
    $stmt = $pdo->prepare("SELECT id FROM se_registrations WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->execute([$id, (int) $event['id']]);
    if (!$stmt->fetchColumn()) {
        throw new SeNotFoundException('That person is not on this list.');
    }

    se_tokens_revoke($pdo, $id);

    if (se_table_exists($pdo, 'se_devices')) {
        $pdo->prepare("UPDATE se_devices SET revoked_at = NOW(), registration_id = NULL WHERE registration_id = ?")
            ->execute([$id]);
    }

    $url = se_manage_url($pdo, $event, $days, $id);

    se_audit($pdo, (int) $event['id'], 'attendee_reset_links', ['registration_id' => $id],
        'registration', $id, $actorId);

    return $url;
}

/**
 * Erase a person at their request (§19.9).
 *
 * The registration row survives so the counts of the night stay true, but
 * every identifying field is blanked and the contact is tombstoned. This is
 * irreversible by design — there is nothing left to restore from.
 */
function se_attendee_erase(PDO $pdo, array $event, int $id, string $reason, int $actorId): void
{
    $eventId = (int) $event['id'];

    $stmt = $pdo->prepare("SELECT * FROM se_registrations WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->execute([$id, $eventId]);
    $reg = $stmt->fetch();
    if (!$reg) {
        throw new SeNotFoundException('That person is not on this list.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE se_registrations
                SET first_name = 'Erased', last_name = '', display_name = 'Erased',
                    email = NULL, gender = NULL, name_correction = NULL,
                    how_heard_other = NULL, answers_json = NULL, ip_hash = NULL,
                    status = IF(status IN ('confirmed','waitlisted'), 'removed', status),
                    seat_pool = NULL
              WHERE id = ?"
        )->execute([$id]);

        if ($reg['contact_id'] !== null) {
            // The phone is the contact's unique key, so it cannot simply be
            // nulled: it is replaced with an unusable tombstone that keeps
            // the row (and its foreign keys) intact.
            $pdo->prepare(
                "UPDATE se_contacts
                    SET phone_e164 = CONCAT('erased:', id), phone_display = NULL, sms_capable = 0,
                        first_name = 'Erased', last_name = '', email = NULL, gender = NULL,
                        member_user_id = NULL, consent_followup = 0, consent_text_hash = NULL,
                        opted_out_at = NOW(), erased_at = NOW()
                  WHERE id = ?"
            )->execute([(int) $reg['contact_id']]);
        }

        se_tokens_revoke($pdo, $id);

        if (se_table_exists($pdo, 'se_devices')) {
            $pdo->prepare("UPDATE se_devices SET revoked_at = NOW(), registration_id = NULL WHERE registration_id = ?")
                ->execute([$id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // The audit row records that an erasure happened and why — never what
    // was erased.
    se_audit($pdo, $eventId, 'attendee_erase', ['registration_id' => $id, 'reason' => se_line($reason, 160)],
        'registration', $id, $actorId);
}

/**
 * People who look like the same person twice (§12.5 `possible_duplicates`).
 *
 * The phone is unique, so a true duplicate can only happen across two
 * numbers: same name, or the same name with the surname missing.
 */
function se_attendee_duplicates(PDO $pdo, int $eventId): array
{
    if (!se_table_exists($pdo, 'se_registrations')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT LOWER(CONCAT(first_name, ' ', last_name)) AS k,
                COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
           FROM se_registrations
          WHERE event_id = ? AND status IN ('confirmed','waitlisted') AND first_name <> 'Erased'
          GROUP BY k HAVING n > 1 ORDER BY n DESC LIMIT 50"
    );
    $stmt->execute([$eventId]);

    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $out[] = [
            'name'  => ucwords(trim((string) $row['k'])),
            'count' => (int) $row['n'],
            'ids'   => array_map('intval', explode(',', (string) $row['ids'])),
        ];
    }

    return $out;
}

/**
 * Guests whose phone now matches a member record (§12.5 `possible_members`).
 *
 * People join the church between events; this is how last year's "guest"
 * quietly becomes this year's member without anyone retyping anything.
 */
function se_attendee_possible_members(PDO $pdo, int $eventId): array
{
    if (!se_table_exists($pdo, 'se_registrations')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT r.id, r.display_name, c.phone_e164
           FROM se_registrations r
           JOIN se_contacts c ON c.id = r.contact_id
          WHERE r.event_id = ? AND r.is_member = 0 AND c.member_user_id IS NULL
                AND c.erased_at IS NULL AND r.status IN ('confirmed','waitlisted')
          ORDER BY r.id DESC LIMIT 300"
    );
    $stmt->execute([$eventId]);

    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $member = se_find_member($pdo, (string) $row['phone_e164']);
        if (!$member) {
            continue;
        }
        $out[] = [
            'id'           => (int) $row['id'],
            'display_name' => (string) $row['display_name'],
            'member_name'  => trim($member['first_name'] . ' ' . $member['last_name']),
            'user_id'      => (int) $member['id'],
        ];
        if (count($out) >= 50) {
            break;
        }
    }

    return $out;
}
