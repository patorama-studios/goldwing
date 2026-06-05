/* Floating "?" Help button.
 *
 * Reads window.GoldwingTours.manifest + completions, filters by the current
 * URL's page_match substring, and shows a panel with tours relevant to the
 * current screen plus an "All guides" link.
 *
 * Expects window.GoldwingHelpConfig (set inline by PHP) with:
 *   { allGuidesUrl, supportEmail, currentUrl }
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GoldwingTours || !window.GoldwingTours.manifest) return;
    var cfg = window.GoldwingHelpConfig || {};
    var manifest = window.GoldwingTours.manifest.tours || {};
    var completions = window.GoldwingTours.completions || {};
    var currentUrl = cfg.currentUrl || (location.pathname + location.search);

    // Pick tours whose page_match substring appears in the current URL.
    var pageTours = [];
    Object.keys(manifest).forEach(function (slug) {
      var t = manifest[slug];
      if (!t.page_match) return;
      if (currentUrl.indexOf(t.page_match) !== -1) {
        pageTours.push(t);
      }
    });

    var btn = document.createElement('button');
    btn.id = 'goldwing-help-button';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Open help menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = '?';
    document.body.appendChild(btn);

    var panel = document.createElement('div');
    panel.id = 'goldwing-help-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Help menu');
    document.body.appendChild(panel);

    function renderPanel() {
      var html = '<h3>Need a hand?</h3>';
      if (pageTours.length) {
        html += '<div class="gw-help-section-label">Walk me through</div><ul>';
        pageTours.forEach(function (t) {
          var done = !!completions[t.slug];
          html += '<li><button type="button" data-tour-slug="' + encodeAttr(t.slug) + '">' +
                    '<span>' + escapeHtml(t.name) + '</span>' +
                    '<span class="gw-help-tour-status" data-state="' + (done ? 'done' : 'todo') + '">' +
                      (done ? 'Done' : 'Not yet') +
                    '</span>' +
                  '</button></li>';
        });
        html += '</ul>';
      } else {
        html += '<div class="gw-help-empty">No walkthrough for this page yet — try the full guide below.</div>';
      }
      html += '<div class="gw-help-section-label">More help</div><ul>';
      if (cfg.allGuidesUrl) {
        html += '<li><a href="' + encodeAttr(cfg.allGuidesUrl) + '">All guides &amp; how-tos</a></li>';
      }
      if (cfg.supportEmail) {
        html += '<li><a href="mailto:' + encodeAttr(cfg.supportEmail) + '?subject=Help with the AGA website">Email someone for help</a></li>';
      }
      html += '</ul>';
      panel.innerHTML = html;

      panel.querySelectorAll('button[data-tour-slug]').forEach(function (b) {
        b.addEventListener('click', function () {
          var slug = b.getAttribute('data-tour-slug');
          var tour = manifest[slug];
          closePanel();
          if (!tour) return;

          // Are we already on the tour's target page?
          var pageMatch = tour.page_match || '';
          var onTargetPage = pageMatch === '' || currentUrl.indexOf(pageMatch) !== -1;
          if (!onTargetPage && tour.page_url) {
            var sep = tour.page_url.indexOf('?') !== -1 ? '&' : '?';
            window.location.href = tour.page_url + sep + 'gw_tour=' + encodeURIComponent(slug);
            return;
          }
          window.GoldwingTours.run(slug, {
            onComplete: function () {
              completions[slug] = true;
              renderPanel();
            },
          });
        });
      });
    }

    function openPanel() {
      panel.setAttribute('data-open', 'true');
      btn.setAttribute('aria-expanded', 'true');
    }
    function closePanel() {
      panel.removeAttribute('data-open');
      btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function () {
      if (panel.getAttribute('data-open') === 'true') closePanel();
      else openPanel();
    });
    document.addEventListener('click', function (e) {
      if (panel.getAttribute('data-open') !== 'true') return;
      if (panel.contains(e.target) || btn.contains(e.target)) return;
      closePanel();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closePanel();
    });

    renderPanel();

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
      });
    }
    function encodeAttr(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
      });
    }
  });
})();
