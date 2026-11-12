<?php
// /includes/trainings/view.php
//
// Markup shared by the learner's Trainings page (modules/trainings/) and the
// Academy's builder (modules/academy/training.php): item icons, dates in
// words, and the players. Every value is escaped (ui_e); embeds come only
// from trainings_media_parse() (YouTube no-cookie and Spotify), which the
// pages allow in frame-src (TRAININGS_EMBED_HOSTS) and nowhere else.

const TRAININGS_TYPE_ICONS = ['live' => 'users-round', 'video' => 'video', 'audio' => 'music', 'reading' => 'book-open',
    'assignment' => 'pencil', 'exam' => 'clipboard-list'];

function trainings_view_icon(string $type): string
{
    return TRAININGS_TYPE_ICONS[$type] ?? 'file-text';
}

/** "Sat 14 Dec" */
function trainings_view_date(?string $ymd, bool $weekday = true): string
{
    if ($ymd === null || $ymd === '') {
        return '';
    }
    $d = new DateTimeImmutable(substr($ymd, 0, 10), new DateTimeZone(TRAININGS_TZ));
    return $d->format($weekday ? 'D j M' : 'j M Y');
}

/** "14 – 20 Dec", "Sat 14 Dec" */
function trainings_view_range(?string $from, ?string $to): string
{
    if ($from === null || $from === '') {
        return '';
    }
    if ($to === null || $to === '' || $to === $from) {
        return trainings_view_date($from);
    }
    $a = new DateTimeImmutable($from, new DateTimeZone(TRAININGS_TZ));
    $b = new DateTimeImmutable($to, new DateTimeZone(TRAININGS_TZ));
    return $a->format('M') === $b->format('M') ? $a->format('j') . ' – ' . $b->format('j M') : $a->format('j M') . ' – ' . $b->format('j M');
}

/** "Sat 14 Dec, 09:00 · 90 min" (the parts that are known). */
function trainings_view_when(?string $startsAt, ?int $minutes): string
{
    $parts = [];
    if ($startsAt !== null && $startsAt !== '') {
        $d = new DateTimeImmutable($startsAt, new DateTimeZone(TRAININGS_TZ));
        $parts[] = $d->format('D j M, H:i');
    }
    if ($minutes !== null && $minutes > 0) {
        $parts[] = $minutes >= 60 && $minutes % 60 === 0 ? ($minutes / 60) . ' h' : $minutes . ' min';
    }
    return implode(' · ', $parts);
}

/** The player for a learner item's media (embed, or an uploaded audio file). */
function trainings_view_media(?array $media, string $title): string
{
    if ($media === null) {
        return '';
    }
    if (($media['provider'] ?? '') === 'file' && !empty($media['file'])) {
        return '<audio class="w-full" controls preload="none" src="' . ui_e($media['file']) . '"></audio>';
    }
    $embed = (string) ($media['embed'] ?? '');
    $ok = false;
    foreach (TRAININGS_EMBED_HOSTS as $host) {
        $ok = $ok || str_starts_with($embed, $host . '/');
    }
    if (!$ok) {
        return '';
    }
    $spotify = ($media['provider'] ?? '') === 'spotify';
    $class = $spotify ? 'w-full h-40 rounded-xl border-0' : 'w-full aspect-video rounded-xl border-0 bg-surface-sunken';
    return '<iframe class="' . $class . '" src="' . ui_e($embed) . '" title="' . ui_e($title) . '" loading="lazy"'
        . ' allow="encrypted-media; picture-in-picture; fullscreen; clipboard-write" allowfullscreen'
        . ' referrerpolicy="strict-origin-when-cross-origin"></iframe>';
}

/** A reading's text: paragraphs, escaped. */
function trainings_view_text(string $text): string
{
    $out = '';
    foreach (preg_split("/\n{2,}/", trim($text)) ?: [] as $para) {
        if (trim($para) !== '') {
            $out .= '<p>' . nl2br(ui_e(trim($para)), false) . '</p>';
        }
    }
    return $out !== '' ? '<div class="ui-prose">' . $out . '</div>' : '';
}

/** A learner's status for one item, as a badge. */
function trainings_view_item_badge(array $item): string
{
    $p = $item['progress'] ?? null;
    if ($p === null) {
        return $item['ready'] ? '' : ui_badge('Coming soon', 'neutral');
    }
    return match ($p['status']) {
        'completed' => $item['type'] === 'exam' && isset($p['passed']) && !$p['passed']
            ? ui_badge('Not passed', 'warning', ['dot' => true]) : ui_badge('Done', 'success', ['dot' => true]),
        'submitted' => ui_badge('Handed in', 'info', ['dot' => true]),
        'returned' => ui_badge('Try again', 'warning', ['dot' => true]),
        default => '',
    };
}
