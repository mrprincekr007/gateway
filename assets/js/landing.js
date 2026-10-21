/* =========================================================
   FatherGateway — landing.js
   Scroll motion (GSAP + ScrollTrigger), interactive 3D hero
   (Three.js), code tabs, live toast, nav scrollspy,
   smooth anchors, back-to-top. Loaded only on the home page.
   Uses native scrolling (no scroll hijacking) so the page
   always scrolls smoothly and reliably.
   ========================================================= */
(function () {
  'use strict';

  var body = document.body;

  /* ---------- Global Toast Notification (Bottom Center) ---------- */
  window.toast = function (message, type) {
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    var icon = type === 'error' ? 'danger' : (type === 'info' ? 'info' : 'success');
    var icons = {
      success: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      danger: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14a2 2 0 0 0 1.73 3h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
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
      setTimeout(function () { t.remove(); }, 300);
    }, 3200);
  };

  /* ---------- Global Copy to Clipboard ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) { return; }
    var text = btn.getAttribute('data-copy') || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        window.toast('Copied to clipboard', 'success');
      });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        window.toast('Copied to clipboard', 'success');
      } catch (err) {
        window.toast('Could not copy', 'error');
      }
      ta.remove();
    }
  });

  /* ---------- Custom Right-Click Context Menu ---------- */
  (function initContextMenu() {
    var ICON = {
      open: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
      newtab: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
      copy: '<rect width="14" height="14" x="8" y="8" rx="2.5"/><path d="M4 16a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2"/>',
      back: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
      reload: '<path d="M3 12a9 9 0 0 1 15.5-6.4L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.4L3 16"/><path d="M3 21v-5h5"/>',
      home: '<path d="M3 9.5 12 3l9 6.5"/><path d="M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9"/>',
      zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
      activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
      code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
      rupee: '<path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13h7a5 5 0 0 0 0-10"/><path d="m9 13 6 8"/><path d="M6 13h3l6 8"/>',
      book: '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M9 8h7"/><path d="M9 12h7"/>',
      login: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
      register: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
      dashboard: '<rect width="7.5" height="7.5" x="3" y="3" rx="2"/><rect width="7.5" height="7.5" x="13.5" y="3" rx="2"/><rect width="7.5" height="7.5" x="13.5" y="13.5" rx="2"/><path d="M7 13.5a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2a2 2 0 0 0-2-2Z"/>'
    };

    function icon(name) {
      return '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICON[name] || ICON.copy) + '</svg>';
    }

    function escapeHtml(str) {
      return String(str == null ? '' : str).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    function copyText(text) {
      if (!text) { return; }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          if (typeof window.toast === 'function') { window.toast('Copied to clipboard', 'success'); }
        });
        return;
      }
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try {
        document.execCommand('copy');
        if (typeof window.toast === 'function') { window.toast('Copied to clipboard', 'success'); }
      } catch (err) {}
      ta.remove();
    }

    function pageLabel() {
      var el = document.querySelector('[data-ctx-page]');
      if (el) { return el.getAttribute('data-ctx-page'); }
      var seg = location.pathname.replace(/\/+$/, '').split('/').pop() || '';
      if (seg === '' || seg === 'index.php') { return 'Home'; }
      seg = seg.replace(/\.php$/, '').replace(/[-_]+/g, ' ');
      return seg.charAt(0).toUpperCase() + seg.slice(1);
    }

    function brandName() {
      var el = document.querySelector('.logo-text') || document.querySelector('.brand');
      var t = el ? el.textContent.trim() : '';
      return t || 'FatherGateway';
    }

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
        if (h.indexOf(needle) !== -1) { return links[i]; }
      }
      return null;
    }

    function makeClick(anchor) {
      return function () { if (anchor && anchor.isConnected) { anchor.click(); } };
    }

    // Grab the most specific readable text under the press point (for touch Copy)
    function pickContextText(el) {
      var node = el;
      var best = '';
      for (var i = 0; i < 4 && node && node.nodeType === 1 && node !== document.body; i++) {
        var txt = (node.textContent || '').replace(/\s+/g, ' ').trim();
        if (txt) {
          if (txt.length <= 120) { return txt; }
          best = txt;
        }
        node = node.parentElement;
      }
      return best.length <= 300 ? best : '';
    }

    function build(items) {
      menu.innerHTML = '';
      var head = document.createElement('div');
      head.className = 'fx-ctx-head';
      head.innerHTML = '<span class="fx-ctx-dot"></span>' +
        '<span class="fx-ctx-brand">' + escapeHtml(brandName()) + '</span>' +
        '<span class="fx-ctx-page">' + escapeHtml(pageLabel()) + '</span>';
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
        row.className = 'fx-ctx-item' + (it.danger ? ' is-danger' : '') + (it.disabled ? ' is-disabled' : '');
        row.setAttribute('role', 'menuitem');
        row.tabIndex = -1;
        row.innerHTML = '<span class="fx-ctx-ico">' + icon(it.icon) + '</span>' +
          '<span class="fx-ctx-label">' + it.label + '</span>' +
          (it.key ? '<span class="fx-ctx-key">' + it.key + '</span>' : '');
        if (!it.disabled) {
          row.addEventListener('click', function (ev) {
            ev.preventDefault(); ev.stopPropagation();
            hide();
            if (typeof it.action === 'function') { it.action(); }
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
        if (top + mh > vh - pad) { top = y - mh - 14; }
        left = x - 18;
      } else {
        left = x;
        top = y;
      }

      if (left + mw > vw - pad) { left = vw - mw - pad; }
      if (left < pad) { left = pad; }
      if (top + mh > vh - pad) { top = vh - mh - pad; }
      if (top < pad) { top = pad; }

      menu.style.left = left + 'px';
      menu.style.top = top + 'px';
      menu.__shownAt = Date.now();
      isOpen = true;
    }

    function hide() {
      if (!isOpen) { return; }
      isOpen = false;
      menu.classList.remove('is-open');
      menu.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('contextmenu', function (e) {
      var t = e.target;
      if (t && t.closest && t.closest('input, textarea, [contenteditable="true"], [data-native-menu], .allow-native-menu')) { return; }
      e.preventDefault();

      var link = (t && t.closest) ? t.closest('a[href]') : null;
      if (link && (!link.href || (link.getAttribute('href') || '').indexOf('javascript:') === 0)) { link = null; }

      var sel = window.getSelection ? String(window.getSelection()) : '';
      var items = [];
      var isTouch = window.matchMedia('(hover: none), (pointer: coarse)').matches || window.innerWidth <= 820;

      if (link) {
        items.push({ icon: 'open', label: 'Open Link', action: makeClick(link) });
        if (!isTouch) {
          items.push({ icon: 'newtab', label: 'Open in New Tab', action: (function (u) { return function () { window.open(u, '_blank', 'noopener'); }; })(link.href) });
        }
        items.push('-');
      }

      items.push({ icon: 'back', label: 'Back', action: function () { window.history.back(); } });
      items.push({ icon: 'reload', label: 'Reload', action: function () { window.location.reload(); } });
      items.push('-');

      if (sel && sel.trim()) {
        items.push({ icon: 'copy', label: 'Copy', action: function () { copyText(sel); } });
      }

      // Phone: long-press has no pre-selection, so let Copy grab the text under the finger
      var pickedText = '';
      if (isTouch && !(sel && sel.trim())) {
        pickedText = link ? '' : pickContextText(t);
        if (pickedText) {
          items.push({ icon: 'copy', label: 'Copy', action: (function (v) { return function () { copyText(v); }; })(pickedText) });
        }
      }

      if ((sel && sel.trim()) || link || !pickedText) {
        items.push({
          icon: 'copy',
          label: link ? 'Copy Link Address' : 'Copy Page Link',
          action: function () {
            var a = (t && t.closest) ? t.closest('a[href]') : null;
            copyText(a ? a.href : window.location.href);
          }
        });
      }

      // Section jumps stay desktop-only — on touch the menu stays short and native-feeling.
      if (!isTouch) {
        var navDefs = [
          [['index.php'], 'home', 'Home'],
          [['#features'], 'zap', 'Features'],
          [['#how-it-works'], 'activity', 'How it works'],
          [['#api'], 'code', 'API'],
          [['#pricing'], 'rupee', 'Pricing'],
          [['docs.php'], 'book', 'Documentation']
        ];
        var navItems = [];
        navDefs.forEach(function (d) {
          var a = null;
          for (var i = 0; i < d[0].length && !a; i++) { a = findAnchor(d[0][i]); }
          if (!a && d[0][0] === 'index.php') { a = document.querySelector('a.brand'); }
          if (a) { navItems.push({ icon: d[1], label: d[2], action: makeClick(a) }); }
        });
        if (navItems.length) {
          items.push('-');
          items = items.concat(navItems);
        }
      }

      var authDefs = [
        ['dashboard.php', 'dashboard', 'Dashboard'],
        ['login.php', 'login', 'Sign in'],
        ['register.php', 'register', 'Create account']
      ];
      var authItems = [];
      authDefs.forEach(function (d) {
        var a = findAnchor(d[0]);
        if (a) { authItems.push({ icon: d[1], label: d[2], action: makeClick(a) }); }
      });
      if (authItems.length) {
        items.push('-');
        items = items.concat(authItems);
      }

      show(e.clientX || 0, e.clientY || 0, items, isTouch);
    });

    document.addEventListener('click', function (e) {
      // Ignore the click fired right after a touch long-press reveals the menu
      if (isOpen && menu.__shownAt && Date.now() - menu.__shownAt < 350) { return; }
      if (isOpen && !menu.contains(e.target)) { hide(); }
    });

    document.addEventListener('scroll', function () { hide(); }, true);
    window.addEventListener('resize', function () { hide(); });
    window.addEventListener('blur', function () { hide(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen) { hide(); }
    });
  })();

  /* ---------- Cursor Glow ---------- */
  if (body.classList.contains('has-glow') && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    var glow = document.createElement('div');
    glow.className = 'cursor-glow-dot';
    document.body.appendChild(glow);

    var ring = document.createElement('div');
    ring.className = 'cursor-ring';
    document.body.appendChild(ring);

    var tx = window.innerWidth / 2, ty = window.innerHeight / 2, cx = tx, cy = ty;
    var rx = tx, ry = ty;
    window.addEventListener('mousemove', function (e) {
      tx = e.clientX; ty = e.clientY;
    });
    (function tick() {
      cx += (tx - cx) * 0.12; cy += (ty - cy) * 0.12;
      rx += (tx - rx) * 0.6; ry += (ty - ry) * 0.6;
      glow.style.transform = 'translate(' + (cx - 150) + 'px,' + (cy - 150) + 'px)';
      ring.style.transform = 'translate(' + (rx - 14) + 'px,' + (ry - 14) + 'px)';
      requestAnimationFrame(tick);
    })();
  }

  if (!body.classList.contains('landing')) { return; }

  var reduceMQ = window.matchMedia('(prefers-reduced-motion: reduce)');
  var reduced = reduceMQ.matches;
  if (reduced) { body.classList.add('reduced'); }

  var CDN = {
    gsap:  'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js',
    st:    'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js',
    three: 'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js'
  };

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src; s.async = false;
      s.onload = function () { resolve(src); };
      s.onerror = function () { reject(new Error('load failed: ' + src)); };
      document.head.appendChild(s);
    });
  }

  /* ---------------------------------------------------------
     Boot: load libs sequentially (order matters for GSAP+ST)
     --------------------------------------------------------- */
  loadScript(CDN.gsap)
    .then(function () { return loadScript(CDN.st); })
    .then(boot)
    .catch(function () { body.classList.add('no-gsap'); initStatic(); stepsExperience(); initThree(); });

  function boot() {
    initStatic();

    var gsap = window.gsap;
    var ScrollTrigger = window.ScrollTrigger;
    if (!gsap || !ScrollTrigger) { body.classList.add('no-gsap'); initThree(); return; }
    gsap.registerPlugin(ScrollTrigger);
    ScrollTrigger.config({ ignoreMobileResize: true });

    var setStepsProgress = stepsExperience();

    if (!reduced) {
      heroIntro(gsap);
      heroParallax(gsap);
      stepsPin(gsap, ScrollTrigger, setStepsProgress);
      initScrollAnimations(gsap, ScrollTrigger);
    }

    initThree();

    window.addEventListener('load', function () { ScrollTrigger.refresh(); });
    setTimeout(function () { ScrollTrigger.refresh(); }, 700);
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () { ScrollTrigger.refresh(); });
    }
  }


  /* ---------------------------------------------------------
     Animated Stat Counters
     --------------------------------------------------------- */
  function initStatCounters() {
    var statNums = document.querySelectorAll('.stat-num [data-count]');
    if (!statNums.length) { return; }

    var animated = false;
    function run() {
      if (animated) { return; }
      animated = true;
      statNums.forEach(function (el) {
        var target = parseFloat(el.getAttribute('data-count') || '0');
        var prefix = el.getAttribute('data-prefix') || '';
        var suffix = el.getAttribute('data-suffix') || '';
        var start = 0;
        var duration = 1600;
        var startTime = null;

        function step(timestamp) {
          if (!startTime) { startTime = timestamp; }
          var p = Math.min((timestamp - startTime) / duration, 1);
          var eased = p === 1 ? 1 : 1 - Math.pow(2, -10 * p);
          var current = Math.round(start + (target - start) * eased);
          el.textContent = prefix + current + suffix;
          if (p < 1) { requestAnimationFrame(step); }
        }
        requestAnimationFrame(step);
      });
    }

    var statsContainer = document.querySelector('.stats');
    if (statsContainer && 'IntersectionObserver' in window) {
      new IntersectionObserver(function (entries, observer) {
        if (entries[0].isIntersecting) {
          run();
          observer.disconnect();
        }
      }, { threshold: 0.2 }).observe(statsContainer);
    } else {
      setTimeout(run, 600);
    }
  }


  /* ---------------------------------------------------------
     Text Animation (Framer Motion style: word-by-word reveal)
     Ease: cubic-bezier(0.22, 1, 0.36, 1), duration 0.6s, stagger 0.05s
     --------------------------------------------------------- */
  function initTextAnimation() {
    var targets = document.querySelectorAll('.split-text-container, [data-text-animate]');
    if (!targets.length) { return; }

    targets.forEach(function (el) {
      if (!el.querySelector('.split-mask')) {
        var divide = el.getAttribute('data-text-animate') || 'word';
        var isLetter = divide === 'letter';
        var stagger = isLetter ? 0.02 : 0.05;
        var text = el.textContent.trim();
        var parts = isLetter ? text.split('') : text.split(/\s+/);
        el.innerHTML = '';
        parts.forEach(function (part, i) {
          var mask = document.createElement('span');
          mask.className = 'split-mask';
          var word = document.createElement('span');
          word.className = 'split-word';
          word.style.setProperty('--i', i);
          word.style.setProperty('--stagger', stagger + 's');
          word.textContent = isLetter ? (part === ' ' ? '\u00A0' : part) : (part + '\u00A0');
          mask.appendChild(word);
          el.appendChild(mask);
        });
      }

      if (el.classList.contains('hero-title')) {
        // Fallback timer ensures hero title is revealed even without GSAP
        setTimeout(function () {
          el.classList.add('split-revealed');
        }, 150);
        return;
      }

      if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              entry.target.classList.add('split-revealed');
              observer.unobserve(entry.target);
            }
          });
        }, { threshold: 0.15 });
        observer.observe(el);
      } else {
        el.classList.add('split-revealed');
      }
    });
  }

  /* DOM-only features (run even without GSAP) */
  function initStatic() {
    initTextAnimation();
    initStatCounters();
    initNavExtras();
    smoothAnchors();
    bentoSpotlight();
    stepsTilt();
    codeTabs();
    heroToast();
    backToTop();
  }

  /* ---------------------------------------------------------
     Smooth programmatic scroll (native, no hijacking)
     --------------------------------------------------------- */
  function animateScrollTo(targetY, dur) {
    targetY = Math.max(0, Math.round(targetY));
    if (reduced) { window.scrollTo(0, targetY); return; }
    dur = dur || 650;
    var startY = window.pageYOffset || document.documentElement.scrollTop || 0;
    var diff = targetY - startY;
    if (Math.abs(diff) < 2) { window.scrollTo(0, targetY); return; }
    var t0 = null;
    function step(ts) {
      if (t0 === null) { t0 = ts; }
      var p = Math.min((ts - t0) / dur, 1);
      var eased = p < 0.5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
      window.scrollTo(0, startY + diff * eased);
      if (p < 1) { requestAnimationFrame(step); }
    }
    requestAnimationFrame(step);
  }

  /* ---------------------------------------------------------
     Nav: burger, scrollspy, progress bar
     --------------------------------------------------------- */
  function initNavExtras() {
    var burger = document.getElementById('navBurger');
    var mobileNav = document.getElementById('mobileNav');
    var header = document.querySelector('.landing-nav');
    if (burger && mobileNav) {
      burger.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = mobileNav.classList.toggle('open');
        burger.classList.toggle('is-open', open);
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (header) { header.classList.toggle('nav-open', open); }
      });

      /* Close when clicking anywhere outside */
      document.addEventListener('click', function (e) {
        if (!mobileNav.classList.contains('open')) { return; }
        if (!mobileNav.contains(e.target) && !burger.contains(e.target)) {
          closeMobileNav();
        }
      });

      /* Close on Escape key */
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
          closeMobileNav();
        }
      });

      /* Close when clicking any link inside mobileNav */
      mobileNav.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
          closeMobileNav();
        });
      });
    }

    var links = Array.prototype.slice.call(document.querySelectorAll('.nav-links a[href^="#"]'));
    var targets = links.map(function (a) { return document.querySelector(a.getAttribute('href')); });

    function onScroll() {
      var y = window.pageYOffset || document.documentElement.scrollTop || 0;
      var active = -1;
      for (var i = 0; i < targets.length; i++) {
        var t = targets[i];
        if (t && t.getBoundingClientRect().top <= 150) { active = i; }
      }
      links.forEach(function (a, i) { a.classList.toggle('is-active', i === active); });
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
  }

  /* ---------------------------------------------------------
     Smooth anchor scrolling
     --------------------------------------------------------- */
  function smoothAnchors() {
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        var id = a.getAttribute('href');
        if (!id || id.length < 2) { return; }
        var el = document.querySelector(id);
        if (!el) { return; }
        e.preventDefault();
        var y = el.getBoundingClientRect().top + (window.pageYOffset || 0) - 86;
        animateScrollTo(y);
        closeMobileNav();
      });
    });
  }

  function closeMobileNav() {
    var mn = document.getElementById('mobileNav');
    var burger = document.getElementById('navBurger');
    var header = document.querySelector('.landing-nav');
    if (mn) { mn.classList.remove('open'); }
    if (header) { header.classList.remove('nav-open'); }
    if (burger) { burger.classList.remove('is-open'); burger.setAttribute('aria-expanded', 'false'); }
  }

  /* ---------------------------------------------------------
     Hero intro timeline
     --------------------------------------------------------- */
  function heroIntro(gsap) {
    try {
      var tl = gsap.timeline({
        defaults: { ease: 'power3.out', duration: 0.85 },
        onComplete: function () {
          var els = document.querySelectorAll('.hero-actions, .hero-actions .btn, .hero-actions a');
          for (var i = 0; i < els.length; i++) {
            els[i].style.opacity = '';
            els[i].style.transform = '';
            els[i].style.visibility = '';
          }
        }
      });
      tl.from('.hero .eyebrow', { y: 16, opacity: 0, clearProps: 'all' })
        .call(function () {
          var ht = document.querySelector('.hero-title');
          if (ht) { ht.classList.add('split-revealed'); }
        }, null, '-=0.45')
        .from('.hero-sub', { y: 18, opacity: 0, clearProps: 'all' }, '-=0.35')
        .from('.hero-actions > *', { y: 16, opacity: 0, stagger: 0.08, clearProps: 'all' }, '-=0.5')
        .from('.hero-stats .hero-stat', { y: 16, opacity: 0, stagger: 0.08, clearProps: 'all' }, '-=0.5');
    } catch (e) {
      var ht = document.querySelector('.hero-title');
      if (ht) { ht.classList.add('split-revealed'); }
      var els = document.querySelectorAll('.hero-actions, .hero-actions .btn, .hero-actions a');
      for (var j = 0; j < els.length; j++) {
        els[j].style.opacity = '1';
        els[j].style.visibility = 'visible';
      }
    }
  }

  /* ---------------------------------------------------------
     Hero parallax on scroll
     --------------------------------------------------------- */
  function heroParallax(gsap) {
    gsap.to('.hero-copy', { yPercent: -10, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
    gsap.to('.hero-stage', { yPercent: -18, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
    gsap.to('.hero-3d', { opacity: 0.15, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
  }

  /* ---------------------------------------------------------
     Steps progress + active-step highlight
     --------------------------------------------------------- */
  function stepsExperience() {
    var viewport = document.querySelector('.steps-viewport');
    var fill = document.getElementById('stepsProgressFill');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card'));
    if (!viewport || !cards.length) { return null; }

    var maxFade = fadeAmount();
    function fadeAmount() {
      return Math.min(72, Math.max(24, Math.round(viewport.clientWidth * 0.09)));
    }
    window.addEventListener('resize', function () { maxFade = fadeAmount(); }, { passive: true });

    function setProgress(p) {
      p = Math.max(0, Math.min(1, p));
      if (fill) { fill.style.width = (p * 100) + '%'; }
      var active = p < 0.34 ? 0 : (p < 0.67 ? 1 : 2);
      cards.forEach(function (c, i) { c.classList.toggle('is-active', i === active); });
      /* Off-screen cards/glow fade into the background at each edge.
         The left edge only fades once the first card slides past it, and
         the right edge fades while cards still remain to the right. */
      var ramp = 0.18;
      var l = Math.min(1, p / ramp);
      var r = Math.min(1, (1 - p) / ramp);
      l = l * l * (3 - 2 * l);
      r = r * r * (3 - 2 * r);
      viewport.style.setProperty('--fade-l', Math.round(l * maxFade) + 'px');
      viewport.style.setProperty('--fade-r', Math.round(r * maxFade) + 'px');
    }

    /* Native horizontal scroll drives progress on narrow screens. */
    viewport.addEventListener('scroll', function () {
      var max = viewport.scrollWidth - viewport.clientWidth;
      setProgress(max > 0 ? viewport.scrollLeft / max : 0);
    }, { passive: true });

    setProgress(0);
    return setProgress;
  }

  /* ---------------------------------------------------------
     Pinned horizontal steps (desktop only)
     --------------------------------------------------------- */
  function stepsPin(gsap, ScrollTrigger, setProgress) {
    var sec = document.querySelector('.steps-section');
    var viewport = document.querySelector('.steps-viewport');
    var track = document.querySelector('.steps-track');
    if (!sec || !viewport || !track) { return; }

    /* Narrow screens use native horizontal scroll instead of pinning. */
    if (window.innerWidth <= 1024) { return; }

    var dist = function () { return Math.max(0, track.scrollWidth - viewport.clientWidth); };
    if (dist() < 40) { return; }

    gsap.to(track, {
      x: function () { return -dist(); },
      ease: 'none',
      scrollTrigger: {
        trigger: sec,
        start: 'top top',
        end: function () { return '+=' + Math.round(dist() * 1.8 + 320); },
        scrub: 1,
        pin: true,
        anticipatePin: 1,
        invalidateOnRefresh: true,
        onUpdate: function (self) { if (setProgress) { setProgress(self.progress); } }
      }
    });
  }

  /* ---------------------------------------------------------
     Card & Section reveal on scroll (ScrollTrigger animations)
     --------------------------------------------------------- */
  function initScrollAnimations(gsap, ScrollTrigger) {
    // Section headers reveal
    gsap.utils.toArray('.sec-head, .steps-head').forEach(function (head) {
      gsap.from(head.children, {
        scrollTrigger: {
          trigger: head,
          start: 'top 86%',
          once: true
        },
        y: 28,
        opacity: 0,
        stagger: 0.1,
        duration: 0.75,
        ease: 'power3.out',
        clearProps: 'transform,opacity'
      });
    });

    // API Code Block & Point cards
    var codeBlock = document.getElementById('codeBlock');
    if (codeBlock) {
      gsap.from(codeBlock, {
        scrollTrigger: { trigger: codeBlock, start: 'top 85%', once: true },
        y: 36,
        opacity: 0,
        scale: 0.96,
        duration: 0.85,
        ease: 'power3.out',
        clearProps: 'transform,opacity'
      });
    }

    gsap.utils.toArray('.api-point').forEach(function (pt, i) {
      gsap.from(pt, {
        scrollTrigger: { trigger: pt, start: 'top 88%', once: true },
        x: 30,
        opacity: 0,
        duration: 0.65,
        delay: i * 0.07,
        ease: 'power2.out',
        clearProps: 'transform,opacity'
      });
    });

    // CTA panel scale and glow
    var ctaPanel = document.querySelector('.cta-panel');
    if (ctaPanel) {
      gsap.from(ctaPanel, {
        scrollTrigger: { trigger: ctaPanel, start: 'top 85%', once: true },
        y: 40,
        scale: 0.94,
        opacity: 0,
        duration: 0.85,
        ease: 'power3.out',
        clearProps: 'transform,opacity'
      });
    }
  }

  /* ---------------------------------------------------------
     Bento spotlight (cursor-follow glow)
     --------------------------------------------------------- */
  function bentoSpotlight() {
    var cards = document.querySelectorAll('.bento-card');
    if (!cards.length) { return; }
    cards.forEach(function (card) {
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        card.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100) + '%');
        card.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100) + '%');
      });
    });
  }

  /* ---------------------------------------------------------
     Subtle 3D tilt on step cards (fine-pointer only)
     --------------------------------------------------------- */
  function stepsTilt() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card'));
    if (!cards.length) { return; }
    if (reduced || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) { return; }
    cards.forEach(function (card) {
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        var dx = (e.clientX - r.left) / r.width - 0.5;
        var dy = (e.clientY - r.top) / r.height - 0.5;
        card.style.setProperty('--ry', (dx * 8).toFixed(2) + 'deg');
        card.style.setProperty('--rx', (-dy * 8).toFixed(2) + 'deg');
      });
      card.addEventListener('mouseleave', function () {
        card.style.setProperty('--ry', '0deg');
        card.style.setProperty('--rx', '0deg');
      });
    });
  }

  /* ---------------------------------------------------------
     Interactive API code tabs + line reveal
     --------------------------------------------------------- */
  function codeTabs() {
    var block = document.getElementById('codeBlock');
    if (!block) { return; }
    var panes = Array.prototype.slice.call(block.querySelectorAll('.code-pane'));
    var tabs = Array.prototype.slice.call(block.querySelectorAll('.code-tab'));
    var copyBtn = block.querySelector('.code-copy');
    if (!panes.length) { return; }

    panes.forEach(function (pane) {
      var raw = pane.innerHTML.replace(/^\s*\n/, '').replace(/\n\s*$/, '');
      pane.innerHTML = raw.split('\n').map(function (line) {
        var blank = line.replace(/^\s+|\s+$/g, '') === '';
        return '<span class="cl">' + (blank ? '&nbsp;' : line) + '</span>';
      }).join('');
    });

    function activePane() { return block.querySelector('.code-pane.is-active') || panes[0]; }

    function replay(pane) {
      var cls = pane.querySelectorAll('.cl');
      Array.prototype.forEach.call(cls, function (cl) { cl.classList.remove('cl-in'); });
      void pane.offsetWidth;
      Array.prototype.forEach.call(cls, function (cl, i) {
        cl.style.transitionDelay = (i * 40) + 'ms';
        cl.classList.add('cl-in');
      });
    }

    function setCopy(pane) {
      if (copyBtn) { copyBtn.setAttribute('data-copy', pane.textContent.replace(/\u00a0/g, '').trim()); }
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        var key = tab.getAttribute('data-tab');
        panes.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-pane') === key); });
        var pane = activePane();
        setCopy(pane);
        replay(pane);
      });
    });

    setCopy(activePane());

    var started = false;
    function start() { if (started) { return; } started = true; replay(activePane()); }
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting) { start(); io.disconnect(); }
      }, { threshold: 0.3 });
      io.observe(block);
    } else { start(); }
  }

  /* ---------------------------------------------------------
     Live activity toast in the hero
     --------------------------------------------------------- */
  function heroToast() {
    var toast = document.getElementById('heroToast');
    if (!toast) { return; }
    var cur = toast.getAttribute('data-cur') || '';
    var icEl = toast.querySelector('.hero-toast-ic');
    var tEl = toast.querySelector('.hero-toast-t');
    var sEl = toast.querySelector('.hero-toast-s');

    var V = 'xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    var I = {
      check:  '<svg ' + V + '><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      send:   '<svg ' + V + '><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
      key:    '<svg ' + V + '><path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
      shield: '<svg ' + V + '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>'
    };
    var items = [
      { ic: I.check,  t: 'Payment received',  s: cur + '1,250.00 \u00b7 just now',      bg: 'rgba(34,197,94,0.14)',  fg: '#4ade80' },
      { ic: I.send,   t: 'Webhook delivered', s: 'payment.success \u00b7 200 OK',         bg: 'rgba(59,130,246,0.16)', fg: '#60a5fa' },
      { ic: I.key,    t: 'Order created',     s: 'fg_9F2C81B4',                            bg: 'rgba(167,139,250,0.16)', fg: '#a78bfa' },
      { ic: I.shield, t: 'UTR verified',      s: '415922837103',                          bg: 'rgba(34,197,94,0.14)',  fg: '#4ade80' }
    ];

    function apply(item) {
      icEl.innerHTML = item.ic;
      icEl.style.background = item.bg;
      icEl.style.color = item.fg;
      tEl.textContent = item.t;
      sEl.textContent = item.s;
    }

    setTimeout(function () { toast.classList.add('in'); }, 900);

    var i = 0;
    setInterval(function () {
      toast.classList.add('swap');
      setTimeout(function () {
        i = (i + 1) % items.length;
        apply(items[i]);
        toast.classList.remove('swap');
      }, 320);
    }, 3200);
  }

  /* ---------------------------------------------------------
     Back-to-top button
     --------------------------------------------------------- */
  function backToTop() {
    var btn = document.createElement('button');
    btn.className = 'to-top';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Back to top');
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>';
    document.body.appendChild(btn);

    btn.addEventListener('click', function () { animateScrollTo(0, 700); });

    function onScroll() {
      var y = window.pageYOffset || document.documentElement.scrollTop || 0;
      btn.classList.toggle('show', y > 700);
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ---------------------------------------------------------
     Three.js interactive hero
     --------------------------------------------------------- */
  function initThree() {
    if (reduced) { return; }
    var canvas = document.getElementById('hero3d');
    if (!canvas) { return; }
    try { if (!document.createElement('canvas').getContext('webgl')) { return; } } catch (e) { return; }

    import(CDN.three).then(function (THREE) {
      buildScene(THREE, canvas);
    }).catch(function () { /* silent - hero bg still looks fine */ });
  }

  function buildScene(THREE, canvas) {
    var host = canvas.parentElement;
    var small = window.innerWidth < 900;
    var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: !small });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, small ? 1.5 : 2));

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100);
    camera.position.set(0, 0, 6);

    var group = new THREE.Group();
    scene.add(group);

    var core = new THREE.LineSegments(
      new THREE.EdgesGeometry(new THREE.IcosahedronGeometry(1.9, 1)),
      new THREE.LineBasicMaterial({ color: 0x3b82f6, transparent: true, opacity: 0.40 })
    );
    group.add(core);

    var inner = new THREE.LineSegments(
      new THREE.EdgesGeometry(new THREE.IcosahedronGeometry(1.15, 0)),
      new THREE.LineBasicMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.32 })
    );
    group.add(inner);

    var N = small ? 480 : 900;
    var pos = new Float32Array(N * 3);
    for (var i = 0; i < N; i++) {
      var r = 2.6 + Math.random() * 1.5;
      var th = Math.random() * Math.PI * 2;
      var ph = Math.acos(2 * Math.random() - 1);
      pos[i * 3]     = r * Math.sin(ph) * Math.cos(th);
      pos[i * 3 + 1] = r * Math.sin(ph) * Math.sin(th);
      pos[i * 3 + 2] = r * Math.cos(ph);
    }
    var pgeo = new THREE.BufferGeometry();
    pgeo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    var points = new THREE.Points(pgeo, new THREE.PointsMaterial({
      color: 0x7dd3fc, size: 0.035, transparent: true, opacity: 0.75,
      blending: THREE.AdditiveBlending, depthWrite: false
    }));
    group.add(points);

    function updatePosition() {
      var w = host.clientWidth || window.innerWidth;
      var h = host.clientHeight || window.innerHeight;
      var isSmall = w < 900;
      var aspect = w / h;

      var vFOV = (camera.fov * Math.PI) / 180;
      var visibleHeight = 2 * Math.tan(vFOV / 2) * camera.position.z;
      var visibleWidth = visibleHeight * aspect;

      if (isSmall) {
        // Mobile layout: Exactly matches user reference screenshot
        // Center sits just off the right edge (x ~ 1.25 - 1.35, y ~ 0.20),
        // with scale 1 so its left curved wireframe mesh spans across
        // behind the headline, subtitle, buttons, and stats.
        var targetX = Math.max(1.25, (visibleWidth / 2) + 0.18);
        var targetY = 0.20;
        group.position.set(targetX, targetY, 0);
        group.scale.setScalar(1);
      } else {
        // Desktop / PC: Original placement on the right behind checkout card
        group.position.set(1.5, 0.15, 0);
        group.scale.setScalar(1);
      }
    }

    updatePosition();

    var pointer = { x: 0, y: 0 }, target = { x: 0, y: 0 };
    window.addEventListener('mousemove', function (e) {
      pointer.x = (e.clientX / window.innerWidth) * 2 - 1;
      pointer.y = (e.clientY / window.innerHeight) * 2 - 1;
    }, { passive: true });

    window.addEventListener('touchmove', function (e) {
      if (e.touches && e.touches[0]) {
        pointer.x = (e.touches[0].clientX / window.innerWidth) * 2 - 1;
        pointer.y = (e.touches[0].clientY / window.innerHeight) * 2 - 1;
      }
    }, { passive: true });

    function resize() {
      var w = host.clientWidth || window.innerWidth;
      var h = host.clientHeight || window.innerHeight;
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      updatePosition();
    }
    resize();
    window.addEventListener('resize', resize);

    var visible = true;
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (en) { visible = en[0].isIntersecting; }, { threshold: 0 }).observe(host);
    }

    var clock = new THREE.Clock();
    (function loop() {
      requestAnimationFrame(loop);
      if (!visible) { return; }
      var t = clock.getElapsedTime();
      target.x += (pointer.x - target.x) * 0.05;
      target.y += (pointer.y - target.y) * 0.05;

      var scrollY = window.pageYOffset || 0;
      var scrollParallax = Math.min(scrollY * 0.0007, 0.35);

      group.position.y = (small ? 0.20 : 0.15) - scrollParallax;
      var breathe = 1 + Math.sin(t * 1.5) * 0.025;
      group.scale.setScalar(breathe);

      group.rotation.y = t * 0.18 + target.x * 0.6;
      group.rotation.x = target.y * 0.4 + scrollParallax * 0.4;
      points.rotation.y = -t * 0.06;
      points.rotation.x = Math.sin(t * 0.3) * 0.1;
      inner.rotation.z = t * 0.25;
      inner.rotation.x = Math.sin(t * 0.5) * 0.18;
      renderer.render(scene, camera);
    })();
  }
})();
