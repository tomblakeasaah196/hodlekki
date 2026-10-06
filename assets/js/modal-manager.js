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

        const preferred = state.panel.querySelector(
            '[data-modal-initial-focus], [autofocus], button[aria-label*="close" i], button[title*="close" i], button[onclick*="close" i]'
        );
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
    }

    function queueSync() {
        if (syncQueued) return;
        syncQueued = true;
        queueMicrotask(sync);
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
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
