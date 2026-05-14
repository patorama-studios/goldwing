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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?></title>
  <?php if ($faviconUrl): ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

  <!-- PDF.js -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

  <!-- StPageFlip -->
  <script src="https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js"></script>

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      background: #111827;
      font-family: 'Inter', sans-serif;
      color: #fff;
      height: 100vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    /* ── Top bar ── */
    #top-bar {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 20px;
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(255,255,255,0.08);
      z-index: 10;
      gap: 12px;
    }

    #back-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 16px;
      border-radius: 8px;
      background: rgba(242,201,76,0.12);
      border: 1px solid rgba(242,201,76,0.35);
      color: #F2C94C;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: background 0.2s;
      white-space: nowrap;
    }
    #back-btn:hover { background: rgba(242,201,76,0.22); }

    #issue-title {
      font-family: 'Playfair Display', serif;
      font-size: 16px;
      font-weight: 700;
      color: #fff;
      text-align: center;
      flex: 1;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    #top-right {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-shrink: 0;
    }

    #page-counter {
      font-size: 12px;
      color: rgba(255,255,255,0.55);
      white-space: nowrap;
    }

    #fullscreen-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 7px 14px;
      border-radius: 8px;
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.15);
      color: rgba(255,255,255,0.8);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s, color 0.2s;
      white-space: nowrap;
      font-family: 'Inter', sans-serif;
    }
    #fullscreen-btn:hover { background: rgba(255,255,255,0.14); color: #fff; }
    #fullscreen-btn .material-icons-outlined { font-size: 16px; }

    /* Pinch zoom hint (mobile only) */
    #zoom-hint {
      position: fixed;
      bottom: 70px;
      left: 50%;
      transform: translateX(-50%) translateY(10px);
      background: rgba(0,0,0,0.65);
      color: rgba(255,255,255,0.75);
      font-size: 11px;
      padding: 6px 14px;
      border-radius: 20px;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.4s;
      z-index: 50;
      white-space: nowrap;
      display: none;
    }
    #zoom-hint.show { opacity: 1; }
    @media (max-width: 1023px) { #zoom-hint { display: block; } }
    body.is-mobile #zoom-hint { display: block; }

    /* Escape hint toast */
    #esc-hint {
      position: fixed;
      bottom: 60px;
      left: 50%;
      transform: translateX(-50%) translateY(20px);
      background: rgba(0,0,0,0.75);
      color: rgba(255,255,255,0.85);
      font-size: 12px;
      padding: 8px 16px;
      border-radius: 20px;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.3s, transform 0.3s;
      z-index: 50;
      white-space: nowrap;
    }
    #esc-hint.show {
      opacity: 1;
      transform: translateX(-50%) translateY(0);
    }

    /* ── Reader area ── */
    #reader-area {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
      padding: 16px 70px;
    }

    /* ── Arrow buttons (desktop side arrows) ── */
    .nav-arrow {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      z-index: 20;
      width: 52px;
      height: 52px;
      border-radius: 50%;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(242,201,76,0.13);
      border: 1.5px solid rgba(242,201,76,0.3);
      color: #F2C94C;
      transition: background 0.2s, transform 0.15s, opacity 0.2s;
    }
    .nav-arrow:hover:not(:disabled) {
      background: rgba(242,201,76,0.28);
      transform: translateY(-50%) scale(1.08);
    }
    .nav-arrow:disabled {
      opacity: 0.22;
      cursor: default;
    }
    .nav-arrow .material-icons-outlined { font-size: 30px; }
    #btn-prev { left: 12px; }
    #btn-next { right: 12px; }

    /* ── Mobile bottom nav arrows ── */
    .mobile-nav-arrow {
      display: none;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(242,201,76,0.13);
      border: 1.5px solid rgba(242,201,76,0.3);
      color: #F2C94C;
      cursor: pointer;
      align-items: center;
      justify-content: center;
      transition: background 0.2s, opacity 0.2s;
      flex-shrink: 0;
    }
    .mobile-nav-arrow:hover:not(:disabled) { background: rgba(242,201,76,0.28); }
    .mobile-nav-arrow:disabled { opacity: 0.22; cursor: default; }
    .mobile-nav-arrow .material-icons-outlined { font-size: 24px; }

    /* ── Mobile overrides (phones + tablets) ── */
    @media (max-width: 1023px) {
      #btn-prev, #btn-next { display: none; }
      .mobile-nav-arrow { display: inline-flex; }
      #reader-area { padding: 8px 6px; }
    }
    /* Also apply mobile overrides when ?mobile=1 forces mobile mode (body.is-mobile added by JS) */
    body.is-mobile #btn-prev, body.is-mobile #btn-next { display: none; }
    body.is-mobile .mobile-nav-arrow { display: inline-flex; }
    body.is-mobile #reader-area { padding: 8px 6px; }

    /* ── Flipbook container ── */
    #flip-book-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      height: 100%;
      transform-origin: center center;
      /* transform: scale() set by zoom JS */
    }

    #flip-book {
      /* sized by JS */
    }

    /* ── Loading overlay ── */
    #loading-overlay {
      position: fixed;
      inset: 0;
      background: #111827;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      z-index: 100;
      gap: 24px;
    }

    .loader-icon {
      width: 64px;
      height: 64px;
      border: 3px solid rgba(242,201,76,0.2);
      border-top-color: #F2C94C;
      border-radius: 50%;
      animation: spin 0.9s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    #loading-title {
      font-family: 'Playfair Display', serif;
      font-size: 20px;
      font-weight: 700;
      color: #fff;
    }

    #progress-wrap {
      width: 280px;
      background: rgba(255,255,255,0.1);
      border-radius: 99px;
      height: 6px;
      overflow: hidden;
    }
    #progress-bar {
      height: 100%;
      background: #F2C94C;
      border-radius: 99px;
      width: 0%;
      transition: width 0.15s ease;
    }

    #progress-text {
      font-size: 13px;
      color: rgba(255,255,255,0.5);
    }

    #error-msg {
      display: none;
      color: #f87171;
      font-size: 14px;
      text-align: center;
      max-width: 340px;
    }

    /* ── Bottom bar ── */
    #bottom-bar {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 16px;
      padding: 10px 20px;
      background: rgba(0,0,0,0.35);
      border-top: 1px solid rgba(255,255,255,0.07);
      z-index: 10;
    }

    #page-dots {
      display: flex;
      gap: 5px;
      align-items: center;
    }

    .page-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: rgba(255,255,255,0.25);
      transition: background 0.2s, transform 0.2s;
    }
    .page-dot.active {
      background: #F2C94C;
      transform: scale(1.4);
    }

    #download-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      border-radius: 7px;
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.12);
      color: rgba(255,255,255,0.65);
      font-size: 12px;
      font-weight: 500;
      text-decoration: none;
      transition: background 0.2s;
    }
    #download-btn:hover { background: rgba(255,255,255,0.13); color: #fff; }
    #download-btn .material-icons-outlined { font-size: 15px; }

    /* hide flipbook until ready */
    #flip-book-wrap { opacity: 0; transition: opacity 0.4s ease; }
    #flip-book-wrap.ready { opacity: 1; }
  </style>
</head>
<body>

<!-- Loading Overlay -->
<div id="loading-overlay">
  <div class="loader-icon"></div>
  <div id="loading-title">Loading <?= $issueTitle ?></div>
  <div id="progress-wrap"><div id="progress-bar"></div></div>
  <div id="progress-text">Preparing magazine…</div>
  <div id="error-msg"></div>
</div>

<!-- Top Bar -->
<div id="top-bar">
  <a id="back-btn" href="/member/index.php?page=wings">
    <span class="material-icons-outlined" style="font-size:17px">arrow_back</span>
    All Issues
  </a>
  <div id="issue-title"><?= $issueTitle ?></div>
  <div id="top-right">
    <div id="page-counter">— / —</div>
    <button id="fullscreen-btn" title="Toggle fullscreen">
      <span class="material-icons-outlined">fullscreen</span>
      <span id="fs-label">Full Screen</span>
    </button>
  </div>
</div>
<div id="esc-hint">Press <kbd style="background:rgba(255,255,255,0.15);padding:1px 6px;border-radius:4px;font-family:monospace">Esc</kbd> to exit full screen</div>
<div id="zoom-hint">Pinch to zoom · Double-tap to reset</div>

<!-- Reader Area -->
<div id="reader-area">
  <button id="btn-prev" class="nav-arrow" aria-label="Previous page" disabled>
    <span class="material-icons-outlined">chevron_left</span>
  </button>

  <div id="flip-book-wrap">
    <div id="flip-book"></div>
  </div>

  <button id="btn-next" class="nav-arrow" aria-label="Next page" disabled>
    <span class="material-icons-outlined">chevron_right</span>
  </button>
</div>

<!-- Bottom Bar -->
<div id="bottom-bar">
  <button id="mobile-prev" class="mobile-nav-arrow" aria-label="Previous page" disabled>
    <span class="material-icons-outlined">chevron_left</span>
  </button>
  <div id="page-dots"></div>
  <button id="mobile-next" class="mobile-nav-arrow" aria-label="Next page" disabled>
    <span class="material-icons-outlined">chevron_right</span>
  </button>
  <a id="download-btn" href="/member/download_wings.php?id=<?= $id ?>" download>
    <span class="material-icons-outlined">download</span>
    Download PDF
  </a>
</div>

<script>
(async function () {
  // ── Config ──────────────────────────────────────────────
  const PDF_URL    = <?= json_encode($pdfUrl) ?>;
  const RENDER_SCALE = 1.5;   // higher = sharper pages, slower load (1.5 balances quality/speed)
  const PARALLEL   = 4;       // pages rendered simultaneously
  const MAX_DOTS   = 20;      // max navigation dots shown

  // ── Elements ─────────────────────────────────────────────
  const overlay      = document.getElementById('loading-overlay');
  const progressBar  = document.getElementById('progress-bar');
  const progressText = document.getElementById('progress-text');
  const errorMsg     = document.getElementById('error-msg');
  const pageCounter  = document.getElementById('page-counter');
  const btnPrev      = document.getElementById('btn-prev');
  const btnNext      = document.getElementById('btn-next');
  const mobilePrev   = document.getElementById('mobile-prev');
  const mobileNext   = document.getElementById('mobile-next');
  const dotsWrap     = document.getElementById('page-dots');
  const flipWrap     = document.getElementById('flip-book-wrap');
  const flipContainer= document.getElementById('flip-book');

  // ── PDF.js setup ─────────────────────────────────────────
  const pdfjsLib = window['pdfjs-dist/build/pdf'];
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

  let pageFlip = null;
  let totalPages = 0;

  // Treat anything under 1024px as mobile (catches phones in landscape + small tablets)
  // ?mobile=1 in URL forces mobile mode (useful for testing on desktop)
  const isMobile = window.innerWidth < 1024 || new URLSearchParams(location.search).get('mobile') === '1';
  if (isMobile) document.body.classList.add('is-mobile');

  function getBookDimensions() {
    const topBar    = document.getElementById('top-bar').offsetHeight;
    const bottomBar = document.getElementById('bottom-bar').offsetHeight;

    // A4 portrait ratio (1 : 1.414)
    const aspect = 1 / 1.414;

    let w, h;
    if (isMobile) {
      // No side arrows — fill the full width and as much height as possible
      const vPad  = 12;   // small vertical breathing room
      const hPad  = 10;   // minimal horizontal padding
      const availH = window.innerHeight - topBar - bottomBar - vPad;
      const availW = window.innerWidth  - hPad;

      w = Math.floor(availW * 0.98);
      h = Math.floor(w / aspect);
      if (h > availH) { h = Math.floor(availH * 0.98); w = Math.floor(h * aspect); }
      w = Math.max(160, Math.min(w, 900));   // allow larger tablets to use full width
      h = Math.max(226, Math.min(h, 1200));
    } else {
      // Two-page spread — side arrows take ~150px total
      const vPad    = 48;
      const arrowGap = 150;
      const availH  = window.innerHeight - topBar - bottomBar - vPad;
      const availW  = window.innerWidth  - arrowGap;

      w = Math.floor((availW * 0.88) / 2);
      h = Math.floor(w / aspect);
      if (h > availH) { h = Math.floor(availH * 0.94); w = Math.floor(h * aspect); }
      w = Math.max(180, Math.min(w, 600));
      h = Math.max(254, Math.min(h, 850));
    }

    return { w, h };
  }

  // ── Fullscreen logic ──────────────────────────────────────
  const fsBtn   = document.getElementById('fullscreen-btn');
  const fsLabel = document.getElementById('fs-label');
  const fsIcon  = fsBtn.querySelector('.material-icons-outlined');
  const escHint = document.getElementById('esc-hint');
  let escHintTimer = null;

  function showEscHint() {
    escHint.classList.add('show');
    clearTimeout(escHintTimer);
    escHintTimer = setTimeout(() => escHint.classList.remove('show'), 3500);
  }

  function updateFsButton(isFs) {
    fsIcon.textContent  = isFs ? 'fullscreen_exit' : 'fullscreen';
    fsLabel.textContent = isFs ? 'Exit Full Screen' : 'Full Screen';
  }

  fsBtn.addEventListener('click', () => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().then(() => {
        updateFsButton(true);
        showEscHint();
      }).catch(() => {});
    } else {
      document.exitFullscreen();
    }
  });

  document.addEventListener('fullscreenchange', () => {
    const isFs = !!document.fullscreenElement;
    updateFsButton(isFs);
    if (!isFs) escHint.classList.remove('show');
  });

  function updateUI(currentPage, total) {
    const spread = Math.ceil(currentPage / 2);
    const totalSpreads = Math.ceil(total / 2);
    pageCounter.textContent = `Page ${currentPage} of ${total}`;
    btnPrev.disabled    = currentPage <= 1;
    btnNext.disabled    = currentPage >= total;
    mobilePrev.disabled = currentPage <= 1;
    mobileNext.disabled = currentPage >= total;

    // Update dots
    if (dotsWrap.children.length > 0) {
      const step   = total <= MAX_DOTS ? 1 : Math.ceil(total / MAX_DOTS);
      const active = Math.floor((currentPage - 1) / step);
      Array.from(dotsWrap.children).forEach((dot, i) => {
        dot.classList.toggle('active', i === active);
      });
    }
  }

  function buildDots(total) {
    dotsWrap.innerHTML = '';
    const count = Math.min(total, MAX_DOTS);
    for (let i = 0; i < count; i++) {
      const d = document.createElement('div');
      d.className = 'page-dot';
      dotsWrap.appendChild(d);
    }
  }

  try {
    // ── Load PDF ───────────────────────────────────────────
    progressText.textContent = 'Fetching magazine…';
    const loadingTask = pdfjsLib.getDocument({ url: PDF_URL, withCredentials: true });
    const pdf = await loadingTask.promise;
    totalPages = pdf.numPages;

    buildDots(totalPages);

    // Render first page to determine page dimensions
    const firstPage    = await pdf.getPage(1);
    const firstVP      = firstPage.getViewport({ scale: RENDER_SCALE });
    const pageW        = Math.round(firstVP.width);
    const pageH        = Math.round(firstVP.height);

    // ── Render all pages in parallel batches ──────────────
    const images = new Array(totalPages);
    let rendered = 0;

    async function renderPage(pageNum) {
      const page     = await pdf.getPage(pageNum);
      const viewport = page.getViewport({ scale: RENDER_SCALE });
      const canvas   = document.createElement('canvas');
      canvas.width   = Math.round(viewport.width);
      canvas.height  = Math.round(viewport.height);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
      images[pageNum - 1] = canvas.toDataURL('image/jpeg', 0.85);
      rendered++;
      const pct = Math.round((rendered / totalPages) * 100);
      progressBar.style.width = pct + '%';
      progressText.textContent = `Loading pages… ${rendered} of ${totalPages}`;
    }

    // Run pages in batches of PARALLEL
    for (let i = 1; i <= totalPages; i += PARALLEL) {
      const batch = [];
      for (let j = i; j < i + PARALLEL && j <= totalPages; j++) {
        batch.push(renderPage(j));
      }
      await Promise.all(batch);
      // Yield to browser between batches
      await new Promise(r => setTimeout(r, 0));
    }

    // ── Init StPageFlip ────────────────────────────────────
    const { w, h } = getBookDimensions();

    pageFlip = new St.PageFlip(flipContainer, {
      width:               w,
      height:              h,
      size:                'stretch',
      minWidth:            160,
      minHeight:           226,
      maxWidth:            isMobile ? 950 : 650,
      maxHeight:           isMobile ? 1300 : 900,
      maxShadowOpacity:    0.5,
      showCover:           true,
      mobileScrollSupport: true,
      startPage:           0,
      flippingTime:        650,
      usePortrait:         isMobile,   // single page on mobile, spread on desktop
      startZIndex:         0,
      autoSize:            true,
      clickEventForward:   false,
      swipeDistance:       30,
      showPageCorners:     !isMobile,
      disableFlipByClick:  false,
    });

    // Hide fullscreen label text on mobile to save space
    if (isMobile) fsLabel.style.display = 'none';

    pageFlip.loadFromImages(images);

    // ── Wire events ────────────────────────────────────────
    pageFlip.on('flip', (e) => {
      updateUI(e.data + 1, totalPages);
    });

    pageFlip.on('init', () => {
      overlay.style.display = 'none';
      flipWrap.classList.add('ready');
      btnPrev.disabled    = false;
      btnNext.disabled    = false;
      mobilePrev.disabled = false;
      mobileNext.disabled = false;
      updateUI(pageFlip.getCurrentPageIndex() + 1, totalPages);
      // Show pinch-to-zoom hint on mobile briefly
      if (isMobile) {
        const zh = document.getElementById('zoom-hint');
        setTimeout(() => { zh.classList.add('show'); }, 600);
        setTimeout(() => { zh.classList.remove('show'); }, 4000);
      }
    });

    // Arrow buttons (desktop side + mobile bottom)
    btnPrev.addEventListener('click', () => pageFlip.flipPrev());
    btnNext.addEventListener('click', () => pageFlip.flipNext());
    mobilePrev.addEventListener('click', () => pageFlip.flipPrev());
    mobileNext.addEventListener('click', () => pageFlip.flipNext());

    // Keyboard arrows
    document.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   pageFlip.flipPrev();
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown')  pageFlip.flipNext();
    });

    // ── Zoom (pinch on mobile, scroll wheel on desktop) ──────
    let zoomScale    = 1;
    let pinchStartDist  = 0;
    let pinchStartScale = 1;
    let isPinching   = false;
    let lastTapTime  = 0;
    const readerEl   = document.getElementById('reader-area');

    function applyZoom(scale, animate) {
      zoomScale = Math.min(4, Math.max(0.75, scale));
      flipWrap.style.transition = animate ? 'transform 0.18s ease' : 'none';
      flipWrap.style.transform  = `scale(${zoomScale})`;
    }

    // Mobile — pinch to zoom
    readerEl.addEventListener('touchstart', (e) => {
      if (e.touches.length === 2) {
        isPinching = true;
        pinchStartDist  = Math.hypot(
          e.touches[0].clientX - e.touches[1].clientX,
          e.touches[0].clientY - e.touches[1].clientY
        );
        pinchStartScale = zoomScale;
      }
    }, { passive: true });

    readerEl.addEventListener('touchmove', (e) => {
      if (e.touches.length === 2 && isPinching) {
        e.preventDefault();                        // block browser default zoom
        const dist = Math.hypot(
          e.touches[0].clientX - e.touches[1].clientX,
          e.touches[0].clientY - e.touches[1].clientY
        );
        applyZoom(pinchStartScale * (dist / pinchStartDist), false);
      }
    }, { passive: false });

    readerEl.addEventListener('touchend', (e) => {
      if (e.touches.length < 2) isPinching = false;
      // Double-tap to reset zoom
      if (e.touches.length === 0 && e.changedTouches.length === 1) {
        const now = Date.now();
        if (now - lastTapTime < 300) applyZoom(1, true);
        lastTapTime = now;
      }
    });

    // Desktop — scroll wheel to zoom
    readerEl.addEventListener('wheel', (e) => {
      e.preventDefault();
      applyZoom(zoomScale * (e.deltaY < 0 ? 1.12 : 0.89), true);
    }, { passive: false });

    // Responsive resize
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => {
        if (pageFlip) {
          const dims = getBookDimensions();
          // StPageFlip auto-resizes via autoSize:true, but we can nudge it
          pageFlip.updateState({ width: dims.w, height: dims.h });
        }
      }, 200);
    });

  } catch (err) {
    console.error('Flipbook error:', err);
    progressText.style.display = 'none';
    progressBar.parentElement.style.display = 'none';
    errorMsg.style.display = 'block';
    errorMsg.innerHTML =
      'Sorry, we couldn\'t load this magazine.<br>' +
      '<a href="/member/download_wings.php?id=<?= $id ?>" ' +
      'style="color:#F2C94C;text-decoration:underline">Download the PDF instead</a>';
  }
})();
</script>

</body>
</html>
