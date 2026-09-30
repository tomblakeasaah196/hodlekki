<?php
// /api/security_api.php
//
// Admin Security Centre backend (modules/security/index.php).
//
// Clearance: Super_Admin or Resident_Pastor, checked against the live roles
// table rather than $_SESSION['active_role'] (which a user can switch
// themselves from the profile screen).
//
// Guard rails enforced by includes/security_helpers.php:
//   - no security action against your own account from this screen
//   - a Resident_Pastor cannot act on a Super_Admin
//   - the last active Super_Admin cannot be suspended or revoked
//
// Every state-changing action writes a row to security_audit_log.

require_once '../includes/db.php';
header('Content-Type: application/json');

// ---------------------------------------------------------------- auth gate

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$admin_id = (int) $_SESSION['user_id'];

if (!security_is_admin($pdo, $admin_id)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'CLASSIFIED: The Security Centre is restricted to Super Admins and the Resident Pastor.',
    ]);
    exit;
}

if (!security_schema_ready($pdo)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Security tables are not installed yet. Run `php db/migrate.php` on the server '
                   . '(or trigger a deploy) to apply 20261005090000_security_core.sql.',
    ]);
    exit;
}

$action        = $_POST['action'] ?? $_GET['action'] ?? '';
$isSuperAdmin  = security_is_super_admin($pdo, $admin_id);
$writeActions  = [
    'set_status', 'reset_password', 'change_email', 'force_logout', 'revoke_session',
    'unlock_account', 'set_force_change', 'restore_roles',
];

// State-changing actions must be POSTed.
if (in_array($action, $writeActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'This action requires a POST request.']);
    exit;
}

/** Fetch a target user or bail out with a JSON error. */
function security_api_target(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, account_status FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

try {
    switch ($action) {

        // =================================================================
        // READ: headline numbers for the dashboard strip
        // =================================================================
        case 'fetch_overview':
            $counts = $pdo->query("
                SELECT
                    COUNT(*)                                                          AS total_accounts,
                    SUM(CASE WHEN account_status = 'active'    THEN 1 ELSE 0 END)     AS active_accounts,
                    SUM(CASE WHEN account_status = 'suspended' THEN 1 ELSE 0 END)     AS suspended_accounts,
                    SUM(CASE WHEN account_status = 'revoked'   THEN 1 ELSE 0 END)     AS revoked_accounts,
                    SUM(CASE WHEN locked_until IS NOT NULL AND locked_until > NOW() THEN 1 ELSE 0 END) AS locked_accounts,
                    SUM(CASE WHEN must_change_password = 1 THEN 1 ELSE 0 END)         AS forced_resets,
                    SUM(CASE WHEN password_hash IS NULL OR password_hash = '' THEN 1 ELSE 0 END) AS without_password
                FROM users
            ")->fetch(PDO::FETCH_ASSOC);

            $live = (int) $pdo->query("
                SELECT COUNT(*) FROM user_sessions
                 WHERE revoked_at IS NULL AND last_seen_at >= (NOW() - INTERVAL 1 DAY)
            ")->fetchColumn();

            $today = $pdo->query("
                SELECT
                    SUM(CASE WHEN was_successful = 1 THEN 1 ELSE 0 END) AS successes,
                    SUM(CASE WHEN was_successful = 0 THEN 1 ELSE 0 END) AS failures
                FROM login_attempts
                WHERE created_at >= CURDATE()
            ")->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'total_accounts'     => (int) ($counts['total_accounts'] ?? 0),
                    'active_accounts'    => (int) ($counts['active_accounts'] ?? 0),
                    'suspended_accounts' => (int) ($counts['suspended_accounts'] ?? 0),
                    'revoked_accounts'   => (int) ($counts['revoked_accounts'] ?? 0),
                    'locked_accounts'    => (int) ($counts['locked_accounts'] ?? 0),
                    'forced_resets'      => (int) ($counts['forced_resets'] ?? 0),
                    'without_password'   => (int) ($counts['without_password'] ?? 0),
                    'live_sessions'      => $live,
                    'logins_today'       => (int) ($today['successes'] ?? 0),
                    'failures_today'     => (int) ($today['failures'] ?? 0),
                    'viewer_is_super'    => $isSuperAdmin,
                    'viewer_id'          => $admin_id,
                    'min_password_length'=> SECURITY_MIN_PASSWORD_LENGTH,
                    'password_rules'     => security_password_rules(),
                ],
            ]);
            break;

        // =================================================================
        // READ: the account roster (paginated, 100 per page)
        // =================================================================
        case 'fetch_accounts':
            $search = trim((string) ($_POST['search'] ?? $_GET['search'] ?? ''));
            $filter = (string) ($_POST['filter'] ?? $_GET['filter'] ?? 'all');
            $page   = max(1, (int) ($_POST['page'] ?? $_GET['page'] ?? 1));

            // Fixed page size — the client cannot ask for everything at once.
            $perPage = 100;

            $where  = [];
            $params = [];

            if ($search !== '') {
                $where[] = "(CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            switch ($filter) {
                case 'active':
                    $where[] = "u.account_status = 'active'";
                    break;
                case 'suspended':
                    $where[] = "u.account_status = 'suspended'";
                    break;
                case 'revoked':
                    $where[] = "u.account_status = 'revoked'";
                    break;
                case 'locked':
                    $where[] = "u.locked_until IS NOT NULL AND u.locked_until > NOW()";
                    break;
                case 'must_change':
                    $where[] = "u.must_change_password = 1";
                    break;
                case 'no_password':
                    $where[] = "(u.password_hash IS NULL OR u.password_hash = '')";
                    break;
                case 'no_email':
                    $where[] = "(u.email IS NULL OR u.email = '')";
                    break;
                case 'online':
                    $where[] = "EXISTS (SELECT 1 FROM user_sessions s
                                         WHERE s.user_id = u.id AND s.revoked_at IS NULL
                                           AND s.last_seen_at >= (NOW() - INTERVAL 1 DAY))";
                    break;
                case 'privileged':
                    $where[] = "EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)";
                    break;
            }

            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

            // Total matching rows, so the pager can offer every page.
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u{$whereSql}");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $pages = max(1, (int) ceil($total / $perPage));
            $page  = min($page, $pages);
            $offset = ($page - 1) * $perPage;

            $sql = "
                SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.picture_path,
                       u.account_status, u.status_reason, u.status_changed_at,
                       u.must_change_password, u.password_changed_at,
                       u.last_login_at, u.last_login_ip, u.failed_login_count, u.locked_until,
                       CASE WHEN u.password_hash IS NULL OR u.password_hash = '' THEN 1 ELSE 0 END AS no_password,
                       (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ',')
                          FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                         WHERE ur.user_id = u.id AND COALESCE(ur.is_frozen, 0) = 0) AS active_roles,
                       (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ',')
                          FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                         WHERE ur.user_id = u.id AND COALESCE(ur.is_frozen, 0) = 1) AS frozen_roles,
                       (SELECT COUNT(*) FROM user_sessions s
                         WHERE s.user_id = u.id AND s.revoked_at IS NULL
                           AND s.last_seen_at >= (NOW() - INTERVAL 1 DAY)) AS live_sessions
                  FROM users u
                {$whereSql}
                 ORDER BY (u.account_status <> 'active') DESC,
                          (u.locked_until IS NOT NULL AND u.locked_until > NOW()) DESC,
                          u.first_name ASC, u.last_name ASC, u.id ASC
                 LIMIT {$perPage} OFFSET {$offset}
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'      => 'success',
                'data'        => $rows,
                'count'       => count($rows),
                'pagination'  => [
                    'page'     => $page,
                    'per_page' => $perPage,
                    'pages'    => $pages,
                    'total'    => $total,
                    'from'     => $total > 0 ? $offset + 1 : 0,
                    'to'       => $offset + count($rows),
                ],
            ]);
            break;

        // =================================================================
        // READ: one account, in depth
        // =================================================================
        case 'fetch_user_detail':
            $target_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
            if ($target_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'No member selected.']);
                exit;
            }

            $uStmt = $pdo->prepare("
                SELECT id, first_name, last_name, email, real_email, phone, picture_path,
                       spiritual_status, attendance_status,
                       account_status, status_reason, status_changed_at, status_changed_by,
                       must_change_password, password_changed_at,
                       last_login_at, last_login_ip, failed_login_count, locked_until,
                       CASE WHEN password_hash IS NULL OR password_hash = '' THEN 1 ELSE 0 END AS no_password
                  FROM users WHERE id = ? LIMIT 1
            ");
            $uStmt->execute([$target_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            $rStmt = $pdo->prepare("
                SELECT r.id AS role_id, r.role_name, COALESCE(ur.is_frozen, 0) AS is_frozen,
                       COALESCE(ur.is_primary, 0) AS is_primary, ur.custom_title
                  FROM user_roles ur
                  JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = ?
                 ORDER BY ur.is_primary DESC, r.role_name ASC
            ");
            $rStmt->execute([$target_id]);
            $roles = $rStmt->fetchAll(PDO::FETCH_ASSOC);

            $sStmt = $pdo->prepare("
                SELECT id, ip_address, user_agent, created_at, last_seen_at
                  FROM user_sessions
                 WHERE user_id = ? AND revoked_at IS NULL
                   AND last_seen_at >= (NOW() - INTERVAL 30 DAY)
                 ORDER BY last_seen_at DESC LIMIT 25
            ");
            $sStmt->execute([$target_id]);
            $sessions = array_map(static function (array $s) {
                $s['device'] = security_describe_device($s['user_agent'] ?? '');
                unset($s['user_agent']);
                return $s;
            }, $sStmt->fetchAll(PDO::FETCH_ASSOC));

            $aStmt = $pdo->prepare("
                SELECT was_successful, failure_reason, context, ip_address, user_agent, created_at
                  FROM login_attempts WHERE user_id = ?
                 ORDER BY created_at DESC LIMIT 20
            ");
            $aStmt->execute([$target_id]);
            $attempts = array_map(static function (array $a) {
                $a['device'] = security_describe_device($a['user_agent'] ?? '');
                unset($a['user_agent']);
                return $a;
            }, $aStmt->fetchAll(PDO::FETCH_ASSOC));

            $lStmt = $pdo->prepare("
                SELECT action, details, actor_label, ip_address, created_at
                  FROM security_audit_log WHERE target_user_id = ?
                 ORDER BY created_at DESC LIMIT 20
            ");
            $lStmt->execute([$target_id]);

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'user'      => $user,
                    'roles'     => $roles,
                    'sessions'  => $sessions,
                    'attempts'  => $attempts,
                    'audit'     => $lStmt->fetchAll(PDO::FETCH_ASSOC),
                    'can_act'   => security_guard_target($pdo, $admin_id, $target_id, 'view') === null,
                    'is_super'  => security_is_super_admin($pdo, $target_id),
                ],
            ]);
            break;

        // =================================================================
        // WRITE: suspend / revoke / reinstate
        // =================================================================
        case 'set_status':
            $target_id = (int) ($_POST['user_id'] ?? 0);
            $status    = strtolower(trim((string) ($_POST['account_status'] ?? '')));
            $reason    = trim((string) ($_POST['reason'] ?? ''));

            if (!in_array($status, ['active', 'suspended', 'revoked'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'Unknown account status requested.']);
                exit;
            }

            $guardAction = $status === 'active' ? 'reinstate' : $status;
            $blocked = security_guard_target($pdo, $admin_id, $target_id, $guardAction);
            if ($blocked !== null) {
                echo json_encode(['status' => 'error', 'message' => $blocked]);
                exit;
            }

            $target = security_api_target($pdo, $target_id);
            if (!$target) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            if ($status !== 'active' && $reason === '') {
                echo json_encode(['status' => 'error', 'message' => 'Please record a reason — it goes into the audit trail.']);
                exit;
            }

            $name = trim($target['first_name'] . ' ' . $target['last_name']);

            if ($status === 'active') {
                $pdo->prepare("
                    UPDATE users
                       SET account_status = 'active',
                           status_reason = NULL,
                           status_changed_at = NOW(),
                           status_changed_by = ?,
                           failed_login_count = 0,
                           locked_until = NULL
                     WHERE id = ?
                ")->execute([$admin_id, $target_id]);

                $frozenStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND COALESCE(is_frozen, 0) = 1"
                );
                $frozenStmt->execute([$target_id]);
                $frozen = (int) $frozenStmt->fetchColumn();

                security_log($pdo, 'account_reinstated', $target_id, "Account reinstated. {$reason}");
                security_notify_user(
                    $pdo,
                    $target_id,
                    'Account Reinstated',
                    'Your workspace access has been restored. You can sign in again.'
                );

                echo json_encode([
                    'status'  => 'success',
                    'message' => "{$name} can sign in again."
                               . ($frozen > 0 ? " Note: {$frozen} role(s) are still frozen — use Restore Roles to give clearance back." : ''),
                ]);
                break;
            }

            // Suspended or revoked: block login and kill every live session.
            $pdo->prepare("
                UPDATE users
                   SET account_status = ?,
                       status_reason = ?,
                       status_changed_at = NOW(),
                       status_changed_by = ?
                 WHERE id = ?
            ")->execute([$status, substr($reason, 0, 255), $admin_id, $target_id]);

            $killed = security_revoke_sessions(
                $pdo,
                $target_id,
                $admin_id,
                $status === 'revoked' ? 'Account revoked by an administrator' : 'Account suspended by an administrator'
            );

            $frozenRoles = 0;
            if ($status === 'revoked') {
                // Full lockdown: freeze every role row so a reinstated account
                // comes back with zero clearance until it is explicitly restored.
                $freeze = $pdo->prepare("UPDATE user_roles SET is_frozen = 1 WHERE user_id = ?");
                $freeze->execute([$target_id]);
                $frozenRoles = $freeze->rowCount();
            }

            security_log(
                $pdo,
                $status === 'revoked' ? 'account_revoked' : 'account_suspended',
                $target_id,
                ucfirst($status) . " by admin. Reason: {$reason}. Sessions killed: {$killed}."
                . ($status === 'revoked' ? " Roles frozen: {$frozenRoles}." : '')
            );
            security_notify_user(
                $pdo,
                $target_id,
                $status === 'revoked' ? 'Account Access Revoked' : 'Account Suspended',
                'Your workspace access has been ' . $status . ' by an administrator. Reason: ' . $reason
            );

            echo json_encode([
                'status'  => 'success',
                'message' => "{$name} is now {$status}. {$killed} live session(s) signed out"
                           . ($frozenRoles > 0 ? ", {$frozenRoles} role(s) frozen" : '') . '.',
            ]);
            break;

        // =================================================================
        // WRITE: admin sets a new password for a member
        // =================================================================
        case 'reset_password':
            $target_id     = (int) ($_POST['user_id'] ?? 0);
            $new_password  = (string) ($_POST['new_password'] ?? '');
            $confirm       = (string) ($_POST['confirm_password'] ?? '');
            $requireChange = !empty($_POST['require_change']);
            $reason        = trim((string) ($_POST['reason'] ?? ''));

            $blocked = security_guard_target($pdo, $admin_id, $target_id, 'reset');
            if ($blocked !== null) {
                echo json_encode(['status' => 'error', 'message' => $blocked]);
                exit;
            }

            $target = security_api_target($pdo, $target_id);
            if (!$target) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            if ($new_password === '' || $confirm === '') {
                echo json_encode(['status' => 'error', 'message' => 'Type the new password twice.']);
                exit;
            }
            if (!hash_equals($new_password, $confirm)) {
                echo json_encode(['status' => 'error', 'message' => 'The two passwords do not match.']);
                exit;
            }

            $problems = security_password_problems($new_password, $target);
            if (!empty($problems)) {
                echo json_encode(['status' => 'error', 'message' => 'Password rejected: ' . implode(' ', $problems)]);
                exit;
            }

            $hash = password_hash($new_password, PASSWORD_BCRYPT);

            $pdo->prepare("
                UPDATE users
                   SET password_hash = ?,
                       password_changed_at = NOW(),
                       must_change_password = ?,
                       failed_login_count = 0,
                       locked_until = NULL
                 WHERE id = ?
            ")->execute([$hash, $requireChange ? 1 : 0, $target_id]);

            // A credential change always invalidates every existing session.
            $killed = security_revoke_sessions(
                $pdo,
                $target_id,
                $admin_id,
                'Password reset by an administrator'
            );

            $name = trim($target['first_name'] . ' ' . $target['last_name']);

            security_log(
                $pdo,
                'password_reset_by_admin',
                $target_id,
                'Administrator set a new password.'
                . ($requireChange ? ' Member must change it at next sign-in.' : ' No forced change.')
                . " Sessions killed: {$killed}." . ($reason !== '' ? " Reason: {$reason}" : '')
            );
            security_notify_user(
                $pdo,
                $target_id,
                'Password Reset by Administrator',
                'An administrator set a new password on your account'
                . ($requireChange ? ' and you will be asked to choose your own at next sign-in.' : '.')
                . ' If you did not request this, contact the church office immediately.'
            );

            echo json_encode([
                'status'  => 'success',
                'message' => "Password reset for {$name}. {$killed} session(s) signed out."
                           . ($requireChange ? ' They must choose a new password at next sign-in.' : ''),
            ]);
            break;

        // =================================================================
        // WRITE: admin changes a member's sign-in (login) email
        // =================================================================
        // This is the system-generated email the member types on the sign-in
        // screen (users.email) — never their personal address (real_email),
        // which the member edits themselves on their profile. Used when a
        // member finds their generated address too long or keeps forgetting it.
        case 'change_email':
            $target_id     = (int) ($_POST['user_id'] ?? 0);
            $new_email     = strtolower(trim((string) ($_POST['new_email'] ?? '')));
            $confirm       = strtolower(trim((string) ($_POST['confirm_email'] ?? '')));
            $reason        = trim((string) ($_POST['reason'] ?? ''));
            $killSessions  = !empty($_POST['sign_out_everywhere']);

            $blocked = security_guard_target($pdo, $admin_id, $target_id, 'change_email');
            if ($blocked !== null) {
                echo json_encode(['status' => 'error', 'message' => $blocked]);
                exit;
            }

            $target = security_api_target($pdo, $target_id);
            if (!$target) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            if ($new_email === '' || $confirm === '') {
                echo json_encode(['status' => 'error', 'message' => 'Type the new sign-in email twice.']);
                exit;
            }

            $problems = security_login_email_problems($new_email);
            if (!empty($problems)) {
                echo json_encode(['status' => 'error', 'message' => 'Email rejected: ' . implode(' ', $problems)]);
                exit;
            }

            // Both entries may be bare prefixes or full addresses — normalise
            // before comparing so "grace" and "grace@hodlc.com" still match.
            $newFull     = security_normalize_login_email($new_email);
            $confirmFull = security_normalize_login_email($confirm);

            if ($newFull === null || $confirmFull === null || !hash_equals($newFull, $confirmFull)) {
                echo json_encode(['status' => 'error', 'message' => 'The two email addresses do not match.']);
                exit;
            }

            $old_email = strtolower(trim((string) $target['email']));
            $old_display = $old_email !== '' ? $old_email : '(none — no sign-in email was set)';

            if ($newFull === $old_email) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => "That is already {$target['first_name']}'s sign-in email.",
                ]);
                exit;
            }

            // Uniqueness is checked in the application: users.email has no
            // unique index in the baseline schema, so a duplicate would make
            // the login query silently pick the first row that matches.
            // LOWER() keeps this safe whatever the column collation is.
            $dupStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1");
            $dupStmt->execute([$newFull, $target_id]);
            if ($dupStmt->fetch()) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'That sign-in email is already used by another member. Pick a different one.',
                ]);
                exit;
            }

            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$newFull, $target_id]);

            // Optional: sign them out so their next sign-in uses the new
            // address (existing sessions stay valid otherwise — sessions are
            // keyed by user id, not by email).
            $killed = 0;
            if ($killSessions) {
                $killed = security_revoke_sessions(
                    $pdo,
                    $target_id,
                    $admin_id,
                    'Sign-in email changed by an administrator'
                );
            }

            $name = trim($target['first_name'] . ' ' . $target['last_name']);

            security_log(
                $pdo,
                'login_email_changed',
                $target_id,
                "Sign-in email changed by administrator from {$old_display} to {$newFull}."
                . ($killSessions ? " Sessions killed: {$killed}." : '')
                . ($reason !== '' ? " Reason: {$reason}" : '')
            );
            security_notify_user(
                $pdo,
                $target_id,
                'Sign-in Email Changed',
                'Your sign-in email was changed from ' . $old_display . ' to ' . $newFull
                . ' by an administrator. Use the new one next time you sign in — your password has not changed.'
            );

            echo json_encode([
                'status'  => 'success',
                'message' => "Sign-in email for {$name} is now {$newFull}. Their password is unchanged"
                           . ($killed > 0 ? " and {$killed} session(s) were signed out" : '')
                           . '. Tell them — the old address will no longer work.',
            ]);
            break;

        // =================================================================
        // WRITE: generate sign-in emails for every account that has none
        // =================================================================
        // One click for "some members were created without an email at all".
        // Mirrors the generator used when a member is created in the
        // Congregation module (shortest name parts, numeric suffix until
        // unique), so bulk-filled addresses look exactly like generated ones.
        case 'generate_missing_emails':
            $missing = $pdo->query("
                SELECT id, first_name, last_name
                  FROM users
                 WHERE email IS NULL OR email = ''
                 ORDER BY first_name ASC, last_name ASC, id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (!$missing) {
                echo json_encode([
                    'status'  => 'success',
                    'message' => 'Every account already has a sign-in email — nothing to generate.',
                    'data'    => ['generated' => [], 'skipped' => []],
                ]);
                exit;
            }

            $update  = $pdo->prepare("UPDATE users SET email = ? WHERE id = ? AND (email IS NULL OR email = '')");
            $reserved = [];   // addresses handed out in this batch
            $generated = [];  // rows for the response
            $skipped   = [];  // members whose names yield no usable prefix

            $pdo->beginTransaction();
            try {
                foreach ($missing as $m) {
                    $email = security_generate_login_email(
                        $pdo,
                        (string) $m['first_name'],
                        (string) $m['last_name'],
                        $reserved
                    );

                    $name = trim($m['first_name'] . ' ' . $m['last_name']) ?: 'Member #' . $m['id'];

                    if ($email === null) {
                        // No letters or digits in either name — nothing to
                        // build an address from. Set it by hand instead.
                        $skipped[] = ['id' => (int) $m['id'], 'name' => $name];
                        continue;
                    }

                    $update->execute([$email, (int) $m['id']]);
                    if ($update->rowCount() < 1) {
                        continue; // email was set by someone else mid-batch — leave it
                    }

                    $reserved[] = $email;
                    $generated[] = ['id' => (int) $m['id'], 'name' => $name, 'email' => $email];

                    security_log(
                        $pdo,
                        'login_email_generated',
                        (int) $m['id'],
                        "Sign-in email generated for a member who had none: {$email}."
                    );
                    security_notify_user(
                        $pdo,
                        (int) $m['id'],
                        'Your Sign-in Email',
                        'Your sign-in email is ' . $email . '. Use it with your password to sign in.'
                    );
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $msg = count($generated) . ' sign-in email(s) generated.';
            if ($skipped) {
                $msg .= ' ' . count($skipped) . ' member(s) were skipped — their names have no letters or digits, so set an email by hand on the Manage panel.';
            }

            echo json_encode([
                'status'  => 'success',
                'message' => $msg,
                'data'    => ['generated' => $generated, 'skipped' => $skipped],
            ]);
            break;

        // =================================================================
        // WRITE: sign a member out of everything
        // =================================================================
        case 'force_logout':
            $target_id = (int) ($_POST['user_id'] ?? 0);

            $blocked = security_guard_target($pdo, $admin_id, $target_id, 'force_logout');
            if ($blocked !== null) {
                echo json_encode(['status' => 'error', 'message' => $blocked]);
                exit;
            }

            $target = security_api_target($pdo, $target_id);
            if (!$target) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            $killed = security_revoke_sessions($pdo, $target_id, $admin_id, 'Signed out by an administrator');
            security_log($pdo, 'sessions_revoked', $target_id, "Administrator signed out {$killed} session(s).");

            echo json_encode([
                'status'  => 'success',
                'message' => $killed > 0
                    ? "Signed out {$killed} session(s) for {$target['first_name']}."
                    : "{$target['first_name']} had no live sessions — future ones are still blocked until they sign in again.",
            ]);
            break;

        // =================================================================
        // WRITE: kill one specific device
        // =================================================================
        case 'revoke_session':
            $session_id = (int) ($_POST['session_id'] ?? 0);
            if ($session_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'No session selected.']);
                exit;
            }

            $sStmt = $pdo->prepare("SELECT id, user_id, session_hash FROM user_sessions WHERE id = ? LIMIT 1");
            $sStmt->execute([$session_id]);
            $session = $sStmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                echo json_encode(['status' => 'error', 'message' => 'That session no longer exists.']);
                exit;
            }

            if (hash_equals((string) $session['session_hash'], security_current_session_hash())) {
                echo json_encode(['status' => 'error', 'message' => 'That is the browser you are using right now. Use Secure Sign Out instead.']);
                exit;
            }

            if ((int) $session['user_id'] !== $admin_id) {
                $blocked = security_guard_target($pdo, $admin_id, (int) $session['user_id'], 'revoke_session');
                if ($blocked !== null) {
                    echo json_encode(['status' => 'error', 'message' => $blocked]);
                    exit;
                }
            }

            $pdo->prepare("
                UPDATE user_sessions
                   SET revoked_at = NOW(), revoked_by = ?, revoke_reason = 'Device signed out by an administrator'
                 WHERE id = ? AND revoked_at IS NULL
            ")->execute([$admin_id, $session_id]);

            security_log($pdo, 'session_revoked', (int) $session['user_id'], "Single device signed out (session #{$session_id}).");

            echo json_encode(['status' => 'success', 'message' => 'That device has been signed out.']);
            break;

        // =================================================================
        // WRITE: clear a failed-login lockout
        // =================================================================
        case 'unlock_account':
            $target_id = (int) ($_POST['user_id'] ?? 0);

            $blocked = security_guard_target($pdo, $admin_id, $target_id, 'unlock');
            if ($blocked !== null) {
                echo json_encode(['status' => 'error', 'message' => $blocked]);
                exit;
            }

            $target = security_api_target($pdo, $target_id);
            if (!$target) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            $pdo->prepare("UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?")
                ->execute([$target_id]);

            security_log($pdo, 'account_unlocked', $target_id, 'Failed-login lockout cleared by an administrator.');
            security_notify_user($pdo, $target_id, 'Account Unlocked', 'Your sign-in lockout has been cleared. You can try again now.');

            echo json_encode(['status' => 'success', 'message' => "Lockout cleared for {$target['first_name']}."]);
            break;

        // =================================================================
        // WRITE: force (or cancel) a password change at next sign-in
        // =================================================================
        case 'set_force_change':
            $target_id = (int) ($_POST['user_id'] ?? 0);
            $flag      = !empty($_POST['force']) ? 1 : 0;

            $blocked = security_guard_target($pdo, $admin_id, $target_id, 'force_change');
            if ($blocked !== null) {
                echo json_encode(['status' => 'error', 'message' => $blocked]);
                exit;
            }

            $target = security_api_target($pdo, $target_id);
            if (!$target) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            $pdo->prepare("UPDATE users SET must_change_password = ? WHERE id = ?")->execute([$flag, $target_id]);

            security_log(
                $pdo,
                $flag ? 'force_password_change_on' : 'force_password_change_off',
                $target_id,
                $flag ? 'Member must change their password at next sign-in.' : 'Forced password change cancelled.'
            );

            echo json_encode([
                'status'  => 'success',
                'message' => $flag
                    ? "{$target['first_name']} will be asked to set a new password at next sign-in."
                    : "Forced password change cancelled for {$target['first_name']}.",
            ]);
            break;

        // =================================================================
        // WRITE: unfreeze roles after a revoke (Super Admin only)
        // =================================================================
        case 'restore_roles':
            if (!$isSuperAdmin) {
                echo json_encode(['status' => 'error', 'message' => 'Only a Super Admin can restore system roles.']);
                exit;
            }

            $target_id = (int) ($_POST['user_id'] ?? 0);

            $blocked = security_guard_target($pdo, $admin_id, $target_id, 'restore_roles');
            if ($blocked !== null) {
                echo json_encode(['status' => 'error', 'message' => $blocked]);
                exit;
            }

            $target = security_api_target($pdo, $target_id);
            if (!$target) {
                echo json_encode(['status' => 'error', 'message' => 'That member no longer exists.']);
                exit;
            }

            $unfreeze = $pdo->prepare("UPDATE user_roles SET is_frozen = 0 WHERE user_id = ? AND COALESCE(is_frozen, 0) = 1");
            $unfreeze->execute([$target_id]);
            $restored = $unfreeze->rowCount();

            security_log($pdo, 'roles_restored', $target_id, "Restored {$restored} frozen role(s).");
            if ($restored > 0) {
                security_notify_user(
                    $pdo,
                    $target_id,
                    'System Clearance Restored',
                    'Your system roles have been restored. Sign out and back in to see your modules again.'
                );
            }

            echo json_encode([
                'status'  => 'success',
                'message' => $restored > 0
                    ? "Restored {$restored} role(s) for {$target['first_name']}. They must sign in again to pick them up."
                    : "{$target['first_name']} had no frozen roles.",
            ]);
            break;

        // =================================================================
        // READ: every live session in the system
        // =================================================================
        case 'fetch_sessions':
            $stmt = $pdo->query("
                SELECT s.id, s.user_id, s.ip_address, s.user_agent, s.created_at, s.last_seen_at,
                       u.first_name, u.last_name, u.email, u.account_status
                  FROM user_sessions s
                  JOIN users u ON u.id = s.user_id
                 WHERE s.revoked_at IS NULL
                   AND s.last_seen_at >= (NOW() - INTERVAL 7 DAY)
                 ORDER BY s.last_seen_at DESC
                 LIMIT 200
            ");

            $rows = array_map(static function (array $r) {
                $r['device'] = security_describe_device($r['user_agent'] ?? '');
                unset($r['user_agent']);
                return $r;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            echo json_encode(['status' => 'success', 'data' => $rows]);
            break;

        // =================================================================
        // READ: login activity
        // =================================================================
        case 'fetch_login_history':
            $filter = (string) ($_POST['filter'] ?? $_GET['filter'] ?? 'all');
            $search = trim((string) ($_POST['search'] ?? $_GET['search'] ?? ''));

            $where  = [];
            $params = [];

            if ($filter === 'failed') {
                $where[] = 'a.was_successful = 0';
            } elseif ($filter === 'success') {
                $where[] = 'a.was_successful = 1';
            }

            if ($search !== '') {
                $where[] = "(a.email LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR a.ip_address LIKE ?)";
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $sql = "
                SELECT a.id, a.user_id, a.email, a.was_successful, a.failure_reason, a.context,
                       a.ip_address, a.user_agent, a.created_at,
                       u.first_name, u.last_name
                  FROM login_attempts a
             LEFT JOIN users u ON u.id = a.user_id
            ";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY a.created_at DESC LIMIT 200';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $rows = array_map(static function (array $r) {
                $r['device'] = security_describe_device($r['user_agent'] ?? '');
                unset($r['user_agent']);
                return $r;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));

            echo json_encode(['status' => 'success', 'data' => $rows]);
            break;

        // =================================================================
        // READ: the audit trail
        // =================================================================
        case 'fetch_audit_log':
            $search = trim((string) ($_POST['search'] ?? $_GET['search'] ?? ''));

            $sql = "
                SELECT l.id, l.actor_user_id, l.actor_label, l.target_user_id, l.action,
                       l.details, l.ip_address, l.created_at,
                       t.first_name AS target_first, t.last_name AS target_last
                  FROM security_audit_log l
             LEFT JOIN users t ON t.id = l.target_user_id
            ";
            $params = [];

            if ($search !== '') {
                $sql .= " WHERE (l.action LIKE ? OR l.details LIKE ? OR l.actor_label LIKE ?
                                 OR CONCAT_WS(' ', t.first_name, t.last_name) LIKE ?)";
                $like = '%' . $search . '%';
                $params = [$like, $like, $like, $like];
            }

            $sql .= ' ORDER BY l.created_at DESC LIMIT 200';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // =================================================================
        // READ: a strong password suggestion for the reset form
        // =================================================================
        case 'suggest_password':
            echo json_encode(['status' => 'success', 'data' => ['password' => security_suggest_password()]]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid security command.']);
            break;
    }

} catch (PDOException $e) {
    error_log('Security API Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Check includes/error_log for details.']);
} catch (Throwable $e) {
    error_log('Security API Fatal: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Unexpected error while running that security action.']);
}
?>
