<?php
// /includes/header.php
/**
 * Every staff screen starts here (`require_once '../../includes/header.php';`)
 * and ends with includes/footer.php. This file signs the visitor in or sends
 * them to the login page, then prints the app shell (includes/ui/shell.php):
 * the desktop sidebar and top bar, or the phone app bar and bottom bar.
 *
 * A page may set $ui before requiring this file — title, 'strict' mode for
 * pages rebuilt on the shared components, extra scripts, CSP sources. See
 * ui_page_config() and docs/UI.md.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database first: db.php resumes a remembered device's session (30-day "stay
// signed in") and runs security_enforce_session(), which tears the session
// down if the account has been suspended, revoked or signed out by an
// administrator. Only then is "signed in?" a question worth asking.
require_once __DIR__ . '/db.php';

// Security: Redirect to login if not authenticated, remembering the page so
// the member lands back on it (a tapped notification used to end on the
// dashboard).
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . auth_login_url_for_request());
    exit;
}

// An outstanding forced password change blocks every other authenticated page.
// /auth/change_password.php deliberately does NOT include this file, so there
// is no redirect loop.
if (!empty($_SESSION['must_change_password'])) {
    header("Location: /auth/change_password.php");
    exit;
}

// Everyone needs a confirmed personal email (docs/EMAIL.md). With none and no
// recent link, every page sends them to the prompt, which (like
// change_password.php) never includes this file. The answer is cached in the
// session for 5 minutes, so this costs nothing on most page views.
require_once __DIR__ . '/campaigns/util.php';
require_once __DIR__ . '/campaigns/addresses.php';
require_once __DIR__ . '/campaigns/identity.php';
$emailPrompt = email_prompt_state_cached($pdo, (int) $_SESSION['user_id']);
if ($emailPrompt === 'required') {
    $emailPromptNext = auth_safe_next((string) ($_SERVER['REQUEST_URI'] ?? ''));
    header('Location: /auth/add_email.php' . ($emailPromptNext !== null ? '?next=' . rawurlencode($emailPromptNext) : ''));
    exit;
}

require_once __DIR__ . '/ui/shell.php';

// ------------------------------------------------------------------------
// Variables pages have read from this file for years. Keep them.
// ------------------------------------------------------------------------
$firstName = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$activeRole = htmlspecialchars($_SESSION['active_role'] ?? 'Member');
$profilePicPath = !empty($_SESSION['picture_path']) ? htmlspecialchars($_SESSION['picture_path']) : null;

$currentModule = basename(dirname($_SERVER['PHP_SELF']));
$isDashboard = ($_SERVER['PHP_SELF'] == '/index.php' || $currentModule == 'dashboard');
$uiModuleKey = $_SERVER['PHP_SELF'] == '/index.php' ? 'index.php' : $currentModule;

$uiNavCtx = ui_nav_context($pdo);
$user_dept_ids = $uiNavCtx['dept_ids'];
$user_dept_roles = $uiNavCtx['dept_roles'];
$is_super_admin = $uiNavCtx['is_super_admin'];
$assim_nav = $uiNavCtx['assim_nav'];
$assim_manager = $uiNavCtx['assim_manager'];
$se_nav = $uiNavCtx['se_nav'];

$pastors = ['Resident_Pastor', 'Assoc_Pastor'];
$pastors_directors = ['Resident_Pastor', 'Assoc_Pastor', 'Director'];
$sms_roles = ['Resident_Pastor', 'Assoc_Pastor', 'Director', 'HOD', 'Sub_Unit_Head']; // mirrors SMS_ALLOWED_ROLES in includes/sms_functions.php
$can_view_departments = ui_nav_has($uiNavCtx, $pastors)
    || (bool) array_intersect($user_dept_roles, ['Director', 'HOD', 'Sub_Unit_Head']);

if (!function_exists('userHasNavAccess')) {
    /** Kept for older pages: roles or active department seats; Super Admin always. */
    function userHasNavAccess($allowed_roles = [], $allowed_dept_ids = [])
    {
        global $pdo;

        return ui_nav_has(ui_nav_context($pdo), (array) $allowed_roles, (array) $allowed_dept_ids);
    }
}

$uiPage = ui_page_config(isset($ui) && is_array($ui) ? $ui : [], $uiModuleKey);
$moduleTitle = $uiPage['title'];

ui_shell_open($pdo, $uiPage);

// A confirmation link is waiting: a reminder at the top of every page.
if ($emailPrompt === 'banner') {
    echo email_prompt_banner($pdo, (int) $_SESSION['user_id']);
}
