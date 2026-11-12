<?php
// /modules/contact_extractor/index.php
// Contact extractor: every phone number the church holds (members,
// registrations, the IDI lists and guests checked in at a service), written as
// 234..., each number once, split into batches to paste into BulkSMS.
// Numbers load only when asked. Opening the page needs the contact extractor;
// loading the numbers needs its Export right.
// Data: /api/contact_extractor_api.php. Script: assets/ui/js/pages/contact-extractor.js.
$ui = [
    'title' => 'Contact extractor',
    'mode' => 'strict',
    'scripts' => ['/assets/ui/js/pages/contact-extractor.js'],
];
require_once '../../includes/header.php';
// Access: the contact extractor in the permission matrix (Roles & permissions).
require_can('contact_extractor');
$ceCanLoad = can('contact_extractor', 'export');

/** A count tile. */
function ce_tile(string $key, string $label, string $meta): string
{
    return '<div class="ui-card ui-stat"><span class="ui-stat__label">' . ui_e($label) . '</span>'
        . '<span class="ui-stat__value ui-num" data-ce="' . ui_e($key) . '">—</span>'
        . '<span class="ui-stat__meta">' . ui_e($meta) . '</span></div>';
}
?>
<div class="ui-page ui-page--wide">
    <?= ui_page_header([
        'title' => 'Contact extractor',
        'description' => 'Every phone number, once.',
        'info' => 'Numbers come from members, event registrations, the IDI lists and guests checked in at a service. Each is written as 234… and appears once, even when it is in several places. Split them into batches to paste into BulkSMS.',
        'back' => ['href' => '/modules/events/', 'label' => 'Events'],
    ]) ?>

<?php if (!$ceCanLoad): ?>
    <?= ui_empty(['icon' => 'lock-keyhole', 'title' => 'Export needed', 'text' => 'Ask an admin for the Export right.']) ?>
<?php else: ?>
    <div id="ce-start">
        <?= ui_empty(['icon' => 'phone', 'title' => 'Load the numbers', 'text' => 'They load only when you ask.', 'actions' => ui_button('Load numbers', ['variant' => 'primary', 'icon' => 'download', 'attrs' => ['data-action' => 'ce.load', 'id' => 'ce-load']])]) ?>
    </div>

    <div class="ui-stack ui-stack--md" id="ce-body" hidden>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <?= ce_tile('total', 'Unique numbers', 'Each once') ?>
            <?= ce_tile('users', 'Members', 'Profiles') ?>
            <?= ce_tile('registrations', 'Registrations', 'Any event') ?>
            <?= ce_tile('mobilization', 'IDI lists', 'Follow-up') ?>
            <?= ce_tile('guests', 'Service guests', 'Checked in') ?>
        </div>

        <section class="ui-card" aria-labelledby="ce-batch-title">
            <div class="ui-card__header ui-card__header--divided">
                <div class="ui-title-row"><h2 class="ui-card__title" id="ce-batch-title">Batches</h2><?= ui_info('Split the list into roughly equal batches, then copy one at a time into BulkSMS.', 'About batches') ?></div>
            </div>
            <div class="ui-card__body">
                <form class="flex flex-wrap items-end gap-4" id="ce-batch-form" data-action="ce.split" novalidate>
                    <div class="ui-field mb-0">
                        <span class="ui-label" id="ce-sep-label">Separate with</span>
                        <div class="ui-segmented" role="group" aria-labelledby="ce-sep-label">
                            <button type="button" class="ui-segmented__item" data-action="ce.sep" data-sep="semicolon" aria-pressed="true">Semicolon</button>
                            <button type="button" class="ui-segmented__item" data-action="ce.sep" data-sep="comma" aria-pressed="false">Comma</button>
                            <button type="button" class="ui-segmented__item" data-action="ce.sep" data-sep="newline" aria-pressed="false">New line</button>
                        </div>
                    </div>
                    <div class="ui-field mb-0 w-32">
                        <label class="ui-label" for="ce-count">Batches</label>
                        <input class="ui-input ui-num" type="number" id="ce-count" name="count" min="1" max="50" value="4" inputmode="numeric" data-action-input="ce.split">
                    </div>
                    <?= ui_button('Copy all', ['variant' => 'secondary', 'icon' => 'copy', 'attrs' => ['data-action' => 'ce.copy-all']]) ?>
                </form>
            </div>
            <ul class="divide-y divide-line border-t border-line" id="ce-batches" aria-live="polite"></ul>
        </section>
    </div>
<?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
