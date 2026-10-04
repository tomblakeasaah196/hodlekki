// /tests/special_events/js/program.test.mjs
//
// Programme-tab regression coverage: current.value is the event payload, not
// {event: payload}; every import remains a mutable review until the explicit
// apply request is made.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';

register('./vendor_loader.mjs', import.meta.url);

globalThis.document ??= {
    getElementById: () => null,
    addEventListener: () => {},
    visibilityState: 'visible',
};
globalThis.fetch ??= async () => { throw new Error('no network in tests'); };

const {
    programEventFromCurrent,
    loadProgram,
    programPanelState,
    programImportRequest,
    prepareProgramReview,
    updateProgramReviewRow,
    reviewStartForDay,
    uploadAndImportProgramSource,
    programApplyRequest,
} = await import('@se/studio/tabs/program.js');

const event = { id: 17, title: 'Chara', capabilities: ['event.edit'] };
const days = [
    { day_id: 71, day_date: '2026-10-24', label: 'Saturday' },
    { day_id: 72, day_date: '2026-10-25', label: 'Sunday' },
];

test('Programme mounts from current.value when it is the full event payload', () => {
    assert.equal(programEventFromCurrent(event), event);
    assert.equal(programEventFromCurrent({ event }), null, 'a wrapper is not the Studio state shape');
});

test('Programme loading calls program_list for the current event', async () => {
    const calls = [];
    const response = await loadProgram(async (action, payload) => {
        calls.push({ action, payload });
        return { program: { days: [] }, time_mode: 'approximate' };
    }, event.id);

    assert.deepEqual(calls, [{ action: 'program_list', payload: { id: 17 } }]);
    assert.deepEqual(response.program.days, []);
});

test('loading, API failure and an empty programme each have a visible panel state', () => {
    assert.equal(programPanelState(event, true, '', null, []), 'loading');
    assert.equal(programPanelState(event, false, 'offline', null, []), 'error');
    assert.equal(programPanelState(event, false, '', { days: [] }, []), 'empty');
    assert.equal(programPanelState(event, false, '', { days: [] }, [{ id: 1 }]), 'builder');
});

test('Retry repeats program_list after a loading failure', async () => {
    let attempts = 0;
    const retryable = async () => {
        attempts += 1;
        if (attempts === 1) throw new Error('offline');
        return { program: { days: [] }, time_mode: 'approximate' };
    };

    await assert.rejects(loadProgram(retryable, event.id), /offline/);
    const result = await loadProgram(retryable, event.id);
    assert.equal(attempts, 2);
    assert.deepEqual(result.program, { days: [] });
});

test('pasted text starts the same review-only programme import flow', () => {
    assert.deepEqual(
        programImportRequest(event.id, { text: '4:30 Welcome' }),
        { id: 17, text: '4:30 Welcome' },
    );
    assert.deepEqual(
        programImportRequest(event.id, { assetId: 902 }),
        { id: 17, asset_id: 902 },
    );
});

test('a screenshot or PDF upload passes its returned asset id to program_import', async () => {
    const calls = [];
    const file = { name: 'programme.png', type: 'image/png' };
    const result = await uploadAndImportProgramSource(
        async (action, payload, files) => {
            calls.push({ action, payload, files });
            return { asset: { id: 902 }, assets: [{ id: 902, role: 'program_source' }] };
        },
        async (action, payload) => {
            calls.push({ action, payload });
            return { job_id: 11, items: [], warnings: [] };
        },
        event,
        file,
        { title: 'Run sheet', altText: 'Programme screenshot' },
    );

    assert.equal(result.assetId, 902);
    assert.deepEqual(calls[0], {
        action: 'asset_upload',
        payload: {
            id: 17,
            role: 'program_source',
            title: 'Run sheet',
            alt_text: 'Programme screenshot',
        },
        files: { file },
    });
    assert.deepEqual(calls[1], { action: 'program_import', payload: { id: 17, asset_id: 902 } });
});

test('every extracted row can be edited before apply, including its event day', () => {
    let review = prepareProgramReview({
        job_id: 11,
        items: [{ title: 'Photo Booth', kind: 'other', day_id: 71, start_time: '2026-10-24T15:30:00+01:00', duration_min: 60 }],
    }, days, 71);

    review = updateProgramReviewRow(review, 0, {
        title: 'Photo Booth and games',
        kind: 'game',
        duration_min: 55,
        day_id: 72,
        start_time: reviewStartForDay(review.items[0], 72, days),
    });

    assert.deepEqual(review.items[0], {
        title: 'Photo Booth and games',
        kind: 'game',
        day_id: 72,
        start_time: '2026-10-25 15:30:00',
        duration_min: 55,
    });
});

test('Apply preserves the producer-selected replace mode and day', () => {
    const review = { job_id: 11, items: [{ title: 'Welcome', day_id: 72, kind: 'welcome', duration_min: 5 }] };
    assert.deepEqual(programApplyRequest(event.id, review, 'replace', 72), {
        id: 17,
        job_id: 11,
        items: review.items,
        mode: 'replace',
        day_id: 72,
    });
});
