// /assets/ui/src/tailwind.config.js
//
// Tailwind for the members' app (the ERP shell and every page inside it).
// Compiled by bin/build_ui.sh into assets/ui/app.css, which is COMMITTED: the
// cPanel host has no Node. CI rebuilds it and fails on any difference.
//
// Tailwind v3 on purpose (pinned in TAILWIND_VERSION). Until a page is rebuilt
// on the shared components (docs/UI.md) it still carries the utility classes
// it was written with against the play CDN, which is v3 — compiling them with
// the same major version is what lets the CDN go without any of those pages
// shifting. Special Events keeps its own v4 build under assets/se/.
//
// Colours, radii, shadows and fonts below point at the CSS custom properties
// defined in app.css (the design tokens). Pages use the semantic names
// (canvas, surface, ink, primary …); the brand aliases hodBlue / hodRed and
// font-display stay so the older pages render exactly as they did.

/** @type {import('tailwindcss').Config} */
module.exports = {
    content: {
        relative: true,
        files: [
            // The shell, its partials and the shared PHP that prints markup.
            '../../../includes/**/*.php',
            '../../../index.php',
            // Standalone and public pages rebuilt on the foundation (sign-in,
            // the password and email-link pages, verify, live radio, the
            // monitor links). Pages still on the Tailwind CDN are not scanned.
            '../../../auth/*.php',
            '../../../email/*.php',
            '../../../verify.php',
            '../../../live_radio.php',
            '../../../monitor_confirm.php',
            '../../../reach.php',
            '../../../assimilation.php',
            // Every staff screen, including the Bible and the Hymnary, which
            // sit inside the shell even though their design is their own.
            '../../../modules/**/*.php',
            // Scripts that build markup (global search, exports, the reader).
            '../../../assets/js/**/*.js',
            '../js/**/*.js',
            // The Special Events Studio renders inside the shell and was
            // written against the CDN header.php used to load. Its own public
            // screens use assets/se/css/se.css and are not scanned here.
            '../../../assets/se/js/studio/**/*.js',
            '../../../assets/se/js/core/**/*.js',
            // Rendered markdown guides.
            '../../../modules/**/*.md',
        ],
    },
    // Classes built at runtime from fragments (`bg-${tone}-50`), so no scan
    // can see them. Add to this list only with a comment saying where.
    safelist: [
        // modules/departments/index.php — leadership seat tones.
        'bg-blue-50/50', 'bg-purple-50/50', 'bg-yellow-50/50',
        'border-blue-100', 'border-purple-100', 'border-yellow-100',
        // modules/departments/index.php — "Move to primary/secondary".
        'bg-gray-50', 'bg-red-50', 'text-hodRed', 'text-gray-600',
        'hover:bg-hodRed', 'hover:bg-gray-600', 'border-red-100', 'border-gray-200',
    ],
    theme: {
        extend: {
            colors: {
                // Brand aliases used by the older pages (do not remove until
                // nothing references them).
                hodBlue: '#1D356A',
                hodRed: '#D11920',
                hodSlate: '#F4F5F7',
                // Semantic tokens — the only colour names new markup uses.
                canvas: 'var(--ui-canvas)',
                surface: {
                    DEFAULT: 'var(--ui-surface)',
                    sunken: 'var(--ui-surface-sunken)',
                    hover: 'var(--ui-surface-hover)',
                },
                line: {
                    DEFAULT: 'var(--ui-border)',
                    strong: 'var(--ui-border-strong)',
                },
                ink: {
                    DEFAULT: 'var(--ui-text)',
                    muted: 'var(--ui-text-muted)',
                    subtle: 'var(--ui-text-subtle)',
                },
                primary: {
                    DEFAULT: 'var(--ui-primary)',
                    hover: 'var(--ui-primary-hover)',
                    soft: 'var(--ui-primary-soft)',
                    on: 'var(--ui-on-primary)',
                },
                brand: {
                    navy: 'var(--ui-brand-navy)',
                    red: 'var(--ui-brand-red)',
                },
                success: { DEFAULT: 'var(--ui-success)', soft: 'var(--ui-success-soft)' },
                warning: { DEFAULT: 'var(--ui-warning)', soft: 'var(--ui-warning-soft)' },
                danger: { DEFAULT: 'var(--ui-danger)', soft: 'var(--ui-danger-soft)' },
                info: { DEFAULT: 'var(--ui-info)', soft: 'var(--ui-info-soft)' },
            },
            fontFamily: {
                sans: ['var(--ui-font-sans)'],
                display: ['var(--ui-font-display)'],
                heading: ['var(--ui-font-display)'],
            },
            borderRadius: {
                'ui-sm': 'var(--ui-radius-sm)',
                ui: 'var(--ui-radius)',
                'ui-lg': 'var(--ui-radius-lg)',
                'ui-xl': 'var(--ui-radius-xl)',
            },
            boxShadow: {
                'ui-sm': 'var(--ui-shadow-sm)',
                'ui-md': 'var(--ui-shadow-md)',
                'ui-lg': 'var(--ui-shadow-lg)',
            },
            maxWidth: {
                page: 'var(--ui-page-max)',
                prose: '68ch',
            },
            transitionTimingFunction: {
                ui: 'var(--ui-ease)',
            },
            // header.php's old CDN config defined exactly this one animation.
            animation: {
                'fade-in-up': 'fadeInUp 0.6s ease-out forwards',
            },
            keyframes: {
                fadeInUp: {
                    '0%': { opacity: '0', transform: 'translateY(15px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
        },
    },
    plugins: [],
};
