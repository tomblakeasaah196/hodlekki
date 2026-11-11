<?php
// /includes/campaigns/dsn.php
//
// Bounces and complaints. They arrive in system@'s inbox (system@ is the
// envelope sender of everything the outbox sends). Reading that inbox needs an
// IMAP client, which the mailbox module brings (its PR 1); cron/email_bounces.php
// will hand each message to email_dsn_parse() and email_record_bounce(). The
// parser is pure and works on the raw message text.
//
// Understood:
//   - RFC 3464 delivery reports (multipart/report; report-type=delivery-status):
//     Final-Recipient / Original-Recipient, Action, Status, Diagnostic-Code;
//   - Exim's plain bounces (X-Failed-Recipients header, "permanent error");
//   - RFC 5965 complaints (multipart/report; report-type=feedback-report);
//   - the host's hourly cap ("exceeded the max emails per hour"), which is
//     NOT the recipient's fault: the email goes back in the queue.
// Our own X-HOD-Ref header, found in the returned headers, ties a report to the
// exact outbox row; failing that, the original Message-ID.

/** @return array{0:array<string,string>,1:string} [lower-case headers, body] */
function email_mime_split(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $pos = strpos($raw, "\n\n");
    $head = $pos === false ? $raw : substr($raw, 0, $pos);
    $body = $pos === false ? '' : substr($raw, $pos + 2);
    $headers = [];
    $current = null;
    foreach (explode("\n", $head) as $line) {
        if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
            $headers[$current] .= ' ' . trim($line);
            continue;
        }
        if (preg_match('/^([A-Za-z0-9-]+)\s*:\s*(.*)$/', $line, $m)) {
            $current = strtolower($m[1]);
            $headers[$current] = isset($headers[$current]) && $current !== 'received'
                ? $headers[$current] : trim($m[2]);
        }
    }
    return [$headers, $body];
}

/** @return list<string> the parts of a multipart body */
function email_mime_parts(string $contentType, string $body): array
{
    if (!preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $m)) {
        return [];
    }
    $boundary = '--' . trim($m[1]);
    $parts = [];
    foreach (explode($boundary, $body) as $i => $chunk) {
        if ($i === 0 || str_starts_with($chunk, '--')) {
            continue;
        }
        $parts[] = ltrim($chunk, "\n");
    }
    return $parts;
}

/** Fields of a delivery-status / feedback-report body, grouped by blank lines. */
function email_report_groups(string $body): array
{
    $groups = [];
    foreach (preg_split("/\n\s*\n/", trim(str_replace(["\r\n", "\r"], "\n", $body))) ?: [] as $chunk) {
        [$fields] = email_mime_split($chunk . "\n\n");
        if ($fields) {
            $groups[] = $fields;
        }
    }
    return $groups;
}

function email_report_address(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim(preg_replace('/^[a-z0-9-]+\s*;/i', '', $value) ?? $value, " <>\t");
    $value = email_normalize($value);
    return email_is_valid_syntax($value) ? $value : null;
}

/**
 * Parse one message from system@'s inbox.
 *
 * @return array{kind:string, recipients:list<array{email:string, hard:bool, status:string, diagnostic:string}>,
 *               ref:?string, message_id:?string, cap:?int}|null  null = not a bounce or complaint
 *   kind: bounce | delay | complaint | cap
 */
function email_dsn_parse(string $raw): ?array
{
    [$headers, $body] = email_mime_split($raw);
    $type = strtolower($headers['content-type'] ?? '');
    $report = ['kind' => 'bounce', 'recipients' => [], 'ref' => null, 'message_id' => null, 'cap' => null];

    $returned = $body;
    if (str_starts_with($type, 'multipart/report') || (str_starts_with($type, 'multipart/') && str_contains($type, 'report-type'))) {
        $isFeedback = str_contains($type, 'feedback-report');
        $returned = '';
        foreach (email_mime_parts($headers['content-type'], $body) as $part) {
            [$ph, $pb] = email_mime_split($part);
            $ptype = strtolower($ph['content-type'] ?? 'text/plain');
            if (str_starts_with($ptype, 'message/delivery-status')) {
                $groups = email_report_groups($pb);
                foreach (array_slice($groups, 1) as $g) {
                    $addr = email_report_address($g['original-recipient'] ?? null) ?? email_report_address($g['final-recipient'] ?? null);
                    if ($addr === null) {
                        continue;
                    }
                    $action = strtolower(trim($g['action'] ?? 'failed'));
                    $status = trim($g['status'] ?? '');
                    $diag = trim($g['diagnostic-code'] ?? '');
                    $hard = $action === 'failed' && str_starts_with($status, '5') && !str_starts_with($status, '5.2.2');
                    if ($action === 'delivered' || $action === 'relayed' || $action === 'expanded') {
                        continue;
                    }
                    $report['recipients'][] = ['email' => $addr, 'hard' => $hard, 'status' => $status,
                                               'diagnostic' => campaigns_cut($diag, 250), 'action' => $action];
                }
            } elseif (str_starts_with($ptype, 'message/feedback-report')) {
                $groups = email_report_groups($pb);
                $g = $groups[0] ?? [];
                $addr = email_report_address($g['original-rcpt-to'] ?? null);
                $report['kind'] = 'complaint';
                if ($addr !== null) {
                    $report['recipients'][] = ['email' => $addr, 'hard' => true, 'status' => 'complaint',
                                               'diagnostic' => campaigns_cut(trim($g['feedback-type'] ?? 'abuse'), 250), 'action' => 'complaint'];
                }
            } elseif (str_starts_with($ptype, 'text/rfc822-headers') || str_starts_with($ptype, 'message/rfc822')) {
                $returned .= "\n" . $pb;
            } else {
                $returned .= "\n" . $pb; // human-readable part: may quote the server's words
            }
        }
        if ($isFeedback) {
            $report['kind'] = 'complaint';
        }
    } elseif (isset($headers['x-failed-recipients'])) {
        // Exim's own bounce: "This is a permanent error. The following address(es) failed:"
        $permanent = !preg_match('/temporary|delayed|will (?:try|retry)|has not yet been delivered/i', $body);
        $code = preg_match('/\b([45])\d\d\b/', $body, $cm) ? $cm[1] : ($permanent ? '5' : '4');
        foreach (preg_split('/\s*,\s*/', $headers['x-failed-recipients']) ?: [] as $addr) {
            $addr = email_report_address($addr);
            if ($addr !== null) {
                $report['recipients'][] = ['email' => $addr, 'hard' => $permanent && $code === '5',
                                           'status' => $code === '5' ? '5.0.0' : '4.0.0',
                                           'diagnostic' => campaigns_cut(email_dsn_first_reason($body), 250),
                                           'action' => $permanent ? 'failed' : 'delayed'];
            }
        }
    } else {
        return null;
    }

    if ($report['recipients'] === [] && $report['kind'] !== 'complaint') {
        return null;
    }
    if ($report['kind'] === 'bounce') {
        $allDelayed = true;
        foreach ($report['recipients'] as $r) {
            if (($r['action'] ?? '') !== 'delayed') {
                $allDelayed = false;
            }
        }
        if ($allDelayed) {
            $report['kind'] = 'delay';
        }
    }

    // The host's hourly cap is not the recipient's fault.
    $everything = $raw;
    if (($cap = email_parse_cap_refusal($everything)) !== null) {
        $report['kind'] = 'cap';
        $report['cap'] = $cap['cap'];
    }

    if (preg_match('/^X-HOD-Ref:\s*([A-Za-z0-9_-]{22})\s*$/mi', str_replace("\r", '', $returned), $m)) {
        $report['ref'] = $m[1];
    }
    if (preg_match('/^Message-ID:\s*(<[^<>\s]+>)\s*$/mi', str_replace("\r", '', $returned), $m)) {
        $report['message_id'] = $m[1];
    }
    if ($report['kind'] === 'complaint' && $report['recipients'] === []
        && preg_match('/^To:\s*(?:.*<)?([^<>\s,]+@[^<>\s,]+)>?\s*$/mi', str_replace("\r", '', $returned), $m)
        && ($addr = email_report_address($m[1])) !== null) {
        $report['recipients'][] = ['email' => $addr, 'hard' => true, 'status' => 'complaint', 'diagnostic' => 'abuse', 'action' => 'complaint'];
    }
    foreach ($report['recipients'] as &$r) {
        unset($r['action']);
    }
    unset($r);
    return $report;
}

/** The first line of an Exim bounce that looks like the server's reason. */
function email_dsn_first_reason(string $body): string
{
    foreach (explode("\n", str_replace("\r", '', $body)) as $line) {
        $line = trim($line);
        if (preg_match('/\b[45]\d\d\b/', $line)) {
            return $line;
        }
    }
    return 'Delivery failed';
}

/**
 * Apply a parsed report: suppress hard bounces and complaints, count soft
 * bounces, withdraw news consent on a complaint, re-queue an email the host
 * dropped for its hourly cap.
 *
 * @return array{outbox_id:?int, suppressed:int, soft:int, requeued:bool, kind:string}
 */
function email_record_bounce(PDO $pdo, array $report): array
{
    $out = ['outbox_id' => null, 'suppressed' => 0, 'soft' => 0, 'requeued' => false, 'kind' => (string) ($report['kind'] ?? '')];
    if (!campaigns_ready($pdo)) {
        return $out;
    }
    $row = null;
    if (!empty($report['ref'])) {
        $row = email_outbox_by_ref($pdo, (string) $report['ref']);
    }
    if ($row === null && !empty($report['message_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM email_outbox WHERE message_id_hash = ? LIMIT 1');
        $stmt->execute([email_message_id_hash((string) $report['message_id'])]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $now = campaigns_now();
    // Anyone can write to system@. A report is only believed when it names an
    // email we actually sent (our random X-HOD-Ref, or its Message-ID), and it
    // only ever acts on the address WE sent that email to.
    if ($row === null || $row['to_email'] === 'erased') {
        $out['kind'] = 'unmatched';
        return $out;
    }
    $out['outbox_id'] = (int) $row['id'];

    if ($report['kind'] === 'cap') {
        // Re-queue our email, but learn nothing from it: only the server's own
        // SMTP answer (in the worker) may change the budget.
        if ($row['status'] === 'sent') {
            $next = campaigns_dt($now->format('Y-m-d H:00:00'))->modify('+1 hour');
            $pdo->prepare("UPDATE email_outbox SET status = 'queued', not_before = ?, sent_at = NULL,
                                  last_error = 'The host dropped it for its hourly limit; sending again next hour.'
                            WHERE id = ? AND status = 'sent'")
                ->execute([campaigns_sql_dt($next), (int) $row['id']]);
            email_event($pdo, (int) $row['id'], 'deferred', null, 'hourly cap');
            $out['requeued'] = true;
        }
        return $out;
    }

    // Act on the address WE sent to (a forwarded final recipient is someone
    // else's business), with the report's verdict.
    $addr = (string) $row['to_email'];
    $r = $report['recipients'][0] ?? ['hard' => $report['kind'] === 'complaint', 'status' => '', 'diagnostic' => ''];
    $why = trim(($r['status'] ?? '') . ' ' . ($r['diagnostic'] ?? ''));
    if (!email_is_church_domain($addr)) {
        if ($report['kind'] === 'complaint') {
            email_suppress($pdo, $addr, 'complaint', 'Marked as spam');
            $out['suppressed']++;
            if ($row['user_id'] !== null && email_consent_state($pdo, (int) $row['user_id'], 'news', 'email') !== 'withdrawn') {
                email_consent_record($pdo, (int) $row['user_id'], 'withdrawn', 'complaint', [
                    'email' => $addr, 'outbox_id' => (int) $row['id'],
                ]);
            }
        } elseif ($report['kind'] === 'delay') {
            // Still trying; nothing to do but note it.
        } elseif (!empty($r['hard'])) {
            email_suppress($pdo, $addr, 'hard_bounce', $why);
            $out['suppressed']++;
        } else {
            email_note_soft_bounce($pdo, $addr, $why);
            $out['soft']++;
        }
    }

    $kind = $report['kind'] === 'complaint' ? 'complaint' : ($report['kind'] === 'delay' ? 'deferred' : 'bounce');
    email_event($pdo, (int) $row['id'], $kind, null, $why !== '' ? $why : null);
    if ($kind === 'bounce') {
        $pdo->prepare('UPDATE email_outbox SET bounced_at = ? WHERE id = ? AND bounced_at IS NULL')
            ->execute([campaigns_sql_dt($now), (int) $row['id']]);
    }
    return $out;
}
