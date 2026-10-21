/* =========================================================
   FatherGateway — Integrations Page JS
   Live IMAP Sync Trigger & App Password Reveal Toggle
   ========================================================= */
(function () {
  'use strict';

  function initIntegrationsPage() {
    // ---------- Live IMAP Sync Trigger ----------
    var imapSyncBtn = document.getElementById('dashImapSyncBtn');
    if (imapSyncBtn) {
      imapSyncBtn.onclick = function () {
        if (imapSyncBtn.classList.contains('is-syncing')) return;
        imapSyncBtn.classList.add('is-syncing');

        var fd = new FormData();
        fd.append('action', 'sync_imap');
        fd.append('csrf_token', window.CSRF_TOKEN || '');
        fd.append('ajax', '1');

        fetch(window.location.href, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          imapSyncBtn.classList.remove('is-syncing');
          if (data.success) {
            if (window.toast) window.toast(data.message || 'IMAP scan complete!', 'success');
            var lastCheckEl = document.getElementById('imapLastCheckDisplay');
            if (lastCheckEl && data.last_check) {
              lastCheckEl.textContent = data.last_check;
            }
            if (data.matched > 0) {
              setTimeout(function () {
                if (window.navigateTo) window.navigateTo(window.location.href, false);
                else window.location.reload();
              }, 1200);
            }
          } else {
            if (window.toast) window.toast(data.error || 'IMAP scan failed.', 'error');
          }
        })
        .catch(function () {
          imapSyncBtn.classList.remove('is-syncing');
          if (window.toast) window.toast('Synced check requested.', 'info');
        });
      };
    }


    // ---------- Integrations App Password Reveal Toggle ----------
    var toggleAppPassBtn = document.getElementById('toggleAppPassBtn');
    var appPasswordInput = document.getElementById('appPasswordInput');
    var eyeOpenIcon = document.getElementById('eyeOpenIcon');
    var eyeClosedIcon = document.getElementById('eyeClosedIcon');

    if (toggleAppPassBtn && appPasswordInput) {
      toggleAppPassBtn.onclick = function () {
        if (appPasswordInput.type === 'password') {
          appPasswordInput.type = 'text';
          if (eyeOpenIcon) eyeOpenIcon.style.display = 'none';
          if (eyeClosedIcon) eyeClosedIcon.style.display = 'inline-flex';
        } else {
          appPasswordInput.type = 'password';
          if (eyeOpenIcon) eyeOpenIcon.style.display = 'inline-flex';
          if (eyeClosedIcon) eyeClosedIcon.style.display = 'none';
        }
      };
    }

  }

  document.addEventListener('DOMContentLoaded', initIntegrationsPage);
  window.addEventListener('turbo:load', initIntegrationsPage);
  window.addEventListener('page:loaded', initIntegrationsPage);
})();
