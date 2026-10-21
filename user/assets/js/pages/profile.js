/* =========================================================
   FatherGateway — Profile Page JS
   Segmented Tabs, Live Image Preview, Password Strength & Toggle
   ========================================================= */
(function () {
  'use strict';

  // ---------- Profile Segmented Tabs & Interactive Previews ----------
  var profileTabsBound = false;
  function switchProfileTab(tabKey, pushHistory) {
    if (!tabKey) return;

    // 1. Update tab navigation active state
    document.querySelectorAll('[data-profile-tab]').forEach(function (el) {
      if (el.getAttribute('data-profile-tab') === tabKey) {
        el.classList.add('is-active', 'active');
      } else {
        el.classList.remove('is-active', 'active');
      }
    });

    // 2. Switch panel
    document.querySelectorAll('.profile-tab-panel').forEach(function (panel) {
      if (panel.getAttribute('data-profile-panel') === tabKey) {
        panel.classList.remove('is-active');
        void panel.offsetWidth;
        panel.classList.add('is-active');
      } else {
        panel.classList.remove('is-active');
      }
    });

    // 3. Move universal liquid spring glider smoothly
    var tabsNav = document.querySelector('.profile-tabs-nav');
    var activeBtn = tabsNav ? tabsNav.querySelector('.profile-tab-btn[data-profile-tab="' + tabKey + '"]') : null;
    if (tabsNav && activeBtn && typeof window.syncLiquidGlider === 'function') {
      window.syncLiquidGlider(tabsNav, activeBtn, false);
    } else if (typeof window.initUniversalLiquidTabs === 'function') {
      window.initUniversalLiquidTabs();
    }

    // 4. Update URL parameter silently without page reload
    if (pushHistory !== false) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tabKey);
        history.replaceState({ profileTab: tabKey }, '', url.toString());
      } catch (e) {}
    }
  }
  window.switchProfileTab = switchProfileTab;

  function initProfileTabs() {
    if (!document.querySelector('.profile-tabs-nav')) return;

    // Live checkout preview sync
    var nameInput = document.getElementById('profileMerchantNameInput');
    var upiInput = document.getElementById('profileUpiIdInput');
    var previewName = document.getElementById('previewMerchantName');
    var previewUpi = document.getElementById('previewUpiId');

    if (nameInput && previewName) {
      nameInput.addEventListener('input', function () {
        previewName.textContent = nameInput.value.trim() || 'Your Brand Name';
      });
    }

    if (upiInput && previewUpi) {
      upiInput.addEventListener('input', function () {
        previewUpi.textContent = upiInput.value.trim() || 'merchant@upi';
      });
    }

    // Avatar preset gallery — live preview on selection
    var presetRadios = document.querySelectorAll('.avatar-preset input[type="radio"]');
    if (presetRadios.length) {
      presetRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
          var grid = radio.closest('.avatar-preset-grid');
          if (grid) {
            grid.querySelectorAll('.avatar-preset').forEach(function (label) {
              label.classList.toggle('is-selected', label.querySelector('input') === radio);
            });
          }

          var img = radio.parentElement.querySelector('img');
          var src = img ? img.getAttribute('src') : null;
          if (!src) return;

          var heroCircle = document.querySelector('.profile-avatar-circle');
          if (heroCircle) {
            heroCircle.innerHTML = '<img src="' + src + '" alt="Brand Avatar">';
          }

          var qrBadge = document.querySelector('.mock-qr-avatar-badge');
          if (qrBadge) {
            qrBadge.innerHTML = '<img src="' + src + '" id="mockQrAvatarImg" alt="Brand Logo">';
          }
        });
      });
    }

    // Avatar style filter chips
    var filterChips = document.querySelectorAll('[data-avatar-filter]');
    if (filterChips.length) {
      filterChips.forEach(function (chip) {
        chip.addEventListener('click', function () {
          var style = chip.getAttribute('data-avatar-filter');
          filterChips.forEach(function (c) {
            c.classList.toggle('is-active', c === chip);
          });
          document.querySelectorAll('.avatar-preset').forEach(function (card) {
            var show = style === 'all' || card.getAttribute('data-style') === style;
            card.classList.toggle('is-hidden', !show);
          });
        });
      });
    }

    // Password strength meter
    var newPassInput = document.getElementById('profileNewPassInput');
    var strengthFill = document.getElementById('passStrengthFill');
    var strengthText = document.getElementById('passStrengthText');

    if (newPassInput && strengthFill) {
      newPassInput.addEventListener('input', function () {
        var val = newPassInput.value;
        var score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        var widths = ['0%', '25%', '50%', '75%', '100%'];
        var colors = ['transparent', '#ef4444', '#f59e0b', '#3b82f6', '#22c55e'];
        var labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];

        strengthFill.style.width = widths[score];
        strengthFill.style.backgroundColor = colors[score];
        if (strengthText) {
          strengthText.textContent = labels[score];
          strengthText.style.color = colors[score];
        }
      });
    }

    // Password toggle eye buttons
    document.querySelectorAll('.pass-toggle-action').forEach(function (btn) {
      btn.onclick = function (e) {
        e.preventDefault();
        var targetId = btn.getAttribute('data-target');
        var input = document.getElementById(targetId);
        if (!input) return;
        if (input.type === 'password') {
          input.type = 'text';
          btn.classList.add('is-showing');
        } else {
          input.type = 'password';
          btn.classList.remove('is-showing');
        }
      };
    });

    if (profileTabsBound) return;
    profileTabsBound = true;

    // Tab click delegation
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-profile-tab]');
      if (!btn) return;
      var tabKey = btn.getAttribute('data-profile-tab');
      if (tabKey) {
        e.preventDefault();
        switchProfileTab(tabKey, true);
      }
    });

    window.addEventListener('popstate', function () {
      if (window.location.pathname.indexOf('profile.php') !== -1) {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab') || 'general';
        switchProfileTab(tab, false);
      }
    });
  }


  document.addEventListener('DOMContentLoaded', function () {
    if (typeof initProfileTabs === 'function') initProfileTabs();
  });
  window.addEventListener('turbo:load', function () {
    if (typeof initProfileTabs === 'function') initProfileTabs();
  });
  window.addEventListener('page:loaded', function () {
    if (typeof initProfileTabs === 'function') initProfileTabs();
  });
})();
