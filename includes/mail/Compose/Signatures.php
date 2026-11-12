<?php

namespace HOD\Mail\Compose;

use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailError;
use HOD\Mail\MailSettings;
use HOD\Mail\People;
use Throwable;

/**
 * Email signatures: one church design (ported from Courrier), built from the person's name, their place in the
 * mailbox's department, their numbers, the church's logo and its details. A person edits only their own numbers
 * (mail_signatures; until they do, the phone on their profile shows); mail admins edit the church part
 * (mail_settings `signature.church`). Added when the message is built (MessageBuilder), never inside the editor:
 * 'full' on new mail and forwards, 'short' on replies, or 'none'.
 *
 * The address shown is the mailbox the message goes from: on a shared mailbox, the person and the shared address
 * (From is the mailbox; who wrote it shows in their signature).
 *
 * Mail apps ignore style sheets and SVG, and many drop CSS layout, so the design is tables, inline styles and
 * literal colours (the church's navy and red), a PNG logo at three times its size with width and height set
 * (sharp on phones, right size in Outlook), and a plain-text twin.
 */
final class Signatures
{
    public const STYLES = ['full' => 'Full', 'slim' => 'Short', 'none' => 'None'];

    /** Digits, spaces, brackets, dots and dashes, with an optional + in front */
    public const PHONE = '/^\+?[0-9][0-9 ().\-]{4,22}[0-9]$/';

    private const FONT = "font-family:'Segoe UI','Helvetica Neue',Helvetica,Arial,sans-serif;";

    private const INK = '#101828';

    private const SOFT = '#475467';

    private const MUTED = '#667085';

    private const LINE = '#E4E7EC';

    private const BRAND = '#26437F';   // navy-700: readable as text and on white

    private const ACCENT = '#D11920';  // hodRed

    private static array $people = [];

    // ------------------------------------------------------------------ the church

    /**
     * The church part as the signature shows it.
     *
     * @return array{name: string, tagline: string, address: string, phone: string, website: string, logo_url: string, show_logo: bool, custom: bool}
     */
    public static function church(): array
    {
        $defaults = self::churchDefaults();
        $saved = MailSettings::get('signature.church');
        if (!is_array($saved)) {
            return $defaults + ['custom' => false];
        }
        $out = $defaults;
        foreach (['name', 'tagline', 'address', 'phone', 'website'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = trim((string) $saved[$k]);
            }
        }
        // A logo address replaces the church logo; none given keeps it (hiding it is show_logo)
        if (trim((string) ($saved['logo_url'] ?? '')) !== '') {
            $out['logo_url'] = trim((string) $saved['logo_url']);
        }
        if (array_key_exists('show_logo', $saved)) {
            $out['show_logo'] = (bool) $saved['show_logo'];
        }
        if ($out['name'] === '') {
            $out['name'] = $defaults['name'];
        }

        return $out + ['custom' => true];
    }

    /** Before any change made by a mail admin: the church's sending name, its site and its logo */
    public static function churchDefaults(): array
    {
        $env = static fn (string $key, string $default): string => function_exists('site_env') && site_env($key) !== '' ? (string) site_env($key) : $default;
        $site = function_exists('site_url') ? site_url() : 'https://' . Config::get('domain', 'hodlekki.org');
        $logo = function_exists('site_url') ? site_url((string) ComposeConfig::get('logo_path', '')) : '';

        return [
            'name' => $env('MAIL_FROM_NAME', 'Household of David Lekki'),
            'tagline' => '',
            'address' => '',
            'phone' => '',
            'website' => self::website($site),
            'logo_url' => preg_match('#^https://#i', $logo) ? $logo : '',
            'show_logo' => true,
        ];
    }

    /** Mail admins save the church part (checked by the caller). Throws MailError on a field. @return array as church() */
    public static function setChurch(array $values, int $byUserId): array
    {
        MailSettings::set('signature.church', self::checkChurch($values), $byUserId);

        return self::church();
    }

    /** Back to the defaults */
    public static function resetChurch(): void
    {
        MailSettings::forget('signature.church');
    }

    /** The church values from a form, checked and tidied. Throws MailError on the first wrong field. */
    public static function checkChurch(array $values): array
    {
        $str = fn (string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($values[$k] ?? ''))), 0, $max);
        $data = [
            'name' => $str('name', 80),
            'tagline' => $str('tagline', 120),
            'address' => $str('address', 160),
            'phone' => $str('phone', 30),
            'website' => $str('website', 120),
            'logo_url' => $str('logo_url', 300),
            'show_logo' => filter_var($values['show_logo'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        if ($data['name'] === '') {
            throw MailError::field('name', 'Type the church\'s name.');
        }
        if ($data['phone'] !== '' && !preg_match(self::PHONE, $data['phone'])) {
            throw MailError::field('phone', 'Type the number with digits and spaces, like +234 801 234 5678.');
        }
        if ($data['website'] !== '') {
            $site = self::website($data['website']);
            if ($site === '') {
                throw MailError::field('website', 'Type the site\'s address, like https://hodlekki.org.');
            }
            $data['website'] = $site;
        }
        if ($data['logo_url'] !== '' && (!preg_match('#^https://[^\s"<>]+$#i', $data['logo_url']) || !filter_var($data['logo_url'], FILTER_VALIDATE_URL))) {
            throw MailError::field('logo_url', 'Type the logo\'s full https:// address (a PNG or JPG).');
        }

        return $data;
    }

    // ------------------------------------------------------------------ the person

    /** @return array{phone: ?string, mobile: ?string, saved: bool} the person's own numbers */
    public static function numbers(int $userId): array
    {
        $row = Db::one('SELECT phone, mobile FROM mail_signatures WHERE user_id = ?', [$userId]);

        return ['phone' => $row['phone'] ?? null, 'mobile' => $row['mobile'] ?? null, 'saved' => $row !== null];
    }

    /** A person changes their own numbers (blank = not shown). Throws MailError on a field. */
    public static function setNumbers(int $userId, ?string $phone, ?string $mobile): void
    {
        $data = self::checkNumbers(['phone' => $phone, 'mobile' => $mobile]);
        $now = Clock::sql();
        Db::exec(
            'INSERT INTO mail_signatures (user_id, phone, mobile, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE phone = VALUES(phone), mobile = VALUES(mobile), updated_at = VALUES(updated_at)',
            [$userId, $data['phone'], $data['mobile'], $now, $now],
        );
        self::$people = [];
    }

    /** @return array{phone: ?string, mobile: ?string} */
    public static function checkNumbers(array $values): array
    {
        $out = [];
        foreach (['phone' => 'the office number', 'mobile' => 'the mobile number'] as $k => $what) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($values[$k] ?? '')));
            if ($v !== '' && !preg_match(self::PHONE, $v)) {
                throw MailError::field($k, "Type {$what} with digits and spaces, like +234 801 234 5678.");
            }
            $out[$k] = $v !== '' ? $v : null;
        }

        return $out;
    }

    /**
     * What the ERP knows about the person for the signature: their name; on a department's mailbox, the department
     * and their seat in it; their numbers from mail_signatures (their profile's phone as mobile until they set
     * their own).
     *
     * @return array{name: string, title: ?string, department: ?string, phone: ?string, mobile: ?string, profile_phone: ?string}
     */
    public static function person(int $userId, ?array $account = null): array
    {
        $key = $userId . ':' . (int) ($account['department_id'] ?? 0);
        if (isset(self::$people[$key])) {
            return self::$people[$key];
        }
        $p = People::find($userId);
        $profilePhone = self::blank(Db::value('SELECT phone FROM users WHERE id = ?', [$userId]));
        $own = self::numbers($userId);
        [$title, $department] = self::place($userId, $account);

        return self::$people[$key] = [
            'name' => $p['name'] ?? 'Someone',
            'title' => $title,
            'department' => $department,
            'phone' => $own['saved'] ? $own['phone'] : null,
            'mobile' => $own['saved'] ? $own['mobile'] : $profilePhone,
            'profile_phone' => $profilePhone,
        ];
    }

    // ------------------------------------------------------------------ rendering

    /** The signature as HTML, ready to add below a message ('' for 'none') */
    public static function html(int $userId, array $account, string $style = 'full'): string
    {
        return self::render(self::person($userId, $account), self::church(), (string) $account['email'], $style);
    }

    /** The same signature as plain text ('' for 'none'); starts with the "-- " line mail apps recognise */
    public static function text(int $userId, array $account, string $style = 'full'): string
    {
        return self::renderText(self::person($userId, $account), self::church(), (string) $account['email'], $style);
    }

    /** @param array $person as person() @param array $church as church() */
    public static function render(array $person, array $church, string $email, string $style = 'full'): string
    {
        if ($style === 'none' || !isset(self::STYLES[$style])) {
            return '';
        }
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $f = self::FONT;
        $table = 'role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;';
        $role = implode(' · ', array_filter([$person['title'] ?? null, $person['department'] ?? null]));
        $link = fn (string $href, string $text, string $color) => '<a href="' . $e($href) . '" style="color:' . $color . ';text-decoration:none;">' . $e($text) . '</a>';
        $tel = fn (string $n) => $link('tel:' . preg_replace('/[^0-9+]/', '', $n), $n, self::SOFT);
        $mail = $link('mailto:' . $email, $email, self::BRAND);
        $site = $church['website'] !== '' ? $link($church['website'], self::siteLabel($church['website']), self::BRAND) : '';

        if ($style === 'slim') {
            $line2 = array_filter([
                '<span style="color:' . self::BRAND . ';font-weight:600;">' . $e($church['name']) . '</span>',
                !empty($person['phone']) ? $tel($person['phone']) : null,
                !empty($person['mobile']) ? $tel($person['mobile']) : null,
                $mail,
            ]);

            return '<table ' . $table . 'margin-top:20px;"><tr>'
                . '<td style="' . $f . 'border-left:3px solid ' . self::ACCENT . ';padding:1px 0 1px 12px;font-size:13px;line-height:20px;color:' . self::SOFT . ';mso-line-height-rule:exactly;">'
                . '<span style="color:' . self::INK . ';font-weight:700;">' . $e($person['name']) . '</span>'
                . ($role !== '' ? '<span style="color:' . self::MUTED . ';"> · ' . $e($role) . '</span>' : '')
                . '<br>' . implode('<span style="color:' . self::MUTED . ';"> · </span>', $line2)
                . '</td></tr></table>';
        }

        $rows = [];
        if (!empty($person['phone'])) {
            $rows[] = ['Office', $tel($person['phone'])];
        }
        if (!empty($person['mobile'])) {
            $rows[] = ['Mobile', $tel($person['mobile'])];
        }
        if (empty($person['phone']) && empty($person['mobile']) && $church['phone'] !== '') {
            $rows[] = ['Phone', $tel($church['phone'])];
        }
        $rows[] = ['Email', $mail];
        if ($site !== '') {
            $rows[] = ['Web', $site];
        }
        $contact = '';
        foreach ($rows as [$label, $value]) {
            $contact .= '<tr>'
                . '<td valign="top" style="' . $f . 'padding:0 10px 3px 0;font-size:10px;line-height:19px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:' . self::BRAND . ';white-space:nowrap;mso-line-height-rule:exactly;">' . $e($label) . '</td>'
                . '<td valign="top" style="' . $f . 'padding:0 0 3px;font-size:13px;line-height:19px;color:' . self::SOFT . ';mso-line-height-rule:exactly;">' . $value . '</td>'
                . '</tr>';
        }
        // The rule under the name: navy, then red, as in the logo
        $rule = '<table ' . $table . 'margin:9px 0 10px;"><tr>'
            . '<td width="22" height="3" bgcolor="' . self::BRAND . '" style="width:22px;height:3px;font-size:0;line-height:0;background:' . self::BRAND . ';">&nbsp;</td>'
            . '<td width="44" height="3" bgcolor="' . self::ACCENT . '" style="width:44px;height:3px;font-size:0;line-height:0;background:' . self::ACCENT . ';">&nbsp;</td>'
            . '</tr></table>';
        $text = '<div style="' . $f . 'font-size:17px;line-height:22px;font-weight:700;color:' . self::INK . ';letter-spacing:-0.1px;mso-line-height-rule:exactly;">' . $e($person['name']) . '</div>'
            . ($role !== '' ? '<div style="' . $f . 'font-size:11px;line-height:17px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:' . self::BRAND . ';padding-top:2px;mso-line-height-rule:exactly;">' . $e($role) . '</div>' : '')
            . $rule
            . '<table ' . $table . '">' . $contact . '</table>';

        $logo = '';
        if ($church['show_logo'] && $church['logo_url'] !== '') {
            $img = '<img src="' . $e($church['logo_url']) . '" width="76" height="76" alt="' . $e($church['name']) . '" style="display:block;width:76px;height:76px;border:0;outline:none;text-decoration:none;' . $f . 'font-size:12px;line-height:16px;font-weight:700;color:' . self::BRAND . ';">';
            $logo = '<td valign="top" width="76" style="width:76px;padding:2px 18px 0 0;">'
                . ($church['website'] !== '' ? '<a href="' . $e($church['website']) . '" style="text-decoration:none;">' . $img . '</a>' : $img)
                . '</td>';
        }
        $textCell = '<td valign="top" style="' . $f . ($logo !== '' ? 'border-left:1px solid ' . self::LINE . ';padding:0 0 0 18px;' : 'padding:0;') . '">' . $text . '</td>';

        $foot = '<span style="font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:' . self::BRAND . ';">' . $e($church['name']) . '</span>';
        if ($church['tagline'] !== '') {
            $foot .= '<span style="color:' . self::MUTED . ';">&nbsp;&nbsp;·&nbsp;&nbsp;' . $e($church['tagline']) . '</span>';
        }
        if ($church['address'] !== '') {
            $foot .= '<br><span style="color:' . self::MUTED . ';">' . $e($church['address']) . '</span>';
        }

        return '<table ' . $table . 'margin-top:24px;">'
            . '<tr>' . $logo . $textCell . '</tr>'
            . '<tr><td colspan="' . ($logo !== '' ? 2 : 1) . '" style="padding:14px 0 0;">'
            . '<table ' . $table . 'width:100%;"><tr><td height="1" bgcolor="' . self::LINE . '" style="height:1px;font-size:0;line-height:0;background:' . self::LINE . ';">&nbsp;</td></tr></table>'
            . '<div style="' . $f . 'font-size:11px;line-height:17px;padding-top:9px;color:' . self::MUTED . ';mso-line-height-rule:exactly;">' . $foot . '</div>'
            . '</td></tr></table>';
    }

    public static function renderText(array $person, array $church, string $email, string $style = 'full'): string
    {
        if ($style === 'none' || !isset(self::STYLES[$style])) {
            return '';
        }
        $role = implode(' · ', array_filter([$person['title'] ?? null, $person['department'] ?? null]));
        $numbers = array_filter([
            !empty($person['phone']) ? 'Office ' . $person['phone'] : null,
            !empty($person['mobile']) ? 'Mobile ' . $person['mobile'] : null,
        ]);
        if ($style === 'slim') {
            return "-- \n" . $person['name'] . ($role !== '' ? " · {$role}" : '') . "\n"
                . implode(' · ', array_filter([$church['name'], ...array_map(fn ($n) => preg_replace('/^(Office|Mobile) /', '', $n), $numbers), $email]));
        }
        if (!$numbers && $church['phone'] !== '') {
            $numbers = ['Phone ' . $church['phone']];
        }
        $lines = array_filter([
            $person['name'],
            $role ?: null,
            '',
            implode(' · ', $numbers) ?: null,
            implode(' · ', array_filter([$email, $church['website'] !== '' ? self::siteLabel($church['website']) : null])),
            '',
            $church['name'] . ($church['tagline'] !== '' ? ' · ' . $church['tagline'] : ''),
            $church['address'] ?: null,
        ], fn ($l) => $l !== null);

        return "-- \n" . trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** For tests and the preview after a change */
    public static function flush(): void
    {
        self::$people = [];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * [title, department] for a department's mailbox: the department's name, and the person's seat in it (its
     * label from perm_seat_types) when they hold one. Nothing on church-wide and personal mailboxes.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function place(int $userId, ?array $account): array
    {
        $deptId = (int) ($account['department_id'] ?? 0);
        if (($account['kind'] ?? '') !== 'department' || $deptId <= 0) {
            return [null, null];
        }
        $department = self::blank(Db::value('SELECT name FROM departments WHERE id = ?', [$deptId]));
        $title = null;
        try {
            if (function_exists('perm_person_seats') && function_exists('perm_seat_types')) {
                $types = perm_seat_types(Db::pdo());
                foreach (perm_person_seats(Db::pdo(), $userId) as $seat) {
                    if ((int) $seat['department_id'] === $deptId && isset($types[$seat['seat_key']])) {
                        $title = self::blank($types[$seat['seat_key']]['label'] ?? null);
                        break;
                    }
                }
            }
        } catch (Throwable) {
            $title = null;
        }

        return [$title, $department];
    }

    /** "hodlekki.org" → "https://hodlekki.org"; '' when it isn't a web address */
    private static function website(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) && !preg_match('/[\s"<>]/', $value) ? rtrim($value, '/') : '';
    }

    private static function siteLabel(string $url): string
    {
        return preg_replace('~^https?://(www\.)?~i', '', rtrim($url, '/'));
    }

    private static function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
