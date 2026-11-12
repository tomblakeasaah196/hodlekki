<?php
// /includes/exams/ai_actions.php
//
// The actions of api/exams_ai_api.php (the AI help page, modules/exams/ai.php).
// Every one is for the Academy's staff: can('trainings', 'edit', 'church')
// (exams_staff_can()), on a training that exists. The approve flow (suggested
// explanations) lives with the bank in access.php ('approve_check', 'approve').
//
//   status                usage today, whether AI is ready, this training's readings and assignments
//   questions             write questions into a bank as drafts (multipart when a PDF comes with it)
//   outline / outline_apply
//   summary / summary_apply
//   prompts / prompts_apply

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/ai.php';

/** What the AI page lists for a training: readings with text or slides, and assignments. */
function exams_ai_training_items(PDO $pdo, int $trainingId): array
{
    $assign = [];
    if (exams_table_exists($pdo, 'training_items')) {
        $stmt = $pdo->prepare("SELECT id, title, details FROM training_items WHERE training_id = ? AND type = 'assignment' ORDER BY section_id, position, id");
        $stmt->execute([$trainingId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string) $r['details'], true) ?: [];
            $assign[] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'sermon' => ($d['kind'] ?? '') === 'sermon_review',
                         'prompts' => array_values((array) ($d['prompts'] ?? []))];
        }
    }
    return ['readings' => exams_ai_source_items($pdo, $trainingId), 'assignments' => $assign];
}

/**
 * One AI action. The caller has checked sign-in and CSRF.
 * @return array{status:string, ...}
 */
function exams_ai_action(PDO $pdo, string $do, array $in, int $me, array $files = []): array
{
    $fail = static fn (string $msg, ?string $code = null): array => ['status' => 'error', 'message' => $msg] + ($code ? ['code' => $code] : []);
    if (!exams_staff_can('edit')) {
        return $fail('You do not have access to this.', 'FORBIDDEN');
    }
    $training = exams_training($pdo, (int) ($in['training_id'] ?? 0));
    if ($training === null) {
        return $fail('That training no longer exists.', 'NOT_FOUND');
    }
    $tid = (int) $training['id'];
    $t = function_exists('trainings_get') ? trainings_get($pdo, $tid) : null;
    $source = static function () use ($in, $files, $tid): ?array {
        switch ((string) ($in['source'] ?? '')) {
            case 'notes':
                return ['notes' => (string) ($in['notes'] ?? '')];
            case 'pdf':
                $f = $files['pdf'] ?? null;
                if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
                    return ['pdf' => ''];
                }
                if ((int) ($f['size'] ?? 0) > EXAMS_AI_MAX_PDF) {
                    return ['pdf' => ''];   // refused as "up to 10 MB" without reading it
                }
                return ['pdf' => (string) file_get_contents((string) $f['tmp_name'])];   // read, sent, never stored
            case 'items':
                return ['training_id' => $tid, 'items' => array_map('intval', (array) ($in['items'] ?? []))];
        }
        return null;
    };

    switch ($do) {
        case 'status':
            $usage = exams_ai_usage($pdo, $me);
            return ['status' => 'success', 'available' => exams_ai_available($pdo), 'blocked' => exams_ai_blocked($pdo, $me),
                    'usage' => $usage, 'training' => ['id' => $tid, 'title' => $training['title'], 'length' => $t['length'] ?? null]]
                + exams_ai_training_items($pdo, $tid);

        case 'questions':
            $src = $source();
            if ($src === null) {
                return $fail('Choose where the questions come from.');
            }
            $bank = ($in['bank'] ?? '') === 'shared' ? null : $tid;
            $r = exams_ai_questions($pdo, $me, $bank, [
                'count' => (int) ($in['count'] ?? 10), 'types' => (array) ($in['types'] ?? []),
                'topic' => (string) ($in['topic'] ?? ''), 'marks' => (int) ($in['marks'] ?? 1),
            ], $src);
            if (!$r['ok']) {
                return $fail((string) $r['error']);
            }
            $parts = [$r['added'] . ($r['added'] === 1 ? ' draft added' : ' drafts added')];
            if ($r['repeats']) {
                $parts[] = $r['repeats'] . ' already in the bank';
            }
            if ($r['unusable']) {
                $parts[] = $r['unusable'] . ' left out (not usable)';
            }
            return ['status' => 'success', 'message' => implode(' · ', $parts) . '.', 'added' => $r['added'], 'ids' => $r['ids'],
                    'bank_url' => '/modules/exams/?training=' . ($bank === null ? 'shared' : $tid) . '&status=draft'];

        case 'outline':
            $src = isset($in['source']) && $in['source'] !== '' && $in['source'] !== 'none' ? $source() : null;
            $r = exams_ai_outline($pdo, $me, ['id' => $tid, 'title' => $training['title'], 'length' => $t['length'] ?? 'self_paced'],
                ['notes' => (string) ($in['notes'] ?? ''), 'sections' => (int) ($in['sections'] ?? 4)], $src);
            return $r['ok'] ? ['status' => 'success', 'proposal' => $r['proposal']] : $fail((string) $r['error']);

        case 'outline_apply':
            if ($t === null) {
                return $fail('The training builder is not available.');
            }
            $proposal = is_array($in['proposal'] ?? null) ? $in['proposal'] : (json_decode((string) ($in['proposal'] ?? ''), true) ?: []);
            $r = exams_ai_outline_apply($pdo, $tid, $proposal);
            $msg = $r['items'] . ($r['items'] === 1 ? ' item' : ' items') . ' added'
                . ($r['sections'] ? ', ' . $r['sections'] . ($r['sections'] === 1 ? ' new card' : ' new cards') : '') . '.';
            return ['status' => $r['ok'] ? 'success' : 'error', 'message' => $r['ok'] ? $msg : $msg . ' ' . implode(' ', array_slice($r['errors'], 0, 3)),
                    'errors' => $r['errors'], 'builder_url' => '/modules/academy/training?id=' . $tid];

        case 'summary':
            $r = exams_ai_summary($pdo, $me, $tid, (int) ($in['item_id'] ?? 0));
            return $r['ok'] ? ['status' => 'success', 'summary' => $r['summary']] : $fail((string) $r['error']);

        case 'summary_apply':
            if ($t === null) {
                return $fail('The training builder is not available.');
            }
            $r = exams_ai_summary_apply($pdo, $tid, (int) ($in['item_id'] ?? 0), (string) ($in['text'] ?? ''));
            return !empty($r['ok']) ? ['status' => 'success', 'message' => 'Added as a reading.', 'builder_url' => '/modules/academy/training?id=' . $tid]
                                    : $fail((string) ($r['message'] ?? 'Not added.'));

        case 'prompts':
            $r = exams_ai_prompts($pdo, $me, $tid, (string) ($in['notes'] ?? ''));
            return $r['ok'] ? ['status' => 'success', 'prompts' => $r['prompts']] : $fail((string) $r['error']);

        case 'prompts_apply':
            if ($t === null) {
                return $fail('The training builder is not available.');
            }
            $prompts = $in['prompts'] ?? [];
            $r = exams_ai_prompts_apply($pdo, $tid, (int) ($in['item_id'] ?? 0), is_string($prompts) ? explode("\n", $prompts) : (array) $prompts);
            return $r['ok'] ? ['status' => 'success', 'message' => 'Questions saved on the assignment.', 'builder_url' => '/modules/academy/training?id=' . $tid]
                            : $fail((string) $r['message']);
    }
    return $fail('Unknown action.');
}
