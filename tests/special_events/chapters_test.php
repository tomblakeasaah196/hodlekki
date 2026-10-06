<?php
// /tests/special_events/chapters_test.php — the portal chapter catalogue
// (guide §13.3 S3).
//
// Chapters became editable content, which means two new ways to break the
// public page: an icon key that no longer draws anything, and a chapter the
// Studio accepted but the portal cannot render. Both are pure logic, so both
// are covered here.

echo "    the icon catalogue\n";

ok('every default chapter names an icon that exists',
    array_reduce(
        SE_CHAPTER_DEFAULTS,
        static fn(bool $carry, array $row): bool => $carry && isset(SE_CHAPTER_ICONS[$row['icon']]),
        true
    ));

is_same('the four defaults ship in the order the producers asked for',
    ['mic', 'teams', 'games', 'night'],
    array_column(SE_CHAPTER_DEFAULTS, 'chapter_key'));

is_same('a default blurb stays inside the 25-word guidance', [],
    array_values(array_filter(
        array_column(SE_CHAPTER_DEFAULTS, 'blurb'),
        static fn(string $blurb): bool => se_chapter_word_count($blurb) > 25
    )));

foreach (array_keys(SE_CHAPTER_ICONS) as $icon) {
    $svg = se_chapter_icon_svg($icon);
    ok("icon {$icon} draws real markup", str_contains($svg, '<svg') && str_contains($svg, '</svg>')
        || str_contains($svg, 'se-orbit'));
    ok("icon {$icon} carries no style attribute (§19.7)", !str_contains($svg, ' style='));
    ok("icon {$icon} is hidden from assistive technology",
        str_contains($svg, 'aria-hidden="true"') || str_contains($svg, 'se-orbit'));
}

ok('an unknown icon still returns something drawable',
    str_contains(se_chapter_icon_svg('not-a-real-icon'), '<svg'));

ok('the orbit icon paints one orb per real team',
    substr_count(
        se_chapter_icon_svg('orbit', [
            ['index' => 0, 'label' => 'Red'],
            ['index' => 1, 'label' => 'Blue'],
            ['index' => 2, 'label' => 'Gold'],
            ['index' => 3, 'label' => 'Green'],
        ]),
        'class="se-orb '
    ) === 4);

ok('the orbit icon still composes with no teams configured',
    str_contains(se_chapter_icon_svg('orbit', []), 'se-orbit-core'));

ok('the Studio picker shows a plain-SVG still of the orbit, not the CSS one',
    str_contains(
        (string) (array_column(se_chapter_icon_options(), 'svg', 'key')['orbit'] ?? ''),
        'data-se-icon="orbit"'
    ));

ok('the Studio picker offers every icon with its artwork',
    count(se_chapter_icon_options()) === count(SE_CHAPTER_ICONS)
    && array_reduce(
        se_chapter_icon_options(),
        static fn(bool $carry, array $icon): bool => $carry && $icon['label'] !== '' && $icon['svg'] !== '',
        true
    ));

echo "    animation hooks the portal JS looks for\n";

$hooks = [
    'mic'      => ['data-se-mic-ring', 'data-se-mic-bar', 'data-se-mic-glow'],
    'cards'    => ['data-se-card=', 'data-se-buzz-ring'],
    'timeline' => ['data-se-draw', 'data-se-dot'],
    'clock'    => ['data-se-clock-hands'],
    'music'    => ['data-se-note'],
    'people'   => ['data-se-person='],
    'flame'    => ['data-se-flame'],
    'spark'    => ['data-se-twinkle'],
];
foreach ($hooks as $icon => $needles) {
    $svg = se_chapter_icon_svg($icon);
    foreach ($needles as $needle) {
        ok("icon {$icon} keeps the {$needle} hook", str_contains($svg, $needle));
    }
}

echo "    what the Studio accepts\n";

[$clean, $errors] = se_chapter_clean([
    'title'   => '  The   Food  ',
    'blurb'   => 'Jollof, small chops and a queue that moves.',
    'icon'    => 'plate',
    'feature' => '',
]);
is_same('whitespace in a title is collapsed', 'The Food', $clean['title']);
is_same('a clean chapter has no errors', [], $errors);
is_same('no linked feature becomes null', null, $clean['feature']);
is_same('a chapter is shown unless it is told otherwise', true, $clean['is_active']);

[, $errors] = se_chapter_clean(['title' => '', 'icon' => 'mic']);
ok('an empty title is refused', isset($errors['title']));

[, $errors] = se_chapter_clean(['title' => 'The Mic', 'icon' => 'rocket-ship']);
ok('an icon outside the catalogue is refused', isset($errors['icon']));

[$clean] = se_chapter_clean(['title' => 'The Mic', 'icon' => 'mic', 'feature' => 'karaoke']);
is_same('a known feature survives', 'karaoke', $clean['feature']);

[$clean] = se_chapter_clean(['title' => 'The Mic', 'icon' => 'mic', 'feature' => 'finance']);
is_same('an unknown feature is dropped rather than stored', null, $clean['feature']);

[$clean] = se_chapter_clean([
    'title'       => 'The Night',
    'icon'        => 'timeline',
    'is_active'   => false,
    'bg_asset_id' => '41',
]);
is_same('hiding a chapter is honoured', false, $clean['is_active']);
is_same('a background id arrives as an int', 41, $clean['bg_asset_id']);

[$clean] = se_chapter_clean(['title' => 'The Night', 'icon' => 'timeline', 'bg_asset_id' => '']);
is_same('no background is null, not zero', null, $clean['bg_asset_id']);

is_same('a 400-character blurb is capped, not rejected', 400,
    mb_strlen(se_chapter_clean(['title' => 'x', 'icon' => 'mic', 'blurb' => str_repeat('word ', 200)])[0]['blurb']));

echo "    word counting\n";

is_same('an empty blurb is zero words', 0, se_chapter_word_count('   '));
is_same('line breaks do not inflate the count', 3, se_chapter_word_count("one\ntwo   three"));

echo "    the fallback used before the migration runs\n";

$fallback = se_chapters_fallback();
is_same('the fallback is the four defaults', 4, count($fallback));
ok('every fallback row carries what the portal reads',
    array_reduce(
        $fallback,
        static fn(bool $carry, array $row): bool => $carry
            && isset($row['title'], $row['blurb'], $row['icon'], $row['chapter_key'])
            && $row['bg_asset_id'] === null,
        true
    ));

is_same('the fallback orders itself the same way as the seed',
    ['mic', 'teams', 'games', 'night'],
    array_column($fallback, 'chapter_key'));

echo "    the payload the Studio receives\n";

$payload = se_chapter_payload([
    'id'          => 7,
    'chapter_key' => 'the-food',
    'title'       => 'The Food',
    'blurb'       => 'Jollof and small chops.',
    'icon'        => 'plate',
    'feature'     => null,
    'is_active'   => 1,
    'sort_order'  => 30,
    'bg_asset_id' => 12,
    'bg_path'     => '/uploads/se/abc/background/x.webp',
]);
is_same('the payload keeps the id', 7, $payload['id']);
is_same('the payload names the icon in plain language', 'Plate', $payload['icon_label']);
is_same('is_active comes back as a boolean', true, $payload['is_active']);
is_same('the payload counts the words for the editor', 4, $payload['words']);
is_same('the background path travels with it', '/uploads/se/abc/background/x.webp', $payload['bg_path']);

is_same('an icon that was removed from the catalogue falls back to its key',
    'ghost-icon',
    se_chapter_payload(['icon' => 'ghost-icon'])['icon_label']);
