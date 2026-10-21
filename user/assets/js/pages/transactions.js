/* =========================================================
   FatherGateway — Transactions Page JS
   100% Pure Client-Side Instant Filtering (Exact Dashboard Match)
   ZERO Page Refresh, ZERO DOM Flickering, ZERO Card Cascade,
   Liquid Jelly Glider stays permanently active & smooth.
   ========================================================= */

(function () {
  'use strict';

  var isTxUpdating = false;

  // 1. Get currently selected status key
  function getActiveStatusKey() {
    var activeBtn = document.querySelector('.tx-status-tabs .tx-status-tab.active');
    if (!activeBtn) return '';
    return (activeBtn.getAttribute('data-status-filter') || '').trim().toLowerCase();
  }

  // 2. Fast Instant In-DOM Filter (0ms latency, exactly like Dashboard)
  function instantFilterTable(statusKey) {
    var filterKey = (statusKey !== undefined ? statusKey : getActiveStatusKey()).trim().toLowerCase();
    var rows = document.querySelectorAll('.tx-table-row');
    var cards = document.querySelectorAll('.tx-card-mobile');

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
    var existingEmptyRow = document.getElementById('txInstantEmptyRow');
    if (tbody && rows.length > 0) {
      if (visibleRows === 0) {
        if (!existingEmptyRow) {
          var tr = document.createElement('tr');
          tr.id = 'txInstantEmptyRow';
          tr.innerHTML = '<td colspan="7" style="text-align:center;padding:36px 16px;color:#64748b;font-size:13px;font-weight:500;">No ' + (filterKey ? filterKey.toUpperCase() : '') + ' transactions found in this view.</td>';
          tbody.appendChild(tr);
        } else {
          existingEmptyRow.style.display = '';
          existingEmptyRow.querySelector('td').textContent = 'No ' + (filterKey ? filterKey.toUpperCase() : '') + ' transactions found in this view.';
        }
      } else if (existingEmptyRow) {
        existingEmptyRow.style.display = 'none';
      }
    }

    var mobileList = document.querySelector('.tx-mobile-list');
    var existingMobileEmpty = document.getElementById('txInstantMobileEmpty');
    if (mobileList && cards.length > 0) {
      if (visibleCards === 0) {
        if (!existingMobileEmpty) {
          var div = document.createElement('div');
          div.id = 'txInstantMobileEmpty';
          div.style.cssText = 'text-align:center;padding:32px 16px;color:#64748b;font-size:13px;font-weight:500;background:rgba(241,245,249,0.5);border-radius:12px;margin:8px 0;';
          div.textContent = 'No ' + (filterKey ? filterKey.toUpperCase() : '') + ' transactions found in this view.';
          mobileList.appendChild(div);
        } else {
          existingMobileEmpty.style.display = '';
          existingMobileEmpty.textContent = 'No ' + (filterKey ? filterKey.toUpperCase() : '') + ' transactions found in this view.';
        }
      } else if (existingMobileEmpty) {
        existingMobileEmpty.style.display = 'none';
      }
    }
  }

  // 3. Silent In-Place AJAX Sync (For Horizon / Search / Pagination ONLY — NEVER touches KPI or Filter cards!)
  function updateTxTable(targetUrl) {
    if (!targetUrl || isTxUpdating) return;

    var tableWrap = document.querySelector('.tx-desktop-table-wrap');
    var mobileList = document.querySelector('.tx-mobile-list');
    var presCard = document.getElementById('txPresentationCard');

    if (!presCard) return;

    isTxUpdating = true;

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

        var newTableWrap = doc.querySelector('.tx-desktop-table-wrap');
        var newMobileList = doc.querySelector('.tx-mobile-list');
        var newEmptyState = doc.querySelector('.empty-state');
        var newPagination = doc.querySelector('.tx-pagination-footer, .tx-pagination-wrap, .pagination');

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

        // Swap pagination if present
        var curPagination = presCard.querySelector('.tx-pagination-footer, .tx-pagination-wrap, .pagination');
        if (newPagination && curPagination) {
          curPagination.innerHTML = newPagination.innerHTML;
        }

        // Update URL quietly without triggering Turbo or popstate cascade
        try {
          history.replaceState(null, '', targetUrl);
        } catch (e) {}

        // Re-apply client-side instant status filter on the newly loaded rows
        instantFilterTable();
      })
      .catch(function (err) {
        console.warn('Silent table sync:', err.message);
      })
      .finally(function () {
        isTxUpdating = false;
      });
  }
  window.updateTxTable = updateTxTable;

  function initTransactionsEvents() {
    var filterCard = document.getElementById('txFilterCard');
    if (!filterCard) return;

    // Remove old listener flag to avoid duplicate registration
    if (filterCard.getAttribute('data-tx-events-bound') === 'true') return;
    filterCard.setAttribute('data-tx-events-bound', 'true');

    // 1. Status Filter Buttons (All, Captured, Pending, Expired, Failed)
    var statusTabsContainer = document.getElementById('txStatusTabs');
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
        if (statusKey) {
          statusBtn.classList.add('active-' + statusKey);
        } else {
          statusBtn.classList.add('active-all');
        }

        // 100% Instant In-DOM Filter (0ms delay, zero network, zero refresh, exact Dashboard behavior)
        instantFilterTable(statusKey);

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

    // 2. Horizon Date Pills (All Time, Today, Yesterday, 7 Days, 30 Days, This Month)
    var horizonContainer = document.getElementById('txHorizonTabs');
    if (horizonContainer) {
      horizonContainer.addEventListener('click', function (e) {
        var horizonBtn = e.target.closest('.tx-horizon-pill');
        if (!horizonBtn) return;

        e.preventDefault();

        var targetUrl = horizonBtn.getAttribute('data-url') || '';

        var allHorizonBtns = horizonContainer.querySelectorAll('.tx-horizon-pill');
        allHorizonBtns.forEach(function (b) {
          b.classList.remove('active', 'is-active');
        });
        horizonBtn.classList.add('active', 'is-active');

        // Fluid Jelly Glider Motion
        if (typeof window.syncLiquidGlider === 'function') {
          window.syncLiquidGlider(horizonContainer, horizonBtn, false);
        }

        // Silent background fetch (only table rows, never touches KPI cards or filter card)
        if (targetUrl) {
          updateTxTable(targetUrl);
        }
      });
    }

    // 3. Search Form Submission without page reload
    var searchForm = filterCard.querySelector('.tx-search-form');
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
        params.set('page', '1');
        var targetUrl = window.location.pathname + '?' + params.toString();
        updateTxTable(targetUrl);
      });
    }

    // 4. Per-Page buttons click (10, 25, 50, 100) without page reload
    var perPageBtns = filterCard.querySelector('#txPerPageButtons');
    if (perPageBtns) {
      perPageBtns.addEventListener('click', function (e) {
        var pill = e.target.closest('.tx-perpage-pill');
        if (!pill) return;
        e.preventDefault();

        var targetUrl = pill.getAttribute('data-url') || '';
        if (!targetUrl) return;

        perPageBtns.querySelectorAll('.tx-perpage-pill').forEach(function (b) {
          b.classList.remove('active');
        });
        pill.classList.add('active');

        // Update hidden form inputs if present
        var hiddenPerPage = filterCard.querySelector('input[name="per_page"]');
        if (hiddenPerPage) {
          hiddenPerPage.value = pill.getAttribute('data-perpage') || '25';
        }

        updateTxTable(targetUrl);
      });
    }

    // Fallback: Per-Page dropdown change without page reload
    var perPageSelect = filterCard.querySelector('.tx-perpage-select');
    if (perPageSelect) {
      perPageSelect.addEventListener('change', function () {
        if (this.value) {
          updateTxTable(this.value);
        }
      });
    }

    // Initial instant filter sync on page load
    instantFilterTable();
  }

  // Self-execute immediately
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTransactionsEvents);
  } else {
    initTransactionsEvents();
  }

  window.addEventListener('turbo:load', initTransactionsEvents);
  window.addEventListener('page:loaded', initTransactionsEvents);
})();
