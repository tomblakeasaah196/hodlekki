<?php

namespace HOD\Mail\Ai;

use RuntimeException;
use Throwable;

/**
 * Gemini's generateContent over HTTPS (ported from Courrier's PR 5). One log line on a failure, never the prompt or
 * the answer. Tests replace the network with Gemini::fake(fn (array $request): array => ['status' => 200, 'body' => …]).
 *
 * Shared: Mail (Summariser, Writer), Trainings' AI help (includes/exams/ai.php) and Our sermons' AI
 * (includes/our_sermons/ai.php) call it. The prompt may be a string, or a list of parts — ['text' => …],
 * ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64]] or ['file_data' => ['mime_type' => …,
 * 'file_uri' => …]] — so a PDF can be read natively and an uploaded sermon heard. The last call's token counts are in
 * $lastUsage (for usage logs and cost caps).
 *
 * The Files API (uploadFile, waitActive, deleteFile) holds audio too long to send inline: a resumable upload streamed
 * from disk, then the file is used by URI and deleted. A fake transport sees ['method' => 'POST'|'GET'|'DELETE',
 * 'url', 'headers', 'body' (array, JSON-encoded) or 'file' (a path, streamed), 'timeout'] and may answer 'headers'
 * (lower-case names). generate() requests carry no 'method' and are sent exactly as before.
 */
final class Gemini
{
    /** Why the last call gave nothing: off, refused, busy, unreachable, blocked, empty (Files API: processing) */
    public static ?string $lastFailure = null;

    /** The last call's tokens: ['input' => int, 'output' => int, 'total' => int], or null when nothing came back */
    public static ?array $lastUsage = null;

    /** @var callable|null */
    private static $transport = null;

    public static function fake(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * @param string|list<array> $prompt the user turn: text, or parts (text and inline_data)
     * @param int|null $timeout seconds, when longer than Mail's default is needed (reading a PDF)
     */
    public static function generate(string $system, string|array $prompt, int $maxTokens = 2048, bool $json = false, float $temperature = 0.2, ?int $timeout = null): ?string
    {
        self::$lastFailure = null;
        self::$lastUsage = null;
        $key = Ai::key();
        if ($key === '') {
            self::$lastFailure = 'off';

            return null;
        }
        $model = (string) Ai::config('model', 'gemini-2.5-flash');
        $generation = ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens];
        if ($json) {
            $generation['responseMimeType'] = 'application/json';
        }
        if (str_starts_with($model, 'gemini-2.5-flash')) {
            $generation['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $request = [
            'url' => Ai::config('endpoint') . $model . ':generateContent',
            'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $key],
            'body' => [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => is_array($prompt) ? array_values($prompt) : [['text' => $prompt]]]],
                'generationConfig' => $generation,
            ],
            'timeout' => $timeout,
        ];
        try {
            $response = self::$transport !== null ? (self::$transport)($request) : self::send($request);
        } catch (Throwable $e) {
            self::fail('unreachable', $model, 0, $e instanceof RuntimeException ? $e->getMessage() : get_class($e));

            return null;
        }
        $status = (int) ($response['status'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (is_array($data) && is_array($data['usageMetadata'] ?? null)) {
            $u = $data['usageMetadata'];
            self::$lastUsage = ['input' => (int) ($u['promptTokenCount'] ?? 0), 'output' => (int) ($u['candidatesTokenCount'] ?? 0),
                                'total' => (int) ($u['totalTokenCount'] ?? 0)];
        }
        if ($status < 200 || $status >= 300) {
            self::fail(match (true) {
                in_array($status, [400, 401, 403], true) => 'refused',
                $status === 429, $status === 503 => 'busy',
                default => 'unreachable',
            }, $model, $status);

            return null;
        }
        if (!is_array($data) || !empty($data['promptFeedback']['blockReason'])) {
            self::fail(is_array($data) ? 'blocked' : 'empty', $model, $status);

            return null;
        }
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (is_array($part) && empty($part['thought']) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        if (trim($text) === '') {
            self::fail(($data['candidates'][0]['finishReason'] ?? '') === 'SAFETY' ? 'blocked' : 'empty', $model, $status);

            return null;
        }

        return $text;
    }

    /** A JSON answer, fences removed */
    public static function json(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }
        $data = json_decode(trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text))), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Upload a local file to Gemini's file store: the resumable protocol's start, then one "upload, finalize" request
     * with the bytes streamed from disk. Files expire at Google after 48 hours; callers delete them as soon as done.
     *
     * @return array{name: string, uri: string, mime: string, state: string}|null null with $lastFailure set
     */
    public static function uploadFile(string $path, string $mime, string $displayName = '', int $timeout = 300): ?array
    {
        self::$lastFailure = null;
        $key = Ai::key();
        if ($key === '') {
            self::$lastFailure = 'off';

            return null;
        }
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            self::fail('empty', 'files', 0, 'nothing to upload');

            return null;
        }
        $start = self::call([
            'method' => 'POST',
            'url' => (string) Ai::config('upload_endpoint', 'https://generativelanguage.googleapis.com/upload/v1beta/files'),
            'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $key, 'X-Goog-Upload-Protocol' => 'resumable',
                          'X-Goog-Upload-Command' => 'start', 'X-Goog-Upload-Header-Content-Length' => (string) $size,
                          'X-Goog-Upload-Header-Content-Type' => $mime],
            'body' => ['file' => ['display_name' => mb_substr($displayName !== '' ? $displayName : 'upload', 0, 120)]],
            'timeout' => 30,
        ]);
        if ($start === null) {
            return null;
        }
        // The bytes go only to Google's own upload address.
        $url = (string) ($start['headers']['x-goog-upload-url'] ?? '');
        if (!self::googleUrl($url)) {
            self::fail('empty', 'files', $start['status'], 'no upload address');

            return null;
        }
        $done = self::call([
            'method' => 'POST',
            'url' => $url,
            'headers' => ['x-goog-api-key' => $key, 'Content-Length' => (string) $size, 'X-Goog-Upload-Offset' => '0',
                          'X-Goog-Upload-Command' => 'upload, finalize'],
            'file' => $path,
            'timeout' => $timeout,
        ]);
        $file = $done !== null ? self::fileInfo($done['data']['file'] ?? null) : null;
        if ($done !== null && $file === null) {
            self::fail('empty', 'files', $done['status'], 'no file in the answer');
        }

        return $file;
    }

    /**
     * Wait until an uploaded file can be used (audio is usually ACTIVE at once; long files take a few seconds).
     *
     * @return array{name: string, uri: string, mime: string, state: string}|null
     */
    public static function waitActive(array $file, int $maxSeconds = 120): ?array
    {
        self::$lastFailure = null;
        $deadline = time() + $maxSeconds;
        for ($round = 0; ; $round++) {
            if (($file['state'] ?? '') === 'ACTIVE') {
                return $file;
            }
            if (($file['state'] ?? '') === 'FAILED') {
                self::fail('processing', 'files', 0, 'Google could not process the file');

                return null;
            }
            if ($round > 0 && time() >= $deadline) {
                self::fail('processing', 'files', 0, 'still processing after ' . $maxSeconds . ' s');

                return null;
            }
            if ($round > 0 && self::$transport === null) {
                sleep(3);
            }
            if ($round >= 200) {
                self::fail('processing', 'files', 0, 'too many checks');

                return null;
            }
            $key = Ai::key();
            $name = (string) ($file['name'] ?? '');
            if ($key === '' || !preg_match('~^files/[a-z0-9-]{1,64}$~', $name)) {
                self::fail($key === '' ? 'off' : 'empty', 'files', 0, 'no file to check');

                return null;
            }
            $got = self::call(['method' => 'GET', 'url' => self::filesUrl($name), 'headers' => ['x-goog-api-key' => $key], 'timeout' => 20]);
            $file = $got !== null ? self::fileInfo($got['data'] ?? null) : null;
            if ($file === null) {
                self::$lastFailure ??= 'empty';

                return null;
            }
        }
    }

    /** Delete an uploaded file ("files/abc"); one already gone counts as deleted. */
    public static function deleteFile(string $name): bool
    {
        $key = Ai::key();
        if ($key === '' || !preg_match('~^files/[a-z0-9-]{1,64}$~', $name)) {
            return false;
        }
        try {
            $request = ['method' => 'DELETE', 'url' => self::filesUrl($name), 'headers' => ['x-goog-api-key' => $key], 'timeout' => 20];
            $response = self::$transport !== null ? (self::$transport)($request) : self::send($request);
        } catch (Throwable $e) {
            error_log('Mail AI: Gemini file not deleted (' . $name . ', ' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e)) . ')');

            return false;
        }
        $status = (int) ($response['status'] ?? 0);
        if (($status >= 200 && $status < 300) || $status === 404) {
            return true;
        }
        error_log('Mail AI: Gemini file not deleted (' . $name . ', HTTP ' . $status . ')');

        return false;
    }

    /** One Files API request: the decoded answer and its headers, or null with $lastFailure set */
    private static function call(array $request): ?array
    {
        try {
            $response = self::$transport !== null ? (self::$transport)($request) : self::send($request);
        } catch (Throwable $e) {
            self::fail('unreachable', 'files', 0, $e instanceof RuntimeException ? $e->getMessage() : get_class($e));

            return null;
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            self::fail(match (true) {
                in_array($status, [400, 401, 403, 413], true) => 'refused',
                $status === 429, $status === 503 => 'busy',
                default => 'unreachable',
            }, 'files', $status);

            return null;
        }
        $headers = [];
        foreach ((array) ($response['headers'] ?? []) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }
        $data = json_decode((string) ($response['body'] ?? ''), true);

        return ['status' => $status, 'data' => is_array($data) ? $data : [], 'headers' => $headers];
    }

    /** @return array{name: string, uri: string, mime: string, state: string}|null */
    private static function fileInfo(mixed $f): ?array
    {
        if (!is_array($f) || !preg_match('~^files/[a-z0-9-]{1,64}$~', (string) ($f['name'] ?? '')) || !self::googleUrl((string) ($f['uri'] ?? ''))) {
            return null;
        }

        return ['name' => (string) $f['name'], 'uri' => (string) $f['uri'], 'mime' => (string) ($f['mimeType'] ?? ''),
                'state' => (string) ($f['state'] ?? 'ACTIVE')];
    }

    private static function filesUrl(string $name): string
    {
        return rtrim((string) Ai::config('files_endpoint', 'https://generativelanguage.googleapis.com/v1beta/files'), '/') . '/' . substr($name, 6);
    }

    /** An https address on Google's Gemini host (the upload endpoint's host) */
    private static function googleUrl(string $url): bool
    {
        $host = parse_url((string) Ai::config('upload_endpoint', 'https://generativelanguage.googleapis.com/upload/v1beta/files'), PHP_URL_HOST);

        return str_starts_with($url, 'https://') && is_string($host) && parse_url($url, PHP_URL_HOST) === $host;
    }

    private static function fail(string $why, string $model, int $status, string $detail = ''): void
    {
        self::$lastFailure = $why;
        error_log('Mail AI: Gemini ' . $why . ' (model ' . $model . ($status ? ', HTTP ' . $status : '') . ($detail !== '' ? ', ' . $detail : '') . ')');
    }

    /**
     * POST with a JSON body (generate(), and the upload's start), POST streaming a file ('file'), GET or DELETE.
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private static function send(array $request): array
    {
        $method = strtoupper((string) ($request['method'] ?? 'POST'));
        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl missing');
        }
        $in = null;
        $responseHeaders = [];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($request['timeout'] ?? 0) ?: (int) Ai::config('timeout', 25),
            CURLOPT_CONNECTTIMEOUT => (int) Ai::config('connect_timeout', 5),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }

                return strlen($line);
            },
        ];
        if (isset($request['file'])) {
            $in = @fopen((string) $request['file'], 'rb');
            if ($in === false) {
                throw new RuntimeException('file unreadable');
            }
            $headers[] = 'Expect:';
            $options += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => (int) filesize((string) $request['file']),
                         CURLOPT_CUSTOMREQUEST => $method];
        } elseif ($method === 'POST') {
            $options += [CURLOPT_POST => true,
                         CURLOPT_POSTFIELDS => (string) json_encode($request['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)];
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, $options);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if (is_resource($in)) {
            fclose($in);
        }
        if ($out === false || $errno !== 0) {
            throw new RuntimeException('curl ' . $errno);
        }

        return ['status' => $status, 'body' => (string) $out, 'headers' => $responseHeaders];
    }
}
