// /assets/se/js/portal/dom.js
//
// Small DOM helpers for the portal. The registration sheet is a short,
// imperative, one-at-a-time flow with a focus trap and drag-to-dismiss, so
// plain DOM is both lighter and clearer here than a virtual one — and it
// keeps the first-load JS budget (§13.15) for GSAP and the card engine.

/** createElement with attributes and children in one call. */
export function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key === 'html') node.innerHTML = value;
        else if (key.startsWith('on') && typeof value === 'function') {
            node.addEventListener(key.slice(2).toLowerCase(), value);
        } else if (value === true) node.setAttribute(key, '');
        else node.setAttribute(key, String(value));
    }

    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    }

    return node;
}

export const qs = (selector, root = document) => root.querySelector(selector);
export const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), '
    + 'textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/**
 * Trap the tab order inside `container` and restore focus on teardown
 * (§13.14). Returns the teardown function.
 */
export function trapFocus(container) {
    const previous = document.activeElement;

    const onKeyDown = (event) => {
        if (event.key !== 'Tab') return;

        const items = qsa(FOCUSABLE, container).filter((n) => n.offsetParent !== null || n === document.activeElement);
        if (!items.length) return;

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    container.addEventListener('keydown', onKeyDown);

    return () => {
        container.removeEventListener('keydown', onKeyDown);
        if (previous && typeof previous.focus === 'function') previous.focus();
    };
}

/**
 * A bottom sheet / centred modal (§13.4).
 *
 * `onBeforeClose` may return false to keep it open — that is how
 * "Leave registration?" works when something has been typed.
 */
export function openSheet({ label, onBeforeClose } = {}) {
    const panel = el('div', {
        class: 'se-sheet se-glass',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': label || 'Dialog',
    });

    const handle = el('div', { class: 'se-sheet-handle', 'aria-hidden': 'true' });
    panel.appendChild(handle);

    const body = el('div');
    panel.appendChild(body);

    const backdrop = el('div', { class: 'se-sheet-backdrop' }, panel);

    const release = () => {
        untrap();
        document.removeEventListener('keydown', onEscape);
        document.documentElement.style.removeProperty('overflow');
        backdrop.remove();
        document.body.dataset.seSheet = '0';
    };

    const close = async (force = false) => {
        if (!force && typeof onBeforeClose === 'function' && (await onBeforeClose()) === false) return false;
        release();
        return true;
    };

    const onEscape = (event) => {
        if (event.key === 'Escape') close();
    };

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) close();
    });

    // Drag down to dismiss, from the handle only, so scrolling the body
    // inside the sheet never closes it by accident.
    let startY = null;
    handle.addEventListener('pointerdown', (event) => {
        startY = event.clientY;
        handle.setPointerCapture(event.pointerId);
    });
    handle.addEventListener('pointerup', (event) => {
        if (startY !== null && event.clientY - startY > 60) close();
        startY = null;
    });

    document.body.appendChild(backdrop);
    document.body.dataset.seSheet = '1';
    document.documentElement.style.overflow = 'hidden';
    document.addEventListener('keydown', onEscape);

    const untrap = trapFocus(panel);

    return {
        panel,
        body,
        close,
        /** Replace the contents and move focus to the first heading. */
        render(...nodes) {
            body.replaceChildren(...nodes.flat().filter(Boolean));
            const heading = qs('h2, h3', body);
            if (heading) {
                heading.setAttribute('tabindex', '-1');
                heading.focus({ preventScroll: true });
            }
        },
    };
}

/** Progress dots for the sheet's steps. */
export function dots(total, current) {
    return el('div', { class: 'se-dots', 'aria-hidden': 'true' },
        Array.from({ length: total }, (_, i) => el('span', { class: 'se-dot', 'data-on': i <= current ? '1' : '0' })));
}

const LABELLABLE = new Set(['INPUT', 'SELECT', 'TEXTAREA']);

/**
 * A labelled field with a hint and an error slot, wired through
 * aria-describedby (§13.14).
 *
 * A real form control gets a <label for>; a group of buttons (the gender and
 * how-heard chips) gets a <div role="group" aria-labelledby>, because a
 * <label> may only point at a labellable element.
 */
export function field(id, labelText, control, hint) {
    control.id = id;
    const describedBy = [];

    const label = el('span', { id: `${id}-label`, text: labelText });
    const nodes = [label, control];

    if (hint) {
        nodes.push(el('span', { class: 'se-hint', id: `${id}-hint`, text: hint }));
        describedBy.push(`${id}-hint`);
    }

    const error = el('span', { class: 'se-error', id: `${id}-error`, role: 'alert', hidden: true });
    nodes.push(error);
    describedBy.push(`${id}-error`);

    control.setAttribute('aria-describedby', describedBy.join(' '));

    const wrapper = LABELLABLE.has(control.tagName)
        ? el('label', { class: 'se-field', for: id }, nodes)
        : el('div', { class: 'se-field', role: 'group', 'aria-labelledby': `${id}-label` }, nodes);

    // Not `wrapper.control`: on a <label> that is a read-only DOM property,
    // and assigning it throws in a module (strict mode). It did, on every
    // text field, so the registration sheet died before the phone step.
    wrapper.seControl = control;
    wrapper.setError = (message) => {
        error.textContent = message || '';
        error.hidden = !message;
        control.setAttribute('aria-invalid', message ? 'true' : 'false');
    };

    return wrapper;
}

/** Swap a field's control for a decorated wrapper (e.g. the +234 prefix). */
export function decorate(wrapper, build) {
    const control = wrapper.seControl;

    // build() moves the control INTO the replacement, so the control cannot
    // then be asked to replace itself with it ("the new child element
    // contains the parent"). Hold its place with a marker first.
    const marker = document.createComment('');
    control.replaceWith(marker);
    marker.replaceWith(build(control));

    return wrapper;
}

/** Copy to clipboard with a fallback for browsers without the async API. */
export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (e) {
        const area = el('textarea', { class: 'se-sr-only' });
        area.value = text;
        document.body.appendChild(area);
        area.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        area.remove();
        return ok;
    }
}
