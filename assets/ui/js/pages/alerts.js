// /assets/ui/js/pages/alerts.js
// Alerts Centre (modules/alerts/index.php): delivery health, the site-wide
// switches and the two-tap broadcast composer. Data from
// /api/push_admin_api.php through HOD.api. Error texts and audience names come
// from the server and people, so they go into the page as TEXT (DOM nodes).
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('alerts-boot');
    if (!boot) return;
    var API = boot.api;

    var lookups = null;
    var checked = null;   // the payload the Send button has been confirmed for

    /* ------------------------------------------------------------- helpers */

    function q(sel, root) { return (root || document).querySelector(sel); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function num(v) { return HOD.format.number(v || 0); }

    function words(v) { return String(v == null ? '' : v).replace(/_/g, ' '); }

    /* -------------------------------------------------------------- health */

    function showBanner(key) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-alerts-banner]'), function (b) {
            b.hidden = b.getAttribute('data-alerts-banner') !== key;
        });
    }

    function row(dl, label, value) {
        dl.appendChild(el('dt', '', label));
        dl.appendChild(el('dd', '', value));
    }

    function paint(d) {
        ['devices', 'members', 'sent_today', 'pending'].forEach(function (key) {
            var v = q('[data-stat="' + key + '"] .ui-stat__value');
            if (v) v.textContent = num(d[key]);
        });

        if (d.ready && d.key_mismatch) showBanner('key_mismatch');
        else if (d.ready) showBanner(null);
        else showBanner(q('[data-alerts-banner="' + d.reason + '"]') ? d.reason : 'other');

        var box = q('[data-alerts-health]');
        box.textContent = '';
        var dl = el('dl', 'ui-dl ui-dl--stacked');
        row(dl, 'Push notifications', d.enabled ? 'On' : 'Off');
        row(dl, 'Signing keys', d.key_source === 'env' ? 'From .env' : 'Generated, kept in the database');
        row(dl, 'Instant delivery', d.inline ? 'On: sent as soon as written' : 'Off: the cron job sends them');
        row(dl, 'In flight', num(d.in_flight));
        row(dl, 'Failed today', num(d.failed_today));
        row(dl, 'Retired devices', num(d.retired));
        if (d.cursor !== null && d.cursor !== undefined) {
            var behind = Number(d.latest || 0) - Number(d.cursor || 0);
            row(dl, 'Fan-out', behind <= 0 ? 'Up to date' : num(behind) + ' behind');
        }
        (d.platforms || []).forEach(function (p) {
            row(dl, 'Devices on ' + words(p.platform || 'other'), num(p.devices));
        });
        box.appendChild(dl);

        if ((d.failures || []).length) {
            box.appendChild(el('h3', 'ui-label mt-4', 'Failures, last 7 days'));
            var list = el('div', 'ui-list');
            d.failures.forEach(function (f) {
                var item = el('div', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                main.appendChild(el('span', 'ui-list__title', num(f.hits) + '×'));
                var err = el('span', 'ui-meta break-words', f.last_error);
                main.appendChild(err);
                item.appendChild(main);
                list.appendChild(item);
            });
            box.appendChild(list);
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-action-change="alerts.switch"]'), function (sw) {
            var key = sw.getAttribute('data-key');
            sw.checked = key === 'enabled' ? !!d.enabled : !!d.inline;
            sw.disabled = false;
        });
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'overview', quiet: true }).then(function (res) {
            q('[data-alerts-error]').hidden = true;
            paint(res.data || {});
        }).catch(function () {
            q('[data-alerts-error]').hidden = false;
        });
    }

    HOD.actions.on('alerts.refresh', function (btn) {
        HOD.busy(btn, true);
        load().then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('alerts.switch', function (sw) {
        var on = sw.checked;
        sw.disabled = true;
        HOD.api(API, { action: 'set_switch', data: { key: sw.getAttribute('data-key'), value: on ? '1' : '0' } }).then(function () {
            HOD.toast(on ? 'Switched on.' : 'Switched off.', { tone: 'success' });
            return load();
        }).catch(function () {
            sw.checked = !on;
        }).then(function () { sw.disabled = false; });
    });

    /* ------------------------------------------------------------ composer */

    var form = q('#alerts-form');

    function submitButton() { return q('[data-alerts-submit]', form); }

    function setSubmit(label, iconName, variant) {
        var b = submitButton();
        b.className = b.className.replace(/ui-btn--(primary|danger)/, 'ui-btn--' + variant);
        b.textContent = '';
        var w = document.createElement('span');
        w.innerHTML = HOD.icon(iconName);   // our own sprite name
        b.appendChild(w.firstChild);
        b.appendChild(el('span', '', label));
    }

    // Any edit invalidates the "reaches N people" check, so the button goes
    // back to asking: nobody can change the audience after seeing a number and
    // then send to a different one.
    function resetCheck() {
        checked = null;
        setSubmit('Check reach', 'users', 'primary');
        q('[data-alerts-preview]', form).hidden = true;
    }

    function counters() {
        Array.prototype.forEach.call(form.querySelectorAll('[data-count]'), function (input) {
            var hint = document.getElementById(input.id + '-hint');
            if (hint) hint.textContent = input.value.length + '/' + input.getAttribute('data-count');
        });
    }

    function payload() {
        var v = HOD.form.values(form);
        return {
            title: (v.title || '').trim(),
            message: (v.message || '').trim(),
            link: (v.link || '').trim() || '/',
            audience: v.audience || '',
            audience_id: q('[data-alerts-target]', form).hidden ? 0 : (v.audience_id || 0)
        };
    }

    function needsTarget(kind) { return ['department', 'role', 'region'].indexOf(kind) !== -1; }

    function fillTargets(kind) {
        var select = form.elements.audience_id;
        select.textContent = '';
        select.appendChild(new Option('Choose…', ''));
        var list = (lookups || {})[kind === 'department' ? 'departments' : kind === 'role' ? 'roles' : 'regions'] || [];
        list.forEach(function (r) { select.appendChild(new Option(words(r.name), r.id)); });
    }

    if (form) {
        HOD.api(API, { method: 'GET', action: 'audiences', quiet: true }).then(function (res) {
            lookups = res.data || {};
            var kind = form.elements.audience.value;
            if (needsTarget(kind)) fillTargets(kind);
        }).catch(function () {});

        HOD.actions.on('alerts.edit', function () { counters(); resetCheck(); });

        HOD.actions.on('alerts.audience', function (select) {
            var kind = select.value;
            q('[data-alerts-target]', form).hidden = !needsTarget(kind);
            if (needsTarget(kind)) fillTargets(kind);
            resetCheck();
        });

        HOD.actions.on('alerts.send', function (f, event) {
            var data = payload();
            var errors = {};
            if (!data.title) errors.title = 'Give it a title.';
            if (!data.message) errors.message = 'Write the message.';
            if (!data.audience) errors.audience = 'Choose who gets it.';
            else if (needsTarget(data.audience) && !Number(data.audience_id)) errors.audience_id = 'Choose which one.';
            HOD.form.clearErrors(form);
            if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }

            var btn = (event && event.submitter) || submitButton();
            var fingerprint = JSON.stringify(data);

            // Two taps, always: the first says how many people this reaches,
            // the second sends it. A broadcast cannot be unsent.
            if (checked !== fingerprint) {
                HOD.busy(btn, true);
                HOD.api(API, { action: 'preview', data: data }).then(function (res) {
                    var r = res.data || {};
                    checked = fingerprint;
                    var box = q('[data-alerts-preview]', form);
                    box.textContent = '';
                    var banner = el('div', 'ui-banner ui-banner--info');
                    var body = el('div', 'ui-banner__body');
                    var p = el('p', 'ui-banner__text');
                    p.appendChild(el('strong', '', num(r.people)));
                    p.appendChild(document.createTextNode(' ' + (Number(r.people) === 1 ? 'person' : 'people') + ' in '));
                    p.appendChild(el('strong', '', r.label));
                    p.appendChild(document.createTextNode('; '));
                    p.appendChild(el('strong', '', num(r.with_devices)));
                    p.appendChild(document.createTextNode(' get it on a phone, the rest in their bell.'));
                    body.appendChild(p);
                    banner.appendChild(body);
                    box.appendChild(banner);
                    box.hidden = false;
                    HOD.busy(btn, false);
                    setSubmit('Send now', 'send', 'danger');
                }).catch(function () { HOD.busy(btn, false); });
                return;
            }

            HOD.busy(btn, true);
            HOD.api(API, { action: 'broadcast', data: data }).then(function (res) {
                HOD.busy(btn, false);
                HOD.toast(res.message || 'Sent.', { tone: 'success' });
                form.elements.title.value = '';
                form.elements.message.value = '';
                counters();
                resetCheck();
                setTimeout(load, 1500);
            }).catch(function () { HOD.busy(btn, false); });
        });
    }

    load();
})();
