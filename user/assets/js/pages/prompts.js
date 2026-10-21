/* =========================================================
   FatherGateway — AI Builder (Prompts) Page JS
   Prompt tab switcher engine (data-pmt-tab / data-pmt-sec)
   ========================================================= */
(function () {
  'use strict';

  var pmtSectionsMeta = {
    'overview': { title: 'Overview',        prev: null,    next: 'html' },
    'html':     { title: 'HTML + CSS + JS', prev: 'overview', next: 'php' },
    'php':      { title: 'PHP + MySQL',     prev: 'html',  next: 'node' },
    'node':     { title: 'Node.js',         prev: 'php',   next: 'app' },
    'app':      { title: 'Mobile App',      prev: 'node',  next: 'telegram' },
    'telegram': { title: 'Telegram Bot',    prev: 'app',   next: null }
  };

  var PMT_PREV_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform:rotate(180deg);"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>';
  var PMT_NEXT_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>';

  function switchPmtTab(tabKey, pushHistory) {
    if (!tabKey || !pmtSectionsMeta[tabKey]) return;

    // 1. Sidebar + mobile pill active state
    document.querySelectorAll('[data-pmt-tab]').forEach(function (el) {
      if (el.classList.contains('docs-pager-btn')) return;
      if (el.getAttribute('data-pmt-tab') === tabKey) {
        el.classList.add('is-active');
      } else {
        el.classList.remove('is-active');
      }
    });

    // 2. Swap visible panel
    document.querySelectorAll('.docs-section-panel').forEach(function (p) {
      if (p.getAttribute('data-pmt-sec') === tabKey) {
        p.classList.remove('is-active');
        void p.offsetWidth;
        p.classList.add('is-active');
      } else {
        p.classList.remove('is-active');
      }
    });

    // 3. Rebuild pager
    var meta = pmtSectionsMeta[tabKey];
    var prevHolder = document.getElementById('pmtPagerPrevHolder');
    var nextHolder = document.getElementById('pmtPagerNextHolder');

    if (prevHolder) {
      if (meta.prev && pmtSectionsMeta[meta.prev]) {
        prevHolder.innerHTML = '<a href="?tab=' + meta.prev + '" data-pmt-tab="' + meta.prev + '" class="docs-pager-btn" id="pmtPagerPrevBtn">' +
          PMT_PREV_SVG + '<span id="pmtPagerPrevTitle">' + pmtSectionsMeta[meta.prev].title + '</span></a>';
      } else {
        prevHolder.innerHTML = '';
      }
    }

    if (nextHolder) {
      if (meta.next && pmtSectionsMeta[meta.next]) {
        nextHolder.innerHTML = '<a href="?tab=' + meta.next + '" data-pmt-tab="' + meta.next + '" class="docs-pager-btn primary" id="pmtPagerNextBtn">' +
          '<span id="pmtPagerNextTitle">' + pmtSectionsMeta[meta.next].title + '</span>' + PMT_NEXT_SVG + '</a>';
      } else {
        nextHolder.innerHTML = '';
      }
    }

    // 4. Title + URL
    if (meta.title) {
      try { document.title = meta.title + ' · AI Integration Guide'; } catch (e) {}
    }

    if (pushHistory !== false) {
      try {
        history.pushState({ pmtTab: tabKey }, '', '?tab=' + encodeURIComponent(tabKey));
      } catch (e) {}
    }

    var activePill = document.querySelector('.docs-mobile-pill[data-pmt-tab="' + tabKey + '"]');
    if (activePill && typeof activePill.scrollIntoView === 'function') {
      try { activePill.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' }); } catch (err) {}
    }

    var layout = document.querySelector('.docs-layout-container');
    if (layout && window.scrollY > layout.offsetTop + 40) {
      layout.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  window.switchPmtTab = switchPmtTab;

  var pmtTabsBound = false;
  function initPmtTabs() {
    if (pmtTabsBound) return;
    pmtTabsBound = true;

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-pmt-tab]');
      if (!btn) return;
      var tabKey = btn.getAttribute('data-pmt-tab');
      if (tabKey) {
        e.preventDefault();
        switchPmtTab(tabKey, true);
      }
    });

    window.addEventListener('popstate', function () {
      if (window.location.pathname.indexOf('prompts.php') !== -1) {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab') || 'overview';
        switchPmtTab(tab, false);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', initPmtTabs);
  window.addEventListener('turbo:load', initPmtTabs);
  window.addEventListener('page:loaded', initPmtTabs);
})();
