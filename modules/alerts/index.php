<?php
// /modules/alerts/index.php
require_once '../../includes/header.php';
require_once '../../includes/push_helpers.php';

// Same gate as the Security Centre, and for the same reason: the composer on
// this page can reach every member at once. Checked against the live roles
// table, never $_SESSION['active_role'] — the member can switch that
// themselves from their profile.
if (!isset($_SESSION['user_id']) || !security_is_admin($pdo)) {
    echo "<div class='min-h-screen flex items-center justify-center bg-gray-50'><div class='bg-white p-8 rounded-3xl shadow-xl text-center max-w-md border border-red-100'><svg class='w-16 h-16 text-red-500 mx-auto mb-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'></path></svg><h2 class='text-2xl font-black text-gray-900 mb-2'>CLEARANCE REQUIRED</h2><p class='text-gray-500 mb-6'>The Alerts Centre is restricted to Super Admins and the Resident Pastor.</p><a href='/index.php' class='bg-gray-900 text-white px-6 py-3 rounded-xl font-bold hover:bg-black transition-all inline-block'>Return to Dashboard</a></div></div>";
    require_once '../../includes/footer.php';
    exit;
}
?>

<div class="max-w-7xl mx-auto space-y-6 pb-10">

    <!-- ============================ HERO ============================ -->
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 bg-gradient-to-br from-hodBlue via-[#152750] to-[#0D1830] p-6 md:p-8 rounded-3xl shadow-2xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 rounded-full pointer-events-none z-0" style="background: radial-gradient(circle, rgba(209,25,32,0.22) 0%, transparent 65%); transform: translate(25%, -25%);"></div>

        <div class="relative z-10 flex items-center gap-4">
            <div class="w-14 h-14 bg-white/10 border border-white/20 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0 backdrop-blur-md">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            </div>
            <div>
                <h1 class="text-2xl md:text-3xl font-display font-bold text-white tracking-tight">Alerts Centre</h1>
                <p class="text-blue-100/80 text-sm mt-1">Push notification health, and one place to reach the congregation.</p>
            </div>
        </div>

        <div class="relative z-10 flex items-center gap-3 w-full lg:w-auto">
            <button type="button" data-hod-alerts class="flex-1 lg:flex-none bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl text-sm font-bold transition-all backdrop-blur-md">
                My alert settings
            </button>
            <button type="button" id="refreshHealth" class="flex-1 lg:flex-none bg-white hover:bg-gray-100 text-hodBlue px-5 py-3 rounded-xl text-sm font-black transition-all shadow-lg">
                Refresh
            </button>
        </div>
    </div>

    <!-- ========================== READINESS ========================== -->
    <div id="readyBanner" class="hidden rounded-3xl p-5 border"></div>

    <!-- =========================== NUMBERS =========================== -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4" id="statGrid">
        <?php
        $tiles = [
            ['devices',   'Devices',        'Phones and computers that will buzz'],
            ['members',   'Members reached','People with at least one device'],
            ['sent_today','Delivered today','Notifications that landed'],
            ['pending',   'Waiting',        'Queued, not yet sent'],
        ];
        foreach ($tiles as [$key, $label, $hint]): ?>
        <div class="bg-white rounded-3xl p-5 border border-gray-100 shadow-sm">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?= htmlspecialchars($label) ?></p>
            <p class="text-3xl font-display font-bold text-gray-900 mt-2" data-stat="<?= htmlspecialchars($key) ?>">—</p>
            <p class="text-[11px] text-gray-400 mt-1 leading-snug"><?= htmlspecialchars($hint) ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        <!-- ======================= BROADCAST ======================= -->
        <div class="lg:col-span-3 bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-5 sm:p-6 border-b border-gray-100">
                <h2 class="font-display font-bold text-lg text-gray-900 tracking-tight">Send a broadcast</h2>
                <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                    This writes a normal notification, so it reaches everyone's bell whether or not
                    they have a phone registered &mdash; and buzzes the phones of those who do.
                    Quiet hours and anyone who has opted out are respected automatically.
                </p>
            </div>

            <div class="p-5 sm:p-6 space-y-4">
                <div>
                    <label for="bTitle" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Title</label>
                    <input id="bTitle" type="text" maxlength="120" placeholder="Sunday service moves to 8am"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-300">
                    <p class="text-[10px] text-gray-400 mt-1">This is the bold line on the lock screen. <span data-count="bTitle">0</span>/120</p>
                </div>

                <div>
                    <label for="bMessage" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Message</label>
                    <textarea id="bMessage" rows="3" maxlength="400" placeholder="Please arrive by 7:45am. Children's church runs as normal."
                              class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-300 resize-none"></textarea>
                    <p class="text-[10px] text-gray-400 mt-1">Phones show about two lines. <span data-count="bMessage">0</span>/400</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="bAudience" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Who</label>
                        <select id="bAudience" class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-100">
                            <option value="">Choose&hellip;</option>
                            <option value="everyone">Everyone</option>
                            <option value="with_devices">Everyone with alerts on</option>
                            <option value="department">A department</option>
                            <option value="role">A role</option>
                            <option value="region">A region</option>
                        </select>
                    </div>
                    <div id="bTargetWrap" class="hidden">
                        <label for="bTarget" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Which one</label>
                        <select id="bTarget" class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"></select>
                    </div>
                </div>

                <div>
                    <label for="bLink" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Opens when tapped</label>
                    <input id="bLink" type="text" maxlength="255" value="/index.php"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-300">
                    <p class="text-[10px] text-gray-400 mt-1">A path on this site, such as <code>/modules/events/index.php</code>. Anything else becomes the dashboard.</p>
                </div>

                <div id="bPreview" class="hidden rounded-2xl bg-blue-50/70 border border-blue-100 p-4 text-sm text-hodBlue"></div>

                <button type="button" id="bSend" disabled
                        class="w-full bg-hodRed hover:bg-red-700 disabled:bg-gray-200 disabled:text-gray-400 text-white px-6 py-4 rounded-xl font-black text-sm uppercase tracking-widest transition-all shadow-lg shadow-red-500/20 disabled:shadow-none">
                    Check who this reaches
                </button>
            </div>
        </div>

        <!-- ========================= HEALTH ========================= -->
        <div class="lg:col-span-2 space-y-6">

            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100">
                    <h2 class="font-display font-bold text-lg text-gray-900 tracking-tight">Delivery</h2>
                </div>
                <div class="p-5 space-y-3 text-sm" id="healthBody">
                    <p class="text-gray-400 text-xs">Loading&hellip;</p>
                </div>
            </div>

            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-gray-100">
                    <h2 class="font-display font-bold text-lg text-gray-900 tracking-tight">Switches</h2>
                    <p class="text-[11px] text-gray-500 mt-1 leading-relaxed">Every change here is written to the security audit log.</p>
                </div>
                <div class="p-5 space-y-4" id="switchBody">
                    <p class="text-gray-400 text-xs">Loading&hellip;</p>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var API = '/api/push_admin_api.php';
    var lookups = null;
    var checked = null;   // the audience the Send button has been confirmed for

    function post(action, data) {
        var body = new URLSearchParams();
        body.set('action', action);
        Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function notify(message, good) {
        if (window.hodApp) { window.hodApp.toast(message, good ? 'ok' : 'bad'); return; }
        if (window.Toastify) {
            Toastify({ text: message, duration: 4000, gravity: 'top', position: 'center',
                style: { background: good ? '#10B981' : '#DC2626', borderRadius: '10px', fontWeight: 'bold' } }).showToast();
            return;
        }
        alert(message);
    }

    function esc(value) {
        var d = document.createElement('div');
        d.textContent = String(value == null ? '' : value);
        return d.innerHTML;
    }

    /* ---------------------------------------------------------------- *
     * Health
     * ---------------------------------------------------------------- */

    function paintHealth(d) {
        ['devices', 'members', 'sent_today', 'pending'].forEach(function (key) {
            var node = document.querySelector('[data-stat="' + key + '"]');
            if (node) node.textContent = Number(d[key] || 0).toLocaleString();
        });

        var banner = document.getElementById('readyBanner');
        if (d.ready && d.key_mismatch) {
            // Push "works", but every already-registered device is now signed
            // with a key its push service will reject. Worth shouting about:
            // nothing else on this page would look wrong.
            banner.className = 'rounded-3xl p-5 border bg-red-50 border-red-200 text-red-900';
            banner.innerHTML = '<p class="font-bold text-sm">The signing keys changed after people subscribed</p>' +
                '<p class="text-xs mt-1.5 leading-relaxed">VAPID keys in <code>.env</code> do not match the pair ' +
                'that was generated before them, and ' + Number(d.devices).toLocaleString() + ' device(s) are still ' +
                'registered against the old one. Those devices will silently receive nothing. Either remove the ' +
                '<code>.env</code> keys to go back to the generated pair, or clear the old one ' +
                '(<code>DELETE FROM push_config WHERE config_key IN (\'vapid_public\',\'vapid_private\')</code>) ' +
                'and ask everyone to turn alerts on again. See docs/pwa_push.md.</p>';
        } else if (d.ready) {
            banner.className = 'hidden';
        } else {
            var reasons = {
                FEATURE_NOT_READY: 'The database tables are not there yet. They are created by the next deploy — nothing to do.',
                NO_VAPID_KEYS: 'No usable VAPID keys. Run <code>php bin/generate_vapid_keys.php</code> and put the result in .env.',
                NO_OPENSSL_ECDH: "This server's PHP cannot encrypt push messages (ext-openssl has no elliptic-curve support).",
                DISABLED: 'Push notifications are switched off site-wide, below. The bell still works.'
            };
            banner.className = 'rounded-3xl p-5 border bg-amber-50 border-amber-200 text-amber-900';
            banner.innerHTML = '<p class="font-bold text-sm">Push is not delivering right now</p>' +
                '<p class="text-xs mt-1.5 leading-relaxed">' + (reasons[d.reason] || esc(d.reason)) + '</p>';
        }

        var rows = [];
        rows.push(['Signing keys', d.key_source === 'env' ? 'From .env' : 'Generated, stored in the database']);
        rows.push(['Instant delivery', d.inline ? 'On — sent as soon as they are written' : 'Off — the cron job sends them']);
        rows.push(['In flight', Number(d.in_flight || 0).toLocaleString()]);
        rows.push(['Failed today', Number(d.failed_today || 0).toLocaleString()]);
        rows.push(['Retired devices', Number(d.retired || 0).toLocaleString()]);

        if (d.cursor !== null && d.cursor !== undefined) {
            var behind = Number(d.latest || 0) - Number(d.cursor || 0);
            rows.push(['Fan-out', behind <= 0 ? 'Up to date' : behind.toLocaleString() + ' notification(s) behind']);
        }

        var html = rows.map(function (r) {
            return '<div class="flex items-start justify-between gap-4 py-2 border-t border-gray-50 first:border-0">' +
                '<span class="text-gray-500 text-xs">' + esc(r[0]) + '</span>' +
                '<span class="font-bold text-gray-900 text-xs text-right">' + esc(r[1]) + '</span></div>';
        }).join('');

        if ((d.platforms || []).length) {
            html += '<div class="pt-3 mt-1 border-t border-gray-100"><p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">By platform</p>' +
                d.platforms.map(function (p) {
                    return '<div class="flex justify-between py-1"><span class="text-xs text-gray-500 capitalize">' +
                        esc(p.platform || 'other') + '</span><span class="text-xs font-bold text-gray-900">' +
                        Number(p.devices).toLocaleString() + '</span></div>';
                }).join('') + '</div>';
        }

        if ((d.failures || []).length) {
            html += '<div class="pt-3 mt-1 border-t border-gray-100"><p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Recent failures (7 days)</p>' +
                d.failures.map(function (f) {
                    return '<div class="py-1.5"><p class="text-xs font-bold text-red-700">' + Number(f.hits).toLocaleString() + '&times;</p>' +
                        '<p class="text-[11px] text-gray-500 leading-snug break-words">' + esc(f.last_error) + '</p></div>';
                }).join('') + '</div>';
        }

        document.getElementById('healthBody').innerHTML = html;

        document.getElementById('switchBody').innerHTML =
            switchRow('enabled', 'Push notifications', 'Off means nobody is sent anything. The bell keeps working.', d.enabled) +
            switchRow('inline_dispatch', 'Instant delivery', 'Off means the once-a-minute cron job sends them instead.', d.inline);

        document.querySelectorAll('[data-switch]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var on = btn.getAttribute('aria-checked') !== 'true';
                btn.disabled = true;
                post('set_switch', { key: btn.getAttribute('data-switch'), value: on ? '1' : '0' })
                    .then(function (res) {
                        btn.disabled = false;
                        notify(res.message, res.status === 'success');
                        if (res.status === 'success') btn.setAttribute('aria-checked', on ? 'true' : 'false');
                    })
                    .catch(function () { btn.disabled = false; notify('Could not save that.', false); });
            });
        });
    }

    function switchRow(key, label, hint, on) {
        return '<div class="flex items-start gap-3">' +
            '<div class="flex-1"><p class="text-sm font-bold text-gray-900">' + esc(label) + '</p>' +
            '<p class="text-[11px] text-gray-500 mt-0.5 leading-snug">' + esc(hint) + '</p></div>' +
            '<button type="button" class="hod-switch shrink-0" role="switch" data-switch="' + esc(key) + '" aria-checked="' + (on ? 'true' : 'false') + '" aria-label="' + esc(label) + '"></button>' +
            '</div>';
    }

    function loadHealth() {
        fetch(API + '?action=overview', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.status === 'success') paintHealth(res.data);
                else notify(res.message || 'Could not load delivery health.', false);
            })
            .catch(function () { notify('Could not load delivery health.', false); });
    }

    /* ---------------------------------------------------------------- *
     * Composer
     * ---------------------------------------------------------------- */

    var audience = document.getElementById('bAudience');
    var targetWrap = document.getElementById('bTargetWrap');
    var target = document.getElementById('bTarget');
    var sendBtn = document.getElementById('bSend');
    var preview = document.getElementById('bPreview');

    ['bTitle', 'bMessage'].forEach(function (id) {
        var input = document.getElementById(id);
        var counter = document.querySelector('[data-count="' + id + '"]');
        input.addEventListener('input', function () {
            counter.textContent = input.value.length;
            resetConfirmation();
        });
    });

    // Any edit invalidates the "reaches N people" check, so the Send button
    // goes back to asking. Nobody should be able to change the audience after
    // seeing a number and then send to a different one.
    function resetConfirmation() {
        checked = null;
        sendBtn.textContent = 'Check who this reaches';
        sendBtn.disabled = !(document.getElementById('bTitle').value.trim() && audience.value);
        preview.className = 'hidden';
    }

    audience.addEventListener('change', function () {
        var kind = audience.value;
        var needsTarget = ['department', 'role', 'region'].indexOf(kind) !== -1;

        targetWrap.className = needsTarget ? '' : 'hidden';
        if (needsTarget && lookups) {
            var list = lookups[kind === 'department' ? 'departments' : kind === 'role' ? 'roles' : 'regions'] || [];
            target.innerHTML = '<option value="">Choose&hellip;</option>' + list.map(function (row) {
                return '<option value="' + esc(row.id) + '">' + esc(String(row.name).replace(/_/g, ' ')) + '</option>';
            }).join('');
        }
        resetConfirmation();
    });

    target.addEventListener('change', resetConfirmation);
    document.getElementById('bLink').addEventListener('input', resetConfirmation);

    function payload() {
        return {
            title: document.getElementById('bTitle').value.trim(),
            message: document.getElementById('bMessage').value.trim(),
            link: document.getElementById('bLink').value.trim() || '/index.php',
            audience: audience.value,
            audience_id: target.value || 0
        };
    }

    sendBtn.addEventListener('click', function () {
        var data = payload();
        var fingerprint = JSON.stringify(data);

        // Two taps, always: the first says how many people this reaches, the
        // second sends it. A broadcast cannot be unsent.
        if (checked !== fingerprint) {
            sendBtn.disabled = true;
            post('preview', data).then(function (res) {
                sendBtn.disabled = false;
                if (res.status !== 'success') { notify(res.message, false); return; }

                checked = fingerprint;
                preview.className = 'rounded-2xl bg-blue-50/70 border border-blue-100 p-4 text-sm text-hodBlue';
                preview.innerHTML = '<strong>' + Number(res.data.people).toLocaleString() + '</strong> ' +
                    (res.data.people === 1 ? 'person' : 'people') + ' in <strong>' + esc(res.data.label) + '</strong>. ' +
                    '<strong>' + Number(res.data.with_devices).toLocaleString() + '</strong> will get it on a phone; ' +
                    'the rest see it in their bell.';
                sendBtn.textContent = 'Send it';
            }).catch(function () { sendBtn.disabled = false; notify('Could not check that audience.', false); });
            return;
        }

        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending…';
        post('broadcast', data).then(function (res) {
            sendBtn.disabled = false;
            notify(res.message, res.status === 'success');
            if (res.status === 'success') {
                document.getElementById('bTitle').value = '';
                document.getElementById('bMessage').value = '';
                document.querySelectorAll('[data-count]').forEach(function (n) { n.textContent = '0'; });
                resetConfirmation();
                setTimeout(loadHealth, 1500);
            } else {
                sendBtn.textContent = 'Send it';
            }
        }).catch(function () {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send it';
            notify('Could not send that broadcast.', false);
        });
    });

    document.getElementById('refreshHealth').addEventListener('click', loadHealth);

    fetch(API + '?action=audiences', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res.status === 'success') lookups = res.data; })
        .catch(function () {});

    loadHealth();
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
