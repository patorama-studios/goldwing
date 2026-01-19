<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;

require_role(['admin', 'committee']);

$pageTitle = 'AI Page Builder';
$activePage = 'ai-editor';

require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet">
<style>
  :root {
    --builder-bg: #f8fafc;
    --builder-border: #e2e8f0;
    --builder-text: #0f172a;
    --builder-muted: #64748b;
    --builder-card: #ffffff;
    --builder-accent: #15803d;
    --builder-accent-soft: rgba(21, 128, 61, 0.12);
  }

  .builder-shell {
    height: 100vh;
    display: flex;
    background: var(--builder-bg);
  }
  .builder-shell [data-backend-sidebar] {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 40;
    transform: translateX(-100%);
  }
  .builder-shell[data-sidebar-open="true"] [data-backend-sidebar] {
    transform: translateX(0);
  }
  .builder-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    transition: margin-left 0.2s ease;
  }
  .builder-shell[data-sidebar-open="true"] .builder-main {
    margin-left: 16rem;
  }
  .builder-hide-sidebar {
    display: none;
    position: absolute;
    right: -14px;
    top: 120px;
    width: 28px;
    height: 48px;
    border-radius: 999px;
    background: var(--builder-card);
    border: 1px solid var(--builder-border);
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
  }
  .builder-shell[data-sidebar-open="true"] .builder-hide-sidebar {
    display: flex;
  }
  .builder-topbar {
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1rem;
    border-bottom: 1px solid var(--builder-border);
    background: var(--builder-card);
    position: sticky;
    top: 0;
    z-index: 20;
  }
  .topbar-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }
  .topbar-title {
    font-family: 'Oswald', sans-serif;
    font-size: 1.1rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .topbar-devices {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 0.25rem;
    padding: 0.25rem;
    border-radius: 10px;
    border: 1px solid var(--builder-border);
    background: #f1f5f9;
  }
  .device-toggle {
    width: 36px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    transition: all 0.2s ease;
  }
  .device-toggle.active {
    background: #ffffff;
    color: var(--builder-accent);
    box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08);
  }
  .topbar-right {
    display: flex;
    align-items: center;
    gap: 1rem;
  }
  .topbar-actions {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding-right: 0.75rem;
    border-right: 1px solid var(--builder-border);
  }
  .topbar-action {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--builder-muted);
    transition: all 0.2s ease;
  }
  .topbar-action.active {
    color: var(--builder-accent);
    background: var(--builder-accent-soft);
  }
  .topbar-action:hover {
    color: var(--builder-accent);
    background: var(--builder-accent-soft);
  }
  .topbar-subtitle {
    font-size: 0.7rem;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: #94a3b8;
    display: none;
  }
  .builder-content {
    flex: 1;
    display: grid;
    grid-template-columns: 288px 1fr 360px;
    min-height: 0;
  }
  .builder-panel {
    background: var(--builder-card);
    border-right: 1px solid var(--builder-border);
    padding: 1rem;
    overflow-y: auto;
  }
  .panel-section-label {
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #94a3b8;
    margin-bottom: 0.5rem;
  }
  .panel-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.75rem;
    font-size: 0.8rem;
    color: #64748b;
  }
  .builder-preview {
    background: #ffffff;
    position: relative;
    overflow: hidden;
  }
  .preview-stage {
    height: 100%;
    width: 100%;
    background: #0f172a;
    display: flex;
    align-items: stretch;
    justify-content: center;
  }
  .preview-viewport {
    height: 100%;
    width: 100%;
    background: #0f172a;
    display: flex;
    justify-content: center;
  }
  .preview-viewport.is-constrained {
    padding: 1.25rem 0;
  }
  .preview-frame {
    height: 100%;
    width: 100%;
    border: 0;
    background: #0f172a;
  }
  .preview-viewport.is-constrained .preview-frame {
    border-radius: 12px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.35);
    background: #ffffff;
  }
  .builder-chat {
    background: var(--builder-card);
    border-left: 1px solid var(--builder-border);
    display: flex;
    flex-direction: column;
    min-height: 0;
  }
  .chat-header {
    padding: 0.9rem 1rem;
    border-bottom: 1px solid var(--builder-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
  }
  .chat-header-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
  }
  .chat-pulse {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--builder-accent);
    box-shadow: 0 0 0 6px rgba(21, 128, 61, 0.15);
  }
  .chat-selected {
    margin: 0.75rem 1rem 0;
    padding: 0.6rem 0.75rem;
    border-radius: 999px;
    background: var(--builder-accent-soft);
    color: var(--builder-accent);
    font-size: 0.75rem;
    font-weight: 600;
    display: none;
  }
  .chat-selected.active {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
  }
  .selected-actions {
    display: none;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 1rem 0;
  }
  .selected-actions.active {
    display: flex;
  }
  .selected-action {
    padding: 0.35rem 0.6rem;
    border-radius: 999px;
    border: 1px solid var(--builder-border);
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--builder-muted);
    background: #ffffff;
  }
  .builder-chat-history {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    background: #f8fafc;
  }
  .builder-chat-message {
    padding: 0.75rem 1rem;
    border-radius: 18px;
    background: #e2e8f0;
    font-size: 0.85rem;
    color: #0f172a;
    max-width: 85%;
    border: 1px solid #e2e8f0;
  }
  .builder-chat-message.user {
    align-self: flex-end;
    border-top-right-radius: 6px;
  }
  .builder-chat-message.assistant {
    align-self: flex-start;
    background: var(--builder-accent);
    color: #ffffff;
    border-color: var(--builder-accent);
    border-top-left-radius: 6px;
  }
  .builder-chat-message.system {
    align-self: center;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 999px;
    font-size: 0.7rem;
    padding: 0.35rem 0.75rem;
  }
  .chat-media-card {
    align-self: flex-start;
    background: #ffffff;
    border: 1px solid var(--builder-border);
    border-radius: 14px;
    padding: 0.6rem;
    max-width: 85%;
    display: grid;
    gap: 0.4rem;
  }
  .chat-media-thumb {
    width: 100%;
    border-radius: 10px;
    object-fit: cover;
    max-height: 160px;
  }
  .chat-footer {
    border-top: 1px solid var(--builder-border);
    padding: 0.85rem 1rem;
    background: #ffffff;
  }
  .chat-input-wrap {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid var(--builder-border);
    border-radius: 16px;
    padding: 0.4rem 0.5rem;
    background: #f8fafc;
  }
  .chat-input-wrap:focus-within {
    border-color: var(--builder-accent);
    box-shadow: 0 0 0 3px rgba(21, 128, 61, 0.15);
  }
  .chat-media-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 12px;
    color: #94a3b8;
    transition: color 0.2s ease;
  }
  .chat-media-button:hover {
    color: var(--builder-accent);
  }
  .chat-input {
    flex: 1;
    border: none;
    background: transparent;
    resize: none;
    font-size: 0.9rem;
    min-height: 40px;
    max-height: 120px;
    outline: none;
  }
  .chat-send {
    width: 40px;
    height: 40px;
    border-radius: 14px;
    background: var(--builder-accent);
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 12px 20px rgba(21, 128, 61, 0.25);
  }
  .chat-attachment {
    margin-top: 0.6rem;
    display: none;
    align-items: center;
    justify-content: space-between;
    padding: 0.4rem 0.6rem;
    border-radius: 12px;
    background: #ecfdf3;
    color: var(--builder-accent);
    font-size: 0.75rem;
    font-weight: 600;
  }
  .chat-attachment.active {
    display: flex;
  }
  .chat-footer-meta {
    margin-top: 0.6rem;
    display: flex;
    justify-content: space-between;
    font-size: 0.65rem;
    color: #94a3b8;
  }
  .builder-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 50;
  }
  .builder-modal.active {
    display: flex;
  }
  .builder-modal-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 1rem;
    width: min(720px, 92vw);
    max-height: 90vh;
    overflow-y: auto;
  }
  @media (min-width: 1024px) {
    .topbar-subtitle {
      display: block;
    }
  }
  @media (max-width: 1200px) {
    .builder-content {
      grid-template-columns: 260px 1fr 320px;
    }
  }
  @media (max-width: 980px) {
    .builder-content {
      grid-template-columns: 1fr;
    }
    .builder-panel,
    .builder-chat {
      border: none;
      border-top: 1px solid var(--builder-border);
    }
    .builder-preview {
      min-height: 50vh;
    }
    .topbar-devices {
      position: static;
      transform: none;
      margin: 0 auto;
    }
  }
</style>
<div class="builder-shell" data-sidebar-open="false">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <button type="button" class="builder-hide-sidebar" id="builder-hide-sidebar" aria-label="Hide admin menu">
    <span class="material-icons-outlined text-gray-600">chevron_left</span>
  </button>
  <main class="builder-main">
    <header class="builder-topbar">
      <div class="topbar-left">
        <button type="button" class="inline-flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100" id="builder-menu-toggle" aria-expanded="false" aria-label="Toggle navigation">
          <span class="material-icons-outlined">menu</span>
        </button>
        <div class="topbar-title">AI Page Builder</div>
      </div>
      <div class="topbar-devices" role="tablist" aria-label="Preview size">
        <button class="device-toggle active" data-viewport="desktop" title="Desktop view">
          <span class="material-symbols-outlined text-[20px]">desktop_windows</span>
        </button>
        <button class="device-toggle" data-viewport="tablet" title="Tablet view">
          <span class="material-symbols-outlined text-[20px]">tablet_mac</span>
        </button>
        <button class="device-toggle" data-viewport="mobile" title="Mobile view">
          <span class="material-symbols-outlined text-[20px]">smartphone</span>
        </button>
      </div>
      <div class="topbar-right">
        <div class="topbar-actions">
          <button class="topbar-action active" id="toggle-selection" title="Toggle selection mode">
            <span class="material-symbols-outlined text-[20px]">ads_click</span>
          </button>
          <button class="topbar-action" id="clear-selection" title="Clear selection">
            <span class="material-symbols-outlined text-[20px]">close</span>
          </button>
        </div>
        <div class="topbar-subtitle">Draft-first editor</div>
      </div>
    </header>

    <div class="builder-content">
      <aside class="builder-panel">
        <div class="panel-section-label">Pages</div>
        <select id="page-select" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"></select>
        <button id="new-page" class="mt-3 w-full rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600">+ New Page</button>

        <div class="mt-6">
          <div class="panel-section-label">Access</div>
          <select id="access-level" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"></select>
        </div>

        <div class="mt-6 space-y-2">
          <div class="panel-section-label">Status</div>
          <div class="panel-card">
            <div id="draft-status">Select a page.</div>
            <div id="usage-wrap" class="mt-3 hidden">
              <div class="w-full h-1.5 bg-slate-200 rounded-full overflow-hidden mt-2">
                <div id="usage-bar" class="h-full" style="width: 0%; background: var(--builder-accent);"></div>
              </div>
              <div id="usage-label" class="text-[10px] mt-2"></div>
            </div>
          </div>
        </div>

        <div class="mt-6 space-y-2">
          <button id="save-draft" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-slate-900">Save Draft</button>
          <button id="push-live" class="w-full rounded-lg bg-secondary px-4 py-2 text-sm font-semibold text-white">Push Live</button>
          <button id="show-versions" class="w-full rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600">Versions</button>
        </div>

        <div class="mt-6 space-y-2">
          <div class="panel-section-label">Templates</div>
          <button id="edit-header-template" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">view_stream</span> Edit Header Block
          </button>
          <button id="edit-footer-template" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">view_headline</span> Edit Footer Block
          </button>
        </div>

        <div class="mt-6">
          <div class="panel-section-label">Menu Builder</div>
          <a class="inline-flex items-center gap-2 text-sm font-semibold text-secondary" href="/admin/navigation.php" target="_blank" rel="noopener">
            Manage menus
            <span class="material-icons-outlined text-base">open_in_new</span>
          </a>
        </div>
      </aside>

      <section class="builder-preview">
        <div class="preview-stage">
          <div class="preview-viewport" id="preview-viewport" data-viewport="desktop">
            <iframe id="preview-frame" class="preview-frame" title="Draft preview"></iframe>
          </div>
        </div>
      </section>

      <aside class="builder-chat">
        <div class="chat-header">
          <div class="chat-header-title">
            <span class="chat-pulse"></span>
            AI Design Assistant
          </div>
          <div class="flex items-center gap-2 text-slate-400">
            <button id="chat-history-toggle" class="text-slate-400 hover:text-slate-600" title="Versions">
              <span class="material-symbols-outlined text-[20px]">history</span>
            </button>
          </div>
        </div>
        <div id="selected-summary" class="chat-selected"></div>
        <div id="selected-actions" class="selected-actions">
          <button id="edit-ai" class="selected-action">Edit with AI</button>
          <button id="edit-manual" class="selected-action">Edit HTML</button>
          <button id="replace-image" class="selected-action hidden">Replace image</button>
          <button id="generate-image" class="selected-action hidden">Generate image</button>
        </div>
        <div class="builder-chat-history" id="chat-history"></div>
        <div class="chat-footer">
          <div class="flex items-center justify-between text-xs text-slate-400 mb-2">
            <div class="flex items-center gap-2">
              <span>Mode:</span>
              <select id="ai-mode" class="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs">
                <option value="element">Element</option>
                <option value="page">Page</option>
              </select>
            </div>
            <span id="chat-status"></span>
          </div>
          <div class="chat-input-wrap">
            <label class="chat-media-button" title="Upload media">
              <input id="chat-media-input" type="file" class="hidden">
              <span class="material-symbols-outlined text-[22px]">add_circle</span>
            </label>
            <textarea id="chat-input" rows="1" class="chat-input" placeholder="Ask AI to edit anything..."></textarea>
            <button id="send-ai" class="chat-send" title="Send">
              <span class="material-symbols-outlined text-[20px]">send</span>
            </button>
          </div>
          <div id="chat-attachment" class="chat-attachment">
            <span id="chat-attachment-label"></span>
            <button id="chat-attachment-clear" class="text-xs font-semibold">Remove</button>
          </div>
          <div class="chat-footer-meta">
            <span>Press Enter to send</span>
            <button id="clear-chat" class="text-xs font-semibold hover:text-green-700">Clear chat</button>
          </div>
        </div>
      </aside>
    </div>
  </main>
</div>

<div class="builder-modal" id="manual-modal">
  <div class="builder-modal-card space-y-3">
    <h3 class="font-display text-lg font-bold text-gray-900">Edit HTML</h3>
    <textarea id="manual-html" rows="10" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"></textarea>
    <div class="flex justify-end gap-2">
      <button id="manual-cancel" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600">Cancel</button>
      <button id="manual-save" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save</button>
    </div>
  </div>
</div>

<div class="builder-modal" id="versions-modal">
  <div class="builder-modal-card space-y-3">
    <h3 class="font-display text-lg font-bold text-gray-900">Versions</h3>
    <div id="versions-list" class="space-y-2 text-sm text-gray-700"></div>
    <div class="flex justify-end">
      <button id="versions-close" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600">Close</button>
    </div>
  </div>
</div>

<div class="builder-modal" id="image-modal">
  <div class="builder-modal-card space-y-3">
    <h3 class="font-display text-lg font-bold text-gray-900">Replace Image</h3>
    <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Image URL</label>
    <input id="image-src" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="https://">
    <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Media ID (optional)</label>
    <input id="image-media-id" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="123">
    <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Alt text</label>
    <input id="image-alt" type="text" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Describe the image">
    <div class="flex justify-end gap-2">
      <button id="image-cancel" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600">Cancel</button>
      <button id="image-save" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save</button>
    </div>
  </div>
</div>

<div class="builder-modal" id="new-page-modal">
  <div class="builder-modal-card space-y-3">
    <h3 class="font-display text-lg font-bold text-gray-900">Create New Page</h3>
    <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Describe the page</label>
    <textarea id="new-page-prompt" rows="4" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Describe the page idea..."></textarea>
    <div class="flex justify-end gap-2">
      <button id="new-page-cancel" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600">Cancel</button>
      <button id="new-page-create" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Create</button>
    </div>
  </div>
</div>

<script>
  (() => {
    const csrfToken = <?= json_encode(Csrf::token()) ?>;
    const pageSelect = document.getElementById('page-select');
    const shell = document.querySelector('.builder-shell');
    const menuToggle = document.getElementById('builder-menu-toggle');
    const hideSidebar = document.getElementById('builder-hide-sidebar');
    const accessSelect = document.getElementById('access-level');
    const draftStatus = document.getElementById('draft-status');
    const previewFrame = document.getElementById('preview-frame');
    const previewViewport = document.getElementById('preview-viewport');
    const chatHistory = document.getElementById('chat-history');
    const chatInput = document.getElementById('chat-input');
    const chatStatus = document.getElementById('chat-status');
    const aiMode = document.getElementById('ai-mode');
    const sendAiBtn = document.getElementById('send-ai');
    const selectedSummary = document.getElementById('selected-summary');
    const selectedActions = document.getElementById('selected-actions');
    const editAiBtn = document.getElementById('edit-ai');
    const editManualBtn = document.getElementById('edit-manual');
    const replaceImageBtn = document.getElementById('replace-image');
    const generateImageBtn = document.getElementById('generate-image');
    const toggleSelectionBtn = document.getElementById('toggle-selection');
    const clearSelectionBtn = document.getElementById('clear-selection');
    const manualModal = document.getElementById('manual-modal');
    const manualHtml = document.getElementById('manual-html');
    const versionsModal = document.getElementById('versions-modal');
    const versionsList = document.getElementById('versions-list');
    const imageModal = document.getElementById('image-modal');
    const imageSrc = document.getElementById('image-src');
    const imageMediaId = document.getElementById('image-media-id');
    const imageAlt = document.getElementById('image-alt');
    const usageWrap = document.getElementById('usage-wrap');
    const usageBar = document.getElementById('usage-bar');
    const usageLabel = document.getElementById('usage-label');
    const editHeaderBtn = document.getElementById('edit-header-template');
    const editFooterBtn = document.getElementById('edit-footer-template');
    const mediaInput = document.getElementById('chat-media-input');
    const chatAttachment = document.getElementById('chat-attachment');
    const chatAttachmentLabel = document.getElementById('chat-attachment-label');
    const chatAttachmentClear = document.getElementById('chat-attachment-clear');
    const clearChatBtn = document.getElementById('clear-chat');
    const chatHistoryToggle = document.getElementById('chat-history-toggle');
    const newPageBtn = document.getElementById('new-page');
    const newPageModal = document.getElementById('new-page-modal');
    const newPagePrompt = document.getElementById('new-page-prompt');
    const initialPageId = parseInt(new URLSearchParams(window.location.search).get('page_id') || '', 10);
    let pendingPageId = Number.isFinite(initialPageId) ? initialPageId : null;

    const state = {
      pages: [],
      roles: [],
      currentPage: null,
      draftHtml: '',
      selected: null,
      imageGenerationEnabled: false,
      usage: null,
      chatMessages: [],
      templates: {
        header: '',
        footer: ''
      },
      lastMedia: null,
      attachedMedia: null,
      uploadsByPage: {},
      selectionEnabled: true
    };

    const apiRequest = async (path, options = {}) => {
      const opts = { ...options };
      opts.headers = opts.headers || {};
      if (opts.method && opts.method !== 'GET') {
        opts.headers['X-CSRF-Token'] = csrfToken;
        opts.headers['Content-Type'] = 'application/json';
      }
      const res = await fetch(path, opts);
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || 'Request failed.');
      }
      return data;
    };

    const apiUrl = (action, id = null) => {
      const params = new URLSearchParams({ action });
      if (id) {
        params.set('id', id);
      }
      return `/admin/page-builder/api.php?${params.toString()}`;
    };

    const setSidebarOpen = (isOpen) => {
      if (!shell) return;
      shell.dataset.sidebarOpen = isOpen ? 'true' : 'false';
      if (menuToggle) {
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }
    };

    const renderPages = () => {
      pageSelect.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Select a page';
      pageSelect.appendChild(placeholder);
      state.pages.forEach(page => {
        const option = document.createElement('option');
        option.value = page.id;
        option.textContent = `${page.title} (${page.slug})`;
        if (state.currentPage && state.currentPage.id === page.id) {
          option.selected = true;
        }
        pageSelect.appendChild(option);
      });
    };

    const renderAccessOptions = () => {
      accessSelect.innerHTML = '';
      const publicOption = document.createElement('option');
      publicOption.value = 'public';
      publicOption.textContent = 'Public';
      accessSelect.appendChild(publicOption);
      state.roles.forEach(role => {
        const option = document.createElement('option');
        option.value = `role:${role}`;
        option.textContent = `Locked to ${role}`;
        accessSelect.appendChild(option);
      });
    };

    const renderChat = (messages) => {
      chatHistory.innerHTML = '';
      messages.forEach(msg => {
        const div = document.createElement('div');
        const role = msg.role || 'system';
        div.className = `builder-chat-message ${role}`;
        div.textContent = msg.content || '';
        chatHistory.appendChild(div);
      });
      const uploads = state.currentPage ? (state.uploadsByPage[state.currentPage.id] || []) : [];
      uploads.forEach(upload => {
        const card = document.createElement('div');
        card.className = 'chat-media-card';
        if (upload.type === 'image') {
          const img = document.createElement('img');
          img.src = upload.url;
          img.alt = upload.title || 'Uploaded image';
          img.className = 'chat-media-thumb';
          card.appendChild(img);
        }
        const label = document.createElement('div');
        label.className = 'text-xs text-slate-500';
        label.textContent = upload.title || upload.url;
        card.appendChild(label);
        chatHistory.appendChild(card);
      });
      chatHistory.scrollTop = chatHistory.scrollHeight;
    };

    const updateStatus = () => {
      if (!state.currentPage) {
        draftStatus.textContent = 'Select a page.';
        return;
      }
      draftStatus.textContent = state.currentPage.has_draft_changes ? 'Draft differs from live.' : 'Draft matches live.';
    };

    const updateUsage = () => {
      if (!usageWrap || !usageBar || !usageLabel || !state.usage) {
        return;
      }
      const cap = state.usage.cap_cents || 0;
      const used = state.usage.usd_cents || 0;
      if (cap <= 0) {
        usageWrap.classList.add('hidden');
        return;
      }
      const percent = Math.min(100, Math.round((used / cap) * 100));
      usageBar.style.width = `${percent}%`;
      const usedDollars = (used / 100).toFixed(2);
      const capDollars = (cap / 100).toFixed(2);
      const days = state.usage.days_to_reset ?? 0;
      usageLabel.textContent = `$${usedDollars} / $${capDollars} · ${days} days to reset`;
      usageWrap.classList.remove('hidden');
    };

    const updateElementPanel = () => {
      if (!state.selected) {
        selectedSummary.classList.remove('active');
        selectedSummary.textContent = '';
        selectedActions.classList.remove('active');
        replaceImageBtn.classList.add('hidden');
        generateImageBtn.classList.add('hidden');
        return;
      }
      const tag = state.selected.tagName ? state.selected.tagName.toUpperCase() : 'ELEMENT';
      const descriptor = `${state.selected.idText || ''}${state.selected.classText || ''}`;
      const selectorRef = state.selected.selectorHint || state.selected.elementId || '';
      const text = state.selected.textSnippet || state.selected.snippet || '';
      selectedSummary.textContent = `Selected: ${tag}${descriptor ? ` ${descriptor}` : ''}${selectorRef ? ` · ${selectorRef}` : ''} — ${text}`;
      selectedSummary.classList.add('active');
      selectedActions.classList.add('active');
      if (state.selected.tagName === 'img') {
        replaceImageBtn.classList.remove('hidden');
        if (state.imageGenerationEnabled) {
          generateImageBtn.classList.remove('hidden');
        } else {
          generateImageBtn.classList.add('hidden');
        }
      } else {
        replaceImageBtn.classList.add('hidden');
        generateImageBtn.classList.add('hidden');
      }
    };

    const postToPreview = (payload) => {
      previewFrame.contentWindow?.postMessage(payload, '*');
    };

    const clearSelection = () => {
      state.selected = null;
      updateElementPanel();
      postToPreview({ type: 'gw-clear-selection' });
    };

    const setSelectionMode = (enabled) => {
      state.selectionEnabled = enabled;
      toggleSelectionBtn.classList.toggle('active', enabled);
      postToPreview({ type: 'gw-selection-mode', enabled });
      if (!enabled) {
        clearSelection();
      }
    };

    const updateAttachment = () => {
      if (!chatAttachment || !chatAttachmentLabel) {
        return;
      }
      if (!state.attachedMedia) {
        chatAttachment.classList.remove('active');
        chatAttachmentLabel.textContent = '';
        return;
      }
      const label = state.attachedMedia.title || state.attachedMedia.url || 'Attachment';
      chatAttachmentLabel.textContent = `Attached: ${label}`;
      chatAttachment.classList.add('active');
    };

    const appendAttachmentToPrompt = (prompt) => {
      if (!state.attachedMedia) {
        return prompt;
      }
      const ref = state.attachedMedia;
      const lines = [];
      if (ref.referenceContent) {
        lines.push(`Reference HTML (${ref.title || 'upload'}):`);
        lines.push(ref.referenceContent);
      } else if (ref.type === 'image') {
        lines.push('Use the attached design reference image to inform a full redesign.');
      } else {
        lines.push(`Reference file: ${ref.url}`);
      }
      return `${prompt}\n\n${lines.join('\n')}`;
    };

    const setViewport = (mode) => {
      const sizes = { mobile: 375, tablet: 768 };
      previewViewport.dataset.viewport = mode;
      const constrained = mode !== 'desktop';
      previewViewport.classList.toggle('is-constrained', constrained);
      if (constrained) {
        previewFrame.style.width = `${sizes[mode]}px`;
      } else {
        previewFrame.style.width = '100%';
      }
    };

    const loadPage = async (id) => {
      const data = await apiRequest(apiUrl('page', id));
      state.currentPage = data.page;
      state.draftHtml = data.page.draft_html || '';
      state.selected = null;
      state.attachedMedia = null;
      pageSelect.value = data.page.id;
      state.templates = data.templates || { header: '', footer: '' };
      accessSelect.value = data.page.access_level || 'public';
      state.chatMessages = data.chat || [];
      renderChat(state.chatMessages);
      updateStatus();
      updateElementPanel();
      updateAttachment();
      previewFrame.src = `/admin/page-builder/preview.php?page_id=${id}`;
    };

    const loadPages = async () => {
      const data = await apiRequest(apiUrl('pages'));
      state.pages = data.pages || [];
      state.roles = data.roles || [];
      state.usage = data.usage || null;
      renderPages();
      renderAccessOptions();
      updateUsage();
      if (state.pages.length) {
        const targetId = pendingPageId && state.pages.find(page => page.id === pendingPageId)
          ? pendingPageId
          : state.pages[0].id;
        pendingPageId = null;
        loadPage(targetId);
      }
      try {
        const settings = await apiRequest(apiUrl('settings'));
        state.imageGenerationEnabled = !!settings.image_generation_enabled;
      } catch (err) {
        state.imageGenerationEnabled = false;
      }
    };

    if (menuToggle) {
      menuToggle.addEventListener('click', () => {
        const isOpen = shell?.dataset.sidebarOpen === 'true';
        setSidebarOpen(!isOpen);
      });
    }

    if (hideSidebar) {
      hideSidebar.addEventListener('click', () => {
        setSidebarOpen(false);
      });
    }

    if (toggleSelectionBtn) {
      toggleSelectionBtn.addEventListener('click', () => {
        setSelectionMode(!state.selectionEnabled);
      });
    }

    if (clearSelectionBtn) {
      clearSelectionBtn.addEventListener('click', () => {
        clearSelection();
      });
    }

    document.querySelectorAll('.device-toggle').forEach(button => {
      button.addEventListener('click', () => {
        const mode = button.dataset.viewport || 'desktop';
        document.querySelectorAll('.device-toggle').forEach(btn => btn.classList.remove('active'));
        button.classList.add('active');
        setViewport(mode);
      });
    });

    window.addEventListener('message', (event) => {
      const data = event.data || {};
      if (data.type === 'gw-select') {
        state.selected = {
          elementId: data.elementId,
          tagName: data.tagName,
          html: data.html,
          snippet: data.snippet,
          templateScope: data.templateScope || '',
          textSnippet: data.textSnippet || '',
          selectorHint: data.selectorHint || '',
          idText: data.idText || '',
          classText: data.classText || ''
        };
        updateElementPanel();
      }
    });

    pageSelect.addEventListener('change', () => {
      const id = parseInt(pageSelect.value, 10);
      if (id) {
        loadPage(id);
      }
    });

    document.getElementById('save-draft').addEventListener('click', async () => {
      if (!state.currentPage) return;
      await apiRequest(apiUrl('save_draft', state.currentPage.id), {
        method: 'POST',
        body: JSON.stringify({
          draft_html: state.draftHtml,
          access_level: accessSelect.value
        })
      });
      state.currentPage.has_draft_changes = true;
      updateStatus();
    });

    document.getElementById('push-live').addEventListener('click', async () => {
      if (!state.currentPage) return;
      const label = prompt('Version label (optional):');
      await apiRequest(apiUrl('publish', state.currentPage.id), {
        method: 'POST',
        body: JSON.stringify({ version_label: label || '' })
      });
      state.currentPage.has_draft_changes = false;
      updateStatus();
    });

    document.getElementById('show-versions').addEventListener('click', async () => {
      if (!state.currentPage) return;
      const data = await apiRequest(apiUrl('versions', state.currentPage.id));
      versionsList.innerHTML = '';
      (data.versions || []).forEach(version => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between border border-gray-200 rounded-lg px-3 py-2';
        row.innerHTML = `<div><strong>${version.version_label || 'Version'}</strong><div class="text-xs text-gray-500">${version.published_at || ''}</div></div>`;
        const btn = document.createElement('button');
        btn.className = 'text-xs font-semibold text-secondary';
        btn.textContent = 'Restore';
        btn.addEventListener('click', async () => {
          await apiRequest(apiUrl('rollback', state.currentPage.id), {
            method: 'POST',
            body: JSON.stringify({ version_id: version.id })
          });
          versionsModal.classList.remove('active');
          loadPage(state.currentPage.id);
        });
        row.appendChild(btn);
        versionsList.appendChild(row);
      });
      versionsModal.classList.add('active');
    });

    if (chatHistoryToggle) {
      chatHistoryToggle.addEventListener('click', () => {
        document.getElementById('show-versions').click();
      });
    }

    document.getElementById('versions-close').addEventListener('click', () => {
      versionsModal.classList.remove('active');
    });

    editAiBtn.addEventListener('click', () => {
      aiMode.value = 'element';
      chatInput.focus();
    });

    chatInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendAiBtn.click();
      }
    });

    sendAiBtn.addEventListener('click', async () => {
      if (!state.currentPage) return;
      const prompt = chatInput.value.trim();
      if (!prompt) return;
      if (aiMode.value === 'page') {
        const confirmPage = confirm('Apply AI changes to the whole page?');
        if (!confirmPage) {
          return;
        }
      }
      if (aiMode.value === 'element' && !state.selected) {
        alert('Select an element in the preview first.');
        return;
      }
      let finalPrompt = prompt;
      if (aiMode.value === 'element' && state.selected) {
        const context = [
          `Selected Element: ${state.selected.elementId}`,
          `Tag: ${state.selected.tagName || ''}`,
          `Selector: ${state.selected.selectorHint || ''}`,
          `Snippet: ${state.selected.textSnippet || state.selected.snippet || ''}`
        ].join('\n');
        finalPrompt = `${context}\n\n${prompt}`;
      }
      finalPrompt = appendAttachmentToPrompt(finalPrompt);
      chatStatus.textContent = 'Thinking...';
      const payload = {
        prompt: finalPrompt,
        mode: aiMode.value,
        selected_element_id: state.selected ? state.selected.elementId : '',
        selected_element_html: state.selected ? state.selected.html : '',
        template_scope: state.selected ? state.selected.templateScope : '',
        reference_image_url: state.attachedMedia && state.attachedMedia.type === 'image' ? state.attachedMedia.url : ''
      };
      try {
        const data = await apiRequest(apiUrl('ai_edit', state.currentPage.id), {
          method: 'POST',
          body: JSON.stringify(payload)
        });
        if (data.template_html) {
          if (state.selected && state.selected.templateScope === 'header') {
            state.templates.header = data.template_html;
          } else if (state.selected && state.selected.templateScope === 'footer') {
            state.templates.footer = data.template_html;
          }
        } else {
          state.draftHtml = data.draft_html || state.draftHtml;
        }
        postToPreview({ type: 'gw-update-html', html: state.draftHtml });
        loadPage(state.currentPage.id);
        chatInput.value = '';
      } catch (err) {
        alert(err.message || 'AI request failed.');
      } finally {
        chatStatus.textContent = '';
      }
    });

    editManualBtn.addEventListener('click', () => {
      if (!state.selected) return;
      manualHtml.value = state.selected.html || '';
      manualModal.classList.add('active');
    });

    document.getElementById('manual-cancel').addEventListener('click', () => {
      manualModal.dataset.templateScope = '';
      manualModal.classList.remove('active');
    });

    document.getElementById('manual-save').addEventListener('click', async () => {
      if (!state.currentPage) return;
      const updatedHtml = manualHtml.value.trim();
      if (!updatedHtml) return;
      const scope = manualModal.dataset.templateScope || '';
      if (!scope && !state.selected) {
        return;
      }
      if (scope) {
        const data = await apiRequest(apiUrl('template_update', state.currentPage.id), {
          method: 'POST',
          body: JSON.stringify({
            scope,
            html: updatedHtml
          })
        });
        if (scope === 'header') {
          state.templates.header = data.html || state.templates.header;
        } else if (scope === 'footer') {
          state.templates.footer = data.html || state.templates.footer;
        }
        manualModal.dataset.templateScope = '';
        manualModal.classList.remove('active');
        loadPage(state.currentPage.id);
        return;
      }
      const data = await apiRequest(apiUrl('manual_edit', state.currentPage.id), {
        method: 'POST',
        body: JSON.stringify({
          selected_element_id: state.selected.elementId,
          updated_html: updatedHtml,
          template_scope: state.selected.templateScope || ''
        })
      });
      if (data.template_html) {
        if (state.selected.templateScope === 'header') {
          state.templates.header = data.template_html;
        } else if (state.selected.templateScope === 'footer') {
          state.templates.footer = data.template_html;
        }
      } else {
        state.draftHtml = data.draft_html || state.draftHtml;
      }
      postToPreview({ type: 'gw-update-html', html: state.draftHtml });
      manualModal.classList.remove('active');
      loadPage(state.currentPage.id);
    });

    replaceImageBtn.addEventListener('click', () => {
      if (!state.selected || state.selected.tagName !== 'img') return;
      const parser = new DOMParser();
      const doc = parser.parseFromString(state.selected.html, 'text/html');
      const img = doc.querySelector('img');
      imageSrc.value = img?.getAttribute('src') || '';
      imageMediaId.value = img?.getAttribute('data-media-id') || '';
      imageAlt.value = img?.getAttribute('alt') || '';
      imageModal.classList.add('active');
    });

    generateImageBtn.addEventListener('click', async () => {
      if (!state.currentPage || !state.selected || state.selected.tagName !== 'img') return;
      const prompt = prompt('Describe the image you want to generate:');
      if (!prompt) return;
      try {
        const image = await apiRequest(apiUrl('image_generate', state.currentPage.id), {
          method: 'POST',
          body: JSON.stringify({
            prompt,
            template_scope: state.selected ? state.selected.templateScope : ''
          })
        });
        const imgHtml = `<img src="${image.url}" alt="${prompt}">`;
        const data = await apiRequest(apiUrl('manual_edit', state.currentPage.id), {
          method: 'POST',
          body: JSON.stringify({
            selected_element_id: state.selected.elementId,
            updated_html: imgHtml,
            template_scope: state.selected.templateScope || ''
          })
        });
        if (data.template_html) {
          if (state.selected.templateScope === 'header') {
            state.templates.header = data.template_html;
          } else if (state.selected.templateScope === 'footer') {
            state.templates.footer = data.template_html;
          }
        } else {
          state.draftHtml = data.draft_html || state.draftHtml;
        }
        postToPreview({ type: 'gw-update-html', html: state.draftHtml });
        loadPage(state.currentPage.id);
      } catch (err) {
        alert(err.message || 'Image generation failed.');
      }
    });

    document.getElementById('image-cancel').addEventListener('click', () => {
      imageModal.classList.remove('active');
    });

    document.getElementById('image-save').addEventListener('click', async () => {
      if (!state.currentPage || !state.selected) return;
      const src = imageSrc.value.trim();
      if (!src) return;
      const mediaId = imageMediaId.value.trim();
      const alt = imageAlt.value.trim();
      const imgHtml = `<img src="${src}" ${mediaId ? `data-media-id="${mediaId}"` : ''} ${alt ? `alt="${alt}"` : 'alt=""'}>`;
      const data = await apiRequest(apiUrl('manual_edit', state.currentPage.id), {
        method: 'POST',
        body: JSON.stringify({
          selected_element_id: state.selected.elementId,
          updated_html: imgHtml,
          template_scope: state.selected.templateScope || ''
        })
      });
      if (data.template_html) {
        if (state.selected.templateScope === 'header') {
          state.templates.header = data.template_html;
        } else if (state.selected.templateScope === 'footer') {
          state.templates.footer = data.template_html;
        }
      } else {
        state.draftHtml = data.draft_html || state.draftHtml;
      }
      postToPreview({ type: 'gw-update-html', html: state.draftHtml });
      imageModal.classList.remove('active');
      loadPage(state.currentPage.id);
    });

    const openTemplateEditor = (scope) => {
      manualHtml.value = scope === 'header' ? state.templates.header : state.templates.footer;
      manualModal.dataset.templateScope = scope;
      manualModal.classList.add('active');
    };

    if (editHeaderBtn) {
      editHeaderBtn.addEventListener('click', () => openTemplateEditor('header'));
    }
    if (editFooterBtn) {
      editFooterBtn.addEventListener('click', () => openTemplateEditor('footer'));
    }

    const readReferenceContent = (file) => new Promise((resolve) => {
      const isHtmlRef = file && (file.type === 'text/html' || file.type === 'application/xhtml+xml' || file.type === 'text/plain' || file.name.toLowerCase().endsWith('.html') || file.name.toLowerCase().endsWith('.htm'));
      if (!isHtmlRef) {
        resolve('');
        return;
      }
      const reader = new FileReader();
      reader.onload = () => {
        const raw = String(reader.result || '');
        const maxChars = 8000;
        const trimmed = raw.length > maxChars ? `${raw.slice(0, maxChars)}\n...[truncated]` : raw;
        resolve(trimmed);
      };
      reader.readAsText(file);
    });

    if (mediaInput) {
      mediaInput.addEventListener('change', async () => {
        if (!mediaInput.files || !mediaInput.files.length || !state.currentPage) {
          return;
        }
        const file = mediaInput.files[0];
        const referenceContent = await readReferenceContent(file);
        const formData = new FormData();
        formData.append('file', file);
        formData.append('title', file.name || '');
        formData.append('csrf_token', csrfToken);
        try {
          const res = await fetch(apiUrl('upload_media'), {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) {
            throw new Error(data.error || 'Upload failed.');
          }
          const upload = {
            id: data.id,
            url: data.url,
            type: data.type,
            title: file.name || data.url,
            referenceContent
          };
          state.lastMedia = data;
          state.attachedMedia = upload;
          if (!state.uploadsByPage[state.currentPage.id]) {
            state.uploadsByPage[state.currentPage.id] = [];
          }
          state.uploadsByPage[state.currentPage.id].push(upload);
          renderChat(state.chatMessages);
          updateAttachment();
        } catch (err) {
          alert(err.message || 'Upload failed.');
        } finally {
          mediaInput.value = '';
        }
      });
    }

    if (chatAttachmentClear) {
      chatAttachmentClear.addEventListener('click', () => {
        state.attachedMedia = null;
        updateAttachment();
      });
    }

    if (clearChatBtn) {
      clearChatBtn.addEventListener('click', () => {
        if (!state.currentPage) {
          return;
        }
        state.chatMessages = [];
        state.uploadsByPage[state.currentPage.id] = [];
        renderChat(state.chatMessages);
      });
    }

    if (newPageBtn) {
      newPageBtn.addEventListener('click', () => {
      newPagePrompt.value = '';
      newPageModal.classList.add('active');
      });
    }

    document.getElementById('new-page-cancel').addEventListener('click', () => {
      newPageModal.classList.remove('active');
    });

    document.getElementById('new-page-create').addEventListener('click', async () => {
      const prompt = newPagePrompt.value.trim();
      if (!prompt) {
        return;
      }
      try {
        const data = await apiRequest(apiUrl('create_page'), {
          method: 'POST',
          body: JSON.stringify({ prompt })
        });
        newPageModal.classList.remove('active');
        await loadPages();
        if (data.page_id) {
          loadPage(data.page_id);
        }
      } catch (err) {
        alert(err.message || 'Failed to create page.');
      }
    });

    loadPages();
    setViewport('desktop');
    previewFrame.addEventListener('load', () => {
      postToPreview({ type: 'gw-selection-mode', enabled: state.selectionEnabled });
    });
    setSidebarOpen(false);
  })();
</script>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
