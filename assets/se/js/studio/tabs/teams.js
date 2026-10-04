// /assets/se/js/studio/tabs/teams.js
//
// Teams (guide §10.6, §13.13).
//
// Teams are defined by colour, because that is how the room refers to them:
// "Team Red", not "Team 3". So the main input is a list of hex codes pasted
// straight out of a design file, and everything else — the readable label,
// the contrast ring, the on-colour for text — is derived.
//
// Two things here are deliberately awkward:
//
//   * The team count locks once the first person has been assigned. Changing
//     it afterwards would renumber a room that has already been told its
//     colours, so the Studio refuses rather than offering a "are you sure?".
//   * The warnings never block. A low-contrast colour gets a ring and a
//     note; two similar colours get a note. The producer decides.

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextArea, TextInput } from '../ui.js';

function Swatch({ team }) {
    return html`
        <span class="inline-flex items-center gap-2">
            <span class=${'w-6 h-6 rounded-lg shrink-0 ' + (team.tokens.needs_ring ? 'ring-2 ring-gray-900/30' : '')}
                  style=${{ background: team.color_hex }} aria-hidden="true"></span>
            <span class="font-semibold text-gray-900">${team.name || team.color_label}</span>
            <span class="text-xs text-gray-400 font-mono">${team.color_hex}</span>
        </span>`;
}

function Warnings({ warnings, teams }) {
    const similar = warnings?.similar || [];
    const low = warnings?.low_contrast || [];
    if (!similar.length && !low.length) return null;

    return html`
        <div class="rounded-2xl bg-amber-50 border border-amber-200 p-4 space-y-2">
            <p class="text-sm font-semibold text-amber-900">Worth a look — none of this blocks you.</p>
            <ul class="text-sm text-amber-800 space-y-1 list-disc pl-5">
                ${similar.map((pair, i) => html`
                    <li key=${'s' + i}>
                        ${(teams[pair.a]?.color_label || pair.a)} and ${(teams[pair.b]?.color_label || pair.b)}
                        look alike on a projector. Consider pulling them further apart.
                    </li>`)}
                ${low.map((item, i) => html`
                    <li key=${'c' + i}>
                        ${(teams[item.index]?.color_label || item.index)} is low contrast on the stage background
                        (${Number(item.ratio).toFixed(2)}:1), so it gets a white ring on screen.
                    </li>`)}
            </ul>
        </div>`;
}

function Roster({ data, onMoved }) {
    const [moving, setMoving] = useState(null);
    const [reason, setReason] = useState('');
    const roster = data.roster || [];

    if (!roster.length) {
        return html`<${EmptyState} title="Nobody on a team yet"
            message="Teams fill up as people check in on the night. Nothing to do here before then." />`;
    }

    const byTeam = new Map(data.teams.map((t) => [t.id, []]));
    for (const person of roster) {
        if (byTeam.has(person.team_id)) byTeam.get(person.team_id).push(person);
    }

    return html`
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            ${data.teams.map((team) => html`
                <div class="rounded-2xl border border-gray-100 p-4" key=${team.id}>
                    <header class="flex items-center justify-between gap-2 mb-3">
                        <${Swatch} team=${team} />
                        <span class="text-sm font-bold text-gray-500">${team.counts.n}</span>
                    </header>
                    <p class="text-xs text-gray-400 mb-3">
                        ${team.counts.n_Female} female · ${team.counts.n_Male} male ·
                        ${team.counts.n_member} members · ${team.counts.n_guest} guests
                    </p>
                    <ul class="space-y-1">
                        ${(byTeam.get(team.id) || []).map((person) => html`
                            <li class="flex items-center justify-between gap-2 text-sm" key=${person.registration_id}>
                                <span class="text-gray-700">
                                    ${person.player_no ? html`<span class="font-mono text-gray-400">#${person.player_no}</span> ` : null}
                                    ${person.display_name}
                                    ${team.captain?.registration_id === person.registration_id
                                        ? html`<span class="ml-1 text-xs font-bold text-hodBlue">captain</span>` : null}
                                </span>
                                ${can('team.move') ? html`
                                    <button type="button" class="text-xs text-gray-400 hover:text-hodBlue"
                                        onClick=${() => { setMoving(person); setReason(''); }}>move</button>` : null}
                            </li>`)}
                    </ul>
                </div>`)}
        </div>

        ${moving ? html`
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-app-modal data-modal-ignore>
                <button type="button" aria-label="Cancel" class="absolute inset-0 bg-gray-900/40"
                        onClick=${() => setMoving(null)}></button>
                <div class="relative bg-white rounded-3xl shadow-2xl p-6 w-full max-w-md space-y-4">
                    <h3 class="font-display font-bold text-gray-900">Move ${moving.display_name}</h3>
                    <p class="text-sm text-gray-500">
                        Points already scored stay with the old team — only the person moves.
                    </p>
                    <${Field} label="Why?" name="reason" required
                        hint="A few words. It goes in the audit log so the night can be explained afterwards.">
                        <${TextInput} name="reason" value=${reason} onInput=${setReason} maxLength=${160} />
                    <//>
                    <div class="flex flex-wrap gap-2">
                        ${data.teams.filter((t) => t.id !== moving.team_id).map((team) => html`
                            <${Button} key=${team.id} variant="secondary" onClick=${async () => {
                                try {
                                    const next = await studio('team_move', {
                                        id: current.value.id,
                                        registration_id: moving.registration_id,
                                        team_id: team.id,
                                        reason,
                                    });
                                    onMoved(next);
                                    setMoving(null);
                                    toast('Moved.', 'success');
                                } catch (e) { toast(e.message, 'error'); }
                            }}>→ ${team.name || team.color_label}<//>`)}
                    </div>
                    <${Button} variant="ghost" onClick=${() => setMoving(null)}>Cancel<//>
                </div>
            </div>` : null}`;
}

export function TeamsTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [hexList, setHexList] = useState('');
    const [names, setNames] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        let alive = true;
        (async () => {
            try {
                const next = await studio('teams_get', { id: event.id });
                if (!alive) return;
                setData(next);
                setHexList((next.teams || []).map((t) => t.color_hex).join('\n'));
            } catch (e) {
                if (alive) setError(e.message);
            }
        })();
        return () => { alive = false; };
    }, [event.id]);

    if (error) {
        return html`<${Card} title="Teams">
            <${EmptyState} title="Not available yet" message=${error} />
        <//>`;
    }
    if (!data) return html`<${Spinner} label="Loading teams…" />`;

    const locked = data.locked;
    const editable = can('event.edit');

    const save = async () => {
        setSaving(true);
        try {
            const next = await studio('teams_save', {
                id: event.id,
                hex_list: locked ? null : hexList,
                teams: data.teams.map((team) => ({
                    id: team.id,
                    name: names[team.id] ?? team.name ?? '',
                })),
            });
            setData(next);
            setNames({});
            toast('Teams saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        }
        setSaving(false);
    };

    return html`
        <div class="space-y-6">
            <${Card} title="Colours"
                subtitle=${locked
                    ? 'Locked: people have already been put on teams tonight. Names can still change.'
                    : 'Paste hex codes, one per line. Between ' + data.limits.min + ' and ' + data.limits.max + '.'}
                actions=${editable ? html`<${Button} onClick=${save} loading=${saving}>Save<//>` : null}>

                <div class="grid gap-6 lg:grid-cols-2">
                    <${Field} label="Team colours" name="hex_list"
                        hint="The readable name — Red, Royal Blue, Lime — is worked out for you.">
                        <${TextArea} name="hex_list" value=${hexList} rows=${8}
                            onInput=${setHexList} placeholder="#D11920&#10;#1D356A&#10;#1E9E62" />
                    <//>

                    <div class="space-y-3">
                        <p class="text-sm font-semibold text-gray-700">Names</p>
                        ${data.teams.map((team) => html`
                            <div class="flex items-center gap-3" key=${team.id}>
                                <${Swatch} team=${team} />
                                <input class="flex-1 px-3 py-2 rounded-xl border border-gray-200 focus:border-hodBlue outline-none"
                                    maxLength=${40}
                                    aria-label=${'Name for ' + team.color_label}
                                    placeholder=${team.color_label}
                                    value=${names[team.id] ?? team.name ?? ''}
                                    disabled=${!editable}
                                    onInput=${(e) => setNames({ ...names, [team.id]: e.currentTarget.value })} />
                            </div>`)}
                        ${!data.teams.length
                            ? html`<p class="text-sm text-gray-400">Save some colours and the names appear here.</p>`
                            : null}
                    </div>
                </div>

                <div class="mt-6">
                    <${Warnings} warnings=${data.warnings} teams=${data.teams} />
                </div>
            <//>

            <${Card} title="Who is on which team"
                subtitle="Filled automatically at check-in: size first, then gender, then members and guests.">
                <${Roster} data=${data} onMoved=${setData} />
            <//>
        </div>`;
}
