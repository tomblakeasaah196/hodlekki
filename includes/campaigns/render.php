<?php
// /includes/campaigns/render.php
//
// Block document → email-safe HTML, a plain-text twin, and the list of links.
//
// Email clients are not browsers: Outlook for Windows renders with Word, Gmail
// strips <style> in some views and inverts colours in dark mode. So:
//   - a 600 px centred table layout (role="presentation"), every style inline,
//     MSO "ghost tables" so Outlook keeps the width;
//   - buttons are a padded, coloured table cell around a link (no VML, no images);
//   - system font stack; brand tokens only (ink navy, HOD red, calm neutrals);
//   - colour-scheme light dark, with a small <style> that only ADDS dark-mode
//     colours where a client supports it, and colours that survive inversion;
//   - every picture has a width and an alt text; the preheader is hidden text.
//
// Text is escaped AFTER merge fields are filled in, so a name like
// "<b>Ada</b>" arrives as those characters, never as markup. With a ref, links
// become /email/c.php?r=REF&l=N and the real targets are returned in `links`:
// the redirect will only ever serve those, so it can never be an open redirect.

const EMAIL_COLORS = [
    'page'   => '#F3F4F6',
    'card'   => '#FFFFFF',
    'text'   => '#1F2430',
    'muted'  => '#5B6474',
    'rule'   => '#E3E6EB',
    'navy'   => '#0A0E17',
    'red'    => '#D11920',
    'white'  => '#FFFFFF',
    'tint'   => '#FBF2F2',
];
const EMAIL_FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
const EMAIL_LOGO_PATH = '/assets/pwa/icon-128.png';

function email_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Fill {{fields}}. Unknown fields become empty (validation refuses them earlier). */
function email_merge(string $s, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($vars): string {
        return isset($vars[$m[1]]) && is_scalar($vars[$m[1]]) ? (string) $vars[$m[1]] : '';
    }, $s) ?? $s;
}

/** "Sunday 16 November" for the next $weekday (0 = Sunday … 6), today included. */
function email_next_weekday_words(DateTimeImmutable $from, int $weekday): string
{
    $days = ($weekday - (int) $from->format('w') + 7) % 7;
    return $from->modify("+{$days} days")->format('l j F');
}

/** The merge values for one person. */
function email_merge_vars(array $person, array $extra = []): array
{
    $now = campaigns_now();
    $vars = campaigns_person_names($person) + [
        'email'        => (string) ($person['email'] ?? ''),
        'church_name'  => CAMPAIGNS_CHURCH_NAME,
        'next_sunday'  => email_next_weekday_words($now, 0),
        'next_midweek' => email_next_weekday_words($now, 4),
        'week_number'  => '',
        'weeks_away'   => '',
        'streak'       => '',
        'last_attended' => '',
    ];
    foreach ($extra as $k => $v) {
        if (in_array($k, EMAIL_MERGE_FIELDS, true) && is_scalar($v)) {
            $vars[$k] = (string) $v;
        }
    }
    return $vars;
}

function email_unsubscribe_url(string $ref): string
{
    return site_url('/email/unsubscribe.php?r=' . rawurlencode($ref));
}

function email_click_url(string $ref, int $index): string
{
    return site_url('/email/c.php?r=' . rawurlencode($ref) . '&l=' . $index);
}

function email_open_url(string $ref): string
{
    return site_url('/email/o.php?r=' . rawurlencode($ref));
}

/**
 * Render a document for one person.
 *
 * $ctx: purpose 'news'|'account', ref (the outbox token), track (rewrite links),
 *       track_opens, vars (extra merge values), pdo (for event cards),
 *       event_resolver (callable(int): ?array), preview (bool).
 *
 * @return array{ok:bool, errors:list<string>, subject:string, preheader:string, html:string, text:string, links:list<string>}
 */
function email_render($doc, array $person, array $ctx = []): array
{
    $check = email_doc_validate($doc);
    $empty = ['ok' => false, 'errors' => $check['errors'], 'subject' => '', 'preheader' => '', 'html' => '', 'text' => '', 'links' => []];
    if (!$check['ok']) {
        return $empty;
    }
    $doc = $check['doc'];
    $purpose = ($ctx['purpose'] ?? 'news') === 'account' ? 'account' : 'news';
    $ref = isset($ctx['ref']) && is_string($ctx['ref']) && $ctx['ref'] !== '' ? $ctx['ref'] : null;
    $track = !empty($ctx['track']) && $ref !== null && empty($ctx['preview']);
    $vars = email_merge_vars($person, (array) ($ctx['vars'] ?? []));
    $C = EMAIL_COLORS;
    $F = EMAIL_FONT;

    $links = [];
    $href = static function (string $url) use (&$links, $track, $ref): string {
        if (!$track || !str_starts_with($url, 'https://')) {
            return $url;
        }
        $links[] = $url;
        return email_click_url($ref, count($links) - 1);
    };
    $m = static fn (string $s): string => email_merge($s, $vars);

    $subject = trim(preg_replace('/\s+/', ' ', $m($doc['subject'])) ?? '');
    $preheader = trim($m($doc['preheader']));

    $resolver = $ctx['event_resolver'] ?? null;
    if (!is_callable($resolver)) {
        $pdo = $ctx['pdo'] ?? null;
        $resolver = static fn (int $id): ?array => $pdo instanceof PDO ? email_event_card($pdo, $id) : null;
    }

    $rows = [];   // HTML rows inside the card
    $text = [];   // plain-text paragraphs

    foreach ($doc['blocks'] as $block) {
        switch ($block['type']) {
            case 'heading':
                $size = $block['level'] === 1 ? 26 : 20;
                $tag = $block['level'] === 1 ? 'h1' : 'h2';
                $rows[] = '<' . $tag . ' class="hod-text" style="margin:0 0 16px;font-family:' . $F . ';font-size:' . $size
                    . 'px;line-height:1.25;font-weight:700;color:' . $C['navy'] . ';text-align:' . $block['align'] . ';">'
                    . email_h($m($block['text'])) . '</' . $tag . '>';
                $text[] = $m($block['text']);
                break;

            case 'text':
                [$html, $plain] = email_render_rich($block['content'], $block['align'], $m, $href);
                $rows[] = $html;
                $text[] = $plain;
                break;

            case 'image':
                $img = '<img src="' . email_h($block['src']) . '" width="' . $block['width'] . '" alt="' . email_h($m($block['alt'])) . '"'
                    . ' style="display:block;width:100%;max-width:' . $block['width'] . 'px;height:auto;border:0;outline:none;text-decoration:none;'
                    . ($block['align'] === 'center' ? 'margin:0 auto;' : '') . '">';
                if ($block['href'] !== null) {
                    $img = '<a href="' . email_h($href($block['href'])) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>';
                }
                $rows[] = '<div style="margin:0 0 20px;text-align:' . $block['align'] . ';">' . $img . '</div>';
                if (trim($block['alt']) !== '') {
                    $text[] = '[' . $m($block['alt']) . ']' . ($block['href'] !== null ? ' ' . $block['href'] : '');
                }
                break;

            case 'button':
                $primary = $block['style'] === 'primary';
                $bg = $primary ? $C['red'] : $C['card'];
                $fg = $primary ? $C['white'] : $C['red'];
                $border = $C['red'];
                $tableAlign = $block['align'] === 'center' ? ' align="center"' : '';
                $rows[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"' . $tableAlign . ' class="hod-btn" style="margin:4px 0 24px;'
                    . ($block['align'] === 'center' ? 'margin-left:auto;margin-right:auto;' : '') . '"><tr>'
                    . '<td align="center" bgcolor="' . $bg . '" style="border-radius:8px;background:' . $bg . ';border:2px solid ' . $border . ';padding:12px 26px;">'
                    . '<a href="' . email_h($href($block['href'])) . '" target="_blank" style="display:inline-block;font-family:' . $F
                    . ';font-size:16px;line-height:1.2;font-weight:700;color:' . $fg . ';text-decoration:none;">'
                    . email_h($m($block['label'])) . '</a></td></tr></table>';
                $text[] = $m($block['label']) . ': ' . $block['href'];
                break;

            case 'divider':
                $rows[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;"><tr>'
                    . '<td class="hod-rule" style="border-top:1px solid ' . $C['rule'] . ';font-size:0;line-height:0;height:1px;">&nbsp;</td></tr></table>';
                $text[] = '----';
                break;

            case 'spacer':
                $h = EMAIL_SPACER_SIZES[$block['size']];
                $rows[] = '<div style="height:' . $h . 'px;line-height:' . $h . 'px;font-size:1px;">&nbsp;</div>';
                break;

            case 'scripture':
                $verse = email_scripture($block['ref']);
                if ($verse === null) {
                    break;
                }
                $rows[] = email_render_callout('“' . $verse['text'] . '”', $verse['ref'] . ' (KJV)');
                $text[] = '“' . $verse['text'] . '” — ' . $verse['ref'] . ' (KJV)';
                break;

            case 'quote':
                $rows[] = email_render_callout('“' . $m($block['text']) . '”', $block['cite'] !== '' ? $m($block['cite']) : null);
                $text[] = '“' . $m($block['text']) . '”' . ($block['cite'] !== '' ? ' — ' . $m($block['cite']) : '');
                break;

            case 'event':
                $event = $block['event_id'] > 0 ? $resolver($block['event_id']) : null;
                if ($event === null) {
                    break; // cancelled, unpublished or gone: leave it out rather than mislead
                }
                $rows[] = email_render_event($event, $href);
                $text[] = $event['title'] . "\n" . $event['when'] . ($event['venue'] !== '' ? "\n" . $event['venue'] : '') . "\n" . $event['url'];
                break;

            case 'signature':
                $rows[] = '<p class="hod-text" style="margin:8px 0 4px;font-family:' . $F . ';font-size:16px;line-height:1.5;font-weight:700;color:' . $C['text'] . ';">'
                    . email_h($m($block['name'])) . '</p>'
                    . ($block['line'] !== '' ? '<p class="hod-muted" style="margin:0 0 16px;font-family:' . $F . ';font-size:14px;line-height:1.5;color:' . $C['muted'] . ';">'
                        . email_h($m($block['line'])) . '</p>' : '');
                $text[] = $m($block['name']) . ($block['line'] !== '' ? "\n" . $m($block['line']) : '');
                break;

            case 'footer':
                // Rendered below the card (email_render_footer()).
                break;
        }
    }

    $postal = $ctx['postal_line'] ?? ((($ctx['pdo'] ?? null) instanceof PDO)
        ? email_setting($ctx['pdo'], 'postal_line') : EMAIL_SETTING_DEFAULTS['postal_line']);
    [$footerHtml, $footerText] = email_render_footer($purpose, $ref, (string) $postal);
    $text[] = $footerText;

    $pixel = '';
    if (!empty($ctx['track_opens']) && $ref !== null && empty($ctx['preview'])) {
        $pixel = '<img src="' . email_h(email_open_url($ref)) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
    }

    $html = email_render_shell($subject, $preheader, implode("\n", $rows), $footerHtml, $pixel);

    return [
        'ok'        => true,
        'errors'    => [],
        'subject'   => $subject,
        'preheader' => $preheader,
        'html'      => $html,
        'text'      => trim(implode("\n\n", array_filter($text, static fn ($t) => trim((string) $t) !== ''))) . "\n",
        'links'     => $links,
    ];
}

/** @return array{0:string,1:string} [html, plain] */
function email_render_rich(array $content, string $align, callable $m, callable $href): array
{
    $C = EMAIL_COLORS;
    $F = EMAIL_FONT;
    $runHtml = static function (array $runs) use ($m, $href, $C): string {
        $out = '';
        foreach ($runs as $run) {
            $t = email_h($m($run['t']));
            if (!empty($run['b'])) {
                $t = '<strong>' . $t . '</strong>';
            }
            if (!empty($run['i'])) {
                $t = '<em>' . $t . '</em>';
            }
            if (!empty($run['href'])) {
                $t = '<a href="' . email_h($href($run['href'])) . '" target="_blank" style="color:' . $C['red'] . ';text-decoration:underline;">' . $t . '</a>';
            }
            $out .= $t;
        }
        return $out;
    };
    $runText = static function (array $runs) use ($m): string {
        $out = '';
        foreach ($runs as $run) {
            $t = $m($run['t']);
            if (!empty($run['href']) && !str_starts_with($run['href'], 'mailto:') && !str_starts_with($run['href'], 'tel:')
                && trim($t) !== $run['href']) {
                $t .= ' (' . $run['href'] . ')';
            }
            $out .= $t;
        }
        return trim($out);
    };

    $pStyle = 'margin:0 0 16px;font-family:' . $F . ';font-size:16px;line-height:1.6;color:' . $C['text'] . ';text-align:' . $align . ';';
    $html = [];
    $plain = [];
    foreach ($content as $node) {
        if ($node['k'] === 'p') {
            $html[] = '<p class="hod-text" style="' . $pStyle . '">' . $runHtml($node['r']) . '</p>';
            $plain[] = $runText($node['r']);
            continue;
        }
        $tag = $node['k'];
        $items = [];
        $n = 1;
        foreach ($node['items'] as $runs) {
            $items[] = '<li class="hod-text" style="margin:0 0 6px;font-family:' . $F . ';font-size:16px;line-height:1.6;color:' . $C['text'] . ';">'
                . $runHtml($runs) . '</li>';
            $plain[] = ($tag === 'ol' ? ($n++) . '. ' : '- ') . $runText($runs);
        }
        $html[] = '<' . $tag . ' style="margin:0 0 16px;padding:0 0 0 24px;">' . implode('', $items) . '</' . $tag . '>';
    }
    return [implode("\n", $html), implode("\n", $plain)];
}

function email_render_callout(string $body, ?string $cite): string
{
    $C = EMAIL_COLORS;
    $F = EMAIL_FONT;
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 24px;"><tr>'
        . '<td class="hod-tint" style="border-left:4px solid ' . $C['red'] . ';background:' . $C['tint'] . ';padding:16px 20px;border-radius:0 8px 8px 0;">'
        . '<p class="hod-text" style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:18px;line-height:1.55;font-style:italic;color:' . $C['text'] . ';">'
        . email_h($body) . '</p>'
        . ($cite !== null && $cite !== ''
            ? '<p class="hod-muted" style="margin:8px 0 0;font-family:' . $F . ';font-size:14px;line-height:1.4;font-weight:600;color:' . $C['muted'] . ';">' . email_h($cite) . '</p>'
            : '')
        . '</td></tr></table>';
}

function email_render_event(array $event, callable $href): string
{
    $C = EMAIL_COLORS;
    $F = EMAIL_FONT;
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 24px;"><tr>'
        . '<td class="hod-rule" style="border:1px solid ' . $C['rule'] . ';border-radius:10px;padding:20px;">'
        . '<p class="hod-muted" style="margin:0 0 6px;font-family:' . $F . ';font-size:13px;line-height:1.4;font-weight:700;letter-spacing:0.04em;color:' . $C['red'] . ';">EVENT</p>'
        . '<p class="hod-text" style="margin:0 0 6px;font-family:' . $F . ';font-size:20px;line-height:1.3;font-weight:700;color:' . $C['navy'] . ';">' . email_h($event['title']) . '</p>'
        . ($event['tagline'] !== '' ? '<p class="hod-muted" style="margin:0 0 8px;font-family:' . $F . ';font-size:15px;line-height:1.5;color:' . $C['muted'] . ';">' . email_h($event['tagline']) . '</p>' : '')
        . '<p class="hod-text" style="margin:0 0 4px;font-family:' . $F . ';font-size:15px;line-height:1.5;color:' . $C['text'] . ';">' . email_h($event['when']) . '</p>'
        . ($event['venue'] !== '' ? '<p class="hod-text" style="margin:0 0 14px;font-family:' . $F . ';font-size:15px;line-height:1.5;color:' . $C['text'] . ';">' . email_h($event['venue']) . '</p>' : '')
        . '<a href="' . email_h($href($event['url'])) . '" target="_blank" style="font-family:' . $F . ';font-size:15px;font-weight:700;color:' . $C['red'] . ';text-decoration:underline;">View event</a>'
        . '</td></tr></table>';
}

/** @return array{0:string,1:string} */
function email_render_footer(string $purpose, ?string $ref, string $postal): array
{
    $C = EMAIL_COLORS;
    $F = EMAIL_FONT;
    $postal = trim($postal) !== '' ? trim($postal) : EMAIL_SETTING_DEFAULTS['postal_line'];
    $site = site_url('/');
    $style = 'margin:0 0 8px;font-family:' . $F . ';font-size:13px;line-height:1.6;color:' . $C['muted'] . ';text-align:center;';
    $linkStyle = 'color:' . $C['muted'] . ';text-decoration:underline;';

    $html = '<p class="hod-muted" style="' . $style . '"><strong>' . email_h(CAMPAIGNS_CHURCH_NAME) . '</strong><br>' . email_h($postal)
        . '<br><a href="' . email_h($site) . '" target="_blank" style="' . $linkStyle . '">' . email_h(site_host()) . '</a></p>';
    $text = "--\n" . CAMPAIGNS_CHURCH_NAME . "\n" . $postal . "\n" . $site;

    if ($purpose === 'news') {
        $unsub = $ref !== null ? email_unsubscribe_url($ref) : site_url('/email/unsubscribe.php?r=preview');
        $html .= '<p class="hod-muted" style="' . $style . '">You are receiving this because you agreed to hear from ' . email_h(CAMPAIGNS_CHURCH_NAME)
            . '. <a href="' . email_h($unsub) . '" target="_blank" style="' . $linkStyle . '">Unsubscribe</a></p>';
        $text .= "\n\nYou are receiving this because you agreed to hear from " . CAMPAIGNS_CHURCH_NAME . ".\nUnsubscribe: " . $unsub;
    } else {
        $html .= '<p class="hod-muted" style="' . $style . '">This email is about your church account at ' . email_h(site_host())
            . '. If you did not expect it, you can ignore it.</p>';
        $text .= "\n\nThis email is about your church account at " . site_host() . '. If you did not expect it, you can ignore it.';
    }
    return [$html, $text];
}

function email_render_shell(string $subject, string $preheader, string $body, string $footer, string $pixel): string
{
    $C = EMAIL_COLORS;
    $F = EMAIL_FONT;
    $logo = site_url(EMAIL_LOGO_PATH);
    // Invisible filler after the preheader keeps clients from showing body text there.
    $filler = str_repeat('&#8199;&#65279;&#847; ', 40);

    return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>' . email_h($subject) . '</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
:root{color-scheme:light dark;supported-color-schemes:light dark}
body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
table,td{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt}
img{border:0;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}
@media (max-width:620px){.hod-container{width:100%!important}.hod-pad{padding:28px 20px!important}.hod-btn{width:100%!important}}
@media (prefers-color-scheme:dark){
.hod-page{background:#0A0E17!important}.hod-card{background:#141A26!important}
.hod-text{color:#E8EAF0!important}.hod-muted{color:#A3AAB8!important}
.hod-rule{border-color:#2A3242!important}.hod-tint{background:#1E1416!important}}
[data-ogsc] .hod-text{color:#E8EAF0!important}[data-ogsc] .hod-muted{color:#A3AAB8!important}
</style>
</head>
<body class="hod-page" style="margin:0;padding:0;background:' . $C['page'] . ';">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . email_h($preheader) . $filler . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="hod-page" style="background:' . $C['page'] . ';">
<tr><td align="center" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
<table role="presentation" class="hod-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
<tr><td style="background:' . $C['navy'] . ';border-radius:12px 12px 0 0;padding:18px 28px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td style="padding:0 12px 0 0;"><img src="' . email_h($logo) . '" width="44" height="44" alt="HOD" style="display:block;width:44px;height:44px;border:0;border-radius:22px;"></td>
<td style="font-family:' . $F . ';font-size:17px;line-height:1.3;font-weight:700;color:#FFFFFF;">' . email_h(CAMPAIGNS_CHURCH_NAME) . '</td>
</tr></table>
</td></tr>
<tr><td class="hod-card hod-pad" style="background:' . $C['card'] . ';border-radius:0 0 12px 12px;padding:36px 40px 20px;">
' . $body . '
</td></tr>
<tr><td style="padding:24px 28px 8px;">
' . $footer . '
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
' . $pixel . '
</body>
</html>
';
}

/**
 * A published Special Event for the event block, or null (draft, cancelled,
 * archived, past, missing, or Special Events not installed).
 *
 * @return array{title:string, tagline:string, when:string, venue:string, url:string}|null
 */
function email_event_card(PDO $pdo, int $eventId): ?array
{
    if ($eventId <= 0 || !campaigns_table_exists($pdo, 'se_events')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT slug, title, edition_label, tagline, venue_name, starts_at, ends_at, status
                                 FROM se_events WHERE id = ?");
        $stmt->execute([$eventId]);
        $e = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        error_log('[email] event card ' . $eventId . ': ' . $ex->getMessage());
        return null;
    }
    if (!$e || $e['status'] !== 'published') {
        return null;
    }
    $start = campaigns_dt((string) $e['starts_at']);
    if (campaigns_dt((string) $e['ends_at']) < campaigns_now()) {
        return null;
    }
    $title = trim((string) $e['title'] . ' ' . (string) ($e['edition_label'] ?? ''));
    return [
        'title'   => $title,
        'tagline' => trim((string) ($e['tagline'] ?? '')),
        'when'    => $start->format('l j F') . ' · ' . ltrim($start->format('g:i A'), '0'),
        'venue'   => trim((string) ($e['venue_name'] ?? '')),
        'url'     => site_url('/e/' . rawurlencode((string) $e['slug'])),
    ];
}
