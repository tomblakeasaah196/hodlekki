<?php
// /includes/ui/nav.php
/**
 * THE NAVIGATION REGISTRY — one list of every staff module.
 *
 * The desktop sidebar, the phone menu sheet and the Ctrl/Cmd+K search index
 * are all rendered from ui_nav_registry(). Adding a module means adding ONE
 * entry here (and its icon to assets/ui/src/icons/ if it is new).
 *
 * Who sees what is decided by ONE function, ui_nav_can_access(), which asks
 * the roles × modules permission matrix (includes/permissions.php): both
 * shells and the search follow it. Hiding a link is not a permission: every
 * module and API checks can() itself.
 */

if (defined('HOD_UI_NAV')) {
    return;
}
define('HOD_UI_NAV', true);

/** Sections in display order: key => label. */
function ui_nav_sections(): array
{
    return [
        'command' => 'Command Center',
        'growth' => 'Growth & Retention',
        'units' => 'Specialized Units',
        'operations' => 'Core Operations',
        'stewardship' => 'Security & Stewardship',
        'system' => 'System',
    ];
}

/**
 * Every module, in display order within its section.
 *
 * key       stable id (also the search id and the access key)
 * label     what the link says
 * icon      a name from the sprite (assets/ui/src/icons)
 * href      where it goes
 * section   a key of ui_nav_sections()
 * match     module folder names (or 'index.php') that mark it active
 * keywords  extra words the search should find it by
 * tabs      tab id => label, for the search's "#tab=" deep links
 * badge     optional: fn(PDO, array $ctx): int — a count shown on the link
 * when_exists  true: listed only once the href's file exists (a module whose
 *           screens land in a later PR keeps its place here meanwhile)
 */
function ui_nav_registry(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => '/index.php', 'section' => 'command',
            'match' => ['index.php', 'dashboard'], 'keywords' => ['home', 'workspace', 'overview', 'kpi']],
        ['key' => 'member_portal', 'label' => 'My Portal', 'icon' => 'house', 'href' => '/modules/member_portal/index.php', 'section' => 'command',
            'match' => ['member_portal'], 'keywords' => ['member portal', 'my portal', 'home']],
        ['key' => 'profile', 'label' => 'My Profile', 'icon' => 'circle-user-round', 'href' => '/modules/profile/index.php', 'section' => 'command',
            'match' => ['profile'], 'keywords' => ['account', 'settings', 'picture', 'password', 'details']],
        ['key' => 'bible', 'label' => 'The Bible', 'icon' => 'book-open', 'href' => '/modules/bible/index.php', 'section' => 'command',
            'match' => ['bible'],
            'keywords' => ['bible', 'scripture', 'kjv', 'nkjv', 'niv', 'amp', 'amplified', 'verse', 'read', 'devotion', 'study', 'journal', 'notes', 'highlights', 'reading plan'],
            'tabs' => ['read' => 'Read', 'search' => 'Search', 'notes' => 'My Study', 'plans' => 'Reading Plans']],
        ['key' => 'hymnal', 'label' => 'Hymnary', 'icon' => 'music', 'href' => '/modules/hymnal/index.php', 'section' => 'command',
            'match' => ['hymnal'],
            'keywords' => ['hymn', 'hymnal', 'hymnary', 'song', 'songs', 'worship', 'choir', 'sol-fa', 'solfa', 'tonic solfa', 'notes', 'score',
                'sheet music', 'satb', 'chorus', 'praise', 'lyrics', 'words', 'rehearse', 'rehearsal', 'music', 'yoruba'],
            'tabs' => ['congregation' => 'Words', 'choir' => 'Choir mode']],
        ['key' => 'regions', 'label' => 'Regional Hubs', 'icon' => 'map-pin', 'href' => '/modules/regions/index.php', 'section' => 'command',
            'match' => ['regions'], 'keywords' => ['regions', 'hubs', 'locations', 'broadcast'], 'tabs' => ['hub' => 'My Region']],
        ['key' => 'idi', 'label' => 'Data Insights (IDI)', 'icon' => 'chart-column', 'href' => '/modules/idi/index.php', 'section' => 'command',
            'match' => ['idi'], 'keywords' => ['idi', 'analytics', 'insights', 'data', 'reports', 'statistics'],
            'tabs' => ['congregation' => 'Congregation', 'attendance' => 'Events', 'embrace' => 'Embrace', 'operations' => 'Units & Academy', 'charis' => 'Charis', 'pastoral' => 'Pastoral']],
        // Mail (docs/MAIL.md): shown to people who work from a mailbox, and to mail admins and export approvers
        // (the Mailboxes screen is reached from the Mail page). The badge: unread Inbox conversations.
        ['key' => 'mail', 'label' => 'Mail', 'icon' => 'mail', 'href' => '/modules/mail/index.php', 'section' => 'command',
            'match' => ['mail'], 'keywords' => ['mail', 'email', 'inbox', 'mailbox', 'mailboxes', 'messages'],
            'badge' => 'ui_nav_badge_mail', 'badge_title' => 'Unread conversations in your mailboxes'],

        ['key' => 'congregation', 'label' => 'Congregation', 'icon' => 'users', 'href' => '/modules/congregation/index.php', 'section' => 'growth',
            'match' => ['congregation'], 'keywords' => ['members', 'people', 'database', 'roster', 'directory']],
        ['key' => 'reach', 'label' => 'Reach (Evangelism)', 'icon' => 'globe', 'href' => '/modules/reach/index.php', 'section' => 'growth',
            'match' => ['reach'], 'keywords' => ['evangelism', 'outreach', 'souls', 'leads', 'campaigns'],
            'tabs' => ['campaigns' => 'Campaigns', 'followup' => 'Follow-Up', 'analytics' => 'Analytics', 'howto' => 'How to Use'],
            'badge' => 'ui_nav_badge_reach', 'badge_title' => 'Unassigned leads + your overdue follow-ups'],
        ['key' => 'embrace', 'label' => 'Embrace (First Timers)', 'icon' => 'user-round-plus', 'href' => '/modules/embrace/index.php', 'section' => 'growth',
            'match' => ['embrace'], 'keywords' => ['first timers', 'visitors', 'welcome', 'guests']],
        ['key' => 'assimilation', 'label' => 'Assimilation', 'icon' => 'sprout', 'href' => '/modules/assimilation/index.php', 'section' => 'growth',
            'match' => ['assimilation'], 'keywords' => ['new converts', 'follow up', 'retention', 'integration'],
            'tabs' => ['followup' => 'Follow-Up', 'team' => 'Team', 'howto' => 'How to Use'],
            'manager_tabs' => ['find' => 'Find People', 'followup' => 'Follow-Up', 'team' => 'Team', 'analytics' => 'Analytics', 'howto' => 'How to Use'],
            'badge' => 'ui_nav_badge_assimilation', 'badge_title' => 'Your overdue follow-ups (managers: + unclaimed people)'],
        ['key' => 'charis', 'label' => 'Charis (Welfare)', 'icon' => 'heart-handshake', 'href' => '/modules/charis/index.php', 'section' => 'growth',
            'match' => ['charis'], 'keywords' => ['welfare', 'care', 'benevolence', 'support'],
            'tabs' => ['welfare' => 'Welfare', 'events' => 'Event Planning', 'finance' => 'Finance', 'library' => 'Library', 'howto' => 'How To Use']],
        ['key' => 'academy', 'label' => 'HOD Academy', 'icon' => 'graduation-cap', 'href' => '/modules/academy/index.php', 'section' => 'growth',
            'match' => ['academy'], 'keywords' => ['school', 'lms', 'courses', 'classes', 'study', 'exams', 'learning'],
            'tabs' => ['student-dash' => 'My Noticeboard', 'student-study' => 'Study & Assignments', 'student-exam' => 'Examination Portal', 'student-result' => 'My Result Slip']],

        ['key' => 'junior_church', 'label' => 'Junior Church', 'icon' => 'baby', 'href' => '/modules/junior_church/index.php', 'section' => 'units',
            'match' => ['junior_church'], 'keywords' => ['kids', 'children', 'sunday school'],
            'tabs' => ['services' => 'Service Tracker', 'roster' => 'Children Roster', 'curriculum' => 'Curriculums']],
        ['key' => 'river_of_life', 'label' => 'River of Life (Choir)', 'icon' => 'mic-vocal', 'href' => '/modules/river_of_life/index.php', 'section' => 'units',
            'match' => ['river_of_life'], 'keywords' => ['choir', 'music', 'worship', 'songs'],
            'tabs' => ['kanban' => 'Roster Kanban', 'rehearsals' => 'Rehearsals', 'attendance' => 'Attendance']],
        ['key' => 'envision', 'label' => 'Envision (Media)', 'icon' => 'video', 'href' => '/modules/envision/index.php', 'section' => 'units',
            'match' => ['envision'], 'keywords' => ['media', 'video', 'radio', 'sermons', 'broadcast'],
            'tabs' => ['roster' => 'Crew Roster', 'archive' => 'Sermon Archive', 'comments' => 'Moderation', 'config' => 'Broadcast Config']],
        ['key' => 'special_events', 'label' => 'Special Events', 'icon' => 'party-popper', 'href' => '/modules/special_events/index.php', 'section' => 'units',
            'match' => ['special_events'], 'keywords' => ['events', 'karaoke', 'games', 'chara', 'check-in', 'envision'],
            'tabs' => ['overview' => 'Overview', 'registration' => 'Registration', 'teams' => 'Teams', 'games' => 'Games', 'attendees' => 'Attendees', 'insights' => 'Insights']],
        ['key' => 'zoe', 'label' => 'Zoe (Prayer)', 'icon' => 'flame', 'href' => '/modules/zoe/index.php', 'section' => 'units',
            'match' => ['zoe'], 'keywords' => ['prayer', 'intercessory', 'intercession'],
            'tabs' => ['requests' => 'Church Requests', 'sessions' => 'Prayer Sessions & Campaigns', 'roster' => 'Zoe Roster', 'war_room' => 'The War Room']],

        ['key' => 'departments', 'label' => 'Departments', 'icon' => 'building-2', 'href' => '/modules/departments/index.php', 'section' => 'operations',
            'match' => ['departments'], 'keywords' => ['units', 'teams', 'workforce']],
        ['key' => 'tribes', 'label' => 'Tribes', 'icon' => 'tent', 'href' => '/modules/tribes/index.php', 'section' => 'operations',
            'match' => ['tribes'], 'keywords' => ['camp david', 'cells', 'groups'],
            'tabs' => ['global' => 'Global Dashboard', 'mytribe' => 'My Tribe', 'meetings' => 'Meetings & Rhema', 'roster' => 'Duty Roster']],
        ['key' => 'events', 'label' => 'Events & Attendance', 'icon' => 'calendar-check', 'href' => '/modules/events/index.php', 'section' => 'operations',
            'match' => ['events'], 'keywords' => ['calendar', 'services', 'check-in', 'checkin', 'attendance']],
        ['key' => 'sms_studio', 'label' => 'SMS Studio', 'icon' => 'message-square-text', 'href' => '/modules/sms_studio/index.php', 'section' => 'operations',
            'match' => ['sms_studio'], 'keywords' => ['text', 'messages', 'bulk sms', 'broadcast'], 'tabs' => ['campaigns' => 'Campaigns', 'history' => 'History']],
        ['key' => 'assets', 'label' => 'Asset Management', 'icon' => 'package', 'href' => '/modules/assets/index.php', 'section' => 'operations',
            'match' => ['assets'], 'keywords' => ['inventory', 'equipment', 'gear'], 'tabs' => ['inventory' => 'Master Inventory', 'logs' => 'Activity Logs']],
        ['key' => 'pastoral', 'label' => 'Pastoral Desk', 'icon' => 'messages-square', 'href' => '/modules/pastoral/index.php', 'section' => 'operations',
            'match' => ['pastoral'], 'keywords' => ['questions', 'feedback'],
            'tabs' => ['impressions' => 'Impressions', 'qa' => 'Pastoral Q&A', 'suggestions' => 'Suggestion Box']],
        ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'megaphone', 'href' => '/modules/announcements/index.php', 'section' => 'operations',
            'match' => ['announcements'], 'keywords' => ['news', 'notices', 'bulletin']],
        ['key' => 'testimonies', 'label' => 'Testimonies', 'icon' => 'sparkles', 'href' => '/modules/testimonies/index.php', 'section' => 'operations',
            'match' => ['testimonies'], 'keywords' => ['stories', 'praise reports', 'testimonies admin']],

        ['key' => 'finance', 'label' => 'Finance & Stewardship', 'icon' => 'wallet', 'href' => '/modules/finance/index.php', 'section' => 'stewardship',
            'match' => ['finance'], 'keywords' => ['money', 'giving', 'tithe', 'offering', 'ledger'],
            'tabs' => ['dashboard' => 'IDI Overview', 'transactions' => 'Master Ledger', 'config' => 'System Configuration']],
        ['key' => 'requisition', 'label' => 'Requisitions', 'icon' => 'clipboard-list', 'href' => '/modules/requisition/index.php', 'section' => 'stewardship',
            'match' => ['requisition'], 'keywords' => ['purchase', 'spend', 'approvals', 'expenses'],
            'tabs' => ['requisitions' => 'Requisitions', 'analytics' => 'Analytics']],
        ['key' => 'roles', 'label' => 'Role Management', 'icon' => 'shield-check', 'href' => '/modules/roles/index.php', 'section' => 'stewardship',
            'match' => ['roles'], 'keywords' => ['permissions', 'rbac', 'security', 'access'],
            'tabs' => ['tab-roster' => 'Master Roster', 'tab-audit' => 'Audit Trail']],
        ['key' => 'security', 'label' => 'Security Centre', 'icon' => 'lock-keyhole', 'href' => '/modules/security/index.php', 'section' => 'stewardship',
            'match' => ['security'],
            'keywords' => ['suspend', 'revoke', 'reset password', 'sign-in email', 'lockout', 'kill sessions', 'login attempts', 'audit'],
            'tabs' => ['accounts' => 'Accounts', 'sessions' => 'Live Sessions', 'logins' => 'Login Activity', 'audit' => 'Audit Trail']],
        ['key' => 'alerts', 'label' => 'Alerts Centre', 'icon' => 'bell-ring', 'href' => '/modules/alerts/index.php', 'section' => 'stewardship',
            'match' => ['alerts'], 'keywords' => ['push', 'notifications', 'broadcast', 'announce', 'pwa', 'app', 'devices']],

        // The error and health monitor (its screens arrive with the monitor's
        // PR 2; until then the entry stays hidden). Gate: monitor_user_can()
        // in that module — Super Admin today, see ui_nav_can_access().
        ['key' => 'system_health', 'label' => 'System Health', 'icon' => 'activity', 'href' => '/modules/monitor/index.php', 'section' => 'system',
            'match' => ['monitor'], 'keywords' => ['errors', 'monitor', 'health', 'uptime', 'logs', 'status', 'alerts'], 'when_exists' => true],
        ['key' => 'ui_guide', 'label' => 'Design System', 'icon' => 'palette', 'href' => '/modules/ui_guide/index.php', 'section' => 'system',
            'match' => ['ui_guide'], 'keywords' => ['ui', 'components', 'design', 'style guide', 'tokens']],
    ];
}

/**
 * Everything the navigation needs about the signed-in person, read once per
 * request: their resolved permissions (includes/permissions.php — roles, live
 * department seats, the matrix) and the two module rosters that open a module
 * on their own (Assimilation volunteers, Special Events crew). The other keys
 * are kept for code that has read them from header.php for years.
 */
function ui_nav_context(PDO $pdo): array
{
    static $ctx = null;
    if ($ctx !== null) {
        return $ctx;
    }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $perm = function_exists('perm_current') ? perm_current() : null;

    // Assimilation's volunteer roster is the module's own list of who helps.
    $assimTeam = false;
    try {
        if (!function_exists('assim_is_team_member')) {
            require_once dirname(__DIR__) . '/assimilation_helpers.php';
        }
        $assimTeam = assim_is_team_member($pdo, $uid);
    } catch (Throwable $e) {
        error_log('ui_nav_context assimilation: ' . $e->getMessage());
    }

    // Special Events crew on a live event, from the module's own crew table.
    // Inside try/catch so a missing se_* table can never break the navigation.
    $seCrew = false;
    try {
        if (!function_exists('se_is_crew')) {
            require_once dirname(__DIR__) . '/special_events/bootstrap.php';
        }
        $seCrew = se_is_crew($pdo, $uid);
    } catch (Throwable $e) {
        error_log('ui_nav_context special events: ' . $e->getMessage());
    }

    // Mail: anyone who works from a mailbox (its own grants, through the mail module's one decision, which asks
    // can()), plus mail admins and export approvers. Safe before the mail tables exist.
    $mailNav = false;
    try {
        if (!function_exists('mail_nav_visible')) {
            require_once dirname(__DIR__) . '/mail/bootstrap.php';
        }
        $mailNav = mail_nav_visible($uid);
    } catch (Throwable $e) {
        error_log('ui_nav_context mail: ' . $e->getMessage());
    }

    $seats = $perm['seats'] ?? [];
    $ctx = [
        'user_id' => $uid,
        'perm' => $perm,
        'assim_team' => $assimTeam,
        'se_crew' => $seCrew,
        'mail_nav' => $mailNav,
        'is_super_admin' => !empty($perm['breakglass']),
        'dept_ids' => array_values(array_unique(array_column($seats, 'department_id'))),
        'seat_keys' => array_values(array_unique(array_column($seats, 'seat_key'))),
    ];
    $ctx['assim_manager'] = $perm !== null && perm_ctx_can($perm, 'assimilation', 'manage') || !empty($perm['breakglass']);
    $ctx['assim_nav'] = ui_nav_can_access('assimilation', $ctx);
    $ctx['se_nav'] = ui_nav_can_access('special_events', $ctx);

    return $ctx;
}

/** The permission module behind a navigation entry (the same key, except where noted). */
function ui_nav_module(string $key): string
{
    return ['system_health' => 'monitor'][$key] ?? $key;
}

/**
 * THE ACCESS DECISION for a navigation entry: the permission matrix
 * (perm_ctx_nav_visible(): the link shows when the person may do something
 * beyond their own records, or the page is member-facing), plus the two
 * module rosters. No role name and no department number is consulted here;
 * tests/permissions/ compares the result with the old sidebar, person by
 * person, and every difference is one the owner decided.
 */
function ui_nav_can_access(string $key, array $ctx): bool
{
    $perm = $ctx['perm'] ?? null;
    if (is_array($perm) && function_exists('perm_ctx_nav_visible') && perm_ctx_nav_visible($perm, ui_nav_module($key))) {
        return true;
    }
    if ($key === 'assimilation') {
        return !empty($ctx['assim_team']);
    }
    if ($key === 'special_events') {
        return !empty($ctx['se_crew']);
    }
    if ($key === 'mail') {
        return !empty($ctx['mail_nav']);
    }

    return false;
}

/** Mail: unread Inbox conversations in the mailboxes this person works from (cached 30 s). */
function ui_nav_badge_mail(PDO $pdo, array $ctx): int
{
    return function_exists('mail_nav_unread') ? mail_nav_unread((int) $ctx['user_id']) : 0;
}

/** Reach: unassigned leads + this person's overdue follow-ups. */
function ui_nav_badge_reach(PDO $pdo, array $ctx): int
{
    require_once dirname(__DIR__) . '/reach_helpers.php';
    $counts = reach_sidebar_counts($pdo, (int) $ctx['user_id']);

    return (int) $counts['unassigned_all'] + (int) $counts['my_overdue'];
}

/** Assimilation: overdue follow-ups, plus the pool and untouched watch list for managers. */
function ui_nav_badge_assimilation(PDO $pdo, array $ctx): int
{
    if (!function_exists('assim_sidebar_counts')) {
        require_once dirname(__DIR__) . '/assimilation_helpers.php';
    }
    $counts = assim_sidebar_counts($pdo, (int) $ctx['user_id']);

    return (int) $counts['my_overdue']
        + (!empty($ctx['assim_manager']) ? (int) $counts['pool'] + (int) $counts['watchlist_untouched'] : 0);
}

/**
 * The navigation this person sees, grouped by section, with badges and the
 * active entry resolved. Shape:
 *   [ ['key' => 'growth', 'label' => 'Growth & Retention', 'items' => [ item + 'active' + 'count' ], …], … ]
 */
function ui_nav_visible(PDO $pdo, string $currentModule): array
{
    $ctx = ui_nav_context($pdo);
    $sections = [];
    foreach (ui_nav_sections() as $key => $label) {
        $sections[$key] = ['key' => $key, 'label' => $label, 'items' => []];
    }

    foreach (ui_nav_registry() as $item) {
        if (!empty($item['when_exists']) && !is_file(dirname(__DIR__, 2) . parse_url($item['href'], PHP_URL_PATH))) {
            continue;
        }
        if (!ui_nav_can_access($item['key'], $ctx)) {
            continue;
        }
        $item['active'] = in_array($currentModule, (array) ($item['match'] ?? []), true);
        $item['count'] = 0;
        if (!empty($item['badge']) && is_callable($item['badge'])) {
            try {
                $item['count'] = (int) call_user_func($item['badge'], $pdo, $ctx);
            } catch (Throwable $e) {
                error_log('ui_nav badge ' . $item['key'] . ': ' . $e->getMessage());
            }
        }
        if ($item['key'] === 'assimilation' && !empty($ctx['assim_manager']) && !empty($item['manager_tabs'])) {
            $item['tabs'] = $item['manager_tabs'];
        }
        $sections[$item['section']]['items'][] = $item;
    }

    return array_values(array_filter($sections, static fn ($s) => $s['items'] !== []));
}

/** The Ctrl/Cmd+K index: every visible module, its tabs, and sign-out. */
function ui_nav_search_index(array $visibleSections): array
{
    $index = [];
    foreach ($visibleSections as $section) {
        foreach ($section['items'] as $item) {
            $keywords = array_values((array) ($item['keywords'] ?? []));
            $index[] = [
                'id' => $item['key'], 'type' => 'module', 'label' => $item['label'], 'url' => $item['href'],
                'group' => $section['label'], 'icon' => $item['icon'], 'keywords' => $keywords,
            ];
            foreach ((array) ($item['tabs'] ?? []) as $tabId => $tabLabel) {
                $index[] = [
                    'id' => $item['key'] . ':' . $tabId, 'type' => 'tab',
                    'label' => $item['label'] . ' › ' . $tabLabel,
                    'url' => $item['href'] . '#tab=' . rawurlencode((string) $tabId),
                    'group' => $section['label'], 'icon' => $item['icon'],
                    'keywords' => array_merge($keywords, array_map('strtolower', explode(' ', (string) $tabLabel))),
                ];
            }
        }
    }
    $index[] = [
        'id' => 'action:logout', 'type' => 'action', 'label' => 'Sign out', 'url' => '/auth/logout.php',
        'group' => 'Actions', 'icon' => 'log-out', 'keywords' => ['logout', 'log out', 'sign out', 'exit'],
    ];

    return $index;
}

/** Where "Home" goes: the dashboard for those who have it, otherwise the member's portal. */
function ui_nav_home_href(PDO $pdo): string
{
    return ui_nav_can_access('dashboard', ui_nav_context($pdo)) ? '/index.php' : '/modules/member_portal/index.php';
}
