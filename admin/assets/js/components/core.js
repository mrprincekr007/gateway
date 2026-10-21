/* =========================================================
   FatherGateway — Admin Core Runtime (core.js)
   Shared scripts for the Admin Panel: toasts, clipboard
   copying, animated counters, scroll reveals, modals, tabs,
   theme switcher and the shared init bootstrap.
   ========================================================= */

(function () {
  'use strict';

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Escape closes overlays ---------- */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (typeof window.closeSidebar === 'function') { window.closeSidebar(); }
      document.querySelectorAll('.admin-modal-overlay.show').forEach(function (m) {
        m.classList.remove('show');
      });
      document.body.style.overflow = '';
      var ud = document.getElementById('topbarUserDropdown');
      if (ud) { ud.classList.remove('is-open'); }
      var ub = document.getElementById('topbarUserBtn');
      if (ub) { ub.setAttribute('aria-expanded', 'false'); }
    }
  });

  /* ---------- Toast Notification (Bottom Center) ---------- */
  window.toast = function (message, type) {
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    var icon = type === 'error' ? 'danger' : (type === 'info' ? 'info' : 'success');
    var icons = {
      success: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      danger: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14a2 2 0 0 0 1.73 3h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
      info: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    t.setAttribute('role', 'status');
    t.innerHTML = '<span class="toast-icon">' + (icons[icon] || icons.info) + '</span><span class="toast-text"></span>';
    var textEl = t.querySelector('.toast-text');
    if (textEl) { textEl.textContent = message == null ? '' : String(message); }

    var container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-atomic', 'false');
      document.body.appendChild(container);
    }
    container.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () {
      t.classList.remove('show');
      setTimeout(function () { t.remove(); }, 300);
    }, 3200);
  };

  /* ---------- Universal Copy to Clipboard (Preserves All Newlines/Enters) ---------- */
  function normalizeNewlines(str) {
    if (!str) return '';
    if (str.indexOf('\\n') !== -1 && str.indexOf('\n') === -1) {
      str = str.replace(/\\r\\n/g, '\n').replace(/\\n/g, '\n').replace(/\\t/g, '\t');
    }
    return str.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\n/g, '\r\n');
  }

  window.copyToClipboard = function (text, cb) {
    if (typeof text !== 'string') text = String(text || '');
    text = normalizeNewlines(text);
    if (!text) return;

    function onDone() {
      if (typeof window.toast === 'function') {
        window.toast('Copied to clipboard', 'success');
      }
      if (cb) cb();
    }

    if (navigator.clipboard && window.isSecureContext && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(onDone).catch(function () {
        fallbackCopy(text, onDone);
      });
    } else {
      fallbackCopy(text, onDone);
    }
  };

  function fallbackCopy(text, cb) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }
    document.body.removeChild(ta);
    if (ok) {
      if (cb) cb();
    } else if (typeof window.toast === 'function') {
      window.toast('Could not copy', 'error');
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy], [data-copy-target]');
    if (!btn) return;

    var text = '';
    var targetSelector = btn.getAttribute('data-copy-target');
    if (targetSelector) {
      var targetEl = document.querySelector(targetSelector);
      if (targetEl) text = targetEl.innerText || targetEl.textContent || '';
    }

    if (!text) {
      var container = btn.closest('.code-box, .terminal-box, pre');
      if (container) {
        var pre = container.tagName === 'PRE' ? container : container.querySelector('pre');
        if (pre) text = pre.innerText || pre.textContent || '';
      }
    }

    if (!text) {
      text = btn.getAttribute('data-copy') || '';
    }

    if (text) {
      window.copyToClipboard(text);
    }
  });

  /* ---------- Animated Counters ---------- */
  function animateValue(el) {
    var target = parseFloat(el.getAttribute('data-count'));
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
    var prefix = el.getAttribute('data-prefix') || '';
    var suffix = el.getAttribute('data-suffix') || '';
    if (reduced) {
      el.textContent = prefix + target.toLocaleString('en-IN', {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      }) + suffix;
      return;
    }
    var dur = 1000;
    var start = null;
    function frame(ts) {
      if (!start) { start = ts; }
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      var val = target * eased;
      el.textContent = prefix + val.toLocaleString('en-IN', {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      }) + suffix;
      if (p < 1) { requestAnimationFrame(frame); }
    }
    requestAnimationFrame(frame);
  }

  var countersIO = null;
  function initCounters() {
    if (countersIO) { countersIO.disconnect(); countersIO = null; }
    if (!('IntersectionObserver' in window)) {
      document.querySelectorAll('[data-count]').forEach(animateValue);
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          animateValue(entry.target);
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.05 });
    countersIO = io;
    document.querySelectorAll('[data-count]').forEach(function (el) { io.observe(el); });
  }

  /* ---------- Scroll Reveal ---------- */
  var revealIO = null;
  function initReveal() {
    if (revealIO) { revealIO.disconnect(); revealIO = null; }
    var items = document.querySelectorAll('[data-reveal], [data-stagger]');
    if (reduced || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-visible'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.05, rootMargin: '0px 0px 40px 0px' });
    revealIO = io;
    items.forEach(function (el) {
      io.observe(el);
      if ('stagger' in el.dataset) {
        Array.prototype.forEach.call(el.children, function (child, i) {
          child.style.transitionDelay = (i * 50) + 'ms';
        });
      }
    });
  }

  /* ---------- Admin Modal Controller (with focus management) ---------- */
  var lastFocusedEl = null;

  function focusables(container) {
    return Array.prototype.slice.call(container.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (el) { return el.offsetParent !== null; });
  }

  function onModalKeydown(e) {
    if (e.key !== 'Tab') { return; }
    var modal = document.querySelector('.admin-modal-overlay.show');
    if (!modal) { return; }
    var items = focusables(modal);
    if (!items.length) { return; }
    var first = items[0];
    var last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  window.openAdminModal = function (modalId) {
    var m = document.getElementById(modalId);
    if (!m) { return; }
    if (!m.getAttribute('role')) { m.setAttribute('role', 'dialog'); }
    m.setAttribute('aria-modal', 'true');
    lastFocusedEl = document.activeElement;
    m.classList.add('show');
    document.body.style.overflow = 'hidden';
    var items = focusables(m);
    if (items.length) {
      setTimeout(function () { items[0].focus(); }, 30);
    }
    document.addEventListener('keydown', onModalKeydown);
  };

  function dismissModal(m) {
    if (!m) { return; }
    m.classList.remove('show');
    if (!document.querySelector('.admin-modal-overlay.show')) {
      document.body.style.overflow = '';
    }
    document.removeEventListener('keydown', onModalKeydown);
    if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
      try { lastFocusedEl.focus(); } catch (err) {}
    }
    lastFocusedEl = null;
  }

  window.closeAdminModal = function (modalId) {
    dismissModal(document.getElementById(modalId));
  };

  document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('admin-modal-overlay')) {
      dismissModal(e.target);
    }
  });

  /* ---------- Tab Controller ---------- */
  function initTabs() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.admin-tab-btn');
      if (!btn) return;
      var targetTab = btn.getAttribute('data-tab');
      if (!targetTab) return;

      var nav = btn.closest('.admin-tab-nav');
      if (nav) {
        nav.querySelectorAll('.admin-tab-btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
      }

      var container = btn.closest('.card') || document;
      container.querySelectorAll('.admin-tab-pane').forEach(function (p) {
        p.classList.remove('active');
      });
      var pane = container.querySelector('#' + targetTab);
      if (pane) {
        pane.classList.add('active');
      }
    });
  }

  /* ---------- Theme Switcher (Night / Light Mode) ---------- */
  function initThemeToggle() {
    var toggleBtn = document.getElementById('themeToggleBtn');

    function applyTheme(theme) {
      if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        if (document.body) document.body.classList.add('dark-mode');
        document.documentElement.setAttribute('data-theme', 'dark');
        if (toggleBtn) {
          toggleBtn.setAttribute('title', 'Switch to Light Mode');
          toggleBtn.setAttribute('aria-label', 'Switch to Light Mode');
        }
      } else {
        document.documentElement.classList.remove('dark-mode');
        if (document.body) document.body.classList.remove('dark-mode');
        document.documentElement.setAttribute('data-theme', 'light');
        if (toggleBtn) {
          toggleBtn.setAttribute('title', 'Switch to Night Mode');
          toggleBtn.setAttribute('aria-label', 'Switch to Night Mode');
        }
      }
    }

    var savedTheme = 'light';
    try {
      savedTheme = localStorage.getItem('admin_theme') || 'light';
    } catch (e) { }
    applyTheme(savedTheme);

    if (toggleBtn) {
      toggleBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var currentIsDark = document.documentElement.classList.contains('dark-mode');
        var newTheme = currentIsDark ? 'light' : 'dark';
        applyTheme(newTheme);
        try {
          localStorage.setItem('admin_theme', newTheme);
        } catch (e) { }
        if (typeof window.toast === 'function') {
          window.toast(newTheme === 'dark' ? 'Night Mode Activated' : 'Light Mode Activated', 'info');
        }
      });
    }
  }


  /* =========================================================
     UNIVERSAL AUTO-FIT TEXT ENGINE (Global UI Protection)
     Automatically shrinks text size when content is too long
     so it fits perfectly inside its container without breaking.
     ========================================================= */
  function autoFitTextElements() {
    var selectors = [
      '[data-auto-fit]',
      '.auto-fit-text',
      '.text-autofit',
      '.sdk-pkg-code',
      '.api-status-name',
      '.api-status-desc',
      '.t-stat-label',
      '.t-stat-value',
      '.kpi-number-display',
      '.api-bottom-title',
      '.white-card-title',
      '.panel-head-title',
      '.traffic-card-title',
      '.meta-cell-value',
      '.meta-cell-label'
    ];

    var els = document.querySelectorAll(selectors.join(','));
    els.forEach(function (el) {
      if (!el || el.offsetParent === null) return;

      if (!el.dataset.origFontSize) {
        var comp = window.getComputedStyle(el);
        var fs = parseFloat(comp.fontSize);
        if (!fs || isNaN(fs)) return;
        el.dataset.origFontSize = fs;
      }

      var origSize = parseFloat(el.dataset.origFontSize);
      var minSize = parseFloat(el.dataset.minFontSize) || (
        origSize >= 24 ? 15 :
          origSize >= 18 ? 12 :
            origSize >= 14 ? 10 :
              origSize >= 11 ? 8.5 : 7.5
      );

      el.style.fontSize = origSize + 'px';

      var clientW = el.clientWidth;
      var scrollW = el.scrollWidth;

      if (clientW > 0 && scrollW > clientW + 1) {
        var ratio = clientW / scrollW;
        var scaled = origSize * ratio * 0.95;
        var finalSize = Math.max(minSize, Math.floor(scaled * 10) / 10);
        el.style.fontSize = finalSize + 'px';
      } else {
        el.style.fontSize = '';
      }
    });
  }

  window.autoFitText = autoFitTextElements;

  window.addEventListener('resize', function () {
    clearTimeout(window.__autoFitTimer);
    window.__autoFitTimer = setTimeout(autoFitTextElements, 80);
  });

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(autoFitTextElements);
  }

  /* ---------- Liquid Buttons: pointer-follow sheen (clean, no glowing circular burst) ---------- */
  function initButtonFX() {
    var sel = '.btn, button.btn, button, .btn-hero-primary, .btn-hero-secondary, .btn-primary, .btn-secondary, .btn-ghost, .btn-danger, .btn-sm, .launchpad-btn, .topbar-btn-collect, .topbar-btn-action, .filter-pill-btn';

    document.addEventListener('pointermove', function (e) {
      var t = e.target;
      var btn = t && t.closest ? t.closest(sel) : null;
      if (!btn) return;
      var r = btn.getBoundingClientRect();
      if (!r.width) return;
      btn.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      btn.style.setProperty('--my', (e.clientY - r.top) + 'px');
    }, { passive: true });
  }

  /* ---------- Staggered Card Cascade Reveal (Smooth Spring Motion) ---------- */
  function triggerCardCascade(root) {
    if (reduced) return;
    var container = root || document.querySelector('.main-content') || document;
    var wrap = container.querySelector('.wrap') || container;
    if (!wrap) return;

    var cascadeElements = wrap.querySelectorAll(
      '.admin-kpi-grid > *, .kpi-card, .admin-kpi-card, .card, .panel, .clean-card, .chart-card, .table-card, .admin-launchpad-card'
    );

    cascadeElements.forEach(function (el, i) {
      el.style.setProperty('--stagger-i', i);
      el.classList.remove('stagger-in');
      void el.offsetWidth;
      el.classList.add('stagger-in');
    });

    var rowLimit = 20;
    var rows = wrap.querySelectorAll('table tbody tr');
    rows.forEach(function (row, i) {
      if (i >= rowLimit) return;
      row.style.setProperty('--row-i', i);
      row.classList.remove('row-in');
      void row.offsetWidth;
      row.classList.add('row-in');
    });
  }

  /* ---------- Interactive Cursor Spotlight Glow ---------- */
  var spotlightInitialized = false;
  function initSpotlight() {
    if (spotlightInitialized) return;
    spotlightInitialized = true;
    if (window.matchMedia('(pointer: coarse)').matches) return;

    document.addEventListener('mousemove', function (e) {
      var card = e.target.closest(
        '.admin-kpi-card, .card, .panel, .light-kpi-card, .admin-card, .table-card, .clean-card'
      );
      if (!card) return;
      var rect = card.getBoundingClientRect();
      var x = (e.clientX - rect.left);
      var y = (e.clientY - rect.top);
      card.style.setProperty('--mouse-x', x + 'px');
      card.style.setProperty('--mouse-y', y + 'px');
    }, { passive: true });
  }

  /* ---------- Modal / dialog accessibility labels ---------- */
  function initModalA11y() {
    document.querySelectorAll('.admin-modal-overlay').forEach(function (m) {
      if (!m.getAttribute('role')) { m.setAttribute('role', 'dialog'); }
      m.setAttribute('aria-modal', 'true');
      var title = m.querySelector('.admin-modal-title, .admin-modal-head h3, .admin-modal-head h2, .admin-modal-dialog h3');
      if (title) {
        if (!title.id) { title.id = 'adminModalTitle-' + Math.random().toString(36).slice(2, 8); }
        if (!m.getAttribute('aria-labelledby')) { m.setAttribute('aria-labelledby', title.id); }
      }
    });
    document.querySelectorAll('.admin-modal-close').forEach(function (b) {
      if (!b.getAttribute('aria-label')) { b.setAttribute('aria-label', 'Close dialog'); }
    });
  }

  function init() {
    initThemeToggle();
    initCounters();
    initReveal();
    initTabs();
    initButtonFX();
    initSpotlight();
    initModalA11y();
    triggerCardCascade();
    autoFitTextElements();
    if (window.AdminHeader && typeof window.AdminHeader.init === 'function') {
      window.AdminHeader.init();
    }
  }

  /* Expose re-runnable content initialisers for SPA navigation */
  window.AdminRuntime = {
    refresh: function () {
      initCounters();
      initReveal();
      triggerCardCascade();
      autoFitTextElements();
      initModalA11y();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
