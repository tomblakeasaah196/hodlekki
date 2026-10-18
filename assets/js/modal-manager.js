// /assets/js/modal-manager.js
(function () {
    'use strict';

    const DIALOG_SELECTOR = '[data-app-modal], [id*="modal" i]';
    const FOCUSABLE_SELECTOR = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
        '[contenteditable="true"]'
    ].join(',');

    // In order: an explicit opt-in, then the platform autofocus hint, then a
    // close button as the safe default for a dialog that asks for nothing.
    const PREFERRED_FOCUS = [
        '[data-modal-initial-focus]',
        '[autofocus]',
        'button[aria-label*="close" i]',
        'button[title*="close" i]',
        'button[onclick*="close" i]'
    ];

    const registered = new Set();
    const openDialogs = [];
    const dialogState = new WeakMap();
    let inertedElements = [];
    let syncQueued = false;
    let openSequence = 0;

    function isDialogOverlay(element) {
        if (!(element instanceof HTMLElement) || element.dataset.modalIgnore !== undefined) return false;
        if (!element.matches(DIALOG_SELECTOR)) return false;

        // Side drawers are intentionally not dialogs. Responsive bottom-sheet
        // dialogs still participate, but keep their mobile presentation.
        if (element.classList.contains('justify-end') && !element.classList.contains('justify-center')) return false;
        return window.getComputedStyle(element).position === 'fixed';
    }

    function isOpen(element) {
        if (element.hidden || element.classList.contains('hidden')) return false;
        const style = window.getComputedStyle(element);
        return style.display !== 'none' && style.visibility !== 'hidden';
    }

    function isBackdrop(element) {
        if (!(element instanceof HTMLElement)) return false;
        if (element.matches('[data-modal-backdrop], [data-sheet-backdrop], [data-drawer-backdrop]')) return true;
        const classes = element.classList;
        return classes.contains('absolute') && classes.contains('inset-0');
    }

    function findPanel(overlay) {
        const explicit = overlay.querySelector(':scope > [data-modal-panel], :scope > [role="document"], :scope > .modal-content');
        if (explicit) return explicit;

        const children = Array.from(overlay.children).filter(child => !isBackdrop(child));
        return children[0] || overlay;
    }

    function ensureAccessibleName(overlay, panel) {
        if (overlay.hasAttribute('aria-label') || overlay.hasAttribute('aria-labelledby')) return;
        const heading = panel.querySelector('h1, h2, h3, [data-modal-title]');
        if (!heading) {
            overlay.setAttribute('aria-label', 'Dialog');
            return;
        }
        if (!heading.id) heading.id = `${overlay.id || 'app-modal'}-title`;
        overlay.setAttribute('aria-labelledby', heading.id);
    }

    function register(overlay) {
        if (registered.has(overlay) || !isDialogOverlay(overlay)) return;

        const panel = findPanel(overlay);
        registered.add(overlay);
        overlay.classList.add('app-modal-overlay');
        if (overlay.classList.contains('items-end')) overlay.classList.add('app-modal-preserve-layout');
        panel.classList.add('app-modal-panel');
        overlay.setAttribute('role', overlay.getAttribute('role') || 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        ensureAccessibleName(overlay, panel);

        dialogState.set(overlay, {
            panel,
            opener: null,
            sequence: 0,
            baseZIndex: Number.parseInt(window.getComputedStyle(overlay).zIndex, 10) || 0,
            wasOpen: false,
            panelHadTabindex: panel.hasAttribute('tabindex')
        });
    }

    function discover(root) {
        if (root instanceof HTMLElement) register(root);
        if (!(root instanceof Document || root instanceof DocumentFragment || root instanceof HTMLElement)) return;
        root.querySelectorAll(DIALOG_SELECTOR).forEach(register);
    }

    function clearInert() {
        inertedElements.forEach(({ element, inert, ariaHidden }) => {
            element.inert = inert;
            if (ariaHidden === null) element.removeAttribute('aria-hidden');
            else element.setAttribute('aria-hidden', ariaHidden);
        });
        inertedElements = [];
    }

    function makeInert(element) {
        if (!(element instanceof HTMLElement) || inertedElements.some(item => item.element === element)) return;
        inertedElements.push({
            element,
            inert: element.inert,
            ariaHidden: element.getAttribute('aria-hidden')
        });
        element.inert = true;
        element.setAttribute('aria-hidden', 'true');
    }

    function isolate(topDialog) {
        clearInert();
        let child = topDialog;
        let parent = child.parentElement;

        while (parent) {
            Array.from(parent.children).forEach(sibling => {
                if (sibling !== child && sibling.tagName !== 'SCRIPT' && sibling.tagName !== 'STYLE'
                    && !isPreservedPortaledWidget(sibling)) makeInert(sibling);
            });
            child = parent;
            parent = parent.parentElement;
        }
    }

    function visibleFocusable(panel) {
        return Array.from(panel.querySelectorAll(FOCUSABLE_SELECTOR)).filter(element => {
            const style = window.getComputedStyle(element);
            return !element.hidden && style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
        });
    }

    function focusDialog(overlay) {
        const state = dialogState.get(overlay);
        if (!state || topDialog() !== overlay) return;

        // Tried in order of preference, NOT as one selector list: a single
        // querySelector with a comma-separated list returns the first match in
        // DOCUMENT order, so a close button in the dialog header always beat an
        // explicit [data-modal-initial-focus] further down the panel. That made
        // opt-in focus impossible — the Hymnary's search dialog put the cursor
        // on its X instead of its search box, and typing went nowhere.
        // A dialog that sets neither attribute behaves exactly as before.
        let preferred = null;
        for (const selector of PREFERRED_FOCUS) {
            preferred = state.panel.querySelector(selector);
            if (preferred) break;
        }
        const target = preferred || visibleFocusable(state.panel)[0] || state.panel;
        if (target === state.panel && !state.panel.hasAttribute('tabindex')) state.panel.setAttribute('tabindex', '-1');
        target.focus({ preventScroll: true });
    }

    function topDialog() {
        return openDialogs.length ? openDialogs[openDialogs.length - 1] : null;
    }

    function portalToViewport(overlay) {
        // A transformed or filtered ancestor changes the containing block of
        // position:fixed. Portalling prevents this regression in future page
        // layouts as well as in the legacy animated module shell.
        if (overlay.parentElement !== document.body) document.body.appendChild(overlay);
    }

    function sync() {
        syncQueued = false;
        discover(document);

        const nowOpen = Array.from(registered).filter(isOpen);

        nowOpen.forEach(overlay => {
            const state = dialogState.get(overlay);
            if (!state.wasOpen) {
                portalToViewport(overlay);
                state.wasOpen = true;
                state.sequence = ++openSequence;
                state.opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                window.setTimeout(() => focusDialog(overlay), 0);
            }
        });

        nowOpen.sort((a, b) => dialogState.get(a).sequence - dialogState.get(b).sequence);
        openDialogs.splice(0, openDialogs.length, ...nowOpen);
        let nextZIndex = 9990;
        openDialogs.forEach(overlay => {
            const state = dialogState.get(overlay);
            nextZIndex = Math.max(nextZIndex + 10, state.baseZIndex);
            const value = String(nextZIndex);
            if (overlay.style.zIndex !== value) overlay.style.zIndex = value;
        });

        registered.forEach(overlay => {
            const state = dialogState.get(overlay);
            if (state.wasOpen && !nowOpen.includes(overlay)) {
                state.wasOpen = false;
                overlay.style.removeProperty('z-index');
                if (!state.panelHadTabindex) state.panel.removeAttribute('tabindex');

                const opener = state.opener;
                state.opener = null;
                if (opener && opener.isConnected) {
                    window.setTimeout(() => opener.focus({ preventScroll: true }), 0);
                }
            }
        });

        document.documentElement.classList.toggle('app-modal-open', openDialogs.length > 0);
        document.body.classList.toggle('app-modal-open', openDialogs.length > 0);

        const main = document.querySelector('main');
        if (main) main.classList.toggle('app-modal-scroll-locked', openDialogs.length > 0);

        const top = topDialog();
        if (top) isolate(top);
        else clearInert();

        syncViewport(false);
    }

    function queueSync() {
        if (syncQueued) return;
        syncQueued = true;
        queueMicrotask(sync);
    }

    // On-screen keyboard. Android Chrome and iOS Safari shrink only the VISUAL
    // viewport when the keyboard opens; the layout viewport -- and so 100dvh --
    // keeps its full height. A dialog sized to it therefore runs behind the
    // keyboard: nothing in it needs scrolling as far as the browser can tell, so
    // the fields and the Next/Save button underneath can never be reached.
    //
    // While a dialog is open and the visual viewport is clearly shorter than the
    // layout viewport, publish its height and top edge as CSS variables and flag
    // <html>; the shared dialog CSS in includes/header.php then fits the overlay
    // and panel into the part of the screen that is actually visible. Nothing
    // changes without a keyboard, on desktop, or in browsers that resize the
    // layout viewport themselves (there the two heights match).
    const KEYBOARD_MIN_PX = 120; // a URL bar hiding or showing is smaller than this
    let keyboardOpen = false;
    let revealTimer = null;

    // `reveal` is true only when the keyboard itself moved (resize, rotation) or
    // focus changed. Mutation-driven syncs and the visual viewport panning must
    // not re-centre the field: that would fight someone scrolling the form.
    function syncViewport(reveal) {
        const vv = window.visualViewport;
        const root = document.documentElement;
        // A pinch-zoom also shrinks the visual viewport; it is not a keyboard.
        const open = !!vv && openDialogs.length > 0 && Math.abs(vv.scale - 1) < 0.05
            && (window.innerHeight - vv.height) > KEYBOARD_MIN_PX;

        if (open) {
            root.style.setProperty('--app-vv-height', vv.height + 'px');
            root.style.setProperty('--app-vv-top', vv.offsetTop + 'px');
        } else {
            root.style.removeProperty('--app-vv-height');
            root.style.removeProperty('--app-vv-top');
        }
        root.classList.toggle('app-keyboard-open', open);
        const changed = open !== keyboardOpen;
        keyboardOpen = open;
        if (reveal || changed) scheduleReveal();
    }

    // Once the dialog has been resized, bring the field being typed in into the
    // middle of what is visible. Debounced: the keyboard animates in over
    // several resize events.
    function scheduleReveal() {
        window.clearTimeout(revealTimer);
        if (!keyboardOpen) return;
        revealTimer = window.setTimeout(revealFocused, 120);
    }

    function revealFocused() {
        const top = topDialog();
        const element = document.activeElement;
        if (!top || !(element instanceof HTMLElement) || !top.contains(element)) return;
        if (!element.matches('input, select, textarea, [contenteditable="true"]')) return;
        element.scrollIntoView({ block: 'center', inline: 'nearest' });
    }

    // Widget libraries such as Select2 teleport their dropdown surface away
    // from the original control — either to the dialog root (dropdownParent)
    // or straight to <body>. Those surfaces carry real interactive elements
    // (search fields, option lists) and must be treated as part of the dialog
    // even though they sit next to the panel rather than inside it.
    function isPortaledWidgetSurface(target) {
        return target instanceof HTMLElement && !!target.closest('.select2-container');
    }

    function isPreservedPortaledWidget(element) {
        // An open widget dropdown portaled to <body> belongs to a control that
        // lives inside the active dialog; keep it interactive and readable.
        return element instanceof HTMLElement && element.matches('.select2-container--open');
    }

    function isInsideTopDialog(target) {
        const top = topDialog();
        if (!top) return false;
        // Taps directly on the dialog backdrop still count as "outside".
        if (target === top) return false;
        // Anything inside the overlay (panel + in-overlay portals) is inside.
        if (top.contains(target)) return true;
        // Widget dropdowns portaled elsewhere (e.g. <body>) by Select2 et al.
        return isPortaledWidgetSurface(target);
    }

    function blockOutsideDismissal(event) {
        // Nothing to guard when no dialog is open — the page works normally.
        if (!topDialog()) return;
        if (isInsideTopDialog(event.target)) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
    }

    function handleKeydown(event) {
        const top = topDialog();
        if (!top) return;

        // Dialogs close only through a deliberate visible control.
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            return;
        }

        if (event.key !== 'Tab') return;
        const panel = dialogState.get(top).panel;
        const focusable = visibleFocusable(panel);
        if (!focusable.length) {
            event.preventDefault();
            panel.focus({ preventScroll: true });
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function handleFocus(event) {
        const top = topDialog();
        if (!top) return;
        if (!isInsideTopDialog(event.target)) focusDialog(top);
        else scheduleReveal();
    }

    function init() {
        discover(document);
        sync();

        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => discover(node));
            });
            queueSync();
        });
        observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'hidden', 'style'] });

        document.addEventListener('pointerdown', blockOutsideDismissal, true);
        document.addEventListener('touchstart', blockOutsideDismissal, { capture: true, passive: false });
        document.addEventListener('click', blockOutsideDismissal, true);
        document.addEventListener('keydown', handleKeydown, true);
        document.addEventListener('focusin', handleFocus, true);

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', () => syncViewport(true));
            window.visualViewport.addEventListener('scroll', () => syncViewport(false));
        }
        window.addEventListener('orientationchange', () => syncViewport(true));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
