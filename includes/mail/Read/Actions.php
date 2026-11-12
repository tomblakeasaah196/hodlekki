<?php

namespace HOD\Mail\Read;

use HOD\Mail\MailAccess;
use HOD\Mail\MailActions;
use HOD\Mail\MailError;
use HOD\Mail\MailNav;
use HOD\Mail\Store;

/**
 * The reader's toolbar and keyboard actions on the open conversation (docs/MAIL.md), through PR 1's MailActions
 * (the server first, then the ERP's copy). Each one says what it did in a short French sentence; a move can be
 * undone (the ids and the folder they came from go back to the page, and are checked again on Annuler).
 *
 *   archive, spam, trash, inbox    move what is in view (Not spam / Move to Inbox)
 *   read, unread                   any level (unread: the latest message, to come back to it)
 *   flag, unflag                   suivi
 *   delete                         for good, from Trash or Spam only (the page confirms first)
 *   move:<folder id>               another folder of the same mailbox
 */
final class Actions
{
    public const MOVES = ['archive', 'spam', 'trash', 'inbox'];

    /** @return array{message: string, undo: ?array{ids: list<int>, folder: int}, left: bool} left: no longer in this folder */
    public static function run(Inbox $inbox, array $thread, string $action, bool $searching): array
    {
        $userId = $inbox->userId;
        $roles = $inbox->roles();
        $scope = Reader::scope($thread['id'], $inbox->account['id'], $inbox->folder, $searching, $roles);
        if (!$scope) {
            throw MailError::notFound('This conversation has no messages here now. Refresh the list.');
        }
        $ids = array_map(fn ($m) => $m['id'], $scope);
        $latest = end($scope);
        $from = $scope[0]['folder_id'];
        $actions = MailActions::make();
        $moved = false;

        if (in_array($action, self::MOVES, true)) {
            $actions->moveToRole($userId, $ids, $action);
            $moved = true;
            $message = match ($action) {
                'archive' => 'Archived.',
                'spam' => 'Moved to Spam.',
                'trash' => 'Moved to Trash.',
                default => 'Moved to Inbox.',
            };
        } elseif ($action === 'read') {
            $actions->markRead($userId, $ids, true);
            $message = 'Marked read.';
        } elseif ($action === 'unread') {
            $actions->markRead($userId, [$latest['id']], false);
            $message = 'Marked unread.';
        } elseif ($action === 'flag') {
            $actions->flag($userId, [$latest['id']], true);
            $message = 'Flagged.';
        } elseif ($action === 'unflag') {
            $flagged = array_map(fn ($m) => $m['id'], array_filter(Store::threadMessages($thread['id']), fn ($m) => $m['is_flagged'] && $m['account_id'] === $inbox->account['id']));
            $actions->flag($userId, $flagged, false);
            $message = 'Flag removed.';
        } elseif ($action === 'delete') {
            $actions->deleteForever($userId, $ids);
            $message = 'Deleted for good.';
        } elseif (preg_match('/^move:(\d+)$/', $action, $m)) {
            $to = $inbox->folderById((int) $m[1]) ?? throw MailError::invalid('That folder does not belong to this mailbox.');
            $actions->move($userId, $ids, $to['id']);
            $moved = true;
            $message = 'Moved to “' . Store::folderLabel($to) . '”.';
        } else {
            throw MailError::invalid('Unknown action.');
        }
        MailNav::forget();

        // Moves keep the same rows (the server gives the new UIDs), so Annuler can bring them back
        $kept = $moved ? array_values(array_filter(Store::messages($ids), fn ($r) => $r['folder_id'] !== $from)) : [];

        return [
            'message' => $message,
            'undo' => $moved && $kept ? ['ids' => array_map(fn ($r) => $r['id'], $kept), 'folder' => $from] : null,
            'left' => $moved || $action === 'delete',
        ];
    }

    /** Annuler after a move: the same messages back to the folder they came from */
    public static function undo(Inbox $inbox, array $ids, int $folderId): string
    {
        $folder = $inbox->folderById($folderId);
        $rows = array_values(array_filter(Store::messages(array_slice(array_map('intval', $ids), 0, 500)), fn ($r) => $r['account_id'] === $inbox->account['id']));
        if (!$folder || !$rows) {
            throw MailError::invalid('This can no longer be undone.');
        }
        MailAccess::authorize($inbox->userId, $inbox->account, 'organise');
        MailActions::make()->move($inbox->userId, array_map(fn ($r) => $r['id'], $rows), $folder['id']);
        MailNav::forget();

        return 'Moved back to “' . Store::folderLabel($folder) . '”.';
    }

    /** Empty: Trash or Spam, everything on the server */
    public static function emptyFolder(Inbox $inbox, array $folder): string
    {
        MailActions::make()->emptyFolder($inbox->userId, $folder['id']);
        MailNav::forget();

        return $folder['role'] === 'trash' ? 'Trash emptied.' : 'Spam emptied.';
    }
}
