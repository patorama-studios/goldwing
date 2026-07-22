/* Goldwing event lightbox — shared by the public ride calendar and the members
   dashboard. Loads calendar/event_view.php?embed=1 into a centered modal instead
   of navigating to a separate page. Expose window.GoldwingEventModal.open(url). */
(function () {
  if (window.GoldwingEventModal) { return; }

  var overlay, dialog, content, bound = false;

  function injectStyles() {
    if (document.getElementById('gw-modal-styles')) { return; }
    var css = ''
      + '.gw-modal-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;'
      + 'padding:20px;background:rgba(17,24,39,.55);opacity:0;transition:opacity .18s ease;overflow-y:auto}'
      + '.gw-modal-overlay.is-open{opacity:1}'
      + '.gw-modal-dialog{position:relative;width:100%;max-width:560px;max-height:92vh;overflow-y:auto;'
      + 'background:#fff;border-radius:20px;box-shadow:0 30px 70px rgba(0,0,0,.35);transform:translateY(12px) scale(.98);'
      + 'transition:transform .18s ease;-webkit-overflow-scrolling:touch}'
      + '.gw-modal-overlay.is-open .gw-modal-dialog{transform:none}'
      + '.gw-modal-close{position:absolute;top:14px;right:14px;z-index:5;width:34px;height:34px;border-radius:50%;'
      + 'border:none;background:rgba(17,24,39,.55);color:#fff;font-size:20px;line-height:1;cursor:pointer;'
      + 'display:flex;align-items:center;justify-content:center}'
      + '.gw-modal-close:hover{background:rgba(17,24,39,.8)}'
      + '.gw-modal-loading{padding:48px 24px;text-align:center;color:#6b7280;font-family:"Manrope","Inter",sans-serif}';
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
    var closeBtn = document.createElement('button');
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

  function open(url) {
    var embedUrl = withEmbed(url);
    if (!embedUrl) { return; }
    build();
    content.innerHTML = '<div class="gw-modal-loading">Loading event&hellip;</div>';
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
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
