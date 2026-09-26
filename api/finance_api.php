<?php
// /api/finance_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

// 1. Core Security & Session Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$active_role = $_SESSION['active_role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 2. Strict Finance RBAC
$is_finance_admin = in_array($active_role, ['Super_Admin', 'Resident_Pastor', 'Director']);
if (!$is_finance_admin) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied. Financial module is highly restricted.']);
    exit;
}

try {
    switch ($action) {

        // =====================================================================================
        // ACTION 1: FETCH DASHBOARD (Analytics, Configs, and Transactions)
        // =====================================================================================
        case 'fetch_dashboard':
            // 1. Get Setup Configurations (For the UI Dropdowns and Management Tables)
            $accounts = $pdo->query("SELECT * FROM finance_accounts ORDER BY account_name ASC")->fetchAll();
            $funds = $pdo->query("SELECT * FROM finance_funds ORDER BY fund_name ASC")->fetchAll();
            $categories = $pdo->query("SELECT * FROM finance_categories ORDER BY type ASC, category_name ASC")->fetchAll();

            // 2. Get Transaction History (Limit to 100 for performance, ordered by newest)
            $transactions = $pdo->query("
                SELECT t.*, a.account_name, f.fund_name, c.category_name, u.first_name, u.last_name
                FROM finance_transactions t
                JOIN finance_accounts a ON t.account_id = a.id
                JOIN finance_funds f ON t.fund_id = f.id
                LEFT JOIN finance_categories c ON t.category_id = c.id
                LEFT JOIN users u ON t.entered_by = u.id
                ORDER BY t.transaction_date DESC, t.id DESC
                LIMIT 100
            ")->fetchAll();

            // 3. IDI Analytics: Current Month Overview
            $currentMonth = date('Y-m');
            $statsStmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END) as total_income,
                    SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END) as total_expense
                FROM finance_transactions 
                WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?
            ");
            $statsStmt->execute([$currentMonth]);
            $month_stats = $statsStmt->fetch();

            echo json_encode([
                'status' => 'success',
                'accounts' => $accounts,
                'funds' => $funds,
                'categories' => $categories,
                'transactions' => $transactions,
                'analytics' => [
                    'month_income' => $month_stats['total_income'] ?: 0,
                    'month_expense' => $month_stats['total_expense'] ?: 0,
                    'net_position' => ($month_stats['total_income'] ?: 0) - ($month_stats['total_expense'] ?: 0)
                ]
            ]);
            break;

        // =====================================================================================
        // ACTION 2: PROCESS TRANSACTION (Income / Expense)
        // =====================================================================================
        case 'save_transaction':
            $date = $_POST['transaction_date'] ?? date('Y-m-d');
            $type = $_POST['transaction_type'] ?? '';
            $account_id = $_POST['account_id'] ?? '';
            $fund_id = $_POST['fund_id'] ?? '';
            $category_id = $_POST['category_id'] ?? null;
            $amount = floatval($_POST['amount'] ?? 0);
            $desc = trim($_POST['description'] ?? '');
            
            if (empty($type) || empty($account_id) || empty($fund_id) || $amount <= 0) {
                exit(json_encode(['status' => 'error', 'message' => 'Please fill all required transaction fields with a valid amount.']));
            }

            // Optional Receipt Upload
            $receipt_url = null;
            if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/finance/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = time() . '_REC_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['receipt_file']['name']));
                if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $upload_dir . $filename)) {
                    $receipt_url = '../../uploads/finance/' . $filename; 
                }
            }

            // Begin Transaction to ensure DB integrity
            $pdo->beginTransaction();

            // 1. Insert the Transaction Record
            $stmt = $pdo->prepare("INSERT INTO finance_transactions (transaction_date, transaction_type, account_id, fund_id, category_id, amount, description, receipt_url, entered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$date, $type, $account_id, $fund_id, $category_id, $amount, $desc, $receipt_url, $user_id]);

            // 2. Dynamically Update the Account Balance
            if ($type === 'Income') {
                $pdo->prepare("UPDATE finance_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $account_id]);
            } elseif ($type === 'Expense') {
                $pdo->prepare("UPDATE finance_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $account_id]);
            }

            // NOTIFICATION TRIGGER: Alert other Finance Admins/Pastors of the transaction
            $adminStmt = $pdo->query("
                SELECT user_id FROM user_roles 
                JOIN roles ON user_roles.role_id = roles.id 
                WHERE roles.role_name IN ('Super_Admin', 'Resident_Pastor')
            ");
            $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($admins)) {
                $formatted_amount = number_format($amount, 2);
                // Get the name of the person who entered it
                $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$user_id]);
                $uName = $uStmt->fetch(PDO::FETCH_ASSOC);
                $loggerName = $uName ? "{$uName['first_name']} {$uName['last_name']}" : "A finance admin";

                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Financial Transaction', ?, '/modules/finance/index.php')");
                $alertMessage = "A new {$type} of ₦{$formatted_amount} was just logged by {$loggerName}.";

                foreach($admins as $admin_id) {
                    if ($admin_id != $user_id) { // Don't alert the person who just logged it
                        $notifStmt->execute([$admin_id, $alertMessage]);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "$type of ₦" . number_format($amount, 2) . " processed successfully."]);
            break;

        // =====================================================================================
        // ACTION 3: DELETE TRANSACTION (And Restore Balance)
        // =====================================================================================
        case 'delete_transaction':
            $tx_id = $_POST['transaction_id'] ?? '';
            if (empty($tx_id)) exit(json_encode(['status' => 'error', 'message' => 'Transaction ID missing.']));

            $pdo->beginTransaction();

            // Get transaction details before deleting
            $txStmt = $pdo->prepare("SELECT amount, transaction_type, account_id FROM finance_transactions WHERE id = ?");
            $txStmt->execute([$tx_id]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);

            if ($tx) {
                // Reverse the balance
                if ($tx['transaction_type'] === 'Income') {
                    $pdo->prepare("UPDATE finance_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$tx['amount'], $tx['account_id']]);
                } elseif ($tx['transaction_type'] === 'Expense') {
                    $pdo->prepare("UPDATE finance_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$tx['amount'], $tx['account_id']]);
                }
                // Delete the record
                $pdo->prepare("DELETE FROM finance_transactions WHERE id = ?")->execute([$tx_id]);

                // NOTIFICATION TRIGGER: Critical Security Alert for Reversed Transactions
                $adminStmt = $pdo->query("
                    SELECT user_id FROM user_roles 
                    JOIN roles ON user_roles.role_id = roles.id 
                    WHERE roles.role_name IN ('Super_Admin', 'Resident_Pastor')
                ");
                $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($admins)) {
                    $formatted_amount = number_format($tx['amount'], 2);
                    $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    $uStmt->execute([$user_id]);
                    $uName = $uStmt->fetch(PDO::FETCH_ASSOC);
                    $loggerName = $uName ? "{$uName['first_name']} {$uName['last_name']}" : "A finance admin";

                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'SECURITY: Transaction Reversed', ?, '/modules/finance/index.php')");
                    $alertMessage = "A previously logged {$tx['transaction_type']} of ₦{$formatted_amount} was just reversed and deleted from the ledger by {$loggerName}.";

                    foreach($admins as $admin_id) {
                        if ($admin_id != $user_id) { 
                            $notifStmt->execute([$admin_id, $alertMessage]);
                        }
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Transaction reversed and deleted successfully.']);
            break;

        // =====================================================================================
        // ACTION 4: CONFIGURATION MANAGEMENT (Add/Edit/Delete Accounts, Funds, Categories)
        // =====================================================================================
        
        // --- ACCOUNTS (Bank / Cash) ---
        case 'save_account':
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['account_name'] ?? '');
            $type = $_POST['account_type'] ?? 'Bank';
            if (empty($name)) exit(json_encode(['status' => 'error', 'message' => 'Account name required.']));
            if ($id) {
                $pdo->prepare("UPDATE finance_accounts SET account_name = ?, account_type = ? WHERE id = ?")->execute([$name, $type, $id]);
            } else {
                $pdo->prepare("INSERT INTO finance_accounts (account_name, account_type) VALUES (?, ?)")->execute([$name, $type]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Ledger Account saved.']);
            break;

        case 'delete_account':
            $id = $_POST['id'] ?? '';
            // Verify no transactions exist before deleting
            $chk = $pdo->prepare("SELECT id FROM finance_transactions WHERE account_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) exit(json_encode(['status' => 'error', 'message' => 'Cannot delete this account. It has active transactions.']));
            $pdo->prepare("DELETE FROM finance_accounts WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'Account deleted.']);
            break;

        // --- FUNDS (Virtual Wallets) ---
        case 'save_fund':
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['fund_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if (empty($name)) exit(json_encode(['status' => 'error', 'message' => 'Fund name required.']));
            if ($id) {
                $pdo->prepare("UPDATE finance_funds SET fund_name = ?, description = ? WHERE id = ?")->execute([$name, $desc, $id]);
            } else {
                $pdo->prepare("INSERT INTO finance_funds (fund_name, description) VALUES (?, ?)")->execute([$name, $desc]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Fund/Wallet saved.']);
            break;

        case 'delete_fund':
            $id = $_POST['id'] ?? '';
            $chk = $pdo->prepare("SELECT id FROM finance_transactions WHERE fund_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) exit(json_encode(['status' => 'error', 'message' => 'Cannot delete this fund. It has active transactions.']));
            $pdo->prepare("DELETE FROM finance_funds WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'Fund deleted.']);
            break;

        // --- CATEGORIES (Income / Expense Types) ---
        case 'save_category':
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['category_name'] ?? '');
            $type = $_POST['type'] ?? 'Income';
            if (empty($name)) exit(json_encode(['status' => 'error', 'message' => 'Category name required.']));
            if ($id) {
                $pdo->prepare("UPDATE finance_categories SET category_name = ?, type = ? WHERE id = ?")->execute([$name, $type, $id]);
            } else {
                $pdo->prepare("INSERT INTO finance_categories (category_name, type) VALUES (?, ?)")->execute([$name, $type]);
            }
            echo json_encode(['status' => 'success', 'message' => 'Category saved.']);
            break;

        case 'delete_category':
            $id = $_POST['id'] ?? '';
            $chk = $pdo->prepare("SELECT id FROM finance_transactions WHERE category_id = ? LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) exit(json_encode(['status' => 'error', 'message' => 'Cannot delete category. It is tied to existing transactions.']));
            $pdo->prepare("DELETE FROM finance_categories WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'Category deleted.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API action requested.']);
            break;
    }

} catch (PDOException $e) {
    error_log("Finance API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred. Check system logs.']);
}
?>