<?php

namespace HOD\TrainingLive;

use DateTimeImmutable;
use DateTimeZone;
use HOD\Integrations\Google\Google;
use HOD\Integrations\Google\GoogleError;
use PDO;
use Throwable;

/**
 * Trainings' live classes on Google Meet (docs/INTEGRATIONS.md, "Live classes"). Built on PR 1's tables and
 * helpers (includes/trainings/): a live item's details keep mode, venue, link and present_minutes; this adds
 * meet_url and meet_event_id, which the learner's page already prefers over a pasted link.
 *
 *   createMeet / updateMeet / cancelMeet   the Calendar event with a Meet link (the Academy's "Meet" page)
 *   reconcileTraining                      after the builder saved, moved or removed something: the event follows
 *   join                                   Join class: logs who joined and when, then the learner goes to Meet
 *   sync / syncDue                         after the class: Meet participants → Matcher → attendance and progress
 *   setPresent / assign / ignore / reopen  the tutor's corrections (never undone by a later sync)
 *
 * Progress: a learner found present gets the item completed ({by: 'meet'}); a tutor's tick ({by: 'tutor'}) is never
 * touched by a sync. Times are Lagos, from trainings_now().
 */
final class Live
{
    public const TABLES = ['training_meetings', 'training_join_log', 'training_attendance', 'training_meet_participants'];
    public const DEFAULT_MINUTES = 60;

    public static function ready(PDO $pdo): bool
    {
        if (!function_exists('trainings_ready') || !trainings_ready($pdo)) {
            return false;
        }
        foreach (self::TABLES as $table) {
            if (!trainings_table_exists($pdo, $table)) {
                return false;
            }
        }

        return true;
    }

    // ---- The item and its Meet --------------------------------------------------------------------------

    /** [item, training] of a live item, or null */
    public static function context(PDO $pdo, int $itemId): ?array
    {
        $item = trainings_item_get($pdo, $itemId);
        $t = $item !== null ? trainings_get($pdo, $item['training_id']) : null;

        return $item !== null && $t !== null && $item['type'] === 'live' ? [$item, $t] : null;
    }

    public static function meeting(PDO $pdo, int $itemId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM training_meetings WHERE item_id = ?');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** When the class starts and ends (Lagos), or null without a time */
    public static function window(array $item): ?array
    {
        if (empty($item['starts_at'])) {
            return null;
        }
        $start = new DateTimeImmutable((string) $item['starts_at'], new DateTimeZone(TRAININGS_TZ));

        return [$start, $start->modify('+' . max(1, (int) ($item['minutes'] ?: self::DEFAULT_MINUTES)) . ' minutes')];
    }

    public static function eventTitle(array $t, array $item): string
    {
        return mb_substr(trim($t['title'] . ': ' . $item['title']), 0, 250);
    }

    /** The Meet for a live item: made once, then kept in step with the item. */
    public static function createMeet(PDO $pdo, int $itemId, int $by): array
    {
        $ctx = self::context($pdo, $itemId);
        if ($ctx === null) {
            return self::fail('That class was removed. Reload the page.');
        }
        [$item, $t] = $ctx;
        if (($item['details']['mode'] ?? 'in_person') !== 'online') {
            return self::fail('Make the class online first (Edit item).');
        }
        $window = self::window($item);
        if ($window === null) {
            return self::fail('Give the class a date and time first.');
        }
        $have = self::meeting($pdo, $itemId);
        if ($have !== null && $have['status'] === 'active' && $have['meet_url']) {
            return ['ok' => true, 'meet_url' => $have['meet_url']];
        }
        try {
            $ev = Google::createEvent(['title' => self::eventTitle($t, $item), 'starts_at' => $window[0], 'ends_at' => $window[1],
                'description' => 'HOD Lekki Trainings. Join from the Trainings page so your attendance counts.']);
        } catch (GoogleError $e) {
            return self::fail($e->getMessage());
        }
        $now = trainings_sql_dt();
        $row = ['training_id' => $t['id'], 'provider' => 'google', 'status' => 'active', 'event_id' => $ev['event_id'],
            'meet_url' => $ev['meet_url'], 'meet_code' => $ev['meet_code'], 'html_link' => $ev['html_link'],
            'title_sent' => self::eventTitle($t, $item), 'starts_at' => trainings_sql_dt($window[0]), 'ends_at' => trainings_sql_dt($window[1]),
            'last_error' => null, 'updated_at' => $now];
        if ($have !== null) {
            self::update($pdo, 'training_meetings', $row + ['conference_record' => null, 'synced_at' => null], (int) $have['id']);
        } else {
            self::insert($pdo, 'training_meetings', $row + ['item_id' => $itemId, 'created_by' => $by, 'created_at' => $now]);
        }
        self::setDetails($pdo, $itemId, $ev['meet_url'], $ev['event_id']);

        return ['ok' => true, 'meet_url' => $ev['meet_url']];
    }

    /** Send the item's title and time to Google again (normally automatic after an edit). */
    public static function updateMeet(PDO $pdo, int $itemId): array
    {
        $m = self::meeting($pdo, $itemId);
        if ($m === null || $m['status'] !== 'active') {
            return self::fail('This class has no Meet link yet.');
        }
        $r = self::reconcile($pdo, $m, true);

        return $r === null ? ['ok' => true] : self::fail($r);
    }

    /** Remove the Meet: the event is deleted at Google and the link leaves the item. */
    public static function cancelMeet(PDO $pdo, int $itemId): array
    {
        $m = self::meeting($pdo, $itemId);
        if ($m === null || $m['status'] !== 'active') {
            return ['ok' => true];
        }
        try {
            if ($m['event_id']) {
                Google::cancelEvent((string) $m['event_id']);
            }
        } catch (GoogleError $e) {
            return self::fail($e->getMessage());
        }
        self::update($pdo, 'training_meetings', ['status' => 'cancelled', 'last_error' => null, 'updated_at' => trainings_sql_dt()], (int) $m['id']);
        self::setDetails($pdo, $itemId, null, null);

        return ['ok' => true];
    }

    /** After the builder changed a training: each of its Meets follows its item (moved, retitled, gone). */
    public static function reconcileTraining(PDO $pdo, int $trainingId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM training_meetings WHERE training_id = ? AND status = 'active'");
        $stmt->execute([$trainingId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            self::reconcile($pdo, $m);
        }
    }

    /** null when in step (or put back in step); else why not (kept in last_error, tried again next time) */
    private static function reconcile(PDO $pdo, array $m, bool $force = false): ?string
    {
        $ctx = self::context($pdo, (int) $m['item_id']);
        $item = $ctx[0] ?? null;
        $window = $item !== null ? self::window($item) : null;
        try {
            if ($ctx === null || ($item['details']['mode'] ?? '') !== 'online' || $window === null) {
                // Removed, or no longer an online class with a time: the event goes
                if ($m['event_id']) {
                    Google::cancelEvent((string) $m['event_id']);
                }
                self::update($pdo, 'training_meetings', ['status' => 'cancelled', 'last_error' => null, 'updated_at' => trainings_sql_dt()], (int) $m['id']);
                if ($item !== null) {
                    self::setDetails($pdo, $item['id'], null, null);
                }

                return null;
            }
            $title = self::eventTitle($ctx[1], $item);
            $starts = trainings_sql_dt($window[0]);
            $ends = trainings_sql_dt($window[1]);
            if ($force || $title !== $m['title_sent'] || $starts !== $m['starts_at'] || $ends !== $m['ends_at']) {
                Google::updateEvent((string) $m['event_id'], ['title' => $title, 'starts_at' => $window[0], 'ends_at' => $window[1]]);
                self::update($pdo, 'training_meetings', ['title_sent' => $title, 'starts_at' => $starts, 'ends_at' => $ends,
                    'last_error' => null, 'updated_at' => trainings_sql_dt()], (int) $m['id']);
            } elseif ($m['last_error'] !== null) {
                // Back in step without a call (the item went back to what Google has): the old problem is gone
                self::update($pdo, 'training_meetings', ['last_error' => null, 'updated_at' => trainings_sql_dt()], (int) $m['id']);
            }
            if (($item['details']['meet_url'] ?? '') !== $m['meet_url']) {
                self::setDetails($pdo, $item['id'], $m['meet_url'], $m['event_id']);
            }

            return null;
        } catch (GoogleError $e) {
            self::update($pdo, 'training_meetings', ['last_error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => trainings_sql_dt()], (int) $m['id']);

            return $e->getMessage();
        }
    }

    /** meet_url / meet_event_id in the item's details (PR 1 keeps them when the item is edited) */
    private static function setDetails(PDO $pdo, int $itemId, ?string $url, ?string $eventId): void
    {
        $item = trainings_item_get($pdo, $itemId);
        if ($item === null) {
            return;
        }
        $d = $item['details'];
        unset($d['meet_url'], $d['meet_event_id']);
        if ($url !== null) {
            $d['meet_url'] = $url;
            $d['meet_event_id'] = $eventId;
        }
        $pdo->prepare('UPDATE training_items SET details = ? WHERE id = ?')->execute([trainings_json($d), $itemId]);
    }

    // ---- Join class -------------------------------------------------------------------------------------

    /** A learner pressed Join class: log it (once a minute at most) and hand back where to go. */
    public static function join(PDO $pdo, int $itemId, int $userId): array
    {
        $ctx = self::context($pdo, $itemId);
        if ($ctx === null || $ctx[1]['status'] !== 'published') {
            return self::fail('That class is not open.');
        }
        [$item, $t] = $ctx;
        $url = (string) (($item['details']['meet_url'] ?? '') !== '' ? $item['details']['meet_url'] : ($item['details']['link'] ?? ''));
        if (($item['details']['mode'] ?? '') !== 'online' || $url === '') {
            return self::fail('This class has no link to join.');
        }
        $e = trainings_enrolment_for($pdo, $t['id'], $userId);
        if ($e === null || $e['status'] === 'withdrawn') {
            return self::fail('Join the training first.');
        }
        $now = trainings_now();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_join_log WHERE item_id = ? AND user_id = ? AND joined_at >= ?');
        $stmt->execute([$itemId, $userId, trainings_sql_dt($now->modify('-60 seconds'))]);
        if ((int) $stmt->fetchColumn() === 0) {
            self::insert($pdo, 'training_join_log', ['item_id' => $itemId, 'user_id' => $userId, 'joined_at' => trainings_sql_dt($now)]);
        }

        return ['ok' => true, 'url' => $url];
    }

    // ---- Attendance -------------------------------------------------------------------------------------

    /** Enrolled learners (not withdrawn): user_id, enrolment_id, name */
    public static function learners(PDO $pdo, int $trainingId): array
    {
        $stmt = $pdo->prepare("SELECT e.id AS enrolment_id, e.user_id, u.first_name, u.last_name FROM training_enrolments e
            JOIN users u ON u.id = e.user_id WHERE e.training_id = ? AND e.status <> 'withdrawn' ORDER BY u.first_name, u.last_name");
        $stmt->execute([$trainingId]);

        return array_map(fn ($r) => ['user_id' => (int) $r['user_id'], 'enrolment_id' => (int) $r['enrolment_id'],
            'name' => trainings_person_name($r)], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * After the class: read Meet's calls for this item, match the participants, write attendance and progress.
     * state: synced | running (the call is still on: partial) | no_records (Meet has nothing yet)
     */
    public static function sync(PDO $pdo, int $itemId): array
    {
        $ctx = self::context($pdo, $itemId);
        $m = self::meeting($pdo, $itemId);
        if ($ctx === null || $m === null || $m['status'] !== 'active' || !$m['meet_code']) {
            return self::fail('Only a class with a Meet link from here can bring attendance in.');
        }
        [$item, $t] = $ctx;
        $window = self::window($item);
        $now = trainings_now();
        if ($window === null || $now < $window[0]) {
            return self::fail('The class has not started yet.');
        }
        try {
            $records = Google::conferenceRecords((string) $m['meet_code'], $window[0]->modify('-60 minutes'), $window[1]->modify('+180 minutes'));
            if ($records === []) {
                return ['ok' => true, 'state' => 'no_records', 'message' => 'Meet has no call for this class yet.'];
            }
            $participants = [];
            $running = false;
            $end = 0;
            foreach ($records as $r) {
                array_push($participants, ...Google::participants($r['name']));
                $running = $running || $r['end'] === null;
                $end = max($end, $r['end'] !== null ? strtotime((string) $r['end']) : $now->getTimestamp());
            }
        } catch (GoogleError $e) {
            self::update($pdo, 'training_meetings', ['last_error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => trainings_sql_dt()], (int) $m['id']);

            return self::fail($e->getMessage());
        }
        $learners = self::learners($pdo, $t['id']);
        $stmt = $pdo->prepare('SELECT user_id, joined_at FROM training_join_log WHERE item_id = ?');
        $stmt->execute([$itemId]);
        $tz = new DateTimeZone(TRAININGS_TZ);
        $joins = array_map(fn ($j) => ['user_id' => (int) $j['user_id'], 'at' => (new DateTimeImmutable((string) $j['joined_at'], $tz))->getTimestamp()],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
        $need = self::presentMinutes($item);
        $result = Matcher::match($participants, $learners, $joins, $end, $need);

        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $changed = self::write($pdo, $item, $result, $learners);
            self::update($pdo, 'training_meetings', ['conference_record' => trainings_json(array_column($records, 'name')),
                'synced_at' => trainings_sql_dt($now), 'last_error' => null, 'updated_at' => trainings_sql_dt($now)], (int) $m['id']);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        foreach ($changed as $enrolmentId) {
            trainings_recheck_enrolment($pdo, $enrolmentId);
        }
        $open = self::count($pdo, "SELECT COUNT(*) FROM training_meet_participants WHERE item_id = ? AND status = 'open'", [$itemId]);
        $present = self::count($pdo, 'SELECT COUNT(*) FROM training_attendance WHERE item_id = ? AND present = 1', [$itemId]);

        return ['ok' => true, 'state' => $running ? 'running' : 'synced', 'present' => $present, 'open' => $open,
            'message' => $present . ' present' . ($open ? ', ' . $open . ' to look at' : '') . ($running ? ' so far (the call is still on)' : '') . '.'];
    }

    /** The daily run: classes that ended at least 2 hours ago (in the last 3 days) and were not read since. */
    public static function syncDue(PDO $pdo): int
    {
        $now = trainings_now();
        $stmt = $pdo->prepare("SELECT item_id, ends_at, synced_at FROM training_meetings WHERE status = 'active' AND meet_code IS NOT NULL
            AND ends_at <= ? AND ends_at >= ?");
        $stmt->execute([trainings_sql_dt($now->modify('-2 hours')), trainings_sql_dt($now->modify('-3 days'))]);
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $settled = (new DateTimeImmutable((string) $m['ends_at'], new DateTimeZone(TRAININGS_TZ)))->modify('+2 hours');
            if ($m['synced_at'] !== null && new DateTimeImmutable((string) $m['synced_at'], new DateTimeZone(TRAININGS_TZ)) >= $settled) {
                continue;
            }
            try {
                $r = self::sync($pdo, (int) $m['item_id']);
                $n += !empty($r['ok']) && ($r['state'] ?? '') === 'synced' ? 1 : 0;
            } catch (Throwable $e) {
                error_log('[trainings] live attendance for item ' . (int) $m['item_id'] . ' failed: ' . get_class($e));
            }
        }

        return $n;
    }

    /** @return list<int> enrolment ids whose progress changed */
    private static function write(PDO $pdo, array $item, array $result, array $learners): array
    {
        $itemId = (int) $item['id'];
        $now = trainings_sql_dt();
        $enrolments = array_column($learners, 'enrolment_id', 'user_id');
        $stmt = $pdo->prepare('SELECT * FROM training_attendance WHERE item_id = ?');
        $stmt->execute([$itemId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[(int) $row['user_id']] = $row;
        }
        $touched = [];
        foreach ($result['matched'] as $uid => $r) {
            if (($existing[$uid]['source'] ?? '') === 'manual') {
                continue;   // the tutor decided
            }
            self::upsertAttendance($pdo, $itemId, (int) $uid, ['source' => $r['source'], 'minutes' => $r['minutes'], 'present' => $r['present'] ? 1 : 0,
                'confidence' => $r['confidence'], 'participants' => trainings_json($r['participants']), 'resolved_by' => null, 'updated_at' => $now]);
            $touched[$uid] = ['present' => $r['present'], 'minutes' => $r['minutes'], 'source' => $r['source']];
        }
        foreach ($existing as $uid => $row) {
            if ($row['source'] !== 'manual' && !isset($result['matched'][$uid])) {
                $pdo->prepare('DELETE FROM training_attendance WHERE id = ?')->execute([$row['id']]);
                $touched[$uid] = ['present' => false];
            }
        }
        $changed = [];
        foreach ($touched as $uid => $a) {
            $eid = $enrolments[$uid] ?? null;
            if ($eid === null) {
                continue;
            }
            $p = trainings_progress_map($pdo, $eid)[$itemId] ?? null;
            $byMeet = $p !== null && ($p['data']['by'] ?? '') === 'meet';
            if ($a['present'] && ($p === null || $byMeet)) {
                trainings_progress_put($pdo, $eid, $itemId, 'completed', ['by' => 'meet', 'source' => $a['source'], 'minutes' => $a['minutes']]);
                $changed[] = $eid;
            } elseif (!$a['present'] && $byMeet) {
                trainings_progress_clear($pdo, $eid, $itemId);
                $changed[] = $eid;
            }
        }

        // Needs a look: one row per name; a row the tutor resolved keeps its decision
        $keep = [];
        foreach ($result['review'] as $r) {
            $key = mb_substr(Matcher::normalise($r['display_name']) ?: '#' . $r['participants'][0], 0, 190);
            $keep[] = $key;
            $stmt = $pdo->prepare('SELECT id, status FROM training_meet_participants WHERE item_id = ? AND name_key = ?');
            $stmt->execute([$itemId, $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $fields = ['display_name' => mb_substr($r['display_name'] !== '' ? $r['display_name'] : 'Someone without a name', 0, 190),
                'minutes' => $r['minutes'], 'suggestions' => trainings_json($r['suggestions']), 'updated_at' => $now];
            if ($row) {
                self::update($pdo, 'training_meet_participants', $fields, (int) $row['id']);
            } else {
                self::insert($pdo, 'training_meet_participants', $fields + ['item_id' => $itemId, 'name_key' => $key, 'status' => 'open']);
            }
        }
        $stmt = $pdo->prepare("SELECT id, name_key FROM training_meet_participants WHERE item_id = ? AND status = 'open'");
        $stmt->execute([$itemId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array($row['name_key'], $keep, true)) {
                $pdo->prepare('DELETE FROM training_meet_participants WHERE id = ?')->execute([$row['id']]);
            }
        }

        return array_values(array_unique($changed));
    }

    // ---- The tutor's corrections ------------------------------------------------------------------------

    /** Present or not, decided by the tutor: kept through every later sync. */
    public static function setPresent(PDO $pdo, int $itemId, int $userId, bool $present, int $by): array
    {
        $ctx = self::context($pdo, $itemId);
        $e = $ctx !== null ? trainings_enrolment_for($pdo, $ctx[1]['id'], $userId) : null;
        if ($ctx === null || $e === null || $e['status'] === 'withdrawn') {
            return self::fail('That learner is not on this training.');
        }
        $stmt = $pdo->prepare('SELECT minutes FROM training_attendance WHERE item_id = ? AND user_id = ?');
        $stmt->execute([$itemId, $userId]);
        $minutes = $stmt->fetchColumn();
        self::upsertAttendance($pdo, $itemId, $userId, ['source' => 'manual', 'minutes' => $minutes === false || $minutes === null ? null : (int) $minutes,
            'present' => $present ? 1 : 0, 'confidence' => null, 'resolved_by' => $by, 'updated_at' => trainings_sql_dt()]);
        $r = trainings_tutor_done($pdo, $ctx[1], $e, $ctx[0], $present, $by);

        return ['ok' => true, 'completed' => !empty($r['completed'])];
    }

    /** A "Needs a look" participant is this learner: their minutes count for them. */
    public static function assign(PDO $pdo, int $itemId, int $rowId, int $userId, int $by): array
    {
        $row = self::reviewRow($pdo, $itemId, $rowId);
        $ctx = self::context($pdo, $itemId);
        $e = $ctx !== null ? trainings_enrolment_for($pdo, $ctx[1]['id'], $userId) : null;
        if ($row === null || $ctx === null || $e === null || $e['status'] === 'withdrawn') {
            return self::fail('Reload the page: that changed.');
        }
        $stmt = $pdo->prepare('SELECT minutes FROM training_attendance WHERE item_id = ? AND user_id = ?');
        $stmt->execute([$itemId, $userId]);
        $minutes = (int) $stmt->fetchColumn() + (int) $row['minutes'];
        $present = $minutes >= self::presentMinutes($ctx[0]);
        self::upsertAttendance($pdo, $itemId, $userId, ['source' => 'manual', 'minutes' => $minutes, 'present' => $present ? 1 : 0,
            'confidence' => null, 'resolved_by' => $by, 'updated_at' => trainings_sql_dt()]);
        self::update($pdo, 'training_meet_participants', ['status' => 'assigned', 'user_id' => $userId, 'resolved_by' => $by,
            'updated_at' => trainings_sql_dt()], $rowId);
        trainings_tutor_done($pdo, $ctx[1], $e, $ctx[0], $present, $by);

        return ['ok' => true, 'present' => $present, 'minutes' => $minutes];
    }

    /** Not a learner (a guest, the tutor's own device): out of the list. */
    public static function ignore(PDO $pdo, int $itemId, int $rowId, int $by): array
    {
        if (self::reviewRow($pdo, $itemId, $rowId) === null) {
            return self::fail('Reload the page: that changed.');
        }
        self::update($pdo, 'training_meet_participants', ['status' => 'ignored', 'resolved_by' => $by, 'updated_at' => trainings_sql_dt()], $rowId);

        return ['ok' => true];
    }

    /** Back to the list (the learner's attendance stays as the tutor last set it). */
    public static function reopen(PDO $pdo, int $itemId, int $rowId): array
    {
        if (self::reviewRow($pdo, $itemId, $rowId) === null) {
            return self::fail('Reload the page: that changed.');
        }
        self::update($pdo, 'training_meet_participants', ['status' => 'open', 'user_id' => null, 'resolved_by' => null,
            'updated_at' => trainings_sql_dt()], $rowId);

        return ['ok' => true];
    }

    /**
     * Everything the Academy's Meet page shows: each learner with their attendance and Join presses, the
     * participants to look at, and those resolved.
     */
    public static function state(PDO $pdo, int $itemId): array
    {
        $ctx = self::context($pdo, $itemId);
        if ($ctx === null) {
            return [];
        }
        [$item, $t] = $ctx;
        $att = [];
        $stmt = $pdo->prepare('SELECT * FROM training_attendance WHERE item_id = ?');
        $stmt->execute([$itemId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $att[(int) $a['user_id']] = $a;
        }
        $joined = [];
        $stmt = $pdo->prepare('SELECT user_id, MIN(joined_at) AS first_at, COUNT(*) AS n FROM training_join_log WHERE item_id = ? GROUP BY user_id');
        $stmt->execute([$itemId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $joined[(int) $j['user_id']] = (string) $j['first_at'];
        }
        $learners = [];
        foreach (self::learners($pdo, $t['id']) as $l) {
            $a = $att[$l['user_id']] ?? null;
            $learners[] = $l + ['present' => $a !== null ? (int) $a['present'] === 1 : null, 'minutes' => $a !== null && $a['minutes'] !== null ? (int) $a['minutes'] : null,
                'source' => $a['source'] ?? null, 'joined_at' => $joined[$l['user_id']] ?? null];
        }
        $stmt = $pdo->prepare('SELECT * FROM training_meet_participants WHERE item_id = ? ORDER BY minutes DESC, display_name');
        $stmt->execute([$itemId]);
        $review = array_map(fn ($r) => ['id' => (int) $r['id'], 'display_name' => (string) $r['display_name'], 'minutes' => (int) $r['minutes'],
            'status' => (string) $r['status'], 'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'suggestions' => trainings_decode($r['suggestions'])], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['item' => $item, 'training' => $t, 'meeting' => self::meeting($pdo, $itemId), 'window' => self::window($item),
            'present_minutes' => self::presentMinutes($item), 'learners' => $learners, 'review' => $review];
    }

    public static function presentMinutes(array $item): int
    {
        return max(1, (int) ($item['details']['present_minutes'] ?? 30) ?: 30);
    }

    // ---- Plumbing ---------------------------------------------------------------------------------------

    private static function reviewRow(PDO $pdo, int $itemId, int $rowId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM training_meet_participants WHERE id = ? AND item_id = ?');
        $stmt->execute([$rowId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function upsertAttendance(PDO $pdo, int $itemId, int $userId, array $fields): void
    {
        $stmt = $pdo->prepare('SELECT id FROM training_attendance WHERE item_id = ? AND user_id = ?');
        $stmt->execute([$itemId, $userId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            self::update($pdo, 'training_attendance', $fields, (int) $id);
        } else {
            self::insert($pdo, 'training_attendance', $fields + ['item_id' => $itemId, 'user_id' => $userId]);
        }
    }

    private static function insert(PDO $pdo, string $table, array $row): void
    {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($row));
    }

    private static function update(PDO $pdo, string $table, array $fields, int $id): void
    {
        $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', array_map(fn ($c) => $c . ' = ?', array_keys($fields))) . ' WHERE id = ?')
            ->execute([...array_values($fields), $id]);
    }

    private static function count(PDO $pdo, string $sql, array $params): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
