/* Goldwing event lightbox — shared by the public ride calendar, the members
   dashboard, and the calendar iframe. Loads calendar/event_view.php?embed=1
   into a full-screen overlay instead of navigating to a separate page.

   When opened from inside the calendar iframe, it hands off to the SAME script
   running on the parent page so the overlay covers the whole window (nav and
   all) rather than being trapped inside the iframe's box. For that hand-off to
   work the parent page must also load this script. Exposes
   window.GoldwingEventModal.open(url). */
(function () {
  if (window.GoldwingEventModal) { return; }

  var overlay, dialog, content, closeBtn;

  function injectStyles() {
    if (document.getElementById('gw-modal-styles')) { return; }
    var css = ''
      + '.gw-modal-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;'
      + 'padding:24px;background:rgba(17,24,39,.62);opacity:0;transition:opacity .18s ease;overflow-y:auto;-webkit-overflow-scrolling:touch}'
      // The bug fix: a bare [hidden] loses to the .gw-modal-overlay class rule
      // above (both single-specificity, author wins over the UA sheet), so the
      // closed overlay stayed display:flex and kept catching clicks. This wins.
      + '.gw-modal-overlay[hidden]{display:none}'
      + '.gw-modal-overlay.is-open{opacity:1}'
      + '.gw-modal-dialog{position:relative;width:100%;max-width:660px;max-height:94vh;overflow-y:auto;'
      + 'background:#fff;border-radius:22px;box-shadow:0 40px 90px rgba(0,0,0,.45);transform:translateY(14px) scale(.98);'
      + 'transition:transform .18s ease;-webkit-overflow-scrolling:touch}'
      + '.gw-modal-overlay.is-open .gw-modal-dialog{transform:none}'
      + '.gw-modal-close{position:absolute;top:16px;right:16px;z-index:5;width:38px;height:38px;border-radius:50%;'
      + 'border:none;background:rgba(17,24,39,.55);color:#fff;font-size:22px;line-height:1;cursor:pointer;'
      + 'display:flex;align-items:center;justify-content:center;transition:background .15s ease}'
      + '.gw-modal-close:hover{background:rgba(17,24,39,.82)}'
      + '.gw-modal-loading{padding:56px 24px;text-align:center;color:#6b7280;font-family:"Manrope","Inter",sans-serif}'
      // On phones the sheet fills the whole screen so it reads as its own page.
      + '@media (max-width:600px){.gw-modal-overlay{padding:0}'
      + '.gw-modal-dialog{max-width:none;height:100%;max-height:100%;border-radius:0}}';
    var style = document.createElement('style');
    style.id = 'gw-modal-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function build() {
    if (overlay) { return; }
    injectStyles();
    overlay = document.createElement('div');
    overlay.className = 'gw-modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.hidden = true;
    dialog = document.createElement('div');
    dialog.className = 'gw-modal-dialog';
    closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'gw-modal-close';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.innerHTML = '&times;';
    closeBtn.addEventListener('click', close);
    content = document.createElement('div');
    content.className = 'gw-modal-content';
    dialog.appendChild(closeBtn);
    dialog.appendChild(content);
    overlay.appendChild(dialog);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    document.body.appendChild(overlay);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay && !overlay.hidden) { close(); }
    });
  }

  function withEmbed(url) {
    if (!url) { return ''; }
    if (url.indexOf('embed=1') !== -1) { return url; }
    return url + (url.indexOf('?') === -1 ? '?embed=1' : '&embed=1');
  }

  function bindContent() {
    content.querySelectorAll('[data-calendar-back]').forEach(function (link) {
      link.addEventListener('click', function (e) { e.preventDefault(); close(); });
    });
    content.querySelectorAll('form[data-calendar-embed-form="1"]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var action = withEmbed(form.getAttribute('action') || '');
        if (!action) { return; }
        fetch(action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
          .then(function (r) { return r.text(); })
          .then(function (html) { content.innerHTML = html; bindContent(); })
          .catch(function () {
            content.innerHTML = '<div class="gw-modal-loading">Unable to update this event right now.</div>';
          });
      });
    });
  }

  // If we're inside a same-origin iframe whose parent runs this same script,
  // let the parent own the overlay so it covers the entire page. Returns true
  // when the hand-off succeeded (caller should stop). Cross-origin access throws
  // and is swallowed, so we simply fall back to rendering locally.
  function delegateToParent(url) {
    try {
      if (window.self === window.top) { return false; }
      var parentModal = window.parent && window.parent.GoldwingEventModal;
      if (!parentModal || parentModal === window.GoldwingEventModal) { return false; }
      parentModal.open(new URL(url, window.location.href).href);
      return true;
    } catch (e) {
      return false;
    }
  }

  function open(url) {
    var embedUrl = withEmbed(url);
    if (!embedUrl) { return; }
    if (delegateToParent(url)) { return; }
    build();
    content.innerHTML = '<div class="gw-modal-loading">Loading event&hellip;</div>';
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    // Move focus into the overlay so keyboard events (Escape) land on THIS
    // document — essential when we've delegated from the iframe to the parent,
    // where focus would otherwise stay trapped in the iframe.
    if (closeBtn) { try { closeBtn.focus(); } catch (e) { /* ignore */ } }
    requestAnimationFrame(function () { overlay.classList.add('is-open'); });
    fetch(embedUrl, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('failed'); } return r.text(); })
      .then(function (html) { content.innerHTML = html; bindContent(); })
      .catch(function () {
        content.innerHTML = '<div class="gw-modal-loading">Unable to load this event. '
          + '<a href="' + url + '">Open it on its own page</a>.</div>';
      });
  }

  function close() {
    if (!overlay) { return; }
    overlay.classList.remove('is-open');
    document.body.style.overflow = '';
    window.setTimeout(function () { if (overlay) { overlay.hidden = true; content.innerHTML = ''; } }, 180);
  }

  window.GoldwingEventModal = { open: open, close: close };
})();
