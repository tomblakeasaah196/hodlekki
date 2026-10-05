<?php
// /includes/charis_awol_pdf.php
// HODLC Charis — AWOL Welfare Report PDF Generator
// Engine: dompdf v3.x  |  Style: Requisition SSOT (Strict Margins & Canvas Footer)

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/charis_helpers.php';

// ─── Auth Guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

// ─── Charis Access Guard ──────────────────────────────────
$current_user_id = (int) $_SESSION['user_id'];
$charis_access = charis_access_context($pdo, $current_user_id);
if (!$charis_access['is_manager']) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif;color:#c00">Access Denied.</h2>');
}

// ─── PDF Zero-Trust Notes Fetcher ─────────────────────────
function getSecurePdfNotes(
    PDO $pdo,
    int $targetUserId,
    int $currentUserId,
    array $access,
    string $createdOnOrBefore
): string {
    $allNotes = charis_secure_welfare_notes(
        $pdo,
        $targetUserId,
        $currentUserId,
        $access,
        $createdOnOrBefore
    );
    $formattedNotes = '';
    foreach ($allNotes as $note) {
        $date = date('d M Y', strtotime($note['created_at']));
        $author = htmlspecialchars($note['first_name'] . ' ' . $note['last_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = nl2br(htmlspecialchars(strip_tags($note['note_text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $formattedNotes .= "<div style='margin-bottom:8px;'><strong>[{$date}] {$author}:</strong> {$text}</div>";
    }
    return $formattedNotes !== ''
        ? $formattedNotes
        : "<em style='color:#94a3b8;'>No notes are available for your clearance level.</em>";
}

// ─── dompdf Bootstrap ─────────────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    die('<h2 style="font-family:sans-serif">PDF library not found. Run: composer require dompdf/dompdf</h2>');
}
require_once $autoload;
use Dompdf\Dompdf;
use Dompdf\Options;

// ─── Parameters ───────────────────────────────────────────
$mode = (string) ($_GET['mode'] ?? 'month');
if (!in_array($mode, ['month', 'range'], true)) {
    http_response_code(400);
    die('Invalid report mode.');
}

if ($mode === 'month') {
    $repMonth = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT);
    $repYear = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT);
    if (!$repMonth || $repMonth < 1 || $repMonth > 12
        || !$repYear || $repYear < 2000 || $repYear > ((int) date('Y') + 1)) {
        http_response_code(400);
        die('Invalid report month or year.');
    }
    $startDate = new DateTimeImmutable(sprintf('%04d-%02d-01', $repYear, $repMonth));
    $endDate = $startDate->modify('last day of this month');
} else {
    $startDate = charis_parse_date(trim((string) ($_GET['date_start'] ?? '')));
    $endDate = charis_parse_date(trim((string) ($_GET['date_end'] ?? '')));
    if (!$startDate || !$endDate || $startDate > $endDate || $startDate->diff($endDate)->days > 366) {
        http_response_code(400);
        die('Choose a valid date range of no more than 366 days.');
    }
}

$date_start = $startDate->format('Y-m-d');
$date_end = $endDate->format('Y-m-d');
$period_label = $mode === 'month'
    ? $startDate->format('F Y')
    : $startDate->format('d M Y') . ' — ' . $endDate->format('d M Y');

// ─── Pull Records ─────────────────────────────────────────
$columnStmt = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'charis_welfare_assignments'
       AND column_name = 'resolved_at'
");
$columnStmt->execute();
$hasResolvedAt = (bool) $columnStmt->fetchColumn();
$resolvedSelect = $hasResolvedAt ? 'cwa.resolved_at' : 'NULL';
$resolutionStatusSelect = $hasResolvedAt
    ? "CASE WHEN cwa.status = 'Resolved' AND cwa.resolved_at > :status_as_of THEN 'Assigned' ELSE cwa.status END"
    : 'cwa.status';
$periodWhere = $hasResolvedAt
    ? '(cwa.created_at BETWEEN :created_start AND :created_end'
        . ' OR cwa.resolved_at BETWEEN :resolved_start AND :resolved_end'
        . ' OR (cwa.created_at <= :pending_end'
        . ' AND (cwa.resolved_at IS NULL OR cwa.resolved_at > :pending_resolved_after)))'
    : "(cwa.created_at BETWEEN :created_start AND :created_end"
        . " OR (cwa.status != 'Resolved' AND cwa.created_at <= :pending_end))";

$stmt = $pdo->prepare("
    SELECT
        u.id, u.first_name, u.last_name, u.phone, u.physical_address, u.email,
        u.spiritual_status, u.attendance_status,
        {$resolutionStatusSelect} as resolution_status, cwa.created_at as assigned_at,
        {$resolvedSelect} as resolved_at,
        w.first_name as worker_first, w.last_name as worker_last, w.phone as worker_phone,
        r.name as region_name
    FROM charis_welfare_assignments cwa
    JOIN users u ON cwa.target_user_id = u.id
    LEFT JOIN users w ON cwa.worker_id = w.id
    LEFT JOIN regions r ON u.region_id = r.id
    WHERE cwa.followup_id = 0
      AND {$periodWhere}
    ORDER BY cwa.status ASC, COALESCE({$resolvedSelect}, cwa.created_at) ASC
");
$params = [
    ':created_start' => $date_start . ' 00:00:00',
    ':created_end' => $date_end . ' 23:59:59',
    ':pending_end' => $date_end . ' 23:59:59',
];
if ($hasResolvedAt) {
    $params[':resolved_start'] = $date_start . ' 00:00:00';
    $params[':resolved_end'] = $date_end . ' 23:59:59';
    $params[':pending_resolved_after'] = $date_end . ' 23:59:59';
    $params[':status_as_of'] = $date_end . ' 23:59:59';
}
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($records);
$resolved = count(array_filter($records, fn($r) => $r['resolution_status'] === 'Resolved'));
$pending = $total - $resolved;

// ─── Helper functions ─────────────────────────────────────
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function fd(?string $ds): string {
    if (!$ds) return '—';
    return (new DateTime($ds))->format('d M Y');
}

// ─── Get logo as base64 ───────────────────────────────────
$logo_path = __DIR__ . '/../assets/images/logo_hod.png';
$logo_b64  = '';
if (file_exists($logo_path)) {
    $logo_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

// ─── Build HTML ───────────────────────────────────────────
$generated_by   = esc(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$generated_date = date('d M Y — H:i:s') . ' WAT';
$ref_code       = 'AWOL-' . strtoupper(date('MyY', strtotime($date_start)));

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
/* 1. Explicit standard margins from SSOT */
@page {
    margin-top: 60pt;
    margin-bottom: 60pt;
    margin-left: 60pt;
    margin-right: 60pt;
}
* { margin: 5px; padding: 3px; box-sizing: border-box; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.5pt; color: #1e293b; line-height: 1.5; background: #fff; }

/* 2. Document Header - 3 Column Layout (SSOT) */
.header-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
.header-table td { vertical-align: middle; }
.church-title { font-size: 11pt; font-weight: 900; color: #1D356A; letter-spacing: 0.5px; margin-bottom: 2px; line-height: 1.1; }
.church-address { font-size: 6.5pt; color: #64748b; line-height: 1.3; }
.logo-img { max-height: 55px; width: auto; display: block; margin: 0 auto; }
.doc-ref { font-size: 11pt; font-weight: 900; color: #0f172a; }
.header-line { border-bottom: 2px solid #1D356A; margin-bottom: 10px; }
.doc-title { font-size: 10pt; font-weight: bold; color: #1D356A; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }

/* Badges */
.badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
.badge-report { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }

/* AWOL Internal Content Styles */
.summary-box  { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px 14px; margin-bottom: 16px; }
.summary-row  { width: 100%; border-collapse: collapse; }
.summary-row td { width: 33%; padding: 6px 8px; text-align: center; vertical-align: middle; }
.sum-num      { font-size: 18pt; font-weight: 900; color: #0A0E17; display: block; }
.sum-lbl      { font-size: 6.5pt; color: #64748b; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px; }
.sum-sep      { border-right: 1px solid #e2e8f0; }
.sum-resolved { color: #059669 !important; }
.sum-pending  { color: #D97706 !important; }

.section-hd   { font-size: 9pt; font-weight: 900; color: #0A0E17; text-transform: uppercase; letter-spacing: 0.8px; border-bottom: 2px solid #D11920; padding-bottom: 3px; margin-bottom: 12px; margin-top: 18px; }

.case-block   { margin-bottom: 16px; padding: 12px 14px; background: #fff; border: 1px solid #e2e8f0; border-left: 4px solid #e2e8f0; border-radius: 3px; page-break-inside: avoid; }
.case-block.resolved { border-left-color: #10b981; }
.case-block.pending  { border-left-color: #f59e0b; }
.case-block.assigned { border-left-color: #3b82f6; }

.case-name    { font-size: 10pt; font-weight: 900; color: #0f172a; }
.case-badge   { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 6pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 6px; vertical-align: middle; }
.badge-res    { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.badge-pen    { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
.badge-asg    { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

.case-row     { width: 100%; border-collapse: collapse; margin-top: 6px; }
.case-row td  { width: 50%; vertical-align: top; padding: 2px 0; }
.case-lbl     { font-size: 6.5pt; color: #94a3b8; text-transform: uppercase; font-weight: bold; letter-spacing: 0.3px; }
.case-val     { font-size: 8pt; color: #1e293b; font-weight: bold; }

/* Worker notes — the most important part of each case block */
.notes-outer  { margin-top: 10px; }
.notes-hd     { font-size: 7pt; font-weight: 900; text-transform: uppercase; color: #0A0E17; letter-spacing: 0.5px; margin-bottom: 5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
.note-entry   { margin-bottom: 7px; padding: 7px 10px; border-left: 3px solid #D11920; background: #fff; border-radius: 0 3px 3px 0; page-break-inside: avoid; }
.note-entry.resolved-note { border-left-color: #10b981; }
.note-meta    { font-size: 6pt; color: #94a3b8; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
.note-text    { font-size: 8.5pt; color: #1e293b; line-height: 1.55; font-style: italic; }
.no-notes     { font-size: 7.5pt; color: #94a3b8; font-style: italic; padding: 6px 0; }

/* Keep legacy classes for contact detail rows */
.notes-box    { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 3px; padding: 7px 10px; margin-top: 8px; font-size: 7.5pt; color: #334155; line-height: 1.5; }
.notes-lbl    { font-size: 6pt; font-weight: 900; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 3px; }

.no-records   { text-align: center; color: #94a3b8; padding: 30px; font-size: 9pt; }
</style>
</head>
<body>

<table class="header-table">
    <tr>
        <td style="width:35%; text-align:left;">
            <div class="church-title">HOUSE OF DAVID<br>LEKKI CENTRE</div>
            <div class="church-address">
                Kon-X Place, Km 14, Lekki Express Way,<br>Agungi-Ajiran, Lagos<br>
                Charis (Welfare) Department
            </div>
        </td>

        <td style="width:30%; text-align:center;">
            <?php if ($logo_b64): ?>
            <img src="<?= $logo_b64 ?>" class="logo-img" alt="HOD Logo">
            <?php else: ?>
            <div style="font-size:16pt;font-weight:900;color:#1D356A;text-align:center;">HODLC</div>
            <?php endif; ?>
        </td>

        <td style="width:35%; text-align:right;">
            <div class="doc-ref"><?= esc($ref_code) ?></div>
            <div style="margin:4px 0;"><span class="badge badge-report">AWOL REPORT</span></div>
            <div style="font-size:6.5pt; color:#64748b; text-transform:uppercase;">Generated: <strong style="color:#0f172a;"><?= date('d M Y') ?></strong></div>
        </td>
    </tr>
</table>
<div class="header-line"></div>
<div class="doc-title">AWOL WELFARE INTERVENTION &mdash; <?= esc(strtoupper($period_label)) ?></div>

<div class="summary-box">
    <table class="summary-row">
        <tr>
            <td class="sum-sep">
                <span class="sum-num"><?= $total ?></span>
                <span class="sum-lbl">Total AWOL Cases</span>
            </td>
            <td class="sum-sep">
                <span class="sum-num sum-resolved"><?= $resolved ?></span>
                <span class="sum-lbl">Resolved / Handled</span>
            </td>
            <td>
                <span class="sum-num sum-pending"><?= $pending ?></span>
                <span class="sum-lbl">Still Pending</span>
            </td>
        </tr>
    </table>
</div>

<p style="font-size:8pt; color:#475569; line-height:1.6; margin-bottom:6px;">
    This report documents AWOL welfare cases opened or resolved during
    <strong><?= esc($period_label) ?></strong>, together with the unresolved backlog as at the end of that period.
    Each entry represents a member who was absent for three or more consecutive Sunday services and whose case
    was claimed or assigned for follow-up by the Charis team.
    The pastor is advised to review all pending cases and determine next-level pastoral intervention where necessary.
</p>

<?php if ($total === 0): ?>
<div class="no-records">No AWOL cases were recorded for this period.</div>
<?php else: ?>

<?php
// ── Separate resolved from others ──
$resolved_records = array_filter($records, fn($r) => $r['resolution_status'] === 'Resolved');
$pending_records  = array_filter($records, fn($r) => $r['resolution_status'] !== 'Resolved');
?>

<?php if (!empty($resolved_records)): ?>
<div class="section-hd">✓ Resolved Cases (<?= count($resolved_records) ?>)</div>
<?php foreach ($resolved_records as $i => $r): ?>
<div class="case-block resolved">
    <div>
        <span class="case-name"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></span>
        <span class="case-badge badge-res">✓ Resolved</span>
    </div>
    <table class="case-row">
        <tr>
            <td>
                <div class="case-lbl">Phone / Contact</div>
                <div class="case-val"><?= esc($r['phone'] ?? '—') ?></div>
            </td>
            <td>
                <div class="case-lbl">Email</div>
                <div class="case-val"><?= esc($r['email'] ?? '—') ?></div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="case-lbl">Address / Location</div>
                <div class="case-val"><?= esc($r['physical_address'] ?? '—') ?></div>
            </td>
            <td>
                <div class="case-lbl">Region</div>
                <div class="case-val"><?= esc($r['region_name'] ?? '—') ?></div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="case-lbl">Handled By (Charis Worker)</div>
                <div class="case-val"><?= esc($r['worker_first'] . ' ' . $r['worker_last']) ?> <?php if ($r['worker_phone']): ?><span style="color:#64748b; font-weight:normal;">(<?= esc($r['worker_phone']) ?>)</span><?php endif; ?></div>
            </td>
            <td>
                <div class="case-lbl">Resolved On</div>
                <div class="case-val"><?= fd($r['resolved_at']) ?></div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="case-lbl">Final Attendance Status</div>
                <div class="case-val"><?= esc(str_replace('_', ' ', $r['attendance_status'] ?? '—')) ?></div>
            </td>
            <td>
                <div class="case-lbl">Spiritual Status</div>
                <div class="case-val"><?= esc($r['spiritual_status'] ?? '—') ?></div>
            </td>
        </tr>
    </table>

    <?php
    $safe_notes = getSecurePdfNotes($pdo, (int) $r['id'], $current_user_id, $charis_access, $date_end . ' 23:59:59');
    ?>
    <div class="notes-outer">
        <div class="notes-hd">Secure Worker Notes &amp; Findings</div>
        <div class="notes-box">
            <?= $safe_notes ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($pending_records)): ?>
<div class="section-hd">⚠ Pending / Unresolved Cases (<?= count($pending_records) ?>)</div>
<p style="font-size:7.5pt; color:#92400e; background:#fef3c7; padding:7px 10px; border-radius:3px; margin-bottom:12px; border:1px solid #fbbf24;">
    <strong>Pastoral Attention Required:</strong> The following members have been flagged as AWOL but their cases remain unresolved as at the date of this report. Please follow up directly or instruct the Charis team on next steps.
</p>
<?php foreach ($pending_records as $r): ?>
<?php $block_class = $r['resolution_status'] === 'Assigned' ? 'assigned' : 'pending'; ?>
<div class="case-block <?= $block_class ?>">
    <div>
        <span class="case-name"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></span>
        <?php if ($r['resolution_status'] === 'Assigned'): ?>
        <span class="case-badge badge-asg">Assigned — In Progress</span>
        <?php elseif ($r['resolution_status'] === 'Requested'): ?>
        <span class="case-badge badge-pen">Awaiting Assignment Approval</span>
        <?php else: ?>
        <span class="case-badge badge-pen">Unassigned</span>
        <?php endif; ?>
    </div>
    <table class="case-row">
        <tr>
            <td>
                <div class="case-lbl">Phone / Contact</div>
                <div class="case-val"><?= esc($r['phone'] ?? '—') ?></div>
            </td>
            <td>
                <div class="case-lbl">Email</div>
                <div class="case-val"><?= esc($r['email'] ?? '—') ?></div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="case-lbl">Address / Location</div>
                <div class="case-val"><?= esc($r['physical_address'] ?? '—') ?></div>
            </td>
            <td>
                <div class="case-lbl">Region</div>
                <div class="case-val"><?= esc($r['region_name'] ?? '—') ?></div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="case-lbl">Assigned Charis Worker</div>
                <div class="case-val"><?= $r['worker_first'] ? esc($r['worker_first'] . ' ' . $r['worker_last']) : '<em style="color:#94a3b8">Not yet assigned</em>' ?></div>
            </td>
            <td>
                <div class="case-lbl">Flagged On</div>
                <div class="case-val"><?= fd($r['assigned_at']) ?></div>
            </td>
        </tr>
    </table>

    <?php
    $safe_notes = getSecurePdfNotes($pdo, (int) $r['id'], $current_user_id, $charis_access, $date_end . ' 23:59:59');
    ?>
    <div class="notes-outer">
        <div class="notes-hd">Secure Worker Notes &amp; Findings</div>
        <div class="notes-box">
            <?= $safe_notes ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; // end total > 0 ?>

</body>
</html>
<?php
$html = ob_get_clean();

// ─── Render PDF ───────────────────────────────────────────
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isPhpEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', realpath(__DIR__ . '/..'));

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->add_info('Title', 'AWOL Welfare Report — ' . $period_label);
$dompdf->add_info('Author', 'HOD Lekki Centre — Charis Department');
$dompdf->add_info('Creator', 'HODLC Charis System');

// Phase 1: Compile HTML and CSS into PDF geometry
$dompdf->render();

// Phase 2: DETERMINISTIC CANVAS OVERRIDE (Inject Footer via physical coordinates)
$canvas = $dompdf->getCanvas();
$w = $canvas->get_width();
$h = $canvas->get_height();
$font = $dompdf->getFontMetrics()->get_font("DejaVu Sans", "normal");
$bold_font = $dompdf->getFontMetrics()->get_font("DejaVu Sans", "bold");

$margin_x = 40; // 40pt left/right physical margin
$footer_y = $h - 50; // Pin the footer 50pt from the very bottom of the page

// 1. Draw top border line for the footer block
$canvas->line($margin_x, $footer_y, $w - $margin_x, $footer_y, array(0.88, 0.91, 0.94), 1); // #e2e8f0

// 2. Inject Left Column: Document Reference
$ref_text = "Ref: " . $ref_code;
$canvas->page_text($margin_x, $footer_y + 8, $ref_text, $font, 6.5, array(0.39, 0.45, 0.54)); // #64748b

// 3. Inject Center Column: Generation Date & User
$gen_date = "Generated: " . (new DateTime())->format('d M Y, H:i') . " WAT by " . $generated_by;
$text_width = $dompdf->getFontMetrics()->getTextWidth($gen_date, $font, 6.5);
$canvas->page_text(($w / 2) - ($text_width / 2), $footer_y + 8, $gen_date, $font, 6.5, array(0.39, 0.45, 0.54));

// 4. Inject Right Column: Pagination (dompdf parses {PAGE_NUM} natively)
$page_text = "Page {PAGE_NUM}";
$canvas->page_text($w - $margin_x - 40, $footer_y + 8, $page_text, $font, 6.5, array(0.39, 0.45, 0.54));

// 5. Inject Confidentiality block at the very bottom (Replaces Requisition Verify Link)
$conf_text = "CONFIDENTIAL DOCUMENT — Intended solely for pastoral review. Do not distribute.";
$conf_width = $dompdf->getFontMetrics()->getTextWidth($conf_text, $bold_font, 7);
$canvas->page_text(($w / 2) - ($conf_width / 2), $footer_y + 22, $conf_text, $bold_font, 7, array(0.86, 0.15, 0.15)); // Red shade for privacy

$filename = 'HODLC_AWOL_Report_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $period_label) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
