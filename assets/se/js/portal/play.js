// /assets/se/js/portal/play.js
import { call } from '@se/core/api.js';
import { html } from '@se/core/html.js';
export async function startPlay(config) {
    const host=document.querySelector('#se-main'); if(!host)return;
    host.innerHTML='<section class="se-card"><h1>Join the games</h1><p>Getting your game pass ready…</p></section>';
    try { const joined=await call('public','join_games',{event:config.event.public_id}); const games=joined.games||[]; host.innerHTML=''; const card=document.createElement('section');card.className='se-card';card.innerHTML='<h1>Games are ready</h1><p>Watch the stage for the next round. Your answers will appear here.</p><div class="se-game-list"></div>';host.append(card);const list=card.querySelector('.se-game-list');for(const g of games){const el=document.createElement('article');el.className='se-game-row';el.textContent=`${g.title} · ${g.type}`;list.append(el);}}
    catch(e){host.innerHTML=`<section class="se-card"><h1>Join the games</h1><p>${String(e.message||'Check in first.').replace(/[<>&"']/g,'')}</p></section>`;}
}
