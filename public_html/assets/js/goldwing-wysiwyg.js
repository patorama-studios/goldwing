/**
 * goldwing-wysiwyg.js
 * Auto-mounts Quill 2.x onto any <textarea data-wysiwyg> in the page.
 * Adds an emoji-picker button so admins can drop emojis without hunting the OS keyboard.
 *
 * Usage in PHP templates:
 *   <textarea name="content" data-wysiwyg ...><?= e($html) ?></textarea>
 *
 * The textarea is hidden, a Quill editor is mounted next to it, and the textarea
 * value is kept in sync as HTML — so existing form-submit handlers keep working.
 */
(function () {
  'use strict';

  const TOOLBAR_CONFIG = [
    [{ header: [false, 1, 2, 3] }],
    [{ font: [] }, { size: ['small', false, 'large', 'huge'] }],
    ['bold', 'italic', 'underline', 'strike'],
    [{ color: [] }, { background: [] }],
    [{ list: 'ordered' }, { list: 'bullet' }],
    [{ align: [] }],
    ['link', 'blockquote', 'clean'],
    ['emoji'],
  ];

  function waitForQuill(cb) {
    if (window.Quill) { cb(); return; }
    const tick = setInterval(() => {
      if (window.Quill) { clearInterval(tick); cb(); }
    }, 50);
    setTimeout(() => clearInterval(tick), 10000);
  }

  function buildEmojiPicker(quill, button) {
    const popover = document.createElement('div');
    popover.className = 'gw-emoji-popover';
    popover.hidden = true;
    const picker = document.createElement('emoji-picker');
    picker.classList.add('light');
    popover.appendChild(picker);
    document.body.appendChild(popover);

    picker.addEventListener('emoji-click', (event) => {
      const unicode = event?.detail?.unicode;
      if (!unicode) return;
      const range = quill.getSelection(true);
      quill.insertText(range.index, unicode, 'user');
      quill.setSelection(range.index + unicode.length, 0, 'user');
      popover.hidden = true;
    });

    const closeOnOutside = (event) => {
      if (popover.hidden) return;
      if (popover.contains(event.target) || button.contains(event.target)) return;
      popover.hidden = true;
    };
    document.addEventListener('mousedown', closeOnOutside);

    button.addEventListener('click', () => {
      if (!popover.hidden) { popover.hidden = true; return; }
      const rect = button.getBoundingClientRect();
      popover.style.top = (window.scrollY + rect.bottom + 6) + 'px';
      popover.style.left = (window.scrollX + rect.left) + 'px';
      popover.hidden = false;
    });
  }

  function mount(textarea) {
    if (textarea.dataset.wysiwygReady === '1') return;
    textarea.dataset.wysiwygReady = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'gw-wysiwyg';
    const editor = document.createElement('div');
    wrapper.appendChild(editor);

    textarea.style.display = 'none';
    textarea.parentNode.insertBefore(wrapper, textarea);

    const placeholder = textarea.getAttribute('placeholder') || '';

    const quill = new window.Quill(editor, {
      theme: 'snow',
      placeholder,
      modules: { toolbar: TOOLBAR_CONFIG },
    });

    // Seed from the textarea's existing HTML/text value.
    if (textarea.value && textarea.value.trim() !== '') {
      // Quill's clipboard module converts the HTML safely into its delta model.
      const delta = quill.clipboard.convert({ html: textarea.value });
      quill.setContents(delta, 'silent');
    }

    // Customise the emoji button label (Quill renders an empty <button class="ql-emoji">).
    const toolbar = quill.getModule('toolbar');
    const emojiBtn = toolbar.container.querySelector('button.ql-emoji');
    if (emojiBtn) {
      emojiBtn.classList.add('gw-emoji-btn');
      emojiBtn.innerHTML = '<span aria-hidden="true">\u{1F600}</span>';
      emojiBtn.setAttribute('aria-label', 'Insert emoji');
      buildEmojiPicker(quill, emojiBtn);
    }

    const sync = () => {
      // Empty Quill renders as "<p><br></p>" — treat that as empty string so
      // server-side "required" checks still work.
      const html = quill.getText().trim() === '' ? '' : quill.root.innerHTML;
      textarea.value = html;
    };
    quill.on('text-change', sync);

    // Belt-and-braces: sync once more right before the form submits.
    const form = textarea.closest('form');
    if (form) form.addEventListener('submit', sync, { capture: true });
  }

  function mountAll(root) {
    const scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('textarea[data-wysiwyg]').forEach(mount);
  }

  function init() {
    waitForQuill(() => {
      mountAll(document);
      // Mount editors that get added later (e.g. inside dialogs already in the DOM
      // but with content loaded dynamically).
      const observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
          m.addedNodes.forEach((node) => {
            if (node.nodeType !== 1) return;
            if (node.matches && node.matches('textarea[data-wysiwyg]')) mount(node);
            mountAll(node);
          });
        }
      });
      observer.observe(document.body, { childList: true, subtree: true });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
