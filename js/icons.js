(() => {
    const ICONS = {
        // Navigation & Actions
        PLUS: '+',
        TRASH: 'dY-`‹,?',
        FULLSCREEN_ENTER: 'ƒ>',
        FULLSCREEN_EXIT: 'ƒ>',
        // Category Toggles
        EXPAND: 'ƒ-_',
        COLLAPSE: 'ƒ-,',
        // Reordering
        DRAG: 'ƒ<rƒ<r',
        UP: 'ƒ+`',
        DOWN: 'ƒ+"',
    };
    // Expose on window for non-module usage
    window.ICONS = ICONS;
})();
