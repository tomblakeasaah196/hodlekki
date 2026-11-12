<?php
// /modules/exams/index.php
// The question bank (Trainings PR 2): questions for exams, written here or
// imported from the Excel template. On the foundation (strict CSP); the
// behaviour is assets/ui/js/pages/exams.js and every request goes to
// /api/exams_api.php. Logic: includes/exams/. Guide: docs/EXAMS.md.
//
//   /modules/exams/?training=<id>   that training's bank (default: the first one you manage)
//   /modules/exams/?training=shared the shared bank
$ui = [
    'title' => 'Question bank',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/exams.js'],
];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/exams/access.php';

// Access: Trainings, Manage (Roles & permissions), per bank; the API checks every action again.
$banks = exams_ready($pdo) ? exams_banks($pdo) : [];
if ($banks === []) {
    echo ui_no_access();
    require_once '../../includes/footer.php';
    exit;
}
$want = (string) ($_GET['training'] ?? '');
$bank = $banks[0];
foreach ($banks as $b) {
    if (($want === 'shared' && $b['id'] === null) || ($want !== '' && $b['id'] !== null && (string) $b['id'] === $want)) {
        $bank = $b;
    }
}
$bankOptions = [];
foreach ($banks as $b) {
    $bankOptions[$b['id'] === null ? 'shared' : (string) $b['id']] = $b['title'];
}
echo ui_json_script('exams-config', ['page' => 'bank', 'training_id' => $bank['id'], 'types' => EXAM_QTYPES, 'letters' => EXAM_LETTERS]);
?>
<div class="ui-page ui-page--wide" data-exams="bank">
    <?= ui_page_header([
        'title' => 'Question bank',
        'description' => 'Questions for your exams.',
        'info' => 'Three kinds of question: one answer, several answers, and true/false. Write them here, or download the Excel template, fill it in and import it: every row is checked before anything is added, and importing the same file again adds nothing twice. Only questions marked Ready can go in an exam. The explanation is shown only to people who get a question wrong, after the exam closes.',
        'actions' => ui_button('Template', ['variant' => 'ghost', 'icon' => 'download', 'href' => '/api/exams_api.php?action=template'])
            . ui_button('Import', ['variant' => 'secondary', 'icon' => 'upload', 'attrs' => ['data-action' => 'ex.import-open']])
            . ui_button('Add question', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-action' => 'ex.add']]),
    ]) ?>

    <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_200px_200px]">
        <?= ui_field('search', ['name' => 'q', 'id' => 'ex-search', 'label' => 'Find', 'attrs' => ['data-action-input' => 'ex.filter']]) ?>
        <?= ui_field('select', ['name' => 'status', 'id' => 'ex-status', 'label' => 'Status',
            'options' => ['' => 'Draft and Ready', 'approved' => 'Ready', 'draft' => 'Draft', 'archived' => 'Archived'],
            'attrs' => ['data-action-change' => 'ex.filter']]) ?>
        <?= ui_field('select', ['name' => 'bank', 'id' => 'ex-bank', 'label' => 'Bank', 'options' => $bankOptions,
            'value' => $bank['id'] === null ? 'shared' : (string) $bank['id'], 'attrs' => ['data-action-change' => 'ex.bank']]) ?>
    </div>
    <p class="ui-meta mb-2" data-ex-count aria-live="polite"></p>
    <div data-ex-list><p class="ui-meta">Loading…</p></div>

    <?= ui_dialog_start('ex-q', ['title' => 'Question', 'size' => 'lg']) ?>
        <form class="ui-stack" id="ex-q-form" data-action="ex.save" novalidate>
            <?= ui_field('select', ['name' => 'type', 'id' => 'ex-q-type', 'label' => 'Type', 'options' => EXAM_QTYPES,
                'attrs' => ['data-action-change' => 'ex.type']]) ?>
            <?= ui_field('textarea', ['name' => 'text', 'id' => 'ex-q-text', 'label' => 'Question', 'required' => true, 'attrs' => ['rows' => 3, 'maxlength' => 2000]]) ?>
            <fieldset class="ui-fieldset">
                <legend class="ui-label">Options <?= ui_info('Tick the right answer. Several answers: tick every right one. Leave the last options empty if you need fewer.') ?></legend>
                <div class="ui-stack ui-stack--sm" data-ex-options></div>
            </fieldset>
            <div class="grid gap-3 sm:grid-cols-2">
                <?= ui_field('number', ['name' => 'marks', 'id' => 'ex-q-marks', 'label' => 'Marks', 'value' => '1', 'attrs' => ['min' => 1, 'max' => 100]]) ?>
                <?= ui_field('text', ['name' => 'topic', 'id' => 'ex-q-topic', 'label' => 'Topic', 'optional' => true, 'attrs' => ['maxlength' => 120]]) ?>
            </div>
            <?= ui_field('textarea', ['name' => 'explanation', 'id' => 'ex-q-expl', 'label' => 'Explanation', 'optional' => true,
                'info' => 'Shown only to people who get this wrong, after the exam closes. Say why the answer is right.', 'attrs' => ['rows' => 3, 'maxlength' => 2000]]) ?>
            <label class="ui-check"><input type="checkbox" name="ready" value="1"><span>Ready to use</span></label>
            <p class="ui-meta" data-ex-q-locked hidden>Used in an exam: only the explanation and topic can change.</p>
        </form>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Save', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ex-q-form']])
    ) ?>

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
            <label class="ui-check mt-3"><input type="checkbox" name="ready" value="1" checked data-ex-import-ready><span>Mark them Ready</span></label>
        </div>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Check file', ['variant' => 'secondary', 'type' => 'submit', 'attrs' => ['form' => 'ex-import-form', 'data-ex-import-check' => true]])
        . ui_button('Import', ['variant' => 'primary', 'attrs' => ['data-action' => 'ex.import-confirm', 'data-ex-import-go' => true, 'hidden' => true]])
    ) ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
