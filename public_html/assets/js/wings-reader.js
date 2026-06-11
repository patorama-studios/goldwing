/* ============================================================================
 * wings-reader.js — reader shell: flip engine + navigation + view modes
 * ----------------------------------------------------------------------------
 * Sits on top of WingsReaderCore. Owns the page-flip animation (StPageFlip,
 * vendored), browse-mode navigation, the single/double view toggle, and lazy
 * feeding of rendered pages into the flip book.
 *
 * Pages are fed as <img> (blob URLs from the core) because the flip engine
 * clones page nodes mid-turn and a cloned <canvas> would be blank. Raw canvases
 * are reserved for the zoom overlay (added in Phase 3).
 *
 * Classic script, no build step. Exposes window.WingsReader. Depends on
 * WingsReaderCore + StPageFlip (window.St) being loaded first.
 *
 * Emits (via .on(event, cb)):
 *   'ready'       -> ({ total })            once the book is shown
 *   'statechange' -> ({ page, total, mode })  on every page/mode change
 * ========================================================================== */
(function (window, document) {
  'use strict';

  var MOBILE_MAX = 1023;       // <=1023 px viewport defaults to single-page
  var WINDOW_AHEAD = 2;        // pages to pre-render ahead of the current one
  var WINDOW_BEHIND = 1;       // and behind

  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }

  function WingsReader(container, opts) {
    opts = opts || {};
    this.container = typeof container === 'string'
      ? document.querySelector(container) : container;
    this.opts = opts;
    this.core = opts.core || new window.WingsReaderCore({
      url: opts.url, workerSrc: opts.workerSrc
    });
    this.pageFlip = null;
    this.total = 0;
    this.current = 0;            // 0-based page index
    // Persisted view-mode preference wins over the opts default.
    var saved = null;
    try { saved = window.localStorage.getItem('wings.mode'); } catch (e) {}
    this.mode = saved || opts.mode || 'auto';   // 'auto' | 'single' | 'double'
    this._slots = [];            // the .wings-page elements
    this._srcByPage = {};        // pageIndex -> blob URL already set on a slot
    this._listeners = {};
    this._destroyed = false;
    this._relayoutTimer = null;
    this._onResize = this._onResize.bind(this);
  }

  WingsReader.prototype.on = function (evt, cb) {
    (this._listeners[evt] = this._listeners[evt] || []).push(cb);
    return this;
  };
  WingsReader.prototype._emit = function (evt, data) {
    (this._listeners[evt] || []).forEach(function (cb) { try { cb(data); } catch (e) {} });
  };

  /* ---- effective mode (resolve 'auto' against viewport) ------------------- */
  WingsReader.prototype._effectiveMode = function () {
    if (this.mode === 'single' || this.mode === 'double') return this.mode;
    return (window.innerWidth <= MOBILE_MAX) ? 'single' : 'double';
  };

  /* ---- book sizing -------------------------------------------------------- */
  // Returns the size of ONE page in CSS px for the current mode + container.
  WingsReader.prototype._pageSize = function () {
    var single = this._effectiveMode() === 'single';
    var availW = this.container.clientWidth;
    var availH = this.container.clientHeight;
    var aspect = this.core.aspect || (1 / 1.414);   // w:h

    var w, h;
    if (single) {
      h = availH;
      w = Math.round(h * aspect);
      if (w > availW) { w = availW; h = Math.round(w / aspect); }
    } else {
      // two pages side by side fill the width
      w = Math.floor(availW / 2);
      h = Math.round(w / aspect);
      if (h > availH) { h = availH; w = Math.round(h * aspect); }
    }
    return { w: Math.max(80, w), h: Math.max(113, h), single: single };
  };

  /* ---- lifecycle ---------------------------------------------------------- */
  WingsReader.prototype.init = function () {
    var self = this;
    return this.core.load().then(function () {
      self.total = self.core.numPages;
      self._build();
      self._initZoom();
      window.addEventListener('resize', self._onResize);
      window.addEventListener('orientationchange', self._onResize);
      // Keyboard page turning (the zoom layer owns Esc/0 while zoomed).
      document.addEventListener('keydown', function (e) {
        if (self.zoom && self.zoom.isActive()) return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown') { e.preventDefault(); self.next(); }
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') { e.preventDefault(); self.prev(); }
      });
      return self;
    });
  };

  /* ---- zoom/pan entry (the other half of the two-mode state machine) ------ */
  WingsReader.prototype._initZoom = function () {
    if (!window.WingsZoom) return;           // zoom layer optional
    var self = this;
    this.zoom = new window.WingsZoom({
      container: this.container,
      core: this.core,
      onEnter: function () { self._emit('zoomenter', {}); },
      onExit: function () { self._emit('zoomexit', {}); self._emitState(); }
    });

    // Which page should a focal point zoom? In double mode the spread shows
    // [current, current+1]; left half -> current, right half -> current+1.
    function focalPage(clientX) {
      if (self._effectiveMode() === 'single') return self.current;
      var r = self.container.getBoundingClientRect();
      var rightHalf = (clientX - r.left) > r.width / 2;
      var idx = self.current + (rightHalf ? 1 : 0);
      return Math.min(self.total - 1, Math.max(0, idx));
    }

    // Two-finger pinch -> enter zoom (capture phase so the flip engine, bound
    // on a descendant, never sees it). One finger passes through to the flip.
    this.container.addEventListener('touchstart', function (e) {
      if (self.zoom.isActive()) return;
      if (e.touches.length === 2) {
        e.stopPropagation();
        var m = (e.touches[0].clientX + e.touches[1].clientX) / 2;
        self.zoom.enter(focalPage(m), null, 1, [e.touches[0], e.touches[1]]);
      }
    }, { capture: true });

    // Double-tap/click and wheel-in also enter zoom.
    this.container.addEventListener('dblclick', function (e) {
      if (self.zoom.isActive()) return;
      self.zoom.enter(focalPage(e.clientX), { x: e.clientX, y: e.clientY }, 2.5);
    });
    this.container.addEventListener('wheel', function (e) {
      if (self.zoom.isActive() || e.deltaY >= 0) return;
      e.preventDefault();
      self.zoom.enter(focalPage(e.clientX), { x: e.clientX, y: e.clientY }, 1.6);
    }, { passive: false });
  };

  // (Re)build the slot DOM + flip instance for the current mode at `current`.
  //
  // StPageFlip.destroy() calls `block.remove()` — it deletes the element it was
  // given. So we hand it a THROWAWAY inner element it can mangle/remove, while
  // our outer `container` persists across mode toggles and breakpoint rebuilds.
  WingsReader.prototype._build = function () {
    var self = this;
    var size = this._pageSize();
    var reduceMotion = window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Remember where we are; loadFromHTML below fires a spurious flip(0) during
    // (re)build that would otherwise clobber our place. Suppress flip events
    // until we've restored the target page.
    var startAt = this.current || 0;
    this._suppressFlip = true;

    if (this.pageFlip) { try { this.pageFlip.destroy(); } catch (e) {} this.pageFlip = null; }
    if (this._inner && this._inner.parentNode) this._inner.remove();   // belt-and-braces
    this._inner = document.createElement('div');
    this._inner.className = 'wings-book-inner';
    this.container.appendChild(this._inner);
    this._slots = [];

    for (var i = 0; i < this.total; i++) {
      var pageEl = document.createElement('div');
      pageEl.className = 'wings-page';
      // Covers are stiff (hard) pages; interior leaves flex (soft).
      pageEl.setAttribute('data-density', (i === 0 || i === this.total - 1) ? 'hard' : 'soft');
      var img = document.createElement('img');
      img.className = 'wings-page__img';
      img.alt = 'Page ' + (i + 1);
      img.draggable = false;
      if (this._srcByPage[i]) img.src = this._srcByPage[i];   // reuse across rebuilds
      pageEl.appendChild(img);
      this._inner.appendChild(pageEl);
      this._slots.push(pageEl);
    }

    this.pageFlip = new window.St.PageFlip(this._inner, {
      width: size.w,
      height: size.h,
      size: 'fixed',
      usePortrait: size.single,
      showCover: true,
      autoSize: false,
      maxShadowOpacity: 0.5,
      flippingTime: reduceMotion ? 0 : 700,   // honour prefers-reduced-motion
      swipeDistance: 30,
      drawShadow: true,
      useMouseEvents: true,
      mobileScrollSupport: false,   // we own zoom gestures (Phase 3)
      clickEventForward: false,
      disableFlipByClick: true      // click/tap is reserved for double-tap-to-zoom
    });

    this.pageFlip.on('flip', function (e) {
      if (self._suppressFlip) return;        // ignore build/restore noise
      self.current = e.data;
      self._renderWindow();
      self._emitState();
    });
    this.pageFlip.on('changeState', function () { /* reserved for zoom handoff */ });

    this.pageFlip.loadFromHTML(this._inner.querySelectorAll('.wings-page'));

    // Render the visible window (around startAt) first, then jump there and
    // re-enable flip tracking. Reveal once the current page is in.
    this.current = startAt;
    this._renderWindow(true).then(function () {
      if (self._destroyed) return;
      if (startAt > 0) self.pageFlip.turnToPage(startAt);
      self.current = startAt;
      self._suppressFlip = false;
      self.container.classList.add('is-ready');
      self._emit('ready', { total: self.total });
      self._emitState();
    });
  };

  /* ---- lazy rendering ----------------------------------------------------- */
  WingsReader.prototype._windowPages = function () {
    var single = this._effectiveMode() === 'single';
    // In double mode the visible spread is two pages; widen the window.
    var lo = this.current - (single ? WINDOW_BEHIND : WINDOW_BEHIND + 1);
    var hi = this.current + (single ? WINDOW_AHEAD : WINDOW_AHEAD + 1);
    var pages = [];
    for (var p = Math.max(0, lo); p <= Math.min(this.total - 1, hi); p++) pages.push(p);
    return pages;
  };

  // Ensure the window pages have their <img> filled. If `awaitCurrent`, the
  // returned promise resolves once the current page (and its spread mate) is in.
  WingsReader.prototype._renderWindow = function (awaitCurrent) {
    var self = this;
    var size = this._pageSize();
    var pages = this._windowPages();

    this.core.cancelOutside(Math.max(1, pages[0] + 1), Math.min(this.total, pages[pages.length - 1] + 1));

    var mustWait = [];
    pages.forEach(function (p) {
      var pr = self._ensurePage(p, size.w);
      if (awaitCurrent && (p === self.current || p === self.current + 1)) mustWait.push(pr);
    });
    return Promise.all(mustWait);
  };

  // Render+assign one page's image if not already at the right resolution.
  WingsReader.prototype._ensurePage = function (pageIndex, cssWidth) {
    var self = this;
    var slot = this._slots[pageIndex];
    if (!slot) return Promise.resolve();
    var img = slot.firstChild;
    var want = Math.round(cssWidth);
    if (img._renderedW === want && img.src) return Promise.resolve();   // already good

    return this.core.getPageImage(pageIndex + 1, { cssWidth: cssWidth }).then(function (url) {
      if (self._destroyed || !url) return;
      img.src = url;
      img._renderedW = want;
      self._srcByPage[pageIndex] = url;
    }).catch(function () {});
  };

  /* ---- navigation --------------------------------------------------------- */
  WingsReader.prototype.next = function () { if (this.pageFlip) this.pageFlip.flipNext(); };
  WingsReader.prototype.prev = function () { if (this.pageFlip) this.pageFlip.flipPrev(); };
  WingsReader.prototype.goTo = function (pageIndex) {
    pageIndex = clamp(pageIndex | 0, 0, this.total - 1);
    this.current = pageIndex;
    this._renderWindow(true).then(function () {});
    if (this.pageFlip) this.pageFlip.turnToPage(pageIndex);
  };

  /* ---- view mode ---------------------------------------------------------- */
  WingsReader.prototype.getMode = function () { return this._effectiveMode(); };
  WingsReader.prototype.setMode = function (mode) {
    if (mode !== 'single' && mode !== 'double' && mode !== 'auto') return;
    this.mode = mode;
    try { window.localStorage.setItem('wings.mode', mode); } catch (e) {}
    this._build();              // rebuild preserves `current` + cached srcs
    this._emitState();
  };

  /* ---- resize ------------------------------------------------------------- */
  WingsReader.prototype._onResize = function () {
    var self = this;
    clearTimeout(this._relayoutTimer);
    this._relayoutTimer = setTimeout(function () { self.relayout(); }, 180);
  };
  WingsReader.prototype.relayout = function () {
    if (!this.pageFlip || this._destroyed) return;
    var prevSingle = this.pageFlip.getOrientation() === 'portrait';
    var size = this._pageSize();
    // A mode flip (auto crossing the breakpoint) needs a full rebuild.
    if (size.single !== prevSingle) { this._build(); return; }
    this.pageFlip.updateState({ width: size.w, height: size.h });
    this._renderWindow();       // re-render window at the new resolution bucket
  };

  WingsReader.prototype.getCurrentPage = function () { return this.current + 1; };
  WingsReader.prototype.getTotal = function () { return this.total; };
  WingsReader.prototype._emitState = function () {
    this._emit('statechange', {
      page: this.current + 1, total: this.total, mode: this._effectiveMode()
    });
  };

  WingsReader.prototype.destroy = function () {
    this._destroyed = true;
    window.removeEventListener('resize', this._onResize);
    window.removeEventListener('orientationchange', this._onResize);
    if (this.pageFlip) { try { this.pageFlip.destroy(); } catch (e) {} }
    if (this.zoom) this.zoom.destroy();
    this.core.destroy();
  };

  window.WingsReader = WingsReader;
})(window, document);
