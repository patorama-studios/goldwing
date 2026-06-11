/* ============================================================================
 * wings-zoom.js — zoom/pan overlay for the Wings reader
 * ----------------------------------------------------------------------------
 * The second half of the two-mode state machine. At zoom = 1× the flip engine
 * owns gestures (browse mode). When the user pinches / double-taps / wheels in,
 * the reader hands control here: the flip book freezes behind this overlay and
 * we show the focused page as a high-res canvas the user can pan and zoom.
 *
 * Fixes the old reader's three zoom faults:
 *   • zoom is point-anchored (focal pinch / tap), not centre-only
 *   • drag-to-pan, clamped to bounds (you can reach the corners)
 *   • the page is RE-RENDERED at the zoomed resolution (sharp, not stretched)
 *
 * Classic script, exposes window.WingsZoom. Depends on WingsReaderCore.
 *
 * opts: { container, core, onExit }
 *   container : element the overlay is appended to (the reader area)
 *   core      : a WingsReaderCore (for high-res getPage canvases)
 *   onExit    : called when zoom returns to 1× (browse mode resumes)
 * ========================================================================== */
(function (window, document) {
  'use strict';

  var MAX_SCALE = 5;
  var EXIT_SCALE = 1.04;       // at/under this we drop back to browse mode
  var DOUBLE_TAP_MS = 300;
  var RERENDER_DEBOUNCE = 140; // ms after a gesture settles before re-rendering

  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }
  function dist(t1, t2) { return Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY); }
  function mid(t1, t2) { return { x: (t1.clientX + t2.clientX) / 2, y: (t1.clientY + t2.clientY) / 2 }; }

  function WingsZoom(opts) {
    this.container = opts.container;
    this.core = opts.core;
    this.onExit = opts.onExit || function () {};
    this.onEnter = opts.onEnter || function () {};
    this.active = false;

    this.scale = 1; this.tx = 0; this.ty = 0;
    this.pageIndex = 0;
    this.baseW = 0; this.baseH = 0;     // page display size (CSS px) at 1×
    this._rerenderTimer = null;
    this._renderedScale = 0;

    this._buildDom();
    this._bind();
  }

  WingsZoom.prototype._buildDom = function () {
    var self = this;
    var ov = document.createElement('div');
    ov.className = 'wings-zoom';
    var content = document.createElement('div');
    content.className = 'wings-zoom__content';
    ov.appendChild(content);
    // Explicit close affordance — never leave the user trapped in zoom.
    var close = document.createElement('button');
    close.className = 'wings-zoom__close';
    close.type = 'button';
    close.setAttribute('aria-label', 'Close zoom');
    close.innerHTML = '&times;';
    close.addEventListener('click', function (e) { e.stopPropagation(); self.close(); });
    ov.appendChild(close);
    this.container.appendChild(ov);
    this.el = ov;
    this.content = content;
  };

  // Animate back to fit and drop to browse mode (button / Esc / programmatic).
  WingsZoom.prototype.close = function () {
    if (!this.active) return;
    this.scale = 1; this._clamp(); this._apply(true);
    this._exit();
  };

  /* ---- entry (called by the reader) --------------------------------------- */
  // pageIndex: 0-based page to zoom. focal: client {x,y}. startScale: initial.
  // seedTouches: optional [t1,t2] so an in-progress pinch continues smoothly.
  WingsZoom.prototype.enter = function (pageIndex, focal, startScale, seedTouches) {
    var self = this;
    if (this.active) return;
    this.active = true;
    this.pageIndex = pageIndex;

    var rect = this.container.getBoundingClientRect();
    this.OW = rect.width; this.OH = rect.height;
    // Base page size = fit the page into the overlay (same as browse single-page).
    var aspect = this.core.aspect || (1 / 1.414);
    var h = this.OH, w = Math.round(h * aspect);
    if (w > this.OW) { w = this.OW; h = Math.round(w / aspect); }
    this.baseW = w; this.baseH = h;
    this.content.style.width = w + 'px';
    this.content.style.height = h + 'px';

    // Centre the page in the overlay at scale 1, then zoom toward the focal pt.
    this.scale = 1;
    this.tx = (this.OW - w) / 2;
    this.ty = (this.OH - h) / 2;
    this._renderedScale = 0;

    this.el.classList.add('is-active');
    this.el.style.pointerEvents = 'auto';
    this.onEnter();

    // First render at scale 1, then the zoom-to-focal kicks it sharper.
    this._render(1).then(function () {
      if (seedTouches && seedTouches.length === 2) {
        self._pinchStartDist = dist(seedTouches[0], seedTouches[1]);
        self._pinchStartScale = 1;
        self._pinching = true;
      } else {
        var fx = focal ? focal.x - rect.left : self.OW / 2;
        var fy = focal ? focal.y - rect.top : self.OH / 2;
        self._zoomTo(startScale || 2.5, fx, fy, true);
      }
    });
  };

  WingsZoom.prototype._exit = function () {
    var self = this;
    this.active = false;
    this.el.classList.remove('is-active');
    this.el.style.pointerEvents = 'none';
    this._pinching = false;
    this._panning = false;
    // Clear the heavy canvas so we don't keep a big bitmap around.
    setTimeout(function () { if (!self.active) self.content.innerHTML = ''; }, 250);
    this.onExit();
  };

  /* ---- transform + clamp -------------------------------------------------- */
  WingsZoom.prototype._apply = function (animate) {
    this.content.style.transition = animate ? 'transform .18s ease' : 'none';
    this.content.style.transform =
      'translate(' + this.tx + 'px,' + this.ty + 'px) scale(' + this.scale + ')';
  };

  WingsZoom.prototype._clamp = function () {
    var sw = this.baseW * this.scale, sh = this.baseH * this.scale;
    // Horizontal: centre if smaller than overlay, else keep edges within view.
    if (sw <= this.OW) this.tx = (this.OW - sw) / 2;
    else this.tx = clamp(this.tx, this.OW - sw, 0);
    if (sh <= this.OH) this.ty = (this.OH - sh) / 2;
    else this.ty = clamp(this.ty, this.OH - sh, 0);
  };

  // Zoom to `newScale` keeping the point (fx,fy) (overlay-relative) anchored.
  WingsZoom.prototype._zoomTo = function (newScale, fx, fy, animate) {
    newScale = clamp(newScale, 1, MAX_SCALE);
    var k = newScale / this.scale;
    this.tx = fx - (fx - this.tx) * k;
    this.ty = fy - (fy - this.ty) * k;
    this.scale = newScale;
    this._clamp();
    this._apply(animate);
    if (this.scale <= EXIT_SCALE) { this._exit(); return; }
    this._scheduleRerender();
  };

  /* ---- re-render the page crisp at the current zoom ----------------------- */
  WingsZoom.prototype._scheduleRerender = function () {
    var self = this;
    clearTimeout(this._rerenderTimer);
    this._rerenderTimer = setTimeout(function () { self._render(self.scale); }, RERENDER_DEBOUNCE);
  };

  WingsZoom.prototype._render = function (forScale) {
    var self = this;
    // Don't re-render for trivial scale changes already covered.
    if (Math.abs(forScale - this._renderedScale) < 0.25 && this._renderedScale >= forScale) {
      return Promise.resolve();
    }
    var targetCssW = Math.round(this.baseW * Math.max(1, forScale));
    return this.core.getPage(this.pageIndex + 1, { cssWidth: targetCssW }).then(function (canvas) {
      if (!canvas || !self.active) return;
      // Display the canvas at the page's base CSS size; the transform scales it.
      canvas.style.width = self.baseW + 'px';
      canvas.style.height = self.baseH + 'px';
      canvas.className = 'wings-zoom__canvas';
      self.content.innerHTML = '';
      self.content.appendChild(canvas);
      self._renderedScale = forScale;
    }).catch(function () {});
  };

  /* ---- gesture handlers --------------------------------------------------- */
  WingsZoom.prototype._bind = function () {
    var self = this;
    var el = this.el;

    // ---- touch ----
    el.addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        self._pinching = true;
        self._pinchStartDist = dist(e.touches[0], e.touches[1]);
        self._pinchStartScale = self.scale;
        self._panning = false;
      } else if (e.touches.length === 1) {
        var now = Date.now();
        if (now - (self._lastTap || 0) < DOUBLE_TAP_MS) {
          // double-tap: toggle between fit and 2.5× at the tap point
          var r = self.container.getBoundingClientRect();
          var fx = e.touches[0].clientX - r.left, fy = e.touches[0].clientY - r.top;
          self._zoomTo(self.scale > 1.3 ? 1 : 2.5, fx, fy, true);
          self._lastTap = 0;
        } else {
          self._lastTap = now;
          self._panning = true;
          self._panStart = { x: e.touches[0].clientX - self.tx, y: e.touches[0].clientY - self.ty };
        }
      }
    }, { passive: true });

    el.addEventListener('touchmove', function (e) {
      if (self._pinching && e.touches.length === 2) {
        e.preventDefault();
        var d = dist(e.touches[0], e.touches[1]);
        var m = mid(e.touches[0], e.touches[1]);
        var r = self.container.getBoundingClientRect();
        var ns = self._pinchStartScale * (d / self._pinchStartDist);
        self._zoomTo(ns, m.x - r.left, m.y - r.top, false);
      } else if (self._panning && e.touches.length === 1) {
        e.preventDefault();
        self.tx = e.touches[0].clientX - self._panStart.x;
        self.ty = e.touches[0].clientY - self._panStart.y;
        self._clamp();
        self._apply(false);
      }
    }, { passive: false });

    el.addEventListener('touchend', function (e) {
      if (e.touches.length < 2) self._pinching = false;
      if (e.touches.length === 0) {
        self._panning = false;
        if (self.scale <= EXIT_SCALE) self._exit();
        else self._scheduleRerender();
      }
    });

    // ---- mouse (desktop) ----
    el.addEventListener('mousedown', function (e) {
      e.preventDefault();
      self._panning = true;
      self._panStart = { x: e.clientX - self.tx, y: e.clientY - self.ty };
    });
    window.addEventListener('mousemove', function (e) {
      if (!self.active || !self._panning) return;
      self.tx = e.clientX - self._panStart.x;
      self.ty = e.clientY - self._panStart.y;
      self._clamp();
      self._apply(false);
    });
    window.addEventListener('mouseup', function () { self._panning = false; });

    el.addEventListener('dblclick', function (e) {
      var r = self.container.getBoundingClientRect();
      self._zoomTo(self.scale > 1.3 ? 1 : 2.5, e.clientX - r.left, e.clientY - r.top, true);
    });

    el.addEventListener('wheel', function (e) {
      if (!self.active) return;
      e.preventDefault();
      var r = self.container.getBoundingClientRect();
      var factor = e.deltaY < 0 ? 1.15 : 0.87;
      self._zoomTo(self.scale * factor, e.clientX - r.left, e.clientY - r.top, false);
    }, { passive: false });

    // Esc exits zoom (keyboard + accessibility).
    document.addEventListener('keydown', function (e) {
      if (self.active && (e.key === 'Escape' || e.key === '0')) { e.preventDefault(); self.close(); }
    });
  };

  WingsZoom.prototype.isActive = function () { return this.active; };
  WingsZoom.prototype.destroy = function () {
    if (this.el && this.el.parentNode) this.el.remove();
  };

  window.WingsZoom = WingsZoom;
})(window, document);
