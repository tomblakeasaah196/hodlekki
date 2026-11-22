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

## 8. Standalone tests (`includes/trainings/tests.php`)

Owner, 21 Nov 2026: the School of the Word (Sunday morning and the Rhema evening, a class every Tuesday evening) sets a
test each month, until now on Google Forms. A **test** stands on its own: no course, questions uploaded for it alone,
open to every member, instant results, a certificate if the Academy wants one.

**One engine.** A test is a training of kind `test` with one card and one required exam item, so everything above
applies unchanged: the bank and the Excel import, the window, the countdown, shuffling, marking at hand-in, retakes,
the tutor's results. Nothing new in the schema.

| What | How |
| ---- | --- |
| Create | Academy › **New test** (`test_create` in `trainings_action()`, Trainings Create): a draft (self-paced, out of the catalogue), then straight to the exam set-up page for the questions and rules. |
| Set up | The set-up page shows a **Test** card for a test: the name, **Certificate for those who pass**, **Publish** / **Unpublish** (`test_save`, `test_publish` in `exams_action()`, the tutors' check), the link and its QR code (inline SVG; **Download QR** as PNG). Publishing needs the exam saved with questions. Unpublishing keeps every attempt. |
| Who | Every member while it is published: opening it enrols them (`exams_learner_enrolment()` → `trainings_test_enrol()`, source `self`). Nothing to assign. |
| Where | Trainings › **Tests** (`trainings_tests_for_learner()`): open, coming and taken tests, with Start / Continue / Try again / See result and the certificate. The tab leads when one is waiting. Tests never show in My trainings, the catalogue or the Academy's training list; the Academy has its own **Tests** tab (`trainings_tests_overview()`: window, questions, took it, passed). |
| Link | `trainings_test_url()` → `/test?t=<item>` (`test.php`): signed in, straight to the test; otherwise the sign-in page, then the test. |
| Tries | 1 to `EXAM_TEST_ATTEMPTS_MAX` (5) per test; course exams keep 1–3. |
| Answers | `exam_review_state()` says `test`: open at once. `exam_result_for_learner()` still holds the right answers back while the person has another try; meanwhile `missed` lists the questions they got wrong (the question only). Once they pass or use their last try, they see their answer, the right one and the explanation. |
| Certificate | On a pass, when switched on: the pass completes the training (`trainings_check_completion()`), which issues it (`trainings_certificate_auto()`). The result offers **Download certificate**. One bell, linking to the test ("Certificate ready", else "Test passed"). |
| The result | `modules/exams/take.php` + `exam-take.js`: the score, Right / Missed / Marks, tries left, Try again, the certificate. Confetti on a pass and a short sway on a miss, right after handing in only, never with reduced motion. |

Tests: the "Standalone tests" section of `tests/trainings/run.php`.

## 9. The QR entry (`test.php`, `includes/trainings/entry.php`)

Owner, 21-22 Nov 2026: "very very short, punchy and lean… everything should not take anyone more than 2 minutes… if
IDI has to intervene for anyone then we have failed, unless they don't have their numbers on the system at all." People
scan the test's QR code (or open its link) and a short **conversation** signs them in, in the JBS Praxis forms' manner:
one message at a time after a moment of typing, answers typed in the box or tapped as pills, a rail (Phone · Email ·
Password · Test) that only moves forward. Messages rise in, pills pop in one after another; nothing moves with reduced
motion. The password box is a real one (dots, an eye to show it) so Chrome, Edge and Safari offer the saved HOD password
and offer to save a new one (owner, 22 Nov); the conversation shows dots. Signed in for 30 days
(`auth_remember_issue()`, as "keep me signed in"). A quiet "Engineered by JBS Praxis" line, as on the sign-in page.

The server decides every step (`trainings_entry_action()`); the page (`assets/ui/js/pages/test-entry.js`) only says it.

**The keyboard** (owner, 22 Nov: "I can't see where I am nor what I am typing"; Edge, Chrome and Safari must all be
perfect). The conversation always fits the part of the screen above the on-screen keyboard: test.php asks Chrome and
Edge to shrink for it (`interactive-widget=resizes-content`, kept by `pwa_head.php`), and for Safari, which slides the
page under the keyboard instead, `fit()` follows `window.visualViewport` (`--tq-h`, `--tq-top`; the page never
scrolls, only the messages). The keyboard stays up between answers: Send and the eye never take the focus from the
box, and the box is never locked while waiting. It closes when the next step is a tap. While it is up the header
shrinks to the progress rail.

| Who | The way in |
| --- | ---------- |
| Not on HOD | "We couldn't find this number… Please contact Information and Data Insights (IDI)." The one dead end. |
| A shared number | "Which one is you?" (first names and an initial). |
| Has a password | "Welcome back! Please type the password you use to sign in to HOD Lekki." Then, once, their email is confirmed with a code if it is not yet (or asked for, if HOD has none). |
| "I can't remember" | A code to their email (masked `tom…h@gmail.com`, with **Open Gmail** / Yahoo / Outlook and "check Spam or Junk"), then a new password typed twice, then in. No email on record: they type one, give their **birthday** (day and month), and the code goes there. |
| Never signed in | A code to the email on record, or to one they type, then they choose a password. "I don't have an email": they choose a password on the number alone (as the first-time set-up page allows). |
| "I don't use this email" | They give the one they use (with the birthday when a password is at stake); the right code makes it theirs and it replaces the old one in `users.email`. |
| Staff (`user_roles`) | The same way in as everyone (owner, 22 Nov: no dead ends). A staff password set or reset here alerts everyone who can open the Security Centre (IDI's people, through Roles & permissions; the Super Admins when nobody else can) and the person, in the app and by push (`trainings_entry_staff_alert()`). |
| A shared number, not listed | "None of these": their own number is not on HOD, so IDI. |

**One email, once.** A "real" email is a confirmed one, one waiting, or `users.email` when it is not an @hodlc.com
sign-in name. The right code confirms the address it went to through the email identity flow (as its link would) and
puts it in `users.email`; next time there is nothing to type but the password. Slips are caught ("Did you mean
tom@gmail.com?", `email_typo_hint()`); an address already on another account (families share one Gmail): "That email is on
another account. Please type another one." (owner). The only dead ends: a number HOD does not know, an account on hold.

**Birthdays** (`trainings_entry_parse_birthday()`): no AI (personal data stays here); a forgiving reader instead: "12
March", "march 12th", "12/03" (day first), "12-3-1990", "Mrach 12", "the 12th of sept". The year is ignored (it is often
a stand-in); a missing birthday is saved with the year 2000. A different one than on record: "Shall we update it to 12
March?", and yes replaces it (owner: replace freely, so the check captures birthdays more than it guards; logged).

**Codes** (`includes/auth_codes.php`, table `auth_email_codes`): six digits, only their `password_hash()` stored, one
use, 15 minutes, dead after 5 wrong tries, a new one replaces the last; one a minute per person, purpose and address
(a new address gets its code at once), 5 an hour per person, 300 an hour per IP (a full church hall shares one Wi-Fi;
numbers HOD does not know: 150 per 15 minutes per IP). The page sends the code by itself at
the sixth digit. A right code allows a new password for 15 minutes; a reset signs out every other session and sends the
"password changed" email.

**Guards:** the endpoint (`api/test_entry_api.php`) takes POST only and checks the CSRF token; the state between steps
lives in the session (`$_SESSION['test_entry']`, 20 minutes); numbers HOD does not know are recorded (`login_attempts`,
context `test_entry`) and more than 150 in 15 minutes from one IP are refused for a while.

**After the test:** anyone still without a confirmed email sees **Confirm your email** on the result
(`trainings_entry_email_prompt()`, `confirm_send`, `confirm_check`).

Tests: the "QR entry to a test" section of `tests/trainings/run.php`.
