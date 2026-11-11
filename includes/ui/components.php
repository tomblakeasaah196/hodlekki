<?php
// /includes/ui/components.php
/**
 * PHP helpers for the shared components (docs/UI.md). Each returns a string
 * of markup; echo it. They exist where markup repeats and must not drift —
 * a page can always write the same classes by hand.
 *
 * Class names are written out in full (never built as 'ui-btn--' . $x):
 * Tailwind finds the classes to keep by scanning this file, and a name
 * assembled at runtime would be missing from the stylesheet.
 */

if (defined('HOD_UI_COMPONENTS')) {
    return;
}
define('HOD_UI_COMPONENTS', true);

/**
 * A button, or a link styled as one.
 *
 *   ui_button('Save changes', ['variant' => 'primary', 'type' => 'submit'])
 *   ui_button('Add member', ['variant' => 'primary', 'icon' => 'plus', 'href' => '?new=1'])
 *   ui_button('Delete', ['variant' => 'danger-ghost', 'icon' => 'trash-2',
 *       'icon_only' => true, 'attrs' => ['data-action' => 'member.delete', 'data-id' => 7]])
 *
 * Options: variant (primary|secondary|ghost|soft|danger|danger-ghost|brand),
 * size (sm|md|lg), icon, icon_end, icon_only (label becomes the accessible
 * name and the tooltip), href, type (button|submit|reset), block, class,
 * attrs, disabled.
 */
function ui_button(string $label, array $o = []): string
{
    $variants = [
        'primary' => 'ui-btn--primary',
        'secondary' => 'ui-btn--secondary',
        'ghost' => 'ui-btn--ghost',
        'soft' => 'ui-btn--soft',
        'danger' => 'ui-btn--danger',
        'danger-ghost' => 'ui-btn--danger-ghost',
        'brand' => 'ui-btn--brand',
    ];
    $sizes = ['sm' => 'ui-btn--sm', 'md' => '', 'lg' => 'ui-btn--lg'];

    $classes = ['ui-btn', $variants[$o['variant'] ?? 'secondary'] ?? 'ui-btn--secondary', $sizes[$o['size'] ?? 'md'] ?? ''];
    $iconOnly = !empty($o['icon_only']);
    if ($iconOnly) {
        $classes[] = 'ui-btn--icon';
    }
    if (!empty($o['block'])) {
        $classes[] = 'ui-btn--block';
    }
    if (!empty($o['class'])) {
        $classes[] = $o['class'];
    }

    $attrs = (array) ($o['attrs'] ?? []);
    if ($iconOnly) {
        $attrs['aria-label'] = $attrs['aria-label'] ?? $label;
        $attrs['data-tooltip'] = $attrs['data-tooltip'] ?? $label;
    }

    $inner = '';
    if (!empty($o['icon'])) {
        $inner .= ui_icon((string) $o['icon']);
    }
    if (!$iconOnly) {
        $inner .= '<span>' . ui_e($label) . '</span>';
    }
    if (!empty($o['icon_end']) && !$iconOnly) {
        $inner .= ui_icon((string) $o['icon_end']);
    }

    $class = trim(implode(' ', array_filter($classes)));
    if (!empty($o['href'])) {
        $attrs = ['href' => $o['href'], 'class' => $class] + $attrs;
        if (!empty($o['disabled'])) {
            $attrs['aria-disabled'] = 'true';
            $attrs['tabindex'] = '-1';
        }

        return '<a' . ui_attrs($attrs) . '>' . $inner . '</a>';
    }

    $attrs = ['type' => $o['type'] ?? 'button', 'class' => $class] + $attrs;
    if (!empty($o['disabled'])) {
        $attrs['disabled'] = true;
    }

    return '<button' . ui_attrs($attrs) . '>' . $inner . '</button>';
}

/** A small status label. Tones: neutral|primary|success|warning|danger|info|brand. */
function ui_badge(string $label, string $tone = 'neutral', array $o = []): string
{
    $tones = [
        'neutral' => '',
        'primary' => 'ui-badge--primary',
        'success' => 'ui-badge--success',
        'warning' => 'ui-badge--warning',
        'danger' => 'ui-badge--danger',
        'info' => 'ui-badge--info',
        'brand' => 'ui-badge--brand',
    ];
    $classes = ['ui-badge', $tones[$tone] ?? ''];
    if (!empty($o['dot'])) {
        $classes[] = 'ui-badge--dot';
    }
    if (!empty($o['round'])) {
        $classes[] = 'ui-badge--round';
    }
    if (!empty($o['class'])) {
        $classes[] = $o['class'];
    }
    $icon = !empty($o['icon']) ? ui_icon((string) $o['icon']) : '';

    return '<span class="' . ui_e(trim(implode(' ', array_filter($classes)))) . '">' . $icon . ui_e($label) . '</span>';
}

/** A person's avatar: their photo, or initials on a colour that is always theirs. */
function ui_avatar(string $name, ?string $imageUrl = null, string $size = 'md', array $o = []): string
{
    $sizes = ['xs' => 'ui-avatar--xs', 'sm' => 'ui-avatar--sm', 'md' => '', 'lg' => 'ui-avatar--lg', 'xl' => 'ui-avatar--xl'];
    $classes = ['ui-avatar', $sizes[$size] ?? '', ui_avatar_tone($name)];
    if (!empty($o['class'])) {
        $classes[] = $o['class'];
    }
    $class = ui_e(trim(implode(' ', array_filter($classes))));

    if ($imageUrl !== null && $imageUrl !== '') {
        return '<span class="' . $class . '"><img src="' . ui_e($imageUrl) . '" alt="" loading="lazy" decoding="async"></span>';
    }

    return '<span class="' . $class . '" aria-hidden="true">' . ui_e(ui_initials($name)) . '</span>';
}

/**
 * The page's title block. Options: title (required), description, actions
 * (HTML, usually ui_button() calls), back => ['href' => …, 'label' => …].
 * The phone app bar shows the title only once this block scrolls away.
 */
function ui_page_header(array $o): string
{
    $html = '<header class="ui-page-header" data-ui-page-header><div class="ui-page-header__main">';
    if (!empty($o['back']['href'])) {
        $html .= '<a class="ui-backlink" href="' . ui_e($o['back']['href']) . '">' . ui_icon('chevron-left')
            . '<span>' . ui_e($o['back']['label'] ?? 'Back') . '</span></a>';
    }
    $html .= '<h1 class="ui-h1">' . ui_e($o['title'] ?? '') . '</h1>';
    if (!empty($o['description'])) {
        $html .= '<p class="ui-page-header__description">' . ui_e($o['description']) . '</p>';
    }
    $html .= '</div>';
    if (!empty($o['actions'])) {
        $html .= '<div class="ui-page-header__actions">' . $o['actions'] . '</div>';
    }

    return $html . '</header>';
}

/**
 * An empty or filtered-to-nothing state. Always say what to do next.
 * Options: icon, title, text, actions (HTML), compact.
 */
function ui_empty(array $o): string
{
    $class = !empty($o['compact']) ? 'ui-empty ui-empty--compact' : 'ui-empty';
    $html = '<div class="' . $class . '">';
    $html .= '<div class="ui-empty__icon">' . ui_icon((string) ($o['icon'] ?? 'inbox')) . '</div>';
    $html .= '<p class="ui-empty__title">' . ui_e($o['title'] ?? 'Nothing here yet') . '</p>';
    if (!empty($o['text'])) {
        $html .= '<p class="ui-empty__text">' . ui_e($o['text']) . '</p>';
    }
    if (!empty($o['actions'])) {
        $html .= '<div class="ui-empty__actions">' . $o['actions'] . '</div>';
    }

    return $html . '</div>';
}

/**
 * A KPI tile. Options: label, value, icon, meta (text), delta (number, %),
 * delta_label ("vs last week"), href (makes the whole tile a link).
 */
function ui_stat(array $o): string
{
    $inner = '<span class="ui-stat__label">' . (!empty($o['icon']) ? ui_icon((string) $o['icon']) : '')
        . '<span>' . ui_e($o['label'] ?? '') . '</span></span>';
    $inner .= '<span class="ui-stat__value">' . ui_e($o['value'] ?? '—') . '</span>';

    $meta = '';
    if (isset($o['delta']) && $o['delta'] !== null && $o['delta'] !== '') {
        $d = (float) $o['delta'];
        [$cls, $icon] = $d > 0 ? ['ui-delta ui-delta--up', 'trending-up'] : ($d < 0 ? ['ui-delta ui-delta--down', 'trending-down'] : ['ui-delta ui-delta--flat', 'minus']);
        $meta .= '<span class="' . $cls . '">' . ui_icon($icon) . ui_e(($d > 0 ? '+' : '') . rtrim(rtrim(number_format($d, 1), '0'), '.') . '%') . '</span>';
        if (!empty($o['delta_label'])) {
            $meta .= '<span>' . ui_e($o['delta_label']) . '</span>';
        }
    }
    if (!empty($o['meta'])) {
        $meta .= '<span>' . ui_e($o['meta']) . '</span>';
    }
    if ($meta !== '') {
        $inner .= '<span class="ui-stat__meta">' . $meta . '</span>';
    }

    if (!empty($o['href'])) {
        return '<a class="ui-card ui-stat" href="' . ui_e($o['href']) . '">' . $inner . '</a>';
    }

    return '<div class="ui-card ui-stat">' . $inner . '</div>';
}

/** An inline message. Tones: info|success|warning|danger|neutral. Options: title, text, actions (HTML). */
function ui_banner(string $tone, array $o): string
{
    $map = [
        'info' => ['ui-banner ui-banner--info', 'info'],
        'success' => ['ui-banner ui-banner--success', 'circle-check'],
        'warning' => ['ui-banner ui-banner--warning', 'triangle-alert'],
        'danger' => ['ui-banner ui-banner--danger', 'circle-alert'],
        'neutral' => ['ui-banner', 'info'],
    ];
    [$cls, $icon] = $map[$tone] ?? $map['neutral'];
    $role = in_array($tone, ['danger', 'warning'], true) ? 'alert' : 'status';

    $html = '<div class="' . $cls . '" role="' . $role . '">' . ui_icon((string) ($o['icon'] ?? $icon)) . '<div class="ui-banner__body">';
    if (!empty($o['title'])) {
        $html .= '<p class="ui-banner__title">' . ui_e($o['title']) . '</p>';
    }
    if (!empty($o['text'])) {
        $html .= '<p class="ui-banner__text">' . ui_e($o['text']) . '</p>';
    }
    if (!empty($o['actions'])) {
        $html .= '<div class="ui-banner__actions">' . $o['actions'] . '</div>';
    }

    return $html . '</div></div>';
}

/**
 * A labelled form control. Types: text, email, tel, number, date, time,
 * password, search, url, textarea, select. Options: name (required), label
 * (required), id, value, hint, error, required, placeholder (a format hint,
 * never the label), options (select: value => label), attrs (on the control),
 * class (on the field wrapper), autocomplete, inputmode.
 */
function ui_field(string $type, array $o): string
{
    static $seq = 0;
    $name = (string) ($o['name'] ?? '');
    $id = (string) ($o['id'] ?? ('f-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name) . '-' . (++$seq)));
    $hintId = $id . '-hint';
    $errorId = $id . '-error';
    $required = !empty($o['required']);

    $label = '<label class="ui-label" for="' . ui_e($id) . '">' . ui_e($o['label'] ?? $name)
        . ($required ? '<span class="ui-label__req" aria-hidden="true">*</span>' : '')
        . (!empty($o['optional']) ? '<span class="ui-label__opt">(optional)</span>' : '')
        . '</label>';

    $describedBy = [];
    if (!empty($o['hint'])) {
        $describedBy[] = $hintId;
    }
    $describedBy[] = $errorId;

    $attrs = [
        'id' => $id,
        'name' => $name,
        'required' => $required,
        'placeholder' => $o['placeholder'] ?? null,
        'autocomplete' => $o['autocomplete'] ?? null,
        'inputmode' => $o['inputmode'] ?? null,
        'aria-describedby' => implode(' ', $describedBy),
        'aria-invalid' => !empty($o['error']) ? 'true' : null,
    ] + (array) ($o['attrs'] ?? []);

    $value = $o['value'] ?? '';
    if ($type === 'textarea') {
        $control = '<textarea' . ui_attrs(['class' => 'ui-textarea'] + $attrs) . '>' . ui_e($value) . '</textarea>';
    } elseif ($type === 'select') {
        $opts = '';
        foreach ((array) ($o['options'] ?? []) as $optValue => $optLabel) {
            $selected = (string) $optValue === (string) $value ? ' selected' : '';
            $opts .= '<option value="' . ui_e($optValue) . '"' . $selected . '>' . ui_e($optLabel) . '</option>';
        }
        $control = '<select' . ui_attrs(['class' => 'ui-select'] + $attrs) . '>' . $opts . '</select>';
    } else {
        $control = '<input' . ui_attrs(['class' => 'ui-input', 'type' => $type, 'value' => $value] + $attrs) . '>';
    }

    $html = '<div class="' . ui_e(trim('ui-field ' . ($o['class'] ?? ''))) . '">' . $label . $control;
    if (!empty($o['hint'])) {
        $html .= '<p class="ui-hint" id="' . ui_e($hintId) . '">' . ui_e($o['hint']) . '</p>';
    }
    $html .= '<p class="ui-field-error" id="' . ui_e($errorId) . '" aria-live="polite">' . ui_e($o['error'] ?? '') . '</p>';

    return $html . '</div>';
}

/**
 * Opens a dialog (native <dialog>). Close it with ui_dialog_end(). Open it
 * from markup with data-dialog-open="<id>", from script with HOD.dialog.open(id).
 * Options: title (required), description, size (sm|md|lg|xl|drawer), class.
 * Ids must NOT contain "modal": modal-manager.js would treat it as a legacy one.
 */
function ui_dialog_start(string $id, array $o): string
{
    $sizes = ['sm' => 'ui-dialog--sm', 'md' => '', 'lg' => 'ui-dialog--lg', 'xl' => 'ui-dialog--xl', 'drawer' => 'ui-dialog--drawer'];
    $class = trim('ui-dialog ' . ($sizes[$o['size'] ?? 'md'] ?? '') . ' ' . ($o['class'] ?? ''));
    $titleId = $id . '-title';
    $descId = $id . '-desc';

    $html = '<dialog id="' . ui_e($id) . '" class="' . ui_e($class) . '" aria-labelledby="' . ui_e($titleId) . '"'
        . (!empty($o['description']) ? ' aria-describedby="' . ui_e($descId) . '"' : '') . ' data-modal-ignore>';
    $html .= '<div class="ui-dialog__header"><div class="ui-dialog__heading">'
        . '<h2 class="ui-dialog__title" id="' . ui_e($titleId) . '">' . ui_e($o['title'] ?? '') . '</h2>';
    if (!empty($o['description'])) {
        $html .= '<p class="ui-dialog__description" id="' . ui_e($descId) . '">' . ui_e($o['description']) . '</p>';
    }
    $html .= '</div>' . ui_button('Close', ['variant' => 'ghost', 'icon' => 'x', 'icon_only' => true, 'class' => 'ui-dialog__close', 'attrs' => ['data-dialog-close' => true, 'data-tooltip' => null]]) . '</div>';

    return $html . '<div class="ui-dialog__body">';
}

/** Closes ui_dialog_start(); $footer is the action row's HTML (optional). */
function ui_dialog_end(string $footer = ''): string
{
    $html = '</div>';
    if ($footer !== '') {
        $html .= '<div class="ui-dialog__footer">' . $footer . '</div>';
    }

    return $html . '</dialog>';
}

/**
 * A tab bar. $tabs is id => label (or id => ['label' => …, 'count' => n,
 * 'icon' => …]). Panels are elements with data-tab-panel="<id>" and the
 * shared deep link #tab=<id> works out of the box.
 */
function ui_tabs(array $tabs, string $active, string $label = 'Sections'): string
{
    $html = '<div class="ui-tabs" role="tablist" aria-label="' . ui_e($label) . '" data-ui-tabs>';
    foreach ($tabs as $id => $tab) {
        $tab = is_array($tab) ? $tab : ['label' => $tab];
        $selected = (string) $id === $active;
        $html .= '<button type="button" class="ui-tab" role="tab" id="tab-' . ui_e($id) . '" data-tab="' . ui_e($id) . '"'
            . ' aria-controls="panel-' . ui_e($id) . '" aria-selected="' . ($selected ? 'true' : 'false') . '"'
            . ' tabindex="' . ($selected ? '0' : '-1') . '">'
            . (!empty($tab['icon']) ? ui_icon((string) $tab['icon']) : '')
            . '<span>' . ui_e($tab['label'] ?? $id) . '</span>'
            . (isset($tab['count']) ? '<span class="ui-count">' . ui_e($tab['count']) . '</span>' : '')
            . '</button>';
    }

    return $html . '</div>';
}

/** Hidden CSRF input for a classic form post (fetch/jQuery get the header automatically). */
function ui_csrf_field(): string
{
    if (!function_exists('csrf_token')) {
        require_once dirname(__DIR__) . '/csrf.php';
    }

    return '<input type="hidden" name="_csrf" value="' . ui_e(csrf_token()) . '">';
}
