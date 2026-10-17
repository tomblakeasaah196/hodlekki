<?php
// /modules/hymnal/studio.php
//
// The Studio, included by modules/hymnal/index.php only when the member holds
// it (River of Life or a pastor — includes/hymnal/rights.php). The endpoint
// re-checks on every write, because a hidden dialog is not a permission.
//
// The shape of the screen follows the job: a song arrives either as a
// photograph of a sheet, as text somebody pasted, or in somebody's head. So
// there are two ways in and ONE form, and the form is the same whichever way
// the words got there. Nothing the AI returns is saved by itself — it lands in
// this form, with its own notes about what it could not read, and a person
// presses Save.
//
// $hyBoot and $icon come from the including page.

$hyStudio = $hyBoot['studio'];
?>
<div class="hy-modal" id="hyStudioModal" hidden>
    <div class="hy-sheet hy-sheet-tall hy-sheet-wide" data-modal-panel>
        <div class="hy-sheet-head">
            <h3 id="hyStudioTitle">Hymnary Studio</h3>
            <button type="button" class="hy-close" data-close aria-label="Close"><?= $icon(HY_I_CLOSE) ?></button>
        </div>

        <div class="hy-tabs">
            <button type="button" class="hy-tab is-on" data-pane="hyPaneAdd">Add a song</button>
            <button type="button" class="hy-tab" data-pane="hyPaneList">Our songs</button>
        </div>

        <div class="hy-sheet-body">

            <!-- ------------------------------------------------ add a song -->
            <div class="hy-pane" id="hyPaneAdd">

                <p class="hy-note">
                    <b>Two ways to add a song.</b>
                    Take a photo of the song sheet or paste the words and let it be
                    read for you, or just start typing. Either way you get the same
                    form, and nothing is saved until you press Save.
                </p>

                <div class="hy-studio-grid" id="hyIntake">
                    <?php if (!empty($hyStudio['ai'])) : ?>
                        <label class="hy-drop" id="hyDrop">
                            <?= $icon(HY_I_SPARK, 'w-6 h-6') ?>
                            <b>Photograph or PDF of the song sheet</b>
                            <span>Tap to choose a file, or drag one here. JPG, PNG or PDF, under 6&nbsp;MB.</span>
                            <input type="file" id="hyFile" accept="image/jpeg,image/png,image/webp,application/pdf" hidden>
                        </label>
                        <div>
                            <label class="hy-in-label" for="hyPaste">…or paste the words here</label>
                            <textarea class="hy-ta" id="hyPaste" placeholder="Paste the whole song. Chords in square brackets are kept."></textarea>
                            <button type="button" class="hy-btn hy-btn-primary" id="hyReadPaste" style="margin-top:.5rem">
                                <?= $icon(HY_I_SPARK, 'w-4 h-4') ?> Read this for me
                            </button>
                        </div>
                    <?php else : ?>
                        <p class="hy-note">
                            <b>Reading a song automatically is off.</b>
                            <?= htmlspecialchars((string) ($hyStudio['ai_off_why'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>
                    <div>
                        <button type="button" class="hy-btn" id="hyStartBlank"><?= $icon(HY_I_PLUS, 'w-4 h-4') ?> Type a song in myself</button>
                    </div>
                </div>

                <div id="hyBusy" class="hy-busy" hidden>Reading the song… this can take up to a minute.</div>

                <!-- The one form. Hidden until there is something to put in it. -->
                <form id="hyForm" class="hy-studio-grid" hidden autocomplete="off">
                    <input type="hidden" id="hySongId" value="">
                    <input type="hidden" id="hyAiJobId" value="">

                    <div class="hy-note" id="hyAiNotes" hidden></div>

                    <div>
                        <label class="hy-in-label" for="hyTitle">Title</label>
                        <input class="hy-in" type="text" id="hyTitle" maxlength="255" required>
                        <p class="hy-err" id="hyErrTitle" hidden></p>
                    </div>

                    <div class="hy-row2">
                        <div>
                            <label class="hy-in-label" for="hyWriter">Who wrote it</label>
                            <input class="hy-in" type="text" id="hyWriter" maxlength="255" placeholder="Writer or composer">
                        </div>
                        <div>
                            <label class="hy-in-label" for="hyLang">Language of these words</label>
                            <select class="hy-sel" id="hyLang">
                                <?php foreach (HYMNAL_LANGUAGES as $code => $label) : ?>
                                    <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="hy-row3">
                        <div>
                            <label class="hy-in-label" for="hyKey">Key</label>
                            <input class="hy-in" type="text" id="hyKey" maxlength="16" placeholder="D">
                        </div>
                        <div>
                            <label class="hy-in-label" for="hyTime">Time</label>
                            <input class="hy-in" type="text" id="hyTime" maxlength="16" placeholder="4/4">
                        </div>
                        <div>
                            <label class="hy-in-label" for="hyBpm">Speed (bpm)</label>
                            <input class="hy-in" type="number" id="hyBpm" min="20" max="300" placeholder="96">
                        </div>
                    </div>

                    <!-- The words. One block per verse or chorus, because that
                         is how a song is actually sung and how the page prints
                         it back. -->
                    <div>
                        <label class="hy-in-label">The words</label>
                        <div class="hy-seclist" id="hySections"></div>
                        <p class="hy-err" id="hyErrSections" hidden></p>
                        <div style="display:flex;gap:.4rem;margin-top:.5rem;flex-wrap:wrap">
                            <button type="button" class="hy-btn" data-add="verse"><?= $icon(HY_I_PLUS, 'w-4 h-4') ?> Verse</button>
                            <button type="button" class="hy-btn" data-add="chorus"><?= $icon(HY_I_PLUS, 'w-4 h-4') ?> Chorus</button>
                            <button type="button" class="hy-btn" data-add="bridge"><?= $icon(HY_I_PLUS, 'w-4 h-4') ?> Bridge</button>
                        </div>
                    </div>

                    <!-- Licence. Deliberately not foldable and not optional:
                         the library mixes public-domain hymns with songs held
                         under the church's licence, so every song has to say
                         which it is. -->
                    <div>
                        <label class="hy-in-label" for="hyLicence">How are we allowed to use this song?</label>
                        <select class="hy-sel" id="hyLicence">
                            <?php foreach (HYMNAL_LICENCES as $code => $l) : ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($l['label'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hy-err" id="hyErrLicence" hidden></p>
                        <div class="hy-row2" style="margin-top:.5rem">
                            <div id="hyCcliWrap" hidden>
                                <label class="hy-in-label" for="hyCcli">CCLI song number</label>
                                <input class="hy-in" type="text" id="hyCcli" maxlength="32" inputmode="numeric">
                                <p class="hy-err" id="hyErrCcli" hidden></p>
                            </div>
                            <div id="hyHolderWrap" hidden>
                                <label class="hy-in-label" for="hyHolder">Whose licence</label>
                                <input class="hy-in" type="text" id="hyHolder" maxlength="190" placeholder="Household of David, main church">
                                <p class="hy-err" id="hyErrHolder" hidden></p>
                            </div>
                        </div>
                        <div id="hyDetailWrap" hidden style="margin-top:.5rem">
                            <label class="hy-in-label" for="hyDetail">Where the permission comes from</label>
                            <input class="hy-in" type="text" id="hyDetail" maxlength="500" placeholder="Email from the writer, 12 March 2026">
                            <p class="hy-err" id="hyErrDetail" hidden></p>
                        </div>
                    </div>

                    <div>
                        <label class="hy-in-label">Which shelves</label>
                        <div class="hy-shelfpick" id="hyShelfPick">
                            <?php foreach (hymnal_studio_collections() as $slug => $c) : ?>
                                <button type="button" class="hy-chip" data-shelf="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" aria-pressed="false"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endforeach; ?>
                        </div>
                        <p class="hy-err" id="hyErrCollections" hidden></p>
                    </div>

                    <div class="hy-row2">
                        <div>
                            <label class="hy-in-label" for="hyThemes">Themes</label>
                            <input class="hy-in" type="text" id="hyThemes" maxlength="500" placeholder="Praise, Communion">
                        </div>
                        <div>
                            <label class="hy-in-label" for="hyScripture">Scripture</label>
                            <input class="hy-in" type="text" id="hyScripture" maxlength="500" placeholder="Psalm 103:1">
                        </div>
                    </div>

                    <!-- Music. Optional, and plainly described as optional: a
                         song is useful to the congregation with words alone,
                         and choir mode says so when there is no score. -->
                    <details id="hyScoreWrap">
                        <summary style="cursor:pointer;font-size:.8125rem;font-weight:600;margin-bottom:.5rem">
                            Add the music (optional — for choir mode)
                        </summary>
                        <p class="hy-note" style="margin-bottom:.6rem">
                            Paste the music in <b>ABC notation</b>, one voice per part. When you
                            check it, the staff is drawn here and the tonic sol-fa is worked out
                            from the notes — you never type sol-fa yourself.
                        </p>
                        <textarea class="hy-ta" id="hyAbc" placeholder="X:1&#10;T:Song title&#10;M:4/4&#10;L:1/4&#10;K:D&#10;D2 F2 | A4 |"></textarea>
                        <div style="display:flex;gap:.4rem;margin-top:.5rem;flex-wrap:wrap">
                            <button type="button" class="hy-btn" id="hyCheckAbc"><?= $icon(HY_I_CHECK, 'w-4 h-4') ?> Check the music</button>
                            <button type="button" class="hy-btn" id="hySaveAbc" disabled>Save the music</button>
                            <button type="button" class="hy-btn hy-btn-danger" id="hyClearAbc">Remove the music</button>
                        </div>
                        <div class="hy-score" id="hyAbcPreview"></div>
                        <div id="hyAbcSolfa" class="hy-solfa"></div>
                    </details>

                    <div class="hy-sheet-foot" style="padding-left:0;padding-right:0">
                        <button type="button" class="hy-btn" id="hyCancel">Cancel</button>
                        <span class="hy-grow"></span>
                        <span class="hy-count" id="hySaveState"></span>
                        <?php if (!empty($hyStudio['can_publish'])) : ?>
                            <button type="button" class="hy-btn" id="hyPublish" disabled>Publish to the church</button>
                        <?php endif; ?>
                        <button type="button" class="hy-btn hy-btn-primary" id="hySave">Save</button>
                    </div>

                    <?php if (empty($hyStudio['can_publish'])) : ?>
                        <p class="hy-count"><?= htmlspecialchars((string) $hyStudio['why'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </form>
            </div>

            <!-- --------------------------------------------------- our songs -->
            <div class="hy-pane" id="hyPaneList" hidden>
                <div class="hy-lib-search" style="padding-left:0;padding-right:0;border:0">
                    <?= $icon(HY_I_SEARCH, 'w-4 h-4') ?>
                    <input type="search" id="hyStudioQuery" placeholder="Find one of our songs" autocomplete="off">
                </div>
                <div id="hyStudioList"></div>
            </div>
        </div>

        <div class="hy-sheet-foot">
            <span class="hy-count"><?= htmlspecialchars((string) $hyStudio['why'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="hy-grow"></span>
            <button type="button" class="hy-btn hy-btn-primary" data-close>Done</button>
        </div>
    </div>
</div>
