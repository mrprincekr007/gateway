/* =========================================================
   FatherGateway — Sidebar Component JS (Floating Capsule & Mini Dock)
   ========================================================= */
(function () {
  'use strict';

  /* ---------- Floating Island Mini / Expand Sidebar Toggle ---------- */
  window.toggleSidebarMini = function () {
    var isMini = document.body.classList.toggle('sidebar-mini');
    document.documentElement.classList.toggle('sidebar-mini', isMini);
    try {
      localStorage.setItem('user_sidebar_mini', isMini ? '1' : '0');
    } catch (e) { }
    hideSidebarTip();

    var glider = document.getElementById('sidebarLiquidGlider');
    if (glider) {
      if (isMini) {
        glider.classList.remove('is-active');
      } else {
        setTimeout(function () {
          if (typeof initLiquidGlider === 'function') initLiquidGlider();
        }, 260);
      }
    }
  };

  // Synchronize on load
  try {
    if (localStorage.getItem('user_sidebar_mini') === '1' && window.innerWidth > 960) {
      document.body.classList.add('sidebar-mini');
      document.documentElement.classList.add('sidebar-mini');
    }
  } catch (e) { }

  /* ---------- Floating Tooltip Engine for Mini Dock (Zero-Overflow on body) ---------- */
  var sidebarFloatTip = null;
  function getSidebarFloatTip() {
    if (!sidebarFloatTip) {
      sidebarFloatTip = document.createElement('div');
      sidebarFloatTip.id = 'sidebar-float-tip';
      sidebarFloatTip.className = 'sidebar-floating-tooltip';
      document.body.appendChild(sidebarFloatTip);
    }
    return sidebarFloatTip;
  }

  function showSidebarTip(el) {
    if (!document.body.classList.contains('sidebar-mini') || window.innerWidth <= 960) return;
    var text = el.getAttribute('data-tooltip') || el.getAttribute('title');
    if (!text) return;
    var tip = getSidebarFloatTip();
    tip.textContent = text;
    var rect = el.getBoundingClientRect();
    tip.style.left = (rect.right + 12) + 'px';
    tip.style.top = (rect.top + rect.height / 2) + 'px';
    tip.classList.add('visible');
  }

  function hideSidebarTip() {
    if (sidebarFloatTip) {
      sidebarFloatTip.classList.remove('visible');
    }
  }

  document.addEventListener('mouseover', function (e) {
    var target = e.target.closest('#sidebar [data-tooltip]');
    if (target) {
      showSidebarTip(target);
    }
  });

  document.addEventListener('mouseout', function (e) {
    var target = e.target.closest('#sidebar [data-tooltip]');
    if (target) {
      hideSidebarTip();
    }
  });

  window.addEventListener('scroll', hideSidebarTip, { passive: true });

  var sidebarEl = null;
  var overlayEl = null;
  function getSidebar() {
    if (!sidebarEl) sidebarEl = document.getElementById('sidebar');
    return sidebarEl;
  }
  function getOverlay() {
    if (!overlayEl) overlayEl = document.getElementById('sidebarOverlay');
    return overlayEl;
  }

  var animTimer = null;
  var overflowTimer = null;
  function markAnimating(sb) {
    if (!sb) return;
    sb.classList.add('is-animating');
    clearTimeout(animTimer);
    animTimer = setTimeout(function () {
      sb.classList.remove('is-animating');
    }, 320);
  }

  window.openSidebar = function () {
    var sb = getSidebar();
    var ov = getOverlay();
    if (sb && sb.classList.contains('open')) {
      window.closeSidebar();
      return;
    }
    clearTimeout(overflowTimer);
    if (window.innerWidth <= 960) {
      document.body.style.overflow = 'hidden';
    }
    markAnimating(sb);
    if (sb) sb.classList.add('open');
    if (ov) ov.classList.add('show');
  };

  window.closeSidebar = function () {
    var sb = getSidebar();
    var ov = getOverlay();
    clearTimeout(overflowTimer);
    markAnimating(sb);
    if (sb) sb.classList.remove('open');
    if (ov) ov.classList.remove('show');
    if (window.innerWidth <= 960 && document.body.style.overflow === 'hidden') {
      overflowTimer = setTimeout(function () {
        document.body.style.overflow = '';
      }, 280);
    } else {
      document.body.style.overflow = '';
    }
  };

  document.addEventListener('click', function (e) {
    var link = e.target.closest('#sidebar .nav-item');
    if (link && window.innerWidth <= 960) {
      window.closeSidebar();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { window.closeSidebar(); }
  });


  // Initialize tooltips on load
  document.addEventListener('DOMContentLoaded', function () {
    try {
      if (localStorage.getItem('user_sidebar_mini') === '1' && window.innerWidth > 960) {
        document.body.classList.add('sidebar-mini');
        document.documentElement.classList.add('sidebar-mini');
      }
    } catch (e) { }
  });

  /* ---------- Liquid Fluid Glider Controller ---------- */
  function initLiquidGlider() {
    var nav = document.getElementById('sidebarNav');
    var glider = document.getElementById('sidebarLiquidGlider');
    if (!nav || !glider) return;

    nav.classList.add('has-liquid-glider');

    var navItems = nav.querySelectorAll('.nav-item');
    if (!navItems.length) return;

    var activeItem = nav.querySelector('.nav-item.active') || navItems[0];
    var currentItem = activeItem;
    var stretchTimer = null;
    var currentTop = activeItem ? activeItem.offsetTop : 0;

    function moveGliderTo(targetItem, isImmediate) {
      if (!targetItem || document.body.classList.contains('sidebar-mini')) {
        glider.classList.remove('is-active');
        return;
      }

      var targetX = targetItem.offsetLeft;
      var targetY = targetItem.offsetTop;
      var targetW = targetItem.offsetWidth;
      var targetH = targetItem.offsetHeight;

      if (!targetW || !targetH) return;

      var deltaY = targetY - currentTop;
      var distance = Math.abs(deltaY);

      navItems.forEach(function (el) { el.classList.remove('is-hovered'); });
      targetItem.classList.add('is-hovered');

      if (!isImmediate && distance > 6) {
        glider.classList.remove('gliding-down', 'gliding-up');
        if (deltaY > 0) {
          glider.classList.add('gliding-down');
        } else {
          glider.classList.add('gliding-up');
        }

        clearTimeout(stretchTimer);
        stretchTimer = setTimeout(function () {
          glider.classList.remove('gliding-down', 'gliding-up');
        }, 360);
      }

      currentTop = targetY;

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
    }

    // Set initial position immediately on load
    setTimeout(function () {
      moveGliderTo(activeItem, true);
    }, 50);

    // Liquid transition across buttons on hover
    navItems.forEach(function (item) {
      item.addEventListener('mouseenter', function () {
        currentItem = item;
        moveGliderTo(item, false);
      });

      item.addEventListener('click', function () {
        navItems.forEach(function (el) { el.classList.remove('active'); });
        item.classList.add('active');
        activeItem = item;
        currentItem = item;
        moveGliderTo(item, false);
      });
    });

    // When mouse leaves nav, liquid glides back to active item
    nav.addEventListener('mouseleave', function () {
      currentItem = activeItem;
      moveGliderTo(activeItem, false);
    });

    // Window resize
    window.addEventListener('resize', function () {
      moveGliderTo(currentItem, true);
    }, { passive: true });

    // Mini sidebar toggle support
    var toggleBtn = document.querySelector('.sidebar-dock-toggle');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        setTimeout(function () {
          moveGliderTo(currentItem, true);
        }, 360);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLiquidGlider);
  } else {
    initLiquidGlider();
  }
})();
