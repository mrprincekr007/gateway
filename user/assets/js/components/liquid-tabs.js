/* =========================================================
   FatherGateway — Universal Liquid Jelly Glider Engine
   Supports: Dashboard, Transactions, Payment Links,
   Webhooks, Profile, and any custom [data-liquid-tabs] strip.
   Mobile Centering, SPA / Turbo Navigation, & Dynamic Resizing.
   ========================================================= */

(function () {
  'use strict';

  function findActiveTab(container) {
    if (!container) return null;
    var tabs = Array.prototype.slice.call(
      container.querySelectorAll('.tx-tab-btn, .tx-status-tab, .tx-horizon-pill, .profile-tab-btn, [data-liquid-tabs] > a, [data-liquid-tabs] > button')
    );
    tabs = tabs.filter(function (t) {
      return t.closest('.tx-filter-tabs, .tx-status-tabs, .tx-horizon-pills, .profile-tabs-nav, [data-liquid-tabs]') === container;
    });
    if (!tabs.length) return null;

    for (var i = 0; i < tabs.length; i++) {
      var t = tabs[i];
      if (
        t.classList.contains('active') ||
        t.classList.contains('is-active') ||
        t.classList.contains('active-all') ||
        t.classList.contains('active-success') ||
        t.classList.contains('active-pending') ||
        t.classList.contains('active-expired') ||
        t.classList.contains('active-failed')
      ) {
        return t;
      }
    }
    return tabs[0];
  }

  function syncLiquidGlider(container, targetTab, isImmediate) {
    if (!container) return;

    // Ensure relative positioning
    var cs = window.getComputedStyle(container);
    if (cs.position === 'static') {
      container.style.position = 'relative';
    }

    // Ensure glider exists
    var glider = container.querySelector('.tx-liquid-glider');
    if (!glider) {
      glider = document.createElement('div');
      glider.className = 'tx-liquid-glider';
      glider.innerHTML = '<span class="tx-glider-wave"></span>';
      container.insertBefore(glider, container.firstChild);
    }

    if (!targetTab) {
      targetTab = findActiveTab(container);
    }
    if (!targetTab) return;

    var targetX = targetTab.offsetLeft;
    var targetY = targetTab.offsetTop;
    var targetW = targetTab.offsetWidth;
    var targetH = targetTab.offsetHeight;

    // If dimensions are not yet available (e.g. initial DOM render or hidden container), retry shortly
    if (!targetW || !targetH) {
      if (!container._gliderRetryCount || container._gliderRetryCount < 5) {
        container._gliderRetryCount = (container._gliderRetryCount || 0) + 1;
        requestAnimationFrame(function () {
          syncLiquidGlider(container, targetTab, isImmediate);
        });
      }
      return;
    }
    container._gliderRetryCount = 0;

    var currentX = container._currentGliderX !== undefined ? container._currentGliderX : targetX;
    var deltaX = targetX - currentX;
    var distance = Math.abs(deltaX);

    // Apply jelly elasticity if moving between tabs
    if (!isImmediate && distance > 6) {
      glider.classList.remove('jelly-stretching-right', 'jelly-stretching-left');
      if (deltaX > 0) {
        glider.classList.add('jelly-stretching-right');
      } else {
        glider.classList.add('jelly-stretching-left');
      }

      clearTimeout(container._stretchTimer);
      container._stretchTimer = setTimeout(function () {
        glider.classList.remove('jelly-stretching-right', 'jelly-stretching-left');
      }, 380);
    }

    container._currentGliderX = targetX;

    if (isImmediate) {
      var oldTrans = glider.style.transition;
      glider.style.transition = 'none';
      glider.style.transform = 'translate3d(' + targetX + 'px, ' + targetY + 'px, 0)';
      glider.style.width = targetW + 'px';
      glider.style.height = targetH + 'px';
      glider.classList.add('is-active');
      glider.offsetHeight; // Force reflow
      glider.style.transition = oldTrans;
    } else {
      glider.style.transform = 'translate3d(' + targetX + 'px, ' + targetY + 'px, 0)';
      glider.style.width = targetW + 'px';
      glider.style.height = targetH + 'px';
      glider.classList.add('is-active');
    }

    // Auto-scroll horizontal centering on mobile screens
    if (container.scrollWidth > container.clientWidth) {
      var centerOffset = targetX - (container.clientWidth / 2) + (targetW / 2);
      if (typeof container.scrollTo === 'function') {
        container.scrollTo({ left: Math.max(0, centerOffset), behavior: isImmediate ? 'auto' : 'smooth' });
      } else {
        container.scrollLeft = Math.max(0, centerOffset);
      }
    }
  }

  function initSingleLiquidTabContainer(container) {
    if (!container) return;

    // Ensure relative positioning
    var cs = window.getComputedStyle(container);
    if (cs.position === 'static') {
      container.style.position = 'relative';
    }

    // Ensure glider exists
    var glider = container.querySelector('.tx-liquid-glider');
    if (!glider) {
      glider = document.createElement('div');
      glider.className = 'tx-liquid-glider';
      glider.innerHTML = '<span class="tx-glider-wave"></span>';
      container.insertBefore(glider, container.firstChild);
    }

    // Attach event delegation on container (only once)
    if (!container.getAttribute('data-liquid-bound')) {
      container.setAttribute('data-liquid-bound', 'true');

      container.addEventListener('click', function (e) {
        var tab = e.target.closest('.tx-tab-btn, .tx-status-tab, .tx-horizon-pill, .profile-tab-btn, [data-liquid-tabs] > a, [data-liquid-tabs] > button');
        if (!tab || tab.closest('.tx-filter-tabs, .tx-status-tabs, .tx-horizon-pills, .profile-tabs-nav, [data-liquid-tabs]') !== container) {
          return;
        }

        // 1. Organic Jelly Pop Bounce
        tab.classList.remove('jelly-pop');
        void tab.offsetWidth;
        tab.classList.add('jelly-pop');

        // 2. Liquid Water Ripple
        var rect = tab.getBoundingClientRect();
        var ripple = document.createElement('span');
        var size = Math.max(rect.width, rect.height);
        ripple.className = 'tx-liquid-ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        var cx = (e.clientX !== undefined && e.clientX !== 0) ? e.clientX : (rect.left + rect.width / 2);
        var cy = (e.clientY !== undefined && e.clientY !== 0) ? e.clientY : (rect.top + rect.height / 2);
        ripple.style.left = (cx - rect.left - size / 2) + 'px';
        ripple.style.top = (cy - rect.top - size / 2) + 'px';
        tab.appendChild(ripple);
        setTimeout(function () { ripple.remove(); }, 560);

        // 3. Fluid Jelly Glider Motion
        syncLiquidGlider(container, tab, false);
      });
    }

    // Initial position sync (with RAF and delayed fallbacks for layout calculation)
    var initialTab = findActiveTab(container);
    if (initialTab) {
      syncLiquidGlider(container, initialTab, true);
    }
    requestAnimationFrame(function () {
      var t = findActiveTab(container);
      if (t) syncLiquidGlider(container, t, true);
    });
    setTimeout(function () {
      var t = findActiveTab(container);
      if (t) syncLiquidGlider(container, t, true);
    }, 60);
    setTimeout(function () {
      var t = findActiveTab(container);
      if (t) syncLiquidGlider(container, t, true);
    }, 240);
  }

  window.syncLiquidGlider = syncLiquidGlider;

  window.initUniversalLiquidTabs = function () {
    var selectors = [
      '[data-liquid-tabs]',
      '.tx-filter-tabs',
      '.tx-status-tabs',
      '.tx-horizon-pills',
      '.profile-tabs-nav'
    ];
    var containers = document.querySelectorAll(selectors.join(', '));
    containers.forEach(function (el) {
      initSingleLiquidTabContainer(el);
    });
  };

  // Lifecycle listeners
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      window.initUniversalLiquidTabs();
    });
  } else {
    window.initUniversalLiquidTabs();
  }

  // Turbo / SPA navigation lifecycle hooks
  window.addEventListener('turbo:load', function () {
    window.initUniversalLiquidTabs();
  });
  window.addEventListener('page:loaded', function () {
    window.initUniversalLiquidTabs();
  });
  window.addEventListener('popstate', function () {
    window.initUniversalLiquidTabs();
  });

  // Responsive re-sync
  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      window.initUniversalLiquidTabs();
    }, 80);
  }, { passive: true });
})();
