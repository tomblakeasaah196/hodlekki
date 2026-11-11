<?php
// /modules/regions/index.php
// Regional Hubs: every member's local hub (leaders, noticeboard, prayer and
// carpool posts, the regional stream) and, for admins, the command center
// (members by region on a board, broadcasts, auto-assign, export).
// Everything goes through /api/regions_api.php (request shapes unchanged).
$ui = [
    'title' => 'Regional Hubs',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/regions.js'],
];
require_once '../../includes/header.php';

// Security & RBAC Check
if (!isset($_SESSION['user_id'])) {
    echo ui_no_access('Please sign in again to open your region.');
    require_once '../../includes/footer.php';
    exit;
}

$my_user_id = $_SESSION['user_id'];
$admin_roles = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor', 'Director'];
$is_admin = false;

if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    foreach ($_SESSION['roles'] as $role) {
        if (in_array($role['role_name'], $admin_roles) || strpos($role['role_name'], 'IDI') !== false) {
            $is_admin = true;
            break;
        }
    }
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Regional Hubs',
        'description' => 'Your local church community: leaders, notices, prayer, rides and a shared stream.',
    ]) ?>

<?php if ($is_admin): ?>
    <?= ui_tabs([
        'hub' => ['label' => 'My region', 'icon' => 'map-pin'],
        'admin' => ['label' => 'Command center', 'icon' => 'layout-dashboard'],
    ], 'hub', 'Regional Hubs sections') ?>
<?php endif; ?>

    <section id="panel-hub" data-tab-panel="hub" class="ui-tab-panel">
        <div class="ui-card" data-regions-unassigned hidden>
            <?= ui_empty([
                'icon' => 'map-pin',
                'title' => 'You are not in a region yet',
                'text' => 'Add your home address to your profile and you will be placed in your local hub, or an admin will place you.',
                'actions' => ui_button('Update my address', ['variant' => 'primary', 'href' => '/modules/profile/index.php']),
            ]) ?>
        </div>

        <div class="grid gap-6 lg:grid-cols-3" data-regions-hub>
            <div class="ui-stack min-w-0">
                <section class="ui-card" aria-labelledby="regions-name">
                    <div class="ui-card__body">
                        <p class="ui-meta">Your region</p>
                        <h2 class="ui-h2 mt-0.5" id="regions-name" data-regions-name><span class="ui-skeleton inline-block h-7 w-40"></span></h2>
                        <p class="mt-1 text-sm text-ink-muted"><span class="ui-num" data-regions-population>—</span> active members</p>
                    </div>
                    <div class="border-t border-line">
                        <h3 class="ui-meta px-5 pt-4">Leaders</h3>
                        <ul class="ui-list" data-regions-leaders></ul>
                    </div>
                    <div class="ui-card__footer grid grid-cols-2 gap-2">
                        <?= ui_button('Post prayer', ['variant' => 'soft', 'icon' => 'heart-handshake', 'attrs' => ['data-dialog-open' => 'regions-prayer']]) ?>
                        <?= ui_button('Need a ride?', ['variant' => 'soft', 'icon' => 'users-round', 'attrs' => ['data-dialog-open' => 'regions-ride']]) ?>
                    </div>
                </section>

                <section class="ui-card" aria-labelledby="regions-notices-title">
                    <div class="ui-card__header ui-card__header--divided">
                        <h2 class="ui-card__title" id="regions-notices-title">Noticeboard</h2>
                    </div>
                    <ul class="ui-list" data-regions-notices></ul>
                </section>
            </div>

            <section class="ui-card flex min-w-0 flex-col lg:col-span-2" aria-labelledby="regions-stream-title">
                <div class="ui-card__header ui-card__header--divided">
                    <div>
                        <h2 class="ui-card__title" id="regions-stream-title">Regional stream</h2>
                        <p class="ui-card__description">Everyone in your region sees what you post here.</p>
                    </div>
                </div>
                <div class="h-[28rem] overflow-y-auto bg-surface-sunken px-4 py-5 lg:h-[34rem]" data-regions-stream aria-live="polite">
                    <p class="ui-meta text-center">Loading the stream…</p>
                </div>
                <form class="ui-card__footer flex-col items-stretch gap-2" id="regions-stream-form" data-action="regions.post" novalidate>
                    <input type="hidden" name="action" value="post_stream_message">
                    <div class="flex items-center justify-between gap-2 rounded-ui bg-surface-sunken px-3 py-2 text-sm" data-regions-attachment hidden>
                        <span class="min-w-0 truncate text-ink-muted" data-regions-attachment-name></span>
                        <?= ui_button('Remove', ['variant' => 'ghost', 'size' => 'sm', 'attrs' => ['data-action' => 'regions.clear-media']]) ?>
                    </div>
                    <div class="flex items-end gap-2">
                        <label class="ui-btn ui-btn--ghost ui-btn--icon cursor-pointer" for="regions-media" aria-label="Attach a photo or voice note" data-tooltip="Attach">
                            <?= ui_icon('image') ?>
                        </label>
                        <input class="sr-only" type="file" id="regions-media" name="media" accept="image/*,audio/*" data-action-change="regions.media">
                        <label class="sr-only" for="regions-message">Message</label>
                        <textarea class="ui-textarea min-h-[44px] flex-1 resize-none" id="regions-message" name="content" rows="1" placeholder="Write to your region" data-action-input="regions.grow"></textarea>
                        <?= ui_button('Send', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'send', 'icon_only' => true]) ?>
                    </div>
                </form>
            </section>
        </div>
    </section>

<?php if ($is_admin): ?>
    <section id="panel-admin" data-tab-panel="admin" class="ui-tab-panel" hidden>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="ui-h3">Regional distribution</h2>
                <p class="ui-meta">Drag members between regions on a computer, or tap a member to move or mute them.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <?= ui_button('Export', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'href' => '/api/regions_api.php?action=admin_export_excel']) ?>
                <?= ui_button('New region', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['data-action' => 'regions.new-region']]) ?>
                <?= ui_button('Broadcast', ['variant' => 'secondary', 'size' => 'sm', 'icon' => 'megaphone', 'attrs' => ['data-action' => 'regions.broadcasts']]) ?>
                <?= ui_button('Auto-assign', ['variant' => 'primary', 'size' => 'sm', 'icon' => 'sparkles', 'attrs' => ['data-action' => 'regions.auto-assign']]) ?>
            </div>
        </div>
        <div class="-mx-4 flex gap-4 overflow-x-auto px-4 pb-4 sm:mx-0 sm:px-0" data-regions-board>
            <div class="ui-card w-72 flex-none p-4"><span class="ui-skeleton ui-skeleton--text w-2/3"></span></div>
            <div class="ui-card w-72 flex-none p-4"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></div>
            <div class="ui-card w-72 flex-none p-4"><span class="ui-skeleton ui-skeleton--text w-3/5"></span></div>
        </div>
    </section>
<?php endif; ?>
</div>

<?= ui_dialog_start('regions-prayer', ['title' => 'Share with your region', 'description' => 'A prayer request or a testimony. It appears in the stream.']) ?>
    <form class="ui-form" id="regions-prayer-form" data-action="regions.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="add_prayer">
        <?= ui_field('textarea', ['name' => 'prayer_content', 'id' => 'regions-prayer-text', 'label' => "What's on your heart?", 'required' => true, 'attrs' => ['rows' => '4']]) ?>
        <label class="ui-check">
            <input type="checkbox" name="is_testimony" value="1">
            <span>This is a testimony<span class="ui-check__hint">Praise God! It is marked as a testimony in the stream.</span></span>
        </label>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Post to stream', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'regions-prayer-form']])
) ?>

<?= ui_dialog_start('regions-ride', ['title' => 'Carpool and care', 'description' => 'Ask for a ride to church, or offer the seats you have.']) ?>
    <form class="ui-form" id="regions-ride-form" data-action="regions.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="add_carpool">
        <fieldset class="ui-fieldset">
            <legend class="ui-label">I…</legend>
            <div class="grid grid-cols-2 gap-2">
                <label class="ui-check rounded-ui border border-line p-3"><input type="radio" name="type" value="Need_Ride" checked><span>Need a ride</span></label>
                <label class="ui-check rounded-ui border border-line p-3"><input type="radio" name="type" value="Offering_Ride"><span>Have space</span></label>
            </div>
        </fieldset>
        <?= ui_field('text', ['name' => 'route_details', 'id' => 'regions-route', 'label' => 'Route', 'required' => true, 'placeholder' => 'e.g. From Alpha Beach to church']) ?>
        <?= ui_field('number', ['name' => 'seats_available', 'id' => 'regions-seats', 'label' => 'Seats available', 'hint' => 'If you are offering a ride.', 'value' => '0', 'inputmode' => 'numeric', 'attrs' => ['min' => '0', 'max' => '10']]) ?>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Post to stream', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'regions-ride-form']])
) ?>

<?= ui_dialog_start('regions-notice', ['title' => 'Notice', 'description' => ' ', 'size' => 'sm']) ?>
    <p class="whitespace-pre-line text-sm leading-relaxed text-ink" data-regions-notice-text></p>
<?= ui_dialog_end(ui_button('Close', ['variant' => 'secondary', 'attrs' => ['data-dialog-close' => true]])) ?>

<?php if ($is_admin): ?>
<?= ui_dialog_start('regions-region', ['title' => 'New region', 'description' => 'Name the region, the areas it covers, and its leaders.']) ?>
    <form class="ui-form" id="regions-region-form" data-action="regions.save" data-ui-validate novalidate>
        <input type="hidden" name="action" value="admin_manage_region">
        <input type="hidden" name="region_id" value="">
        <?= ui_field('text', ['name' => 'name', 'id' => 'regions-region-name', 'label' => 'Region name', 'required' => true, 'placeholder' => 'e.g. Region 4 (Ajah axis)']) ?>
        <?= ui_field('textarea', ['name' => 'description', 'id' => 'regions-region-desc', 'label' => 'Areas covered', 'optional' => true, 'placeholder' => 'e.g. Ikota to Awoyaya', 'attrs' => ['rows' => '2']]) ?>
        <div class="ui-form-grid">
            <?= ui_field('select', ['name' => 'director_id', 'id' => 'regions-director', 'label' => 'Director in charge', 'options' => ['' => 'Loading…'], 'attrs' => ['data-regions-leader' => true, 'data-ui-combobox' => true]]) ?>
            <?= ui_field('select', ['name' => 'pastor_id', 'id' => 'regions-pastor', 'label' => 'Pastor in charge', 'options' => ['' => 'Loading…'], 'attrs' => ['data-regions-leader' => true, 'data-ui-combobox' => true]]) ?>
            <?= ui_field('select', ['name' => 'head_id', 'id' => 'regions-head', 'label' => 'Regional head', 'options' => ['' => 'Loading…'], 'attrs' => ['data-regions-leader' => true, 'data-ui-combobox' => true]]) ?>
            <div class="ui-field">
                <label class="ui-label" for="regions-color">Colour</label>
                <input class="ui-input h-11 w-20 cursor-pointer p-1" type="color" id="regions-color" name="color_code" value="#1D356A">
                <p class="ui-hint">Marks the region on the board.</p>
            </div>
        </div>
    </form>
<?= ui_dialog_end(
    ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-dialog-close' => true]])
    . ui_button('Save region', ['variant' => 'primary', 'type' => 'submit', 'attrs' => ['form' => 'regions-region-form', 'data-regions-region-save' => true]])
) ?>

<?= ui_dialog_start('regions-broadcast', ['title' => 'Regional broadcasts', 'description' => 'Notices appear on the region\'s noticeboard.', 'size' => 'lg']) ?>
    <form class="ui-form" id="regions-broadcast-form" data-action="regions.broadcast" data-ui-validate novalidate>
        <input type="hidden" name="action" value="admin_manage_broadcast">
        <input type="hidden" name="manage_action" value="create">
        <input type="hidden" name="broadcast_id" value="">
        <?= ui_field('select', ['name' => 'region_id', 'id' => 'regions-broadcast-region', 'label' => 'Region', 'required' => true, 'options' => ['' => 'Choose a region'], 'attrs' => ['data-action-change' => 'regions.broadcast-region']]) ?>
        <?= ui_field('textarea', ['name' => 'message', 'id' => 'regions-broadcast-message', 'label' => 'Message', 'required' => true, 'attrs' => ['rows' => '3']]) ?>
        <div class="flex justify-end gap-2">
            <?= ui_button('Cancel edit', ['variant' => 'ghost', 'attrs' => ['data-action' => 'regions.broadcast-reset', 'data-regions-broadcast-cancel' => true, 'hidden' => true]]) ?>
            <?= ui_button('Send notice', ['variant' => 'primary', 'type' => 'submit', 'icon' => 'megaphone', 'attrs' => ['data-regions-broadcast-save' => true]]) ?>
        </div>
    </form>
    <h3 class="ui-meta mt-6">Noticeboard history</h3>
    <ul class="ui-list -mx-5 mt-1" data-regions-broadcast-list>
        <li class="ui-list__item px-5 text-sm text-ink-subtle">Choose a region to see its notices.</li>
    </ul>
<?= ui_dialog_end() ?>

<?= ui_dialog_start('regions-member', ['title' => 'Manage member', 'description' => ' ', 'size' => 'sm']) ?>
    <div class="ui-stack ui-stack--sm" data-regions-member-actions></div>
<?= ui_dialog_end() ?>
<?php endif; ?>

<?= ui_json_script('regions-config', ['me' => (int) $my_user_id, 'admin' => $is_admin]) ?>

<?php require_once '../../includes/footer.php'; ?>
