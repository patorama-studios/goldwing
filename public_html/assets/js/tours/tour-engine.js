/* Goldwing tour engine.
 *
 * Loads the manifest, registers tours, runs them through Driver.js with our
 * senior-friendly config, and supports a "validator mode" used by the admin
 * Tour Validator page to score each step.
 *
 * Globals exposed on window.GoldwingTours:
 *   register(slug, steps)   - tour files call this on load
 *   run(slug, opts)         - start a tour for the current user
 *   runInValidator(slug)    - same, but with the validator bar
 *   currentSlug             - last tour started
 *   manifest                - parsed JSON manifest, injected by PHP
 *   completions             - { slug: true } map for the logged-in user
 */
(function () {
  'use strict';

  if (typeof window.driver !== 'function' && typeof window.driver !== 'object') {
    console.warn('[GoldwingTours] Driver.js not loaded — tours disabled.');
    return;
  }

  // driver.js IIFE exposes a `driver` global. Pick the constructor:
  var driverFactory = (window.driver && window.driver.js && window.driver.js.driver)
    || window.driver.driver
    || window.driver;
  if (typeof driverFactory !== 'function') {
    console.warn('[GoldwingTours] Driver.js global shape unexpected.', window.driver);
    return;
  }

  var registered = {};      // slug -> { steps: [...] }
  var GT = {
    manifest: { tours: {} },
    completions: {},
    currentSlug: null,
    csrfToken: '',
    isAdmin: false,
  };

  GT.register = function (slug, steps) {
    if (!slug || !Array.isArray(steps)) return;
    registered[slug] = { steps: steps };
  };

  /** Default Driver.js config — senior-friendly. */
  function baseConfig(overrides) {
    var cfg = {
      animate: true,
      showProgress: true,
      progressText: 'Step {{current}} of {{total}}',
      nextBtnText: 'Next',
      prevBtnText: 'Go back',
      doneBtnText: 'All done!',
      allowClose: true,
      overlayOpacity: 0.55,
      stagePadding: 6,
      smoothScroll: true,
      disableActiveInteraction: false,    // let users click while a step is open
    };
    if (overrides) {
      for (var k in overrides) cfg[k] = overrides[k];
    }
    return cfg;
  }

  /** Resolve a tour. Source of truth is the DB via /admin/help/api_steps.php.
   *  In-memory register() entries (legacy JS step files) are still respected
   *  as a fallback so locally-registered tours can override DB content while
   *  authoring.
   *  @param opts.preview when true, ask the API for draft-overlaid steps. */
  function ensureLoaded(slug, opts, cb) {
    if (typeof opts === 'function') { cb = opts; opts = {}; }
    opts = opts || {};
    if (!opts.preview && registered[slug]) {
      cb(null, registered[slug]);
      return;
    }
    var entry = GT.manifest.tours && GT.manifest.tours[slug];
    if (!entry) {
      cb(new Error('Tour not in manifest: ' + slug));
      return;
    }
    var url = '/admin/help/api_steps.php?slug=' + encodeURIComponent(slug) +
              (opts.preview ? '&preview=1' : '');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data || !Array.isArray(data.steps) || data.steps.length === 0) {
          throw new Error('No steps returned for ' + slug);
        }
        cb(null, { steps: data.steps });
      })
      .catch(function (err) { cb(err); });
  }

  /** Record completion server-side (best-effort, never blocks). */
  function reportCompletion(slug) {
    try {
      fetch('/admin/help/api_complete.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug: slug, csrf: GT.csrfToken }),
      });
    } catch (e) { /* swallow */ }
    GT.completions[slug] = true;
  }

  GT.run = function (slug, opts) {
    opts = opts || {};
    ensureLoaded(slug, { preview: !!opts.preview }, function (err, tour) {
      if (err) { console.warn('[GoldwingTours]', err.message); return; }
      GT.currentSlug = slug;
      var cfg = baseConfig({
        steps: tour.steps,
        onDestroyed: function () {
          if (opts.onClose) opts.onClose();
        },
        onCloseClick: function (el, step, options) {
          // user clicked X — close without marking complete
          options.driver.destroy();
        },
      });
      var d = driverFactory(cfg);
      // Wrap the last step's "All done" to mark completion
      var originalNext = d.moveNext;
      d.moveNext = function () {
        var idx = d.getActiveIndex();
        var total = tour.steps.length;
        if (idx === total - 1) {
          reportCompletion(slug);
          if (opts.onComplete) opts.onComplete();
        }
        return originalNext.apply(d, arguments);
      };
      d.drive();
    });
  };

  /* ===== Validator mode =====
   * Wraps the tour with a fixed bottom bar that asks the tester after each step:
   *   - Did the popover make sense?    [Pass / Fail / Skip]
   * Results are POSTed to /admin/help/api_validator.php at the end.
   */
  GT.runInValidator = function (slug, runAsRole) {
    ensureLoaded(slug, {}, function (err, tour) {
      if (err) { alert('Could not load tour: ' + err.message); return; }
      GT.currentSlug = slug;

      var results = [];
      var bar = document.createElement('div');
      bar.className = 'gw-validator-bar';
      bar.innerHTML =
        '<div><strong>Validator</strong> — step <span class="gw-vb-current">1</span> of ' + tour.steps.length +
        ' &nbsp;|&nbsp; tour: <code>' + slug + '</code></div>' +
        '<div>' +
          '<button class="gw-vb-pass" type="button">Step looks right</button> ' +
          '<button class="gw-vb-fail" type="button">Wrong / confusing</button> ' +
          '<button class="gw-vb-skip" type="button">Skip</button>' +
        '</div>';
      document.body.appendChild(bar);

      function setStepNumber(n) {
        var el = bar.querySelector('.gw-vb-current');
        if (el) el.textContent = n;
      }

      var d;
      function record(verdict, note) {
        var idx = d.getActiveIndex();
        var step = tour.steps[idx] || {};
        results.push({
          step_index: idx,
          element: (step.element || ''),
          verdict: verdict,
          title: (step.popover && step.popover.title) || '',
          note: note || '',
        });
      }

      bar.querySelector('.gw-vb-pass').addEventListener('click', function () {
        record('pass', '');
        d.moveNext();
      });
      bar.querySelector('.gw-vb-fail').addEventListener('click', function () {
        var note = prompt('What was wrong with this step? (optional)') || '';
        record('fail', note);
        d.moveNext();
      });
      bar.querySelector('.gw-vb-skip').addEventListener('click', function () {
        record('skip', '');
        d.moveNext();
      });

      var cfg = baseConfig({
        steps: tour.steps,
        showButtons: ['close'],          // hide built-in next/back — use the bar
        onHighlightStarted: function () {
          setStepNumber(d.getActiveIndex() + 1);
        },
        onDestroyed: function () {
          bar.parentNode && bar.parentNode.removeChild(bar);
          var anyFail = results.some(function (r) { return r.verdict === 'fail'; });
          var anySkip = results.some(function (r) { return r.verdict === 'skip'; });
          var status = anyFail ? 'fail' : (anySkip ? 'partial' : 'pass');
          fetch('/admin/help/api_validator.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              slug: slug,
              status: status,
              run_as_role: runAsRole || null,
              results: results,
              csrf: GT.csrfToken,
            }),
          }).then(function () {
            alert('Tour result saved: ' + status.toUpperCase() +
                  '\nYou can review it on the Tour Validator page.');
          });
        },
      });
      d = driverFactory(cfg);
      d.drive();
    });
  };

  window.GoldwingTours = GT;

  // URL autostart — if the page was loaded with ?tour=<slug>, run that tour
  // automatically once the manifest + Driver.js are ready. Used by the
  // System Documentation's "Walk me through this" buttons to drop the user
  // straight onto the right page with the tour already running.
  //
  // The deep-link form is: /admin/<page>?tour=<slug>  (the page_url for the
  // tour, with an extra ?tour=… that this hook picks up). The hook tolerates
  // both `?tour=…` and `&tour=…` and is a no-op when the slug isn't in the
  // manifest or doesn't match the current page.
  function tryAutostart() {
    try {
      var params = new URLSearchParams(window.location.search);
      var slugFromUrl = params.get('tour');
      var slugFromStorage = null;
      try { slugFromStorage = sessionStorage.getItem('gw_pending_tour'); } catch (e) {}
      var slug = slugFromUrl || slugFromStorage;
      if (!slug) return;

      if (slugFromUrl) {
        // Strip the param so refreshing or sharing the URL doesn't keep
        // re-triggering the tour every time.
        params.delete('tour');
        var newSearch = params.toString();
        var clean = window.location.pathname + (newSearch ? '?' + newSearch : '') + window.location.hash;
        try { window.history.replaceState(null, '', clean); } catch (e) {}
      }

      var entry = GT.manifest.tours && GT.manifest.tours[slug];
      if (!entry) {
        console.warn('[GoldwingTours] Autostart: tour not in manifest:', slug);
        try { sessionStorage.removeItem('gw_pending_tour'); } catch (e) {}
        return;
      }
      // Page-match guard — if the tour expects a different page (e.g. it walks
      // through the order detail screen but the docs button sent us to the
      // orders list so the admin can pick one), stash the slug in
      // sessionStorage and run it once we land somewhere matching.
      var match = entry.page_match || entry.page_url || '';
      if (match && window.location.href.indexOf(match) === -1) {
        try { sessionStorage.setItem('gw_pending_tour', slug); } catch (e) {}
        // Tiny inline hint so the admin knows what they're meant to do next.
        showPendingHint(entry);
        return;
      }
      // We're on the right page — clear sessionStorage and run.
      try { sessionStorage.removeItem('gw_pending_tour'); } catch (e) {}
      // Wait a beat for the page to settle (layout, fonts, sidebar) so
      // Driver.js can locate elements reliably.
      setTimeout(function () { GT.run(slug); }, 250);
    } catch (e) {
      console.warn('[GoldwingTours] Autostart error:', e);
    }
  }

  /** Floating banner shown while a tour is waiting for the admin to navigate
   *  to the right page. Tour fires automatically once they get there. */
  function showPendingHint(entry) {
    if (document.querySelector('[data-gw-tour-hint]')) return; // already shown
    var hint = document.createElement('div');
    hint.setAttribute('data-gw-tour-hint', '');
    hint.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99999;'
      + 'background:#2F7D32;color:#fff;padding:14px 18px;border-radius:12px;'
      + 'box-shadow:0 10px 25px rgba(0,0,0,.18);max-width:340px;font:14px/1.4 system-ui,sans-serif;';
    var name = (entry && entry.name) ? entry.name : 'walkthrough';
    var msg = (entry && entry.starthint)
      || ('Tour ready: <strong>' + name + '</strong>.<br>Pick an item from this list to open it — the tour will start automatically.');
    hint.innerHTML = '<div style="display:flex;gap:8px;align-items:flex-start">'
      + '<span style="font-size:18px">▶</span>'
      + '<div style="flex:1">' + msg + '</div>'
      + '<button type="button" aria-label="Cancel tour" '
      + 'style="background:transparent;border:0;color:#fff;cursor:pointer;font-size:18px;line-height:1;padding:0 0 0 6px">×</button>'
      + '</div>';
    hint.querySelector('button').addEventListener('click', function () {
      try { sessionStorage.removeItem('gw_pending_tour'); } catch (e) {}
      hint.remove();
    });
    document.body.appendChild(hint);
  }
  if (document.readyState === 'complete') {
    tryAutostart();
  } else {
    window.addEventListener('load', tryAutostart);
  }
})();
