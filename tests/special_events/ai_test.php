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

/**
 * A long, realistic portal-description variant. Each call must produce copy
 * that is genuinely different from the others, because the post-processor now
 * drops near-duplicate options.
 */
function se_ai_test_long_description(string $opening, string $body): string
{
    return trim($opening . ' ' . trim($body));
}

/** Three distinct 100-ish word description variants, one per prompt angle. */
function se_ai_test_long_variants(): array
{
    return [
        se_ai_test_long_description(
            'Chara is an evening built for anyone who wants somewhere easy to land on a Saturday.',
            'If church is new territory, start here: nobody will put you on a stage, nobody will single you out, '
            . 'and the room is friendly from the door. Come as you are, sit where you like, meet a few faces, '
            . 'and leave knowing the names of people who were genuinely glad you turned up. Nobody here needs you to '
            . 'perform, explain yourself or know a single song. There is food, there '
            . 'is laughter, and there is plenty of space to simply watch the night unfold until you feel like '
            . 'joining in yourself. Doors open early, so arrive whenever suits you best this weekend.'
        ),
        se_ai_test_long_description(
            'Microphones, scoreboards and a very competitive room: that is the shape of the night.',
            'Singers queue up for the mic while teams argue over scripture trivia answers, and between rounds the '
            . 'hosts keep the pace fast enough that nobody checks their phone. Pick a colour, claim your round, '
            . 'and see whether your table can hold the lead until the final buzzer. Expect loud choruses, '
            . 'ridiculous tie-breakers and at least one performance nobody saw coming. Rounds reset often, so a slow start '
            . 'costs your table almost nothing. Bring your voice, bring '
            . 'your best guesses, and settle the question of who really runs this evening once and for all.'
        ),
        se_ai_test_long_description(
            'Most people arrive with one friend and leave having met six more.',
            'That is really what this gathering is about: a roomful of neighbours, students, young families and '
            . 'colleagues sharing one long table and a lot of noise. Conversations start over a shared round, '
            . 'carry on past the last song, and keep going long after the chairs are stacked. Whoever you bring '
            . 'along, they will find somebody worth talking to before the night is over. Regulars make a point of looking '
            . 'out for whoever walked in alone. It is the kind of '
            . 'belonging that is hard to describe and very easy to recognise once you are standing inside it.'
        ),
    ];
}

echo "    copywriter prompt budget\n";
$copyPrompt = se_ai_prompt('copywrite');
ok('copywrite prompt version is at least 3', (int) $copyPrompt['version'] >= 3, $copyPrompt['version']);
ok('copywrite has enough visible output tokens', $copyPrompt['max_tokens'] >= 4096, (string) $copyPrompt['max_tokens']);
is_same('copywrite disables Gemini Flash thinking', 0, $copyPrompt['thinking_budget']);
ok('Gemini 2.5 Flash accepts the zero thinking budget', se_ai_model_allows_thinking_budget('gemini-2.5-flash', 0));
ok('older text models do not receive thinkingConfig', !se_ai_model_allows_thinking_budget('gemini-1.5-flash', 0));

echo "    long portal-description variants\n";
$longVariants = se_ai_test_long_variants();

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

echo "    purpose-specific prompt guidance\n";
$descriptionSystem = se_ai_render_prompt($copyPrompt['system'], [
    'count'       => 3,
    'purpose'     => SE_COPYWRITE_LABELS['description'],
    'limit'       => SE_COPYWRITE_LIMITS['description'],
    'title'       => 'Chara',
    'edition'     => '2026',
    'organizer'   => 'Envision',
    'facts'       => 'date Saturday; venue HOD Lekki; karaoke; Bible games and teams',
    'guidance'    => se_ai_copywrite_guidance('description'),
    'angles'      => se_ai_copywrite_angles('description'),
    'length_note' => se_ai_copywrite_length_note('description'),
    'banned'      => se_ai_copywrite_banned_list(),
]);

ok('no placeholder is left unrendered', !preg_match('/\{\{(?!link\}\})/', $descriptionSystem), $descriptionSystem);
ok('the literal {{link}} token survives rendering', str_contains($descriptionSystem, '{{link}}'));
ok('the description prompt names the three option angles',
    str_contains($descriptionSystem, 'Option 1:')
    && str_contains($descriptionSystem, 'Option 2:')
    && str_contains($descriptionSystem, 'Option 3:'));
ok('variant 1 is the guest-first invitation', str_contains($descriptionSystem, 'guest-first'));
ok('variant 2 is activity-forward', str_contains($descriptionSystem, 'Activity-forward')
    || str_contains($descriptionSystem, 'activity-forward'));
ok('variant 3 is about belonging', str_contains($descriptionSystem, 'belonging'));
ok('the description prompt asks for 70 to 110 words', str_contains($descriptionSystem, '70 to 110 words'));
ok('the description prompt forbids restating the tagline',
    stripos($descriptionSystem, 'tagline') !== false);
ok('the description prompt bans the known clichés',
    str_contains($descriptionSystem, 'something for everyone')
    && str_contains($descriptionSystem, 'good vibes')
    && str_contains($descriptionSystem, 'warm joy'));
ok('the prompt still demands one complete JSON object',
    str_contains($descriptionSystem, 'one complete JSON object'));

$taglineSystem = se_ai_render_prompt($copyPrompt['system'], [
    'count' => 3, 'purpose' => SE_COPYWRITE_LABELS['tagline'],
    'limit' => SE_COPYWRITE_LIMITS['tagline'], 'title' => 'Chara', 'edition' => '2026',
    'organizer' => 'Envision', 'facts' => 'karaoke',
    'guidance' => se_ai_copywrite_guidance('tagline'),
    'angles' => se_ai_copywrite_angles('tagline'),
    'length_note' => se_ai_copywrite_length_note('tagline'),
    'banned' => se_ai_copywrite_banned_list(),
]);
ok('each purpose gets its own guidance', $taglineSystem !== $descriptionSystem);
ok('the tagline prompt keeps the 12-word limit', str_contains($taglineSystem, 'at most 12 words'));
ok('every purpose has guidance and three angles', (function (): bool {
    foreach (SE_COPYWRITE_PURPOSES as $purpose) {
        if (trim(se_ai_copywrite_guidance($purpose)) === ''
            || substr_count(se_ai_copywrite_angles($purpose), 'Option ') !== 3) {
            return false;
        }
    }

    return true;
})());

echo "    quality post-processing\n";
foreach ($longVariants as $i => $variant) {
    $words = count(preg_split('/\s+/u', trim($variant), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    ok('variant ' . ($i + 1) . ' is a full paragraph, not one thin sentence', $words >= 70, (string) $words);
    ok('variant ' . ($i + 1) . ' stays inside the 120-word limit', $words <= 120, (string) $words);
}

ok('distinct variants are not treated as duplicates',
    se_ai_copywrite_similarity($longVariants[0], $longVariants[1]) < SE_COPYWRITE_DUPLICATE_THRESHOLD,
    (string) se_ai_copywrite_similarity($longVariants[0], $longVariants[1]));
ok('an identical variant scores 1.0', se_ai_copywrite_similarity($longVariants[0], $longVariants[0]) >= 1.0);

$nearDuplicate = str_replace(
    ['evening built', 'somewhere easy to land'],
    ['night built', 'a place easy to land'],
    $longVariants[0]
);
ok('a reworded copy of a variant is detected as a near-duplicate',
    se_ai_copywrite_similarity($longVariants[0], $nearDuplicate) >= SE_COPYWRITE_DUPLICATE_THRESHOLD,
    (string) se_ai_copywrite_similarity($longVariants[0], $nearDuplicate));

$withDuplicate = se_ai_copywrite_variants(
    ['variants' => [$longVariants[0], $nearDuplicate, $longVariants[2]]],
    'description'
);
is_same('the near-duplicate option is dropped', 2, count($withDuplicate));
is_same('the first of the pair is the one kept', $longVariants[0], $withDuplicate[0]);

is_same('three identical variants never collapse to nothing', 1, count(se_ai_copywrite_variants(
    ['variants' => [$longVariants[1], $longVariants[1], $longVariants[1]]],
    'description'
)));
is_same('no variants in still means no variants out, not a crash', 0,
    count(se_ai_copywrite_variants(['variants' => []], 'description')));

$tagline = 'Sing loud, play hard, belong here';
$echoed  = $tagline . '. ' . $longVariants[2];
$stripped = se_ai_copywrite_variants(['variants' => [$echoed]], 'description', ['tagline' => $tagline]);
ok('an echoed tagline sentence is removed from a description',
    !str_contains($stripped[0], $tagline), $stripped[0]);
ok('the rest of the description survives tagline stripping',
    str_contains($stripped[0], 'Most people arrive with one friend'));
is_same('a description that is only the tagline is left alone for the human',
    $tagline, se_ai_copywrite_variants(['variants' => [$tagline]], 'description', ['tagline' => $tagline])[0]);
is_same('taglines themselves are never tagline-stripped', 1, count(
    se_ai_copywrite_variants(['variants' => [$tagline]], 'tagline', ['tagline' => $tagline])
));

$longOne = $longVariants[0];
ok('post-processing keeps long copy whole',
    se_ai_copywrite_variants(['variants' => [$longOne]], 'description')[0] === $longOne);

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

echo "    model resolution from .env\n";
(function (): void {
    $saved = [
        'text'   => $_ENV['SE_AI_MODEL_TEXT'] ?? null,
        'vision' => $_ENV['SE_AI_MODEL_VISION'] ?? null,
    ];

    // Unset: the documented default.
    unset($_ENV['SE_AI_MODEL_TEXT'], $_ENV['SE_AI_MODEL_VISION']);
    is_same('text model defaults when unset', SE_AI_MODEL_FALLBACK, se_ai_model(false));
    is_same('vision model defaults when unset', SE_AI_MODEL_FALLBACK, se_ai_model(true));

    // Present but blank — what `SE_AI_MODEL_VISION=""` from .env.example
    // leaves behind, because includes/db.php's loader stores every line it
    // reads. This used to build `…/v1beta/models/:generateContent` and 404,
    // so a programme screenshot reported "AI unavailable" while Health said
    // the key was ready.
    $_ENV['SE_AI_MODEL_TEXT']   = '';
    $_ENV['SE_AI_MODEL_VISION'] = '   ';
    is_same('blank text model falls back', SE_AI_MODEL_FALLBACK, se_ai_model(false));
    is_same('blank vision model falls back', SE_AI_MODEL_FALLBACK, se_ai_model(true));

    // A real override still wins, whitespace and all.
    $_ENV['SE_AI_MODEL_VISION'] = ' gemini-2.5-pro ';
    is_same('an override is used and trimmed', 'gemini-2.5-pro', se_ai_model(true));

    is_same('se_env falls back for a missing key', 'poll', se_env('SE_TEST_MISSING_KEY', 'poll'));
    $_ENV['SE_TEST_BLANK_KEY'] = '""';
    is_same('se_env keeps a literal value', '""', se_env('SE_TEST_BLANK_KEY', 'poll'));
    unset($_ENV['SE_TEST_BLANK_KEY']);

    foreach (['text' => 'SE_AI_MODEL_TEXT', 'vision' => 'SE_AI_MODEL_VISION'] as $k => $env) {
        if ($saved[$k] === null) { unset($_ENV[$env]); } else { $_ENV[$env] = $saved[$k]; }
    }
})();

echo "    an empty model never reaches the provider\n";
(function (): void {
    $gemini = new SeAiGemini('test-key');
    try {
        $gemini->generate([
            'model' => '', 'system' => 's', 'parts' => [['text' => 'x']],
            'temperature' => 0.1, 'schema' => [], 'max_tokens' => 256, 'timeout' => 5,
        ]);
        ok('an empty model name is refused before the HTTP call', false, 'no exception thrown');
    } catch (SeAiException $e) {
        is_same('an empty model name reports AI_UNAVAILABLE', 'AI_UNAVAILABLE', $e->errorCode);
        ok('the message names the setting to fix', str_contains($e->getMessage(), 'SE_AI_MODEL_VISION'), $e->getMessage());
    }
})();
