<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;

require_role(['admin', 'committee']);

$pageTitle = 'AI Page Builder';
$activePage = 'ai-editor';

require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<style>
  .builder-shell {
    height: 100vh;
    display: flex;
    background: #f5f3ee;
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
  .builder-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid #e5e1d6;
    background: #fffdfa;
    position: sticky;
    top: 0;
    z-index: 10;
  }
  .builder-hide-sidebar {
    display: none;
    position: absolute;
    right: -14px;
    top: 120px;
    width: 28px;
    height: 48px;
    border-radius: 999px;
    background: #fffdfa;
    border: 1px solid #e5e1d6;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
  }
  .builder-shell[data-sidebar-open="true"] .builder-hide-sidebar {
    display: flex;
  }
  .builder-header .builder-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.5rem;
    letter-spacing: 0.04em;
  }
  .builder-content {
    flex: 1;
    display: grid;
    grid-template-columns: 260px 360px 1fr;
    gap: 0;
    min-height: 0;
  }
  .builder-panel {
    background: #ffffff;
    border-right: 1px solid #e5e1d6;
    padding: 1rem;
    overflow-y: auto;
  }
  .builder-chat {
    background: #fefcf7;
    border-right: 1px solid #e5e1d6;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }
  .builder-chat-history {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }
  .builder-chat-message {
    padding: 0.75rem;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #eee6d9;
    font-size: 0.9rem;
  }
  .builder-chat-message.user {
    border-left: 4px solid #9e9140;
  }
  .builder-chat-message.assistant {
    border-left: 4px solid #4a9114;
  }
  .builder-chat-footer {
    border-top: 1px solid #e5e1d6;
    padding: 0.75rem;
    background: #ffffff;
    display: grid;
    gap: 0.5rem;
  }
  .builder-preview {
    position: relative;
    background: #f5f3ee;
  }
  .builder-iframe {
    width: 100%;
    height: 100%;
    border: 0;
    background: #f5f3ee;
  }
  .element-panel {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: #ffffff;
    border: 1px solid #e5e1d6;
    border-radius: 16px;
    padding: 0.75rem;
    width: 300px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
    font-size: 0.85rem;
  }
  .element-panel button {
    margin-top: 0.5rem;
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
  @media (max-width: 1200px) {
    .builder-content {
      grid-template-columns: 240px 320px 1fr;
    }
  }
  @media (max-width: 980px) {
    .builder-content {
      grid-template-columns: 1fr;
    }
    .builder-panel,
    .builder-chat {
      border-right: none;
      border-bottom: 1px solid #e5e1d6;
    }
  }
</style>
<div class="builder-shell" data-sidebar-open="false">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <button type="button" class="builder-hide-sidebar" id="builder-hide-sidebar" aria-label="Hide admin menu">
    <span class="material-icons-outlined text-gray-600">chevron_left</span>
  </button>
  <main class="builder-main">
    <header class="builder-header">
      <button type="button" class="inline-flex items-center justify-center rounded-full p-2 text-gray-600 hover:bg-gray-100" id="builder-menu-toggle" aria-expanded="false" aria-label="Toggle navigation">
        <span class="material-icons-outlined">menu</span>
      </button>
      <div class="builder-title">AI Page Builder</div>
      <div class="text-sm text-gray-500">Draft-first editor</div>
    </header>

    <div class="builder-content">
      <aside class="builder-panel">
        <div class="text-xs uppercase tracking-[0.2em] text-slate-500 mb-2">Pages</div>
        <select id="page-select" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"></select>
        <button id="new-page" class="mt-3 w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700">New Page</button>
        <div class="mt-6">
          <div class="text-xs uppercase tracking-[0.2em] text-slate-500 mb-2">Access</div>
          <select id="access-level" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"></select>
        </div>
        <div class="mt-6 space-y-2">
          <div class="text-xs uppercase tracking-[0.2em] text-slate-500">Status</div>
          <div id="draft-status" class="text-sm text-gray-600">Select a page.</div>
          <div id="usage-wrap" class="mt-3 hidden">
            <div class="text-xs text-gray-500">Monthly usage</div>
            <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden mt-2">
              <div id="usage-bar" class="h-full bg-primary" style="width: 0%;"></div>
            </div>
            <div id="usage-label" class="text-xs text-gray-500 mt-2"></div>
          </div>
        </div>
        <div class="mt-6 space-y-2">
          <button id="save-draft" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save Draft</button>
          <button id="push-live" class="w-full rounded-lg bg-secondary px-4 py-2 text-sm font-semibold text-white">Push Live</button>
          <button id="show-versions" class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700">Versions</button>
        </div>
        <div class="mt-6 space-y-2">
          <div class="text-xs uppercase tracking-[0.2em] text-slate-500">Templates</div>
          <button id="edit-header-template" class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700">Edit Header Block</button>
          <button id="edit-footer-template" class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700">Edit Footer Block</button>
        </div>
        <div class="mt-6 space-y-2">
          <div class="text-xs uppercase tracking-[0.2em] text-slate-500">Media Library</div>
          <input id="media-file" type="file" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
          <button id="media-upload" class="w-full rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white">Upload</button>
          <div id="media-result" class="text-xs text-gray-500"></div>
          <button id="media-use" class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hidden">Use in selected image</button>
          <button id="media-reference" class="w-full rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hidden">Add to AI prompt</button>
        </div>
        <div class="mt-6">
          <div class="text-xs uppercase tracking-[0.2em] text-slate-500 mb-2">Menu Builder</div>
          <a class="inline-flex items-center gap-2 text-sm font-semibold text-secondary" href="/admin/navigation.php" target="_blank" rel="noopener">
            Manage menus
            <span class="material-icons-outlined text-base">open_in_new</span>
          </a>
        </div>
      </aside>

      <section class="builder-chat">
        <div class="builder-chat-history" id="chat-history"></div>
        <div class="builder-chat-footer">
          <div class="flex items-center gap-2 text-xs text-gray-500">
            <span>Mode:</span>
            <select id="ai-mode" class="rounded-md border border-gray-200 bg-white px-2 py-1 text-xs">
              <option value="element">Element</option>
              <option value="page">Page</option>
            </select>
          </div>
          <textarea id="chat-input" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Describe the change you want..."></textarea>
          <div class="flex items-center gap-2">
            <button id="send-ai" class="inline-flex items-center px-4 py-2 rounded-lg bg-ink text-white text-sm font-semibold">Send to AI</button>
            <span id="chat-status" class="text-xs text-gray-500"></span>
          </div>
        </div>
      </section>

      <section class="builder-preview">
        <iframe id="preview-frame" class="builder-iframe" title="Draft preview"></iframe>
        <div class="element-panel" id="element-panel">
          <div class="text-xs uppercase tracking-[0.2em] text-slate-500">Selected Element</div>
          <div id="element-meta" class="text-sm text-gray-700 mt-2">Click an element in the preview.</div>
          <div class="text-xs text-gray-500 mt-2" id="element-snippet"></div>
          <button id="edit-ai" class="w-full rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-ink">Edit with AI</button>
          <button id="edit-manual" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700">Edit manually</button>
          <button id="replace-image" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hidden">Replace image</button>
          <button id="generate-image" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hidden">Generate image</button>
        </div>
      </section>
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
    const chatHistory = document.getElementById('chat-history');
    const chatInput = document.getElementById('chat-input');
    const chatStatus = document.getElementById('chat-status');
    const aiMode = document.getElementById('ai-mode');
    const elementMeta = document.getElementById('element-meta');
    const elementSnippet = document.getElementById('element-snippet');
    const editAiBtn = document.getElementById('edit-ai');
    const editManualBtn = document.getElementById('edit-manual');
    const replaceImageBtn = document.getElementById('replace-image');
    const generateImageBtn = document.getElementById('generate-image');
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
    const mediaFile = document.getElementById('media-file');
    const mediaUpload = document.getElementById('media-upload');
    const mediaResult = document.getElementById('media-result');
    const mediaUse = document.getElementById('media-use');
    const mediaReference = document.getElementById('media-reference');
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
      templates: {
        header: '',
        footer: ''
      },
      lastMedia: null,
      lastReferenceContent: '',
      lastReferenceName: ''
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
        div.className = `builder-chat-message ${msg.role || ''}`;
        const time = msg.created_at ? new Date(msg.created_at).toLocaleString() : '';
        div.innerHTML = `<strong>${msg.role}</strong> <span class="text-xs text-gray-400">${time}</span><div>${msg.content}</div>`;
        chatHistory.appendChild(div);
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
      if (mediaReference) {
        if (state.lastMedia) {
          mediaReference.classList.remove('hidden');
        } else {
          mediaReference.classList.add('hidden');
        }
      }
      if (!state.selected) {
        elementMeta.textContent = 'Click an element in the preview.';
        elementSnippet.textContent = '';
        replaceImageBtn.classList.add('hidden');
        generateImageBtn.classList.add('hidden');
        return;
      }
      const scopeLabel = state.selected.templateScope ? ` • ${state.selected.templateScope}` : '';
      elementMeta.textContent = `${state.selected.tagName} • ${state.selected.elementId}${scopeLabel}`;
      elementSnippet.textContent = state.selected.snippet || '';
      if (state.selected.tagName === 'img') {
        replaceImageBtn.classList.remove('hidden');
        if (state.imageGenerationEnabled) {
          generateImageBtn.classList.remove('hidden');
        } else {
          generateImageBtn.classList.add('hidden');
        }
        if (state.lastMedia && mediaUse) {
          mediaUse.classList.remove('hidden');
        }
      } else {
        replaceImageBtn.classList.add('hidden');
        generateImageBtn.classList.add('hidden');
        if (mediaUse) {
          mediaUse.classList.add('hidden');
        }
      }
    };

    const postToPreview = (payload) => {
      previewFrame.contentWindow?.postMessage(payload, '*');
    };

    const loadPage = async (id) => {
      const data = await apiRequest(apiUrl('page', id));
      state.currentPage = data.page;
      state.draftHtml = data.page.draft_html || '';
      state.selected = null;
      pageSelect.value = data.page.id;
      state.templates = data.templates || { header: '', footer: '' };
      accessSelect.value = data.page.access_level || 'public';
      renderChat(data.chat || []);
      updateStatus();
      updateElementPanel();
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

    window.addEventListener('message', (event) => {
      const data = event.data || {};
      if (data.type === 'gw-select') {
        state.selected = {
          elementId: data.elementId,
          tagName: data.tagName,
          html: data.html,
          snippet: data.snippet,
          templateScope: data.templateScope || ''
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

    document.getElementById('versions-close').addEventListener('click', () => {
      versionsModal.classList.remove('active');
    });

    editAiBtn.addEventListener('click', () => {
      aiMode.value = 'element';
      chatInput.focus();
    });

    document.getElementById('send-ai').addEventListener('click', async () => {
      if (!state.currentPage) return;
      const prompt = chatInput.value.trim();
      if (!prompt) return;
      if (aiMode.value === 'page') {
        const confirmPage = confirm('Apply AI changes to the whole page?');
        if (!confirmPage) {
          return;
        }
      }
      let finalPrompt = prompt;
      if (aiMode.value === 'element' && state.selected) {
        const context = `Selected Element: ${state.selected.elementId}\nSnippet: ${state.selected.snippet}\n\n`;
        finalPrompt = context + prompt;
      }
      chatStatus.textContent = 'Thinking...';
      const payload = {
        prompt: finalPrompt,
        mode: aiMode.value,
        selected_element_id: state.selected ? state.selected.elementId : '',
        selected_element_html: state.selected ? state.selected.html : '',
        template_scope: state.selected ? state.selected.templateScope : ''
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

    if (mediaUpload) {
      mediaUpload.addEventListener('click', async () => {
        if (!mediaFile || !mediaFile.files || !mediaFile.files.length) {
          return;
        }
        const file = mediaFile.files[0];
        const isHtmlRef = file && (file.type === 'text/html' || file.type === 'application/xhtml+xml' || file.type === 'text/plain' || file.name.toLowerCase().endsWith('.html') || file.name.toLowerCase().endsWith('.htm'));
        if (isHtmlRef) {
          const reader = new FileReader();
          reader.onload = () => {
            const raw = String(reader.result || '');
            const maxChars = 8000;
            const trimmed = raw.length > maxChars ? `${raw.slice(0, maxChars)}\n...[truncated]` : raw;
            state.lastReferenceContent = trimmed;
            state.lastReferenceName = file.name || 'reference';
          };
          reader.readAsText(file);
        } else {
          state.lastReferenceContent = '';
          state.lastReferenceName = '';
        }
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
          state.lastMedia = data;
          mediaResult.textContent = `Uploaded: ${data.url} (ID ${data.id}) — [media:${data.id}]`;
          mediaUse.classList.remove('hidden');
          if (mediaReference) {
            mediaReference.classList.remove('hidden');
          }
        } catch (err) {
          mediaResult.textContent = err.message || 'Upload failed.';
        }
      });
    }

    if (mediaUse) {
      mediaUse.addEventListener('click', async () => {
        if (!state.lastMedia || !state.selected || state.selected.tagName !== 'img') {
          return;
        }
        const imgHtml = `<img src="${state.lastMedia.url}" data-media-id="${state.lastMedia.id}" alt="">`;
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
      });
    }

    if (mediaReference) {
      mediaReference.addEventListener('click', () => {
        if (!state.lastMedia) {
          return;
        }
        const refLines = [];
        if (state.lastReferenceContent) {
          refLines.push(`Reference HTML (${state.lastReferenceName || 'upload'}):`);
          refLines.push(state.lastReferenceContent);
        } else if (state.lastMedia.type === 'image') {
          refLines.push(`Reference image URL: ${state.lastMedia.url}`);
        } else {
          refLines.push(`Reference file: ${state.lastMedia.url}`);
        }
        const refText = refLines.join('\n');
        const spacer = chatInput.value.trim() === '' ? '' : '\n\n';
        chatInput.value = `${chatInput.value}${spacer}${refText}`;
        chatInput.focus();
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
    setSidebarOpen(false);
  })();
</script>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
