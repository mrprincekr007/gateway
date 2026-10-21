/* =========================================================
   FatherGateway — auth.js
   Dedicated Client Scripts for Authentication Suite:
   login.php, register.php, forgot-password.php, reset-password.php
   Password Eye Toggles, Toast Feedback, Submit Spinners
   ========================================================= */

(function () {
  'use strict';

  // ---------- Toast Notification Helper (Bottom Center) ----------
  window.toast = function (message, type) {
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    var icon = type === 'error' ? 'danger' : (type === 'info' ? 'info' : 'success');
    var icons = {
      success: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      danger: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
      info: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    t.innerHTML = '<span class="toast-icon">' + (icons[icon] || icons.info) + '</span><span class="toast-text">' + message + '</span>';
    
    var container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      document.body.appendChild(container);
    }
    container.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () {
      t.classList.remove('show');
      setTimeout(function () { t.remove(); }, 280);
    }, 3200);
  };

  // ---------- Robust Global Password Toggle ----------
  var eyeSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var eyeOffSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>';

  window.toggleAuthPwd = function (inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;

    var isFocused = (document.activeElement === input);
    var start = 0;
    var end = 0;
    try {
      start = input.selectionStart;
      end = input.selectionEnd;
    } catch (e) {}

    if (input.type === 'password') {
      input.type = 'text';
      if (btn) {
        btn.innerHTML = eyeOffSvg;
        btn.setAttribute('aria-label', 'Hide password');
      }
    } else {
      input.type = 'password';
      if (btn) {
        btn.innerHTML = eyeSvg;
        btn.setAttribute('aria-label', 'Show password');
      }
    }

    // Ensure inputmode="text" remains intact so virtual keyboard layout stays on standard text
    input.setAttribute('inputmode', 'text');
    input.setAttribute('autocapitalize', 'none');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('spellcheck', 'false');

    if (isFocused) {
      input.focus({ preventScroll: true });
      try {
        if (typeof start === 'number' && typeof end === 'number') {
          input.setSelectionRange(start, end);
        }
      } catch (e) {}
    }
  };

  var authSpinnerSvg = '<svg class="auth-btn-spinner" width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="margin-right:8px;vertical-align:middle;display:inline-block;flex-shrink:0;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="2.5"/><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>';

  function getFormLoadingText(form) {
    var actionInput = form.querySelector('input[name="action"]');
    var action = actionInput ? actionInput.value : '';
    var formId = form.id || '';

    if (action === 'signin' || formId === 'formSignIn') {
      return 'Signing in...';
    } else if (action === 'signup' || formId === 'formSignUp') {
      return 'Creating Account...';
    } else if (action === 'forgot_password' || formId === 'formForgot') {
      return 'Sending OTP...';
    } else if (action === 'verify_and_reset' || formId === 'formResetOtp') {
      return 'Saving Password...';
    } else if (action === 'verify_register_otp' || formId === 'formVerifyRegOtp') {
      return 'Verifying...';
    } else if (action === 'resend_register_otp' || action === 'resend_reset_otp') {
      return 'Resending Code...';
    }
    return 'Processing...';
  }

  function initFormSubmitFeedback() {
    var forms = document.querySelectorAll('form');
    forms.forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (!f.checkValidity || !f.checkValidity()) {
          return; // Let browser HTML5 validation show tooltip
        }
        var submitBtn = f.querySelector('button[type="submit"].auth-submit-btn, button[type="submit"]');
        if (submitBtn) {
          submitBtn.classList.add('is-loading');
          var originalHtml = submitBtn.innerHTML;
          var loadingText = getFormLoadingText(f);
          submitBtn.innerHTML = authSpinnerSvg + '<span>' + loadingText + '</span>';
          // Auto revert if not redirected within 8s
          setTimeout(function () {
            submitBtn.classList.remove('is-loading');
            submitBtn.innerHTML = originalHtml;
          }, 8000);
        }
      });
    });
  }

  // ---------- Copy To Clipboard Helper ----------
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var text = btn.getAttribute('data-copy') || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        window.toast('Copied to clipboard', 'success');
      });
    }
  });

  window.setAuthMode = function (mode) {
    var card = document.getElementById('authSliderCard');
    var tabSignIn = document.getElementById('mobileTabSignIn');
    var tabSignUp = document.getElementById('mobileTabSignUp');
    if (!card) return;

    // Trigger elastic rubber jelly bounce on card switch (PC Only)
    if (window.innerWidth > 900) {
      card.classList.remove('is-rubber-bouncing');
      void card.offsetWidth;
      card.classList.add('is-rubber-bouncing');
      setTimeout(function () {
        card.classList.remove('is-rubber-bouncing');
      }, 600);
    }

    if (mode === 'signup') {
      card.classList.add('is-signup');
      if (tabSignIn) tabSignIn.classList.remove('active');
      if (tabSignUp) tabSignUp.classList.add('active');

      // Ensure signup panel view is active and visible
      var signupPanel = document.querySelector('.panel-signup');
      if (signupPanel) {
        var views = signupPanel.querySelectorAll('.auth-subview');
        views.forEach(function (v) { v.classList.add('active'); });
      }

      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#signup');
      }
      setTimeout(function () {
        var input = document.getElementById('signup_name');
        if (input && window.innerWidth > 840) input.focus();
      }, 350);
    } else {
      card.classList.remove('is-signup');
      if (tabSignIn) tabSignIn.classList.add('active');
      if (tabSignUp) tabSignUp.classList.remove('active');

      // Ensure a subview is active in signin panel
      var signinPanel = document.querySelector('.panel-signin');
      if (signinPanel) {
        var activeSubview = signinPanel.querySelector('.auth-subview.active');
        if (!activeSubview) {
          var defaultSignin = document.getElementById('subview-signin');
          if (defaultSignin) defaultSignin.classList.add('active');
        }
      }

      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#signin');
      }
      setTimeout(function () {
        var input = document.getElementById('signin_email');
        if (input && window.innerWidth > 840) input.focus();
      }, 350);
    }
  };

  // ---------- Subview Controller (Sign In vs Forgot Password inside Left Panel) ----------
  window.showSubView = function (name) {
    // Only toggle subviews inside .panel-signin (Sign In, Forgot Password, Reset OTP)
    var signinPanel = document.querySelector('.panel-signin');
    if (signinPanel) {
      var subviews = signinPanel.querySelectorAll('.auth-subview');
      subviews.forEach(function (el) {
        el.classList.remove('active');
      });
    }

    var target = document.getElementById('subview-' + name);
    if (target) {
      target.classList.add('active');
      if (name === 'forgot' || name === 'signin') {
        window.setAuthMode('signin');
      }
      setTimeout(function () {
        var firstInput = target.querySelector('input:not([type="hidden"])');
        if (firstInput && window.innerWidth > 840) firstInput.focus();
      }, 200);
    }
  };

  function initSliderAuth() {
    var card = document.getElementById('authSliderCard');
    if (!card) return;

    // Check URL parameters or hash
    var hash = window.location.hash;
    var search = window.location.search;
    if (hash === '#forgot' || search.indexOf('mode=forgot') !== -1) {
      window.setAuthMode('signin');
      window.showSubView('forgot');
    } else if (hash === '#signup' || search.indexOf('mode=signup') !== -1 || search.indexOf('signup=1') !== -1) {
      window.setAuthMode('signup');
    } else if (hash === '#signin' || search.indexOf('mode=signin') !== -1) {
      window.setAuthMode('signin');
      window.showSubView('signin');
    }
  }

  // ---------- Custom Cyber Context Menu (Right Click) ----------
  function initCustomContextMenu() {
    var menu = document.getElementById('authContextMenu');
    if (!menu) return;

    var switchBtn = document.getElementById('ctxSwitchMode');
    var switchLabel = document.getElementById('ctxSwitchLabel');
    var forgotBtn = document.getElementById('ctxForgotPwd');
    var pasteBtn = document.getElementById('ctxPaste');
    var reloadBtn = document.getElementById('ctxReload');
    var securityBtn = document.getElementById('ctxSecurityInfo');
    var lastTargetInput = null;

    function hideContextMenu() {
      menu.classList.remove('active');
    }

    // Intercept Right Click
    document.addEventListener('contextmenu', function (e) {
      e.preventDefault();

      if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
        lastTargetInput = e.target;
      } else {
        lastTargetInput = document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA') ? document.activeElement : null;
      }

      // Dynamic Mode Switch Label
      var card = document.getElementById('authSliderCard');
      var isSignup = card && card.classList.contains('is-signup');
      if (switchLabel) {
        switchLabel.textContent = isSignup ? 'Switch to Sign In' : 'Switch to Sign Up';
      }

      // Compute Boundary-Safe Coordinates
      var menuWidth = 224;
      var menuHeight = 260;
      var posX = e.clientX;
      var posY = e.clientY;

      if (posX + menuWidth > window.innerWidth - 10) {
        posX = window.innerWidth - menuWidth - 10;
      }
      if (posY + menuHeight > window.innerHeight - 10) {
        posY = window.innerHeight - menuHeight - 10;
      }
      if (posX < 10) posX = 10;
      if (posY < 10) posY = 10;

      menu.style.left = posX + 'px';
      menu.style.top = posY + 'px';
      menu.classList.add('active');
    });

    // Dismiss on click outside
    document.addEventListener('click', function (e) {
      if (!menu.contains(e.target)) {
        hideContextMenu();
      }
    });

    // Dismiss on Escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        hideContextMenu();
      }
    });

    // Dismiss on window resize or scroll
    window.addEventListener('resize', hideContextMenu);
    window.addEventListener('scroll', hideContextMenu);

    // Action: Switch Mode
    if (switchBtn) {
      switchBtn.addEventListener('click', function () {
        hideContextMenu();
        var card = document.getElementById('authSliderCard');
        var isSignup = card && card.classList.contains('is-signup');
        if (typeof window.setAuthMode === 'function') {
          window.setAuthMode(isSignup ? 'signin' : 'signup');
          if (isSignup && typeof window.showSubView === 'function') {
            window.showSubView('signin');
          }
        }
      });
    }

    // Action: Forgot Password
    if (forgotBtn) {
      forgotBtn.addEventListener('click', function () {
        hideContextMenu();
        if (typeof window.showSubView === 'function') {
          window.showSubView('forgot');
        }
      });
    }

    // Action: Paste / Autofill
    if (pasteBtn) {
      pasteBtn.addEventListener('click', function () {
        hideContextMenu();
        if (navigator.clipboard && navigator.clipboard.readText) {
          navigator.clipboard.readText().then(function (text) {
            if (!text) return;
            var target = lastTargetInput || document.querySelector('.auth-subview.active input:not([type="hidden"])');
            if (target) {
              target.value = text;
              target.focus();
              target.dispatchEvent(new Event('input', { bubbles: true }));
              if (typeof window.toast === 'function') {
                window.toast('Pasted from clipboard', 'success');
              }
            }
          }).catch(function () {
            if (typeof window.toast === 'function') {
              window.toast('Please use Ctrl+V to paste', 'info');
            }
          });
        } else {
          if (typeof window.toast === 'function') {
            window.toast('Use Ctrl+V to paste', 'info');
          }
        }
      });
    }

    // Action: Reload
    if (reloadBtn) {
      reloadBtn.addEventListener('click', function () {
        hideContextMenu();
        window.location.reload();
      });
    }

    // Action: Security Info
    if (securityBtn) {
      securityBtn.addEventListener('click', function () {
        hideContextMenu();
        if (typeof window.toast === 'function') {
          window.toast('Direct UPI 256-Bit SSL Active', 'success');
        }
      });
    }
  }

  // ---------- Auto-dismiss Floating Alert ----------
  function initFloatingAlert() {
    var alertEl = document.getElementById('authFloatingAlert');
    if (!alertEl) return;
    setTimeout(function () {
      alertEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
      alertEl.style.opacity = '0';
      alertEl.style.transform = 'translate(-50%, -15px)';
      setTimeout(function () {
        if (alertEl.parentNode) alertEl.parentNode.removeChild(alertEl);
      }, 420);
    }, 5500);
  }

  // ---------- Password Keyboard Stability (Keep Standard QWERTY Text Keyboard) ----------
  function ensurePasswordKeyboardStability() {
    var pwdInputs = document.querySelectorAll('input[type="password"], input[id*="password"], input[name*="password"]');
    pwdInputs.forEach(function (inp) {
      inp.setAttribute('inputmode', 'text');
      inp.setAttribute('autocapitalize', 'none');
      inp.setAttribute('autocorrect', 'off');
      inp.setAttribute('spellcheck', 'false');

      // Keep keyboard mode strictly text on focus
      inp.addEventListener('focus', function () {
        if (inp.getAttribute('inputmode') !== 'text') {
          inp.setAttribute('inputmode', 'text');
        }
      }, { passive: true });
    });
  }

  // Helper cookie functions for remember credentials
  function setAuthCookie(name, value, days) {
    var expires = '';
    if (days) {
      var date = new Date();
      date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
      expires = '; expires=' + date.toUTCString();
    }
    document.cookie = name + '=' + encodeURIComponent(value || '') + expires + '; path=/; SameSite=Lax';
  }

  function deleteAuthCookie(name) {
    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';
  }

  // ---------- Saved Credentials / Remember Me Management ----------
  function initSavedCredentials() {
    var emailInput = document.getElementById('signin_email');
    var pwdInput = document.getElementById('signin_password');
    var rememberCheck = document.getElementById('signin_remember');
    var saveBtn = document.getElementById('saveCredsQuickBtn');
    var signInForm = document.getElementById('formSignIn');

    if (!signInForm) return;

    // Prefill from localStorage if available
    try {
      var savedEmail = localStorage.getItem('auth_saved_email');
      var savedPwd = localStorage.getItem('auth_saved_pwd');
      var rememberMe = localStorage.getItem('auth_remember_me');

      if (savedEmail && emailInput && !emailInput.value) {
        emailInput.value = savedEmail;
      }
      if (savedPwd && pwdInput && !pwdInput.value) {
        pwdInput.value = savedPwd;
      }
      if ((rememberMe === '1' || savedEmail || savedPwd) && rememberCheck) {
        rememberCheck.checked = true;
      }
    } catch (e) {}

    // Real-time synchronization while Remember Me is active
    function syncIfRemembered() {
      if (rememberCheck && rememberCheck.checked) {
        var email = emailInput ? emailInput.value.trim() : '';
        var pwd = pwdInput ? pwdInput.value : '';
        try {
          if (email) {
            localStorage.setItem('auth_saved_email', email);
            setAuthCookie('auth_remember_email', email, 30);
          }
          if (pwd) {
            localStorage.setItem('auth_saved_pwd', pwd);
            setAuthCookie('auth_remember_pass', btoa(unescape(encodeURIComponent(pwd))), 30);
          }
          localStorage.setItem('auth_remember_me', '1');
        } catch (e) {}
      }
    }

    if (emailInput) emailInput.addEventListener('input', syncIfRemembered);
    if (pwdInput) pwdInput.addEventListener('input', syncIfRemembered);

    // When User Ticks or Unticks Remember Me Checkbox
    if (rememberCheck) {
      rememberCheck.addEventListener('change', function () {
        if (rememberCheck.checked) {
          var email = emailInput ? emailInput.value.trim() : '';
          var pwd = pwdInput ? pwdInput.value : '';

          try {
            localStorage.setItem('auth_remember_me', '1');
            if (email) {
              localStorage.setItem('auth_saved_email', email);
              setAuthCookie('auth_remember_email', email, 30);
            }
            if (pwd) {
              localStorage.setItem('auth_saved_pwd', pwd);
              setAuthCookie('auth_remember_pass', btoa(unescape(encodeURIComponent(pwd))), 30);
            }
          } catch (e) {}

          if (typeof window.toast === 'function') {
            window.toast('Gmail aur Password dono save ho gaye!', 'success');
          }
        } else {
          try {
            localStorage.removeItem('auth_saved_email');
            localStorage.removeItem('auth_saved_pwd');
            localStorage.removeItem('auth_remember_me');
            deleteAuthCookie('auth_remember_email');
            deleteAuthCookie('auth_remember_pass');
          } catch (e) {}

          if (typeof window.toast === 'function') {
            window.toast('Saved login info hata di gayi', 'info');
          }
        }
      });
    }

    // Direct "Save Info" Button Click
    if (saveBtn) {
      saveBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var email = emailInput ? emailInput.value.trim() : '';
        var pwd = pwdInput ? pwdInput.value : '';

        if (!email && !pwd) {
          if (typeof window.toast === 'function') {
            window.toast('Please enter your email and password to save', 'info');
          }
          if (emailInput) emailInput.focus();
          return;
        }

        try {
          if (email) {
            localStorage.setItem('auth_saved_email', email);
            setAuthCookie('auth_remember_email', email, 30);
          }
          if (pwd) {
            localStorage.setItem('auth_saved_pwd', pwd);
            setAuthCookie('auth_remember_pass', btoa(unescape(encodeURIComponent(pwd))), 30);
          }
          localStorage.setItem('auth_remember_me', '1');
        } catch (e) {}

        if (rememberCheck) {
          rememberCheck.checked = true;
        }

        // Native Credential Management API (if supported)
        if (window.PasswordCredential && navigator.credentials && email && pwd) {
          try {
            var cred = new PasswordCredential({
              id: email,
              password: pwd,
              name: email
            });
            navigator.credentials.store(cred);
          } catch (err) {}
        }

        // Visual Feedback on Button
        var originalHtml = saveBtn.innerHTML;
        saveBtn.classList.add('saved');
        saveBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>Saved</span>';

        if (typeof window.toast === 'function') {
          window.toast('Gmail aur Password dono save ho gaye!', 'success');
        }

        setTimeout(function () {
          saveBtn.classList.remove('saved');
          saveBtn.innerHTML = originalHtml;
        }, 2400);
      });
    }

    // Save both email and password on form submit if remember_me is checked
    signInForm.addEventListener('submit', function () {
      if (rememberCheck && rememberCheck.checked && emailInput) {
        var email = emailInput.value.trim();
        var pwd = pwdInput ? pwdInput.value : '';
        try {
          if (email) {
            localStorage.setItem('auth_saved_email', email);
            setAuthCookie('auth_remember_email', email, 30);
          }
          if (pwd) {
            localStorage.setItem('auth_saved_pwd', pwd);
            setAuthCookie('auth_remember_pass', btoa(unescape(encodeURIComponent(pwd))), 30);
          }
          localStorage.setItem('auth_remember_me', '1');
        } catch (e) {}
      }
    });
  }

  // ---------- Dark / Light Theme Toggle (Sliding Upper-Corner Button with Circular Wave Reveal) ----------
  function initAuthThemeToggle() {
    var themeToggle = document.getElementById('authThemeToggle');
    var mobileToggle = document.getElementById('authMobileThemeToggle');
    var ctxThemeBtn = document.getElementById('ctxToggleTheme');
    var isThemeTransitioning = false;

    function applyTheme(theme) {
      if (theme === 'light') {
        document.body.classList.add('light-theme');
        document.documentElement.classList.add('light-theme');
        if (themeToggle) themeToggle.setAttribute('title', 'Switch to Dark Mode');
        if (mobileToggle) mobileToggle.setAttribute('title', 'Switch to Dark Mode');
      } else {
        document.body.classList.remove('light-theme');
        document.documentElement.classList.remove('light-theme');
        if (themeToggle) themeToggle.setAttribute('title', 'Switch to Light Mode');
        if (mobileToggle) mobileToggle.setAttribute('title', 'Switch to Light Mode');
      }
      try {
        localStorage.setItem('auth_theme', theme);
      } catch (e) {}
    }

    // Spawns expanding glowing concentric shockwave rings (PC ONLY)
    function spawnCircularShockwavePC(x, y, newTheme, maxRadius) {
      try {
        function createRing(isSecondary) {
          var ripple = document.createElement('div');
          ripple.className = 'theme-circular-ripple ripple-to-' + newTheme + (isSecondary ? ' ripple-secondary' : '');
          ripple.style.left = x + 'px';
          ripple.style.top = y + 'px';
          document.body.appendChild(ripple);

          if (typeof ripple.animate === 'function') {
            var targetScale = Math.max((maxRadius * 2.5) / 32, 45);
            var anim = ripple.animate(
              [
                { transform: 'translate(-50%, -50%) scale(0.08)', opacity: 1 },
                { transform: 'translate(-50%, -50%) scale(' + (targetScale * 0.42) + ')', opacity: 0.9, offset: 0.35 },
                { transform: 'translate(-50%, -50%) scale(' + targetScale + ')', opacity: 0 }
              ],
              {
                duration: isSecondary ? 800 : 700,
                easing: 'cubic-bezier(0.18, 0.9, 0.28, 1)',
                fill: 'forwards'
              }
            );
            anim.onfinish = function () {
              if (ripple.parentNode) ripple.parentNode.removeChild(ripple);
            };
          } else {
            setTimeout(function () {
              if (ripple.parentNode) ripple.parentNode.removeChild(ripple);
            }, 850);
          }
        }

        // 1. Primary intense laser wavefront ring
        createRing(false);

        // 2. Secondary trailing concentric shimmer ring (80ms delay)
        setTimeout(function () {
          createRing(true);
        }, 80);
      } catch (err) {}
    }

    function toggleThemeWithCircularWave(originElem, clickEvent) {
      if (isThemeTransitioning) return;
      isThemeTransitioning = true;

      var isLight = document.body.classList.contains('light-theme');
      var newTheme = isLight ? 'dark' : 'light';

      var isMobile = window.innerWidth <= 900 || originElem === mobileToggle;

      // -----------------------------------------------------------
      // MOBILE PHONES: Direct clean switch with NO circular wave
      // -----------------------------------------------------------
      if (isMobile) {
        applyTheme(newTheme);
        if (typeof window.toast === 'function') {
          window.toast(newTheme === 'light' ? 'Light Mode enabled' : 'Dark Mode enabled', 'info');
        }
        setTimeout(function () {
          isThemeTransitioning = false;
        }, 150);
        return;
      }

      // -----------------------------------------------------------
      // PC ONLY: Enhanced High-End Cinematic Circular Wave Animation
      // -----------------------------------------------------------
      var activeBtn = originElem || themeToggle;
      var x, y;

      if (activeBtn && typeof activeBtn.getBoundingClientRect === 'function') {
        var rect = activeBtn.getBoundingClientRect();
        x = rect.left + rect.width / 2;
        y = rect.top + rect.height / 2;

        // Button tactile pop & pulse wave on PC
        activeBtn.classList.remove('is-pulsing');
        void activeBtn.offsetWidth;
        activeBtn.classList.add('is-pulsing');
        setTimeout(function () {
          activeBtn.classList.remove('is-pulsing');
        }, 700);
      } else if (clickEvent && typeof clickEvent.clientX === 'number' && (clickEvent.clientX > 0 || clickEvent.clientY > 0)) {
        x = clickEvent.clientX;
        y = clickEvent.clientY;
      } else {
        x = window.innerWidth / 2;
        y = window.innerHeight / 2;
      }

      // Maximum radius to all 4 corners of screen
      var maxRadius = Math.hypot(
        Math.max(x, window.innerWidth - x),
        Math.max(y, window.innerHeight - y)
      );

      // Trigger PC Dual Concentric Shockwave Rings
      spawnCircularShockwavePC(x, y, newTheme, maxRadius);

      // Circular Reveal via Native View Transitions or Fallback
      if (typeof document.startViewTransition === 'function') {
        try {
          var transition = document.startViewTransition(function () {
            applyTheme(newTheme);
          });
          transition.ready.then(function () {
            var clipPath = [
              'circle(0px at ' + x + 'px ' + y + 'px)',
              'circle(' + (maxRadius * 1.1) + 'px at ' + x + 'px ' + y + 'px)'
            ];
            document.documentElement.animate(
              {
                clipPath: clipPath
              },
              {
                duration: 720,
                easing: 'cubic-bezier(0.18, 0.9, 0.28, 1)',
                pseudoElement: '::view-transition-new(root)'
              }
            );
          }).catch(function () {
            applyTheme(newTheme);
          });
        } catch (e) {
          applyTheme(newTheme);
        }
      } else {
        // Fallback for non-view-transition desktop browsers
        try {
          var fallbackWave = document.createElement('div');
          fallbackWave.className = 'theme-circular-fallback-wave ' + (newTheme === 'light' ? 'to-light' : 'to-dark');
          fallbackWave.style.clipPath = 'circle(0px at ' + x + 'px ' + y + 'px)';
          document.body.appendChild(fallbackWave);

          void fallbackWave.offsetWidth; // force reflow

          fallbackWave.style.transition = 'clip-path 0.7s cubic-bezier(0.18, 0.9, 0.28, 1), opacity 0.3s ease 0.5s';
          fallbackWave.style.clipPath = 'circle(' + (maxRadius * 1.1) + 'px at ' + x + 'px ' + y + 'px)';

          setTimeout(function () {
            applyTheme(newTheme);
          }, 260);

          setTimeout(function () {
            fallbackWave.style.opacity = '0';
            setTimeout(function () {
              if (fallbackWave.parentNode) fallbackWave.parentNode.removeChild(fallbackWave);
            }, 350);
          }, 700);
        } catch (e) {
          applyTheme(newTheme);
        }
      }

      if (typeof window.toast === 'function') {
        window.toast(newTheme === 'light' ? 'Light Mode enabled' : 'Dark Mode enabled', 'info');
      }

      setTimeout(function () {
        isThemeTransitioning = false;
      }, 750);
    }

    if (themeToggle) {
      themeToggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleThemeWithCircularWave(themeToggle, e);
      });
    }

    if (mobileToggle) {
      mobileToggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleThemeWithCircularWave(mobileToggle, e);
      });
    }

    if (ctxThemeBtn) {
      ctxThemeBtn.addEventListener('click', function (e) {
        if (typeof hideContextMenu === 'function') hideContextMenu();
        toggleThemeWithCircularWave(null, e);
      });
    }

    // Keyboard shortcut 'd' or 'D' when not in inputs
    document.addEventListener('keydown', function (e) {
      var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
      if (e.key === 'd' || e.key === 'D') {
        toggleThemeWithCircularWave(null, null);
      }
    });

    // Check saved theme on load
    try {
      var savedTheme = localStorage.getItem('auth_theme');
      if (savedTheme) {
        applyTheme(savedTheme);
      }
    } catch (e) {}
  }


  // ---------- Initialization ----------
  function init() {
    initFormSubmitFeedback();
    initSliderAuth();
    initCustomContextMenu();
    initFloatingAlert();
    ensurePasswordKeyboardStability();
    initSavedCredentials();
    initAuthThemeToggle();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();

