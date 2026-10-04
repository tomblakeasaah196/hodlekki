<?php
// /includes/special_events/ai.php
//
// The AI layer (guide §15.1): one provider-neutral entry point, se_ai(), with
// JSON-schema validation, usage logging, per-user and per-event limits and a
// single automatic retry.
//
// Two rules are absolute (§15.1, AGENTS.md):
//   1. AI input NEVER contains an attendee's name, phone or email.
//   2. AI output is NEVER applied automatically. Every task ends in a review
//      UI, and applied rows are marked source = 'ai'.

/** Raised for every AI failure. The code maps straight onto §12.1. */
class SeAiException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

/** One provider. Swapping provider means one new class and a config switch. */
interface SeAiProvider
{
    /**
     * @param array $request {system, parts, temperature, schema, max_tokens, model, timeout, thinking_budget?}
     * @return array{text: string, http_status: int, usage: array, model: string}
     */
    public function generate(array $request): array;
}

// --------------------------------------------------------------------------
// Gemini adapter
// --------------------------------------------------------------------------

class SeAiGemini implements SeAiProvider
{
    public function __construct(private readonly string $apiKey) {}

    public function generate(array $request): array
    {
        $model = (string) $request['model'];
        $url   = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent';

        $generationConfig = [
            'temperature'      => (float) $request['temperature'],
            'responseMimeType' => 'application/json',
            'maxOutputTokens'  => (int) $request['max_tokens'],
        ];
        if (!empty($request['schema'])) {
            $generationConfig['responseSchema'] = se_schema_to_openapi($request['schema']);
        }
        if (isset($request['thinking_budget'])) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => (int) $request['thinking_budget']];
        }

        $payload = [
            'systemInstruction' => ['parts' => [['text' => (string) $request['system']]]],
            'contents'          => [['role' => 'user', 'parts' => $request['parts']]],
            'generationConfig'  => $generationConfig,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) $request['timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            // The key travels in a header, never in the URL, so it cannot
            // land in an access log or a Referer (§15.1).
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => se_json_encode($payload),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            // The message may name the host but never the key.
            throw new SeAiException('AI_UNAVAILABLE', 'The AI service could not be reached: ' . $curlError);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new SeAiException('AI_UNAVAILABLE', 'The AI service returned something unreadable.');
        }

        if ($status !== 200) {
            $reason = (string) ($decoded['error']['message'] ?? 'HTTP ' . $status);
            throw new SeAiException(
                in_array($status, [429, 500, 503], true) ? 'AI_RETRYABLE' : 'AI_UNAVAILABLE',
                'The AI service refused the request: ' . mb_substr($reason, 0, 200, 'UTF-8')
            );
        }

        $text = '';
        foreach ($decoded['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        return [
            'text'        => $text,
            'http_status' => $status,
            'model'       => $model,
            'usage'       => [
                'input'  => (int) ($decoded['usageMetadata']['promptTokenCount'] ?? 0),
                'output' => (int) ($decoded['usageMetadata']['candidatesTokenCount'] ?? 0),
            ],
        ];
    }
}

// --------------------------------------------------------------------------
// Availability and limits
// --------------------------------------------------------------------------

/** True when an API key is configured, so the UI can hide the AI buttons. */
function se_ai_available(): bool
{
    return trim((string) ($_ENV['GEMINI_API_KEY'] ?? '')) !== '';
}

/** Why AI is off, for the tooltip next to a disabled button. */
function se_ai_unavailable_reason(): ?string
{
    if (!se_ai_available()) {
        return 'AI suggestions are off because no Gemini API key is configured. An administrator can add GEMINI_API_KEY.';
    }

    return null;
}

/**
 * Enforce the per-user hourly and per-event daily call limits (§15.1).
 * @throws SeAiException AI_LIMIT
 */
function se_ai_check_limits(PDO $pdo, ?int $userId, ?int $eventId): void
{
    if (!se_table_exists($pdo, 'se_ai_requests')) {
        return;
    }

    $settings   = se_settings_all($pdo);
    $userLimit  = max(1, (int) ($settings['ai_user_hourly_limit'] ?? 30));
    $eventLimit = max(1, (int) ($settings['ai_event_daily_limit'] ?? 300));

    try {
        if ($userId) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM se_ai_requests
                 WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $stmt->execute([$userId]);
            if ((int) $stmt->fetchColumn() >= $userLimit) {
                throw new SeAiException('AI_LIMIT', 'You have used your AI suggestions for this hour. Please try again later.');
            }
        }
        if ($eventId) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM se_ai_requests
                 WHERE event_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
            );
            $stmt->execute([$eventId]);
            if ((int) $stmt->fetchColumn() >= $eventLimit) {
                throw new SeAiException('AI_LIMIT', 'This event has used its AI suggestions for today.');
            }
        }
    } catch (PDOException $e) {
        error_log('SE ai/limits: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// Prompts
// --------------------------------------------------------------------------

/**
 * Load prompts/<task>.md (front matter + system prompt) and, when present,
 * prompts/<task>.schema.json.
 *
 * @return array{version: string, temperature: float, max_tokens: int, system: string, schema: array, thinking_budget: ?int}
 */
function se_ai_prompt(string $task): array
{
    if (!preg_match('/^[a-z0-9_]+$/', $task)) {
        throw new SeAiException('AI_UNAVAILABLE', 'Unknown AI task.');
    }

    $path = __DIR__ . '/prompts/' . $task . '.md';
    if (!is_file($path)) {
        throw new SeAiException('AI_UNAVAILABLE', 'That AI task is not configured yet.');
    }

    $raw = (string) file_get_contents($path);

    $meta = ['version' => '1', 'temperature' => 0.7, 'max_tokens' => 2048, 'thinking_budget' => null];
    $body = $raw;

    if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $m)) {
        $body = $m[2];
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k === 'version')         { $meta['version'] = $v; }
            if ($k === 'temperature')     { $meta['temperature'] = (float) $v; }
            if ($k === 'max_tokens')      { $meta['max_tokens'] = (int) $v; }
            if ($k === 'thinking_budget') { $meta['thinking_budget'] = (int) $v; }
        }
    }

    $schemaPath = __DIR__ . '/prompts/' . $task . '.schema.json';
    $schema     = is_file($schemaPath) ? se_json_decode((string) file_get_contents($schemaPath)) : [];

    return $meta + ['system' => trim($body), 'schema' => $schema];
}

/** Substitute {{placeholders}} in a prompt body. Values are plain text. */
function se_ai_render_prompt(string $template, array $vars): string
{
    $map = [];
    foreach ($vars as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $value));
        }
        $map['{{' . $key . '}}'] = se_str((string) $value, 2000);
    }

    return strtr($template, $map);
}

// --------------------------------------------------------------------------
// Schema validation (§15.1)
// --------------------------------------------------------------------------

/**
 * Validate decoded JSON against the task's schema (a practical subset of
 * JSON Schema: type, required, properties, items, enum, min/maxItems,
 * min/maxLength, minimum/maximum).
 *
 * @return string[] Human-readable problems; empty means valid.
 */
function se_schema_validate(mixed $value, array $schema, string $path = '$'): array
{
    $problems = [];
    $type     = $schema['type'] ?? null;

    $typeOk = match ($type) {
        null      => true,
        'object'  => is_array($value) && !array_is_list($value) || $value === [],
        'array'   => is_array($value) && (array_is_list($value) || $value === []),
        'string'  => is_string($value),
        'number'  => is_int($value) || is_float($value),
        'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
        'boolean' => is_bool($value),
        default   => true,
    };
    if (!$typeOk) {
        return ["{$path} should be a {$type}"];
    }

    if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
        $problems[] = "{$path} must be one of: " . implode(', ', array_map('strval', $schema['enum']));
    }

    if ($type === 'string' && is_string($value)) {
        if (isset($schema['minLength']) && mb_strlen($value, 'UTF-8') < (int) $schema['minLength']) {
            $problems[] = "{$path} is too short";
        }
        if (isset($schema['maxLength']) && mb_strlen($value, 'UTF-8') > (int) $schema['maxLength']) {
            $problems[] = "{$path} is longer than {$schema['maxLength']} characters";
        }
    }

    if (($type === 'number' || $type === 'integer') && is_numeric($value)) {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $problems[] = "{$path} must be at least {$schema['minimum']}";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $problems[] = "{$path} must be at most {$schema['maximum']}";
        }
    }

    if ($type === 'object') {
        $object = is_array($value) ? $value : [];
        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $object)) {
                $problems[] = "{$path}.{$key} is missing";
            }
        }
        foreach ($schema['properties'] ?? [] as $key => $childSchema) {
            if (array_key_exists($key, $object) && is_array($childSchema)) {
                $problems = array_merge(
                    $problems,
                    se_schema_validate($object[$key], $childSchema, "{$path}.{$key}")
                );
            }
        }
    }

    if ($type === 'array') {
        $list = is_array($value) ? $value : [];
        if (isset($schema['minItems']) && count($list) < (int) $schema['minItems']) {
            $problems[] = "{$path} needs at least {$schema['minItems']} entries";
        }
        if (isset($schema['maxItems']) && count($list) > (int) $schema['maxItems']) {
            $problems[] = "{$path} has more than {$schema['maxItems']} entries";
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($list as $i => $item) {
                $problems = array_merge(
                    $problems,
                    se_schema_validate($item, $schema['items'], "{$path}[{$i}]")
                );
            }
        }
    }

    return $problems;
}

/** Strip the keys Gemini's OpenAPI-subset responseSchema does not accept. */
function se_schema_to_openapi(array $schema): array
{
    $allowed = ['type', 'format', 'description', 'nullable', 'enum', 'items',
                'properties', 'required', 'minItems', 'maxItems'];
    $out = [];

    foreach ($schema as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        if ($key === 'properties' && is_array($value)) {
            $out[$key] = [];
            foreach ($value as $prop => $child) {
                $out[$key][$prop] = is_array($child) ? se_schema_to_openapi($child) : $child;
            }
        } elseif ($key === 'items' && is_array($value)) {
            $out[$key] = se_schema_to_openapi($value);
        } elseif ($key === 'type' && is_string($value)) {
            $out[$key] = strtoupper($value);
        } else {
            $out[$key] = $value;
        }
    }

    return $out;
}

// --------------------------------------------------------------------------
// se_ai()
// --------------------------------------------------------------------------

/**
 * Run an AI task and return validated, post-processed data.
 *
 * @param array $input Task-specific variables. MUST NOT hold attendee PII.
 * @param array $ctx   {user_id?, event_id?, parts?: extra inlineData parts, vision?: bool}
 * @throws SeAiException AI_UNAVAILABLE | AI_INVALID_OUTPUT | AI_LIMIT | AI_TIMEOUT
 */
function se_ai(PDO $pdo, string $task, array $input, array $ctx = []): array
{
    $apiKey = trim((string) ($_ENV['GEMINI_API_KEY'] ?? ''));
    if ($apiKey === '') {
        throw new SeAiException('AI_UNAVAILABLE', (string) se_ai_unavailable_reason());
    }

    $userId  = isset($ctx['user_id']) ? (int) $ctx['user_id'] : null;
    $eventId = isset($ctx['event_id']) ? (int) $ctx['event_id'] : null;

    se_ai_check_limits($pdo, $userId, $eventId);

    $prompt  = se_ai_prompt($task);
    $isVision = !empty($ctx['vision']);
    $model   = $isVision
        ? (string) ($_ENV['SE_AI_MODEL_VISION'] ?? 'gemini-2.5-flash')
        : (string) ($_ENV['SE_AI_MODEL_TEXT'] ?? 'gemini-2.5-flash');

    // The rendered template IS the system instruction (it already carries the
    // task's variables). The user turn holds only the extra inlineData parts
    // an import task attaches — plus a nudge, because Gemini rejects an
    // empty `contents`.
    $system = se_ai_render_prompt($prompt['system'], $input);

    $parts = [];
    foreach ($ctx['parts'] ?? [] as $extra) {
        if (is_array($extra)) {
            $parts[] = $extra;
        }
    }
    $parts[] = ['text' => 'Produce the JSON now.'];

    $request = [
        'system'      => $system,
        'parts'       => $parts,
        'temperature' => $prompt['temperature'],
        'schema'      => $prompt['schema'],
        'max_tokens'  => $prompt['max_tokens'],
        'model'       => $model,
        'timeout'     => $isVision ? 60 : 30,
    ];
    if ($prompt['thinking_budget'] !== null) {
        $request['thinking_budget'] = $prompt['thinking_budget'];
    }

    $provider = new SeAiGemini($apiKey);
    $attempt  = 0;
    $lastProblems = [];

    while ($attempt < 2) {
        $attempt++;
        $startedAt = microtime(true);
        $usage     = ['input' => 0, 'output' => 0];
        $httpStatus = null;
        $errorCode = null;

        try {
            $response   = $provider->generate($request);
            $usage      = $response['usage'];
            $httpStatus = $response['http_status'];

            $decoded = json_decode($response['text'], true);
            if (!is_array($decoded)) {
                throw new SeAiException('AI_INVALID_OUTPUT', 'The AI did not return usable JSON.');
            }

            $problems = $prompt['schema'] ? se_schema_validate($decoded, $prompt['schema']) : [];
            if ($problems) {
                $lastProblems = $problems;
                throw new SeAiException('AI_INVALID_OUTPUT', 'The AI output did not match the expected shape.');
            }

            se_ai_log($pdo, $task, $model, $prompt['version'], $usage, $startedAt, $httpStatus, true, null, $userId, $eventId, $ctx['job_id'] ?? null);

            return $decoded;
        } catch (SeAiException $e) {
            $errorCode = $e->errorCode;
            se_ai_log($pdo, $task, $model, $prompt['version'], $usage, $startedAt, $httpStatus, false, $errorCode, $userId, $eventId, $ctx['job_id'] ?? null);

            if ($attempt >= 2) {
                throw new SeAiException(
                    $errorCode === 'AI_RETRYABLE' ? 'AI_UNAVAILABLE' : $errorCode,
                    $e->getMessage()
                );
            }

            if ($errorCode === 'AI_RETRYABLE') {
                usleep(1500000);   // One retry after 1.5 s on 429/500/503.
                continue;
            }
            if ($errorCode === 'AI_INVALID_OUTPUT') {
                // Retry once with a hint naming what was wrong (§15.1).
                $request['parts'] = array_merge($parts, [[
                    'text' => 'Your previous output was invalid because: '
                        . implode('; ', array_slice($lastProblems, 0, 6))
                        . '. Return only JSON matching the schema.',
                ]]);
                continue;
            }

            throw $e;
        }
    }

    throw new SeAiException('AI_UNAVAILABLE', 'The AI service did not answer.');
}

/** One row per call in se_ai_requests. Never logs prompt text or output. */
function se_ai_log(
    PDO $pdo, string $task, string $model, string $promptVersion, array $usage,
    float $startedAt, ?int $httpStatus, bool $ok, ?string $errorCode,
    ?int $userId, ?int $eventId, ?int $jobId = null
): void {
    try {
        if (!se_table_exists($pdo, 'se_ai_requests')) {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO se_ai_requests
                (job_id, event_id, user_id, task, model, prompt_version,
                 input_tokens, output_tokens, latency_ms, http_status, ok, error_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $jobId !== null ? (int) $jobId : null,
            $eventId, $userId, $task, $model, $promptVersion,
            (int) ($usage['input'] ?? 0), (int) ($usage['output'] ?? 0),
            (int) round((microtime(true) - $startedAt) * 1000),
            $httpStatus, $ok ? 1 : 0, $errorCode,
        ]);
    } catch (Throwable $e) {
        error_log('SE ai/log: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// AI jobs (review before apply, §15.1)
// --------------------------------------------------------------------------

/** Record a finished AI result for review. Returns the job id. */
function se_ai_job_create(PDO $pdo, ?int $eventId, string $task, array $input, array $result, int $actorId, string $status = 'ready', ?string $error = null): int
{
    if (!se_table_exists($pdo, 'se_ai_jobs')) {
        return 0;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO se_ai_jobs (event_id, task, input_json, result_json, status, error, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $eventId, $task, se_json_encode($input), se_json_encode($result),
        $status, $error !== null ? se_line($error, 255) : null, $actorId,
    ]);

    return (int) $pdo->lastInsertId();
}

function se_ai_job_find(PDO $pdo, int $jobId, ?int $eventId = null): ?array
{
    if (!se_table_exists($pdo, 'se_ai_jobs')) {
        return null;
    }
    if ($eventId !== null) {
        $stmt = $pdo->prepare("SELECT * FROM se_ai_jobs WHERE id = ? AND event_id = ?");
        $stmt->execute([$jobId, $eventId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM se_ai_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
    }

    return $stmt->fetch() ?: null;
}

/** Mark a job applied. Called only after the human pressed Apply. */
function se_ai_job_mark_applied(PDO $pdo, int $jobId, ?int $actorId): void
{
    if (!se_table_exists($pdo, 'se_ai_jobs')) {
        return;
    }
    $pdo->prepare("UPDATE se_ai_jobs SET status = 'applied', applied_at = NOW() WHERE id = ?")
        ->execute([$jobId]);

    $job = se_ai_job_find($pdo, $jobId);
    se_audit($pdo, $job ? (int) $job['event_id'] : null, 'ai_job_apply',
        ['task' => $job['task'] ?? null], 'ai_job', $jobId, $actorId);
}

// --------------------------------------------------------------------------
// Task: palette_suggest (§15.2)
// --------------------------------------------------------------------------

/**
 * Four palette suggestions. P and S are forced unchanged, every hex is
 * re-validated, and all contrast-critical tokens are recomputed by the theme
 * engine — the AI never sets them (§15.2).
 */
function se_ai_palette_suggest(PDO $pdo, array $event, string $primary, string $secondary, array $mood, int $actorId): array
{
    $P = se_normalize_hex($primary);
    $S = se_normalize_hex($secondary);
    if ($P === null || $S === null) {
        throw new SeValidationException(['primary' => 'Enter two hex colours first, like #1D356A.']);
    }

    $moodWords = [];
    foreach (array_slice($mood, 0, 6) as $word) {
        $clean = preg_replace('/[^a-z0-9 -]/i', '', (string) $word) ?? '';
        $clean = se_line($clean, 24);
        if ($clean !== '') {
            $moodWords[] = strtolower($clean);
        }
    }
    if (!$moodWords) {
        $moodWords = ['joy', 'night', 'karaoke'];
    }

    // Only the event's own public text is sent — never an attendee's details.
    $result = se_ai($pdo, 'palette_suggest', [
        'title'     => se_line($event['title'] ?? 'the event', 120),
        'tagline'   => se_line($event['tagline'] ?? '', 160),
        'mood'      => $moodWords,
        'primary'   => $P,
        'secondary' => $S,
    ], [
        'user_id'  => $actorId,
        'event_id' => (int) ($event['id'] ?? 0) ?: null,
    ]);

    $palettes = [];
    foreach ($result['palettes'] ?? [] as $palette) {
        $accent = se_normalize_hex($palette['accent'] ?? '');
        if ($accent === null) {
            continue;   // Drop a suggestion we cannot trust rather than guess.
        }

        $teams = [];
        foreach ($palette['team_suggestions'] ?? [] as $teamHex) {
            $hex = se_normalize_hex($teamHex);
            if ($hex !== null) {
                $teams[] = $hex;
            }
        }

        $moodOut = [];
        foreach ($palette['mood_words'] ?? [] as $word) {
            $clean = se_line($word, 24);
            if ($clean !== '') {
                $moodOut[] = $clean;
            }
        }

        // P and S unchanged; every token recomputed by §13.2.2.
        $theme = se_theme_derive($P, $S, $accent, (string) ($event['theme_preset'] ?? 'marquee'));

        $palettes[] = [
            'name'             => se_line($palette['name'] ?? 'Palette', 60),
            'rationale'        => se_line($palette['rationale'] ?? '', 120),
            'accent'           => $accent,
            'mood_words'       => array_slice($moodOut, 0, 3),
            'team_suggestions' => array_slice($teams, 0, 4),
            'tokens'           => $theme['tokens'],
            'contrast'         => $theme['contrast'],
        ];
    }

    if (count($palettes) < 1) {
        throw new SeAiException('AI_INVALID_OUTPUT', 'The AI did not return any usable palettes. Please try again.');
    }

    $jobId = se_ai_job_create($pdo, (int) ($event['id'] ?? 0) ?: null, 'palette_suggest', [
        'primary' => $P, 'secondary' => $S, 'mood' => $moodWords,
    ], ['palettes' => $palettes], $actorId);

    return ['job_id' => $jobId, 'palettes' => $palettes];
}
