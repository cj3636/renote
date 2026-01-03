// UI helpers extracted from app.js (kept in global scope for non-module usage).

function makePlaceholder(height = 0) {
    const ph = document.createElement('div');
    ph.className = 'card-placeholder';
    ph.style.height = height ? `${height}px` : '';
    return ph;
}

function makeCategoryPlaceholder(height = 0) {
    const ph = document.createElement('div');
    ph.className = 'category-placeholder category-section';
    ph.style.height = height ? `${height}px` : '';
    return ph;
}

function hideOverlay(modalEl) {
    if (!modalEl) return;
    modalEl.classList.add('hidden');
    modalEl.setAttribute('aria-hidden', 'true');
}

function showCategoryNameDialog(options = {}) {
    return new Promise(resolve => {
        const { title = 'Category', subtitle = 'Name this category', initial = '' } = options;
        if (!window.categoryModal || !window.categoryForm || !window.categoryNameInput) {
            const fallback = prompt(title, initial);
            resolve(fallback ? fallback.trim() : null);
            return;
        }
        if (window.categoryTitleEl) window.categoryTitleEl.textContent = title;
        if (window.categorySubtitleEl) window.categorySubtitleEl.textContent = subtitle;
        window.categoryNameInput.value = initial || '';
        window.categoryModal.classList.remove('hidden');
        window.categoryModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => window.categoryNameInput.focus(), 20);
        const closers = window.categoryModal.querySelectorAll('[data-close]');
        const close = (val = null) => {
            cleanup();
            hideOverlay(window.categoryModal);
            resolve(val);
        };
        const onSubmit = (e) => {
            e.preventDefault();
            const val = window.categoryNameInput.value.trim();
            if (!val) { window.categoryNameInput.focus(); return; }
            close(val);
        };
        const onCancel = (e) => { if (e) e.preventDefault(); close(null); };
        const onKey = (e) => { if (e.key === 'Escape') onCancel(e); };
        window.categoryForm.addEventListener('submit', onSubmit);
        closers.forEach(el => el.addEventListener('click', onCancel));
        document.addEventListener('keydown', onKey);
        function cleanup() {
            window.categoryForm.removeEventListener('submit', onSubmit);
            closers.forEach(el => el.removeEventListener('click', onCancel));
            document.removeEventListener('keydown', onKey);
        }
    });
}

function showConfirmDialog(options = {}) {
    return new Promise(resolve => {
        const { title = 'Confirm', subtitle = '', body = '', confirmText = 'Confirm', danger = false, disableAccept = false } = options;
        if (!window.confirmModal || !window.confirmAcceptBtn) {
            // Fallback to native confirm if modal missing
            const ok = confirm(body || title);
            resolve(ok);
            return;
        }
        if (window.confirmTitleEl) window.confirmTitleEl.textContent = title;
        if (window.confirmSubtitleEl) window.confirmSubtitleEl.textContent = subtitle || '';
        if (window.confirmBodyEl) window.confirmBodyEl.textContent = body || '';
        window.confirmAcceptBtn.textContent = confirmText;
        window.confirmAcceptBtn.classList.toggle('danger', !!danger);
        window.confirmAcceptBtn.disabled = !!disableAccept;
        window.confirmModal.classList.remove('hidden');
        window.confirmModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => window.confirmAcceptBtn.focus(), 20);
        const closers = window.confirmModal.querySelectorAll('[data-close]');
        const close = (val = false) => {
            cleanup();
            hideOverlay(window.confirmModal);
            resolve(val);
        };
        const onAccept = () => { if (!window.confirmAcceptBtn.disabled) close(true); };
        const onCancel = (e) => { if (e) e.preventDefault(); close(false); };
        const onKey = (e) => { if (e.key === 'Escape') onCancel(e); };
        window.confirmAcceptBtn.addEventListener('click', onAccept);
        closers.forEach(el => el.addEventListener('click', onCancel));
        document.addEventListener('keydown', onKey);
        function cleanup() {
            window.confirmAcceptBtn.removeEventListener('click', onAccept);
            closers.forEach(el => el.removeEventListener('click', onCancel));
            document.removeEventListener('keydown', onKey);
        }
    });
}

// Layout switching UI bindings (relies on globals from app.js)
const layoutButtons = document.querySelectorAll('[data-layout]');
layoutButtons?.forEach(btn => {
    btn.addEventListener('click', () => {
        const mode = btn.dataset.layout;
        if (!mode || typeof applyCategoryLayout !== 'function') return;
        applyCategoryLayout(mode);
        if (typeof render === 'function') render();
    });
});
window.addEventListener('resize', debounce(() => {
    if (typeof clampActiveToMax === 'function') clampActiveToMax();
    if (typeof renderLayoutChrome === 'function') renderLayoutChrome();
    if (typeof render === 'function' && typeof categoryLayoutMode !== 'undefined' && categoryLayoutMode === 'horizontal') render();
}, 160));

// Primary controls
const addBtn = document.getElementById('addCardBtn');
addBtn?.addEventListener('click', () => {
    if (typeof addCard === 'function' && typeof ROOT_CATEGORY !== 'undefined') addCard(ROOT_CATEGORY);
});

const addCategoryBtn = document.getElementById('addCategoryBtn');
addCategoryBtn?.addEventListener('click', async () => {
    if (typeof showCategoryNameDialog !== 'function' || typeof API === 'undefined') return;
    const name = await showCategoryNameDialog({
        title: 'New category',
        subtitle: 'Create a new container for cards',
        initial: ''
    });
    if (name === null) return;
    const trimmed = name.trim(); if (!trimmed) return;
    const order = (window.state?.categories?.length) || 0;
    try {
        const res = await API.saveCategory({ name: trimmed, order });
        if (res && res.ok && res.category) {
            state.categories.push(res.category);
            if (typeof saveLocal === 'function') saveLocal();
            if (typeof render === 'function') render();
            if (typeof ensureCategorySelectOptions === 'function') ensureCategorySelectOptions(res.category.id);
        } else {
            alert(res.error || 'Create failed');
        }
    } catch { alert('Create failed'); }
});

const searchInput = document.getElementById('searchInput');
searchInput?.addEventListener('input', () => {
    if (typeof store === 'undefined') return;
    window.searchTerm = (searchInput.value || '').toLowerCase();
    store.set('search_term', window.searchTerm);
    if (typeof render === 'function') render();
});

const trashBtn = document.getElementById('trashBtn');
trashBtn?.addEventListener('click', () => {
    if (typeof state === 'undefined') return;
    if (!window.trashArmed) {
        window.trashArmed = true;
        trashBtn.innerHTML = `Delete ${window.ICONS?.TRASH || 'Trash'}`;
        trashBtn.classList.add('danger');
        setTimeout(() => {
            window.trashArmed = false;
            trashBtn.innerHTML = 'Trash';
            trashBtn.classList.remove('danger');
        }, 2200);
        return;
    }
    const id = window.currentId;
    const card = state.cards.find(c => c.id === id);
    const catId = card ? (typeof normalizeCategory === 'function' ? normalizeCategory(card.category_id) : (card.category_id || 'root')) : (typeof ROOT_CATEGORY !== 'undefined' ? ROOT_CATEGORY : 'root');
    if (typeof closeModal === 'function') closeModal();
    state.cards = state.cards.filter(c => c.id !== id);
    if (typeof resequenceCategory === 'function') resequenceCategory(catId);
    if (typeof saveLocal === 'function') saveLocal();
    if (typeof render === 'function') render();
    if (window.API?.deleteCard) API.deleteCard(id);
});

const flushBtn = document.getElementById('flushBtn');
flushBtn?.addEventListener('click', async () => {
    if (!window.API?.flushOnce) return;
    flushBtn.disabled = true;
    try {
        const res = await API.flushOnce();
        const msg = `Flushed: ${res.flushed}\nUpserts: ${res.stats?.upserts || 0}\nPurges: ${res.stats?.purges || 0}\nPruned empty: ${res.stats?.skipped_empty || 0}`;
        alert(msg);
    } catch (e) { alert('Flush failed'); }
    flushBtn.disabled = false;
});

// Modal bindings
const modal = document.getElementById('modal');
const backdrop = modal?.querySelector('.backdrop');
const closeModalBtn = document.getElementById('closeModal');
const fullscreenBtn = document.getElementById('fullscreenBtn');
const editor = document.getElementById('editor');
backdrop?.addEventListener('click', (e) => { if (e.target.dataset.close && typeof closeModal === 'function') closeModal(); });
closeModalBtn?.addEventListener('click', () => { if (typeof closeModal === 'function') closeModal(); });
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal && !modal.classList.contains('hidden') && typeof closeModal === 'function') closeModal();
});
fullscreenBtn?.addEventListener('click', () => {
    if (!editor) return;
    editor.classList.toggle('fullscreen');
    if (fullscreenBtn && window.ICONS) {
        fullscreenBtn.textContent = editor.classList.contains('fullscreen') ? window.ICONS.FULLSCREEN_EXIT : window.ICONS.FULLSCREEN_ENTER;
    }
});

// History drawer bindings
const historyBtn = document.getElementById('historyBtn');
const drawer = document.getElementById('historyDrawer');
const closeHistory = document.getElementById('closeHistory');
const historyList = document.getElementById('historyList');
const hideHistoryDrawer = () => {
    if (!drawer) return;
    drawer.classList.add('hidden');
    drawer.setAttribute('aria-hidden', 'true');
};
historyBtn?.addEventListener('click', () => {
    if (typeof openHistoryDrawer === 'function') openHistoryDrawer();
});
closeHistory?.addEventListener('click', hideHistoryDrawer);
drawer?.addEventListener('click', (e) => {
    if (e.target && e.target.classList.contains('drawer-backdrop')) hideHistoryDrawer();
});
