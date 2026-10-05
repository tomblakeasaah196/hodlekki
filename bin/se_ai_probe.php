<?php
// /bin/se_ai_probe.php
//
// A CLI-only probe for the Special Events AI layer (guide §15.1, §28.4).
//
// se_ai() deliberately collapses every provider failure into one sentence a
// Producer can read, which is exactly what you do NOT want when diagnosing
// one: "Image and PDF reading is unavailable right now" hides whether Google
// answered 400, 403, 429 or nothing at all. This script makes the same text
// and image calls the module makes — same model, same prompt, same converted
// responseSchema — and prints the raw HTTP status, the curl errno and
// Google's own message.
//
//   php bin/se_ai_probe.php                     # text + image, both tasks
//   php bin/se_ai_probe.php --text-only         # skip the inline-image call
//   php bin/se_ai_probe.php --file=/path/x.png  # send an exact upload
//   php bin/se_ai_probe.php --model=gemini-2.5-pro
//   php bin/se_ai_probe.php --task=songs_extract
//   php bin/se_ai_probe.php --no-schema         # force the fallback shape
//
// It prints the API key's LENGTH, never the key. The only content it sends
// is the fixed sample programme below (or the file you name), so it can
// never put attendee names, phones or emails in front of the model.
//
// Exit code 0 when every probed call returned 200, 1 otherwise.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$repoRoot = dirname(__DIR__);

// --------------------------------------------------------------------------
// .env, loaded exactly the way includes/db.php loads it
// --------------------------------------------------------------------------
//
// db.php reads $_SERVER['DOCUMENT_ROOT'] . '/.env', which is empty under the
// CLI, so the same parser runs here over the docroot path when the webserver
// set one and the repository root otherwise. Same parser, same quirks — a
// `KEY=""` line still lands as an empty string, which is why the module reads
// optional configuration through se_env().

function se_probe_load_env(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (strpos(trim($line), '#') === 0 || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }

    return true;
}

$envCandidates = array_values(array_unique(array_filter([
    isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
        ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/.env'
        : null,
    $repoRoot . '/.env',
])));

$envLoaded = null;
foreach ($envCandidates as $candidate) {
    if (se_probe_load_env($candidate)) {
        $envLoaded = $candidate;
        break;
    }
}

require_once $repoRoot . '/includes/special_events/constants.php';
require_once $repoRoot . '/includes/special_events/util.php';
require_once $repoRoot . '/includes/special_events/ai.php';

// --------------------------------------------------------------------------
// Arguments
// --------------------------------------------------------------------------

$options = ['text-only' => false, 'no-schema' => false, 'file' => null, 'model' => null, 'task' => 'program_extract'];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/se_ai_probe.php [--text-only] [--file=PATH] [--model=NAME] [--task=NAME] [--no-schema]\n";
        exit(0);
    }
    if ($arg === '--text-only')  { $options['text-only'] = true; continue; }
    if ($arg === '--no-schema')  { $options['no-schema'] = true; continue; }
    if (str_starts_with($arg, '--file='))  { $options['file']  = substr($arg, 7);  continue; }
    if (str_starts_with($arg, '--model=')) { $options['model'] = substr($arg, 8);  continue; }
    if (str_starts_with($arg, '--task='))  { $options['task']  = substr($arg, 7);  continue; }

    fwrite(STDERR, "Unknown option: {$arg}\n");
    exit(2);
}

$apiKey = se_env('GEMINI_API_KEY');

echo "Special Events AI probe\n";
echo str_repeat('=', 64) . "\n";
echo '  .env            : ' . ($envLoaded ?? 'not found (' . implode(', ', $envCandidates) . ')') . "\n";
echo '  GEMINI_API_KEY  : ' . ($apiKey === '' ? 'MISSING' : 'present, ' . strlen($apiKey) . ' characters') . "\n";
echo '  SE_AI_MODEL_TEXT: ' . (trim((string) ($_ENV['SE_AI_MODEL_TEXT'] ?? '')) === '' ? 'unset or blank' : $_ENV['SE_AI_MODEL_TEXT'])
    . '  -> ' . se_ai_model(false) . "\n";
echo '  SE_AI_MODEL_VIS.: ' . (trim((string) ($_ENV['SE_AI_MODEL_VISION'] ?? '')) === '' ? 'unset or blank' : $_ENV['SE_AI_MODEL_VISION'])
    . '  -> ' . se_ai_model(true) . "\n";
echo '  curl            : ' . (function_exists('curl_init') ? (curl_version()['version'] ?? 'present') : 'MISSING') . "\n";

if ($apiKey === '') {
    echo "\nNo API key, so nothing can be probed. Set GEMINI_API_KEY in .env.\n";
    exit(1);
}
if (!function_exists('curl_init')) {
    echo "\nThe curl extension is missing, so the module cannot call Gemini at all.\n";
    exit(1);
}

// --------------------------------------------------------------------------
// One raw call
// --------------------------------------------------------------------------

/**
 * POST one generateContent request and report everything the adapter hides.
 *
 * @param array $parts Gemini content parts (text and/or inline_data).
 * @return array{http_status:int, errno:int, error:string, ms:int, message:string, text:string}
 */
function se_probe_call(string $apiKey, string $model, string $system, array $parts, array $schema, float $temperature, int $maxTokens, int $timeout): array
{
    $generationConfig = [
        'temperature'      => $temperature,
        'responseMimeType' => 'application/json',
        'maxOutputTokens'  => $maxTokens,
    ];
    if ($schema) {
        $generationConfig['responseSchema'] = se_schema_to_openapi($schema);
    }

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents'          => [['role' => 'user', 'parts' => $parts]],
        'generationConfig'  => $generationConfig,
    ];

    $started = microtime(true);
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS     => se_json_encode($payload),
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($body) ? json_decode($body, true) : null;
    $text    = '';
    foreach ($decoded['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (isset($part['text'])) {
            $text .= (string) $part['text'];
        }
    }

    return [
        'http_status' => $status,
        'errno'       => $errno,
        'error'       => $error,
        'ms'          => (int) round((microtime(true) - $started) * 1000),
        'message'     => (string) ($decoded['error']['message'] ?? ''),
        'text'        => $text,
    ];
}

/** Print one probed call and say whether it worked. */
function se_probe_report(string $label, string $model, bool $withSchema, array $result, array $schema): bool
{
    echo "\n" . $label . "\n";
    echo '  model        : ' . $model . ' · responseSchema: ' . ($withSchema ? 'sent' : 'omitted') . "\n";
    echo '  HTTP status  : ' . ($result['http_status'] ?: 'none (no response)') . "\n";
    echo '  curl errno   : ' . $result['errno'] . ($result['error'] !== '' ? ' (' . $result['error'] . ')' : '') . "\n";
    echo '  latency      : ' . $result['ms'] . " ms\n";

    if ($result['message'] !== '') {
        echo '  Google says  : ' . $result['message'] . "\n";
    }

    if ($result['http_status'] !== 200) {
        return false;
    }

    $decoded = json_decode($result['text'], true);
    if (!is_array($decoded)) {
        echo "  result       : 200, but the body was not a JSON object (the module would retry with a hint).\n";
        return false;
    }

    $problems = $schema ? se_schema_validate($decoded, $schema) : [];
    echo '  result       : 200, valid JSON, ' . ($problems
        ? count($problems) . ' schema problem(s): ' . implode('; ', array_slice($problems, 0, 3))
        : 'matches the task schema') . "\n";

    foreach ($decoded as $key => $value) {
        echo '    ' . $key . ': ' . (is_array($value) ? count($value) . ' entries' : se_line((string) $value, 60)) . "\n";
    }

    return $problems === [];
}

// --------------------------------------------------------------------------
// The probes
// --------------------------------------------------------------------------

$task   = preg_match('/^[a-z0-9_]+$/', $options['task']) ? $options['task'] : 'program_extract';
$prompt = se_ai_prompt($task);
$schema = $options['no-schema'] ? [] : $prompt['schema'];

// A fixed sample, written here on purpose: the probe must never read a real
// event's data, and this is the shape a WhatsApp programme arrives in.
$sampleText = "3:30 PM - 4:30 PM Photo Booth and games\n"
    . "4:30 PM Welcome\n"
    . "4:35 PM Bingo\n"
    . "4:45 PM Kahoot\n"
    . "5:00 PM Ministration";

$variables = [
    'kinds' => implode(', ', SE_PROGRAM_KINDS),
    'days'  => se_json_encode([['index' => 0, 'date' => '2026-10-24', 'starts_at' => '2026-10-24 15:00:00', 'ends_at' => '2026-10-24 22:00:00']]),
    'text'  => $sampleText,
];
$system = se_ai_render_prompt($prompt['system'], $variables);

$ok = true;

// 1. The text path — the same call "Read pasted text" makes.
$textModel = $options['model'] ?: se_ai_model(false);
$result = se_probe_call($apiKey, $textModel, $system, [['text' => $sampleText], ['text' => 'Produce the JSON now.']],
    $schema, (float) $prompt['temperature'], (int) $prompt['max_tokens'], 30);
$ok = se_probe_report('[1] text · ' . $task, $textModel, $schema !== [], $result, $prompt['schema']) && $ok;

// The one failure worth re-probing automatically: a rejected schema. This is
// what se_ai() now does by itself, and seeing it here proves the fallback.
if ($result['http_status'] === 400 && $schema !== []
    && preg_match('/too many states|constraint|invalid.*schema/is', $result['message'])) {
    echo "\n  The schema itself was refused. Re-probing without responseSchema,\n"
        . "  which is the fallback se_ai() now performs automatically.\n";
    $retry = se_probe_call($apiKey, $textModel, $system, [
        ['text' => $sampleText],
        ['text' => 'Produce the JSON now.'],
        ['text' => se_ai_schema_fallback_hint($prompt['schema'])],
    ], [], (float) $prompt['temperature'], (int) $prompt['max_tokens'], 30);
    $ok = se_probe_report('[1b] text · ' . $task . ' · no responseSchema', $textModel, false, $retry, $prompt['schema']) && $ok;
}

// 2. The image path — the same call "Upload and read" makes.
if (!$options['text-only']) {
    $visionModel = $options['model'] ?: se_ai_model(true);

    if ($options['file'] !== null) {
        $path = $options['file'];
        if (!is_file($path) || !is_readable($path)) {
            echo "\n[2] image · {$task}\n  the file {$path} does not exist or cannot be read\n";
            exit(1);
        }
        $bytes = (string) file_get_contents($path);
        $mime  = function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream';
        echo "\n  file         : " . $path . ' · ' . $mime . ' · ' . strlen($bytes) . " bytes\n";
    } else {
        // A 1×1 PNG. The point is to exercise the inlineData path and the
        // vision model's schema validation, not to read anything: pass
        // --file=<a real screenshot> to test an exact upload end to end.
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $mime  = 'image/png';
        echo "\n  file         : none given, using a 1x1 PNG (pass --file=PATH to test a real upload)\n";
    }

    $imageParts = [
        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
        ['text' => 'Produce the JSON now.'],
    ];
    $imageResult = se_probe_call($apiKey, $visionModel, $system, $imageParts, $schema,
        (float) $prompt['temperature'], (int) $prompt['max_tokens'], 60);
    $ok = se_probe_report('[2] image · ' . $task, $visionModel, $schema !== [], $imageResult, $prompt['schema']) && $ok;

    if ($imageResult['http_status'] === 400 && $schema !== []
        && preg_match('/too many states|constraint|invalid.*schema/is', $imageResult['message'])) {
        echo "\n  The schema itself was refused on the vision call too. Re-probing\n"
            . "  without responseSchema.\n";
        $retry = se_probe_call($apiKey, $visionModel, $system,
            array_merge($imageParts, [['text' => se_ai_schema_fallback_hint($prompt['schema'])]]), [],
            (float) $prompt['temperature'], (int) $prompt['max_tokens'], 60);
        $ok = se_probe_report('[2b] image · ' . $task . ' · no responseSchema', $visionModel, false, $retry, $prompt['schema']) && $ok;
    }
}

echo "\n" . str_repeat('=', 64) . "\n";
echo $ok
    ? "  Every probed call returned 200 with usable JSON.\n"
    : "  At least one call failed. The status and message above are Google's own.\n";
echo str_repeat('=', 64) . "\n";

exit($ok ? 0 : 1);
