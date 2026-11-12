// /assets/ui/js/pages/reach.js
// Reach & Evangelism (modules/reach/index.php): campaigns (create, edit,
// delete, share with a QR drawn on this device), the follow-up queue and a
// lead's drawer (claim, assign, log, push to Embrace), campaign settings,
// and analytics (vendored Chart.js via HOD.chart, CSV, PDF).
// Requests go to /api/reach_api.php with the same shapes as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/reach_api.php';
    var esc = HOD.esc;
    var cfg = HOD.data('reach-config') || {};
    var TYPES = cfg.types || [{ code: 'Other', label: 'Other' }];
    var DEFAULT_IMAGE = cfg.defaultImage || '/assets/images/hod_lekki.jpeg';
    var TIER = { Rapid: 'Quick', Standard: 'Standard', Rich: 'Detailed' };

    var FU_TABS = [['not_spoken', 'Not spoken to'], ['will_come', 'Will come to church'], ['spoken', 'Spoken to'], ['cold', 'Cold'], ['converted', 'Visited church'], ['all', 'All']];
    var CATEGORIES = ['New_Convert', 'Unsaved', 'Saved', 'Broken', 'Dechurched', 'Other'];
    var CATEGORY_TONE = { New_Convert: 'ui-badge--success', Unsaved: 'ui-badge--danger', Saved: 'ui-badge--info', Broken: 'ui-badge--warning', Dechurched: 'ui-badge--warning', Other: '' };
    var OUTCOMES = ['Reached', 'Promised_Church', 'No_Answer', 'Wrong_Number', 'Rescheduled', 'Requested_No_Contact', 'Declined'];
    var OUTCOME_TONE = { Reached: 'ui-badge--success', Promised_Church: 'ui-badge--primary', No_Answer: '', Wrong_Number: 'ui-badge--warning', Rescheduled: 'ui-badge--info', Requested_No_Contact: 'ui-badge--danger', Declined: 'ui-badge--danger' };
    var CHANNELS = { Call: 'phone', WhatsApp: 'message-circle', SMS: 'message-square-text', In_Person_Visit: 'house', Church_Service: 'building-2' };
    var WORDS = {
        Promised_Church: 'Promised to come to church', Will_Attend: 'Will come to church', Converted: 'Visited church',
        Not_Spoken_To: 'Not spoken to', Spoken_To: 'Spoken to', Declined: 'Declined', Requested_No_Contact: 'Asked not to be contacted'
    };

    var campaigns = [];
    var editingFlyer = null;
    var ai = { options: [], i: 0 };
    var fu = { ready: false, sub: 'not_spoken', category: '', overdue: false, page: 1, members: [], leadId: null };
    var an = { ready: false, preset: 'this_month', charts: [] };

    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    function word(s) { return WORDS[s] || label(s); }
    function pad2(n) { return String(n).padStart(2, '0'); }
    function toMinutes(t) { var p = String(t).split(':').map(Number); return p[0] * 60 + p[1]; }
    function ymd(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(https?:\/\/|\/|\.\.?\/)/i.test(url) ? url : '';
    }
    function typeLabel(code) {
        var t = TYPES.filter(function (x) { return x.code === code; })[0];
        return t ? t.label : label(code);
    }
    function relTime(s) {
        var d = s ? new Date(String(s).replace(' ', 'T')) : null;
        if (!d || isNaN(d)) return '';
        var mins = Math.round((Date.now() - d.getTime()) / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return mins + ' min ago';
        if (mins < 1440) return Math.round(mins / 60) + ' h ago';
        if (mins < 10080) return Math.round(mins / 1440) + ' d ago';
        return HOD.format.date(d);
    }
    function badge(text, tone) { return '<span class="ui-badge ' + (tone || '') + '">' + esc(text) + '</span>'; }
    function catBadges(cats) {
        return String(cats || 'Other').split(',').filter(Boolean).map(function (c) { return badge(label(c), CATEGORY_TONE[c]); }).join('');
    }
    function fail(err) {
        if (err && err.name === 'AbortError') return;
        HOD.toast((err && err.message) || 'That did not work. Please try again.', 'danger');
    }

    /* =================================================================== *
     * Campaigns
     * =================================================================== */

    function statusBadge(s) {
        return s === 'Completed' ? badge('Completed', '') : s === 'Cancelled' ? badge('Cancelled', 'ui-badge--danger') : badge('Active', 'ui-badge--success');
    }

    function renderCampaigns() {
        var box = $('[data-reach-campaigns]');
        if (!campaigns.length) {
            box.innerHTML = '<div class="ui-card sm:col-span-2 xl:col-span-3"><div class="ui-empty"><div class="ui-empty__icon">' + HOD.icon('megaphone') + '</div>'
                + '<p class="ui-empty__title">No campaigns yet</p><p class="ui-empty__text">Schedule your first outreach and share its capture link.</p>'
                + '<div class="ui-empty__actions"><button type="button" class="ui-btn ui-btn--primary" data-action="reach.new">' + HOD.icon('plus') + '<span>New campaign</span></button></div></div></div>';
            return;
        }
        box.innerHTML = campaigns.map(function (c, i) {
            var img = safeUrl(c.image_url) || DEFAULT_IMAGE;
            return '<article class="ui-card flex flex-col overflow-hidden">'
                + '<div class="relative h-36 bg-surface-sunken"><img class="h-full w-full object-cover' + (c.flyer_path ? ' object-top' : '') + '" src="' + esc(img) + '" alt="" loading="lazy">'
                + (c.flyer_path ? '' : '<span class="ui-badge absolute bottom-2 left-2">Default image</span>') + '</div>'
                + '<div class="ui-card__body flex flex-1 flex-col gap-4">'
                + '<div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="ui-meta">' + esc(typeLabel(c.campaign_type)) + '</p>'
                + '<h3 class="truncate text-base font-semibold text-ink">' + esc(c.title) + '</h3>'
                + '<p class="truncate text-xs text-ink-subtle">/reach?c=' + esc(c.slug) + '</p></div>' + statusBadge(c.status) + '</div>'
                + '<dl class="grid grid-cols-3 gap-2 text-center">'
                + '<div class="ui-card ui-card--sunken px-2 py-2"><dt class="ui-meta">Date</dt><dd class="mt-0.5 text-xs font-medium text-ink">' + esc(c.nice_date || '—') + '</dd></div>'
                + '<div class="ui-card ui-card--sunken px-2 py-2"><dt class="ui-meta">Souls</dt><dd class="ui-num mt-0.5 text-sm font-semibold text-ink">' + esc(c.souls_count) + '</dd></div>'
                + '<div class="ui-card ui-card--sunken px-2 py-2"><dt class="ui-meta">Form</dt><dd class="mt-0.5 text-xs font-medium text-ink">' + esc(TIER[c.payload_tier] || c.payload_tier) + '</dd></div>'
                + '</dl>'
                + '<div class="mt-auto flex gap-2">'
                + '<button type="button" class="ui-btn ui-btn--primary ui-btn--sm flex-1" data-action="reach.share" data-id="' + esc(c.id) + '">' + HOD.icon('send') + '<span>Share</span></button>'
                + '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm flex-1" data-action="reach.edit" data-index="' + i + '">Edit</button>'
                + '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm ui-btn--icon" data-action="reach.delete" data-index="' + i + '" aria-label="Delete ' + esc(c.title) + '" data-tooltip="Delete">' + HOD.icon('trash-2') + '</button>'
                + '</div></div></article>';
        }).join('');
    }

    function loadCampaigns() {
        return HOD.api(API, { method: 'GET', action: 'fetch_campaigns', quiet: true }).then(function (res) {
            campaigns = res.data || [];
            renderCampaigns();
        }).catch(fail);
    }

    function form() { return $('#reach-campaign-form'); }

    function renderTypeOptions(selected) {
        var opts = TYPES.map(function (t) { return '<option value="' + esc(t.code) + '">' + esc(t.label) + '</option>'; });
        if (selected && !TYPES.some(function (t) { return t.code === selected; })) opts.push('<option value="' + esc(selected) + '">' + esc(typeLabel(selected)) + '</option>');
        var sel = form().campaign_type;
        sel.innerHTML = opts.join('');
        sel.value = selected || TYPES[0].code;
    }

    function markDuration(mins) {
        document.querySelectorAll('[data-action="reach.duration"]').forEach(function (b) {
            b.setAttribute('aria-pressed', Number(b.getAttribute('data-min')) === mins ? 'true' : 'false');
        });
    }
    function currentDuration() {
        var f = form();
        return f.start_time.value && f.end_time.value ? toMinutes(f.end_time.value) - toMinutes(f.start_time.value) : 0;
    }
    function setDuration(mins) {
        var f = form();
        if (!f.start_time.value) { var n = new Date(); f.start_time.value = pad2(n.getHours()) + ':' + pad2(n.getMinutes()); }
        var end = Math.min(toMinutes(f.start_time.value) + mins, 23 * 60 + 59);
        f.end_time.value = pad2(Math.floor(end / 60)) + ':' + pad2(end % 60);
        markDuration(mins);
    }

    function metaCount() { $('[data-reach-meta-count]').textContent = (form().meta_description.value || '').length; }
    function aiReset() {
        ai.options = []; ai.i = 0;
        $('[data-reach-ai-next]').hidden = true;
        $('[data-reach-ai]').querySelector('span').textContent = 'Write with AI';
    }
    function aiApply() {
        var o = ai.options[ai.i];
        var f = form();
        f.meta_description.value = o.meta_description || '';
        f.share_scripture.value = o.share_scripture || '';
        $('#reach-scripture-pick').value = '';
        metaCount();
        var next = $('[data-reach-ai-next]');
        next.hidden = ai.options.length < 2;
        next.querySelector('span').textContent = 'Another option (' + (ai.i + 1) + '/' + ai.options.length + ')';
    }

    function openCampaign(c, opener) {
        var f = form();
        f.reset();
        HOD.form.clearErrors(f);
        aiReset();
        f.elements.action.value = c ? 'edit_campaign' : 'create_campaign';
        f.elements.id.value = c ? c.id : '';
        $('#reach-campaign-title').textContent = c ? 'Edit campaign' : 'New campaign';
        renderTypeOptions(c ? c.campaign_type : null);
        if (c) {
            f.title.value = c.title || '';
            f.campaign_date.value = c.campaign_date || '';
            f.start_time.value = (c.start_time || '').slice(0, 5);
            f.end_time.value = (c.end_time || '').slice(0, 5);
            f.location.value = c.location || '';
            f.meta_description.value = c.meta_description || '';
            f.share_scripture.value = c.share_scripture || '';
            var tier = f.querySelector('input[name="payload_tier"][value="' + (c.payload_tier || 'Rich') + '"]');
            if (tier) tier.checked = true;
            f.status.value = c.status || 'Active';
            editingFlyer = c.flyer_path || null;
            $('[data-reach-flyer-preview]').src = safeUrl(c.image_url) || DEFAULT_IMAGE;
        } else {
            var start = new Date(Math.ceil(Date.now() / 300000) * 300000);
            f.campaign_date.value = ymd(start);
            f.start_time.value = pad2(start.getHours()) + ':' + pad2(start.getMinutes());
            f.end_time.value = '';
            editingFlyer = null;
            $('[data-reach-flyer-preview]').src = DEFAULT_IMAGE;
        }
        markDuration(currentDuration());
        $('[data-reach-remove-flyer]').hidden = !editingFlyer;
        $('[data-reach-status]').hidden = !c;
        metaCount();
        HOD.dialog.open('reach-campaign', opener);
    }

    function shareCampaign(btn) {
        HOD.busy(btn, true);
        HOD.api(API, { method: 'GET', action: 'fetch_campaign_share_bundle', data: { id: btn.getAttribute('data-id') } }).then(function (res) {
            var d = res.data || {};
            $('#reach-share-desc').textContent = d.title || '';
            $('#reach-share-link').value = d.public_url || '';
            $('#reach-share-text').value = d.whatsapp_text || '';
            $('[data-reach-whatsapp]').href = safeUrl(d.whatsapp_link) || '#';
            var holder = $('[data-reach-qr]');
            holder.innerHTML = '<span class="ui-skeleton block h-full w-full"></span>';
            // Drawn on this device: the link never goes to a third-party QR service.
            import(HOD.config.vendor.qrcode).then(function (mod) {
                var qr = mod.qrcode(0, 'M');
                qr.addData(d.public_url || '');
                qr.make();
                holder.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
            }).catch(function () { holder.innerHTML = '<p class="ui-meta text-center">The QR code did not load.</p>'; });
            HOD.dialog.open('reach-share', btn);
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    /* =================================================================== *
     * Follow-up
     * =================================================================== */

    function pickCategory(c) {
        fu.category = c;
        document.querySelectorAll('[data-action="reach.category"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-cat') === c ? 'true' : 'false');
        });
    }

    function initFollowUp() {
        if (fu.ready) return;
        fu.ready = true;
        $('[data-reach-categories]').innerHTML = [''].concat(CATEGORIES).map(function (c) {
            return '<button type="button" class="ui-chip" data-action="reach.category" data-cat="' + c + '" aria-pressed="' + (c === '' ? 'true' : 'false') + '">' + esc(c ? label(c) : 'All categories') + '</button>';
        }).join('');
        HOD.api(API, { action: 'fetch_campaigns', quiet: true }).then(function (res) {
            $('#reach-fu-campaign').insertAdjacentHTML('beforeend', (res.data || []).map(function (c) {
                return '<option value="' + esc(c.id) + '">' + esc(c.title) + '</option>';
            }).join(''));
        }).catch(function () {});
        HOD.api(API, { action: 'list_reach_members', quiet: true }).then(function (res) {
            fu.members = res.data || [];
            $('#reach-fu-assignee').insertAdjacentHTML('beforeend', fu.members.map(function (m) {
                return '<option value="' + esc(m.id) + '">' + esc(m.name) + '</option>';
            }).join(''));
        }).catch(function () {});
        loadLeads(true);
    }

    function renderSubTabs(counts) {
        counts = counts || {};
        $('[data-reach-subtabs]').innerHTML = FU_TABS.map(function (t) {
            return '<button type="button" class="ui-chip" data-action="reach.sub" data-sub="' + t[0] + '" aria-pressed="' + (fu.sub === t[0] ? 'true' : 'false') + '">'
                + esc(t[1]) + ' <span class="ui-count">' + esc(counts[t[0]] == null ? 0 : counts[t[0]]) + '</span></button>';
        }).join('');
    }

    function leadCard(l) {
        var name = ((l.first_name || '') + ' ' + (l.last_name || '')).trim();
        var added = 'Added by ' + (l.capturer_name || 'unknown') + ' · ' + (l.campaign_title || 'No campaign') + ' · ' + relTime(l.captured_at || l.created_at);
        var last = l.last_outcome ? 'Last: ' + word(l.last_outcome) + ' · ' + (l.last_follower_name || '—') + ' · ' + relTime(l.last_follow_up_date) : '';
        var flags = '';
        if (Number(l.will_attend_church) === 1 && !l.pushed_to_embrace_at) flags += badge('Coming to church', 'ui-badge--primary');
        if (Number(l.willing_for_visit) === 1) flags += badge('Willing to visit', 'ui-badge--danger');
        var assign = l.assigned_to
            ? '<span class="inline-flex items-center gap-1.5 text-xs font-medium text-ink-muted">' + HOD.icon('user') + esc(l.assignee_name) + '</span>'
            : '<span class="text-xs text-ink-subtle">Unassigned</span>' + (l.pushed_to_embrace_at ? '' : '<button type="button" class="ui-btn ui-btn--soft ui-btn--sm" data-action="reach.claim" data-id="' + esc(l.id) + '">Claim</button>');
        return '<article class="ui-card flex flex-col">'
            + '<button type="button" class="flex flex-1 flex-col gap-3 rounded-t-ui-lg p-4 text-left hover:bg-surface-hover" data-action="reach.lead" data-id="' + esc(l.id) + '" aria-haspopup="dialog">'
            + '<span class="flex items-start justify-between gap-2"><span class="min-w-0"><span class="block truncate font-semibold text-ink">' + esc(name) + '</span>'
            + '<span class="block text-sm text-ink-muted">' + esc(l.phone || 'No phone') + '</span></span><span class="flex flex-col items-end gap-1">' + flags + '</span></span>'
            + '<span class="flex flex-wrap gap-1.5">' + catBadges(l.category) + '</span>'
            + '<span class="block text-xs text-ink-subtle">' + esc(added) + '</span>'
            + (last ? '<span class="block text-xs text-ink-muted">' + esc(last) + '</span>' : '')
            + '</button>'
            + '<div class="flex items-center justify-between gap-2 border-t border-line px-4 py-2.5">' + assign + '</div>'
            + '</article>';
    }

    var leadsAbort = null;
    function loadLeads(reset) {
        fu.page = reset ? 1 : fu.page + 1;
        var list = $('[data-reach-leads]');
        if (reset) list.innerHTML = [1, 2, 3].map(function () { return '<div class="ui-card p-4"><span class="ui-skeleton ui-skeleton--text w-2/3"></span><span class="ui-skeleton ui-skeleton--text mt-2 w-1/2"></span></div>'; }).join('');
        if (leadsAbort) leadsAbort.abort();
        leadsAbort = new AbortController();
        HOD.api(API, {
            action: 'list_leads', quiet: true, signal: leadsAbort.signal,
            data: {
                sub_tab: fu.sub, page: fu.page, category: fu.category,
                campaign_id: $('#reach-fu-campaign').value, assignee: $('#reach-fu-assignee').value,
                area: $('#reach-fu-area').value.trim(), search: $('#reach-fu-search').value.trim(),
                willing_only: $('#reach-fu-willing').checked ? 1 : 0, overdue_only: fu.overdue ? 1 : 0
            }
        }).then(function (res) {
            var d = res.data || {};
            renderSubTabs(d.counts);
            $('[data-reach-overdue-count]').textContent = d.overdue || 0;
            $('[data-reach-overdue-hint]').textContent = fu.overdue ? 'Showing only overdue leads. Tap to show everyone.' : 'Tap to show only these.';
            $('[data-reach-overdue]').hidden = !d.overdue && !fu.overdue;
            var html = (d.leads || []).map(leadCard).join('');
            if (reset) {
                list.innerHTML = html || '<div class="ui-card md:col-span-2 xl:col-span-3"><div class="ui-empty ui-empty--compact"><div class="ui-empty__icon">' + HOD.icon('users') + '</div><p class="ui-empty__title">Nobody here</p><p class="ui-empty__text">No leads match these filters.</p></div></div>';
            } else {
                list.insertAdjacentHTML('beforeend', html);
            }
            $('[data-reach-more]').hidden = !d.has_more;
        }).catch(fail);
    }

    function section(title, body) { return '<section><h3 class="ui-meta mb-2">' + esc(title) + '</h3>' + body + '</section>'; }
    function field(k, v) { return v ? '<div><dt class="ui-meta">' + esc(k) + '</dt><dd class="mt-0.5 whitespace-pre-line text-sm text-ink">' + esc(v) + '</dd></div>' : ''; }

    function renderLead(d) {
        var l = d.lead || {};
        var name = ((l.first_name || '') + ' ' + (l.last_name || '')).trim();
        $('#reach-lead-title').textContent = name || 'Lead';
        $('#reach-lead-desc').textContent = [l.campaign_title, word(l.status)].filter(Boolean).join(' · ');
        var wa = String(l.phone || '').replace(/\D/g, '').replace(/^0/, '234');

        var profile = '<div class="flex flex-wrap gap-1.5">' + catBadges(l.category)
            + (Number(l.willing_for_visit) === 1 ? badge('Home visit wanted', 'ui-badge--danger') : '')
            + (Number(l.will_attend_church) ? badge('Will come to church', 'ui-badge--primary') : '') + '</div>'
            + (l.phone ? '<div class="mt-3 grid grid-cols-2 gap-2"><a class="ui-btn ui-btn--secondary" href="tel:' + esc(l.phone) + '">' + HOD.icon('phone') + '<span>Call</span></a>'
                + '<a class="ui-btn ui-btn--secondary" href="https://wa.me/' + esc(wa) + '" target="_blank" rel="noopener">' + HOD.icon('message-circle') + '<span>WhatsApp</span></a></div>' : '')
            + '<dl class="mt-3 grid grid-cols-2 gap-3">' + field('Phone', l.phone) + field('Address', l.address) + field('Age group', l.age_band) + field('Marital status', l.marital_status)
            + field('Language', l.language) + field('Best time to call', l.best_time_to_call) + field('Assigned to', l.assignee_name || 'Unassigned') + '</dl>'
            + '<dl class="mt-3 ui-stack ui-stack--sm">' + field('Prayer request', l.prayer_request) + field('Notes', l.notes) + '</dl>';

        var actions = '';
        if (!l.assigned_to && !l.pushed_to_embrace_at) {
            actions += '<button type="button" class="ui-btn ui-btn--primary ui-btn--block" data-action="reach.claim" data-id="' + esc(l.id) + '">Claim this lead</button>';
        }
        if (d.can_assign) {
            actions += '<div class="flex gap-2"><label class="sr-only" for="reach-assign">Assign to</label><select class="ui-select min-w-0 flex-1" id="reach-assign" data-ui-combobox><option value="">Choose a Reach member…</option>'
                + fu.members.map(function (m) { return '<option value="' + esc(m.id) + '"' + (Number(m.id) === Number(l.assigned_to) ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('')
                + '</select><button type="button" class="ui-btn ui-btn--secondary" data-action="reach.assign" data-id="' + esc(l.id) + '" data-re="' + (l.assigned_to ? 1 : 0) + '">' + (l.assigned_to ? 'Reassign' : 'Assign') + '</button></div>';
        }
        if (l.pushed_to_embrace_at) {
            actions += '<div class="ui-banner ui-banner--success">' + HOD.icon('circle-check') + '<div class="ui-banner__body"><p class="ui-banner__text">Visited church · with Embrace · ' + esc(relTime(l.pushed_to_embrace_at)) + '</p></div></div>';
            if (d.can_assign) {
                actions += '<label class="ui-check"><input type="checkbox" data-action-change="reach.testimony" data-id="' + esc(l.id) + '"' + (Number(l.share_testimony_in_report) ? ' checked' : '') + '><span>Share their testimony in the monthly report</span></label>';
            }
        } else if (d.can_push) {
            actions += '<button type="button" class="ui-btn ui-btn--brand ui-btn--block" data-action="reach.embrace" data-id="' + esc(l.id) + '">They came to church: send to Embrace</button>';
        }

        var log = '<form class="ui-card ui-card--sunken ui-stack ui-stack--sm p-4" data-action="reach.log" novalidate>'
            + '<input type="hidden" name="action" value="log_follow_up"><input type="hidden" name="lead_id" value="' + esc(l.id) + '">'
            + '<div class="grid grid-cols-2 gap-2">'
            + '<div><label class="ui-label" for="reach-log-channel">How</label><select class="ui-select" id="reach-log-channel" name="channel" required>' + Object.keys(CHANNELS).map(function (c) { return '<option value="' + c + '">' + esc(label(c)) + '</option>'; }).join('') + '</select></div>'
            + '<div><label class="ui-label" for="reach-log-outcome">Outcome</label><select class="ui-select" id="reach-log-outcome" name="outcome" required>' + OUTCOMES.map(function (o) { return '<option value="' + o + '">' + esc(word(o)) + '</option>'; }).join('') + '</select></div>'
            + '</div>'
            + '<div><label class="ui-label" for="reach-log-notes">What happened <span class="ui-label__opt">(optional)</span></label><textarea class="ui-textarea" id="reach-log-notes" name="notes" rows="2" placeholder="e.g. Coming on Sunday"></textarea></div>'
            + '<div><label class="ui-label" for="reach-log-next">Next contact <span class="ui-label__opt">(optional)</span></label><input class="ui-input" type="date" id="reach-log-next" name="next_touch_date"></div>'
            + '<button type="submit" class="ui-btn ui-btn--primary ui-btn--block">Save follow-up</button></form>';

        var timeline = (d.follow_ups || []).length ? '<ol class="ui-list -mx-5">' + d.follow_ups.map(function (f) {
            return '<li class="ui-list__item items-start px-5"><span class="ui-list__icon">' + HOD.icon(CHANNELS[f.channel] || 'phone') + '</span>'
                + '<span class="ui-list__main"><span class="flex flex-wrap items-center gap-2">' + badge(word(f.outcome), OUTCOME_TONE[f.outcome]) + '<span class="text-xs text-ink-subtle">' + esc(label(f.channel)) + ' · ' + esc(f.follower_name || '—') + ' · ' + esc(relTime(f.created_at)) + '</span></span>'
                + (f.notes ? '<span class="mt-1 block whitespace-pre-line text-sm text-ink">' + esc(f.notes) + '</span>' : '')
                + (f.next_touch_date ? '<span class="mt-1 block text-xs font-medium text-primary">Next contact: ' + esc(HOD.format.date(f.next_touch_date)) + '</span>' : '')
                + '</span></li>';
        }).join('') + '</ol>' : '<p class="text-sm text-ink-subtle">No follow-ups yet. Be the first to reach out.</p>';

        var captures = '<ul class="ui-stack ui-stack--sm">' + (d.captures || []).map(function (c, i) {
            return '<li class="flex items-center justify-between gap-2 text-sm"><span class="text-ink">' + (i === 0 ? 'Captured' : 'Captured again') + ' by ' + esc(c.name || 'unknown')
                + (c.captured_by_user_id ? '' : ' ' + badge('Guest', 'ui-badge--warning')) + '</span><span class="text-xs text-ink-subtle">' + esc(relTime(c.captured_at || c.created_at)) + '</span></li>';
        }).join('') + '</ul>';

        var history = (d.assignments || []).length ? section('Assignment history', '<ul class="ui-stack ui-stack--sm">' + d.assignments.map(function (a) {
            return '<li class="text-xs text-ink-muted"><span class="font-medium text-ink">' + esc(label(a.action)) + '</span> → ' + esc(a.to_name || '—') + ' by ' + esc(a.by_name || '—') + ' · ' + esc(relTime(a.created_at)) + '</li>';
        }).join('') + '</ul>') : '';

        $('[data-reach-lead]').innerHTML = section('Profile', profile)
            + (actions ? section('Actions', '<div class="ui-stack ui-stack--sm">' + actions + '</div>') : '')
            + section('Log a follow-up', log) + section('Timeline', timeline) + section('Capture chain', captures) + history;
    }

    function openLead(id, opener) {
        fu.leadId = id;
        $('#reach-lead-title').textContent = 'Loading…';
        $('#reach-lead-desc').textContent = '';
        $('[data-reach-lead]').innerHTML = '<span class="ui-skeleton ui-skeleton--title w-1/2"></span><span class="ui-skeleton ui-skeleton--text w-3/4"></span><span class="ui-skeleton ui-skeleton--text w-2/3"></span>';
        var dialog = $('#reach-lead');
        if (!dialog.open) HOD.dialog.open('reach-lead', opener);
        HOD.api(API, { action: 'fetch_lead_detail', data: { lead_id: id }, quiet: true }).then(function (res) {
            renderLead(res.data || {});
        }).catch(function (err) { fail(err); HOD.dialog.close('reach-lead'); });
    }

    function leadAction(btn, action, data, success) {
        HOD.busy(btn, true);
        HOD.api(API, { action: action, data: data }).then(function (res) {
            HOD.toast(success ? success(res) : (res.message || 'Done.'), 'success');
            loadLeads(true);
            if (fu.leadId && $('#reach-lead').open) openLead(fu.leadId);
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    /* =================================================================== *
     * Settings
     * =================================================================== */

    function renderTypeChips() {
        $('[data-reach-types]').innerHTML = TYPES.map(function (t) {
            return '<span class="ui-chip">' + esc(t.label)
                + (t.code === 'Other' ? '' : '<button type="button" class="ml-1 grid h-5 w-5 place-items-center rounded-full hover:bg-surface-hover" data-action="reach.remove-type" data-code="' + esc(t.code) + '" aria-label="Remove ' + esc(t.label) + '">' + HOD.icon('x') + '</button>')
                + '</span>';
        }).join('');
    }

    function openSettings(btn) {
        renderTypeChips();
        $('[data-reach-default-image]').src = DEFAULT_IMAGE;
        HOD.api(API, { action: 'fetch_campaign_settings', quiet: true }).then(function (res) {
            var f = $('#reach-settings-form');
            f.overdue_days.value = (res.data || {}).overdue_days || '';
            f.guide.value = (res.data || {}).guide || '';
        }).catch(fail);
        HOD.dialog.open('reach-settings', btn);
    }

    function applyDefaultImage(res) {
        HOD.toast(res.message || 'Saved.', 'success');
        DEFAULT_IMAGE = (res.data || {}).default_image || DEFAULT_IMAGE;
        $('[data-reach-default-image]').src = DEFAULT_IMAGE;
        loadCampaigns();
    }

    /* =================================================================== *
     * Analytics
     * =================================================================== */

    function presetRange(p) {
        var now = new Date(), y = now.getFullYear(), m = now.getMonth();
        switch (p) {
            case 'last_month': return [ymd(new Date(y, m - 1, 1)), ymd(new Date(y, m, 0))];
            case 'last_3': return [ymd(new Date(y, m - 2, 1)), ymd(new Date(y, m + 1, 0))];
            case 'ytd': return [y + '-01-01', ymd(now)];
            case 'custom': return [$('#reach-an-from').value, $('#reach-an-to').value];
            default: return [ymd(new Date(y, m, 1)), ymd(new Date(y, m + 1, 0))];
        }
    }

    function initAnalytics() {
        if (an.ready) return;
        an.ready = true;
        var presets = [['this_month', 'This month'], ['last_month', 'Last month'], ['last_3', 'Last 3 months'], ['ytd', 'Year to date'], ['custom', 'Custom']];
        $('[data-reach-presets]').innerHTML = presets.map(function (p) {
            return '<button type="button" class="ui-chip" data-action="reach.preset" data-p="' + p[0] + '" aria-pressed="' + (p[0] === an.preset ? 'true' : 'false') + '">' + esc(p[1]) + '</button>';
        }).join('');
        var r = presetRange('this_month');
        $('#reach-an-from').value = r[0];
        $('#reach-an-to').value = r[1];
        loadAnalytics();
    }

    function card(title, body, extra) {
        return '<section class="ui-card ' + (extra || '') + '"><div class="ui-card__header"><h3 class="ui-card__title">' + esc(title) + '</h3></div><div class="ui-card__body pt-0">' + body + '</div></section>';
    }

    function loadAnalytics() {
        var r = presetRange(an.preset);
        if (!r[0] || !r[1]) return;
        an.charts.forEach(function (c) { if (c && c.destroy) c.destroy(); });
        an.charts = [];
        var body = $('[data-reach-analytics]');
        body.innerHTML = '<div class="ui-grid ui-grid--stats">' + [1, 2, 3, 4].map(function () { return '<div class="ui-card ui-stat"><span class="ui-skeleton ui-skeleton--text w-1/2"></span><span class="ui-skeleton mt-2 h-7 w-16"></span></div>'; }).join('') + '</div>';
        HOD.api(API, { action: 'fetch_analytics', data: { from_date: r[0], to_date: r[1] }, quiet: true }).then(function (res) {
            renderAnalytics(res.data || {});
        }).catch(fail);
    }

    function renderAnalytics(d) {
        var k = d.kpis || {};
        var body = $('[data-reach-analytics]');
        if (!k.souls && !(d.campaigns_table || []).length) {
            body.innerHTML = '<div class="ui-card">' + '<div class="ui-empty"><div class="ui-empty__icon">' + HOD.icon('chart-column') + '</div><p class="ui-empty__title">Nothing in this period</p><p class="ui-empty__text">Choose another date range, or run a campaign and capture some souls.</p></div></div>';
            return;
        }
        var tile = function (l, v) { return '<div class="ui-card ui-stat"><span class="ui-stat__label">' + esc(l) + '</span><span class="ui-stat__value">' + esc(v) + '</span></div>'; };
        var cats = d.category_breakdown || [];
        var legend = '<table class="w-full text-sm"><tbody>' + cats.map(function (c, i) {
            return '<tr class="border-b border-line last:border-0"><td class="py-1.5"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-sm align-middle" data-swatch="' + i + '"></span>' + esc(label(c.category)) + '</td>'
                + '<td class="ui-num py-1.5 text-right font-medium text-ink">' + esc(c.count) + '</td><td class="ui-num py-1.5 pl-2 text-right text-ink-subtle">' + (k.souls ? Math.round(c.count / k.souls * 100) + '%' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
        var leaders = (d.volunteer_leaderboard || []).length
            ? '<div class="ui-table-wrap"><table class="ui-table ui-table--compact"><thead><tr><th>#</th><th>Volunteer</th><th class="text-right">Souls</th></tr></thead><tbody>' + d.volunteer_leaderboard.map(function (v, i) {
                return '<tr><td class="ui-num text-ink-subtle">' + (i + 1) + '</td><td class="font-medium">' + esc(v.name) + (v.is_member ? '' : ' ' + badge('Guest', 'ui-badge--warning')) + '</td><td class="ui-num text-right">' + esc(v.souls) + '</td></tr>';
            }).join('') + '</tbody></table></div>'
            : '<p class="text-sm text-ink-subtle">No captures in this period.</p>';
        var camps = (d.campaigns_table || []).length
            ? '<div class="ui-table-wrap"><table class="ui-table ui-table--compact min-w-[520px]"><thead><tr><th>Campaign</th><th>Date</th><th class="text-right">Souls</th><th class="text-right">Follow-up</th><th class="text-right">Visited church</th><th>Last activity</th></tr></thead><tbody>' + d.campaigns_table.map(function (c) {
                return '<tr><td class="font-medium">' + esc(c.title) + '</td><td class="whitespace-nowrap">' + esc(c.date ? HOD.format.date(c.date) : '—') + '</td><td class="ui-num text-right">' + esc(c.souls) + '</td><td class="ui-num text-right">' + esc((c.follow_up_rate || 0) + '%') + '</td><td class="ui-num text-right">' + esc((c.conversion_rate || 0) + '%') + '</td><td class="whitespace-nowrap">' + esc(relTime(c.last_activity) || '—') + '</td></tr>';
            }).join('') + '</tbody></table></div>'
            : '<p class="text-sm text-ink-subtle">No campaigns in this period.</p>';
        var areas = d.area_breakdown || [];

        body.innerHTML = '<div class="ui-grid ui-grid--stats">' + tile('Souls captured', HOD.format.number(k.souls)) + tile('Campaigns run', HOD.format.number(k.campaigns))
            + tile('Follow-up rate', (k.follow_up_rate || 0) + '%') + tile('Visited church', (k.conversion_rate || 0) + '%') + '</div>'
            + (k.overdue ? '<button type="button" class="ui-banner ui-banner--warning mt-4 w-full text-left" data-action="reach.goto-overdue">' + HOD.icon('clock') + '<span class="ui-banner__body"><span class="ui-banner__text">' + esc(k.overdue) + ' assigned leads have had no follow-up for too long. Show them.</span></span></button>' : '')
            + '<div class="mt-5 grid gap-5 lg:grid-cols-2">'
            + card('Follow-up funnel', '<div class="h-64"><canvas data-chart="funnel" role="img" aria-label="Follow-up funnel: ' + esc((d.funnel || []).map(function (f) { return f.stage + ' ' + f.count; }).join(', ')) + '"></canvas></div>')
            + card('By category', '<p class="ui-meta mb-3">A person can be in more than one · % of souls captured</p><div class="grid items-center gap-4 sm:grid-cols-2"><div class="h-52"><canvas data-chart="cat" role="img" aria-label="Souls by category"></canvas></div>' + legend + '</div>')
            + '</div>'
            + card('Top areas', areas.length ? '<div data-area-holder><canvas data-chart="area" role="img" aria-label="Top areas by souls captured"></canvas></div>' : '<p class="text-sm text-ink-subtle">No addresses recorded in this period.</p>', 'mt-5')
            + '<div class="mt-5 grid gap-5 lg:grid-cols-2">' + card('Volunteer leaderboard', leaders) + card('Campaigns', camps) + '</div>';

        body.querySelectorAll('[data-swatch]').forEach(function (s) { s.style.backgroundColor = HOD.palette[Number(s.getAttribute('data-swatch')) % HOD.palette.length]; });
        var holder = body.querySelector('[data-area-holder]');
        if (holder) holder.style.height = Math.max(140, areas.length * 28 + 30) + 'px';

        var hbar = { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } };
        var funnel = d.funnel || [];
        HOD.chart(body.querySelector('[data-chart="funnel"]'), {
            type: 'bar',
            data: { labels: funnel.map(function (f) { return f.stage + ' (' + f.count + ')'; }), datasets: [{ data: funnel.map(function (f) { return f.count; }), backgroundColor: HOD.ramp.slice(1, 1 + funnel.length), borderRadius: 4, maxBarThickness: 72 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
        }).then(function (c) { an.charts.push(c); }).catch(function () {});
        HOD.chart(body.querySelector('[data-chart="cat"]'), {
            type: 'bar',
            data: { labels: cats.map(function (c) { return label(c.category); }), datasets: [{ data: cats.map(function (c) { return c.count; }), backgroundColor: cats.map(function (c, i) { return HOD.palette[i % HOD.palette.length]; }), borderRadius: 4, maxBarThickness: 18 }] },
            options: hbar
        }).then(function (c) { an.charts.push(c); }).catch(function () {});
        if (areas.length) {
            HOD.chart(body.querySelector('[data-chart="area"]'), {
                type: 'bar',
                data: { labels: areas.map(function (a) { return a.area; }), datasets: [{ data: areas.map(function (a) { return a.count; }), backgroundColor: HOD.palette[0], borderRadius: 4, maxBarThickness: 18 }] },
                options: hbar
            }).then(function (c) { an.charts.push(c); }).catch(function () {});
        }
    }

    /* =================================================================== *
     * Wire up
     * =================================================================== */

    function init() {
        // Campaigns
        HOD.actions.on('reach.new', function (btn) { openCampaign(null, btn); });
        HOD.actions.on('reach.edit', function (btn) { openCampaign(campaigns[Number(btn.getAttribute('data-index'))], btn); });
        HOD.actions.on('reach.delete', function (btn) {
            var c = campaigns[Number(btn.getAttribute('data-index'))];
            if (!c) return;
            HOD.confirm({ title: 'Delete “' + c.title + '”?', message: 'With souls captured, it is marked Cancelled instead.', confirmLabel: 'Delete campaign', tone: 'danger' }).then(function (ok) {
                if (!ok) return;
                HOD.api(API, { action: 'delete_campaign', data: { id: c.id } }).then(function (res) { HOD.toast(res.message || 'Deleted.', 'success'); loadCampaigns(); }).catch(function () {});
            });
        });
        HOD.actions.on('reach.share', shareCampaign);
        HOD.actions.on('reach.copy', function () {
            var el = $('#reach-share-link');
            el.select();
            (navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.reject()).then(function () {
                HOD.toast('Link copied.', 'success');
            }, function () { document.execCommand('copy'); HOD.toast('Link copied.', 'success'); });
        });
        HOD.actions.on('reach.save-campaign', function (f) {
            var file = f.flyer.files[0];
            if (file && file.size > 5 * 1024 * 1024) { HOD.form.setErrors(f, { flyer: 'That image is over 5 MB.' }); return; }
            var button = document.querySelector('button[form="reach-campaign-form"]');
            HOD.busy(button, true);
            HOD.api(API, { data: new FormData(f) }).then(function (res) {
                HOD.toast(res.message || 'Saved.', 'success');
                HOD.dialog.close('reach-campaign');
                loadCampaigns();
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
        HOD.actions.on('reach.duration', function (btn) { setDuration(Number(btn.getAttribute('data-min'))); });
        HOD.actions.on('reach.start-change', function () {
            var active = document.querySelector('[data-action="reach.duration"][aria-pressed="true"]');
            if (active) setDuration(Number(active.getAttribute('data-min')));
        });
        HOD.actions.on('reach.end-change', function () { markDuration(currentDuration()); });
        HOD.actions.on('reach.flyer', function (input) {
            var file = input.files[0];
            var box = form().querySelector('[name="remove_flyer"]');
            if (file) { $('[data-reach-flyer-preview]').src = URL.createObjectURL(file); box.checked = false; }
            else $('[data-reach-flyer-preview]').src = safeUrl(editingFlyer) || DEFAULT_IMAGE;
        });
        HOD.actions.on('reach.remove-flyer', function (box) {
            if (box.checked) { form().flyer.value = ''; $('[data-reach-flyer-preview]').src = DEFAULT_IMAGE; }
            else $('[data-reach-flyer-preview]').src = safeUrl(editingFlyer) || DEFAULT_IMAGE;
        });
        HOD.actions.on('reach.meta-count', metaCount);
        HOD.actions.on('reach.scripture', function (sel) {
            var t = form().share_scripture;
            if (sel.value === '__custom__') { t.value = ''; t.focus(); } else if (sel.value) t.value = sel.value;
        });
        HOD.actions.on('reach.ai', function (btn) {
            var f = form();
            var title = f.title.value.trim();
            if (!title) { HOD.form.setErrors(f, { title: 'Enter the campaign title first.' }); f.title.focus(); return; }
            HOD.busy(btn, true);
            HOD.api(API, { action: 'suggest_share_copy', data: { title: title, location: f.location.value, campaign_type: f.campaign_type.value, campaign_date: f.campaign_date.value } }).then(function (res) {
                ai.options = res.data || [];
                ai.i = 0;
                if (ai.options.length) aiApply();
                btn.querySelector('span').textContent = 'Write new options';
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
        HOD.actions.on('reach.ai-next', function () {
            if (!ai.options.length) return;
            ai.i = (ai.i + 1) % ai.options.length;
            aiApply();
        });

        // Follow-up
        var t = null;
        HOD.actions.on('reach.fu-filter', function () { clearTimeout(t); t = setTimeout(function () { loadLeads(true); }, 300); });
        HOD.actions.on('reach.category', function (btn) { pickCategory(btn.getAttribute('data-cat')); loadLeads(true); });
        HOD.actions.on('reach.sub', function (btn) { fu.sub = btn.getAttribute('data-sub'); loadLeads(true); });
        HOD.actions.on('reach.overdue', function () { fu.overdue = !fu.overdue; loadLeads(true); });
        HOD.actions.on('reach.more', function () { loadLeads(false); });
        HOD.actions.on('reach.lead', function (btn) { openLead(Number(btn.getAttribute('data-id')), btn); });
        HOD.actions.on('reach.claim', function (btn) { leadAction(btn, 'self_claim', { lead_id: btn.getAttribute('data-id') }); });
        HOD.actions.on('reach.assign', function (btn) {
            var to = $('#reach-assign').value;
            if (!to) { HOD.toast('Choose a member first.', 'warning'); return; }
            leadAction(btn, btn.getAttribute('data-re') === '1' ? 'reassign' : 'assign_lead', { lead_id: btn.getAttribute('data-id'), to_user_id: to });
        });
        HOD.actions.on('reach.embrace', function (btn) {
            HOD.confirm({ title: 'Did they visit church?', message: 'This creates their 1st timer profile and hands them to Embrace.', confirmLabel: 'Send to Embrace' }).then(function (ok) {
                if (ok) leadAction(btn, 'push_to_embrace', { lead_id: btn.getAttribute('data-id') });
            });
        });
        HOD.actions.on('reach.testimony', function (box) {
            HOD.api(API, { action: 'set_testimony_flag', data: { lead_id: box.getAttribute('data-id'), share: box.checked ? 1 : 0 } })
                .then(function (res) { HOD.toast(res.message || 'Saved.', 'success'); })
                .catch(function () { box.checked = !box.checked; });
        });
        HOD.actions.on('reach.log', function (f) {
            var btn = f.querySelector('[type="submit"]');
            HOD.busy(btn, true);
            HOD.api(API, { data: new FormData(f) }).then(function (res) {
                HOD.toast('Logged. The lead is now ' + label((res.data || {}).lead_status) + '.', 'success');
                loadLeads(true);
                openLead(fu.leadId);
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
        $('#reach-lead').addEventListener('ui:dialog-close', function () { fu.leadId = null; });

        // Settings (managers)
        if (cfg.canAssign) {
            HOD.actions.on('reach.settings', openSettings);
            HOD.actions.on('reach.add-type', function (f) {
                var name = f.label.value.trim();
                if (!name) return;
                HOD.api(API, { action: 'add_campaign_type', data: { label: name } }).then(function (res) {
                    HOD.toast(res.message || 'Added.', 'success');
                    TYPES = res.data || TYPES;
                    renderTypeChips();
                    f.label.value = '';
                }).catch(function () {});
            });
            HOD.actions.on('reach.remove-type', function (btn) {
                HOD.confirm({ title: 'Remove this event type?', message: 'Campaigns that already use it keep it.', confirmLabel: 'Remove type' }).then(function (ok) {
                    if (!ok) return;
                    HOD.api(API, { action: 'remove_campaign_type', data: { code: btn.getAttribute('data-code') } }).then(function (res) {
                        HOD.toast(res.message || 'Removed.', 'success');
                        TYPES = res.data || TYPES;
                        renderTypeChips();
                    }).catch(function () {});
                });
            });
            HOD.actions.on('reach.save-settings', function (f) {
                HOD.api(API, { data: new FormData(f) }).then(function (res) { HOD.toast(res.message || 'Saved.', 'success'); }).catch(function () {});
            });
            HOD.actions.on('reach.restore-guide', function () {
                HOD.confirm({ title: 'Restore the default guide?', message: 'Your edits to the guide are replaced with the built-in version.', confirmLabel: 'Restore default' }).then(function (ok) {
                    if (!ok) return;
                    var f = $('#reach-settings-form');
                    f.guide.value = '';
                    HOD.api(API, { data: new FormData(f) }).then(function () {
                        HOD.toast('Default guide restored.', 'success');
                        openSettings();
                    }).catch(function () {});
                });
            });
            HOD.actions.on('reach.default-upload', function (input) {
                var file = input.files[0];
                if (!file) return;
                var data = new FormData();
                data.append('action', 'upload_default_image');
                data.append('image', file);
                HOD.api(API, { data: data }).then(applyDefaultImage).catch(function () {}).finally(function () { input.value = ''; });
            });
            HOD.actions.on('reach.default-reset', function () {
                HOD.confirm({ title: 'Use the church photo?', message: 'Campaigns without a flyer go back to the church photo.', confirmLabel: 'Use church photo' }).then(function (ok) {
                    if (ok) HOD.api(API, { action: 'reset_default_image' }).then(applyDefaultImage).catch(function () {});
                });
            });
        }

        // Analytics
        HOD.actions.on('reach.preset', function (btn) {
            an.preset = btn.getAttribute('data-p');
            document.querySelectorAll('[data-action="reach.preset"]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
            $('[data-reach-custom]').hidden = an.preset !== 'custom';
            loadAnalytics();
        });
        HOD.actions.on('reach.an-load', loadAnalytics);
        HOD.actions.on('reach.csv', function () {
            var r = presetRange(an.preset);
            window.location.href = API + '?action=export_csv&from_date=' + encodeURIComponent(r[0]) + '&to_date=' + encodeURIComponent(r[1]);
        });
        HOD.actions.on('reach.pdf', function (btn) {
            var r = presetRange(an.preset);
            HOD.busy(btn, true);
            HOD.api(API, { action: 'generate_pdf', data: { from_date: r[0], to_date: r[1] } }).then(function (res) {
                var url = safeUrl((res.data || {}).url);
                HOD.toast('Your report is ready.', 'success');
                $('[data-reach-pdf]').innerHTML = url ? '<div class="ui-banner ui-banner--success mb-4">' + HOD.icon('circle-check') + '<div class="ui-banner__body"><p class="ui-banner__text">Your Reach report is ready.</p></div>'
                    + '<div class="ui-banner__actions"><a class="ui-btn ui-btn--secondary ui-btn--sm" href="' + esc(url) + '" target="_blank" rel="noopener">' + HOD.icon('file-down') + '<span>Open the PDF</span></a></div></div>' : '';
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
        HOD.actions.on('reach.goto-overdue', function () {
            fu.overdue = true;
            HOD.tabs.select(document.querySelector('[data-ui-tabs]'), 'followup');
        });

        document.addEventListener('ui:tab', function (e) {
            var tab = e.detail && e.detail.tab;
            if (tab === 'followup') { if (!fu.ready) initFollowUp(); else loadLeads(true); }
            if (tab === 'analytics') initAnalytics();
        });

        loadCampaigns();
        if (!$('#panel-followup').hidden) initFollowUp();
        if (!$('#panel-analytics').hidden) initAnalytics();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
