<?php
// /includes/our_sermons/actions.php
//
// The one entry point of api/our_sermons_api.php. Every access decision is
// here, against the 'our_sermons' row of the permission matrix (church-wide):
//
//   add_link                      Create   paste a Spotify / YouTube link → a draft
//   save, publish, hide           Edit     check and correct, publish (tells everyone once), hide
//   delete                        Delete   remove a sermon
//   feed_add, feed_remove,        Manage   the RSS feeds; Check now reads one at once
//   feed_check

/** @return array the JSON reply */
function our_sermons_action(PDO $pdo, string $action, array $in, int $uid): array
{
    if (!our_sermons_ready($pdo)) {
        return our_sermons_fail('Our sermons opens after the next update.', 'FEATURE_NOT_READY');
    }
    $need = [
        'add_link' => 'create',
        'save' => 'edit', 'publish' => 'edit', 'hide' => 'edit',
        'delete' => 'delete',
        'feed_add' => 'manage', 'feed_remove' => 'manage', 'feed_check' => 'manage',
    ][$action] ?? null;
    if ($need === null) {
        return our_sermons_fail('Unknown action.', 'UNKNOWN');
    }
    if (!can('our_sermons', $need, 'church')) {
        return our_sermons_fail('You do not have access to this. Ask Envision if you think you should.', 'FORBIDDEN');
    }
    $id = (int) ($in['id'] ?? 0);

    switch ($action) {
        case 'add_link':
            return our_sermons_add_link($pdo, (string) ($in['link'] ?? ''), $uid);
        case 'save':
            return our_sermons_save($pdo, $id, $in, $uid);
        case 'publish':
            return our_sermons_publish($pdo, $id, $in, $uid);
        case 'hide':
            return our_sermons_hide($pdo, $id, $uid);
        case 'delete':
            return our_sermons_delete($pdo, $id, $uid);
        case 'feed_add':
            return our_sermons_feed_add($pdo, (string) ($in['url'] ?? ''), isset($in['import_from']) ? (string) $in['import_from'] : null, $uid);
        case 'feed_remove':
            return our_sermons_feed_remove($pdo, $id);
        case 'feed_check':
            $r = our_sermons_feed_poll($pdo, $id, ['force' => true]);
            if ($r['error'] !== '') {
                return our_sermons_fail($r['error'], 'FEED');
            }
            if (!$r['read']) {
                return our_sermons_fail('It was read a moment ago. Try again in a minute.', 'BUSY');
            }
            return our_sermons_ok(['new' => $r['new'], 'linked' => $r['linked']]);
    }
    return our_sermons_fail('Unknown action.', 'UNKNOWN');
}
