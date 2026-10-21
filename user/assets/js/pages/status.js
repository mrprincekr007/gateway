/* =========================================================
   FatherGateway — System Status Page JS
   Uptime Telemetry, Sub-50ms Latency Gauges & Node Health Monitors
   ========================================================= */
(function () {
  'use strict';

  function initStatusPage() {
    // Pulse animation for active nodes
    var livePills = document.querySelectorAll('.val-rate');
    livePills.forEach(function (pill) {
      pill.style.transition = 'opacity 0.6s ease';
    });

    // Auto-refresh telemetry timestamp
    var timerEl = document.querySelector('.status-hero-time');
    if (timerEl) {
      setInterval(function () {
        var d = new Date();
        timerEl.textContent = d.toUTCString();
      }, 1000);
    }
  }

  document.addEventListener('DOMContentLoaded', initStatusPage);
  window.addEventListener('turbo:load', initStatusPage);
  window.addEventListener('page:loaded', initStatusPage);
})();
