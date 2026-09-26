<?php
// /api/public_verify_api.php
// House of David Lekki Centre — Public Document Verification API
// Front-facing, read-only lookup for Cryptographic Verification Codes (VRF-XXXX-XXXX)

require_once '../includes/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: *'); // Publicly accessible

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'verify_document':
            // 1. Honeypot check to prevent bot spam
            $honeypot = $_POST['honeypot'] ?? '';
            if (!empty($honeypot)) {
                // Silently pretend it worked or failed to trick bots
                echo json_encode(['status' => 'error', 'message' => 'Invalid verification code.']);
                exit;
            }

            // 2. Sanitize and prepare the code
            $code = strtoupper(trim($_POST['verify_code'] ?? ''));
            if (empty($code)) {
                echo json_encode(['status' => 'error', 'message' => 'Please enter a verification code.']);
                exit;
            }

            // 3. Check 1: Is it a Director Validated Requisition?
            $stmtDir = $pdo->prepare("
                SELECT rds.hash_code, rds.stamp_json, rds.stamped_at, 
                       rh.ref_number, rh.total_amount, d.name AS dept_name
                FROM requisition_director_stamps rds
                JOIN requisition_headers rh ON rds.requisition_id = rh.id
                JOIN departments d ON rh.department_id = d.id
                WHERE rds.verify_code = ?
            ");
            $stmtDir->execute([$code]);
            $dirStamp = $stmtDir->fetch(PDO::FETCH_ASSOC);

            if ($dirStamp) {
                $stampData = json_decode($dirStamp['stamp_json'], true);
                
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'document_type' => 'Departmental Requisition',
                        'reference'     => $dirStamp['ref_number'],
                        'department'    => $dirStamp['dept_name'],
                        'amount'        => $stampData['total_validated'] ?? ('₦' . number_format($dirStamp['total_amount'], 2)),
                        'validated_by'  => $stampData['name'] ?? 'Authorized Director',
                        'role'          => $stampData['role'] ?? 'Director',
                        'timestamp'     => $stampData['date_time'] ?? $dirStamp['stamped_at'],
                        'cryptographic_hash' => $dirStamp['hash_code'],
                        'verification_status'=> 'VALID & BINDING',
                        'stamp_level'   => 'Director Level'
                    ]
                ]);
                exit;
            }

            // 4. Check 2: Is it a Finance HQ Sealed Batch?
            $stmtFin = $pdo->prepare("
                SELECT rb.batch_ref, rb.total_amount, rb.finance_stamp_hash, 
                       rb.finance_stamp_json, rb.finance_stamp_at
                FROM requisition_batches rb
                WHERE rb.finance_stamp_verify_code = ?
            ");
            $stmtFin->execute([$code]);
            $finStamp = $stmtFin->fetch(PDO::FETCH_ASSOC);

            if ($finStamp) {
                $stampData = json_decode($finStamp['finance_stamp_json'], true);
                
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'document_type' => 'Finance Disbursement Batch',
                        'reference'     => $finStamp['batch_ref'],
                        'department'    => 'Finance Headquarters',
                        'amount'        => $stampData['total_validated'] ?? ('₦' . number_format($finStamp['total_amount'], 2)),
                        'validated_by'  => $stampData['name'] ?? 'Authorized Finance Officer',
                        'role'          => $stampData['role'] ?? 'Finance Director',
                        'timestamp'     => $stampData['date_time'] ?? $finStamp['finance_stamp_at'],
                        'cryptographic_hash' => $finStamp['finance_stamp_hash'],
                        'verification_status'=> 'VALID & BINDING',
                        'stamp_level'   => 'Finance HQ Level'
                    ]
                ]);
                exit;
            }

            // 5. If no matches found in either table
            echo json_encode([
                'status' => 'error', 
                'message' => 'No record found. The code may be invalid, or the document has not been digitally stamped.'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid API route.']);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    // You can suppress $e->getMessage() in production to hide DB schema errors from the public
    error_log("Verification API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'System error during verification. Please try again later.']);
}
?>