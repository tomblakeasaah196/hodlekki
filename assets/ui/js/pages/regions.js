// /assets/ui/js/pages/regions.js
// Regional Hubs (modules/regions/index.php).
//   Hub (everyone): region, leaders, noticeboard, prayer and carpool posts,
//   and the regional stream (refreshed every 10 s while it is on screen).
//   Command center (admins): members by region on a board (drag on a
//   computer with the vendored Sortable; tap to move or mute anywhere),
//   regions, broadcasts, auto-assign and export.
// Requests go to /api/regions_api.php with the same shapes as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/regions_api.php';
    var esc = HOD.esc;
    var cfg = HOD.data('regions-config') || {};

    var pollTimer = null;
    var boardLoaded = false;
    var regions = [];
    var memberTarget = null;

    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    function person(u) { return ((u.first_name || '') + ' ' + (u.last_name || '')).trim(); }
    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(https?:\/\/|\/|\.\.?\/)/i.test(url) ? url : '';
    }
    function tone(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
        return 'ui-avatar--c' + ((h % 6) + 1);
    }
    function avatar(first, last, pic, size) {
        var name = ((first || '') + ' ' + (last || '')).trim() || '?';
        var url = safeUrl(pic);
        return '<span class="ui-avatar ' + (size || 'ui-avatar--sm') + ' ' + tone(name) + '">'
            + (url ? '<img class="nav-avatar-img" src="' + esc(url) + '" alt="" loading="lazy">'
                   : '<span aria-hidden="true">' + esc(((first || '').charAt(0) + (last || '').charAt(0)).toUpperCase() || '?') + '</span>')
            + '</span>';
    }
    function when(value, withTime) {
        var d = new Date(String(value || '').replace(' ', 'T'));
        if (isNaN(d)) return '';
        return withTime ? HOD.format.date(d) + ', ' + HOD.format.time(d) : HOD.format.date(d);
    }

    /* =================================================================== *
     * Hub
     * =================================================================== */

    function renderLeaders(r) {
        var roles = [
            { title: 'Director in charge', fn: r.dir_first, ln: r.dir_last, pic: r.dir_pic, phone: r.dir_phone },
            { title: 'Pastor in charge', fn: r.pas_first, ln: r.pas_last, pic: r.pas_pic, phone: r.pas_phone },
            { title: 'Regional head', fn: r.head_first, ln: r.head_last, pic: r.head_pic, phone: r.head_phone }
        ].filter(function (x) { return x.fn; });
        $('[data-regions-leaders]').innerHTML = roles.length ? roles.map(function (x) {
            var name = (x.fn + ' ' + (x.ln || '')).trim();
            return '<li class="ui-list__item">' + avatar(x.fn, x.ln, x.pic)
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(name) + '</span><span class="ui-list__meta">' + esc(x.title) + '</span></span>'
                + (x.phone ? '<span class="ui-list__end"><a class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" href="tel:' + esc(x.phone) + '" aria-label="Call ' + esc(name) + '">' + HOD.icon('phone') + '</a></span>' : '')
                + '</li>';
        }).join('') : '<li class="ui-list__item text-sm text-ink-subtle">Leaders are being assigned.</li>';
    }

    function loadHub() {
        HOD.api(API, { action: 'fetch_my_region_hub', quiet: true }).then(function (res) {
            var d = res.data || {};
            var r = d.region || {};
            $('[data-regions-unassigned]').hidden = true;
            $('[data-regions-hub]').hidden = false;
            $('[data-regions-name]').textContent = r.name || 'Your region';
            $('[data-regions-population]').textContent = HOD.format.number(d.population);
            renderLeaders(r);
            loadNotices();
            startPolling();
        }).catch(function (err) {
            if (err && err.response && err.response.status === 'unassigned') {
                $('[data-regions-unassigned]').hidden = false;
                $('[data-regions-hub]').hidden = true;
                return;
            }
            HOD.toast((err && err.message) || 'Your region did not load. Reload the page to try again.', 'danger');
        });
    }

    var notices = [];
    function loadNotices() {
        HOD.api(API, { action: 'fetch_broadcasts', quiet: true }).then(function (res) {
            notices = res.data || [];
            $('[data-regions-notices]').innerHTML = notices.length ? notices.map(function (b, i) {
                return '<li><button type="button" class="ui-list__item w-full text-left" data-action="regions.notice" data-index="' + i + '">'
                    + '<span class="ui-list__icon">' + HOD.icon('megaphone') + '</span>'
                    + '<span class="ui-list__main"><span class="ui-list__title">' + esc(String(b.message || '').replace(/\s+/g, ' ')) + '</span>'
                    + '<span class="ui-list__meta">' + esc(when(b.created_at)) + '</span></span>'
                    + '<span class="ui-list__end">' + HOD.icon('chevron-right') + '</span></button></li>';
            }).join('') : '<li class="ui-list__item text-sm text-ink-subtle">No notices right now.</li>';
        }).catch(function () {});
    }

    var SYSTEM = {
        system_prayer: { icon: 'heart-handshake', title: 'Prayer request' },
        system_testimony: { icon: 'sparkles', title: 'Testimony' },
        system_carpool: { icon: 'users-round', title: 'Carpool' }
    };

    function message(m) {
        var mine = String(m.user_id) === String(cfg.me);
        var name = mine ? 'You' : person(m);
        var time = HOD.format.time(String(m.created_at || '').replace(' ', 'T'));
        var del = cfg.admin ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="regions.delete-message" data-id="' + esc(m.id) + '" aria-label="Remove this message" data-tooltip="Remove">' + HOD.icon('trash-2') + '</button>' : '';

        if (SYSTEM[m.message_type] || (m.message_type && m.message_type.indexOf('system_') === 0)) {
            var s = SYSTEM[m.message_type] || { icon: 'megaphone', title: 'Notice' };
            return '<li class="mx-auto w-full max-w-md rounded-ui-lg border border-line bg-surface px-4 py-3">'
                + '<div class="flex items-center justify-between gap-2"><p class="flex items-center gap-2 text-xs text-ink-subtle">'
                + '<span class="text-primary">' + HOD.icon(s.icon) + '</span>' + esc(s.title) + ' · ' + esc(name) + ' · ' + esc(time) + '</p>' + del + '</div>'
                + '<p class="mt-1.5 whitespace-pre-line text-sm font-medium text-ink">' + esc(m.content || '') + '</p></li>';
        }

        var media = '';
        var url = safeUrl(m.media_path);
        if (m.message_type === 'image' && url) {
            media = '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="mb-2 block"><img class="max-h-64 max-w-full rounded-ui object-cover" src="' + esc(url) + '" alt="Photo from ' + esc(name) + '" loading="lazy"></a>';
        } else if (m.message_type === 'voice' && url) {
            media = '<audio class="mb-2 h-10 w-56 max-w-full" controls preload="none" src="' + esc(url) + '"></audio>';
        }
        var text = m.content ? '<p class="whitespace-pre-line text-sm leading-relaxed">' + esc(m.content) + '</p>' : '';
        var bubble = '<div class="max-w-[85%] rounded-ui-lg px-3.5 py-2.5 ' + (mine ? 'rounded-tr-sm bg-primary text-primary-on' : 'rounded-tl-sm border border-line bg-surface text-ink') + '">' + media + text + '</div>';
        var meta = '<p class="mb-1 flex items-center gap-2 text-xs text-ink-subtle">' + (mine ? '' : '<span class="font-medium text-ink-muted">' + esc(name) + '</span>') + '<span>' + esc(time) + '</span>' + del + '</p>';
        return mine
            ? '<li class="flex flex-col items-end">' + meta + bubble + '</li>'
            : '<li class="flex gap-2.5">' + avatar(m.first_name, m.last_name, m.picture_path) + '<div class="flex min-w-0 flex-1 flex-col items-start">' + meta + bubble + '</div></li>';
    }

    function loadStream() {
        var box = $('[data-regions-stream]');
        return HOD.api(API, { action: 'fetch_stream', quiet: true }).then(function (res) {
            var list = res.data || [];
            var atBottom = box.scrollHeight - box.clientHeight <= box.scrollTop + 50;
            box.innerHTML = list.length ? '<ol class="ui-stack">' + list.map(message).join('') + '</ol>'
                : '<p class="py-16 text-center text-sm text-ink-subtle">Nothing here yet. Say hello to your region.</p>';
            if (atBottom) box.scrollTop = box.scrollHeight;
        }).catch(function () {});
    }

    function startPolling() {
        stopPolling();
        loadStream();
        pollTimer = setInterval(function () { if (!document.hidden) loadStream(); }, 10000);
    }
    function stopPolling() { clearInterval(pollTimer); pollTimer = null; }

    function postMessage(form) {
        var text = form.content.value.trim();
        var file = $('#regions-media').files[0];
        if (!text && !file) { form.content.focus(); return; }
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        HOD.api(API, { data: new FormData(form) }).then(function () {
            form.reset();
            clearMedia();
            form.content.style.height = '';
            var box = $('[data-regions-stream]');
            box.scrollTop = box.scrollHeight;
            loadStream().then(function () { box.scrollTop = box.scrollHeight; });
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function clearMedia() {
        $('#regions-media').value = '';
        $('[data-regions-attachment]').hidden = true;
    }

    /* =================================================================== *
     * Command center
     * =================================================================== */

    function card(u) {
        var muted = Number(u.is_muted_in_region) === 1;
        return '<li class="relative" data-id="' + esc(u.id) + '" data-name="' + esc(person(u).toLowerCase()) + '">'
            + '<button type="button" class="flex w-full items-center gap-3 rounded-ui border border-line bg-surface p-2.5 text-left hover:border-line-strong" data-action="regions.member"'
            + ' data-id="' + esc(u.id) + '" data-region="' + esc(u.region_id || '') + '" data-muted="' + (muted ? 1 : 0) + '" data-label="' + esc(person(u)) + '">'
            + avatar(u.first_name, u.last_name, u.picture_path)
            + '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-ink">' + esc(person(u)) + '</span>'
            + '<span class="block truncate text-xs text-ink-subtle">' + esc(u.physical_address || 'No address') + '</span></span>'
            + (muted ? '<span class="ui-badge ui-badge--danger">Muted</span>' : '')
            + '</button></li>';
    }

    function column(title, regionId, users, region) {
        var color = region && region.color_code ? region.color_code : '';
        return '<section class="ui-card flex max-h-[70vh] w-72 flex-none flex-col" data-region-color="' + esc(color) + '">'
            + '<header class="flex items-center gap-2 border-b border-line px-4 py-3">'
            + '<span class="h-2.5 w-2.5 flex-none rounded-full bg-[var(--region-color,var(--ui-border-strong))]" aria-hidden="true"></span>'
            + '<h3 class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">' + esc(title) + '</h3>'
            + '<span class="ui-count" data-count>' + users.length + '</span>'
            + (region ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="regions.edit-region" data-id="' + esc(region.id) + '" aria-label="Edit ' + esc(title) + '" data-tooltip="Edit region">' + HOD.icon('pencil') + '</button>' : '')
            + '</header>'
            + (regionId === '' ? '<div class="border-b border-line p-2.5"><label class="sr-only" for="regions-unassigned-filter">Filter unassigned members</label><input class="ui-input ui-input--sm" type="search" id="regions-unassigned-filter" placeholder="Filter by name" autocomplete="off" data-action-input="regions.filter"></div>' : '')
            + '<ul class="flex-1 space-y-2 overflow-y-auto p-2.5" data-region-id="' + esc(regionId) + '">' + users.map(card).join('') + '</ul>'
            + '</section>';
    }

    function loadBoard() {
        boardLoaded = true;
        return HOD.api(API, { action: 'admin_fetch_kanban', quiet: true }).then(function (res) {
            var b = res.data || {};
            regions = [];
            var html = column('Unassigned', '', b.unassigned || [], null);
            Object.keys(b.regions || {}).forEach(function (k) {
                var reg = b.regions[k];
                regions.push(reg.details);
                html += column(reg.details.name, String(reg.details.id), reg.users || [], reg.details);
            });
            var board = $('[data-regions-board]');
            board.innerHTML = html;
            // Region colours come from the database: set through the CSSOM,
            // never as a style attribute (strict CSP).
            board.querySelectorAll('[data-region-color]').forEach(function (col) {
                var c = col.getAttribute('data-region-color');
                if (/^#[0-9a-f]{3,8}$/i.test(c)) col.style.setProperty('--region-color', c);
            });
            var sel = $('#regions-broadcast-region');
            var current = sel.value;
            sel.innerHTML = '<option value="">Choose a region</option>' + regions.map(function (r) {
                return '<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>';
            }).join('');
            sel.value = current;
            if (window.matchMedia('(min-width: 768px)').matches) initSortable();
        }).catch(function (err) {
            HOD.toast((err && err.message) || 'The board did not load.', 'danger');
        });
    }

    function initSortable() {
        HOD.lib('sortable').then(function () {
            document.querySelectorAll('[data-regions-board] [data-region-id]').forEach(function (list) {
                new window.Sortable(list, {
                    group: 'regions',
                    animation: 150,
                    ghostClass: 'opacity-50',
                    onEnd: function (evt) {
                        if (evt.from === evt.to) return;
                        var id = evt.item.getAttribute('data-id');
                        HOD.api(API, { action: 'admin_reassign_user', data: { target_user_id: id, new_region_id: evt.to.getAttribute('data-region-id') }, quiet: true }).then(function () {
                            [evt.from, evt.to].forEach(function (l) {
                                l.closest('section').querySelector('[data-count]').textContent = l.children.length;
                            });
                            var btn = evt.item.querySelector('[data-action="regions.member"]');
                            if (btn) btn.setAttribute('data-region', evt.to.getAttribute('data-region-id'));
                        }).catch(function () {
                            HOD.toast('That move did not save. The board has been reloaded.', 'danger');
                            loadBoard();
                        });
                    }
                });
            });
        }).catch(function () { /* tapping a member still moves them */ });
    }

    function openMember(btn) {
        memberTarget = btn.getAttribute('data-id');
        var current = btn.getAttribute('data-region');
        var muted = btn.getAttribute('data-muted') === '1';
        $('#regions-member-title').textContent = btn.getAttribute('data-label');
        $('#regions-member-desc').textContent = muted ? 'Muted in the regional stream' : 'Move them, or mute them in the stream';
        var out = [];
        regions.forEach(function (r) {
            if (String(r.id) === String(current)) return;
            out.push('<button type="button" class="ui-btn ui-btn--secondary ui-btn--block justify-start" data-action="regions.move" data-region="' + esc(r.id) + '">' + HOD.icon('arrow-right') + '<span>Move to ' + esc(r.name) + '</span></button>');
        });
        if (current) out.push('<button type="button" class="ui-btn ui-btn--ghost ui-btn--block justify-start" data-action="regions.move" data-region="">' + HOD.icon('arrow-right') + '<span>Move to Unassigned</span></button>');
        out.push('<hr class="ui-divider">');
        out.push(muted
            ? '<button type="button" class="ui-btn ui-btn--soft ui-btn--block" data-action="regions.mute" data-mute="0">Unmute in the stream</button>'
            : '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--block" data-action="regions.mute" data-mute="1">Mute in the stream</button>');
        $('[data-regions-member-actions]').innerHTML = out.join('');
        HOD.dialog.open('regions-member', btn);
    }

    function loadLeaders() {
        return HOD.api(API, { action: 'admin_fetch_leaders', quiet: true }).then(function (res) {
            var opts = '<option value="">None</option>' + (res.data || []).map(function (l) {
                return '<option value="' + esc(l.id) + '">' + esc(person(l)) + ' (' + esc(label(l.role_name)) + ')</option>';
            }).join('');
            document.querySelectorAll('[data-regions-leader]').forEach(function (sel) {
                sel.innerHTML = opts;
                sel.value = '';
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    function openRegion(region, opener) {
        var f = $('#regions-region-form');
        f.reset();
        HOD.form.clearErrors(f);
        f.region_id.value = region ? region.id : '';
        f.elements.name.value = region ? region.name : '';
        f.description.value = region ? (region.description || '') : '';
        f.color_code.value = region && /^#[0-9a-f]{6}$/i.test(region.color_code || '') ? region.color_code : '#1D356A';
        $('#regions-region-title').textContent = region ? 'Edit ' + region.name : 'New region';
        $('[data-regions-region-save]').querySelector('span').textContent = region ? 'Update region' : 'Save region';
        loadLeaders().catch(function () {}).finally(function () { HOD.dialog.open('regions-region', opener); });
    }

    /* ---------- broadcasts ---------- */

    var broadcasts = [];
    function loadBroadcasts() {
        var regionId = $('#regions-broadcast-region').value;
        var list = $('[data-regions-broadcast-list]');
        if (!regionId) { list.innerHTML = '<li class="ui-list__item px-5 text-sm text-ink-subtle">Choose a region to see its notices.</li>'; return; }
        list.innerHTML = '<li class="ui-list__item px-5"><span class="ui-skeleton ui-skeleton--text w-2/3"></span></li>';
        HOD.api(API, { action: 'fetch_broadcasts', data: { region_id: regionId }, quiet: true }).then(function (res) {
            broadcasts = res.data || [];
            list.innerHTML = broadcasts.length ? broadcasts.map(function (b, i) {
                return '<li class="ui-list__item items-start px-5"><span class="ui-list__main">'
                    + '<span class="ui-list__meta">' + esc(when(b.created_at)) + '</span>'
                    + '<span class="mt-0.5 block whitespace-pre-line text-sm text-ink">' + esc(b.message) + '</span></span>'
                    + '<span class="ui-list__end flex gap-1">'
                    + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="regions.broadcast-edit" data-index="' + i + '">Edit</button>'
                    + '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" data-action="regions.broadcast-delete" data-id="' + esc(b.id) + '">Delete</button>'
                    + '</span></li>';
            }).join('') : '<li class="ui-list__item px-5 text-sm text-ink-subtle">No notices for this region yet.</li>';
        }).catch(function (err) {
            list.innerHTML = '<li class="ui-list__item px-5 text-sm text-danger">' + esc((err && err.message) || 'The notices did not load.') + '</li>';
        });
    }

    function resetBroadcast() {
        var f = $('#regions-broadcast-form');
        f.manage_action.value = 'create';
        f.broadcast_id.value = '';
        f.message.value = '';
        HOD.form.clearErrors(f);
        $('[data-regions-broadcast-cancel]').hidden = true;
        $('[data-regions-broadcast-save]').querySelector('span').textContent = 'Send notice';
    }

    /* =================================================================== *
     * Wire up
     * =================================================================== */

    function init() {
        HOD.actions.on('regions.notice', function (btn) {
            var b = notices[Number(btn.getAttribute('data-index'))];
            if (!b) return;
            $('#regions-notice-title').textContent = 'Notice';
            $('#regions-notice-desc').textContent = when(b.created_at);
            $('[data-regions-notice-text]').textContent = b.message;
            HOD.dialog.open('regions-notice', btn);
        });
        HOD.actions.on('regions.save', function (form) {
            var button = document.querySelector('button[form="' + form.id + '"]');
            HOD.busy(button, true);
            HOD.api(API, { data: new FormData(form) }).then(function (res) {
                HOD.toast(res.message || 'Saved.', 'success');
                HOD.dialog.close(form.closest('dialog').id);
                form.reset();
                if (form.id === 'regions-region-form') loadBoard(); else loadStream();
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
        HOD.actions.on('regions.post', postMessage);
        HOD.actions.on('regions.media', function (input) {
            var file = input.files[0];
            $('[data-regions-attachment]').hidden = !file;
            if (file) $('[data-regions-attachment-name]').textContent = file.name;
        });
        HOD.actions.on('regions.clear-media', clearMedia);
        HOD.actions.on('regions.grow', function (el) {
            el.style.height = '';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        });
        HOD.actions.on('regions.delete-message', function (btn) {
            HOD.confirm({ title: 'Remove this message?', message: 'It disappears from the stream for everyone in the region.', confirmLabel: 'Remove message', tone: 'danger' }).then(function (ok) {
                if (!ok) return;
                HOD.api(API, { action: 'admin_delete_stream_message', data: { message_id: btn.getAttribute('data-id') } })
                    .then(function (res) { HOD.toast(res.message || 'Removed.', 'success'); loadStream(); })
                    .catch(function () {});
            });
        });

        if (cfg.admin) {
            HOD.actions.on('regions.member', openMember);
            HOD.actions.on('regions.move', function (btn) {
                HOD.dialog.close('regions-member');
                HOD.api(API, { action: 'admin_reassign_user', data: { target_user_id: memberTarget, new_region_id: btn.getAttribute('data-region') } })
                    .then(function () { HOD.toast('Moved.', 'success'); loadBoard(); })
                    .catch(function () {});
            });
            HOD.actions.on('regions.mute', function (btn) {
                HOD.dialog.close('regions-member');
                HOD.api(API, { action: 'admin_mute_user', data: { target_user_id: memberTarget, mute_status: btn.getAttribute('data-mute') } })
                    .then(function (res) { HOD.toast(res.message || 'Updated.', 'success'); loadBoard(); })
                    .catch(function () {});
            });
            HOD.actions.on('regions.filter', function (input) {
                var q = input.value.trim().toLowerCase();
                if (q.length === 1) return;
                document.querySelectorAll('[data-regions-board] [data-region-id=""] > li').forEach(function (li) {
                    li.hidden = q !== '' && (li.getAttribute('data-name') || '').indexOf(q) === -1;
                });
            });
            HOD.actions.on('regions.new-region', function (btn) { openRegion(null, btn); });
            HOD.actions.on('regions.edit-region', function (btn) {
                var r = regions.filter(function (x) { return String(x.id) === btn.getAttribute('data-id'); })[0];
                openRegion(r || null, btn);
            });
            HOD.actions.on('regions.auto-assign', function (btn) {
                HOD.confirm({
                    title: 'Auto-assign unassigned members?',
                    message: 'Members with map coordinates are placed by the Jakande pivot rule.',
                    confirmLabel: 'Auto-assign'
                }).then(function (ok) {
                    if (!ok) return;
                    HOD.busy(btn, true);
                    HOD.api(API, { action: 'admin_auto_assign' })
                        .then(function (res) { HOD.toast(res.message || 'Done.', 'success'); loadBoard(); })
                        .catch(function () {})
                        .finally(function () { HOD.busy(btn, false); });
                });
            });
            HOD.actions.on('regions.broadcasts', function (btn) {
                resetBroadcast();
                HOD.dialog.open('regions-broadcast', btn);
                // The region list comes with the board.
                (regions.length ? Promise.resolve() : loadBoard()).then(loadBroadcasts);
            });
            HOD.actions.on('regions.broadcast-region', function () { resetBroadcast(); loadBroadcasts(); });
            HOD.actions.on('regions.broadcast', function (form) {
                var button = $('[data-regions-broadcast-save]');
                HOD.busy(button, true);
                HOD.api(API, { data: new FormData(form) }).then(function (res) {
                    HOD.toast(res.message || 'Saved.', 'success');
                    resetBroadcast();
                    loadBroadcasts();
                }).catch(function () {}).finally(function () { HOD.busy(button, false); });
            });
            HOD.actions.on('regions.broadcast-edit', function (btn) {
                var b = broadcasts[Number(btn.getAttribute('data-index'))];
                if (!b) return;
                var f = $('#regions-broadcast-form');
                f.manage_action.value = 'update';
                f.broadcast_id.value = b.id;
                f.message.value = b.message;
                $('[data-regions-broadcast-cancel]').hidden = false;
                $('[data-regions-broadcast-save]').querySelector('span').textContent = 'Update notice';
                f.message.focus();
            });
            HOD.actions.on('regions.broadcast-reset', resetBroadcast);
            HOD.actions.on('regions.broadcast-delete', function (btn) {
                HOD.confirm({ title: 'Delete this notice?', message: 'It is removed from the region\'s noticeboard. This cannot be undone.', confirmLabel: 'Delete notice', tone: 'danger' }).then(function (ok) {
                    if (!ok) return;
                    HOD.api(API, { action: 'admin_manage_broadcast', data: { manage_action: 'delete', broadcast_id: btn.getAttribute('data-id') } })
                        .then(function (res) { HOD.toast(res.message || 'Deleted.', 'success'); loadBroadcasts(); })
                        .catch(function () {});
                });
            });

            // The board loads the first time its tab opens; the stream only
            // polls while the hub is on screen.
            document.addEventListener('ui:tab', function (e) {
                var tab = e.detail && e.detail.tab;
                if (tab === 'admin') {
                    stopPolling();
                    if (!boardLoaded) loadBoard();
                } else if (tab === 'hub') {
                    // Reload: an admin may have just placed themselves in a region.
                    loadHub();
                }
            });
        }

        var adminPanel = $('#panel-admin');
        if (adminPanel && !adminPanel.hidden) {
            loadBoard();
        } else {
            loadHub();
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
