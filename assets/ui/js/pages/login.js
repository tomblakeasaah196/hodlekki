// /assets/ui/js/pages/login.js
// Sign-in (auth/login.php): the rotating scripture beside the form, and the
// form itself, posted to /api/auth_api.php exactly as before (email,
// password, remember). The API answers with a message and, on success,
// where to go next; when it is returning the member to the page they asked
// for, the #fragment the browser carried here is put back (it never reaches
// the server).
(function () {
    'use strict';

    var HOD = window.HOD;

    var QUOTES = [
        ['“Let all that you do be done in love.”', '1 Corinthians 16:14'],
        ['“For God is not unjust so as to overlook your work and the love that you have shown for his name in serving the saints...”', 'Hebrews 6:10'],
        ['“Whatever you do, work heartily, as for the Lord and not for men.”', 'Colossians 3:23'],
        ['“And let us not grow weary of doing good, for in due season we will reap, if we do not give up.”', 'Galatians 6:9'],
        ['“Well done, good and faithful servant. You have been faithful over a little; I will set you over much.”', 'Matthew 25:21'],
        ['“Do not be slothful in zeal, be fervent in spirit, serve the Lord.”', 'Romans 12:11'],
        ['“Whoever brings blessing will be enriched, and one who waters will himself be watered.”', 'Proverbs 11:25'],
        ['“As each has received a gift, use it to serve one another, as good stewards of God’s varied grace.”', '1 Peter 4:10'],
        ['“But as for me and my house, we will serve the Lord.”', 'Joshua 24:15'],
        ['“Serve the Lord with gladness! Come into his presence with singing!”', 'Psalm 100:2'],
        ['“Rendering service with a good will as to the Lord and not to man.”', 'Ephesians 6:7'],
        ['“For even the Son of Man came not to be served but to serve, and to give his life as a ransom for many.”', 'Mark 10:45'],
        ['“If anyone serves me, he must follow me; and where I am, there will my servant be also.”', 'John 12:26'],
        ['“Only fear the Lord and serve him faithfully with all your heart. For consider what great things he has done for you.”', '1 Samuel 12:24'],
        ['“Present your bodies as a living sacrifice, holy and acceptable to God, which is your spiritual worship.”', 'Romans 12:1'],
        ['“But you, take courage! Do not let your hands be weak, for your work shall be rewarded.”', '2 Chronicles 15:7'],
        ['“Do not neglect to do good and to share what you have, for such sacrifices are pleasing to God.”', 'Hebrews 13:16'],
        ['“Remembering before our God and Father your work of faith and labor of love...”', '1 Thessalonians 1:3'],
        ['“Therefore, my beloved brothers, be steadfast, immovable, always abounding in the work of the Lord...”', '1 Corinthians 15:58'],
        ['“For God has not given us a spirit of fear, but of power and of love and of a sound mind.”', '2 Timothy 1:7']
    ];
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function rotateQuotes() {
        var at = 0;
        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-login-quote]'));
        if (!boxes.length) return;
        setInterval(function () {
            at = (at + 1) % QUOTES.length;
            boxes.forEach(function (box) { box.classList.add('opacity-0'); });
            setTimeout(function () {
                boxes.forEach(function (box) {
                    box.querySelector('[data-quote-text]').textContent = QUOTES[at][0];
                    box.querySelector('[data-quote-ref]').textContent = QUOTES[at][1];
                    box.classList.remove('opacity-0');
                });
            }, reduceMotion ? 0 : 700);
        }, 8000);
    }

    function setLabel(btn, text) {
        var spans = btn.querySelectorAll('span:not(.ui-btn__spinner)');
        if (spans.length) spans[spans.length - 1].textContent = text;
    }

    function showAlert(tone, title, text) {
        var box = document.getElementById('login-alert');
        if (!title && !text) { box.innerHTML = ''; return; }
        var icons = { success: 'circle-check', danger: 'circle-alert', info: 'info', warning: 'triangle-alert' };
        box.innerHTML = '<div class="ui-banner ui-banner--' + tone + '" role="' + (tone === 'danger' ? 'alert' : 'status') + '">'
            + HOD.icon(icons[tone] || 'info') + '<div class="ui-banner__body">'
            + (title ? '<p class="ui-banner__title">' + HOD.esc(title) + '</p>' : '')
            + (text ? '<p class="ui-banner__text">' + HOD.esc(text) + '</p>' : '')
            + '</div></div>';
    }

    HOD.actions.on('login.submit', function (form, event) {
        var btn = (event && event.submitter) || document.getElementById('login-submit');
        var data = {
            email: form.elements.email.value.trim(),
            password: form.elements.password.value
        };
        if (form.elements.remember.checked) data.remember = '1';

        showAlert();
        HOD.busy(btn, true);
        setLabel(btn, 'Authenticating…');

        HOD.api('/api/auth_api.php', { data: data, quiet: true }).then(function (res) {
            showAlert('success', null, res.message || 'Welcome back.');
            setLabel(btn, 'Success!');
            var destination = res.redirect || '/';
            if (res.returning && window.location.hash && destination.indexOf('#') === -1) {
                destination += window.location.hash;
            }
            setTimeout(function () { window.location.href = destination; }, 1000);
        }).catch(function (err) {
            var message = err.code === 'NETWORK' ? 'A network error occurred. Please try again.' : err.message;
            if (err.code === 'MOVED') showAlert('info', 'Use your personal email', message);
            else showAlert('danger', null, message);
            HOD.busy(btn, false);
            setLabel(btn, 'Authenticate');
            if (err.code === 'MOVED') form.elements.email.focus();
        });
    });

    function init() { rotateQuotes(); }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
