<?php
// /includes/couples.php
//
// How HOD Lekki names people, and couples in church (owner, 26 Nov 2026).
//
// TITLES. Every name on Charis and Congregation carries the title the church
// uses for that person, the highest one they hold:
//   Pastor  spiritual status Pastor, a Pastor in charge seat (or higher), or
//           a live pastor role (Resident / Associate Pastor ...)
//   Dir.    a Director seat in any department
//   Min.    an HOD or Assistant (Deputy) HOD seat in any department
//   Bro. / Sis.  everyone else, by gender (sub-unit heads included)
//   ''      no gender on file and no leadership seat
// Seats are read through the permission engine's live roster
// (perm_live_seats(), ranked by perm_seat_types), so a renamed seat or an
// ended ministry year is followed automatically. A pastor role is any live
// role whose name or label says "Pastor" (owner, 26 Nov 2026: an Associate
// Pastor is Pastor too). A title is only what we print; it never decides
// access.
//
// COUPLES. users.spouse_id links a married person to their spouse when the
// spouse is in the register too (migration 20261225090000). The link is kept
// on BOTH rows, with the same wedding anniversary, by couples_sync(); deleting
// a person clears it on their spouse (couples_forget()). A link counts only
// when it points both ways.
//
// Wedding anniversaries are one line, husband first: both titled first names,
// then the HUSBAND's last name (owner, 28 Nov 2026):
// "Pastor Ebele & Min. Bolanle Uzo-Peters". No husband's last name on file:
// first names only, "Pastor Ebele & Min. Bolanle". When only one spouse is in
// church, their own last name: "Bro. John Uzo & wife" / "Sis. Ada Okafor &
// husband".
//
// Tests: php tests/couples_test.php (SQLite).

if (!function_exists('couples_ready')) {

    /** Has users.spouse_id arrived (the migration ran)? */
    function couples_ready(PDO $pdo): bool
    {
        static $ready = [];
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, $ready)) {
            try {
                $pdo->query('SELECT spouse_id FROM users WHERE 1 = 0');
                $ready[$key] = true;
            } catch (Throwable $e) {
                $ready[$key] = false;
            }
        }
        return $ready[$key];
    }

    /**
     * The title for one person. $person needs gender and spiritual_status;
     * $rank is the highest live seat rank they hold (0 = none); $floors are
     * the ranks of the pastor / director / hod seat types.
     */
    function couples_title(array $person, int $rank, array $floors, bool $pastorRole = false): string
    {
        $at = static fn (string $k): bool => isset($floors[$k]) && $floors[$k] > 0 && $rank >= $floors[$k];
        if ($pastorRole || (string) ($person['spiritual_status'] ?? '') === 'Pastor' || $at('pastor')) {
            return 'Pastor';
        }
        if ($at('director')) {
            return 'Dir.';
        }
        if ($at('hod')) {
            return 'Min.';
        }
        $gender = strtolower(trim((string) ($person['gender'] ?? '')));
        if ($gender === 'male') {
            return 'Bro.';
        }
        if ($gender === 'female') {
            return 'Sis.';
        }
        return '';
    }

    /**
     * [user_id => highest live seat rank] for everyone seated, the floor
     * ranks of the titled seats, and [user_id => true] for everyone holding a
     * live pastor role. Empty before the permission tables.
     */
    function couples_seat_ranks(PDO $pdo): array
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $ranks = [];
        $floors = [];
        $pastors = [];
        if (function_exists('perm_ready') && perm_ready($pdo)) {
            try {
                $types = perm_seat_types($pdo);
                // Min. starts at the Assistant HOD: the deputy_hod seat, or a
                // seat type named "Assistant HOD" if one was added since.
                $hod = (int) ($types['hod']['seat_rank'] ?? 0);
                $min = $hod;
                foreach ($types as $key => $t) {
                    if ((int) ($t['is_archived'] ?? 0) === 0 && (int) $t['seat_rank'] > 0
                        && couples_is_assistant_hod((string) $key, (string) ($t['label'] ?? ''))) {
                        $min = $min > 0 ? min($min, (int) $t['seat_rank']) : (int) $t['seat_rank'];
                    }
                }
                $floors = [
                    'pastor' => (int) ($types['pastor_in_charge']['seat_rank'] ?? 0),
                    'director' => (int) ($types['director']['seat_rank'] ?? 0),
                    'hod' => $min,
                ];
                foreach (perm_live_seats($pdo, null, perm_today()) as $seat) {
                    $uid = (int) $seat['user_id'];
                    $ranks[$uid] = max($ranks[$uid] ?? 0, (int) $seat['seat_rank']);
                }
            } catch (Throwable $e) {
                error_log('couples: seat ranks unreadable: ' . $e->getMessage());
                $ranks = [];
            }
            $pastors = couples_pastor_roles($pdo, perm_today());
        }
        return $cache[$key] = ['ranks' => $ranks, 'floors' => $floors, 'pastors' => $pastors];
    }

    /** Is this seat type the Assistant HOD (deputy_hod, or a seat named "Assistant/Asst/Deputy HOD")? */
    function couples_is_assistant_hod(string $seatKey, string $label): bool
    {
        if ($seatKey === 'deputy_hod') {
            return true;
        }
        $words = preg_split('/[^a-z]+/', strtolower($seatKey . ' ' . $label), -1, PREG_SPLIT_NO_EMPTY);
        return in_array('hod', $words, true) && array_intersect(['assistant', 'asst', 'deputy'], $words) !== [];
    }

    /**
     * [user_id => true] for everyone holding a live role whose name or label
     * says "Pastor" (Resident Pastor, Associate Pastor, a renamed Senior
     * Pastor ...). Live as in perm_person_roles(): not frozen, not archived,
     * inside its dates. Matched in PHP, for printing a title only.
     */
    function couples_pastor_roles(PDO $pdo, string $today): array
    {
        $out = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT ur.user_id, r.role_name, r.label
                   FROM user_roles ur
                   JOIN roles r ON r.id = ur.role_id
                  WHERE COALESCE(ur.is_frozen, 0) = 0
                    AND COALESCE(r.is_archived, 0) = 0
                    AND (ur.starts_on IS NULL OR ur.starts_on <= ?)
                    AND (ur.ends_on IS NULL OR ur.ends_on >= ?)"
            );
            $stmt->execute([$today, $today]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (preg_match('/pastor/i', (string) $row['role_name'] . ' ' . (string) ($row['label'] ?? ''))) {
                    $out[(int) $row['user_id']] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('couples: pastor roles unreadable: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Title, first name and gender of each person in $ids (numeric ids only;
     * Junior Church refs such as "jc_4" are skipped): [id => [...]].
     * $seats overrides couples_seat_ranks() (tests).
     */
    function couples_people(PDO $pdo, array $ids, ?array $seats = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($v) => is_int($v) || ctype_digit((string) $v) ? (int) $v : 0,
            $ids
        ))));
        if ($ids === []) {
            return [];
        }
        $seats = $seats ?? couples_seat_ranks($pdo);
        $spouseCol = couples_ready($pdo) ? 'spouse_id' : 'NULL AS spouse_id';
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, gender, spiritual_status, {$spouseCol}
                                     FROM users WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['id'];
                $row['title'] = couples_title($row, (int) ($seats['ranks'][$id] ?? 0), $seats['floors'] ?? [], !empty($seats['pastors'][$id]));
                $out[$id] = $row;
            }
        }
        return $out;
    }

    /** "Min. Bolanle" or "Min. Bolanle Ade" — the title, then the name. */
    function couples_titled(string $title, string $first, string $last = ''): string
    {
        return trim(preg_replace('/\s+/', ' ', $title . ' ' . $first . ' ' . $last));
    }

    /** True when $gender reads as female. */
    function couples_is_female(?string $gender): bool
    {
        return strtolower(trim((string) $gender)) === 'female';
    }

    /**
     * A couple's line, husband first: both titled first names, then the
     * husband's last name ("Pastor Ebele & Min. Bolanle Uzo-Peters"); first
     * names only when he has no last name on file. $b is null when the spouse
     * is not in church: "Bro. John Uzo & wife".
     */
    function couples_name(array $a, ?array $b): string
    {
        $one = static fn (array $p): string => couples_titled((string) ($p['title'] ?? ''), (string) ($p['first_name'] ?? ''));
        if ($b === null) {
            $g = strtolower(trim((string) ($a['gender'] ?? '')));
            $other = $g === 'male' ? 'wife' : ($g === 'female' ? 'husband' : 'spouse');
            return couples_titled((string) ($a['title'] ?? ''), (string) ($a['first_name'] ?? ''), (string) ($a['last_name'] ?? ''))
                . ' & ' . $other;
        }
        if (couples_is_female($a['gender'] ?? null) && !couples_is_female($b['gender'] ?? null)) {
            [$a, $b] = [$b, $a];
        }
        return trim($one($a) . ' & ' . $one($b) . ' ' . trim((string) ($a['last_name'] ?? '')));
    }

    /**
     * Name every row of a celebrations list, and fold a linked couple's two
     * wedding anniversary rows into one.
     *
     * $idKey   the column holding the person's users.id
     * $wedding the event_type values that are wedding anniversaries
     * Adds to each row: title, display_name (birthdays and the rest: title +
     * full name; anniversaries: the couple's line) and, for a couple,
     * spouse_user_id. Of a couple's two rows the one already assigned to a
     * worker is kept, else the husband's. Order is otherwise unchanged.
     */
    function couples_label_rows(PDO $pdo, array $rows, string $idKey = 'target_user_id', array $wedding = ['Wedding_Anniversary'], ?array $seats = null): array
    {
        // Junior Church rows (source 'jc', or a "jc_4" id) are children: no
        // title, never a couple.
        $isUser = static fn (array $r): bool => ($r['source'] ?? 'user') === 'user'
            && ctype_digit((string) ($r[$idKey] ?? ''));
        $ids = [];
        foreach ($rows as $r) {
            if ($isUser($r)) {
                $ids[] = $r[$idKey];
            }
        }
        $people = couples_people($pdo, $ids, $seats);
        $spouseIds = [];
        foreach ($people as $p) {
            if (!empty($p['spouse_id'])) {
                $spouseIds[] = (int) $p['spouse_id'];
            }
        }
        $people += couples_people($pdo, array_diff($spouseIds, array_keys($people)), $seats);

        $kept = [];   // couple key => index in $out
        $out = [];
        foreach ($rows as $r) {
            $me = $isUser($r) ? ($people[(int) $r[$idKey]] ?? null) : null;
            $r['title'] = $me['title'] ?? '';
            $r['display_name'] = couples_titled($r['title'], (string) ($r['first_name'] ?? ''), (string) ($r['last_name'] ?? ''));

            if ($me === null || !in_array((string) ($r['event_type'] ?? ''), $wedding, true)) {
                $out[] = $r;
                continue;
            }
            $spouse = !empty($me['spouse_id']) ? ($people[(int) $me['spouse_id']] ?? null) : null;
            if ($spouse !== null && (int) ($spouse['spouse_id'] ?? 0) !== (int) $me['id']) {
                $spouse = null;   // a one-way link does not count
            }
            $meRow = $me;
            $meRow['first_name'] = (string) ($r['first_name'] ?? $me['first_name']);
            $meRow['last_name'] = (string) ($r['last_name'] ?? $me['last_name']);
            $r['display_name'] = couples_name($meRow, $spouse);
            if ($spouse === null) {
                $out[] = $r;
                continue;
            }
            $r['spouse_user_id'] = (int) $spouse['id'];
            $pair = [(int) $me['id'], (int) $spouse['id']];
            sort($pair);
            $day = (string) ($r['event_date'] ?? '');
            if (preg_match('/^\d{4}-(\d{2}-\d{2})/', $day, $m)) {
                $day = $m[1];   // the same day in any year
            }
            $key = $pair[0] . '-' . $pair[1] . '|' . ($r['event_type'] ?? '') . '|' . $day;
            if (!isset($kept[$key])) {
                $kept[$key] = count($out);
                $out[] = $r;
                continue;
            }
            $other = $out[$kept[$key]];
            $mineAssigned = !empty($r['assignment_id']);
            $theirsAssigned = !empty($other['assignment_id']);
            $iAmHusband = !couples_is_female($me['gender'] ?? null) && couples_is_female($spouse['gender'] ?? null);
            if (($mineAssigned && !$theirsAssigned) || ($mineAssigned === $theirsAssigned && $iAmHusband)) {
                [$r, $other] = [$other, $r];
            }
            // The recap's slide keeps whichever photo the couple has.
            if (array_key_exists('display_picture', $r) && empty($other['display_picture']) && !empty($r['display_picture'])) {
                $other['display_picture'] = $r['display_picture'];
            }
            $out[$kept[$key]] = $other;
        }
        return $out;
    }

    /**
     * Keep a person's spouse link in step after their record is saved with
     * $marital and the chosen $spouseId (0/null = none, or the spouse is not
     * in church). Married + a spouse: both rows point at each other, the
     * spouse is marked Married, and both carry the same anniversary (the one
     * just typed, else the spouse's). Anything else: the link is cleared on
     * both sides. Anyone the spouse was linked to before loses that link.
     * Returns null on success, or a one-line problem.
     */
    function couples_sync(PDO $pdo, int $personId, string $marital, ?int $spouseId, ?string $anniversary): ?string
    {
        if (!couples_ready($pdo) || $personId <= 0) {
            return null;
        }
        $spouseId = (int) $spouseId;
        $old = $pdo->prepare('SELECT spouse_id FROM users WHERE id = ?');
        $old->execute([$personId]);
        $oldSpouse = (int) $old->fetchColumn();
        $clearBack = $pdo->prepare('UPDATE users SET spouse_id = NULL WHERE id = ? AND spouse_id = ?');

        if ($marital !== 'Married' || $spouseId <= 0) {
            $pdo->prepare('UPDATE users SET spouse_id = NULL WHERE id = ?')->execute([$personId]);
            if ($oldSpouse > 0) {
                $clearBack->execute([$oldSpouse, $personId]);
            }
            return null;
        }
        if ($spouseId === $personId) {
            return 'Choose someone else as their spouse.';
        }
        $sp = $pdo->prepare('SELECT id, spouse_id, wedding_anniversary FROM users WHERE id = ?');
        $sp->execute([$spouseId]);
        $spouse = $sp->fetch(PDO::FETCH_ASSOC);
        if (!$spouse) {
            return 'That spouse is no longer in the register. Choose again.';
        }
        $theirOld = (int) $spouse['spouse_id'];
        if ($theirOld > 0 && $theirOld !== $personId) {
            $clearBack->execute([$theirOld, $spouseId]);
        }
        if ($oldSpouse > 0 && $oldSpouse !== $spouseId) {
            $clearBack->execute([$oldSpouse, $personId]);
        }
        $date = $anniversary !== null && $anniversary !== ''
            ? $anniversary
            : ($spouse['wedding_anniversary'] ?: null);
        $pdo->prepare('UPDATE users SET spouse_id = ?, wedding_anniversary = ? WHERE id = ?')
            ->execute([$spouseId, $date, $personId]);
        $pdo->prepare("UPDATE users SET spouse_id = ?, marital_status = 'Married', wedding_anniversary = ? WHERE id = ?")
            ->execute([$personId, $date, $spouseId]);
        return null;
    }

    /** A person is being deleted: their spouse is no longer linked to them. */
    function couples_forget(PDO $pdo, int $personId): void
    {
        if (couples_ready($pdo) && $personId > 0) {
            $pdo->prepare('UPDATE users SET spouse_id = NULL WHERE spouse_id = ?')->execute([$personId]);
        }
    }

    /**
     * A marital status saved somewhere other than Congregation (the member's
     * own profile, Embrace): anything but Married ends the link on both sides,
     * and Married keeps the anniversary in step on the spouse.
     */
    function couples_after_status(PDO $pdo, int $personId, string $marital, ?string $anniversary): void
    {
        if (!couples_ready($pdo) || $personId <= 0) {
            return;
        }
        $stmt = $pdo->prepare('SELECT spouse_id FROM users WHERE id = ?');
        $stmt->execute([$personId]);
        $spouse = (int) $stmt->fetchColumn();
        if ($spouse <= 0) {
            return;
        }
        if ($marital !== 'Married') {
            couples_sync($pdo, $personId, $marital, null, null);
            return;
        }
        if ($anniversary !== null && $anniversary !== '') {
            $pdo->prepare('UPDATE users SET wedding_anniversary = ? WHERE id = ? AND spouse_id = ?')
                ->execute([$anniversary, $spouse, $personId]);
        }
    }
}
