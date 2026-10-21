<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/icons.php';

$redirect = get('redirect') !== '' ? get('redirect') : 'user/dashboard.php';
if (strncmp($redirect, 'http', 4) === 0) {
    $redirect = 'user/dashboard.php';
}

// Cancel / Reset active OTP sessions if requested
if (get('cancel_reg_otp') === '1') {
    unset(
        $_SESSION['pending_reg_email'],
        $_SESSION['pending_reg_name'],
        $_SESSION['reg_step'],
        $_SESSION['reg_retry_after'],
        $_SESSION['reg_otp_sent_at']
    );
    redirect('auth.php?mode=signup');
}

if (get('cancel_reset_otp') === '1') {
    unset(
        $_SESSION['reset_email'],
        $_SESSION['reset_step'],
        $_SESSION['reset_retry_after'],
        $_SESSION['reset_otp_sent_at']
    );
    redirect('auth.php');
}

// Check pending OTP states
$isRegOtpPending = isset($_SESSION['reg_step']) && $_SESSION['reg_step'] === 'verify_otp' && !empty($_SESSION['pending_reg_email']);
$isResetOtpPending = isset($_SESSION['reset_step']) && $_SESSION['reset_step'] === 'verify_otp' && !empty($_SESSION['reset_email']);

$pendingRegEmail = (string) ($_SESSION['pending_reg_email'] ?? '');
$pendingResetEmail = (string) ($_SESSION['reset_email'] ?? '');

$signinError = null;
$signupError = null;
$forgotError = null;
$resetError  = null;
$regOtpError = null;
$infoMsg     = null;

// Determine initial mode & subview
$initialMode = 'signin';
$initialLeftSubview = 'signin';

if ($isResetOtpPending) {
    $initialMode = 'signin';
    $initialLeftSubview = 'reset-otp';
} elseif ($isRegOtpPending) {
    $initialMode = 'signup';
} elseif (get('mode') === 'forgot' || get('tab') === 'forgot') {
    $initialMode = 'signin';
    $initialLeftSubview = 'forgot';
} elseif (get('mode') === 'signup' || get('tab') === 'signup' || get('signup') === '1') {
    $initialMode = 'signup';
}

$savedEmail = $_COOKIE['auth_remember_email'] ?? '';
$rememberChecked = !empty($savedEmail);
$signinValues = [
    'email' => $savedEmail,
    'password' => ''
];
$signupValues = ['name' => '', 'email' => ''];
$forgotValues = ['email' => ''];

// ==================== POST HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action', 'signin');

    if (!verify_csrf(post('csrf_token'))) {
        $signinError = 'Your session expired. Please try again.';
    } elseif ($action === 'signin') {
        // ---------- SIGN IN ----------
        $initialMode = 'signin';
        $initialLeftSubview = 'signin';
        $email    = strtolower(trim((string) post('email')));
        $password = post('password');
        $signinValues['email'] = $email;
        $signinValues['password'] = '';
        $rememberChecked = post('remember_me') === '1';

        if ($email === '' || $password === '') {
            $signinError = 'Please enter your email and password.';
        } else {
            $clientIp = security_ip();
            $lockout  = security_check_login_lockout($email, $clientIp);

            if ($lockout['locked']) {
                $signinError = $lockout['message'];
            } else {
                $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                    security_record_login_failure($email, $clientIp);
                    $signinError = 'Invalid email or password.';
                } elseif ($user['status'] !== 'active') {
                    $signinError = 'This account has been suspended.';
                } else {
                    security_clear_login_failures($email, $clientIp);
                    if ($rememberChecked) {
                        setcookie('auth_remember_email', $email, time() + (86400 * 30), '/', '', false, true);
                    } else {
                        setcookie('auth_remember_email', '', time() - 3600, '/', '', false, true);
                    }
                    // Always clear legacy insecure password cookie if present
                    if (isset($_COOKIE['auth_remember_pass'])) {
                        setcookie('auth_remember_pass', '', time() - 3600, '/', '', false, true);
                    }
                    login_user((int) $user['id']);

                    // High-Precision Real-Time Location Capture On Every Sign-In
                    $geoLat = post('geo_lat');
                    $geoLng = post('geo_lng');
                    $geoAcc = post('geo_acc') !== '' ? (float) post('geo_acc') : null;
                    $geoAlt = post('geo_alt') !== '' ? (float) post('geo_alt') : null;
                    $clientIp = function_exists('security_ip') ? security_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

                    if ($geoLat !== '' && $geoLng !== '' && is_numeric($geoLat) && is_numeric($geoLng) && abs((float)$geoLat) > 0.0001) {
                        record_user_location((int) $user['id'], (float) $geoLat, (float) $geoLng, $geoAcc, $geoAlt, 'gps', 'login', $clientIp);
                    } else {
                        $ipLoc = lookup_ip_location($clientIp);
                        if ($ipLoc && !empty($ipLoc['lat']) && !empty($ipLoc['lng'])) {
                            record_user_location((int) $user['id'], (float) $ipLoc['lat'], (float) $ipLoc['lng'], (float) ($ipLoc['accuracy'] ?? 5000), null, 'ip', 'login', $clientIp);
                        }
                    }

                    if ($redirect === 'user/dashboard.php' && $user['role'] === 'admin') {
                        $redirect = 'admin/index.php';
                    }
                    redirect($redirect);
                }
            }
        }
    } elseif ($action === 'signup') {
        // ---------- SIGN UP (STEP 1) ----------
        $initialMode = 'signup';
        $signupValues['name']  = trim((string) post('name'));
        $signupValues['email'] = strtolower(trim((string) post('email')));
        $password              = post('password');
        $confirm               = post('password_confirm');

        if ($regErr = security_check_register_rate(security_ip())) {
            $signupError = $regErr;
        } elseif (security_is_blacklisted('email', $signupValues['email'])) {
            $signupError = 'Registration with this email address has been restricted.';
        } elseif (mb_strlen($signupValues['name']) < 2) {
            $signupError = 'Please enter your full name.';
        } elseif (!filter_var($signupValues['email'], FILTER_VALIDATE_EMAIL)) {
            $signupError = 'Please enter a valid email address.';
        } elseif (mb_strlen($password) < 8) {
            $signupError = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm) {
            $signupError = 'Passwords do not match.';
        } else {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$signupValues['email']]);
            if ($stmt->fetch()) {
                $signupError = 'An account with this email already exists. Please sign in instead.';
            } elseif (!otp_is_enabled()) {
                // Direct registration fallback if admin disabled email OTP
                $insert = db()->prepare(
                    'INSERT INTO users (name, email, password_hash, merchant_id, role, status)
                     VALUES (:name, :email, :hash, :mid, "user", "active")'
                );
                $insert->execute([
                    ':name'  => $signupValues['name'],
                    ':email' => $signupValues['email'],
                    ':hash'  => password_hash($password, PASSWORD_DEFAULT),
                    ':mid'   => generate_merchant_id(),
                ]);
                $newUserId = (int) db()->lastInsertId();
                login_user($newUserId);

                $geoLat = post('geo_lat');
                $geoLng = post('geo_lng');
                $geoAcc = post('geo_acc') !== '' ? (float) post('geo_acc') : null;
                $geoAlt = post('geo_alt') !== '' ? (float) post('geo_alt') : null;
                $clientIp = security_ip();
                if ($geoLat !== '' && $geoLng !== '' && is_numeric($geoLat) && is_numeric($geoLng)) {
                    record_user_location($newUserId, (float) $geoLat, (float) $geoLng, $geoAcc, $geoAlt, 'gps', 'register', $clientIp);
                } else {
                    $ipLoc = lookup_ip_location($clientIp);
                    if ($ipLoc) {
                        record_user_location($newUserId, $ipLoc['lat'], $ipLoc['lng'], $ipLoc['accuracy'], null, 'ip', 'register', $clientIp);
                    }
                }

                redirect('user/dashboard.php');
            } else {
                // Dispatch 6-digit OTP to user's Gmail
                $pwdHash = password_hash($password, PASSWORD_DEFAULT);
                $otpRes  = otp_send($signupValues['email'], 'register', [
                    'name'  => $signupValues['name'],
                    'hash'  => $pwdHash,
                ]);

                if ($otpRes['ok']) {
                    $_SESSION['pending_reg_email'] = $signupValues['email'];
                    $_SESSION['pending_reg_name']  = $signupValues['name'];
                    $_SESSION['pending_reg_geo']   = [
                        'lat' => post('geo_lat'),
                        'lng' => post('geo_lng'),
                        'acc' => post('geo_acc'),
                        'alt' => post('geo_alt'),
                    ];
                    $_SESSION['reg_step']          = 'verify_otp';
                    $_SESSION['reg_retry_after']   = $otpRes['retry_after'] ?? security_setting('otp_resend_cooldown_seconds', 30);
                    $_SESSION['reg_otp_sent_at']   = time();
                    redirect('auth.php?mode=signup');
                } else {
                    $signupError = $otpRes['error'] ?? 'Could not deliver verification OTP to your Gmail. Please try again.';
                }
            }
        }
    } elseif ($action === 'verify_register_otp') {
        // ---------- SIGN UP (STEP 2: OTP VERIFY) ----------
        $initialMode = 'signup';
        $otpCode = trim((string) post('otp_code'));
        $verifyEmail = $pendingRegEmail !== '' ? $pendingRegEmail : strtolower(trim((string) post('email')));

        if ($verifyEmail === '') {
            $regOtpError = 'Session missing email. Please restart registration.';
        } else {
            $verifyRes = otp_verify($verifyEmail, 'register', $otpCode);
            if ($verifyRes['ok']) {
                $payload = $verifyRes['payload'] ?? [];
                $userName = !empty($payload['name']) ? (string) $payload['name'] : 'Merchant';
                $userHash = !empty($payload['hash']) ? (string) $payload['hash'] : '';

                if ($userHash === '') {
                    $regOtpError = 'Verification payload expired. Please register again.';
                } else {
                    $insert = db()->prepare(
                        'INSERT INTO users (name, email, password_hash, merchant_id, role, status)
                         VALUES (:name, :email, :hash, :mid, "user", "active")'
                    );
                    $insert->execute([
                        ':name'  => $userName,
                        ':email' => $verifyEmail,
                        ':hash'  => $userHash,
                        ':mid'   => generate_merchant_id(),
                    ]);

                    $newUserId = (int) db()->lastInsertId();
                    $regGeo = $_SESSION['pending_reg_geo'] ?? [];

                    unset(
                        $_SESSION['pending_reg_email'],
                        $_SESSION['pending_reg_name'],
                        $_SESSION['pending_reg_geo'],
                        $_SESSION['reg_step'],
                        $_SESSION['reg_retry_after'],
                        $_SESSION['reg_otp_sent_at']
                    );

                    login_user($newUserId);

                    // High-Precision Real-Time Location Capture
                    $geoLat = post('geo_lat') !== '' ? post('geo_lat') : ($regGeo['lat'] ?? '');
                    $geoLng = post('geo_lng') !== '' ? post('geo_lng') : ($regGeo['lng'] ?? '');
                    $geoAcc = post('geo_acc') !== '' ? (float) post('geo_acc') : (!empty($regGeo['acc']) ? (float) $regGeo['acc'] : null);
                    $geoAlt = post('geo_alt') !== '' ? (float) post('geo_alt') : (!empty($regGeo['alt']) ? (float) $regGeo['alt'] : null);
                    $clientIp = security_ip();
                    if ($geoLat !== '' && $geoLng !== '' && is_numeric($geoLat) && is_numeric($geoLng)) {
                        record_user_location($newUserId, (float) $geoLat, (float) $geoLng, $geoAcc, $geoAlt, 'gps', 'register', $clientIp);
                    } else {
                        $ipLoc = lookup_ip_location($clientIp);
                        if ($ipLoc) {
                            record_user_location($newUserId, $ipLoc['lat'], $ipLoc['lng'], $ipLoc['accuracy'], null, 'ip', 'register', $clientIp);
                        }
                    }

                    redirect('user/dashboard.php');
                }
            } else {
                $regOtpError = $verifyRes['error'] ?? 'Invalid verification code.';
            }
        }
    } elseif ($action === 'resend_register_otp') {
        $initialMode = 'signup';
        $targetEmail = $pendingRegEmail !== '' ? $pendingRegEmail : strtolower(trim((string) post('email')));
        if ($targetEmail !== '') {
            $resendRes = otp_resend($targetEmail, 'register');
            if ($resendRes['ok']) {
                $_SESSION['reg_retry_after'] = $resendRes['retry_after'] ?? security_setting('otp_resend_cooldown_seconds', 30);
                $_SESSION['reg_otp_sent_at']   = time();
                $infoMsg = 'A fresh 6-digit code has been dispatched to your Gmail.';
            } else {
                $regOtpError = $resendRes['error'] ?? 'Failed to resend code. Please wait.';
            }
        }
    } elseif ($action === 'forgot_password') {
        // ---------- FORGOT PASSWORD (STEP 1: REQUEST OTP) ----------
        $initialMode = 'signin';
        $initialLeftSubview = 'forgot';
        $email = strtolower(trim((string) post('email')));
        $forgotValues['email'] = $email;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $forgotError = 'Please enter a valid email address.';
        } elseif ($pwdErr = security_check_password_reset_rate($email, security_ip())) {
            $forgotError = $pwdErr;
        } else {
            $stmt = db()->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $userRow = $stmt->fetch();

            if (!$userRow) {
                $forgotError = 'No active account found with this email address. Please check spelling.';
            } else {
                $otpRes = otp_send($email, 'forgot_password', [
                    'user_id' => (int) $userRow['id'],
                ]);

                if ($otpRes['ok']) {
                    $_SESSION['reset_email']       = $email;
                    $_SESSION['reset_step']        = 'verify_otp';
                    $_SESSION['reset_retry_after'] = $otpRes['retry_after'] ?? security_setting('otp_resend_cooldown_seconds', 30);
                    $_SESSION['reset_otp_sent_at'] = time();
                    redirect('auth.php');
                } else {
                    $forgotError = $otpRes['error'] ?? 'Could not deliver OTP. Please check SMTP settings.';
                }
            }
        }
    } elseif ($action === 'verify_and_reset') {
        // ---------- FORGOT PASSWORD (STEP 2: OTP & NEW PASSWORD) ----------
        $initialMode = 'signin';
        $initialLeftSubview = 'reset-otp';
        $otpCode     = trim((string) post('otp_code'));
        $targetEmail = $pendingResetEmail !== '' ? $pendingResetEmail : strtolower(trim((string) post('email')));
        $password    = (string) post('password');
        $confirm     = (string) post('password_confirm');

        if ($targetEmail === '') {
            $resetError = 'Session missing email. Please request a new code.';
            $initialLeftSubview = 'forgot';
        } elseif (mb_strlen($password) < 8) {
            $resetError = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm) {
            $resetError = 'Passwords do not match.';
        } else {
            $verifyRes = otp_verify($targetEmail, 'forgot_password', $otpCode);
            if ($verifyRes['ok']) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([$hash, $targetEmail]);
                db()->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$targetEmail]);

                security_clear_login_failures($targetEmail, security_ip());

                unset(
                    $_SESSION['reset_email'],
                    $_SESSION['reset_step'],
                    $_SESSION['reset_retry_after'],
                    $_SESSION['reset_otp_sent_at']
                );

                flash_set('success', 'Your password has been updated! Please sign in with your new password.');
                redirect('auth.php');
            } else {
                $resetError = $verifyRes['error'] ?? 'Invalid verification code.';
            }
        }
    } elseif ($action === 'resend_reset_otp') {
        $initialMode = 'signin';
        $initialLeftSubview = 'reset-otp';
        $targetEmail = $pendingResetEmail !== '' ? $pendingResetEmail : strtolower(trim((string) post('email')));
        if ($targetEmail !== '') {
            $resendRes = otp_resend($targetEmail, 'forgot_password');
            if ($resendRes['ok']) {
                $_SESSION['reset_retry_after'] = $resendRes['retry_after'] ?? security_setting('otp_resend_cooldown_seconds', 30);
                $_SESSION['reset_otp_sent_at'] = time();
                $infoMsg = 'A fresh 6-digit code has been dispatched to your Gmail.';
            } else {
                $resetError = $resendRes['error'] ?? 'Failed to resend code. Please wait.';
            }
        }
    }
}

// Compute timers for OTP resend buttons
$defaultCooldown = security_setting('otp_resend_cooldown_seconds', 30);

$regCooldown = 0;
if ($isRegOtpPending && security_is_rate_limiting_enabled()) {
    $elapsed = time() - (int) ($_SESSION['reg_otp_sent_at'] ?? time());
    $regCooldown = max(0, ((int) ($_SESSION['reg_retry_after'] ?? $defaultCooldown)) - $elapsed);
}

$resetCooldown = 0;
if ($isResetOtpPending && security_is_rate_limiting_enabled()) {
    $elapsed = time() - (int) ($_SESSION['reset_otp_sent_at'] ?? time());
    $resetCooldown = max(0, ((int) ($_SESSION['reset_retry_after'] ?? $defaultCooldown)) - $elapsed);
}

$activeNotification = null;
if (!empty($signinError)) {
    $activeNotification = ['type' => 'error', 'text' => $signinError];
} elseif (!empty($signupError)) {
    $activeNotification = ['type' => 'error', 'text' => $signupError];
} elseif (!empty($forgotError)) {
    $activeNotification = ['type' => 'error', 'text' => $forgotError];
} elseif (!empty($resetError)) {
    $activeNotification = ['type' => 'error', 'text' => $resetError];
} elseif (!empty($regOtpError)) {
    $activeNotification = ['type' => 'error', 'text' => $regOtpError];
} elseif (!empty($infoMsg)) {
    $activeNotification = ['type' => 'success', 'text' => $infoMsg];
} elseif ($f = flash_get()) {
    $activeNotification = ['type' => $f['type'] === 'error' ? 'error' : 'success', 'text' => $f['message']];
}

$pageTitle = 'Sign in or Create Account · ' . SITE_NAME;
$bodyClass = 'auth-page auth-page-slider';
$isAuth    = true;
include APP_ROOT . '/includes/header.php';
?>
<script>
(function(){
    try {
        var t = localStorage.getItem('auth_theme');
        if (t === 'light') {
            document.documentElement.classList.add('light-theme');
            if (document.body) document.body.classList.add('light-theme');
        }
    } catch(e) {}
})();
</script>

<div class="auth-viewport">
    <!-- Ambient Cyber-Glow Orbs with Liquid Rubber Morphing -->
    <div class="auth-ambient-glow auth-ambient-1"></div>
    <div class="auth-ambient-glow auth-ambient-2"></div>
    <div class="auth-ambient-glow auth-ambient-3"></div>
    <div class="auth-liquid-drop drop-1"></div>
    <div class="auth-liquid-drop drop-2"></div>
    <div class="auth-liquid-drop drop-3"></div>

    <!-- Top Floating Brand Navigation -->
    <header class="auth-top-nav">
        <a class="auth-nav-brand" href="<?= url('index.php') ?>">
            <img src="<?= asset('assets/img/logo.png') ?>" alt="<?= e(SITE_NAME) ?>" class="brand-logo-img">
            <span class="brand-title"><?= e(SITE_NAME) ?></span>
        </a>
        <div class="auth-nav-actions">
            <!-- Mobile Sliding Pill Button (Exact same design as PC, replaces DIRECT UPI on phone) -->
            <button type="button" class="blade-theme-toggle mobile-theme-pill-toggle" id="authMobileThemeToggle" aria-label="Toggle dark and light theme" title="Toggle Dark/Light Mode">
                <span class="theme-toggle-track">
                    <span class="theme-toggle-icon icon-light" title="Light Mode">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    </span>
                    <span class="theme-toggle-icon icon-dark" title="Dark Mode">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </span>
                    <span class="theme-toggle-thumb"></span>
                </span>
            </button>
            <div class="auth-nav-badge">
                <span class="live-dot"></span>
                <span>DIRECT UPI</span>
            </div>
        </div>
    </header>

    <?php if ($activeNotification): ?>
        <div class="auth-floating-alert auth-alert-<?= $activeNotification['type'] ?>" id="authFloatingAlert">
            <span class="alert-icon">
                <?= icon($activeNotification['type'] === 'error' ? 'alert' : 'check-circle', 16) ?>
            </span>
            <span class="alert-text"><?= e($activeNotification['text']) ?></span>
            <button type="button" class="alert-close-btn" onclick="this.parentElement.remove()" aria-label="Close message">
                <?= icon('x', 14) ?>
            </button>
        </div>
    <?php endif; ?>

    <!-- Centerpiece Sliding Rectangle Card (One rectangle. One translateX) -->
    <div class="auth-slider-card <?= $initialMode === 'signup' ? 'is-signup' : '' ?>" id="authSliderCard">

        <!-- Mobile Segmented Control (< 840px) -->
        <div class="auth-mobile-switch">
            <button type="button" class="mobile-switch-btn <?= $initialMode !== 'signup' ? 'active' : '' ?>" id="mobileTabSignIn" onclick="setAuthMode('signin'); showSubView('signin');">Sign In</button>
            <button type="button" class="mobile-switch-btn <?= $initialMode === 'signup' ? 'active' : '' ?>" id="mobileTabSignUp" onclick="setAuthMode('signup')">Sign Up</button>
            <div class="mobile-switch-glider"></div>
        </div>

        <!-- Real Forms Track Underneath -->
        <div class="auth-forms-track">

            <!-- ================= LEFT PANEL: SIGN IN & RECOVERY ================= -->
            <div class="auth-form-panel panel-signin">
                <div class="form-panel-inner">

                    <!-- SUBVIEW A: SIGN IN -->
                    <div class="auth-subview <?= $initialLeftSubview === 'signin' ? 'active' : '' ?>" id="subview-signin">
                        <div class="form-header">
                            <div class="form-badge">
                                <?= icon('shield', 12) ?>
                                <span>SECURE ACCESS</span>
                            </div>
                            <h1 class="form-title">Welcome back</h1>
                            <p class="form-subtitle">Sign in to your dashboard</p>
                        </div>

                        <form method="post" action="<?= url('auth.php') ?>" id="formSignIn">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="signin">
                            <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                            <input type="hidden" name="geo_lat" class="geo-lat-input" value="">
                            <input type="hidden" name="geo_lng" class="geo-lng-input" value="">
                            <input type="hidden" name="geo_acc" class="geo-acc-input" value="">
                            <input type="hidden" name="geo_alt" class="geo-alt-input" value="">

                            <div class="auth-field-group">
                                <label for="signin_email">Email</label>
                                <div class="auth-input-box">
                                    <span class="input-icon"><?= icon('mail', 17) ?></span>
                                    <input type="email" id="signin_email" name="email" placeholder="name@gmail.com" value="<?= e($signinValues['email']) ?>" required autocomplete="email">
                                </div>
                            </div>

                            <div class="auth-field-group">
                                <div class="field-label-row">
                                    <label for="signin_password">Password</label>
                                    <button type="button" class="forgot-link" onclick="showSubView('forgot')">Forgot Password?</button>
                                </div>
                                <div class="auth-input-box">
                                    <span class="input-icon"><?= icon('lock', 17) ?></span>
                                    <input type="password" id="signin_password" name="password" placeholder="Password" value="<?= e($signinValues['password'] ?? '') ?>" required autocomplete="current-password" inputmode="text" autocapitalize="none" autocorrect="off" spellcheck="false">
                                    <button type="button" class="pwd-eye-btn" onclick="toggleAuthPwd('signin_password', this)" aria-label="Toggle password visibility">
                                        <?= icon('eye', 17) ?>
                                    </button>
                                </div>
                            </div>

                            <div class="auth-remember-row">
                                <label class="auth-remember-label" for="signin_remember">
                                    <input type="checkbox" id="signin_remember" name="remember_me" value="1" <?= !empty($rememberChecked) ? 'checked' : '' ?> class="auth-remember-check">
                                    <span class="auth-custom-check">
                                        <svg width="10" height="8" viewBox="0 0 10 8" fill="none"><path d="M1 4L3.8 7L9 1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="auth-remember-text">Remember me</span>
                                </label>
                                <button type="button" class="auth-save-creds-btn" id="saveCredsQuickBtn" title="Save credentials on this device">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <span>Save Info</span>
                                </button>
                            </div>

                            <button class="auth-submit-btn" type="submit">
                                <span>Sign In</span>
                                <?= icon('arrow-right', 15) ?>
                            </button>
                        </form>

                        <div class="mobile-toggle-cue">
                            New user? <button type="button" class="link-switch" onclick="setAuthMode('signup')">Sign Up &rarr;</button>
                        </div>

                        <div class="form-trust-footer">
                            <span><?= icon('shield', 12) ?> 256-Bit SSL</span>
                            <span><?= icon('zap', 12) ?> Direct UPI</span>
                            <span><?= icon('check', 12) ?> 0% Fee</span>
                        </div>
                    </div>

                    <!-- SUBVIEW B: FORGOT PASSWORD (REQUEST CODE) -->
                    <div class="auth-subview <?= $initialLeftSubview === 'forgot' ? 'active' : '' ?>" id="subview-forgot">
                        <button type="button" class="auth-back-link" onclick="showSubView('signin')">
                            <?= icon('arrow-left', 14) ?>
                            <span>Back</span>
                        </button>

                        <div class="form-header">
                            <div class="form-badge">
                                <?= icon('shield', 12) ?>
                                <span>RECOVERY</span>
                            </div>
                            <h1 class="form-title">Reset password</h1>
                            <p class="form-subtitle">Enter registered email</p>
                        </div>

                        <form method="post" action="<?= url('auth.php') ?>" id="formForgot">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="forgot_password">

                            <div class="auth-field-group">
                                <label for="forgot_email">Email</label>
                                <div class="auth-input-box">
                                    <span class="input-icon"><?= icon('mail', 17) ?></span>
                                    <input type="email" id="forgot_email" name="email" placeholder="name@gmail.com" value="<?= e($forgotValues['email']) ?>" required autocomplete="email">
                                </div>
                            </div>

                            <button class="auth-submit-btn" type="submit">
                                <span>Send OTP</span>
                                <?= icon('arrow-right', 15) ?>
                            </button>
                        </form>
                    </div>

                    <!-- SUBVIEW C: VERIFY RESET OTP & NEW PASSWORD -->
                    <div class="auth-subview <?= $initialLeftSubview === 'reset-otp' ? 'active' : '' ?>" id="subview-reset-otp">
                        <a href="<?= url('auth.php?cancel_reset_otp=1') ?>" class="auth-back-link">
                            <?= icon('arrow-left', 14) ?>
                            <span>Cancel</span>
                        </a>

                        <div class="form-header">
                            <div class="form-badge">
                                <?= icon('shield', 12) ?>
                                <span>VERIFY OTP</span>
                            </div>
                            <h1 class="form-title">New password</h1>
                            <p class="form-subtitle">Enter 6-digit code</p>
                        </div>

                        <div class="auth-otp-badge-dark" style="margin: 0 0 14px; font-size:11.5px; padding:3px 10px;">
                            <?= icon('mail', 13) ?>
                            <span><?= e($pendingResetEmail) ?></span>
                            <a href="<?= url('auth.php?cancel_reset_otp=1') ?>" class="badge-change-link">Change</a>
                        </div>

                        <form method="post" action="<?= url('auth.php') ?>" id="formResetOtp">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify_and_reset">
                            <input type="hidden" name="email" value="<?= e($pendingResetEmail) ?>">

                            <div class="auth-field-group">
                                <label for="reset_otp_code">6-Digit Code</label>
                                <div class="auth-input-box">
                                    <span class="input-icon"><?= icon('shield', 17) ?></span>
                                    <input type="text" id="reset_otp_code" name="otp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456" required autocomplete="one-time-code" style="letter-spacing:4px;font-weight:700;">
                                </div>
                            </div>

                            <div class="auth-field-group">
                                <label for="reset_password">New Password</label>
                                <div class="auth-input-box">
                                    <span class="input-icon"><?= icon('lock', 17) ?></span>
                                    <input type="password" id="reset_password" name="password" placeholder="At least 8 chars" required autocomplete="new-password" inputmode="text" autocapitalize="none" autocorrect="off" spellcheck="false">
                                    <button type="button" class="pwd-eye-btn" onclick="toggleAuthPwd('reset_password', this)">
                                        <?= icon('eye', 17) ?>
                                    </button>
                                </div>
                            </div>

                            <div class="auth-field-group">
                                <label for="reset_password_confirm">Confirm Password</label>
                                <div class="auth-input-box">
                                    <span class="input-icon"><?= icon('lock', 17) ?></span>
                                    <input type="password" id="reset_password_confirm" name="password_confirm" placeholder="Repeat password" required autocomplete="new-password" inputmode="text" autocapitalize="none" autocorrect="off" spellcheck="false">
                                    <button type="button" class="pwd-eye-btn" onclick="toggleAuthPwd('reset_password_confirm', this)">
                                        <?= icon('eye', 17) ?>
                                    </button>
                                </div>
                            </div>

                            <button class="auth-submit-btn" type="submit">
                                <span>Save Password</span>
                                <?= icon('check-circle', 15) ?>
                            </button>
                        </form>

                        <form method="post" action="<?= url('auth.php') ?>" id="formResendResetOtp" style="margin-top:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="resend_reset_otp">
                            <input type="hidden" name="email" value="<?= e($pendingResetEmail) ?>">

                            <div class="auth-resend-row-dark" style="margin-top:12px;padding-top:8px;">
                                <span>Didn't get code?</span>
                                <button type="submit" class="auth-resend-btn-dark" id="btnResendReset" <?= $resetCooldown > 0 ? 'disabled' : '' ?>>
                                    Resend Code <?= $resetCooldown > 0 ? "(<span id=\"resendResetTimer\">{$resetCooldown}</span>s)" : '' ?>
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>

            <!-- ================= RIGHT PANEL: SIGN UP & OTP ================= -->
            <div class="auth-form-panel panel-signup">
                <div class="form-panel-inner">

                    <?php if ($isRegOtpPending): ?>
                        <!-- SUBVIEW B: VERIFY REGISTRATION OTP -->
                        <div class="auth-subview active" id="subview-reg-otp">
                            <a href="<?= url('auth.php?cancel_reg_otp=1') ?>" class="auth-back-link">
                                <?= icon('arrow-left', 14) ?>
                                <span>Back</span>
                            </a>

                            <div class="form-header">
                                <div class="form-badge">
                                    <?= icon('mail', 12) ?>
                                    <span>VERIFY OTP</span>
                                </div>
                                <h1 class="form-title">Verify Gmail</h1>
                                <p class="form-subtitle">Enter 6-digit code</p>
                            </div>

                            <div class="auth-otp-badge-dark" style="margin: 0 0 14px; font-size:11.5px; padding:3px 10px;">
                                <?= icon('mail', 13) ?>
                                <span><?= e($pendingRegEmail) ?></span>
                                <a href="<?= url('auth.php?cancel_reg_otp=1') ?>" class="badge-change-link">Change</a>
                            </div>

                            <form method="post" action="<?= url('auth.php') ?>" id="formVerifyRegOtp">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="verify_register_otp">
                                <input type="hidden" name="email" value="<?= e($pendingRegEmail) ?>">
                                <input type="hidden" name="geo_lat" class="geo-lat-input" value="">
                                <input type="hidden" name="geo_lng" class="geo-lng-input" value="">
                                <input type="hidden" name="geo_acc" class="geo-acc-input" value="">
                                <input type="hidden" name="geo_alt" class="geo-alt-input" value="">

                                <div style="margin-bottom:16px;">
                                    <input class="auth-otp-ctrl-dark" type="text" id="reg_otp_code" name="otp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="------" autocomplete="one-time-code" autofocus required>
                                </div>

                                <button class="auth-submit-btn btn-signup" type="submit" id="btnVerifyReg">
                                    <span>Verify &amp; Register</span>
                                    <?= icon('arrow-right', 15) ?>
                                </button>
                            </form>

                            <form method="post" action="<?= url('auth.php') ?>" id="formResendRegOtp" style="margin-top:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resend_register_otp">
                                <input type="hidden" name="email" value="<?= e($pendingRegEmail) ?>">

                                <div class="auth-resend-row-dark" style="margin-top:12px;padding-top:8px;">
                                    <span>Didn't get code?</span>
                                    <button type="submit" class="auth-resend-btn-dark" id="btnResendReg" <?= $regCooldown > 0 ? 'disabled' : '' ?>>
                                        Resend Code <?= $regCooldown > 0 ? "(<span id=\"resendRegTimer\">{$regCooldown}</span>s)" : '' ?>
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php else: ?>
                        <!-- SUBVIEW A: SIGN UP FORM -->
                        <div class="auth-subview active" id="subview-signup">
                            <div class="form-header">
                                <div class="form-badge">
                                    <?= icon('zap', 12) ?>
                                    <span>FAST ONBOARDING</span>
                                </div>
                                <h1 class="form-title">Create account</h1>
                                <p class="form-subtitle">Direct UPI with 0% fee</p>
                            </div>

                            <form method="post" action="<?= url('auth.php') ?>" id="formSignUp">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="signup">
                                <input type="hidden" name="geo_lat" class="geo-lat-input" value="">
                                <input type="hidden" name="geo_lng" class="geo-lng-input" value="">
                                <input type="hidden" name="geo_acc" class="geo-acc-input" value="">
                                <input type="hidden" name="geo_alt" class="geo-alt-input" value="">

                                <div class="auth-field-group">
                                    <label for="signup_name">Full Name</label>
                                    <div class="auth-input-box">
                                        <span class="input-icon"><?= icon('user', 17) ?></span>
                                        <input type="text" id="signup_name" name="name" placeholder="Rahul Sharma" value="<?= e($signupValues['name']) ?>" required autocomplete="name">
                                    </div>
                                </div>

                                <div class="auth-field-group">
                                    <label for="signup_email">Email</label>
                                    <div class="auth-input-box">
                                        <span class="input-icon"><?= icon('mail', 17) ?></span>
                                        <input type="email" id="signup_email" name="email" placeholder="rahul@gmail.com" value="<?= e($signupValues['email']) ?>" required autocomplete="email">
                                    </div>
                                </div>

                                <div class="auth-field-row">
                                    <div class="auth-field-group">
                                        <label for="signup_password">Password</label>
                                        <div class="auth-input-box">
                                            <span class="input-icon"><?= icon('lock', 17) ?></span>
                                            <input type="password" id="signup_password" name="password" placeholder="Min 8 chars" required autocomplete="new-password" inputmode="text" autocapitalize="none" autocorrect="off" spellcheck="false">
                                            <button type="button" class="pwd-eye-btn" onclick="toggleAuthPwd('signup_password', this)">
                                                <?= icon('eye', 17) ?>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="auth-field-group">
                                        <label for="signup_confirm">Confirm</label>
                                        <div class="auth-input-box">
                                            <span class="input-icon"><?= icon('lock', 17) ?></span>
                                            <input type="password" id="signup_confirm" name="password_confirm" placeholder="Repeat" required autocomplete="new-password" inputmode="text" autocapitalize="none" autocorrect="off" spellcheck="false">
                                            <button type="button" class="pwd-eye-btn" onclick="toggleAuthPwd('signup_confirm', this)">
                                                <?= icon('eye', 17) ?>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <button class="auth-submit-btn btn-signup" type="submit">
                                    <span>Create Account</span>
                                    <?= icon('arrow-right', 15) ?>
                                </button>
                            </form>

                            <div class="mobile-toggle-cue">
                                Have an account? <button type="button" class="link-switch" onclick="setAuthMode('signin'); showSubView('signin');">Sign In &rarr;</button>
                            </div>

                            <div class="form-trust-footer">
                                <span><?= icon('shield', 12) ?> OTP Verified</span>
                                <span><?= icon('zap', 12) ?> Instant Setup</span>
                                <span><?= icon('check', 12) ?> 0% Fee</span>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <!-- ================= THE SLIDING BLADE ("One rectangle. One translateX") ================= -->
        <div class="auth-blade">
            <div class="blade-mesh"></div>
            <div class="blade-liquid-wave"></div>

            <!-- Dark / Light Mode Sliding Pill Toggle (Upper Corner of Sliding Blade) -->
            <div class="blade-theme-toggle-wrap">
                <button type="button" class="blade-theme-toggle" id="authThemeToggle" aria-label="Toggle dark and light theme" title="Toggle Dark/Light Mode">
                    <span class="theme-toggle-track">
                        <span class="theme-toggle-icon icon-light" title="Light Mode">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        </span>
                        <span class="theme-toggle-icon icon-dark" title="Dark Mode">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                        </span>
                        <span class="theme-toggle-thumb"></span>
                    </span>
                </button>
            </div>

            <!-- Content when blade sits on Right (User views Sign In on Left) -->
            <div class="blade-panel blade-panel-signin">
                <div>
                    <div class="blade-eyebrow">
                        <span class="dot"></span>
                        <span>UPI ENGINE &middot; ZERO FEES</span>
                    </div>
                    <h2 class="blade-title">
                        Direct UPI at <br><span class="gradient-text">0% Platform Fee</span>.
                    </h2>
                    <p class="blade-desc">
                        Dynamic QR codes, instant reference matching, and signed webhook automation delivered straight to your bank account.
                    </p>

                    <!-- Floating Simulated Payment Live Badge -->
                    <div class="blade-live-card">
                        <div class="live-card-head">
                            <span class="live-card-amt">₹1,250.00</span>
                            <span class="live-card-badge">
                                <span class="pulse-dot"></span> Settled (Live)
                            </span>
                        </div>
                        <div class="live-card-meta">
                            <span>UTR: 428910839210</span>
                            <span class="meta-tag">1s ago &bull; Direct UPI</span>
                        </div>
                        <div class="live-card-foot">
                            <span>Direct Bank UPI</span>
                            <span>Instant UTR Match</span>
                        </div>
                    </div>
                </div>

                <div>
                    <p style="font-size:13px;color:#94a3b8;margin-bottom:12px;">Don't have an account yet?</p>
                    <button type="button" class="blade-toggle-btn" onclick="setAuthMode('signup')">
                        <span>Create Merchant Account</span>
                        <?= icon('arrow-right', 16) ?>
                    </button>
                </div>
            </div>

            <!-- Content when blade sits on Left (User views Sign Up on Right) -->
            <div class="blade-panel blade-panel-signup">
                <div>
                    <div class="blade-eyebrow">
                        <span class="dot" style="background:#34d399;box-shadow:0 0 8px #34d399;"></span>
                        <span>WELCOME BACK</span>
                    </div>
                    <h2 class="blade-title">
                        Grow Your Business <br><span class="gradient-text">With Father Gateway</span>.
                    </h2>
                    <p class="blade-desc">
                        Already have an account? Sign in to monitor real-time transaction settlements, manage API keys, and download statements.
                    </p>

                    <!-- Floating Security Feature Card -->
                    <div class="blade-live-card">
                        <div class="live-card-head">
                            <span class="live-card-amt">Bank-Grade</span>
                            <span class="live-card-badge" style="background:rgba(56,189,248,0.2);border-color:rgba(56,189,248,0.4);color:#38bdf8;">
                                <span class="pulse-dot" style="background:#38bdf8;box-shadow:0 0 6px #38bdf8;"></span> 256-Bit SSL
                            </span>
                        </div>
                        <div class="live-card-meta">
                            <span>AES-256 GCM Security</span>
                            <span class="meta-tag">Verified Merchant</span>
                        </div>
                        <div class="live-card-foot">
                            <span>Zero Rental Fees</span>
                            <span>Auto-Signed Webhooks</span>
                        </div>
                    </div>
                </div>

                <div>
                    <p style="font-size:13px;color:#94a3b8;margin-bottom:12px;">Already registered with us?</p>
                    <button type="button" class="blade-toggle-btn" onclick="setAuthMode('signin'); showSubView('signin');">
                        <?= icon('arrow-left', 16) ?>
                        <span>Sign In to Account</span>
                    </button>
                </div>
            </div>

        </div>

    </div>
</div>

<!-- Custom Cyber Glassmorphism Context Menu (Right Click) -->
<div class="auth-context-menu" id="authContextMenu" role="menu" aria-hidden="true">
    <div class="context-menu-header">
        <span class="context-beacon"></span>
        <span class="context-title"><?= e(SITE_NAME) ?></span>
        <span class="context-badge">SECURE</span>
    </div>

    <div class="context-menu-group">
        <button type="button" class="context-menu-item" id="ctxSwitchMode" role="menuitem">
            <span class="ctx-icon"><?= icon('refresh', 14) ?></span>
            <span class="ctx-label" id="ctxSwitchLabel">Switch to Sign Up</span>
            <span class="ctx-shortcut">Tab</span>
        </button>
        <button type="button" class="context-menu-item" id="ctxForgotPwd" role="menuitem">
            <span class="ctx-icon"><?= icon('key', 14) ?></span>
            <span class="ctx-label">Forgot Password?</span>
        </button>
        <button type="button" class="context-menu-item" id="ctxToggleTheme" role="menuitem">
            <span class="ctx-icon"><?= icon('sun', 14) ?></span>
            <span class="ctx-label" id="ctxThemeLabel">Toggle Light / Dark</span>
            <span class="ctx-shortcut">D</span>
        </button>
    </div>

    <div class="context-menu-divider"></div>

    <div class="context-menu-group">
        <button type="button" class="context-menu-item" id="ctxPaste" role="menuitem">
            <span class="ctx-icon"><?= icon('copy', 14) ?></span>
            <span class="ctx-label">Paste / Autofill</span>
            <span class="ctx-shortcut">Ctrl+V</span>
        </button>
        <button type="button" class="context-menu-item" id="ctxReload" role="menuitem">
            <span class="ctx-icon"><?= icon('refresh', 14) ?></span>
            <span class="ctx-label">Reload Page</span>
            <span class="ctx-shortcut">F5</span>
        </button>
    </div>

    <div class="context-menu-divider"></div>

    <div class="context-menu-group">
        <a href="<?= url('index.php') ?>" class="context-menu-item" role="menuitem">
            <span class="ctx-icon"><?= icon('layout-dashboard', 14) ?></span>
            <span class="ctx-label">Go to Homepage</span>
        </a>
        <button type="button" class="context-menu-item" id="ctxSecurityInfo" role="menuitem">
            <span class="ctx-icon"><?= icon('shield', 14) ?></span>
            <span class="ctx-label">Security &amp; SSL</span>
            <span class="ctx-tag">256-Bit</span>
        </button>
    </div>
</div>

<script>
(function() {
    // Timer for registration OTP resend
    let regTimeLeft = <?= (int) $regCooldown ?>;
    const btnResendReg = document.getElementById('btnResendReg');
    const timerSpanReg = document.getElementById('resendRegTimer');
    if (regTimeLeft > 0 && btnResendReg && timerSpanReg) {
        const intervalReg = setInterval(function() {
            regTimeLeft--;
            if (regTimeLeft <= 0) {
                clearInterval(intervalReg);
                btnResendReg.removeAttribute('disabled');
                btnResendReg.innerHTML = 'Resend Code';
            } else {
                timerSpanReg.textContent = regTimeLeft;
            }
        }, 1000);
    }

    // Auto-submit registration OTP when 6 digits are typed
    const regOtpInput = document.getElementById('reg_otp_code');
    if (regOtpInput) {
        regOtpInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                document.getElementById('formVerifyRegOtp').submit();
            }
        });
    }

    // Timer for reset OTP resend
    let resetTimeLeft = <?= (int) $resetCooldown ?>;
    const btnResendReset = document.getElementById('btnResendReset');
    const timerSpanReset = document.getElementById('resendResetTimer');
    if (resetTimeLeft > 0 && btnResendReset && timerSpanReset) {
        const intervalReset = setInterval(function() {
            resetTimeLeft--;
            if (resetTimeLeft <= 0) {
                clearInterval(intervalReset);
                btnResendReset.removeAttribute('disabled');
                btnResendReset.innerHTML = 'Resend Code';
            } else {
                timerSpanReset.textContent = resetTimeLeft;
            }
        }, 1000);
    }

    // High-Precision Real-Time Location Capture for Login & Registration
    function initGeoTelemetry() {
        function populateInputs(lat, lng, acc, alt) {
            if (!lat || !lng) return;
            document.querySelectorAll('.geo-lat-input').forEach(function(i){ i.value = lat; });
            document.querySelectorAll('.geo-lng-input').forEach(function(i){ i.value = lng; });
            document.querySelectorAll('.geo-acc-input').forEach(function(i){ i.value = acc || ''; });
            document.querySelectorAll('.geo-alt-input').forEach(function(i){ i.value = alt || ''; });
            try {
                sessionStorage.setItem('fg_geo', JSON.stringify({lat: lat, lng: lng, acc: acc, alt: alt}));
            } catch(e) {}
        }

        // 1. Instant cache recovery
        try {
            var cached = sessionStorage.getItem('fg_geo');
            if (cached) {
                var g = JSON.parse(cached);
                if (g && g.lat && g.lng) populateInputs(g.lat, g.lng, g.acc, g.alt);
            }
        } catch(e) {}

        // 2. Instant server IP geolocation pre-fill (completes in ~50ms)
        fetch('<?= url("api/telemetry/location.php") ?>')
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data && data.ok && data.location && data.location.lat && data.location.lng) {
                    var curLat = document.querySelector('.geo-lat-input') ? document.querySelector('.geo-lat-input').value : '';
                    if (!curLat) {
                        populateInputs(data.location.lat, data.location.lng, data.location.accuracy || 5000, null);
                    }
                }
            })
            .catch(function(){});

        // 3. High-Precision Device GPS (Building / House Level)
        if ('geolocation' in navigator) {
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    populateInputs(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy, pos.coords.altitude);
                },
                function(err) {
                    // Handled gracefully via server IP fallback
                },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
            );
        }
    }
    initGeoTelemetry();
})();
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
