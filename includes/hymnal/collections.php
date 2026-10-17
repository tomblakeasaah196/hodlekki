<?php
// /includes/hymnal/collections.php
//
// The shelves of the library.
//
// The Pastor asked for one hymnary that works for a Sunday service and for a
// member's morning devotion, so songs are filed by what they are FOR, not by
// which book they came out of. A song sits on as many shelves as fit it, and
// every song is on 'hymns' or 'worship' so nothing is unreachable by browsing.
//
// This list is code, not data, on purpose: the corpus build reads `themes` to
// file the generated hymns automatically, and the Studio offers `studio: true`
// shelves when someone adds a song. Keeping it in one place stops the two
// drifting apart. Adding a shelf is an edit here plus a corpus rebuild; it
// needs no migration.

/**
 * slug => [
 *   name   => what a member sees
 *   blurb  => one line under the heading
 *   themes => source themes that file a generated hymn here ('*' = every hymn)
 *   studio => may someone file a Studio song here
 *   order  => sort position in the shelf list
 * ]
 */
const HYMNAL_COLLECTIONS = [
    'hymns' => [
        'name'   => 'Hymns',
        'blurb'  => 'The hymns of the church, with four-part music for the choir.',
        'themes' => '*',
        'studio' => true,
        'order'  => 10,
    ],
    'worship' => [
        'name'   => 'Worship & Praise',
        'blurb'  => 'Songs the church sings now, added by the music team.',
        'themes' => [],
        'studio' => true,
        'order'  => 20,
    ],
    'choruses' => [
        'name'   => 'Choruses',
        'blurb'  => 'Short songs the congregation already knows by heart.',
        'themes' => [],
        'studio' => true,
        'order'  => 30,
    ],
    'yoruba' => [
        'name'   => 'Yoruba',
        'blurb'  => 'Songs we sing in Yoruba, on their own shelf as well as with their English.',
        'themes' => [],
        'studio' => true,
        'order'  => 40,
    ],
    'praise' => [
        'name'   => 'Praise & Adoration',
        'blurb'  => 'For opening worship and thanksgiving.',
        'themes' => ['Praise', 'Adoration', 'Thanksgiving'],
        'studio' => true,
        'order'  => 50,
    ],
    'cross' => [
        'name'   => 'The Cross',
        'blurb'  => 'The suffering and death of our Lord.',
        'themes' => ['Cross', 'Lent'],
        'studio' => true,
        'order'  => 60,
    ],
    'grace' => [
        'name'   => 'Grace & Salvation',
        'blurb'  => 'Repentance, grace and the call to come.',
        'themes' => ['Grace', 'Salvation', 'Confession'],
        'studio' => true,
        'order'  => 70,
    ],
    'faith' => [
        'name'   => 'Faith & Trust',
        'blurb'  => 'Standing on the promises.',
        'themes' => ['Faith', 'Trust', 'Assurance'],
        'studio' => true,
        'order'  => 80,
    ],
    'comfort' => [
        'name'   => 'Comfort & Hope',
        'blurb'  => 'For the weary, the waiting and the grieving.',
        'themes' => ['Comfort', 'Hope', 'Peace', 'Providence'],
        'studio' => true,
        'order'  => 90,
    ],
    'christmas' => [
        'name'   => 'Christmas & Advent',
        'blurb'  => 'Carols and the songs of his coming.',
        'themes' => ['Christmas', 'Advent'],
        'studio' => true,
        'order'  => 100,
    ],
    'easter' => [
        'name'   => 'Easter',
        'blurb'  => 'He is risen.',
        'themes' => ['Easter'],
        'studio' => true,
        'order'  => 110,
    ],
    'communion' => [
        'name'   => 'Communion',
        'blurb'  => 'At the Lord’s table.',
        'themes' => ['Communion'],
        'studio' => true,
        'order'  => 120,
    ],
    'baptism' => [
        'name'   => 'Baptism',
        'blurb'  => 'For baptisms and dedications.',
        'themes' => ['Baptism'],
        'studio' => true,
        'order'  => 130,
    ],
    'church' => [
        'name'   => 'The Church & the Trinity',
        'blurb'  => 'The body of Christ, the Spirit and the Creator.',
        'themes' => ['Church', 'Trinity', 'Creation', 'Holy Spirit'],
        'studio' => true,
        'order'  => 140,
    ],
    'mission' => [
        'name'   => 'Mission & Sending',
        'blurb'  => 'Going out, and the closing of a service.',
        'themes' => ['Mission', 'Sending'],
        'studio' => true,
        'order'  => 150,
    ],
    'prayer' => [
        'name'   => 'Prayer',
        'blurb'  => 'Songs that are themselves prayers.',
        'themes' => ['Prayer'],
        'studio' => true,
        'order'  => 160,
    ],
    'kids' => [
        'name'   => 'Junior Church',
        'blurb'  => 'Simple songs for the children.',
        'themes' => ['Kids'],
        'studio' => true,
        'order'  => 170,
    ],
    'funeral' => [
        'name'   => 'Funerals',
        'blurb'  => 'For a service of thanksgiving for a life.',
        'themes' => ['Funeral'],
        'studio' => true,
        'order'  => 180,
    ],
    'wedding' => [
        'name'   => 'Weddings',
        'blurb'  => 'For a marriage service.',
        'themes' => ['Wedding'],
        'studio' => true,
        'order'  => 190,
    ],
];

/** Does this shelf exist? */
function hymnal_collection_exists(string $slug): bool
{
    return isset(HYMNAL_COLLECTIONS[$slug]);
}

/** Shelves someone may file a Studio song on, in display order. */
function hymnal_studio_collections(): array
{
    $out = [];
    foreach (HYMNAL_COLLECTIONS as $slug => $c) {
        if (!empty($c['studio'])) {
            $out[$slug] = $c;
        }
    }
    uasort($out, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

    return $out;
}

/**
 * Which shelves a generated hymn belongs on, from the themes its source gave
 * it. Always includes 'hymns', so a hymn with no themes at all is still
 * reachable by browsing.
 *
 * @param list<string> $themes
 * @return list<string>
 */
function hymnal_collections_for_themes(array $themes): array
{
    $want = [];
    foreach ($themes as $t) {
        $want[strtolower(trim($t))] = true;
    }

    $out = [];
    foreach (HYMNAL_COLLECTIONS as $slug => $c) {
        if ($c['themes'] === '*') {
            $out[] = $slug;
            continue;
        }
        foreach ((array) $c['themes'] as $t) {
            if (isset($want[strtolower($t)])) {
                $out[] = $slug;
                break;
            }
        }
    }

    return $out;
}
