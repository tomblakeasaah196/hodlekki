<?php
// /modules/exams/ai.php
// AI help for the Academy (Trainings PR 4b): questions written from a
// training's material into the bank as drafts, an outline, a lesson summary
// and sermon-review prompts. Everything is a proposal: questions wait in the
// bank as drafts, content is created only when Apply is pressed. Only the
// material staff choose is sent to Gemini (docs/EXAMS.md §7). Behaviour in
// assets/ui/js/pages/exams-ai.js; requests to /api/exams_ai_api.php.
//
//   /modules/exams/ai?training=<id>
$ui = [
    'title' => 'AI help',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/exams-ai.js'],
];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/exams/ai_actions.php';

// Access: Trainings, Edit, church-wide (the Academy's staff check, Roles & permissions).
if (!exams_ready($pdo) || !exams_staff_can('edit')) {
    echo ui_no_access();
    require_once '../../includes/footer.php';
    exit;
}
$training = exams_training($pdo, (int) ($_GET['training'] ?? 0));
if ($training === null) {
    $list = '';
    foreach (exams_banks($pdo) as $b) {
        if ($b['id'] !== null) {
            $list .= '<li><a class="ui-link" href="/modules/exams/ai?training=' . (int) $b['id'] . '">' . ui_e($b['title']) . '</a></li>';
        }
    }
    echo '<div class="ui-page ui-page--narrow">' . ui_page_header(['title' => 'AI help', 'description' => 'Choose a training.'])
        . ($list !== '' ? '<ul class="ui-stack ui-stack--sm">' . $list . '</ul>' : ui_empty(['icon' => 'sparkles', 'title' => 'No trainings yet', 'text' => 'Create one in the Academy first.']))
        . '</div>';
    require_once '../../includes/footer.php';
    exit;
}
$tid = (int) $training['id'];
echo ui_json_script('exams-ai-config', ['training_id' => $tid, 'types' => EXAM_QTYPES]);
$kind = static fn (string $value, string $label, bool $checked = false, string $name = 'types'): string =>
    '<label class="ui-check"><input type="checkbox" name="' . ui_e($name) . '" value="' . ui_e($value) . '"' . ($checked ? ' checked' : '') . '><span>' . ui_e($label) . '</span></label>';
$src = static fn (string $group, string $value, string $label, string $hint, bool $checked = false): string =>
    '<label class="ui-check rounded-ui-lg border border-line px-3 py-2"><input type="radio" name="source" value="' . ui_e($value) . '"' . ($checked ? ' checked' : '')
    . ' data-action-change="ai.source" data-group="' . ui_e($group) . '"><span>' . ui_e($label) . '<span class="ui-check__hint">' . ui_e($hint) . '</span></span></label>';
?>
<div class="ui-page" data-exams-ai>
    <?= ui_page_header([
        'title' => 'AI help',
        'description' => $training['title'],
        'info' => 'Gemini drafts from the material you choose: notes you paste, a PDF you upload, or this training\'s readings and slides. Only that material is sent, never anyone\'s name, records or answers. Questions arrive in the bank as drafts; nothing reaches learners until someone approves it. There is a daily limit for the whole church.',
        'back' => ['href' => '/modules/exams/?training=' . $tid, 'label' => 'Question bank'],
    ]) ?>

    <div data-ai-banner></div>
    <p class="ui-meta mb-3" data-ai-usage aria-live="polite"></p>

    <?= ui_tabs([
        'questions' => ['label' => 'Questions', 'icon' => 'sparkles'],
        'outline' => ['label' => 'Outline', 'icon' => 'layout-dashboard'],
        'summary' => ['label' => 'Summary', 'icon' => 'file-text'],
        'prompts' => ['label' => 'Review prompts', 'icon' => 'pencil'],
    ], 'questions', 'AI help sections') ?>

    <section id="panel-questions" data-tab-panel="questions" class="ui-tab-panel">
        <form class="ui-card" id="ai-q-form" data-action="ai.questions" novalidate aria-labelledby="ai-q-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="ai-q-title">Write questions</h2></div>
            <div class="ui-card__body ui-stack">
                <fieldset class="ui-fieldset"><legend class="ui-label">From</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <?= $src('q', 'items', 'This training', 'Its readings and slides.', true) ?>
                        <?= $src('q', 'notes', 'Notes', 'Paste a lesson or outline.') ?>
                        <?= $src('q', 'pdf', 'A PDF', 'Up to 10 MB, not kept.') ?>
                    </div>
                </fieldset>
                <div data-ai-pane="q-items"><div class="ui-stack ui-stack--sm" data-ai-readings><p class="ui-meta">Loading…</p></div></div>
                <div data-ai-pane="q-notes" hidden>
                    <?= ui_field('textarea', ['name' => 'notes', 'id' => 'ai-q-notes', 'label' => 'Notes', 'attrs' => ['rows' => 8, 'maxlength' => 60000]]) ?>
                </div>
                <div data-ai-pane="q-pdf" hidden>
                    <div class="ui-field">
                        <label class="ui-label" for="ai-q-pdf">PDF</label>
                        <input class="ui-input" type="file" id="ai-q-pdf" name="pdf" accept="application/pdf,.pdf">
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <?= ui_field('number', ['name' => 'count', 'id' => 'ai-q-count', 'label' => 'How many', 'value' => '10', 'attrs' => ['min' => 1, 'max' => EXAMS_AI_MAX_QUESTIONS]]) ?>
                    <?= ui_field('number', ['name' => 'marks', 'id' => 'ai-q-marks', 'label' => 'Marks each', 'value' => '1', 'attrs' => ['min' => 1, 'max' => 100]]) ?>
                    <?= ui_field('text', ['name' => 'topic', 'id' => 'ai-q-topic', 'label' => 'Topic', 'optional' => true, 'attrs' => ['maxlength' => 120]]) ?>
                </div>
                <fieldset class="ui-fieldset"><legend class="ui-label">Kinds</legend>
                    <div class="flex flex-wrap gap-4">
                        <?= $kind('single', 'One answer', true) ?><?= $kind('multiple', 'Several answers', true) ?><?= $kind('true_false', 'True/False', true) ?>
                    </div>
                </fieldset>
                <?= ui_field('select', ['name' => 'bank', 'id' => 'ai-q-bank', 'label' => 'Save to', 'options' => ['training' => 'This training\'s bank', 'shared' => 'Shared bank']]) ?>
                <div class="flex flex-wrap items-center gap-3">
                    <?= ui_button('Write questions', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'sparkles']) ?>
                    <p class="ui-meta" data-ai-q-result aria-live="polite"></p>
                </div>
            </div>
        </form>
    </section>

    <section id="panel-outline" data-tab-panel="outline" class="ui-tab-panel" hidden>
        <form class="ui-card" id="ai-o-form" data-action="ai.outline" novalidate aria-labelledby="ai-o-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="ai-o-title">Draft an outline</h2></div>
            <div class="ui-card__body ui-stack">
                <?= ui_field('textarea', ['name' => 'notes', 'id' => 'ai-o-notes', 'label' => 'What it should cover', 'attrs' => ['rows' => 5, 'maxlength' => 4000]]) ?>
                <div class="grid gap-3 sm:grid-cols-2">
                    <?= ui_field('number', ['name' => 'sections', 'id' => 'ai-o-sections', 'label' => 'Cards', 'value' => '4', 'attrs' => ['min' => 1, 'max' => 12]]) ?>
                    <label class="ui-check self-end"><input type="checkbox" name="use_readings" value="1"><span>Use its readings</span></label>
                </div>
                <div><?= ui_button('Draft outline', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'sparkles']) ?></div>
            </div>
        </form>
        <section class="ui-card mt-4" data-ai-o-proposal hidden aria-labelledby="ai-o-p-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="ai-o-p-title">Proposal</h2>
                <?= ui_info('Change anything, untick what you do not want, then Apply. Empty cards are filled first; the rest are added at the end. Nothing is created before Apply.') ?></div>
            <div class="ui-card__body ui-stack" data-ai-o-cards></div>
            <div class="ui-card__body flex flex-wrap items-center gap-3 border-t border-line">
                <?= ui_button('Apply', ['variant' => 'primary', 'icon' => 'check', 'attrs' => ['data-action' => 'ai.outline-apply']]) ?>
                <p class="ui-meta" data-ai-o-result aria-live="polite"></p>
            </div>
        </section>
    </section>

    <section id="panel-summary" data-tab-panel="summary" class="ui-tab-panel" hidden>
        <section class="ui-card" aria-labelledby="ai-s-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="ai-s-title">Lesson summary</h2></div>
            <div class="ui-card__body ui-stack">
                <?= ui_field('select', ['name' => 'item_id', 'id' => 'ai-s-item', 'label' => 'Reading', 'options' => []]) ?>
                <div><?= ui_button('Write summary', ['variant' => 'primary', 'icon' => 'sparkles', 'attrs' => ['data-action' => 'ai.summary']]) ?></div>
                <div data-ai-s-out hidden>
                    <?= ui_field('textarea', ['name' => 'summary', 'id' => 'ai-s-text', 'label' => 'Summary', 'attrs' => ['rows' => 10, 'maxlength' => 4000]]) ?>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <?= ui_button('Add as reading', ['variant' => 'primary', 'icon' => 'check', 'attrs' => ['data-action' => 'ai.summary-apply']]) ?>
                        <p class="ui-meta" data-ai-s-result aria-live="polite"></p>
                    </div>
                </div>
            </div>
        </section>
    </section>

    <section id="panel-prompts" data-tab-panel="prompts" class="ui-tab-panel" hidden>
        <section class="ui-card" aria-labelledby="ai-p-title">
            <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title" id="ai-p-title">Sermon review</h2></div>
            <div class="ui-card__body ui-stack">
                <?= ui_field('select', ['name' => 'item_id', 'id' => 'ai-p-item', 'label' => 'Assignment', 'options' => []]) ?>
                <?= ui_field('textarea', ['name' => 'notes', 'id' => 'ai-p-notes', 'label' => 'About the sermon', 'info' => 'Its title, text and main points, in a few lines. The recording itself is not sent.', 'attrs' => ['rows' => 5, 'maxlength' => 8000]]) ?>
                <div><?= ui_button('Write prompts', ['variant' => 'primary', 'icon' => 'sparkles', 'attrs' => ['data-action' => 'ai.prompts']]) ?></div>
                <div data-ai-p-out hidden>
                    <?= ui_field('textarea', ['name' => 'prompts', 'id' => 'ai-p-text', 'label' => 'Questions', 'hint' => 'One per line, up to 12.', 'attrs' => ['rows' => 8]]) ?>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <?= ui_button('Use these', ['variant' => 'primary', 'icon' => 'check', 'attrs' => ['data-action' => 'ai.prompts-apply']]) ?>
                        <p class="ui-meta" data-ai-p-result aria-live="polite"></p>
                    </div>
                </div>
            </div>
        </section>
    </section>
</div>
<?php require_once '../../includes/footer.php'; ?>
