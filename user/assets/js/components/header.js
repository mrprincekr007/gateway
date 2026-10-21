/* =========================================================
   FatherGateway — Header / Topbar Component JS (Goll Pill Header)
   ========================================================= */
(function () {
  'use strict';

  function initHeader() {
    // ---------- Dropdowns (Topbar) ----------
    var notifBtn = document.getElementById('topbarNotifBtn');
    var notifDropdown = document.getElementById('topbarNotifDropdown');
    var userBtn = document.getElementById('topbarUserBtn');
    var userDropdown = document.getElementById('topbarUserDropdown');

    function closeAllDropdowns() {
      if (notifDropdown) notifDropdown.classList.remove('is-open');
      if (userDropdown) userDropdown.classList.remove('is-open');
    }

    if (notifBtn && notifDropdown) {
      notifBtn.onclick = function (e) {
        e.stopPropagation();
        var wasOpen = notifDropdown.classList.contains('is-open');
        closeAllDropdowns();
        if (!wasOpen) notifDropdown.classList.add('is-open');
      };
    }

    if (userBtn && userDropdown) {
      userBtn.onclick = function (e) {
        e.stopPropagation();
        var wasOpen = userDropdown.classList.contains('is-open');
        closeAllDropdowns();
        if (!wasOpen) userDropdown.classList.add('is-open');
      };
    }

    document.addEventListener('click', function (e) {
      if (!e.target.closest('#topbarNotifBtn') && !e.target.closest('#topbarNotifDropdown') &&
          !e.target.closest('#topbarUserBtn') && !e.target.closest('#topbarUserDropdown')) {
        closeAllDropdowns();
      }
    });


    // ---------- Sticky Header Scrolled State ----------
    var appTopbar = document.querySelector('.app-topbar-goll');
    if (appTopbar) {
      var checkScroll = function () {
        if (window.scrollY > 15) {
          appTopbar.classList.add('is-scrolled');
        } else {
          appTopbar.classList.remove('is-scrolled');
        }
      };
      window.removeEventListener('scroll', checkScroll);
      window.addEventListener('scroll', checkScroll, { passive: true });
      checkScroll();
    }

  }

  document.addEventListener('DOMContentLoaded', initHeader);
  window.addEventListener('turbo:load', initHeader);
  window.addEventListener('page:loaded', initHeader);
})();
