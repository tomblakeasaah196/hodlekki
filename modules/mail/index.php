<?php
// /modules/mail/index.php
/**
 * Mail (docs/MAIL.md §7c): the reader. Strict mode (docs/UI.md): behaviour in assets/ui/js/pages/mail.js, styles
 * in assets/css/mail.css. Every decision goes through mail_can() / MailAccess (HOD\Mail\Read\Inbox checks them).
 *
 * ?box=<mailbox id>&folder=<role or folder id>&thread=<thread id> (links from alerts use MailNotices::inboxUrl),
 * plus q= (search), show= (filter) and page=. Three panes: mailboxes and folders with counts, the conversation
 * list, the reader (the whole conversation, safe HTML in a sandboxed frame from api/mail_view.php, pictures held
 * back, attachments, "Opened by"). On a phone, the list or the reader. The first screen is drawn here; the page
 * script then swaps the list and the reader with pieces from api/mail_api.php drawn by the same partials.
 *
 * Mounts (HOD\Mail\Read\Partial::mount): composer (PR 3), thread_team (PR 4, shared mailboxes), thread_summary.
 * Until a mount exists nothing that leads to it is drawn. ?compose=<outbox id> opens one of the person's drafts
 * (links from the Outbox page); assets/ui/js/pages/mail-compose.js does nothing on a page without the composer.
 */
$ui = [
    'title'   => 'Mail',
    'mode'    => 'strict',
    'styles'  => ['/assets/css/mail.css'],
    'scripts' => ['/assets/ui/js/pages/mail-compose.js', '/assets/ui/js/pages/mail-team.js', '/assets/ui/js/pages/mail.js'],
    // Each message is shown in a sandboxed frame from api/mail_view.php (this site). Strict pages refuse every
    // frame by default (frame-src 'none'), which showed each message as a blocked page (12 Nov 2026).
    'csp'     => ['frame-src' => ["'self'"]],
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail/bootstrap.php';

use HOD\Mail\Imap\ImapException;
use HOD\Mail\MailError;
use HOD\Mail\MailNotices;
use HOD\Mail\Read\Format;
use HOD\Mail\Read\Inbox;
use HOD\Mail\Read\Partial;
use HOD\Mail\Read\Screen;
use HOD\Mail\Read\ThreadList;
use HOD\Mail\Store;

$uid = (int) ($_SESSION['user_id'] ?? 0);
if (!mail_ready()) {
    echo '<div class="ui-page">' . ui_empty(['icon' => 'mail', 'title' => 'Mail not ready', 'text' => 'Try again after the next update.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$isAdmin = mail_can('admin') || mail_can('export_admin') || mail_can('export_pastoral');
$inbox = Inbox::open($uid, $_GET);
$account = $inbox->account;
$adminLink = $isAdmin ? ui_button('Manage mailboxes', ['variant' => 'ghost', 'icon' => 'settings', 'icon_only' => true,
    'href' => MailNotices::mailboxesUrl($account['id'] ?? null)]) : '';

if (!$account) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'Mail', 'actions' => $adminLink])
        . ui_empty(['icon' => 'mail', 'title' => 'No mailbox yet',
            'text' => 'Ask a mail admin for one.',
            'actions' => mail_info('Mail admins give mailboxes: a department\'s, a church-wide one such as info@, or a personal firstname.lastname@ address.')]) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$folder = $inbox->folder;
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
$filter = (string) ($_GET['show'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$shared = $inbox->shared();
$role = $folder['role'] ?? null;

$list = null;
$reader = null;
$counts = null;
try {
    if ($inbox->thread) {
        try {
            $reader = Screen::thread($inbox, $inbox->thread, null, [], $q !== '');
        } catch (MailError $ex) {
            $inbox->notice = $ex->getMessage();
            $inbox->thread = null;
        }
    }
    $list = Screen::list($inbox, $q, $filter, $page, (int) ($inbox->thread['id'] ?? 0));
    $counts = $inbox->counts();
} catch (ImapException $ex) {
    error_log('Mail: page: server: ' . $ex->getMessage());
    $inbox->notice = 'The mail server did not answer. Try again in a minute.';
}

$filters = ThreadList::filtersFor($account);
$filter = in_array($filter, $filters, true) ? $filter : 'all';
$boxUnread = $inbox->boxUnread();
$canSend = $inbox->allows('send') && Partial::mounted('composer');
$folderKey = $folder ? $inbox->key($folder) : 'inbox';
$mount = [
    'user_id' => $uid,
    'account_id' => $account['id'],
    'folder' => $folderKey,
    'thread_id' => $reader ? (int) $inbox->thread['id'] : null,
    'shared' => $shared,
];
$folderIcon = ['inbox' => 'inbox', 'drafts' => 'file-text', 'sent' => 'send', 'archive' => 'archive', 'spam' => 'shield-alert', 'trash' => 'trash-2'];
$folderHref = fn (array $f) => '?box=' . (int) $account['id'] . '&folder=' . rawurlencode($inbox->key($f));
$boxName = fn (array $a) => Store::isShared($a) ? (string) $a['display_name'] : 'My mailbox';
?>
<div class="ui-page mr-page" data-mail>
    <?= ui_page_header([
        'title' => 'Mail',
        'description' => $account['email'],
        'actions' => ($canSend ? ui_button('New message', ['variant' => 'primary', 'icon' => 'pencil', 'attrs' => ['data-compose' => 'new', 'title' => 'New message (c)']]) : '')
            . ui_button('Check mail', ['variant' => 'ghost', 'icon' => 'refresh-cw', 'icon_only' => true, 'attrs' => ['data-action' => 'refresh']])
            . ($canSend ? ui_button('Outbox', ['variant' => 'ghost', 'icon' => 'clock', 'icon_only' => true, 'href' => '/modules/mail/outbox.php']) : '')
            . ui_button('My mail settings', ['variant' => 'ghost', 'icon' => 'sliders-horizontal', 'icon_only' => true, 'href' => '/modules/mail/settings.php'])
            . ui_button('Shortcuts', ['variant' => 'ghost', 'icon' => 'circle-help', 'icon_only' => true, 'class' => 'mr-hide-phone', 'attrs' => ['data-dialog-open' => 'mail-keys']])
            . $adminLink,
    ]) ?>

    <?php if ($inbox->notice): ?>
        <?= ui_banner('info', ['text' => $inbox->notice]) ?>
    <?php endif; ?>
    <?php if ($account['status'] === 'needs_reset'): ?>
        <?= ui_banner('warning', ['title' => 'Mailbox not updating', 'text' => 'Ask a mail admin to reset its password.']) ?>
    <?php endif; ?>

    <div class="mr-panes<?= $reader ? ' has-reader' : '' ?>" data-panes>
        <nav class="mr-nav" aria-label="Mailboxes and folders">
            <?php if (count($inbox->accounts) > 1): ?>
                <h2 class="mr-nav-title">Mailboxes</h2>
                <ul>
                    <?php foreach ($inbox->accounts as $a): $isBox = $a['id'] === $account['id']; $n = $boxUnread[$a['id']] ?? 0; ?>
                        <li>
                            <a class="mr-nav-btn<?= $isBox ? ' is-active' : '' ?>" href="?box=<?= (int) $a['id'] ?>"<?= $isBox ? ' aria-current="true"' : '' ?>>
                                <?= Format::icon(Store::isShared($a) ? 'users-round' : 'user') ?>
                                <span class="mr-nav-name"><?= ui_e($boxName($a)) ?><span class="mr-nav-email"><?= ui_e($a['email']) ?></span></span>
                                <span class="mr-nav-count" data-box-count="<?= (int) $a['id'] ?>"<?= $n > 0 ? '' : ' hidden' ?>><?= $n ?><span class="sr-only"> unread</span></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <h2 class="mr-nav-title">Folders</h2>
            <ul data-folder-list>
                <?php foreach ($inbox->folders as $f):
                    $isHere = $folder && $f['id'] === $folder['id'];
                    $n = $f['role'] === 'drafts' ? $f['total_count'] : $f['unread_count'];
                    ?>
                    <li data-depth="<?= min(4, $inbox->depth($f)) ?>">
                        <a class="mr-nav-btn<?= $isHere ? ' is-active' : '' ?>" href="<?= ui_e($folderHref($f)) ?>" data-folder-key="<?= ui_e($inbox->key($f)) ?>" data-folder-id="<?= (int) $f['id'] ?>" data-role="<?= ui_e($f['role']) ?>" data-label="<?= ui_e(Store::folderLabel($f)) ?>"<?= $isHere ? ' aria-current="true"' : '' ?>>
                            <?= Format::icon($folderIcon[$f['role']] ?? 'folder') ?>
                            <span class="mr-nav-name"><?= ui_e(Store::folderLabel($f)) ?></span>
                            <span class="mr-nav-count" data-folder-count="<?= (int) $f['id'] ?>"<?= $n > 0 ? '' : ' hidden' ?>><?= (int) $n ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if (!$inbox->folders): ?>
                    <li class="mr-nav-wait">Reading this mailbox’s folders…</li>
                <?php endif; ?>
            </ul>
        </nav>

        <section class="mr-list" aria-label="Conversations">
            <div class="mr-list-head">
                <div class="mr-pickers">
                    <?php if (count($inbox->accounts) > 1): ?>
                        <label class="sr-only" for="mr-box-pick">Mailbox</label>
                        <?php // email_off: Cloudflare's email obfuscation would turn each address into "[email protected]", and its
                              // decoder cannot repair text inside an <option>. The comments tell Cloudflare to leave it alone. ?>
                        <!--email_off-->
                        <select id="mr-box-pick" class="ui-select ui-select--sm" data-box-pick>
                            <?php foreach ($inbox->accounts as $a): $n = $boxUnread[$a['id']] ?? 0; ?>
                                <option value="<?= (int) $a['id'] ?>"<?= $a['id'] === $account['id'] ? ' selected' : '' ?>><?= ui_e($a['email'] . ($n > 0 ? " ({$n})" : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <!--/email_off-->
                    <?php endif; ?>
                    <label class="sr-only" for="mr-folder-pick">Folder</label>
                    <select id="mr-folder-pick" class="ui-select ui-select--sm" data-folder-pick>
                        <?php foreach ($inbox->folders as $f): $n = $f['role'] === 'drafts' ? $f['total_count'] : $f['unread_count']; ?>
                            <option value="<?= ui_e($inbox->key($f)) ?>" data-folder-id="<?= (int) $f['id'] ?>" data-label="<?= ui_e(str_repeat("\u{00A0}\u{00A0}", $inbox->depth($f)) . Store::folderLabel($f)) ?>"<?= $folder && $f['id'] === $folder['id'] ? ' selected' : '' ?>><?= ui_e(str_repeat("\u{00A0}\u{00A0}", $inbox->depth($f)) . Store::folderLabel($f) . ($n > 0 ? " ({$n})" : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <form class="mr-search" role="search" data-search action="" method="get">
                    <input type="hidden" name="box" value="<?= (int) $account['id'] ?>">
                    <input type="hidden" name="folder" value="<?= ui_e($folderKey) ?>" data-search-folder>
                    <label class="sr-only" for="mr-search">Search mail</label>
                    <span class="mr-search-icon"><?= Format::icon('search') ?></span>
                    <input id="mr-search" class="ui-input ui-input--sm" name="q" type="search" value="<?= ui_e($q) ?>" placeholder="Search this mailbox" autocomplete="off" spellcheck="false" enterkeyhint="search">
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm mr-search-clear" data-action="clear-search" aria-label="Clear search"<?= $q === '' ? ' hidden' : '' ?>><?= Format::icon('x') ?></button>
                </form>
                <?php if (count($filters) > 1): ?>
                    <div class="mr-filters" role="group" aria-label="Show">
                        <?php foreach ($filters as $key): ?>
                            <button type="button" class="mr-filter<?= $filter === $key ? ' is-active' : '' ?>" data-filter="<?= ui_e($key) ?>" aria-pressed="<?= $filter === $key ? 'true' : 'false' ?>"><?= ui_e(ThreadList::FILTER_LABELS[$key]) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mr-list-body" data-list aria-busy="false"><?= $list['html'] ?? '' ?></div>
        </section>

        <section class="mr-reader" data-reader aria-label="Reading pane">
            <div data-reader-head><?= $reader['head'] ?? '' ?></div>
            <div class="mr-mounts" data-reader-mounts<?= $reader ? '' : ' hidden' ?>>
                <?php if ($shared): ?>
                    <div data-mount-wrap="thread_team"><?= Partial::mount('thread_team', $mount) ?></div>
                <?php endif; ?>
                <div data-mount-wrap="thread_summary"<?= $role === 'spam' ? ' hidden' : '' ?>><?= Partial::mount('thread_summary', $mount) ?></div>
            </div>
            <div class="mr-messages" data-reader-messages><?= $reader['messages'] ?? '' ?></div>
            <div class="mr-reader-empty" data-reader-empty<?= $reader ? ' hidden' : '' ?>>
                <div>
                    <span class="mr-reader-empty-icon" aria-hidden="true"><?= Format::icon('mail-open', 'ui-icon--xl') ?></span>
                    <p>Pick a conversation</p>
                </div>
            </div>
        </section>
    </div>
</div>

<div data-mount-wrap="composer"><?= Partial::mount('composer', $mount) ?></div>

<?= ui_dialog_start('mail-keys', ['title' => 'Keyboard shortcuts', 'description' => 'They work when you are not typing.', 'size' => 'sm']) ?>
    <dl class="mr-keys">
        <dt><kbd>j</kbd> <kbd>k</kbd></dt><dd>Next, previous</dd>
        <dt><kbd>u</kbd> <kbd>Esc</kbd></dt><dd>Back to list</dd>
        <dt><kbd>/</kbd></dt><dd>Search</dd>
        <?php if ($canSend): ?>
            <dt><kbd>c</kbd></dt><dd>New message</dd>
            <dt><kbd>r</kbd> <kbd>a</kbd> <kbd>f</kbd></dt><dd>Reply, reply all, forward</dd>
        <?php endif; ?>
        <dt><kbd>e</kbd></dt><dd>Archive</dd>
        <dt><kbd>#</kbd></dt><dd>Trash</dd>
        <dt><kbd>!</kbd></dt><dd>Spam</dd>
        <dt><kbd>s</kbd></dt><dd>Flag</dd>
        <dt><kbd>Shift</kbd> <kbd>u</kbd></dt><dd>Mark unread</dd>
        <dt><kbd>?</kbd></dt><dd>These shortcuts</dd>
    </dl>
<?= ui_dialog_end() ?>

<?= ui_json_script('mail-page', [
    'api' => '/api/mail_api.php',
    'accountId' => (int) $account['id'],
    'shared' => $shared,
    'canSend' => $canSend,
    'organise' => $inbox->allows('organise'),
    'folder' => $folder ? ['id' => $folder['id'], 'key' => $folderKey, 'role' => $folder['role'], 'label' => Store::folderLabel($folder)] : null,
    'q' => $q,
    'filter' => $filter,
    'page' => $list['page'] ?? 1,
    'thread' => $reader['thread'] ?? null,
    'stamp' => $counts['stamp'] ?? null,
    'compose' => $canSend && preg_match('/^[0-9a-f-]{36}$/', (string) ($_GET['compose'] ?? '')) ? (string) $_GET['compose'] : null,
    'poll' => 60,
]) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
