<?php
// /includes/hymnal/member.php
//
// What a member marks and remembers. All of it is private to them.
//
// Every function here takes the caller's user id and every query filters on it,
// updates and deletes included, exactly as the Bible module does. There is no
// view of one member's favourites for anybody else — not for a pastor, not for
// an administrator — and none should be added: what a person sings alone in the
// morning is between them and God.
//
// All of it also degrades: the library is readable with no tables at all, so
// each function answers "not ready" rather than failing when the migration has
// not run. A member who cannot save a favourite can still read every hymn.

/** Favourites and reading position are keyed by HOD number, so they work the
 *  same for a generated hymn and for one the Studio added. */
function hymnal_state_get(?PDO $pdo, int $userId): array
{
    $default = [
        'mode'        => 'congregation',
        'last_number' => null,
        'lang'        => 'en',
        'prefs'       => hymnal_default_prefs(),
    ];
    if ($pdo === null || $userId <= 0 || !hymnal_table_exists($pdo, 'hymnal_user_state')) {
        return $default;
    }
    try {
        $stmt = $pdo->prepare('SELECT mode, last_number, lang, prefs FROM hymnal_user_state WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Hymnary state: ' . $e->getMessage());

        return $default;
    }
    if (!$row) {
        return $default;
    }
    $prefs = json_decode((string) $row['prefs'], true);

    return [
        'mode'        => in_array($row['mode'], ['congregation', 'choir'], true) ? $row['mode'] : 'congregation',
        'last_number' => $row['last_number'] === null ? null : (int) $row['last_number'],
        'lang'        => (string) ($row['lang'] ?: 'en'),
        'prefs'       => is_array($prefs) ? $prefs + hymnal_default_prefs() : hymnal_default_prefs(),
    ];
}

/** The reader's defaults. Big text and a paper page, because the first use we
 *  were asked for is a phone held at arm's length during morning devotions. */
function hymnal_default_prefs(): array
{
    return [
        'text_size'   => 3,      // 1-6
        'page'        => 'paper', // paper | white | night
        'show_chords' => false,
        'show_solfa'  => true,
        'voice'       => 'all',   // all | S | A | T | B
        'tempo'       => 100,     // per cent of the written tempo
        'instrument'  => 'acoustic_grand_piano',
    ];
}

function hymnal_state_save(PDO $pdo, int $userId, array $patch): bool
{
    if ($userId <= 0 || !hymnal_table_exists($pdo, 'hymnal_user_state')) {
        return false;
    }
    $now = hymnal_state_get($pdo, $userId);

    if (isset($patch['mode']) && in_array($patch['mode'], ['congregation', 'choir'], true)) {
        $now['mode'] = $patch['mode'];
    }
    if (array_key_exists('last_number', $patch)) {
        $n = (int) $patch['last_number'];
        $now['last_number'] = $n > 0 ? $n : null;
    }
    if (isset($patch['lang']) && preg_match('/^[a-z]{2,8}$/', (string) $patch['lang'])) {
        $now['lang'] = (string) $patch['lang'];
    }
    if (isset($patch['prefs']) && is_array($patch['prefs'])) {
        $now['prefs'] = hymnal_clean_prefs($patch['prefs'] + $now['prefs']);
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE hymnal_user_state SET mode = ?, last_number = ?, lang = ?, prefs = ?, updated_at = ?
              WHERE user_id = ?'
        );
        $stmt->execute([
            $now['mode'], $now['last_number'], $now['lang'],
            json_encode($now['prefs'], JSON_UNESCAPED_UNICODE), hymnal_now(), $userId,
        ]);
        if ($stmt->rowCount() === 0) {
            // No ON DUPLICATE KEY / INSERT IGNORE: the tests run on SQLite.
            $ins = $pdo->prepare(
                'INSERT INTO hymnal_user_state (user_id, mode, last_number, lang, prefs, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $userId, $now['mode'], $now['last_number'], $now['lang'],
                json_encode($now['prefs'], JSON_UNESCAPED_UNICODE), hymnal_now(),
            ]);
        }
    } catch (Throwable $e) {
        error_log('Hymnary state save: ' . $e->getMessage());

        return false;
    }

    return true;
}

/** Keep stored preferences inside the ranges the reader can actually render. */
function hymnal_clean_prefs(array $p): array
{
    $d = hymnal_default_prefs();

    return [
        'text_size'   => max(1, min(6, (int) ($p['text_size'] ?? $d['text_size']))),
        'page'        => in_array($p['page'] ?? '', ['paper', 'white', 'night'], true) ? $p['page'] : $d['page'],
        'show_chords' => !empty($p['show_chords']),
        'show_solfa'  => !empty($p['show_solfa']),
        'voice'       => in_array($p['voice'] ?? '', ['all', 'S', 'A', 'T', 'B'], true) ? $p['voice'] : $d['voice'],
        'tempo'       => max(50, min(150, (int) ($p['tempo'] ?? $d['tempo']))),
        'instrument'  => in_array($p['instrument'] ?? '', ['acoustic_grand_piano', 'choir_aahs'], true)
            ? $p['instrument']
            : $d['instrument'],
    ];
}

/** @return list<int> HOD numbers this member has marked, newest first */
function hymnal_favourites(?PDO $pdo, int $userId): array
{
    if ($pdo === null || $userId <= 0 || !hymnal_table_exists($pdo, 'hymnal_favourites')) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT song_number FROM hymnal_favourites WHERE user_id = ? ORDER BY created_at DESC, song_number ASC'
        );
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        error_log('Hymnary favourites: ' . $e->getMessage());

        return [];
    }
}

/**
 * Mark or unmark a hymn.
 *
 * @return array{ok:bool, on:bool, code?:string, message?:string}
 */
function hymnal_favourite_toggle(PDO $pdo, int $userId, int $number, bool $on): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'on' => false, 'code' => 'FORBIDDEN', 'message' => 'Please sign in again.'];
    }
    if (!hymnal_table_exists($pdo, 'hymnal_favourites')) {
        return ['ok' => false, 'on' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'Saving favourites is not switched on yet.'];
    }
    try {
        if (!$on) {
            // The user_id filter is on the delete too, not just the read.
            $stmt = $pdo->prepare('DELETE FROM hymnal_favourites WHERE user_id = ? AND song_number = ?');
            $stmt->execute([$userId, $number]);

            return ['ok' => true, 'on' => false];
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM hymnal_favourites WHERE user_id = ?');
        $count->execute([$userId]);
        if ((int) $count->fetchColumn() >= HYMNAL_MAX_FAVOURITES) {
            return [
                'ok'      => false,
                'on'      => false,
                'code'    => 'TOO_MANY',
                'message' => 'You have marked the most hymns we can keep. Remove one first.',
            ];
        }
        $has = $pdo->prepare('SELECT 1 FROM hymnal_favourites WHERE user_id = ? AND song_number = ?');
        $has->execute([$userId, $number]);
        if ($has->fetchColumn() === false) {
            $ins = $pdo->prepare('INSERT INTO hymnal_favourites (user_id, song_number, created_at) VALUES (?, ?, ?)');
            $ins->execute([$userId, $number, hymnal_now()]);
        }

        return ['ok' => true, 'on' => true];
    } catch (Throwable $e) {
        error_log('Hymnary favourite: ' . $e->getMessage());

        return ['ok' => false, 'on' => false, 'code' => 'ERROR', 'message' => 'That did not save. Please try again.'];
    }
}

/** @return list<int> HOD numbers this member opened lately, newest first */
function hymnal_recent(?PDO $pdo, int $userId, int $limit = 8): array
{
    if ($pdo === null || $userId <= 0 || !hymnal_table_exists($pdo, 'hymnal_recent')) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->prepare(
            'SELECT song_number FROM hymnal_recent WHERE user_id = ? ORDER BY opened_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        error_log('Hymnary recent: ' . $e->getMessage());

        return [];
    }
}

/** Note that a member opened a hymn. One row per hymn per member, moved
 *  forward rather than appended, so the list stays short on its own. */
function hymnal_recent_touch(PDO $pdo, int $userId, int $number): void
{
    if ($userId <= 0 || $number <= 0 || !hymnal_table_exists($pdo, 'hymnal_recent')) {
        return;
    }
    try {
        $stmt = $pdo->prepare('UPDATE hymnal_recent SET opened_at = ? WHERE user_id = ? AND song_number = ?');
        $stmt->execute([hymnal_now(), $userId, $number]);
        if ($stmt->rowCount() === 0) {
            $ins = $pdo->prepare('INSERT INTO hymnal_recent (user_id, song_number, opened_at) VALUES (?, ?, ?)');
            $ins->execute([$userId, $number, hymnal_now()]);
        }
    } catch (Throwable $e) {
        error_log('Hymnary recent touch: ' . $e->getMessage());
    }
}
