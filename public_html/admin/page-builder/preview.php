<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\PageBuilderService;
use App\Services\PageService;
use App\Services\SettingsService;

require_role(['admin', 'committee']);

$pageId = isset($_GET['page_id']) ? (int) $_GET['page_id'] : 0;
$page = $pageId ? PageService::getById($pageId) : null;
if (!$page) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

$draftHtml = PageService::draftHtml($page);
$draftHtml = PageBuilderService::ensureEditableBody($page, $draftHtml);
$draftHtml = PageBuilderService::ensureDraftHtml($draftHtml);

if (!isset($_GET['page']) && !empty($page['slug'])) {
    $_GET['page'] = $page['slug'];
}

$pageSlug = $page['slug'] ?? 'home';

$siteHeaderHtml = '';
ob_start();
require __DIR__ . '/../../../app/Views/partials/nav_public.php';
$siteHeaderHtml = ob_get_clean();

$headerTemplate = (string) SettingsService::getGlobal('ai.template_header_html', '');
$footerTemplate = (string) SettingsService::getGlobal('ai.template_footer_html', '');
if ($headerTemplate === '') {
    $headerTemplate = $siteHeaderHtml;
}
$headerTemplate = PageBuilderService::ensureDraftHtml($headerTemplate);
$footerTemplate = PageBuilderService::ensureDraftHtml($footerTemplate);

ob_start();
require __DIR__ . '/../../../app/Views/partials/footer.php';
$siteFooterHtml = ob_get_clean();
$siteFooterHtml = preg_replace('/<\/body>.*$/s', '', $siteFooterHtml);
if ($footerTemplate === '') {
    $footerTemplate = PageBuilderService::ensureDraftHtml($siteFooterHtml);
}
if ($footerTemplate !== '') {
    $siteFooterHtml = '';
}

if ($draftHtml !== ($page['draft_html'] ?? '')) {
    PageService::updateDraft($pageId, $draftHtml, (string) ($page['access_level'] ?? 'public'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Manrope:wght@400;500;700;800&family=Noto+Sans:wght@400;500;700&family=Rajdhani:wght@400;500;600;700&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/styles.css">
  <script src="/assets/navigation.js" defer></script>
  <style>
    body {
      margin: 0;
    }
    .gw-selected {
      outline: 2px solid #f59e0b;
      outline-offset: 2px;
    }
    .gw-hover {
      outline: 2px solid rgba(21, 128, 61, 0.6);
      outline-offset: 2px;
      box-shadow: 0 0 0 2px rgba(21, 128, 61, 0.15);
    }
  </style>
</head>
<body>
  <div class="gw-preview-shell">
    <main class="site-main">
      <?php if ($headerTemplate !== ''): ?>
        <div class="page-builder-template page-builder-template--header" data-gw-template="header" data-gw-el="gw-template-header">
          <?= render_media_shortcodes($headerTemplate) ?>
        </div>
      <?php endif; ?>
      <div id="gw-preview-root"><?= render_media_shortcodes($draftHtml) ?></div>
    </main>
    <?php if ($footerTemplate !== ''): ?>
      <div class="page-builder-template page-builder-template--footer" data-gw-template="footer" data-gw-el="gw-template-footer">
        <?= render_media_shortcodes($footerTemplate) ?>
      </div>
    <?php endif; ?>
    <?= $siteFooterHtml ?>
  </div>
  <script>
    (() => {
      const root = document.getElementById('gw-preview-root');
      let contentRoot = document.getElementById('gw-content-root');
      const selectableTags = new Set(['section', 'article', 'div', 'header', 'main', 'h1', 'h2', 'h3', 'h4', 'p', 'img', 'button', 'a', 'ul', 'ol', 'li', 'figure', 'figcaption', 'blockquote', 'table']);
      let selectionEnabled = true;
      let selectedEl = null;
      let hoveredEl = null;

      const clearSelection = () => {
        if (selectedEl) {
          selectedEl.classList.remove('gw-selected');
        }
        selectedEl = null;
      };

      const clearHover = () => {
        if (hoveredEl) {
          hoveredEl.classList.remove('gw-hover');
        }
        hoveredEl = null;
      };

      const hasIgnoreMarker = (el) => el && el.closest('[data-gw-no-select]') !== null;

      const findSelectable = (target) => {
        if (!target || !contentRoot) {
          return null;
        }
        if (!contentRoot.contains(target)) {
          return null;
        }
        if (hasIgnoreMarker(target)) {
          return null;
        }
        let node = target;
        while (node && node !== contentRoot) {
          if (node instanceof HTMLElement && node.hasAttribute('data-gw-el')) {
            const tag = node.tagName.toLowerCase();
            if (selectableTags.has(tag)) {
              return node;
            }
          }
          node = node.parentElement;
        }
        node = target;
        while (node && node !== contentRoot) {
          if (node instanceof HTMLElement && node.hasAttribute('data-gw-el')) {
            return node;
          }
          node = node.parentElement;
        }
        return null;
      };

      const buildSnippet = (el) => {
        if (!el) {
          return '';
        }
        if (el.tagName.toLowerCase() === 'img') {
          return el.getAttribute('alt') || el.getAttribute('src') || 'Image';
        }
        const text = (el.textContent || '').trim().replace(/\s+/g, ' ');
        if (text !== '') {
          return text.length > 120 ? `${text.slice(0, 120)}...` : text;
        }
        return (el.outerHTML || '').slice(0, 120);
      };

      const sendSelection = (el) => {
        if (!el) {
          return;
        }
        const templateNode = el.closest('[data-gw-template]');
        const templateScope = templateNode ? templateNode.getAttribute('data-gw-template') : '';
        const html = el.outerHTML || '';
        const snippet = html.length > 240 ? html.slice(0, 240) + '...' : html;
        const tagName = el.tagName.toLowerCase();
        const idText = el.id ? `#${el.id}` : '';
        const classText = el.classList && el.classList.length ? `.${Array.from(el.classList).slice(0, 3).join('.')}` : '';
        const selectorHint = el.getAttribute('data-gw-el') ? `[data-gw-el="${el.getAttribute('data-gw-el')}"]` : '';
        window.parent.postMessage({
          type: 'gw-select',
          elementId: el.getAttribute('data-gw-el'),
          tagName,
          templateScope,
          html,
          snippet,
          textSnippet: buildSnippet(el),
          selectorHint,
          idText,
          classText
        }, '*');
      };

      document.addEventListener('mousemove', (event) => {
        if (!selectionEnabled) {
          clearHover();
          return;
        }
        const target = findSelectable(event.target);
        if (!target || target === selectedEl) {
          if (hoveredEl && hoveredEl !== selectedEl) {
            clearHover();
          }
          return;
        }
        if (hoveredEl && hoveredEl !== target) {
          clearHover();
        }
        hoveredEl = target;
        hoveredEl.classList.add('gw-hover');
      });

      document.addEventListener('click', (event) => {
        if (!selectionEnabled) {
          return;
        }
        const target = findSelectable(event.target);
        if (!target) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        clearSelection();
        clearHover();
        selectedEl = target;
        selectedEl.classList.add('gw-selected');
        sendSelection(selectedEl);
      });

      window.addEventListener('message', (event) => {
        const data = event.data || {};
        if (data.type === 'gw-highlight') {
          clearSelection();
          const next = document.querySelector(`[data-gw-el="${data.elementId}"]`);
          if (next) {
            selectedEl = next;
            selectedEl.classList.add('gw-selected');
          }
        }
        if (data.type === 'gw-update-html') {
          clearSelection();
          if (root) {
            root.innerHTML = data.html || '';
          }
          contentRoot = document.getElementById('gw-content-root');
        }
        if (data.type === 'gw-selection-mode') {
          selectionEnabled = !!data.enabled;
          clearHover();
        }
        if (data.type === 'gw-clear-selection') {
          clearSelection();
          clearHover();
        }
      });
    })();
  </script>
</body>
</html>
