// /assets/se/js/studio/tabs/games.js
import { useEffect, useState } from 'preact/hooks';
import { html } from '@se/core/html.js';
import { call } from '@se/core/api.js';
import { current, toast } from '../state.js';

export function GamesTab() {
    const event = current.value; const [games,setGames]=useState([]); const [decks,setDecks]=useState([]); const [title,setTitle]=useState(''); const [type,setType]=useState('live_quiz');
    const load=async()=>{try{const [g,d]=await Promise.all([call('studio','game_list',{id:event.id}),call('studio','deck_list',{id:event.id})]);setGames(g.games||[]);setDecks(d.decks||[]);}catch(e){toast(e.message,'error');}};
    useEffect(()=>{load();},[event.id]);
    const add=async()=>{try{await call('studio','game_save',{id:event.id,title,type,settings:{},weight:1});setTitle('');load();toast('Game added.','success');}catch(e){toast(e.message,'error');}};
    return html`<div class="space-y-6"><div><h1 class="text-2xl font-bold">Games</h1><p class="text-gray-500">Build reviewed decks, then place games in the run of show.</p></div>
      <section class="bg-white rounded-2xl border p-5 space-y-3"><h2 class="font-bold">Add a game</h2><div class="flex gap-2"><input class="border rounded-lg px-3 py-2 flex-1" placeholder="Game title" value=${title} onInput=${e=>setTitle(e.currentTarget.value)}/><select class="border rounded-lg px-3" value=${type} onChange=${e=>setType(e.currentTarget.value)}>${['live_quiz','trivia','buzzer'].map(x=>html`<option value=${x}>${x}</option>`)}</select><button class="px-4 py-2 rounded-lg bg-hodBlue text-white" onClick=${add}>Add</button></div></section>
      <section class="bg-white rounded-2xl border p-5"><h2 class="font-bold mb-3">Games in this event</h2>${games.length?html`<ul class="space-y-2">${games.map(g=>html`<li class="border rounded-lg p-3 flex justify-between"><span>${g.title}</span><span class="text-sm text-gray-500">${g.type} · ${g.status}</span></li>`)}</ul>`:html`<p class="text-gray-500">No games yet.</p>`}</section>
      <section class="bg-white rounded-2xl border p-5"><h2 class="font-bold mb-2">Deck library</h2><p class="text-gray-500">${decks.length} deck(s) available. Add and review content before the host can run it.</p></section></div>`;
}
