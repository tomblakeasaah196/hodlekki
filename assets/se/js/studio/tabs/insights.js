// /assets/se/js/studio/tabs/insights.js
//
// Studio → Insights (guide §17.2): how the night went, in aggregate only —
// no person is ever named here. Every chart is a plain bar list that is its
// own table, so it reads the same to a screen reader and on a phone (the
// Studio page does not load a chart library).

import { html } from '@se/core/html.js';
import { useEffect, useState } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast } from '../state.js';
import { Card, Button, Spinner } from '../ui.js';

const nf = new Intl.NumberFormat();

/** A labelled bar list: the bars are the chart, the numbers are the table. */
function Bars({ title, subtitle, rows = [], empty = 'Nothing to show yet.' }) {
    const max = Math.max(1, ...rows.map((r) => Number(r.value) || 0));
    return html`
        <${Card} title=${title} subtitle=${subtitle}>
            ${rows.length ? html`
                <ul class="space-y-2.5" aria-label=${title}>
                    ${rows.map((row) => html`
                        <li key=${row.label} class="grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 text-sm">
                            <span class="text-gray-700 truncate" title=${row.label}>${row.label}</span>
                            <span class="h-2.5 rounded-full bg-gray-100 overflow-hidden" aria-hidden="true">
                                <span class="block h-full rounded-full bg-hodBlue" style=${{ width: Math.round(((Number(row.value) || 0) / max) * 100) + '%' }}></span>
                            </span>
                            <span class="font-bold text-gray-900 tabular-nums">${nf.format(Number(row.value) || 0)}</span>
                        </li>`)}
                </ul>` : html`<p class="text-sm text-gray-500">${empty}</p>`}
        </${Card}>`;
}

function Kpi({ label, value, note }) {
    return html`
        <div class="bg-white rounded-2xl border border-gray-100 p-4">
            <p class="text-xs font-semibold text-gray-500">${label}</p>
            <p class="text-2xl font-bold text-gray-900 tabular-nums">${value}</p>
            ${note ? html`<p class="text-xs text-gray-500 mt-0.5">${note}</p>` : null}
        </div>`;
}

const HANDOFF_LABEL = {
    created: 'created', linked_existing: 'linked to an existing person', already_handed_off: 'already handed off',
    skipped_member: 'members (not handed off)', skipped_no_consent: 'no follow-up consent', skipped_no_show: 'did not come',
    skipped_opted_out: 'opted out', skipped_excluded: 'held back', skipped_invalid_phone: 'unusable phone number',
};

function handoffRows(rows) {
    return (rows || []).map((row) => {
        const [destination, outcome] = String(row.label).split(': ');
        const where = destination === 'reach' ? 'Reach' : destination === 'embrace' ? 'Embrace' : 'Not sent';
        return { label: where + ' · ' + (HANDOFF_LABEL[outcome] || outcome || ''), value: row.value };
    });
}

export function InsightsTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const load = () => {
        setError(null);
        studio('insights_get', { id: event.id }).then(setData).catch((e) => setError(e.message || 'Insights could not be calculated.'));
    };
    useEffect(() => { setData(null); load(); }, [event.id]);

    if (error && !data) {
        return html`
            <${Card} title="Insights">
                <p class="text-sm text-gray-700">${error}</p>
                <div class="mt-4"><${Button} variant="secondary" onClick=${load}>Try again</${Button}></div>
            </${Card}>`;
    }
    if (!data) return html`<${Spinner} label="Calculating insights…" />`;

    const c = data.counts || {};
    const fb = data.feedback || {};
    const pdf = async () => {
        try {
            const links = await studio('report_links', { id: event.id });
            window.open(links.pdf, '_blank', 'noopener');
        } catch (e) {
            toast(e.message, 'error');
        }
    };
    const series = data.series || [];

    return html`
        <div class="space-y-6">
            <div class="flex flex-wrap justify-between items-start gap-3">
                <div>
                    <h2 class="text-xl font-display font-bold text-gray-900">Insights</h2>
                    <p class="text-sm text-gray-500">Totals only — nobody is named on this page. Rehearsal (test) records are left out.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <${Button} variant="secondary" onClick=${pdf}>PDF report</${Button}>
                    <a class="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-gray-900 hover:bg-gray-800"
                        href=${'/api/special_events_export.php?event=' + encodeURIComponent(event.public_id) + '&full=1'}>Excel export</a>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <${Kpi} label="Registered" value=${nf.format(c.registrations || 0)} note=${nf.format(c.confirmed || 0) + ' confirmed'} />
                <${Kpi} label="Checked in" value=${nf.format(c.checked_in || 0)} note=${(c.show_up_pct ?? 0) + '% of confirmed came'} />
                <${Kpi} label="Walk-ins" value=${nf.format(c.walkins || 0)} />
                <${Kpi} label="Church members there" value=${nf.format(c.members || 0)} />
                <${Kpi} label="Played on their phones" value=${nf.format(c.games_joined || 0)} note=${(c.game_join_pct ?? 0) + '% of those checked in'} />
                <${Kpi} label="Karaoke songs" value=${nf.format(c.karaoke_performed || 0)} />
                <${Kpi} label="Feedback NPS" value=${fb.nps ?? '—'} note=${nf.format(fb.responses || 0) + ' responses'} />
                <${Kpi} label="Champions" value=${data.hall_of_fame?.name || '—'} note=${data.hall_of_fame ? nf.format(data.hall_of_fame.points) + ' points' : ''} />
            </div>

            <div class="grid lg:grid-cols-2 gap-5">
                <${Bars} title="From interest to the door" rows=${data.funnel} />
                <${Bars} title="How people heard" subtitle="The ?src= on the link they registered from" rows=${data.channels} />
                <${Bars} title="Gender" rows=${data.gender} />
                <${Bars} title="Checked in, by team" rows=${data.teams} empty="No teams were used." />
                <${Bars} title="Hand-off" rows=${handoffRows(data.handoff)} empty="Nothing handed off yet." />
                <${Bars} title="Back in church afterwards" subtitle="Handed-off guests seen at a service within 30, 60 and 90 days"
                    rows=${data.followup_attendance} empty="Shows once guests have been handed off to Embrace." />
            </div>

            ${series.length > 1 ? html`
                <${Card} title="Across the series">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-gray-500">
                                <th class="py-2 pr-4 font-semibold">Edition</th>
                                <th class="py-2 pr-4 font-semibold text-right">Registered</th>
                                <th class="py-2 pr-4 font-semibold text-right">Checked in</th>
                                <th class="py-2 font-semibold text-right">Average NPS score</th>
                            </tr></thead>
                            <tbody>
                                ${series.map((row) => html`
                                    <tr key=${row.id} class=${'border-t border-gray-100 ' + (Number(row.id) === Number(event.id) ? 'font-bold' : '')}>
                                        <td class="py-2 pr-4">${row.label}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">${nf.format(Number(row.registrations) || 0)}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">${nf.format(Number(row.checked_in) || 0)}</td>
                                        <td class="py-2 text-right tabular-nums">${row.avg_nps ?? '—'}</td>
                                    </tr>`)}
                            </tbody>
                        </table>
                    </div>
                </${Card}>` : null}
        </div>`;
}
