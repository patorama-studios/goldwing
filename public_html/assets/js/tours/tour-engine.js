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

  /** Resolve a tour either from in-memory registration or the manifest's steps_file. */
  function ensureLoaded(slug, cb) {
    if (registered[slug]) {
      cb(null, registered[slug]);
      return;
    }
    var entry = GT.manifest.tours && GT.manifest.tours[slug];
    if (!entry || !entry.steps_file) {
      cb(new Error('Tour not found: ' + slug));
      return;
    }
    var script = document.createElement('script');
    script.src = entry.steps_file;
    script.async = true;
    script.onload = function () {
      if (registered[slug]) cb(null, registered[slug]);
      else cb(new Error('Tour script loaded but did not register: ' + slug));
    };
    script.onerror = function () { cb(new Error('Could not load ' + entry.steps_file)); };
    document.head.appendChild(script);
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
    ensureLoaded(slug, function (err, tour) {
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
    ensureLoaded(slug, function (err, tour) {
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
})();
