<?php
// /includes/trainings/build.php
//
// Building a training: the length wizard's empty frame (one day, a few days,
// weeks, self-paced), its cards (sections), its items and their media, the
// two ready templates, publishing. Loaded by bootstrap.php.
//
// Media is links first (owner's answer 7): YouTube and Spotify play embedded
// (frame-src allows exactly those two hosts on the pages that show them).
// Audio can instead be an uploaded MP3/M4A (up to 50 MB) and a reading a PDF
// (up to 20 MB): through upload_guard.php (allow-list, random names, 0755),
// then the first bytes are checked to really be that kind of file.

const TRAININGS_AUDIO_MAX_BYTES = 50 * 1024 * 1024;
const TRAININGS_PDF_MAX_BYTES = 20 * 1024 * 1024;
const TRAININGS_UPLOAD_URL = '/uploads/trainings/';
const TRAININGS_EMBED_HOSTS = ['https://www.youtube-nocookie.com', 'https://open.spotify.com'];
const TRAININGS_DEFAULT_PASS = ['required' => 'all', 'live_percent' => 100];

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

function trainings_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM trainings WHERE id = ?');
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    return $t ? trainings_shape($t) : null;
}

function trainings_shape(array $t): array
{
    $t['id'] = (int) $t['id'];
    $t['catalogue'] = (int) $t['catalogue'] === 1;
    $t['gives_certificate'] = (int) $t['gives_certificate'] === 1;
    $t['pass_rule'] = trainings_decode($t['pass_rule'] ?? null) + TRAININGS_DEFAULT_PASS;
    $t['department_id'] = $t['department_id'] !== null ? (int) $t['department_id'] : null;
    return $t;
}

function trainings_section_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_sections WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function trainings_item_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM training_items WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? trainings_item_shape($row) : null;
}

function trainings_item_shape(array $i): array
{
    $i['id'] = (int) $i['id'];
    $i['training_id'] = (int) $i['training_id'];
    $i['section_id'] = $i['section_id'] !== null ? (int) $i['section_id'] : null;
    $i['position'] = (int) $i['position'];
    $i['minutes'] = $i['minutes'] !== null ? (int) $i['minutes'] : null;
    $i['required'] = (int) $i['required'] === 1;
    $i['details'] = trainings_decode($i['details'] ?? null);
    $i['needs_content'] = trainings_item_missing($i);
    return $i;
}

/**
 * A training's cards with their items in order (a dated card shows its items
 * in time order; untimed ones follow in the order they were placed).
 *
 * @return list<array> sections, each with 'items'; items without a card come last under id 0
 */
function trainings_outline(PDO $pdo, int $trainingId): array
{
    $stmt = $pdo->prepare('SELECT * FROM training_sections WHERE training_id = ? ORDER BY position, id');
    $stmt->execute([$trainingId]);
    $sections = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $s['id'] = (int) $s['id'];
        $s['position'] = (int) $s['position'];
        $s['items'] = [];
        $sections[$s['id']] = $s;
    }
    $stmt = $pdo->prepare('SELECT * FROM training_items WHERE training_id = ?
                            ORDER BY CASE WHEN starts_at IS NULL THEN 1 ELSE 0 END, starts_at, position, id');
    $stmt->execute([$trainingId]);
    $loose = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item = trainings_item_shape($row);
        if ($item['section_id'] !== null && isset($sections[$item['section_id']])) {
            $sections[$item['section_id']]['items'][] = $item;
        } else {
            $loose[] = $item;
        }
    }
    $out = array_values($sections);
    if ($loose !== []) {
        $out[] = ['id' => 0, 'training_id' => $trainingId, 'kind' => 'module', 'title' => 'Other items',
            'date_from' => null, 'date_to' => null, 'position' => PHP_INT_MAX, 'items' => $loose];
    }
    return $out;
}

/** Every item of a training, in outline order. */
function trainings_items_flat(PDO $pdo, int $trainingId): array
{
    $items = [];
    foreach (trainings_outline($pdo, $trainingId) as $s) {
        foreach ($s['items'] as $i) {
            $items[] = $i;
        }
    }
    return $items;
}

// ---------------------------------------------------------------------------
// Creating: the length wizard
// ---------------------------------------------------------------------------

/**
 * Create a training and its empty frame (or a template's frame and items).
 * $in: title, summary, length, starts_on, count (days or weeks), template
 * ('' | 'membership' | 'maturity').
 *
 * @return array{ok:bool, id?:int, message?:string, field?:string}
 */
function trainings_create(PDO $pdo, array $in, int $by): array
{
    $template = (string) ($in['template'] ?? '');
    $tpl = $template !== '' ? (trainings_templates()[$template] ?? null) : null;
    if ($template !== '' && $tpl === null) {
        return ['ok' => false, 'message' => 'Choose a template from the list.', 'field' => 'template'];
    }
    $title = trainings_text($in['title'] ?? '', 160);
    if ($title === '' && $tpl !== null) {
        $title = $tpl['title'];
    }
    if ($title === '') {
        return ['ok' => false, 'message' => 'Give the training a name.', 'field' => 'title'];
    }
    $length = (string) ($tpl['length'] ?? ($in['length'] ?? ''));
    if (!isset(TRAININGS_LENGTHS[$length])) {
        return ['ok' => false, 'message' => 'Choose how long it runs.', 'field' => 'length'];
    }
    $startsOn = trainings_date($in['starts_on'] ?? '');
    if ($length !== 'self_paced' && $startsOn === null) {
        return ['ok' => false, 'message' => 'Choose the first day.', 'field' => 'starts_on'];
    }
    $count = $tpl !== null ? count($tpl['sections']) : (int) ($in['count'] ?? 0);
    if ($length === 'days' && ($count < 2 || $count > TRAININGS_MAX_DAYS)) {
        return ['ok' => false, 'message' => 'Choose 2 to ' . TRAININGS_MAX_DAYS . ' days.', 'field' => 'count'];
    }
    if ($length === 'weeks' && ($count < 1 || $count > TRAININGS_MAX_WEEKS)) {
        return ['ok' => false, 'message' => 'Choose 1 to ' . TRAININGS_MAX_WEEKS . ' weeks.', 'field' => 'count'];
    }
    if ($length === 'one_day') {
        $count = 1;
    }
    if ($length === 'self_paced') {
        $startsOn = null;
        $count = max(1, $tpl !== null ? $count : 1);
    }

    $now = trainings_sql_dt();
    $pass = $tpl['pass_rule'] ?? TRAININGS_DEFAULT_PASS;
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT INTO trainings (title, summary, kind, official_key, length, starts_on, ends_on, status, catalogue,
                                              gives_certificate, pass_rule, department_id, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, trainings_text($in['summary'] ?? ($tpl['summary'] ?? ''), 600, true) ?: null,
                       $tpl !== null ? 'official' : 'individual', $tpl !== null ? $template : null,
                       $length, $startsOn, trainings_frame_end($length, $startsOn, $count), 'draft', 0,
                       0, trainings_json($pass), trainings_default_department($pdo, $by), $by, $now]);
        $id = (int) $pdo->lastInsertId();
        $sectionIds = trainings_make_frame($pdo, $id, $length, $startsOn, $count);
        if ($tpl !== null) {
            foreach ($tpl['sections'] as $n => $s) {
                $sid = $sectionIds[$n] ?? null;
                if ($sid === null) {
                    continue;
                }
                $pdo->prepare('UPDATE training_sections SET title = ? WHERE id = ?')->execute([$s['title'], $sid]);
                foreach ($s['items'] as $p => $item) {
                    $pdo->prepare('INSERT INTO training_items (training_id, section_id, position, type, title, minutes, required, details, created_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$id, $sid, $p + 1, $item['type'], $item['title'], $item['minutes'] ?? null, 1,
                                   trainings_json($item['details'] ?? []), $now]);
                }
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'id' => $id];
}

/** The last day a dated frame covers. */
function trainings_frame_end(string $length, ?string $startsOn, int $count): ?string
{
    if ($startsOn === null) {
        return null;
    }
    return match ($length) {
        'one_day' => $startsOn,
        'days' => trainings_add_days($startsOn, $count - 1),
        'weeks' => trainings_add_days($startsOn, 7 * $count - 1),
        default => null,
    };
}

/**
 * The empty frame: Day 1…N (dated), Week 1…N (dated, Monday-to-Sunday from
 * the first day), or Module 1 for self-paced. @return list<int> section ids
 */
function trainings_make_frame(PDO $pdo, int $trainingId, string $length, ?string $startsOn, int $count): array
{
    $ids = [];
    for ($n = 0; $n < $count; $n++) {
        [$kind, $title, $from, $to] = trainings_frame_card($length, $startsOn, $n);
        $pdo->prepare('INSERT INTO training_sections (training_id, kind, title, date_from, date_to, position) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$trainingId, $kind, $title, $from, $to, $n + 1]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    return $ids;
}

/** Card $n (0-based) of a frame: [kind, title, date_from, date_to]. */
function trainings_frame_card(string $length, ?string $startsOn, int $n): array
{
    return match ($length) {
        'one_day', 'days' => ['day', 'Day ' . ($n + 1), $startsOn !== null ? trainings_add_days($startsOn, $n) : null,
                              $startsOn !== null ? trainings_add_days($startsOn, $n) : null],
        'weeks' => ['week', 'Week ' . ($n + 1), $startsOn !== null ? trainings_add_days($startsOn, 7 * $n) : null,
                    $startsOn !== null ? trainings_add_days($startsOn, 7 * $n + 6) : null],
        default => ['module', 'Module ' . ($n + 1), null, null],
    };
}

/** The Academy department the builder serves in (owning department), if any. */
function trainings_default_department(PDO $pdo, int $userId): ?int
{
    try {
        $owners = function_exists('perm_module_owners') ? (perm_module_owners($pdo)['trainings'] ?? []) : [];
        $owners = array_values(array_filter(array_map('intval', $owners), static fn ($d) => $d > 0));
        if ($owners === []) {
            return null;
        }
        if (function_exists('perm_person_departments')) {
            $mine = array_values(array_intersect($owners, perm_person_departments($pdo, $userId)));
            if ($mine !== []) {
                return (int) $mine[0];
            }
        }
        return (int) $owners[0];
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Settings, publishing, deleting
// ---------------------------------------------------------------------------

/** Title, summary, catalogue, certificate, live attendance %, first day (dates move with it). */
function trainings_update_settings(PDO $pdo, array $t, array $in): array
{
    $title = trainings_text($in['title'] ?? $t['title'], 160);
    if ($title === '') {
        return ['ok' => false, 'message' => 'Give the training a name.', 'field' => 'title'];
    }
    $pass = $t['pass_rule'];
    if (array_key_exists('live_percent', $in)) {
        $p = (int) $in['live_percent'];
        if ($p < 0 || $p > 100) {
            return ['ok' => false, 'message' => 'Use 0 to 100.', 'field' => 'live_percent'];
        }
        $pass['live_percent'] = $p;
    }
    $startsOn = $t['starts_on'];
    $delta = 0;
    if ($t['length'] !== 'self_paced' && array_key_exists('starts_on', $in)) {
        $new = trainings_date($in['starts_on']);
        if ($new === null) {
            return ['ok' => false, 'message' => 'Choose the first day.', 'field' => 'starts_on'];
        }
        if ($startsOn !== null && $new !== $startsOn) {
            $delta = trainings_days_between($startsOn, $new);
        }
        $startsOn = $new;
    }
    $pdo->prepare('UPDATE trainings SET title = ?, summary = ?, catalogue = ?, gives_certificate = ?, pass_rule = ?, starts_on = ?,
                          ends_on = ?, updated_at = ? WHERE id = ?')
        ->execute([$title, trainings_text($in['summary'] ?? ($t['summary'] ?? ''), 600, true) ?: null,
                   !empty($in['catalogue']) ? 1 : 0, !empty($in['gives_certificate']) ? 1 : 0, trainings_json($pass),
                   $startsOn, $t['ends_on'] !== null && $delta !== 0 ? trainings_add_days((string) $t['ends_on'], $delta) : $t['ends_on'],
                   trainings_sql_dt(), $t['id']]);
    if ($delta !== 0) {
        trainings_shift_dates($pdo, (int) $t['id'], $delta);
    }
    return ['ok' => true];
}

/** Move every card date and item time of a training by $days. */
function trainings_shift_dates(PDO $pdo, int $trainingId, int $days): void
{
    $stmt = $pdo->prepare('SELECT id, date_from, date_to FROM training_sections WHERE training_id = ?');
    $stmt->execute([$trainingId]);
    $upd = $pdo->prepare('UPDATE training_sections SET date_from = ?, date_to = ? WHERE id = ?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $upd->execute([$s['date_from'] !== null ? trainings_add_days((string) $s['date_from'], $days) : null,
                       $s['date_to'] !== null ? trainings_add_days((string) $s['date_to'], $days) : null, $s['id']]);
    }
    $stmt = $pdo->prepare('SELECT id, starts_at FROM training_items WHERE training_id = ? AND starts_at IS NOT NULL');
    $stmt->execute([$trainingId]);
    $upd = $pdo->prepare('UPDATE training_items SET starts_at = ? WHERE id = ?');
    $tz = new DateTimeZone(TRAININGS_TZ);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $at = (new DateTimeImmutable((string) $i['starts_at'], $tz))->modify(($days >= 0 ? '+' : '') . $days . ' days');
        $upd->execute([trainings_sql_dt($at), $i['id']]);
    }
}

/**
 * Publish: open to the people it is assigned to (and the catalogue, if ticked).
 * Refused while it has no item, or an item still has no content.
 */
function trainings_publish(PDO $pdo, array $t): array
{
    $items = trainings_items_flat($pdo, (int) $t['id']);
    if ($items === []) {
        return ['ok' => false, 'message' => 'Add an item first.'];
    }
    $missing = array_values(array_filter($items, static fn ($i) => $i['needs_content']));
    if ($missing !== []) {
        return ['ok' => false, 'message' => count($missing) === 1
            ? 'Add the content of "' . $missing[0]['title'] . '" first.'
            : 'Add the content of ' . count($missing) . ' items first.', 'missing' => array_column($missing, 'id')];
    }
    $pdo->prepare("UPDATE trainings SET status = 'published', updated_at = ? WHERE id = ?")->execute([trainings_sql_dt(), $t['id']]);
    return ['ok' => true];
}

function trainings_set_status(PDO $pdo, array $t, string $status): void
{
    $pdo->prepare('UPDATE trainings SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, trainings_sql_dt(), $t['id']]);
}

/** Delete a training and everything under it (its uploaded files too). */
function trainings_delete(PDO $pdo, int $trainingId): void
{
    foreach (trainings_items_flat($pdo, $trainingId) as $item) {
        trainings_item_forget_files($item['details']);
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $enrol = 'SELECT id FROM training_enrolments WHERE training_id = ?';
        $pdo->prepare("DELETE FROM training_progress WHERE enrolment_id IN ({$enrol})")->execute([$trainingId]);
        $pdo->prepare("DELETE FROM training_invites WHERE enrolment_id IN ({$enrol})")->execute([$trainingId]);
        foreach (['training_enrolments', 'training_assignments', 'training_items', 'training_sections'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE training_id = ?")->execute([$trainingId]);
        }
        $pdo->prepare('DELETE FROM trainings WHERE id = ?')->execute([$trainingId]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Cards (sections)
// ---------------------------------------------------------------------------

/** Add a card at the end: the next day or week (dated), or the next module. */
function trainings_section_add(PDO $pdo, array $t, string $title = ''): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, MAX(position) AS p, MAX(date_from) AS d FROM training_sections WHERE training_id = ?');
    $stmt->execute([$t['id']]);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC);
    $n = (int) $agg['n'];
    if ($n >= TRAININGS_MAX_SECTIONS) {
        return ['ok' => false, 'message' => 'A training holds up to ' . TRAININGS_MAX_SECTIONS . ' cards.'];
    }
    $length = $t['length'] === 'one_day' ? 'days' : (string) $t['length'];
    [$kind, $auto, $from, $to] = trainings_frame_card($length, $t['starts_on'], $n);
    if ($agg['d'] !== null && $from !== null) {
        // After the last dated card, whatever was moved or removed before.
        $step = $kind === 'week' ? 7 : 1;
        $from = trainings_add_days((string) $agg['d'], $step);
        $to = $kind === 'week' ? trainings_add_days($from, 6) : $from;
    }
    $title = trainings_text($title, 120) ?: $auto;
    $pdo->prepare('INSERT INTO training_sections (training_id, kind, title, date_from, date_to, position) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$t['id'], $kind, $title, $from, $to, (int) $agg['p'] + 1]);
    $id = (int) $pdo->lastInsertId();
    if ($t['length'] === 'one_day') {
        $pdo->prepare("UPDATE trainings SET length = 'days' WHERE id = ?")->execute([$t['id']]);
    }
    trainings_refresh_end($pdo, (int) $t['id']);
    return ['ok' => true, 'id' => $id];
}

function trainings_section_update(PDO $pdo, array $t, array $s, array $in): array
{
    $title = trainings_text($in['title'] ?? $s['title'], 120);
    if ($title === '') {
        return ['ok' => false, 'message' => 'Give the card a name.', 'field' => 'title'];
    }
    $from = $s['date_from'];
    $to = $s['date_to'];
    if ($s['kind'] !== 'module' && array_key_exists('date_from', $in)) {
        $from = trainings_date($in['date_from']);
        if ($from === null) {
            return ['ok' => false, 'message' => 'Choose a date.', 'field' => 'date_from'];
        }
        $to = $s['kind'] === 'week' ? trainings_add_days($from, 6) : $from;
    }
    $pdo->prepare('UPDATE training_sections SET title = ?, date_from = ?, date_to = ? WHERE id = ?')
        ->execute([$title, $from, $to, $s['id']]);
    if ($from !== $s['date_from'] && $s['date_from'] !== null) {
        // Its timed items move with it.
        $delta = trainings_days_between((string) $s['date_from'], (string) $from);
        $stmt = $pdo->prepare('SELECT id, starts_at FROM training_items WHERE section_id = ? AND starts_at IS NOT NULL');
        $stmt->execute([$s['id']]);
        $upd = $pdo->prepare('UPDATE training_items SET starts_at = ? WHERE id = ?');
        $tz = new DateTimeZone(TRAININGS_TZ);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $upd->execute([trainings_sql_dt((new DateTimeImmutable((string) $i['starts_at'], $tz))->modify(($delta >= 0 ? '+' : '') . $delta . ' days')), $i['id']]);
        }
    }
    trainings_refresh_end($pdo, (int) $t['id']);
    return ['ok' => true];
}

/** Remove a card and its items. */
function trainings_section_delete(PDO $pdo, array $t, array $s): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_sections WHERE training_id = ?');
    $stmt->execute([$t['id']]);
    if ((int) $stmt->fetchColumn() <= 1) {
        return ['ok' => false, 'message' => 'A training keeps at least one card.'];
    }
    $stmt = $pdo->prepare('SELECT * FROM training_items WHERE section_id = ?');
    $stmt->execute([$s['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        trainings_item_delete($pdo, trainings_item_shape($row));
    }
    $pdo->prepare('DELETE FROM training_sections WHERE id = ?')->execute([$s['id']]);
    trainings_refresh_end($pdo, (int) $t['id']);
    return ['ok' => true];
}

/** Swap a card with its neighbour ($dir -1 up, +1 down). */
function trainings_section_move(PDO $pdo, array $s, int $dir): void
{
    $stmt = $pdo->prepare('SELECT id, position FROM training_sections WHERE training_id = ? ORDER BY position, id');
    $stmt->execute([$s['training_id']]);
    trainings_swap($pdo, 'training_sections', $stmt->fetchAll(PDO::FETCH_ASSOC), (int) $s['id'], $dir);
}

/** ends_on follows the last dated card. */
function trainings_refresh_end(PDO $pdo, int $trainingId): void
{
    $stmt = $pdo->prepare('SELECT MIN(date_from) AS a, MAX(COALESCE(date_to, date_from)) AS b FROM training_sections WHERE training_id = ?');
    $stmt->execute([$trainingId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r && $r['b'] !== null) {
        $pdo->prepare('UPDATE trainings SET starts_on = ?, ends_on = ? WHERE id = ?')->execute([$r['a'], $r['b'], $trainingId]);
    }
}

/** Renumber $rows (id, position in order) with $id moved one place. */
function trainings_swap(PDO $pdo, string $table, array $rows, int $id, int $dir): void
{
    $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
    $at = array_search($id, $ids, true);
    $to = $at === false ? false : $at + ($dir < 0 ? -1 : 1);
    if ($at === false || $to < 0 || $to >= count($ids)) {
        return;
    }
    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
    $upd = $pdo->prepare("UPDATE {$table} SET position = ? WHERE id = ?");
    foreach ($ids as $p => $rid) {
        $upd->execute([$p + 1, $rid]);
    }
}

// ---------------------------------------------------------------------------
// Items
// ---------------------------------------------------------------------------

/**
 * Create or change an item. $in: id (0 = new), section_id, type, title, date,
 * time, minutes, required, and the type's own fields (see trainings_item_details()).
 * $files: the request's $_FILES.
 */
function trainings_item_save(PDO $pdo, array $t, array $in, array $files = []): array
{
    $id = (int) ($in['id'] ?? 0);
    $old = $id > 0 ? trainings_item_get($pdo, $id) : null;
    if ($id > 0 && ($old === null || $old['training_id'] !== $t['id'])) {
        return ['ok' => false, 'message' => 'That item was removed. Reload the page.', 'code' => 'NOT_FOUND'];
    }
    $type = $old['type'] ?? (string) ($in['type'] ?? '');
    if (!in_array($type, trainings_builder_types(), true) && !($old !== null && isset(TRAININGS_ITEM_TYPES[$type]))) {
        return ['ok' => false, 'message' => 'Choose what kind of item it is.', 'field' => 'type'];
    }
    $title = trainings_text($in['title'] ?? '', 160);
    if ($title === '') {
        return ['ok' => false, 'message' => 'Give the item a name.', 'field' => 'title'];
    }
    $section = trainings_section_get($pdo, (int) ($in['section_id'] ?? ($old['section_id'] ?? 0)));
    if ($section === null || (int) $section['training_id'] !== $t['id']) {
        return ['ok' => false, 'message' => 'Choose the card it belongs to.', 'field' => 'section_id'];
    }
    if ($old === null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_items WHERE training_id = ?');
        $stmt->execute([$t['id']]);
        if ((int) $stmt->fetchColumn() >= TRAININGS_MAX_ITEMS) {
            return ['ok' => false, 'message' => 'A training holds up to ' . TRAININGS_MAX_ITEMS . ' items.'];
        }
    }

    // When: a time on a dated card (a week card also takes a day within it).
    $startsAt = null;
    $time = trainings_time($in['time'] ?? '');
    if (trim((string) ($in['time'] ?? '')) !== '' && $time === null) {
        return ['ok' => false, 'message' => 'Use a time like 09:30.', 'field' => 'time'];
    }
    if ($time !== null && $section['date_from'] !== null) {
        $date = (string) $section['date_from'];
        if ($section['kind'] === 'week' && trim((string) ($in['date'] ?? '')) !== '') {
            $date = trainings_date($in['date']) ?? '';
            if ($date === '' || $date < (string) $section['date_from'] || $date > (string) $section['date_to']) {
                return ['ok' => false, 'message' => 'Choose a day in this week.', 'field' => 'date'];
            }
        }
        $startsAt = $date . ' ' . $time . ':00';
    }
    $minutes = trim((string) ($in['minutes'] ?? '')) === '' ? null : (int) $in['minutes'];
    if ($minutes !== null && ($minutes < 1 || $minutes > 720)) {
        return ['ok' => false, 'message' => 'Use 1 to 720 minutes.', 'field' => 'minutes'];
    }

    $details = trainings_item_details($type, $in, $old['details'] ?? [], $files);
    if (isset($details['error'])) {
        return ['ok' => false, 'message' => $details['error'], 'field' => $details['field'] ?? null];
    }
    $required = !array_key_exists('required', $in) || !empty($in['required']) ? 1 : 0;
    $now = trainings_sql_dt();
    if ($old === null) {
        $stmt = $pdo->prepare('SELECT MAX(position) FROM training_items WHERE section_id = ?');
        $stmt->execute([$section['id']]);
        $pdo->prepare('INSERT INTO training_items (training_id, section_id, position, type, title, starts_at, minutes, required, details, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$t['id'], $section['id'], (int) $stmt->fetchColumn() + 1, $type, $title, $startsAt, $minutes, $required,
                       trainings_json($details['details']), $now]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $position = $old['position'];
        if ((int) $section['id'] !== (int) $old['section_id']) {
            $stmt = $pdo->prepare('SELECT MAX(position) FROM training_items WHERE section_id = ?');
            $stmt->execute([$section['id']]);
            $position = (int) $stmt->fetchColumn() + 1;
        }
        $pdo->prepare('UPDATE training_items SET section_id = ?, position = ?, title = ?, starts_at = ?, minutes = ?, required = ?, details = ?,
                              updated_at = ? WHERE id = ?')
            ->execute([$section['id'], $position, $title, $startsAt, $minutes, $required, trainings_json($details['details']), $now, $id]);
        foreach ($details['replaced'] as $url) {
            trainings_forget_file($url);
        }
    }
    return ['ok' => true, 'id' => $id];
}

/**
 * The details JSON of one item type, from the form (keeping an uploaded file
 * that was not replaced). @return array{details:array, replaced:list<string>}|array{error:string, field?:string}
 */
function trainings_item_details(string $type, array $in, array $old, array $files): array
{
    $replaced = [];
    switch ($type) {
        case 'live':
            $mode = (string) ($in['live_mode'] ?? ($old['mode'] ?? 'in_person'));
            if (!in_array($mode, ['in_person', 'online'], true)) {
                return ['error' => 'Choose in person or online.', 'field' => 'live_mode'];
            }
            $link = trim((string) ($in['link'] ?? ''));
            if ($link !== '' && !trainings_is_https_url($link)) {
                return ['error' => 'Paste the full link, starting https://', 'field' => 'link'];
            }
            $present = trim((string) ($in['present_minutes'] ?? '')) === '' ? 30 : (int) $in['present_minutes'];
            if ($present < 1 || $present > 720) {
                return ['error' => 'Use 1 to 720 minutes.', 'field' => 'present_minutes'];
            }
            // A pasted link lives in 'link'. meet_url / meet_event_id belong to PR 3 (the Meet it
            // creates): never written here, and kept as they are when the item is edited.
            $d = array_merge($old, ['mode' => $mode, 'venue' => trainings_text($in['venue'] ?? '', 160),
                'link' => $mode === 'online' ? mb_substr($link, 0, 500) : '', 'present_minutes' => $present]);
            return ['details' => $d, 'replaced' => $replaced];

        case 'video':
            $url = trim((string) ($in['url'] ?? ''));
            if ($url === '') {
                return ['details' => [], 'replaced' => $replaced];
            }
            $m = trainings_media_parse($url);
            if ($m === null) {
                return ['error' => 'Paste a YouTube or Spotify link.', 'field' => 'url'];
            }
            return ['details' => ['url' => mb_substr($url, 0, 500)] + $m, 'replaced' => $replaced];

        case 'audio':
            $source = (string) ($in['audio_source'] ?? 'link');
            if ($source === 'file') {
                $f = $files['audio_file'] ?? null;
                if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $up = trainings_upload($f, 'audio');
                    if (!$up['ok']) {
                        return ['error' => $up['error'], 'field' => 'audio_file'];
                    }
                    if (!empty($old['file'])) {
                        $replaced[] = (string) $old['file'];
                    }
                    return ['details' => ['source' => 'file', 'file' => $up['url'], 'name' => $up['name']], 'replaced' => $replaced];
                }
                if (($old['source'] ?? '') === 'file' && !empty($old['file'])) {
                    return ['details' => $old, 'replaced' => $replaced];
                }
                return ['error' => 'Choose an MP3 or M4A file.', 'field' => 'audio_file'];
            }
            if (($old['source'] ?? '') === 'file' && !empty($old['file'])) {
                $replaced[] = (string) $old['file'];
            }
            $url = trim((string) ($in['url'] ?? ''));
            if ($url === '') {
                return ['details' => [], 'replaced' => $replaced];
            }
            $m = trainings_media_parse($url);
            if ($m === null) {
                return ['error' => 'Paste a Spotify or YouTube link, or upload a file.', 'field' => 'url'];
            }
            return ['details' => ['source' => 'link', 'url' => mb_substr($url, 0, 500)] + $m, 'replaced' => $replaced];

        case 'reading':
            $d = ['text' => trainings_text($in['text'] ?? '', 20000, true)];
            $f = $files['pdf_file'] ?? null;
            if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = trainings_upload($f, 'pdf');
                if (!$up['ok']) {
                    return ['error' => $up['error'], 'field' => 'pdf_file'];
                }
                if (!empty($old['pdf'])) {
                    $replaced[] = (string) $old['pdf'];
                }
                $d['pdf'] = $up['url'];
                $d['pdf_name'] = $up['name'];
            } elseif (!empty($old['pdf']) && empty($in['remove_pdf'])) {
                $d['pdf'] = $old['pdf'];
                $d['pdf_name'] = $old['pdf_name'] ?? 'Reading.pdf';
            } elseif (!empty($old['pdf'])) {
                $replaced[] = (string) $old['pdf'];
            }
            return ['details' => $d, 'replaced' => $replaced];

        case 'assignment':
            $kind = (string) ($in['assignment_kind'] ?? ($old['kind'] ?? 'written'));
            if (!in_array($kind, ['written', 'sermon_review'], true)) {
                return ['error' => 'Choose the kind of assignment.', 'field' => 'assignment_kind'];
            }
            $prompts = $in['prompts'] ?? [];
            if (is_string($prompts)) {
                $prompts = explode("\n", $prompts);
            }
            $prompts = array_values(array_filter(array_map(static fn ($p) => trainings_text($p, 300), (array) $prompts),
                static fn ($p) => $p !== ''));
            if (count($prompts) > 12) {
                return ['error' => 'Use up to 12 questions.', 'field' => 'prompts'];
            }
            $max = trim((string) ($in['max_mark'] ?? '')) === '' ? 10 : (int) $in['max_mark'];
            if ($max < 1 || $max > 100) {
                return ['error' => 'Use a mark out of 1 to 100.', 'field' => 'max_mark'];
            }
            $pass = trim((string) ($in['pass_mark'] ?? '')) === '' ? (int) ceil($max / 2) : (int) $in['pass_mark'];
            if ($pass < 0 || $pass > $max) {
                return ['error' => 'The pass mark is 0 to ' . $max . '.', 'field' => 'pass_mark'];
            }
            $d = ['kind' => $kind, 'instructions' => trainings_text($in['instructions'] ?? '', 2000, true),
                  'prompts' => $prompts, 'max_mark' => $max, 'pass_mark' => $pass];
            if ($kind === 'sermon_review') {
                $oldMedia = (array) ($old['media'] ?? []);
                $source = (string) ($in['media_source'] ?? 'link');
                $f = $files['media_file'] ?? null;
                if ($source === 'file' && is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $up = trainings_upload($f, 'audio');
                    if (!$up['ok']) {
                        return ['error' => $up['error'], 'field' => 'media_file'];
                    }
                    if (!empty($oldMedia['file'])) {
                        $replaced[] = (string) $oldMedia['file'];
                    }
                    $d['media'] = ['source' => 'file', 'file' => $up['url'], 'name' => $up['name']];
                } elseif ($source === 'file' && !empty($oldMedia['file'])) {
                    $d['media'] = $oldMedia;
                } elseif ($source === 'file') {
                    return ['error' => 'Choose an MP3 or M4A file.', 'field' => 'media_file'];
                } else {
                    if (!empty($oldMedia['file'])) {
                        $replaced[] = (string) $oldMedia['file'];
                    }
                    $url = trim((string) ($in['media_url'] ?? ''));
                    if ($url !== '') {
                        $m = trainings_media_parse($url);
                        if ($m === null) {
                            return ['error' => 'Paste a Spotify or YouTube link, or upload a file.', 'field' => 'media_url'];
                        }
                        $d['media'] = ['source' => 'link', 'url' => mb_substr($url, 0, 500)] + $m;
                    }
                }
            } elseif (!empty($old['media']['file'])) {
                $replaced[] = (string) $old['media']['file'];
            }
            return ['details' => $d, 'replaced' => $replaced];

        default:
            // Types other PRs own (exam): their details are theirs; keep them.
            return ['details' => $old, 'replaced' => $replaced];
    }
}

/** Still needs its content before the training can be published. */
function trainings_item_missing(array $item): bool
{
    $d = (array) ($item['details'] ?? []);
    return match ((string) $item['type']) {
        'video' => empty($d['embed']),
        'audio' => empty($d['embed']) && empty($d['file']),
        'reading' => trim((string) ($d['text'] ?? '')) === '' && empty($d['pdf']),
        'assignment' => trim((string) ($d['instructions'] ?? '')) === '' && empty($d['prompts'])
            || (($d['kind'] ?? '') === 'sermon_review' && empty($d['media'])),
        default => false,
    };
}

function trainings_item_delete(PDO $pdo, array $item): void
{
    trainings_item_forget_files($item['details']);
    $pdo->prepare('DELETE FROM training_progress WHERE item_id = ?')->execute([$item['id']]);
    $pdo->prepare('DELETE FROM training_items WHERE id = ?')->execute([$item['id']]);
}

/** Swap an item with its neighbour in its card. */
function trainings_item_move(PDO $pdo, array $item, int $dir): void
{
    $stmt = $pdo->prepare('SELECT id, position FROM training_items WHERE section_id = ?
                            ORDER BY CASE WHEN starts_at IS NULL THEN 1 ELSE 0 END, starts_at, position, id');
    $stmt->execute([$item['section_id']]);
    trainings_swap($pdo, 'training_items', $stmt->fetchAll(PDO::FETCH_ASSOC), (int) $item['id'], $dir);
}

// ---------------------------------------------------------------------------
// Media
// ---------------------------------------------------------------------------

function trainings_is_https_url(string $url): bool
{
    return strlen($url) <= 500 && preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?(/[^\s]*)?$#', $url) === 1
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * A YouTube or Spotify link → what the page embeds. Null for anything else.
 *   YouTube: watch?v=, youtu.be/, /shorts/, /live/, /embed/ → youtube-nocookie.com/embed/ID
 *   Spotify: open.spotify.com/(episode|track|show|album|playlist)/ID → /embed/…
 *
 * @return array{provider:string, kind:string, id:string, embed:string}|null
 */
function trainings_media_parse(string $url): ?array
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) {
        return null;
    }
    $host = strtolower((string) $p['host']);
    $path = (string) ($p['path'] ?? '');
    parse_str((string) ($p['query'] ?? ''), $q);
    $yt = null;
    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $yt = explode('/', ltrim($path, '/'))[0] ?? '';
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'www.youtube-nocookie.com',
                               'youtube-nocookie.com'], true)) {
        if ($path === '/watch') {
            $yt = (string) ($q['v'] ?? '');
        } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
            $yt = $m[2];
        }
    }
    if ($yt !== null) {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $yt)) {
            return null;
        }
        $start = trainings_media_start((string) ($q['t'] ?? ($q['start'] ?? '')));
        return ['provider' => 'youtube', 'kind' => 'video', 'id' => $yt,
                'embed' => 'https://www.youtube-nocookie.com/embed/' . $yt . ($start > 0 ? '?start=' . $start : '')];
    }
    if ($host === 'open.spotify.com'
        && preg_match('#^/(?:intl-[a-z]{2}(?:-[A-Za-z]{2})?/)?(?:embed/)?(episode|track|show|album|playlist)/([A-Za-z0-9]{22})/?$#', $path, $m)) {
        return ['provider' => 'spotify', 'kind' => $m[1], 'id' => $m[2], 'embed' => 'https://open.spotify.com/embed/' . $m[1] . '/' . $m[2]];
    }
    return null;
}

/** "90", "1m30s", "1h2m3s" → seconds. */
function trainings_media_start(string $t): int
{
    if (preg_match('/^\d+$/', $t)) {
        return (int) $t;
    }
    if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $t, $m) && $t !== '') {
        return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
    }
    return 0;
}

/**
 * Store an uploaded audio file (MP3/M4A, ≤ 50 MB) or reading (PDF, ≤ 20 MB).
 * @return array{ok:bool, url?:string, name?:string, error?:string}
 */
function trainings_upload(array $file, string $kind): array
{
    $max = $kind === 'pdf' ? TRAININGS_PDF_MAX_BYTES : TRAININGS_AUDIO_MAX_BYTES;
    $allowed = $kind === 'pdf' ? ['pdf'] : ['mp3', 'm4a'];
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > $max) {
        return ['ok' => false, 'error' => 'The file is too big. Use up to ' . (int) ($max / 1048576) . ' MB.'];
    }
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The file did not arrive. Try again.'];
    }
    $ext = hod_upload_extension((string) ($file['name'] ?? ''), $allowed);
    if ($ext === '' || !trainings_sniff((string) $file['tmp_name'], $ext)) {
        return ['ok' => false, 'error' => $kind === 'pdf' ? 'Choose a PDF file.' : 'Choose an MP3 or M4A file.'];
    }
    $dir = dirname(__DIR__, 2) . rtrim(TRAININGS_UPLOAD_URL, '/');
    $stored = hod_upload_store($file, $dir, TRAININGS_UPLOAD_URL, $allowed);
    if (!$stored['ok']) {
        return ['ok' => false, 'error' => (string) $stored['error']];
    }
    $name = trainings_text(pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME), 80);
    return ['ok' => true, 'url' => (string) $stored['url'], 'name' => ($name !== '' ? $name : 'File') . '.' . $ext];
}

/** Do the first bytes say this really is an MP3 / M4A / PDF? */
function trainings_sniff(string $path, string $ext): bool
{
    $h = @fopen($path, 'rb');
    if ($h === false) {
        return false;
    }
    $head = (string) fread($h, 16);
    fclose($h);
    return trainings_sniff_bytes($head, $ext);
}

function trainings_sniff_bytes(string $head, string $ext): bool
{
    return match ($ext) {
        'pdf' => str_starts_with($head, '%PDF-'),
        'mp3' => str_starts_with($head, 'ID3') || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0),
        'm4a' => strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp',
        default => false,
    };
}

/** An uploaded file of ours (random name under /uploads/trainings/), removed from disk. */
function trainings_forget_file(string $url): void
{
    if (!preg_match('#^/uploads/trainings/(\d{8}_[0-9a-f]{16}\.(mp3|m4a|pdf))$#', $url, $m)) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/uploads/trainings/' . $m[1];
    if (is_file($path)) {
        @unlink($path);
    }
}

function trainings_item_forget_files(array $details): void
{
    foreach ([$details['file'] ?? null, $details['pdf'] ?? null, $details['media']['file'] ?? null] as $url) {
        if (is_string($url) && $url !== '') {
            trainings_forget_file($url);
        }
    }
}

// ---------------------------------------------------------------------------
// Templates: Membership Class and Maturity Class (owner's answers 2 and 4)
// ---------------------------------------------------------------------------

/**
 * Ready frames for the two official classes. Titles only: the Academy adds the
 * links, readings and venues, renames anything, and publishes. Confirming a
 * graduate moves Membership Class to Member; Maturity Class marks them ready
 * to serve (progress.php).
 */
function trainings_templates(): array
{
    $live = static fn (string $title): array => ['type' => 'live', 'title' => $title, 'minutes' => 90,
        'details' => ['mode' => 'in_person', 'venue' => '', 'present_minutes' => 30]];
    $review = ['type' => 'assignment', 'title' => 'Sermon review', 'details' => ['kind' => 'sermon_review', 'instructions' => '',
        'prompts' => ['The main points', 'The scriptures used', 'How it applies to me'], 'max_mark' => 10, 'pass_mark' => 5]];
    return [
        'membership' => [
            'title' => 'Membership Class',
            'summary' => 'Becoming a member of HOD Lekki.',
            'length' => 'weeks',
            'pass_rule' => ['required' => 'all', 'live_percent' => 75],
            'sections' => [
                ['title' => 'Welcome home', 'items' => [$live('Class 1'), ['type' => 'reading', 'title' => 'Our vision', 'details' => []]]],
                ['title' => 'Salvation and baptism', 'items' => [$live('Class 2'), ['type' => 'video', 'title' => 'Teaching', 'details' => []]]],
                ['title' => 'Life in the church', 'items' => [$live('Class 3'), ['type' => 'reading', 'title' => 'How we serve', 'details' => []]]],
                ['title' => 'Becoming a member', 'items' => [$live('Class 4'), ['type' => 'assignment', 'title' => 'Why I am joining',
                    'details' => ['kind' => 'written', 'instructions' => '', 'prompts' => ['What drew you to HOD Lekki?', 'Where would you like to serve?'],
                                  'max_mark' => 10, 'pass_mark' => 5]]]],
            ],
        ],
        'maturity' => [
            'title' => 'Maturity Class',
            'summary' => 'Growing in faith and getting ready to serve.',
            'length' => 'weeks',
            'pass_rule' => ['required' => 'all', 'live_percent' => 75],
            'sections' => [
                ['title' => 'Foundations', 'items' => [$live('Class 1')]],
                ['title' => 'The Word and prayer', 'items' => [$live('Class 2'), $review]],
                ['title' => 'The Holy Spirit', 'items' => [$live('Class 3'), ['type' => 'reading', 'title' => 'Reading', 'details' => []]]],
                ['title' => 'Character', 'items' => [$live('Class 4'), $review]],
                ['title' => 'Stewardship', 'items' => [$live('Class 5')]],
                ['title' => 'Ready to serve', 'items' => [$live('Class 6'), $review]],
            ],
        ],
    ];
}
