<?php

namespace HOD\Mail;

/**
 * Reading mail rows. Every row is a plain array with its JSON columns decoded and its ids as int:
 *
 *   account  id, kind (department/church/personal), email, local_part, display_name, department_id, owner_user_id,
 *            managed, is_pastoral, ai_enabled, status, leaving_since, remove_after, disk_quota_mb, disk_used_mb,
 *            disk_checked_at, disk_warned_at, last_error, capabilities (list), folders_synced_at, last_synced_at,
 *            change_stamp, created_by, suspended_by, suspended_at, created_at, updated_at
 *            (never the secrets: see accountWithSecret)
 *   folder   id, account_id, path, name, delimiter, role, attributes (list), selectable, uidvalidity, uidnext,
 *            highestmodseq, unread_count, total_count, server_unseen, server_total, initial_synced_at, synced_at
 *   thread   id, account_id, subject, subject_key, snippet, participants (list), message_count, unread_count,
 *            has_attachments, first/last_message_at, last_inbound_at, status (open/waiting/done), status_by,
 *            status_at, assigned_to/by/at, ai_summary, ai_summary_at
 *   message  id, account_id, folder_id, thread_id, uid, message_id, message_id_hash, in_reply_to, refs, subject,
 *            from_name, from_email, to_list, cc_list, bcc_list, reply_to_list (lists of {name, email}), to_text,
 *            sent_at, received_at, size, is_* flags (bool), has_attachments, is_outbound, is_auto, snippet,
 *            body_state, parts, list_unsubscribe, seen_by, seen_at, sent_by (bodies only through MailFetch::body())
 *
 * These read without access checks: callers check with MailAccess first (docs/MAIL.md, "Access").
 */
final class Store
{
    public const KINDS = ['department', 'church', 'personal'];

    public const ROLE_ORDER = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'spam' => 4, 'trash' => 5, 'other' => 6];

    public const ROLE_LABELS = [
        'inbox' => 'Inbox', 'drafts' => 'Drafts', 'sent' => 'Sent',
        'archive' => 'Archive', 'spam' => 'Spam', 'trash' => 'Trash',
    ];

    private const ACCOUNT_COLUMNS = 'id, kind, email, local_part, display_name, department_id, owner_user_id, managed, is_pastoral,
        ai_enabled, status, leaving_since, remove_after, disk_quota_mb, disk_used_mb, disk_checked_at, disk_warned_at, last_error,
        capabilities, folders_synced_at, last_synced_at, change_stamp, created_by, suspended_by, suspended_at, created_at, updated_at';

    private const MESSAGE_COLUMNS = 'id, account_id, folder_id, thread_id, uid, message_id, message_id_hash, in_reply_to, refs, subject,
        from_name, from_email, to_list, cc_list, bcc_list, reply_to_list, to_text, sent_at, received_at, size, is_seen, is_flagged,
        is_answered, is_draft, has_attachments, is_outbound, is_auto, snippet, body_state, parts, list_unsubscribe, seen_by, seen_at,
        sent_by, created_at, updated_at';

    // ------------------------------------------------------------------ accounts

    public static function account(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::ACCOUNT_COLUMNS . ' FROM mail_accounts WHERE id = ?', [$id]);

        return $row ? self::accountRow($row) : null;
    }

    public static function accountByEmail(string $email): ?array
    {
        $row = Db::one('SELECT ' . self::ACCOUNT_COLUMNS . ' FROM mail_accounts WHERE email = ?', [strtolower(trim($email))]);

        return $row ? self::accountRow($row) : null;
    }

    /** With the encrypted passwords: only MailServer and the admin jobs use this */
    public static function accountWithSecret(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::ACCOUNT_COLUMNS . ', secret, secret_pending FROM mail_accounts WHERE id = ?', [$id]);

        return $row ? self::accountRow($row) + ['secret' => $row['secret'], 'secret_pending' => $row['secret_pending']] : null;
    }

    /** @param string $where SQL after WHERE, with ? placeholders @return list<array> */
    public static function accounts(string $where = '1 = 1', array $params = []): array
    {
        return array_map([self::class, 'accountRow'], Db::all('SELECT ' . self::ACCOUNT_COLUMNS . ' FROM mail_accounts WHERE ' . $where, $params));
    }

    /** Department and church mailboxes are shared; a personal one belongs to one person */
    public static function isShared(array $account): bool
    {
        return ($account['kind'] ?? '') !== 'personal';
    }

    /** Something on screen in this mailbox changed: list polls compare this number */
    public static function bump(int $accountId): void
    {
        Db::exec('UPDATE mail_accounts SET change_stamp = change_stamp + 1 WHERE id = ?', [$accountId]);
    }

    // ------------------------------------------------------------------ folders

    public static function folder(int $id): ?array
    {
        $row = Db::one('SELECT * FROM mail_folders WHERE id = ?', [$id]);

        return $row ? self::folderRow($row) : null;
    }

    /** The mailbox's folder with this role (inbox, sent, drafts, archive, spam, trash), if the server has one */
    public static function folderByRole(int $accountId, string $role): ?array
    {
        $row = Db::one('SELECT * FROM mail_folders WHERE account_id = ? AND role = ? AND selectable = 1 ORDER BY id LIMIT 1', [$accountId, $role]);

        return $row ? self::folderRow($row) : null;
    }

    /** @return list<array> standard folders first (Store::ROLE_ORDER), then the others by name */
    public static function folders(int $accountId, bool $selectableOnly = false): array
    {
        $rows = array_map([self::class, 'folderRow'], Db::all(
            'SELECT * FROM mail_folders WHERE account_id = ?' . ($selectableOnly ? ' AND selectable = 1' : ''),
            [$accountId],
        ));
        usort($rows, fn ($a, $b) => [self::ROLE_ORDER[$a['role']] ?? 9, mb_strtolower($a['name'])] <=> [self::ROLE_ORDER[$b['role']] ?? 9, mb_strtolower($b['name'])]);

        return $rows;
    }

    public static function folderLabel(array $folder): string
    {
        return self::ROLE_LABELS[$folder['role']] ?? $folder['name'];
    }

    // ------------------------------------------------------------------ threads

    public static function thread(int $id): ?array
    {
        $row = Db::one('SELECT * FROM mail_threads WHERE id = ?', [$id]);

        return $row ? self::threadRow($row) : null;
    }

    // ------------------------------------------------------------------ messages

    public static function message(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::MESSAGE_COLUMNS . ' FROM mail_messages WHERE id = ?', [$id]);

        return $row ? self::messageRow($row) : null;
    }

    /** @return list<array> in received order */
    public static function messages(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        return array_map([self::class, 'messageRow'], Db::all(
            'SELECT ' . self::MESSAGE_COLUMNS . ' FROM mail_messages WHERE id IN (' . Db::marks(count($ids)) . ') ORDER BY received_at, id',
            $ids,
        ));
    }

    /** @return list<array> every copy of every message in a conversation, oldest first */
    public static function threadMessages(int $threadId): array
    {
        return array_map([self::class, 'messageRow'], Db::all(
            'SELECT ' . self::MESSAGE_COLUMNS . ' FROM mail_messages WHERE thread_id = ? ORDER BY received_at, id',
            [$threadId],
        ));
    }

    /** @return list<array> the attachments of a message (details only; content through MailFetch) */
    public static function attachments(int $messageId): array
    {
        return array_map([self::class, 'attachmentRow'], Db::all('SELECT * FROM mail_attachments WHERE message_id = ? ORDER BY id', [$messageId]));
    }

    public static function attachment(int $id): ?array
    {
        $r = Db::one('SELECT * FROM mail_attachments WHERE id = ?', [$id]);

        return $r ? self::attachmentRow($r) : null;
    }

    /** How Message-IDs are matched: lower case, no angle brackets, sha1 */
    public static function hashId(?string $messageId): ?string
    {
        $messageId = trim((string) $messageId, " \t<>");

        return $messageId === '' ? null : sha1(strtolower($messageId));
    }

    /** Message-IDs a message answers, oldest first (References, then In-Reply-To; without brackets) */
    public static function referenceIds(array $message): array
    {
        $ids = preg_split('/\s+/', trim((string) ($message['refs'] ?? ''))) ?: [];
        if (!empty($message['in_reply_to'])) {
            $ids[] = $message['in_reply_to'];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    // ------------------------------------------------------------------ row shapes

    public static function accountRow(array $r): array
    {
        unset($r['secret'], $r['secret_pending']);
        foreach (['id', 'disk_quota_mb', 'change_stamp'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['department_id', 'owner_user_id', 'disk_used_mb', 'created_by', 'suspended_by'] as $k) {
            $r[$k] = isset($r[$k]) ? (int) $r[$k] : null;
        }
        foreach (['managed', 'is_pastoral', 'ai_enabled'] as $k) {
            $r[$k] = (bool) $r[$k];
        }
        $r['capabilities'] = Db::decode($r['capabilities'] ?? null) ?? [];

        return $r;
    }

    public static function folderRow(array $r): array
    {
        foreach (['id', 'account_id', 'unread_count', 'total_count'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['uidvalidity', 'uidnext', 'highestmodseq', 'server_unseen', 'server_total', 'status_uidnext'] as $k) {
            $r[$k] = isset($r[$k]) ? (int) $r[$k] : null;
        }
        $r['selectable'] = (bool) $r['selectable'];
        $r['attributes'] = Db::decode($r['attributes'] ?? null) ?? [];

        return $r;
    }

    public static function threadRow(array $r): array
    {
        foreach (['id', 'account_id', 'message_count', 'unread_count'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['assigned_to', 'assigned_by', 'status_by'] as $k) {
            $r[$k] = isset($r[$k]) ? (int) $r[$k] : null;
        }
        $r['has_attachments'] = (bool) $r['has_attachments'];
        $r['participants'] = Db::decode($r['participants'] ?? null) ?? [];
        $r['ai_summary'] = Db::decode($r['ai_summary'] ?? null);

        return $r;
    }

    public static function messageRow(array $r): array
    {
        foreach (['id', 'account_id', 'folder_id', 'uid', 'size'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['thread_id', 'seen_by', 'sent_by'] as $k) {
            $r[$k] = isset($r[$k]) ? (int) $r[$k] : null;
        }
        foreach (['is_seen', 'is_flagged', 'is_answered', 'is_draft', 'has_attachments', 'is_outbound', 'is_auto'] as $k) {
            $r[$k] = (bool) ($r[$k] ?? false);
        }
        foreach (['to_list', 'cc_list', 'bcc_list', 'reply_to_list'] as $k) {
            $r[$k] = Db::decode($r[$k] ?? null) ?? [];
        }
        $r['parts'] = Db::decode($r['parts'] ?? null) ?? [];

        return $r;
    }

    private static function attachmentRow(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'message_id' => (int) $r['message_id'], 'section' => $r['section'],
            'filename' => $r['filename'], 'mime' => $r['mime'], 'encoding' => $r['encoding'],
            'size' => (int) $r['size'], 'content_id' => $r['content_id'], 'is_inline' => (bool) $r['is_inline'],
        ];
    }
}
