<?php
// /modules/academy/index.php
// HOD Academy: the worker-training school. Staff (pastors and the Academy
// department) run programmes, cohorts, courses, exams, grading and the order
// of merit; members apply, then study, submit assignments and sit exams.
// Everything goes through /api/academy_api.php (request shapes unchanged).
$ui = [
    'title' => 'HOD Academy',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/academy.js'],
];
require_once '../../includes/header.php';

if (!isset($_SESSION['user_id'])) {
    echo ui_no_access('Please sign in again to open the Academy.');
    require_once '../../includes/footer.php';
    exit;
}

// Who runs the Academy: the same rule as api/academy_api.php, so the page
// shows the right view straight away (the API still enforces it).
$academyAdmin = in_array($_SESSION['active_role'] ?? '', ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'], true);
if (!$academyAdmin) {
    $deptStmt = $pdo->prepare("
        SELECT d.id FROM departments d
        JOIN user_departments ud ON d.id = ud.department_id
        WHERE ud.user_id = :uid AND (d.name LIKE '%Academy%' OR d.name LIKE '%School%')
    ");
    $deptStmt->execute(['uid' => $_SESSION['user_id']]);
    $academyAdmin = (bool) $deptStmt->fetch();
}

$academyName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

/** A field whose id is unique per form: ac-<form>-<name>. */
function academy_field(string $form, string $type, string $name, string $label, array $o = []): string
{
    return ui_field($type, ['name' => $name, 'id' => 'ac-' . $form . '-' . $name, 'label' => $label] + $o);
}

/** An empty <select> the script fills (programmes, courses, cohorts, facilitators). */
function academy_select(string $form, string $name, string $label, string $source, array $o = []): string
{
    $o['options'] = ['' => 'Loading…'];
    $o['attrs'] = ($o['attrs'] ?? []) + ['data-academy-options' => $source];
    return academy_field($form, 'select', $name, $label, $o);
}
?>
<div class="ui-page" data-academy-mode="<?= $academyAdmin ? 'admin' : 'student' ?>">
    <div class="ui-no-print">
        <?= ui_page_header([
            'title' => 'HOD Academy',
            'description' => $academyAdmin
                ? 'Programmes, cohorts, courses, grading and graduation.'
                : 'Your path to serving as a worker at Household of David.',
        ]) ?>
    </div>

<?php if ($academyAdmin): ?>
    <?= ui_tabs([
        'merit' => ['label' => 'Cohorts & merit', 'icon' => 'graduation-cap'],
        'grading' => ['label' => 'Grading', 'icon' => 'clipboard-list'],
        'setup' => ['label' => 'Programmes & applications', 'icon' => 'users'],
        'curriculum' => ['label' => 'Courses & exams', 'icon' => 'book-open'],
    ], 'merit', 'Academy sections') ?>

    <section id="panel-merit" data-tab-panel="merit" class="ui-tab-panel">
        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Order of merit</h2>
                    <p class="ui-card__description">Choose a cohort to score final panels and graduate students.</p>
                </div>
                <div class="w-full sm:w-64">
                    <label class="sr-only" for="ac-merit-batch">Cohort</label>
                    <select class="ui-select ui-select--sm" id="ac-merit-batch" data-academy-options="batches" data-action-change="academy.merit">
                        <option value="">Loading…</option>
                    </select>
                </div>
            </div>
            <div class="ui-card__body ui-card__body--flush" data-academy-merit>
                <?= ui_empty(['icon' => 'graduation-cap', 'title' => 'No cohort chosen', 'text' => 'Pick a cohort above to see its ranking.', 'compact' => true]) ?>
            </div>
        </div>
    </section>

    <section id="panel-grading" data-tab-panel="grading" class="ui-tab-panel" hidden>
        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Waiting to be graded</h2>
                    <p class="ui-card__description">Assignments students have handed in.</p>
                </div>
            </div>
            <ul class="ui-list" data-academy-grading>
                <li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>
            </ul>
        </div>
    </section>

    <section id="panel-setup" data-tab-panel="setup" class="ui-tab-panel" hidden>
        <div class="ui-grid ui-grid--2 mb-6">
            <form class="ui-card" id="ac-program-form" data-action="academy.save" data-ui-validate novalidate>
                <input type="hidden" name="action" value="create_program">
                <div class="ui-card__header"><h2 class="ui-card__title">New programme</h2></div>
                <div class="ui-card__body ui-form">
                    <?= academy_field('program', 'text', 'name', 'Programme name', ['required' => true, 'placeholder' => 'e.g. Membership Class']) ?>
                    <?= academy_field('program', 'textarea', 'description', 'Description', ['optional' => true, 'attrs' => ['rows' => '2']]) ?>
                </div>
                <div class="ui-card__footer"><?= ui_button('Create programme', ['variant' => 'primary', 'type' => 'submit']) ?></div>
            </form>

            <form class="ui-card" id="ac-batch-form" data-action="academy.save" data-ui-validate novalidate>
                <input type="hidden" name="action" value="create_batch">
                <div class="ui-card__header"><h2 class="ui-card__title">Open a cohort</h2></div>
                <div class="ui-card__body ui-form">
                    <?= academy_select('batch', 'program_id', 'Programme', 'programs', ['required' => true]) ?>
                    <?= academy_field('batch', 'text', 'batch_name', 'Cohort name', ['required' => true, 'placeholder' => 'e.g. Nov/Dec 2026']) ?>
                </div>
                <div class="ui-card__footer"><?= ui_button('Open cohort', ['variant' => 'primary', 'type' => 'submit']) ?></div>
            </form>
        </div>

        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Applications waiting</h2>
                    <p class="ui-card__description">Approve an applicant to enrol them in a cohort.</p>
                </div>
            </div>
            <div class="ui-card__body ui-card__body--flush">
                <div class="ui-table-wrap border-0">
                    <table class="ui-table ui-table--stack">
                        <thead><tr><th scope="col">Applicant</th><th scope="col">Phone</th><th scope="col">Class wanted</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody data-academy-applications>
                            <tr><td colspan="4"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section id="panel-curriculum" data-tab-panel="curriculum" class="ui-tab-panel" hidden>
        <div class="ui-grid ui-grid--2 mb-6">
            <form class="ui-card" id="ac-course-form" data-action="academy.save" data-ui-validate novalidate>
                <input type="hidden" name="action" value="create_course">
                <div class="ui-card__header"><h2 class="ui-card__title">New course</h2></div>
                <div class="ui-card__body ui-form">
                    <?= academy_select('course', 'program_id', 'Programme', 'programs', ['required' => true]) ?>
                    <?= academy_field('course', 'text', 'name', 'Course name', ['required' => true, 'placeholder' => 'e.g. The Holy Spirit']) ?>
                    <?= academy_select('course', 'instructor_id', 'Facilitator', 'instructors', ['optional' => true]) ?>
                    <div class="ui-form-grid">
                        <?= academy_field('course', 'date', 'start_date', 'Starts', ['optional' => true]) ?>
                        <?= academy_field('course', 'date', 'end_date', 'Ends', ['optional' => true]) ?>
                        <?= academy_field('course', 'number', 'timer_minutes', 'Exam time (minutes)', ['required' => true, 'value' => '30', 'inputmode' => 'numeric', 'attrs' => ['min' => '1', 'max' => '300']]) ?>
                        <?= academy_field('course', 'number', 'passing_percentage', 'Pass mark (%)', ['required' => true, 'value' => '70', 'inputmode' => 'numeric', 'attrs' => ['min' => '1', 'max' => '100']]) ?>
                    </div>
                </div>
                <div class="ui-card__footer"><?= ui_button('Create course', ['variant' => 'primary', 'type' => 'submit']) ?></div>
            </form>

            <form class="ui-card" id="ac-material-form" data-action="academy.save" data-ui-validate novalidate>
                <input type="hidden" name="action" value="add_material">
                <div class="ui-card__header"><h2 class="ui-card__title">Add course material</h2></div>
                <div class="ui-card__body ui-form">
                    <?= academy_select('material', 'course_id', 'Course', 'courses', ['required' => true]) ?>
                    <?= academy_field('material', 'text', 'title', 'Title', ['required' => true]) ?>
                    <?= academy_field('material', 'select', 'material_type', 'Kind', ['options' => ['Document' => 'Document (PDF or Word)', 'Link' => 'Link (YouTube or web page)'], 'attrs' => ['data-action-change' => 'academy.material-type']]) ?>
                    <div data-academy-material="Document">
                        <?= academy_field('material', 'file', 'file_upload', 'File', ['required' => true, 'hint' => 'PDF, DOC or DOCX.', 'attrs' => ['accept' => '.pdf,.doc,.docx']]) ?>
                    </div>
                    <div data-academy-material="Link" hidden>
                        <?= academy_field('material', 'url', 'file_url', 'Link', ['required' => true, 'placeholder' => 'https://', 'attrs' => ['disabled' => true]]) ?>
                    </div>
                </div>
                <div class="ui-card__footer"><?= ui_button('Publish material', ['variant' => 'primary', 'type' => 'submit']) ?></div>
            </form>

            <form class="ui-card" id="ac-assignment-form" data-action="academy.save" data-ui-validate novalidate>
                <input type="hidden" name="action" value="create_assignment">
                <div class="ui-card__header"><h2 class="ui-card__title">New assignment</h2></div>
                <div class="ui-card__body ui-form">
                    <?= academy_select('assignment', 'program_id', 'Programme', 'programs', ['required' => true]) ?>
                    <?= academy_field('assignment', 'text', 'title', 'Title', ['required' => true]) ?>
                    <div class="ui-form-grid">
                        <?= academy_field('assignment', 'select', 'assignment_type', 'Kind', ['options' => ['Sermon_Review' => 'Sermon review', 'Book_Review' => 'Book review', 'Group_Project' => 'Group project (slides)']]) ?>
                        <?= academy_field('assignment', 'datetime-local', 'deadline', 'Due', ['required' => true]) ?>
                    </div>
                </div>
                <div class="ui-card__footer"><?= ui_button('Set assignment', ['variant' => 'primary', 'type' => 'submit']) ?></div>
            </form>

            <form class="ui-card" id="ac-question-form" data-action="academy.save" data-ui-validate novalidate>
                <input type="hidden" name="action" value="add_exam_question">
                <div class="ui-card__header"><h2 class="ui-card__title">Add an exam question</h2></div>
                <div class="ui-card__body ui-form">
                    <?= academy_select('question', 'course_id', 'Course', 'courses', ['required' => true]) ?>
                    <?= academy_field('question', 'textarea', 'question', 'Question', ['required' => true, 'attrs' => ['rows' => '2']]) ?>
                    <div class="ui-form-grid">
                        <?= academy_field('question', 'text', 'option_a', 'Option A', ['required' => true]) ?>
                        <?= academy_field('question', 'text', 'option_b', 'Option B', ['required' => true]) ?>
                        <?= academy_field('question', 'text', 'option_c', 'Option C', ['required' => true]) ?>
                        <?= academy_field('question', 'text', 'option_d', 'Option D', ['optional' => true]) ?>
                    </div>
                    <?= academy_field('question', 'select', 'correct_option', 'Correct answer', ['options' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D']]) ?>
                </div>
                <div class="ui-card__footer"><?= ui_button('Save question', ['variant' => 'primary', 'type' => 'submit']) ?></div>
            </form>
        </div>

        <div class="ui-card">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Publishing</h2>
                    <p class="ui-card__description">Show courses to students and open their exams.</p>
                </div>
            </div>
            <div class="ui-card__body ui-card__body--flush">
                <div class="ui-table-wrap border-0">
                    <table class="ui-table ui-table--stack">
                        <thead><tr><th scope="col">Course</th><th scope="col">Students see it</th><th scope="col">Exam</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody data-academy-publishing>
                            <tr><td colspan="4"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <?= ui_dialog_start('ac-approve', ['title' => 'Enrol in a cohort', 'description' => 'Choose the cohort this applicant joins.', 'size' => 'sm']) ?>
        <form class="ui-form" id="ac-approve-form" data-action="academy.save" data-ui-validate novalidate>
            <input type="hidden" name="action" value="approve_application">
            <input type="hidden" name="application_id">
            <p class="text-sm text-ink-muted" data-academy-approve-name></p>
            <?= academy_select('approve', 'batch_id', 'Cohort', 'batches', ['required' => true]) ?>
        </form>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Approve and enrol', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ac-approve-form']])
    ) ?>

    <?= ui_dialog_start('ac-panel', ['title' => 'Final panel', 'description' => 'Score each area out of 10.']) ?>
        <form class="ui-form" id="ac-panel-form" data-action="academy.save" data-ui-validate novalidate>
            <input type="hidden" name="action" value="submit_interview_score">
            <input type="hidden" name="enrollment_id">
            <p class="text-sm text-ink-muted">Student: <strong class="text-ink" data-academy-panel-name></strong></p>
            <div class="ui-form-grid ui-form-grid--3">
                <?= academy_field('panel', 'number', 'doctrinal_score', 'Doctrine', ['required' => true, 'inputmode' => 'numeric', 'attrs' => ['min' => '0', 'max' => '10']]) ?>
                <?= academy_field('panel', 'number', 'zeal_score', 'Zeal for service', ['required' => true, 'inputmode' => 'numeric', 'attrs' => ['min' => '0', 'max' => '10']]) ?>
                <?= academy_field('panel', 'number', 'character_score', 'Character', ['required' => true, 'inputmode' => 'numeric', 'attrs' => ['min' => '0', 'max' => '10']]) ?>
            </div>
            <?= academy_field('panel', 'textarea', 'comments', 'Panel comments', ['optional' => true, 'attrs' => ['rows' => '3']]) ?>
        </form>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Save scores', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ac-panel-form']])
    ) ?>

<?php else: ?>
    <div data-academy-state="loading">
        <div class="ui-card"><div class="ui-card__body"><span class="ui-skeleton ui-skeleton--title w-1/3"></span><span class="ui-skeleton ui-skeleton--text mt-3 w-2/3"></span></div></div>
    </div>

    <div data-academy-state="apply" hidden>
        <form class="ui-card" id="ac-apply-form" data-action="academy.apply" data-ui-validate novalidate>
            <input type="hidden" name="action" value="submit_application">
            <div class="ui-card__header ui-card__header--divided">
                <div>
                    <h2 class="ui-card__title">Apply to HOD Academy</h2>
                    <p class="ui-card__description">The first step to serving as a worker.</p>
                </div>
            </div>
            <div class="ui-card__body ui-stack">
                <fieldset class="ui-fieldset">
                    <legend class="ui-legend">About you</legend>
                    <div class="ui-form-grid">
                        <?= academy_field('apply', 'text', 'full_name', 'Full name', ['required' => true, 'autocomplete' => 'name', 'value' => $academyName]) ?>
                        <?= academy_field('apply', 'tel', 'phone_number', 'Phone number', ['required' => true, 'autocomplete' => 'tel', 'inputmode' => 'tel']) ?>
                        <?= academy_field('apply', 'tel', 'whatsapp_number', 'WhatsApp number', ['optional' => true, 'inputmode' => 'tel']) ?>
                        <?= academy_field('apply', 'email', 'email_address', 'Email', ['optional' => true, 'autocomplete' => 'email']) ?>
                        <?= academy_field('apply', 'date', 'birth_date', 'Date of birth', ['optional' => true, 'autocomplete' => 'bday']) ?>
                        <?= academy_field('apply', 'select', 'marital_status', 'Marital status', ['options' => ['Single' => 'Single', 'Married' => 'Married', 'Other' => 'Other'], 'attrs' => ['data-action-change' => 'academy.marital']]) ?>
                        <div data-academy-anniversary hidden>
                            <?= academy_field('apply', 'date', 'wedding_anniversary', 'Wedding anniversary', ['optional' => true]) ?>
                        </div>
                        <?= academy_field('apply', 'text', 'residential_address', 'Home address', ['optional' => true, 'autocomplete' => 'street-address', 'class' => 'ui-span-full']) ?>
                        <?= academy_field('apply', 'text', 'occupation', 'Occupation or school', ['optional' => true]) ?>
                        <?= academy_field('apply', 'text', 'work_address', 'Work or school address', ['optional' => true]) ?>
                    </div>
                </fieldset>

                <fieldset class="ui-fieldset">
                    <legend class="ui-legend">Your walk with God</legend>
                    <div class="ui-form-grid">
                        <?= academy_field('apply', 'text', 'salvation_info', 'Salvation (when and where)', ['optional' => true, 'placeholder' => 'e.g. Feb 2024, Douala']) ?>
                        <?= academy_field('apply', 'text', 'baptism_info', 'Water baptism (when and where)', ['optional' => true, 'placeholder' => 'e.g. May 2024, Douala']) ?>
                        <?= academy_field('apply', 'text', 'holy_spirit_info', 'Holy Spirit baptism (when and where)', ['optional' => true, 'placeholder' => 'e.g. Aug 2025, Lagos']) ?>
                        <?= academy_field('apply', 'text', 'previous_church', 'Previous church', ['optional' => true]) ?>
                        <?= academy_field('apply', 'text', 'invited_by', 'Who invited you?', ['optional' => true]) ?>
                        <?= academy_field('apply', 'text', 'tribe_captain', 'Tribe or captain', ['optional' => true]) ?>
                        <?= academy_field('apply', 'text', 'other_ministries', 'Serving elsewhere now?', ['optional' => true, 'class' => 'ui-span-full']) ?>
                    </div>
                </fieldset>

                <fieldset class="ui-fieldset">
                    <legend class="ui-legend">Serving</legend>
                    <div class="ui-form-grid">
                        <?= academy_field('apply', 'textarea', 'past_service_experience', 'Where you have served before', ['optional' => true, 'attrs' => ['rows' => '2']]) ?>
                        <?= academy_field('apply', 'textarea', 'service_interests', 'Departments you would like to serve in', ['optional' => true, 'placeholder' => 'e.g. Sound, Choir', 'attrs' => ['rows' => '2']]) ?>
                        <?= academy_field('apply', 'textarea', 'why_hod', 'Why Household of David?', ['optional' => true, 'class' => 'ui-span-full', 'attrs' => ['rows' => '2']]) ?>
                    </div>
                </fieldset>

                <fieldset class="ui-fieldset">
                    <legend class="ui-legend">Class and questions</legend>
                    <div class="ui-form-grid">
<?php
$academyMonths = [];
$m = new DateTimeImmutable('first day of this month');
for ($i = 0; $i < 12; $i++) {
    $academyMonths[$m->modify("+{$i} months")->format('Y-m')] = $m->modify("+{$i} months")->format('F Y');
}
?>
                        <?= academy_field('apply', 'select', 'class_date', 'Class you can attend', ['required' => true, 'options' => ['' => 'Choose a month'] + $academyMonths]) ?>
                        <?= academy_field('apply', 'textarea', 'comments', 'Questions for the Academy team', ['optional' => true, 'class' => 'ui-span-full', 'attrs' => ['rows' => '2']]) ?>
                    </div>
                </fieldset>
            </div>
            <div class="ui-card__footer"><?= ui_button('Send application', ['variant' => 'primary', 'type' => 'submit']) ?></div>
        </form>
    </div>

    <div data-academy-state="pending" hidden>
        <div class="ui-card">
            <?= ui_empty(['icon' => 'clock', 'title' => 'Application under review', 'text' => 'We will tell you when you are placed in a cohort.']) ?>
        </div>
    </div>

    <div data-academy-state="missing" hidden>
        <div class="ui-card">
            <?= ui_empty(['icon' => 'circle-help', 'title' => 'Waiting for a cohort', 'text' => 'Ask the Academy team to add you to one.']) ?>
        </div>
    </div>

    <div data-academy-state="enrolled" hidden>
        <?= ui_tabs([
            'board' => ['label' => 'Noticeboard', 'icon' => 'messages-square'],
            'study' => ['label' => 'Study', 'icon' => 'book-open'],
            'exams' => ['label' => 'Exams', 'icon' => 'clipboard-list'],
        ], 'board', 'Classroom sections') ?>

        <section id="panel-board" data-tab-panel="board" class="ui-tab-panel">
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="ui-card lg:col-span-2">
                    <div class="ui-card__header ui-card__header--divided">
                        <div class="min-w-0 flex-1">
                            <h2 class="ui-card__title">Cohort noticeboard</h2>
                            <p class="ui-card__description" data-academy-batch-name></p>
                        </div>
                        <?= ui_button('Refresh', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'refresh-cw', 'icon_only' => true, 'attrs' => ['data-action' => 'academy.forum']]) ?>
                    </div>
                    <div class="ui-card__body max-h-[28rem] overflow-y-auto" data-academy-forum aria-live="polite"></div>
                    <form class="ui-card__footer flex gap-2" id="ac-forum-form" data-action="academy.post" data-ui-validate novalidate>
                        <input type="hidden" name="action" value="post_forum_message">
                        <input type="hidden" name="batch_id" data-academy-batch-id>
                        <label class="sr-only" for="ac-forum-message">Message</label>
                        <input class="ui-input min-w-0 flex-1" id="ac-forum-message" name="message" required autocomplete="off" placeholder="Write to your cohort" data-error="Write a message first.">
                        <?= ui_button('Send', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send']) ?>
                    </form>
                </div>
                <div class="ui-card">
                    <div class="ui-card__header ui-card__header--divided"><h2 class="ui-card__title">Timetable</h2></div>
                    <ul class="ui-list" data-academy-events></ul>
                </div>
            </div>
        </section>

        <section id="panel-study" data-tab-panel="study" class="ui-tab-panel" hidden>
            <h2 class="ui-h3 mb-3">Assignments</h2>
            <div class="ui-card mb-6"><ul class="ui-list" data-academy-assignments></ul></div>
            <h2 class="ui-h3 mb-3">Course materials</h2>
            <div class="ui-grid ui-grid--2" data-academy-courses></div>
        </section>

        <section id="panel-exams" data-tab-panel="exams" class="ui-tab-panel" hidden>
            <div data-academy-exam-list>
                <div class="ui-card"><ul class="ui-list" data-academy-exams></ul></div>
            </div>

            <form class="ui-stack" id="ac-exam-form" data-action="academy.submit-exam" data-academy-exam hidden novalidate>
                <div class="ui-card sticky top-2 z-10">
                    <div class="ui-card__body flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <h2 class="ui-card__title" data-academy-exam-title>Exam</h2>
                            <p class="ui-meta">Stay on this page until you submit.</p>
                        </div>
                        <p class="ui-num text-2xl font-semibold text-ink" data-academy-timer aria-live="off">00:00</p>
                    </div>
                </div>
                <div class="ui-stack" data-academy-questions></div>
                <div class="flex justify-end"><?= ui_button('Submit answers', ['variant' => 'primary', 'size' => 'lg', 'type' => 'submit']) ?></div>
            </form>

            <div class="ui-card" data-academy-exam-result hidden>
                <div class="ui-card__body text-center">
                    <span class="ui-empty__icon mx-auto" data-academy-result-icon></span>
                    <h2 class="ui-h2 mt-3" data-academy-result-title></h2>
                    <p class="ui-lead mt-1" data-academy-result-text></p>
                    <ol class="mx-auto mt-6 max-w-md space-y-2 text-left" data-academy-result-breakdown></ol>
                    <div class="mt-6"><?= ui_button('Back to exams', ['variant' => 'secondary', 'attrs' => ['data-action' => 'academy.exam-done']]) ?></div>
                </div>
            </div>
        </section>
    </div>

    <div data-academy-state="graduated" hidden>
        <div class="ui-no-print mb-4 flex justify-end">
            <?= ui_button('Print result slip', ['variant' => 'secondary', 'icon' => 'printer', 'attrs' => ['data-action' => 'academy.print']]) ?>
        </div>
        <article class="ui-card mx-auto max-w-2xl">
            <div class="ui-card__body p-8 sm:p-10">
                <header class="border-b border-line pb-6 text-center">
                    <p class="ui-meta uppercase tracking-widest">Household of David, Lekki Centre</p>
                    <h2 class="ui-h1 mt-1">HOD Academy</h2>
                    <p class="mt-1 text-ink-muted">Result slip</p>
                </header>
                <dl class="ui-dl mt-6">
                    <dt>Student</dt><dd><?= ui_e($academyName !== '' ? $academyName : 'Graduate') ?></dd>
                    <dt>Status</dt><dd><span class="ui-badge ui-badge--success">Passed and certified</span></dd>
                    <dt>Date issued</dt><dd><?= ui_e(date('j F Y')) ?></dd>
                </dl>
                <footer class="mt-12 text-center">
                    <p class="font-display text-lg text-ink">Bolanle Uzo-Peters</p>
                    <div class="mx-auto mt-1 w-56 border-t border-line"></div>
                    <p class="mt-2 text-sm font-medium text-ink">Minister Bolanle Uzo-Peters</p>
                    <p class="ui-meta">Head of Department, HOD Academy</p>
                </footer>
            </div>
        </article>
    </div>

    <?= ui_dialog_start('ac-submit', ['title' => 'Hand in your work', 'description' => 'Type your response, attach a file, or both.']) ?>
        <form class="ui-form" id="ac-submit-form" data-action="academy.hand-in" novalidate>
            <input type="hidden" name="action" value="submit_assignment">
            <input type="hidden" name="assignment_id">
            <p class="text-sm font-medium text-ink" data-academy-submit-title></p>
            <?= academy_field('submit', 'textarea', 'submission_text', 'Your response', ['attrs' => ['rows' => '6']]) ?>
            <?= academy_field('submit', 'file', 'submission_file', 'Attach a file', ['hint' => 'Slides, Word or PDF.', 'attrs' => ['accept' => '.pdf,.doc,.docx,.ppt,.pptx']]) ?>
            <p class="ui-field-error" data-academy-submit-error aria-live="polite"></p>
        </form>
    <?= ui_dialog_end(
        ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
        . ui_button('Hand in', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'ac-submit-form']])
    ) ?>
<?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
