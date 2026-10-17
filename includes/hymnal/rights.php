<?php
// /includes/hymnal/rights.php
//
// Who may open the Studio.
//
// Reading the library is open to every signed-in member. Adding or changing a
// song is not: what is in the library is what the congregation sings and what
// the choir rehearses, and a song carries a licence claim the church stands
// behind. So the Studio is held by:
//
//   - the River of Life department, who run music at this centre, and
//   - Super_Admin, Resident_Pastor and Assoc_Pastor.
//
// Publishing — making a draft visible to the congregation — is narrower still:
// a pastor or a Super_Admin. The music team prepares; a pastor releases.
//
// Roles are read LIVE from the roles table, never from $_SESSION['active_role'],
// which a member switches themselves from their profile screen (AGENTS.md). The
// Alerts Centre and the Security Centre are gated the same way and for the same
// reason.

/** Roles that may open the Studio, beyond River of Life membership. */
const HYMNAL_STUDIO_ROLES = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];

/** Roles that may publish a song, or retire one. */
const HYMNAL_PUBLISH_ROLES = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];

/**
 * River of Life's department id.
 *
 * Resolved by name, because an id is a fact about one database and this reads
 * better in six months. Department 4 is what it is in production today
 * (api/rol_api.php hardcodes it) and stands as the fallback when the lookup
 * cannot run.
 */
function hymnal_music_department_id(PDO $pdo): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE name LIKE ? ESCAPE '!' ORDER BY id LIMIT 1");
        $stmt->execute(['%River of Life%']);
        $found = $stmt->fetchColumn();
        $id = $found === false ? 4 : (int) $found;
    } catch (Throwable $e) {
        error_log('Hymnary music department lookup: ' . $e->getMessage());
        $id = 4;
    }

    return $id;
}

/** Is this member serving in River of Life right now, in the live year? */
function hymnal_in_music_department(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM user_departments
              WHERE user_id = ? AND department_id = ? AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$userId, hymnal_music_department_id($pdo)]);

        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        error_log('Hymnary department check: ' . $e->getMessage());

        return false;
    }
}

/** Live role names for this member, or an empty list if we cannot tell. */
function hymnal_role_names(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    if (function_exists('security_user_role_names')) {
        return security_user_role_names($pdo, $userId);
    }
    // security_helpers.php is loaded by includes/db.php on every web request;
    // this path is for a CLI caller that bootstrapped the module alone.
    try {
        $stmt = $pdo->prepare(
            'SELECT r.role_name FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND COALESCE(ur.is_frozen, 0) = 0'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('Hymnary role lookup: ' . $e->getMessage());

        return [];
    }
}

/**
 * The one answer the page and every endpoint ask.
 *
 * `why` is written for the member to read, so a chorister who expected the
 * Studio button and does not have it is told what would give it to them
 * instead of being left guessing.
 *
 * @return array{allowed:bool, can_publish:bool, roles:list<string>, music:bool, why:string}
 */
function hymnal_clearance(PDO $pdo, int $userId): array
{
    $roles = hymnal_role_names($pdo, $userId);
    $music = hymnal_in_music_department($pdo, $userId);
    $isRole = static fn(array $allowed): bool => array_intersect($allowed, $roles) !== [];

    $allowed = $music || $isRole(HYMNAL_STUDIO_ROLES);
    $canPublish = $isRole(HYMNAL_PUBLISH_ROLES);

    if (!$allowed) {
        $why = 'The Studio is for the River of Life department and the pastors. '
            . 'Ask the church office to add you to River of Life if you help with music.';
    } elseif (!$canPublish) {
        $why = 'You can add and edit songs. A pastor publishes them to the congregation.';
    } else {
        $why = 'You can add, edit and publish songs.';
    }

    return [
        'allowed'     => $allowed,
        'can_publish' => $canPublish,
        'roles'       => array_values($roles),
        'music'       => $music,
        'why'         => $why,
    ];
}

/** Throwing version for an endpoint, returning the API error shape instead. */
function hymnal_require_studio(PDO $pdo, int $userId): ?array
{
    $c = hymnal_clearance($pdo, $userId);
    if (!$c['allowed']) {
        return ['status' => 'error', 'code' => 'FORBIDDEN', 'message' => $c['why']];
    }

    return null;
}

function hymnal_require_publish(PDO $pdo, int $userId): ?array
{
    $c = hymnal_clearance($pdo, $userId);
    if (!$c['can_publish']) {
        return [
            'status'  => 'error',
            'code'    => 'FORBIDDEN',
            'message' => 'Only a pastor can publish a song to the congregation.',
        ];
    }

    return null;
}
