<?php
// /api/hymnal_api.php
//
// The Hymnary's JSON endpoint.
//
// READING is open to every signed-in member: the library is hymns and songs,
// and the only personal thing here is what a member marks, which every query
// scopes to the caller's own user id.
//
// THE STUDIO is gated by includes/hymnal/rights.php — River of Life and the
// pastors may add and edit, only a pastor may publish. The gate is checked HERE
// on every studio action as well as on the page, because a hidden button is not
// a permission.
//
// GET  ?action=boot | song | search | collections | shelf | meta
//             studio_list | studio_song | ai_job
// POST action=state | favourite | song_save | song_publish | song_retire |
//             score_save | config_set | ai_read
//
// POST bodies are JSON and must carry the X-Hymnal-CSRF header that
// modules/hymnal/index.php prints into the page. ai_read is the one exception:
// it is multipart/form-data, because it carries a photograph of a song sheet,
// and sends the same token as a _csrf field.

require_once '../includes/db.php';
require_once '../includes/hymnal/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function hymnal_out(array $payload, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hymnal_api_ok($data = null, string $message = ''): void
{
    hymnal_out(['status' => 'success', 'message' => $message, 'data' => $data]);
}

function hymnal_api_fail(string $message, string $code = 'ERROR', int $http = 200, array $extra = []): void
{
    hymnal_out(['status' => 'error', 'code' => $code, 'message' => $message] + $extra, $http);
}

/** Turn a module result array into the API's shape. */
function hymnal_api_result(array $res, $data = null): void
{
    if (!empty($res['ok'])) {
        hymnal_api_ok($data ?? $res);
    }
    hymnal_api_fail(
        (string) ($res['message'] ?? 'That did not work.'),
        (string) ($res['code'] ?? 'ERROR'),
        200,
        isset($res['errors']) ? ['errors' => $res['errors']] : []
    );
}

/**
 * What an uploaded file actually is, read from its first bytes.
 *
 * Never the type the browser claimed: a .jpg that is really something else
 * would otherwise be forwarded to Google on the strength of its name. fileinfo
 * is used when the host has it, and the magic numbers of the four types we
 * accept are checked directly when it does not, so this does not quietly stop
 * working on a host built without that extension.
 */
function hymnal_api_sniff_mime(string $bytes): string
{
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $got = (string) $fi->buffer($bytes);
        // Only trusted when it names a type we accept. Anything else falls
        // through to the magic numbers below, which can only ever answer with
        // one of those four types — so the fallback cannot widen what is
        // allowed, it can only recognise a file this build of fileinfo did not.
        if (isset(HYMNAL_AI_MIME[$got])) {
            return $got;
        }
    }
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
        return 'image/png';
    }
    if (str_starts_with($bytes, '%PDF-')) {
        return 'application/pdf';
    }
    if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }

    return '';
}

if (!isset($_SESSION['user_id'])) {
    hymnal_api_fail('Your session has ended. Please sign in again.', 'UNAUTHORIZED', 401);
}

$userId = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    // A multipart upload (ai_read) leaves php://input unusable, so $_POST is
    // the fallback rather than an afterthought.
    $input = is_array($decoded) ? $decoded : $_POST;

    $sent = (string) ($_SERVER['HTTP_X_HYMNAL_CSRF'] ?? ($input['_csrf'] ?? ''));
    $have = (string) ($_SESSION['hymnal_csrf'] ?? '');
    if ($sent === '' || $have === '' || !hash_equals($have, $sent)) {
        hymnal_api_fail('Your session expired. Reload the page and try again.', 'CSRF', 403);
    }
}

$action = (string) ($method === 'POST' ? ($input['action'] ?? '') : ($_GET['action'] ?? ''));

// Nothing below writes the session, and the AI call can take a minute, so
// releasing it now keeps the member's other tabs responsive.
if (function_exists('session_write_close')) {
    session_write_close();
}

/** Every studio action re-checks clearance here, not only in the interface. */
$requireStudio = static function () use ($pdo, $userId): void {
    $deny = hymnal_require_studio($pdo, $userId);
    if ($deny !== null) {
        hymnal_api_fail((string) $deny['message'], (string) $deny['code'], 403);
    }
};
$requirePublish = static function () use ($pdo, $userId): void {
    $deny = hymnal_require_publish($pdo, $userId);
    if ($deny !== null) {
        hymnal_api_fail((string) $deny['message'], (string) $deny['code'], 403);
    }
};

try {
    switch ($action) {

        // ---------------------------------------------------------------
        // Reading
        // ---------------------------------------------------------------

        case 'boot':
            hymnal_api_ok(hymnal_boot_payload($pdo, $userId));
            // no break needed: hymnal_api_ok exits

        case 'song': {
            $number = (int) ($_GET['n'] ?? 0);
            // Drafts are only ever served to someone who holds the Studio, so
            // a member cannot read an unpublished song by guessing its number.
            $wantDraft = !empty($_GET['draft']);
            if ($wantDraft) {
                $requireStudio();
            }
            $res = hymnal_library_open($pdo, $userId, $number, $wantDraft);
            if (empty($res['ok'])) {
                hymnal_api_fail((string) $res['message'], (string) $res['code'], 404);
            }
            hymnal_api_ok($res['data']);
        }

        case 'search': {
            $res = hymnal_library_search($pdo, (string) ($_GET['q'] ?? ''), [
                'collection' => (string) ($_GET['collection'] ?? ''),
                'score_only' => !empty($_GET['score_only']),
                'sort'       => (string) ($_GET['sort'] ?? 'number'),
                'limit'      => (int) ($_GET['limit'] ?? 60),
                'offset'     => (int) ($_GET['offset'] ?? 0),
            ]);
            hymnal_api_ok($res);
        }

        case 'collections':
            hymnal_api_ok(hymnal_library_collections($pdo));

        case 'shelf':
            hymnal_api_ok(hymnal_library_shelf($pdo, $userId));

        case 'meta':
            // What the library is and what it leaves out, for the About panel.
            hymnal_api_ok([
                'corpus'     => hymnal_corpus_meta(),
                'counts'     => [
                    'songs'  => hymnal_library_count($pdo),
                    'studio' => hymnal_songs_count($pdo),
                ],
                'languages'  => hymnal_library_languages($pdo),
                'licences'   => HYMNAL_LICENCES,
            ]);

        // ---------------------------------------------------------------
        // The member's own
        // ---------------------------------------------------------------

        case 'state': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            if (!hymnal_ready($pdo)) {
                hymnal_api_fail('Saving your place is not switched on yet.', 'FEATURE_NOT_READY');
            }
            $saved = hymnal_state_save($pdo, $userId, [
                'mode'        => $input['mode'] ?? null,
                'last_number' => $input['last_number'] ?? null,
                'lang'        => $input['lang'] ?? null,
                'prefs'       => is_array($input['prefs'] ?? null) ? $input['prefs'] : null,
            ]);
            if (!$saved) {
                hymnal_api_fail('That did not save.', 'ERROR');
            }
            hymnal_api_ok(hymnal_state_get($pdo, $userId));
        }

        case 'favourite': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            $number = (int) ($input['n'] ?? 0);
            if (hymnal_library_song($pdo, $number) === null) {
                hymnal_api_fail('There is no hymn with that number.', 'NOT_FOUND', 404);
            }
            $res = hymnal_favourite_toggle($pdo, $userId, $number, !empty($input['on']));
            if (empty($res['ok'])) {
                hymnal_api_fail((string) $res['message'], (string) $res['code']);
            }
            hymnal_api_ok(['n' => $number, 'on' => $res['on']]);
        }

        // ---------------------------------------------------------------
        // The Studio
        // ---------------------------------------------------------------

        case 'studio_list': {
            $requireStudio();
            $res = hymnal_library_search($pdo, (string) ($_GET['q'] ?? ''), [
                'include_drafts' => true,
                'limit'          => (int) ($_GET['limit'] ?? 100),
                'offset'         => (int) ($_GET['offset'] ?? 0),
                'sort'           => 'number',
            ]);
            // The Studio only manages what it owns; the corpus is generated and
            // read-only, so it is not offered for editing.
            $res['rows'] = array_values(array_filter(
                $res['rows'],
                static fn(array $r): bool => ($r['origin'] ?? '') === 'studio'
            ));
            $res['total'] = count($res['rows']);
            hymnal_api_ok($res);
        }

        case 'studio_song': {
            $requireStudio();
            $song = hymnal_song_db_by_id($pdo, (int) ($_GET['id'] ?? 0));
            if ($song === null) {
                hymnal_api_fail('That song is no longer there.', 'NOT_FOUND', 404);
            }
            hymnal_api_ok($song);
        }

        case 'song_save': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            $requireStudio();
            $res = hymnal_song_save($pdo, $userId, $input);
            // A song that came from an AI reading records which one, so the two
            // can be compared later. The reading never saved itself.
            if (!empty($res['ok']) && !empty($input['ai_job_id'])) {
                hymnal_ai_job_applied($pdo, $userId, (int) $input['ai_job_id'], (int) $res['id']);
            }
            hymnal_api_result($res, ['id' => $res['id'] ?? null, 'number' => $res['number'] ?? null]);
        }

        case 'score_save': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            $requireStudio();
            $songId = (int) ($input['song_id'] ?? 0);
            if (hymnal_song_db_by_id($pdo, $songId) === null) {
                hymnal_api_fail('That song is no longer there.', 'NOT_FOUND', 404);
            }
            $res = hymnal_song_score_save(
                $pdo,
                $userId,
                $songId,
                (string) ($input['abc'] ?? ''),
                is_array($input['voices'] ?? null) ? $input['voices'] : [],
                is_array($input['key'] ?? null) ? $input['key'] : ['root' => 'C', 'acc' => '', 'mode' => '', 'accidentals' => []],
                (string) ($input['arrangement_licence'] ?? ''),
                (string) ($input['arrangement_note'] ?? '')
            );
            hymnal_api_result($res);
        }

        case 'song_publish': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            $requirePublish();
            hymnal_api_result(hymnal_song_publish($pdo, $userId, (int) ($input['id'] ?? 0)));
        }

        case 'song_retire': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            $requirePublish();
            hymnal_api_result(hymnal_song_retire(
                $pdo,
                $userId,
                (int) ($input['id'] ?? 0),
                (string) ($input['reason'] ?? '')
            ));
        }

        case 'config_set': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            $requirePublish();
            $key = (string) ($input['key'] ?? '');
            if (!in_array($key, ['ai_enabled'], true)) {
                hymnal_api_fail('That is not a setting.', 'INVALID');
            }
            if (!hymnal_config_set($pdo, $key, (string) ($input['value'] ?? ''), $userId)) {
                hymnal_api_fail('That did not save.', 'ERROR');
            }
            hymnal_api_ok(['key' => $key, 'value' => hymnal_config_get($pdo, $key)]);
        }

        // ---------------------------------------------------------------
        // AI help
        // ---------------------------------------------------------------

        case 'ai_read': {
            if ($method !== 'POST') {
                hymnal_api_fail('Use POST.', 'METHOD', 405);
            }
            $requireStudio();

            $file = null;
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                if ((int) ($_FILES['file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
                    hymnal_api_fail('That file did not finish uploading.', 'INVALID');
                }
                $bytes = (string) file_get_contents($_FILES['file']['tmp_name']);
                $file = [
                    'bytes' => $bytes,
                    'mime'  => hymnal_api_sniff_mime($bytes),
                    'name'  => (string) ($_FILES['file']['name'] ?? ''),
                ];
            }
            $res = hymnal_ai_read_song($pdo, $userId, (string) ($input['text'] ?? ''), $file);
            hymnal_api_result($res, $res['job'] ?? null);
        }

        case 'ai_job': {
            $requireStudio();
            $job = hymnal_ai_job($pdo, $userId, (int) ($_GET['id'] ?? 0));
            if ($job === null) {
                hymnal_api_fail('That reading is not there.', 'NOT_FOUND', 404);
            }
            hymnal_api_ok($job);
        }

        default:
            hymnal_api_fail('Unknown action.', 'UNKNOWN_ACTION', 400);
    }
} catch (Throwable $e) {
    // PDO::ATTR_ERRMODE is EXCEPTION platform-wide, so an unhandled error here
    // would otherwise break the JSON contract the page depends on.
    error_log('Hymnary API (' . $action . '): ' . $e->getMessage());
    hymnal_api_fail('Something went wrong. Please try again.', 'ERROR', 500);
}
