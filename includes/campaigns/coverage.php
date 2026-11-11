<?php
// /includes/campaigns/coverage.php
//
// How many people the church can reach by email, and how: the number the
// owner watches while email replaces SMS. Two groups:
//   first_timers  the Embrace pipeline (embrace_pipeline_sql())
//   members       Member, Worker, Pastor
// For each group, where their address stands:
//   total, confirmed (a confirmed personal email), pending (a link sent, not
//   clicked), on_file (users.email is a real address nobody confirmed yet)
// and how an automation reaches them (exactly one of these per person, in the
// order automation_choose_channel() decides):
//   news (email: confirmed + said yes to church news), unsubscribed (skipped),
//   sms_only (no email route but a mobile number), unreachable (neither).

const COVERAGE_MEMBER_STATUSES = ['Member', 'Worker', 'Pastor'];

/** @return array{first_timers:array, members:array, ready:bool} */
function email_coverage(PDO $pdo): array
{
    $ready = campaigns_ready($pdo);
    $groups = [
        'first_timers' => 'SELECT u.id, u.email, u.phone, u.spiritual_status FROM users u WHERE ' . embrace_pipeline_sql($pdo, 'u'),
        'members'      => "SELECT u.id, u.email, u.phone, u.spiritual_status FROM users u WHERE u.spiritual_status IN ('"
                          . implode("', '", COVERAGE_MEMBER_STATUSES) . "')",
    ];
    $confirmed = $pending = $news = [];
    if ($ready) {
        foreach ($pdo->query("SELECT user_id, status FROM email_addresses WHERE status IN ('confirmed', 'pending')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] === 'confirmed') {
                $confirmed[(int) $row['user_id']] = true;
            } else {
                $pending[(int) $row['user_id']] = true;
            }
        }
        $latest = $pdo->query("SELECT c.user_id, c.action FROM email_consents c
                                WHERE c.purpose = 'news' AND c.channel = 'email'
                                  AND c.id = (SELECT MAX(c2.id) FROM email_consents c2
                                               WHERE c2.user_id = c.user_id AND c2.purpose = 'news' AND c2.channel = 'email')")
                        ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($latest as $row) {
            $news[(int) $row['user_id']] = $row['action'] === 'granted';
        }
    }
    $phoneOk = static function (?string $phone): bool {
        if (function_exists('sms_normalize_phone')) {
            return sms_normalize_phone((string) $phone) !== null;
        }
        return strlen(preg_replace('/\D/', '', (string) $phone) ?? '') >= 10;
    };

    $out = ['ready' => $ready];
    foreach ($groups as $name => $sql) {
        $c = ['total' => 0, 'confirmed' => 0, 'news' => 0, 'pending' => 0, 'on_file' => 0,
              'unsubscribed' => 0, 'sms_only' => 0, 'unreachable' => 0];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $uid = (int) $p['id'];
            $c['total']++;
            if (isset($confirmed[$uid])) {
                $c['confirmed']++;
            } elseif (isset($pending[$uid])) {
                $c['pending']++;
            } elseif (email_looks_personal($p['email'] ?? null)) {
                $c['on_file']++;
            }
            // How automations reach them (same order as automation_choose_channel()).
            if (($news[$uid] ?? null) === false) {
                $c['unsubscribed']++;
            } elseif (isset($confirmed[$uid]) && (($news[$uid] ?? false) === true
                      || (!isset($news[$uid]) && in_array((string) ($p['spiritual_status'] ?? ''), EMAIL_MEMBER_STATUSES, true)))) {
                $c['news']++;
            } elseif ($phoneOk($p['phone'] ?? null)) {
                $c['sms_only']++;
            } else {
                $c['unreachable']++;
            }
        }
        $out[$name] = $c;
    }
    return $out;
}
