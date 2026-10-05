<?php
// /tests/special_events/schema_test.php — se_schema_validate() against the
// Appendix D schemas (guide §15.1, §22.1).
//
// This is what stands between a hallucinated AI response and the database:
// nothing reaches a review screen unless it matches the task's schema.

$palette = json_decode(
    (string) file_get_contents(__DIR__ . '/../../includes/special_events/prompts/palette_suggest.schema.json'),
    true
);

/** A response that should pass the palette schema. */
$valid = ['palettes' => array_fill(0, 4, [
    'name'             => 'Midnight Carnival',
    'rationale'        => 'Warm neon against a deep night sky.',
    'accent'           => '#00E5FF',
    'team_suggestions' => ['#FF3366', '#00E5FF', '#FFD166', '#8C6CFF'],
    'mood_words'       => ['joyful', 'electric', 'warm'],
])];

echo "    the real palette schema\n";
is_same('a well-formed response passes', [], se_schema_validate($valid, $palette));

$bad = $valid;
unset($bad['palettes'][0]['accent']);
ok('a missing required key is caught', se_schema_validate($bad, $palette) !== []);

$bad = $valid;
$bad['palettes'] = array_slice($valid['palettes'], 0, 3);
ok('three palettes instead of four is caught', se_schema_validate($bad, $palette) !== []);

$more = $valid;
$more['palettes'][] = $valid['palettes'][0];
is_same('the serving schema leaves the palette cap to PHP', [], se_schema_validate($more, $palette));
ok('the palette object array has no maxItems serving constraint',
    !isset($palette['properties']['palettes']['maxItems']));

$bad = $valid;
$bad['palettes'][1]['mood_words'] = ['only', 'two'];
ok('too few mood words is caught', se_schema_validate($bad, $palette) !== []);

$bad = $valid;
$bad['palettes'][0]['name'] = 42;
ok('a number where a string belongs is caught', se_schema_validate($bad, $palette) !== []);

ok('a missing top-level key is caught', se_schema_validate([], $palette) !== []);
ok('a list where an object belongs is caught', se_schema_validate(['palettes' => 'nope'], $palette) !== []);

echo "    deterministic object shapes\n";
$missingTeams = $valid;
unset($missingTeams['palettes'][0]['team_suggestions']);
ok('team colours are required instead of multiplying optional shapes',
    se_schema_validate($missingTeams, $palette) !== []);

$badTeams = $valid;
$badTeams['palettes'][0]['team_suggestions'] = ['#111111'];
ok('a required key still has its minimum checked', se_schema_validate($badTeams, $palette) !== []);

echo "    primitives\n";
is_same('a string', [], se_schema_validate('x', ['type' => 'string']));
ok('a number is not a string', se_schema_validate(5, ['type' => 'string']) !== []);
is_same('an integer', [], se_schema_validate(5, ['type' => 'integer']));
is_same('a whole float counts as an integer', [], se_schema_validate(5.0, ['type' => 'integer']));
ok('a fractional float is not an integer', se_schema_validate(5.5, ['type' => 'integer']) !== []);
is_same('a boolean', [], se_schema_validate(true, ['type' => 'boolean']));
ok('a string is not a boolean', se_schema_validate('true', ['type' => 'boolean']) !== []);
is_same('nullable permits null without weakening non-null values', [],
    se_schema_validate(null, ['type' => 'string', 'nullable' => true]));
ok('null is rejected unless nullable is explicit',
    se_schema_validate(null, ['type' => 'string']) !== []);

echo "    bounds\n";
ok('minLength', se_schema_validate('a', ['type' => 'string', 'minLength' => 3]) !== []);
ok('maxLength', se_schema_validate('abcd', ['type' => 'string', 'maxLength' => 3]) !== []);
ok('minimum', se_schema_validate(1, ['type' => 'integer', 'minimum' => 5]) !== []);
ok('maximum', se_schema_validate(9, ['type' => 'integer', 'maximum' => 5]) !== []);
is_same('a value on the boundary passes', [], se_schema_validate(5, ['type' => 'integer', 'minimum' => 5, 'maximum' => 5]));

echo "    enums\n";
$enum = ['type' => 'string', 'enum' => ['a', 'b']];
is_same('a listed value passes', [], se_schema_validate('a', $enum));
ok('an unlisted value is caught', se_schema_validate('c', $enum) !== []);

echo "    arrays\n";
$list = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 2];
is_same('a valid list', [], se_schema_validate(['x'], $list));
ok('too few items', se_schema_validate([], $list) !== []);
ok('too many items', se_schema_validate(['a', 'b', 'c'], $list) !== []);
ok('a bad item type is caught', se_schema_validate([1], $list) !== []);
ok('the failing index is named', str_contains(implode(' ', se_schema_validate([1], $list)), '[0]'));

echo "    error messages name the path\n";
$problems = se_schema_validate(['palettes' => [['name' => 1]]], $palette);
ok('a path is included', str_contains(implode(' ', $problems), '$.palettes[0]'), implode('; ', $problems));

echo "    conversion to Gemini's OpenAPI subset\n";
$openapi = se_schema_to_openapi($palette);
is_same('the type is upper-cased', 'OBJECT', $openapi['type']);
is_same('nested types are upper-cased', 'ARRAY', $openapi['properties']['palettes']['type']);
is_same('item types are upper-cased', 'OBJECT', $openapi['properties']['palettes']['items']['type']);
ok('required survives', in_array('palettes', $openapi['required'], true));
ok('minItems survives', ($openapi['properties']['palettes']['minItems'] ?? null) === 4);

$extra = se_schema_to_openapi(['type' => 'string', 'pattern' => '^x$', 'additionalProperties' => false]);
ok('unsupported keys are stripped', !array_key_exists('pattern', $extra) && !array_key_exists('additionalProperties', $extra));

echo "    serving-schema complexity\n";
foreach (['program_extract' => 9, 'songs_extract' => 3] as $task => $requiredCount) {
    $taskSchema = json_decode((string) file_get_contents(
        __DIR__ . '/../../includes/special_events/prompts/' . $task . '.schema.json'
    ), true);
    $listKey = $task === 'program_extract' ? 'items' : 'songs';
    $listSchema = $taskSchema['properties'][$listKey];
    $itemSchema = $listSchema['items'];

    ok($task . ' has no nested-object maxItems constraint', !isset($listSchema['maxItems']));
    is_same($task . ' requires every item property', count($itemSchema['properties']), count($itemSchema['required']));
    is_same($task . ' has the audited property count', $requiredCount, count($itemSchema['properties']));
}
foreach (['palette_suggest' => 'palettes', 'deck_generate' => 'items', 'verses_suggest' => 'verses'] as $task => $listKey) {
    $taskSchema = json_decode((string) file_get_contents(
        __DIR__ . '/../../includes/special_events/prompts/' . $task . '.schema.json'
    ), true);
    ok($task . ' also leaves its object-array cap to PHP',
        !isset($taskSchema['properties'][$listKey]['maxItems']));
}

echo "    the schema sketch used when responseSchema is refused\n";
$outline = se_schema_outline($palette);
is_same('the sketch is derived from the schema itself',
    '{"palettes": [{"name": string, "rationale": string, "accent": string, '
    . '"team_suggestions": [string, …], "mood_words": [string, …]}, …]}',
    $outline);
is_same('a nullable property is marked', 'string|null',
    se_schema_outline(['type' => 'string', 'nullable' => true]));
is_same('an enum lists its values', '"easy"|"medium"|"hard"',
    se_schema_outline(['type' => 'string', 'enum' => ['easy', 'medium', 'hard']]));
is_same('a free-form object stays a word', 'object', se_schema_outline(['type' => 'object']));
ok('the sketch cannot recurse forever', str_contains(
    se_schema_outline(['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array',
        'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array',
            'items' => ['type' => 'array', 'items' => ['type' => 'string']]]]]]]]),
    'value'
));
ok('the fallback hint names the shape and forbids Markdown',
    str_contains(se_ai_schema_fallback_hint($palette), '"palettes"')
    && str_contains(se_ai_schema_fallback_hint($palette), 'No Markdown'));

echo "    prompt loading\n";
$prompt = se_ai_prompt('palette_suggest');
is_same('the version is read from the front matter', '2', $prompt['version']);
is_same('the temperature is read', 0.8, $prompt['temperature']);
is_same('max tokens are read', 2048, $prompt['max_tokens']);
ok('the front matter is not left in the body', !str_contains($prompt['system'], '---'), substr($prompt['system'], 0, 60));
ok('the body carries the instructions', str_contains($prompt['system'], 'PRIMARY'));
ok('the schema is loaded beside it', ($prompt['schema']['required'] ?? []) === ['palettes']);
throws('an unknown task is refused', static fn() => se_ai_prompt('no_such_task'), SeAiException::class);
throws('a traversal in the task name is refused', static fn() => se_ai_prompt('../../../etc/passwd'), SeAiException::class);

echo "    prompt rendering\n";
$rendered = se_ai_render_prompt('A {{title}} with {{mood}}.', ['title' => 'Night', 'mood' => ['joy', 'neon']]);
is_same('scalars and lists both substitute', 'A Night with joy, neon.', $rendered);
is_same('an unknown placeholder is left alone', 'Hi {{nope}}', se_ai_render_prompt('Hi {{nope}}', []));
