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
