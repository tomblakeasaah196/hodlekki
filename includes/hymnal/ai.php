<?php
// /includes/hymnal/ai.php
//
// hymnal_ai(): Gemini reads a song someone uploaded, so the music team does not
// have to retype it.
//
// TWO RULES, BOTH ABSOLUTE, the same two the Special Events module works under
// (AGENTS.md):
//
//   1. NOTHING ABOUT A PERSON EVER GOES INTO A PROMPT. The only thing sent is
//      the song: text pasted into the Studio, or an image or PDF of a song
//      sheet. No member names, no phone numbers, no congregation data. There is
//      no code path here that reads a user record.
//
//   2. NOTHING THE MODEL RETURNS IS EVER APPLIED BY ITSELF. A call produces a
//      hymnal_ai_jobs row and nothing else. It becomes a song only when a
//      person has read it in the Studio form and pressed save, and the job then
//      records which song it became. A model that invents a verse must be
//      caught by a human reading it, so the review step is not optional and
//      must never be skipped "because it is usually right".
//
// This is a sibling of se_ai() rather than a caller of it: that function logs
// to se_ai_requests, counts against an event's limits and lives behind a
// bootstrap that demands SE_HASH_PEPPER and sets up the Special Events CSP.
// Coupling the Hymnary to all of that to borrow one HTTP call would be the
// wrong trade. The transport, the header-not-URL key handling and the failure
// taxonomy are deliberately the same, so a fix in one is easy to carry across.

/** Raised for every AI failure here. */
class HymnalAiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null
    ) {
        parent::__construct($message);
    }
}

/** The model used when .env names none. */
const HYMNAL_AI_MODEL_FALLBACK = 'gemini-2.5-flash';

/** Calls one member may make in an hour. The Studio is a small team. */
const HYMNAL_AI_HOURLY_LIMIT = 20;

/** What an upload may weigh, before base64. Gemini inline data is capped and a
 *  song sheet photographed on a phone is comfortably inside this. */
const HYMNAL_AI_MAX_UPLOAD_BYTES = 6 * 1024 * 1024;

const HYMNAL_AI_MIME = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

/**
 * The model for a text or a vision task.
 *
 * Read through hymnal_env() rather than `?? 'default'`, because
 * `HYMNAL_AI_MODEL_VISION=""` in .env is an empty string, not null, and would
 * otherwise build the URL `…/v1beta/models/:generateContent` — which 404s and
 * reports itself as "AI unavailable" while the key looks fine. That exact bug
 * cost the Special Events module a debugging session (AGENTS.md §28.4), so the
 * Hymnary inherits the fix rather than the bug. SE's own variables are read as
 * a fallback so one key and one model choice serve both modules.
 */
function hymnal_ai_model(bool $vision): string
{
    $own = $vision ? hymnal_env('HYMNAL_AI_MODEL_VISION') : hymnal_env('HYMNAL_AI_MODEL_TEXT');
    if ($own !== '') {
        return $own;
    }
    $shared = $vision ? hymnal_env('SE_AI_MODEL_VISION') : hymnal_env('SE_AI_MODEL_TEXT');

    return $shared !== '' ? $shared : HYMNAL_AI_MODEL_FALLBACK;
}

/** True when a key is configured, so the Studio can hide the AI buttons. */
function hymnal_ai_available(): bool
{
    return hymnal_env('GEMINI_API_KEY') !== '';
}

/** Why the AI help is off, for the note next to a disabled button. */
function hymnal_ai_unavailable_reason(): ?string
{
    if (!hymnal_ai_available()) {
        return 'Reading a song automatically is off because no Gemini API key is configured. '
            . 'An administrator can add GEMINI_API_KEY. You can still type a song in by hand.';
    }

    return null;
}

/**
 * Enforce the per-member hourly limit.
 *
 * @throws HymnalAiException AI_LIMIT
 */
function hymnal_ai_check_limit(PDO $pdo, int $userId): void
{
    if (!hymnal_table_exists($pdo, 'hymnal_ai_requests') || $userId <= 0) {
        return;
    }
    try {
        // Written as a plain comparison rather than DATE_SUB so the tests can
        // run this on SQLite (AGENTS.md).
        $since = date('Y-m-d H:i:s', time() - 3600);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM hymnal_ai_requests WHERE user_id = ? AND created_at >= ?');
        $stmt->execute([$userId, $since]);
        $used = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Hymnary ai limit: ' . $e->getMessage());

        return;
    }
    if ($used >= HYMNAL_AI_HOURLY_LIMIT) {
        throw new HymnalAiException(
            'AI_LIMIT',
            'You have used this hour\'s automatic readings. Try again later, or type the song in by hand.'
        );
    }
}

/** Load prompts/<task>.php. */
function hymnal_ai_prompt(string $task): array
{
    if (!preg_match('/^[a-z0-9_]+$/', $task)) {
        throw new HymnalAiException('AI_UNAVAILABLE', 'Unknown AI task.');
    }
    $path = __DIR__ . '/prompts/' . $task . '.php';
    if (!is_file($path)) {
        throw new HymnalAiException('AI_UNAVAILABLE', 'That AI task is not configured.');
    }
    $p = (array) require $path;
    foreach (['system', 'schema', 'temperature', 'max_tokens'] as $k) {
        if (!isset($p[$k])) {
            throw new HymnalAiException('AI_UNAVAILABLE', "The $task prompt is missing $k.");
        }
    }

    return $p;
}

/**
 * One call to Gemini.
 *
 * @param array $parts Gemini content parts: [['text' => …]] or
 *                     [['inline_data' => ['mime_type' => …, 'data' => base64]]]
 * @return array{text:string, http_status:int, model:string, finish_reason:string}
 * @throws HymnalAiException
 */
function hymnal_ai_generate(string $task, array $parts, bool $vision): array
{
    $key = hymnal_env('GEMINI_API_KEY');
    if ($key === '') {
        throw new HymnalAiException('AI_UNAVAILABLE', 'No Gemini API key is configured.', 0);
    }
    $model = hymnal_ai_model($vision);
    if (trim($model) === '') {
        throw new HymnalAiException('AI_UNAVAILABLE', 'No Gemini model is configured.', 0);
    }
    $prompt = hymnal_ai_prompt($task);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent';

    $payload = [
        'systemInstruction' => ['parts' => [['text' => (string) $prompt['system']]]],
        'contents'          => [['role' => 'user', 'parts' => $parts]],
        'generationConfig'  => [
            'temperature'      => (float) $prompt['temperature'],
            'responseMimeType' => 'application/json',
            'maxOutputTokens'  => (int) $prompt['max_tokens'],
            'responseSchema'   => $prompt['schema'],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        // The key travels in a header, never in the URL, so it cannot land in
        // an access log or a Referer. Redirects are not followed, because a
        // redirect would resend the header to wherever it points.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $key,
        ],
        CURLOPT_POSTFIELDS     => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        // The message may name the host but never the key. A zero status means
        // no HTTP response arrived at all, which distinguishes a network
        // problem from a rejected request.
        throw new HymnalAiException(
            'AI_UNAVAILABLE',
            'The AI service could not be reached: ' . $curlError,
            $status > 0 ? $status : 0
        );
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new HymnalAiException('AI_UNAVAILABLE', 'The AI service returned something unreadable.', $status);
    }
    if ($status !== 200) {
        $reason = (string) ($decoded['error']['message'] ?? 'HTTP ' . $status);
        throw new HymnalAiException(
            in_array($status, [429, 500, 503], true) ? 'AI_RETRYABLE' : 'AI_UNAVAILABLE',
            'The AI service refused the request: ' . mb_substr($reason, 0, 400),
            $status
        );
    }

    $candidate = $decoded['candidates'][0] ?? [];
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (isset($part['text'])) {
            $text .= (string) $part['text'];
        }
    }

    return [
        'text'          => $text,
        'http_status'   => $status,
        'model'         => $model,
        'finish_reason' => (string) ($candidate['finishReason'] ?? ''),
    ];
}

/** One row per attempt, successful or not, so a failure can be diagnosed
 *  without reproducing it. http_status 0 means it never reached Google. */
function hymnal_ai_log(PDO $pdo, ?int $userId, ?int $jobId, string $task, string $model, int $status, bool $ok, int $ms, string $error): void
{
    if (!hymnal_table_exists($pdo, 'hymnal_ai_requests')) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO hymnal_ai_requests (user_id, job_id, task, model, http_status, ok, duration_ms, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $jobId, $task, $model, $status, $ok ? 1 : 0, $ms, mb_substr($error, 0, 500), hymnal_now()]);
    } catch (Throwable $e) {
        error_log('Hymnary ai log: ' . $e->getMessage());
    }
}

/**
 * Read a song and file the result for review.
 *
 * Returns the job, never a song. The Studio shows job['result'] in the normal
 * song form, with job['result']['notes'] above it, and only a person pressing
 * save turns any of it into a row the congregation can see.
 *
 * @param string      $text  pasted words, when there is no file
 * @param array|null  $file  ['bytes' => raw, 'mime' => 'image/jpeg', 'name' => '…']
 * @return array{ok:bool, code?:string, message?:string, job?:array}
 */
function hymnal_ai_read_song(PDO $pdo, int $userId, string $text, ?array $file = null): array
{
    if (!hymnal_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'The Studio is not switched on yet.'];
    }
    if (!hymnal_ai_available() || hymnal_config_get($pdo, 'ai_enabled', '1') !== '1') {
        return [
            'ok'      => false,
            'code'    => 'AI_UNAVAILABLE',
            'message' => hymnal_ai_unavailable_reason() ?? 'Automatic reading is switched off.',
        ];
    }

    $vision = false;
    $parts = [];
    $inputKind = 'text';
    $inputNote = '';

    if ($file !== null) {
        $mime = (string) ($file['mime'] ?? '');
        if (!isset(HYMNAL_AI_MIME[$mime])) {
            return [
                'ok'      => false,
                'code'    => 'INVALID',
                'message' => 'Upload a photo (JPG, PNG or WebP) or a PDF of the song.',
            ];
        }
        $bytes = (string) ($file['bytes'] ?? '');
        if ($bytes === '') {
            return ['ok' => false, 'code' => 'INVALID', 'message' => 'That file came through empty.'];
        }
        if (strlen($bytes) > HYMNAL_AI_MAX_UPLOAD_BYTES) {
            return [
                'ok'      => false,
                'code'    => 'TOO_LARGE',
                'message' => 'That file is too large. Please send one under 6 MB.',
            ];
        }
        $vision = true;
        $inputKind = $mime === 'application/pdf' ? 'pdf' : 'image';
        $inputNote = mb_substr((string) ($file['name'] ?? ''), 0, 200);
        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
        $parts[] = ['text' => 'Transcribe the song in this file.'];
    } else {
        $text = trim($text);
        if (mb_strlen($text) < 10) {
            return ['ok' => false, 'code' => 'INVALID', 'message' => 'Paste the song’s words first.'];
        }
        if (mb_strlen($text) > 20000) {
            return ['ok' => false, 'code' => 'TOO_LARGE', 'message' => 'That is longer than one song. Please paste one song at a time.'];
        }
        $inputNote = 'pasted text, ' . mb_strlen($text) . ' characters';
        $parts[] = ['text' => "Transcribe this song.\n\n" . $text];
    }

    try {
        hymnal_ai_check_limit($pdo, $userId);
    } catch (HymnalAiException $e) {
        return ['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
    }

    $jobId = null;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO hymnal_ai_jobs (created_by, kind, status, input_kind, input_note, result, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, 'extract', 'pending', $inputKind, $inputNote, '{}', hymnal_now()]);
        $jobId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('Hymnary ai job create: ' . $e->getMessage());

        return ['ok' => false, 'code' => 'ERROR', 'message' => 'That did not start. Please try again.'];
    }

    $started = microtime(true);
    $model = hymnal_ai_model($vision);
    try {
        $res = hymnal_ai_generate('extract_song', $parts, $vision);
        $ms = (int) round((microtime(true) - $started) * 1000);
        $parsed = json_decode($res['text'], true);
        if (!is_array($parsed)) {
            throw new HymnalAiException('AI_UNREADABLE', 'The reading came back in a form we could not use.', $res['http_status']);
        }
        $clean = hymnal_ai_shape_song($parsed);
        hymnal_ai_log($pdo, $userId, $jobId, 'extract_song', $res['model'], $res['http_status'], true, $ms, '');
        $pdo->prepare('UPDATE hymnal_ai_jobs SET status = ?, result = ? WHERE id = ?')
            ->execute(['ready', (string) json_encode($clean, JSON_UNESCAPED_UNICODE), $jobId]);
    } catch (HymnalAiException $e) {
        $ms = (int) round((microtime(true) - $started) * 1000);
        error_log(sprintf(
            'Hymnary ai: task=extract_song model=%s status=%s: %s',
            $model,
            $e->httpStatus ?? 'none',
            $e->getMessage()
        ));
        hymnal_ai_log($pdo, $userId, $jobId, 'extract_song', $model, (int) ($e->httpStatus ?? 0), false, $ms, $e->getMessage());
        try {
            $pdo->prepare('UPDATE hymnal_ai_jobs SET status = ?, error = ? WHERE id = ?')
                ->execute(['failed', mb_substr($e->getMessage(), 0, 500), $jobId]);
        } catch (Throwable $inner) {
            error_log('Hymnary ai job fail write: ' . $inner->getMessage());
        }

        return ['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('Hymnary ai: ' . $e->getMessage());

        return ['ok' => false, 'code' => 'ERROR', 'message' => 'That reading did not finish. Please try again.'];
    }

    hymnal_audit($pdo, $userId, 'ai_read', null, null, 'job ' . $jobId . ', ' . $inputKind);

    return ['ok' => true, 'job' => ['id' => $jobId, 'status' => 'ready', 'result' => $clean]];
}

/**
 * Put what the model returned into the shape the Studio form reads, dropping
 * anything that is not one of the fields we asked for.
 *
 * This runs on output rather than trusting the schema, because a schema is a
 * request and not a guarantee: the model can be served without one on a
 * fallback path, and nothing downstream should have to wonder.
 */
function hymnal_ai_shape_song(array $raw): array
{
    // The model may answer null for anything optional, and on a fallback path
    // may be served with no schema at all, so every field is narrowed here
    // rather than assumed.
    $str = static fn(mixed $v, int $max): string => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $max);

    $sections = [];
    foreach ((array) ($raw['sections'] ?? []) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $lines = [];
        foreach ((array) ($s['lines'] ?? []) as $l) {
            $l = $str($l, 500);
            if ($l !== '') {
                $lines[] = $l;
            }
        }
        if ($lines === []) {
            continue;
        }
        $sections[] = [
            'label' => $str($s['label'] ?? '', 64),
            'kind'  => strtolower($str($s['kind'] ?? 'verse', 16)),
            'lines' => array_slice($lines, 0, 40),
        ];
        if (count($sections) >= 30) {
            break;
        }
    }

    return [
        'title'     => $str($raw['title'] ?? '', 255),
        'writer'    => $str($raw['writer'] ?? '', 255),
        'song_key'  => $str($raw['song_key'] ?? '', 16),
        'time_sig'  => $str($raw['time_sig'] ?? '', 16),
        'themes'    => $str($raw['themes'] ?? '', 500),
        'scripture' => $str($raw['scripture'] ?? '', 500),
        'chordpro'  => mb_substr(hymnal_ai_scalar($raw['chordpro'] ?? null), 0, 60000),
        'notes'     => $str($raw['notes'] ?? '', 2000),
        'sections'  => hymnal_clean_sections($sections),
    ];
}

/** Anything that is not a plain scalar becomes an empty string. */
function hymnal_ai_scalar(mixed $v): string
{
    return is_scalar($v) ? (string) $v : '';
}

/** A job this member started. Jobs are private to whoever ran them. */
function hymnal_ai_job(PDO $pdo, int $userId, int $jobId): ?array
{
    if (!hymnal_table_exists($pdo, 'hymnal_ai_jobs')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM hymnal_ai_jobs WHERE id = ? AND created_by = ?');
        $stmt->execute([$jobId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $result = json_decode((string) $row['result'], true);

        return [
            'id'      => (int) $row['id'],
            'status'  => (string) $row['status'],
            'error'   => (string) $row['error'],
            'result'  => is_array($result) ? $result : [],
            'applied' => $row['applied_song_id'] === null ? null : (int) $row['applied_song_id'],
        ];
    } catch (Throwable $e) {
        error_log('Hymnary ai job read: ' . $e->getMessage());

        return null;
    }
}

/**
 * Record that a person turned a reading into a song.
 *
 * Called only from the save path, after the save succeeded, so the trail says
 * which AI reading a song came from and who accepted it.
 */
function hymnal_ai_job_applied(PDO $pdo, int $userId, int $jobId, int $songId): void
{
    if (!hymnal_table_exists($pdo, 'hymnal_ai_jobs')) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'UPDATE hymnal_ai_jobs SET status = ?, applied_song_id = ?, applied_by = ?, applied_at = ?
              WHERE id = ? AND created_by = ?'
        );
        $stmt->execute(['applied', $songId, $userId, hymnal_now(), $jobId, $userId]);
    } catch (Throwable $e) {
        error_log('Hymnary ai job applied: ' . $e->getMessage());
    }
}
