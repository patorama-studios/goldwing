/*
 * Password strength visual indicator.
 *
 * Auto-attaches to any <input type="password" data-password-strength>.
 * Renders a live checklist (12+ chars, upper, lower, number, special),
 * a length progress bar, a Generate strong password button, and a
 * Show/Hide toggle. If the input has data-pw-confirm="<other-name>",
 * a match indicator is added and Generate fills both fields.
 *
 * Pure vanilla JS + scoped inline styles — works on member pages
 * (custom CSS) and admin pages (Tailwind) without conflicts.
 */
(function () {
  if (window.__pwStrengthLoaded) return;
  window.__pwStrengthLoaded = true;

  var MIN_LEN = 12;
  var REQS = [
    { id: 'length',  label: 'At least 12 characters',                test: function (v) { return v.length >= MIN_LEN; } },
    { id: 'upper',   label: 'An uppercase letter (A–Z)',        test: function (v) { return /[A-Z]/.test(v); } },
    { id: 'lower',   label: 'A lowercase letter (a–z)',         test: function (v) { return /[a-z]/.test(v); } },
    { id: 'number',  label: 'A number (0–9)',                   test: function (v) { return /[0-9]/.test(v); } },
    { id: 'special', label: 'A special character (! @ # $ % etc.)',  test: function (v) { return /[^A-Za-z0-9]/.test(v); } }
  ];

  var STYLES = [
    '.pw-strength{margin-top:8px;font-family:inherit;font-size:13px;line-height:1.4;color:#374151;}',
    '.pw-strength *{box-sizing:border-box;}',
    '.pw-strength__bar{height:6px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin:6px 0 10px;}',
    '.pw-strength__bar-fill{height:100%;width:0%;background:#ef4444;border-radius:999px;transition:width .2s ease,background-color .2s ease;}',
    '.pw-strength__bar-fill[data-level="medium"]{background:#f59e0b;}',
    '.pw-strength__bar-fill[data-level="strong"]{background:#16a34a;}',
    '.pw-strength__list{list-style:none;padding:0;margin:0 0 8px;display:grid;gap:4px;}',
    '.pw-strength__item{display:flex;align-items:center;gap:8px;color:#6b7280;transition:color .15s;}',
    '.pw-strength__item[data-ok="true"]{color:#16a34a;}',
    '.pw-strength__icon{width:16px;height:16px;border-radius:50%;background:#e5e7eb;color:#9ca3af;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;transition:background .15s,color .15s;line-height:1;}',
    '.pw-strength__item[data-ok="true"] .pw-strength__icon{background:#16a34a;color:#fff;}',
    '.pw-strength__actions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;}',
    '.pw-strength__btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:13px;font-weight:500;border-radius:6px;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer;font-family:inherit;line-height:1.2;transition:background .15s,border-color .15s,color .15s;}',
    '.pw-strength__btn:hover{background:#f9fafb;border-color:#9ca3af;}',
    '.pw-strength__btn:focus{outline:2px solid #2563eb;outline-offset:1px;}',
    '.pw-strength__btn--primary{background:#f3f4f6;border-color:#9ca3af;}',
    '.pw-strength__match{font-size:13px;margin-top:6px;min-height:1.2em;}',
    '.pw-strength__match[data-match="true"]{color:#16a34a;}',
    '.pw-strength__match[data-match="false"]{color:#dc2626;}',
    '.pw-strength__summary{font-size:12px;color:#6b7280;margin-bottom:4px;}',
    '.pw-strength__summary[data-level="strong"]{color:#16a34a;}',
    '.pw-strength__summary[data-level="medium"]{color:#b45309;}'
  ].join('');

  function ensureStyles() {
    if (document.getElementById('pw-strength-styles')) return;
    var style = document.createElement('style');
    style.id = 'pw-strength-styles';
    style.textContent = STYLES;
    document.head.appendChild(style);
  }

  function randInt(max) {
    if (window.crypto && window.crypto.getRandomValues) {
      var arr = new Uint32Array(1);
      window.crypto.getRandomValues(arr);
      return arr[0] % max;
    }
    return Math.floor(Math.random() * max);
  }

  function pick(str) {
    return str.charAt(randInt(str.length));
  }

  function generateStrongPassword(length) {
    var len = Math.max(MIN_LEN, length || 16);
    // Omit visually ambiguous chars (0, O, 1, l, I) for usability.
    var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    var lower = 'abcdefghijkmnpqrstuvwxyz';
    var nums  = '23456789';
    var spec  = '!@#$%^&*-_=+?';
    var all   = upper + lower + nums + spec;
    var pwd = [pick(upper), pick(lower), pick(nums), pick(spec)];
    while (pwd.length < len) pwd.push(pick(all));
    for (var i = pwd.length - 1; i > 0; i--) {
      var j = randInt(i + 1);
      var tmp = pwd[i]; pwd[i] = pwd[j]; pwd[j] = tmp;
    }
    return pwd.join('');
  }

  function fireInput(el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function attach(input) {
    if (!input || input.dataset.pwStrengthInit === '1') return;
    input.dataset.pwStrengthInit = '1';
    ensureStyles();

    var wrapper = document.createElement('div');
    wrapper.className = 'pw-strength';

    var summary = document.createElement('div');
    summary.className = 'pw-strength__summary';
    summary.textContent = 'Password strength';
    wrapper.appendChild(summary);

    var bar = document.createElement('div');
    bar.className = 'pw-strength__bar';
    var barFill = document.createElement('div');
    barFill.className = 'pw-strength__bar-fill';
    barFill.setAttribute('role', 'progressbar');
    barFill.setAttribute('aria-valuemin', '0');
    barFill.setAttribute('aria-valuemax', '100');
    barFill.setAttribute('aria-valuenow', '0');
    bar.appendChild(barFill);
    wrapper.appendChild(bar);

    var list = document.createElement('ul');
    list.className = 'pw-strength__list';
    list.setAttribute('aria-live', 'polite');
    var items = {};
    REQS.forEach(function (r) {
      var li = document.createElement('li');
      li.className = 'pw-strength__item';
      li.dataset.ok = 'false';
      var icon = document.createElement('span');
      icon.className = 'pw-strength__icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = '✓';
      var label = document.createElement('span');
      label.textContent = r.label;
      li.appendChild(icon);
      li.appendChild(label);
      list.appendChild(li);
      items[r.id] = li;
    });
    wrapper.appendChild(list);

    // Resolve a linked confirm field by data-pw-confirm="name".
    var confirmInput = null;
    var confirmName = input.getAttribute('data-pw-confirm');
    if (confirmName) {
      var scope = input.form || document;
      confirmInput = scope.querySelector('[name="' + confirmName + '"]');
    }

    var matchEl = document.createElement('div');
    matchEl.className = 'pw-strength__match';
    matchEl.setAttribute('aria-live', 'polite');
    if (confirmInput) wrapper.appendChild(matchEl);

    var actions = document.createElement('div');
    actions.className = 'pw-strength__actions';

    var genBtn = document.createElement('button');
    genBtn.type = 'button';
    genBtn.className = 'pw-strength__btn pw-strength__btn--primary';
    genBtn.innerHTML = '<span aria-hidden="true">⚡</span><span>Generate strong password</span>';
    actions.appendChild(genBtn);

    var showBtn = document.createElement('button');
    showBtn.type = 'button';
    showBtn.className = 'pw-strength__btn';
    showBtn.textContent = 'Show';
    actions.appendChild(showBtn);

    wrapper.appendChild(actions);
    input.insertAdjacentElement('afterend', wrapper);

    function evaluate() {
      var val = input.value || '';
      var passed = 0;
      REQS.forEach(function (r) {
        var ok = r.test(val);
        items[r.id].dataset.ok = ok ? 'true' : 'false';
        if (ok) passed++;
      });
      var pct = val.length === 0 ? 0 : Math.min(100, Math.round((val.length / MIN_LEN) * 100));
      barFill.style.width = pct + '%';
      barFill.setAttribute('aria-valuenow', String(pct));
      var level = 'weak';
      if (passed === REQS.length) level = 'strong';
      else if (passed >= 3) level = 'medium';
      barFill.dataset.level = level;
      summary.dataset.level = level;
      if (val.length === 0) {
        summary.textContent = 'Password strength';
      } else if (level === 'strong') {
        summary.textContent = 'Strong password — all requirements met';
      } else if (level === 'medium') {
        summary.textContent = 'Getting there — ' + (REQS.length - passed) + ' more to go';
      } else {
        summary.textContent = 'Weak — ' + (REQS.length - passed) + ' requirement' + (REQS.length - passed === 1 ? '' : 's') + ' left';
      }
      updateMatch();
    }

    function updateMatch() {
      if (!confirmInput) return;
      var cv = confirmInput.value || '';
      if (cv.length === 0) {
        matchEl.removeAttribute('data-match');
        matchEl.textContent = '';
        return;
      }
      if (cv === input.value) {
        matchEl.dataset.match = 'true';
        matchEl.textContent = '✓ Passwords match';
      } else {
        matchEl.dataset.match = 'false';
        matchEl.textContent = '✗ Passwords do not match';
      }
    }

    input.addEventListener('input', evaluate);
    input.addEventListener('change', evaluate);
    if (confirmInput) {
      confirmInput.addEventListener('input', updateMatch);
      confirmInput.addEventListener('change', updateMatch);
    }

    genBtn.addEventListener('click', function () {
      var pwd = generateStrongPassword(16);
      input.value = pwd;
      input.type = 'text';
      showBtn.textContent = 'Hide';
      if (confirmInput) {
        confirmInput.value = pwd;
        confirmInput.type = 'text';
        fireInput(confirmInput);
      }
      fireInput(input);
      input.focus();
      input.select();
    });

    showBtn.addEventListener('click', function () {
      var hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      if (confirmInput) confirmInput.type = hidden ? 'text' : 'password';
      showBtn.textContent = hidden ? 'Hide' : 'Show';
    });

    evaluate();
  }

  function init(root) {
    var scope = root || document;
    scope.querySelectorAll('input[data-password-strength]').forEach(attach);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); });
  } else {
    init();
  }

  // Observe DOM for inputs added later (e.g. dynamic forms).
  if (window.MutationObserver) {
    var mo = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes && m.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('input[data-password-strength]')) attach(n);
          else if (n.querySelectorAll) n.querySelectorAll('input[data-password-strength]').forEach(attach);
        });
      });
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }

  window.PasswordStrength = {
    attach: attach,
    generate: generateStrongPassword,
    MIN_LEN: MIN_LEN
  };
})();
