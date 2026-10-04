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
    'name'       => 'Midnight Carnival',
    'rationale'  => 'Warm neon against a deep night sky.',
    'accent'     => '#00E5FF',
    'mood_words' => ['joyful', 'electric', 'warm'],
])];

echo "    the real palette schema\n";
is_same('a well-formed response passes', [], se_schema_validate($valid, $palette));

$bad = $valid;
unset($bad['palettes'][0]['accent']);
ok('a missing required key is caught', se_schema_validate($bad, $palette) !== []);

$bad = $valid;
$bad['palettes'] = array_slice($valid['palettes'], 0, 3);
ok('three palettes instead of four is caught', se_schema_validate($bad, $palette) !== []);

$bad = $valid;
$bad['palettes'][] = $valid['palettes'][0];
ok('five palettes is caught', se_schema_validate($bad, $palette) !== []);

$bad = $valid;
$bad['palettes'][1]['mood_words'] = ['only', 'two'];
ok('too few mood words is caught', se_schema_validate($bad, $palette) !== []);

$bad = $valid;
$bad['palettes'][0]['name'] = 42;
ok('a number where a string belongs is caught', se_schema_validate($bad, $palette) !== []);

ok('a missing top-level key is caught', se_schema_validate([], $palette) !== []);
ok('a list where an object belongs is caught', se_schema_validate(['palettes' => 'nope'], $palette) !== []);

echo "    optional keys\n";
$withTeams = $valid;
$withTeams['palettes'][0]['team_suggestions'] = ['#111111', '#222222', '#333333', '#444444'];
is_same('an optional key is accepted when present', [], se_schema_validate($withTeams, $palette));

$badTeams = $withTeams;
$badTeams['palettes'][0]['team_suggestions'] = ['#111111'];
ok('an optional key still has its bounds checked', se_schema_validate($badTeams, $palette) !== []);

echo "    primitives\n";
is_same('a string', [], se_schema_validate('x', ['type' => 'string']));
ok('a number is not a string', se_schema_validate(5, ['type' => 'string']) !== []);
is_same('an integer', [], se_schema_validate(5, ['type' => 'integer']));
is_same('a whole float counts as an integer', [], se_schema_validate(5.0, ['type' => 'integer']));
ok('a fractional float is not an integer', se_schema_validate(5.5, ['type' => 'integer']) !== []);
is_same('a boolean', [], se_schema_validate(true, ['type' => 'boolean']));
ok('a string is not a boolean', se_schema_validate('true', ['type' => 'boolean']) !== []);

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

echo "    prompt loading\n";
$prompt = se_ai_prompt('palette_suggest');
is_same('the version is read from the front matter', '1', $prompt['version']);
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
