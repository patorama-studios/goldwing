/* ============================================================================
 * wings-reader-core.js — on-the-fly PDF render core for the Wings reader
 * ----------------------------------------------------------------------------
 * Renders pages straight off the PDF (no pre-baked/stored images), lazily and
 * at the device's real pixel density, with a small LRU cache. This is the layer
 * that fixes the old reader's blur: it never CSS-stretches a flat raster — it
 * re-rasterises the page at whatever resolution it's actually displayed at.
 *
 * No build step, no ES modules — classic script exposing window.WingsReaderCore.
 * Depends on PDF.js (vendored) already being loaded on the page.
 *
 * Usage:
 *   const core = new WingsReaderCore({ url: '/member/download_wings.php?id=1&view=1' });
 *   await core.load();                     // opens the PDF (streamed via ranges)
 *   const canvas = await core.getPage(1, { cssWidth: 380 });  // render page 1
 *   core.prefetch(1, 2);                   // warm neighbours in the background
 * ========================================================================== */
(function (window) {
  'use strict';

  // Cap the backing-store scale so a DPR-3 phone zoomed in doesn't allocate a
  // monster canvas (mobile Safari kills tabs past ~16M canvas px / ~4096 a side).
  var MAX_DPR = 3;
  var MAX_CANVAS_DIM = 4096;     // hard ceiling on either canvas dimension
  var DEFAULT_CACHE = 8;         // rendered pages kept in memory (LRU)
  var RENDER_CONCURRENCY = 2;    // simultaneous PDF.js rasterisations

  function devicePixelRatioCapped() {
    return Math.min(Math.max(window.devicePixelRatio || 1, 1), MAX_DPR);
  }

  // Bucket the requested backing width so tiny resizes reuse the same render,
  // but a real zoom (much larger width) gets a fresh, sharper one.
  function bucketOf(backingWidth) {
    return Math.round(backingWidth / 128) * 128;
  }

  /* ---- tiny LRU of rendered canvases -------------------------------------- */
  function LRU(capacity) {
    this.capacity = capacity;
    this.map = new Map();        // key -> canvas (Map preserves insertion order)
  }
  LRU.prototype.get = function (key) {
    if (!this.map.has(key)) return null;
    var v = this.map.get(key);
    this.map.delete(key);        // re-insert to mark most-recently-used
    this.map.set(key, v);
    return v;
  };
  LRU.prototype.set = function (key, val) {
    if (this.map.has(key)) this.map.delete(key);
    this.map.set(key, val);
    while (this.map.size > this.capacity) {
      var oldestKey = this.map.keys().next().value;
      var c = this.map.get(oldestKey);
      this.map.delete(oldestKey);
      // Free the GPU/heap backing of evicted canvases promptly.
      if (c) { c.width = 0; c.height = 0; }
    }
  };
  LRU.prototype.clear = function () {
    this.map.forEach(function (c) { if (c) { c.width = 0; c.height = 0; } });
    this.map.clear();
  };

  /* ---- LRU of blob-URL strings (revokes URLs on eviction) ----------------- */
  function ImgLRU(capacity) {
    this.capacity = capacity;
    this.map = new Map();
  }
  ImgLRU.prototype.get = function (key) {
    if (!this.map.has(key)) return null;
    var v = this.map.get(key);
    this.map.delete(key); this.map.set(key, v);
    return v;
  };
  ImgLRU.prototype.set = function (key, url) {
    if (this.map.has(key)) { URL.revokeObjectURL(this.map.get(key)); this.map.delete(key); }
    this.map.set(key, url);
    while (this.map.size > this.capacity) {
      var oldestKey = this.map.keys().next().value;
      URL.revokeObjectURL(this.map.get(oldestKey));
      this.map.delete(oldestKey);
    }
  };
  ImgLRU.prototype.clear = function () {
    this.map.forEach(function (url) { URL.revokeObjectURL(url); });
    this.map.clear();
  };

  /* ---- the render core ---------------------------------------------------- */
  function WingsReaderCore(opts) {
    opts = opts || {};
    this.url = opts.url;
    this.pdf = null;
    this.numPages = 0;
    this.aspect = 1 / 1.414;     // w:h, refined from page 1 once loaded
    this._pageProxies = {};      // pageNum -> PDFPageProxy (cached, cheap)
    this._cache = new LRU(opts.cacheSize || DEFAULT_CACHE);
    this._inFlight = {};         // key -> { task, promise } for de-dupe/cancel
    this._queue = [];            // pending render thunks
    this._active = 0;            // currently-running renders

    var lib = window['pdfjs-dist/build/pdf'] || window.pdfjsLib;
    if (!lib) throw new Error('WingsReaderCore: PDF.js not loaded');
    this._lib = lib;
    if (opts.workerSrc) lib.GlobalWorkerOptions.workerSrc = opts.workerSrc;
  }

  WingsReaderCore.prototype.load = function () {
    var self = this;
    var task = this._lib.getDocument({
      url: this.url,
      withCredentials: true,     // the PDF endpoint is auth-gated (member benefit)
      // Lazy chunked loading. On a range-capable server PDF.js streams; on our
      // download_wings.php (full file, no ranges) it loads the whole PDF — same
      // as the previous reader. disableAutoFetch avoids prefetching the lot.
      rangeChunkSize: 262144,
      disableAutoFetch: true,
      disableStream: false
    });
    return task.promise.then(function (pdf) {
      self.pdf = pdf;
      self.numPages = pdf.numPages;
      return pdf.getPage(1);
    }).then(function (page) {
      self._pageProxies[1] = page;
      var vp = page.getViewport({ scale: 1 });
      self.aspect = vp.width / vp.height;
      return self;
    });
  };

  WingsReaderCore.prototype._getPageProxy = function (n) {
    var self = this;
    if (this._pageProxies[n]) return Promise.resolve(this._pageProxies[n]);
    return this.pdf.getPage(n).then(function (p) {
      self._pageProxies[n] = p;
      return p;
    });
  };

  WingsReaderCore.prototype._pump = function () {
    while (this._active < RENDER_CONCURRENCY && this._queue.length) {
      var thunk = this._queue.shift();
      thunk();
    }
  };

  /**
   * Render a page to a canvas sized for display at `cssWidth` CSS pixels.
   * Resolution = cssWidth * devicePixelRatio (capped) — the DPR-aware fix.
   * For zoom, pass a larger cssWidth (e.g. cssWidth * zoomLevel) and the page
   * is re-rasterised crisp rather than stretched.
   *
   * @returns {Promise<HTMLCanvasElement>}
   */
  WingsReaderCore.prototype.getPage = function (pageNum, o) {
    o = o || {};
    var self = this;
    var dpr = o.dpr || devicePixelRatioCapped();
    var cssWidth = Math.max(1, Math.round(o.cssWidth || 380));
    var backingWidth = Math.round(cssWidth * dpr);
    var bucket = bucketOf(backingWidth);
    var key = pageNum + ':' + bucket;

    var cached = this._cache.get(key);
    if (cached) return Promise.resolve(cached);
    if (this._inFlight[key]) return this._inFlight[key].promise;

    var entry = {};
    entry.promise = new Promise(function (resolve, reject) {
      var thunk = function () {
        self._active++;
        self._getPageProxy(pageNum).then(function (page) {
          // scale maps PDF units -> backing-store px for the requested width
          var unit = page.getViewport({ scale: 1 });
          var scale = (bucket / unit.width);
          var viewport = page.getViewport({ scale: scale });

          // Respect the canvas dimension ceiling (downscale if a deep zoom on a
          // hi-DPR screen would otherwise overflow it).
          var maxDim = Math.max(viewport.width, viewport.height);
          if (maxDim > MAX_CANVAS_DIM) {
            scale *= (MAX_CANVAS_DIM / maxDim);
            viewport = page.getViewport({ scale: scale });
          }

          var canvas = document.createElement('canvas');
          canvas.width = Math.floor(viewport.width);
          canvas.height = Math.floor(viewport.height);
          // CSS size = logical layout size; backing store is denser → sharp.
          canvas.style.width = cssWidth + 'px';
          canvas.style.height = Math.round(cssWidth / (viewport.width / viewport.height)) + 'px';
          var ctx = canvas.getContext('2d', { alpha: false });

          var task = page.render({ canvasContext: ctx, viewport: viewport });
          entry.task = task;
          return task.promise.then(function () { return canvas; });
        }).then(function (canvas) {
          self._cache.set(key, canvas);
          resolve(canvas);
        }).catch(function (err) {
          // A cancelled render (navigated away) is expected, not an error.
          if (err && err.name === 'RenderingCancelledException') resolve(null);
          else reject(err);
        }).then(function () {
          self._active--;
          delete self._inFlight[key];
          self._pump();
        });
      };
      self._queue.push(thunk);
      self._pump();
    });

    this._inFlight[key] = entry;
    return entry.promise;
  };

  /**
   * Like getPage(), but resolves to a blob-URL string for an <img>.
   *
   * The flip engine clones page nodes for its turn animation, and a cloned
   * <canvas> comes out blank — so flip content must be an <img>, not a canvas.
   * A blob URL is far lighter than a base64 data URL (holds compressed bytes,
   * not raw RGBA in a string) and clones keep working. URLs are revoked when
   * evicted from this cache. Use this for browse/flip mode; use getPage() (raw
   * canvas) for the zoom overlay where there's no clone and we want max sharp.
   *
   * @returns {Promise<string|null>} object URL, or null if the render cancelled
   */
  WingsReaderCore.prototype.getPageImage = function (pageNum, o) {
    o = o || {};
    var self = this;
    var dpr = o.dpr || devicePixelRatioCapped();
    var bucket = bucketOf(Math.round(Math.max(1, o.cssWidth || 380) * dpr));
    var key = pageNum + ':' + bucket;

    if (!this._imgCache) this._imgCache = new ImgLRU(this._cache.capacity);
    var hit = this._imgCache.get(key);
    if (hit) return Promise.resolve(hit);
    if (!this._imgInFlight) this._imgInFlight = {};
    if (this._imgInFlight[key]) return this._imgInFlight[key];

    var p = this.getPage(pageNum, o).then(function (canvas) {
      if (!canvas) return null;
      return new Promise(function (resolve) {
        // WebP keeps text crisp and files tiny; high quality since it's display.
        canvas.toBlob(function (blob) {
          if (!blob) { resolve(null); return; }
          var url = URL.createObjectURL(blob);
          self._imgCache.set(key, url);
          resolve(url);
        }, 'image/webp', 0.92);
      });
    }).then(function (url) {
      delete self._imgInFlight[key];
      return url;
    }, function (err) {
      delete self._imgInFlight[key];
      throw err;
    });

    this._imgInFlight[key] = p;
    return p;
  };

  /** Warm a set of pages (current ± neighbours) in the background. */
  WingsReaderCore.prototype.prefetch = function (cssWidth /*, p1, p2, ... */) {
    var pages = Array.prototype.slice.call(arguments, 1);
    var self = this;
    pages.forEach(function (n) {
      if (n >= 1 && n <= self.numPages) self.getPage(n, { cssWidth: cssWidth });
    });
  };

  /** Cancel in-flight renders whose page is outside [lo, hi] (navigation). */
  WingsReaderCore.prototype.cancelOutside = function (lo, hi) {
    var self = this;
    Object.keys(this._inFlight).forEach(function (key) {
      var p = parseInt(key.split(':')[0], 10);
      if (p < lo || p > hi) {
        var e = self._inFlight[key];
        if (e && e.task) { try { e.task.cancel(); } catch (x) {} }
      }
    });
    // Drop queued (not yet started) renders outside the window too.
    this._queue = this._queue.filter(function () { return true; }); // queue thunks are opaque; rely on cancel above
  };

  WingsReaderCore.prototype.destroy = function () {
    this._cache.clear();
    if (this._imgCache) this._imgCache.clear();
    if (this.pdf) { try { this.pdf.destroy(); } catch (x) {} }
    this._pageProxies = {};
  };

  window.WingsReaderCore = WingsReaderCore;
})(window);
