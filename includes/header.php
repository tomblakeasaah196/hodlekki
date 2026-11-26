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
    header("Location: /auth/change_password");
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
    header('Location: /auth/add_email' . ($emailPromptNext !== null ? '?next=' . rawurlencode($emailPromptNext) : ''));
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

// Access is decided by can() (includes/permissions.php); these are the
// navigation's answers, kept under the names pages have read for years.
$uiNavCtx = ui_nav_context($pdo);
$user_dept_ids = $uiNavCtx['dept_ids'];
$is_super_admin = $uiNavCtx['is_super_admin'];
$assim_nav = $uiNavCtx['assim_nav'];
$assim_manager = $uiNavCtx['assim_manager'];
$se_nav = $uiNavCtx['se_nav'];
$can_view_departments = ui_nav_can_access('departments', $uiNavCtx);

// Passkeys (includes/passkeys.php): the first page after a password sign-in
// offers one, once. The browser decides whether to show it (this device can
// make one and has none); Profile always has passkey.js for its own list.
$passkeyOffer = !empty($_SESSION['passkey_offer']);
unset($_SESSION['passkey_offer']);
if ($passkeyOffer || $uiModuleKey === 'profile') {
    $ui = isset($ui) && is_array($ui) ? $ui : [];
    $ui['scripts'] = array_merge((array) ($ui['scripts'] ?? []), ['/assets/ui/js/passkey.js']);
}

$uiPage = ui_page_config(isset($ui) && is_array($ui) ? $ui : [], $uiModuleKey);
$moduleTitle = $uiPage['title'];

ui_shell_open($pdo, $uiPage);

// A confirmation link is waiting: a reminder at the top of every page.
if ($emailPrompt === 'banner') {
    echo email_prompt_banner($pdo, (int) $_SESSION['user_id']);
}

if ($passkeyOffer) {
    try {
        require_once __DIR__ . '/passkeys.php';
        if (passkeys_ready($pdo)) {
            echo passkey_offer_html($pdo, (int) $_SESSION['user_id']);
        }
    } catch (Throwable $e) {
        error_log('[passkey] offer failed: ' . $e->getMessage());
    }
}
