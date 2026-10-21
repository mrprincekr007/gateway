/* =========================================================
   FatherGateway — Webhooks Page JS
   100% Pure Client-Side Instant Filtering (Exact Dashboard Match)
   ZERO Page Refresh, ZERO DOM Flickering, ZERO Card Cascade,
   Liquid Jelly Glider stays permanently active & smooth.
   ========================================================= */
(function () {
  'use strict';

  var webhooksTabsBound = false;

  function switchWebhooksTab(tabKey, pushHistory) {
    if (!tabKey) tabKey = 'endpoints';

    // 1. Update tab navigation pills
    document.querySelectorAll('[data-wh-tab]').forEach(function (el) {
      if (el.getAttribute('data-wh-tab') === tabKey) {
        el.classList.add('active', 'is-active', 'active-all');
      } else {
        el.classList.remove('active', 'is-active', 'active-all');
      }
    });

    // 2. Toggle panels
    var epPanel = document.getElementById('whPanelEndpoints');
    var logsPanel = document.getElementById('whPanelLogs');
    var filterStrip = document.getElementById('whLogsFilterStrip');

    if (epPanel) {
      if (tabKey === 'endpoints') {
        epPanel.style.display = '';
        epPanel.classList.add('is-active');
      } else {
        epPanel.style.display = 'none';
        epPanel.classList.remove('is-active');
      }
    }

    if (logsPanel) {
      if (tabKey === 'logs') {
        logsPanel.style.display = '';
        logsPanel.classList.add('is-active');
      } else {
        logsPanel.style.display = 'none';
        logsPanel.classList.remove('is-active');
      }
    }

    if (filterStrip) {
      filterStrip.style.display = (tabKey === 'logs') ? '' : 'none';
    }

    // 3. Update URL parameter smoothly without reload
    if (pushHistory !== false) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tabKey);
        history.replaceState({ whTab: tabKey }, '', url.toString());
      } catch (e) {}
    }

    var tabsNav = document.getElementById('whStatusTabs');
    var activeBtn = tabsNav ? tabsNav.querySelector('[data-wh-tab="' + tabKey + '"]') : null;
    if (tabsNav && activeBtn && typeof window.syncLiquidGlider === 'function') {
      window.syncLiquidGlider(tabsNav, activeBtn, false);
    } else if (typeof window.initUniversalLiquidTabs === 'function') {
      window.initUniversalLiquidTabs();
    }
  }
  window.switchWebhooksTab = switchWebhooksTab;

  function filterWebhookLogs(filterKey) {
    if (!filterKey) filterKey = 'all';

    // Update filter pill styles
    document.querySelectorAll('[data-wh-log-filter]').forEach(function (pill) {
      if (pill.getAttribute('data-wh-log-filter') === filterKey) {
        pill.classList.add('is-active', 'active');
      } else {
        pill.classList.remove('is-active', 'active');
      }
    });

    var filterStrip = document.getElementById('whLogsFilterStrip');
    var activePill = filterStrip ? filterStrip.querySelector('[data-wh-log-filter="' + filterKey + '"]') : null;
    if (filterStrip && activePill && typeof window.syncLiquidGlider === 'function') {
      window.syncLiquidGlider(filterStrip, activePill, false);
    } else if (typeof window.initUniversalLiquidTabs === 'function') {
      window.initUniversalLiquidTabs();
    }

    var rows = document.querySelectorAll('.wh-log-row');
    var cards = document.querySelectorAll('.wh-log-item');
    var visibleCount = 0;

    rows.forEach(function (r) {
      var st = r.getAttribute('data-log-status');
      if (filterKey === 'all' || st === filterKey) {
        r.style.display = '';
        visibleCount++;
      } else {
        r.style.display = 'none';
      }
    });

    cards.forEach(function (c) {
      var st = c.getAttribute('data-log-status');
      if (filterKey === 'all' || st === filterKey) {
        c.style.display = '';
      } else {
        c.style.display = 'none';
      }
    });

    var emptyNotice = document.getElementById('whLogEmptyFilterNotice');
    var tableWrap = document.querySelector('#whPanelLogs .tx-desktop-table-wrap');
    var mobileList = document.getElementById('whLogMobileList');

    if (emptyNotice) {
      if (visibleCount === 0 && (rows.length > 0 || cards.length > 0)) {
        emptyNotice.style.display = 'block';
        if (tableWrap) tableWrap.style.display = 'none';
        if (mobileList) mobileList.style.display = 'none';
      } else {
        emptyNotice.style.display = 'none';
        if (tableWrap) tableWrap.style.display = '';
        if (mobileList) mobileList.style.display = '';
      }
    }
  }
  window.filterWebhookLogs = filterWebhookLogs;

  function initWebhooksTabs() {
    if (webhooksTabsBound) return;
    webhooksTabsBound = true;

    document.addEventListener('click', function (e) {
      var tabBtn = e.target.closest('[data-wh-tab]');
      if (tabBtn) {
        e.preventDefault();
        var tabKey = tabBtn.getAttribute('data-wh-tab');
        switchWebhooksTab(tabKey, true);
        return;
      }

      var filterBtn = e.target.closest('[data-wh-log-filter]');
      if (filterBtn) {
        e.preventDefault();
        var fKey = filterBtn.getAttribute('data-wh-log-filter');
        filterWebhookLogs(fKey);
        return;
      }
    });

    window.addEventListener('popstate', function () {
      if (window.location.pathname.indexOf('webhooks') !== -1) {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab') || 'endpoints';
        switchWebhooksTab(tab, false);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWebhooksTabs);
  } else {
    initWebhooksTabs();
  }
  window.addEventListener('turbo:load', initWebhooksTabs);
  window.addEventListener('page:loaded', initWebhooksTabs);
})();
