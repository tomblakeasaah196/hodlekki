<?php
// /includes/charis_event_report_pdf.php
// HODLC Charis — Event Completion Report PDF
// Includes HOD + Finance Director digital stamps

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: /auth/login.php'); exit; }

$session_roles = array_column($_SESSION['roles'] ?? [], 'role_name');
$is_privileged = array_intersect($session_roles, ['Super_Admin','Resident_Pastor','Assoc_Pastor','Director','HOD']) !== [];
if (!$is_privileged) { http_response_code(403); die('<h2 style="font-family:sans-serif;color:#c00">Access Denied.</h2>'); }

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) { die('<h2>PDF library not found.</h2>'); }
require_once $autoload;
use Dompdf\Dompdf;
use Dompdf\Options;

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) die('Event ID required.');

// ─── Pull Event ───────────────────────────────────────────
$evtStmt = $pdo->prepare("SELECT e.*, DATE_FORMAT(e.event_date,'%W, %d %B %Y') as nice_date FROM events e WHERE e.id = ?");
$evtStmt->execute([$event_id]);
$event = $evtStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) die('Event not found.');

// ─── Pull Tasks ───────────────────────────────────────────
$taskStmt = $pdo->prepare("
    SELECT t.*, u.first_name as worker_first, u.last_name as worker_last, u.phone as worker_phone
    FROM charis_event_tasks t
    LEFT JOIN users u ON t.assigned_worker_id = u.id
    WHERE t.event_id = ?
    ORDER BY t.created_at ASC
");
$taskStmt->execute([$event_id]);
$tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Pull Stamps ──────────────────────────────────────────
$stampStmt = $pdo->prepare("SELECT * FROM charis_event_completion_stamps WHERE event_id = ?");
$stampStmt->execute([$event_id]);
$stamp_row = $stampStmt->fetch(PDO::FETCH_ASSOC);
$hod_stamp     = $stamp_row && $stamp_row['hod_stamp_json']     ? json_decode($stamp_row['hod_stamp_json'],     true) : null;
$finance_stamp = $stamp_row && $stamp_row['finance_stamp_json'] ? json_decode($stamp_row['finance_stamp_json'], true) : null;

// ─── Pull Logistics header ────────────────────────────────
$logStmt = $pdo->prepare("SELECT * FROM charis_event_logistics WHERE event_id = ?");
$logStmt->execute([$event_id]);
$logistics = $logStmt->fetch(PDO::FETCH_ASSOC);

// ─── Financials ───────────────────────────────────────────
$total_est    = array_sum(array_column($tasks, 'estimated_budget'));
$total_actual = array_sum(array_column($tasks, 'actual_spent'));
$variance     = $total_est - $total_actual;

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function fc(float $v): string   { return '&#8358;' . number_format($v, 2); }
function fd(?string $ds): string { if (!$ds) return '—'; return (new DateTime($ds))->format('d M Y'); }
function fdt(?string $ds): string { if (!$ds) return '—'; $d = new DateTime($ds); return $d->format('d M Y') . ' — ' . $d->format('H:i:s') . ' WAT'; }

$logo_path = __DIR__ . '/../assets/images/hod_logo.svg';
$logo_b64  = file_exists($logo_path) ? 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($logo_path)) : '';
$ref_code  = 'EVT-' . $event_id . '-RPT-' . date('Ymd');
$gen_by    = esc(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$gen_date  = date('d M Y — H:i:s') . ' WAT';

// Stamp renderer helper (inline)
function renderStampBlock(array $s, string $title): string {
    return '
    <table style="width:100%; border-collapse:collapse; border:2px solid #1D356A; border-radius:4px; margin-bottom:12px;">
        <tr style="background:linear-gradient(90deg,#0A0E17,#1D356A);">
            <td colspan="2" style="padding:8px 12px;">
                <span style="color:#fff; font-size:8pt; font-weight:900; text-transform:uppercase; letter-spacing:0.5px;">' . esc($title) . '</span>
                <span style="float:right; background:#4ade80; color:#14532d; font-size:6pt; font-weight:900; padding:2px 7px; border-radius:10px; text-transform:uppercase;">✓ VALID</span>
            </td>
        </tr>
        <tr>
            <td style="padding:8px 12px; width:50%; border-right:1px solid #e2e8f0; vertical-align:top;">
                <div style="font-size:6pt; color:#94a3b8; text-transform:uppercase; font-weight:bold;">Signed By</div>
                <div style="font-size:9pt; font-weight:900; color:#0f172a; margin-top:2px;">' . esc($s['name'] ?? '—') . '</div>
                <div style="font-size:7pt; color:#475569; margin-top:1px;">' . esc($s['role'] ?? '') . '</div>
                <div style="font-size:6.5pt; color:#64748b; margin-top:4px;"><strong>Action:</strong> ' . esc($s['action'] ?? '') . '</div>
                <div style="font-size:6.5pt; color:#64748b; margin-top:2px;"><strong>Date:</strong> ' . esc($s['date_time'] ?? '') . '</div>
                <div style="font-size:6.5pt; color:#64748b; margin-top:2px;"><strong>Amount:</strong> ' . esc($s['total_validated'] ?? '') . '</div>
            </td>
            <td style="padding:8px 12px; vertical-align:top; background:#f8fafc;">
                <div style="font-size:6pt; color:#64748b; text-transform:uppercase; font-weight:bold; margin-bottom:3px;">Cryptographic Verification</div>
                <div style="font-family: monospace; font-size:6.5pt; background:#0f172a; color:#38bdf8; padding:7px; border-radius:3px; word-break:break-all; line-height:1.7;">
                    <div><span style="color:#94a3b8;">SHA256: </span><span style="color:#e2e8f0;">' . esc($s['hash'] ?? '') . '</span></div>
                    <div style="margin-top:3px;"><span style="color:#94a3b8;">VERIFY: </span><span style="color:#facc15; font-weight:900;">' . esc($s['verify_code'] ?? '') . '</span></div>
                    <div style="margin-top:3px;"><span style="color:#94a3b8;">STATUS: </span><span style="color:#4ade80; font-weight:900;">VALID &amp; BINDING</span></div>
                    <div style="margin-top:3px;"><span style="color:#94a3b8;">IP: </span>' . esc($s['ip_address'] ?? '') . '</div>
                </div>
            </td>
        </tr>
    </table>';
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { margin-top:42pt; margin-bottom:55pt; margin-left:40pt; margin-right:40pt; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:DejaVu Sans, Arial, sans-serif; font-size:8.5pt; color:#1e293b; line-height:1.5; }
.divider { border:none; border-top:2.5px solid #D11920; margin:10px 0; }
.divider-thin { border:none; border-top:1px solid #e2e8f0; margin:8px 0; }
.section-hd { font-size:8.5pt; font-weight:900; color:#0A0E17; text-transform:uppercase; letter-spacing:0.8px; border-bottom:2px solid #D11920; padding-bottom:3px; margin:14px 0 10px; }
.task-row td { padding:6px 8px; font-size:7.5pt; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.task-table { width:100%; border-collapse:collapse; border:1px solid #e2e8f0; margin-bottom:12px; }
.task-table thead td { background:#f8fafc; font-size:6.5pt; font-weight:900; text-transform:uppercase; color:#64748b; letter-spacing:0.5px; padding:7px 8px; }
.badge { display:inline-block; padding:2px 6px; border-radius:3px; font-size:6pt; font-weight:bold; text-transform:uppercase; }
.b-done { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.b-prog { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
.b-pend { background:#fef3c7; color:#92400e; border:1px solid #fbbf24; }
.fin-box { background:#f8fafc; border:1px solid #e2e8f0; padding:10px 14px; border-radius:3px; margin-bottom:14px; }
.fin-row td { padding:5px 0; width:25%; font-size:8pt; }
.fin-lbl { font-size:6.5pt; color:#94a3b8; text-transform:uppercase; font-weight:bold; }
.fin-val { font-size:11pt; font-weight:900; color:#0f172a; }
.fin-ok  { color:#059669; }
.fin-ov  { color:#DC2626; }
</style>
</head>
<body>

<!-- HEADER -->
<table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
    <tr>
        <td style="width:60%; vertical-align:middle;">
            <div style="font-size:12pt; font-weight:900; color:#0A0E17; margin-bottom:3px;">Household of David Lekki Centre</div>
            <div style="font-size:7pt; color:#64748b; line-height:1.4;">Charis Department — Event Completion Report</div>
        </td>
        <td style="width:15%; text-align:center; vertical-align:middle;">
            <?php if ($logo_b64): ?><img src="<?= $logo_b64 ?>" style="max-height:50px; width:auto; display:block; margin:0 auto;" alt="HOD"><?php endif; ?>
        </td>
        <td style="width:25%; text-align:right; vertical-align:middle;">
            <div style="font-size:10pt; font-weight:900; color:#0f172a;"><?= esc($ref_code) ?></div>
            <div style="font-size:6.5pt; color:#94a3b8; margin-top:2px;">Generated: <?= esc($gen_date) ?></div>
            <div style="font-size:6.5pt; color:#94a3b8;">By: <?= esc($gen_by) ?></div>
        </td>
    </tr>
</table>
<hr class="divider">

<!-- EVENT INFO -->
<div style="font-size:14pt; font-weight:900; color:#0A0E17; margin-bottom:3px;"><?= esc($event['title']) ?></div>
<div style="font-size:9pt; color:#D11920; font-weight:bold; margin-bottom:14px;"><?= esc($event['nice_date']) ?></div>

<div class="section-hd">Financial Summary</div>
<div class="fin-box">
    <table class="fin-row" style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="border-right:1px solid #e2e8f0; text-align:center;">
                <div class="fin-lbl">Total Estimated</div>
                <div class="fin-val"><?= fc($total_est) ?></div>
            </td>
            <td style="border-right:1px solid #e2e8f0; text-align:center;">
                <div class="fin-lbl">Total Actual Spent</div>
                <div class="fin-val"><?= fc($total_actual) ?></div>
            </td>
            <td style="border-right:1px solid #e2e8f0; text-align:center;">
                <div class="fin-lbl">Variance</div>
                <div class="fin-val <?= $variance >= 0 ? 'fin-ok' : 'fin-ov' ?>"><?= fc(abs($variance)) ?> <?= $variance >= 0 ? 'Under' : 'Over' ?></div>
            </td>
            <td style="text-align:center;">
                <div class="fin-lbl">Tasks Total</div>
                <div class="fin-val"><?= count($tasks) ?></div>
            </td>
        </tr>
    </table>
</div>

<!-- TASK BREAKDOWN -->
<div class="section-hd">Task Breakdown & Member Reports</div>
<?php if (empty($tasks)): ?>
<p style="color:#94a3b8; font-size:8pt; text-align:center; padding:20px;">No tasks were created for this event.</p>
<?php else: ?>
<table class="task-table">
    <thead>
        <tr>
            <td style="width:25%">Task</td>
            <td style="width:20%">Assigned To</td>
            <td style="width:10%; text-align:center;">Est. Budget</td>
            <td style="width:10%; text-align:center;">Actual Spent</td>
            <td style="width:10%; text-align:center;">Status</td>
            <td style="width:25%">Member Report / Notes</td>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($tasks as $t): ?>
    <tr class="task-row">
        <td>
            <strong><?= esc($t['task_title']) ?></strong>
            <?php if ($t['task_description']): ?>
            <br><span style="font-size:7pt; color:#64748b;"><?= esc($t['task_description']) ?></span>
            <?php endif; ?>
            <?php if ($t['funds_disbursed']): ?>
            <br><span style="font-size:6.5pt; color:#059669; font-weight:bold;">✓ Funds Disbursed <?= fd($t['funds_disbursed_at']) ?></span>
            <?php endif; ?>
        </td>
        <td>
            <?= esc($t['worker_first'] . ' ' . $t['worker_last']) ?>
            <?php if ($t['worker_phone']): ?>
            <br><span style="font-size:7pt; color:#64748b;"><?= esc($t['worker_phone']) ?></span>
            <?php endif; ?>
        </td>
        <td style="text-align:center;"><?= fc((float)$t['estimated_budget']) ?></td>
        <td style="text-align:center;"><?= fc((float)$t['actual_spent']) ?></td>
        <td style="text-align:center;">
            <?php
            $bc = match($t['status']) {
                'Completed'  => 'b-done',
                'In_Progress'=> 'b-prog',
                default      => 'b-pend'
            };
            ?>
            <span class="badge <?= $bc ?>"><?= esc(str_replace('_',' ',$t['status'])) ?></span>
            <?php if ($t['completed_at']): ?>
            <br><span style="font-size:6pt; color:#94a3b8;"><?= fd($t['completed_at']) ?></span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($t['member_report']): ?>
            <em style="font-size:7.5pt; color:#334155;">"<?= esc($t['member_report']) ?>"</em>
            <?php else: ?>
            <span style="color:#cbd5e1; font-size:7.5pt;">No report submitted.</span>
            <?php endif; ?>
            <?php if ($t['receipt_note']): ?>
            <br><span style="font-size:7pt; color:#64748b;"><strong>Receipt:</strong> <?= esc($t['receipt_note']) ?></span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- DIGITAL STAMPS -->
<div class="section-hd">Digital Sign-Off</div>
<?php if (!$hod_stamp && !$finance_stamp): ?>
<p style="color:#f59e0b; font-size:8pt; padding:10px; background:#fef3c7; border:1px solid #fbbf24; border-radius:3px;">
    This report has not yet been digitally signed. The HOD must sign off first, followed by the Finance Director.
</p>
<?php else: ?>
<?php if ($hod_stamp): echo renderStampBlock($hod_stamp, 'HOD Digital Sign-Off'); endif; ?>
<?php if ($finance_stamp): echo renderStampBlock($finance_stamp, 'Finance Director Countersignature'); endif; ?>
<?php if ($hod_stamp && !$finance_stamp): ?>
<p style="color:#3b82f6; font-size:7.5pt; padding:8px 10px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:3px;">
    Awaiting Finance Director countersignature to fully seal this report.
</p>
<?php endif; ?>
<?php endif; ?>

<!-- FOOTER -->
<div style="font-size:6.5pt; color:#94a3b8; text-align:center; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:8px;">
    <strong style="color:#64748b;">Household of David Lekki Centre</strong> — Charis Department &nbsp;|&nbsp;
    <?= esc($ref_code) ?> &nbsp;|&nbsp; Generated by <?= esc($gen_by) ?> on <?= esc($gen_date) ?>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isPhpEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', realpath(__DIR__ . '/..'));

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->add_info('Title', 'Event Completion Report — ' . $event['title']);
$dompdf->add_info('Author', 'HOD Lekki Centre — Charis Department');
$dompdf->render();

$filename = 'HODLC_Event_Report_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $event['title']) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
