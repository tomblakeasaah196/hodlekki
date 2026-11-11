<?php
// /includes/campaigns/suppression.php
//
// Addresses we must stop sending to, whoever they belong to: a hard bounce (the
// mailbox does not exist), a spam complaint, three soft bounces in a row (full
// mailbox, temporarily refused: suppressed for 7 days), or a manual block.
// Bounces also count against the host's "max defers and failures per hour", so
// a clean list protects every other email the church sends.
//
// Checked when choosing a channel AND again just before each send, so an
// address that bounces while a campaign is queued stops receiving it.

const EMAIL_SUPPRESSION_REASONS = ['hard_bounce', 'soft_bounce', 'complaint', 'invalid', 'manual', 'unsubscribed'];
const EMAIL_SOFT_BOUNCE_LIMIT   = 3;
const EMAIL_SOFT_BOUNCE_DAYS    = 7;

/** Is this address suppressed right now? */
function email_is_suppressed(PDO $pdo, string $email): bool
{
    $email = email_normalize($email);
    if ($email === '' || !campaigns_table_exists($pdo, 'email_suppressions')) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT reason, until_at FROM email_suppressions
                            WHERE email = ? AND released_at IS NULL");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if ($row['reason'] === 'soft_bounce') {
        return $row['until_at'] !== null && campaigns_dt((string) $row['until_at']) > campaigns_now();
    }
    return true;
}

/** Suppress an address for good (until an admin releases it). */
function email_suppress(PDO $pdo, string $email, string $reason, ?string $detail = null): void
{
    $email = email_normalize($email);
    if ($email === '' || !in_array($reason, EMAIL_SUPPRESSION_REASONS, true) || $reason === 'soft_bounce') {
        throw new InvalidArgumentException('email_suppress: bad address or reason');
    }
    $now = campaigns_sql_dt(campaigns_now());
    $detail = $detail !== null ? campaigns_cut($detail, 255) : null;
    $upd = $pdo->prepare("UPDATE email_suppressions
                             SET reason = ?, detail = ?, until_at = NULL, updated_at = ?, released_at = NULL, released_by = NULL
                           WHERE email = ?");
    $upd->execute([$reason, $detail, $now, $email]);
    if ($upd->rowCount() > 0) {
        return;
    }
    try {
        $pdo->prepare('INSERT INTO email_suppressions (email, reason, detail, soft_count, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)')
            ->execute([$email, $reason, $detail, $now, $now]);
    } catch (PDOException $e) {
        if (!campaigns_is_duplicate_key($e)) {
            throw $e;
        }
        $upd->execute([$reason, $detail, $now, $email]);
    }
}

/**
 * Count a soft bounce. The third in a row suppresses the address for 7 days.
 * A permanent suppression is never weakened by a soft bounce.
 *
 * @return bool whether the address is now suppressed
 */
function email_note_soft_bounce(PDO $pdo, string $email, ?string $detail = null): bool
{
    $email = email_normalize($email);
    if ($email === '') {
        return false;
    }
    $now = campaigns_now();
    $nowSql = campaigns_sql_dt($now);
    $stmt = $pdo->prepare('SELECT reason, soft_count, released_at FROM email_suppressions WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['released_at'] === null && $row['reason'] !== 'soft_bounce') {
        return true; // already suppressed for a stronger reason
    }
    $count = $row ? ((int) $row['soft_count']) + 1 : 1;
    $until = $count >= EMAIL_SOFT_BOUNCE_LIMIT
        ? campaigns_sql_dt($now->modify('+' . EMAIL_SOFT_BOUNCE_DAYS . ' days'))
        : null;
    $detail = $detail !== null ? campaigns_cut($detail, 255) : null;
    if ($row) {
        $pdo->prepare("UPDATE email_suppressions
                          SET reason = 'soft_bounce', soft_count = ?, until_at = ?, detail = ?, updated_at = ?,
                              released_at = NULL, released_by = NULL
                        WHERE email = ?")
            ->execute([$count, $until, $detail, $nowSql, $email]);
    } else {
        try {
            $pdo->prepare("INSERT INTO email_suppressions (email, reason, detail, soft_count, until_at, created_at, updated_at)
                           VALUES (?, 'soft_bounce', ?, ?, ?, ?, ?)")
                ->execute([$email, $detail, $count, $until, $nowSql, $nowSql]);
        } catch (PDOException $e) {
            if (!campaigns_is_duplicate_key($e)) {
                throw $e;
            }
            return email_note_soft_bounce($pdo, $email, $detail);
        }
    }
    return $until !== null;
}

/** A successful delivery ends a run of soft bounces. */
function email_note_delivered(PDO $pdo, string $email): void
{
    $email = email_normalize($email);
    if ($email === '' || !campaigns_table_exists($pdo, 'email_suppressions')) {
        return;
    }
    $pdo->prepare("UPDATE email_suppressions SET soft_count = 0, updated_at = ?
                    WHERE email = ? AND reason = 'soft_bounce' AND soft_count > 0
                      AND (until_at IS NULL OR until_at <= ?)")
        ->execute([campaigns_sql_dt(campaigns_now()), $email, campaigns_sql_dt(campaigns_now())]);
}

/** An admin lifts a suppression (with a note). */
function email_suppression_release(PDO $pdo, string $email, int $byUserId, ?string $note = null): bool
{
    $stmt = $pdo->prepare('UPDATE email_suppressions SET released_at = ?, released_by = ?, soft_count = 0, detail = COALESCE(?, detail)
                            WHERE email = ? AND released_at IS NULL');
    $stmt->execute([campaigns_sql_dt(campaigns_now()), $byUserId, $note !== null ? campaigns_cut($note, 255) : null, email_normalize($email)]);
    return $stmt->rowCount() > 0;
}
