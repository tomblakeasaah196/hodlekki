<?php
// /includes/trainings/notes.php
//
// My notes (owner, 13 Nov 2026): one running note per learner and training,
// typed in the floating notes panel (ui_notetaker(), docs/UI.md) on the
// training's page, saved as they type, downloadable as a Word file.
//
// The note is the learner's alone. No Academy screen, report, export or AI
// reads it: only note_get / note_save (actions.php) for the signed-in person
// and api/trainings_notes_doc.php for their own Word copy.
//
// Taking notes follows the page: whoever may open the training (a learner on
// it or browsing the catalogue, or the Academy previewing it) may write one.
// Reading back and downloading need only that the note is theirs, so notes
// stay theirs after the training ends or is archived.

require_once dirname(__DIR__) . '/docx.php';

const TRAININGS_NOTE_MAX = 20000;

/** True once the notes migration has run (the panel is offered only then). */
function trainings_notes_ready(PDO $pdo): bool
{
    return trainings_ready($pdo) && trainings_table_exists($pdo, 'training_notes');
}

/** May $uid open this training's page, and so take notes on it? The same rule as the 'view' action. */
function trainings_note_can_take(PDO $pdo, array $t, int $uid): bool
{
    if (trainings_staff_can('view')) {
        return true;
    }
    $e = trainings_enrolment_for($pdo, (int) $t['id'], $uid);
    $open = $t['status'] === 'published' && (($e !== null && $e['status'] !== 'withdrawn') || !empty($t['catalogue']));
    return $open && trainings_self_can($uid, 'view');
}

/** The person's note on a training: ['body', 'updated_at'], or null before the first save. */
function trainings_note_get(PDO $pdo, int $trainingId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT body, updated_at FROM training_notes WHERE training_id = ? AND user_id = ?');
    $stmt->execute([$trainingId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['body' => (string) $row['body'], 'updated_at' => (string) $row['updated_at']] : null;
}

/** What is kept of a note: Unix line ends, no control characters but tab and newline. */
function trainings_note_clean(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
}

/** Save the whole note as typed. @return array ['ok' => true, 'updated_at'] or ['ok' => false, 'message'] */
function trainings_note_save(PDO $pdo, int $trainingId, int $userId, string $body): array
{
    $body = trainings_note_clean($body);
    if (!mb_check_encoding($body, 'UTF-8')) {
        return ['ok' => false, 'message' => 'That text could not be read. Try again.'];
    }
    if (mb_strlen($body) > TRAININGS_NOTE_MAX) {
        return ['ok' => false, 'message' => 'Notes can be up to 20,000 characters.', 'code' => 'TOO_LONG'];
    }
    $now = trainings_sql_dt();
    $upd = $pdo->prepare('UPDATE training_notes SET body = ?, updated_at = ? WHERE training_id = ? AND user_id = ?');
    $upd->execute([$body, $now, $trainingId, $userId]);
    if ($upd->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO training_notes (training_id, user_id, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$trainingId, $userId, $body, $now, $now]);
        } catch (PDOException $e) {
            // Saved a moment ago from another tab: the row is there now.
            $upd->execute([$body, $now, $trainingId, $userId]);
        }
    }
    return ['ok' => true, 'updated_at' => $now];
}

/** The Word copy of a note: the training's title, the person and the date, then the note. */
function trainings_note_docx(array $t, string $personName, array $note): string
{
    $when = $note['updated_at'] !== '' ? (new DateTimeImmutable(substr($note['updated_at'], 0, 10), new DateTimeZone(TRAININGS_TZ)))->format('j M Y') : '';
    return docx_write([
        'title' => (string) $t['title'],
        'subtitle' => implode(' · ', array_filter(['My notes', $personName, $when !== '' ? 'Saved ' . $when : ''])),
        'body' => $note['body'],
        'author' => $personName,
    ]);
}
