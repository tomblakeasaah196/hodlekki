// /assets/se/js/core/phone.js
//
// The JavaScript twin of se_phone_normalize() (guide §10.3.1).
//
// Both sides MUST agree, or the form will cheerfully accept a number the
// server then rejects. tests/special_events/fixtures/phones.json is the
// shared contract: phone_test.php and js/phone.test.mjs run the same vectors
// through both implementations.
//
// The Nigerian branch is a direct port of sms_normalize_phone() in
// includes/sms_functions.php — the module never invents its own idea of what
// "0803 123 4567" means.

/** Nigerian mobile in E.164 digits, or null. Mirrors sms_normalize_phone(). */
export function normalizeNigerian(input) {
    let p = String(input ?? '').replace(/[^0-9]/g, '');
    if (p === '') return null;

    if (p.startsWith('00')) p = p.slice(2);                 // 00234… international prefix

    if (p.length === 14 && p.startsWith('2340')) {
        p = '234' + p.slice(4);                             // +234 (0)801… → 234801…
    } else if (p.length === 11 && p[0] === '0') {
        p = '234' + p.slice(1);                             // 08012345678 → 2348012345678
    } else if (p.length === 10 && p[0] !== '0') {
        p = '234' + p;                                      // 8012345678 → 2348012345678
    }

    return /^234[789][01]\d{8}$/.test(p) ? p : null;
}

/**
 * Normalise anything a guest might type.
 *
 * @param {string|number} raw
 * @returns {{e164: string, sms: boolean, display: string}|null}
 */
export function normalizePhone(raw) {
    if (typeof raw !== 'string' && typeof raw !== 'number') return null;

    const input = String(raw).trim();
    if (input === '') return null;

    const hadPlus = input.startsWith('+');
    let digits = input.replace(/\D+/g, '');
    if (digits === '') return null;

    const ng = normalizeNigerian(input);
    if (ng) return { e164: ng, sms: true, display: displayPhone(ng) };

    const hadZeroZero = digits.startsWith('00');
    if (hadZeroZero) digits = digits.slice(2);

    if ((hadPlus || hadZeroZero) && digits.length >= 8 && digits.length <= 15) {
        return { e164: digits, sms: false, display: displayPhone(digits) };
    }

    return null;
}

/** "+234 803 123 4567", or a generic grouping for a foreign number. */
export function displayPhone(e164) {
    const digits = String(e164 ?? '').replace(/\D+/g, '');
    if (digits === '') return '';

    if (digits.length === 13 && digits.startsWith('234')) {
        return `+234 ${digits.slice(3, 6)} ${digits.slice(6, 9)} ${digits.slice(9)}`;
    }

    const head = digits.slice(0, 2);
    const tail = (digits.slice(2).match(/.{1,4}/g) || []).join(' ');

    return `+${head} ${tail}`.trim();
}

/** True when this number can receive an SMS on the Nigerian route. */
export function smsCapable(e164) {
    return /^234[789][01]\d{8}$/.test(String(e164 ?? ''));
}

/** "Ada O." — the only name shape a public surface ever shows (§10.3.4). */
export function displayName(firstName, lastName = '') {
    const first = String(firstName ?? '').trim();
    const last = String(lastName ?? '').trim();
    if (first === '') return 'Guest';

    return last === '' ? first : `${first} ${last[0].toUpperCase()}.`;
}
