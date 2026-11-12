<?php

namespace HOD\Mail\Ai;

use RuntimeException;
use Throwable;

/**
 * Gemini's generateContent over HTTPS (ported from Courrier's PR 5). One log line on a failure, never the prompt or
 * the answer. Tests replace the network with Gemini::fake(fn (array $request): array => ['status' => 200, 'body' => …]).
 *
 * Shared: Mail (Summariser, Writer) and Trainings' AI help (includes/exams/ai.php) both call it. The prompt may be a
 * string, or a list of parts — ['text' => …] or ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64]]
 * — so a PDF can be read natively. The last call's token counts are in $lastUsage (for usage logs and cost caps).
 */
final class Gemini
{
    /** Why the last call gave nothing: off, refused, busy, unreachable, blocked, empty */
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

    private static function fail(string $why, string $model, int $status, string $detail = ''): void
    {
        self::$lastFailure = $why;
        error_log('Mail AI: Gemini ' . $why . ' (model ' . $model . ($status ? ', HTTP ' . $status : '') . ($detail !== '' ? ', ' . $detail : '') . ')');
    }

    /** @return array{status: int, body: string} */
    private static function send(array $request): array
    {
        $body = (string) json_encode($request['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl missing');
        }
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($request['timeout'] ?? 0) ?: (int) Ai::config('timeout', 25),
            CURLOPT_CONNECTTIMEOUT => (int) Ai::config('connect_timeout', 5),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($out === false || $errno !== 0) {
            throw new RuntimeException('curl ' . $errno);
        }

        return ['status' => $status, 'body' => (string) $out];
    }
}
