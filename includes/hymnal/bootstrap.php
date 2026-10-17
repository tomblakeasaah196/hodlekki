<?php
// /includes/hymnal/bootstrap.php
//
// The Hymnary's only entry point. Pages and endpoints require includes/db.php
// first (it owns the session, the PDO handle and the account security gate),
// then this file.
//
// Map of the module:
//   collections.php  the shelves of the library
//   solfa.php        tonic sol-fa, generated from a score (pure arithmetic)
//   corpus.php       the 511 generated public-domain hymns, read from files
//   songs.php        songs the music team adds, read from the database
//   library.php      one view over both, which is all the reader ever uses
//   member.php       favourites, reading position, preferences — private
//   rights.php       who may open the Studio
//   ai.php           hymnal_ai(): Gemini reads an uploaded song for review
//   prompts/         the AI task prompts and their response schemas
//
// DEGRADING. The code reaches production BEFORE db/migrate.php runs, so the
// 511 corpus hymns must be readable with no tables at all: every function that
// needs a table asks hymnal_ready()/hymnal_table_exists() first and answers
// FEATURE_NOT_READY rather than throwing. Reading and singing never depend on
// the database — only favourites, the Studio and the member's saved position do.

require_once __DIR__ . '/collections.php';
require_once __DIR__ . '/solfa.php';
require_once __DIR__ . '/corpus.php';
require_once __DIR__ . '/songs.php';
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/member.php';
require_once __DIR__ . '/rights.php';
require_once __DIR__ . '/ai.php';

const HYMNAL_TABLES = [
    'hymnal_songs', 'hymnal_song_versions', 'hymnal_song_scores',
    'hymnal_song_collections', 'hymnal_favourites', 'hymnal_user_state',
    'hymnal_recent', 'hymnal_ai_jobs', 'hymnal_ai_requests', 'hymnal_audit',
    'hymnal_config',
];

/** How many hymns a member may mark. A guard on the table, not a product idea. */
const HYMNAL_MAX_FAVOURITES = 500;

function hymnal_env(string $name, string $default = ''): string
{
    $v = $_ENV[$name] ?? getenv($name);

    return is_string($v) && trim($v) !== '' ? trim($v) : $default;
}

/**
 * Does $table exist? Cached per request. Works on MySQL/MariaDB and on the
 * SQLite database the tests use.
 */
function hymnal_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        }
        $stmt->execute([$table]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('Hymnary table check: ' . $e->getMessage());
        $cache[$key] = false;
    }

    return $cache[$key];
}

/** Has the hymnal_core migration run? */
function hymnal_ready(?PDO $pdo): bool
{
    if ($pdo === null) {
        return false;
    }
    foreach (HYMNAL_TABLES as $t) {
        if (!hymnal_table_exists($pdo, $t)) {
            return false;
        }
    }

    return true;
}

/** Lagos time, as everywhere else on the platform (includes/db.php). */
function hymnal_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * A module switch. Reads fall back to $default whenever the table is not there
 * yet, so nothing on the page depends on the migration having run.
 */
function hymnal_config_get(?PDO $pdo, string $key, string $default = ''): string
{
    if ($pdo === null || !hymnal_table_exists($pdo, 'hymnal_config')) {
        return $default;
    }
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT config_value FROM hymnal_config WHERE config_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        $cache[$key] = $v === false ? $default : (string) $v;
    } catch (Throwable $e) {
        error_log('Hymnary config: ' . $e->getMessage());
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function hymnal_config_set(PDO $pdo, string $key, string $value, ?int $userId): bool
{
    if (!hymnal_table_exists($pdo, 'hymnal_config')) {
        return false;
    }
    try {
        // No ON DUPLICATE KEY: the tests run on SQLite (AGENTS.md).
        $stmt = $pdo->prepare('UPDATE hymnal_config SET config_value = ?, updated_by = ?, updated_at = ? WHERE config_key = ?');
        $stmt->execute([$value, $userId, hymnal_now(), $key]);
        if ($stmt->rowCount() === 0) {
            $ins = $pdo->prepare('INSERT INTO hymnal_config (config_key, config_value, updated_by, updated_at) VALUES (?, ?, ?, ?)');
            $ins->execute([$key, $value, $userId, hymnal_now()]);
        }
    } catch (Throwable $e) {
        error_log('Hymnary config set: ' . $e->getMessage());

        return false;
    }

    return true;
}

/** Write one line to the Studio's audit trail. Never fatal. */
function hymnal_audit(PDO $pdo, ?int $userId, string $action, ?int $songId, ?int $number, string $detail = ''): void
{
    if (!hymnal_table_exists($pdo, 'hymnal_audit')) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO hymnal_audit (user_id, action, song_id, song_number, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $action, $songId, $number, mb_substr($detail, 0, 500), hymnal_now()]);
    } catch (Throwable $e) {
        error_log('Hymnary audit: ' . $e->getMessage());
    }
}

/**
 * Everything the reader needs on first paint. modules/hymnal/index.php prints
 * it into the page; api/hymnal_api.php?action=boot returns the same thing.
 */
function hymnal_boot_payload(PDO $pdo, int $userId): array
{
    $ready = hymnal_ready($pdo);
    $meta  = hymnal_corpus_meta();
    $clearance = hymnal_clearance($pdo, $userId);

    return [
        'ready'       => $ready,
        'counts'      => [
            'songs'      => hymnal_library_count($pdo),
            'with_score' => (int) ($meta['with_score'] ?? 0),
            'corpus'     => (int) ($meta['songs'] ?? 0),
            'studio'     => hymnal_songs_count($pdo),
        ],
        'collections' => hymnal_library_collections($pdo),
        'state'       => hymnal_state_get($pdo, $userId),
        'favourites'  => hymnal_favourites($pdo, $userId),
        'recent'      => hymnal_recent($pdo, $userId, 8),
        'languages'   => hymnal_library_languages($pdo),
        'studio'      => [
            'allowed'      => $clearance['allowed'],
            'why'          => $clearance['why'],
            'can_publish'  => $clearance['can_publish'],
            'ai'           => hymnal_ai_available() && hymnal_config_get($pdo, 'ai_enabled', '1') === '1',
            'ai_off_why'   => hymnal_ai_unavailable_reason(),
            'collections'  => array_map(
                static fn(string $slug, array $c): array => ['slug' => $slug, 'name' => $c['name']],
                array_keys(hymnal_studio_collections()),
                array_values(hymnal_studio_collections())
            ),
        ],
        'solfa_key'   => [
            'degrees' => HYMNAL_SOLFA_DEGREES,
            'sharp'   => HYMNAL_SOLFA_SHARP,
            'flat'    => HYMNAL_SOLFA_FLAT,
        ],
    ];
}
