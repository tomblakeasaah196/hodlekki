<?php
// /modules/trainings/index.php
// Trainings: the learner's area of the HOD Academy (TRAININGS-PLAN.md).
//   /modules/trainings/        my trainings (progress, the next thing to do) and the catalogue
//   /modules/trainings/?t=ID   one training: its cards, the players, handing in work, and
//                              Take notes (My notes, ui_notetaker(), floating over the training)
// Rendered on the server (it reads well as an offline copy); actions go to
// /api/trainings_api.php from assets/ui/js/pages/trainings.js. Access: the
// 'trainings' row of the permission matrix, own records (everyone signed in).
// YouTube and Spotify play embedded, and PowerPoint decks show in Microsoft's Office
// viewer: frame-src allows exactly those three hosts (TRAININGS_FRAME_HOSTS).
require_once __DIR__ . '/../../includes/trainings/bootstrap.php';
require_once __DIR__ . '/../../includes/trainings/view.php';
$ui = [
    'title' => 'Trainings',
    'mode' => 'strict',
    // training-live.js (PR 3) logs each press of Join class for attendance, then the link opens as usual.
    'scripts' => array_merge(['/assets/ui/js/pages/trainings.js'], function_exists('training_live_url') ? ['/assets/ui/js/pages/training-live.js'] : []),
    'csp' => ['frame-src' => TRAININGS_FRAME_HOSTS],
];
require_once '../../includes/header.php';
$me = (int) $_SESSION['user_id'];
require_can('trainings', 'view', perm_person($me));

$ready = trainings_ready($pdo);
$tid = (int) ($_GET['t'] ?? 0);
$isStaff = can('trainings', 'view', 'church');
?>
<div class="ui-page">
<?php if (!$ready): ?>
    <?= ui_page_header(['title' => 'Trainings']) ?>
    <?= ui_banner('info', ['title' => 'Almost ready', 'text' => 'Trainings opens after the next update.']) ?>
<?php elseif ($tid > 0):
    $t = trainings_get($pdo, $tid);
    $e = $t !== null ? trainings_enrolment_for($pdo, $tid, $me) : null;
    $enrolled = $e !== null && $e['status'] !== 'withdrawn';
    $open = $t !== null && $t['status'] === 'published' && ($enrolled || $t['catalogue']);
    if ($t === null || (!$open && !$isStaff)): ?>
    <?= ui_page_header(['title' => 'Not found', 'back' => ['href' => '/modules/trainings/', 'label' => 'Trainings']]) ?>
    <?= ui_empty(['icon' => 'graduation-cap', 'title' => 'Training not open', 'text' => 'It may have ended or moved.',
        'actions' => ui_button('My trainings', ['variant' => 'primary', 'href' => '/modules/trainings/'])]) ?>
<?php else:
        $view = trainings_learner_view($pdo, $t, $enrolled ? $e : null);
        $en = $view['enrolment'];
        $dates = trainings_view_range($t['starts_on'], $t['ends_on']);
        $sub = $en === null ? ($dates !== '' ? $dates : (TRAININGS_LENGTHS[$t['length']] ?? ''))
            : ($en['status'] === 'active' ? ($en['due_on'] ? 'Due ' . trainings_view_date($en['due_on']) : ($dates ?: 'Go at your own pace.'))
                : ($en['confirmed_at'] ? 'Confirmed by the Academy.' : 'Completed.'));
        $canJoin = $t['status'] === 'published' && $t['catalogue'] && !$enrolled && can('trainings', 'create', perm_person($me));
        $actions = $canJoin ? ui_button('Join training', ['variant' => 'primary', 'icon' => 'plus',
            'attrs' => ['data-action' => 'trn.join', 'data-training' => $t['id']]]) : '';
        // My notes (includes/trainings/notes.php): the learner's own, floating over the training.
        $notes = trainings_notes_ready($pdo) && trainings_note_can_take($pdo, $t, $me);
        if ($notes) {
            $actions .= ui_button('Take notes', ['variant' => 'secondary', 'icon' => 'notebook-pen',
                'attrs' => ['data-notes-open' => 'trn-notes', 'aria-controls' => 'trn-notes']]);
        }
        if ($enrolled && $e['source'] === 'self' && $e['status'] === 'active') {
            $actions .= ui_button('Leave', ['variant' => 'ghost', 'attrs' => ['data-action' => 'trn.leave', 'data-enrolment' => $e['id']]]);
        }
?>
    <?= ui_page_header(['title' => $t['title'], 'description' => $sub, 'info' => (string) ($t['summary'] ?? '') ?: null,
        'back' => ['href' => '/modules/trainings/', 'label' => 'Trainings'], 'actions' => $actions]) ?>
<?php if ($t['status'] !== 'published'): ?>
    <?= ui_banner('warning', ['title' => 'Preview', 'text' => 'Learners see this once it is published.']) ?>
<?php endif; ?>
<?php if ($en !== null): ?>
    <div class="ui-card mb-6">
        <div class="ui-card__body ui-stack ui-stack--sm">
            <div class="flex items-center justify-between gap-3">
                <span class="font-semibold text-ink"><?= (int) $en['done'] ?> of <?= (int) $en['required'] ?> done</span>
                <?= $en['status'] === 'completed' ? ui_badge($en['confirmed_at'] ? 'Confirmed' : 'Completed', 'success', ['dot' => true]) : '' ?>
            </div>
            <progress class="ui-progress" max="100" value="<?= (int) $en['percent'] ?>" aria-label="Progress"><?= (int) $en['percent'] ?>%</progress>
<?php if ($en['certificate'] !== ''): ?>
            <div><?= ui_button('Download certificate', ['variant' => 'primary', 'icon' => 'file-down', 'href' => '/api/trainings_certificate.php?e=' . (int) $en['id']]) ?></div>
<?php endif; ?>
        </div>
    </div>
<?php endif; ?>
    <div class="ui-stack ui-stack--md">
<?php foreach ($view['sections'] as $s):
        if ($s['items'] === []) {
            continue;
        } ?>
        <section class="ui-card" aria-labelledby="trn-s-<?= (int) $s['id'] ?>">
            <div class="ui-card__header ui-card__header--divided">
                <h2 class="ui-card__title" id="trn-s-<?= (int) $s['id'] ?>"><?= ui_e($s['title']) ?></h2>
                <span class="ui-meta"><?= ui_e(trainings_view_range($s['date_from'], $s['date_to'])) ?></span>
            </div>
            <ul class="ui-list">
<?php foreach ($s['items'] as $item):
            $p = $item['progress'];
            $d = $item['details'];
            $when = trainings_view_when($item['starts_at'], $item['minutes']); ?>
                <li class="ui-list__item block">
                    <details class="group">
                        <summary class="flex items-center gap-3 cursor-pointer list-none">
                            <span class="ui-list__icon"><?= ui_icon(trainings_view_icon($item['type'])) ?></span>
                            <span class="ui-list__main">
                                <span class="ui-list__title"><?= ui_e($item['title']) ?></span>
                                <span class="ui-list__meta"><?= ui_e($item['type_label'] . ($when !== '' ? ' · ' . $when : '') . ($item['required'] ? '' : ' · Optional')) ?></span>
                            </span>
                            <span class="ui-list__end"><?= trainings_view_item_badge($item) ?><?= ui_icon('chevron-down', 'ui-icon--sm text-ink-subtle transition-transform group-open:rotate-180') ?></span>
                        </summary>
                        <div class="ui-stack ui-stack--sm pt-4">
<?php if (!$item['ready']): ?>
                            <p class="ui-meta">Coming soon.</p>
<?php elseif ($item['type'] === 'live'): ?>
                            <p class="text-ink"><?= $d['mode'] === 'online' ? 'Online' : 'In person' ?><?= $d['venue'] !== '' ? ' · ' . ui_e($d['venue']) : '' ?></p>
<?php if ($d['mode'] === 'online' && $d['link'] !== '' && $en !== null): ?>
                            <div><?= ui_button('Join class', ['variant' => 'primary', 'icon' => 'external-link', 'href' => $d['link'],
                                'attrs' => ['target' => '_blank', 'rel' => 'noopener noreferrer', 'data-live-join' => (string) $item['id']]]) ?></div>
<?php endif; ?>
                            <p class="ui-meta">Your tutor records who attended.</p>
<?php elseif ($item['type'] === 'exam'): ?>
<?php if ($p !== null && $p['percent'] !== null): ?>
                            <?= ui_banner($p['passed'] ? 'success' : 'warning', ['title' => 'Score: ' . (int) $p['percent'] . '%',
                                'text' => $p['passed'] ? 'Passed.' : 'Talk to your tutor about it.']) ?>
<?php endif; ?>
<?php if ($d['take_url'] !== null && $en !== null && $p === null): ?>
                            <div><?= ui_button('Start exam', ['variant' => 'primary', 'icon' => 'clipboard-list', 'href' => $d['take_url']]) ?></div>
<?php elseif ($d['take_url'] !== null && $p !== null): ?>
                            <div><?= ui_button('See result', ['variant' => 'secondary', 'href' => $d['take_url']]) ?></div>
<?php elseif ($d['take_url'] === null): ?>
                            <p class="ui-meta">Coming soon.</p>
<?php endif; ?>
<?php elseif ($item['type'] === 'video' || $item['type'] === 'audio'): ?>
                            <?= trainings_view_media($d['media'], $item['title']) ?>
<?php elseif ($item['type'] === 'reading'): ?>
<?php if ($d['deck'] !== ''): ?>
                            <?= trainings_view_deck($d['deck'], $item['title'], $d['deck_name'], $d['slides']) ?>
<?php endif; ?>
                            <?= trainings_view_text($d['text']) ?>
<?php if ($d['pdf'] !== ''): ?>
                            <div><?= ui_button('Open PDF', ['variant' => 'secondary', 'icon' => 'file-down', 'href' => $d['pdf'],
                                'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?></div>
<?php endif; ?>
<?php elseif ($item['type'] === 'assignment'):
                $canHandIn = $en !== null && $en['status'] !== 'withdrawn' && ($p === null || $p['status'] === 'returned');
                $prompts = $d['prompts'] !== [] ? $d['prompts'] : ['Your answer']; ?>
<?php if ($d['instructions'] !== ''): ?>
                            <?= trainings_view_text($d['instructions']) ?>
<?php endif; ?>
<?php if ($d['kind'] === 'sermon_review'): ?>
                            <?= trainings_view_media($d['media'], 'Sermon: ' . $item['title']) ?>
<?php endif; ?>
<?php if ($p !== null && $p['mark'] !== null): ?>
                            <?= ui_banner($p['status'] === 'completed' ? 'success' : 'warning', ['title' => 'Mark: ' . (int) $p['mark'] . ' / ' . (int) $d['max_mark'],
                                'text' => $p['status'] === 'completed' ? 'Well done.' : 'Read the comment, then hand it in again.', 'info' => $p['comment'] !== '' ? $p['comment'] : null]) ?>
<?php if ($p['comment'] !== ''): ?>
                            <blockquote class="border-l-4 border-line pl-3 text-ink-muted"><?= nl2br(ui_e($p['comment']), false) ?></blockquote>
<?php endif; ?>
<?php endif; ?>
<?php if ($canHandIn): ?>
                            <form class="ui-form" data-action="trn.submit" data-ui-validate novalidate>
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
<?php foreach ($prompts as $n => $prompt): ?>
                                <?= ui_field('textarea', ['name' => 'answers[]', 'id' => 'trn-a-' . (int) $item['id'] . '-' . $n, 'label' => $prompt,
                                    'required' => true, 'value' => (string) ($p['answers'][$n] ?? ''), 'attrs' => ['rows' => 4, 'maxlength' => 5000]]) ?>
<?php endforeach; ?>
                                <div><?= ui_button('Hand in', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send']) ?></div>
                            </form>
<?php elseif ($p !== null): ?>
<?php foreach ($prompts as $n => $prompt): ?>
                            <div>
                                <p class="ui-label"><?= ui_e($prompt) ?></p>
                                <p class="text-ink whitespace-pre-line"><?= ui_e((string) ($p['answers'][$n] ?? '')) ?></p>
                            </div>
<?php endforeach; ?>
<?php if ($p['status'] === 'submitted'): ?>
                            <p class="ui-meta">Handed in. Your tutor marks it next.</p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($item['ready'] && $item['self'] && $en !== null && $en['status'] !== 'withdrawn'): ?>
                            <div>
<?php if ($p !== null && $p['status'] === 'completed'): ?>
                                <?= ui_button('Mark not done', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'x',
                                    'attrs' => ['data-action' => 'trn.done', 'data-item' => $item['id'], 'data-done' => '0']]) ?>
<?php else: ?>
                                <?= ui_button('Mark done', ['variant' => 'soft', 'icon' => 'check',
                                    'attrs' => ['data-action' => 'trn.done', 'data-item' => $item['id'], 'data-done' => '1']]) ?>
<?php endif; ?>
                            </div>
<?php endif; ?>
                        </div>
                    </details>
                </li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endforeach; ?>
    </div>
<?php if ($notes): ?>
    <?= ui_notetaker('trn-notes', [
        'subject' => (string) $t['title'],
        'endpoint' => '/api/trainings_api.php',
        'load' => ['action' => 'note_get', 'training_id' => (int) $t['id']],
        'save' => ['action' => 'note_save', 'training_id' => (int) $t['id']],
        'download' => '/api/trainings_notes_doc.php?t=' . (int) $t['id'],
        'remember' => 'trn-' . (int) $t['id'],
        'max' => TRAININGS_NOTE_MAX,
    ]) ?>
<?php endif; ?>
<?php endif; ?>
<?php else:
    $mine = trainings_mine($pdo, $me);
    $catalogue = array_values(array_filter(trainings_catalogue($pdo, $me), static fn ($c) => !$c['joined']));
?>
    <?= ui_page_header([
        'title' => 'Trainings',
        'description' => 'Your classes, work and progress.',
        'info' => 'Your classes, live sessions, exams and certificates in one place. Trainings the HOD Academy gives you appear here; join others from the catalogue.',
        'actions' => $isStaff ? ui_button('HOD Academy', ['variant' => 'secondary', 'icon' => 'graduation-cap', 'href' => '/modules/academy/']) : '',
    ]) ?>
    <?= ui_tabs(['mine' => ['label' => 'My trainings', 'count' => count($mine)], 'catalogue' => ['label' => 'Catalogue', 'count' => count($catalogue)]], 'mine') ?>

    <section data-tab-panel="mine" id="panel-mine" role="tabpanel" aria-labelledby="tab-mine" class="ui-tab-panel ui-stack ui-stack--md">
<?php if ($mine === []): ?>
        <?= ui_empty(['icon' => 'graduation-cap', 'title' => 'No trainings yet', 'text' => 'Join one from the catalogue.',
            'actions' => $catalogue !== [] ? ui_button('Open catalogue', ['variant' => 'primary', 'href' => '#tab=catalogue']) : '']) ?>
<?php else: ?>
        <div class="ui-grid ui-grid--2">
<?php foreach ($mine as $m):
        $next = $m['next'];
        $meta = $m['status'] === 'active'
            ? ($m['due_on'] ? 'Due ' . trainings_view_date($m['due_on']) : (trainings_view_range($m['starts_on'], $m['ends_on']) ?: 'At your own pace'))
            : ($m['confirmed_at'] ? 'Confirmed' : 'Completed ' . trainings_view_date($m['completed_at'])); ?>
            <a class="ui-card" href="/modules/trainings/?t=<?= (int) $m['id'] ?>">
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="ui-card__title"><?= ui_e($m['title']) ?></h2>
                        <?= $m['status'] === 'completed' ? ui_badge($m['confirmed_at'] ? 'Confirmed' : 'Completed', 'success', ['dot' => true])
                            : ($m['due_on'] !== null && $m['due_on'] < trainings_today() ? ui_badge('Overdue', 'warning', ['dot' => true]) : '') ?>
                    </div>
                    <p class="ui-meta"><?= ui_e($meta) ?></p>
                    <progress class="ui-progress ui-progress--sm" max="100" value="<?= (int) $m['percent'] ?>" aria-label="Progress"><?= (int) $m['percent'] ?>%</progress>
                    <p class="text-sm text-ink-muted"><?= (int) $m['done'] ?> of <?= (int) $m['required'] ?> done<?= $next !== null && $m['status'] === 'active'
                        ? ' · Next: ' . ui_e($next['title']) : '' ?></p>
                </div>
            </a>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </section>

    <section data-tab-panel="catalogue" id="panel-catalogue" role="tabpanel" aria-labelledby="tab-catalogue" class="ui-tab-panel ui-stack ui-stack--md" hidden>
<?php if ($catalogue === []): ?>
        <?= ui_empty(['icon' => 'book-open', 'title' => 'Nothing to join', 'text' => 'New trainings appear here.']) ?>
<?php else: ?>
        <div class="ui-grid ui-grid--2">
<?php foreach ($catalogue as $c): ?>
            <div class="ui-card">
                <div class="ui-card__body ui-stack ui-stack--sm">
                    <h2 class="ui-card__title"><a href="/modules/trainings/?t=<?= (int) $c['id'] ?>"><?= ui_e($c['title']) ?></a></h2>
                    <p class="ui-meta"><?= ui_e(trainings_view_range($c['starts_on'], $c['ends_on']) ?: $c['length_label']) ?></p>
<?php if ($c['summary'] !== ''): ?>
                    <p class="text-sm text-ink-muted line-clamp-3"><?= ui_e($c['summary']) ?></p>
<?php endif; ?>
                    <div class="flex gap-2">
                        <?= ui_button('Join', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'trn.join', 'data-training' => $c['id']]]) ?>
                        <?= ui_button('Look inside', ['variant' => 'ghost', 'href' => '/modules/trainings/?t=' . (int) $c['id']]) ?>
                    </div>
                </div>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </section>
<?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
