<?php
// /includes/campaigns/tracking.php
//
// What happens after an email has left: unsubscribe (one click, or the page
// with one button), clicks and opens. The public URLs (/email/unsubscribe.php,
// /email/c.php, /email/o.php) are thin wrappers over these functions.
//
// Every one of them is keyed by the outbox row's `ref`, a random 22-character
// token that identifies one email to one person; nothing else from the request
// is trusted. The click redirect only ever serves a target recorded for that
// email when it was rendered, so it can never be used as an open redirect.
// No IP address or user agent is stored for clicks and opens.

/** The outbox row for a ref, or null. */
function email_outbox_by_ref(PDO $pdo, string $ref): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{22}$/', $ref) || !campaigns_table_exists($pdo, 'email_outbox')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM email_outbox WHERE ref = ?');
    $stmt->execute([$ref]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function email_event(PDO $pdo, int $outboxId, string $kind, ?int $linkIndex = null, ?string $detail = null): void
{
    if (!in_array($kind, ['open', 'click', 'unsubscribe', 'resubscribe', 'bounce', 'complaint', 'deferred'], true)) {
        throw new InvalidArgumentException('Unknown email event: ' . $kind);
    }
    $pdo->prepare('INSERT INTO email_events (outbox_id, kind, link_index, detail, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$outboxId, $kind, $linkIndex, $detail !== null ? campaigns_cut($detail, 255) : null, campaigns_sql_dt(campaigns_now())]);
}

/**
 * Unsubscribe the person an email went to from church news by email.
 * $method: one_click (RFC 8058 POST from the mail app) | unsubscribe_link (the page's button).
 *
 * @return array{ok:bool, code?:string, already?:bool, email?:string}
 */
function email_unsubscribe(PDO $pdo, string $ref, string $method = 'one_click', ?string $ip = null, ?string $userAgent = null): array
{
    $row = email_outbox_by_ref($pdo, $ref);
    if ($row === null) {
        return ['ok' => false, 'code' => 'UNKNOWN'];
    }
    $email = (string) $row['to_email'];
    if ($row['user_id'] === null) {
        // Not tied to a person (should not happen for news): stop the address.
        if ($email !== 'erased' && email_is_valid_syntax($email)) {
            email_suppress($pdo, $email, 'unsubscribed', 'Unsubscribe link');
        }
        email_event($pdo, (int) $row['id'], 'unsubscribe');
        return ['ok' => true, 'email' => $email];
    }
    $userId = (int) $row['user_id'];
    if (email_consent_state($pdo, $userId, 'news', 'email') === 'withdrawn') {
        return ['ok' => true, 'already' => true, 'email' => $email];
    }
    email_consent_record($pdo, $userId, 'withdrawn', $method === 'unsubscribe_link' ? 'unsubscribe_link' : 'one_click', [
        'email'      => $email,
        'outbox_id'  => (int) $row['id'],
        'ip'         => $ip,
        'user_agent' => $userAgent,
    ]);
    email_event($pdo, (int) $row['id'], 'unsubscribe');
    // Anything else already queued for them stops at the worker's last check.
    return ['ok' => true, 'email' => $email];
}

/** "Undo" on the unsubscribe page: they choose to stay. */
function email_resubscribe(PDO $pdo, string $ref, ?string $ip = null, ?string $userAgent = null): array
{
    $row = email_outbox_by_ref($pdo, $ref);
    if ($row === null || $row['user_id'] === null) {
        return ['ok' => false, 'code' => 'UNKNOWN'];
    }
    $userId = (int) $row['user_id'];
    if (email_consent_state($pdo, $userId, 'news', 'email') === 'granted') {
        return ['ok' => true, 'already' => true];
    }
    email_consent_record($pdo, $userId, 'granted', 'unsubscribe_link', [
        'email'      => (string) $row['to_email'],
        'text'       => 'Stay subscribed to church news from ' . CAMPAIGNS_CHURCH_NAME . ' (undo on the unsubscribe page).',
        'outbox_id'  => (int) $row['id'],
        'ip'         => $ip,
        'user_agent' => $userAgent,
    ]);
    email_event($pdo, (int) $row['id'], 'resubscribe');
    return ['ok' => true];
}

/**
 * A click on link $index of an email: record it and return where to send the
 * person, or null (unknown email or link: the page then goes to the home page).
 */
function email_track_click(PDO $pdo, string $ref, int $index): ?string
{
    $row = email_outbox_by_ref($pdo, $ref);
    if ($row === null || $index < 0) {
        return null;
    }
    $links = json_decode((string) ($row['links_json'] ?? ''), true);
    $target = is_array($links) ? ($links[$index] ?? null) : null;
    if (!is_string($target) || !email_doc_url_ok($target) || !str_starts_with($target, 'https://')) {
        return null;
    }
    email_event($pdo, (int) $row['id'], 'click', $index);
    if ($row['clicked_at'] === null) {
        $pdo->prepare('UPDATE email_outbox SET clicked_at = ? WHERE id = ? AND clicked_at IS NULL')
            ->execute([campaigns_sql_dt(campaigns_now()), (int) $row['id']]);
    }
    return $target;
}

/** The open pixel was fetched. Counted once per email (opens are approximate). */
function email_track_open(PDO $pdo, string $ref): void
{
    $row = email_outbox_by_ref($pdo, $ref);
    if ($row === null || $row['opened_at'] !== null) {
        return;
    }
    $upd = $pdo->prepare('UPDATE email_outbox SET opened_at = ? WHERE id = ? AND opened_at IS NULL');
    $upd->execute([campaigns_sql_dt(campaigns_now()), (int) $row['id']]);
    if ($upd->rowCount() === 1) {
        email_event($pdo, (int) $row['id'], 'open');
    }
}

/** The 1×1 transparent GIF the open URL answers with. */
function email_pixel_gif(): string
{
    return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}
