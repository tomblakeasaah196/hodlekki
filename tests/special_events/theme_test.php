<?php
// /tests/special_events/theme_test.php — the OKLCH engine (guide §13.2).
//
// The vectors in fixtures/theme_vectors.json are shared with
// js/theme.test.mjs: both must produce identical tokens, which is what keeps
// the Studio's live preview honest about what the portal will render.

$vectors = fixture('theme_vectors.json');

echo "    hex handling\n";
is_same('uppercases', '#1D356A', se_normalize_hex('#1d356a'));
is_same('adds the hash', '#1D356A', se_normalize_hex('1D356A'));
is_same('expands shorthand', '#112233', se_normalize_hex('#123'));
is_same('trims', '#FFFFFF', se_normalize_hex('  #ffffff  '));
is_same('rejects a bad length', null, se_normalize_hex('#12345'));
is_same('rejects non-hex', null, se_normalize_hex('#GGGGGG'));
is_same('rejects a word', null, se_normalize_hex('red'));
is_same('rejects a non-string', null, se_normalize_hex(123));

echo "    colour maths\n";
[$L, $C, $H] = se_hex_to_oklch('#000000');
ok('black has lightness 0', abs($L) < 1e-6, (string) $L);
ok('black has no chroma', abs($C) < 1e-6, (string) $C);
[$L, $C] = se_hex_to_oklch('#FFFFFF');
ok('white has lightness 1', abs($L - 1.0) < 1e-3, (string) $L);
ok('white has no chroma', abs($C) < 1e-3, (string) $C);

// A round trip through OKLCH must land on the same hex.
foreach (['#1D356A', '#D11920', '#22C55E', '#FACC15', '#7C3AED', '#808080'] as $hex) {
    [$l, $c, $h] = se_hex_to_oklch($hex);
    is_same("round trip {$hex}", $hex, se_oklch_to_hex($l, $c, $h));
}

echo "    contrast\n";
ok('black on white is 21:1', abs(se_contrast_ratio('#000000', '#FFFFFF') - 21.0) < 0.01);
ok('a colour against itself is 1:1', abs(se_contrast_ratio('#D11920', '#D11920') - 1.0) < 0.001);
ok('the ratio is symmetric',
    abs(se_contrast_ratio('#1D356A', '#FFFFFF') - se_contrast_ratio('#FFFFFF', '#1D356A')) < 1e-9);
is_same('white reads better on a dark colour', '#FFFFFF', se_best_on_color('#1D356A'));
is_same('black reads better on a light colour', '#0B0B0F', se_best_on_color('#FACC15'));

echo "    gamut clipping\n";
// An impossible chroma must still produce a valid hex of the right hue.
$clipped = se_oklch_to_hex(0.7, 0.9, 150.0);
ok('an out-of-gamut colour still yields valid hex', se_normalize_hex($clipped) !== null, $clipped);
ok('clipping only reduces chroma, keeping lightness close',
    abs(se_hex_to_oklch($clipped)[0] - 0.7) < 0.02);

echo "    delta E\n";
ok('a colour is identical to itself', se_color_delta_e('#D11920', '#D11920') < 1e-9);
ok('black and white are far apart', se_color_delta_e('#000000', '#FFFFFF') > 0.9);
ok('two near-identical reds are close', se_color_delta_e('#D11920', '#D21921') < 0.01);

echo "    Marquee derivation — every vector\n";
foreach ($vectors['cases'] as $i => $case) {
    $theme = se_theme_derive($case['primary'], $case['secondary'], $case['accent']);

    foreach ($case['tokens'] as $name => $expected) {
        is_same("case {$i} {$case['primary']}/{$case['secondary']} {$name}", $expected, $theme['tokens'][$name]);
    }
}

echo "    accessibility guarantees hold for every vector\n";
foreach ($vectors['cases'] as $i => $case) {
    $theme  = se_theme_derive($case['primary'], $case['secondary'], $case['accent']);
    $tokens = $theme['tokens'];

    ok("case {$i}: body text clears 4.5:1",
        se_contrast_ratio($tokens['--se-text'], $tokens['--se-bg']) >= 4.5,
        (string) round(se_contrast_ratio($tokens['--se-text'], $tokens['--se-bg']), 2));
    ok("case {$i}: muted text clears 4.5:1",
        se_contrast_ratio($tokens['--se-text-muted'], $tokens['--se-bg']) >= 4.5,
        (string) round(se_contrast_ratio($tokens['--se-text-muted'], $tokens['--se-bg']), 2));
    ok("case {$i}: primary clears 3:1 on the background",
        se_contrast_ratio($tokens['--se-primary'], $tokens['--se-bg']) >= 3.0,
        (string) round(se_contrast_ratio($tokens['--se-primary'], $tokens['--se-bg']), 2));
    ok("case {$i}: text on a primary button clears 4.5:1",
        se_contrast_ratio($tokens['--se-on-primary'], $tokens['--se-primary']) >= 4.5,
        (string) round(se_contrast_ratio($tokens['--se-on-primary'], $tokens['--se-primary']), 2));
    ok("case {$i}: text on a secondary button clears 4.5:1",
        se_contrast_ratio($tokens['--se-on-secondary'], $tokens['--se-secondary']) >= 4.5,
        (string) round(se_contrast_ratio($tokens['--se-on-secondary'], $tokens['--se-secondary']), 2));
    ok("case {$i}: every contrast row reports passing",
        !in_array(false, array_column($theme['contrast'], 'passes'), true));
}

echo "    the palette is cached by a hash of its inputs\n";
$a = se_theme_derive('#1D356A', '#D11920');
$b = se_theme_derive('#1D356A', '#D11920');
is_same('the same inputs give the same hash', $a['hash'], $b['hash']);
ok('different inputs give a different hash', $a['hash'] !== se_theme_derive('#1D356A', '#D11921')['hash']);
ok('the accent is part of the hash', $a['hash'] !== se_theme_derive('#1D356A', '#D11920', '#00E5FF')['hash']);

echo "    team colours\n";
$bg = $vectors['team_bg'];
foreach ($vectors['teams'] as $expected) {
    $actual = se_team_tokens($expected['color'], $bg, (int) $expected['index']);
    foreach (['color', 'on', 'glow', 'needs_ring'] as $key) {
        is_same("team {$expected['color']} {$key}", $expected[$key], $actual[$key]);
    }
    // A contrast ratio is a float; JSON drops the ".0" on a whole number, so
    // compare numerically rather than by identity.
    ok("team {$expected['color']} ratio_vs_bg",
        abs((float) $expected['ratio_vs_bg'] - (float) $actual['ratio_vs_bg']) < 0.005,
        $expected['ratio_vs_bg'] . ' vs ' . $actual['ratio_vs_bg']);
}

$black = se_team_tokens('#000000', $bg, 1);
ok('a black team on a dark stage gets a ring', $black['needs_ring']);
ok('a black team keeps a neutral glow',
    se_hex_to_oklch($black['glow'])[1] < 0.01, $black['glow']);
ok('a bright team needs no ring', !se_team_tokens('#22C55E', $bg, 2)['needs_ring']);

echo "    similarity warnings\n";
is_same('four distinct colours raise nothing', [],
    se_team_similarity_warnings(['#000000', '#E11D48', '#22C55E', '#3B82F6']));
ok('two near-identical colours are flagged',
    count(se_team_similarity_warnings(['#D11920', '#D21921', '#22C55E'])) === 1);

echo "    CSS rendering\n";
$css = se_theme_css_vars($a, [se_team_tokens('#000000', $bg, 1)]);
ok('declares a :root block', str_starts_with($css, ':root {'));
ok('includes a brand token', str_contains($css, '--se-primary:'));
ok('includes the team token', str_contains($css, '--team-1:'));
ok('a ringed team gets a visible ring', str_contains($css, '--team-1-ring: rgb(255 255 255 / 0.85)'));
ok('no stray closing brace could escape the block', substr_count($css, '}') === 1);
