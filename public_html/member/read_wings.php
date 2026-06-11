<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

use App\Services\SettingsService;

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /member/index.php?page=wings');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM wings_issues WHERE id = :id');
$stmt->execute(['id' => $id]);
$issue = $stmt->fetch();

if (!$issue) {
    header('Location: /member/index.php?page=wings');
    exit;
}

$appName    = SettingsService::getGlobal('site.name', 'Australian Goldwing Association');
$faviconUrl = SettingsService::getGlobal('site.favicon_url', '');
$pageTitle  = htmlspecialchars($issue['title'], ENT_QUOTES, 'UTF-8') . ' — Wings Magazine';
$pdfUrl     = '/member/download_wings.php?id=' . $id . '&view=1';
$issueTitle = htmlspecialchars($issue['title'], ENT_QUOTES, 'UTF-8');

// Cache-bust the reader assets so updates land without a hard refresh.
$asset = function (string $path): string {
    $full = __DIR__ . '/..' . $path;
    $v = @filemtime($full) ?: time();
    return $path . '?v=' . $v;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $pageTitle ?></title>
  <?php if ($faviconUrl): ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

  <!-- Reader engine (on-the-fly PDF rendering — no stored images).
       PDF.js (lib + worker) loads from cdnjs: the site CSP is
       `worker-src blob: https://cdnjs.cloudflare.com` (no 'self', no
       'unsafe-eval'), so PDF.js must fetch a cdnjs worker and run it as a real
       blob worker — a self-hosted worker triggers the fake-worker fallback,
       which then can't init without 'unsafe-eval' and hangs every render.
       This matches the original (proven) reader's setup. page-flip + the reader
       modules are self-hosted (script-src 'self'). -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
  <script src="<?= $asset('/assets/js/vendor/pageflip/page-flip.browser.js') ?>"></script>
  <script src="<?= $asset('/assets/js/wings-reader-core.js') ?>"></script>
  <script src="<?= $asset('/assets/js/wings-zoom.js') ?>"></script>
  <script src="<?= $asset('/assets/js/wings-reader.js') ?>"></script>

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: #111827;
      font-family: 'Inter', sans-serif;
      color: #fff;
      height: 100vh; height: 100dvh;     /* dvh fixes iOS Safari URL-bar height */
      overflow: hidden;
      display: flex;
      flex-direction: column;
      padding-top: env(safe-area-inset-top);
      padding-bottom: env(safe-area-inset-bottom);
    }

    /* ── Top bar ── */
    #top-bar {
      flex-shrink: 0;
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 20px; gap: 12px;
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(255,255,255,0.08);
      z-index: 10;
    }
    #back-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 16px; border-radius: 8px;
      background: rgba(242,201,76,0.12); border: 1px solid rgba(242,201,76,0.35);
      color: #F2C94C; font-size: 13px; font-weight: 600; text-decoration: none;
      transition: background 0.2s; white-space: nowrap;
    }
    #back-btn:hover { background: rgba(242,201,76,0.22); }
    #issue-title {
      font-family: 'Playfair Display', serif; font-size: 16px; font-weight: 700;
      color: #fff; text-align: center; flex: 1;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    #top-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    #page-counter { font-size: 12px; color: rgba(255,255,255,0.55); white-space: nowrap; }

    .pill-btn {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 7px 14px; border-radius: 8px;
      background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.15);
      color: rgba(255,255,255,0.8); font-size: 12px; font-weight: 600;
      cursor: pointer; transition: background 0.2s, color 0.2s;
      white-space: nowrap; font-family: 'Inter', sans-serif;
    }
    .pill-btn:hover { background: rgba(255,255,255,0.14); color: #fff; }
    .pill-btn .material-icons-outlined { font-size: 16px; }

    /* ── Reader area ── */
    #reader-area {
      flex: 1; position: relative;
      display: flex; align-items: center; justify-content: center;
      overflow: hidden; padding: 16px 70px;
    }
    #flip-book {
      position: relative; width: 100%; height: 100%;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; transition: opacity 0.4s ease;
      /* No native pinch-zoom/scroll over the book — our JS owns pinch (zoom
         layer) and horizontal swipes (flip engine). The page doesn't scroll
         (fixed 100dvh layout), so nothing is lost. */
      touch-action: none;
    }
    #flip-book.is-ready { opacity: 1; }
    .wings-page { background: #fff; overflow: hidden; }
    .wings-page__img { width: 100%; height: 100%; display: block; }

    /* ── Side nav arrows (desktop) ── */
    .nav-arrow {
      position: absolute; top: 50%; transform: translateY(-50%);
      z-index: 20; width: 52px; height: 52px; border-radius: 50%;
      border: 1.5px solid rgba(242,201,76,0.3); cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      background: rgba(242,201,76,0.13); color: #F2C94C;
      transition: background 0.2s, transform 0.15s, opacity 0.2s;
    }
    .nav-arrow:hover:not(:disabled) { background: rgba(242,201,76,0.28); transform: translateY(-50%) scale(1.08); }
    .nav-arrow:disabled { opacity: 0.22; cursor: default; }
    .nav-arrow .material-icons-outlined { font-size: 30px; }
    #btn-prev { left: 12px; } #btn-next { right: 12px; }

    /* ── Mobile bottom nav arrows ── */
    .mobile-nav-arrow {
      display: none; width: 40px; height: 40px; border-radius: 50%;
      background: rgba(242,201,76,0.13); border: 1.5px solid rgba(242,201,76,0.3);
      color: #F2C94C; cursor: pointer; align-items: center; justify-content: center;
      transition: background 0.2s, opacity 0.2s; flex-shrink: 0;
    }
    .mobile-nav-arrow:hover:not(:disabled) { background: rgba(242,201,76,0.28); }
    .mobile-nav-arrow:disabled { opacity: 0.22; cursor: default; }
    .mobile-nav-arrow .material-icons-outlined { font-size: 24px; }

    @media (max-width: 1023px) {
      #btn-prev, #btn-next { display: none; }
      .mobile-nav-arrow { display: inline-flex; }
      #reader-area { padding: 8px 6px; }
      #view-toggle .pill-label { display: none; }   /* icon-only on small screens */
    }
    /* Hide page-turn controls while zoomed (the overlay owns the screen). */
    body.zoom-active .nav-arrow,
    body.zoom-active .mobile-nav-arrow { opacity: 0; pointer-events: none; }

    /* ── Zoom overlay ── */
    .wings-zoom {
      position: absolute; inset: 0; z-index: 50; display: none; overflow: hidden;
      background: #0e1422; touch-action: none; cursor: grab;
    }
    .wings-zoom.is-active { display: block; }
    .wings-zoom:active { cursor: grabbing; }
    .wings-zoom__content { position: absolute; top: 0; left: 0; transform-origin: 0 0; will-change: transform; }
    .wings-zoom__canvas { display: block; background: #fff; }
    .wings-zoom__close {
      position: absolute; top: 12px; right: 12px; z-index: 2;
      width: 42px; height: 42px; border-radius: 50%; border: none;
      background: rgba(0,0,0,0.55); color: #fff; font-size: 24px; line-height: 1;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
    }
    .wings-zoom__close:hover { background: rgba(0,0,0,0.75); }

    /* ── Bottom bar ── */
    #bottom-bar {
      flex-shrink: 0; display: flex; align-items: center; gap: 14px;
      padding: 10px 20px; background: rgba(0,0,0,0.35);
      border-top: 1px solid rgba(255,255,255,0.07); z-index: 10;
    }
    #scrub {
      flex: 1; -webkit-appearance: none; appearance: none; height: 4px;
      border-radius: 99px; background: rgba(255,255,255,0.2); cursor: pointer; outline: none;
    }
    #scrub::-webkit-slider-thumb {
      -webkit-appearance: none; appearance: none; width: 16px; height: 16px;
      border-radius: 50%; background: #F2C94C; cursor: pointer; border: none;
    }
    #scrub::-moz-range-thumb { width: 16px; height: 16px; border-radius: 50%; background: #F2C94C; cursor: pointer; border: none; }
    #download-btn {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 7px 14px; border-radius: 8px;
      background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12);
      color: rgba(255,255,255,0.65); font-size: 12px; font-weight: 500;
      text-decoration: none; transition: background 0.2s; white-space: nowrap;
    }
    #download-btn:hover { background: rgba(255,255,255,0.13); color: #fff; }
    #download-btn .material-icons-outlined { font-size: 15px; }
    @media (max-width: 1023px) { #download-btn .dl-label { display: none; } }

    /* ── Loading overlay ── */
    #loading-overlay {
      position: fixed; inset: 0; background: #111827;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      z-index: 100; gap: 22px;
    }
    .loader-icon {
      width: 64px; height: 64px; border: 3px solid rgba(242,201,76,0.2);
      border-top-color: #F2C94C; border-radius: 50%; animation: spin 0.9s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .loader-icon { animation-duration: 2.4s; } }
    #loading-title { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 700; }
    #progress-text { font-size: 13px; color: rgba(255,255,255,0.5); }
    #error-msg { display: none; color: #f87171; font-size: 14px; text-align: center; max-width: 340px; }

    /* ── Zoom hint (first visit, auto-hides) ── */
    #zoom-hint {
      position: fixed; bottom: 70px; left: 50%; transform: translateX(-50%) translateY(10px);
      background: rgba(0,0,0,0.65); color: rgba(255,255,255,0.8); font-size: 12px;
      padding: 6px 14px; border-radius: 20px; pointer-events: none; opacity: 0;
      transition: opacity 0.4s; z-index: 50; white-space: nowrap;
    }
    #zoom-hint.show { opacity: 1; }
  </style>
</head>
<body>

<!-- Loading overlay -->
<div id="loading-overlay">
  <div class="loader-icon"></div>
  <div id="loading-title">Loading <?= $issueTitle ?></div>
  <div id="progress-text">Preparing magazine…</div>
  <div id="error-msg"></div>
</div>

<!-- Top bar -->
<div id="top-bar">
  <a id="back-btn" href="/member/index.php?page=wings">
    <span class="material-icons-outlined" style="font-size:17px">arrow_back</span>
    All Issues
  </a>
  <div id="issue-title" data-tour="wings-title"><?= $issueTitle ?></div>
  <div id="top-right">
    <div id="page-counter" aria-live="polite">— / —</div>
    <button id="view-toggle" class="pill-btn" title="Toggle single / double page" aria-label="Toggle single or double page">
      <span class="material-icons-outlined" id="view-icon">auto_stories</span>
      <span class="pill-label" id="view-label">Two pages</span>
    </button>
    <button id="fullscreen-btn" class="pill-btn" title="Toggle fullscreen" aria-label="Toggle fullscreen">
      <span class="material-icons-outlined" id="fs-icon">fullscreen</span>
      <span class="pill-label" id="fs-label">Full Screen</span>
    </button>
  </div>
</div>

<!-- Reader area -->
<div id="reader-area" data-tour="wings-reader">
  <button id="btn-prev" class="nav-arrow" aria-label="Previous page" data-tour="wings-prev" disabled>
    <span class="material-icons-outlined">chevron_left</span>
  </button>

  <div id="flip-book" data-tour="wings-book"></div>

  <button id="btn-next" class="nav-arrow" aria-label="Next page" data-tour="wings-next" disabled>
    <span class="material-icons-outlined">chevron_right</span>
  </button>
</div>

<div id="zoom-hint">Pinch or double-tap to zoom · drag to move</div>

<!-- Bottom bar -->
<div id="bottom-bar">
  <button id="mobile-prev" class="mobile-nav-arrow" aria-label="Previous page" disabled>
    <span class="material-icons-outlined">chevron_left</span>
  </button>
  <input id="scrub" type="range" min="1" max="1" value="1" aria-label="Jump to page">
  <button id="mobile-next" class="mobile-nav-arrow" aria-label="Next page" disabled>
    <span class="material-icons-outlined">chevron_right</span>
  </button>
  <a id="download-btn" data-tour="wings-download" href="/member/download_wings.php?id=<?= $id ?>" download>
    <span class="material-icons-outlined">download</span>
    <span class="dl-label">Download PDF</span>
  </a>
</div>

<script>
(function () {
  var PDF_URL  = <?= json_encode($pdfUrl) ?>;
  var WORKER   = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

  var overlay   = document.getElementById('loading-overlay');
  var errorMsg  = document.getElementById('error-msg');
  var progress  = document.getElementById('progress-text');
  var counter   = document.getElementById('page-counter');
  var scrub      = document.getElementById('scrub');
  var btnPrev   = document.getElementById('btn-prev');
  var btnNext   = document.getElementById('btn-next');
  var mPrev     = document.getElementById('mobile-prev');
  var mNext     = document.getElementById('mobile-next');
  var viewToggle= document.getElementById('view-toggle');
  var viewIcon  = document.getElementById('view-icon');
  var viewLabel = document.getElementById('view-label');

  var reader = new window.WingsReader('#flip-book', { url: PDF_URL, workerSrc: WORKER, mode: 'auto' });

  function syncControls(s) {
    counter.textContent = 'Page ' + s.page + ' of ' + s.total;
    scrub.max = s.total; scrub.value = s.page;
    var atStart = s.page <= 1, atEnd = s.page >= s.total;
    btnPrev.disabled = mPrev.disabled = atStart;
    btnNext.disabled = mNext.disabled = atEnd;
    var single = s.mode === 'single';
    viewIcon.textContent = single ? 'description' : 'auto_stories';
    viewLabel.textContent = single ? 'One page' : 'Two pages';
  }

  reader.on('ready', function (s) {
    overlay.style.display = 'none';
    // First-visit zoom hint (once per browser).
    try {
      if (!localStorage.getItem('wings.zoomHintSeen')) {
        var zh = document.getElementById('zoom-hint');
        setTimeout(function () { zh.classList.add('show'); }, 700);
        setTimeout(function () { zh.classList.remove('show'); }, 4600);
        localStorage.setItem('wings.zoomHintSeen', '1');
      }
    } catch (e) {}
  });
  reader.on('statechange', syncControls);
  reader.on('zoomenter', function () { document.body.classList.add('zoom-active'); });
  reader.on('zoomexit',  function () { document.body.classList.remove('zoom-active'); });

  // Navigation
  btnPrev.addEventListener('click', function () { reader.prev(); });
  btnNext.addEventListener('click', function () { reader.next(); });
  mPrev.addEventListener('click',  function () { reader.prev(); });
  mNext.addEventListener('click',  function () { reader.next(); });
  scrub.addEventListener('input',  function () { reader.goTo(parseInt(scrub.value, 10) - 1); });

  // Single / double toggle (explicit; persists via the reader's localStorage)
  viewToggle.addEventListener('click', function () {
    reader.setMode(reader.getMode() === 'single' ? 'double' : 'single');
  });

  // Fullscreen
  var fsBtn = document.getElementById('fullscreen-btn');
  var fsIcon = document.getElementById('fs-icon');
  var fsLabel = document.getElementById('fs-label');
  fsBtn.addEventListener('click', function () {
    if (!document.fullscreenElement) {
      (document.documentElement.requestFullscreen() || Promise.resolve()).catch(function () {});
    } else { document.exitFullscreen(); }
  });
  document.addEventListener('fullscreenchange', function () {
    var fs = !!document.fullscreenElement;
    fsIcon.textContent = fs ? 'fullscreen_exit' : 'fullscreen';
    fsLabel.textContent = fs ? 'Exit Full Screen' : 'Full Screen';
  });

  reader.init().catch(function (err) {
    console.error('Wings reader failed:', err);
    progress.style.display = 'none';
    document.querySelector('.loader-icon').style.display = 'none';
    document.getElementById('loading-title').style.display = 'none';
    errorMsg.style.display = 'block';
    errorMsg.innerHTML = 'Sorry, we couldn\'t load this magazine.<br>' +
      '<a href="/member/download_wings.php?id=<?= $id ?>" style="color:#F2C94C;text-decoration:underline">Download the PDF instead</a>';
  });
})();
</script>

<?php include __DIR__ . '/../../app/Views/partials/help_button.php'; ?>
</body>
</html>
