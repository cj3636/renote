<?php
// Minimal bootstrap: serve the SPA, and embed initial state (server copy) for faster first paint.
$path = __DIR__ . '/data/cards.json';
if (!is_dir(__DIR__ . '/data')) { @mkdir(__DIR__ . '/data', 0775, true); }
if (!file_exists($path)) {
  $initial = [
    "cards" => [[
      "id" => bin2hex(random_bytes(8)),
      "text" => "Welcome! Click this card to open the editor. Type here and everything saves instantly.",
      "order" => 0
    ]],
    "updated_at" => time()
  ];
  file_put_contents($path, json_encode($initial, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
}
$serverState = json_decode(file_get_contents($path), true);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Card Notes</title>
<link rel="icon" href="data:image/svg+xml;utf8,<?xml version='1.0' encoding='UTF-8'?><svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='12' fill='%230b0f14'/><path d='M14 18h36v28H14z' fill='%231a2430'/><rect x='18' y='22' width='28' height='20' rx='4' fill='%234f7cff'/></svg>">
<link rel="stylesheet" href="styles.css" />
</head>
<body>
<div id="app">
  <header class="topbar">
    <h1>Card Notes</h1>
    <button id="addCardBtn" class="icon-btn" title="Add card" aria-label="Add card">+</button>
  </header>

  <main class="grid" id="grid" aria-live="polite" aria-busy="false"></main>
</div>

<!-- Modal -->
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

<script>
  // Hydrate initial server state for instant paint
  window.__INITIAL_STATE__ = <?php echo json_encode($serverState, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="modern.store.min.js"></script>
<script src="app.js" type="module"></script>
</body>
</html>
