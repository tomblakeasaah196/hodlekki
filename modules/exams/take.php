<?php
// /modules/exams/take.php
// Taking an exam (Trainings PR 2): the rules, Start, the questions with a
// countdown, Hand in, and the result. One attempt; the clock, the marking
// and the answers live on the server (includes/exams/engine.php). One
// question per screen (a wizard): behaviour in assets/ui/js/pages/exam-take.js,
// styles in assets/ui/src/app.css "Exam wizard". Guide: docs/EXAMS.md.
//
//   /modules/exams/take?item=<training_items.id>
$ui = [
    'title' => 'Your exam',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/exam-take.js'],
];
require_once '../../includes/header.php';
require_once __DIR__ . '/../../includes/exams/access.php';

$item = exams_ready($pdo) ? exam_item($pdo, (int) ($_GET['item'] ?? 0)) : null;
if ($item === null) {
    echo '<div class="ui-page ui-page--narrow">' . ui_empty(['icon' => 'file-text', 'title' => 'Exam not found', 'text' => 'It was removed, or the link is wrong.']) . '</div>';
    require_once '../../includes/footer.php';
    exit;
}
// Access: Trainings on their own records and an enrolment in the training (PR 1); the API checks every action again.
// A standalone test (docs/EXAMS.md §8) is open to every member while published: this enrols them.
$isTest = exams_item_is_test($item);
if (exams_learner_enrolment($pdo, $item, (int) $_SESSION['user_id']) === null) {
    echo $isTest ? ui_no_access('It may have closed, or not opened yet.', 'This test is not open')
        : ui_no_access('This exam is for people enrolled in ' . $item['training_title'] . '.', 'Not enrolled');
    require_once '../../includes/footer.php';
    exit;
}
echo ui_json_script('exams-config', ['page' => 'take', 'item' => (int) $item['id'], 'test' => $isTest]);
// Retakes: attempts are set per exam (docs/EXAMS.md §3).
$tries = (int) (exam_get($pdo, (int) $item['id'])['attempts_allowed'] ?? 1);
?>
<div class="ui-page ui-page--narrow xt" data-exam-take>
    <?= ui_page_header([
        'title' => $isTest ? $item['title'] : 'Your exam',
        'description' => $isTest ? 'Test' : $item['title'] . ' · ' . $item['training_title'],
        'back' => $isTest ? ['href' => '/modules/trainings/#tab=tests', 'label' => 'Trainings'] : null,
        'info' => ($tries > 1 ? 'You have ' . $tries . ' attempts, until you pass; your best score counts.' : 'You have one attempt.') . ' Once you start, the clock runs even if you close the page, and at zero your answers are handed in by themselves. Each answer is saved as you choose it. Stay on this tab: leaving it is noted for your tutor.',
    ]) ?>

    <div data-xt-view aria-live="polite"><p class="ui-meta">Loading…</p></div>

    <div data-xt-run hidden>
        <div class="xt-head">
            <div class="xt-head__row">
                <p class="xt-count"><span data-xt-pos>Question 1</span> <span class="xt-count__of" data-xt-of></span></p>
                <p class="xt-timer" role="timer" aria-live="off" data-xt-timer><?= ui_icon('clock') ?><span data-xt-clock>--:--</span><span class="sr-only"> left</span></p>
            </div>
            <div class="xt-bar" role="progressbar" aria-label="Where you are" aria-valuemin="1" aria-valuemax="1" aria-valuenow="1" data-xt-bar><span class="xt-bar__fill" data-xt-fill></span></div>
            <div class="xt-head__foot">
                <p class="xt-saved" data-xt-saved aria-live="polite"></p>
                <button type="button" class="xt-maptoggle" aria-expanded="false" aria-controls="xt-map" data-action="xt.map"><?= ui_icon('list-ordered') ?><span data-xt-answered></span><?= ui_icon('chevron-down') ?></button>
            </div>
            <nav class="xt-map" id="xt-map" aria-label="All questions" hidden>
                <ol class="xt-map__grid" data-xt-map></ol>
                <p class="xt-map__key"><span class="xt-key xt-key--done">Answered</span><span class="xt-key xt-key--flag">Flagged</span><span class="xt-key">Not yet</span></p>
            </nav>
        </div>

        <div data-xt-stage></div>

        <div class="xt-foot">
            <?= ui_button('Back', ['icon' => 'arrow-left', 'attrs' => ['data-action' => 'xt.back', 'data-xt-back' => true]]) ?>
            <button type="button" class="ui-btn ui-btn--primary" data-action="xt.next" data-xt-next><span>Next</span><?= ui_icon('arrow-right') ?></button>
        </div>
        <p class="sr-only" aria-live="polite" data-xt-say></p>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
