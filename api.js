// ===== Boot state safely from JSON script tag =====
const bootEl = document.getElementById('bootstrap');
let serverState = {};
try { serverState = JSON.parse(bootEl?.textContent || '{}'); } catch {}
let state = store.get('cards_state', null) || serverState || {cards:[], updated_at:0};
state.cards ||= [];

const grid = document.getElementById('grid');
const addBtn = document.getElementById('addCardBtn');
const modal = document.getElementById('modal');
const backdrop = modal?.querySelector('.backdrop');
const nameInput = document.getElementById('nameInput');
const editor = document.getElementById('editor');
const closeModalBtn = document.getElementById('closeModal');
const trashBtn = document.getElementById('trashBtn');
const fullscreenBtn = document.getElementById('fullscreenBtn');

const historyBtn = document.getElementById('historyBtn');
const drawer = document.getElementById('historyDrawer');
const closeHistory = document.getElementById('closeHistory');
const historyList = document.getElementById('historyList');

const flushBtn = document.getElementById('flushBtn');

const API = {
  state: () => fetch('api.php?action=state').then(r=>r.json()),
  saveCard: (card) => fetch('api.php?action=save_card', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(card)}).then(r=>r.json()),
  deleteCard: (id) => fetch('api.php?action=delete_card', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})}).then(r=>r.json()),
  bulkSave: (cards) => navigator.sendBeacon
    ? navigator.sendBeacon('api.php?action=bulk_save', new Blob([JSON.stringify({cards})], {type:'application/json'}))
    : fetch('api.php?action=bulk_save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({cards}) }),
  flushOnce: () => fetch('api.php?action=flush_once', {cache:'no-store'}).then(r=>r.json()),
  history: () => fetch('api.php?action=history', {cache:'no-store'}).then(r=>r.json()),
  historyPurge: (id)=> fetch('api.php?action=history_purge',{method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})}).then(r=>r.json()),
  historyRestore: (id)=> fetch('api.php?action=history_restore',{method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})}).then(r=>r.json()),
};

const uid = () => crypto.randomUUID?.() || (Date.now().toString(36)+Math.random().toString(36).slice(2));
const firstSentence = (t) => { if (!t) return ''; const m = t.match(/[^.!?]*[.!?]/); return (m?m[0]:t).trim(); };
const debounce = (fn, ms=400)=>{ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; };
const serverSaveDebounced = debounce((card)=> API.saveCard(card), 450);

let currentId = null;
let trashArmed = false;

// ===== Render grid =====
function render() {
  grid.innerHTML = '';
  state.cards.sort((a,b)=>a.order-b.order).forEach(card => {
    const el = document.createElement('div');
    el.className = 'card';
    el.dataset.id = card.id;

    const handle = document.createElement('div');
    handle.className = 'card-handle';
    const grab = document.createElement('div');
    grab.className = 'grab';
    grab.textContent = '⋮⋮';
    grab.title = 'Drag';
    const title = document.createElement('div');
    title.className = 'title';
    title.textContent = (card.name || '').trim() || '—';
    handle.append(grab, title);

    const blurb = document.createElement('div');
    blurb.className = 'card-blurb';
    blurb.textContent = firstSentence(card.text) || '…';

    el.appendChild(handle);
    el.appendChild(blurb);
    grid.appendChild(el);

    // Clicking anywhere except the grab opens modal
    handle.addEventListener('click', (e)=> { if (e.target === grab) return; openModal(card.id); });
    blurb.addEventListener('click', ()=> openModal(card.id));

    // DnD strictly on grab
    let dragId=null;
    grab.setAttribute('draggable','true');
    grab.addEventListener('dragstart', (e)=> { dragId = card.id; e.dataTransfer.setData('text/plain', dragId); });
    el.addEventListener('dragover', (e)=> { e.preventDefault(); const srcId = dragId || e.dataTransfer.getData('text/plain'); if (!srcId || srcId===card.id) return; reorder(srcId, card.id); });
    grab.addEventListener('dragend', ()=> dragId=null);
  });
}

function reorder(srcId, tgtId) {
  const cards = state.cards;
  const srcIdx = cards.findIndex(c=>c.id===srcId);
  const tgtIdx = cards.findIndex(c=>c.id===tgtId);
  if (srcIdx<0 || tgtIdx<0 || srcIdx===tgtIdx) return;
  const [moved] = cards.splice(srcIdx,1);
  cards.splice(tgtIdx,0,moved);
  cards.forEach((c,i)=>c.order=i);
  saveLocal();
  queueServerSave(moved);
  render();
}

// ===== Modal =====
function openModal(id) {
  currentId = id;
  const card = state.cards.find(c=>c.id===id);
  if (!card) return;
  editor.value = card.text || '';
  nameInput.value = (card.name || '').trim();
  modal.classList.remove('hidden');
  modal.setAttribute('aria-hidden','false');
  editor.focus();
  trashBtn.classList.remove('armed'); trashArmed=false;
}
function closeModal() {
  currentId = null;
  modal.classList.add('hidden');
  modal.setAttribute('aria-hidden','true');
  modal.classList.remove('fullscreen');
  trashBtn.classList.remove('armed'); trashArmed=false;
}
backdrop?.addEventListener('click', (e)=> { if (e.target.dataset.close) closeModal(); });
closeModalBtn?.addEventListener('click', closeModal);
document.addEventListener('keydown', (e)=>{ if (e.key==='Escape' && !modal.classList.contains('hidden')) closeModal(); });

fullscreenBtn?.addEventListener('click', ()=> {
  modal.classList.toggle('fullscreen');
});

// Save text on input
editor?.addEventListener('input', ()=>{
  if (!currentId) return;
  const card = state.cards.find(c=>c.id===currentId);
  card.text = editor.value;
  saveLocal();
  queueServerSave(card);
  const el = grid.querySelector(`.card[data-id="${card.id}"] .card-blurb`);
  if (el) el.textContent = firstSentence(card.text) || '…';
});

// Save name on blur & Enter
nameInput?.addEventListener('keydown', (e)=> {
  if (e.key==='Enter') { e.preventDefault(); nameInput.blur(); }
});
nameInput?.addEventListener('blur', ()=>{
  if (!currentId) return;
  const card = state.cards.find(c=>c.id===currentId);
  const val = nameInput.value.trim();
  card.name = val;
  saveLocal();
  queueServerSave(card);
  const t = grid.querySelector(`.card[data-id="${card.id}"] .card-handle .title`);
  if (t) t.textContent = val || '—';
});

// Double-prompt trash
trashBtn?.addEventListener('click', ()=>{
  if (!currentId) return;
  if (!trashArmed) {
    trashArmed = true; trashBtn.classList.add('armed');
    setTimeout(()=>{ trashArmed=false; trashBtn.classList.remove('armed'); }, 2500);
    return;
  }
  // Soft delete (Redis only)
  const id = currentId;
  closeModal();
  state.cards = state.cards.filter(c=>c.id!==id).map((c,i)=>({ ...c, order:i }));
  saveLocal(); render();
  API.deleteCard(id);
});

// Add card
addBtn?.addEventListener('click', ()=>{
  const id = uid(); const order = state.cards.length;
  const card = { id, name:'', text:'', order, updated_at: Date.now()/1000|0 };
  state.cards.push(card); saveLocal(); render(); queueServerSave(card);
  setTimeout(()=>openModal(id), 0);
});

function queueServerSave(card) {
  serverSaveDebounced({ id: card.id, name: card.name||'', text: card.text, order: card.order|0 });
}
function saveLocal(){ store.set('cards_state', state); }

// Safety net
window.addEventListener('beforeunload', ()=> { try{ API.bulkSave(state.cards); }catch{} });

// ===== Flush & Health =====
flushBtn?.addEventListener('click', async ()=>{
  flushBtn.disabled = true;
  try {
    const res = await API.flushOnce();
    const msg = `Flushed: ${res.flushed}\nUpserts: ${res.stats?.upserts||0}\nPurges: ${res.stats?.purges||0}\nPruned empty: ${res.stats?.skipped_empty||0}`;
    alert(msg);
  } catch(e) { alert('Flush failed'); }
  flushBtn.disabled = false;
});

// ===== History drawer =====
historyBtn?.addEventListener('click', async ()=>{
  try{
    const {orphans=[]} = await API.history();
    historyList.innerHTML = '';
    if (!orphans.length) {
      historyList.textContent = 'No DB-only cards found.';
    } else {
      orphans.forEach(o=>{
        const row = document.createElement('div');
        row.className='history-row';
        const name = (o.name||'').trim() || '—';
        const preview = (o.txt||'').slice(0,120).replace(/\s+/g,' ');
        row.innerHTML = `
          <div><strong>${name}</strong> <code>${o.id}</code></div>
          <div class="muted">${preview}</div>
          <div class="row-actions">
            <button class="icon-btn restore" data-id="${o.id}">Restore</button>
            <button class="icon-btn danger purge" data-id="${o.id}">Purge</button>
          </div>`;
        historyList.appendChild(row);
      });
      historyList.querySelectorAll('.restore').forEach(b=> b.addEventListener('click', async(e)=>{
        const id = e.currentTarget.dataset.id; await API.historyRestore(id); alert('Restored '+id);
      }));
      historyList.querySelectorAll('.purge').forEach(b=> b.addEventListener('click', async(e)=>{
        const id = e.currentTarget.dataset.id; if (!confirm('Permanently delete?')) return;
        await API.historyPurge(id); alert('Purged '+id);
      }));
    }
    drawer.classList.remove('hidden'); drawer.setAttribute('aria-hidden','false');
  }catch{ alert('History load failed'); }
});
closeHistory?.addEventListener('click', ()=>{ drawer.classList.add('hidden'); drawer.setAttribute('aria-hidden','true'); });

// ===== Initial render =====
render();
