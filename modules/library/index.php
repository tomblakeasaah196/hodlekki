<?php
// /modules/library/index.php
// The Charis Library: public (anyone may browse, read e-books and listen),
// signed-in members also reserve physical copies, join waitlists and see
// their own borrowing. Runs on the public layout (includes/ui/public.php);
// all data comes from /api/library_api.php (request shapes unchanged).
session_start();

// We need the DB connection here specifically to fetch Open Graph data for WhatsApp embeds before the page renders.
require_once '../../includes/db.php';
require_once '../../includes/ui/public.php';

$isLoggedIn = isset($_SESSION['user_id']);
$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : null;

// Default Open Graph Meta Tags
$ogTitle = "Charis Library | Household of David";
$ogDesc = "Explore a collection of resources to build your faith, leadership, and purpose at HOD Lekki. 'Give instruction to a wise man, and he will be yet wiser.' - Proverbs 9:9";
$ogImage = "https://" . $_SERVER['HTTP_HOST'] . "/assets/images/hod_library_default.jpg";
$currentUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Dynamic Open Graph Meta Tags (If a specific book is shared)
if ($book_id) {
    try {
        $stmt = $pdo->prepare("SELECT title, author, description, cover_image_path FROM charis_books WHERE id = ?");
        $stmt->execute([$book_id]);
        $bookInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($bookInfo) {
            $ogTitle = $bookInfo['title'] . " by " . $bookInfo['author'] . " | HOD Lekki";
            // Create the beautiful WhatsApp preview description
            $baseDesc = mb_substr((string) $bookInfo['description'], 0, 90) . "... ";
            $ogDesc = $baseDesc . "Grow in grace and knowledge! Explore this powerful resource from Household of David Lekki. 'Give instruction to a wise man, and he will be yet wiser.' - Prov 9:9";
            if (!empty($bookInfo['cover_image_path'])) {
                // Ensure the image path is an absolute URL for WhatsApp to read it
                $ogImage = "https://" . $_SERVER['HTTP_HOST'] . $bookInfo['cover_image_path'];
            }
        }
    } catch (Exception $e) {
        // Silently fail to defaults if DB error occurs during header rendering
    }
}

$head = '    <meta name="description" content="' . ui_e($ogDesc) . '">' . "\n"
    . '    <meta property="og:title" content="' . ui_e($ogTitle) . '">' . "\n"
    . '    <meta property="og:description" content="' . ui_e($ogDesc) . '">' . "\n"
    . '    <meta property="og:image" content="' . ui_e($ogImage) . '">' . "\n"
    . '    <meta property="og:url" content="' . ui_e($currentUrl) . '">' . "\n"
    . '    <meta property="og:type" content="website">' . "\n"
    . '    <meta name="twitter:card" content="summary_large_image">' . "\n";

$loginUrl = '/auth/login?next=' . rawurlencode('/modules/library/' . ($book_id ? '?book_id=' . $book_id : ''));
$actions = $isLoggedIn
    ? ui_button('My library', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'book-open', 'attrs' => ['data-action' => 'library.mine', 'aria-expanded' => 'false', 'aria-controls' => 'library-mine']])
      . ui_button('My portal', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'house', 'icon_only' => true, 'href' => '/modules/member_portal/'])
    : ui_button('Sign in', ['variant' => 'primary', 'size' => 'sm', 'href' => $loginUrl]);

$page = [
    'title' => $book_id && !empty($bookInfo) ? $bookInfo['title'] . ' · Charis Library' : 'Charis Library',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/library.js'],
    // Book PDFs open in a frame from this site; audiobooks are YouTube embeds.
    'csp' => ['frame-src' => ["'self'", 'https://www.youtube-nocookie.com']],
    'head' => $head,
    'actions' => $actions,
    'indexable' => true,
];
ui_public_open($page);
?>
<div class="ui-stack">
    <section aria-labelledby="library-title">
        <h1 class="ui-h1" id="library-title">Charis Library</h1>
        <p class="ui-lead mt-1 max-w-2xl">Books, e-books and audiobooks to build your faith, leadership and purpose.</p>

        <form class="mt-5 flex max-w-2xl gap-2" role="search" data-action="library.search">
            <label class="sr-only" for="library-q">Search the library</label>
            <input class="ui-input min-w-0 flex-1" type="search" id="library-q" name="q" placeholder="Search by title or author" enterkeyhint="search" autocomplete="off">
            <?= ui_button('Search', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'search']) ?>
        </form>
        <div class="ui-chips mt-4" role="group" aria-label="Categories" data-library-categories></div>
    </section>

<?php if ($isLoggedIn): ?>
    <section class="ui-card scroll-mt-20" id="library-mine" aria-labelledby="library-mine-title" hidden>
        <div class="ui-card__header ui-card__header--divided">
            <div>
                <h2 class="ui-card__title" id="library-mine-title">My library</h2>
                <p class="ui-card__description">Books you have borrowed, are waiting for, or are reading.</p>
            </div>
        </div>
        <div class="grid gap-0 lg:grid-cols-3 lg:divide-x lg:divide-line">
            <div class="min-w-0">
                <h3 class="ui-meta px-5 pt-4">Borrowed</h3>
                <ul class="ui-list" data-library-borrowed></ul>
            </div>
            <div class="min-w-0">
                <h3 class="ui-meta px-5 pt-4">Waitlist</h3>
                <ul class="ui-list" data-library-waitlist></ul>
            </div>
            <div class="min-w-0">
                <h3 class="ui-meta px-5 pt-4">Reading and listening</h3>
                <ul class="ui-list" data-library-history></ul>
            </div>
        </div>
    </section>
<?php endif; ?>

    <section aria-label="Books">
        <div class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5" data-library-grid aria-live="polite" aria-busy="true">
<?php for ($i = 0; $i < 10; $i++): ?>
            <div><span class="ui-skeleton block aspect-[2/3] w-full rounded-ui-lg"></span><span class="ui-skeleton ui-skeleton--text mt-2 w-3/4"></span></div>
<?php endfor; ?>
        </div>
        <div data-library-empty hidden>
            <?= ui_empty(['icon' => 'book-open', 'title' => 'No books found', 'text' => 'Try another title or author, or choose All.']) ?>
        </div>
    </section>
</div>

<?= ui_dialog_start('library-book', ['title' => 'Book', 'description' => ' ', 'size' => 'lg']) ?>
    <div class="grid gap-6 sm:grid-cols-[176px_minmax(0,1fr)]">
        <div class="mx-auto w-32 sm:w-auto">
            <span class="relative block aspect-[2/3] overflow-hidden rounded-ui-lg bg-surface-sunken ring-1 ring-line" data-library-cover></span>
        </div>
        <div class="min-w-0">
            <p class="ui-meta" data-library-meta></p>
            <div class="mt-2 flex flex-wrap gap-1.5" data-library-badges></div>
            <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-ink-muted" data-library-synopsis></p>
            <div class="mt-4 overflow-hidden rounded-ui-lg bg-surface-sunken" data-library-player hidden></div>
            <div class="ui-stack ui-stack--sm mt-6" data-library-actions></div>
        </div>
    </div>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('library-reader', ['title' => 'Reader', 'description' => ' ', 'class' => 'ui-dialog--full']) ?>
    <iframe class="block h-full w-full border-0 bg-white" title="Book reader" data-library-frame></iframe>
<?= ui_dialog_end() ?>

<?= ui_json_script('library-config', ['loggedIn' => $isLoggedIn, 'loginUrl' => $loginUrl]) ?>
<?php ui_public_close(); ?>
