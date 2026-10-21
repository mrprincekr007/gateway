/* =========================================================
   FatherGateway — Payment Links Page JS
   100% Pure Client-Side Instant Filtering (Exact Dashboard Match)
   ZERO Page Refresh, ZERO DOM Flickering, ZERO Card Cascade,
   Liquid Jelly Glider stays permanently active & smooth.
   ========================================================= */

(function () {
  'use strict';

  var isPlUpdating = false;

  // 1. Get currently selected status key ('', 'active', 'inactive')
  function getActiveStatusKey() {
    var activeBtn = document.querySelector('#plStatusTabs .tx-status-tab.active');
    if (!activeBtn) return '';
    return (activeBtn.getAttribute('data-status-filter') || '').trim().toLowerCase();
  }

  // 2. Fast Instant In-DOM Filter (0ms latency, exactly like Dashboard)
  function instantFilterLinks(statusKey) {
    var filterKey = (statusKey !== undefined ? statusKey : getActiveStatusKey()).trim().toLowerCase();
    var rows = document.querySelectorAll('.pl-table-row');
    var cards = document.querySelectorAll('.pl-card-mobile');

    var visibleRows = 0;
    rows.forEach(function (r) {
      var rowStatus = (r.getAttribute('data-status') || '').trim().toLowerCase();
      if (!filterKey || rowStatus === filterKey) {
        r.style.display = '';
        visibleRows++;
      } else {
        r.style.display = 'none';
      }
    });

    var visibleCards = 0;
    cards.forEach(function (c) {
      var cardStatus = (c.getAttribute('data-status') || '').trim().toLowerCase();
      if (!filterKey || cardStatus === filterKey) {
        c.style.display = '';
        visibleCards++;
      } else {
        c.style.display = 'none';
      }
    });

    // Handle empty state gracefully if 0 records match
    var tbody = document.querySelector('.tx-table tbody');
    var existingEmptyRow = document.getElementById('plInstantEmptyRow');
    if (tbody && rows.length > 0) {
      if (visibleRows === 0) {
        if (!existingEmptyRow) {
          var tr = document.createElement('tr');
          tr.id = 'plInstantEmptyRow';
          var label = filterKey === 'active' ? 'Active Live' : (filterKey === 'inactive' ? 'Inactive / Expired' : '');
          tr.innerHTML = '<td colspan="6" style="text-align:center;padding:36px 16px;color:#64748b;font-size:13px;font-weight:500;">No ' + label + ' links found in this view.</td>';
          tbody.appendChild(tr);
        } else {
          existingEmptyRow.style.display = '';
          var label = filterKey === 'active' ? 'Active Live' : (filterKey === 'inactive' ? 'Inactive / Expired' : '');
          existingEmptyRow.querySelector('td').textContent = 'No ' + label + ' links found in this view.';
        }
      } else if (existingEmptyRow) {
        existingEmptyRow.style.display = 'none';
      }
    }

    var mobileList = document.querySelector('.pl-mobile-list');
    var existingMobileEmpty = document.getElementById('plInstantMobileEmpty');
    if (mobileList && cards.length > 0) {
      if (visibleCards === 0) {
        if (!existingMobileEmpty) {
          var div = document.createElement('div');
          div.id = 'plInstantMobileEmpty';
          div.style.cssText = 'text-align:center;padding:32px 16px;color:#64748b;font-size:13px;font-weight:500;background:rgba(241,245,249,0.5);border-radius:12px;margin:8px 0;';
          var label = filterKey === 'active' ? 'Active Live' : (filterKey === 'inactive' ? 'Inactive / Expired' : '');
          div.textContent = 'No ' + label + ' links found in this view.';
          mobileList.appendChild(div);
        } else {
          existingMobileEmpty.style.display = '';
          var label = filterKey === 'active' ? 'Active Live' : (filterKey === 'inactive' ? 'Inactive / Expired' : '');
          existingMobileEmpty.textContent = 'No ' + label + ' links found in this view.';
        }
      } else if (existingMobileEmpty) {
        existingMobileEmpty.style.display = 'none';
      }
    }
  }

  // 3. Silent In-Place AJAX Sync (For Search ONLY — NEVER touches Filter card or KPIs!)
  function updatePlTable(targetUrl) {
    if (!targetUrl || isPlUpdating) return;

    var tableWrap = document.querySelector('.pl-desktop-table-wrap');
    var mobileList = document.querySelector('.pl-mobile-list');
    var presCard = document.getElementById('plPresentationCard');

    if (!presCard) return;

    isPlUpdating = true;

    fetch(targetUrl, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function (html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');

        var newTableWrap = doc.querySelector('.pl-desktop-table-wrap');
        var newMobileList = doc.querySelector('.pl-mobile-list');
        var newEmptyState = doc.querySelector('.empty-state');

        // Swap table wrap
        if (newTableWrap && tableWrap) {
          tableWrap.innerHTML = newTableWrap.innerHTML;
        } else if (newEmptyState && presCard) {
          presCard.innerHTML = newEmptyState.outerHTML;
        }

        // Swap mobile list
        if (newMobileList && mobileList) {
          mobileList.innerHTML = newMobileList.innerHTML;
        }

        // Update URL quietly without triggering Turbo or popstate cascade
        try {
          history.replaceState(null, '', targetUrl);
        } catch (e) {}

        // Re-apply client-side instant status filter on the newly loaded rows
        instantFilterLinks();
      })
      .catch(function (err) {
        console.warn('Silent links sync:', err.message);
      })
      .finally(function () {
        isPlUpdating = false;
      });
  }
  window.updatePlTable = updatePlTable;

  function initPaymentLinksEvents() {
    var filterCard = document.getElementById('plFilterCard');
    if (!filterCard) return;

    if (filterCard.getAttribute('data-pl-events-bound') === 'true') return;
    filterCard.setAttribute('data-pl-events-bound', 'true');

    // 1. Status Filter Buttons (All Links, Active Live, Inactive / Expired)
    var statusTabsContainer = document.getElementById('plStatusTabs');
    if (statusTabsContainer) {
      statusTabsContainer.addEventListener('click', function (e) {
        var statusBtn = e.target.closest('.tx-status-tab');
        if (!statusBtn) return;

        e.preventDefault();

        var statusKey = (statusBtn.getAttribute('data-status-filter') || '').trim();
        var targetUrl = statusBtn.getAttribute('data-url') || '';

        // Update active class on siblings
        var allStatusBtns = statusTabsContainer.querySelectorAll('.tx-status-tab');
        allStatusBtns.forEach(function (b) {
          b.classList.remove('active', 'is-active', 'active-all', 'active-success', 'active-pending', 'active-expired', 'active-failed');
        });
        statusBtn.classList.add('active');
        if (statusKey === 'active') {
          statusBtn.classList.add('active-success');
        } else if (statusKey === 'inactive') {
          statusBtn.classList.add('active-expired');
        } else {
          statusBtn.classList.add('active-all');
        }

        // 100% Instant In-DOM Filter (0ms delay, zero network, zero refresh, exact Dashboard behavior)
        instantFilterLinks(statusKey);

        // Fluid Jelly Glider Motion
        if (typeof window.syncLiquidGlider === 'function') {
          window.syncLiquidGlider(statusTabsContainer, statusBtn, false);
        }

        // Update browser URL silently without page refresh or Turbo navigation
        if (targetUrl) {
          try {
            history.replaceState(null, '', targetUrl);
          } catch (err) {}
        }
      });
    }

    // 2. Search Form Submission without page reload
    var searchForm = filterCard.querySelector('.pl-search-form');
    if (searchForm) {
      searchForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var formData = new FormData(searchForm);
        var params = new URLSearchParams(window.location.search);
        formData.forEach(function (val, key) {
          if (val !== '') {
            params.set(key, val);
          } else {
            params.delete(key);
          }
        });
        var targetUrl = window.location.pathname + '?' + params.toString();
        updatePlTable(targetUrl);
      });
    }

    // Initial instant filter sync on page load
    instantFilterLinks();
  }

  // Self-execute immediately
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPaymentLinksEvents);
  } else {
    initPaymentLinksEvents();
  }

  window.addEventListener('turbo:load', initPaymentLinksEvents);
  window.addEventListener('page:loaded', initPaymentLinksEvents);
})();
