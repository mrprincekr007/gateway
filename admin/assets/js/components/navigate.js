/* =========================================================
   FatherGateway — Admin SPA Navigation (navigate.js)
   SPA-style navigation for the Admin Panel. Only the page
   content area (.wrap) is swapped; the sidebar and topbar
   DOM nodes are never re-created, so the sidebar keeps its
   scroll position and never "refreshes" on page change.
   ========================================================= */

(function () {
  'use strict';

  var isNavigating = false;

  /* ---------- Link eligibility ---------- */
  function isEligibleLink(anchor, e) {
    if (!anchor || !anchor.href) return false;
    if (anchor.target && anchor.target !== '_self') return false;
    if (anchor.hasAttribute('download')) return false;
    if (anchor.hasAttribute('data-no-spa')) return false;
    if (e && (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0)) return false;

    var url;
    try { url = new URL(anchor.href, window.location.origin); } catch (err) { return false; }

    // same origin + admin panel only
    if (url.origin !== window.location.origin) return false;
    if (url.pathname.indexOf('/admin/') === -1) return false;

    // never intercept logout, exports, downloads or action links
    if (url.pathname.indexOf('logout.php') !== -1) return false;
    if (url.search.indexOf('export=') !== -1) return false;
    if (url.search.indexOf('download') !== -1) return false;
    if (url.search.indexOf('action=') !== -1) return false;

    // same exact page (incl. hash) → let the browser handle it
    if (url.pathname === window.location.pathname && url.search === window.location.search) return false;

    return true;
  }

  /* ---------- Keep the persistent sidebar active state in sync ---------- */
  function applySidebarActive(doc, url) {
    var activePath = null;
    var activeEl = doc.querySelector('#sidebar .nav-item.active');
    if (activeEl && activeEl.href) {
      try { activePath = new URL(activeEl.href, window.location.origin).pathname.replace(/\/+$/, ''); } catch (err) {}
    }
    if (!activePath) {
      try { activePath = new URL(url, window.location.origin).pathname.replace(/\/+$/, ''); } catch (err) {}
    }
    document.querySelectorAll('#sidebar .nav-item').forEach(function (item) {
      var itemPath = '';
      try { itemPath = new URL(item.href, window.location.origin).pathname.replace(/\/+$/, ''); } catch (err) {}
      item.classList.toggle('active', itemPath !== '' && itemPath === activePath);
    });
  }

  /* ---------- Swap the dedicated page stylesheet (no FOUC) ---------- */
  function ensurePageCss(doc, done) {
    var target = '';
    doc.querySelectorAll('link[rel="stylesheet"]').forEach(function (l) {
      var h = l.getAttribute('href') || '';
      if (h.indexOf('/assets/css/pages/') !== -1) target = h;
    });

    var existing = document.getElementById('adminPageCss');
    var existingKey = existing ? (existing.getAttribute('href') || '').split('?')[0] : '';
    var targetKey = target ? target.split('?')[0] : '';
    if (existingKey === targetKey) { done(); return; }

    if (!target) {
      if (existing) { existing.parentNode.removeChild(existing); }
      done();
      return;
    }

    var staged = document.createElement('link');
    staged.rel = 'stylesheet';
    staged.href = target;
    var fired = false;
    var finish = function () {
      if (fired) { return; }
      fired = true;
      var old = document.getElementById('adminPageCss');
      if (old && old !== staged) { old.parentNode.removeChild(old); }
      staged.id = 'adminPageCss';
      done();
    };
    staged.onload = finish;
    staged.onerror = finish;
    document.head.appendChild(staged);
    setTimeout(finish, 800);
  }

  /* ---------- Swap the dedicated page script ---------- */
  function syncPageScripts(doc) {
    document.querySelectorAll('script[src*="/assets/js/pages/"]').forEach(function (s) {
      s.parentNode.removeChild(s);
    });
    doc.querySelectorAll('script[src*="/assets/js/pages/"]').forEach(function (s) {
      var src = s.getAttribute('src');
      if (!src) { return; }
      var ns = document.createElement('script');
      ns.src = src;
      ns.id = 'adminPageScript';
      document.body.appendChild(ns);
    });
  }

  /* ---------- Execute inline scripts that came with the new content ---------- */
  function runInlineScripts(container) {
    if (!container) { return; }
    container.querySelectorAll('script').forEach(function (s) {
      if (s.src) { return; }
      var ns = document.createElement('script');
      ns.textContent = s.textContent;
      document.body.appendChild(ns);
      ns.remove();
    });
  }

  /* ---------- Clear transient UI state left over from the old page ---------- */
  function resetTransientUI() {
    // release any scroll lock applied by a modal / drawer
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';

    // collapse the mobile sidebar + overlay
    var sidebar = document.getElementById('sidebar');
    if (sidebar) { sidebar.classList.remove('open'); }
    var overlay = document.getElementById('sidebarOverlay');
    if (overlay) { overlay.classList.remove('show'); }

    // close the topbar profile dropdown
    var dropdown = document.getElementById('topbarUserDropdown');
    if (dropdown) { dropdown.classList.remove('is-open'); }

    // close any open admin modals / drawers
    document.querySelectorAll('.admin-modal-overlay.show, .modal-overlay.show, .drawer.open')
      .forEach(function (node) { node.classList.remove('show', 'open'); });
  }

  /* ---------- Render the fetched page into the current shell ---------- */
  function renderPage(html, url, pushState, onComplete) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var newMain = doc.querySelector('.main-content');
    var currentMain = document.querySelector('.main-content');

    if (!newMain || !currentMain) { window.location.href = url; return; }

    resetTransientUI();

    var newTitle = doc.querySelector('title');
    if (newTitle) { document.title = newTitle.textContent; }

    var currentTopbar = currentMain.querySelector('.app-topbar-goll');
    var newTopbar = newMain.querySelector('.app-topbar-goll');
    var currentWrap = currentMain.querySelector('.wrap');
    var newWrap = newMain.querySelector('.wrap');

    ensurePageCss(doc, function () {
      // 1. Update topbar text (breadcrumb + mobile heading) WITHOUT re-rendering it
      if (currentTopbar && newTopbar) {
        var currentCrumb = currentTopbar.querySelector('.topbar-crumb-current');
        var newCrumb = newTopbar.querySelector('.topbar-crumb-current');
        if (currentCrumb && newCrumb) { currentCrumb.textContent = newCrumb.textContent; }

        var currentMobileHeading = currentTopbar.querySelector('.topbar-mobile-heading');
        var newMobileHeading = newTopbar.querySelector('.topbar-mobile-heading');
        if (currentMobileHeading && newMobileHeading) { currentMobileHeading.textContent = newMobileHeading.textContent; }
      }

      // 2. Swap ONLY the .wrap content — sidebar + topbar are 100% untouched
      if (currentWrap && newWrap) {
        currentWrap.innerHTML = newWrap.innerHTML;
      } else {
        currentMain.innerHTML = newMain.innerHTML;
      }

      runInlineScripts(currentWrap || currentMain);
      syncPageScripts(doc);
      applySidebarActive(doc, url);

      if (pushState !== false) {
        window.history.pushState({ adminSpa: true, url: url }, '', url);
      }

      window.scrollTo(0, 0);

      if (window.AdminRuntime && typeof window.AdminRuntime.refresh === 'function') {
        window.AdminRuntime.refresh();
      }

      if (typeof onComplete === 'function') { onComplete(); }
    });
  }

  /* ---------- Navigate ---------- */
  function navigateTo(url, pushState) {
    if (isNavigating) { return; }
    isNavigating = true;

    if (window.innerWidth <= 960 && typeof window.closeSidebar === 'function') {
      window.closeSidebar();
    }

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (res) {
        if (res.redirected) { window.location.href = res.url; return null; }
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.text();
      })
      .then(function (html) {
        if (!html) { isNavigating = false; return; }
        renderPage(html, url, pushState, function () { isNavigating = false; });
      })
      .catch(function () {
        isNavigating = false;
        window.location.href = url;
      });
  }

  window.navigateTo = navigateTo;

  /* ---------- Click interceptor ---------- */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a || !isEligibleLink(a, e)) { return; }
    e.preventDefault();
    if (isNavigating) { return; }
    navigateTo(a.href, true);
  });

  /* ---------- Browser back / forward ---------- */
  window.addEventListener('popstate', function (e) {
    if (e.state && e.state.adminSpa) {
      navigateTo(window.location.href, false);
    }
  });

  /* ---------- Prevent scroll drift on reload ---------- */
  if ('scrollRestoration' in window.history) {
    try { window.history.scrollRestoration = 'manual'; } catch (e) {}
  }
  if (window.history && window.history.replaceState) {
    window.history.replaceState({ adminSpa: true, url: window.location.href }, '', window.location.href);
  }
})();
