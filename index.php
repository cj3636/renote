<?php
require_once __DIR__ . '/src/Support/Bootstrap.php';
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

// Security headers
$cspNonce = bin2hex(random_bytes(16));
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: microphone=(), camera=(), geolocation=()");
// CSP: allow scripts self + nonce, disallow inline styles except loaded stylesheet, restrict everything else.
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$cspNonce'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self';");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Card Notes</title>
<link rel="icon" href='data:image/svg+xml;utf8,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"%3E%3Crect width="64" height="64" rx="12" fill="%230b0f14"/%3E%3Cpath d="M14 18h36v28H14z" fill="%231a2430"/%3E%3Crect x="18" y="22" width="28" height="20" rx="4" fill="%234f7cff"/%3E%3C/svg%3E'>
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="<?php echo (defined('APP_DEBUG') && APP_DEBUG) ? 'debug' : ''; ?>">
<script id="bootstrap" type="application/json">
<?= safe_json_for_script($state) . "\n" ?>
</script>
<div id="app">
  <?php echo file_get_contents(__DIR__ . '/inc/header.html'); ?>
  <main class="grid" id="grid" aria-live="polite" aria-busy="false"></main>
</div>
<?php echo file_get_contents(__DIR__ . '/inc/modal.html'); ?>
<script src="js/modern.store.min.js"></script>
<script src="js/app.js" type="module"></script>
<script type="module" nonce="<?= $cspNonce ?>">
// Manual flush button handler
<?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
const flushBtn = document.getElementById('flushBtn');
flushBtn?.addEventListener('click', async ()=> {
  flushBtn.disabled = true;
  try {
    await fetch('src/Api/index.php?action=flush_once', {cache:'no-store'});
    fetch('src/Api/index.php?action=trim_stream&keep=5000'); // async trim
  } catch(e) {}
  setTimeout(()=>{ flushBtn.disabled=false; }, 800);
});
<?php endif; ?>

async function pollHealth() {
  try {
    const r = await fetch('src/Api/index.php?action=health', {cache:'no-store'});
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
