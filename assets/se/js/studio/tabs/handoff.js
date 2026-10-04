// /assets/se/js/studio/tabs/handoff.js
import { html } from '@se/core/html.js';
import { useEffect, useState } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner } from '../ui.js';

export function HandoffTab() {
    const event=current.value; const [preview,setPreview]=useState(null); const [history,setHistory]=useState([]); const [noShows,setNoShows]=useState(false); const [busy,setBusy]=useState(false);
    const load=()=>Promise.all([studio('handoff_preview',{id:event.id,include_no_shows:noShows}),studio('handoff_history',{id:event.id})]).then(([p,h])=>{setPreview(p);setHistory(h.runs||[]);}).catch(e=>toast(e.message,'error'));
    useEffect(load,[event.id,noShows]);
    if(!preview)return html`<${Spinner} label="Building hand-off preview…"/>`;
    const ready=preview.items.filter(i=>i.reason==='ready').length;
    const push=async()=>{if(!confirm(`Push ${ready} reviewed people to Reach and Embrace?`))return;setBusy(true);try{const r=await studio('handoff_push',{id:event.id,options:{include_no_shows:noShows},overrides:[]});toast(`Hand-off complete: ${r.counts.created||0} created.`,'success');await load();}catch(e){toast(e.message,'error');}finally{setBusy(false);}};
    const archive=async()=>{if(!confirm('Archive this event? Editing and live operations will freeze.'))return;setBusy(true);try{await studio('archive',{id:event.id});current.value={...event,status:'archived',phase:'archived'};toast('Event archived.','success');}catch(e){toast(e.message,'error');}finally{setBusy(false);}};
    return html`<div class="space-y-6"><div class="flex flex-wrap justify-between gap-3"><div><h2 class="text-xl font-bold">Hand-off</h2><p class="text-sm text-gray-500">Review consent and attendance before anything leaves Envision. Re-runs are idempotent.</p></div>${can('event.archive') && event.status !== 'archived' ? html`<${Button} disabled=${busy} onClick=${archive}>Archive event</${Button}>` : null}</div>
    <${Card} title="Rules"><label class="flex gap-2 text-sm"><input type="checkbox" checked=${noShows} onChange=${e=>setNoShows(e.currentTarget.checked)}/> Include registered no-shows in Reach</label><p class="mt-3 font-bold">${ready} ready to push</p></${Card}>
    <${Card} title="Preview"><div class="overflow-x-auto max-h-96"><table class="w-full text-sm"><thead><tr><th class="text-left p-2">Person</th><th class="p-2">Destination</th><th class="p-2">Reason</th></tr></thead><tbody>${preview.items.map(i=>html`<tr class="border-t"><td class="p-2">${i.name}</td><td class="p-2 text-center">${i.destination}</td><td class="p-2 text-center">${i.reason}</td></tr>`)}</tbody></table></div><div class="mt-4"><${Button} disabled=${busy||!ready} onClick=${push}>${busy?'Pushing…':'Push reviewed people'}</${Button}></div></${Card}>
    <${Card} title="History">${history.length?history.map(r=>html`<p class="text-sm border-b py-2">Run #${r.id} · ${r.created_at}</p>`):html`<p class="text-sm text-gray-500">No hand-offs yet.</p>`}</${Card}></div>`;
}
