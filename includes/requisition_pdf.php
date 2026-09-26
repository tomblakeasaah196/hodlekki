<?php
// /includes/requisition_pdf.php
// House of David Lekki Centre — Requisition PDF Generator
// Strategy: HTML Content Body + Deterministic Canvas Footer Override
// Engine:  dompdf v3.x

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

// ─── Auth Guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

// ─── CSRF Guard ───────────────────────────────────────────
$csrf_get = trim($_GET['csrf'] ?? '');
if (empty($csrf_get) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_get)) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif;color:#c00">Security token invalid.</h2><p>Return to the Requisition module and try exporting again.</p>');
}

// ─── dompdf Bootstrap ─────────────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    die('<h2 style="font-family:sans-serif">PDF library not found.</h2><p>Run: <code>composer require dompdf/dompdf</code></p>');
}
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

// ─── Parameters ───────────────────────────────────────────
$req_id    = (int)($_GET['req_id']    ?? 0);
$batch_id  = (int)($_GET['batch_id'] ?? 0);
$user_id   = (int)$_SESSION['user_id'];

if (!$req_id && !$batch_id) {
    die('No requisition or batch ID provided.');
}

// ─── Role check ───────────────────────────────────────────
$session_roles = array_column($_SESSION['roles'] ?? [], 'role_name');
$is_privileged = array_intersect($session_roles, ['Super_Admin','Resident_Pastor','Assoc_Pastor','Director','HOD']) !== [];
if (!$is_privileged) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif;color:#c00">Access Denied.</h2>');
}

// ─── Helper Functions ─────────────────────────────────────
function fc(float $v): string { return '&#8358;' . number_format($v, 2); }

function fd(?string $ds, bool $long = false): string {
    if (!$ds) return '&mdash;';
    $d = new DateTime($ds);
    return $long ? $d->format('d F Y') : $d->format('d M Y');
}

function fdt(?string $ds): string {
    if (!$ds) return '&mdash;';
    $d = new DateTime($ds);
    return $d->format('d M Y') . ' &mdash; ' . $d->format('H:i:s') . ' WAT';
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

// ─── CSS Strategy: Strict Margins & Header Styling ────────
function sharedCSS(): string { return '
    * { margin:3px; padding:3px; box-sizing:border-box; }
    
    /* Explicit standard margins. 
       Bottom margin is 60pt to leave blank space for the Canvas footer injection. 
    */
    @page { 
        margin-top: 40pt; 
        margin-bottom: 60pt; 
        margin-left: 40pt; 
        margin-right: 40pt; 
    }
    
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size:8pt; color:#1e293b; line-height:1.4; background:#fff; }
    
    /* Document Header - 3 Column Layout */
    .header-table { width:100%; border-collapse:collapse; margin-bottom:15px; }
    .header-table td { vertical-align: middle; }
    .church-title { font-size:11pt; font-weight:900; color:#1D356A; letter-spacing:0.5px; margin-bottom:2px; line-height:1.1; }
    .church-address { font-size:6.5pt; color:#64748b; line-height:1.3; }
    
    .logo-img { max-height: 55px; width: auto; display: block; margin: 0 auto; }
    
    .doc-ref { font-size:11pt; font-weight:900; color:#0f172a; }
    .header-line { border-bottom:2px solid #1D356A; margin-bottom:10px; }
    .doc-title { font-size:10pt; font-weight:bold; color:#1D356A; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px; }
    
    /* Badges */
    .badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:6.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; }
    .badge-approved  { background:#d1fae5; color:#065f46; border:1px solid #34d399; }
    .badge-rejected  { background:#fee2e2; color:#991b1b; border:1px solid #f87171; }
    .badge-pending   { background:#fef3c7; color:#92400e; border:1px solid #fbbf24; }
    .badge-disbursed { background:#ecfdf5; color:#047857; border:1px solid #10b981; }
    
    /* Meta Grid (4 Columns) */
    .meta-grid { width:100%; border-collapse:collapse; margin-bottom:14px; }
    .meta-grid td { padding:4px 0; width:25%; vertical-align:top; }
    .meta-lbl { font-size:6pt; color:#64748b; text-transform:uppercase; font-weight:bold; letter-spacing:0.5px; }
    .meta-val { font-size:8pt; color:#0f172a; font-weight:bold; margin-top:2px; }
    
    /* Line Items Table */
    .items-table { width:100%; border-collapse:collapse; margin-bottom:12px; border:1px solid #e2e8f0; }
    .items-table th { background:#f8fafc; color:#334155; font-size:6.5pt; font-weight:bold; padding:5px 6px; text-align:left; text-transform:uppercase; border-bottom:1px solid #cbd5e1; }
    .items-table td { padding:5px 6px; font-size:8pt; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
    .items-table tr:nth-child(even) td { background:#fcfcfd; }
    .items-table .num { text-align:right; font-weight:bold; }
    .items-table .total-row td { background:#1D356A; color:#fff; font-weight:bold; font-size:8.5pt; border-top:2px solid #0f172a; }
    .items-table .total-row .num { text-align:right; font-size:10pt; }
    
    /* Side-by-Side Signatures */
    .sig-layout { width:100%; border-collapse:collapse; margin-top:10px; page-break-inside:avoid; }
    .sig-layout td { vertical-align:top; width:50%; }
    
    /* Compact Stamp */
    .stamp-compact { border:1px solid #cbd5e1; border-top:3px solid #1D356A; border-radius:4px; padding:8px; background:#f8fafc; margin-bottom:6px; }
    .stamp-title { font-size:7pt; font-weight:bold; color:#1D356A; text-transform:uppercase; margin-bottom:4px; letter-spacing:0.5px; }
    .stamp-hash { font-family:monospace; font-size:6pt; background:#e2e8f0; padding:4px; border-radius:2px; word-break:break-all; margin-top:4px; color:#334155; line-height:1.3; }
    
    /* Compact Pastor Signature */
    .pastor-compact { border:1px solid #cbd5e1; border-top:3px solid #dc2626; border-radius:4px; padding:8px; background:#fff; text-align:center; min-height:92px; margin-bottom:6px; }
    .pastor-img { max-height:40px; max-width:180px; margin:2px auto; display:block; }
    .pastor-line { border-bottom:1px solid #cbd5e1; width:80%; margin:4px auto; }
    .pastor-name { font-size:7.5pt; font-weight:bold; color:#0f172a; }
    .pastor-role { font-size:6pt; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
    
    /* Miscellaneous */
    .note-box { background:#f8fafc; border:1px dashed #cbd5e1; padding:6px 8px; font-size:7.5pt; color:#475569; margin-bottom:12px; }
    .rej-note { font-size:6.5pt; color:#dc2626; font-style:italic; display:block; margin-top:1px; }
'; }

// ─── 3-Column Document Header ─────────────────────────────
function docHeader(string $title, string $ref, string $status, string $date_lbl, string $date_val): string {
    $status_cls = in_array($status, ['Approved','Disbursed']) ? 'badge-approved' : (in_array($status, ['Rejected','Cancelled']) ? 'badge-rejected' : 'badge-pending');
    $logo_path = __DIR__ . '/../assets/images/logo_hod.png';
    $logo_html = file_exists($logo_path) ? '<img src="' . $logo_path . '" class="logo-img">' : '<div style="font-size:16pt;font-weight:900;color:#1D356A;text-align:center;">HODLC</div>';

    return '
    <table class="header-table">
        <tr>
            <td style="width:35%; text-align:left;">
                <div class="church-title">HOUSE OF DAVID<br>LEKKI CENTRE</div>
                <div class="church-address">
                    Kon-X Place, Km 14, Lekki Express Way,<br>Agungi-Ajiran, Lagos<br>
                    householdofdavidlekki@gmail.com
                </div>
            </td>
            
            <td style="width:30%; text-align:center;">
                ' . $logo_html . '
            </td>
            
            <td style="width:35%; text-align:right;">
                <div class="doc-ref">' . esc($ref) . '</div>
                <div style="margin:4px 0;"><span class="badge ' . $status_cls . '">' . esc($status) . '</span></div>
                <div style="font-size:6.5pt; color:#64748b; text-transform:uppercase;">' . esc($date_lbl) . ': <strong style="color:#0f172a;">' . $date_val . '</strong></div>
            </td>
        </tr>
    </table>
    <div class="header-line"></div>
    <div class="doc-title">' . esc($title) . '</div>';
}

// ─── Compact Director Stamp ───────────────────────────────
function directorStampCompact(array $s, bool $is_auto): string {
    $auto = $is_auto ? '<div style="color:#2563eb; font-size:6pt; margin-top:4px;">&#9432; Auto-validated via Director submission</div>' : '';
    return '
    <div class="stamp-compact">
        <div class="stamp-title">Director Validation <span style="float:right; color:#16a34a;">VALID</span></div>
        <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
            <tr><td style="width:35px; color:#64748b; font-weight:bold; font-size:6.5pt; vertical-align:top;">Name:</td><td style="color:#0f172a; font-weight:bold; font-size:6.5pt;">' . esc($s['name']) . '</td></tr>
            <tr><td style="color:#64748b; font-weight:bold; font-size:6.5pt; vertical-align:top;">Date:</td><td style="color:#0f172a; font-weight:bold; font-size:6.5pt;">' . esc($s['date_time']) . '</td></tr>
            <tr><td style="color:#64748b; font-weight:bold; font-size:6.5pt; vertical-align:top;">IP:</td><td style="color:#0f172a; font-weight:bold; font-size:6.5pt;">' . esc($s['ip_address']) . '</td></tr>
        </table>
        <div class="stamp-hash">
            <span style="color:#64748b;">SHA:</span> ' . esc($s['hash']) . '<br>
            <span style="color:#64748b;">VRF:</span> <strong style="color:#000; font-size:7pt;">' . esc($s['verify_code']) . '</strong>
        </div>
        ' . $auto . '
    </div>';
}

// ─── Compact Finance Stamp ────────────────────────────────
function financeStampCompact(array $s): string {
    return '
    <div class="stamp-compact" style="border-top-color:#059669; background:#f0fdf4;">
        <div class="stamp-title" style="color:#059669;">Finance HQ Authorisation <span style="float:right;">VALID</span></div>
        <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
            <tr><td style="width:35px; color:#64748b; font-weight:bold; font-size:6.5pt; vertical-align:top;">Name:</td><td style="color:#0f172a; font-weight:bold; font-size:6.5pt;">' . esc($s['name']) . '</td></tr>
            <tr><td style="color:#64748b; font-weight:bold; font-size:6.5pt; vertical-align:top;">Date:</td><td style="color:#0f172a; font-weight:bold; font-size:6.5pt;">' . esc($s['date_time']) . '</td></tr>
            <tr><td style="color:#64748b; font-weight:bold; font-size:6.5pt; vertical-align:top;">Total:</td><td style="color:#059669; font-weight:bold; font-size:6.5pt;">' . esc($s['total_validated']) . '</td></tr>
        </table>
        <div class="stamp-hash" style="background:#d1fae5;">
            <span style="color:#065f46;">VRF:</span> <strong style="color:#000; font-size:7pt;">' . esc($s['verify_code']) . '</strong>
        </div>
    </div>';
}

// ─── Compact Pastor Signature ─────────────────────────────
function pastorSignatureCompact(string $sig_b64, string $pastor_name, string $acted_at, string $notes = ''): string {
    $img = $sig_b64 ? '<img src="' . $sig_b64 . '" class="pastor-img">' : '<div style="height:40px; line-height:40px; color:#dc2626; font-size:7pt;">Signature on file</div>';
    $n = $notes ? '<div style="font-size:6pt; color:#1e40af; font-style:italic; margin-top:2px;">' . esc($notes) . '</div>' : '';
    return '
    <div class="pastor-compact">
        <div class="stamp-title" style="color:#dc2626; text-align:left;">Pastoral Approval</div>
        ' . $img . '
        <div class="pastor-line"></div>
        <div class="pastor-name">' . esc($pastor_name) . '</div>
        <div class="pastor-role">Resident Pastor</div>
        <div style="font-size:6pt; color:#94a3b8; margin-top:1px;">Approved: ' . fdt($acted_at) . '</div>
        ' . $n . '
    </div>';
}

$document_reference_number = ''; // Extracted for the canvas footer below

// ══════════════════════════════════════════════════════════
// INDIVIDUAL REQUISITION PDF
// ══════════════════════════════════════════════════════════
if ($req_id) {
    $hStmt = $pdo->prepare("SELECT rh.*, d.name AS department_name, CONCAT(u.first_name,' ',u.last_name) AS submitted_by_name FROM requisition_headers rh JOIN departments d ON rh.department_id = d.id JOIN users u ON rh.submitted_by = u.id WHERE rh.id = ?");
    $hStmt->execute([$req_id]);
    $req = $hStmt->fetch();
    if (!$req) die('Requisition not found.');
    
    $document_reference_number = $req['ref_number'];

    $iStmt = $pdo->prepare("SELECT ri.*, fc.category_name FROM requisition_items ri LEFT JOIN finance_categories fc ON ri.finance_category_id = fc.id WHERE ri.requisition_id = ? ORDER BY ri.id ASC");
    $iStmt->execute([$req_id]);
    $items = $iStmt->fetchAll();

    $sStmt = $pdo->prepare("SELECT rds.* FROM requisition_director_stamps rds WHERE rds.requisition_id = ?");
    $sStmt->execute([$req_id]);
    $dir_stamp = $sStmt->fetch();
    $stamp_data = ($dir_stamp && $dir_stamp['stamp_json']) ? json_decode($dir_stamp['stamp_json'], true) : null;

    $pStmt = $pdo->prepare("SELECT rpa.*, CONCAT(u.first_name,' ',u.last_name) AS pastor_name FROM requisition_pastor_actions rpa JOIN users u ON rpa.action_by = u.id WHERE rpa.requisition_id = ? AND rpa.action = 'Approved' ORDER BY rpa.acted_at DESC LIMIT 1");
    $pStmt->execute([$req_id]);
    $pastor_action = $pStmt->fetch();

    $attStmt = $pdo->prepare("SELECT * FROM requisition_attachments WHERE requisition_id = ?");
    $attStmt->execute([$req_id]);
    $attachments = $attStmt->fetchAll();

    $period = $req['type'] === 'Monthly' ? date('F Y', mktime(0,0,0,$req['period_month'],1,$req['period_year'])) : htmlspecialchars($req['event_label'] ?? '');

    // Items
    $items_html = '';
    foreach ($items as $item) {
        $status_cls = $item['status'] === 'Rejected' ? 'rejected' : '';
        $rej_note = ($item['status'] === 'Rejected' && $item['rejection_reason']) ? '<span class="rej-note">&#9888; ' . esc($item['rejection_reason']) . '</span>' : '';
        $cat = $item['category_name'] ? '<br><span style="font-size:6pt;color:#94a3b8">' . esc($item['category_name']) . '</span>' : '';
        $items_html .= '
        <tr class="' . $status_cls . '">
            <td>' . esc($item['item_description']) . $cat . $rej_note . '</td>
            <td class="num">' . number_format((float)$item['quantity'], 2) . '</td>
            <td class="num">' . fc((float)$item['unit_cost']) . '</td>
            <td class="num">' . fc((float)$item['total_amount']) . '</td>
        </tr>';
    }

    $note_html = $req['submission_note'] ? '<div class="note-box"><strong>Submitter Note:</strong> ' . esc($req['submission_note']) . '</div>' : '';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>' . sharedCSS() . '</style></head><body>
        <main>
            ' . docHeader('EXPENSE REQUISITION — ' . strtoupper($req['type']), $req['ref_number'], str_replace('_', ' ', $req['status']), 'Submitted', fd($req['created_at'])) . '

            <table class="meta-grid">
                <tr>
                    <td><div class="meta-lbl">Department</div><div class="meta-val">' . esc($req['department_name']) . '</div></td>
                    <td><div class="meta-lbl">Period / Event</div><div class="meta-val">' . esc($period) . '</div></td>
                    <td><div class="meta-lbl">Submitted By</div><div class="meta-val">' . esc($req['submitted_by_name']) . '</div></td>
                    <td><div class="meta-lbl">Submission Date</div><div class="meta-val">' . fdt($req['created_at']) . '</div></td>
                </tr>
            </table>
            
            ' . $note_html . '

            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:45%">Description</th>
                        <th style="width:10%;text-align:right">Qty</th>
                        <th style="width:20%;text-align:right">Unit Cost</th>
                        <th style="width:25%;text-align:right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $items_html . '
                    <tr class="total-row">
                        <td colspan="3" style="text-align:right">GRAND TOTAL</td>
                        <td class="num">' . fc((float)$req['total_amount']) . '</td>
                    </tr>
                </tbody>
            </table>';

    if ($attachments) {
        $html .= '<div style="font-size:6.5pt; color:#64748b; margin-bottom:10px;"><strong>ATTACHMENTS ON FILE:</strong> ' . implode(', ', array_map(fn($a) => esc($a['file_name']), $attachments)) . '</div>';
    }

    $html .= '<table class="sig-layout"><tr>';
    $html .= '<td style="padding-right:5px;">' . ($stamp_data ? directorStampCompact($stamp_data, (bool)$dir_stamp['is_auto']) : '') . '</td>';
    $html .= '<td style="padding-left:5px;">' . ($pastor_action ? pastorSignatureCompact($pastor_action['signature_base64'] ?? '', $pastor_action['pastor_name'], $pastor_action['acted_at'], $pastor_action['notes'] ?? '') : '') . '</td>';
    $html .= '</tr></table>';

    $html .= '</main></body></html>';
    $filename = 'REQ_' . preg_replace('/[^A-Za-z0-9\-]/', '', $req['ref_number']) . '_' . date('Ymd') . '.pdf';
}

// ══════════════════════════════════════════════════════════
// BATCH PDF
// ══════════════════════════════════════════════════════════
elseif ($batch_id) {
    $bStmt = $pdo->prepare("SELECT rb.*, CONCAT(u.first_name,' ',u.last_name) AS created_by_name FROM requisition_batches rb JOIN users u ON rb.created_by = u.id WHERE rb.id = ?");
    $bStmt->execute([$batch_id]);
    $batch = $bStmt->fetch();
    if (!$batch) die('Batch not found.');
    
    $document_reference_number = $batch['batch_ref'];

    $riStmt = $pdo->prepare("SELECT rbi.amount_in_batch, rh.id AS req_id, rh.ref_number, rh.type, rh.period_month, rh.period_year, rh.event_label, d.name AS department_name FROM requisition_batch_items rbi JOIN requisition_headers rh ON rbi.requisition_id = rh.id JOIN departments d ON rh.department_id = d.id WHERE rbi.batch_id = ? ORDER BY rbi.id ASC");
    $riStmt->execute([$batch_id]);
    $batch_reqs = $riStmt->fetchAll();

    $fin_stamp = $batch['finance_stamp_json'] ? json_decode($batch['finance_stamp_json'], true) : null;
    $batch_status_label = str_replace('_', ' ', $batch['status']);

    $summary_rows = '';
    foreach ($batch_reqs as $r) {
        $period = $r['type'] === 'Monthly' ? date('M Y', mktime(0,0,0,$r['period_month'],1,$r['period_year'])) : ($r['event_label'] ?? '—');
        $summary_rows .= '
        <tr>
            <td style="font-weight:bold">' . esc($r['ref_number']) . '</td>
            <td>' . esc($r['department_name']) . '</td>
            <td>' . esc($period) . '</td>
            <td class="num">' . fc((float)$r['amount_in_batch']) . '</td>
        </tr>';
    }

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>' . sharedCSS() . '</style></head><body>
        <main>
            ' . docHeader('BATCH DISBURSEMENT REPORT — ' . strtoupper($batch_status_label), $batch['batch_ref'], $batch_status_label, 'Exported', fd($batch['exported_at'])) . '

            <table class="meta-grid">
                <tr>
                    <td><div class="meta-lbl">Created By</div><div class="meta-val">' . esc($batch['created_by_name']) . '</div></td>
                    <td><div class="meta-lbl">Created Date</div><div class="meta-val">' . fdt($batch['created_at']) . '</div></td>
                    <td><div class="meta-lbl">Disbursed Date</div><div class="meta-val">' . ($batch['disbursed_at'] ? fdt($batch['disbursed_at']) : '&mdash;') . '</div></td>
                    <td><div class="meta-lbl">Total Requisitions</div><div class="meta-val">' . count($batch_reqs) . ' items</div></td>
                </tr>
            </table>

            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:25%">Reference</th>
                        <th style="width:35%">Department</th>
                        <th style="width:20%">Period</th>
                        <th style="width:20%;text-align:right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $summary_rows . '
                    <tr class="total-row">
                        <td colspan="3" style="text-align:right">BATCH TOTAL</td>
                        <td class="num">' . fc((float)$batch['total_amount']) . '</td>
                    </tr>
                </tbody>
            </table>

            <table class="sig-layout"><tr>
                <td style="padding-right:5px; width:50%;"></td>
                <td style="padding-left:5px; width:50%;">' . ($fin_stamp ? financeStampCompact($fin_stamp) : '') . '</td>
            </tr></table>
        </main></body></html>';

    $filename = 'BATCH_' . preg_replace('/[^A-Za-z0-9\-]/', '', $batch['batch_ref']) . '_' . date('Ymd') . '.pdf';
}

// ══════════════════════════════════════════════════════════
// RENDER WITH DOMPDF
// ══════════════════════════════════════════════════════════
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isFontSubsettingEnabled', true);
$options->set('isPhpEnabled', false);
$options->set('chroot', realpath(__DIR__ . '/..'));

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');

$dompdf->add_info('Creator', 'HOD Lekki Centre — Requisition System');
$dompdf->add_info('Producer', 'Dompdf v3');

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
$ref_text = "Ref: " . $document_reference_number;
$canvas->page_text($margin_x, $footer_y + 8, $ref_text, $font, 6.5, array(0.39, 0.45, 0.54)); // #64748b

// 3. Inject Center Column: Generation Date
$gen_date = "Generated: " . (new DateTime())->format('d M Y, H:i') . " WAT";
$text_width = $dompdf->getFontMetrics()->getTextWidth($gen_date, $font, 6.5);
$canvas->page_text(($w / 2) - ($text_width / 2), $footer_y + 8, $gen_date, $font, 6.5, array(0.39, 0.45, 0.54));

// 4. Inject Right Column: Pagination (dompdf parses {PAGE_NUM} natively)
$page_text = "Page {PAGE_NUM}";
$canvas->page_text($w - $margin_x - 40, $footer_y + 8, $page_text, $font, 6.5, array(0.39, 0.45, 0.54));

// 5. Inject Verification Link block at the very bottom
$verify_text = "SECURE DOCUMENT — Verify authenticity instantly at: hodlc.lpc.cm/verify";
$verify_width = $dompdf->getFontMetrics()->getTextWidth($verify_text, $bold_font, 7);
$canvas->page_text(($w / 2) - ($verify_width / 2), $footer_y + 22, $verify_text, $bold_font, 7, array(0.11, 0.20, 0.41)); // #1D356A

// Stream output
$dompdf->stream($filename, ['Attachment' => false]);
exit;