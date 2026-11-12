<?php
// /modules/exams/take.php
// Taking an exam (Trainings PR 2): the rules, Start, the questions with a
// countdown, Hand in, and the result. One attempt; the clock, the marking
// and the answers live on the server (includes/exams/engine.php). Behaviour
// in assets/ui/js/pages/exams.js. Guide: docs/EXAMS.md.
//
//   /modules/exams/take?item=<training_items.id>
$ui = [
    'title' => 'Your exam',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/exams.js'],
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
if (exams_learner_enrolment($pdo, $item, (int) $_SESSION['user_id']) === null) {
    echo ui_no_access('This exam is for people enrolled in ' . $item['training_title'] . '.', 'Not enrolled');
    require_once '../../includes/footer.php';
    exit;
}
echo ui_json_script('exams-config', ['page' => 'take', 'item' => (int) $item['id']]);
?>
<div class="ui-page ui-page--narrow" data-exams="take">
    <?= ui_page_header([
        'title' => 'Your exam',
        'description' => $item['title'] . ' · ' . $item['training_title'],
        'info' => 'You have one attempt. Once you start, the clock runs even if you close the page, and at zero your answers are handed in by themselves. Each answer is saved as you choose it. Stay on this tab: leaving it is noted for your tutor.',
    ]) ?>

    <div data-ex-take aria-live="polite"><p class="ui-meta">Loading…</p></div>

    <div class="sticky bottom-0 z-10 -mx-4 mt-4 flex items-center justify-between gap-3 border-t border-line bg-surface px-4 py-3" data-ex-bar hidden>
        <p class="font-semibold tabular-nums" data-ex-clock role="timer" aria-live="off">--:--</p>
        <p class="ui-meta" data-ex-saved aria-live="polite"></p>
        <?= ui_button('Hand in', ['variant' => 'primary', 'icon' => 'send', 'attrs' => ['data-action' => 'ex.submit']]) ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
