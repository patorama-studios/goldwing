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
$draftHtml = PageBuilderService::ensureDraftHtml($draftHtml);

if (!isset($_GET['page']) && !empty($page['slug'])) {
    $_GET['page'] = $page['slug'];
}

$pageSlug = $page['slug'] ?? 'home';
$pageTitle = $page['title'] ?? 'Australian Goldwing Association';
$heroTitle = $pageTitle;
$plainContent = trim(strip_tags($draftHtml));
$heroLead = $plainContent !== '' ? $plainContent : 'Rides, events, and member services for Goldwing riders across Australia.';
if (strlen($heroLead) > 200) {
    $heroLead = substr($heroLead, 0, 200) . '...';
}
$heroClass = $pageSlug === 'home' ? 'hero hero--home' : 'hero hero--compact';

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
      <section class="<?= e($heroClass) ?>">
        <div class="container hero__inner">
          <span class="hero__eyebrow">Australian Goldwing Association</span>
          <h1><?= e($heroTitle) ?></h1>
          <p class="hero__lead"><?= e($heroLead) ?></p>
        </div>
      </section>
      <section class="page-section">
        <div class="container">
          <div class="page-card reveal">
            <div id="gw-preview-root"><?= $draftHtml ?></div>
          </div>
        </div>
    </section>
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
      let selectedEl = null;

      const clearSelection = () => {
        if (selectedEl) {
          selectedEl.classList.remove('gw-selected');
        }
        selectedEl = null;
      };

      const sendSelection = (el) => {
        if (!el) {
          return;
        }
        const templateNode = el.closest('[data-gw-template]');
        const templateScope = templateNode ? templateNode.getAttribute('data-gw-template') : '';
        const html = el.outerHTML || '';
        const snippet = html.length > 240 ? html.slice(0, 240) + '...' : html;
        window.parent.postMessage({
          type: 'gw-select',
          elementId: el.getAttribute('data-gw-el'),
          tagName: el.tagName.toLowerCase(),
          templateScope,
          html,
          snippet
        }, '*');
      };

      document.addEventListener('click', (event) => {
        const target = event.target.closest('[data-gw-el]');
        if (!target) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (selectedEl !== target) {
          clearSelection();
          selectedEl = target;
          selectedEl.classList.add('gw-selected');
        }
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
        }
      });
    })();
  </script>
</body>
</html>
