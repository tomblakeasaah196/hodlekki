<?php
// /modules/academy/training.php
// HOD Academy builder, one training (?id=): its cards (days, weeks or
// modules) and items (live classes, YouTube/Spotify videos, audio by link or
// upload, readings, assignments with tutor marking), the people on it
// (Assign to groups and named people with a due date, visitor invites,
// each learner's progress), and its settings. Publish opens it to learners.
// Access: the 'trainings' row, church-wide reach (view to look, edit to
// change, create to assign and invite, delete to remove). Data and actions:
// /api/trainings_api.php. Script: assets/ui/js/pages/academy-training.js.
require_once __DIR__ . '/../../includes/trainings/bootstrap.php';
require_once __DIR__ . '/../../includes/trainings/view.php';
$ui = [
    'title' => 'Training builder',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/academy-training.js'],
    // img-src: YouTube's still frames (i.ytimg.com), as on Junior Church (monitor error #98)
    'csp' => ['frame-src' => TRAININGS_FRAME_HOSTS, 'img-src' => ['https://i.ytimg.com']],
];
require_once '../../includes/header.php';
require_can('trainings', 'view', 'church');

$tid = (int) ($_GET['id'] ?? 0);
$t = trainings_ready($pdo) ? trainings_get($pdo, $tid) : null;
if ($t === null) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'Not found', 'back' => ['href' => '/modules/academy/', 'label' => 'HOD Academy']])
        . ui_empty(['icon' => 'graduation-cap', 'title' => 'Training not found', 'text' => 'It may have been removed.',
            'actions' => ui_button('All trainings', ['variant' => 'primary', 'href' => '/modules/academy/'])]) . '</div>';
    require '../../includes/footer.php';
    exit;
}
$canEdit = can('trainings', 'edit', 'church');
$canAssign = can('trainings', 'create', 'church');
$canDelete = can('trainings', 'delete', 'church');
$canApprove = can('trainings', 'approve', 'church');   // confirm graduates; issue, reissue and revoke certificates
trainings_sync_newcomers($pdo, $tid);
$sections = trainings_outline($pdo, $tid);
$learners = trainings_learners($pdo, $t);
$history = trainings_assignment_history($pdo, $tid);
$options = $canAssign ? trainings_audience_options($pdo) : ['statuses' => [], 'departments' => []];
$dated = $t['length'] !== 'self_paced';
$missing = 0;
$itemMap = [];   // id => what the item dialog prefills
foreach ($sections as $s) {
    foreach ($s['items'] as $i) {
        $missing += $i['needs_content'] ? 1 : 0;
        $itemMap[$i['id']] = ['id' => $i['id'], 'section_id' => $i['section_id'], 'type' => $i['type'], 'title' => $i['title'],
            'starts_at' => $i['starts_at'], 'minutes' => $i['minutes'], 'required' => $i['required'],
            'details' => trainings_details_for_browser($i['details'])];
    }
}
$statusTone = ['published' => 'success', 'draft' => 'neutral', 'archived' => 'warning'];
$sub = TRAININGS_STATUSES[$t['status']] . ' · ' . (TRAININGS_LENGTHS[$t['length']] ?? '')
    . ($dated && $t['starts_on'] ? ' · ' . trainings_view_range($t['starts_on'], $t['ends_on']) : '');

$actions = ui_button('Preview', ['variant' => 'ghost', 'icon' => 'eye', 'href' => '/modules/trainings/?t=' . $tid]);
$actions .= $canEdit && function_exists('exam_url_bank') ? ui_button('Question bank', ['variant' => 'ghost', 'icon' => 'clipboard-list', 'href' => exam_url_bank($tid)]) : '';   // docs/EXAMS.md
$actions .= $canEdit && function_exists('exam_url_ai') ? ui_button('AI help', ['variant' => 'ghost', 'icon' => 'sparkles', 'href' => exam_url_ai($tid)]) : '';   // PR 4b (docs/EXAMS.md §7)
if ($canEdit && $t['status'] !== 'published') {
    $actions .= ui_button('Publish', ['variant' => 'primary', 'icon' => 'send', 'attrs' => ['data-action' => 'trb.status', 'data-status' => 'publish']]);
} elseif ($canEdit) {
    $actions .= ui_button('Unpublish', ['variant' => 'secondary', 'attrs' => ['data-action' => 'trb.status', 'data-status' => 'unpublish']]);
}
?>
<div class="ui-page">
    <?= ui_page_header(['title' => $t['title'], 'description' => $sub,
        'info' => 'Cards hold the items in order; a dated card shows its items by time. Learners mark videos, audio and readings done themselves; '
            . 'a tutor ticks live classes attended and marks assignments. Publish opens it to the people on it (and the catalogue, if ticked in Settings).',
        'back' => ['href' => '/modules/academy/', 'label' => 'HOD Academy'], 'actions' => $actions]) ?>
<?php if ($t['status'] === 'archived'): ?>
    <?= ui_banner('warning', ['title' => 'Archived', 'text' => 'Publish it to bring it back.', 'info' => 'Learners no longer see it. Their progress is kept.']) ?>
<?php elseif ($missing > 0): ?>
    <?= ui_banner('info', ['title' => $missing === 1 ? '1 item needs content' : $missing . ' items need content', 'text' => 'Add links or text, then publish.']) ?>
<?php endif; ?>
    <?= ui_tabs(['build' => 'Build', 'people' => ['label' => 'People', 'count' => count($learners)], 'settings' => 'Settings'], 'build') ?>

    <section data-tab-panel="build" id="panel-build" role="tabpanel" aria-labelledby="tab-build" class="ui-tab-panel ui-stack ui-stack--md">
<?php foreach ($sections as $n => $s):
        if ((int) $s['id'] === 0) {
            continue;
        } ?>
        <section class="ui-card" aria-labelledby="trb-s-<?= (int) $s['id'] ?>">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title" id="trb-s-<?= (int) $s['id'] ?>"><?= ui_e($s['title']) ?></h2>
                    <span class="ui-meta"><?= ui_e(trainings_view_range($s['date_from'], $s['date_to'])) ?></span>
                </div>
<?php if ($canEdit): ?>
                <div class="flex items-center gap-1">
                    <?= ui_button('Edit card', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'pencil', 'icon_only' => true, 'attrs' => ['data-action' => 'trb.section', 'data-section' => $s['id']]]) ?>
                    <?= ui_button('Move up', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-up', 'icon_only' => true, 'disabled' => $n === 0,
                        'attrs' => ['data-action' => 'trb.sectionMove', 'data-section' => $s['id'], 'data-dir' => '-1']]) ?>
                    <?= ui_button('Move down', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-down', 'icon_only' => true,
                        'attrs' => ['data-action' => 'trb.sectionMove', 'data-section' => $s['id'], 'data-dir' => '1']]) ?>
                    <?= ui_button('Remove card', ['variant' => 'danger-ghost', 'size' => 'sm', 'icon' => 'trash-2', 'icon_only' => true,
                        'attrs' => ['data-action' => 'trb.sectionDelete', 'data-section' => $s['id'], 'data-count' => count($s['items'])]]) ?>
                </div>
<?php endif; ?>
            </div>
<?php if ($s['items'] === []): ?>
            <div class="ui-card__body"><p class="ui-meta">No items yet.</p></div>
<?php else: ?>
            <ul class="ui-list">
<?php foreach ($s['items'] as $i):
            $when = trainings_view_when($i['starts_at'], $i['minutes']); ?>
                <li class="ui-list__item">
                    <span class="ui-list__icon"><?= ui_icon(trainings_view_icon($i['type'])) ?></span>
                    <span class="ui-list__main">
                        <span class="ui-list__title"><?= ui_e($i['title']) ?></span>
                        <span class="ui-list__meta"><?= ui_e((TRAININGS_ITEM_TYPES[$i['type']] ?? $i['type']) . ($when !== '' ? ' · ' . $when : '') . ($i['required'] ? '' : ' · Optional')) ?></span>
                    </span>
                    <span class="ui-list__end">
                        <?= $i['needs_content'] ? ui_badge('Needs content', 'warning') : '' ?>
<?php if ($i['type'] === 'exam' && $canEdit && ($setup = trainings_exam_setup_url($i['id'])) !== null): ?>
                        <?= ui_button('Set up exam', ['variant' => 'soft', 'size' => 'sm', 'icon' => 'clipboard-list', 'href' => $setup]) ?>
<?php endif; ?>
<?php if ($i['type'] === 'live' && function_exists('training_live_url')): ?>
                        <?= ui_button(($i['details']['mode'] ?? '') === 'online' ? 'Meet' : 'Attendance', ['variant' => 'soft', 'size' => 'sm',
                            'icon' => ($i['details']['mode'] ?? '') === 'online' ? 'video' : 'users', 'href' => training_live_url($i['id'])]) ?>
<?php endif; ?>
<?php if ($canEdit): ?>
                        <?= ui_button('Edit item', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'pencil', 'icon_only' => true, 'attrs' => ['data-action' => 'trb.item', 'data-item' => $i['id']]]) ?>
                        <?= ui_button('Move up', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-up', 'icon_only' => true, 'attrs' => ['data-action' => 'trb.itemMove', 'data-item' => $i['id'], 'data-dir' => '-1']]) ?>
                        <?= ui_button('Move down', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-down', 'icon_only' => true, 'attrs' => ['data-action' => 'trb.itemMove', 'data-item' => $i['id'], 'data-dir' => '1']]) ?>
                        <?= ui_button('Remove item', ['variant' => 'danger-ghost', 'size' => 'sm', 'icon' => 'trash-2', 'icon_only' => true, 'attrs' => ['data-action' => 'trb.itemDelete', 'data-item' => $i['id']]]) ?>
<?php endif; ?>
                    </span>
                </li>
<?php if ($i['type'] === 'reading' && !empty($i['details']['deck'])): ?>
                <li class="ui-list__item block">
                    <details>
                        <summary class="ui-meta cursor-pointer">Show slides</summary>
                        <div class="pt-3"><?= trainings_view_deck((string) $i['details']['deck'], $i['title'], (string) ($i['details']['deck_name'] ?? ''),
                            (int) ($i['details']['slides'] ?? 0)) ?></div>
                    </details>
                </li>
<?php endif; ?>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
<?php if ($canEdit): ?>
            <div class="ui-card__footer flex flex-wrap gap-2">
                <?= ui_button('Add item', ['variant' => 'soft', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'trb.item', 'data-section' => $s['id']]]) ?>
                <?= ui_button('Add slides', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'upload', 'attrs' => ['data-action' => 'trb.slides', 'data-section' => $s['id']]]) ?>
            </div>
<?php endif; ?>
        </section>
<?php endforeach; ?>
<?php if ($canEdit): ?>
        <div><?= ui_button($t['length'] === 'weeks' ? 'Add week' : ($t['length'] === 'self_paced' ? 'Add module' : 'Add day'),
            ['variant' => 'secondary', 'icon' => 'plus', 'attrs' => ['data-action' => 'trb.sectionAdd']]) ?></div>
<?php endif; ?>
    </section>

    <section data-tab-panel="people" id="panel-people" role="tabpanel" aria-labelledby="tab-people" class="ui-tab-panel ui-stack ui-stack--md" hidden>
<?php if ($canAssign): ?>
        <div class="ui-toolbar">
            <div class="ui-toolbar__grow"><p class="ui-meta">Assign groups or people; invite visitors.</p></div>
            <div class="ui-toolbar__end">
                <?= ui_button('Invite visitor', ['variant' => 'secondary', 'icon' => 'mail', 'attrs' => ['data-action' => 'trb.invite']]) ?>
                <?= ui_button('Assign', ['variant' => 'primary', 'icon' => 'user-round-plus', 'attrs' => ['data-dialog-open' => 'trb-assign']]) ?>
            </div>
        </div>
<?php endif; ?>
<?php if ($learners === []): ?>
        <?= ui_empty(['icon' => 'users', 'title' => 'Nobody on it yet', 'text' => 'Assign it, or tick the catalogue in Settings.']) ?>
<?php else: ?>
        <div class="ui-card">
            <div class="ui-table-wrap border-0">
                <table class="ui-table ui-table--stack">
                    <thead><tr><th scope="col">Learner</th><th scope="col">Progress</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col" class="is-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
<?php foreach ($learners as $l): ?>
                        <tr>
                            <td data-label="Learner"><span class="ui-table__primary"><?= ui_e($l['name']) ?></span>
                                <span class="ui-table__secondary"><?= ui_e($l['person_status'] . ' · ' . ['self' => 'Joined', 'assigned' => 'Assigned', 'invite' => 'Invited'][$l['source']]) ?></span></td>
                            <td data-label="Progress"><span class="ui-num"><?= (int) $l['done'] ?> / <?= (int) $l['required'] ?></span>
                                <progress class="ui-progress ui-progress--sm" max="100" value="<?= (int) $l['percent'] ?>" aria-label="Progress"><?= (int) $l['percent'] ?>%</progress></td>
                            <td data-label="Due"><?= $l['due_on'] ? ui_e(trainings_view_date($l['due_on'])) : '—' ?></td>
                            <td data-label="Status">
                                <?= $l['status'] === 'completed' ? ui_badge($l['confirmed_at'] ? 'Confirmed' : 'Completed', 'success', ['dot' => true])
                                    : ($l['due_on'] !== null && $l['due_on'] < trainings_today() ? ui_badge('Overdue', 'warning', ['dot' => true]) : ui_badge('Active', 'info', ['dot' => true])) ?>
                                <?= $l['to_mark'] > 0 ? ui_badge('To mark', 'primary') : '' ?>
                                <?= !$l['signin'] ? ui_badge($l['invited_at'] ? 'Invited' : 'No sign-in', 'neutral') : '' ?>
                                <?= $l['certificate'] !== '' ? ui_badge($l['certificate'], 'success', ['class' => 'font-mono']) : '' ?>
                            </td>
                            <td class="is-actions">
                                <?= ui_button('Open progress', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'eye', 'icon_only' => true, 'attrs' => ['data-action' => 'trb.progress', 'data-enrolment' => $l['id']]]) ?>
<?php if ($l['certificate'] !== ''): ?>
                                <?= ui_button('Download certificate', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'file-down', 'icon_only' => true,
                                    'href' => '/api/trainings_certificate.php?e=' . (int) $l['id']]) ?>
<?php endif; ?>
<?php if ($canApprove && $t['gives_certificate'] && $l['status'] === 'completed'): ?>
                                <?= ui_button($l['certificate'] !== '' ? 'Reissue certificate' : 'Issue certificate', ['variant' => 'ghost', 'size' => 'sm',
                                    'icon' => $l['certificate'] !== '' ? 'refresh-cw' : 'star', 'icon_only' => true,
                                    'attrs' => ['data-action' => 'trb.certIssue', 'data-enrolment' => $l['id'], 'data-name' => $l['name'], 'data-has' => $l['certificate'] !== '' ? '1' : '0']]) ?>
<?php if ($l['certificate'] !== ''): ?>
                                <?= ui_button('Revoke certificate', ['variant' => 'danger-ghost', 'size' => 'sm', 'icon' => 'circle-x', 'icon_only' => true,
                                    'attrs' => ['data-action' => 'trb.certRevoke', 'data-enrolment' => $l['id'], 'data-name' => $l['name']]]) ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($canAssign && !$l['signin']): ?>
                                <?= ui_button('Send invite', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'mail', 'icon_only' => true,
                                    'attrs' => ['data-action' => 'trb.invite', 'data-user' => $l['user_id'], 'data-name' => $l['name']]]) ?>
<?php endif; ?>
<?php if ($canEdit): ?>
                                <?= ui_button('Take off', ['variant' => 'danger-ghost', 'size' => 'sm', 'icon' => 'trash-2', 'icon_only' => true,
                                    'attrs' => ['data-action' => 'trb.remove', 'data-enrolment' => $l['id'], 'data-name' => $l['name']]]) ?>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php endif; ?>
<?php if ($history !== []): ?>
        <section class="ui-card" aria-labelledby="trb-history">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="trb-history">Assigned</h2></div>
            <ul class="ui-list">
<?php foreach ($history as $h): ?>
                <li class="ui-list__item">
                    <span class="ui-list__icon"><?= ui_icon('user-round-plus') ?></span>
                    <span class="ui-list__main">
                        <span class="ui-list__title"><?= ui_e($h['who']) ?></span>
                        <span class="ui-list__meta"><?= ui_e($h['people'] . ($h['people'] === 1 ? ' person' : ' people') . ' · ' . trainings_view_date($h['at'])
                            . ($h['by'] !== '' ? ' · ' . $h['by'] : '') . ($h['keep'] ? ' · Keeps enrolling newcomers' : '')) ?></span>
                    </span>
                </li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endif; ?>
    </section>

    <section data-tab-panel="settings" id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" class="ui-tab-panel ui-stack ui-stack--md" hidden>
        <form class="ui-card" id="trb-settings-form" data-action="trb.settings" data-ui-validate novalidate>
            <div class="ui-card__body ui-stack ui-stack--md">
                <input type="hidden" name="training_id" value="<?= $tid ?>">
                <?= ui_field('text', ['name' => 'title', 'label' => 'Name', 'required' => true, 'value' => $t['title'], 'attrs' => ['maxlength' => 160, 'disabled' => !$canEdit]]) ?>
                <?= ui_field('textarea', ['name' => 'summary', 'label' => 'Summary', 'optional' => true, 'value' => (string) ($t['summary'] ?? ''),
                    'info' => 'Learners see it under the (i) by the title, and in the catalogue.', 'attrs' => ['rows' => 3, 'maxlength' => 600, 'disabled' => !$canEdit]]) ?>
                <div class="ui-form-grid">
<?php if ($dated): ?>
                    <?= ui_field('date', ['name' => 'starts_on', 'label' => 'First day', 'required' => true, 'value' => (string) $t['starts_on'],
                        'info' => 'Moving it moves every card and every class time with it.', 'attrs' => ['disabled' => !$canEdit]]) ?>
<?php endif; ?>
                    <?= ui_field('number', ['name' => 'live_percent', 'label' => 'Live classes to attend (%)', 'value' => (string) $t['pass_rule']['live_percent'],
                        'info' => 'To complete it, a learner attends at least this share of the required live classes, and finishes every other required item.',
                        'attrs' => ['min' => 0, 'max' => 100, 'inputmode' => 'numeric', 'disabled' => !$canEdit]]) ?>
                </div>
                <label class="ui-check"><input type="checkbox" name="catalogue" value="1"<?= $t['catalogue'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
                    <span>Show in the catalogue<span class="ui-check__hint">Anyone signed in can join it.</span></span></label>
                <label class="ui-check"><input type="checkbox" name="gives_certificate" value="1"<?= $t['gives_certificate'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
                    <span>Gives a certificate<span class="ui-check__hint">Certificates arrive in a later update.</span></span></label>
            </div>
<?php if ($canEdit): ?>
            <div class="ui-card__footer flex flex-wrap gap-2 justify-between">
                <div class="flex gap-2">
<?php if ($t['status'] !== 'archived'): ?>
                    <?= ui_button('Archive', ['variant' => 'ghost', 'icon' => 'archive', 'attrs' => ['data-action' => 'trb.status', 'data-status' => 'archive']]) ?>
<?php endif; ?>
<?php if ($canDelete): ?>
                    <?= ui_button('Delete training', ['variant' => 'danger-ghost', 'icon' => 'trash-2', 'attrs' => ['data-action' => 'trb.delete', 'data-learners' => count($learners)]]) ?>
<?php endif; ?>
                </div>
                <?= ui_button('Save settings', ['variant' => 'primary', 'type' => 'submit']) ?>
            </div>
<?php endif; ?>
        </form>
    </section>
</div>

<?php if ($canEdit): ?>
<?= ui_dialog_start('trb-item', ['title' => 'Item', 'size' => 'lg']) ?>
<form id="trb-item-form" class="ui-form" data-action="trb.itemSave" data-ui-validate novalidate>
    <input type="hidden" name="training_id" value="<?= $tid ?>">
    <input type="hidden" name="id" value="0">
    <div class="ui-form-grid">
        <?= ui_field('select', ['name' => 'type', 'id' => 'trb-type', 'label' => 'Kind', 'required' => true,
            'options' => array_intersect_key(TRAININGS_ITEM_TYPES, array_flip(trainings_builder_types())), 'attrs' => ['data-action-change' => 'trb.itemType']]) ?>
        <?= ui_field('text', ['name' => 'title', 'id' => 'trb-title', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 160]]) ?>
        <?= ui_field('select', ['name' => 'section_id', 'id' => 'trb-item-section', 'label' => 'Card', 'required' => true,
            'options' => array_column(array_filter($sections, static fn ($s) => (int) $s['id'] > 0), 'title', 'id'), 'attrs' => ['data-action-change' => 'trb.itemType']]) ?>
        <?= ui_field('date', ['name' => 'date', 'id' => 'trb-date', 'label' => 'Day', 'class' => 'trb-when-week', 'optional' => true]) ?>
        <?= ui_field('time', ['name' => 'time', 'id' => 'trb-time', 'label' => 'Time', 'class' => 'trb-when', 'optional' => true]) ?>
        <?= ui_field('number', ['name' => 'minutes', 'id' => 'trb-minutes', 'label' => 'Minutes', 'optional' => true, 'attrs' => ['min' => 1, 'max' => 720, 'inputmode' => 'numeric']]) ?>
    </div>
    <label class="ui-check"><input type="checkbox" name="required" value="1" checked><span>Required<span class="ui-check__hint">Counts towards completing the training.</span></span></label>

    <fieldset class="ui-fieldset" data-trb-type="live" hidden>
        <legend class="ui-legend">Live class</legend>
        <label class="ui-check"><input type="radio" name="live_mode" value="in_person" checked data-action-change="trb.itemType"><span>In person</span></label>
        <label class="ui-check"><input type="radio" name="live_mode" value="online" data-action-change="trb.itemType"><span>Online</span></label>
        <?= ui_field('text', ['name' => 'venue', 'id' => 'trb-venue', 'label' => 'Venue', 'optional' => true, 'class' => 'trb-inperson', 'attrs' => ['maxlength' => 160]]) ?>
        <?= ui_field('url', ['name' => 'link', 'id' => 'trb-link', 'label' => 'Meeting link', 'optional' => true, 'class' => 'trb-online', 'placeholder' => 'https://',
            'info' => 'Paste the Google Meet or Zoom link for now. Creating Meet links from here comes in a later update.']) ?>
        <?= ui_field('number', ['name' => 'present_minutes', 'id' => 'trb-present', 'label' => 'Present after (minutes)', 'value' => '30',
            'info' => 'How long someone must be in the class to count as present.', 'attrs' => ['min' => 1, 'max' => 720, 'inputmode' => 'numeric']]) ?>
    </fieldset>

    <fieldset class="ui-fieldset" data-trb-type="video" hidden>
        <legend class="ui-legend">Video</legend>
        <?= ui_field('url', ['name' => 'url', 'id' => 'trb-video-url', 'label' => 'YouTube or Spotify link', 'placeholder' => 'https://']) ?>
    </fieldset>

    <fieldset class="ui-fieldset" data-trb-type="audio" hidden>
        <legend class="ui-legend">Audio</legend>
        <label class="ui-check"><input type="checkbox" class="ui-switch" name="audio_source" value="file" data-action-change="trb.itemType"><span>Upload an audio file instead</span></label>
        <?= ui_field('url', ['name' => 'url', 'id' => 'trb-audio-url', 'label' => 'Spotify or YouTube link', 'class' => 'trb-audio-link', 'placeholder' => 'https://']) ?>
        <?= ui_field('file', ['name' => 'audio_file', 'id' => 'trb-audio-file', 'label' => 'Audio file', 'class' => 'trb-audio-file', 'hint' => 'MP3 or M4A, up to 50 MB.',
            'attrs' => ['accept' => '.mp3,.m4a,audio/mpeg,audio/mp4']]) ?>
        <p class="ui-meta" data-trb-current="audio"></p>
    </fieldset>

    <fieldset class="ui-fieldset" data-trb-type="reading" hidden>
        <legend class="ui-legend">Reading</legend>
        <?= ui_field('textarea', ['name' => 'text', 'id' => 'trb-text', 'label' => 'Text', 'optional' => true, 'attrs' => ['rows' => 8, 'maxlength' => 20000]]) ?>
        <?= ui_field('file', ['name' => 'pdf_file', 'id' => 'trb-pdf', 'label' => 'PDF', 'optional' => true, 'hint' => 'Up to 20 MB.', 'attrs' => ['accept' => '.pdf,application/pdf']]) ?>
        <label class="ui-check" data-trb-current="pdf" hidden><input type="checkbox" name="remove_pdf" value="1"><span>Remove the PDF<span class="ui-check__hint" data-trb-current-name></span></span></label>
        <?= ui_field('file', ['name' => 'pptx_file', 'id' => 'trb-pptx', 'label' => 'PowerPoint', 'optional' => true, 'hint' => '.pptx, up to 30 MB.',
            'info' => 'Learners see the slides in the page (through Microsoft\'s Office viewer) and can download them.',
            'attrs' => ['accept' => '.pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation']]) ?>
        <label class="ui-check" data-trb-current="deck" hidden><input type="checkbox" name="remove_deck" value="1"><span>Remove the slides<span class="ui-check__hint" data-trb-current-name></span></span></label>
    </fieldset>

    <fieldset class="ui-fieldset" data-trb-type="exam" hidden>
        <legend class="ui-legend">Exam</legend>
        <p class="ui-meta">Save it, then press Set up exam. <?= ui_info('Questions, the time window, the pass mark and the rules live on the exam\'s own page.', 'About exams') ?></p>
    </fieldset>

    <fieldset class="ui-fieldset" data-trb-type="assignment" hidden>
        <legend class="ui-legend">Assignment</legend>
        <?= ui_field('select', ['name' => 'assignment_kind', 'id' => 'trb-akind', 'label' => 'Kind', 'options' => ['written' => 'Written work', 'sermon_review' => 'Sermon review'],
            'attrs' => ['data-action-change' => 'trb.itemType']]) ?>
        <?= ui_field('textarea', ['name' => 'instructions', 'id' => 'trb-instructions', 'label' => 'Instructions', 'optional' => true, 'attrs' => ['rows' => 3, 'maxlength' => 2000]]) ?>
        <?= ui_field('textarea', ['name' => 'prompts', 'id' => 'trb-prompts', 'label' => 'Questions', 'optional' => true,
            'info' => 'One per line. The learner answers each one. For a sermon review: the main points, the scriptures used, how it applies to me.',
            'attrs' => ['rows' => 4]]) ?>
        <div class="ui-form-grid">
            <?= ui_field('number', ['name' => 'max_mark', 'id' => 'trb-max', 'label' => 'Marked out of', 'value' => '10', 'attrs' => ['min' => 1, 'max' => 100, 'inputmode' => 'numeric']]) ?>
            <?= ui_field('number', ['name' => 'pass_mark', 'id' => 'trb-pass', 'label' => 'Pass mark', 'value' => '5', 'attrs' => ['min' => 0, 'max' => 100, 'inputmode' => 'numeric']]) ?>
        </div>
        <div class="ui-stack ui-stack--sm trb-sermon">
            <label class="ui-check"><input type="checkbox" class="ui-switch" name="media_source" value="file" data-action-change="trb.itemType"><span>Upload an audio file instead</span></label>
            <?= ui_field('url', ['name' => 'media_url', 'id' => 'trb-media-url', 'label' => 'Sermon link', 'class' => 'trb-media-link', 'placeholder' => 'https://',
                'info' => 'A Spotify or YouTube link. It plays in the page.']) ?>
            <?= ui_field('file', ['name' => 'media_file', 'id' => 'trb-media-file', 'label' => 'Sermon audio', 'class' => 'trb-media-file', 'hint' => 'MP3 or M4A, up to 50 MB.',
                'attrs' => ['accept' => '.mp3,.m4a,audio/mpeg,audio/mp4']]) ?>
            <p class="ui-meta" data-trb-current="media"></p>
        </div>
    </fieldset>
</form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save item', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'trb-item-form']])) ?>

<?= ui_dialog_start('trb-slides', ['title' => 'Add slides', 'description' => 'One reading per PowerPoint, in name order.']) ?>
<form id="trb-slides-form" class="ui-form" data-action="trb.slidesSave" novalidate>
    <input type="hidden" name="training_id" value="<?= $tid ?>">
    <?= ui_field('select', ['name' => 'section_id', 'id' => 'trb-slides-section', 'label' => 'Card', 'required' => true,
        'options' => array_column(array_filter($sections, static fn ($s) => (int) $s['id'] > 0), 'title', 'id')]) ?>
    <?= ui_field('file', ['name' => 'decks', 'id' => 'trb-slides-files', 'label' => 'PowerPoint files', 'required' => true, 'hint' => '.pptx, up to 30 MB each.',
        'info' => 'Each file becomes one reading, named from its file name, added at the end of the card in name order (Day 1, Day 2 …).',
        'attrs' => ['multiple' => true, 'accept' => '.pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'data-action-change' => 'trb.slidesPick']]) ?>
    <ol class="ui-stack ui-stack--sm list-decimal pl-5 text-sm text-ink" data-trb-slides-list></ol>
    <p class="ui-meta" data-trb-slides-progress aria-live="polite"></p>
</form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Add slides', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'upload', 'attrs' => ['form' => 'trb-slides-form']])) ?>

<?= ui_dialog_start('trb-section', ['title' => 'Edit card', 'size' => 'sm']) ?>
<form id="trb-section-form" class="ui-form" data-action="trb.sectionSave" data-ui-validate novalidate>
    <input type="hidden" name="training_id" value="<?= $tid ?>">
    <input type="hidden" name="section_id" value="0">
    <?= ui_field('text', ['name' => 'title', 'id' => 'trb-s-title', 'label' => 'Name', 'required' => true, 'attrs' => ['maxlength' => 120]]) ?>
    <?= ui_field('date', ['name' => 'date_from', 'id' => 'trb-s-date', 'label' => 'Date', 'class' => 'trb-s-dated',
        'info' => 'For a week, its first day. Class times on the card move with it.']) ?>
</form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save card', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'trb-section-form']])) ?>
<?php endif; ?>

<?php if ($canAssign): ?>
<?= ui_dialog_start('trb-assign', ['title' => 'Assign training', 'description' => 'Everyone you pick is enrolled now.', 'size' => 'lg']) ?>
<form id="trb-assign-form" class="ui-form" data-action="trb.assign" novalidate>
    <input type="hidden" name="training_id" value="<?= $tid ?>">
    <label class="ui-check"><input type="checkbox" name="workers" value="1"><span>All workers<span class="ui-check__hint">Everyone serving in a department.</span></span></label>
    <fieldset class="ui-fieldset">
        <legend class="ui-legend">Status</legend>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
<?php foreach ($options['statuses'] as $st): ?>
            <label class="ui-check"><input type="checkbox" name="statuses[]" value="<?= ui_e($st['value']) ?>"><span><?= ui_e($st['label']) ?> <span class="ui-meta ui-num">(<?= (int) $st['count'] ?>)</span></span></label>
<?php endforeach; ?>
        </div>
    </fieldset>
    <fieldset class="ui-fieldset">
        <legend class="ui-legend">Departments</legend>
        <div class="ui-search mb-2"><?= ui_icon('search') ?><input class="ui-input" type="search" aria-label="Filter departments" placeholder="Filter" data-action-input="trb.deptFilter"></div>
        <div class="max-h-56 overflow-y-auto ui-stack ui-stack--sm" data-trb-depts>
<?php foreach ($options['departments'] as $d): ?>
            <label class="ui-check" data-name="<?= ui_e(mb_strtolower($d['label'])) ?>"><input type="checkbox" name="departments[]" value="<?= (int) $d['value'] ?>"><span><?= ui_e($d['label']) ?></span></label>
<?php endforeach; ?>
        </div>
    </fieldset>
    <fieldset class="ui-fieldset">
        <legend class="ui-legend">People</legend>
        <div class="ui-search"><?= ui_icon('search') ?><input class="ui-input" type="search" id="trb-people-q" aria-label="Find people" placeholder="Name, phone or email"
            data-action-input="trb.people" data-trb-picker="assign" autocomplete="off"></div>
        <ul class="ui-list" data-trb-results="assign" hidden></ul>
        <div class="ui-chips flex-wrap" data-trb-chosen="assign"></div>
    </fieldset>
    <div class="ui-form-grid">
        <?= ui_field('date', ['name' => 'due_on', 'id' => 'trb-due', 'label' => 'Due', 'optional' => true, 'attrs' => ['min' => trainings_today()]]) ?>
    </div>
    <label class="ui-check"><input type="checkbox" name="keep" value="1"><span>Keep enrolling newcomers<span class="ui-check__hint">Someone who joins these groups later is enrolled too.</span></span></label>
</form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Assign', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'trb-assign-form']])) ?>

<?= ui_dialog_start('trb-invite', ['title' => 'Invite visitor', 'description' => 'One tap confirms their email and opens Trainings.']) ?>
<form id="trb-invite-form" class="ui-form" data-action="trb.inviteSend" data-ui-validate novalidate>
    <input type="hidden" name="training_id" value="<?= $tid ?>">
    <input type="hidden" name="user_id" value="">
    <div class="ui-field" data-trb-invite-pick>
        <label class="ui-label" for="trb-invite-q">Person</label>
        <div class="ui-search"><?= ui_icon('search') ?><input class="ui-input" type="search" id="trb-invite-q" placeholder="Name, phone or email"
            data-action-input="trb.people" data-trb-picker="invite" autocomplete="off"></div>
        <ul class="ui-list" data-trb-results="invite" hidden></ul>
    </div>
    <div class="ui-chips flex-wrap" data-trb-chosen="invite"></div>
    <?= ui_field('email', ['name' => 'email', 'id' => 'trb-invite-email', 'label' => 'Their email', 'required' => true, 'autocomplete' => 'off']) ?>
    <?= ui_field('date', ['name' => 'due_on', 'id' => 'trb-invite-due', 'label' => 'Due', 'optional' => true, 'attrs' => ['min' => trainings_today()]]) ?>
</form>
<?= ui_dialog_end(ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Send invite', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send', 'attrs' => ['form' => 'trb-invite-form']])) ?>
<?php endif; ?>

<?= ui_dialog_start('trb-progress', ['title' => 'Learner progress', 'size' => 'drawer']) ?>
<div class="ui-stack ui-stack--md" data-trb-progress><p class="ui-meta">Loading…</p></div>
<?= ui_dialog_end() ?>

<?= ui_json_script('trb-data', [
    'trainingId' => $tid,
    'status' => $t['status'],
    'canEdit' => $canEdit,
    'sections' => array_values(array_map(static fn ($s) => ['id' => (int) $s['id'], 'kind' => (string) $s['kind'], 'title' => (string) $s['title'],
        'date_from' => $s['date_from'], 'date_to' => $s['date_to']], array_filter($sections, static fn ($s) => (int) $s['id'] > 0))),
    'items' => (object) $itemMap,
]) ?>
<?php require_once '../../includes/footer.php'; ?>
