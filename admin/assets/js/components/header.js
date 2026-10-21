/* =========================================================
   FatherGateway — Admin Header / Topbar (header.js)
   Profile dropdown, scroll state and ⌘K omni-search focus.
   ========================================================= */

(function () {
  'use strict';

  function init() {
    var userBtn = document.getElementById('topbarUserBtn');
    var userDropdown = document.getElementById('topbarUserDropdown');

    function closeAllDropdowns() {
      if (userDropdown) userDropdown.classList.remove('is-open');
      if (userBtn) { userBtn.setAttribute('aria-expanded', 'false'); }
    }

    function toggleDropdown() {
      var isOpen = userDropdown.classList.contains('is-open');
      closeAllDropdowns();
      if (!isOpen) {
        userDropdown.classList.add('is-open');
        userBtn.setAttribute('aria-expanded', 'true');
      }
    }

    if (userBtn && userDropdown) {
      userBtn.onclick = function (e) {
        e.stopPropagation();
        toggleDropdown();
      };
      userBtn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggleDropdown();
        }
      });
    }

    document.addEventListener('click', function (e) {
      if (!e.target.closest('#topbarUserBtn') && !e.target.closest('#topbarUserDropdown')) {
        closeAllDropdowns();
      }
    });

    // Header Scroll State
    var topbar = document.querySelector('.app-topbar-goll');
    if (topbar) {
      var checkScroll = function () {
        if (window.scrollY > 15) {
          topbar.classList.add('is-scrolled');
        } else {
          topbar.classList.remove('is-scrolled');
        }
      };
      window.addEventListener('scroll', checkScroll, { passive: true });
      checkScroll();
    }

    // ⌘K / Ctrl+K Omni Search Focus
    document.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        var sInput = document.querySelector('.topbar-search-input');
        if (sInput) {
          sInput.focus();
          sInput.select();
        }
      }
    });
  }

  window.AdminHeader = { init: init };
})();
