/* =========================================================
   FatherGateway — API Keys Page JS
   API Keys In-Place Secret Reveal Engine
   ========================================================= */
(function () {
  'use strict';

  // API KEYS IN-PLACE SECRET REVEAL ENGINE
  // =========================================================
  var apiKeysBound = false;
  function initApiKeysSecretReveal() {
    if (apiKeysBound) return;
    apiKeysBound = true;

    document.addEventListener('click', function (e) {
      var toggleBtn = e.target.closest('[data-toggle-secret]');
      if (toggleBtn) {
        e.preventDefault();
        var keyId = toggleBtn.getAttribute('data-toggle-secret');
        var box = document.getElementById('secretBox-' + keyId);
        var txt = document.getElementById('revealText-' + keyId);
        if (!box) return;

        if (box.style.display === 'none' || box.style.display === '') {
          box.style.display = 'block';
          if (txt) txt.textContent = 'Hide';
        } else {
          box.style.display = 'none';
          if (txt) txt.textContent = 'Reveal';
        }
        return;
      }

      var hideBtn = e.target.closest('[data-hide-secret]');
      if (hideBtn) {
        e.preventDefault();
        var kId = hideBtn.getAttribute('data-hide-secret');
        var b = document.getElementById('secretBox-' + kId);
        var t = document.getElementById('revealText-' + kId);
        if (b) b.style.display = 'none';
        if (t) t.textContent = 'Reveal';
        return;
      }
    });
  }

  window.initDashboardV2 = initDashboardV2;


  document.addEventListener('DOMContentLoaded', function () {
    if (typeof initApiKeysSecretReveal === 'function') initApiKeysSecretReveal();
  });
  window.addEventListener('turbo:load', function () {
    if (typeof initApiKeysSecretReveal === 'function') initApiKeysSecretReveal();
  });
  window.addEventListener('page:loaded', function () {
    if (typeof initApiKeysSecretReveal === 'function') initApiKeysSecretReveal();
  });
})();
