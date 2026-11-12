<?php
// /includes/campaigns/profile.php
//
// The Profile "Your email" card's engine: what the card shows
// (email_profile_view) and the person's own church-news switch
// (email_profile_set_news). The card: profile_card.php; its actions:
// api/profile_api.php. Guide: docs/EMAIL.md.

/** The wording recorded when someone switches church news on in Profile (the (i) beside the switch). */
const EMAIL_PROFILE_NEWS_DETAILS = 'Church news, service reminders and messages from the pastoral team. Account emails, like password resets, always come.';
const EMAIL_PROFILE_NEWS_TEXT = 'Church news switched on in Profile. ' . EMAIL_PROFILE_NEWS_DETAILS;

/**
 * What the card shows.
 *
 * @return array{ready:bool, confirmed:?string, confirmed_at:?string, pending:?string,
 *               pending_sent_at:?string, suggest:?string, member:bool, news:bool, suppressed:bool}
 *   suggest     an address on file to offer in the form (users.email, else users.real_email)
 *   news        church news on: a "yes" on record, or a member who has not said stop
 *   suppressed  mail to the confirmed address bounced or was reported
 */
function email_profile_view(PDO $pdo, int $userId): array
{
    $view = ['ready' => false, 'confirmed' => null, 'confirmed_at' => null, 'pending' => null,
             'pending_sent_at' => null, 'suggest' => null, 'member' => false, 'news' => false, 'suppressed' => false];
    if ($userId <= 0 || !campaigns_ready($pdo)) {
        return $view;
    }
    $s = email_address_state($pdo, $userId);
    $view['ready'] = true;
    $view['confirmed'] = $s['confirmed'];
    $view['confirmed_at'] = $s['confirmed_at'];
    $view['pending'] = $s['pending'];
    $view['pending_sent_at'] = $s['pending_sent_at'];
    $view['suggest'] = $s['on_file'] ?? email_profile_real_email($pdo, $userId, $s);
    $view['member'] = email_is_member($pdo, $userId);
    $consent = email_consent_state($pdo, $userId);
    $view['news'] = $consent === 'granted' || ($consent !== 'withdrawn' && $view['member']);
    $view['suppressed'] = $s['confirmed'] !== null && email_is_suppressed($pdo, $s['confirmed']);
    return $view;
}

/** users.real_email (the old Profile field) when it is a personal address not already in play. */
function email_profile_real_email(PDO $pdo, int $userId, array $state): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT real_email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $real = email_normalize((string) ($stmt->fetchColumn() ?: ''));
    } catch (PDOException $e) {
        return null; // no such column (tests, older schemas)
    }
    if (!email_looks_personal($real) || $real === $state['confirmed'] || $real === $state['pending']) {
        return null;
    }
    return $real;
}

/**
 * The person's own church-news switch. Appends to the consent log (method
 * 'profile'); a "yes" records the wording beside the switch.
 *
 * @return array{ok:bool, news?:bool, already?:bool, code?:string, message?:string}
 */
function email_profile_set_news(PDO $pdo, int $userId, bool $on, ?string $ip = null, ?string $userAgent = null): array
{
    if (!campaigns_ready($pdo)) {
        return ['ok' => false, 'code' => 'FEATURE_NOT_READY', 'message' => 'Email is being set up. Try again later.'];
    }
    $view = email_profile_view($pdo, $userId);
    if ($view['news'] === $on) {
        return ['ok' => true, 'news' => $on, 'already' => true];
    }
    email_consent_record($pdo, $userId, $on ? 'granted' : 'withdrawn', 'profile', [
        'email'      => $view['confirmed'] ?? $view['pending'],
        'text'       => $on ? EMAIL_PROFILE_NEWS_TEXT : null,
        'ip'         => $ip,
        'user_agent' => $userAgent,
    ]);
    return ['ok' => true, 'news' => $on];
}
