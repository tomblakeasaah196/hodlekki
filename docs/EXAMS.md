# Trainings: exams and the question bank (PR 2)

Plan: `TRAININGS-PLAN.md` (owner's answers 9, 10, 11 and 12c). Code: `includes/exams/` (entry `bootstrap.php`, after
`includes/db.php`), `api/exams_api.php`, `modules/exams/`, `assets/ui/js/pages/exams.js`. Schema:
`db/migrations/20261209090000_trainings_exams.sql`. Tests: `php tests/exams/run.php` (SQLite + zip, CI step "Trainings
exams and question import tests").

## 1. The question bank (`bank.php`)

- **Kinds:** one answer (`single`), several answers (`multiple`), true/false (`true_false`). Options A–E. No written
  answers in exams (the sermon review is PR 1's assignment).
- **One check for every source:** `exam_question_check()` cleans and checks a question whoever writes it: the form,
  the import, and PR 4's AI drafts. It refuses: no question, an unknown type, fewer than two options, a gap between
  options, the same option twice, a right answer on an empty option, two answers on a one-answer question, every
  option right on a several-answers question, marks outside 1–100. A missing explanation is only a warning.
- **Banks:** each training has its own (`training_id`); `training_id NULL` is the shared bank.
- **Never twice:** `import_hash` is a content hash (the bank, type, words, options and answer, normalised for case and
  spacing), UNIQUE in the table. A question cannot be in a bank twice, from any source.
- **Status:** `draft` → `approved` ("Ready") → `archived`. Only Ready questions can go in an exam. Sources: `manual`,
  `import`, `ai`.
- **Fixed once used:** after anyone has answered a question in an exam, only its explanation and topic can change.

## 2. The Excel import

- **Template** (`exam_template_bytes()`, `GET /api/exams_api.php?action=template`): a *Questions* sheet (headings,
  three example rows, a Type dropdown, a whole-number rule on Marks, the heading row frozen) and a *Guide* sheet that
  explains every column. Columns: Type, Question, Option A–E, Correct, Marks, Explanation, Topic.
- **Hand-rolled OOXML** (`xlsx.php`, ZipArchive): CI's test job has zip but no Composer packages, so the tests write the
  template and read it back. The reader handles shared strings, rich-text runs, inline strings, booleans, formula
  text, numbers as typed and skipped cells; it finds the *Questions* sheet by name (any case), else the first sheet,
  and the columns by their headings (any order). It trusts nothing: every part is size-capped before it is inflated,
  a DOCTYPE is refused, no network while parsing, at most 2,000 rows and 30 columns, a sheet that points outside the
  package is refused. Checked against openpyxl both ways.
- **Flow:** upload → `exam_import_check()` checks **every row** (as the form would, against the rest of the sheet and
  against the bank) → the screen shows each row: Add / Fix (with what to fix) / Skip (already there, or the same as
  row N) / Example → **Import** (`exam_import_confirm()`) adds the good rows, Ready unless the tutor unticks it. The
  file is never stored; the checked rows wait in `training_question_imports` for a day. A confirm runs once.
  Up to 500 questions per file, 5 MB.

## 3. Exams (`engine.php`), "fair and firm"

One exam per training item of type `exam` (`training_exams.item_id`, UNIQUE).

| Rule | How |
| ---- | --- |
| Opens and closes | `window_open` / `window_close` (Lagos) bound when an attempt may **start**. |
| One attempt | `training_attempts` UNIQUE (`exam_id`, `user_id`), claimed by insert; two tabs pressing Start share one. |
| Countdown | The deadline is fixed on the server at Start: the earlier of start + minutes and `window_close`. The page shows the server's seconds left and re-syncs on every saved answer. |
| Shuffled per person | Question order and option order per attempt (`random_int`, Fisher–Yates), stored in `training_attempt_answers`. True/false keeps True, False. |
| Nothing right sent | The browser gets each question's text and its options in this person's order as shown positions; never `correct_json`, never the explanation. Answers come back as shown positions and are mapped on the server. |
| Auto-submit at zero | The page hands in when its clock reaches zero. The server also ends every attempt past its deadline + 20 s whenever an exam is opened, answered or looked at (`exam_finalize_due()`): `ended_by` = `timer`, or `closed` when the window's close cut it short. An answer after that hands the paper in instead. |
| Tab-leave notes | `visibilitychange` → `leave` / `return` events; after `leave_alert` leaves (default 3) the people who set the exam up get a bell (and push), once per attempt. The tutor sees the timeline. |
| Marking | On the server, once, when the attempt ends. One answer and true/false: all or nothing. Several answers: all or nothing, or with **part marks** when the exam says so: (right picked − wrong picked) / right × marks, never below 0. |
| Firm once started | The questions, minutes, pass mark, shuffle and part marks cannot change once anyone has started; the times and the leave alert can. |

**Results.** A learner sees the score, the percentage and Passed / Not passed straight away. For the questions they
got **wrong** they see their answer, the right answer and the stored explanation, only when nobody can still be
helped by them (`exam_review_state()`): at the exam's closing time; with no closing time, the day after the
training's end date; with neither (self-paced), when a tutor presses **Show answers** on the set-up page. A tutor may
also show them earlier (and hide them again); the learner is told when they will show, before starting and on the
result. Marking never calls
the AI: explanations are written once per question (by the tutor, or by PR 4's AI with approval). Tutors see every
attempt (and the enrolled people who have not started), each attempt in full with its timeline, and can hand in an
attempt that is still running.

## 4. Access (`access.php`)

- Tutors: `can('trainings', 'edit', 'church')`, PR 1's staff check (`trainings_staff_can()`: Edit = any training,
  mark work; seats in an owner department have it). It is also what PR 1's training page asks before it links to the
  exam set-up. One rule for every bank, the shared one included.
- Learners: `can('trainings', 'view', perm_person($me))` (their own records, as PR 1's learner pages) and an enrolment
  in the training (`training_enrolments`, not withdrawn) while the training is `published`.
- The library never checks access; `exams_action()` decides every action.

## 5. Meeting points with PR 1 (Trainings core)

- Reads `trainings` (id, title, department_id, status), `training_items` (id, training_id, type, title),
  `training_enrolments` (id, training_id, user_id, status).
- On hand-in `exam_progress_hook()` writes `training_progress` (`enrolment_id`, `item_id`) through PR 1's
  `trainings_progress_put()`: status `completed` (PR 1's words: `completed` | `submitted` | `returned`), `data`
  `{score, max, percent, passed, attempt_id}`; PR 1 counts an exam towards completion only when `passed` is true. Then
  it calls `trainings_recheck_enrolment()`, which may complete the training. A failure is logged and never breaks
  marking. PR 1's `includes/trainings/bootstrap.php` loads this module; the hook loads PR 1 on first use.
- Links for PR 1's pages: `exam_url_setup($itemId)` → `/modules/exams/setup?item=N` (Academy builder: "Set up exam"),
  `exam_url_take($itemId)` → `/modules/exams/take?item=N` (learner: "Start exam"). The bank: `/modules/exams/?training=N`.

## 6. For PR 4 (AI)

Save AI drafts with `exam_question_save($pdo, null, $trainingId, $q + ['status' => 'draft'], $userId, 'ai')`: the same
checks, the same uniqueness. Write explanations into `training_questions.explanation`; nothing in marking calls the AI.
