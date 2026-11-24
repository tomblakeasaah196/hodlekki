// /assets/ui/js/pages/events.js
// The Events page (modules/events/index.php): the events list, the monthly
// series, Configure (details, the description editor, ministers, registration
// questions, the live preview, the link and the report) and Registrations.
// Attendance and Analytics are in events-attendance.js and
// events-analytics.js; the three share window.HODEvents.
// Data: /api/events_api.php (same actions and fields as before the rebuild).
(function () {
    'use strict';

    // require_can() answers someone without Events access with the shell's
    // "not open to you" state, but this script is already in <head>. Without
    // the page there is nothing to run: stop before HODEvents exists, so
    // events-attendance.js and events-analytics.js stop too.
    if (!document.getElementById('panel-events')) return;

    var API = '/api/events_api.php';
    var EXPORT = '/api/export_event_excel.php';
    var cfg = HOD.data('events-config') || {};
    var esc = HOD.esc;
    var CATEGORIES = cfg.categories || {};
    var SECTION_KEY = 'events.activeSection';
    var CHOICE_TYPES = ['select', 'radio', 'checkbox'];
    var TYPE_LABELS = {
        text: 'Short answer', textarea: 'Paragraph', email: 'Email', number: 'Number', date: 'Date',
        select: 'Dropdown', radio: 'Single choice', checkbox: 'Multiple choice'
    };

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function num(n) { return HOD.format.number(n || 0); }
    function parseDate(v) { return new Date(String(v || '').replace(' ', 'T')); }
    function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function plural(n, word) { return num(n) + ' ' + word + (Number(n) === 1 ? '' : 's'); }
    function statusLabel(s) { return String(s || 'Unspecified').replace(/_/g, ' '); }
    function catLabel(c) { return CATEGORIES[c] || String(c || 'Event').replace(/_/g, ' '); }
    /** Only site paths and https images go into src. */
    function safeImg(url) {
        url = String(url || '');
        return (/^\/(?!\/)/.test(url) || /^https:\/\//i.test(url) || /^data:image\/(png|jpeg|webp|gif);/i.test(url)) ? url : '';
    }

    /** "Nov 12, 2026, 9:30 AM – Nov 14, 2026" for a (multi-day) event. */
    function dateRange(e) {
        var d = parseDate(e.event_date);
        if (isNaN(d.getTime())) return '';
        var out = d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
        if (e.end_date && e.end_date !== ymd(d)) {
            out += ' – ' + new Date(e.end_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }
        return out;
    }

    /** The (i) the components print, for copy built here. */
    function info(text, label) {
        var id = 'ev-info-' + (++info.seq);
        return '<span class="ui-info" data-ui-info><button type="button" class="ui-info__btn" aria-label="' + esc(label || 'More information') +
            '" aria-describedby="' + id + '" aria-expanded="false" aria-controls="' + id + '">' + HOD.icon('info') + '</button>' +
            '<span class="ui-info__pop" id="' + id + '" role="tooltip" popover="manual">' + esc(text) + '</span></span>';
    }
    info.seq = 0;

    function delta(pct, suffix) {
        if (pct === null || pct === undefined || pct === '') return '<span class="ui-delta ui-delta--flat">—</span>';
        pct = Number(pct);
        if (pct > 0) return '<span class="ui-delta ui-delta--up">' + HOD.icon('trending-up') + '+' + pct + '%' + (suffix || '') + '</span>';
        if (pct < 0) return '<span class="ui-delta ui-delta--down">' + HOD.icon('trending-down') + pct + '%' + (suffix || '') + '</span>';
        return '<span class="ui-delta ui-delta--flat">No change</span>';
    }

    function emptyHtml(icon, title, text) {
        return '<div class="ui-empty ui-empty--compact"><span class="ui-empty__icon">' + HOD.icon(icon) + '</span>' +
            '<p class="ui-empty__title">' + esc(title) + '</p>' + (text ? '<p class="ui-empty__text">' + esc(text) + '</p>' : '') + '</div>';
    }

    function copyText(text, input) {
        function fallback() {
            if (!input) { HOD.toast('Select the link and copy it.', 'warning'); return; }
            input.focus();
            input.select();
            try { document.execCommand('copy'); HOD.toast('Link copied.', 'success'); } catch (e) { HOD.toast('Select the link and copy it.', 'warning'); }
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { HOD.toast('Link copied.', 'success'); }, fallback);
        } else {
            fallback();
        }
    }

    var tabList = $('[data-ui-tabs]');
    function showTab(id) { HOD.tabs.select(tabList, id); }

    var EV = window.HODEvents = {
        api: function (action, data, opts) {
            return HOD.api(API, Object.assign({ action: action, data: data || {} }, opts || {}));
        },
        events: [],
        $: $, $$: $$, num: num, plural: plural, parseDate: parseDate, ymd: ymd, statusLabel: statusLabel,
        catLabel: catLabel, dateRange: dateRange, info: info, delta: delta, emptyHtml: emptyHtml,
        copyText: copyText, showTab: showTab
    };

    /* =================================================================== *
     * Rich text: the description editor and its allowlist
     * =================================================================== */

    var ALLOWED = { B: 1, STRONG: 1, I: 1, EM: 1, U: 1, BR: 1, P: 1, UL: 1, OL: 1, LI: 1 };
    var DROPPED = { SCRIPT: 1, STYLE: 1, TEMPLATE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, NOSCRIPT: 1, SVG: 1, MATH: 1, HEAD: 1, TITLE: 1, META: 1, LINK: 1 };

    /**
     * Basic formatting only (bold, italic, underline, paragraphs, lists), with
     * no attributes. Parsed inertly (DOMParser), rebuilt node by node.
     */
    function sanitizeRich(html) {
        var src = new DOMParser().parseFromString('<body>' + String(html || '') + '</body>', 'text/html').body;
        var out = document.createElement('div');
        (function copy(from, to) {
            Array.prototype.forEach.call(from.childNodes, function (node) {
                if (node.nodeType === 3) { to.appendChild(document.createTextNode(node.nodeValue)); return; }
                if (node.nodeType !== 1) return;
                var tag = node.tagName.toUpperCase();
                if (DROPPED[tag]) return;
                if (tag === 'DIV') tag = 'P';
                if (ALLOWED[tag]) {
                    var el = document.createElement(tag);
                    to.appendChild(el);
                    copy(node, el);
                } else {
                    copy(node, to);
                }
            });
        })(src, out);
        return out.textContent.trim() === '' ? '' : out.innerHTML;
    }
    EV.sanitizeRich = sanitizeRich;

    var editor = $('#ev-desc-editor');
    var descField = $('#ev-description');
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older engines */ }

    function syncDescription() { descField.value = sanitizeRich(editor.innerHTML); }
    function setDescription(html) {
        editor.innerHTML = sanitizeRich(html);
        descField.value = sanitizeRich(editor.innerHTML);
    }

    editor.addEventListener('input', function () { syncDescription(); renderPreview(); });
    editor.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
        document.execCommand('insertText', false, text.replace(/\r\n?/g, '\n'));
    });
    editor.addEventListener('drop', function (e) { e.preventDefault(); });

    HOD.actions.on('ev.format', function (btn) {
        editor.focus();
        document.execCommand(btn.getAttribute('data-cmd'), false, null);
        syncDescription();
        renderPreview();
    });

    /* =================================================================== *
     * Events list
     * =================================================================== */

    var grid = $('#ev-grid');

    function cardHtml(e) {
        var closed = Number(e.is_closed) === 1;
        var img = safeImg(e.banner_image_url);
        var banner = img
            ? '<img class="h-32 w-full object-cover" src="' + esc(img) + '" alt="" loading="lazy">'
            : '<div class="grid h-32 place-items-center bg-primary font-display text-3xl font-bold text-white" aria-hidden="true">' + esc((String(e.title || '').match(/[A-Za-z0-9]/) || ['?'])[0]) + '</div>';
        var search = [e.title, catLabel(e.event_category), e.location, dateRange(e)].join(' ').toLowerCase();
        return '<article class="ui-card flex flex-col overflow-hidden" data-ev-card data-search="' + esc(search) + '">' +
            '<div class="relative">' + banner +
                '<span class="ui-badge ' + (closed ? 'ui-badge--danger' : 'ui-badge--success') + ' absolute right-3 top-3">' + (closed ? 'Closed' : 'Open') + '</span></div>' +
            '<div class="ui-card__body flex flex-1 flex-col gap-1">' +
                '<p class="text-xs font-semibold uppercase tracking-wide text-primary">' + esc(catLabel(e.event_category)) + '</p>' +
                '<h3 class="ui-h3">' + esc(e.title) + '</h3>' +
                '<p class="flex items-center gap-1 text-sm text-ink-muted">' + HOD.icon('calendar', 'ui-icon--sm') + '<span>' + esc(dateRange(e)) + '</span></p>' +
                '<p class="ui-meta">' + num(e.total_attendance) + ' attended · ' + num(e.total_registered) + ' registered</p>' +
                '<div class="mt-auto pt-3"><button type="button" class="ui-btn ' + (closed ? 'ui-btn--secondary' : 'ui-btn--primary') + ' ui-btn--block" data-action="ev.manage" data-id="' + Number(e.id) + '">' +
                    (closed ? 'View archive' : 'Manage') + '</button></div>' +
            '</div></article>';
    }

    function loadEvents() {
        return EV.api('fetch_events', {}, { quiet: true }).then(function (res) {
            EV.events = res.data || [];
            if (!EV.events.length) {
                grid.innerHTML = '<div class="col-span-full">' + emptyHtml('calendar', 'No events yet', 'Use New event to create one.') + '</div>';
            } else {
                grid.innerHTML = EV.events.map(cardHtml).join('');
            }
            filterCards();
            var regSel = $('#reg-event');
            var attSel = $('#att-event');
            var regVal = regSel.value;
            var attVal = attSel.value;
            regSel.innerHTML = '<option value="">Choose an event</option>' + EV.events.map(function (e) {
                return '<option value="' + Number(e.id) + '">' + esc(e.title) + '</option>';
            }).join('');
            attSel.innerHTML = '<option value="">Choose an open event</option>' + EV.events.filter(function (e) { return Number(e.is_closed) === 0; }).map(function (e) {
                return '<option value="' + Number(e.id) + '">' + esc(e.title + ' (' + dateRange(e) + ')') + '</option>';
            }).join('');
            if (regVal) regSel.value = regVal;
            if (attVal) attSel.value = attVal;
            document.dispatchEvent(new CustomEvent('ev:loaded'));
        }).catch(function (err) {
            grid.innerHTML = '<div class="col-span-full ui-banner ui-banner--danger"><div class="ui-banner__body"><p class="ui-banner__text">' +
                esc(err.message || 'The events did not load.') + '</p></div></div>';
        });
    }
    EV.loadEvents = loadEvents;

    function filterCards() {
        var q = ($('#ev-search').value || '').trim().toLowerCase();
        var shown = 0;
        var cards = $$('[data-ev-card]', grid);
        cards.forEach(function (c) {
            var on = !q || c.getAttribute('data-search').indexOf(q) > -1;
            c.hidden = !on;
            if (on) shown++;
        });
        $('#ev-grid-none').hidden = !(q && cards.length && !shown);
    }
    HOD.actions.on('ev.search', filterCards);

    /* =================================================================== *
     * Monthly series
     * =================================================================== */

    function monthlySchedule(value) {
        var m = /^([0-9]{4})-(0[1-9]|1[0-2])$/.exec(value || '');
        if (!m) return [];
        var year = Number(m[1]);
        var month = Number(m[2]) - 1;
        var last = new Date(year, month + 1, 0).getDate();
        var lastSunday = last - new Date(year, month, last).getDay();
        var out = [];
        for (var day = 1; day <= last; day++) {
            var date = new Date(year, month, day);
            var wd = date.getDay();
            var hour = null, minute = 30, title = '', category = '';
            if (wd === 0) { hour = 9; category = 'Sunday_Service'; title = day === lastSunday ? 'Total Experience - Thanksgiving Service' : 'Total Experience'; }
            else if (wd === 4) { hour = 18; category = 'Midweek_Service'; title = 'Mercy Experience'; }
            if (hour === null) continue;
            out.push({
                title: title,
                category: category,
                label: date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }),
                time: new Date(year, month, day, hour, minute).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
            });
        }
        return out;
    }

    function renderMonthly() {
        var list = monthlySchedule($('#ev-month').value);
        $('#ev-month-count').textContent = plural(list.length, 'service');
        $('#ev-month-list').innerHTML = list.length ? list.map(function (s) {
            var thanks = s.title.indexOf('Thanksgiving') !== -1;
            return '<li class="ui-list__item"><span class="ui-list__main"><span class="ui-list__title">' + esc(s.title) + '</span>' +
                '<span class="ui-list__meta">' + esc(s.label) + '</span></span><span class="ui-list__end"><span class="ui-badge ' +
                (thanks ? 'ui-badge--brand' : 'ui-badge--info') + ' ui-num">' + esc(s.time) + '</span></span></li>';
        }).join('') : '<li class="ui-list__item ui-meta">Choose a month to see the services.</li>';
    }

    HOD.actions.on('ev.monthly', function (btn) {
        var month = $('#ev-month');
        if (!month.value) { var now = new Date(); month.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0'); }
        renderMonthly();
        HOD.dialog.open('ev-monthly', btn);
    });
    HOD.actions.on('ev.monthly-preview', renderMonthly);
    $('#ev-month').addEventListener('input', renderMonthly);

    HOD.actions.on('ev.monthly-save', function (form) {
        var month = $('#ev-month').value;
        if (!monthlySchedule(month).length) { HOD.form.setErrors(form, { month: 'Choose a month.' }); return; }
        var btn = $('#ev-month-submit');
        HOD.busy(btn, true);
        EV.api('create_monthly_services', { month: month }).then(function (res) {
            HOD.dialog.close('ev-monthly');
            HOD.toast(res.message || 'Monthly series created.', 'success');
            loadEvents();
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== *
     * Configure: create / edit
     * =================================================================== */

    var form = $('#ev-form');
    var state = { id: null, fields: [], ministers: [] };
    EV.config = state;

    function setMode(editing) {
        var badge = $('#ev-mode');
        badge.textContent = editing ? 'Editing' : 'New';
        badge.className = 'ui-badge ' + (editing ? 'ui-badge--success' : 'ui-badge--info');
        $('#ev-action').value = editing ? 'update_event' : 'create_event';
        $('#ev-submit span').textContent = editing ? 'Save changes' : 'Create event';
    }

    function setPeriod(on) {
        var end = $('#ev-end');
        $('#ev-period').checked = on;
        $('#ev-period-flag').value = on ? '1' : '0';
        $('#ev-end-wrap').hidden = !on;
        end.disabled = !on;
        end.required = on;
        if (!on) { end.removeAttribute('aria-invalid'); $('#ev-end-error').textContent = ''; }
        $('#ev-date-label').textContent = on ? 'Start date and time' : 'Date and time';
    }

    /** An image gets a src only when there is one to show; otherwise none at all, and it hides. */
    function setSrc(img, src) {
        if (src) img.src = src; else img.removeAttribute('src');
        img.hidden = !src;
    }

    function setBanner(src) {
        var img = $('#ev-banner-preview');
        src = safeImg(src);
        setSrc(img, src);
    }

    function setLink(token) {
        var box = $('#ev-link-box');
        if (token) {
            $('#ev-link').value = window.location.origin + '/register?token=' + encodeURIComponent(token);
            box.hidden = false;
        } else {
            $('#ev-link').value = '';
            box.hidden = true;
        }
    }

    function setClosed(closed) {
        $('#ev-closed-badge').hidden = !closed;
        $('#ev-close-btn').hidden = closed;
        var unlock = $('#ev-unlock-btn');
        if (unlock) unlock.hidden = !closed;
    }

    function startNew() {
        state.id = null;
        state.fields = [];
        state.ministers = [];
        form.reset();
        HOD.form.clearErrors(form);
        $('#ev-id').value = '';
        setMode(false);
        setPeriod(false);
        setDescription('');
        setBanner('');
        $('#ev-lifecycle').hidden = true;
        renderMinisters();
        renderFields();
        renderPreview();
        showTab('configure');
        $('#ev-title').focus();
    }
    HOD.actions.on('ev.new', startNew);

    function parseMinisters(raw, legacyImg) {
        if (!raw) return [];
        try {
            var arr = JSON.parse(raw);
            if (Array.isArray(arr)) {
                return arr.map(function (m) { return { name: (m && m.name) || '', imageUrl: (m && m.image) || '', file: null }; })
                    .filter(function (m) { return m.name || m.imageUrl; });
            }
        } catch (e) { /* the old plain-text field */ }
        var text = String(raw).replace(/<[^>]*>/g, '').trim();
        return text ? [{ name: text, imageUrl: legacyImg || '', file: null }] : [];
    }

    function loadFields(id) {
        return EV.api('fetch_manage_data', { event_id: id }, { quiet: true }).then(function (r) {
            if (state.id !== id) return;
            state.fields = r.fields || [];
            renderFields();
            renderPreview();
        }).catch(function () {});
    }

    function loadIntoConfigure(id) {
        var e = EV.events.filter(function (x) { return String(x.id) === String(id); })[0];
        if (!e) return;
        state.id = Number(e.id);
        state.fields = [];
        HOD.form.clearErrors(form);
        $('#ev-id').value = e.id;
        setMode(true);
        $('#ev-title').value = e.title || '';
        $('#ev-category').value = e.event_category;
        $('#ev-date').value = String(e.event_date || '').replace(' ', 'T').slice(0, 16);
        $('#ev-location').value = e.location || '';
        $('#ev-youtube').value = e.youtube_url || '';
        $('#ev-external').value = e.external_registration_url || '';
        setDescription(e.description || '');
        state.ministers = parseMinisters(e.ministers, e.ministers_image_url);
        renderMinisters();
        $('#ev-requires').checked = Number(e.requires_registration) === 1;
        $('#ev-visitors').checked = Number(e.allow_visitors) === 1;
        $('#ev-banner').value = '';
        var start = parseDate(e.event_date);
        var multi = !!(e.end_date && !isNaN(start.getTime()) && e.end_date !== ymd(start));
        setPeriod(multi);
        $('#ev-end').value = multi ? e.end_date : '';
        setBanner(e.banner_image_url || '');
        $('#ev-lifecycle').hidden = false;
        setLink(Number(e.requires_registration) === 1 && e.registration_token ? e.registration_token : '');
        $('#ev-report').value = e.report_notes || '';
        setClosed(Number(e.is_closed) === 1);
        renderFields();
        renderPreview();
        loadFields(state.id);
        showTab('configure');
    }
    EV.loadIntoConfigure = loadIntoConfigure;
    HOD.actions.on('ev.manage', function (btn) { loadIntoConfigure(btn.getAttribute('data-id')); });

    HOD.actions.on('ev.period', function (el) { setPeriod(el.checked); renderPreview(); });

    HOD.actions.on('ev.banner', function (input) {
        var f = input.files && input.files[0];
        if (!f) return;
        var r = new FileReader();
        r.onload = function (ev) { setBanner(ev.target.result); renderPreview(); };
        r.readAsDataURL(f);
    });

    form.addEventListener('input', function (e) { if (e.target.hasAttribute && e.target.hasAttribute('data-ev-preview')) renderPreview(); });
    form.addEventListener('change', function (e) { if (e.target.hasAttribute && e.target.hasAttribute('data-ev-preview')) renderPreview(); });

    /** The API marks a question required when is_required is sent at all, so send it only when it is. */
    function requiredParam(f) { return Number(f.is_required) === 1 ? 1 : undefined; }

    /** Saves the questions added before the event existed, one after another. */
    function pushDraftFields(eventId, fields) {
        return fields.reduce(function (chain, f) {
            return chain.then(function () {
                var d = { event_id: eventId, field_label: f.field_label, field_type: f.field_type, is_required: requiredParam(f), placeholder: f.placeholder || '' };
                if (CHOICE_TYPES.indexOf(f.field_type) !== -1 && f.field_options) d.field_options = f.field_options.join(',');
                return EV.api('add_custom_field', d, { quiet: true }).catch(function () {});
            });
        }, Promise.resolve());
    }

    HOD.actions.on('ev.save', function () {
        var start = $('#ev-date').value;
        var end = $('#ev-end');
        if (!end.disabled && end.value && start && end.value < start.slice(0, 10)) {
            HOD.form.setErrors(form, { end_date: 'End on or after the start date.' });
            return;
        }
        $('#ev-ministers-json').value = JSON.stringify(state.ministers.map(function (m) {
            return { name: m.name, image: m.file ? '' : (m.imageUrl || '') };
        }));
        syncDescription();
        var fd = new FormData(form);
        state.ministers.forEach(function (m) { if (m.file) fd.append('minister_image[]', m.file, m.file.name); });
        var btn = $('#ev-submit');
        var wasNew = !state.id;
        HOD.busy(btn, true);
        EV.api(fd.get('action'), fd).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
            setLink(res.token || '');
            if (wasNew && res.event_id) {
                state.id = Number(res.event_id);
                $('#ev-id').value = res.event_id;
                setMode(true);
                $('#ev-lifecycle').hidden = false;
                setClosed(false);
                var draft = state.fields.slice();
                return pushDraftFields(state.id, draft).then(function () {
                    return loadEvents();
                }).then(function () { if (draft.length) loadFields(state.id); });
            }
            return loadEvents();
        }).catch(function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        }).finally(function () { HOD.busy(btn, false); });
    });

    /* ---------- Ministers ---------- */

    var ministersBox = $('#ev-ministers');

    function renderMinisters() {
        if (!state.ministers.length) {
            ministersBox.innerHTML = '<p class="ui-meta">No ministers yet.</p>';
            return;
        }
        ministersBox.innerHTML = state.ministers.map(function (m, i) {
            var img = safeImg(m.imageUrl);
            var thumb = img
                ? '<img class="h-14 w-14 rounded-ui object-cover" src="' + esc(img) + '" alt="">'
                : '<span class="grid h-14 w-14 place-items-center rounded-ui border border-dashed border-line-strong bg-surface text-ink-subtle">' + HOD.icon('image') + '</span>';
            return '<div class="flex items-center gap-3 rounded-ui border border-line bg-surface-sunken p-2">' +
                '<label class="relative shrink-0 cursor-pointer" data-tooltip="Change photo">' + thumb +
                    '<input type="file" class="sr-only" accept="image/png,image/jpeg,image/webp" data-min-photo="' + i + '" aria-label="Photo of minister ' + (i + 1) + '"></label>' +
                '<label class="sr-only" for="ev-min-name-' + i + '">Name of minister ' + (i + 1) + '</label>' +
                '<input class="ui-input min-w-0 flex-1" type="text" id="ev-min-name-' + i + '" placeholder="Minister or speaker" value="' + esc(m.name) + '" data-min-name="' + i + '" maxlength="120">' +
                '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm ui-btn--icon" data-action="ev.minister-remove" data-index="' + i + '" aria-label="Remove minister ' + (i + 1) + '" data-tooltip="Remove">' + HOD.icon('trash-2') + '</button>' +
            '</div>';
        }).join('');
    }

    HOD.actions.on('ev.minister-add', function () {
        state.ministers.push({ name: '', imageUrl: '', file: null });
        renderMinisters();
        renderPreview();
        var last = $('[data-min-name="' + (state.ministers.length - 1) + '"]');
        if (last) last.focus();
    });
    HOD.actions.on('ev.minister-remove', function (btn) {
        state.ministers.splice(Number(btn.getAttribute('data-index')), 1);
        renderMinisters();
        renderPreview();
    });
    ministersBox.addEventListener('input', function (e) {
        var i = e.target.getAttribute && e.target.getAttribute('data-min-name');
        if (i === null || i === undefined || !state.ministers[i]) return;
        state.ministers[i].name = e.target.value;
        renderPreview();
    });
    ministersBox.addEventListener('change', function (e) {
        var i = e.target.getAttribute && e.target.getAttribute('data-min-photo');
        if (i === null || i === undefined || !state.ministers[i]) return;
        var f = e.target.files && e.target.files[0];
        if (!f) return;
        var r = new FileReader();
        r.onload = function (ev) {
            state.ministers[i].imageUrl = ev.target.result;
            state.ministers[i].file = f;
            renderMinisters();
            renderPreview();
        };
        r.readAsDataURL(f);
    });

    /* ---------- Registration questions ---------- */

    var fieldsList = $('#ev-fields');
    var fieldForm = $('#ev-field-form');

    function renderFields() {
        var n = state.fields.length;
        if (!n) {
            fieldsList.innerHTML = '<li class="ui-list__item"><span class="ui-meta">No questions yet.</span></li>';
            return;
        }
        fieldsList.innerHTML = state.fields.map(function (f, i) {
            return '<li class="ui-list__item">' +
                '<span class="flex flex-col">' +
                    '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-action="ev.field-move" data-index="' + i + '" data-dir="-1" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>' + HOD.icon('chevron-up') + '</button>' +
                    '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-action="ev.field-move" data-index="' + i + '" data-dir="1" aria-label="Move down"' + (i === n - 1 ? ' disabled' : '') + '>' + HOD.icon('chevron-down') + '</button>' +
                '</span>' +
                '<span class="ui-list__main"><span class="ui-list__title">' + esc(f.field_label) + (Number(f.is_required) === 1 ? ' <span class="text-danger" aria-label="required">*</span>' : '') + '</span>' +
                    '<span class="ui-list__meta">' + esc(TYPE_LABELS[f.field_type] || f.field_type) + '</span></span>' +
                '<span class="ui-list__end">' +
                    '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ev.field-edit" data-index="' + i + '">Edit</button>' +
                    '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm ui-btn--icon" data-action="ev.field-delete" data-index="' + i + '" aria-label="Delete question" data-tooltip="Delete">' + HOD.icon('trash-2') + '</button>' +
                '</span></li>';
        }).join('');
    }

    function toggleOptions() {
        var on = CHOICE_TYPES.indexOf($('#ev-field-type').value) !== -1;
        var opts = $('#ev-field-options');
        $('#ev-field-options-wrap').hidden = !on;
        opts.disabled = !on;
        if (!on) { opts.removeAttribute('aria-invalid'); }
    }
    HOD.actions.on('ev.field-type', toggleOptions);

    function openField(index, opener) {
        HOD.form.clearErrors(fieldForm);
        var f = index === null ? null : state.fields[index];
        $('#ev-field-title').textContent = f ? 'Edit question' : 'Add question';
        $('#ev-field-index').value = f ? index : '';
        $('#ev-field-label').value = f ? f.field_label : '';
        $('#ev-field-type').value = f ? f.field_type : 'text';
        $('#ev-field-required').checked = f ? Number(f.is_required) === 1 : false;
        $('#ev-field-placeholder').value = f ? (f.placeholder || '') : '';
        $('#ev-field-options').value = f ? (f.field_options || []).join(', ') : '';
        toggleOptions();
        HOD.dialog.open('ev-field', opener);
    }
    HOD.actions.on('ev.field-add', function (btn) { openField(null, btn); });
    HOD.actions.on('ev.field-edit', function (btn) { openField(Number(btn.getAttribute('data-index')), btn); });

    function persistField(mode, fieldId, f) {
        var d = { field_label: f.field_label, field_type: f.field_type, is_required: requiredParam(f), placeholder: f.placeholder || '' };
        if (f.field_options) d.field_options = f.field_options.join(',');
        if (mode === 'add') {
            d.event_id = state.id;
            EV.api('add_custom_field', d).then(function (r) {
                HOD.toast(r.message || 'Question added.', 'success');
                loadFields(state.id);
            }).catch(function () {});
        } else {
            d.field_id = fieldId;
            EV.api('update_custom_field', d).then(function (r) { HOD.toast(r.message || 'Question saved.', 'success'); }).catch(function () {});
        }
    }

    HOD.actions.on('ev.field-save', function () {
        var label = $('#ev-field-label').value.trim();
        var type = $('#ev-field-type').value;
        var options = $('#ev-field-options').value.trim();
        if (!label) { HOD.form.setErrors(fieldForm, { field_label: 'Write the question.' }); return; }
        if (CHOICE_TYPES.indexOf(type) !== -1 && !options.split(',').some(function (s) { return s.trim(); })) {
            HOD.form.setErrors(fieldForm, { field_options: 'Add at least one option.' });
            return;
        }
        var field = {
            field_label: label,
            field_type: type,
            is_required: $('#ev-field-required').checked ? 1 : 0,
            placeholder: $('#ev-field-placeholder').value.trim(),
            field_options: CHOICE_TYPES.indexOf(type) !== -1 ? options.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : null
        };
        var idx = $('#ev-field-index').value;
        if (idx !== '') {
            var i = Number(idx);
            state.fields[i] = Object.assign({}, state.fields[i], field);
            if (state.id) persistField('update', state.fields[i].id, field);
        } else {
            state.fields.push(field);
            if (state.id) persistField('add', null, field);
        }
        renderFields();
        renderPreview();
        HOD.dialog.close('ev-field');
    });

    HOD.actions.on('ev.field-move', function (btn) {
        var i = Number(btn.getAttribute('data-index'));
        var j = i + Number(btn.getAttribute('data-dir'));
        if (j < 0 || j >= state.fields.length) return;
        var tmp = state.fields[i];
        state.fields[i] = state.fields[j];
        state.fields[j] = tmp;
        if (state.id) {
            EV.api('reorder_custom_fields', { event_id: state.id, ordered_ids: state.fields.map(function (f) { return f.id; }).join(',') }, { quiet: true }).catch(function () {
                HOD.toast('The new order was not saved.', 'danger');
            });
        }
        renderFields();
        renderPreview();
        var moved = $('[data-action="ev.field-move"][data-index="' + j + '"][data-dir="' + btn.getAttribute('data-dir') + '"]');
        if (moved && !moved.disabled) moved.focus();
    });

    HOD.actions.on('ev.field-delete', function (btn) {
        var i = Number(btn.getAttribute('data-index'));
        var f = state.fields[i];
        if (!f) return;
        HOD.confirm({ title: 'Delete this question?', message: f.field_label, confirmLabel: 'Delete', tone: 'danger' }).then(function (ok) {
            if (!ok) return;
            if (f.id && state.id) {
                EV.api('delete_custom_field', { field_id: f.id }).then(function (r) { HOD.toast(r.message || 'Question deleted.', 'success'); }).catch(function () {});
            }
            state.fields.splice(state.fields.indexOf(f), 1);
            renderFields();
            renderPreview();
        });
    });

    /* ---------- Live preview ---------- */

    function previewField(f) {
        var req = Number(f.is_required) === 1 ? ' <span class="text-danger">*</span>' : '';
        var inner;
        var opts = f.field_options || [];
        if (f.field_type === 'radio' || f.field_type === 'checkbox') {
            inner = '<div class="ui-chips flex-wrap">' + opts.map(function (o) { return '<span class="ui-chip">' + esc(o) + '</span>'; }).join('') + '</div>';
        } else if (f.field_type === 'select') {
            inner = '<div class="ui-select text-ink-subtle">' + esc(opts[0] || 'Choose') + '</div>';
        } else {
            inner = '<div class="ui-input text-ink-subtle' + (f.field_type === 'textarea' ? ' min-h-16' : '') + '">' + esc(f.placeholder || '') + '&nbsp;</div>';
        }
        return '<div><p class="ui-label">' + esc(f.field_label) + req + '</p>' + inner + '</div>';
    }

    function renderPreview() {
        $('#pv-title').textContent = $('#ev-title').value || 'Your event title';
        $('#pv-category').textContent = catLabel($('#ev-category').value);
        var dateVal = $('#ev-date').value;
        var d = dateVal ? new Date(dateVal) : null;
        $('#pv-date').textContent = d && !isNaN(d.getTime())
            ? d.toLocaleString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
            : 'Pick a date';
        var loc = $('#ev-location').value.trim();
        $('#pv-location').hidden = !loc;
        $('#pv-location span').textContent = loc;

        var desc = sanitizeRich(editor.innerHTML);
        $('#pv-description').hidden = !desc;
        $('#pv-description').innerHTML = desc;

        var bannerImg = $('#ev-banner-preview');
        var pv = $('#pv-banner');
        setSrc(pv, bannerImg.hidden ? '' : bannerImg.getAttribute('src'));

        var ministers = state.ministers.filter(function (m) { return m.name || m.imageUrl; });
        $('#pv-ministers').hidden = !ministers.length;
        $('#pv-ministers-list').innerHTML = ministers.map(function (m) {
            var img = safeImg(m.imageUrl);
            return '<div class="flex w-20 flex-col items-center gap-1 text-center">' +
                (img ? '<img class="h-16 w-16 rounded-full object-cover" src="' + esc(img) + '" alt="">'
                    : '<span class="grid h-16 w-16 place-items-center rounded-full bg-primary-soft font-display font-bold text-primary">' + esc((m.name || '?').charAt(0)) + '</span>') +
                '<span class="text-xs font-semibold text-ink">' + esc(m.name) + '</span></div>';
        }).join('');

        var startDay = dateVal ? dateVal.slice(0, 10) : '';
        var endDay = $('#ev-period').checked ? $('#ev-end').value : '';
        var days = [];
        if (startDay && endDay && endDay > startDay) {
            var c = new Date(startDay + 'T00:00:00');
            var last = new Date(endDay + 'T00:00:00');
            for (var guard = 0; c <= last && guard < 60; guard++) {
                days.push(c.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }));
                c.setDate(c.getDate() + 1);
            }
        }
        $('#pv-days').hidden = !days.length;
        $('#pv-days-list').innerHTML = days.map(function (l) { return '<span class="ui-chip">' + esc(l) + '</span>'; }).join('');
        $('#pv-fields').innerHTML = state.fields.map(previewField).join('');
    }
    EV.renderPreview = renderPreview;

    /* ---------- Link, report, close / unlock ---------- */

    function currentTitle() {
        var e = EV.events.filter(function (x) { return Number(x.id) === state.id; })[0];
        return e ? e.title : ($('#ev-title').value || 'Event');
    }

    HOD.actions.on('ev.link-copy', function () { copyText($('#ev-link').value, $('#ev-link')); });
    HOD.actions.on('ev.link-whatsapp', function () {
        var link = $('#ev-link').value;
        if (!link) return;
        var text = 'You\'re invited to join us for "' + currentTitle() + '".\n\nClick the link below to review details and secure your registration:\n' + link;
        window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(text), '_blank', 'noopener,noreferrer');
    });
    HOD.actions.on('ev.link-share', function () {
        var link = $('#ev-link').value;
        var title = currentTitle();
        if (navigator.share) navigator.share({ title: title, text: 'You\'re invited: ' + title, url: link }).catch(function () {});
        else copyText(link, $('#ev-link'));
    });

    HOD.actions.on('ev.report', function (btn) {
        if (!state.id) return;
        HOD.busy(btn, true);
        EV.api('save_report', { event_id: state.id, report_notes: $('#ev-report').value }).then(function (r) {
            HOD.toast(r.message || 'Report saved.', 'success');
            var e = EV.events.filter(function (x) { return Number(x.id) === state.id; })[0];
            if (e) e.report_notes = $('#ev-report').value;
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });

    function lifecycle(action, ask, btn) {
        if (!state.id) return;
        HOD.confirm(ask).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            var id = state.id;
            EV.api(action, { event_id: id }).then(function (r) {
                HOD.toast(r.message || 'Done.', 'success');
                return loadEvents().then(function () { loadIntoConfigure(id); });
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    }
    HOD.actions.on('ev.close', function (btn) {
        lifecycle('close_event', { title: 'Close this event?', message: 'Attendance and registration will be locked.', confirmLabel: 'Close event', tone: 'danger' }, btn);
    });
    HOD.actions.on('ev.unlock', function (btn) {
        lifecycle('unlock_event', { title: 'Unlock this event?', message: 'Attendance and registration open again.', confirmLabel: 'Unlock' }, btn);
    });

    /* =================================================================== *
     * Registrations
     * =================================================================== */

    var reg = { id: '', page: 1, seq: 0 };
    var regRows = $('#reg-rows');

    function regLoading() {
        var row = '<tr><td colspan="6"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>';
        regRows.innerHTML = row + row + row;
    }

    function attendingDays(raw) {
        if (!raw || !raw.length) return '—';
        try {
            var days = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return days.map(function (d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }).join(', ');
        } catch (e) { return String(raw); }
    }

    function matchHtml(r) {
        if (!r.user_id && r.match_status === 'pending' && r.matched_first_name) {
            return '<div class="space-y-1 text-right sm:text-left"><p class="text-sm font-medium text-warning">' + Math.round(parseFloat(r.match_confidence) || 0) + '% · ' +
                esc(r.matched_first_name + ' ' + (r.matched_last_name || '')) + '</p><div class="flex justify-end gap-1 sm:justify-start">' +
                '<button type="button" class="ui-btn ui-btn--soft ui-btn--sm" data-action="reg.match" data-id="' + Number(r.id) + '" data-decision="confirm">Confirm</button>' +
                '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="reg.match" data-id="' + Number(r.id) + '" data-decision="reject">Not them</button></div></div>';
        }
        if (r.match_status === 'confirmed') return '<span class="ui-badge ui-badge--success">Linked</span>';
        if (r.match_status === 'rejected') return '<span class="ui-meta">Dismissed</span>';
        return '<span class="ui-meta">—</span>';
    }

    function fetchRegistrations(page) {
        if (!reg.id) return;
        reg.page = page || 1;
        var seq = ++reg.seq;
        regLoading();
        EV.api('fetch_registrations', { event_id: reg.id, page: reg.page, search: $('#reg-search').value.trim() }).then(function (res) {
            if (seq !== reg.seq) return;
            var k = res.kpis || {};
            $('#reg-kpi-total').textContent = num(k.total);
            $('#reg-kpi-today').textContent = num(k.today);
            $('#reg-kpi-yesterday').textContent = num(k.yesterday);
            var g = Number(k.growth || 0);
            $('#reg-kpi-trend').innerHTML = g ? delta(g, ' vs yesterday') : 'Same as yesterday';

            var p = res.pagination || { offset: 0, total_records: 0, current_page: 1, total_pages: 1 };
            var rows = res.data || [];
            regRows.innerHTML = rows.length ? rows.map(function (r, i) {
                var name = r.user_id ? (r.first_name + ' ' + r.last_name) : (r.guest_name || r.full_name || 'N/A');
                var phone = r.user_id ? (r.member_phone || '—') : (r.guest_phone || r.phone_number || '—');
                var at = r.nice_date || (r.registered_at ? parseDate(r.registered_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—');
                return '<tr>' +
                    '<td data-label="#" class="ui-num text-ink-subtle">' + (Number(p.offset) + i + 1) + '</td>' +
                    '<td data-label="Name"><span class="ui-table__primary">' + esc(name) + '</span></td>' +
                    '<td data-label="Contact"><div class="text-right sm:text-left"><span class="block text-sm">' + esc(r.email || '—') + '</span><span class="ui-table__secondary ui-num">' + esc(phone) + '</span></div></td>' +
                    '<td data-label="Days">' + esc(attendingDays(r.attendance_days)) + '</td>' +
                    '<td data-label="Member match">' + matchHtml(r) + '</td>' +
                    '<td data-label="Registered" class="ui-num">' + esc(at) + '</td>' +
                '</tr>';
            }).join('') : '<tr><td colspan="6">' + emptyHtml('search', 'No registrations found', $('#reg-search').value.trim() ? 'Try another search.' : '') + '</td></tr>';

            var total = Number(p.total_records) || 0;
            var from = total ? Number(p.offset) + 1 : 0;
            var to = Math.min(Number(p.offset) + rows.length, total);
            $('#reg-page-info').textContent = 'Showing ' + num(from) + '–' + num(to) + ' of ' + num(total);
            $('#reg-pages').innerHTML =
                (p.current_page > 1 ? '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="reg.page" data-page="' + (p.current_page - 1) + '">' + HOD.icon('chevron-left') + 'Previous</button>' : '') +
                (p.current_page < p.total_pages ? '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="reg.page" data-page="' + (p.current_page + 1) + '">Next' + HOD.icon('chevron-right') + '</button>' : '');
        }).catch(function () {
            if (seq !== reg.seq) return;
            regRows.innerHTML = '<tr><td colspan="6">' + emptyHtml('circle-alert', 'Registrations did not load', 'Try again in a moment.') + '</td></tr>';
        });
    }

    HOD.actions.on('reg.event', function (sel) {
        reg.id = sel.value;
        $('#reg-none').hidden = !!reg.id;
        $('#reg-body').hidden = !reg.id;
        var exp = $('#reg-export');
        exp.hidden = !reg.id;
        exp.href = reg.id ? EXPORT + '?event_id=' + encodeURIComponent(reg.id) + '&type=registrants' : '#';
        $('#reg-search').value = '';
        if (reg.id) fetchRegistrations(1);
    });
    HOD.actions.on('reg.search', function () { fetchRegistrations(1); });
    HOD.actions.on('reg.page', function (btn) { fetchRegistrations(Number(btn.getAttribute('data-page'))); });
    HOD.actions.on('reg.match', function (btn) {
        HOD.busy(btn, true);
        EV.api('confirm_match', { registration_id: btn.getAttribute('data-id'), decision: btn.getAttribute('data-decision') }).then(function (r) {
            HOD.toast(r.message || 'Saved.', 'success');
            fetchRegistrations(reg.page);
        }).catch(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== *
     * Sections and deep links
     * =================================================================== */

    document.addEventListener('ui:tab', function (e) {
        var tab = e.detail && e.detail.tab;
        try { sessionStorage.setItem(SECTION_KEY, tab); } catch (err) { /* private mode */ }
    });

    (function deepLink() {
        if (/(^|[#&])tab=/.test(window.location.hash)) return;
        var params = new URLSearchParams(window.location.search);
        var section = params.get('section');
        if (!section && params.get('attendance_event')) section = 'attendance';
        if (!section) {
            try { if (sessionStorage.getItem(SECTION_KEY) === 'attendance') section = 'attendance'; } catch (err) { /* private mode */ }
        }
        if (section) showTab(section);
    })();

    setMode(false);
    renderMinisters();
    renderFields();
    renderPreview();
    loadEvents();
})();
