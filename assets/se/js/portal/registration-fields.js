// /assets/se/js/portal/registration-fields.js
//
// Small, side-effect-free rules used by the phone-first registration sheet.
// Keeping these separate makes the privacy-sensitive member path easy to
// regression test without mounting the full portal DOM.

/**
 * A blank email input is useful for a new visitor, or when the server says a
 * required email is genuinely missing. Do not show it to a recognised member
 * whose Congregation profile already has an email: lookup intentionally does
 * not send that private value back to the browser.
 */
export function shouldAskEmail(lookup, fields) {
    if (!fields?.email || fields.email === 'off') return false;

    return lookup?.kind === 'new'
        || (lookup?.needs ?? []).includes('email');
}
