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
let versionsPanel = null; // dynamic container
let versionsState = { cardId:null, versions:[], selected:null };

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
// Multi-line preview: take text, normalize whitespace, take first N lines/characters
const previewSnippet = (t, maxLines=9, maxChars=600) => {
  if (!t) return '';
  // Normalize newlines
  const lines = t.replace(/\r\n?/g, '\n').split('\n');
  const trimmed = [];
  for (const line of lines) {
    if (trimmed.length >= maxLines) break;
    // Collapse internal excessive whitespace
    trimmed.push(line.trimEnd());
    if (trimmed.join('\n').length > maxChars) break;
  }
  let out = trimmed.join('\n');
  if (out.length > maxChars) out = out.slice(0, maxChars - 1) + '…';
  return out;
};
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

// ===== Drag & Drop meta =====
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
  blurb.textContent = previewSnippet(card.text) || '…';

    el.appendChild(handle);
    el.appendChild(blurb);
    grid.appendChild(el);

    // Clicking anywhere except the grab opens modal
    handle.addEventListener('click', (e)=> { if (e.target === grab) return; openModal(card.id); });
    blurb.addEventListener('click', ()=> openModal(card.id));

    // ---- Drag & Drop (handle-only) ----
  // We drag only via the grab handle. The card itself is moved on drop.
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
    // (Per-card dragover no longer needed; global grid handler manages placeholder.)
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
    // Bump updated_at locally to make sure reconcile treats order change as newer
    const nowSec = Date.now()/1000|0;
    cards.forEach(c=>{ c.updated_at = nowSec; });
    saveLocal();
    // Force bulk save via fetch (ignore sendBeacon path) so we know it executes.
    try {
      fetch('src/Api/index.php?action=bulk_save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({cards: cards.map(c=>({id:c.id, text:c.text, order:c.order, name:c.name||''}))}) });
    } catch {}
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
  if (el) el.textContent = previewSnippet(card.text) || '…';
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
    // Build tabs inside drawer header if not present
    const drawerHeader = drawer.querySelector('.drawer-header');
    if (drawerHeader && !drawerHeader.querySelector('.history-tabs')) {
      const tabs = document.createElement('div');
      tabs.className='history-tabs inline';
      tabs.innerHTML = `<button class="icon-btn tab active" data-tab="orphans" title="Deleted cards still in DB">Deleted</button><button class="icon-btn tab" data-tab="versions" title="Per-card snapshots">Versions</button>`;
      // Insert tabs before the spacer/close if we add a spacer
      // Create a spacer to push close button right if not present
      if (!drawerHeader.querySelector('.header-spacer')) {
        const spacer = document.createElement('div'); spacer.className='header-spacer';
        drawerHeader.insertBefore(spacer, drawerHeader.lastElementChild);
      }
      drawerHeader.insertBefore(tabs, drawerHeader.querySelector('.header-spacer'));
      tabs.querySelectorAll('.tab').forEach(t=> t.addEventListener('click', (e)=>{
        tabs.querySelectorAll('.tab').forEach(b=>b.classList.remove('active')); e.currentTarget.classList.add('active');
        const tab = e.currentTarget.getAttribute('data-tab');
        if (tab==='orphans') { historyList.style.display='block'; versionsPanel.style.display='none'; }
        else { historyList.style.display='none'; versionsPanel.style.display='block'; }
      }));
    }
    if (!versionsPanel) {
      versionsPanel = document.createElement('div');
      versionsPanel.className='versions-panel';
      versionsPanel.style.display='none';
      versionsPanel.innerHTML = `<div class="versions-header"><select id="versionsCardSelect"></select><button class="icon-btn" id="snapshotBtn">Snapshot Now</button></div><div id="versionsList" class="versions-list muted">Select a card to load versions…</div><div id="versionDiff" class="version-diff"></div>`;
  historyList.parentElement.appendChild(versionsPanel);
      // Populate select with current in-memory cards
      const select = versionsPanel.querySelector('#versionsCardSelect');
      state.cards.sort((a,b)=>a.order-b.order).forEach(c=>{
        const opt=document.createElement('option'); opt.value=c.id; opt.textContent=(c.name||'')||c.id.slice(0,8); select.appendChild(opt);
      });
      select.addEventListener('change', ()=> loadVersions(select.value));
      versionsPanel.querySelector('#snapshotBtn').addEventListener('click', async()=>{
        if(!select.value) return; await fetch('src/Api/index.php?action=version_snapshot',{method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id:select.value})}); loadVersions(select.value);
      });
    }
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
    // Initialize versions select default to first card
    if (versionsPanel) {
      const sel = versionsPanel.querySelector('#versionsCardSelect');
      if (sel && sel.options.length && !sel.value) { sel.value = sel.options[0].value; loadVersions(sel.value); }
    }
  }catch{ alert('History load failed'); }
});
closeHistory?.addEventListener('click', ()=>{ drawer.classList.add('hidden'); drawer.setAttribute('aria-hidden','true'); });
// Backdrop outside click to close (history drawer)
drawer?.addEventListener('click', (e)=>{
  if (e.target && e.target.classList.contains('drawer-backdrop')) {
    drawer.classList.add('hidden'); drawer.setAttribute('aria-hidden','true');
  }
});

async function loadVersions(cardId){
  if(!cardId) return; versionsState.cardId = cardId; const listEl = versionsPanel.querySelector('#versionsList'); listEl.textContent='Loading versions…';
  try{
    const res = await fetch(`src/Api/index.php?action=versions_list&id=${encodeURIComponent(cardId)}&limit=25`);
    const j = await res.json(); if(!j.ok) throw new Error(j.error||'fail');
    versionsState.versions = j.versions||[];
    if(!versionsState.versions.length){ listEl.textContent='No versions captured yet.'; return; }
    listEl.innerHTML='';
    versionsState.versions.forEach(v=>{
      const row=document.createElement('div'); row.className='version-row';
      const when = new Date(v.captured_at*1000).toLocaleString();
      row.innerHTML = `<div class="v-meta"><strong>#${v.version_id}</strong> <span>${when}</span> <span class="badge">${v.origin}</span> <span class="muted">${(v.size||0)} chars</span></div>`;
      row.addEventListener('click', ()=> showVersionDiff(v.version_id));
      listEl.appendChild(row);
    });
  }catch(e){ listEl.textContent='Load failed'; }
}

async function showVersionDiff(versionId){
  const diffEl = versionsPanel.querySelector('#versionDiff'); diffEl.textContent='Loading…';
  try{
    const res = await fetch(`src/Api/index.php?action=version_get&version_id=${versionId}`);
    const j = await res.json(); if(!j.ok) throw new Error(j.error||'fail');
    const v = j.version; versionsState.selected = v;
    const currentCard = state.cards.find(c=>c.id===versionsState.cardId);
    const currentText = currentCard ? currentCard.text||'' : '';
    // Build container with mode buttons Raw / Diff
    diffEl.innerHTML = `
      <div class="version-actions">
        <div class="left-group">
          <button class="icon-btn mode-btn active" data-mode="raw">Raw</button>
          <button class="icon-btn mode-btn" data-mode="diff">Diff vs Current</button>
        </div>
        <div class="right-group">
          <button class="icon-btn" data-act="restore" title="Restore this version as current">Restore</button>
          <button class="icon-btn" data-act="copy" title="Copy version text to clipboard">Copy</button>
        </div>
      </div>
      <div class="version-view" data-view="raw"><pre class="version-raw"><code>${escapeHtml(v.txt)}</code></pre></div>
    `;
    const renderDiff = () => {
      const view = diffEl.querySelector('.version-view');
      if (!view) return;
      const diffLines = computeLineDiff(v.txt||'', currentText);
      const html = diffLines.map(d=>{
        const safe = escapeHtml(d.text);
        if (d.type==='add') return `<div class="diff-line add">+ ${safe}</div>`;
        if (d.type==='del') return `<div class="diff-line del">- ${safe}</div>`;
        return `<div class="diff-line same">  ${safe}</div>`;
      }).join('');
      view.setAttribute('data-view','diff');
      view.innerHTML = `<pre class="diff-block">${html}</pre>`;
    };
    // Mode switching
    diffEl.querySelectorAll('.mode-btn').forEach(btn=> btn.addEventListener('click', e=>{
      diffEl.querySelectorAll('.mode-btn').forEach(b=>b.classList.remove('active'));
      e.currentTarget.classList.add('active');
      const mode = e.currentTarget.getAttribute('data-mode');
      const view = diffEl.querySelector('.version-view');
      if (!view) return;
      if (mode==='raw') {
        view.setAttribute('data-view','raw');
        view.innerHTML = `<pre class="version-raw"><code>${escapeHtml(v.txt)}</code></pre>`;
      } else {
        renderDiff();
      }
    }));
    diffEl.querySelector('[data-act="restore"]').addEventListener('click', ()=> restoreVersion(v.version_id));
    diffEl.querySelector('[data-act="copy"]').addEventListener('click', ()=> { navigator.clipboard.writeText(v.txt||''); alert('Copied'); });
  }catch(e){ diffEl.textContent='Failed to load version'; }
}

async function restoreVersion(versionId){
  if(!confirm('Restore this version?')) return; const res = await fetch('src/Api/index.php?action=version_restore',{method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({version_id:versionId})});
  try{ const j = await res.json(); if(!j.ok) throw new Error(j.error||'fail'); alert('Restored'); window.dispatchEvent(new Event('renote:force-sync')); }catch{ alert('Restore failed'); }
}

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }

// ===== Simple line diff (LCS based) =====
// Returns array of {type: 'same'|'add'|'del', text}
function computeLineDiff(oldText, newText) {
  if (oldText === newText) return oldText.split(/\r?\n/).map(t=>({type:'same', text:t}));
  const a = oldText.split(/\r?\n/);
  const b = newText.split(/\r?\n/);
  const n = a.length, m = b.length;
  // Guard for huge texts – fall back to simple comparison to avoid O(n*m) blowup
  if (n*m > 160000) { // ~400x400 lines threshold
    // Fallback: mark differing lines naively
    const max = Math.max(n,m);
    const out = [];
    for (let i=0;i<max;i++) {
      const av = a[i]; const bv = b[i];
      if (av === bv) out.push({type:'same', text: av ?? ''});
      else {
        if (av !== undefined) out.push({type:'del', text: av});
        if (bv !== undefined) out.push({type:'add', text: bv});
      }
    }
    return out;
  }
  const dp = Array(n+1); for (let i=0;i<=n;i++){ dp[i]=Array(m+1).fill(0);} // LCS lengths
  for (let i=n-1;i>=0;i--) {
    for (let j=m-1;j>=0;j--) {
      dp[i][j] = a[i] === b[j] ? 1 + dp[i+1][j+1] : Math.max(dp[i+1][j], dp[i][j+1]);
    }
  }
  const out = [];
  let i=0, j=0;
  while (i<n && j<m) {
    if (a[i] === b[j]) { out.push({type:'same', text:a[i]}); i++; j++; }
    else if (dp[i+1][j] >= dp[i][j+1]) { out.push({type:'del', text:a[i++]}); }
    else { out.push({type:'add', text:b[j++]}); }
  }
  while (i<n) out.push({type:'del', text:a[i++]});
  while (j<m) out.push({type:'add', text:b[j++]});
  // Collapse trivial noise: combine consecutive adds/dels separated by empty same lines (optional future)
  return out;
}

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

// Manual force sync (triggered after flush button completes on server)
window.addEventListener('renote:force-sync', ()=> {
  reconcileWithServer(true);
});
