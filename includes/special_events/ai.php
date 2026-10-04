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
     * @return array{text: string, http_status: int, usage: array, model: string, finish_reason?: string}
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
        if (isset($request['thinking_budget'])
            && se_ai_model_allows_thinking_budget($model, (int) $request['thinking_budget'])) {
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

        $candidate = $decoded['candidates'][0] ?? [];
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        return [
            'text'        => $text,
            'http_status' => $status,
            'model'       => $model,
            'usage'       => [
                'input'    => (int) ($decoded['usageMetadata']['promptTokenCount'] ?? 0),
                'output'   => (int) ($decoded['usageMetadata']['candidatesTokenCount'] ?? 0),
                'thinking' => (int) ($decoded['usageMetadata']['thoughtsTokenCount'] ?? 0),
                'total'    => (int) ($decoded['usageMetadata']['totalTokenCount'] ?? 0),
            ],
            'finish_reason' => (string) ($candidate['finishReason'] ?? ''),
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

/**
 * Gemini only accepts `thinkingConfig` on the newer thinking-capable models.
 * Default text generation is `gemini-2.5-flash`, where a zero budget disables
 * thinking for simple copy tasks. If a site overrides the model to an older
 * non-thinking name, omitting the field is safer than sending an unsupported
 * generationConfig key.
 */
function se_ai_model_allows_thinking_budget(string $model, int $budget): bool
{
    $m = strtolower($model);
    if (str_contains($m, 'thinking')) {
        return true;
    }
    if (!str_contains($m, 'gemini-2.5')) {
        return false;
    }

    // Gemini 2.5 Pro requires thinking; do not send the Flash-specific
    // "disable thinking" budget when an administrator has overridden the
    // model to Pro. A positive low budget is still allowed.
    if ($budget <= 0 && str_contains($m, 'pro')) {
        return false;
    }

    return true;
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

/** True when Gemini reports, or usage suggests, a response was cut off. */
function se_ai_response_looks_truncated(?string $finishReason, array $usage, int $maxTokens): bool
{
    $reason = strtoupper(trim((string) $finishReason));
    if (in_array($reason, ['MAX_TOKENS', 'LENGTH', 'TOKEN_LIMIT'], true)) {
        return true;
    }

    $outputTokens = (int) ($usage['output'] ?? 0);
    return $maxTokens > 0 && $outputTokens > 0 && $outputTokens >= max(1, $maxTokens - 8);
}

/**
 * A retry may fail again for the same reason unless the token ceiling grows.
 * Cap at the largest prompt currently used by the module so a malformed answer
 * cannot accidentally turn into an unbounded, expensive loop.
 */
function se_ai_retry_max_tokens(int $maxTokens): int
{
    return min(8192, max($maxTokens + 1024, $maxTokens * 2));
}

/**
 * Summarise bad model output without quoting any of it. The summary is safe to
 * show to the user or send back as the retry hint: no raw prompt, response,
 * secrets or attendee details are included.
 */
function se_ai_json_problem(string $text, ?string $finishReason, array $usage, int $maxTokens): string
{
    if (trim($text) === '') {
        $problem = 'the model returned an empty response instead of JSON';
    } elseif (json_last_error() !== JSON_ERROR_NONE) {
        $problem = 'the model returned malformed JSON (' . json_last_error_msg() . ')';
    } else {
        $problem = 'the top-level JSON value was not an object';
    }

    if (se_ai_response_looks_truncated($finishReason, $usage, $maxTokens)) {
        $problem .= '; it appears to have been truncated before the JSON was closed';
    }

    return $problem;
}

/** A concise, non-sensitive correction sent on the one invalid-output retry. */
function se_ai_retry_hint(array $problems): string
{
    $summary = $problems
        ? implode('; ', array_slice(array_map('strval', $problems), 0, 6))
        : 'the response was missing, malformed or did not match the schema';

    return 'Your previous output was invalid because: ' . $summary
        . '. Return one complete JSON object only, matching the schema exactly. '
        . 'Do not include Markdown, commentary or trailing text; close every string, array and object.';
}

// --------------------------------------------------------------------------
// se_ai()
// --------------------------------------------------------------------------

/**
 * Run an AI task and return validated, post-processed data.
 *
 * @param array $input Task-specific variables. MUST NOT hold attendee PII.
 * @param array $ctx   {user_id?, event_id?, parts?: extra inlineData parts, vision?: bool, provider?: SeAiProvider}
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

    $provider = ($ctx['provider'] ?? null) instanceof SeAiProvider
        ? $ctx['provider']
        : new SeAiGemini($apiKey);
    $attempt  = 0;
    $lastProblems = [];

    while ($attempt < 2) {
        $attempt++;
        $startedAt = microtime(true);
        $usage     = ['input' => 0, 'output' => 0, 'thinking' => 0, 'total' => 0];
        $httpStatus = null;
        $errorCode = null;
        $retryShouldExpandTokens = false;

        try {
            $response   = $provider->generate($request);
            $usage      = is_array($response['usage'] ?? null) ? $response['usage'] : $usage;
            $httpStatus = isset($response['http_status']) ? (int) $response['http_status'] : null;
            $finishReason = (string) ($response['finish_reason'] ?? '');
            $text = (string) ($response['text'] ?? '');

            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                $lastProblems = [se_ai_json_problem($text, $finishReason, $usage, (int) $request['max_tokens'])];
                $retryShouldExpandTokens = se_ai_response_looks_truncated($finishReason, $usage, (int) $request['max_tokens']);
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
                // Retry once with a non-sensitive hint naming what was wrong.
                // If Gemini stopped because of MAX_TOKENS, the retry also gets
                // a larger output budget; otherwise the same ceiling is kept.
                if ($retryShouldExpandTokens) {
                    $request['max_tokens'] = se_ai_retry_max_tokens((int) $request['max_tokens']);
                }
                $request['parts'] = array_merge($parts, [[
                    'text' => se_ai_retry_hint($lastProblems),
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
// Inline files (§15.1): a screenshot or a PDF sent with the prompt
// --------------------------------------------------------------------------

/**
 * Turn one of the event's own uploaded assets into a Gemini `inlineData`
 * part for an import task (programme, song list).
 *
 * The asset must belong to this event and be one of the roles §14.1 marks as
 * AI input, so a stray id can never make the module read an arbitrary file
 * off disk and post it to a third party.
 *
 * @return array{inline_data: array{mime_type: string, data: string}}
 */
function se_ai_inline_asset(PDO $pdo, array $event, int $assetId): array
{
    $asset = se_asset_find($pdo, $assetId);
    if (!$asset || (int) $asset['event_id'] !== (int) $event['id'] || $asset['deleted_at'] !== null) {
        throw new SeValidationException(['asset_id' => 'We could not find that upload.']);
    }
    if (!in_array((string) $asset['role'], SE_ASSET_ROLES_TEMPORARY, true)) {
        throw new SeValidationException(['asset_id' => 'Upload the picture under "AI source" first.']);
    }

    $path = se_docroot() . (string) $asset['path'];
    $real = realpath($path);
    $base = realpath(se_docroot() . '/uploads/se');
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        throw new SeValidationException(['asset_id' => 'That upload is no longer on the server.']);
    }

    $bytes = (string) file_get_contents($real);
    if ($bytes === '' || strlen($bytes) > 15728640) {
        throw new SeValidationException(['asset_id' => 'That file is empty or too large to read.']);
    }

    return [
        'inline_data' => [
            'mime_type' => (string) $asset['mime'],
            'data'      => base64_encode($bytes),
        ],
    ];
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
// Task: copywrite (§15.8)
// --------------------------------------------------------------------------

/**
 * The purpose-specific craft note that replaces the old one-size instruction.
 * Falls back to a neutral line so an unknown purpose can never break the task.
 */
function se_ai_copywrite_guidance(string $purpose): string
{
    return (string) (SE_COPYWRITE_GUIDANCE[$purpose]
        ?? 'Write clearly and warmly, in plain prose a first-time guest would understand.');
}

/**
 * The three distinct angles, numbered, one per variant. Keeping them in the
 * prompt is what stops the model returning three rewordings of one sentence.
 */
function se_ai_copywrite_angles(string $purpose): string
{
    $angles = SE_COPYWRITE_ANGLES[$purpose] ?? [
        'a warm, guest-first angle',
        'an activity-forward, high-energy angle',
        'a community and belonging angle',
    ];

    $lines = [];
    foreach (array_values($angles) as $i => $angle) {
        $lines[] = 'Option ' . ($i + 1) . ': ' . $angle . '.';
    }

    return implode("\n", $lines);
}

/** The extra length sentence for purposes with a word target window. */
function se_ai_copywrite_length_note(string $purpose): string
{
    $target = SE_COPYWRITE_WORD_TARGETS[$purpose] ?? null;
    if (!is_array($target) || count($target) !== 2) {
        return 'Use the full allowance when the piece deserves it; never pad to reach it.';
    }

    [$min, $max] = $target;

    return 'Aim for ' . (int) $min . ' to ' . (int) ($max - 10) . ' words — a full paragraph. '
        . 'Never go over ' . (int) $max . ' words, and never return one thin sentence.';
}

/** The banned-phrase list as one prompt-friendly, quoted string. */
function se_ai_copywrite_banned_list(): string
{
    return implode(', ', array_map(
        static fn(string $p): string => '"' . $p . '"',
        SE_COPYWRITE_BANNED_PHRASES
    ));
}

/** The comparable word set of a variant: lowercase, punctuation-free, deduped. */
function se_ai_copywrite_word_set(string $text): array
{
    $clean = strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text);
    $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    // Very common words carry no signal about whether two options differ.
    $stop = ['the', 'a', 'an', 'and', 'or', 'to', 'of', 'for', 'in', 'on', 'at',
             'is', 'are', 'it', 'you', 'your', 'we', 'our', 'with', 'this', 'that'];

    return array_values(array_unique(array_diff($words, $stop)));
}

/**
 * How alike two variants are, 0 (nothing shared) to 1 (identical word sets).
 * A deliberately simple Jaccard overlap: cheap, explainable, and it cannot
 * throw on odd input.
 */
function se_ai_copywrite_similarity(string $a, string $b): float
{
    $setA = se_ai_copywrite_word_set($a);
    $setB = se_ai_copywrite_word_set($b);
    if (!$setA || !$setB) {
        return $setA === $setB ? 1.0 : 0.0;
    }

    $shared = count(array_intersect($setA, $setB));
    $union  = count(array_unique(array_merge($setA, $setB)));

    return $union > 0 ? round($shared / $union, 4) : 0.0;
}

/**
 * Drop options that are near-copies of one already kept.
 *
 * Deliberately gentle: it never empties the list and never returns fewer than
 * one option, so a strict threshold can degrade the choice on offer but can
 * never turn a successful generation into an error.
 *
 * @param string[] $variants
 * @return string[]
 */
function se_ai_copywrite_drop_near_duplicates(array $variants, ?float $threshold = null): array
{
    $limit = $threshold ?? SE_COPYWRITE_DUPLICATE_THRESHOLD;
    $kept  = [];

    foreach (array_values($variants) as $variant) {
        $isDuplicate = false;
        foreach ($kept as $existing) {
            if (se_ai_copywrite_similarity($existing, $variant) >= $limit) {
                $isDuplicate = true;
                break;
            }
        }
        if (!$isDuplicate) {
            $kept[] = $variant;
        }
    }

    return $kept ?: array_values(array_slice($variants, 0, 1));
}

/**
 * Remove a sentence that is just the event's tagline echoed back. Only an
 * exact-ish match (ignoring case, punctuation and quotes) is removed, so real
 * copy is never mangled, and the variant is left untouched if that would empty
 * it.
 */
function se_ai_copywrite_strip_tagline_echo(string $text, string $tagline): string
{
    $tagline = trim($tagline);
    if ($tagline === '' || mb_strlen($tagline, 'UTF-8') < 6) {
        return $text;
    }

    $normalise = static fn(string $s): string => trim(preg_replace(
        '/\s+/u',
        ' ',
        strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s)
    ) ?? $s);

    $target    = $normalise($tagline);
    $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($sentences) < 2) {
        return $text;
    }

    $kept = array_values(array_filter(
        $sentences,
        static fn(string $s): bool => $normalise($s) !== $target
    ));

    return $kept ? trim(implode(' ', $kept)) : $text;
}

/**
 * Clean the copywriter variants after schema validation. This deliberately
 * keeps longer portal descriptions intact (up to the purpose-specific cap)
 * while still enforcing the SMS GSM/page checks before a human can use one.
 *
 * It also strips an echoed tagline and drops near-duplicate options, but it
 * never rewrites wording and never empties the list: every surviving string
 * still goes to a human for review (§15.1).
 *
 * @param array $context {tagline?: string}
 * @return string[] At most three human-review variants.
 */
function se_ai_copywrite_variants(array $result, string $purpose, array $context = []): array
{
    $maxChars = max(60, (int) (SE_COPYWRITE_CHAR_LIMITS[$purpose] ?? 600));
    $tagline  = (string) ($context['tagline'] ?? '');

    $variants = array_values(array_filter(array_map(
        static function ($v) use ($maxChars, $tagline, $purpose): string {
            $text = se_line($v, $maxChars);
            if ($purpose === 'description' && $tagline !== '') {
                $text = se_ai_copywrite_strip_tagline_echo($text, $tagline);
            }

            return $text;
        },
        (array) ($result['variants'] ?? [])
    )));

    $variants = se_ai_copywrite_drop_near_duplicates($variants);

    if (str_starts_with($purpose, 'sms_') && function_exists('sms_segments')) {
        $variants = array_values(array_filter($variants, static function (string $text): bool {
            $info = sms_segments(str_replace('{{link}}', str_repeat('x', 53), $text));
            return ((int) ($info['pages'] ?? 1)) <= 2 && ($info['encoding'] ?? '') === 'GSM-7';
        }));
    }

    return array_slice($variants, 0, 3);
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
