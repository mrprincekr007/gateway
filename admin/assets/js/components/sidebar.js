/* =========================================================
   FatherGateway — Admin Sidebar (sidebar.js)
   Mobile drawer open/close and close-on-navigate behaviour.
   ========================================================= */

(function () {
  'use strict';

  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');

  function setToggleState(open) {
    document.querySelectorAll('.topbar-mobile-toggle').forEach(function (btn) {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    // aria-hidden only applies while the sidebar behaves as a mobile drawer;
    // on desktop it is always visible so it must stay exposed to ATs.
    if (sidebar) {
      if (window.innerWidth <= 960) {
        sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
      } else {
        sidebar.removeAttribute('aria-hidden');
      }
    }
  }

  window.openSidebar = function () {
    if (sidebar) { sidebar.classList.add('open'); }
    if (overlay && window.innerWidth <= 960) { overlay.classList.add('show'); }
    document.body.classList.remove('sidebar-collapsed');
    if (window.innerWidth <= 960) {
      document.body.style.overflow = 'hidden';
    }
    setToggleState(true);
  };

  window.closeSidebar = function () {
    if (sidebar) { sidebar.classList.remove('open'); }
    if (overlay) { overlay.classList.remove('show'); }
    document.body.style.overflow = '';
    if (window.innerWidth > 960) {
      document.body.classList.add('sidebar-collapsed');
    }
    setToggleState(false);
  };

  if (sidebar) {
    sidebar.querySelectorAll('.nav-item').forEach(function (item) {
      item.addEventListener('click', function () {
        if (window.innerWidth <= 960) {
          window.closeSidebar();
        }
      });
    });
  }

  function syncSidebarA11y() {
    if (!sidebar) { return; }
    if (window.innerWidth <= 960) {
      sidebar.setAttribute('aria-hidden', sidebar.classList.contains('open') ? 'false' : 'true');
    } else {
      sidebar.removeAttribute('aria-hidden');
    }
  }
  window.addEventListener('resize', syncSidebarA11y, { passive: true });
  syncSidebarA11y();

  /* Keep the sidebar's internal scroll position across full-page navigations */
  var navScroll = sidebar ? sidebar.querySelector('.sidebar-nav') : null;
  var SCROLL_KEY = 'admin_sidebar_nav_scroll';

  if (navScroll) {
    var savedScroll = 0;
    try { savedScroll = parseInt(localStorage.getItem(SCROLL_KEY) || '0', 10) || 0; } catch (e) { savedScroll = 0; }

    if (savedScroll > 0) {
      navScroll.style.scrollBehavior = 'auto';
      navScroll.scrollTop = savedScroll;
      if (navScroll.scrollTop !== savedScroll) { navScroll.scrollTop = savedScroll; }
      requestAnimationFrame(function () {
        navScroll.scrollTop = savedScroll;
        navScroll.style.scrollBehavior = '';
      });
    }

    var saveScrollTimer = null;
    navScroll.addEventListener('scroll', function () {
      if (saveScrollTimer) { clearTimeout(saveScrollTimer); }
      saveScrollTimer = setTimeout(function () {
        try { localStorage.setItem(SCROLL_KEY, String(Math.round(navScroll.scrollTop))); } catch (e) {}
      }, 80);
    }, { passive: true });
  }
})();
