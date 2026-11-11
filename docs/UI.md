# HOD Lekki — UI foundation

How every staff screen and public page is built, so the whole ERP looks and
behaves like one product. Read this before you add or rebuild a screen. The
live version of everything below is **Design System** in the menu
(`/modules/ui_guide/`, Super Admins), rendered by the real CSS and scripts.

- [1. The rules](#1-the-rules)
- [2. Anatomy of a page](#2-anatomy-of-a-page)
- [3. Tokens](#3-tokens)
- [4. Components](#4-components)
- [5. Behaviour (`HOD.*`)](#5-behaviour-hod)
- [6. The shells and the navigation registry](#6-the-shells-and-the-navigation-registry)
- [7. Security: CSP and CSRF](#7-security-csp-and-csrf)
- [8. Offline and the service worker](#8-offline-and-the-service-worker)
- [9. The build](#9-the-build)
- [10. Rebuilding a legacy page (checklist)](#10-rebuilding-a-legacy-page-checklist)
- [11. Is it top-notch?](#11-is-it-top-notch)

---

## 1. The rules

1. **Use the components.** A button is `ui-btn`, a dialog is `ui-dialog`, a
   toast is `HOD.toast()`. Never hand-build a `fixed inset-0` modal, a
   Toastify call, `confirm()`, `alert()` or `prompt()`.
2. **Semantic tokens only.** Colours come from `canvas`, `surface`, `ink`,
   `primary`, `danger`… (Tailwind: `bg-surface`, `text-ink-muted`,
   `border-line`). Never `#1D356A`, never `bg-blue-600` in new markup.
3. **No inline script, handler or style.** No `onclick=`, no `<script>` block
   with code, no `style="…"`. Behaviour goes in a file under
   `assets/ui/js/pages/`, wired with `data-action`. Data for scripts goes in
   `ui_json_script()`. The CSP on rebuilt pages refuses everything else.
4. **Every write carries the CSRF token** (automatic through `HOD.api`,
   `fetch`, jQuery and POST forms) and every endpoint of a rebuilt module
   calls `csrf_require()`.
5. **Phones first.** Design at 375 px, then widen. Touch targets ≥ 44 px on
   phones. Nothing scrolls sideways except a table inside its own wrapper.
6. **Say what to do next.** Every empty, error and offline state names the
   next step. Buttons are verbs ("Save member", "Send back"), never "OK" or
   "Submit". Errors never blame the person.
7. **Self-hosted only.** No CDN, no Google Fonts. A library a page needs is
   vendored under `assets/ui/vendor/` with its SHA-256 in `VENDOR.md`.
8. **Rebuild the CSS in the same commit** (`bash bin/build_ui.sh`). CI fails
   on a stale build.

Out of scope of these rules: the Bible and the Hymnary (their own reading
design, `assets/css/bible.css` / `hymnal.css`) and Special Events (its own
design system under `assets/se/`). They still sit inside the shell.

## 2. Anatomy of a page

```php
<?php
// /modules/example/index.php
$ui = [
    'title'   => 'Example',                          // default: the registry label
    'mode'    => 'strict',                            // rebuilt pages; see §7
    'scripts' => ['/assets/ui/js/pages/example.js'], // deferred, after ui.js
    'csp'     => [],                                  // e.g. ['connect-src' => ['https://api.geoapify.com']]
];
require_once '../../includes/header.php';
?>
<div class="ui-page">
    <?= ui_page_header([
        'title' => 'Example',
        'description' => 'One sentence on what this screen is for.',
        'actions' => ui_button('Add item', ['variant' => 'primary', 'icon' => 'plus', 'attrs' => ['data-dialog-open' => 'item-dialog']]),
    ]) ?>

    <?= ui_tabs(['list' => 'All items', 'insights' => 'Insights'], 'list') ?>
    <section data-tab-panel="list" class="ui-tab-panel ui-stack"> … </section>
    <section data-tab-panel="insights" class="ui-tab-panel" hidden> … </section>
</div>
<?= ui_json_script('example-data', ['items' => $items]) ?>
<?php require_once '../../includes/footer.php'; ?>
```

```js
// /assets/ui/js/pages/example.js
(function () {
    'use strict';
    var data = HOD.data('example-data');
    HOD.actions.on('item.delete', function (button) {
        HOD.confirm({ title: 'Delete this item?', confirmLabel: 'Delete item', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                HOD.busy(button, true);
                HOD.api('/api/example_api.php', { action: 'delete', data: { id: button.dataset.id } })
                    .then(function () { HOD.toast('Item deleted.', 'success'); button.closest('tr').remove(); })
                    .catch(function () {})            // HOD.api already showed the error
                    .finally(function () { HOD.busy(button, false); });
            });
    });
})();
```

Page settings (`$ui`, read by `ui_page_config()` in `includes/ui/shell.php`):

| Key | Meaning |
| --- | --- |
| `title` | Page name in `<title>`, the top bar and the phone app bar. |
| `mode` | `legacy` (default) or `strict`. See §7. |
| `scripts`, `styles` | Extra files; `/assets/…` paths get their content hash automatically. |
| `csp` | Strict only: extra sources per directive. |
| `jquery` | Strict only: also load jQuery (deferred). Avoid; use `HOD.api`. |
| `offline` | Strict only: may a read-only copy be kept (§8)? Default yes. |
| `page_action` | HTML for the one action slot in the top/app bar. |

Page patterns: a **list** page is header → toolbar (search + filters +
primary action) → card with a table (`ui-table--stack`) or list rows →
pagination. A **detail** page is header with back link → description list
and related lists in cards → actions in the header. A **form** page is one
card, `ui-form-grid`, actions in the card footer. A **dashboard** is a
`ui-grid--stats` row of `ui_stat()` tiles, then cards. Pick one before you
build.

## 3. Tokens

Defined once, in `assets/ui/src/app.css` (`:root`). Two layers:
**primitives** (`--navy-800`, `--grey-150`…) that pages never touch, and the
**semantic** layer below. Dark mode, when it comes, remaps the semantic layer
under `:root[data-theme="dark"]`; no page changes.

| Token | Tailwind | Use |
| --- | --- | --- |
| `--ui-canvas` | `bg-canvas` | The app background behind cards. |
| `--ui-surface` | `bg-surface` | Cards, dialogs, inputs. |
| `--ui-surface-sunken` / `-hover` / `-subtle` | `bg-surface-sunken` … | Wells, row hover, card footers. |
| `--ui-border` / `-strong` | `border-line` / `border-line-strong` | Hairlines between surfaces. |
| `--ui-text` / `-muted` / `-subtle` | `text-ink` / `text-ink-muted` / `text-ink-subtle` | Body / secondary / meta (subtle is the smallest contrast allowed: 4.6:1). |
| `--ui-heading` | — | Titles (brand navy `#0A0E17`). |
| `--ui-primary` (+ `-hover`, `-soft`) | `bg-primary`, `bg-primary-soft` | The one main action; navy `#1D356A`. |
| `--ui-brand-red` | `bg-brand-red` | Brand accent (`#D11920`): the active-nav marker, public CTAs. Sparingly. |
| `--ui-danger` / `-success` / `-warning` / `-info` (+ `-soft`) | `text-danger`, `bg-success-soft` … | Status only. |
| `--ui-focus` | — | The focus ring (2 px, offset 2 px). |

Type: **Inter** (400–700, variable) for everything; **Montserrat 700** only
for page titles (`ui-h1`) and the wordmark. Scale 12 / 13 / 14 / 15 / 17 /
20 / 24 / 30 px; body 15 px, line-height 1.55; never below 12 px; figures
use tabular numerals (`ui-num`, automatic in stats and tables). No more
10 px uppercase labels.

Space moves in 4/8 px steps. Page gutter 16 / 24 / 32 px (phone / tablet /
desktop), section gap 24 / 32 px, card padding 16 / 20 / 24 px. Radii 6
(badges), 10 (controls), 14 (cards), 20 (dialogs, sheets). Surfaces are
separated by hairline borders; shadows are for things that float (menus,
dialogs, toasts). Motion: 120 / 200 / 320 ms, one easing, opacity and
transform only, and everything honours `prefers-reduced-motion`.

## 4. Components

Classes live in `assets/ui/src/app.css`; PHP helpers in
`includes/ui/components.php` (loaded by the shell). Helpers return strings —
echo them. Every helper escapes what it prints and drops `on*` attributes.

| Component | Markup | PHP | Notes |
| --- | --- | --- | --- |
| Icon | `<svg class="ui-icon"><use href="…icons.svg?v=…#name"/></svg>` | `ui_icon('search', 'ui-icon--sm')` | Lucide subset, sprite at `assets/ui/icons.svg`. Add an SVG to `assets/ui/src/icons/` to add an icon. Sizes `--xs/--sm/--lg/--xl`. |
| Button | `.ui-btn` + `--primary/--secondary/--ghost/--soft/--danger/--danger-ghost/--brand`, `--sm/--lg`, `--icon`, `--block` | `ui_button('Save member', ['variant' => 'primary', 'icon' => 'check'])` | One primary per area. Icon-only buttons get `aria-label` + tooltip from the label. Busy: `HOD.busy(btn, true)`. |
| Page header | `.ui-page-header` | `ui_page_header([...])` | Title, description, actions, optional back link. The phone app bar shows the title once this scrolls away. |
| Card | `.ui-card` › `__header` (`--divided`) / `__body` (`--flush`) / `__footer` | — | `a.ui-card` is a link card. |
| Grid | `.ui-grid --2/--3/--stats/--sidebar`, `.ui-stack --sm/--md` | — | Stats: 2 across on phones, 4 on desktop. |
| Toolbar | `.ui-toolbar` › `__grow`, `__end`; `.ui-search` | — | Search input with icon: `<div class="ui-search"><svg…/><input class="ui-input" type="search"></div>`. |
| Tabs | `.ui-tabs` › `.ui-tab` + panels `[data-tab-panel]` | `ui_tabs($tabs, $active)` | Keyboard arrows, `#tab=<id>` deep link, `ui:tab` event. |
| Chips | `.ui-chips` › `.ui-chip[aria-pressed]` | — | Filters; scrolls sideways on phones. |
| Segmented | `.ui-segmented` › `.ui-segmented__item[aria-pressed]` | — | View switches (list / calendar). |
| Field | `.ui-field` › `.ui-label`, control, `.ui-hint`, `.ui-field-error` | `ui_field('tel', ['name' => …, 'label' => …, 'required' => true])` | Label always visible; placeholder is only a format hint. Types: text, email, tel, number, date, time, password, search, url, textarea, select. |
| Inputs | `.ui-input`, `.ui-select`, `.ui-textarea`, `.ui-input-group` | — | 16 px on phones (iOS zoom). `aria-invalid="true"` shows the error state. |
| Searchable select | `<select data-ui-combobox>` | — | Progressive enhancement for long lists (members, areas). The real select still submits. |
| Check / radio / switch | `<label class="ui-check"><input type="checkbox">…</label>`, `input.ui-switch` | — | `.ui-check__hint` for a second line. |
| Form layout | `.ui-form`, `.ui-form-grid` (`--3`), `.ui-span-full`, `.ui-fieldset`/`.ui-legend`, `.ui-form-actions` | `ui_csrf_field()` | Add `data-ui-validate` (+ `novalidate`) to a form for blur-first validation. |
| Table | `.ui-table-wrap` › `table.ui-table` (`--stack`, `--compact`); cells `.is-num`, `.is-actions`, `.is-head`; `.ui-table__primary/__secondary` | — | `--stack` turns rows into cards under 640 px; give cells `data-label`. |
| List rows | `.ui-list` › `.ui-list__item` › `__icon`, `__main` (`__title`, `__meta`), `__end` | — | The mobile workhorse. |
| Description list | `dl.ui-dl` | — | Label / value pairs on detail pages. |
| Badge | `.ui-badge --primary/--success/--warning/--danger/--info/--brand`, `--dot`, `--round` | `ui_badge('Present', 'success', ['dot' => true])` | Status words, not sentences. Counts: `.ui-count`. |
| Avatar | `.ui-avatar --xs/--sm/--lg/--xl`, `--square` | `ui_avatar($name, $photo, 'sm')` | Initials on a colour that is always that person's. |
| Stat | `.ui-stat` in `.ui-card` | `ui_stat(['label' => …, 'value' => …, 'delta' => 6.2])` | `href` makes the tile a link. |
| Empty state | `.ui-empty` | `ui_empty(['icon' => …, 'title' => …, 'text' => …, 'actions' => …])` | Always offer the next step. |
| Loading | `.ui-skeleton` (`--text/--title/--circle/--block`), `.ui-spinner`, `.ui-loading` | — | Nothing under 100 ms; inline spinner up to 1 s; skeleton beyond. |
| Progress / steps | `progress.ui-progress` (`--sm`, tones), `ol.ui-steps` › `.ui-step` (`.is-done`, `aria-current="step"`) | — | `<progress>` needs no style attribute. |
| Banner | `.ui-banner --info/--success/--warning/--danger` | `ui_banner('warning', ['title' => …, 'text' => …])` | In-page messages that stay. |
| Toast | `.ui-toast` | `HOD.toast()` | Passing messages; one region for the whole app. |
| Dialog | `dialog.ui-dialog --sm/--lg/--xl/--drawer` | `ui_dialog_start($id, [...])` … `ui_dialog_end($footerHtml)` | Native `<dialog>`: focus trap, Esc, return focus. A bottom sheet on phones. Open with `data-dialog-open="id"`, close with `data-dialog-close`. A dialog holding a form never closes on a backdrop tap. Ids must not contain "modal". |
| Menu | `button[data-menu-trigger][aria-controls=id]` + `.ui-menu#id[hidden]` › `.ui-menu__item`, `__sep`, `__label`, `--danger` | — | Keyboard arrows, Esc, outside click. |
| Pagination | `.ui-pagination` › `__pages` | — | At the foot of a list card. |
| Tooltip | `data-tooltip="Text"` (`data-tooltip-side="right|bottom"`) | — | Visual only, on hover-capable devices; the element still needs its own `aria-label`. |
| Prose | `.ui-prose` | — | Rendered guides (`how_to_use.md`). |

## 5. Behaviour (`HOD.*`)

`assets/ui/js/ui.js`, loaded `defer` on every page of the app.

| API | Does |
| --- | --- |
| `HOD.api(url, {action, data, method, json, quiet})` | POST form-encoded by default (what `$_POST` endpoints read), CSRF header included, resolves with the JSON when `status === 'success'`, rejects with `err.code` / `err.field` and shows the message as a toast unless `quiet`. Refuses writes while offline. |
| `HOD.toast(message, tone \| {tone, action, duration})` | Tones `success`, `danger`, `warning`, `info`. `action: {label: 'Undo', run: fn}`. |
| `HOD.confirm({title, message, confirmLabel, tone: 'danger', requireText})` | Promise<boolean>. Name the action in the button ("Delete member"). `requireText: 'DELETE'` for the irreversible ones. |
| `HOD.prompt({title, label, required, multiline})` / `HOD.alert({...})` | Replace `prompt()` / `alert()`. |
| `HOD.dialog.open(id)` / `.close(id)` | Plus `ui:dialog-open` / `ui:dialog-close` events on the dialog. |
| `HOD.actions.on(name, fn)` | Clicks on `[data-action=name]`, submits of `form[data-action=name]`, `[data-action-change]`, `[data-action-input]` (debounced). |
| `HOD.busy(button, on)` | Spinner, keeps the width, disables. |
| `HOD.form.validate(form)` / `.setErrors(form, {field: msg})` / `.values(form)` | Server errors land under their fields. |
| `HOD.tabs.select(list, id)`, `HOD.combobox.refresh(select)` | After changing tabs or options from script. |
| `HOD.format.number/naira/date/time` | `en-NG` formatting. |
| `HOD.data(id)`, `HOD.icon(name)`, `HOD.esc(text)`, `HOD.csrf()` | Boot data, icon markup, escaping, the token. |

Anything put into the page with `innerHTML` goes through `HOD.esc()` — a
member's name, a notification's text and a note are data, never markup.

## 6. The shells and the navigation registry

One document, two shells (`includes/ui/shell.php`, styles in §5 of
`app.css`, behaviour in `assets/ui/js/shell.js`):

- **Desktop (≥ 1024 px):** navy sidebar (collapses to an icon rail,
  remembered in the `hod_ui_rail` cookie so nothing jumps on load), top bar
  with the page title, search (Ctrl/Cmd+K), notifications and the account
  menu.
- **Phone (< 1024 px):** app bar (mark, title, one page action), bottom bar
  **Home · Search · Alerts · Me · Menu**; Menu opens the same navigation as a
  sheet above the bottom bar.

`includes/header.php` signs the person in (unchanged rules) and calls
`ui_shell_open()`; `includes/footer.php` calls `ui_shell_close()` (a page
that forgets the footer is closed at shutdown anyway). The variables older
pages read from header.php (`$firstName`, `$currentModule`,
`$is_super_admin`, `$assim_manager`, `userHasNavAccess()` …) are still set.

**The registry** — `includes/ui/nav.php`:

- `ui_nav_registry()` lists every module once: `key`, `label`, `icon`,
  `href`, `section`, `match` (folder names that mark it active), `keywords`
  and `tabs` (for search), optional `badge` callable. The sidebar, the phone
  menu and the search index all come from it. **A new module = one entry
  here.**
- `ui_nav_can_access(string $key, array $ctx): bool` is **the single access
  decision** for the navigation. Today it reproduces the old hard-coded
  sidebar exactly (`tests/ui/run.php` checks ~19,500 combinations against a
  transcription of it). The roles × modules permission matrix replaces this
  function's body; keep the signature and both shells and the search follow.
  `$ctx` comes from `ui_nav_context($pdo)` (roles, live department seats,
  the Assimilation and Special Events flags) — extend it there.
- Hiding a link is not a permission. Every module page and API still checks
  access itself.

## 7. Security: CSP and CSRF

**Headers on every response** (`includes/security_headers.php`, from
`db.php`): `X-Content-Type-Options: nosniff`, `Referrer-Policy:
strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`,
`Permissions-Policy` (camera, microphone and geolocation for this site only),
`Cross-Origin-Opener-Policy: same-origin-allow-popups`. HSTS is Cloudflare's.

**Content-Security-Policy**, per page, sent by the shell:

- `mode => 'strict'` (rebuilt pages): `default-src 'self'`; scripts and
  styles only from this site (plus a per-request nonce used solely by the
  PWA boot blocks in `includes/pwa_head.php`); images `self data: blob:`;
  fonts, connections, media `self`; `object-src 'none'`, `base-uri 'self'`,
  `form-action 'self'`, `frame-ancestors 'self'`. Violations are reported
  to `/api/csp_report.php` → the PHP error log. Add a third-party source
  only through `$ui['csp']`, and only if it cannot be self-hosted.
- `mode => 'legacy'` (pages not rebuilt yet): `object-src 'none'; base-uri
  'self'; frame-ancestors 'self'` — what cannot break their inline handlers.

**CSRF** (`includes/csrf.php`): one token per session in
`<meta name="csrf-token">`. `ui.js` adds `X-CSRF-Token` to every same-origin
non-GET `fetch` and `XMLHttpRequest` (so jQuery too) and a `_csrf` field to
every same-origin POST form — on legacy pages as well. On the server:

- Every endpoint of a rebuilt module: `require_once '../includes/db.php';
  csrf_require();` before any write. A refusal is 403
  `{"status":"error","code":"CSRF"}` and `HOD.api` shows its message.
- `db.php` runs `csrf_observe()` on every request: until the last module is
  rebuilt it logs, once per endpoint per session, a signed-in write that
  arrived without a valid token (`CSRF report-only: POST /api/x (no token)`).
  When the log is quiet, `CSRF_ENFORCE_ALL = true` enforces it everywhere
  except `CSRF_EXEMPT_PREFIXES` (public endpoints with their own protection,
  webhooks, the service worker's push re-subscription).
- The older per-module tokens (`sms_csrf`, `charis_csrf`, `bible_csrf`,
  `hymnal`, `csrf_token` in Requisitions …) keep working until their module
  moves to this one.

## 8. Offline and the service worker

`sw.js` (v2):

- `/assets/ui/*` is content-hashed, so it is served **cache-first** — the
  shell, fonts and icons are instant from the second visit.
- Navigations always go to the **network first**. A page is kept as a
  **read-only offline copy** only when the server sends `X-HOD-Offline:
  snapshot; u=<session key>` — strict pages, never Security, Roles, Finance,
  Alerts, Requisitions, SMS Studio or the Design System. Copies: one cache
  per session (another session's are deleted first), at most 12 pages, at
  most 72 h old, wiped on every navigation to `/auth/` and on any redirect
  there. Served only when the network fails or hangs (8 s), stamped
  `data-ui-snapshot`: the page shows "You're offline. This is the copy saved
  at …" and refuses every action and form.
- Nothing under `/api/`, `/auth/`, `/e/`, `/live/`, `/uploads/`, `/webhook/`
  is ever touched.
- While offline, `HOD.api` refuses writes with a clear message; actions wait
  for the network. There is no offline data capture.

A rebuilt page that should read well offline renders its main content on the
server (not only through a later API call).

## 9. The build

```bash
bash bin/build_ui.sh          # writes assets/ui/app.css, assets/ui/icons.svg, includes/ui/asset_manifest.php
bash bin/build_ui.sh --check  # what CI runs: fails if any of the three is stale
```

- Tailwind **v3.4.17** standalone (pinned in `assets/ui/src/TAILWIND_VERSION`;
  the binary is downloaded into `.tailwind/`, git-ignored). v3 on purpose:
  the old play CDN was v3, so unrebuilt pages compile exactly as before.
  Config `assets/ui/src/tailwind.config.js`, source `assets/ui/src/app.css`.
- The content scan covers `includes/`, `modules/`, `index.php`,
  `assets/js/`, `assets/ui/js/` and the Special Events Studio scripts. A class
  built at runtime from pieces (`'bg-' + tone`) cannot be seen: write class
  names out in full, or add them to `safelist` with a comment.
- Needs bash, curl (first run), PHP on `PATH` (or `PHP=/path/to/php`). On
  Windows, Git Bash works (`tailwindcss-windows-x64.exe`).
- `ui_asset('/assets/…')` prints the URL with `?v=<hash>` from the manifest;
  `assets/ui/.htaccess` lets browsers keep those files for a year.
- Fonts: `assets/ui/fonts/*.v1.woff2` (Inter 400–700 variable, Montserrat
  700; latin, latin-ext and vietnamese subsets — Yoruba needs the last two,
  and they download only when a page uses those letters). Changing a font
  means a new file name (`.v2`).
- Merge conflict in a built file: rebuild on the merged tree, never resolve
  it by hand.

## 10. Rebuilding a legacy page (checklist)

1. `$ui = ['mode' => 'strict', …]` before `require header.php`.
2. Layout on `ui-page` + `ui_page_header()` + cards; pick a page pattern (§2).
3. Every `onclick=` / inline `<script>` → `data-action` + a file in
   `assets/ui/js/pages/`; PHP data → `ui_json_script()`.
4. Every `fixed inset-0` modal → `ui_dialog_start/end`; every `confirm()` →
   `HOD.confirm`; every `Toastify` → `HOD.toast`; every `$.post` →
   `HOD.api` (jQuery only if a library truly needs it).
5. Every `style="…"` → classes (a width from data → `<progress>` or
   `el.style` in the page script).
6. Long `<select>`s → `data-ui-combobox`; forms → `ui_field()` +
   `data-ui-validate`.
7. Its API: `csrf_require()` at the top of every write action.
8. Remove its line from `tests/ui/tailwind_cdn_allowlist.txt` if it had one;
   no CDN left.
9. `bash bin/build_ui.sh`, `php tests/ui/run.php` (the ratchet checks strict
   pages), and the module's own suites.
10. Look at it at 375 px and 1280 px, keyboard only, empty / loading /
    error / offline states, and the console (no CSP violation).

## 11. Is it top-notch?

Before calling a screen done:

- [ ] At 375 px nothing is cramped, nothing scrolls sideways, every target ≥ 44 px.
- [ ] At 1280 px it breathes: one primary action, calm surfaces, aligned edges.
- [ ] Only semantic tokens; red only for the brand accent or danger.
- [ ] Type from the scale; no text under 12 px; figures tabular.
- [ ] Keyboard: everything reachable, focus visible, dialogs trap and return focus.
- [ ] Empty, loading, error, offline and no-permission states all designed and worded.
- [ ] Buttons are verbs; destructive ones name what they destroy and confirm.
- [ ] Console clean: no CSP violation, no error.
- [ ] Lighthouse mobile ≥ 90 performance on a signed-in page.
