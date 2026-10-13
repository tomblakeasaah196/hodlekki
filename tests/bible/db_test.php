<?php
// /tests/bible/db_test.php — the module against a real (SQLite) database
// built from db/migrations/20261114090000_bible_core.sql.

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIP: pdo_sqlite is not available in this PHP build.\n";
    return;
}

$pdo = bible_test_db();
$ME = 101;
$OTHER = 202;

section('schema');
ok('migration translates and applies', true);
eq('bible_ready', true, bible_ready($pdo));

// ---------------------------------------------------------------------------
section('reader state');
$s = bible_state_get($pdo, $ME);
eq('defaults before first save', ['KJV', 43, 1, false], [$s['translation'], $s['book'], $s['chapter'], $s['saved']]);
bible_state_save($pdo, $ME, ['translation' => 'niv', 'book' => 19, 'chapter' => 23, 'verse' => 4, 'prefs' => ['theme' => 'night', 'size' => 99, 'font' => 'comic']]);
$s = bible_state_get($pdo, $ME);
eq('saved position', ['NIV', 19, 23, 4], [$s['translation'], $s['book'], $s['chapter'], $s['verse']]);
eq('prefs cleaned', ['night', 32, 'serif'], [$s['prefs']['theme'], $s['prefs']['size'], $s['prefs']['font']]);
bible_state_save($pdo, $ME, ['book' => 99, 'chapter' => 1]);
eq('impossible chapter keeps the old one', 19, bible_state_get($pdo, $ME)['book']);
bible_state_save($pdo, $ME, ['prefs' => ['red' => 'false']]);
eq('a prefs update keeps the others', ['night', false], [bible_state_get($pdo, $ME)['prefs']['theme'], bible_state_get($pdo, $ME)['prefs']['red']]);

// ---------------------------------------------------------------------------
section('highlights');
$texts = bible_chapter_texts($pdo, 'KJV', 43, 3);
eq('KJV texts load without a query', true, isset($texts[16]));
$range = bible_clamp_range($texts, 16, 4, 16, 16);
$list = bible_highlight_apply($pdo, $ME, 'KJV', 43, 3, $range, 'yellow', $texts);
eq('one highlight', 1, count($list));
$row = $pdo->query('SELECT quote FROM bible_highlights')->fetchColumn();
eq('KJV quote stored', 'God so loved', $row);

$list = bible_highlight_apply($pdo, $ME, 'KJV', 43, 3, bible_clamp_range($texts, 16, 10, 16, 30), 'green', $texts);
$shape = array_map(static fn($h) => [$h['os'], $h['oe'], $h['color']], $list);
eq('green trims the yellow', [[4, 10, 'yellow'], [10, 30, 'green']], $shape);

$list = bible_highlight_apply($pdo, $ME, 'KJV', 43, 3, bible_clamp_range($texts, 16, 12, 16, 14), null, $texts);
$shape = array_map(static fn($h) => [$h['os'], $h['oe'], $h['color']], $list);
eq('erasing the middle splits the green', [[4, 10, 'yellow'], [10, 12, 'green'], [14, 30, 'green']], $shape);

$list = bible_highlight_apply($pdo, $ME, 'KJV', 43, 3, bible_clamp_range($texts, 16, 0, 17, 999), 'blue', $texts);
eq('painting two whole verses replaces everything under them', [[16, 0, 17, mb_strlen($texts[17]), 'blue']], array_map(static fn($h) => [$h['vs'], $h['os'], $h['ve'], $h['oe'], $h['color']], $list));
eq('…with the quote spanning both verses', true, str_contains((string) $pdo->query("SELECT quote FROM bible_highlights WHERE color = 'blue'")->fetchColumn(), 'For God sent not his Son'));

eq('another member sees none of it', [], bible_highlights_for_chapter($pdo, $OTHER, 'KJV', 43, 3));
eq('the same words in another translation are separate', [], bible_highlights_for_chapter($pdo, $ME, 'NIV', 43, 3));
$id = $list[0]['id'];
eq('another member cannot delete it', false, bible_highlight_delete($pdo, $OTHER, $id));
$all = bible_highlights_list($pdo, $ME);
eq('Highlights list', [1, 'John 3:16–17'], [$all['total'], $all['items'][0]['ref']]);
eq('owner deletes it', true, bible_highlight_delete($pdo, $ME, $id));

// ---------------------------------------------------------------------------
section('notes, journal and tags');
$r = bible_note_save($pdo, $ME, [
    'kind' => 'verse', 'body' => 'He gave — that is love.', 'tags' => ['Love', '#love', ' grace! '],
    'anchor' => ['KJV', 43, 3, 16, 4, 16, 16],
], $texts);
eq('verse note saved', true, $r['ok']);
$verseNote = bible_note_get($pdo, $ME, $r['id']);
eq('quote cut from the selection', 'God so loved', $verseNote['quote']);
eq('tags cleaned and de-duplicated', ['grace', 'love'], $verseNote['tags']);
eq('anchor ref', 'John 3:16', $verseNote['anchor']['ref']);

$r = bible_note_save($pdo, $ME, [
    'kind' => 'journal', 'title' => 'Sunday: God’s love', 'body' => "Pastor said 50% of faith is showing up.\nThe rest is grace.",
    'refs' => 'Romans 8:28; John 3:16-18', 'tags' => ['faith', 'Sermon'],
]);
eq('journal saved', true, $r['ok']);
$journalId = $r['id'];
$j = bible_note_get($pdo, $ME, $journalId);
eq('journal refs', ['Romans 8:28', 'John 3:16–18'], array_column($j['refs'], 'ref'));

$bad = bible_note_save($pdo, $ME, ['kind' => 'journal', 'title' => 'x', 'refs' => 'Hezekiah 4:2']);
eq('a reference that is not in the Bible is refused', false, $bad['ok']);
eq('an empty verse note is refused', false, bible_note_save($pdo, $ME, ['kind' => 'verse', 'body' => '  ', 'anchor' => ['KJV', 43, 3, 16, null, 16, null]])['ok']);

$list = bible_notes_list($pdo, $ME, ['tag' => 'faith']);
eq('filter by tag', [$journalId], array_column($list['items'], 'id'));
$list = bible_notes_list($pdo, $ME, ['q' => 'loved']);
eq('search reaches the quote', [$verseNote['id']], array_column($list['items'], 'id'));
$list = bible_notes_list($pdo, $ME, ['q' => '50%']);
eq('% is literal in search, not a wildcard', [$journalId], array_column($list['items'], 'id'));
$list = bible_notes_list($pdo, $ME, ['q' => '_']);
eq('_ is literal in search', 0, $list['total']);
$list = bible_notes_list($pdo, $ME, ['book' => 45]);
eq('filter by book finds journal entries linking it', [$journalId], array_column($list['items'], 'id'));
eq('kind filter', 1, bible_notes_list($pdo, $ME, ['kind' => 'verse'])['total']);
eq('tag list with counts', ['faith' => 1, 'grace' => 1, 'love' => 1, 'sermon' => 1], array_column(bible_tags_list($pdo, $ME), 'count', 'name'));

$marks = bible_notes_for_chapter($pdo, $ME, 43, 3);
eq('chapter markers: the verse note and the journal entry', [['verse', 16], ['journal', 16]], array_map(static fn($m) => [$m['kind'], $m['vs']], $marks));
eq('journal marker spans its passage', 18, $marks[1]['ve']);
eq('another member sees no markers', [], bible_notes_for_chapter($pdo, $OTHER, 43, 3));
eq('another member cannot open it', null, bible_note_get($pdo, $OTHER, $journalId));
eq('another member cannot edit it', false, bible_note_save($pdo, $OTHER, ['id' => $journalId, 'kind' => 'journal', 'title' => 'mine now'])['ok']);
eq('another member cannot delete it', false, bible_note_delete($pdo, $OTHER, $journalId));

bible_note_save($pdo, $ME, ['id' => $journalId, 'kind' => 'journal', 'title' => 'Sunday', 'body' => 'Edited', 'refs' => 'Psalm 23', 'tags' => ['faith']]);
$j = bible_note_get($pdo, $ME, $journalId);
eq('edit replaces refs and tags', [['Psalm 23'], ['faith']], [array_column($j['refs'], 'ref'), $j['tags']]);
eq('unused tags are pruned', ['faith', 'grace', 'love'], array_column(bible_tags_list($pdo, $ME), 'name'));
eq('delete', true, bible_note_delete($pdo, $ME, $verseNote['id']));
eq('…and its tags go with it', ['faith'], array_column(bible_tags_list($pdo, $ME), 'name'));

// ---------------------------------------------------------------------------
section('reading log, plans and streaks');
$res = bible_plan_start($pdo, $ME, 'gospels-30');
eq('plan started', true, $res['ok']);
$enId = $res['id'];
eq('starting it again returns the same plan', $enId, bible_plan_start($pdo, $ME, 'gospels-30')['id']);
eq('unknown plan refused', false, bible_plan_start($pdo, $ME, 'nope')['ok']);
$day1 = bible_plan_schedule('gospels-30')[1];
$last = array_pop($day1);
foreach ($day1 as [$b, $c]) {
    $m = bible_mark_read($pdo, $ME, 'KJV', $b, $c);
    eq("reading {$b}:{$c} does not finish day 1 yet", [], $m['completed_days']);
}
$m = bible_mark_read($pdo, $ME, 'NKJV', $last[0], $last[1], 'voice');
eq('the last chapter of the day ticks it off', [['enrollment_id' => $enId, 'plan_key' => 'gospels-30', 'day' => 1]], $m['completed_days']);
eq('marking twice is harmless', false, bible_mark_read($pdo, $ME, 'KJV', $last[0], $last[1])['new']);
$ov = bible_plan_overview($pdo, $ME, bible_enrollment($pdo, $ME, $enId), true);
eq('overview: day 2 is next', [2, 1], [$ov['next']['day'], $ov['done_count']]);
eq('schedule carries each day’s first chapter', [40, 1], $ov['schedule'][0]['first']);
$streak = bible_streak($pdo, $ME);
eq('streak: one day, read today', [1, true], [$streak['current'], $streak['today']]);
$pdo->prepare('INSERT INTO bible_reading_log (user_id, read_on, book, chapter) VALUES (?, ?, 1, 1)')->execute([$ME, bible_date_add(bible_today(), -1)]);
eq('yesterday counts too', 2, bible_streak($pdo, $ME)['current']);
eq('another member has no streak', 0, bible_streak($pdo, $OTHER)['current']);

foreach (array_keys(bible_plan_schedule('gospels-30')) as $d) {
    bible_plan_set_day($pdo, $ME, $enId, $d, true);
}
eq('ticking every day completes the plan', 'done', bible_enrollment($pdo, $ME, $enId)['status']);
bible_plan_set_day($pdo, $ME, $enId, 5, false);
eq('unticking a day reopens it', 'active', bible_enrollment($pdo, $ME, $enId)['status']);
eq('another member cannot tick my plan', false, bible_plan_set_day($pdo, $OTHER, $enId, 5, true));
eq('stop', true, bible_plan_stop($pdo, $ME, $enId));
eq('no active plans left', [], bible_enrollments($pdo, $ME, 'active'));

// ---------------------------------------------------------------------------
section('API.Bible: catalogue, 29-day cache, quota, FUMS');
$fx = __DIR__ . '/fixtures';
$calls = [];
$fail = false;
$catalogJson = json_encode(['data' => array_map(static fn($b) => $b + ['copyright' => 'Catalogue copyright for ' . $b['abbreviationLocal'], 'info' => '<p>About</p>'], json_decode((string) file_get_contents($fx . '/api_bible_catalog_eng.json'), true))]);
$chapterJson = json_encode([
    'data' => ['id' => 'JHN.3', 'content' => file_get_contents($fx . '/bsb_john3_16_19.html'), 'copyright' => 'NIV copyright line'],
    'meta' => ['fumsToken' => 'tok-123'],
]);
bible_api_set_transport(static function (string $url, array $headers) use (&$calls, &$fail, $catalogJson, $chapterJson, $fx): array {
    $calls[] = ['url' => $url, 'headers' => $headers];
    if ($fail) {
        return ['status' => 0, 'body' => ''];
    }
    if (str_contains($url, '/bibles?')) {
        return ['status' => 200, 'body' => $catalogJson];
    }
    if (str_contains($url, '/chapters/')) {
        return ['status' => 200, 'body' => $chapterJson];
    }
    if (str_contains($url, '/search')) {
        return ['status' => 200, 'body' => (string) file_get_contents($fx . '/bsb_search_love.json')];
    }
    return ['status' => 404, 'body' => '{}'];
});

$_ENV['BIBLE_API_KEY'] = '';
eq('no key → translation off', 'TRANSLATION_OFF', bible_chapter($pdo, 'NIV', 43, 3)['code'] ?? null);
eq('no key → status explains', false, bible_api_translation_status($pdo, 'NIV')['available']);
eq('no key → nothing sent', 0, count($calls));

$_ENV['BIBLE_API_KEY'] = 'test-key-123';
$res = bible_chapter($pdo, 'NIV', 43, 3);
eq('first read fetches', [true, 'api'], [$res['ok'], $res['data']['source'] ?? null]);
eq('catalogue + chapter = 2 calls', 2, count($calls));
eq('…and both are counted', 2, bible_api_usage($pdo));
eq('verses parsed', [16, 17, 18, 19], array_column($res['data']['verses'], 'v'));
eq('copyright from the chapter', 'NIV copyright line', $res['data']['copyright']);
eq('FUMS token passed to the page', 'tok-123', $res['data']['fums_token']);
$chapterCall = $calls[1]['url'];
eq('asked for the NIV id with FUMS v3', true, str_contains($chapterCall, '/bibles/78a9f6124f344018-01/chapters/JHN.3') && str_contains($chapterCall, 'fums-version=3'));
eq('key travels in the header, never the URL', [true, false], [in_array('api-key: test-key-123', $calls[1]['headers'], true), str_contains($chapterCall, 'test-key')]);
eq('base is https', true, str_starts_with($chapterCall, 'https://'));

$res = bible_chapter($pdo, 'NIV', 43, 3);
eq('second read is served from the cache', ['cache', 2], [$res['data']['source'], count($calls)]);
eq('…still carrying the FUMS token to report', 'tok-123', $res['data']['fums_token']);
eq('cached licensed text clamps a selection', [16, 4, 16, 16], bible_clamp_range(bible_chapter_texts($pdo, 'NIV', 43, 3), 16, 4, 16, 16));

$hl = bible_highlight_apply($pdo, $ME, 'NIV', 43, 3, [16, 4, 16, 16], 'pink', bible_chapter_texts($pdo, 'NIV', 43, 3));
eq('licensed highlight works', 1, count($hl));
eq('…but its words are never stored', null, $pdo->query("SELECT quote FROM bible_highlights WHERE translation = 'NIV'")->fetchColumn() ?: null);

$st = bible_api_translation_status($pdo, 'NIV');
eq('NIV available, with the catalogue copyright', [true, 'Catalogue copyright for NIV'], [$st['available'], $st['copyright']]);
$st = bible_api_translation_status($pdo, 'AMP');
eq('AMP not on this key → unavailable with a reason', [false, true], [$st['available'], $st['reason'] !== '']);
$_ENV['BIBLE_API_ID_AMP'] = 'pinned-amp';
eq('…unless pinned in .env', 'pinned-amp', bible_api_resolve($pdo, 'AMP', false)['id'] ?? null);
unset($_ENV['BIBLE_API_ID_AMP']);

// 30 days later the licence says refresh — and if we cannot, we must not serve it.
$old = date('Y-m-d H:i:s', time() - 31 * 86400);
$pdo->prepare("UPDATE bible_chapter_cache SET fetched_at = ? WHERE translation = 'NIV'")->execute([$old]);
eq('stale rows are not offered to highlights either', [], bible_chapter_texts($pdo, 'NIV', 43, 3));
$fail = true;
$res = bible_chapter($pdo, 'NIV', 43, 3);
eq('stale + unreachable → an error, not old text', [false, 'SOURCE_UNREACHABLE'], [$res['ok'], $res['code'] ?? null]);
eq('…and the stale row is deleted', 0, (int) $pdo->query("SELECT COUNT(*) FROM bible_chapter_cache WHERE translation = 'NIV'")->fetchColumn());
$fail = false;
$res = bible_chapter($pdo, 'NIV', 43, 3);
eq('next time it refetches', [true, 'api'], [$res['ok'], $res['data']['source']]);
$pdo->prepare("UPDATE bible_chapter_cache SET fetched_at = ? WHERE translation = 'NIV'")->execute([$old]);
$before = count($calls);
$res = bible_chapter($pdo, 'NIV', 43, 3);
eq('stale + reachable → refreshed in place', [true, 'api', $before + 1], [$res['ok'], $res['data']['source'], count($calls)]);
eq('…with a new date', true, (string) $pdo->query("SELECT fetched_at FROM bible_chapter_cache WHERE translation = 'NIV'")->fetchColumn() > $old);

$s = bible_api_search($pdo, 'NIV', 'love', 'nt', 3, 0);
eq('licensed search parses verses', ['John 12:25', 'Luke 6:32', '1 John 4:19'], array_column($s['results'] ?? [], 'ref'));
eq('…limited to the New Testament', true, str_contains($calls[count($calls) - 1]['url'], 'range=MAT-REV'));

$pdo->prepare('UPDATE bible_api_usage SET calls = ? WHERE month = ?')->execute([bible_api_cap(), bible_month()]);
$before = count($calls);
$res = bible_chapter($pdo, 'NIV', 1, 1);
eq('over the monthly cap: refused without a call', ['QUOTA', $before], [$res['code'] ?? null, count($calls)]);
eq('…cached chapters still open', true, bible_chapter($pdo, 'NIV', 43, 3)['ok']);
eq('status says only recent chapters', true, str_contains(bible_api_translation_status($pdo, 'NIV')['reason'], 'recently'));

bible_api_set_transport(null);
$_ENV['BIBLE_API_KEY'] = '';
