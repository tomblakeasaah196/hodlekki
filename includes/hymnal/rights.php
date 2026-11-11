<?php
// /includes/hymnal/rights.php
//
// Who may open the Studio.
//
// Reading the library is open to every signed-in member. Adding or changing a
// song is not: what is in the library is what the congregation sings and what
// the choir rehearses, and a song carries a licence claim the church stands
// behind. Publishing — making a draft visible to the congregation — is
// narrower still. Both are rows of the permission matrix (module 'hymnal'):
//
//   Studio   can('hymnal', 'edit')     day one: the department that owns the
//                                       Hymnary (River of Life, who run music
//                                       here) and the pastors
//   Publish  can('hymnal', 'approve')  day one: the pastors
//
// Read live for the member asked about, never from the session.

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
    $ctx = ($userId > 0 && function_exists('perm_resolve')) ? perm_resolve($pdo, $userId) : null;
    $bg = $ctx !== null && $ctx['breakglass'];
    $allowed = $bg || ($ctx !== null && perm_ctx_can($ctx, 'hymnal', 'edit'));
    $canPublish = $bg || ($ctx !== null && perm_ctx_can($ctx, 'hymnal', 'approve'));
    $music = $userId > 0 && function_exists('perm_module_team') && in_array($userId, perm_module_team($pdo, 'hymnal'), true);

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
        'roles'       => [],
        'music'       => $music,
        'why'         => $why,
    ];
}

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
