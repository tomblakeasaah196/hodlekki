<?php
// /api/bible_api.php
//
// The Bible module's JSON endpoint. Open to every signed-in member: there is
// no department or role gate, because everything here is either Scripture
// or the member's own notes. Every query in includes/bible/ is scoped to the
// caller's user id, so one member can never read or change another's
// highlights, notes, journal or plans.
//
// GET  ?action=boot | chapter | search | notes | note | highlights | tags |
//              plans | plan | streak | admin_status
// POST action=state | highlight | highlight_delete | note_save | note_delete |
//             mark_read | plan_start | plan_day | plan_stop | admin_refresh
//
// POST bodies are JSON and must carry the X-Bible-CSRF header that
// modules/bible/index.php prints into the page.

require_once '../includes/db.php';
require_once '../includes/bible/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bible_out(array $payload, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bible_ok($data = null, string $message = ''): void
{
    bible_out(['status' => 'success', 'message' => $message, 'data' => $data]);
}

function bible_fail(string $message, string $code = 'ERROR', int $http = 200): void
{
    bible_out(['status' => 'error', 'code' => $code, 'message' => $message], $http);
}

if (!isset($_SESSION['user_id'])) {
    bible_fail('Your session has ended. Please sign in again.', 'UNAUTHORIZED', 401);
}

$userId = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $input = is_array($decoded) ? $decoded : $_POST;

    $sent = (string) ($_SERVER['HTTP_X_BIBLE_CSRF'] ?? ($input['_csrf'] ?? ''));
    $have = (string) ($_SESSION['bible_csrf'] ?? '');
    if ($sent === '' || $have === '' || !hash_equals($have, $sent)) {
        bible_fail('Your session expired. Reload the page and try again.', 'CSRF', 403);
    }
}

$action = (string) ($method === 'POST' ? ($input['action'] ?? '') : ($_GET['action'] ?? ''));

// Searching a licensed translation spends one of the church's monthly
// API.Bible calls, shared by everyone. Cap it per member per day so nobody
// can use up the month on their own (chapters are bounded by the cache).
if ($action === 'search' && !bible_is_local(bible_translation_code((string) ($_GET['t'] ?? 'KJV')))) {
    $day = bible_today();
    $used = ($_SESSION['bible_lsearch']['day'] ?? '') === $day ? (int) ($_SESSION['bible_lsearch']['n'] ?? 0) : 0;
    if ($used >= BIBLE_LICENSED_SEARCHES_PER_DAY) {
        session_write_close();
        bible_fail('You have searched the licensed translations a lot today. Search in the KJV is unlimited — or try again tomorrow.', 'QUOTA');
    }
    $_SESSION['bible_lsearch'] = ['day' => $day, 'n' => $used + 1];
}

// Nothing below writes the session. Releasing it now means a slow API.Bible
// call never blocks the member's other tabs.
session_write_close();
$q = static fn(string $k, $default = null) => $method === 'POST' ? ($input[$k] ?? $default) : ($_GET[$k] ?? $default);

$ready = bible_ready($pdo);
$needsDb = !in_array($action, ['boot', 'chapter', 'search'], true);
if ($needsDb && !$ready) {
    bible_fail('Notes, highlights and plans are being set up. Reading works already — try the rest again after the next update.', 'FEATURE_NOT_READY');
}

/**
 * The chapter's verse texts, for clamping a selection and cutting KJV quotes.
 */
function bible_api_texts(PDO $pdo, string $t, int $b, int $c): array
{
    return bible_chapter_texts($pdo, $t, $b, $c);
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        case 'boot':
            bible_ok(bible_boot_payload($pdo, $userId));

        // ------------------------------------------------------------------
        case 'chapter':
            $res = bible_chapter_for_user($pdo, $userId, bible_translation_code((string) $q('t', 'KJV')), (int) $q('b', 0), (int) $q('c', 0));
            if (!$res['ok']) {
                bible_fail($res['message'] ?? 'That chapter could not be loaded.', $res['code'] ?? 'ERROR');
            }
            bible_ok($res['data']);

        // ------------------------------------------------------------------
        case 'search':
            $query = trim(mb_substr((string) $q('q', ''), 0, 120, 'UTF-8'));
            $t     = bible_translation_code((string) $q('t', 'KJV'));
            $scope = (string) $q('scope', 'all');
            $page  = max(1, (int) $q('page', 1));
            $per   = 30;
            if ($query === '') {
                bible_ok(['ref' => null, 'total' => 0, 'results' => [], 'terms' => []]);
            }

            $ref = null;
            if (preg_match('/\d/', $query)) {
                $p = bible_parse_ref($query);
                if ($p !== null) {
                    $ref = $p + ['label' => bible_format_ref($p['book'], $p['chapter'], $p['verse'], $p['chapter_end'], $p['verse_end'])];
                }
            }

            if (bible_is_local($t)) {
                $r = bible_kjv_search($query, $scope, $per, ($page - 1) * $per);
                bible_ok(['ref' => $ref, 'translation' => $t, 'page' => $page, 'per_page' => $per] + $r);
            }

            if ($ref !== null) {
                // A reference typed into a licensed translation's search: just
                // jump there, no search call needed.
                bible_ok(['ref' => $ref, 'translation' => $t, 'total' => 0, 'results' => [], 'terms' => [], 'page' => 1, 'per_page' => $per]);
            }
            $r = bible_api_search($pdo, $t, $query, $scope, $per, ($page - 1) * $per);
            if (!$r['ok']) {
                bible_fail($r['message'], $r['code']);
            }
            bible_ok([
                'ref'         => null,
                'translation' => $t,
                'page'        => $page,
                'per_page'    => $per,
                'total'       => $r['total'],
                'results'     => $r['results'],
                'terms'       => bible_search_terms($query),
                'fums_token'  => $r['fums_token'],
                'by_book'     => [],
            ]);

        // ------------------------------------------------------------------
        case 'notes':
            $page = max(1, (int) $q('page', 1));
            $per  = 30;
            $res = bible_notes_list($pdo, $userId, [
                'q'    => (string) $q('q', ''),
                'tag'  => (string) $q('tag', ''),
                'kind' => (string) $q('kind', ''),
                'book' => (int) $q('book', 0),
            ], $per, ($page - 1) * $per);
            bible_ok($res + ['page' => $page, 'per_page' => $per, 'tags' => bible_tags_list($pdo, $userId)]);

        case 'note':
            $note = bible_note_get($pdo, $userId, (int) $q('id', 0));
            if ($note === null) {
                bible_fail('That note no longer exists.', 'NOT_FOUND');
            }
            bible_ok($note);

        case 'highlights':
            $page = max(1, (int) $q('page', 1));
            $per  = 40;
            $res = bible_highlights_list($pdo, $userId, [
                'color' => (string) $q('color', ''),
                'book'  => (int) $q('book', 0),
            ], $per, ($page - 1) * $per);
            bible_ok($res + ['page' => $page, 'per_page' => $per]);

        case 'tags':
            bible_ok(bible_tags_list($pdo, $userId));

        case 'plans':
            $mine = [];
            foreach (bible_enrollments($pdo, $userId) as $en) {
                if ($en['status'] === 'stopped') {
                    continue;
                }
                $mine[] = bible_plan_overview($pdo, $userId, $en);
            }
            bible_ok(['catalog' => bible_plan_catalog(), 'mine' => $mine, 'streak' => bible_streak($pdo, $userId)]);

        case 'plan':
            $en = bible_enrollment($pdo, $userId, (int) $q('id', 0));
            if ($en === null) {
                bible_fail('That plan could not be found.', 'NOT_FOUND');
            }
            bible_ok(bible_plan_overview($pdo, $userId, $en, true));

        case 'streak':
            bible_ok(bible_streak($pdo, $userId));

        // ------------------------------------------------------------------
        case 'state':
            bible_ok(bible_state_save($pdo, $userId, [
                'translation' => $input['translation'] ?? null,
                'book'        => $input['book'] ?? null,
                'chapter'     => $input['chapter'] ?? null,
                'verse'       => $input['verse'] ?? null,
                'prefs'       => is_array($input['prefs'] ?? null) ? $input['prefs'] : null,
            ]));

        case 'highlight':
            $t = bible_translation_code((string) ($input['t'] ?? 'KJV'));
            $b = (int) ($input['b'] ?? 0);
            $c = (int) ($input['c'] ?? 0);
            if (!bible_chapter_exists($b, $c)) {
                bible_fail('That chapter is not in the Bible.', 'NOT_FOUND');
            }
            $texts = bible_api_texts($pdo, $t, $b, $c);
            $range = bible_clamp_range($texts, (int) ($input['vs'] ?? 0), (int) ($input['os'] ?? 0), (int) ($input['ve'] ?? 0), (int) ($input['oe'] ?? 0));
            if ($range === null) {
                bible_fail('Select some words first.', 'EMPTY');
            }
            $color = isset($input['color']) && $input['color'] !== null && $input['color'] !== '' ? (string) $input['color'] : null;
            bible_ok(bible_highlight_apply($pdo, $userId, $t, $b, $c, $range, $color, $texts));

        case 'highlight_delete':
            bible_highlight_delete($pdo, $userId, (int) ($input['id'] ?? 0));
            bible_ok(true);

        case 'note_save':
            $texts = [];
            $anchor = $input['anchor'] ?? null;
            if (is_array($anchor) && count($anchor) >= 6) {
                $texts = bible_api_texts($pdo, bible_translation_code((string) $anchor[0]), (int) $anchor[1], (int) $anchor[2]);
            }
            $res = bible_note_save($pdo, $userId, $input, $texts);
            if (!$res['ok']) {
                bible_fail($res['message'], 'INVALID');
            }
            bible_ok(bible_note_get($pdo, $userId, $res['id']), 'Saved');

        case 'note_delete':
            if (!bible_note_delete($pdo, $userId, (int) ($input['id'] ?? 0))) {
                bible_fail('That note no longer exists.', 'NOT_FOUND');
            }
            bible_ok(true, 'Deleted');

        case 'mark_read':
            $t = bible_translation_code((string) ($input['t'] ?? 'KJV'));
            $b = (int) ($input['b'] ?? 0);
            $c = (int) ($input['c'] ?? 0);
            if (!bible_chapter_exists($b, $c)) {
                bible_fail('That chapter is not in the Bible.', 'NOT_FOUND');
            }
            $res = bible_mark_read($pdo, $userId, $t, $b, $c, (string) ($input['source'] ?? 'read'));
            bible_ok($res + ['streak' => bible_streak($pdo, $userId)]);

        case 'plan_start':
            $res = bible_plan_start($pdo, $userId, (string) ($input['key'] ?? ''), isset($input['start']) ? (string) $input['start'] : null);
            if (!$res['ok']) {
                bible_fail($res['message'], 'INVALID');
            }
            $en = bible_enrollment($pdo, $userId, $res['id']);
            bible_ok(bible_plan_overview($pdo, $userId, $en), 'Plan started');

        case 'plan_day':
            $id   = (int) ($input['id'] ?? 0);
            $day  = (int) ($input['day'] ?? 0);
            $done = filter_var($input['done'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $en = bible_enrollment($pdo, $userId, $id);
            if ($en === null || !bible_plan_set_day($pdo, $userId, $id, $day, $done)) {
                bible_fail('That plan day could not be found.', 'NOT_FOUND');
            }
            // Ticking a day by hand counts as reading it today: the streak
            // follows, and the chapters show as read.
            if ($done) {
                foreach (bible_plan_schedule($en['plan_key'])[$day] ?? [] as [$pb, $pc]) {
                    bible_mark_read($pdo, $userId, 'KJV', $pb, $pc, 'plan');
                }
            }
            $en = bible_enrollment($pdo, $userId, $id);
            bible_ok(bible_plan_overview($pdo, $userId, $en, true) + ['streak' => bible_streak($pdo, $userId)]);

        case 'plan_stop':
            bible_plan_stop($pdo, $userId, (int) ($input['id'] ?? 0));
            bible_ok(true, 'Plan stopped');

        // ------------------------------------------------------------------
        case 'admin_status':
        case 'admin_refresh':
            if (!function_exists('security_is_admin') || !security_is_admin($pdo, $userId)) {
                bible_fail('Only administrators can see this.', 'FORBIDDEN', 403);
            }
            if ($action === 'admin_refresh') {
                if ($method !== 'POST') {
                    bible_fail('Use POST.', 'METHOD', 405);
                }
                bible_api_catalog($pdo, true);
            }
            $catalog = json_decode((string) bible_config_get($pdo, 'api_catalog'), true);
            $resolved = [];
            foreach (['NKJV', 'NIV', 'AMP'] as $code) {
                $r = bible_api_resolve($pdo, $code, false);
                $resolved[$code] = $r === null ? null : ['id' => $r['id'], 'name' => $r['name'], 'pinned' => bible_env('BIBLE_API_ID_' . $code) !== ''];
            }
            $cache = $pdo->query('SELECT translation, COUNT(*) AS n, MIN(fetched_at) AS oldest FROM bible_chapter_cache GROUP BY translation')->fetchAll(PDO::FETCH_ASSOC);
            bible_ok([
                'key_set'     => bible_api_key() !== '',
                'base'        => bible_api_base(),
                'cap'         => bible_api_cap(),
                'used'        => bible_api_usage($pdo),
                'month'       => bible_month(),
                'catalog_at'  => $catalog['fetched_at'] ?? null,
                'catalog_err' => $catalog['error'] ?? '',
                'available'   => array_map(static fn(array $b): string => $b['abbreviationLocal'] . ' — ' . $b['name'], $catalog['bibles'] ?? []),
                'resolved'    => $resolved,
                'cache'       => $cache,
                'cache_days'  => BIBLE_CACHE_DAYS,
            ]);

        default:
            bible_fail('Unknown action.', 'BAD_ACTION', 400);
    }
} catch (Throwable $e) {
    error_log('Bible api [' . $action . ']: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    bible_fail('Something went wrong. Please try again.', 'SERVER');
}
