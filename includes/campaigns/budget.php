<?php
// /includes/campaigns/budget.php
//
// The domain's hourly sending budget, shared by EVERYTHING that sends mail as
// @hodlekki.org: system@ (confirm links, resets, alerts, automations,
// campaigns) and people's own sends from ERP mailboxes. The host (Exim on the
// shared cPanel server) caps email per domain per hour and discards anything
// over it, so one budget, one ledger.
//
//   cap      email_settings.hourly_cap (the host's number), or what the host
//            told us when it refused, whichever is lower
//   usable   floor(cap × safety)                       — never plan to the edge
//   bulk     floor(usable × bulk_share)                — automations + campaigns
//
// Bulk lanes may only use their share, so at a cap of 100 a campaign can never
// take more than 54 an hour and at least 36 are always left for password
// resets, alerts and people's replies.
//
// email_budget_take() reserves with ONE conditional UPDATE on the hour's row.
// That is atomic on MySQL, MariaDB and SQLite: two workers (or a worker and the
// mailbox) can never both take the last slot.

const EMAIL_LANES = [
    // lane          => [priority (lower first), bulk?]
    'transactional' => [10, false],
    'alert'         => [20, false],
    'mailbox'       => [25, false],
    'test'          => [30, false],
    'automation'    => [50, true],
    'campaign'      => [70, true],
];

function email_lane_priority(string $lane): int
{
    return EMAIL_LANES[$lane][0] ?? 99;
}

function email_lane_is_bulk(string $lane): bool
{
    return EMAIL_LANES[$lane][1] ?? true;
}

function email_hour_key(?DateTimeImmutable $at = null): string
{
    return ($at ?? campaigns_now())->setTimezone(new DateTimeZone(CAMPAIGNS_TZ))->format('Y-m-d H');
}

const EMAIL_LEARNED_CAP_MIN  = 10;
const EMAIL_LEARNED_CAP_DAYS = 7;

/** The cap the host named in a refusal, while it is still fresh (7 days). */
function email_learned_cap(PDO $pdo): ?int
{
    $cap = (int) email_setting($pdo, 'learned_cap');
    $at = email_setting($pdo, 'learned_cap_at');
    if ($cap < EMAIL_LEARNED_CAP_MIN || $at === ''
        || campaigns_dt($at) < campaigns_now()->modify('-' . EMAIL_LEARNED_CAP_DAYS . ' days')) {
        return null;
    }
    return $cap;
}

/** @return array{cap:int, usable:int, bulk:int} */
function email_budget_limits(PDO $pdo): array
{
    $cap = max(1, (int) email_setting($pdo, 'hourly_cap'));
    $learned = email_learned_cap($pdo);
    if ($learned !== null && $learned < $cap) {
        $cap = $learned;
    }
    $safety = min(1.0, max(0.1, (float) email_setting($pdo, 'safety')));
    $share = min(1.0, max(0.0, (float) email_setting($pdo, 'bulk_share')));
    $usable = max(1, (int) floor($cap * $safety));
    return ['cap' => $cap, 'usable' => $usable, 'bulk' => (int) floor($usable * $share)];
}

function email_budget_bucket(PDO $pdo, string $hourKey): void
{
    try {
        $pdo->prepare('INSERT INTO email_rate_buckets (hour_key, sent_total, sent_bulk) VALUES (?, 0, 0)')
            ->execute([$hourKey]);
    } catch (PDOException $e) {
        if (!campaigns_is_duplicate_key($e)) {
            throw $e;
        }
    }
}

/**
 * Reserve $n sends in this hour for $lane. False = not now (the hour is full
 * for this lane, or the host already refused this hour).
 */
function email_budget_take(PDO $pdo, string $lane, int $n = 1, ?DateTimeImmutable $at = null): bool
{
    if ($n <= 0) {
        return true;
    }
    if (!isset(EMAIL_LANES[$lane])) {
        throw new InvalidArgumentException('Unknown email lane: ' . $lane);
    }
    if (!campaigns_table_exists($pdo, 'email_rate_buckets')) {
        return true; // before the migration nothing is metered (and nothing bulk is sent)
    }
    $hour = email_hour_key($at);
    email_budget_bucket($pdo, $hour);
    $limits = email_budget_limits($pdo);
    // Numbers are bound AS numbers: SQLite compares an expression with a
    // text-bound value as "number < text" (always true), which would quietly
    // let every send through.
    if (email_lane_is_bulk($lane)) {
        $stmt = $pdo->prepare('UPDATE email_rate_buckets
                                  SET sent_total = sent_total + ?, sent_bulk = sent_bulk + ?
                                WHERE hour_key = ? AND refused_at IS NULL
                                  AND sent_total + ? <= ? AND sent_bulk + ? <= ?');
        $values = [$n, $n, $hour, $n, $limits['usable'], $n, $limits['bulk']];
    } else {
        $stmt = $pdo->prepare('UPDATE email_rate_buckets
                                  SET sent_total = sent_total + ?
                                WHERE hour_key = ? AND refused_at IS NULL
                                  AND sent_total + ? <= ?');
        $values = [$n, $hour, $n, $limits['usable']];
    }
    foreach ($values as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->rowCount() === 1;
}

/** Hand back a reservation that was not used (the message was suppressed, say). */
function email_budget_give_back(PDO $pdo, string $lane, int $n = 1, ?DateTimeImmutable $at = null): void
{
    if ($n <= 0 || !campaigns_table_exists($pdo, 'email_rate_buckets')) {
        return;
    }
    $bulk = email_lane_is_bulk($lane) ? $n : 0;
    $pdo->prepare('UPDATE email_rate_buckets
                      SET sent_total = CASE WHEN sent_total >= ? THEN sent_total - ? ELSE 0 END,
                          sent_bulk  = CASE WHEN sent_bulk >= ? THEN sent_bulk - ? ELSE 0 END
                    WHERE hour_key = ?')
        ->execute([$n, $n, $bulk, $bulk, email_hour_key($at)]);
}

/**
 * The host refused for its hourly cap: stop for the rest of this hour and
 * remember the cap it named, so the next hours plan below it.
 */
function email_budget_refused(PDO $pdo, ?int $hostCap = null, ?DateTimeImmutable $at = null): void
{
    $at ??= campaigns_now();
    $hour = email_hour_key($at);
    email_budget_bucket($pdo, $hour);
    $pdo->prepare('UPDATE email_rate_buckets SET refused_at = ?, learned_cap = ? WHERE hour_key = ?')
        ->execute([campaigns_sql_dt($at), $hostCap, $hour]);
    // Only the server's own SMTP answer reaches here. A figure under 10 is not
    // believable for a church domain; anything learnt lapses after 7 days, so a
    // raised host limit is picked up again.
    if ($hostCap !== null && $hostCap >= EMAIL_LEARNED_CAP_MIN) {
        $known = email_learned_cap($pdo);
        if ($known === null || $hostCap < $known) {
            email_setting_set($pdo, 'learned_cap', (string) $hostCap);
        }
        email_setting_set($pdo, 'learned_cap_at', campaigns_sql_dt($at));
    }
    error_log('[email] the host refused for its hourly cap' . ($hostCap ? " ({$hostCap}/hour)" : '') . '; sending resumes next hour');
}

/**
 * Is this send error the host's hourly cap? Exim on cPanel says:
 *   "Domain hodlekki.org has exceeded the max emails per hour (100/100 (100%)) allowed."
 *
 * @return array{cap:?int}|null
 */
function email_parse_cap_refusal(string $error): ?array
{
    // "max defers and failures per hour" is the host's limit on bounces: also
    // not the recipient's fault, also "stop for this hour", but its number is
    // not a sending cap, so nothing is learnt from it.
    if (!preg_match('/exceeded the max (emails|messages|defers and failures) per hour/i', $error, $kind)) {
        return null;
    }
    $cap = null;
    if (strtolower($kind[1]) !== 'defers and failures' && preg_match('/\((\d+)\s*\/\s*(\d+)/', $error, $m)) {
        $cap = (int) $m[2] > 0 ? (int) $m[2] : null;
    }
    return ['cap' => $cap];
}

/**
 * For the status line and the mailbox's "your message leaves in N minutes".
 *
 * @return array{hour:string, cap:int, usable:int, bulk_limit:int, sent_total:int, sent_bulk:int,
 *               left_total:int, left_bulk:int, refused:bool, minutes_to_next_hour:int}
 */
function email_budget_status(PDO $pdo, ?DateTimeImmutable $at = null): array
{
    $at ??= campaigns_now();
    $hour = email_hour_key($at);
    $limits = email_budget_limits($pdo);
    $row = ['sent_total' => 0, 'sent_bulk' => 0, 'refused_at' => null];
    if (campaigns_table_exists($pdo, 'email_rate_buckets')) {
        $stmt = $pdo->prepare('SELECT sent_total, sent_bulk, refused_at FROM email_rate_buckets WHERE hour_key = ?');
        $stmt->execute([$hour]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
    }
    $refused = $row['refused_at'] !== null;
    $total = (int) $row['sent_total'];
    $bulk = (int) $row['sent_bulk'];
    return [
        'hour'                 => $hour,
        'cap'                  => $limits['cap'],
        'usable'               => $limits['usable'],
        'bulk_limit'           => $limits['bulk'],
        'sent_total'           => $total,
        'sent_bulk'            => $bulk,
        'left_total'           => $refused ? 0 : max(0, $limits['usable'] - $total),
        'left_bulk'            => $refused ? 0 : max(0, min($limits['bulk'] - $bulk, $limits['usable'] - $total)),
        'refused'              => $refused,
        'minutes_to_next_hour' => 60 - (int) $at->format('i'),
    ];
}
