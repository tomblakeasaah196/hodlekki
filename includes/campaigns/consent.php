<?php
// /includes/campaigns/consent.php
//
// NDPA consent for CHURCH NEWS (automations and campaigns), per channel.
// Account and security mail (confirm links, password resets, security notices)
// needs no consent and writes nothing here.
//
// The log is append-only: who, when, how (method), the exact wording they saw
// (stored once in email_consent_texts by its hash), who recorded an in-person
// answer, which email's link was used, and the IP/user agent of a form or link.
// The current answer is the latest row for a person, purpose and channel.
//
// "Withdrawn" is not the same as "never asked": someone who unsubscribed is
// not texted instead (docs/EMAIL.md, owner decision pending as question 4).

const EMAIL_CONSENT_NEWS_TEXT = 'Yes, Household of David Lekki may email me church news, service reminders and messages from the pastoral team. I can unsubscribe at any time with the link in every email.';

// Visitors (Connect card, Embrace first-timer entry): a box that starts TICKED
// (owner's decision, 11 Nov 2026, on record after the NDPA point was raised).
// It is recorded honestly as method 'prechecked' — a pre-ticked box left
// ticked — never as a typed or spoken yes. The recorded wording is exactly the
// label and the details shown behind (i).
const EMAIL_CONSENT_VISITOR_LABEL   = 'Stay in touch with us';
const EMAIL_CONSENT_VISITOR_DETAILS = 'Church news, service reminders and messages from the pastoral team, by email. One tap to stop, in every email.';
const EMAIL_CONSENT_VISITOR_TEXT    = EMAIL_CONSENT_VISITOR_LABEL . ' — ' . EMAIL_CONSENT_VISITOR_DETAILS . ' (box ticked in advance, left ticked)';

const EMAIL_CONSENT_PURPOSES = ['news'];
const EMAIL_CONSENT_CHANNELS = ['email', 'sms'];
const EMAIL_CONSENT_ACTIONS  = ['granted', 'withdrawn'];
const EMAIL_CONSENT_METHODS  = [
    'connect_form', 'embrace_entry', 'prechecked', 'signin_prompt', 'profile',
    'unsubscribe_link', 'one_click', 'complaint', 'staff', 'import',
];

/** The id of this exact wording, stored once. */
function email_consent_text_id(PDO $pdo, string $purpose, string $body): int
{
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('Consent wording is required.');
    }
    $sha = hash('sha256', $purpose . "\n" . $body);
    $find = $pdo->prepare('SELECT id FROM email_consent_texts WHERE body_sha = ?');
    $find->execute([$sha]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    try {
        $pdo->prepare('INSERT INTO email_consent_texts (purpose, body, body_sha, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$purpose, $body, $sha, campaigns_sql_dt(campaigns_now())]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (!campaigns_is_duplicate_key($e)) {
            throw $e;
        }
        $find->execute([$sha]);
        return (int) $find->fetchColumn();
    }
}

/**
 * Append one consent answer.
 *
 * $opts: purpose ('news'), channel ('email'|'sms'), email, text (the wording
 * shown — required when granting), recorded_by, outbox_id, ip, user_agent.
 */
function email_consent_record(PDO $pdo, int $userId, string $action, string $method, array $opts = []): int
{
    $purpose = (string) ($opts['purpose'] ?? 'news');
    $channel = (string) ($opts['channel'] ?? 'email');
    if (!in_array($purpose, EMAIL_CONSENT_PURPOSES, true) || !in_array($channel, EMAIL_CONSENT_CHANNELS, true)
        || !in_array($action, EMAIL_CONSENT_ACTIONS, true) || !in_array($method, EMAIL_CONSENT_METHODS, true)) {
        throw new InvalidArgumentException("Bad consent record: {$purpose}/{$channel}/{$action}/{$method}");
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('Consent belongs to a person.');
    }
    if (!campaigns_ready($pdo)) {
        return 0; // before the migration there is nowhere to record it, and nothing sends
    }
    $textId = null;
    if (isset($opts['text']) && trim((string) $opts['text']) !== '') {
        $textId = email_consent_text_id($pdo, $purpose, (string) $opts['text']);
    } elseif ($action === 'granted' && !in_array($method, ['unsubscribe_link', 'staff', 'import'], true)) {
        // A "yes" is only evidence together with the sentence the person saw.
        throw new InvalidArgumentException('Granting consent needs the wording that was shown.');
    }
    $email = isset($opts['email']) ? email_normalize((string) $opts['email']) : null;
    $pdo->prepare('INSERT INTO email_consents
                       (user_id, email, purpose, channel, action, method, text_id, recorded_by, outbox_id, ip, user_agent, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $userId,
            $email !== '' ? $email : null,
            $purpose,
            $channel,
            $action,
            $method,
            $textId,
            isset($opts['recorded_by']) ? (int) $opts['recorded_by'] : null,
            isset($opts['outbox_id']) ? (int) $opts['outbox_id'] : null,
            isset($opts['ip']) ? campaigns_cut((string) $opts['ip'], 45) : null,
            isset($opts['user_agent']) ? campaigns_cut((string) $opts['user_agent'], 255) : null,
            campaigns_sql_dt(campaigns_now()),
        ]);
    return (int) $pdo->lastInsertId();
}

/** The latest consent row for a person, purpose and channel, or null. */
function email_consent_current(PDO $pdo, int $userId, string $purpose = 'news', string $channel = 'email'): ?array
{
    if (!campaigns_table_exists($pdo, 'email_consents')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM email_consents WHERE user_id = ? AND purpose = ? AND channel = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId, $purpose, $channel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** 'granted' | 'withdrawn' | 'none' */
function email_consent_state(PDO $pdo, int $userId, string $purpose = 'news', string $channel = 'email'): string
{
    $row = email_consent_current($pdo, $userId, $purpose, $channel);
    return $row === null ? 'none' : (string) $row['action'];
}

/**
 * May we send this person church news by email right now?
 *
 * @return array{ok:bool, email:?string, reason:string}
 *   reason: ok | no_confirmed_email | no_consent | withdrawn | suppressed | not_ready
 */
function email_may_send_news(PDO $pdo, int $userId): array
{
    if (!campaigns_ready($pdo)) {
        return ['ok' => false, 'email' => null, 'reason' => 'not_ready'];
    }
    $email = member_confirmed_email($pdo, $userId);
    if ($email === null) {
        return ['ok' => false, 'email' => null, 'reason' => 'no_confirmed_email'];
    }
    $state = email_consent_state($pdo, $userId, 'news', 'email');
    if ($state === 'withdrawn') {
        return ['ok' => false, 'email' => $email, 'reason' => 'withdrawn'];
    }
    if ($state !== 'granted') {
        return ['ok' => false, 'email' => $email, 'reason' => 'no_consent'];
    }
    if (email_is_suppressed($pdo, $email)) {
        return ['ok' => false, 'email' => $email, 'reason' => 'suppressed'];
    }
    return ['ok' => true, 'email' => $email, 'reason' => 'ok'];
}

/**
 * Erase a person's email data (a data-deletion request; Congregation's
 * delete). Addresses and consent rows are deleted, outbox bodies cleared and
 * addresses blanked; the counts are returned for the audit log.
 *
 * @return array{addresses:int, consents:int, outbox:int}
 */
function email_forget_user(PDO $pdo, int $userId): array
{
    $out = ['addresses' => 0, 'consents' => 0, 'outbox' => 0];
    if ($userId <= 0 || !campaigns_ready($pdo)) {
        return $out;
    }
    $stmt = $pdo->prepare('DELETE FROM email_addresses WHERE user_id = ?');
    $stmt->execute([$userId]);
    $out['addresses'] = $stmt->rowCount();
    $stmt = $pdo->prepare('DELETE FROM email_consents WHERE user_id = ?');
    $stmt->execute([$userId]);
    $out['consents'] = $stmt->rowCount();
    $stmt = $pdo->prepare("UPDATE email_outbox
                              SET to_email = 'erased', to_name = NULL, html = NULL, text_body = NULL,
                                  links_json = NULL, user_id = NULL, pruned_at = ?,
                                  status = CASE WHEN status = 'queued' THEN 'cancelled' ELSE status END
                            WHERE user_id = ?");
    $stmt->execute([campaigns_sql_dt(campaigns_now()), $userId]);
    $out['outbox'] = $stmt->rowCount();
    $pdo->prepare('DELETE FROM automation_person_day WHERE user_id = ?')->execute([$userId]);
    return $out;
}
