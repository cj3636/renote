<header class="topbar">
  <h1>Card Notes</h1>
  <button id="addCardBtn" class="icon-btn" title="Add card" aria-label="Add card">+</button>
  <?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
    <button id="flushBtn" class="icon-btn" title="Flush pending changes" aria-label="Flush">⟳</button>
    <button id="historyBtn" class="icon-btn" title="Show DB history" aria-label="History">🕘</button>
  <?php endif; ?>
  <div id="health" class="health-indicator" title="System health">
    <span id="healthDot" class="health-dot health-pulse"></span>
  </div>
</header>
