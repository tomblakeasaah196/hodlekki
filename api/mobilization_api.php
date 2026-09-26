<?php
// /api/mobilization_api.php

require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure error reporting doesn't break JSON output
ini_set('display_errors', 0);
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    // =====================================================================================
    // 1. VERIFY PIN (Publicly Accessible)
    // =====================================================================================
    if ($action === 'verify_pin') {
        $pin_attempt = trim($_POST['pin'] ?? '');
        
        $stmt = $pdo->prepare("SELECT setting_value FROM mobilization_settings WHERE setting_key = 'access_pin'");
        $stmt->execute();
        $correct_pin = $stmt->fetchColumn();

        if ($pin_attempt === $correct_pin) {
            $_SESSION['idi_mobilization_auth'] = true;
            echo json_encode(['status' => 'success', 'message' => 'Access Granted']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Incorrect PIN']);
        }
        exit;
    }

    // =====================================================================================
    // SECURITY CHECK: All actions below this line require the PIN session
    // =====================================================================================
    if (empty($_SESSION['idi_mobilization_auth'])) {
        echo json_encode(['status' => 'auth_error', 'message' => 'Session expired. Please enter the PIN again.']);
        exit;
    }

    $campaign = trim($_POST['event_campaign'] ?? $_GET['event_campaign'] ?? 'Exousia 2026');

    switch ($action) {
        // =====================================================================================
        // 2. FETCH CONTACTS (Separated into Uncontacted and Contacted)
        // =====================================================================================
        case 'fetch_contacts':
            // Tab 1: Uncontacted (No notes saved yet)
            $stmt1 = $pdo->prepare("SELECT * FROM idi_mobilization WHERE event_campaign = ? AND is_contacted = 0 ORDER BY full_name ASC");
            $stmt1->execute([$campaign]);
            $uncontacted = $stmt1->fetchAll(PDO::FETCH_ASSOC);

            // Tab 2: Contacted (Has follow-up notes)
            $stmt2 = $pdo->prepare("SELECT * FROM idi_mobilization WHERE event_campaign = ? AND is_contacted = 1 ORDER BY updated_at DESC");
            $stmt2->execute([$campaign]);
            $contacted = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'uncontacted' => $uncontacted,
                'contacted' => $contacted
            ]);
            break;

        // =====================================================================================
        // 3. UPDATE / SAVE FOLLOW-UP NOTE
        // =====================================================================================
        case 'update_note':
            $id = $_POST['id'] ?? '';
            $notes = trim($_POST['followup_notes'] ?? '');
            $contacted_by = trim($_POST['contacted_by'] ?? '');
            
            if (empty($id) || empty($notes) || empty($contacted_by)) {
                echo json_encode(['status' => 'error', 'message' => 'Notes and your name are required.']);
                exit;
            }

            // Saving a note automatically moves them to Tab 2 (is_contacted = 1)
            $stmt = $pdo->prepare("
                UPDATE idi_mobilization 
                SET followup_notes = ?, contacted_by = ?, is_contacted = 1 
                WHERE id = ?
            ");
            $stmt->execute([$notes, $contacted_by, $id]);

            echo json_encode(['status' => 'success', 'message' => 'Follow-up saved successfully!']);
            break;

        // =====================================================================================
        // 4. EXPORT TO EXCEL / CSV
        // =====================================================================================
        case 'export_data':
            $stmt = $pdo->prepare("SELECT full_name, phone_number, context, followup_notes, contacted_by, updated_at FROM idi_mobilization WHERE event_campaign = ? ORDER BY is_contacted ASC, full_name ASC");
            $stmt->execute([$campaign]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Output directly as a downloadable CSV/Excel format
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=Mobilization_List_' . date('Y-m-d') . '.csv');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Full Name', 'Phone Number', 'Context/Background', 'Follow-up Notes', 'Contacted By', 'Last Updated']);
            
            foreach ($records as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
            exit; // Stop execution here so JSON doesn't append to the Excel file

        // =====================================================================================
        // 5. IMPORT EXCEL / CSV (Idempotent Update)
        // =====================================================================================
        case 'import_data':
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'Please upload a valid file.']);
                exit;
            }

            $file_tmp = $_FILES['import_file']['tmp_name'];
            $file_name = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $rows = [];

            // Method 1: Native CSV Parsing
            if ($ext === 'csv') {
                if (($handle = fopen($file_tmp, "r")) !== FALSE) {
                    $header = fgetcsv($handle, 1000, ","); // Skip header
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (array_filter($data)) {
                            $rows[] = $data;
                        }
                    }
                    fclose($handle);
                }
            } 
            // Method 2: PhpSpreadsheet for Excel Parsing
            elseif (in_array($ext, ['xls', 'xlsx'])) {
                try {
                    // Assuming PhpSpreadsheet is autoloaded in your project environment
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_tmp);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                    array_shift($rows); // Remove header row
                } catch (Throwable $e) {
                    error_log("Excel Import Error: " . $e->getMessage());
                    echo json_encode(['status' => 'error', 'message' => 'Excel processing error. Please ensure the file is not corrupted, or try saving as CSV.']);
                    exit;
                }
            } 
            else {
                echo json_encode(['status' => 'error', 'message' => 'Only .csv, .xls, and .xlsx files are supported.']);
                exit;
            }

            if (empty($rows)) {
                echo json_encode(['status' => 'error', 'message' => 'The uploaded file appears to be empty.']);
                exit;
            }

            $imported = 0;
            $updated = 0;

            // Updated Idempotent Insert (MySQL 8+ Compatible)
            $stmt = $pdo->prepare("
                INSERT INTO idi_mobilization (event_campaign, full_name, phone_number, context) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    full_name = ?, 
                    context = ?
            ");

            foreach ($rows as $row) {
                // 1. Clean invisible Excel characters (like \xA0) and convert to clean UTF-8
                $name = trim(str_replace("\xA0", ' ', $row[0] ?? ''));
                $name = mb_convert_encoding($name, 'UTF-8', 'auto');
                
                // 2. Aggressively strip EVERYTHING from the phone number except digits and the + sign
                $raw_phone = trim($row[1] ?? '');
                $phone = preg_replace('/[^\d\+]/', '', $raw_phone);
                
                // 3. Clean the context string
                $raw_context = trim(str_replace("\xA0", ' ', $row[2] ?? ''));
                $raw_context = mb_convert_encoding($raw_context, 'UTF-8', 'auto');
                $context = strlen($raw_context) > 200 ? substr($raw_context, 0, 197) . '...' : $raw_context;

                if (!empty($name) && !empty($phone)) {
                    // We pass the variables twice: Once for the INSERT, once for the UPDATE fallback
                    $stmt->execute([$campaign, $name, $phone, $context, $name, $context]);
                    
                    if ($stmt->rowCount() == 1) {
                        $imported++;
                    } else {
                        $updated++;
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => "Successfully imported $imported new records and updated $updated existing records."]);
            break;
            
            // =====================================================================================
        // 6. QUICK ADD (Single Record)
        // =====================================================================================
        case 'quick_add':
            $name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $context = trim($_POST['context'] ?? '');

            if (empty($name) || empty($phone)) {
                echo json_encode(['status' => 'error', 'message' => 'Name and Phone are required.']);
                exit;
            }

            // Idempotent: If phone exists, update name and context but protect follow-up notes
            $stmt = $pdo->prepare("
                INSERT INTO idi_mobilization (event_campaign, full_name, phone_number, context) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    full_name = VALUES(full_name), 
                    context = VALUES(context)
            ");
            $stmt->execute([$campaign, $name, $phone, $context]);

            echo json_encode(['status' => 'success', 'message' => 'Contact added successfully!']);
            break;

        // =====================================================================================
        // 7. DOWNLOAD EXCEL TEMPLATE
        // =====================================================================================
        case 'download_template':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=Import_Template.csv');
            $output = fopen('php://output', 'w');
            
            // Write Headers
            fputcsv($output, ['Full Name', 'Phone Number', 'Context']);
            // Write a sample row so they know exactly what to do
            fputcsv($output, ['John Doe', '08012345678', 'Visited the church in 2025. Needs a call.']);
            
            fclose($output);
            exit;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            break;
    }

} catch (Throwable $e) {
    error_log("Mobilization API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server Error: ' . $e->getMessage()]);
}
?>