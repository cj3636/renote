// ===== Boot state safely from JSON script tag =====
const bootEl = document.getElementById('bootstrap');
let serverState = {};
try { serverState = JSON.parse(bootEl?.textContent || '{}'); } catch {}
// Local-first boot, then reconcile with server to avoid device divergence.
let state = store.get('cards_state', null) || serverState || {cards:[], updated_at:0};
state.cards ||= [];

// Track whether we've synced after boot so we don't show placeholder titles for long.
let initialSynced = false;

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
  state: () => fetch('src/Api/index.php?action=state').then(r=>r.json()),
  saveCard: (card) => fetch('src/Api/index.php?action=save_card', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(card)}).then(r=>r.json()),
  deleteCard: (id) => fetch('src/Api/index.php?action=delete_card', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})}).then(r=>r.json()),
  bulkSave: (cards) => navigator.sendBeacon
    ? navigator.sendBeacon('src/Api/index.php?action=bulk_save', new Blob([JSON.stringify({cards})], {type:'application/json'}))
    : fetch('src/Api/index.php?action=bulk_save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({cards}) }),
  flushOnce: () => fetch('src/Api/index.php?action=flush_once', {cache:'no-store'}).then(r=>r.json()),
  history: () => fetch('src/Api/index.php?action=history', {cache:'no-store'}).then(r=>r.json()),
  historyPurge: (id)=> fetch('src/Api/index.php?action=history_purge',{method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})}).then(r=>r.json()),
  historyRestore: (id)=> fetch('src/Api/index.php?action=history_restore',{method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})}).then(r=>r.json()),
};

const uid = () => crypto.randomUUID?.() || (Date.now().toString(36)+Math.random().toString(36).slice(2));
const firstSentence = (t) => { if (!t) return ''; const m = t.match(/[^.!?]*[.!?]/); return (m?m[0]:t).trim(); };
const debounce = (fn, ms=400)=>{ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; };
const serverSaveDebounced = debounce(async (card)=> {
  try {
    const res = await API.saveCard(card);
    if (res && res.ok && res.updated_at && card) {
      const local = state.cards.find(c=>c.id===card.id);
      if (local) {
        local.updated_at = res.updated_at|0;
        saveLocal();
      }
    }
  } catch {}
}, 450);

let currentId = null;
let trashArmed = false;

// ===== Drag & Drop meta (new) =====
let dragMeta = {
  dragging: null,           // original element
  id: null,
  startIndex: -1,
  placeholder: null,
  active: false,
  committed: false
};

function makePlaceholder(height=0) {
  const ph = document.createElement('div');
  ph.className = 'card-placeholder';
  ph.style.height = height ? height+ 'px' : '';
  return ph;
}

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

    // ---- Drag & Drop (handle-only) ----
    // We drag the small grab element but move the entire card on drop.
    let dragId=null;
    grab.setAttribute('draggable','true');
    grab.addEventListener('dragstart', (e)=> {
      dragId = card.id;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', dragId);
      el.classList.add('dragging');
      // Build placeholder
      dragMeta.id = card.id;
      dragMeta.dragging = el;
      dragMeta.startIndex = [...grid.children].indexOf(el);
      dragMeta.placeholder = makePlaceholder(el.getBoundingClientRect().height);
      dragMeta.active = true;
      dragMeta.cancel = false;
      // Insert placeholder immediately after dragging el to preserve space
      el.parentNode.insertBefore(dragMeta.placeholder, el.nextSibling);
      // Custom drag image (clone mini)
      const clone = el.cloneNode(true);
      clone.style.width = el.getBoundingClientRect().width + 'px';
      clone.style.position = 'absolute';
      clone.style.top = '-9999px';
      clone.style.pointerEvents = 'none';
      clone.style.opacity = '0.85';
      document.body.appendChild(clone);
      try { e.dataTransfer.setDragImage(clone, clone.offsetWidth/2, 20); } catch {}
      setTimeout(()=> clone.remove(), 0);
    });
    grab.addEventListener('dragend', (e)=> {
      el.classList.remove('dragging');
      finalizeDrag(e);
    });
    // Card level dragover used for dynamic placeholder reposition
    el.addEventListener('dragover', (e)=> {
      if (!dragMeta.active) return; 
      e.preventDefault();
      const draggingId = dragMeta.id;
      if (!draggingId || el.dataset.id === draggingId) return;
      const rect = el.getBoundingClientRect();
      const before = (e.clientY - rect.top) < rect.height/2; // vertical midpoint heuristic
      const currentChildren = [...grid.children].filter(c=>c!==dragMeta.dragging && c!==dragMeta.placeholder);
      // Determine desired index
      const targetIndex = currentChildren.indexOf(el) + (before?0:1);
      const phIndex = [...grid.children].indexOf(dragMeta.placeholder);
      if (phIndex === targetIndex) return; // already there
      // Insert at calculated position
      if (targetIndex >= currentChildren.length) {
        grid.appendChild(dragMeta.placeholder);
      } else {
        grid.insertBefore(dragMeta.placeholder, currentChildren[targetIndex]);
      }
    });
  });
}

// Removed continuous visual reorders to prevent flicker – we only move placeholder.

function finalizeDrag(e) {
  if (!dragMeta.active) return;
  const ph = dragMeta.placeholder;
  const movedEl = dragMeta.dragging;
  const movedId = dragMeta.id;
  const validDrop = e && ph && grid.contains(ph);
  if (validDrop) {
    // Place original element where placeholder sits
    grid.insertBefore(movedEl, ph);
    // Compute new order from DOM sequence
    const ids = [...grid.querySelectorAll('.card')].map(c=>c.dataset.id);
    const cards = state.cards.sort((a,b)=>a.order-b.order);
    const byId = Object.fromEntries(cards.map(c=>[c.id,c]));
    ids.forEach((id,i)=>{ const c = byId[id]; if (c) c.order = i; });
    saveLocal();
    try { API.bulkSave(cards.map(c=>({id:c.id, text:c.text, order:c.order, name:c.name||''}))); } catch {}
    const moved = state.cards.find(c=>c.id===movedId);
    if (moved) queueServerSave(moved);
  }
  // Restore visibility
  if (movedEl) movedEl.style.removeProperty('display');
  ph?.remove();
  dragMeta = { dragging:null, id:null, startIndex:-1, placeholder:null, active:false, committed:false };
}

// Global dragover on grid to allow placeholder at end and live reorder preview
grid.addEventListener('dragover', (e)=> {
  if (!dragMeta.active) return; e.preventDefault();
  const ph = dragMeta.placeholder; if (!ph) return;
  const pointerX = e.clientX; const pointerY = e.clientY;
  const cards = [...grid.querySelectorAll('.card:not(.dragging)')];
  if (!cards.length) { grid.appendChild(ph); return; }

  // Build row groupings by vertical center proximity (tolerant clustering)
  const rows = [];
  const rowTolerance = 40; // px
  cards.forEach(card => {
    const r = card.getBoundingClientRect();
    const centerY = r.top + r.height/2;
    let row = rows.find(row => Math.abs(row.centerY - centerY) < rowTolerance);
    if (!row) { row = { centerY, items: [] }; rows.push(row); }
    row.items.push({ el: card, rect: r });
  });
  rows.sort((a,b)=>a.centerY - b.centerY);
  rows.forEach(row => row.items.sort((a,b)=>a.rect.left - b.rect.left));

  // Determine target row: closest centerY below pointer OR last row
  let targetRow = rows[0];
  for (const row of rows) {
    if (pointerY >= row.centerY - rowTolerance && pointerY <= row.centerY + rowTolerance) { targetRow = row; break; }
    if (pointerY > row.centerY) targetRow = row; // fallback to last passed row
  }

  // Within row decide position by comparing pointerX to item midpoints
  let insertBefore = null;
  for (const item of targetRow.items) {
    const midX = item.rect.left + item.rect.width/2;
    if (pointerX < midX) { insertBefore = item.el; break; }
  }

  if (!insertBefore) {
    // append to end of target row: find last element in that row's DOM order
    const lastEl = targetRow.items[targetRow.items.length-1].el;
    // Insert after lastEl (which in DOM means before next sibling of lastEl)
    if (lastEl.nextSibling !== ph) {
      if (lastEl.nextSibling) grid.insertBefore(ph, lastEl.nextSibling); else grid.appendChild(ph);
    }
  } else if (insertBefore !== ph) {
    grid.insertBefore(ph, insertBefore);
  }
});

// Legacy getDragAfterElement removed in favor of row-aware positioning.

// Cancel if ESC pressed during drag
document.addEventListener('keydown', (e)=>{
  if (e.key==='Escape' && dragMeta.active) {
    finalizeDrag(null); // cancels
    render(); // restore canonical order
  }
});

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

// ===== Reconciliation Logic =====
async function reconcileWithServer(force = false) {
  try {
    const remote = await API.state();
    if (!remote || !Array.isArray(remote.cards)) return;

    // Build map of existing local cards by id
    const localMap = Object.fromEntries(state.cards.map(c=>[c.id,c]));
    let changed = false;

    // Merge: for each remote card, if missing locally add; if remote newer, update
    const merged = [];
    for (const rc of remote.cards) {
      const lc = localMap[rc.id];
      if (!lc) { // new card from server not on this device
        merged.push({ id: rc.id, name: rc.name||'', text: rc.text||'', order: rc.order|0, updated_at: rc.updated_at|0 });
        changed = true;
      } else {
        // If remote more recent OR local has missing name while remote has one, update fields
        const needsUpdate = (rc.updated_at|0) > (lc.updated_at|0) || (!lc.name && rc.name);
        if (needsUpdate) {
          lc.name = rc.name||'';
          lc.text = rc.text||'';
          lc.order = rc.order|0;
          lc.updated_at = rc.updated_at|0;
          changed = true;
        }
        merged.push(lc);
        delete localMap[rc.id];
      }
    }
    // Any leftover local-only cards (not on server).
    const leftovers = Object.values(localMap);
    if (leftovers.length) {
      const nowSec = Date.now()/1000|0;
      for (const c of leftovers) {
        // If card has never been synced (no updated_at) treat as new and push upstream.
        if (!c.updated_at) {
          queueServerSave(c);
          merged.push(c);
          continue;
        }
        // If it has been synced before but is absent remotely, assume it was deleted elsewhere -> drop locally.
        // Grace period: if last update < 5s ago, keep (could be race with remote flush lag).
        if ((nowSec - c.updated_at) < 5) {
          merged.push(c); // keep during grace window
        } else {
          changed = true; // pruning
        }
      }
    }
    // Normalize order indexes (server authoritative order wins where clash)
    merged.sort((a,b)=>a.order-b.order).forEach((c,i)=>{ c.order = i; });
    if (changed || force) {
      state.cards = merged;
      saveLocal();
      render();
    }
    initialSynced = true;
  } catch (e) {
    // silent – offline maybe
  }
}

// Perform initial reconciliation shortly after boot (allow first paint), then periodic lightweight sync
setTimeout(()=>reconcileWithServer(true), 150);
setInterval(()=>reconcileWithServer(false), 15000); // every 15s

// If page loaded with no titles (all blank) attempt an earlier quick sync
if (state.cards.some(c=>!c.name)) { reconcileWithServer(false); }
