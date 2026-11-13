// /assets/ui/js/pages/our-sermons-manage.js
// Our sermons, Envision's manager (modules/our_sermons/manage.php): Paste link,
// save / publish / hide / delete a sermon, add, check and remove the RSS feed.
// The page is rendered on the server, so each change reloads it (or opens the
// sermon); the toast is carried across the reload.
(function () {
    'use strict';

    var API = '/api/our_sermons_api.php';
    var FLASH = 'hod-os-flash';

    function go(url, message) {
        try { if (message) window.sessionStorage.setItem(FLASH, message); } catch (e) { /* private mode: no toast after reload */ }
        var here = window.location;
        var target = url ? new URL(url, here.href) : null;
        if (target && target.pathname + target.search !== here.pathname + here.search) {
            here.href = target.href;
            return;
        }
        // The same page (perhaps another tab of it): a hash change alone would not reload it.
        if (target && target.hash) here.hash = target.hash;
        here.reload();
    }

    try {
        var waiting = window.sessionStorage.getItem(FLASH);
        if (waiting) {
            window.sessionStorage.removeItem(FLASH);
            HOD.toast(waiting, 'success');
        }
    } catch (e) { /* storage blocked */ }

    function call(action, data, button, form) {
        if (button) HOD.busy(button, true);
        if (form) HOD.form.clearErrors(form);
        return HOD.api(API, { action: action, data: data })
            .catch(function (err) {
                if (form && err && err.field) {
                    var errors = {};
                    errors[err.field] = err.message;
                    HOD.form.setErrors(form, errors);
                }
                throw err;
            })
            .finally(function () { if (button) HOD.busy(button, false); });
    }

    function editor() {
        return document.querySelector('form[data-action="os.save"]');
    }

    function manageUrl(id) {
        return '/modules/our_sermons/manage' + (id ? '?id=' + encodeURIComponent(id) : '');
    }

    // Paste link: the sermon fills itself in, then opens for checking.
    HOD.actions.on('os.addLink', function (form) {
        if (!HOD.form.validate(form)) return;
        var button = document.querySelector('button[form="os-link-form"]');
        call('add_link', HOD.form.values(form), button, form).then(function (res) {
            go(manageUrl(res.id), res.existing ? 'That sermon was already here.' : 'Filled in. Check it, then publish.');
        }).catch(function () {});
    });

    HOD.actions.on('os.save', function (form) {
        if (!HOD.form.validate(form)) return;
        var button = form.querySelector('button[type="submit"]');
        call('save', HOD.form.values(form), button, form).then(function () {
            go(null, 'Saved.');
        }).catch(function () {});
    });

    HOD.actions.on('os.publish', function (button) {
        var form = editor();
        if (!form || !HOD.form.validate(form)) return;
        var first = form.getAttribute('data-notified') !== '1';
        var ask = first
            ? HOD.confirm({ title: 'Publish this sermon?', message: 'Everyone is told it is up.', confirmLabel: 'Publish sermon' })
            : Promise.resolve(true);
        ask.then(function (yes) {
            if (!yes) return;
            call('publish', HOD.form.values(form), button, form).then(function (res) {
                go(null, res.notified > 0 ? 'Published. Everyone has been told.' : 'Published.');
            }).catch(function () {});
        });
    });

    HOD.actions.on('os.hide', function (button) {
        var form = editor();
        if (!form) return;
        HOD.confirm({ title: 'Hide this sermon?', message: 'Members stop seeing it. You can publish it again.', confirmLabel: 'Hide sermon' })
            .then(function (yes) {
                if (!yes) return;
                call('hide', { id: form.getAttribute('data-sermon') }, button).then(function () {
                    go(null, 'Hidden from members.');
                }).catch(function () {});
            });
    });

    HOD.actions.on('os.delete', function (button) {
        var form = editor();
        if (!form) return;
        HOD.confirm({ title: 'Delete this sermon?', message: 'It cannot be brought back.', confirmLabel: 'Delete sermon', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                call('delete', { id: form.getAttribute('data-sermon') }, button).then(function () {
                    go(manageUrl(null), 'Sermon deleted.');
                }).catch(function () {});
            });
    });

    function feedNews(res) {
        var parts = [];
        if (res.new) parts.push(res.new + (res.new === 1 ? ' new draft' : ' new drafts'));
        if (res.linked) parts.push(res.linked + ' completed');
        return parts.length ? parts.join(', ') + '.' : 'Nothing new.';
    }

    HOD.actions.on('os.feedAdd', function (form) {
        if (!HOD.form.validate(form)) return;
        var button = form.querySelector('button[type="submit"]');
        call('feed_add', HOD.form.values(form), button, form).then(function (res) {
            go(manageUrl(null) + '#tab=feed', 'Feed added. ' + feedNews(res));
        }).catch(function () {});
    });

    HOD.actions.on('os.feedCheck', function (button) {
        call('feed_check', { id: button.getAttribute('data-feed') }, button).then(function (res) {
            go(manageUrl(null) + '#tab=feed', 'Feed read. ' + feedNews(res));
        }).catch(function () {});
    });

    HOD.actions.on('os.feedRemove', function (button) {
        HOD.confirm({ title: 'Remove this feed?', message: 'Sermons it brought in stay.', confirmLabel: 'Remove feed', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                call('feed_remove', { id: button.getAttribute('data-feed') }, button).then(function () {
                    go(manageUrl(null) + '#tab=feed', 'Feed removed.');
                }).catch(function () {});
            });
    });
})();
