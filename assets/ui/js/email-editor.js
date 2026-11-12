// /assets/ui/js/email-editor.js
// The block editor for emails: window.HODEmail.mount(root, options).
//
// An email is a JSON block document (includes/campaigns/doc.php), never HTML.
// This editor shows it as a column of blocks: text blocks are edited in place
// (contenteditable, with Bold / Italic / Link / lists / merge fields) and turned
// back into the JSON tree on every change; every other block is edited in the
// side panel. Pasted text arrives as plain text; nothing else from the
// clipboard gets in. Blocks move with Alt+↑/↓ (announced), the Move buttons,
// or by dragging the handle. The preview is the real server render
// (/api/email_draft_preview.php) in a sandboxed frame — never srcdoc. Saving
// is validated on the server first (/api/email_editor_api.php action=check).
//
// options: doc, onSave(doc) → Promise, storageKey (autosave in this browser),
//          savedAt (ISO time of the stored version), title.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/email_editor_api.php';
    var FIELDS = [
        ['first_name', 'First name'], ['last_name', 'Last name'], ['full_name', 'Full name'],
        ['church_name', 'Church name'], ['next_sunday', 'Next Sunday'], ['next_midweek', 'Next midweek service'],
        ['week_number', 'Week number'], ['streak', 'Sundays away'], ['last_attended', 'Last in church']
    ];
    var FIELD_LABEL = {};
    FIELDS.forEach(function (f) { FIELD_LABEL[f[0]] = f[1]; });
    var KINDS = {
        heading: { label: 'Heading', icon: 'file-text' },
        text: { label: 'Text', icon: 'message-square-text' },
        image: { label: 'Picture', icon: 'image' },
        button: { label: 'Button', icon: 'arrow-up-right' },
        scripture: { label: 'Scripture', icon: 'book-open' },
        quote: { label: 'Quote', icon: 'message-circle' },
        signature: { label: 'Signature', icon: 'pencil' },
        divider: { label: 'Divider', icon: 'minus' },
        spacer: { label: 'Space', icon: 'arrow-up-down' },
        event: { label: 'Event', icon: 'calendar' },
        footer: { label: 'Footer', icon: 'lock-keyhole' }
    };
    var ADDABLE = ['heading', 'text', 'image', 'button', 'scripture', 'quote', 'signature', 'divider', 'spacer'];
    var MAX_BLOCKS = 60;
    var seq = 0;

    function esc(v) { return HOD.esc(v == null ? '' : String(v)); }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function linkOk(url) { return /^(https:\/\/[^\s<>"]+|mailto:[^\s<>"]+@[^\s<>"]+|tel:\+?[0-9]{3,20})$/.test(url); }

    function newBlock(type) {
        switch (type) {
            case 'heading': return { type: 'heading', level: 1, align: 'left', text: 'A heading' };
            case 'text': return { type: 'text', align: 'left', content: [{ k: 'p', r: [{ t: 'Hi {{first_name}},' }] }] };
            case 'image': return { type: 'image', src: '', alt: '', width: 600, href: null, align: 'center' };
            case 'button': return { type: 'button', label: 'Find out more', href: 'https://', style: 'primary', align: 'left' };
            case 'scripture': return { type: 'scripture', ref: 'Psalm 23:1' };
            case 'quote': return { type: 'quote', text: '', cite: '' };
            case 'signature': return { type: 'signature', name: 'Your church family', line: 'Household of David Lekki' };
            case 'divider': return { type: 'divider' };
            case 'spacer': return { type: 'spacer', size: 'm' };
        }
        return null;
    }

    /* ------------------------------------------------------------------ *
     * Rich text: JSON tree <-> editable DOM
     * ------------------------------------------------------------------ */

    function chipHtml(field) {
        return '<span class="mx-0.5 inline-flex items-center rounded-full bg-primary-soft px-2 align-baseline text-sm font-medium text-primary" contenteditable="false" data-merge="'
            + esc(field) + '">' + esc(FIELD_LABEL[field] || field) + '</span>';
    }

    function textHtml(t) {
        var out = '';
        var last = 0;
        String(t).replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (m, field, at) {
            out += esc(t.slice(last, at)) + chipHtml(field);
            last = at + m.length;
            return m;
        });
        return out + esc(String(t).slice(last));
    }

    function runsHtml(runs) {
        var html = (runs || []).map(function (r) {
            var h = textHtml(r.t || '');
            if (r.b) h = '<strong>' + h + '</strong>';
            if (r.i) h = '<em>' + h + '</em>';
            if (r.href) h = '<a href="' + esc(r.href) + '">' + h + '</a>';
            return h;
        }).join('');
        return html || '<br>';
    }

    function contentHtml(content) {
        return (content || []).map(function (node) {
            if (node.k === 'ul' || node.k === 'ol') {
                return '<' + node.k + '>' + (node.items || []).map(function (item) { return '<li>' + runsHtml(item) + '</li>'; }).join('') + '</' + node.k + '>';
            }
            return '<p>' + runsHtml(node.r) + '</p>';
        }).join('') || '<p><br></p>';
    }

    function run(t, fmt) {
        var r = { t: t };
        if (fmt.b) r.b = 1;
        if (fmt.i) r.i = 1;
        if (fmt.href) r.href = fmt.href;
        return r;
    }

    // Inline content of a node → runs, with {br: true} markers for line breaks.
    function inlineRuns(node, fmt, out) {
        Array.prototype.forEach.call(node.childNodes, function (n) {
            if (n.nodeType === 3) {
                var t = n.nodeValue.replace(/[\s\u00a0]+/g, ' ');
                if (t) out.push(run(t, fmt));
                return;
            }
            if (n.nodeType !== 1) return;
            if (n.hasAttribute('data-merge')) {
                out.push(run('{{' + n.getAttribute('data-merge') + '}}', fmt));
                return;
            }
            var tag = n.tagName.toLowerCase();
            if (tag === 'br') { out.push({ br: true }); return; }
            if (/^(ul|ol|p|div|h[1-6]|blockquote|li)$/.test(tag)) { out.push({ br: true }); inlineRuns(n, fmt, out); out.push({ br: true }); return; }
            var f = { b: fmt.b, i: fmt.i, href: fmt.href };
            if (tag === 'b' || tag === 'strong' || (n.style && (n.style.fontWeight === 'bold' || parseInt(n.style.fontWeight, 10) >= 600))) f.b = 1;
            if (tag === 'i' || tag === 'em' || (n.style && n.style.fontStyle === 'italic')) f.i = 1;
            if (tag === 'a' && linkOk(n.getAttribute('href') || '')) f.href = n.getAttribute('href');
            inlineRuns(n, f, out);
        });
        return out;
    }

    // Runs with markers → list of lines (each a tidy list of runs).
    function lines(runs) {
        var outLines = [[]];
        runs.forEach(function (r) {
            if (r.br) { outLines.push([]); return; }
            var line = outLines[outLines.length - 1];
            var prev = line[line.length - 1];
            if (prev && !!prev.b === !!r.b && !!prev.i === !!r.i && (prev.href || '') === (r.href || '')) {
                prev.t += r.t;
            } else {
                line.push(r);
            }
        });
        return outLines.map(function (line) {
            if (line.length) {
                line[0].t = line[0].t.replace(/^ +/, '');
                line[line.length - 1].t = line[line.length - 1].t.replace(/ +$/, '');
            }
            return line.filter(function (r) { return r.t !== ''; });
        }).filter(function (line) { return line.length; });
    }

    function domToContent(root) {
        var out = [];
        var loose = [];
        function flush() {
            lines(loose).forEach(function (l) { out.push({ k: 'p', r: l }); });
            loose = [];
        }
        // Browsers nest freely while editing (a list made inside a <p>, a <div>
        // per line): a block that holds other blocks is walked as a container.
        function walk(container) {
            Array.prototype.forEach.call(container.childNodes, function (n) {
                var tag = n.nodeType === 1 ? n.tagName.toLowerCase() : '';
                if (tag === 'ul' || tag === 'ol') {
                    flush();
                    var items = [];
                    Array.prototype.forEach.call(n.children, function (li) {
                        var l = lines(inlineRuns(li, {}, []));
                        if (l.length) items.push(l.reduce(function (a, b) { return a.concat([{ t: ' ' }], b); }));
                    });
                    if (items.length) out.push({ k: tag, items: items });
                } else if (/^(p|div|h[1-6]|blockquote)$/.test(tag)) {
                    flush();
                    if (n.querySelector('ul, ol, p, div')) {
                        walk(n);
                        flush();
                    } else {
                        lines(inlineRuns(n, {}, [])).forEach(function (l) { out.push({ k: 'p', r: l }); });
                    }
                } else if (n.nodeType === 1 || n.nodeType === 3) {
                    inlineRuns({ childNodes: [n] }, {}, loose);
                }
            });
        }
        walk(root);
        flush();
        return out.length ? out : [{ k: 'p', r: [{ t: '' }] }];
    }

    /* ------------------------------------------------------------------ *
     * The editor
     * ------------------------------------------------------------------ */

    function Editor(root, opts) {
        this.root = root;
        this.opts = opts || {};
        this.id = 'eml-' + (++seq);
        this.doc = this.normalise(this.opts.doc);
        this.selected = null;
        this.dirty = false;
        this.range = null;
        this.build();
        this.offerRestore();
    }

    Editor.prototype.normalise = function (doc) {
        doc = doc && typeof doc === 'object' ? clone(doc) : {};
        var blocks = Array.isArray(doc.blocks) ? doc.blocks.filter(function (b) { return b && KINDS[b.type] && b.type !== 'footer'; }) : [];
        blocks.forEach(function (b) { b._id = 'b' + (++seq); });
        return { v: 1, subject: doc.subject || '', preheader: doc.preheader || '', blocks: blocks };
    };

    /** The document as the server expects it (footer last, no editor ids). */
    Editor.prototype.getDoc = function () {
        return {
            v: 1, subject: this.doc.subject, preheader: this.doc.preheader,
            blocks: this.doc.blocks.map(function (b) { var c = clone(b); delete c._id; return c; }).concat([{ type: 'footer' }])
        };
    };

    Editor.prototype.setDoc = function (doc) {
        this.doc = this.normalise(doc);
        this.selected = null;
        this.root.querySelector('[data-eml-subject]').value = this.doc.subject;
        this.root.querySelector('[data-eml-preheader]').value = this.doc.preheader;
        this.renderCanvas();
        this.renderPanel();
        this.changed();
    };

    Editor.prototype.build = function () {
        var id = this.id;
        var self = this;
        this.root.innerHTML =
            '<div class="ui-stack" data-eml>' +
            '<div data-eml-restore hidden></div>' +
            '<div data-eml-errors hidden></div>' +
            '<div class="ui-form-grid">' +
            '<div class="ui-field"><label class="ui-label" for="' + id + '-subject">Subject<span class="ui-label__req" aria-hidden="true">*</span></label>' +
            '<input class="ui-input" id="' + id + '-subject" maxlength="150" required data-eml-subject></div>' +
            '<div class="ui-field"><label class="ui-label" for="' + id + '-pre">Preview text <span class="ui-label__opt">(optional)</span></label>' +
            '<input class="ui-input" id="' + id + '-pre" maxlength="150" data-eml-preheader></div>' +
            '</div>' +
            '<div class="flex flex-wrap items-center justify-between gap-2">' +
            '<div class="ui-segmented" role="group" aria-label="Mode">' +
            '<button type="button" class="ui-segmented__item" aria-pressed="true" data-eml-mode="edit">' + HOD.icon('pencil', 'ui-icon--sm') + 'Edit</button>' +
            '<button type="button" class="ui-segmented__item" aria-pressed="false" data-eml-mode="preview">' + HOD.icon('eye', 'ui-icon--sm') + 'Preview</button>' +
            '</div>' +
            '<div class="flex flex-wrap gap-2">' +
            '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-eml-templates>' + HOD.icon('folder') + '<span>Templates</span></button>' +
            '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-eml-save-template>' + HOD.icon('copy') + '<span>Save as template</span></button>' +
            '</div></div>' +
            '<div data-eml-edit class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">' +
            '<div class="ui-stack ui-stack--sm"><div class="ui-stack ui-stack--sm" role="list" aria-label="Blocks" data-eml-canvas></div>' +
            '<div class="relative"><button type="button" class="ui-btn ui-btn--secondary ui-btn--block" aria-haspopup="true" aria-expanded="false" data-eml-add>' + HOD.icon('plus') + '<span>Add block</span></button>' +
            '<div class="absolute inset-x-0 z-10 mt-1 grid grid-cols-2 gap-1 rounded-ui-lg border border-line bg-surface p-2 shadow-lg sm:grid-cols-3" role="menu" data-eml-palette hidden>' +
            ADDABLE.map(function (k) {
                return '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm justify-start" role="menuitem" data-eml-new="' + k + '">' + HOD.icon(KINDS[k].icon) + '<span>' + KINDS[k].label + '</span></button>';
            }).join('') + '</div></div>' +
            '<div class="flex items-center gap-2 rounded-ui-lg border border-dashed border-line px-3 py-2 ui-meta">' + HOD.icon('lock-keyhole', 'ui-icon--sm') +
            '<span>Footer: church details, why they got it and Unsubscribe. Added for you.</span></div></div>' +
            '<aside class="rounded-ui-lg border border-line bg-surface-subtle p-4 lg:sticky lg:top-20 lg:self-start" aria-label="Block settings" data-eml-panel></aside>' +
            '</div>' +
            '<div data-eml-preview hidden><div class="mb-2 flex justify-end"><div class="ui-segmented" role="group" aria-label="Width">' +
            '<button type="button" class="ui-segmented__item" aria-pressed="false" data-eml-width="375">' + HOD.icon('smartphone', 'ui-icon--sm') + 'Phone</button>' +
            '<button type="button" class="ui-segmented__item" aria-pressed="true" data-eml-width="640">' + HOD.icon('layout-dashboard', 'ui-icon--sm') + 'Desktop</button>' +
            '</div></div><div class="overflow-hidden rounded-ui-lg border border-line bg-surface-sunken">' +
            '<iframe class="mx-auto block h-[70vh] w-full max-w-[640px] bg-white" title="Email preview" sandbox="" referrerpolicy="no-referrer" data-eml-frame></iframe></div></div>' +
            '<p class="sr-only" aria-live="polite" data-eml-live></p>' +
            '</div>';

        var subject = this.root.querySelector('[data-eml-subject]');
        var pre = this.root.querySelector('[data-eml-preheader]');
        subject.value = this.doc.subject;
        pre.value = this.doc.preheader;
        subject.addEventListener('input', function () { self.doc.subject = subject.value; self.changed(); });
        pre.addEventListener('input', function () { self.doc.preheader = pre.value; self.changed(); });

        this.root.addEventListener('click', function (e) { self.onClick(e); });
        this.root.addEventListener('keydown', function (e) { self.onKey(e); });
        this.root.addEventListener('input', function (e) { self.onInput(e); });
        this.root.addEventListener('change', function (e) { self.onChange(e); });
        this.root.addEventListener('paste', function (e) {
            if (!e.target.closest || !e.target.closest('[data-eml-rich]')) return;
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
            document.execCommand('insertText', false, text.replace(/\r\n?/g, '\n'));
        });
        this.root.addEventListener('drop', function (e) {
            if (e.target.closest && e.target.closest('[data-eml-rich]')) e.preventDefault();
        });
        this.wireDrag();
        document.addEventListener('selectionchange', function () {
            var sel = window.getSelection();
            if (sel && sel.rangeCount && sel.anchorNode && self.root.contains(sel.anchorNode) && sel.anchorNode.parentElement
                && sel.anchorNode.parentElement.closest('[data-eml-rich]')) {
                self.range = sel.getRangeAt(0).cloneRange();
            }
        });
        this.renderCanvas();
        this.renderPanel();
    };

    Editor.prototype.indexOf = function (bid) {
        for (var i = 0; i < this.doc.blocks.length; i++) if (this.doc.blocks[i]._id === bid) return i;
        return -1;
    };

    Editor.prototype.block = function (bid) {
        var i = this.indexOf(bid);
        return i >= 0 ? this.doc.blocks[i] : null;
    };

    /* ---------- canvas ---------- */

    Editor.prototype.renderCanvas = function () {
        var self = this;
        var n = this.doc.blocks.length;
        var canvas = this.root.querySelector('[data-eml-canvas]');
        canvas.innerHTML = n ? this.doc.blocks.map(function (b, i) { return self.cardHtml(b, i, n); }).join('')
            : '<p class="ui-meta rounded-ui-lg border border-dashed border-line p-6 text-center">No blocks yet. Add one below.</p>';
    };

    Editor.prototype.cardHtml = function (b, i, n) {
        var sel = this.selected === b._id;
        var kind = KINDS[b.type];
        var tool = function (act, icon, label, off) {
            return '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-eml-act="' + act + '" aria-label="' + label + '" data-tooltip="' + label + '"' + (off ? ' disabled' : '') + '>' + HOD.icon(icon) + '</button>';
        };
        return '<div class="rounded-ui-lg border bg-surface p-3 outline-none focus-visible:ring-2 focus-visible:ring-primary ' + (sel ? 'border-primary ring-2 ring-primary-soft' : 'border-line') + '"'
            + ' role="listitem" tabindex="0" data-eml-block="' + b._id + '" aria-label="' + esc(kind.label + ', block ' + (i + 1) + ' of ' + n) + '" aria-current="' + (sel ? 'true' : 'false') + '">'
            + '<div class="mb-2 flex items-center justify-between gap-2">'
            + '<span class="flex items-center gap-1.5 ui-meta"><span class="cursor-grab" data-eml-handle aria-hidden="true">' + HOD.icon('ellipsis-vertical', 'ui-icon--sm') + '</span>' + HOD.icon(kind.icon, 'ui-icon--sm') + esc(kind.label) + '</span>'
            + '<span class="flex gap-0.5">' + tool('up', 'chevron-up', 'Move up', i === 0) + tool('down', 'chevron-down', 'Move down', i === n - 1) + tool('remove', 'trash-2', 'Remove block') + '</span></div>'
            + '<div data-eml-body>' + this.bodyHtml(b, sel) + '</div></div>';
    };

    Editor.prototype.bodyHtml = function (b, sel) {
        var align = b.align === 'center' ? ' text-center' : '';
        switch (b.type) {
            case 'heading':
                return '<p class="' + (b.level === 2 ? 'text-lg' : 'text-2xl') + ' font-semibold text-ink' + align + '">' + textHtml(b.text || '') + '</p>';
            case 'text':
                return this.toolbarHtml() + '<div class="email-rich min-h-[3rem] rounded-ui border border-transparent px-1 text-ink outline-none focus:border-line [&_a]:text-primary [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-2 [&_ul]:list-disc [&_ul]:pl-6' + align + '"'
                    + ' contenteditable="true" role="textbox" aria-multiline="true" aria-label="Text" data-eml-rich>' + contentHtml(b.content) + '</div>';
            case 'image':
                return b.src
                    ? '<div class="' + (b.align === 'center' ? 'text-center' : '') + '"><img class="inline-block h-auto max-w-full rounded-ui" src="' + esc(b.src) + '" alt="' + esc(b.alt) + '" width="' + esc(Math.min(600, b.width || 600)) + '"></div>'
                    : '<p class="ui-meta rounded-ui border border-dashed border-line p-6 text-center">No picture yet. Upload one in the settings.</p>';
            case 'button':
                return '<div class="' + (b.align === 'center' ? 'text-center' : '') + '"><span class="ui-btn ' + (b.style === 'secondary' ? 'ui-btn--secondary' : 'ui-btn--brand') + ' pointer-events-none">' + esc(b.label) + '</span>'
                    + '<p class="ui-meta mt-1 truncate">' + esc(b.href) + '</p></div>';
            case 'scripture':
                return '<p class="border-l-4 border-brand-red pl-3 italic text-ink">' + esc(b.ref || 'Choose a reference') + ' <span class="ui-meta not-italic">(KJV, filled in when sent)</span></p>';
            case 'quote':
                return '<p class="border-l-4 border-line pl-3 italic text-ink">' + esc(b.text || 'Quote text') + (b.cite ? ' <span class="ui-meta not-italic">— ' + esc(b.cite) + '</span>' : '') + '</p>';
            case 'signature':
                return '<p class="font-medium text-ink">' + esc(b.name) + '</p><p class="ui-meta">' + esc(b.line) + '</p>';
            case 'divider':
                return '<hr class="ui-divider my-2">';
            case 'spacer':
                return '<div class="' + ({ s: 'h-2', m: 'h-4', l: 'h-8' }[b.size] || 'h-4') + ' rounded bg-surface-sunken"></div>';
            case 'event':
                return '<p class="ui-meta">Event card (event ' + esc(b.event_id) + '), filled in when sent.</p>';
        }
        return '';
    };

    Editor.prototype.toolbarHtml = function () {
        var btn = function (cmd, icon, label, text) {
            return '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm' + (text ? '' : ' ui-btn--icon') + '" data-eml-cmd="' + cmd + '" aria-label="' + label + '" data-tooltip="' + label + '">'
                + (icon ? HOD.icon(icon) : '') + (text ? '<span class="font-semibold">' + text + '</span>' : '') + '</button>';
        };
        return '<div class="mb-2 flex flex-wrap items-center gap-1 rounded-ui border border-line bg-surface-subtle p-1" role="toolbar" aria-label="Text formatting" data-eml-toolbar>'
            + btn('bold', '', 'Bold (Ctrl+B)', 'B') + btn('italic', '', 'Italic (Ctrl+I)', 'I') + btn('link', 'external-link', 'Link (Ctrl+K)')
            + btn('insertUnorderedList', 'list-filter', 'Bulleted list') + btn('insertOrderedList', 'clipboard-list', 'Numbered list')
            + '<label class="sr-only" for="' + this.id + '-merge">Insert a merge field</label>'
            + '<select class="ui-select ui-select--sm ml-auto w-auto" id="' + this.id + '-merge" data-eml-merge><option value="">Insert field…</option>'
            + FIELDS.map(function (f) { return '<option value="' + f[0] + '">' + esc(f[1]) + '</option>'; }).join('') + '</select></div>';
    };

    Editor.prototype.refreshCard = function (bid) {
        var i = this.indexOf(bid);
        var card = this.root.querySelector('[data-eml-block="' + bid + '"]');
        if (i < 0 || !card) return;
        var holder = document.createElement('div');
        holder.innerHTML = this.cardHtml(this.doc.blocks[i], i, this.doc.blocks.length);
        card.replaceWith(holder.firstChild);
    };

    /* ---------- settings panel ---------- */

    Editor.prototype.field = function (key, label, value, o) {
        o = o || {};
        var fid = this.id + '-f-' + key;
        var attrs = ' id="' + fid + '" data-eml-field="' + key + '"' + (o.max ? ' maxlength="' + o.max + '"' : '') + (o.required ? ' required' : '');
        var control;
        if (o.options) {
            control = '<select class="ui-select"' + attrs + '>' + o.options.map(function (op) {
                return '<option value="' + esc(op[0]) + '"' + (String(op[0]) === String(value) ? ' selected' : '') + '>' + esc(op[1]) + '</option>';
            }).join('') + '</select>';
        } else if (o.multiline) {
            control = '<textarea class="ui-textarea" rows="4"' + attrs + '>' + esc(value) + '</textarea>';
        } else {
            control = '<input class="ui-input" type="' + (o.type || 'text') + '"' + attrs + ' value="' + esc(value) + '"' + (o.inputmode ? ' inputmode="' + o.inputmode + '"' : '') + '>';
        }
        return '<div class="ui-field"><label class="ui-label" for="' + fid + '">' + esc(label)
            + (o.required ? '<span class="ui-label__req" aria-hidden="true">*</span>' : '') + '</label>' + control
            + (o.hint ? '<p class="ui-hint">' + esc(o.hint) + '</p>' : '') + '</div>';
    };

    Editor.prototype.renderPanel = function () {
        var panel = this.root.querySelector('[data-eml-panel]');
        var b = this.selected ? this.block(this.selected) : null;
        if (!b) {
            panel.innerHTML = '<p class="font-medium text-ink">Block settings</p><p class="ui-meta mt-1">Choose a block to change it.</p>'
                + '<p class="ui-meta mt-3">Fields like ' + chipHtml('first_name') + ' are filled in for each person.</p>';
            return;
        }
        var aligns = [['left', 'Left'], ['center', 'Centre']];
        var html = '<p class="mb-3 font-medium text-ink">' + esc(KINDS[b.type].label) + '</p><div class="ui-form">';
        switch (b.type) {
            case 'heading':
                html += this.field('text', 'Text', b.text, { max: 200, required: true, hint: 'Fields work here too, like {{first_name}}.' })
                    + this.field('level', 'Size', b.level, { options: [[1, 'Large'], [2, 'Small']] })
                    + this.field('align', 'Align', b.align, { options: aligns });
                break;
            case 'text':
                html += '<p class="ui-meta">Type in the block. Bold, italic, links, lists and fields are in its toolbar.</p>'
                    + this.field('align', 'Align', b.align, { options: aligns });
                break;
            case 'image':
                html += '<div class="ui-field"><span class="ui-label">Picture</span>'
                    + '<label class="ui-btn ui-btn--secondary cursor-pointer" for="' + this.id + '-upload">' + HOD.icon('upload') + '<span>' + (b.src ? 'Replace' : 'Upload') + '</span></label>'
                    + '<input class="sr-only" type="file" id="' + this.id + '-upload" accept="image/png, image/jpeg, image/gif, image/webp" data-eml-upload>'
                    + '<p class="ui-hint">JPG, PNG, GIF or WebP, 5 MB at most.</p></div>'
                    + this.field('alt', 'Description', b.alt, { max: 200, hint: 'Read aloud, and shown when pictures are off.' })
                    + this.field('href', 'Link (optional)', b.href || '', { max: 500, type: 'url', inputmode: 'url' })
                    + this.field('width', 'Width (px)', b.width, { type: 'number', inputmode: 'numeric' })
                    + this.field('align', 'Align', b.align, { options: aligns });
                break;
            case 'button':
                html += this.field('label', 'Text', b.label, { max: 60, required: true })
                    + this.field('href', 'Link', b.href, { max: 500, required: true, type: 'url', inputmode: 'url', hint: 'https://, mailto: or tel:' })
                    + this.field('style', 'Style', b.style, { options: [['primary', 'Red'], ['secondary', 'Outline']] })
                    + this.field('align', 'Align', b.align, { options: aligns });
                break;
            case 'scripture':
                html += this.field('ref', 'Reference', b.ref, { max: 60, required: true, hint: 'Like John 3:16 or Psalm 23:1-3. The KJV words are added when it is sent.' });
                break;
            case 'quote':
                html += this.field('text', 'Quote', b.text, { max: 600, required: true, multiline: true })
                    + this.field('cite', 'Who said it (optional)', b.cite, { max: 100 });
                break;
            case 'signature':
                html += this.field('name', 'Name', b.name, { max: 80, required: true })
                    + this.field('line', 'Line under it', b.line, { max: 120 });
                break;
            case 'spacer':
                html += this.field('size', 'Height', b.size, { options: [['s', 'Small'], ['m', 'Medium'], ['l', 'Large']] });
                break;
            case 'event':
                html += this.field('event_id', 'Event number', b.event_id, { type: 'number', inputmode: 'numeric' });
                break;
            default:
                html += '<p class="ui-meta">Nothing to set.</p>';
        }
        panel.innerHTML = html + '</div>';
    };

    /* ---------- changes ---------- */

    // Selection only restyles the cards (never re-renders them): a click into
    // a text block must keep its caret, and nothing may jump.
    Editor.prototype.markSelected = function () {
        var self = this;
        this.root.querySelectorAll('[data-eml-block]').forEach(function (card) {
            var on = card.getAttribute('data-eml-block') === self.selected;
            card.classList.toggle('border-primary', on);
            card.classList.toggle('ring-2', on);
            card.classList.toggle('ring-primary-soft', on);
            card.classList.toggle('border-line', !on);
            card.setAttribute('aria-current', on ? 'true' : 'false');
        });
    };

    Editor.prototype.select = function (bid, focusCard) {
        if (this.selected !== bid) {
            this.selected = bid;
            this.markSelected();
            this.renderPanel();
        }
        if (focusCard && bid) {
            var card = this.root.querySelector('[data-eml-block="' + bid + '"]');
            if (card) card.focus();
        }
    };

    Editor.prototype.move = function (bid, delta) {
        var i = this.indexOf(bid);
        var j = i + delta;
        if (i < 0 || j < 0 || j >= this.doc.blocks.length) return;
        var b = this.doc.blocks.splice(i, 1)[0];
        this.doc.blocks.splice(j, 0, b);
        this.renderCanvas();
        this.select(bid, true);
        this.say('Moved to position ' + (j + 1) + ' of ' + this.doc.blocks.length + '.');
        this.changed();
    };

    Editor.prototype.moveTo = function (bid, target) {
        var i = this.indexOf(bid);
        if (i < 0 || target === i) return;
        var b = this.doc.blocks.splice(i, 1)[0];
        this.doc.blocks.splice(Math.max(0, Math.min(target, this.doc.blocks.length)), 0, b);
        this.renderCanvas();
        this.select(bid, true);
        this.say('Moved to position ' + (this.indexOf(bid) + 1) + ' of ' + this.doc.blocks.length + '.');
        this.changed();
    };

    Editor.prototype.remove = function (bid) {
        var i = this.indexOf(bid);
        if (i < 0) return;
        this.doc.blocks.splice(i, 1);
        this.selected = null;
        this.renderCanvas();
        this.renderPanel();
        var next = this.doc.blocks[Math.min(i, this.doc.blocks.length - 1)];
        if (next) this.select(next._id, true);
        this.say('Block removed.');
        this.changed();
    };

    Editor.prototype.add = function (type) {
        if (this.doc.blocks.length >= MAX_BLOCKS) {
            HOD.toast('An email can have at most ' + MAX_BLOCKS + ' blocks.', 'warning');
            return;
        }
        var b = newBlock(type);
        b._id = 'b' + (++seq);
        var at = this.selected ? this.indexOf(this.selected) + 1 : this.doc.blocks.length;
        this.doc.blocks.splice(at, 0, b);
        this.renderCanvas();
        this.select(b._id, true);
        this.say(KINDS[type].label + ' added at position ' + (at + 1) + '.');
        this.changed();
    };

    Editor.prototype.say = function (text) {
        var live = this.root.querySelector('[data-eml-live]');
        live.textContent = '';
        window.setTimeout(function () { live.textContent = text; }, 30);
    };

    Editor.prototype.changed = function () {
        this.dirty = true;
        var self = this;
        if (!this.opts.storageKey) return;
        window.clearTimeout(this.saveTimer);
        this.saveTimer = window.setTimeout(function () {
            try { window.localStorage.setItem(self.opts.storageKey, JSON.stringify({ at: new Date().toISOString(), doc: self.getDoc() })); } catch (e) { /* private mode: no autosave */ }
        }, 2000);
    };

    Editor.prototype.forget = function () {
        this.dirty = false;
        window.clearTimeout(this.saveTimer);
        if (!this.opts.storageKey) return;
        try { window.localStorage.removeItem(this.opts.storageKey); } catch (e) { /* nothing stored */ }
    };

    Editor.prototype.offerRestore = function () {
        if (!this.opts.storageKey) return;
        var saved = null;
        try { saved = JSON.parse(window.localStorage.getItem(this.opts.storageKey) || 'null'); } catch (e) { saved = null; }
        if (!saved || !saved.doc || (this.opts.savedAt && saved.at < this.opts.savedAt)) return;
        if (JSON.stringify(saved.doc) === JSON.stringify(this.getDoc())) return;
        var box = this.root.querySelector('[data-eml-restore]');
        var when = new Date(saved.at);
        box.innerHTML = '<div class="ui-banner ui-banner--info" role="status">' + HOD.icon('history') + '<div class="ui-banner__body">'
            + '<p class="ui-banner__title">Unsaved changes found</p><p class="ui-banner__text">From ' + esc(when.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })) + ' on this device.</p>'
            + '<div class="ui-banner__actions"><button type="button" class="ui-btn ui-btn--sm ui-btn--primary" data-eml-restore-yes>Restore</button>'
            + '<button type="button" class="ui-btn ui-btn--sm ui-btn--ghost" data-eml-restore-no>Discard</button></div></div></div>';
        box.hidden = false;
        this.pending = saved.doc;
    };

    Editor.prototype.showErrors = function (errors) {
        var box = this.root.querySelector('[data-eml-errors]');
        if (!errors || !errors.length) { box.hidden = true; box.innerHTML = ''; return; }
        box.innerHTML = '<div class="ui-banner ui-banner--danger" role="alert">' + HOD.icon('circle-alert') + '<div class="ui-banner__body">'
            + '<p class="ui-banner__title">Fix these first</p><ul class="ui-banner__text list-disc pl-5">'
            + errors.slice(0, 8).map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div></div>';
        box.hidden = false;
        box.scrollIntoView({ block: 'nearest' });
    };

    /** Validate on the server, then hand the clean document to onSave. */
    Editor.prototype.save = function (button) {
        var self = this;
        HOD.busy(button, true);
        return HOD.api(API, { action: 'check', json: true, data: { doc: this.getDoc() } }).then(function (res) {
            if (!res.ok) {
                self.showErrors(res.errors);
                return false;
            }
            self.showErrors([]);
            return Promise.resolve(self.opts.onSave ? self.opts.onSave(res.doc) : true).then(function (ok) {
                if (ok !== false) self.forget();
                return ok;
            });
        }).catch(function () { return false; }).finally(function () { HOD.busy(button, false); });
    };

    /* ---------- events ---------- */

    Editor.prototype.onClick = function (e) {
        var t = e.target;
        var self = this;
        var mode = t.closest('[data-eml-mode]');
        if (mode) { this.setMode(mode.getAttribute('data-eml-mode')); return; }
        var width = t.closest('[data-eml-width]');
        if (width) {
            var narrow = width.getAttribute('data-eml-width') === '375';
            var frame = this.root.querySelector('[data-eml-frame]');
            frame.classList.toggle('max-w-[375px]', narrow);
            frame.classList.toggle('max-w-[640px]', !narrow);
            this.root.querySelectorAll('[data-eml-width]').forEach(function (b) { b.setAttribute('aria-pressed', b === width ? 'true' : 'false'); });
            return;
        }
        var add = t.closest('[data-eml-add]');
        var palette = this.root.querySelector('[data-eml-palette]');
        if (add) {
            palette.hidden = !palette.hidden;
            add.setAttribute('aria-expanded', palette.hidden ? 'false' : 'true');
            if (!palette.hidden) palette.querySelector('button').focus();
            return;
        }
        var nb = t.closest('[data-eml-new]');
        if (nb) {
            palette.hidden = true;
            this.root.querySelector('[data-eml-add]').setAttribute('aria-expanded', 'false');
            this.add(nb.getAttribute('data-eml-new'));
            return;
        }
        if (t.closest('[data-eml-restore-yes]')) { this.setDoc(this.pending); this.root.querySelector('[data-eml-restore]').hidden = true; return; }
        if (t.closest('[data-eml-restore-no]')) { this.forget(); this.root.querySelector('[data-eml-restore]').hidden = true; return; }
        if (t.closest('[data-eml-templates]')) { this.pickTemplate(); return; }
        if (t.closest('[data-eml-save-template]')) { this.saveTemplate(); return; }
        var cmd = t.closest('[data-eml-cmd]');
        if (cmd) { this.format(cmd.getAttribute('data-eml-cmd')); return; }
        var act = t.closest('[data-eml-act]');
        var card = t.closest('[data-eml-block]');
        if (act && card) {
            var bid = card.getAttribute('data-eml-block');
            var what = act.getAttribute('data-eml-act');
            if (what === 'up') this.move(bid, -1);
            else if (what === 'down') this.move(bid, 1);
            else if (what === 'remove') HOD.confirm({ title: 'Remove this block?', confirmLabel: 'Remove', tone: 'danger' }).then(function (yes) { if (yes) self.remove(bid); });
            return;
        }
        if (card) this.select(card.getAttribute('data-eml-block'), false);
    };

    Editor.prototype.onKey = function (e) {
        var rich = e.target.closest && e.target.closest('[data-eml-rich]');
        if (rich && (e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            this.format('link');
            return;
        }
        var card = e.target.matches && e.target.matches('[data-eml-block]') ? e.target : null;
        if (!card) return;
        var bid = card.getAttribute('data-eml-block');
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            this.move(bid, e.key === 'ArrowUp' ? -1 : 1);
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.select(bid, false);
            var first = this.root.querySelector('[data-eml-block="' + bid + '"] [data-eml-rich]') || this.root.querySelector('[data-eml-panel] input, [data-eml-panel] select, [data-eml-panel] textarea, [data-eml-panel] label.ui-btn');
            if (first) first.focus();
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var sib = e.key === 'ArrowDown' ? card.nextElementSibling : card.previousElementSibling;
            if (sib) sib.focus();
        }
    };

    Editor.prototype.onInput = function (e) {
        var rich = e.target.closest && e.target.closest('[data-eml-rich]');
        if (rich) {
            var card = rich.closest('[data-eml-block]');
            var b = this.block(card.getAttribute('data-eml-block'));
            b.content = domToContent(rich);
            this.changed();
            return;
        }
        var f = e.target.getAttribute && e.target.getAttribute('data-eml-field');
        if (f && e.target.tagName !== 'SELECT') this.setField(f, e.target.value);
    };

    Editor.prototype.onChange = function (e) {
        var t = e.target;
        if (t.matches('[data-eml-merge]')) {
            var field = t.value;
            t.value = '';
            if (field) this.insertChip(field);
            return;
        }
        if (t.matches('[data-eml-upload]')) { this.upload(t); return; }
        var f = t.getAttribute('data-eml-field');
        if (f && t.tagName === 'SELECT') this.setField(f, t.value);
    };

    Editor.prototype.setField = function (key, value) {
        var b = this.selected ? this.block(this.selected) : null;
        if (!b) return;
        if (key === 'level' || key === 'width' || key === 'event_id') value = parseInt(value, 10) || 0;
        if (key === 'href' && b.type === 'image') value = value.trim() || null;
        b[key] = value;
        var card = this.root.querySelector('[data-eml-block="' + b._id + '"] [data-eml-body]');
        if (card && b.type !== 'text') card.innerHTML = this.bodyHtml(b, true);
        if (card && b.type === 'text' && key === 'align') {
            card.querySelector('[data-eml-rich]').classList.toggle('text-center', value === 'center');
        }
        this.changed();
    };

    /* ---------- rich text commands ---------- */

    Editor.prototype.restoreRange = function () {
        if (!this.range) return false;
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(this.range);
        return true;
    };

    Editor.prototype.richOfRange = function () {
        if (!this.range) return null;
        var node = this.range.startContainer;
        var el = node.nodeType === 1 ? node : node.parentElement;
        return el ? el.closest('[data-eml-rich]') : null;
    };

    Editor.prototype.format = function (cmd) {
        var self = this;
        var rich = this.richOfRange();
        if (!rich) {
            HOD.toast('Click in the text first.', 'warning');
            return;
        }
        if (cmd === 'link') {
            var saved = this.range.cloneRange();
            HOD.prompt({ title: 'Add a link', label: 'Link', value: 'https://', confirmLabel: 'Add link', required: true }).then(function (url) {
                if (!url) return;
                url = url.trim();
                if (!linkOk(url)) {
                    HOD.toast('Links start with https://, mailto: or tel:.', 'warning');
                    return;
                }
                self.range = saved;
                rich.focus();
                self.restoreRange();
                if (saved.collapsed) {
                    var a = document.createElement('a');
                    a.setAttribute('href', url);
                    a.textContent = url;
                    self.insertNodes(rich, [a, document.createTextNode('\u00a0')]);
                    return;
                }
                document.execCommand('createLink', false, url);
                rich.dispatchEvent(new Event('input', { bubbles: true }));
            });
            return;
        }
        rich.focus();
        this.restoreRange();
        document.execCommand(cmd, false, null);
        rich.dispatchEvent(new Event('input', { bubbles: true }));
    };

    // Put a node at the caret (replacing any selection) and the caret after it.
    // execCommand('insertHTML') would strip the chip's attributes, so the DOM
    // is changed directly.
    Editor.prototype.insertNodes = function (rich, nodes) {
        rich.focus();
        this.restoreRange();
        var sel = window.getSelection();
        if (!sel.rangeCount || !rich.contains(sel.getRangeAt(0).startContainer)) return;
        var range = sel.getRangeAt(0);
        range.deleteContents();
        for (var i = nodes.length - 1; i >= 0; i--) range.insertNode(nodes[i]);
        range.setStartAfter(nodes[nodes.length - 1]);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
        this.range = range.cloneRange();
        rich.dispatchEvent(new Event('input', { bubbles: true }));
    };

    Editor.prototype.insertChip = function (field) {
        var rich = this.richOfRange();
        if (!rich) {
            HOD.toast('Click in the text first.', 'warning');
            return;
        }
        var holder = document.createElement('span');
        holder.innerHTML = chipHtml(field);
        this.insertNodes(rich, [holder.firstChild, document.createTextNode('\u00a0')]);
    };

    /* ---------- pictures ---------- */

    Editor.prototype.upload = function (input) {
        var self = this;
        var file = input.files && input.files[0];
        var b = this.selected ? this.block(this.selected) : null;
        if (!file || !b) return;
        if (file.size > 5 * 1024 * 1024) {
            HOD.toast('That picture is too large (5 MB at most).', 'warning');
            input.value = '';
            return;
        }
        var form = new FormData();
        form.append('action', 'upload');
        form.append('picture', file);
        var label = input.previousElementSibling;
        HOD.busy(label, true);
        HOD.api(API, { data: form }).then(function (res) {
            b.src = res.src;
            if (!b.alt) b.alt = '';
            b.width = Math.min(600, res.width || 600);
            self.refreshCard(b._id);
            self.renderPanel();
            self.changed();
            HOD.toast('Picture added.', 'success');
        }).catch(function () {}).finally(function () { HOD.busy(label, false); input.value = ''; });
    };

    /* ---------- preview ---------- */

    Editor.prototype.setMode = function (mode) {
        var self = this;
        this.root.querySelectorAll('[data-eml-mode]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-eml-mode') === mode ? 'true' : 'false'); });
        this.root.querySelector('[data-eml-edit]').hidden = mode !== 'edit';
        this.root.querySelector('[data-eml-preview]').hidden = mode !== 'preview';
        if (mode !== 'preview') return;
        HOD.api(API, { action: 'draft', json: true, data: { doc: this.getDoc() } }).then(function (res) {
            self.showErrors(res.errors);
            self.root.querySelector('[data-eml-frame]').src = '/api/email_draft_preview.php?d=' + encodeURIComponent(res.token);
        }).catch(function () {});
    };

    /* ---------- templates ---------- */

    Editor.prototype.pickTemplate = function () {
        var self = this;
        HOD.api(API, { action: 'templates', json: true, data: {} }).then(function (res) {
            var list = res.templates || [];
            if (!list.length) {
                HOD.alert({ title: 'No templates yet', message: 'Use "Save as template" to keep an email for later.' });
                return;
            }
            var dialog = document.createElement('dialog');
            dialog.className = 'ui-dialog ui-dialog--sm';
            dialog.setAttribute('aria-labelledby', self.id + '-tpl-title');
            dialog.setAttribute('data-modal-ignore', '');
            dialog.innerHTML = '<div class="ui-dialog__header"><div class="ui-dialog__heading"><h2 class="ui-dialog__title" id="' + self.id + '-tpl-title">Templates</h2></div>'
                + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-dialog__close" data-dialog-close aria-label="Close">' + HOD.icon('x') + '</button></div>'
                + '<div class="ui-dialog__body"><ul class="ui-list rounded-ui-lg border border-line">' + list.map(function (t) {
                    return '<li class="ui-list__item flex items-center justify-between gap-2"><span class="min-w-0 truncate font-medium text-ink">' + esc(t.name) + '</span><span class="flex shrink-0 gap-1">'
                        + '<button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" data-tpl-use="' + t.id + '">Use</button>'
                        + '<button type="button" class="ui-btn ui-btn--sm ui-btn--danger-ghost ui-btn--icon" data-tpl-del="' + t.id + '" aria-label="Remove ' + esc(t.name) + '">' + HOD.icon('trash-2') + '</button></span></li>';
                }).join('') + '</ul></div>';
            document.body.appendChild(dialog);
            dialog.addEventListener('ui:dialog-close', function () { dialog.remove(); });
            dialog.addEventListener('click', function (e) {
                var use = e.target.closest('[data-tpl-use]');
                var del = e.target.closest('[data-tpl-del]');
                if (use) {
                    HOD.api(API, { action: 'template', json: true, data: { id: use.getAttribute('data-tpl-use') } }).then(function (r) {
                        HOD.dialog.close(dialog);
                        HOD.confirm({ title: 'Replace this email?', message: 'The template "' + r.template.name + '" replaces what is here.', confirmLabel: 'Replace' }).then(function (yes) {
                            if (yes) { self.setDoc(r.template.doc); HOD.toast('Template applied.', 'success'); }
                        });
                    }).catch(function () {});
                } else if (del) {
                    HOD.api(API, { action: 'template_archive', json: true, data: { id: del.getAttribute('data-tpl-del') } }).then(function (r) {
                        del.closest('li').remove();
                        HOD.toast(r.message || 'Template removed.', 'success');
                    }).catch(function () {});
                }
            });
            HOD.dialog.open(dialog);
        }).catch(function () {});
    };

    Editor.prototype.saveTemplate = function () {
        var self = this;
        HOD.prompt({ title: 'Save as template', label: 'Template name', value: this.doc.subject.slice(0, 120), confirmLabel: 'Save', required: true }).then(function (name) {
            if (!name) return;
            HOD.api(API, { action: 'template_save', json: true, data: { name: name, doc: self.getDoc() }, quiet: true }).then(function (res) {
                self.showErrors([]);
                HOD.toast(res.message || 'Template saved.', 'success');
            }).catch(function (err) {
                self.showErrors(err.response && err.response.errors ? err.response.errors : [err.message]);
            });
        });
    };

    /* ---------- dragging (the handle; Move up/down and Alt+arrows do the same) ---------- */

    Editor.prototype.wireDrag = function () {
        var self = this;
        var canvas = this.root.querySelector('[data-eml-canvas]');
        var dragging = null;
        canvas.addEventListener('pointerdown', function (e) {
            var handle = e.target.closest('[data-eml-handle]');
            var card = handle && handle.closest('[data-eml-block]');
            if (card) card.setAttribute('draggable', 'true');
        });
        canvas.addEventListener('dragstart', function (e) {
            var card = e.target.closest && e.target.closest('[data-eml-block]');
            if (!card || card.getAttribute('draggable') !== 'true') { e.preventDefault(); return; }
            dragging = card.getAttribute('data-eml-block');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });
        canvas.addEventListener('dragover', function (e) {
            if (dragging) e.preventDefault();
        });
        canvas.addEventListener('drop', function (e) {
            if (!dragging) return;
            e.preventDefault();
            var over = e.target.closest && e.target.closest('[data-eml-block]');
            var target = over ? self.indexOf(over.getAttribute('data-eml-block')) : self.doc.blocks.length - 1;
            if (over) {
                var box = over.getBoundingClientRect();
                if (e.clientY > box.top + box.height / 2 && target < self.indexOf(dragging)) target += 1;
                if (e.clientY < box.top + box.height / 2 && target > self.indexOf(dragging)) target -= 1;
            }
            self.moveTo(dragging, target);
            dragging = null;
        });
        canvas.addEventListener('dragend', function () {
            dragging = null;
            canvas.querySelectorAll('[draggable]').forEach(function (c) { c.removeAttribute('draggable'); });
        });
    };

    window.HODEmail = {
        mount: function (root, opts) { return new Editor(root, opts); },
        // For tests and the server's twin: the JSON tree of an editable element.
        toContent: domToContent,
        toHtml: contentHtml
    };
})();
