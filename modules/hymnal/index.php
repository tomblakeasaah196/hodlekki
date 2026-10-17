<?php
// /modules/hymnal/index.php
require_once '../../includes/header.php';
require_once '../../includes/hymnal/bootstrap.php';

// The Hymnary — Household of David's song library. Open to every signed-in
// member, in two modes: Congregation (big words, for a phone during morning
// devotions or when the screen is down) and Choir (four-part staff, tonic
// sol-fa, and playback for rehearsal).
//
// The Studio button appears only for River of Life and the pastors, and the
// endpoint re-checks that on every write — see includes/hymnal/rights.php.
// Member and setup guide: modules/hymnal/how_to_use.md.

if (empty($_SESSION['hymnal_csrf']) || !is_string($_SESSION['hymnal_csrf'])) {
    $_SESSION['hymnal_csrf'] = bin2hex(random_bytes(32));
}

$hyUserId = (int) $_SESSION['user_id'];
$hyBoot = hymnal_boot_payload($pdo, $hyUserId);

// Paint the first song without a round trip. The corpus is files, so this
// costs no query and works before the migration has run.
$hyOpen = (int) ($_GET['n'] ?? ($hyBoot['state']['last_number'] ?? 0));
if ($hyOpen <= 0) {
    $hyOpen = (int) (hymnal_corpus_index()[0]['n'] ?? 1);
}
$hyFirstRes = hymnal_library_open($pdo, $hyUserId, $hyOpen);
$hyFirst = $hyFirstRes['ok'] ? $hyFirstRes['data'] : null;
if ($hyFirst === null) {
    $hyFirstRes = hymnal_library_open($pdo, $hyUserId, (int) (hymnal_corpus_index()[0]['n'] ?? 1));
    $hyFirst = $hyFirstRes['ok'] ? $hyFirstRes['data'] : null;
}

$hyAsset = static function (string $path): string {
    $file = __DIR__ . '/../../' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';

    return htmlspecialchars($path . '?v=' . $v, ENT_QUOTES, 'UTF-8');
};

$hyConfig = [
    'csrf'      => $_SESSION['hymnal_csrf'],
    'firstName' => (string) ($_SESSION['first_name'] ?? ''),
    'boot'      => $hyBoot,
    'first'     => $hyFirst,
    'soundfont' => '/assets/hymnal/soundfont/',
    'languages' => HYMNAL_LANGUAGES,
    'licences'  => HYMNAL_LICENCES,
];

$icon = static fn(string $d, string $cls = 'w-5 h-5'): string =>
    '<svg class="' . $cls . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';

const HY_I_BOOK   = 'M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5V5a2 2 0 012-2h14v18H6.5A2.5 2.5 0 014 19.5z';
const HY_I_SEARCH = 'M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z';
const HY_I_CLOSE  = 'M18 6L6 18M6 6l12 12';
const HY_I_LEFT   = 'M15 18l-6-6 6-6';
const HY_I_RIGHT  = 'M9 18l6-6-6-6';
const HY_I_HEART  = 'M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 00-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 000-7.8z';
const HY_I_AA     = 'M4 20l6-16 6 16M7 14h6M18 20h3';
const HY_I_INFO   = 'M12 16v-5M12 8h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
const HY_I_PLAY   = 'M7 4v16l13-8L7 4z';
const HY_I_PAUSE  = 'M7 5h3v14H7zM14 5h3v14h-3z';
const HY_I_STUDIO = 'M9 18V5l12-2v13M9 18a3 3 0 11-6 0 3 3 0 016 0zm12-2a3 3 0 11-6 0 3 3 0 016 0z';
const HY_I_PLUS   = 'M12 5v14M5 12h14';
const HY_I_SPARK  = 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z';
const HY_I_CHECK  = 'M20 6L9 17l-5-5';
const HY_I_TRASH  = 'M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2M19 6l-1 14a1 1 0 01-1 1H7a1 1 0 01-1-1L5 6';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Literata:opsz,wght@7..72,400;7..72,500;7..72,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $hyAsset('/assets/hymnal/vendor/abcjs-6.7.2/abcjs-audio.css') ?>">
<link rel="stylesheet" href="<?= $hyAsset('/assets/css/hymnal.css') ?>">

<div class="hy" id="hyRoot" data-page="paper">

    <!-- Top bar. Slides away while reading down on a phone and comes back on
         the first upward scroll, like the Bible reader. -->
    <header class="hy-bar" id="hyBar">
        <button type="button" class="hy-bar-btn hy-bar-where" id="hyLibraryOpen">
            <?= $icon(HY_I_BOOK, 'w-4 h-4') ?>
            <span class="hy-where-n" id="hyWhereNumber"><?= $hyFirst ? (int) $hyFirst['n'] : '' ?></span>
            <span class="hy-where-t" id="hyWhereTitle"><?= htmlspecialchars((string) ($hyFirst['title'] ?? 'Hymnary'), ENT_QUOTES, 'UTF-8') ?></span>
        </button>

        <div class="hy-modes" role="tablist" aria-label="Reading mode">
            <button type="button" class="hy-mode is-on" id="hyModeCongregation" role="tab" aria-selected="true">Congregation</button>
            <button type="button" class="hy-mode" id="hyModeChoir" role="tab" aria-selected="false">Choir</button>
        </div>

        <div class="hy-bar-right">
            <button type="button" class="hy-bar-btn" id="hySearchOpen" aria-label="Search the library"><?= $icon(HY_I_SEARCH, 'w-4 h-4') ?></button>
            <button type="button" class="hy-bar-btn" id="hyDisplayOpen" aria-label="Text and display"><?= $icon(HY_I_AA, 'w-4 h-4') ?></button>
            <?php if (!empty($hyBoot['studio']['allowed'])) : ?>
                <button type="button" class="hy-bar-btn hy-bar-studio" id="hyStudioOpen">
                    <?= $icon(HY_I_STUDIO, 'w-4 h-4') ?><span class="hy-studio-label">Studio</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if (empty($hyBoot['ready'])) : ?>
        <p class="hy-notready">
            Every hymn here is readable now. Marking a favourite, saving your place
            and the Studio switch on once the church office has finished setting
            the Hymnary up.
        </p>
    <?php endif; ?>

    <main class="hy-main">
        <nav class="hy-side hy-side-prev"><button type="button" id="hyPrev" aria-label="Previous"><?= $icon(HY_I_LEFT, 'w-6 h-6') ?></button></nav>
        <nav class="hy-side hy-side-next"><button type="button" id="hyNext" aria-label="Next"><?= $icon(HY_I_RIGHT, 'w-6 h-6') ?></button></nav>

        <article class="hy-song" id="hySong" aria-live="polite">
            <!-- Rendered by assets/js/hymnal/reader.js. The server paints the
                 first song into this element below so the page is readable
                 before any script runs. -->
        </article>

        <div class="hy-pager">
            <button type="button" class="hy-btn" id="hyPrev2"><?= $icon(HY_I_LEFT, 'w-4 h-4') ?> Previous</button>
            <button type="button" class="hy-btn" id="hyFavourite" aria-pressed="false"><?= $icon(HY_I_HEART, 'w-4 h-4') ?> <span>Favourite</span></button>
            <button type="button" class="hy-btn" id="hyAboutOpen"><?= $icon(HY_I_INFO, 'w-4 h-4') ?> About</button>
            <button type="button" class="hy-btn" id="hyNext2">Next <?= $icon(HY_I_RIGHT, 'w-4 h-4') ?></button>
        </div>
    </main>

    <!-- Choir controls. Fixed to the foot of the screen in choir mode so a
         chorister can start, slow down or isolate a part with one thumb while
         holding the music. Hidden entirely when the song has no score. -->
    <div class="hy-transport" id="hyTransport" hidden>
        <button type="button" class="hy-tr-play" id="hyPlay" aria-label="Play">
            <span class="hy-tr-ic-play"><?= $icon(HY_I_PLAY, 'w-5 h-5') ?></span>
            <span class="hy-tr-ic-pause" hidden><?= $icon(HY_I_PAUSE, 'w-5 h-5') ?></span>
        </button>
        <div class="hy-tr-voices" id="hyVoices" role="group" aria-label="Voice parts"></div>
        <label class="hy-tr-field">
            <span>Speed</span>
            <input type="range" id="hyTempo" min="50" max="150" step="5" value="100">
            <output id="hyTempoOut">100%</output>
        </label>
        <label class="hy-tr-field">
            <span>Key</span>
            <select id="hyTranspose">
                <option value="-5">down 5</option>
                <option value="-4">down 4</option>
                <option value="-3">down 3</option>
                <option value="-2">down 2</option>
                <option value="-1">down 1</option>
                <option value="0" selected>as written</option>
                <option value="1">up 1</option>
                <option value="2">up 2</option>
                <option value="3">up 3</option>
                <option value="4">up 4</option>
                <option value="5">up 5</option>
            </select>
        </label>
        <label class="hy-tr-field hy-tr-check">
            <input type="checkbox" id="hySolfaToggle" checked>
            <span>Sol-fa</span>
        </label>
        <label class="hy-tr-field">
            <span>Sound</span>
            <select id="hyInstrument">
                <option value="acoustic_grand_piano">Piano</option>
                <option value="choir_aahs">Voices</option>
            </select>
        </label>
    </div>

    <!-- The library. Browse by shelf, jump to a number, search, and pick up
         what you marked or read lately. -->
    <div class="hy-modal" id="hyLibraryModal" hidden>
        <div class="hy-sheet hy-sheet-tall" data-modal-panel>
            <div class="hy-sheet-head">
                <h3 id="hyLibraryTitle">The Hymnary</h3>
                <button type="button" class="hy-close" data-close aria-label="Close"><?= $icon(HY_I_CLOSE) ?></button>
            </div>
            <div class="hy-lib-search">
                <?= $icon(HY_I_SEARCH, 'w-4 h-4') ?>
                <input type="search" id="hyQuery" placeholder="Number, title, or a line you remember"
                       autocomplete="off" data-modal-initial-focus>
                <button type="button" class="hy-chip" id="hyScoreOnly" aria-pressed="false">With music</button>
            </div>
            <div class="hy-lib-shelves" id="hyShelves"></div>
            <div class="hy-sheet-body" id="hyLibraryBody"></div>
            <div class="hy-sheet-foot">
                <span class="hy-count" id="hyLibraryCount"></span>
                <span class="hy-grow"></span>
                <button type="button" class="hy-btn hy-btn-primary" data-close>Done</button>
            </div>
        </div>
    </div>

    <!-- Text and display. -->
    <div class="hy-modal" id="hyDisplayModal" hidden>
        <div class="hy-sheet" data-modal-panel>
            <div class="hy-sheet-head">
                <h3 id="hyDisplayTitle">Text &amp; display</h3>
                <button type="button" class="hy-close" data-close aria-label="Close"><?= $icon(HY_I_CLOSE) ?></button>
            </div>
            <div class="hy-sheet-body">
                <div class="hy-field">
                    <span class="hy-field-label">Text size</span>
                    <div class="hy-seg" id="hyTextSize" role="group">
                        <button type="button" data-size="1">A</button>
                        <button type="button" data-size="2">A</button>
                        <button type="button" data-size="3" class="is-on">A</button>
                        <button type="button" data-size="4">A</button>
                        <button type="button" data-size="5">A</button>
                        <button type="button" data-size="6">A</button>
                    </div>
                </div>
                <div class="hy-field">
                    <span class="hy-field-label">Page</span>
                    <div class="hy-seg" id="hyPage" role="group">
                        <button type="button" data-page="paper" class="is-on">Paper</button>
                        <button type="button" data-page="white">White</button>
                        <button type="button" data-page="night">Night</button>
                    </div>
                </div>
                <label class="hy-switch">
                    <input type="checkbox" id="hyChords">
                    <span>Show chords above the words</span>
                </label>
                <div class="hy-field" id="hyLangField" hidden>
                    <span class="hy-field-label">Language</span>
                    <div class="hy-seg" id="hyLangs" role="group"></div>
                </div>
            </div>
            <div class="hy-sheet-foot">
                <span class="hy-grow"></span>
                <button type="button" class="hy-btn hy-btn-primary" data-close>Done</button>
            </div>
        </div>
    </div>

    <!-- About this hymn: who wrote it, where it came from, and under what right
         we are allowed to have it here. -->
    <div class="hy-modal" id="hyAboutModal" hidden>
        <div class="hy-sheet" data-modal-panel>
            <div class="hy-sheet-head">
                <h3 id="hyAboutTitle">About this hymn</h3>
                <button type="button" class="hy-close" data-close aria-label="Close"><?= $icon(HY_I_CLOSE) ?></button>
            </div>
            <div class="hy-sheet-body" id="hyAboutBody"></div>
            <div class="hy-sheet-foot">
                <span class="hy-grow"></span>
                <button type="button" class="hy-btn hy-btn-primary" data-close>Done</button>
            </div>
        </div>
    </div>

    <?php if (!empty($hyBoot['studio']['allowed'])) : ?>
        <?php require __DIR__ . '/studio.php'; ?>
    <?php endif; ?>
</div>

<script>
    window.HOD_HYMNAL = <?= json_encode($hyConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= $hyAsset('/assets/hymnal/vendor/abcjs-6.7.2/abcjs-basic-min.js') ?>" defer></script>
<script src="<?= $hyAsset('/assets/js/hymnal/core.js') ?>" defer></script>
<script src="<?= $hyAsset('/assets/js/hymnal/reader.js') ?>" defer></script>
<script src="<?= $hyAsset('/assets/js/hymnal/choir.js') ?>" defer></script>
<script src="<?= $hyAsset('/assets/js/hymnal/library.js') ?>" defer></script>
<?php if (!empty($hyBoot['studio']['allowed'])) : ?>
    <script src="<?= $hyAsset('/assets/js/hymnal/abc_voices.js') ?>" defer></script>
    <script src="<?= $hyAsset('/assets/js/hymnal/studio.js') ?>" defer></script>
<?php endif; ?>
<script src="<?= $hyAsset('/assets/js/hymnal/app.js') ?>" defer></script>

<?php require_once '../../includes/footer.php'; ?>
