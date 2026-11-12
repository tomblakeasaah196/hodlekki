<?php
// /includes/campaigns/bounces.php
//
// The bounce reader: one pass over system@'s inbox (system@ is the envelope
// sender of every email the outbox sends, so delivery reports and complaints
// come back there). cron/email_bounces.php opens the IMAP session with the
// mailbox module's client and calls email_bounces_run().
//
// Each UNREAD message:
//   - a delivery report or complaint (email_dsn_parse()) is applied by
//     email_record_bounce() — which only believes a report that names an email
//     we actually sent, and only acts on the address we sent it to — then
//     moved to INBOX.Bounces (matched or not), so the inbox stays clean;
//   - anything else is marked read and left where it is, for a person;
//   - a message that fails to process is flagged (\Flagged) and left unread:
//     it is skipped from then on, so one bad message never blocks the rest.
// --dry-run reads and reports, and changes nothing anywhere.

use HOD\Mail\Imap\ImapSession;

const EMAIL_BOUNCES_LOCK   = 'hod_email_bounces';
const EMAIL_BOUNCES_FOLDER = 'INBOX.Bounces';   // cPanel's Dovecot: folders sit under INBOX with "."
const EMAIL_BOUNCES_BATCH  = 20;
const EMAIL_BOUNCES_MAX    = 300;               // per run; the rest waits for the next

/**
 * @param array{dry?:bool, limit?:int} $opts
 * @return array{read:int, hard:int, soft:int, complaints:int, delays:int, requeued:int, unmatched:int, other:int, failed:int, left:int, filed:bool}
 */
function email_bounces_run(PDO $pdo, ImapSession $imap, array $opts = []): array
{
    $dry = !empty($opts['dry']);
    $limit = max(1, min(EMAIL_BOUNCES_MAX, (int) ($opts['limit'] ?? EMAIL_BOUNCES_MAX)));
    $n = ['read' => 0, 'hard' => 0, 'soft' => 0, 'complaints' => 0, 'delays' => 0, 'requeued' => 0,
          'unmatched' => 0, 'other' => 0, 'failed' => 0, 'left' => 0, 'filed' => false];

    $imap->select('INBOX');
    // Unread and not set aside after a failure. The flags are checked here too:
    // a server's SEARCH may be looser than its flags (the tests' fake is).
    $uids = $imap->search('UNSEEN UNFLAGGED');
    $flags = $uids !== [] ? $imap->fetchFlags($uids) : [];
    $todo = [];
    foreach ($uids as $uid) {
        $f = $flags[$uid]['flags'] ?? [];
        if (!in_array('\Seen', $f, true) && !in_array('\Flagged', $f, true)) {
            $todo[] = (int) $uid;
        }
    }
    sort($todo);
    $n['left'] = max(0, count($todo) - $limit);
    $todo = array_slice($todo, 0, $limit);

    $file = [];
    foreach (array_chunk($todo, EMAIL_BOUNCES_BATCH) as $batch) {
        $raws = $imap->fetchSectionsBatch($batch, ['']);
        foreach ($batch as $uid) {
            $raw = (string) ($raws[$uid][''] ?? '');
            $n['read']++;
            try {
                $report = $raw !== '' ? email_dsn_parse($raw) : null;
                if ($report === null) {
                    $n['other']++;
                    if (!$dry) {
                        $imap->addFlags([$uid], ['\Seen']);
                    }
                    continue;
                }
                if ($dry) {
                    email_bounces_count($n, $report, email_bounces_matches($pdo, $report) ? null : ['kind' => 'unmatched']);
                    continue;
                }
                email_bounces_count($n, $report, email_record_bounce($pdo, $report));
                $file[] = $uid;
            } catch (Throwable $e) {
                $n['failed']++;
                error_log('[email] bounce reader: message ' . $uid . ' failed: ' . $e->getMessage());
                if (!$dry) {
                    try {
                        $imap->addFlags([$uid], ['\Flagged']);
                    } catch (Throwable $ignored) {
                        // the next run sees it again
                    }
                }
            }
        }
    }

    if ($file !== [] && !$dry) {
        $n['filed'] = email_bounces_file($imap, $file);
    }
    return $n;
}

/** Dry run: would email_record_bounce() believe this report (does it name an email we sent)? */
function email_bounces_matches(PDO $pdo, array $report): bool
{
    if (!empty($report['ref']) && email_outbox_by_ref($pdo, (string) $report['ref']) !== null) {
        return true;
    }
    if (!empty($report['message_id'])) {
        $stmt = $pdo->prepare('SELECT 1 FROM email_outbox WHERE message_id_hash = ? LIMIT 1');
        $stmt->execute([email_message_id_hash((string) $report['message_id'])]);
        return (bool) $stmt->fetchColumn();
    }
    return false;
}

/** Tally one report: from the recorder's answer, or (dry run) from the report itself. */
function email_bounces_count(array &$n, array $report, ?array $done): void
{
    $kind = (string) ($done['kind'] ?? $report['kind'] ?? '');
    if ($kind === 'unmatched') {
        $n['unmatched']++;
    } elseif ($kind === 'cap') {
        $n['requeued'] += ($done === null || !empty($done['requeued'])) ? 1 : 0;
    } elseif ($kind === 'complaint') {
        $n['complaints']++;
    } elseif ($kind === 'delay') {
        $n['delays']++;
    } elseif ($done !== null) {
        $n['hard'] += (int) $done['suppressed'];
        $n['soft'] += (int) $done['soft'];
    } elseif (!empty($report['recipients'][0]['hard'])) {
        $n['hard']++;
    } else {
        $n['soft']++;
    }
}

/** Move processed reports out of the inbox; failing that, mark them read. */
function email_bounces_file(ImapSession $imap, array $uids): bool
{
    try {
        $have = array_map(static fn ($f) => is_array($f) ? (string) ($f['path'] ?? '') : (string) $f, $imap->folders());
        if (!in_array(EMAIL_BOUNCES_FOLDER, $have, true)) {
            $imap->createFolder(EMAIL_BOUNCES_FOLDER);
        }
        $imap->move($uids, EMAIL_BOUNCES_FOLDER);
        return true;
    } catch (Throwable $e) {
        error_log('[email] bounce reader: could not file reports in ' . EMAIL_BOUNCES_FOLDER . ': ' . $e->getMessage());
        try {
            $imap->addFlags($uids, ['\Seen']);
        } catch (Throwable $ignored) {
            // read again next run: email_record_bounce() is idempotent enough (suppress/notes)
        }
        return false;
    }
}

/** One line for the cron log and the stored last run. */
function email_bounces_line(array $n): string
{
    return sprintf('read %d: %d hard, %d soft, %d complaint(s), %d delay(s), %d re-queued, %d unmatched, %d other, %d failed%s',
        $n['read'], $n['hard'], $n['soft'], $n['complaints'], $n['delays'], $n['requeued'], $n['unmatched'], $n['other'], $n['failed'],
        $n['left'] > 0 ? ', ' . $n['left'] . ' left for the next run' : '');
}
