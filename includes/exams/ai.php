<?php
// /includes/exams/ai.php
//
// Trainings PR 4b: AI help for the Academy (owner's answer 12), on Gemini
// through the shared client HOD\Mail\Ai\Gemini (includes/mail/Ai/Gemini.php).
//
//   1. exam questions from source material: pasted notes, an uploaded PDF
//      (sent inline as application/pdf, read natively), or this training's
//      readings and slides (training_items.details text / slides_text);
//      saved as DRAFTS (source 'ai') through exam_question_check()
//   2. training content proposals: an outline of cards and items, a lesson
//      summary, sermon-review prompts; nothing is created until Apply
//   3. explanations: suggested once, in the approve flow; stored only when
//      the tutor confirms them (exams_action 'approve')
//   4. nothing AI-made reaches a learner without a person's click
//
// What is sent: ONLY training content a member of staff chose (notes they
// typed, a PDF they uploaded, the training's own readings and slides, the
// bank's questions). Never a learner, a name, an attempt or an answer. This
// is a written exception to the house rule (AGENTS.md, next to Mail's).
//
// Guards: a daily token cap for the whole church (training_ai_requests is the
// meter; TRAININGS_AI_DAILY_TOKENS in .env overrides it), output limits per
// call, a per-person hourly limit, and a size limit on every source. Every
// call is logged without its text. Tests replace the network with
// HOD\Mail\Ai\Gemini::fake().

require_once __DIR__ . '/bootstrap.php';
if (!function_exists('trainings_get') && is_file(dirname(__DIR__) . '/trainings/bootstrap.php')) {
    require_once dirname(__DIR__) . '/trainings/bootstrap.php';   // PR 1's builder, for the Apply buttons
}
if (!class_exists('HOD\\Mail\\Ai\\Gemini')) {
    require_once dirname(__DIR__) . '/mail/Ai/Ai.php';
    require_once dirname(__DIR__) . '/mail/Ai/Gemini.php';
}

use HOD\Mail\Ai\Gemini;

const EXAMS_AI_TASKS        = ['questions', 'explain', 'outline', 'summary', 'prompts'];
const EXAMS_AI_MAX_OUT      = ['questions' => 8192, 'explain' => 4096, 'outline' => 4096, 'summary' => 1500, 'prompts' => 1024];
const EXAMS_AI_TIMEOUT      = ['questions' => 90, 'explain' => 60, 'outline' => 60, 'summary' => 45, 'prompts' => 30];
const EXAMS_AI_TEMPERATURE  = ['questions' => 0.4, 'explain' => 0.2, 'outline' => 0.5, 'summary' => 0.3, 'prompts' => 0.4];
const EXAMS_AI_DAILY_TOKENS = 1500000;          // the whole church, per Lagos day (about US$1–4 at Gemini 2.5 Flash prices)
const EXAMS_AI_HOURLY       = 30;               // calls per person per hour
const EXAMS_AI_MAX_TEXT     = 60000;            // characters of source text per call
const EXAMS_AI_MAX_PDF      = 10 * 1024 * 1024; // bytes
const EXAMS_AI_MAX_QUESTIONS = 30;
const EXAMS_AI_EXPLAIN_BATCH = 15;

const EXAMS_AI_FAILURES = [
    'off'         => 'AI is not set up on this site (no Gemini key).',
    'busy'        => 'Gemini is busy. Try again in a minute.',
    'refused'     => 'Gemini refused the request. Tell the office if it keeps happening.',
    'blocked'     => 'Gemini would not answer that. Try other material.',
    'unreachable' => 'Gemini did not answer. Try again.',
    'empty'       => 'Gemini gave nothing back. Try again.',
    'shape'       => 'Gemini\'s answer could not be used. Try again.',
];

// ---------------------------------------------------------------------------
// Availability, the meter and the guards
// ---------------------------------------------------------------------------

function exams_ai_key(): string
{
    return function_exists('site_env') ? (string) site_env('GEMINI_API_KEY') : (string) ($_ENV['GEMINI_API_KEY'] ?? '');
}

function exams_ai_daily_cap(): int
{
    $env = function_exists('site_env') ? (string) site_env('TRAININGS_AI_DAILY_TOKENS') : (string) ($_ENV['TRAININGS_AI_DAILY_TOKENS'] ?? '');
    return ctype_digit($env) && (int) $env > 0 ? (int) $env : EXAMS_AI_DAILY_TOKENS;
}

/** Is AI help usable at all? @return array{ok:bool, reason:?string} */
function exams_ai_available(PDO $pdo): array
{
    if (exams_ai_key() === '') {
        return ['ok' => false, 'reason' => EXAMS_AI_FAILURES['off']];
    }
    if (!exams_table_exists($pdo, 'training_ai_requests')) {
        return ['ok' => false, 'reason' => 'AI help is being set up. Try again after the next update.'];
    }
    return ['ok' => true, 'reason' => null];
}

/** Today's meter (Lagos day) and this person's last hour. */
function exams_ai_usage(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): array
{
    $now ??= exams_now();
    $out = ['tokens' => 0, 'calls' => 0, 'cap' => exams_ai_daily_cap(), 'mine_hour' => 0, 'hourly' => EXAMS_AI_HOURLY];
    if (!exams_table_exists($pdo, 'training_ai_requests')) {
        return $out;
    }
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(total_tokens), 0), COUNT(*) FROM training_ai_requests WHERE created_at >= ?');
    $stmt->execute([$now->format('Y-m-d') . ' 00:00:00']);
    [$out['tokens'], $out['calls']] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_ai_requests WHERE user_id = ? AND created_at >= ?');
    $stmt->execute([$userId, exams_sql_dt($now->modify('-1 hour'))]);
    $out['mine_hour'] = (int) $stmt->fetchColumn();
    return $out;
}

/** Why a call may not go out now, or null. */
function exams_ai_blocked(PDO $pdo, int $userId): ?string
{
    $a = exams_ai_available($pdo);
    if (!$a['ok']) {
        return $a['reason'];
    }
    $u = exams_ai_usage($pdo, $userId);
    if ($u['tokens'] >= $u['cap']) {
        return 'Today\'s AI allowance for the church is used up. It starts again at midnight.';
    }
    if ($u['mine_hour'] >= $u['hourly']) {
        return 'You have used AI help ' . $u['hourly'] . ' times this hour. Try again later.';
    }
    return null;
}

/**
 * One Gemini call with the guards, and one log row (never its text).
 * $prompt: text, or parts (text and inline_data).
 * @return array{ok:bool, data?:array, text?:string, error?:string}
 */
function exams_ai_call(PDO $pdo, int $userId, string $task, ?int $trainingId, string $system, string|array $prompt, bool $json = true): array
{
    if (!in_array($task, EXAMS_AI_TASKS, true)) {
        throw new InvalidArgumentException('exams_ai_call: unknown task ' . $task);
    }
    $why = exams_ai_blocked($pdo, $userId);
    if ($why !== null) {
        return ['ok' => false, 'error' => $why];
    }
    $text = Gemini::generate($system, $prompt, EXAMS_AI_MAX_OUT[$task], $json, EXAMS_AI_TEMPERATURE[$task], EXAMS_AI_TIMEOUT[$task]);
    $usage = Gemini::$lastUsage ?? ['input' => 0, 'output' => 0, 'total' => 0];
    $data = $json ? Gemini::json($text) : null;
    $failure = $text === null ? (Gemini::$lastFailure ?? 'empty') : ($json && $data === null ? 'shape' : null);
    try {
        $pdo->prepare('INSERT INTO training_ai_requests (user_id, task, training_id, model, input_tokens, output_tokens, total_tokens, ok, failure, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $task, $trainingId, mb_substr((string) \HOD\Mail\Ai\Ai::config('model', ''), 0, 40),
                       (int) $usage['input'], (int) $usage['output'], (int) ($usage['total'] ?: $usage['input'] + $usage['output']),
                       $failure === null ? 1 : 0, $failure, exams_sql_dt(exams_now())]);
    } catch (Throwable $e) {
        error_log('[exams-ai] usage not logged: ' . $e->getMessage());
    }
    if ($failure !== null) {
        return ['ok' => false, 'error' => EXAMS_AI_FAILURES[$failure] ?? EXAMS_AI_FAILURES['empty']];
    }
    return $json ? ['ok' => true, 'data' => $data] : ['ok' => true, 'text' => (string) $text];
}

// ---------------------------------------------------------------------------
// Source material (staff-chosen training content only)
// ---------------------------------------------------------------------------

/** The training's readings that carry text or slides: what "from this training" offers. */
function exams_ai_source_items(PDO $pdo, int $trainingId): array
{
    if (!exams_table_exists($pdo, 'training_items')) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT id, title, details FROM training_items WHERE training_id = ? AND type = 'reading' ORDER BY section_id, position, id");
    $stmt->execute([$trainingId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string) $r['details'], true) ?: [];
        $text = trim((string) ($d['text'] ?? ''));
        $slides = exams_ai_slides_text($d);
        if ($text === '' && $slides === '') {
            continue;
        }
        $out[] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'slides' => $slides !== '', 'chars' => mb_strlen($text . $slides)];
    }
    return $out;
}

/** PR 1b keeps a deck's extracted text in details.slides_text (a string, or one entry per slide). */
function exams_ai_slides_text(array $details): string
{
    $s = $details['slides_text'] ?? '';
    if (is_array($s)) {
        $s = implode("\n\n", array_map(static fn ($x) => is_array($x) ? implode("\n", array_map('strval', $x)) : (string) $x, $s));
    }
    return trim((string) $s);
}

/**
 * The chosen readings' text and slides as one block (cut to the limit).
 * $itemIds empty = every reading of the training.
 */
function exams_ai_source_text(PDO $pdo, int $trainingId, array $itemIds = []): string
{
    $stmt = $pdo->prepare("SELECT id, title, details FROM training_items WHERE training_id = ? AND type = 'reading' ORDER BY section_id, position, id");
    $stmt->execute([$trainingId]);
    $want = array_map('intval', $itemIds);
    $out = '';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($want !== [] && !in_array((int) $r['id'], $want, true)) {
            continue;
        }
        $d = json_decode((string) $r['details'], true) ?: [];
        $body = trim(trim((string) ($d['text'] ?? '')) . "\n\n" . exams_ai_slides_text($d));
        if ($body !== '') {
            $out .= '## ' . $r['title'] . "\n" . $body . "\n\n";
        }
    }
    return mb_substr(trim($out), 0, EXAMS_AI_MAX_TEXT);
}

/**
 * The source as Gemini parts. $src: ['notes' => text] | ['pdf' => bytes] | ['items' => ids, 'training_id' => id].
 * @return array{ok:bool, parts?:list<array>, error?:string}
 */
function exams_ai_source_parts(PDO $pdo, array $src): array
{
    if (isset($src['pdf'])) {
        $bytes = (string) $src['pdf'];
        if ($bytes === '' || strlen($bytes) > EXAMS_AI_MAX_PDF) {
            return ['ok' => false, 'error' => 'Choose a PDF of up to 10 MB.'];
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            return ['ok' => false, 'error' => 'That file is not a PDF.'];
        }
        return ['ok' => true, 'parts' => [['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($bytes)]]]];
    }
    $text = isset($src['notes'])
        ? mb_substr(exams_text($src['notes'], EXAMS_AI_MAX_TEXT + 1), 0, EXAMS_AI_MAX_TEXT)
        : exams_ai_source_text($pdo, (int) ($src['training_id'] ?? 0), (array) ($src['items'] ?? []));
    if (mb_strlen($text) < 40) {
        return ['ok' => false, 'error' => isset($src['notes']) ? 'Paste more of the lesson (a few sentences at least).' : 'Those readings have no text or slides yet.'];
    }
    return ['ok' => true, 'parts' => [['text' => "SOURCE MATERIAL:\n" . $text]]];
}

/** The rules every prompt carries. */
function exams_ai_rules(): string
{
    return 'You help the HOD Academy of a church in Lagos, Nigeria (Household of David Lekki) prepare trainings. '
        . 'Use only the source material you are given and plain, warm British English. '
        . 'When you refer to the Bible, give the reference (for example John 3:16) and do not quote the verse: the church reads the KJV and quotes it from its own copy. '
        . 'Never invent names of real people, and never include personal details.';
}

// ---------------------------------------------------------------------------
// 1. Questions from source material -> drafts in the bank
// ---------------------------------------------------------------------------

/**
 * Ask for $opts['count'] questions and save the usable ones as drafts.
 * $opts: count (1–30), types (subset of single|multiple|true_false), topic, marks (1–100).
 * @return array{ok:bool, added?:int, repeats?:int, unusable?:int, ids?:list<int>, error?:string}
 */
function exams_ai_questions(PDO $pdo, int $userId, ?int $bankId, array $opts, array $src): array
{
    $count = max(1, min(EXAMS_AI_MAX_QUESTIONS, (int) ($opts['count'] ?? 10)));
    $types = array_values(array_intersect(array_keys(EXAM_QTYPES), (array) ($opts['types'] ?? [])));
    if ($types === []) {
        return ['ok' => false, 'error' => 'Choose at least one kind of question.'];
    }
    $marks = max(1, min(100, (int) ($opts['marks'] ?? 1)));
    $topic = exams_line($opts['topic'] ?? '', 120);
    $parts = exams_ai_source_parts($pdo, $src);
    if (!$parts['ok']) {
        return $parts;
    }
    $kinds = ['single' => '"one" (exactly one right option)', 'multiple' => '"several" (two or more right options, not all)',
              'true_false' => '"true_false" (a statement that is true or false)'];
    $system = exams_ai_rules() . ' Write exam questions that test understanding of the source material, not trivia. '
        . 'Answer with JSON only: {"questions":[{"type":"one|several|true_false","question":"…","options":["…","…"],'
        . '"correct":"B" (or "A, C", or "True"/"False"),"topic":"…","explanation":"…"}]}. '
        . 'Options: 3 to 5 short, plausible choices for one/several; none for true_false. '
        . 'Explanation: one or two sentences saying why the right answer is right, for someone who got it wrong.';
    $ask = 'Write ' . $count . ' questions. Kinds allowed: ' . implode('; ', array_map(static fn ($t) => $kinds[$t], $types)) . '.'
        . ($topic !== '' ? ' They are all about: ' . $topic . '.' : '');
    $r = exams_ai_call($pdo, $userId, 'questions', $bankId, $system, array_merge($parts['parts'], [['text' => $ask]]));
    if (!$r['ok']) {
        return $r;
    }
    $added = $repeats = $unusable = 0;
    $ids = [];
    foreach (array_slice((array) ($r['data']['questions'] ?? []), 0, $count) as $q) {
        if (!is_array($q)) {
            $unusable++;
            continue;
        }
        $type = exam_qtype_from_text((string) ($q['type'] ?? ''));
        if ($type === null || !in_array($type, $types, true)) {
            $unusable++;
            continue;
        }
        $correct = $q['correct'] ?? '';
        $saved = exam_question_save($pdo, null, $bankId, [
            'qtype' => $type, 'body' => (string) ($q['question'] ?? ''),
            'options' => $type === 'true_false' ? [] : array_map('strval', (array) ($q['options'] ?? [])),
            'correct_text' => is_array($correct) ? implode(', ', array_map('strval', $correct)) : (string) $correct,
            'marks' => (string) $marks, 'explanation' => (string) ($q['explanation'] ?? ''),
            'topic' => $topic !== '' ? $topic : (string) ($q['topic'] ?? ''), 'status' => 'draft',
        ], $userId, 'ai');
        if ($saved['ok']) {
            $added++;
            $ids[] = (int) $saved['id'];
        } elseif (in_array('This question is already in the bank.', $saved['errors'] ?? [], true)) {
            $repeats++;
        } else {
            $unusable++;
        }
    }
    return ['ok' => true, 'added' => $added, 'repeats' => $repeats, 'unusable' => $unusable, 'ids' => $ids];
}

// ---------------------------------------------------------------------------
// 3. Explanations, suggested in the approve flow (stored only on confirm)
// ---------------------------------------------------------------------------

/**
 * Suggested explanations for these questions (decoded bank rows), batched.
 * @return array{ok:bool, explanations:array<int,string>, error?:string}
 */
function exams_ai_explanations(PDO $pdo, int $userId, array $questions): array
{
    $out = [];
    $error = null;
    foreach (array_chunk(array_values($questions), EXAMS_AI_EXPLAIN_BATCH) as $batch) {
        $lines = [];
        foreach ($batch as $q) {
            $opts = $q['qtype'] === 'true_false' ? '' : implode(' ', array_map(static fn ($o, $i) => EXAM_LETTERS[$i] . ') ' . $o, $q['options'], array_keys($q['options'])));
            $lines[] = '[' . $q['id'] . '] ' . $q['body'] . ($opts !== '' ? ' Options: ' . $opts : '') . ' Right answer: ' . exam_answer_text($q, $q['correct']) . '.';
        }
        $system = exams_ai_rules() . ' For each exam question, write the explanation a learner sees after getting it wrong: '
            . 'one or two sentences on why the right answer is right (and, if useful, why a tempting wrong choice is wrong). '
            . 'Answer with JSON only: {"explanations":[{"id":123,"explanation":"…"}]}.';
        $r = exams_ai_call($pdo, $userId, 'explain', null, $system, 'QUESTIONS:' . "\n" . implode("\n", $lines));
        if (!$r['ok']) {
            $error = $r['error'];
            break;
        }
        $wanted = array_column($batch, 'id');
        foreach ((array) ($r['data']['explanations'] ?? []) as $e) {
            $id = (int) ($e['id'] ?? 0);
            $text = exams_text($e['explanation'] ?? '', 2000);
            if (in_array($id, $wanted, true) && $text !== '') {
                $out[$id] = $text;
            }
        }
    }
    return ['ok' => $error === null, 'explanations' => $out] + ($error !== null ? ['error' => $error] : []);
}

// ---------------------------------------------------------------------------
// 2. Training content proposals (nothing is created until Apply)
// ---------------------------------------------------------------------------

const EXAMS_AI_ITEM_TYPES = ['live', 'video', 'audio', 'reading', 'assignment', 'exam'];

/** An outline proposal, cleaned to what the builder accepts. */
function exams_ai_outline_clean($data, int $maxSections = 12): array
{
    $sections = [];
    foreach (array_slice((array) ($data['sections'] ?? []), 0, $maxSections) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $items = [];
        foreach (array_slice((array) ($s['items'] ?? []), 0, 10) as $i) {
            $type = (string) ($i['type'] ?? '');
            $title = exams_line($i['title'] ?? '', 160);
            if (!in_array($type, EXAMS_AI_ITEM_TYPES, true) || $title === '') {
                continue;
            }
            $minutes = (int) ($i['minutes'] ?? 0);
            $items[] = ['type' => $type, 'title' => $title, 'minutes' => $minutes >= 1 && $minutes <= 720 ? $minutes : null,
                        'note' => exams_text($i['note'] ?? '', 1000)];
        }
        $title = exams_line($s['title'] ?? '', 120);
        if ($title !== '' || $items !== []) {
            $sections[] = ['title' => $title !== '' ? $title : 'Part ' . (count($sections) + 1), 'items' => $items];
        }
    }
    return ['sections' => $sections];
}

/** Propose cards and items for a training. $in: notes, sections (how many cards), plus an optional source. */
function exams_ai_outline(PDO $pdo, int $userId, array $training, array $in, ?array $src = null): array
{
    $cards = max(1, min(12, (int) ($in['sections'] ?? 4)));
    $notes = exams_text($in['notes'] ?? '', 4000);
    $parts = [];
    if ($src !== null) {
        $p = exams_ai_source_parts($pdo, $src);
        if (!$p['ok']) {
            return $p;
        }
        $parts = $p['parts'];
    } elseif (mb_strlen($notes) < 20) {
        return ['ok' => false, 'error' => 'Say in a sentence or two what the training should cover.'];
    }
    $kind = ['one_day' => 'one day (one card: the day\'s programme)', 'days' => 'a few days (one card per day)',
             'weeks' => 'several weeks (one card per week)', 'self_paced' => 'self-paced (cards are modules)'][$training['length'] ?? 'self_paced'] ?? 'self-paced';
    $system = exams_ai_rules() . ' Propose the outline of a church training: cards (days, weeks or modules) and the items in each. '
        . 'Item types: live (a class), video, audio, reading, assignment (written work), exam (a short test). '
        . 'Answer with JSON only: {"sections":[{"title":"…","items":[{"type":"reading","title":"…","minutes":30,"note":"what it covers, one or two sentences"}]}]}.';
    $ask = 'Training: ' . $training['title'] . '. Length: ' . $kind . '. Cards: ' . $cards . '.'
        . ($notes !== '' ? ' What it should cover: ' . $notes : '');
    $r = exams_ai_call($pdo, $userId, 'outline', (int) $training['id'], $system, array_merge($parts, [['text' => $ask]]));
    if (!$r['ok']) {
        return $r;
    }
    $clean = exams_ai_outline_clean($r['data'], $cards);
    return $clean['sections'] === [] ? ['ok' => false, 'error' => EXAMS_AI_FAILURES['shape']] : ['ok' => true, 'proposal' => $clean];
}

/**
 * Apply an outline (as the tutor left it) through PR 1's builder: empty cards
 * are reused in order (and renamed), more are added; items are added at the
 * end of their card. @return array{ok:bool, sections:int, items:int, errors:list<string>}
 */
function exams_ai_outline_apply(PDO $pdo, int $trainingId, array $proposal): array
{
    $clean = exams_ai_outline_clean($proposal, 60);
    $errors = [];
    $madeSections = $madeItems = 0;
    $t = trainings_get($pdo, $trainingId);
    $empty = [];
    foreach (trainings_outline($pdo, $trainingId) as $s) {
        if ((int) $s['id'] > 0 && ($s['items'] ?? []) === []) {
            $empty[] = $s;
        }
    }
    foreach ($clean['sections'] as $s) {
        $card = array_shift($empty);
        if ($card !== null) {
            trainings_section_update($pdo, $t, trainings_section_get($pdo, (int) $card['id']), ['title' => $s['title']]);
            $sectionId = (int) $card['id'];
        } else {
            $add = trainings_section_add($pdo, $t, $s['title']);
            if (empty($add['ok'])) {
                $errors[] = (string) ($add['message'] ?? 'A card could not be added.');
                break;
            }
            $sectionId = (int) $add['id'];
            $madeSections++;
            $t = trainings_get($pdo, $trainingId);
        }
        foreach ($s['items'] as $i) {
            $in = ['type' => $i['type'], 'title' => $i['title'], 'section_id' => $sectionId, 'minutes' => $i['minutes'] ?? '', 'required' => 1];
            if ($i['type'] === 'reading') {
                $in['text'] = $i['note'];
            } elseif ($i['type'] === 'assignment') {
                $in += ['assignment_kind' => 'written', 'instructions' => $i['note']];
            } elseif ($i['type'] === 'live') {
                $in['live_mode'] = 'in_person';
            }
            $r = trainings_item_save($pdo, $t, $in);
            if (!empty($r['ok'])) {
                $madeItems++;
            } else {
                $errors[] = $i['title'] . ': ' . ($r['message'] ?? 'not added');
            }
        }
    }
    return ['ok' => $errors === [], 'sections' => $madeSections, 'items' => $madeItems, 'errors' => $errors];
}

/** A lesson summary of one reading (its text and slides). */
function exams_ai_summary(PDO $pdo, int $userId, int $trainingId, int $itemId): array
{
    $p = exams_ai_source_parts($pdo, ['training_id' => $trainingId, 'items' => [$itemId]]);
    if (!$p['ok']) {
        return $p;
    }
    $system = exams_ai_rules() . ' Summarise the lesson for the people taking the training: the main points in short paragraphs or a short list, '
        . 'at most 250 words. Answer with JSON only: {"summary":"…"}.';
    $r = exams_ai_call($pdo, $userId, 'summary', $trainingId, $system, $p['parts']);
    if (!$r['ok']) {
        return $r;
    }
    $text = exams_text($r['data']['summary'] ?? '', 4000);
    return $text === '' ? ['ok' => false, 'error' => EXAMS_AI_FAILURES['shape']] : ['ok' => true, 'summary' => $text];
}

/** Add an accepted summary as an optional reading at the end of the same card. */
function exams_ai_summary_apply(PDO $pdo, int $trainingId, int $itemId, string $text): array
{
    $item = trainings_item_get($pdo, $itemId);
    $text = exams_text($text, 4000);
    if ($item === null || $item['training_id'] !== $trainingId || $text === '') {
        return ['ok' => false, 'message' => 'Nothing to add.'];
    }
    return trainings_item_save($pdo, trainings_get($pdo, $trainingId), ['type' => 'reading', 'title' => exams_line('Summary: ' . $item['title'], 160),
        'section_id' => (int) $item['section_id'], 'text' => $text, 'required' => 0]);
}

/** Sermon-review prompts for an assignment, from the tutor's notes on the sermon. */
function exams_ai_prompts(PDO $pdo, int $userId, int $trainingId, string $notes): array
{
    $notes = exams_text($notes, 8000);
    if (mb_strlen($notes) < 20) {
        return ['ok' => false, 'error' => 'Paste a few lines about the sermon first (title, text, main points).'];
    }
    $system = exams_ai_rules() . ' Write 4 to 8 questions that help a church worker review a sermon in writing: its main points, the scriptures it used, '
        . 'and how it applies to their life and service. Each question is one short line. Answer with JSON only: {"prompts":["…","…"]}.';
    $r = exams_ai_call($pdo, $userId, 'prompts', $trainingId, $system, 'ABOUT THE SERMON:' . "\n" . $notes);
    if (!$r['ok']) {
        return $r;
    }
    $prompts = exams_ai_prompts_clean((array) ($r['data']['prompts'] ?? []));
    return $prompts === [] ? ['ok' => false, 'error' => EXAMS_AI_FAILURES['shape']] : ['ok' => true, 'prompts' => $prompts];
}

/** As PR 1's builder keeps them: up to 12 lines of up to 300 characters. */
function exams_ai_prompts_clean(array $prompts): array
{
    $out = [];
    foreach ($prompts as $p) {
        $p = exams_line(is_scalar($p) ? (string) $p : '', 300);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return array_slice($out, 0, 12);
}

/** Put accepted prompts on an assignment item (its other details stay as they are). */
function exams_ai_prompts_apply(PDO $pdo, int $trainingId, int $itemId, array $prompts): array
{
    $item = trainings_item_get($pdo, $itemId);
    $prompts = exams_ai_prompts_clean($prompts);
    if ($item === null || $item['training_id'] !== $trainingId || $item['type'] !== 'assignment' || $prompts === []) {
        return ['ok' => false, 'message' => 'Choose an assignment and keep at least one question.'];
    }
    $details = (array) $item['details'];
    $details['prompts'] = $prompts;
    $pdo->prepare('UPDATE training_items SET details = ?, updated_at = ? WHERE id = ?')
        ->execute([trainings_json($details), trainings_sql_dt(), $itemId]);
    return ['ok' => true];
}
