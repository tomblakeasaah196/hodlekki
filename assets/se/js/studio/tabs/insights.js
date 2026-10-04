// /assets/se/js/studio/tabs/insights.js
import { html } from '@se/core/html.js';
import { useEffect, useRef, useState } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast } from '../state.js';
import { Card, Button, Spinner } from '../ui.js';

function DataTable({ title, rows = [] }) {
    const canvas = useRef(null);
    useEffect(() => {
        if (!canvas.current || !window.Chart || !rows.length) return;
        const chart = new window.Chart(canvas.current, { type: 'bar', data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.value), backgroundColor: ['#1D356A','#D11920','#D19A19','#26736A','#7756A8','#A85252'] }] }, options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } } });
        return () => chart.destroy();
    }, [JSON.stringify(rows)]);
    return html`<${Card} title=${title}>
        <canvas ref=${canvas} height="130" role="img" aria-label=${title + ' chart'}></canvas>
        <details class="mt-3" open><summary class="text-sm font-semibold cursor-pointer">Table view</summary>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="text-left p-2">Category</th><th class="text-right p-2">Count</th></tr></thead><tbody>
        ${rows.map((r) => html`<tr key=${r.label} class="border-t"><td class="p-2">${r.label}</td><td class="p-2 text-right font-bold">${r.value}</td></tr>`)}
        </tbody></table></div></details>
    </${Card}>`;
}

export function InsightsTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    useEffect(() => { studio('insights_get', { id: event.id }).then(setData).catch((e) => toast(e.message, 'error')); }, [event.id]);
    if (!data) return html`<${Spinner} label="Calculating insights…" />`;
    const c = data.counts || {};
    const reports = async () => {
        try { const links = await studio('report_links', { id: event.id }); window.open(links.pdf, '_blank', 'noopener'); }
        catch (e) { toast(e.message, 'error'); }
    };
    return html`<div class="space-y-6">
        <div class="flex flex-wrap justify-between gap-3"><div><h2 class="text-xl font-bold">Insights</h2><p class="text-sm text-gray-500">Aggregate results; every chart also has this accessible table view.</p></div>
        <div class="flex gap-2"><${Button} onClick=${reports}>PDF report</${Button}><a class="px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold" href=${'/api/special_events_export.php?event='+encodeURIComponent(event.public_id)+'&full=1'}>Excel</a></div></div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">${[['Registered',c.registrations],['Checked in',c.checked_in],['Show-up',c.show_up_pct+'%'],['NPS',data.feedback?.nps ?? '—']].map(([k,v])=>html`<div class="bg-white rounded-2xl border p-4"><p class="text-xs text-gray-500">${k}</p><p class="text-2xl font-bold">${v}</p></div>`)}</div>
        <div class="grid lg:grid-cols-2 gap-5"><${DataTable} title="Funnel" rows=${data.funnel}/><${DataTable} title="Channels" rows=${data.channels}/><${DataTable} title="Gender" rows=${data.gender}/><${DataTable} title="Teams" rows=${data.teams}/><${DataTable} title="Hand-off" rows=${data.handoff}/><${DataTable} title="Follow-up attendance" rows=${data.followup_attendance}/></div>
    </div>`;
}
