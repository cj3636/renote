(() => {
    const ICONS = {
        // Navigation & Actions
        PLUS: '+',
        TRASH: '🗑️',
        FULLSCREEN_ENTER: '⛶',
        FULLSCREEN_EXIT: '⛶',
        // Category Toggles
        EXPAND: '▾',
        COLLAPSE: '▸',
        // Reordering
        DRAG: '⋮⋮',
        UP: '↑',
        DOWN: '↓',
    };
    // Expose on window for non-module usage
    window.ICONS = ICONS;
})();