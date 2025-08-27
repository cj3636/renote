// Simple SPA logic: grid, modal, drag-reorder, instant local save + efficient server sync.

const grid = document.getElementById('grid');
const addBtn = document.getElementById('addCardBtn');
const modal = document.getElementById('modal');
const backdrop = modal.querySelector('.backdrop');
const closeModalBtn = document.getElementById('closeModal');
const editor = document.getElementById('editor');
const trashBtn = document.getElementById('trashBtn');

const API = {
  state: () => fetch('api.php?action=state').then(r=>r.json()),
  saveCard: (card) => fetch('api.php?action=save_card', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(card) }).then(r=>r.json()),
  bulkSave: (cards) => navigator.sendBeacon
    ? navigator.sendBeacon('api.php?action=bulk_save', new Blob([JSON.stringify({cards})], {type:'application/json'}))
    : fetch('api.php?action=bulk_save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({cards}) })
};

const uid = () => (crypto?.randomUUID?.() || ([1e7]+-1e3+-4e3+-8e3+-1e11)).toString();

const firstSentence = (t) => {
  if (!t) return '';
  const m = t.match(/[^.!?]*[.!?]/);
  return (m ? m[0] : t).trim();
};

const debounce = (fn, ms=400) => {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); };
};

let state = store.get('cards_state', null) || window.__INITIAL_STATE__ || {cards:[]};
state.cards = state.cards || [];

let currentId = null;
let trashArmed = false;

// Render
function render() {
  grid.innerHTML = '';
  state.cards.sort((a,b)=>a.order-b.order).forEach(card => {
    const el = document.createElement('div');
    el.className = 'card';
    el.setAttribute('draggable', 'true');
    el.dataset.id = card.id;

    const handle = document.createElement('div');
    handle.className = 'card-handle';
    handle.title = 'Drag to reorder';
    handle.textContent = '⋮⋮';

    const blurb = document.createElement('div');
    blurb.className = 'card-blurb';
    blurb.textContent = firstSentence(card.text) || '…';

    el.appendChild(handle);
    el.appendChild(blurb);
    grid.appendChild(el);

    // Click to open modal (ignore if dragging)
    let moved = false;
    el.addEventListener('mousedown', ()=>{ moved = false; }, { passive:true });
    el.addEventListener('mousemove', ()=>{ moved = true; }, { passive:true });
    el.addEventListener('click', (e) => {
      if (moved) return;
      openModal(card.id);
    });

    // Drag & drop reorder (drag only from handle)
    el.addEventListener('dragstart', (e) => {
      if (!e.target.closest('.card-handle')) { e.preventDefault(); return; }
      e.dataTransfer.setData('text/plain', card.id);
      el.classList.add('dragging');
    });
    el.addEventListener('dragend', ()=> el.classList.remove('dragging'));

    el.addEventListener('dragover', (e) => {
      e.preventDefault();
      const srcId = e.dataTransfer.getData('text/plain');
      const tgtId = card.id;
      if (!srcId || srcId === tgtId) return;
      reorder(srcId, tgtId);
    });
  });
}

function reorder(srcId, tgtId) {
  const cards = state.cards;
  const srcIdx = cards.findIndex(c=>c.id===srcId);
  const tgtIdx = cards.findIndex(c=>c.id===tgtId);
  if (srcIdx < 0 || tgtIdx < 0 || srcIdx === tgtIdx) return;
  const [moved] = cards.splice(srcIdx, 1);
  cards.splice(tgtIdx, 0, moved);
  cards.forEach((c,i)=>c.order=i);
  saveLocal();
  queueServerSave(moved); // save at least the moved card (order)
  render();
}

// Modal controls
function openModal(id) {
  currentId = id;
  const card = state.cards.find(c=>c.id===id);
  if (!card) return;
  editor.value = card.text || '';
  modal.classList.remove('hidden');
  modal.setAttribute('aria-hidden', 'false');
  editor.focus();
  trashBtn.classList.remove('armed');
  trashArmed = false;
}
function closeModal() {
  currentId = null;
  modal.classList.add('hidden');
  modal.setAttribute('aria-hidden', 'true');
  trashBtn.classList.remove('armed');
  trashArmed = false;
}

backdrop.addEventListener('click', (e)=> { if (e.target.dataset.close) closeModal(); });
closeModalBtn.addEventListener('click', closeModal);
document.addEventListener('keydown', (e)=>{ if (e.key==='Escape' && !modal.classList.contains('hidden')) closeModal(); });

// Double-prompt trash button
trashBtn.addEventListener('click', () => {
  if (!currentId) return;
  if (!trashArmed) {
    trashArmed = true;
    trashBtn.classList.add('armed'); // turn red
    setTimeout(()=>{ trashArmed=false; trashBtn.classList.remove('armed'); }, 2500);
    return;
  }
  // Confirmed second click within window
  deleteCard(currentId);
  closeModal();
});

function deleteCard(id) {
  state.cards = state.cards.filter(c=>c.id!==id).map((c,i)=>({ ...c, order:i }));
  saveLocal();
  fetch('api.php?action=delete_card', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id})});
  render();
}

// Add card
addBtn.addEventListener('click', () => {
  const id = uid();
  const order = state.cards.length;
  const card = { id, text: '', order };
  state.cards.push(card);
  saveLocal();
  // Create on server quickly
  API.saveCard(card);
  render();
  // Open editor right away
  setTimeout(()=>openModal(id), 0);
});

// Editor input: instant local save + efficient server save (debounced)
const serverSaveDebounced = debounce((card) => API.saveCard(card), 450);

editor.addEventListener('input', () => {
  if (!currentId) return;
  const card = state.cards.find(c=>c.id===currentId);
  if (!card) return;
  card.text = editor.value;
  saveLocal();
  queueServerSave(card);
  // Update blurb live
  const el = grid.querySelector(`.card[data-id="${card.id}"] .card-blurb`);
  if (el) el.textContent = firstSentence(card.text) || '…';
});

function queueServerSave(card) {
  serverSaveDebounced({ id: card.id, text: card.text, order: card.order|0 });
}

function saveLocal() { store.set('cards_state', state); }

// Persist everything on unload as a safety net
window.addEventListener('beforeunload', () => {
  try { API.bulkSave(state.cards); } catch(e){}
});

// Boot: prefer local (for offline + instant), merge/replace from server if newer.
async function boot() {
  // If no local, load server
  if (!store.get('cards_state')) {
    state = await API.state();
    store.set('cards_state', state);
  } else {
    // Try to pull server and choose the newer
    try {
      const remote = await API.state();
      const local = store.get('cards_state');
      const r = +remote.updated_at || 0;
      const l = +local.updated_at || 0;
      state = (r > l) ? remote : local;
      store.set('cards_state', state);
    } catch(e) {
      state = store.get('cards_state');
    }
  }
  // Guarantee at least one card
  if (!state.cards || state.cards.length===0) {
    state.cards = [{ id: uid(), text: '', order:0 }];
    saveLocal();
    API.saveCard(state.cards[0]);
  }
  render();
}
boot();
