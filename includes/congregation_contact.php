<?php
// /includes/congregation_contact.php
/**
 * Reaching a person from Congregation (owner, 13 Nov 2026): a row opens their
 * details (modules/congregation/, assets/ui/js/pages/congregation.js), and
 * from there anyone who may view Congregation can call or WhatsApp them;
 * Email shows for people with a mailbox they may send from (Mail's own
 * composer), SMS for people with SMS Studio access (SMS Studio's own sender,
 * so it is in History and checks the wallet).
 *
 * This file holds the pure parts, tested by tests/congregation_contact_test.php
 * on SQLite:
 *   cong_contact_email()   the address to write to
 *   cong_contact_intl()    the number for tel: and wa.me (Nigerian rules)
 *   cong_contact_log()     one row in congregation_contacts per reach-out
 *   cong_contact_recent()  the latest rows for the details, with the email's
 *                          or text's own state; an email that was undone and
 *                          deleted, or is a draft again, is not shown
 *   cong_contact_rights()  what the signed-in person may do (Email, SMS, Edit)
 *
 * Only the fact of contact is kept, never the message. Degrades before the
 * migration (20261215090000): nothing is logged, the history is empty.
 */

const CONG_CONTACT_CHANNELS = ['call', 'whatsapp', 'sms', 'email'];
/** A second Call or WhatsApp tap on the same person by the same person within this is not logged again. */
const CONG_CONTACT_REPEAT_SECONDS = 120;
/** The longest text accepted from the SMS box (6 pages of plain text). */
const CONG_CONTACT_SMS_MAX = 900;

/** Whether $table exists on this connection (MySQL or SQLite), asked once per connection. */
function cong_contact_has_table(PDO $pdo, string $table): bool
{
    static $seen = [];
    if (preg_match('/^[a-z_]+$/', $table) !== 1) {
        return false;
    }
    $key = spl_object_id($pdo) . ':' . $table;
    if (!array_key_exists($key, $seen)) {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            $seen[$key] = true;
        } catch (PDOException $e) {
            $seen[$key] = false;
        }
    }

    return $seen[$key];
}

function cong_contact_ready(PDO $pdo): bool
{
    return cong_contact_has_table($pdo, 'congregation_contacts');
}

/**
 * The address to write to: the confirmed personal email (users.real_email), else
 * users.email, but never a @hodlc.com sign-in name (those are not mailboxes).
 */
function cong_contact_email(array $person): ?string
{
    foreach ([$person['real_email'] ?? '', $person['email'] ?? ''] as $raw) {
        $email = strtolower(trim((string) $raw));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }
        if (str_ends_with($email, '@hodlc.com')) {
            continue;
        }
        return $email;
    }

    return null;
}

/**
 * The number in international form, digits only (2348012345678), for tel: and
 * wa.me. Nigerian numbers as typed (08012345678, 8012345678, +234 (0)801…)
 * gain 234; anything else is kept if it has 10 to 15 digits.
 */
function cong_contact_intl(?string $phone): ?string
{
    $p = preg_replace('/\D/', '', (string) $phone);
    if (str_starts_with($p, '00')) {
        $p = substr($p, 2);
    }
    if (strlen($p) === 14 && str_starts_with($p, '2340')) {
        $p = '234' . substr($p, 4);
    } elseif (strlen($p) === 11 && $p[0] === '0') {
        $p = '234' . substr($p, 1);
    } elseif (strlen($p) === 10 && $p[0] !== '0') {
        $p = '234' . $p;
    }

    return strlen($p) >= 10 && strlen($p) <= 15 && $p[0] !== '0' ? $p : null;
}

/**
 * Log one reach-out. An email or a text is logged once (UNIQUE channel + ref);
 * a Call or WhatsApp tap repeated within CONG_CONTACT_REPEAT_SECONDS is not
 * logged twice. Returns false only when nothing could be logged.
 */
function cong_contact_log(PDO $pdo, int $personId, string $channel, int $byUserId, ?string $ref = null, ?int $now = null): bool
{
    if ($personId <= 0 || $byUserId <= 0 || !in_array($channel, CONG_CONTACT_CHANNELS, true) || !cong_contact_ready($pdo)) {
        return false;
    }
    $now = $now ?? time();
    $ref = $ref !== null && trim($ref) !== '' ? substr(trim($ref), 0, 64) : null;
    try {
        if ($ref === null) {
            $recent = $pdo->prepare(
                'SELECT 1 FROM congregation_contacts
                  WHERE person_id = ? AND channel = ? AND by_user_id = ? AND created_at >= ? LIMIT 1'
            );
            $recent->execute([$personId, $channel, $byUserId, date('Y-m-d H:i:s', $now - CONG_CONTACT_REPEAT_SECONDS)]);
            if ($recent->fetchColumn()) {
                return true;
            }
        }
        $pdo->prepare(
            'INSERT INTO congregation_contacts (person_id, channel, by_user_id, ref, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$personId, $channel, $byUserId, $ref, date('Y-m-d H:i:s', $now)]);

        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return true;   // this email or text is already logged
        }
        error_log('Congregation contact log: ' . $e->getMessage());

        return false;
    }
}

/**
 * The latest reach-outs to a person, newest first:
 * [{channel, by, at, state}] where state is null (call, WhatsApp), or
 * 'sending' | 'sent' | 'delivered' | 'failed' from sms_log / mail_outbox.
 */
function cong_contact_recent(PDO $pdo, int $personId, int $limit = 5): array
{
    if ($personId <= 0 || !cong_contact_ready($pdo)) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    try {
        $stmt = $pdo->prepare(
            'SELECT c.channel, c.ref, c.created_at, c.by_user_id, u.first_name, u.last_name
               FROM congregation_contacts c
               LEFT JOIN users u ON u.id = c.by_user_id
              WHERE c.person_id = ?
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . ($limit * 3)
        );
        $stmt->execute([$personId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Congregation contact history: ' . $e->getMessage());

        return [];
    }

    $states = ['sms' => [], 'email' => []];
    $refs = ['sms' => [], 'email' => []];
    foreach ($rows as $r) {
        if (isset($refs[$r['channel']]) && $r['ref'] !== null) {
            $refs[$r['channel']][] = (string) $r['ref'];
        }
    }
    $lookups = ['sms' => ['sms_log', 'status'], 'email' => ['mail_outbox', 'status']];
    foreach ($lookups as $channel => [$table, $column]) {
        if (!$refs[$channel] || !cong_contact_has_table($pdo, $table)) {
            continue;
        }
        try {
            $in = implode(',', array_fill(0, count($refs[$channel]), '?'));
            $q = $pdo->prepare("SELECT id, {$column} FROM {$table} WHERE id IN ({$in})");
            $q->execute($refs[$channel]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as [$id, $status]) {
                $states[$channel][(string) $id] = (string) $status;
            }
        } catch (PDOException $e) {
            error_log('Congregation contact history (' . $table . '): ' . $e->getMessage());
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $state = null;
        if ($r['channel'] === 'email') {
            $raw = $states['email'][(string) $r['ref']] ?? null;
            if (cong_contact_has_table($pdo, 'mail_outbox') && ($raw === null || $raw === 'draft' || $raw === 'cancelled')) {
                continue;   // undone: deleted, or a draft again
            }
            $state = match ($raw) {
                'sent' => 'sent',
                'failed' => 'failed',
                null => null,
                default => 'sending',
            };
        } elseif ($r['channel'] === 'sms') {
            $raw = $states['sms'][(string) $r['ref']] ?? null;
            $state = match ($raw) {
                'delivered' => 'delivered',
                'failed', 'blocked', 'rejected' => 'failed',
                null => null,
                default => 'sent',
            };
        }
        $out[] = [
            'channel' => $r['channel'],
            'by' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Someone',
            'at' => $r['created_at'],
            'state' => $state,
        ];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/**
 * What the signed-in person may do from someone's details. Email: Mail is set
 * up and they hold at least one mailbox they may send from (MailAccess). SMS:
 * SMS Studio access and its vault key. Edit: Congregation edit.
 */
function cong_contact_rights(int $userId): array
{
    require_once __DIR__ . '/sms_functions.php';
    $sms = sms_user_can_send() && sms_vault_ready();

    $email = false;
    try {
        require_once __DIR__ . '/mail/bootstrap.php';
        $email = mail_ready() && mail_compose_ready() && \HOD\Mail\MailAccess::accounts($userId, 'send') !== [];
    } catch (Throwable $e) {
        error_log('Congregation contact rights (mail): ' . $e->getMessage());
    }

    return ['email' => $email, 'sms' => $sms, 'edit' => can('congregation', 'edit')];
}
