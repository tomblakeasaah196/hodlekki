<?php
// /includes/campaigns/profile_card.php
//
// The Profile "Your email" card: its HTML (on the foundation components, short
// copy, details behind ui_info()) and its four actions. modules/profile/index.php
// prints it; api/profile_api.php runs the actions and answers with the card
// again, which assets/ui/js/pages/profile.js swaps in. Not loaded by
// bootstrap.php: only Profile needs the UI components.
//
//   confirmed   the address + "Confirmed"; "Change email" opens the form
//   pending     the address + "Waiting"; Resend, or drop it
//   neither     the form, offering an address on file
//   + the church-news switch once there is an address

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/ui/bootstrap.php';

/**
 * The card's HTML. $o: error (shown on the email field), typed (the field's
 * value after an error), open (the form starts open).
 */
function email_profile_card_html(array $view, array $o = []): string
{
    $html = '<section class="ui-card mb-6" id="profile-email-card" aria-labelledby="profile-email">'
        . '<div class="ui-card__header ui-card__header--divided"><div class="ui-title-row">'
        . '<h2 class="ui-card__title" id="profile-email">Your email</h2>'
        . ui_info('For sign-in, password resets and church news. Use your own, like Gmail or Yahoo, not a church one.', 'About your email')
        . '</div></div><div class="ui-card__body ui-stack">';

    if (empty($view['ready'])) {
        return $html . '<p class="ui-meta">Email is being set up.</p></div></section>';
    }

    $confirmed = $view['confirmed'] ?? null;
    $pending = $view['pending'] ?? null;
    $address = static fn (string $email, string $badge, string $tone): string =>
        '<div class="flex flex-wrap items-center gap-2"><span class="font-medium text-ink break-all">' . ui_e($email) . '</span>'
        . ui_badge($badge, $tone) . '</div>';
    $act = static fn (string $do, string $label, string $variant): string =>
        ui_button($label, ['variant' => $variant, 'size' => 'sm', 'attrs' => ['data-action' => 'profile.email', 'data-do' => $do]]);

    if ($confirmed !== null) {
        $html .= $address($confirmed, 'Confirmed', 'success');
        if (!empty($view['suppressed'])) {
            $html .= ui_banner('warning', ['title' => 'Emails are not arriving', 'text' => 'Use another email.',
                'info' => 'Mail to this address bounced or was marked as spam, so we stopped sending to it.']);
        }
    }
    if ($pending !== null) {
        $html .= $address($pending, $confirmed !== null ? 'New, waiting' : 'Waiting', 'warning')
            . '<p class="ui-meta">Tap the link we sent. '
            . ui_info('Look in Spam or Promotions too. The link works for 7 days. Until then we use your confirmed email, if you have one.', 'About the link')
            . '</p><div class="flex flex-wrap gap-2">'
            . $act('email_resend', 'Resend', 'secondary')
            . $act('email_cancel', $confirmed !== null ? 'Keep current' : 'Wrong email?', 'ghost')
            . '</div>';
    }

    if ($pending === null) {
        $open = $confirmed === null || !empty($o['open']) || !empty($o['error']);
        $value = (string) ($o['typed'] ?? ($confirmed === null ? ($view['suggest'] ?? '') : ''));
        $html .= '<form class="ui-form" data-action="profile.email-save" data-profile-email-form novalidate' . ($open ? '' : ' hidden') . '>'
            . ui_field('email', [
                'name' => 'email',
                'id' => 'profile-email-input',
                'label' => $confirmed !== null ? 'New email' : 'Email',
                'value' => $value,
                'required' => true,
                'autocomplete' => 'email',
                'inputmode' => 'email',
                'error' => $o['error'] ?? null,
                'info' => $confirmed === null && !isset($o['typed']) && !empty($view['suggest'])
                    ? 'The email we have on file. Change it if it is not yours.' : null,
            ])
            . '<div class="ui-form-actions sm:justify-start">'
            . ui_button('Send link', ['variant' => 'primary', 'type' => 'submit'])
            . ($confirmed !== null ? ui_button('Cancel', ['variant' => 'ghost', 'attrs' => ['data-action' => 'profile.email-close']]) : '')
            . '</div></form>';
        if ($confirmed !== null) {
            $html .= '<div' . ($open ? ' hidden' : '') . ' data-profile-email-change>'
                . ui_button('Change email', ['variant' => 'secondary', 'size' => 'sm', 'attrs' => ['data-action' => 'profile.email-open']])
                . '</div>';
        }
    }

    if ($confirmed !== null || $pending !== null) {
        $html .= '<hr class="ui-divider my-0"><div class="flex items-center gap-2">'
            . '<label class="ui-check items-center"><input type="checkbox" class="ui-switch" name="news" value="1"'
            . ' data-action-change="profile.email-news"' . (!empty($view['news']) ? ' checked' : '') . '><span>Church news</span></label>'
            . ui_info(EMAIL_PROFILE_NEWS_DETAILS, 'About church news')
            . '</div>';
    }

    return $html . '</div></section>';
}

/**
 * One of the card's actions for the signed-in person (never anyone else).
 * $do: email_card | email_save | email_resend | email_cancel | email_news.
 * $in: the POST fields (email, on).
 *
 * @return array{status:string, message?:string, field?:string, html?:string, news?:bool}
 */
function email_profile_action(PDO $pdo, int $userId, string $do, array $in, ?string $ip = null, ?string $userAgent = null): array
{
    $card = static fn (array $o = []): string => email_profile_card_html(email_profile_view($pdo, $userId), $o);
    if (!campaigns_ready($pdo)) {
        return ['status' => 'error', 'message' => 'Email is being set up. Try again later.'];
    }
    switch ($do) {
        case 'email_card':
            return ['status' => 'success', 'html' => $card()];

        case 'email_save':
        case 'email_resend':
            $email = $do === 'email_resend'
                ? (string) (email_address_state($pdo, $userId)['pending'] ?? '')
                : trim((string) ($in['email'] ?? ''));
            if ($email === '') {
                return $do === 'email_resend'
                    ? ['status' => 'error', 'message' => 'No link is waiting.', 'html' => $card()]
                    : ['status' => 'error', 'message' => 'Enter your email.', 'field' => 'email'];
            }
            $r = email_capture_start($pdo, $userId, $email, 'profile', $userId, ['ip' => $ip, 'user_agent' => $userAgent]);
            if (empty($r['ok'])) {
                return $do === 'email_save'
                    ? ['status' => 'error', 'message' => (string) ($r['message'] ?? 'Please try again.'), 'field' => 'email']
                    : ['status' => 'error', 'message' => (string) ($r['message'] ?? 'Please try again.')];
            }
            email_prompt_unskip($pdo, $userId);
            email_prompt_forget();
            return ['status' => 'success', 'html' => $card(),
                    'message' => !empty($r['already_confirmed']) ? 'Already confirmed.' : 'Link sent.'];

        case 'email_cancel':
            email_capture_cancel($pdo, $userId);
            email_prompt_forget();
            return ['status' => 'success', 'html' => $card(), 'message' => 'Link cancelled.'];

        case 'email_news':
            $on = !empty($in['on']) && (string) $in['on'] !== '0';
            $r = email_profile_set_news($pdo, $userId, $on, $ip, $userAgent);
            if (empty($r['ok'])) {
                return ['status' => 'error', 'message' => (string) ($r['message'] ?? 'Please try again.'), 'html' => $card()];
            }
            return ['status' => 'success', 'news' => $on, 'message' => $on ? 'Church news on.' : 'Church news off.'];
    }
    return ['status' => 'error', 'message' => 'Unknown action.'];
}
