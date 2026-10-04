// /assets/se/js/dj/main.js
//
// The DJ screen (guide §13.12) — shell only.
//
// Karaoke is PR4. The page exists now because the crew route, the login
// bounce and the permission (`karaoke.queue`) were all built in PR1/PR3,
// and a DJ who opens the link tonight should get a clear "not yet" rather
// than a 404 that looks like the event is broken.
//
// When PR4 lands it replaces the body of render() with the real queue: the
// list comes from live.json's `karaoke` block and the controls from the
// live API's karaoke actions.

import { boot } from '@se/core/store.js';
import { call } from '@se/core/api.js';

const config = boot.value || {};

function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text != null) element.textContent = text;
    return element;
}

async function start() {
    const host = document.getElementById('se-app');
    if (!host) return;

    document.getElementById('se-crew-frame')?.remove();
    host.hidden = false;
    host.className = 'se-container se-stack';

    const panel = node('section', 'se-panel');
    panel.appendChild(node('h2', null, 'DJ · ' + (config.event?.title || '')));
    panel.appendChild(node('p', 'se-small se-muted',
        'The karaoke queue arrives with the games release. Until then the host console '
        + 'runs the room and singers are called from the desk list.'));
    host.appendChild(panel);

    // Confirm the crew session is real, so a DJ who is signed in as the
    // wrong person finds out now and not at nine o'clock tonight.
    if (!config.event?.public_id) return;

    try {
        const state = await call('live', 'desk_state', { event: config.event.public_id });
        const who = node('p', 'se-small', state.counts.checked_in_today + ' checked in so far.');
        panel.appendChild(who);
        if (state.test_mode) {
            host.prepend(node('p', 'se-test-ribbon', 'Test mode — nothing tonight counts'));
        }
    } catch (error) {
        panel.appendChild(node('p', 'se-small se-muted',
            'Signed in, but without desk access — that is fine for tonight.'));
    }
}

start();
