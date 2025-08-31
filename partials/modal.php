<div id="modal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="backdrop" data-close="1"></div>
  <div class="dialog" role="document">
    <div class="dialog-header">
      <div class="drag-hint" title="Drag to move card">✥</div>
      <input id="nameInput" class="name-input" placeholder="Untitled" />
      <div class="spacer"></div>
      <button class="icon-btn close" id="closeModal" aria-label="Close">×</button>
    </div>
    <textarea id="editor" placeholder="Type your note…"></textarea>
    <div class="dialog-actions">
      <button id="fullscreenBtn" class="icon-btn" aria-label="Fullscreen">⛶</button>
      <button class="trash icon-btn" id="trashBtn" aria-label="Delete">🗑</button>
    </div>
  </div>
</div>

<!-- History drawer -->
<div id="historyDrawer" class="drawer hidden" aria-hidden="true">
  <div class="drawer-panel">
    <div class="drawer-header">
      <strong>DB History (not in Redis)</strong>
      <button id="closeHistory" class="icon-btn" aria-label="Close">×</button>
    </div>
    <div id="historyList" class="drawer-body"></div>
  </div>
</div>
