<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/config.php';

// Serve SPA with state from Redis/DB (no legacy filesystem JSON).
$state = load_state();
if (empty($state['cards'])) {
    // Seed an initial welcome card if completely empty
    $id = bin2hex(random_bytes(8));
    $welcome = "Welcome! Click this card to open the editor. Type here and everything saves instantly.";
    redis_upsert_card($id, $welcome, 0);
    $state = load_state();
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Card Notes</title>
<link rel="icon" href='data:image/svg+xml;utf8,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"%3E%3Crect width="64" height="64" rx="12" fill="%230b0f14"/%3E%3Cpath d="M14 18h36v28H14z" fill="%231a2430"/%3E%3Crect x="18" y="22" width="28" height="20" rx="4" fill="%234f7cff"/%3E%3C/svg%3E'>
<link rel="stylesheet" href="styles.css" />
</head>
<body>
<script>
  window.__INITIAL_STATE__ = <?php echo json_encode($state, JSON_UNESCAPED_SLASHES); ?>;
</script>
<div id="app">
  <header class="topbar">
    <h1>Card Notes</h1>
  <button id="addCardBtn" class="icon-btn" title="Add card" aria-label="Add card">+</button>
  <?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
  <button id="flushBtn" class="icon-btn" title="Flush pending changes" aria-label="Flush" style="margin-left:4px;">⟳</button>
  <?php endif; ?>
    <div id="health" class="health-indicator" title="System health">
      <span id="healthDot" class="health-dot health-pulse"></span>
    </div>
  </header>
  <main class="grid" id="grid" aria-live="polite" aria-busy="false"></main>
</div>
<div id="modal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="backdrop" data-close="1"></div>
  <div class="dialog" role="document">
    <div class="dialog-header">
      <div class="drag-hint">✥</div>
      <div class="spacer"></div>
      <button class="icon-btn close" id="closeModal" aria-label="Close">×</button>
    </div>
    <textarea id="editor" placeholder="Type your note…"></textarea>
    <div class="dialog-actions">
      <button class="trash icon-btn" id="trashBtn" aria-label="Delete">🗑</button>
    </div>
  </div>
</div>
<script src="modern.store.min.js"></script>
<script src="app.js" type="module"></script>
<script type="module">
// Manual flush button handler
<?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
const flushBtn = document.getElementById('flushBtn');
flushBtn?.addEventListener('click', async ()=> {
  flushBtn.disabled = true;
  try {
    await fetch('api.php?action=flush_once', {cache:'no-store'});
    fetch('api.php?action=trim_stream&keep=5000'); // async trim
  } catch(e) {}
  setTimeout(()=>{ flushBtn.disabled=false; }, 800);
});
<?php endif; ?>

async function pollHealth() {
  try {
    const r = await fetch('api.php?action=health', {cache:'no-store'});
    const j = await r.json();
    const dot = document.getElementById('healthDot');
    if (!dot) return;
    dot.className = 'health-dot health-pulse';
    if (j.status === 'ok') dot.classList.add('health-ok');
    else if (j.status === 'degraded') dot.classList.add('health-degraded');
    else dot.classList.add('health-backlog');
    dot.title = `Status: ${j.status}\nLag: ${j.lag}\nStream length: ${j.stream_length}`;
  } catch(e) {
    const dot = document.getElementById('healthDot');
    if (dot) { dot.className='health-dot health-backlog'; dot.title='Health check failed'; }
  } finally {
    setTimeout(pollHealth, 5000);
  }
}
pollHealth();
</script>
</body>
</html>
