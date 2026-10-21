/* =========================================================
   FatherGateway — Documentation Page JS
   Docs Sidebar Navigation, Language Tabs & Payload Switcher
   ========================================================= */
(function () {
  'use strict';

  // ---------- Documentation Tab Switcher Engine ----------
  var docsSectionsMeta = {
    'overview':       { title: 'Overview', prev: null, next: 'quickstart' },
    'quickstart':     { title: 'Quickstart', prev: 'overview', next: 'authentication' },
    'authentication': { title: 'Authentication', prev: 'quickstart', next: 'orders' },
    'orders':         { title: 'Create Order', prev: 'authentication', next: 'check' },
    'check':          { title: 'Check Status', prev: 'orders', next: 'webhooks' },
    'webhooks':       { title: 'Webhooks', prev: 'check', next: 'events' },
    'events':         { title: 'Events', prev: 'webhooks', next: 'errors' },
    'errors':         { title: 'Errors', prev: 'events', next: null }
  };

  function switchDocsTab(tabKey, pushHistory) {
    if (!tabKey || !docsSectionsMeta[tabKey]) return;

    // 1. Update left docs nav item active state
    document.querySelectorAll('[data-docs-tab]').forEach(function (el) {
      if (el.classList.contains('docs-pager-btn')) return; // skip pager buttons
      if (el.getAttribute('data-docs-tab') === tabKey) {
        el.classList.add('is-active');
      } else {
        el.classList.remove('is-active');
      }
    });

    // 2. Switch documentation article panels on the right side
    var panels = document.querySelectorAll('.docs-section-panel');
    panels.forEach(function (p) {
      if (p.getAttribute('data-docs-sec') === tabKey) {
        p.classList.remove('is-active');
        void p.offsetWidth;
        p.classList.add('is-active');
      } else {
        p.classList.remove('is-active');
      }
    });

    // 3. Update Pager Prev / Next buttons dynamically
    var meta = docsSectionsMeta[tabKey];
    var prevHolder = document.getElementById('docsPagerPrevHolder');
    var nextHolder = document.getElementById('docsPagerNextHolder');

    if (prevHolder) {
      if (meta.prev && docsSectionsMeta[meta.prev]) {
        prevHolder.innerHTML = '<a href="?page=' + meta.prev + '" data-docs-tab="' + meta.prev + '" class="docs-pager-btn" id="docsPagerPrevBtn">' +
          '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform:rotate(180deg);"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>' +
          '<span id="docsPagerPrevTitle">' + docsSectionsMeta[meta.prev].title + '</span></a>';
      } else {
        prevHolder.innerHTML = '';
      }
    }

    if (nextHolder) {
      if (meta.next && docsSectionsMeta[meta.next]) {
        nextHolder.innerHTML = '<a href="?page=' + meta.next + '" data-docs-tab="' + meta.next + '" class="docs-pager-btn primary" id="docsPagerNextBtn">' +
          '<span id="docsPagerNextTitle">' + docsSectionsMeta[meta.next].title + '</span>' +
          '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>';
      } else {
        nextHolder.innerHTML = '';
      }
    }

    // 4. Update document title and URL without page reload
    if (meta && meta.title) {
      try { document.title = meta.title + ' · API Documentation'; } catch (e) {}
    }

    if (pushHistory !== false) {
      try {
        history.pushState({ docsTab: tabKey }, '', '?page=' + encodeURIComponent(tabKey));
      } catch (e) {}
    }

    // Auto-scroll active mobile pill into view if needed
    var activePill = document.querySelector('.docs-mobile-pill[data-docs-tab="' + tabKey + '"]');
    if (activePill && typeof activePill.scrollIntoView === 'function') {
      try {
        activePill.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      } catch (err) {}
    }

    // 5. Scroll smoothly to top of documentation content if scrolled down
    var layout = document.querySelector('.docs-layout-container');
    if (layout && window.scrollY > layout.offsetTop + 40) {
      layout.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  window.switchDocsTab = switchDocsTab;

  var docsTabsBound = false;
  function initDocsTabs() {
    if (docsTabsBound) return;
    docsTabsBound = true;

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-docs-tab]');
      if (!btn) return;
      var tabKey = btn.getAttribute('data-docs-tab');
      if (tabKey) {
        e.preventDefault();
        switchDocsTab(tabKey, true);
      }
    });

    window.addEventListener('popstate', function () {
      if (window.location.pathname.indexOf('docs.php') !== -1) {
        var params = new URLSearchParams(window.location.search);
        var page = params.get('page') || 'overview';
        switchDocsTab(page, false);
      }
    });
  }


  document.addEventListener('DOMContentLoaded', function () {
    if (typeof initDocsTabs === 'function') initDocsTabs();
  });
  window.addEventListener('turbo:load', function () {
    if (typeof initDocsTabs === 'function') initDocsTabs();
  });
  window.addEventListener('page:loaded', function () {
    if (typeof initDocsTabs === 'function') initDocsTabs();
  });
})();
