/* =========================================================
   FatherGateway — Core Runtime & SPA Navigation Engine
   ========================================================= */

/* =========================================================
   FatherGateway — user.js (Core Runtime & SPA Engine)
   Modular architecture:
   - Components in: user/assets/js/components/
     (header.js, sidebar.js, modal.js, theme.js)
   - Pages in: user/assets/js/pages/
     (dashboard.js, transactions.js, payment-links.js, api-keys.js,
      webhooks.js, integrations.js, profile.js, docs.js, status.js)
   - Global Utilities: Toast, Clipboard Copy, Counter Animations,
     Fintech Spotlight, and Turbo SPA Navigation Engine.
   ========================================================= */

(function () {
  'use strict';

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // =========================================================
  // 1. GLOBAL HELPERS: TOAST, COPY, COUNTERS
  // =========================================================

  /* ---------- Toast Notification ---------- */
  window.toast = function (message, type) {
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    var icon = type === 'error' ? 'danger' : (type === 'info' ? 'info' : 'success');
    var icons = {
      success: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      danger: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
      info: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    t.innerHTML = '<span class="toast-icon">' + (icons[icon] || icons.info) + '</span><span class="toast-text">' + message + '</span>';
    var container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      document.body.appendChild(container);
    }
    container.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () {
      t.classList.remove('show');
      setTimeout(function () { t.remove(); }, 260);
    }, 3200);
  };

  /* ---------- Universal Multi-Platform Clipboard Copy (Preserves All Newlines/Enters) ---------- */
  function normalizeNewlines(str) {
    if (!str) return '';
    // Unescape literal \n or \r\n if present from attributes
    if (str.indexOf('\\n') !== -1 && str.indexOf('\n') === -1) {
      str = str.replace(/\\r\\n/g, '\n').replace(/\\n/g, '\n').replace(/\\t/g, '\t');
    }
    // Normalize to CRLF (\r\n) so when pasted into Windows Notepad, terminals, or mobile editors, enters always work
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

  /* 1-Tap Copy Delegation for all buttons & code terminals */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy], .docs-copy-action, .wh-code-copy-btn, .code-copy-action-btn, [data-copy-target]');
    if (!btn) return;

    var text = '';

    // 1. If explicit target specified
    var targetSelector = btn.getAttribute('data-copy-target');
    if (targetSelector) {
      var targetEl = document.querySelector(targetSelector);
      if (targetEl) {
        text = targetEl.innerText || targetEl.textContent || '';
      }
    }

    // 2. If button is in or next to a code terminal (docs-terminal, wh-code-box, apikey-code-box, or any pre container)
    if (!text) {
      var terminalBox = btn.closest('.docs-terminal, .wh-code-box, .apikey-code-box, .code-terminal-box');
      if (terminalBox) {
        var pres = terminalBox.querySelectorAll('pre');
        var activePre = null;
        for (var i = 0; i < pres.length; i++) {
          if (pres[i].offsetParent !== null || window.getComputedStyle(pres[i]).display !== 'none') {
            activePre = pres[i];
            break;
          }
        }
        if (!activePre && pres.length > 0) activePre = pres[0];
        if (activePre) {
          text = activePre.innerText || activePre.textContent || '';
        }
      }
    }

    // 3. Fallback to data-copy attribute
    if (!text) {
      text = btn.getAttribute('data-copy') || '';
    }

    if (!text) return;

    function showFeedback() {
      var span = btn.querySelector('span');
      if (span && span.textContent.trim().toLowerCase().indexOf('copy') !== -1) {
        if (!btn.dataset.origText) btn.dataset.origText = span.textContent;
        span.textContent = 'Copied!';
        setTimeout(function () {
          if (btn.dataset.origText) {
            span.textContent = btn.dataset.origText;
            delete btn.dataset.origText;
          }
        }, 1800);
      } else {
        if (!btn.dataset.origHtml) {
          btn.dataset.origHtml = btn.innerHTML;
        }
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        btn.classList.add('copied-active');
        setTimeout(function () {
          if (btn.dataset.origHtml) {
            btn.innerHTML = btn.dataset.origHtml;
            delete btn.dataset.origHtml;
          }
          btn.classList.remove('copied-active');
        }, 1400);
      }
    }

    window.copyToClipboard(text, showFeedback);
  });


  /* ---------- Number Counter Animation ---------- */
  function animateValue(el) {
    var target = parseFloat(el.getAttribute('data-count'));
    if (isNaN(target)) return;
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
    var prefix = el.getAttribute('data-prefix') || '';
    var suffix = el.getAttribute('data-suffix') || '';

    if (el.__animId) {
      cancelAnimationFrame(el.__animId);
      el.__animId = null;
    }

    if (reduced) {
      el.textContent = prefix + target.toLocaleString('en-IN', {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      }) + suffix;
      return;
    }

    var dur = 750;
    var start = null;
    el.classList.remove('count-pop');
    void el.offsetWidth;
    el.classList.add('count-pop');
    function frame(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      // Smooth quintic ease-out for financial terminal roll-up
      var eased = 1 - Math.pow(1 - p, 4);
      var val = target * eased;
      el.textContent = prefix + val.toLocaleString('en-IN', {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      }) + suffix;
      if (p < 1) {
        el.__animId = requestAnimationFrame(frame);
      } else {
        el.__animId = null;
      }
    }
    el.__animId = requestAnimationFrame(frame);
  }

  function initCounters() {
    document.querySelectorAll('[data-count]').forEach(function (el) {
      animateValue(el);
    });
  }

  /* ---------- Staggered Card Cascade Reveal (Orbix Studio Fintech Signature) ---------- */
  function triggerCardCascade(root) {
    if (reduced) return;
    var container = root || document.querySelector('.main-content') || document;
    var wrap = container.querySelector('.wrap') || container;
    if (!wrap) return;

    // Only content cards below the banner are animated - BANNER NEVER ANIMATES!
    var cascadeElements = wrap.querySelectorAll(
      '.dash-kpi-grid-light > *, .stat-grid > *, .dash-grid-main > *, .panel-clean, .overview-card, .dash-white-card, .card, .quick-action-card, .docs-card, .keys-panel, .wh-panel, .integrations-panel, .table-card'
    );

    cascadeElements.forEach(function (el, i) {
      el.style.setProperty('--stagger-i', i);
      el.classList.remove('stagger-in');
      void el.offsetWidth;
      el.classList.add('stagger-in');
    });

    // Stagger table rows so long ledgers read top-to-bottom instead of popping in
    var rowLimit = 24;
    var rows = wrap.querySelectorAll('table tbody tr');
    rows.forEach(function (row, i) {
      if (i >= rowLimit) return;
      row.style.setProperty('--row-i', i);
      row.classList.remove('row-in');
      void row.offsetWidth;
      row.classList.add('row-in');
    });

    initCounters();
  }

  /* ---------- Interactive Fintech Spotlight Cursor Tracker ---------- */
  var spotlightInitialized = false;
  function initSpotlight() {
    if (spotlightInitialized) return;
    spotlightInitialized = true;
    if (window.matchMedia('(pointer: coarse)').matches) return; // Touch devices skip cursor halo

    document.addEventListener('mousemove', function (e) {
      var card = e.target.closest(
        '.light-kpi-card, .kpi-card, .stat-card, .admin-kpi-card, .dash-white-card, .card, .panel, .s-panel, .w-card-body, .pl-card, .profile-card, .integ-card, .docs-card, .admin-card, .stat-card-wrap, .tx-filter-card, .table-card, .panel-clean, .overview-card, .quick-action-card, .docs-hero, .meta-ribbon-cell, .tx-card-mobile, .pl-card-mobile, .wh-card-mobile, .api-key-card, .log-card, .apikey-card-item, .api-bottom-card, .api-traffic-full-card, .api-status-item, .integ-feature-card, .integ-step-card, .integ-status-banner, .profile-grand-card, .checkout-preview-card, .preview-amount-card, .preview-apps-card, .danger-zone-card, .profile-tab-panel, .status-comp-card, .status-uptime-box, .tx-receipt-card, .tx-amount-hero-banner, .pl-create-card, .wh-create-card, .wh-tab-panel, .docs-section-panel'
      );
      if (!card) return;
      var rect = card.getBoundingClientRect();
      // The panel uses CSS zoom, so rect is in visual px while background-position
      // is applied in the (unzoomed) local px. Convert so the glow sits under the cursor.
      var scaleX = card.offsetWidth ? (rect.width / card.offsetWidth) : 1;
      var scaleY = card.offsetHeight ? (rect.height / card.offsetHeight) : 1;
      if (!scaleX || scaleX <= 0) scaleX = 1;
      if (!scaleY || scaleY <= 0) scaleY = 1;
      var x = (e.clientX - rect.left) / scaleX;
      var y = (e.clientY - rect.top) / scaleY;
      card.style.setProperty('--mouse-x', x + 'px');
      card.style.setProperty('--mouse-y', y + 'px');
    }, { passive: true });
  }

  /* ---------- Scroll Reveal & 3D Tilt ---------- */
  function initReveal() {
    var items = document.querySelectorAll('[data-reveal], [data-stagger]');
    items.forEach(function (el) { el.classList.add('is-visible'); });
  }

  function initTilt() {
    document.querySelectorAll('.tilt').forEach(function (card) {
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        var x = (e.clientX - r.left) / r.width - 0.5;
        var y = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = 'perspective(900px) rotateX(' + (-y * 6) + 'deg) rotateY(' + (x * 8) + 'deg) translateY(-2px)';
      });
      card.addEventListener('mouseleave', function () {
        card.style.transform = '';
      });
    });
  }

  /* ---------- Liquid / Rubber Pointer-Follow (KPI metric cards) ----------
     The card leans & pulls toward the cursor and springs back like rubber.
     Only the transform property is driven (translate/scale cascade untouched). */
  function initLiquidCards() {
    if (reduced) return;
    if (window.matchMedia('(hover: none), (pointer: coarse)').matches) return;

    var MAX_TILT = 8;     // degrees of lean at the card edge
    var PULL = 0.055;     // magnetic translate (fraction of card size)
    var K = 0.18;         // spring stiffness
    var DAMP = 0.70;      // spring damping (lower = more rubbery bounce)

    document.querySelectorAll('.light-kpi-card, .kpi-card, .stat-card, .admin-kpi-card').forEach(function (card) {
      if (card.__liquidBound) return;
      card.__liquidBound = true;
      card.classList.add('is-liquid');

      var st = { rx: 0, ry: 0, tx: 0, ty: 0, vrx: 0, vry: 0, vtx: 0, vty: 0 };
      var tg = { rx: 0, ry: 0, tx: 0, ty: 0 };
      var raf = null;

      function near() {
        return Math.abs(tg.rx - st.rx) < 0.03 && Math.abs(tg.ry - st.ry) < 0.03 &&
               Math.abs(tg.tx - st.tx) < 0.12 && Math.abs(tg.ty - st.ty) < 0.12 &&
               Math.abs(st.vrx) < 0.02 && Math.abs(st.vry) < 0.02 &&
               Math.abs(st.vtx) < 0.08 && Math.abs(st.vty) < 0.08;
      }

      function step() {
        st.vrx += (tg.rx - st.rx) * K; st.vrx *= DAMP; st.rx += st.vrx;
        st.vry += (tg.ry - st.ry) * K; st.vry *= DAMP; st.ry += st.vry;
        st.vtx += (tg.tx - st.tx) * K; st.vtx *= DAMP; st.tx += st.vtx;
        st.vty += (tg.ty - st.ty) * K; st.vty *= DAMP; st.ty += st.vty;

        card.style.setProperty(
          'transform',
          'perspective(900px) translate3d(' + st.tx.toFixed(2) + 'px,' + st.ty.toFixed(2) + 'px,0) ' +
          'rotateX(' + st.rx.toFixed(3) + 'deg) rotateY(' + st.ry.toFixed(3) + 'deg) scale(1.03)',
          'important'
        );

        if (near()) {
          if (tg.rx === 0 && tg.ry === 0 && tg.tx === 0 && tg.ty === 0) {
            card.style.removeProperty('transform');
          }
          raf = null;
          return;
        }
        raf = requestAnimationFrame(step);
      }

      function kick() {
        if (raf === null) raf = requestAnimationFrame(step);
      }

      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        if (!r.width || !r.height) return;
        var dx = (e.clientX - r.left) / r.width - 0.5;
        var dy = (e.clientY - r.top) / r.height - 0.5;
        tg.ry = dx * MAX_TILT * 2;
        tg.rx = -dy * MAX_TILT * 2;
        tg.tx = dx * r.width * PULL;
        tg.ty = dy * r.height * PULL;
        kick();
      }, { passive: true });

      card.addEventListener('mouseleave', function () {
        tg.rx = 0; tg.ry = 0; tg.tx = 0; tg.ty = 0;
        kick();
      });
    });
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

  /* ---------- Liquid button feedback: clean interaction (no glowing circular burst) ---------- */
  function initButtonFX() {
    if (window.__btnFXBound) return;
    window.__btnFXBound = true;

    var sel = '.btn, .wh-submit-btn, .pl-btn-create, .dash-action-btn, .dash-chip-copy';

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

  window.initAppWidgets = function () {
    initCounters();
    initReveal();
    initTilt();
    initSpotlight();
    initLiquidCards();
    initButtonFX();
    triggerCardCascade();
    autoFitTextElements();
    if (typeof window.initUniversalLiquidTabs === 'function') {
      window.initUniversalLiquidTabs();
    }
  };


  // =========================================================
  // 2. CENTRAL ORCHESTRATOR FOR MODULAR WIDGETS
  // =========================================================
  window.initDashboardV2 = function () {
    if (typeof animateNumberCounters === 'function') animateNumberCounters();
    triggerCardCascade();
    initSpotlight();
    initLiquidCards();
    initButtonFX();
    autoFitTextElements();
    if (typeof window.initUniversalLiquidTabs === 'function') {
      window.initUniversalLiquidTabs();
    }
  };

  // =========================================================
  // 3. TURBO SPA NAVIGATION ENGINE (Rock-Solid, Zero Scroll-Drift)
  // =========================================================
  // =========================================================
  var pageCache = new Map();
  var isNavigating = false;
  var progressBar = null;
  var progressTimer = null;
  var progressVal = 0;

  function ensureProgressBar() {
    if (!progressBar) {
      progressBar = document.getElementById('turboProgressBar');
      if (!progressBar) {
        progressBar = document.createElement('div');
        progressBar.id = 'turboProgressBar';
        document.body.appendChild(progressBar);
      }
    }
    return progressBar;
  }

  function startProgress() {
    var bar = ensureProgressBar();
    if (progressTimer) clearInterval(progressTimer);
    bar.classList.remove('error');
    bar.classList.add('is-active');
    progressVal = 20;
    bar.style.width = progressVal + '%';

    progressTimer = setInterval(function () {
      if (progressVal < 80) {
        progressVal += Math.random() * 14 + 6;
        bar.style.width = progressVal + '%';
      }
    }, 130);
  }

  function finishProgress() {
    var bar = ensureProgressBar();
    if (progressTimer) clearInterval(progressTimer);
    bar.style.width = '100%';
    setTimeout(function () {
      bar.classList.remove('is-active');
      setTimeout(function () {
        bar.style.width = '0%';
      }, 250);
    }, 140);
  }

  function errorProgress() {
    var bar = ensureProgressBar();
    if (progressTimer) clearInterval(progressTimer);
    bar.classList.add('error');
    bar.style.width = '100%';
    setTimeout(function () {
      bar.classList.remove('is-active', 'error');
      setTimeout(function () {
        bar.style.width = '0%';
      }, 250);
    }, 450);
  }

  function isEligibleLink(anchor, e) {
    if (!anchor || !anchor.href) return false;
    if (anchor.target && anchor.target !== '_self') return false;
    if (anchor.hasAttribute('download')) return false;
    if (e && (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0)) return false;

    // In-page documentation, profile, webhooks, filters, pagination, and secret reveals should never trigger full SPA navigation
    if (anchor.hasAttribute('data-docs-tab') ||
        anchor.hasAttribute('data-profile-tab') ||
        anchor.hasAttribute('data-wh-tab') ||
        anchor.hasAttribute('data-wh-log-filter') ||
        anchor.hasAttribute('data-toggle-secret') ||
        anchor.hasAttribute('data-hide-secret') ||
        anchor.classList.contains('docs-nav-item') ||
        anchor.classList.contains('docs-mobile-pill') ||
        anchor.classList.contains('docs-pager-btn') ||
        anchor.classList.contains('profile-tab-btn') ||
        anchor.classList.contains('tx-status-tab') ||
        anchor.classList.contains('tx-horizon-pill') ||
        anchor.classList.contains('tx-search-clear') ||
        anchor.classList.contains('tx-page-btn') ||
        anchor.classList.contains('btn-tx-export') ||
        anchor.closest('#txFilterCard') ||
        anchor.closest('#txPresentationCard') ||
        anchor.closest('#plFilterCard') ||
        anchor.closest('#plPresentationCard') ||
        anchor.classList.contains('pl-quick-amount-chip')) {
      return false;
    }

    var linkUrl;
    try {
      linkUrl = new URL(anchor.href, window.location.origin);
    } catch (err) {
      return false;
    }

    if (linkUrl.origin !== window.location.origin) return false;

    // Do NOT intercept logout or admin
    if (linkUrl.pathname.indexOf('logout.php') !== -1) return false;
    if (linkUrl.pathname.indexOf('/admin/') !== -1) return false;

    // Must be in /user/ or status.php
    var isAppPath = (linkUrl.pathname.indexOf('/user/') !== -1) ||
                    (linkUrl.pathname.indexOf('status.php') !== -1);
    if (!isAppPath) return false;

    // Already on exact path and query
    if (linkUrl.pathname === window.location.pathname && linkUrl.search === window.location.search) {
      if (linkUrl.hash) return false;
      return false;
    }

    // In-page query navigation on transactions, payment links, webhooks, or profile
    if (linkUrl.pathname === window.location.pathname) {
      if (window.location.pathname.indexOf('transactions.php') !== -1 ||
          window.location.pathname.indexOf('payment-links.php') !== -1 ||
          window.location.pathname.indexOf('webhooks.php') !== -1 ||
          window.location.pathname.indexOf('profile.php') !== -1 ||
          window.location.pathname.indexOf('docs.php') !== -1) {
        return false;
      }
    }

    return true;
  }

  function updateSidebarActive(targetHref) {
    try {
      var targetUrl = new URL(targetHref, window.location.origin);
      var targetPath = targetUrl.pathname.replace(/\/+$/, '');
      var navItems = document.querySelectorAll('.sidebar-nav .nav-item');

      navItems.forEach(function (item) {
        var href = item.getAttribute('href');
        if (!href) return;
        var itemUrl = new URL(href, window.location.origin);
        var itemPath = itemUrl.pathname.replace(/\/+$/, '');

        if (itemPath === targetPath) {
          item.classList.add('active');
        } else {
          item.classList.remove('active');
        }
      });
    } catch (e) {}
  }

  function ensurePageStyles(doc, done) {
    var target = '';
    doc.querySelectorAll('link[rel="stylesheet"]').forEach(function (nl) {
      var h = nl.getAttribute('href') || '';
      if (h.indexOf('/assets/css/pages/') !== -1) target = h;
    });
    if (!target) { done(); return; }
    var key = target.split('?')[0];
    var present = false;
    document.querySelectorAll('link[rel="stylesheet"]').forEach(function (l) {
      if ((l.getAttribute('href') || '').split('?')[0] === key) present = true;
    });
    if (present) { done(); return; }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = target;
    var finished = false;
    var proceed = function () { if (finished) return; finished = true; done(); };
    link.onload = proceed;
    link.onerror = proceed;
    document.head.appendChild(link);
    setTimeout(proceed, 900);
  }

  function renderPage(html, url, pushState, onComplete) {
    var parser = new DOMParser();
    var doc = parser.parseFromString(html, 'text/html');

    var newMain = doc.querySelector('.main-content');
    var currentMain = document.querySelector('.main-content');

    if (!newMain || !currentMain) {
      window.location.href = url;
      return;
    }

    var newTitle = doc.querySelector('title');
    if (newTitle) {
      document.title = newTitle.textContent;
    }

    var currentTopbar = currentMain.querySelector('.app-topbar-goll');
    var newTopbar = newMain.querySelector('.app-topbar-goll');
    var currentWrap = currentMain.querySelector('.wrap');
    var newWrap = newMain.querySelector('.wrap');

    function swapDOM() {
      // 1. Keep header .app-topbar-goll completely intact - never shakes or moves
      if (currentTopbar && newTopbar) {
        var currentCrumb = currentTopbar.querySelector('.topbar-crumb-current');
        var newCrumb = newTopbar.querySelector('.topbar-crumb-current');
        if (currentCrumb && newCrumb) {
          currentCrumb.textContent = newCrumb.textContent;
        }

        var currentMobileHeading = currentTopbar.querySelector('.topbar-mobile-heading');
        var newMobileHeading = newTopbar.querySelector('.topbar-mobile-heading');
        if (currentMobileHeading && newMobileHeading) {
          currentMobileHeading.textContent = newMobileHeading.textContent;
        }
      }

      // 2. Swap ONLY .wrap content (header and sidebar are 100% untouched)
      if (currentWrap && newWrap) {
        currentWrap.innerHTML = newWrap.innerHTML;
      } else {
        currentMain.innerHTML = newMain.innerHTML;
      }

      // Soft page transition: fade the freshly swapped content in
      var enterTarget = currentWrap || currentMain;
      if (enterTarget && !reduced) {
        enterTarget.classList.remove('page-enter');
        void enterTarget.offsetWidth;
        enterTarget.classList.add('page-enter');
      }

      // 3. New stylesheets
      var targetPageCss = '';
      doc.querySelectorAll('link[rel="stylesheet"]').forEach(function (nl) {
        var h = nl.getAttribute('href') || '';
        if (h.indexOf('/assets/css/pages/') !== -1) targetPageCss = h.split('?')[0];
      });
      document.querySelectorAll('link[rel="stylesheet"]').forEach(function (stale) {
        var h = stale.getAttribute('href') || '';
        if (h.indexOf('/assets/css/pages/') !== -1 && h.split('?')[0] !== targetPageCss) {
          stale.parentNode.removeChild(stale);
        }
      });
      var newStyles = doc.querySelectorAll('link[rel="stylesheet"], head style');
      newStyles.forEach(function (ns) {
        var href = ns.getAttribute('href');
        if (href && !document.querySelector('link[href="' + href + '"]')) {
          var l = document.createElement('link');
          l.rel = 'stylesheet';
          l.href = href;
          document.head.appendChild(l);
        }
      });

      // 4. Execute scripts
      var targetScope = currentWrap || currentMain;
      var scripts = (newWrap || newMain).querySelectorAll('script');
      scripts.forEach(function (s) {
        var newScript = document.createElement('script');
        if (s.src) {
          newScript.src = s.src;
        } else {
          newScript.textContent = s.textContent;
        }
        document.body.appendChild(newScript);
        newScript.remove();
      });

      // 4b. Sync dedicated page scripts (e.g. user/assets/js/pages/*.js)
      var incomingPageScripts = doc.querySelectorAll('script[src*="/pages/"]');
      incomingPageScripts.forEach(function (s) {
        var src = s.getAttribute('src');
        if (src) {
          var cleanSrc = src.split('?')[0];
          var existing = document.querySelector('script[src*="' + cleanSrc + '"]');
          if (existing) {
            existing.remove();
          }
          var pageScript = document.createElement('script');
          pageScript.src = src;
          document.body.appendChild(pageScript);
        }
      });

      if (pushState !== false) {
        window.history.pushState({ turbo: true, url: url }, '', url);
      }

      window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
      updateSidebarActive(url);
      initDashboardV2();

      window.dispatchEvent(new Event('turbo:load'));
      window.dispatchEvent(new CustomEvent('page:loaded', { detail: { url: url } }));
    }

    ensurePageStyles(doc, function () {
      swapDOM();
      triggerCardCascade(currentWrap || currentMain);
      if (typeof onComplete === 'function') onComplete();
    });
  }

  function navigateTo(url, pushState) {
    if (isNavigating) return;
    isNavigating = true;
    startProgress();
    updateSidebarActive(url);

    if (window.innerWidth <= 960 && typeof window.closeSidebar === 'function') {
      window.closeSidebar();
    }

    var cachedHtml = pageCache.get(url);
    if (cachedHtml) {
      renderPage(cachedHtml, url, pushState, function () {
        finishProgress();
        isNavigating = false;
      });
      return;
    }

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (res) {
        if (res.redirected) {
          window.location.href = res.url;
          return null;
        }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function (html) {
        if (!html) return;
        pageCache.set(url, html);
        renderPage(html, url, pushState, function () {
          finishProgress();
          isNavigating = false;
        });
      })
      .catch(function () {
        errorProgress();
        isNavigating = false;
        window.location.href = url;
      });
  }

  window.navigateTo = navigateTo;

  // ---------- Hover & Touch Pre-fetching ----------
  var prefetchTimer = null;

  document.addEventListener('mouseover', function (e) {
    var a = e.target.closest('a');
    if (!a || !isEligibleLink(a)) return;
    var href = a.href;
    if (pageCache.has(href)) return;

    clearTimeout(prefetchTimer);
    prefetchTimer = setTimeout(function () {
      fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) {
          if (res.ok) return res.text();
          throw new Error('Prefetch fail');
        })
        .then(function (html) {
          pageCache.set(href, html);
          if (pageCache.size > 25) {
            var first = pageCache.keys().next().value;
            pageCache.delete(first);
          }
        })
        .catch(function () {});
    }, 60);
  });

  document.addEventListener('touchstart', function (e) {
    var a = e.target.closest('a');
    if (!a || !isEligibleLink(a)) return;
    var href = a.href;
    if (pageCache.has(href)) return;

    fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (res) { return res.ok ? res.text() : null; })
      .then(function (html) {
        if (html) pageCache.set(href, html);
      })
      .catch(function () {});
  }, { passive: true });

  // ---------- Click Interceptor ----------
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a) return;
    if (!isEligibleLink(a, e)) return;

    e.preventDefault();
    navigateTo(a.href, true);
  });

  // ---------- Browser Back / Forward (Popstate) ----------
  window.addEventListener('popstate', function (e) {
    if (window.location.pathname.indexOf('docs.php') !== -1 ||
        window.location.pathname.indexOf('profile.php') !== -1 ||
        window.location.pathname.indexOf('webhooks.php') !== -1 ||
        window.location.pathname.indexOf('transactions.php') !== -1 ||
        window.location.pathname.indexOf('payment-links.php') !== -1) {
      // In-page engines handle their respective popstate events without full wrap replacement
      return;
    }
    if (e.state && e.state.turbo) {
      navigateTo(window.location.href, false);
    } else if (window.location.pathname.indexOf('/user/') !== -1 || window.location.pathname.indexOf('status.php') !== -1) {
      navigateTo(window.location.href, false);
    }
  });

  // Prevent scroll drift on reload
  if ('scrollRestoration' in window.history) {
    try { window.history.scrollRestoration = 'manual'; } catch (e) {}
  }

  if (window.history && window.history.replaceState) {
    window.history.replaceState({ turbo: true, url: window.location.href }, '', window.location.href);
  }

  // ---------- Keyboard Shortcut: Ctrl+K or ⌘K ----------
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      var searchInput = document.querySelector('.topbar-search-input');
      if (searchInput) {
        searchInput.focus();
        if (window.toast) window.toast('Omni-search activated', 'info');
      }
    }
  });

    // ---------- Custom Right-Click Context Menu ----------
    function ctxIcon(name) {
      var P = {
        back: '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
        refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        open: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        newtab: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        dashboard: '<rect width="7.5" height="7.5" x="3" y="3" rx="2"/><rect width="7.5" height="7.5" x="13.5" y="3" rx="2"/><rect width="7.5" height="7.5" x="13.5" y="13.5" rx="2"/><path d="M7 13.5a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2a2 2 0 0 0-2-2Z"/>',
        link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        receipt: '<rect width="20" height="14" x="2" y="5" rx="3"/><line x1="2" x2="22" y1="10" y2="10"/><path d="M6 14.5h3"/><path d="M14 14.5h4"/>',
        key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 8.8-8.8a1.5 1.5 0 0 1 2.1 0l.9.9-2 2 1.5 1.5-1.5 1.5 1.5 1.5-3 3"/><circle cx="7.5" cy="15.5" r="1.5" fill="currentColor"/>',
        book: '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="m9 9.5 2 2-2 2"/><line x1="13" x2="16" y1="13.5" y2="13.5"/>',
        user: '<circle cx="12" cy="7.5" r="4"/><path d="M5 20.5c0-3.3 3.1-6 7-6s7 2.7 7 6"/><circle cx="12" cy="7.5" r="1.5" fill="currentColor"/>',
        moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>'
      };
      return '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (P[name] || P.copy) + '</svg>';
    }

    function ctxEscape(str) {
      return String(str == null ? '' : str).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    // Grab the most specific readable text under the press point (for touch Copy)
    function pickContextText(el) {
      var node = el;
      var best = '';
      for (var i = 0; i < 4 && node && node.nodeType === 1 && node !== document.body; i++) {
        var txt = (node.textContent || '').replace(/\s+/g, ' ').trim();
        if (txt) {
          if (txt.length <= 120) return txt;
          if (!best) best = txt;
        }
        node = node.parentElement;
      }
      return best.length <= 300 ? best : '';
    }

    function initContextMenu() {
      if (document.getElementById('fxContextMenu')) return;

      var menu = document.createElement('div');
      menu.id = 'fxContextMenu';
      menu.className = 'fx-ctx';
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-hidden', 'true');
      document.body.appendChild(menu);

      var isOpen = false;

      function findAnchor(needle) {
        var links = document.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
          var h = links[i].getAttribute('href') || '';
          if (h.indexOf(needle) !== -1) return links[i];
        }
        return null;
      }

      function isDark() {
        return document.documentElement.classList.contains('dark-mode') ||
               (document.body && document.body.classList.contains('dark-mode'));
      }

      function makeClick(anchor) {
        return function () {
          if (anchor && anchor.isConnected) anchor.click();
        };
      }

      function build(items) {
        menu.innerHTML = '';

        var head = document.createElement('div');
        head.className = 'fx-ctx-head';
        var pageEl = document.querySelector('.topbar-crumb-current');
        var pageName = pageEl ? pageEl.textContent.trim() : 'Console';
        head.innerHTML = '<span class="fx-ctx-dot"></span>' +
          '<span class="fx-ctx-brand">FatherGateway</span>' +
          '<span class="fx-ctx-page">' + ctxEscape(pageName || 'Console') + '</span>';
        menu.appendChild(head);

        var list = document.createElement('div');
        list.className = 'fx-ctx-list';

        items.forEach(function (it) {
          if (it === '-') {
            var sep = document.createElement('div');
            sep.className = 'fx-ctx-sep';
            list.appendChild(sep);
            return;
          }
          var row = document.createElement('div');
          row.className = 'fx-ctx-item' +
            (it.danger ? ' is-danger' : '') +
            (it.disabled ? ' is-disabled' : '');
          row.setAttribute('role', 'menuitem');
          row.tabIndex = -1;
          row.innerHTML = '<span class="fx-ctx-ico">' + ctxIcon(it.icon) + '</span>' +
            '<span class="fx-ctx-label">' + it.label + '</span>' +
            (it.key ? '<span class="fx-ctx-key">' + it.key + '</span>' : '');
          if (!it.disabled) {
            row.addEventListener('click', function (ev) {
              ev.preventDefault();
              ev.stopPropagation();
              hide();
              if (typeof it.action === 'function') it.action();
            });
          }
          list.appendChild(row);
        });

        menu.appendChild(list);
      }

      function show(x, y, items, touchMode) {
        build(items);
        menu.style.left = '-9999px';
        menu.style.top = '0px';
        menu.classList.add('is-open');
        menu.setAttribute('aria-hidden', 'false');

        var mw = menu.offsetWidth;
        var mh = menu.offsetHeight;
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        var pad = 10;
        var left, top;

        if (touchMode) {
          // Phone: drop the sheet just below the finger, flip above only when it won't fit
          top = y + 14;
          if (top + mh > vh - pad) top = y - mh - 14;
          left = x - 18;
        } else {
          left = x;
          top = y;
        }

        if (left + mw > vw - pad) left = vw - mw - pad;
        if (left < pad) left = pad;
        if (top + mh > vh - pad) top = vh - mh - pad;
        if (top < pad) top = pad;

        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        menu.__shownAt = Date.now();
        isOpen = true;
      }

      function hide() {
        if (!isOpen) return;
        isOpen = false;
        menu.classList.remove('is-open');
        menu.setAttribute('aria-hidden', 'true');
      }

      document.addEventListener('contextmenu', function (e) {
        var t = e.target;
        if (t && t.closest && t.closest('input, textarea, [contenteditable="true"], [data-native-menu], .allow-native-menu')) {
          return;
        }
        e.preventDefault();

        var link = (t && t.closest) ? t.closest('a[href]') : null;
        if (link && (!link.href || link.getAttribute('href').indexOf('javascript:') === 0)) link = null;

        var sel = window.getSelection ? String(window.getSelection()) : '';
        var items = [];
        var isTouch = window.matchMedia('(hover: none), (pointer: coarse)').matches || window.innerWidth <= 820;

        if (link) {
          items.push({ icon: 'open', label: 'Open Link', action: makeClick(link) });
          if (!isTouch) {
            items.push({ icon: 'newtab', label: 'Open in New Tab', action: (function (u) {
              return function () { window.open(u, '_blank', 'noopener'); };
            })(link.href) });
          }
          items.push('-');
        }

        items.push({ icon: 'back', label: 'Back', action: function () { window.history.back(); } });
        items.push({ icon: 'refresh', label: 'Reload', action: function () { window.location.reload(); } });
        items.push('-');

        if (sel && sel.trim()) {
          items.push({ icon: 'copy', label: 'Copy', action: function () { window.copyToClipboard(sel); } });
        }

        // Phone: long-press has no pre-selection, so let Copy grab the text under the finger
        var pickedText = '';
        if (isTouch && !(sel && sel.trim())) {
          pickedText = link ? '' : pickContextText(t);
          if (pickedText) {
            items.push({ icon: 'copy', label: 'Copy', action: (function (v) {
              return function () { window.copyToClipboard(v); };
            })(pickedText) });
          }
        }

        if (sel && sel.trim() || link || !pickedText) {
          items.push({
            icon: 'copy',
            label: link ? 'Copy Link Address' : 'Copy Page Link',
            action: function () {
              var a = (t && t.closest) ? t.closest('a[href]') : null;
              window.copyToClipboard(a ? a.href : window.location.href);
            }
          });
        }

        // Page navigation shortcuts stay desktop-only — the mobile sidebar already covers them,
        // so the touch long-press menu stays short and native-feeling.
        if (!isTouch) {
          var navDefs = [
            ['dashboard.php', 'dashboard', 'Dashboard'],
            ['payment-links.php', 'link', 'Payment Links'],
            ['transactions.php', 'receipt', 'Transactions'],
            ['api-keys.php', 'key', 'API Keys'],
            ['docs.php', 'book', 'Documentation']
          ];
          var navItems = [];
          navDefs.forEach(function (d) {
            var a = findAnchor(d[0]);
            if (a) navItems.push({ icon: d[1], label: d[2], action: makeClick(a) });
          });
          if (navItems.length) {
            items.push('-');
            items = items.concat(navItems);
          }
        }

        items.push('-');
        items.push({
          icon: isDark() ? 'sun' : 'moon',
          label: isDark() ? 'Switch to Light Mode' : 'Switch to Night Mode',
          action: function () {
            var b = document.getElementById('themeToggleBtn');
            if (b) b.click();
          }
        });
        var logoutA = findAnchor('logout.php');
        if (logoutA) {
          items.push({ icon: 'logout', label: 'Sign Out', danger: true, action: makeClick(logoutA) });
        }

        show(e.clientX || 0, e.clientY || 0, items, isTouch);
      });

      document.addEventListener('click', function (e) {
        // Ignore the click fired right after a touch long-press reveals the menu
        if (isOpen && menu.__shownAt && Date.now() - menu.__shownAt < 350) return;
        if (isOpen && !menu.contains(e.target)) hide();
      }, true);

      document.addEventListener('scroll', function () { hide(); }, true);
      window.addEventListener('resize', hide);
      window.addEventListener('blur', hide);
      window.addEventListener('popstate', hide);
      document.addEventListener('keydown', function (e) {
        if (isOpen && e.key === 'Escape') hide();
      });
    }

    // ---------- Quick QR Collect Modal Engine ----------
    var qrModal = document.getElementById('quickQrModal');
    var qrModalInput = document.getElementById('quickQrAmountInput');
    var qrModalSvgHolder = document.getElementById('quickQrSvgHolder');
    var qrModalUpiLink = document.getElementById('quickQrUpiLink');
    var qrModalPaytm = document.getElementById('quickQrPaytm');
    var qrModalPhonepe = document.getElementById('quickQrPhonepe');
    var qrModalGpay = document.getElementById('quickQrGpay');
    var qrLoadToken = 0;
    var qrInputTimer = null;
    var qrLastUri = '';
    var qrLastImgUrl = '';
    var qrLoadedImg = null;

    window.openQuickQrModal = function (amount) {
      if (typeof window.closeSidebar === 'function') {
        window.closeSidebar();
      }
      if (!qrModal) qrModal = document.getElementById('quickQrModal');
      if (!qrModal) return;
      if (amount && qrModalInput) {
        qrModalInput.value = amount;
      }
      updateModalQr();
      qrModal.classList.remove('is-closing');
      qrModal.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    };

    window.closeQuickQrModal = function () {
      if (!qrModal) qrModal = document.getElementById('quickQrModal');
      if (!qrModal) return;
      if (!qrModal.classList.contains('is-open')) return;
      document.body.style.overflow = '';
      if (reduced) {
        qrModal.classList.remove('is-open', 'is-closing');
        return;
      }
      qrModal.classList.add('is-closing');
      setTimeout(function () {
        qrModal.classList.remove('is-open', 'is-closing');
      }, 200);
    };

    function loadQrImage(upiUri) {
      qrLastUri = upiUri;
      qrLoadedImg = null;
      var token = ++qrLoadToken;
      if (!qrModalSvgHolder) qrModalSvgHolder = document.getElementById('quickQrSvgHolder');
      if (!qrModalSvgHolder) return;

      var dlBtn = document.getElementById('quickQrDownloadBtn');
      if (dlBtn) dlBtn.disabled = true;

      qrModalSvgHolder.classList.remove('qr-in');
      qrModalSvgHolder.innerHTML =
        '<div class="quick-qr-loading">' +
          '<div class="quick-qr-spinner"></div>' +
          '<span class="quick-qr-loading-text">Generating QR...</span>' +
        '</div>';

      qrLastImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=6&data=' + encodeURIComponent(upiUri);
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function () {
        if (token !== qrLoadToken || !qrModalSvgHolder) return;
        img.alt = 'UPI QR';
        img.style.cssText = 'width:100%;height:100%;display:block;';
        var frame = document.createElement('div');
        frame.className = 'quick-qr-frame';
        frame.appendChild(img);
        qrModalSvgHolder.innerHTML = '';
        qrModalSvgHolder.appendChild(frame);
        qrModalSvgHolder.classList.add('qr-in');
        qrLoadedImg = img;
        if (dlBtn) dlBtn.disabled = false;
      };
      img.onerror = function () {
        if (token !== qrLoadToken || !qrModalSvgHolder) return;
        qrModalSvgHolder.classList.remove('qr-in');
        qrModalSvgHolder.innerHTML =
          '<div class="quick-qr-error">' +
            '<span>Couldn\'t load the QR. Check your connection and retry.</span>' +
            '<button type="button" class="quick-qr-retry">Retry</button>' +
          '</div>';
        var rb = qrModalSvgHolder.querySelector('.quick-qr-retry');
        if (rb) rb.addEventListener('click', function () { loadQrImage(qrLastUri || upiUri); });
      };
      img.src = qrLastImgUrl;
    }

    function updateModalQr() {
      if (!qrModalInput) qrModalInput = document.getElementById('quickQrAmountInput');
      if (!qrModalSvgHolder) qrModalSvgHolder = document.getElementById('quickQrSvgHolder');
      if (!qrModalInput) return;

      var amt = parseFloat(qrModalInput.value) || 0;
      var vpa = (window.GATEWAY_UPI_ID || '').trim();
      var name = (window.GATEWAY_MERCHANT_NAME || 'FatherGateway').trim();
      if (!vpa) {
        qrLoadToken++;
        if (qrModalSvgHolder) {
          qrModalSvgHolder.innerHTML = '<div class="quick-qr-error"><span>No UPI ID is set on this account.</span></div>';
        }
        return;
      }

      var upiUri = 'upi://pay?pa=' + encodeURIComponent(vpa) +
                   '&pn=' + encodeURIComponent(name) +
                   '&cu=INR' +
                   (amt > 0 ? '&am=' + amt.toFixed(2) : '') +
                   '&tn=Payment%20to%20' + encodeURIComponent(name);

      if (qrModalUpiLink) qrModalUpiLink.href = upiUri;
      if (qrModalPaytm) qrModalPaytm.href = 'paytmmp://pay?pa=' + encodeURIComponent(vpa) + '&pn=' + encodeURIComponent(name) + (amt > 0 ? '&am=' + amt.toFixed(2) : '');
      if (qrModalPhonepe) qrModalPhonepe.href = 'phonepe://pay?pa=' + encodeURIComponent(vpa) + '&pn=' + encodeURIComponent(name) + (amt > 0 ? '&am=' + amt.toFixed(2) : '');
      if (qrModalGpay) qrModalGpay.href = 'gpay://upi/pay?pa=' + encodeURIComponent(vpa) + '&pn=' + encodeURIComponent(name) + (amt > 0 ? '&am=' + amt.toFixed(2) : '');

      loadQrImage(upiUri);
    }

    function currentQrAmount() {
      var amt = parseFloat(qrModalInput && qrModalInput.value) || 0;
      return amt > 0 ? amt.toFixed(2) : '';
    }

    function buildQrCanvas() {
      var img = qrLoadedImg;
      if (!img || !img.complete || !img.naturalWidth) return null;

      var W = 640, H = 900;
      var c = document.createElement('canvas');
      c.width = W; c.height = H;
      var ctx = c.getContext('2d');

      var bg = ctx.createLinearGradient(0, 0, W, H);
      bg.addColorStop(0, '#0a1230');
      bg.addColorStop(1, '#16255e');
      ctx.fillStyle = bg;
      ctx.fillRect(0, 0, W, H);

      var glow = ctx.createRadialGradient(W / 2, 96, 10, W / 2, 96, 420);
      glow.addColorStop(0, 'rgba(56,189,248,0.30)');
      glow.addColorStop(1, 'rgba(56,189,248,0)');
      ctx.fillStyle = glow;
      ctx.fillRect(0, 0, W, 440);

      var cur = window.GATEWAY_CURRENCY || '\u20B9';
      var amt = currentQrAmount();
      var name = (window.GATEWAY_MERCHANT_NAME || 'FatherGateway').trim();
      ctx.textAlign = 'center';

      ctx.fillStyle = '#7dd3fc';
      ctx.font = '700 22px Inter, Arial, sans-serif';
      ctx.fillText('SCAN & PAY', W / 2, 92);

      ctx.fillStyle = '#ffffff';
      ctx.font = '700 38px Sora, Inter, Arial, sans-serif';
      ctx.fillText(truncateText(ctx, name, W - 140), W / 2, 148);

      ctx.fillStyle = '#bfdbfe';
      if (amt) {
        ctx.font = '800 66px Sora, Inter, Arial, sans-serif';
        ctx.fillText(cur + amt, W / 2, 232);
      } else {
        ctx.font = '700 34px Sora, Inter, Arial, sans-serif';
        ctx.fillText('Scan to Pay', W / 2, 224);
      }

      var cardX = 90, cardY = 272, cardW = 460, cardH = 460, rad = 40, qr = 372;
      roundRectPath(ctx, cardX, cardY, cardW, cardH, rad);
      ctx.fillStyle = '#ffffff';
      ctx.shadowColor = 'rgba(0,0,0,0.45)';
      ctx.shadowBlur = 44;
      ctx.shadowOffsetY = 20;
      ctx.fill();
      ctx.shadowColor = 'transparent';
      ctx.shadowBlur = 0;
      ctx.shadowOffsetY = 0;

      try {
        ctx.drawImage(img, cardX + (cardW - qr) / 2, cardY + (cardH - qr) / 2, qr, qr);
      } catch (err) {
        return null;
      }

      ctx.fillStyle = '#cbd5e1';
      ctx.font = '600 22px Inter, Arial, sans-serif';
      ctx.fillText('Scan with any UPI app', W / 2, cardY + cardH + 74);

      var site = (window.GATEWAY_SITE_NAME || '').trim();
      if (site) {
        ctx.fillStyle = 'rgba(148,163,184,0.9)';
        ctx.font = '600 20px Inter, Arial, sans-serif';
        ctx.fillText('Powered by ' + site, W / 2, H - 48);
      }
      return c;
    }

    function truncateText(ctx, text, maxWidth) {
      if (ctx.measureText(text).width <= maxWidth) return text;
      var out = text;
      while (out.length > 1 && ctx.measureText(out + '\u2026').width > maxWidth) {
        out = out.slice(0, -1);
      }
      return out + '\u2026';
    }

    function roundRectPath(ctx, x, y, w, h, r) {
      r = Math.min(r, w / 2, h / 2);
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
    }

    function qrShareText() {
      var amt = currentQrAmount();
      var name = (window.GATEWAY_MERCHANT_NAME || 'FatherGateway').trim();
      var vpa = (window.GATEWAY_UPI_ID || '').trim();
      return (amt ? 'Pay \u20B9' + amt + ' ' : 'Pay ') + 'to ' + name +
             '\nUPI ID: ' + vpa +
             '\n' + (qrLastUri || '');
    }

    function textShare(text) {
      if (navigator.share) {
        navigator.share({ title: 'UPI Payment', text: text }).catch(function () {});
        return;
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          if (window.toast) window.toast('Payment details copied', 'success');
        }).catch(function () {
          if (window.toast) window.toast('Could not share', 'error');
        });
      } else if (window.toast) {
        window.toast('Sharing not supported', 'error');
      }
    }

    function downloadQr() {
      var canvas = buildQrCanvas();
      if (!canvas) {
        if (qrLastImgUrl) window.open(qrLastImgUrl, '_blank');
        return;
      }
      try {
        canvas.toBlob(function (blob) {
          if (!blob) { if (qrLastImgUrl) window.open(qrLastImgUrl, '_blank'); return; }
          var amt = currentQrAmount();
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = 'upi-qr-' + (amt || 'payment') + '.png';
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
          if (window.toast) window.toast('QR downloaded', 'success');
        }, 'image/png');
      } catch (err) {
        if (qrLastImgUrl) window.open(qrLastImgUrl, '_blank');
      }
    }

    function shareQr() {
      var text = qrShareText();
      var canvas = buildQrCanvas();
      if (canvas && navigator.canShare && typeof navigator.share === 'function') {
        try {
          canvas.toBlob(function (blob) {
            var file = blob ? new File([blob], 'upi-qr.png', { type: 'image/png' }) : null;
            if (file && navigator.canShare({ files: [file] })) {
              navigator.share({ files: [file], title: 'UPI Payment QR', text: text }).catch(function () {});
              return;
            }
            textShare(text);
          }, 'image/png');
          return;
        } catch (err) { /* fall through to text share */ }
      }
      textShare(text);
    }

    (function bindQrActions() {
      var dl = document.getElementById('quickQrDownloadBtn');
      var sh = document.getElementById('quickQrShareBtn');
      if (dl) dl.addEventListener('click', function (e) { e.preventDefault(); downloadQr(); });
      if (sh) sh.addEventListener('click', function (e) { e.preventDefault(); shareQr(); });
    })();

    if (qrModalInput) {
      qrModalInput.addEventListener('input', function () {
        if (qrInputTimer) clearTimeout(qrInputTimer);
        qrInputTimer = setTimeout(updateModalQr, 260);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && typeof window.closeQuickQrModal === 'function') {
        window.closeQuickQrModal();
      }
    });

    // ---------- Theme Switcher Engine ----------
    function initTheme() {
      var themeToggleBtn = document.getElementById('themeToggleBtn');

      function applyTheme(theme) {
        if (theme === 'dark') {
          document.documentElement.classList.add('dark-mode');
          if (document.body) document.body.classList.add('dark-mode');
          document.documentElement.setAttribute('data-theme', 'dark');
          if (themeToggleBtn) {
            themeToggleBtn.setAttribute('title', 'Switch to Light Mode');
            themeToggleBtn.setAttribute('aria-label', 'Switch to Light Mode');
          }
        } else {
          document.documentElement.classList.remove('dark-mode');
          if (document.body) document.body.classList.remove('dark-mode');
          document.documentElement.setAttribute('data-theme', 'light');
          if (themeToggleBtn) {
            themeToggleBtn.setAttribute('title', 'Switch to Night Mode');
            themeToggleBtn.setAttribute('aria-label', 'Switch to Night Mode');
          }
        }
      }

      var savedTheme = 'light';
      try {
        savedTheme = localStorage.getItem('user_theme') || localStorage.getItem('admin_theme') || 'light';
      } catch (e) {}
      applyTheme(savedTheme);

      if (themeToggleBtn) {
        themeToggleBtn.onclick = function (e) {
          e.preventDefault();
          var currentIsDark = document.documentElement.classList.contains('dark-mode');
          var newTheme = currentIsDark ? 'light' : 'dark';
          applyTheme(newTheme);
          try {
            localStorage.setItem('user_theme', newTheme);
            document.cookie = 'user_theme=' + newTheme + ';path=/;max-age=31536000;SameSite=Lax';
          } catch (e) {}
          if (typeof window.toast === 'function') {
            window.toast(newTheme === 'dark' ? 'Night Mode Activated' : 'Light Mode Activated', 'info');
          }
        };
      }
    }

    initTheme();

    initContextMenu();

  // Initialize on load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardV2);
  } else {
    initDashboardV2();
  }

})();
