<?php
// /api/library_api.php

require_once '../includes/db.php';
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// User ID is nullable here because viewing the library is public
$user_id = $_SESSION['user_id'] ?? null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper: Ensure user is logged in for protected actions
function require_login($user_id) {
    if (!$user_id) {
        echo json_encode(['status' => 'auth_required', 'message' => 'Please log in to your church portal to perform this action.']);
        exit;
    }
}

try {
    switch ($action) {

        // ==========================================
        // ACTION 1: FETCH FULL LIBRARY CATALOG & USER DATA
        // ==========================================
        case 'fetch_library':
            // 1. Fetch Categories for the UI filter
            $catStmt = $pdo->query("SELECT DISTINCT category FROM charis_books ORDER BY category ASC");
            $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

            // 2. Fetch the Master Catalog
            $search = trim($_GET['search'] ?? '');
            $category = trim($_GET['category'] ?? '');
            
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if (!empty($search)) {
                $whereClause .= " AND (title LIKE ? OR author LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if (!empty($category)) {
                $whereClause .= " AND category = ?";
                $params[] = $category;
            }

            $booksStmt = $pdo->prepare("SELECT * FROM charis_books $whereClause ORDER BY title ASC");
            $booksStmt->execute($params);
            $books = $booksStmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. If User is Logged In, fetch their personal library data
            $active_borrows = [];
            $waitlist = [];
            $reading_progress = [];
            $can_borrow = false;

            if ($user_id) {
                // Get Active Physical Borrows
                $borrowStmt = $pdo->prepare("
                    SELECT b.*, cb.title, cb.cover_image_path, cb.author 
                    FROM charis_book_borrowing b
                    JOIN charis_books cb ON b.book_id = cb.id
                    WHERE b.user_id = ? AND b.status IN ('Reserved', 'Picked_Up', 'Overdue')
                    ORDER BY b.due_date ASC
                ");
                $borrowStmt->execute([$user_id]);
                $active_borrows = $borrowStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $can_borrow = count($active_borrows) < 3; // Enforce the 3 book limit

                // PATCHED: Get Waitlisted Books (Full objects for UI rendering)
                $waitStmt = $pdo->prepare("
                    SELECT w.book_id, w.status, cb.title, cb.cover_image_path, cb.author 
                    FROM charis_book_waitlist w
                    JOIN charis_books cb ON w.book_id = cb.id
                    WHERE w.user_id = ? AND w.status IN ('Waiting', 'Notified')
                ");
                $waitStmt->execute([$user_id]);
                $waitlist = $waitStmt->fetchAll(PDO::FETCH_ASSOC);

                // PATCHED: Get E-Book & Audio Progress (Full objects for UI rendering)
                $progStmt = $pdo->prepare("
                    SELECT p.book_id, p.last_page_read, cb.title, cb.author, cb.cover_image_path, cb.ebook_file_path, cb.audiobook_link 
                    FROM charis_ebook_progress p
                    JOIN charis_books cb ON p.book_id = cb.id
                    WHERE p.user_id = ?
                ");
                $progStmt->execute([$user_id]);
                $reading_progress = $progStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'status' => 'success',
                'is_logged_in' => (bool)$user_id,
                'can_borrow_more' => $can_borrow,
                'categories' => $categories,
                'books' => $books,
                'user_data' => [
                    'active_borrows' => $active_borrows,
                    'waitlist' => $waitlist,
                    'reading_progress' => $reading_progress
                ]
            ]);
            break;

        // ==========================================
        // ACTION 2: BORROW PHYSICAL BOOK (SMART SCHEDULING)
        // ==========================================
        case 'borrow_book':
            require_login($user_id);
            $book_id = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);

            if (!$book_id) exit(json_encode(['status' => 'error', 'message' => 'Invalid book ID.']));

            $pdo->beginTransaction();
            try {
                // Check user limit again securely
                $limitStmt = $pdo->prepare("SELECT COUNT(*) FROM charis_book_borrowing WHERE user_id = ? AND status IN ('Reserved', 'Picked_Up', 'Overdue')");
                $limitStmt->execute([$user_id]);
                if ($limitStmt->fetchColumn() >= 3) {
                    throw new Exception('You have reached the maximum limit of 3 active books. Please return a book before borrowing another.');
                }

                // Lock the row and check availability
                $bookStmt = $pdo->prepare("SELECT available_copies, title FROM charis_books WHERE id = ? FOR UPDATE");
                $bookStmt->execute([$book_id]);
                $book = $bookStmt->fetch(PDO::FETCH_ASSOC);

                if (!$book || $book['available_copies'] <= 0) {
                    throw new Exception('Sorry, the last copy of "' . $book['title'] . '" was just taken. Please join the waitlist.');
                }

                // PATCHED: Calculate Pickup Date (Next closest Thursday or Friday)
                $pickup_date = new DateTime();
                while (!in_array($pickup_date->format('w'), [4, 5])) { // 4 = Thu, 5 = Fri
                    $pickup_date->modify('+1 day');
                }
                $formatted_pickup = $pickup_date->format('l, M jS');

                // PATCHED: Calculate Due Date (2 weeks from now, snapped to closest Thursday or Sunday)
                $due_date = new DateTime('+14 days');
                while (!in_array($due_date->format('w'), [0, 4])) { // 0 = Sun, 4 = Thu
                    $due_date->modify('+1 day');
                }
                $formatted_due_date = $due_date->format('Y-m-d');

                // Record the borrow
                $insertStmt = $pdo->prepare("INSERT INTO charis_book_borrowing (book_id, user_id, due_date, status) VALUES (?, ?, ?, 'Reserved')");
                $insertStmt->execute([$book_id, $user_id, $formatted_due_date]);

                // Decrement inventory
                $updateStmt = $pdo->prepare("UPDATE charis_books SET available_copies = available_copies - 1 WHERE id = ?");
                $updateStmt->execute([$book_id]);

                // NOTIFICATION TRIGGER: Alert Charis Librarians to prep the book
                $charisStmt = $pdo->query("
                    SELECT ud.user_id FROM user_departments ud 
                    JOIN departments d ON ud.department_id = d.id 
                    WHERE d.name LIKE '%Charis%' AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
                ");
                $charis_admins = $charisStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($charis_admins)) {
                    $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    $uStmt->execute([$user_id]);
                    $usr = $uStmt->fetch(PDO::FETCH_ASSOC);
                    $uName = $usr ? "{$usr['first_name']} {$usr['last_name']}" : "A member";

                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'New Book Reservation', ?, '/modules/charis/index.php')");
                    $alertMsg = "{$uName} has reserved '{$book['title']}' and is scheduled to pick it up on {$formatted_pickup}.";
                    
                    foreach($charis_admins as $admin_id) {
                        $notifStmt->execute([$admin_id, $alertMsg]);
                    }
                }

                $pdo->commit();
                echo json_encode([
                    'status' => 'success', 
                    'message' => "Book reserved! Your estimated pickup day is $formatted_pickup at the Charis desk."
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        // ==========================================
        // ACTION 3: JOIN WAITLIST
        // ==========================================
        case 'join_waitlist':
            require_login($user_id);
            $book_id = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);

            if (!$book_id) exit(json_encode(['status' => 'error', 'message' => 'Invalid book ID.']));

            // Prevent duplicate waiting
            $checkStmt = $pdo->prepare("SELECT id FROM charis_book_waitlist WHERE user_id = ? AND book_id = ? AND status IN ('Waiting', 'Notified')");
            $checkStmt->execute([$user_id, $book_id]);
            
            if ($checkStmt->fetch()) {
                echo json_encode(['status' => 'warning', 'message' => 'You are already on the waitlist for this book.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO charis_book_waitlist (book_id, user_id) VALUES (?, ?)");
            $stmt->execute([$book_id, $user_id]);

            echo json_encode(['status' => 'success', 'message' => 'You have joined the waitlist. We will notify you the moment a copy is returned!']);
            break;

        // ==========================================
        // ACTION 4: NUDGE CURRENT BORROWERS
        // ==========================================
        case 'nudge_borrowers':
            require_login($user_id);
            $book_id = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);

            // Fetch users who currently have the book
            $borrowersStmt = $pdo->prepare("SELECT user_id FROM charis_book_borrowing WHERE book_id = ? AND status IN ('Picked_Up', 'Overdue')");
            $borrowersStmt->execute([$book_id]);
            $borrowers = $borrowersStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($borrowers)) {
                // PATCHED: Added the link_url so it directs them to their profile/library tab
                $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Book Request Nudge', 'Another member is eagerly waiting to read a book you currently have checked out. If you are finished, please return it to the Charis desk soon!', '/modules/profile/index.php')");
                foreach ($borrowers as $b_id) {
                    $notifStmt->execute([$b_id]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'A gentle, anonymous nudge has been sent to the current borrowers!']);
            break;

        // ==========================================
        // ACTION 5: SAVE E-BOOK READING PROGRESS
        // ==========================================
        case 'save_progress':
            require_login($user_id);
            $book_id = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);
            $page = filter_var($_POST['page'] ?? '', FILTER_VALIDATE_INT);

            if ($book_id && $page) {
                $stmt = $pdo->prepare("
                    INSERT INTO charis_ebook_progress (book_id, user_id, last_page_read) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE last_page_read = VALUES(last_page_read)
                ");
                $stmt->execute([$book_id, $user_id, $page]);
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid progress data.']);
            }
            break;

        // ==========================================
        // ACTION 6: REQUEST EXTENSION (PUBLIC FACING)
        // ==========================================
        case 'request_extension':
            require_login($user_id);
            $borrow_id = filter_var($_POST['borrow_id'] ?? '', FILTER_VALIDATE_INT);
            
            $stmt = $pdo->prepare("UPDATE charis_book_borrowing SET extension_requested = 1, extension_status = 'Pending' WHERE id = ? AND user_id = ? AND status = 'Picked_Up'");
            $stmt->execute([$borrow_id, $user_id]);
            
            if ($stmt->rowCount() > 0) {

                // NOTIFICATION TRIGGER: Alert Charis Admins
                $adminStmt = $pdo->query("
                    SELECT ud.user_id FROM user_departments ud 
                    JOIN departments d ON ud.department_id = d.id 
                    WHERE d.name LIKE '%Charis%' AND ud.role_in_dept IN ('HOD', 'Director') AND ud.is_active = 1
                ");
                $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($admins)) {
                    // Get the book title for context
                    $bStmt = $pdo->prepare("SELECT cb.title FROM charis_book_borrowing b JOIN charis_books cb ON b.book_id = cb.id WHERE b.id = ?");
                    $bStmt->execute([$borrow_id]);
                    $bTitle = $bStmt->fetchColumn() ?: 'a book';
                    
                    $notifStmt = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, 'Library Extension Request', ?, '/modules/charis/index.php')");
                    foreach($admins as $admin_id) {
                        $notifStmt->execute([$admin_id, "A member has requested a due date extension for '{$bTitle}'."]);
                    }
                }

                echo json_encode(['status' => 'success', 'message' => 'Extension requested successfully. Awaiting Charis approval.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Unable to request extension. Check book status.']);
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid API endpoint.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Charis Library API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A secure database error occurred.']);
}
?>