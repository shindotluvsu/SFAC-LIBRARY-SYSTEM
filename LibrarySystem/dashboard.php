<?php
session_start();

if (!isset($_SESSION['library_user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['library_user'];
$isAdmin = ($user['role'] ?? '') === 'Administrator';
$accounts = [];
if ($isAdmin) {
    $accounts = [
        'admin' => ['name' => 'System Administrator', 'role' => 'Administrator'],
        'student' => ['name' => 'Student User', 'role' => 'Student'],
    ] + ($_SESSION['registered_users'] ?? []);
}

if (!isset($_SESSION['library_books'])) {
    $_SESSION['library_books'] = [
        'book-1' => ['title' => 'The Silent Patient', 'author' => 'Alex Michaelides', 'available' => true, 'borrower' => null],
        'book-2' => ['title' => 'Atomic Habits', 'author' => 'James Clear', 'available' => true, 'borrower' => null],
        'book-3' => ['title' => 'Pride and Prejudice', 'author' => 'Jane Austen', 'available' => true, 'borrower' => null],
    ];
}

$books = &$_SESSION['library_books'];
if (!isset($_SESSION['library_reviews'])) {
    $_SESSION['library_reviews'] = [];
}
$reviews = &$_SESSION['library_reviews'];
$actionMessage = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $redirectSection = 'books';
    if ($action === 'delete_account' && $isAdmin) {
        $redirectSection = 'accounts';
    } elseif ($action === 'leave_review' && !$isAdmin) {
        $redirectSection = 'review';
    }
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!isset($_SESSION['dashboard_csrf_token']) || !hash_equals($_SESSION['dashboard_csrf_token'], $csrfToken)) {
        $_SESSION['dashboard_flash'] = ['error', 'Your session check failed. Please try again.'];
    } else {
        if ($action === 'add_book' && ($user['role'] ?? '') === 'Administrator') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $author = trim((string) ($_POST['author'] ?? ''));

            if ($title === '' || $author === '') {
                $_SESSION['dashboard_flash'] = ['error', 'Enter both a book title and author.'];
            } elseif (strlen($title) > 120 || strlen($author) > 100) {
                $_SESSION['dashboard_flash'] = ['error', 'The title must be 120 characters or fewer and the author 100 characters or fewer.'];
            } else {
                $bookId = 'book-' . bin2hex(random_bytes(8));
                $books[$bookId] = [
                    'title' => $title,
                    'author' => $author,
                    'available' => true,
                    'borrower' => null,
                ];
                $_SESSION['dashboard_flash'] = ['success', 'Book added to the catalog.'];
            }
        } elseif ($action === 'remove_book' && $isAdmin) {
            $bookId = (string) ($_POST['book_id'] ?? '');

            if (!isset($books[$bookId])) {
                $_SESSION['dashboard_flash'] = ['error', 'That book could not be found.'];
            } elseif (!$books[$bookId]['available']) {
                $_SESSION['dashboard_flash'] = ['error', 'Return the book before removing it from the catalog.'];
            } else {
                unset($books[$bookId]);
                $_SESSION['dashboard_flash'] = ['success', 'Book removed from the catalog.'];
            }
        } elseif ($action === 'delete_account' && $isAdmin) {
            $username = (string) ($_POST['username'] ?? '');

            if (!isset($_SESSION['registered_users'][$username])) {
                $_SESSION['dashboard_flash'] = ['error', 'That registered account could not be found.'];
            } else {
                unset($_SESSION['registered_users'][$username]);
                $_SESSION['dashboard_flash'] = ['success', 'Account deleted.'];
            }
        } elseif ($action === 'leave_review' && ($user['role'] ?? '') === 'Student') {
            $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
            $reviewText = trim((string) ($_POST['review'] ?? ''));

            if ($rating === false || $rating < 1 || $rating > 5) {
                $_SESSION['dashboard_flash'] = ['error', 'Choose a rating from 1 to 5.'];
            } elseif ($reviewText === '') {
                $_SESSION['dashboard_flash'] = ['error', 'Enter your review before submitting.'];
            } elseif (preg_match('//u', $reviewText) !== 1 || preg_match_all('/./us', $reviewText) > 1000) {
                $_SESSION['dashboard_flash'] = ['error', 'Your review must be 1000 characters or fewer.'];
            } else {
                $reviews[] = [
                    'name' => (string) ($user['name'] ?? 'Student'),
                    'rating' => $rating,
                    'text' => $reviewText,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                $_SESSION['dashboard_flash'] = ['success', 'Thank you. Your review has been submitted.'];
            }
        } elseif ($action === 'borrow_book' && ($user['role'] ?? '') === 'Student') {
            $bookId = (string) ($_POST['book_id'] ?? '');

            if (!isset($books[$bookId]) || !$books[$bookId]['available']) {
                $_SESSION['dashboard_flash'] = ['error', 'That book is not available to borrow.'];
            } else {
                $books[$bookId]['available'] = false;
                $books[$bookId]['borrower'] = $user['email'];
                $books[$bookId]['due_date'] = date('Y-m-d', strtotime('+7 days'));
                $_SESSION['dashboard_flash'] = ['success', 'Book borrowed successfully.'];
            }
        } elseif ($action === 'return_book' && ($user['role'] ?? '') === 'Student') {
            $bookId = (string) ($_POST['book_id'] ?? '');

            if (!isset($books[$bookId]) || $books[$bookId]['available']) {
                $_SESSION['dashboard_flash'] = ['error', 'That book is not currently borrowed.'];
            } elseif (
                $books[$bookId]['borrower'] !== ($user['email'] ?? '') &&
                $books[$bookId]['borrower'] !== ($user['name'] ?? '')
            ) {
                $_SESSION['dashboard_flash'] = ['error', 'You can only return a book borrowed by your account.'];
            } else {
                $books[$bookId]['available'] = true;
                $books[$bookId]['borrower'] = null;
                $books[$bookId]['due_date'] = null;
                $_SESSION['dashboard_flash'] = ['success', 'Book returned successfully.'];
            }
        } else {
            http_response_code(403);
            exit('You do not have permission to perform this book action.');
        }
    }

    $_SESSION['dashboard_flash_section'] = $redirectSection;
    header('Location: dashboard.php#' . $redirectSection);
    exit;
}

if (isset($_SESSION['dashboard_csrf_token']) === false) {
    $_SESSION['dashboard_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['dashboard_csrf_token'];
$flashSection = '';

if (isset($_SESSION['dashboard_flash'])) {
    [$flashType, $flashMessage] = $_SESSION['dashboard_flash'];
    $flashSection = (string) ($_SESSION['dashboard_flash_section'] ?? '');
    if ($flashType === 'success') {
        $actionMessage = $flashMessage;
    } else {
        $actionError = $flashMessage;
    }
    unset($_SESSION['dashboard_flash']);
    unset($_SESSION['dashboard_flash_section']);
}

$availableBooks = 0;
$borrowedBooks = 0;
$myBorrowedBooks = [];
$borrowedBookList = [];
foreach ($books as $bookId => $book) {
    if ($book['available']) {
        $availableBooks++;
    } else {
        $borrowedBooks++;
        $borrowedBookList[$bookId] = $book;
        if (
            $book['borrower'] === ($user['email'] ?? '') ||
            $book['borrower'] === ($user['name'] ?? '')
        ) {
            $myBorrowedBooks[$bookId] = $book;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SFAC Library System Dashboard</title>
    <style>
        :root {
            --bg: #faf7f7;
            --sidebar: #ffffff;
            --panel: #ffffff;
            --panel-soft: #fff5f5;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #eadcdd;
            --primary: #a34349;
            --primary-soft: #f7eded;
            --success: #0f9f6e;
            --warning: #d97706;
            --danger: #dc2626;
        }

        * { box-sizing: border-box; }
        [hidden] { display: none !important; }

        body {
            margin: 0;
            background: var(--bg);
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
        }

        .app {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            background: var(--sidebar);
            border-right: 1px solid var(--line);
            padding: 26px 18px;
        }

        .brand {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 30px;
            padding: 6px 8px;
        }

        .nav {
            display: grid;
            gap: 8px;
        }

        .nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 10px;
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
        }

        .nav a.active {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .content-area {
            flex: 1;
            padding: 28px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 26px;
        }

        .page-title {
            font-size: 2rem;
            margin: 0;
        }

        .user-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 8px 12px;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700;
        }

        .meta {
            display: grid;
            gap: 2px;
        }

        .meta strong {
            font-size: 0.92rem;
        }

        .meta small {
            color: var(--muted);
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 600;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--text);
        }

        .button.primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 20px;
        }

        .label {
            color: var(--muted);
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 16px;
            display: block;
        }

        .value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .note {
            color: var(--muted);
            font-size: 0.8rem;
        }

        .student-tools {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .student-tools form {
            display: grid;
            gap: 12px;
        }

        .student-tools label {
            display: grid;
            gap: 6px;
            color: var(--muted);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .student-tools input,
        .student-tools select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            font: inherit;
        }

        .student-tools button {
            padding: 10px 14px;
            border: 0;
            border-radius: 8px;
            background: var(--primary);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .notice {
            margin-top: 16px;
            padding: 11px 14px;
            border-radius: 8px;
        }

        .notice.success {
            background: #ecfdf5;
            color: #047857;
        }

        .notice.error {
            background: #fef2f2;
            color: #b91c1c;
        }

        .catalog {
            margin-top: 20px;
        }

        .books-section {
            display: grid;
            gap: 16px;
            margin-top: 20px;
        }

        .books-section > h2 {
            margin: 0;
        }

        .books-section .catalog,
        .books-section .student-tools {
            margin-top: 0;
        }

        .catalog-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .catalog-list li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-top: 1px solid var(--line);
        }

        .empty-catalog {
            margin: 0;
            color: var(--muted);
        }

        .review-section {
            margin-top: 20px;
        }

        .review-form {
            display: grid;
            gap: 14px;
        }

        .review-form label {
            display: grid;
            gap: 6px;
            color: var(--muted);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .review-form select,
        .review-form textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--text);
            font: inherit;
        }

        .review-form textarea {
            min-height: 120px;
            resize: vertical;
        }

        .review-form button {
            justify-self: start;
            border: 0;
            border-radius: 8px;
            padding: 11px 14px;
            background: var(--primary);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .review-list {
            display: grid;
            gap: 12px;
            margin: 20px 0 0;
            padding: 0;
            list-style: none;
        }

        .review-list li {
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--panel-soft);
        }

        .review-meta {
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 0.85rem;
        }

        @media (max-width: 760px) {
            .student-tools {
                grid-template-columns: 1fr;
            }
        }

        .main-grid {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 20px;
        }

        h2 {
            margin: 0 0 16px;
            font-size: 1.15rem;
        }

        .list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 12px;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .book-name {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .book-meta,
        .activity-time {
            color: var(--muted);
            font-size: 0.8rem;
        }

        .status {
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status.available {
            background: rgba(15, 159, 110, 0.12);
            color: var(--success);
        }

        .status.borrowed {
            background: rgba(217, 119, 6, 0.12);
            color: var(--warning);
        }

        .status.overdue {
            background: rgba(220, 38, 38, 0.12);
            color: var(--danger);
        }

        .activity-item {
            display: block;
            padding: 14px 16px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .activity-item strong {
            display: block;
            margin-bottom: 6px;
        }

        .quick-actions {
            display: grid;
            gap: 10px;
            margin-top: 18px;
        }

        .quick-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            background: var(--primary-soft);
            color: var(--primary);
            border: 1px solid #dbe7ff;
            border-radius: 10px;
            padding: 11px 12px;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .app {
                display: block;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--line);
            }

            .stats,
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        body {
            background: #faf7f7;
        }

        .sidebar {
            display: block;
            flex: 0 0 240px;
        }

        .main-grid {
            display: none;
        }

        .app {
            display: flex;
        }

        .content-area {
            flex: 1;
            min-width: 0;
            width: auto;
            margin: 0 auto;
            padding: 28px 20px;
        }

        .topbar {
            background: #ffffff;
            border: 1px solid #eadcdd;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 1.4rem;
        }

        .user-box {
            background: transparent;
            border: 0;
            padding: 0;
        }

        .stats {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .card {
            border-radius: 12px;
            padding: 18px;
        }

        .label {
            margin-bottom: 10px;
        }

        .value {
            font-size: 1.8rem;
            margin-bottom: 4px;
        }

        @media (max-width: 760px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 18px 14px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        .admin-theme {
            background: #faf7f7;
        }

        .admin-theme .topbar {
            background: #ffffff;
            border-color: #eadcdd;
            color: #1f2937;
            padding: 22px 24px;
        }

        .admin-theme .page-title {
            font-size: 1.6rem;
        }

        .admin-theme .user-box {
            color: #1f2937;
        }

        .admin-theme .meta small {
            color: #6b7280;
        }

        .admin-theme .avatar {
            background: #f7eded;
            color: #a34349;
        }

        .admin-theme .button {
            border-color: #a34349;
            background: #a34349;
            color: #ffffff;
        }

        .admin-intro {
            margin: 0 0 18px;
            color: var(--muted);
        }

        .admin-layout {
            display: grid;
            grid-template-columns: minmax(240px, 0.8fr) minmax(0, 1.4fr);
            gap: 18px;
        }

        .admin-layout form {
            display: grid;
            gap: 12px;
        }

        .admin-layout label {
            display: grid;
            gap: 6px;
            color: var(--muted);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .admin-layout input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            font: inherit;
        }

        .admin-layout button {
            border: 0;
            border-radius: 8px;
            padding: 11px 14px;
            background: #a34349;
            color: #ffffff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .admin-catalog {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .admin-catalog th,
        .admin-catalog td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--line);
        }

        .admin-catalog th {
            color: var(--muted);
            font-size: 0.76rem;
            text-transform: uppercase;
        }

        .admin-catalog form {
            margin: 0;
        }

        .admin-catalog .remove-button {
            border: 1px solid #fecaca;
            border-radius: 7px;
            padding: 7px 10px;
            background: #fff;
            color: #b91c1c;
            font: inherit;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }

        .accounts-section {
            margin-top: 20px;
        }

        .accounts-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .accounts-table th,
        .accounts-table td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--line);
        }

        .accounts-table th {
            color: var(--muted);
            font-size: 0.76rem;
            text-transform: uppercase;
        }

        .accounts-table form {
            margin: 0;
        }

        .accounts-table .delete-button {
            border: 1px solid #eadcdd;
            border-radius: 7px;
            padding: 7px 10px;
            background: #fff;
            color: var(--primary);
            font: inherit;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }

        @media (max-width: 760px) {
            .admin-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .app {
                display: block;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--line);
            }
        }
    </style>
</head>
<body class="<?php echo $isAdmin ? 'admin-theme' : 'student-theme'; ?>">
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <span>SFAC</span>
            </div>

            <nav class="nav">
                <a href="#dashboard" class="active" data-view="dashboard" aria-current="page">Dashboard</a>
                <a href="#books" data-view="books">Books</a>
                <?php if ($isAdmin): ?>
                    <a href="#accounts" data-view="accounts">Accounts</a>
                    <a href="#borrowed" data-view="borrowed">Borrowed</a>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <a href="#">Reports</a>
                <?php else: ?>
                    <a href="#review" data-view="review">Leave a review</a>
                <?php endif; ?>
            </nav>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <h1 class="page-title"><?php echo $isAdmin ? 'Administration' : 'Student Dashboard'; ?></h1>

                <div class="user-box">
                    <div class="avatar"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                    <div class="meta">
                        <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                        <small><?php echo htmlspecialchars($user['role']); ?></small>
                    </div>
                    <a class="button" href="index.php?logout=1">Log Out</a>
                </div>
            </header>

            <?php if ($flashSection !== 'review' && $actionMessage !== ''): ?>
                <div class="notice success" role="status"><?php echo htmlspecialchars($actionMessage); ?></div>
            <?php elseif ($flashSection !== 'review' && $actionError !== ''): ?>
                <div class="notice error" role="alert"><?php echo htmlspecialchars($actionError); ?></div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <p class="admin-intro dashboard-overview">Manage the library catalog and monitor book availability.</p>
            <?php endif; ?>

            <section class="stats dashboard-overview" id="dashboard">
                <?php if ($isAdmin): ?>
                    <div class="card">
                        <span class="label">Total Books</span>
                        <div class="value"><?php echo count($books); ?></div>
                        <div class="note">Catalog records</div>
                    </div>
                    <div class="card">
                        <span class="label">Available</span>
                        <div class="value"><?php echo $availableBooks; ?></div>
                        <div class="note">Ready to borrow</div>
                    </div>
                    <div class="card">
                        <span class="label">Borrowed</span>
                        <div class="value"><?php echo $borrowedBooks; ?></div>
                        <div class="note">Currently checked out</div>
                    </div>
                    <div class="card">
                        <span class="label">My Account</span>
                        <div class="value" style="font-size: 1.2rem;">Administrator</div>
                        <div class="note">Catalog management access</div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <span class="label">Available Books</span>
                        <div class="value"><?php echo $availableBooks; ?></div>
                        <div class="note">Ready to borrow</div>
                    </div>
                    <div class="card">
                        <span class="label">My Borrowed Books</span>
                        <div class="value"><?php echo count($myBorrowedBooks); ?></div>
                        <div class="note">Currently checked out to you</div>
                    </div>
                    <div class="card">
                        <span class="label">Total Catalog</span>
                        <div class="value"><?php echo count($books); ?></div>
                        <div class="note">Books in the library</div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="books-section" id="books" aria-labelledby="books-heading">
                <h2 id="books-heading">Books</h2>
                <section class="card catalog" aria-labelledby="available-books-heading">
                    <h2 id="available-books-heading">Available Books</h2>
                    <?php if ($availableBooks > 0): ?>
                        <ul class="catalog-list">
                            <?php foreach ($books as $book): ?>
                                <?php if ($book['available']): ?>
                                    <li>
                                        <span><strong><?php echo htmlspecialchars($book['title']); ?></strong> — <?php echo htmlspecialchars($book['author']); ?></span>
                                        <span>Available</span>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="empty-catalog">There are no books available right now.</p>
                    <?php endif; ?>
                </section>

                <?php if (($user['role'] ?? '') === 'Student'): ?>
                    <section class="student-tools" aria-label="Student book options">
                        <div class="card">
                            <h2>Borrow a Book</h2>
                            <form method="post" action="dashboard.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="borrow_book">
                                <label for="book-id">Available books
                                    <select
                                        id="book-id"
                                        name="book_id"
                                        required
                                        <?php echo $availableBooks === 0 ? 'disabled' : ''; ?>
                                    >
                                        <option value="">Select a book</option>
                                        <?php foreach ($books as $bookId => $book): ?>
                                            <?php if ($book['available']): ?>
                                                <option value="<?php echo htmlspecialchars($bookId); ?>">
                                                    <?php echo htmlspecialchars($book['title'] . ' — ' . $book['author']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button
                                    type="submit"
                                    <?php echo $availableBooks === 0 ? 'disabled' : ''; ?>
                                >
                                    Borrow Book
                                </button>
                            </form>
                        </div>

                        <div class="card">
                            <h2>Return a Book</h2>
                            <form method="post" action="dashboard.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="return_book">
                                <label for="return-book-id">Your borrowed books
                                    <select
                                        id="return-book-id"
                                        name="book_id"
                                        required
                                        <?php echo count($myBorrowedBooks) === 0 ? 'disabled' : ''; ?>
                                    >
                                        <option value="">Select a book</option>
                                        <?php foreach ($myBorrowedBooks as $bookId => $book): ?>
                                            <option value="<?php echo htmlspecialchars($bookId); ?>">
                                                <?php echo htmlspecialchars($book['title'] . ' — ' . $book['author']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button
                                    type="submit"
                                    <?php echo count($myBorrowedBooks) === 0 ? 'disabled' : ''; ?>
                                >
                                    Return Book
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <section class="admin-layout" aria-label="Administrator catalog management">
                        <div class="card">
                            <h2>Add a Book</h2>
                            <form method="post" action="dashboard.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="add_book">
                                <label for="admin-book-title">Title
                                    <input id="admin-book-title" name="title" type="text" maxlength="120" required>
                                </label>
                                <label for="admin-book-author">Author
                                    <input id="admin-book-author" name="author" type="text" maxlength="100" required>
                                </label>
                                <button type="submit">Add Book</button>
                            </form>
                        </div>

                        <div class="card">
                            <h2>Catalog Management</h2>
                            <table class="admin-catalog">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($books as $bookId => $book): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($book['title']); ?></td>
                                            <td><?php echo htmlspecialchars($book['author']); ?></td>
                                            <td><?php echo $book['available'] ? 'Available' : 'Borrowed'; ?></td>
                                            <td>
                                                <?php if ($book['available']): ?>
                                                    <form
                                                        method="post"
                                                        action="dashboard.php"
                                                        onsubmit="return confirm('Remove this book from the catalog?');"
                                                    >
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="remove_book">
                                                        <input type="hidden" name="book_id" value="<?php echo htmlspecialchars($bookId); ?>">
                                                        <button class="remove-button" type="submit">Remove</button>
                                                    </form>
                                                <?php else: ?>
                                                    Return first
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

            </section>

            <?php if (!$isAdmin): ?>
                <section class="card review-section" id="review" aria-labelledby="review-heading">
                    <h2 id="review-heading">Leave a review</h2>
                    <?php if ($flashSection === 'review' && $actionMessage !== ''): ?>
                        <div class="notice success" role="status"><?php echo htmlspecialchars($actionMessage); ?></div>
                    <?php elseif ($flashSection === 'review' && $actionError !== ''): ?>
                        <div class="notice error" role="alert"><?php echo htmlspecialchars($actionError); ?></div>
                    <?php endif; ?>
                    <form class="review-form" method="post" action="dashboard.php#review">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="leave_review">
                        <label for="review-rating">Rating
                            <select id="review-rating" name="rating" required>
                                <option value="">Choose a rating</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Very good</option>
                                <option value="3">3 - Good</option>
                                <option value="2">2 - Fair</option>
                                <option value="1">1 - Poor</option>
                            </select>
                        </label>
                        <label for="review-text">Your review
                            <textarea
                                id="review-text"
                                name="review"
                                maxlength="1000"
                                required
                                placeholder="Share your feedback about the library..."
                            ></textarea>
                        </label>
                        <button type="submit">Submit review</button>
                    </form>

                    <?php if ($reviews !== []): ?>
                        <ul class="review-list" aria-label="Submitted reviews">
                            <?php foreach (array_reverse($reviews) as $review): ?>
                                <li>
                                    <div class="review-meta">
                                        <strong><?php echo htmlspecialchars($review['name']); ?></strong>
                                        <span> · <?php echo htmlspecialchars((string) $review['rating']); ?>/5 ·
                                            <?php echo htmlspecialchars(date('F j, Y', strtotime($review['created_at']))); ?>
                                        </span>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($review['text'])); ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <section class="card accounts-section" id="accounts" aria-labelledby="accounts-heading">
                    <h2 id="accounts-heading">Existing Accounts</h2>
                    <table class="accounts-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $username => $account): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars((string) $username); ?></td>
                                    <td><?php echo htmlspecialchars($account['role'] ?? ''); ?></td>
                                    <td>
                                        <?php if (isset($_SESSION['registered_users'][$username])): ?>
                                            <form method="post" action="dashboard.php#accounts" onsubmit="return confirm('Delete this account? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="delete_account">
                                                <input type="hidden" name="username" value="<?php echo htmlspecialchars((string) $username); ?>">
                                                <button class="delete-button" type="submit">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            Protected
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="card accounts-section" id="borrowed" aria-labelledby="borrowed-heading">
                    <h2 id="borrowed-heading">Borrowed Books</h2>
                    <?php if ($borrowedBookList !== []): ?>
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>Book</th>
                                    <th>Author</th>
                                    <th>Borrowed By</th>
                                    <th>Expiration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($borrowedBookList as $book): ?>
                                    <?php
                                    $borrower = (string) ($book['borrower'] ?? '');
                                    $borrowerName = $accounts[$borrower]['name'] ?? $borrower;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                                        <td><?php echo htmlspecialchars($book['author']); ?></td>
                                        <td><?php echo htmlspecialchars($borrowerName !== '' ? $borrowerName : 'Unknown account'); ?></td>
                                        <td><?php echo !empty($book['due_date']) ? htmlspecialchars(date('F j, Y', strtotime($book['due_date']))) : 'Not recorded'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="empty-catalog">No books are currently borrowed.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="main-grid dashboard-overview">
                <div class="card">
                    <h2>Latest Book Status</h2>
                    <ul class="list">
                        <li class="list-item">
                            <div>
                                <div class="book-name">The Silent Patient</div>
                                <div class="book-meta">Borrowed by A. Brown • Due in 2 days</div>
                            </div>
                            <span class="status available">Available</span>
                        </li>
                        <li class="list-item">
                            <div>
                                <div class="book-name">Atomic Habits</div>
                                <div class="book-meta">Borrowed by J. Davis • Due today</div>
                            </div>
                            <span class="status borrowed">Borrowed</span>
                        </li>
                        <li class="list-item">
                            <div>
                                <div class="book-name">Pride and Prejudice</div>
                                <div class="book-meta">Borrowed by C. Lee • 4 days overdue</div>
                            </div>
                            <span class="status overdue">Overdue</span>
                        </li>
                    </ul>
                </div>

                <div class="card">
                    <h2>Quick Actions</h2>
                    <div class="quick-actions">
                        <a href="#">Add New Book</a>
                        <a href="#">Register Member</a>
                        <a href="#">Issue Book</a>
                        <a href="#">View Reports</a>
                    </div>

                    <h2 style="margin-top: 24px;">Recent Activity</h2>
                    <ul class="list" style="gap: 10px;">
                        <li class="activity-item">
                            <strong>New member registered</strong>
                            <span class="activity-time">Today • Maria T.</span>
                        </li>
                        <li class="activity-item">
                            <strong>Book returned</strong>
                            <span class="activity-time">Today • Clean Code</span>
                        </li>
                        <li class="activity-item">
                            <strong>Reservation confirmed</strong>
                            <span class="activity-time">Yesterday • To Kill a Mockingbird</span>
                        </li>
                    </ul>
                </div>
            </section>
        </main>
    </div>
    <script>
        const booksSection = document.getElementById('books');
        const reviewSection = document.getElementById('review');
        const accountsSection = document.getElementById('accounts');
        const borrowedSection = document.getElementById('borrowed');
        const overviewSections = document.querySelectorAll('.dashboard-overview');
        const viewLinks = document.querySelectorAll('.nav [data-view]');
        const pageTitle = document.querySelector('.page-title');
        const dashboardTitle = <?php echo json_encode($isAdmin ? 'Administration' : 'Student Dashboard'); ?>;
        const canViewAccounts = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const canViewReviews = <?php echo $isAdmin ? 'false' : 'true'; ?>;

        function showCurrentView() {
            let currentView = window.location.hash.slice(1);
            if (
                currentView !== 'books' &&
                !(currentView === 'review' && canViewReviews) &&
                !(currentView === 'accounts' && canViewAccounts) &&
                !(currentView === 'borrowed' && canViewAccounts)
            ) {
                currentView = 'dashboard';
            }
            booksSection.hidden = currentView !== 'books';
            if (reviewSection) {
                reviewSection.hidden = currentView !== 'review';
            }
            if (accountsSection) {
                accountsSection.hidden = currentView !== 'accounts';
            }
            if (borrowedSection) {
                borrowedSection.hidden = currentView !== 'borrowed';
            }
            overviewSections.forEach((section) => {
                section.hidden = currentView !== 'dashboard';
            });
            pageTitle.textContent = currentView === 'dashboard'
                ? dashboardTitle
                : currentView.charAt(0).toUpperCase() + currentView.slice(1);
            viewLinks.forEach((link) => {
                const isActive = link.dataset.view === currentView;
                link.classList.toggle('active', isActive);
                if (isActive) {
                    link.setAttribute('aria-current', 'page');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        }

        window.addEventListener('hashchange', showCurrentView);
        showCurrentView();
    </script>
</body>
</html>
