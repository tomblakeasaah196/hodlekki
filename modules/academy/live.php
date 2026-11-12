<?php
// /modules/academy/live.php
// HOD Academy, one live class (?item=): its Google Meet link (make, update, remove), who attended (from Meet,
// after the class, through HOD\TrainingLive\Matcher), the participants the match could not place ("Needs a
// look") and the tutor's corrections. Access: the 'trainings' row, church-wide (view to look, edit to change).
// Strict mode: behaviour in assets/ui/js/pages/training-live.js, actions through api/training_live_api.php.
// docs/INTEGRATIONS.md, "Live classes".
require_once __DIR__ . '/../../includes/trainings/bootstrap.php';
require_once __DIR__ . '/../../includes/trainings/view.php';
$ui = [
    'title' => 'Live class',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/training-live.js'],
    'offline' => false,
];
require_once '../../includes/header.php';
require_can('trainings', 'view', 'church');

use HOD\Integrations\Google\Google;
use HOD\TrainingLive\Live;

$itemId = (int) ($_GET['item'] ?? 0);
$state = class_exists(Live::class) && Live::ready($pdo) ? Live::state($pdo, $itemId) : null;
if (!$state) {
    echo '<div class="ui-page">' . ui_page_header(['title' => 'Not found', 'back' => ['href' => '/modules/academy/', 'label' => 'HOD Academy']])
        . ui_empty(['icon' => 'video', 'title' => $state === null ? 'Not ready yet' : 'Class not found',
            'text' => $state === null ? 'Try again after the next update.' : 'It may have been removed.']) . '</div>';
    require '../../includes/footer.php';
    exit;
}
$item = $state['item'];
$t = $state['training'];
$m = $state['meeting'];
$canEdit = can('trainings', 'edit', 'church');
$online = ($item['details']['mode'] ?? 'in_person') === 'online';
$hasMeet = $m !== null && $m['status'] === 'active' && $m['meet_url'];
$google = Google::status();
$connected = $google['state'] === 'connected';
$when = trainings_view_when($item['starts_at'], $item['minutes']);
$ended = $state['window'] !== null && trainings_now() >= $state['window'][1];
$names = array_column($state['learners'], 'name', 'user_id');
$open = array_values(array_filter($state['review'], fn ($r) => $r['status'] === 'open'));
$done = array_values(array_filter($state['review'], fn ($r) => $r['status'] !== 'open'));
$present = count(array_filter($state['learners'], fn ($l) => $l['present'] === true));
$fmt = static fn (?string $dt): string => $dt ? substr($dt, 11, 5) : '';
?>
<div class="ui-page ui-page--narrow" data-training-live>
    <?= ui_page_header(['title' => 'Live class', 'description' => $item['title'] . ($when !== '' ? ' · ' . $when : ''),
        'back' => ['href' => '/modules/academy/training?id=' . (int) $t['id'], 'label' => $t['title']]]) ?>

<?php if ($online && !$connected): ?>
    <?= ui_banner('warning', ['title' => 'Google not connected', 'text' => can('integrations', 'manage') ? 'Connect it under Integrations.' : 'Ask an admin to connect it.',
        'actions' => can('integrations', 'manage') ? ui_button('Integrations', ['size' => 'sm', 'href' => '/modules/integrations/']) : '']) ?>
<?php endif; ?>
<?php if ($m !== null && $m['last_error']): ?>
    <?= ui_banner('danger', ['title' => 'Google said no', 'text' => (string) $m['last_error']]) ?>
<?php endif; ?>

<?php if ($online): ?>
    <section class="ui-card">
        <div class="ui-card__header ui-card__header--divided">
            <h2 class="ui-h3">Meet link</h2>
            <?= ui_info('Made on the church\'s Google account. Learners join from their Trainings page, which records who joined. When you change the class\'s time or title in the builder, the Meet follows; removing the class removes it.') ?>
        </div>
<?php if ($hasMeet): ?>
        <ul class="ui-list">
            <li class="ui-list__item">
                <div class="ui-list__main"><a class="ui-list__title" href="<?= ui_e($m['meet_url']) ?>" target="_blank" rel="noopener noreferrer"><?= ui_e($m['meet_url']) ?></a></div>
                <div class="ui-list__end"><?= ui_button('Copy link', ['size' => 'sm', 'variant' => 'ghost', 'icon' => 'copy', 'icon_only' => true,
                    'attrs' => ['data-action' => 'live.copy', 'data-url' => $m['meet_url']]]) ?></div>
            </li>
        </ul>
<?php if ($canEdit): ?>
        <div class="ui-card__footer">
            <?= ui_button('Update', ['size' => 'sm', 'icon' => 'refresh-cw', 'disabled' => !$connected, 'attrs' => ['data-action' => 'live.update']]) ?>
            <?= ui_button('Remove link', ['size' => 'sm', 'variant' => 'danger-ghost', 'disabled' => !$connected, 'attrs' => ['data-action' => 'live.cancel']]) ?>
        </div>
<?php endif; ?>
<?php else: ?>
        <div class="ui-card__body"><p class="ui-meta"><?= $item['starts_at'] ? 'No Meet link yet.' : 'Give the class a date and time first.' ?></p></div>
<?php if ($canEdit): ?>
        <div class="ui-card__footer">
            <?= ui_button('Create Meet link', ['variant' => 'primary', 'icon' => 'video', 'disabled' => !$connected || !$item['starts_at'],
                'attrs' => ['data-action' => 'live.create']]) ?>
        </div>
<?php endif; ?>
<?php endif; ?>
    </section>
<?php endif; ?>

    <section class="ui-card">
        <div class="ui-card__header ui-card__header--divided">
            <h2 class="ui-h3">Attendance</h2>
            <?= ui_info('Present: at least ' . (int) $state['present_minutes'] . ' minutes in the call. Found automatically only when clear: a name matching one learner (95% or more), or the learner who pressed Join just before. The rest wait under Needs a look. Your choices are never undone by a later read.') ?>
            <span class="ui-meta"><?= $present ?> of <?= count($state['learners']) ?> present<?= $m !== null && $m['synced_at'] ? ' · read ' . ui_e(substr((string) $m['synced_at'], 0, 16)) : '' ?></span>
        </div>
<?php if ($state['learners'] === []): ?>
        <div class="ui-card__body"><p class="ui-meta">Nobody is on this training yet.</p></div>
<?php else: ?>
        <ul class="ui-list">
<?php foreach ($state['learners'] as $l):
            $meta = array_filter([
                $l['minutes'] !== null ? $l['minutes'] . ' min' : '',
                ['meet' => 'Meet', 'join' => 'Join log', 'manual' => 'You'][$l['source'] ?? ''] ?? '',
                $l['joined_at'] ? 'Joined ' . $fmt($l['joined_at']) : '',
            ]); ?>
            <li class="ui-list__item">
                <div class="ui-list__main">
                    <span class="ui-list__title"><?= ui_e($l['name']) ?></span>
<?php if ($meta): ?>
                    <span class="ui-list__meta"><?= ui_e(implode(' · ', $meta)) ?></span>
<?php endif; ?>
                </div>
                <div class="ui-list__end">
                    <?= $l['present'] === true ? ui_badge('Present', 'success') : ($l['present'] === false ? ui_badge('Absent', 'neutral') : '') ?>
<?php if ($canEdit): ?>
                    <?= ui_button($l['present'] === true ? 'Mark absent' : 'Mark present', ['size' => 'sm', 'variant' => $l['present'] === true ? 'ghost' : 'soft',
                        'attrs' => ['data-action' => 'live.present', 'data-user' => (string) $l['user_id'], 'data-present' => $l['present'] === true ? '0' : '1']]) ?>
<?php endif; ?>
                </div>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
<?php if ($canEdit && $hasMeet): ?>
        <div class="ui-card__footer">
            <?= ui_button('Get attendance', ['icon' => 'user-check', 'disabled' => !$connected || $state['window'] === null || trainings_now() < $state['window'][0],
                'attrs' => ['data-action' => 'live.sync']]) ?>
            <?= ui_info($ended ? 'Reads who was in the call from Meet. It also runs by itself the morning after the class.' : 'After the class starts. It also runs by itself the morning after the class.') ?>
        </div>
<?php endif; ?>
    </section>

<?php if ($open !== []): ?>
    <section class="ui-card">
        <div class="ui-card__header ui-card__header--divided">
            <h2 class="ui-h3">Needs a look</h2>
            <?= ui_info('People in the call the match could not place. Meet shows names only, never email addresses. Choose the learner, or Ignore a guest or your own device.') ?>
        </div>
        <ul class="ui-list">
<?php foreach ($open as $r): ?>
            <li class="ui-list__item">
                <div class="ui-list__main">
                    <span class="ui-list__title"><?= ui_e($r['display_name']) ?></span>
                    <span class="ui-list__meta"><?= (int) $r['minutes'] ?> min</span>
                </div>
<?php if ($canEdit): ?>
                <div class="ui-list__end">
                    <label class="sr-only" for="live-pick-<?= (int) $r['id'] ?>">Learner for <?= ui_e($r['display_name']) ?></label>
                    <select id="live-pick-<?= (int) $r['id'] ?>" class="ui-select ui-select--sm" data-live-pick="<?= (int) $r['id'] ?>">
                        <option value="">Choose…</option>
<?php           $shown = [];
                foreach ($r['suggestions'] as $s):
                    if (!isset($names[$s['user_id']])) {
                        continue;
                    }
                    $shown[$s['user_id']] = true; ?>
                        <option value="<?= (int) $s['user_id'] ?>"><?= ui_e($names[$s['user_id']] . (!empty($s['joined']) ? ' (pressed Join)' : '') . ' · ' . (int) round($s['score'] * 100) . '%') ?></option>
<?php           endforeach; ?>
<?php           foreach ($names as $u => $n):
                    if (isset($shown[$u])) {
                        continue;
                    } ?>
                        <option value="<?= (int) $u ?>"><?= ui_e($n) ?></option>
<?php           endforeach; ?>
                    </select>
                    <?= ui_button('Assign', ['size' => 'sm', 'variant' => 'soft', 'attrs' => ['data-action' => 'live.assign', 'data-row' => (string) $r['id']]]) ?>
                    <?= ui_button('Ignore', ['size' => 'sm', 'variant' => 'ghost', 'attrs' => ['data-action' => 'live.ignore', 'data-row' => (string) $r['id']]]) ?>
                </div>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($done !== []): ?>
    <details class="ui-card">
        <summary class="ui-card__header cursor-pointer"><span class="ui-h3">Looked at (<?= count($done) ?>)</span></summary>
        <ul class="ui-list">
<?php foreach ($done as $r): ?>
            <li class="ui-list__item">
                <div class="ui-list__main">
                    <span class="ui-list__title"><?= ui_e($r['display_name']) ?></span>
                    <span class="ui-list__meta"><?= (int) $r['minutes'] ?> min · <?= $r['status'] === 'assigned' ? ui_e('Counted for ' . ($names[$r['user_id']] ?? 'a learner')) : 'Ignored' ?></span>
                </div>
<?php if ($canEdit): ?>
                <div class="ui-list__end"><?= ui_button('Reopen', ['size' => 'sm', 'variant' => 'ghost', 'attrs' => ['data-action' => 'live.reopen', 'data-row' => (string) $r['id']]]) ?></div>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>
</div>
<?= ui_json_script('training-live', ['api' => '/api/training_live_api.php', 'item' => $itemId]) ?>
<?php require '../../includes/footer.php'; ?>
