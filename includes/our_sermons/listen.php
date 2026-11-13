<?php
// /includes/our_sermons/listen.php
//
// Our sermons, PR S2: the members' listening page (owner, 13 Nov 2026).
// Plan: OUR-SERMONS-PLAN.md (shared notes). The sermons themselves are PR S1's
// (`our_sermons`, Envision's manager, the list and calendar); this file adds
// what a member does on one sermon:
//
//   our_sermon_notes     private notes, each stamped with a moment in the
//                        sermon (tap to jump back), editable, deletable, and
//                        emailed to the member on request
//   our_sermon_progress  where they stopped, so the page carries on there,
//                        and when they first heard it to the end
//
// Rules:
//   * A note is the member's alone. Only this file touches our_sermon_notes;
//     every query filters on the caller's user id (updates and deletes too).
//     No Envision screen, report, export or AI reads it; tests/our_sermons/
//     listen.php fails if any other file mentions the table.
//   * "Email my notes" goes through the outbox (email_send_doc(), purpose
//     account) to the member's own address or one they type, at most
//     OUR_SERMON_EMAILS_PER_DAY a day and one a minute, counted from the
//     outbox itself.
//   * Degrade before the migration: our_sermon_listen_ready() first, then
//     FEATURE_NOT_READY, never a fatal.
//   * SQL stays portable to SQLite (the tests): no ON DUPLICATE KEY, no
//     INSERT IGNORE.
//
// Loaded after includes/db.php; brings in S1's includes/our_sermons/bootstrap.php
// (our_sermons_get(), the labels, our_sermons_media_hosts()). References need
// includes/bible/bootstrap.php and email includes/campaigns/bootstrap.php;
// without them those parts say they are not ready.

require_once __DIR__ . '/bootstrap.php';

const OUR_SERMON_NOTE_MAX          = 2000;    // characters in one note
const OUR_SERMON_NOTES_PER_SERMON  = 300;     // notes per member and sermon
const OUR_SERMON_EMAILS_PER_DAY    = 5;       // "Email my notes", per member, all sermons
const OUR_SERMON_EMAIL_GAP_SECONDS = 60;      // between two of them
const OUR_SERMON_MAX_SECONDS       = 86400;   // no sermon is longer than a day
const OUR_SERMON_EMAIL_FLOW        = 'our_sermon_note_email';
const OUR_SERMON_TABLES            = ['our_sermon_notes', 'our_sermon_progress'];
const OUR_SERMON_YOUTUBE_ORIGIN    = 'https://www.youtube-nocookie.com';

// ---------------------------------------------------------------------------
// Readiness, clock
// ---------------------------------------------------------------------------

/** Have PR S1's tables and this PR's two tables arrived? */
function our_sermon_listen_ready(?PDO $pdo): bool
{
    if ($pdo === null || !our_sermons_ready($pdo)) {
        return false;
    }
    foreach (OUR_SERMON_TABLES as $table) {
        if (!our_sermons_table_exists($pdo, $table)) {
            return false;
        }
    }

    return true;
}

/** Lagos time; tests set $GLOBALS['our_sermon_now']. */
function our_sermon_now(): DateTimeImmutable
{
    $now = $GLOBALS['our_sermon_now'] ?? null;

    return $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
}

function our_sermon_sql_now(): string
{
    return our_sermon_now()->format('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Access and the sermon
// ---------------------------------------------------------------------------

/** May the person signed in open the module at all? */
function our_sermon_can_view(): bool
{
    return function_exists('can') && can('our_sermons', 'view');
}

/** Envision (edit) may open a draft or hidden sermon to check it (as S1's pages decide). */
function our_sermon_can_preview(): bool
{
    return function_exists('can') && can('our_sermons', 'edit', 'church');
}

/**
 * One sermon (S1's our_sermons_get(): lists decoded), or null when it does not exist or the member may not
 * see it: published for everyone; drafts and hidden ones only with $preview.
 */
function our_sermon_listen_get(PDO $pdo, int $id, bool $preview = false): ?array
{
    if ($id <= 0 || !our_sermons_ready($pdo)) {
        return null;
    }
    $row = our_sermons_get($pdo, $id);
    if ($row === null) {
        return null;
    }
    if ((string) ($row['status'] ?? '') !== 'published' && !$preview) {
        return null;
    }

    return $row;
}

/** The sermon the person signed in may open (published, or a preview for Envision). */
function our_sermon_visible(PDO $pdo, int $id): ?array
{
    return our_sermon_listen_get($pdo, $id, our_sermon_can_preview());
}

/** "12:34", "1:02:03". */
function our_sermon_fmt_time(?int $seconds): string
{
    $s = max(0, (int) $seconds);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $r = $s % 60;

    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $r) : sprintf('%d:%02d', $m, $r);
}

/** A JSON column (or an already decoded value) as a list. */
function our_sermon_json_list($value): array
{
    if (is_string($value)) {
        $value = trim($value) === '' ? [] : json_decode($value, true);
    }

    return is_array($value) ? array_values($value) : [];
}

/** The 11-character YouTube id, from youtube_id or youtube_url. */
function our_sermon_youtube_id(array $row): ?string
{
    $id = trim((string) ($row['youtube_id'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
        return $id;
    }
    $url = (string) ($row['youtube_url'] ?? '');
    if ($url !== '' && preg_match('#(?:youtu\.be/|[?&]v=|/(?:embed|shorts|live)/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])#', $url, $m)) {
        return $m[1];
    }

    return null;
}

/** An https link on one of $hosts, else null. */
function our_sermon_https_url(?string $url, array $hosts = []): ?string
{
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 1000 || preg_match('/[\s<>"\\\\]/', $url)) {
        return null;
    }
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    if ($hosts !== [] && !in_array(strtolower((string) $p['host']), $hosts, true)) {
        return null;
    }

    return $url;
}

/** The audio the page may play: an https URL, or a file on this site. */
function our_sermon_audio_url(?string $url): ?string
{
    $url = trim((string) $url);
    if (preg_match('#^/uploads/[A-Za-z0-9_][A-Za-z0-9/_.-]*\.(?:mp3|m4a|aac|ogg|oga|wav)$#i', $url) && !str_contains($url, '..')) {
        return $url;
    }

    return our_sermon_https_url($url);
}

/**
 * The audio's type, for <source type>. The Lekki feed's CDN answers
 * application/octet-stream; a declared type lets the browser (Safari's
 * AVFoundation in particular) decode it anyway. Read from the file's extension,
 * also when the real file is carried inside an anchor.fm play URL; podcasts
 * are MP3 when nothing says otherwise.
 */
function our_sermon_audio_type(?string $url): string
{
    $path = strtolower(rawurldecode((string) parse_url((string) $url, PHP_URL_PATH)));
    $types = ['m4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav'];
    foreach ($types as $ext => $type) {
        if (str_ends_with($path, '.' . $ext)) {
            return $type;
        }
    }

    return 'audio/mpeg';
}

/** Artwork is copied to this site by S1 (img-src stays 'self'); anything else is not shown. */
function our_sermon_artwork_url(?string $url): ?string
{
    $url = trim((string) $url);

    return preg_match('#^/(?:uploads|assets)/[A-Za-z0-9_][A-Za-z0-9/_.-]*\.(?:jpe?g|png|webp|gif)$#i', $url) && !str_contains($url, '..')
        ? $url : null;
}

// ---------------------------------------------------------------------------
// Scripture references: key scriptures, and the ones typed in notes
// ---------------------------------------------------------------------------

/**
 * A reference the Bible panel can open, or null.
 *
 * @return array{b:int, c:int, v:?int, ve:?int, ce:int, label:string}|null
 */
function our_sermon_ref(string $text): ?array
{
    if (!function_exists('bible_parse_ref')) {
        return null;
    }
    $p = bible_parse_ref($text);
    if ($p === null) {
        return null;
    }

    return [
        'b'     => (int) $p['book'],
        'c'     => (int) $p['chapter'],
        'v'     => $p['verse'] !== null ? (int) $p['verse'] : null,
        've'    => $p['verse_end'] !== null ? (int) $p['verse_end'] : null,
        'ce'    => (int) $p['chapter_end'],
        'label' => bible_format_ref((int) $p['book'], (int) $p['chapter'], $p['verse'], (int) $p['chapter_end'], $p['verse_end']),
    ];
}

/**
 * Every reference in a piece of free text, as [start byte, end byte, ref].
 * A reference is a book name (up to four words, "Song of Solomon", "1 Cor.")
 * right before a chapter, chapter:verse or a range. Without a verse the book
 * must start with a capital, so "I am 5 minutes late" stays text.
 *
 * @return list<array{0:int, 1:int, 2:array}>
 */
function our_sermon_find_refs(string $text): array
{
    if ($text === '' || !function_exists('bible_parse_ref')) {
        return [];
    }
    if (!preg_match_all('/(?<![\d:.])\d{1,3}(?:\s*[:.]\s*\d{1,3})?(?:\s*[-–]\s*\d{1,3}(?:\s*[:.]\s*\d{1,3})?)?(?![\d:])/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $found = [];
    $taken = 0;
    foreach ($m[0] as [$num, $at]) {
        if ($at < $taken) {
            continue;
        }
        $from = max($taken, $at - 48);
        $before = substr($text, $from, $at - $from);
        if ($before === '' || !preg_match('/\s$/u', $before)) {
            continue;
        }
        preg_match_all('/\S+/u', $before, $tok, PREG_OFFSET_CAPTURE);
        $tokens = $tok[0];
        $n = count($tokens);
        $hasVerse = (bool) preg_match('/\d\s*[:.]\s*\d/', $num);
        for ($k = min(4, $n); $k >= 1; $k--) {
            $start = $from + $tokens[$n - $k][1];
            $phrase = substr($text, $start, $at - $start);
            // An opening bracket or quote is not part of the name.
            if (preg_match('/^[(\[{"\'“‘]+/u', $phrase, $lead)) {
                $start += strlen($lead[0]);
                $phrase = substr($phrase, strlen($lead[0]));
            }
            if (!preg_match('/^(?:[1-3]\s*)?(\p{L})/u', $phrase, $first)) {
                continue;
            }
            if (!$hasVerse && !preg_match('/^\p{Lu}$/u', $first[1])) {
                continue;
            }
            $ref = our_sermon_ref(trim($phrase) . ' ' . $num);
            if ($ref !== null) {
                $end = $at + strlen($num);
                $found[] = [$start, $end, $ref];
                $taken = $end;
                break;
            }
        }
    }

    return $found;
}

/**
 * Text cut into plain runs and references, for the browser to build with
 * textContent (never innerHTML): [{t}, {t, ref}, …].
 *
 * @return list<array{t:string, ref?:array}>
 */
function our_sermon_text_segments(string $text): array
{
    $out = [];
    $pos = 0;
    foreach (our_sermon_find_refs($text) as [$start, $end, $ref]) {
        if ($start > $pos) {
            $out[] = ['t' => substr($text, $pos, $start - $pos)];
        }
        $out[] = ['t' => substr($text, $start, $end - $start), 'ref' => $ref];
        $pos = $end;
    }
    if ($pos < strlen($text) || $out === []) {
        $out[] = ['t' => substr($text, $pos)];
    }

    return $out;
}

/** The ref's data attributes for a button that opens the Bible panel. */
function our_sermon_ref_attrs(array $ref): string
{
    $attrs = ['data-action' => 'os.ref', 'data-b' => $ref['b'], 'data-c' => $ref['c'], 'data-ce' => $ref['ce'],
        'data-v' => $ref['v'] ?? '', 'data-ve' => $ref['ve'] ?? ''];
    $html = '';
    foreach ($attrs as $name => $value) {
        $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
    }

    return $html;
}

/** The Bible module at a reference (what an inline reference opens without the script). */
function our_sermon_ref_href(array $ref): string
{
    return '/modules/bible/#read=' . (int) $ref['b'] . '.' . (int) $ref['c'] . (!empty($ref['v']) ? '.' . (int) $ref['v'] : '');
}

/**
 * Free text as HTML: escaped, references as links that open the Bible panel
 * (links, not buttons, so they wrap with the words around them).
 */
function our_sermon_rich_html(string $text): string
{
    $html = '';
    foreach (our_sermon_text_segments($text) as $seg) {
        $t = htmlspecialchars($seg['t'], ENT_QUOTES, 'UTF-8');
        $html .= isset($seg['ref'])
            ? '<a class="osl-ref" href="' . htmlspecialchars(our_sermon_ref_href($seg['ref']), ENT_QUOTES, 'UTF-8') . '"' . our_sermon_ref_attrs($seg['ref']) . '>' . $t . '</a>'
            : $t;
    }

    return $html;
}

/**
 * Key scriptures as S1/S3 store them: a list of strings ("Jude 1:3") or of
 * objects {ref|reference|label, note|text}. Unreadable ones keep their words
 * and simply do not open the panel.
 *
 * @return list<array{text:string, note:string, ref:?array}>
 */
function our_sermon_scripture_list($value): array
{
    $out = [];
    foreach (our_sermon_json_list($value) as $item) {
        $text = '';
        $note = '';
        if (is_string($item)) {
            $text = $item;
        } elseif (is_array($item)) {
            $text = (string) ($item['ref'] ?? $item['reference'] ?? $item['label'] ?? '');
            $note = (string) ($item['note'] ?? $item['text'] ?? $item['why'] ?? '');
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || mb_strlen($text, 'UTF-8') > 80) {
            continue;
        }
        $ref = our_sermon_ref($text);
        $out[] = ['text' => $ref['label'] ?? $text, 'note' => trim($note), 'ref' => $ref];
        if (count($out) >= 30) {
            break;
        }
    }

    return $out;
}

/**
 * Key points: a list of strings, or of objects {point|title|text, detail}.
 *
 * @return list<array{text:string, detail:string}>
 */
function our_sermon_key_points($value): array
{
    $out = [];
    foreach (our_sermon_json_list($value) as $item) {
        $text = '';
        $detail = '';
        if (is_string($item)) {
            $text = $item;
        } elseif (is_array($item)) {
            $text = (string) ($item['point'] ?? $item['title'] ?? $item['text'] ?? '');
            $detail = (string) ($item['detail'] ?? $item['body'] ?? $item['explanation'] ?? '');
        }
        $text = trim($text);
        if ($text === '') {
            continue;
        }
        $out[] = ['text' => $text, 'detail' => trim($detail)];
        if (count($out) >= 20) {
            break;
        }
    }

    return $out;
}

/** The summary as paragraphs (blank lines split them). */
function our_sermon_paragraphs(?string $text): array
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
    if ($text === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: []), static fn ($p) => $p !== ''));
}

/**
 * What the page and its script need about a sermon, cleaned: only https links
 * and local artwork survive.
 */
function our_sermon_view(array $row): array
{
    $duration = (int) ($row['duration_seconds'] ?? 0);

    return [
        'id'          => (int) $row['id'],
        'title'       => trim((string) ($row['title'] ?? '')),
        'preacher'    => trim((string) ($row['preacher'] ?? '')),
        'date'        => (string) ($row['date_preached'] ?? ''),
        'date_label'  => our_sermons_date_label($row['date_preached'] ?? null),
        'length'      => our_sermons_length_label($duration > 0 ? $duration : null),
        'service'     => our_sermons_service_label($row['service'] ?? null),
        'series'      => trim((string) ($row['series'] ?? '')),
        'summary'     => our_sermon_paragraphs($row['summary'] ?? null),
        'key_points'  => our_sermon_key_points($row['key_points'] ?? null),
        'scriptures'  => our_sermon_scripture_list($row['scriptures'] ?? null),
        'artwork'     => our_sermon_artwork_url($row['artwork_url'] ?? null),
        'audio'       => our_sermon_audio_url($row['audio_url'] ?? null),
        'duration'    => $duration > 0 && $duration <= OUR_SERMON_MAX_SECONDS ? $duration : null,
        'spotify'     => our_sermon_https_url($row['spotify_url'] ?? null, ['open.spotify.com']),
        'youtube_id'  => our_sermon_youtube_id($row),
        'status'      => (string) ($row['status'] ?? ''),
    ];
}

// ---------------------------------------------------------------------------
// Progress: carry on where you stopped
// ---------------------------------------------------------------------------

/** @return array{position:int, finished:bool, finished_at:?string} */
function our_sermon_progress_get(PDO $pdo, int $userId, int $sermonId): array
{
    $none = ['position' => 0, 'finished' => false, 'finished_at' => null];
    if ($userId <= 0 || !our_sermons_table_exists($pdo, 'our_sermon_progress')) {
        return $none;
    }
    $stmt = $pdo->prepare('SELECT position_seconds, finished_at FROM our_sermon_progress WHERE sermon_id = ? AND user_id = ?');
    $stmt->execute([$sermonId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $none;
    }

    return [
        'position'    => max(0, (int) $row['position_seconds']),
        'finished'    => $row['finished_at'] !== null,
        'finished_at' => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
    ];
}

/**
 * Save where the member is. $finished (they reached the end) stamps
 * finished_at the first time and sets the position back to the start, so the
 * next visit plays from the top. A position is clamped to the sermon's length.
 */
function our_sermon_progress_put(PDO $pdo, int $userId, array $sermon, $position, bool $finished): array
{
    $sermonId = (int) $sermon['id'];
    $max = (int) ($sermon['duration_seconds'] ?? 0);
    $max = $max > 0 && $max <= OUR_SERMON_MAX_SECONDS ? $max : OUR_SERMON_MAX_SECONDS;
    $pos = is_numeric($position) ? (int) floor((float) $position) : 0;
    $pos = $finished ? 0 : max(0, min($max, $pos));
    $now = our_sermon_sql_now();

    for ($try = 0; $try < 2; $try++) {
        $stmt = $pdo->prepare('SELECT id, finished_at FROM our_sermon_progress WHERE sermon_id = ? AND user_id = ?');
        $stmt->execute([$sermonId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $finishedAt = $row['finished_at'] ?? null;
            if ($finished && $finishedAt === null) {
                $finishedAt = $now;
            }
            $pdo->prepare('UPDATE our_sermon_progress SET position_seconds = ?, finished_at = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([$pos, $finishedAt, $now, (int) $row['id'], $userId]);

            return ['position' => $pos, 'finished' => $finishedAt !== null, 'finished_at' => $finishedAt];
        }
        try {
            // The UNIQUE (sermon_id, user_id) key claims the row; a second tab
            // that loses the race goes round once more and updates it.
            $pdo->prepare('INSERT INTO our_sermon_progress (sermon_id, user_id, position_seconds, finished_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$sermonId, $userId, $pos, $finished ? $now : null, $now]);

            return ['position' => $pos, 'finished' => $finished, 'finished_at' => $finished ? $now : null];
        } catch (PDOException $e) {
            if ($try > 0 || (string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    return our_sermon_progress_get($pdo, $userId, $sermonId);
}

// ---------------------------------------------------------------------------
// Notes
// ---------------------------------------------------------------------------

/** Plain text: line breaks kept (at most one blank line in a row), control characters gone. */
function our_sermon_note_body($raw): string
{
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], is_string($raw) ? $raw : '');
    $text = preg_replace('/[\x00-\x09\x0b-\x1f\x7f]/u', '', $text) ?? '';
    $text = preg_replace('/[ \x{00A0}]+$/mu', '', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    return trim($text);
}

/** A moment in the sermon, or null for "not stamped". */
function our_sermon_note_at($raw): ?int
{
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return null;
    }
    $s = (int) floor((float) $raw);

    return $s < 0 ? null : min($s, OUR_SERMON_MAX_SECONDS);
}

/** A note as the browser shows it. */
function our_sermon_note_view(array $row): array
{
    $at = $row['at_seconds'] !== null ? (int) $row['at_seconds'] : null;
    $body = (string) $row['body'];

    return [
        'id'         => (int) $row['id'],
        'at'         => $at,
        'at_label'   => $at !== null ? our_sermon_fmt_time($at) : '',
        'body'       => $body,
        'segments'   => our_sermon_text_segments($body),
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'edited'     => (string) $row['updated_at'] !== (string) $row['created_at'],
    ];
}

/** The member's notes on a sermon, in the order of the sermon (unstamped last). */
function our_sermon_notes_list(PDO $pdo, int $userId, int $sermonId): array
{
    if ($userId <= 0 || !our_sermons_table_exists($pdo, 'our_sermon_notes')) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT id, at_seconds, body, created_at, updated_at FROM our_sermon_notes
                            WHERE user_id = ? AND sermon_id = ?
                            ORDER BY CASE WHEN at_seconds IS NULL THEN 1 ELSE 0 END, at_seconds, id');
    $stmt->execute([$userId, $sermonId]);

    return array_map('our_sermon_note_view', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** @return array{ok:bool, code?:string, message?:string, field?:string, body?:string} */
function our_sermon_note_check($raw): array
{
    $body = our_sermon_note_body($raw);
    if ($body === '') {
        return ['ok' => false, 'code' => 'EMPTY', 'message' => 'Type your note first.', 'field' => 'body'];
    }
    if (mb_strlen($body, 'UTF-8') > OUR_SERMON_NOTE_MAX) {
        return ['ok' => false, 'code' => 'TOO_LONG', 'message' => 'A note holds up to ' . number_format(OUR_SERMON_NOTE_MAX) . ' characters. Split it in two.', 'field' => 'body'];
    }

    return ['ok' => true, 'body' => $body];
}

function our_sermon_note_add(PDO $pdo, int $userId, array $sermon, $rawBody, $rawAt): array
{
    $check = our_sermon_note_check($rawBody);
    if (!$check['ok']) {
        return $check;
    }
    $sermonId = (int) $sermon['id'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM our_sermon_notes WHERE user_id = ? AND sermon_id = ?');
    $stmt->execute([$userId, $sermonId]);
    if ((int) $stmt->fetchColumn() >= OUR_SERMON_NOTES_PER_SERMON) {
        return ['ok' => false, 'code' => 'FULL', 'message' => 'This sermon has ' . OUR_SERMON_NOTES_PER_SERMON . ' notes. Join some together first.'];
    }
    $at = our_sermon_note_at($rawAt);
    $max = (int) ($sermon['duration_seconds'] ?? 0);
    if ($at !== null && $max > 0 && $at > $max) {
        $at = $max;
    }
    $now = our_sermon_sql_now();
    $pdo->prepare('INSERT INTO our_sermon_notes (sermon_id, user_id, at_seconds, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$sermonId, $userId, $at, $check['body'], $now, $now]);

    return ['ok' => true, 'note' => our_sermon_note_view([
        'id' => (int) $pdo->lastInsertId(), 'at_seconds' => $at, 'body' => $check['body'], 'created_at' => $now, 'updated_at' => $now,
    ])];
}

/**
 * Change a note's words and, when $rawAt is given ('' = unstamp), its moment.
 * Only the member's own note: the UPDATE filters on user_id.
 */
function our_sermon_note_edit(PDO $pdo, int $userId, int $noteId, $rawBody, $rawAt = false): array
{
    $check = our_sermon_note_check($rawBody);
    if (!$check['ok']) {
        return $check;
    }
    $stmt = $pdo->prepare('SELECT id, at_seconds, body, created_at, updated_at FROM our_sermon_notes WHERE id = ? AND user_id = ?');
    $stmt->execute([$noteId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'That note is gone. Reload the page.'];
    }
    $at = $rawAt === false ? ($row['at_seconds'] !== null ? (int) $row['at_seconds'] : null) : our_sermon_note_at($rawAt);
    if ($check['body'] === (string) $row['body'] && $at === ($row['at_seconds'] !== null ? (int) $row['at_seconds'] : null)) {
        return ['ok' => true, 'note' => our_sermon_note_view($row)];
    }
    $now = our_sermon_sql_now();
    $pdo->prepare('UPDATE our_sermon_notes SET body = ?, at_seconds = ?, updated_at = ? WHERE id = ? AND user_id = ?')
        ->execute([$check['body'], $at, $now, $noteId, $userId]);

    return ['ok' => true, 'note' => our_sermon_note_view(['at_seconds' => $at, 'body' => $check['body'], 'updated_at' => $now] + $row)];
}

function our_sermon_note_delete(PDO $pdo, int $userId, int $noteId): array
{
    $stmt = $pdo->prepare('DELETE FROM our_sermon_notes WHERE id = ? AND user_id = ?');
    $stmt->execute([$noteId, $userId]);
    if ($stmt->rowCount() < 1) {
        return ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'That note is gone. Reload the page.'];
    }

    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// Email my notes
// ---------------------------------------------------------------------------

/**
 * Where "my email" goes: the member's confirmed personal email, else their
 * account email (the sign-in name, a system-made one included: the owner
 * said that is fine), or null.
 */
function our_sermon_account_email(PDO $pdo, int $userId): ?string
{
    if (function_exists('member_confirmed_email')) {
        $confirmed = member_confirmed_email($pdo, $userId);
        if (is_string($confirmed) && $confirmed !== '') {
            return $confirmed;
        }
    }
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $email = strtolower(trim((string) $stmt->fetchColumn()));

    return function_exists('email_is_valid_syntax') && email_is_valid_syntax($email) ? $email : null;
}

/** "a•••@gmail.com", for the dialog. */
function our_sermon_mask_email(?string $email): string
{
    if ($email === null || $email === '') {
        return '';
    }
    if (function_exists('email_mask')) {
        return email_mask($email);
    }
    $at = strrpos($email, '@');

    return $at ? mb_substr($email, 0, 1, 'UTF-8') . '•••' . substr($email, $at) : '•••';
}

/** Text that goes into an email document: one line, no merge fields of its own. */
function our_sermon_doc_text(string $text, int $max): string
{
    $text = str_replace(['{{', '}}'], ['{ {', '} }'], $text);
    $text = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text) ?? '';
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

    return mb_strlen($text, 'UTF-8') > $max ? rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…' : $text;
}

/** The email: a block document (docs/EMAIL.md), never HTML. */
function our_sermon_notes_doc(array $sermon, array $notes, string $link): array
{
    $view = our_sermon_view($sermon);
    $title = our_sermon_doc_text($view['title'] !== '' ? $view['title'] : 'Sermon', 140);
    $meta = our_sermon_doc_text(implode(' · ', array_filter([$view['preacher'], $view['date_label']])), 300);

    $intro = [['k' => 'p', 'r' => [['t' => $title, 'b' => 1]]]];
    if ($meta !== '') {
        $intro[] = ['k' => 'p', 'r' => [['t' => $meta]]];
    }
    $blocks = [
        ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => 'Your sermon notes'],
        ['type' => 'text', 'align' => 'left', 'content' => $intro],
        ['type' => 'divider'],
    ];

    // Notes are packed into text blocks of at most 40 paragraphs and 9,000
    // characters (the document allows 50 and 10,000), and at most 50 blocks.
    $content = [];
    $chars = 0;
    $packed = 0;
    $more = false;
    $flush = static function () use (&$content, &$chars, &$blocks, &$packed): void {
        if ($content !== []) {
            $blocks[] = ['type' => 'text', 'align' => 'left', 'content' => $content];
            $packed++;
        }
        $content = [];
        $chars = 0;
    };
    foreach ($notes as $note) {
        $lines = array_values(array_filter(array_map(
            static fn (string $l): string => our_sermon_doc_text($l, 1900),
            explode("\n", (string) $note['body'])
        ), static fn (string $l): bool => $l !== ''));
        if ($lines === []) {
            continue;
        }
        $stamp = $note['at'] !== null ? our_sermon_fmt_time((int) $note['at']) : 'Note';
        $paras = [['k' => 'p', 'r' => [['t' => $stamp . '  ', 'b' => 1], ['t' => $lines[0]]]]];
        foreach (array_slice($lines, 1) as $line) {
            $paras[] = ['k' => 'p', 'r' => [['t' => $line]]];
        }
        $size = array_sum(array_map(static fn (string $l): int => mb_strlen($l, 'UTF-8'), $lines)) + 10;
        if ($content !== [] && (count($content) + count($paras) > 40 || $chars + $size > 9000)) {
            $flush();
        }
        if ($packed >= 50) {
            $more = true;
            break;
        }
        array_push($content, ...array_slice($paras, 0, 40));
        $chars += $size;
    }
    $flush();
    if ($more) {
        $blocks[] = email_doc_paragraphs('More notes are on the sermon page.');
    }
    $blocks[] = ['type' => 'button', 'label' => 'Listen again', 'href' => $link, 'style' => 'primary', 'align' => 'left'];
    $blocks[] = ['type' => 'footer'];

    return [
        'v'         => 1,
        'subject'   => our_sermon_doc_text('Your notes: ' . $title, 150),
        'preheader' => our_sermon_doc_text(count($notes) === 1 ? '1 note' : count($notes) . ' notes', 150),
        'blocks'    => $blocks,
    ];
}

/** The sermon's page, for links that leave the browser. */
function our_sermon_page_url(int $sermonId): string
{
    $path = our_sermons_url($sermonId);

    return function_exists('site_url') ? site_url($path) : $path;
}

/**
 * Send the member their notes on a sermon. $to: 'mine' (their own address)
 * or 'other' ($typed). At most OUR_SERMON_EMAILS_PER_DAY a day and one every
 * OUR_SERMON_EMAIL_GAP_SECONDS, counted from the outbox's own rows.
 */
function our_sermon_notes_email(PDO $pdo, int $userId, array $sermon, string $to, string $typed = ''): array
{
    if (!function_exists('email_send_doc') || !function_exists('campaigns_ready') || !campaigns_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'Email is being set up. Try again later.'];
    }
    $notes = our_sermon_notes_list($pdo, $userId, (int) $sermon['id']);
    if ($notes === []) {
        return ['ok' => false, 'code' => 'NO_NOTES', 'message' => 'Add a note first.'];
    }
    if ($to === 'other') {
        $address = email_normalize($typed);
        if (!email_is_valid_syntax($address)) {
            return ['ok' => false, 'code' => 'INVALID_EMAIL', 'message' => 'Check the email address.', 'field' => 'email'];
        }
    } else {
        $address = our_sermon_account_email($pdo, $userId);
        if ($address === null) {
            return ['ok' => false, 'code' => 'NO_EMAIL', 'message' => 'Type an address to send to.', 'field' => 'email'];
        }
    }

    $now = our_sermon_now();
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, MAX(created_at) AS last FROM email_outbox WHERE user_id = ? AND flow = ? AND created_at >= ?');
    $stmt->execute([$userId, OUR_SERMON_EMAIL_FLOW, $now->modify('-1 day')->format('Y-m-d H:i:s')]);
    $sent = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'last' => null];
    if ((int) $sent['n'] >= OUR_SERMON_EMAILS_PER_DAY) {
        return ['ok' => false, 'code' => 'LIMIT', 'message' => OUR_SERMON_EMAILS_PER_DAY . ' emails went today. Try again tomorrow.'];
    }
    if ($sent['last'] !== null && strtotime((string) $sent['last']) > $now->getTimestamp() - OUR_SERMON_EMAIL_GAP_SECONDS) {
        return ['ok' => false, 'code' => 'TOO_SOON', 'message' => 'One just went. Try again in a minute.'];
    }

    $person = (function_exists('email_person') ? email_person($pdo, $userId) : null) ?? ['id' => $userId, 'first_name' => '', 'last_name' => ''];
    $doc = our_sermon_notes_doc($sermon, $notes, our_sermon_page_url((int) $sermon['id']));
    try {
        $queued = email_send_doc($pdo, $doc, $person + ['email' => $address], [
            'lane' => 'transactional', 'purpose' => 'account', 'flow' => OUR_SERMON_EMAIL_FLOW, 'to_email' => $address,
            'created_by' => $userId, 'now' => true,
        ]);
    } catch (Throwable $e) {
        error_log('[our_sermons] notes email failed for user ' . $userId . ': ' . $e->getMessage());
        $queued = ['ok' => false];
    }
    if (empty($queued['ok'])) {
        if (!empty($queued['code']) && $queued['code'] === 'INVALID_EMAIL') {
            return ['ok' => false, 'code' => 'INVALID_EMAIL', 'message' => 'Check the email address.', 'field' => 'email'];
        }
        error_log('[our_sermons] notes email not queued for user ' . $userId . ': ' . ($queued['message'] ?? $queued['code'] ?? 'unknown'));

        return ['ok' => false, 'code' => 'NOT_SENT', 'message' => 'The email could not be sent. Try again later.'];
    }

    return ['ok' => true, 'to' => our_sermon_mask_email($address)];
}

// ---------------------------------------------------------------------------
// The endpoint's one entry point (api/our_sermons_listen_api.php)
// ---------------------------------------------------------------------------

function our_sermon_listen_reply(array $r, $data = null, string $message = ''): array
{
    if (!empty($r['ok'])) {
        return ['status' => 'success', 'message' => $message, 'data' => $data];
    }
    $out = ['status' => 'error', 'code' => (string) ($r['code'] ?? 'ERROR'), 'message' => (string) ($r['message'] ?? 'Something went wrong. Please try again.')];
    if (!empty($r['field'])) {
        $out['field'] = (string) $r['field'];
    }

    return $out;
}

/**
 * Every action the listening page sends, for the member signed in ($userId).
 * Access: can('our_sermons', 'view') for all of it; a sermon that is not
 * published only for those who may edit (Envision's preview). A note is
 * changed or deleted only by its owner, wherever the sermon now is.
 *
 *   notes        sermon_id                       → the member's notes
 *   note_add     sermon_id, body, at             → the new note
 *   note_edit    id, body[, at]                  → the note
 *   note_delete  id
 *   progress     sermon_id, position, finished   → saved position
 *   email_notes  sermon_id, to (mine|other), email
 *   ref          q                               → a reference for the Bible panel
 */
function our_sermon_listen_action(PDO $pdo, string $action, array $in, int $userId): array
{
    if ($userId <= 0) {
        return ['status' => 'error', 'code' => 'SIGNED_OUT', 'message' => 'Please sign in again.'];
    }
    if (!our_sermon_listen_ready($pdo)) {
        return ['status' => 'error', 'code' => 'FEATURE_NOT_READY', 'message' => 'Notes are being set up. Try again after the next update.'];
    }
    if (!our_sermon_can_view()) {
        return ['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'You do not have access to this. Ask the church office if you think you should.'];
    }
    $sermon = static function () use ($pdo, $in): ?array {
        return our_sermon_visible($pdo, (int) ($in['sermon_id'] ?? 0));
    };
    $gone = ['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'This sermon is not available. Go back to the list.'];

    switch ($action) {
        case 'notes':
            $s = $sermon();
            return $s === null ? our_sermon_listen_reply($gone)
                : our_sermon_listen_reply(['ok' => true], ['notes' => our_sermon_notes_list($pdo, $userId, (int) $s['id'])]);

        case 'note_add':
            $s = $sermon();
            if ($s === null) {
                return our_sermon_listen_reply($gone);
            }
            $r = our_sermon_note_add($pdo, $userId, $s, $in['body'] ?? '', $in['at'] ?? null);
            return our_sermon_listen_reply($r, ['note' => $r['note'] ?? null], 'Note added.');

        case 'note_edit':
            $r = our_sermon_note_edit($pdo, $userId, (int) ($in['id'] ?? 0), $in['body'] ?? '', array_key_exists('at', $in) ? $in['at'] : false);
            return our_sermon_listen_reply($r, ['note' => $r['note'] ?? null], 'Note saved.');

        case 'note_delete':
            return our_sermon_listen_reply(our_sermon_note_delete($pdo, $userId, (int) ($in['id'] ?? 0)), null, 'Note deleted.');

        case 'progress':
            $s = $sermon();
            if ($s === null) {
                return our_sermon_listen_reply($gone);
            }
            $finished = in_array((string) ($in['finished'] ?? ''), ['1', 'true'], true);
            return our_sermon_listen_reply(['ok' => true], our_sermon_progress_put($pdo, $userId, $s, $in['position'] ?? 0, $finished));

        case 'email_notes':
            $s = $sermon();
            if ($s === null) {
                return our_sermon_listen_reply($gone);
            }
            $r = our_sermon_notes_email($pdo, $userId, $s, (string) ($in['to'] ?? 'mine') === 'other' ? 'other' : 'mine', (string) ($in['email'] ?? ''));
            return our_sermon_listen_reply($r, ['to' => $r['to'] ?? null], 'Notes sent.');

        case 'ref':
            $q = trim(mb_substr((string) ($in['q'] ?? ''), 0, 60, 'UTF-8'));
            $ref = $q !== '' ? our_sermon_ref($q) : null;
            return $ref === null
                ? our_sermon_listen_reply(['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'Try a reference like John 3:16.', 'field' => 'q'])
                : our_sermon_listen_reply(['ok' => true], ['ref' => $ref]);
    }

    return ['status' => 'error', 'code' => 'UNKNOWN_ACTION', 'message' => 'Reload the page and try again.'];
}
