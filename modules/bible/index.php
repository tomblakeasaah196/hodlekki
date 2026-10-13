<?php
// /modules/bible/index.php
require_once '../../includes/header.php';
require_once '../../includes/bible/bootstrap.php';

// The Bible — open to every signed-in member. Reading, exact-word
// highlights, notes, a study journal, reading plans and a voice reader.
// Everything a member writes is private to them. Member guide:
// modules/bible/how_to_use.md.

if (empty($_SESSION['bible_csrf']) || !is_string($_SESSION['bible_csrf'])) {
    $_SESSION['bible_csrf'] = bin2hex(random_bytes(32));
}

$bibleUserId = (int) $_SESSION['user_id'];
$bibleBoot = bible_boot_payload($pdo, $bibleUserId);

// Paint the first chapter without a round trip when it is local (KJV).
$bibleFirst = null;
$bs = $bibleBoot['state'];
if (bible_is_local($bs['translation'])) {
    $res = bible_chapter_for_user($pdo, $bibleUserId, $bs['translation'], $bs['book'], $bs['chapter']);
    $bibleFirst = $res['ok'] ? $res['data'] : null;
}

$bibleAsset = static function (string $path): string {
    $file = __DIR__ . '/../../' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';

    return htmlspecialchars($path . '?v=' . $v, ENT_QUOTES, 'UTF-8');
};

$bibleConfig = [
    'csrf'      => $_SESSION['bible_csrf'],
    'firstName' => (string) ($_SESSION['first_name'] ?? ''),
    'fumsUrl'   => bible_env('BIBLE_FUMS_URL', 'https://fums.api.bible/f3'),
    'boot'      => $bibleBoot,
    'first'     => $bibleFirst,
    'logo'      => '/assets/images/logo_hod.png',
];

$icon = static fn(string $d, string $cls = 'w-5 h-5'): string =>
    '<svg class="' . $cls . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';

const BB_I_CHEVRON = 'M6 9l6 6 6-6';
const BB_I_LEFT    = 'M15 18l-6-6 6-6';
const BB_I_RIGHT   = 'M9 18l6-6-6-6';
const BB_I_PHONES  = 'M3 18v-6a9 9 0 0118 0v6M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3zM3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3z';
const BB_I_CLOSE   = 'M18 6L6 18M6 6l12 12';
const BB_I_FLAME   = 'M12 22c4.4 0 7-2.9 7-6.6 0-3.8-2.6-6.1-4.3-8.4-.5 2-1.6 3.2-2.7 3.6.3-3-1.1-6.2-3.8-8.6-.2 3.4-2.3 5.6-3.7 7.6C3.2 11.9 5 22 12 22z';
const BB_I_SEARCH  = 'M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z';
const BB_I_CHECK   = 'M20 6L9 17l-5-5';
const BB_I_NOTE    = 'M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z';
const BB_I_COPY    = 'M8 8h11a1 1 0 011 1v11a1 1 0 01-1 1H9a1 1 0 01-1-1V8zM16 8V5a1 1 0 00-1-1H5a1 1 0 00-1 1v10a1 1 0 001 1h3';
const BB_I_SHARE   = 'M4 12v7a2 2 0 002 2h12a2 2 0 002-2v-7M16 6l-4-4-4 4M12 2v13';
const BB_I_ERASE   = 'M20 20H9L4 15a2 2 0 010-2.8L13.2 3a2 2 0 012.8 0l5 5a2 2 0 010 2.8L13 19M9 10l6 6';
const BB_I_PLAY    = 'M7 4v16l13-8L7 4z';
const BB_I_PREV    = 'M19 20L9 12l10-8v16zM5 19V5';
const BB_I_NEXT    = 'M5 4l10 8-10 8V4zM19 5v14';
const BB_I_MOON    = 'M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z';
const BB_I_GEAR    = 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z';
const BB_I_DOWN    = 'M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3';
const BB_I_PLUS    = 'M12 5v14M5 12h14';
const BB_I_BOOK    = 'M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5V5a2 2 0 012-2h14v18H6.5A2.5 2.5 0 014 19.5z';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,500;0,7..72,600;1,7..72,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $bibleAsset('/assets/css/bible.css') ?>">

<div id="bibleApp" class="bible-app" data-view="read">

    <!-- ============================== TOP BAR ============================== -->
    <div class="bb-topbar" id="bbTopbar">
        <div class="bb-topbar-inner">
            <div class="bb-tb-left">
                <button type="button" class="bb-ref-btn" id="bbRefBtn" aria-label="Choose a book and chapter">
                    <?= $icon(BB_I_BOOK, 'w-4 h-4 bb-ref-icon') ?>
                    <span id="bbRefLabel">John 1</span>
                    <?= $icon(BB_I_CHEVRON, 'w-4 h-4 opacity-60') ?>
                </button>
                <button type="button" class="bb-tr-btn" id="bbTrBtn" aria-label="Choose a translation">
                    <span id="bbTrLabel">KJV</span>
                </button>
            </div>

            <nav class="bb-tabs" role="tablist" aria-label="The Bible">
                <button type="button" role="tab" data-tab="read" class="is-active">Read</button>
                <button type="button" role="tab" data-tab="search">Search</button>
                <button type="button" role="tab" data-tab="notes">Notes</button>
                <button type="button" role="tab" data-tab="plans">Plans</button>
            </nav>

            <div class="bb-tb-right">
                <button type="button" class="bb-streak" id="bbStreakChip" title="Your reading streak" hidden>
                    <?= $icon(BB_I_FLAME, 'w-4 h-4') ?><span id="bbStreakNum">0</span>
                </button>
                <button type="button" class="bb-icon-btn" id="bbListenBtn" title="Listen" aria-label="Listen to this chapter">
                    <?= $icon(BB_I_PHONES) ?>
                </button>
                <button type="button" class="bb-icon-btn bb-aa" id="bbDisplayBtn" title="Text and display" aria-label="Text and display settings">
                    <span aria-hidden="true">A<small>a</small></span>
                </button>
            </div>
        </div>
        <div class="bb-progress" aria-hidden="true"><span id="bbProgress"></span></div>
    </div>

    <!-- ============================== READ ============================== -->
    <section class="bb-view" data-view="read" id="bbViewRead">
        <div class="bb-stage">
            <div class="bb-side"><button type="button" class="bb-side-btn" id="bbPrevSide" aria-label="Previous chapter"><?= $icon(BB_I_LEFT, 'w-6 h-6') ?></button></div>

            <article class="bb-page" id="bbPage" aria-live="polite">
                <div class="bb-notice" id="bbNotice" hidden></div>
                <header class="bb-head">
                    <p class="bb-head-book" id="bbHeadBook">&nbsp;</p>
                    <p class="bb-head-tr" id="bbHeadTr">&nbsp;</p>
                </header>
                <div class="bb-text" id="bbText" lang="en">
                    <div class="bb-skeleton"><span></span><span></span><span></span><span></span><span></span></div>
                </div>
                <footer class="bb-foot" id="bbFoot" hidden>
                    <button type="button" class="bb-mark" id="bbMarkRead">
                        <?= $icon(BB_I_CHECK, 'w-5 h-5') ?><span>Mark as read</span>
                    </button>
                    <div class="bb-nextprev">
                        <button type="button" class="bb-np" id="bbPrev"><small>Previous</small><span></span></button>
                        <button type="button" class="bb-np bb-np-next" id="bbNext"><small>Next</small><span></span></button>
                    </div>
                    <p class="bb-copy" id="bbCopyright"></p>
                </footer>
                <div id="bbEnd" class="bb-end-sentinel" aria-hidden="true"></div>
            </article>

            <div class="bb-side"><button type="button" class="bb-side-btn" id="bbNextSide" aria-label="Next chapter"><?= $icon(BB_I_RIGHT, 'w-6 h-6') ?></button></div>
        </div>
    </section>

    <!-- ============================== SEARCH ============================== -->
    <section class="bb-view" data-view="search" id="bbViewSearch" hidden>
        <div class="bb-panel-wrap">
            <form class="bb-search-form" id="bbSearchForm" autocomplete="off">
                <div class="bb-search-box">
                    <?= $icon(BB_I_SEARCH, 'w-5 h-5 bb-search-ico') ?>
                    <input type="search" id="bbSearchQ" placeholder="Search words, or type a verse like John 3:16" aria-label="Search the Bible" enterkeyhint="search">
                    <select id="bbSearchTr" aria-label="Translation to search"></select>
                </div>
                <div class="bb-chips" id="bbSearchScope" role="radiogroup" aria-label="Where to search">
                    <button type="button" class="bb-chip is-on" data-scope="all">Whole Bible</button>
                    <button type="button" class="bb-chip" data-scope="ot">Old Testament</button>
                    <button type="button" class="bb-chip" data-scope="nt">New Testament</button>
                    <button type="button" class="bb-chip" data-scope="book" id="bbScopeBook">This book</button>
                </div>
                <p class="bb-hint">Tip: put words in quotes to find an exact phrase — <em>"peace be still"</em>.</p>
            </form>
            <div class="bb-search-meta" id="bbSearchMeta"></div>
            <div class="bb-chips bb-book-counts" id="bbSearchBooks"></div>
            <div class="bb-list" id="bbSearchResults"></div>
            <button type="button" class="bb-more" id="bbSearchMore" hidden>Show more</button>
        </div>
    </section>

    <!-- ============================== NOTES ============================== -->
    <section class="bb-view" data-view="notes" id="bbViewNotes" hidden>
        <div class="bb-panel-wrap">
            <div class="bb-panel-head">
                <div>
                    <h2>My study</h2>
                    <p>Your notes, journal and highlights. Only you can see them.</p>
                </div>
                <div class="bb-panel-actions">
                    <button type="button" class="bb-btn" id="bbExportBtn"><?= $icon(BB_I_DOWN, 'w-4 h-4') ?><span>PDF</span></button>
                    <button type="button" class="bb-btn bb-btn-primary" id="bbNewJournal"><?= $icon(BB_I_PLUS, 'w-4 h-4') ?><span>Journal entry</span></button>
                </div>
            </div>
            <div class="bb-seg" id="bbNotesKind" role="tablist" aria-label="Show">
                <button type="button" class="is-on" data-kind="">All</button>
                <button type="button" data-kind="verse">Verse notes</button>
                <button type="button" data-kind="journal">Journal</button>
                <button type="button" data-kind="highlights">Highlights</button>
            </div>
            <div class="bb-filters">
                <div class="bb-search-box bb-search-sm">
                    <?= $icon(BB_I_SEARCH, 'w-4 h-4 bb-search-ico') ?>
                    <input type="search" id="bbNotesQ" placeholder="Search my notes" aria-label="Search my notes" data-deeplink-search>
                </div>
                <select id="bbNotesBook" aria-label="Book"><option value="0">All books</option></select>
                <div class="bb-color-filter" id="bbHlColors" hidden></div>
            </div>
            <div class="bb-chips bb-tag-chips" id="bbTagChips"></div>
            <div class="bb-list" id="bbNotesList"></div>
            <button type="button" class="bb-more" id="bbNotesMore" hidden>Show more</button>
        </div>
    </section>

    <!-- ============================== PLANS ============================== -->
    <section class="bb-view" data-view="plans" id="bbViewPlans" hidden>
        <div class="bb-panel-wrap">
            <div class="bb-streak-card" id="bbStreakCard"></div>
            <h3 class="bb-section-title">Your plans</h3>
            <div id="bbMyPlans" class="bb-plan-list"></div>
            <h3 class="bb-section-title">Start a reading plan</h3>
            <div id="bbPlanCatalog" class="bb-plan-grid"></div>
        </div>
    </section>
</div>

<!-- ============================== SELECTION BAR (not a dialog) ============================== -->
<div id="bbSelBar" class="bb-selbar" hidden role="toolbar" aria-label="Selected text">
    <div class="bb-selbar-colors" id="bbSelColors"></div>
    <span class="bb-selbar-sep"></span>
    <button type="button" data-act="erase" title="Remove highlight" aria-label="Remove highlight"><?= $icon(BB_I_ERASE, 'w-5 h-5') ?></button>
    <button type="button" data-act="note" title="Add a note" aria-label="Add a note"><?= $icon(BB_I_NOTE, 'w-5 h-5') ?></button>
    <button type="button" data-act="copy" title="Copy" aria-label="Copy"><?= $icon(BB_I_COPY, 'w-5 h-5') ?></button>
    <button type="button" data-act="share" title="Share as image" aria-label="Share as image"><?= $icon(BB_I_SHARE, 'w-5 h-5') ?></button>
    <button type="button" data-act="listen" title="Listen from here" aria-label="Listen from here"><?= $icon(BB_I_PHONES, 'w-5 h-5') ?></button>
</div>

<!-- ============================== VOICE PLAYER (not a dialog) ============================== -->
<div id="bbPlayer" class="bb-player" hidden role="region" aria-label="Voice reader">
    <button type="button" class="bb-pl-btn" id="bbPlPrev" aria-label="Previous verse"><?= $icon(BB_I_PREV, 'w-5 h-5') ?></button>
    <button type="button" class="bb-pl-main" id="bbPlPlay" aria-label="Pause">
        <svg class="w-6 h-6 bb-ico-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16" rx="1.2"/><rect x="14" y="4" width="4" height="16" rx="1.2"/></svg>
        <svg class="w-6 h-6 bb-ico-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="<?= BB_I_PLAY ?>"/></svg>
    </button>
    <button type="button" class="bb-pl-btn" id="bbPlNext" aria-label="Next verse"><?= $icon(BB_I_NEXT, 'w-5 h-5') ?></button>
    <div class="bb-pl-info">
        <strong id="bbPlRef">—</strong>
        <span id="bbPlSub">Voice reader</span>
    </div>
    <button type="button" class="bb-pl-chip" id="bbPlRate" title="Reading speed">1.0×</button>
    <button type="button" class="bb-pl-chip" id="bbPlTimer" title="Sleep timer"><?= $icon(BB_I_MOON, 'w-4 h-4') ?><span></span></button>
    <button type="button" class="bb-pl-btn" id="bbPlSettings" aria-label="Voice settings"><?= $icon(BB_I_GEAR, 'w-5 h-5') ?></button>
    <button type="button" class="bb-pl-btn" id="bbPlClose" aria-label="Stop reading"><?= $icon(BB_I_CLOSE, 'w-5 h-5') ?></button>
</div>

<!-- ============================== DIALOGS ============================== -->
<!-- All dialogs follow the shared modal contract (assets/js/modal-manager.js):
     fixed overlays whose id contains "modal", closed only by a visible button. -->

<div id="bibleBookModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="bibleBookTitle">
    <div class="bb-sheet bb-sheet-lg" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="bibleBookTitle">Go to</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body">
            <form class="bb-goto" id="bbGotoForm" autocomplete="off">
                <input type="text" id="bbGotoInput" placeholder="Type a book or verse — e.g. Rom 8:28" aria-label="Book or reference">
                <button type="submit" class="bb-btn bb-btn-primary">Go</button>
            </form>
            <div class="bb-seg bb-seg-sm" id="bbTestament">
                <button type="button" class="is-on" data-t="OT">Old Testament</button>
                <button type="button" data-t="NT">New Testament</button>
            </div>
            <div id="bbBookGrid" class="bb-book-grid"></div>
            <div id="bbChapterPane" class="bb-chapter-pane" hidden>
                <button type="button" class="bb-back" id="bbChapterBack"><?= $icon(BB_I_LEFT, 'w-4 h-4') ?><span>All books</span></button>
                <h4 id="bbChapterBook"></h4>
                <div id="bbChapterGrid" class="bb-chapter-grid"></div>
            </div>
        </div>
    </div>
</div>

<div id="bibleTranslationModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="bibleTrTitle">
    <div class="bb-sheet" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="bibleTrTitle">Translation</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body">
            <div id="bbTrList" class="bb-tr-list"></div>
            <p class="bb-fine">NKJV, NIV and AMP are licensed through API.Bible (American Bible Society) and stay on our server for at most 29 days.</p>
            <button type="button" class="bb-link" id="bbAdminOpen" hidden>API.Bible status (admins)</button>
        </div>
    </div>
</div>

<div id="bibleDisplayModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="bibleDisplayTitle">
    <div class="bb-sheet" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="bibleDisplayTitle">Text &amp; display</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body bb-display">
            <div class="bb-themes" id="bbThemes">
                <button type="button" data-theme="paper"><span class="bb-swatch bb-sw-paper">Aa</span>Paper</button>
                <button type="button" data-theme="white"><span class="bb-swatch bb-sw-white">Aa</span>White</button>
                <button type="button" data-theme="night"><span class="bb-swatch bb-sw-night">Aa</span>Night</button>
            </div>
            <label class="bb-field">
                <span>Text size <output id="bbSizeOut">19</output></span>
                <div class="bb-range-row"><small>A</small><input type="range" id="bbSize" min="14" max="32" step="1"><big>A</big></div>
            </label>
            <div class="bb-field">
                <span>Typeface</span>
                <div class="bb-seg bb-seg-sm" id="bbFont">
                    <button type="button" data-font="serif" class="bb-f-serif">Literata</button>
                    <button type="button" data-font="classic" class="bb-f-classic">Garamond</button>
                    <button type="button" data-font="sans" class="bb-f-sans">Modern</button>
                </div>
            </div>
            <div class="bb-field">
                <span>Line spacing</span>
                <div class="bb-seg bb-seg-sm" id="bbLeading">
                    <button type="button" data-leading="1.6">Snug</button>
                    <button type="button" data-leading="1.8">Comfortable</button>
                    <button type="button" data-leading="2.1">Airy</button>
                </div>
            </div>
            <div class="bb-field">
                <span>Layout</span>
                <div class="bb-seg bb-seg-sm" id="bbLayout">
                    <button type="button" data-layout="para">Paragraphs</button>
                    <button type="button" data-layout="verse">Verse by verse</button>
                </div>
            </div>
            <label class="bb-toggle"><input type="checkbox" id="bbOptRed"><span></span>Words of Jesus in red</label>
            <label class="bb-toggle"><input type="checkbox" id="bbOptNumbers"><span></span>Verse numbers</label>
            <label class="bb-toggle"><input type="checkbox" id="bbOptHeadings"><span></span>Section headings (NKJV, NIV, AMP)</label>
        </div>
    </div>
</div>

<div id="bibleNoteModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="bibleNoteTitle">
    <div class="bb-sheet bb-sheet-lg" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="bibleNoteTitle">Note</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body bb-note-form">
            <blockquote class="bb-quote" id="bbNoteQuote" hidden></blockquote>
            <input type="text" id="bbNoteTitleInput" class="bb-input bb-input-title" placeholder="Title — e.g. Sunday: Living by faith" maxlength="160" hidden>
            <textarea id="bbNoteBody" class="bb-input bb-textarea" rows="7" placeholder="What is God saying to you here?"></textarea>
            <label class="bb-label" for="bbNoteRefs">Passages <small>separate with ;</small></label>
            <input type="text" id="bbNoteRefs" class="bb-input" placeholder="e.g. Romans 8:28; Hebrews 11:1-6">
            <label class="bb-label" for="bbNoteTagInput">Tags</label>
            <div class="bb-taginput" id="bbNoteTags">
                <input type="text" id="bbNoteTagInput" placeholder="faith, prayer…" maxlength="40" aria-label="Add a tag">
            </div>
            <div class="bb-chips bb-tag-suggest" id="bbTagSuggest"></div>
            <p class="bb-fine">Private — only you can see your notes.</p>
        </div>
        <div class="bb-sheet-foot">
            <button type="button" class="bb-btn bb-btn-danger" id="bbNoteDelete" hidden>Delete</button>
            <button type="button" class="bb-btn" id="bbNotePdf" hidden><?= $icon(BB_I_DOWN, 'w-4 h-4') ?><span>PDF</span></button>
            <span class="bb-grow"></span>
            <button type="button" class="bb-btn" data-close>Cancel</button>
            <button type="button" class="bb-btn bb-btn-primary" id="bbNoteSave">Save</button>
        </div>
    </div>
</div>

<div id="bibleShareModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="bibleShareTitle">
    <div class="bb-sheet bb-sheet-lg" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="bibleShareTitle">Share this verse</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body bb-share">
            <div class="bb-share-preview"><canvas id="bbShareCanvas" width="1080" height="1080"></canvas></div>
            <div class="bb-share-controls">
                <div class="bb-field">
                    <span>Background</span>
                    <div class="bb-share-themes" id="bbShareThemes"></div>
                </div>
                <div class="bb-field">
                    <span>Shape</span>
                    <div class="bb-seg bb-seg-sm" id="bbShareFormat">
                        <button type="button" data-format="square" class="is-on">Square</button>
                        <button type="button" data-format="story">Status / Story</button>
                    </div>
                </div>
                <label class="bb-toggle"><input type="checkbox" id="bbShareBrand" checked><span></span>Show HOD Lekki name</label>
            </div>
        </div>
        <div class="bb-sheet-foot">
            <span class="bb-grow"></span>
            <button type="button" class="bb-btn" id="bbShareDownload"><?= $icon(BB_I_DOWN, 'w-4 h-4') ?><span>Download</span></button>
            <button type="button" class="bb-btn bb-btn-primary" id="bbShareGo"><?= $icon(BB_I_SHARE, 'w-4 h-4') ?><span>Share</span></button>
        </div>
    </div>
</div>

<div id="bibleVoiceModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="bibleVoiceTitle">
    <div class="bb-sheet" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="bibleVoiceTitle">Voice reader</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body bb-voice">
            <p class="bb-fine" id="bbVoiceSupport"></p>
            <label class="bb-field">
                <span>Voice</span>
                <div class="bb-row">
                    <select id="bbVoiceSelect" class="bb-input"></select>
                    <button type="button" class="bb-btn" id="bbVoiceTest">Try</button>
                </div>
            </label>
            <label class="bb-field">
                <span>Speed <output id="bbRateOut">1.0×</output></span>
                <input type="range" id="bbVoiceRate" min="0.6" max="1.6" step="0.05">
            </label>
            <label class="bb-toggle"><input type="checkbox" id="bbVoiceContinue"><span></span>Carry on into the next chapter</label>
            <div class="bb-field">
                <span>Sleep timer</span>
                <div class="bb-seg bb-seg-sm bb-seg-wrap" id="bbSleep">
                    <button type="button" data-sleep="0" class="is-on">Off</button>
                    <button type="button" data-sleep="chapter">End of chapter</button>
                    <button type="button" data-sleep="15">15 min</button>
                    <button type="button" data-sleep="30">30 min</button>
                    <button type="button" data-sleep="60">1 hour</button>
                </div>
            </div>
            <div class="bb-memorise">
                <h4>Repeat to memorise</h4>
                <p class="bb-fine">Listen to a verse or passage again and again, with a short pause between.</p>
                <div class="bb-mem-grid">
                    <label>From verse <select id="bbMemFrom" class="bb-input"></select></label>
                    <label>To verse <select id="bbMemTo" class="bb-input"></select></label>
                    <label>Times
                        <select id="bbMemTimes" class="bb-input">
                            <option value="2">2×</option><option value="3" selected>3×</option><option value="5">5×</option><option value="10">10×</option><option value="0">Until I stop</option>
                        </select>
                    </label>
                    <label>Pause
                        <select id="bbMemGap" class="bb-input">
                            <option value="1">1 sec</option><option value="2" selected>2 sec</option><option value="4">4 sec</option><option value="8">8 sec</option>
                        </select>
                    </label>
                </div>
                <button type="button" class="bb-btn bb-btn-primary bb-w-full" id="bbMemStart">Start repeating</button>
            </div>
        </div>
    </div>
</div>

<div id="biblePlanModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="biblePlanTitle">
    <div class="bb-sheet bb-sheet-lg" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="biblePlanTitle">Plan</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body" id="bbPlanBody"></div>
        <div class="bb-sheet-foot">
            <button type="button" class="bb-btn bb-btn-danger" id="bbPlanStop">Stop this plan</button>
            <span class="bb-grow"></span>
            <button type="button" class="bb-btn" data-close>Close</button>
        </div>
    </div>
</div>

<div id="bibleAdminModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center" aria-labelledby="bibleAdminTitle">
    <div class="bb-sheet" data-modal-panel>
        <div class="bb-sheet-head">
            <h3 id="bibleAdminTitle">API.Bible status</h3>
            <button type="button" class="bb-close" data-close aria-label="Close"><?= $icon(BB_I_CLOSE) ?></button>
        </div>
        <div class="bb-sheet-body" id="bbAdminBody"></div>
        <div class="bb-sheet-foot">
            <span class="bb-grow"></span>
            <button type="button" class="bb-btn" id="bbAdminRefresh">Refresh catalogue</button>
            <button type="button" class="bb-btn bb-btn-primary" data-close>Done</button>
        </div>
    </div>
</div>

<script>
    window.HOD_BIBLE = <?= json_encode($bibleConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= $bibleAsset('/assets/js/bible/core.js') ?>" defer></script>
<script src="<?= $bibleAsset('/assets/js/bible/reader.js') ?>" defer></script>
<script src="<?= $bibleAsset('/assets/js/bible/voice.js') ?>" defer></script>
<script src="<?= $bibleAsset('/assets/js/bible/share.js') ?>" defer></script>
<script src="<?= $bibleAsset('/assets/js/bible/study.js') ?>" defer></script>
<script src="<?= $bibleAsset('/assets/js/bible/app.js') ?>" defer></script>

<?php require_once '../../includes/footer.php'; ?>
