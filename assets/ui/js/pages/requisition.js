// /assets/ui/js/pages/requisition.js
// Requisitions (modules/requisition/index.php): my requests and the Director
// queue, the detail drawer and every step of the workflow (stamp, approve with
// a signature, flag lines, revise, reject, cancel), Finance batches and the
// analytics. Data from /api/requisition_api.php through HOD.api (the API
// re-checks permissions and the state machine). Everything typed by people —
// descriptions, notes, names, file names — goes in as TEXT (DOM nodes).
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('requisition-boot');
    if (!boot) return;
    var API = boot.api;

    var STATUS = {
        Draft: ['Draft', ''], Pending_Director: ['With Director', 'warning'], Pending_Pastor: ['With Pastor', 'info'],
        Revision_Required: ['Needs revision', 'warning'], Approved: ['Approved', 'success'], Batched: ['Batched', 'primary'],
        Disbursed: ['Paid', 'success'], Rejected: ['Rejected', 'danger'], Cancelled: ['Cancelled', '']
    };
    var STATUS_COLOURS = {
        Approved: '#157A4A', Disbursed: '#0F5C37', Pending_Pastor: '#4A6BB0', Pending_Director: '#D98A00',
        Revision_Required: '#E2777B', Rejected: '#D11920', Cancelled: '#9AA2B2', Batched: '#1D356A', Draft: '#C9CED8'
    };

    var state = {
        flags: {}, depts: [], categories: [], windowWarning: null, hasVault: false,
        filter: 'all', page: 1, approvalFilter: 'all', period: 'quarter', draftBatch: null,
        signTarget: 'approve', approveSig: '', vaultSig: '', loaded: { requests: true }
    };

    /* ------------------------------------------------------------- helpers */

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function icon(name) {
        var w = document.createElement('span');
        w.innerHTML = HOD.icon(name);   // our own sprite name
        return w.firstChild;
    }

    function badge(label, tone) { return el('span', 'ui-badge' + (tone ? ' ui-badge--' + tone : ''), label); }

    function statusBadge(s) {
        var c = STATUS[s] || [String(s || '').replace(/_/g, ' '), ''];
        return badge(c[0], c[1]);
    }

    function button(label, opts) {
        opts = opts || {};
        var b = el('button', 'ui-btn ' + (opts.variant ? 'ui-btn--' + opts.variant : 'ui-btn--secondary') + (opts.size === 'md' ? '' : ' ui-btn--sm'));
        b.type = 'button';
        if (opts.icon) b.appendChild(icon(opts.icon));
        b.appendChild(el('span', '', label));
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, data: data || {} }, opts || {}));
    }

    function naira(v) { return HOD.format.naira ? HOD.format.naira(Number(v || 0)) : '₦' + Number(v || 0).toLocaleString('en-NG', { minimumFractionDigits: 2 }); }
    function dateText(v) { return v ? HOD.format.date(v) : '—'; }

    function ago(v) {
        var d = v ? new Date(String(v).replace(' ', 'T')) : null;
        if (!d || isNaN(d.getTime())) return '';
        var s = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s / 60) + ' min ago';
        if (s < 86400) return Math.floor(s / 3600) + ' h ago';
        return Math.floor(s / 86400) + ' days ago';
    }

    function period(r) {
        if (r.type === 'Monthly' && r.period_year && r.period_month) {
            return new Date(r.period_year, r.period_month - 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
        }
        return r.event_label || '';
    }

    function emptyRow(box, text) {
        box.textContent = '';
        box.appendChild(el('p', 'ui-list__item ui-meta', text));
    }

    function loadingRow(box) {
        box.textContent = '';
        var w = el('div', 'ui-loading');
        w.appendChild(el('span', 'ui-spinner'));
        w.appendChild(el('span', '', 'Loading…'));
        box.appendChild(w);
    }

    function setPressed(group, value) {
        document.querySelectorAll('[data-action="' + group + '"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-value') === value ? 'true' : 'false');
        });
    }

    function setTabCount(tab, n) {
        var t = document.getElementById('tab-' + tab);
        if (!t) return;
        var c = t.querySelector('.ui-count');
        if (!c) { c = el('span', 'ui-count'); t.appendChild(c); }
        c.textContent = n;
        c.hidden = !n;
    }

    function pdfLink(param, id) {
        var a = el('a', 'ui-btn ui-btn--secondary ui-btn--sm');
        a.href = boot.pdf + '?' + param + '=' + encodeURIComponent(id) + '&csrf=' + encodeURIComponent(HOD.csrf());
        a.target = '_blank';
        a.rel = 'noopener';
        a.appendChild(icon('file-down'));
        a.appendChild(el('span', '', 'Export PDF'));
        return a;
    }

    /* ---------------------------------------------------------- first load */

    function loadFormData() {
        return call('fetch_form_data', {}, { quiet: true }).then(function (res) {
            state.flags = res.flags || {};
            state.depts = res.my_departments || [];
            state.categories = res.expense_categories || [];
            state.windowWarning = res.window_warning || null;
            state.hasVault = !!res.has_stored_signature;
            // Finance is for the Finance Director, pastors and Super Admins.
            var fin = document.getElementById('tab-finance');
            if (fin) fin.hidden = !(state.flags.is_finance_director || state.flags.is_super_admin || state.flags.is_resident_pastor);
            loadRequests();
            if (boot.isDirector) loadDirectorQueue();
            loadCounts();
        }).catch(function () { document.querySelector('[data-req-error]').hidden = false; });
    }

    function loadCounts() {
        if (boot.isPastor) {
            call('fetch_approval_queue', { filter: 'all' }, { quiet: true }).then(function (r) { setTabCount('approval', (r.queue || []).length); }).catch(function () {});
        }
        var fin = document.getElementById('tab-finance');
        if (fin && !fin.hidden) {
            call('fetch_finance_queue', {}, { quiet: true }).then(function (r) { setTabCount('finance', (r.available_requisitions || []).length); }).catch(function () {});
        }
    }

    /* ------------------------------------------------------------ requests */

    var reqList = document.querySelector('[data-req-list]');

    function requestRow(r, opts) {
        opts = opts || {};
        var row = el('div', 'ui-list__item');
        var main = el('div', 'ui-list__main');
        var t = el('p', 'ui-list__title');
        var open = el('button', 'ui-link', r.ref_number);
        open.type = 'button';
        open.setAttribute('data-action', 'req.open');
        open.setAttribute('data-id', r.id);
        t.appendChild(open);
        t.appendChild(document.createTextNode(' '));
        t.appendChild(statusBadge(r.status));
        main.appendChild(t);
        main.appendChild(el('p', 'ui-list__meta', [r.department_name, period(r)].filter(Boolean).join(' · ')));
        var meta = r.item_count === undefined || r.item_count === null ? [] : [r.item_count + (Number(r.item_count) === 1 ? ' line' : ' lines')];
        if (Number(r.rejected_item_count) > 0) meta.push(r.rejected_item_count + ' flagged');
        if (opts.by && r.submitted_by_name) meta.push('by ' + r.submitted_by_name);
        if (r.has_director_stamp) meta.push('stamped');
        if (opts.director) meta.push(r.director_auto ? 'auto-stamped (Director request)' : (r.director_name ? 'stamped by ' + r.director_name : ''));
        main.appendChild(el('p', 'ui-list__meta', meta.filter(Boolean).join(' · ')));
        if (opts.note && r.submission_note) main.appendChild(el('p', 'ui-list__meta', r.submission_note));
        row.appendChild(main);
        var end = el('div', 'ui-list__end ui-stack ui-stack--sm');
        end.appendChild(el('p', 'ui-h3 is-num', naira(r.total_amount)));
        end.appendChild(el('p', 'ui-meta', ago(opts.since ? (r[opts.since] || r.created_at) : r.updated_at)));
        (opts.actions || []).forEach(function (b) { end.appendChild(b); });
        row.appendChild(end);
        return row;
    }

    function loadRequests(append) {
        if (!append) { state.page = 1; loadingRow(reqList); }
        call('fetch_my_requisitions', { filter_status: state.filter, page: state.page, limit: 20 }).then(function (res) {
            var rows = res.requisitions || [];
            if (!append) reqList.textContent = '';
            if (!rows.length && !append) emptyRow(reqList, 'Nothing here. Change the filter, or start a new requisition.');
            rows.forEach(function (r) {
                var actions = [];
                if (r.status === 'Revision_Required' && Number(r.submitted_by) === boot.me) {
                    actions.push(button('Revise', { icon: 'pencil', data: { action: 'req.revise', id: r.id } }));
                }
                reqList.appendChild(requestRow(r, { actions: actions }));
            });
            document.querySelector('[data-req-more]').hidden = rows.length < 20;
        }).catch(function () { if (!append) emptyRow(reqList, 'Requests did not load. Reload the page to try again.'); });
    }

    HOD.actions.on('req.filter', function (btn) {
        state.filter = btn.getAttribute('data-value');
        setPressed('req.filter', state.filter);
        loadRequests();
    });
    HOD.actions.on('req.more', function () { state.page++; loadRequests(true); });

    function loadDirectorQueue() {
        var card = document.querySelector('[data-req-dirq-card]');
        if (!card) return;
        var box = document.querySelector('[data-req-dirq]');
        call('fetch_director_queue', {}, { quiet: true }).then(function (res) {
            var q = res.queue || [];
            card.hidden = !q.length;
            document.querySelector('[data-req-dirq-count]').textContent = q.length;
            setTabCount('requests', q.length);
            box.textContent = '';
            q.forEach(function (r) {
                box.appendChild(requestRow(r, { by: true, since: 'created_at', actions: [
                    button('Review', { variant: 'primary', data: { action: 'req.open', id: r.id } }),
                    button('Reject', { variant: 'danger-ghost', data: { action: 'req.reject.dir', id: r.id } })
                ] }));
            });
        }).catch(function () { card.hidden = true; });
    }

    /* -------------------------------------------------------- detail drawer */

    var detail = document.querySelector('[data-req-detail]');
    var detailActions = document.querySelector('[data-req-detail-actions]');

    function dl(pairs) {
        var d = el('dl', 'ui-dl');
        pairs.forEach(function (p) {
            if (p[1] === null || p[1] === undefined || p[1] === '') return;
            d.appendChild(el('dt', '', p[0]));
            d.appendChild(el('dd', '', p[1]));
        });
        return d;
    }

    function card(title, body) {
        var c = el('section', 'ui-card');
        var h = el('div', 'ui-card__header ui-card__header--divided');
        if (title instanceof Node) h.appendChild(title); else h.appendChild(el('h3', 'ui-h3', title));
        c.appendChild(h);
        var b = el('div', 'ui-card__body');
        b.appendChild(body);
        c.appendChild(b);
        return c;
    }

    function stampCard(s, isAuto) {
        var d = dl([
            ['Stamped by', s.name], ['Role', s.role], ['Action', s.action], ['When', s.date_time],
            ['IP address', s.ip_address], ['Amount', s.total_validated], ['SHA-256', s.hash],
            ['Verify code', s.verify_code], ['Status', s.status], ['Device', s.device]
        ]);
        var head = el('div', 'ui-title-row');
        head.appendChild(el('h3', 'ui-h3', isAuto ? 'Auto-stamped' : 'Director stamp'));
        head.appendChild(badge('Valid', 'success'));
        return card(head, d);
    }

    function openDetail(id) {
        loadingRow(detail);
        detailActions.textContent = '';
        document.getElementById('req-detail-dialog-title').textContent = 'Requisition';
        HOD.dialog.open('req-detail-dialog');
        call('fetch_requisition_detail', { req_id: id }).then(function (res) {
            var h = res.header;
            var title = document.getElementById('req-detail-dialog-title');
            title.textContent = h.ref_number + ' ';
            title.appendChild(statusBadge(h.status));
            detail.textContent = '';
            detail.appendChild(dl([['Department', h.department_name], ['Period', period(h)], ['Submitted by', h.submitted_by_name],
                ['Submitted', dateText(h.created_at)], ['Note', h.submission_note]]));

            var lines = el('div', 'ui-list');
            (res.items || []).forEach(function (i) {
                var row = el('div', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                var t = el('p', 'ui-list__title', i.item_description + ' ');
                if (i.status === 'Rejected') t.appendChild(badge('Flagged', 'warning'));
                main.appendChild(t);
                var meta = [i.quantity + ' × ' + naira(i.unit_cost)];
                if (i.category_name) meta.push(i.category_name);
                main.appendChild(el('p', 'ui-list__meta', meta.join(' · ')));
                if (i.status === 'Rejected' && i.rejection_reason) main.appendChild(el('p', 'ui-list__meta', 'Reason: ' + i.rejection_reason));
                row.appendChild(main);
                row.appendChild(el('p', 'ui-list__end is-num', naira(i.total_amount)));
                lines.appendChild(row);
            });
            var linesHead = el('div', 'ui-title-row');
            linesHead.appendChild(el('h3', 'ui-h3', 'Lines'));
            linesHead.appendChild(el('span', 'ui-count', naira(h.total_amount)));
            detail.appendChild(card(linesHead, lines));

            if ((res.attachments || []).length) {
                var files = el('div', 'ui-stack ui-stack--sm');
                res.attachments.forEach(function (a) {
                    var link = el('a', 'ui-link', a.file_name);
                    link.href = a.file_path;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    files.appendChild(link);
                });
                detail.appendChild(card('Documents', files));
            }
            if (res.director_stamp && res.director_stamp.stamp_data) detail.appendChild(stampCard(res.director_stamp.stamp_data, res.director_stamp.is_auto));
            if ((res.pastor_actions || []).length) {
                var acts = el('div', 'ui-list');
                res.pastor_actions.forEach(function (a) {
                    var row = el('div', 'ui-list__item');
                    var main = el('div', 'ui-list__main');
                    var t = el('p', 'ui-list__title');
                    t.appendChild(badge(a.action === 'Approved' ? 'Approved' : (a.action === 'Rejected' ? 'Rejected' : 'Sent back'),
                        a.action === 'Approved' ? 'success' : (a.action === 'Rejected' ? 'danger' : 'warning')));
                    t.appendChild(document.createTextNode(' ' + (a.pastor_name || '')));
                    main.appendChild(t);
                    var meta = [dateText(a.acted_at)];
                    if (a.has_signature) meta.push('signed');
                    main.appendChild(el('p', 'ui-list__meta', meta.join(' · ')));
                    if (a.notes) main.appendChild(el('p', 'ui-list__meta', a.notes));
                    row.appendChild(main);
                    acts.appendChild(row);
                });
                detail.appendChild(card('Pastor', acts));
            }
            if (res.batch_info) {
                detail.appendChild(card('Batch', dl([['Batch', res.batch_info.batch_ref], ['Status', res.batch_info.batch_status],
                    ['Paid', res.batch_info.disbursed_at ? dateText(res.batch_info.disbursed_at) : '']])));
            }
            drawDetailActions(h);
        }).catch(function () { emptyRow(detail, 'This request did not load. Close and try again.'); });
    }

    function drawDetailActions(h) {
        detailActions.textContent = '';
        var s = h.status;
        var owner = Number(h.submitted_by) === boot.me;
        if (s === 'Pending_Director' && boot.isDirector) {
            detailActions.appendChild(button('Reject', { variant: 'danger-ghost', size: 'md', data: { action: 'req.reject.dir', id: h.id } }));
            detailActions.appendChild(button('Stamp', { variant: 'primary', size: 'md', icon: 'circle-check', data: { action: 'req.stamp', id: h.id } }));
        }
        if ((s === 'Pending_Pastor' || s === 'Revision_Required') && boot.isPastor) {
            detailActions.appendChild(button('Reject all', { variant: 'danger-ghost', size: 'md', data: { action: 'req.reject.full', id: h.id } }));
            detailActions.appendChild(button('Flag lines', { size: 'md', data: { action: 'req.flag', id: h.id } }));
            detailActions.appendChild(button('Approve', { variant: 'primary', size: 'md', icon: 'circle-check', data: { action: 'req.approve', id: h.id } }));
        }
        if (s === 'Revision_Required' && owner) {
            detailActions.appendChild(button('Revise', { variant: 'primary', size: 'md', icon: 'pencil', data: { action: 'req.revise', id: h.id } }));
        }
        if ((s === 'Draft' || s === 'Pending_Director') && owner) {
            detailActions.appendChild(button('Cancel request', { variant: 'danger-ghost', size: 'md', data: { action: 'req.cancel', id: h.id } }));
        }
        if (s === 'Approved' || s === 'Batched' || s === 'Disbursed') detailActions.appendChild(pdfLink('req_id', h.id));
        if (!detailActions.childNodes.length) detailActions.appendChild(el('p', 'ui-meta', 'Nothing to do here.'));
    }

    HOD.actions.on('req.open', function (btn) { openDetail(btn.getAttribute('data-id')); });

    function refreshAll() {
        loadRequests();
        if (boot.isDirector) loadDirectorQueue();
        if (state.loaded.approval) loadApproval();
        if (state.loaded.finance) loadFinance();
        loadCounts();
    }

    /* ----------------------------------------------------- director actions */

    HOD.actions.on('req.stamp', function (btn) {
        HOD.confirm({ title: 'Stamp this request?', message: 'Your Director stamp sends it to the Resident Pastor.', confirmLabel: 'Stamp' })
            .then(function (yes) {
                if (!yes) return;
                HOD.busy(btn, true);
                call('validate_director', { req_id: btn.getAttribute('data-id') }).then(function (res) {
                    HOD.toast(res.message, 'success');
                    HOD.dialog.close('req-detail-dialog');
                    refreshAll();
                }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
            });
    });

    var reasonForm = document.getElementById('req-reason-form');

    function openReason(mode, id) {
        reasonForm.reset();
        reasonForm.elements.mode.value = mode;
        reasonForm.elements.req_id.value = id;
        var full = mode === 'full';
        document.getElementById('req-reason-dialog-title').textContent = full ? 'Reject all lines' : 'Reject requisition';
        reasonForm.querySelector('[data-req-reason-note]').textContent = full
            ? 'The whole request is rejected; they start again from scratch.'
            : 'They are told why and can submit again.';
        HOD.dialog.open('req-reason-dialog');
    }

    HOD.actions.on('req.reject.dir', function (btn) { openReason('director', btn.getAttribute('data-id')); });
    HOD.actions.on('req.reject.full', function (btn) { openReason('full', btn.getAttribute('data-id')); });

    HOD.actions.on('req.reason.save', function (form) {
        var reason = form.elements.reason.value.trim();
        if (!reason) { HOD.form.setErrors(form, { reason: 'Say why, so they can fix it.' }); return; }
        var full = form.elements.mode.value === 'full';
        call(full ? 'pastor_reject_full' : 'reject_director', full
            ? { req_id: form.elements.req_id.value, notes: reason }
            : { req_id: form.elements.req_id.value, rejection_reason: reason }).then(function (res) {
            HOD.toast(res.message, 'success');
            HOD.dialog.close('req-reason-dialog');
            HOD.dialog.close('req-detail-dialog');
            refreshAll();
        }).catch(function () {});
    });

    /* ------------------------------------------------------------ approval */

    var approvalBox = document.querySelector('[data-req-approval]');

    function loadApproval() {
        if (!approvalBox) return;
        loadingRow(approvalBox);
        call('fetch_approval_queue', { filter: state.approvalFilter }).then(function (res) {
            var q = res.queue || [];
            approvalBox.textContent = '';
            if (!q.length) { emptyRow(approvalBox, 'Nothing is waiting for you.'); return; }
            q.forEach(function (r) {
                approvalBox.appendChild(requestRow(r, { director: true, note: true, since: 'director_validated_at', actions: [
                    button('Approve', { variant: 'primary', icon: 'circle-check', data: { action: 'req.approve', id: r.id } }),
                    button('Flag lines', { data: { action: 'req.flag', id: r.id } }),
                    button('Reject all', { variant: 'danger-ghost', data: { action: 'req.reject.full', id: r.id } })
                ] }));
            });
        }).catch(function () { emptyRow(approvalBox, 'The queue did not load. Reload the page to try again.'); });
    }

    HOD.actions.on('req.approval.filter', function (btn) {
        state.approvalFilter = btn.getAttribute('data-value');
        setPressed('req.approval.filter', state.approvalFilter);
        loadApproval();
    });

    var approveForm = document.getElementById('req-approve-form');
    var approveImg = approveForm.querySelector('[data-req-approve-sig]');
    var approveNoSig = approveForm.querySelector('[data-req-approve-nosig]');

    function setApproveSig(b64) {
        state.approveSig = b64 || '';
        approveImg.hidden = !b64;
        approveNoSig.hidden = !!b64;
        if (b64) approveImg.src = b64; else approveImg.removeAttribute('src');
    }

    HOD.actions.on('req.approve', function (btn) {
        approveForm.reset();
        approveForm.elements.req_id.value = btn.getAttribute('data-id');
        setApproveSig('');
        HOD.dialog.open('req-approve-dialog');
        call('check_signature_vault', {}, { quiet: true }).then(function (res) {
            state.hasVault = !!res.has_signature;
            approveForm.querySelector('[data-req-use-stored]').hidden = !state.hasVault;
        }).catch(function () {});
    });

    HOD.actions.on('req.approve.save', function (form) {
        if (!state.approveSig) { HOD.toast('Sign first: draw your signature or use the stored one.', 'warning'); return; }
        var submit = document.querySelector('[form="req-approve-form"][type="submit"]');
        HOD.busy(submit, true);
        call('pastor_approve', { req_id: form.elements.req_id.value, signature_base64: state.approveSig, notes: form.elements.notes.value })
            .then(function (res) {
                HOD.toast(res.message, 'success');
                HOD.dialog.close('req-approve-dialog');
                HOD.dialog.close('req-detail-dialog');
                refreshAll();
            }).catch(function () {}).finally(function () { HOD.busy(submit, false); });
    });

    /* --------------------------------------------------------- signature pad */

    var canvas = document.querySelector('[data-req-canvas]');
    var ctx = null, drawing = false, last = null, inked = false;

    function setupCanvas() {
        var r = canvas.getBoundingClientRect();
        if (!r.width || !r.height) { setTimeout(setupCanvas, 50); return; }
        var ratio = window.devicePixelRatio || 1;
        canvas.width = Math.round(r.width * ratio);
        canvas.height = Math.round(r.height * ratio);
        ctx = canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.strokeStyle = '#1D356A';
        ctx.lineWidth = 2.8;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.clearRect(0, 0, r.width, r.height);
        inked = false;
    }

    function point(e) {
        var r = canvas.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    canvas.addEventListener('pointerdown', function (e) {
        if (!ctx) return;
        e.preventDefault();
        canvas.setPointerCapture(e.pointerId);
        drawing = true;
        last = point(e);
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!drawing) return;
        e.preventDefault();
        var p = point(e);
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        last = p;
        inked = true;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
        canvas.addEventListener(ev, function () { drawing = false; });
    });

    HOD.actions.on('req.sign', function (btn) {
        state.signTarget = btn.getAttribute('data-target') || 'approve';
        HOD.dialog.open('req-sign-dialog');
        setTimeout(setupCanvas, 60);
    });
    HOD.actions.on('req.sign.clear', setupCanvas);
    HOD.actions.on('req.sign.done', function () {
        if (!ctx || !inked) { HOD.toast('Draw your signature first.', 'warning'); return; }
        var b64 = canvas.toDataURL('image/png');
        HOD.dialog.close('req-sign-dialog');
        if (state.signTarget === 'vault') setVaultSig(b64); else setApproveSig(b64);
    });

    /* ------------------------------------------------------- PIN + the vault */

    var pinForm = document.getElementById('req-pin-form');

    HOD.actions.on('req.pin', function () {
        HOD.dialog.open('req-pin-dialog');
        // Cleared after it shows, so a browser's autofill cannot leave a PIN in it.
        setTimeout(function () { pinForm.elements.pin.value = ''; pinForm.elements.pin.focus(); }, 150);
    });

    HOD.actions.on('req.pin.save', function (form) {
        var pin = form.elements.pin.value.trim();
        if (!/^\d{4}$/.test(pin)) { HOD.form.setErrors(form, { pin: 'The PIN is 4 digits.' }); return; }
        call('verify_pin_get_signature', { pin: pin }, { quiet: true }).then(function (res) {
            HOD.dialog.close('req-pin-dialog');
            setApproveSig(res.signature_base64);
            HOD.toast('Signature added.', 'success');
        }).catch(function (err) {
            HOD.form.setErrors(form, { pin: (err && err.message) || 'That PIN did not match. Try again.' });
        });
    });

    var vaultImg = document.querySelector('[data-req-vault-sig]');

    function setVaultSig(b64) {
        state.vaultSig = b64 || '';
        vaultImg.hidden = !b64;
        if (b64) vaultImg.src = b64; else vaultImg.removeAttribute('src');
    }

    HOD.actions.on('req.vault', function () {
        setVaultSig('');
        document.getElementById('req-vault-pin').value = '';
        document.getElementById('req-vault-delete-pin').value = '';
        var status = document.querySelector('[data-req-vault-status]');
        status.textContent = 'Checking…';
        HOD.dialog.open('req-vault-dialog');
        call('check_signature_vault', {}, { quiet: true }).then(function (res) {
            state.hasVault = !!res.has_signature;
            status.textContent = res.has_signature
                ? 'Stored since ' + dateText(res.created_at) + '. Last used: ' + (res.last_used_at ? dateText(res.last_used_at) : 'never') + '.'
                : 'Nothing stored yet. Draw one and set a PIN.';
            document.querySelector('[data-req-vault-delete]').hidden = !res.has_signature;
        }).catch(function () { status.textContent = 'The vault did not answer. Close and try again.'; });
    });

    HOD.actions.on('req.vault.save', function (btn) {
        var pin = document.getElementById('req-vault-pin').value.trim();
        if (!state.vaultSig) { HOD.toast('Draw your signature first.', 'warning'); return; }
        if (!/^\d{4}$/.test(pin)) { HOD.toast('The PIN is 4 digits.', 'warning'); return; }
        HOD.busy(btn, true);
        call('save_signature_vault', { signature_base64: state.vaultSig, pin: pin }).then(function (res) {
            HOD.toast(res.message, 'success');
            state.hasVault = true;
            HOD.dialog.close('req-vault-dialog');
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('req.vault.delete', function (btn) {
        var pin = document.getElementById('req-vault-delete-pin').value.trim();
        if (!pin) { HOD.toast('Type your PIN to delete it.', 'warning'); return; }
        HOD.confirm({ title: 'Delete your stored signature?', message: 'You can store a new one any time.', confirmLabel: 'Delete', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                HOD.busy(btn, true);
                call('delete_signature_vault', { pin: pin }).then(function (res) {
                    HOD.toast(res.message, 'success');
                    state.hasVault = false;
                    HOD.dialog.close('req-vault-dialog');
                }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
            });
    });

    /* ------------------------------------------------------- flag / revise */

    var linesForm = document.getElementById('req-lines-form');
    var linesList = linesForm.querySelector('[data-req-lines-list]');

    HOD.actions.on('req.flag', function (btn) {
        linesForm.reset();
        linesForm.elements.req_id.value = btn.getAttribute('data-id');
        loadingRow(linesList);
        HOD.dialog.open('req-lines-dialog');
        call('fetch_requisition_detail', { req_id: btn.getAttribute('data-id') }).then(function (res) {
            var eligible = (res.items || []).filter(function (i) { return i.status !== 'Rejected'; });
            linesList.textContent = '';
            if (!eligible.length) { linesList.appendChild(el('p', 'ui-meta', 'No line can be flagged.')); return; }
            eligible.forEach(function (i) {
                var box = el('div', 'ui-card');
                var body = el('div', 'ui-card__body ui-stack ui-stack--sm');
                var lab = el('label', 'ui-check');
                var cb = el('input');
                cb.type = 'checkbox';
                cb.setAttribute('data-item', i.id);
                cb.setAttribute('data-action-change', 'req.flag.toggle');
                lab.appendChild(cb);
                lab.appendChild(el('span', '', i.item_description + ' · ' + naira(i.total_amount)));
                body.appendChild(lab);
                var reason = el('input', 'ui-input');
                reason.type = 'text';
                reason.placeholder = 'Why it needs changing';
                reason.setAttribute('aria-label', 'Reason for ' + i.item_description);
                reason.setAttribute('data-reason', i.id);
                reason.hidden = true;
                body.appendChild(reason);
                box.appendChild(body);
                linesList.appendChild(box);
            });
        }).catch(function () { emptyRow(linesList, 'The lines did not load. Close and try again.'); });
    });

    HOD.actions.on('req.flag.toggle', function (cb) {
        var r = linesList.querySelector('[data-reason="' + cb.getAttribute('data-item') + '"]');
        if (r) { r.hidden = !cb.checked; if (cb.checked) r.focus(); }
    });

    HOD.actions.on('req.lines.save', function (form) {
        var picks = linesList.querySelectorAll('input[type="checkbox"]:checked');
        if (!picks.length) { HOD.toast('Tick at least one line to send back.', 'warning'); return; }
        var rejections = [];
        for (var k = 0; k < picks.length; k++) {
            var id = picks[k].getAttribute('data-item');
            var reason = (linesList.querySelector('[data-reason="' + id + '"]').value || '').trim();
            if (!reason) { HOD.toast('Give a reason for each flagged line.', 'warning'); return; }
            rejections.push({ item_id: id, reason: reason });
        }
        call('pastor_reject_lines', { req_id: form.elements.req_id.value, rejections: JSON.stringify(rejections), notes: form.elements.notes.value })
            .then(function (res) {
                HOD.toast(res.message, 'success');
                HOD.dialog.close('req-lines-dialog');
                HOD.dialog.close('req-detail-dialog');
                refreshAll();
            }).catch(function () {});
    });

    var reviseForm = document.getElementById('req-revise-form');
    var reviseList = reviseForm.querySelector('[data-req-revise-list]');

    function numberField(label, value, attr) {
        var f = el('div', 'ui-field');
        var lab = el('label', 'ui-label', label);
        var input = el('input', 'ui-input');
        input.type = 'number';
        input.step = '0.01';
        input.min = '0.01';
        input.value = value;
        input.setAttribute(attr, '');
        input.setAttribute('data-action-input', 'req.revise.calc');
        input.id = 'rev-' + attr + '-' + Math.random().toString(36).slice(2, 8);
        lab.htmlFor = input.id;
        f.appendChild(lab);
        f.appendChild(input);
        return f;
    }

    HOD.actions.on('req.revise', function (btn) {
        reviseForm.elements.req_id.value = btn.getAttribute('data-id');
        loadingRow(reviseList);
        HOD.dialog.open('req-revise-dialog');
        call('fetch_requisition_detail', { req_id: btn.getAttribute('data-id') }).then(function (res) {
            var flagged = (res.items || []).filter(function (i) { return i.status === 'Rejected'; });
            reviseList.textContent = '';
            if (!flagged.length) { reviseList.appendChild(el('p', 'ui-meta', 'No line is flagged.')); return; }
            flagged.forEach(function (i) {
                var box = el('div', 'ui-card');
                box.setAttribute('data-rev', i.id);
                var body = el('div', 'ui-card__body ui-stack ui-stack--sm');
                if (i.rejection_reason) body.appendChild(el('p', 'ui-meta', 'Why: ' + i.rejection_reason));
                var f = el('div', 'ui-field');
                var lab = el('label', 'ui-label', 'Description');
                var desc = el('input', 'ui-input');
                desc.type = 'text';
                desc.value = i.item_description;
                desc.setAttribute('data-rev-desc', '');
                desc.id = 'rev-desc-' + i.id;
                lab.htmlFor = desc.id;
                f.appendChild(lab);
                f.appendChild(desc);
                body.appendChild(f);
                var grid = el('div', 'ui-form-grid ui-form-grid--3');
                grid.appendChild(numberField('Quantity', i.quantity, 'data-rev-qty'));
                grid.appendChild(numberField('Unit cost (₦)', i.unit_cost, 'data-rev-cost'));
                var tot = el('div', 'ui-field');
                tot.appendChild(el('p', 'ui-label', 'Total'));
                tot.appendChild(el('p', 'ui-h3 is-num', naira(i.total_amount))).setAttribute('data-rev-total', '');
                grid.appendChild(tot);
                body.appendChild(grid);
                box.appendChild(body);
                reviseList.appendChild(box);
            });
        }).catch(function () { emptyRow(reviseList, 'The lines did not load. Close and try again.'); });
    });

    HOD.actions.on('req.revise.calc', function (input) {
        var box = input.closest('[data-rev]');
        var q = parseFloat(box.querySelector('[data-rev-qty]').value || 0);
        var c = parseFloat(box.querySelector('[data-rev-cost]').value || 0);
        box.querySelector('[data-rev-total]').textContent = naira(q * c);
    });

    HOD.actions.on('req.revise.save', function (form) {
        var revised = [];
        var boxes = reviseList.querySelectorAll('[data-rev]');
        for (var k = 0; k < boxes.length; k++) {
            var b = boxes[k];
            var desc = b.querySelector('[data-rev-desc]').value.trim();
            var qty = parseFloat(b.querySelector('[data-rev-qty]').value || 0);
            var cost = parseFloat(b.querySelector('[data-rev-cost]').value || 0);
            if (!desc || cost <= 0 || qty <= 0) { HOD.toast('Each line needs a description, a quantity and a cost.', 'warning'); return; }
            revised.push({ item_id: b.getAttribute('data-rev'), item_description: desc, quantity: qty, unit_cost: cost });
        }
        call('resubmit_revised', { req_id: form.elements.req_id.value, revised_items: JSON.stringify(revised) }).then(function (res) {
            HOD.toast(res.message, 'success');
            HOD.dialog.close('req-revise-dialog');
            HOD.dialog.close('req-detail-dialog');
            refreshAll();
        }).catch(function () {});
    });

    HOD.actions.on('req.cancel', function (btn) {
        HOD.confirm({ title: 'Cancel this request?', message: 'It cannot be undone.', confirmLabel: 'Cancel request', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                call('cancel_requisition', { req_id: btn.getAttribute('data-id') }).then(function (res) {
                    HOD.toast(res.message, 'success');
                    HOD.dialog.close('req-detail-dialog');
                    refreshAll();
                }).catch(function () {});
            });
    });

    /* ------------------------------------------------------- new requisition */

    var newForm = document.getElementById('req-new-form');
    var linesBox = newForm.querySelector('[data-req-lines]');
    var lineSeq = 0;

    function addLine() {
        var id = ++lineSeq;
        var row = el('div', 'ui-form-grid ui-form-grid--3');
        row.setAttribute('data-line', id);
        function field(label, input) {
            var f = el('div', 'ui-field');
            input.id = 'req-line-' + id + '-' + label.replace(/\W+/g, '').toLowerCase();
            var lab = el('label', 'ui-label', label);
            lab.htmlFor = input.id;
            f.appendChild(lab);
            f.appendChild(input);
            return f;
        }
        var desc = el('input', 'ui-input');
        desc.type = 'text';
        desc.setAttribute('data-li', 'desc');
        desc.maxLength = 255;
        var d = field('Description', desc);
        d.classList.add('ui-span-full');
        row.appendChild(d);
        var qty = el('input', 'ui-input');
        qty.type = 'number'; qty.step = '0.01'; qty.min = '0.01'; qty.value = '1';
        qty.setAttribute('data-li', 'qty');
        qty.setAttribute('data-action-input', 'req.line.calc');
        row.appendChild(field('Quantity', qty));
        var cost = el('input', 'ui-input');
        cost.type = 'number'; cost.step = '1'; cost.min = '1'; cost.inputMode = 'decimal';
        cost.setAttribute('data-li', 'cost');
        cost.setAttribute('data-action-input', 'req.line.calc');
        row.appendChild(field('Unit cost (₦)', cost));
        var cat = el('select', 'ui-select');
        cat.setAttribute('data-li', 'cat');
        [['', '—']].concat(state.categories.map(function (c) { return [String(c.id), c.category_name]; })).forEach(function (o) {
            var opt = el('option', '', o[1]); opt.value = o[0]; cat.appendChild(opt);
        });
        row.appendChild(field('Category', cat));
        var end = el('div', 'ui-toolbar ui-span-full');
        var total = el('p', 'ui-meta ui-toolbar__grow is-num', naira(0));
        total.setAttribute('data-li-total', '');
        end.appendChild(total);
        var rm = button('Remove line', { variant: 'danger-ghost', icon: 'x', data: { action: 'req.line.remove', 'remove-line': id } });
        end.appendChild(rm);
        row.appendChild(end);
        linesBox.appendChild(row);
        desc.focus();
    }

    function recalc() {
        var sum = 0;
        linesBox.querySelectorAll('[data-line]').forEach(function (row) {
            var q = parseFloat(row.querySelector('[data-li="qty"]').value || 0);
            var c = parseFloat(row.querySelector('[data-li="cost"]').value || 0);
            row.querySelector('[data-li-total]').textContent = naira(q * c);
            sum += q * c;
        });
        newForm.querySelector('[data-req-total]').textContent = naira(sum);
    }

    HOD.actions.on('req.new', function () {
        newForm.reset();
        linesBox.textContent = '';
        lineSeq = 0;
        newForm.querySelector('[data-req-files]').textContent = '';
        var dept = newForm.elements.department_id;
        dept.textContent = '';
        [['', state.depts.length ? 'Choose…' : 'No department: ask the church office']].concat(state.depts.map(function (d) {
            return [String(d.id), d.name];
        })).forEach(function (o) { var opt = el('option', '', o[1]); opt.value = o[0]; dept.appendChild(opt); });
        if (state.depts.length === 1) dept.value = String(state.depts[0].id);
        var warn = newForm.querySelector('[data-req-window]');
        warn.hidden = !state.windowWarning;
        if (state.windowWarning) warn.querySelector('.ui-banner__text').textContent = state.windowWarning;
        newForm.querySelector('[data-req-monthly]').hidden = false;
        newForm.querySelector('[data-req-occasional]').hidden = true;
        addLine();
        recalc();
        HOD.dialog.open('req-new-dialog');
    });

    HOD.actions.on('req.type', function (select) {
        var monthly = select.value === 'Monthly';
        newForm.querySelector('[data-req-monthly]').hidden = !monthly;
        newForm.querySelector('[data-req-occasional]').hidden = monthly;
    });
    HOD.actions.on('req.line.add', function () { addLine(); });
    HOD.actions.on('req.line.remove', function (btn) {
        var row = linesBox.querySelector('div[data-line="' + btn.getAttribute('data-remove-line') + '"]');
        if (row) row.remove();
        recalc();
    });
    HOD.actions.on('req.line.calc', recalc);
    HOD.actions.on('req.files', function (input) {
        var box = newForm.querySelector('[data-req-files]');
        box.textContent = '';
        Array.prototype.forEach.call(input.files || [], function (f) {
            var row = el('div', 'ui-list__item');
            row.appendChild(el('p', 'ui-list__main', f.name));
            row.appendChild(el('p', 'ui-list__end ui-meta', Math.round(f.size / 1024) + ' KB'));
            box.appendChild(row);
        });
    });

    HOD.actions.on('req.submit', function (form) {
        var errors = {};
        if (!form.elements.department_id.value) errors.department_id = 'Choose the department.';
        if (form.elements.type.value === 'Occasional' && !form.elements.event_label.value.trim()) errors.event_label = 'Say what it is for.';
        var items = [];
        var bad = false;
        linesBox.querySelectorAll('[data-line]').forEach(function (row) {
            var desc = row.querySelector('[data-li="desc"]').value.trim();
            var qty = parseFloat(row.querySelector('[data-li="qty"]').value || 0);
            var cost = parseFloat(row.querySelector('[data-li="cost"]').value || 0);
            if (!desc || cost <= 0 || qty <= 0) { bad = true; return; }
            items.push({ item_description: desc, quantity: qty, unit_cost: cost, finance_category_id: row.querySelector('[data-li="cat"]').value || '' });
        });
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        if (bad || !items.length) { HOD.toast('Every line needs a description, a quantity and a cost.', 'warning'); return; }
        var fd = new FormData(form);
        fd.set('items', JSON.stringify(items));
        var submit = document.querySelector('[form="req-new-form"][type="submit"]');
        HOD.busy(submit, true);
        HOD.api(API, { action: 'submit_requisition', data: fd }).then(function (res) {
            HOD.toast(res.message, 'success');
            HOD.dialog.close('req-new-dialog');
            refreshAll();
        }).catch(function () {}).finally(function () { HOD.busy(submit, false); });
    });

    /* -------------------------------------------------------------- finance */

    var availBox = document.querySelector('[data-req-avail]');
    var draftBox = document.querySelector('[data-req-draft]');
    var batchesBox = document.querySelector('[data-req-batches]');

    function loadFinance() {
        loadingRow(availBox);
        call('fetch_finance_queue').then(function (res) {
            var avail = res.available_requisitions || [];
            var drafts = res.draft_items || [];
            var batches = res.batches || [];
            var draft = batches.filter(function (b) { return b.status === 'Draft'; })[0] || null;
            state.draftBatch = draft;
            document.querySelector('[data-req-avail-count]').textContent = avail.length;
            setTabCount('finance', avail.length);

            availBox.textContent = '';
            if (!avail.length) emptyRow(availBox, 'No approved request is waiting.');
            avail.forEach(function (r) {
                var inDraft = drafts.some(function (d) { return String(d.requisition_id) === String(r.id); });
                var actions = [];
                if (draft) {
                    actions.push(inDraft
                        ? button('Take out', { variant: 'ghost', data: { action: 'req.batch.toggle', batch: draft.id, id: r.id, toggle: 'remove' } })
                        : button('Add', { icon: 'plus', data: { action: 'req.batch.toggle', batch: draft.id, id: r.id, toggle: 'add' } }));
                }
                availBox.appendChild(requestRow(r, { actions: actions }));
            });

            document.querySelector('[data-req-batch-new]').hidden = !!draft;
            draftBox.textContent = '';
            if (!draft) {
                var e = el('div', 'ui-card__body');
                e.appendChild(el('p', 'ui-meta', 'No open batch. Create one to group requests.'));
                draftBox.appendChild(e);
            } else {
                var items = drafts.filter(function (d) { return String(d.batch_id) === String(draft.id); });
                var head = el('div', 'ui-card__body ui-toolbar');
                var t = el('div', 'ui-toolbar__grow');
                t.appendChild(el('p', 'ui-list__title', draft.batch_ref));
                if (draft.batch_label) t.appendChild(el('p', 'ui-meta', draft.batch_label));
                head.appendChild(t);
                head.appendChild(el('p', 'ui-h3 is-num', naira(draft.total_amount)));
                draftBox.appendChild(head);
                var list = el('div', 'ui-list');
                if (!items.length) list.appendChild(el('p', 'ui-list__item ui-meta', 'Add requests from Ready to batch.'));
                items.forEach(function (i) {
                    var row = el('div', 'ui-list__item');
                    row.appendChild(el('p', 'ui-list__main', i.ref_number + ' · ' + (i.dept_name || '')));
                    row.appendChild(el('p', 'ui-list__end is-num', naira(i.amount_in_batch)));
                    list.appendChild(row);
                });
                draftBox.appendChild(list);
                var foot = el('div', 'ui-card__footer ui-form-actions');
                var seal = button('Seal batch', { variant: 'primary', size: 'md', data: { action: 'req.batch.seal', batch: draft.id } });
                seal.disabled = !items.length;
                foot.appendChild(seal);
                draftBox.appendChild(foot);
            }

            batchesBox.textContent = '';
            var done = batches.filter(function (b) { return b.status !== 'Draft'; });
            if (!done.length) emptyRow(batchesBox, 'No sealed batch yet.');
            done.forEach(function (b) {
                var row = el('div', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                var t = el('p', 'ui-list__title', b.batch_ref + ' ');
                t.appendChild(badge(b.status === 'Disbursed' ? 'Paid' : b.status, b.status === 'Disbursed' ? 'success' : 'info'));
                main.appendChild(t);
                main.appendChild(el('p', 'ui-list__meta', b.item_count + ' requests · ' + dateText(b.created_at) + (b.disbursed_at ? ' · paid ' + dateText(b.disbursed_at) : '')));
                row.appendChild(main);
                var end = el('div', 'ui-list__end ui-stack ui-stack--sm');
                end.appendChild(el('p', 'ui-h3 is-num', naira(b.total_amount)));
                if (b.status === 'Exported') end.appendChild(button('Mark paid', { variant: 'primary', data: { action: 'req.pay', batch: b.id } }));
                end.appendChild(pdfLink('batch_id', b.id));
                row.appendChild(end);
                batchesBox.appendChild(row);
            });
        }).catch(function () { emptyRow(availBox, 'Finance did not load. Reload the page to try again.'); });
    }

    HOD.actions.on('req.batch.new', function () {
        document.getElementById('req-batch-form').reset();
        HOD.dialog.open('req-batch-dialog');
    });
    HOD.actions.on('req.batch.save', function (form) {
        call('create_batch', { batch_label: form.elements.batch_label.value }).then(function (res) {
            HOD.toast(res.message, 'success');
            HOD.dialog.close('req-batch-dialog');
            loadFinance();
        }).catch(function () {});
    });
    HOD.actions.on('req.batch.toggle', function (btn) {
        HOD.busy(btn, true);
        call('toggle_batch_item', { batch_id: btn.getAttribute('data-batch'), req_id: btn.getAttribute('data-id'), toggle: btn.getAttribute('data-toggle') })
            .then(loadFinance).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });
    HOD.actions.on('req.batch.seal', function (btn) {
        HOD.confirm({ title: 'Seal this batch?', message: 'Your Finance stamp locks it; nothing can be added after.', confirmLabel: 'Seal batch' })
            .then(function (yes) {
                if (!yes) return;
                HOD.busy(btn, true);
                call('seal_batch', { batch_id: btn.getAttribute('data-batch') }).then(function (res) {
                    HOD.toast(res.message, 'success');
                    loadFinance();
                }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
            });
    });

    var payForm = document.getElementById('req-pay-form');

    function fillSelect(select, options) {
        select.textContent = '';
        options.forEach(function (o) { var opt = el('option', '', o[1]); opt.value = o[0]; select.appendChild(opt); });
    }

    HOD.actions.on('req.pay', function (btn) {
        payForm.reset();
        payForm.elements.batch_id.value = btn.getAttribute('data-batch');
        fillSelect(payForm.elements.account_id, [['', 'Loading…']]);
        fillSelect(payForm.elements.fund_id, [['', 'Loading…']]);
        HOD.dialog.open('req-pay-dialog');
        call('fetch_accounts_funds').then(function (res) {
            fillSelect(payForm.elements.account_id, [['', 'Choose…']].concat((res.accounts || []).map(function (a) {
                return [String(a.id), a.account_name + ' (' + a.account_type + ') · ' + naira(a.current_balance)];
            })));
            fillSelect(payForm.elements.fund_id, [['', 'Choose…']].concat((res.funds || []).map(function (f) { return [String(f.id), f.fund_name]; })));
        }).catch(function () {});
    });

    HOD.actions.on('req.pay.save', function (form) {
        var errors = {};
        if (!form.elements.account_id.value) errors.account_id = 'Choose the account.';
        if (!form.elements.fund_id.value) errors.fund_id = 'Choose the fund.';
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        call('disburse_batch', { batch_id: form.elements.batch_id.value, account_id: form.elements.account_id.value, fund_id: form.elements.fund_id.value })
            .then(function (res) {
                HOD.toast(res.message, 'success');
                HOD.dialog.close('req-pay-dialog');
                loadFinance();
            }).catch(function () {});
    });

    /* ------------------------------------------------------------ analytics */

    function setStat(key, v) {
        var n = document.querySelector('[data-stat="' + key + '"] .ui-stat__value');
        if (n) n.textContent = v;
    }

    function chart(name, cfg) {
        var c = document.querySelector('[data-req-chart="' + name + '"]');
        if (c) HOD.chart(c, cfg).catch(function () {});
    }

    function nairaShort(v) { return '₦' + Math.round(v / 1000) + 'k'; }

    function loadAnalytics() {
        var data = { period: state.period, department_id: document.getElementById('req-dept-filter').value || '' };
        if (state.period === 'custom') {
            data.start_date = document.getElementById('req-from').value;
            data.end_date = document.getElementById('req-to').value;
        }
        call('fetch_analytics', data).then(function (res) {
            var df = document.getElementById('req-dept-filter');
            if (df.options.length <= 1 && res.all_departments) {
                res.all_departments.forEach(function (d) { df.add(new Option(d.name, d.id)); });
            }
            if (res.period) document.querySelector('[data-req-period]').textContent = dateText(res.period.start) + ' – ' + dateText(res.period.end);
            var total = 0, amount = 0, paid = 0;
            (res.status_breakdown || []).forEach(function (s) {
                total += parseInt(s.cnt, 10) || 0;
                amount += parseFloat(s.total || 0);
                if (s.status === 'Disbursed') paid += parseFloat(s.total || 0);
            });
            setStat('total', HOD.format.number(total));
            setStat('amount', naira(amount));
            setStat('cycle', res.cycle_days > 0 ? res.cycle_days : '—');
            setStat('paid', naira(paid));

            var sb = res.status_breakdown || [];
            chart('status', { type: 'doughnut', data: {
                labels: sb.map(function (d) { return (STATUS[d.status] || [d.status])[0]; }),
                datasets: [{ data: sb.map(function (d) { return d.cnt; }), backgroundColor: sb.map(function (d) { return STATUS_COLOURS[d.status] || '#C9CED8'; }), borderWidth: 2, borderColor: '#fff' }]
            }, options: { cutout: '65%', plugins: { legend: { position: 'right' } } } });
            var mt = res.monthly_trend || [];
            chart('trend', { type: 'bar', data: {
                labels: mt.map(function (d) { return d.month_label; }),
                datasets: [
                    { label: 'Asked', data: mt.map(function (d) { return parseFloat(d.total_amount); }), backgroundColor: HOD.palette[0] },
                    { label: 'Approved', data: mt.map(function (d) { return parseFloat(d.approved_amount); }), backgroundColor: HOD.palette[3] }
                ]
            }, options: { scales: { y: { ticks: { callback: nairaShort } } } } });
            var dc = res.dept_comparison || [];
            document.querySelector('[data-req-dept-card]').hidden = !dc.length;
            if (dc.length) {
                chart('dept', { type: 'bar', data: {
                    labels: dc.map(function (d) { return d.dept_name; }),
                    datasets: [
                        { label: 'Asked', data: dc.map(function (d) { return parseFloat(d.total_amount); }), backgroundColor: HOD.palette[0] },
                        { label: 'Approved', data: dc.map(function (d) { return parseFloat(d.approved_amount); }), backgroundColor: HOD.palette[3] }
                    ]
                }, options: { indexAxis: 'y', scales: { x: { ticks: { callback: nairaShort } } } } });
            }

            var top = document.querySelector('[data-req-top]');
            top.textContent = '';
            var items = res.top_items || [];
            if (!items.length) emptyRow(top, 'Nothing in this period.');
            var max = items.length ? parseFloat(items[0].total_requested) || 0 : 0;
            items.forEach(function (it, i) {
                var row = el('div', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                main.appendChild(el('p', 'ui-list__title', (i + 1) + '. ' + it.item_description));
                var bar = el('progress', 'ui-progress ui-progress--sm');
                bar.max = 100;
                bar.value = max > 0 ? Math.round(parseFloat(it.total_requested) / max * 100) : 0;
                bar.setAttribute('aria-label', 'Share of the largest line');
                main.appendChild(bar);
                main.appendChild(el('p', 'ui-list__meta', it.frequency + '× asked' + (it.category_name ? ' · ' + it.category_name : '')));
                row.appendChild(main);
                row.appendChild(el('p', 'ui-list__end is-num', naira(it.total_requested)));
                top.appendChild(row);
            });

            var bh = res.batch_history || [];
            var showBh = !!res.can_see_all && bh.length > 0;
            document.querySelector('[data-req-bh-card]').hidden = !showBh;
            var body = document.querySelector('[data-req-bh]');
            body.textContent = '';
            if (showBh) {
                bh.forEach(function (b) {
                    var tr = el('tr');
                    [[b.batch_ref, 'Batch', ''], [b.status === 'Disbursed' ? 'Paid' : b.status, 'Status', ''], [b.req_count, 'Requests', 'is-num'],
                        [naira(b.total_amount), 'Total', 'is-num'], [dateText(b.disbursed_at), 'Paid', '']].forEach(function (c) {
                        var td = el('td', c[2], c[0]);
                        td.setAttribute('data-label', c[1]);
                        tr.appendChild(td);
                    });
                    body.appendChild(tr);
                });
            }
        }).catch(function () {});
    }

    HOD.actions.on('req.period', function (btn) {
        state.period = btn.getAttribute('data-value');
        setPressed('req.period', state.period);
        document.querySelector('[data-req-custom]').hidden = state.period !== 'custom';
        if (state.period !== 'custom') loadAnalytics();
    });
    HOD.actions.on('req.analytics', loadAnalytics);

    /* ---------------------------------------------------------------- tabs */

    document.addEventListener('ui:tab', function (e) {
        var tab = e.detail && e.detail.tab;
        if (!tab || state.loaded[tab]) return;
        state.loaded[tab] = true;
        if (tab === 'approval') loadApproval();
        if (tab === 'finance') loadFinance();
        if (tab === 'analytics') loadAnalytics();
    });

    loadFormData();
})();
