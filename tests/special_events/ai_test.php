<?php
// /tests/special_events/ai_test.php — copywriter and invalid-output retries.

/** A tiny deterministic provider for se_ai() retry tests. */
class SeAiSequenceProvider implements SeAiProvider
{
    /** @var array<int,array> */
    public array $requests = [];

    /** @param array<int,array> $responses */
    public function __construct(private array $responses) {}

    public function generate(array $request): array
    {
        $this->requests[] = $request;
        if (!$this->responses) {
            throw new SeAiException('AI_UNAVAILABLE', 'No test response queued.');
        }

        return array_shift($this->responses);
    }
}

function se_ai_test_sqlite_pdo(): ?PDO
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return null;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('DATABASE', static fn() => 'se_test');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');

    return $pdo;
}

function se_ai_test_long_description(string $opening): string
{
    return trim($opening . ' ' . implode(' ', array_fill(0, 6,
        'Expect karaoke, Bible games, warm laughter, easy conversation, bright teams and a gentle welcome for friends who are visiting church for the first time.'
    )));
}

echo "    copywriter prompt budget\n";
$copyPrompt = se_ai_prompt('copywrite');
is_same('copywrite prompt version is bumped', '2', $copyPrompt['version']);
ok('copywrite has enough visible output tokens', $copyPrompt['max_tokens'] >= 4096, (string) $copyPrompt['max_tokens']);
is_same('copywrite disables Gemini Flash thinking', 0, $copyPrompt['thinking_budget']);
ok('Gemini 2.5 Flash accepts the zero thinking budget', se_ai_model_allows_thinking_budget('gemini-2.5-flash', 0));
ok('older text models do not receive thinkingConfig', !se_ai_model_allows_thinking_budget('gemini-1.5-flash', 0));

echo "    long portal-description variants\n";
$longVariants = [
    se_ai_test_long_description('Chara is a relaxed night built for joy from the first hello.'),
    se_ai_test_long_description('Step into Chara for a friendly evening where every guest can settle in quickly.'),
    se_ai_test_long_description('Bring someone along to Chara and enjoy a full, cheerful night together.'),
];

foreach ($longVariants as $i => $variant) {
    ok('description variant ' . ($i + 1) . ' is longer than the old 600-char clamp', mb_strlen($variant, 'UTF-8') > 600);
}

is_same(
    'the copywrite schema accepts three longer descriptions',
    [],
    se_schema_validate(['variants' => $longVariants], $copyPrompt['schema'])
);

$cleaned = se_ai_copywrite_variants(['variants' => $longVariants], 'description');
is_same('all three description variants survive post-processing', 3, count($cleaned));
ok('post-processing does not truncate descriptions at 600 chars', mb_strlen($cleaned[0], 'UTF-8') > 600);

$tooFew = ['variants' => array_slice($longVariants, 0, 2)];
ok('the copywrite schema rejects fewer than three variants', se_schema_validate($tooFew, $copyPrompt['schema']) !== []);

$tooLong = ['variants' => [$longVariants[0], $longVariants[1], str_repeat('x', 1201)]];
ok('the copywrite schema rejects over-long variants', se_schema_validate($tooLong, $copyPrompt['schema']) !== []);

echo "    malformed/truncated JSON retry\n";
$pdo = se_ai_test_sqlite_pdo();
if ($pdo === null) {
    ok('malformed JSON retry skipped without pdo_sqlite', true);
} else {
    $_ENV['GEMINI_API_KEY'] = 'test-gemini-key';
    $_ENV['SE_AI_MODEL_TEXT'] = 'gemini-2.5-flash';

    $provider = new SeAiSequenceProvider([
        [
            'text'          => '{"variants":["' . str_repeat('unfinished ', 80),
            'http_status'   => 200,
            'usage'         => ['input' => 100, 'output' => 4096, 'thinking' => 0, 'total' => 4196],
            'model'         => 'gemini-2.5-flash',
            'finish_reason' => 'MAX_TOKENS',
        ],
        [
            'text'          => se_json_encode(['variants' => $longVariants]),
            'http_status'   => 200,
            'usage'         => ['input' => 120, 'output' => 950, 'thinking' => 0, 'total' => 1070],
            'model'         => 'gemini-2.5-flash',
            'finish_reason' => 'STOP',
        ],
    ]);

    $result = se_ai($pdo, 'copywrite', [
        'count'     => 3,
        'purpose'   => SE_COPYWRITE_LABELS['description'],
        'limit'     => SE_COPYWRITE_LIMITS['description'],
        'title'     => 'Chara',
        'edition'   => '2026',
        'organizer' => 'Envision',
        'facts'     => 'date Saturday; venue HOD Lekki; karaoke; Bible games and teams',
    ], ['provider' => $provider, 'event_id' => 123, 'user_id' => 456]);

    is_same('the retry returns the valid JSON result', $longVariants, $result['variants']);
    is_same('the provider was called twice', 2, count($provider->requests));
    ok(
        'truncated retry increases the output token budget',
        $provider->requests[1]['max_tokens'] > $provider->requests[0]['max_tokens'],
        $provider->requests[0]['max_tokens'] . ' -> ' . $provider->requests[1]['max_tokens']
    );

    $retryParts = $provider->requests[1]['parts'];
    $retryHint = $retryParts[array_key_last($retryParts)]['text'] ?? '';
    ok('the retry hint names truncation', str_contains($retryHint, 'truncated'), $retryHint);
    ok('the retry hint asks for complete JSON', str_contains($retryHint, 'complete JSON'), $retryHint);
    ok('the retry hint does not echo raw AI output', !str_contains($retryHint, 'unfinished unfinished'));
}
