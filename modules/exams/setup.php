<?php
// /modules/exams/setup.php
// One exam item (Trainings PR 2): its window, time, pass mark, rules and
// questions, and the results. On the foundation (strict CSP); behaviour in
// assets/ui/js/pages/exams.js, requests to /api/exams_api.php. Guide: docs/EXAMS.md.
//
//   /modules/exams/setup?item=<training_items.id>    (#tab=results for the results)
$ui = [
    'title' => 'Exam set-up',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/exams.js'],
];
// A standalone test's page is called that in the tab too (docs/EXAMS.md §8).
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/exams/access.php';
$peek = exams_ready($pdo) ? exam_item($pdo, (int) ($_GET['item'] ?? 0)) : null;
if ($peek !== null && exams_item_is_test($peek)) {
    $ui['title'] = 'Test set-up';
}
require_once '../../includes/header.php';

$item = exams_ready($pdo) ? exam_item($pdo, (int) ($_GET['item'] ?? 0)) : null;
// Access: Trainings, Edit, church-wide (the Academy's staff check, Roles & permissions).
if ($item === null || !exams_may_manage($pdo, (int) $item['training_id'])) {
    echo $item === null
        ? '<div class="ui-page ui-page--narrow">' . ui_empty(['icon' => 'file-text', 'title' => 'Exam not found', 'text' => 'It was removed, or the link is wrong.']) . '</div>'
        : ui_no_access();
    require_once '../../includes/footer.php';
    exit;
}
// A standalone test (includes/trainings/tests.php, docs/EXAMS.md §8): its name, certificate, status and QR code too.
$test = exams_test_setup($pdo, $item);
echo ui_json_script('exams-config', ['page' => 'setup', 'item' => (int) $item['id'], 'training_id' => (int) $item['training_id'], 'test' => $test !== null]);
?>
<div class="ui-page ui-page--wide" data-exams="setup">
<?php if ($test !== null): ?>
    <?= ui_page_header([
        'title' => 'Test set-up',
        'description' => $test['title'],
        'info' => 'A test on its own, with no course. Once published, every member sees it under Trainings › Tests, and anyone can open it from the link or the QR code. Each person gets the questions in their own order and a countdown. They see their score straight away, and the right answers once they pass or use their last try.',
        'back' => ['href' => '/modules/academy/#tab=tests', 'label' => 'Tests'],
    ]) ?>

    <section class="ui-card mb-5" aria-labelledby="ex-test-title">
        <div class="ui-card__header ui-card__header--divided">
            <h2 class="ui-card__title" id="ex-test-title">Test</h2>
            <span data-ex-test-status><?= $test['status'] === 'published' ? ui_badge('Published', 'success', ['dot' => true]) : ui_badge('Draft', 'neutral', ['dot' => true]) ?></span>
        </div>
        <div class="ui-card__body grid gap-6 md:grid-cols-[minmax(0,1fr)_220px]">
            <form class="ui-stack" id="ex-test-form" data-action="ex.test-save" novalidate>
                <?= ui_field('text', ['name' => 'title', 'id' => 'ex-test-name', 'label' => 'Name', 'required' => true, 'value' => $test['title'], 'attrs' => ['maxlength' => 160]]) ?>
                <label class="ui-check"><input type="checkbox" name="gives_certificate" value="1"<?= $test['gives_certificate'] ? ' checked' : '' ?>><span>Certificate for those who pass<span class="ui-check__hint">Given at once, with a QR code anyone can check.</span></span></label>
                <div class="flex flex-wrap gap-2">
                    <?= ui_button('Save', ['variant' => 'secondary', 'type' => 'submit', 'icon' => 'check']) ?>
                    <?= ui_button('Publish', ['variant' => 'primary', 'icon' => 'send', 'attrs' => ['data-action' => 'ex.test-publish', 'data-publish' => '1', 'data-ex-test-pub' => true, 'hidden' => $test['status'] === 'published']]) ?>
                    <?= ui_button('Unpublish', ['variant' => 'ghost', 'attrs' => ['data-action' => 'ex.test-publish', 'data-publish' => '0', 'data-ex-test-unpub' => true, 'hidden' => $test['status'] !== 'published']]) ?>
<?php if (can('trainings', 'delete', 'church')): ?>
                    <?= ui_button('Delete test', ['variant' => 'danger-ghost', 'icon' => 'trash-2', 'attrs' => ['data-action' => 'ex.test-delete']]) ?>
<?php endif; ?>
                </div>
            </form>
            <div class="ui-stack ui-stack--sm">
                <div class="ex-qr"><?= trainings_test_qr_svg($test['url']) ?></div>
                <p class="ui-meta break-all"><?= ui_e($test['url']) ?></p>
                <div class="flex flex-wrap gap-2">
                    <?= ui_button('Copy link', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'copy', 'attrs' => ['data-action' => 'ex.test-copy', 'data-url' => $test['url']]]) ?>
                    <?= ui_button('Download QR', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'download', 'href' => trainings_test_qr_png_uri($test['url']),
                        'attrs' => ['download' => 'test-' . (int) $item['id'] . '-qr.png']]) ?>
                </div>
            </div>
        </div>
    </section>
<?php else: ?>
    <?= ui_page_header([
        'title' => 'Exam set-up',
        'description' => $item['title'] . ' · ' . $item['training_title'],
        'info' => 'People can start the exam between the opening and closing times. Each person gets one attempt, with the questions and options in their own order, and a countdown; at zero it is handed in by itself. Answers are checked on the server. If someone keeps leaving the exam tab you get a note. People see the right answers and explanations only for the questions they got wrong, and only once the answers are shown (see Answers).',
        'back' => ['href' => exam_url_bank((int) $item['training_id']), 'label' => 'Question bank'],
    ]) ?>
<?php endif; ?>

    <?= ui_tabs(['settings' => ['label' => 'Settings', 'icon' => 'settings'], 'results' => ['label' => 'Results', 'icon' => 'chart-column']], 'settings', 'Exam sections') ?>

    <section id="panel-settings" data-tab-panel="settings" class="ui-tab-panel">
        <div data-ex-locked></div>
        <form class="grid items-start gap-5 lg:grid-cols-[340px_minmax(0,1fr)]" data-action="ex.exam-save" id="ex-exam-form" novalidate>
            <section class="ui-card" aria-labelledby="ex-rules-title">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="ex-rules-title">Rules</h2></div>
                <div class="ui-card__body ui-stack">
                    <?= ui_field('datetime-local', ['name' => 'window_open', 'id' => 'ex-open', 'label' => 'Opens', 'optional' => true]) ?>
                    <?= ui_field('datetime-local', ['name' => 'window_close', 'id' => 'ex-close', 'label' => 'Closes', 'optional' => true,
                        'info' => 'Nobody can start after this, and anyone still writing is handed in at this time.']) ?>
                    <div class="grid grid-cols-2 gap-3">
                        <?= ui_field('number', ['name' => 'minutes', 'id' => 'ex-minutes', 'label' => 'Minutes', 'value' => '30', 'required' => true, 'attrs' => ['min' => 1, 'max' => 300]]) ?>
                        <?= ui_field('number', ['name' => 'pass_percent', 'id' => 'ex-pass', 'label' => 'Pass mark (%)', 'value' => '50', 'required' => true, 'attrs' => ['min' => 0, 'max' => 100]]) ?>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <?= ui_field('select', ['name' => 'attempts_allowed', 'id' => 'ex-attempts', 'label' => 'Attempts allowed', 'value' => '1',
                            'options' => ['1' => '1', '2' => '2', '3' => '3'] + ($test !== null ? ['4' => '4', '5' => '5'] : []),
                            'info' => 'People can retake it until they pass or run out. Each retake is a new paper and timer, the best score counts, and the right answers wait until no attempt is left. Once people have started you can add attempts, not take them away.']) ?>
                        <?= ui_field('number', ['name' => 'leave_alert', 'id' => 'ex-leaves', 'label' => 'Tell me after', 'value' => '3',
                            'info' => 'How many times someone may leave the exam tab before you get a note.', 'attrs' => ['min' => 1, 'max' => 50]]) ?>
                    </div>
                    <label class="ui-check"><input type="checkbox" name="shuffle" value="1" checked><span>Shuffle per person</span></label>
                    <label class="ui-check"><input type="checkbox" name="part_marks" value="1"><span>Part marks<span class="ui-check__hint">Several answers: partly right earns part.</span></span></label>
                    <div><?= ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'check']) ?></div>
<?php if ($test !== null): ?>
                    <p class="ui-meta border-t border-line pt-3">Answers show after a pass or the last try.</p>
<?php else: ?>
                    <div class="ui-stack ui-stack--sm border-t border-line pt-3">
                        <div class="ui-label-row"><span class="ui-label">Answers</span><?= ui_info('People see the right answer and the explanation for the questions they got wrong only when nobody can still be helped by them: at the closing time; with no closing time, the day after the training ends; with neither, when you show them here. You can also show them earlier.') ?></div>
                        <p class="ui-meta" data-ex-review aria-live="polite"></p>
                        <div><?= ui_button('Show answers', ['variant' => 'secondary', 'size' => 'sm', 'attrs' => ['data-action' => 'ex.answers', 'data-ex-answers' => true, 'data-show' => '1']]) ?></div>
                    </div>
<?php endif; ?>
                </div>
            </section>
            <section class="ui-card" aria-labelledby="ex-pick-title">
                <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="ex-pick-title">Questions</h2>
                    <span class="ui-meta" data-ex-total aria-live="polite"></span></div>
                <div class="ui-card__body">
                    <div class="mb-3 flex flex-wrap gap-2">
                        <?= ui_button('Select all', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'ex.pick-all']]) ?>
                        <?= ui_button('Clear', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'ex.pick-none']]) ?>
                        <?= ui_button('Import questions', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'upload', 'attrs' => ['data-action' => 'ex.import-open']]) ?>
                        <?= ui_button('Download template', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'file-down', 'href' => '/api/exams_api.php?action=template']) ?>
                        <?= ui_button('Question bank', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'arrow-up-right', 'href' => exam_url_bank((int) $item['training_id'])]) ?>
                    </div>
                    <div class="ui-stack ui-stack--sm" data-ex-pool><p class="ui-meta">Loading…</p></div>
                </div>
            </section>
        </form>
    </section>

    <section id="panel-results" data-tab-panel="results" class="ui-tab-panel" hidden>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p data-ex-summary aria-live="polite"></p>
            <?= ui_button('Refresh', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'refresh-cw', 'attrs' => ['data-action' => 'ex.results']]) ?>
        </div>
        <div class="ui-toolbar mb-3" data-ex-tools hidden>
            <div class="ui-search ui-toolbar__grow">
                <?= ui_icon('search') ?>
                <label class="sr-only" for="ex-find">Find person</label>
                <input class="ui-input" type="search" id="ex-find" placeholder="Find person" data-action-input="ex.find" autocomplete="off">
            </div>
            <label class="sr-only" for="ex-sort">Sort</label>
            <select class="ui-select" id="ex-sort" data-action-change="ex.find">
                <option value="position">By position</option>
                <option value="name">By name (A–Z)</option>
            </select>
        </div>
        <div data-ex-results></div>
    </section>

    <?= ui_dialog_start('ex-attempt', ['title' => 'Attempt', 'size' => 'drawer']) ?>
        <div data-ex-attempt></div>
    <?= ui_dialog_end(
        ui_button('Close', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Hand in now', ['variant' => 'danger', 'attrs' => ['data-action' => 'ex.end', 'data-ex-end' => true, 'hidden' => true]])
    ) ?>

    <?php // Import straight into this exam: this training's bank, saved as Ready, then chosen here (docs/EXAMS.md §3). ?>
    <?= ui_dialog_start('ex-import', ['title' => 'Import questions', 'size' => 'lg']) ?>
        <div data-ex-import-pick>
            <form class="ui-stack" id="ex-import-form" data-action="ex.import-check" novalidate>
                <p>Fill in the <a class="ui-link" href="/api/exams_api.php?action=template">Excel template</a>, then upload it.</p>
                <div class="ui-field">
                    <label class="ui-label" for="ex-import-file">Excel file (.xlsx)</label>
                    <input class="ui-input" type="file" id="ex-import-file" name="sheet" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                </div>
            </form>
        </div>
        <div data-ex-import-result hidden>
            <p class="font-medium" data-ex-import-summary aria-live="polite"></p>
            <div class="mt-3 max-h-[50vh] overflow-auto" data-ex-import-rows></div>
            <p class="ui-meta mt-3" data-ex-import-into>They are saved as Ready and added to this exam.</p>
            <label class="ui-check mt-3" data-ex-import-explain-row hidden><input type="checkbox" data-ex-import-explain><span>Suggest missing explanations<span class="ui-check__hint" data-ex-import-explain-hint></span></span></label>
        </div>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Check file', ['variant' => 'secondary', 'type' => 'submit', 'attrs' => ['form' => 'ex-import-form', 'data-ex-import-check' => true]])
        . ui_button('Import', ['variant' => 'primary', 'attrs' => ['data-action' => 'ex.import-confirm', 'data-ex-import-go' => true, 'hidden' => true]])
    ) ?>

    <?php // AI suggestions for the imported questions with no explanation (PR 4b's flow): stored only on Save. ?>
    <?= ui_dialog_start('ex-approve', ['title' => 'Check explanations', 'size' => 'lg']) ?>
        <div data-ex-approve-note></div>
        <ol class="ui-stack" data-ex-approve-list></ol>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Save', ['variant' => 'primary', 'attrs' => ['data-action' => 'ex.approve-confirm']])
    ) ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
