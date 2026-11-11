<?php
// /modules/mail/index.php
/**
 * Mail (docs/MAIL.md). PR 1 shows the mailboxes a person works from and the latest conversations of each Inbox,
 * read from the ERP's copy; opening and answering mail arrive with PR 2 (reading) and PR 3 (writing). Strict mode
 * (docs/UI.md): behaviour in assets/ui/js/pages/mail.js. Every decision: mail_can().
 *
 * ?box=<mailbox id> picks the mailbox (default: the first one, personal first).
 */
$ui = [
    'title'   => 'Mail',
    'mode'    => 'strict',
    'scripts' => ['/assets/ui/js/pages/mail.js'],
];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail/bootstrap.php';

use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailNotices;
use HOD\Mail\Store;

$uid = (int) ($_SESSION['user_id'] ?? 0);
if (!mail_ready()) {
    echo '<div class="ui-page">' . ui_empty(['icon' => 'mail', 'title' => 'Mail not ready', 'text' => 'Try again after the next update.']) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$boxes = MailAccess::accounts($uid);
$isAdmin = mail_can('admin') || mail_can('export_admin') || mail_can('export_pastoral');
$adminLink = $isAdmin ? ui_button('Manage mailboxes', ['variant' => 'secondary', 'icon' => 'inbox', 'href' => MailNotices::mailboxesUrl()]) : '';

if (!$boxes) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'Mail', 'actions' => $adminLink])
        . ui_empty(['icon' => 'mail', 'title' => 'No mailbox yet',
            'text' => 'Ask a mail admin for one.',
            'actions' => mail_info('Mail admins give mailboxes: a department\'s, a church-wide one such as info@, or a personal firstname.lastname@ address.') . $adminLink]) . '</div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$boxId = (int) ($_GET['box'] ?? 0);
$box = null;
foreach ($boxes as $b) {
    if ($b['id'] === $boxId) {
        $box = $b;
    }
}
$box ??= $boxes[0];
$inbox = Store::folderByRole($box['id'], 'inbox');
$threads = $inbox ? Db::all(
    'SELECT t.id, t.subject, t.snippet, t.participants, t.last_message_at, t.message_count,
            (SELECT COUNT(*) FROM mail_messages m WHERE m.thread_id = t.id AND m.folder_id = ? AND m.is_seen = 0) AS unread,
            (SELECT MAX(m.is_auto) FROM mail_messages m WHERE m.thread_id = t.id AND m.folder_id = ?) AS is_auto
       FROM mail_threads t
      WHERE t.account_id = ?
        AND EXISTS (SELECT 1 FROM mail_messages m WHERE m.thread_id = t.id AND m.folder_id = ?)
   ORDER BY t.last_message_at DESC
      LIMIT 50',
    [$inbox['id'], $inbox['id'], $box['id'], $inbox['id']],
) : [];
$fmt = static fn (?string $at): string => $at ? (substr($at, 0, 10) === date('Y-m-d') ? substr($at, 11, 5) : date('j M', strtotime($at))) : '';
?>
<div class="ui-page">
    <?= ui_page_header([
        'title' => 'Mail',
        'description' => $box['display_name'] . ' · ' . $box['email'],
        'actions' => ui_button('Check mail', ['variant' => 'secondary', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'mail.refresh', 'data-box' => $box['id']]]) . $adminLink,
    ]) ?>

    <?php if (count($boxes) > 1): ?>
    <div class="ui-chips" role="group" aria-label="Mailboxes">
        <?php foreach ($boxes as $b): ?>
            <a class="ui-chip" href="?box=<?= (int) $b['id'] ?>" aria-pressed="<?= $b['id'] === $box['id'] ? 'true' : 'false' ?>"><?= ui_e($b['email']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($box['status'] === 'needs_reset'): ?>
        <?= ui_banner('warning', ['title' => 'Mailbox not updating', 'text' => 'Ask a mail admin to reset its password.']) ?>
    <?php endif; ?>

    <div class="ui-card">
        <div class="ui-card__header ui-card__header--divided"><h2 class="ui-h3">Inbox</h2>
            <span class="ui-hint"><?= $box['last_synced_at'] ? 'Updated ' . ui_e($fmt($box['last_synced_at'])) : 'First copy on its way' ?></span></div>
        <?php if (!$threads): ?>
            <div class="ui-card__body"><?= ui_empty(['icon' => 'inbox', 'title' => $box['last_synced_at'] ? 'Inbox empty' : 'Copying this mailbox',
                'text' => $box['last_synced_at'] ? 'New mail shows here within a minute.' : 'This takes a minute or two.', 'compact' => true]) ?></div>
        <?php else: ?>
        <ul class="ui-list">
            <?php foreach ($threads as $t):
                $people = json_decode((string) $t['participants'], true) ?: [];
                $from = $people[0]['name'] ?? $people[0]['email'] ?? 'Unknown sender';
                $unread = (int) $t['unread'] > 0; ?>
            <li class="ui-list__item">
                <div class="ui-list__main">
                    <span class="ui-list__title"><?= $unread ? '<strong>' . ui_e($from) . '</strong>' : ui_e($from) ?>
                        <?= (int) $t['message_count'] > 1 ? ui_badge((string) (int) $t['message_count'], 'neutral', ['round' => true]) : '' ?>
                        <?= $t['is_auto'] ? ui_badge('Automatic reply', 'neutral') : '' ?></span>
                    <span class="ui-list__meta"><?= ui_e($t['subject'] ?: '(no subject)') ?> · <?= ui_e($t['snippet'] ?? '') ?></span>
                </div>
                <div class="ui-list__end"><span class="ui-hint ui-num"><?= ui_e($fmt($t['last_message_at'])) ?></span><?= $unread ? ' ' . ui_badge('New', 'primary', ['dot' => true]) : '' ?></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?= ui_json_script('mail-page', ['api' => '/api/mail_api.php']) ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
