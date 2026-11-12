# Trainings: exams and the question bank (PR 2)

Plan: `TRAININGS-PLAN.md` (owner's answers 9, 10, 11 and 12c). Code: `includes/exams/` (entry `bootstrap.php`, after
`includes/db.php`), `api/exams_api.php`, `modules/exams/`, `assets/ui/js/pages/exams.js` (the bank, set-up and results) and
`assets/ui/js/pages/exam-take.js` (taking an exam: one question per screen). Schema:
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
- **Straight into an exam** (owner, 12 Nov 2026: "add and save at once"): the exam set-up page
  (`modules/exams/setup`) has **Import questions** and **Download template** too. There the bank is the training's (no
  choice), the rows are saved as Ready, and the confirm (`import_confirm` with `item` and the form as the tutor sees
  it) adds them to the exam and saves it in the same step (`exam_import_into_exam()`); the toast says what was added.
  - The save uses the form's rules and ticks, so afterwards the page and the exam agree. A rule that doesn't pass
    (say, closing before it opens) saves nothing: the questions are in the bank and ticked, and the page says what to fix.
  - People have started: the server answers `CONFIRM_STARTED` before importing anything, and the page asks first
    ("N people have started. Each paper is fixed when it starts, so theirs won't change; new starters get the new
    questions."). On **Add**, the questions are added, never removed (`exam_save(..., $addAfterStart)`); the time, pass
    mark and marking rules stay fixed. A paper is fixed at the start: `exam_start()` writes the attempt's own rows
    (`training_attempt_answers`) and marking reads only those.
  - The file's questions that were already in the bank (found by `import_hash`) and are Ready join the exam too, so a
    re-import adds nothing twice to the bank or the exam (and saves nothing). Drafts and archived ones are left out,
    and counted. The questions stay in the bank, so other exams can use them.
  - Rows with no explanation: the check offers **Suggest missing explanations**. After the import it runs PR 4b's
    flow (`approve_check`): AI suggestions to check and edit, stored only on **Save** (`approve`); **Cancel** stores
    nothing. Off by default.
- **Finding the bank:** **Question bank** in the HOD Academy header and on each training's builder (Trainings Edit,
  through `exam_url_bank()`), and the global search ("question bank", "questions", "import questions"), from the
  Academy's search-only `links` entry in `includes/ui/nav.php`.

## 3. Exams (`engine.php`), "fair and firm"

One exam per training item of type `exam` (`training_exams.item_id`, UNIQUE).

| Rule | How |
| ---- | --- |
| Opens and closes | `window_open` / `window_close` (Lagos) bound when an attempt may **start**. |
| Attempts | Set per exam: **Attempts allowed** 1, 2 or 3 (`training_exams.attempts_allowed`, default 1; owner, 12 Nov 2026). One row per attempt, `training_attempts.attempt_no`, UNIQUE (`exam_id`, `user_id`, `attempt_no`) claimed by insert (never check-then-insert), so two tabs pressing Start share one. A learner retakes on their own (`start` begins the next attempt) until they pass or run out, while the window is open. Each retake is a fresh paper: a new shuffle, a new timer, the same window rules. Every attempt is kept. Once people have started, attempts can go up, not down. Migration `20261209090200` (existing rows become attempt 1); until it runs every exam is one attempt (`exams_retakes_ready()`). |
| Countdown | The deadline is fixed on the server at Start: the earlier of start + minutes and `window_close`. The page shows the server's seconds left and re-syncs on every saved answer. |
| Shuffled per person | Question order and option order per attempt (`random_int`, Fisher–Yates), stored in `training_attempt_answers`. True/false keeps True, False. |
| Nothing right sent | The browser gets each question's text and its options in this person's order as shown positions; never `correct_json`, never the explanation. Answers come back as shown positions and are mapped on the server. |
| Auto-submit at zero | The page hands in when its clock reaches zero. The server also ends every attempt past its deadline + 20 s whenever an exam is opened, answered or looked at (`exam_finalize_due()`): `ended_by` = `timer`, or `closed` when the window's close cut it short. An answer after that hands the paper in instead. |
| Tab-leave notes | `visibilitychange` → `leave` / `return` events; after `leave_alert` leaves (default 3) the people who set the exam up get a bell (and push), once per attempt (a retake counts afresh, and the bell names the attempt). The tutor sees the timeline. |
| Marking | On the server, once, when the attempt ends. One answer and true/false: all or nothing. Several answers: all or nothing, or with **part marks** when the exam says so: (right picked − wrong picked) / right × marks, never below 0. |
| Best score counts | `exam_best_attempt()`: the highest percentage handed in (the earlier on a tie). It decides pass/fail for training progress and completion: `exam_progress_hook()` writes the best attempt's score to `training_progress` (`data.attempt_id`, `attempt_no`, `attempts`) after every hand-in, so a worse retake never lowers it, then `trainings_recheck_enrolment()`. |
| Firm once started | The questions, minutes, pass mark, shuffle and part marks cannot change once anyone has started; the times, the leave alert and more attempts can. One exception, the set-up page's import after the tutor's confirm: questions can be added (never removed) for new starters. Every paper is fixed at its start (its own `training_attempt_answers` rows), so started papers never change. |

**Results.** A learner sees the score, the percentage and Passed / Not passed straight away. For the questions they
got **wrong** they see their answer, the right answer and the stored explanation, only when nobody can still be
helped by them (`exam_review_state()`): at the exam's closing time; with no closing time, the day after the
training's end date; with neither (self-paced), when a tutor presses **Show answers** on the set-up page. A tutor may
also show them earlier (and hide them again); the learner is told when they will show, before starting and on the
result. With retakes there is a second condition, for that learner: they can't sit it again (no attempt left, or they
passed, or it has closed). Never while another attempt is still theirs, whatever the review rule or the tutor's
switch says (`exam_result_for_learner()`, `review_when` = `attempts` until then).

The take view (`take`, `start`, `submit`) carries `attempts_allowed`, `attempts_used`, `attempts_left`, `attempt_no`,
`can_retake` (an attempt is left, not passed, the window open), `best` {score, max, percent, passed, attempt_no} and
`attempts` (each one, `best` marked). The take page shows "Try again (attempt N of M)" from these. Marking never calls
the AI: explanations are written once per question (by the tutor, or by PR 4's AI with approval). Tutors see every
attempt, numbered, with the best one marked (and the enrolled people who have not started; the summary counts people), each attempt in full with its timeline, and can hand in an
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
  `exam_url_take($itemId)` → `/modules/exams/take?item=N` (learner: "Start exam"). The bank: `exam_url_bank($trainingId)` →
  `/modules/exams/?training=N` ("Question bank" in the Academy header and the builder).

## 6. For PR 4 (AI)

Save AI drafts with `exam_question_save($pdo, null, $trainingId, $q + ['status' => 'draft'], $userId, 'ai')`: the same
checks, the same uniqueness. Write explanations into `training_questions.explanation`; nothing in marking calls the AI.

## 7. AI help (PR 4b, `includes/exams/ai.php`, `modules/exams/ai.php`)

Gemini through the shared client `HOD\Mail\Ai\Gemini` (Mail's; a prompt may be text or parts, so a PDF goes inline as
`application/pdf`; the call's token counts are kept). Everything for the Academy's staff
(`can('trainings', 'edit', 'church')`); every call through `exams_ai_call()`.

| What | How |
| ---- | --- |
| Questions | From pasted notes, an uploaded PDF (read, sent, never stored; 10 MB), or the training's readings and slides (`details.text` and `details.slides_text`, chosen per item). Each question comes back through `exam_question_check()` and is saved as a **draft**, source `ai`; repeats are skipped by the content hash, unusable ones left out and counted. |
| Explanations | Written once, in the approve flow: **Ready** (one question, or several picked) asks Gemini once (batches of 15) for the ones with no explanation and shows the suggestions to check and edit. `approve` stores them and marks the questions Ready; Cancel stores nothing. |
| Outline | A proposal of cards and items for the training's length; the tutor edits or unticks, then **Apply** creates them through PR 1's `trainings_section_add()` / `trainings_item_save()` (empty cards are filled first). |
| Summary | Of one reading (text and slides); **Add as reading** creates an optional reading "Summary: …" in the same card. |
| Review prompts | From the tutor's notes on a sermon (never the recording); **Use these** puts them on the assignment (`details.prompts`, as PR 1 keeps them: up to 12 lines of 300 characters). |

**What is sent:** only what staff chose: their notes, their PDF, the training's readings and slides, the bank's
questions. Never a learner, a name, an attempt or an answer (AGENTS.md: the second written exception).

**Guards:** a daily token cap for the whole church, `TRAININGS_AI_DAILY_TOKENS` in `.env` (default 1,500,000, about
US$1–4 a day at Gemini 2.5 Flash prices), with a clear message when it is reached; output limits per task
(questions 8,192 tokens, explanations 4,096, outline 4,096, summary 1,500, prompts 1,024); 30 calls per person per
hour; 60,000 characters of source text per call. `training_ai_requests` logs every call: who, the task, the training,
the model, the tokens, ok or why not, and never the text. The page shows today's meter.
