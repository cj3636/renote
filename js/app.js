// ===== Boot state safely from JSON script tag =====
const bootEl = document.getElementById('bootstrap');
let serverState = {};
try { serverState = JSON.parse(bootEl?.textContent || '{}'); } catch { }
// Local-first boot, then reconcile with server to avoid device divergence.
let state = store.get('cards_state', null) || serverState || { cards: [], categories: [], updated_at: 0 };
state.cards ||= [];
state.categories ||= [];
const ROOT_CATEGORY = 'root';
function normalizeCategory(cid) { return (!cid || cid === ROOT_CATEGORY) ? ROOT_CATEGORY : String(cid); }
state.cards = state.cards.map(c => ({ ...c, category_id: normalizeCategory(c.category_id) }));

// Track whether we've synced after boot so we don't show placeholder titles for long.
let initialSynced = false;

const ICONS = window.ICONS || {};

const grid = document.getElementById('grid');
grid?.classList.add('category-grid');
const addBtn = document.getElementById('addCardBtn');
const addCategoryBtn = document.getElementById('addCategoryBtn');
const modal = document.getElementById('modal');
const backdrop = modal?.querySelector('.backdrop');
const nameInput = document.getElementById('nameInput');
const categorySelect = document.getElementById('categorySelect');
const searchInput = document.getElementById('searchInput');
const editor = document.getElementById('editor');
const closeModalBtn = document.getElementById('closeModal');
const trashBtn = document.getElementById('trashBtn');
const fullscreenBtn = document.getElementById('fullscreenBtn');
const categoryModal = document.getElementById('categoryModal');
const categoryForm = document.getElementById('categoryForm');
const categoryNameInput = document.getElementById('categoryNameInput');
const categoryTitleEl = categoryModal?.querySelector('[data-role="title"]');
const categorySubtitleEl = categoryModal?.querySelector('[data-role="subtitle"]');
const confirmModal = document.getElementById('confirmModal');
const confirmTitleEl = confirmModal?.querySelector('[data-role="confirm-title"]');
const confirmSubtitleEl = confirmModal?.querySelector('[data-role="confirm-subtitle"]');
const layoutBar = document.getElementById('layoutBar');
const layoutStatusEl = document.getElementById('layoutStatus');
const categoryTabBar = document.getElementById('categoryTabBar');
const layoutButtons = layoutBar?.querySelectorAll('[data-layout]');

const STACKED_COLLAPSED_KEY = 'stacked_collapsed_categories';
const TAB_ACTIVE_KEY = 'tabbed_active_categories';
const LEGACY_COLLAPSED_KEY = 'collapsed_categories';
let stackedCollapsed = new Set(store.get(STACKED_COLLAPSED_KEY, store.get(LEGACY_COLLAPSED_KEY, [])));
let tabbedActive = store.get(TAB_ACTIVE_KEY, null);
let searchTerm = (store.get('search_term', '') || '').toLowerCase();
if (searchInput) searchInput.value = searchTerm;
const confirmBodyEl = confirmModal?.querySelector('[data-role="confirm-body"]');
const confirmAcceptBtn = confirmModal?.querySelector('[data-role="confirm-accept"]');
Object.assign(window, {
    categoryModal,
    categoryForm,
    categoryNameInput,
    categoryTitleEl,
    categorySubtitleEl,
    confirmModal,
    confirmTitleEl,
    confirmSubtitleEl,
    confirmBodyEl,
    confirmAcceptBtn,
});

const historyBtn = document.getElementById('historyBtn');
const drawer = document.getElementById('historyDrawer');
const closeHistory = document.getElementById('closeHistory');
const historyList = document.getElementById('historyList');
let versionsPanel = null; // dynamic container
let versionsState = { cardId: null, versions: [], selected: null };

const flushBtn = document.getElementById('flushBtn');

const CATEGORY_LAYOUTS = { HORIZONTAL: 'horizontal', STACKED: 'stacked' };
let categoryLayoutMode = store.get('category_layout_mode', CATEGORY_LAYOUTS.HORIZONTAL);
const applyCategoryLayout = (mode) => {
    const safeMode = mode === CATEGORY_LAYOUTS.STACKED ? CATEGORY_LAYOUTS.STACKED : CATEGORY_LAYOUTS.HORIZONTAL;
    const changed = categoryLayoutMode !== safeMode;
    categoryLayoutMode = safeMode;
    grid?.setAttribute('data-layout', safeMode);
    layoutButtons?.forEach(btn => {
        const active = btn.dataset.layout === safeMode;
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-pressed', String(active));
    });
    if (changed) {
        try { store.set('category_layout_mode', safeMode); } catch { }
    }
};
applyCategoryLayout(categoryLayoutMode);

const isCoarsePointer = window.matchMedia('(pointer: coarse)').matches;
if (isCoarsePointer) {
    document.body.classList.add('coarse-pointer');
    grid?.setAttribute('data-mobile-dnd-disabled', '');
}

const API = {
    state: () => fetch('src/Api/index.php?action=state').then(r => r.json()),
    saveCard: (card) => fetch('src/Api/index.php?action=save_card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(card) }).then(r => r.json()),
    deleteCard: (id) => fetch('src/Api/index.php?action=delete_card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }).then(r => r.json()),
    bulkSave: (cards) => navigator.sendBeacon
        ? navigator.sendBeacon('src/Api/index.php?action=bulk_save', new Blob([JSON.stringify({ cards })], { type: 'application/json' }))
        : fetch('src/Api/index.php?action=bulk_save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cards }) }),
    flushOnce: () => fetch('src/Api/index.php?action=flush_once', { cache: 'no-store' }).then(r => r.json()),
    history: () => fetch('src/Api/index.php?action=history', { cache: 'no-store' }).then(r => r.json()),
    historyPurge: (id) => fetch('src/Api/index.php?action=history_purge', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }).then(r => r.json()),
    historyRestore: (id) => fetch('src/Api/index.php?action=history_restore', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }).then(r => r.json()),
    saveCategory: (cat) => fetch('src/Api/index.php?action=save_category', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cat) }).then(r => r.json()),
    deleteCategory: (id) => fetch('src/Api/index.php?action=delete_category', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }).then(r => r.json()),
};

const categoriesList = () => {
    const cats = [...(state.categories || [])].sort((a, b) => a.order - b.order);
    return [{ id: ROOT_CATEGORY, name: 'Uncategorized', order: -1, system: true }, ...cats];
};
const matchesSearch = (card) => {
    if (!searchTerm) return true;
    const hay = ((card.name || '') + ' ' + (card.text || '')).toLowerCase();
    return hay.includes(searchTerm);
};
const cardsInCategoryRaw = (catId) => state.cards
    .filter(c => normalizeCategory(c.category_id) === normalizeCategory(catId))
    .sort((a, b) => a.order - b.order);
const cardsInCategory = (catId) => cardsInCategoryRaw(catId).filter(matchesSearch);
const nextOrderForCategory = (catId) => {
    const list = cardsInCategoryRaw(catId);
    if (!list.length) return 0;
    return Math.max(...list.map(c => c.order | 0)) + 1;
};
const resequenceCategory = (catId) => {
    const list = cardsInCategoryRaw(catId);
    list.forEach((c, i) => { c.order = i; });
};
const resequenceCategories = () => {
    state.categories.sort((a, b) => a.order - b.order).forEach((c, i) => { c.order = i; });
};
const persistCategoryOrders = (catId) => {
    const payload = cardsInCategoryRaw(catId).map(c => ({
        id: c.id,
        text: c.text,
        order: c.order,
        name: c.name || '',
        category_id: c.category_id || ROOT_CATEGORY
    }));
    try {
        fetch('src/Api/index.php?action=bulk_save', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cards: payload })
        });
    } catch { }
};
const resetDragMeta = () => {
    dragMeta = { dragging: null, id: null, startIndex: -1, placeholder: null, active: false, committed: false, catId: null, container: null, currentCatId: null, currentContainer: null };
};
const parsePx = (val = '') => {
    const num = parseFloat(String(val).replace('px', ''));
    return Number.isFinite(num) ? num : 0;
};
const categoryMinWidth = () => {
    const raw = getComputedStyle(document.documentElement).getPropertyValue('--category-min');
    return parsePx(raw) || 360;
};
const maxTabbedColumns = () => {
    const width = grid?.clientWidth || window.innerWidth || categoryMinWidth();
    return Math.max(1, Math.floor(width / Math.max(260, categoryMinWidth())));
};
const persistTabbedActive = () => {
    try { store.set(TAB_ACTIVE_KEY, tabbedActive); } catch { }
};
const syncTabbedActive = () => {
    const before = Array.isArray(tabbedActive) ? tabbedActive.join('|') : '';
    const ids = categoriesList().map(c => c.id);
    const allowed = new Set(ids);
    if (!Array.isArray(tabbedActive)) tabbedActive = [];
    tabbedActive = tabbedActive.filter(id => allowed.has(id));
    if (!tabbedActive.length && ids.length) {
        tabbedActive = ids.slice(0, Math.min(2, ids.length));
    }
    if (!tabbedActive.length && ids.length) tabbedActive = [ids[0]];
    const after = tabbedActive.join('|');
    if (before !== after) persistTabbedActive();
    return allowed;
};
const clampActiveToMax = () => {
    const max = maxTabbedColumns();
    if (tabbedActive.length > max) {
        tabbedActive = tabbedActive.slice(0, max);
        persistTabbedActive();
    }
    return max;
};
syncTabbedActive();

const uid = () => crypto.randomUUID?.() || (Date.now().toString(36) + Math.random().toString(36).slice(2));
// Multi-line preview: take text, normalize whitespace, take first N lines/characters
const previewSnippet = (t, maxLines = 9, maxChars = 600) => {
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
    if (out.length > maxChars) out = out.slice(0, maxChars - 1) + 'â€¦';
    return out;
};
const debounce = (fn, ms = 400) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const serverSaveDebounced = debounce(async (card) => {
    try {
        const res = await API.saveCard(card);
        if (res && res.ok && res.updated_at && card) {
            const local = state.cards.find(c => c.id === card.id);
            if (local) {
                local.updated_at = res.updated_at | 0;
                if (res.category_id) local.category_id = res.category_id;
                saveLocal();
            }
        }
    } catch { }
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
    committed: false,
    catId: null,
    container: null,
    currentCatId: null,
    currentContainer: null
};
let categoryDragMeta = { active: false, section: null, placeholder: null, mode: null };

function addCard(catId = ROOT_CATEGORY) {
    const categoryId = normalizeCategory(catId);
    const id = uid(); const order = nextOrderForCategory(categoryId);
    const card = { id, name: '', text: '', order, updated_at: Date.now() / 1000 | 0, category_id: categoryId };
    state.cards.push(card); saveLocal(); render(); queueServerSave(card);
    ensureCategorySelectOptions(categoryId);
    setTimeout(() => openModal(id), 0);
}

function moveCardToCategory(card, newCategoryId) {
    const target = normalizeCategory(newCategoryId);
    const prev = normalizeCategory(card.category_id);
    if (target === prev) return;
    // Normalize orders: remove from previous sequence then append to target
    resequenceCategory(prev);
    card.category_id = target;
    card.order = nextOrderForCategory(target);
    card.updated_at = Date.now() / 1000 | 0;
    resequenceCategory(target);
    saveLocal();
    queueServerSave(card);
    persistCategoryOrders(target);
    if (target !== prev) persistCategoryOrders(prev);
    render();
    ensureCategorySelectOptions(target);
}

async function renameCategory(cat) {
    const name = await showCategoryNameDialog({
        title: 'Rename category',
        subtitle: 'Give this category a clear name',
        initial: cat.name || ''
    });
    if (name === null) return;
    const trimmed = name.trim();
    if (!trimmed) return;
    cat.name = trimmed;
    cat.updated_at = Date.now() / 1000 | 0;
    saveLocal();
    try { await API.saveCategory({ id: cat.id, name: trimmed, order: cat.order }); } catch { }
    ensureCategorySelectOptions();
    render();
}

async function deleteCategory(catId) {
    const cat = state.categories.find(c => c.id === catId);
    const count = cardsInCategory(catId).length;
    const confirmed = await showConfirmDialog({
        title: 'Delete category',
        subtitle: count ? `${count} card${count === 1 ? '' : 's'} remain. Move or delete them first.` : 'This cannot be undone.',
        body: cat ? `Delete category "${cat.name || cat.id}"?` : 'Delete this category?',
        confirmText: count ? 'Cannot delete (not empty)' : 'Delete',
        danger: true,
        disableAccept: count > 0
    });
    if (!confirmed) return;
    try {
        const res = await API.deleteCategory(catId);
        if (!res.ok) { alert(res.error || 'Delete failed'); return; }
        state.categories = state.categories.filter(c => c.id !== catId);
        saveLocal(); render(); ensureCategorySelectOptions();
    } catch { alert('Delete failed'); }
}

async function bumpCategory(catId, delta) {
    const idx = state.categories.findIndex(c => c.id === catId);
    if (idx === -1) return;
    const swap = idx + delta;
    if (swap < 0 || swap >= state.categories.length) return;
    const tmp = state.categories[swap];
    state.categories[swap] = state.categories[idx];
    state.categories[idx] = tmp;
    resequenceCategories();
    saveLocal();
    // Persist new orders
    try {
        await Promise.all(state.categories.map(c => API.saveCategory({ id: c.id, name: c.name, order: c.order })));
    } catch { }
    render();
    ensureCategorySelectOptions();
}

function ensureCategorySelectOptions(selectedId) {
    if (!categorySelect) return;
    const current = normalizeCategory(selectedId || categorySelect.value || ROOT_CATEGORY);
    categorySelect.innerHTML = '';
    categoriesList().forEach(cat => {
        const opt = document.createElement('option');
        opt.value = cat.id;
        opt.textContent = cat.name || cat.id;
        categorySelect.appendChild(opt);
    });
    categorySelect.value = current;
}

// ===== Render grid =====
const setLayoutStatus = (text = '', warning = false) => {
    if (!layoutStatusEl) return;
    layoutStatusEl.textContent = text;
    layoutStatusEl.classList.toggle('warning', !!warning);
};
function render() {
    grid.innerHTML = '';
    const allowed = syncTabbedActive();
    applyCategoryLayout(categoryLayoutMode);
    if (categoryLayoutMode === CATEGORY_LAYOUTS.STACKED) {
        renderStackedView();
    } else {
        renderTabbedView();
    }
    renderLayoutChrome(allowed);
}

function renderLayoutChrome(allowedSet = syncTabbedActive()) {
    if (!layoutBar) return;
    layoutBar.setAttribute('data-mode', categoryLayoutMode);
    const max = clampActiveToMax();
    renderTabBar(allowedSet, max);
    if (categoryLayoutMode === CATEGORY_LAYOUTS.STACKED) {
        setLayoutStatus(`Full mode - ${state.categories.length + 1} categories`, false);
    }
}

function renderTabbedView() {
    const cats = categoriesList();
    const byId = Object.fromEntries(cats.map(c => [c.id, c]));
    const max = clampActiveToMax();
    const visibleIds = tabbedActive.slice(0, max);
    if (!visibleIds.length && cats.length) {
        visibleIds.push(cats[0].id);
        tabbedActive = [...visibleIds];
        persistTabbedActive();
    }
    visibleIds.forEach(id => {
        const cat = byId[id];
        if (cat) renderTabbedCategory(cat);
    });
    setLayoutStatus(`Tabbed mode - showing ${visibleIds.length} of ${max} columns`, tabbedActive.length > max);
}

function renderStackedView() {
    categoriesList().forEach(cat => renderStackedCategory(cat));
}

function renderStackedCategory(cat) {
    const section = document.createElement('section');
    section.className = 'category-section glass elevate';
    section.dataset.categoryId = cat.id;

    const header = document.createElement('div');
    header.className = 'category-header';
    const heading = document.createElement('div');
    heading.className = 'category-heading';
    if (cat.id !== ROOT_CATEGORY) {
        const dragHandle = document.createElement('button');
        dragHandle.className = 'icon-btn category-drag-handle';
        dragHandle.title = isCoarsePointer ? 'Drag categories from desktop; mobile coming soon' : 'Drag to reorder category';
        dragHandle.textContent = ICONS.DRAG;
        heading.appendChild(dragHandle);
        attachCategoryDrag(section, dragHandle);
    } else {
        const spacer = document.createElement('div');
        spacer.style.width = '34px';
        spacer.setAttribute('aria-hidden', 'true');
        heading.appendChild(spacer);
    }

    const toggle = document.createElement('button');
    toggle.className = 'category-toggle';
    const collapsed = stackedCollapsed.has(cat.id);
    toggle.textContent = collapsed ? ICONS.COLLAPSE : ICONS.EXPAND;
    toggle.title = collapsed ? 'Expand category' : 'Collapse category';
    toggle.addEventListener('click', () => {
        if (stackedCollapsed.has(cat.id)) stackedCollapsed.delete(cat.id); else stackedCollapsed.add(cat.id);
        store.set(STACKED_COLLAPSED_KEY, Array.from(stackedCollapsed));
        render();
    });
    heading.appendChild(toggle);

    const title = document.createElement('div');
    title.className = 'category-title';
    title.textContent = (cat.name || '').trim() || (cat.id === ROOT_CATEGORY ? 'Uncategorized' : '-');
    heading.appendChild(title);

    const meta = document.createElement('div');
    meta.className = 'category-meta';
    const cardCount = cardsInCategoryRaw(cat.id).length;
    const badge = document.createElement('span');
    badge.className = 'category-badge';
    badge.textContent = `${cardCount} card${cardCount === 1 ? '' : 's'}`;
    meta.appendChild(badge);
    heading.appendChild(meta);

    header.appendChild(heading);

    const actions = document.createElement('div');
    actions.className = 'category-actions';
    const addCardBtn = document.createElement('button');
    addCardBtn.className = 'icon-btn';
    addCardBtn.title = 'Add card to this category';
    addCardBtn.textContent = ICONS.PLUS;
    addCardBtn.addEventListener('click', () => addCard(cat.id));
    actions.appendChild(addCardBtn);

    if (cat.id !== ROOT_CATEGORY) {
        const upBtn = document.createElement('button');
        upBtn.className = 'icon-btn';
        upBtn.title = 'Move category up';
        upBtn.textContent = ICONS.UP;
        upBtn.addEventListener('click', () => bumpCategory(cat.id, -1));

        const downBtn = document.createElement('button');
        downBtn.className = 'icon-btn';
        downBtn.title = 'Move category down';
        downBtn.textContent = ICONS.DOWN;
        downBtn.addEventListener('click', () => bumpCategory(cat.id, 1));

        const renameBtn = document.createElement('button');
        renameBtn.className = 'icon-btn';
        renameBtn.title = 'Rename category';
        renameBtn.textContent = '✎';
        renameBtn.addEventListener('click', () => renameCategory(cat));
        actions.appendChild(renameBtn);

        actions.appendChild(upBtn);
        actions.appendChild(downBtn);

        const delBtn = document.createElement('button');
        delBtn.className = 'icon-btn danger';
        delBtn.title = 'Delete category (only when empty)';
        delBtn.textContent = '🗑';
        delBtn.disabled = cardsInCategory(cat.id).length > 0;
        delBtn.addEventListener('click', () => deleteCategory(cat.id));
        actions.appendChild(delBtn);
    }
    header.appendChild(actions);

    section.appendChild(header);
    const subGrid = document.createElement('div');
    subGrid.className = 'grid cards-grid subgrid';
    subGrid.dataset.categoryId = cat.id;
    if (!collapsed) {
        section.appendChild(subGrid);
        renderCategoryCards(cat.id, subGrid);
    } else {
        section.appendChild(subGrid);
        subGrid.style.display = 'none';
    }
    section.classList.toggle('collapsed', collapsed);
    grid.appendChild(section);
}

function renderTabbedCategory(cat) {
    const section = document.createElement('section');
    section.className = 'category-section glass elevate';
    section.dataset.categoryId = cat.id;

    const header = document.createElement('div');
    header.className = 'category-header';
    const heading = document.createElement('div');
    heading.className = 'category-heading';
    if (cat.id !== ROOT_CATEGORY) {
        const dragHandle = document.createElement('button');
        dragHandle.className = 'icon-btn category-drag-handle';
        dragHandle.title = isCoarsePointer ? 'Drag categories from desktop; mobile coming soon' : 'Drag to reorder column';
        dragHandle.textContent = ICONS.DRAG;
        heading.appendChild(dragHandle);
        attachCategoryDrag(section, dragHandle);
    } else {
        const spacer = document.createElement('div');
        spacer.style.width = '34px';
        spacer.setAttribute('aria-hidden', 'true');
        heading.appendChild(spacer);
    }

    const toggle = document.createElement('button');
    toggle.className = 'category-toggle';
    toggle.textContent = 'Hide';
    toggle.title = 'Remove from tabbed view';
    toggle.addEventListener('click', () => {
        const idx = tabbedActive.indexOf(cat.id);
        if (idx !== -1 && tabbedActive.length > 1) {
            tabbedActive.splice(idx, 1);
            persistTabbedActive();
            render();
        }
    });
    heading.appendChild(toggle);

    const title = document.createElement('div');
    title.className = 'category-title';
    title.textContent = (cat.name || '').trim() || (cat.id === ROOT_CATEGORY ? 'Uncategorized' : '-');
    heading.appendChild(title);

    const meta = document.createElement('div');
    meta.className = 'category-meta';
    const cardCount = cardsInCategoryRaw(cat.id).length;
    const badge = document.createElement('span');
    badge.className = 'category-badge';
    badge.textContent = `${cardCount} card${cardCount === 1 ? '' : 's'}`;
    meta.appendChild(badge);
    heading.appendChild(meta);

    header.appendChild(heading);

    const actions = document.createElement('div');
    actions.className = 'category-actions';
    const addCardBtn = document.createElement('button');
    addCardBtn.className = 'icon-btn';
    addCardBtn.title = 'Add card to this category';
    addCardBtn.textContent = ICONS.PLUS;
    addCardBtn.addEventListener('click', () => addCard(cat.id));
    actions.appendChild(addCardBtn);

    if (cat.id !== ROOT_CATEGORY) {
        const upBtn = document.createElement('button');
        upBtn.className = 'icon-btn';
        upBtn.title = 'Move category left';
        upBtn.textContent = ICONS.UP;
        upBtn.addEventListener('click', () => bumpCategory(cat.id, -1));

        const downBtn = document.createElement('button');
        downBtn.className = 'icon-btn';
        downBtn.title = 'Move category right';
        downBtn.textContent = ICONS.DOWN;
        downBtn.addEventListener('click', () => bumpCategory(cat.id, 1));

        const renameBtn = document.createElement('button');
        renameBtn.className = 'icon-btn';
        renameBtn.title = 'Rename category';
        renameBtn.textContent = '✎';
        renameBtn.addEventListener('click', () => renameCategory(cat));
        actions.appendChild(renameBtn);

        actions.appendChild(upBtn);
        actions.appendChild(downBtn);

        const delBtn = document.createElement('button');
        delBtn.className = 'icon-btn danger';
        delBtn.title = 'Delete category (only when empty)';
        delBtn.textContent = '🗑';
        delBtn.disabled = cardsInCategory(cat.id).length > 0;
        delBtn.addEventListener('click', () => deleteCategory(cat.id));
        actions.appendChild(delBtn);
    }
    header.appendChild(actions);

    section.appendChild(header);
    const subGrid = document.createElement('div');
    subGrid.className = 'grid cards-grid subgrid';
    subGrid.dataset.categoryId = cat.id;
    section.appendChild(subGrid);
    renderCategoryCards(cat.id, subGrid);
    grid.appendChild(section);
}

function renderTabBar(allowedSet = new Set(), max = clampActiveToMax()) {
    if (!categoryTabBar) return;
    categoryTabBar.innerHTML = '';
    const activeSet = new Set(tabbedActive);
    categoriesList().forEach(cat => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tab-pill icon-btn';
        const isActive = activeSet.has(cat.id);
        btn.classList.toggle('active', isActive);
        btn.setAttribute('role', 'tab');
        btn.setAttribute('aria-pressed', String(isActive));
        btn.dataset.categoryId = cat.id;
        btn.textContent = cat.name || cat.id;
        const blocked = !isActive && tabbedActive.length >= max;
        btn.disabled = blocked && categoryLayoutMode === CATEGORY_LAYOUTS.HORIZONTAL;
        btn.title = blocked ? `Hide a column to show ${cat.name || cat.id}` : (isActive ? 'Click to hide' : 'Click to show');
        btn.addEventListener('click', () => toggleTabbedCategory(cat.id, max, allowedSet));
        categoryTabBar.appendChild(btn);
    });
    categoryTabBar.classList.toggle('muted', categoryLayoutMode === CATEGORY_LAYOUTS.STACKED);
}

function toggleTabbedCategory(catId, max = clampActiveToMax(), allowedSet = syncTabbedActive()) {
    if (!allowedSet.has(catId)) return;
    const idx = tabbedActive.indexOf(catId);
    if (idx !== -1) {
        if (tabbedActive.length === 1) {
            setLayoutStatus('At least one category must remain visible.', true);
            return;
        }
        tabbedActive.splice(idx, 1);
    } else {
        if (tabbedActive.length >= max) {
            const name = (categoriesList().find(c => c.id === catId)?.name) || 'this tab';
            setLayoutStatus(`Width fits ${max} column${max === 1 ? '' : 's'}. Hide one to add ${name}.`, true);
            return;
        }
        tabbedActive.push(catId);
    }
    persistTabbedActive();
    render();
}

function renderCategoryCards(catId, subGrid) {
    subGrid.innerHTML = '';
    registerCardContainer(subGrid, catId);
    const cards = cardsInCategory(catId);
    if (!cards.length) {
        const empty = document.createElement('div');
        empty.className = 'muted';
        empty.style.padding = '8px 12px 16px';
        empty.textContent = searchTerm ? 'No matches' : 'No cards yet';
        subGrid.appendChild(empty);
        return;
    }
    cards.forEach(card => {
        const el = document.createElement('div');
        el.className = 'card';
        el.dataset.id = card.id;
        el.dataset.categoryId = catId;

        const handle = document.createElement('div');
        handle.className = 'card-handle';
        const grab = document.createElement('div');
        grab.className = 'grab';
        grab.textContent = ICONS.DRAG;
        grab.title = 'Drag';
        const title = document.createElement('div');
        title.className = 'title';
        title.textContent = (card.name || '').trim() || '-';
        handle.append(grab, title);

        const blurb = document.createElement('div');
        blurb.className = 'card-blurb';
        blurb.textContent = previewSnippet(card.text) || '.';

        el.appendChild(handle);
        el.appendChild(blurb);
        subGrid.appendChild(el);

        handle.addEventListener('click', (e) => { if (e.target === grab) return; openModal(card.id); });
        blurb.addEventListener('click', () => openModal(card.id));

        attachCardDrag(el, grab, catId, subGrid);
    });
}

function registerCardContainer(container, catId) {
    container.addEventListener('dragover', (e) => handleCardDragOver(e, catId, container));
    container.addEventListener('drop', (e) => { if (dragMeta.active) e.preventDefault(); });
}

// Simplified drag/drop per category grid (desktop)
function attachCardDrag(cardEl, grabEl, catId, container) {
    if (isCoarsePointer) {
        grabEl.title = 'Drag on desktop; mobile movement coming soon';
        grabEl.removeAttribute('draggable');
        return;
    }
    grabEl.setAttribute('draggable', 'true');
    grabEl.addEventListener('dragstart', (e) => {
        dragMeta = {
            dragging: cardEl,
            id: cardEl.dataset.id,
            startIndex: [...container.children].indexOf(cardEl),
            placeholder: makePlaceholder(cardEl.getBoundingClientRect().height),
            active: true,
            committed: false,
            catId,
            container,
            currentCatId: catId,
            currentContainer: container
        };
        cardEl.classList.add('dragging');
        container.insertBefore(dragMeta.placeholder, cardEl.nextSibling);
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragMeta.id); } catch { }
    });
    grabEl.addEventListener('dragend', finalizeCardDrag);
}

function handleCardDragOver(e, catId, container) {
    if (!dragMeta.active) return;
    e.preventDefault();
    const ph = dragMeta.placeholder;
    if (!ph) return;
    dragMeta.currentCatId = catId;
    dragMeta.currentContainer = container;
    const target = e.target.closest('.card');
    if (!target || target === ph || target === dragMeta.dragging) {
        if (ph.parentNode !== container) container.appendChild(ph);
        return;
    }
    container.insertBefore(ph, target);
}

function finalizeCardDrag() {
    if (!dragMeta.active) { resetDragMeta(); return; }
    const ph = dragMeta.placeholder;
    const targetContainer = dragMeta.currentContainer || dragMeta.container;
    const targetCatId = dragMeta.currentCatId || dragMeta.catId;
    const movedEl = dragMeta.dragging;
    if (ph && targetContainer && movedEl) {
        targetContainer.insertBefore(movedEl, ph);
    }
    ph?.remove();
    if (!movedEl || !targetContainer) { resetDragMeta(); render(); return; }
    const nowSec = Date.now() / 1000 | 0;
    const targetIds = [...targetContainer.querySelectorAll('.card')].map(c => c.dataset.id);
    const targetCards = cardsInCategoryRaw(targetCatId);
    const targetById = Object.fromEntries(targetCards.map(c => [c.id, c]));
    targetIds.forEach((id, i) => { const c = targetById[id]; if (c) { c.order = i; c.updated_at = nowSec; c.category_id = targetCatId; } });
    if (targetCatId !== dragMeta.catId) {
        const sourceIds = [...(dragMeta.container?.querySelectorAll('.card') || [])].map(c => c.dataset.id);
        const sourceCards = cardsInCategoryRaw(dragMeta.catId);
        const sourceById = Object.fromEntries(sourceCards.map(c => [c.id, c]));
        sourceIds.forEach((id, i) => { const c = sourceById[id]; if (c) { c.order = i; c.updated_at = nowSec; } });
    }
    saveLocal();
    persistCategoryOrders(targetCatId);
    if (targetCatId !== dragMeta.catId) persistCategoryOrders(dragMeta.catId);
    resetDragMeta();
    render();
}

function attachCategoryDrag(section, handle) {
    if (!handle || isCoarsePointer) { if (handle) handle.disabled = true; return; }
    handle.setAttribute('draggable', 'true');
    handle.addEventListener('dragstart', (e) => {
        const placeholder = makeCategoryPlaceholder(section.getBoundingClientRect().height);
        categoryDragMeta = { active: true, section, placeholder, mode: categoryLayoutMode };
        section.classList.add('dragging');
        grid.insertBefore(categoryDragMeta.placeholder, section.nextSibling);
        e.dataTransfer.effectAllowed = 'move';
    });
    handle.addEventListener('dragend', finalizeCategoryDrag);
}

function handleCategoryDragOver(e) {
    if (!categoryDragMeta.active) return;
    e.preventDefault();
    const ph = categoryDragMeta.placeholder;
    if (!ph) return;
    const targetSection = e.target.closest('.category-section');
    if (!targetSection || targetSection === ph || targetSection === categoryDragMeta.section) return;
    grid.insertBefore(ph, targetSection);
}

function finalizeCategoryDrag() {
    if (!categoryDragMeta.active) return;
    const { placeholder: ph, section, mode } = categoryDragMeta;
    if (ph && grid.contains(ph) && section) {
        grid.insertBefore(section, ph);
    }
    ph?.remove();
    section?.classList.remove('dragging');
    const layoutMode = mode || categoryLayoutMode;
    if (layoutMode === CATEGORY_LAYOUTS.HORIZONTAL) {
        const ids = [...grid.querySelectorAll('.category-section')]
            .map(s => s.dataset.categoryId)
            .filter(Boolean);
        if (ids.length) {
            tabbedActive = ids;
            persistTabbedActive();
        }
        categoryDragMeta = { active: false, section: null, placeholder: null, mode: null };
        render();
        return;
    }
    const nowSec = Date.now() / 1000 | 0;
    const ids = [...grid.querySelectorAll('.category-section')]
        .map(s => s.dataset.categoryId)
        .filter(id => id && id !== ROOT_CATEGORY);
    ids.forEach((id, i) => { const c = state.categories.find(cat => cat.id === id); if (c) { c.order = i; c.updated_at = nowSec; } });
    saveLocal();
    try { state.categories.forEach(c => API.saveCategory({ id: c.id, name: c.name, order: c.order })); } catch { }
    categoryDragMeta = { active: false, section: null, placeholder: null, mode: null };
    render();
}

grid?.addEventListener('dragover', handleCategoryDragOver);
grid?.addEventListener('drop', (e) => { if (categoryDragMeta.active) e.preventDefault(); });

// Cancel if ESC pressed during drag
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        let rerender = false;
        if (dragMeta.active) {
            dragMeta.placeholder?.remove();
            resetDragMeta();
            rerender = true;
        }
        if (categoryDragMeta.active) {
            categoryDragMeta.placeholder?.remove();
            categoryDragMeta = { active: false, section: null, placeholder: null, mode: null };
            rerender = true;
        }
        if (rerender) render();
    }
});
// ===== Modal =====
function openModal(id) {
    currentId = id;
    const card = state.cards.find(c => c.id === id);
    if (!card) return;
    editor.value = card.text || '';
    nameInput.value = (card.name || '').trim();
    ensureCategorySelectOptions(card.category_id);
    if (categorySelect) categorySelect.value = normalizeCategory(card.category_id);
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    editor.focus();
    trashBtn.classList.remove('armed'); trashArmed = false;
}
function closeModal() {
    currentId = null;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('fullscreen');
    trashBtn.classList.remove('armed'); trashArmed = false;
}
backdrop?.addEventListener('click', (e) => { if (e.target.dataset.close) closeModal(); });
closeModalBtn?.addEventListener('click', closeModal);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

fullscreenBtn?.addEventListener('click', () => {
    editor.classList.toggle('fullscreen');
    fullscreenBtn.textContent = editor.classList.contains('fullscreen') ? ICONS.FULLSCREEN_EXIT : ICONS.FULLSCREEN_ENTER;
});

// Save text on input
editor?.addEventListener('input', () => {
    if (!currentId) return;
    const card = state.cards.find(c => c.id === currentId);
    card.text = editor.value;
    saveLocal();
    queueServerSave(card);
    const el = grid.querySelector(`.card[data-id="${card.id}"] .card-blurb`);
    if (el) el.textContent = previewSnippet(card.text) || 'â€¦';
});

// Save name on blur & Enter
nameInput?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); nameInput.blur(); }
});
nameInput?.addEventListener('blur', () => {
    if (!currentId) return;
    const card = state.cards.find(c => c.id === currentId);
    const val = nameInput.value.trim();
    card.name = val;
    saveLocal();
    queueServerSave(card);
    const t = grid.querySelector(`.card[data-id="${card.id}"] .card-handle .title`);
    if (t) t.textContent = val || '-';
});
categorySelect?.addEventListener('change', () => {
    if (!currentId) return;
    const card = state.cards.find(c => c.id === currentId);
    if (!card) return;
    moveCardToCategory(card, categorySelect.value);
});

function queueServerSave(card) {
    serverSaveDebounced({ id: card.id, name: card.name || '', text: card.text, order: card.order | 0, category_id: card.category_id || ROOT_CATEGORY });
}
function saveLocal() { store.set('cards_state', state); }

// Safety net
window.addEventListener('beforeunload', () => { try { API.bulkSave(state.cards); } catch { } });

// ===== History drawer =====
historyBtn?.addEventListener('click', async () => {
    try {
        const { orphans = [] } = await API.history();
        historyList.innerHTML = '';
        // Build tabs inside drawer header if not present
        const drawerHeader = drawer.querySelector('.drawer-header');
        if (drawerHeader && !drawerHeader.querySelector('.history-tabs')) {
            const tabs = document.createElement('div');
            tabs.className = 'history-tabs inline';
            tabs.innerHTML = `<button class="icon-btn tab active" data-tab="orphans" title="Deleted cards still in DB">Deleted</button><button class="icon-btn tab" data-tab="versions" title="Per-card snapshots">Versions</button>`;
            // Insert tabs before the spacer/close if we add a spacer
            // Create a spacer to push close button right if not present
            if (!drawerHeader.querySelector('.header-spacer')) {
                const spacer = document.createElement('div'); spacer.className = 'header-spacer';
                drawerHeader.insertBefore(spacer, drawerHeader.lastElementChild);
            }
            drawerHeader.insertBefore(tabs, drawerHeader.querySelector('.header-spacer'));
            tabs.querySelectorAll('.tab').forEach(t => t.addEventListener('click', (e) => {
                tabs.querySelectorAll('.tab').forEach(b => b.classList.remove('active')); e.currentTarget.classList.add('active');
                const tab = e.currentTarget.getAttribute('data-tab');
                if (tab === 'orphans') { historyList.style.display = 'block'; versionsPanel.style.display = 'none'; }
                else { historyList.style.display = 'none'; versionsPanel.style.display = 'block'; }
            }));
        }
        if (!versionsPanel) {
            versionsPanel = document.createElement('div');
            versionsPanel.className = 'versions-panel';
            versionsPanel.style.display = 'none';
            versionsPanel.innerHTML = `<div class="versions-header"><select id="versionsCardSelect"></select><button class="icon-btn" id="snapshotBtn">Snapshot Now</button></div><div id="versionsList" class="versions-list muted">Select a card to load versionsâ€¦</div><div id="versionDiff" class="version-diff"></div>`;
            historyList.parentElement.appendChild(versionsPanel);
            // Populate select with current in-memory cards
            const select = versionsPanel.querySelector('#versionsCardSelect');
            state.cards.sort((a, b) => a.order - b.order).forEach(c => {
                const opt = document.createElement('option'); opt.value = c.id; opt.textContent = (c.name || '') || c.id.slice(0, 8); select.appendChild(opt);
            });
            select.addEventListener('change', () => loadVersions(select.value));
            versionsPanel.querySelector('#snapshotBtn').addEventListener('click', async () => {
                if (!select.value) return; await fetch('src/Api/index.php?action=version_snapshot', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: select.value }) }); loadVersions(select.value);
            });
        }
        if (!orphans.length) {
            historyList.textContent = 'No DB-only cards found.';
        } else {
            orphans.forEach(o => {
                const row = document.createElement('div');
                row.className = 'history-row';
                const name = (o.name || '').trim() || 'â€”';
                const preview = (o.txt || '').slice(0, 120).replace(/\s+/g, ' ');
                row.innerHTML = `
          <div><strong>${name}</strong> <code>${o.id}</code></div>
          <div class="muted">${preview}</div>
          <div class="row-actions">
            <button class="icon-btn restore" data-id="${o.id}">Restore</button>
            <button class="icon-btn danger purge" data-id="${o.id}">Purge</button>
          </div>`;
                historyList.appendChild(row);
            });
            historyList.querySelectorAll('.restore').forEach(b => b.addEventListener('click', async (e) => {
                const id = e.currentTarget.dataset.id; await API.historyRestore(id); alert('Restored ' + id);
            }));
            historyList.querySelectorAll('.purge').forEach(b => b.addEventListener('click', async (e) => {
                const id = e.currentTarget.dataset.id; if (!confirm('Permanently delete?')) return;
                await API.historyPurge(id); alert('Purged ' + id);
            }));
        }
        drawer.classList.remove('hidden'); drawer.setAttribute('aria-hidden', 'false');
        // Initialize versions select default to first card
        if (versionsPanel) {
            const sel = versionsPanel.querySelector('#versionsCardSelect');
            if (sel && sel.options.length && !sel.value) { sel.value = sel.options[0].value; loadVersions(sel.value); }
        }
    } catch { alert('History load failed'); }
});
closeHistory?.addEventListener('click', () => { drawer.classList.add('hidden'); drawer.setAttribute('aria-hidden', 'true'); });
// Backdrop outside click to close (history drawer)
drawer?.addEventListener('click', (e) => {
    if (e.target && e.target.classList.contains('drawer-backdrop')) {
        drawer.classList.add('hidden'); drawer.setAttribute('aria-hidden', 'true');
    }
});

async function loadVersions(cardId) {
    if (!cardId) return; versionsState.cardId = cardId; const listEl = versionsPanel.querySelector('#versionsList'); listEl.textContent = 'Loading versions…';
    try {
        const res = await fetch(`src/Api/index.php?action=versions_list&id=${encodeURIComponent(cardId)}&limit=25`);
        const j = await res.json(); if (!j.ok) throw new Error(j.error || 'fail');
        versionsState.versions = j.versions || [];
        if (!versionsState.versions.length) { listEl.textContent = 'No versions captured yet.'; return; }
        listEl.innerHTML = '';
        versionsState.versions.forEach(v => {
            const row = document.createElement('div'); row.className = 'version-row';
            const when = new Date(v.captured_at * 1000).toLocaleString();
            row.innerHTML = `<div class="v-meta"><strong>#${v.version_id}</strong> <span>${when}</span> <span class="badge">${v.origin}</span> <span class="muted">${(v.size || 0)} chars</span></div>`;
            row.addEventListener('click', () => showVersionDiff(v.version_id));
            listEl.appendChild(row);
        });
    } catch (e) { listEl.textContent = 'Load failed'; }
}

async function showVersionDiff(versionId) {
    const diffEl = versionsPanel.querySelector('#versionDiff'); diffEl.textContent = 'Loading…';
    try {
        const res = await fetch(`src/Api/index.php?action=version_get&version_id=${versionId}`);
        const j = await res.json(); if (!j.ok) throw new Error(j.error || 'fail');
        const v = j.version; versionsState.selected = v;
        const currentCard = state.cards.find(c => c.id === versionsState.cardId);
        const currentText = currentCard ? currentCard.text || '' : '';
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
            const diffLines = computeLineDiff(v.txt || '', currentText);
            const html = diffLines.map(d => {
                const safe = escapeHtml(d.text);
                if (d.type === 'add') return `<div class="diff-line add">+ ${safe}</div>`;
                if (d.type === 'del') return `<div class="diff-line del">- ${safe}</div>`;
                return `<div class="diff-line same">  ${safe}</div>`;
            }).join('');
            view.setAttribute('data-view', 'diff');
            view.innerHTML = `<pre class="diff-block">${html}</pre>`;
        };
        // Mode switching
        diffEl.querySelectorAll('.mode-btn').forEach(btn => btn.addEventListener('click', e => {
            diffEl.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
            e.currentTarget.classList.add('active');
            const mode = e.currentTarget.getAttribute('data-mode');
            const view = diffEl.querySelector('.version-view');
            if (!view) return;
            if (mode === 'raw') {
                view.setAttribute('data-view', 'raw');
                view.innerHTML = `<pre class="version-raw"><code>${escapeHtml(v.txt)}</code></pre>`;
            } else {
                renderDiff();
            }
        }));
        diffEl.querySelector('[data-act="restore"]').addEventListener('click', () => restoreVersion(v.version_id));
        diffEl.querySelector('[data-act="copy"]').addEventListener('click', () => { navigator.clipboard.writeText(v.txt || ''); alert('Copied'); });
    } catch (e) { diffEl.textContent = 'Failed to load version'; }
}

async function restoreVersion(versionId) {
    if (!confirm('Restore this version?')) return; const res = await fetch('src/Api/index.php?action=version_restore', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ version_id: versionId }) });
    try { const j = await res.json(); if (!j.ok) throw new Error(j.error || 'fail'); alert('Restored'); window.dispatchEvent(new Event('renote:force-sync')); } catch { alert('Restore failed'); }
}

function escapeHtml(s) { return (s || '').replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c])); }

// ===== Simple line diff (LCS based) =====
// Returns array of {type: 'same'|'add'|'del', text}
function computeLineDiff(oldText, newText) {
    if (oldText === newText) return oldText.split(/\r?\n/).map(t => ({ type: 'same', text: t }));
    const a = oldText.split(/\r?\n/);
    const b = newText.split(/\r?\n/);
    const n = a.length, m = b.length;
    // Guard for huge texts â€“ fall back to simple comparison to avoid O(n*m) blowup
    if (n * m > 160000) { // ~400x400 lines threshold
        // Fallback: mark differing lines naively
        const max = Math.max(n, m);
        const out = [];
        for (let i = 0; i < max; i++) {
            const av = a[i]; const bv = b[i];
            if (av === bv) out.push({ type: 'same', text: av ?? '' });
            else {
                if (av !== undefined) out.push({ type: 'del', text: av });
                if (bv !== undefined) out.push({ type: 'add', text: bv });
            }
        }
        return out;
    }
    const dp = Array(n + 1); for (let i = 0; i <= n; i++) { dp[i] = Array(m + 1).fill(0); } // LCS lengths
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            dp[i][j] = a[i] === b[j] ? 1 + dp[i + 1][j + 1] : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }
    const out = [];
    let i = 0, j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) { out.push({ type: 'same', text: a[i] }); i++; j++; }
        else if (dp[i + 1][j] >= dp[i][j + 1]) { out.push({ type: 'del', text: a[i++] }); }
        else { out.push({ type: 'add', text: b[j++] }); }
    }
    while (i < n) out.push({ type: 'del', text: a[i++] });
    while (j < m) out.push({ type: 'add', text: b[j++] });
    // Collapse trivial noise: combine consecutive adds/dels separated by empty same lines (optional future)
    return out;
}

// ===== Initial render =====
render();
ensureCategorySelectOptions();

// ===== Reconciliation Logic =====
async function reconcileWithServer(force = false) {
    try {
        const remote = await API.state();
        if (!remote || !Array.isArray(remote.cards)) return;

        // Merge categories
        const remoteCats = Array.isArray(remote.categories) ? remote.categories : [];
        const localCatMap = Object.fromEntries((state.categories || []).map(c => [c.id, c]));
        let catsChanged = false;
        const mergedCats = [];
        for (const rc of remoteCats) {
            const lc = localCatMap[rc.id];
            if (!lc) {
                mergedCats.push({ id: rc.id, name: rc.name || '', order: rc.order | 0, updated_at: rc.updated_at | 0 });
                catsChanged = true;
            } else {
                const needs = (rc.updated_at | 0) > (lc.updated_at | 0) || lc.name !== rc.name || (lc.order | 0) !== (rc.order | 0);
                if (needs) {
                    lc.name = rc.name || '';
                    lc.order = rc.order | 0;
                    lc.updated_at = rc.updated_at | 0;
                    catsChanged = true;
                }
                mergedCats.push(lc);
                delete localCatMap[rc.id];
            }
        }
        // Keep/persist local-only categories (created offline)
        Object.values(localCatMap).forEach(c => {
            mergedCats.push(c);
            catsChanged = true;
            try { API.saveCategory({ id: c.id, name: c.name, order: c.order }); } catch { }
        });
        mergedCats.sort((a, b) => a.order - b.order).forEach((c, i) => { c.order = (c.id === ROOT_CATEGORY) ? c.order : i; });

        // Merge cards
        const localMap = Object.fromEntries(state.cards.map(c => [c.id, c]));
        let changed = false;
        const merged = [];
        for (const rc of remote.cards) {
            const lc = localMap[rc.id];
            const cat = normalizeCategory(rc.category_id);
            if (!lc) {
                merged.push({ id: rc.id, name: rc.name || '', text: rc.text || '', order: rc.order | 0, updated_at: rc.updated_at | 0, category_id: cat });
                changed = true;
            } else {
                const needsUpdate = (rc.updated_at | 0) > (lc.updated_at | 0) || (!lc.name && rc.name) || normalizeCategory(lc.category_id) !== cat;
                if (needsUpdate) {
                    lc.name = rc.name || '';
                    lc.text = rc.text || '';
                    lc.order = rc.order | 0;
                    lc.updated_at = rc.updated_at | 0;
                    lc.category_id = cat;
                    changed = true;
                }
                merged.push(lc);
                delete localMap[rc.id];
            }
        }
        const leftovers = Object.values(localMap);
        if (leftovers.length) {
            const nowSec = Date.now() / 1000 | 0;
            for (const c of leftovers) {
                if (!c.updated_at) {
                    queueServerSave(c);
                    merged.push(c);
                    continue;
                }
                if ((nowSec - c.updated_at) < 5) {
                    merged.push(c);
                } else {
                    changed = true;
                }
            }
        }
        // Normalize order within each category
        const grouped = {};
        merged.forEach(c => {
            const cid = normalizeCategory(c.category_id);
            if (!grouped[cid]) grouped[cid] = [];
            grouped[cid].push(c);
        });
        Object.values(grouped).forEach(list => list.sort((a, b) => a.order - b.order).forEach((c, i) => { c.order = i; }));

        if (catsChanged || force) {
            state.categories = mergedCats.filter(c => c.id !== ROOT_CATEGORY);
            resequenceCategories();
            changed = true;
        }
        if (changed || force) {
            state.cards = merged;
            saveLocal();
            render();
            ensureCategorySelectOptions();
        }
        initialSynced = true;
    } catch (e) {
        // silent – offline maybe
    }
}
// Perform initial reconciliation shortly after boot (allow first paint), then periodic lightweight sync
setTimeout(() => reconcileWithServer(true), 150);
setInterval(() => reconcileWithServer(false), 15000); // every 15s

// If page loaded with no titles (all blank) attempt an earlier quick sync
if (state.cards.some(c => !c.name)) { reconcileWithServer(false); }

// Manual force sync (triggered after flush button completes on server)
window.addEventListener('renote:force-sync', () => {
    reconcileWithServer(true);
});
