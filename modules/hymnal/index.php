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
    'logo'      => '/assets/images/logo_hod.png',
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
const HY_I_CARET  = 'M6 9l6 6 6-6';
const HY_I_PEOPLE = 'M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75';
const HY_I_CHOIR  = HY_I_STUDIO;
const HY_I_PEN    = 'M12 20h9M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4 12.5-12.5z';
const HY_I_DOTS   = 'M5 12h.01M12 12h.01M19 12h.01';
const HY_I_SHARE  = 'M4 12v7a2 2 0 002 2h12a2 2 0 002-2v-7M16 6l-4-4-4 4M12 2v13';
const HY_I_DOWN   = 'M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3';
const HY_I_TUNE   = 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,500;0,7..72,600;1,7..72,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $hyAsset('/assets/hymnal/vendor/abcjs-6.7.2/abcjs-audio.css') ?>">
<link rel="stylesheet" href="<?= $hyAsset('/assets/css/hymnal.css') ?>">

<div class="hy" id="hyRoot" data-page="paper">

    <!-- Top bar. Four things and no more, so the hymn number is never cut off
         on a phone: where you are (tap to choose another hymn), the reading
         mode, search, and a ⋯ menu for everything else. Slides away while
         reading down and comes back on the first upward scroll.

         The two menus are popovers, not dialogs: no "modal" in their ids, so
         modal-manager.js leaves them alone, and app.js closes them on a tap
         outside or Escape. -->
    <div class="hy-bar" id="hyBar">
        <button type="button" class="hy-where" id="hyLibraryOpen" title="Choose a hymn">
            <span class="hy-where-n" id="hyWhereNumber"><?= $hyFirst ? (int) $hyFirst['n'] : '' ?></span>
            <span class="hy-where-t" id="hyWhereTitle"><?= htmlspecialchars((string) ($hyFirst['title'] ?? 'Hymnary'), ENT_QUOTES, 'UTF-8') ?></span>
            <?= $icon(HY_I_CARET, 'hy-where-caret') ?>
        </button>

        <div class="hy-bar-right">
            <div class="hy-pop">
                <button type="button" class="hy-modebtn" id="hyModeOpen" aria-haspopup="menu" aria-expanded="false"
                        aria-controls="hyModeMenu" title="Reading mode">
                    <span class="hy-modebtn-ic">
                        <span data-mode-icon="congregation"><?= $icon(HY_I_PEOPLE, 'hy-ic') ?></span>
                        <span data-mode-icon="choir" hidden><?= $icon(HY_I_CHOIR, 'hy-ic') ?></span>
                        <?= $icon(HY_I_CARET, 'hy-modebtn-caret') ?>
                    </span>
                    <span class="hy-modebtn-label" id="hyModeLabel">Congregation</span>
                </button>
                <div class="hy-menu" id="hyModeMenu" role="menu" aria-label="Reading mode" hidden>
                    <button type="button" class="hy-menu-item" id="hyModeCongregation" role="menuitemradio" aria-checked="true" data-mode="congregation">
                        <span class="hy-menu-ic"><?= $icon(HY_I_PEOPLE, 'hy-ic') ?></span>
                        <span class="hy-menu-text"><b>Congregation</b><small>Big words to sing from</small></span>
                        <span class="hy-menu-tick"><?= $icon(HY_I_CHECK, 'hy-ic-sm') ?></span>
                    </button>
                    <button type="button" class="hy-menu-item" id="hyModeChoir" role="menuitemradio" aria-checked="false" data-mode="choir">
                        <span class="hy-menu-ic"><?= $icon(HY_I_CHOIR, 'hy-ic') ?></span>
                        <span class="hy-menu-text"><b>Choir</b><small>Staff, sol-fa and playback</small></span>
                        <span class="hy-menu-tick"><?= $icon(HY_I_CHECK, 'hy-ic-sm') ?></span>
                    </button>
                </div>
            </div>

            <button type="button" class="hy-icbtn" id="hySearchOpen" aria-label="Search the library" title="Search (/)"><?= $icon(HY_I_SEARCH, 'hy-ic') ?></button>

            <div class="hy-pop">
                <button type="button" class="hy-icbtn" id="hyMoreOpen" aria-haspopup="menu" aria-expanded="false"
                        aria-controls="hyMoreMenu" aria-label="More options" title="More options"><?= $icon(HY_I_DOTS, 'hy-ic hy-ic-dots') ?></button>
                <div class="hy-menu" id="hyMoreMenu" role="menu" aria-label="More options" hidden>
                    <button type="button" class="hy-menu-item" id="hyShareMenu" role="menuitem" data-share>
                        <span class="hy-menu-ic"><?= $icon(HY_I_SHARE, 'hy-ic') ?></span>
                        <span class="hy-menu-text"><b>Share as a card</b><small>A picture for WhatsApp or Status</small></span>
                    </button>
                    <button type="button" class="hy-menu-item" id="hyDisplayOpen" role="menuitem">
                        <span class="hy-menu-ic"><?= $icon(HY_I_AA, 'hy-ic') ?></span>
                        <span class="hy-menu-text"><b>Text &amp; display</b><small>Size, page colour, chords</small></span>
                    </button>
                    <button type="button" class="hy-menu-item" id="hyAboutOpen" role="menuitem">
                        <span class="hy-menu-ic"><?= $icon(HY_I_INFO, 'hy-ic') ?></span>
                        <span class="hy-menu-text"><b>About this hymn</b><small>Writer, Scripture, licence</small></span>
                    </button>
                    <?php if (!empty($hyBoot['studio']['allowed'])) : ?>
                        <div class="hy-menu-sep" role="separator"></div>
                        <span class="hy-menu-cap" aria-hidden="true">Music team</span>
                        <button type="button" class="hy-menu-item" id="hyStudioOpen" role="menuitem">
                            <span class="hy-menu-ic"><?= $icon(HY_I_PEN, 'hy-ic') ?></span>
                            <span class="hy-menu-text"><b>Studio</b><small>Add and edit songs</small></span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($hyBoot['ready'])) : ?>
        <p class="hy-notready">
            Every hymn here is readable now. Marking a favourite, saving your place
            and the Studio switch on once the church office has finished setting
            the Hymnary up.
        </p>
    <?php endif; ?>

    <!-- A <div>, not <main> or <header>: the page is already inside
         includes/header.php's <main>, and pwa.css styles every <main> and
         <header> in the installed app (it took this column's bottom padding,
         so the floating choir bar covered the buttons under the song). -->
    <div class="hy-main">
        <nav class="hy-side hy-side-prev"><button type="button" id="hyPrev" aria-label="Previous"><?= $icon(HY_I_LEFT, 'w-6 h-6') ?></button></nav>
        <nav class="hy-side hy-side-next"><button type="button" id="hyNext" aria-label="Next"><?= $icon(HY_I_RIGHT, 'w-6 h-6') ?></button></nav>

        <article class="hy-song" id="hySong" aria-live="polite">
            <!-- Rendered by assets/js/hymnal/reader.js. The server paints the
                 first song into this element below so the page is readable
                 before any script runs. -->
        </article>

        <!-- One row on the narrowest phone: the two page turns at the edges and
             the two things you do to the song on screen between them. -->
        <div class="hy-pager">
            <button type="button" class="hy-btn hy-pager-prev" id="hyPrev2"><?= $icon(HY_I_LEFT, 'hy-ic-sm') ?> <span>Previous</span></button>
            <div class="hy-pager-mid">
                <button type="button" class="hy-round" id="hyFavourite" aria-pressed="false"
                        aria-label="Add to your favourites" title="Add to your favourites"><?= $icon(HY_I_HEART, 'hy-ic') ?></button>
                <button type="button" class="hy-round" id="hyShareOpen" data-share
                        aria-label="Share this hymn as a card" title="Share as a card"><?= $icon(HY_I_SHARE, 'hy-ic') ?></button>
            </div>
            <button type="button" class="hy-btn hy-pager-next" id="hyNext2"><span>Next</span> <?= $icon(HY_I_RIGHT, 'hy-ic-sm') ?></button>
        </div>
    </div>

    <!-- Choir controls. Floats at the foot of the screen in choir mode so a
         chorister can start the music or take one part with a thumb while
         holding the music. Speed, key, sound and sol-fa sit behind the
         options button, which shows the speed and key so a change is never
         hidden. Hidden entirely when the song has no score. -->
    <div class="hy-transport" id="hyTransport" hidden>
        <button type="button" class="hy-tr-play" id="hyPlay" aria-label="Play">
            <span class="hy-tr-ic-play"><?= $icon(HY_I_PLAY, 'w-5 h-5') ?></span>
            <span class="hy-tr-ic-pause" hidden><?= $icon(HY_I_PAUSE, 'w-5 h-5') ?></span>
        </button>
        <div class="hy-tr-voices" id="hyVoices" role="group" aria-label="Voice parts"></div>
        <div class="hy-pop hy-tr-opts">
            <button type="button" class="hy-tr-optbtn" id="hyChoirOptsOpen" aria-expanded="false"
                    aria-controls="hyChoirOpts" title="Speed, key, sound and sol-fa">
                <?= $icon(HY_I_TUNE, 'hy-ic-sm') ?>
                <span class="hy-tr-sum" id="hyChoirOptsSum">100%</span>
            </button>
            <div class="hy-menu hy-menu-up hy-tr-panel" id="hyChoirOpts" role="group" aria-label="Playback options" hidden>
                <label class="hy-tr-row">
                    <span class="hy-tr-k">Speed</span>
                    <input type="range" id="hyTempo" min="50" max="150" step="5" value="100">
                    <output id="hyTempoOut" for="hyTempo">100%</output>
                </label>
                <label class="hy-tr-row">
                    <span class="hy-tr-k">Key</span>
                    <select id="hyTranspose" autocomplete="off">
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
                <div class="hy-tr-row">
                    <span class="hy-tr-k" id="hyInstrumentLabel">Sound</span>
                    <div class="hy-seg" id="hyInstrument" role="group" aria-labelledby="hyInstrumentLabel">
                        <button type="button" data-instrument="acoustic_grand_piano" class="is-on" aria-pressed="true">Piano</button>
                        <button type="button" data-instrument="choir_aahs" aria-pressed="false">Voices</button>
                    </div>
                </div>
                <label class="hy-switch hy-tr-row">
                    <input type="checkbox" id="hySolfaToggle" checked>
                    <span>Show the sol-fa under the staff</span>
                </label>
            </div>
        </div>
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

    <!-- Share as a card: the verses the member picks, drawn on a canvas in the
         church's colours, for a WhatsApp post or a Status. assets/js/hymnal/share.js. -->
    <div class="hy-modal" id="hyShareModal" hidden>
        <div class="hy-sheet hy-sheet-wide" data-modal-panel>
            <div class="hy-sheet-head">
                <h3 id="hyShareTitle">Share this hymn</h3>
                <button type="button" class="hy-close" data-close aria-label="Close"><?= $icon(HY_I_CLOSE) ?></button>
            </div>
            <div class="hy-sheet-body hy-share">
                <div class="hy-share-preview">
                    <canvas id="hyShareCanvas" width="1080" height="1080" role="img" aria-label="Preview of the hymn card"></canvas>
                </div>
                <div class="hy-share-controls">
                    <div class="hy-field">
                        <span class="hy-field-label" id="hyShareSectionsLabel">On the card</span>
                        <div class="hy-share-secs" id="hyShareSections" role="group" aria-labelledby="hyShareSectionsLabel"></div>
                        <p class="hy-share-hint" id="hyShareHint" aria-live="polite"></p>
                    </div>
                    <div class="hy-field">
                        <span class="hy-field-label" id="hyShareThemesLabel">Background</span>
                        <div class="hy-share-themes" id="hyShareThemes" role="group" aria-labelledby="hyShareThemesLabel"></div>
                    </div>
                    <div class="hy-field">
                        <span class="hy-field-label" id="hyShareFormatLabel">Shape</span>
                        <div class="hy-seg" id="hyShareFormat" role="group" aria-labelledby="hyShareFormatLabel">
                            <button type="button" data-format="square" class="is-on" aria-pressed="true">Square</button>
                            <button type="button" data-format="story" aria-pressed="false">Status / Story</button>
                        </div>
                    </div>
                    <label class="hy-switch">
                        <input type="checkbox" id="hyShareBrand" checked>
                        <span>Show the Household of David name</span>
                    </label>
                </div>
            </div>
            <div class="hy-sheet-foot">
                <span class="hy-grow"></span>
                <button type="button" class="hy-btn" id="hyShareDownload"><?= $icon(HY_I_DOWN, 'hy-ic-sm') ?> <span>Download</span></button>
                <button type="button" class="hy-btn hy-btn-primary" id="hyShareGo"><?= $icon(HY_I_SHARE, 'hy-ic-sm') ?> <span>Share</span></button>
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
<script src="<?= $hyAsset('/assets/js/hymnal/share.js') ?>" defer></script>
<?php if (!empty($hyBoot['studio']['allowed'])) : ?>
    <script src="<?= $hyAsset('/assets/js/hymnal/abc_voices.js') ?>" defer></script>
    <script src="<?= $hyAsset('/assets/js/hymnal/studio.js') ?>" defer></script>
<?php endif; ?>
<script src="<?= $hyAsset('/assets/js/hymnal/app.js') ?>" defer></script>

<?php require_once '../../includes/footer.php'; ?>
