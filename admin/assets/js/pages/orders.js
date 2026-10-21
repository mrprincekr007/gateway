function openReconcileModal(id, code, amt) {
    document.getElementById('orders-reconcile-id').value = id;
    document.getElementById('orders-reconcile-info').innerHTML = 'Settling order: <strong>' + code + '</strong> for <strong>' + amt + '</strong>';
    openAdminModal('modal-orders-reconcile');
}

/* =========================================================
   Transactions Monitor — Infinite Scroll
   Streams the next page of transactions as the admin scrolls
   the ledger to the bottom (mirrors the merchants directory).
   ========================================================= */
(function () {
  'use strict';

  var observer = null;
  var loading = false;
  var failed = false;

  function qs(id) {
    return document.getElementById(id);
  }

  function parseIntAttr(el, name, fallback) {
    var v = parseInt(el.getAttribute(name) || '', 10);
    return isNaN(v) ? fallback : v;
  }

  function updateCount(bar, tbody) {
    var countEl = qs('ordersLoadCount');
    if (!countEl) { return; }
    var loaded = tbody.querySelectorAll('tr').length;
    var total = bar.getAttribute('data-total-records') || '0';
    countEl.innerHTML = 'Showing <strong>' + loaded + '</strong> of <strong>' + total + '</strong> transactions';
  }

  function markEnd(bar) {
    bar.classList.add('is-end');
    var btn = qs('ordersLoadMore');
    if (btn) { btn.style.display = 'none'; }
    if (observer) { observer.disconnect(); observer = null; }
  }

  function refreshState(bar) {
    var nextPage = parseIntAttr(bar, 'data-next-page', 1);
    var totalPages = parseIntAttr(bar, 'data-total-pages', 1);
    if (nextPage > totalPages) { markEnd(bar); }
  }

  function loadNextPage(bar, tbody) {
    if (loading || failed) { return; }

    var nextPage = parseIntAttr(bar, 'data-next-page', 1);
    var totalPages = parseIntAttr(bar, 'data-total-pages', 1);
    if (nextPage > totalPages) { markEnd(bar); return; }

    var base = bar.getAttribute('data-load-url');
    if (!base) { return; }

    var params = new URLSearchParams();
    params.set('page', String(nextPage));
    params.set('per_page', bar.getAttribute('data-per-page') || '25');

    var current = new URLSearchParams(window.location.search);
    ['q', 'status', 'merchant'].forEach(function (key) {
      var val = current.get(key);
      if (val) { params.set(key, val); }
    });

    loading = true;
    bar.classList.add('is-loading');
    bar.classList.remove('has-error');

    fetch(base + (base.indexOf('?') === -1 ? '?' : '&') + params.toString(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        var ct = res.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) { throw new Error('Unexpected response'); }
        return res.json();
      })
      .then(function (data) {
        if (!data || data.ok !== true) { throw new Error('Bad payload'); }

        if (data.html) {
          tbody.insertAdjacentHTML('beforeend', data.html);
        }

        var currentPage = parseInt(data.page, 10) || nextPage;
        var totalPagesResp = parseInt(data.total_pages, 10) || totalPages;

        bar.setAttribute('data-next-page', String(currentPage + 1));
        bar.setAttribute('data-total-pages', String(totalPagesResp));
        if (data.total_records != null) {
          bar.setAttribute('data-total-records', String(data.total_records));
        }

        updateCount(bar, tbody);
        loading = false;
        bar.classList.remove('is-loading');

        if (!data.has_more || currentPage >= totalPagesResp) {
          markEnd(bar);
        } else {
          refreshState(bar);
          maybeContinue(bar, tbody);
        }
      })
      .catch(function () {
        loading = false;
        failed = true;
        bar.classList.remove('is-loading');
        bar.classList.add('has-error');
      });
  }

  function maybeContinue(bar, tbody) {
    if (loading || failed) { return; }
    var rect = bar.getBoundingClientRect();
    var vh = window.innerHeight || document.documentElement.clientHeight || 0;
    if (rect.top <= vh + 400) {
      loadNextPage(bar, tbody);
    }
  }

  function initOrdersInfiniteScroll() {
    var bar = qs('ordersLoadBar');
    var tbody = qs('ordersTbody');
    if (!bar || !tbody) { return; }

    if (observer) { observer.disconnect(); observer = null; }
    loading = false;
    failed = false;

    var moreBtn = qs('ordersLoadMore');
    if (moreBtn) {
      moreBtn.addEventListener('click', function () {
        failed = false;
        bar.classList.remove('has-error');
        loadNextPage(bar, tbody);
      });
    }

    refreshState(bar);

    if ('IntersectionObserver' in window) {
      observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { loadNextPage(bar, tbody); }
        });
      }, { root: null, rootMargin: '500px 0px', threshold: 0 });
      observer.observe(bar);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOrdersInfiniteScroll);
  } else {
    initOrdersInfiniteScroll();
  }
})();
