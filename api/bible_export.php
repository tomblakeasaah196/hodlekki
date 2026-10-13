<?php
// /api/bible_export.php
//
// "Download as PDF" for a member's own study: journal entries, verse notes
// and highlights, optionally narrowed to one tag, one book or one entry.
//
//   GET ?what=all|journal|notes|highlights [&tag=faith] [&book=45] [&id=12]
//
// Only the caller's rows are ever read (every helper filters on user_id).
// KJV words come from the public-domain data files. Licensed translations
// (NKJV/NIV/AMP) are printed only from the 29-day cache, never fetched for
// the export, capped at BIBLE_EXPORT_LICENSED_VERSES verses in total, and
// carry the publisher's copyright notice — the quotation allowance those
// publishers give.

require_once '../includes/db.php';
require_once '../includes/bible/bootstrap.php';

const BIBLE_EXPORT_LICENSED_VERSES = 400;

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}
$userId    = (int) $_SESSION['user_id'];
$firstName = (string) ($_SESSION['first_name'] ?? '');
session_write_close();

function bible_export_fail(string $message, int $http = 400): void
{
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Export</title><body style="font-family:sans-serif;padding:2rem;color:#334155">'
        . '<h2>We could not make your PDF</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="/modules/bible/index.php">Back to The Bible</a></p></body>';
    exit;
}

if (!bible_ready($pdo)) {
    bible_export_fail('Notes and highlights are still being set up. Please try again after the next update.', 503);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    bible_export_fail('The PDF library is not installed on this server.', 500);
}
require_once $autoload;

$what = (string) ($_GET['what'] ?? 'all');
if (!in_array($what, ['all', 'journal', 'notes', 'highlights'], true)) {
    $what = 'all';
}
$tag  = bible_tag_clean((string) ($_GET['tag'] ?? ''));
$book = (int) ($_GET['book'] ?? 0);
$book = bible_book($book) !== null ? $book : 0;
$id   = (int) ($_GET['id'] ?? 0);

$e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ---------------------------------------------------------------------------
// Gather
// ---------------------------------------------------------------------------
$journal = [];
$notes = [];
$highlights = [];

try {
    if ($id > 0) {
        $one = bible_note_get($pdo, $userId, $id);
        if ($one === null) {
            bible_export_fail('That note no longer exists.', 404);
        }
        if ($one['kind'] === 'journal') {
            $journal[] = $one;
        } else {
            $notes[] = $one;
        }
    } else {
        $filters = ['tag' => $tag, 'book' => $book, 'order' => 'canonical'];
        if ($what === 'all' || $what === 'journal') {
            $journal = bible_notes_list($pdo, $userId, $filters + ['kind' => 'journal', 'order' => ''], 500)['items'];
        }
        if ($what === 'all' || $what === 'notes') {
            $notes = bible_notes_list($pdo, $userId, $filters + ['kind' => 'verse'], 500)['items'];
        }
        // Highlights carry no tags, so a tag filter leaves them out.
        if (($what === 'all' || $what === 'highlights') && $tag === '') {
            $highlights = bible_highlights_list($pdo, $userId, ['book' => $book, 'order' => 'canonical'], 500)['items'];
        }
    }
} catch (Throwable $ex) {
    error_log('Bible export: ' . $ex->getMessage());
    bible_export_fail('Something went wrong reading your notes.', 500);
}

if ($journal === [] && $notes === [] && $highlights === []) {
    bible_export_fail('There is nothing to export yet with those filters. Highlight a verse or write a note first.', 404);
}

// Words for licensed translations: cache only, within the quotation cap.
$licensedBudget = BIBLE_EXPORT_LICENSED_VERSES;
$usedTranslations = [];
$textCache = [];
$quoteFor = static function (string $t, int $b, int $c, int $vs, ?int $os, int $ve, ?int $oe, ?string $stored) use ($pdo, &$licensedBudget, &$usedTranslations, &$textCache): ?string {
    $usedTranslations[$t] = true;
    if ($stored !== null && $stored !== '') {
        return $stored;
    }
    if (bible_is_local($t)) {
        $texts = $textCache["$t:$b:$c"] ??= bible_chapter_texts($pdo, $t, $b, $c);
    } else {
        $verses = $ve - $vs + 1;
        if ($licensedBudget < $verses) {
            return null;
        }
        $texts = $textCache["$t:$b:$c"] ??= bible_chapter_texts($pdo, $t, $b, $c);
        if ($texts === []) {
            return null;
        }
        $licensedBudget -= $verses;
    }
    $q = bible_cut_quote($texts, $vs, $os ?? 0, $ve, $oe ?? mb_strlen($texts[$ve] ?? '', 'UTF-8'));

    return $q === '' ? null : $q;
};

$hlColors = [
    'yellow' => ['#FEF9C3', '#CA8A04'],
    'green'  => ['#DCFCE7', '#16A34A'],
    'blue'   => ['#DBEAFE', '#2563EB'],
    'pink'   => ['#FCE7F3', '#DB2777'],
    'purple' => ['#EDE9FE', '#7C3AED'],
];

$body = static fn(string $text): string => nl2br($e($text));

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$title = 'My Bible Study';
$subtitle = [];
if ($tag !== '') {
    $subtitle[] = '#' . $tag;
}
if ($book > 0) {
    $subtitle[] = bible_book($book)['name'];
}
if ($id > 0) {
    $one = $journal[0] ?? $notes[0];
    $title = $one['title'] !== '' ? $one['title'] : ($one['anchor']['ref'] ?? 'Study note');
}

ob_start();
?>
<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 54px 54px 64px 54px; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 10.5pt; line-height: 1.5; }
    .cover { border-bottom: 2px solid #1D356A; padding-bottom: 14px; margin-bottom: 22px; }
    .eyebrow { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #D11920; font-weight: bold; }
    h1 { font-family: 'DejaVu Serif', serif; font-size: 22pt; color: #1D356A; margin: 4px 0 2px; }
    .meta { color: #64748b; font-size: 9pt; }
    h2 { font-family: 'DejaVu Serif', serif; font-size: 14pt; color: #1D356A; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin: 26px 0 12px; }
    h3 { font-size: 9pt; letter-spacing: 1.5px; text-transform: uppercase; color: #94a3b8; margin: 16px 0 6px; page-break-after: avoid; }
    h2 { page-break-after: avoid; }
    .entry { margin: 0 0 16px; page-break-inside: avoid; }
    .entry-title { font-family: 'DejaVu Serif', serif; font-size: 12.5pt; font-weight: bold; color: #0f172a; }
    .ref { font-weight: bold; color: #1D356A; }
    .tr { font-size: 7.5pt; color: #64748b; border: 1px solid #cbd5e1; border-radius: 3px; padding: 0 3px; margin-left: 4px; }
    .quote { font-family: 'DejaVu Serif', serif; font-style: italic; color: #334155; border-left: 3px solid #cbd5e1; padding: 2px 0 2px 10px; margin: 5px 0; }
    .note { margin-top: 4px; }
    .tags { margin-top: 4px; }
    .tag { font-size: 8pt; color: #475569; background: #f1f5f9; border-radius: 8px; padding: 1px 6px; margin-right: 3px; }
    .links { font-size: 9pt; color: #475569; margin-top: 3px; }
    .date { font-size: 8pt; color: #94a3b8; }
    .hl { margin: 0 0 9px; padding: 5px 8px; border-left: 4px solid; page-break-inside: avoid; }
    .legal { margin-top: 30px; font-size: 7.5pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }
</style></head><body>

<div class="cover">
    <div class="eyebrow">Household of David · Lekki</div>
    <h1><?= $e($title) ?></h1>
    <div class="meta">
        <?= $firstName !== '' ? $e($firstName) . ' · ' : '' ?><?= $e(date('j F Y')) ?>
        <?= $subtitle !== [] ? ' · ' . $e(implode(' · ', $subtitle)) : '' ?>
    </div>
</div>

<?php if ($journal !== []): ?>
    <h2>Study journal</h2>
    <?php foreach ($journal as $n): ?>
        <div class="entry">
            <div class="entry-title"><?= $e($n['title'] !== '' ? $n['title'] : 'Untitled entry') ?></div>
            <div class="date"><?= $e(date('j M Y', strtotime($n['updated_at']))) ?></div>
            <?php if ($n['refs'] !== []): ?>
                <div class="links">Passages: <?= $e(implode('; ', array_column($n['refs'], 'ref'))) ?></div>
            <?php endif; ?>
            <?php if ($n['body'] !== ''): ?><div class="note"><?= $body($n['body']) ?></div><?php endif; ?>
            <?php if ($n['tags'] !== []): ?>
                <div class="tags"><?php foreach ($n['tags'] as $t): ?><span class="tag">#<?= $e($t) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($notes !== []): ?>
    <h2>Notes on Scripture</h2>
    <?php $lastBook = 0; foreach ($notes as $n): $a = $n['anchor']; if ($a === null) { continue; } ?>
        <?php if ($a['book'] !== $lastBook): $lastBook = $a['book']; ?><h3><?= $e(bible_book($a['book'])['name']) ?></h3><?php endif; ?>
        <div class="entry">
            <span class="ref"><?= $e($a['ref']) ?></span><span class="tr"><?= $e($a['translation']) ?></span>
            <?php $qt = $quoteFor($a['translation'], $a['book'], $a['chapter'], $a['vs'], $a['os'], $a['ve'], $a['oe'], $n['quote']); ?>
            <?php if ($qt !== null): ?><div class="quote"><?= $e($qt) ?></div><?php endif; ?>
            <div class="note"><?= $body($n['body']) ?></div>
            <?php if ($n['tags'] !== []): ?>
                <div class="tags"><?php foreach ($n['tags'] as $t): ?><span class="tag">#<?= $e($t) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($highlights !== []): ?>
    <h2>Highlights</h2>
    <?php $lastBook = 0; foreach ($highlights as $h): [$bg, $fg] = $hlColors[$h['color']] ?? $hlColors['yellow']; ?>
        <?php if ($h['book'] !== $lastBook): $lastBook = $h['book']; ?><h3><?= $e(bible_book($h['book'])['name']) ?></h3><?php endif; ?>
        <?php $qt = $quoteFor($h['translation'], $h['book'], $h['chapter'], $h['vs'], $h['os'], $h['ve'], $h['oe'], $h['quote']); ?>
        <div class="hl" style="border-color: <?= $fg ?>; background: <?= $bg ?>;">
            <span class="ref"><?= $e($h['ref']) ?></span><span class="tr"><?= $e($h['translation']) ?></span>
            <?php if ($qt !== null): ?><div class="quote" style="border:none;padding-left:0;margin-bottom:0;"><?= $e($qt) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="legal">
    <?php foreach (array_keys($usedTranslations) as $t): ?>
        <div><?= $e($t) ?>: <?= $e(BIBLE_TRANSLATIONS[$t]['copyright'] ?? '') ?></div>
    <?php endforeach; ?>
    <div>Private study notes exported from The Bible, HOD Lekki Centre.</div>
</div>
</body></html>
<?php
$html = (string) ob_get_clean();

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', realpath(__DIR__ . '/..'));

$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->add_info('Title', $title);
$dompdf->add_info('Author', $firstName !== '' ? $firstName : 'HOD Lekki member');
$dompdf->add_info('Creator', 'HOD Lekki — The Bible');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->get_font('DejaVu Sans', 'normal');
$canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 40, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.58, 0.64, 0.72]);

$filename = 'bible-study-' . date('Y-m-d') . ($tag !== '' ? '-' . preg_replace('/[^a-z0-9]+/', '-', $tag) : '') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store');
echo $dompdf->output();
